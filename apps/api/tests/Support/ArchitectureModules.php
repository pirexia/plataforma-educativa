<?php

namespace Tests\Support;

use App\Support\Modules\DeclaresModuleRegistry;
use App\Support\Modules\ModuleServiceProviderDiscovery;

/**
 * ADR-056 §3.3: enumeración de los módulos de `app/Modules/` por sistema de
 * ficheros, para que las reglas de arquitectura vigilen un módulo nuevo sin
 * tocar los tests. Usa `__DIR__` y no `base_path()` porque Pest carga los
 * ficheros de test (y genera sus `arch()`) antes de arrancar la aplicación.
 */
final class ArchitectureModules
{
    /**
     * Nombres de directorio (StudlyCase) de cada módulo, ordenados.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = array_map('basename', glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR) ?: []);
        sort($names);

        return array_values($names);
    }

    /**
     * `declaredPermissions()` de todos los módulos que declaran catálogo,
     * tal como los descubre `platform:sync-registry` (requiere la aplicación
     * arrancada, no se puede llamar al cargar el fichero).
     *
     * @return list<array{code: string, resource: string, action: string, is_special_category?: bool, applicable_scopes?: list<string>, resource_label_key?: string}>
     */
    public static function declaredPermissions(): array
    {
        $permissions = [];

        foreach (ModuleServiceProviderDiscovery::discover(app_path('Modules')) as $provider) {
            $instance = new $provider(app());

            if ($instance instanceof DeclaresModuleRegistry) {
                array_push($permissions, ...$instance->declaredPermissions());
            }
        }

        return $permissions;
    }
}
