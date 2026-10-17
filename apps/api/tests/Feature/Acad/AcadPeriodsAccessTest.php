<?php

use App\Http\Middleware\RequireAuthenticatedUser;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadPeriodHelpers as Periods;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001 (1.11d-a), docs/modulos/REQ-ACAD/funcional.md §13.13-§13.15 y
// permisos.md §2.2: acceso a los cinco endpoints de períodos lectivos.
// CA-ACAD-088 (escritura en curso cerrado), CA-ACAD-090 (lectura denegada de
// curso cerrado), CA-ACAD-092 (curso activo/planificación y sin curso),
// CA-ACAD-093 (401/403 e independencia de los dos recursos), CA-ACAD-094
// (aislamiento entre centros, INV-001), CA-ACAD-095 (módulo no contratado,
// RMOD-009). INV-002, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Los cinco endpoints con un cuerpo válido sobre un período y una etapa dados.
 *
 * @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: string}> verbo, ruta, cuerpo, acción
 */
function periodEndpoints(string $period, string $level, string $yearPublicId): array
{
    return [
        ['GET', '/academic-periods?academic_year='.$yearPublicId, [], 'leer'],
        ['POST', '/academic-periods', ['education_level' => $level, 'period_type' => 'trimestre', 'name_translations' => ['es-ES' => 'Nuevo'], 'starts_on' => '2027-01-10', 'ends_on' => '2027-03-26'], 'crear'],
        ['GET', "/academic-periods/{$period}", [], 'leer'],
        ['PATCH', "/academic-periods/{$period}", ['ends_on' => '2026-12-20'], 'actualizar'],
        ['DELETE', "/academic-periods/{$period}", [], 'eliminar'],
    ];
}

/** Centro con una etapa y un período vivo. */
function periodAccessFixture(string $slug, AcademicYearStatus $status = AcademicYearStatus::Activo): array
{
    [$tenant, $admin, $year] = Api::setup($slug, $status, '2027-08-31');
    $level = Api::level($tenant, $admin, $year);
    resetSessionState();
    $period = test()->actingAs($admin)->postJson(Api::url($tenant, '/academic-periods'), [
        'education_level' => $level, 'period_type' => 'trimestre', 'name_translations' => ['es-ES' => 'Primero'], 'starts_on' => '2026-09-08', 'ends_on' => '2026-12-22',
    ])->assertCreated()->json('public_id');
    // Sin usuario autenticado residual: el siguiente centro del mismo test se aprovisiona limpio.
    resetSessionState();

    return [$tenant, $admin, $year, $level, $period];
}

test('CA-ACAD-093 RN-ACAD-50 INV-002: los cinco endpoints responden 401 sin sesión y 403 sin su permiso de configuracion_academica (leer, crear, actualizar, eliminar)', function (): void {
    [$tenant, $admin, $year, $level, $period] = periodAccessFixture('per-093');
    $all = ['leer', 'crear', 'actualizar', 'eliminar'];

    $users = [];
    foreach ($all as $lacking) {
        $users[$lacking] = Api::userWith($tenant, $admin, "sin_{$lacking}", array_map(
            fn (string $a): string => "configuracion_academica.{$a}",
            array_values(array_diff($all, [$lacking])),
        ));
    }
    $nobody = Api::userWith($tenant, $admin, 'nada', ['usuario.leer']);

    foreach (periodEndpoints($period, $level, $year->public_id) as [$method, $path, $body, $needs]) {
        $url = Api::url($tenant, $path);

        resetSessionState();
        test()->json($method, $url, $body)->assertUnauthorized();

        resetSessionState();
        test()->actingAs($users[$needs])->json($method, $url, $body)->assertForbidden();

        resetSessionState();
        test()->actingAs($nobody)->json($method, $url, $body)->assertForbidden();
    }
});

test('CA-ACAD-093 RN-ACAD-50: estructura_academica no da acceso a los períodos, y configuracion_academica no da acceso a ninguno de los 27 endpoints de la estructura', function (): void {
    [$tenant, $admin, $year, $level, $period] = periodAccessFixture('per-093i');
    $structureOnly = Api::userWith($tenant, $admin, 'solo_estructura', ['estructura_academica.leer', 'estructura_academica.crear', 'estructura_academica.actualizar', 'estructura_academica.eliminar']);
    $configOnly = Api::userWith($tenant, $admin, 'solo_config', ['configuracion_academica.leer', 'configuracion_academica.crear', 'configuracion_academica.actualizar', 'configuracion_academica.eliminar']);

    foreach (periodEndpoints($period, $level, $year->public_id) as [$method, $path, $body]) {
        resetSessionState();
        test()->actingAs($structureOnly)->json($method, Api::url($tenant, $path), $body)->assertForbidden();
    }

    // Los 27 endpoints de la estructura: el permiso se comprueba antes que nada, así que basta cualquier identificador.
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'acad.') && ! str_starts_with((string) $r->getName(), 'acad.academic-periods.'));

    expect($routes)->toHaveCount(27);

    foreach ($routes as $route) {
        $uri = preg_replace('/\{[^}]+\}/', '01JA0000000000000000000099', $route->uri());

        resetSessionState();
        test()->actingAs($configOnly)->json($route->methods()[0], Api::url($tenant, '/'.substr((string) $uri, strlen('api/v1/'))), [])->assertForbidden();
    }

    // Y a la inversa, con su recurso sí leen (comprobación de que el 403 de arriba es por el recurso).
    resetSessionState();
    test()->actingAs($configOnly)->getJson(Api::url($tenant, '/academic-periods'))->assertOk();
    resetSessionState();
    test()->actingAs($structureOnly)->getJson(Api::url($tenant, '/education-levels'))->assertOk();
});

