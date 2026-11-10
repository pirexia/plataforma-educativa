<?php

namespace App\Modules\Alum\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-ALUM/api.md §2, `GET /enrollments` (1.15-b): `academic_year` (obligatorio; lo exige y valida el servicio, que
 * responde `422 required` o `404`), `course`, `group`, `student` (`public_id`), `status` (`activa`, `baja`), `sort`
 * (`name` defecto o `enrolled_on`, con prefijo `-`) y paginación por página (`per_page` defecto 25, máximo 100). Un
 * parámetro desconocido se ignora (`ADR-038 §5.2`).
 */
class IndexEnrollmentsRequest extends ApiFormRequest
{
    public const SORTS = ['name', '-name', 'enrolled_on', '-enrolled_on'];

    public const STATUSES = ['activa', 'baja'];

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
            'course' => ['sometimes', 'string', 'max:64'],
            'group' => ['sometimes', 'string', 'max:64'],
            'student' => ['sometimes', 'string', 'max:64'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function sortParam(): string
    {
        $sort = $this->query('sort');

        return is_string($sort) ? $sort : 'name';
    }

    /**
     * @return array{academic_year: ?string, course: ?string, group: ?string, student: ?string, status: ?string}
     */
    public function filters(): array
    {
        $get = fn (string $key): ?string => is_string($this->query($key)) ? $this->query($key) : null;

        return [
            'academic_year' => $get('academic_year'),
            'course' => $get('course'),
            'group' => $get('group'),
            'student' => $get('student'),
            'status' => $get('status'),
        ];
    }
}
