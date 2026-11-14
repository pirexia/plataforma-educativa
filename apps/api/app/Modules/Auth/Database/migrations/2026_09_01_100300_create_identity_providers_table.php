<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §F.2 (REQ-AUTH-004, 1.4b). El catálogo de proveedores OIDC de
 * un centro. Tabla de tenant ordinaria, `Full` (sin datos personales:
 * URLs, un `client_id` y una lista de dominios — ADR-035 §8).
 *
 * Ninguna columna de `protocol` (SAML es 1.4c, ADR-034 OPEN-13), ninguna
 * de `jwks_uri` (no se verifica la firma del `id_token`, funcional.md
 * §F.3.2) y ninguna de mapeo de atributos hacia `people` (funcional.md
 * §F.5.2). Ninguna columna de credencial: vive en `identity_provider_secrets`
 * (`§F.3`, decisión del usuario sobre `ADR-043 §8.2`).
 *
 * Sin `CHECK (issuer LIKE 'https://%')` ni equivalentes: la exigencia de
 * `https` es real pero no puede vivir en el esquema porque el emisor
 * simulado de desarrollo sirve sobre `http` (operacion.md §F.10). Vive en
 * la validación de servidor, cubierta por `CA-AUTH-264`.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('identity_providers', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->text('display_name');
            $table->text('discovery_url');
            $table->text('issuer');
            $table->text('authorization_endpoint');
            $table->text('token_endpoint');
            $table->text('userinfo_endpoint')->nullable();
            $table->text('claims_source')->default('id_token');
            $table->text('email_claim')->default('email');
            $table->jsonb('scopes')->default(DB::raw("'[\"openid\",\"email\",\"profile\"]'::jsonb"));
            $table->text('client_id');
            $table->jsonb('allowed_email_domains')->default(DB::raw("'[]'::jsonb"));
            $table->text('provisioning_mode')->default('desactivado');
            $table->boolean('is_enabled')->default(false);
            $table->timestampTz('discovery_fetched_at');
            $table->timestampTz('discovery_failed_at')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        // Un centro no cataloga dos veces el mismo emisor (funcional.md §F.4.1).
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX identity_providers_tenant_issuer_unique
                ON identity_providers (tenant_id, issuer)
                WHERE deleted_at IS NULL
            SQL);

        // La consulta caliente: los proveedores activos del tenant.
        $owner->statement(<<<'SQL'
            CREATE INDEX identity_providers_tenant_enabled_idx
                ON identity_providers (tenant_id, is_enabled)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE identity_providers ADD CONSTRAINT identity_providers_claims_source_check
                CHECK (claims_source IN ('id_token', 'userinfo'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE identity_providers ADD CONSTRAINT identity_providers_claims_source_userinfo_check
                CHECK (claims_source <> 'userinfo' OR userinfo_endpoint IS NOT NULL)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE identity_providers ADD CONSTRAINT identity_providers_email_claim_check
                CHECK (email_claim IN ('email', 'preferred_username', 'upn'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE identity_providers ADD CONSTRAINT identity_providers_provisioning_mode_check
                CHECK (provisioning_mode IN ('desactivado', 'emparejamiento'))
            SQL);
        // `openid` es lo que hace que el flujo sea OIDC y no OAuth2 a
        // secas: sin él no hay `id_token`, y sin `id_token` no hay `sub`.
        $owner->statement(<<<'SQL'
            ALTER TABLE identity_providers ADD CONSTRAINT identity_providers_scopes_check
                CHECK (jsonb_typeof(scopes) = 'array' AND scopes @> '["openid"]'::jsonb)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE identity_providers ADD CONSTRAINT identity_providers_allowed_domains_check
                CHECK (jsonb_typeof(allowed_email_domains) = 'array')
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS identity_providers');
    }
};
