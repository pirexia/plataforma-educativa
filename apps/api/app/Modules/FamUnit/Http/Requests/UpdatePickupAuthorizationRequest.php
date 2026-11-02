<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Support\Api\ValidationErrorBag;

/**
 * REQ-FAM-UNIT/api.md §8.3, `PATCH /pickup-authorizations/{id}`: solo `valid_until` (una fecha civil `Y-m-d` o `null` para
 * quitar la caducidad) y **obligatorio**. `kind`, `person`, `student_person` y `authorized_by` no son editables (`422`), y
 * tampoco ningún otro campo de la autorización: lo que cambia la autorización se retira y se crea otra. Que sea de una
 * `permanente`, que no esté retirada y que la fecha no sea anterior al inicio lo decide el servicio.
 */
class UpdatePickupAuthorizationRequest extends FamUnitRequest
{
    /** Campos de la autorización que no se editan por `PATCH`. */
    private const NOT_EDITABLE = ['kind', 'person', 'student_person', 'authorized_by', 'relationship', 'valid_from', 'reason', 'terms_version', 'channel', 'authorized_at'];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array{valid_until: ?string}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $this->rejectFields($body, self::NOT_EDITABLE, 'fam_unit.pickup.not_editable', $errors);

        if (! array_key_exists('valid_until', $body)) {
            $errors->add('valid_until', 'fam_unit.validation.required', 'fam_unit.validation.required');
        } elseif ($body['valid_until'] !== null && ! StorePickupAuthorizationRequest::isCivilDate($body['valid_until'])) {
            $errors->add('valid_until', 'fam_unit.validation.date_invalid', 'fam_unit.validation.date_invalid');
        }

        $errors->throwIfAny();

        return ['valid_until' => $body['valid_until']];
    }
}
