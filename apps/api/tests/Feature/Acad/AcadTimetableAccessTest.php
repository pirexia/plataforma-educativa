<?php

use App\Models\PermissionRole;
use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadTimetableHelpers as Tt;

// REQ-ACAD-002 (1.12-a), docs/modulos/REQ-ACAD/permisos.md §10, funcional.md
// §18.9: acceso a los endpoints de espacios y marcos horarios y siembra de
// permisos. CA-ACAD-131 (401/403/module-disabled), CA-ACAD-133 (siembra y
// ámbitos), INV-002, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Los diez endpoints de espacios y marcos con un cuerpo válido.
 *
 * @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: string, 4: string}> verbo, ruta, cuerpo, recurso, acción
 */
function ttEndpoints(string $facility, string $frame, string $level): array
{
    return [
        ['GET', '/facilities', [], 'espacios', 'leer'],
        ['POST', '/facilities', ['facility_type' => 'aula', 'code' => 'NUEVA', 'name' => 'Nueva'], 'espacios', 'crear'],
        ['GET', "/facilities/{$facility}", [], 'espacios', 'leer'],
        ['PATCH', "/facilities/{$facility}", ['name' => 'Cambiada'], 'espacios', 'actualizar'],
        ['DELETE', "/facilities/{$facility}", [], 'espacios', 'eliminar'],
        ['GET', '/timetable-frames', [], 'horarios', 'leer'],
        ['POST', '/timetable-frames', Tt::frameBody($level), 'horarios', 'crear'],
        ['GET', "/timetable-frames/{$frame}", [], 'horarios', 'leer'],
        ['PATCH', "/timetable-frames/{$frame}", ['slots' => []], 'horarios', 'actualizar'],
        ['DELETE', "/timetable-frames/{$frame}", [], 'horarios', 'eliminar'],
    ];
}

test('CA-ACAD-131 INV-002: cada endpoint de espacios y marcos responde 401 sin sesión y 403 sin su permiso, y un permiso del recurso hermano o de estructura no basta', function (): void {
    [$tenant, $admin, $year] = Api::setup('tt-131');
    $level = Api::level($tenant, $admin, $year);
    $facility = Tt::postFacility($tenant, $admin)->json('public_id');
    $frame = Tt::postFrame($tenant, $admin, Tt::frameBody($level, ['slots' => []]))->json('public_id');
    $all = ['leer', 'crear', 'actualizar', 'eliminar'];

    // Un usuario por (recurso, acción que le falta): tiene las otras tres de ese recurso y las cuatro de los demás.
    $users = [];
    foreach (['espacios', 'horarios'] as $resource) {
        foreach ($all as $lacking) {
            $codes = array_map(fn (string $a): string => "{$resource}.{$a}", array_values(array_diff($all, [$lacking])));
            $users["{$resource}.{$lacking}"] = Api::userWith($tenant, $admin, "sin_{$resource}_{$lacking}", $codes);
        }
    }
    $others = Api::userWith($tenant, $admin, 'otros131', ['estructura_academica.leer', 'estructura_academica.crear', 'estructura_academica.actualizar', 'estructura_academica.eliminar', 'configuracion_academica.leer', 'asignacion_docente.leer', 'espacios.leer', 'horarios.leer']);

    foreach (ttEndpoints($facility, $frame, $level) as [$method, $path, $body, $resource, $action]) {
        $url = Api::url($tenant, $path);

        resetSessionState();
        test()->json($method, $url, $body)->assertUnauthorized();

        resetSessionState();
        test()->actingAs($users["{$resource}.{$action}"])->json($method, $url, $body)->assertForbidden();

        // `espacios.leer` + `horarios.leer` + estructura completa no dan escritura de ninguno de los dos recursos.
        if ($action !== 'leer') {
            resetSessionState();
            test()->actingAs($others)->json($method, $url, $body)->assertForbidden();
        }
    }
});

test('CA-ACAD-131 RMOD-009: con acad descontratado, los endpoints de espacios y marcos responden 403 module-disabled antes de comprobar el permiso', function (): void {
    [$tenant, $admin] = provisionCoreTenant('tt-131b');
    $nobody = Api::userWith($tenant, $admin, 'nada131', ['usuario.leer']);
    $id = '01JA0000000000000000000001';

    foreach (ttEndpoints($id, $id, $id) as [$method, $path, $body]) {
        foreach ([$admin, $nobody] as $user) {
            resetSessionState();
            $response = test()->actingAs($user)->json($method, Api::url($tenant, $path), $body)->assertForbidden();

            expect($response->json('type'))->toBe('urn:pge:error:module-disabled', "{$method} {$path}");
        }
    }
});

/** @return list<string> los permisos de los tres recursos de 1.12-a que tiene un rol predefinido, ordenados */
function ttGrantsOf(string $roleCode): array
{
    $role = Role::where('code', $roleCode)->firstOrFail();

    return PermissionRole::where('role_id', $role->id)
        ->where(fn ($q) => $q->where('permission_code', 'like', 'asignacion_docente.%')->orWhere('permission_code', 'like', 'espacios.%')->orWhere('permission_code', 'like', 'horarios.%'))
        ->pluck('permission_code')->sort()->values()->all();
}

