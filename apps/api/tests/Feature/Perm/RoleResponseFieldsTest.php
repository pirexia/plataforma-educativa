<?php

use App\Models\Permission;
use App\Support\Modules\PermissionResourceLabels;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/funcional.md §20.14, api.md §14.3 (1.5b): S-PERM-1
 * (`users_count` en el detalle y en las respuestas de escritura del rol,
 * `CA-PERM-135`) y S-PERM-2 (`resource_label` traducido por el módulo
 * dueño del recurso, `CA-PERM-134`).
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * @return string public_id del usuario creado
 */
function permCreateUserWithRole(object $tenant, object $admin, string $email, string $rolePublicId): string
{
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => $email,
        'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
        'send_invitation' => false,
        'role_ids' => [$rolePublicId],
    ])->assertCreated();

    resetSessionState();

    return $email;
}

// CA-PERM-135
test('CA-PERM-135: GET /roles/{id} devuelve users_count igual que el listado, y las escrituras del rol también lo traen', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-135');

    $created = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, '/roles'), ['code' => 'rol_135', 'name' => 'Rol 135'])
        ->assertCreated();

    expect($created->json('users_count'))->toBe(0);

    $roleId = $created->json('public_id');
    resetSessionState();

    permCreateUserWithRole($tenant, $admin, 'uno-135@example.com', $roleId);
    permCreateUserWithRole($tenant, $admin, 'dos-135@example.com', $roleId);

    $detail = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/roles/{$roleId}"))
        ->assertOk();

    resetSessionState();

    $list = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, '/roles?per_page=100'))
        ->assertOk();

    $fromList = collect($list->json('data'))->firstWhere('public_id', $roleId);

    expect($detail->json('users_count'))->toBe(2)
        ->and($fromList['users_count'])->toBe(2);

    resetSessionState();

    $patched = test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, "/roles/{$roleId}"), ['name' => 'Rol 135 bis'])
        ->assertOk();

    expect($patched->json('users_count'))->toBe(2);

    resetSessionState();

    $replaced = test()->actingAs($admin)
        ->putJson(coreApiUrl($tenant->slug, "/roles/{$roleId}/permissions"), [
            'permissions' => [['code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'todos']],
        ])
        ->assertOk();

    expect($replaced->json('users_count'))->toBe(2);
});

// CA-PERM-134
test('CA-PERM-134: todo recurso del catálogo trae resource_label no vacío en los cuatro idiomas', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-134');

    $labels = app(PermissionResourceLabels::class);
    $resources = Permission::query()->whereNull('retired_at')->distinct()->pluck('resource');

    expect($resources)->not->toBeEmpty();

    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        app()->setLocale($locale);

        foreach ($resources as $resource) {
            $label = $labels->label($resource);

            // La rama por defecto (código del recurso) significa que ningún
            // módulo declaró la etiqueta: el test falla, no la respuesta.
            expect($label)->not->toBe('', "{$resource} sin etiqueta en {$locale}")
                ->and($label)->not->toBe($resource, "{$resource} sin etiqueta traducida en {$locale}");
        }
    }

    app()->setLocale('es');
});

// CA-PERM-134
test('CA-PERM-134: resource_label sale en GET /permissions, en los permisos efectivos y en permissions[] del rol', function (): void {
    [$tenant, $admin] = provisionCoreTenant('perm-134b');

    $catalog = test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/permissions'))->assertOk();

    $row = collect($catalog->json('data'))->firstWhere('code', 'auditoria.leer');
    $mfa = collect($catalog->json('data'))->firstWhere('code', 'mfa.leer');

    expect($row['resource'])->toBe('auditoria')
        ->and($row['resource_label'])->toBe('Auditoría')
        ->and($mfa['resource_label'])->toBe('Autenticación en dos pasos (MFA)');

    foreach ($catalog->json('data') as $permission) {
        expect($permission['resource_label'])->toBeString()->not->toBe('');
    }

    resetSessionState();

    $effective = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/users/{$admin->public_id}/effective-permissions"))
        ->assertOk();

    foreach ($effective->json('data') as $effectiveRow) {
        expect($effectiveRow['resource_label'])->toBeString()->not->toBe('');
    }

    resetSessionState();

    $mine = test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/me/effective-permissions'))->assertOk();

    expect(collect($mine->json('data'))->firstWhere('code', 'usuario.leer')['resource_label'])->toBe('Usuarios');

    resetSessionState();

    $role = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'rol_134',
        'name' => 'Rol 134',
        'permissions' => [['code' => 'auditoria.leer', 'effect' => 'allow', 'scope' => 'propios']],
    ])->assertCreated();

    expect($role->json('permissions.0.resource_label'))->toBe('Auditoría');

    resetSessionState();

    $detail = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/roles/{$role->json('public_id')}"))
        ->assertOk();

    expect($detail->json('permissions.0.resource'))->toBe('auditoria')
        ->and($detail->json('permissions.0.resource_label'))->toBe('Auditoría');
});
