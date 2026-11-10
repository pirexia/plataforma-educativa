<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AlumTestHelpers as Alum;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-ALUM-001 (1.15-a T2), docs/modulos/REQ-ALUM/permisos.md §3.1, funcional.md RN-ALUM-62 a -64, ADR-044 §4.2: el ámbito
// `unidad_familiar` sobre `alumno` se resuelve por vínculos activos, en listado Y en detalle. CA-ALUM-012, INV-001,
// INV-002, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Una unidad con dos alumnos (A1 y A2) y un tutor T con ámbito `unidad_familiar` vinculado solo a A1.
 *
 * @return array<string, mixed>
 */
function alumFamily(string $slug): array
{
    [$tenant, $admin] = Alum::setup($slug);
    $unit = Fam::unitId($tenant, $admin, 'U1');
    $a1 = Alum::student($tenant, $admin, ['given_name' => 'Alumno', 'family_name_1' => 'Uno', 'family_unit' => $unit]);
    $a2 = Alum::student($tenant, $admin, ['given_name' => 'Alumno', 'family_name_1' => 'Dos', 'family_unit' => $unit]);

    $tutor = Alum::userWithScopes($tenant, $admin, 'tutor', [
        'alumno.leer' => 'unidad_familiar',
        'familia.leer' => 'unidad_familiar',
        'vinculo_tutor.leer' => 'unidad_familiar',
    ]);
    $guardian = Fam::guardian($tenant, $admin, $tutor, $unit)->assertCreated()->json('public_id');
    $link = Fam::link($tenant, $admin, $guardian, Alum::person($tenant, $a1['person']['public_id']))->assertCreated()->json('public_id');

    return compact('tenant', 'admin', 'unit', 'a1', 'a2', 'tutor', 'guardian', 'link');
}

test('CA-ALUM-012 RN-ALUM-62 RN-ALUM-63 RN-ALUM-64: con unidad_familiar el tutor lista solo al alumno con vínculo activo y el otro de su unidad es 404 idéntico a un inexistente; tras revocar el vínculo, en la petición siguiente tampoco ve al primero', function (): void {
    $c = alumFamily('alum-012');
    $t = $c['tutor'];

    $list = Alum::call($c['tenant'], $t, 'get', '/students')->assertOk();

    expect(array_column($list->json('data'), 'public_id'))->toBe([$c['a1']['public_id']])
        ->and($list->json('meta.total'))->toBe(1);

    Alum::call($c['tenant'], $t, 'get', "/students/{$c['a1']['public_id']}")->assertOk()->assertJsonPath('given_name', 'Alumno');

    $other = Alum::call($c['tenant'], $t, 'get', "/students/{$c['a2']['public_id']}")->assertNotFound();
    $missing = Alum::call($c['tenant'], $t, 'get', '/students/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();

    expect(Arr::except($other->json(), ['instance', 'request_id']))->toBe(Arr::except($missing->json(), ['instance', 'request_id']));

    // `q` busca solo dentro del ámbito (nunca revela a quien no alcanza).
    expect(Alum::call($c['tenant'], $t, 'get', '/students?q=Alumno')->json('meta.total'))->toBe(1)
        ->and(array_column(Alum::call($c['tenant'], $t, 'get', '/students?q=Alumno&sort=-created_at')->json('data'), 'public_id'))->toBe([$c['a1']['public_id']]);

    // El personal con `todos` ve a los dos.
    expect(Alum::call($c['tenant'], $c['admin'], 'get', '/students')->json('meta.total'))->toBe(2);

    // Revocación del vínculo: sin caché entre peticiones.
    Fam::call($c['tenant'], $c['admin'], 'post', "/guardian-links/{$c['link']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();

    Alum::call($c['tenant'], $t, 'get', "/students/{$c['a1']['public_id']}")->assertNotFound();
    expect(Alum::call($c['tenant'], $t, 'get', '/students')->json('data'))->toBe([])
        ->and(Alum::call($c['tenant'], $t, 'get', '/students')->json('meta.total'))->toBe(0);
});

test('RN-ALUM-62 INV-002: un sujeto con unidad_familiar sin faceta de tutor, sin vínculos o un tutor con vínculo revocado ve una lista vacía y 404 en cada ficha; no escribe', function (): void {
    $c = alumFamily('alum-062');

    $noGuardian = Alum::userWithScopes($c['tenant'], $c['admin'], 'sin_tutor', ['alumno.leer' => 'unidad_familiar']);

    Alum::call($c['tenant'], $noGuardian, 'get', '/students')->assertOk()->assertJsonPath('meta.total', 0);
    Alum::call($c['tenant'], $noGuardian, 'get', "/students/{$c['a1']['public_id']}")->assertNotFound();

    // El tutor del escenario solo lee: las escrituras son 403 aunque la ficha se alcance.
    Alum::call($c['tenant'], $c['tutor'], 'patch', "/students/{$c['a1']['public_id']}", ['given_name' => 'X'])->assertForbidden();
    Alum::call($c['tenant'], $c['tutor'], 'delete', "/students/{$c['a1']['public_id']}")->assertForbidden();
    Alum::call($c['tenant'], $c['tutor'], 'post', '/students', Alum::body(), ['Idempotency-Key' => (string) Str::ulid()])->assertForbidden();
});

test('RN-ALUM-61 PermissionResolver: alumno.leer admite el ámbito grupo desde 1.15-b (ampliación compatible) y un ámbito que el permiso no admite sigue rechazándose al crear el rol', function (): void {
    [$tenant, $admin] = Alum::setup('alum-062b');

    resetSessionState();
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'rol_grupo', 'name' => 'Rol grupo',
        'permissions' => [['code' => 'alumno.leer', 'effect' => 'allow', 'scope' => 'grupo']],
    ])->assertCreated();

    // `alumno.crear` solo admite `todos`: con `grupo` se rechaza.
    resetSessionState();
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'rol_crear_grupo', 'name' => 'Rol crear grupo',
        'permissions' => [['code' => 'alumno.crear', 'effect' => 'allow', 'scope' => 'grupo']],
    ])->assertUnprocessable();
});
