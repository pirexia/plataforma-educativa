<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\AccountLockService;
use App\Modules\Auth\Domain\ExternalIdentity;
use App\Modules\Auth\Domain\ExternalIdentityException;
use App\Modules\Auth\Domain\ExternalIdentityFailure;
use App\Modules\Auth\Domain\ExternalIdentityProvider;
use App\Modules\Auth\Domain\LinkMethod;
use App\Modules\Auth\Domain\LoginMethod;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Auth\Domain\Models\UserIdentity;
use App\Modules\Auth\Domain\OAuthCallbackOutcome;
use App\Modules\Core\Domain\UserDirectory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * `funcional.md §E.4.2` (resolución de identidad y creación de sesión) y
 * `§E.4.4` (vinculación desde el perfil): las dos ramas de `intent`
 * confluyen en el mismo *callback*. Orquesta exactamente el mismo orden
 * de comprobaciones que el login local (`RN-AUTH-94`): bloqueo vivo,
 * estado de la cuenta, `MfaPolicy` completo — sin replicar su lógica.
 */
final class GoogleOAuthCallbackService
{
    /** funcional.md §B.6.2, RN-AUTH-45. Mismo nombre que SessionController. */
    private const DEVICE_COOKIE_NAME = 'pge_device';

    public function __construct(
        private readonly RateLimitGuard $rateLimits,
        private readonly OAuthProviderAvailability $availability,
        private readonly ExternalIdentityProvider $provider,
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
        // operacion.md §E.6: límite de tasa más holgado que el de
        // arranque (es una navegación legítima que ocurre una vez por
        // login). RN-AUTH-93/§E.4.1: este endpoint nunca responde
        // problem+json, ni siquiera por límite de tasa — RateLimitGuard::
        // exceeded() no lanza, y el límite excedido se presenta como
        // error_proveedor, el mismo código con el que ya se presenta
        // cualquier fallo transitorio sin distinguir la causa exacta.
        if ($this->rateLimits->exceeded('oauth_callback_ip', (string) $request->ip())) {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::ErrorProveedor);
        }

