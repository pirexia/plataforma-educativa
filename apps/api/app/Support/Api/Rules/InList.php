<?php

namespace App\Support\Api\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ADR-038 §5.2: un enumerado admite varios valores separados por comas
 * (`?status=vigente,caducada`). La regla nativa `in` solo valida un valor,
 * así que esta valida cada elemento de la lista contra el vocabulario
 * cerrado (REQ-CORE 1.9b, S7). El primer valor fuera del vocabulario
 * produce el `422`.
 */
final class InList implements ValidationRule
{
    /**
     * @param  list<string>  $allowed
     */
    public function __construct(private readonly array $allowed) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('core.validation.filter_value_invalid')->translate(['value' => '']);

            return;
        }

        foreach (explode(',', $value) as $item) {
            if (! in_array($item, $this->allowed, true)) {
                $fail('core.validation.filter_value_invalid')->translate(['value' => $item]);

                return;
            }
        }
    }
}
