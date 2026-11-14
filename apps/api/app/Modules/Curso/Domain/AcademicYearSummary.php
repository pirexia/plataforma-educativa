<?php

namespace App\Modules\Curso\Domain;

use Carbon\CarbonImmutable;

/**
 * REQ-CURSO/funcional.md §7.1, RN-CURSO-26 (1.10). Vista inmutable de un
 * curso para los demás módulos: nunca el modelo Eloquent (`AR-01`,
 * `INV-007`). `id` es el identificador interno, solo para que el consumidor
 * escriba su propio `academic_year_id`; no sale de la API (`ADR-029`).
 */
final readonly class AcademicYearSummary
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $code,
        public AcademicYearStatus $status,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
    ) {}

    /** RN-CURSO-20. */
    public function isReadOnly(): bool
    {
        return $this->status->isReadOnly();
    }
}
