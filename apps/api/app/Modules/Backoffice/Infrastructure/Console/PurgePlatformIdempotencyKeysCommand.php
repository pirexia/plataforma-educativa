<?php

namespace App\Modules\Backoffice\Infrastructure\Console;

use App\Modules\Backoffice\Infrastructure\Jobs\PurgePlatformIdempotencyKeys;
use Illuminate\Console\Command;

/**
 * `operacion.md §6.2` (issue #221). Diaria, mismo criterio que su
 * homóloga de tenant (`core:purge-maintenance` → `PurgeExpiredIdempotencyKeys`,
 * `Schedule::job(new PurgeExpiredIdempotencyKeys)->daily()` en
 * `routes/console.php`). A diferencia de las otras tareas `bo:*`, que
 * despachan trabajos sensibles a tenant, ésta no necesita `RunsPerTenant`:
 * `platform_idempotency_keys` es una tabla de plataforma sin `tenant_id`.
 */
class PurgePlatformIdempotencyKeysCommand extends Command
{
    protected $signature = 'bo:purge-idempotency-keys';

    protected $description = 'Purga físicamente las claves de idempotencia de plataforma vencidas (operacion.md §6.2)';

    public function handle(): int
    {
        PurgePlatformIdempotencyKeys::dispatch();

        return self::SUCCESS;
    }
}
