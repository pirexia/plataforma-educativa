<?php

namespace App\Modules\Auth\Infrastructure\Console;

use App\Modules\Auth\Infrastructure\Jobs\PurgeSamlAuthRequests;
use App\Modules\Auth\Infrastructure\Jobs\PurgeSamlConsumedAssertions;
use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * `operacion.md §G.4` (REQ-AUTH-004, 1.4c). Diaria. Despacha las dos
 * purgas de la correlación SAML, para cada tenant activo. Comando propio
 * y no una fila más de `auth:purge-maintenance`: `operacion.md §G.4` lo
 * nombra explícitamente como `auth:purge-saml-correlation`.
 */
class PurgeSamlCorrelationCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'auth:purge-saml-correlation';

    protected $description = 'Despacha la purga de saml_auth_requests y saml_consumed_assertions para cada tenant activo';

    public function handle(): int
    {
        $this->eachTenant(function (Tenant $tenant): void {
            PurgeSamlAuthRequests::dispatch($tenant->id);
            PurgeSamlConsumedAssertions::dispatch($tenant->id);
        });

        return self::SUCCESS;
    }
}
