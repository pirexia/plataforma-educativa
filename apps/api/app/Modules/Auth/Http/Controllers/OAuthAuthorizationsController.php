<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\OAuthAuthorizationService;
use App\Modules\Auth\Http\Requests\StoreOAuthAuthorizationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * `api.md §E.3`, `POST /auth/oauth-authorizations`. Arranca el flujo:
 * devuelve la URL a la que la SPA debe navegar. Nunca `302` (`§E.4.1`
 * argumenta por qué es la SPA quien navega, no el servidor).
 */
class OAuthAuthorizationsController extends Controller
{
    public function __construct(
        private readonly OAuthAuthorizationService $service,
    ) {}

    public function store(StoreOAuthAuthorizationRequest $request): JsonResponse
    {
        $result = $this->service->begin(
            $request,
            $request->string('provider')->value(),
            $request->string('intent')->value(),
        );

        return response()->json($result, 201);
    }
}
