<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §G.4.1 (REQ-AUTH-004, 1.4c). La correlación del `AuthnRequest`
 * que emitimos, sin la que la excepción de CSRF del ACS no es defendible
 * (`funcional.md §G.3.2`, `RN-AUTH-120`, `RN-AUTH-124`). A diferencia de
 * la `oauth_authorization_requests` que `OPEN-AUTH-30` rechazó en 1.4 —sin
 * `tenant_id` y con RLS imposible—, esta SÍ lleva `tenant_id` ordinario:
 * el ACS es una URL del host del tenant y `ResolveTenant` corre en
 * primera posición (`ADR-033 §2`). No es la misma tabla ni el mismo
 * problema (`ADR-043 §2.1`).
 *
 * Sin `public_id`: no se expone en ninguna URL ni respuesta de API. El
 * identificador que viaja es `request_id`, dentro del propio mensaje
 * SAML, no en una ruta nuestra.
 *
 * Auditoría: `None` — es estado transitorio de protocolo con vida de
 * cinco minutos, del mismo carácter que el `state` de OIDC en sesión, que
 * tampoco se audita (`funcional.md §G.8`). El modelo no implementa
 * `Auditable`.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('saml_auth_requests', function (Blueprint $table): void {
            TenantMigration::tenantForeignId($table, 'identity_provider_id', 'identity_providers');
            $table->text('request_id');
            $table->text('intent');
            // Nullable y declarada a mano (no tenantForeignId(), que
            // fuerza NOT NULL): la referencia no es obligatoria — mismo
            // criterio que user_identities.identity_provider_id (§F.4.2).
            $table->foreignId('linking_user_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        // No parcial sobre deleted_at: la unicidad tiene que valer también
        // sobre filas ya consumidas, o la repetición se reabre por la
        // puerta del borrado lógico.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX saml_auth_requests_tenant_request_id_unique
                ON saml_auth_requests (tenant_id, request_id)
            SQL);

        // La purga programada (operacion.md §G.4), mismo patrón que
        // 2026_08_31_100100_add_purge_indexes_to_mfa_tables.php. La
        // consulta de purga (PurgeSamlAuthRequests) filtra por dos ramas
        // OR: caducadas sin consumir, o consumidas hace tiempo. Cada rama
        // necesita su propio índice parcial — con uno solo (hallazgo de
        // `db-reviewer`, 1.4c) la rama de filas ya consumidas (que es
        // *todo* login SSO SAML exitoso) se queda sin índice de apoyo
        // hasta que se purgue, mismo defecto que motivó #118/#119.
        $owner->statement(<<<'SQL'
            CREATE INDEX saml_auth_requests_tenant_expires_idx
                ON saml_auth_requests (tenant_id, expires_at)
                WHERE consumed_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            CREATE INDEX saml_auth_requests_tenant_consumed_idx
                ON saml_auth_requests (tenant_id, consumed_at)
                WHERE consumed_at IS NOT NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE saml_auth_requests ADD CONSTRAINT saml_auth_requests_linking_user_id_foreign
                FOREIGN KEY (tenant_id, linking_user_id) REFERENCES users (tenant_id, id)
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE saml_auth_requests ADD CONSTRAINT saml_auth_requests_intent_check
                CHECK (intent IN ('login', 'link'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE saml_auth_requests ADD CONSTRAINT saml_auth_requests_link_requires_user_check
                CHECK (intent <> 'link' OR linking_user_id IS NOT NULL)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE saml_auth_requests ADD CONSTRAINT saml_auth_requests_login_no_user_check
                CHECK (intent <> 'login' OR linking_user_id IS NULL)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE saml_auth_requests ADD CONSTRAINT saml_auth_requests_consumed_after_created_check
                CHECK (consumed_at IS NULL OR consumed_at >= created_at)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS saml_auth_requests');
    }
};
