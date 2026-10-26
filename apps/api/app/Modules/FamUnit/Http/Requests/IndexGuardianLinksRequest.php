<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\FamUnit\Http\Requests\Rules\ListOfUlids;
use App\Support\Api\Rules\InList;
use App\Support\Api\Rules\QueryBoolean;

/**
 * REQ-FAM-UNIT/api.md §3, `GET /guardian-links`: filtros `family_unit`, `guardian`, `student_person` (varios
 * `public_id`), `status` (`revocado` exige `revocacion_tutor.leer`, lo comprueba el controlador),
 * `is_emergency_contact` e `is_legal_guardian`; orden `created_at`.
 */
class IndexGuardianLinksRequest extends IndexRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'family_unit' => ['sometimes', 'string', new ListOfUlids],
            'guardian' => ['sometimes', 'string', new ListOfUlids],
            'student_person' => ['sometimes', 'string', new ListOfUlids],
            'status' => ['sometimes', 'string', new InList(['activo', 'revocado'])],
            'is_emergency_contact' => ['sometimes', new QueryBoolean],
            'is_legal_guardian' => ['sometimes', new QueryBoolean],
        ];
    }
}
