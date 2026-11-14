<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Support\Api\ValidationErrorBag;

/**
 * REQ-FAM-UNIT/api.md §2.2, `PATCH /guardians/{public_id}` (`RN-FAM-UNIT-14`, decisión del usuario del 2026-10-26): un
 * tutor no cambia de unidad. El cuerpo con `family_unit` (la misma o cualquier otra) es `422 guardian.not_editable`;
 * el tutor no tiene otro campo editable en `1.14-a`.
 */
class UpdateGuardianRequest extends FamUnitRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    public function assertEditable(): void
    {
        $errors = new ValidationErrorBag;

        $this->rejectFields($this->body(), ['family_unit'], 'fam_unit.guardian.not_editable', $errors);

        $errors->throwIfAny();
    }
}
