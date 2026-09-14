<?php

namespace App\Modules\Backoffice\Domain;

/**
 * permisos.md §2, §3. El catálogo de capacidades vive en el código, un
 * `enum` de PHP — no en una tabla, a diferencia de `REQ-PERM`: `REQ-BO-007`
 * enumera exactamente cuatro roles y ningún requisito pide capacidades de
 * plataforma personalizadas. Una tabla editable de capacidades de
 * plataforma sería un camino para que alguien se conceda capacidades
 * sobre todos los centros a la vez, y ese camino no debe existir.
 *
 * **Subconjunto de 1.6, ampliado por 1.6b**: permisos.md §3 describe la
 * matriz completa de `REQ-BO` (`tenant.crear/actualizar/suspender/baja/
 * eliminar`, `modulo.*`, `flag.*`, `salud.leer`, `job.reintentar`,
 * `metrica.leer`, `autorizacion.leer`…). El chasis de 1.6 declaró las
 * capacidades que respaldaba con un *endpoint* real — identidad y
 * acceso, gestión de administradores, lista blanca de IP, y la lectura
 * de auditoría de plataforma—; `1.6b` añade las cinco de ciclo de vida
 * de tenant (permisos.md §4.3) y `autorizacion.leer`, que respalda la
 * doble autorización que la eliminación de un tenant necesita
 * (`REQ-BO-007`). El resto se añade en `1.6c`/`1.6d`/`1.6e`, cuando
 * exista el *endpoint* que las necesite: declarar aquí una capacidad sin
 * *endpoint* sería inventar superficie que no existe.
 */
enum PlatformCapability: string
{
    case TenantLeer = 'tenant.leer';
    case TenantCrear = 'tenant.crear';
    case TenantActualizar = 'tenant.actualizar';
    case TenantSuspender = 'tenant.suspender';
    case TenantBaja = 'tenant.baja';
    case TenantEliminar = 'tenant.eliminar';

    case AdminLeer = 'admin.leer';
    case AdminCrear = 'admin.crear';
    case AdminActualizar = 'admin.actualizar';
    case AdminEliminar = 'admin.eliminar';
    case AdminRolGestionar = 'admin.rol.gestionar';
    case AdminMfaRestablecer = 'admin.mfa.restablecer';

    case IpAllowlistLeer = 'ip_allowlist.leer';
    case IpAllowlistGestionar = 'ip_allowlist.gestionar';

    case AuditoriaPlataformaLeer = 'auditoria_plataforma.leer';

    // 1.6b: permisos.md §2.8. No existe `autorizacion.aprobar` — la
    // aprobación se rige por la capacidad de la acción autorizada
    // (permisos.md §5.2).
    case AutorizacionLeer = 'autorizacion.leer';
}
