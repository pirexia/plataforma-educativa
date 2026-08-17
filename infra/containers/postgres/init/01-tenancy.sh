#!/bin/sh
# Ejecutado automáticamente por la imagen oficial de postgres en
# docker-entrypoint-initdb.d, solo en la inicialización de un volumen
# nuevo. Para un volumen ya existente, ver "Provisión de tenancy" en
# SYSADMIN.md para aplicarlo a mano con este mismo script.
set -eu

: "${TENANCY_OWNER_PASSWORD:?falta TENANCY_OWNER_PASSWORD}"
: "${TENANCY_APP_PASSWORD:?falta TENANCY_APP_PASSWORD}"
: "${TENANCY_PLATFORM_PASSWORD:?falta TENANCY_PLATFORM_PASSWORD}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -v owner_password="$TENANCY_OWNER_PASSWORD" \
    -v app_password="$TENANCY_APP_PASSWORD" \
    -v platform_password="$TENANCY_PLATFORM_PASSWORD" \
    -v dbname="$POSTGRES_DB" \
    -f "$(dirname "$0")/01-tenancy.sql"
