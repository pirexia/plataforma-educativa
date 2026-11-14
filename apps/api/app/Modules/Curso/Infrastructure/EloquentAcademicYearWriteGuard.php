<?php

namespace App\Modules\Curso\Infrastructure;

use App\Modules\Curso\Domain\AcademicYearReadOnlyException;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Modules\Curso\Domain\AcademicYearWriteGuard;

/**
 * ADR-057 §5.6: comprobación previa **consultiva**; no toma bloqueos ni
 * sustituye al disparador. Opera sobre la vista que ya tiene el llamador.
 */
final class EloquentAcademicYearWriteGuard implements AcademicYearWriteGuard
{
    public function admitsWrites(AcademicYearSummary $year): bool
    {
        return ! $year->isReadOnly();
    }

    public function assertWritable(AcademicYearSummary $year): void
    {
        if (! $this->admitsWrites($year)) {
            throw new AcademicYearReadOnlyException($year);
        }
    }
}
