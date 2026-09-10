<?php

namespace App\Modules\Backoffice\Domain;

/**
 * datos.md §3. Vocabulario cerrado de `dual_authorizations.status`.
 */
enum DualAuthorizationStatus: string
{
    case Pendiente = 'pendiente';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';
    case Caducada = 'caducada';
    case Ejecutada = 'ejecutada';
    case Fallida = 'fallida';
}
