<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §C.1, `POST /auth/mfa-factors`, `§C.4.1` puntos 5-6: confirma un
 * alta provisional.
 */
class StoreMfaFactorRequest extends ApiFormRequest
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
            'enrollment' => ['required', 'string'],
            'code' => ['required', 'string'],
        ];
    }
}
