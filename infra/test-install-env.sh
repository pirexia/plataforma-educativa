#!/usr/bin/env bash
# Prueba de shell de las validaciones de install-lib.sh (ADR-080 §3.5, #685).
# Sin systemctl ni unidades reales: usa un directorio temporal.
# Uso: ./infra/test-install-env.sh
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=infra/install-lib.sh
source "$HERE/install-lib.sh"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
FAILS=0

ENV_FILE="$TMP/plataforma.env"
TRAEFIK_ENV_FILE="$TMP/traefik-cloudflare.env"

# base_env <línea extra...>: escribe un plataforma.env válido más las líneas dadas.
base_env() {
    printf '%s\n' "TENANCY_BASE_DOMAIN=adagiox.eu" "BACKOFFICE_HOST=panel.adagiox.com" "TENANT_ALLOWED_IPS=203.0.113.0/24" "$@" > "$ENV_FILE"
}

# expect <nombre> <código esperado 0|1> <patrón stderr o ""> <comando...>
expect() {
    local name="$1" want="$2" pat="$3" out rc
    shift 3
    out="$("$@" 2>&1 >/dev/null)"; rc=$?
    if [[ "$rc" -ne "$want" ]] || { [[ -n "$pat" ]] && ! grep -q -- "$pat" <<<"$out"; }; then
        echo "FALLA  $name (rc=$rc, esperado $want; stderr: $out)"
        FAILS=$((FAILS + 1))
    else
        echo "ok     $name"
    fi
}

validate() { ( set -euo pipefail; load_and_validate_config ); }
validate_val() { ( load_and_validate_config && echo "$ACME_CA_SERVER" ) 2>&1; }

base_env "ACME_EMAIL=ops@example.com"
expect "clave ACME_CA_SERVER ausente no aborta" 0 "" validate
got="$(validate_val)"
[[ "$got" == "$ACME_CA_STAGING" ]] && echo "ok     clave ausente usa el servidor de pruebas por defecto" \
    || { echo "FALLA  default de ACME_CA_SERVER: $got"; FAILS=$((FAILS + 1)); }

base_env "BACKOFFICE_ALLOWED_IPS=" "ACME_EMAIL=ops@example.com"
expect "BACKOFFICE_ALLOWED_IPS vacío no aborta" 0 "" validate

base_env "BACKOFFICE_ALLOWED_IPS=203.0.113.7/32" "ACME_EMAIL=ops@example.com"
expect "BACKOFFICE_ALLOWED_IPS con un CIDR" 0 "" validate
base_env "BACKOFFICE_ALLOWED_IPS=203.0.113.0/24,198.51.100.7/32,2001:db8::/32,198.51.100.9" "ACME_EMAIL=ops@example.com"
expect "BACKOFFICE_ALLOWED_IPS con varias entradas (IPv4, IPv6, sin prefijo)" 0 "" validate
base_env "BACKOFFICE_ALLOWED_IPS=999.999.999.999" "ACME_EMAIL=ops@example.com"
expect "BACKOFFICE_ALLOWED_IPS con octetos > 255 rechazada" 1 "no es IP ni CIDR" validate
base_env "BACKOFFICE_ALLOWED_IPS=203.0.113.0/33" "ACME_EMAIL=ops@example.com"
expect "BACKOFFICE_ALLOWED_IPS IPv4 con prefijo /33 rechazada" 1 "no es IP ni CIDR" validate
base_env "BACKOFFICE_ALLOWED_IPS=2001:db8::/129" "ACME_EMAIL=ops@example.com"
expect "BACKOFFICE_ALLOWED_IPS IPv6 con prefijo /129 rechazada" 1 "no es IP ni CIDR" validate
base_env "BACKOFFICE_ALLOWED_IPS=203.0.113.0/24,198.51.100.7,2001:db8::/32,2001:db8::/128" "ACME_EMAIL=ops@example.com"
expect "BACKOFFICE_ALLOWED_IPS válidas aceptadas" 0 "" validate
base_env "ACME_EMAIL=ops%t@example.com"
expect "ACME_EMAIL con % rechazado" 1 "formato de correo" validate
base_env "BACKOFFICE_ALLOWED_IPS=203.0.113.0/24|rm" "ACME_EMAIL=ops@example.com"
expect "BACKOFFICE_ALLOWED_IPS con | rechazada" 1 "no es IP ni CIDR" validate
base_env "BACKOFFICE_ALLOWED_IPS=203.0.113.0/24 &" "ACME_EMAIL=ops@example.com"
expect "BACKOFFICE_ALLOWED_IPS con espacio y & rechazada" 1 "no es IP ni CIDR" validate
base_env "BACKOFFICE_ALLOWED_IPS=host.example.com" "ACME_EMAIL=ops@example.com"
expect "BACKOFFICE_ALLOWED_IPS con un nombre rechazada" 1 "no es IP ni CIDR" validate

