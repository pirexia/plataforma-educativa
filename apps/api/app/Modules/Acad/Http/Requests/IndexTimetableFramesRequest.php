<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\TimetableFrameService;
use App\Modules\Acad\Http\Requests\Rules\PlainText;
use App\Support\Api\Rules\InList;

/**
 * REQ-ACAD/api.md §12.4, `GET /timetable-frames`: filtros `education_level`
 * (múltiple) y `frame_type`; orden por etapa (`education_level`/`-education_level`),
 * luego `frame_type` e `id`.
 */
class IndexTimetableFramesRequest extends IndexStructureRequest
{
    public const SORTS = ['education_level', '-education_level'];

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'education_level' => ['sometimes', 'string', new PlainText],
            'frame_type' => ['sometimes', 'string', new InList(TimetableFrameService::TYPES)],
        ];
    }
}
