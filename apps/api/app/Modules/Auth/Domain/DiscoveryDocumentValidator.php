<?php

namespace App\Modules\Auth\Domain;

/**
 * `funcional.md §F.4.2`, `RN-AUTH-113`. Descarga y valida el documento de
 * descubrimiento de un emisor OIDC con las cinco guardas contra SSRF.
 * Único punto del sistema autorizado a que el servidor haga una petición
 * HTTP a un destino que indica un administrador de centro — `api.md
 * §F.9.4`.
 */
interface DiscoveryDocumentValidator
{
    /**
     * @throws DiscoveryValidationException
     */
    public function validate(string $discoveryUrl): DiscoveryDocument;
}
