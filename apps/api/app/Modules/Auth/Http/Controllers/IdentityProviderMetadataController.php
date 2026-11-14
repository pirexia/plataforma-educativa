<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Protocol;
use App\Modules\Auth\Domain\SamlSpUrls;
use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * `api.md §G.3`, `GET /identity-providers/{public_id}/metadata`. Nuestros
 * metadatos de SP — lo que el administrador registra en su IdP. **No es
 * anónimo** (`§G.3.1`): publicarlo sin sesión publicaría el mapa de
 * integración del centro. Permiso `proveedor_identidad.leer`.
 */
class IdentityProviderMetadataController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function show(Request $request, string $publicId): Response|JsonResponse
    {
        $provider = IdentityProvider::query()
            ->where('public_id', $publicId)
            ->where('protocol', Protocol::Saml)
            ->first();

        // §G.3: 404 si el public_id no resuelve, es de otro tenant, está
        // borrado o es un proveedor OIDC — que no tiene metadatos de SP
        // que publicar.
        if ($provider === null) {
            throw ApiException::notFound();
        }

        $settings = $provider->samlSettings;
        $entityId = SamlSpUrls::entityId($this->tenantContext);
        $acsUrl = SamlSpUrls::acsUrl($this->tenantContext, $provider->public_id);
        $nameIdFormatUrn = $settings->name_id_format->urn();

        $spCertPem = $settings->sign_authn_requests ? $this->platformSigningCertPem() : null;

        if ($request->accepts('application/json') && ! $request->accepts('application/samlmetadata+xml')) {
            return response()->json([
                'entity_id' => $entityId,
                'assertion_consumer_service_url' => $acsUrl,
                'name_id_format' => $nameIdFormatUrn,
                'certificate' => $spCertPem !== null ? $this->stripPem($spCertPem) : null,
            ]);
        }

        $keyDescriptor = $spCertPem !== null
            ? '<md:KeyDescriptor use="signing"><ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:X509Data><ds:X509Certificate>'.$this->stripPem($spCertPem).'</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>'
            : '';

        $entityIdAttr = htmlspecialchars($entityId, ENT_QUOTES);
        $acsUrlAttr = htmlspecialchars($acsUrl, ENT_QUOTES);
        $nameIdFormatAttr = htmlspecialchars($nameIdFormatUrn, ENT_QUOTES);

        $xml = <<<XML
            <?xml version="1.0"?>
            <md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{$entityIdAttr}">
              <md:SPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol" AuthnRequestsSigned="{$this->boolAttr($settings->sign_authn_requests)}" WantAssertionsSigned="true">
                {$keyDescriptor}
                <md:NameIDFormat>{$nameIdFormatAttr}</md:NameIDFormat>
                <md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="{$acsUrlAttr}" index="0" isDefault="true"/>
              </md:SPSSODescriptor>
            </md:EntityDescriptor>
            XML;

        return response($xml, 200)->header('Content-Type', 'application/samlmetadata+xml');
    }

    private function boolAttr(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private function platformSigningCertPem(): ?string
    {
        $path = (string) config('auth-local.saml.sp_signing_cert_path');

        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents !== false && $contents !== '' ? $contents : null;
    }

    private function stripPem(string $pem): string
    {
        return trim((string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem));
    }
}
