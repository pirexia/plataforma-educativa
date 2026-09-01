<?php

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\OidcRedirectUri;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * `api.md §F.2`, `GET /identity-providers/{public_id}`. El detalle añade,
 * sobre la colección: los tres *endpoints* descubiertos, `discovery_url`,
 * la lista de credenciales (sin valores) y el bloque `integration` —lo
 * que `ADR-043 §5.2` obliga a publicar para que el administrador
 * configure su IdP.
 */
class IdentityProviderDetailResource extends IdentityProviderResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var IdentityProvider $provider */
        $provider = $this->resource;

        return array_merge(parent::toArray($request), [
            'discovery_url' => $provider->discovery_url,
            'authorization_endpoint' => $provider->authorization_endpoint,
            'token_endpoint' => $provider->token_endpoint,
            'userinfo_endpoint' => $provider->userinfo_endpoint,
            'integration' => [
                'redirect_uri' => OidcRedirectUri::build(app(TenantContext::class)),
                'scopes' => $provider->scopes,
                'subject_claim' => 'sub',
                'email_claim' => $provider->email_claim->value,
            ],
            'secrets' => IdentityProviderSecretResource::collection(
                $provider->secrets()->orderByDesc('activated_at')->get()
            ),
        ]);
    }
}
