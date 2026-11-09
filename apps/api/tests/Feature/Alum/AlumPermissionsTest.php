<?php

use App\Models\Module;
use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Modules\Alum\Infrastructure\AlumServiceProvider;
use App\Modules\Core\Application\ProvisionTenantDefaults;
use App\Modules\Core\Domain\ModuleCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

// REQ-ALUM-001 (1.15-a T1), docs/modulos/REQ-ALUM/permisos.md §2-§4, CA-ALUM-014a, CA-ALUM-064, INV-002, INV-015.
// Catálogo y descriptor materializados por `platform:sync-registry`; siembra por `tenant:provision-defaults` y por
// `alum:grant-permissions`. Sin endpoints hasta el T2: aquí solo se fijan los cuatro códigos declarados.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

const ALUM_CODES = ['alumno.actualizar', 'alumno.crear', 'alumno.eliminar', 'alumno.leer'];

/** @return list<string> permisos de alumnado de un rol, ordenados, con su ámbito (`código:ámbito`) */
function alumGrantsOf(string $roleCode): array
{
    $role = Role::where('code', $roleCode)->firstOrFail();

    return PermissionRole::where('role_id', $role->id)
        ->where('permission_code', 'like', 'alumno.%')
        ->get()
        ->map(static fn (PermissionRole $p): string => "{$p->permission_code}:{$p->scope}")
        ->sort()->values()->all();
}

function alumRevokeAll(object $tenant): void
{
    app(TenantContext::class)->runFor($tenant->id, function (): void {
        PermissionRole::query()->where('permission_code', 'like', 'alumno.%')->get()->each->delete();
    });
}

test('REQ-ALUM-001 permisos.md §2: tras platform:sync-registry, permissions contiene exactamente los cuatro códigos de alumno, con leer admitiendo todos y unidad_familiar, y sin matricula ni exportar', function (): void {
    test()->artisan('platform:sync-registry')->assertSuccessful();

    $rows = Permission::query()->where('module_code', 'alum')->orderBy('code')->get();

    expect($rows->pluck('code')->all())->toBe(ALUM_CODES);

    foreach ($rows as $permission) {
        expect($permission->applicable_scopes)->toBe($permission->code === 'alumno.leer' ? ['todos', 'unidad_familiar'] : ['todos'], $permission->code)
            ->and($permission->is_special_category)->toBeFalse()
            ->and($permission->retired_at)->toBeNull()
            ->and($permission->resource)->toBe('alumno');
    }

    expect(Permission::query()->where('code', 'like', 'matricula.%')->orWhere('code', 'like', 'alumno.exportar')->orWhere('code', 'like', 'alumno.importar')->count())->toBe(0);
});

test('OPEN-ALUM-28 ADR-045: el descriptor de alum es fase 1, no esencial y depende de curso, acad y fam_unit', function (): void {
    test()->artisan('platform:sync-registry')->assertSuccessful();

    $descriptor = (new AlumServiceProvider(app()))->moduleDescriptor();

    expect($descriptor)->toBe(['code' => 'alum', 'name_key' => 'modules.alum', 'phase' => '1', 'depends_on' => ['curso', 'acad', 'fam_unit'], 'essential' => false])
        ->and(Module::query()->findOrFail('alum')->retired_at)->toBeNull();

    $catalogued = app(ModuleCatalog::class)->find('alum');

    expect($catalogued)->not->toBeNull()
        ->and($catalogued->dependsOn)->toBe(['curso', 'acad', 'fam_unit'])
        ->and($catalogued->essential)->toBeFalse();
});

test('CA-ALUM-064 CA-PERM-134 AR-12: el nombre del módulo y la etiqueta del recurso existen en es, en, de y fr, y cada permiso declara su resource_label_key', function (): void {
    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        foreach (['modules.alum', 'alum.permissions.resources.alumno'] as $key) {
            expect(trans($key, [], $locale))->not->toBe($key, "{$key} sin traducir en {$locale}")->and(trans($key, [], $locale))->not->toBe('');
        }
    }

    $declared = (new AlumServiceProvider(app()))->declaredPermissions();

    expect($declared)->toHaveCount(4);

    foreach ($declared as $permission) {
        expect($permission['resource_label_key'])->toBe("alum.permissions.resources.{$permission['resource']}");
    }
});

