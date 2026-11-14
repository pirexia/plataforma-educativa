<?php

use App\Models\Module;
use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Modules\Acad\Infrastructure\AcadServiceProvider;
use App\Modules\Core\Application\ProvisionTenantDefaults;
use App\Modules\Core\Domain\ModuleCatalog;
use App\Modules\Core\Infrastructure\Console\GrantStructurePermissionsCommand;
use App\Support\Authorization\Scope;
use App\Support\Authorization\ScopeResolverRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// REQ-ACAD-001 (1.11-b y 1.11d-a), permisos.md §2-§4.1, CA-ACAD-035, CA-ACAD-036,
// CA-ACAD-037, CA-ACAD-096, CA-ACAD-097, INV-002, INV-015. Catálogo de permisos y descriptor del
// módulo materializados por `platform:sync-registry`; siembra por
// `tenant:provision-defaults` y por `acad:grant-structure-permissions`.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

const ACAD_PERMISSION_CODES = [
    'estructura_academica.leer', 'estructura_academica.crear',
    'estructura_academica.actualizar', 'estructura_academica.eliminar',
];

const ACAD_SCHEDULE_PERMISSION_CODES = [
    'asignacion_docente.leer', 'asignacion_docente.crear', 'asignacion_docente.actualizar', 'asignacion_docente.eliminar',
    'espacios.leer', 'espacios.crear', 'espacios.actualizar', 'espacios.eliminar',
    'horarios.leer', 'horarios.crear', 'horarios.actualizar', 'horarios.eliminar',
];

const ACAD_CONFIG_PERMISSION_CODES = [
    'configuracion_academica.leer', 'configuracion_academica.crear',
    'configuracion_academica.actualizar', 'configuracion_academica.eliminar',
];

/** @return list<string> */
function acadGrantsOf(string $roleCode): array
{
    $role = Role::where('code', $roleCode)->firstOrFail();

    return PermissionRole::where('role_id', $role->id)
        ->where('permission_code', 'like', 'estructura_academica.%')
        ->pluck('permission_code')->sort()->values()->all();
}

/** @return list<string> */
function acadConfigGrantsOf(string $roleCode): array
{
    $role = Role::where('code', $roleCode)->firstOrFail();

    return PermissionRole::where('role_id', $role->id)
        ->where('permission_code', 'like', 'configuracion_academica.%')
        ->pluck('permission_code')->sort()->values()->all();
}

test('CA-ACAD-035 CA-ACAD-096 CA-ACAD-133 ADR-034 §5: tras platform:sync-registry, permissions contiene exactamente los veintitrés códigos de acad (cuatro de cada uno de los cinco recursos de 1.11 y 1.12-a y tres de reservas, 1.12-b) con ámbito todos salvo reservas.eliminar (todos y propios)', function (): void {
    test()->artisan('platform:sync-registry')->assertSuccessful();

    $rows = Permission::query()->where('module_code', 'acad')->orderBy('code')->get();

    expect($rows->pluck('code')->all())->toBe([
        'asignacion_docente.actualizar', 'asignacion_docente.crear',
        'asignacion_docente.eliminar', 'asignacion_docente.leer',
        'configuracion_academica.actualizar', 'configuracion_academica.crear',
        'configuracion_academica.eliminar', 'configuracion_academica.leer',
        'espacios.actualizar', 'espacios.crear', 'espacios.eliminar', 'espacios.leer',
        'estructura_academica.actualizar', 'estructura_academica.crear',
        'estructura_academica.eliminar', 'estructura_academica.leer',
        'horarios.actualizar', 'horarios.crear', 'horarios.eliminar', 'horarios.leer',
        'reservas.crear', 'reservas.eliminar', 'reservas.leer',
    ]);

    foreach ($rows as $permission) {
        expect($permission->applicable_scopes)->toBe($permission->code === 'reservas.eliminar' ? ['todos', 'propios'] : ['todos'])
            ->and($permission->is_special_category)->toBeFalse()
            ->and($permission->retired_at)->toBeNull()
            ->and($permission->resource)->toBe(explode('.', $permission->code)[0]);
    }

    // exportar/importar no se declaran (permisos.md §2.1, §2.2).
    expect(Permission::query()->whereIn('code', [
        'estructura_academica.exportar', 'estructura_academica.importar',
        'configuracion_academica.exportar', 'configuracion_academica.importar',
        'horarios.exportar', 'horarios.importar', 'asignacion_docente.exportar', 'espacios.exportar', 'reservas.actualizar',
    ])->count())->toBe(0);
});

