<?php

namespace App\Modules\Acad\Application;

/**
 * REQ-ACAD/funcional.md `RN-ACAD-73`, `RN-ACAD-75` y §14 (riesgo de decimales
 * con coma flotante): los valores de una escala se manejan como **enteros
 * escalados ×100** (centésimas), nunca como `float`. `numeric(5,2)` admite de
 * −999,99 a 999,99, así que cabe de sobra en un entero.
 */
final class ScaleDecimal
{
    /** Máximo de cifras decimales que guarda `numeric(5,2)`. */
    public const MAX_DECIMALS = 2;

    /**
     * Entrada (cadena o número JSON) a centésimas. `null` si no es un decimal
     * con punto, de a lo sumo dos decimales y dentro de −999,99 a 999,99
     * (rechaza «5,5», «1e1», «1000», «.5», «5.», «+5» y los espacios).
     */
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            $text = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            $text = (string) $value;
        } elseif (is_string($value)) {
            $text = $value;
        } else {
            return null;
        }

        if (preg_match('/^(-?)(\d{1,3})(?:\.(\d{1,2}))?$/', $text, $m) !== 1) {
            return null;
        }

        $hundredths = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$hundredths : $hundredths;
    }

    /** Valor de una columna `numeric(5,2)` (`"5.00"`, `"-0.50"`) a centésimas. */
    public static function fromStored(mixed $stored): ?int
    {
        return $stored === null ? null : self::parse((string) $stored);
    }

    /** Forma canónica con dos decimales, la que devuelve PostgreSQL (`"5.00"`): así Eloquent no ve cambio donde no lo hay. */
    public static function toStored(int $hundredths): string
    {
        return self::format($hundredths, self::MAX_DECIMALS);
    }

    /** Forma de la API (`RN-ACAD-75`): exactamente `$decimals` cifras decimales y punto (`"5.00"`, `"5.0"`, `"5"`). */
    public static function format(int $hundredths, int $decimals): string
    {
        $sign = $hundredths < 0 ? '-' : '';
        $abs = abs($hundredths);
        $whole = intdiv($abs, 100);
        $fraction = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return $decimals === 0 ? "{$sign}{$whole}" : "{$sign}{$whole}.".substr($fraction, 0, $decimals);
    }

    /** Tamaño de un paso de la escala en centésimas (`10^−decimals`): 100, 10 o 1. */
    public static function step(int $decimals): int
    {
        return 10 ** (self::MAX_DECIMALS - $decimals);
    }

    /** ¿Tiene el valor (en centésimas) no más cifras decimales que `$decimals`? */
    public static function fits(int $hundredths, int $decimals): bool
    {
        return $hundredths % self::step($decimals) === 0;
    }
}
