<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Hash;

/**
 * ADR-060 §4.3.1: bcrypt con el coste real de la aplicación (RN-AUTH-03,
 * coste >= 12; ~170 ms por operación) se calcula UNA vez por contraseña de
 * prueba y por proceso, no una vez por test. El resultado sigue siendo un
 * hash bcrypt de coste 12 de verdad: no se baja el coste en `testing`.
 *
 * Los tests que prueban el hash en sí (coste, reamasado, contraseña
 * distinta) llaman a `Hash` directamente y no usan esta clase.
 */
final class TestPasswordHash
{
    /** @var array<string, string> */
    private static array $hashes = [];

    public static function of(string $plain): string
    {
        return self::$hashes[$plain] ??= Hash::make($plain);
    }
}
