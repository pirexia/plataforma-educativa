<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md §7.1, ADR-057 §5.6 (1.10): **comprobación previa
 * consultiva, no barrera**. La garantía es el disparador de PostgreSQL
 * (`academic_year_write_guard`, RN-CURSO-23). Esta interfaz no toma
 * bloqueos: entre la comprobación y la escritura puede cerrarse el curso, y
 * entonces responde el disparador. Su uso **no es obligatorio**.
 *
 * Sirve para fallar antes de efectos laterales (p. ej. antes de generar un
 * PDF), para que un proceso por lotes salte filas sin dejar abortada su
 * transacción (`QueryException` con `YC001` la aborta) y para que la
 * interfaz oculte acciones.
 */
interface AcademicYearWriteGuard
{
    /** ¿Admite escritura este curso? (RN-CURSO-20). */
    public function admitsWrites(AcademicYearSummary $year): bool;

    /**
     * Afirma que admite escritura o lanza la excepción de dominio, que el
     * manejador traduce al mismo `409 academic-year-closed` (RN-CURSO-21).
     *
     * @throws AcademicYearReadOnlyException
     */
    public function assertWritable(AcademicYearSummary $year): void;
}
