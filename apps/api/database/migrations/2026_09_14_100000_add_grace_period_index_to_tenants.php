<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue #200 (revisión de `1.6b`, `db-reviewer`). `bo:check-grace-periods`
 * (`CheckGracePeriodsCommand`) filtra `tenants` por
 * `status = 'en_baja' AND grace_period_ends_at < now() AND
 * grace_period_expired_at IS NULL`, y `GET /tenants?grace_expired=` usa
 * el mismo par de columnas — sin índice, ambas consultas escanean toda
 * la tabla. Este mismo cambio ya aplica el patrón correcto en dos tablas
 * hermanas (`tenant_lifecycle_events_grace_period_idx`,
 * `dual_authorizations_pending_expires_idx`) pero lo había omitido en
 * `tenants`. Índice parcial: fuera de `status = 'en_baja'` las columnas
 * son irrelevantes para este filtro. `CREATE INDEX` puro, sin bloqueo
 * de escritura más allá del habitual (tabla pequeña hoy).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_owner')->statement(
            'CREATE INDEX tenants_grace_period_idx ON tenants (grace_period_ends_at) '.
            "WHERE status = 'en_baja' AND grace_period_expired_at IS NULL"
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP INDEX tenants_grace_period_idx');
    }
};
