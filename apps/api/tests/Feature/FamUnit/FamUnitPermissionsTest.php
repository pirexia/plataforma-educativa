<?php

use App\Models\Module;
use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Application\ProvisionTenantDefaults;
use App\Modules\Core\Domain\ModuleCatalog;
use App\Modules\Core\Infrastructure\Console\GrantFamUnitPermissionsCommand;
use App\Modules\FamUnit\Infrastructure\FamUnitServiceProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT (1.14-a y 1.14-b), docs/modulos/REQ-FAM-UNIT/permisos.md §2-§4, operacion.md §3, CA-FAM-UNIT-081,
// CA-FAM-UNIT-082, CA-FAM-UNIT-106, INV-002, INV-015. Catálogo y descriptor materializados por `platform:sync-registry`; siembra por
// `tenant:provision-defaults` y por `fam_unit:grant-permissions`.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

const FAM_UNIT_CODES = [
    'aceptacion_condiciones.leer',
    'consentimiento_imagen.actualizar', 'consentimiento_imagen.exportar', 'consentimiento_imagen.leer',
    'familia.actualizar', 'familia.crear', 'familia.eliminar', 'familia.leer',
    'revocacion_tutor.crear', 'revocacion_tutor.eliminar', 'revocacion_tutor.leer',
    'texto_legal.actualizar', 'texto_legal.crear', 'texto_legal.eliminar', 'texto_legal.leer', 'texto_legal.publicar',
    'vinculo_tutor.actualizar', 'vinculo_tutor.crear', 'vinculo_tutor.eliminar', 'vinculo_tutor.leer',
];

/** Condición SQL de los permisos que siembra este módulo (por prefijo de recurso). */
function famCodeFilter(): Closure
{
    return fn ($q) => $q->where('permission_code', 'like', 'familia.%')->orWhere('permission_code', 'like', 'vinculo_tutor.%')
        ->orWhere('permission_code', 'like', 'revocacion_tutor.%')->orWhere('permission_code', 'like', 'consentimiento_imagen.%')
        ->orWhere('permission_code', 'like', 'texto_legal.%')->orWhere('permission_code', 'like', 'aceptacion_condiciones.%');
}

/** @return list<string> permisos de familias de un rol, ordenados, con su ámbito (`código:ámbito`) */
function famGrantsOf(string $roleCode): array
{
    $role = Role::where('code', $roleCode)->firstOrFail();

    return PermissionRole::where('role_id', $role->id)
        ->where(famCodeFilter())
        ->get()
        ->map(static fn (PermissionRole $p): string => "{$p->permission_code}:{$p->scope}")
        ->sort()->values()->all();
}

function famGrantCount(object $tenant): int
{
    return app(TenantContext::class)->runFor($tenant->id, fn (): int => PermissionRole::query()
        ->where(famCodeFilter())
        ->count());
}

function famRevokeAll(object $tenant): void
{
    app(TenantContext::class)->runFor($tenant->id, function (): void {
        PermissionRole::query()
            ->where(famCodeFilter())
            ->get()->each->delete();
    });
}

test('permisos.md §2 ADR-034 §5: tras platform:sync-registry, permissions contiene exactamente los veinte códigos de 1.14-a, 1.14-b y 1.14-c con el ámbito de permisos.md, sin los de custodia', function (): void {
    test()->artisan('platform:sync-registry')->assertSuccessful();

    $rows = Permission::query()->where('module_code', 'fam_unit')->orderBy('code')->get();

    expect($rows->pluck('code')->all())->toBe(FAM_UNIT_CODES);

    foreach ($rows as $permission) {
        $expected = in_array($permission->code, ['familia.leer', 'vinculo_tutor.leer', 'consentimiento_imagen.leer', 'consentimiento_imagen.actualizar'], true) ? ['todos', 'unidad_familiar'] : ['todos'];

        expect($permission->applicable_scopes)->toBe($expected, $permission->code)
            ->and($permission->is_special_category)->toBeFalse()
            ->and($permission->retired_at)->toBeNull()
            ->and($permission->resource)->toBe(explode('.', $permission->code)[0]);
    }

    // Sin endpoint, un permiso invita a concederlo creyendo que hace algo (permisos.md §2.1, funcional.md §15 D-6): ni
    // custodia (condicionada), ni el antiguo recurso `condiciones_uso`
    // (renombrado `texto_legal`, OPEN-066-03), ni acciones que no existen.
    expect(Permission::query()->where(fn ($q) => $q
        ->where('code', 'like', 'restriccion_custodia.%')->orWhere('code', 'like', 'condiciones_uso.%')->orWhere('code', 'like', 'aceptacion_condiciones.crear')->orWhere('code', 'like', 'aceptacion_condiciones.actualizar')->orWhere('code', 'like', 'aceptacion_condiciones.eliminar')
        ->orWhereIn('code', ['familia.exportar', 'familia.importar', 'vinculo_tutor.exportar', 'revocacion_tutor.actualizar', 'consentimiento_imagen.crear', 'consentimiento_imagen.eliminar', 'texto_legal.exportar']))->count())->toBe(0);
});

