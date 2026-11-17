#!/usr/bin/env bash
# Despliegue de la landing (ADR-072 §4.6). ÚNICO punto que conoce al proveedor (Cloudflare Workers
# static assets): cambiar de proveedor toca este script, el secreto y el DNS (RNF-MANT-007).
#
# Uso:  scripts/deploy.sh [--dry-run] [--url <https://...>]
#   --dry-run  Construye, comprueba y simula el despliegue (`wrangler deploy --dry-run`); no sube nada
#              ni hace smoke test (no hay URL desplegada).
#   --url      URL a comprobar en el smoke test. Por defecto, la que imprime `wrangler deploy`.
#   DEPLOY_SMOKE_ATTEMPTS / DEPLOY_SMOKE_DELAY  Reintentos (10) y segundos entre ellos (6) de la primera petición del smoke test.
# Entorno: CLOUDFLARE_API_TOKEN y CLOUDFLARE_ACCOUNT_ID (obligatorios, también en --dry-run).
#
# Puerta de publicación (#582): `npm run check:publish` falla mientras haya marcadores del titular,
# traducciones pendientes o dist/ obsoleto; si falla, NO se despliega.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

DRY_RUN=false
URL=""
while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run) DRY_RUN=true ;;
    --url)
      [ $# -ge 2 ] || { echo "deploy: --url necesita un valor" >&2; exit 2; }
      URL="$2"; shift ;;
    *) echo "deploy: argumento desconocido: $1" >&2; exit 2 ;;
  esac
  shift
done

for v in CLOUDFLARE_API_TOKEN CLOUDFLARE_ACCOUNT_ID; do
  if [ -z "${!v:-}" ]; then
    echo "deploy: falta la variable de entorno $v" >&2
    exit 2
  fi
done

# Sin telemetría de wrangler.
export WRANGLER_SEND_METRICS=false

npm run build
npm run check:publish   # puerta de publicación (#582): si falla, set -e detiene el despliegue

if [ "$DRY_RUN" = true ]; then
  npx wrangler deploy --dry-run
  echo "deploy: --dry-run completado; no se ha desplegado nada ni se ha hecho smoke test"
  exit 0
fi

OUT="$(mktemp)"
trap 'rm -f "$OUT"' EXIT
npx wrangler deploy 2>&1 | tee "$OUT"

if [ -z "$URL" ]; then
  URL="$(grep -Eo 'https://[A-Za-z0-9.-]+\.workers\.dev' "$OUT" | head -1 || true)"
fi
if [ -z "$URL" ]; then
  echo "deploy: no se pudo determinar la URL desplegada para el smoke test (use --url)" >&2
  exit 1
fi

# Smoke test de cabeceras (#583) contra la URL real, con el valor exacto de dist/_headers.
echo "deploy: smoke test de cabeceras en $URL"
# Justo tras `wrangler deploy` la URL puede responder 404 unos segundos (propagación, #666): se reintenta.
ATTEMPTS="${DEPLOY_SMOKE_ATTEMPTS:-10}"
DELAY="${DEPLOY_SMOKE_DELAY:-6}"
HEADERS=""
for n in $(seq 1 "$ATTEMPTS"); do
  if HEADERS="$(curl --fail --silent --show-error --location --max-time 30 --dump-header - --output /dev/null "$URL/")"; then
    break
  fi
  HEADERS=""
  if [ "$n" -lt "$ATTEMPTS" ]; then
    echo "deploy: smoke test: intento $n/$ATTEMPTS sin respuesta correcta de $URL; reintento en ${DELAY}s" >&2
    sleep "$DELAY"
  fi
done
if [ -z "$HEADERS" ]; then
  echo "deploy: smoke test FALLA, $URL no respondió correctamente tras $ATTEMPTS intentos; el despliegue ya está publicado, revertir si procede: npx wrangler rollback (README, «Desplegar y revertir»)" >&2
  exit 1
fi
# Nombres en minúsculas (HTTP/2 los normaliza); los valores se comparan exactos.
HEADERS="$(printf '%s' "$HEADERS" | tr -d '\r' | sed -E 's/^([^:]+):/\L\1:/')"
STATUS=0
while IFS= read -r line; do
  case "$line" in
    "  "*)
      name="${line#  }"; name="${name%%:*}"
      value="${line#*: }"
      lname="$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]')"
      if ! printf '%s\n' "$HEADERS" | grep -qxF -- "$lname: $value"; then
        echo "deploy: smoke test FALLA, cabecera ausente o distinta: $name" >&2
        STATUS=1
      fi ;;
  esac
done < dist/_headers
if [ "$STATUS" -ne 0 ]; then
  echo "deploy: el despliegue ya está publicado pero incumple las cabeceras; revertir ya: npx wrangler rollback (README, «Desplegar y revertir»)" >&2
  exit 1
fi

# Smoke test del script del carrusel (ADR-075): estado 200, tipo MIME JavaScript y nosniff.
JS_HEADERS="$(curl --silent --show-error --location --max-time 30 --dump-header - --output /dev/null "$URL/js/carousel.js" || true)"
JS_HEADERS="$(printf '%s' "$JS_HEADERS" | tr -d '\r' | sed -E 's/^([^:]+):/\L\1:/')"
JS_STATUS="$(printf '%s\n' "$JS_HEADERS" | grep -E '^HTTP/' | tail -1 | awk '{print $2}')"
JS_OK=true
if [ "$JS_STATUS" != "200" ]; then
  echo "deploy: smoke test FALLA, /js/carousel.js responde ${JS_STATUS:-sin estado} (se esperaba 200)" >&2
  JS_OK=false
fi
if ! printf '%s\n' "$JS_HEADERS" | grep -Eiq '^content-type: *(text|application)/javascript([;[:space:]]|$)'; then
  echo "deploy: smoke test FALLA, /js/carousel.js sin tipo MIME JavaScript" >&2
  JS_OK=false
fi
if ! printf '%s\n' "$JS_HEADERS" | grep -Eiq '^x-content-type-options: *nosniff[[:space:]]*$'; then
  echo "deploy: smoke test FALLA, /js/carousel.js sin X-Content-Type-Options: nosniff" >&2
  JS_OK=false
fi
if [ "$JS_OK" != true ]; then
  echo "deploy: el despliegue ya está publicado pero el script del carrusel no se sirve bien; revertir ya: npx wrangler rollback (README, «Desplegar y revertir»)" >&2
  exit 1
fi
echo "deploy: correcto"
