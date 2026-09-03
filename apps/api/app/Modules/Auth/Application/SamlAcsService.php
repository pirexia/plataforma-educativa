<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\AccountLockService;
use App\Modules\Auth\Domain\ExternalIdentityException;
use App\Modules\Auth\Domain\ExternalIdentityFailure;
use App\Modules\Auth\Domain\LinkMethod;
use App\Modules\Auth\Domain\LoginMethod;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Models\SamlAuthRequest;
use App\Modules\Auth\Domain\Models\SamlConsumedAssertion;
use App\Modules\Auth\Domain\Models\UserIdentity;
use App\Modules\Auth\Domain\OAuthCallbackOutcome;
use App\Modules\Auth\Domain\Protocol;
use App\Modules\Auth\Domain\ProvisioningMode;
use App\Modules\Auth\Domain\SamlIdentity;
use App\Modules\Auth\Domain\SamlIdentityProviderRegistry;
use App\Modules\Auth\Infrastructure\SamlInResponseToReader;
use App\Modules\Core\Domain\UserDirectory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `funcional.md §G.4.3`, `§G.4.4` (REQ-AUTH-004, 1.4c). El ACS:
 * `POST /api/v1/auth/saml/{public_id}/acs`. Paralelo SAML de
 * `OidcCallbackService`, con dos diferencias estructurales que
 * `funcional.md §G.0.4`/`§G.3.4` obligan a escribir aparte en vez de
 * generalizar el código existente:
 *
 * - **El proveedor se resuelve desde la ruta, jamás desde el `Issuer`
 *   del mensaje** (`RN-AUTH-118`): al contrario que el *callback* de
 *   OIDC, que lo lee del *payload* de la sesión.
 * - **La correlación vive en `saml_auth_requests`, no en la sesión**
 *   (`RN-AUTH-120`, `RN-AUTH-124`): el ACS llega sin cookie. El
 *   `InResponseTo` se lee **antes** de verificar la firma —solo como
 *   clave de búsqueda, nunca como base de una decisión de confianza— con
 *   `SamlInResponseToReader`, y es lo que distingue `estado_no_valido`
 *   (fallo de correlación) de `error_proveedor` (fallo criptográfico),
 *   una distinción que `api.md §G.7.2` exige y que delegar en el
 *   `InResponseTo` interno de `php-saml` no puede dar por sí sola.
 */
final class SamlAcsService
{
    private const DEVICE_COOKIE_NAME = 'pge_device';

    public function __construct(
        private readonly RateLimitGuard $rateLimits,
        private readonly SamlIdentityProviderRegistry $registry,
        private readonly SamlInResponseToReader $inResponseToReader,
        private readonly UserDirectory $userDirectory,
        private readonly AccountLockService $lockService,
        private readonly LoginAttemptRecorder $attempts,
        private readonly LoginService $loginService,
        private readonly MfaPolicy $mfaPolicy,
        private readonly MfaChallengeService $mfaChallenges,
        private readonly UserIdentityLinkingService $identityLinking,
        private readonly AuthenticatedSessionEstablisher $establisher,
    ) {}

    /**
     * `api.md §G.6`, `operacion.md §G.6`: el ACS es la única ruta del
     * módulo cuyo `429` es un `429` de verdad (con `Retry-After`), no un
     * `302` con `error_proveedor` — `RateLimitGuard::hit()`, no
     * `exceeded()`, y se deja propagar.
     */
    public function handle(Request $request, string $providerPublicId): OAuthCallbackResult
    {
        $this->rateLimits->hit('saml_acs_ip', (string) $request->ip());

        $provider = IdentityProvider::query()
            ->where('public_id', $providerPublicId)
            ->where('protocol', Protocol::Saml)
            ->first();

        // funcional.md §G.10.2: ningún `public_id` inexistente, de otro
        // protocolo, no activo o sin certificado resuelve a un flujo
        // utilizable. `estado_no_valido`, no 404 — distinguir "este
        // proveedor no existe" de "esta aserción no correlaciona" en una
        // ruta anónima sería un comprobador de qué centros tienen SAML.
        if ($provider === null || ! $provider->is_enabled || $provider->activeCertificates()->isEmpty()) {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::ProveedorNoDisponible);
        }

        $samlResponse = (string) $request->input('SAMLResponse', '');

