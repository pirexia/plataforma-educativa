<?php

namespace App\Modules\Core\Http\Resources;

use App\Modules\Core\Domain\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §4. `status` es derivado (UserInvitation::status()), nunca una
 * columna. El token nunca aparece (RN-CORE-19).
 *
 * @mixin UserInvitation
 */
class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'user' => [
                'public_id' => $this->user->public_id,
                'email' => $this->user->email,
            ],
            'status' => $this->status(),
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
            'accepted_at' => $this->accepted_at,
            'revoked_at' => $this->revoked_at,
        ];
    }
}
