<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\MfaChallengeService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Domain\MfaMethod;
use App\Modules\Auth\Http\Requests\StoreMfaChallengeRequest;
use Illuminate\Routing\Controller;

/**
 * api.md §C.1, `POST /auth/mfa-challenges`, `§C.4.4.1`. Sin sesión
 * autenticada — el desafío vive en la sesión anónima (`§C.6`), por eso
 * este endpoint tiene que estar en la lista blanca de `RequireMfaEnrollment`
 * y del propio grupo de rutas: no hay `Auth::user()` que comprobar aquí,
 * `MfaChallengeService` resuelve el titular a partir del `session_id`.
 */
class MfaChallengesController extends Controller
{
    public function __construct(
        private readonly MfaChallengeService $service,
        private readonly RateLimitGuard $rateLimits,
    ) {}

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
