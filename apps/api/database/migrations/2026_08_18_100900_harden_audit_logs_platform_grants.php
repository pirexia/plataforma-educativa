<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hallazgo propio de revisión de seguridad tras 0.8.8 (severidad Alta:
 * incumple la garantía central que ADR-034 §3/§6 promete para audit_logs
 * — "inmutabilidad forzada en el motor, no por convención"). La migración
 * original solo revocaba UPDATE/DELETE a plataforma_app: plataforma_platform
 * (BYPASSRLS, ADR-033 §5) se quedaba con privilegio completo por los GRANT
 * por defecto de 0.7.1, igual que failed_jobs antes del bug 6 de 0.7. Es
 * precisamente la conexión con más probabilidad de tocar estas filas por
 * error en un futuro script de mantenimiento entre tenants (REQ-BO), así
 * que dejarla fuera del REVOKE vaciaba la garantía para el camino que más
 * la necesita. INSERT y SELECT se conservan para los dos roles: el
 * backoffice necesita poder registrar acciones de plataforma
 * (`actor_type = 'platform'`) y leer entre tenants.
 *
 * `TenantMigration::tenantTableAppendOnly()` ya revoca de los dos roles
 * desde este mismo commit — esta migración cierra el hueco en la única
 * tabla append-only creada antes del cambio.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_owner')->statement(
            'REVOKE UPDATE, DELETE ON audit_logs FROM plataforma_platform'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement(
            'GRANT UPDATE, DELETE ON audit_logs TO plataforma_platform'
        );
    }
};
