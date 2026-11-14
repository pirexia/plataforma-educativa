<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\AccountLockService;
use App\Modules\Auth\Domain\ExternalIdentity;
use App\Modules\Auth\Domain\ExternalIdentityException;
use App\Modules\Auth\Domain\ExternalIdentityFailure;
use App\Modules\Auth\Domain\ExternalIdentityProviderRegistry;
use App\Modules\Auth\Domain\IdentityProviderDirectory;
use App\Modules\Auth\Domain\LinkMethod;
use App\Modules\Auth\Domain\LoginMethod;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Models\UserIdentity;
use App\Modules\Auth\Domain\OAuthCallbackOutcome;
use App\Modules\Auth\Domain\ProvisioningMode;
use App\Modules\Core\Domain\UserDirectory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `funcional.md §F.4.3` (resolución de identidad y creación de sesión) y
 * `api.md §F.6` punto de vinculación manual. Es el paralelo institucional
 * de `GoogleOAuthCallbackService`, sobre `GET /auth/oauth/oidc/callback`
 * (`funcional.md §F.3.1`: una sola ruta para todos los proveedores
 * catalogados del tenant).
 *
 * `RN-AUTH-103`: el proveedor se resuelve **siempre** desde el *payload*
 * de la sesión que arrancó el flujo (`OAuthAuthorizationService::
 * pullOidcProviderId()`), nunca desde la URL.
 */
final class OidcCallbackService
{
    private const DEVICE_COOKIE_NAME = 'pge_device';

    public function __construct(
        private readonly RateLimitGuard $rateLimits,
        private readonly IdentityProviderDirectory $identityProviders,
        private readonly ExternalIdentityProviderRegistry $registry,
        private readonly UserDirectory $userDirectory,
        private readonly AccountLockService $lockService,
        private readonly LoginAttemptRecorder $attempts,
        private readonly LoginService $loginService,
        private readonly MfaPolicy $mfaPolicy,
        private readonly MfaChallengeService $mfaChallenges,
        private readonly UserIdentityLinkingService $identityLinking,
        private readonly AuthenticatedSessionEstablisher $establisher,
    ) {}

    public function handle(Request $request): OAuthCallbackResult
    {
        // operacion.md §F.6: mismo criterio que el callback de Google —
        // nunca problem+json, ni siquiera por límite de tasa.
        if ($this->rateLimits->exceeded('oidc_callback_ip', (string) $request->ip())) {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::ErrorProveedor);
        }

        $providerId = OAuthAuthorizationService::pullOidcProviderId($request);