test('ADR-045 OPEN-FAM-UNIT-14: el descriptor del módulo es fam_unit, fase 1, sin dependencias y no esencial', function (): void {
    test()->artisan('platform:sync-registry')->assertSuccessful();

    $descriptor = (new FamUnitServiceProvider(app()))->moduleDescriptor();
    $module = Module::query()->findOrFail('fam_unit');

    expect($descriptor)->toBe(['code' => 'fam_unit', 'name_key' => 'modules.fam_unit', 'phase' => '1', 'depends_on' => [], 'essential' => false])
        ->and($module->name_key)->toBe('modules.fam_unit')
        ->and($module->retired_at)->toBeNull();

    $catalogued = app(ModuleCatalog::class)->find('fam_unit');

    expect($catalogued)->not->toBeNull()
        ->and($catalogued->dependsOn)->toBe([])
        ->and($catalogued->essential)->toBeFalse();
});

test('CA-FAM-UNIT-082 CA-PERM-134 AR-12: el nombre del módulo, la etiqueta de cada recurso y los mensajes de error existen en es, en, de y fr, y cada permiso declara su resource_label_key', function (): void {
    $keys = [
        'modules.fam_unit',
        ...array_map(static fn (string $r): string => "fam_unit.permissions.resources.{$r}", ['familia', 'vinculo_tutor', 'revocacion_tutor', 'consentimiento_imagen', 'texto_legal']),
        'fam_unit.family_unit.code_taken', 'fam_unit.family_unit.code_invalid', 'fam_unit.family_unit.principal_not_member', 'fam_unit.family_unit.has_members',
        'fam_unit.guardian.already_exists', 'fam_unit.guardian.not_editable', 'fam_unit.guardian.user_not_eligible', 'fam_unit.student.already_in_unit',
        'fam_unit.link.already_exists', 'fam_unit.link.self_link', 'fam_unit.link.emergency_contact_taken', 'fam_unit.link.invalid_transition',
        'fam_unit.link.not_editable', 'fam_unit.link.emergency_contact_revoked', 'fam_unit.link.revoked_not_deletable', 'fam_unit.errors.revocation_rate_limited',
        // 1.14-b (funcional.md §17 tarea 4): las claves de error nuevas del consentimiento de imagen y los textos legales.
        'fam_unit.image_consent.not_legal_guardian', 'fam_unit.image_consent.guardian_required', 'fam_unit.image_consent.guardian_not_linked',
        'fam_unit.image_consent.text_not_published', 'fam_unit.image_consent.text_version_invalid',
        'fam_unit.terms.kind_not_editable', 'fam_unit.terms.kind_invalid', 'fam_unit.terms.requires_reacceptance_not_allowed',
        'fam_unit.terms.not_draft', 'fam_unit.terms.default_locale_missing', 'fam_unit.export.q_not_allowed',
        // 1.14-c (ADR-067, api.md §7): aceptación de las condiciones y su etiqueta de recurso.
        'fam_unit.terms.version_not_current', 'fam_unit.validation.terms_version_not_found', 'fam_unit.permissions.resources.aceptacion_condiciones',
        ...array_map(static fn (string $k): string => "fam_unit.validation.{$k}", ['level_invalid', 'decision_invalid', 'channel_invalid', 'state_invalid', 'cursor_invalid']),
        ...array_map(static fn (string $k): string => "fam_unit.validation.{$k}", [
            'user_not_found', 'student_not_found', 'guardian_not_found', 'family_unit_not_found', 'principal_guardian_not_found',
            'relationship_invalid', 'access_level_invalid', 'restricted_category_invalid', 'reason_invalid', 'required', 'boolean_invalid',
            'reference_invalid', 'filter_forbidden',
        ]),
    ];

    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        foreach ($keys as $key) {
            expect(trans($key, [], $locale))->not->toBe($key, "{$key} sin traducir en {$locale}")->and(trans($key, [], $locale))->not->toBe('');
        }
    }

    $declared = (new FamUnitServiceProvider(app()))->declaredPermissions();

    expect($declared)->toHaveCount(20);

    foreach ($declared as $permission) {
        expect($permission['resource_label_key'])->toBe("fam_unit.permissions.resources.{$permission['resource']}");
    }
});

