<?php

namespace App\Modules\Acad\Application;

use App\Modules\Acad\Domain\Models\GradingScale;
use App\Modules\Acad\Domain\Models\GradingScaleValue;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use App\Support\I18n\TranslationsValidator;

/**
 * REQ-ACAD/funcional.md §4.9, §5.9-§5.10 (`RN-ACAD-70` a `-89`), `INV-010`:
 * validación de negocio de una escala y de sus valores sobre el **estado
 * resultante** (un alta, o el cuerpo de un `PATCH` aplicado sobre lo guardado).
 * Pura: no escribe ni consulta; la unicidad del código y el bloqueo los hace
 * `GradingScaleService`. Acumula todos los errores independientes y lanza un
 * único `422`.
 *
 * Campo (`errors.<campo>`) de cada error, que la especificación no fijó y fija
 * esta implementación (como `AcademicPeriodService::assertValidState`):
 *
 * - `scale_field_required`, `decimal_invalid`, `decimal_precision_exceeded`,
 *   `not_applicable_to_scale_type`: el campo afectado (`min_value`, `max_value`,
 *   `passing_min_value`, `decimals`; en un valor, `values.<n>.<campo>`).
 * - `decimals_invalid`: `decimals`. `range_invalid`: `max_value`.
 *   `passing_out_of_range`: `passing_min_value`.
 * - `band_range_invalid`: `values.<n>.range_min` si empieza antes de la escala,
 *   `values.<n>.range_max` si acaba después o si `range_min > range_max`.
 * - De la lista (`values`): `values_required`, `values_too_many`,
 *   `passing_value_required`, `bands_not_contiguous`,
 *   `passing_threshold_inside_band`, y `not_applicable_to_scale_type` por
 *   `values` en una numérica.
 * - De un elemento: `value_not_in_scale` en `values.<n>.public_id`;
 *   `code_taken`/`code_required`/`invalid_characters` en `values.<n>.code`;
 *   `is_passing_required` en `values.<n>.is_passing`; las traducciones, en
 *   `values.<n>.label_translations`.
 *
 * `<n>` es el índice de la lista **resultante** (base 0); si el `PATCH` no
 * envía `values`, el orden guardado.
 *
 * Los decimales se comparan como enteros en centésimas (`ScaleDecimal`),
 * nunca como `float`.
 */
final class GradingScaleValidator
{
    /** `RN-ACAD-89`. */
    public const MAX_VALUES = 100;

    private const NUMERIC_FIELDS = ['min_value', 'max_value', 'decimals', 'passing_min_value'];

    public function __construct(private readonly TranslationsValidator $translations) {}

    /**
     * @param  array<string, mixed>  $body  cuerpo en crudo del alta o del `PATCH`
     * @param  GradingScale|null  $stored  la escala guardada (`PATCH`) o `null` (alta)
     * @param  list<GradingScaleValue>  $storedValues  sus valores vivos, en orden de `position`
     *
     * @throws ApiException 422 si algo no es válido
     */
    public function resolve(string $type, array $body, ?GradingScale $stored, array $storedValues): ResolvedGradingScale
    {
        $errors = new ValidationErrorBag;

        $code = $this->scaleCode($body, $stored, $errors);
        $names = $this->scaleName($body, $stored, $errors);
        $numeric = $this->numericFields($type, $body, $stored, $errors);
        [$valuesSent, $values] = $this->values($type, $body, $stored, $storedValues, $numeric, $errors);

        $errors->throwIfAny();

        return new ResolvedGradingScale(
            code: (string) $code,
            nameTranslations: (array) $names,
            min: $numeric['min_value'],
            max: $numeric['max_value'],
            decimals: $numeric['decimals'],
            passing: $numeric['passing_min_value'],
            values: $values,
            valuesSent: $valuesSent,
        );
    }

    /**
     * `RN-ACAD-53`: obligatorio, recortado, no vacío, una sola línea y sin
     * caracteres de control. Devuelve el código, o `null` con el error añadido.
     */
    public static function codeOf(mixed $value, string $field, ValidationErrorBag $errors): ?string
    {
        if (is_string($value) && (! StructureFields::isSafeText($value) || preg_match('/[\r\n]/', $value) === 1)) {
            $errors->add($field, 'acad.validation.invalid_characters', 'acad.validation.invalid_characters');

            return null;
        }

        $code = is_string($value) ? trim($value) : '';

        if ($code === '') {
            $errors->add($field, 'acad.validation.code_required', 'acad.validation.code_required');

            return null;
        }

        return $code;
    }

