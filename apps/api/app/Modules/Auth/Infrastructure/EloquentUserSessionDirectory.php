<?php

namespace App\Modules\Auth\Infrastructure;

use App\Models\User;
use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Auth\Domain\UserSessionDirectory;
use Illuminate\Database\Eloquent\Collection;

/**
 * funcional.md §B.8.3. Sin consumidor propio en 1.2b — se expone para que
 * 1.6 (soporte de plataforma) no tenga que abrir el modelo del módulo
 * (`INV-007`).
 */
final class EloquentUserSessionDirectory implements UserSessionDirectory
{
    /**
     * @return Collection<int, UserSession>
     */
    public function liveSessionsForUser(User $user): Collection
    {
        return UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->get();
    }
}