test('permisos.md §4 OPEN-FAM-UNIT-10 OPEN-FAM-UNIT-22: tenant:provision-defaults siembra familia, vinculo_tutor, revocacion_tutor, consentimiento_imagen y texto_legal según la tabla — revocar solo el administrador, redactar textos solo el administrador y secretaría solo los lee — y tutor_legal con unidad_familiar', function (): void {
    [$tenant] = provisionCoreTenant('fp-seed');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $all = fn (string $resource, array $actions): array => array_map(static fn (string $a): string => "{$resource}.{$a}:todos", $actions);
        $crud = ['actualizar', 'crear', 'eliminar', 'leer'];

        $admin = [
            ...$all('familia', $crud), ...$all('vinculo_tutor', $crud), ...$all('revocacion_tutor', ['crear', 'eliminar', 'leer']),
            ...$all('consentimiento_imagen', ['actualizar', 'exportar', 'leer']), ...$all('texto_legal', [...$crud, 'publicar']),
            'aceptacion_condiciones.leer:todos',
        ];
        sort($admin);
        $secretaria = [
            ...$all('familia', $crud), ...$all('vinculo_tutor', $crud), 'revocacion_tutor.leer:todos',
            ...$all('consentimiento_imagen', ['actualizar', 'exportar', 'leer']), 'texto_legal.leer:todos',
            'aceptacion_condiciones.leer:todos',
        ];
        sort($secretaria);

        expect(famGrantsOf('administrador_centro'))->toBe($admin)
            ->and(famGrantsOf('secretaria'))->toBe($secretaria)
            ->and(famGrantsOf('direccion'))->toBe(['consentimiento_imagen.leer:todos', 'familia.leer:todos', 'revocacion_tutor.leer:todos', 'vinculo_tutor.leer:todos'])
            ->and(famGrantsOf('administrativo'))->toBe(['familia.leer:todos', 'vinculo_tutor.leer:todos'])
            ->and(famGrantsOf('tutor_legal'))->toBe(['consentimiento_imagen.actualizar:unidad_familiar', 'consentimiento_imagen.leer:unidad_familiar', 'familia.leer:unidad_familiar', 'vinculo_tutor.leer:unidad_familiar']);

        // Revocar y levantar, solo el administrador (consecuencias legales, permisos.md §4).
        $revokers = PermissionRole::query()->whereIn('permission_code', ['revocacion_tutor.crear', 'revocacion_tutor.eliminar'])->pluck('role_id')->unique();
        expect($revokers)->toHaveCount(1)
            ->and(Role::find($revokers->first())->code)->toBe('administrador_centro');

        // Redactar, editar, borrar y publicar un texto legal, solo el administrador (OPEN-066-03).
        $writers = PermissionRole::query()->whereIn('permission_code', ['texto_legal.crear', 'texto_legal.actualizar', 'texto_legal.eliminar', 'texto_legal.publicar'])->pluck('role_id')->unique();
        expect($writers)->toHaveCount(1)
            ->and(Role::find($writers->first())->code)->toBe('administrador_centro');

        // Los once roles restantes, nada (RPERM-011).
        $others = Role::where('is_system', true)
            ->whereNotIn('code', ['administrador_centro', 'secretaria', 'direccion', 'administrativo', 'tutor_legal'])
            ->pluck('code');

        expect($others)->not->toBeEmpty();

        foreach ($others as $code) {
            expect(famGrantsOf($code))->toBe([], $code);
        }
    });

    expect(array_keys(ProvisionTenantDefaults::FAMILY_PERMISSION_GRANTS))->toBe(['administrador_centro', 'secretaria', 'direccion', 'administrativo'])
        ->and(array_keys(ProvisionTenantDefaults::FAMILY_PERMISSION_UNIDAD_FAMILIAR_GRANTS))->toBe(['tutor_legal']);
});

