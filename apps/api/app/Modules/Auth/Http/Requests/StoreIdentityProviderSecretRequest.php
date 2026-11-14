<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * `api.md §F.4`, `POST /api/v1/identity-providers/{public_id}/secrets`.
 */
class StoreIdentityProviderSecretRequest extends ApiFormRequest
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
            'client_secret' => ['required', 'string', 'min:1', 'max:8192'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
