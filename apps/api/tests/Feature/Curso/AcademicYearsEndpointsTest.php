<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Curso\Domain\AcademicYearClosureCheck;
use App\Modules\Curso\Domain\AcademicYearClosureFailure;
use App\Modules\Curso\Domain\AcademicYearClosureRegistry;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Modules\Curso\Infrastructure\CursoServiceProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArchitectureModules;
use Tests\Support\CursoTestHelpers;

// REQ-CURSO-001 (paso 1.10), docs/modulos/REQ-CURSO/api.md y funcional.md
// §13.1, §13.2, §13.4. Los seis endpoints, de punta a punta con PostgreSQL
// real, permisos reales y los dos aislamientos (INV-001, INV-002, INV-015).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

function yearsUrl(object $tenant, string $path = ''): string
{
    return coreApiUrl($tenant->slug, '/academic-years'.$path);
}

/**
 * Usuario activo con un rol personalizado que concede EXACTAMENTE los
 * permisos indicados (ámbito `todos`). Un rol que no existía: las reglas de
 * curso no comparan códigos de rol (RN-PERM-46).
 *
 * @param  list<string>  $codes
 */
function userWithCursoPermissions(object $tenant, User $admin, string $local, array $codes): User
{
    resetSessionState();
    $roleId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => "rol_{$local}",
        'name' => "Rol {$local}",
        'permissions' => array_map(static fn (string $c): array => ['code' => $c, 'effect' => 'allow', 'scope' => 'todos'], $codes),
    ])->assertCreated()->json('public_id');

    resetSessionState();
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => "{$local}@example.com",
        'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
        'send_invitation' => false,
        'role_ids' => [$roleId],
    ])->assertCreated();

    $user = app(TenantContext::class)->runFor($tenant->id, function () use ($local): User {
        $user = User::query()->where('email', "{$local}@example.com")->firstOrFail();
        $user->status = UserStatus::Activo;
        $user->save();

        return $user;
    });

    resetSessionState();

    return $user;
}

/** @return array<string, mixed> */
function validYear(array $overrides = []): array
{
    return array_merge(['code' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'], $overrides);
}

// ───────────────────────── 13.1 Alta y edición ─────────────────────────

test('CA-CURSO-001 RN-CURSO-03: POST crea el curso en planificacion, con public_id ULID, fechas AAAA-MM-DD, Location y auditoría', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-001');

    $response = test()->actingAs($admin)->postJson(yearsUrl($tenant), validYear())
        ->assertCreated()
        ->assertJsonPath('code', '2026-2027')
        ->assertJsonPath('starts_on', '2026-09-01')
        ->assertJsonPath('ends_on', '2027-06-30')
        ->assertJsonPath('status', 'planificacion');

    $publicId = $response->json('public_id');

    expect($publicId)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($response->headers->get('Location'))->toEndWith("/api/v1/academic-years/{$publicId}")
        // ADR-038 §3.1: recurso individual desnudo; nada interno.
        ->and($response->json())->toHaveKeys(['public_id', 'code', 'starts_on', 'ends_on', 'status', 'created_at', 'updated_at'])
        ->and($response->json())->not->toHaveKeys(['id', 'tenant_id', 'created_by', 'updated_by', 'deleted_at']);

    app(TenantContext::class)->runFor($tenant->id, function () use ($publicId): void {
        $year = AcademicYear::query()->where('public_id', $publicId)->firstOrFail();
        $log = AuditLog::query()->where('auditable_type', 'academic_year')->where('auditable_id', $year->id)->where('event', 'created')->first();

        expect($log)->not->toBeNull()
            ->and($log->changes['code'])->toEqual(['from' => null, 'to' => '2026-2027']);
    });
});

test('CA-CURSO-002 RN-CURSO-03: un POST con status se rechaza con 422 status_not_editable y no se crea nada', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-002');

    test()->actingAs($admin)->postJson(yearsUrl($tenant), validYear(['status' => 'activo']))
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:pge:error:validation')
        ->assertJsonPath('errors.status.0.code', 'curso.validation.status_not_editable');

    $count = app(TenantContext::class)->runFor($tenant->id, fn () => AcademicYear::query()->count());

    expect($count)->toBe(0);
});

