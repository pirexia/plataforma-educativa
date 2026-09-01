<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\IdentityProviderSecretService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Http\Requests\StoreIdentityProviderSecretRequest;
use App\Modules\Auth\Http\Resources\IdentityProviderSecretResource;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * `api.md §F.4`. Carga y retirada de la credencial de cliente. Ningún
 * método de esta clase la devuelve (`RN-AUTH-112`). Sin permiso propio
 * (`permisos.md §F.4`): las dos viajan con `proveedor_identidad.actualizar`.
 */
class IdentityProviderSecretsController extends Controller
{
    public function __construct(
        private readonly IdentityProviderSecretService $service,
        private readonly RateLimitGuard $rateLimits,
    ) {}

    public function store(StoreIdentityProviderSecretRequest $request, string $providerPublicId): JsonResponse
    {
        // operacion.md §F.6: la clave es (tenant_id) — ya la aporta
        // rateLimitKey(), sin sufijo de IP ni de sesión.
        $this->rateLimits->hit('sso_secret_tenant', '');

        $provider = $this->findProviderOrFail($providerPublicId);

        $expiresAt = $request->filled('expires_at') ? Carbon::parse($request->string('expires_at')->value()) : null;

        $secret = $this->service->store($provider, $request->string('client_secret')->value(), $expiresAt);

        return (new IdentityProviderSecretResource($secret))->response()->setStatusCode(201);
    }

    public function destroy(string $providerPublicId, string $secretPublicId): Response
    {
        $provider = $this->findProviderOrFail($providerPublicId);

        // permisos.md §F.5 punto 2: se busca por su proveedor padre en la
        // misma consulta — una credencial de otro proveedor del mismo
        // tenant presentada bajo el public_id equivocado es 404, no un
        // borrado ajeno.
        $secret = $provider->secrets()
            ->where('public_id', $secretPublicId)
            ->whereNull('retired_at')
            ->first();

        if ($secret === null) {
            throw ApiException::notFound();
        }

        $this->service->retire($provider, $secret);

        return response()->noContent();
    }

    private function findProviderOrFail(string $publicId): IdentityProvider
    {
        $provider = IdentityProvider::query()->where('public_id', $publicId)->first();

        if ($provider === null) {
            throw ApiException::notFound();
        }

        return $provider;
    }
}
