<?php

namespace App\Modules\Curso\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Curso\Domain\YearRolloverStatus;
use App\Support\Api\Rules\InList;
use Illuminate\Validation\Rule;

/**
 * REQ-CURSO/api.md §2.1, `GET /academic-year-rollovers` (1.12b): `academic_year` (destino; omitido ⇒ curso
 * activo), `source`, `status` (múltiple, `ADR-038 §5.2`), `sort` ∈ {`created_at`, `-created_at`}, paginación.
 */
class IndexRolloversRequest extends ApiFormRequest
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
            'academic_year' => ['sometimes', 'string', 'max:64'],
            'source' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', 'string', new InList(array_map(static fn (YearRolloverStatus $s): string => $s->value, YearRolloverStatus::cases()))],
            'sort' => ['sometimes', Rule::in(['created_at', '-created_at'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
