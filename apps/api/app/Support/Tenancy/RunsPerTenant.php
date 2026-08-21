<?php

namespace App\Support\Tenancy;

use Closure;

/**
 * ADR-033 §8: comandos de consola y tareas programadas no tienen tenant
 * por defecto. Uno que toque datos de negocio usa este trait para iterar
 * los tenants activos; sin él, la primera consulta lanza
 * TenantContextMissing en vez de correr "sin filtro".
 *
 * Si $callback despacha un job, hazlo como sentencia suelta dentro del
 * callback, nunca como su valor de retorno — ver el aviso en
 * TenantContext::runFor().
 */
// Consumido desde 1.1 por PurgeCoreMaintenanceCommand (operacion.md §4).
// Verificado también por test (RunsPerTenantTest).
trait RunsPerTenant
{
    protected function eachTenant(Closure $callback): void
    {
        $context = app(TenantContext::class);

        $tenants = $context->runAsPlatform(
            fn () => Tenant::query()->where('status', TenantStatus::Activo)->get()
        );

        foreach ($tenants as $tenant) {
            $context->runFor($tenant->id, function () use ($callback, $tenant): void {
                $callback($tenant);
            });
        }
    }
}
