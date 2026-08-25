<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Core\Domain\InvitationRedeemer;
use App\Modules\Core\Domain\Models\UserInvitation;
use App\Support\Api\ApiException;

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

        $invitation->update(['accepted_at' => now()]);

        return $invitation->user;
    }
}
