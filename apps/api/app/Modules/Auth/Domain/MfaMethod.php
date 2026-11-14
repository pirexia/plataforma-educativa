<?php

namespace App\Modules\Auth\Domain;

/**
 * funcional.md §C.4.12, datos.md §C.2. Los tres métodos del `CHECK` de
 * `user_mfa_factors.method` y `mfa_challenges.method`. `Sms` existe en el
 * enumerado y en `tenant_settings.mfa_allowed_methods` desde 1.3, y una
 * guarda le impide activarse mientras no haya proveedor (RN-AUTH-69,
 * funcional.md §C.7, `OPEN-AUTH-18`). `Email` existe en el modelo de
 * datos desde 1.3 (`§C.16`); su lógica de envío llega en 1.3b.
 */
enum MfaMethod: string
{
    case Totp = 'totp';
    case Email = 'email';
    case Sms = 'sms';

    public function requiresDelivery(): bool
    {
        return $this !== self::Totp;
    }
}
