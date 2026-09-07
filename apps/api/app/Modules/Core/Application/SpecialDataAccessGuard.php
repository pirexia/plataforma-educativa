<?php

namespace App\Modules\Core\Application;

use App\Models\User;

/**
 * REQ-PERM/funcional.md §5.3 (RPERM-013 sobre el atributo, ADR-044 §4.4):
 * «nadie activa `special_data_access` en un rol si él mismo no lo tiene».
 * Es una comprobación de **sujeto**, no de par (código, ámbito) — a
 * propósito distinta de `PermissionResolver::ownsScope()`: aquí no se
 * transfiere un permiso concreto, sino la llave de toda una categoría de
 * dato.
 *
 * `administrador_centro` tiene `rol_datos_especiales.actualizar` (el
 * permiso) pero no `special_data_access` (el atributo) — puede delegar,
 * no ejercer (`permisos.md §5`, `OPEN-PERM-07`).
 */
final class SpecialDataAccessGuard
{
    public function holds(User $subject): bool
    {
        return $subject->roles()->where('special_data_access', true)->exists();
    }
}
