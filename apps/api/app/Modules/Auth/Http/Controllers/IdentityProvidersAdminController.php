<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\IdentityProviderService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Domain\DiscoveryValidationException;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Http\Requests\StoreIdentityProviderRequest;
use App\Modules\Auth\Http\Requests\UpdateIdentityProviderRequest;
use App\Modules\Auth\Http\Resources\IdentityProviderDetailResource;
use App\Modules\Auth\Http\Resources\IdentityProviderResource;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * `api.md §F.2`-`§F.3`. El catálogo del centro, autoservicio del
 * administrador (`ADR-043 §8.3`). Permisos `proveedor_identidad.*`
 * (`permisos.md §F.3`), aplicados por el middleware `permission:` en
 * `routes.php` (`INV-002`).
 */
class IdentityProvidersAdminController extends Controller
{
    public function __construct(
        private readonly IdentityProviderService $service,
        private readonly RateLimitGuard $rateLimits,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = IdentityProvider::query()
            ->orderBy('display_name')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return PagePaginatedResponse::make($paginator, IdentityProviderResource::class);
    }

    public function show(string $publicId): IdentityProviderDetailResource
    {
        return new IdentityProviderDetailResource($this->findOrFail($publicId));
    }

    public function store(StoreIdentityProviderRequest $request): JsonResponse
    {
        // api.md §F.3: la validación hace una petición saliente y no
        // puede quedar al limitador global.
        $this->rateLimits->hit('sso_discovery_tenant', '');

        try {
            $provider = $this->service->create($request->validated());
        } catch (DiscoveryValidationException $e) {
            throw $this->discoveryException($e);
        }

        return (new IdentityProviderDetailResource($provider))->response()->setStatusCode(201);
    }

    public function update(UpdateIdentityProviderRequest $request, string $publicId): IdentityProviderDetailResource
    {
        $provider = $this->findOrFail($publicId);

        if ($request->filled('discovery_url')) {
            $this->rateLimits->hit('sso_discovery_tenant', '');
        }

        try {
            $provider = $this->service->update($provider, $request->validated());
        } catch (DiscoveryValidationException $e) {
            throw $this->discoveryException($e);
        }

        return new IdentityProviderDetailResource($provider);
    }

    public function destroy(string $publicId): Response
    {
        $this->service->destroy($this->findOrFail($publicId));

        return response()->noContent();
    }

    private function findOrFail(string $publicId): IdentityProvider
    {
        // RN-AUTH-101: un public_id de otro tenant es 404, nunca 403 —
        // la RLS de TenantModel ya lo garantiza sin predicado explícito
        // de tenant (ADR-038 §6.4).
        $provider = IdentityProvider::query()->where('public_id', $publicId)->first();

        if ($provider === null) {
            throw ApiException::notFound();
        }

        return $provider;
    }

    private function discoveryException(DiscoveryValidationException $e): ApiException
    {
        $code = $e->failureCode->value;

        return ApiException::validation([
            'discovery_url' => [[
                'code' => "auth.sso.discovery.{$code}",
                'message' => __("auth.sso.discovery.{$code}"),
                'params' => [],
            ]],
        ]);
    }
}
