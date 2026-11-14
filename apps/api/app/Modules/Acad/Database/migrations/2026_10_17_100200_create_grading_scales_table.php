<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §1.8 (1.11d-b, `REQ-ACAD-001`). Escala de calificación de
 * un curso académico: numérica, cualitativa o mixta. Los campos numéricos son
 * anulables porque no aplican a la cualitativa; el `CHECK` de coherencia por
 * tipo (`grading_scales_type_fields_check`) los exige o prohíbe según
 * `scale_type`. `numeric(5,2)` y no céntimos: una calificación no es un importe.
 *
 * El disparador `academic_year_write_guard` lo engancha `tenantTable()` por
 * tener `academic_year_id`. Nombre traducible con el ayudante de `ADR-062 §5.1`.
 * La restricción única `(tenant_id, academic_year_id, id, scale_type)` es el
 * destino de la FK de cuatro columnas de `grading_scale_values` (§1.9).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin
 * `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('grading_scales', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            $table->text('code');
            TenantMigration::translatableColumn($table, 'name');
            $table->text('scale_type');
            $table->decimal('min_value', 5, 2)->nullable();
            $table->decimal('max_value', 5, 2)->nullable();
            $table->smallInteger('decimals')->nullable();
            $table->decimal('passing_min_value', 5, 2)->nullable();

            // Destino de la FK de cuatro columnas de `grading_scale_values`: garantiza mismo
            // centro, mismo curso académico y mismo tipo que la escala (datos.md §1.9).
            $table->unique(['tenant_id', 'academic_year_id', 'id', 'scale_type'], 'grading_scales_tenant_year_id_type_unique');
        });

        $owner = DB::connection('pgsql_owner');

        // RN-ACAD-71: código único entre las escalas vivas del curso; la carrera se traduce a 422 code_taken.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX grading_scales_tenant_year_code_unique
                ON grading_scales (tenant_id, academic_year_id, code)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE grading_scales ADD CONSTRAINT grading_scales_scale_type_check
                CHECK (scale_type IN ('numerica', 'cualitativa', 'mixta'))
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE grading_scales ADD CONSTRAINT grading_scales_type_fields_check
                CHECK (
                    (scale_type = 'cualitativa'
                        AND min_value IS NULL AND max_value IS NULL
                        AND decimals IS NULL AND passing_min_value IS NULL)
                    OR
                    (scale_type IN ('numerica', 'mixta')
                        AND min_value IS NOT NULL AND max_value IS NOT NULL
                        AND decimals IS NOT NULL AND passing_min_value IS NOT NULL
                        AND decimals BETWEEN 0 AND 2
                        AND min_value < max_value
                        AND passing_min_value BETWEEN min_value AND max_value
                        AND round(min_value, decimals) = min_value
                        AND round(max_value, decimals) = max_value
                        AND round(passing_min_value, decimals) = passing_min_value)
                )
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS grading_scales');
    }
};
