<?php

namespace App\Modules\Core\Infrastructure\Jobs;

use App\Models\IdempotencyKey;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ADR-038 §8.2/§8.3, datos.md §A.9: purga física diaria, sin borrado
 * lógico (registro técnico de vida corta, 24h). A diferencia de las otras
 * cuatro purgas, **no es por tenant en el filtro**: `expires_at` es un
 * criterio puramente técnico sin datos personales que proteger, así que
 * una única pasada con `TenantContext::runAsPlatform()` basta — pero cada
 * fila borrada sigue siendo, como siempre, de un tenant concreto (su
 * propia columna `tenant_id`), solo que la consulta no necesita iterar
 * tenant a tenant para decidir qué borrar.
 */
class PurgeExpiredIdempotencyKeys implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $this->onQueue('core-maintenance');
    }

    public function handle(TenantContext $tenantContext): void
    {
        $tenantContext->runAsPlatform(function (): void {
            IdempotencyKey::query()
                ->where('expires_at', '<', now())
                ->forceDelete();
        });
    }
}
