<?php

namespace App\Support\Api\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ADR-038 §5.2: "Booleanos: true/false. No se aceptan 1, 0, si, on." La
 * regla nativa `boolean` de Laravel acepta justo lo contrario (1, 0,
 * "1", "0", true, false) y **no** la palabra "true"/"false" en un
 * parámetro de consulta (que llega siempre como cadena) — usar `boolean`
 * ahí produce un 422 en el caso que la convención exige aceptar.
 */
final class QueryBoolean implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! in_array($value, ['true', 'false'], true)) {
            $fail('core.validation.query_boolean_invalid')->translate();
        }
    }
}
