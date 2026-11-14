<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Application\SamlMetadataRefreshService;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Protocol;
use App\Modules\Auth\Domain\SamlMetadataSource;
use App\Modules\Auth\Domain\SamlMetadataValidationException;
use App\Modules\Auth\Http\Resources\IdentityProviderDetailResource;
use App\Support\Api\ApiException;
use Illuminate\Routing\Controller;

/**
 * `api.md §G.4`, `POST /identity-providers/{public_id}/metadata-refreshes`.
 * Hermano exacto de `IdentityProviderDiscoveryRefreshesController`. Es un
 * `POST` que crea un refresco, no un `PATCH`: la operación es un hecho,
 * no una edición.
 */
class SamlMetadataRefreshesController extends Controller
{
    public function __construct(
        private readonly SamlMetadataRefreshService $service,
        private readonly RateLimitGuard $rateLimits,
    ) {}

    public function store(string $publicId): IdentityProviderDetailResource
    {
        // Bucket compartido con el alta (api.md §G.4).
        $this->rateLimits->hit('sso_metadata_tenant', '');

        $provider = IdentityProvider::query()->where('public_id', $publicId)->first();

        if ($provider === null) {
            throw ApiException::notFound();
        }

        if ($provider->protocol !== Protocol::Saml || $provider->samlSettings?->metadata_source !== SamlMetadataSource::Url) {
            // api.md §G.4: sobre un proveedor de origen XML, o que no sea
            // SAML, no hay nada que refrescar.
            throw ApiException::conflict('auth.saml.metadata_refresh_not_applicable');
        }

        try {
            $provider = $this->service->refresh($provider);
        } catch (SamlMetadataValidationException $e) {
            $code = $e->failureCode->value;

            throw ApiException::validation([
                'metadata_url' => [[
                    'code' => "auth.saml.metadata.{$code}",
                    'message' => __("auth.saml.metadata.{$code}"),
                    'params' => [],
                ]],
            ]);
        }

        return new IdentityProviderDetailResource($provider);
    }
}
