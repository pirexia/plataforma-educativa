<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Domain\PasswordResetTokenRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * operacion.md §4.1: borra los tokens de restablecimiento vencidos.
 * Artefacto transitorio (datos.md §A.9) — no se conserva traza, la pide
 * la auditoría de `password_reset_requested`, no esta tabla.
 */
class PurgeExpiredPasswordResetTokens implements ShouldQueue
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

    public function handle(PasswordResetTokenRepository $repository): void
    {
        $repository->deleteExpired();
    }
}
