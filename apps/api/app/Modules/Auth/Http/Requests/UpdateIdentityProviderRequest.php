<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `api.md §F.3`, `PATCH /api/v1/identity-providers/{public_id}`. Si
 * viene `discovery_url`, se revalida entera (`IdentityProviderService`).
 * `is_enabled: true` exige credencial vigente — comprobación de negocio,
 * no de forma.
 */
class UpdateIdentityProviderRequest extends ApiFormRequest
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
            'display_name' => ['sometimes', 'string', 'max:255'],
            'discovery_url' => ['sometimes', 'string', 'max:2048', 'url'],
            'client_id' => ['sometimes', 'string', 'max:255'],
            'email_claim' => ['sometimes', 'string', Rule::in(['email', 'preferred_username', 'upn'])],
            'claims_source' => ['sometimes', 'string', Rule::in(['id_token', 'userinfo'])],
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => ['string', 'max:255'],
            'allowed_email_domains' => ['sometimes', 'array'],
            'allowed_email_domains.*' => ['string', 'max:255'],
            'provisioning_mode' => ['sometimes', 'string', Rule::in(['desactivado', 'emparejamiento'])],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
