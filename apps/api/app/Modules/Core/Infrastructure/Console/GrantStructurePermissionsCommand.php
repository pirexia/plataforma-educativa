<?php

namespace App\Modules\Core\Infrastructure\Console;

use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Modules\Core\Application\ProvisionTenantDefaults;
use App\Support\Audit\AuditActor;
use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * REQ-ACAD/operacion.md §3 punto 4, permisos.md §4, §4.1 y §10.3 (1.11-b, 1.11d-a y 1.12-b,
 * `OPEN-ACAD-09` = X, `OPEN-ACAD-28` = X + 1, `CA-ACAD-036`, `CA-ACAD-097`): mismo
 * tipo que `curso:grant-year-permissions`.
 * `tenant:provision-defaults` solo corre en el alta de un centro nuevo; los
 * centros que ya existían no reciben las concesiones de `estructura_academica`
 * ni de `configuracion_academica`, `asignacion_docente`, `espacios`, `horarios` o `reservas` por desplegar, y su síntoma es un `403` indistinguible de «no tengo permiso».
 *
 * Concede, en cada centro vivo, exactamente las concesiones de
 * `ProvisionTenantDefaults::STRUCTURE_PERMISSION_GRANTS` (ámbito `todos`) y
 * `STRUCTURE_PERMISSION_PROPIOS_GRANTS` (ámbito `propios`, `reservas.eliminar` de
 * `docente` y `tutor_grupo`, `1.12-b`) —una sola lista por los dos caminos— a los
 * roles predefinidos que no tengan **esa concesión** (por código; una concesión
 * previa con otro ámbito no se toca), y **no toca
 * roles personalizados**. Idempotente. Escritura por el modelo (`RN-PERM-19`),
 * nunca por `attach()`/`sync()`, para que quede auditada (issue #165).
 *
 * **Comprueba antes** que todos los permisos de la lista existen en `permissions` y que
 * ninguno está retirado (`retired_at IS NULL`, issue #387 B-5): si falta o
 * está retirado alguno, falla diciendo cuál y qué hacer, y **no concede nada**.
 * **Solo recorre los centros en estado `Activo`** (`RunsPerTenant`): un centro
 * suspendido, en baja o aún aprovisionándose no recibe las concesiones; al
 * reactivarlo hay que volver a ejecutar el comando.
 *
 * Vive en `Core` y no en `Acad` porque la escritura en `roles`/`permission_role`
 * es de `Core` (`INV-007`) y `AR-08` confina la clase `Role` a `Core`.
 */
class GrantStructurePermissionsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'acad:grant-structure-permissions';

    protected $description = 'Concede los permisos de estructura académica a los roles predefinidos de cada tenant existente (REQ-ACAD/operacion.md §3)';

    public function handle(): int
    {
        $required = collect([...ProvisionTenantDefaults::STRUCTURE_PERMISSION_GRANTS, ...ProvisionTenantDefaults::STRUCTURE_PERMISSION_PROPIOS_GRANTS])->flatten()->unique()->values()->all();
        $retired = $this->retiredPermissionCodes($required);
        $missing = array_values(array_diff($required, $this->registeredPermissionCodes($required), $retired));

        // Issue #387 B-5, CA-ACAD-036: un permiso retirado (`retired_at`) sigue
        // en `permissions` pero ya no se declara; concederlo dejaría
        // concesiones a algo que `platform:sync-registry` ha dado de baja.
        if ($retired !== []) {
            $this->error('Hay permisos retirados en el registro (retired_at informado): '.implode(', ', $retired).'. '
                .'No se concede nada. Revisa el catálogo declarado y vuelve a ejecutar `php artisan platform:sync-registry` (REQ-ACAD/operacion.md §3).');

            return self::FAILURE;
        }

        if ($missing !== []) {
            $this->error('El registro de permisos no está sincronizado: faltan '.implode(', ', $missing).'. '
                .'Ejecuta primero `php artisan platform:sync-registry` y vuelve a lanzar este comando (REQ-ACAD/operacion.md §3).');

            return self::FAILURE;
        }

        $this->eachTenant(function (Tenant $tenant): void {
            AuditActor::actingAs('console', function () use ($tenant): void {
                // Ámbito `todos` y, aparte, `propios` (`reservas.eliminar` de `docente` y `tutor_grupo`, `OPEN-ACAD-42`).
                foreach (['todos' => ProvisionTenantDefaults::STRUCTURE_PERMISSION_GRANTS, 'propios' => ProvisionTenantDefaults::STRUCTURE_PERMISSION_PROPIOS_GRANTS] as $scope => $grants) {
                    foreach ($grants as $roleCode => $permissionCodes) {
                        $role = Role::query()->where('code', $roleCode)->first();

                        if ($role === null) {
                            continue;
                        }

                        foreach ($permissionCodes as $permissionCode) {
                            $exists = PermissionRole::query()
                                ->where('role_id', $role->id)
                                ->where('permission_code', $permissionCode)
                                ->exists();

                            if (! $exists) {
                                PermissionRole::create([
                                    'role_id' => $role->id,
                                    'permission_code' => $permissionCode,
                                    'effect' => 'allow',
                                    'scope' => $scope,
                                ]);

                                $this->line("  {$tenant->slug}: {$roleCode} ← {$permissionCode} ({$scope})");
                            }
                        }
                    }
                }
            });
        });

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $codes
     * @return list<string> los de `$codes` que figuran en `permissions` y no están retirados
     */
    protected function registeredPermissionCodes(array $codes): array
    {
        return Permission::query()->whereIn('code', $codes)->whereNull('retired_at')->pluck('code')->all();
    }

    /**
     * @param  list<string>  $codes
     * @return list<string> los de `$codes` que figuran en `permissions` con `retired_at` informado
     */
    protected function retiredPermissionCodes(array $codes): array
    {
        return Permission::query()->whereIn('code', $codes)->whereNotNull('retired_at')->pluck('code')->all();
    }
}
