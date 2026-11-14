<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `ADR-045 §4.4`, `REQ-BO/datos.md §7`, `§7.7` (1.6c). **No cambia ni una
 * columna** de `module_subscriptions` (`ADR-045 §4.2`): sólo quién puede
 * escribir y leer qué.
 *
 * Hasta hoy `plataforma_app` conservaba los privilegios por defecto de
 * `01-tenancy.sql.tpl` sobre esta tabla: `INSERT`, `UPDATE` completo y
 * `SELECT` de tabla. Que un centro no pueda contratarse un módulo a sí
 * mismo era, hasta este commit, un `if` del `PATCH` de `REQ-CORE`
 * (`ModulesController::updateSettings()`, que ya rechaza `enabled` en el
 * cuerpo) — a partir de aquí es un `REVOKE`, que refuerza esa garantía
 * en el motor (`CA-CORE-061`, `CA-BO-030`, `CA-BO-031`, `INV-001`).
 *
 * `OPEN-BO-19` (resuelta: sí, cerrar el `GRANT` por columnas). A partir
 * de `1.6c`, `reason` guarda texto libre escrito por un operador de
 * plataforma (motivo comercial de la contratación/descontratación) y
 * `plataforma_app` seguía pudiendo leer la tabla entera dentro de su
 * RLS. Mismo criterio ya aplicado a `admin_action_logs` y
 * `tenant_lifecycle_events` (`ADR-047 §4.4`): ningún texto libre escrito
 * por un operador del proveedor cruza el `GRANT`. `created_by` y
 * `updated_by` tampoco: no los escribe nadie desde `1.6c` (`RN-BO-73`,
 * `datos.md §7.6`) y son referencias internas a `users`.
 *
 * `id` **sí** entra en el `GRANT SELECT`, a diferencia de
 * `admin_action_logs` (`datos.md §7.7`, nota): `module_subscriptions` es
 * un modelo Eloquent completo con camino de escritura —
 * `PATCH /modules/{code}` de `REQ-CORE` localiza la fila y guarda
 * `settings`— y sin la clave primaria concedida Eloquent no puede
 * hidratar el modelo ni emitir el `UPDATE`. `ModuleSubscription::
 * TENANT_VISIBLE_COLUMNS` (`App\Models\ModuleSubscription`) es la
 * proyección explícita que cualquier lectura de esta tabla alcanzable
 * desde el *runtime* de un centro debe usar en lugar de `SELECT *`
 * (`RN-BO-82`, `CA-BO-147`) — ya aplicada en
 * `EloquentModuleAvailability::isEnabled()` y en
 * `Core\Http\Controllers\ModulesController`.
 *
 * `plataforma_platform` no se toca: conserva `INSERT`/`UPDATE`/`SELECT`
 * completos, es la conexión del backoffice (`datos.md §7`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('REVOKE UPDATE, INSERT ON module_subscriptions FROM plataforma_app');
        $owner->statement(
            'GRANT UPDATE (settings, updated_at, updated_by, deleted_at) ON module_subscriptions TO plataforma_app'
        );

        $owner->statement('REVOKE SELECT ON module_subscriptions FROM plataforma_app');
        $owner->statement(<<<'SQL'
            GRANT SELECT (
                id,
                tenant_id,
                public_id,
                module_code,
                enabled,
                enabled_at,
                disabled_at,
                settings,
                created_at,
                updated_at,
                deleted_at
            ) ON module_subscriptions TO plataforma_app
            SQL);
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('REVOKE SELECT, UPDATE ON module_subscriptions FROM plataforma_app');
        $owner->statement('GRANT SELECT, INSERT, UPDATE ON module_subscriptions TO plataforma_app');
    }
};
