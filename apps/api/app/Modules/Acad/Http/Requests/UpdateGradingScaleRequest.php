<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\StructureLimits;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/api.md §11.4, `PATCH /grading-scales/{public_id}`: editables
 * `code`, `name_translations` (fusión por idioma), los campos numéricos y
 * `values` (conjunto completo, `RN-ACAD-88`). `scale_type` ⇒ `422
 * scale_type_immutable` (`RN-ACAD-70`); `academic_year` ⇒ `422 parent_immutable`
 * (`RN-ACAD-51`). Las reglas se evalúan sobre el estado resultante, en el
 * servicio, que conoce el tipo y los valores guardados.
 */
class UpdateGradingScaleRequest extends StructureRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'nullable', 'string', 'max:'.StructureLimits::MAX_TEXT_LENGTH],
            'values.*.code' => ['nullable', 'string', 'max:'.StructureLimits::MAX_TEXT_LENGTH],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        if (array_key_exists('scale_type', $body)) {
            $errors->add('scale_type', 'acad.validation.scale_type_immutable', 'acad.validation.scale_type_immutable');
        }

        if (array_key_exists('academic_year', $body)) {
            $errors->add('academic_year', 'acad.validation.parent_immutable', 'acad.validation.parent_immutable');
        }

        $errors->throwIfAny();

        return $body;
    }
}
