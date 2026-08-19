<?php

namespace App\Modules\Core\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * api.md §8, `GET /audit-logs`.
 */
class IndexAuditLogsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'actor_id' => ['sometimes', 'string', 'ulid'],
            'actor_type' => ['sometimes', Rule::in(['user', 'system', 'console', 'import', 'platform'])],
            'event' => ['sometimes', 'string'],
            'auditable_type' => ['sometimes', 'string'],
            'auditable_id' => ['sometimes', 'string', 'ulid'],
            'module' => ['sometimes', 'string'],
            'cursor' => ['sometimes', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
