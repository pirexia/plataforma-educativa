<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * api.md §2.11, §2.13. `POST /feature-flags/{key}/rules/preview`: el
 * mismo conjunto que `PUT .../rules`, sin `reason` — no escribe nada
 * (`RN-BO-68`).
 */
class StorePlatformFeatureFlagRulesPreviewRequest extends ApiFormRequest
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
            'rollout_unit' => ['prohibited'],
            'rules' => ['present', 'array'],
            'rules.*.scope_type' => ['required', 'string', 'in:global,tenant,early_adopters,percentage,role'],
            'rules.*.tenant_public_id' => ['sometimes', 'nullable', 'string'],
            'rules.*.role_code' => ['sometimes', 'nullable', 'string'],
            'rules.*.percentage' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'rules.*.enabled' => ['sometimes', 'boolean'],
        ];
    }
}
