<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §D.4, `POST /api/v1/mfa-exemptions`, funcional.md §D.4.6.
 * `RN-AUTH-81`: motivo de al menos 10 caracteres y caducidad futura de
 * como mucho `AUTH_MFA_MAX_EXEMPTION_DAYS` (90) por delante — el motor
 * solo garantiza que `expires_at` existe (`datos.md §D.3`), el tope es de
 * aplicación. Quién es el sujeto y si ya tiene una excepción viva son
 * comprobaciones de negocio (`MfaExemptionService`), no de forma.
 */
class StoreMfaExemptionRequest extends ApiFormRequest
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
        $maxExemptionDays = (int) config('auth-local.mfa.max_exemption_days');

        return [
            'user' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'expires_at' => [
                'required',
                'date',
                'after:now',
                'before_or_equal:'.now()->addDays($maxExemptionDays)->toISOString(),
            ],
        ];
    }
}
