<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §9.3 (1.12-a, `REQ-ACAD-002`), fase *expand*. Dos únicos
 * nuevos en tablas que ya existen en `develop`, destino de las FK de
 * `teaching_assignments` que garantizan que la asignatura (`group_subject`) y el
 * subgrupo son del grupo de la asignación (`RN-ACAD-93`): `(tenant_id, group_id,
 * id)` en `group_subjects` y en `subgroups`. Aditivos y compatibles con la
 * versión anterior, que no los usa.
 *
 * `$withinTransaction = false`: el índice se crea con `CONCURRENTLY` (no bloquea
 * escrituras) y luego se promueve a restricción con `ADD CONSTRAINT … UNIQUE
 * USING INDEX` (bloqueo breve, sin reescaneo). Idempotente: un reintento tras un
 * fallo parcial no se atasca.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, string> tabla => nombre de la restricción */
    private const UNIQUES = [
        'group_subjects' => 'group_subjects_tenant_group_id_unique',
        'subgroups' => 'subgroups_tenant_group_id_unique',
    ];

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        foreach (self::UNIQUES as $table => $name) {
            $exists = $owner->selectOne('SELECT 1 AS ok FROM pg_constraint WHERE conname = ?', [$name]);

            if ($exists !== null) {
                continue;
            }

            // Un índice inválido de un intento anterior interrumpido se descarta antes de recrearlo.
            $owner->statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            $owner->statement("CREATE UNIQUE INDEX CONCURRENTLY {$name} ON {$table} (tenant_id, group_id, id)");
            $owner->statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} UNIQUE USING INDEX {$name}");
        }
    }

    public function down(): void
    {
        // Aditiva; revertirla no aporta nada y la versión anterior convive con ella (operacion.md §12.5).
        $owner = DB::connection('pgsql_owner');

        foreach (self::UNIQUES as $table => $name) {
            $owner->statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
    }
};
