<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §4, `POST /auth/password-resets`. Sin correo en el cuerpo: la
 * búsqueda es por token (`funcional.md §4.5` punto 6).
 */
class StorePasswordResetRequest extends ApiFormRequest
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
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed'],
        ];
    }
}
