<?php

namespace App\Modules\Core\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Support\Api\Rules\NotEmptyString;

/**
 * api.md §3, `PATCH /users/{public_id}`. No acepta `status`, `roles` ni
 * `deleted_at` — si llegan, se ignoran (no están en `rules()`, y
 * `CreateUser`/el controlador nunca los lee del payload validado).
 */
class UpdateUserRequest extends ApiFormRequest
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
            'email' => ['sometimes', new NotEmptyString, 'email', 'max:255'],
            'person' => ['sometimes', 'array'],
            'person.given_name' => ['sometimes', new NotEmptyString, 'string', 'max:255'],
            'person.family_name_1' => ['sometimes', new NotEmptyString, 'string', 'max:255'],
            'person.family_name_2' => ['sometimes', 'nullable', new NotEmptyString, 'string', 'max:255'],
            'person.birth_date' => ['sometimes', 'nullable', new NotEmptyString, 'date_format:Y-m-d'],
            'person.document_type' => ['sometimes', 'nullable', new NotEmptyString, 'string', 'max:32'],
            'person.document_number' => ['sometimes', 'nullable', new NotEmptyString, 'string', 'max:32'],
            'person.contact_email' => ['sometimes', 'nullable', new NotEmptyString, 'email', 'max:255'],
            'person.contact_phone' => ['sometimes', 'nullable', new NotEmptyString, 'string', 'max:32'],
            'person.locale' => ['sometimes', new NotEmptyString, 'string', 'in:es-ES,en,de,fr'],
        ];
    }
}
