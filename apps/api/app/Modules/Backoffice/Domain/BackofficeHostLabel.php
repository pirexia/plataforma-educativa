<?php

namespace App\Modules\Backoffice\Domain;

/**
 * `RN-BO-49`, `ADR-046 §4.4`, `operacion.md §0.4`. Defensa en profundidad:
 * un `slug` de tenant no puede coincidir con la etiqueta del *host* de
 * plataforma, para que un despliegue mal configurado (`BACKOFFICE_HOST`
 * como subdominio de `TENANCY_BASE_DOMAIN`, que `RN-BO-49` ya prohíbe en
 * la capa de red) no pueda resolver un centro con ese nombre.
 */
final class BackofficeHostLabel
{
    /**
     * Null si `BACKOFFICE_HOST` no está configurado todavía (`OPEN-08`):
     * sin *host*, no hay etiqueta que reservar.
     */
    public static function current(): ?string
    {
        $host = (string) config('backoffice.host');

        if ($host === '') {
            return null;
        }

        return strtolower(explode('.', $host)[0]);
    }

    public static function matches(string $slug): bool
    {
        $label = self::current();

        return $label !== null && strtolower($slug) === $label;
    }
}