test('CA-ALUM-014a RN-ALUM-65 permisos.md §4: tenant:provision-defaults siembra alumno.* con todos solo en administrador, secretaría, dirección y administrativo, y alumno.leer con unidad_familiar en tutor_legal; ningún otro rol, docente incluido', function (): void {
    [$tenant] = provisionCoreTenant('alum-seed');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $crud = ['alumno.actualizar:todos', 'alumno.crear:todos', 'alumno.eliminar:todos', 'alumno.leer:todos'];

        expect(alumGrantsOf('administrador_centro'))->toBe($crud)
            ->and(alumGrantsOf('secretaria'))->toBe($crud)
            ->and(alumGrantsOf('direccion'))->toBe(['alumno.leer:todos'])
            ->and(alumGrantsOf('administrativo'))->toBe(['alumno.leer:todos'])
            ->and(alumGrantsOf('tutor_legal'))->toBe(['alumno.leer:unidad_familiar']);

        $others = Role::where('is_system', true)
            ->whereNotIn('code', ['administrador_centro', 'secretaria', 'direccion', 'administrativo', 'tutor_legal'])
            ->pluck('code');

        expect($others)->not->toBeEmpty()->and($others->contains('docente'))->toBeTrue();

        foreach ($others as $code) {
            expect(alumGrantsOf($code))->toBe([], $code);
        }
    });

    expect(array_keys(ProvisionTenantDefaults::ALUM_PERMISSION_GRANTS))->toBe(['administrador_centro', 'secretaria', 'direccion', 'administrativo'])
        ->and(array_keys(ProvisionTenantDefaults::ALUM_PERMISSION_UNIDAD_FAMILIAR_GRANTS))->toBe(['tutor_legal']);
});

test('CA-ALUM-014a permisos.md §4: alum:grant-permissions repone la siembra en un centro existente, es idempotente y no toca un rol personalizado', function (): void {
    test()->artisan('platform:sync-registry')->assertSuccessful();
    [$tenant] = provisionCoreTenant('alum-cmd');

    $seeded = app(TenantContext::class)->runFor($tenant->id, fn (): int => PermissionRole::query()->where('permission_code', 'like', 'alumno.%')->count());
    expect($seeded)->toBe(4 + 4 + 1 + 1 + 1);

    $custom = app(TenantContext::class)->runFor($tenant->id, fn (): int => (int) Role::forceCreate([
        'tenant_id' => $tenant->id, 'code' => 'rol_propio', 'name' => 'Rol propio', 'is_system' => false,
    ])->id);

    alumRevokeAll($tenant);
    expect(app(TenantContext::class)->runFor($tenant->id, fn (): int => PermissionRole::query()->where('permission_code', 'like', 'alumno.%')->count()))->toBe(0);

    test()->artisan('alum:grant-permissions')->assertSuccessful();
    $afterFirst = app(TenantContext::class)->runFor($tenant->id, fn (): int => PermissionRole::query()->where('permission_code', 'like', 'alumno.%')->count());

    test()->artisan('alum:grant-permissions')->assertSuccessful();
    $afterSecond = app(TenantContext::class)->runFor($tenant->id, fn (): int => PermissionRole::query()->where('permission_code', 'like', 'alumno.%')->count());

    expect($afterFirst)->toBe($seeded)->and($afterSecond)->toBe($seeded);

    app(TenantContext::class)->runFor($tenant->id, function () use ($custom): void {
        expect(PermissionRole::query()->where('role_id', $custom)->count())->toBe(0)
            ->and(alumGrantsOf('tutor_legal'))->toBe(['alumno.leer:unidad_familiar'])
            ->and(alumGrantsOf('docente'))->toBe([]);
    });
});

test('issue #387 permisos.md §4: alum:grant-permissions no concede nada y falla si algún permiso está retirado', function (): void {
    test()->artisan('platform:sync-registry')->assertSuccessful();
    [$tenant] = provisionCoreTenant('alum-cmd-ret');
    alumRevokeAll($tenant);

    $owner = DB::connection('pgsql_owner');
    $owner->table('permissions')->where('code', 'alumno.leer')->update(['retired_at' => now()]);

    try {
        test()->artisan('alum:grant-permissions')
            ->expectsOutputToContain('permisos retirados en el registro (retired_at informado): alumno.leer')
            ->assertFailed();

        expect(app(TenantContext::class)->runFor($tenant->id, fn (): int => PermissionRole::query()->where('permission_code', 'like', 'alumno.%')->count()))->toBe(0);
    } finally {
        $owner->table('permissions')->where('code', 'alumno.leer')->update(['retired_at' => null]);
    }
});
