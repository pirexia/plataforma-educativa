<?php

namespace App\Modules\Core\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Support\Authorization\Scope;
use Illuminate\Validation\Rule;

/**
 * REQ-PERM/api.md §5, `PUT /roles/{public_id}/permissions`. `permissions`
 * es obligatorio y admite el array vacío («quítalas todas»,
 * `ADR-038 §9.3`) — por eso `present`, no `required`. `scope` es
 * obligatorio en cada entrada y no admite `null`: omitirlo equivale a
 * conceder acceso total (`datos.md §2.2`).
 */
class ReplaceRolePermissionsRequest extends ApiFormRequest
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
            'permissions' => ['present', 'array'],
            'permissions.*.code' => ['required', 'string'],
            'permissions.*.effect' => ['required', 'string', 'in:allow,deny'],
            'permissions.*.scope' => ['required', 'string', Rule::in(Scope::values())],
        ];
    }
}
