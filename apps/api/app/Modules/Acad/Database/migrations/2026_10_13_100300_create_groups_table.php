<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §1.3 (1.11-b, `REQ-ACAD-001`). Grupo de un curso de
 * etapa. `groups` es palabra clave no reservada de PostgreSQL: se admite
 * como identificador. `name` es texto simple, no traducible (funcional.md
 * Anexo A punto 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('groups', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            $table->foreignId('course_id');
            $table->text('code');
            $table->text('name');
            $table->integer('position');

            $table->foreign(['tenant_id', 'academic_year_id', 'course_id'], 'groups_tenant_year_course_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('courses');

            // Destino de `subgroups` (y, en 1.15, de la matrícula).
            $table->unique(['tenant_id', 'academic_year_id', 'id'], 'groups_tenant_year_id_unique');
            // Destino de la FK de `group_subjects`.
            $table->unique(['tenant_id', 'course_id', 'id'], 'groups_tenant_course_id_unique');
        });

        $owner = DB::connection('pgsql_owner');

        // RN-ACAD-01; listado ?course=; hijos vivos de un curso de etapa.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX groups_tenant_course_code_unique
                ON groups (tenant_id, course_id, code)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE groups ADD CONSTRAINT groups_position_check CHECK (position >= 1)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS groups');
    }
};
