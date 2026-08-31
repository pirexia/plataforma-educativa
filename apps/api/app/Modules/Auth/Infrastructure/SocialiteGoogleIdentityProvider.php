<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\ExternalIdentity;
use App\Modules\Auth\Domain\ExternalIdentityException;
use App\Modules\Auth\Domain\ExternalIdentityFailure;
use App\Modules\Auth\Domain\ExternalIdentityProvider;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Throwable;

/**
 * `ADR-042`. **Único fichero de todo `apps/api` autorizado a escribir
 * `use Laravel\Socialite\...`**. Se instancia `GoogleProvider`
 * directamente (sin pasar por el *facade* `Socialite::driver()` ni por
 * `config('services.google')`, que no existe en este proyecto): todo el
 * origen de configuración es `config('auth-local.oauth.*')`
 * (`operacion.md §E.2.1`).
 *
 * `state` y PKCE se gestionan enteramente aquí, **sin** el mecanismo
 * interno de sesión de Socialite (`->stateless()`): la biblioteca genera
 * su `state` con `Str::random(40)` sin caducidad propia, y este paso
 * exige un `state` de 32 bytes de un generador criptográfico, comparado
 * en tiempo constante y con caducidad propia de `AUTH_OAUTH_STATE_TTL_MINUTES`
 * (`RN-AUTH-91`) — distinta de la duración completa de la sesión. Se
 * añaden `state`/`code_challenge`/`code_verifier` como parámetros propios
 * vía `with()`, y se valida el `state` a mano con `hash_equals()`, de un
 * solo uso, consumido en el acto incluso cuando resulta inválido.
 *
 * `operacion.md §E.7`: la lectura de `email_verified` usa `userinfo` con
 * el *access token* (el camino que no verifica firma JWKS), nunca
 * decodificación de `id_token` — `GoogleProvider::getUserByToken()` ya
 * hace esa elección por la forma del token, y aquí nunca se le pasa uno
 * con forma de JWT.
 */
final class SocialiteGoogleIdentityProvider implements ExternalIdentityProvider
{
    /** Payload propio: state, code_verifier y expires_at (RN-AUTH-91). */
    private const SESSION_KEY = 'pge_oauth_state';

    public function __construct(
        private readonly Request $request,
        private readonly TenantContext $tenantContext,
    ) {}

