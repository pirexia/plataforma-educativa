<?php

namespace App\Modules\Auth\Domain;

use App\Models\Role;

/**
 * funcional.md §C.1.1 punto 9, §C.9.2. Vista previa de usuarios afectados
 * antes de guardar `mfa_required`, y estado de cumplimiento consultable —
 * el requisito pide las dos cosas y las resuelve el mismo endpoint
 * (`GET /mfa-compliance`, api.md §C.5). **1.5** la consumirá desde el
 * editor de roles sin importar nada interno de `REQ-AUTH` (`INV-007`).
 */
interface MfaComplianceDirectory
{
    /**
     * Estado real hoy: usa el valor de `$role->mfa_required` ya guardado.
     */
    public function current(Role $role): MfaComplianceSummary;

    /**
     * Vista previa (`CA-AUTH-136`): cuántos usuarios de `$role` **quedarían**
     * obligados si `mfa_required` pasara a `$hypotheticalMfaRequired`, sin
     * escribir nada. `false` siempre cuenta 0 (apagar la obligación de un
     * rol nunca obliga a nadie por sí solo).
     */
    public function preview(Role $role, bool $hypotheticalMfaRequired): MfaComplianceSummary;
}
