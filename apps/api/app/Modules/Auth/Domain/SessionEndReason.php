<?php

namespace App\Modules\Auth\Domain;

/**
 * funcional.md §B.4.6, RN-AUTH-44. Las siete razones por las que una fila
 * de `user_sessions` deja de estar viva, y ninguna octava por analogía
 * (issue #61 es el recordatorio de lo que pasa cuando se reutiliza un
 * valor por no tener el correcto).
 */
enum SessionEndReason: string
{
    /** DELETE /auth/session (§4.3): la salida ordinaria. */
    case Logout = 'logout';

    /** DELETE /auth/sessions/{public_id} y DELETE /auth/sessions, por el propio titular. */
    case RevocadaUsuario = 'revocada_usuario';

    /** EnforceSessionIdleTimeout (§4.6): el punto 1 de REQ-AUTH-005. */
    case Inactividad = 'inactividad';

    /** Cierre perezoso (§B.4.2) y CloseOrphanedUserSessions (§B.4.7): la fila de `sessions` ya no existe. */
    case Caducidad = 'caducidad';

    /** Restablecimiento (RN-AUTH-22) y cambio auto-servicio (RN-AUTH-36): contención automática. */
    case CambioCredencial = 'cambio_credencial';

    /** Evento UserDeactivated de REQ-CORE. */
    case BajaUsuario = 'baja_usuario';

    /** VerifySessionTenant (RN-AUTH-31): hecho de seguridad, no una salida normal. */
    case TenantIncoherente = 'tenant_incoherente';
}
