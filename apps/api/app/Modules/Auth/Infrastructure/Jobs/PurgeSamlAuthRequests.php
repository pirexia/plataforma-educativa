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
 * `saml_auth_requests` caducadas o consumidas con más de
 * `AUTH_SAML_AUTH_REQUEST_RETENTION_HOURS`. **Es la primera tabla del
 * módulo con artefactos transitorios en base de datos**: el `state` de
 * OIDC vive en la sesión y muere con ella; aquí el ACS llega sin cookie
 * (`ADR-043 §2.1`), así que el estado equivalente se persiste y hay que
 * purgarlo.
 *
 * **Borrado por lotes acotados, nunca un `DELETE` masivo en una
 * transacción** — precedente de
 * `2026_08_31_100100_add_purge_indexes_to_mfa_tables.php` e issues
 * #118/#119. Las dos ramas OR de la consulta tienen su propio índice
 * parcial de apoyo: `saml_auth_requests_tenant_expires_idx` (caducadas
 * sin consumir) y `saml_auth_requests_tenant_consumed_idx` (consumidas).
 */
class PurgeSamlAuthRequests implements ShouldQueue
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

        $retentionHours = (int) config('auth-local.saml.auth_request_retention_hours');
        $cutoff = now()->subHours($retentionHours);

        do {
            $deleted = DB::connection('pgsql_owner')->table('saml_auth_requests')
                ->where('tenant_id', $this->tenantId)
                ->where(function ($query) use ($cutoff): void {
                    $query->where('consumed_at', '<', $cutoff)
                        ->orWhere(function ($query) use ($cutoff): void {
                            $query->whereNull('consumed_at')->where('expires_at', '<', $cutoff);
                        });
                })
                ->limit(self::BATCH_SIZE)
                ->delete();
        } while ($deleted > 0);
    }
}
