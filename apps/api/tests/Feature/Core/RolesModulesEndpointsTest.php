<?php

use App\Models\ModuleSubscription;
use App\Models\PermissionRole;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

test('GET /roles devuelve los 16 roles predefinidos y GET /roles/{id} sus permisos', function (): void {
    [$tenant, $admin] = provisionCoreTenant('roles-001');

    $list = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/roles?per_page=100'))
        ->assertOk()
        ->json('data');

    expect($list)->toHaveCount(16);

    $adminRole = collect($list)->firstWhere('code', 'administrador_centro');
    expect($adminRole['is_system'])->toBeTrue()
        ->and($adminRole['mfa_required'])->toBeTrue();

    $detail = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/roles/{$adminRole['public_id']}"))
        ->assertOk();

    // 21 de REQ-CORE (20 de 1.1 + rol.actualizar de 1.3) + 11 de REQ-AUTH
    // (bloqueo_cuenta.leer/eliminar de 1.2, mfa.leer/eliminar de 1.3,
    // exencion_mfa.crear/leer/eliminar de 1.3b, proveedor_identidad.leer/
    // crear/actualizar/eliminar de 1.4b — permisos.md §5/§D.6/§F.7 —
    // solo administrador_centro los recibe) + los cuatro de REQ-PERM/
    // permisos.md §5 (1.5): rol.crear, rol.eliminar,
    // rol_datos_especiales.actualizar, permiso_efectivo.leer.
    expect($detail->json('permissions'))->toHaveCount(36);
});

// CA-CORE-041 (REQ-AUTH-003, 1.3, RN-AUTH-70) / CA-PERM-053, CA-PERM-056
// (REQ-PERM, 1.5): `DELETE /roles/{public_id}` existe desde 1.5 y un rol
// `is_system` responde `409`, no `405`; `code` sigue sin ser editable
// (`422`, ahora con el código específico `role_code_immutable`), y `name`
// en un rol de sistema responde `422` (`role_name_system`).
test('CA-CORE-041/CA-PERM-053/056: un rol del sistema no se puede eliminar (409) ni renombrar (422); su code tampoco (422)', function (): void {
    [$tenant, $admin] = provisionCoreTenant('roles-041');

    $role = app(TenantContext::class)->runFor($tenant->id, fn () => Role::where('code', 'docente')->firstOrFail());

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, "/roles/{$role->public_id}"), ['name' => 'Cambiado'])
        ->assertStatus(422);

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, "/roles/{$role->public_id}"), ['code' => 'otro_codigo'])
        ->assertStatus(422);

    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, "/roles/{$role->public_id}"))
        ->assertStatus(409);
});

// CA-CORE-043
test('CA-CORE-043: asignar a un usuario un rol de otro tenant falla y no crea la fila role_user', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('roles-043-a');
    [$tenantB] = provisionCoreTenant('roles-043-b');

    $foreignRole = app(TenantContext::class)->runFor($tenantB->id, fn () => Role::where('code', 'docente')->firstOrFail());

    $targetUser = test()->actingAs($adminA)->postJson(coreApiUrl($tenantA->slug, '/users'), [
        'email' => 'objetivo@example.com',
        'person' => ['given_name' => 'Objetivo', 'family_name_1' => 'Prueba'],
        'send_invitation' => false,
    ])->assertCreated()->json('public_id');

    test()->actingAs($adminA)
        ->putJson(coreApiUrl($tenantA->slug, "/users/{$targetUser}/roles"), [
            'role_ids' => [$foreignRole->public_id],
        ])
        ->assertStatus(422);

    app(TenantContext::class)->runFor($tenantA->id, function () use ($targetUser): void {
        $user = User::where('public_id', $targetUser)->firstOrFail();
        expect($user->roles)->toBeEmpty();
    });
});

