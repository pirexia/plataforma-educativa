<?php

use App\Http\Middleware\RequireAuthenticatedUser;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadScaleHelpers as Scales;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001 (1.11d-b), docs/modulos/REQ-ACAD/funcional.md §13.13-§13.15 y
// permisos.md §2.2: acceso a los cinco endpoints de escalas de calificación.
// CA-ACAD-088 (escritura en curso cerrado), CA-ACAD-091 (lectura denegada de
// curso cerrado), CA-ACAD-092 (curso activo/planificación y sin curso),
// CA-ACAD-093 (401/403 e independencia de los dos recursos), CA-ACAD-094
// (aislamiento entre centros, INV-001), CA-ACAD-095 (módulo no contratado,
// RMOD-009), CA-ACAD-106 (S-3). INV-002, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Los cinco endpoints con un cuerpo válido sobre una escala y un curso dados.
 *
 * @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: string}> verbo, ruta, cuerpo, acción
 */
function scaleEndpoints(string $scale, string $yearPublicId): array
{
    return [
        ['GET', '/grading-scales?academic_year='.$yearPublicId, [], 'leer'],
        ['POST', '/grading-scales', Scales::numeric($yearPublicId, ['code' => 'NUEVA']), 'crear'],
        ['GET', "/grading-scales/{$scale}", [], 'leer'],
        ['PATCH', "/grading-scales/{$scale}", ['code' => 'RENOMBRADA'], 'actualizar'],
        ['DELETE', "/grading-scales/{$scale}", [], 'eliminar'],
    ];
}

/** Centro con una escala cualitativa viva. */
function scaleAccessFixture(string $slug, AcademicYearStatus $status = AcademicYearStatus::Activo): array
{
    [$tenant, $admin, $year] = Scales::setup($slug, $status);
    $scale = Scales::create($tenant, $admin, Scales::qualitative($year->public_id));
    // Sin usuario autenticado residual: el siguiente centro del mismo test se aprovisiona limpio.
    resetSessionState();

    return [$tenant, $admin, $year, $scale];
}

test('CA-ACAD-093 RN-ACAD-50 INV-002: los cinco endpoints responden 401 sin sesión y 403 sin su permiso de configuracion_academica; el PATCH con values exige solo actualizar', function (): void {
    [$tenant, $admin, $year, $scale] = scaleAccessFixture('esc-093');
    $all = ['leer', 'crear', 'actualizar', 'eliminar'];

    $users = [];
    foreach ($all as $lacking) {
        $users[$lacking] = Api::userWith($tenant, $admin, "sin_{$lacking}", array_map(
            fn (string $a): string => "configuracion_academica.{$a}",
            array_values(array_diff($all, [$lacking])),
        ));
    }
    $nobody = Api::userWith($tenant, $admin, 'nada', ['usuario.leer']);

    foreach (scaleEndpoints($scale['public_id'], $year->public_id) as [$method, $path, $body, $needs]) {
        $url = Api::url($tenant, $path);

        resetSessionState();
        test()->json($method, $url, $body)->assertUnauthorized();

        resetSessionState();
        test()->actingAs($users[$needs])->json($method, $url, $body)->assertForbidden();

        resetSessionState();
        test()->actingAs($nobody)->json($method, $url, $body)->assertForbidden();
    }

    // El PATCH con values exige solo actualizar: quien lo tiene (y nada más) lo hace.
    $updater = Api::userWith($tenant, $admin, 'solo_actualizar', ['configuracion_academica.actualizar', 'configuracion_academica.leer']);
    $keep = array_map(fn (array $v): array => ['public_id' => $v['public_id']], $scale['values']);
    $keep[] = Scales::value('EX', 'Excelente', true);

    Scales::patch($tenant, $updater, $scale['public_id'], ['values' => $keep])->assertOk()->assertJsonCount(6, 'values');
});

