<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\AccountLockService;
use App\Modules\Auth\Domain\PasswordResetTokenRepository;
use App\Modules\Auth\Domain\SessionEndReason;
use App\Modules\Auth\Domain\SessionRevoker;
use App\Modules\Auth\Domain\UnlockReason;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use Illuminate\Support\Facades\DB;

/**
 * funcional.md §4.5 fase 2. RN-AUTH-22: revoca TODAS las sesiones (a
 * diferencia del cambio auto-servicio de §4.8, que conserva la actual —
 * aquí no hay "sesión actual" porque el flujo no exige estar
 * autenticado). El `updated` sobre `User` lo audita el *observer* de 0.9,
 * sin llamada manual.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly PasswordResetTokenRepository $resetTokens,
        private readonly PasswordPolicyValidator $passwordPolicy,
        private readonly AccountLockService $lockService,
        private readonly SessionRevoker $sessionRevoker,
    ) {}

    /**
     * @throws ApiException gone() si el token no es válido, validation()
     *                      si la contraseña incumple la política
     */
    public function reset(string $token, string $password): void
    {
        $resetToken = $this->resetTokens->findValid($token);

        if ($resetToken === null) {
            throw ApiException::gone();
        }

        $errors = new ValidationErrorBag;
        $this->passwordPolicy->validate('password', $password, $errors);
        $errors->throwIfAny();

        $user = User::query()->where('email', $resetToken->email)->firstOrFail();

        DB::transaction(function () use ($user, $password, $resetToken): void {
            $user->password = $password;
            $user->save();

            $this->resetTokens->consume($resetToken);

            $lockout = $this->lockService->findLiveOrCloseExpired($user->email);

            if ($lockout !== null) {
                // Issue #61: mismo criterio que InvitationRedemptionService.
                $this->lockService->lift($lockout, UnlockReason::Correo, null);
            }

            $this->sessionRevoker->revokeAllForUser($user, SessionEndReason::CambioCredencial);
        });
    }
}
