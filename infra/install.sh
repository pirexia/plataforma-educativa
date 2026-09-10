#!/usr/bin/env bash
# ADR-037 §5.4: instalador de unas decenas de líneas, no un gestor de
# configuración. Copia las unidades Quadlet a su sitio, sustituye el tag
# de imagen con un sed de una línea (Quadlet no expande variables de
# entorno en Image=) y recarga systemd. Pensado para un host, ejecutado a
# mano una vez por despliegue — no para tres hosts ni automatización
# diaria (eso es Ansible, descartado explícitamente en el ADR).
#
# Uso:
#   ./infra/install.sh <tag> [--user]
#
#   <tag>      Versión exacta a desplegar (X.Y.Z en producción, sha-<7>
#              o "develop" en staging). Nunca "latest" (ADR-037 §5.2).
#   --user     Instala como unidades de usuario (systemd --user), el modo
#              en que se prueba en WSL2 (ADR-037 §6.5). Sin esta bandera,
#              instala como unidades de sistema (root, VPS real).
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

TAG="${1:?Uso: install.sh <tag> [--user]}"
USER_MODE="${2:-}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_DIR="$REPO_ROOT/infra/quadlet"

if [[ "$USER_MODE" == "--user" ]]; then
    DEST_DIR="$HOME/.config/containers/systemd"
    SYSTEMCTL="systemctl --user"
    ENV_FILE="$HOME/.config/plataforma/plataforma.env"
else
    DEST_DIR="/etc/containers/systemd"
    SYSTEMCTL="sudo systemctl"
    ENV_FILE="/etc/plataforma/plataforma.env"
fi

if [[ ! -f "$ENV_FILE" ]]; then
    echo "No existe $ENV_FILE — ver RUNBOOK.md para generarlo antes de instalar." >&2
    exit 1
fi

# Lectura simple de valores del env file, sin exportarlo entero (evita
# ejecutar secretos como comandos si el fichero tuviera algo raro).
env_value() {
    grep -E "^${1}=" "$ENV_FILE" | tail -n1 | cut -d= -f2-
}

TENANCY_BASE_DOMAIN="$(env_value TENANCY_BASE_DOMAIN)"
BACKOFFICE_HOST="$(env_value BACKOFFICE_HOST)"
BACKOFFICE_ALLOWED_IPS="$(env_value BACKOFFICE_ALLOWED_IPS)"

if [[ -z "$TENANCY_BASE_DOMAIN" || -z "$BACKOFFICE_HOST" ]]; then
    echo "TENANCY_BASE_DOMAIN y BACKOFFICE_HOST tienen que estar fijados en $ENV_FILE" >&2
    echo "(operacion.md §0.4, RN-BO-49: BACKOFFICE_HOST nunca puede ser subdominio de TENANCY_BASE_DOMAIN)." >&2
    exit 1
fi

# Escapado para HostRegexp (un literal de regexp, no una cadena Traefik):
# el único metacaracter real en un dominio es el punto.
TENANCY_BASE_DOMAIN_ESCAPED="${TENANCY_BASE_DOMAIN//./\\.}"

echo "Instalando unidades Quadlet en $DEST_DIR con tag=$TAG"
mkdir -p "$DEST_DIR"

for unit in "$SOURCE_DIR"/*.container "$SOURCE_DIR"/*.network "$SOURCE_DIR"/*.volume; do
    name="$(basename "$unit")"
    # __TAG__ es el punto de variación original (ADR-037 §6.1); los otros
    # tres son su misma forma, no un sistema de plantillado nuevo.
    sed \
        -e "s/__TAG__/$TAG/g" \
        -e "s/__TENANCY_BASE_DOMAIN_ESCAPED__/$TENANCY_BASE_DOMAIN_ESCAPED/g" \
        -e "s/__BACKOFFICE_HOST__/$BACKOFFICE_HOST/g" \
        -e "s/__BACKOFFICE_ALLOWED_IPS__/$BACKOFFICE_ALLOWED_IPS/g" \
        "$unit" > "$DEST_DIR/$name"
done

$SYSTEMCTL daemon-reload

echo "Unidades instaladas. Arrancar con, por ejemplo:"
echo "  $SYSTEMCTL enable --now plataforma.network"
echo "  $SYSTEMCTL enable --now postgres.service redis.service"
# plataforma-migrate.container es un oneshot sin sección [Install]: se
# ejecuta una vez por despliegue, no se habilita para el arranque del
# sistema (hallazgo de la revisión independiente de doc-reviewer sobre
# 0.9b — "enable" sobre esta unidad es un no-op sin efecto, y habilitarla
# sería semánticamente incorrecto: no debe correr en cada reinicio).
echo "  $SYSTEMCTL start plataforma-migrate.service"
echo "  $SYSTEMCTL enable --now api@1.service web.service traefik.service"