/** @return list<string> */
function ttCodes(string $resource, array $actions): array
{
    $codes = array_map(fn (string $a): string => "{$resource}.{$a}", $actions);
    sort($codes);

    return $codes;
}

test('CA-ACAD-133 permisos.md §10.3 OPEN-ACAD-42 = X: tenant:provision-defaults siembra los permisos de horarios, espacios y asignación docente exactamente como la tabla (direccion escribe horarios y asignación; el resto lee), todos con ámbito todos', function (): void {
    [$tenant] = provisionCoreTenant('tt-133');
    $four = ['actualizar', 'crear', 'eliminar', 'leer'];
    $merge = function (array ...$lists): array {
        $merged = array_merge(...$lists);
        sort($merged);

        return $merged;
    };

    app(TenantContext::class)->runFor($tenant->id, function () use ($four, $merge): void {
        $expected = [
            'administrador_centro' => $merge(ttCodes('asignacion_docente', $four), ttCodes('espacios', $four), ttCodes('horarios', $four)),
            'direccion' => $merge(ttCodes('asignacion_docente', $four), ttCodes('espacios', ['leer']), ttCodes('horarios', $four)),
        ];

        foreach (['secretaria', 'docente', 'tutor_grupo', 'orientador'] as $read) {
            $expected[$read] = $merge(ttCodes('asignacion_docente', ['leer']), ttCodes('espacios', ['leer']), ttCodes('horarios', ['leer']));
        }

        foreach ($expected as $role => $codes) {
            expect(ttGrantsOf($role))->toBe($codes, $role);
        }

        // Los demás roles predefinidos, nada (RPERM-011).
        $others = Role::where('is_system', true)->whereNotIn('code', array_keys($expected))->pluck('code');

        expect($others)->not->toBeEmpty();

        foreach ($others as $code) {
            expect(ttGrantsOf($code))->toBe([], $code);
        }

        expect(PermissionRole::where(fn ($q) => $q->where('permission_code', 'like', 'asignacion_docente.%')->orWhere('permission_code', 'like', 'espacios.%')->orWhere('permission_code', 'like', 'horarios.%'))->where('scope', '<>', 'todos')->count())->toBe(0);
    });
});

test('CA-ACAD-133 operacion.md §12.2: acad:grant-structure-permissions concede los permisos nuevos a un centro existente (direccion incluida), es idempotente y no toca los de otros recursos', function (): void {
    [$tenant] = provisionCoreTenant('tt-133b');

    // Centro anterior al despliegue: se retiran los permisos de 1.12-a.
    app(TenantContext::class)->runFor($tenant->id, function (): void {
        PermissionRole::query()->where(fn ($q) => $q->where('permission_code', 'like', 'asignacion_docente.%')->orWhere('permission_code', 'like', 'espacios.%')->orWhere('permission_code', 'like', 'horarios.%'))->get()->each->delete();
    });

    test()->artisan('acad:grant-structure-permissions')->assertSuccessful();
    $first = app(TenantContext::class)->runFor($tenant->id, fn () => [ttGrantsOf('direccion'), ttGrantsOf('docente'), PermissionRole::count()]);
    test()->artisan('acad:grant-structure-permissions')->assertSuccessful();
    $second = app(TenantContext::class)->runFor($tenant->id, fn () => [ttGrantsOf('direccion'), ttGrantsOf('docente'), PermissionRole::count()]);

    expect($first)->toBe($second)
        ->and($first[0])->toContain('horarios.crear', 'horarios.eliminar', 'asignacion_docente.actualizar', 'espacios.leer')->not->toContain('espacios.crear')
        ->and($first[1])->toBe(['asignacion_docente.leer', 'espacios.leer', 'horarios.leer']);
});

test('CA-ACAD-133 CA-ACAD-037 ADR-063 §5.1: conceder cualquier permiso nuevo de 1.12-a con ámbito grupo o clase responde 422 y no guarda el rol', function (): void {
    [$tenant, $admin] = provisionCoreTenant('tt-133c');
    contractModule($tenant, 'acad');

    foreach (['asignacion_docente.leer', 'espacios.leer', 'horarios.leer', 'horarios.crear'] as $i => $code) {
        foreach (['grupo', 'clase', 'propios'] as $j => $scope) {
            resetSessionState();

            test()->actingAs($admin)
                ->postJson(coreApiUrl($tenant->slug, '/roles'), [
                    'code' => "rol_tt_{$i}_{$j}",
                    'name' => "Rol {$i}{$j}",
                    'permissions' => [['code' => $code, 'effect' => 'allow', 'scope' => $scope]],
                ])
                ->assertStatus(422);
        }
    }

    app(TenantContext::class)->runFor($tenant->id, fn () => expect(Role::where('code', 'like', 'rol_tt_%')->count())->toBe(0));
});
