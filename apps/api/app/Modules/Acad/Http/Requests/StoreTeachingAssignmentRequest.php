<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\StructureFields;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/api.md §12.3, `POST /teaching-assignments`: `teacher`, `group`,
 * `subject` y `subgroup` opcional, todos por `public_id` (`RN-ACAD-93`). Sin
 * `academic_year` (`RN-ACAD-91`): es el del grupo.
 */
class StoreTeachingAssignmentRequest extends StructureRequest
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
     * @return array{teacher: string, group: string, subject: string, subgroup: ?string}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        StructureFields::rejectAcademicYear($body, $errors);

        $teacher = $this->reference($body['teacher'] ?? null);
        $group = $this->reference($body['group'] ?? null);
        $subject = $this->reference($body['subject'] ?? null);
        $subgroup = null;

        if ($teacher === null) {
            $errors->add('teacher', 'acad.validation.user_not_found', 'acad.validation.user_not_found');
        }

        if ($group === null) {
            $errors->add('group', 'acad.validation.parent_not_found', 'acad.validation.parent_not_found');
        }

        if ($subject === null) {
            $errors->add('subject', 'acad.validation.subject_not_in_group', 'acad.validation.subject_not_in_group');
        }

        if (($body['subgroup'] ?? null) !== null) {
            $subgroup = $this->reference($body['subgroup']);

            if ($subgroup === null) {
                $errors->add('subgroup', 'acad.validation.subgroup_not_in_group', 'acad.validation.subgroup_not_in_group');
            }
        }

        $errors->throwIfAny();

        return ['teacher' => (string) $teacher, 'group' => (string) $group, 'subject' => (string) $subject, 'subgroup' => $subgroup];
    }
}
