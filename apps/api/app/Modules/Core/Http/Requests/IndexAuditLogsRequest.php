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

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'actor_id' => ['sometimes', 'string', 'ulid'],
            // ADR-039 §4.1: 'anonymous' se añade al vocabulario para
            // password_reset_requested (petición sin sesión, OPEN-AUTH-12).
            'actor_type' => ['sometimes', Rule::in(['user', 'system', 'console', 'import', 'platform', 'anonymous'])],
            'event' => ['sometimes', 'string'],
            'auditable_type' => ['sometimes', 'string'],
            'auditable_id' => ['sometimes', 'string', 'ulid'],
            'module' => ['sometimes', 'string'],
            'cursor' => ['sometimes', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