test('CA-CURSO-003 RN-CURSO-04: con un curso en planificacion, crear otro responde 409 planning_exists con el curso existente en params', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-003');
    $planning = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-09-01', '2027-06-30');

    test()->actingAs($admin)->postJson(yearsUrl($tenant), validYear(['code' => '2027-2028', 'starts_on' => '2027-09-01', 'ends_on' => '2028-06-30']))
        ->assertStatus(409)
        ->assertJsonPath('type', 'urn:pge:error:conflict')
        ->assertJsonPath('errors.academic_year.0.code', 'curso.conflict.planning_exists')
        ->assertJsonPath('errors.academic_year.0.params.public_id', $planning->public_id)
        ->assertJsonPath('errors.academic_year.0.params.code', '2026-2027');
});

test('CA-CURSO-004 RN-CURSO-01 RMT-009: un código repetido en el centro es 422 code_taken, y el mismo código en otro centro se acepta', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('cur-004a');
    [$tenantB, $adminB] = provisionCoreTenant('cur-004b');
    CursoTestHelpers::year($tenantA, '2025-2026', AcademicYearStatus::Activo, '2025-09-01', '2026-06-30');

    test()->actingAs($adminA)->postJson(yearsUrl($tenantA), validYear(['code' => '2025-2026']))
        ->assertStatus(422)
        ->assertJsonPath('errors.code.0.code', 'curso.validation.code_taken');

    resetSessionState();
    CursoTestHelpers::year($tenantB, '2025-2026', AcademicYearStatus::Cerrado, '2025-09-01', '2026-06-30');
    test()->actingAs($adminB)->postJson(yearsUrl($tenantB), validYear(['code' => '2025-2026']))->assertStatus(422);

    // En otro centro sin ese código: se acepta.
    [$tenantC, $adminC] = provisionCoreTenant('cur-004c');
    resetSessionState();
    test()->actingAs($adminC)->postJson(yearsUrl($tenantC), validYear(['code' => '2025-2026']))->assertCreated();
});

test('RN-CURSO-01: el código se recorta y no puede quedar vacío', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-code');

    test()->actingAs($admin)->postJson(yearsUrl($tenant), validYear(['code' => '   ']))
        ->assertStatus(422)->assertJsonPath('errors.code.0.code', 'curso.validation.code_required');
    test()->actingAs($admin)->postJson(yearsUrl($tenant), ['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])
        ->assertStatus(422)->assertJsonPath('errors.code.0.code', 'curso.validation.code_required');
    test()->actingAs($admin)->postJson(yearsUrl($tenant), validYear(['code' => '  2026-2027  ']))
        ->assertCreated()->assertJsonPath('code', '2026-2027');
});

test('CA-CURSO-005 RN-CURSO-02 INV-010: ends_on igual o anterior a starts_on es 422 ends_before_start, no una violación del CHECK', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-005');

    test()->actingAs($admin)->postJson(yearsUrl($tenant), validYear(['ends_on' => '2026-09-01']))
        ->assertStatus(422)->assertJsonPath('errors.ends_on.0.code', 'curso.validation.ends_before_start');
    test()->actingAs($admin)->postJson(yearsUrl($tenant), validYear(['ends_on' => '2026-08-31']))
        ->assertStatus(422)->assertJsonPath('errors.ends_on.0.code', 'curso.validation.ends_before_start');

    // Forma de fecha: la valida la petición antes de llegar a la regla de negocio.
    test()->actingAs($admin)->postJson(yearsUrl($tenant), validYear(['starts_on' => '01/09/2026']))
        ->assertStatus(422)->assertJsonPath('errors.starts_on.0.code', 'core.validation.date_format');
    test()->actingAs($admin)->postJson(yearsUrl($tenant), ['code' => 'x'])
        ->assertStatus(422)->assertJsonPath('errors.starts_on.0.code', 'core.validation.required');
});

