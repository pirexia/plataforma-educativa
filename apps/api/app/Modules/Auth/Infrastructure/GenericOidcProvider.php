<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\ClaimsSource;
use App\Modules\Auth\Domain\EmailClaim;
use App\Modules\Auth\Domain\ExternalIdentity;
use App\Modules\Auth\Domain\ExternalIdentityException;
use App\Modules\Auth\Domain\ExternalIdentityFailure;
use App\Modules\Auth\Domain\ExternalIdentityProvider;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\OidcRedirectUri;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * `funcional.md §F.3.4`. Emisor OIDC genérico parametrizado por la fila
 * del catálogo — no una dependencia nueva (`ADR-043 §2.2`): construido a
 * mano sobre `Http`, sin `Laravel\Socialite\*` (`CA-AUTH-308`).
 *
 * `state`/PKCE/`nonce` viven en el *payload* de la sesión bajo una clave
 * propia (`pge_oidc_state`), separada de `pge_oauth_state` que usa el
 * *driver* de Google — los dos flujos no pueden solaparse en la misma
 * sesión, pero conviene que no compartan clave por si alguna vez lo
 * hacen. `funcional.md §F.3.2`: los *claims* se leen del `id_token`, sin
 * verificar su firma contra el JWKS del emisor (`operacion.md §F.7`,
 * OpenID Connect Core 1.0 §3.1.3.7) — se confía en el canal TLS
 * servidor-a-servidor del canje de código.
 */
final class GenericOidcProvider implements ExternalIdentityProvider
{
    private const SESSION_KEY = 'pge_oidc_state';

    public function __construct(
        private readonly IdentityProvider $provider,
        private readonly Request $request,
        private readonly TenantContext $tenantContext,
    ) {}

