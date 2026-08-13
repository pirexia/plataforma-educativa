<?php

namespace App\Support\Modules;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Descubre los ServiceProvider de cada bounded context en app/Modules/.
 *
 * Convención: app/Modules/<Modulo>/Infrastructure/<Modulo>ServiceProvider.php,
 * con namespace App\Modules\<Modulo>\Infrastructure. Ningún módulo se registra
 * a mano en bootstrap/providers.php (INV-007: los módulos son autónomos).
 */
class ModuleServiceProviderDiscovery
{
    /**
     * @return list<class-string>
     */
    public static function discover(string $modulesPath): array
    {
        if (! is_dir($modulesPath)) {
            return [];
        }

        $providers = [];

        foreach (glob($modulesPath.'/*/Infrastructure/*ServiceProvider.php') ?: [] as $file) {
            $module = basename(dirname(dirname($file)));
            $class = Str::before(basename($file), '.php');
            $fqcn = "App\\Modules\\{$module}\\Infrastructure\\{$class}";

            if (is_subclass_of($fqcn, ServiceProvider::class)) {
                $providers[] = $fqcn;
            }
        }

        sort($providers);

        return $providers;
    }
}
