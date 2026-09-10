<?php

namespace App\Modules\Backoffice\Application;

use App\Modules\Auth\Domain\PasswordPolicy;
use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\Models\PlatformAdminInvitation;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Issue #173, `operacion.md §5` paso 6. Precedente de forma:
 * `App\Modules\Auth\Application\InvitationRedemptionService`.
 *
 * Fija la contraseña — el canje **no** abre sesión (mismo criterio que
 * `RN-AUTH-21` para el tenant: la sesión nace sólo de `POST
 * /auth/session`, aquí `PlatformAuthenticationService::attempt()`). El
 * administrador entra después con su contraseña nueva y, sin MFA
 * confirmado, sólo alcanza `/mfa/*` (`RN-BO-05`) — es ahí, dentro de esa
 * sesión, donde da de alta su segundo factor. Este servicio no lo
 * adelanta.
 *
 * `PasswordPolicy` es la interfaz de `App\Modules\Auth\Domain` que su
 * propio docblock declara pensada para que **1.6** la consuma (misma
 * regla — longitud, complejidad — que `RN-AUTH-01`, configurada una sola
 * vez): se importa la interfaz, nunca la implementación concreta de
 * `Auth` (`INV-007`, «comunicación por interfaces»).
 *
 * Issue #180: límite de tasa por IP, único punto de defensa activa de este
 * *endpoint* anónimo (mismo criterio — `RateLimiter` inline, sin tabla
 * nueva — que `PlatformAuthenticationService::attempt()`; no se reutiliza
 * `Auth\Application\RateLimitGuard` porque su clave pasa por
 * `TenantContext::rateLimitKey()`, y este canje corre sin tenant).
 */
final class PlatformAdminInvitationRedemptionService
{
    private const MAX_ATTEMPTS = 10;

    private const DECAY_SECONDS = 3600;

    public function __construct(
        private readonly PasswordPolicy $passwordPolicy,
        private readonly AdminActionLogRecorder $recorder,
    ) {}

    /**
     * @throws ApiException tooManyRequests() por límite de tasa,
     *                      gone() si el token no es válido,
     *                      validation() si la contraseña incumple la
     *                      política
     */
    public function redeem(string $token, string $password, string $ip): void
    {
        $rateLimitKey = "platform-invitation-redemption:{$ip}";

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_ATTEMPTS)) {
            throw ApiException::tooManyRequests(RateLimiter::availableIn($rateLimitKey));
        }

        RateLimiter::hit($rateLimitKey, self::DECAY_SECONDS);

        // El 410 se decide antes que la política de contraseña, mismo
        // motivo que el precedente de Auth: no filtrar por tiempo de
        // respuesta si el token era válido.
        $invitation = PlatformAdminInvitation::query()
            ->with('admin')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (
            $invitation === null
            || ! $invitation->isLive()
            || $invitation->admin === null
        ) {
            throw ApiException::gone();
        }

        $admin = $invitation->admin;

        $errors = new ValidationErrorBag;

        foreach ($this->passwordPolicy->violations($password) as $code) {
            $params = $code === 'min_length' ? ['min' => $this->passwordPolicy->minLength()] : [];

            $errors->add('password', "auth.validation.password.{$code}", "auth.validation.password.{$code}", $params);
        }

        $errors->throwIfAny();

        DB::connection('pgsql_platform')->transaction(function () use ($invitation, $admin, $password): void {
            $admin->forceFill([
                'password' => $password,
                'password_changed_at' => now(),
            ])->save();

            $invitation->update(['accepted_at' => now()]);

            $this->recorder->record(action: AdminActionLogAction::AdminInvitacionCanjeada, subjectPublicId: $admin->public_id);
        });
    }
}
