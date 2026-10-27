<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/datos.md §1.6 (1.14-b, `REQ-FAM-UNIT-004`, `ADR-066 §5.8`). Histórico inmutable de las decisiones de
 * imagen: `tenantTableAppendOnly()` (sin `deleted_at`, sin `updated_at`, `REVOKE UPDATE, DELETE`), mismo patrón que
 * `academic_year_reopenings`. **No hay tabla de estado vigente** (`image_consents` desaparece, `ADR-066 §5.2`): el
 * estado se calcula en cada consulta desde los vínculos y estas decisiones.
 *
 * `consent_text_version_id` es una referencia opcional (nula solo con `no_autorizo` sin texto publicado, `RN-FAM-UNIT-68`),
 * así que se declara a mano —`tenantForeignId()` fuerza `NOT NULL`— con `ON DELETE RESTRICT` y su índice (`AR-16`): el
 * texto al que apunta una decisión es evidencia y no se puede borrar ni físicamente. Que el `kind` de la versión
 * corresponda al nivel y su estado se validan en servicio (`RN-FAM-UNIT-69`).
 *
 * El índice `(tenant_id, student_person_id, level, guardian_id, occurred_at DESC, id DESC)` sirve el `DISTINCT ON` del
 * cálculo del estado por tutor (`ADR-066 §9.18`) y el histórico por alumno y nivel.
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin `CONCURRENTLY` son inocuos. Debe ejecutarse
 * después de `terms_versions` (FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTableAppendOnly('image_consent_decisions', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'student_person_id', 'people');
            $table->text('level');
            $table->text('decision');
            TenantMigration::tenantForeignId($table, 'guardian_id', 'guardians');
            TenantMigration::tenantForeignId($table, 'recorded_by', 'users');
            $table->text('channel');
            $table->unsignedBigInteger('consent_text_version_id')->nullable();
            $table->text('shown_locale')->nullable();
            $table->timestampTz('occurred_at');
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            $table->foreign(['tenant_id', 'consent_text_version_id'], 'image_consent_decisions_tenant_text_version_foreign')
                ->references(['tenant_id', 'id'])->on('terms_versions')->restrictOnDelete();
            $table->index(['tenant_id', 'consent_text_version_id'], TenantMigration::indexName('image_consent_decisions', 'consent_text_version_id'));
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            CREATE INDEX image_consent_decisions_state_idx
                ON image_consent_decisions (tenant_id, student_person_id, level, guardian_id, occurred_at DESC, id DESC)
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE image_consent_decisions
                ADD CONSTRAINT image_consent_decisions_level_check
                    CHECK (level IN ('web', 'redes_sociales')),
                ADD CONSTRAINT image_consent_decisions_decision_check
                    CHECK (decision IN ('autorizo', 'no_autorizo')),
                ADD CONSTRAINT image_consent_decisions_channel_check
                    CHECK (channel IN ('portal', 'presencial')),
                ADD CONSTRAINT image_consent_decisions_shown_locale_check
                    CHECK (shown_locale IS NULL OR shown_locale IN ('es-ES', 'en', 'de', 'fr')),
                ADD CONSTRAINT image_consent_decisions_authorize_needs_text_check
                    CHECK (decision = 'no_autorizo' OR consent_text_version_id IS NOT NULL)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS image_consent_decisions');
    }
};
