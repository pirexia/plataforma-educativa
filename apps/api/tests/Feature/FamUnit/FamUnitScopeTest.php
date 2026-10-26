<?php

use App\Models\Role;
use App\Support\Authorization\Scope;
use App\Support\Authorization\ScopeResolverRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-002 (1.14-a), docs/modulos/REQ-FAM-UNIT/permisos.md §3.1, funcional.md §3.6, ADR-044 §4.2: el ámbito
// `unidad_familiar` se resuelve por vínculos activos, en listado Y en detalle. CA-FAM-UNIT-040, -041, -042, -044 y -046,
// INV-001, INV-002, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Dos familias en el mismo centro. F1: tutor T1 (con ámbito `unidad_familiar`) vinculado a S1, otro tutor T1b también
 * vinculado a S1, un alumno S1x de F1 sin vínculo con T1 y un tutor T1c sin vínculos. F2: tutora T2 vinculada a S2.
 *
 * @return array<string, mixed>
 */
function twoFamilies(string $slug): array
{
    [$tenant, $admin] = Fam::setup($slug);
    $f1 = Fam::unitId($tenant, $admin, 'F1');
    $f2 = Fam::unitId($tenant, $admin, 'F2');

    [$t1, $g1] = Fam::familyUser($tenant, $admin, 't1', $f1);
    [, $g1b] = Fam::familyUser($tenant, $admin, 't1b', $f1);
    [, $g1c] = Fam::familyUser($tenant, $admin, 't1c', $f1);
    [$t2, $g2] = Fam::familyUser($tenant, $admin, 't2', $f2);

    $s1 = Fam::person($tenant, 'Alumno', 'Uno');
    $s1x = Fam::person($tenant, 'Alumno', 'UnoX');
    $s2 = Fam::person($tenant, 'Alumno', 'Dos');
    Fam::addStudent($tenant, $admin, $f1, $s1)->assertCreated();
    Fam::addStudent($tenant, $admin, $f1, $s1x)->assertCreated();
    Fam::addStudent($tenant, $admin, $f2, $s2)->assertCreated();

    $l1 = Fam::link($tenant, $admin, $g1, $s1)->assertCreated()->json('public_id');
    $l1b = Fam::link($tenant, $admin, $g1b, $s1)->assertCreated()->json('public_id');
    $l2 = Fam::link($tenant, $admin, $g2, $s2)->assertCreated()->json('public_id');

    return compact('tenant', 'admin', 'f1', 'f2', 't1', 't2', 'g1', 'g1b', 'g1c', 'g2', 's1', 's1x', 's2', 'l1', 'l1b', 'l2');
}

