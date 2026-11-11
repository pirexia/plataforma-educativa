#!/usr/bin/env bash
# Ejecuta los tests e2e que necesitan la API real (CA-PERM-133, CA-CURSO-086, CA-CURSO-147, CA-ACAD-053, CA-ACAD-101, CA-ACAD-135 y CA-FAM-UNIT-142, E2E_REAL_API=1):
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

# La SPA que arranca Playwright usa el subdominio del centro, no el `localhost` de `apps/web/.env` (issue #530).
# shellcheck source=e2e-vite-api-url.sh
source "$(dirname "${BASH_SOURCE[0]}")/e2e-vite-api-url.sh"
prepare_vite_api_url "$SLUG" || exit 2

# Almacén S3 de desarrollo (Garage, ADR-076): levantado, inicializado y con api/worker configurados contra él.
# shellcheck source=lib/e2e-garage.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/e2e-garage.sh"
ensure_garage || exit 2

# `E2E_ONLY` no vacío y fuera de la lista cerrada: se rechaza antes de tocar nada (un typo no puede salir en verde sin
# haber ejecutado ninguna suite).
case "${E2E_ONLY:-}" in
  ''|core|acad-structure|acad-periods|acad-scales|acad-timetable|curso-rollover|fam-unit) ;;
  *)
    echo "E2E_ONLY no válido: «${E2E_ONLY}». Valores: core, acad-structure, acad-periods, acad-scales, acad-timetable, curso-rollover, fam-unit." >&2
    exit 2
    ;;
esac

# La purga (lib/e2e-purge.sh) exige E2E_ALLOW_DESTRUCTIVE=1 en el entorno: estos scripts solo operan sobre el centro sintético de desarrollo.
export E2E_ALLOW_DESTRUCTIVE=1
support() { podman exec -e E2E_ALLOW_DESTRUCTIVE=1 "$API_CONTAINER" php tests/Support/e2e-real-tenant.php "$@"; }

# Lee un campo de una línea JSON recibida por stdin (no por argumentos, que
# serían visibles en `ps`).
field() { python3 -c 'import json,sys; print(json.load(sys.stdin)[sys.argv[1]])' "$1"; }

# `purge_tenant_rows` (purga con el superusuario del contenedor de PostgreSQL de desarrollo) vive en un fichero compartido con
# `landing-shots.sh`.
# shellcheck source=lib/e2e-purge.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/e2e-purge.sh"

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
# `E2E_ONLY=<suite>` ejecuta una sola suite (`core`, `acad-structure`, `acad-periods`, `acad-scales`, `acad-timetable`,
# `curso-rollover`, `fam-unit`); sin ella, todas, como siempre.
only() { [[ -z "${E2E_ONLY:-}" || "$E2E_ONLY" == "$1" ]]; }

prepare_tenant() {
  local mode="${1:-}"

  # Un «demo» anterior de este script (ejecución interrumpida o suite previa) se
  # retira; si no lo creó el script, teardown se niega y la ejecución aborta en setup.
  cleanup

  local json
  json="$(support setup "$SLUG" $mode)"

  export E2E_REAL_API=1
  E2E_ADMIN_EMAIL="$(printf '%s' "$json" | field admin_email)"
  E2E_ADMIN_PASSWORD="$(printf '%s' "$json" | field admin_password)"
  E2E_TARGET_EMAIL="$(printf '%s' "$json" | field target_email)"
  export E2E_ADMIN_EMAIL E2E_ADMIN_PASSWORD E2E_TARGET_EMAIL

  # Con `door` (`fam_unit` y `prl` contratados, secretaría y conserjería, dos alumnos ficticios).
  if [[ "$mode" == "door" ]]; then
    E2E_SECRETARIA_EMAIL="$(printf '%s' "$json" | field secretaria_email)"
    E2E_STAFF_PASSWORD="$(printf '%s' "$json" | field staff_password)"
    E2E_STUDENT_1="$(printf '%s' "$json" | field student_1)"
    export E2E_SECRETARIA_EMAIL E2E_STAFF_PASSWORD E2E_STUDENT_1
  fi
}

if only core; then
  prepare_tenant
  npx playwright test e2e/core-roles.spec.ts e2e/curso-academic-years.spec.ts -g "CA-PERM-133|CA-CURSO-086" --workers=1 "$@"
fi

# REQ-ACAD (1.11-b), CA-ACAD-053: centro propio, con `acad` contratado por el setup.
if only acad-structure; then
  prepare_tenant
  npx playwright test e2e/acad-structure.spec.ts -g "CA-ACAD-053" --workers=1 "$@"
fi

# REQ-ACAD (1.11d-a), CA-ACAD-101: períodos lectivos de la ficha de etapa. El test crea su propio curso y su etapa
# y necesita `acad` contratado, que el setup ya deja hecho.
if only acad-periods; then
  prepare_tenant
  npx playwright test e2e/acad-periods.spec.ts -g "CA-ACAD-101" --workers=1 "$@"
fi

# REQ-ACAD (1.11d-b), CA-ACAD-101 (parte de escalas): escala cualitativa reordenada, mixta 0-10 y 320 px. Crea su propio
# curso, igual que el de períodos.
if only acad-scales; then
  prepare_tenant
  npx playwright test e2e/acad-scales.spec.ts -g "CA-ACAD-101" --workers=1 "$@"
fi

# REQ-ACAD (1.12-b), CA-ACAD-135: aula, marco ordinario con franjas, asignaciones docentes, sesiones, conflicto de
# profesor y vista del profesor. Crea su propio curso, etapa, grupo y asignaturas; usa los dos profesores sintéticos
# (Daniel y Marta Docente) que deja el setup.
if only acad-timetable; then
  prepare_tenant
  npx playwright test e2e/acad-timetable.spec.ts -g "CA-ACAD-135" --workers=1 "$@"
fi

# REQ-CURSO (1.12b), CA-CURSO-147: apertura del curso siguiente. Crea sus propios cursos y su etapa, y ejecuta la cola `long`
# dentro del contenedor de la API (`queue:work database_long --queue=long --stop-when-empty`); `acad` lo contrata el setup.
if only curso-rollover; then
  prepare_tenant
  npx playwright test e2e/curso-rollover.spec.ts -g "CA-CURSO-147" --workers=1 "$@"
fi

# REQ-FAM-UNIT (1.14b-c1 T3), CA-FAM-UNIT-142: sección «Personas autorizadas» en navegador real contra la API real
# (persona → autorización → fotografía → lista vigente → retirada). Centro propio con `fam_unit` y `prl` (modo `door`).
# El almacén S3 es Garage (ADR-076): `ensure_garage` lo levanta, ejecuta infra/garage/init.sh y recrea api/worker si hace falta.
# Su endpoint de firma es 127.0.0.1:3900, alcanzable desde el navegador y desde el host.
if only fam-unit; then
  prepare_tenant door
  npx playwright test e2e/fam-unit-pickup-real.spec.ts --workers=1 "$@"
fi
