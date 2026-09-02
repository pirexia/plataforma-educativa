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
 * No es un cambio destructivo diferible en el sentido del ciclo largo de
 * `CLAUDE.md §9`: es la sustitución de dos índices por cuatro que dicen
 * exactamente lo mismo para todas las filas que existen hoy (ninguna
 * tiene `identity_provider_id` informado: la columna se acaba de crear
 * en esta misma migración), sin ventana sin garantía de unicidad —
 * `datos.md §F.7` desarrolla el argumento completo. Se retiran los dos
 * antiguos en esta misma entrega, no dos versiones después.
 *
 * `user_identities` recibe una fila en cada login/vínculo de Google
 * desde que 1.4 se desplegó: es una tabla viva, no vacía. Hallazgo de
 * `db-reviewer` en la revisión independiente de 1.4b: la primera versión
 * de esta migración creaba los índices y validaba los `CHECK`/la `FK`
 * dentro de la transacción por defecto, bloqueando escrituras (índices)
 * y lecturas (`CHECK`/`FK` sin `NOT VALID`) durante todo el recorrido —
 * exactamente lo que ya corrigió `2026_08_31_100100_add_purge_indexes_to_mfa_tables.php`
 * (issues #118/#119) y lo que pide la *skill* `migracion-segura`. De ahí
 * `$withinTransaction = false` y el patrón `CONCURRENTLY`/`NOT VALID` +
 * `VALIDATE CONSTRAINT`, incondicional — es seguro incluso con la tabla
 * vacía, nunca es incorrecto, así que no hace falta ramificar en función
 * del volumen en el momento del despliegue (esa rama condicional no era
 * implementable: una migración es código estático).
 *
 * Los dos índices condicionados a `identity_provider_id IS NULL` (3 y 4)
 * llevan el sufijo `_null` al final del nombre antiguo, no en medio: así
 * `user_identities_tenant_provider_subject_unique` sigue siendo
 * subcadena literal del nombre nuevo, y `GoogleOAuthCallbackService`
 * (código ya desplegado desde 1.4, sin cambios de este paso más allá del
 * propio nombre del índice que compara) sigue reconociendo la violación
 * durante la ventana de un despliegue continuo con instancias antiguas y
 * nuevas conviviendo — segundo hallazgo de `db-reviewer`, corregido aquí
 * en vez de documentado y diferido, porque el coste de evitarlo del todo
 * es solo elegir bien el nombre.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::connection('pgsql_owner')->hasColumn('user_identities', 'identity_provider_id')) {
            // Nullable a propósito, y declarada a mano (no con
            // tenantForeignId(), que fuerza NOT NULL): esta referencia no
            // es obligatoria (datos.md §F.4.2, ADR-034 §4). ADD COLUMN
            // nullable sin DEFAULT no reescribe la tabla en PostgreSQL.
            Schema::connection('pgsql_owner')->table('user_identities', function (Blueprint $table): void {
                $table->foreignId('identity_provider_id')->nullable()->after('user_id');
            });
        }

        $owner = DB::connection('pgsql_owner');

        // Hallazgo de db-reviewer (segunda pasada, revisión independiente
        // de 1.4b): sin este DROP previo, un reintento tras un fallo
        // parcial de la migración (p.ej. en la VALIDATE CONSTRAINT de la
        // línea siguiente) se atasca en "constraint already exists" —
        // mismo patrón de idempotencia que ya llevan los seis CHECK.
        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT IF EXISTS user_identities_identity_provider_id_foreign');
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_identity_provider_id_foreign
                FOREIGN KEY (tenant_id, identity_provider_id) REFERENCES identity_providers (tenant_id, id)
                NOT VALID
            SQL);
        $owner->statement('ALTER TABLE user_identities VALIDATE CONSTRAINT user_identities_identity_provider_id_foreign');

        // 1. Una identidad de un emisor catalogado está vinculada como
        // mucho a un usuario del tenant (mitad institucional de RN-AUTH-89).
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS user_identities_tenant_provider_id_subject_unique
                ON user_identities (tenant_id, identity_provider_id, subject)
                WHERE deleted_at IS NULL AND identity_provider_id IS NOT NULL
            SQL);

        // 2. Un usuario tiene como mucho un vínculo vivo por proveedor
        // catalogado (la otra mitad).
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS user_identities_tenant_user_provider_id_unique
                ON user_identities (tenant_id, user_id, identity_provider_id)
                WHERE deleted_at IS NULL AND identity_provider_id IS NOT NULL
            SQL);

        // 3. La garantía de 1.4, estrechada al mundo sin catálogo (el
        // driver global). Sufijo al final del nombre a propósito: ver
        // docblock de cabecera.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS user_identities_tenant_provider_subject_unique_null
                ON user_identities (tenant_id, provider, subject)
                WHERE deleted_at IS NULL AND identity_provider_id IS NULL
            SQL);

        // 4. Ídem.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS user_identities_tenant_user_provider_unique_null
                ON user_identities (tenant_id, user_id, provider)
                WHERE deleted_at IS NULL AND identity_provider_id IS NULL
            SQL);

        // 5. El listado de administración por proveedor y la comprobación
        // previa a desactivar uno.
        $owner->statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY IF NOT EXISTS user_identities_tenant_identity_provider_idx
                ON user_identities (tenant_id, identity_provider_id)
                WHERE deleted_at IS NULL
            SQL);

        // Se retiran los dos únicos de 1.4, sustituidos por 3 y 4 —dicen
        // exactamente lo mismo para todas las filas que existen hoy—, ya
        // con las cuatro garantías nuevas en su sitio.
        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS user_identities_tenant_provider_subject_unique');
        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS user_identities_tenant_user_provider_unique');

        // CHECKs nuevos y ampliados. DROP+ADD de un CHECK es metadato
        // puro (no escanea la tabla); solo la validación posterior toca
        // cada fila, de ahí NOT VALID + VALIDATE CONSTRAINT en las cinco.
        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT IF EXISTS user_identities_provider_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_provider_check
                CHECK (provider IN ('google', 'oidc')) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE user_identities VALIDATE CONSTRAINT user_identities_provider_check');

        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_google_no_provider_check
                CHECK (provider <> 'google' OR identity_provider_id IS NULL) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE user_identities VALIDATE CONSTRAINT user_identities_google_no_provider_check');

        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_oidc_requires_provider_check
                CHECK (provider <> 'oidc' OR identity_provider_id IS NOT NULL) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE user_identities VALIDATE CONSTRAINT user_identities_oidc_requires_provider_check');

        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT IF EXISTS user_identities_link_method_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_link_method_check
                CHECK (link_method IN ('fusion_automatica', 'perfil', 'emparejamiento_sso')) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE user_identities VALIDATE CONSTRAINT user_identities_link_method_check');

        // La restricción más importante que añade este paso (ADR-043
        // §3.6): un vínculo institucional nunca existe sin su fila de
        // catálogo detrás. `user_identities_fusion_requires_verified_check`
        // (1.4) no se toca ni se debilita.
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_emparejamiento_requires_provider_check
                CHECK (link_method <> 'emparejamiento_sso' OR identity_provider_id IS NOT NULL) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE user_identities VALIDATE CONSTRAINT user_identities_emparejamiento_requires_provider_check');

        // Hallazgo de db-reviewer, defensa en profundidad: sin este
        // CHECK, `link_method = 'fusion_automatica'` con
        // `identity_provider_id` informado satisface los cinco `CHECK`
        // de arriba y solo lo evita el código de aplicación
        // (`UserIdentityLinkingService`), no el motor — contradice
        // `datos.md §F.8` ("nada de esto vive solo en la aplicación").
        // Simétrico a `user_identities_google_no_provider_check`.
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_fusion_no_provider_check
                CHECK (link_method <> 'fusion_automatica' OR identity_provider_id IS NULL) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE user_identities VALIDATE CONSTRAINT user_identities_fusion_no_provider_check');
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        // datos.md §F.7: recrear los dos índices antiguos ANTES de
        // retirar los cinco nuevos y la columna. Falla si existe alguna
        // fila institucional (provider = 'oidc') — señal correcta:
        // revertir con vínculos institucionales vivos no es seguro.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS user_identities_tenant_provider_subject_unique
                ON user_identities (tenant_id, provider, subject)
                WHERE deleted_at IS NULL
            SQL);
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS user_identities_tenant_user_provider_unique
                ON user_identities (tenant_id, user_id, provider)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT IF EXISTS user_identities_fusion_no_provider_check');
        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT IF EXISTS user_identities_emparejamiento_requires_provider_check');
        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT IF EXISTS user_identities_link_method_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_link_method_check
                CHECK (link_method IN ('fusion_automatica', 'perfil')) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE user_identities VALIDATE CONSTRAINT user_identities_link_method_check');

        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT IF EXISTS user_identities_oidc_requires_provider_check');
        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT IF EXISTS user_identities_google_no_provider_check');
        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT IF EXISTS user_identities_provider_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE user_identities ADD CONSTRAINT user_identities_provider_check
                CHECK (provider IN ('google')) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE user_identities VALIDATE CONSTRAINT user_identities_provider_check');

        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS user_identities_tenant_identity_provider_idx');
        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS user_identities_tenant_user_provider_unique_null');
        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS user_identities_tenant_provider_subject_unique_null');
        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS user_identities_tenant_user_provider_id_unique');
        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS user_identities_tenant_provider_id_subject_unique');

        $owner->statement('ALTER TABLE user_identities DROP CONSTRAINT IF EXISTS user_identities_identity_provider_id_foreign');

        if (Schema::connection('pgsql_owner')->hasColumn('user_identities', 'identity_provider_id')) {
            Schema::connection('pgsql_owner')->table('user_identities', function (Blueprint $table): void {
                $table->dropColumn('identity_provider_id');
            });
        }
    }
};
