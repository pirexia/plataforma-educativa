<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §2.4.3, `POST /tenants/{public_id}/clone`. Sensible. Mismo
 * cuerpo que el alta **menos `settings`** —se copian del origen— y
 * **más** `reason`.
 */
class StoreTenantCloneRequest extends ApiFormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/'],
            'reason' => ['required', 'string', 'min:1'],

            'administrator' => ['required', 'array'],
            'administrator.email' => ['required', 'string', 'email', 'max:255'],
            'administrator.given_name' => ['required', 'string', 'max:255'],
            'administrator.family_name' => ['required', 'string', 'max:255'],
        ];
    }
}
