<?php

namespace App\Modules\Core\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Core\Domain\AutonomousCommunity;
use App\Support\Api\Rules\NotEmptyString;
use Illuminate\Validation\Rule;

/**
 * api.md §2, `PATCH /tenant/settings`. Reglas de forma únicamente
 * (INV-010 exige además validación de negocio en servidor, que hace
 * TenantSettingsController: pertenencia de default_locale a
 * active_locales, RN-CORE-13, y contraste WCAG, RN-CORE-15 — ninguna de
 * las dos es expresable como regla de Laravel sobre un campo aislado).
 *
 * `sometimes` en cada campo, nunca `required`: ADR-038 §9.2 regla 1
 * (clave ausente no toca el campo). `nullable` + NotEmptyString: reglas
 * 2 y 3 ("" es 422, para vaciar se envía null) en los campos anulables.
 */
class PatchTenantSettingsRequest extends ApiFormRequest
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
            'regional' => ['sometimes', 'array'],
            'regional.default_locale' => ['sometimes', 'string', Rule::in(['es-ES', 'en', 'de', 'fr'])],
            'regional.active_locales' => ['sometimes', 'array', 'min:1'],
            'regional.active_locales.*' => ['string', Rule::in(['es-ES', 'en', 'de', 'fr'])],
            'regional.timezone' => ['sometimes', 'string', 'timezone:all'],
            'regional.currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'regional.autonomous_community' => ['sometimes', 'nullable', new NotEmptyString, Rule::in(AutonomousCommunity::CODES)],

            'fiscal' => ['sometimes', 'array'],
            'fiscal.legal_name' => ['sometimes', 'nullable', new NotEmptyString, 'string', 'max:255'],
            'fiscal.tax_id' => ['sometimes', 'nullable', new NotEmptyString, 'string', 'max:32'],
            'fiscal.address' => ['sometimes', 'nullable', new NotEmptyString, 'string', 'max:255'],
            'fiscal.postal_code' => ['sometimes', 'nullable', new NotEmptyString, 'string', 'max:16'],
            'fiscal.city' => ['sometimes', 'nullable', new NotEmptyString, 'string', 'max:120'],
            'fiscal.province' => ['sometimes', 'nullable', new NotEmptyString, 'string', 'max:120'],
            'fiscal.country_code' => ['sometimes', 'nullable', new NotEmptyString, 'string', 'regex:/^[A-Z]{2}$/'],

            'branding' => ['sometimes', 'array'],
            'branding.color_primary' => ['sometimes', 'nullable', new NotEmptyString, 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'branding.color_secondary' => ['sometimes', 'nullable', new NotEmptyString, 'regex:/^#[0-9A-Fa-f]{6}$/'],

            // REQ-AUTH/api.md §6, RN-AUTH-30: escalar NOT NULL — ausente
            // no toca el campo, null es 422 (regla 2 de ADR-038 §9.2: la
            // columna no admite NULL, así que no hay forma de "vaciarlo").
            'security' => ['sometimes', 'array'],
            'security.session_timeout_minutes' => ['sometimes', 'integer', 'between:5,480'],
        ];
    }
}
