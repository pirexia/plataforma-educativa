<?php

use App\Models\AuditLog;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadTestHelpers;
use Tests\Support\AcadTimetableHelpers as Tt;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-002 (1.12-a), docs/modulos/REQ-ACAD/api.md §12, funcional.md §18.4.3,
// §18.9: espacios (aulas y recursos). CA-ACAD-115, CA-ACAD-116, CA-ACAD-128
// (los espacios siguen editables con el curso cerrado), INV-003 (auditoría),
// INV-001 (aislamiento), INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-ACAD-115 RN-ACAD-100 RN-ACAD-101 INV-003: el alta de un aula y de un recurso responde 201 con Location, sin academic_year, y deja una entrada created con auditable_type facility', function (): void {
    [$tenant, $admin] = Api::setup('fac-115');

    $room = Tt::postFacility($tenant, $admin)->assertCreated();
    $resource = Tt::postFacility($tenant, $admin, ['facility_type' => 'recurso', 'code' => 'CARRO1', 'name' => 'Carro de portátiles'])->assertCreated();

    expect($room->json('public_id'))->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($room->headers->get('Location'))->toEndWith('/api/v1/facilities/'.$room->json('public_id'))
        ->and($room->json('facility_type'))->toBe('aula')
        ->and($resource->json('facility_type'))->toBe('recurso')
        ->and(array_keys($room->json()))->toEqualCanonicalizing(['public_id', 'facility_type', 'code', 'name', 'created_at', 'updated_at'])
        ->and(array_keys($room->json()))->not->toContain('id', 'tenant_id', 'academic_year', 'created_by', 'deleted_at');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $audit = AuditLog::query()->where('auditable_type', 'facility')->where('event', 'created')->get();

        expect($audit)->toHaveCount(2)
            ->and($audit[0]->changes)->toHaveKeys(['facility_type', 'code', 'name']);
    });
});

test('CA-ACAD-115 RN-ACAD-100: facility_type fuera de vocabulario o ausente es 422 facility_type_invalid; en PATCH es 422 facility_type_immutable, también con el mismo valor', function (): void {
    [$tenant, $admin] = Api::setup('fac-115b');

    foreach ([['facility_type' => 'laboratorio'], ['facility_type' => 5], ['facility_type' => null]] as $overrides) {
        expect(Tt::errorCode(Tt::postFacility($tenant, $admin, $overrides)->assertStatus(422), 'facility_type'))->toBe('acad.validation.facility_type_invalid');
    }

    resetSessionState();
    $body = test()->actingAs($admin)->postJson(Api::url($tenant, '/facilities'), ['code' => 'X', 'name' => 'X'])->assertStatus(422);
    expect(Tt::errorCode($body, 'facility_type'))->toBe('acad.validation.facility_type_invalid');

    $id = Tt::postFacility($tenant, $admin)->json('public_id');

    foreach (['recurso', 'aula'] as $type) {
        resetSessionState();
        $response = test()->actingAs($admin)->patchJson(Api::url($tenant, "/facilities/{$id}"), ['facility_type' => $type])->assertStatus(422);
        expect(Tt::errorCode($response, 'facility_type'))->toBe('acad.validation.facility_type_immutable');
    }

    expect(Tt::count($tenant, 'facilities'))->toBe(1);
});

test('CA-ACAD-115 RN-ACAD-101: código repetido entre vivos del centro es 422 code_taken, uno borrado se reutiliza, name y code son obligatorios y academic_year en el cuerpo se ignora', function (): void {
    [$tenant, $admin] = Api::setup('fac-115c');

    $first = Tt::postFacility($tenant, $admin)->assertCreated()->json('public_id');
    expect(Tt::errorCode(Tt::postFacility($tenant, $admin, ['name' => 'Otra'])->assertStatus(422), 'code'))->toBe('acad.validation.code_taken');

    // PATCH al código de otro espacio vivo ⇒ code_taken; al suyo propio ⇒ 200.
    $second = Tt::postFacility($tenant, $admin, ['code' => 'A22', 'name' => 'Aula 2.2'])->assertCreated()->json('public_id');
    resetSessionState();
    expect(Tt::errorCode(test()->actingAs($admin)->patchJson(Api::url($tenant, "/facilities/{$second}"), ['code' => 'A21'])->assertStatus(422), 'code'))->toBe('acad.validation.code_taken');
    resetSessionState();
    test()->actingAs($admin)->patchJson(Api::url($tenant, "/facilities/{$second}"), ['code' => 'A22', 'name' => 'Aula 22'])->assertOk()->assertJsonPath('name', 'Aula 22');

    // Borrado lógico y reutilización del código.
    resetSessionState();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/facilities/{$first}"))->assertNoContent();
    Tt::postFacility($tenant, $admin)->assertCreated();

    expect(Tt::errorCode(Tt::postFacility($tenant, $admin, ['code' => ''])->assertStatus(422), 'code'))->toBe('acad.validation.code_required')
        ->and(Tt::errorCode(Tt::postFacility($tenant, $admin, ['code' => 'Z', 'name' => ''])->assertStatus(422), 'name'))->toBe('acad.validation.name_required');

    // `academic_year` se ignora (el espacio no tiene curso académico, OPEN-ACAD-33).
    $withYear = Tt::postFacility($tenant, $admin, ['code' => 'CONANIO', 'academic_year' => '01JA0000000000000000000099'])->assertCreated();
    expect($withYear->json())->not->toHaveKey('academic_year');
});

