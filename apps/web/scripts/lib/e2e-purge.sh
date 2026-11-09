#!/usr/bin/env bash
# Se carga con `source` desde e2e-real-api.sh y landing-shots.sh. Necesita `DB_CONTAINER` definido por quien lo carga.
#
# Borra de las tablas con `tenant_id` las filas de un tenant. Cada fila de
# estas tablas es sintética; se ejecuta con triggers y FKs desactivados
# (session_replication_role = replica) dentro de una sola transacción.
purge_tenant_rows() {
  local tenant_id="$1"
  [[ "$tenant_id" =~ ^[0-9]+$ ]] || { echo "tenant_id no numérico: no se purga." >&2; return 1; }
  local db_user
  db_user="$(podman exec "$DB_CONTAINER" printenv POSTGRES_USER)"
  podman exec -i "$DB_CONTAINER" psql -q -U "$db_user" -d plataforma -v ON_ERROR_STOP=1 <<SQL
BEGIN;
SET LOCAL session_replication_role = replica;
DO \$\$
DECLARE r record;
BEGIN
  FOR r IN
    SELECT c.relname FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped
    WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
  LOOP
    EXECUTE format('DELETE FROM public.%I WHERE tenant_id = %s', r.relname, ${tenant_id});
  END LOOP;
END
\$\$;
COMMIT;
SQL
}
