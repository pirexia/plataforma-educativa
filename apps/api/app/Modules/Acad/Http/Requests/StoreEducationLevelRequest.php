<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\StructureFields;
use App\Modules\Acad\Application\StructureLimits;
use App\Support\Api\ValidationErrorBag;
use App\Support\I18n\TranslationsValidator;

/**
 * REQ-ACAD/api.md §2.1, `POST /education-levels`. `name` en el cuerpo se
 * ignora (`RN-ACAD-13`); las claves desconocidas, también.
 */
class StoreEducationLevelRequest extends StructureRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'academic_year' => ['nullable', 'string'],
            'code' => ['nullable', 'string', 'max:'.StructureLimits::MAX_TEXT_LENGTH],
            'name_translations' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array{academic_year: string, code: string, name_translations: array<string, string>, stage: string, regime: string, position: ?int}
     */
    public function payload(TranslationsValidator $translations): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $year = $body['academic_year'] ?? null;

        if (! is_string($year) || trim($year) === '') {
            $errors->add('academic_year', 'acad.validation.academic_year_required', 'acad.validation.academic_year_required');
        }

        $code = StructureFields::code($body['code'] ?? null, $errors);
        $names = $translations->validateForCreate($body['name_translations'] ?? null, 'name_translations', $errors, StructureLimits::MAX_TEXT_LENGTH);
        $stage = StructureFields::stage($body['stage'] ?? null, $errors);
        $regime = StructureFields::regime($body['regime'] ?? null, $errors);
        $position = isset($body['position']) ? StructureFields::position($body['position'], $errors) : null;

        $errors->throwIfAny();

        return [
            'academic_year' => trim((string) $year),
            'code' => (string) $code,
            'name_translations' => (array) $names,
            'stage' => (string) $stage,
            'regime' => (string) $regime,
            'position' => $position,
        ];
    }
}
