<?php

namespace App\Modules\Core\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §3, `POST /users`. Reglas de forma; la validación de negocio
 * (unicidad entre vivos, dígito de documento, RPERM-013) vive en
 * `CreateUser` (INV-010).
 */
class StoreUserRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'person' => ['required', 'array'],
            'person.given_name' => ['required', 'string', 'max:255'],
            'person.family_name_1' => ['required', 'string', 'max:255'],
            'person.family_name_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'person.birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'person.document_type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'person.document_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'person.contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'person.contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'person.locale' => ['sometimes', 'nullable', 'string', 'in:es-ES,en,de,fr'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['string', 'ulid'],
            'send_invitation' => ['sometimes', 'boolean'],
        ];
    }
}