# Validador de listas de IP endurecido (#693).
for v in ":" "::::" "a:b" "1.2.3.4," ",1.2.3.4" "1.2.3.4,,5.6.7.8" "1:2:3:4:5:6:7:8:9" "1::2::3" "12345::1" ":1:2:3:4:5:6:7" "1.2.3" "1.2.3.4/"; do
    base_env "TENANT_ALLOWED_IPS=$v" "ACME_EMAIL=ops@example.com"
    expect "TENANT_ALLOWED_IPS '$v' rechazada" 1 "no es IP ni CIDR" validate
done
for v in "::/0" "2001:db8::/32" "::1/128" "203.0.113.0/24,2001:db8::/32" "0.0.0.0/0,::/0" "1:2:3:4:5:6:7:8" "::ffff:203.0.113.7"; do
    base_env "TENANT_ALLOWED_IPS=$v" "ACME_EMAIL=ops@example.com"
    expect "TENANT_ALLOWED_IPS '$v' aceptada" 0 "" validate
done
base_env "BACKOFFICE_ALLOWED_IPS=1.2.3.4," "ACME_EMAIL=ops@example.com"
expect "BACKOFFICE_ALLOWED_IPS con coma final rechazada" 1 "no es IP ni CIDR" validate

# TENANT_ALLOWED_IPS (#693, INV-002): obligatoria, cerrada por defecto.
printf '%s\n' "TENANCY_BASE_DOMAIN=adagiox.eu" "BACKOFFICE_HOST=panel.adagiox.com" "ACME_EMAIL=ops@example.com" > "$ENV_FILE"
expect "TENANT_ALLOWED_IPS ausente aborta" 1 "TENANT_ALLOWED_IPS tiene que estar fijado" validate
base_env "TENANT_ALLOWED_IPS=" "ACME_EMAIL=ops@example.com"
expect "TENANT_ALLOWED_IPS vacía aborta" 1 "TENANT_ALLOWED_IPS tiene que estar fijado" validate
base_env "TENANT_ALLOWED_IPS=203.0.113.0/33" "ACME_EMAIL=ops@example.com"
expect "TENANT_ALLOWED_IPS IPv4 con prefijo /33 rechazada" 1 "TENANT_ALLOWED_IPS.*no es IP ni CIDR" validate
base_env "TENANT_ALLOWED_IPS=2001:db8::/129" "ACME_EMAIL=ops@example.com"
expect "TENANT_ALLOWED_IPS IPv6 con prefijo /129 rechazada" 1 "no es IP ni CIDR" validate
base_env "TENANT_ALLOWED_IPS=203.0.113.0/24,host.example.com" "ACME_EMAIL=ops@example.com"
expect "TENANT_ALLOWED_IPS con un nombre rechazada" 1 "no es IP ni CIDR" validate
base_env "TENANT_ALLOWED_IPS=203.0.113.0/24|rm" "ACME_EMAIL=ops@example.com"
expect "TENANT_ALLOWED_IPS con | rechazada" 1 "no es IP ni CIDR" validate
base_env "TENANT_ALLOWED_IPS=0.0.0.0/0,::/0" "ACME_EMAIL=ops@example.com"
expect "TENANT_ALLOWED_IPS abierta explícita (0.0.0.0/0,::/0) aceptada" 0 "" validate
base_env "TENANT_ALLOWED_IPS=203.0.113.0/24,198.51.100.7,2001:db8::/32" "ACME_EMAIL=ops@example.com"
expect "TENANT_ALLOWED_IPS válidas (IPv4, IPv6, sin prefijo) aceptadas" 0 "" validate

