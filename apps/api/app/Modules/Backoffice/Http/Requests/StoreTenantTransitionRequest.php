<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Validation\Rule;

/**
 * api.md §2.4, §2.4.2, `POST /tenants/{id}/transitions`. La validación de
 * **forma** solo comprueba que `to_status` sea un valor reconocido del
 * vocabulario y que el motivo no esté vacío — cuáles transiciones son
 * alcanzables desde el estado actual es una regla de negocio
 * (`RN-BO-12`) que decide `TenantTransitionCapability`, no este
 * `FormRequest`.
 */
class StoreTenantTransitionRequest extends ApiFormRequest
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
            'to_status' => ['required', 'string', Rule::enum(TenantStatus::class)],
            'reason' => ['required', 'string', 'min:1'],
            'suspension_message' => ['nullable', 'string'],
            'confirmation_name' => ['required_if:to_status,eliminado', 'string'],
        ];
    }
}
