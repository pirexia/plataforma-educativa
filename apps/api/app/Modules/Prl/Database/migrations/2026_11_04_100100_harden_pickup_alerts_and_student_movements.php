<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PRL/datos.md §8.9 (`1.14b-d1`, `REQ-PRL-004`, `OPEN-FAM-UNIT-53` puntos 2 a 4, `RN-PRL-17` a `-19`). Fase *expand*,
 * aditiva y reversible: endurece en el motor lo que la aplicación ya garantiza. No toca ninguna fila.
 *
 * - `RN-PRL-18`: `REVOKE DELETE` sobre `pickup_alerts` a `plataforma_app` y `plataforma_platform` (como `student_movements`).
 * - `RN-PRL-17` (`OPEN-PRL-05`, todos los roles incluido el propietario): disparador `pickup_alerts_closed_immutable`,
 *   `BEFORE UPDATE FOR EACH ROW WHEN (OLD.status = 'cerrada')`, que rechaza cualquier `UPDATE` de una alerta cerrada
 *   (SQLSTATE `23001`, `restrict_violation`). No actúa sobre `DELETE` (lo impide el `REVOKE`; la purga de `1.23b` borrará
 *   con otro rol). Función en el esquema `app` (como `ADR-057`) con `search_path` fijado.
 * - `RN-PRL-19`: `CHECK student_movements_reason_requires_off_schedule_check (off_schedule OR off_schedule_reason IS NULL)`
 *   y FK compuesta `(tenant_id, student_person_id, corrects_movement_id)` → `student_movements (tenant_id, student_person_id, id)`
 *   (corrección del mismo alumno; `MATCH SIMPLE`: con `corrects_movement_id` nulo no se comprueba), con su `UNIQUE` destino y su
 *   índice completo (`AR-16`). La FK de `student_movements_tenant_corrects_movement_id_foreign` **se mantiene** (quitarla sería
 *   *contract*).
 *
 * `$withinTransaction = false`, `CREATE … CONCURRENTLY` y `NOT VALID` + `VALIDATE CONSTRAINT` (`migracion-segura`): las tablas
 * están en uso. Reintentable: cada paso es idempotente (un índice `INVALID` de un `CONCURRENTLY` interrumpido se descarta antes de
 * recrearlo). La aplicación ya impide ambas incoherencias (`DP-3`), así que `VALIDATE` debe pasar; si fallara, la migración
 * aborta y **no** se corrigen filas a mano (son evidencia).
 *
 * `down()` no se niega con datos: no destruye evidencia, solo quita restricciones y devuelve el `GRANT DELETE`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const CHECK = 'student_movements_reason_requires_off_schedule_check';

    private const FOREIGN_KEY = 'student_movements_corrects_same_student_foreign';

    private const UNIQUE_INDEX = 'student_movements_tenant_student_id_unique';

    private const FK_INDEX = 'student_movements_tenant_student_corrects_idx';

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('REVOKE DELETE ON pickup_alerts FROM plataforma_app, plataforma_platform');

        $owner->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app.assert_pickup_alert_not_closed() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $fn$
            BEGIN
                RAISE EXCEPTION 'pickup_alerts: una alerta cerrada es inmutable (RN-PRL-17)'
                    USING ERRCODE = 'restrict_violation';
            END
            $fn$
            SQL);

        $this->withLockTimeout(function () use ($owner): void {
            $owner->statement(<<<'SQL'
                CREATE OR REPLACE TRIGGER pickup_alerts_closed_immutable BEFORE UPDATE ON pickup_alerts
                    FOR EACH ROW WHEN (OLD.status = 'cerrada')
                    EXECUTE FUNCTION app.assert_pickup_alert_not_closed()
                SQL);

            if (! $this->constraintExists(self::CHECK)) {
                $owner->statement('ALTER TABLE student_movements ADD CONSTRAINT '.self::CHECK.' CHECK (off_schedule OR off_schedule_reason IS NULL) NOT VALID');
            }
        });

        $this->validate(self::CHECK);

        $this->createIndexConcurrently(self::UNIQUE_INDEX, 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.self::UNIQUE_INDEX.' ON student_movements (tenant_id, student_person_id, id)');
        $this->createIndexConcurrently(self::FK_INDEX, 'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::FK_INDEX.' ON student_movements (tenant_id, student_person_id, corrects_movement_id)');

        $this->withLockTimeout(function () use ($owner): void {
            if (! $this->constraintExists(self::FOREIGN_KEY)) {
                $owner->statement(<<<'SQL'
                    ALTER TABLE student_movements ADD CONSTRAINT student_movements_corrects_same_student_foreign
                        FOREIGN KEY (tenant_id, student_person_id, corrects_movement_id)
                        REFERENCES student_movements (tenant_id, student_person_id, id) NOT VALID
                    SQL);
            }
        });

        $this->validate(self::FOREIGN_KEY);
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $this->withLockTimeout(function () use ($owner): void {
            $owner->statement('ALTER TABLE student_movements DROP CONSTRAINT IF EXISTS '.self::FOREIGN_KEY);
            $owner->statement('ALTER TABLE student_movements DROP CONSTRAINT IF EXISTS '.self::CHECK);
            $owner->statement('DROP TRIGGER IF EXISTS pickup_alerts_closed_immutable ON pickup_alerts');
        });

        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::FK_INDEX);
        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::UNIQUE_INDEX);
        $owner->statement('DROP FUNCTION IF EXISTS app.assert_pickup_alert_not_closed()');
        $owner->statement('GRANT DELETE ON pickup_alerts TO plataforma_app, plataforma_platform');
    }

    private function constraintExists(string $name): bool
    {
        return DB::connection('pgsql_owner')->selectOne('SELECT 1 AS found FROM pg_constraint WHERE conname = ?', [$name]) !== null;
    }

    /** `VALIDATE` solo si la restricción sigue `NOT VALID` (reintento tras un fallo). Aborta con la fila infractora. */
    private function validate(string $name): void
    {
        $state = DB::connection('pgsql_owner')->selectOne('SELECT convalidated FROM pg_constraint WHERE conname = ?', [$name]);

        if ($state !== null && ! $state->convalidated) {
            DB::connection('pgsql_owner')->statement('ALTER TABLE student_movements VALIDATE CONSTRAINT '.$name);
        }
    }

    /** Un `CREATE INDEX CONCURRENTLY` interrumpido deja un índice no válido que `IF NOT EXISTS` daría por bueno. */
    private function createIndexConcurrently(string $index, string $sql): void
    {
        $owner = DB::connection('pgsql_owner');

        if ($owner->selectOne('SELECT 1 AS found FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND NOT i.indisvalid', [$index]) !== null) {
            $owner->statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }

        $owner->statement($sql);
    }

    /** `lock_timeout` breve (y restablecido) para no encolar las escrituras detrás de un `ALTER TABLE` que espera un bloqueo. */
    private function withLockTimeout(Closure $work): void
    {
        $owner = DB::connection('pgsql_owner');
        $previous = $owner->selectOne("SELECT current_setting('lock_timeout') AS v")->v;
        $owner->statement("SET lock_timeout = '5s'");

        try {
            $work();
        } finally {
            $owner->statement("SET lock_timeout = '{$previous}'");
        }
    }
};
