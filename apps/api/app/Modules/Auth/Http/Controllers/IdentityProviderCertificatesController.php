<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\IdentityProviderCertificateService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Http\Requests\StoreIdentityProviderCertificateRequest;
use App\Modules\Auth\Http\Resources\IdentityProviderCertificateResource;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * `api.md §G.5`. Carga y retirada de un certificado de firma del IdP.
 * Ningún método de esta clase devuelve el PEM completo en la respuesta
 * del alta (`api.md §G.5`: "el cliente acaba de enviarlo").
 */
class IdentityProviderCertificatesController extends Controller
{
    public function __construct(
        private readonly IdentityProviderCertificateService $service,
        private readonly RateLimitGuard $rateLimits,
    ) {}

    public function store(StoreIdentityProviderCertificateRequest $request, string $providerPublicId): JsonResponse
    {
        $this->rateLimits->hit('sso_certificate_tenant', '');

        $provider = $this->findProviderOrFail($providerPublicId);

        $certificate = $this->service->store($provider, $request->string('certificate')->value());

        return (new IdentityProviderCertificateResource($certificate))->response()->setStatusCode(201);
    }

    public function destroy(string $providerPublicId, string $certificatePublicId): Response
    {
        $provider = $this->findProviderOrFail($providerPublicId);

        // permisos.md §G.4 punto 2 (por analogía con §F.5): se busca por
        // su proveedor padre en la misma consulta — un certificado de
        // otro proveedor del mismo tenant presentado bajo el public_id
        // equivocado es 404, no una retirada ajena.
        $certificate = $provider->certificates()
            ->where('public_id', $certificatePublicId)
            ->whereNull('retired_at')
            ->first();

        if ($certificate === null) {
            throw ApiException::notFound();
        }

        $this->service->retire($provider, $certificate);

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
