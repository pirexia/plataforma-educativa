<?php

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Auth\Domain\Models\UserMfaExemption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md §D.4. Recurso común a los tres endpoints de `/mfa-exemptions`.
 * `state` es derivado (`live`/`expired`/`revoked`), nunca una columna.
 * `user`/`granted_by` llevan solo campos públicos — nunca secretos, ni
 * estado de factores, ni recuento de códigos de respaldo.
 *
 * @mixin UserMfaExemption
 */
class MfaExemptionResource extends JsonResource
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
                'given_name' => $this->user->person->given_name ?? '',
                'family_name_1' => $this->user->person->family_name_1 ?? '',
                'family_name_2' => $this->user->person->family_name_2 ?? '',
                'email' => $this->user->email,
            ],
            'reason' => $this->reason,
            'expires_at' => $this->expires_at->toISOString(),
            'state' => $this->state(),
            // api.md §D.4: `granted_at` es `created_at` renombrado.
            'granted_by' => [
                'public_id' => $this->grantedByUser->public_id,
                'given_name' => $this->grantedByUser->person->given_name ?? '',
                'family_name_1' => $this->grantedByUser->person->family_name_1 ?? '',
            ],
            'granted_at' => $this->created_at->toISOString(),
            'revoked_by' => $this->revokedByUser === null ? null : [
                'public_id' => $this->revokedByUser->public_id,
                'given_name' => $this->revokedByUser->person->given_name ?? '',
                'family_name_1' => $this->revokedByUser->person->family_name_1 ?? '',
            ],
            'revoked_at' => $this->revoked_at?->toISOString(),
        ];
    }

    /**
     * api.md §D.4: `live` (`revoked_at IS NULL` y `expires_at > ahora`),
     * `expired` (`revoked_at IS NULL` y `expires_at <= ahora`), `revoked`
     * (`revoked_at` informado).
     */
    private function state(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        return now()->lessThan($this->expires_at) ? 'live' : 'expired';
    }
}
