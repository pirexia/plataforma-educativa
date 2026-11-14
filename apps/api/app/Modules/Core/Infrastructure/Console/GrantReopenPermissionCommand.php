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
 * REQ-CURSO/operacion.md §3.1, permisos.md §4 (1.11c, `ADR-059 §5.2`,
 * `OPEN-CURSO-24` = b, acordada con el usuario el 2026-10-08): siembra de
 * `reapertura_curso_academico.actualizar` en los centros que ya existían.
 * `tenant:provision-defaults` solo corre en el alta de un centro nuevo; sin este
 * comando, el administrador de un centro existente recibe un `403` indistinguible
 * de «no tengo permiso» al reabrir.
 *
 * **Concede SOLO el permiso nuevo** (`ProvisionTenantDefaults::
 * ACADEMIC_YEAR_REOPEN_PERMISSION_GRANTS`), a diferencia de ampliar
 * `curso:grant-year-permissions`: volver a ejecutar aquel con una lista ampliada
 * re-concedería también los cinco permisos de 1.10 a los roles predefinidos de un
 * centro que los hubiera retirado a propósito, pisando una decisión del centro.
 * Mismo patrón que aquel: idempotente, no toca roles personalizados, escritura por
 * el modelo (`RN-PERM-19`, issue #165), solo centros `Activo` (`RunsPerTenant`:
 * un centro suspendido o en baja debe volver a ejecutarlo al reactivarse), y
 * **comprueba antes** que el permiso existe en `permissions` y no está retirado
 * (`platform:sync-registry` ejecutado antes); si no, falla diciendo el remedio y
 * no concede nada.
 *
 * Vive en `Core` y no en `Curso` porque `AR-08` confina la clase `Role` a `Core`.
 */
class GrantReopenPermissionCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'curso:grant-reopen-permission';

    protected $description = 'Concede SOLO el permiso de reapertura de curso a los roles predefinidos de cada tenant existente (REQ-CURSO/operacion.md §3.1)';

    public function handle(): int
    {
        $required = collect(ProvisionTenantDefaults::ACADEMIC_YEAR_REOPEN_PERMISSION_GRANTS)->flatten()->unique()->values()->all();
        $retired = $this->retiredPermissionCodes($required);
        $missing = array_values(array_diff($required, $this->registeredPermissionCodes($required), $retired));

        // Issue #387 B-5, CA-ACAD-041: un permiso retirado (`retired_at`) sigue
        // en `permissions` pero ya no se declara; concederlo dejaría
        // concesiones a algo que `platform:sync-registry` ha dado de baja.
        if ($retired !== []) {
            $this->error('Hay permisos retirados en el registro (retired_at informado): '.implode(', ', $retired).'. '
                .'No se concede nada. Revisa el catálogo declarado y vuelve a ejecutar `php artisan platform:sync-registry` (REQ-CURSO/operacion.md §3.1).');

            return self::FAILURE;
        }

        if ($missing !== []) {
            $this->error('El registro de permisos no está sincronizado: faltan '.implode(', ', $missing).'. '
                .'Ejecuta primero `php artisan platform:sync-registry` y vuelve a lanzar este comando (REQ-CURSO/operacion.md §3.1).');

            return self::FAILURE;
        }

        $this->eachTenant(function (Tenant $tenant): void {
            AuditActor::actingAs('console', function () use ($tenant): void {
                foreach (ProvisionTenantDefaults::ACADEMIC_YEAR_REOPEN_PERMISSION_GRANTS as $roleCode => $permissionCodes) {
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
