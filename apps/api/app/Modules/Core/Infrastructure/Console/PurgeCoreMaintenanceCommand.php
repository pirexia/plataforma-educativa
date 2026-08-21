<?php

namespace App\Modules\Core\Infrastructure\Console;

use App\Modules\Core\Infrastructure\Jobs\PurgeExpiredExports;
use App\Modules\Core\Infrastructure\Jobs\PurgeExpiredInvitations;
use App\Modules\Core\Infrastructure\Jobs\PurgeImportArtifacts;
use App\Modules\Core\Infrastructure\Jobs\PurgeOrphanBrandingAssets;
use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * operacion.md §4: despacha las cuatro purgas por tenant a la cola
 * `core-maintenance`. Programado a diario desde `routes/console.php`.
 * `PurgeExpiredIdempotencyKeys` no está aquí: no es por tenant en el
 * filtro (ver su propia clase) y se programa directamente.
 *
 * Cada `dispatch()` es una sentencia suelta, nunca el valor de retorno
 * del closure de `eachTenant()` (aviso de `TenantContext::runFor()`).
 */
class PurgeCoreMaintenanceCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'core:purge-maintenance';

    protected $description = 'Despacha las purgas programadas de REQ-CORE (invitaciones, artefactos de importación, exportaciones, activos de marca huérfanos) para cada tenant activo';

    public function handle(): int
    {
        $this->eachTenant(function (Tenant $tenant): void {
            PurgeExpiredInvitations::dispatch($tenant->id);
            PurgeImportArtifacts::dispatch($tenant->id);
            PurgeExpiredExports::dispatch($tenant->id);
            PurgeOrphanBrandingAssets::dispatch($tenant->id);
        });

        return self::SUCCESS;
    }
}
