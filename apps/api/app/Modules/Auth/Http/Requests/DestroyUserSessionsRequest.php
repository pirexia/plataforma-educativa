<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * api.md §B.4, `DELETE /auth/sessions`. Sin permiso — por identidad.
 */
class DestroyUserSessionsRequest extends ApiFormRequest
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
            // RN-AUTH-43: por defecto 'others' — un cliente que se olvide
            // del parámetro nunca se echa a sí mismo del sistema.
            'scope' => ['sometimes', Rule::in(['others', 'all'])],
        ];
    }
}
