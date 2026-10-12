<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §1.6 (1.11-b, `REQ-ACAD-001`). Asignaturas que cursa un
 * grupo (el par grupo x asignatura es la «clase» de `ADR-063 §5.3`).
 * `course_id` está desnormalizado a propósito: las dos FK de tres columnas
 * `(tenant_id, course_id, group_id)` y `(tenant_id, course_id, subject_id)`
 * hacen que el motor garantice `RN-ACAD-30` (`CA-ACAD-019`).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('group_subjects', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            $table->foreignId('course_id');
            $table->foreignId('group_id');
            $table->foreignId('subject_id');

            $table->foreign(['tenant_id', 'academic_year_id', 'course_id'], 'group_subjects_tenant_year_course_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('courses');
            $table->foreign(['tenant_id', 'course_id', 'group_id'], 'group_subjects_tenant_course_group_foreign')
                ->references(['tenant_id', 'course_id', 'id'])
                ->on('groups');
            $table->foreign(['tenant_id', 'course_id', 'subject_id'], 'group_subjects_tenant_course_subject_foreign')
                ->references(['tenant_id', 'course_id', 'id'])
                ->on('subjects');
        });

        $owner = DB::connection('pgsql_owner');

        // Una asignatura una vez por grupo; GET/PUT /groups/{id}/subjects.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX group_subjects_tenant_group_subject_unique
                ON group_subjects (tenant_id, group_id, subject_id)
                WHERE deleted_at IS NULL
            SQL);

        // Índices no parciales que cubren las dos claves foráneas de tres columnas
        // (comprobación de integridad ante un DELETE físico del padre, p. ej. la
        // purga de un tenant; un parcial no las cubre, operacion.md §6.2, #407).
        $owner->statement('CREATE INDEX group_subjects_tenant_course_group_idx ON group_subjects (tenant_id, course_id, group_id)');
        $owner->statement('CREATE INDEX group_subjects_tenant_course_subject_idx ON group_subjects (tenant_id, course_id, subject_id)');

        // Borrado de una asignatura (RN-ACAD-20), ficha con sus grupos.
        $owner->statement(<<<'SQL'
            CREATE INDEX group_subjects_tenant_subject_idx
                ON group_subjects (tenant_id, subject_id)
                WHERE deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS group_subjects');
    }
};
