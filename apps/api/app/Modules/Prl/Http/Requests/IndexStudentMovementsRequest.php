<?php

namespace App\Modules\Prl\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Prl\Domain\Models\StudentMovement;
use App\Support\Api\Rules\QueryBoolean;
use Illuminate\Validation\Rule;

/**
 * REQ-PRL/api.md §8.4, §8.5, `GET /student-movements`: por cursor sobre `(occurred_at DESC, id DESC)` (`ADR-038 §4.4`), orden
 * fijo. Filtros `student_person` (un `public_id`), `date` (fecha civil del centro), `direction` y `off_schedule`; `limit` de 1
 * a 200 (defecto 50).
 */
class IndexStudentMovementsRequest extends ApiFormRequest
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
            'student_person' => ['sometimes', 'string', 'regex:/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/'],
            'date' => ['sometimes', 'string', 'date_format:Y-m-d'],
            'direction' => ['sometimes', 'string', Rule::in(StudentMovement::DIRECTIONS)],
            'off_schedule' => ['sometimes', new QueryBoolean],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @return array{student_person: ?string, date: ?string, direction: ?string, off_schedule: ?bool}
     */
    public function filters(): array
    {
        $string = fn (string $name): ?string => is_string($this->query($name)) ? $this->query($name) : null;
        $flag = $this->query('off_schedule');

        return [
            'student_person' => $string('student_person'),
            'date' => $string('date'),
            'direction' => $string('direction'),
            'off_schedule' => is_string($flag) ? $flag === 'true' : null,
        ];
    }

    public function cursorValue(): ?string
    {
        $cursor = $this->query('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
