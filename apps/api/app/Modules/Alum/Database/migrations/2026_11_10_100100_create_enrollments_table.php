<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ALUM/datos.md §1.2, §3 (1.15-b, `REQ-ALUM-002`). Matrícula: alumno en un curso académico, un curso de etapa y,
 * opcionalmente, un grupo (`OPEN-ALUM-11` = B). Las dos FK compuestas encadenan matrícula → curso de etapa del mismo
 * curso académico → grupo de ese curso de etapa (`RN-ALUM-20`); sus destinos ya existen en `acad`
 * (`courses_tenant_year_id_unique`, `groups_tenant_course_id_unique`). `academic_year_id` con `tenantForeignId()`
 * hace que `tenantTable()` ponga el disparador `academic_year_write_guard` (`ADR-057`). Sin ENUM, sin CASCADE ni
 * SET NULL, sin partición (~1.200 filas por centro y curso, `ADR-029`).
 *
 * Tabla nueva y vacía: los índices sin `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('enrollments', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'student_id', 'students');
            // Sin índice propio (`withIndex: false`): `enrollments_tenant_year_course_idx` empieza por `(tenant_id, academic_year_id)`
            // y ya cubre la FK (`AR-16`); uno más solo encarecería cada escritura (revisión de `db-reviewer`, 1.15-b).
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years', null, false);
            $table->foreignId('course_id');
            $table->foreignId('group_id')->nullable();
            $table->text('status')->default('activa');
            $table->date('enrolled_on');
            $table->date('withdrawn_on')->nullable();
            $table->text('withdrawal_reason')->nullable();

            // `RN-ALUM-20`: el curso de etapa es del mismo curso académico que la matrícula.
            $table->foreign(['tenant_id', 'academic_year_id', 'course_id'], 'enrollments_tenant_year_course_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('courses');
            // `RN-ALUM-20`: el grupo es del curso de etapa de la matrícula. `MATCH SIMPLE` (por defecto): con
            // `group_id` nulo la FK no se comprueba.
            $table->foreign(['tenant_id', 'course_id', 'group_id'], 'enrollments_tenant_course_group_foreign')
                ->references(['tenant_id', 'course_id', 'id'])
                ->on('groups');

            // Listado por curso académico y curso de etapa; cubre la FK a `courses` y el veto de borrado de curso.
            $table->index(['tenant_id', 'academic_year_id', 'course_id'], 'enrollments_tenant_year_course_idx');
            // Cubre la FK a `groups` (`AR-16`: el índice empieza por las columnas de la FK, en su orden). Sustituye
            // al `UNIQUE (tenant_id, course_id, group_id, id)` opcional de datos.md §1.2, que solo hará falta
            // cuando `group_change_requests` apunte a la matrícula (lo decide `db-reviewer`).
            $table->index(['tenant_id', 'course_id', 'group_id'], 'enrollments_tenant_course_group_idx');
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            ALTER TABLE enrollments ADD CONSTRAINT enrollments_status_check
                CHECK (status IN ('activa', 'baja'))
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE enrollments ADD CONSTRAINT enrollments_withdrawal_reason_check
                CHECK (withdrawal_reason IN ('traslado', 'baja_voluntaria', 'otro'))
            SQL);

        // `RN-ALUM-22`: coherencia de estado y baja.
        $owner->statement(<<<'SQL'
            ALTER TABLE enrollments ADD CONSTRAINT enrollments_status_withdrawal_check
                CHECK (
                    (status = 'activa' AND withdrawn_on IS NULL AND withdrawal_reason IS NULL)
                    OR (status = 'baja' AND withdrawn_on IS NOT NULL AND withdrawal_reason IS NOT NULL AND withdrawn_on >= enrolled_on)
                )
            SQL);

        // `RN-ALUM-21`: una matrícula activa viva por alumno y curso académico.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX enrollments_tenant_year_student_active_unique
                ON enrollments (tenant_id, academic_year_id, student_id)
                WHERE status = 'activa' AND deleted_at IS NULL
            SQL);

        // Lista de clase y resolutor `grupo` (`group_id IN (…)`).
        $owner->statement(<<<'SQL'
            CREATE INDEX enrollments_tenant_group_status_idx
                ON enrollments (tenant_id, group_id, status)
                WHERE deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS enrollments');
    }
};
