<?php

namespace App\Modules\Acad\Http\Requests;

use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/api.md §12.3, `PATCH /timetable-sessions/{public_id}`: `time_slot` y
 * `facility` (`null` quita el aula). `teaching_assignment` y `academic_year`
 * son inmutables (`RN-ACAD-122`, `422 parent_immutable`).
 */
class UpdateTimetableSessionRequest extends StructureRequest
{
    use ReadsReferences;

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

        foreach (['teaching_assignment', 'academic_year'] as $key) {
            if (array_key_exists($key, $body)) {
                $errors->add($key, 'acad.validation.parent_immutable', 'acad.validation.parent_immutable');
            }
        }

        if (array_key_exists('time_slot', $body)) {
            $slot = $this->reference($body['time_slot']);

            if ($slot === null) {
                $errors->add('time_slot', 'acad.validation.parent_not_found', 'acad.validation.parent_not_found');
            } else {
                $input['time_slot'] = $slot;
            }
        }

        if (array_key_exists('facility', $body)) {
            $facility = $body['facility'] === null ? null : $this->reference($body['facility']);

            if ($body['facility'] !== null && $facility === null) {
                $errors->add('facility', 'acad.validation.facility_not_found', 'acad.validation.facility_not_found');
            } else {
                $input['facility'] = $facility;
            }
        }

        $errors->throwIfAny();

        return $input;
    }
}
