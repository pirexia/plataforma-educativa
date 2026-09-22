<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §2.11.1, `PUT /tenants/{public_id}/early-adopter`. `reason`
 * obligatorio y no vacío (`RN-BO-43`), igual que las otras dos escrituras
 * de este grupo.
 */
class StorePlatformTenantEarlyAdopterRequest extends ApiFormRequest
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
            'early_adopter' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:1'],
        ];
    }
}
