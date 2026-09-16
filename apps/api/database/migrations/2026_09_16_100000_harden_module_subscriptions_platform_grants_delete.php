<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hallazgo propio de revisión de seguridad tras `1.6c` (severidad Alta,
 * issue #220): `2026_09_15_100100_harden_module_subscriptions_platform_grants`
 * revocaba `UPDATE`, `INSERT` y `SELECT` de tabla para `plataforma_app`,
 * pero nunca `DELETE` — la única migración de endurecimiento del
 * repositorio que dejaba ese privilegio sin tocar (compárese con
 * `permissions`, `modules`, `failed_jobs`, `audit_logs`,
 * `admin_action_logs`, `tenant_lifecycle_events`, que sí lo revocan
 * explícitamente). `plataforma_app` conservaba el `DELETE` que
 * `01-tenancy.sql.tpl` concede por defecto a cualquier tabla nueva.
 *
 * Migración nueva, no edición de la ya aplicada (`CLAUDE.md §9`,
 * `migracion-segura`): la original ya está mezclada y desplegada.
 *
 * `module_subscriptions` usa borrado lógico (`deleted_at`, `INV-004`):
 * ningún camino de la aplicación que corre como `plataforma_app`
 * necesita jamás un `DELETE` físico sobre esta tabla — el único
 * escritor de `deleted_at` es el propio `UPDATE` que la migración de
 * `1.6c` ya concede por columnas. `plataforma_platform` no se toca
 * (backoffice, conexión de plataforma, `datos.md §7`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_owner')->statement(
            'REVOKE DELETE ON module_subscriptions FROM plataforma_app'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement(
            'GRANT DELETE ON module_subscriptions TO plataforma_app'
        );
    }
};
