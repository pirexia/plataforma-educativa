<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Closure;
use Illuminate\Validation\Rule;

/**
 * api.md §5, `GET /account-lockouts`.
 */
class IndexAccountLockoutsRequest extends ApiFormRequest
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
            'status' => ['sometimes', 'string', $this->commaSeparatedIn(['vigente', 'levantado'])],
            'q' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', Rule::in(['locked_at', '-locked_at'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * ADR-038 §5.2: "varios valores separados por coma". `Rule::in()` no
     * cubre una lista separada por comas en un único parámetro.
     *
     * @param  list<string>  $allowed
     */
    private function commaSeparatedIn(array $allowed): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($allowed): void {
            foreach (explode(',', (string) $value) as $token) {
                if (! in_array($token, $allowed, true)) {
                    $fail('validation.in')->translate();
                }
            }
        };
    }
}
