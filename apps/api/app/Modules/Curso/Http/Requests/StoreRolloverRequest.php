<?php

namespace App\Modules\Curso\Http\Requests;

/**
 * REQ-CURSO/api.md §2.1, `POST /academic-year-rollovers` (1.12b): cuerpo con `academic_year` (destino),
 * `source`, `elements` (lista de códigos) y `copy_teaching_assignments` (booleano, opcional, por defecto
 * `false`; `"si"` u otro valor no booleano ⇒ `422` de forma, `RN-CURSO-82`).
 */
class StoreRolloverRequest extends RolloverSelectionRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'academic_year' => ['sometimes', 'nullable', 'string', 'max:64'],
            'source' => ['sometimes', 'nullable', 'string', 'max:64'],
            'elements' => ['sometimes', 'nullable', 'array', 'max:100'],
            'elements.*' => ['string', 'max:100'],
            'copy_teaching_assignments' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    protected function requestedElements(): array
    {
        $elements = $this->validated('elements');

        return is_array($elements) ? array_values(array_map('strval', $elements)) : [];
    }

    protected function copyTeachingAssignments(): bool
    {
        return filter_var($this->validated('copy_teaching_assignments'), FILTER_VALIDATE_BOOLEAN);
    }
}
