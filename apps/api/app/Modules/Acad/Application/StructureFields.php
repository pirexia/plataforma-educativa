<?php

namespace App\Modules\Acad\Application;

use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/funcional.md §5.1-§5.2, api.md §6, `INV-010`: validación de
 * negocio de los campos comunes de la estructura. Cada método añade el error
 * al `ValidationErrorBag` (códigos de api.md §6) y devuelve el valor ya
 * normalizado, o `null` si no es válido.
 */
final class StructureFields
{
    /** `RN-ACAD-06`: claves de padre o curso académico que un `PATCH` nunca admite. */
    public const IMMUTABLE_KEYS = ['academic_year', 'education_level', 'course', 'group'];

    public const STAGES = ['infantil', 'primaria', 'eso', 'bachillerato', 'fp', 'universidad'];

    public const REGIMES = ['publico', 'privado', 'concertado'];

    /** `RN-ACAD-01`: obligatorio, recortado, no vacío. */
    public static function code(mixed $value, ValidationErrorBag $errors): ?string
    {
        $code = is_string($value) ? trim($value) : '';

        if ($code === '') {
            $errors->add('code', 'acad.validation.code_required', 'acad.validation.code_required');

            return null;
        }

        return $code;
    }

    /** `RN-ACAD-03`: nombre simple de grupo y subgrupo, obligatorio. */
    public static function name(mixed $value, ValidationErrorBag $errors): ?string
    {
        $name = is_string($value) ? trim($value) : '';

        if ($name === '') {
            $errors->add('name', 'acad.validation.name_required', 'acad.validation.name_required');

            return null;
        }

        return $name;
    }

    /** `RN-ACAD-04`: entero ≥ 1. */
    public static function position(mixed $value, ValidationErrorBag $errors): ?int
    {
        if (! is_int($value) || $value < 1 || $value > StructureLimits::MAX_POSITION) {
            $errors->add('position', 'acad.validation.position_invalid', 'acad.validation.position_invalid');

            return null;
        }

        return $value;
    }

    /** `RN-ACAD-10`. */
    public static function stage(mixed $value, ValidationErrorBag $errors): ?string
    {
        if (! is_string($value) || ! in_array($value, self::STAGES, true)) {
            $errors->add('stage', 'acad.validation.stage_invalid', 'acad.validation.stage_invalid');

            return null;
        }

        return $value;
    }

    /** `RN-ACAD-11`. */
    public static function regime(mixed $value, ValidationErrorBag $errors): ?string
    {
        if (! is_string($value) || ! in_array($value, self::REGIMES, true)) {
            $errors->add('regime', 'acad.validation.regime_invalid', 'acad.validation.regime_invalid');

            return null;
        }

        return $value;
    }

    /**
     * `RN-ACAD-06`: un `PATCH` con padre o curso académico, aunque sea el
     * mismo valor, es `422 parent_immutable` en ese campo.
     *
     * @param  array<string, mixed>  $body
     */
    public static function rejectImmutableKeys(array $body, ValidationErrorBag $errors): void
    {
        foreach (self::IMMUTABLE_KEYS as $key) {
            if (array_key_exists($key, $body)) {
                $errors->add($key, 'acad.validation.parent_immutable', 'acad.validation.parent_immutable');
            }
        }
    }

    /**
     * `RN-ACAD-05`: el alta de un hijo no admite `academic_year`.
     *
     * @param  array<string, mixed>  $body
     */
    public static function rejectAcademicYear(array $body, ValidationErrorBag $errors): void
    {
        if (array_key_exists('academic_year', $body)) {
            $errors->add('academic_year', 'acad.validation.academic_year_not_allowed', 'acad.validation.academic_year_not_allowed');
        }
    }

    /** `parent_not_found` para un padre del cuerpo ausente o que no es una cadena. */
    public static function parentReference(mixed $value, string $field, ValidationErrorBag $errors): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            $errors->add($field, 'acad.validation.parent_not_found', 'acad.validation.parent_not_found');

            return null;
        }

        return trim($value);
    }
}
