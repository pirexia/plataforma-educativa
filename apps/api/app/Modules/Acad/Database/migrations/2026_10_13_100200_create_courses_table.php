<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §1.2 (1.11-b, `REQ-ACAD-001`). Curso de etapa. FK de
 * tres columnas a `education_levels` declarada a mano: `tenantForeignId()`
 * solo emite dos (excepción justificada a la convención para referencias
 * obligatorias, issue #104). Sin acción referencial (`NO ACTION`, `AR-13`,
 * `ADR-057 §5.2`).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('courses', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            $table->foreignId('education_level_id');
            $table->text('code');
            TenantMigration::translatableColumn($table, 'name');
            $table->integer('position');

            $table->foreign(['tenant_id', 'academic_year_id', 'education_level_id'], 'courses_tenant_year_level_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('education_levels');

            // Destino de las FK de `groups` y `subjects`.
            $table->unique(['tenant_id', 'academic_year_id', 'id'], 'courses_tenant_year_id_unique');
        });

        $owner = DB::connection('pgsql_owner');

        // RN-ACAD-01.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX courses_tenant_year_code_unique
                ON courses (tenant_id, academic_year_id, code)
                WHERE deleted_at IS NULL
            SQL);

        // Listado ?education_level= e hijos vivos de una etapa (RN-ACAD-20). Al ser
        // parcial no cubre la FK de tres columnas; esa la sirve el índice
        // (tenant_id, academic_year_id) del ayudante, por prefijo.
        $owner->statement(<<<'SQL'
            CREATE INDEX courses_tenant_level_idx
                ON courses (tenant_id, education_level_id)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE courses ADD CONSTRAINT courses_position_check CHECK (position >= 1)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS courses');
    }
};
