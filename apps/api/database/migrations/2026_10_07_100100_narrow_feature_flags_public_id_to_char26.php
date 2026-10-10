<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-056 AR-05 / CA-056-06 (paso 1.7b), `ADR-029`. La migración histórica
 * `2026_09_22_100100_create_feature_flags_tables.php` (REQ-BO-005, 1.6e)
 * declaró `public_id` como `text` en `feature_flags` y `feature_flag_rules`;
 * el resto de tablas con `public_id` (28) es `character(26) NOT NULL` con
 * índice único propio. Decisión del usuario (2026-10-07): corregir las dos
 * columnas en lugar de registrar una excepción.
 *
 * Seguridad del cambio de tipo (skill `migracion-segura`, `CLAUDE.md §9`):
 * - Ambas son tablas de catálogo diminutas (el catálogo de *flags* de los
 *   módulos y sus reglas), así que la reescritura de tabla que implica
 *   `ALTER COLUMN TYPE` es instantánea; se asume el `ACCESS EXCLUSIVE`
 *   breve. Es una desviación consciente de «no cambiar el tipo sin columna
 *   intermedia»: la columna intermedia solo aporta cuando la reescritura es
 *   costosa, y aquí el riesgo real (filas que no quepan) se cierra con la
 *   comprobación previa de longitud, que aborta si alguna fila no tiene
 *   exactamente 26 caracteres.
 * - Compatibilidad con la versión anterior del código: todo escritor genera
 *   un ULID de 26 caracteres (`Str::ulid()`), válido en `text` y en
 *   `character(26)`; la versión anterior sigue insertando sin cambios.
 * - El índice único de una sola columna lo reconstruye PostgreSQL sobre el
 *   tipo nuevo con el mismo nombre; los privilegios de tabla y los de
 *   columna (`GRANT UPDATE (...)` de `plataforma_platform`) no se alteran:
 *   un cambio de tipo conserva la ACL. No hay RLS en estas dos tablas.
 * - Transaccional (por defecto): la comprobación y el `ALTER` son atómicos
 *   y no hay sentencias que exijan quedar fuera de transacción.
 * - `down()` restaura `text` (sin pérdida: 26 caracteres siguen siendo 26).
 */
return new class extends Migration
{
    private const TABLES = ['feature_flags', 'feature_flag_rules'];

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        foreach (self::TABLES as $table) {
            $bad = $owner->selectOne("SELECT count(*) AS n FROM {$table} WHERE length(public_id) <> 26");

            if ((int) $bad->n > 0) {
                throw new RuntimeException("{$table}.public_id tiene {$bad->n} fila(s) con longitud distinta de 26: corregirlas a mano antes de migrar (ADR-056 AR-05).");
            }
        }

        foreach (self::TABLES as $table) {
            $owner->statement("ALTER TABLE {$table} ALTER COLUMN public_id TYPE character(26) USING public_id::character(26)");
        }
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        foreach (self::TABLES as $table) {
            $owner->statement("ALTER TABLE {$table} ALTER COLUMN public_id TYPE text USING public_id::text");
        }
    }
};
