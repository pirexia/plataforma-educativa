<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CURSO/datos.md §1.6 (1.12b, `REQ-CURSO-002`, `RN-CURSO-67`, `-69`, `-73` a `-75`).
 * Una fila por **copia** (ejecución del *rollover*): selección efectiva, estado, resultado
 * y fallo. Es el «log de resultados» del requisito.
 *
 * `tenantTable()` da RLS `ENABLE`+`FORCE`, autoría, `timestamptz`, borrado lógico y
 * `UNIQUE (tenant_id, id)`; por llevar `academic_year_id` (el curso **destino**) engancha
 * el disparador `academic_year_write_guard`, sin excepción en `AR-13`. El curso **origen**
 * va en `source_academic_year_id`, que el disparador no mira (cerrar el origen no congela la
 * copia). Declaradas a mano (revisión de documentación M-4): `public_id` y `reverted_by`
 * (anulable, que `tenantForeignId()` no crea). Ninguna FK con acción referencial (`AR-13`).
 *
 * Tabla nueva y vacía: no hay nada que validar bajo bloqueo (`CHECK` y únicos sin `NOT VALID`).
 * No altera `academic_years` ni `app.assert_academic_year_writable()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('year_rollovers', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            // `withIndex: false`: el único no parcial `(tenant_id, academic_year_id, id)` de abajo ya cubre el prefijo
            // `(tenant_id, academic_year_id)` (FK y listado por destino); el índice del ayudante sería redundante (#446-c).
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years', withIndex: false);
            TenantMigration::tenantForeignId($table, 'source_academic_year_id', 'academic_years');
            $table->text('status')->default('pendiente');
            $table->jsonb('elements');
            $table->boolean('copy_teaching_assignments')->default(false);
            $table->integer('date_offset_days')->nullable();
            $table->jsonb('result')->nullable();
            $table->jsonb('failure')->nullable();
            $table->jsonb('revert_failure')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            // Anulable: `tenantForeignId()` crearía una columna NOT NULL (M-4).
            $table->unsignedBigInteger('reverted_by')->nullable();
            $table->timestampTz('reverted_at')->nullable();
            $table->foreign(['tenant_id', 'reverted_by'], 'year_rollovers_tenant_reverted_by_foreign')
                ->references(['tenant_id', 'id'])
                ->on('users');
            $table->index(['tenant_id', 'reverted_by'], 'year_rollovers_tenant_reverted_by_idx');
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            ALTER TABLE year_rollovers ADD CONSTRAINT year_rollovers_status_check
                CHECK (status IN ('pendiente', 'en_curso', 'completado', 'fallido', 'revirtiendo', 'revertido'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE year_rollovers ADD CONSTRAINT year_rollovers_elements_check
                CHECK (jsonb_typeof(elements) = 'array' AND jsonb_array_length(elements) >= 1)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE year_rollovers ADD CONSTRAINT year_rollovers_distinct_years_check
                CHECK (source_academic_year_id <> academic_year_id)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE year_rollovers ADD CONSTRAINT year_rollovers_revert_check
                CHECK (
                    (status NOT IN ('revirtiendo', 'revertido') OR reverted_by IS NOT NULL)
                    AND (status <> 'revertido' OR reverted_at IS NOT NULL)
                )
            SQL);

        // No parcial: destino de la FK de tres columnas de `year_rollover_items` (RN-CURSO-27).
        $owner->statement(<<<'SQL'
            ALTER TABLE year_rollovers ADD CONSTRAINT year_rollovers_tenant_year_id_unique
                UNIQUE (tenant_id, academic_year_id, id)
            SQL);

        // Una copia viva por destino (RN-CURSO-67) y la lectura «¿hay copia en vuelo?» de la
        // activación (RN-CURSO-71). La carrera se traduce a `409 rollover_exists`, nunca `500`.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX year_rollovers_tenant_year_live_unique
                ON year_rollovers (tenant_id, academic_year_id)
                WHERE status IN ('pendiente', 'en_curso', 'completado', 'revirtiendo') AND deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS year_rollovers');
    }
};
