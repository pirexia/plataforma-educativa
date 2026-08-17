#!/bin/sh
# Base de datos separada para tests (ADR-033 §10), en el mismo clúster y con
# los mismos roles que la de desarrollo (los roles son del clúster, no de
# una base concreta). Igual que 01-tenancy.sh: automático en volumen nuevo,
# aplicar a mano una vez sobre un volumen existente (ver SYSADMIN.md).
set -eu

: "${TENANCY_OWNER_PASSWORD:?falta TENANCY_OWNER_PASSWORD}"
: "${TENANCY_APP_PASSWORD:?falta TENANCY_APP_PASSWORD}"
: "${TENANCY_PLATFORM_PASSWORD:?falta TENANCY_PLATFORM_PASSWORD}"

TEST_DB="${POSTGRES_TEST_DB:-${POSTGRES_DB}_test}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -v test_db="$TEST_DB" \
    -v owner="$POSTGRES_USER" \
    <<'SQL'
SELECT format('CREATE DATABASE %I OWNER %I', :'test_db', :'owner')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'test_db') \gexec
SQL

# Mismo esquema/función/roles/permisos que la base de desarrollo, aplicados
# ahora sobre la base de test (los roles ya existen a nivel de clúster).
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$TEST_DB" \
    -v owner_password="$TENANCY_OWNER_PASSWORD" \
    -v app_password="$TENANCY_APP_PASSWORD" \
    -v platform_password="$TENANCY_PLATFORM_PASSWORD" \
    -v dbname="$TEST_DB" \
    -f "$(dirname "$0")/01-tenancy.sql"
