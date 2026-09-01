<?php

namespace App\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `api.md §F.4`. Nunca el valor de la credencial — ni en la respuesta de
 * alta, ni aquí (`RN-AUTH-112`).
 */
class IdentityProviderSecretResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'activated_at' => $this->activated_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'retired_at' => $this->retired_at?->toISOString(),
        ];
    }
}
