<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\StructureFields;
use App\Modules\Acad\Application\StructureLimits;
use App\Support\Api\ValidationErrorBag;
use App\Support\I18n\TranslationsValidator;

/**
 * REQ-ACAD/api.md §12.3, `POST /timetable-frames`. Sin `academic_year`
 * (`RN-ACAD-91`): es el de la etapa. `frame_type` obligatorio; en `1.12-a` solo
 * `ordinaria` (`RN-ACAD-110`), así que `especial` es `422 frame_type_invalid` y
 * una fecha de vigencia no tiene dónde aplicarse (`422 frame_dates_invalid`).
 * Las franjas (`slots`) las valida `TimeSlotsValidator` en el servicio.
 */
class StoreTimetableFrameRequest extends StructureRequest
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
     * @return array{education_level: string, frame_type: string, name_translations: array<string, string>, slots: mixed}
     */
    public function payload(TranslationsValidator $translations): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        StructureFields::rejectAcademicYear($body, $errors);
        $parent = StructureFields::parentReference($body['education_level'] ?? null, 'education_level', $errors);

        $type = $body['frame_type'] ?? null;

        if ($type !== 'ordinaria') {
            $errors->add('frame_type', 'acad.validation.frame_type_invalid', 'acad.validation.frame_type_invalid');
        }

        foreach (['valid_from', 'valid_to'] as $field) {
            if (($body[$field] ?? null) !== null) {
                $errors->add($field, 'acad.validation.frame_dates_invalid', 'acad.validation.frame_dates_invalid');
            }
        }

        $names = $translations->validateForCreate($body['name_translations'] ?? null, 'name_translations', $errors, StructureLimits::MAX_TEXT_LENGTH);

        $errors->throwIfAny();

        return [
            'education_level' => (string) $parent,
            'frame_type' => (string) $type,
            'name_translations' => (array) $names,
            'slots' => $body['slots'] ?? [],
        ];
    }
}
