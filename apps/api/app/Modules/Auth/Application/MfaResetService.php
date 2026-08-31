<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\Events\MfaReset as MfaResetEvent;
use App\Modules\Auth\Domain\MfaObligationTrigger;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Auth\Domain\Models\MfaRecoveryCode;
use App\Modules\Auth\Domain\Models\MfaReset;
use App\Modules\Auth\Domain\SessionEndReason;
use App\Modules\Auth\Domain\SessionRevoker;
use App\Modules\Auth\Infrastructure\Jobs\SendMfaFactorRemovedEmail;
use App\Support\Api\ApiException;
use App\Support\Http\RequestId;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * funcional.md §C.4.10. Restablecimiento de MFA por el administrador.
 * `RN-AUTH-67`: nadie se restablece a sí mismo, tenga el permiso que
 * tenga — si pudiera, `mfa.eliminar` sería un interruptor de apagado de
 * toda la obligatoriedad.
 */
final class MfaResetService
{
    public function __construct(
        private readonly SessionRevoker $sessionRevoker,
        private readonly MfaPolicy $policy,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @throws ApiException forbidden() (403, `RN-AUTH-67`)
     */
    public function reset(User $actor, User $target, string $reason): void
    {
        if ($actor->id === $target->id) {
            // issue #115 (hallazgo propio de 1.3b): api.md §C.5 documenta
            // que este 403 "es distinto de no tener permiso, y se
            // distingue en el mensaje", pero antes de este cambio se
            // lanzaba sin detailKey — idéntico al 403 genérico de
            // RequirePermission. Se corrige aquí porque es exactamente el
            // criterio que RN-AUTH-81 (autoexención) replica.
            throw ApiException::forbidden('auth.validation.mfa_reset_self');
        }

        DB::transaction(function () use ($actor, $target, $reason): void {
            $factors = MfaFactor::query()->where('user_id', $target->id)->get();

            foreach ($factors as $factor) {
                $factor->delete();
            }

            MfaRecoveryCode::query()->where('user_id', $target->id)->delete();

            $this->sessionRevoker->revokeAllForUser($target, SessionEndReason::CambioCredencial);

            MfaReset::create([
                'user_id' => $target->id,
                'reason' => $reason,
                'factors_removed' => $factors->count(),
                'performed_by' => $actor->id,
                'performed_at' => now(),
                'request_id' => app(RequestId::class)->current(),
            ]);

            // §C.4.10 punto 4: si sigue obligado, obligación nueva con
            // plazo de gracia completo — MfaObligationTrigger::Restablecimiento,
            // no el genérico de la evaluación perezosa.
            $this->policy->materialize($target, MfaObligationTrigger::Restablecimiento);
        });

        event(new MfaResetEvent($this->tenantContext->tenantId(), $target->public_id, $actor->public_id));

        SendMfaFactorRemovedEmail::dispatch(
            recipientEmail: $target->email,
            recipientGivenName: $target->person->given_name ?? '',
            recipientLocale: $target->person->locale ?? 'es-ES',
            tenantName: Tenant::query()->find($this->tenantContext->tenantId())->name ?? '',
            byAdmin: true,
        );
    }
}
