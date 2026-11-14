<?php

namespace App\Modules\Backoffice\Domain;

/**
 * permisos.md §4. El mapa capacidad → rol vive en el código, en una
 * constante única — no en base de datos: es lo único de esta forma que
 * cambia con el tiempo (`platform_admin_roles`, qué roles tiene cada
 * persona), y eso sí es una tabla.
 *
 * Resolución multi-rol: **unión de capacidades**, sin `deny` (permisos.md
 * §1.4) — los roles son cuatro, fijos, y un `deny` solo añadiría una
 * forma de equivocarse.
 */
final class PlatformCapabilityMap
{
    /**
     * @var array<value-of<PlatformRole>, list<PlatformCapability>>
     */
    private const MAP = [
        'soporte' => [
            PlatformCapability::TenantLeer,
            PlatformCapability::AuditoriaPlataformaLeer,
            PlatformCapability::ModuloLeer,
            // permisos.md §4.5 punto 1: `salud.leer` es diagnóstico —
            // literalmente el trabajo de soporte— y no incluye
            // `job.reintentar`, que es escritura (§4.1: "soporte no tiene
            // ni una escritura").
            PlatformCapability::SaludLeer,
            // permisos.md §4.6: soporte lee flags (diagnóstico) y no
            // puede escribirlos — ni el interruptor ni las reglas.
            PlatformCapability::FlagLeer,
        ],
        'operaciones' => [
            PlatformCapability::TenantLeer,
            // permisos.md §4.3: operaciones puede parar un centro y no
            // puede cerrarlo — tenant.actualizar y tenant.suspender sí,
            // tenant.baja y tenant.eliminar no.
            PlatformCapability::TenantActualizar,
            PlatformCapability::TenantSuspender,
            PlatformCapability::AuditoriaPlataformaLeer,
            PlatformCapability::AutorizacionLeer,
            // permisos.md §4.4: «módulos, límites, flags» es literal en
            // REQ-BO-007 para operaciones.
            PlatformCapability::ModuloLeer,
            PlatformCapability::ModuloContratar,
            PlatformCapability::ModuloContratarMasivo,
            // permisos.md §4.5: operaciones diagnostica, reintenta y lee
            // métricas — las tres capacidades de 1.6d.
            PlatformCapability::SaludLeer,
            PlatformCapability::JobReintentar,
            PlatformCapability::MetricaLeer,
            // permisos.md §4.6: «módulos, límites, flags» es literal en
            // REQ-BO-007 para operaciones — también la designación de
            // early adopter, autorizada con tenant.actualizar, ya
            // concedida arriba.
            PlatformCapability::FlagLeer,
            PlatformCapability::FlagGestionar,
        ],
        'comercial' => [
            PlatformCapability::TenantLeer,
            // permisos.md §4.4 punto 4: comercial necesita saber qué está
            // contratado («planes y facturación»), nunca escribir.
            PlatformCapability::ModuloLeer,
            // permisos.md §4.5 punto 2 y 3: comercial lee métricas
            // («planes y facturación») y NO lee la ficha de salud de un
            // centro concreto — esa asimetría con `soporte` es deliberada.
            PlatformCapability::MetricaLeer,
        ],
        'superadministrador' => [
            PlatformCapability::TenantLeer,
            PlatformCapability::TenantCrear,
            PlatformCapability::TenantActualizar,
            PlatformCapability::TenantSuspender,
            PlatformCapability::TenantBaja,
            PlatformCapability::TenantEliminar,
            PlatformCapability::AdminLeer,
            PlatformCapability::AdminCrear,
            PlatformCapability::AdminActualizar,
            PlatformCapability::AdminEliminar,
            PlatformCapability::AdminRolGestionar,
            PlatformCapability::AdminMfaRestablecer,
            PlatformCapability::IpAllowlistLeer,
            PlatformCapability::IpAllowlistGestionar,
            PlatformCapability::AuditoriaPlataformaLeer,
            PlatformCapability::AutorizacionLeer,
            PlatformCapability::ModuloLeer,
            PlatformCapability::ModuloContratar,
            PlatformCapability::ModuloContratarMasivo,
            PlatformCapability::SaludLeer,
            PlatformCapability::JobReintentar,
            PlatformCapability::MetricaLeer,
            PlatformCapability::FlagLeer,
            PlatformCapability::FlagGestionar,
        ],
    ];

    /**
     * @param  list<PlatformRole>  $roles
     */
    public static function grants(array $roles, PlatformCapability $capability): bool
    {
        foreach ($roles as $role) {
            // self::MAP declara los cuatro casos de PlatformRole (comprobado
            // por PHPStan: un quinto caso sin entrada aquí deja de compilar
            // en vez de degradar en silencio a "sin capacidades" — el mismo
            // criterio de "un REVOKE que no se prueba no existe" aplicado
            // al tipo, no a un fallback en tiempo de ejecución).
            if (in_array($capability, self::MAP[$role->value], true)) {
                return true;
            }
        }

        return false;
    }
}
