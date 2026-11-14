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
 * REQ-CURSO/operacion.md §3.3, permisos.md §4 (1.12b, `OPEN-CURSO-32` = A,
 * `OPEN-CURSO-24` = b): siembra de `apertura_curso_academico.{leer,crear,eliminar}`
 * en los centros que ya existían. `tenant:provision-defaults` solo corre en el alta
 * de un centro nuevo; sin este comando, el administrador de un centro existente ve un
 * `403` al abrir el curso siguiente.
 *
 * **Concede SOLO los tres permisos nuevos** (`ProvisionTenantDefaults::
 * ACADEMIC_YEAR_ROLLOVER_PERMISSION_GRANTS`), solo a `administrador_centro`: ni
 * re-concede los de 1.10 ni el de reapertura a un centro que los hubiera retirado a
 * propósito. Mismo patrón que `curso:grant-reopen-permission`: idempotente, no toca roles
 * personalizados, escritura por el modelo (`RN-PERM-19`, issue #165), solo centros
 * `Activo` (`RunsPerTenant`: al reactivar un centro suspendido hay que volver a
 * ejecutarlo), y **comprueba antes** que los permisos existen en `permissions` y no están
 * retirados (`platform:sync-registry` ejecutado antes); si no, falla diciendo el remedio y
 * no concede nada.
 *
 * Vive en `Core` y no en `Curso` porque `AR-08` confina la clase `Role` a `Core`.
 */
class GrantRolloverPermissionsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'curso:grant-rollover-permissions';

    protected $description = 'Concede SOLO los permisos de apertura del curso siguiente a los roles predefinidos de cada tenant existente (REQ-CURSO/operacion.md §3.3)';

    public function handle(): int
    {
        $required = collect(ProvisionTenantDefaults::ACADEMIC_YEAR_ROLLOVER_PERMISSION_GRANTS)->flatten()->unique()->values()->all();
        $retired = $this->retiredPermissionCodes($required);
        $missing = array_values(array_diff($required, $this->registeredPermissionCodes($required), $retired));

        // Issue #387 B-5, CA-ACAD-041: un permiso retirado (`retired_at`) sigue
        // en `permissions` pero ya no se declara; concederlo dejaría
        // concesiones a algo que `platform:sync-registry` ha dado de baja.
        if ($retired !== []) {
            $this->error('Hay permisos retirados en el registro (retired_at informado): '.implode(', ', $retired).'. '
                .'No se concede nada. Revisa el catálogo declarado y vuelve a ejecutar `php artisan platform:sync-registry` (REQ-CURSO/operacion.md §3.3).');

            return self::FAILURE;
        }

        if ($missing !== []) {
            $this->error('El registro de permisos no está sincronizado: faltan '.implode(', ', $missing).'. '
                .'Ejecuta primero `php artisan platform:sync-registry` y vuelve a lanzar este comando (REQ-CURSO/operacion.md §3.3).');

            return self::FAILURE;
        }

        $this->eachTenant(function (Tenant $tenant): void {
            AuditActor::actingAs('console', function () use ($tenant): void {
                foreach (ProvisionTenantDefaults::ACADEMIC_YEAR_ROLLOVER_PERMISSION_GRANTS as $roleCode => $permissionCodes) {
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
