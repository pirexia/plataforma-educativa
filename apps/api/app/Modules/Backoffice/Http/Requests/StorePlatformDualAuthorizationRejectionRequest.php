<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §2.8, §5.7 punto 5, `POST /dual-authorizations/{public_id}/rejection`.
 * Sensible. «Rechazo explícito con motivo, también auditado.»
 */
class StorePlatformDualAuthorizationRejectionRequest extends ApiFormRequest
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
            'resolution_reason' => ['required', 'string', 'min:1'],
        ];
    }
}
