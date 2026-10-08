<?php

namespace App\Support\Sessions;

use App\Modules\Auth\Domain\SessionEndReason;

/**
 * INV-007, ADR-056 AR-02, ADR-044 §4.10: contrato mínimo que necesitan los
 * *middleware* de sesión del núcleo (`EnforceSessionIdleTimeout`,
 * `VerifySessionTenant`) para cerrar la fila de `user_sessions` de la sesión
 * en curso sin importar el modelo `UserSession` de Auth. Implementación en
 * `Auth\Infrastructure`.
 *
 * Semántica idéntica a la consulta que reemplaza: busca la fila abierta
 * (`ended_at IS NULL`) de ese `session_id` **bajo el tenant activo** (la
 * consulta pasa por `TenantScope`, así que el llamador es responsable de
 * estar dentro del `TenantContext` correcto, p. ej. `runFor()`) y, si
 * existe, la cierra con el motivo dado. Si no existe, no hace nada.
 */
interface ActiveSessionCloser
{
    public function closeBySessionId(string $sessionId, SessionEndReason $reason): void;
}
