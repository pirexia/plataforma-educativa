<?php

namespace App\Modules\Curso\Application\Rollover;

use App\Modules\Curso\Domain\AcademicYearSummary;

/** REQ-CURSO/funcional.md `RN-CURSO-72` (1.12b, `OPEN-CURSO-30` = A). */
final class RolloverDates
{
    public static function offsetDays(AcademicYearSummary $source, AcademicYearSummary $target): int
    {
        return (int) $source->startsOn->startOfDay()->diffInDays($target->startsOn->startOfDay(), false);
    }
}
