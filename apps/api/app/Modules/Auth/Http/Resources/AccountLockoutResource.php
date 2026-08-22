<?php

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Auth\Domain\Models\AccountLockout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §5, `GET /account-lockouts`. `user` es `null` en un bloqueo
 * fantasma (RN-AUTH-15). Ni `unlock_token_hash` ni ningún material de
 * token aparece nunca aquí.
 *
 * @mixin AccountLockout
 */
class AccountLockoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'email' => $this->email,
            'user' => $this->user === null ? null : [
                'public_id' => $this->user->public_id,
                'email' => $this->user->email,
            ],
            'status' => $this->status(),
            'failed_count' => $this->failed_count,
            'locked_at' => $this->locked_at,
            'unlocked_at' => $this->unlocked_at,
            'unlocked_by' => $this->unlockedByUser?->public_id,
        ];
    }
}
