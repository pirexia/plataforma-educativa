<?php

namespace App\Modules\Auth\Infrastructure\Console;

use App\Modules\Auth\Infrastructure\Jobs\MaterializeMfaObligations;
use App\Modules\Auth\Infrastructure\Jobs\ReopenExpiredMfaExemptions;
use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * funcional.md §D.4.1.1, operacion.md §D.4.1.1. **Horario**, no diario —
 * a propósito, y a propósito distinto de `auth:purge-maintenance`: una
 * excepción que caduca a las 9:00 no debería dejar a alguien sin
 * exigencia hasta la madrugada, y un plazo de gracia perdido por un
 * *listener* que falló tampoco debería esperar 24 horas. Despacha
 * `MaterializeMfaObligations` y `ReopenExpiredMfaExemptions` por tenant.
 */
class MfaObligationsMaintenanceCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'auth:mfa-obligations';

    protected $description = 'Despacha, por tenant, la materialización de obligaciones de MFA perdidas y la reapertura de las que caducaron por excepción';

    public function handle(): int
    {
        $this->eachTenant(function (Tenant $tenant): void {
            MaterializeMfaObligations::dispatch($tenant->id);
            ReopenExpiredMfaExemptions::dispatch($tenant->id);
        });

        return self::SUCCESS;
    }
}
