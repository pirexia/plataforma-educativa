<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * `api.md §2.10.3`, `POST /tenants/{public_id}/failed-jobs/{uuid}/retry`.
 * `reason` sólo se comprueba aquí en **tipo**, no en presencia: la
 * comprobación de negocio —obligatorio y no vacío (`RN-BO-85`)— vive en
 * `FailedJobRetryService`, que produce el código de error propio
 * `bo.job.reason_required` en vez del genérico `core.validation.required`
 * que produciría una regla `required` de `FormRequest` (mismo criterio
 * que `StorePlatformModuleRequest`).
 *
 * Ningún otro campo: `queue`, `delay` y `connection` los declara la
 * propia fila de `failed_jobs`, y aceptarlos del cliente dejaría que el
 * operador reencolara un trabajo en una cola que nadie procesa
 * (`api.md §2.10.3`).
 */
class StoreFailedJobRetryRequest extends ApiFormRequest
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
            'reason' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
