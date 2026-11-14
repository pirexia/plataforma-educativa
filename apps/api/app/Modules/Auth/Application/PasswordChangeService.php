<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\SessionEndReason;
use App\Modules\Auth\Domain\SessionRevoker;
use App\Modules\Auth\Infrastructure\Jobs\SendPasswordChangedEmail;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * funcional.md §4.8 (`OPEN-AUTH-05`, aprobado). RN-AUTH-36: revoca todas
 * las sesiones salvo la actual — a diferencia del restablecimiento
 * (`PasswordResetService`), que las revoca todas: aquí el usuario está
 * delante y expulsarlo de su propia sesión por hacer lo correcto sería un
 * castigo sin motivo.
 */
final class PasswordChangeService
{
    public function __construct(
        private readonly LoginAttemptRecorder $attempts,
        private readonly PasswordPolicyValidator $passwordPolicy,
        private readonly SessionRevoker $sessionRevoker,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @throws ApiException validation() (422) en cualquiera de los tres
     *                      fallos posibles
     */
    public function change(User $user, string $currentPassword, string $newPassword, string $currentSessionId): void
    {
        // §4.8 punto 3: 422, no 401 — la sesión sigue siendo válida.
        // Punto 4: el fallo cuenta hacia el mismo bloqueo que el login.
        if (! Hash::check($currentPassword, $user->password)) {
            $this->attempts->recordFailure($user->email, $user);

            $errors = new ValidationErrorBag;
            $errors->add('current_password', 'auth.validation.current_password_incorrect', 'auth.validation.current_password_incorrect');
            $errors->throwIfAny();
        }

        $errors = new ValidationErrorBag;
        $this->passwordPolicy->validate('password', $newPassword, $errors);

        if ($newPassword === $currentPassword) {
            $errors->add('password', 'auth.validation.password.same_as_current', 'auth.validation.password.same_as_current');
        }

        $errors->throwIfAny();

        DB::transaction(function () use ($user, $newPassword, $currentSessionId): void {
            $user->password = $newPassword;
            $user->save();

            $this->sessionRevoker->revokeAllForUser($user, SessionEndReason::CambioCredencial, $currentSessionId);
        });

        $tenant = Tenant::query()->find($this->tenantContext->tenantId());

        SendPasswordChangedEmail::dispatch(
            recipientEmail: $user->email,
            recipientGivenName: $user->person->given_name ?? '',
            recipientLocale: $user->person->locale ?? 'es-ES',
            tenantName: $tenant->name ?? '',
        );
    }
}
