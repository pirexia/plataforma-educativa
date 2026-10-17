<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §9.1 (1.12-a, `REQ-ACAD-002`). Sesión: una asignación
 * docente en una franja, con aula opcional; es la celda de la rejilla. Tres FK
 * de tres columnas a mano, sin acción referencial (`AR-13`):
 *
 * - `(tenant_id, academic_year_id, time_slot_id)` → `time_slots` y
 *   `(tenant_id, academic_year_id, teaching_assignment_id)` →
 *   `teaching_assignments`: el motor garantiza que franja y asignación son del
 *   mismo curso académico.
 * - `(tenant_id, facility_id, facility_type)` → `facilities`, `MATCH SIMPLE`, con
 *   el `CHECK` de abajo: el motor impide un `recurso` en una sesión
 *   (`RN-ACAD-121`). `facility_type` es copia fijada por el servicio.
 *
 * Lo que el motor NO garantiza —etapa del grupo = etapa del marco
 * (`RN-ACAD-120`) y conflictos de profesor, aula y grupo (`RN-ACAD-123`)— lo
 * valida el servicio bajo `FOR UPDATE` del ancla `timetables` (`RN-ACAD-125`).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin
 * `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('timetable_sessions', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            $table->foreignId('time_slot_id');
            $table->foreignId('teaching_assignment_id');
            $table->unsignedBigInteger('facility_id')->nullable();
            $table->text('facility_type')->nullable();

            $table->foreign(['tenant_id', 'academic_year_id', 'time_slot_id'], 'timetable_sessions_tenant_year_slot_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('time_slots');
            $table->foreign(['tenant_id', 'academic_year_id', 'teaching_assignment_id'], 'timetable_sessions_tenant_year_assignment_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('teaching_assignments');
            $table->foreign(['tenant_id', 'facility_id', 'facility_type'], 'timetable_sessions_tenant_facility_type_foreign')
                ->references(['tenant_id', 'id', 'facility_type'])
                ->on('facilities');
        });

        $owner = DB::connection('pgsql_owner');

        // Índices no parciales que cubren las tres FK; conflictos y has_dependents de franja,
        // asignación y espacio.
        $owner->statement('CREATE INDEX timetable_sessions_tenant_year_slot_idx ON timetable_sessions (tenant_id, academic_year_id, time_slot_id)');
        $owner->statement('CREATE INDEX timetable_sessions_tenant_year_assignment_idx ON timetable_sessions (tenant_id, academic_year_id, teaching_assignment_id)');
        $owner->statement('CREATE INDEX timetable_sessions_tenant_facility_type_idx ON timetable_sessions (tenant_id, facility_id, facility_type)');

        $owner->statement(<<<'SQL'
            ALTER TABLE timetable_sessions ADD CONSTRAINT timetable_sessions_facility_check
                CHECK (
                    (facility_id IS NULL AND facility_type IS NULL)
                    OR
                    (facility_id IS NOT NULL AND facility_type = 'aula')
                )
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS timetable_sessions');
    }
};
