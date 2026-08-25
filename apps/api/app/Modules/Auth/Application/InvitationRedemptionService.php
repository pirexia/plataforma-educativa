<?php

namespace App\Modules\Auth\Application;

use App\Models\UserStatus;
use App\Modules\Auth\Domain\AccountLockService;
use App\Modules\Auth\Domain\PasswordResetTokenRepository;
use App\Modules\Auth\Domain\UnlockReason;
use App\Modules\Core\Domain\InvitationRedeemer;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use Illuminate\Support\Facades\DB;

/**
 * funcional.md §4.1. RN-AUTH-20: efectos atómicos. Issue #61: el
 * levantamiento de un bloqueo vivo del correo se registra como
 * `UnlockReason::Correo` — el token de invitación es, igual que el de
 * desbloqueo, un token de un solo uso entregado por correo; documentado
 * como decisión, no como invención silenciosa.
 */
final class InvitationRedemptionService
{
    public function __construct(
        private readonly InvitationRedeemer $redeemer,
        private readonly PasswordPolicyValidator $passwordPolicy,
        private readonly PasswordResetTokenRepository $resetTokens,
        private readonly AccountLockService $lockService,
    ) {}

    /**
     * @throws ApiException gone() si el token no es
     *                      válido, validation() si la
     *                      contraseña incumple la
     *                      política
     */
    public function redeem(string $token, string $password): void
    {
        // §4.1 punto 3: el 410 se decide antes que la política de
        // contraseña, para no filtrar por tiempo de respuesta si el
        // token era válido.
        $user = $this->redeemer->redeem($token);

        $errors = new ValidationErrorBag;
        $this->passwordPolicy->validate('password', $password, $errors);
        $errors->throwIfAny();

        DB::transaction(function () use ($user, $password): void {
            $user->password = $password;
            $user->status = UserStatus::Activo;
            $user->email_verified_at = now();
            $user->save();

            $this->resetTokens->deleteForEmail($user->email);

            $lockout = $this->lockService->findLiveOrCloseExpired($user->email);

            if ($lockout !== null) {
                $this->lockService->lift($lockout, UnlockReason::Correo, null);
            }
        });
    }
}
