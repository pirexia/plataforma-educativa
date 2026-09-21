<?php

namespace App\Modules\Backoffice\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `REQ-BO-004`, hallazgo Alta de `funcional.md §5.9.6`, resuelto en
 * `§5.9.7` (decisión del usuario, 2026-09-16). `RN-BO-98`, `CA-BO-166`.
 *
 * **Qué arregla**: `queue:prune-failed` lleva programada desde `0.7`
 * como segunda capa del issue #73 (no conservar más de 24 horas los
 * *payloads* cifrados de `SendPasswordResetEmail`/
 * `SendAccountLockedEmail`, que llevan un token de un solo uso), pero usa
 * `config('queue.failed.database')` — la conexión por defecto,
 * `plataforma_app` — que tiene `REVOKE DELETE` sobre `failed_jobs` desde
 * la migración de endurecimiento de `0.7`. **Esa purga no ha borrado ni
 * una fila jamás.**
 *
 * **La conexión**: `pgsql_platform`, el único rol con `DELETE` sobre
 * `failed_jobs` (`RN-BO-86`). **No** se corrige apuntando
 * `queue.failed.database` ahí: eso le daría al *worker* —que sólo
 * necesita `INSERT`— una conexión con `BYPASSRLS`, deshaciendo la mitad
 * del endurecimiento de `0.7` (`datos.md §14.3`).
 *
 * **El plazo**: constante en `config('backoffice.failed_jobs_retention_hours')`,
 * deliberadamente sin `env()` (`RN-BO-98`).
 *
 * **Sin contexto ni `RunsPerTenant`**: `failed_jobs` es tabla de
 * plataforma (`shared_tables.platform`), corre fuera de todo tenant —
 * mismo precedente que `CloseOrphanedPlatformSessions`
 * (`operacion.md §6.3`).
 *
 * **Sin `runAsPlatform()`**: sigue ese mismo precedente —escritura
 * directa por `pgsql_platform`— y no inventa una forma nueva (issue
 * #219, Baja, documentado sin corregir).
 *
 * **Ejecuta el `DELETE` ella misma, no lo encola**, a diferencia de
 * `bo:purge-idempotency-keys` y `bo:close-orphaned-sessions`: hoy no hay
 * ningún *worker* desplegado (issue #128), así que una purga encolada no
 * purgaría nada — repetiría el mismo defecto con otra forma. Y un
 * `DELETE` acotado por fecha no es trabajo pesado (`INV-012` no obliga a
 * encolar lo que ni siquiera ocurre dentro de una petición HTTP).
 *
 * **Sin auditoría**: es mantenimiento de retención sin sujeto, igual que
 * las otras dos purgas del módulo (`ADR-046 §6.2`). El recuento de filas
 * borradas va al registro de la tarea.
 */
class PurgeFailedJobsCommand extends Command
{
    protected $signature = 'bo:purge-failed-jobs';

    protected $description = 'Borra físicamente las filas de failed_jobs más antiguas que la retención (RN-BO-98, funcional.md §5.9.7)';

    public function handle(): int
    {
        $hours = (int) config('backoffice.failed_jobs_retention_hours');

        $deleted = DB::connection('pgsql_platform')->table('failed_jobs')
            ->where('failed_at', '<', now()->subHours($hours))
            ->delete();

        $this->info("failed_jobs: {$deleted} fila(s) purgada(s) (retención de {$hours}h).");

        return self::SUCCESS;
    }
}
