<?php

namespace App\Modules\Core\Infrastructure;

use App\Models\PermissionRole;
use App\Modules\Core\Domain\AuditoriaPropiosScopeResolver;
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
use App\Modules\Core\Infrastructure\Console\GrantRoleAdministrationCommand;
use App\Modules\Core\Infrastructure\Console\ProvisionTenantDefaultsCommand;
use App\Modules\Core\Infrastructure\Console\PurgeCoreMaintenanceCommand;
use App\Support\Authorization\ScopeResolverRegistry;
use App\Support\Modules\DeclaresModuleRegistry;
use App\Support\Modules\ModuleAvailability;
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

        // REQ-PERM/operacion.md §6.2 (1.5, ADR-044 §4.9): REQ-CORE posee la
        // interfaz que consume App\Support\Authorization sin importar nada
        // de este módulo (INV-007).
        $this->app->bind(ModuleAvailability::class, EloquentModuleAvailability::class);
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
            // REQ-PERM/datos.md §5.2 (1.5, issue #165): PermissionRole pasa
            // a Auditable — sin alias estable, AuditRecorder lanzaría
            // RuntimeException en la primera concesión.
            'permission_role' => PermissionRole::class,
        ]);

        // REQ-PERM/funcional.md §6, ADR-044 §8 (1.5): el único resolutor
        // real de este paso, registrado desde el módulo propietario de
        // `auditoria` — el núcleo de autorización nunca sabe qué es
        // `auditoria`, solo implementa el contrato ScopeResolver.
        $this->app->make(ScopeResolverRegistry::class)->register(new AuditoriaPropiosScopeResolver);

        $this->forceDocumentValidationInProduction();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ProvisionTenantDefaultsCommand::class,
                PurgeCoreMaintenanceCommand::class,
                GrantRoleAdministrationCommand::class,
            ]);
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
            // REQ-AUTH/funcional.md §C.2.2, §C.16 (1.3): 'actualizar' se
            // añade aquí, no en AuthServiceProvider — el recurso 'rol' es
            // de REQ-CORE, se gobierna con el permiso de ese recurso
            // (mismo criterio que 'configuracion.actualizar' en 1.2,
            // permisos.md §4.1), aunque quien lo use en 1.3 sea el
            // PATCH acotado a mfa_required.
            // REQ-PERM/permisos.md §2 (1.5): 'crear' y 'eliminar' se
            // añaden — POST /roles y DELETE /roles/{id}.
            'rol' => ['leer', 'actualizar', 'crear', 'eliminar'],
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
                    // REQ-PERM/permisos.md §3.1 (1.5): 'auditoria.leer' y
                    // 'auditoria.exportar' son el único caso real de 1.5
                    // (funcional.md §6) — el resto se queda en la regla
                    // conservadora (omisión = ['todos'], CA-PERM-007).
                    ...($resource === 'auditoria' ? ['applicable_scopes' => ['todos', 'propios']] : []),
                ];
            }
        }

        // REQ-PERM/permisos.md §2 (1.5, ADR-044 §4.4/§5): dos recursos
        // nuevos, ninguno con acción inventada — rol_datos_especiales.actualizar
        // gobierna el atributo special_data_access separado del resto del
        // rol; permiso_efectivo.leer protege la resolución completa de
        // otro usuario (OPEN-PERM-01).
        $permissions[] = [
            'code' => 'rol_datos_especiales.actualizar',
            'resource' => 'rol_datos_especiales',
            'action' => 'actualizar',
            'is_special_category' => false,
        ];

        $permissions[] = [
            'code' => 'permiso_efectivo.leer',
            'resource' => 'permiso_efectivo',
            'action' => 'leer',
            'is_special_category' => false,
        ];

        return $permissions;
    }
}
