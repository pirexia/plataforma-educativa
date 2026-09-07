<?php

namespace App\Support\Modules;

use App\Support\Authorization\Scope;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ADR-034 §2, §5, §7 (subpaso 0.8.11). Materializa en `modules` y
 * `permissions` lo que declaran los ServiceProvider de los módulos.
 * Idempotente: dos ejecuciones seguidas con el mismo código no producen
 * cambios. Nunca borra — lo que desaparece del código se marca
 * `retired_at`, porque borrar arrastraría por clave foránea las
 * concesiones (`permission_role`) y suscripciones (`module_subscriptions`)
 * históricas de todos los centros.
 *
 * Escribe siempre por `pgsql_owner`: son tablas de referencia con
 * escritura reservada al propietario (REVOKE en las migraciones de 0.8.6
 * y 0.8.7), y este comando es precisamente el único que debe poder
 * tocarlas.
 */
final class SyncModuleRegistry
{
    /**
     * @param  list<class-string>  $providerClasses
     */
    public static function run(array $providerClasses): void
    {
        $declaredModuleCodes = [];
        $declaredPermissionCodes = [];

        foreach ($providerClasses as $providerClass) {
            $provider = new $providerClass(app());

            if (! $provider instanceof DeclaresModuleRegistry) {
                continue;
            }

            $module = $provider->moduleDescriptor();
            $declaredModuleCodes[] = $module['code'];

            DB::connection('pgsql_owner')->table('modules')->updateOrInsert(
                ['code' => $module['code']],
                ['name_key' => $module['name_key'], 'phase' => $module['phase'], 'retired_at' => null]
            );

            foreach ($provider->declaredPermissions() as $permission) {
                $declaredPermissionCodes[] = $permission['code'];

                DB::connection('pgsql_owner')->table('permissions')->updateOrInsert(
                    ['code' => $permission['code']],
                    [
                        'resource' => $permission['resource'],
                        'action' => $permission['action'],
                        'module_code' => $module['code'],
                        'is_special_category' => $permission['is_special_category'] ?? false,
                        'applicable_scopes' => self::encodeApplicableScopes($permission),
                        'retired_at' => null,
                    ]
                );
            }
        }

        DB::connection('pgsql_owner')->table('modules')
            ->whereNotIn('code', $declaredModuleCodes)
            ->whereNull('retired_at')
            ->update(['retired_at' => now()]);

        DB::connection('pgsql_owner')->table('permissions')
            ->whereNotIn('code', $declaredPermissionCodes)
            ->whereNull('retired_at')
            ->update(['retired_at' => now()]);
    }

    /**
     * REQ-PERM/operacion.md §4.2: valida `applicable_scopes` contra el
     * vocabulario cerrado de `Scope` y **aborta el despliegue** si un
     * módulo declara un ámbito inexistente — preferible un despliegue
     * detenido a un catálogo con un ámbito que ningún `CHECK` de
     * `permission_role` aceptará después.
     *
     * @param  array{code: string, applicable_scopes?: list<string>}  $permission
     */
    private static function encodeApplicableScopes(array $permission): ?string
    {
        if (! isset($permission['applicable_scopes'])) {
            return null;
        }

        foreach ($permission['applicable_scopes'] as $scope) {
            if (Scope::tryFrom($scope) === null) {
                throw new InvalidArgumentException(
                    "El permiso «{$permission['code']}» declara el ámbito «{$scope}», ".
                    'fuera del vocabulario cerrado de Scope (RN-PERM-01). platform:sync-registry aborta.'
                );
            }
        }

        return json_encode($permission['applicable_scopes']);
    }
}
