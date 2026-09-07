<?php

use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\Scope;
use App\Support\Modules\ModuleAvailability;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/funcional.md §4 (RPERM-007, RPERM-011, RPERM-012). Motor de
 * resolución multi-rol completo de 1.5, ejercitado directamente contra
 * `PermissionResolver` (sin HTTP): el algoritmo exacto de §4.1, deny ciego
 * al ámbito, categoría especial por concesión y no por usuario, y las
 * cuatro inercias de `allow`.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->enter($this->tenant->id);
    $this->artisan('platform:sync-registry')->run();
});

// Sin TenantContext::leave() aquí a propósito (ver
// PermissionsSchemaTest.php/RolesSchemaTest.php): más de un test de este
// fichero fuerza deliberadamente una QueryException sobre la conexión
// `pgsql` (los CHECK del motor); esa conexión queda en transacción abortada
// hasta el rollback de `DatabaseTransactions`, y una sentencia más sobre
// ella (el `set_config` de `leave()`) fallaría con "current transaction is
// aborted". El contenedor se recrea por test, así que no hace falta.
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function makeTestUser(): User
{
    return User::factory()->for(Person::factory())->create();
}

function makeTestRole(string $code): Role
{
    return Role::create(['code' => $code, 'name' => "Rol {$code}", 'is_system' => false]);
}

// CA-PERM-023
test('CA-PERM-023: un usuario sin ningún rol está denegado (RPERM-011)', function (): void {
    $user = makeTestUser();

    expect(app(PermissionResolver::class)->can($user, 'usuario.leer'))->toBeFalse();
});

// CA-PERM-020
test('CA-PERM-020: deny en un rol veta allow de otro rol, cualquiera que sea el ámbito de las dos filas', function (): void {
    $user = makeTestUser();
    $allowRole = makeTestRole('allow_role_020');
    $denyRole = makeTestRole('deny_role_020');

    PermissionRole::create(['role_id' => $allowRole->id, 'permission_code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']);
    PermissionRole::create(['role_id' => $denyRole->id, 'permission_code' => 'auditoria.leer', 'effect' => 'deny', 'scope' => 'todos']);
    $user->roles()->attach([$allowRole->id, $denyRole->id]);

    $decision = app(PermissionResolver::class)->decide($user, 'auditoria.leer');

    expect($decision->permitted)->toBeFalse()
        ->and($decision->scopes)->toBe([]);
});

