<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\PeriodFields;
use App\Modules\Acad\Application\StructureFields;
use App\Modules\Acad\Application\StructureLimits;
use App\Support\Api\ValidationErrorBag;
use App\Support\I18n\TranslationsValidator;

/**
 * REQ-ACAD/api.md §11.4, `POST /academic-periods`. La etapa llega en el cuerpo
 * (`education_level`); el curso académico es el de la etapa y el cuerpo no lo
 * admite (`RN-ACAD-51`). Los períodos no tienen `code` (`RN-ACAD-53`). `name`
 * y las claves desconocidas se ignoran (`RN-ACAD-13`).
 */
class StoreAcademicPeriodRequest extends StructureRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name_translations' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array{education_level: string, period_type: string, name_translations: array<string, string>, starts_on: string, ends_on: string, position: ?int}
     */
    public function payload(TranslationsValidator $translations): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        StructureFields::rejectAcademicYear($body, $errors);
        $parent = StructureFields::parentReference($body['education_level'] ?? null, 'education_level', $errors);
        $type = PeriodFields::periodType($body['period_type'] ?? null, $errors);
        $names = $translations->validateForCreate($body['name_translations'] ?? null, 'name_translations', $errors, StructureLimits::MAX_TEXT_LENGTH);
        $startsOn = PeriodFields::date($body['starts_on'] ?? null, 'starts_on', $errors);
        $endsOn = PeriodFields::date($body['ends_on'] ?? null, 'ends_on', $errors);

        if ($startsOn !== null && $endsOn !== null) {
            PeriodFields::order($startsOn, $endsOn, $errors);
        }

        $position = isset($body['position']) ? StructureFields::position($body['position'], $errors) : null;

        $errors->throwIfAny();

        return [
            'education_level' => (string) $parent,
            'period_type' => (string) $type,
            'name_translations' => (array) $names,
            'starts_on' => (string) $startsOn,
            'ends_on' => (string) $endsOn,
            'position' => $position,
        ];
    }
}
