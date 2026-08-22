<?php

namespace App\Modules\Auth\Infrastructure\Console;

use App\Modules\Auth\Infrastructure\Jobs\CloseExpiredLockouts;
use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * funcional.md §4.4: consolidador perezoso de RN-AUTH-38, programado cada
 * pocos minutos (no diario, a diferencia de las purgas de
 * `auth:purge-maintenance`) — ver el hallazgo documentado en
 * `CloseExpiredLockouts`.
 */
class CloseExpiredLockoutsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'auth:close-expired-lockouts';

    protected $description = 'Cierra como "caducidad" los bloqueos de cuenta vencidos que nadie ha vuelto a tocar (RN-AUTH-38)';

    public function handle(): int
    {
        $this->eachTenant(function (Tenant $tenant): void {
            CloseExpiredLockouts::dispatch($tenant->id);
        });

        return self::SUCCESS;
    }
}
