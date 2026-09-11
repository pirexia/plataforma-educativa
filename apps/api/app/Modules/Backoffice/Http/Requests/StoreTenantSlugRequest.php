<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §2.5, `POST /tenants/{public_id}/slug`. Sensible. Valida lo
 * mismo que el alta (formato de etiqueta DNS) y exige `reason` como
 * cualquier otra escritura de ciclo de vida.
 */
class StoreTenantSlugRequest extends ApiFormRequest
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
            'slug' => ['required', 'string', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/'],
            'reason' => ['required', 'string', 'min:1'],
        ];
    }
}
