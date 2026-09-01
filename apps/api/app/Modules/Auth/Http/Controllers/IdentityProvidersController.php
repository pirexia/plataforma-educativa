<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\OAuthProviderAvailability;
use App\Modules\Auth\Application\RateLimitGuard;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * `api.md §E.2`, `GET /auth/identity-providers`. Anónimo, tenant por
 * host. Le dice a la pantalla de login si hay botón que pintar
 * (`RN-AUTH-98`) — nunca por una constante del cliente.
 */
class IdentityProvidersController extends Controller
{
    public function __construct(
        private readonly OAuthProviderAvailability $availability,
        private readonly RateLimitGuard $rateLimits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        // operacion.md §E.6: 60/min por IP, el mismo valor que
        // GET /auth/csrf-cookie — un GET anónimo y barato que la SPA pide
        // en cada carga de la pantalla de login.
        $this->rateLimits->hit('identity_providers_ip', (string) $request->ip());

        // `data: []` con AUTH_OAUTH_DRIVER=none (el valor por defecto) no
        // es un error ni un estado degradado (operacion.md §E.1).
        //
        // Sin `label_key`: se documentó en el diseño inicial pensando en
        // un futuro catálogo multiproveedor (1.4b), pero `ADR-042 §4.3`
        // fija `IdentityProvider` como interfaz de un solo proveedor "a
        // propósito", y el texto del botón lo decide la SPA por
        // `intent` (login/link), no por el proveedor — nunca llegó a
        // consumirlo ningún cliente (hallazgo de `doc-reviewer`, cierre
        // de 1.4). Retirado en vez de dejarlo como superficie muerta
        // documentada como si se usara.
        $data = $this->availability->isConfigured()
            ? [['provider' => 'google']]
            : [];

        return [
            'data' => $data,
            'meta' => ['total' => count($data)],
        ];
    }
}
