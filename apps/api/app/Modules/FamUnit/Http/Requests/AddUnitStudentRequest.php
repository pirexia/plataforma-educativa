<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Support\Api\ValidationErrorBag;

/**
 * REQ-FAM-UNIT/api.md §2.3, `POST /family-units/{public_id}/students`: `student_person` (`public_id` de la persona).
 */
class AddUnitStudentRequest extends FamUnitRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    public function studentPerson(): string
    {
        $errors = new ValidationErrorBag;
        $student = self::requiredReference($this->body(), 'student_person', $errors);

        $errors->throwIfAny();

        return (string) $student;
    }
}
