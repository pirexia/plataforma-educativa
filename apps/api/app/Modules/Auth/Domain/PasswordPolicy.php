<?php

namespace App\Modules\Auth\Domain;

/**
 * funcional.md §8.4. RN-AUTH-01/RN-AUTH-02, aplicada siempre en servidor
 * (INV-010) y siempre con la misma regla en los tres puntos que fijan
 * contraseña: canje, restablecimiento y cambio auto-servicio. Expuesta
 * como interfaz porque 1.3, 1.6 y REQ-SEED (1.15b) la necesitarán.
 */
interface PasswordPolicy
{
    /**
     * Códigos de regla incumplidos (vacío si la contraseña es válida). Un
     * código por regla, para que el llamador los traduzca con
     * `auth.validation.password.<code>` (INV-009, ADR-038 §6.3).
     *
     * @return list<string>
     */
    public function violations(string $password): array;
}
