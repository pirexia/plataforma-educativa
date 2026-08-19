<?php

namespace App\Support\Authorization;

use App\Models\PermissionRole;
use App\Models\User;

/**
 * ADR-034 §2 (RESOLUTOR PROVISIONAL, vigente entre 1.1 y 1.5): lee
 * `permission_role.effect` e **ignora `permission_role.scope`**, que
 * equivale a tratar todo ámbito como `todos`. `deny` gana a `allow`
 * (RPERM-007) sobre el mismo código de permiso — 1.1 nunca siembra `deny`
 * (RN-CORE-22, permisos.md §5), pero el resolutor lo respeta desde ya
 * porque es la semántica que ADR-034 §2 fija, no una que 1.5 tenga que
 * introducir de cero.
 *
 * No cachea: RPERM-011 exige denegar por defecto y este paso no introduce
 * invalidación de caché de permisos (queda para 1.5, que sí tendrá
 * concesión/revocación de permisos en caliente). Una consulta indexada
 * por PK compuesta sobre dos tablas pequeñas por tenant es barata.
 */
final class PermissionResolver
{
    /**
     * @return array<int, string>
     */
    public function effectivePermissionCodes(User $user): array
    {
        $roleIds = $user->roles()->pluck('roles.id');

        if ($roleIds->isEmpty()) {
            return [];
        }

        $grants = PermissionRole::query()
            ->whereIn('role_id', $roleIds)
            ->get(['permission_code', 'effect']);

        $allowed = $grants->where('effect', 'allow')->pluck('permission_code');
        $denied = $grants->where('effect', 'deny')->pluck('permission_code');

        return $allowed->diff($denied)->unique()->values()->all();
    }

    public function can(User $user, string $permissionCode): bool
    {
        return in_array($permissionCode, $this->effectivePermissionCodes($user), true);
    }
}
