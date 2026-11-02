<?php

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FamUnitTestHelpers as Fam;
use Tests\Support\PrlTestHelpers as Prl;

// REQ-PRL-004 (1.14b-b), docs/modulos/REQ-PRL/api.md §8.4: la consulta rápida de la puerta — buscador de alumnos y lista de puerta.
// RN-PRL-01, RN-PRL-10, RN-PRL-11; CA-PRL-009, CA-PRL-010; ADR-068 §5.4, §5.5; OPEN-068-06; OPEN-FAM-UNIT-34.

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('api.md §8.4 OPEN-068-06: GET /door/students busca alumnos por el comienzo del nombre (máx. 20) y devuelve el código de su unidad; q de 2 a 100 caracteres, si no 422', function (): void {
    $f = Prl::doorFamily('prl-buscar');
    $extra = Fam::person($f['tenant'], 'Alba', 'Marin');
    $other = Fam::person($f['tenant'], 'Alberto', 'Soto');
    Fam::addStudent($f['tenant'], $f['admin'], $f['unit'], $extra)->assertCreated();
    $outside = Fam::person($f['tenant'], 'Alina', 'Fuera'); // ni unidad ni vínculo: no es alumno de la puerta

    $unitCode = Fam::row($f['tenant'], 'family_units', $f['unit'])->code;
    $response = Prl::call($f['tenant'], $f['conserje'], 'get', '/door/students?q=Al')->assertOk();

    expect(array_column($response->json('data'), 'student_person'))->toContain($extra->public_id)
        ->and(array_column($response->json('data'), 'student_person'))->not->toContain($outside->public_id)
        ->and(array_column($response->json('data'), 'student_person'))->not->toContain($other->public_id);

    $found = collect($response->json('data'))->firstWhere('student_person', $extra->public_id);
    expect($found)->toBe(['student_person' => $extra->public_id, 'display_name' => $found['display_name'], 'family_unit_code' => $unitCode])
        ->and($found['display_name'])->toContain('Alba');

    // Sin distinguir mayúsculas; solo el comienzo.
    expect(array_column(Prl::call($f['tenant'], $f['conserje'], 'get', '/door/students?q=alba')->assertOk()->json('data'), 'student_person'))->toBe([$extra->public_id])
        ->and(Prl::call($f['tenant'], $f['conserje'], 'get', '/door/students?q=zzzz')->assertOk()->json('data'))->toBe([]);

    foreach (['q=a', 'q=', '', 'q='.str_repeat('x', 101)] as $query) {
        Prl::call($f['tenant'], $f['conserje'], 'get', '/door/students?'.$query)->assertStatus(422);
    }

    // Máximo 20.
    foreach (range(1, 22) as $i) {
        Fam::addStudent($f['tenant'], $f['admin'], $f['unit'], Fam::person($f['tenant'], "Zeta{$i}", 'Masivo'))->assertCreated();
    }

    expect(Prl::call($f['tenant'], $f['conserje'], 'get', '/door/students?q=Zeta')->assertOk()->json('data'))->toHaveCount(20);
});

test('RN-PRL-01 RN-PRL-10 CA-PRL-010: la lista de puerta pasa por la lectura auditada de fam_unit con finalidad puerta — una fila read por consulta, sin valores personales — y trae foto firmada, teléfono y documento enmascarado', function (): void {
    $f = Prl::doorFamily('prl-lista');
    $response = Prl::call($f['tenant'], $f['conserje'], 'get', "/door/students/{$f['student']->public_id}/pickup-list")->assertOk();

    $entries = collect($response->json('data'));
    $abuelo = $entries->firstWhere('person', $f['abuelo']['person']);

    expect($entries)->toHaveCount(3)
        ->and($entries->map(fn ($e) => $e['bases'][0]['basis'])->all())->toBe(['tutor', 'tutor', 'tercero'])
        ->and($abuelo['has_photo'])->toBeTrue()
        ->and($abuelo['photo_url'])->toBeString()
        ->and($abuelo['contact_phone'])->toBe('600000000')
        ->and($abuelo['document']['masked'])->toEndWith(substr(Fam::dni(1), -4))
        ->and($abuelo['document']['masked'])->not->toBe(Fam::dni(1))
        ->and($response->json('meta.as_of'))->toBe('2026-11-02T09:00:00Z')
        ->and($response->json('meta.protocol_available'))->toBeTrue()
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    // Un tutor sin fotografía figura (para exigir documento) sin URL.
    $tutor = $entries->firstWhere('has_photo', false);
    expect($tutor['photo_url'])->toBeNull();

    $reads = array_values(array_filter(Fam::audit($f['tenant'], 'person', 'read'), fn (AuditLog $log): bool => ($log->context['purpose'] ?? null) === 'puerta'));

    expect($reads)->toHaveCount(1)
        ->and($reads[0]->context)->toEqual(['purpose' => 'puerta', 'entries' => 3, 'photos' => 1])
        ->and($reads[0]->changes)->toBeNull()
        ->and(json_encode($reads[0]->context))->not->toContain('Abuelo')->not->toContain('600000000')->not->toContain($abuelo['photo_url']);

    // Dos consultas más, dos filas más (una por consulta).
    Prl::call($f['tenant'], $f['conserje'], 'get', "/door/students/{$f['student']->public_id}/pickup-list")->assertOk();
    Prl::call($f['tenant'], $f['conserje'], 'get', "/door/students/{$f['student']->public_id}/pickup-list")->assertOk();
    expect(array_filter(Fam::audit($f['tenant'], 'person', 'read'), fn (AuditLog $log): bool => ($log->context['purpose'] ?? null) === 'puerta'))->toHaveCount(3);
});

