<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §2.6.4, `POST /module-rollouts`. `reason` sólo se comprueba en
 * tipo aquí — la comprobación de negocio vive en
 * `ModuleSubscriptionsService` (`bo.module.reason_required`), mismo
 * criterio que `StorePlatformModuleRequest`.
 */
class StorePlatformModuleRolloutRequest extends ApiFormRequest
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
            // RN-BO-79: un enabled para todo el lote.
            'enabled' => ['required', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string'],
            'cascade' => ['sometimes', 'boolean'],
            // RN-BO-81: lista explícita, no vacía y sin duplicados. Sin
            // selector por filtro.
            'tenant_public_ids' => ['required', 'array', 'min:1'],
            'tenant_public_ids.*' => ['required', 'string', 'distinct'],
        ];
    }
}
