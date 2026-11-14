<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Application\OAuthProviderAvailability;
use App\Modules\Auth\Application\RateLimitGuard;
use App\Modules\Auth\Domain\IdentityProviderDirectory;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * `api.md §E.2`, ampliado por `api.md §F.6` (1.4b). Anónimo, tenant por
 * host. Le dice a la pantalla de login qué botones pintar (`RN-AUTH-98`)
 * — nunca por una constante del cliente. La colección deja de ser «cero
 * o uno» (1.4) y pasa a ser **`N`**: el *driver* global de Google, si lo
 * hay, más los proveedores catalogados y activos del tenant.
 */
class IdentityProvidersController extends Controller
{
    public function __construct(
        private readonly OAuthProviderAvailability $availability,
        private readonly RateLimitGuard $rateLimits,
        private readonly IdentityProviderDirectory $identityProviders,
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

        // `data: []` con AUTH_OAUTH_DRIVER=none y catálogo vacío (el
        // estado de todos los tenants el día del despliegue) no es un
        // error ni un estado degradado (operacion.md §F.1).
        $data = [];

        if ($this->availability->isConfigured()) {
            // Sin `display_name_key`: el driver global sigue sin traer
            // texto, su etiqueta la resuelve la SPA con su propio
            // catálogo de cuatro idiomas (api.md §F.6, hallazgo de
            // doc-reviewer en 1.4 conservado).
            $data[] = ['id' => 'google', 'display_name_key' => 'auth.provider.google'];
        }

        foreach ($this->identityProviders->activeCatalog() as $provider) {
            /** @var IdentityProvider $provider */
            $data[] = ['id' => $provider->public_id, 'display_name' => $provider->display_name];
        }

        return [
            'data' => $data,
            'meta' => ['total' => count($data)],
        ];
    }
}
