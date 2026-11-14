<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * api.md §C.1, `POST /auth/mfa-challenges`, `§C.4.4.1`: cambia el método
 * en curso del desafío abierto o reenvía el código.
 */
class StoreMfaChallengeRequest extends ApiFormRequest
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
