<?php

namespace App\Modules\Curso\Domain;

use RuntimeException;

/**
 * RN-CURSO-21 (1.10): se intentó escribir en un curso `cerrado`/`archivado`.
 * La lanza `AcademicYearWriteGuard::assertWritable()`; el módulo `curso` la
 * traduce a `409 urn:pge:error:academic-year-closed`, igual que el error de
 * motor `SQLSTATE YC001` del disparador (ADR-057 §5.5).
 *
 * `year` es `null` cuando no se pudo resolver el curso (p. ej. transacción
 * abortada): la respuesta sigue siendo `409`, con un `detail` sin código.
 */
final class AcademicYearReadOnlyException extends RuntimeException
{
    public function __construct(public readonly ?AcademicYearSummary $year = null)
    {
        parent::__construct('academic_year_closed:'.($year->publicId ?? ''));
    }
}
