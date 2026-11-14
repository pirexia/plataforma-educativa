<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Domain\Models\GradingScale;
use App\Support\Api\Rules\InList;

/**
 * REQ-ACAD/api.md §11.5, `GET /grading-scales`: filtro `scale_type`
 * (múltiple, separado por comas; fuera de vocabulario ⇒ `422`), `sort` por
 * `code` (defecto) y paginación por página.
 */
class IndexGradingScalesRequest extends IndexStructureRequest
{
    public const SORTS = ['code', '-code'];

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'scale_type' => ['sometimes', 'string', new InList(GradingScale::TYPES)],
        ];
    }
}
