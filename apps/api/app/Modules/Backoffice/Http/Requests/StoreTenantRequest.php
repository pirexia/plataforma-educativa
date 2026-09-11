<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Core\Domain\AutonomousCommunity;
use Illuminate\Validation\Rule;

/**
 * api.md §2.4.1, `POST /tenants`. Sensible. Comprueba lo que le
 * corresponde a `REQ-BO` (ADR-048 §4.7): campos obligatorios,
 * `autonomous_community` obligatoria aquí aunque la columna sea
 * anulable, `slug` con formato de etiqueta DNS, formato de correo.
 * `REQ-CORE` vuelve a comprobar **coherencia** dentro de
 * `TenantInitialSettings`, y ese es un defecto de programación si llega
 * mal desde aquí, no un error de operador.
 */
class StoreTenantRequest extends ApiFormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/'],
            'reason' => ['required', 'string', 'min:1'],

            'settings' => ['required', 'array'],
            'settings.default_locale' => ['required', 'string', Rule::in(['es-ES', 'en', 'de', 'fr'])],
            'settings.active_locales' => ['required', 'array', 'min:1'],
            'settings.active_locales.*' => ['string', Rule::in(['es-ES', 'en', 'de', 'fr'])],
            'settings.timezone' => ['required', 'string'],
            'settings.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'settings.autonomous_community' => ['required', 'string', Rule::in(AutonomousCommunity::CODES)],

            'administrator' => ['required', 'array'],
            'administrator.email' => ['required', 'string', 'email', 'max:255'],
            'administrator.given_name' => ['required', 'string', 'max:255'],
            'administrator.family_name' => ['required', 'string', 'max:255'],
        ];
    }
}
