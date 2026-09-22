<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §2.11.1, `PUT /feature-flags/{key}/state`. `reason` obligatorio
 * y no vacío (`RN-BO-43`) — sin clave propia en el catálogo cerrado de
 * errores (api.md §5: `1.6e` no añade ninguna para motivo ausente), así
 * que se exige aquí con el mecanismo genérico de validación.
 */
class StorePlatformFeatureFlagStateRequest extends ApiFormRequest
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
            'status' => ['required', 'string', 'in:activo,forced_off'],
            'reason' => ['required', 'string', 'min:1'],
        ];
    }
}