test('CA-CURSO-006 RN-CURSO-05: un curso que se solapa con otro es 422 dates_overlap con el código del solapado; los contiguos no se solapan', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-006');
    CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Activo, '2026-09-01', '2027-08-31');

    test()->actingAs($admin)->postJson(yearsUrl($tenant), validYear(['code' => '2027-sol', 'starts_on' => '2027-06-01', 'ends_on' => '2028-06-30']))
        ->assertStatus(422)
        ->assertJsonPath('errors.starts_on.0.code', 'curso.validation.dates_overlap')
        ->assertJsonPath('errors.starts_on.0.params.code', '2026-2027');

    // El último día del anterior es también del nuevo: solape (límites inclusive).
    test()->actingAs($admin)->postJson(yearsUrl($tenant), validYear(['code' => '2027-sol2', 'starts_on' => '2027-08-31', 'ends_on' => '2028-06-30']))
        ->assertStatus(422)->assertJsonPath('errors.starts_on.0.code', 'curso.validation.dates_overlap');

    // Contiguo: empieza el día siguiente.
    test()->actingAs($admin)->postJson(yearsUrl($tenant), validYear(['code' => '2027-2028', 'starts_on' => '2027-09-01', 'ends_on' => '2028-06-30']))
        ->assertCreated();
});

test('CA-CURSO-007 RN-CURSO-06: PATCH de un curso en planificacion devuelve el recurso completo y audita los valores anterior y nuevo; un PATCH no se solapa consigo mismo', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-007');
    $year = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-09-01', '2027-06-30');

    // Mismo rango de fechas sobre sí mismo: el propio curso se excluye del solape y de la unicidad.
    test()->actingAs($admin)->patchJson(yearsUrl($tenant, "/{$year->public_id}"), ['code' => '2026-2027', 'ends_on' => '2027-06-29'])
        ->assertOk();

    $response = test()->actingAs($admin)->patchJson(yearsUrl($tenant, "/{$year->public_id}"), ['code' => '2026-27', 'starts_on' => '2026-09-02'])
        ->assertOk()
        ->assertJsonPath('public_id', $year->public_id)
        ->assertJsonPath('code', '2026-27')
        ->assertJsonPath('starts_on', '2026-09-02')
        ->assertJsonPath('ends_on', '2027-06-29')
        ->assertJsonPath('status', 'planificacion');

    expect($response->json())->toHaveKeys(['public_id', 'code', 'starts_on', 'ends_on', 'status', 'created_at', 'updated_at']);

    app(TenantContext::class)->runFor($tenant->id, function () use ($year): void {
        $log = AuditLog::query()->where('auditable_type', 'academic_year')->where('auditable_id', $year->id)->where('event', 'updated')->latest('id')->first();

        expect($log->changes['code'])->toEqual(['from' => '2026-2027', 'to' => '2026-27'])
            ->and($log->changes['starts_on']['to'])->toContain('2026-09-02');
    });
});

test('CA-CURSO-008 RN-CURSO-06: PATCH de un curso activo o cerrado es 409 not_editable y la fila no cambia; status en el PATCH es 422', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-008');
    $active = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Activo, '2026-09-01', '2027-06-30');
    $closed = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Cerrado, '2025-09-01', '2026-06-30');

    foreach ([$active, $closed] as $year) {
        test()->actingAs($admin)->patchJson(yearsUrl($tenant, "/{$year->public_id}"), ['code' => 'nuevo'])
            ->assertStatus(409)
            ->assertJsonPath('type', 'urn:pge:error:conflict')
            ->assertJsonPath('errors.academic_year.0.code', 'curso.conflict.not_editable');
    }

    $codes = app(TenantContext::class)->runFor($tenant->id, fn () => AcademicYear::query()->orderBy('id')->pluck('code')->all());

    expect($codes)->toBe(['2026-2027', '2025-2026']);

    $planning = CursoTestHelpers::year($tenant, '2027-2028', AcademicYearStatus::Planificacion, '2027-09-01', '2028-06-30');
    test()->actingAs($admin)->patchJson(yearsUrl($tenant, "/{$planning->public_id}"), ['status' => 'activo'])
        ->assertStatus(422)->assertJsonPath('errors.status.0.code', 'curso.validation.status_not_editable');
});