test('CA-FAM-UNIT-106 OPEN-FAM-UNIT-22: secretaria tiene texto_legal.leer con ámbito todos y ninguno de crear, actualizar, eliminar ni publicar — en un centro nuevo y en uno existente tras fam_unit:grant-permissions — y lista las versiones retiradas para indicarlas en una decisión presencial', function (): void {
    [$tenant, $admin] = Fam::setup('fp-106');

    $secretariaTexts = fn (): array => app(TenantContext::class)->runFor($tenant->id, fn () => PermissionRole::where('role_id', Role::where('code', 'secretaria')->firstOrFail()->id)
        ->where('permission_code', 'like', 'texto_legal.%')->get()->map(static fn (PermissionRole $p): string => "{$p->permission_code}:{$p->scope}")->all());

    // Centro nuevo.
    expect($secretariaTexts())->toBe(['texto_legal.leer:todos']);

    // Centro existente: sin la concesión, el comando la repone.
    app(TenantContext::class)->runFor($tenant->id, fn () => PermissionRole::where('role_id', Role::where('code', 'secretaria')->firstOrFail()->id)
        ->where('permission_code', 'like', 'texto_legal.%')->get()->each->delete());
    expect($secretariaTexts())->toBe([]);

    test()->artisan('fam_unit:grant-permissions')->assertSuccessful();
    expect($secretariaTexts())->toBe(['texto_legal.leer:todos']);

    // Y con ese permiso lista la versión retirada que firmó una familia (RN-FAM-UNIT-69) sin poder escribir textos.
    $secretaria = app(TenantContext::class)->runFor($tenant->id, function (): User {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', 'secretaria')->firstOrFail()->id);

        return $user;
    });
    $v1 = Fam::publishText($tenant, $admin, 'web');
    Fam::publishText($tenant, $admin, 'web');

    $retired = Fam::call($tenant, $secretaria, 'get', '/terms-versions?kind=consentimiento_imagen_web&status=retirada')->assertOk();
    expect(array_column($retired->json('data'), 'public_id'))->toBe([$v1]);

    Fam::call($tenant, $secretaria, 'post', '/terms-versions', ['kind' => 'consentimiento_imagen_web', 'title_translations' => ['es-ES' => 'T'], 'body_translations' => ['es-ES' => 'C']])->assertForbidden();
    Fam::call($tenant, $secretaria, 'post', "/terms-versions/{$v1}/publish")->assertForbidden();
    Fam::call($tenant, $secretaria, 'delete', "/terms-versions/{$v1}")->assertForbidden();
    Fam::call($tenant, $secretaria, 'patch', "/terms-versions/{$v1}", ['body_translations' => ['es-ES' => 'X']])->assertForbidden();
});

test('RPERM-012 permisos.md §4: ningún rol predefinido recibe permisos de categoría especial por este módulo (no hay ninguno)', function (): void {
    [$tenant] = provisionCoreTenant('fp-special');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $special = PermissionRole::query()->whereHas('permission', fn ($q) => $q->where('module_code', 'fam_unit')->where('is_special_category', true))->count();

        expect($special)->toBe(0);
    });
});

test('operacion.md §3: fam_unit:grant-permissions concede lo que falta a un centro existente (con el ámbito de cada lista), es idempotente y no toca roles personalizados ni una concesión previa con otro ámbito', function (): void {
    [$tenant, $admin] = provisionCoreTenant('fp-grant');

    resetSessionState();
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'rol_custom_fam', 'name' => 'Custom', 'permissions' => [['code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'todos']],
    ])->assertCreated();

    famRevokeAll($tenant);
    expect(famGrantCount($tenant))->toBe(0);

    // 20 (administrador) + 14 (secretaria) + 4 (direccion) + 2 (administrativo) + 4 (tutor_legal).
    test()->artisan('fam_unit:grant-permissions')->assertSuccessful();
    expect(famGrantCount($tenant))->toBe(44);

    test()->artisan('fam_unit:grant-permissions')->assertSuccessful();
    expect(famGrantCount($tenant))->toBe(44);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $custom = Role::where('code', 'rol_custom_fam')->firstOrFail();

        expect(PermissionRole::where('role_id', $custom->id)->pluck('permission_code')->all())->toBe(['usuario.leer'])
            ->and(famGrantsOf('tutor_legal'))->toBe(['consentimiento_imagen.actualizar:unidad_familiar', 'consentimiento_imagen.leer:unidad_familiar', 'familia.leer:unidad_familiar', 'vinculo_tutor.leer:unidad_familiar'])
            ->and(famGrantsOf('direccion'))->toBe(['consentimiento_imagen.leer:todos', 'familia.leer:todos', 'revocacion_tutor.leer:todos', 'vinculo_tutor.leer:todos']);
    });

    // Una concesión previa de otro ámbito (un centro que acotó `administrativo`) no se toca ni se duplica.
    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $role = Role::where('code', 'administrativo')->firstOrFail();
        PermissionRole::query()->where('role_id', $role->id)->where('permission_code', 'familia.leer')->get()->each->delete();
        PermissionRole::create(['role_id' => $role->id, 'permission_code' => 'familia.leer', 'effect' => 'allow', 'scope' => 'unidad_familiar']);
    });

    test()->artisan('fam_unit:grant-permissions')->assertSuccessful();

    app(TenantContext::class)->runFor($tenant->id, fn () => expect(famGrantsOf('administrativo'))->toBe(['familia.leer:unidad_familiar', 'vinculo_tutor.leer:todos']));
});

