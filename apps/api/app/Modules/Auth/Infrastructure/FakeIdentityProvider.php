<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\ExternalIdentity;
use App\Modules\Auth\Domain\ExternalIdentityException;
use App\Modules\Auth\Domain\ExternalIdentityFailure;
use App\Modules\Auth\Domain\ExternalIdentityProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * `operacion.md §E.10`. La única forma de desarrollar y probar 1.4 sin
 * credenciales reales de Google en WSL2 (`§E.10.1`). Tras la misma
 * interfaz que `SocialiteGoogleIdentityProvider` — «exactamente para lo
 * que `RNF-MANT-007` obliga a envolver la dependencia» —: **el resto del
 * flujo es el de verdad**, mismo `state`, mismo PKCE (simétrico, aunque
 * aquí no hay proveedor real que lo verifique), mismo *callback*, misma
 * resolución de identidad, misma fusión, mismo `MfaPolicy`. Lo único
 * simulado es de dónde salen los *claims*: de un formulario propio en vez
 * de `accounts.google.com`.
 *
 * Dos barreras contra producción (`§E.10.3`, `CA-AUTH-230`): esta clase
 * solo se vincula cuando `AUTH_OAUTH_DRIVER=fake`, y esa configuración
 * aborta el arranque fuera de `local`/`testing`
 * (`OAuthEnvironmentGuard`) — la ruta del formulario tampoco se registra
 * fuera de esos entornos (`routes.php`).
 */
final class FakeIdentityProvider implements ExternalIdentityProvider
{
    private const SESSION_KEY = 'pge_oauth_state';

    public function __construct(
        private readonly Request $request,
    ) {}

    public function beginAuthorization(): string
    {
        $state = Str::random(64);
        $codeVerifier = Str::random(96);
        $ttlMinutes = (int) config('auth-local.oauth.state_ttl_minutes');

        $this->request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'code_verifier' => $codeVerifier,
            'expires_at' => now()->addMinutes($ttlMinutes)->toISOString(),
        ]);

        return URL::route('auth.oauth.fake.authorize', ['state' => $state]);
    }

    public function completeAuthorization(): ExternalIdentity
    {
        $stored = $this->request->session()->pull(self::SESSION_KEY);

        if (! $this->stateIsValid($stored)) {
            throw new ExternalIdentityException(ExternalIdentityFailure::InvalidState);
        }

        if ($this->request->query('error') !== null) {
            throw new ExternalIdentityException(ExternalIdentityFailure::ConsentDenied);
        }

        $claims = self::decodeCode((string) $this->request->query('code', ''));

        if ($claims === null) {
            throw new ExternalIdentityException(ExternalIdentityFailure::ProviderUnreachable);
        }

        return new ExternalIdentity(
            providerUserId: (string) ($claims['sub'] ?? ''),
            email: (string) ($claims['email'] ?? ''),
            emailVerified: ($claims['email_verified'] ?? false) === true,
            displayName: $claims['name'] ?? null,
            givenName: $claims['given_name'] ?? null,
            familyName: $claims['family_name'] ?? null,
            avatarUrl: null,
        );
    }

    /**
     * `FakeGoogleAuthorizationController` codifica los datos del
     * formulario simulado como el propio `code`: no hay ningún servidor
     * de Google al que canjearlo, así que el `code` lleva los *claims*
     * consigo, opacos para cualquiera que no sea este par de clases.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function encodeCode(array $claims): string
    {
        return base64_encode((string) json_encode($claims));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeCode(string $code): ?array
    {
        if ($code === '') {
            return null;
        }

        $decoded = base64_decode($code, true);

        if ($decoded === false) {
            return null;
        }

        $claims = json_decode($decoded, true);

        return is_array($claims) ? $claims : null;
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

        return is_string($expiresAt) && now()->lessThanOrEqualTo(Carbon::parse($expiresAt));
    }
}
