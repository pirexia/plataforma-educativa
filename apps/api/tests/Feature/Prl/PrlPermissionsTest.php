<?php

use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Modules\Prl\Infrastructure\PrlServiceProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

// REQ-PRL-004 (1.14b-b), docs/modulos/REQ-PRL/permisos.md §8.1 a §8.4, ADR-068 §6 L7, L9, RPERM-011, RPERM-012, AR-09, AR-12, INV-002,
// INV-009: catálogo de permisos de la puerta, su siembra en roles predefinidos (centros nuevos y existentes) y sus etiquetas.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

const PRL_CODES = ['alerta_entrega.actualizar', 'alerta_entrega.crear', 'alerta_entrega.leer', 'entrega_menor.crear', 'entrega_menor.leer'];

/** Concesiones de la puerta (`entrega_menor.*`, `alerta_entrega.*`) de un rol, ordenadas, con su ámbito (`código:ámbito`). */
function prlGrantsOf(string $roleCode): array
{
    $role = Role::where('code', $roleCode)->firstOrFail();

    return PermissionRole::where('role_id', $role->id)
        ->where(fn ($q) => $q->where('permission_code', 'like', 'entrega_menor.%')->orWhere('permission_code', 'like', 'alerta_entrega.%'))
        ->get()
        ->map(static fn (PermissionRole $p): string => "{$p->permission_code}:{$p->scope}")
        ->sort()->values()->all();
}

test('permisos.md §8.2 L7 ADR-034 §5: tras platform:sync-registry, permissions contiene exactamente los cinco códigos de prl, solo con ámbito todos, sin exportar ni importar y ninguno de categoría especial', function (): void {
    test()->artisan('platform:sync-registry')->assertSuccessful();

    $rows = Permission::query()->where('module_code', 'prl')->orderBy('code')->get();

    expect($rows->pluck('code')->all())->toBe(PRL_CODES);

    foreach ($rows as $permission) {
        expect($permission->applicable_scopes)->toBe(['todos'], $permission->code)
            ->and($permission->is_special_category)->toBeFalse()
            ->and($permission->retired_at)->toBeNull()
            ->and($permission->resource)->toBe(explode('.', $permission->code)[0]);
    }

    // Sin endpoint, un permiso invita a concederlo creyendo que hace algo: ni exportar, ni importar, ni eliminar.
    expect(Permission::query()->where(fn ($q) => $q
        ->where('code', 'like', 'entrega_menor.exportar')->orWhere('code', 'like', 'entrega_menor.importar')->orWhere('code', 'like', 'entrega_menor.actualizar')->orWhere('code', 'like', 'entrega_menor.eliminar')
        ->orWhere('code', 'like', 'alerta_entrega.exportar')->orWhere('code', 'like', 'alerta_entrega.importar')->orWhere('code', 'like', 'alerta_entrega.eliminar'))->count())->toBe(0);
});

test('CA-PRL-013 AR-12 INV-009: el nombre del módulo, la etiqueta de cada recurso y todos los textos de prl existen en es, en, de y fr, y cada permiso declara su resource_label_key', function (): void {
    $es = require base_path('lang/es/prl.php');
    $flatten = function (array $array, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($array as $key => $value) {
            is_array($value) ? $keys = [...$keys, ...$flatten($value, "{$prefix}{$key}.")] : $keys[] = "{$prefix}{$key}";
        }

        return $keys;
    };
    $keys = ['modules.prl', ...array_map(static fn (string $k): string => "prl.{$k}", $flatten($es))];

    expect(count($keys))->toBeGreaterThan(20);

    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        foreach ($keys as $key) {
            expect(trans($key, [], $locale))->not->toBe($key, "{$key} sin traducir en {$locale}")->and(trans($key, [], $locale))->not->toBe('');
        }
    }

    foreach ((new PrlServiceProvider(app()))->declaredPermissions() as $permission) {
        expect($permission['resource_label_key'])->toBe("prl.permissions.resources.{$permission['resource']}");
    }
});

