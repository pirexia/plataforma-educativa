<?php

namespace App\Modules\Auth\Infrastructure;

use App\Models\User;
use App\Modules\Auth\Domain\AccountLockService;
use App\Modules\Auth\Domain\Models\AccountLockout;
use App\Modules\Auth\Domain\UnlockReason;
use App\Modules\Auth\Infrastructure\Jobs\SendAccountLockedEmail;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * funcional.md §4.4, §8.4. `AccountLockout` es `TenantModel`: la RLS y el
 * *scope* de tenant ya filtran cada consulta por `(tenant_id, …)`
 * (RN-AUTH-06/07) sin código adicional en esta clase.
 */
final class EloquentAccountLockService implements AccountLockService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function findLiveOrCloseExpired(string $email): ?AccountLockout
    {
        $lockout = AccountLockout::query()
            ->where('email', $email)
            ->whereNull('unlocked_at')
            ->first();

        if ($lockout === null) {
            return null;
        }

        if ($lockout->isExpired()) {
            // RN-AUTH-38: se cierra en la misma transacción que la
            // comprueba y se sigue adelante — el índice único parcial de
            // RN-AUTH-17 queda libre de inmediato.
            $this->lift($lockout, UnlockReason::Caducidad, null);

            return null;
        }

        return $lockout;
    }

    public function lock(string $email, ?User $user, int $failedCount): AccountLockout
    {
        $unlockToken = null;
        $unlockTokenHash = null;
        $unlockTokenExpiresAt = null;

        if ($user !== null) {
            $unlockToken = bin2hex(random_bytes(32));
            $unlockTokenHash = hash('sha256', $unlockToken);
            $unlockTokenExpiresAt = now()->addHours((int) config('auth-local.unlock_token_ttl_hours'));
        }

        $lockedAt = now();

        $lockout = AccountLockout::create([
            'email' => $email,
            'user_id' => $user?->id,
            'failed_count' => $failedCount,
            'locked_at' => $lockedAt,
            'unlock_token_hash' => $unlockTokenHash,
            'unlock_token_expires_at' => $unlockTokenExpiresAt,
        ]);

        // RN-AUTH-15: sin cuenta detrás no hay a quién avisar — el
        // bloqueo fantasma no produce ninguna señal distinta hacia quien
        // lo provocó.
        if ($user !== null && $unlockToken !== null) {
            $tenant = Tenant::query()->find($this->tenantContext->tenantId());
            $locale = $user->person->locale ?? 'es-ES';
            $lockoutMinutes = (int) config('auth-local.lockout_minutes');

            SendAccountLockedEmail::dispatch(
                rawUnlockToken: $unlockToken,
                recipientEmail: $user->email,
                recipientGivenName: $user->person->given_name ?? '',
                recipientLocale: $locale,
                tenantName: $tenant->name ?? '',
                tenantSlug: $tenant->slug ?? '',
                failedCount: $failedCount,
                lockedAt: $lockedAt,
                autoUnlocksAt: $lockedAt->clone()->addMinutes($lockoutMinutes),
            );
        }

        return $lockout;
    }

    public function lift(AccountLockout $lockout, UnlockReason $reason, ?User $unlockedBy): void
    {
        $lockout->update([
            'unlocked_at' => now(),
            'unlock_reason' => $reason,
            'unlocked_by' => $unlockedBy?->id,
        ]);
    }
}