test('CA-ACAD-035 ADR-045: el descriptor del módulo es acad, depende de curso y no es esencial', function (): void {
    test()->artisan('platform:sync-registry')->assertSuccessful();

    $descriptor = (new AcadServiceProvider(app()))->moduleDescriptor();
    $module = Module::query()->findOrFail('acad');

    expect($descriptor)->toBe(['code' => 'acad', 'name_key' => 'modules.acad', 'phase' => '1', 'depends_on' => ['curso'], 'essential' => false])
        ->and($module->name_key)->toBe('modules.acad')
        ->and($module->retired_at)->toBeNull();

    // `depends_on` y `essential` no se materializan en `modules`: los resuelve el catálogo de descriptores (ADR-045 §4.5).
    $catalogued = app(ModuleCatalog::class)->find('acad');

    expect($catalogued)->not->toBeNull()
        ->and($catalogued->dependsOn)->toBe(['curso'])
        ->and($catalogued->essential)->toBeFalse()
        ->and(app(ModuleCatalog::class)->dependenciesOf('acad'))->toBe([]);
});

test('CA-ACAD-035 CA-ACAD-096 CA-PERM-134: la etiqueta de cada recurso y las del módulo, stage y regime existen en los cuatro idiomas', function (): void {
    $keys = [
        'acad.permissions.resources.estructura_academica', 'acad.permissions.resources.configuracion_academica', 'acad.permissions.resources.asignacion_docente', 'acad.permissions.resources.espacios', 'acad.permissions.resources.horarios', 'acad.permissions.resources.reservas', 'modules.acad',
        ...array_map(static fn (string $s): string => "acad.stage.{$s}", ['infantil', 'primaria', 'eso', 'bachillerato', 'fp', 'universidad']),
        ...array_map(static fn (string $r): string => "acad.regime.{$r}", ['publico', 'privado', 'concertado']),
    ];

    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        foreach ($keys as $key) {
            expect(trans($key, [], $locale))->not->toBe($key, "{$key} sin traducir en {$locale}")->and(trans($key, [], $locale))->not->toBe('');
        }
    }

    // El catálogo declara esa etiqueta en cada entrada.
    $declaredPermissions = (new AcadServiceProvider(app()))->declaredPermissions();

    expect($declaredPermissions)->toHaveCount(23);

    foreach ($declaredPermissions as $declared) {
        expect($declared['resource_label_key'])->toBe("acad.permissions.resources.{$declared['resource']}")
            ->and($declared['applicable_scopes'])->toBe($declared['code'] === 'reservas.eliminar' ? ['todos', 'propios'] : ['todos'])
            ->and($declared['is_special_category'])->toBeFalse();
    }
});

test('CA-ACAD-037 ADR-063 §5.1 permisos.md §3.1 §10.2: acad solo registra el resolutor (propios, reservas) y no admite grupo, clase ni departamento en ningún recurso', function (): void {
    $registry = app(ScopeResolverRegistry::class);

    foreach (['estructura_academica', 'configuracion_academica', 'asignacion_docente', 'espacios', 'horarios', 'reservas'] as $resource) {
        foreach ([Scope::Grupo, Scope::Clase, Scope::Departamento] as $scope) {
            expect($registry->has($resource, $scope))->toBeFalse("{$resource} {$scope->value}");
        }
    }

    // El único resolutor de acad (1.12-b, OPEN-ACAD-42): propios sobre reservas.
    expect($registry->has('reservas', Scope::Propios))->toBeTrue()
        ->and($registry->has('estructura_academica', Scope::Propios))->toBeFalse()
        ->and($registry->has('horarios', Scope::Propios))->toBeFalse();

    // Solo el proveedor de servicios del módulo toca el registro de resolutores.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Acad')));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && ! str_ends_with($file->getPathname(), 'Infrastructure/AcadServiceProvider.php')) {
            expect(file_get_contents($file->getPathname()))->not->toContain('ScopeResolverRegistry', $file->getPathname());
        }
    }
});