// ───────────────────── 13.2 Transiciones y un solo activo ─────────────────────

test('CA-CURSO-020 RN-CURSO-13: activar un curso en planificacion lo deja activo y audita changes.status {from, to}', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-020');
    $year = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion);

    test()->actingAs($admin)->postJson(yearsUrl($tenant, "/{$year->public_id}/status"), ['status' => 'activo'])
        ->assertOk()->assertJsonPath('status', 'activo')->assertJsonPath('public_id', $year->public_id);

    app(TenantContext::class)->runFor($tenant->id, function () use ($year): void {
        $log = AuditLog::query()->where('auditable_type', 'academic_year')->where('auditable_id', $year->id)->where('event', 'updated')->latest('id')->first();

        expect($log->changes['status'])->toEqual(['from' => 'planificacion', 'to' => 'activo']);
    });
});

test('CA-CURSO-021 RN-CURSO-11: activar con otro curso activo es 409 active_exists con el activo en params y ninguno cambia', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-021');
    $active = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo, '2025-09-01', '2026-06-30');
    $planning = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion);

    test()->actingAs($admin)->postJson(yearsUrl($tenant, "/{$planning->public_id}/status"), ['status' => 'activo'])
        ->assertStatus(409)
        ->assertJsonPath('errors.academic_year.0.code', 'curso.conflict.active_exists')
        ->assertJsonPath('errors.academic_year.0.params.public_id', $active->public_id)
        ->assertJsonPath('errors.academic_year.0.params.code', '2025-2026');

    $statuses = app(TenantContext::class)->runFor($tenant->id, fn () => AcademicYear::query()->orderBy('id')->pluck('status')->map->value->all());

    expect($statuses)->toBe(['activo', 'planificacion']);
});

test('CA-CURSO-022: cerrar el activo lo deja cerrado y GET /academic-years/current pasa a 404 curso.no_active_year', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-022');
    $year = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo, '2025-09-01', '2026-06-30');

    test()->actingAs($admin)->getJson(yearsUrl($tenant, '/current'))->assertOk()->assertJsonPath('public_id', $year->public_id);

    test()->actingAs($admin)->postJson(yearsUrl($tenant, "/{$year->public_id}/status"), ['status' => 'cerrado'])
        ->assertOk()->assertJsonPath('status', 'cerrado');

    test()->actingAs($admin)->getJson(yearsUrl($tenant, '/current'))
        ->assertNotFound()
        ->assertJsonPath('type', 'urn:pge:error:not-found')
        ->assertJsonPath('errors.academic_year.0.code', 'curso.no_active_year');
});

test('CA-CURSO-023 RN-CURSO-12: toda transición no admitida es 409 invalid_transition con from y to', function (string $from, string $to): void {
    [$tenant, $admin] = provisionCoreTenant('cur-023');
    $year = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::from($from));

    test()->actingAs($admin)->postJson(yearsUrl($tenant, "/{$year->public_id}/status"), ['status' => $to])
        ->assertStatus(409)
        ->assertJsonPath('errors.academic_year.0.code', 'curso.conflict.invalid_transition')
        ->assertJsonPath('errors.academic_year.0.params', ['from' => $from, 'to' => $to]);

    $status = app(TenantContext::class)->runFor($tenant->id, fn () => AcademicYear::query()->firstOrFail()->status->value);

    expect($status)->toBe($from);
})->with([
    'planificacion → cerrado' => ['planificacion', 'cerrado'],
    'cerrado → activo (sin reapertura, OPEN-CURSO-08)' => ['cerrado', 'activo'],
    'activo → activo' => ['activo', 'activo'],
    'archivado → activo' => ['archivado', 'activo'],
    'archivado → cerrado' => ['archivado', 'cerrado'],
]);

