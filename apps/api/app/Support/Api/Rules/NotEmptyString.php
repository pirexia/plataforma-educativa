<?php

namespace App\Support\Api\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ADR-038 §9.2 regla 3: en un `PATCH`, una cadena vacía `""` para un campo
 * anulable es 422 — para vaciar el campo se envía `null`, nunca `""`.
 * Aceptar ambos crearía dos representaciones del mismo estado.
 */
final class NotEmptyString implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === '') {
            $fail('core.validation.empty_string_not_allowed')->translate();
        }
    }
}
