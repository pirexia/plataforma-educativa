<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/** api.md §2.1, `POST /auth/reauthenticate`. RN-BO-08. */
class StorePlatformReauthenticationRequest extends ApiFormRequest
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
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ];
    }
}
