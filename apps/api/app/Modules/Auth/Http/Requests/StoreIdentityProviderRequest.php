<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `api.md §F.3`, `POST /api/v1/identity-providers`. `is_enabled` no se
 * acepta a propósito (`funcional.md §F.4.1`): un proveedor nace siempre
 * no activo. La validación del documento de descubrimiento es de
 * negocio (`IdentityProviderService`), no de forma.
 */
class StoreIdentityProviderRequest extends ApiFormRequest
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
            'display_name' => ['required', 'string', 'max:255'],
            'discovery_url' => ['required', 'string', 'max:2048', 'url'],
            'client_id' => ['required', 'string', 'max:255'],
            'email_claim' => ['sometimes', 'string', Rule::in(['email', 'preferred_username', 'upn'])],
            'claims_source' => ['sometimes', 'string', Rule::in(['id_token', 'userinfo'])],
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => ['string', 'max:255'],
            'allowed_email_domains' => ['sometimes', 'array'],
            'allowed_email_domains.*' => ['string', 'max:255'],
            'provisioning_mode' => ['sometimes', 'string', Rule::in(['desactivado', 'emparejamiento'])],
        ];
    }
}
