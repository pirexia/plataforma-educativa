<?php

namespace App\Modules\Core\Domain\Events;

/**
 * REQ-AUTH/funcional.md §C.2, §C.4.8 (1.3). `RolesController::update()`
 * lo publica solo cuando `mfa_required` pasa de `false` a `true` — es el
 * disparador «un trabajo encolado materializa la fila de los usuarios
 * afectados en ese momento» de la tabla de `§C.4.8`. `REQ-AUTH` lo
 * consume sin que `REQ-CORE` importe nada suyo (`INV-007`).
 */
final class RoleMfaRequirementChanged
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $rolePublicId,
    ) {}
}