test('CA-CURSO-023 api.md §2: el cuerpo solo admite activo o cerrado; planificacion, archivado u otro valor es 422 y no cambia nada', function (string $to): void {
    [$tenant, $admin] = provisionCoreTenant('cur-023b');
    $year = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo);

    test()->actingAs($admin)->postJson(yearsUrl($tenant, "/{$year->public_id}/status"), ['status' => $to])->assertStatus(422);
    test()->actingAs($admin)->postJson(yearsUrl($tenant, "/{$year->public_id}/status"), [])->assertStatus(422);

    expect(app(TenantContext::class)->runFor($tenant->id, fn () => AcademicYear::query()->firstOrFail()->status->value))->toBe('activo');
})->with(['planificacion', 'archivado', 'otro']);

test('CA-CURSO-024 OPEN-CURSO-13: curso_academico.actualizar no basta para activar o cerrar, ni estado_curso_academico.actualizar para editar', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-024');
    $year = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion);

    $editor = userWithCursoPermissions($tenant, $admin, 'editor024', ['curso_academico.leer', 'curso_academico.actualizar']);
    $closer = userWithCursoPermissions($tenant, $admin, 'cerrador024', ['curso_academico.leer', 'estado_curso_academico.actualizar']);

    test()->actingAs($editor)->postJson(yearsUrl($tenant, "/{$year->public_id}/status"), ['status' => 'activo'])->assertForbidden();
    test()->actingAs($editor)->patchJson(yearsUrl($tenant, "/{$year->public_id}"), ['code' => '2026-27'])->assertOk();

    resetSessionState();
    test()->actingAs($closer)->patchJson(yearsUrl($tenant, "/{$year->public_id}"), ['code' => 'otro'])->assertForbidden();
    test()->actingAs($closer)->postJson(yearsUrl($tenant, "/{$year->public_id}/status"), ['status' => 'activo'])->assertOk();
});

test('CA-CURSO-026 RN-CURSO-30: una validación de cierre que falla da 409 con una entrada en errors.closure[] y el curso sigue activo; con el registro vacío se permite', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-026');
    $year = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo, '2025-09-01', '2026-06-30');

    app(AcademicYearClosureRegistry::class)->register(new class implements AcademicYearClosureCheck
    {
        public function check(AcademicYearSummary $year): ?AcademicYearClosureFailure
        {
            return new AcademicYearClosureFailure('calif.closure.unpublished_grades', 'Hay calificaciones sin publicar.', ['count' => 3]);
        }
    });

    test()->actingAs($admin)->postJson(yearsUrl($tenant, "/{$year->public_id}/status"), ['status' => 'cerrado'])
        ->assertStatus(409)
        ->assertJsonPath('errors.closure.0.code', 'calif.closure.unpublished_grades')
        ->assertJsonPath('errors.closure.0.message', 'Hay calificaciones sin publicar.')
        ->assertJsonPath('errors.closure.0.params.count', 3)
        ->assertJsonCount(1, 'errors.closure');

    expect(app(TenantContext::class)->runFor($tenant->id, fn () => AcademicYear::query()->firstOrFail()->status->value))->toBe('activo');
});

test('CA-CURSO-026 RN-CURSO-30: con el registro vacío (estado de 1.10) el cierre se permite', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-026b');
    $year = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo, '2025-09-01', '2026-06-30');

    expect(app(AcademicYearClosureRegistry::class)->all())->toBe([]);

    test()->actingAs($admin)->postJson(yearsUrl($tenant, "/{$year->public_id}/status"), ['status' => 'cerrado'])->assertOk();
});

// ─────────────────────────── Lectura ───────────────────────────

