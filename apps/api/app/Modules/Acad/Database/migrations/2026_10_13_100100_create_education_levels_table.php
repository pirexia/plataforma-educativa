<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §1.1 (1.11-b, `REQ-ACAD-001`). Etapa educativa de un
 * curso académico. Primera tabla real con `academic_year_id`: el disparador
 * `academic_year_write_guard` (`ADR-057`) lo engancha `tenantTable()` solo,
 * y `tenantForeignId()` crea la FK compuesta y el índice (tenant_id,
 * academic_year_id) (#407). Nombre traducible con el ayudante de
 * `ADR-062 §5.1` (el `CHECK` lo emite él, `AR-16`). Tabla nueva y vacía:
 * `ADD CONSTRAINT` sin `NOT VALID` e índices sin `CONCURRENTLY` son
 * inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('education_levels', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            $table->text('code');
            TenantMigration::translatableColumn($table, 'name');
            $table->text('stage');
            $table->text('regime');
            $table->integer('position');

            // Destino de la FK de tres columnas de `courses` (RN-CURSO-27).
            // No parcial (ADR-034 §6).
            $table->unique(['tenant_id', 'academic_year_id', 'id'], 'education_levels_tenant_year_id_unique');
        });

        $owner = DB::connection('pgsql_owner');

        // RN-ACAD-01: código único entre hermanos vivos.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX education_levels_tenant_year_code_unique
                ON education_levels (tenant_id, academic_year_id, code)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE education_levels ADD CONSTRAINT education_levels_stage_check
                CHECK (stage IN ('infantil', 'primaria', 'eso', 'bachillerato', 'fp', 'universidad'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE education_levels ADD CONSTRAINT education_levels_regime_check
                CHECK (regime IN ('publico', 'privado', 'concertado'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE education_levels ADD CONSTRAINT education_levels_position_check
                CHECK (position >= 1)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS education_levels');
    }
};
