<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/datos.md §2 (1.5), ADR-044 §8, `funcional.md §18` OPEN-PERM-06.
 * El único cambio de esquema con riesgo sobre datos existentes de tenant:
 * `permission_role.scope` pasa de `text` nullable sin `CHECK` a `NOT NULL`
 * con `CHECK` sobre el vocabulario cerrado de seis ámbitos
 * (`App\Support\Authorization\Scope`).
 *
 * *Skill* `migracion-segura`: siete sentencias escalonadas, ninguna con
 * bloqueo apreciable — `permission_role` es tabla de tenant con filas
 * vivas de todos los centros, RLS y escritura concurrente (datos.md §2.3):
 *
 *   1. Relleno idempotente (0 filas hoy: `ProvisionTenantDefaults` siempre
 *      escribe 'todos' — CA-CORE-042).
 *   2. `CHECK (scope IS NOT NULL) NOT VALID` — ACCESS EXCLUSIVE instantáneo.
 *   3. `VALIDATE CONSTRAINT` — SHARE UPDATE EXCLUSIVE, no bloquea lecturas
 *      ni escrituras normales.
 *   4. `SET NOT NULL` — PostgreSQL >= 12 reutiliza el CHECK ya validado y
 *      se salta el escaneo completo de la tabla.
 *   5. `DROP` del CHECK auxiliar, redundante con NOT NULL.
 *   6-7. Mismo patrón NOT VALID/VALIDATE para el CHECK de vocabulario.
 *
 * Compatibilidad con la versión anterior (`CLAUDE.md §9`): el único
 * escritor de `permission_role` en el código desplegado hoy
 * (`ProvisionTenantDefaults::seedPermissionGrants()`) siempre fija
 * `scope = 'todos'` de forma literal — el `NOT NULL` no le rompe nada.
 *
 * Sin `contract`: no se elimina ni se renombra nada (`expand` puro,
 * datos.md §2.4). Ejecutada sobre `pgsql_owner`, como el resto del DDL de
 * esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement("UPDATE permission_role SET scope = 'todos' WHERE scope IS NULL");

        $owner->statement(<<<'SQL'
            ALTER TABLE permission_role ADD CONSTRAINT permission_role_scope_not_null_check
                CHECK (scope IS NOT NULL) NOT VALID
            SQL);

        $owner->statement('ALTER TABLE permission_role VALIDATE CONSTRAINT permission_role_scope_not_null_check');

        $owner->statement('ALTER TABLE permission_role ALTER COLUMN scope SET NOT NULL');

        $owner->statement('ALTER TABLE permission_role DROP CONSTRAINT permission_role_scope_not_null_check');

        $owner->statement(<<<'SQL'
            ALTER TABLE permission_role ADD CONSTRAINT permission_role_scope_check
                CHECK (scope IN ('todos', 'propios', 'departamento', 'grupo', 'clase', 'unidad_familiar')) NOT VALID
            SQL);

        $owner->statement('ALTER TABLE permission_role VALIDATE CONSTRAINT permission_role_scope_check');
    }

    /**
     * datos.md §2.5: reversible sin pérdida de datos (ninguna fila cambia
     * de valor al revertir), pero reabre el fallo silencioso que
     * `ADR-044 §1.2` describe. Para un despliegue fallido, nunca para
     * «desactivar temporalmente la validación» con el código nuevo en
     * producción.
     */
    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE permission_role DROP CONSTRAINT permission_role_scope_check');
        $owner->statement('ALTER TABLE permission_role ALTER COLUMN scope DROP NOT NULL');
    }
};
