<?php

namespace App\Modules\Auth\Domain;

/**
 * funcional.md §C.9.2, §C.4.1, `ADR-041`. Alta de un factor TOTP: secreto
 * nuevo y la URI `otpauth://` completa para el QR. Separada de
 * `MfaVerifier` a propósito (`ADR-041 §1.4`): un verificador de SMS o de
 * correo no tiene secreto ni URI, y meter estos dos métodos en
 * `MfaVerifier` obligaría a la mitad de sus implementaciones a lanzar
 * excepciones.
 */
interface TotpProvisioner
{
    /**
     * RN-AUTH-55, funcional.md §C.4.1 punto 3: secreto de 20 bytes de un
     * generador criptográfico, devuelto en base32.
     */
    public function generateSecret(): string;

    /**
     * funcional.md §C.4.1 punto 4: la URI completa, lista para el QR de la
     * SPA. El servidor no genera ninguna imagen (`OPEN-AUTH-20`).
     */
    public function buildOtpAuthUri(string $secret, string $accountLabel, string $issuer): string;
}
