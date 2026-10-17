<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §1.9 (1.11d-b, `REQ-ACAD-001`). Valores de una escala
 * cualitativa o tramos de una mixta, como filas hijas (`ADR-062 §5.1`,
 * `ADR-029`): identidad estable, `public_id` y clave foránea para `CALIF`.
 *
 * FK de **cuatro** columnas `grading_scale_values_tenant_year_scale_foreign`
 * `(tenant_id, academic_year_id, grading_scale_id, scale_type)` hacia
 * `grading_scales`, declarada a mano con nombre explícito (`tenantForeignId()`
 * solo emite dos columnas, issue #104), `NO ACTION` (`AR-13`, `ADR-057 §5.2`):
 * el motor garantiza mismo centro, mismo curso académico y mismo tipo que la
 * escala; un `UPDATE` del `scale_type` de una escala con valores falla con
 * `23503` (`CA-ACAD-086`). `scale_type` es copia del tipo de la escala, fijada
 * por el servicio.
 *
 * Lo que el motor NO garantiza —tramos dentro del rango, contiguos, umbral en
 * frontera, al menos un valor aprobado, precisión de los límites— lo valida el
 * servicio bajo el bloqueo de la escala (`datos.md §8.3`).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin
 * `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('grading_scale_values', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            $table->foreignId('grading_scale_id');
            $table->text('scale_type');
            $table->text('code');
            TenantMigration::translatableColumn($table, 'label');
            $table->integer('position');
            $table->boolean('is_passing')->nullable();
            $table->decimal('range_min', 5, 2)->nullable();
            $table->decimal('range_max', 5, 2)->nullable();

            $table->foreign(['tenant_id', 'academic_year_id', 'grading_scale_id', 'scale_type'], 'grading_scale_values_tenant_year_scale_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id', 'scale_type'])
                ->on('grading_scales');

            // Destino de la futura FK de una calificación cualitativa de `CALIF` (funcional.md §12).
            $table->unique(['tenant_id', 'academic_year_id', 'id'], 'grading_scale_values_tenant_year_id_unique');
        });

        $owner = DB::connection('pgsql_owner');

        // Cubre la FK de cuatro columnas (índice no parcial que empieza por sus columnas) y sirve a
        // los valores de una escala: lectura con la escala, sincronización y borrado (datos.md §3.1).
        $owner->statement(<<<'SQL'
            CREATE INDEX grading_scale_values_tenant_year_scale_idx
                ON grading_scale_values (tenant_id, academic_year_id, grading_scale_id, scale_type)
            SQL);

        // RN-ACAD-83: código único entre los valores vivos de su escala.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX grading_scale_values_tenant_scale_code_unique
                ON grading_scale_values (tenant_id, grading_scale_id, code)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE grading_scale_values ADD CONSTRAINT grading_scale_values_scale_type_check
                CHECK (scale_type IN ('cualitativa', 'mixta'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE grading_scale_values ADD CONSTRAINT grading_scale_values_position_check
                CHECK (position >= 1)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE grading_scale_values ADD CONSTRAINT grading_scale_values_type_fields_check
                CHECK (
                    (scale_type = 'cualitativa'
                        AND is_passing IS NOT NULL AND range_min IS NULL AND range_max IS NULL)
                    OR
                    (scale_type = 'mixta'
                        AND is_passing IS NULL AND range_min IS NOT NULL AND range_max IS NOT NULL
                        AND range_min <= range_max)
                )
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS grading_scale_values');
    }
};
