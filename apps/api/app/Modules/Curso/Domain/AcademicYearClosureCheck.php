<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md §7.1, RN-CURSO-30 (1.10): validación bloqueante
 * del cierre que registra un módulo (p. ej. `CALIF`: «no se puede cerrar
 * con calificaciones sin publicar»), vía `AcademicYearClosureRegistry`.
 * Contrato síncrono en el módulo propietario, no evento: el cierre necesita
 * el resultado (`ADR-048 §4.9`).
 *
 * Se ejecuta con el `FOR UPDATE` del curso retenido (RN-CURSO-32): **debe
 * ser acotada** y **no puede tomar bloqueos de filas de otros módulos** (riesgo de
 * interbloqueo, ADR-057 §5.4).
 */
interface AcademicYearClosureCheck
{
    /** @return AcademicYearClosureFailure|null `null` si el curso puede cerrarse */
    public function check(AcademicYearSummary $year): ?AcademicYearClosureFailure;
}
