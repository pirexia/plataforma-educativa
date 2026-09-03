<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\SamlAcsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cookie;

/**
 * `api.md §G.7`, `POST /api/v1/auth/saml/{public_id}/acs`. La ruta más
 * sensible del módulo: la única de la aplicación entera sin `csrf`, en un
 * grupo de rutas propio (`routes/api.php`) con la pila declarada
 * explícitamente. Nunca `problem+json`: siempre `302`, con un código de
 * resultado de la lista cerrada de `§F.7.1` en la cadena de consulta
 * (`RN-AUTH-93`) — salvo el `429` de `RateLimitGuard::hit()`
 * (`operacion.md §G.6`), la única respuesta del *endpoint* que no es un
 * `302` y que se deja propagar tal cual.
 */
class SamlAcsController extends Controller
{
    private const DEVICE_COOKIE_NAME = 'pge_device';

    private const RESULT_SCREEN_PATH = '/entrar/sso';

    private const SUCCESS_PATH = '/';

    public function __construct(
        private readonly SamlAcsService $service,
    ) {}

    public function __invoke(Request $request, string $publicId): RedirectResponse
    {
        $result = $this->service->handle($request, $publicId);

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
