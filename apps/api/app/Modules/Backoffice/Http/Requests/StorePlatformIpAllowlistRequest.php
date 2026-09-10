<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/** api.md §2.3, `POST /ip-allowlist`. Sensible. */
class StorePlatformIpAllowlistRequest extends ApiFormRequest
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
            // RN-BO-07: nunca vacía como concepto de "sin restricción",
            // pero una entrada individual sí puede ser un rango amplio —
            // eso lo decide el operador, no la validación de forma.
            'cidr' => ['required', 'string'],
            'description' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }
}
