<?php

use App\Models\AuditLog;
use App\Models\PermissionRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FamUnitTestHelpers as Fam;
use Tests\Support\PrlTestHelpers as Prl;

// REQ-PRL-004 (1.14b-b), RN-PRL-10 enmendada (DP-11, decisión del usuario del 2026-11-02), ADR-035, ADR-068 §5.4, INV-002:
// las lecturas de prl que devuelven teléfonos o nombres de personas dejan una fila `read` sin valores y consumen un cupo.

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return list<AuditLog> filas `read` de un tipo auditable con la finalidad dada */
function prlReads(object $tenant, string $type, string $purpose): array
{
    return array_values(array_filter(Fam::audit($tenant, $type, 'read'), fn (AuditLog $log): bool => ($log->context['purpose'] ?? null) === $purpose));
}

test('DP-11 RN-PRL-10: abrir, leer y actualizar una alerta ABIERTA (con teléfonos) escribe una fila read puerta_alerta sin valores; el listado y la alerta cerrada no', function (): void {
    $f = Prl::doorFamily('prlr-alert');
    app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('people')->where('id', $f['principal']->person_id)->update(['contact_phone' => '600777888']));

    $id = Prl::call($f['tenant'], $f['conserje'], 'post', '/pickup-alerts', ['student_person' => $f['student']->public_id, 'presenter_description' => 'Desconocido'])->assertCreated()->json('public_id');
    expect(prlReads($f['tenant'], 'pickup_alert', 'puerta_alerta'))->toHaveCount(1);

    Prl::call($f['tenant'], $f['direccion'], 'get', "/pickup-alerts/{$id}")->assertOk()->assertJsonPath('contacts.principal_phone', '600777888');
    Prl::call($f['tenant'], $f['direccion'], 'patch', "/pickup-alerts/{$id}", ['principal_contacted_at' => '2026-11-02T09:00:00Z'])->assertOk();
    expect(prlReads($f['tenant'], 'pickup_alert', 'puerta_alerta'))->toHaveCount(3);

    // Listado: sin teléfonos, sin fila.
    Prl::call($f['tenant'], $f['direccion'], 'get', '/pickup-alerts')->assertOk();
    expect(prlReads($f['tenant'], 'pickup_alert', 'puerta_alerta'))->toHaveCount(3);

    // Cierre: la respuesta ya no lleva teléfonos ni escribe fila; el detalle cerrado tampoco.
    Prl::call($f['tenant'], $f['direccion'], 'patch', "/pickup-alerts/{$id}", ['resolution' => 'no_entregado'])->assertOk();
    Prl::call($f['tenant'], $f['direccion'], 'get', "/pickup-alerts/{$id}")->assertOk();
    $reads = prlReads($f['tenant'], 'pickup_alert', 'puerta_alerta');

    expect($reads)->toHaveCount(3)
        ->and($reads[0]->changes)->toBeNull()
        ->and($reads[0]->auditable_public_id)->toBe($id)
        ->and($reads[0]->context)->toEqual(['purpose' => 'puerta_alerta', 'entries' => 1])
        ->and(json_encode(array_map(fn ($l) => [$l->context, $l->changes], $reads)))->not->toContain('600777888')->not->toContain('Desconocido');
});

test('DP-11 RN-PRL-10: GET /student-movements y su detalle escriben una fila read puerta_historial sin valores (solo con resultados) y consumen cupo', function (): void {
    $f = Prl::doorFamily('prlr-hist');

    // Sin movimientos no se devuelve ningún nombre: sin fila.
    Prl::call($f['tenant'], $f['direccion'], 'get', '/student-movements')->assertOk();
    expect(prlReads($f['tenant'], 'student_movement', 'puerta_historial'))->toHaveCount(0);

    $first = Prl::exit($f)->assertCreated()->json('public_id');
    Fam::tick();
    Prl::exit($f)->assertCreated();

    Prl::call($f['tenant'], $f['direccion'], 'get', '/student-movements')->assertOk();
    Prl::call($f['tenant'], $f['direccion'], 'get', "/student-movements/{$first}")->assertOk();
    $reads = prlReads($f['tenant'], 'student_movement', 'puerta_historial');

    expect($reads)->toHaveCount(2)
        ->and($reads[0]->context)->toEqual(['purpose' => 'puerta_historial', 'entries' => 2])
        ->and($reads[1]->context)->toEqual(['purpose' => 'puerta_historial', 'entries' => 1])
        ->and($reads[1]->auditable_public_id)->toBe($first)
        ->and($reads[0]->changes)->toBeNull()
        ->and(json_encode(array_map(fn ($l) => [$l->context, $l->changes], $reads)))->not->toContain('Abuelo');
});

