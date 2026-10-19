<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §9.1 (1.12-a, `REQ-ACAD-002`). Ancla de serialización del
 * horario de un curso académico (`RN-ACAD-115`, `RN-ACAD-125`): una fila por
 * centro y curso, sobre la que toda escritura de sesiones toma `FOR UPDATE`
 * antes de leer las sesiones con que compara (sin `btree_gist`, el motor no
 * garantiza los conflictos). `UNIQUE (tenant_id, academic_year_id)` **no
 * parcial**: el ancla no se borra nunca.
 *
 * Tabla nueva y vacía: índices sin `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('timetables', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');

            $table->unique(['tenant_id', 'academic_year_id'], 'timetables_tenant_year_unique');
        });
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS timetables');
    }
};
