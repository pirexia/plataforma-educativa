<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/datos.md §1.3 (1.14-a, `REQ-FAM-UNIT-001`). Pertenencia de la persona de un alumno a una unidad
 * (`funcional.md §7.4`, `OPEN-FAM-UNIT-01`): tabla propia y no `students.family_unit_id`, porque `students` es de
 * `REQ-ALUM` (1.15) y no existe.
 *
 * Tabla nueva y vacía: índices sin `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('family_unit_students', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'family_unit_id', 'family_units');
            TenantMigration::tenantForeignId($table, 'student_person_id', 'people');
        });

        // RN-FAM-UNIT-20, `§16.2`: una persona de alumno pertenece como mucho a una unidad viva.
        DB::connection('pgsql_owner')->statement(<<<'SQL'
            CREATE UNIQUE INDEX family_unit_students_tenant_student_unique
                ON family_unit_students (tenant_id, student_person_id)
                WHERE deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS family_unit_students');
    }
};