        if ($samlResponse === '') {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::EstadoNoValido);
        }

        // RN-AUTH-120: se lee el InResponseTo SIN verificar la firma —
        // solo como clave de búsqueda contra la fila que NOSOTROS
        // emitimos. No es una decisión de confianza: todo lo que importa
        // se valida después, sobre la fila ya resuelta.
        $inResponseTo = $this->inResponseToReader->read($samlResponse);

        if ($inResponseTo === null) {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::EstadoNoValido);
        }

        $authRequest = SamlAuthRequest::query()
            ->where('identity_provider_id', $provider->id)
            ->where('request_id', $inResponseTo)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        // CA-AUTH-341/342: ausente, ya consumida o caducada comparten el
        // mismo cuerpo — estado_no_valido.
        if ($authRequest === null) {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::EstadoNoValido);
        }

        try {
            $identity = $this->registry->forProvider($provider)->validateAssertion($samlResponse, $authRequest->request_id);
        } catch (ExternalIdentityException $e) {
            Log::channel(config('logging.default'))->warning('auth.saml.assertion.invalid', [
                'identity_provider_id' => $provider->id,
                'reason' => $e->getMessage(),
            ]);

            return OAuthCallbackResult::outcome(match ($e->failure) {
                // AssertionInvalid es el único caso que este servicio
                // lanza en la práctica, pero el match tiene que ser
                // exhaustivo sobre el enum completo (Larastan, mismo
                // criterio que OidcCallbackService::handle()/
                // GoogleOAuthCallbackService::handle()).
                ExternalIdentityFailure::ConsentDenied => OAuthCallbackOutcome::Cancelado,
                ExternalIdentityFailure::InvalidState => OAuthCallbackOutcome::EstadoNoValido,
                ExternalIdentityFailure::ProviderUnreachable, ExternalIdentityFailure::IdTokenInvalid, ExternalIdentityFailure::AssertionInvalid => OAuthCallbackOutcome::ErrorProveedor,
                ExternalIdentityFailure::DomainNotAllowed => OAuthCallbackOutcome::DominioNoPermitido,
            });
        }

        // RN-AUTH-121/122: consumo atómico de la fila + registro del ID
        // de la aserción, en la misma transacción.
        try {
            $consumed = DB::transaction(function () use ($authRequest, $identity): bool {
                $affected = SamlAuthRequest::query()
                    ->where('id', $authRequest->id)
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => now()]);

                if ($affected !== 1) {
                    return false;
                }

                SamlConsumedAssertion::create([
                    'identity_provider_id' => $authRequest->identity_provider_id,
                    'assertion_id' => $identity->assertionId,
                    'not_on_or_after' => $identity->notOnOrAfter,
                ]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // RN-AUTH-122, CA-AUTH-344: la misma aserción ya se consumió
            // contra otra petición. El intento actual (contra ESTA fila,
            // que seguía viva) se aborta entero: la fila NO queda
            // consumida, y una entrega legítima futura contra ella sigue
            // pudiendo completarse.
            Log::channel(config('logging.default'))->warning('auth.saml.assertion.invalid', [
                'identity_provider_id' => $provider->id,
                'reason' => 'repetida',
            ]);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::ErrorProveedor);
        }

        // CA-AUTH-343: dos ACS simultáneos con la misma aserción — el
        // segundo pierde la carrera del UPDATE, no una lectura previa.
        if (! $consumed) {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::EstadoNoValido);
        }

        return $authRequest->intent === 'link'
            ? $this->handleLink($authRequest, $provider, $identity)
            : $this->handleLogin($request, $provider, $identity);
    }

    /**
     * `funcional.md §G.4.4`, `CA-AUTH-355`/`CA-AUTH-356`. Sin sesión: el
     * usuario a vincular sale de `linking_user_id`, capturado al emitir
     * la petición (donde sí había sesión). La aserción no se reinterpreta
     * como un login si ese usuario ya no es válido.
     */
    private function handleLink(SamlAuthRequest $authRequest, IdentityProvider $provider, SamlIdentity $identity): OAuthCallbackResult
    {
        $user = $authRequest->linking_user_id !== null
            ? User::query()->where('id', $authRequest->linking_user_id)->where('status', UserStatus::Activo)->first()
            : null;

        if ($user === null) {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::EstadoNoValido);
        }

        if ($identity->nameId === '') {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::SinCuenta);
        }

        try {
            DB::transaction(function () use ($user, $provider, $identity): void {
                $this->identityLinking->link(
                    $user,
                    $identity->nameId,
                    $identity->email ?? '',
                    // RN-AUTH-130: SAML no tiene el concepto de correo
                    // verificado. Se guarda `false` y no sostiene ninguna
                    // decisión.
                    false,
                    LinkMethod::Perfil,
                    $provider,
                );
            });
        } catch (UniqueConstraintViolationException $e) {
            return OAuthCallbackResult::outcome(
                str_contains($e->getMessage(), 'user_identities_tenant_provider_id_subject_unique')
                    ? OAuthCallbackOutcome::ProveedorYaVinculado
                    : OAuthCallbackOutcome::YaVinculado
            );
        }

        return OAuthCallbackResult::outcome(OAuthCallbackOutcome::Vinculado);
    }

    /**
     * `funcional.md §G.4.3` pasos 10-14. Mismo orden que
     * `OidcCallbackService::handleLogin()`.
     */
    private function handleLogin(Request $request, IdentityProvider $provider, SamlIdentity $identity): OAuthCallbackResult
    {
        // RN-AUTH-123: sin NameID utilizable, sin alternativa. Byte a
        // byte igual que "no hay cuenta".
        if ($identity->nameId === '') {
            $this->attempts->recordFederatedNoLink('', LoginMethod::Sso);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::SinCuenta);
        }

        $normalizedEmail = $identity->email !== null && $identity->email !== ''
            ? $this->loginService->normalize($identity->email)
            : '';

        // RN-AUTH-107 (sin cambios respecto de OIDC): dominio admitido.
        // Se evalúa antes de resolver el usuario porque, igual que en
        // 1.4b, es una propiedad del correo con el que se emparejaría,
        // no de una cuenta ya encontrada.
        if ($normalizedEmail !== '' && ! $this->isDomainAllowed($provider, $normalizedEmail)) {
            $this->attempts->recordFederatedNoLink($normalizedEmail, LoginMethod::Sso);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::DominioNoPermitido);
        }

        $link = UserIdentity::query()
            ->where('identity_provider_id', $provider->id)
            ->where('subject', $identity->nameId)
            ->first();

        $pendingMatch = false;

        if ($link !== null) {
            $user = $link->user;
        } elseif ($provider->provisioning_mode === ProvisioningMode::Emparejamiento && $normalizedEmail !== '') {
            $user = $this->userDirectory->findActiveByEmail($normalizedEmail);

            if ($user !== null) {
                $alreadyLinkedWithOtherSubject = UserIdentity::query()
                    ->where('user_id', $user->id)
                    ->where('identity_provider_id', $provider->id)
                    ->exists();

                if ($alreadyLinkedWithOtherSubject) {
                    Log::channel(config('logging.default'))->warning('auth.saml.subject_changed', [
                        'identity_provider_id' => $provider->id,
                    ]);

                    $this->attempts->recordFederatedNoLink($normalizedEmail, LoginMethod::Sso);

                    return OAuthCallbackResult::outcome(OAuthCallbackOutcome::SinCuenta);
                }

                $pendingMatch = true;
            }
        } else {
            $user = null;
        }

        if ($user === null) {
            $this->attempts->recordFederatedNoLink($normalizedEmail !== '' ? $normalizedEmail : ($identity->email ?? ''), LoginMethod::Sso);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::SinCuenta);
        }

        $localEmail = $user->email;

        $lockout = $this->lockService->findLiveOrCloseExpired($localEmail);

        if ($lockout !== null) {
            $this->attempts->recordLockedAttempt($localEmail, $user, LoginMethod::Sso);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::CuentaBloqueada);
        }

        // RN-AUTH-23: solo `activo` entra. `pendiente`/`inactivo`/borrado
        // comparten la misma salida genérica (OPEN-AUTH-39).
        if ($user->status !== UserStatus::Activo) {
            $this->attempts->recordNonActiveState($localEmail, $user, LoginMethod::Sso);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::AccesoDenegado);
        }

        // RN-AUTH-129: MfaPolicy completo, sin una sola excepción. El SSO
        // institucional SAML no salta el segundo factor.
        if ($this->mfaPolicy->hasUsableFactor($user)) {
            if ($pendingMatch) {
                $this->mfaChallenges->stashPendingSsoMatch(
                    $request,
                    $provider->id,
                    $identity->nameId,
                    $identity->email ?? '',
                    false,
                );
            }

            $this->mfaChallenges->open($request, $user, LoginMethod::Sso);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::SegundoFactor);
        }

        $isEnforced = $this->mfaPolicy->resolve($user)->isEnforced();

        // RN-AUTH-109: el emparejamiento escribe solo la fila de
        // user_identities, en la misma transacción que crea la sesión.
        $result = DB::transaction(function () use ($request, $user, $localEmail, $pendingMatch, $provider, $identity) {
            if ($pendingMatch) {
                $this->identityLinking->linkViaSso(
                    $user,
                    $provider,
                    $identity->nameId,
                    $identity->email ?? '',
                    false,
                );
            }

            return $this->establisher->establish(
                $request,
                $user,
                $localEmail,
                $request->cookie(self::DEVICE_COOKIE_NAME),
                LoginMethod::Sso,
            );
        });

        return $isEnforced
            ? OAuthCallbackResult::outcome(OAuthCallbackOutcome::AltaMfaRequerida, $result->newDeviceCookieValue)
            : OAuthCallbackResult::success($result->newDeviceCookieValue);
    }

    /**
     * `RN-AUTH-107`, sin cambios de criterio respecto de OIDC. La capa
     * `hd` de Google no aplica en SAML (`funcional.md §G.4.3` punto 11).
     */
    private function isDomainAllowed(IdentityProvider $provider, string $normalizedEmail): bool
    {
        $allowedDomains = $provider->allowed_email_domains;

        if ($allowedDomains === []) {
            return true;
        }

        $domain = strtolower((string) strrchr($normalizedEmail, '@'));
        $domain = ltrim($domain, '@');

        foreach ($allowedDomains as $allowed) {
            if (strtolower((string) $allowed) === $domain) {
                return true;
            }
        }

        return false;
    }
}
