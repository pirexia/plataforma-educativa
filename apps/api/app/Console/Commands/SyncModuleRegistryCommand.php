<?php

namespace App\Console\Commands;

use App\Support\Modules\ModuleServiceProviderDiscovery;
use App\Support\Modules\SyncModuleRegistry;
use Illuminate\Console\Command;

/**
 * ADR-034 §2, §5, §7 (subpaso 0.8.11). Paso obligatorio del despliegue,
 * documentado en SYSADMIN.md: sin ejecutarlo, un permiso o módulo nuevo
 * del código no existe todavía en las tablas de referencia y su endpoint
 * deniega — falla en cerrado, correcto, pero hay que saber que hace falta
 * correrlo.
 */
class SyncModuleRegistryCommand extends Command
{
    protected $signature = 'platform:sync-registry';

    protected $description = 'Materializa en modules/permissions lo que declaran los ServiceProvider de los módulos (ADR-034 §2, §5, §7)';

    public function handle(): int
    {
        $providers = ModuleServiceProviderDiscovery::discover(app_path('Modules'));

        SyncModuleRegistry::run($providers);

        $this->info(sprintf('Registro de plataforma sincronizado (%d ServiceProvider revisados).', count($providers)));

        return self::SUCCESS;
    }
}
