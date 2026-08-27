<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §C.5, `POST /mfa-resets`, `§C.4.10`, `RN-AUTH-66`: motivo
 * obligatorio de al menos 10 caracteres.
 */
class StoreMfaResetRequest extends ApiFormRequest
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
            'user' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
