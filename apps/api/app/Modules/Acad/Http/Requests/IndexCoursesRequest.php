<?php

namespace App\Modules\Acad\Http\Requests;

/** REQ-ACAD/api.md §3, `GET /courses`: filtro `education_level` (múltiple). */
class IndexCoursesRequest extends IndexStructureRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return ['education_level' => ['sometimes', 'string']];
    }
}
