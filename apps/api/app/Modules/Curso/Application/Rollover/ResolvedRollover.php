<?php

namespace App\Modules\Curso\Application\Rollover;

use App\Modules\Curso\Domain\AcademicYearSummary;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-63`, `-64`, `-78` (1.12b): una petición ya validada.
 * `target` es el resumen del curso destino (leído bajo `FOR SHARE` cuando quien resuelve lo
 * pide, `RN-CURSO-68`); `effective` es la selección efectiva (los elegidos más, con la
 * casilla, los del paquete), en el orden del catálogo.
 */
final readonly class ResolvedRollover
{
    /**
     * @param  list<string>  $effective
     */
    public function __construct(
        public AcademicYearSummary $target,
        public AcademicYearSummary $source,
        public array $effective,
        public bool $copyTeachingAssignments,
    ) {}

    /** RN-CURSO-72: `destino.starts_on − origen.starts_on`, en días. */
    public function dateOffsetDays(): int
    {
        return RolloverDates::offsetDays($this->source, $this->target);
    }
}
