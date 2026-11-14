<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §G.3 (REQ-AUTH-004, 1.4c). La hija 1:1 de un proveedor SAML.
 * Sin `public_id`: no se expone nunca en una URL propia, se administra
 * siempre a través del `public_id` del padre (`api.md §G.2`).
 *
 * Tres columnas que el boceto de `ADR-043 §10.4` punto 3 esbozaba y que
 * deliberadamente NO se crean (`funcional.md §G.0.3`): `sso_binding`
 * (solo se implementa HTTP-Redirect de salida), `idp_entity_id`/
 * `sso_service_url` (viven en `issuer`/`authorization_endpoint` del
 * padre, `OPEN-AUTH-42` salida A) y nombres de atributo de nombre/
 * apellidos (`RN-AUTH-109`: ningún camino de código los leería).
 *
 * Sin `CHECK (metadata_url LIKE 'https://%')`: el IdP simulado de
 * desarrollo sirve sobre `http` en local/testing (mismo criterio que
 * `identity_providers` en 1.4b). Vive en la validación de servidor.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('saml_identity_provider_settings', function (Blueprint $table): void {
            // Nombre explícito y corto: el generado por defecto (70
            // bytes) supera el límite de 63 de PostgreSQL y el motor lo
            // truncaría en silencio (hallazgo de db-reviewer, punto de
            // control 1).
            TenantMigration::tenantForeignId(
                $table,
                'identity_provider_id',
                'identity_providers',
                'saml_idp_settings_identity_provider_id_foreign'
            );
            $table->text('metadata_source');
            $table->text('metadata_url')->nullable();
            $table->text('metadata_xml')->nullable();
            $table->text('name_id_format');
            $table->text('email_attribute')->nullable();
            $table->boolean('sign_authn_requests')->default(false);
            $table->timestampTz('metadata_fetched_at')->nullable();
            $table->timestampTz('metadata_failed_at')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        // La relación es 1:1, no 1:N. En el motor, no en el servicio.
        // Parcial sobre deleted_at, a diferencia de saml_auth_requests y
        // saml_consumed_assertions: aquí no hay ningún ataque de
        // reutilización que cerrar (SchemaInvariantsTest, ADR-034 §6) —
        // una fila de configuración borrada lógicamente no tiene que
        // seguir bloqueando el hueco, mismo criterio que
        // identity_provider_certificates_tenant_provider_fp_unique.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX saml_identity_provider_settings_tenant_provider_unique
                ON saml_identity_provider_settings (tenant_id, identity_provider_id)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE saml_identity_provider_settings ADD CONSTRAINT saml_identity_provider_settings_metadata_source_check
                CHECK (metadata_source IN ('url', 'xml'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE saml_identity_provider_settings ADD CONSTRAINT saml_identity_provider_settings_metadata_url_check
                CHECK (metadata_source <> 'url' OR metadata_url IS NOT NULL)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE saml_identity_provider_settings ADD CONSTRAINT saml_identity_provider_settings_metadata_xml_check
                CHECK (metadata_source <> 'xml' OR metadata_xml IS NOT NULL)
            SQL);
        // transient NO está en la lista: RN-AUTH-123, un identificador que
        // cambia en cada acceso no puede sostener un vínculo.
        $owner->statement(<<<'SQL'
            ALTER TABLE saml_identity_provider_settings ADD CONSTRAINT saml_identity_provider_settings_name_id_format_check
                CHECK (name_id_format IN ('emailAddress', 'persistent', 'unspecified'))
            SQL);
        // Garantiza que siempre hay de dónde sacar un correo de
        // emparejamiento: sin ella, persistent + sin atributo se
        // catalogaría sin problemas y no emparejaría a nadie nunca.
        $owner->statement(<<<'SQL'
            ALTER TABLE saml_identity_provider_settings ADD CONSTRAINT saml_identity_provider_settings_email_source_check
                CHECK (email_attribute IS NOT NULL OR name_id_format = 'emailAddress')
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS saml_identity_provider_settings');
    }
};
