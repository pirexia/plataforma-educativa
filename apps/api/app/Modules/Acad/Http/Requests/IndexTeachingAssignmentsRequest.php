<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Http\Requests\Rules\PlainText;

/**
 * REQ-ACAD/api.md §12.4, `GET /teaching-assignments`: filtros `academic_year`,
 * `group`, `subject` y `teacher` (múltiples, `public_id` separados por comas);
 * orden `id`/`-id` (orden de alta).
 */
class IndexTeachingAssignmentsRequest extends IndexStructureRequest
{
    public const SORTS = ['id', '-id'];

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'group' => ['sometimes', 'string', new PlainText],
            'subject' => ['sometimes', 'string', new PlainText],
            'teacher' => ['sometimes', 'string', new PlainText],
        ];
    }
}
