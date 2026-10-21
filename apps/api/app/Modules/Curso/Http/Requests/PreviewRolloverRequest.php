<?php

namespace App\Modules\Curso\Http\Requests;

use App\Support\Api\Rules\QueryBoolean;

/**
 * REQ-CURSO/api.md §2.1, `GET /academic-year-rollovers/preview` (1.12b): `academic_year` (destino),
 * `source`, `elements` (códigos separados por comas, `ADR-038 §5.2`) y `copy_teaching_assignments`
 * (`true` o `false`; omitido ⇒ `false`; otro valor ⇒ `422` de forma, `RN-CURSO-82`).
 */
class PreviewRolloverRequest extends RolloverSelectionRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'academic_year' => ['sometimes', 'nullable', 'string', 'max:64'],
            'source' => ['sometimes', 'nullable', 'string', 'max:64'],
            'elements' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'copy_teaching_assignments' => ['sometimes', 'nullable', new QueryBoolean],
        ];
    }

    protected function requestedElements(): array
    {
        $elements = $this->validated('elements');

        if (! is_string($elements) || trim($elements) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $elements)), static fn (string $code): bool => $code !== ''));
    }

    protected function copyTeachingAssignments(): bool
    {
        return $this->validated('copy_teaching_assignments') === 'true';
    }
}
