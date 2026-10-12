<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\StructureFields;
use App\Support\Api\Rules\InList;

/** REQ-ACAD/api.md §3, `GET /education-levels`: filtros `stage` y `regime` (múltiples). */
class IndexEducationLevelsRequest extends IndexStructureRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'stage' => ['sometimes', 'string', new InList(StructureFields::STAGES)],
            'regime' => ['sometimes', 'string', new InList(StructureFields::REGIMES)],
        ];
    }
}
