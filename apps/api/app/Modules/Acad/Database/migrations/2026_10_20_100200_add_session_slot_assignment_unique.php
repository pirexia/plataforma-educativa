<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §9.3 (1.12-a, `REQ-ACAD-002`), issue #446 (db-reviewer d). Cinturón de seguridad del
 * motor: dos sesiones vivas no pueden compartir franja **y** asignación docente, `UNIQUE (tenant_id,
 * time_slot_id, teaching_assignment_id) WHERE deleted_at IS NULL`.
 *
 * No añade una regla nueva: la especificación ya no admite esa pareja, porque dos sesiones de la misma
 * asignación comparten profesor y la misma franja las hace coincidir (`RN-ACAD-123`, conflicto de
 * profesor). La validación sigue siendo del servidor, bajo el `FOR UPDATE` del ancla (`RN-ACAD-125`,
 * `INV-010`) y responde `422 timetable_conflict`; este índice solo impide que un camino que se salte esa
 * validación (SQL a mano, un bug futuro) deje la pareja repetida.
 *
 * Aditivo y compatible con la versión anterior (que nunca crea la pareja). `$withinTransaction = false`:
 * `CREATE UNIQUE INDEX CONCURRENTLY`, con `lock_timeout` y reintento acotado. Si una instalación tuviera ya
 * una pareja repetida (no debería: el servicio la rechaza), el índice queda inválido y la migración falla en
 * voz alta; se descarta el inválido antes de reintentar.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'timetable_sessions_tenant_slot_assignment_unique';

    private int $maxAttempts = 5;

    private string $lockTimeout = '3s';

    private int $retryPauseMs = 2000;

    /** SQLSTATE `lock_not_available`: vence `lock_timeout`. */
    private const LOCK_NOT_AVAILABLE = '55P03';

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        // Antes de construir nada: una pareja repetida haría fallar el índice y dejaría uno inválido. Se avisa con un
        // mensaje claro y las primeras filas a revisar (el servicio las rechaza; solo SQL a mano o un bug las crea).
        // La tabla tiene RLS forzada, también para el propietario: se mira centro a centro (los ids, por la conexión de plataforma).
        $duplicates = [];

        foreach (DB::connection('pgsql_platform')->table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            $owner->statement("select set_config('app.tenant_id', ?, false)", [(string) $tenantId]);

            foreach ($owner->select(
                'SELECT tenant_id, time_slot_id, teaching_assignment_id, count(*) AS n FROM timetable_sessions'
                .' WHERE deleted_at IS NULL GROUP BY tenant_id, time_slot_id, teaching_assignment_id HAVING count(*) > 1 LIMIT 5'
            ) as $row) {
                $duplicates[] = $row;
            }

            if (count($duplicates) >= 5) {
                break;
            }
        }

        $owner->statement("select set_config('app.tenant_id', '', false)");

        if ($duplicates !== []) {
            $sample = implode('; ', array_map(
                fn (object $d): string => "tenant {$d->tenant_id}, franja {$d->time_slot_id}, asignación {$d->teaching_assignment_id} ({$d->n} sesiones)",
                array_slice($duplicates, 0, 5),
            ));

            throw new RuntimeException(
                'No se puede crear '.self::INDEX.': hay sesiones vivas con la misma franja y la misma asignación docente (RN-ACAD-123). '
                ."Bórralas o corrígelas y relanza la migración. Primeras: {$sample}."
            );
        }

        $this->withLockTimeout($owner, function () use ($owner): void {
            // Un índice inválido de un intento anterior interrumpido se descarta antes de recrearlo.
            $valid = $owner->selectOne(
                'SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND i.indrelid = ?::regclass',
                [self::INDEX, 'timetable_sessions'],
            );

            if ($valid !== null && ! $valid->indisvalid) {
                $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
            }

            $owner->statement(
                'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX
                .' ON timetable_sessions (tenant_id, time_slot_id, teaching_assignment_id) WHERE deleted_at IS NULL'
            );
        });
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $this->withLockTimeout($owner, fn () => $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX));
    }

    /** Reintenta el paso, hasta `$maxAttempts` veces, si `lock_timeout` vence por un bloqueo ajeno. */
    private function withLockTimeout(Connection $owner, Closure $step): void
    {
        for ($attempt = 1; ; $attempt++) {
            $owner->statement("SET lock_timeout = '{$this->lockTimeout}'");

            try {
                $step();

                return;
            } catch (QueryException $e) {
                if ($attempt >= $this->maxAttempts || ($e->errorInfo[0] ?? null) !== self::LOCK_NOT_AVAILABLE) {
                    throw $e;
                }

                usleep($this->retryPauseMs * 1000);
            } finally {
                $owner->statement('RESET lock_timeout');
            }
        }
    }
};