test('DP-11 RN-PRL-10: el cupo de lectura de prl (alertas y movimientos comparten cubo) agotado es 429 con Retry-After, sin fila de auditoría ni datos; otro usuario no se ve afectado', function (): void {
    config(['prl.read_rate_limit.attempts' => 2]);
    $f = Prl::doorFamily('prlr-limit');
    $id = Prl::call($f['tenant'], $f['conserje'], 'post', '/pickup-alerts', ['student_person' => $f['student']->public_id, 'presenter_description' => 'x'])->assertCreated()->json('public_id');

    Prl::call($f['tenant'], $f['conserje'], 'get', "/pickup-alerts/{$id}")->assertOk();
    $before = count(prlReads($f['tenant'], 'pickup_alert', 'puerta_alerta'));

    // Tercer intento (alerta abierta) y alta con teléfonos: 429 y nada se crea.
    Prl::call($f['tenant'], $f['conserje'], 'get', "/pickup-alerts/{$id}")->assertStatus(429);
    Prl::call($f['tenant'], $f['conserje'], 'post', '/pickup-alerts', ['student_person' => $f['student']->public_id, 'presenter_description' => 'y'])->assertStatus(429);

    // conserjería tiene entrega_menor.leer: el listado sí llega al cupo (429), no al 403.
    Prl::call($f['tenant'], $f['conserje'], 'get', '/student-movements')->assertStatus(429)->assertHeader('Retry-After');
    expect(count(prlReads($f['tenant'], 'pickup_alert', 'puerta_alerta')))->toBe($before)
        ->and(Prl::count($f['tenant'], 'pickup_alerts'))->toBe(1);

    Prl::call($f['tenant'], $f['direccion'], 'get', "/pickup-alerts/{$id}")->assertOk();
});

test('M2 INV-002 RPERM-011: un rol con entrega_menor/alerta_entrega en ámbito unidad_familiar (inserción directa, fuera del catálogo) recibe 403 en los 9 endpoints', function (): void {
    $f = Prl::doorFamily('prlr-ambito');
    $user = Prl::roleUser($f['tenant'], 'estudiante');

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($user): void {
        $role = $user->roles()->first();
        $codes = ['entrega_menor.leer', 'entrega_menor.crear', 'alerta_entrega.leer', 'alerta_entrega.crear', 'alerta_entrega.actualizar'];

        foreach ($codes as $code) {
            PermissionRole::forceCreate(['tenant_id' => $role->tenant_id, 'role_id' => $role->id, 'permission_code' => $code, 'effect' => 'allow', 'scope' => 'unidad_familiar']);
        }
    });

    $student = $f['student']->public_id;
    $calls = [
        ['get', '/door/students?q=Al', []],
        ['get', "/door/students/{$student}/pickup-list", []],
        ['post', '/student-movements', Prl::exitBody($f)],
        ['get', '/student-movements', []],
        ['get', '/student-movements/01JZZZZZZZZZZZZZZZZZZZZZZZ', []],
        ['post', '/pickup-alerts', ['student_person' => $student, 'presenter_description' => 'x']],
        ['get', '/pickup-alerts', []],
        ['get', '/pickup-alerts/01JZZZZZZZZZZZZZZZZZZZZZZZ', []],
        ['patch', '/pickup-alerts/01JZZZZZZZZZZZZZZZZZZZZZZZ', ['resolution' => 'otro']],
    ];

    foreach ($calls as [$method, $path, $body]) {
        Prl::call($f['tenant'], $user, $method, $path, $body)->assertForbidden();
    }

    expect(Prl::count($f['tenant'], 'student_movements'))->toBe(0)->and(Prl::count($f['tenant'], 'pickup_alerts'))->toBe(0);
});
