<?php

use App\Modules\Core\Domain\ModuleCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PrlTestHelpers as Prl;

// REQ-PRL-004 (1.14b-b), INV-001, CA-PRL-011: aislamiento entre centros de todos los endpoints de `prl`, y contratación del módulo
// (RMOD-009, `403 module-disabled`).

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('INV-001 CA-PRL-011: dos centros con datos equivalentes — desde A, ningún endpoint de prl devuelve, modifica ni cuenta nada de B', function (): void {
    $a = Prl::doorFamily('prl-iso-a');
    $b = Prl::doorFamily('prl-iso-b');

    // Movimientos y alertas en ambos centros, con las mismas formas.
    $movementA = Prl::exit($a)->assertCreated()->json('public_id');
    $movementB = Prl::exit($b)->assertCreated()->json('public_id');
    $alertA = Prl::call($a['tenant'], $a['conserje'], 'post', '/pickup-alerts', ['student_person' => $a['student']->public_id, 'presenter_description' => 'Desconocido A'])->assertCreated()->json('public_id');
    $alertB = Prl::call($b['tenant'], $b['conserje'], 'post', '/pickup-alerts', ['student_person' => $b['student']->public_id, 'presenter_description' => 'Desconocido B'])->assertCreated()->json('public_id');

    // Lecturas: solo lo de A.
    expect(array_column(Prl::call($a['tenant'], $a['direccion'], 'get', '/student-movements')->assertOk()->json('data'), 'public_id'))->toBe([$movementA])
        ->and(array_column(Prl::call($a['tenant'], $a['direccion'], 'get', '/pickup-alerts')->assertOk()->json('data'), 'public_id'))->toBe([$alertA])
        ->and(Prl::call($a['tenant'], $a['direccion'], 'get', '/pickup-alerts')->json('meta.total'))->toBe(1);

    // Detalle, modificación y lista de puerta de recursos de B: 404 (nunca 403), sin tocar nada.
    Prl::call($a['tenant'], $a['direccion'], 'get', "/student-movements/{$movementB}")->assertNotFound();
    Prl::call($a['tenant'], $a['direccion'], 'get', "/pickup-alerts/{$alertB}")->assertNotFound();
    Prl::call($a['tenant'], $a['direccion'], 'patch', "/pickup-alerts/{$alertB}", ['resolution' => 'otro'])->assertNotFound();
    Prl::call($a['tenant'], $a['conserje'], 'get', "/door/students/{$b['student']->public_id}/pickup-list")->assertNotFound();
    Prl::call($a['tenant'], $a['direccion'], 'get', "/student-movements?student_person={$b['student']->public_id}")->assertOk()->assertJsonPath('data', []);
    Prl::call($a['tenant'], $a['direccion'], 'get', "/pickup-alerts?student_person={$b['student']->public_id}")->assertOk()->assertJsonPath('data', []);

    // Escrituras de A que citan alumnos o personas de B: sin efecto.
    Prl::call($a['tenant'], $a['conserje'], 'post', '/student-movements', Prl::exitBody($b))->assertStatus(422);
    Prl::call($a['tenant'], $a['conserje'], 'post', '/student-movements', ['student_person' => $a['student']->public_id, 'direction' => 'salida', 'picked_up_by' => $b['abuelo']['person'], 'verification_method' => 'foto'])->assertStatus(422);
    Prl::call($a['tenant'], $a['conserje'], 'post', '/pickup-alerts', ['student_person' => $b['student']->public_id, 'presenter_description' => 'x'])->assertNotFound();
    Prl::call($a['tenant'], $a['conserje'], 'post', '/student-movements', Prl::exitBody($a, null, ['corrects' => $movementB, 'correction_reason' => 'x']))->assertStatus(422);
    Prl::call($a['tenant'], $a['conserje'], 'post', '/student-movements', Prl::exitBody($a, null, ['delivered_by' => $b['conserje']->public_id]))->assertStatus(422);

    // El buscador solo ve alumnos de A; el cursor de A no sirve en B.
    $names = array_column(Prl::call($a['tenant'], $a['conserje'], 'get', '/door/students?q=Al')->assertOk()->json('data'), 'student_person');
    expect($names)->toBe([$a['student']->public_id]);

    $cursor = Prl::call($a['tenant'], $a['direccion'], 'get', '/student-movements?limit=1')->json('meta.next_cursor');
    expect($cursor)->toBeNull();

    Prl::exit($a)->assertCreated();
    $cursorA = Prl::call($a['tenant'], $a['direccion'], 'get', '/student-movements?limit=1')->assertOk()->json('meta.next_cursor');
    Prl::call($b['tenant'], $b['direccion'], 'get', '/student-movements?limit=1&cursor='.urlencode($cursorA))->assertStatus(422);

    // Lo de B sigue intacto.
    expect(Prl::count($b['tenant'], 'student_movements'))->toBe(1)->and(Prl::count($b['tenant'], 'pickup_alerts'))->toBe(1)
        ->and(Prl::call($b['tenant'], $b['direccion'], 'get', "/pickup-alerts/{$alertB}")->assertOk()->json('status'))->toBe('abierta');

    // Un usuario de A no entra en B (la sesión es del centro).
    Prl::call($b['tenant'], $a['conserje'], 'get', '/student-movements')->assertStatus(401);
});

test('CA-PRL-011 RMOD-009: con prl descontratado todas las rutas responden 403 module-disabled; platform:sync-registry registra depends_on [fam_unit]', function (): void {
    $f = Prl::doorFamily('prl-modulo');
    Prl::disableModule($f['tenant']);

    foreach ([['get', '/door/students?q=Al'], ['get', "/door/students/{$f['student']->public_id}/pickup-list"], ['post', '/student-movements'], ['get', '/student-movements'], ['get', '/student-movements/01JZZZZZZZZZZZZZZZZZZZZZZZ'], ['post', '/pickup-alerts'], ['get', '/pickup-alerts'], ['get', '/pickup-alerts/01JZZZZZZZZZZZZZZZZZZZZZZZ'], ['patch', '/pickup-alerts/01JZZZZZZZZZZZZZZZZZZZZZZZ']] as [$method, $path]) {
        $response = Prl::call($f['tenant'], $f['admin'], $method, $path, $method === 'get' ? [] : ['x' => 1])->assertForbidden();

        expect($response->json('type'))->toBe('urn:pge:error:module-disabled');
    }

    // `platform:sync-registry` materializa el módulo; `depends_on` y `essential` los resuelve el catálogo de descriptores (ADR-045 §4.5).
    test()->artisan('platform:sync-registry')->assertSuccessful();
    $catalogued = app(ModuleCatalog::class)->find('prl');

    expect(DB::connection('pgsql_platform')->table('modules')->where('code', 'prl')->whereNull('retired_at')->exists())->toBeTrue()
        ->and($catalogued)->not->toBeNull()
        ->and($catalogued->dependsOn)->toBe(['fam_unit'])
        ->and($catalogued->essential)->toBeFalse()
        ->and(app(ModuleCatalog::class)->dependenciesOf('prl'))->toBe(['fam_unit']);

    // Contratado de nuevo, vuelve a responder.
    app(TenantContext::class)->runFor($f['tenant']->id, function (): void {
        DB::connection('pgsql_platform')->table('module_subscriptions')->where('module_code', 'prl')->update(['enabled' => true]);
        Cache::forget('modules:prl:enabled');
    });
    Prl::call($f['tenant'], $f['admin'], 'get', '/student-movements')->assertOk();
});
