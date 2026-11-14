<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/datos.md §1.9 (1.14-c, `REQ-FAM-UNIT-003`, `ADR-067`). Aceptaciones de las condiciones de uso: histórico
 * inmutable de **solo anexar** (`tenantTableAppendOnly()`: sin `deleted_at`, sin `updated_at`, `REVOKE UPDATE, DELETE`),
 * mismo patrón que `image_consent_decisions`. Migración *expand*: tabla nueva y vacía, sin cambios en las existentes
 * (`CLAUDE.md §9`); la versión anterior de la aplicación no la usa.
 *
 * `UNIQUE (tenant_id, user_id, terms_version_id)` hace idempotente aceptar dos veces la misma versión
 * (`RN-FAM-UNIT-64`, por índice y no por comprobación: dos peticiones simultáneas la romperían). La FK a `terms_versions`
 * es `NO ACTION`: una versión aceptada es evidencia y no se puede borrar ni físicamente (`datos.md §6`).
 *
 * El índice `(tenant_id, user_id, accepted_at DESC)` sirve la comprobación del muro en cada petición de un tutor obligado
 * (`RN-FAM-UNIT-62`, `ADR-067 §5.7`) y el listado por usuario.
 *
 * Tabla nueva y vacía: `ADD CONSTRAINT` sin `NOT VALID` e índices sin `CONCURRENTLY` son inocuos. Debe ejecutarse
 * después de `terms_versions` (FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTableAppendOnly('terms_acceptances', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            // Sin índice propio: el único `(tenant_id, user_id, terms_version_id)` y el del muro ya empiezan por (tenant_id, user_id).
            TenantMigration::tenantForeignId($table, 'user_id', 'users', withIndex: false);
            TenantMigration::tenantForeignId($table, 'terms_version_id', 'terms_versions');
            $table->text('shown_locale');
            $table->timestampTz('accepted_at');
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE terms_acceptances ADD CONSTRAINT terms_acceptances_tenant_user_version_unique UNIQUE (tenant_id, user_id, terms_version_id)');

        $owner->statement(<<<'SQL'
            CREATE INDEX terms_acceptances_user_accepted_idx
                ON terms_acceptances (tenant_id, user_id, accepted_at DESC)
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE terms_acceptances
                ADD CONSTRAINT terms_acceptances_shown_locale_check
                    CHECK (shown_locale IN ('es-ES', 'en', 'de', 'fr'))
            SQL);
    }

    public function down(): void
    {
        // Evidencia legal de la aceptación (datos.md §6): no se borra con un rollback. La comprobación va por la conexión de
        // plataforma (BYPASSRLS) para ver las filas de todos los centros.
        if (DB::connection('pgsql_platform')->table('terms_acceptances')->exists()) {
            throw new RuntimeException('terms_acceptances tiene filas: son evidencia de la aceptación y no se revierte. Purga el centro por el procedimiento de retención (REQ-PRIV-006) antes.');
        }

        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS terms_acceptances');
    }
};
