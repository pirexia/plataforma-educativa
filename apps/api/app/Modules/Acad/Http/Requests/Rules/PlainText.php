<?php

namespace App\Modules\Acad\Http\Requests\Rules;

use App\Modules\Acad\Application\StructureFields;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `S-2`: un parámetro con NUL, controles o UTF-8 inválido no llega a la
 * consulta (PostgreSQL lo rechazaría con `22021`, un `500`): es `422`.
 */
final class PlainText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! StructureFields::isSafeText($value)) {
            $fail('validation.string')->translate();
        }
    }
}
