<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §C.1, `POST /auth/mfa-verifications`, `§C.4.4` punto 6: el paso
 * 2 del login. Exactamente uno de `code`/`recovery_code`.
 */
class StoreMfaVerificationRequest extends ApiFormRequest
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
            'code' => ['required_without:recovery_code', 'prohibits:recovery_code', 'string'],
            'recovery_code' => ['required_without:code', 'prohibits:code', 'string'],
        ];
    }
}
