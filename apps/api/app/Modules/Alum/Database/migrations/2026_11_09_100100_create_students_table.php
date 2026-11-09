<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ALUM/datos.md §1.1 (1.15-a, `REQ-ALUM-001`). Alumno: faceta de `people` (`ADR-034 §1`). No guarda nombre,
 * documento, fecha de nacimiento ni contacto (`RN-ALUM-02`); sin `academic_year_id` (ser alumno no depende del curso).
 * Sin FK a `acad` ni `fam_unit`: la pertenencia a una unidad es de `fam_unit` (`FamilyUnitStudents`).
 *
 * Tabla nueva y vacía: el índice sin `CONCURRENTLY` es inocuo.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('students', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'person_id', 'people');
        });

        // `ADR-034 §1`, `RN-ALUM-01`: como mucho una faceta de alumno viva por persona.
        DB::connection('pgsql_owner')->statement(<<<'SQL'
            CREATE UNIQUE INDEX students_tenant_person_unique
                ON students (tenant_id, person_id)
                WHERE deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS students');
    }
};
