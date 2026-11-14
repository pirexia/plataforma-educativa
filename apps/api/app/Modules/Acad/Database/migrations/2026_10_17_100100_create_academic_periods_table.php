<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §1.7 (1.11d-a, `REQ-ACAD-001`). Período lectivo de una
 * etapa (trimestre, cuatrimestre o semestre). FK de tres columnas a
 * `education_levels` declarada a mano con nombre explícito (`tenantForeignId()`
 * solo emite dos, issue #104); sin acción referencial (`NO ACTION`, `AR-13`,
 * `ADR-057 §5.2`). El disparador `academic_year_write_guard` lo engancha
 * `tenantTable()` por tener `academic_year_id`. Nombre traducible con el
 * ayudante de `ADR-062 §5.1` (`AR-16`).
 *
 * Lo que el motor NO garantiza —período dentro del curso, sin solape y un solo
 * tipo por etapa— lo valida el servicio bajo el bloqueo de `RN-ACAD-54`
 * (`datos.md §8.3`): sin `EXCLUDE` ni `btree_gist` (`ADR-034 §4`).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin
 * `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('academic_periods', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            $table->foreignId('education_level_id');
            $table->text('period_type');
            TenantMigration::translatableColumn($table, 'name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->integer('position');

            $table->foreign(['tenant_id', 'academic_year_id', 'education_level_id'], 'academic_periods_tenant_year_level_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('education_levels');

            // Destino de las FK futuras de `CALIF` que imponen el mismo curso académico (funcional.md §12).
            $table->unique(['tenant_id', 'academic_year_id', 'id'], 'academic_periods_tenant_year_id_unique');
        });

        $owner = DB::connection('pgsql_owner');

        // Cubre la FK de tres columnas (un índice no parcial que empieza por sus columnas) y
        // sirve a los períodos de una etapa: `?education_level=`, solape y tipo (RN-ACAD-58/-59)
        // e hijos vivos de una etapa (RN-ACAD-61). Datos.md §3.1.
        $owner->statement(<<<'SQL'
            CREATE INDEX academic_periods_tenant_year_level_idx
                ON academic_periods (tenant_id, academic_year_id, education_level_id)
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE academic_periods ADD CONSTRAINT academic_periods_period_type_check
                CHECK (period_type IN ('trimestre', 'cuatrimestre', 'semestre'))
            SQL);
        // RN-ACAD-56: `>` y no `>=`, como `academic_years`.
        $owner->statement(<<<'SQL'
            ALTER TABLE academic_periods ADD CONSTRAINT academic_periods_dates_check
                CHECK (ends_on > starts_on)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE academic_periods ADD CONSTRAINT academic_periods_position_check
                CHECK (position >= 1)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS academic_periods');
    }
};
