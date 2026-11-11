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

// REQ-ALUM-001 (1.15-a T1), REQ-ALUM-002 (1.15-b T1), docs/modulos/REQ-ALUM/permisos.md §2-§4, CA-ALUM-014a, CA-ALUM-014b, CA-ALUM-064, INV-002, INV-015.
// Catálogo y descriptor materializados por `platform:sync-registry`; siembra por `tenant:provision-defaults` y por
// `alum:grant-permissions`. Sin endpoints hasta el T2 de 1.15-b: aquí solo se fijan los diez códigos declarados y su siembra.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

const ALUM_CODES = [
    'alumno.actualizar', 'alumno.crear', 'alumno.eliminar', 'alumno.leer',
    'matricula.actualizar', 'matricula.aprobar', 'matricula.crear', 'matricula.eliminar', 'matricula.exportar', 'matricula.leer',
];

/** Cuenta las concesiones de alumnado (`alumno.*` y `matricula.*`) del centro en curso. */
function alumGrantCount(): int
{
    return PermissionRole::query()->where(fn ($q) => $q->where('permission_code', 'like', 'alumno.%')->orWhere('permission_code', 'like', 'matricula.%'))->count();
}

/** @return list<string> permisos de alumnado de un rol, ordenados, con su ámbito (`código:ámbito`) */
function alumGrantsOf(string $roleCode): array
{
    $role = Role::where('code', $roleCode)->firstOrFail();

    return PermissionRole::where('role_id', $role->id)
        ->where(fn ($q) => $q->where('permission_code', 'like', 'alumno.%')->orWhere('permission_code', 'like', 'matricula.%'))
        ->get()
        ->map(static fn (PermissionRole $p): string => "{$p->permission_code}:{$p->scope}")
        ->sort()->values()->all();
}

function alumRevokeAll(object $tenant): void
{
    app(TenantContext::class)->runFor($tenant->id, function (): void {
        PermissionRole::query()->where(fn ($q) => $q->where('permission_code', 'like', 'alumno.%')->orWhere('permission_code', 'like', 'matricula.%'))->get()->each->delete();
    });
}

test('REQ-ALUM-001 permisos.md §2: tras platform:sync-registry, permissions contiene exactamente los diez códigos de alumno y matricula, con leer admitiendo todos, grupo y unidad_familiar, y sin alumno.exportar ni importar', function (): void {
    test()->artisan('platform:sync-registry')->assertSuccessful();

    $rows = Permission::query()->where('module_code', 'alum')->orderBy('code')->get();

    expect($rows->pluck('code')->all())->toBe(ALUM_CODES);

    foreach ($rows as $permission) {
        expect($permission->applicable_scopes)->toBe(str_ends_with($permission->code, '.leer') ? ['todos', 'grupo', 'unidad_familiar'] : ['todos'], $permission->code)
            ->and($permission->is_special_category)->toBeFalse()
            ->and($permission->retired_at)->toBeNull()
            ->and($permission->resource)->toBe(explode('.', $permission->code)[0]);
    }

    expect(Permission::query()->whereIn('code', ['alumno.exportar', 'alumno.importar', 'matricula.importar'])->count())->toBe(0);
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
        foreach (['modules.alum', 'alum.permissions.resources.alumno', 'alum.permissions.resources.matricula'] as $key) {
            expect(trans($key, [], $locale))->not->toBe($key, "{$key} sin traducir en {$locale}")->and(trans($key, [], $locale))->not->toBe('');
        }
    }

    $declared = (new AlumServiceProvider(app()))->declaredPermissions();

    expect($declared)->toHaveCount(10);

    foreach ($declared as $permission) {
        expect($permission['resource_label_key'])->toBe("alum.permissions.resources.{$permission['resource']}");
    }
});

