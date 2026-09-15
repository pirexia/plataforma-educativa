<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §2.7, `POST /tenants/{public_id}/modules/preview`. No escribe
 * nada y no bloquea nada (`RN-BO-68`): sin `reason`, a diferencia de la
 * ejecución — no hay motivo que registrar para algo que no se hace.
 */
class StorePlatformModulePreviewRequest extends ApiFormRequest
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
        ];
    }
}
