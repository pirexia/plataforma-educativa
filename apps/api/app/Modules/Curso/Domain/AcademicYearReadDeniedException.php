<?php

namespace App\Modules\Curso\Domain;

use RuntimeException;

/**
 * RN-CURSO-25 (1.10): el usuario no tiene `curso_historico.leer` y pide
 * datos de un curso `cerrado`/`archivado`. El módulo `curso` la traduce a
 * `404` (no `403`: no se confirma que existan datos, `ADR-038 §6.4`).
 */
final class AcademicYearReadDeniedException extends RuntimeException
{
    public function __construct(public readonly AcademicYearSummary $year)
    {
        parent::__construct('academic_year_read_denied:'.$year->publicId);
    }
}
