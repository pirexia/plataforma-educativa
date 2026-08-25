<?php

namespace App\Modules\Auth\Infrastructure;

use App\Models\User;
use App\Modules\Auth\Domain\SessionRevoker;
use Illuminate\Support\Facades\DB;

/**
 * RN-AUTH-22, RN-AUTH-36. `sessions` guarda `user_id` como valor plano (no
 * FK, framework de Laravel) y no tiene `tenant_id` todavía (OPEN-AUTH-10,
 * 1.2b) — filtrar también por `user_id` real, que sí es único por diseño
 * de aplicación (una fila de `users` por tenant), evita tocar sesiones de
 * otro tenant aunque la tabla sea compartida.
 *
 * Solo actúa con `SESSION_DRIVER=database` (operacion.md §2.2: es el
 * único driver que deja algo que revocar). Con cualquier otro driver no
 * hay fila que borrar y la llamada es un no-op silencioso.
 */
final class DatabaseSessionRevoker implements SessionRevoker
{
    public function revokeAllForUser(User $user, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $query = DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id);

        if ($exceptSessionId !== null) {
            $query->where('id', '!=', $exceptSessionId);
        }

        $query->delete();
    }
}
