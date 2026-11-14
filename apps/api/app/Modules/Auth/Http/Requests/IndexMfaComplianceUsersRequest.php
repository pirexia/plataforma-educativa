<?php

namespace App\Modules\Auth\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Closure;

/**
 * api.md §C.5, `GET /mfa-compliance/users`. Restaurado en 1.3 el
 * 2026-08-27 (decisión del usuario, corrige un recorte no autorizado de
 * un subagente anterior — `funcional.md §C.16`).
 */
class IndexMfaComplianceUsersRequest extends ApiFormRequest
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
            // `obligated` es un alias de conveniencia (pending+past_deadline)
            // que el controlador expande antes de llamar al directorio —
            // ver `MfaComplianceUserRow`.
            'state' => ['sometimes', 'string', $this->commaSeparatedIn([
                'obligated', 'enrolled', 'pending', 'past_deadline', 'exempt',
            ])],
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
