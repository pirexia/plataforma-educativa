#!/usr/bin/env bash
# Ejecuta los e2e de la puerta (REQ-PRL-004) contra la API real: `prl-door-api.spec.ts` (1.14b-b, HTTP) y `prl-door-real.spec.ts`
# (1.14b-c2, CA-PRL-012, navegador; necesita la SPA en :5173 con VITE_API_URL=http://<slug>.plataforma.test:8000/api/v1): prepara DOS centros sintéticos con `fam_unit` y `prl`
# contratados y personal con los roles predefinidos (apps/api/tests/Support/e2e-real-tenant.php setup <slug> door), lanza
# Playwright con sus credenciales y los retira al terminar, también si falla (mismo método y mismas garantías que
# `e2e-real-api.sh`: solo contenedores de desarrollo, solo centros que lleven la marca del script, solo datos sintéticos).
#
# Requisitos: la pila de desarrollo levantada (`plataforma-api` en :8000 y `plataforma-postgres`), migraciones y
# `platform:sync-registry` aplicados, y un almacén S3 en `minio:9000` con el *bucket* `plataforma` (MinIO con
# `podman compose --profile full up -d minio` o, si no se puede descargar, el servidor falso de
# apps/api/tests/Support/e2e-fake-s3.php; ver docs/modulos/REQ-PRL/operacion.md §12.7) publicado en 127.0.0.1:9000.
# No necesita entradas de `hosts`: el test alcanza cada centro por 127.0.0.1:8000 con la cabecera `Host` de su subdominio.
#
# Uso: npm run test:e2e:real:prl -- [argumentos de playwright]
set -euo pipefail

API_CONTAINER="${API_CONTAINER:-plataforma-api}"
DB_CONTAINER="${DB_CONTAINER:-plataforma-postgres}"
SLUG_A="${E2E_TENANT_SLUG_A:-demo}"
SLUG_B="${E2E_TENANT_SLUG_B:-demo2}"

for slug in "$SLUG_A" "$SLUG_B"; do
  if [[ ! "$slug" =~ ^[a-z0-9][a-z0-9-]{0,39}$ ]]; then
    echo "Slug no válido ($slug): minúsculas, dígitos y guiones, hasta 40." >&2
    exit 2
  fi
done

support() { podman exec -e E2E_ALLOW_DESTRUCTIVE=1 "$API_CONTAINER" php tests/Support/e2e-real-tenant.php "$@"; }
field() { python3 -c 'import json,sys; print(json.load(sys.stdin)[sys.argv[1]])' "$1"; }

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

remove_tenant() {
  local out id
  if out="$(support teardown "$1" 2>/dev/null)" && [[ "$(printf '%s' "$out" | field outcome)" == "removed" ]]; then
    id="$(printf '%s' "$out" | field tenant_id)"
    purge_tenant_rows "$id" || echo "AVISO: no se pudieron purgar las filas del tenant $id." >&2
  fi
}

cleanup() {
  remove_tenant "$SLUG_A"
  remove_tenant "$SLUG_B"
}

trap cleanup EXIT
cleanup

prepare() {
  local prefix="$1" slug="$2" json
  json="$(support setup "$slug" door)"

  export "E2E_${prefix}_SLUG=$slug"
  export "E2E_${prefix}_ADMIN_EMAIL=$(printf '%s' "$json" | field admin_email)"
  export "E2E_${prefix}_ADMIN_PASSWORD=$(printf '%s' "$json" | field admin_password)"
  export "E2E_${prefix}_CONSERJE_EMAIL=$(printf '%s' "$json" | field conserje_email)"
  export "E2E_${prefix}_SECRETARIA_EMAIL=$(printf '%s' "$json" | field secretaria_email)"
  export "E2E_${prefix}_STAFF_PASSWORD=$(printf '%s' "$json" | field staff_password)"
  export "E2E_${prefix}_STUDENT_1=$(printf '%s' "$json" | field student_1)"
  export "E2E_${prefix}_STUDENT_2=$(printf '%s' "$json" | field student_2)"
}

prepare A "$SLUG_A"
prepare B "$SLUG_B"
export E2E_REAL_API=1

npx playwright test e2e/prl-door-api.spec.ts e2e/prl-door-real.spec.ts --workers=1 "$@"
