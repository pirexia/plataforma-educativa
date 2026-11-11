#!/usr/bin/env bash
# Inicialización idempotente de Garage para desarrollo, CI y E2E (ADR-076 §6).
#
# La imagen oficial no trae shell: el script corre en el host e invoca el
# binario /garage dentro del contenedor con `podman exec`.
#
# Variables (del entorno o del .env de la raíz; nunca con valores en el repo):
#   GARAGE_ACCESS_KEY_ID      GK + 24 hex (formato que exige Garage)
#   GARAGE_SECRET_ACCESS_KEY  64 hex
#   GARAGE_BUCKET             bucket del entorno (p. ej. plataforma-dev)
# Opcionales: GARAGE_CONTAINER (plataforma-garage), GARAGE_CAPACITY (1G),
#   GARAGE_ZONE (dc1), CONTAINER_CLI (podman), GARAGE_WAIT_SECONDS (60).
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

fail() { echo "garage-init: ERROR: $*" >&2; exit 1; }
log() { echo "garage-init: $*"; }

# Lee VAR del .env sin ejecutarlo (no se hace `source` de un fichero de secretos).
load_var() {
  local name="$1" line
  [ -n "${!name:-}" ] && return 0
  [ -f "$root/.env" ] || return 0
  line="$(grep -E "^${name}=" "$root/.env" | tail -n1 || true)"
  [ -n "$line" ] || return 0
  line="${line#*=}"
  line="${line%\"}"; line="${line#\"}"
  printf -v "$name" '%s' "$line"
}

for v in GARAGE_ACCESS_KEY_ID GARAGE_SECRET_ACCESS_KEY GARAGE_BUCKET; do
  load_var "$v"
  [ -n "${!v:-}" ] || fail "falta $v (defínela en .env o en el entorno)"
done

cli="${CONTAINER_CLI:-podman}"
container="${GARAGE_CONTAINER:-plataforma-garage}"
capacity="${GARAGE_CAPACITY:-1G}"
zone="${GARAGE_ZONE:-dc1}"
wait_s="${GARAGE_WAIT_SECONDS:-60}"

[[ "$GARAGE_ACCESS_KEY_ID" =~ ^GK[0-9a-f]{24}$ ]] \
  || fail "GARAGE_ACCESS_KEY_ID debe ser 'GK' + 24 hex minúsculas"
[[ "$GARAGE_SECRET_ACCESS_KEY" =~ ^[0-9a-f]{64}$ ]] \
  || fail "GARAGE_SECRET_ACCESS_KEY debe ser 64 hex minúsculas"
[[ "$GARAGE_BUCKET" =~ ^[a-z0-9][a-z0-9-]{2,62}$ ]] \
  || fail "GARAGE_BUCKET no es un nombre de bucket válido"

# Valores opcionales: se validan para que nunca se interpreten como opciones de la CLI.
[[ "$capacity" =~ ^[0-9]+[KMGT]?$ ]] || fail "GARAGE_CAPACITY no es válida (p. ej. 1G)"
[[ "$zone" =~ ^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$ ]] || fail "GARAGE_ZONE no es válida"
[[ "$container" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$ ]] || fail "GARAGE_CONTAINER no es válido"
[[ "$cli" =~ ^[A-Za-z0-9][A-Za-z0-9_./-]{0,63}$ ]] || fail "CONTAINER_CLI no es válido"
[[ "$wait_s" =~ ^[0-9]{1,4}$ ]] || fail "GARAGE_WAIT_SECONDS no es válido"

g() { "$cli" exec -e RUST_LOG=warn "$container" /garage "$@"; }

# 1. Esperar a que el nodo responda.
log "esperando a que $container responda (máx. ${wait_s}s)"
ready=0
for _ in $(seq 1 "$wait_s"); do
  if g status >/dev/null 2>&1; then ready=1; break; fi
  sleep 1
done
[ "$ready" = 1 ] || fail "el nodo $container no responde tras ${wait_s}s"

# 2. Layout: solo si el nodo no tiene rol asignado.
node_id="$(g node id -q 2>/dev/null | cut -d@ -f1)" || true
[[ "$node_id" =~ ^[0-9a-f]{64}$ ]] || fail "no se pudo obtener el id del nodo"
if g layout show 2>/dev/null | grep -qE "^${node_id:0:16}[[:space:]]"; then
  log "layout ya aplicado, no se toca"
else
  log "asignando el nodo (zona $zone, capacidad $capacity) y aplicando la versión 1"
  g layout assign -z "$zone" -c "$capacity" "${node_id:0:16}" >/dev/null \
    || fail "layout assign falló"
  g layout apply --version 1 >/dev/null || fail "layout apply falló"
fi

# 3. Clave de acceso con id y secreto fijos (key import falla con 409 si existe).
if g key info "$GARAGE_ACCESS_KEY_ID" >/dev/null 2>&1; then
  log "la clave de acceso ya existe, no se toca"
else
  log "importando la clave de acceso"
  g key import --yes -n "$GARAGE_BUCKET" \
    "$GARAGE_ACCESS_KEY_ID" "$GARAGE_SECRET_ACCESS_KEY" >/dev/null \
    || fail "key import falló"
fi

# 4. Bucket del entorno y permisos de la clave (allow es idempotente).
if g bucket info "$GARAGE_BUCKET" >/dev/null 2>&1; then
  log "el bucket $GARAGE_BUCKET ya existe, no se crea"
else
  log "creando el bucket $GARAGE_BUCKET"
  g bucket create "$GARAGE_BUCKET" >/dev/null || fail "bucket create falló"
fi
g bucket allow --read --write --owner "$GARAGE_BUCKET" --key "$GARAGE_ACCESS_KEY_ID" >/dev/null \
  || fail "bucket allow falló"

log "listo: bucket $GARAGE_BUCKET accesible con la clave $GARAGE_ACCESS_KEY_ID"
