<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §2.3. Segundo paso pendiente de un login de plataforma. Igual
 * que `mfa_challenges` de tenant, artefacto transitorio de vida corta.
 * Se purga por `bo:purge-mfa-challenges` (operacion.md §6.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::platformTable('platform_admin_mfa_challenges', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->foreignId('platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->text('challenge_hash');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->timestampsTz();
        });

        $owner = DB::connection('pgsql_owner');

        // Un solo desafío vivo por administrador, mismo patrón que
        // mfa_challenges de tenant.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX platform_admin_mfa_challenges_admin_live_unique
                ON platform_admin_mfa_challenges (platform_admin_id)
                WHERE consumed_at IS NULL
            SQL);

        $owner->statement(
            'CREATE INDEX platform_admin_mfa_challenges_expires_idx ON platform_admin_mfa_challenges (expires_at)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS platform_admin_mfa_challenges');
    }
};
