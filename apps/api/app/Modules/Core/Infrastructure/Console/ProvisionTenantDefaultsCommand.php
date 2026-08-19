<?php

namespace App\Modules\Core\Infrastructure\Console;

use App\Modules\Core\Application\ProvisionTenantDefaults;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * funcional.md §4.7. No es un endpoint (§1.1: el ciclo de vida de
 * tenants es 1.6): aprovisionamiento inicial de un centro ya creado por
 * la vía de plataforma (hoy, a mano contra `pgsql_platform`; 1.6 lo
 * envolverá en el alta del backoffice).
 */
class ProvisionTenantDefaultsCommand extends Command
{
    protected $signature = 'tenant:provision-defaults
        {slug : Slug del tenant ya existente}
        {--admin-email= : Correo de acceso del primer Administrador de Centro}
        {--admin-given-name= : Nombre del primer Administrador de Centro}
        {--admin-family-name= : Primer apellido del primer Administrador de Centro}';

    protected $description = 'Siembra la configuración por defecto, los roles predefinidos y el primer Administrador de Centro de un tenant (funcional.md §4.7)';

    public function handle(ProvisionTenantDefaults $provisioner): int
    {
        $tenant = Tenant::findBySlug((string) $this->argument('slug'));

        if ($tenant === null) {
            $this->error("No existe ningún tenant con el slug «{$this->argument('slug')}».");

            return self::FAILURE;
        }

        $adminEmail = $this->option('admin-email');
        $adminGivenName = $this->option('admin-given-name');
        $adminFamilyName = $this->option('admin-family-name');

        if (! is_string($adminEmail) || ! is_string($adminGivenName) || ! is_string($adminFamilyName)
            || $adminEmail === '' || $adminGivenName === '' || $adminFamilyName === '') {
            $this->error('--admin-email, --admin-given-name y --admin-family-name son obligatorios.');

            return self::FAILURE;
        }

        $provisioner->provision($tenant, $adminEmail, $adminGivenName, $adminFamilyName);

        $this->info("Tenant «{$tenant->slug}» aprovisionado (o ya lo estaba: la operación es idempotente).");

        return self::SUCCESS;
    }
}
