<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\PasswordPolicy;

/**
 * RN-AUTH-01: mínimo `auth-local.password_min_length` (12 por defecto),
 * con al menos una mayúscula, una minúscula, un dígito y un símbolo.
 * RN-AUTH-02: máximo 72 bytes — bcrypt trunca en silencio a partir de ahí,
 * así que se rechaza en vez de aceptar y verificar solo un prefijo.
 */
final class ConfigPasswordPolicy implements PasswordPolicy
{
    public function violations(string $password): array
    {
        $violations = [];
        $minLength = (int) config('auth-local.password_min_length');
        $maxBytes = (int) config('auth-local.password_max_bytes');

        if (mb_strlen($password) < $minLength) {
            $violations[] = 'min_length';
        }

        if (strlen($password) > $maxBytes) {
            $violations[] = 'max_bytes';
        }

        if (! preg_match('/\p{Lu}/u', $password)) {
            $violations[] = 'uppercase';
        }

        if (! preg_match('/\p{Ll}/u', $password)) {
            $violations[] = 'lowercase';
        }

        if (! preg_match('/\d/', $password)) {
            $violations[] = 'digit';
        }

        if (! preg_match('/[^\p{L}\p{N}]/u', $password)) {
            $violations[] = 'symbol';
        }

        return $violations;
    }
}
