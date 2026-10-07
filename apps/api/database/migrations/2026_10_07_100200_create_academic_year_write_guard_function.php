<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-057 §5.2, REQ-CURSO/datos.md §1.4 (paso 1.10, RN-CURSO-20/-23/-32).
 * Primera función PL/pgSQL del proyecto: el bloqueo de escritura de un
 * curso `cerrado`/`archivado` es una invariante de datos impuesta por el
 * motor (igual que «como mucho un curso activo», ADR-034 §4), no una
 * comprobación que cada módulo deba recordar.
 *
 * Esta migración SOLO crea la función. El disparador
 * `academic_year_write_guard` lo crea `TenantMigration::tenantTable()`/
 * `tenantTableAppendOnly()` (y `guardAcademicYearWrites()` en una tabla
 * existente) sobre toda tabla con `academic_year_id`; en 1.10 ninguna
 * tabla real la tiene todavía. `AR-13` vigila el enganche.
 *
 * Decisiones (ADR-057 §5.2):
 * - `SECURITY INVOKER`: sujeta a RLS como quien escribe. Nunca `DEFINER`.
 * - Nombres totalmente cualificados (`public.academic_years`,
 *   `pg_catalog.*`) y `SET search_path = pg_catalog, pg_temp` en la propia
 *   función: ninguna dependencia del `search_path` de quien escribe, ni
 *   siquiera para operadores y tipos (db-reviewer B-1). La migración no está
 *   desplegada en ningún entorno, así que se incorpora sin migración nueva.
 * - Exención del PROPIETARIO REAL de la tabla (`pg_class.relowner` de
 *   `TG_RELID`), no de un nombre de rol escrito en SQL (`DB_OWNER_USERNAME`
 *   es configurable): migraciones de relleno, purga física de un tenant
 *   (issue #371) y archivado. Asimetría deliberada con
 *   `FORCE ROW LEVEL SECURITY`, que obliga también al propietario.
 * - `FOR SHARE` sobre la fila del curso: choca con el `FOR UPDATE` del
 *   cierre (RN-CURSO-32). Exige `UPDATE` sobre `academic_years`, que
 *   `plataforma_app` y `plataforma_platform` tienen.
 * - `SQLSTATE` propio `CY001`. La clase `CY` no figura en el apéndice A
 *   («PostgreSQL Error Codes») de PostgreSQL 17: las clases definidas por
 *   el producto no deben coincidir con ninguna del motor; la clase `CY`
 *   («curso») no la usa ninguna versión publicada. El mensaje lo compone el
 *   producto (`academic_year_closed:<public_id>`) y no depende de
 *   `lc_messages`.
 * - Vocabulario de estados de solo lectura DUPLICADO a sabiendas aquí y en
 *   `AcademicYearStatus::isReadOnly()`; el test de paridad `CA-057-07` los
 *   une. Añadir un estado ya exige migración (CHECK de `academic_years`),
 *   y esta función se actualiza en la misma entrega.
 * - El curso inexistente no lo decide la función: lo rechaza la clave
 *   foránea compuesta (23503).
 *
 * `TRUNCATE` no dispara disparadores de fila: ningún rol de aplicación lo
 * tiene sobre tablas de curso (`CA-057-10`); concederlo sería una regresión.
 *
 * Requisito previo: el rol propietario necesita `CREATE` sobre el esquema
 * `app` (`GRANT CREATE ON SCHEMA app TO plataforma_owner`, paso 0 de
 * `RUNBOOK.md`). `up()` lo comprueba antes y, si falta, falla con el comando
 * exacto en lugar de un `permission denied for schema app` opaco (M-1).
 *
 * Reversión: `down()` elimina la función, solo posible mientras ninguna
 * tabla tenga un disparador que la use (cierto en 1.10, falso desde 1.11).
 * Para apagar el bloqueo en todas las tablas a la vez la vía es una
 * migración nueva que reescriba la función (ADR-057 §6), nunca
 * `ALTER TABLE … DISABLE TRIGGER`.
 */
return new class extends Migration
{
    /**
     * Mensaje de la comprobación previa (también lo usa el test).
     */
    public static function missingCreatePrivilegeMessage(): string
    {
        return 'El rol propietario no tiene CREATE sobre el esquema app, necesario para crear '
            .'app.assert_academic_year_writable() (ADR-057 §5.2). Ejecuta una sola vez, como superusuario, '
            .'sobre esta base de datos: GRANT CREATE ON SCHEMA app TO <rol propietario>; '
            .'(en desarrollo: podman exec -i plataforma-postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" '
            ."-c 'GRANT CREATE ON SCHEMA app TO plataforma_owner'). Ver RUNBOOK.md, paso 0 del despliegue.";
    }

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        if (! $owner->selectOne("SELECT pg_catalog.has_schema_privilege(current_user, 'app', 'CREATE') AS ok")->ok) {
            throw new \RuntimeException(self::missingCreatePrivilegeMessage());
        }

        $owner->unprepared(<<<'SQL'
            CREATE FUNCTION app.assert_academic_year_writable() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $fn$
            DECLARE
                v_status    text;
                v_public_id text;
                v_check_new boolean := false;
            BEGIN
                -- El propietario real de la tabla no está sujeto (ADR-057 §5.2).
                IF current_user = (
                    SELECT pg_catalog.pg_get_userbyid(c.relowner)
                      FROM pg_catalog.pg_class c
                     WHERE c.oid = TG_RELID
                ) THEN
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END IF;

                -- Curso de la fila anterior (UPDATE, DELETE): mover o tocar una
                -- fila que pertenecía a un curso cerrado está prohibido.
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    SELECT y.status, y.public_id
                      INTO v_status, v_public_id
                      FROM public.academic_years y
                     WHERE y.tenant_id = OLD.tenant_id
                       AND y.id = OLD.academic_year_id
                       FOR SHARE;

                    IF v_status IN ('cerrado', 'archivado') THEN
                        RAISE EXCEPTION USING
                            ERRCODE = 'CY001',
                            MESSAGE = 'academic_year_closed:' || v_public_id;
                    END IF;
                END IF;

                -- Curso de la fila nueva (INSERT, UPDATE): insertar en un curso
                -- cerrado o pasar a pertenecer a él. En UPDATE se omite si es el
                -- mismo curso que el ya comprobado. IF anidados y no `OR`: SQL no
                -- garantiza el cortocircuito, y `OLD` no está asignado en INSERT.
                IF TG_OP = 'INSERT' THEN
                    v_check_new := true;
                ELSIF TG_OP = 'UPDATE' THEN
                    v_check_new := NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                                OR NEW.academic_year_id IS DISTINCT FROM OLD.academic_year_id;
                END IF;

                IF v_check_new THEN
                    v_status := NULL;
                    v_public_id := NULL;

                    SELECT y.status, y.public_id
                      INTO v_status, v_public_id
                      FROM public.academic_years y
                     WHERE y.tenant_id = NEW.tenant_id
                       AND y.id = NEW.academic_year_id
                       FOR SHARE;

                    IF v_status IN ('cerrado', 'archivado') THEN
                        RAISE EXCEPTION USING
                            ERRCODE = 'CY001',
                            MESSAGE = 'academic_year_closed:' || v_public_id;
                    END IF;
                END IF;

                -- Curso inexistente: no se decide aquí; lo rechaza la FK compuesta.
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END
            $fn$
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP FUNCTION IF EXISTS app.assert_academic_year_writable()');
    }
};
