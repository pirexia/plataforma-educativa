<?php

namespace App\Modules\Auth\Domain;

/**
 * funcional.md §D.4.5. Regla única, determinista y testeable de
 * enmascarado del destino de un factor de entrega. Se calcula siempre al
 * presentar (`RN-AUTH-77`): nunca se persiste un valor enmascarado.
 */
final class DestinationMasker
{
    /** U+00B7 (middle dot), repetido tres veces con independencia de la
     * longitud real del segmento enmascarado — repetir un punto por
     * carácter revelaría la longitud del correo. */
    private const SEPARATOR = "\u{00B7}\u{00B7}\u{00B7}";

    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return self::maskLabel($email);
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        return self::maskLabel($local).'@'.self::maskDomain($domain);
    }

    private static function maskDomain(string $domain): string
    {
        $lastDot = strrpos($domain, '.');

        if ($lastDot === false) {
            return self::maskLabel($domain);
        }

        $label = substr($domain, 0, $lastDot);
        $tld = substr($domain, $lastDot + 1);

        return self::maskLabel($label).'.'.$tld;
    }

    private static function maskLabel(string $label): string
    {
        $length = mb_strlen($label);

        if ($length === 0) {
            return self::SEPARATOR;
        }

        if ($length === 1) {
            return $label.self::SEPARATOR;
        }

        return mb_substr($label, 0, 1).self::SEPARATOR.mb_substr($label, -1);
    }
}
