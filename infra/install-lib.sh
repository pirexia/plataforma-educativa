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
    if [[ -n "$BACKOFFICE_ALLOWED_IPS" ]]; then
        local ip_re='^(([0-9]{1,3}\.){3}[0-9]{1,3}|[0-9A-Fa-f:]*:[0-9A-Fa-f:.]*)(/[0-9]{1,3})?$' entry
        local -a entries
        IFS=',' read -r -a entries <<<"$BACKOFFICE_ALLOWED_IPS"
        for entry in "${entries[@]}"; do
            if [[ ! "$entry" =~ $ip_re ]]; then
                echo "BACKOFFICE_ALLOWED_IPS de $ENV_FILE tiene una entrada que no es IP ni CIDR (separadas por comas, sin espacios)." >&2
                return 1
            fi
        done
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

# Prepara el directorio de init de PostgreSQL con los scripts del repositorio
# (sin secretos: las contraseñas llegan al contenedor por las variables
# TENANCY_*_PASSWORD de plataforma-postgres.env). Idempotente; solo se
# ejecutan en la inicialización de un volumen nuevo.
prepare_postgres_init() {
    local src="$1" dest="$2"
    mkdir -p "$dest"
    cp -f "$src"/* "$dest"/
    chmod 0755 "$dest"/*.sh
    chmod 0644 "$dest"/*.tpl
}

# Renderiza las unidades Quadlet de $1 en $2 sustituyendo los tokens con los
# valores ya cargados (TENANCY_*, ACME_*, BACKOFFICE_*, TRAEFIK_*, ENV_DIR,
# POSTGRES_INIT_DIR) y la versión $3. Único sitio con el `sed`.
render_units() {
    local src="$1" dest="$2" tag="$3" unit name escaped
    escaped="${TENANCY_BASE_DOMAIN//./\\.}"
    mkdir -p "$dest"
    for unit in "$src"/*.container "$src"/*.network "$src"/*.volume; do
        name="$(basename "$unit")"
        # __TAG__ es el punto de variación original (ADR-037 §6.1); el resto
        # son su misma forma, no un sistema de plantillado nuevo.
        sed \
            -e "s/__TAG__/$tag/g" \
            -e "s/__TENANCY_BASE_DOMAIN_ESCAPED__/$escaped/g" \
            -e "s/__TENANCY_BASE_DOMAIN__/$TENANCY_BASE_DOMAIN/g" \
            -e "s|__TRAEFIK_IMAGE__|$TRAEFIK_IMAGE|g" \
            -e "s|__ACME_EMAIL__|$ACME_EMAIL|g" \
            -e "s|__ACME_CA_SERVER__|$ACME_CA_SERVER|g" \
            -e "s|__TRAEFIK_ENV_FILE__|$TRAEFIK_ENV_FILE|g" \
            -e "s|__ENV_DIR__|$ENV_DIR|g" \
            -e "s|__POSTGRES_INIT_DIR__|$POSTGRES_INIT_DIR|g" \
            -e "s/__BACKOFFICE_HOST__/$BACKOFFICE_HOST/g" \
            -e "s|__BACKOFFICE_ALLOWED_IPS__|$BACKOFFICE_ALLOWED_IPS|g" \
            "$unit" > "$dest/$name"
    done
}
