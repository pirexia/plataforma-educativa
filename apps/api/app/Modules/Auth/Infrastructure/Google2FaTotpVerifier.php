<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\MfaMethod;
use App\Modules\Auth\Domain\MfaVerifier;
use App\Modules\Auth\Domain\TotpProvisioner;
use PragmaRX\Google2FA\Google2FA;

/**
 * `ADR-041`. **Único fichero de todo `apps/api` autorizado a escribir
 * `use PragmaRX\Google2FA\...`** — todo lo demás pasa por `MfaVerifier` o
 * `TotpProvisioner`. Implementa las dos interfaces porque son la misma
 * librería subyacente, pero cada una se consume por su contrato propio.
 *
 * Cuatro trampas verificadas en el código fuente de la librería
 * (`ADR-041 §1.3`, §2 de sus "Preguntas abiertas"; la cuarta es hallazgo
 * propio de 1.3, no estaba en el ADR), evitadas aquí y solo aquí:
 *
 * 1. `generateSecretKey($length)` mide **caracteres base32, no bytes**:
 *    `self::SECRET_LENGTH_BASE32_CHARS` es `32`, no `20` — 32 caracteres
 *    base32 son 20 bytes (`RN-AUTH-55`, funcional.md §C.4.1 punto 3).
 * 2. `verifyKeyNewer()` devuelve `int|false`, nunca tratado como booleano:
 *    la única comparación es `false === $resultado`.
 * 3. `getQRCodeUrl()` ya construye la URI `otpauth://` completa — no se
 *    reconstruye a mano.
 * 4. **Hallazgo propio (CA-AUTH-121, severidad Alta)**: `verifyKeyNewer()`
 *    con `$oldTimestamp = null` (la primera verificación de un factor,
 *    `last_used_step` todavía sin valor) no devuelve el paso de tiempo:
 *    devuelve el booleano `true` (código fuente de la librería,
 *    `Google2FA::findValidOTP()`: `return is_null($oldTimestamp) ? true :
 *    $startingTimestamp;`). Guardar ese `true` en `last_used_step`
 *    inutiliza la protección contra reutilización desde la primera vez:
 *    la siguiente verificación compara contra `1` (el `true` truncado a
 *    entero), nunca contra un paso de tiempo real, y un código ya usado
 *    vuelve a aceptarse durante el resto de su ventana de validez
 *    (`RN-AUTH-58`). `self::NEVER_USED_STEP` (`0`) es más antiguo que
 *    cualquier paso de tiempo real (Unix ÷ 30 ya son decenas de
 *    millones), así que la librería SIEMPRE devuelve el entero real la
 *    primera vez.
 */
final class Google2FaTotpVerifier implements MfaVerifier, TotpProvisioner
{
    /** 32 caracteres base32 = 20 bytes (ADR-041 §1.3, RN-AUTH-55). */
    private const SECRET_LENGTH_BASE32_CHARS = 32;

    /**
     * Trampa 4 de la clase: sentinela para la primera verificación de un
     * factor (`last_used_step` todavía `NULL`), más antiguo que cualquier
     * paso de tiempo TOTP real.
     */
    private const NEVER_USED_STEP = 0;

    public function __construct(
        private readonly Google2FA $engine,
    ) {}

    public function method(): MfaMethod
    {
        return MfaMethod::Totp;
    }

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(self::SECRET_LENGTH_BASE32_CHARS);
    }

    public function buildOtpAuthUri(string $secret, string $accountLabel, string $issuer): string
    {
        return $this->engine->getQRCodeUrl($issuer, $accountLabel, $secret);
    }

    public function verify(string $secret, string $code, ?int $lastUsedStep): ?int
    {
        $window = (int) config('auth-local.mfa.totp_window');

        // Trampa 4: nunca pasar null tal cual — la librería lo trata como
        // "sin protección de reutilización que comprobar" y devuelve
        // `true` en vez del paso de tiempo real.
        $oldTimestamp = $lastUsedStep ?? self::NEVER_USED_STEP;

        // ADR-041: verifyKeyNewer() devuelve int|false. NUNCA `if ($resultado)`
        // ni `=== true` — la única comparación válida es `false === $resultado`,
        // y el entero devuelto (el paso de tiempo validado) es lo que hay
        // que guardar en last_used_step (RN-AUTH-58).
        $result = $this->engine->verifyKeyNewer($secret, $code, $oldTimestamp, $window);

        if ($result === false) {
            return null;
        }

        // Defensa en profundidad: con $oldTimestamp siempre entero (nunca
        // null, ver arriba), la librería no debería devolver `true` — pero
        // ADR-041 ya asume un solo mantenedor con huecos de hasta dos años
        // entre releases. Si alguna vez lo hiciera, fallar alto (código
        // rechazado) es más seguro que persistir un `last_used_step` que
        // no protege nada (RN-AUTH-58).
        if (! is_int($result)) {
            return null;
        }

        return $result;
    }
}
