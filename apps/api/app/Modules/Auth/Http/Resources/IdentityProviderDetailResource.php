<?php

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\OidcRedirectUri;
use App\Modules\Auth\Domain\Protocol;
use App\Modules\Auth\Domain\SamlMetadataSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * `api.md §F.2`, `§G.2`, `GET /identity-providers/{public_id}`. El detalle
 * añade, sobre la colección, los campos propios de cada protocolo.
 *
 * **OIDC**: los tres *endpoints* descubiertos, `discovery_url`, la lista
 * de credenciales (sin valores) y el bloque `integration`.
 *
 * **SAML** (`§G.2`): `metadata_source`, `metadata_url` (o `null`),
 * `name_id_format`, `email_attribute`, `sign_authn_requests`,
 * `metadata_fetched_at`, `metadata_failed_at`, y la lista de certificados.
 * `metadata_xml` **solo si `metadata_source = "xml"`** — es el único caso
 * en que el administrador lo pegó y puede querer revisarlo; cuando el
 * origen es una URL es un artefacto descargado que nadie va a leer en una
 * pantalla.
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

        $base = array_merge(parent::toArray($request), [
            // El mismo dato del padre en los dos protocolos (datos.md
            // §G.0.3 desviación 2): la URL a la que se envía el navegador
            // para autenticarse.
            'authorization_endpoint' => $provider->authorization_endpoint,
        ]);

        if ($provider->protocol === Protocol::Saml) {
            return array_merge($base, $this->samlDetail($provider));
        }

        return array_merge($base, [
            'discovery_url' => $provider->discovery_url,
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

    /**
     * @return array<string, mixed>
     */
    private function samlDetail(IdentityProvider $provider): array
    {
        $settings = $provider->samlSettings;

        return [
            'metadata_source' => $settings->metadata_source->value,
            'metadata_url' => $settings->metadata_url,
            'metadata_xml' => $settings->metadata_source === SamlMetadataSource::Xml ? $settings->metadata_xml : null,
            'name_id_format' => $settings->name_id_format->value,
            'email_attribute' => $settings->email_attribute,
            'sign_authn_requests' => $settings->sign_authn_requests,
            'metadata_fetched_at' => $settings->metadata_fetched_at?->toISOString(),
            'metadata_failed_at' => $settings->metadata_failed_at?->toISOString(),
            // datos.md §G.5, §G.10: un certificado retirado es borrado
            // lógico además de `retired_at` (api.md §G.5, a diferencia de
            // las credenciales OIDC) pero es "fila permanente" — traza de
            // qué certificado estuvo vigente en qué ventana. withTrashed()
            // para que siga saliendo en el detalle con su retired_at.
            'certificates' => IdentityProviderCertificateResource::collection(
                $provider->certificates()->withTrashed()->orderByDesc('not_before')->get()
            ),
        ];
    }
}
