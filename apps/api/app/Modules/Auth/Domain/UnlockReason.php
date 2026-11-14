<?php

namespace App\Modules\Auth\Domain;

/**
 * RN-AUTH-14, RN-AUTH-18. Las tres formas de levantar un bloqueo, ninguna
 * sustituye a las otras.
 */
enum UnlockReason: string
{
    case Caducidad = 'caducidad';
    case Correo = 'correo';
    case Administrador = 'administrador';
}
