<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\FamUnit\Application\ImageConsentService;
use App\Modules\FamUnit\Domain\Models\ImageConsentDecision;
use App\Modules\FamUnit\Http\Requests\Rules\ListOfUlids;
use App\Support\Api\Rules\InList;

/**
 * REQ-FAM-UNIT/api.md §3, `GET /image-consents`: filtros `family_unit`, `student_person` (varios `public_id`), `level` y
 * `state`; orden por `last_decision_at` (defecto) o `state`. Un alumno o una unidad que el sujeto no alcanza no es error:
 * el ámbito se aplica antes que el filtro y devuelve vacío.
 */
class IndexImageConsentsRequest extends IndexRequest
{
    /** @var list<string> */
    public const SORTS = ['last_decision_at', '-last_decision_at', 'state', '-state'];

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'family_unit' => ['sometimes', 'string', new ListOfUlids],
            'student_person' => ['sometimes', 'string', new ListOfUlids],
            'level' => ['sometimes', 'string', new InList(ImageConsentDecision::LEVELS)],
            'state' => ['sometimes', 'string', new InList(ImageConsentService::STATES)],
        ];
    }

    /**
     * @return array{family_unit: list<string>, student_person: list<string>, level: list<string>, state: list<string>}
     */
    public function filters(): array
    {
        return [
            'family_unit' => $this->list('family_unit'),
            'student_person' => $this->list('student_person'),
            'level' => $this->list('level'),
            'state' => $this->list('state'),
        ];
    }
}
