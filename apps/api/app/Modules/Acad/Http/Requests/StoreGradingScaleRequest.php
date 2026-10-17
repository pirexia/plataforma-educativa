<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\StructureLimits;
use App\Modules\Acad\Domain\Models\GradingScale;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/api.md §11.4, `POST /grading-scales`. El curso académico llega en
 * el cuerpo y es obligatorio (`RN-ACAD-51`, `422 academic_year_required`); el
 * tipo, también (`RN-ACAD-70`). El resto —campos por tipo, valores, tramos,
 * unicidad del código— lo valida `GradingScaleValidator` en el servicio
 * (`INV-010`). `name`, `label` y las claves desconocidas se ignoran (`RN-ACAD-13`).
 */
class StoreGradingScaleRequest extends StructureRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:'.StructureLimits::MAX_TEXT_LENGTH],
            'name_translations' => ['nullable', 'array'],
            'values.*.code' => ['nullable', 'string', 'max:'.StructureLimits::MAX_TEXT_LENGTH],
        ];
    }

    /**
     * @return array{academic_year: string, scale_type: string, body: array<string, mixed>}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $academicYear = $body['academic_year'] ?? null;

        if (! is_string($academicYear) || trim($academicYear) === '') {
            $errors->add('academic_year', 'acad.validation.academic_year_required', 'acad.validation.academic_year_required');
        }

        $type = $body['scale_type'] ?? null;

        if (! is_string($type) || ! in_array($type, GradingScale::TYPES, true)) {
            $errors->add('scale_type', 'acad.validation.scale_type_invalid', 'acad.validation.scale_type_invalid');
        }

        $errors->throwIfAny();

        return [
            'academic_year' => trim((string) $academicYear),
            'scale_type' => (string) $type,
            'body' => $body,
        ];
    }
}
