<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §C.5. Una fila por período de obligación, no una por usuario:
 * cumplir cierra la fila (`resolved_at`); volver a quedar sin factor abre
 * otra con plazo completo (RN-AUTH-65). Sin `public_id`: no se expone
 * individualmente.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('user_mfa_obligations', function (Blueprint $table): void {
            TenantMigration::tenantForeignId($table, 'user_id', 'users');
            $table->timestampTz('obligated_since');
            $table->timestampTz('grace_deadline_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->text('trigger');
        });

        $owner = DB::connection('pgsql_owner');

        // Una sola obligación abierta por usuario.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX user_mfa_obligations_tenant_user_open_unique
                ON user_mfa_obligations (tenant_id, user_id)
                WHERE resolved_at IS NULL AND deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE user_mfa_obligations ADD CONSTRAINT user_mfa_obligations_deadline_after_since_check
                CHECK (grace_deadline_at > obligated_since)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE user_mfa_obligations ADD CONSTRAINT user_mfa_obligations_resolved_after_since_check
                CHECK (resolved_at IS NULL OR resolved_at >= obligated_since)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE user_mfa_obligations ADD CONSTRAINT user_mfa_obligations_trigger_check
                CHECK (trigger IN ('rol_modificado', 'rol_asignado', 'metodo_retirado', 'restablecimiento', 'exencion_vencida'))
            SQL);

        // Evaluación de MfaPolicy, en cada petición autenticada de un
        // usuario obligado.
        $owner->statement(<<<'SQL'
            CREATE INDEX user_mfa_obligations_tenant_user_open_idx
                ON user_mfa_obligations (tenant_id, user_id)
                WHERE resolved_at IS NULL
            SQL);
        // La consulta de cumplimiento: quién está pendiente y quién ha
        // pasado del plazo (GET /mfa-compliance).
        $owner->statement(<<<'SQL'
            CREATE INDEX user_mfa_obligations_tenant_deadline_open_idx
                ON user_mfa_obligations (tenant_id, grace_deadline_at)
                WHERE resolved_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS user_mfa_obligations');
    }
};
