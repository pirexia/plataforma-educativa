<?php

namespace App\Modules\Curso\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Curso\Application\CursoErrors;

/**
 * REQ-CURSO/api.md §2, `POST /academic-years/{public_id}/reopen` (1.11c,
 * `RN-CURSO-43`, `CA-CURSO-102`). `reason` es obligatorio: se recorta de
 * espacios y no puede quedar vacío; ausente, no textual o vacío tras recortar
 * es `422 curso.validation.reason_required` (código completo `curso.*`, nunca
 * derivado por el formateador, issue #60). Límite de longitud [DERIVADA]: el
 * mismo que los demás motivos del producto (`mfa_resets.reason`).
 */
class ReopenAcademicYearRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');
        $trimmed = is_string($reason) ? trim($reason) : '';

        if ($trimmed === '') {
            throw CursoErrors::validation(['reason' => [CursoErrors::item('curso.validation.reason_required')]]);
        }

        $this->merge(['reason' => $trimmed]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /** El motivo ya recortado y no vacío. */
    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