base_env
expect "ACME_EMAIL ausente: error claro" 1 "ACME_EMAIL tiene que estar fijado" validate

base_env "ACME_EMAIL=no-es-un-correo"
expect "ACME_EMAIL inválido" 1 "formato de correo" validate

base_env "ACME_EMAIL=ops@example.com" "ACME_CA_SERVER=https://otra.ca.example/directory"
expect "ACME_CA_SERVER fuera de lista" 1 "no está permitido" validate

base_env "ACME_EMAIL=ops@example.com" "ACME_CA_SERVER=$ACME_CA_PRODUCTION"
expect "ACME_CA_SERVER de producción permitido" 0 "" validate

printf '%s\n' "TENANCY_BASE_DOMAIN=Mal;Dominio" "BACKOFFICE_HOST=panel.adagiox.com" "ACME_EMAIL=ops@example.com" > "$ENV_FILE"
expect "dominio base inválido" 1 "TENANCY_BASE_DOMAIN de" validate

printf '%s\n' "TENANCY_BASE_DOMAIN=adagiox.eu" "BACKOFFICE_HOST=a b/c" "ACME_EMAIL=ops@example.com" > "$ENV_FILE"
expect "BACKOFFICE_HOST inválido" 1 "BACKOFFICE_HOST de" validate

# Aviso de staging en producción: no es error.
base_env "ACME_EMAIL=ops@example.com"
load_and_validate_config
expect "aviso ACME de pruebas con tag X.Y.Z (no error)" 0 "AVISO" warn_acme_staging_in_production 1.2.3

# Token.
expect "token ausente" 1 "No existe" check_token_file
echo "CF_DNS_API_TOKEN=valor-de-prueba" > "$TRAEFIK_ENV_FILE"
chmod 644 "$TRAEFIK_ENV_FILE"
expect "token con permisos 644" 1 "permisos 600" check_token_file
chmod 640 "$TRAEFIK_ENV_FILE"
expect "token con permisos 640" 1 "permisos 600" check_token_file
chmod 600 "$TRAEFIK_ENV_FILE"
expect "token 600 y propietario correcto" 0 "" check_token_file

# Propietario distinto: solo comprobable con otro usuario real (root o sudo -n).
if [[ "$(id -u)" -eq 0 ]]; then
    chown nobody "$TRAEFIK_ENV_FILE" 2>/dev/null
    expect "token de otro propietario" 1 "debe pertenecer" check_token_file
elif sudo -n true 2>/dev/null; then
    sudo chown root "$TRAEFIK_ENV_FILE"
    expect "token de otro propietario" 1 "debe pertenecer" check_token_file
else
    # Sin privilegios: se simula con un id -un distinto.
    id() { echo otro-usuario; }
    expect "token de otro propietario (id simulado)" 1 "debe pertenecer" check_token_file
    unset -f id
fi

# Integración: install.sh --user completo con systemctl simulado y una lista CIDR (regresión del
# `sed` con delimitador `/`: la lista blanca documentada en CIDR hacía abortar el instalador).
H="$TMP/home"; mkdir -p "$H/.config/plataforma" "$TMP/bin"
printf '#!/usr/bin/env bash\nexit 0\n' > "$TMP/bin/systemctl"; chmod +x "$TMP/bin/systemctl"
printf '%s\n' "TENANCY_BASE_DOMAIN=centros.adagiox.eu" "BACKOFFICE_HOST=panel.adagiox.eu" \
    "BACKOFFICE_ALLOWED_IPS=203.0.113.7/32,198.51.100.0/24" "TENANT_ALLOWED_IPS=198.51.100.0/24,2001:db8::/32" "ACME_EMAIL=ops@example.com" > "$H/.config/plataforma/plataforma.env"
