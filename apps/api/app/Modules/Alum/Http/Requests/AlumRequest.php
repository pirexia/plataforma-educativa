<?php

namespace App\Modules\Alum\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Support\Api\ValidationErrorBag;
use Carbon\CarbonImmutable;

/**
 * Base de las peticiones de escritura de `alum`. La validación de negocio (`INV-010`) va en `payload()`, que el
 * controlador invoca nada más resolver el recurso de la ruta (así un alumno inexistente es `404` y no `422`).
 */
abstract class AlumRequest extends ApiFormRequest
{
    private const TEXT_MAX = 255;

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

    /** Texto de 1 a 255 caracteres sin caracteres de control, recortado; `null` y error si no lo es. */
    protected static function text(mixed $value, string $field, ValidationErrorBag $errors): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed !== ''
                && mb_check_encoding($trimmed, 'UTF-8')
                && mb_strlen($trimmed) <= self::TEXT_MAX
                && preg_match('/[\x00-\x1F\x7F]/', $trimmed) !== 1) {
                return $trimmed;
            }
        }

        $errors->add($field, 'alum.validation.text_invalid', 'alum.validation.text_invalid');

        return null;
    }

    /** `RN-ALUM-08`: `AAAA-MM-DD`, fecha real y no futura. */
    protected static function birthDate(mixed $value, ValidationErrorBag $errors): ?string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value) === 1) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if ($parsed !== false && $parsed->format('Y-m-d') === $value && $value <= CarbonImmutable::today()->toDateString()) {
                return $value;
            }
        }

        $errors->add('birth_date', 'alum.validation.birth_date_invalid', 'alum.validation.birth_date_invalid');

        return null;
    }

    protected static function required(string $field, ValidationErrorBag $errors): void
    {
        $errors->add($field, 'alum.validation.required', 'alum.validation.required');
    }
}
