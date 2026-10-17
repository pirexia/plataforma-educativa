<?php

namespace App\Modules\Curso\Infrastructure;

use App\Modules\Curso\Domain\AcademicYearSharedLock;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Modules\Curso\Domain\Models\AcademicYear;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * RN-CURSO-55 a -57, INV-001: `SELECT … FOR SHARE` por `TenantScope` y RLS,
 * una consulta por llamada (sin el memo de `AcademicYearDirectory`). Sin
 * estado: se registra con `bind`, no con `scoped`.
 */
final class EloquentAcademicYearSharedLock implements AcademicYearSharedLock
{
    public function lockForShareById(int $id): ?AcademicYearSummary
    {
        // RN-CURSO-56: fuera de transacción el FOR SHARE se liberaría al
        // terminar la sentencia y la garantía de RN-CURSO-58 desaparecería
        // en silencio: error de programación, ninguna consulta.
        if (DB::transactionLevel() < 1) {
            throw new LogicException('AcademicYearSharedLock::lockForShareById() exige una transacción abierta (RN-CURSO-56).');
        }

        return AcademicYear::query()->whereKey($id)->sharedLock()->first()?->toSummary();
    }
}
