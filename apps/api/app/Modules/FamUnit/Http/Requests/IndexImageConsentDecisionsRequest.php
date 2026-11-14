<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\FamUnit\Domain\Models\ImageConsentDecision;
use App\Modules\FamUnit\Http\Requests\Rules\ListOfUlids;
use App\Support\Api\Rules\InList;

/**
 * REQ-FAM-UNIT/api.md §3, `GET /image-consent-decisions`: histórico de un alumno (`student_person`, obligatorio) por
 * cursor sobre `(occurred_at DESC, id DESC)` (`ADR-038 §4.4`); filtro opcional `level`; `limit` de 1 a 200 (defecto 50).
 * Sin orden: es fijo.
 */
class IndexImageConsentDecisionsRequest extends ApiFormRequest
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
        return [
            'student_person' => ['required', 'string', new ListOfUlids],
            'level' => ['sometimes', 'string', new InList(ImageConsentDecision::LEVELS)],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function studentPerson(): string
    {
        return trim((string) $this->query('student_person'));
    }

    public function level(): ?string
    {
        $level = $this->query('level');

        return is_string($level) ? $level : null;
    }

    public function cursorValue(): ?string
    {
        $cursor = $this->query('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
