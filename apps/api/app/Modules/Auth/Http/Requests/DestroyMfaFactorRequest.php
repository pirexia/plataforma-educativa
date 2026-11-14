<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §C.1, `DELETE /auth/mfa-factors/{public_id}`, `§C.4.6` punto 1:
 * exige la contraseña actual (mismo argumento que `§C.4.3` punto 4).
 */
class DestroyMfaFactorRequest extends ApiFormRequest
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
