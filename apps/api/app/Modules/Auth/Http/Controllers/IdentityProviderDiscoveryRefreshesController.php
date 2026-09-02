<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\DiscoveryRefreshService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Domain\DiscoveryValidationException;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Http\Resources\IdentityProviderDetailResource;
use App\Support\Api\ApiException;
use Illuminate\Routing\Controller;

/**
 * `api.md §F.5`, `POST /identity-providers/{public_id}/discovery-refreshes`.
 * Síncrono, no encolado (`INV-012` no lo exige: tiempo de espera corto y
 * tope de tamaño, `funcional.md §F.4.2` guarda 4).
 */
class IdentityProviderDiscoveryRefreshesController extends Controller
{
    public function __construct(
        private readonly DiscoveryRefreshService $service,
        private readonly RateLimitGuard $rateLimits,
    ) {}

    public function store(string $publicId): IdentityProviderDetailResource
    {
        // Bucket compartido con el alta (api.md §F.5).
        $this->rateLimits->hit('sso_discovery_tenant', '');

        $provider = IdentityProvider::query()->where('public_id', $publicId)->first();

        if ($provider === null) {
            throw ApiException::notFound();
        }

        try {
            $provider = $this->service->refresh($provider);
        } catch (DiscoveryValidationException $e) {
            $code = $e->failureCode->value;

            throw ApiException::validation([
                'discovery_url' => [[
                    'code' => "auth.sso.discovery.{$code}",
                    'message' => __("auth.sso.discovery.{$code}"),
                    'params' => [],
                ]],
            ]);
        }

        return new IdentityProviderDetailResource($provider);
    }
}
