<?php

namespace App\Modules\Auth\Infrastructure\Console;

use App\Modules\Auth\Infrastructure\Jobs\PurgeExpiredPasswordResetTokens;
use App\Modules\Auth\Infrastructure\Jobs\PurgeLoginAttempts;
use App\Modules\Auth\Infrastructure\Jobs\PurgeMfaChallenges;
use App\Modules\Auth\Infrastructure\Jobs\PurgeMfaEnrollments;
use App\Modules\Auth\Infrastructure\Jobs\PurgeMfaFactors;
use App\Modules\Auth\Infrastructure\Jobs\PurgeUnlockTokens;
use App\Modules\Auth\Infrastructure\Jobs\PurgeUserKnownDevices;
use App\Modules\Auth\Infrastructure\Jobs\PurgeUserSessions;
use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * operacion.md §4, §B.3, §D.4.1.1: despacha las purgas diarias de
 * REQ-AUTH para cada tenant activo. 1.2b añade las dos de sesiones/
 * dispositivos; 1.3b (issue #109, pieza 4) añade las tres de MFA —
 * `PurgeMfaChallenges`, `PurgeMfaEnrollments`, `PurgeMfaFactors`, que
 * `operacion.md §D.4.1.1` fija deliberadamente aquí (diarias) y no en el
 * comando horario nuevo (`auth:mfa-obligations`). Programado desde
 * `routes/console.php`.
 */
class PurgeAuthMaintenanceCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'auth:purge-maintenance';

    protected $description = 'Despacha las purgas programadas de REQ-AUTH (intentos de acceso, tokens de restablecimiento y de desbloqueo vencidos, sesiones, dispositivos y material de MFA vencido) para cada tenant activo';

    public function handle(): int
    {
        $this->eachTenant(function (Tenant $tenant): void {
            PurgeLoginAttempts::dispatch($tenant->id);
            PurgeExpiredPasswordResetTokens::dispatch($tenant->id);
            PurgeUnlockTokens::dispatch($tenant->id);
            PurgeUserSessions::dispatch($tenant->id);
            PurgeUserKnownDevices::dispatch($tenant->id);
            PurgeMfaChallenges::dispatch($tenant->id);
            PurgeMfaEnrollments::dispatch($tenant->id);
            PurgeMfaFactors::dispatch($tenant->id);
        });

        return self::SUCCESS;
    }
}