test('PUT /users/{id}/roles reemplaza el conjunto y emite UserRolesChanged', function (): void {
    [$tenant, $admin] = provisionCoreTenant('roles-put');

    $docente = app(TenantContext::class)->runFor($tenant->id, fn () => Role::where('code', 'docente')->firstOrFail());
    $secretaria = app(TenantContext::class)->runFor($tenant->id, fn () => Role::where('code', 'secretaria')->firstOrFail());

    $targetUser = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'multi-rol@example.com',
        'person' => ['given_name' => 'Multi', 'family_name_1' => 'Rol'],
        'role_ids' => [$docente->public_id],
        'send_invitation' => false,
    ])->assertCreated()->json('public_id');

    $response = test()->actingAs($admin)
        ->putJson(coreApiUrl($tenant->slug, "/users/{$targetUser}/roles"), [
            'role_ids' => [$secretaria->public_id],
        ])
        ->assertOk();

    expect(collect($response->json('data'))->pluck('code')->all())->toBe(['secretaria']);
});

// Issue #168 (security-reviewer, revisión de 1.5): la comprobación de
// `asignacion_rol.eliminar` en `ReplaceUserRoles` (api.md §8.2) no tenía
// ningún test que la ejercitara con un actor que posea `asignacion_rol.crear`
// sin `asignacion_rol.eliminar` — el único test existente (arriba) actúa
// siempre como `administrador_centro`, que tiene los dos.
test('PUT /users/{id}/roles: retirar un rol exige asignacion_rol.eliminar además de .crear', function (): void {
    [$tenant, $admin] = provisionCoreTenant('roles-168');

    [$roleA, $roleB, $actorRole] = app(TenantContext::class)->runFor($tenant->id, function () {
        // Roles sin ninguna concesión propia: así `assertActorCanGrant()`
        // nunca bloquea el caso de "solo añadir" por un motivo distinto
        // al que este test quiere aislar.
        $roleA = Role::create(['code' => 'rol_168_a', 'name' => 'Rol 168 A', 'is_system' => false]);
        $roleB = Role::create(['code' => 'rol_168_b', 'name' => 'Rol 168 B', 'is_system' => false]);

        $actorRole = Role::create(['code' => 'rol_168_gestor', 'name' => 'Gestor parcial 168', 'is_system' => false]);
        PermissionRole::create(['role_id' => $actorRole->id, 'permission_code' => 'asignacion_rol.crear', 'effect' => 'allow', 'scope' => 'todos']);

        return [$roleA, $roleB, $actorRole];
    });

    $targetPublicId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'objetivo-168@example.com',
        'person' => ['given_name' => 'Objetivo', 'family_name_1' => 'CientoSesentaYOcho'],
        'role_ids' => [$roleA->public_id],
        'send_invitation' => false,
    ])->assertCreated()->json('public_id');

    $actorUser = app(TenantContext::class)->runFor($tenant->id, function () use ($actorRole) {
        $person = Person::factory()->create(['contact_email' => 'gestor-168@example.com']);
        $user = User::factory()->for($person)->create(['email' => 'gestor-168@example.com']);
        $user->roles()->attach($actorRole->id);

        return $user;
    });

    // Solo añadir (no retira roleA) ⇒ basta con asignacion_rol.crear.
    test()->actingAs($actorUser)
        ->putJson(coreApiUrl($tenant->slug, "/users/{$targetPublicId}/roles"), [
            'role_ids' => [$roleA->public_id, $roleB->public_id],
        ])
        ->assertOk();

    // Retirar roleA (el conjunto nuevo ya no lo incluye) ⇒ 403 sin
    // asignacion_rol.eliminar, aunque el actor SÍ tiene asignacion_rol.crear.
    test()->actingAs($actorUser)
        ->putJson(coreApiUrl($tenant->slug, "/users/{$targetPublicId}/roles"), [
            'role_ids' => [$roleB->public_id],
        ])
        ->assertStatus(403);
});

