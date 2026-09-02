<?php

namespace App\Modules\Auth\Domain;

/**
 * `datos.md §G.2`-`§G.5` (REQ-AUTH-004, 1.4c). El resultado de validar los
 * metadatos de un IdP con las guardas de `funcional.md §G.4.2`. Lo que se
 * necesita para rellenar `issuer`/`authorization_endpoint` del padre, la
 * hija `saml_identity_provider_settings` y una fila de
 * `identity_provider_certificates` por cada certificado de firma.
 */
final readonly class SamlMetadata
{
    /**
     * @param  list<SamlMetadataCertificate>  $signingCertificates
     */
    public function __construct(
        public string $entityId,
        public string $singleSignOnServiceUrl,
        public SamlNameIdFormat $nameIdFormat,
        public array $signingCertificates,
    ) {}
}
