<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\ExternalIdentityProvider;
use App\Modules\Auth\Domain\ExternalIdentityProviderRegistry;
use App\Modules\Auth\Domain\IdentityProviderDirectory;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Models\SamlAuthRequest;
use App\Modules\Auth\Domain\Protocol;
use App\Modules\Auth\Domain\SamlIdentityProviderRegistry;
use App\Support\Api\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * `funcional.md §E.4.1`, `api.md §E.3`, ampliado por `funcional.md
 * §F.4.3` (1.4b) y `§G.4.3`/`§G.6` (1.4c). Arranca el flujo: `POST
 * /auth/oauth-authorizations`. Anónimo con `intent = 'login'`; por
 * identidad del portador con `intent = 'link'` (`permisos.md §E.2`).
 *
 * `provider` acepta **o** el literal `"google"` (*driver* global de 1.4,
 * sin cambios) **o** el `public_id` de un proveedor catalogado (OIDC
 * desde 1.4b, SAML desde 1.4c) — un identificador opaco que la SPA copia
 * de `GET /auth/identity-providers` sin interpretarlo (`api.md §F.6`,
 * `§G.1` punto 2: **la SPA no sabe qué protocolo es ninguno**).
 *
 * `RN-AUTH-114`: los dos mecanismos de correlación de OIDC/Google
 * (`pge_oauth_intent`/`pge_oidc_provider_id` en sesión) y de SAML
 * (`saml_auth_requests` en base de datos, `funcional.md §G.0.4`) son
 * **independientes**. Un flujo SAML no escribe nada en la clave de
 * sesión, y un flujo OIDC/Google no toca la tabla de correlación.
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
        private readonly SamlIdentityProviderRegistry $samlRegistry,
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

        if ($provider === 'google') {
            $authorizationUrl = $this->beginGoogle($request, $intent);
        } else {
            $identityProvider = $this->resolveCatalogProvider($provider);

            $authorizationUrl = $identityProvider->protocol === Protocol::Saml
                ? $this->beginSaml($identityProvider, $intent, $user)
                : $this->beginOidc($request, $identityProvider, $intent);
        }

        $ttlMinutes = (int) config('auth-local.oauth.state_ttl_minutes');

        return [
            'authorization_url' => $authorizationUrl,
            'expires_at' => now()->addMinutes($ttlMinutes)->toISOString(),
        ];
    }

    private function beginGoogle(Request $request, string $intent): string
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

        $this->putIntent($request, $intent);

        return $this->provider->beginAuthorization();
    }

    /**
     * `funcional.md §F.4.3` puntos 3.2-3.3, `§G.4.3` puntos 3.2-3.3.
     * `$publicId` es el `public_id` de un proveedor catalogado (OIDC o
     * SAML). Desconocido, borrado o no activo ⇒ **el mismo** `422`, sin
     * distinguir los casos (`RN-AUTH-101`, `RN-AUTH-102`) — anónimo, y
     * distinguirlos sería un comprobador de qué centros tienen SSO.
     */
    private function resolveCatalogProvider(string $publicId): IdentityProvider
    {
        $identityProvider = $this->identityProviders->findByPublicId($publicId);

        if ($identityProvider === null || ! $identityProvider->is_enabled) {
            $this->fail('provider', 'oauth_provider_not_configured');
        }

        return $identityProvider;
    }

    private function beginOidc(Request $request, IdentityProvider $identityProvider, string $intent): string
    {
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
        $this->putIntent($request, $intent);

        return $this->registry->forProvider($identityProvider)->beginAuthorization();
    }

    /**
     * `funcional.md §G.4.3` puntos 3.3-3.5. **No escribe nada en la
     * sesión** (`§G.0.4`, `RN-AUTH-114`): el `intent` y el usuario a
     * vincular viajan en la fila de `saml_auth_requests`, no en
     * `pge_oauth_intent`/`pge_oidc_provider_id` — un flujo SAML no debe
     * dejar ninguna de esas dos claves huérfana.
     */
    private function beginSaml(IdentityProvider $identityProvider, string $intent, ?User $user): string
    {
        if ($identityProvider->activeCertificates()->isEmpty()) {
            // operacion.md §G.8: auth.saml.provider.enabled_without_certificate.
            Log::channel(config('logging.default'))->warning('auth.saml.provider.enabled_without_certificate', [
                'identity_provider_id' => $identityProvider->id,
            ]);

            $this->fail('provider', 'oauth_provider_not_configured');
        }

        // funcional.md §G.4.3 punto 3.4: un ID de SAML es un NCName, no
        // puede empezar por dígito — mismo prefijo que php-saml usa para
        // lo mismo (OneLogin\Saml2\Utils::generateUniqueID()), sin cruzar
        // la frontera de CA-AUTH-362 para generarlo (es texto, no un tipo
        // de la biblioteca).
        $requestId = 'ONELOGIN_'.bin2hex(random_bytes(20));
        $ttlMinutes = (int) config('auth-local.oauth.state_ttl_minutes');

        SamlAuthRequest::create([
            'identity_provider_id' => $identityProvider->id,
            'request_id' => $requestId,
            'intent' => $intent,
            'linking_user_id' => $intent === 'link' ? $user?->id : null,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return $this->samlRegistry->forProvider($identityProvider)->buildAuthnRequest($requestId);
    }

    private function putIntent(Request $request, string $intent): void
    {
        // funcional.md §E.4.1 punto 3.3: intent guardado junto al state,
        // en el mismo payload de sesión cifrado — nunca en una cookie
        // propia ni en localStorage (RN-AUTH-28).
        $request->session()->put(self::INTENT_SESSION_KEY, $intent);
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
