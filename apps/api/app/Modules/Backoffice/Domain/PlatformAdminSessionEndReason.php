<?php

namespace App\Modules\Backoffice\Domain;

/**
 * datos.md §2.7. Vocabulario propio, distinto del `SessionEndReason` de
 * tenant: son dos sujetos distintos y compartir el enum ataría dos
 * ciclos de vida que no tienen por qué evolucionar juntos.
 */
enum PlatformAdminSessionEndReason: string
{
    case CierreUsuario = 'cierre_usuario';
    case Caducidad = 'caducidad';
    case RevocadaAdmin = 'revocada_admin';
    case AdminSuspendido = 'admin_suspendido';
    case MfaRestablecido = 'mfa_restablecido';
}
