<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-BO-007, datos.md §3. Mecanismo genérico y con vocabulario cerrado:
 * "ninguna acción destructiva se hace en un solo paso" no es una
 * condición dentro de cada operación, es esta tabla.
 *
 * Pieza de esquema/infraestructura de 1.6 que 1.6b (`tenant.eliminar`,
 * `tenant.baja`) y 1.6c (`modulo.descontratar_masivo`) reutilizan: 1.6
 * construye la tabla, su restricción de aprobador distinto (RN-BO-19,
 * en el motor y no en el controlador) y su máquina de estados; los
 * *endpoints* que la disparan (`POST /dual-authorizations/...`) llegan
 * con la primera operación real que los necesita, porque hoy no hay
 * ninguna acción del vocabulario cerrado que 1.6 pueda ejecutar.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::platformTable('dual_authorizations', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->text('action');
            $table->jsonb('payload');
            $table->text('payload_fingerprint');
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('platform_admins');
            $table->timestampTz('requested_at');
            $table->timestampTz('expires_at');
            $table->text('status')->default('pendiente');
            $table->foreignId('approved_by')->nullable()->constrained('platform_admins');
            $table->timestampTz('approved_at')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->text('execution_error')->nullable();
            $table->timestampsTz();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            ALTER TABLE dual_authorizations ADD CONSTRAINT dual_authorizations_action_check
                CHECK (action IN ('tenant.eliminar', 'tenant.baja', 'modulo.descontratar_masivo'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE dual_authorizations ADD CONSTRAINT dual_authorizations_status_check
                CHECK (status IN ('pendiente', 'aprobada', 'rechazada', 'caducada', 'ejecutada', 'fallida'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE dual_authorizations ADD CONSTRAINT dual_authorizations_reason_not_blank_check
                CHECK (length(btrim(reason)) > 0)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE dual_authorizations ADD CONSTRAINT dual_authorizations_expires_after_requested_check
                CHECK (expires_at > requested_at)
            SQL);

        // La restricción que define el módulo (datos.md §3.1, RN-BO-19):
        // quien aprueba no puede ser quien solicita. En el motor, no en
        // el controlador — CA-BO-062 lo comprueba escribiendo por SQL
        // directo.
        $owner->statement(<<<'SQL'
            ALTER TABLE dual_authorizations ADD CONSTRAINT dual_authorizations_distinct_approver
                CHECK (approved_by IS NULL OR approved_by <> requested_by)
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE dual_authorizations ADD CONSTRAINT dual_authorizations_approved_coherence_check
                CHECK ((status = 'aprobada') = (approved_by IS NOT NULL AND approved_at IS NOT NULL))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE dual_authorizations ADD CONSTRAINT dual_authorizations_executed_coherence_check
                CHECK (status <> 'ejecutada' OR executed_at IS NOT NULL)
            SQL);

        // Una sola solicitud viva por operación idéntica.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX dual_authorizations_action_fingerprint_pending_unique
                ON dual_authorizations (action, payload_fingerprint)
                WHERE status = 'pendiente'
            SQL);

        // El barrido de caducidad (bo:expire-dual-authorizations).
        $owner->statement(<<<'SQL'
            CREATE INDEX dual_authorizations_pending_expires_idx
                ON dual_authorizations (status, expires_at)
                WHERE status = 'pendiente'
            SQL);
        // "Mis solicitudes".
        $owner->statement(
            'CREATE INDEX dual_authorizations_requested_by_idx ON dual_authorizations (requested_by)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS dual_authorizations');
    }
};
