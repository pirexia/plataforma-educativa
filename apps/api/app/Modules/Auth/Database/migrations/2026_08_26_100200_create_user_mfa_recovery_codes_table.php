<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §C.3. Cuelgan del usuario, no del factor (funcional.md
 * §C.4.5): un código de respaldo vale para cualquier método. Sin
 * `public_id`: no se exponen individualmente por ninguna API, se
 * identifican por su valor (que solo conoce el titular).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('user_mfa_recovery_codes', function (Blueprint $table): void {
            TenantMigration::tenantForeignId($table, 'user_id', 'users');
            $table->text('code_hash');
            $table->timestampTz('used_at')->nullable();
            $table->ipAddress('used_ip')->nullable();
            $table->ulid('batch_id');
        });

        $owner = DB::connection('pgsql_owner');

        // Total, no parcial: un código no se reutiliza jamás, ni siquiera
        // tras un borrado lógico (mismo criterio que los tokens de §A.7).
        $owner->statement(
            'ALTER TABLE user_mfa_recovery_codes ADD CONSTRAINT user_mfa_recovery_codes_tenant_hash_unique UNIQUE (tenant_id, code_hash)'
        );

        // La consulta del canje: búsqueda exacta por titular y hash entre
        // los no usados.
        $owner->statement(<<<'SQL'
            CREATE INDEX user_mfa_recovery_codes_tenant_user_hash_unused_idx
                ON user_mfa_recovery_codes (tenant_id, user_id, code_hash)
                WHERE used_at IS NULL AND deleted_at IS NULL
            SQL);
        // "¿Cuántos le quedan?", para GET /auth/mfa.
        $owner->statement(<<<'SQL'
            CREATE INDEX user_mfa_recovery_codes_tenant_user_unused_idx
                ON user_mfa_recovery_codes (tenant_id, user_id)
                WHERE used_at IS NULL AND deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS user_mfa_recovery_codes');
    }
};
