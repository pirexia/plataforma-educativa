<?php

use App\Http\Middleware\RequireAuthenticatedUser;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001 (1.11-b), docs/modulos/REQ-ACAD/api.md §2: los 27 endpoints en
// tabla. CA-ACAD-032 (401/403 por permiso), CA-ACAD-034 (módulo no
// contratado), CA-ACAD-033 (aislamiento de tenant, 404 nunca 403),
// CA-ACAD-020 (curso cerrado: 409 en toda escritura) y CA-ACAD-024 a -031
// (lectura de curso cerrado sin `curso_historico.leer`: 404; por recurso:
// CA-ACAD-025 cursos de etapa, -026 grupos, -027 subgrupos, -028 asignaturas). INV-001,
// INV-002, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Los 27 endpoints de api.md §2 con un cuerpo válido, sobre los `public_id`
 * del árbol dado.
 *
 * @param  array{level: string, course: string, group: string, subgroup: string, subject: string}  $t
 * @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: string}> verbo, ruta, cuerpo, permiso
 */
function acadEndpoints(array $t, string $yearPublicId): array
{
    return [
        ['GET', '/education-levels?academic_year='.$yearPublicId, [], 'leer'],
        ['POST', '/education-levels', ['academic_year' => $yearPublicId, 'code' => 'NUEVA', 'name_translations' => ['es-ES' => 'Nueva'], 'stage' => 'eso', 'regime' => 'publico'], 'crear'],
        ['GET', "/education-levels/{$t['level']}", [], 'leer'],
        ['PATCH', "/education-levels/{$t['level']}", ['code' => 'CAMBIADA'], 'actualizar'],
        ['DELETE', "/education-levels/{$t['level']}", [], 'eliminar'],
        ['GET', '/courses?academic_year='.$yearPublicId, [], 'leer'],
        ['POST', '/courses', ['education_level' => $t['level'], 'code' => 'NUEVO', 'name_translations' => ['es-ES' => 'Nuevo']], 'crear'],
        ['GET', "/courses/{$t['course']}", [], 'leer'],
        ['PATCH', "/courses/{$t['course']}", ['code' => 'CAMBIADO'], 'actualizar'],
        ['DELETE', "/courses/{$t['course']}", [], 'eliminar'],
        ['GET', '/groups?academic_year='.$yearPublicId, [], 'leer'],
        ['POST', '/groups', ['course' => $t['course'], 'code' => 'NUEVO', 'name' => 'Nuevo'], 'crear'],
        ['GET', "/groups/{$t['group']}", [], 'leer'],
        ['PATCH', "/groups/{$t['group']}", ['code' => 'CAMBIADO'], 'actualizar'],
        ['DELETE', "/groups/{$t['group']}", [], 'eliminar'],
        ['GET', "/groups/{$t['group']}/subgroups", [], 'leer'],
        ['POST', "/groups/{$t['group']}/subgroups", ['code' => 'NUEVO', 'name' => 'Nuevo'], 'crear'],
        ['GET', "/subgroups/{$t['subgroup']}", [], 'leer'],
        ['PATCH', "/subgroups/{$t['subgroup']}", ['code' => 'CAMBIADO'], 'actualizar'],
        ['DELETE', "/subgroups/{$t['subgroup']}", [], 'eliminar'],
        ['GET', '/subjects?academic_year='.$yearPublicId, [], 'leer'],
        ['POST', '/subjects', ['course' => $t['course'], 'code' => 'NUEVA', 'name_translations' => ['es-ES' => 'Nueva']], 'crear'],
        ['GET', "/subjects/{$t['subject']}", [], 'leer'],
        ['PATCH', "/subjects/{$t['subject']}", ['code' => 'CAMBIADA'], 'actualizar'],
        ['DELETE', "/subjects/{$t['subject']}", [], 'eliminar'],
        ['GET', "/groups/{$t['group']}/subjects", [], 'leer'],
        ['PUT', "/groups/{$t['group']}/subjects", ['subjects' => [$t['subject']]], 'actualizar'],
    ];
}