        if ($providerId === null) {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::EstadoNoValido);
        }

        $identityProvider = $this->identityProviders->findById($providerId);

        // funcional.md §F.4.5: el proveedor se desactivó, se borró o se
        // quedó sin credencial vigente entre el arranque del flujo y la
        // vuelta.
        if ($identityProvider === null || ! $identityProvider->is_enabled || $identityProvider->activeSecret() === null) {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::ProveedorNoDisponible);
        }

        $intent = OAuthAuthorizationService::pullIntent($request);

        if ($intent === null) {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::EstadoNoValido);
        }

        try {
            $identity = $this->registry->forProvider($identityProvider)->completeAuthorization();
        } catch (ExternalIdentityException $e) {
            return OAuthCallbackResult::outcome(match ($e->failure) {
                ExternalIdentityFailure::ConsentDenied => OAuthCallbackOutcome::Cancelado,
                ExternalIdentityFailure::InvalidState => OAuthCallbackOutcome::EstadoNoValido,
                // AssertionInvalid es de 1.4c (SAML): este servicio nunca la
                // lanza, pero el match tiene que ser exhaustivo sobre el
                // enum completo (Larastan, mismo criterio que
                // GoogleOAuthCallbackService::handle()).
                ExternalIdentityFailure::ProviderUnreachable, ExternalIdentityFailure::IdTokenInvalid, ExternalIdentityFailure::AssertionInvalid => OAuthCallbackOutcome::ErrorProveedor,
                ExternalIdentityFailure::DomainNotAllowed => OAuthCallbackOutcome::DominioNoPermitido,
            });
        }

        return $intent === 'link'
            ? $this->handleLink($identityProvider, $identity)
            : $this->handleLogin($request, $identityProvider, $identity);
    }

    /**
     * `api.md §F.6`: `intent = link` funciona igual con un proveedor
     * catalogado — vincula al usuario de la sesión, sin buscar por
     * correo. `link_method = 'perfil'`, no `'emparejamiento_sso'`: es el
     * titular quien lo pide, no el sistema por coincidencia.
     */
    private function handleLink(IdentityProvider $identityProvider, ExternalIdentity $identity): OAuthCallbackResult
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::EstadoNoValido);
        }

        try {
            DB::transaction(function () use ($user, $identityProvider, $identity): void {
                $this->identityLinking->link(
                    $user,
                    $identity->providerUserId,
                    $identity->email,
                    $identity->emailVerified,
                    LinkMethod::Perfil,
                    $identityProvider,
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
     * `funcional.md §F.4.3` pasos 9-14.
     */
    private function handleLogin(Request $request, IdentityProvider $identityProvider, ExternalIdentity $identity): OAuthCallbackResult
    {
        // Paso 9, RN-AUTH-105: sin `sub` no hay identidad, y no se busca
        // por correo como alternativa. Salida idéntica a "no hay cuenta".
        if ($identity->providerUserId === '') {
            $this->attempts->recordFederatedNoLink($this->loginService->normalize($identity->email), LoginMethod::Sso);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::SinCuenta);
        }

        // Paso 11a: vínculo vivo con ESTE proveedor concreto ⇒ ese es el
        // usuario. El correo no se consulta (ADR-043 §3.6).
        $link = UserIdentity::query()
            ->where('identity_provider_id', $identityProvider->id)
            ->where('subject', $identity->providerUserId)
            ->first();

        $pendingMatch = false;
        $normalizedEmail = $identity->email !== '' ? $this->loginService->normalize($identity->email) : '';

        if ($link !== null) {
            $user = $link->user;
        } elseif ($identityProvider->provisioning_mode === ProvisioningMode::Emparejamiento && $normalizedEmail !== '') {
            // Paso 11b: emparejamiento candidato.
            $user = $this->userDirectory->findActiveByEmail($normalizedEmail);

            if ($user !== null) {
                // Paso 11d: ese usuario ya tiene un vínculo vivo con ESTE
                // proveedor pero otro `subject` — cambio de identidad en
                // el IdP, no un acceso ordinario. No se empareja.
                $alreadyLinkedWithOtherSubject = UserIdentity::query()
                    ->where('user_id', $user->id)
                    ->where('identity_provider_id', $identityProvider->id)
                    ->exists();

                if ($alreadyLinkedWithOtherSubject) {
                    Log::channel(config('logging.default'))->warning('auth.oidc.subject_changed', [
                        'identity_provider_id' => $identityProvider->id,
                    ]);

                    $this->attempts->recordFederatedNoLink($normalizedEmail, LoginMethod::Sso);

                    return OAuthCallbackResult::outcome(OAuthCallbackOutcome::SinCuenta);
                }

                $pendingMatch = true;
            }
        } else {
            // Paso 11c: emparejamiento desactivado, sin claim de correo,
            // o sin usuario activo con ese correo.
            $user = null;
        }

        if ($user === null) {
            $this->attempts->recordFederatedNoLink($normalizedEmail !== '' ? $normalizedEmail : $identity->email, LoginMethod::Sso);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::SinCuenta);
        }

        $localEmail = $user->email;

        // Paso 12: bloqueo vivo (§F.4.5, sin reabrir OPEN-AUTH-32).
        $lockout = $this->lockService->findLiveOrCloseExpired($localEmail);

        if ($lockout !== null) {
            $this->attempts->recordLockedAttempt($localEmail, $user, LoginMethod::Sso);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::CuentaBloqueada);
        }

        // Paso 12: solo `activo` entra (RN-AUTH-23) — `pendiente` no
        // entra por SSO (OPEN-AUTH-39, `§F.0.3` punto 3) e `inactivo`/
        // borrado comparten la misma salida genérica.
        if ($user->status !== UserStatus::Activo) {
            $this->attempts->recordNonActiveState($localEmail, $user, LoginMethod::Sso);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::AccesoDenegado);
        }

        // Paso 12: MfaPolicy completo, sin excepciones (RN-AUTH-111). El
        // emparejamiento candidato se aplaza hasta superar el desafío.
        if ($this->mfaPolicy->hasUsableFactor($user)) {
            if ($pendingMatch) {
                $this->mfaChallenges->stashPendingSsoMatch(
                    $request,
                    $identityProvider->id,
                    $identity->providerUserId,
                    $identity->email,
                    $identity->emailVerified,
                );
            }

            $this->mfaChallenges->open($request, $user, LoginMethod::Sso);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::SegundoFactor);
        }

        $isEnforced = $this->mfaPolicy->resolve($user)->isEnforced();

        // Paso 13, RN-AUTH-109: el emparejamiento escribe solo la fila de
        // user_identities, en la misma transacción que crea la sesión.
        $result = DB::transaction(function () use ($request, $user, $localEmail, $pendingMatch, $identityProvider, $identity) {
            if ($pendingMatch) {
                $this->identityLinking->linkViaSso(
                    $user,
                    $identityProvider,
                    $identity->providerUserId,
                    $identity->email,
                    $identity->emailVerified,
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
}
