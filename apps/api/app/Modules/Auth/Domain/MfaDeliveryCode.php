<?php

namespace App\Modules\Auth\Domain;

/**
 * funcional.md §D.4.1, §D.4.2, `RN-AUTH-56`, `RN-AUTH-75`. Código de 6
 * dígitos de un método de entrega (alta o desafío): generador
 * criptográfico, solo su hash SHA-256 se persiste — el valor en claro
 * únicamente existe en el *payload* cifrado del correo (`RN-AUTH-84`).
 */
final class MfaDeliveryCode
{
    private const LENGTH = 6;

    public static function generate(): string
    {
        return str_pad((string) random_int(0, (10 ** self::LENGTH) - 1), self::LENGTH, '0', STR_PAD_LEFT);
    }

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
