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

set -euo pipefail

TAG="${1:?Uso: install.sh <tag> [--user]}"
USER_MODE="${2:-}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_DIR="$REPO_ROOT/infra/quadlet"

if [[ "$USER_MODE" == "--user" ]]; then
    DEST_DIR="$HOME/.config/containers/systemd"
    SYSTEMCTL="systemctl --user"
else
    DEST_DIR="/etc/containers/systemd"
    SYSTEMCTL="sudo systemctl"
fi

echo "Instalando unidades Quadlet en $DEST_DIR con tag=$TAG"
mkdir -p "$DEST_DIR"

for unit in "$SOURCE_DIR"/*.container "$SOURCE_DIR"/*.network "$SOURCE_DIR"/*.volume; do
    name="$(basename "$unit")"
    # __TAG__ es el único punto de variación entre despliegues dentro de
    # un fichero por lo demás literal (ADR-037 §6.1) — no hay sistema de
    # plantillado que mantener, solo esta sustitución.
    sed "s/__TAG__/$TAG/g" "$unit" > "$DEST_DIR/$name"
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