printf 'CF_DNS_API_TOKEN=x\n' > "$H/.config/plataforma/traefik-cloudflare.env"; chmod 600 "$H/.config/plataforma/traefik-cloudflare.env"
out="$(HOME="$H" PATH="$TMP/bin:$PATH" bash "$HERE/install.sh" sha-test --user 2>&1)"; rc=$?
if [[ "$rc" -eq 0 ]] && grep -q 'sourcerange=203.0.113.7/32,198.51.100.0/24' "$H/.config/containers/systemd/api@.container" \
   && ! grep -rq '__[A-Z_]*__' "$H/.config/containers/systemd/"; then
    echo "ok     install.sh --user con CIDR sustituye la lista y no deja tokens sin sustituir"
else
    echo "FALLA  install.sh --user con CIDR (rc=$rc): $(tail -3 <<<"$out")"; FAILS=$((FAILS + 1))
fi

# #701: Quadlet descarta en silencio (o trunca) un Label= con espacios o con barra invertida.
# (a) Estático: ningún Label= (fuera de comentarios) de infra/quadlet los lleva.
bad=""
while IFS= read -r l; do
    [[ "$l" == Label=* ]] || continue
    [[ "$l" =~ [[:space:]] || "$l" == *'\\'* ]] && bad="$bad [$l]"
