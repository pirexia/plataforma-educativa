<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\FamUnit\Http\Requests\Rules\ListOfUlids;

/**
 * REQ-FAM-UNIT/api.md §3, `GET /guardians`: filtros `family_unit` y `user` (varios `public_id` separados por comas);
 * orden `created_at`.
 */
class IndexGuardiansRequest extends IndexRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'family_unit' => ['sometimes', 'string', new ListOfUlids],
            'user' => ['sometimes', 'string', new ListOfUlids],
        ];
    }
}
