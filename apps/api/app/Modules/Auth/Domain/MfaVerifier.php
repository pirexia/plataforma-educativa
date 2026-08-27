<?php

namespace App\Modules\Auth\Domain;

/**
 * funcional.md §C.9.2, `ADR-041`. Verificación de un código de un método de
 * MFA que se apoya en material secreto derivado en el alta — hoy, TOTP.
 *
 * Los métodos de entrega (correo, y el día de mañana SMS) **no** pasan por
 * aquí: su código se compara con `hash_equals()` directamente contra el
 * hash guardado en el desafío o en el alta (funcional.md §C.4.2), sin
 * ningún secreto que envolver. Es el motivo por el que esta interfaz no
 * tiene más que una implementación en 1.3 (`Google2FaTotpVerifier`) y por
 * el que su firma no presupone nada sobre cómo llegaría un verificador de
 * SMS: cuando exista, decidirá si encaja aquí o si necesita su propio
 * contrato (funcional.md §C.12).
 */
interface MfaVerifier
{
    public function method(): MfaMethod;

    /**
     * RN-AUTH-58: si `$code` es válido dentro de la ventana de tolerancia,
     * devuelve el paso de tiempo que lo validó — un entero que hay que
     * guardar como `last_used_step` y volver a pasar como `$lastUsedStep`
     * en la siguiente llamada, para rechazar la reutilización del mismo
     * paso. Si el código es inválido, o el paso que validaría ya se había
     * consumido, devuelve `null`.
     */
    public function verify(string $secret, string $code, ?int $lastUsedStep): ?int;
}
