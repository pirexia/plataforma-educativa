<?php

namespace App\Support\Csv;

/**
 * ADR-054 §10.2 (RN-CORE-48): tipos de celda admitidos. Los declara el
 * generador en su esquema; `CsvWriter` nunca los infiere del contenido.
 * No hay tipo decimal: un decimal lo formatea el generador como `Text`.
 */
enum CsvColumnType
{
    case Text;
    case Integer;
    case Instant;
}
