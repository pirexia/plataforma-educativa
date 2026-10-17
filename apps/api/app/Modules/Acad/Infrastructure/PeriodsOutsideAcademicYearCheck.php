<?php

namespace App\Modules\Acad\Infrastructure;

use App\Modules\Acad\Domain\Models\AcademicPeriod;
use App\Modules\Curso\Domain\AcademicYearDateChange;
use App\Modules\Curso\Domain\AcademicYearDateChangeCheck;
use App\Modules\Curso\Domain\AcademicYearDateChangeFailure;

/**
 * REQ-ACAD/funcional.md `RN-ACAD-62` (1.11d-a, `OPEN-ACAD-24` = B): validación
 * del cambio de fechas de un curso académico que `acad` registra en `curso`.
 * Rechaza un `PATCH` de fechas que dejaría fuera de ellas algún período
 * lectivo vivo del curso (cualquiera de sus etapas), bordes incluidos, como
 * `RN-ACAD-57`. Un período borrado no cuenta.
 *
 * `curso` la ejecuta con el `FOR UPDATE` del curso retenido (`RN-CURSO-51`):
 * una única consulta agregada de solo lectura, sin ningún bloqueo de fila
 * (riesgo de interbloqueo, `RN-CURSO-58`), por el índice
 * `(tenant_id, academic_year_id)`. El filtro por módulo contratado lo aplica
 * `curso` (`RN-CURSO-52`), no esta clase.
 *
 * El fallo no lleva `public_id`, nombre ni etapa de ningún período: quien hace
 * el `PATCH` (`curso_academico.actualizar`) puede no tener permisos de `acad`
 * (`RN-CURSO-53`, `ADR-035 §5`). `params`: cuántos quedarían fuera y las
 * fechas que el curso debe incluir (menor inicio y mayor fin de TODOS los
 * períodos vivos del curso).
 */
final class PeriodsOutsideAcademicYearCheck implements AcademicYearDateChangeCheck
{
    public function moduleCode(): string
    {
        return 'acad';
    }

    public function check(AcademicYearDateChange $change): ?AcademicYearDateChangeFailure
    {
        $newStart = $change->startsOn->toDateString();
        $newEnd = $change->endsOn->toDateString();

        $row = AcademicPeriod::query()
            ->where('academic_year_id', $change->year->id)
            ->selectRaw('count(*) filter (where starts_on < ? or ends_on > ?) as outside, min(starts_on) as first_start, max(ends_on) as last_end', [$newStart, $newEnd])
            ->first();

        $count = (int) ($row?->getAttribute('outside') ?? 0);

        if ($row === null || $count === 0) {
            return null;
        }

        $params = [
            'count' => $count,
            'periods_starts_on' => (string) $row->getAttribute('first_start'),
            'periods_ends_on' => (string) $row->getAttribute('last_end'),
        ];

        return new AcademicYearDateChangeFailure(
            'acad.conflict.periods_outside_academic_year',
            __('acad.conflict.periods_outside_academic_year', $params),
            $params,
        );
    }
}
