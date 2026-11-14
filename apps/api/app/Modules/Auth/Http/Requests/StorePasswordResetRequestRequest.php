<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §4, `POST /auth/password-reset-requests`. El único caso donde
 * este endpoint no responde `202` (`api.md §4`): forma de correo
 * inválida.
 */
class StorePasswordResetRequestRequest extends ApiFormRequest
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
            'email' => ['required', 'string', 'email'],
        ];
    }
}
