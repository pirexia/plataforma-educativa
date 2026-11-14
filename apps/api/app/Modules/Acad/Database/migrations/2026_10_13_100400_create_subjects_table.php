<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §1.5 (1.11-b, `REQ-ACAD-001`). Asignatura de un curso de
 * etapa. Tabla de referencia para la medición del disparador
 * (`AcademicYearWriteGuardOverheadTest`, `CA-ACAD-023`).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('subjects', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            $table->foreignId('course_id');
            $table->text('code');
            TenantMigration::translatableColumn($table, 'name');
            $table->integer('position');

            $table->foreign(['tenant_id', 'academic_year_id', 'course_id'], 'subjects_tenant_year_course_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('courses');

            // Destino de la FK de `group_subjects` (RN-ACAD-30).
            $table->unique(['tenant_id', 'course_id', 'id'], 'subjects_tenant_course_id_unique');
        });

        $owner = DB::connection('pgsql_owner');

        // RN-ACAD-01; listado ?course=; hijos vivos.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX subjects_tenant_course_code_unique
                ON subjects (tenant_id, course_id, code)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE subjects ADD CONSTRAINT subjects_position_check CHECK (position >= 1)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS subjects');
    }
};