test('CA-ACAD-037 CA-PERM-004: conceder un permiso de acad con ámbito grupo o clase responde 422 y no guarda el rol', function (): void {
    [$tenant, $admin] = provisionCoreTenant('acad-037');
    contractModule($tenant, 'acad');

    foreach (['grupo', 'clase'] as $i => $scope) {
        resetSessionState();

        test()->actingAs($admin)
            ->postJson(coreApiUrl($tenant->slug, '/roles'), [
                'code' => "rol_acad_037_{$i}",
                'name' => "Rol {$i}",
                'permissions' => [['code' => 'estructura_academica.leer', 'effect' => 'allow', 'scope' => $scope]],
            ])
            ->assertStatus(422);

        // CA-ACAD-097: lo mismo con configuracion_academica.leer (ADR-063 §5.1).
        resetSessionState();

        test()->actingAs($admin)
            ->postJson(coreApiUrl($tenant->slug, '/roles'), [
                'code' => "rol_acad_097_{$i}",
                'name' => "Rol cfg {$i}",
                'permissions' => [['code' => 'configuracion_academica.leer', 'effect' => 'allow', 'scope' => $scope]],
            ])
            ->assertStatus(422);

        app(TenantContext::class)->runFor($tenant->id, function () use ($i): void {
            expect(Role::where('code', "rol_acad_037_{$i}")->exists())->toBeFalse()
                ->and(Role::where('code', "rol_acad_097_{$i}")->exists())->toBeFalse();
        });
    }
});

test('CA-ACAD-036 CA-ACAD-097 permisos.md §4 y §4.1 OPEN-ACAD-09 = X, OPEN-ACAD-28 = X + 1: tenant:provision-defaults siembra los ocho al administrador y solo leer de cada recurso a direccion, secretaria, docente, tutor_grupo y orientador', function (): void {
    [$tenant] = provisionCoreTenant('acad-036a');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(acadGrantsOf('administrador_centro'))->toBe(ACAD_PERMISSION_CODES_SORTED())
            ->and(acadConfigGrantsOf('administrador_centro'))->toBe(ACAD_CONFIG_PERMISSION_CODES_SORTED());

        foreach (['direccion', 'secretaria', 'docente', 'tutor_grupo', 'orientador'] as $code) {
            expect(acadGrantsOf($code))->toBe(['estructura_academica.leer'], $code)
                ->and(acadConfigGrantsOf($code))->toBe(['configuracion_academica.leer'], $code);
        }

        // Los demás roles predefinidos, nada (RPERM-011).
        $others = Role::where('is_system', true)
            ->whereNotIn('code', ['administrador_centro', 'direccion', 'secretaria', 'docente', 'tutor_grupo', 'orientador'])
            ->pluck('code');

        expect($others)->not->toBeEmpty();

        foreach ($others as $code) {
            expect(acadGrantsOf($code))->toBe([], $code)
                ->and(acadConfigGrantsOf($code))->toBe([], $code);
        }

        // Todas con ámbito `todos`.
        expect(PermissionRole::where(fn ($q) => $q->where('permission_code', 'like', 'estructura_academica.%')->orWhere('permission_code', 'like', 'configuracion_academica.%'))->where('scope', '<>', 'todos')->count())->toBe(0);
    });

    // La lista del aprovisionamiento es la única fuente del comando (una sola lista por los dos caminos).
    expect(array_keys(ProvisionTenantDefaults::STRUCTURE_PERMISSION_GRANTS))->toBe(['administrador_centro', 'direccion', 'secretaria', 'docente', 'tutor_grupo', 'orientador']);
});

function ACAD_PERMISSION_CODES_SORTED(): array
{
    $codes = ACAD_PERMISSION_CODES;
    sort($codes);

    return $codes;
}

function ACAD_CONFIG_PERMISSION_CODES_SORTED(): array
{
    $codes = ACAD_CONFIG_PERMISSION_CODES;
    sort($codes);

    return $codes;
}

/** Retira las concesiones de `acad` de un centro (simula un centro anterior al despliegue). */
function acadRevokeAll(object $tenant): void
{
    app(TenantContext::class)->runFor($tenant->id, function (): void {
        PermissionRole::query()->where(fn ($q) => $q->where('permission_code', 'like', 'estructura_academica.%')->orWhere('permission_code', 'like', 'configuracion_academica.%'))->get()->each->delete();
    });
}

function acadGrantCount(object $tenant): int
{
    return app(TenantContext::class)->runFor($tenant->id, fn () => PermissionRole::query()->where(fn ($q) => $q->where('permission_code', 'like', 'estructura_academica.%')->orWhere('permission_code', 'like', 'configuracion_academica.%'))->count());
}

