<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * api.md §B.2, `GET /auth/sessions`. Sin permiso — por identidad
 * (`RN-AUTH-41`); `authorize()` siempre `true`, el `401` sin sesión lo
 * lanza el controlador (`api.md §B.1`).
 */
class IndexUserSessionsRequest extends ApiFormRequest
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
            'sort' => ['sometimes', Rule::in(['started_at', '-started_at', 'last_activity_at', '-last_activity_at'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
