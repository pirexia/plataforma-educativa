#!/usr/bin/env bash
# Funciones de install.sh (ADR-080 §3.5), en fichero aparte para poder
# probarlas sin systemctl ni copiar unidades (infra/test-install-env.sh).
# Se carga con `source`; no ejecuta nada por sí mismo. Cada función
# escribe el error en stderr y devuelve 1, sin imprimir valores sensibles.

ACME_CA_STAGING="https://acme-staging-v02.api.letsencrypt.org/directory"
ACME_CA_PRODUCTION="https://acme-v02.api.letsencrypt.org/directory"
# Imagen de Traefik definida en UN solo sitio (Image= y ExecStartPre de
# traefik.container).
TRAEFIK_IMAGE="docker.io/library/traefik:v3.7.19"

# Lectura simple de valores del env file, sin exportarlo entero. Una clave
# ausente devuelve cadena vacía (no aborta con pipefail).
env_value() {
    { grep -E "^${1}=" "$ENV_FILE" || true; } | tail -n1 | cut -d= -f2-
}

# Lee y valida TENANCY_BASE_DOMAIN, BACKOFFICE_HOST, BACKOFFICE_ALLOWED_IPS,
# ACME_EMAIL y ACME_CA_SERVER (con valor por defecto: entorno de pruebas).
load_and_validate_config() {
    local host_re='^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$'
    local email_re='^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'

    TENANCY_BASE_DOMAIN="$(env_value TENANCY_BASE_DOMAIN)"
    BACKOFFICE_HOST="$(env_value BACKOFFICE_HOST)"
    BACKOFFICE_ALLOWED_IPS="$(env_value BACKOFFICE_ALLOWED_IPS)"
    ACME_EMAIL="$(env_value ACME_EMAIL)"
    ACME_CA_SERVER="$(env_value ACME_CA_SERVER)"
    ACME_CA_SERVER="${ACME_CA_SERVER:-$ACME_CA_STAGING}"

    if [[ -z "$TENANCY_BASE_DOMAIN" || -z "$BACKOFFICE_HOST" ]]; then
        echo "TENANCY_BASE_DOMAIN y BACKOFFICE_HOST tienen que estar fijados en $ENV_FILE" >&2
        echo "(operacion.md §0.4, RN-BO-49: BACKOFFICE_HOST nunca puede ser subdominio de TENANCY_BASE_DOMAIN)." >&2
        return 1
    fi
    if [[ ! "$TENANCY_BASE_DOMAIN" =~ $host_re ]]; then
        echo "TENANCY_BASE_DOMAIN de $ENV_FILE no es un nombre de dominio válido (minúsculas, dígitos, puntos y guiones)." >&2
        return 1
    fi
    if [[ ! "$BACKOFFICE_HOST" =~ $host_re ]]; then
        echo "BACKOFFICE_HOST de $ENV_FILE no es un nombre de dominio válido (minúsculas, dígitos, puntos y guiones)." >&2
        return 1
    fi
    if [[ -z "$ACME_EMAIL" ]]; then
        echo "ACME_EMAIL tiene que estar fijado en $ENV_FILE (email de la cuenta ACME, ADR-080 §3.5)." >&2
        return 1
    fi
    if [[ ! "$ACME_EMAIL" =~ $email_re ]]; then
        echo "ACME_EMAIL de $ENV_FILE no tiene formato de correo válido." >&2
        return 1
    fi
    if [[ "$ACME_CA_SERVER" != "$ACME_CA_STAGING" && "$ACME_CA_SERVER" != "$ACME_CA_PRODUCTION" ]]; then
        echo "ACME_CA_SERVER de $ENV_FILE no está permitido: solo $ACME_CA_STAGING o $ACME_CA_PRODUCTION." >&2
        return 1
    fi
    return 0
}

# Fichero del token de Cloudflare: existe, permisos exactos 600 y propietario
# el usuario que ejecuta el script. Nunca se lee ni se imprime su contenido.
check_token_file() {
    local info mode owner
    if [[ ! -f "$TRAEFIK_ENV_FILE" ]]; then
        echo "No existe $TRAEFIK_ENV_FILE (plantilla: infra/quadlet/plataforma-traefik.env.example); Traefik no podría emitir el certificado." >&2
        return 1
    fi
    info="$(stat -L -c '%a %U' "$TRAEFIK_ENV_FILE")"
    mode="${info%% *}"
    owner="${info#* }"
    if [[ "$mode" != "600" ]]; then
        echo "$TRAEFIK_ENV_FILE debe tener permisos 600 (contiene un token): chmod 600 $TRAEFIK_ENV_FILE" >&2
        return 1
    fi
    if [[ "$owner" != "$(id -un)" ]]; then
        echo "$TRAEFIK_ENV_FILE debe pertenecer a $(id -un) (el usuario que ejecuta el instalador), no a $owner." >&2
        return 1
    fi
    return 0
}

# Aviso (no error) si se instala una versión de producción (X.Y.Z) con el
# servidor ACME de pruebas.
warn_acme_staging_in_production() {
    if [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ && "$ACME_CA_SERVER" == "$ACME_CA_STAGING" ]]; then
        echo "AVISO: tag de producción ($1) con el servidor ACME de PRUEBAS: los certificados no serán de confianza. Fija ACME_CA_SERVER en $ENV_FILE." >&2
    fi
    return 0
}
