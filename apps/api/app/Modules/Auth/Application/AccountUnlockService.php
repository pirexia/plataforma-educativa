<?php

namespace App\Modules\Auth\Application;

use App\Modules\Auth\Domain\AccountLockService;
use App\Modules\Auth\Domain\Models\AccountLockout;
use App\Modules\Auth\Domain\UnlockReason;
use App\Support\Api\ApiException;

/**
 * funcional.md §4.4 punto 4, segunda vía. RN-AUTH-13: un solo uso,
 * caducidad 24h configurable. `AccountLockout` es `TenantModel`: RLS y el
 * *scope* de tenant ya limitan la búsqueda al tenant activo (RN-AUTH-06),
 * el predicado de tenant explícito (RN-AUTH-07) lo aplican ambos.
 */
final class AccountUnlockService
{
    public function __construct(
        private readonly AccountLockService $lockService,
    ) {}

    /**
     * @throws ApiException gone() si el token no es válido
     */
    public function unlock(string $token): void
    {
        $lockout = AccountLockout::query()
            ->whereNull('unlocked_at')
            ->where('unlock_token_hash', hash('sha256', $token))
            ->first();

        if ($lockout === null
            || $lockout->unlock_token_expires_at === null
            || now()->greaterThanOrEqualTo($lockout->unlock_token_expires_at)
        ) {
            throw ApiException::gone();
        }

        // unlockedBy null: fue el titular, no un administrador (§4.4).
        $this->lockService->lift($lockout, UnlockReason::Correo, null);
    }
}
