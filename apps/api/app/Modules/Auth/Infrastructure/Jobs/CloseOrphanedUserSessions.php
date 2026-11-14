<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Auth\Domain\SessionEndReason;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * funcional.md §B.4.7, operacion.md §B.3.1. Consolidador de las filas que
 * nadie ha vuelto a mirar: el listado (`UserSessionsController::index()`)
 * ya cierra perezosamente las que encuentra, esta tarea recoge el resto.
 * Cierra modelo a modelo (nunca un `UPDATE` masivo, `ADR-040 §6`): un
 * `UPDATE` por consulta no dispara el *observer* de auditoría y el cierre
 * quedaría sin registrar.
 */
class CloseOrphanedUserSessions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $tenantId,
    ) {
        $this->onQueue('auth-maintenance');
    }

    public function handle(): void
    {
        $liveSessions = UserSession::query()->whereNull('ended_at')->get();

        if ($liveSessions->isEmpty()) {
            return;
        }

        $existingIds = DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->whereIn('id', $liveSessions->pluck('session_id'))
            ->pluck('id')
            ->all();

        foreach ($liveSessions as $session) {
            if (! in_array($session->session_id, $existingIds, true)) {
                $session->close(SessionEndReason::Caducidad);
            }
        }
    }
}
