<?php

namespace App\Modules\Curso\Infrastructure\Jobs;

use App\Modules\Curso\Application\Rollover\RolloverExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * REQ-CURSO/operacion.md §4.1 (1.12b, `INV-012`): la copia (`RN-CURSO-69`), como `created_by` (`RN-CURSO-83`). Conexión `database_long`, cola `long`
 * (`operacion.md §1`). **Un solo intento, sin reentrega** (`RN-CURSO-84`): `tries = 1`; `timeout` menor que el
 * `retry_after` de su conexión, de modo que el *worker* mata el proceso antes de que la cola pueda volver a
 * entregarlo (lo comprueba un test). El reintento ante un error de serialización es interno al trabajo
 * (`RN-CURSO-86`). Carga útil: solo el identificador interno de la copia; el centro lo estampa el mecanismo de
 * colas consciente del centro (`0.7`, `RN-CURSO-81`), así que un *worker* de una versión posterior procesa
 * trabajos de la anterior (`CLAUDE.md §9`).
 *
 * `failed()` aplica la transición condicional de `RN-CURSO-84` (3): pendiente/en_curso ⇒ fallido internal_error; cualquier otro estado, nada.
 * Nunca se reencola desde `failed_jobs` (`queue:retry`): la salida de una copia atascada es la cancelación
 * (`RN-CURSO-85`).
 */
class RunAcademicYearRollover implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const CONNECTION = 'database_long';

    public int $tries = 1;

    /** Confirmado por la medición de `CA-CURSO-149` (`operacion.md §1`, 2026-10-21): 13,6 s la copia más pesada. */
    public int $timeout = 600;

    public function __construct(public readonly int $rolloverId)
    {
        $this->onConnection(self::CONNECTION);
        $this->onQueue('long');
    }

    public function handle(RolloverExecutor $executor): void
    {
        $executor->run($this->rolloverId);
    }

    public function failed(Throwable $exception): void
    {
        app(RolloverExecutor::class)->failedByQueue($this->rolloverId);
    }
}
