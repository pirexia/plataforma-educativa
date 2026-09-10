<?php

namespace App\Modules\Backoffice\Infrastructure\Console;

use App\Modules\Backoffice\Infrastructure\Jobs\CloseOrphanedPlatformSessions;
use Illuminate\Console\Command;

/**
 * operacion.md §6.2, §6.3. Cada 15 minutos, no cada 5 — una sesión de
 * plataforma huérfana no ocupa ningún hueco de índice único.
 */
class CloseOrphanedPlatformSessionsCommand extends Command
{
    protected $signature = 'bo:close-orphaned-sessions';

    protected $description = 'Cierra como "caducidad" las filas de platform_admin_sessions cuya sesión ya no existe (operacion.md §6.3)';

    public function handle(): int
    {
        CloseOrphanedPlatformSessions::dispatch();

        return self::SUCCESS;
    }
}
