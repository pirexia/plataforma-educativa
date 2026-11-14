<?php

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Protocol;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `api.md §F.2`, ampliado en `§G.2` (REQ-AUTH-004, 1.4c: `protocol` entra
 * en el contrato). Vale para la colección y para el detalle (el detalle
 * añade campos por encima de este vía `IdentityProviderDetailResource`).
 *
 * **Ningún campo nuevo de este paso es un secreto** (`api.md §G.1`): la
 * clave privada de firma del SP no vive en esta entidad, y el certificado
 * del IdP es material público que sí sale entero en el detalle
 * (`IdentityProviderCertificateResource`). **Nunca la credencial OIDC**,
 * ni siquiera enmascarada (`RN-AUTH-112`): `secret_status` es un resumen,
 * hermano exacto de `certificate_status` para SAML.
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
        /** @var IdentityProvider $provider */
        $provider = $this->resource;

        $common = [
            'public_id' => $provider->public_id,
            'display_name' => $provider->display_name,
            // datos.md §G.0.3 desviación 2 / api.md §G.1 punto 1: campo
            // nuevo en la colección y en el detalle, inmutable tras el
            // alta (RN-AUTH-114, CA-AUTH-316).
            'protocol' => $provider->protocol->value,
            'issuer' => $provider->issuer,
            'is_enabled' => $provider->is_enabled,
            'provisioning_mode' => $provider->provisioning_mode->value,
            'allowed_email_domains' => $provider->allowed_email_domains,
        ];

        if ($provider->protocol === Protocol::Saml) {
            return $common + [
                // api.md §G.2: hermano exacto de secret_status. Es lo que
                // la pantalla necesita para el aviso de caducidad
                // (funcional.md §G.9) sin pedir el detalle de cada fila.
                'certificate_status' => $this->certificateStatus($provider),
            ];
        }

        return $common + [
            'client_id' => $provider->client_id,
            'claims_source' => $provider->claims_source->value,
            'email_claim' => $provider->email_claim->value,
            'scopes' => $provider->scopes,
            'discovery_fetched_at' => $provider->discovery_fetched_at->toISOString(),
            'discovery_failed_at' => $provider->discovery_failed_at?->toISOString(),
            'secret_status' => $this->secretStatus($provider),
        ];
    }

    /**
     * @return array{has_active: bool, active_expires_at: ?string, expiring_soon: bool}
     */
    protected function secretStatus(IdentityProvider $provider): array
    {
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

    /**
     * `api.md §G.2`: `{"vigentes": n, "proximo_vencimiento": "<fecha o null>"}`.
     *
     * @return array{vigentes: int, proximo_vencimiento: ?string}
     */
    protected function certificateStatus(IdentityProvider $provider): array
    {
        $active = $provider->activeCertificates();

        return [
            'vigentes' => $active->count(),
            'proximo_vencimiento' => $active->min('not_after')?->toISOString(),
        ];
    }
}
