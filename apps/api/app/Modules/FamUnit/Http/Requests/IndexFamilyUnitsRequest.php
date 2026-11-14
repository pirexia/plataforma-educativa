<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\FamUnit\Http\Requests\Rules\PlainText;
use App\Support\Api\Rules\QueryBoolean;

/**
 * REQ-FAM-UNIT/api.md §3, `GET /family-units`: filtros `q` (prefijo de código), `has_principal` e `include_deleted`
 * (este último solo con `familia.eliminar`, lo comprueba el controlador); orden `code` (defecto) o `created_at`.
 */
class IndexFamilyUnitsRequest extends IndexRequest
{
    public const SORTS = ['code', '-code', 'created_at', '-created_at'];

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:40', new PlainText],
            'has_principal' => ['sometimes', new QueryBoolean],
            'include_deleted' => ['sometimes', new QueryBoolean],
        ];
    }
}
