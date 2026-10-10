<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Auth\Domain\SessionEndReason;
use App\Support\Sessions\ActiveSessionCloser;

/**
 * Implementación de `ActiveSessionCloser` (INV-007, ADR-056 AR-02). Conserva
 * exactamente la consulta que antes vivía inline en los *middleware*
 * `EnforceSessionIdleTimeout` y `VerifySessionTenant`: pasa por `TenantScope`
 * y por `UserSession::close()`, de modo que el observer de auditoría sigue
 * registrando el cierre (CA-AUTH-102).
 */
final class EloquentActiveSessionCloser implements ActiveSessionCloser
{
    public function closeBySessionId(string $sessionId, SessionEndReason $reason): void
    {
        UserSession::query()
            ->where('session_id', $sessionId)
            ->whereNull('ended_at')
            ->first()
            ?->close($reason);
    }
}
