<?php

namespace App\Modules\Auth\Domain;

/**
 * `datos.md §G.7.2`, `funcional.md §G.4.2` (REQ-AUTH-004, 1.4c). Obtiene y
 * valida los metadatos de un IdP, por URL o por XML pegado. Único punto
 * autorizado, junto a `DiscoveryDocumentValidator`, a que el servidor haga
 * una petición a un destino que indica un administrador de centro.
 */
interface SamlMetadataValidator
{
    /**
     * @throws SamlMetadataValidationException
     */
    public function validateFromUrl(string $metadataUrl): SamlMetadata;

    /**
     * @throws SamlMetadataValidationException
     */
    public function validateFromXml(string $metadataXml): SamlMetadata;
}
