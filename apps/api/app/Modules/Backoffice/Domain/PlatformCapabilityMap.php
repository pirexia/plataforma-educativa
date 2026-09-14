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
        ],
        'comercial' => [
            PlatformCapability::TenantLeer,
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
