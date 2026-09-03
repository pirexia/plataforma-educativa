<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * datos.md §G.2, §G.7.1 (REQ-AUTH-004, 1.4c). Discriminador `protocol`
 * sobre `identity_providers` —tabla viva desde 1.4b— más las siete
 * columnas OIDC que pasan a *nullable*, la retirada de tres `DEFAULT` de
 * conveniencia y la reescritura de cuatro `CHECK` existentes más nueve
 * nuevos. Aditivo puro: `ADD COLUMN … DEFAULT 'oidc'` no reescribe la
 * tabla en PostgreSQL 11+, y toda fila existente queda `oidc`
 * (`CA-AUTH-314`).
 *
 * `$withinTransaction = false`: los cuatro `CHECK` reescritos y los nueve
 * nuevos van `NOT VALID` + `VALIDATE CONSTRAINT` fuera de transacción,
 * como exige la *skill* `migracion-segura` y como corrigió
 * `2026_08_31_100100_add_purge_indexes_to_mfa_tables.php` (issues
 * #118/#119) y `2026_09_01_100500_add_identity_provider_to_user_identities.php`.
 * `DROP CONSTRAINT IF EXISTS` delante de cada `ADD`, para que un reintento
 * tras un fallo parcial no se atasque en «constraint already exists»
 * (segundo hallazgo de `db-reviewer` en 1.4b).
 *
 * El orden importa (`datos.md §G.7.1`): 1) columna `protocol`, porque los
 * `CHECK` siguientes la mencionan; 2) `DROP NOT NULL`, metadato puro; 3)
 * `DROP DEFAULT`, metadato puro; 4) los cuatro `CHECK` reescritos; 5) los
 * nueve `CHECK` nuevos.
 *
 * Ningún índice nuevo sobre el padre: `(tenant_id, is_enabled)` sigue
 * siendo la consulta caliente y no se tecla por `protocol` (`§G.2.4`).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var list<string> */
    private const NULLABLE_COLUMNS = [
        'discovery_url',
        'token_endpoint',
        'client_id',
        'scopes',
        'discovery_fetched_at',
        'email_claim',
        'claims_source',
    ];

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        // 1. El discriminador. DEFAULT no volátil ⇒ no reescribe la tabla.
        if (! Schema::connection('pgsql_owner')->hasColumn('identity_providers', 'protocol')) {
            Schema::connection('pgsql_owner')->table('identity_providers', function (Blueprint $table): void {
                $table->text('protocol')->default('oidc')->after('public_id');
            });
        }

        // 2. Las siete columnas OIDC pasan a nullable. Metadato puro.
        foreach (self::NULLABLE_COLUMNS as $column) {
            $owner->statement("ALTER TABLE identity_providers ALTER COLUMN {$column} DROP NOT NULL");
        }

        // 3. Los DEFAULT de conveniencia se retiran (§G.2.3): sin ellos,
        // una fila SAML insertada sin nombrar estas columnas queda a NULL
        // en vez de rellenarse con un valor OIDC. Verificado contra el
        // código desplegado: IdentityProviderService::create() las fija
        // las tres explícitamente en todas sus rutas.
        $owner->statement('ALTER TABLE identity_providers ALTER COLUMN scopes DROP DEFAULT');
        $owner->statement('ALTER TABLE identity_providers ALTER COLUMN claims_source DROP DEFAULT');
        $owner->statement('ALTER TABLE identity_providers ALTER COLUMN email_claim DROP DEFAULT');

        // 4. Los cuatro CHECK existentes, reescritos con "protocol <>
        // 'oidc' OR …": la obligatoriedad no se pierde, cambia de sitio.
        $this->replaceCheck($owner, 'identity_providers_claims_source_check',
            "CHECK (protocol <> 'oidc' OR claims_source IN ('id_token', 'userinfo'))");
        $this->replaceCheck($owner, 'identity_providers_claims_source_userinfo_check',
            "CHECK (protocol <> 'oidc' OR claims_source <> 'userinfo' OR userinfo_endpoint IS NOT NULL)");
        $this->replaceCheck($owner, 'identity_providers_email_claim_check',
            "CHECK (protocol <> 'oidc' OR email_claim IN ('email', 'preferred_username', 'upn'))");
        $this->replaceCheck($owner, 'identity_providers_scopes_check',
            "CHECK (protocol <> 'oidc' OR (jsonb_typeof(scopes) = 'array' AND scopes @> '[\"openid\"]'::jsonb))");

        // 5.1. El discriminador, como CHECK. Aditivo si un día hay un
        // tercer protocolo.
        $this->replaceCheck($owner, 'identity_providers_protocol_check',
            "CHECK (protocol IN ('oidc', 'saml'))");

        // 5.2-5.8. Las siete, una por columna, para que un fallo señale
        // cuál falta (CA-AUTH-312) en vez de un CHECK compuesto opaco.
        foreach (self::NULLABLE_COLUMNS as $column) {
            $this->replaceCheck(
                $owner,
                "identity_providers_{$column}_required_for_oidc_check",
                "CHECK (protocol <> 'oidc' OR {$column} IS NOT NULL)"
            );
        }

        // 5.9. La garantía simétrica (hallazgo de db-reviewer en 1.4b,
        // hermana de user_identities_fusion_no_provider_check): una fila
        // SAML no rellena NINGUNA columna OIDC, ni siquiera las dos que ya
        // eran nullable antes de este paso (userinfo_endpoint,
        // discovery_failed_at). CA-AUTH-311.
        $this->replaceCheck($owner, 'identity_providers_saml_no_oidc_columns_check', <<<'SQL'
            CHECK (
                protocol <> 'saml' OR (
                    discovery_url IS NULL
                    AND token_endpoint IS NULL
                    AND client_id IS NULL
                    AND scopes IS NULL
                    AND email_claim IS NULL
                    AND claims_source IS NULL
                    AND userinfo_endpoint IS NULL
                    AND discovery_fetched_at IS NULL
                    AND discovery_failed_at IS NULL
                )
            )
            SQL);
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        // datos.md §G.7.3: la reversión falla ruidosamente si existe
        // alguna fila protocol = 'saml', porque devolver las siete
        // columnas a NOT NULL es imposible con una fila SAML dentro (las
        // tiene todas a NULL). Es el comportamiento correcto y no se
        // suaviza: no se rellenan de conveniencia para que la reversión
        // "pase".
        $owner->statement('ALTER TABLE identity_providers DROP CONSTRAINT IF EXISTS identity_providers_saml_no_oidc_columns_check');

        foreach (self::NULLABLE_COLUMNS as $column) {
            $owner->statement("ALTER TABLE identity_providers DROP CONSTRAINT IF EXISTS identity_providers_{$column}_required_for_oidc_check");
        }

        $owner->statement('ALTER TABLE identity_providers DROP CONSTRAINT IF EXISTS identity_providers_protocol_check');

        $this->replaceCheck($owner, 'identity_providers_scopes_check',
            "CHECK (jsonb_typeof(scopes) = 'array' AND scopes @> '[\"openid\"]'::jsonb)");
        $this->replaceCheck($owner, 'identity_providers_email_claim_check',
            "CHECK (email_claim IN ('email', 'preferred_username', 'upn'))");
        $this->replaceCheck($owner, 'identity_providers_claims_source_userinfo_check',
            "CHECK (claims_source <> 'userinfo' OR userinfo_endpoint IS NOT NULL)");
        $this->replaceCheck($owner, 'identity_providers_claims_source_check',
            "CHECK (claims_source IN ('id_token', 'userinfo'))");

        $owner->statement("ALTER TABLE identity_providers ALTER COLUMN scopes SET DEFAULT '[\"openid\",\"email\",\"profile\"]'::jsonb");
        $owner->statement("ALTER TABLE identity_providers ALTER COLUMN claims_source SET DEFAULT 'id_token'");
        $owner->statement("ALTER TABLE identity_providers ALTER COLUMN email_claim SET DEFAULT 'email'");

        foreach (self::NULLABLE_COLUMNS as $column) {
            $owner->statement("ALTER TABLE identity_providers ALTER COLUMN {$column} SET NOT NULL");
        }

        if (Schema::connection('pgsql_owner')->hasColumn('identity_providers', 'protocol')) {
            Schema::connection('pgsql_owner')->table('identity_providers', function (Blueprint $table): void {
                $table->dropColumn('protocol');
            });
        }
    }

    private function replaceCheck(Connection $owner, string $name, string $definition): void
    {
        $owner->statement("ALTER TABLE identity_providers DROP CONSTRAINT IF EXISTS {$name}");
        $owner->statement("ALTER TABLE identity_providers ADD CONSTRAINT {$name} {$definition} NOT VALID");
        $owner->statement("ALTER TABLE identity_providers VALIDATE CONSTRAINT {$name}");
    }
};
