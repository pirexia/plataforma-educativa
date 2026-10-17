<?php

namespace App\Modules\Curso\Domain;

use Carbon\CarbonImmutable;

/**
 * RN-CURSO-49 (1.11d-a): el cambio de fechas que se valida. `year` es el
 * resumen del curso **antes** del cambio; `startsOn`/`endsOn` son las fechas
 * **resultantes** (la que el `PATCH` no envía es la actual).
 */
final readonly class AcademicYearDateChange
{
    public function __construct(
        public AcademicYearSummary $year,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
    ) {}
}
