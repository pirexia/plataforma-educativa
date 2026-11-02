<?php

namespace App\Modules\Prl\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * REQ-PRL/api.md §8.4, `GET /door/students?q=`: `q` de 2 a 100 caracteres (`422` fuera de ese rango). Es una búsqueda de
 * alumnos por el comienzo de su nombre (`PersonDirectory::searchByNameAmong()`), no un directorio de personas.
 */
class SearchDoorStudentsRequest extends ApiFormRequest
{
    public const MIN = 2;

    public const MAX = 100;

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
            'q' => ['required', 'string', 'min:'.self::MIN, 'max:'.self::MAX],
        ];
    }

    public function term(): string
    {
        return trim((string) $this->query('q'));
    }
}