test('api.md §2 y §11.3: son exactamente 32 endpoints (27 de la estructura con estructura_academica y 5 de períodos con configuracion_academica) con autenticación, module-enabled:acad y permission: en ese orden', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'acad.'));
    $periods = $routes->filter(fn ($r) => str_starts_with((string) $r->getName(), 'acad.academic-periods.'));

    expect($routes)->toHaveCount(32)->and($periods)->toHaveCount(5);

    foreach ($routes as $route) {
        $resource = $periods->contains($route) ? 'configuracion_academica' : 'estructura_academica';
        $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
        $permission = collect($middleware)->first(fn (string $m) => str_starts_with($m, "permission:{$resource}."));

        expect($permission)->not->toBeNull($route->getName());
        expect(array_search(RequireAuthenticatedUser::class, $middleware, true))->toBeLessThan(array_search('module-enabled:acad', $middleware, true))
            ->and(array_search('module-enabled:acad', $middleware, true))->toBeLessThan(array_search($permission, $middleware, true));
    }
});

test('CA-ACAD-032 INV-002: todo endpoint responde 401 sin sesión y 403 sin su permiso (leer, crear, actualizar incluido PUT subjects, eliminar)', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-032');
    $tree = Api::tree($tenant, $admin, $year);
    $all = ['leer', 'crear', 'actualizar', 'eliminar'];

    $users = [];
    foreach ($all as $lacking) {
        $users[$lacking] = Api::userWith($tenant, $admin, "sin_{$lacking}", array_map(
            fn (string $a): string => "estructura_academica.{$a}",
            array_values(array_diff($all, [$lacking])),
        ));
    }
    $nobody = Api::userWith($tenant, $admin, 'nada', ['usuario.leer']);

    foreach (acadEndpoints($tree, $year->public_id) as [$method, $path, $body, $needs]) {
        $label = "{$method} {$path}";
        $url = Api::url($tenant, $path);

        resetSessionState();
        test()->json($method, $url, $body)->assertUnauthorized();

        resetSessionState();
        test()->actingAs($users[$needs])->json($method, $url, $body)->assertForbidden();

        resetSessionState();
        test()->actingAs($nobody)->json($method, $url, $body)->assertForbidden();
    }
});

test('CA-ACAD-034 RMOD-009: con acad descontratado, todo endpoint responde 403 module-disabled antes de comprobar el permiso', function (): void {
    [$tenant, $admin] = provisionCoreTenant('acad-034');
    $year = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Activo);
    $ids = ['level' => '01JA0000000000000000000001', 'course' => '01JA0000000000000000000002', 'group' => '01JA0000000000000000000003', 'subgroup' => '01JA0000000000000000000004', 'subject' => '01JA0000000000000000000005'];
    $nobody = Api::userWith($tenant, $admin, 'nada034', ['usuario.leer']);

    foreach (acadEndpoints($ids, $year->public_id) as [$method, $path, $body]) {
        foreach ([$admin, $nobody] as $user) {
            resetSessionState();
            $response = test()->actingAs($user)->json($method, Api::url($tenant, $path), $body)->assertForbidden();

            expect($response->json('type'))->toBe('urn:pge:error:module-disabled', "{$method} {$path}");
        }
    }
});

test('CA-ACAD-033 INV-001: con un árbol equivalente en otro centro, ningún endpoint devuelve, modifica ni borra datos ajenos: 404 (nunca 403) sobre public_id ajenos', function (): void {
    [$a, $adminA, $yearA] = Api::setup('acad-033x');
    [$b, $adminB, $yearB] = Api::setup('acad-033y');
    $treeA = Api::tree($a, $adminA, $yearA);
    $treeB = Api::tree($b, $adminB, $yearB);

    foreach (acadEndpoints($treeB, $yearB->public_id) as [$method, $path, $body]) {
        // Los POST con padre en el cuerpo responden 422 parent_not_found (api.md §6); los listados con el curso ajeno, 404.
        resetSessionState();
        $response = test()->actingAs($adminA)->json($method, Api::url($a, $path), $body);
        $label = "{$method} {$path}";

        if ($method === 'POST' && ! str_contains($path, '/subgroups')) {
            if ($path === '/education-levels') {
                expect($response->status())->toBe(422, $label)->and($response->json('errors.academic_year.0.code'))->toBe('acad.validation.academic_year_not_found');
            } else {
                expect($response->status())->toBe(422, $label)->and($response->json('errors.*.0.code'))->toContain('acad.validation.parent_not_found');
            }

            continue;
        }

        expect($response->status())->toBe(404, $label);
    }

    // Nada cambió en el otro centro.
    expect(Api::row($b, 'education_levels', $treeB['level'])->code)->toBe('ESO')
        ->and(Api::row($b, 'education_levels', $treeB['level'])->deleted_at)->toBeNull()
        ->and(Api::row($b, 'subjects', $treeB['subject'])->code)->toBe('MAT');

    // Y los listados propios no cuentan nada del otro.
    resetSessionState();
    foreach (['/education-levels', '/courses', '/groups', '/subjects'] as $list) {
        expect(test()->actingAs($adminA)->getJson(Api::url($a, $list))->assertOk()->json('meta.total'))->toBe(1, $list);
        resetSessionState();
    }

    expect($treeA['level'])->not->toBe($treeB['level']);
});

