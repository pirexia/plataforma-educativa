-- Esquema y roles del aislamiento multi-tenant (ADR-033).
--
-- Se ejecuta a través de 01-tenancy.sh, que sustituye las variables psql
-- :'owner_password', :'app_password', :'platform_password' y :"dbname"
-- desde las variables de entorno TENANCY_*_PASSWORD y POSTGRES_DB.
--
-- Nota técnica: psql no interpola variables (`:'var'`) dentro de cadenas
-- delimitadas con `$$`, así que la creación de roles no puede ir en un
-- bloque `DO $$ ... $$` directo. Se usa el patrón `SELECT format(...) \gexec`:
-- construye la sentencia como texto (ahí sí se interpola) y la ejecuta.
--
-- Idempotente: puede volver a ejecutarse a mano sin duplicar roles ni
-- perder permisos ya concedidos (necesario porque docker-entrypoint-initdb.d
-- solo corre en volúmenes nuevos; en un volumen existente se aplica a mano
-- una vez, ver SYSADMIN.md).

CREATE SCHEMA IF NOT EXISTS app;

CREATE OR REPLACE FUNCTION app.current_tenant_id() RETURNS bigint
    LANGUAGE sql STABLE PARALLEL SAFE
AS $$ SELECT nullif(current_setting('app.tenant_id', true), '')::bigint $$;

-- plataforma_owner: propietario de los objetos, ejecuta las migraciones.
SELECT format('CREATE ROLE plataforma_owner LOGIN PASSWORD %L', :'owner_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'plataforma_owner') \gexec

SELECT format('ALTER ROLE plataforma_owner WITH PASSWORD %L', :'owner_password')
WHERE EXISTS (SELECT FROM pg_roles WHERE rolname = 'plataforma_owner') \gexec

-- plataforma_app: runtime de la API y de los workers. Sin BYPASSRLS.
SELECT format('CREATE ROLE plataforma_app LOGIN PASSWORD %L', :'app_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'plataforma_app') \gexec

SELECT format('ALTER ROLE plataforma_app WITH PASSWORD %L', :'app_password')
WHERE EXISTS (SELECT FROM pg_roles WHERE rolname = 'plataforma_app') \gexec

-- plataforma_platform: backoffice y mantenimiento entre tenants. BYPASSRLS.
SELECT format('CREATE ROLE plataforma_platform LOGIN PASSWORD %L BYPASSRLS', :'platform_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'plataforma_platform') \gexec

SELECT format('ALTER ROLE plataforma_platform WITH PASSWORD %L BYPASSRLS', :'platform_password')
WHERE EXISTS (SELECT FROM pg_roles WHERE rolname = 'plataforma_platform') \gexec

-- plataforma_owner es propietario de los objetos de negocio: ejecuta las
-- migraciones y queda sujeto a sus propias políticas RLS por FORCE.
GRANT ALL ON SCHEMA public TO plataforma_owner;
GRANT USAGE ON SCHEMA app TO plataforma_owner, plataforma_app, plataforma_platform;
GRANT CONNECT ON DATABASE :"dbname" TO plataforma_owner, plataforma_app, plataforma_platform;

-- plataforma_app y plataforma_platform solo hacen DML sobre lo que cree
-- plataforma_owner: privilegios por defecto para tablas y secuencias futuras.
ALTER DEFAULT PRIVILEGES FOR ROLE plataforma_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO plataforma_app, plataforma_platform;
ALTER DEFAULT PRIVILEGES FOR ROLE plataforma_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO plataforma_app, plataforma_platform;

-- Igual para lo que ya exista en este momento (necesario al aplicar el
-- script a mano sobre un volumen con tablas creadas por el rol bootstrap).
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP
        EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON public.%I TO plataforma_app, plataforma_platform', r.tablename);
    END LOOP;
    FOR r IN SELECT sequencename FROM pg_sequences WHERE schemaname = 'public' LOOP
        EXECUTE format('GRANT USAGE, SELECT ON public.%I TO plataforma_app, plataforma_platform', r.sequencename);
    END LOOP;
END
$$;
