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
 * Tres trampas verificadas en el código fuente de la librería
 * (`ADR-041 §1.3`, §2 de sus "Preguntas abiertas"), evitadas aquí y solo
 * aquí:
 *
 * 1. `generateSecretKey($length)` mide **caracteres base32, no bytes**:
 *    `self::SECRET_LENGTH_BASE32_CHARS` es `32`, no `20` — 32 caracteres
 *    base32 son 20 bytes (`RN-AUTH-55`, funcional.md §C.4.1 punto 3).
 * 2. `verifyKeyNewer()` devuelve `int|false`, nunca tratado como booleano:
 *    la única comparación es `false === $resultado`.
 * 3. `getQRCodeUrl()` ya construye la URI `otpauth://` completa — no se
 *    reconstruye a mano.
 */
final class Google2FaTotpVerifier implements MfaVerifier, TotpProvisioner
{
    /** 32 caracteres base32 = 20 bytes (ADR-041 §1.3, RN-AUTH-55). */
    private const SECRET_LENGTH_BASE32_CHARS = 32;

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

        // ADR-041: verifyKeyNewer() devuelve int|false. NUNCA `if ($resultado)`
        // ni `=== true` — la única comparación válida es `false === $resultado`,
        // y el entero devuelto (el paso de tiempo validado) es lo que hay
        // que guardar en last_used_step (RN-AUTH-58).
        $result = $this->engine->verifyKeyNewer($secret, $code, $lastUsedStep, $window);

        if (false === $result) {
            return null;
        }

        return $result;
    }
}