test('CA-ACAD-093 RN-ACAD-50: estructura_academica no da acceso a las escalas, y configuracion_academica no da acceso a ninguno de los 27 endpoints de la estructura', function (): void {
    [$tenant, $admin, $year, $scale] = scaleAccessFixture('esc-093i');
    $structureOnly = Api::userWith($tenant, $admin, 'solo_estructura', ['estructura_academica.leer', 'estructura_academica.crear', 'estructura_academica.actualizar', 'estructura_academica.eliminar']);
    $configOnly = Api::userWith($tenant, $admin, 'solo_config', ['configuracion_academica.leer', 'configuracion_academica.crear', 'configuracion_academica.actualizar', 'configuracion_academica.eliminar']);

    foreach (scaleEndpoints($scale['public_id'], $year->public_id) as [$method, $path, $body]) {
        resetSessionState();
        test()->actingAs($structureOnly)->json($method, Api::url($tenant, $path), $body)->assertForbidden();
    }

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'acad.') && ! str_starts_with((string) $r->getName(), 'acad.academic-periods.') && ! str_starts_with((string) $r->getName(), 'acad.grading-scales.') && ! str_starts_with((string) $r->getName(), 'acad.facilities.') && ! str_starts_with((string) $r->getName(), 'acad.timetable-frames.'));

    expect($routes)->toHaveCount(27);

    foreach ($routes as $route) {
        $uri = preg_replace('/\{[^}]+\}/', '01JA0000000000000000000099', $route->uri());

        resetSessionState();
        test()->actingAs($configOnly)->json($route->methods()[0], Api::url($tenant, '/'.substr((string) $uri, strlen('api/v1/'))), [])->assertForbidden();
    }

    resetSessionState();
    test()->actingAs($configOnly)->getJson(Api::url($tenant, '/grading-scales'))->assertOk();
    resetSessionState();
    test()->actingAs($structureOnly)->getJson(Api::url($tenant, '/education-levels'))->assertOk();
});

test('CA-ACAD-095 RMOD-009: con acad descontratado, los cinco endpoints responden 403 module-disabled antes de comprobar el permiso', function (): void {
    [$tenant, $admin] = provisionCoreTenant('esc-095');
    $year = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Activo, '2026-09-01', '2027-08-31');
    $nobody = Api::userWith($tenant, $admin, 'nada095', ['usuario.leer']);

    foreach (scaleEndpoints('01JA0000000000000000000002', $year->public_id) as [$method, $path, $body]) {
        foreach ([$admin, $nobody] as $user) {
            resetSessionState();
            $response = test()->actingAs($user)->json($method, Api::url($tenant, $path), $body)->assertForbidden();

            expect($response->json('type'))->toBe('urn:pge:error:module-disabled', "{$method} {$path}");
        }
    }
});

test('CA-ACAD-095 AR-07a: las rutas de escalas llevan autenticación, module-enabled:acad y permission:configuracion_academica.* en ese orden, con el permiso de su acción', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'acad.grading-scales.'));

    expect($routes->map(fn ($r) => $r->getName())->sort()->values()->all())->toBe([
        'acad.grading-scales.destroy', 'acad.grading-scales.index', 'acad.grading-scales.show', 'acad.grading-scales.store', 'acad.grading-scales.update',
    ]);

    $expected = ['index' => 'leer', 'show' => 'leer', 'store' => 'crear', 'update' => 'actualizar', 'destroy' => 'eliminar'];

    foreach ($routes as $route) {
        $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
        $permission = collect($middleware)->first(fn (string $m) => str_starts_with($m, 'permission:configuracion_academica.'));
        $action = substr((string) $route->getName(), strlen('acad.grading-scales.'));

        expect($permission)->toBe('permission:configuracion_academica.'.$expected[$action], $route->getName())
            ->and(array_search(RequireAuthenticatedUser::class, $middleware, true))->toBeLessThan(array_search('module-enabled:acad', $middleware, true))
            ->and(array_search('module-enabled:acad', $middleware, true))->toBeLessThan(array_search($permission, $middleware, true));
    }
});

