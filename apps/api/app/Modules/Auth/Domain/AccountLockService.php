<?php

namespace App\Modules\Auth\Domain;

use App\Models\User;
use App\Modules\Auth\Domain\Models\AccountLockout;

/**
 * funcional.md §8.4. Consultar y levantar bloqueos. La consumirá 1.6
 * (soporte de plataforma).
 */
interface AccountLockService
{
    /**
     * Bloqueo vivo de `(tenant_id, email)`, o `null`. Si el bloqueo vivo
     * encontrado ya venció (RN-AUTH-38), lo cierra como `caducidad` en el
     * momento de la consulta y devuelve `null` — el índice único parcial
     * de `RN-AUTH-17` queda libre de inmediato.
     */
    public function findLiveOrCloseExpired(string $email): ?AccountLockout;

    /**
     * RN-AUTH-14: quinto fallo consecutivo. Crea la fila, y si el correo
     * corresponde a una cuenta, emite el token de desbloqueo y encola el
     * correo de aviso (RN-AUTH-15, `CA-AUTH-028`).
     */
    public function lock(string $email, ?User $user, int $failedCount): AccountLockout;

    /**
     * RN-AUTH-14, RN-AUTH-18: levanta el bloqueo por cualquiera de las
     * tres vías y pone a cero el recuento de fallos consecutivos (que no
     * es una columna: lo resuelve la ausencia de bloqueo vivo posterior).
     */
    public function lift(AccountLockout $lockout, UnlockReason $reason, ?User $unlockedBy): void;
}
