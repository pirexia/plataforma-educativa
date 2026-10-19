<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\StructureFields;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/api.md §12.3, `PATCH /timetable-frames/{public_id}`: editables
 * `name_translations` (fusión) y `slots` (conjunto completo por `public_id`,
 * `RN-ACAD-113`). Padre y curso inmutables (`RN-ACAD-91`, `parent_immutable`);
 * `frame_type` inmutable (`RN-ACAD-110`). Las fechas de vigencia son del marco
 * `especial` (`1.12-b`): en un `ordinaria` es `422 frame_dates_invalid`.
 */
class UpdateTimetableFrameRequest extends StructureRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name_translations' => ['sometimes', 'nullable', 'array'],
        ];
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

        if (array_key_exists('frame_type', $body)) {
            $errors->add('frame_type', 'acad.validation.frame_type_immutable', 'acad.validation.frame_type_immutable');
        }

        foreach (['valid_from', 'valid_to'] as $field) {
            if (($body[$field] ?? null) !== null) {
                $errors->add($field, 'acad.validation.frame_dates_invalid', 'acad.validation.frame_dates_invalid');
            }
        }

        $errors->throwIfAny();

        foreach (['name_translations', 'slots'] as $key) {
            if (array_key_exists($key, $body)) {
                $input[$key] = $body[$key];
            }
        }

        return $input;
    }
}
