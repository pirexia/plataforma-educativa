<?php

namespace App\Modules\Curso\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Curso\Application\CursoErrors;

/**
 * REQ-CURSO/api.md §2, `POST /academic-years`. La forma (tipos, formato de
 * fecha) se valida aquí; la regla de negocio (código vacío tras recortar,
 * único, fechas coherentes, solape) en `AcademicYearInput`, con códigos
 * `curso.*` completos. `status` no es campo de entrada (RN-CURSO-03): se
 * rechaza con `422`, no se ignora en silencio.
 */
class StoreAcademicYearRequest extends ApiFormRequest
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
            'code' => ['nullable', 'string', 'max:255'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array{code: mixed, starts_on: string, ends_on: string}
     */
    public function academicYearInput(): array
    {
        /** @var array{code?: mixed, starts_on: string, ends_on: string} $validated */
        $validated = $this->validated();

        return ['code' => $validated['code'] ?? null, 'starts_on' => $validated['starts_on'], 'ends_on' => $validated['ends_on']];
    }
}
