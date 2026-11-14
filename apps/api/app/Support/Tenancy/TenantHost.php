<?php

namespace App\Support\Tenancy;

/**
 * ADR-014: el slug sale exclusivamente del host de la petición, nunca de un
 * parámetro de ruta, cabecera personalizada o campo de formulario.
 */
final class TenantHost
{
    /**
     * Null si el host no tiene una forma resoluble: sin dominio base
     * configurado, host que no termina en él, o más de un nivel de
     * subdominio (rechazado por simplicidad, no soportado en 0.7).
     */
    public static function slugFrom(string $host): ?string
    {
        $host = strtolower(explode(':', $host, 2)[0]);
        $base = strtolower((string) config('tenancy.base_domain'));

        if ($base === '' || ! str_ends_with($host, ".{$base}")) {
            return null;
        }

        $slug = substr($host, 0, -(strlen($base) + 1));

        return $slug !== '' && ! str_contains($slug, '.') ? $slug : null;
    }
}
