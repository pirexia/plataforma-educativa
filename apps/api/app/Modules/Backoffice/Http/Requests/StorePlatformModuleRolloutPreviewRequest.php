<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §2.7, `POST /module-rollouts/preview`. No escribe nada y no
 * bloquea nada (`RN-BO-68`).
 */
class StorePlatformModuleRolloutPreviewRequest extends ApiFormRequest
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
            'module_code' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
            'cascade' => ['sometimes', 'boolean'],
            // RN-BO-81: lista explícita, no vacía y sin duplicados.
            'tenant_public_ids' => ['required', 'array', 'min:1'],
            'tenant_public_ids.*' => ['required', 'string', 'distinct'],
        ];
    }
}