        // operacion.md §E.1, issue #140: con AUTH_OAUTH_DRIVER=none no
        // hay proveedor que resolver — ni el real ni el simulado, y
        // ExternalIdentityProvider resuelve NullIdentityProvider, que
        // lanza si se invoca (defensa en profundidad, no pensada para
        // producir un código de resultado). Se corta aquí, antes de
        // tocarlo: mismo código que un `state` inválido, porque con
        // `none` nadie ha podido arrancar el flujo (funcional.md §E.10).
        if (! $this->availability->isConfigured()) {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::EstadoNoValido);
        }

        // funcional.md §E.4.1 punto 3.3: de un solo uso, consumido junto
        // al state/PKCE que gestiona el adaptador. Ausente o corrupto
        // (sesión perdida, dos flujos solapados) ⇒ mismo criterio que un
        // `state` inválido: no se hace nada, 302 estado_no_valido.
        $intent = OAuthAuthorizationService::pullIntent($request);

        try {
            // Internamente: state en tiempo constante, de un solo uso
            // (RN-AUTH-91), después `error=access_denied` (RN-AUTH-91,
            // funcional.md §E.4.2 paso 4), después el canje de código.
            $identity = $this->provider->completeAuthorization();
        } catch (ExternalIdentityException $e) {
            return OAuthCallbackResult::outcome(match ($e->failure) {
                ExternalIdentityFailure::ConsentDenied => OAuthCallbackOutcome::Cancelado,
                ExternalIdentityFailure::InvalidState => OAuthCallbackOutcome::EstadoNoValido,
                ExternalIdentityFailure::ProviderUnreachable => OAuthCallbackOutcome::ErrorProveedor,
            });
        }

        if ($intent === null) {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::EstadoNoValido);
        }

        return $intent === 'link'
            ? $this->handleLink($request, $identity)
            : $this->handleLogin($request, $identity);
    }

    /**
     * `funcional.md §E.4.4`. El sujeto es **siempre** el usuario de la
     * sesión que arrancó el flujo (`permisos.md §E.2`) — nunca se busca
     * por correo ni se resuelve por `sub`.
     */
    private function handleLink(Request $request, ExternalIdentity $identity): OAuthCallbackResult
    {
        $user = Auth::user();

        // Defensivo: intent=link exige sesión completa AL ARRANCAR
        // (OAuthAuthorizationService::begin()); si se perdió entre medias
        // (logout en otra pestaña), no hay sujeto al que vincular. No es
        // un caso enumerado en funcional.md §E.4.4 —requiere que la
        // cookie de sesión sobreviva pero Auth::user() no—, y se trata
        // con el mismo criterio que cualquier otro estado inconsistente
        // de este canal: la salida genérica de "vuelve a intentarlo".
        if (! $user instanceof User) {
            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::EstadoNoValido);
        }

        try {
            // DB::transaction() envuelve en un SAVEPOINT (ya hay una
            // transacción en curso, la del propio callback/petición): sin
            // esto, la violación de índice deja la conexión en estado
            // "aborted" de PostgreSQL y cualquier consulta posterior de la
            // misma petición fallaría, aunque el error se capture en PHP.
            DB::transaction(function () use ($user, $identity): void {
                $this->identityLinking->link(
                    $user,
                    $identity->providerUserId,
                    $identity->email,
                    $identity->emailVerified,
                    LinkMethod::Perfil,
                );
            });
        } catch (UniqueConstraintViolationException $e) {
            // RN-AUTH-89, CA-AUTH-223: el rechazo lo produce el índice
            // único, no una comprobación previa con condición de carrera.
            return OAuthCallbackResult::outcome(
                str_contains($e->getMessage(), 'user_identities_tenant_provider_subject_unique')
                    ? OAuthCallbackOutcome::ProveedorYaVinculado
                    : OAuthCallbackOutcome::YaVinculado
            );
        }

        return OAuthCallbackResult::outcome(OAuthCallbackOutcome::Vinculado);
    }

    /**
     * `funcional.md §E.4.2` pasos 6-10.
     */
    private function handleLogin(Request $request, ExternalIdentity $identity): OAuthCallbackResult
    {
        // Paso 6: RN-AUTH-86, la identidad es `sub`, nunca el correo.
        // RN-AUTH-100: el correo se normaliza igual que el local, sin
        // normalización propia de un proveedor concreto.
        $normalizedEmail = $this->loginService->normalize($identity->email);

        // Paso 7a: vínculo vivo ⇒ ese es el usuario, sin consultar el
        // correo (RN-AUTH-86, CA-AUTH-212/CA-AUTH-213).
        $link = UserIdentity::query()
            ->where('provider', 'google')
            ->where('subject', $identity->providerUserId)
            ->first();

        $pendingFusion = false;

        if ($link !== null) {
            $user = $link->user;
        } elseif ($identity->emailVerified) {
            // Paso 7b: fusión candidata.
            $user = $this->userDirectory->findActiveByEmail($normalizedEmail);
            $pendingFusion = $user !== null;
        } else {
            // Paso 7c: sin vínculo y sin verificar. No se consulta si
            // existe cuenta local — la respuesta debe ser indistinguible
            // de "no hay cuenta" (RN-AUTH-87, funcional.md §E.4.6).
            $user = null;
        }

        if ($user === null) {
            // Pasos 7c/7d, y el vínculo huérfano de un usuario borrado:
            // una sola salida genérica, sin incrementar el bloqueo
            // (RN-AUTH-14 no aplica — ninguna credencial nuestra se
            // probó, datos.md §E.3.2).
            $this->attempts->recordFederatedNoLink($normalizedEmail);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::SinCuenta);
        }

        // RN-AUTH-86: a partir de aquí el correo del proveedor deja de
        // importar — se usa el correo local para bloqueo/telemetría,
        // igual que el login local.
        $localEmail = $user->email;

        // Paso 8.1: bloqueo vivo (funcional.md §E.6, OPEN-AUTH-32).
        $lockout = $this->lockService->findLiveOrCloseExpired($localEmail);

        if ($lockout !== null) {
            $this->attempts->recordLockedAttempt($localEmail, $user, LoginMethod::Google);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::CuentaBloqueada);
        }

        // Paso 8.2: solo `activo` entra (RN-AUTH-23). `pendiente` e
        // `inactivo` comparten la misma salida genérica.
        if ($user->status !== UserStatus::Activo) {
            $this->attempts->recordNonActiveState($localEmail, $user, LoginMethod::Google);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::AccesoDenegado);
        }

        // Paso 8.3: MfaPolicy completo, las cuatro ramas de §C.4.4 sin
        // cambios (RN-AUTH-94). Con factor confirmado, se abre desafío y
        // la fusión candidata se aplaza hasta superarlo
        // (MfaChallengeService::verify(), funcional.md §E.4.2 paso 8.3).
        if ($this->mfaPolicy->hasUsableFactor($user)) {
            if ($pendingFusion) {
                $this->mfaChallenges->stashPendingFederatedLink(
                    $request,
                    $identity->providerUserId,
                    $identity->email,
                    $identity->emailVerified,
                );
            }

            $this->mfaChallenges->open($request, $user, LoginMethod::Google);

            return OAuthCallbackResult::outcome(OAuthCallbackOutcome::SegundoFactor);
        }

        // Sin desafío que superar: obligado en gracia o no obligado ⇒
        // sesión plena; obligado y vencido ⇒ sesión restringida — la
        // establece igual, MfaPolicy::resolve() solo decide el código de
        // resultado (RequireMfaEnrollment ya la restringirá en la
        // petición siguiente, aquí solo evitamos el viaje de más).
        $isEnforced = $this->mfaPolicy->resolve($user)->isEnforced();

        // funcional.md §E.4.3 punto 1, RN-AUTH-88: la fusión escribe solo
        // la fila de user_identities, en la misma transacción que crea la
        // sesión — mismo orden que MfaChallengeService::verify() ya
        // establece para la fusión diferida por MFA (link() antes que
        // establish()), por consistencia dentro del propio paso.
        $result = DB::transaction(function () use ($request, $user, $localEmail, $pendingFusion, $identity) {
            if ($pendingFusion) {
                $this->identityLinking->link(
                    $user,
                    $identity->providerUserId,
                    $identity->email,
                    $identity->emailVerified,
                    LinkMethod::FusionAutomatica,
                );
            }

            return $this->establisher->establish(
                $request,
                $user,
                $localEmail,
                $request->cookie(self::DEVICE_COOKIE_NAME),
                LoginMethod::Google,
            );
        });

        return $isEnforced
            ? OAuthCallbackResult::outcome(OAuthCallbackOutcome::AltaMfaRequerida, $result->newDeviceCookieValue)
            : OAuthCallbackResult::success($result->newDeviceCookieValue);
    }
}
