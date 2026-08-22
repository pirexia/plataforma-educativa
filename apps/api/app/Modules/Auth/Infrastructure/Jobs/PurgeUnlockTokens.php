<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Domain\Models\AccountLockout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * operacion.md §4.1: pone a `NULL` `unlock_token_hash`/`unlock_token_expires_at`
 * de los bloqueos cuyo token venció. **No toca la fila del bloqueo**
 * (`account_lockouts` no es append-only: `plataforma_app` sí puede
 * actualizarla).
 */
class PurgeUnlockTokens implements ShouldQueue
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
        AccountLockout::query()
            ->whereNotNull('unlock_token_hash')
            ->where('unlock_token_expires_at', '<', now())
            ->each(fn (AccountLockout $lockout) => $lockout->update([
                'unlock_token_hash' => null,
                'unlock_token_expires_at' => null,
            ]));
    }
}