test('CA-ACAD-036 operacion.md §3: acad:grant-structure-permissions concede lo que falta a un centro existente, es idempotente y no toca roles personalizados', function (): void {
    [$tenant, $admin] = provisionCoreTenant('acad-036b');

    // Un rol personalizado con un permiso ajeno: el comando no debe tocarlo.
    resetSessionState();
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'rol_custom_036', 'name' => 'Custom', 'permissions' => [['code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'todos']],
    ])->assertCreated();

    acadRevokeAll($tenant);
    expect(acadGrantCount($tenant))->toBe(0);

    // 8 (administrador_centro) + 5 roles con dos `leer` cada uno.
    test()->artisan('acad:grant-structure-permissions')->assertSuccessful();
    expect(acadGrantCount($tenant))->toBe(18);

    test()->artisan('acad:grant-structure-permissions')->assertSuccessful();
    expect(acadGrantCount($tenant))->toBe(18);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $custom = Role::where('code', 'rol_custom_036')->firstOrFail();

        expect(PermissionRole::where('role_id', $custom->id)->pluck('permission_code')->all())->toBe(['usuario.leer'])
            ->and(acadGrantsOf('administrador_centro'))->toBe(ACAD_PERMISSION_CODES_SORTED())
            ->and(acadConfigGrantsOf('administrador_centro'))->toBe(ACAD_CONFIG_PERMISSION_CODES_SORTED())
            ->and(acadGrantsOf('docente'))->toBe(['estructura_academica.leer'])
            ->and(acadConfigGrantsOf('docente'))->toBe(['configuracion_academica.leer']);
    });
});

test('CA-ACAD-036 issue #387 B-5: el comando no concede nada y lo dice si algún permiso está retirado (retired_at)', function (): void {
    [$tenant] = provisionCoreTenant('acad-036c');
    acadRevokeAll($tenant);

    // `permissions` solo la escribe el propietario (SyncModuleRegistry); se retira y se restaura.
    $owner = DB::connection('pgsql_owner');
    $owner->table('permissions')->where('code', 'estructura_academica.eliminar')->update(['retired_at' => now()]);

    try {
        test()->artisan('acad:grant-structure-permissions')
            ->expectsOutputToContain('permisos retirados en el registro (retired_at informado): estructura_academica.eliminar')
            ->assertFailed();

        expect(acadGrantCount($tenant))->toBe(0);
    } finally {
        $owner->table('permissions')->where('code', 'estructura_academica.eliminar')->update(['retired_at' => null]);
    }

    // Caso normal sin cambios: con el permiso vigente concede.
    test()->artisan('acad:grant-structure-permissions')->assertSuccessful();
    expect(acadGrantCount($tenant))->toBe(18);
});

test('CA-ACAD-097 issue #387 B-5: el comando tampoco concede nada si está retirado un permiso de configuracion_academica', function (): void {
    [$tenant] = provisionCoreTenant('acad-097c');
    acadRevokeAll($tenant);

    $owner = DB::connection('pgsql_owner');
    $owner->table('permissions')->where('code', 'configuracion_academica.crear')->update(['retired_at' => now()]);

    try {
        test()->artisan('acad:grant-structure-permissions')
            ->expectsOutputToContain('permisos retirados en el registro (retired_at informado): configuracion_academica.crear')
            ->assertFailed();

        expect(acadGrantCount($tenant))->toBe(0);
    } finally {
        $owner->table('permissions')->where('code', 'configuracion_academica.crear')->update(['retired_at' => null]);
    }
});

test('CA-ACAD-036 #385: el comando se niega con un mensaje claro si el registro de permisos no está sincronizado, y no concede nada', function (): void {
    [$tenant] = provisionCoreTenant('acad-036d');
    acadRevokeAll($tenant);

    // Registro sin sincronizar: el comando de producción con su comprobación previa, catálogo vacío.
    Artisan::registerCommand(new class extends GrantStructurePermissionsCommand
    {
        protected function registeredPermissionCodes(array $codes): array
        {
            return [];
        }
    });

    test()->artisan('acad:grant-structure-permissions')
        ->expectsOutputToContain('platform:sync-registry')
        ->assertFailed();

    expect(acadGrantCount($tenant))->toBe(0);
});
