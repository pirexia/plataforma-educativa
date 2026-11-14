<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §2.4, `PATCH /tenants/{public_id}`. Nombre y
 * `suspension_message` — el `slug` va aparte (§2.5).
 */
class UpdateTenantRequest extends ApiFormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'suspension_message' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
