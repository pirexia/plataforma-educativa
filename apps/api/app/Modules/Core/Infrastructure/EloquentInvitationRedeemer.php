<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Core\Domain\InvitationRedeemer;
use App\Modules\Core\Domain\Models\UserInvitation;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-AUTH/funcional.md §4.1. `UserInvitation` es `TenantModel`: la RLS y
 * el *scope* de tenant ya limitan la búsqueda al tenant activo
 * (RN-AUTH-06/08) sin predicado adicional en esta clase.
 */
final class EloquentInvitationRedeemer implements InvitationRedeemer
{
    public function redeem(string $token): User
    {
        $invitation = UserInvitation::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (
            $invitation === null
            || ! $invitation->isLive()
            || $invitation->user === null
            || $invitation->user->status !== UserStatus::Pendiente
        ) {
            throw ApiException::gone();
        }

        // Issue #182: la lectura de arriba, sin bloqueo, solo sirve para
        // el 410 rápido. Dos canjes concurrentes con el mismo token
        // podían leer ambos isLive() === true antes de que el primero
        // confirmara accepted_at — se repite aquí, bajo lockForUpdate()
        // dentro de una transacción, la única comprobación que de verdad
        // decide si se escribe. Mismo patrón que
        // PlatformAdminInvitationRedemptionService::redeem() (Backoffice).
        return DB::transaction(function () use ($invitation): User {
            $locked = UserInvitation::query()->with('user')->lockForUpdate()->find($invitation->id);

            if (
                $locked === null
                || ! $locked->isLive()
                || $locked->user === null
                || $locked->user->status !== UserStatus::Pendiente
            ) {
                throw ApiException::gone();
            }

            $locked->update(['accepted_at' => now()]);

            return $locked->user;
        });
    }
}
