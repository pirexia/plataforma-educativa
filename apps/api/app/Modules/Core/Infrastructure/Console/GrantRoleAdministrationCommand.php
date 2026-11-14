<?php

namespace App\Modules\Core\Infrastructure\Console;

use App\Models\PermissionRole;
use App\Models\Role;
use App\Modules\Core\Application\ProvisionTenantDefaults;
use App\Support\Audit\AuditActor;
use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * REQ-PERM/operacion.md §4.3 — «el paso que se olvida»: exactamente el
 * mismo patrón que `auth:grant-lockout-permissions` (1.2). `tenant:
 * provision-defaults` solo corre en el alta de un centro nuevo; los
 * tenants que ya existían antes de este despliegue no reciben las
 * concesiones nuevas por el simple hecho de desplegar. Su síntoma es un
 * `403` indistinguible de «no tengo permiso», y por eso es el fallo más
 * probable de este despliegue.
 *
 * Concede, en cada tenant vivo, los mismos cuatro permisos que
 * `ProvisionTenantDefaults::ROLE_ADMINISTRATION_PERMISSIONS` siembra en los
 * tenants nuevos — una sola lista, en un solo sitio, consumida por los dos
 * caminos. Idempotente. Escritura por el modelo (`RN-PERM-19`), nunca por
 * `attach()`/`sync()`, para que quede auditada como cualquier otra
 * concesión (issue #165).
 */
class GrantRoleAdministrationCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'perm:grant-role-administration';

    protected $description = 'Concede rol.crear, rol.eliminar, rol_datos_especiales.actualizar y permiso_efectivo.leer al rol administrador_centro de cada tenant existente (REQ-PERM/operacion.md §4.3)';

    public function handle(): int
    {
        $this->eachTenant(function (Tenant $tenant): void {
            $role = Role::query()->where('code', 'administrador_centro')->first();

            if ($role === null) {
                return;
            }

            AuditActor::actingAs('console', function () use ($role, $tenant): void {
                foreach (ProvisionTenantDefaults::ROLE_ADMINISTRATION_PERMISSIONS as $permissionCode) {
                    $exists = PermissionRole::query()
                        ->where('role_id', $role->id)
                        ->where('permission_code', $permissionCode)
                        ->exists();

                    if (! $exists) {
                        PermissionRole::create([
                            'role_id' => $role->id,
                            'permission_code' => $permissionCode,
                            'effect' => 'allow',
                            'scope' => 'todos',
                        ]);

                        $this->line("  {$tenant->slug}: concedido {$permissionCode}");
                    }
                }
            });
        });

        return self::SUCCESS;
    }
}
