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
 * `datos.md §G.10`, `operacion.md §G.4` (REQ-AUTH-004, 1.4c). Purga
 * `saml_consumed_assertions` cuyo `not_on_or_after` ya pasó, con margen:
 * pasada esa marca la aserción ya se rechazaría por ventana temporal
 * (`RN-AUTH-119`) aunque la fila siguiera aquí, así que dejar de
 * recordarla no reabre ninguna protección.
 *
 * Borrado por lotes acotados, mismo criterio que `PurgeSamlAuthRequests`.
 * El margen usa la misma tolerancia de reloj que la validación
 * (`AUTH_SSO_CLOCK_SKEW_SECONDS`), para no purgar una fila que la
 * comparación en tiempo real todavía considerara dentro de ventana.
 */
class PurgeSamlConsumedAssertions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const BATCH_SIZE = 1000;

    public function __construct(
        public readonly int $tenantId,
    ) {
        $this->onQueue('auth-maintenance');
    }

    public function handle(TenantContext $tenantContext): void
    {
        $tenantContext->applyToConnection('pgsql_owner');

        $clockSkewSeconds = (int) config('auth-local.sso.clock_skew_seconds');
        $cutoff = now()->subSeconds($clockSkewSeconds);

        do {
            $deleted = DB::connection('pgsql_owner')->table('saml_consumed_assertions')
                ->where('tenant_id', $this->tenantId)
                ->where('not_on_or_after', '<', $cutoff)
                ->limit(self::BATCH_SIZE)
                ->delete();
        } while ($deleted > 0);
    }
}
