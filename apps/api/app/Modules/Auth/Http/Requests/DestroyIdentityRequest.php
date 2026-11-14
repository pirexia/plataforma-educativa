<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * `api.md §E.5`, `DELETE /auth/identities/{public_id}`, `funcional.md
 * §E.4.5` punto 1: exige la contraseña actual (mismo criterio que
 * `DestroyMfaFactorRequest`, `§C.4.6`).
 */
class DestroyIdentityRequest extends ApiFormRequest
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
