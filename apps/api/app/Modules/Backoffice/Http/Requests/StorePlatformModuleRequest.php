<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §2.6.3, `PUT /tenants/{public_id}/modules/{module_code}`.
 * `cascade` no tiene valor por defecto verdadero (`RN-BO-67`): su
 * ausencia se resuelve a `false` en el controlador, nunca aquí.
 *
 * `reason` sólo se comprueba aquí en **tipo**, no en presencia: la
 * comprobación de negocio —obligatorio y no vacío (`RN-BO-24`)— vive en
 * `ModuleSubscriptionsService`, que produce el código de error propio
 * `bo.module.reason_required` (api.md §5) en vez del genérico
 * `core.validation.required` que produciría esta regla si exigiera
 * `required` (`ADR-038 §6.3`).
 */
class StorePlatformModuleRequest extends ApiFormRequest
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
            'enabled' => ['required', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string'],
            'cascade' => ['sometimes', 'boolean'],
        ];
    }
}
