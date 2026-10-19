<?php

namespace App\Modules\Acad\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Acad\Http\Requests\Rules\PlainText;

/**
 * REQ-ACAD/api.md §12.4, `GET /timetable-sessions`: sin paginar, orden fijo
 * (`weekday`, `starts_at`, `id`). Filtros `academic_year`, opcional `frame` y
 * al menos uno de `teacher`, `facility`, `group`, `subject` (`RN-ACAD-127`),
 * cuya ausencia es `422 view_filter_required` y la comprueba el controlador.
 */
class IndexTimetableSessionsRequest extends ApiFormRequest
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
        $filter = ['sometimes', 'string', new PlainText];

        return [
            'academic_year' => $filter,
            'frame' => $filter,
            'teacher' => $filter,
            'facility' => $filter,
            'group' => $filter,
            'subject' => $filter,
        ];
    }
}
