<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\MfaObligationTrigger;
use App\Modules\Auth\Domain\MfaPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * funcional.md §D.1.1 punto 15, §D.4.1.1. Ejecución horaria que
 * complementa al *listener* `MaterializeMfaObligationsForRole`
 * (disparo directo en `PATCH /roles`): si ese trabajo encolado se
 * pierde, el plazo de gracia de los usuarios afectados no puede quedar
 * pendiente de que ellos mismos entren primero (`RN-AUTH-65`,
 * `OPEN-AUTH-22`).
 *
 * Candidatos: usuarios activos con al menos un rol vivo que exija MFA.
 * `MfaPolicy::materialize()` es quien decide, por usuario, si hace falta
 * algo — ya comprueba excepción viva, factor utilizable y obligación
 * abierta, así que una segunda ejecución no crea una fila duplicada
 * (`CA-AUTH-173`, garantizado además por el índice único parcial de
 * `datos.md §C.5`).
 */
class MaterializeMfaObligations implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $tenantId,
    ) {
        $this->onQueue('auth-maintenance');
    }

    public function handle(MfaPolicy $policy): void
    {
        User::query()
            ->where('status', UserStatus::Activo)
            ->whereHas('roles', function ($query): void {
                $query->where('mfa_required', true);
            })
            ->chunkById(200, function ($users) use ($policy): void {
                foreach ($users as $user) {
                    $policy->materialize($user, MfaObligationTrigger::RolAsignado);
                }
            });
    }
}
