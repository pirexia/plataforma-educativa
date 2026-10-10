#!/bin/sh
# Bases de datos de la suite de tests en paralelo (ADR-060 §4.1).
#
# Uso:   bases-test-paralelo.sh N [--recrear]
#
# Crea `plataforma_test_1 .. plataforma_test_N` como clones de la plantilla
# `plataforma_test` (CREATE DATABASE ... TEMPLATE) y reaplica a cada una el
# `GRANT CONNECT` a los tres roles de tenancy (CREATE DATABASE ... TEMPLATE
# no copia los privilegios de base). Cada proceso de Pest usa la suya
# (tests/bootstrap.php, TEST_TOKEN); migra su propia base en el primer
# setUp(), así que el esquema del clon no necesita estar al día.
#
# - Sin --recrear: crea las que falten y reaplica el GRANT a todas; no toca
#   las que existen (idempotente).
# - Con --recrear: borra y vuelve a clonar las N (tras editar en sitio una
#   migración no publicada, ADR-058 §5, o si una base queda sucia).
# - Clonar exige que la plantilla no tenga conexiones abiertas: si las tiene,
#   falla con un mensaje propio (en CI, parar `artisan serve` o ejecutar este
#   script antes de arrancarlo).
#
# Se ejecuta con el superusuario del clúster. Desarrollo, desde el host (nada
# se instala en el host, CLAUDE.md §9):
#   podman exec -i plataforma-postgres sh -s -- 6 < infra/containers/postgres/bases-test-paralelo.sh
# CI: con el `psql` del job, con PGHOST/PGPORT/PGUSER/PGPASSWORD en el entorno.
set -eu

uso() {
    echo "Uso: $0 N [--recrear]   (N = número de procesos de test, 1 a 13)" >&2
    exit 2
}

[ "$#" -ge 1 ] && [ "$#" -le 2 ] || uso
N="$1"
RECREAR=0
if [ "$#" -eq 2 ]; then
    [ "$2" = "--recrear" ] || uso
    RECREAR=1
fi

case "$N" in
    ''|*[!0-9]*) uso ;;
esac
# Con 16 bases Redis (0-15), REDIS_CACHE_DB = 2 + N cabe hasta N = 13.
if [ "$N" -lt 1 ] || [ "$N" -gt 13 ]; then
    echo "N debe estar entre 1 y 13 (límite de bases Redis, ADR-060 §4.1.3)." >&2
    exit 2
fi

SUPERUSER="${POSTGRES_USER:-${PGUSER:-plataforma}}"
TEMPLATE_DB="${POSTGRES_TEST_DB:-plataforma_test}"

# Conexión de mantenimiento a `postgres`, nunca a la plantilla: una conexión
# propia a la plantilla impediría clonarla.
sql() {
    psql -X -q -v ON_ERROR_STOP=1 --username "$SUPERUSER" --dbname postgres -At "$@"
}

if [ "$(sql -c "SELECT 1 FROM pg_database WHERE datname = '$TEMPLATE_DB'")" != "1" ]; then
    echo "No existe la plantilla '$TEMPLATE_DB'. Créala primero (02-tenancy-test-db.sh, SYSADMIN.md §2b)." >&2
    exit 1
fi

# Qué clones hay que crear.
PENDIENTES=""
i=1
while [ "$i" -le "$N" ]; do
    DB="${TEMPLATE_DB}_$i"
    EXISTE="$(sql -c "SELECT 1 FROM pg_database WHERE datname = '$DB'")"
    if [ "$RECREAR" -eq 1 ] || [ "$EXISTE" != "1" ]; then
        PENDIENTES="$PENDIENTES $i"
    fi
    i=$((i + 1))
done

if [ -n "$PENDIENTES" ]; then
    CONEXIONES="$(sql -c "SELECT count(*) FROM pg_stat_activity WHERE datname = '$TEMPLATE_DB' AND pid <> pg_backend_pid()")"
    if [ "$CONEXIONES" != "0" ]; then
        echo "La plantilla '$TEMPLATE_DB' tiene $CONEXIONES conexión(es) abierta(s) y PostgreSQL no clona una base en uso." >&2
        echo "Cierra lo que esté conectado (p. ej. 'artisan serve' contra ella) y vuelve a ejecutar este script." >&2
        exit 1
    fi
fi

for i in $PENDIENTES; do
    DB="${TEMPLATE_DB}_$i"
    if [ "$RECREAR" -eq 1 ]; then
        sql -c "DROP DATABASE IF EXISTS \"$DB\" WITH (FORCE)"
    fi
    sql -c "CREATE DATABASE \"$DB\" TEMPLATE \"$TEMPLATE_DB\" OWNER \"$SUPERUSER\""
    echo "Creada $DB"
done

# GRANT CONNECT en todas (idempotente), incluidas las que ya existían.
i=1
while [ "$i" -le "$N" ]; do
    DB="${TEMPLATE_DB}_$i"
    sql -c "GRANT CONNECT ON DATABASE \"$DB\" TO plataforma_owner, plataforma_app, plataforma_platform"
    i=$((i + 1))
done

echo "Bases ${TEMPLATE_DB}_1..${TEMPLATE_DB}_$N listas."
