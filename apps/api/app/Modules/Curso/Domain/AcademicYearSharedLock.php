<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md §5.6.1, RN-CURSO-55 a -59 (1.11d-a,
 * `OPEN-CURSO-25` = B): lectura del curso que retiene `FOR SHARE` sobre su
 * fila hasta el final de la transacción más externa. Quien valida un dato
 * contra las fechas del curso y lo escribe (en `acad`, un período lectivo)
 * las lee con esta operación, **antes de bloquear filas propias**, y no con
 * `AcademicYearDirectory` (memoizado y sin bloqueo).
 */
interface AcademicYearSharedLock
{
    /**
     * Resumen leído bajo `FOR SHARE`. `null` si no existe, está borrado o es
     * de otro centro (no bloquea nada). Un curso `cerrado`/`archivado` sí se
     * devuelve. Sin memoización.
     *
     * @throws \LogicException si no hay transacción abierta (RN-CURSO-56)
     */
    public function lockForShareById(int $id): ?AcademicYearSummary;
}
