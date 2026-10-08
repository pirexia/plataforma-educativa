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
 * REQ-CURSO/operacion.md §3 punto 3, permisos.md §4 (1.10, `OPEN-CURSO-14`)
 * — «el paso que se olvida»: mismo patrón que
 * `perm:grant-role-administration` (1.5) y `auth:grant-lockout-permissions`
 * (1.2). `tenant:provision-defaults` solo corre en el alta de un centro
 * nuevo; los centros que ya existían antes de este despliegue no reciben
 * las concesiones de `curso` por desplegar. Su síntoma es un `403`
 * indistinguible de «no tengo permiso».
 *
 * Concede, en cada centro vivo, exactamente las concesiones de
 * `ProvisionTenantDefaults::ACADEMIC_YEAR_PERMISSION_GRANTS` (una sola
 * lista por los dos caminos) a los roles predefinidos que no las tengan, y
 * **no toca roles personalizados**. Como los cinco permisos son nuevos,
 * ningún centro ha podido quitarlos antes: no hay decisión del centro que
 * pisar. Idempotente. Escritura por el modelo (`RN-PERM-19`), nunca por
 * `attach()`/`sync()`, para que quede auditada (issue #165).
 *
 * Requiere `platform:sync-registry` ejecutado antes (`permission_role`
 * apunta por clave foránea a `permissions`): el comando lo comprueba al
 * empezar y, si falta algún permiso, falla con el motivo y el remedio en
 * lugar de una violación de clave foránea a mitad del recorrido (#385).
 * **Solo recorre los centros en estado `Activo`** (`RunsPerTenant`): un
 * centro suspendido, en baja o aún aprovisionándose no recibe las
 * concesiones; al reactivarlo hay que volver a ejecutar el comando.
 *
 * Vive en `Core` y no en `Curso` porque `AR-08` confina la clase `Role` a
 * `Core`.
 */
class GrantAcademicYearPermissionsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'curso:grant-year-permissions';

    protected $description = 'Concede los permisos de curso académico a los roles predefinidos de cada tenant existente (REQ-CURSO/operacion.md §3)';

    public function handle(): int
    {
        $required = collect(ProvisionTenantDefaults::ACADEMIC_YEAR_PERMISSION_GRANTS)->flatten()->unique()->values()->all();
        $missing = array_values(array_diff($required, $this->registeredPermissionCodes($required)));

        if ($missing !== []) {
            $this->error('El registro de permisos no está sincronizado: faltan '.implode(', ', $missing).'. '
                .'Ejecuta primero `php artisan platform:sync-registry` y vuelve a lanzar este comando (REQ-CURSO/operacion.md §3).');

            return self::FAILURE;
        }

        $this->eachTenant(function (Tenant $tenant): void {
            AuditActor::actingAs('console', function () use ($tenant): void {
                foreach (ProvisionTenantDefaults::ACADEMIC_YEAR_PERMISSION_GRANTS as $roleCode => $permissionCodes) {
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
                                'scope' => 'todos',
                            ]);

                            $this->line("  {$tenant->slug}: {$roleCode} ← {$permissionCode}");
                        }
                    }
                }
            });
        });

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $codes
     * @return list<string> los de `$codes` que figuran en `permissions`
     */
    protected function registeredPermissionCodes(array $codes): array
    {
        return Permission::query()->whereIn('code', $codes)->pluck('code')->all();
    }
}
