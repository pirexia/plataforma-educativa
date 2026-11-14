<?php

namespace App\Modules\Acad\Application;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Piezas comunes de los servicios de escritura de la estructura académica:
 * unicidad del código entre hermanos vivos (`RN-ACAD-01`, también por carrera)
 * y posición por defecto (`RN-ACAD-04`).
 */
trait WritesStructure
{
    /**
     * Ejecuta la escritura en una transacción. Una violación de la unicidad
     * parcial `(padre, code) WHERE deleted_at IS NULL` (SQLSTATE 23505), la
     * de una carrera entre dos altas, se traduce al mismo `422 code_taken`
     * que la comprobación previa, nunca a `500` (`CA-ACAD-004`).
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function inTransaction(Closure $write): mixed
    {
        try {
            return DB::transaction($write);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw StructureErrors::codeTaken();
            }

            throw $e;
        }
    }

    /**
     * @param  Builder<*>  $siblings  hermanos vivos (el borrado lógico ya está excluido)
     */
    private function assertCodeFree(Builder $siblings, string $code, ?int $exceptId = null): void
    {
        $taken = $siblings
            ->where('code', $code)
            ->when($exceptId !== null, fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw StructureErrors::codeTaken();
        }
    }

    /**
     * `RN-ACAD-04`: la última posición viva entre hermanos + 1 (1 si no hay).
     *
     * @param  Builder<*>  $siblings
     */
    private function nextPosition(Builder $siblings): int
    {
        return ((int) $siblings->max('position')) + 1;
    }
}