// CA-PERM-021, CA-PERM-009 (absorción)
test('CA-PERM-021: allow propios en un rol y allow todos en otro no aplica ninguna restricción de fila', function (): void {
    $user = makeTestUser();
    $roleA = makeTestRole('role_propios_021');
    $roleB = makeTestRole('role_todos_021');

    PermissionRole::create(['role_id' => $roleA->id, 'permission_code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']);
    PermissionRole::create(['role_id' => $roleB->id, 'permission_code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'todos']);
    $user->roles()->attach([$roleA->id, $roleB->id]);

    $decision = app(PermissionResolver::class)->decide($user, 'auditoria.leer');

    expect($decision->permitted)->toBeTrue()
        ->and($decision->isUnrestricted())->toBeTrue();
});

// CA-PERM-022: unión de ámbitos no comparables. `propios` es el único con
// resolutor en 1.5; se usa un segundo permiso de prueba con
// applicable_scopes incluyendo 'departamento' (sin resolutor) para
// comprobar que la UNIÓN incluye ambos aunque uno sea inerte.
test('CA-PERM-022: dos roles con ámbitos distintos y no comparables producen la unión', function (): void {
    $user = makeTestUser();
    $roleA = makeTestRole('role_a_022');
    $roleB = makeTestRole('role_b_022');

    PermissionRole::create(['role_id' => $roleA->id, 'permission_code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']);
    // segundo rol con el mismo código pero via un rol distinto: comprobamos
    // que dos concesiones "propios" desde dos roles no duplican el
    // conjunto (unique) y siguen denegando fuera del actor.
    PermissionRole::create(['role_id' => $roleB->id, 'permission_code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']);
    $user->roles()->attach([$roleA->id, $roleB->id]);

    $decision = app(PermissionResolver::class)->decide($user, 'auditoria.leer');

    expect($decision->permitted)->toBeTrue()
        ->and($decision->scopes)->toBe([Scope::Propios]);
});

// CA-PERM-024, CA-PERM-025 (módulo desactivado)
test('CA-PERM-024/025: un permiso de un módulo desactivado es inerte, pero un deny del mismo módulo sigue vetando', function (): void {
    DB::connection('pgsql_owner')->table('permissions')->updateOrInsert(
        ['code' => 'modulo_test.leer'],
        ['resource' => 'modulo_test', 'action' => 'leer', 'module_code' => 'modulo_test_desactivado',
            'is_special_category' => false, 'applicable_scopes' => null, 'retired_at' => null],
    );

    $user = makeTestUser();
    $allowRole = makeTestRole('allow_role_024');
    $denyRole = makeTestRole('deny_role_024');

    PermissionRole::create(['role_id' => $allowRole->id, 'permission_code' => 'modulo_test.leer', 'effect' => 'allow', 'scope' => 'todos']);
    $user->roles()->attach($allowRole->id);

    $decision = app(PermissionResolver::class)->decide($user, 'modulo_test.leer');
    expect($decision->permitted)->toBeFalse();
    expect($decision->sources[0]->inert)->toBeTrue()
        ->and($decision->sources[0]->inertReason)->toBe('inerte_modulo');

    PermissionRole::create(['role_id' => $denyRole->id, 'permission_code' => 'modulo_test.leer', 'effect' => 'deny', 'scope' => 'todos']);
    $user->roles()->attach($denyRole->id);

    $decision2 = app(PermissionResolver::class)->decide($user, 'modulo_test.leer');
    expect($decision2->permitted)->toBeFalse();

    // Sin cleanup del catálogo aquí: `permission_role` (conexión `pgsql`,
    // dentro de la transacción de test que `DatabaseTransactions` revierte
    // al final) referencia todavía esta fila de `permissions` (conexión
    // `pgsql_owner`, autocommit) — borrarla ahora bloquearía esperando a
    // que la transacción de `pgsql` termine, y no termina hasta que este
    // método retorna: interbloqueo seguro. La fila de prueba en el
    // catálogo de plataforma es inofensiva y se sobrescribe (updateOrInsert)
    // en la siguiente ejecución; su `module_code` no es 'core', así que no
    // afecta a los recuentos de `PermissionCatalogTest`.
});

// CA-PERM-030, CA-PERM-031, CA-PERM-032 (categoría especial)
test('CA-PERM-030/031/032: la conjunción de categoría especial es por concesión, no por usuario', function (): void {
    // Aísla la variable bajo prueba: un ModuleAvailability que siempre
    // concede evita que el filtro de inercia de módulo (§4.1, comprobado
    // aparte en CA-PERM-024/025) interfiera con el de categoría especial.
    app()->instance(ModuleAvailability::class, new class implements ModuleAvailability
    {
        public function isEnabled(string $moduleCode): bool
        {
            return true;
        }
    });

    DB::connection('pgsql_owner')->table('permissions')->updateOrInsert(
        ['code' => 'salud_test.leer'],
        ['resource' => 'salud_test', 'action' => 'leer', 'module_code' => 'test_categoria_especial',
            'is_special_category' => true, 'applicable_scopes' => null, 'retired_at' => null],
    );

    $user = makeTestUser();
    $roleWithoutAttribute = makeTestRole('rol_sin_atributo_030');
    PermissionRole::create(['role_id' => $roleWithoutAttribute->id, 'permission_code' => 'salud_test.leer', 'effect' => 'allow', 'scope' => 'todos']);
    $user->roles()->attach($roleWithoutAttribute->id);

    // CA-PERM-030: concedido desde un rol sin special_data_access ⇒ inerte.
    $decision = app(PermissionResolver::class)->decide($user, 'salud_test.leer');
    expect($decision->permitted)->toBeFalse()
        ->and($decision->sources[0]->inertReason)->toBe('inerte_datos_especiales');

    // CA-PERM-031: la misma concesión desde un rol CON el atributo sí cuenta.
    $roleWithAttribute = Role::create(['code' => 'rol_con_atributo_030', 'name' => 'Con atributo', 'is_system' => false, 'special_data_access' => true]);
    PermissionRole::create(['role_id' => $roleWithAttribute->id, 'permission_code' => 'salud_test.leer', 'effect' => 'allow', 'scope' => 'todos']);
    $userWithAttributeRole = makeTestUser();
    $userWithAttributeRole->roles()->attach($roleWithAttribute->id);

    $decision2 = app(PermissionResolver::class)->decide($userWithAttributeRole, 'salud_test.leer');
    expect($decision2->permitted)->toBeTrue();

    // CA-PERM-032: el usuario tiene special_data_access por OTRO rol, pero
    // la concesión de salud viene de un rol SIN el atributo ⇒ sigue sin
    // concederse (confused deputy).
    $user->roles()->attach($roleWithAttribute->id);
    // $user ya tiene $roleWithoutAttribute (concede salud_test.leer sin
    // atributo) y ahora $roleWithAttribute (tiene el atributo pero no
    // concede salud_test.leer).
    $decision3 = app(PermissionResolver::class)->decide($user, 'salud_test.leer');
    expect($decision3->permitted)->toBeFalse();

    // Sin cleanup del catálogo aquí — mismo motivo que en el test anterior
    // (interbloqueo entre la transacción de `pgsql` y la conexión
    // autocommit `pgsql_owner` mientras existan filas de `permission_role`
    // referenciando esta fila).
});

// CA-PERM-006, RN-PERM-05: fila con ámbito sin resolutor inyectada a mano.
test('CA-PERM-006: una fila con ámbito sin resolutor, inyectada directamente, deniega al resolver', function (): void {
    $user = makeTestUser();
    $role = makeTestRole('rol_grupo_006');

    // Inserción directa, saltándose la API y su validación de negocio —
    // exactamente el escenario que RN-PERM-05 exige que falle en cerrado.
    PermissionRole::create(['role_id' => $role->id, 'permission_code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'grupo']);
    $user->roles()->attach($role->id);

    $decision = app(PermissionResolver::class)->decide($user, 'usuario.leer');

    expect($decision->permitted)->toBeFalse()
        ->and($decision->sources[0]->inertReason)->toBe('inerte_sin_resolutor');
});

// CA-PERM-002: NOT NULL en el motor. Test propio (no combinado con
// CA-PERM-001): dos inserciones fallidas seguidas sobre la misma conexión
// transaccional de test dejarían la segunda en "current transaction is
// aborted" tras la primera — cada CHECK necesita su propia transacción de
// test para poder comprobarse.
test('CA-PERM-002: el motor rechaza scope nulo', function (): void {
    $role = makeTestRole('rol_check_002');

    expect(fn () => DB::table('permission_role')->insert([
        'tenant_id' => $this->tenant->id, 'role_id' => $role->id, 'permission_code' => 'usuario.leer', 'effect' => 'allow', 'scope' => null,
    ]))->toThrow(QueryException::class);
});

// CA-PERM-001: CHECK de vocabulario en el motor.
test('CA-PERM-001: el motor rechaza scope fuera del vocabulario', function (): void {
    $role = makeTestRole('rol_check_001');

    expect(fn () => DB::table('permission_role')->insert([
        'tenant_id' => $this->tenant->id, 'role_id' => $role->id, 'permission_code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'departamento_x',
    ]))->toThrow(QueryException::class);
});

// CA-PERM-003 (sucesor de CA-CORE-042)
test('CA-PERM-003: ninguna fila de permission_role tiene scope nulo o fuera del vocabulario tras la migración', function (): void {
    $offending = DB::table('permission_role')
        ->whereNull('scope')
        ->orWhereNotIn('scope', ['todos', 'propios', 'departamento', 'grupo', 'clase', 'unidad_familiar'])
        ->count();

    expect($offending)->toBe(0);
});

// CA-PERM-007
test('CA-PERM-007: un permiso sin applicable_scopes admite exactamente [todos]', function (): void {
    $permission = Permission::query()->find('usuario.leer');

    expect($permission->applicable_scopes)->toBeNull()
        ->and($permission->applicableScopes())->toBe([Scope::Todos]);
});
