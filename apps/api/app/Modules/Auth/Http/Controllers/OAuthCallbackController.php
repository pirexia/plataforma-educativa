<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\GoogleOAuthCallbackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cookie;

/**
 * `api.md §E.4`, `GET /auth/oauth/google/callback`. **La primera
 * excepción real a `ADR-038` del módulo** (`§E.7.1`): nunca responde
 * `problem+json`, nunca devuelve un recurso, siempre `302` — a una ruta
 * de la SPA con, como mucho, un código de resultado de una lista cerrada
 * en la cadena de consulta (`RN-AUTH-93`). Sin CSRF: es una navegación de
 * primer nivel que llega desde un tercero, y su defensa es el `state`
 * (`RN-AUTH-91`), no un token.
 *
 * Las URL de destino son **relativas**: `redirect()->to()` las resuelve
 * contra el host de la petición en curso, que `ResolveTenant` ya validó
 * como el del tenant (`{slug}.{base}`) — no hace falta reconstruir el
 * dominio a mano aquí como sí exige `RN-AUTH-92` para la `redirect_uri`
 * que se le manda a Google.
 */
class OAuthCallbackController extends Controller
{
    /** funcional.md §B.6.2, RN-AUTH-45. Mismo nombre que SessionController. */
    private const DEVICE_COOKIE_NAME = 'pge_device';

    /**
     * `funcional.md §E.9`: pantalla de resultado, nueva desde 1.4. No hay
     * ruta de "destino tras el login" documentada más allá de "la ruta de
     * destino de la SPA" (`api.md §E.4`) — se usa la raíz, la misma que
     * resuelve cualquier navegación anónima del *router* de la SPA hoy.
     */
    private const RESULT_SCREEN_PATH = '/entrar/google';

    private const SUCCESS_PATH = '/';

    public function __construct(
        private readonly GoogleOAuthCallbackService $service,
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
