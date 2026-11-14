<?php

namespace App\Modules\Core\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Support\Authorization\Scope;
use Illuminate\Validation\Rule;

/**
 * REQ-PERM/api.md §3 (1.5), `POST /roles`. Alta y clonación comparten
 * forma: `code`/`name` siempre obligatorios (también en clonación —
 * `api.md §3.2` renombra el clon), `clone_from` y `permissions`
 * mutuamente excluyentes (comprobado en `CreateRole`, cruza dos campos).
 *
 * `is_system` y `name_key` no son campos del cuerpo: `prohibited` los
 * rechaza con 422 sin necesidad de código de negocio propio.
 */
class StoreRoleRequest extends ApiFormRequest
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
            'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,63}$/'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'mfa_required' => ['sometimes', 'boolean'],
            'special_data_access' => ['sometimes', 'boolean'],
            'clone_from' => ['sometimes', 'string', 'ulid'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*.code' => ['required', 'string'],
            'permissions.*.effect' => ['required', 'string', 'in:allow,deny'],
            'permissions.*.scope' => ['required', 'string', Rule::in(Scope::values())],
            'is_system' => ['prohibited'],
            'name_key' => ['prohibited'],
        ];
    }
}
