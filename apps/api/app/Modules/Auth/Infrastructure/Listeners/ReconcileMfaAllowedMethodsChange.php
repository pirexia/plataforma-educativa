<?php

namespace App\Modules\Auth\Infrastructure\Listeners;

use App\Models\User;
use App\Modules\Auth\Domain\MfaObligationTrigger;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Core\Domain\Events\TenantSettingsUpdated;
use App\Modules\Core\Domain\TenantSettingsReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * funcional.md §C.4.12 punto 5, `RN-AUTH-69`, `CA-AUTH-133`. "Quitar un
 * método invalida los factores existentes de ese método: dejan de ser
 * utilizables en el login y dejan de contar como cumplimiento, con lo
 * que sus titulares vuelven a estar obligados con plazo de gracia
 * completo." Reacciona a `TenantSettingsUpdated` (REQ-CORE) en vez de a
 * un evento propio: el cambio puede tocar cualquier grupo de
 * `tenant/settings`, no solo `security.mfa_allowed_methods`, así que la
 * reconciliación se limita a comparar contra el estado actual (ya
 * cacheado de nuevo por `TenantSettingsController::update()` antes de
 * publicar el evento) — es un no-op barato si nada relevante cambió.
 *
 * `INV-007`: consume el evento público de `REQ-CORE`, no importa nada
 * interno suyo.
 */
class ReconcileMfaAllowedMethodsChange implements ShouldQueue
{
    use Queueable;

    /**
     * Hallazgo propio (ver `MaterializeMfaObligationsForRole`, mismo
     * fallo): un *listener* `ShouldQueue` no admite inyección por
     * parámetros extra de `handle()` — `ArgumentCountError` en cuanto la
     * cola procesa de verdad (incluido `QUEUE_CONNECTION=sync`). Las
     * dependencias van al constructor.
     */
    public function __construct(
        private readonly TenantSettingsReader $settings,
        private readonly MfaPolicy $policy,
    ) {
        $this->onQueue('auth-maintenance');
    }

    public function handle(TenantSettingsUpdated $event): void
    {
        $allowed = $this->settings->mfaAllowedMethods();

        $affectedUserIds = MfaFactor::query()
            ->whereNotNull('confirmed_at')
            ->whereNotIn('method', $allowed)
            ->distinct()
            ->pluck('user_id');

        if ($affectedUserIds->isEmpty()) {
            return;
        }

        User::query()
            ->whereIn('id', $affectedUserIds)
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    // materialize() ya comprueba hasUsableFactor() con los
                    // métodos permitidos ACTUALES: si al usuario le queda
                    // otro factor confirmado de un método sí admitido, no
                    // hace nada — solo abre obligación quien se quedó sin
                    // ninguno utilizable.
                    $this->policy->materialize($user, MfaObligationTrigger::MetodoRetirado);
                }
            });
    }
}
