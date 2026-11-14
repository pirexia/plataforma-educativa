<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\FamUnit\Domain\Models\PickupAuthorization;
use App\Modules\FamUnit\Http\Requests\Rules\ListOfUlids;
use App\Support\Api\Rules\InList;
use App\Support\Api\Rules\QueryBoolean;

/**
 * REQ-FAM-UNIT/api.md §8.3, `GET /pickup-authorizations`: filtros `student_person` y `person` (varios `public_id`), `kind` y
 * `effective` (vigente ahora); orden `valid_from` (por defecto, descendente) y `created_at`.
 */
class IndexPickupAuthorizationsRequest extends IndexRequest
{
    /** @var list<string> */
    public const SORTS = ['valid_from', '-valid_from', 'created_at', '-created_at'];

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'student_person' => ['sometimes', 'string', new ListOfUlids],
            'person' => ['sometimes', 'string', new ListOfUlids],
            'kind' => ['sometimes', 'string', new InList(PickupAuthorization::KINDS)],
            'effective' => ['sometimes', new QueryBoolean],
        ];
    }
}
