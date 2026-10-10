#!/usr/bin/env bash
# Ejecuta los tests e2e que necesitan la API real (CA-PERM-133 y CA-CURSO-086, E2E_REAL_API=1):
# prepara un centro de pruebas sintético en el contenedor de la API, lanza
# Playwright con sus credenciales y lo retira por completo al terminar, también
# si falla.
#
# Requisitos: la pila de desarrollo levantada (`plataforma-api` en :8000,
# `plataforma-web` en :5173 y `plataforma-postgres`) y `demo.plataforma.test`
# apuntando a 127.0.0.1 (/etc/hosts). Usa el centro «demo», el de VITE_API_URL
# en desarrollo; se niega a tocar un «demo» que no haya creado el propio script.
#
# Retirada: el script de la API borra la fila de `tenants` (solo si lleva su
# marca) y devuelve el `tenant_id`; este script purga con el superusuario del
# contenedor de PostgreSQL de DESARROLLO las filas de ese tenant en las tablas
# con `tenant_id`, porque no hay `ON DELETE CASCADE` y el rol de plataforma no
# puede borrar de las tablas de solo anexar. Nunca corre contra producción:
# solo habla con los contenedores de desarrollo, y el script de la API se
# niega fuera de `APP_ENV` local/testing y de las bases plataforma/plataforma_test.
#
# Uso: npm run test:e2e:real -- [argumentos de playwright]
set -euo pipefail

API_CONTAINER="${API_CONTAINER:-plataforma-api}"
DB_CONTAINER="${DB_CONTAINER:-plataforma-postgres}"
SLUG="${E2E_TENANT_SLUG:-demo}"

if [[ ! "$SLUG" =~ ^[a-z0-9][a-z0-9-]{0,39}$ ]]; then
  echo "E2E_TENANT_SLUG no válido: minúsculas, dígitos y guiones, hasta 40." >&2
  exit 2
fi

support() { podman exec -e E2E_ALLOW_DESTRUCTIVE=1 "$API_CONTAINER" php tests/Support/e2e-real-tenant.php "$@"; }

# Lee un campo de una línea JSON recibida por stdin (no por argumentos, que
# serían visibles en `ps`).
field() { python3 -c 'import json,sys; print(json.load(sys.stdin)[sys.argv[1]])' "$1"; }

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

cleanup() {
  local out id
  if out="$(support teardown "$SLUG" 2>/dev/null)" && [[ "$(printf '%s' "$out" | field outcome)" == "removed" ]]; then
    id="$(printf '%s' "$out" | field tenant_id)"
    purge_tenant_rows "$id" || echo "AVISO: no se pudieron purgar las filas del tenant $id." >&2
  fi
}

# El `trap` va antes de `setup`: si `setup` falla a mitad, tras crear el centro,
# también se limpia.
trap cleanup EXIT

# Un «demo» anterior de este script (ejecución interrumpida) se retira; si no
# lo creó el script, teardown se niega y la ejecución aborta en setup.
cleanup

json="$(support setup "$SLUG")"

export E2E_REAL_API=1
E2E_ADMIN_EMAIL="$(printf '%s' "$json" | field admin_email)"
E2E_ADMIN_PASSWORD="$(printf '%s' "$json" | field admin_password)"
E2E_TARGET_EMAIL="$(printf '%s' "$json" | field target_email)"
export E2E_ADMIN_EMAIL E2E_ADMIN_PASSWORD E2E_TARGET_EMAIL

npx playwright test e2e/core-roles.spec.ts e2e/curso-academic-years.spec.ts -g "CA-PERM-133|CA-CURSO-086" "$@"