test('CA-PRL-010 RN-FAM-UNIT-93: la lista de puerta comparte el límite de lectura de fam_unit — superado, 429 con Retry-After; otro usuario no se ve afectado', function (): void {
    config(['fam_unit.pickup.read_rate_limit.attempts' => 2]);
    $f = Prl::doorFamily('prl-limite');
    $path = "/door/students/{$f['student']->public_id}/pickup-list";

    Prl::call($f['tenant'], $f['conserje'], 'get', $path)->assertOk();
    Prl::call($f['tenant'], $f['conserje'], 'get', $path)->assertOk();
    $limited = Prl::call($f['tenant'], $f['conserje'], 'get', $path)->assertStatus(429);

    expect($limited->headers->get('Retry-After'))->not->toBeNull();

    // Otro usuario (dirección) no se ve afectado, y la lista bloqueada no dejó ninguna fila de lectura.
    Prl::call($f['tenant'], $f['direccion'], 'get', $path)->assertOk();
    expect(array_filter(Fam::audit($f['tenant'], 'person', 'read'), fn (AuditLog $log): bool => ($log->context['purpose'] ?? null) === 'puerta'))->toHaveCount(3);
});

test('OPEN-FAM-UNIT-34 RN-FAM-UNIT-80: la lista de puerta no incluye a la persona excluida por revocación y no hay rastro de que lo esté; un alumno desconocido es 404', function (): void {
    $f = Prl::doorFamily('prl-revocado');
    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['lo']}/revocation", ['reason' => 'resolucion_judicial', 'notes' => null])->assertOk();

    $response = Prl::call($f['tenant'], $f['conserje'], 'get', "/door/students/{$f['student']->public_id}/pickup-list")->assertOk();
    $persons = array_column($response->json('data'), 'person');

    expect($persons)->not->toContain(Prl::personOf($f['tenant'], $f['other']))
        ->and($persons)->toHaveCount(2)
        ->and(json_encode(array_column($response->json('data'), 'bases')))->not->toContain('revoc');

    Prl::call($f['tenant'], $f['conserje'], 'get', '/door/students/01JZZZZZZZZZZZZZZZZZZZZZZZ/pickup-list')->assertNotFound();
});

test('CA-PRL-009 RPERM-007 INV-002: sin entrega_menor.leer/.crear — también un tutor con ámbito unidad_familiar — la lista de puerta y los movimientos son 403; un deny de uno de sus roles también', function (): void {
    $f = Prl::doorFamily('prl-permisos');
    $door = "/door/students/{$f['student']->public_id}/pickup-list";

    // Un tutor con ámbito unidad_familiar (lee la lista de recogida de sus alumnos) no tiene nada de la puerta.
    foreach ([['get', '/door/students?q=Al'], ['get', $door], ['get', '/student-movements'], ['post', '/student-movements'], ['get', '/pickup-alerts'], ['post', '/pickup-alerts']] as [$method, $path]) {
        Prl::call($f['tenant'], $f['principal'], $method, $path, $method === 'post' ? ['student_person' => $f['student']->public_id] : [])->assertForbidden();
    }

    // Quien solo lee (secretaría) no registra; el docente-tipo sin permisos tampoco.
    $secretaria = Prl::roleUser($f['tenant'], 'secretaria');
    Prl::call($f['tenant'], $secretaria, 'get', $door)->assertOk();
    Prl::exit($f, null, [], $secretaria)->assertForbidden();
    Prl::call($f['tenant'], Prl::roleUser($f['tenant'], 'docente'), 'get', $door)->assertForbidden();

    // `deny` sobrescribe `allow` en un usuario con varios roles (RPERM-007).
    $both = Fam::userWithRoles($f['tenant'], $f['admin'], 'deny', [
        'puerta' => ['entrega_menor.leer' => ['allow', 'todos'], 'entrega_menor.crear' => ['allow', 'todos']],
        'bloqueo' => ['entrega_menor.crear' => ['deny', 'todos']],
    ]);
    Prl::call($f['tenant'], $both, 'get', $door)->assertOk();
    Prl::exit($f, null, [], $both)->assertForbidden();

    // Sin sesión, 401.
    resetSessionState();
    test()->getJson(Fam::url($f['tenant'], $door))->assertUnauthorized();
});

test('INV-002 permisos.md §8.4: conserjería (rol predefinido) consulta y registra pero no da de alta autorizados ni cierra alertas; dirección cierra alertas pero no registra salidas', function (): void {
    $f = Prl::doorFamily('prl-roles');
    $authorize = Fam::authorizationBody($f, $f['abuelo']['person']);

    Prl::call($f['tenant'], $f['conserje'], 'post', '/pickup-authorizations', $authorize)->assertForbidden();
    Prl::call($f['tenant'], $f['conserje'], 'post', '/pickup-persons', ['given_name' => 'X', 'family_name_1' => 'Y'])->assertForbidden();
    Prl::exit($f, null, [], $f['direccion'])->assertForbidden();

    $alert = Prl::call($f['tenant'], $f['conserje'], 'post', '/pickup-alerts', ['student_person' => $f['student']->public_id, 'presenter_description' => 'Persona sin identificar'])->assertCreated()->json('public_id');
    Prl::call($f['tenant'], $f['conserje'], 'patch', "/pickup-alerts/{$alert}", ['resolution' => 'no_entregado'])->assertForbidden();
    Prl::call($f['tenant'], $f['direccion'], 'patch', "/pickup-alerts/{$alert}", ['resolution' => 'no_entregado'])->assertOk();
});
