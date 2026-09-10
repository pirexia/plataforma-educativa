<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-BO-001, ADR-047 §4.1/§4.2/§4.3/§4.4. Historia de la máquina de
 * estados de un centro: dato de negocio que la aplicación lee (cuándo
 * vence la gracia, desde cuándo está suspendido), no auditoría — por eso
 * es tabla distinta de `admin_action_logs` (datos.md §5.1).
 *
 * Pieza de esquema/infraestructura de 1.6: esta migración crea la tabla
 * y sus restricciones; la lógica de negocio de las transiciones
 * (`POST /tenants/{id}/transitions`) es de 1.6b, que es quien empieza a
 * escribir filas aquí.
 *
 * `affected_tenant_id` (no `tenant_id`, ADR-047 §4.2) es NOT NULL aquí
 * —a diferencia de `admin_action_logs`, donde es anulable— porque no
 * existe el evento de ciclo de vida sin centro: la fila ES una
 * transición de la máquina de estados de un centro concreto (decisión
 * ratificada por el usuario el 2026-09-08, datos.md §5.2).
 *
 * No usa `TenantMigration::tenantTable()`/`tenantTableAppendOnly()`: no
 * encaja (no es tabla de tenant), se crea a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_owner')->create('tenant_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('affected_tenant_id');
            $table->text('from_status')->nullable();
            $table->text('to_status');
            $table->text('reason');
            $table->timestampTz('occurred_at');
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->unsignedBigInteger('dual_authorization_id')->nullable();
            $table->timestampTz('grace_period_ends_at')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(
            'ALTER TABLE tenant_lifecycle_events ADD CONSTRAINT tenant_lifecycle_events_affected_tenant_fk '.
            'FOREIGN KEY (affected_tenant_id) REFERENCES tenants (id)'
        );
        $owner->statement(
            'ALTER TABLE tenant_lifecycle_events ADD CONSTRAINT tenant_lifecycle_events_performed_by_fk '.
            'FOREIGN KEY (performed_by) REFERENCES platform_admins (id)'
        );
        $owner->statement(
            'ALTER TABLE tenant_lifecycle_events ADD CONSTRAINT tenant_lifecycle_events_dual_authorization_fk '.
            'FOREIGN KEY (dual_authorization_id) REFERENCES dual_authorizations (id)'
        );

        $statusList = "'en_alta', 'activo', 'suspendido', 'en_baja', 'eliminado'";

        $owner->statement(
            'ALTER TABLE tenant_lifecycle_events ADD CONSTRAINT tenant_lifecycle_events_from_status_check '.
            "CHECK (from_status IS NULL OR from_status IN ({$statusList}))"
        );
        $owner->statement(
            'ALTER TABLE tenant_lifecycle_events ADD CONSTRAINT tenant_lifecycle_events_to_status_check '.
            "CHECK (to_status IN ({$statusList}))"
        );
        $owner->statement(<<<'SQL'
            ALTER TABLE tenant_lifecycle_events ADD CONSTRAINT tenant_lifecycle_events_reason_not_blank_check
                CHECK (length(btrim(reason)) > 0)
            SQL);

        // Eliminar exige doble autorización, y lo dice el esquema.
        $owner->statement(<<<'SQL'
            ALTER TABLE tenant_lifecycle_events ADD CONSTRAINT tenant_lifecycle_events_deletion_requires_dual_auth_check
                CHECK (to_status <> 'eliminado' OR dual_authorization_id IS NOT NULL)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE tenant_lifecycle_events ADD CONSTRAINT tenant_lifecycle_events_grace_period_matches_status_check
                CHECK ((to_status = 'en_baja') = (grace_period_ends_at IS NOT NULL))
            SQL);

        // ADR-047 §4.3: solo-anexión, misma forma que admin_action_logs.
        $owner->statement('ALTER TABLE tenant_lifecycle_events ENABLE ROW LEVEL SECURITY');
        $owner->statement('ALTER TABLE tenant_lifecycle_events FORCE ROW LEVEL SECURITY');

        $owner->statement(<<<'SQL'
            CREATE POLICY tenant_visibility ON tenant_lifecycle_events
                FOR SELECT
                USING (affected_tenant_id = app.current_tenant_id())
            SQL);

        $owner->statement('REVOKE ALL ON tenant_lifecycle_events FROM plataforma_app');
        $owner->statement('REVOKE ALL ON SEQUENCE tenant_lifecycle_events_id_seq FROM plataforma_app');

        // `reason` y `performed_by` y `dual_authorization_id` no cruzan
        // el GRANT (datos.md §5.3, §5.3.1).
        $owner->statement(<<<'SQL'
            GRANT SELECT (
                public_id,
                affected_tenant_id,
                from_status,
                to_status,
                occurred_at,
                grace_period_ends_at
            ) ON tenant_lifecycle_events TO plataforma_app
            SQL);

        $owner->statement('REVOKE UPDATE, DELETE ON tenant_lifecycle_events FROM plataforma_platform');

        $owner->statement(
            'CREATE INDEX tenant_lifecycle_events_affected_tenant_occurred_id_idx ON tenant_lifecycle_events (affected_tenant_id, occurred_at DESC, id DESC)'
        );
        $owner->statement(<<<'SQL'
            CREATE INDEX tenant_lifecycle_events_grace_period_idx
                ON tenant_lifecycle_events (to_status, grace_period_ends_at)
                WHERE to_status = 'en_baja'
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS tenant_lifecycle_events');
    }
};
