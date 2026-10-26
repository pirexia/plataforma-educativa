<?php

namespace App\Modules\FamUnit\Http\Requests\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Un parámetro con NUL, caracteres de control o UTF-8 inválido no llega a la consulta (PostgreSQL lo rechazaría con
 * `22021`, un `500`): es `422`.
 */
final class PlainText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $value) === 1)) {
            $fail('validation.string')->translate();
        }
    }
}
