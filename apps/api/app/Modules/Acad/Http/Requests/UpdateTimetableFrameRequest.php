<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\PeriodFields;
use App\Modules\Acad\Application\StructureFields;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/api.md §12.3, `PATCH /timetable-frames/{public_id}`: editables
 * `name_translations` (fusión) y `slots` (conjunto completo por `public_id`,
 * `RN-ACAD-113`). Padre y curso inmutables (`RN-ACAD-91`, `parent_immutable`);
 * `frame_type` inmutable (`RN-ACAD-110`). Las fechas de vigencia
 * (`valid_from`, `valid_to`) son del marco `especial` (`RN-ACAD-111`): aquí solo
 * se valida su formato (`422 date_invalid`); si el marco es `ordinaria`, si
 * `valid_to < valid_from` o si caen fuera del curso lo decide el servicio, que
 * conoce el marco.
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
                PeriodFields::date($body[$field], $field, $errors);
            }
        }

        $errors->throwIfAny();

        foreach (['name_translations', 'slots', 'valid_from', 'valid_to'] as $key) {
            if (array_key_exists($key, $body)) {
                $input[$key] = $body[$key];
            }
        }

        return $input;
    }
}
