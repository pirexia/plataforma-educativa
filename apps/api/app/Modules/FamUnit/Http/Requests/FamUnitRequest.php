<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\FamUnit\Application\FamUnitErrors;
use App\Support\Api\ValidationErrorBag;

/**
 * Base de las peticiones de escritura de `fam_unit`. La validación de negocio (`INV-010`) va en `payload()`, que el
 * controlador invoca nada más resolver el recurso de la ruta (así una unidad inexistente es `404` y no `422`).
 */
abstract class FamUnitRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Cuerpo JSON en crudo: `ConvertEmptyStringsToNull` y `TrimStrings` alterarían lo que el cliente envía y no
     * distinguirían `""` de un campo ausente.
     *
     * @return array<string, mixed>
     */
    protected function body(): array
    {
        $decoded = json_decode($this->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Un `public_id` ULID (Crockford, 26 caracteres). */
    protected static function isUlid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}\z/', $value) === 1;
    }

    /**
     * Referencia obligatoria a un recurso por su `public_id`.
     *
     * @param  array<string, mixed>  $body
     */
    protected static function requiredReference(array $body, string $field, ValidationErrorBag $errors): ?string
    {
        $value = $body[$field] ?? null;

        if ($value === null || $value === '') {
            $errors->add($field, 'fam_unit.validation.required', 'fam_unit.validation.required');

            return null;
        }

        if (! self::isUlid($value)) {
            $errors->add($field, 'fam_unit.validation.reference_invalid', 'fam_unit.validation.reference_invalid');

            return null;
        }

        return $value;
    }

    /**
     * Booleano JSON estricto (no se aceptan `1`, `"true"`...).
     *
     * @param  array<string, mixed>  $body
     */
    protected static function optionalBoolean(array $body, string $field, ValidationErrorBag $errors): ?bool
    {
        if (! array_key_exists($field, $body)) {
            return null;
        }

        if (! is_bool($body[$field])) {
            $errors->add($field, 'fam_unit.validation.boolean_invalid', 'fam_unit.validation.boolean_invalid');

            return null;
        }

        return $body[$field];
    }

    /**
     * Enumerado cerrado (`RN-FAM-UNIT-32`): un valor fuera del vocabulario ⇒ `422`.
     *
     * @param  array<string, mixed>  $body
     * @param  list<string>  $allowed
     */
    protected static function optionalEnum(array $body, string $field, array $allowed, string $errorKey, ValidationErrorBag $errors): ?string
    {
        if (! array_key_exists($field, $body)) {
            return null;
        }

        if (! is_string($body[$field]) || ! in_array($body[$field], $allowed, true)) {
            $errors->add($field, $errorKey, $errorKey);

            return null;
        }

        return $body[$field];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $allowed
     * @return list<string>|null
     */
    protected static function optionalCategories(array $body, string $field, array $allowed, ValidationErrorBag $errors): ?array
    {
        if (! array_key_exists($field, $body)) {
            return null;
        }

        $value = $body[$field];
        $key = 'fam_unit.validation.restricted_category_invalid';

        if (! is_array($value) || ! array_is_list($value)) {
            $errors->add($field, $key, $key);

            return null;
        }

        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                $errors->add($field, $key, $key);

                return null;
            }
        }

        return array_values(array_unique($value));
    }

    /**
     * Acumula un error `$code` por cada uno de esos campos que el cuerpo traiga (`not_editable`).
     *
     * @param  array<string, mixed>  $body
     * @param  list<string>  $fields
     */
    protected function rejectFields(array $body, array $fields, string $code, ValidationErrorBag $errors): void
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $body)) {
                $errors->add($field, $code, $code);
            }
        }
    }

    protected static function invalid(string $field, string $code): never
    {
        throw FamUnitErrors::invalid($field, $code);
    }
}
