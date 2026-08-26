<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Modules\Auth\Domain\Models\UserKnownDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * datos.md §B.7, operacion.md §B.3.1. Un dispositivo sin uso durante
 * `AUTH_KNOWN_DEVICE_RETENTION_DAYS` (365 por defecto) ya no reconoce
 * nada: su cookie caducó (365 días, `RN-AUTH-45`) y volverá a presentarse
 * como nuevo de todos modos. `forceDelete()`: borrado físico.
 */
class PurgeUserKnownDevices implements ShouldQueue
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
        $retentionDays = (int) config('auth-local.known_device_retention_days');

        UserKnownDevice::query()
            ->where('last_seen_at', '<', now()->subDays($retentionDays))
            ->forceDelete();
    }
}
