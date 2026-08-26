<?php

namespace App\Modules\Core\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §5, `PATCH /roles/{public_id}`. REQ-AUTH/funcional.md §C.2.2,
 * §C.16, `RN-AUTH-70` (1.3): acotado a **exactamente** `mfa_required` —
 * el resto del editor de roles es 1.5. Que el cuerpo no traiga ninguna
 * otra clave es negocio (`RolesController::update()`, `ValidationErrorBag`),
 * no expresable como una regla de Laravel sobre un campo aislado.
 */
class PatchRoleRequest extends ApiFormRequest
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
            'mfa_required' => ['required', 'boolean'],
        ];
    }
}
