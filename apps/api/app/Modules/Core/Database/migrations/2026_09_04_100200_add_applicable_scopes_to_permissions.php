<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PERM/datos.md §3 (1.5), `funcional.md §18` OPEN-PERM-06: columna
 * nueva `permissions.applicable_scopes`, decisión del usuario (2026-09-04)
 * — categoría de riesgo distinta de la migración hermana de `permission_role`
 * (arriba), y por eso con tratamiento distinto: **`ADD COLUMN` simple**, sin
 * el patrón `CHECK NOT VALID → VALIDATE`.
 *
 * `permissions` es tabla de referencia compartida, sin `tenant_id`, sin
 * RLS, de unas 35 filas y con un único escritor (`platform:sync-registry`,
 * como `pgsql_owner`). `ADD COLUMN` de una columna anulable sin defecto es
 * una operación de solo catálogo desde PostgreSQL 11 (no reescribe la
 * tabla), y el `CHECK` de forma recorre unas 35 filas sin concurrencia que
 * proteger. Aplicar aquí el patrón cauteloso de la migración hermana sería
 * ceremonia sin beneficio (datos.md §3.3).
 *
 * `NULL` se interpreta como `['todos']` (funcional.md §3.2 regla 1): la
 * columna no lleva `NOT NULL DEFAULT`, a propósito, para distinguir «este
 * módulo todavía no declara ámbitos» de «declara exactamente todos» —las
 * dos se comportan igual, pero la distinción ayuda a saber qué módulos
 * quedan por revisar cuando lleguen `REQ-ACAD`/`REQ-FAM-UNIT` (datos.md
 * §3.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE permissions ADD COLUMN applicable_scopes jsonb NULL');

        $owner->statement(<<<'SQL'
            ALTER TABLE permissions ADD CONSTRAINT permissions_applicable_scopes_check
                CHECK (applicable_scopes IS NULL
                       OR (jsonb_typeof(applicable_scopes) = 'array'
                           AND jsonb_array_length(applicable_scopes) > 0))
            SQL);
    }

    /**
     * datos.md §3.3: reversible sin pérdida — el dato se regenera entero
     * con `platform:sync-registry`.
     */
    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE permissions DROP CONSTRAINT permissions_applicable_scopes_check');
        $owner->statement('ALTER TABLE permissions DROP COLUMN applicable_scopes');
    }
};
