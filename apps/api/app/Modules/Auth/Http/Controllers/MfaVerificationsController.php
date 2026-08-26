<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\MfaChallengeService;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Http\Requests\StoreMfaVerificationRequest;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cookie;

/**
 * api.md §C.1, `POST /auth/mfa-verifications`, `§C.4.4` puntos 6-11. El
 * paso 2 del login. Sin sesión autenticada (`§C.6`) — verificado por
 * `session_id`, nunca por un token.
 */
class MfaVerificationsController extends Controller
{
    /** funcional.md §B.6.2, RN-AUTH-45. Mismo nombre que SessionController. */
    private const DEVICE_COOKIE_NAME = 'pge_device';

    public function __construct(
        private readonly MfaChallengeService $service,
        private readonly RateLimitGuard $rateLimits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function store(StoreMfaVerificationRequest $request)
    {
        $this->rateLimits->hit('mfa_verification_ip', (string) $request->ip());
        $this->rateLimits->hit('mfa_verification_session', (string) $request->session()->getId());

        $result = $this->service->verify(
            $request,
            $request->string('code')->value() ?: null,
            $request->string('recovery_code')->value() ?: null,
            $request->cookie(self::DEVICE_COOKIE_NAME),
        );

        if ($result->newDeviceCookieValue !== null) {
            $this->queueDeviceCookie($result->newDeviceCookieValue);
        }

        return $result->profile;
    }

    private function queueDeviceCookie(string $rawValue): void
    {
        $ttlMinutes = (int) config('auth-local.device_cookie_ttl_days') * 24 * 60;

        Cookie::queue(Cookie::make(
            name: self::DEVICE_COOKIE_NAME,
            value: $rawValue,
            minutes: $ttlMinutes,
            path: null,
            domain: null,
            secure: (bool) config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }
}
