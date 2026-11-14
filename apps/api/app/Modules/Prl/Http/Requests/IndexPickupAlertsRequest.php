<?php

namespace App\Modules\Prl\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Prl\Domain\Models\PickupAlert;
use Illuminate\Validation\Rule;

/**
 * REQ-PRL/api.md §8.4, §8.5, `GET /pickup-alerts`: por página, `raised_at` descendente (orden fijo). Filtros `status`,
 * `date` (fecha civil del centro en la que se abrió) y `student_person` (un `public_id`).
 */
class IndexPickupAlertsRequest extends ApiFormRequest
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
            'status' => ['sometimes', 'string', Rule::in(PickupAlert::STATUSES)],
            'date' => ['sometimes', 'string', 'date_format:Y-m-d'],
            'student_person' => ['sometimes', 'string', 'regex:/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{status: ?string, date: ?string, student_person: ?string}
     */
    public function filters(): array
    {
        $string = fn (string $name): ?string => is_string($this->query($name)) ? $this->query($name) : null;

        return ['status' => $string('status'), 'date' => $string('date'), 'student_person' => $string('student_person')];
    }
}