test('CA-ACAD-094 INV-001 RN-ACAD-50 RN-ACAD-88: con escalas equivalentes en otro centro, ningún endpoint devuelve, modifica, cuenta ni borra datos ajenos: 404 (nunca 403) sobre public_id ajeno, 422 value_not_in_scale con un valor ajeno y 422 academic_year_not_found con un curso ajeno', function (): void {
    [$a, $adminA, $yearA, $scaleA] = scaleAccessFixture('esc-094x');
    [$b, $adminB, $yearB, $scaleB] = scaleAccessFixture('esc-094y');
    $before = Scales::snapshot($b);

    foreach ([
        ['GET', "/grading-scales/{$scaleB['public_id']}", []],
        ['PATCH', "/grading-scales/{$scaleB['public_id']}", ['code' => 'ROBADA']],
        ['DELETE', "/grading-scales/{$scaleB['public_id']}", []],
    ] as [$method, $path, $body]) {
        resetSessionState();
        test()->actingAs($adminA)->json($method, Api::url($a, $path), $body)->assertNotFound();
    }

    // Un valor ajeno en values, y un curso ajeno en el alta: 422.
    $response = Scales::patch($a, $adminA, $scaleA['public_id'], ['values' => [['public_id' => $scaleB['values'][0]['public_id']]]])->assertStatus(422);
    expect(Scales::errorCode($response, 'values.0.public_id'))->toBe('acad.validation.value_not_in_scale');

    $response = Scales::post($a, $adminA, Scales::numeric($yearB->public_id))->assertStatus(422);
    expect(Scales::errorCode($response, 'academic_year'))->toBe('acad.validation.academic_year_not_found');

    // Un curso académico ajeno en el filtro: 404; el listado propio no cuenta nada del otro.
    Scales::get($a, $adminA, "/grading-scales?academic_year={$yearB->public_id}")->assertNotFound();
    $list = Scales::get($a, $adminA, '/grading-scales')->assertOk();
    expect($list->json('meta.total'))->toBe(1)->and($list->json('data.0.public_id'))->toBe($scaleA['public_id']);

    // Nada cambió en el otro centro.
    expect(Scales::snapshot($b))->toBe($before)->and(Scales::scales($b))->toBe(1)->and(Scales::scales($a))->toBe(1);
});

test('CA-ACAD-088 RN-ACAD-52 RN-ACAD-07 ADR-057: en un curso cerrado, crear, modificar (con y sin values) y borrar una escala es 409 academic-year-closed y ninguna fila cambia; en planificación y activo se admiten', function (): void {
    foreach ([AcademicYearStatus::Planificacion, AcademicYearStatus::Activo] as $status) {
        [$tenant, $admin, $year, $scale] = scaleAccessFixture("esc-088-{$status->value}", $status);
        Scales::create($tenant, $admin, Scales::numeric($year->public_id));
        Scales::patch($tenant, $admin, $scale['public_id'], ['code' => 'NUEVO'])->assertOk();
        resetSessionState();
    }

    foreach ([AcademicYearStatus::Cerrado, AcademicYearStatus::Archivado] as $status) {
        [$tenant, $admin, $year, $scale] = scaleAccessFixture("esc-088-{$status->value}");
        CursoTestHelpers::setStatus($tenant, $year, $status);
        $before = Scales::snapshot($tenant);
        $values = array_map(fn (array $v): array => ['public_id' => $v['public_id']], array_reverse($scale['values']));

        $calls = [
            ['POST', '/grading-scales', Scales::numeric($year->public_id)],
            ['PATCH', "/grading-scales/{$scale['public_id']}", ['code' => 'OTRO']],
            ['PATCH', "/grading-scales/{$scale['public_id']}", ['values' => $values]],
            ['PATCH', "/grading-scales/{$scale['public_id']}", ['values' => [...$values, Scales::value('NUEVO', 'Nuevo', true)]]],
            ['DELETE', "/grading-scales/{$scale['public_id']}", []],
        ];

        foreach ($calls as [$method, $path, $body]) {
            resetSessionState();
            $response = test()->actingAs($admin)->json($method, Api::url($tenant, $path), $body);

            // El alta de un curso cerrado la responde 409 solo a quien puede leerlo (el administrador tiene curso_historico.leer).
            expect($response->status())->toBe(409, "{$status->value} {$method} {$path}")
                ->and($response->json('type'))->toBe('urn:pge:error:academic-year-closed')
                ->and($response->json('errors.academic_year.0.code'))->toBe('curso.academic_year_closed');
        }

        expect(Scales::snapshot($tenant))->toBe($before)->and(Scales::scales($tenant))->toBe(1)->and(Scales::values($tenant))->toBe(5);
        resetSessionState();
    }
});