test('CA-ACAD-020 RN-ACAD-07 ADR-057: en un curso cerrado, toda escritura sobre las seis entidades es 409 academic-year-closed y la fila no cambia', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-020');
    $tree = Api::tree($tenant, $admin, $year);
    resetSessionState();
    test()->actingAs($admin)->putJson(Api::url($tenant, "/groups/{$tree['group']}/subjects"), ['subjects' => [$tree['subject']]])->assertOk();
    CursoTestHelpers::setStatus($tenant, $year, AcademicYearStatus::Cerrado);

    $before = [
        'education_levels' => Api::row($tenant, 'education_levels', $tree['level']),
        'courses' => Api::row($tenant, 'courses', $tree['course']),
        'groups' => Api::row($tenant, 'groups', $tree['group']),
        'subgroups' => Api::row($tenant, 'subgroups', $tree['subgroup']),
        'subjects' => Api::row($tenant, 'subjects', $tree['subject']),
    ];

    $writes = collect(acadEndpoints($tree, $year->public_id))
        ->filter(fn (array $e): bool => in_array($e[0], ['POST', 'PATCH', 'DELETE'], true))
        // La asignación necesita un cambio real para escribir: se vacía el conjunto.
        ->push(['PUT', "/groups/{$tree['group']}/subjects", ['subjects' => []], 'actualizar'])
        // Un DELETE de una entidad con hijos vivos es 409 has_dependents antes que nada; se prueban las hojas.
        ->reject(fn (array $e): bool => $e[0] === 'DELETE' && ! str_contains($e[1], '/subgroups/'));

    foreach ($writes as [$method, $path, $body]) {
        resetSessionState();
        $response = test()->actingAs($admin)->json($method, Api::url($tenant, $path), $body)->assertStatus(409);

        expect($response->json('type'))->toBe('urn:pge:error:academic-year-closed', "{$method} {$path}")
            ->and($response->json('errors.academic_year.0.code'))->toBe('curso.academic_year_closed', "{$method} {$path}");
    }

    foreach ($before as $table => $row) {
        $publicId = $row->public_id;
        expect((array) Api::row($tenant, $table, $publicId))->toEqual((array) $row, $table);
    }

    // Una asignación sigue viva.
    expect(app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('group_subjects')->whereNull('deleted_at')->count()))->toBe(1);

    // PATCH sin cambios en un curso cerrado: 200 sin escritura [DERIVADA].
    resetSessionState();
    test()->actingAs($admin)->patchJson(Api::url($tenant, "/subjects/{$tree['subject']}"), [])->assertOk();
    // PUT con el mismo conjunto: 200 sin escritura.
    resetSessionState();
    test()->actingAs($admin)->putJson(Api::url($tenant, "/groups/{$tree['group']}/subjects"), ['subjects' => [$tree['subject']]])->assertOk();
});

