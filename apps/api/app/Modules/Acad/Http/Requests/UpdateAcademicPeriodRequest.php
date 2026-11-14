<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\PeriodFields;
use App\Modules\Acad\Application\StructureFields;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/api.md §11.4, `PATCH /academic-periods/{public_id}`: editables
 * `period_type`, `name_translations` (fusión por idioma en el servicio),
 * `starts_on`, `ends_on` y `position`. Claves de padre o curso académico ⇒
 * `422 parent_immutable` (`RN-ACAD-51`, `-06`). Las reglas entre fechas y
 * hermanos se evalúan sobre el estado resultante, en el servicio.
 */
class UpdateAcademicPeriodRequest extends StructureRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;
        $input = [];

        StructureFields::rejectImmutableKeys($body, $errors);

        if (array_key_exists('period_type', $body) && ($type = PeriodFields::periodType($body['period_type'], $errors)) !== null) {
            $input['period_type'] = $type;
        }

        foreach (['starts_on', 'ends_on'] as $field) {
            if (array_key_exists($field, $body) && ($date = PeriodFields::date($body[$field], $field, $errors)) !== null) {
                $input[$field] = $date;
            }
        }

        if (isset($input['starts_on'], $input['ends_on'])) {
            PeriodFields::order($input['starts_on'], $input['ends_on'], $errors);
        }

        if (array_key_exists('position', $body) && ($position = StructureFields::position($body['position'], $errors)) !== null) {
            $input['position'] = $position;
        }

        if (array_key_exists('name_translations', $body)) {
            $input['name_translations'] = $body['name_translations'];
        }

        $errors->throwIfAny();

        return $input;
    }
}
