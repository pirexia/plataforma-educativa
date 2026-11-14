<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/datos.md §1.2 (1.14-a, `REQ-FAM-UNIT-001`). Tutor: faceta 0..1 de `people` (`ADR-034 §1`) que pertenece
 * a una sola unidad (`§16.2`, `RN-FAM-UNIT-11`). No guarda nombre, correo ni teléfono: son de `people`/`users`.
 *
 * Tabla nueva y vacía: índices sin `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('guardians', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'person_id', 'people');
            TenantMigration::tenantForeignId($table, 'family_unit_id', 'family_units');
        });

        // `ADR-034 §1`: como mucho una faceta de tutor viva por persona.
        DB::connection('pgsql_owner')->statement(<<<'SQL'
            CREATE UNIQUE INDEX guardians_tenant_person_unique
                ON guardians (tenant_id, person_id)
                WHERE deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS guardians');
    }
};