test('GET /academic-years: paginación por página, orden por defecto -starts_on, filtro status múltiple y 422 con valores inválidos', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-list');
    CursoTestHelpers::year($tenant, '2023-2024', AcademicYearStatus::Cerrado, '2023-09-01', '2024-06-30');
    CursoTestHelpers::year($tenant, '2024-2025', AcademicYearStatus::Cerrado, '2024-09-01', '2025-06-30');
    CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo, '2025-09-01', '2026-06-30');
    CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-09-01', '2027-06-30');

    $all = test()->actingAs($admin)->getJson(yearsUrl($tenant))->assertOk();

    expect(array_column($all->json('data'), 'code'))->toBe(['2026-2027', '2025-2026', '2024-2025', '2023-2024'])
        ->and($all->json('meta'))->toBe(['current_page' => 1, 'per_page' => 25, 'total' => 4, 'last_page' => 1]);

    $page = test()->actingAs($admin)->getJson(yearsUrl($tenant, '?per_page=2&page=2&sort=code'))->assertOk();
    expect(array_column($page->json('data'), 'code'))->toBe(['2025-2026', '2026-2027'])
        ->and($page->json('meta.last_page'))->toBe(2);

    $filtered = test()->actingAs($admin)->getJson(yearsUrl($tenant, '?status=activo,cerrado&sort=-code'))->assertOk();
    expect(array_column($filtered->json('data'), 'code'))->toBe(['2025-2026', '2024-2025', '2023-2024']);

    test()->actingAs($admin)->getJson(yearsUrl($tenant, '?per_page=101'))->assertStatus(422);
    test()->actingAs($admin)->getJson(yearsUrl($tenant, '?status=inexistente'))->assertStatus(422);
    test()->actingAs($admin)->getJson(yearsUrl($tenant, '?sort=status'))->assertStatus(422);
    // ADR-038 §5.2: un parámetro desconocido se ignora.
    test()->actingAs($admin)->getJson(yearsUrl($tenant, '?q=algo'))->assertOk();
});

test('GET /academic-years/{public_id} y /current: el detalle y el curso activo; current se registra antes que el parámetro', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-show');
    $closed = CursoTestHelpers::year($tenant, '2024-2025', AcademicYearStatus::Cerrado, '2024-09-01', '2025-06-30');
    $active = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo, '2025-09-01', '2026-06-30');

    test()->actingAs($admin)->getJson(yearsUrl($tenant, "/{$closed->public_id}"))->assertOk()->assertJsonPath('status', 'cerrado');
    test()->actingAs($admin)->getJson(yearsUrl($tenant, '/current'))->assertOk()->assertJsonPath('public_id', $active->public_id);
    test()->actingAs($admin)->getJson(yearsUrl($tenant, '/01JA0000000000000000000000'))->assertNotFound();
});

test('CA-CURSO-060 INV-002: todo endpoint responde 401 sin sesión y 403 sin su permiso; current es autoservicio y responde 200 a quien no tiene ningún permiso', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-060');
    $year = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo, '2025-09-01', '2026-06-30');
    $nobody = userWithCursoPermissions($tenant, $admin, 'nadie060', ['usuario.leer']);

    $routes = [
        ['get', ''],
        ['get', "/{$year->public_id}"],
        ['post', ''],
        ['patch', "/{$year->public_id}"],
        ['post', "/{$year->public_id}/status"],
    ];

    resetSessionState();

    foreach ($routes as [$method, $path]) {
        test()->{$method.'Json'}(yearsUrl($tenant, $path), ['status' => 'cerrado'])->assertUnauthorized();
    }

    test()->getJson(yearsUrl($tenant, '/current'))->assertUnauthorized();

    foreach ($routes as [$method, $path]) {
        resetSessionState();
        test()->actingAs($nobody)->{$method.'Json'}(yearsUrl($tenant, $path), ['status' => 'cerrado'])->assertForbidden();
    }

    resetSessionState();
    test()->actingAs($nobody)->getJson(yearsUrl($tenant, '/current'))->assertOk()->assertJsonPath('public_id', $year->public_id);
});

