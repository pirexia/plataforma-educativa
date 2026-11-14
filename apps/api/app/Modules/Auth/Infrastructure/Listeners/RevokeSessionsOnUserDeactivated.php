<?php

namespace App\Modules\Auth\Infrastructure\Listeners;

use App\Models\User;
use App\Modules\Auth\Domain\SessionEndReason;
use App\Modules\Auth\Domain\SessionRevoker;
use App\Modules\Core\Domain\Events\UserDeactivated;

/**
 * funcional.md §8.2, ampliado en §B.2.1 punto 1 (1.2b): consumidor de
 * `UserDeactivated` (REQ-CORE) que revoca todas las sesiones activas del
 * usuario dado de baja, cerrando sus filas de `user_sessions` con
 * `baja_usuario`. Síncrono, no `ShouldQueue`: se dispara dentro de la
 * misma petición y del mismo contexto de tenant que la baja
 * (`UsersController::destroy()`), y la revocación tiene que ser
 * inmediata.
 *
 * `ended_by` queda `NULL`: el evento no transporta el actor que dio de
 * baja (funcional.md §B.4.6, «si el evento lo transporta»; hoy no lo
 * hace).
 *
 * `INV-007`: consume el evento publicado por REQ-CORE, no importa código
 * interno de ese módulo. Hallazgo propio: este *listener* no existía
 * todavía pese a que `SessionRevoker` y `funcional.md §8.2` ya lo daban
 * por consumidor desde 1.2 — se crea aquí, en 1.2b, que es quien primero
 * lo necesita para escribir `baja_usuario`.
 */
final class RevokeSessionsOnUserDeactivated
{
    public function __construct(
        private readonly SessionRevoker $sessionRevoker,
    ) {}

    public function handle(UserDeactivated $event): void
    {
        $user = User::withTrashed()->where('public_id', $event->userPublicId)->first();

        if ($user === null) {
            return;
        }

        $this->sessionRevoker->revokeAllForUser($user, SessionEndReason::BajaUsuario);
    }
}
