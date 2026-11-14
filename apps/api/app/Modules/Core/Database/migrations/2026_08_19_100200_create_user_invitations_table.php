<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CORE-003 (paso 1.1). datos.md §A.2: token solo hash (RN-CORE-19),
 * sin correo de destino (minimización, se deriva de users.email).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('user_invitations', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'user_id', 'users');
            $table->text('token_hash');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        // Búsqueda del canje (1.2): por tenant + hash. Total, no parcial —
        // un token no se reutiliza jamás (datos.md §A.8).
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX user_invitations_tenant_token_unique
                ON user_invitations (tenant_id, token_hash)
            SQL);

        // RN-CORE-09: una sola invitación viva por usuario.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX user_invitations_tenant_user_live_unique
                ON user_invitations (tenant_id, user_id)
                WHERE accepted_at IS NULL AND revoked_at IS NULL AND deleted_at IS NULL
            SQL);

        $owner->statement(
            'CREATE INDEX user_invitations_tenant_expires_idx ON user_invitations (tenant_id, expires_at)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS user_invitations');
    }
};
