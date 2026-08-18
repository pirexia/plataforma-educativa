<?php

namespace App\Support\Modules;

use Illuminate\Support\Facades\DB;

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
}
