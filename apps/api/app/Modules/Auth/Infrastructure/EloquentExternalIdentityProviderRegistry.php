<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\ExternalIdentityProvider;
use App\Modules\Auth\Domain\ExternalIdentityProviderRegistry;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * `funcional.md §F.3.4`. La única implementación de
 * `ExternalIdentityProviderRegistry`: cualquier proveedor catalogado es
 * hoy OIDC (SAML es `1.4c`), así que siempre construye un
 * `GenericOidcProvider`.
 */
final class EloquentExternalIdentityProviderRegistry implements ExternalIdentityProviderRegistry
{
    public function forProvider(IdentityProvider $provider): ExternalIdentityProvider
    {
        return new GenericOidcProvider($provider, App::make(Request::class), App::make(TenantContext::class));
    }
}
