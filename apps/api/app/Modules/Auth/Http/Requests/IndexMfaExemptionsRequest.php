<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Closure;

/**
 * api.md §D.4, `GET /api/v1/mfa-exemptions`. Filtros `state` (uno o
 * varios separados por coma, `ADR-038 §5.2`) y `user`.
 */
class IndexMfaExemptionsRequest extends ApiFormRequest
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
            'state' => ['sometimes', 'string', $this->commaSeparatedIn(['live', 'expired', 'revoked'])],
            'user' => ['sometimes', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * ADR-038 §5.2: "varios valores separados por coma". Mismo patrón que
     * `IndexAccountLockoutsRequest`.
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
