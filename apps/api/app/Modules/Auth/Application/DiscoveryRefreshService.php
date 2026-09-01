<?php

namespace App\Modules\Auth\Application;

use App\Modules\Auth\Domain\DiscoveryDocumentValidator;
use App\Modules\Auth\Domain\DiscoveryValidationException;
use App\Modules\Auth\Domain\Models\IdentityProvider;

/**
 * `funcional.md §F.4.2`, `api.md §F.5`, `operacion.md §F.4`. Revalida el
 * documento de descubrimiento y actualiza los *endpoints* — usado por
 * `POST .../discovery-refreshes` (síncrono) y por
 * `RefreshOidcDiscoveryCommand` (programado). Si falla, conserva los
 * valores anteriores: un emisor momentáneamente inalcanzable no debe
 * dejar sin SSO a un centro cuyo IdP funciona.
 */
final class DiscoveryRefreshService
{
    public function __construct(
        private readonly DiscoveryDocumentValidator $validator,
    ) {}

    /**
     * @throws DiscoveryValidationException
     */
    public function refresh(IdentityProvider $provider): IdentityProvider
    {
        try {
            $document = $this->validator->validate($provider->discovery_url);
        } catch (DiscoveryValidationException $e) {
            $provider->forceFill(['discovery_failed_at' => now()])->save();

            throw $e;
        }

        $provider->fill([
            'issuer' => $document->issuer,
            'authorization_endpoint' => $document->authorizationEndpoint,
            'token_endpoint' => $document->tokenEndpoint,
            'userinfo_endpoint' => $document->userinfoEndpoint,
            'discovery_fetched_at' => now(),
            'discovery_failed_at' => null,
        ]);
        $provider->save();

        return $provider;
    }
}