// CA-CORE-060
test('CA-CORE-060: un tenant sin fila de suscripción para un módulo obtiene 403 al usar EnsureModuleEnabled', function (): void {
    [$tenant] = provisionCoreTenant('modules-060');

    Route::middleware(['resolve-tenant', 'resolve-locale', 'module-enabled:acad-test-060'])
        ->get('/api/v1/_test/probe-060', fn () => response()->json(['ok' => true]));

    test()->get('http://'.$tenant->slug.'.'.config('tenancy.base_domain').'/api/v1/_test/probe-060')
        ->assertStatus(403)
        ->assertJsonPath('type', 'urn:pge:error:module-disabled');
});

// Issue #169 (security-reviewer, revisión de 1.5): CA-PERM-093/RN-PERM-18
// exige que un módulo desactivado responda 403 module-disabled ANTES de
// evaluar el permiso — sin test propio que encadene ambos middlewares en
// una misma ruta. El usuario de este test no tiene ningún rol: si el orden
// se invirtiera alguna vez, el fallo sería 403 forbidden (permiso), no
// 403 module-disabled — la diferencia es lo que este test fija.
test('CA-PERM-093: módulo desactivado responde module-disabled antes de evaluar el permiso', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-093');

    $userWithoutAnyRole = app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create(['contact_email' => 'sin-rol-093@example.com']);

        return User::factory()->for($person)->create(['email' => 'sin-rol-093@example.com']);
    });

    Route::middleware(['resolve-tenant', 'resolve-locale', 'module-enabled:modulo-test-093', 'permission:algun.permiso.inexistente'])
        ->get('/api/v1/_test/probe-093', fn () => response()->json(['ok' => true]));

    test()->actingAs($userWithoutAnyRole)
        ->get('http://'.$tenant->slug.'.'.config('tenancy.base_domain').'/api/v1/_test/probe-093')
        ->assertStatus(403)
        ->assertJsonPath('type', 'urn:pge:error:module-disabled');

    // Control: el mismo usuario, sin el módulo de por medio, sí recibe el
    // 403 de permiso — confirma que la ruta de prueba distingue los dos
    // motivos y que el resultado de arriba no es un 403 genérico.
    Route::middleware(['resolve-tenant', 'resolve-locale', 'permission:algun.permiso.inexistente'])
        ->get('/api/v1/_test/probe-093-sin-modulo', fn () => response()->json(['ok' => true]));

    test()->actingAs($userWithoutAnyRole)
        ->get('http://'.$tenant->slug.'.'.config('tenancy.base_domain').'/api/v1/_test/probe-093-sin-modulo')
        ->assertStatus(403)
        ->assertJsonPath('type', 'urn:pge:error:forbidden');
});

// CA-CORE-061
test('CA-CORE-061: cambiar enabled de una suscripción por API no existe (422 si se envía)', function (): void {
    [$tenant, $admin] = provisionCoreTenant('modules-061');

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, '/module-subscriptions/01ARZ3NDEKTSV4RRFFQ69G5FAV'), [
            'enabled' => true,
        ])
        ->assertStatus(422);
});

