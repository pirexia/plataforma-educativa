<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\MfaChallengeService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Domain\MfaMethod;
use App\Modules\Auth\Http\Requests\StoreMfaChallengeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * api.md §C.1, `POST /auth/mfa-challenges`, `§C.4.4.1`. `GET` añadido en
 * REQ-AUTH-002 (1.4, `api.md §E.5b`). Sin sesión autenticada — el
 * desafío vive en la sesión anónima (`§C.6`), por eso este endpoint
 * tiene que estar en la lista blanca de `RequireMfaEnrollment` y del
 * propio grupo de rutas: no hay `Auth::user()` que comprobar aquí,
 * `MfaChallengeService` resuelve el titular a partir del `session_id`.
 */
class MfaChallengesController extends Controller
{
    public function __construct(
        private readonly MfaChallengeService $service,
        private readonly RateLimitGuard $rateLimits,
    ) {}

    /**
     * `GET /auth/mfa-challenges`, api.md §E.5b. Estrictamente de lectura
     * (CA-AUTH-239): sin CSRF (es un GET), sin token nuevo, con
     * Cache-Control: no-store obligatorio — devuelve estado de
     * autenticación con un destino enmascarado dentro.
     */
    public function show(Request $request): JsonResponse
    {
        $this->rateLimits->hit('mfa_challenge_read_session', (string) $request->session()->getId());

        return response()->json($this->service->current($request))
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>
     */
    public function store(StoreMfaChallengeRequest $request)
    {
        $this->rateLimits->hit('mfa_challenge_session', (string) $request->session()->getId());

        $method = MfaMethod::from($request->string('method')->value());

        return $this->service->changeMethod($request, $method);
    }
}
