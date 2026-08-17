#!/bin/sh
# Ejecutado automáticamente por la imagen oficial de postgres en
# docker-entrypoint-initdb.d, solo en la inicialización de un volumen
# nuevo. Para un volumen ya existente, ver "Provisión de tenancy" en
# SYSADMIN.md para aplicarlo a mano con este mismo script.
#
# El SQL vive en 01-tenancy.sql.tpl, no en 01-tenancy.sql: la imagen
# oficial de postgres recorre docker-entrypoint-initdb.d/ y procesa
# CUALQUIER *.sql suelto por su cuenta (con psql -f y solo las
# credenciales por defecto, sin los -v de abajo) además de ejecutar este
# script — con extensión .sql, el fichero se ejecutaba dos veces: una
# vez bien (desde aquí) y otra sin las variables owner_password/
# app_password/platform_password/dbname, dejando esos ALTER ROLE con
# valores vacíos o abortando la inicialización entera. Hallazgo de
# db-reviewer antes de mezclar 0.7, corregido con el cambio de extensión.
set -eu

: "${TENANCY_OWNER_PASSWORD:?falta TENANCY_OWNER_PASSWORD}"
: "${TENANCY_APP_PASSWORD:?falta TENANCY_APP_PASSWORD}"
: "${TENANCY_PLATFORM_PASSWORD:?falta TENANCY_PLATFORM_PASSWORD}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -v owner_password="$TENANCY_OWNER_PASSWORD" \
    -v app_password="$TENANCY_APP_PASSWORD" \
    -v platform_password="$TENANCY_PLATFORM_PASSWORD" \
    -v dbname="$POSTGRES_DB" \
    -f "$(dirname "$0")/01-tenancy.sql.tpl"
