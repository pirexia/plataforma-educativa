#!/usr/bin/env bash
# Ejecuta los e2e de alumnado (REQ-ALUM, 1.15-a CA-ALUM-009/012/018a, 1.15-b CA-ALUM-020 a -042 y 1.15-d CA-ALUM-071/072) contra la API real:
# `alum-students-real.spec.ts`, `alum-enrollments-real.spec.ts` y `alum-photo-real.spec.ts` (foto contra Garage; HTTP real con cookie de sesión y CSRF, sin navegador) y, en navegador real,
# `alum-screens-real.spec.ts` y `alum-group-change-real.spec.ts` (1.15-d T3; Playwright arranca la SPA con VITE_API_URL del centro `demo`
# y se niega si ya hay otra en :5173 con otra URL, como `e2e-real-prl.sh`; necesita `demo.plataforma.test` en /etc/hosts). Prepara DOS centros sintéticos con `alum` y `fam_unit`
# contratados y un tutor con el rol `tutor_legal` (apps/api/tests/Support/e2e-real-tenant.php setup <slug> alum), lanza Playwright
# con sus credenciales y los retira al terminar, también si falla (mismo método y garantías que `e2e-real-api.sh`: solo
# contenedores de desarrollo, solo centros con la marca del script, solo datos sintéticos). Usa Garage como almacén S3 (ADR-076, lib/e2e-garage.sh): las exportaciones CSV se sirven por URL firmada.
#
# Requisitos: pila de desarrollo levantada (`plataforma-api` en :8000 y `plataforma-postgres`), migraciones y
# `platform:sync-registry` aplicados. No necesita entradas de `hosts`: cada centro se alcanza por 127.0.0.1:8000 con la cabecera `Host`.
#
# Uso: npm run test:e2e:real:alum -- [argumentos de playwright]
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

# shellcheck source=e2e-vite-api-url.sh
source "$(dirname "${BASH_SOURCE[0]}")/e2e-vite-api-url.sh"
prepare_vite_api_url "$SLUG_A" || exit 2

# Almacén S3 de desarrollo (Garage, ADR-076): levantado, inicializado y con api/worker configurados contra él.
# shellcheck source=lib/e2e-garage.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/e2e-garage.sh"
ensure_garage || exit 2

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
  json="$(support setup "$slug" alum)"
  export "E2E_${prefix}_SLUG=$slug"
  export "E2E_${prefix}_ADMIN_EMAIL=$(printf '%s' "$json" | field admin_email)"
  export "E2E_${prefix}_ADMIN_PASSWORD=$(printf '%s' "$json" | field admin_password)"
  export "E2E_${prefix}_TUTOR_EMAIL=$(printf '%s' "$json" | field tutor_email)"
  export "E2E_${prefix}_TUTOR_USER=$(printf '%s' "$json" | field tutor_user)"
  export "E2E_${prefix}_STAFF_PASSWORD=$(printf '%s' "$json" | field staff_password)"
  export "E2E_${prefix}_SECRETARIA_EMAIL=$(printf '%s' "$json" | field secretaria_email)"
  export "E2E_${prefix}_DIRECCION_EMAIL=$(printf '%s' "$json" | field direccion_email)"
  export "E2E_${prefix}_DOCENTE_EMAIL=$(printf '%s' "$json" | field docente_email)"
  export "E2E_${prefix}_DOCENTE_USER=$(printf '%s' "$json" | field docente_user)"
  export "E2E_${prefix}_DOCENTE2_EMAIL=$(printf '%s' "$json" | field docente2_email)"
  export "E2E_${prefix}_DOCENTE2_USER=$(printf '%s' "$json" | field docente2_user)"
}

prepare A "$SLUG_A"
prepare B "$SLUG_B"
export E2E_REAL_API=1

# Dos invocaciones: primero los specs HTTP y después los de navegador. Los accesos a la API están limitados (429, 10 por minuto y centro desde
# una IP): los specs de navegador esperan lo que pida el servidor, los HTTP no, y por eso van antes.
status=0
npx playwright test e2e/alum-students-real.spec.ts e2e/alum-enrollments-real.spec.ts e2e/alum-photo-real.spec.ts --workers=1 --pass-with-no-tests "$@" || status=$?
npx playwright test e2e/alum-screens-real.spec.ts e2e/alum-group-change-real.spec.ts --workers=1 --pass-with-no-tests "$@" || status=$?
exit "$status"
