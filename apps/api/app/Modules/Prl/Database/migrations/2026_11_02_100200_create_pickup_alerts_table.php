<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PRL/datos.md §8.5, §8.6 (1.14b-b, `REQ-PRL-004`, `RN-PRL-08`). Alerta por persona no autorizada: se abre con el alumno y
 * la descripción de quien se presenta, y se cierra una sola vez con una resolución de vocabulario cerrado. **Tabla ordinaria**
 * (la resolución cambia su estado) con `deleted_at` por `INV-005`, pero sin *endpoint* de borrado: la alerta es evidencia.
 *
 * - `kind` vale solo `persona_no_autorizada` en `1.14b-b`; `alumno_no_recogido` lo añade `1.14b-d` ampliando el `CHECK`.
 * - `CHECK ((status = 'cerrada') = (resolution, resolved_at y resolved_by presentes))`: una alerta cerrada lo está del todo
 *   (`CA-PRL-008`); y su complemento, `pickup_alerts_open_without_resolution_check`: una abierta no lleva resolución, instante,
 *   autor ni notas de resolución a medias (la alerta no se reabre ni queda a medio cerrar).
 * - Idempotencia como `student_movements` (`RN-PRL-09`), por índice único parcial `(tenant_id, raised_by, idempotency_key)`.
 * - Índice `(tenant_id, status, raised_at DESC) WHERE deleted_at IS NULL`: alertas abiertas (`datos.md §8.6`). Toda clave
 *   foránea compuesta tiene su índice **completo** (`AR-16`).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('pickup_alerts', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'student_person_id', 'people');
            $table->text('kind');
            $table->text('presenter_description');
            $table->text('status');
            TenantMigration::tenantForeignId($table, 'raised_by', 'users');
            $table->timestampTz('raised_at');
            $table->timestampTz('principal_contacted_at')->nullable();
            $table->text('resolution')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('idempotency_key')->nullable();
            $table->text('idempotency_body_hash')->nullable();

            $table->foreign(['tenant_id', 'resolved_by'], 'pickup_alerts_tenant_resolved_by_foreign')->references(['tenant_id', 'id'])->on('users');
            $table->index(['tenant_id', 'resolved_by'], TenantMigration::indexName('pickup_alerts', 'resolved_by'));
        });

        $owner = DB::connection('pgsql_owner');

        // datos.md §8.6: alertas abiertas, las más recientes primero.
        $owner->statement(<<<'SQL'
            CREATE INDEX pickup_alerts_status_raised_idx
                ON pickup_alerts (tenant_id, status, raised_at DESC)
                WHERE deleted_at IS NULL
            SQL);

        // RN-PRL-09.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX pickup_alerts_idempotency_unique
                ON pickup_alerts (tenant_id, raised_by, idempotency_key)
                WHERE idempotency_key IS NOT NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE pickup_alerts
                ADD CONSTRAINT pickup_alerts_kind_check
                    CHECK (kind IN ('persona_no_autorizada')),
                ADD CONSTRAINT pickup_alerts_presenter_description_check
                    CHECK (length(presenter_description) BETWEEN 1 AND 500),
                ADD CONSTRAINT pickup_alerts_status_check
                    CHECK (status IN ('abierta', 'cerrada')),
                ADD CONSTRAINT pickup_alerts_resolution_check
                    CHECK (resolution IS NULL OR resolution IN ('no_entregado', 'recogido_por_autorizado', 'autorizacion_puntual_registrada', 'otro')),
                ADD CONSTRAINT pickup_alerts_resolution_notes_length_check
                    CHECK (resolution_notes IS NULL OR length(resolution_notes) <= 1000),
                ADD CONSTRAINT pickup_alerts_closed_coherence_check
                    CHECK ((status = 'cerrada') = (resolution IS NOT NULL AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL)),
                ADD CONSTRAINT pickup_alerts_open_without_resolution_check
                    CHECK (status = 'cerrada' OR (resolution IS NULL AND resolved_at IS NULL AND resolved_by IS NULL AND resolution_notes IS NULL)),
                ADD CONSTRAINT pickup_alerts_idempotency_coherence_check
                    CHECK ((idempotency_key IS NULL) = (idempotency_body_hash IS NULL))
            SQL);
    }

    public function down(): void
    {
        // Evidencia (`datos.md §8.5`): una alerta no se borra con un rollback.
        if (DB::connection('pgsql_platform')->table('pickup_alerts')->exists()) {
            throw new RuntimeException('pickup_alerts tiene filas: son evidencia de un incidente con un menor y no se revierte. Purga el centro por el procedimiento de retención (REQ-PRIV-006) antes.');
        }

        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS pickup_alerts');
    }
};
