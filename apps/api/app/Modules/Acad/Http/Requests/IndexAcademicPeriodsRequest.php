<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\PeriodFields;
use App\Modules\Acad\Http\Requests\Rules\PlainText;
use App\Support\Api\Rules\InList;

/**
 * REQ-ACAD/api.md §11.5, `GET /academic-periods`: filtros `education_level` y
 * `period_type` (múltiples, separados por comas), `sort` por `starts_on`
 * (defecto) o `position`, paginación por página. Un `period_type` fuera de
 * vocabulario ⇒ `422`.
 */
class IndexAcademicPeriodsRequest extends IndexStructureRequest
{
    public const SORTS = ['starts_on', '-starts_on', 'position', '-position'];

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'education_level' => ['sometimes', 'string', new PlainText],
            'period_type' => ['sometimes', 'string', new InList(PeriodFields::TYPES)],
        ];
    }
}
