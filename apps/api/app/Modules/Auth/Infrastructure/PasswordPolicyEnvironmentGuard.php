<?php

namespace App\Modules\Auth\Infrastructure;

use RuntimeException;

/**
 * operacion.md §2.1: `AUTH_PASSWORD_MIN_LENGTH` y `AUTH_BCRYPT_ROUNDS` "no
 * son conmutadores de política: existen para poder endurecerla", y la
 * documentación afirma que "la guarda de arranque rechaza cualquier valor
 * por debajo de 12" para las dos. Esa guarda no existía en el código —
 * hallazgo al escribir la suite de tests de REQ-AUTH (1.2): sin ella,
 * `AUTH_PASSWORD_MIN_LENGTH=1` o `AUTH_BCRYPT_ROUNDS=4` en un fichero de
 * entorno de producción se aplicarían en silencio, debilitando RN-AUTH-01
 * y RN-AUTH-03 sin que nada lo impidiera. Mismo patrón que
 * `SessionEnvironmentGuard`: corre en todos los entornos, lanzada desde
 * `AuthServiceProvider::boot()`.
 */
final class PasswordPolicyEnvironmentGuard
{
    private const MINIMUM = 12;

    public function verify(): void
    {
        $this->assertMinLengthAtLeastTwelve();
        $this->assertBcryptRoundsAtLeastTwelve();
    }

    /**
     * RN-AUTH-01: mínimo 12 caracteres. `AUTH_PASSWORD_MIN_LENGTH` puede
     * endurecer la política, nunca debilitarla.
     */
    private function assertMinLengthAtLeastTwelve(): void
    {
        $minLength = (int) config('auth-local.password_min_length');

        if ($minLength < self::MINIMUM) {
            throw new RuntimeException(
                "AUTH_PASSWORD_MIN_LENGTH ({$minLength}) no puede ser menor que ".self::MINIMUM.
                ' (RN-AUTH-01, docs/modulos/REQ-AUTH/operacion.md §2.1).'
            );
        }
    }

    /**
     * RN-AUTH-03: coste bcrypt mínimo 12. Verifica el valor real que
     * `config/hashing.php` va a pasarle al hasher (`AUTH_BCRYPT_ROUNDS`),
     * no una copia — evita que las dos configuraciones diverjan.
     */
    private function assertBcryptRoundsAtLeastTwelve(): void
    {
        $rounds = (int) config('hashing.bcrypt.rounds');

        if ($rounds < self::MINIMUM) {
            throw new RuntimeException(
                "AUTH_BCRYPT_ROUNDS ({$rounds}) no puede ser menor que ".self::MINIMUM.
                ' (RN-AUTH-03, docs/modulos/REQ-AUTH/operacion.md §2.1).'
            );
        }
    }
}
