<?php

namespace App\Modules\Alum\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Support\Api\Rules\QueryBoolean;
use Illuminate\Validation\Rule;

/**
 * REQ-ALUM/api.md §1 y §5, `GET /students` (1.15-a): paginación por página (`per_page` defecto 25, máximo 100 ⇒ `422`
 * si más), `sort` por lista blanca con desempate por `id` (`ADR-038 §5.3`), `q` (mínimo 2 caracteres) e
 * `include_deleted` (exige `alumno.eliminar`, lo comprueba el controlador). Con `academic_year`, `course`, `group` y
 * `enrollment_status` desde `1.15-b`. Un parámetro desconocido se ignora (`ADR-038 §5.2`).
 */
class IndexStudentsRequest extends ApiFormRequest
{
    public const SORTS = ['name', '-name', 'created_at', '-created_at'];

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
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'string', 'min:2', 'max:100'],
            'include_deleted' => ['sometimes', new QueryBoolean],
            'academic_year' => ['sometimes', 'string', 'max:64'],
            'course' => ['sometimes', 'string', 'max:64'],
            'group' => ['sometimes', 'string', 'max:64'],
            'enrollment_status' => ['sometimes', Rule::in(['activa', 'baja'])],
        ];
    }

    public function sortParam(): string
    {
        $sort = $this->query('sort');

        return is_string($sort) ? $sort : 'name';
    }

    public function search(): ?string
    {
        $q = $this->query('q');

        return is_string($q) ? trim($q) : null;
    }

    /**
     * Filtros de matrícula (desde `1.15-b`): los resuelve `EnrollmentService::studentIdsMatching()`.
     *
     * @return array{academic_year: ?string, course: ?string, group: ?string, enrollment_status: ?string}
     */
    public function enrollmentFilters(): array
    {
        $get = fn (string $key): ?string => is_string($this->query($key)) ? $this->query($key) : null;

        return [
            'academic_year' => $get('academic_year'),
            'course' => $get('course'),
            'group' => $get('group'),
            'enrollment_status' => $get('enrollment_status'),
        ];
    }

    public function includeDeleted(): bool
    {
        return $this->query('include_deleted') === 'true';
    }
}
