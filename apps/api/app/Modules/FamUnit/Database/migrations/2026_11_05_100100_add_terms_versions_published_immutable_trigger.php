<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/datos.md §1.8 (`REQ-FAM-UNIT-004`, `ADR-066 §5.5`, issue #482). Fase *expand*, aditiva y reversible: la
 * inmutabilidad de una versión de texto legal que ya no es borrador (es evidencia del consentimiento) pasa de estar solo en
 * `TermsVersionService::assertDraft` a garantizarla el motor.
 *
 * Disparador `terms_versions_published_text_immutable`, `BEFORE UPDATE FOR EACH ROW WHEN (OLD.status <> 'borrador')`, que
 * rechaza (SQLSTATE `23001`, `restrict_violation`) cualquier cambio de `title_translations` o `body_translations` —las columnas
 * reales de `title` y `body` (`ADR-062`)—. Rige para todos los roles, incluido el propietario (un disparador de fila no se
 * omite sin `session_replication_role`, que exige superusuario). **No** restringe el resto de columnas: publicar
 * (`borrador → publicada`), retirar (`publicada → retirada`) y el borrado lógico siguen funcionando. Función en el esquema `app`
 * con `search_path` fijado (como `ADR-057`). No toca ninguna fila.
 *
 * Dos cierres más (revisión de seguridad, M2): el `CHECK terms_versions_published_at_check` por sí solo permite volver una
 * versión publicada a `borrador` (`status = 'borrador'`, `published_at = NULL`) y editarla después, así que la misma función
 * rechaza también la **regresión de estado** (`OLD.status <> 'borrador' AND NEW.status = 'borrador'`). Y `plataforma_app` y
 * `plataforma_platform` conservan `DELETE` sobre `terms_versions` (comprobado: el borrado de un borrador es lógico, pero el
 * privilegio existe), así que un segundo disparador `BEFORE DELETE … WHEN (OLD.status <> 'borrador')` rechaza el borrado físico
 * de una versión publicada o retirada, para todos los roles. La purga de `1.23b` (`REQ-PRIV-006`) tendrá que desactivarlo
 * explícitamente (`ALTER TABLE … DISABLE TRIGGER`, solo el propietario) y dejar constancia; no se decide aquí.
 *
 * Tabla en uso: `CREATE TRIGGER` toma `SHARE ROW EXCLUSIVE`, breve; se acota con `lock_timeout`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app.assert_terms_version_text_immutable() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $fn$
            BEGIN
                IF NEW.status = 'borrador' THEN
                    RAISE EXCEPTION 'terms_versions: una versión publicada o retirada no vuelve a borrador (REQ-FAM-UNIT-004)'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.title_translations IS DISTINCT FROM OLD.title_translations
                    OR NEW.body_translations IS DISTINCT FROM OLD.body_translations THEN
                    RAISE EXCEPTION 'terms_versions: el título y el cuerpo de una versión que no es borrador son inmutables (REQ-FAM-UNIT-004)'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END
            $fn$
            SQL);

        $owner->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app.assert_terms_version_not_deleted() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $fn$
            BEGIN
                RAISE EXCEPTION 'terms_versions: una versión que no es borrador es evidencia y no se borra físicamente (REQ-FAM-UNIT-004)'
                    USING ERRCODE = 'restrict_violation';
            END
            $fn$
            SQL);

        $previous = $owner->selectOne("SELECT current_setting('lock_timeout') AS v")->v;
        $owner->statement("SET lock_timeout = '5s'");

        try {
            $owner->statement(<<<'SQL'
                CREATE OR REPLACE TRIGGER terms_versions_published_text_immutable BEFORE UPDATE ON terms_versions
                    FOR EACH ROW WHEN (OLD.status <> 'borrador')
                    EXECUTE FUNCTION app.assert_terms_version_text_immutable()
                SQL);
            $owner->statement(<<<'SQL'
                CREATE OR REPLACE TRIGGER terms_versions_published_not_deleted BEFORE DELETE ON terms_versions
                    FOR EACH ROW WHEN (OLD.status <> 'borrador')
                    EXECUTE FUNCTION app.assert_terms_version_not_deleted()
                SQL);
        } finally {
            $owner->statement("SET lock_timeout = '{$previous}'");
        }
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('DROP TRIGGER IF EXISTS terms_versions_published_text_immutable ON terms_versions');
        $owner->statement('DROP TRIGGER IF EXISTS terms_versions_published_not_deleted ON terms_versions');
        $owner->statement('DROP FUNCTION IF EXISTS app.assert_terms_version_text_immutable()');
        $owner->statement('DROP FUNCTION IF EXISTS app.assert_terms_version_not_deleted()');
    }
};
