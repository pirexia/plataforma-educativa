<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §C.6. Excepción temporal nominal a la obligatoriedad. `expires_at`
 * es `NOT NULL`: implementación literal de "no existe la exención
 * permanente" (RN-AUTH-68), garantizada por el motor.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('user_mfa_exemptions', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'user_id', 'users');
            $table->text('reason');
            $table->timestampTz('expires_at');
            TenantMigration::tenantForeignId($table, 'granted_by', 'users');
            $table->timestampTz('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(
            'ALTER TABLE user_mfa_exemptions ADD CONSTRAINT user_mfa_exemptions_revoked_by_fkey '.
            'FOREIGN KEY (tenant_id, revoked_by) REFERENCES users (tenant_id, id)'
        );

        // Una sola excepción vigente por usuario.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX user_mfa_exemptions_tenant_user_live_unique
                ON user_mfa_exemptions (tenant_id, user_id)
                WHERE revoked_at IS NULL AND deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE user_mfa_exemptions ADD CONSTRAINT user_mfa_exemptions_expires_after_created_check
                CHECK (expires_at > created_at)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE user_mfa_exemptions ADD CONSTRAINT user_mfa_exemptions_revoked_at_by_check
                CHECK ((revoked_at IS NULL) = (revoked_by IS NULL))
            SQL);

        // Paso 1 de MfaPolicy::resolve(), en cada evaluación.
        $owner->statement(<<<'SQL'
            CREATE INDEX user_mfa_exemptions_tenant_user_live_idx
                ON user_mfa_exemptions (tenant_id, user_id)
                WHERE revoked_at IS NULL
            SQL);
        // Tarea que reabre la obligación al caducar (ReopenExpiredMfaExemptions).
        $owner->statement(<<<'SQL'
            CREATE INDEX user_mfa_exemptions_tenant_expires_live_idx
                ON user_mfa_exemptions (tenant_id, expires_at)
                WHERE revoked_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS user_mfa_exemptions');
    }
};
