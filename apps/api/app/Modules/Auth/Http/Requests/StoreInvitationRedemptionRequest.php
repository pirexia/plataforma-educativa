<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §3, `POST /auth/invitation-redemptions`. La política de
 * contraseña (longitud, complejidad) la valida `PasswordPolicyValidator`
 * en el servicio, no aquí — solo la coincidencia con la confirmación es
 * una regla de forma.
 */
class StoreInvitationRedemptionRequest extends ApiFormRequest
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