    public function beginAuthorization(): string
    {
        $state = Str::random(64);
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->codeChallengeFor($codeVerifier);
        $ttlMinutes = (int) config('auth-local.oauth.state_ttl_minutes');

        $this->request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'code_verifier' => $codeVerifier,
            'expires_at' => now()->addMinutes($ttlMinutes)->toISOString(),
        ]);

        $provider = $this->newProvider()
            ->stateless()
            ->scopes(['openid', 'profile', 'email'])
            ->with([
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
                // funcional.md §E.4.1 punto 3.4: siempre se ofrece elegir
                // cuenta, nunca se reutiliza en silencio la última sesión
                // de Google del navegador.
                'prompt' => 'select_account',
            ]);

        return $provider->redirect()->getTargetUrl();
    }

    public function completeAuthorization(): ExternalIdentity
    {
        // RN-AUTH-91: de un solo uso — se retira de la sesión en el acto,
        // válido o no.
        $stored = $this->request->session()->pull(self::SESSION_KEY);

        if (! $this->stateIsValid($stored)) {
            throw new ExternalIdentityException(ExternalIdentityFailure::InvalidState);
        }

        // funcional.md §E.4.2 paso 4: la persona canceló en Google. Se
        // comprueba DESPUÉS del `state` (paso 3), nunca antes.
        if ($this->request->query('error') !== null) {
            throw new ExternalIdentityException(ExternalIdentityFailure::ConsentDenied);
        }

        $codeVerifier = (string) $stored['code_verifier'];
        $code = (string) $this->request->query('code', '');

        if ($code === '') {
            throw new ExternalIdentityException(ExternalIdentityFailure::ProviderUnreachable);
        }

        try {
            $socialiteUser = $this->newProvider()
                ->stateless()
                ->with(['code_verifier' => $codeVerifier])
                ->userFromToken($this->exchangeCodeForAccessToken($code, $codeVerifier));
        } catch (ExternalIdentityException $e) {
            throw $e;
        } catch (GuzzleException|Throwable $e) {
            throw new ExternalIdentityException(ExternalIdentityFailure::ProviderUnreachable, previous: $e);
        }

        return $this->normalize($socialiteUser);
    }

    /**
     * `user()` de Socialite hace canje + lectura en un único método
     * ligado a `getCode()`, que no distingue "sin código" (cancelación,
     * `§E.4.2` paso 4) de un fallo real del proveedor. Se canjea aquí, a
     * mano, con el mismo `GoogleProvider::getAccessTokenResponse()`.
     */
    private function exchangeCodeForAccessToken(string $code, string $codeVerifier): string
    {
        $provider = $this->newProvider()->stateless()->with(['code_verifier' => $codeVerifier]);

        $response = $provider->getAccessTokenResponse($code);
        $accessToken = Arr::get($response, 'access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new ExternalIdentityException(ExternalIdentityFailure::ProviderUnreachable);
        }

        return $accessToken;
    }

    /**
     * `ADR-042 §4.4`: normalización de lista blanca estricta, prohibido
     * `(bool)`/`filter_var(FILTER_VALIDATE_BOOLEAN)`. `verified_email`
     * (clave *deprecated* de Socialite) nunca se lee.
     */
    private function normalize(SocialiteUser $user): ExternalIdentity
    {
        $raw = $user->getRaw();
        $rawEmailVerified = Arr::get($raw, 'email_verified');
        $emailVerified = $rawEmailVerified === true || $rawEmailVerified === 'true';

        return new ExternalIdentity(
            providerUserId: (string) $user->getId(),
            email: (string) $user->getEmail(),
            emailVerified: $emailVerified,
            displayName: Arr::get($raw, 'name'),
            givenName: Arr::get($raw, 'given_name'),
            familyName: Arr::get($raw, 'family_name'),
            avatarUrl: Arr::get($raw, 'picture'),
        );
    }

    private function stateIsValid(mixed $stored): bool
    {
        if (! is_array($stored)) {
            return false;
        }

        $storedState = $stored['state'] ?? null;
        $expiresAt = $stored['expires_at'] ?? null;
        $requestState = $this->request->query('state');

        if (! is_string($storedState) || $storedState === '' || ! is_string($requestState) || $requestState === '') {
            return false;
        }

        if (! hash_equals($storedState, $requestState)) {
            return false;
        }

        if (! is_string($expiresAt) || now()->greaterThan(Carbon::parse($expiresAt))) {
            return false;
        }

        return isset($stored['code_verifier']) && is_string($stored['code_verifier']) && $stored['code_verifier'] !== '';
    }

    /** RFC 7636 §4.1: 43-128 caracteres del alfabeto sin reservar. */
    private function generateCodeVerifier(): string
    {
        return Str::random(96);
    }

    private function codeChallengeFor(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    private function newProvider(): GoogleProvider
    {
        /** @var GoogleProvider $provider */
        $provider = new GoogleProvider(
            $this->request,
            (string) config('auth-local.oauth.google_client_id'),
            (string) config('auth-local.oauth.google_client_secret'),
            $this->buildRedirectUri(),
        );

        return $provider;
    }

    /**
     * `RN-AUTH-92`, `CA-AUTH-203`: nunca `$request->getHost()`. Se
     * construye con el slug del tenant ya resuelto y el dominio base
     * configurado — el `Host` de la cabecera lo controla el cliente.
     */
    private function buildRedirectUri(): string
    {
        $tenant = Tenant::query()->find($this->tenantContext->tenantId());
        $slug = $tenant->slug ?? '';
        $baseDomain = (string) config('tenancy.base_domain');

        return "https://{$slug}.{$baseDomain}/api/v1/auth/oauth/google/callback";
    }
}
