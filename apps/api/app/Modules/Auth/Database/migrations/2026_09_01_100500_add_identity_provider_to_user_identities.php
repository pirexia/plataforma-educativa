<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * datos.md §F.4 (REQ-AUTH-004, 1.4b). Re-teclea `user_identities` por
 * proveedor concreto en vez de por protocolo (`ADR-043 §3.6`): los dos
 * únicos de 1.4 suponen que `provider` identifica al emisor, y con un
 * catálogo por tenant eso es falso — un centro puede tener más de un IdP
 * a la vez, y `subject` solo es único dentro de su emisor. Con la clave
 * de 1.4, un segundo emisor que emitiera el mismo `subject` para otra
 * persona quedaría vinculado al usuario del primero: apropiación de
 * cuenta por colisión de configuración.
 *
 * Expand/contract (`CLAUDE.md §9`): columna nueva *nullable* (las filas
 * `provider = 'google'` de 1.4 no tienen catálogo detrás), cuatro índices
 * nuevos creados ANTES de retirar los dos antiguos — en ningún instante
 * hay una ventana sin garantía de unicidad, porque los índices 3 y 4
 * dicen exactamente lo mismo que los antiguos para todas las filas que
 * existen hoy (ninguna tiene `identity_provider_id` informado: la columna
 * se acaba de crear). Los antiguos se retiran dos versiones después, no
 * en esta entrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_owner')->table('user_identities', function (Blueprint $table): void {
            // Nullable a propósito, y declarada a mano (no con
            // tenantForeignId(), que fuerza NOT NULL): esta referencia no
            // es obligatoria (datos.md §F.4.2, ADR-034 §4).
            $table->foreignId('identity_provider_id')->nullable()->after('user_id');
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(
            'ALTER TABLE user_identities ADD CONSTRAINT user_identities_identity_provider_id_foreign '.
            'FOREIGN KEY (tenant_id, identity_provider_id) REFERENCES identity_providers (tenant_id, id)'
        );

        // 1. Una identidad de un emisor catalogado está vinculada como
        // mucho a un usuario del tenant (mitad institucional de RN-AUTH-89).
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX user_identities_tenant_provider_id_subject_unique
                ON user_identities (tenant_id, identity_provider_id, subject)
                WHERE deleted_at IS NULL AND identity_provider_id IS NOT NULL
            SQL);

        // 2. Un usuario tiene como mucho un vínculo vivo por proveedor
        // catalogado (la otra mitad).
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX user_identities_tenant_user_provider_id_unique
                ON user_identities (tenant_id, user_id, identity_provider_id)
                WHERE deleted_at IS NULL AND identity_provider_id IS NOT NULL
            SQL);

        // 3. La garantía de 1.4, estrechada al mundo sin catálogo (el
        // driver global).
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX user_identities_tenant_provider_subject_null_unique
                ON user_identities (tenant_id, provider, subject)
                WHERE deleted_at IS NULL AND identity_provider_id IS NULL
            SQL);

        // 4. Ídem.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX user_identities_tenant_user_provider_null_unique
                ON user_identities (tenant_id, user_id, provider)
                WHERE deleted_at IS NULL AND identity_provider_id IS NULL
            SQL);

        // 5. El listado de administración por proveedor y la comprobación
        // previa a desactivar uno.
        $owner->statement(<<<'SQL'
            CREATE INDEX user_identities_tenant_identity_provider_idx
                ON user_identities (tenant_id, identity_provider_id)
                WHERE deleted_at IS NULL
            SQL);

        // Se retiran los dos únicos de 1.4, sustituidos por 3 y 4 —dicen
        // exactamente lo mismo para todas las filas que existen hoy—, ya
        // con las cuatro garantías nuevas en su sitio.
        $owner->statement('DROP INDEX user_identities_tenant_provider_subject_unique');
        $owner->statement('DROP INDEX user_identities_tenant_user_provider_unique');

        // CHECKs nuevos y ampliados.
        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT user_identities_provider_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_provider_check
                CHECK (provider IN ('google', 'oidc'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_google_no_provider_check
                CHECK (provider <> 'google' OR identity_provider_id IS NULL)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_oidc_requires_provider_check
                CHECK (provider <> 'oidc' OR identity_provider_id IS NOT NULL)
            SQL);

        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT user_identities_link_method_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_link_method_check
                CHECK (link_method IN ('fusion_automatica', 'perfil', 'emparejamiento_sso'))
            SQL);
        // La restricción más importante que añade este paso (ADR-043
        // §3.6): un vínculo institucional nunca existe sin su fila de
        // catálogo detrás. `user_identities_fusion_requires_verified_check`
        // (1.4) no se toca ni se debilita.
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_emparejamiento_requires_provider_check
                CHECK (link_method <> 'emparejamiento_sso' OR identity_provider_id IS NOT NULL)
            SQL);
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        // datos.md §F.7: recrear los dos índices antiguos ANTES de
        // retirar los cuatro nuevos y la columna. Falla si existe alguna
        // fila institucional — señal correcta: revertir con vínculos
        // institucionales vivos no es seguro.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX user_identities_tenant_provider_subject_unique
                ON user_identities (tenant_id, provider, subject)
                WHERE deleted_at IS NULL
            SQL);
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX user_identities_tenant_user_provider_unique
                ON user_identities (tenant_id, user_id, provider)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT user_identities_emparejamiento_requires_provider_check');
        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT user_identities_link_method_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_link_method_check
                CHECK (link_method IN ('fusion_automatica', 'perfil'))
            SQL);

        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT user_identities_oidc_requires_provider_check');
        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT user_identities_google_no_provider_check');
        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT user_identities_provider_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_provider_check
                CHECK (provider IN ('google'))
            SQL);

        $owner->statement('DROP INDEX user_identities_tenant_identity_provider_idx');
        $owner->statement('DROP INDEX user_identities_tenant_user_provider_null_unique');
        $owner->statement('DROP INDEX user_identities_tenant_provider_subject_null_unique');
        $owner->statement('DROP INDEX user_identities_tenant_user_provider_id_unique');
        $owner->statement('DROP INDEX user_identities_tenant_provider_id_subject_unique');

        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT user_identities_identity_provider_id_foreign');

        Schema::connection('pgsql_owner')->table('user_identities', function (Blueprint $table): void {
            $table->dropColumn('identity_provider_id');
        });
    }
};
