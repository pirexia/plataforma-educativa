<?php

namespace App\Modules\Core\Infrastructure\Console;

use App\Modules\Core\Domain\TenantAdministrator;
use App\Modules\Core\Domain\TenantInitialSettings;
use App\Modules\Core\Domain\TenantProvisioner;
use App\Modules\Core\Domain\TenantProvisioningOutcome;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * funcional.md §4.7. No es un endpoint (§1.1: el ciclo de vida de
 * tenants es 1.6): aprovisionamiento inicial de un centro ya creado por
 * la vía de plataforma (hoy, a mano contra `pgsql_platform`; 1.6 lo
 * envolverá en el alta del backoffice).
 *
 * ADR-048 §4.8: pasa a inyectar `TenantProvisioner` en vez de instanciar
 * la clase concreta, y gana opciones para los ajustes iniciales — cada
 * una con el valor por defecto que ya tiene la columna, de modo que
 * arrancar un centro por consola no exige teclear nada nuevo. Dos
 * caminos (este comando y `POST /tenants`), una sola implementación
 * (RN-BO-22 aplicado aquí igual).
 */
class ProvisionTenantDefaultsCommand extends Command
{
    protected $signature = 'tenant:provision-defaults
        {slug : Slug del tenant ya existente}
        {--admin-email= : Correo de acceso del primer Administrador de Centro}
        {--admin-given-name= : Nombre del primer Administrador de Centro}
        {--admin-family-name= : Primer apellido del primer Administrador de Centro}
        {--default-locale=es-ES : Idioma por defecto del centro}
        {--active-locale=* : Idiomas activos del centro (repetible). Por defecto, solo el idioma por defecto}
        {--timezone=Europe/Madrid : Zona horaria IANA del centro}
        {--currency=EUR : Moneda ISO 4217 del centro}
        {--autonomous-community= : Comunidad autónoma del centro, si aplica}';

    protected $description = 'Siembra la configuración por defecto, los roles predefinidos y el primer Administrador de Centro de un tenant (funcional.md §4.7)';

    public function handle(TenantProvisioner $provisioner): int
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

        $activeLocales = $this->option('active-locale');
        /** @var list<string> $activeLocales */
        $activeLocales = $activeLocales === [] ? [(string) $this->option('default-locale')] : $activeLocales;

        try {
            $settings = new TenantInitialSettings(
                defaultLocale: (string) $this->option('default-locale'),
                activeLocales: $activeLocales,
                timezone: (string) $this->option('timezone'),
                currency: (string) $this->option('currency'),
                autonomousCommunity: $this->option('autonomous-community') !== null && $this->option('autonomous-community') !== ''
                    ? (string) $this->option('autonomous-community')
                    : null,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $administrator = new TenantAdministrator($adminEmail, $adminGivenName, $adminFamilyName);

        $outcome = $provisioner->provision($tenant, $settings, $administrator);

        $this->info($outcome === TenantProvisioningOutcome::Provisioned
            ? "Tenant «{$tenant->slug}» aprovisionado."
            : "Tenant «{$tenant->slug}» ya estaba aprovisionado: la operación es idempotente.");

        return self::SUCCESS;
    }
}
