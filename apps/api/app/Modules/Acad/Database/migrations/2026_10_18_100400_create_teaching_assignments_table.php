<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §9.1 (1.12-a, `REQ-ACAD-002`). Asignación docente: un
 * usuario imparte una asignatura que cursa un grupo, opcionalmente a un
 * subgrupo. Tres FK de tres columnas declaradas a mano con nombre explícito
 * (`tenantForeignId()` solo emite dos, issue #104), sin acción referencial
 * (`NO ACTION`, `AR-13`, `ADR-057 §5.2`):
 *
 * - `(tenant_id, academic_year_id, group_id)` → `groups`: mismo curso.
 * - `(tenant_id, group_id, group_subject_id)` → `group_subjects` (único nuevo,
 *   migración anterior): la asignatura es del grupo.
 * - `(tenant_id, group_id, subgroup_id)` → `subgroups`, `MATCH SIMPLE`: con
 *   `NULL` no se comprueba; con valor, el subgrupo es del grupo.
 *
 * `user_id` referencia a `users` como las FK de autoría (`OPEN-ACAD-34` = A).
 * La unicidad entre vivas por (usuario, asignatura del grupo, subgrupo) cuenta
 * `NULL` como valor (`NULLS NOT DISTINCT`, PostgreSQL 18, `ADR-061`,
 * `RN-ACAD-94`).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin
 * `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('teaching_assignments', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            TenantMigration::tenantForeignId($table, 'user_id', 'users');
            $table->foreignId('group_id');
            $table->foreignId('group_subject_id');
            $table->unsignedBigInteger('subgroup_id')->nullable();

            $table->foreign(['tenant_id', 'academic_year_id', 'group_id'], 'teaching_assignments_tenant_year_group_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('groups');
            $table->foreign(['tenant_id', 'group_id', 'group_subject_id'], 'teaching_assignments_tenant_group_gsubject_foreign')
                ->references(['tenant_id', 'group_id', 'id'])
                ->on('group_subjects');
            $table->foreign(['tenant_id', 'group_id', 'subgroup_id'], 'teaching_assignments_tenant_group_subgroup_foreign')
                ->references(['tenant_id', 'group_id', 'id'])
                ->on('subgroups');

            // Destino de la FK de las sesiones (mismo curso académico por el motor).
            $table->unique(['tenant_id', 'academic_year_id', 'id'], 'teaching_assignments_tenant_year_id_unique');
        });

        $owner = DB::connection('pgsql_owner');

        // Índices no parciales que cubren las tres FK de tres columnas y sirven a
        // las asignaciones de un grupo y al `has_dependents` de grupo, asignatura y subgrupo (RN-ACAD-96).
        $owner->statement('CREATE INDEX teaching_assignments_tenant_year_group_idx ON teaching_assignments (tenant_id, academic_year_id, group_id)');
        $owner->statement('CREATE INDEX teaching_assignments_tenant_group_gsubject_idx ON teaching_assignments (tenant_id, group_id, group_subject_id)');
        $owner->statement('CREATE INDEX teaching_assignments_tenant_group_subgroup_idx ON teaching_assignments (tenant_id, group_id, subgroup_id)');

        // RN-ACAD-94.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX teaching_assignments_tenant_user_gsubject_subgroup_unique
                ON teaching_assignments (tenant_id, user_id, group_subject_id, subgroup_id) NULLS NOT DISTINCT
                WHERE deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS teaching_assignments');
    }
};
