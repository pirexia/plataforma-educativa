<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §G.6.2 (REQ-AUTH-004, 1.4c). Dos `CHECK`, ni un índice: los
 * cuatro únicos parciales de `§F.4.2` (1.4b) ya cubren SAML sin cambio
 * alguno, porque están tecleados por `identity_provider_id`, no por
 * protocolo — la comprobación de que el re-tecleado de 1.4b se hizo bien.
 *
 * `$withinTransaction = false` y patrón `NOT VALID` + `VALIDATE CONSTRAINT`,
 * mismo criterio que `2026_09_01_100500_add_identity_provider_to_user_identities.php`:
 * `user_identities` es tabla viva desde 1.4.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        // Ampliación aditiva: la versión anterior sigue escribiendo
        // 'google' y 'oidc' sin problema (CA-AUTH-314-símil, datos.md §G.7.2).
        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT IF EXISTS user_identities_provider_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_provider_check
                CHECK (provider IN ('google', 'oidc', 'saml')) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE user_identities VALIDATE CONSTRAINT user_identities_provider_check');

        // Un vínculo SAML nunca existe sin su fila de catálogo. Se
        // escribe como CHECK por valor, igual que
        // user_identities_oidc_requires_provider_check, sin tocar ninguno
        // de los CHECK existentes. DROP CONSTRAINT IF EXISTS delante del
        // ADD, mismo criterio que el resto del paso (hallazgo de
        // db-reviewer, checkpoint 1): sin él, un reintento tras un fallo
        // parcial de VALIDATE CONSTRAINT se atasca en "constraint already
        // exists".
        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT IF EXISTS user_identities_saml_requires_provider_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_saml_requires_provider_check
                CHECK (provider <> 'saml' OR identity_provider_id IS NOT NULL) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE user_identities VALIDATE CONSTRAINT user_identities_saml_requires_provider_check');
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT IF EXISTS user_identities_saml_requires_provider_check');

        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT IF EXISTS user_identities_provider_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_provider_check
                CHECK (provider IN ('google', 'oidc')) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE user_identities VALIDATE CONSTRAINT user_identities_provider_check');
    }
};
