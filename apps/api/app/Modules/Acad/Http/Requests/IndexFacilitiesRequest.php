<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\FacilityService;
use App\Support\Api\Rules\InList;

/**
 * REQ-ACAD/api.md §12.4, `GET /facilities`: filtro `facility_type` (múltiple),
 * orden `code`/`-code`. Los espacios son del centro: `academic_year` no se usa
 * (un parámetro desconocido se ignora, `ADR-038 §5.2`).
 */
class IndexFacilitiesRequest extends IndexStructureRequest
{
    public const SORTS = ['code', '-code'];

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'facility_type' => ['sometimes', 'string', new InList(FacilityService::TYPES)],
        ];
    }
}
