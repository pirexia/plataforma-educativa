<?php

namespace App\Modules\Auth\Infrastructure\Jobs;

use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * operacion.md §4, §4.1, datos.md §A.9: retención de
 * `AUTH_LOGIN_ATTEMPT_RETENTION_DAYS` (90 por defecto). Borrado **físico**
 * con el rol propietario: `tenantTableAppendOnly()` revoca `DELETE` a
 * `plataforma_app` y a `plataforma_platform` (misma mecánica que la purga
 * de `audit_logs` de `REQ-PRIV-006`).
 *
 * `RunsPerTenant`/el worker de colas ya fija el `tenant_id` en la conexión
 * por defecto (`pgsql`) al procesar el job, pero **no** en `pgsql_owner`:
 * la RLS de esta tabla tiene `FORCE`, así que también se aplica al
 * propietario. Sin propagar el GUC a esa conexión, el `DELETE` correría
 * sin error y sin borrar nada — exactamente el síntoma que
 * `operacion.md §9` documenta.
 */
class PurgeLoginAttempts implements ShouldQueue
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

    public function handle(TenantContext $tenantContext): void
    {
        $tenantContext->applyToConnection('pgsql_owner');

        $retentionDays = (int) config('auth-local.login_attempt_retention_days');

        DB::connection('pgsql_owner')->table('login_attempts')
            ->where('tenant_id', $this->tenantId)
            ->where('attempted_at', '<', now()->subDays($retentionDays))
            ->delete();
    }
}
