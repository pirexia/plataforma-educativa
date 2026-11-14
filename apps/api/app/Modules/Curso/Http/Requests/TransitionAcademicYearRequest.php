<?php

namespace App\Modules\Curso\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-CURSO/api.md §2, `POST /academic-years/{public_id}/status`: `status`
 * ∈ {`activo`, `cerrado`}; ausente u otro valor ⇒ `422`.
 */
class TransitionAcademicYearRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['activo', 'cerrado'])],
        ];
    }
}
