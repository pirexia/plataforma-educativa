<?php

namespace App\Modules\Core\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Issue #128. Comprobación de salud de la cola `database`: detecta que no
 * hay un *worker* consumiendo (o que va muy por detrás) mirando el trabajo
 * disponible más antiguo, sin depender de ningún proceso concreto. Un
 * trabajo con `reserved_at` nulo y `available_at` anterior al umbral lleva
 * más de N minutos esperando a ser tomado: con un worker sano esa espera
 * son segundos.
 *
 * Código de salida: 0 = sana, 1 = algún trabajo disponible supera el
 * umbral. Sirve igual como `HealthCmd` del contenedor del worker, como
 * comprobación de monitorización (RUNBOOK.md §2.5) o a mano.
 *
 * Con `--queues=a,b` (revisión de seguridad B3 de 1.12b) solo mira las colas del proceso que lo llama: `worker`
 * y `worker-long` leen la misma tabla `jobs` con procesos distintos, y sin filtro una cola `long` atascada
 * marcaría como no sano al worker general (y al revés).
 *
 * Solo lee la tabla `jobs`: `failed_jobs` está revocada para
 * `plataforma_app` (0.7, ADR-033 §7) y la lee REQ-BO por otra conexión.
 */
class QueueHealthCommand extends Command
{
    protected $signature = 'core:queue-health {--max-age= : Minutos de espera máxima de un trabajo disponible (por defecto core.queue_health_max_age_minutes)} {--queues= : Colas que consume ESTE proceso, separadas por comas (por defecto, todas). Cada worker pasa las suyas: un atasco en la cola de otro worker no debe marcar a este como no sano}';

    protected $description = 'Comprueba que la cola database tiene consumidor: falla si hay trabajos disponibles sin tomar desde hace más del umbral (issue #128)';

    public function handle(): int
    {
        $option = $this->option('max-age');
        $maxAge = is_numeric($option) ? (int) $option : (int) config('core.queue_health_max_age_minutes', 5);
        $table = (string) config('queue.connections.database.table', 'jobs');
        $now = time();
        $queues = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('queues'))), static fn (string $q): bool => $q !== ''));

        $rows = DB::table($table)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now)
            ->when($queues !== [], static fn ($query) => $query->whereIn('queue', $queues))
            ->groupBy('queue')
            ->selectRaw('queue, count(*) as pending, min(available_at) as oldest')
            ->orderBy('queue')
            ->get();

        $stale = false;

        foreach ($rows as $row) {
            $ageMinutes = intdiv(max(0, $now - (int) $row->oldest), 60);
            $isStale = $ageMinutes >= $maxAge;
            $stale = $stale || $isStale;

            $this->line(sprintf(
                '%s queue=%s pending=%d oldest_age_min=%d',
                $isStale ? 'STALE' : 'ok',
                $row->queue,
                (int) $row->pending,
                $ageMinutes,
            ));
        }

        if ($rows->isEmpty()) {
            $this->line('ok no pending jobs');
        }

        if ($stale) {
            $this->error(sprintf('Hay trabajos disponibles sin tomar desde hace %d minutos o más: el worker de colas no está consumiendo (issue #128).', $maxAge));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
