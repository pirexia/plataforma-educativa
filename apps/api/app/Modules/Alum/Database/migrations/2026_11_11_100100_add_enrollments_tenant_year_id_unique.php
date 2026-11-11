<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ALUM/datos.md §1.3 y §3 (1.15-c, `REQ-ALUM-003`, issue #602), fase *expand*. Crea `UNIQUE (tenant_id,
 * academic_year_id, id)` en `enrollments`: destino de la FK compuesta `(tenant_id, academic_year_id, enrollment_id)` de
 * `group_change_requests`, que impide que el curso académico de la solicitud diverja del de la matrícula (patrón
 * `courses_tenant_year_id_unique`). Aditivo y compatible con la versión anterior, que no lo usa.
 *
 * `$withinTransaction = false`: el índice se crea con `CONCURRENTLY` (no bloquea escrituras) y luego se promueve a
 * restricción con `ADD CONSTRAINT … UNIQUE USING INDEX` (bloqueo breve, sin reescaneo). Idempotente y con `lock_timeout`
 * reintentado, igual que `2026_10_18_100100_add_group_uniques_for_teaching_assignments` (revisión de 1.12-a).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLE = 'enrollments';

    private const NAME = 'enrollments_tenant_year_id_unique';

    private int $maxAttempts = 5;

    private string $lockTimeout = '3s';

    private int $retryPauseMs = 2000;

    /** SQLSTATE `lock_not_available`: vence `lock_timeout`. */
    private const LOCK_NOT_AVAILABLE = '55P03';

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        if ($this->constraintExists($owner)) {
            return;
        }

        $table = self::TABLE;
        $name = self::NAME;

        // Fase 1: el índice. Uno válido de un intento anterior se reutiliza; uno inválido se descarta.
        $this->withLockTimeout($owner, function () use ($owner, $table, $name): void {
            $state = $owner->selectOne(
                'SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND i.indrelid = ?::regclass',
                [$name, $table],
            );

            if ($state !== null && $state->indisvalid) {
                return;
            }

            $owner->statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            $owner->statement("CREATE UNIQUE INDEX CONCURRENTLY {$name} ON {$table} (tenant_id, academic_year_id, id)");
        });

        // Fase 2: promoverlo a restricción (bloqueo breve); su reintento no toca el índice.
        $this->withLockTimeout($owner, function () use ($owner, $table, $name): void {
            $owner->statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} UNIQUE USING INDEX {$name}");
        });
    }

    /** Por nombre y por tabla: un homónimo en otra tabla no cuenta como ya aplicado. */
    private function constraintExists(Connection $owner): bool
    {
        return $owner->selectOne(
            'SELECT 1 AS ok FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass',
            [self::NAME, self::TABLE],
        ) !== null;
    }

    /** Ejecuta el paso con `lock_timeout`, repetido hasta `$maxAttempts` veces si vence; `lock_timeout` se restablece siempre. */
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

    public function down(): void
    {
        // Aditiva; la versión anterior convive con ella. Falla si `group_change_requests` ya depende de ella.
        DB::connection('pgsql_owner')->statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS '.self::NAME);
    }
};
