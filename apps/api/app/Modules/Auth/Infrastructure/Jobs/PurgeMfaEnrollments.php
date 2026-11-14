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
 * **físico** de las altas de factor sin confirmar y vencidas —
 * `confirmed_at IS NULL` y `expires_at` en el pasado. Cada una conserva
 * un secreto TOTP cifrado o el hash de un código de correo que ya no
 * tiene finalidad ninguna (`RN-AUTH-85`, `datos.md §D.7`).
 *
 * `forceDelete()`, no `delete()`: es material de credencial, no un
 * registro que valga como traza (a diferencia de `user_mfa_exemptions`).
 * `user_mfa_factors` no es *append-only*: el rol de aplicación puede
 * borrar sin ceremonia, igual que `PurgeUserSessions`.
 */
class PurgeMfaEnrollments implements ShouldQueue
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
        MfaFactor::query()
            ->whereNull('confirmed_at')
            ->where('expires_at', '<', now())
            ->forceDelete();
    }
}