test('CA-ACAD-095 RMOD-009: con acad descontratado, los cinco endpoints responden 403 module-disabled antes de comprobar el permiso', function (): void {
    [$tenant, $admin] = provisionCoreTenant('per-095');
    $year = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Activo, '2026-09-01', '2027-08-31');
    $nobody = Api::userWith($tenant, $admin, 'nada095', ['usuario.leer']);

    foreach (periodEndpoints('01JA0000000000000000000002', '01JA0000000000000000000001', $year->public_id) as [$method, $path, $body]) {
        foreach ([$admin, $nobody] as $user) {
            resetSessionState();
            $response = test()->actingAs($user)->json($method, Api::url($tenant, $path), $body)->assertForbidden();

            expect($response->json('type'))->toBe('urn:pge:error:module-disabled', "{$method} {$path}");
        }
    }
});

test('CA-ACAD-095 AR-07a: las rutas de períodos llevan autenticación, module-enabled:acad y permission:configuracion_academica.* en ese orden', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'acad.academic-periods.'));

    expect($routes->map(fn ($r) => $r->getName())->sort()->values()->all())->toBe([
        'acad.academic-periods.destroy', 'acad.academic-periods.index', 'acad.academic-periods.show', 'acad.academic-periods.store', 'acad.academic-periods.update',
    ]);

    foreach ($routes as $route) {
        $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
        $permission = collect($middleware)->first(fn (string $m) => str_starts_with($m, 'permission:configuracion_academica.'));

        expect($permission)->not->toBeNull($route->getName())
            ->and(array_search(RequireAuthenticatedUser::class, $middleware, true))->toBeLessThan(array_search('module-enabled:acad', $middleware, true))
            ->and(array_search('module-enabled:acad', $middleware, true))->toBeLessThan(array_search($permission, $middleware, true));
    }
});

test('CA-ACAD-094 INV-001 RN-ACAD-50: con períodos equivalentes en otro centro, ningún endpoint devuelve, modifica, cuenta ni borra datos ajenos: 404 (nunca 403) sobre public_id ajeno y 422 parent_not_found con una etapa ajena', function (): void {
    [$a, $adminA, $yearA, $levelA, $periodA] = periodAccessFixture('per-094x');
    [$b, $adminB, $yearB, $levelB, $periodB] = periodAccessFixture('per-094y');

    foreach ([
        ['GET', "/academic-periods/{$periodB}", []],
        ['PATCH', "/academic-periods/{$periodB}", ['ends_on' => '2026-12-20']],
        ['DELETE', "/academic-periods/{$periodB}", []],
    ] as [$method, $path, $body]) {
        resetSessionState();
        test()->actingAs($adminA)->json($method, Api::url($a, $path), $body)->assertNotFound();
    }

    // Etapa ajena en el cuerpo: 422, y nada se crea en ninguno de los dos centros.
    $foreign = Periods::post($a, $adminA, Periods::body($levelB, ['starts_on' => '2027-01-10', 'ends_on' => '2027-03-26']))->assertStatus(422);
    expect($foreign->json('errors.education_level.0.code'))->toBe('acad.validation.parent_not_found');

    // Un curso académico ajeno en el filtro: 404; el listado propio no cuenta nada del otro.
    resetSessionState();
    test()->actingAs($adminA)->getJson(Api::url($a, "/academic-periods?academic_year={$yearB->public_id}"))->assertNotFound();
    resetSessionState();
    expect(test()->actingAs($adminA)->getJson(Api::url($a, '/academic-periods'))->assertOk()->json('meta.total'))->toBe(1);
    resetSessionState();
    expect(test()->actingAs($adminA)->getJson(Api::url($a, "/academic-periods?education_level={$levelB}"))->assertOk()->json('data'))->toBe([]);

    // Nada cambió en el otro centro.
    $row = Api::row($b, 'academic_periods', $periodB);
    expect($row->ends_on)->toBe('2026-12-22')->and($row->deleted_at)->toBeNull()
        ->and(Periods::count($b))->toBe(1)->and(Periods::count($a))->toBe(1)
        ->and($periodA)->not->toBe($periodB);
});

