<?php

namespace App\Modules\Acad\Application;

/**
 * `RN-ACAD-02`/`RN-ACAD-03` [DERIVADA]: «el criterio de textos cortos del
 * producto» (el mismo de `RN-CURSO-01`, `max:255`). Una sola cifra para el
 * código, el nombre simple de grupo y subgrupo y cada idioma de un nombre
 * traducible; el FormRequest la pasa a `TranslationsValidator` (#413).
 */
final class StructureLimits
{
    public const MAX_TEXT_LENGTH = 255;

    /** `position` es `integer` en base de datos (`datos.md §1`). */
    public const MAX_POSITION = 2147483647;
}
