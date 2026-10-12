<?php

namespace App\Modules\Acad\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Acad\Http\Requests\Rules\PlainText;
use Illuminate\Validation\Rule;

/**
 * REQ-ACAD/api.md §3: paginación por página (`per_page` defecto 25, máximo
 * 100 ⇒ `422` si más), `sort` por lista blanca con desempate por `id`
 * (`ADR-038 §5.3`). Un parámetro desconocido se ignora (`ADR-038 §5.2`).
 * Los filtros por padre aceptan varios `public_id` separados por comas.
 */
abstract class IndexStructureRequest extends ApiFormRequest
{
    public const SORTS = ['position', '-position', 'code', '-code'];

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
            'academic_year' => ['sometimes', 'string', new PlainText],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ] + $this->filterRules();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [];
    }

    /**
     * Valores separados por comas de un filtro, sin vacíos.
     *
     * @return list<string>
     */
    public function list(string $name): array
    {
        $raw = $this->query($name);

        return is_string($raw)
            ? array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''))
            : [];
    }
}
