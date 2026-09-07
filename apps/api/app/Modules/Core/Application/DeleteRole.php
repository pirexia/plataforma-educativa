<?php

namespace App\Modules\Core\Application;

use App\Models\Role;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/api.md §6, funcional.md §7.9 (`DELETE /roles/{public_id}`,
 * `RN-PERM-16`, `RN-PERM-17`). Sin cascada sobre las asignaciones, a
 * propósito: arrastrar la baja cambiaría en silencio lo que pueden hacer
 * varias personas a la vez, que es exactamente lo que `RPERM-010` existe
 * para poder reconstruir. Se obliga a reasignar primero, de forma
 * explícita y auditada.
 */
final class DeleteRole
{
    public function delete(Role $role): void
    {
        if ($role->is_system) {
            throw ApiException::conflict('core.validation.role_is_system');
        }

        $usersCount = $role->users()->count();

        if ($usersCount > 0) {
            throw ApiException::conflict('core.validation.role_has_assignments', ['users_count' => $usersCount]);
        }

        DB::transaction(function () use ($role): void {
            // RN-PERM-17: sus concesiones se dan de baja con él, cada baja
            // auditada individualmente (`deleted` sobre PermissionRole,
            // datos.md §5.2) — nunca con un DELETE/UPDATE masivo que se
            // saltaría el observer de auditoría.
            foreach ($role->permissionGrants()->get() as $grant) {
                $grant->delete();
            }

            $role->delete();
        });
    }
}
