<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * datos.md §A.3, issue #18. Entrega 1 (expand) del ciclo expand/contract
 * de `CLAUDE.md §9`: `token` (hash bcrypt de Laravel, no consultable por
 * igualdad) deja de ser obligatorio y se añaden `token_hash` (SHA-256,
 * consultable) y `expires_at` explícito. Las dos nacen nullable aunque la
 * aplicación las escriba siempre — la tabla está vacía hoy (ningún flujo
 * de recuperación ha escrito en ella todavía), pero el patrón es el mismo
 * que copiarán los 51 módulos restantes.
 *
 * Entrega 2 (contract), dos versiones después: DROP COLUMN token, y
 * token_hash/expires_at pasan a NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_owner')->table('password_reset_tokens', function (Blueprint $table): void {
            $table->text('token_hash')->nullable();
            $table->timestampTz('expires_at')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE password_reset_tokens ALTER COLUMN token DROP NOT NULL');

        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX password_reset_tokens_tenant_token_hash_unique
                ON password_reset_tokens (tenant_id, token_hash)
            SQL);
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('DROP INDEX IF EXISTS password_reset_tokens_tenant_token_hash_unique');
        $owner->statement('ALTER TABLE password_reset_tokens ALTER COLUMN token SET NOT NULL');

        Schema::connection('pgsql_owner')->table('password_reset_tokens', function (Blueprint $table): void {
            $table->dropColumn(['token_hash', 'expires_at']);
        });
    }
};
