<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/datos.md §1.8 (1.14-b, `ADR-066 §5.5`, adelantada desde 1.14-c). Versiones de los textos legales del
 * centro con `kind` **desde su creación** (`condiciones_uso`, `consentimiento_imagen_web`, `consentimiento_imagen_redes`):
 * un único mecanismo de versiones para los tres. El servicio de 1.14-b rechaza `condiciones_uso` (`RN-FAM-UNIT-73`); el
 * `CHECK` ya lo admite para no migrar en 1.14-c. Título y cuerpo son contenido del centro traducible (`ADR-062`):
 * `translatableColumn()` crea las columnas `*_translations jsonb NOT NULL` con su `CHECK` (`AR-16`).
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin `CONCURRENTLY` son inocuos. El borrado es lógico
 * y solo de un borrador (servicio, `409 not_draft`); `UNIQUE (tenant_id, kind, version_number)` no es parcial a
 * propósito: un número de versión no se reutiliza nunca, ni tras borrar un borrador.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('terms_versions', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->text('kind');
            $table->integer('version_number');
            TenantMigration::translatableColumn($table, 'title');
            TenantMigration::translatableColumn($table, 'body');
            $table->text('status')->default('borrador');
            $table->boolean('requires_reacceptance')->default(false);
            $table->timestampTz('published_at')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        // Numeración correlativa por centro y tipo, sin reutilizar números (`datos.md §1.8`).
        $owner->statement('ALTER TABLE terms_versions ADD CONSTRAINT terms_versions_tenant_kind_number_unique UNIQUE (tenant_id, kind, version_number)');

        // `RN-FAM-UNIT-60`, `-73`: como mucho una versión publicada por centro y tipo, por índice y no por comprobación
        // (dos publicaciones simultáneas romperían una comprobación).
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX terms_versions_tenant_kind_published_unique
                ON terms_versions (tenant_id, kind)
                WHERE status = 'publicada' AND deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE terms_versions
                ADD CONSTRAINT terms_versions_kind_check
                    CHECK (kind IN ('condiciones_uso', 'consentimiento_imagen_web', 'consentimiento_imagen_redes')),
                ADD CONSTRAINT terms_versions_status_check
                    CHECK (status IN ('borrador', 'publicada', 'retirada')),
                ADD CONSTRAINT terms_versions_version_number_check
                    CHECK (version_number >= 1),
                ADD CONSTRAINT terms_versions_published_at_check
                    CHECK ((status = 'borrador') = (published_at IS NULL)),
                ADD CONSTRAINT terms_versions_reacceptance_kind_check
                    CHECK (kind = 'condiciones_uso' OR NOT requires_reacceptance)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS terms_versions');
    }
};
