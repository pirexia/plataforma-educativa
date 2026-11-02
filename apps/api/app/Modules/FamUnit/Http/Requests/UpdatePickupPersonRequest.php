<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\Core\Domain\PersonCorrection;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-FAM-UNIT/api.md §8.2, `PATCH /pickup-persons/{person}`: `given_name`, `family_name_1`, `family_name_2` y
 * `contact_phone`, todos opcionales pero **al menos uno** (cuerpo vacío ⇒ `422`). `document_type` y `document_number`
 * **no son editables** (son la clave de reutilización, `RN-FAM-UNIT-89`): enviarlos es `422` (aprobado por el usuario el
 * 2026-10-30). `family_name_2` y `contact_phone` admiten `null` (borrar el dato); `given_name` y `family_name_1` no.
 */
class UpdatePickupPersonRequest extends FamUnitRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    public function payload(): PersonCorrection
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $this->rejectFields($body, ['document_type', 'document_number'], 'fam_unit.pickup.document_not_editable', $errors);

        $changes = [];

        foreach (['given_name' => true, 'family_name_1' => true, 'family_name_2' => false, 'contact_phone' => false] as $field => $required) {
            if (! array_key_exists($field, $body)) {
                continue;
            }

            $max = $field === 'contact_phone' ? 32 : 255;
            $value = StorePickupPersonRequest::text($body, $field, $required, $max, $errors);

            // Un nombre o un primer apellido presente no puede vaciarse; los opcionales admiten `null`.
            if ($value !== null || ! $required) {
                $changes[$field] = $value;
            }
        }

        if ($changes === [] && $errors->isEmpty()) {
            $errors->add('body', 'fam_unit.validation.at_least_one_field', 'fam_unit.validation.at_least_one_field');
        }

        $errors->throwIfAny();

        return new PersonCorrection($changes);
    }
}
