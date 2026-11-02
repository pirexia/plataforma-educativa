<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PRL/datos.md §8.4, §8.6 (1.14b-b, `REQ-PRL-004`, `ADR-068 §5.1`). Movimientos de alumnado en la puerta: **solo anexar**
 * (`tenantTableAppendOnly()`: sin `deleted_at` ni `updated_at`, `REVOKE UPDATE, DELETE` del rol de aplicación **y** del de
 * plataforma, `RN-PRL-04`). Un error se corrige con un movimiento nuevo que referencia al anterior (`corrects_movement_id`,
 * con `correction_reason`); el original no se toca.
 *
 * - **Ninguna columna guarda personas autorizadas ni el estado de una autorización** (`RN-PRL-01`): `basis` y las dos
 *   referencias son la **evidencia** de con qué se entregó, resuelta por `AuthorizedPickupList` en la transacción. Las claves
 *   foráneas a `guardian_student_links` y `pickup_authorizations` están permitidas en base de datos; la lectura va por la
 *   interfaz (`INV-007`). Ambas son `RESTRICT` (la acción por defecto): una autorización que respalda una salida no se borra.
 * - Los `CHECK` hacen cumplir en el motor las coherencias del dominio: fuera de horario ⇒ motivo (`otro` ⇒ nota); toda salida
 *   lleva persona que recoge (la salida autónoma de `1.14b-d` relajará el `CHECK` con su especificación); persona, base y método
 *   de verificación aparecen juntos o ninguno; la base casa con la referencia presente; una corrección lleva motivo.
 * - Idempotencia (`RN-PRL-09`): `UNIQUE (tenant_id, recorded_by, idempotency_key) WHERE idempotency_key IS NOT NULL` y el *hash*
 *   del cuerpo; por índice y no por comprobación (dos `POST` simultáneos con la misma clave chocarían).
 * - Sin `academic_year_id` (`OPEN-FAM-UNIT-39`): son hechos fechados; un curso cerrado no puede bloquear la puerta en verano.
 * - Índices (`datos.md §8.6`): historial de un alumno y salidas del día, ambos por `(occurred_at DESC, id DESC)`. Toda clave
 *   foránea compuesta tiene su índice **completo** con prefijo `(tenant_id, columna)` (`AR-16`).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin `CONCURRENTLY` son inocuos. Debe ejecutarse después de
 * las migraciones de `fam_unit` (`guardian_student_links`, `pickup_authorizations`).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTableAppendOnly('student_movements', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            // Sin índice propio: el del historial de un alumno (abajo) ya empieza por (tenant_id, student_person_id) y es completo.
            TenantMigration::tenantForeignId($table, 'student_person_id', 'people', withIndex: false);
            $table->text('direction');
            $table->boolean('off_schedule');
            $table->text('off_schedule_reason')->nullable();
            $table->text('notes')->nullable();

            // Referencias opcionales (nulas en una entrada): se declaran a mano, `tenantForeignId()` fuerza NOT NULL.
            foreach (['picked_up_by_person_id' => 'people', 'guardian_student_link_id' => 'guardian_student_links', 'pickup_authorization_id' => 'pickup_authorizations'] as $column => $referenced) {
                $table->unsignedBigInteger($column)->nullable();
                $table->foreign(['tenant_id', $column], "student_movements_tenant_{$column}_foreign")->references(['tenant_id', 'id'])->on($referenced);
                $table->index(['tenant_id', $column], TenantMigration::indexName('student_movements', $column));
            }

            $table->text('basis')->nullable();
            $table->text('verification_method')->nullable();
            TenantMigration::tenantForeignId($table, 'delivered_by', 'users');
            TenantMigration::tenantForeignId($table, 'recorded_by', 'users');
            $table->unsignedBigInteger('corrects_movement_id')->nullable();
            $table->text('correction_reason')->nullable();
            $table->text('idempotency_key')->nullable();
            $table->text('idempotency_body_hash')->nullable();
            $table->timestampTz('occurred_at');
        });

        $owner = DB::connection('pgsql_owner');

        // La corrección apunta a otro movimiento de la misma tabla: la clave compuesta necesita el UNIQUE (tenant_id, id), que
        // `tenantTableAppendOnly()` crea después del cierre de columnas, así que se añade aquí.
        $owner->statement(<<<'SQL'
            ALTER TABLE student_movements
                ADD CONSTRAINT student_movements_tenant_corrects_movement_id_foreign
                    FOREIGN KEY (tenant_id, corrects_movement_id) REFERENCES student_movements (tenant_id, id)
            SQL);
        $owner->statement(sprintf('CREATE INDEX %s ON student_movements (tenant_id, corrects_movement_id)', TenantMigration::indexName('student_movements', 'corrects_movement_id')));

        // datos.md §8.6: historial de un alumno (cursor) y salidas del día en conserjería.
        $owner->statement('CREATE INDEX student_movements_student_history_idx ON student_movements (tenant_id, student_person_id, occurred_at DESC, id DESC)');
        $owner->statement('CREATE INDEX student_movements_day_idx ON student_movements (tenant_id, occurred_at DESC, id DESC)');

        // RN-PRL-09: una clave por usuario que registra.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX student_movements_idempotency_unique
                ON student_movements (tenant_id, recorded_by, idempotency_key)
                WHERE idempotency_key IS NOT NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE student_movements
                ADD CONSTRAINT student_movements_direction_check
                    CHECK (direction IN ('entrada', 'salida')),
                ADD CONSTRAINT student_movements_off_schedule_reason_check
                    CHECK (off_schedule_reason IS NULL OR off_schedule_reason IN ('medico', 'familiar', 'actividad', 'retraso', 'otro')),
                ADD CONSTRAINT student_movements_off_schedule_needs_reason_check
                    CHECK (NOT off_schedule OR off_schedule_reason IS NOT NULL),
                ADD CONSTRAINT student_movements_other_reason_needs_notes_check
                    CHECK (off_schedule_reason IS DISTINCT FROM 'otro' OR notes IS NOT NULL),
                ADD CONSTRAINT student_movements_notes_length_check
                    CHECK (notes IS NULL OR length(notes) <= 500),
                ADD CONSTRAINT student_movements_exit_needs_person_check
                    CHECK (direction = 'entrada' OR picked_up_by_person_id IS NOT NULL),
                ADD CONSTRAINT student_movements_basis_check
                    CHECK (basis IS NULL OR basis IN ('tutor', 'tercero', 'puntual')),
                ADD CONSTRAINT student_movements_verification_method_check
                    CHECK (verification_method IS NULL OR verification_method IN ('foto', 'documento', 'foto_y_documento')),
                ADD CONSTRAINT student_movements_person_basis_method_check
                    CHECK ((picked_up_by_person_id IS NULL) = (basis IS NULL) AND (basis IS NULL) = (verification_method IS NULL)),
                ADD CONSTRAINT student_movements_basis_reference_check
                    CHECK (
                        (basis IS NULL AND guardian_student_link_id IS NULL AND pickup_authorization_id IS NULL)
                        OR (basis = 'tutor' AND guardian_student_link_id IS NOT NULL AND pickup_authorization_id IS NULL)
                        OR (basis IN ('tercero', 'puntual') AND pickup_authorization_id IS NOT NULL AND guardian_student_link_id IS NULL)
                    ),
                ADD CONSTRAINT student_movements_correction_coherence_check
                    CHECK ((corrects_movement_id IS NULL) = (correction_reason IS NULL)),
                ADD CONSTRAINT student_movements_correction_reason_length_check
                    CHECK (correction_reason IS NULL OR length(correction_reason) <= 500),
                ADD CONSTRAINT student_movements_idempotency_key_length_check
                    CHECK (idempotency_key IS NULL OR length(idempotency_key) <= 255),
                ADD CONSTRAINT student_movements_idempotency_coherence_check
                    CHECK ((idempotency_key IS NULL) = (idempotency_body_hash IS NULL))
            SQL);
    }

    public function down(): void
    {
        // Evidencia ante un incidente con un menor (`datos.md §8.4`): una salida no se borra con un rollback. La comprobación va
        // por la conexión de plataforma (BYPASSRLS) para ver las filas de todos los centros.
        if (DB::connection('pgsql_platform')->table('student_movements')->exists()) {
            throw new RuntimeException('student_movements tiene filas: son evidencia de a quién se entregó a un menor y no se revierte. Purga el centro por el procedimiento de retención (REQ-PRIV-006) antes.');
        }

        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS student_movements');
    }
};
