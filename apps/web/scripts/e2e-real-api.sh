#!/usr/bin/env bash
# Ejecuta los tests e2e que necesitan la API real (CA-PERM-133, E2E_REAL_API=1):
# prepara un centro de pruebas sintético en el contenedor de la API, lanza
# Playwright con sus credenciales y lo retira al terminar, también si falla.
#
# Requisitos: la pila de desarrollo levantada (`plataforma-api` en :8000 y
# `plataforma-web` en :5173) y `demo.plataforma.test` apuntando a 127.0.0.1
# (/etc/hosts). Usa el centro «demo», el de VITE_API_URL en desarrollo; se
# niega a tocar un «demo» que no haya creado el propio script.
#
# Uso: npm run test:e2e:real -- [argumentos de playwright]
set -euo pipefail

API_CONTAINER="${API_CONTAINER:-plataforma-api}"
SLUG="${E2E_TENANT_SLUG:-demo}"
SETUP=(podman exec "$API_CONTAINER" php tests/Support/e2e-real-tenant.php)

cleanup() {
  "${SETUP[@]}" teardown "$SLUG" >/dev/null 2>&1 || echo "AVISO: no se pudo retirar el centro «$SLUG»; ejecuta: ${SETUP[*]} teardown $SLUG" >&2
}

# Un «demo» anterior de este script (ejecución interrumpida) se retira; si no
# lo creó el script, teardown se niega y la ejecución aborta en setup.
"${SETUP[@]}" teardown "$SLUG" >/dev/null 2>&1 || true

json="$("${SETUP[@]}" setup "$SLUG")"
trap cleanup EXIT

field() { python3 -c 'import json,sys; print(json.loads(sys.argv[1])[sys.argv[2]])' "$json" "$1"; }

export E2E_REAL_API=1
E2E_ADMIN_EMAIL="$(field admin_email)"
E2E_ADMIN_PASSWORD="$(field admin_password)"
E2E_TARGET_EMAIL="$(field target_email)"
export E2E_ADMIN_EMAIL E2E_ADMIN_PASSWORD E2E_TARGET_EMAIL

npx playwright test e2e/core-roles.spec.ts -g "CA-PERM-133" "$@"
