<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §9.1 (1.12-a, `REQ-ACAD-002`), `ADR-065`. Franja de un
 * marco: día ISO de la semana (1 = lunes … 7 = domingo) y hora de inicio y fin,
 * **hora civil del centro en `time` sin zona** (excepción acotada a `ADR-029`;
 * `AR-04` la admite sin cambios). Intervalo semiabierto `[inicio, fin)`: `fin >
 * inicio` por `CHECK`. FK de tres columnas a `timetable_frames` a mano, sin
 * acción referencial (`AR-13`).
 *
 * El solape entre franjas del mismo marco y día (`RN-ACAD-112`) lo valida el
 * servicio bajo `FOR UPDATE` de la fila del marco (`datos.md §9.5`).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin
 * `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('time_slots', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            $table->foreignId('timetable_frame_id');
            $table->smallInteger('weekday');
            $table->time('starts_at', 0);
            $table->time('ends_at', 0);

            $table->foreign(['tenant_id', 'academic_year_id', 'timetable_frame_id'], 'time_slots_tenant_year_frame_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('timetable_frames');

            // Destino de la FK de `timetable_sessions`.
            $table->unique(['tenant_id', 'academic_year_id', 'id'], 'time_slots_tenant_year_id_unique');
        });

        $owner = DB::connection('pgsql_owner');

        // Cubre la FK de tres columnas; franjas de un marco.
        $owner->statement('CREATE INDEX time_slots_tenant_year_frame_idx ON time_slots (tenant_id, academic_year_id, timetable_frame_id)');

        $owner->statement(<<<'SQL'
            ALTER TABLE time_slots ADD CONSTRAINT time_slots_weekday_check CHECK (weekday BETWEEN 1 AND 7)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE time_slots ADD CONSTRAINT time_slots_times_check CHECK (ends_at > starts_at)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS time_slots');
    }
};
