<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §3.2 (1.12-a, `REQ-ACAD-002`), issue #446 (db-reviewer c). Retira, en cinco
 * tablas, el índice `(tenant_id, academic_year_id)` que crea `tenantForeignId()` porque otro
 * índice no parcial de la misma tabla empieza por esas mismas dos columnas y ya sirve cada
 * consulta que él serviría, incluida la comprobación de integridad de la FK `(tenant_id,
 * academic_year_id) → academic_years` ante un borrado físico del curso:
 *
 *  - `teaching_assignments`: `(tenant_id, academic_year_id, group_id)` y el único `(…, id)`.
 *  - `group_tutors`:         `(tenant_id, academic_year_id, group_id)`.
 *  - `timetable_frames`:     `(tenant_id, academic_year_id, education_level_id)` y el único `(…, id)`.
 *  - `time_slots`:           `(tenant_id, academic_year_id, timetable_frame_id)` y el único `(…, id)`.
 *  - `timetable_sessions`:   `(…, teaching_assignment_id)` y `(…, time_slot_id)`.
 *
 * Medido con `EXPLAIN (ANALYZE, BUFFERS)` sobre 30 centros × 4 cursos (hasta 180.000 filas por
 * tabla): para `WHERE tenant_id = ? AND academic_year_id = ?` (el listado), `SELECT … FOR KEY SHARE`
 * (lo que lanza la FK) y `count(*)`, el plan es el mismo (Bitmap/Index Scan sobre el índice ancho) y
 * lee 0-1 páginas de índice más. Cada índice retirado cuesta una escritura extra por INSERT/UPDATE/DELETE
 * y su espacio. **No se retiran** los de `facility_bookings` (ningún otro índice empieza por
 * `(tenant_id, academic_year_id)`) ni el de `timetables` (fuera del alcance del issue).
 *
 * Fase *contract* de un índice, no de una columna: ninguna versión del código nombra estos índices, así
 * que la versión anterior convive con la nueva. `down()` los recrea. `$withinTransaction = false`: se
 * usa `DROP/CREATE INDEX CONCURRENTLY`, con `lock_timeout` y reintento acotado (como `2026_10_18_100100`).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var list<string> */
    private const TABLES = [
        'teaching_assignments',
        'group_tutors',
        'timetable_frames',
        'time_slots',
        'timetable_sessions',
    ];

    private int $maxAttempts = 5;

    private string $lockTimeout = '3s';

    private int $retryPauseMs = 2000;

    /** SQLSTATE `lock_not_available`: vence `lock_timeout`. */
    private const LOCK_NOT_AVAILABLE = '55P03';

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        foreach (self::TABLES as $table) {
            $index = TenantMigration::indexName($table, 'academic_year_id');

            $this->withLockTimeout($owner, fn () => $owner->statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}"));
        }
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        foreach (self::TABLES as $table) {
            $index = TenantMigration::indexName($table, 'academic_year_id');

            // Un índice inválido de un intento interrumpido se descarta antes de recrearlo.
            $this->withLockTimeout($owner, function () use ($owner, $table, $index): void {
                $valid = $owner->selectOne(
                    'SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND i.indrelid = ?::regclass',
                    [$index, $table],
                );

                if ($valid !== null && ! $valid->indisvalid) {
                    $owner->statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
                }

                $owner->statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON {$table} (tenant_id, academic_year_id)");
            });
        }
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
