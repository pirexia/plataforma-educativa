<?php

namespace App\Modules\Core\Infrastructure;

use App\Modules\Core\Domain\AuditQuery;
use App\Modules\Core\Domain\BulkUserImporter;
use App\Modules\Core\Domain\ExportRequestService;
use App\Modules\Core\Domain\InvitationRedeemer;
use App\Modules\Core\Domain\Models\DataExport;
use App\Modules\Core\Domain\Models\TenantSetting;
use App\Modules\Core\Domain\Models\UserImport;
use App\Modules\Core\Domain\Models\UserInvitation;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Modules\Core\Domain\UserDirectory;
use App\Modules\Core\Infrastructure\Console\ProvisionTenantDefaultsCommand;
use App\Modules\Core\Infrastructure\Console\PurgeCoreMaintenanceCommand;
use App\Support\Modules\DeclaresModuleRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * ADR-034 §2, §5, §7: descubierto por ModuleServiceProviderDiscovery
 * (convención de ruta y namespace), sin registro a mano en
 * bootstrap/providers.php. Declara el catálogo de permisos de
 * permisos.md §2 para que `platform:sync-registry` los materialice.
 */
class CoreServiceProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function register(): void
    {
        $this->app->singleton(TenantSettingsCache::class);
        $this->app->bind(TenantSettingsReader::class, EloquentTenantSettingsReader::class);
        $this->app->bind(AuditQuery::class, EloquentAuditQuery::class);
        $this->app->bind(ExportRequestService::class, EloquentExportRequestService::class);
        $this->app->bind(BulkUserImporter::class, EloquentBulkUserImporter::class);
        $this->app->bind(UserDirectory::class, EloquentUserDirectory::class);
        $this->app->bind(InvitationRedeemer::class, EloquentInvitationRedeemer::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(app_path('Modules/Core/Database/migrations'));
        $this->loadViewsFrom(app_path('Modules/Core/Infrastructure/resources/views'), 'core');

        // merge=true (por defecto): se suma al morph map de AppServiceProvider,
        // no lo sustituye — cada módulo registra el suyo (INV-007).
        Relation::enforceMorphMap([
            'tenant_setting' => TenantSetting::class,
            'user_invitation' => UserInvitation::class,
            'user_import' => UserImport::class,
            'data_export' => DataExport::class,
        ]);

        $this->forceDocumentValidationInProduction();

        if ($this->app->runningInConsole()) {
            $this->commands([ProvisionTenantDefaultsCommand::class, PurgeCoreMaintenanceCommand::class]);
        }
    }

    /**
     * OPEN-CORE-06, decisión (b): producción fuerza
     * `core.documents.validate_check_digit` a `true` sin excepción, en
     * código — no basta con documentarlo. Cualquier valor de entorno que
     * lo desactive en producción se ignora aquí, no se respeta.
     */
    private function forceDocumentValidationInProduction(): void
    {
        if ($this->app->environment('production')) {
            config(['core.documents.validate_check_digit' => true]);
        }
    }

    public function moduleDescriptor(): array
    {
        return [
            'code' => 'core',
            'name_key' => 'modules.core',
            'phase' => '1',
        ];
    }

    public function declaredPermissions(): array
    {
        $resourceActions = [
            'usuario' => ['leer', 'crear', 'actualizar', 'eliminar', 'importar', 'exportar'],
            'invitacion' => ['leer', 'crear', 'eliminar'],
            'asignacion_rol' => ['leer', 'crear', 'eliminar'],
            'rol' => ['leer'],
            'permiso' => ['leer'],
            'configuracion' => ['leer', 'actualizar'],
            'modulo' => ['leer', 'actualizar'],
            'auditoria' => ['leer', 'exportar'],
        ];

        $permissions = [];

        foreach ($resourceActions as $resource => $actions) {
            foreach ($actions as $action) {
                $permissions[] = [
                    'code' => "{$resource}.{$action}",
                    'resource' => $resource,
                    'action' => $action,
                    'is_special_category' => false,
                ];
            }
        }

        return $permissions;
    }
}
