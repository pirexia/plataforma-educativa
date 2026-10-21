<?php

namespace App\Modules\Curso\Infrastructure;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-86`, `CA-CURSO-158` (1.12b): primer uso de
 * aislamiento `REPEATABLE READ` del producto, para que la copia lea el origen como
 * una foto coherente aunque se siga editando (`RN-CURSO-69`).
 *
 *  1. Exige `DB::transactionLevel() === 0`; con una transacción abierta lanza
 *     `LogicException`: en PostgreSQL el aislamiento solo se fija antes de la primera
 *     sentencia de la transacción y no hay forma correcta de «subirlo» dentro de otra.
 *  2. Abre la transacción y su **primera sentencia** es `SET TRANSACTION ISOLATION
 *     LEVEL REPEATABLE READ`.
 *  3. Ante un error de serialización (`40001`) repite la función completa hasta 3
 *     intentos en total y después propaga el error.
 *
 * **Excepción de pruebas**: con `curso.rollover.allow_nested_transaction` a `true`
 * (nunca en producción), si `transactionLevel() > 0` ejecuta la función en un punto de
 * guardado con el aislamiento heredado y sin reintentos.
 */
final class RepeatableReadTransaction
{
    public const MAX_ATTEMPTS = 3;

    private const SERIALIZATION_FAILURE = '40001';

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() > 0) {
            if ($this->nestedAllowed()) {
                return $connection->transaction($callback);
            }

            throw new LogicException('RepeatableReadTransaction exige que no haya una transacción abierta (RN-CURSO-86): el aislamiento solo se fija antes de la primera sentencia.');
        }

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $connection->transaction(function () use ($connection, $callback): mixed {
                    $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

                    return $callback();
                });
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === self::SERIALIZATION_FAILURE && $attempt < self::MAX_ATTEMPTS) {
                    continue;
                }

                throw $e;
            }
        }
    }

    private function nestedAllowed(): bool
    {
        return (bool) config('curso.rollover.allow_nested_transaction', false) && ! app()->isProduction();
    }
}
