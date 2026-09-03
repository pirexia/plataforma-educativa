<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\SamlIdentityProvider;
use App\Modules\Auth\Domain\SamlIdentityProviderRegistry;
use App\Modules\Auth\Domain\SamlSpUrls;
use App\Support\Tenancy\TenantContext;
use OneLogin\Saml2\Settings as OneLoginSettings;
use RuntimeException;

/**
 * `funcional.md §G.3.6`, `§G.7.2` (REQ-AUTH-004, 1.4c). Construye el
 * `OneLogin\Saml2\Settings` a partir de una fila del catálogo —única
 * pieza de este fichero autorizada a importar `OneLogin\Saml2\*` junto
 * con `OneLoginSamlIdentityProvider` (`CA-AUTH-362`)— y lo entrega
 * envuelto. Hermana exacta de `EloquentExternalIdentityProviderRegistry`.
 *
 * `RN-AUTH-117`: los cuatro indicadores que `ADR-043 §10.2` documenta
 * como la trampa de la biblioteca se fijan aquí, en el único punto que
 * construye un `Settings`, y `CA-AUTH-336` los verifica por reflexión
 * sobre el objeto que este método produce.
 */
final class EloquentSamlIdentityProviderRegistry implements SamlIdentityProviderRegistry
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function forProvider(IdentityProvider $provider): SamlIdentityProvider
    {
        $settings = $provider->samlSettings;

        if ($settings === null) {
            throw new RuntimeException(
                "IdentityProvider {$provider->id} es SAML pero no tiene fila en saml_identity_provider_settings."
            );
        }

        $certificates = $provider->activeCertificates();

        if ($certificates->isEmpty()) {
            // Defensa en profundidad: RN-AUTH-128 exige que el llamador
            // (OAuthAuthorizationService, el ACS) ya haya comprobado esto
            // y respondido en cerrado antes de llegar aquí. Sin al menos
            // un certificado, OneLogin\Saml2\Settings ni siquiera se
            // puede construir (idp_cert_or_fingerprint_not_found_and_required).
            throw new RuntimeException(
                "IdentityProvider {$provider->id} no tiene ningún certificado de firma vigente."
            );
        }

        $spEntityId = SamlSpUrls::entityId($this->tenantContext);
        $acsUrl = SamlSpUrls::acsUrl($this->tenantContext, $provider->public_id);
        $tenantOrigin = $this->tenantOrigin();

        $onelogin = new OneLoginSettings([
            'strict' => true,
            'debug' => false,
            'baseurl' => $tenantOrigin !== '' ? $tenantOrigin : null,
            'sp' => [
                'entityId' => $spEntityId,
                'assertionConsumerService' => [
                    'url' => $acsUrl,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'NameIDFormat' => $settings->name_id_format->urn(),
            ],
            'idp' => [
                'entityId' => $provider->issuer,
                'singleSignOnService' => [
                    'url' => $provider->authorization_endpoint,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509certMulti' => [
                    'signing' => $certificates->map(fn ($certificate) => $certificate->certificate)->values()->all(),
                ],
            ],
            'security' => [
                // RN-AUTH-117, CA-AUTH-336: los cuatro, sin excepción.
                'wantMessagesSigned' => true,
                'wantAssertionsSigned' => true,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                // RN-AUTH-123: la ausencia de NameID la resuelve el
                // llamador (sin_cuenta), no una excepción de la
                // biblioteca.
                'wantNameId' => false,
                'wantNameIdEncrypted' => false,
                'wantAssertionsEncrypted' => false,
                // OPEN-AUTH-46: EncryptedAssertion no se soporta en 1.4c.
                'requestedAuthnContext' => false,
                'signMetadata' => false,
                'authnRequestsSigned' => false,
            ],
        ]);

        return new OneLoginSamlIdentityProvider(
            settings: $onelogin,
            spEntityId: $spEntityId,
            acsUrl: $acsUrl,
            idpSsoUrl: $provider->authorization_endpoint,
            nameIdFormat: $settings->name_id_format,
            signAuthnRequests: $settings->sign_authn_requests,
            spSigningKeyPem: $this->platformSigningKeyPem(),
            tenantOrigin: $tenantOrigin,
            emailAttribute: $settings->email_attribute,
        );
    }

    /**
     * `funcional.md §G.3.7`. La clave de plataforma vive en un fichero
     * montado, nunca en base de datos (`OPEN-AUTH-44`). `''` ⇒ ninguna
     * clave configurada; el servicio de administración ya impide activar
     * `sign_authn_requests` sin ella (`RN-AUTH-128`), así que llegar aquí
     * sin fichero es, como mucho, un proveedor que quedó mal configurado
     * entre la comprobación y el uso — se firma nada, no se lanza.
     */
    private function platformSigningKeyPem(): ?string
    {
        $path = (string) config('auth-local.saml.sp_signing_key_path');

        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents !== false && $contents !== '' ? $contents : null;
    }

    /**
     * `funcional.md §G.3.5`: `scheme://host[:port]` del tenant ya
     * resuelto, sin ruta. `Utils::setBaseURL()` solo usa esta parte.
     */
    private function tenantOrigin(): string
    {
        $acsUrl = SamlSpUrls::acsUrl($this->tenantContext, 'x');
        $parts = parse_url($acsUrl);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
