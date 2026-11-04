<?php

namespace App\Modules\Prl\Application;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PRL/funcional.md `RN-PRL-16`, `OPEN-PRL-04` A (1.14b-d1, `REQ-PRL-004`): la transacción de escritura de la puerta con espera
 * de bloqueo **acotada** (`SET LOCAL lock_timeout`, `config('prl.lock_timeout_ms')`, 3000 ms) y reintento de la transacción
 * completa ante interbloqueo (`40P01`) o fallo de serialización (`40001`), hasta 3 intentos en total. Sin efectos entre
 * intentos: cada uno se deshace entero antes del siguiente. Superado el tiempo de espera (`55P03`) o agotados los intentos,
 * `503 prl.errors.busy` sin haber escrito nada. Cualquier otro error se propaga tal cual (p. ej. la violación del índice de
 * idempotencia, que traduce el servicio).
 */
final class LockedTransaction
{
    public const MAX_ATTEMPTS = 3;

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function run(Closure $work): mixed
    {
        $timeout = max(1, (int) config('prl.lock_timeout_ms'));
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(function () use ($work, $timeout): mixed {
                    DB::statement("SET LOCAL lock_timeout = {$timeout}");

                    return $work();
                });
            } catch (QueryException $e) {
                $state = $e->errorInfo[0] ?? null;

                if ($state === '55P03') {
                    throw PrlErrors::busy();
                }

                if (in_array($state, ['40P01', '40001'], true)) {
                    if ($attempt >= self::MAX_ATTEMPTS) {
                        throw PrlErrors::busy();
                    }

                    continue;
                }

                throw $e;
            }
        }
    }
}
