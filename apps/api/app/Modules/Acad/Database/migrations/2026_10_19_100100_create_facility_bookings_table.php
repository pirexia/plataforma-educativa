<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §9.2 (1.12-b, `REQ-ACAD-002`), `ADR-065`. Reserva: un
 * espacio (aula o recurso) en una fecha y un intervalo horario `[inicio, fin)`,
 * **hora civil del centro en `time` sin zona** (como las franjas). Sin
 * repetición ni aprobación (`OPEN-ACAD-41` = A). Su curso académico es el que
 * contiene la fecha: lleva `academic_year_id` y el disparador de `ADR-057`
 * (`RN-ACAD-90`); que la fecha caiga dentro del curso no lo garantiza el motor
 * (necesita las fechas del curso), lo valida el servicio bajo `FOR SHARE` del
 * curso (`RN-ACAD-140`). El reservante es `created_by` (base del resolutor
 * `propios`, `OPEN-ACAD-42`).
 *
 * FK a `facilities` de dos columnas, a mano y sin acción referencial (`AR-13`):
 * cualquier tipo de espacio (`RN-ACAD-140`), así que sin discriminador.
 *
 * Los conflictos (`RN-ACAD-141`) no los garantiza el motor (sin `btree_gist`,
 * `datos.md §8.3`): los valida el servicio bajo `FOR UPDATE` de la fila
 * `timetables` (`RN-ACAD-125`).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin
 * `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('facility_bookings', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            TenantMigration::tenantForeignId($table, 'facility_id', 'facilities');
            $table->date('booked_on');
            $table->time('starts_at', 0);
            $table->time('ends_at', 0);
        });

        $owner = DB::connection('pgsql_owner');

        // Conflictos de reservas de un espacio en una fecha y `has_dependents` del espacio.
        $owner->statement('CREATE INDEX facility_bookings_tenant_facility_booked_idx ON facility_bookings (tenant_id, facility_id, booked_on)');

        $owner->statement(<<<'SQL'
            ALTER TABLE facility_bookings ADD CONSTRAINT facility_bookings_times_check CHECK (ends_at > starts_at)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS facility_bookings');
    }
};
