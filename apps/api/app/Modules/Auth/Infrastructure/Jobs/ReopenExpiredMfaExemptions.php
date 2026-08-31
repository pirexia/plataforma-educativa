<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Domain\MfaObligationTrigger;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Auth\Domain\Models\UserMfaExemption;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * funcional.md §D.4.9, operacion.md §D.4/§D.4.1. Programada cada hora
 * (`auth:mfa-obligations`, pieza 4): recorre las excepciones caducadas en
 * las últimas `AUTH_MFA_EXEMPTION_REOPEN_WINDOW_HOURS` (48) sin revocar,
 * y reabre la obligación de cada titular con plazo de gracia completo
 * (`RN-AUTH-82`). No es la única garantía — `MfaPolicy::resolve()` es la
 * red de seguridad en la siguiente petición del titular (§D.4.9) — y por
 * eso no marca filas como procesadas: `materialize()` ya es idempotente.
 *
 * El `trigger` es `exencion_vencida` también cuando la excepción se
 * revocó a mano (`DELETE /mfa-exemptions/{public_id}`, que llama a
 * `MfaPolicy::materialize()` directamente, no a este trabajo): esta
 * tarea cubre solo el camino de la caducidad natural.
 */
class ReopenExpiredMfaExemptions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $tenantId,
    ) {
        $this->onQueue('auth-maintenance');
    }

    public function handle(MfaPolicy $policy): void
    {
        $windowHours = (int) config('auth-local.mfa.exemption_reopen_window_hours');

        UserMfaExemption::query()
            ->whereNull('revoked_at')
            ->where('expires_at', '<=', now())
            ->where('expires_at', '>=', now()->subHours($windowHours))
            ->with('user')
            ->each(function (UserMfaExemption $exemption) use ($policy): void {
                $policy->materialize($exemption->user, MfaObligationTrigger::ExencionVencida);
            });
    }
}
