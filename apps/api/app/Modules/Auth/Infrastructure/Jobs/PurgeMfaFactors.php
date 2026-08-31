<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Domain\Models\MfaFactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * funcional.md §D.2.2, §D.4.1 (issue #109, pieza 4 de 1.3b). Borrado
 * **físico** de los factores borrados lógicamente hace más de
 * `AUTH_MFA_FACTOR_PURGE_DAYS` (30). `datos.md §C.11`: "la única tabla
 * del producto donde el borrado lógico de INV-004 conserva una
 * credencial viva, y por eso tiene plazo corto y propio" — el plazo
 * estaba escrito desde 1.3 y esta clase es lo que lo aplica de verdad
 * (`RN-AUTH-85`).
 */
class PurgeMfaFactors implements ShouldQueue
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

    public function handle(): void
    {
        $purgeDays = (int) config('auth-local.mfa.factor_purge_days');

        MfaFactor::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays($purgeDays))
            ->forceDelete();
    }
}
