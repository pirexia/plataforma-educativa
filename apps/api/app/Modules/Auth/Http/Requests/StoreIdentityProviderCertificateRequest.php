<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * `api.md §G.5`, `POST /identity-providers/{public_id}/certificates`.
 * Solo `certificate` — `not_before`/`not_after` no se aceptan aunque
 * vengan (`RN-AUTH-126`): esta clase no los declara, así que
 * `$request->validated()` nunca los expone al servicio.
 */
class StoreIdentityProviderCertificateRequest extends ApiFormRequest
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
            'certificate' => ['required', 'string', 'min:1', 'max:16384'],
        ];
    }
}
