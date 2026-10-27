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
 * REQ-FAM-UNIT/operacion.md §3, permisos.md §4 (1.14-a, 1.14-b, `OPEN-FAM-UNIT-10`, `-22`): siembra de los permisos de
 * `familia`, `vinculo_tutor`, `revocacion_tutor`, `consentimiento_imagen` y `texto_legal` en los centros que ya existían. `tenant:provision-defaults` solo corre en el
 * alta de un centro nuevo; sin este comando, el personal de un centro existente ve un `403` al gestionar familias.
 *
 * Concede, en cada centro vivo, exactamente las concesiones de `ProvisionTenantDefaults::FAMILY_PERMISSION_GRANTS`
 * (ámbito `todos`) y `FAMILY_PERMISSION_UNIDAD_FAMILIAR_GRANTS` (ámbito `unidad_familiar`, `tutor_legal`) a los roles
 * predefinidos que no tengan **esa concesión** (por código; una concesión previa con otro ámbito no se toca) y **no
 * toca roles personalizados**. Idempotente. Escritura por el modelo (`RN-PERM-19`), nunca por `attach()`/`sync()`,
 * para que quede auditada (issue #165). No concede `restriccion_custodia.*` (ni se declara, `OPEN-FAM-UNIT-05`). Con `1.14-b`, `texto_legal.leer` llega también a `secretaria`.
 *
 * **Comprueba antes** que todos los permisos de la lista existen en `permissions` y que ninguno está retirado
 * (`retired_at IS NULL`, issue #387 B-5): si falta o está retirado alguno, falla diciendo cuál y qué hacer, y **no
 * concede nada**. **Solo recorre los centros en estado `Activo`** (`RunsPerTenant`): al reactivar un centro suspendido
 * hay que volver a ejecutarlo. La concesión con `unidad_familiar` queda inerte hasta que el módulo `fam_unit` esté
 * contratado en el centro (`RMOD-009`).
 *
 * Vive en `Core` y no en `FamUnit` porque la escritura en `roles`/`permission_role` es de `Core` (`INV-007`) y `AR-08`
 * confina la clase `Role` a `Core`.
 */
class GrantFamUnitPermissionsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'fam_unit:grant-permissions';

    protected $description = 'Concede los permisos de familias, vínculos y revocación a los roles predefinidos de cada tenant existente (REQ-FAM-UNIT/operacion.md §3)';

    public function handle(): int
    {
        $required = collect([...ProvisionTenantDefaults::FAMILY_PERMISSION_GRANTS, ...ProvisionTenantDefaults::FAMILY_PERMISSION_UNIDAD_FAMILIAR_GRANTS])->flatten()->unique()->values()->all();
        $retired = $this->retiredPermissionCodes($required);
        $missing = array_values(array_diff($required, $this->registeredPermissionCodes($required), $retired));

        if ($retired !== []) {
            $this->error('Hay permisos retirados en el registro (retired_at informado): '.implode(', ', $retired).'. '
                .'No se concede nada. Revisa el catálogo declarado y vuelve a ejecutar `php artisan platform:sync-registry` (REQ-FAM-UNIT/operacion.md §3).');

            return self::FAILURE;
        }

        if ($missing !== []) {
            $this->error('El registro de permisos no está sincronizado: faltan '.implode(', ', $missing).'. '
                .'Ejecuta primero `php artisan platform:sync-registry` y vuelve a lanzar este comando (REQ-FAM-UNIT/operacion.md §3).');

            return self::FAILURE;
        }

        $this->eachTenant(function (Tenant $tenant): void {
            AuditActor::actingAs('console', function () use ($tenant): void {
                foreach (['todos' => ProvisionTenantDefaults::FAMILY_PERMISSION_GRANTS, 'unidad_familiar' => ProvisionTenantDefaults::FAMILY_PERMISSION_UNIDAD_FAMILIAR_GRANTS] as $scope => $grants) {
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
