<?php

namespace App\Modules\Auth\Infrastructure\Console;

use App\Modules\Auth\Infrastructure\Jobs\CloseOrphanedUserSessions;
use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * funcional.md §B.4.7, operacion.md §B.3.1: cada 15 minutos, no cada 5
 * como `auth:close-expired-lockouts` — una sesión huérfana no ocupa
 * ningún hueco de índice único y el listado ya la cierra perezosamente en
 * cuanto alguien mira; esta tarea solo recoge lo que nadie vuelva a
 * mirar.
 */
class CloseOrphanedUserSessionsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'auth:close-orphaned-sessions';

    protected $description = 'Cierra como "caducidad" las filas de user_sessions cuya sesión ya no existe en el framework (funcional.md §B.4.7)';

    public function handle(): int
    {
        $this->eachTenant(function (Tenant $tenant): void {
            CloseOrphanedUserSessions::dispatch($tenant->id);
        });

        return self::SUCCESS;
    }
}
