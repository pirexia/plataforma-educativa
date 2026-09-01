<?php

namespace App\Modules\Auth\Domain;

use App\Modules\Auth\Domain\Models\IdentityProvider;

/**
 * `funcional.md §F.3.4`, `ADR-042 §4.3`. Construye un `ExternalIdentityProvider`
 * ya parametrizado a partir de una fila del catálogo. Es el punto donde
 * la configuración del tenant se convierte en un proveedor utilizable, y
 * el único sitio del código que sabe de dónde salió esa configuración.
 * `ExternalIdentityProvider::beginAuthorization()`/`completeAuthorization()`
 * no cambia de firma.
 */
interface ExternalIdentityProviderRegistry
{
    public function forProvider(IdentityProvider $provider): ExternalIdentityProvider;
}
