<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ALUM/datos.md §1.3, §3 (1.15-c, `REQ-ALUM-003`, `OPEN-ALUM-13` = A). Solicitud de cambio de grupo de una matrícula:
 * flujo pendiente → aprobada / rechazada / cancelada con doble control (`RN-ALUM-40..46`). Migración *expand*: tabla nueva
 * y vacía (la `UNIQUE (tenant_id, academic_year_id, id)` de `enrollments` que necesita llega en la migración anterior).
 *
 * `academic_year_id` es el de la matrícula, desnormalizado para el disparador `academic_year_write_guard` (`ADR-057`,
 * `AR-13`); la FK compuesta `(tenant_id, academic_year_id, enrollment_id)` → `enrollments` impide que diverja. Ambos grupos
 * se enlazan por `(tenant_id, academic_year_id, id)` → `groups_tenant_year_id_unique`: el motor garantiza que son del
 * curso académico de la solicitud; que sean del curso de etapa de la matrícula lo valida el servicio bajo el `FOR UPDATE`
 * de la matrícula (datos.md §1.3). Ninguna FK usa acciones referenciales (solo `NO ACTION`).
 *
 * Índices de FK (`AR-16`): `tenantForeignId()` del solicitante y los explícitos de matrícula, grupos y `decided_by`
 * (ningún índice de `datos.md §3` cubre esas FK; el de la bandeja cubre la de `academic_years` por su prefijo). Tabla
 * nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('group_change_requests', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            // Sin índice propio: la bandeja `(tenant_id, academic_year_id, status, …)` cubre la FK por su prefijo (`AR-16`).
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years', null, false);
            $table->foreignId('enrollment_id');
            $table->foreignId('from_group_id');
            $table->foreignId('to_group_id');
            $table->text('reason');
            $table->text('status')->default('pendiente');
            TenantMigration::tenantForeignId($table, 'requested_by', 'users');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            // La solicitud es del curso académico de su matrícula.
            $table->foreign(['tenant_id', 'academic_year_id', 'enrollment_id'], 'group_change_requests_tenant_year_enrollment_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('enrollments');
            $table->foreign(['tenant_id', 'academic_year_id', 'from_group_id'], 'group_change_requests_tenant_year_from_group_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('groups');
            $table->foreign(['tenant_id', 'academic_year_id', 'to_group_id'], 'group_change_requests_tenant_year_to_group_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('groups');

            // `AR-16`: prefijo = columnas de cada FK, en su orden.
            $table->index(['tenant_id', 'academic_year_id', 'enrollment_id'], 'group_change_requests_tenant_year_enrollment_idx');
            $table->index(['tenant_id', 'academic_year_id', 'from_group_id'], 'group_change_requests_tenant_year_from_group_idx');
            $table->index(['tenant_id', 'academic_year_id', 'to_group_id'], 'group_change_requests_tenant_year_to_group_idx');
            $table->index(['tenant_id', 'decided_by'], 'group_change_requests_tenant_decided_by_idx');
        });

        $owner = DB::connection('pgsql_owner');

        // Quien decide (aprueba, rechaza o cancela); nulo mientras está pendiente. FK compuesta a mano (precedente
        // `user_mfa_exemptions_revoked_by_fkey`): `MATCH SIMPLE`, sin comprobación con `decided_by` nulo.
        $owner->statement(
            'ALTER TABLE group_change_requests ADD CONSTRAINT group_change_requests_decided_by_fkey '.
            'FOREIGN KEY (tenant_id, decided_by) REFERENCES users (tenant_id, id)'
        );

        $owner->statement(<<<'SQL'
            ALTER TABLE group_change_requests
                ADD CONSTRAINT group_change_requests_status_check
                    CHECK (status IN ('pendiente', 'aprobada', 'rechazada', 'cancelada')),
                ADD CONSTRAINT group_change_requests_groups_differ_check
                    CHECK (to_group_id <> from_group_id),
                ADD CONSTRAINT group_change_requests_reason_length_check
                    CHECK (length(reason) BETWEEN 1 AND 500),
                ADD CONSTRAINT group_change_requests_decision_note_length_check
                    CHECK (decision_note IS NULL OR length(decision_note) <= 500),
                ADD CONSTRAINT group_change_requests_status_decision_check
                    CHECK (
                        (status = 'pendiente' AND decided_by IS NULL AND decided_at IS NULL)
                        OR (status <> 'pendiente' AND decided_by IS NOT NULL AND decided_at IS NOT NULL)
                    ),
                ADD CONSTRAINT group_change_requests_approver_not_requester_check
                    CHECK (status <> 'aprobada' OR decided_by <> requested_by)
            SQL);

        // `RN-ALUM-41`: una solicitud pendiente viva por matrícula.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX group_change_requests_tenant_enrollment_pending_unique
                ON group_change_requests (tenant_id, enrollment_id)
                WHERE status = 'pendiente' AND deleted_at IS NULL
            SQL);

        // Bandeja de pendientes por curso académico, con orden estable.
        $owner->statement(<<<'SQL'
            CREATE INDEX group_change_requests_tenant_year_status_created_idx
                ON group_change_requests (tenant_id, academic_year_id, status, created_at DESC, id DESC)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS group_change_requests');
    }
};
