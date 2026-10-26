<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/datos.md §1.1 (1.14-a). Cierra la dependencia circular `family_units.principal_guardian_id` →
 * `guardians` (y `guardians.family_unit_id` → `family_units`): las dos tablas se crean antes y la FK compuesta se
 * añade ahora. No hace falta `DEFERRABLE`: el principal siempre se designa después de crear el tutor.
 *
 * Que el principal sea un tutor vivo **de esta unidad** lo valida el servicio (`RN-FAM-UNIT-02`): una FK no puede
 * expresar «de la misma unidad» sin una columna redundante. Sin acción referencial (`NO ACTION`): las bajas son
 * lógicas y se propagan en servicio. Tablas nuevas y vacías: `ADD CONSTRAINT` sin `NOT VALID` es inocuo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            ALTER TABLE family_units ADD CONSTRAINT family_units_tenant_principal_guardian_foreign
                FOREIGN KEY (tenant_id, principal_guardian_id) REFERENCES guardians (tenant_id, id)
            SQL);

        $index = TenantMigration::indexName('family_units', 'principal_guardian_id');
        $owner->statement("CREATE INDEX {$index} ON family_units (tenant_id, principal_guardian_id)");
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE family_units DROP CONSTRAINT IF EXISTS family_units_tenant_principal_guardian_foreign');
        $owner->statement('DROP INDEX IF EXISTS '.TenantMigration::indexName('family_units', 'principal_guardian_id'));
    }
};
