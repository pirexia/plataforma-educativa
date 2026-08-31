<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\ExternalIdentityProvider;
use App\Support\Api\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * `funcional.md §E.4.1`, `api.md §E.3`. Arranca el flujo: `POST
 * /auth/oauth-authorizations`. Anónimo con `intent = 'login'`; por
 * identidad del portador con `intent = 'link'` (`permisos.md §E.2`).
 */
final class OAuthAuthorizationService
{
    /**
     * `funcional.md §E.4.1` punto 3.3, `ADR-042 §4.3`: `intent` no forma
     * parte de la interfaz `ExternalIdentityProvider::beginAuthorization()`
     * —fijada por el ADR, sin parámetros— así que viaja en una clave de
     * sesión propia de este servicio, separada de la que gestiona cada
     * adaptador (`pge_oauth_state`) para el `state`/PKCE.
     */
    private const INTENT_SESSION_KEY = 'pge_oauth_intent';

    public function __construct(
        private readonly RateLimitGuard $rateLimits,
        private readonly OAuthProviderAvailability $availability,
        private readonly ExternalIdentityProvider $provider,
    ) {}

    /**
     * @return array{authorization_url: string, expires_at: string}
     *
     * @throws ApiException validation() (422), tooManyRequests() (429)
     */
    public function begin(Request $request, string $provider, string $intent): array
    {
        $this->rateLimits->hit('oauth_start_ip', (string) $request->ip());

        // operacion.md §E.1, issue #140: AUTH_OAUTH_DRIVER=none (valor
        // por defecto) es el estado normal de cualquier despliegue que no
        // quiera Google — 422 de negocio, no una comprobación de forma.
        if (! $this->availability->isConfigured()) {
            $this->fail('provider', 'oauth_provider_not_configured');
        }

        // El único proveedor soportado hoy (ADR-042 §4.3 último párrafo);
        // la forma del cuerpo ya la restringe StoreOAuthAuthorizationRequest.
        if ($provider !== 'google') {
            $this->fail('provider', 'oauth_provider_not_configured');
        }

        $user = Auth::user();

        if ($intent === 'link' && ! $user instanceof User) {
            $this->fail('intent', 'oauth_intent_requires_session');
        }

        $ttlMinutes = (int) config('auth-local.oauth.state_ttl_minutes');

        // funcional.md §E.4.1 punto 3.3: intent guardado junto al state,
        // en el mismo payload de sesión cifrado — nunca en una cookie
        // propia ni en localStorage (RN-AUTH-28).
        $request->session()->put(self::INTENT_SESSION_KEY, $intent);

        $authorizationUrl = $this->provider->beginAuthorization();

        return [
            'authorization_url' => $authorizationUrl,
            'expires_at' => now()->addMinutes($ttlMinutes)->toISOString(),
        ];
    }

    /**
     * `GoogleOAuthCallbackService` consume y retira el marcador en el
     * mismo movimiento —de un solo uso, igual que el resto del *payload*
     * transitorio de este paso (`state`, PKCE).
     */
    public static function pullIntent(Request $request): ?string
    {
        $intent = $request->session()->pull(self::INTENT_SESSION_KEY);

        return in_array($intent, ['login', 'link'], true) ? $intent : null;
    }

    private function fail(string $field, string $key): never
    {
        throw ApiException::validation([
            $field => [[
                'code' => "auth.validation.{$key}",
                'message' => __("auth.validation.{$key}"),
                'params' => [],
            ]],
        ]);
    }
}