foreach (['cerrado' => AcademicYearStatus::Cerrado, 'archivado' => AcademicYearStatus::Archivado] as $label => $status) {
    test("CA-ACAD-024 a CA-ACAD-029 RN-ACAD-08 RN-CURSO-33: en un curso {$label}, sin curso_historico.leer cada listado y detalle responde 404 (mismo cuerpo que un recurso inexistente); con él, los datos", function () use ($status): void {
        [$tenant, $admin, $year] = Api::setup('acad-024-'.$status->value);
        $tree = Api::tree($tenant, $admin, $year);
        resetSessionState();
        test()->actingAs($admin)->putJson(Api::url($tenant, "/groups/{$tree['group']}/subjects"), ['subjects' => [$tree['subject']]])->assertOk();
        CursoTestHelpers::setStatus($tenant, $year, $status);

        $reader = Api::userWith($tenant, $admin, 'lector', ['estructura_academica.leer']);
        $historian = Api::userWith($tenant, $admin, 'historiador', ['estructura_academica.leer', 'curso_historico.leer']);

        $reads = collect(acadEndpoints($tree, $year->public_id))->filter(fn (array $e): bool => $e[0] === 'GET');

        expect($reads)->toHaveCount(11);

        $missing = test()->actingAs($reader)->getJson(Api::url($tenant, '/education-levels/01JA0000000000000000000009'))->assertNotFound()->json();
        unset($missing['request_id'], $missing['instance']);

        foreach ($reads as [$method, $path]) {
            resetSessionState();
            $denied = test()->actingAs($reader)->getJson(Api::url($tenant, $path))->assertNotFound()->json();
            unset($denied['request_id'], $denied['instance']);

            expect($denied)->toBe($missing, $path);

            resetSessionState();
            test()->actingAs($historian)->getJson(Api::url($tenant, $path))->assertOk();
        }

        // Filtros por padre del curso cerrado: también 404 sin permiso.
        resetSessionState();
        test()->actingAs($reader)->getJson(Api::url($tenant, "/courses?academic_year={$year->public_id}&education_level={$tree['level']}"))->assertNotFound();
        test()->actingAs($reader)->getJson(Api::url($tenant, "/groups?academic_year={$year->public_id}&course={$tree['course']}"))->assertNotFound();
    });
}

test('CA-ACAD-030 CA-ACAD-031: con el curso en planificacion o activo, las lecturas responden 200 sin curso_historico.leer; sin academic_year se usa el activo', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-030');
    $tree = Api::tree($tenant, $admin, $year);
    $reader = Api::userWith($tenant, $admin, 'lector030', ['estructura_academica.leer']);

    foreach (acadEndpoints($tree, $year->public_id) as [$method, $path]) {
        if ($method === 'GET') {
            resetSessionState();
            test()->actingAs($reader)->getJson(Api::url($tenant, $path))->assertOk();
        }
    }

    // Sin academic_year: el activo.
    resetSessionState();
    expect(test()->actingAs($reader)->getJson(Api::url($tenant, '/subjects'))->assertOk()->json('meta.total'))->toBe(1);

    // Un curso en planificación se lee igual.
    CursoTestHelpers::setStatus($tenant, $year, AcademicYearStatus::Planificacion);
    resetSessionState();
    test()->actingAs($reader)->getJson(Api::url($tenant, "/subjects?academic_year={$year->public_id}"))->assertOk();
});

test('INV-002 INV-001 REQ-ACAD-001 (hallazgo del E2E real): en la pila ORDENADA por el router, el tenant se resuelve y la sesión arranca ANTES de comprobar la autenticación', function (): void {
    // `actingAs()` de los tests se salta este orden: el alias `auth` de Laravel se colocaba antes
    // de `resolve-tenant` y daba 500 (`TenantContextMissing`) en una petición real con cookie.
    $router = app('router');

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with((string) $route->getName(), 'acad.')) {
            continue;
        }

        $stack = array_values(array_filter($router->gatherRouteMiddleware($route), 'is_string'));
        $position = fn (string $class): int|false => array_search($class, $stack, true);

        expect($position('resolve-tenant'))->not->toBeFalse($route->getName())
            ->and($position('resolve-tenant'))->toBeLessThan($position(RequireAuthenticatedUser::class), $route->getName())
            ->and($position('start-session'))->toBeLessThan($position(RequireAuthenticatedUser::class), $route->getName())
            ->and($position(RequireAuthenticatedUser::class))->toBeLessThan($position('module-enabled:acad'), $route->getName());
    }
});
