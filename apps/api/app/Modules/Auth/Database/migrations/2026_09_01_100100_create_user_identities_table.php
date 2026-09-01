<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §E.2 (REQ-AUTH-002, 1.4). Vínculo entre un usuario y una
 * cuenta externa concreta — no un catálogo de proveedores (eso es
 * `identity_providers`, reservado y sin ocupar para 1.4b, §E.1.1).
 *
 * Ninguna columna de token (`RN-AUTH-95`) ni de nombre/fotografía
 * (`RN-AUTH-88`): la fusión y el vínculo solo escriben esta fila.
 *
 * Los dos únicos son parciales sobre `deleted_at IS NULL` a propósito:
 * desvincular y volver a vincular deja dos filas, no una revivida
 * (funcional.md §E.4.5 punto 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('user_identities', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'user_id', 'users');
            $table->text('provider');
            // RN-AUTH-86: la identidad estable, nunca el correo.
            $table->text('subject');
            // Informativo (§E.2): no se usa para resolver nada.
            $table->text('email_at_link')->nullable();
            $table->boolean('email_verified_at_link');
            $table->text('link_method');
            $table->timestampTz('linked_at');
            $table->timestampTz('last_login_at')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        // RN-AUTH-89: una cuenta externa vinculada como mucho a un
        // usuario del tenant.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX user_identities_tenant_provider_subject_unique
                ON user_identities (tenant_id, provider, subject)
                WHERE deleted_at IS NULL
            SQL);

        // RN-AUTH-89: un usuario, como mucho, un vínculo vivo por proveedor.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX user_identities_tenant_user_provider_unique
                ON user_identities (tenant_id, user_id, provider)
                WHERE deleted_at IS NULL
            SQL);

        // El listado del perfil (GET /auth/identities).
        $owner->statement(<<<'SQL'
            CREATE INDEX user_identities_tenant_user_idx
                ON user_identities (tenant_id, user_id)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_provider_check
                CHECK (provider IN ('google'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_link_method_check
                CHECK (link_method IN ('fusion_automatica', 'perfil'))
            SQL);
        // RN-AUTH-87, la restricción más importante de la tabla: una
        // fusión automática solo puede existir si el correo venía
        // verificado, garantizado por el motor y no solo por el servicio.
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_fusion_requires_verified_check
                CHECK (link_method <> 'fusion_automatica' OR email_verified_at_link)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS user_identities');
    }
};
