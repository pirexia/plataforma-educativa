<?php

namespace App\Modules\Auth\Infrastructure\Console;

use App\Models\PermissionRole;
use App\Models\Role;
use App\Support\Tenancy\RunsPerTenant;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;

/**
 * operacion.md §11, punto 4: `tenant:provision-defaults` solo corre en el
 * alta de un centro nuevo, así que los tenants ya existentes antes de este
 * despliegue no reciben `bloqueo_cuenta.leer`/`bloqueo_cuenta.eliminar`
 * por sí solos. Idempotente: una segunda ejecución no duplica filas.
 *
 * "El paso que más fácil se olvida" — su síntoma es un 403 inexplicable
 * en un centro que existía antes del despliegue de 1.2.
 */
class GrantLockoutPermissionsCommand extends Command
{
    use RunsPerTenant;

    private const PERMISSIONS = ['bloqueo_cuenta.leer', 'bloqueo_cuenta.eliminar'];

    protected $signature = 'auth:grant-lockout-permissions';

    protected $description = 'Concede bloqueo_cuenta.leer y bloqueo_cuenta.eliminar al rol administrador_centro de cada tenant existente (operacion.md §11)';

    public function handle(): int
    {
        $this->eachTenant(function (Tenant $tenant): void {
            $role = Role::query()->where('code', 'administrador_centro')->first();

            if ($role === null) {
                return;
            }

            foreach (self::PERMISSIONS as $permissionCode) {
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

        return self::SUCCESS;
    }
}
