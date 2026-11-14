<?php

namespace App\Modules\Auth\Domain;

/**
 * `datos.md §F.2`. El resultado de validar un documento de descubrimiento
 * con las cinco guardas de `funcional.md §F.4.2`. Nada más que lo que se
 * guarda en `identity_providers`: sin `jwks_uri` (no se verifica firma,
 * `§F.3.2`) y sin nada que no lea ningún camino de código.
 */
final readonly class DiscoveryDocument
{
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public ?string $userinfoEndpoint,
    ) {}
}
