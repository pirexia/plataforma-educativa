<?php

namespace App\Modules\Auth\Domain;

/**
 * datos.md §A.1, ampliado en datos.md §C.7.1 (1.3). Seis resultados
 * posibles de un intento de login, registrados en `login_attempts.outcome`.
 * `CredencialesInvalidas` y `SegundoFactorInvalido` incrementan el
 * contador de bloqueo (RN-AUTH-14, RN-AUTH-64); `EstadoNoActivo`
 * (credencial correcta sobre un usuario `pendiente`/`inactivo`) y
 * `PendienteSegundoFactor` (contraseña correcta, desafío abierto) no lo
 * hacen (RN-AUTH-24, RN-AUTH-63) — y `PendienteSegundoFactor` en
 * particular **nunca** pone el contador a cero: solo `Exito` lo hace, y
 * solo se escribe cuando la sesión se ha creado de verdad
 * (funcional.md §C.4.4.2).
 */
enum LoginOutcome: string
{
    case Exito = 'exito';
    case CredencialesInvalidas = 'credenciales_invalidas';
    case CuentaBloqueada = 'cuenta_bloqueada';
    case EstadoNoActivo = 'estado_no_activo';
    case PendienteSegundoFactor = 'pendiente_segundo_factor';
    case SegundoFactorInvalido = 'segundo_factor_invalido';
}
