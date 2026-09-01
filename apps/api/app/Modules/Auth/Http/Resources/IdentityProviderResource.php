<?php

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Auth\Domain\Models\IdentityProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `api.md §F.2`. Vale para la colección y para el detalle (el detalle
 * añade `discovery_url` y los tres *endpoints* — `IdentityProviderDetailResource`
 * los suma por encima de este). **Nunca la credencial**, ni siquiera
 * enmascarada (`RN-AUTH-112`): `secret_status` es un resumen.
 *
 * @mixin IdentityProvider
 */
class IdentityProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'display_name' => $this->display_name,
            'issuer' => $this->issuer,
            'client_id' => $this->client_id,
            'is_enabled' => $this->is_enabled,
            'provisioning_mode' => $this->provisioning_mode->value,
            'claims_source' => $this->claims_source->value,
            'email_claim' => $this->email_claim->value,
            'scopes' => $this->scopes,
            'allowed_email_domains' => $this->allowed_email_domains,
            'discovery_fetched_at' => $this->discovery_fetched_at?->toISOString(),
            'discovery_failed_at' => $this->discovery_failed_at?->toISOString(),
            'secret_status' => $this->secretStatus(),
        ];
    }

    /**
     * @return array{has_active: bool, active_expires_at: ?string, expiring_soon: bool}
     */
    protected function secretStatus(): array
    {
        /** @var IdentityProvider $provider */
        $provider = $this->resource;
        $active = $provider->activeSecret();

        if ($active === null) {
            return ['has_active' => false, 'active_expires_at' => null, 'expiring_soon' => false];
        }

        $warningDays = (int) config('auth-local.sso.secret_expiry_warning_days');
        $expiringSoon = $active->expires_at !== null
            && now()->addDays($warningDays)->greaterThanOrEqualTo($active->expires_at);

        return [
            'has_active' => true,
            'active_expires_at' => $active->expires_at?->toISOString(),
            'expiring_soon' => $expiringSoon,
        ];
    }
}
