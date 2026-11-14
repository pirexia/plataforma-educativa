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
            // api.md §F.6 (1.4b): identificador opaco — "google" (driver
            // global) o el public_id ULID de un proveedor catalogado.
            // Cuál de los dos es, y si existe, lo decide el servicio.
            'provider' => ['required', 'string', 'max:255'],
            'intent' => ['required', 'string', Rule::in(['login', 'link'])],
        ];
    }
}