// Issue #223 (hallazgo Medio de revisión independiente, 1.6c): la
// cobertura existente de CA-BO-031 (ModuleSubscriptionsSchemaTest.php)
// escribe y lee directamente por `DB::table()` crudo, sin ejercitar el
// camino real de Eloquent de `PATCH /module-subscriptions/{publicId}`
// (`Core\Http\Controllers\ModulesController::updateSettings()`). Este
// test hace la petición HTTP real, autenticado como el Administrador de
// Centro del propio tenant, y relee el resultado con el propio modelo
// Eloquent (`ModuleSubscription::TENANT_VISIBLE_COLUMNS`, RN-BO-82), no
// con un `DB::table()` crudo ni por otra conexión.
//
// La relectura tiene que ir por la conexión `pgsql` (la misma que usó la
// petición, dentro de la MISMA transacción de `DatabaseTransactions` de
// este test): `pgsql_platform` es una sesión distinta y no vería un
// `UPDATE` todavía sin `COMMIT` — precisamente lo que le pasó a la
// primera versión de este test (hallazgo propio al escribirlo). Por el
// mismo motivo no se comprueba aquí `updated_by`: `plataforma_app` no
// tiene privilegio de columna para leerlo ni siquiera vía `pgsql`
// (`RN-BO-82`, `CA-BO-147`) y `pgsql_platform` no vería el `UPDATE` sin
// confirmar — es decir, es estructuralmente imposible de comprobar desde
// este camino sin romper la propia barrera de privilegio que se quiere
// proteger. Que nadie lo escribe ya está verificado donde SÍ es
// observable, el camino del backoffice (`CA-BO-137`/`CA-BO-138`, que
// hace `COMMIT` real por no estar bajo `DatabaseTransactions` de
// `pgsql`).
test('CA-BO-031: PATCH /module-subscriptions/{publicId} actualiza settings por el camino real de Eloquent', function (): void {
    [$tenant, $admin] = provisionCoreTenant('modules-223');

    if (! DB::connection('pgsql_owner')->table('modules')->where('code', 'req-test-mod-223')->exists()) {
        DB::connection('pgsql_owner')->table('modules')->insert([
            'code' => 'req-test-mod-223', 'name_key' => 'modules.test', 'phase' => '1',
        ]);
    }

    $publicId = (string) Str::ulid();
    DB::connection('pgsql_platform')->table('module_subscriptions')->insert([
        'tenant_id' => $tenant->id, 'public_id' => $publicId, 'module_code' => 'req-test-mod-223',
        'enabled' => true, 'enabled_at' => now()->subMinute(), 'reason' => 'Alta de prueba',
        'settings' => json_encode(['locale_default' => 'es-ES']),
        'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute(),
    ]);

    $response = test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, "/module-subscriptions/{$publicId}"), [
            'settings' => ['locale_default' => 'fr'],
        ]);

    $response->assertOk();
    expect($response->json('public_id'))->toBe($publicId)
        ->and($response->json('module_code'))->toBe('req-test-mod-223')
        ->and($response->json('settings'))->toBe(['locale_default' => 'fr']);

    $subscription = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => ModuleSubscription::query()
            ->select(ModuleSubscription::TENANT_VISIBLE_COLUMNS)
            ->where('public_id', $publicId)
            ->firstOrFail(),
    );

    expect($subscription->settings)->toBe(['locale_default' => 'fr'])
        ->and($subscription->updated_at->gt($subscription->created_at))->toBeTrue();

    // Sin DELETE aquí a propósito, mismo motivo exacto que CA-BO-031 en
    // ModuleSubscriptionsSchemaTest.php: el UPDATE de arriba corrió por
    // `pgsql` dentro de la transacción de nivel de test
    // (`DatabaseTransactions`), que mantiene el bloqueo de fila hasta que
    // el test termina — un DELETE por `pgsql_platform` (autocommit,
    // sesión distinta) sobre esa misma fila aquí se queda esperando ese
    // bloqueo para siempre (punto muerto real, reproducido al escribir
    // este test). La fila huérfana la limpia la siguiente corrida, igual
    // que allí.
    DB::connection('pgsql_platform')->table('module_subscriptions')
        ->where('module_code', 'req-test-mod-223')
        ->whereNotIn('tenant_id', DB::connection('pgsql_platform')->table('tenants')->select('id'))
        ->delete();
});

// CA-CORE-062
test('CA-CORE-062: GET /modules devuelve solo las suscripciones del propio tenant', function (): void {
    [$tenant, $admin] = provisionCoreTenant('modules-062');

    $response = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/modules'))
        ->assertOk();

    expect(collect($response->json('data'))->pluck('module_code'))->toContain('core');
});
