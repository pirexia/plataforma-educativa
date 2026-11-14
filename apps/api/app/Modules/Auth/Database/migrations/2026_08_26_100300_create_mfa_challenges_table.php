<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §C.4. Segundo paso pendiente de un login (funcional.md §C.4.4,
 * §C.6). `session_id` sin FK, igual que `user_sessions` (`§B.2`) y por el
 * mismo motivo: `sessions` es del framework, no lleva `tenant_id` y no
 * admite una FK compuesta (issue #81). `session_id` es la única
 * credencial que autoriza el desafío (RN-AUTH-53) y **nunca** sale por la
 * API (RN-AUTH-40).
 *
 * Sin observer de auditoría (datos.md §C.4): artefacto transitorio de
 * cinco minutos, mismo trato que `password_reset_tokens`. No implementa
 * `Auditable` a propósito.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('mfa_challenges', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'user_id', 'users');
            $table->text('session_id');
            $table->text('method');
            $table->text('code_hash')->nullable();
            $table->timestampTz('code_expires_at')->nullable();
            $table->timestampTz('expires_at');
            $table->smallInteger('attempts')->default(0);
            $table->smallInteger('deliveries')->default(0);
            $table->timestampTz('consumed_at')->nullable();
            $table->ipAddress('ip_address')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        // Un solo desafío vivo por sesión, por índice y no por
        // comprobación de aplicación (mismo patrón que account_lockouts y
        // user_sessions).
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX mfa_challenges_tenant_session_live_unique
                ON mfa_challenges (tenant_id, session_id)
                WHERE consumed_at IS NULL AND deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE mfa_challenges ADD CONSTRAINT mfa_challenges_method_check
                CHECK (method IN ('totp', 'email', 'sms'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE mfa_challenges ADD CONSTRAINT mfa_challenges_code_matches_method_check
                CHECK ((method = 'totp') = (code_hash IS NULL))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE mfa_challenges ADD CONSTRAINT mfa_challenges_code_hash_expires_check
                CHECK ((code_hash IS NULL) = (code_expires_at IS NULL))
            SQL);

        // La consulta del paso 2, en cada verificación.
        $owner->statement(<<<'SQL'
            CREATE INDEX mfa_challenges_tenant_session_live_idx
                ON mfa_challenges (tenant_id, session_id)
                WHERE consumed_at IS NULL
            SQL);
        // Purga (PurgeMfaChallenges, operacion.md §C.4).
        $owner->statement(
            'CREATE INDEX mfa_challenges_tenant_expires_idx ON mfa_challenges (tenant_id, expires_at)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS mfa_challenges');
    }
};