test('CA-CURSO-061 INV-001: con dos centros ningún endpoint devuelve, modifica ni cuenta cursos del otro; el public_id ajeno es 404, nunca 403', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('cur-061a');
    [$tenantB, $adminB] = provisionCoreTenant('cur-061b');
    $yearA = CursoTestHelpers::year($tenantA, '2025-2026', AcademicYearStatus::Planificacion, '2025-09-01', '2026-06-30');
    $yearB = CursoTestHelpers::year($tenantB, '2025-2026', AcademicYearStatus::Planificacion, '2025-09-01', '2026-06-30');

    // Listado y cuenta: solo los del propio centro.
    $list = test()->actingAs($adminA)->getJson(yearsUrl($tenantA))->assertOk();
    expect(array_column($list->json('data'), 'public_id'))->toBe([$yearA->public_id])->and($list->json('meta.total'))->toBe(1);

    test()->actingAs($adminA)->getJson(yearsUrl($tenantA, "/{$yearB->public_id}"))->assertNotFound();
    test()->actingAs($adminA)->patchJson(yearsUrl($tenantA, "/{$yearB->public_id}"), ['code' => 'robado'])->assertNotFound();
    test()->actingAs($adminA)->postJson(yearsUrl($tenantA, "/{$yearB->public_id}/status"), ['status' => 'activo'])->assertNotFound();

    // El curso de B no se ha tocado, ni activado.
    $b = app(TenantContext::class)->runFor($tenantB->id, fn () => AcademicYear::query()->where('public_id', $yearB->public_id)->firstOrFail());
    expect($b->code)->toBe('2025-2026')->and($b->status)->toBe(AcademicYearStatus::Planificacion);

    // El curso activo de A no es el de B.
    resetSessionState();
    CursoTestHelpers::setStatus($tenantB, $yearB, AcademicYearStatus::Activo);
    test()->actingAs($adminA)->getJson(yearsUrl($tenantA, '/current'))->assertNotFound();
});

test('CA-CURSO-062 OPEN-CURSO-13: todos los permisos de curso son de ámbito todos, no hay resolutor y curso no entra en el mapa de AR-10', function (): void {
    $curso = array_values(array_filter(ArchitectureModules::declaredPermissions(), static fn (array $p): bool => in_array($p['resource'], ['curso_academico', 'estado_curso_academico', 'curso_historico'], true)));

    expect($curso)->toHaveCount(5);

    foreach ($curso as $permission) {
        expect($permission['applicable_scopes'])->toBe(['todos'], $permission['code'])
            ->and($permission['is_special_category'])->toBeFalse();
    }
});

test('CA-CURSO-063 OPEN-CURSO-01: tras platform:sync-registry, permissions contiene exactamente los cinco códigos de curso y modules el módulo curso esencial sin dependencias', function (): void {
    test()->artisan('platform:sync-registry')->run();

    $permissions = Permission::query()->where('module_code', 'curso')->whereNull('retired_at')->orderBy('code')->get();

    expect($permissions->pluck('code')->all())->toBe([
        'curso_academico.actualizar', 'curso_academico.crear', 'curso_academico.leer',
        'curso_historico.leer', 'estado_curso_academico.actualizar',
    ])->and($permissions->every(fn ($p) => $p->applicable_scopes === ['todos'] && $p->is_special_category === false))->toBeTrue();

    $module = DB::table('modules')->where('code', 'curso')->first();

    expect($module)->not->toBeNull()
        ->and($module->name_key)->toBe('modules.curso')
        ->and($module->phase)->toBe('1')
        ->and((new CursoServiceProvider(app()))->moduleDescriptor())->toMatchArray(['essential' => true, 'depends_on' => []]);
});

test('CA-CURSO-064 OPEN-CURSO-14: tras el aprovisionamiento los roles predefinidos tienen exactamente las concesiones de permisos.md §4, y un rol personalizado con los mismos permisos obtiene el mismo resultado', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-064');

    $granted = app(TenantContext::class)->runFor($tenant->id, function (): array {
        $result = [];

        foreach (Role::query()->get() as $role) {
            $codes = PermissionRole::query()->where('role_id', $role->id)->where('permission_code', 'like', 'curso%')->orWhere(fn ($q) => $q->where('role_id', $role->id)->where('permission_code', 'like', 'estado_curso%'))->pluck('permission_code')->sort()->values()->all();

            if ($codes !== []) {
                $result[$role->code] = $codes;
            }
        }

        return $result;
    });

    $all = ['curso_academico.actualizar', 'curso_academico.crear', 'curso_academico.leer', 'curso_historico.leer', 'estado_curso_academico.actualizar'];

    expect($granted)->toBe([
        'administrador_centro' => $all,
        'direccion' => ['curso_academico.leer', 'curso_historico.leer'],
        'secretaria' => ['curso_academico.leer', 'curso_historico.leer'],
    ]);

    // Un rol personalizado con los cinco permisos hace exactamente lo mismo que el administrador.
    $custom = userWithCursoPermissions($tenant, $admin, 'custom064', $all);
    resetSessionState();
    test()->actingAs($custom)->postJson(yearsUrl($tenant), validYear())->assertCreated();
});

