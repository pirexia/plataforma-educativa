<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * api.md §C.1, `POST /auth/mfa-enrollments`, `§C.4.1` punto 2. Regla de
 * forma únicamente: si el método está permitido por el tenant e
 * implementado por el producto es negocio (`MfaEnrollmentService`,
 * `RN-AUTH-69`, `§C.16`).
 */
class StoreMfaEnrollmentRequest extends ApiFormRequest
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
            'method' => ['required', 'string', Rule::in(['totp', 'email', 'sms'])],
        ];
    }
}