test('CA-FAM-UNIT-040 ADR-044 §4.2: con ámbito unidad_familiar el tutor lista solo su unidad y pedir por public_id la de otra familia es 404 igual que un id inexistente (listado y detalle)', function (): void {
    $c = twoFamilies('sc-040');

    $list = Fam::call($c['tenant'], $c['t1'], 'get', '/family-units')->assertOk();

    expect(array_column($list->json('data'), 'public_id'))->toBe([$c['f1']])
        ->and($list->json('meta.total'))->toBe(1);

    Fam::call($c['tenant'], $c['t1'], 'get', "/family-units/{$c['f1']}")->assertOk();

    $other = Fam::call($c['tenant'], $c['t1'], 'get', "/family-units/{$c['f2']}")->assertNotFound();
    $missing = Fam::call($c['tenant'], $c['t1'], 'get', '/family-units/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();

    expect(Arr::except($other->json(), ['instance', 'request_id']))->toBe(Arr::except($missing->json(), ['instance', 'request_id']));

    // Las sub-colecciones de la unidad ajena también son 404; las de la propia, solo lo alcanzado.
    Fam::call($c['tenant'], $c['t1'], 'get', "/family-units/{$c['f2']}/students")->assertNotFound();
    $students = Fam::call($c['tenant'], $c['t1'], 'get', "/family-units/{$c['f1']}/students")->assertOk();
    expect(array_column($students->json('data'), 'student_person'))->toBe([$c['s1']->public_id]);
});

test('CA-FAM-UNIT-040 api.md §1.1 RN-FAM-UNIT-36: para el tutor, students_count cuenta solo los alumnos que alcanza y guardians_count solo los tutores con vínculo activo con ellos; el personal ve el total', function (): void {
    $c = twoFamilies('sc-040b');

    $asTutor = Fam::call($c['tenant'], $c['t1'], 'get', "/family-units/{$c['f1']}")->assertOk();
    $asStaff = Fam::call($c['tenant'], $c['admin'], 'get', "/family-units/{$c['f1']}")->assertOk();

    // T1 y T1b tienen vínculo activo con S1; T1c no tiene ninguno; S1x no está vinculado con T1.
    expect($asTutor->json('students_count'))->toBe(1)
        ->and($asTutor->json('guardians_count'))->toBe(2)
        ->and($asStaff->json('students_count'))->toBe(2)
        ->and($asStaff->json('guardians_count'))->toBe(3);

    $listed = Fam::call($c['tenant'], $c['t1'], 'get', '/family-units')->assertOk()->json('data.0');
    expect($listed['students_count'])->toBe(1)->and($listed['guardians_count'])->toBe(2);
});

test('CA-FAM-UNIT-040 permisos.md §3.1: el tutor lista y lee solo los tutores con vínculo activo con sus alumnos (él mismo y T1b), y la ficha de otro tutor es 404', function (): void {
    $c = twoFamilies('sc-040c');

    $guardians = Fam::call($c['tenant'], $c['t1'], 'get', '/guardians')->assertOk();

    expect(array_column($guardians->json('data'), 'public_id'))->toEqualCanonicalizing([$c['g1'], $c['g1b']]);

    Fam::call($c['tenant'], $c['t1'], 'get', "/guardians/{$c['g1b']}")->assertOk();
    Fam::call($c['tenant'], $c['t1'], 'get', "/guardians/{$c['g1c']}")->assertNotFound();
    Fam::call($c['tenant'], $c['t1'], 'get', "/guardians/{$c['g2']}")->assertNotFound();

    // OPEN-FAM-UNIT-20: de los demás tutores solo nombre; ningún dato de contacto.
    $keys = array_keys($guardians->json('data.0'));
    expect($keys)->toEqualCanonicalizing(['public_id', 'family_unit', 'user', 'display_name', 'is_principal', 'created_at', 'updated_at']);

    // Filtrar por una unidad que no alcanza no es error: devuelve vacío.
    expect(Fam::call($c['tenant'], $c['t1'], 'get', "/guardians?family_unit={$c['f2']}")->assertOk()->json('data'))->toBe([]);
});

test('CA-FAM-UNIT-041 permisos.md §3.1: con vinculo_tutor.leer de ámbito unidad_familiar el tutor lista y lee solo los vínculos activos de sus alumnos, y el vínculo de otra familia es 404 (listado y detalle)', function (): void {
    $c = twoFamilies('sc-041');

    $list = Fam::call($c['tenant'], $c['t1'], 'get', '/guardian-links')->assertOk();

    expect(array_column($list->json('data'), 'public_id'))->toEqualCanonicalizing([$c['l1'], $c['l1b']]);

    Fam::call($c['tenant'], $c['t1'], 'get', "/guardian-links/{$c['l1b']}")->assertOk();
    Fam::call($c['tenant'], $c['t1'], 'get', "/guardian-links/{$c['l2']}")->assertNotFound();

    // El ámbito se aplica antes que el filtro: pedir los vínculos de un alumno ajeno devuelve vacío, no error.
    expect(Fam::call($c['tenant'], $c['t1'], 'get', "/guardian-links?student_person={$c['s2']->public_id}")->assertOk()->json('data'))->toBe([])
        ->and(Fam::call($c['tenant'], $c['t1'], 'get', "/guardian-links?guardian={$c['g2']}")->assertOk()->json('data'))->toBe([]);

    // Y el tutor de la otra familia ve solo lo suyo.
    expect(array_column(Fam::call($c['tenant'], $c['t2'], 'get', '/guardian-links')->assertOk()->json('data'), 'public_id'))->toBe([$c['l2']]);
});

test('permisos.md §3.1 funcional.md §6: un tutor sin vínculos activos, un usuario sin faceta de tutor y uno con ámbito pero sin persona de tutor ven un ámbito vacío y 404 en el detalle', function (): void {
    $c = twoFamilies('sc-empty');

    $noLinks = Fam::userWith($c['tenant'], $c['admin'], 'sinvinculos', ['familia.leer' => 'unidad_familiar', 'vinculo_tutor.leer' => 'unidad_familiar']);
    Fam::guardian($c['tenant'], $c['admin'], $noLinks, $c['f1'])->assertCreated();

    $notGuardian = Fam::userWith($c['tenant'], $c['admin'], 'nonutor', ['familia.leer' => 'unidad_familiar', 'vinculo_tutor.leer' => 'unidad_familiar']);

    foreach ([$noLinks, $notGuardian] as $user) {
        expect(Fam::call($c['tenant'], $user, 'get', '/family-units')->assertOk()->json('data'))->toBe([])
            ->and(Fam::call($c['tenant'], $user, 'get', '/guardians')->assertOk()->json('data'))->toBe([])
            ->and(Fam::call($c['tenant'], $user, 'get', '/guardian-links')->assertOk()->json('data'))->toBe([]);

        Fam::call($c['tenant'], $user, 'get', "/family-units/{$c['f1']}")->assertNotFound();
        Fam::call($c['tenant'], $user, 'get', "/guardian-links/{$c['l1']}")->assertNotFound();
    }
});

test('RPERM-009 permisos.md §3.1: con ámbito todos junto a unidad_familiar el sujeto no se acota (RN-PERM-09: todos absorbe)', function (): void {
    $c = twoFamilies('sc-union');

    $mixed = Fam::userWithRoles($c['tenant'], $c['admin'], 'mixto', [
        'a' => ['familia.leer' => ['allow', 'unidad_familiar']],
        'b' => ['familia.leer' => ['allow', 'todos']],
    ]);

    expect(Fam::call($c['tenant'], $mixed, 'get', '/family-units')->assertOk()->json('meta.total'))->toBe(2);
});

test('CA-FAM-UNIT-042 ADR-044 §4.1: conceder un permiso de este módulo con un ámbito que no admite (grupo, clase, o unidad_familiar en una escritura) es 422', function (): void {
    [$tenant, $admin] = Fam::setup('sc-042');

    $attempt = function (string $code, string $scope) use ($tenant, $admin) {
        resetSessionState();

        return test()->actingAs($admin)->postJson(Fam::url($tenant, '/roles'), [
            'code' => 'rol_'.str_replace('.', '_', $code).'_'.$scope,
            'name' => 'Rol de prueba',
            'permissions' => [['code' => $code, 'effect' => 'allow', 'scope' => $scope]],
        ]);
    };

    foreach ([['familia.leer', 'grupo'], ['familia.leer', 'clase'], ['familia.crear', 'unidad_familiar'], ['familia.actualizar', 'unidad_familiar'], ['vinculo_tutor.actualizar', 'unidad_familiar'], ['revocacion_tutor.leer', 'unidad_familiar'], ['revocacion_tutor.crear', 'unidad_familiar']] as [$code, $scope]) {
        $attempt($code, $scope)->assertStatus(422);
    }

    $attempt('familia.leer', 'unidad_familiar')->assertCreated();
    $attempt('vinculo_tutor.leer', 'unidad_familiar')->assertCreated();

    app(TenantContext::class)->runFor($tenant->id, fn () => expect(Role::where('code', 'rol_familia_crear_unidad_familiar')->exists())->toBeFalse());
});

test('CA-FAM-UNIT-044 RPERM-007: un usuario con dos roles, uno que concede familia.leer con unidad_familiar y otro que lo deniega, recibe 403 en el listado y en el detalle', function (): void {
    $c = twoFamilies('sc-044');

    $user = Fam::userWithRoles($c['tenant'], $c['admin'], 'doble', [
        'tutor' => ['familia.leer' => ['allow', 'unidad_familiar'], 'vinculo_tutor.leer' => ['allow', 'unidad_familiar']],
        'docente' => ['familia.leer' => ['deny', 'todos']],
    ]);
    Fam::guardian($c['tenant'], $c['admin'], $user, $c['f1'])->assertCreated();

    Fam::call($c['tenant'], $user, 'get', '/family-units')->assertForbidden();
    Fam::call($c['tenant'], $user, 'get', "/family-units/{$c['f1']}")->assertForbidden();
    Fam::call($c['tenant'], $user, 'get', '/guardians')->assertForbidden();
    // El otro permiso no está denegado.
    Fam::call($c['tenant'], $user, 'get', '/guardian-links')->assertOk();
});

test('CA-FAM-UNIT-046 AR-10 permisos.md §3.1: el ámbito unidad_familiar tiene resolutor para familia y vinculo_tutor, y ninguno para grupo, clase, departamento o propios', function (): void {
    $registry = app(ScopeResolverRegistry::class);

    foreach (['familia', 'vinculo_tutor'] as $resource) {
        expect($registry->has($resource, Scope::UnidadFamiliar))->toBeTrue($resource);

        foreach ([Scope::Grupo, Scope::Clase, Scope::Departamento, Scope::Propios] as $scope) {
            expect($registry->has($resource, $scope))->toBeFalse("{$resource} {$scope->value}");
        }
    }

    // Solo el proveedor de servicios del módulo toca el registro de resolutores.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/FamUnit')));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && ! str_ends_with($file->getPathname(), 'Infrastructure/FamUnitServiceProvider.php')) {
            expect(file_get_contents($file->getPathname()))->not->toContain('ScopeResolverRegistry', $file->getPathname());
        }
    }
});

