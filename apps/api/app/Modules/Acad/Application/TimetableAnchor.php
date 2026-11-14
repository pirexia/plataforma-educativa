<?php

namespace App\Modules\Acad\Application;

use App\Modules\Acad\Domain\Models\Timetable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/funcional.md §18.4.4-§18.4.5 (`RN-ACAD-115`, `RN-ACAD-125`),
 * `datos.md §9.5`: la fila `timetables` (una por centro y curso académico) es
 * el ancla que serializa toda escritura de sesiones. El motor no garantiza los
 * conflictos (sin `btree_gist`); quien escribe sesiones toma `lock()` antes de
 * leer con qué compara. Nunca `FOR UPDATE` sobre la fila del curso.
 */
final class TimetableAnchor
{
    /**
     * Crear-o-leer bajo el único: la carrera se resuelve releyendo, nunca
     * `500`. El alta va en su propio punto de guardado, de modo que la
     * violación (`23505`) no aborta la transacción en curso.
     */
    public function ensure(int $academicYearId): void
    {
        if (Timetable::query()->where('academic_year_id', $academicYearId)->exists()) {
            return;
        }

        try {
            DB::transaction(fn (): Timetable => Timetable::create(['academic_year_id' => $academicYearId]));
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '23505') {
                throw $e;
            }
        }
    }

    /** `FOR UPDATE` del ancla del curso (la crea si no existe). Solo dentro de una transacción. */
    public function lock(int $academicYearId): Timetable
    {
        $this->ensure($academicYearId);

        return Timetable::query()->where('academic_year_id', $academicYearId)->lockForUpdate()->firstOrFail();
    }
}