test('CA-ALUM-014b CA-ALUM-014a RN-ALUM-65 permisos.md §4: tenant:provision-defaults siembra alumno.* y matricula.* con todos solo en los roles de gestión, grupo en docente y tutor_grupo, unidad_familiar en tutor_legal; ningún otro rol', function (): void {
    [$tenant] = provisionCoreTenant('alum-seed');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $alumno = ['alumno.actualizar:todos', 'alumno.crear:todos', 'alumno.eliminar:todos', 'alumno.leer:todos'];
        $matriculaSinAprobar = ['matricula.actualizar:todos', 'matricula.crear:todos', 'matricula.eliminar:todos', 'matricula.exportar:todos', 'matricula.leer:todos'];

        expect(alumGrantsOf('administrador_centro'))->toBe([...$alumno, 'matricula.actualizar:todos', 'matricula.aprobar:todos', 'matricula.crear:todos', 'matricula.eliminar:todos', 'matricula.exportar:todos', 'matricula.leer:todos'])
            ->and(alumGrantsOf('secretaria'))->toBe([...$alumno, ...$matriculaSinAprobar])
            ->and(alumGrantsOf('direccion'))->toBe(['alumno.leer:todos', 'matricula.aprobar:todos', 'matricula.exportar:todos', 'matricula.leer:todos'])
            ->and(alumGrantsOf('administrativo'))->toBe(['alumno.leer:todos', 'matricula.leer:todos'])
            ->and(alumGrantsOf('docente'))->toBe(['alumno.leer:grupo', 'matricula.leer:grupo'])
            ->and(alumGrantsOf('tutor_grupo'))->toBe(['alumno.leer:grupo', 'matricula.leer:grupo'])
            ->and(alumGrantsOf('tutor_legal'))->toBe(['alumno.leer:unidad_familiar', 'matricula.leer:unidad_familiar']);

        $others = Role::where('is_system', true)
            ->whereNotIn('code', ['administrador_centro', 'secretaria', 'direccion', 'administrativo', 'docente', 'tutor_grupo', 'tutor_legal'])
            ->pluck('code');

        expect($others)->not->toBeEmpty();

        foreach ($others as $code) {
            expect(alumGrantsOf($code))->toBe([], $code);
        }
    });

    // Ningún rol recibe `todos` en `docente` ni `tutor_grupo` (`ADR-063 §5.4`) y `secretaria` no aprueba.
    expect(array_keys(ProvisionTenantDefaults::ALUM_PERMISSION_GRANTS))->toBe(['administrador_centro', 'secretaria', 'direccion', 'administrativo'])
        ->and(array_keys(ProvisionTenantDefaults::ALUM_PERMISSION_UNIDAD_FAMILIAR_GRANTS))->toBe(['tutor_legal'])
        ->and(array_keys(ProvisionTenantDefaults::ALUM_PERMISSION_GRUPO_GRANTS))->toBe(['docente', 'tutor_grupo'])
        ->and(ProvisionTenantDefaults::ALUM_PERMISSION_GRANTS['secretaria'])->not->toContain('matricula.aprobar');
});

test('CA-ALUM-014a permisos.md §4: alum:grant-permissions repone la siembra en un centro existente, es idempotente y no toca un rol personalizado', function (): void {
    test()->artisan('platform:sync-registry')->assertSuccessful();
    [$tenant] = provisionCoreTenant('alum-cmd');

    $seeded = app(TenantContext::class)->runFor($tenant->id, fn (): int => alumGrantCount());
    expect($seeded)->toBe(10 + 9 + 4 + 2 + 2 + 2 + 2);

    $custom = app(TenantContext::class)->runFor($tenant->id, fn (): int => (int) Role::forceCreate([
        'tenant_id' => $tenant->id, 'code' => 'rol_propio', 'name' => 'Rol propio', 'is_system' => false,
    ])->id);

    alumRevokeAll($tenant);
    expect(app(TenantContext::class)->runFor($tenant->id, fn (): int => alumGrantCount()))->toBe(0);

    test()->artisan('alum:grant-permissions')->assertSuccessful();
    $afterFirst = app(TenantContext::class)->runFor($tenant->id, fn (): int => alumGrantCount());

    test()->artisan('alum:grant-permissions')->assertSuccessful();
    $afterSecond = app(TenantContext::class)->runFor($tenant->id, fn (): int => alumGrantCount());

    expect($afterFirst)->toBe($seeded)->and($afterSecond)->toBe($seeded);

    app(TenantContext::class)->runFor($tenant->id, function () use ($custom): void {
        expect(PermissionRole::query()->where('role_id', $custom)->count())->toBe(0)
            ->and(alumGrantsOf('tutor_legal'))->toBe(['alumno.leer:unidad_familiar', 'matricula.leer:unidad_familiar'])
            ->and(alumGrantsOf('docente'))->toBe(['alumno.leer:grupo', 'matricula.leer:grupo']);
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

        expect(app(TenantContext::class)->runFor($tenant->id, fn (): int => alumGrantCount()))->toBe(0);
    } finally {
        $owner->table('permissions')->where('code', 'alumno.leer')->update(['retired_at' => null]);
    }
});