test('CA-ACAD-088 RN-ACAD-52 RN-ACAD-07 ADR-057: en un curso cerrado, crear, modificar y borrar un período es 409 academic-year-closed y la fila no cambia; en planificación y activo se admiten', function (): void {
    foreach ([AcademicYearStatus::Planificacion, AcademicYearStatus::Activo] as $status) {
        [$tenant, $admin, , $level] = periodAccessFixture("per-088-{$status->value}", $status);
        Periods::post($tenant, $admin, Periods::body($level, ['starts_on' => '2027-01-10', 'ends_on' => '2027-03-26']))->assertCreated();
        resetSessionState();
    }

    foreach ([AcademicYearStatus::Cerrado, AcademicYearStatus::Archivado] as $status) {
        [$tenant, $admin, $year, $level, $period] = periodAccessFixture("per-088-{$status->value}");
        CursoTestHelpers::setStatus($tenant, $year, $status);
        $before = (array) Api::row($tenant, 'academic_periods', $period);

        foreach (periodEndpoints($period, $level, $year->public_id) as [$method, $path, $body]) {
            if ($method === 'GET') {
                continue;
            }

            resetSessionState();
            $response = test()->actingAs($admin)->json($method, Api::url($tenant, $path), $body)->assertStatus(409);

            expect($response->json('type'))->toBe('urn:pge:error:academic-year-closed', "{$status->value} {$method} {$path}")
                ->and($response->json('errors.academic_year.0.code'))->toBe('curso.academic_year_closed');
        }

        expect((array) Api::row($tenant, 'academic_periods', $period))->toEqual($before)
            ->and(Periods::count($tenant))->toBe(1);
        resetSessionState();
    }
});

foreach (['cerrado' => AcademicYearStatus::Cerrado, 'archivado' => AcademicYearStatus::Archivado] as $label => $status) {
    test("CA-ACAD-090 RN-ACAD-52 RN-CURSO-33: en un curso {$label}, sin curso_historico.leer el listado y el detalle de períodos responden 404 (mismo cuerpo que un recurso inexistente); con él, los datos", function () use ($status): void {
        [$tenant, $admin, $year, $level, $period] = periodAccessFixture('per-090-'.$status->value);
        CursoTestHelpers::setStatus($tenant, $year, $status);

        $reader = Api::userWith($tenant, $admin, 'lector', ['configuracion_academica.leer']);
        $historian = Api::userWith($tenant, $admin, 'historiador', ['configuracion_academica.leer', 'curso_historico.leer']);

        resetSessionState();
        $missing = test()->actingAs($reader)->getJson(Api::url($tenant, '/academic-periods/01JA0000000000000000000009'))->assertNotFound()->json();
        unset($missing['request_id'], $missing['instance']);

        foreach (["/academic-periods?academic_year={$year->public_id}", "/academic-periods?academic_year={$year->public_id}&education_level={$level}", "/academic-periods/{$period}"] as $path) {
            resetSessionState();
            $denied = test()->actingAs($reader)->getJson(Api::url($tenant, $path))->assertNotFound()->json();
            unset($denied['request_id'], $denied['instance']);

            expect($denied)->toBe($missing, $path);

            resetSessionState();
            test()->actingAs($historian)->getJson(Api::url($tenant, $path))->assertOk();
        }

        resetSessionState();
        expect(test()->actingAs($historian)->getJson(Api::url($tenant, "/academic-periods/{$period}"))->json('name'))->toBe('Primero');
    });
}

test('CA-ACAD-092 RN-ACAD-52 RN-ACAD-51: con el curso en planificación o activo, los períodos se leen sin curso_historico.leer; sin academic_year y sin curso activo, 404 curso.no_active_year; academic_year ajeno o inexistente, 404', function (): void {
    [$tenant, $admin, $year, , $period] = periodAccessFixture('per-092');
    $reader = Api::userWith($tenant, $admin, 'lector092', ['configuracion_academica.leer']);

    foreach ([AcademicYearStatus::Activo, AcademicYearStatus::Planificacion] as $status) {
        CursoTestHelpers::setStatus($tenant, $year, $status);

        foreach (["/academic-periods?academic_year={$year->public_id}", "/academic-periods/{$period}"] as $path) {
            resetSessionState();
            test()->actingAs($reader)->getJson(Api::url($tenant, $path))->assertOk();
        }
    }

    // Sin curso activo (el único está en planificación) y sin academic_year.
    resetSessionState();
    $response = test()->actingAs($reader)->getJson(Api::url($tenant, '/academic-periods'))->assertNotFound();
    expect($response->json('errors.academic_year.0.code'))->toBe('curso.no_active_year');

    foreach (['01JA0000000000000000000099', 'no-es-un-ulid'] as $unknown) {
        resetSessionState();
        test()->actingAs($reader)->getJson(Api::url($tenant, "/academic-periods?academic_year={$unknown}"))->assertNotFound();
    }

    app(TenantContext::class)->runFor($tenant->id, fn () => expect(DB::table('academic_periods')->count())->toBe(1));
});
