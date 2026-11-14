<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Domain\Models\MfaChallenge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * funcional.md §D.2.2, §D.4.1 (issue #109, pieza 4 de 1.3b). Retención de
 * `AUTH_MFA_CHALLENGE_RETENTION_HOURS` (24) sobre los desafíos
 * **consumidos** (`RN-AUTH-85`). Un desafío vivo o vencido sin consumir
 * no se toca aquí: solo `consumed_at` gobierna la purga, tal como
 * `operacion.md §D.4.1`/`CA-AUTH-172` lo describen — crecimiento sin
 * tope de una tabla transitoria, no material de credencial permanente.
 */
class PurgeMfaChallenges implements ShouldQueue
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
        $retentionHours = (int) config('auth-local.mfa.challenge_retention_hours');

        // forceDelete(): un borrado lógico no resolvería "crecimiento sin
        // tope" (RN-AUTH-85) — la fila seguiría contando en la tabla.
        MfaChallenge::query()
            ->whereNotNull('consumed_at')
            ->where('consumed_at', '<', now()->subHours($retentionHours))
            ->forceDelete();
    }
}
