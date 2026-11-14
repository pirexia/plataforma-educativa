<?php

namespace App\Modules\Backoffice\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * `api.md §2.10.2`, `GET /tenants/{public_id}/failed-jobs`. Mismo patrón
 * que `IndexAuditLogsRequest` (`REQ-CORE`): un filtro de fecha mal
 * formado o un `limit` fuera de `1`-`200` es un `422` de forma, nunca un
 * `500` de `QueryException` (hallazgo de `/codex:review` sobre `1.6d`,
 * `funcional.md §5.9.2`, `api.md §2.10.2`).
 */
class IndexFailedJobsRequest extends ApiFormRequest
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
            'failed_at_from' => ['sometimes', 'date'],
            'failed_at_to' => ['sometimes', 'date'],
            'queue' => ['sometimes', 'string'],
            'job_class' => ['sometimes', 'string'],
            'cursor' => ['sometimes', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
