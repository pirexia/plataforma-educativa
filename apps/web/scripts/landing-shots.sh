#!/usr/bin/env bash
# Capturas de la landing (ADR-074 §4.4, issue #579). NO es un test: no entra en `test:e2e:real` ni en CI.
#
# Crea el centro de escaparate de la landing (nombre visible ficticio, slug `demo`, datos fijos de
# `e2e/fixtures/landingShowcase.ts` dados de alta por la API real) con el soporte destructivo de la API
# (`apps/api/tests/Support/e2e-real-tenant.php setup demo showcase`), lanza `e2e/landing-shots.spec.ts` con Playwright y
# lo retira SIEMPRE al terminar, también si falla o se interrumpe (trap).
#
# Requisitos: la pila de desarrollo levantada (`plataforma-api` en :8000 y `plataforma-postgres`); la SPA la arranca Playwright
# (`webServer`) con `VITE_API_URL` del subdominio del centro. Los subdominios `*.plataforma.test` se resuelven a loopback con
# `--host-resolver-rules` en el navegador y con la cabecera `Host` en la API (no hace falta tocar `/etc/hosts`).
# El logo de marca se guarda en S3: el script levanta Garage (ADR-076, lib/e2e-garage.sh) y la URL firmada del logo apunta a 127.0.0.1:3900.
# La cola `long` (worker `worker-long`) solo hace falta si un paso ejecuta la copia del curso siguiente.
#
# Retirada: igual que `e2e-real-api.sh` (script de la API borra la fila de `tenants` solo si es el centro de escaparate que creó él,
# y se purgan las filas de ese tenant con el superusuario del PostgreSQL de DESARROLLO). Nunca corre contra producción: la API se
# niega fuera de `APP_ENV` local/testing y de las bases plataforma/plataforma_test.
#
# Uso: npm run landing:shots -- [argumentos de playwright]
set -euo pipefail

if [[ "${E2E_LANDING_SHOTS:-}" != "1" ]]; then
  echo "Lánzalo con \`npm run landing:shots\` (define E2E_LANDING_SHOTS=1): es una herramienta de capturas, no un test." >&2
  exit 2
fi

API_CONTAINER="${API_CONTAINER:-plataforma-api}"
DB_CONTAINER="${DB_CONTAINER:-plataforma-postgres}"
# El escaparate usa el slug `demo`: es el único origen/tenant para el que la CORS de desarrollo de la API está configurada
# (`CORS_ALLOWED_ORIGINS=http://demo.plataforma.test:5173`) y la SPA lee `XSRF-TOKEN` de su propio dominio. El soporte de la
# API no crea ni retira otro slug en el modo `showcase`, y solo retira un `demo` que lleve el nombre fijo y el administrador de escaparate.
SLUG="demo"

# shellcheck source=e2e-vite-api-url.sh
source "$(dirname "${BASH_SOURCE[0]}")/e2e-vite-api-url.sh"
prepare_vite_api_url "$SLUG" || exit 2

# Almacén S3 de desarrollo (Garage, ADR-076): levantado, inicializado y con api/worker configurados contra él.
# shellcheck source=lib/e2e-garage.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/e2e-garage.sh"
ensure_garage || exit 2

# shellcheck source=lib/e2e-purge.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/e2e-purge.sh"

# La purga (lib/e2e-purge.sh) exige E2E_ALLOW_DESTRUCTIVE=1 en el entorno: estos scripts solo operan sobre el centro sintético de desarrollo.
export E2E_ALLOW_DESTRUCTIVE=1
support() { podman exec -e E2E_ALLOW_DESTRUCTIVE=1 "$API_CONTAINER" php tests/Support/e2e-real-tenant.php "$@"; }

# Lee un campo de una línea JSON recibida por stdin (no por argumentos, que serían visibles en `ps`).
field() { python3 -c 'import json,sys; print(json.load(sys.stdin)[sys.argv[1]])' "$1"; }

cleanup() {
  local out id
  if out="$(support teardown "$SLUG" 2>/dev/null)" && [[ "$(printf '%s' "$out" | field outcome)" == "removed" ]]; then
    id="$(printf '%s' "$out" | field tenant_id)"
    purge_tenant_rows "$id" || echo "AVISO: no se pudieron purgar las filas del tenant $id." >&2
  fi
}

# El `trap` va antes de `setup`: si `setup` falla a mitad, tras crear el centro, también se limpia.
trap cleanup EXIT

# Un escaparate anterior de este script (ejecución interrumpida) se retira; si no lo creó el soporte, teardown se niega y
# `setup` aborta.
cleanup

json="$(support setup "$SLUG" showcase)"

E2E_ADMIN_EMAIL="$(printf '%s' "$json" | field admin_email)"
E2E_ADMIN_PASSWORD="$(printf '%s' "$json" | field admin_password)"
export E2E_ADMIN_EMAIL E2E_ADMIN_PASSWORD E2E_SHOWCASE_SLUG="$SLUG"

npx playwright test e2e/landing-shots.spec.ts --workers=1 "$@"
