<?php

namespace App\Modules\Curso\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Curso\Application\CursoErrors;

/**
 * REQ-CURSO/api.md §2, `PATCH /academic-years/{public_id}`: cualquier
 * subconjunto de `code`, `starts_on`, `ends_on`. `status` ⇒ `422
 * curso.validation.status_not_editable` (una clave **conocida** del recurso
 * enviada donde no se admite, no un parámetro desconocido: no se ignora).
 */
class UpdateAcademicYearRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (array_key_exists('status', $this->all())) {
            throw CursoErrors::validation(['status' => [CursoErrors::item('curso.validation.status_not_editable')]]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'starts_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ];
    }
}
