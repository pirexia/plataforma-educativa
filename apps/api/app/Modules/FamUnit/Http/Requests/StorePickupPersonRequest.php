<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\Core\Domain\PersonRegistration;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-FAM-UNIT/api.md §8.2, `POST /pickup-persons`: `given_name` y `family_name_1` obligatorios; `family_name_2`,
 * `document_type`, `document_number` y `contact_phone` opcionales (el par de documento va completo o no va, y su catálogo,
 * formato y dígito de control los valida `PersonRegistrar`, `RN-CORE-90` a `-93`). Ni fecha de nacimiento, ni correo, ni
 * dirección (minimización, `OPEN-FAM-UNIT-27`).
 */
class StorePickupPersonRequest extends FamUnitRequest
{
    /** Como `people` en `Core` (`StoreUserRequest`): 255 caracteres de nombre y 32 de documento y teléfono. */
    private const NAME_MAX = 255;

    private const SHORT_MAX = 32;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    public function payload(): PersonRegistration
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $given = self::text($body, 'given_name', true, self::NAME_MAX, $errors);
        $family1 = self::text($body, 'family_name_1', true, self::NAME_MAX, $errors);
        $family2 = self::text($body, 'family_name_2', false, self::NAME_MAX, $errors);
        $type = self::text($body, 'document_type', false, self::SHORT_MAX, $errors);
        $number = self::text($body, 'document_number', false, self::SHORT_MAX, $errors);
        $phone = self::text($body, 'contact_phone', false, self::SHORT_MAX, $errors);

        $errors->throwIfAny();

        return new PersonRegistration((string) $given, (string) $family1, $family2, $type, $number, $phone);
    }

    /**
     * Cadena recortada de hasta `$max` caracteres; ausente, `null` o vacía ⇒ `null` (y `required` si es obligatoria).
     *
     * @param  array<string, mixed>  $body
     */
    public static function text(array $body, string $field, bool $required, int $max, ValidationErrorBag $errors): ?string
    {
        $value = $body[$field] ?? null;

        if ($value !== null && ! is_string($value)) {
            $errors->add($field, 'fam_unit.validation.text_invalid', 'fam_unit.validation.text_invalid');

            return null;
        }

        $value = $value === null ? null : trim($value);

        if ($value === null || $value === '') {
            if ($required) {
                $errors->add($field, 'fam_unit.validation.required', 'fam_unit.validation.required');
            }

            return null;
        }

        if (mb_strlen($value) > $max) {
            $errors->add($field, 'fam_unit.validation.text_too_long', 'fam_unit.validation.text_too_long', ['max' => $max]);

            return null;
        }

        return $value;
    }
}
