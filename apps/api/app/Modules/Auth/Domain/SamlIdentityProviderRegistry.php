<?php

namespace App\Modules\Auth\Domain;

use App\Modules\Auth\Domain\Models\IdentityProvider;

/**
 * `funcional.md §G.3.6`, `§G.7.2` (REQ-AUTH-004, 1.4c). Construye un
 * `SamlIdentityProvider` ya parametrizado a partir de una fila del
 * catálogo. Hermana exacta de `ExternalIdentityProviderRegistry`.
 */
interface SamlIdentityProviderRegistry
{
    public function forProvider(IdentityProvider $provider): SamlIdentityProvider;
}
