<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\ExternalIdentityProvider;
use App\Modules\Auth\Domain\ExternalIdentityProviderRegistry;
use App\Modules\Auth\Domain\IdentityProviderDirectory;
use App\Support\Api\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * `funcional.md §E.4.1`, `api.md §E.3`, ampliado por `funcional.md
 * §F.4.3` (1.4b). Arranca el flujo: `POST /auth/oauth-authorizations`.
 * Anónimo con `intent = 'login'`; por identidad del portador con
 * `intent = 'link'` (`permisos.md §E.2`).
 *
 * `provider` acepta ahora **o** el literal `"google"` (*driver* global de
 * 1.4, sin cambios) **o** el `public_id` de un proveedor catalogado
 * (1.4b) — un identificador opaco que la SPA copia de
 * `GET /auth/identity-providers` sin interpretarlo (`api.md §F.6`).
 */
final class OAuthAuthorizationService
{
    /**
     * `funcional.md §E.4.1` punto 3.3, `ADR-042 §4.3`: `intent` no forma
     * parte de la interfaz `ExternalIdentityProvider::beginAuthorization()`
     * —fijada por el ADR, sin parámetros— así que viaja en una clave de
     * sesión propia de este servicio, separada de la que gestiona cada
     * adaptador (`pge_oauth_state`/`pge_oidc_state`) para el `state`/PKCE.
     * Compartida por los dos protocolos: solo puede haber un flujo en
     * curso a la vez en una sesión.
     */
    private const INTENT_SESSION_KEY = 'pge_oauth_intent';

    /**
     * `RN-AUTH-103` (1.4b): el proveedor de un *callback* institucional
     * se resuelve desde el *payload* de la sesión, jamás desde la URL.
     * Guarda el identificador **interno** (`bigint`), nunca el `public_id`.
     */
    private const OIDC_PROVIDER_SESSION_KEY = 'pge_oidc_provider_id';

    public function __construct(
        private readonly RateLimitGuard $rateLimits,
        private readonly OAuthProviderAvailability $availability,
        private readonly ExternalIdentityProvider $provider,
        private readonly IdentityProviderDirectory $identityProviders,
        private readonly ExternalIdentityProviderRegistry $registry,
    ) {}

    /**
     * @return array{authorization_url: string, expires_at: string}
     *
     * @throws ApiException validation() (422), tooManyRequests() (429)
     */
    public function begin(Request $request, string $provider, string $intent): array
    {
        $this->rateLimits->hit('oauth_start_ip', (string) $request->ip());

        $user = Auth::user();

        if ($intent === 'link' && ! $user instanceof User) {
            $this->fail('intent', 'oauth_intent_requires_session');
        }

        $externalProvider = $provider === 'google'
            ? $this->beginGoogle($request)
            : $this->beginCatalog($request, $provider);

        $ttlMinutes = (int) config('auth-local.oauth.state_ttl_minutes');

        // funcional.md §E.4.1 punto 3.3: intent guardado junto al state,
        // en el mismo payload de sesión cifrado — nunca en una cookie
        // propia ni en localStorage (RN-AUTH-28).
        $request->session()->put(self::INTENT_SESSION_KEY, $intent);

        $authorizationUrl = $externalProvider->beginAuthorization();

        return [
            'authorization_url' => $authorizationUrl,
            'expires_at' => now()->addMinutes($ttlMinutes)->toISOString(),
        ];
    }

    private function beginGoogle(Request $request): ExternalIdentityProvider
    {
        // operacion.md §E.1, issue #140: AUTH_OAUTH_DRIVER=none (valor
        // por defecto) es el estado normal de cualquier despliegue que
        // no quiera Google — 422 de negocio, no una comprobación de
        // forma.
        if (! $this->availability->isConfigured()) {
            $this->fail('provider', 'oauth_provider_not_configured');
        }

        // Un flujo de Google no deja un identificador de catálogo huérfano
        // de un intento anterior sin terminar.
        $request->session()->forget(self::OIDC_PROVIDER_SESSION_KEY);

        return $this->provider;
    }

    /**
     * `funcional.md §F.4.3` puntos 3.2-3.3. `$provider` es un `public_id`
     * de proveedor catalogado. Desconocido, borrado, no activo o sin
     * credencial vigente ⇒ **el mismo** `422`, sin distinguir los cuatro
     * casos (`RN-AUTH-101`, `RN-AUTH-102`) — anónimo, y distinguirlos
     * sería un comprobador de qué centros tienen SSO.
     */
    private function beginCatalog(Request $request, string $publicId): ExternalIdentityProvider
    {
        $identityProvider = $this->identityProviders->findByPublicId($publicId);

        if ($identityProvider === null || ! $identityProvider->is_enabled) {
            $this->fail('provider', 'oauth_provider_not_configured');
        }

        if ($identityProvider->activeSecret() === null) {
            // operacion.md §F.8: auth.sso.provider.enabled_without_secret
            // — sin backend de métricas, el registro de aplicación es la
            // fuente de la alerta operativa.
            Log::channel(config('logging.default'))->warning('auth.sso.provider.enabled_without_secret', [
                'identity_provider_id' => $identityProvider->id,
            ]);

            $this->fail('provider', 'oauth_provider_not_configured');
        }

        $request->session()->put(self::OIDC_PROVIDER_SESSION_KEY, $identityProvider->id);

        return $this->registry->forProvider($identityProvider);
    }

    /**
     * `OidcCallbackService`/`GoogleOAuthCallbackService` consumen y
     * retiran el marcador en el mismo movimiento —de un solo uso, igual
     * que el resto del *payload* transitorio de este paso (`state`, PKCE).
     */
    public static function pullIntent(Request $request): ?string
    {
        $intent = $request->session()->pull(self::INTENT_SESSION_KEY);

        return in_array($intent, ['login', 'link'], true) ? $intent : null;
    }

    /**
     * `RN-AUTH-103`. `OidcCallbackService` lo consulta para resolver el
     * proveedor del *callback* institucional — nunca desde la URL.
     */
    public static function pullOidcProviderId(Request $request): ?int
    {
        $id = $request->session()->pull(self::OIDC_PROVIDER_SESSION_KEY);

        return is_int($id) ? $id : null;
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
