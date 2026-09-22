<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §2.11.1, `PUT /feature-flags/{key}/rules`. El conjunto completo,
 * no un parche (`RN-BO-104`): `rules: []` es válido y significa «ninguna
 * regla» (`RN-BO-35`). Cada regla lleva exactamente la columna de su eje
 * — la coherencia fina (`bo.flag.invalid_rule`) la comprueba el servicio,
 * no esta clase, porque depende de combinaciones entre campos que
 * `FormRequest` expresaría peor que un `guard` explícito.
 */
class StorePlatformFeatureFlagRulesRequest extends ApiFormRequest
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
            // RN-BO-36: la unidad de reparto la declara el código, nunca
            // el operador — un `rollout_unit` en el cuerpo es 422.
            'rollout_unit' => ['prohibited'],
            'reason' => ['required', 'string', 'min:1'],
            'rules' => ['present', 'array'],
            'rules.*.scope_type' => ['required', 'string', 'in:global,tenant,early_adopters,percentage,role'],
            'rules.*.tenant_public_id' => ['sometimes', 'nullable', 'string'],
            'rules.*.role_code' => ['sometimes', 'nullable', 'string'],
            'rules.*.percentage' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'rules.*.enabled' => ['sometimes', 'boolean'],
        ];
    }
}
