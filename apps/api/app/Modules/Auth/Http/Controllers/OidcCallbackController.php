<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\OidcCallbackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cookie;

/**
 * `api.md §F.7`, `GET /api/v1/auth/oauth/oidc/callback`. Una sola ruta
 * para todos los proveedores catalogados del tenant (`funcional.md
 * §F.3.1`). Misma excepción a `ADR-038` que `OAuthCallbackController`:
 * nunca `problem+json`, siempre `302` con un código de resultado de una
 * lista cerrada (`RN-AUTH-93`). Sin CSRF: la defensa es el `state`
 * (`RN-AUTH-91`) y ahora también el `nonce` (`RN-AUTH-104`).
 */
class OidcCallbackController extends Controller
{
    private const DEVICE_COOKIE_NAME = 'pge_device';

    private const RESULT_SCREEN_PATH = '/entrar/sso';

    private const SUCCESS_PATH = '/';

    public function __construct(
        private readonly OidcCallbackService $service,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $result = $this->service->handle($request);

        if ($result->newDeviceCookieValue !== null) {
            $this->queueDeviceCookie($result->newDeviceCookieValue);
        }

        if ($result->outcome === null) {
            return redirect()->to(self::SUCCESS_PATH);
        }

        return redirect()->to(self::RESULT_SCREEN_PATH.'?resultado='.$result->outcome->value);
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
