<?php

namespace App\Modules\Auth\Domain;

/**
 * datos.md §A.1. Los cuatro resultados posibles de un intento de login,
 * registrados en `login_attempts.outcome`. Solo `CredencialesInvalidas`
 * incrementa el contador de bloqueo (RN-AUTH-14); `EstadoNoActivo`
 * (credencial correcta sobre un usuario `pendiente`/`inactivo`) no lo hace
 * (RN-AUTH-24).
 */
enum LoginOutcome: string
{
    case Exito = 'exito';
    case CredencialesInvalidas = 'credenciales_invalidas';
    case CuentaBloqueada = 'cuenta_bloqueada';
    case EstadoNoActivo = 'estado_no_activo';
}
