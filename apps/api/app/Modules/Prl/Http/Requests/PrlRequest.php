<?php

namespace App\Modules\Prl\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Prl\Application\IdempotencyKey;
use App\Modules\Prl\Application\PrlErrors;
use App\Support\Api\ValidationErrorBag;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Base de las peticiones de escritura de `prl`. La validación de negocio (`INV-010`) va en los servicios; aquí solo la
 * **forma** del cuerpo, que cada petición comprueba en `payload()` (el controlador la invoca nada más resolver el recurso de
 * la ruta, así un recurso inexistente es `404` y no `422`).
 */
abstract class PrlRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
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

    /**
     * La cabecera `Idempotency-Key` (`RN-PRL-09`), opcional: ausente ⇒ `null`; presente y no admisible ⇒ `422`.
     */
    public function idempotencyKey(): ?string
    {
        $header = $this->header('Idempotency-Key');

        if ($header === null) {
            return null;
        }

        if (! is_string($header) || ! IdempotencyKey::isValid($header)) {
            throw PrlErrors::invalid('idempotency_key', 'prl.validation.idempotency_key_invalid');
        }

        return $header;
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
        if (($body[$field] ?? null) === null || $body[$field] === '') {
            $errors->add($field, 'prl.validation.required', 'prl.validation.required');

            return null;
        }

        return self::optionalReference($body, $field, $errors);
    }

    /**
     * Referencia opcional (`null` o ausente ⇒ `null`).
     *
     * @param  array<string, mixed>  $body
     */
    protected static function optionalReference(array $body, string $field, ValidationErrorBag $errors): ?string
    {
        $value = $body[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (! self::isUlid($value)) {
            $errors->add($field, 'prl.validation.reference_invalid', 'prl.validation.reference_invalid');

            return null;
        }

        return $value;
    }

    /**
     * Enumerado cerrado: un valor fuera del vocabulario ⇒ `422`. Ausente o `null` ⇒ `null`.
     *
     * @param  array<string, mixed>  $body
     * @param  list<string>  $allowed
     */
    protected static function optionalEnum(array $body, string $field, array $allowed, ValidationErrorBag $errors): ?string
    {
        $value = $body[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            $errors->add($field, 'prl.validation.enum_invalid', 'prl.validation.enum_invalid');

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $allowed
     */
    protected static function requiredEnum(array $body, string $field, array $allowed, ValidationErrorBag $errors): ?string
    {
        if (($body[$field] ?? null) === null || $body[$field] === '') {
            $errors->add($field, 'prl.validation.required', 'prl.validation.required');

            return null;
        }

        return self::optionalEnum($body, $field, $allowed, $errors);
    }

    /**
     * Texto libre acotado, recortado; vacío ⇒ `null`.
     *
     * @param  array<string, mixed>  $body
     */
    protected static function optionalText(array $body, string $field, int $max, ValidationErrorBag $errors): ?string
    {
        $value = $body[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            $errors->add($field, 'prl.validation.text_invalid', 'prl.validation.text_invalid', ['max' => $max]);

            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Booleano JSON estricto (no se aceptan `1`, `"true"`...).
     *
     * @param  array<string, mixed>  $body
     */
    protected static function optionalBoolean(array $body, string $field, bool $default, ValidationErrorBag $errors): bool
    {
        if (! array_key_exists($field, $body)) {
            return $default;
        }

        if (! is_bool($body[$field])) {
            $errors->add($field, 'prl.validation.boolean_invalid', 'prl.validation.boolean_invalid');

            return $default;
        }

        return $body[$field];
    }

    /**
     * Instante ISO 8601 con zona.
     *
     * @param  array<string, mixed>  $body
     */
    protected static function optionalInstant(array $body, string $field, ValidationErrorBag $errors): ?CarbonImmutable
    {
        $value = $body[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})\z/', $value) !== 1) {
            $errors->add($field, 'prl.validation.datetime_invalid', 'prl.validation.datetime_invalid');

            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            $errors->add($field, 'prl.validation.datetime_invalid', 'prl.validation.datetime_invalid');

            return null;
        }
    }

    /** Fecha civil `Y-m-d` (calendario válido). */
    public static function isCivilDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
