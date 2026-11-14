<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/datos.md §1.4 (1.14-a, `REQ-FAM-UNIT-002`). Vínculo tutor ↔ alumno, que puede cruzar unidades (C2).
 * Lo que da acceso a los datos del alumno es el vínculo **activo**, no la pertenencia a la unidad (`funcional.md §3.6`).
 * `legal_guardian_verified_by` y `revoked_by` son FK opcionales a `users`, declaradas a mano (una referencia opcional
 * no usa `tenantForeignId()`, que fuerza `NOT NULL`) con su índice (`AR-16`).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('guardian_student_links', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'guardian_id', 'guardians');
            TenantMigration::tenantForeignId($table, 'student_person_id', 'people');
            $table->text('relationship');
            $table->boolean('is_legal_guardian')->default(false);
            $table->timestampTz('legal_guardian_verified_at')->nullable();
            $table->unsignedBigInteger('legal_guardian_verified_by')->nullable();
            $table->text('access_level')->default('lectura');
            $table->jsonb('restricted_categories')->default(DB::raw("'[]'::jsonb"));
            $table->boolean('is_emergency_contact')->default(false);
            $table->text('status')->default('activo');
            $table->text('revocation_reason')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();

            $table->foreign(['tenant_id', 'legal_guardian_verified_by'], 'guardian_student_links_tenant_verified_by_foreign')->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'revoked_by'], 'guardian_student_links_tenant_revoked_by_foreign')->references(['tenant_id', 'id'])->on('users');
            $table->index(['tenant_id', 'legal_guardian_verified_by'], TenantMigration::indexName('guardian_student_links', 'legal_guardian_verified_by'));
            $table->index(['tenant_id', 'revoked_by'], TenantMigration::indexName('guardian_student_links', 'revoked_by'));
        });

        $owner = DB::connection('pgsql_owner');

        // RN-FAM-UNIT-30: como mucho un vínculo vivo por par (tutor, alumno).
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX guardian_student_links_tenant_guardian_student_unique
                ON guardian_student_links (tenant_id, guardian_id, student_person_id)
                WHERE deleted_at IS NULL
            SQL);

        // RN-FAM-UNIT-31: como mucho un contacto de emergencia activo por alumno, garantizado por índice (dos
        // peticiones simultáneas romperían una comprobación).
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX guardian_student_links_tenant_student_emergency_unique
                ON guardian_student_links (tenant_id, student_person_id)
                WHERE is_emergency_contact AND status = 'activo' AND deleted_at IS NULL
            SQL);

        // El resolutor `unidad_familiar` y `FamilyMembership`: alumnos alcanzados por el tutor del sujeto. Se ejecuta
        // en cada petición de un tutor; es la consulta más caliente del módulo (`datos.md §3`).
        $owner->statement(<<<'SQL'
            CREATE INDEX guardian_student_links_tenant_guardian_active_idx
                ON guardian_student_links (tenant_id, guardian_id)
                WHERE status = 'activo' AND deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE guardian_student_links
                ADD CONSTRAINT guardian_student_links_relationship_check
                    CHECK (relationship IN ('padre', 'madre', 'tutor_legal', 'abuelo', 'apoderado', 'otro')),
                ADD CONSTRAINT guardian_student_links_access_level_check
                    CHECK (access_level IN ('lectura', 'lectura_escritura')),
                ADD CONSTRAINT guardian_student_links_status_check
                    CHECK (status IN ('activo', 'revocado')),
                ADD CONSTRAINT guardian_student_links_revocation_reason_check
                    CHECK (revocation_reason IN ('resolucion_judicial')),
                ADD CONSTRAINT guardian_student_links_restricted_categories_check
                    CHECK (jsonb_typeof(restricted_categories) = 'array' AND restricted_categories <@ '["salud"]'::jsonb),
                ADD CONSTRAINT guardian_student_links_state_coherence_check
                    CHECK ((status = 'activo' AND revocation_reason IS NULL AND revoked_at IS NULL)
                        OR (status = 'revocado' AND revocation_reason IS NOT NULL AND revoked_at IS NOT NULL)),
                ADD CONSTRAINT guardian_student_links_revoked_not_emergency_check
                    CHECK (NOT (is_emergency_contact AND status = 'revocado')),
                ADD CONSTRAINT guardian_student_links_verified_needs_legal_check
                    CHECK (legal_guardian_verified_at IS NULL OR is_legal_guardian)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS guardian_student_links');
    }
};
