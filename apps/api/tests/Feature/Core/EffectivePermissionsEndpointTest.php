<?php

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/funcional.md §7.10-§7.11, api.md §7 (`RPERM-009`). Dos rutas,
 * un solo cálculo: `GET /users/{public_id}/effective-permissions`
 * (administración, permiso propio) y `GET /me/effective-permissions`
 * (autoservicio, por identidad).
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

// CA-PERM-073
test('CA-PERM-073: GET /me/effective-permissions responde 200 a un usuario sin ningún permiso, sin exigir ninguno', function (): void {
    [$tenant, $admin] = provisionCoreTenant('effperm-073');

    $roleId = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), ['code' => 'sin_nada_073', 'name' => 'Sin nada'])
        ->assertCreated()->json('public_id');

    resetSessionState();

    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'sinnada-073@example.com',
        'person' => ['given_name' => 'Sin', 'family_name_1' => 'Nada'],
        'send_invitation' => false,
        'role_ids' => [$roleId],
    ])->assertCreated();

    $user = app(TenantContext::class)->runFor($tenant->id, fn () => User::where('email', 'sinnada-073@example.com')->firstOrFail());

    resetSessionState();

    $response = test()->actingAs($user)
        ->getJson(coreApiUrl($tenant->slug, '/me/effective-permissions'))
        ->assertOk();

    $usuarioLeer = collect($response->json('data'))->firstWhere('code', 'usuario.leer');
    expect($usuarioLeer['decision'])->toBe('denegado');
});

// CA-PERM-074
test('CA-PERM-074: sin permiso_efectivo.leer, GET /users/{id}/effective-permissions responde 403 incluso con el propio public_id', function (): void {
    [$tenant, $admin] = provisionCoreTenant('effperm-074');

    resetSessionState();

    test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/users/{$admin->public_id}/effective-permissions"))
        ->assertOk();

    $roleId = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), ['code' => 'sin_permiso_efectivo_074', 'name' => 'Sin permiso efectivo'])
        ->assertCreated()->json('public_id');

    resetSessionState();

    $otherPublicId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'otro-074@example.com',
        'person' => ['given_name' => 'Otro', 'family_name_1' => 'Usuario'],
        'send_invitation' => false,
        'role_ids' => [$roleId],
    ])->assertCreated()->json('public_id');

    $userWithoutPermission = app(TenantContext::class)->runFor($tenant->id, fn () => User::where('email', 'otro-074@example.com')->firstOrFail());

    resetSessionState();

    // Ni sobre otra persona...
    test()->actingAs($userWithoutPermission)
        ->getJson(coreApiUrl($tenant->slug, "/users/{$admin->public_id}/effective-permissions"))
        ->assertStatus(403);

    resetSessionState();

    // ...ni sobre sí mismo: la ruta es de administración y su autorización
    // es estática (funcional.md §7.11).
    test()->actingAs($userWithoutPermission)
        ->getJson(coreApiUrl($tenant->slug, "/users/{$otherPublicId}/effective-permissions"))
        ->assertStatus(403);
});

// CA-PERM-075
test('CA-PERM-075: GET /me/effective-permissions y GET /users/{id}/effective-permissions del propio admin devuelven la misma resolución', function (): void {
    [$tenant, $admin] = provisionCoreTenant('effperm-075');

    $mine = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/me/effective-permissions'))
        ->assertOk()->json('data');

    resetSessionState();

    $viaAdmin = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/users/{$admin->public_id}/effective-permissions"))
        ->assertOk()->json('data');

    $normalize = fn ($rows) => collect($rows)->map(fn ($r) => [$r['code'], $r['decision'], $r['scopes']])->sortBy('0')->values()->all();

    expect($normalize($mine))->toBe($normalize($viaAdmin));
});

// CA-PERM-091 (aplicado a los endpoints nuevos de este paso)
test('CA-PERM-091: los endpoints nuevos exigen sesión, y solo el de administración exige el permiso', function (): void {
    [$tenant, $admin] = provisionCoreTenant('effperm-091');

    // Sin sesión: 401 en los dos.
    test()->getJson(coreApiUrl($tenant->slug, "/users/{$admin->public_id}/effective-permissions"))->assertStatus(401);
    test()->getJson(coreApiUrl($tenant->slug, '/me/effective-permissions'))->assertStatus(401);

    // GET /me/effective-permissions nunca exige permiso, aunque el sujeto
    // no tenga ninguno (ver CA-PERM-073) — solo 401 sin sesión, jamás 403.
});

// CA-PERM-070, CA-PERM-071, CA-PERM-072 (procedencia, inercia y coherencia
// con la aplicación real)
test('CA-PERM-070/071/072: la respuesta trae procedencia por rol, motivo de inercia, y coincide con lo que la API real permite', function (): void {
    [$tenant, $admin] = provisionCoreTenant('effperm-070');

    $roleId = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'auditor_070', 'name' => 'Auditor 070',
            'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']],
        ])->assertCreated()->json('public_id');

    resetSessionState();

    $targetPublicId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => 'auditor-070@example.com',
        'person' => ['given_name' => 'Auditor', 'family_name_1' => 'Setenta'],
        'send_invitation' => false,
        'role_ids' => [$roleId],
    ])->assertCreated()->json('public_id');

    resetSessionState();

    $response = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/users/{$targetPublicId}/effective-permissions"))
        ->assertOk();

    $auditoriaLeer = collect($response->json('data'))->firstWhere('code', 'auditoria.leer');

    expect($auditoriaLeer['decision'])->toBe('permitido')
        ->and($auditoriaLeer['scopes'])->toBe(['propios'])
        ->and($auditoriaLeer['sources'][0]['role']['code'])->toBe('auditor_070')
        ->and($auditoriaLeer['sources'][0]['inert'])->toBeFalse();

    // CA-PERM-072: lo que dice el endpoint coincide con lo que la API real
    // permite — el titular del rol SÍ puede listar auditoria acotado.
    $target = app(TenantContext::class)->runFor($tenant->id, fn () => User::where('email', 'auditor-070@example.com')->firstOrFail());

    resetSessionState();

    test()->actingAs($target)
        ->getJson(coreApiUrl($tenant->slug, '/audit-logs'))
        ->assertOk();

    // CA-PERM-071: un código denegado por falta de concesión aparece como
    // "denegado" con sources vacío — distinguible de un futuro "inerte".
    $usuarioCrear = collect($response->json('data'))->firstWhere('code', 'usuario.crear');
    expect($usuarioCrear['decision'])->toBe('denegado')
        ->and($usuarioCrear['sources'])->toBe([]);
});
