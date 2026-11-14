<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/datos.md §1.1 (1.14-a, `REQ-FAM-UNIT-001`). Unidad familiar: sin `academic_year_id` (`ADR-034 §4`), así
 * que ni `AR-13` ni el disparador `academic_year_write_guard` la miran. `principal_guardian_id` es la FK compuesta a
 * `guardians` que cierra la dependencia circular: se añade en una migración posterior, cuando existe `guardians`
 * (`2026_10_26_100300_add_family_units_principal_guardian_foreign_key`).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('family_units', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->text('code');
            $table->unsignedBigInteger('principal_guardian_id')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        // RN-FAM-UNIT-01: código único entre las unidades vivas del centro.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX family_units_tenant_code_unique
                ON family_units (tenant_id, code)
                WHERE deleted_at IS NULL
            SQL);

        // Búsqueda por prefijo de código del listado (`q`, api.md §3): solo sirve con `text_pattern_ops`.
        $owner->statement(<<<'SQL'
            CREATE INDEX family_units_tenant_code_prefix_idx
                ON family_units (tenant_id, code text_pattern_ops)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE family_units ADD CONSTRAINT family_units_code_length_check
                CHECK (length(code) BETWEEN 1 AND 40)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS family_units');
    }
};