test('permisos.md §3.1: el ámbito unidad_familiar no concede escrituras: un tutor con familia.leer no puede crear, editar ni borrar (403) aunque llegue a su unidad', function (): void {
    $c = twoFamilies('sc-nowrite');

    Fam::call($c['tenant'], $c['t1'], 'post', '/family-units', ['code' => 'NUEVA'])->assertForbidden();
    Fam::call($c['tenant'], $c['t1'], 'patch', "/family-units/{$c['f1']}", ['code' => 'OTRA'])->assertForbidden();
    Fam::call($c['tenant'], $c['t1'], 'delete', "/family-units/{$c['f1']}")->assertForbidden();
    Fam::call($c['tenant'], $c['t1'], 'post', "/family-units/{$c['f1']}/students", ['student_person' => $c['s2']->public_id])->assertForbidden();
    Fam::call($c['tenant'], $c['t1'], 'delete', "/guardians/{$c['g1']}")->assertForbidden();
    Fam::call($c['tenant'], $c['t1'], 'post', "/guardian-links/{$c['l1']}/revocation", ['reason' => 'resolucion_judicial'])->assertForbidden();
    Fam::call($c['tenant'], $c['t1'], 'patch', "/guardian-links/{$c['l1']}", ['access_level' => 'lectura_escritura'])->assertForbidden();
});
