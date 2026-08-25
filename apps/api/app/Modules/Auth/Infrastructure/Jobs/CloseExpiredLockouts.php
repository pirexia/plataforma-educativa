<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Domain\AccountLockService;
use App\Modules\Auth\Domain\Models\AccountLockout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * funcional.md §4.4: consolidador perezoso de RN-AUTH-38. El camino de
 * login ya cierra un bloqueo vencido en el momento en que lo encuentra;
 * este trabajo cierra los que nadie ha vuelto a tocar, para que no ocupen
 * el hueco del índice único de RN-AUTH-17 indefinidamente.
 *
 * Hallazgo propio (severidad Baja, documentado en la entrega de la
 * sesión): funcional.md §4.4 remite a "operacion.md §4" para la
 * programación de este trabajo, pero la tabla de operacion.md §4 no lo
 * lista — se programa aquí con la periodicidad que el propio funcional.md
 * describe ("cada pocos minutos"), y se abre issue documentando la
 * omisión para que operacion.md se corrija.
 */
class CloseExpiredLockouts implements ShouldQueue
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

    public function handle(AccountLockService $lockService): void
    {
        AccountLockout::query()
            ->whereNull('unlocked_at')
            ->where('locked_at', '<', now()->subMinutes((int) config('auth-local.lockout_minutes')))
            ->get()
            ->each(fn (AccountLockout $lockout) => $lockService->findLiveOrCloseExpired($lockout->email));
    }
}
