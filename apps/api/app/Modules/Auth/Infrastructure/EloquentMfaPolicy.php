<?php

namespace App\Modules\Auth\Infrastructure;

use App\Models\User;
use App\Modules\Auth\Domain\Events\MfaObligationStarted;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Auth\Domain\Models\UserMfaExemption;
use App\Modules\Auth\Domain\Models\UserMfaObligation;
use App\Modules\Auth\Domain\MfaObligation;
use App\Modules\Auth\Domain\MfaObligationTrigger;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * funcional.md §C.4.7, RN-AUTH-62. **La única** implementación de
 * `MfaPolicy`. No cachea entre peticiones — memoiza por instancia
 * (`scoped()` en `AuthServiceProvider`, una instancia por petición HTTP,
 * reiniciada entre trabajos de cola) para que las tres o cuatro consultas
 * de una misma petición no sean tres o cuatro viajes a la base de datos
 * (operacion.md §C.7).
 */
final class EloquentMfaPolicy implements MfaPolicy
{
    /** @var array<int, MfaObligation> */
    private array $obligationMemo = [];

    /** @var array<int, bool> */
    private array $usableFactorMemo = [];

    /** @var array<int, list<string>> */
    private array $requiredByRolesMemo = [];

    public function __construct(
        private readonly TenantSettingsReader $settings,
        private readonly TenantContext $tenantContext,
    ) {}

    public function resolve(User $user): MfaObligation
    {
        if (array_key_exists($user->id, $this->obligationMemo)) {
            return $this->obligationMemo[$user->id];
        }

        return $this->obligationMemo[$user->id] = $this->resolveUncached($user);
    }

    private function resolveUncached(User $user): MfaObligation
    {
        // 1. Excepción temporal viva (funcional.md §C.4.7 punto 1).
        if ($this->hasLiveExemption($user)) {
            return MfaObligation::notObligated();
        }

        // 2. ¿Algún rol vivo exige MFA? RPERM-007: basta uno (OR, no AND).
        if ($this->requiredByRoleCodes($user) === []) {
            return MfaObligation::notObligated();
        }

        // 3. ¿Tiene factor confirmado y utilizable? Si sí, está cumpliendo.
        if ($this->hasUsableFactor($user)) {
            return MfaObligation::notObligated();
        }

        // 4. Se materializa o se lee la obligación abierta.
        $obligation = $this->openObligation($user) ?? $this->createObligation($user, MfaObligationTrigger::RolAsignado);

        return now()->greaterThan($obligation->grace_deadline_at)
            ? MfaObligation::enforced($obligation->grace_deadline_at)
            : MfaObligation::inGrace($obligation->grace_deadline_at);
    }

    public function hasUsableFactor(User $user): bool
    {
        if (array_key_exists($user->id, $this->usableFactorMemo)) {
            return $this->usableFactorMemo[$user->id];
        }

        $allowedMethods = $this->settings->mfaAllowedMethods();

        return $this->usableFactorMemo[$user->id] = MfaFactor::query()
            ->where('user_id', $user->id)
            ->whereNotNull('confirmed_at')
            ->whereIn('method', $allowedMethods)
            ->exists();
    }

    public function requiredByRoleCodes(User $user): array
    {
        if (array_key_exists($user->id, $this->requiredByRolesMemo)) {
            return $this->requiredByRolesMemo[$user->id];
        }

        // RN-AUTH-62: una sola consulta EXISTS-shaped (COUNT sobre la
        // pivote, sin cargar la colección de roles en memoria), con
        // predicado de tenant explícito además de la RLS (RN-AUTH-07).
        return $this->requiredByRolesMemo[$user->id] = $user->roles()
            ->where('roles.tenant_id', $this->tenantContext->tenantId())
            ->where('roles.mfa_required', true)
            ->pluck('roles.code')
            ->all();
    }

    public function materialize(User $user, MfaObligationTrigger $trigger): void
    {
        if ($this->hasLiveExemption($user) || $this->hasUsableFactor($user) || $this->requiredByRoleCodes($user) === []) {
            return;
        }

        if ($this->openObligation($user) !== null) {
            return;
        }

        $this->createObligation($user, $trigger);
    }

    private function openObligation(User $user): ?UserMfaObligation
    {
        return UserMfaObligation::query()
            ->where('user_id', $user->id)
            ->whereNull('resolved_at')
            ->first();
    }

    /**
     * El índice único parcial de datos.md §C.5 es lo que hace esto
     * seguro bajo concurrencia, no el `lockForUpdate()`: si dos
     * peticiones simultáneas llegan aquí para el mismo usuario, una gana
     * y la otra recibe la violación de unicidad — se resuelve releyendo.
     */
    private function createObligation(User $user, MfaObligationTrigger $trigger): UserMfaObligation
    {
        return DB::transaction(function () use ($user, $trigger): UserMfaObligation {
            $existing = UserMfaObligation::query()
                ->where('user_id', $user->id)
                ->whereNull('resolved_at')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $obligatedSince = now();
            $deadline = $obligatedSince->clone()->addDays($this->settings->mfaGracePeriodDays());

            $obligation = UserMfaObligation::create([
                'user_id' => $user->id,
                'obligated_since' => $obligatedSince,
                'grace_deadline_at' => $deadline,
                'trigger' => $trigger->value,
            ]);

            event(new MfaObligationStarted(
                $this->tenantContext->tenantId(),
                $user->public_id,
                $deadline,
                $trigger->value,
            ));

            return $obligation;
        });
    }

    private function hasLiveExemption(User $user): bool
    {
        return UserMfaExemption::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();
    }
}
