<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ALUM/datos.md §1.4, §3 (1.15-c, `REQ-ALUM-003`, `OPEN-ALUM-08` = A, decisión del usuario 2026-11-11). Fotografía de
 * un alumno: una viva por alumno, privada (`ADR-013`). Misma forma que `pickup_photos` de `fam_unit` pero **sin compartir
 * tabla** (`INV-007`) y con diferencias intencionadas: `content_type` en lugar de `mime_type`, `byte_size bigint`, y solo
 * `image/jpeg` e `image/png` (`RN-ALUM-51`; sin WebP). Migración *expand*: tabla nueva y vacía.
 *
 * `object_key` es la clave en el *bucket* privado, generada por `App\Support\Files`. El único parcial `(tenant_id,
 * student_id) WHERE deleted_at IS NULL` hace cumplir `RN-ALUM-50` por índice; la FK a `students` conserva además su índice
 * completo `(tenant_id, student_id)` (un parcial no sirve para comprobar la clave foránea, `AR-16`). Sin `academic_year_id`
 * (no aplica `AR-13`).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('student_photos', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'student_id', 'students');
            $table->text('object_key');
            $table->text('content_type');
            $table->bigInteger('byte_size');
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX student_photos_tenant_student_live_unique
                ON student_photos (tenant_id, student_id)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE student_photos
                ADD CONSTRAINT student_photos_content_type_check
                    CHECK (content_type IN ('image/jpeg', 'image/png')),
                ADD CONSTRAINT student_photos_byte_size_check
                    CHECK (byte_size > 0)
            SQL);
    }

    public function down(): void
    {
        // La fotografía de un alumno es dato personal de un menor: no se borra con un rollback. La comprobación va por la
        // conexión de plataforma (BYPASSRLS) para ver todos los centros.
        if (DB::connection('pgsql_platform')->table('student_photos')->exists()) {
            throw new RuntimeException('student_photos tiene filas: las fotografías de alumnos no se revierten. Purga el centro por el procedimiento de retención (REQ-PRIV-006) antes.');
        }

        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS student_photos');
    }
};
