<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/datos.md §8.1 (1.14b-a, `REQ-FAM-UNIT-005`, `ADR-068 §5.3`, `RN-FAM-UNIT-83`). Fotografía de recogida de una
 * persona (tutor o tercero): **una viva por persona**, privada, reprocesada sin metadatos antes de guardarse. La fotografía
 * se queda fuera de `people` a propósito (`datos.md §8.9`). Migración *expand*: tabla nueva y vacía, sin cambios en las
 * existentes (`CLAUDE.md §9`); la versión anterior de la aplicación no la usa.
 *
 * `object_key` es la clave en el *bucket* privado (`tenants/<tenant public_id>/fam-unit/pickup-photos/<ULID>.<ext>`): un
 * ULID, nunca el nombre de la persona. Sustituir la foto es baja lógica de la anterior más alta de la nueva; el objeto
 * anterior lo purga el barrido de huérfanos (`datos.md §8.8`). El único parcial `(tenant_id, person_id) WHERE deleted_at IS
 * NULL` hace cumplir «una viva por persona» por índice y no por comprobación (dos subidas simultáneas romperían una
 * comprobación) y cubre además la clave foránea a `people` (`AR-16`).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin `CONCURRENTLY` son inocuos. Sin `academic_year_id`
 * (`OPEN-FAM-UNIT-39`: hechos fechados), así que no aplica `AR-13`.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('pickup_photos', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            // Sin índice propio: el único parcial de abajo ya empieza por (tenant_id, person_id).
            TenantMigration::tenantForeignId($table, 'person_id', 'people', withIndex: false);
            $table->text('object_key');
            $table->text('mime_type');
            $table->integer('byte_size');
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX pickup_photos_tenant_person_live_unique
                ON pickup_photos (tenant_id, person_id)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE pickup_photos
                ADD CONSTRAINT pickup_photos_mime_type_check
                    CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp')),
                ADD CONSTRAINT pickup_photos_byte_size_check
                    CHECK (byte_size > 0)
            SQL);
    }

    public function down(): void
    {
        // La fotografía de una persona que figura en la lista de un menor es parte de la evidencia de la entrega: no se
        // borra con un rollback. La comprobación va por la conexión de plataforma (BYPASSRLS) para ver todos los centros.
        if (DB::connection('pgsql_platform')->table('pickup_photos')->exists()) {
            throw new RuntimeException('pickup_photos tiene filas: las fotografías de recogida no se revierten. Purga el centro por el procedimiento de retención (REQ-PRIV-006) antes.');
        }

        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS pickup_photos');
    }
};