foreach (['cerrado' => AcademicYearStatus::Cerrado, 'archivado' => AcademicYearStatus::Archivado] as $label => $status) {
    test("CA-ACAD-091 RN-ACAD-52 RN-CURSO-33: en un curso {$label}, sin curso_historico.leer el listado y el detalle de escalas responden 404 (mismo cuerpo que un recurso inexistente); con él, los datos con sus valores", function () use ($status): void {
        [$tenant, $admin, $year, $scale] = scaleAccessFixture('esc-091-'.$status->value);
        CursoTestHelpers::setStatus($tenant, $year, $status);

        $reader = Api::userWith($tenant, $admin, 'lector', ['configuracion_academica.leer']);
        $historian = Api::userWith($tenant, $admin, 'historiador', ['configuracion_academica.leer', 'curso_historico.leer']);

        $missing = Scales::get($tenant, $reader, '/grading-scales/01JA0000000000000000000009')->assertNotFound()->json();
        unset($missing['request_id'], $missing['instance']);

        foreach (["/grading-scales?academic_year={$year->public_id}", "/grading-scales/{$scale['public_id']}"] as $path) {
            $denied = Scales::get($tenant, $reader, $path)->assertNotFound()->json();
            unset($denied['request_id'], $denied['instance']);

            expect($denied)->toBe($missing, $path);

            Scales::get($tenant, $historian, $path)->assertOk();
        }

        expect(Scales::get($tenant, $historian, "/grading-scales/{$scale['public_id']}")->json('values'))->toHaveCount(5)
            ->and(Scales::get($tenant, $historian, "/grading-scales?academic_year={$year->public_id}")->json('data.0.values'))->toHaveCount(5);
    });
}

test('CA-ACAD-092 RN-ACAD-52 RN-ACAD-51: con el curso en planificación o activo, las escalas se leen sin curso_historico.leer; sin academic_year y sin curso activo, 404 curso.no_active_year; academic_year ajeno o inexistente, 404', function (): void {
    [$tenant, $admin, $year, $scale] = scaleAccessFixture('esc-092');
    $reader = Api::userWith($tenant, $admin, 'lector092', ['configuracion_academica.leer']);

    foreach ([AcademicYearStatus::Activo, AcademicYearStatus::Planificacion] as $status) {
        CursoTestHelpers::setStatus($tenant, $year, $status);

        foreach (["/grading-scales?academic_year={$year->public_id}", "/grading-scales/{$scale['public_id']}"] as $path) {
            Scales::get($tenant, $reader, $path)->assertOk();
        }
    }

    // Sin curso activo (el único está en planificación) y sin academic_year.
    $response = Scales::get($tenant, $reader, '/grading-scales')->assertNotFound();
    expect($response->json('errors.academic_year.0.code'))->toBe('curso.no_active_year');

    foreach (['01JA0000000000000000000099', 'no-es-un-ulid'] as $unknown) {
        Scales::get($tenant, $reader, "/grading-scales?academic_year={$unknown}")->assertNotFound();
    }

    app(TenantContext::class)->runFor($tenant->id, fn () => expect(DB::table('grading_scales')->count())->toBe(1));
});

test('CA-ACAD-106 RN-ACAD-52 desviación S-3: el alta en un curso cerrado sin curso_historico.leer es 422 academic_year_not_found con el mismo cuerpo que un curso inexistente; con él, 409 academic-year-closed; no se crea nada', function (): void {
    [$tenant, $admin, $year] = scaleAccessFixture('esc-106');
    CursoTestHelpers::setStatus($tenant, $year, AcademicYearStatus::Cerrado);

    $writer = Api::userWith($tenant, $admin, 'solo_crear', ['configuracion_academica.crear']);
    $historian = Api::userWith($tenant, $admin, 'crear_historico', ['configuracion_academica.crear', 'curso_historico.leer']);
    $before = Scales::snapshot($tenant);

    $closed = Scales::post($tenant, $writer, Scales::numeric($year->public_id))->assertStatus(422)->json();
    $missing = Scales::post($tenant, $writer, Scales::numeric($year->public_id, ['academic_year' => '01JA0000000000000000000099']))->assertStatus(422)->json();
    unset($closed['request_id'], $closed['instance'], $missing['request_id'], $missing['instance']);

    expect($closed['errors']['academic_year'][0]['code'])->toBe('acad.validation.academic_year_not_found')
        ->and($closed)->toBe($missing);

    $response = Scales::post($tenant, $historian, Scales::numeric($year->public_id))->assertStatus(409);

    expect($response->json('type'))->toBe('urn:pge:error:academic-year-closed')
        ->and(Scales::snapshot($tenant))->toBe($before);
});
