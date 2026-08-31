<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hallazgo del `db-reviewer` en la revisión independiente de 1.3b
 * (issues #118 y #119, REQ-AUTH-003). Las dos purgas diarias de la pieza 4
 * (`PurgeMfaChallenges`, `PurgeMfaFactors`, operacion.md §D.4.1.1) filtran
 * `consumed_at`/`deleted_at` respectivamente, sin índice que las soporte:
 * el índice `mfa_challenges_tenant_expires_idx` creado en 1.3 anticipando
 * la purga quedó sobre `expires_at`, columna que el job finalmente no usa.
 *
 * `CREATE INDEX CONCURRENTLY` no puede ejecutarse dentro de una
 * transacción (`migracion-segura`: "crear índices bloqueando la tabla:
 * usar creación concurrente"), de ahí `$withinTransaction = false`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS mfa_challenges_tenant_consumed_idx
                ON mfa_challenges (tenant_id, consumed_at)
                WHERE consumed_at IS NOT NULL
            SQL);

        $owner->statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS user_mfa_factors_tenant_deleted_idx
                ON user_mfa_factors (tenant_id, deleted_at)
                WHERE deleted_at IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS mfa_challenges_tenant_consumed_idx');
        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS user_mfa_factors_tenant_deleted_idx');
    }
};
