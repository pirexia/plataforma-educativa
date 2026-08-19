<?php

namespace App\Modules\Core\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §7, `POST /user-imports`. `send_invitations` no lleva la regla
 * `boolean` nativa de Laravel a propósito: esa regla no acepta la cadena
 * "true" (hallazgo de `IndexUsersRequest`/`QueryBoolean`), y este campo no
 * es un parámetro de consulta sujeto a `ADR-038 §5.2` sino un campo de
 * formulario `multipart`; se lee con `$request->boolean()`, que sí
 * interpreta "true"/"false" correctamente.
 */
class StoreUserImportRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'send_invitations' => ['sometimes'],
        ];
    }
}