test('issue #387 B-5: el comando no concede nada y lo dice si algún permiso está retirado (retired_at) o el registro no está sincronizado', function (): void {
    [$tenant] = provisionCoreTenant('fp-guard');
    famRevokeAll($tenant);

    $owner = DB::connection('pgsql_owner');
    $owner->table('permissions')->where('code', 'revocacion_tutor.crear')->update(['retired_at' => now()]);

    try {
        test()->artisan('fam_unit:grant-permissions')
            ->expectsOutputToContain('permisos retirados en el registro (retired_at informado): revocacion_tutor.crear')
            ->assertFailed();

        expect(famGrantCount($tenant))->toBe(0);
    } finally {
        $owner->table('permissions')->where('code', 'revocacion_tutor.crear')->update(['retired_at' => null]);
    }

    // Registro sin sincronizar: el comando de producción con su comprobación previa, catálogo vacío.
    Artisan::registerCommand(new class extends GrantFamUnitPermissionsCommand
    {
        protected function registeredPermissionCodes(array $codes): array
        {
            return [];
        }
    });

    test()->artisan('fam_unit:grant-permissions')
        ->expectsOutputToContain('platform:sync-registry')
        ->assertFailed();

    expect(famGrantCount($tenant))->toBe(0);
});

test('CA-FAM-UNIT-081 RMOD-008 RMOD-009: con el módulo no contratado, toda ruta del módulo responde 403 module-disabled — también para el administrador — y sin sesión sigue siendo 401', function (): void {
    [$tenant, $admin] = provisionCoreTenant('fp-081');

    $routes = array_values(array_filter(iterator_to_array(Route::getRoutes()), static fn ($r): bool => str_starts_with((string) $r->getName(), 'fam-unit.')));

    expect($routes)->toHaveCount(35);

    foreach ($routes as $route) {
        $uri = '/'.str_replace(['api/v1', '{publicId}', '{membershipPublicId}'], ['', '01JZZZZZZZZZZZZZZZZZZZZZZZ', '01JZZZZZZZZZZZZZZZZZZZZZZY'], $route->uri());
        $uri = preg_replace('#//+#', '/', $uri);
        $method = $route->methods()[0];

        resetSessionState();
        $response = test()->actingAs($admin)->json($method, coreApiUrl($tenant->slug, $uri), []);

        expect($response->getStatusCode())->toBe(403, "{$method} {$uri}")
            ->and($response->json('type'))->toBe('urn:pge:error:module-disabled', "{$method} {$uri}");

        resetSessionState();
        expect(test()->json($method, coreApiUrl($tenant->slug, $uri), [])->getStatusCode())->toBe(401, "{$method} {$uri} sin sesión");
    }
});

test('CA-FAM-UNIT-081: descontratar el módulo vuelve 403 las rutas de un centro que sí lo tenía, aunque el rol conserve los permisos (RMOD-009, inerte_modulo)', function (): void {
    [$tenant, $admin] = Fam::setup('fp-081b');
    Fam::unitId($tenant, $admin);

    Fam::call($tenant, $admin, 'get', '/family-units')->assertOk();

    Fam::disableModule($tenant);

    $response = Fam::call($tenant, $admin, 'get', '/family-units')->assertForbidden();
    expect($response->json('type'))->toBe('urn:pge:error:module-disabled');

    // Los datos se conservan (RMOD-003).
    $count = app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('family_units')->count());
    expect($count)->toBe(1);
});
