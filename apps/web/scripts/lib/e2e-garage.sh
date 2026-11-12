#!/usr/bin/env bash
# Se carga con `source` desde los scripts de E2E real y landing-shots.sh (ADR-076 §6, §7.1).
#
# `ensure_garage`: deja el almacén S3 de desarrollo listo para el E2E.
#   1. Levanta `garage` (perfil `full`) y ejecuta `infra/garage/init.sh` (idempotente: layout, clave y bucket).
#   2. Si `api` o `worker` no están configurados contra Garage con `FILESYSTEM_DISK=s3` (el perfil reducido usa el disco
#      local, ADR-030), los recrea con esa configuración. No toca `postgres`, `redis` ni `web`.
# Variables: CONTAINER_CLI (podman), API_CONTAINER (plataforma-api), WORKER_CONTAINER (plataforma-worker).
# Requiere `.env` en la raíz con las variables GARAGE_* y `apps/api/.env`. Solo datos sintéticos (ADR-030).
ensure_garage() {
  local cli="${CONTAINER_CLI:-podman}" api="${API_CONTAINER:-plataforma-api}" worker="${WORKER_CONTAINER:-plataforma-worker}"
  local root
  root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"

  # Guarda de entorno (revisión de seguridad B1): este helper levanta y recrea contenedores, así que solo se ejecuta contra la
  # pila de desarrollo. Alinea con la guarda de `e2e-real-tenant.php` (solo local/testing) y falla antes de tocar nada.
  local app_env
  app_env="$("$cli" exec "$api" printenv APP_ENV 2>/dev/null || true)"
  case "$app_env" in
    local|testing) ;;
    *)
      echo "ensure_garage: APP_ENV='${app_env}' en $api no es local ni testing (¿pila de desarrollo caída?); no se toca nada." >&2
      return 2
      ;;
  esac

  (cd "$root" && "$cli" compose --profile full up -d garage >/dev/null 2>&1) \
    || { echo "ensure_garage: no se pudo levantar el servicio garage (¿falta .env con GARAGE_*?)." >&2; return 2; }
  (cd "$root" && CONTAINER_CLI="$cli" bash infra/garage/init.sh) \
    || { echo "ensure_garage: infra/garage/init.sh falló." >&2; return 2; }

  local c need=0
  for c in "$api" "$worker"; do
    if ! "$cli" exec "$c" sh -c '[ "$FILESYSTEM_DISK" = s3 ] && [ "$AWS_ENDPOINT" = "http://plataforma-garage:3900" ]' >/dev/null 2>&1; then
      need=1
    fi
  done
  if [[ "$need" == 1 ]]; then
    echo "ensure_garage: recreando api y worker con FILESYSTEM_DISK=s3 contra Garage." >&2
    (cd "$root" && FILESYSTEM_DISK=s3 "$cli" compose --profile full up -d --no-deps --force-recreate api worker >/dev/null 2>&1) \
      || { echo "ensure_garage: no se pudieron recrear api y worker." >&2; return 2; }
    local _
    for _ in $(seq 1 60); do
      curl -fs -m 3 -o /dev/null http://127.0.0.1:8000/api/health && return 0
      sleep 2
    done
    echo "ensure_garage: la API no responde tras recrearla." >&2
    return 2
  fi
}
