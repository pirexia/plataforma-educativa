#!/usr/bin/env bash
# ADR-037 §5.4: instalador de unas decenas de líneas, no un gestor de
# configuración. Copia las unidades Quadlet a su sitio, sustituye el tag
# de imagen con un sed de una línea (Quadlet no expande variables de
# entorno en Image=) y recarga systemd. Pensado para un host, ejecutado a
# mano una vez por despliegue — no para tres hosts ni automatización
# diaria (eso es Ansible, descartado explícitamente en el ADR).
#
# Uso:
#   ./infra/install.sh <tag> [--user] [--with-mailpit]
#
#   <tag>      Versión exacta a desplegar (X.Y.Z en producción, sha-<7>
#              o "develop" en staging). Nunca "latest" (ADR-037 §5.2).
#   --user     Instala como unidades de usuario (systemd --user), el modo
#              en que se prueba en WSL2 (ADR-037 §6.5). Sin esta bandera,
#              instala como unidades de sistema (root, VPS real).
#   --with-mailpit  Instala y habilita Mailpit (captura de correo, solo
#              staging; NO usar en producción). Sin la opción no se
#              renderiza ni se gestiona su unidad; si ya hay una instalada,
#              no se borra.
#
# Requiere que /etc/plataforma/plataforma.env (o, en modo --user,
# ~/.config/plataforma/plataforma.env) exista ya — este script no lo crea,
# ver RUNBOOK.md para el procedimiento de generación de secretos.
#
# REQ-BO (1.6), ADR-046 §4.3/§4.4/§4.6: desde este paso, TENANCY_BASE_
# DOMAIN, BACKOFFICE_HOST y BACKOFFICE_ALLOWED_IPS también se sustituyen
# en las unidades, con el mismo sed de una línea que __TAG__ — no es un
# segundo sistema de plantillado, son tres tokens más del mismo tipo. Se
# leen del propio plataforma.env que este script ya exige que exista,
# para no pedir tres argumentos más en la línea de comandos.

set -euo pipefail

TAG="${1:?Uso: install.sh <tag> [--user] [--with-mailpit]}"
shift
USER_MODE=""
WITH_MAILPIT=0
for arg in "$@"; do
    case "$arg" in
        --user) USER_MODE="--user" ;;
        --with-mailpit) WITH_MAILPIT=1 ;;
        *) echo "Opción desconocida: $arg. Uso: install.sh <tag> [--user] [--with-mailpit]" >&2; exit 1 ;;
    esac
done
export WITH_MAILPIT

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_DIR="$REPO_ROOT/infra/quadlet"

if [[ "$USER_MODE" == "--user" ]]; then
    DEST_DIR="$HOME/.config/containers/systemd"
    SYSTEMCTL="systemctl --user"
    ENV_FILE="$HOME/.config/plataforma/plataforma.env"
    TRAEFIK_ENV_FILE="$HOME/.config/plataforma/traefik-cloudflare.env"
    POSTGRES_INIT_DIR="$HOME/.local/share/plataforma/postgres-init"
else
    DEST_DIR="/etc/containers/systemd"
    SYSTEMCTL="sudo systemctl"
    ENV_FILE="/etc/plataforma/plataforma.env"
    TRAEFIK_ENV_FILE="/etc/plataforma/traefik-cloudflare.env"
    POSTGRES_INIT_DIR="/opt/plataforma/postgres-init"
fi
# Directorio de los ficheros de entorno (api, postgres, workers), derivado
# del ENV_FILE ya calculado: sistema /etc/plataforma, --user ~/.config/plataforma.
ENV_DIR="$(dirname "$ENV_FILE")"

if [[ ! -f "$ENV_FILE" ]]; then
    echo "No existe $ENV_FILE — ver RUNBOOK.md para generarlo antes de instalar." >&2
    exit 1
fi

# Funciones y validaciones (env_value, load_and_validate_config,
# check_token_file...) en install-lib.sh, para poder probarlas sin systemctl.
# shellcheck source=infra/install-lib.sh
source "$REPO_ROOT/infra/install-lib.sh"

load_and_validate_config || exit 1
check_token_file || exit 1
warn_acme_staging_in_production "$TAG"

echo "Instalando unidades Quadlet en $DEST_DIR con tag=$TAG"
render_units "$SOURCE_DIR" "$DEST_DIR" "$TAG"
prepare_postgres_init "$REPO_ROOT/infra/containers/postgres/init" "$POSTGRES_INIT_DIR"

$SYSTEMCTL daemon-reload

echo "Unidades instaladas. Arrancar con, por ejemplo:"
# Las unidades las genera Quadlet (/run/.../generator): "enable" falla con
# "transient or generated" (issue #695). Se activan solas por su [Install]
# y aquí solo se arrancan con "start".
echo "  $SYSTEMCTL daemon-reload"
echo "  $SYSTEMCTL start plataforma-network.service"
echo "  $SYSTEMCTL start postgres.service redis.service"
# plataforma-migrate.container es un oneshot con RemainAfterExit: tras la
# primera ejecución queda "active (exited)" y un "start" no hace nada, así
# que las migraciones del tag nuevo no se ejecutarían (issue #698). Hay que
# usar "restart" y comprobar el resultado antes de seguir.
echo "  $SYSTEMCTL restart plataforma-migrate.service   # restart y no start: la unidad (oneshot + RemainAfterExit) queda activa y start no migra"
echo "  $SYSTEMCTL show plataforma-migrate.service -p Result --value   # debe ser 'success' antes de seguir"
echo "  $SYSTEMCTL start api@1.service web.service traefik.service"
# Worker de colas (issue #128): sin él, los trabajos de cola (correos de
# MFA, invitaciones, restablecimiento de contraseña, exportaciones) quedan
# en la tabla `jobs` sin procesar. Lee su propio fichero de entorno,
# plataforma-worker.env (plantilla: plataforma-worker.env.example). worker-long.service (plataforma-worker-long.env) consume la
# cola `long` (copia y reversión del rollover del curso, REQ-CURSO 1.12b) con su propio retry_after.
echo "  $SYSTEMCTL start worker.service worker-long.service"
if [[ "$WITH_MAILPIT" == "1" ]]; then
    echo "  $SYSTEMCTL start mailpit.service   # solo staging; UI en 127.0.0.1:8025 por túnel SSH"
fi
if [[ ! -f "$(dirname "$ENV_FILE")/plataforma-worker.env" ]]; then
    echo "AVISO: falta $(dirname "$ENV_FILE")/plataforma-worker.env (plantilla: infra/quadlet/plataforma-worker.env.example); worker.service no arrancará sin él." >&2
fi
# worker-long.service lee su propio fichero, sin DB_OWNER_* ni DB_PLATFORM_* (REQ-CURSO 1.12b, revisión M2).
if [[ ! -f "$(dirname "$ENV_FILE")/plataforma-worker-long.env" ]]; then
    echo "AVISO: falta $(dirname "$ENV_FILE")/plataforma-worker-long.env (plantilla: infra/quadlet/plataforma-worker-long.env.example); worker-long.service no arrancará sin él." >&2
fi
