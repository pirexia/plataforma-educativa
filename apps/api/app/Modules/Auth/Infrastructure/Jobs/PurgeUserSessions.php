<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Domain\Models\UserSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * datos.md §B.7, operacion.md §B.3.1. Retención de
 * `AUTH_USER_SESSION_RETENTION_DAYS` (90 por defecto) desde el cierre.
 * `user_sessions` no es *append-only* (`tenantTable()`, no
 * `tenantTableAppendOnly()`): el rol de aplicación puede borrar sin
 * ceremonia, a diferencia de `PurgeLoginAttempts`. `forceDelete()` en vez
 * de `delete()` — borrado físico, no lógico (la fila ya está cerrada, el
 * borrado lógico no protegería nada aquí). ADR-040 §4.3: purga física, no
 * pasa por el *observer* de auditoría — no es una exclusión, es el mismo
 * camino que `ADR-035`/`OPEN-12` fijó para completar la supresión por
 * vencimiento de plazo.
 */
class PurgeUserSessions implements ShouldQueue
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
        $retentionDays = (int) config('auth-local.user_session_retention_days');

        UserSession::query()
            ->whereNotNull('ended_at')
            ->where('ended_at', '<', now()->subDays($retentionDays))
            ->forceDelete();
    }
}
