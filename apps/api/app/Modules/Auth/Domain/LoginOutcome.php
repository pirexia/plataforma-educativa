<?php

namespace App\Modules\Auth\Domain;

/**
 * datos.md §A.1, ampliado en datos.md §C.7.1 (1.3) y §E.3.2 (1.4). Siete
 * resultados posibles de un intento de login, registrados en
 * `login_attempts.outcome`. `CredencialesInvalidas` y
 * `SegundoFactorInvalido` incrementan el contador de bloqueo
 * (RN-AUTH-14, RN-AUTH-64); `EstadoNoActivo` (credencial correcta sobre
 * un usuario `pendiente`/`inactivo`), `PendienteSegundoFactor`
 * (contraseña correcta, desafío abierto) y `FederadoSinVinculo`
 * (`datos.md §E.3.2`: ni bloqueo, ni credencial nuestra probada) no lo
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
    /** REQ-AUTH-002 (1.4). Un solo valor para "sin cuenta" y "correo no verificado" (RN-AUTH-87, §E.4.6). */
    case FederadoSinVinculo = 'federado_sin_vinculo';
}
