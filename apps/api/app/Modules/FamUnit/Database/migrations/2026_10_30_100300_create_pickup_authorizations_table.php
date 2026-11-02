<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/datos.md §8.2, §8.6 (1.14b-a, `REQ-FAM-UNIT-005`, `ADR-068`). Autorización de un tercero (o puntual) para
 * recoger a un alumno: alumno × persona, con relación, tipo (`permanente` o `puntual`), vigencia en **fechas civiles del
 * centro** (`date`, no instantes: `funcional.md §16.3` regla 8), el tutor principal que la da (`RN-FAM-UNIT-85`), la versión
 * del texto `autorizacion_recogida` aceptada, el canal y su estado de retirada. **Tabla ordinaria, no de solo anexar**: la
 * retirada (`RN-FAM-UNIT-87`) cambia su estado; el historial queda en `audit_logs` y en la propia fila, que no se borra
 * físicamente mientras exista una salida que la referencie.
 *
 * - `terms_version_id` es `ON DELETE RESTRICT`: el texto firmado es evidencia, como en `image_consent_decisions`. Que su
 *   `kind` sea `autorizacion_recogida` y su estado `publicada` o `retirada` lo valida el servicio (`RN-FAM-UNIT-85`).
 * - `CHECK (person_id <> student_person_id)`: el alumno no se autoriza a sí mismo. Que la persona no sea una con acceso
 *   revocado (`RN-FAM-UNIT-86`) es del servicio.
 * - `puntual` = una fecha y un motivo (`valid_until = valid_from`, `reason` obligatorio); `telefono` solo con `puntual`
 *   (`OPEN-FAM-UNIT-35`, aprobado por el usuario el 2026-10-30; `portal` llega con `1.22` y amplía el `CHECK` de canal con su
 *   especificación).
 * - Los dos únicos parciales (`RN-FAM-UNIT-88`) hacen cumplir «una viva por (alumno, persona) permanente» y «una por
 *   (alumno, persona, fecha) puntual» **por índice** y no por comprobación (dos altas simultáneas la romperían).
 * - El índice `(tenant_id, student_person_id, valid_from, valid_until) WHERE withdrawn_at IS NULL AND deleted_at IS NULL` sirve
 *   la composición de la lista de un alumno, la consulta caliente de la puerta (`datos.md §8.6`).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin `CONCURRENTLY` son inocuos. Sin `academic_year_id`
 * (`OPEN-FAM-UNIT-39`). Debe ejecutarse después de `terms_versions` y de la ampliación de su `CHECK` de `kind`.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('pickup_authorizations', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            // Índice completo (tenant_id, student_person_id) de la FK (`db-reviewer`): los índices parciales de abajo no los usa el
            // motor para comprobar la clave foránea.
            TenantMigration::tenantForeignId($table, 'student_person_id', 'people');
            TenantMigration::tenantForeignId($table, 'person_id', 'people');
            $table->text('relationship');
            $table->text('kind');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->text('reason')->nullable();
            TenantMigration::tenantForeignId($table, 'authorized_by_guardian_id', 'guardians');
            // El texto firmado es evidencia: `RESTRICT` y no la acción por defecto, para que la intención conste.
            $table->foreignId('terms_version_id');
            $table->foreign(['tenant_id', 'terms_version_id'], 'pickup_authorizations_tenant_terms_version_foreign')
                ->references(['tenant_id', 'id'])->on('terms_versions')->restrictOnDelete();
            $table->index(['tenant_id', 'terms_version_id'], TenantMigration::indexName('pickup_authorizations', 'terms_version_id'));
            $table->text('channel');
            $table->timestampTz('authorized_at');
            TenantMigration::tenantForeignId($table, 'recorded_by', 'users');
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->unsignedBigInteger('withdrawn_by')->nullable();

            $table->foreign(['tenant_id', 'withdrawn_by'], 'pickup_authorizations_tenant_withdrawn_by_foreign')->references(['tenant_id', 'id'])->on('users');
            $table->index(['tenant_id', 'withdrawn_by'], TenantMigration::indexName('pickup_authorizations', 'withdrawn_by'));
        });

        $owner = DB::connection('pgsql_owner');

        // RN-FAM-UNIT-88: una permanente viva por (alumno, persona)...
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX pickup_authorizations_permanent_live_unique
                ON pickup_authorizations (tenant_id, student_person_id, person_id)
                WHERE kind = 'permanente' AND withdrawn_at IS NULL AND deleted_at IS NULL
            SQL);

        // ... y una puntual viva por (alumno, persona, fecha).
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX pickup_authorizations_punctual_live_unique
                ON pickup_authorizations (tenant_id, student_person_id, person_id, valid_from)
                WHERE kind = 'puntual' AND withdrawn_at IS NULL AND deleted_at IS NULL
            SQL);

        // La lista calculada de un alumno (RN-FAM-UNIT-79): lo vigente, sin retiradas ni bajas.
        $owner->statement(<<<'SQL'
            CREATE INDEX pickup_authorizations_student_validity_idx
                ON pickup_authorizations (tenant_id, student_person_id, valid_from, valid_until)
                WHERE withdrawn_at IS NULL AND deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE pickup_authorizations
                ADD CONSTRAINT pickup_authorizations_person_not_student_check
                    CHECK (person_id <> student_person_id),
                ADD CONSTRAINT pickup_authorizations_relationship_check
                    CHECK (relationship IN ('abuelo', 'personal_apoyo', 'otra_familia', 'otro')),
                ADD CONSTRAINT pickup_authorizations_kind_check
                    CHECK (kind IN ('permanente', 'puntual')),
                ADD CONSTRAINT pickup_authorizations_valid_range_check
                    CHECK (valid_until IS NULL OR valid_until >= valid_from),
                ADD CONSTRAINT pickup_authorizations_punctual_single_day_check
                    CHECK (kind <> 'puntual' OR valid_until = valid_from),
                ADD CONSTRAINT pickup_authorizations_punctual_reason_check
                    CHECK (kind <> 'puntual' OR reason IS NOT NULL),
                ADD CONSTRAINT pickup_authorizations_reason_length_check
                    CHECK (reason IS NULL OR length(reason) <= 500),
                ADD CONSTRAINT pickup_authorizations_channel_check
                    CHECK (channel IN ('presencial', 'telefono')),
                ADD CONSTRAINT pickup_authorizations_telephone_punctual_check
                    CHECK (channel <> 'telefono' OR kind = 'puntual'),
                ADD CONSTRAINT pickup_authorizations_withdrawal_coherence_check
                    CHECK ((withdrawn_at IS NULL) = (withdrawn_by IS NULL))
            SQL);
    }

    public function down(): void
    {
        // Evidencia ante un incidente con un menor (`datos.md §8.2`): una autorización no se borra con un rollback. La
        // comprobación va por la conexión de plataforma (BYPASSRLS) para ver las filas de todos los centros.
        if (DB::connection('pgsql_platform')->table('pickup_authorizations')->exists()) {
            throw new RuntimeException('pickup_authorizations tiene filas: son evidencia de quién pudo recoger a un menor y no se revierte. Purga el centro por el procedimiento de retención (REQ-PRIV-006) antes.');
        }

        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS pickup_authorizations');
    }
};
