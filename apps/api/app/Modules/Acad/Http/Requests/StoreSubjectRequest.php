<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\StructureFields;
use App\Modules\Acad\Application\StructureLimits;
use App\Support\Api\ValidationErrorBag;
use App\Support\I18n\TranslationsValidator;

/**
 * REQ-ACAD/api.md §2.1, `POST /subjects`. Sin `academic_year` (`RN-ACAD-05`);
 * un `subject`/`subjects` en el cuerpo no se lee (`CA-ACAD-060`).
 */
class StoreSubjectRequest extends StructureRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:'.StructureLimits::MAX_TEXT_LENGTH],
            'name_translations' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array{course: string, code: string, name_translations: array<string, string>, position: ?int}
     */
    public function payload(TranslationsValidator $translations): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        StructureFields::rejectAcademicYear($body, $errors);
        $parent = StructureFields::parentReference($body['course'] ?? null, 'course', $errors);
        $code = StructureFields::code($body['code'] ?? null, $errors);
        $names = $translations->validateForCreate($body['name_translations'] ?? null, 'name_translations', $errors, StructureLimits::MAX_TEXT_LENGTH);
        $position = isset($body['position']) ? StructureFields::position($body['position'], $errors) : null;

        $errors->throwIfAny();

        return [
            'course' => (string) $parent,
            'code' => (string) $code,
            'name_translations' => (array) $names,
            'position' => $position,
        ];
    }
}
