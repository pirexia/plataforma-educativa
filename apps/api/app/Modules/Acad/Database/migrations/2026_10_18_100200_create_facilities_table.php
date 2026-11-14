<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §9.1 (1.12-a, `REQ-ACAD-002`). Espacio: aula (ocupable por
 * sesiones y reservable) o recurso (solo reservable). **Del centro, sin curso
 * académico** (`OPEN-ACAD-33` = A): sin `academic_year_id` ni disparador
 * `academic_year_write_guard`; `AR-13` no la mira. `name` es texto simple, no
 * traducible (`RN-ACAD-101`). `UNIQUE (tenant_id, id, facility_type)` es el
 * destino de la FK con discriminador de las sesiones (el motor impide un
 * `recurso` en una sesión, `RN-ACAD-121`).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin
 * `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('facilities', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->text('facility_type');
            $table->text('code');
            $table->text('name');

            $table->unique(['tenant_id', 'id', 'facility_type'], 'facilities_tenant_id_type_unique');
        });

        $owner = DB::connection('pgsql_owner');

        // RN-ACAD-101: código único entre los espacios vivos del centro.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX facilities_tenant_code_unique
                ON facilities (tenant_id, code)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE facilities ADD CONSTRAINT facilities_facility_type_check
                CHECK (facility_type IN ('aula', 'recurso'))
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS facilities');
    }
};
