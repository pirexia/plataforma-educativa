<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Http\Requests\Rules\PlainText;

/** REQ-ACAD/api.md §3, `GET /courses`: filtro `education_level` (múltiple). */
class IndexCoursesRequest extends IndexStructureRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return ['education_level' => ['sometimes', 'string', new PlainText]];
    }
}
