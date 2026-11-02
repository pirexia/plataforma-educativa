<?php

namespace App\Modules\Core\Domain;

use InvalidArgumentException;

/**
 * ADR-068 §5.2, `OPEN-068-07`: los campos que `PersonRegistrar::correctUnaccounted()` puede corregir de una persona sin
 * cuenta: `given_name`, `family_name_1`, `family_name_2` y `contact_phone`, **al menos uno**. Nunca el tipo ni el número
 * del documento: son la clave de reutilización (`RN-FAM-UNIT-89`). Solo cuentan las claves presentes: `family_name_2` y
 * `contact_phone` admiten `null` (borrar el dato); `given_name` y `family_name_1` no.
 */
final class PersonCorrection
{
    public const FIELDS = ['given_name', 'family_name_1', 'family_name_2', 'contact_phone'];

    /**
     * @param  array<string, ?string>  $changes  columna ⇒ valor nuevo, solo las claves a corregir
     */
    public function __construct(public readonly array $changes)
    {
        if ($changes === [] || array_diff(array_keys($changes), self::FIELDS) !== []) {
            throw new InvalidArgumentException('PersonCorrection exige al menos un campo y solo admite '.implode(', ', self::FIELDS).'.');
        }

        foreach (['given_name', 'family_name_1'] as $required) {
            if (array_key_exists($required, $changes) && (! is_string($changes[$required]) || trim($changes[$required]) === '')) {
                throw new InvalidArgumentException("{$required} no puede quedar vacío.");
            }
        }
    }
}
