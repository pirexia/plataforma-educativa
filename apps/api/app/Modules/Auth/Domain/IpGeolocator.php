<?php

namespace App\Modules\Auth\Domain;

/**
 * funcional.md §B.7, OPEN-AUTH-13 (sin resolver: sin fuente de
 * geolocalización decidida en el proyecto). El hueco del requisito
 * preparado sin llenarlo: la única implementación de 1.2b es
 * `NullIpGeolocator`, que siempre devuelve `null` ("desconocida"). No hay
 * llamada saliente que pueda fallar — propiedad que conviene no perder al
 * resolver la pregunta abierta.
 */
interface IpGeolocator
{
    public function locate(?string $ipAddress): ?string;
}
