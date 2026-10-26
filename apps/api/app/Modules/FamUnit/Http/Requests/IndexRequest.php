<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-FAM-UNIT/api.md §3: paginación por página (`per_page` defecto 25, máximo 100 ⇒ `422` si más) y `sort` por lista
 * blanca con desempate por `id` (`ADR-038 §5.3`). Un parámetro desconocido se ignora (`ADR-038 §5.2`). Los filtros
 * de referencia aceptan varios `public_id` separados por comas.
 */
abstract class IndexRequest extends ApiFormRequest
{
    /** @var list<string> */
    public const SORTS = ['created_at', '-created_at'];

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
            'sort' => ['sometimes', Rule::in(static::SORTS)],
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

    /** Booleano de consulta (`true`/`false`); `null` si no viene. */
    public function flag(string $name): ?bool
    {
        $raw = $this->query($name);

        return is_string($raw) ? $raw === 'true' : null;
    }

    public function sortParam(string $default): string
    {
        $sort = $this->query('sort');

        return is_string($sort) ? $sort : $default;
    }
}