test('CA-CURSO-064 OPEN-CURSO-14: el comando curso:grant-year-permissions concede lo que falta a los roles predefinidos de un centro existente, es idempotente y no toca roles personalizados', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-064b');
    $custom = userWithCursoPermissions($tenant, $admin, 'custom064b', ['usuario.leer']);

    // Un centro «anterior al despliegue»: se retiran las concesiones de curso.
    app(TenantContext::class)->runFor($tenant->id, function (): void {
        PermissionRole::query()->where(fn ($q) => $q->where('permission_code', 'like', 'curso%')->orWhere('permission_code', 'like', 'estado_curso%'))->get()->each->delete();
    });

    $count = fn (): int => app(TenantContext::class)->runFor($tenant->id, fn () => PermissionRole::query()->where(fn ($q) => $q->where('permission_code', 'like', 'curso%')->orWhere('permission_code', 'like', 'estado_curso%'))->count());

    expect($count())->toBe(0);

    test()->artisan('curso:grant-year-permissions')->assertSuccessful();
    expect($count())->toBe(9);

    test()->artisan('curso:grant-year-permissions')->assertSuccessful();
    expect($count())->toBe(9);

    $customRoleCodes = app(TenantContext::class)->runFor($tenant->id, fn () => PermissionRole::query()->whereHas('role', fn ($q) => $q->where('code', 'rol_custom064b'))->pluck('permission_code')->all());
    expect($customRoleCodes)->toBe(['usuario.leer']);
    expect($custom)->toBeInstanceOf(User::class);
});

test('CA-CURSO-065 CA-PERM-134: las etiquetas de los tres recursos de curso existen en los cuatro idiomas', function (): void {
    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        foreach (['curso_academico', 'estado_curso_academico', 'curso_historico'] as $resource) {
            $key = "curso.permissions.resources.{$resource}";
            $label = trans($key, [], $locale);

            expect($label)->not->toBe($key, "{$key} sin traducir en {$locale}")->and($label)->not->toBe('');
        }

        foreach (['planificacion', 'activo', 'cerrado', 'archivado'] as $status) {
            expect(trans("curso.status.{$status}", [], $locale))->not->toBe("curso.status.{$status}");
        }

        expect(trans('modules.curso', [], $locale))->not->toBe('modules.curso');
    }
});

test('issue #60: los errores de curso llevan su propio prefijo curso.* y nunca core.curso.*', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-60');
    $codes = [];

    foreach ([validYear(['code' => '']), validYear(['ends_on' => '2026-01-01']), validYear(['status' => 'activo'])] as $body) {
        $response = test()->actingAs($admin)->postJson(yearsUrl($tenant), $body)->assertStatus(422);

        foreach ($response->json('errors') as $items) {
            array_push($codes, ...array_column($items, 'code'));
        }
    }

    expect($codes)->not->toBeEmpty();

    foreach ($codes as $code) {
        expect($code)->toStartWith('curso.')->not->toContain('core.curso');
    }
});

test('RN-CURSO-14: el servidor no activa un curso por calendario ni exige haber llegado a starts_on para activarlo', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-014');
    $future = CursoTestHelpers::year($tenant, '2099-2100', AcademicYearStatus::Planificacion, '2099-09-01', '2100-06-30');

    test()->actingAs($admin)->getJson(yearsUrl($tenant, '/current'))->assertNotFound();
    test()->actingAs($admin)->postJson(yearsUrl($tenant, "/{$future->public_id}/status"), ['status' => 'activo'])->assertOk();
});
