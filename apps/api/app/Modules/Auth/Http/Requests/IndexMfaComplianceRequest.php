<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §C.5, `GET /mfa-compliance`, `§C.1.1` punto 9, `CA-AUTH-136`.
 * `mfa_required` ausente ⇒ estado real; presente ⇒ vista previa
 * hipotética sin escribir nada.
 */
class IndexMfaComplianceRequest extends ApiFormRequest
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
            'role' => ['required', 'string'],
            'mfa_required' => ['sometimes', 'boolean'],
        ];
    }
}
