<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/** api.md §2.8, `POST /dual-authorizations/{public_id}/approval`. Sensible. */
class StorePlatformDualAuthorizationApprovalRequest extends ApiFormRequest
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
            'resolution_reason' => ['nullable', 'string'],
        ];
    }
}
