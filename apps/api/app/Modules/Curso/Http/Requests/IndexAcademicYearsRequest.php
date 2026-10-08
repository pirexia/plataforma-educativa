<?php

namespace App\Modules\Curso\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Api\Rules\InList;
use Illuminate\Validation\Rule;

/**
 * REQ-CURSO/api.md §2, `GET /academic-years`. ADR-038 §5.2: filtro `status`
 * con valores múltiples separados por comas. Sin `q`.
 */
class IndexAcademicYearsRequest extends ApiFormRequest
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
            'status' => ['sometimes', 'string', new InList(array_map(static fn (AcademicYearStatus $s): string => $s->value, AcademicYearStatus::cases()))],
            'sort' => ['sometimes', Rule::in(['starts_on', '-starts_on', 'code', '-code'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
