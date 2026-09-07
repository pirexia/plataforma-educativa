<?php

namespace App\Modules\Core\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §5, `PATCH /roles/{public_id}`. Hasta 1.3 (`REQ-AUTH/funcional.md
 * §C.2.2`, `§C.16`, `RN-AUTH-70`) acotado a **exactamente** `mfa_required`.
 * REQ-PERM/api.md §4 (1.5) abre `name` y `special_data_access` sobre la
 * misma ruta y el mismo permiso base — las tres son `sometimes`
 * (`ADR-038 §9.2`: clave ausente no toca el campo). Cualquier otra clave
 * (incluido `code`) se rechaza en `PatchRole` (App\Modules\Core\Application),
 * no aquí: la lista de claves permitidas es negocio, no una regla de
 * Laravel sobre un campo aislado.
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
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'mfa_required' => ['sometimes', 'boolean'],
            'special_data_access' => ['sometimes', 'boolean'],
        ];
    }
}
