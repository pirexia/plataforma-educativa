<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §2.3. Solo el hash, nunca el código. Un código no se reutiliza
 * jamás — unicidad total sobre `code_hash`, mismo criterio que
 * `user_mfa_recovery_codes` (SchemaInvariantsTest, excepción nombrada).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::platformTable('platform_admin_mfa_recovery_codes', function (Blueprint $table): void {
            $table->foreignId('platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->text('code_hash');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(
            'ALTER TABLE platform_admin_mfa_recovery_codes ADD CONSTRAINT platform_admin_mfa_recovery_codes_hash_unique UNIQUE (code_hash)'
        );

        $owner->statement(<<<'SQL'
            CREATE INDEX platform_admin_mfa_recovery_codes_admin_unused_idx
                ON platform_admin_mfa_recovery_codes (platform_admin_id)
                WHERE used_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS platform_admin_mfa_recovery_codes');
    }
};
