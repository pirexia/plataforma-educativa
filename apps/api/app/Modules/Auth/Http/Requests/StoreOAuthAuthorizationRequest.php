<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `api.md §E.3`, `POST /auth/oauth-authorizations`. Anónimo con
 * `intent = "login"`; por identidad con `intent = "link"` — esa
 * comprobación es de negocio (`OAuthAuthorizationService::begin()`), no
 * de forma.
 */
class StoreOAuthAuthorizationRequest extends ApiFormRequest
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
            'provider' => ['required', 'string', Rule::in(['google'])],
            'intent' => ['required', 'string', Rule::in(['login', 'link'])],
        ];
    }
}
