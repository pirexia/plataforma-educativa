<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §G.5 (REQ-AUTH-004, 1.4c). La ventana de rotación de los
 * certificados de firma del IdP (`ADR-043 §2.4`, `§10.6`). Con
 * `public_id`, a diferencia de las otras tres tablas nuevas: sí se expone
 * en URL (`DELETE .../certificates/{public_id}`).
 *
 * Varias filas activas a la vez, a propósito: durante una rotación el IdP
 * firma con la nueva mientras algunas aserciones en vuelo llevan la
 * vieja. Sin columna `is_active`: "activo" es exactamente
 * `retired_at IS NULL AND deleted_at IS NULL`, y `not_before`/`not_after`
 * deciden la vigencia (mismo criterio que `identity_provider_secrets`,
 * que usa `activated_at`/`retired_at` y no un booleano).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('identity_provider_certificates', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            // Nombre explícito y corto: el generado por defecto (69
            // bytes) supera el límite de 63 de PostgreSQL y el motor lo
            // truncaría en silencio (hallazgo de db-reviewer, punto de
            // control 1).
            TenantMigration::tenantForeignId(
                $table,
                'identity_provider_id',
                'identity_providers',
                'idp_certificates_identity_provider_id_foreign'
            );
            $table->text('certificate');
            $table->text('fingerprint_sha256');
            $table->timestampTz('not_before');
            $table->timestampTz('not_after');
            $table->text('source');
            $table->timestampTz('retired_at')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        // El mismo certificado no se cataloga dos veces en un proveedor.
        // Lo que hace idempotente el refresco de metadatos (CA-AUTH-325).
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX identity_provider_certificates_tenant_provider_fingerprint_unique
                ON identity_provider_certificates (tenant_id, identity_provider_id, fingerprint_sha256)
                WHERE deleted_at IS NULL
            SQL);

        // La consulta caliente: los certificados admisibles de un
        // proveedor, una vez por aserción validada.
        $owner->statement(<<<'SQL'
            CREATE INDEX identity_provider_certificates_tenant_provider_active_idx
                ON identity_provider_certificates (tenant_id, identity_provider_id)
                WHERE deleted_at IS NULL AND retired_at IS NULL
            SQL);

        // La tarea diaria de aviso de vencimiento.
        $owner->statement(<<<'SQL'
            CREATE INDEX identity_provider_certificates_tenant_expiry_idx
                ON identity_provider_certificates (tenant_id, not_after)
                WHERE deleted_at IS NULL AND retired_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE identity_provider_certificates ADD CONSTRAINT identity_provider_certificates_source_check
                CHECK (source IN ('metadata', 'manual'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE identity_provider_certificates ADD CONSTRAINT identity_provider_certificates_not_after_check
                CHECK (not_after > not_before)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE identity_provider_certificates ADD CONSTRAINT identity_provider_certificates_retired_after_created_check
                CHECK (retired_at IS NULL OR retired_at >= created_at)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS identity_provider_certificates');
    }
};
