<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/datos.md §1.1 (issue #469, `RN-FAM-UNIT-02`). Garantiza en la base de datos que el tutor principal de una
 * unidad es **un tutor de esa misma unidad**, hasta ahora solo comprobado en servicio. Fase *expand*, aditiva: no toca la
 * FK `(tenant_id, principal_guardian_id)` → `guardians (tenant_id, id)` ya desplegada.
 *
 * - `UNIQUE (tenant_id, family_unit_id, id)` en `guardians`: destino de la nueva FK (`id` ya es único, así que no restringe
 *   nada).
 * - FK compuesta `family_units (tenant_id, id, principal_guardian_id)` → `guardians (tenant_id, family_unit_id, id)`:
 *   la `id` de la unidad hace de `family_unit_id` del tutor. `MATCH SIMPLE` (por defecto): con `principal_guardian_id`
 *   `NULL` no se comprueba, así que «unidad sin principal» (`RN-FAM-UNIT-12`, `-38`) sigue permitida. Un tutor da de baja
 *   (lógica) conserva su fila, así que la FK no interviene en las bajas.
 * - Índice `(tenant_id, id, principal_guardian_id)` que cubre la FK (`AR-16`; excepción consciente de `funcional.md §15` D-12: sobra para el motor, existe para satisfacer la regla).
 *
 * `$withinTransaction = false`, `CREATE … CONCURRENTLY` y `NOT VALID` + `VALIDATE CONSTRAINT` (`migracion-segura`): las
 * tablas están en uso desde `1.14-a`. Reintentable y no destructivo: cada paso es `IF [NOT] EXISTS`, y la FK ya validada no se toca;
 * un índice `INVALID` que dejara un `CONCURRENTLY` interrumpido se descarta antes de recrearlo. Si hubiera una unidad
 * cuyo principal es de otra, `VALIDATE` aborta con la fila infractora y la FK queda `NOT VALID` (no bloquea escrituras
 * nuevas, que ya se comprueban): corregir el dato y repetir.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const FOREIGN_KEY = 'family_units_principal_same_unit_foreign';

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $this->dropIfInvalid('guardians_tenant_unit_id_unique');
        $owner->statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS guardians_tenant_unit_id_unique ON guardians (tenant_id, family_unit_id, id)');

        $this->dropIfInvalid('family_units_tenant_id_principal_guardian_idx');
        $owner->statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS family_units_tenant_id_principal_guardian_idx ON family_units (tenant_id, id, principal_guardian_id)');

        // Relanzar no es destructivo: con la FK ya validada no se toca; con una `NOT VALID` de un intento anterior fallido solo
        // se reintenta `VALIDATE`; solo si falta se añade. `lock_timeout` breve (y restablecido) para no encolar las
        // escrituras de la tabla detrás de un `ALTER TABLE` que espera un bloqueo (`TenantMigration::GUARD_LOCK_TIMEOUT`).
        $state = $owner->selectOne(
            'SELECT convalidated FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass',
            [self::FOREIGN_KEY, 'family_units'],
        );

        if ($state !== null && $state->convalidated) {
            return;
        }

        $previous = $owner->selectOne("SELECT current_setting('lock_timeout') AS v")->v;
        $owner->statement("SET lock_timeout = '5s'");

        try {
            if ($state === null) {
                $owner->statement(<<<'SQL'
                    ALTER TABLE family_units ADD CONSTRAINT family_units_principal_same_unit_foreign
                        FOREIGN KEY (tenant_id, id, principal_guardian_id) REFERENCES guardians (tenant_id, family_unit_id, id) NOT VALID
                    SQL);
            }

            $owner->statement('ALTER TABLE family_units VALIDATE CONSTRAINT family_units_principal_same_unit_foreign');
        } finally {
            $owner->statement("SET lock_timeout = '{$previous}'");
        }
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $previous = $owner->selectOne("SELECT current_setting('lock_timeout') AS v")->v;
        $owner->statement("SET lock_timeout = '5s'");

        try {
            $owner->statement('ALTER TABLE family_units DROP CONSTRAINT IF EXISTS family_units_principal_same_unit_foreign');
        } finally {
            $owner->statement("SET lock_timeout = '{$previous}'");
        }

        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS family_units_tenant_id_principal_guardian_idx');
        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS guardians_tenant_unit_id_unique');
    }

    /** Un `CREATE INDEX CONCURRENTLY` interrumpido deja un índice no válido que `IF NOT EXISTS` daría por bueno. */
    private function dropIfInvalid(string $index): void
    {
        $invalid = DB::connection('pgsql_owner')->selectOne(
            'SELECT 1 AS found FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND NOT i.indisvalid',
            [$index],
        );

        if ($invalid !== null) {
            DB::connection('pgsql_owner')->statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }
};
