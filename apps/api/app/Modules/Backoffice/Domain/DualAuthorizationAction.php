<?php

namespace App\Modules\Backoffice\Domain;

/**
 * datos.md §3. Vocabulario cerrado de `dual_authorizations.action`. Se
 * amplía por migración, nunca por dato. Ninguna de las tres se ejecuta
 * todavía en 1.6 (`tenant.eliminar`/`tenant.baja` son de 1.6b,
 * `modulo.descontratar_masivo` de 1.6c): esta tabla es la pieza de
 * infraestructura que 1.6 construye para que esos sub-pasos la
 * reutilicen (funcional.md §12.2.1).
 */
enum DualAuthorizationAction: string
{
    case TenantEliminar = 'tenant.eliminar';
    case TenantBaja = 'tenant.baja';
    case ModuloDescontratarMasivo = 'modulo.descontratar_masivo';
}
