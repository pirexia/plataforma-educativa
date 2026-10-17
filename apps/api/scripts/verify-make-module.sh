#!/bin/sh
# ADR-056 §3.5, CA-056-34 y CA-056-35: prueba de extremo a extremo del
# generador `php artisan make:module`. Genera un módulo de prueba, comprueba
# que lo generado cumple las reglas y LO DESCARTA, pase lo que pase.
#
# Uso (desde apps/api, con la base de test accesible):
#   sh scripts/verify-make-module.sh minimal   # sin --entity
#   sh scripts/verify-make-module.sh entity    # con --entity y --audit-policy
#
# Es la única prueba de que lo generado cumple (§3.5): un test unitario con
# ficheros de referencia comprobaría el texto, no el resultado.

set -eu

VARIANT="${1:?uso: verify-make-module.sh minimal|entity}"
NAME="CiProbe"
CODE="ci_probe"
export DB_DATABASE="${DB_DATABASE:-plataforma_test}"

case "$VARIANT" in
    minimal) ENTITY_ARGS="" ;;
    entity) ENTITY_ARGS="--entity=Aula --audit-policy=full" ;;
    *) echo "variante desconocida: $VARIANT" >&2; exit 2 ;;
esac

if [ -e "app/Modules/$NAME" ] || [ -e "tests/Feature/$NAME" ]; then
    echo "::error::ya existe un módulo $NAME: no se sobrescribe" >&2
    exit 2
fi

discard() {
    status=$?
    set +e
    echo "== Descarta lo generado ($VARIANT)"

    if [ -d "app/Modules/$NAME/Database/migrations" ]; then
        php artisan migrate:rollback --database=pgsql_owner --force --path="app/Modules/$NAME/Database/migrations"
    fi

    rm -rf "app/Modules/$NAME" "database/factories/$NAME" "tests/Feature/$NAME" lang/es/$CODE.php lang/en/$CODE.php lang/de/$CODE.php lang/fr/$CODE.php
    sed -i "/Modules\/$NAME\/Http\/routes.php/d" routes/api-v1.php
    sed -i "/^    '$CODE' =>/d" lang/es/modules.php lang/en/modules.php lang/de/modules.php lang/fr/modules.php
    exit "$status"
}
trap discard EXIT

echo "== Migra el estado base (para que la migración generada sea su propio lote)"
php artisan migrate --database=pgsql_owner --force

echo "== Genera ($VARIANT)"
# shellcheck disable=SC2086
php artisan make:module "$NAME" --code="$CODE" --name-es="Sonda de CI" --name-en="CI probe" --name-de="CI-Sonde" --name-fr="Sonde CI" $ENTITY_ARGS

echo "== php -l sobre lo generado y sobre los ficheros compartidos editados"
find "app/Modules/$NAME" "database/factories/$NAME" "tests/Feature/$NAME" -name '*.php' 2>/dev/null | while read -r f; do php -l "$f" >/dev/null || exit 1; done
for f in lang/es/$CODE.php lang/en/$CODE.php lang/de/$CODE.php lang/fr/$CODE.php routes/api-v1.php lang/es/modules.php lang/en/modules.php lang/de/modules.php lang/fr/modules.php; do
    php -l "$f" >/dev/null
done

echo "== migrate y migrate:rollback del módulo"
php artisan migrate --database=pgsql_owner --force
php artisan migrate:rollback --database=pgsql_owner --force --path="app/Modules/$NAME/Database/migrations"
php artisan migrate --database=pgsql_owner --force

echo "== Grupo arch"
php -d memory_limit=-1 vendor/bin/pest --group=arch

echo "== Tests generados"
php -d memory_limit=-1 vendor/bin/pest "tests/Feature/$NAME"

echo "== Larastan (composer analyse)"
composer analyse

echo "== OK ($VARIANT)"
