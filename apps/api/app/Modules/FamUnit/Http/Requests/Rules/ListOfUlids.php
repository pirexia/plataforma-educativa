<?php

namespace App\Modules\FamUnit\Http\Requests\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * REQ-FAM-UNIT/api.md §3: un filtro de referencia admite varios `public_id` ULID separados por comas; uno mal
 * formado es `422` (nunca llega a la consulta).
 */
final class ListOfUlids implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('fam_unit.validation.reference_invalid')->translate();

            return;
        }

        foreach (explode(',', $value) as $item) {
            if (preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}\z/', trim($item)) !== 1) {
                $fail('fam_unit.validation.reference_invalid')->translate();

                return;
            }
        }
    }
}
