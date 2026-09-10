<?php

namespace App\Modules\Backoffice\Infrastructure\Jobs;

use App\Modules\Backoffice\Domain\Models\PlatformAdminMfaChallenge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * operacion.md §6.2. Desafíos caducados, igual que su homóloga de 1.3
 * (`PurgeMfaChallenges`). Purga física: artefacto transitorio de vida
 * corta, sin dato que conservar.
 */
class PurgePlatformMfaChallenges implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $this->onQueue('backoffice-maintenance');
    }

    public function handle(): void
    {
        PlatformAdminMfaChallenge::query()
            ->where('expires_at', '<', now())
            ->delete();
    }
}
