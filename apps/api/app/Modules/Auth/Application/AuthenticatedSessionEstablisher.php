<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\LoginMethod;
use App\Support\Api\UserProfilePresenter;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * funcional.md §4.2 punto 6, §C.4.4 punto 10. El tramo final, común, de
 * "crear la sesión de verdad": lo ejecutaba `SessionController::store()`
 * entero antes de 1.3; con el login en dos pasos (`§C.4.4`) el mismo
 * tramo lo necesita también `MfaVerificationsController` al superar el
 * desafío, así que se extrae aquí en vez de duplicarlo.
 *
 * Orden exacto, el mismo que fijó `§4.2`/`§B.4.1` y que `§C.4.4` punto 10
 * confirma para el paso 2: registrar el `exito` (RN-AUTH-63) →
 * `regenerate()` (RN-AUTH-32) → `Auth::login()` → auditar `login`
 * (ADR-039 §4.5, después de `login()` o el actor sale `anonymous`) →
 * *payload* de tenant/actividad → registrar `user_sessions` (con el
 * identificador YA regenerado, `§B.4.1`).
 */
final class AuthenticatedSessionEstablisher
{
    public function __construct(
        private readonly UserProfilePresenter $presenter,
        private readonly TenantContext $tenantContext,
        private readonly AuditRecorder $auditRecorder,
        private readonly SessionRegistrationService $sessionRegistration,
        private readonly LoginAttemptRecorder $attempts,
    ) {}

    public function establish(
        Request $request,
        User $user,
        string $normalizedEmail,
        ?string $deviceCookieValue,
        LoginMethod $method = LoginMethod::Local,
    ): AuthenticatedSessionResult {
        // RN-AUTH-63: el único punto del sistema que pone a cero el
        // contador de fallos consecutivos — porque es el único punto que
        // de verdad crea una sesión. $method: REQ-AUTH-002 (1.4),
        // CA-AUTH-208/CA-AUTH-217 exigen method='google' en el camino
        // federado.
        $this->attempts->recordSuccess($normalizedEmail, $user, $method);

        $request->session()->regenerate();

        Auth::guard('web')->login($user);

        $this->auditRecorder->record($user, 'login');

        $request->session()->put('pge_tenant_id', $this->tenantContext->tenantId());
        $request->session()->put('pge_last_activity_at', now()->timestamp);

        $registration = $this->sessionRegistration->register(
            $user,
            $request->session()->getId(),
            $request->ip(),
            $request->userAgent(),
            $deviceCookieValue,
        );

        return new AuthenticatedSessionResult(
            profile: $this->presenter->present($user),
            newDeviceCookieValue: $registration->newDeviceCookieValue,
        );
    }
}