    public function beginAuthorization(): string
    {
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->codeChallengeFor($codeVerifier);
        $ttlMinutes = (int) config('auth-local.oauth.state_ttl_minutes');

        $this->request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => $codeVerifier,
            'expires_at' => now()->addMinutes($ttlMinutes)->toISOString(),
        ]);

        $scopes = $this->provider->scopes;
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->provider->client_id,
            'redirect_uri' => $this->buildRedirectUri(),
            'scope' => implode(' ', $scopes !== [] ? $scopes : ['openid']),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        $separator = str_contains($this->provider->authorization_endpoint, '?') ? '&' : '?';

        return $this->provider->authorization_endpoint.$separator.$query;
    }

    public function completeAuthorization(): ExternalIdentity
    {
        // RN-AUTH-91: de un solo uso.
        $stored = $this->request->session()->pull(self::SESSION_KEY);

        if (! $this->stateIsValid($stored)) {
            throw new ExternalIdentityException(ExternalIdentityFailure::InvalidState);
        }

        if ($this->request->query('error') !== null) {
            throw new ExternalIdentityException(ExternalIdentityFailure::ConsentDenied);
        }

        $code = (string) $this->request->query('code', '');

        if ($code === '') {
            throw new ExternalIdentityException(ExternalIdentityFailure::ProviderUnreachable);
        }

        $activeSecret = $this->provider->activeSecret();

        if ($activeSecret === null) {
            throw new ExternalIdentityException(ExternalIdentityFailure::ProviderUnreachable);
        }

        $tokenResponse = $this->exchangeCode($code, (string) $stored['code_verifier'], $activeSecret->client_secret);

        $idToken = $tokenResponse['id_token'] ?? null;

        if (! is_string($idToken) || $idToken === '') {
            throw new ExternalIdentityException(ExternalIdentityFailure::ProviderUnreachable);
        }

        $payload = $this->decodeJwtPayload($idToken);

        if ($payload === null) {
            $this->logIdTokenInvalid('malformed');

            throw new ExternalIdentityException(ExternalIdentityFailure::IdTokenInvalid);
        }

        $this->validateIdTokenClaims($payload, (string) $stored['nonce']);

        $claims = $payload;

        if ($this->provider->claims_source === ClaimsSource::Userinfo) {
            $userinfo = $this->fetchUserinfo((string) ($tokenResponse['access_token'] ?? ''));

            $userinfoSub = (string) ($userinfo['sub'] ?? '');

            // RN-AUTH-105, OpenID Connect Core 1.0 §5.3.2: el `sub` de
            // `userinfo` tiene que coincidir con el del `id_token`, o
            // `userinfo` es un canal sin vincular.
            if ($userinfoSub === '' || $userinfoSub !== (string) ($payload['sub'] ?? '')) {
                $this->logIdTokenInvalid('userinfo_sub_mismatch');

                throw new ExternalIdentityException(ExternalIdentityFailure::IdTokenInvalid);
            }

            $claims = $userinfo;
        }

        return $this->normalize($payload, $claims);
    }

    /**
     * `RN-AUTH-104`: los cinco puntos, antes de leer un solo *claim* de
     * identidad. Falla uno ⇒ no hay identidad, con el mismo código de
     * salida que el fallo de canje (`funcional.md §F.7.1`) — el detalle
     * va al registro, no a la pantalla.
     *
     * @param  array<string, mixed>  $payload
     */
    private function validateIdTokenClaims(array $payload, string $expectedNonce): void
    {
        $now = now()->timestamp;
        $skew = (int) config('auth-local.sso.clock_skew_seconds');

        if (($payload['iss'] ?? null) !== $this->provider->issuer) {
            $this->logIdTokenInvalid('iss');

            throw new ExternalIdentityException(ExternalIdentityFailure::IdTokenInvalid);
        }

        $aud = $payload['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];

        if (! in_array($this->provider->client_id, $audiences, true)) {
            $this->logIdTokenInvalid('aud');

            throw new ExternalIdentityException(ExternalIdentityFailure::IdTokenInvalid);
        }

        $exp = $payload['exp'] ?? null;

        if (! is_int($exp) || $now > $exp + $skew) {
            $this->logIdTokenInvalid('exp');

            throw new ExternalIdentityException(ExternalIdentityFailure::IdTokenInvalid);
        }

        $iat = $payload['iat'] ?? null;

        if (! is_int($iat) || $iat > $now + $skew) {
            $this->logIdTokenInvalid('iat');

            throw new ExternalIdentityException(ExternalIdentityFailure::IdTokenInvalid);
        }

        $nonce = $payload['nonce'] ?? null;

        if (! is_string($nonce) || $nonce === '' || ! hash_equals($expectedNonce, $nonce)) {
            $this->logIdTokenInvalid('nonce');

            throw new ExternalIdentityException(ExternalIdentityFailure::IdTokenInvalid);
        }
    }

    /**
     * `funcional.md §F.3.4`: las siete propiedades de `ExternalIdentity`
     * son *claims* estándar de OpenID Connect Core, sin excepciones.
     *
     * `sub` ausente/vacío ⇒ se devuelve tal cual (cadena vacía) y **no**
     * se evalúa el dominio: `funcional.md §F.4.3` fija el orden exacto
     * —paso 9 (`sub`) antes que paso 10 (dominio)— y sin `sub` el paso 9
     * ya decide la salida (`RN-AUTH-105`), sin alcanzar el 10.
     *
     * @param  array<string, mixed>  $idTokenPayload
     * @param  array<string, mixed>  $claims
     */
    private function normalize(array $idTokenPayload, array $claims): ExternalIdentity
    {
        $sub = (string) ($idTokenPayload['sub'] ?? '');
        $email = $this->extractEmail($claims);

        if ($sub !== '' && $email !== '') {
            $this->guardEmailDomain($email, $claims);
        }

        $emailVerifiedRaw = $claims['email_verified'] ?? null;
        $emailVerified = $emailVerifiedRaw === true || $emailVerifiedRaw === 'true';

        return new ExternalIdentity(
            providerUserId: $sub,
            email: $email,
            emailVerified: $emailVerified,
            displayName: is_string($claims['name'] ?? null) ? $claims['name'] : null,
            givenName: is_string($claims['given_name'] ?? null) ? $claims['given_name'] : null,
            familyName: is_string($claims['family_name'] ?? null) ? $claims['family_name'] : null,
            avatarUrl: is_string($claims['picture'] ?? null) ? $claims['picture'] : null,
        );
    }

    /**
     * `funcional.md §F.5.1`: el valor tiene que tener forma de correo, o
     * no empareja (fallo en cerrado) — un `preferred_username` sin `@`
     * se trata como ausente.
     *
     * @param  array<string, mixed>  $claims
     */
    private function extractEmail(array $claims): string
    {
        $key = match ($this->provider->email_claim) {
            EmailClaim::Email => 'email',
            EmailClaim::PreferredUsername => 'preferred_username',
            EmailClaim::Upn => 'upn',
            // `email_claim` es nullable desde 1.4c (columna compartida con
            // SAML, que no la usa) — pero EloquentExternalIdentityProviderRegistry
            // solo construye esta clase para proveedores OIDC
            // (OAuthAuthorizationService despacha por `protocol` antes de
            // llegar aquí). Un `null` real sería un fallo de invariante en
            // el llamador, no un dato de usuario que tratar con gracia.
            null => throw new LogicException('GenericOidcProvider recibió un IdentityProvider sin email_claim; solo debería construirse para proveedores OIDC.'),
        };

        $value = $claims[$key] ?? null;

        return is_string($value) && str_contains($value, '@') ? $value : '';
    }

    /**
     * `funcional.md §F.4.4`, `RN-AUTH-107`. Genérica siempre; el *claim*
     * `hd` de Google, además, cuando el emisor catalogado es
     * `accounts.google.com` y hay dominios declarados.
     *
     * @param  array<string, mixed>  $claims
     */
    private function guardEmailDomain(string $email, array $claims): void
    {
        $allowedDomains = array_map(
            static fn (mixed $domain): string => strtolower((string) $domain),
            $this->provider->allowed_email_domains
        );

        if ($allowedDomains === []) {
            return;
        }

        $domain = strtolower(substr($email, strrpos($email, '@') + 1));

        if (! in_array($domain, $allowedDomains, true)) {
            throw new ExternalIdentityException(ExternalIdentityFailure::DomainNotAllowed);
        }

        if ($this->provider->issuer !== 'https://accounts.google.com') {
            return;
        }

        $hd = $claims['hd'] ?? null;

        if (! is_string($hd) || $hd === '' || ! in_array(strtolower($hd), $allowedDomains, true)) {
            throw new ExternalIdentityException(ExternalIdentityFailure::DomainNotAllowed);
        }
    }

    private function stateIsValid(mixed $stored): bool
    {
        if (! is_array($stored)) {
            return false;
        }

        $storedState = $stored['state'] ?? null;
        $requestState = $this->request->query('state');

        if (! is_string($storedState) || $storedState === '' || ! is_string($requestState) || $requestState === '') {
            return false;
        }

        if (! hash_equals($storedState, $requestState)) {
            return false;
        }

        $expiresAt = $stored['expires_at'] ?? null;

        if (! is_string($expiresAt) || now()->greaterThan(Carbon::parse($expiresAt))) {
            return false;
        }

        return isset($stored['code_verifier'], $stored['nonce'])
            && is_string($stored['code_verifier']) && $stored['code_verifier'] !== ''
            && is_string($stored['nonce']) && $stored['nonce'] !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCode(string $code, string $codeVerifier, string $clientSecret): array
    {
        $timeout = (int) config('auth-local.sso.token_timeout_seconds');

        try {
            $response = Http::asForm()
                ->timeout($timeout)
                ->connectTimeout($timeout)
                ->post($this->provider->token_endpoint, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->buildRedirectUri(),
                    'client_id' => $this->provider->client_id,
                    'client_secret' => $clientSecret,
                    'code_verifier' => $codeVerifier,
                ]);
        } catch (Throwable $e) {
            throw new ExternalIdentityException(ExternalIdentityFailure::ProviderUnreachable, previous: $e);
        }

        if ($response->failed()) {
            throw new ExternalIdentityException(ExternalIdentityFailure::ProviderUnreachable);
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchUserinfo(string $accessToken): array
    {
        if ($accessToken === '' || $this->provider->userinfo_endpoint === null) {
            return [];
        }

        $timeout = (int) config('auth-local.sso.token_timeout_seconds');

        try {
            $response = Http::withToken($accessToken)
                ->timeout($timeout)
                ->connectTimeout($timeout)
                ->get($this->provider->userinfo_endpoint);
        } catch (Throwable $e) {
            throw new ExternalIdentityException(ExternalIdentityFailure::ProviderUnreachable, previous: $e);
        }

        if ($response->failed()) {
            throw new ExternalIdentityException(ExternalIdentityFailure::ProviderUnreachable);
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJwtPayload(string $jwt): ?array
    {
        $segments = explode('.', $jwt);

        if (count($segments) < 2) {
            return null;
        }

        $decoded = base64_decode(strtr($segments[1], '-_', '+/'), true);

        if ($decoded === false) {
            return null;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload) ? $payload : null;
    }

    private function logIdTokenInvalid(string $reason): void
    {
        // operacion.md §F.8: auth.oidc.idtoken.invalid por motivo. Sin
        // backend de métricas en este repositorio: el registro de
        // aplicación es la fuente que consume la alerta operativa.
        Log::channel(config('logging.default'))->warning('auth.oidc.idtoken.invalid', [
            'reason' => $reason,
            'identity_provider_id' => $this->provider->id,
        ]);
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

    /**
     * `RN-AUTH-92`: nunca `$request->getHost()`. Una sola URI por tenant,
     * la misma para cualquier proveedor catalogado (`funcional.md
     * §F.3.1`) — no lleva el `public_id` del proveedor.
     */
    private function buildRedirectUri(): string
    {
        return OidcRedirectUri::build($this->tenantContext);
    }
}
