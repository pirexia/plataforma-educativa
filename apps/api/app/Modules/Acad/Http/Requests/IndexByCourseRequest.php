<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Http\Requests\Rules\PlainText;

/**
 * REQ-ACAD/api.md §3, `GET /groups` y `GET /subjects`: filtros `course` y
 * `education_level` (múltiples).
 */
class IndexByCourseRequest extends IndexStructureRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'course' => ['sometimes', 'string', new PlainText],
            'education_level' => ['sometimes', 'string', new PlainText],
        ];
    }
}
