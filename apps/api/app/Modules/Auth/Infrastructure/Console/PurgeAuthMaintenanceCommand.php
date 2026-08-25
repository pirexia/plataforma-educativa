<?php

namespace App\Modules\Auth\Infrastructure\Console;

use App\Modules\Auth\Infrastructure\Jobs\PurgeExpiredPasswordResetTokens;
use App\Modules\Auth\Infrastructure\Jobs\PurgeLoginAttempts;
use App\Modules\Auth\Infrastructure\Jobs\PurgeUnlockTokens;
use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * operacion.md §4: despacha las tres purgas diarias de REQ-AUTH para cada
 * tenant activo. Programado desde `routes/console.php`.
 */
class PurgeAuthMaintenanceCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'auth:purge-maintenance';

    protected $description = 'Despacha las purgas programadas de REQ-AUTH (intentos de acceso, tokens de restablecimiento y de desbloqueo vencidos) para cada tenant activo';

    public function handle(): int
    {
        $this->eachTenant(function (Tenant $tenant): void {
            PurgeLoginAttempts::dispatch($tenant->id);
            PurgeExpiredPasswordResetTokens::dispatch($tenant->id);
            PurgeUnlockTokens::dispatch($tenant->id);
        });

        return self::SUCCESS;
    }
}
