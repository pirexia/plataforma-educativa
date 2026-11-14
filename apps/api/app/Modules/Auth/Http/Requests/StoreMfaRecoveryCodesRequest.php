<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §C.1, `POST /auth/mfa-recovery-codes`, `§C.4.3` punto 4:
 * regenera el lote. `RN-AUTH-60` exige la contraseña actual.
 */
class StoreMfaRecoveryCodesRequest extends ApiFormRequest
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
            'current_password' => ['required', 'string'],
        ];
    }
}