done < <(cat "$HERE"/quadlet/*.container)
if [[ -z "$bad" ]]; then echo "ok     ningún Label= de infra/quadlet lleva espacios ni barra invertida (#701)"; else echo "FALLA  Label= con espacio o \\:$bad"; FAILS=$((FAILS + 1)); fi
# (b) Texto exacto de las tres reglas tras renderizar con install.sh --user (dominio de $H).
U="$H/.config/containers/systemd"
bad=""
grep -qxF 'Label=traefik.http.routers.plataforma-web.rule=HostRegexp(`^[a-z0-9-]+[.]centros[.]adagiox[.]eu$`)&&PathPrefix(`/`)' "$U/web.container" || bad="$bad web;"
grep -qxF 'Label=traefik.http.routers.plataforma-api.rule=HostRegexp(`^[a-z0-9-]+[.]centros[.]adagiox[.]eu$`)&&PathPrefix(`/api`)' "$U/api@.container" || bad="$bad api;"
grep -qxF 'Label=traefik.http.routers.plataforma-api-platform.rule=Host(`panel.adagiox.eu`)&&PathPrefix(`/api/platform`)' "$U/api@.container" || bad="$bad api-platform;"
if [[ -z "$bad" ]]; then echo "ok     texto exacto de las tres reglas de los routers renderizadas"; else echo "FALLA  reglas de routers:$bad"; FAILS=$((FAILS + 1)); fi
# (c) Con el generador de Quadlet disponible, la regla debe llegar al ExecStart.
GEN=""
for c in /usr/libexec/podman/quadlet /usr/lib/systemd/system-generators/podman-system-generator "$(command -v quadlet 2>/dev/null)"; do
    [[ -n "$c" && -x "$c" ]] && { GEN="$c"; break; }
done
if [[ -z "$GEN" ]]; then
    echo "omitido: sin generador de Quadlet"
else
    gen_out="$(QUADLET_UNIT_DIRS="$U" "$GEN" --user --dryrun 2>&1)"
    bad=""
    for want in \
        'traefik.http.routers.plataforma-web.rule=HostRegexp(`^[a-z0-9-]+[.]centros[.]adagiox[.]eu$`)&&PathPrefix(`/`)' \
        'traefik.http.routers.plataforma-api.rule=HostRegexp(`^[a-z0-9-]+[.]centros[.]adagiox[.]eu$`)&&PathPrefix(`/api`)' \
        'traefik.http.routers.plataforma-api-platform.rule=Host(`panel.adagiox.eu`)&&PathPrefix(`/api/platform`)'; do
        grep -qF -- "--label $want" <<<"$gen_out" || bad="$bad [${want%%=*}]"
    done
    if [[ -z "$bad" ]]; then echo "ok     generador de Quadlet (--dryrun): las tres reglas llegan al ExecStart"; else echo "FALLA  generador de Quadlet descarta:$bad"; FAILS=$((FAILS + 1)); fi
fi

# #705: SELinux desactivado SOLO en Traefik (provisional hasta 0.10e-d). Estático: solo
# traefik.container lleva SecurityLabelDisable=true (fuera de comentarios).
bad=""
for f in "$HERE"/quadlet/*.container; do
    n="$(basename "$f")"
    if grep -qx 'SecurityLabelDisable=true' "$f"; then
        [[ "$n" == traefik.container ]] || bad="$bad $n lo lleva;"
    else
        [[ "$n" == traefik.container ]] && bad="$bad traefik.container no lo lleva;"
    fi
done
if [[ -z "$bad" ]]; then echo "ok     SecurityLabelDisable=true solo en traefik.container (#705)"; else echo "FALLA  SecurityLabelDisable:$bad"; FAILS=$((FAILS + 1)); fi
# Con el generador: solo el ExecStart de traefik.service lleva label=disable (los comentarios
# de la unidad también salen en --dryrun, por eso se mira solo la línea ExecStart=).
if [[ -n "$GEN" ]]; then
    bad=""; unit=""
    while IFS= read -r l; do
        if [[ "$l" =~ ^---(.*)---$ ]]; then unit="${BASH_REMATCH[1]}"; continue; fi
        [[ "$l" == ExecStart=* ]] || continue
        if [[ "$l" == *'--security-opt label=disable'* ]]; then
            [[ "$unit" == traefik.service ]] || bad="$bad $unit lo lleva;"
        else
            [[ "$unit" == traefik.service ]] && bad="$bad traefik.service no lo lleva;"
        fi
    done <<<"$gen_out"
    if [[ -z "$bad" ]]; then echo "ok     generador de Quadlet: label=disable solo en el ExecStart de Traefik (#705)"; else echo "FALLA  generador label=disable:$bad"; FAILS=$((FAILS + 1)); fi
fi

# #693: install.sh sin TENANT_ALLOWED_IPS aborta; con ella, los routers de los centros llevan
# el middleware y el del backoffice conserva el suyo.
UD="$H/.config/containers/systemd"
bad=""
grep -q 'middlewares\.tenant-ipallowlist\.ipallowlist\.sourcerange=198.51.100.0/24,2001:db8::/32' "$UD/api@.container" || bad="$bad sin sourcerange tenant;"
grep -q 'routers\.plataforma-api\.middlewares=tenant-ipallowlist@docker' "$UD/api@.container" || bad="$bad plataforma-api sin middleware;"
grep -q 'routers\.plataforma-web\.middlewares=tenant-ipallowlist-web@docker' "$UD/web.container" || bad="$bad plataforma-web sin middleware;"
grep -q 'middlewares\.tenant-ipallowlist-web\.ipallowlist\.sourcerange=198.51.100.0/24,2001:db8::/32' "$UD/web.container" || bad="$bad web sin su middleware definido;"
# Ninguna unidad referencia un middleware definido en otra: cada @docker usado se define en la misma unidad.
for f in "$UD"/*.container; do
    for m in $(grep -oE 'middlewares=[a-z-]+@docker' "$f" | sed -E 's/middlewares=([a-z-]+)@docker/\1/'); do
        grep -q "traefik\.http\.middlewares\.$m\." "$f" || bad="$bad $(basename "$f") usa $m sin definirlo;"
    done
done
grep -q 'routers\.plataforma-api-platform\.middlewares=platform-ipallowlist@docker' "$UD/api@.container" || bad="$bad backoffice sin su middleware;"
grep -q 'platform-ipallowlist\.ipallowlist\.sourcerange=203.0.113.7/32,198.51.100.0/24' "$UD/api@.container" || bad="$bad backoffice sin su sourcerange;"
grep -q 'plataforma-api-platform\.middlewares=tenant' "$UD/api@.container" && bad="$bad backoffice con middleware de centros;"
if [[ -z "$bad" ]]; then echo "ok     routers de centros con tenant-ipallowlist y backoffice con el suyo"; else echo "FALLA  middlewares de lista blanca:$bad"; FAILS=$((FAILS + 1)); fi
H3="$TMP/home3"; mkdir -p "$H3/.config"; cp -r "$H/.config/plataforma" "$H3/.config/plataforma"
sed -i '/^TENANT_ALLOWED_IPS=/d' "$H3/.config/plataforma/plataforma.env"
out3="$(HOME="$H3" PATH="$TMP/bin:$PATH" bash "$HERE/install.sh" sha-test --user 2>&1)"; rc3=$?
if [[ "$rc3" -ne 0 ]] && grep -q 'TENANT_ALLOWED_IPS tiene que estar fijado' <<<"$out3" && [[ ! -e "$H3/.config/containers/systemd/web.container" ]]; then
    echo "ok     install.sh sin TENANT_ALLOWED_IPS aborta y no genera unidades"
else
    echo "FALLA  install.sh sin TENANT_ALLOWED_IPS (rc=$rc3): $(tail -2 <<<"$out3")"; FAILS=$((FAILS + 1))
fi

# prepare_postgres_init: destino enlace simbólico, destino escribible, sobrantes.
mkdir -p "$TMP/real"; ln -s "$TMP/real" "$TMP/enlace"
expect "init: destino enlace simbólico rechazado" 1 "enlace simbólico" prepare_postgres_init "$HERE/containers/postgres/init" "$TMP/enlace"
mkdir -p "$TMP/abierto"; chmod 0777 "$TMP/abierto"
expect "init: destino escribible por otros rechazado" 1 "escribible" prepare_postgres_init "$HERE/containers/postgres/init" "$TMP/abierto"
mkdir -p "$TMP/init-ok"; chmod 0755 "$TMP/init-ok"; echo x > "$TMP/init-ok/sobrante.sql"
expect "init: destino propio se prepara" 0 "" prepare_postgres_init "$HERE/containers/postgres/init" "$TMP/init-ok"
if [[ ! -e "$TMP/init-ok/sobrante.sql" && -x "$TMP/init-ok/01-tenancy.sh" && "$(ls "$TMP/init-ok" | wc -l)" -eq 3 ]]; then
    echo "ok     init: sobrantes eliminados y solo los tres ficheros del repositorio"
else
    echo "FALLA  init: sobrantes"; FAILS=$((FAILS + 1))
fi

# Rutas por modo (ADR-080 §3.4): ninguna unidad generada lleva rutas fijas del otro modo.
# check_units <nombre> <directorio> <ENV_DIR esperado> <rutas prohibidas...>
check_units() {
    local name="$1" dir="$2" envdir="$3" bad="" p f
    shift 3
    grep -rq '__[A-Z_]*__' "$dir" && bad="tokens sin sustituir"
    for p in "$@"; do grep -rqF "$p" "$dir" && bad="$bad contiene $p;"; done
    for f in "$dir"/*.container; do
        while IFS= read -r l; do
            [[ "$l" == "EnvironmentFile=$envdir/"* ]] || bad="$bad $(basename "$f"): $l;"
        done < <(grep -E '^EnvironmentFile=' "$f")
    done
    if [[ -z "$bad" ]]; then echo "ok     $name"; else echo "FALLA  $name: $bad"; FAILS=$((FAILS + 1)); fi
}

# Modo --user: tras install.sh real (systemctl simulado).
check_units "unidades de install.sh --user: sin rutas de sistema ni tokens, EnvironmentFile en el directorio del usuario" \
    "$H/.config/containers/systemd" "$H/.config/plataforma" /etc/plataforma /opt/plataforma
if grep -qF "Volume=$H/.local/share/plataforma/postgres-init:" "$H/.config/containers/systemd/postgres.container" \
   && [[ -x "$H/.local/share/plataforma/postgres-init/01-tenancy.sh" && -f "$H/.local/share/plataforma/postgres-init/01-tenancy.sql.tpl" ]] \
   && ! grep -rqE 'PASSWORD=.' "$H/.local/share/plataforma/postgres-init"; then
    echo "ok     directorio de init --user preparado con los scripts y sin contraseñas"
else
    echo "FALLA  directorio de init --user"; FAILS=$((FAILS + 1))
fi
if grep -q 'start plataforma-network.service' <<<"$out" && ! grep -q 'plataforma\.network' <<<"$out"; then
    echo "ok     aviso final con el nombre real de la unidad de red"
else
    echo "FALLA  aviso final de la unidad de red"; FAILS=$((FAILS + 1))
fi

if grep -qE '^Exec=php artisan migrate --database=pgsql_owner --force$' "$H/.config/containers/systemd/plataforma-migrate.container"; then
    echo "ok     plataforma-migrate migra con --database=pgsql_owner"
else
    echo "FALLA  plataforma-migrate sin --database=pgsql_owner"; FAILS=$((FAILS + 1))
fi
# Las plantillas de entorno que arrancan la aplicación llevan las variables de sesión (sin SESSION_DOMAIN).
for f in plataforma plataforma-worker plataforma-worker-long; do
    ex="$HERE/quadlet/$f.env.example"
    if grep -q '^SESSION_DRIVER=database$' "$ex" && grep -q '^SESSION_SECURE_COOKIE=true$' "$ex" \
       && grep -q '^SESSION_LIFETIME=480$' "$ex" && grep -q '^SESSION_ENCRYPT=true$' "$ex" && ! grep -q '^SESSION_DOMAIN' "$ex"; then
        echo "ok     $f.env.example con variables de sesión y sin SESSION_DOMAIN"
    else
        echo "FALLA  $f.env.example: variables de sesión"; FAILS=$((FAILS + 1))
    fi
done

# Red host de Traefik: conserva la IP real del cliente (CS-10); backends solo en 127.0.0.1.
U="$H/.config/containers/systemd"
tr="$U/traefik.container"; bad=""
grep -q '^Network=host$' "$tr" || bad="$bad sin Network=host;"
grep -q 'providers.docker.useBindPortIP=true' "$tr" || bad="$bad sin useBindPortIP;"
grep -q 'entrypoints.traefik.address=127.0.0.1:8080' "$tr" || bad="$bad sin entrypoint 127.0.0.1:8080;"
grep -q '^PublishPort' "$tr" && bad="$bad traefik publica puertos;"
grep -q 'PublishPort=127.0.0.1:1800%i:8000' "$U/api@.container" || bad="$bad api@ sin publicar en 127.0.0.1;"
grep -q 'PublishPort=127.0.0.1:18080:80' "$U/web.container" || bad="$bad web sin publicar en 127.0.0.1;"
for f in "$U"/*.container; do
    while IFS= read -r l; do
        [[ "$l" == PublishPort=127.0.0.1:* ]] || bad="$bad $(basename "$f"): $l;"
    done < <(grep -E '^PublishPort=' "$f")
done
if [[ -z "$bad" ]]; then echo "ok     Traefik en red host y backends publicados solo en 127.0.0.1"; else echo "FALLA  red host:$bad"; FAILS=$((FAILS + 1)); fi

# Mailpit opcional (staging): sin --with-mailpit no se renderiza ni se anuncia; con la opción sí.
if [[ ! -e "$U/mailpit.container" ]] && ! grep -q 'mailpit' <<<"$out"; then
    echo "ok     sin --with-mailpit no se instala ni se anuncia mailpit"
else
    echo "FALLA  mailpit aparece sin --with-mailpit"; FAILS=$((FAILS + 1))
fi
H2="$TMP/home2"; mkdir -p "$H2/.config"; cp -r "$H/.config/plataforma" "$H2/.config/plataforma"
out2="$(HOME="$H2" PATH="$TMP/bin:$PATH" bash "$HERE/install.sh" sha-test --user --with-mailpit 2>&1)"; rc2=$?
M="$H2/.config/containers/systemd/mailpit.container"; bad=""
[[ "$rc2" -eq 0 ]] || bad="$bad rc=$rc2;"
[[ -f "$M" ]] || bad="$bad sin mailpit.container;"
grep -q 'start mailpit.service' <<<"$out2" || bad="$bad sin start mailpit;"
[[ "$(grep -c '^PublishPort=' "$M")" -eq 1 ]] && grep -q '^PublishPort=127.0.0.1:8025:8025$' "$M" || bad="$bad PublishPort;"
grep -q 'PublishPort=.*1025' "$M" && bad="$bad publica SMTP;"
grep -q '^Network=plataforma.network$' "$M" || bad="$bad sin red;"
grep -qE '^(Requires|BindsTo)=' "$M" && bad="$bad Requires/BindsTo;"
grep -qE '^Image=.+:v[0-9]+\.[0-9]+\.[0-9]+@sha256:[0-9a-f]{64}$' "$M" || bad="$bad imagen sin versión y digest;"
grep -q '__[A-Z_]*__' "$M" && bad="$bad tokens;"
if [[ -z "$bad" ]]; then echo "ok     --with-mailpit instala mailpit (127.0.0.1:8025, sin publicar SMTP, sin tokens)"; else echo "FALLA  --with-mailpit:$bad"; FAILS=$((FAILS + 1)); fi
# Ayuda de arranque (#695, #698): sin enable --now ni start de migrate; con restart de migrate.
bad=""
grep -q 'enable --now' <<<"$out2" && bad="$bad enable --now;"
grep -qE '(^|[^e])start plataforma-migrate' <<<"$out2" && bad="$bad start plataforma-migrate;"
grep -q 'restart plataforma-migrate' <<<"$out2" || bad="$bad sin restart plataforma-migrate;"
if [[ -z "$bad" ]]; then echo "ok     la ayuda de arranque usa restart de migrate y start (sin enable --now)"; else echo "FALLA  ayuda de arranque:$bad"; FAILS=$((FAILS + 1)); fi
# Reinstalar sin la opción no borra una unidad mailpit ya instalada.
HOME="$H2" PATH="$TMP/bin:$PATH" bash "$HERE/install.sh" sha-test --user >/dev/null 2>&1
[[ -f "$M" ]] && echo "ok     reinstalar sin --with-mailpit conserva la unidad existente" \
    || { echo "FALLA  reinstalar sin la opción borró mailpit"; FAILS=$((FAILS + 1)); }
expect "install.sh rechaza opciones desconocidas" 1 "Opción desconocida" env HOME="$H2" bash "$HERE/install.sh" sha-test --nada

# Modo sistema: render_units a un directorio temporal, sin escribir en /etc ni /opt.
( ENV_FILE="/etc/plataforma/plataforma.env"; TRAEFIK_ENV_FILE="/etc/plataforma/traefik-cloudflare.env"
  ENV_DIR="/etc/plataforma"; POSTGRES_INIT_DIR="/opt/plataforma/postgres-init"
  TENANCY_BASE_DOMAIN=centros.adagiox.eu; BACKOFFICE_HOST=panel.adagiox.eu; BACKOFFICE_ALLOWED_IPS=10.0.0.0/8; TENANT_ALLOWED_IPS=0.0.0.0/0,::/0
  ACME_EMAIL=ops@example.com; ACME_CA_SERVER="$ACME_CA_STAGING"
  render_units "$HERE/quadlet" "$TMP/sistema" sha-test )
check_units "unidades en modo sistema: EnvironmentFile en /etc/plataforma, sin tokens ni rutas del usuario" \
    "$TMP/sistema" /etc/plataforma "$H"
grep -qF "Volume=/opt/plataforma/postgres-init:" "$TMP/sistema/postgres.container" \
    && echo "ok     modo sistema usa /opt/plataforma/postgres-init" \
    || { echo "FALLA  init en modo sistema"; FAILS=$((FAILS + 1)); }

echo "---"
if [[ "$FAILS" -eq 0 ]]; then echo "OK: todas las comprobaciones pasan"; else echo "FALLAN $FAILS comprobaciones"; exit 1; fi