test('CA-ACAD-115 api.md §12.4: GET /facilities pagina, filtra por facility_type (múltiple) y ordena por code; un tipo fuera de vocabulario o un sort ajeno es 422', function (): void {
    [$tenant, $admin] = Api::setup('fac-115d');

    foreach ([['aula', 'B1'], ['aula', 'A1'], ['recurso', 'C1']] as [$type, $code]) {
        Tt::postFacility($tenant, $admin, ['facility_type' => $type, 'code' => $code, 'name' => $code])->assertCreated();
    }

    $get = function (string $query) use ($tenant, $admin) {
        resetSessionState();

        return test()->actingAs($admin)->getJson(Api::url($tenant, '/facilities'.$query));
    };

    expect(array_column($get('')->assertOk()->json('data'), 'code'))->toBe(['A1', 'B1', 'C1'])
        ->and(array_column($get('?sort=-code')->assertOk()->json('data'), 'code'))->toBe(['C1', 'B1', 'A1'])
        ->and(array_column($get('?facility_type=recurso')->assertOk()->json('data'), 'code'))->toBe(['C1'])
        ->and(array_column($get('?facility_type=recurso,aula')->assertOk()->json('data'), 'code'))->toBe(['A1', 'B1', 'C1'])
        ->and($get('?per_page=2')->assertOk()->json('meta.total'))->toBe(3);

    $get('?facility_type=laboratorio')->assertStatus(422);
    $get('?sort=name')->assertStatus(422);
    $get('?per_page=101')->assertStatus(422);
});

test('CA-ACAD-116 RN-ACAD-102: un aula con una sesión viva en un curso activo no se borra (409 has_dependents con timetable_sessions); con sesiones solo en un curso cerrado sí (204)', function (): void {
    [$tenant, $admin, $year] = Api::setup('fac-116');
    $tree = AcadTestHelpers::tree($tenant, $year);
    $level = $tree['level']->public_id;
    $frame = Tt::postFrame($tenant, $admin, Tt::frameBody($level))->assertCreated()->json();
    $assignment = Tt::assignment($tenant, $year, $tree['group'], $tree['groupSubject'], $admin);
    $roomId = Tt::postFacility($tenant, $admin)->json('public_id');
    $session = Tt::session($tenant, $year, $frame['slots'][0]['public_id'], $assignment);

    // La sesión usa el aula (se fija por el modelo, sin API: el servicio de sesiones llega con la Core).
    app(TenantContext::class)->runFor($tenant->id, function () use ($session, $roomId): void {
        $session->facility_id = DB::table('facilities')->where('public_id', $roomId)->value('id');
        $session->facility_type = 'aula';
        $session->save();
    });

    resetSessionState();
    $conflict = test()->actingAs($admin)->deleteJson(Api::url($tenant, "/facilities/{$roomId}"))->assertStatus(409);
    expect($conflict->json('errors.dependents.0.code'))->toBe('acad.conflict.has_dependents')
        ->and($conflict->json('errors.dependents.0.params.dependents'))->toBe(['timetable_sessions']);

    // Con el curso cerrado, la referencia se conserva y el borrado es posible.
    CursoTestHelpers::setStatus($tenant, $year, AcademicYearStatus::Cerrado);
    resetSessionState();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/facilities/{$roomId}"))->assertNoContent();

    expect(Tt::count($tenant, 'facilities'))->toBe(0)
        ->and(Tt::count($tenant, 'facilities', alive: false))->toBe(1)
        ->and(Tt::count($tenant, 'timetable_sessions'))->toBe(1);
});

test('CA-ACAD-128 RN-ACAD-101: con el curso cerrado los espacios siguen editables (no tienen curso académico)', function (): void {
    [$tenant, $admin, $year] = Api::setup('fac-128');
    $id = Tt::postFacility($tenant, $admin)->assertCreated()->json('public_id');

    CursoTestHelpers::setStatus($tenant, $year, AcademicYearStatus::Cerrado);

    Tt::postFacility($tenant, $admin, ['code' => 'NUEVA'])->assertCreated();
    resetSessionState();
    test()->actingAs($admin)->patchJson(Api::url($tenant, "/facilities/{$id}"), ['name' => 'Renombrada'])->assertOk();
    resetSessionState();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/facilities/{$id}"))->assertNoContent();
});

test('CA-ACAD-132 INV-001: un espacio de otro centro es 404 en detalle, PATCH y DELETE, nunca aparece en el listado, y el código de otro centro no choca con el propio', function (): void {
    [$tenantA, $adminA] = Api::setup('fac-132a');
    [$tenantB, $adminB] = Api::setup('fac-132b');

    $foreign = Tt::postFacility($tenantB, $adminB)->assertCreated()->json('public_id');
    $own = Tt::postFacility($tenantA, $adminA)->assertCreated()->json('public_id');

    foreach ([['getJson', null], ['patchJson', ['name' => 'X']], ['deleteJson', null]] as [$method, $body]) {
        resetSessionState();
        $response = $body === null
            ? test()->actingAs($adminA)->{$method}(Api::url($tenantA, "/facilities/{$foreign}"))
            : test()->actingAs($adminA)->{$method}(Api::url($tenantA, "/facilities/{$foreign}"), $body);
        $response->assertNotFound();
    }

    resetSessionState();
    $list = test()->actingAs($adminA)->getJson(Api::url($tenantA, '/facilities'))->assertOk();
    expect(array_column($list->json('data'), 'public_id'))->toBe([$own])
        ->and(Tt::count($tenantB, 'facilities'))->toBe(1);
});
