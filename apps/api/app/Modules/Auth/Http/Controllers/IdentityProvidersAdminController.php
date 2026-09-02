<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\IdentityProviderService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Application\SamlIdentityProviderAdminService;
use App\Modules\Auth\Domain\DiscoveryValidationException;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Protocol;
use App\Modules\Auth\Domain\SamlMetadataFailureCode;
use App\Modules\Auth\Domain\SamlMetadataValidationException;
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
 * `api.md §F.2`-`§F.3`, ampliado en `§G.2` (REQ-AUTH-004, 1.4c: el
 * discriminador `protocol` gana una rama). El catálogo del centro,
 * autoservicio del administrador (`ADR-043 §8.3`). Permisos
 * `proveedor_identidad.*` (`permisos.md §F.3`, `§G.1`: 1.4c no declara
 * ninguno nuevo), aplicados por el middleware `permission:` en
 * `routes.php` (`INV-002`).
 *
 * Con `protocol = "saml"`, el alta y la edición se delegan en
 * `SamlIdentityProviderAdminService`, hermana de `IdentityProviderService`
 * (`funcional.md §G.4.1`). Los dos servicios son mutuamente exclusivos por
 * fila: no hay lógica de protocolo aquí, solo el enrutado hacia el
 * servicio correcto.
 */
class IdentityProvidersAdminController extends Controller
{
    public function __construct(
        private readonly IdentityProviderService $service,
        private readonly SamlIdentityProviderAdminService $samlService,
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
        $attributes = $request->validated();

        if ($attributes['protocol'] === Protocol::Saml->value) {
            // api.md §G.2: el bucket sso_metadata_tenant solo se aplica
            // cuando el origen es una URL — un XML pegado no genera
            // tráfico saliente contra nadie (§G.6).
            if (isset($attributes['metadata_url'])) {
                $this->rateLimits->hit('sso_metadata_tenant', '');
            }

            try {
                $provider = $this->samlService->create($attributes);
            } catch (SamlMetadataValidationException $e) {
                throw $this->samlMetadataException($e);
            }

            return (new IdentityProviderDetailResource($provider))->response()->setStatusCode(201);
        }

        // api.md §F.3: la validación hace una petición saliente y no
        // puede quedar al limitador global.
        $this->rateLimits->hit('sso_discovery_tenant', '');

        try {
            $provider = $this->service->create($attributes);
        } catch (DiscoveryValidationException $e) {
            throw $this->discoveryException($e);
        }

        return (new IdentityProviderDetailResource($provider))->response()->setStatusCode(201);
    }

    public function update(UpdateIdentityProviderRequest $request, string $publicId): IdentityProviderDetailResource
    {
        $provider = $this->findOrFail($publicId);
        $attributes = $request->validated();

        if ($provider->protocol === Protocol::Saml) {
            if ($request->filled('metadata_url')) {
                $this->rateLimits->hit('sso_metadata_tenant', '');
            }

            try {
                $provider = $this->samlService->update($provider, $attributes);
            } catch (SamlMetadataValidationException $e) {
                throw $this->samlMetadataException($e);
            }

            return new IdentityProviderDetailResource($provider);
        }

        if ($request->filled('discovery_url')) {
            $this->rateLimits->hit('sso_discovery_tenant', '');
        }

        try {
            $provider = $this->service->update($provider, $attributes);
        } catch (DiscoveryValidationException $e) {
            throw $this->discoveryException($e);
        }

        return new IdentityProviderDetailResource($provider);
    }

    public function destroy(string $publicId): Response
    {
        $provider = $this->findOrFail($publicId);

        if ($provider->protocol === Protocol::Saml) {
            $this->samlService->destroy($provider);
        } else {
            $this->service->destroy($provider);
        }

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

    /**
     * `api.md §G.4`: la lista cerrada de códigos de fallo de validación de
     * metadatos es `422`, salvo `emisor_ya_catalogado`, que es un
     * conflicto de estado (`409`) y no un cuerpo mal formado
     * (`UNIQUE (tenant_id, issuer)` entre protocolos, `CA-AUTH-315`).
     */
    private function samlMetadataException(SamlMetadataValidationException $e): ApiException
    {
        $code = $e->failureCode->value;

        if ($e->failureCode === SamlMetadataFailureCode::EmisorYaCatalogado) {
            return ApiException::conflict("auth.saml.metadata.{$code}");
        }

        return ApiException::validation([
            'metadata_url' => [[
                'code' => "auth.saml.metadata.{$code}",
                'message' => __("auth.saml.metadata.{$code}"),
                'params' => [],
            ]],
        ]);
    }
}
