<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\MfaObligationTrigger;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Auth\Domain\Models\UserMfaExemption;
use App\Modules\Auth\Domain\Models\UserMfaObligation;
use App\Support\Api\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * funcional.md §D.4.6, §D.4.8, §D.4.9. Ciclo de vida completo de la
 * excepción temporal nominal (`RN-AUTH-81`, `RN-AUTH-82`, `RN-AUTH-83`).
 */
final class MfaExemptionService
{
    public function __construct(
        private readonly MfaPolicy $policy,
    ) {}

    /**
     * `POST /api/v1/mfa-exemptions`. `RN-AUTH-81`: nadie se concede una
     * excepción a sí mismo. `RN-AUTH-82`: cierra la obligación abierta del
     * usuario en la misma transacción.
     *
     * @throws ApiException forbidden() (403) o conflict() (409)
     */
    public function grant(User $actor, User $target, string $reason, Carbon $expiresAt): UserMfaExemption
    {
        if ($actor->id === $target->id) {
            throw ApiException::forbidden('auth.validation.mfa_exemption_self');
        }

        return DB::transaction(function () use ($target, $reason, $expiresAt, $actor): UserMfaExemption {
            // RN-AUTH-81: comprobación explícita, no dejar que salte el
            // índice único — un 500 por violación de unicidad no es una
            // respuesta. lockForUpdate() sobre las filas vivas del
            // usuario acota la ventana de carrera, igual que
            // EloquentMfaPolicy::createObligation().
            $hasLiveExemption = UserMfaExemption::query()
                ->where('user_id', $target->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->exists();

            if ($hasLiveExemption) {
                throw ApiException::conflict('auth.validation.mfa_exemption_already_live');
            }

            $exemption = UserMfaExemption::create([
                'user_id' => $target->id,
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'granted_by' => $actor->id,
            ]);

            // RN-AUTH-82: cierra la obligación abierta, si la hay, para
            // que la reapertura (caducidad o revocación) dé plazo
            // completo en vez de reutilizar un grace_deadline_at vencido.
            UserMfaObligation::query()
                ->where('user_id', $target->id)
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now()]);

            return $exemption;
        });
    }

    /**
     * `DELETE /api/v1/mfa-exemptions/{public_id}`. `RN-AUTH-83`: no borra
     * — conserva la fila con `revoked_at`/`revoked_by` — y reabre la
     * obligación con plazo completo si el usuario sigue reuniendo las
     * condiciones (`funcional.md §D.4.9`).
     */
    public function revoke(User $actor, UserMfaExemption $exemption): void
    {
        DB::transaction(function () use ($actor, $exemption): void {
            $exemption->revoked_at = now();
            $exemption->revoked_by = $actor->id;
            $exemption->save();

            $this->policy->materialize($exemption->user, MfaObligationTrigger::ExencionVencida);
        });
    }
}
