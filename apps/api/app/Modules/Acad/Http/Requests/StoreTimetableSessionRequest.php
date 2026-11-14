<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\StructureFields;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/api.md §12.3, `POST /timetable-sessions`: `time_slot`,
 * `teaching_assignment` y `facility` opcional (aula). Sin `academic_year`
 * (`RN-ACAD-91`): es el de la franja.
 */
class StoreTimetableSessionRequest extends StructureRequest
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
     * @return array{time_slot: string, teaching_assignment: string, facility: ?string}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        StructureFields::rejectAcademicYear($body, $errors);

        $slot = $this->reference($body['time_slot'] ?? null);
        $assignment = $this->reference($body['teaching_assignment'] ?? null);
        $facility = null;

        if ($slot === null) {
            $errors->add('time_slot', 'acad.validation.parent_not_found', 'acad.validation.parent_not_found');
        }

        if ($assignment === null) {
            $errors->add('teaching_assignment', 'acad.validation.parent_not_found', 'acad.validation.parent_not_found');
        }

        if (($body['facility'] ?? null) !== null) {
            $facility = $this->reference($body['facility']);

            if ($facility === null) {
                $errors->add('facility', 'acad.validation.facility_not_found', 'acad.validation.facility_not_found');
            }
        }

        $errors->throwIfAny();

        return ['time_slot' => (string) $slot, 'teaching_assignment' => (string) $assignment, 'facility' => $facility];
    }
}
