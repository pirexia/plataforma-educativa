<?php

namespace App\Models;

/**
 * REQ-CORE-003. La transición entre estados (invitación, activación,
 * baja) la gobierna REQ-AUTH (1.2); aquí solo se fija el vocabulario.
 */
enum UserStatus: string
{
    case Pendiente = 'pendiente';
    case Activo = 'activo';
    case Inactivo = 'inactivo';
}
