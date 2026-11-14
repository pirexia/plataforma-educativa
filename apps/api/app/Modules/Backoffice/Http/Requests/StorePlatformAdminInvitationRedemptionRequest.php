<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * Issue #173. Precedente de forma:
 * `App\Modules\Auth\Http\Requests\StoreInvitationRedemptionRequest`. La
 * política de contraseña (longitud, complejidad) la valida
 * `PlatformAdminInvitationRedemptionService` en el servicio, no aquí —
 * sólo la coincidencia con la confirmación es una regla de forma.
 */
class StorePlatformAdminInvitationRedemptionRequest extends ApiFormRequest
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
