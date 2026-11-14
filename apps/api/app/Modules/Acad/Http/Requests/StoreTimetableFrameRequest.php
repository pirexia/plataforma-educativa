<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\PeriodFields;
use App\Modules\Acad\Application\StructureFields;
use App\Modules\Acad\Application\StructureLimits;
use App\Modules\Acad\Application\TimetableFrameService;
use App\Support\Api\ValidationErrorBag;
use App\Support\I18n\TranslationsValidator;

/**
 * REQ-ACAD/api.md §12.3, `POST /timetable-frames`. Sin `academic_year`
 * (`RN-ACAD-91`): es el de la etapa. `frame_type` obligatorio, `ordinaria` o
 * `especial` (`RN-ACAD-110`, `422 frame_type_invalid` si no). Un `ordinaria` no
 * lleva fechas (`422 frame_dates_invalid`); un `especial` lleva `valid_from` y
 * `valid_to` reales (`422 date_invalid` si el formato no es `AAAA-MM-DD`,
 * `422 frame_dates_invalid` si faltan o `valid_to < valid_from`, `RN-ACAD-111`).
 * Que caigan dentro del curso y no se solapen lo comprueba el servicio. Las
 * franjas (`slots`) las valida `TimeSlotsValidator` en el servicio.
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
     * @return array{education_level: string, frame_type: string, name_translations: array<string, string>, slots: mixed, valid_from: ?string, valid_to: ?string}
     */
    public function payload(TranslationsValidator $translations): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        StructureFields::rejectAcademicYear($body, $errors);
        $parent = StructureFields::parentReference($body['education_level'] ?? null, 'education_level', $errors);

        $type = $body['frame_type'] ?? null;

        if (! is_string($type) || ! in_array($type, TimetableFrameService::TYPES, true)) {
            $errors->add('frame_type', 'acad.validation.frame_type_invalid', 'acad.validation.frame_type_invalid');
        }

        $from = null;
        $to = null;

        if ($type === 'especial') {
            $from = $this->requiredDate($body, 'valid_from', $errors);
            $to = $this->requiredDate($body, 'valid_to', $errors);

            if ($from !== null && $to !== null && $to < $from) {
                $errors->add('valid_to', 'acad.validation.frame_dates_invalid', 'acad.validation.frame_dates_invalid');
            }
        } else {
            foreach (['valid_from', 'valid_to'] as $field) {
                if (($body[$field] ?? null) !== null) {
                    $errors->add($field, 'acad.validation.frame_dates_invalid', 'acad.validation.frame_dates_invalid');
                }
            }
        }

        $names = $translations->validateForCreate($body['name_translations'] ?? null, 'name_translations', $errors, StructureLimits::MAX_TEXT_LENGTH);

        $errors->throwIfAny();

        return [
            'education_level' => (string) $parent,
            'frame_type' => (string) $type,
            'name_translations' => (array) $names,
            'slots' => $body['slots'] ?? [],
            'valid_from' => $from,
            'valid_to' => $to,
        ];
    }

    /**
     * `RN-ACAD-111`: la fecha de un `especial` es obligatoria (`frame_dates_invalid`) y real (`date_invalid`).
     *
     * @param  array<string, mixed>  $body
     */
    private function requiredDate(array $body, string $field, ValidationErrorBag $errors): ?string
    {
        if (($body[$field] ?? null) === null) {
            $errors->add($field, 'acad.validation.frame_dates_invalid', 'acad.validation.frame_dates_invalid');

            return null;
        }

        return PeriodFields::date($body[$field], $field, $errors);
    }
}
