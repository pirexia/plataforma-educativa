#!/usr/bin/env bash
# Ejecuta los tests e2e que necesitan la API real (CA-PERM-133, CA-CURSO-086, CA-CURSO-147, CA-ACAD-053, CA-ACAD-101 y CA-ACAD-135, E2E_REAL_API=1):
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

# Prepara un centro limpio y exporta sus credenciales. Cada suite recibe el
# suyo: los tests de curso y de estructura académica crean cursos académicos
# con el mismo código y se estorbarían en un centro compartido.
prepare_tenant() {
  # Un «demo» anterior de este script (ejecución interrumpida o suite previa) se
  # retira; si no lo creó el script, teardown se niega y la ejecución aborta en setup.
  cleanup

  local json
  json="$(support setup "$SLUG")"

  export E2E_REAL_API=1
  E2E_ADMIN_EMAIL="$(printf '%s' "$json" | field admin_email)"
  E2E_ADMIN_PASSWORD="$(printf '%s' "$json" | field admin_password)"
  E2E_TARGET_EMAIL="$(printf '%s' "$json" | field target_email)"
  export E2E_ADMIN_EMAIL E2E_ADMIN_PASSWORD E2E_TARGET_EMAIL
}

prepare_tenant
npx playwright test e2e/core-roles.spec.ts e2e/curso-academic-years.spec.ts -g "CA-PERM-133|CA-CURSO-086" --workers=1 "$@"

# REQ-ACAD (1.11-b), CA-ACAD-053: centro propio, con `acad` contratado por el setup.
prepare_tenant
npx playwright test e2e/acad-structure.spec.ts -g "CA-ACAD-053" --workers=1 "$@"

# REQ-ACAD (1.11d-a), CA-ACAD-101: períodos lectivos de la ficha de etapa. El test crea su propio curso y su etapa
# y necesita `acad` contratado, que el setup ya deja hecho.
prepare_tenant
npx playwright test e2e/acad-periods.spec.ts -g "CA-ACAD-101" --workers=1 "$@"

# REQ-ACAD (1.11d-b), CA-ACAD-101 (parte de escalas): escala cualitativa reordenada, mixta 0-10 y 320 px. Crea su propio
# curso, igual que el de períodos.
prepare_tenant
npx playwright test e2e/acad-scales.spec.ts -g "CA-ACAD-101" --workers=1 "$@"

# REQ-ACAD (1.12-b), CA-ACAD-135: aula, marco ordinario con franjas, asignaciones docentes, sesiones, conflicto de
# profesor y vista del profesor. Crea su propio curso, etapa, grupo y asignaturas; usa los dos profesores sintéticos
# (Daniel y Marta Docente) que deja el setup.
prepare_tenant
npx playwright test e2e/acad-timetable.spec.ts -g "CA-ACAD-135" --workers=1 "$@"

# REQ-CURSO (1.12b), CA-CURSO-147: apertura del curso siguiente. Crea sus propios cursos y su etapa, y ejecuta la cola `long`
# dentro del contenedor de la API (`queue:work database_long --queue=long --stop-when-empty`); `acad` lo contrata el setup.
prepare_tenant
npx playwright test e2e/curso-rollover.spec.ts -g "CA-CURSO-147" --workers=1 "$@"
