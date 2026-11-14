<?php

namespace App\Modules\Auth\Domain;

/**
 * funcional.md §B.6.4, OPEN-AUTH-17 (aprobada: análisis propio, sin
 * dependencia externa). Deriva del `User-Agent` una descripción legible
 * para que el usuario reconozca la fila del panel — nunca un criterio de
 * seguridad. Un `User-Agent` irreconocible no es un error: produce
 * `desconocido` en los tres campos (CA-AUTH-097).
 */
interface ClientDescriber
{
    public function describe(?string $userAgent): ClientDescription;
}
