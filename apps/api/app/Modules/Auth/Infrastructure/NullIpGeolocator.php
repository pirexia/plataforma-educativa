<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\IpGeolocator;

/**
 * funcional.md §B.7, OPEN-AUTH-13 (sin resolver). Siempre "desconocida":
 * sin fuente de geolocalización decidida en el proyecto, no se inventa
 * una (`CLAUDE.md §11`). Sin esta implementación no hay ninguna llamada
 * saliente que pueda fallar.
 */
final class NullIpGeolocator implements IpGeolocator
{
    public function locate(?string $ipAddress): ?string
    {
        return null;
    }
}
