<?php

namespace App\Modules\Acad\Application;

use App\Support\Api\ValidationErrorBag;
use Carbon\CarbonImmutable;

/**
 * REQ-ACAD/funcional.md §5.8 (`RN-ACAD-55`, `-56`), api.md §11.4, `INV-010`:
 * validación de negocio de los campos propios de un período lectivo. Cada
 * método añade el error al `ValidationErrorBag` (códigos de api.md §11.7) y
 * devuelve el valor normalizado, o `null` si no es válido.
 */
final class PeriodFields
{
    public const TYPES = ['trimestre', 'cuatrimestre', 'semestre'];

    /** `RN-ACAD-55`. */
    public static function periodType(mixed $value, ValidationErrorBag $errors): ?string
    {
        if (! is_string($value) || ! in_array($value, self::TYPES, true)) {
            $errors->add('period_type', 'acad.validation.period_type_invalid', 'acad.validation.period_type_invalid');

            return null;
        }

        return $value;
    }

    /**
     * `RN-ACAD-56`: fecha `AAAA-MM-DD` real (rechaza «2026-02-31» y «2026-13-01»,
     * que `createFromFormat` desbordaría en silencio).
     */
    public static function date(mixed $value, string $field, ValidationErrorBag $errors): ?string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            if ($parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $value) {
                return $value;
            }
        }

        $errors->add($field, 'acad.validation.date_invalid', 'acad.validation.date_invalid');

        return null;
    }

    /** `RN-ACAD-56`: `ends_on > starts_on` (fechas `AAAA-MM-DD`, comparables como cadena). */
    public static function order(string $startsOn, string $endsOn, ValidationErrorBag $errors): bool
    {
        if ($endsOn <= $startsOn) {
            $errors->add('ends_on', 'acad.validation.period_ends_before_start', 'acad.validation.period_ends_before_start');

            return false;
        }

        return true;
    }
}
