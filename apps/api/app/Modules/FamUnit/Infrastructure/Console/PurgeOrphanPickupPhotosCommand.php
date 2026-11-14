<?php

namespace App\Modules\FamUnit\Infrastructure\Console;

use App\Modules\FamUnit\Infrastructure\Jobs\PurgeOrphanPickupPhotos;
use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * REQ-FAM-UNIT/operacion.md §12.3 (1.14b-a): despacha el barrido de fotografías de recogida huérfanas de cada centro activo a
 * la cola `default`. Programado a diario desde `routes/console.php`; mientras el planificador no tenga servicio desplegado
 * (issue #453) se lanza a mano (`RUNBOOK.md`). Cada `dispatch()` es una sentencia suelta, nunca el valor de retorno del
 * closure de `eachTenant()` (aviso de `TenantContext::runFor()`).
 */
class PurgeOrphanPickupPhotosCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'fam_unit:purge-orphan-photos';

    protected $description = 'Despacha el barrido de fotografías de recogida huérfanas (REQ-FAM-UNIT/operacion.md §12.3) para cada centro activo';

    public function handle(): int
    {
        $this->eachTenant(function (Tenant $tenant): void {
            PurgeOrphanPickupPhotos::dispatch($tenant->id);
        });

        return self::SUCCESS;
    }
}