    /** @param  array<string, mixed>  $body */
    private function scaleCode(array $body, ?GradingScale $stored, ValidationErrorBag $errors): ?string
    {
        if ($stored !== null && ! array_key_exists('code', $body)) {
            return $stored->code;
        }

        return self::codeOf($body['code'] ?? null, 'code', $errors);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, string>|null
     */
    private function scaleName(array $body, ?GradingScale $stored, ValidationErrorBag $errors): ?array
    {
        if ($stored === null) {
            return $this->translations->validateForCreate($body['name_translations'] ?? null, 'name_translations', $errors, StructureLimits::MAX_TEXT_LENGTH);
        }

        if (! array_key_exists('name_translations', $body)) {
            return $stored->name_translations;
        }

        return $this->translations->validateForPatch($body['name_translations'], $stored->name_translations, 'name_translations', $errors, StructureLimits::MAX_TEXT_LENGTH);
    }

    /**
     * `RN-ACAD-72` a `-74`. Los cuatro campos del estado resultante: `null`
     * donde no aplican (cualitativa) o donde el valor enviado es inválido (con
     * su error). Las reglas entre ellos se evalúan si todos son utilizables.
     *
     * @param  array<string, mixed>  $body
     * @return array{min_value: ?int, max_value: ?int, decimals: ?int, passing_min_value: ?int}
     */
    private function numericFields(string $type, array $body, ?GradingScale $stored, ValidationErrorBag $errors): array
    {
        $out = ['min_value' => null, 'max_value' => null, 'decimals' => null, 'passing_min_value' => null];

        foreach (self::NUMERIC_FIELDS as $field) {
            $given = $body[$field] ?? null;

            if ($type === GradingScale::TYPE_CUALITATIVA) {
                if ($given !== null) {
                    $errors->add($field, 'acad.validation.not_applicable_to_scale_type', 'acad.validation.not_applicable_to_scale_type');
                }

                continue;
            }

            if ($stored !== null && ! array_key_exists($field, $body)) {
                $out[$field] = $field === 'decimals' ? $stored->decimals : ScaleDecimal::fromStored($stored->{$field});

                continue;
            }

            if ($given === null) {
                $errors->add($field, 'acad.validation.scale_field_required', 'acad.validation.scale_field_required');

                continue;
            }

            if ($field === 'decimals') {
                if (is_int($given) && $given >= 0 && $given <= ScaleDecimal::MAX_DECIMALS) {
                    $out[$field] = $given;
                } else {
                    $errors->add($field, 'acad.validation.decimals_invalid', 'acad.validation.decimals_invalid');
                }

                continue;
            }

            $parsed = ScaleDecimal::parse($given);

            if ($parsed === null) {
                $errors->add($field, 'acad.validation.decimal_invalid', 'acad.validation.decimal_invalid');
            }

            $out[$field] = $parsed;
        }

        if ($type === GradingScale::TYPE_CUALITATIVA) {
            return $out;
        }

        ['min_value' => $min, 'max_value' => $max, 'decimals' => $decimals, 'passing_min_value' => $passing] = $out;

        $rangeOk = $min === null || $max === null || $min < $max;

        if (! $rangeOk) {
            $errors->add('max_value', 'acad.validation.range_invalid', 'acad.validation.range_invalid');
        }

        if ($rangeOk && $min !== null && $max !== null && $passing !== null && ($passing < $min || $passing > $max)) {
            $errors->add('passing_min_value', 'acad.validation.passing_out_of_range', 'acad.validation.passing_out_of_range');
        }

        if ($decimals !== null) {
            foreach (['min_value' => $min, 'max_value' => $max, 'passing_min_value' => $passing] as $field => $value) {
                if ($value !== null && ! ScaleDecimal::fits($value, $decimals)) {
                    $errors->add($field, 'acad.validation.decimal_precision_exceeded', 'acad.validation.decimal_precision_exceeded', ['decimals' => $decimals]);
                }
            }
        }

        return $out;
    }

    /**
     * `RN-ACAD-80` a `-89`: la lista resultante de valores o tramos, validada.
     *
     * @param  array<string, mixed>  $body
     * @param  list<GradingScaleValue>  $storedValues
     * @param  array{min_value: ?int, max_value: ?int, decimals: ?int, passing_min_value: ?int}  $numeric
     * @return array{0: bool, 1: list<ResolvedGradingValue>}
     */
    private function values(string $type, array $body, ?GradingScale $stored, array $storedValues, array $numeric, ValidationErrorBag $errors): array
    {
        $sent = ($body['values'] ?? null) !== null;

        if ($type === GradingScale::TYPE_NUMERICA) {
            // Una lista vacía no informa nada; cualquier otra cosa en `values` no aplica (RN-ACAD-81).
            if ($sent && $body['values'] !== []) {
                $errors->add('values', 'acad.validation.not_applicable_to_scale_type', 'acad.validation.not_applicable_to_scale_type');
            }

            return [false, []];
        }

        if (! $sent) {
            if ($stored === null) {
                $errors->add('values', 'acad.validation.values_required', 'acad.validation.values_required');

                return [false, []];
            }

            // `PATCH` sin `values`: el estado resultante conserva los guardados (RN-ACAD-88, última frase).
            $kept = array_map(static fn (GradingScaleValue $v): ResolvedGradingValue => new ResolvedGradingValue(
                $v, $v->code, $v->label_translations, $v->is_passing, ScaleDecimal::fromStored($v->range_min), ScaleDecimal::fromStored($v->range_max),
            ), $storedValues);

            $this->assertBands($type, $kept, $numeric, $errors);

            return [false, $kept];
        }

        $list = $body['values'];

        if (! is_array($list) || $list === [] || ! array_is_list($list)) {
            $errors->add('values', 'acad.validation.values_required', 'acad.validation.values_required');

            return [true, []];
        }

        if (count($list) > self::MAX_VALUES) {
            $errors->add('values', 'acad.validation.values_too_many', 'acad.validation.values_too_many', ['max' => self::MAX_VALUES]);

            return [true, []];
        }

        $byPublicId = [];

        foreach ($storedValues as $value) {
            $byPublicId[$value->public_id] = $value;
        }

        $resolved = [];
        $seenPublicIds = [];
        $seenCodes = [];
        $clean = true;

        foreach ($list as $n => $element) {
            $element = is_array($element) ? $element : [];
            $path = "values.{$n}";
            $existing = null;

            if (($element['public_id'] ?? null) !== null) {
                $publicId = $element['public_id'];

                // `RN-ACAD-88`: de otra escala, de otro centro, borrado, inexistente o repetido.
                if (! is_string($publicId) || ! isset($byPublicId[$publicId]) || isset($seenPublicIds[$publicId])) {
                    $errors->add("{$path}.public_id", 'acad.validation.value_not_in_scale', 'acad.validation.value_not_in_scale');
                    $clean = false;

                    continue;
                }

                $seenPublicIds[$publicId] = true;
                $existing = $byPublicId[$publicId];
            }

            $value = $this->element($type, $element, $existing, $path, $errors);

            if ($value === null) {
                $clean = false;

                continue;
            }

            if (isset($seenCodes[$value->code])) {
                $errors->add("{$path}.code", 'acad.validation.code_taken', 'acad.validation.code_taken');
                $clean = false;
            }

            $seenCodes[$value->code] = true;
            $resolved[] = $value;
        }

        if ($clean) {
            $this->assertBands($type, $resolved, $numeric, $errors);
        }

        return [true, $resolved];
    }

    /**
     * Un elemento de `values`: sus claves presentes sobre el valor guardado (si
     * es una actualización) o todas obligatorias (si es un alta). `null` si
     * algún campo es inválido (con su error).
     *
     * @param  array<array-key, mixed>  $element
     */
    private function element(string $type, array $element, ?GradingScaleValue $existing, string $path, ValidationErrorBag $errors): ?ResolvedGradingValue
    {
        $valid = true;

        if ($existing !== null && ! array_key_exists('code', $element)) {
            $code = $existing->code;
        } else {
            $code = self::codeOf($element['code'] ?? null, "{$path}.code", $errors);
        }

        if ($existing === null) {
            $labels = $this->translations->validateForCreate($element['label_translations'] ?? null, "{$path}.label_translations", $errors, StructureLimits::MAX_TEXT_LENGTH);
        } elseif (! array_key_exists('label_translations', $element)) {
            $labels = $existing->label_translations;
        } else {
            $labels = $this->translations->validateForPatch($element['label_translations'], $existing->label_translations, "{$path}.label_translations", $errors, StructureLimits::MAX_TEXT_LENGTH);
        }

        $isPassing = null;
        $rangeMin = null;
        $rangeMax = null;

        if ($type === GradingScale::TYPE_CUALITATIVA) {
            foreach (['range_min', 'range_max'] as $field) {
                if (($element[$field] ?? null) !== null) {
                    $errors->add("{$path}.{$field}", 'acad.validation.not_applicable_to_scale_type', 'acad.validation.not_applicable_to_scale_type');
                    $valid = false;
                }
            }

            if ($existing !== null && ! array_key_exists('is_passing', $element)) {
                $isPassing = $existing->is_passing;
            } elseif (is_bool($element['is_passing'] ?? null)) {
                $isPassing = $element['is_passing'];
            } else {
                $errors->add("{$path}.is_passing", 'acad.validation.is_passing_required', 'acad.validation.is_passing_required');
                $valid = false;
            }
        } else {
            if (($element['is_passing'] ?? null) !== null) {
                $errors->add("{$path}.is_passing", 'acad.validation.not_applicable_to_scale_type', 'acad.validation.not_applicable_to_scale_type');
                $valid = false;
            }

            $ranges = ['range_min' => null, 'range_max' => null];

            foreach (array_keys($ranges) as $field) {
                if ($existing !== null && ! array_key_exists($field, $element)) {
                    $ranges[$field] = ScaleDecimal::fromStored($existing->{$field});

                    continue;
                }

                if (($element[$field] ?? null) === null) {
                    $errors->add("{$path}.{$field}", 'acad.validation.scale_field_required', 'acad.validation.scale_field_required');
                    $valid = false;

                    continue;
                }

                $ranges[$field] = ScaleDecimal::parse($element[$field]);

                if ($ranges[$field] === null) {
                    $errors->add("{$path}.{$field}", 'acad.validation.decimal_invalid', 'acad.validation.decimal_invalid');
                    $valid = false;
                }
            }

            $rangeMin = $ranges['range_min'];
            $rangeMax = $ranges['range_max'];
        }

        if (! $valid || $code === null || $labels === null) {
            return null;
        }

        return new ResolvedGradingValue($existing, $code, $labels, $isPassing, $rangeMin, $rangeMax);
    }

    /**
     * Reglas entre valores, sobre la lista resultante y bajo el bloqueo de la
     * escala: cualitativa, al menos un aprobado (`RN-ACAD-82`); mixta, cada
     * tramo con la precisión de la escala y dentro de ella, tramos contiguos
     * que la cubren y umbral en una frontera (`RN-ACAD-84` a `-86`). Las de la
     * mixta se omiten si los cuatro campos numéricos no son utilizables (ya
     * tienen su error).
     *
     * @param  list<ResolvedGradingValue>  $values
     * @param  array{min_value: ?int, max_value: ?int, decimals: ?int, passing_min_value: ?int}  $numeric
     */
    private function assertBands(string $type, array $values, array $numeric, ValidationErrorBag $errors): void
    {
        if ($type === GradingScale::TYPE_CUALITATIVA) {
            if ($values !== [] && array_filter($values, static fn (ResolvedGradingValue $v): bool => $v->isPassing === true) === []) {
                $errors->add('values', 'acad.validation.passing_value_required', 'acad.validation.passing_value_required');
            }

            return;
        }

        ['min_value' => $min, 'max_value' => $max, 'decimals' => $decimals, 'passing_min_value' => $passing] = $numeric;

        if ($min === null || $max === null || $decimals === null || $passing === null || $min >= $max || $passing < $min || $passing > $max) {
            return;
        }

        $step = ScaleDecimal::step($decimals);
        $bandsOk = true;

        foreach ($values as $n => $band) {
            $rangeMin = (int) $band->rangeMin;
            $rangeMax = (int) $band->rangeMax;

            foreach (['range_min' => $rangeMin, 'range_max' => $rangeMax] as $field => $limit) {
                if (! ScaleDecimal::fits($limit, $decimals)) {
                    $errors->add("values.{$n}.{$field}", 'acad.validation.decimal_precision_exceeded', 'acad.validation.decimal_precision_exceeded', ['decimals' => $decimals]);
                    $bandsOk = false;
                }
            }

            if ($rangeMin < $min) {
                $errors->add("values.{$n}.range_min", 'acad.validation.band_range_invalid', 'acad.validation.band_range_invalid');
                $bandsOk = false;
            }

            if ($rangeMax > $max || $rangeMin > $rangeMax) {
                $errors->add("values.{$n}.range_max", 'acad.validation.band_range_invalid', 'acad.validation.band_range_invalid');
                $bandsOk = false;
            }
        }

        if (! $bandsOk || $values === []) {
            return;
        }

        // RN-ACAD-85: en el orden de la lista, el primero empieza en el mínimo, cada uno un paso
        // después del fin del anterior y el último termina en el máximo.
        $expected = $min;

        foreach ($values as $band) {
            if ($band->rangeMin !== $expected) {
                $errors->add('values', 'acad.validation.bands_not_contiguous', 'acad.validation.bands_not_contiguous');

                return;
            }

            $expected = (int) $band->rangeMax + $step;
        }

        if ($expected !== $max + $step) {
            $errors->add('values', 'acad.validation.bands_not_contiguous', 'acad.validation.bands_not_contiguous');

            return;
        }

        // RN-ACAD-86: el umbral cae en una frontera.
        if (array_filter($values, static fn (ResolvedGradingValue $v): bool => $v->rangeMin === $passing) === []) {
            $errors->add('values', 'acad.validation.passing_threshold_inside_band', 'acad.validation.passing_threshold_inside_band');
        }
    }
}
