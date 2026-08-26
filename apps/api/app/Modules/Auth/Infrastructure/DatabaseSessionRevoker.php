<?php

namespace App\Modules\Auth\Infrastructure;

use App\Models\User;
use App\Modules\Auth\Domain\Events\SessionRevoked;
use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Auth\Domain\SessionEndReason;
use App\Modules\Auth\Domain\SessionRevoker;
use Illuminate\Support\Facades\DB;

/**
 * RN-AUTH-22, RN-AUTH-36, RN-AUTH-42, ampliada en 1.2b (funcional.md
 * §B.2.1 punto 1). `sessions` guarda `user_id` como valor plano (no FK,
 * framework de Laravel) y no tiene `tenant_id` (OPEN-AUTH-10, OPEN-AUTH-15
 * — endurecimiento futuro, issue #81); el filtrado real de tenant lo hace
 * `user_sessions`, tabla de tenant con RLS, que es la fuente de verdad de
 * qué sesiones cerrar.
 *
 * Solo borra la fila de `sessions` con `SESSION_DRIVER=database` — el
 * único driver que deja algo que borrar. `RN-AUTH-49`/`SessionEnvironmentGuard`
 * garantizan ese valor en todo entorno que arranca la aplicación; esta
 * comprobación es defensa en profundidad, no el mecanismo principal.
 *
 * ADR-040 §6 (la trampa de la revocación masiva): cierra las filas de
 * `user_sessions` modelo a modelo, nunca con un `UPDATE` por consulta — un
 * `UPDATE` masivo no dispara el *observer* de auditoría de 0.9 y la
 * revocación quedaría sin registrar en `audit_logs` (CA-AUTH-102).
 */
final class DatabaseSessionRevoker implements SessionRevoker
{
    public function revokeAllForUser(User $user, SessionEndReason $reason, ?string $exceptSessionId = null): void
    {
        $query = UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('ended_at');

        if ($exceptSessionId !== null) {
            $query->where('session_id', '!=', $exceptSessionId);
        }

        foreach ($query->get() as $session) {
            $this->revokeSession($session, $reason);
        }
    }

    public function revokeSession(UserSession $session, SessionEndReason $reason, ?User $revokedBy = null): void
    {
        if (! $session->isLive()) {
            return;
        }

        DB::transaction(function () use ($session, $reason, $revokedBy): void {
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table(config('session.table', 'sessions'))
                    ->where('id', $session->session_id)
                    ->delete();
            }

            $session->close($reason, $revokedBy);
        });

        // funcional.md §B.8.2: SessionRevoked describe una acción
        // deliberada del titular sobre su propia sesión — nunca los
        // demás cierres automáticos (inactividad, caducidad, cambio de
        // credencial, baja de usuario, tenant incoherente).
        if ($reason === SessionEndReason::RevocadaUsuario) {
            event(new SessionRevoked($session->tenant_id, $session->user->public_id, $session->public_id, $reason));
        }
    }
}
