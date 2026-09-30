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
     * Reglas de los filtros escalares, **compartidas** con
     * `POST /audit-logs/exports` (ADR-054 §8.2, issue #267): una exportación
     * acepta exactamente los filtros de su listado, con las mismas reglas.
     * `event` y `auditable_type` no están aquí porque cambian de forma (en la
     * query string, lista con coma; en el cuerpo JSON, array).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function filterRules(): array
    {
        return [
            // ADR-038 §5.2: rango con sufijo `_from`/`_to` (issue #266).
            'occurred_at_from' => ['sometimes', 'date'],
            'occurred_at_to' => ['sometimes', 'date'],
            'actor_id' => ['sometimes', 'string', 'ulid'],
            // ADR-039 §4.1: 'anonymous' se añade al vocabulario para
            // password_reset_requested (petición sin sesión, OPEN-AUTH-12).
            'actor_type' => ['sometimes', Rule::in(['user', 'system', 'console', 'import', 'platform', 'anonymous'])],
            'auditable_id' => ['sometimes', 'string', 'ulid'],
            'module' => ['sometimes', 'string'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...self::filterRules(),
            'event' => ['sometimes', 'string'],
            'auditable_type' => ['sometimes', 'string'],
            'cursor' => ['sometimes', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
