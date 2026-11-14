<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §9.1 (1.12-a, `REQ-ACAD-002`). Marco horario: conjunto de
 * franjas de una etapa en un curso académico, `ordinaria` (todo el curso) o
 * `especial` (vigencia por fechas, `1.12-b`). Se crea ya con `valid_from` y
 * `valid_to` para no alterar la tabla después; en `1.12-a` el servicio solo
 * admite `ordinaria` (`RN-ACAD-110`). A lo sumo un `ordinaria` vivo por etapa
 * (índice único parcial). FK de tres columnas a `education_levels` a mano, sin
 * acción referencial (`AR-13`). Nombre traducible (`ADR-062`, `AR-16`).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin
 * `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('timetable_frames', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            $table->foreignId('education_level_id');
            $table->text('frame_type');
            TenantMigration::translatableColumn($table, 'name');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            $table->foreign(['tenant_id', 'academic_year_id', 'education_level_id'], 'timetable_frames_tenant_year_level_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('education_levels');

            // Destino de la FK de `time_slots`.
            $table->unique(['tenant_id', 'academic_year_id', 'id'], 'timetable_frames_tenant_year_id_unique');
        });

        $owner = DB::connection('pgsql_owner');

        // Cubre la FK de tres columnas; marcos de una etapa y has_dependents de la etapa (RN-ACAD-96).
        $owner->statement('CREATE INDEX timetable_frames_tenant_year_level_idx ON timetable_frames (tenant_id, academic_year_id, education_level_id)');

        // RN-ACAD-110: como mucho un marco ordinario vivo por etapa.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX timetable_frames_tenant_level_ordinary_unique
                ON timetable_frames (tenant_id, education_level_id)
                WHERE frame_type = 'ordinaria' AND deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE timetable_frames ADD CONSTRAINT timetable_frames_frame_type_check
                CHECK (frame_type IN ('ordinaria', 'especial'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE timetable_frames ADD CONSTRAINT timetable_frames_dates_check
                CHECK (
                    (frame_type = 'ordinaria' AND valid_from IS NULL AND valid_to IS NULL)
                    OR
                    (frame_type = 'especial' AND valid_from IS NOT NULL AND valid_to IS NOT NULL AND valid_to >= valid_from)
                )
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS timetable_frames');
    }
};
