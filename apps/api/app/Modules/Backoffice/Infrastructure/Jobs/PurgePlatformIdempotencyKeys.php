<?php

namespace App\Modules\Backoffice\Infrastructure\Jobs;

use App\Modules\Backoffice\Domain\Models\PlatformIdempotencyKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * `ADR-038 §8.2/§8.3`, docblock de
 * `2026_09_15_100200_create_platform_idempotency_keys_table` (issue
 * #221, hallazgo Medio de revisión independiente de `1.6c`: la clase que
 * ese docblock prometía no existía todavía). Versión de plataforma de
 * `App\Modules\Core\Infrastructure\Jobs\PurgeExpiredIdempotencyKeys`
 * (`operacion.md §4` de `REQ-CORE`), mismo criterio de purga física a las
 * 24h. `PlatformIdempotencyKey` no lleva `SoftDeletes` (no tiene
 * `deleted_at`, a diferencia de su homóloga de tenant): `->delete()` ya
 * es físico aquí, sin necesitar `forceDelete()`.
 *
 * Sin contexto de tenant que restaurar (`ADR-038 §8`, la propia
 * migración): `platform_idempotency_keys` es tabla de plataforma sin
 * `tenant_id`, y `plataforma_platform` es su único escritor.
 */
class PurgePlatformIdempotencyKeys implements ShouldQueue
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
        PlatformIdempotencyKey::query()
            ->where('expires_at', '<', now())
            ->delete();
    }
}