test('permisos.md §8.4 L9 OPEN-FAM-UNIT-42: tenant:provision-defaults siembra la puerta según la tabla — conserjería consulta, registra y abre alertas; dirección y administrador, las alertas completas; secretaría, solo lee — y ningún otro rol recibe nada', function (): void {
    [$tenant] = provisionCoreTenant('prlp-seed');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $todos = fn (array $codes): array => array_map(static fn (string $c): string => "{$c}:todos", $codes);

        expect(prlGrantsOf('administrador_centro'))->toBe($todos(PRL_CODES))
            ->and(prlGrantsOf('secretaria'))->toBe($todos(['alerta_entrega.leer', 'entrega_menor.leer']))
            ->and(prlGrantsOf('direccion'))->toBe($todos(['alerta_entrega.actualizar', 'alerta_entrega.crear', 'alerta_entrega.leer', 'entrega_menor.leer']))
            ->and(prlGrantsOf('conserjeria_pas'))->toBe($todos(['alerta_entrega.crear', 'alerta_entrega.leer', 'entrega_menor.crear', 'entrega_menor.leer']));

        // Conserjería no gestiona autorizados (permisos.md §8.4): ninguno de autorizado_recogida.
        $conserjeria = Role::where('code', 'conserjeria_pas')->firstOrFail();
        expect(PermissionRole::where('role_id', $conserjeria->id)->where('permission_code', 'like', 'autorizado_recogida.%')->count())->toBe(0);

        // Docentes, tutor de grupo, tutor legal y el resto: nada (OPEN-FAM-UNIT-42, RPERM-011).
        $others = Role::where('is_system', true)->whereNotIn('code', ['administrador_centro', 'secretaria', 'direccion', 'conserjeria_pas'])->pluck('code');
        expect($others)->not->toBeEmpty();

        foreach ($others as $code) {
            expect(prlGrantsOf($code))->toBe([], $code);
        }

        // Ninguna concesión de la puerta lleva ámbito distinto de `todos`.
        expect(PermissionRole::query()->where(fn ($q) => $q->where('permission_code', 'like', 'entrega_menor.%')->orWhere('permission_code', 'like', 'alerta_entrega.%'))->where('scope', '<>', 'todos')->count())->toBe(0);
    });
});

test('operacion.md §12.4 permisos.md §8.4: fam_unit:grant-permissions concede la puerta a un centro existente (conserjería incluida), es idempotente y no toca roles personalizados', function (): void {
    [$tenant, $admin] = provisionCoreTenant('prlp-grant');

    resetSessionState();
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => 'rol_custom_prl', 'name' => 'Custom', 'permissions' => [['code' => 'usuario.leer', 'effect' => 'allow', 'scope' => 'todos']],
    ])->assertCreated();

    $prlFilter = fn ($q) => $q->where('permission_code', 'like', 'entrega_menor.%')->orWhere('permission_code', 'like', 'alerta_entrega.%');
    $count = fn (): int => app(TenantContext::class)->runFor($tenant->id, fn (): int => PermissionRole::query()->where($prlFilter)->count());

    app(TenantContext::class)->runFor($tenant->id, fn () => PermissionRole::query()->where($prlFilter)->get()->each->delete());
    expect($count())->toBe(0);

    // 5 (administrador) + 2 (secretaría) + 4 (dirección) + 4 (conserjería).
    test()->artisan('fam_unit:grant-permissions')->assertSuccessful();
    expect($count())->toBe(15);

    test()->artisan('fam_unit:grant-permissions')->assertSuccessful();
    expect($count())->toBe(15);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $custom = Role::where('code', 'rol_custom_prl')->firstOrFail();

        expect(PermissionRole::where('role_id', $custom->id)->pluck('permission_code')->all())->toBe(['usuario.leer'])
            ->and(prlGrantsOf('conserjeria_pas'))->toBe(['alerta_entrega.crear:todos', 'alerta_entrega.leer:todos', 'entrega_menor.crear:todos', 'entrega_menor.leer:todos']);
    });
});

test('RPERM-012 AR-09: ningún rol predefinido recibe permisos de categoría especial por la puerta (no hay ninguno)', function (): void {
    test()->artisan('platform:sync-registry')->assertSuccessful();

    expect(Permission::query()->where('module_code', 'prl')->where('is_special_category', true)->count())->toBe(0);
});
