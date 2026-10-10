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
     * Espacios de nombres vedados de UN módulo para los demás (ADR-056
     * AR-01, #378 B2): todo lo que cuelgue de su raíz EXCEPTO `Domain`, más
     * `Domain\Models`. Es la regla invertida («de otro módulo solo `Domain`
     * sin `Models`»): una carpeta o clase nueva en la raíz del módulo
     * (`Console`, `Support`, `Listeners`…) queda vedada sin tocar el test.
     *
     * @param  string|null  $modulesPath  raíz de `Modules/` (solo para probar el propio cálculo)
     * @return list<string>
     */
    public static function forbiddenNamespacesOf(string $module, ?string $modulesPath = null): array
    {
        $root = ($modulesPath ?? dirname(__DIR__, 2).'/app/Modules')."/{$module}";
        $forbidden = ["App\\Modules\\{$module}\\Domain\\Models"];

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'Domain') {
                continue;
            }

            if (is_dir("{$root}/{$entry}")) {
                $forbidden[] = "App\\Modules\\{$module}\\{$entry}";
            } elseif (str_ends_with($entry, '.php')) {
                $forbidden[] = "App\\Modules\\{$module}\\".substr($entry, 0, -4);
            }
        }

        sort($forbidden);

        return $forbidden;
    }

    /**
     * Lo vedado a `$module` (AR-01): la unión de `forbiddenNamespacesOf()`
     * de todos los demás módulos.
     *
     * @return list<string>
     */
    public static function forbiddenFor(string $module): array
    {
        $forbidden = [];

        foreach (array_diff(self::names(), [$module]) as $other) {
            array_push($forbidden, ...self::forbiddenNamespacesOf($other));
        }

        return $forbidden;
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
