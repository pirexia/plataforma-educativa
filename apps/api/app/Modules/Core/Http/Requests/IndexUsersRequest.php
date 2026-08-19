<?php

namespace App\Modules\Core\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Support\Api\Rules\QueryBoolean;
use Illuminate\Validation\Rule;

/**
 * api.md §3, `GET /users`. ADR-038 §5.2: valores múltiples separados por
 * comas, nunca repetidos — se parten en el controlador.
 */
class IndexUsersRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string'],
            'role' => ['sometimes', 'string'],
            'locale' => ['sometimes', 'string', 'in:es-ES,en,de,fr'],
            'include_deleted' => ['sometimes', new QueryBoolean],
            'sort' => ['sometimes', Rule::in(['family_name_1', '-family_name_1', 'created_at', '-created_at', 'email'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
