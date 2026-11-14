<?php

namespace App\Modules\Backoffice\Infrastructure\Jobs;

use App\Modules\Backoffice\Domain\Models\PlatformAdminSession;
use App\Modules\Backoffice\Domain\PlatformAdminSessionEndReason;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * ADR-047 §5.1, datos.md §2.7.2, operacion.md §6.3. Precedente exacto de
 * `CloseOrphanedUserSessions`: para toda fila viva de
 * `platform_admin_sessions` cuyo `session_id` ya no existe en
 * `platform_sessions`, anula `session_id`, fija `ended_at` y escribe
 * `end_reason = 'caducidad'`.
 *
 * Corre FUERA de todo tenant, sobre dos tablas de plataforma — no usa
 * `RunsPerTenant` (operacion.md §6.3): no hay tenant que iterar, es un
 * barrido único sobre el rol `plataforma_platform`.
 */
class CloseOrphanedPlatformSessions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $this->onQueue('backoffice-maintenance');
    }

    public function handle(): void
    {
        $liveSessions = PlatformAdminSession::query()
            ->whereNull('ended_at')
            ->whereNotNull('session_id')
            ->get();

        if ($liveSessions->isEmpty()) {
            return;
        }

        $existingIds = DB::connection('pgsql_platform')
            ->table('platform_sessions')
            ->whereIn('id', $liveSessions->pluck('session_id'))
            ->pluck('id')
            ->all();

        foreach ($liveSessions as $session) {
            if (! in_array($session->session_id, $existingIds, true)) {
                $session->close(PlatformAdminSessionEndReason::Caducidad);
            }
        }
    }
}
