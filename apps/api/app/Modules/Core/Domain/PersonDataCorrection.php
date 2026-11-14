<?php

namespace App\Modules\Core\Domain;

use InvalidArgumentException;

/**
 * REQ-ALUM/funcional.md `RN-ALUM-10` (`OPEN-ALUM-19` = A, ampliación aditiva de `Core\Domain`, 1.15-a): los campos que
 * `PersonRegistrar::correctPerson()` puede corregir de una persona **con o sin usuario**: nombre, apellidos, tipo y número
 * de documento, fecha de nacimiento (`AAAA-MM-DD`) e idioma, **al menos uno**. Nunca credenciales, correo de acceso ni el
 * estado del usuario, ni el teléfono ni el correo de contacto (siguen siendo de `Core`). Solo cuentan las claves presentes:
 * `family_name_2`, `document_type`, `document_number`, `birth_date` y `locale` admiten `null` (borrar el dato; el par de
 * documento resultante debe seguir completo); `given_name` y `family_name_1` no. Que la fecha no sea futura es regla de
 * negocio del módulo que corrige (`RN-ALUM-08`), no de este valor.
 */
final class PersonDataCorrection
{
    public const FIELDS = ['given_name', 'family_name_1', 'family_name_2', 'document_type', 'document_number', 'birth_date', 'locale'];

    /**
     * @param  array<string, ?string>  $changes  columna ⇒ valor nuevo, solo las claves a corregir
     */
    public function __construct(public readonly array $changes)
    {
        if ($changes === [] || array_diff(array_keys($changes), self::FIELDS) !== []) {
            throw new InvalidArgumentException('PersonDataCorrection exige al menos un campo y solo admite '.implode(', ', self::FIELDS).'.');
        }

        foreach (['given_name', 'family_name_1'] as $required) {
            if (array_key_exists($required, $changes) && (! is_string($changes[$required]) || trim($changes[$required]) === '')) {
                throw new InvalidArgumentException("{$required} no puede quedar vacío.");
            }
        }

        $birth = $changes['birth_date'] ?? null;

        if (array_key_exists('birth_date', $changes) && $birth !== null) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $birth);

            if ($parsed === false || $parsed->format('Y-m-d') !== $birth) {
                throw new InvalidArgumentException('birth_date debe ser una fecha AAAA-MM-DD válida.');
            }
        }
    }
}
