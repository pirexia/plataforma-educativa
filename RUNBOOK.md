# RUNBOOK.md

> **Versión 0.3.29** · 2026-11-16
> Documento vivo: se actualiza en cada fase (`CLAUDE.md` §6). Cubre el entorno de **desarrollo** en WSL2 (`ADR-030`) y, desde `§3b.9`, el de **staging** en un VPS (con datos sintéticos) — no hay producción, piloto ni usuarios reales todavía. Los procedimientos de guardia, alertas y recuperación ante desastre de un entorno real se documentarán aquí cuando `OPEN-11` (alojamiento del piloto) se resuelva.

---

## 1. Clasificación de incidencias

Tabla completa y vinculante: `CLAUDE.md` §5. Resumen:

| Severidad | Acción |
|-----------|--------|
| Crítica (fuga entre tenants, exposición de datos personales, pérdida de datos, caída total, incumplimiento legal) | Issue en GitHub, **parar el trabajo en curso**, resolver de inmediato |
| Alta (fallo funcional que impide usar un módulo, vulnerabilidad explotable, migración destructiva) | Issue y resolución en la misma sesión |
| Media (rodeo posible, deuda que crecerá) | Issue y resolución en la misma sesión si no descarrila el objetivo |
| Baja (mejora, cosmético) | Issue documentado, sin resolver hasta que se pida |

## 2. Procedimientos de desarrollo (WSL2)

Referencia completa de arranque, `compose.yaml` y red: `SYSADMIN.md`.

### 2.1 Arrancar/parar el entorno

```bash
podman compose up -d          # perfil reducido: postgres, redis, api, web
podman compose --profile full up -d   # añade garage (S3 de desarrollo, ADR-076; después `bash infra/garage/init.sh`) y el worker de colas
podman compose --profile worker up -d worker   # solo el worker de colas (§2.8)
podman compose ps
podman compose logs -f <servicio>
```

**Nunca** `podman compose down` salvo en un entorno completamente desechable — borra la red externa `plataforma-net` y rompe la resolución de nombres entre servicios (`ADR-028`, `SYSADMIN.md` §1.3).

### 2.2 Si un contenedor no arranca o no queda `healthy`

1. `podman compose logs <servicio>` primero, no adivinar.
2. Comprobar que la red `plataforma-net` existe: `podman network ls`.
3. Ver `docs/historial/0.7-nucleo-multitenant.md` y `docs/historial/0.8-modelo-de-datos-nucleo.md` — catálogo de bugs de entorno ya encontrados y su solución (deadlocks de test entre conexiones, `.env`/`storage/` ausentes en un *worktree* nuevo, purga agresiva de paquetes `-dev` en el `Containerfile`, etc.). No repetir un diagnóstico ya hecho.
4. **`api` sano un momento y `500`/`unhealthy` al siguiente, sin haber tocado nada del servicio a propósito** (issue [#62](https://github.com/pirexia/plataforma-educativa/issues/62), 2026-08-22): revisar `apps/api/.env` — `SESSION_LIFETIME` debe ser `>= AUTH_SESSION_TIMEOUT_MAX_MINUTES` (`SYSADMIN.md §2c`) desde `REQ-AUTH` (1.2). El código se interpreta por petición (sin *build*), así que un `git pull`/*merge* que traiga 1.2 sin ese valor ajustado tumba el contenedor en la siguiente petición. Si `podman rm`/`--force-recreate` falla con *"has dependent containers"* (acoplamiento `web` → `api` vía `depends_on`), no forzar más: `podman-compose down && podman-compose up -d` recrea la pila completa sin tocar la red externa (`external: true`) ni el volumen `postgres-cluster`.
5. **Login por navegador da `404` o `419`, aunque la API responda bien por `curl`** (issue [#71](https://github.com/pirexia/plataforma-educativa/issues/71), 2026-08-25): entra por `http://demo.plataforma.test:5173/`, no por `localhost:5173` — la SPA y la API deben compartir host (aunque no puerto) para que la cookie `XSRF-TOKEN` sea legible desde la página (`SYSADMIN.md §2c`, fila `VITE_API_URL`). Requiere `127.0.0.1 demo.plataforma.test` en el `hosts` de **Windows** (no el de la distribución WSL2).
6. **`plataforma-postgres` sale con error al arrancar tras actualizar a `postgres:18`** (`ADR-061`): el *entrypoint* de 18 se niega a arrancar si encuentra datos antiguos (`PG_VERSION`) en `/var/lib/postgresql` o en `/var/lib/postgresql/data`, o si `/var/lib/postgresql/data` es un punto de montaje aunque esté vacío (mensaje `unused mount/volume`; el caso habitual que cita es actualizar la imagen sin `pg_upgrade`). Causa: se montó el volumen de 17 (o el montaje antiguo `.../data`) con la imagen 18. El fallo es seguro, no toca los datos. Solución: no forzar nada; montar el volumen nuevo `postgres-cluster` en `/var/lib/postgresql` (`compose.yaml` de la rama actual) y seguir `§2.7`. Un error de `runc` con enlace simbólico al montar sobre `.../data` tiene el mismo origen (`docker-library/postgres#1370`, `#1377`).
7. Para cualquier otro síntoma: skill `depuracion` (método de diagnóstico y catálogo de fallos característicos del stack).

### 2.3 Si CI falla en un PR

- Revisar el check concreto (`ci-api.yml`, `ci-web.yml`, `dependency-scan.yml`) antes de reintentar sin más.
- **Checks requeridos (verificados el 2026-11-18 con la API de GitHub, `gh api repos/.../rules/branches/<rama>`)**: la protección está como *rulesets* (migrados desde la protección clásica por el usuario) y exige, en `develop`, 11 checks: `E2E real (API)`, `Tests (Pest)`, `Lint (Pint)`, `Análisis estático (Larastan)`, `Lint (ESLint)`, `Typecheck y build (vue-tsc + Vite)`, `Tests unitarios (Vitest)`, `Tests e2e (Playwright)`, `Trivy (composer.lock, package-lock.json)`, `Construir plataforma-api` y `Construir plataforma-web`; en `staging` y `main`, los mismos **sin Trivy** (Trivy solo corre en `pull_request` y el commit promocionado por avance rápido no lo tiene). No son requeridos, a propósito, los jobs de `ci-landing.yml` (filtro `paths:`: un *check* requerido que no se dispara bloquea el PR para siempre), `Exigir CI en verde antes de publicar una versión`, `Generador de módulos (make:module)` ni `Conformidad del almacén (Garage)`. Antes del 2026-11-18 el único requerido era `E2E real (API)`, y lo que aquí se decía de «ocho checks required desde 0.7» no era cierto. Excepción de administrador (`RepositoryRole/5`, modo `always`) y *Require branches to be up to date* desactivado (`strict=false`). Sin verificar: si estos checks frenan el *push* de avance rápido a `staging` y `main` (lo dirá la primera promoción).

### 2.4 Si `db-reviewer` o `security-reviewer` encuentran un hallazgo Crítico o Alto

Parar el merge. Documentar el hallazgo como issue de GitHub con severidad, ficheros implicados y propuesta de solución (`CLAUDE.md` §5). Un hallazgo Alta se corrige en la misma sesión antes de mezclar; uno Crítico detiene además cualquier otro trabajo en curso.

### 2.5 Si `failed_jobs` crece sin parar pese a que la purga está programada (`REQ-BO`, `1.6d`)

Comando de diagnóstico rápido, desde dentro del contenedor `api` (`docs/modulos/REQ-BO/operacion.md §6.2`): comprobar que **`bo:purge-failed-jobs`** está en la lista del planificador y **`queue:prune-failed`** ya no lo está (`php artisan schedule:list`). Si sigue apareciendo el comando del framework, el despliegue no tiene el código de `1.6d` — ese comando lleva desde `0.7` sin poder borrar ni una fila (apunta a la conexión `plataforma_app`, que tiene `REVOKE DELETE` sobre `failed_jobs`), y mientras esté programado la retención de 24 horas del issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73) es solo una promesa documental, no un hecho. Purga manual de emergencia (por `pgsql_platform`, nunca por la conexión de aplicación): `DELETE FROM failed_jobs WHERE failed_at < now() - interval '24 hours';`.

### 2.6 Si una escritura de roles o usuarios responde `409 administration_capacity_lost` (`REQ-PERM`, `1.5b`, `RN-PERM-47`)

Síntoma: al retirar permisos a un rol, asignar o quitar roles, desactivar o dar de baja a un usuario, el centro recibe `409` con `core.validation.administration_capacity_lost` y la lista de permisos afectados (en el `detail` y en `errors.administration_capacity[0].params.codes`). **No es un fallo**: la escritura dejaría al centro sin ningún usuario activo con la capacidad completa de administración y no se ha guardado nada. Resolución: conceder antes esos permisos a otra persona activa del centro y repetir la operación. Si el centro **ya** no cumplía antes de la escritura, la regla no la rechaza. Detalle: `docs/modulos/REQ-PERM/operacion.md`.

### 2.7 Subida de versión mayor de PostgreSQL (`ADR-061`: 17 → 18)

Método: `pg_dump` de la base de aplicación con el cliente de la versión nueva y `pg_restore --create` sobre un clúster nuevo con volumen nuevo. El volumen antiguo **no se toca**: es la vuelta atrás. Patrón para la próxima subida mayor mientras no haya otro ADR. **Nunca** `podman compose down`, `podman rm -v` ni `podman volume rm` (`ADR-028`). El volcado contiene *hashes* de contraseñas (y en producción datos personales): va fuera del repositorio, con permisos `700`/`600`.

```bash
# 0. Preparación (inventario.sql: la consulta de inventario de ADR-061 §4.2, paso 0)
DIR="$HOME/plataforma-respaldos/pg17-a-pg18-$(date +%Y%m%d-%H%M)"
mkdir -p "$DIR" && chmod 700 "$DIR"
PGPW="$(podman exec plataforma-postgres printenv POSTGRES_PASSWORD)"
podman pull docker.io/library/postgres:18
# crear "$DIR/inventario.sql" con la consulta de inventario de ADR-061 §4.2

# 1. Parar a los clientes de la base (no la base)
podman stop plataforma-web plataforma-api

# 2. Inventario en 17 y volcados con el cliente de 18
podman exec -i plataforma-postgres psql -U plataforma -d plataforma -At -v ON_ERROR_STOP=1 \
  < "$DIR/inventario.sql" > "$DIR/inventario-antes.txt"
podman run --rm --network plataforma-net -e PGPASSWORD="$PGPW" docker.io/library/postgres:18 \
  pg_dump -h plataforma-postgres -U plataforma -d plataforma -Fc > "$DIR/plataforma.dump"
podman run --rm --network plataforma-net -e PGPASSWORD="$PGPW" docker.io/library/postgres:18 \
  pg_dumpall -h plataforma-postgres -U plataforma --exclude-database='plataforma_test*' \
  > "$DIR/respaldo-completo-pg17.sql"
chmod 600 "$DIR"/*
# El volcado custom se puede leer (si falla, parar aquí: nada se ha tocado todavía)
podman run --rm -i docker.io/library/postgres:18 pg_restore --list < "$DIR/plataforma.dump" | tail -n 3

# 3. Retirar los contenedores (sin -v: los volúmenes se quedan)
podman stop plataforma-postgres
podman rm plataforma-web plataforma-api plataforma-postgres

# 4. Arrancar 18 sobre el volumen nuevo (01-tenancy.sh y 02-tenancy-test-db.sh crean roles y bases)
podman compose up -d postgres
timeout 180 sh -c 'until podman healthcheck run plataforma-postgres >/dev/null 2>&1; do sleep 2; done'
podman logs plataforma-postgres 2>&1 | grep -E 'PostgreSQL init process complete|ERROR|FATAL'

# 5. Sustituir la `plataforma` recién creada por la restaurada
podman exec plataforma-postgres dropdb -U plataforma plataforma
podman exec -i plataforma-postgres pg_restore -U plataforma --create --exit-on-error -d postgres \
  < "$DIR/plataforma.dump"

# 6. Comprobaciones (CA-061-03 a CA-061-06): el diff debe salir vacío
podman exec -i plataforma-postgres psql -U plataforma -d plataforma -At -v ON_ERROR_STOP=1 \
  < "$DIR/inventario.sql" > "$DIR/inventario-despues.txt"
diff "$DIR/inventario-antes.txt" "$DIR/inventario-despues.txt" && echo "inventario idéntico"
podman exec plataforma-postgres psql -U plataforma -d plataforma -At \
  -c 'SHOW server_version' -c 'SHOW data_checksums' \
  -c "SELECT count(*) FROM pg_authid WHERE rolpassword LIKE 'md5%'"

# 7. Bases de test por proceso (ADR-060): el clúster es nuevo, se crean de cero
podman exec -i plataforma-postgres sh -s -- 6 < infra/containers/postgres/bases-test-paralelo.sh

# 8. Volver a levantar el resto
podman compose up -d
podman exec plataforma-api php artisan migrate:status | grep -c Pending   # debe dar 0
```

Después, `composer test` (suite completa, `ADR-060`). Si el paso 5 o el 6 fallan, no seguir: aplicar la reversión de abajo. El volumen nuevo queda para diagnóstico; repetir desde el paso 3 borrándolo exige autorización expresa del usuario (`podman volume rm plataforma-educativa_postgres-cluster`).

**Prueba de la reversión** (`CA-061-08`), sin tocar el contenedor de 18:

```bash
podman run -d --rm --name plataforma-pg17-reversion \
  -v plataforma-educativa_postgres-data:/var/lib/postgresql/data:Z docker.io/library/postgres:17
timeout 120 sh -c 'until podman exec plataforma-pg17-reversion pg_isready -U plataforma >/dev/null 2>&1; do sleep 2; done'
podman exec -i plataforma-pg17-reversion psql -U plataforma -d plataforma -At -v ON_ERROR_STOP=1 \
  < "$DIR/inventario.sql" > "$DIR/inventario-reversion.txt"
diff "$DIR/inventario-antes.txt" "$DIR/inventario-reversion.txt" && echo "volumen 17 utilizable"
podman stop plataforma-pg17-reversion
```

**Reversión en desarrollo** (`ADR-061 §8.1`): `podman stop` y `podman rm` de `plataforma-web`, `plataforma-api` y `plataforma-postgres` (sin `-v`), volver a la rama con la definición de 17 y `podman compose up -d`: arranca 17 sobre `postgres-data`, intacto. Lo escrito sobre 18 se pierde (datos sintéticos). Después de mezclar, mediante un PR que restaure imagen, montaje y volumen, con un ADR que sustituya a `ADR-061`. En CI: volver a `postgres:17` en los tres servicios y revertir `CA-058-02`.

**Conservación**: el volumen `plataforma-educativa_postgres-data` y el respaldo `$DIR` de la migración de desarrollo se borraron el 2026-10-17 por decisión del usuario, tras una semana trabajando sobre 18; la reversión en desarrollo descrita arriba ya no es posible en ese entorno (el procedimiento sigue valiendo para un entorno que conserve su volumen de 17). El volumen antiguo de prodlike (`plataforma-prodlike_prodlike-postgres-data`) es desechable; su borrado también se deja al usuario.

**Producción (Quadlet)**: hoy no aplica (sin producción ni datos reales, `§4`); la primera instalación nacerá en 18 con `postgres-cluster` vacío. Para un host con un clúster 17 con datos, **condición previa**: ensayar entero, reversión incluida, en `compose.prodlike.yaml` con datos sintéticos (`ADR-030`) y anotar el resultado en `SYSADMIN.md`.

1. Ventana de mantenimiento: `systemctl stop api@1.service web.service traefik.service worker.service worker-long.service`.
2. Inventario y volcados con el cliente de 18, como en los pasos 0 y 2 de arriba. El volcado contiene datos personales reales: se trata como copia de seguridad (cifrado, fuera del host de aplicación cuando `REQ-BKP`/`OPEN-10` lo permitan, borrado al cerrar la ventana de reversión) y nunca se lleva a desarrollo (`ADR-030`).
3. `systemctl stop postgres.service`; `./infra/install.sh <tag con 18>`; `systemctl daemon-reload`; `systemctl start postgres.service` (inicialización sobre `postgres-cluster` con los *scripts* de `/opt/plataforma/postgres-init`).
4. `dropdb` + `pg_restore --create --exit-on-error` + inventario + `diff` + comprobaciones, como en los pasos 5 y 6.
5. Decidir continuar o volver **antes** de reabrir el tráfico. Continuar: `systemctl start api@1.service web.service traefik.service worker.service worker-long.service` y prueba de humo. Volver: `systemctl stop postgres.service`, `./infra/install.sh <tag anterior>`, `systemctl daemon-reload`, `systemctl start postgres.service` (17 sobre el volumen antiguo, intacto), `systemctl start api@1.service web.service traefik.service worker.service worker-long.service`. **Después de reabrir el tráfico**, volver a 17 pierde lo escrito en 18.

---

### 2.8 Si los correos o los trabajos en cola no se procesan (issue #128)

La cola es el driver `database` de Laravel y la consume el *worker* (`queue:work`, **no** Horizon: `OPEN-BO-23`, `CLAUDE.md §1`). Síntoma: filas que se acumulan en `jobs` (correos de MFA, invitaciones, restablecimiento de contraseña sin llegar).

```bash
podman exec plataforma-worker php artisan core:queue-health   # salida 1 y líneas STALE = hay trabajos sin tomar
podman ps --filter name=plataforma-worker                     # ¿existe y está (healthy)?
podman logs --tail 50 plataforma-worker
podman compose --profile worker up -d worker                  # desarrollo: arrancarlo
systemctl --user status worker.service                        # servidor
```

`core:queue-health` falla si hay un trabajo disponible sin tomar desde hace 5 minutos o más (`CORE_QUEUE_HEALTH_MAX_AGE_MINUTES`, tabla de variables en `SYSADMIN.md §2c`; el servicio y su perfil en `SYSADMIN.md §2`); **ignora los trabajos reservados (en curso)**, así que un trabajo colgado dentro de un *worker* vivo no la dispara (se acota con `--timeout`/`retry_after`); es también el `HealthCmd` del contenedor y vale como comprobación de monitorización. **`--queues=a,b`** (revisión de seguridad B3 de `1.12b`) limita la comprobación a las colas de un proceso: `worker` y `worker-long` leen la misma tabla `jobs`, así que cada `HealthCmd` pasa las suyas (`worker`: las nueve de su `--queue`; `worker-long`: `long`); sin la opción mira todas, y a mano conviene indicarla (`php artisan core:queue-health --queues=long`). Causas habituales: el contenedor del *worker* no está levantado (en desarrollo vive en el perfil `worker`, no arranca con `podman compose up -d` a secas); la base de datos estuvo caída y el proceso sigue en espera; un trabajo de más de 120 s (`--timeout`) que se mata y se reintenta (`retry_after` 180 s). El *worker* termina solo cada hora (`--max-time=3600`) y `Restart=always` lo relanza con el código vigente; tras una migración que cambie el tipo de una columna, reiniciarlo (`SYSADMIN.md` §0 «Pendiente»). Los trabajos fallidos van a `failed_jobs` (§2.5) y los reintenta `REQ-BO`, no `queue:retry`.


**Copia del curso siguiente atascada (`1.12b`).** La copia y la reversión del *rollover* van por la cola `long`, que consume **otro** proceso: `worker-long` (`podman compose --profile worker up -d worker-long`; `systemctl --user status worker-long.service`). Síntoma: una copia en «En cola» (`pendiente`) o «En curso» durante más de unos minutos. Comprobar (1) que `worker-long` existe y está `(healthy)`; (2) si el trabajo sigue en `jobs` (cola `long`) o está en `failed_jobs` (el *worker* era de una versión anterior y no conocía la clase): `podman exec plataforma-api php artisan tinker --execute='echo DB::table("jobs")->where("queue","long")->count();'`. Con un *worker* correcto, un trabajo que sigue en `jobs` se procesa solo. Si está en `failed_jobs` o no está en ninguna parte, **no se reencola** (`queue:retry` está prohibido para estos trabajos: un intento, sin reentrega): pasados 30 minutos desde el último cambio, «Cancelar copia» en la pantalla de la copia, o desde la consola `php artisan curso:rollover-reset <public_id> --tenant=<slug>` (con `--force` se salta la espera, tras confirmación, y queda un aviso en el registro); después se pide la copia de nuevo. **No** se cambia el estado de la copia con SQL. Mientras la copia siga viva, el curso destino no se puede activar (`409 rollover_in_progress`). Detalle: `docs/modulos/REQ-CURSO/operacion.md §7`.
## 3. Guardias (on-call)

**No aplica todavía.** No hay entorno de producción, no hay usuarios reales, no hay SLA que cumplir. Esta sección se escribe cuando exista alojamiento del piloto (`OPEN-11`) y el primer centro real.

## 3b. Despliegue y reversión (`ADR-037`)

Escrito, y probado **en parte** en WSL2 — ver `SYSADMIN.md §6` para el detalle exacto de qué está verificado y qué no. La topología completa (red, cinco servicios, imágenes `prod` reales) y las tres pruebas de resiliencia obligatorias se ejecutaron de verdad con `compose.prodlike.yaml` (`SYSADMIN.md §6.3`). El ciclo de vida por unidades Quadlet reales (`install.sh`, `systemctl --user`) **no se ha podido ejecutar en este host concreto**: `~/.config/containers` pertenece a `root`, un problema de permisos preexistente sin relación con este paso (`SYSADMIN.md §6.2`) — se corrige con un `sudo chown` que esta sesión no puede ejecutar sin contraseña interactiva.

**Rutas por modo de `install.sh` (`ADR-080 §3.4`).** `install.sh` sustituye en las unidades las rutas que dependen del modo, así que ninguna lleva rutas del otro modo escritas a mano:

| | Modo sistema (`install.sh <tag>`) | Modo usuario (`install.sh <tag> --user`) |
|---|---|---|
| Unidades | `/etc/containers/systemd` | `~/.config/containers/systemd` |
| Ficheros de entorno (`plataforma.env`, `plataforma-postgres.env`, `plataforma-worker.env`, `plataforma-worker-long.env`, `traefik-cloudflare.env`) | `/etc/plataforma` | `~/.config/plataforma` |
| Scripts de inicialización de PostgreSQL | `/opt/plataforma/postgres-init` | `~/.local/share/plataforma/postgres-init` |

El directorio de inicialización de PostgreSQL lo deja preparado `install.sh` en cada ejecución, copiando `infra/containers/postgres/init/` (ejecutable `01-tenancy.sh` y `02-tenancy-test-db.sh`, más `01-tenancy.sql.tpl`). **No contiene secretos**: las contraseñas llegan al contenedor por las variables `TENANCY_*_PASSWORD` de `plataforma-postgres.env`. Esos scripts solo se ejecutan al inicializar un volumen nuevo. El nombre real de la unidad de red generada por Quadlet es `plataforma-network.service` (no `plataforma.network`): `systemctl [--user] start plataforma-network.service` (`enable` falla con unidades generadas, #695). Lo comprobado: la validación en seco de ambos generadores y la prueba de shell `infra/test-install-env.sh`; el arranque real de las unidades en modo usuario se prueba en el servidor de staging.

**Prerrequisitos del modo `--user`.** Traefik monta el socket de Podman del usuario (`%t/podman/podman.sock`), que **no existe** hasta activarlo: `systemctl --user enable --now podman.socket`. Sin él `traefik.service` falla con código 125 (**comprobado a mano en el VPS de staging, 2026-11-16**; el instalador no lo activa ni hay prueba automatizada). Además, `loginctl enable-linger <usuario>` para que las unidades de usuario sigan vivas sin sesión (`ADR-080 §3.4`); **tampoco lo aplica `install.sh`**: es un paso manual del preparado del *host*.

**`plataforma_test` también existe en staging.** `02-tenancy-test-db.sh` crea la base `plataforma_test` en **cualquier** entorno, también en *staging* (comprobado con `\l`). Es una consecuencia conocida y aceptada de momento (staging no tiene datos reales, `CLAUDE.md §9`); no se ha decidido eliminarla fuera de desarrollo y debe quedar fuera de cualquier copia o restauración que se trate como datos de producción.

**Traefik en red host (se aparta de `ADR-080 §3.7` y de `ADR-037`; recogida en `ADR-088`, estado `PROPUESTA` a 2026-11-16; otra desviación de `ADR-037 §6.1`, «ficheros literales, sin sistema de plantillado», recogida en ese mismo ADR (§2.3): `install.sh` sustituye ya doce tokens —rutas, imagen de Traefik, dominio, listas de IP— con un mismo `sed`, no un sistema de plantillado nuevo, pero sí más de la única sustitución de `__TAG__` que `ADR-037` admitía).** Con Podman rootless y una red *bridge*, la IP de origen se enmascara (llega como `10.89.10.x`): la lista blanca de IP del *backoffice* (`ipallowlist`) y la IP de auditoría dejarían de funcionar (riesgo B1 de `docs/modulos/REQ-FAM-UNIT/operacion.md §13.3` y `CS-10` de `ADR-080`). Por eso `traefik.container` usa `Network=host` (enlaza 80 y 443 directamente; requiere `sysctl net.ipv4.ip_unprivileged_port_start=80`, que `install.sh` **no** configura: hay que dejarlo persistente a mano, p. ej. `echo 'net.ipv4.ip_unprivileged_port_start=80' | sudo tee /etc/sysctl.d/99-ports.conf && sudo sysctl --system`), sin `PublishPort`, con `--providers.docker.useBindPortIP=true` y el *entrypoint* interno del *ping* en `127.0.0.1:8080`. `api@` publica en `127.0.0.1:1800<instancia>` (la fórmula solo vale para las instancias 1 a 9; la segunda réplica es `0.10e-d`) y `web` en `127.0.0.1:18080`: los *backends* solo son accesibles desde el propio *host*. El *peer* que ve la API sigue siendo una dirección de la red de contenedores (`10.89.10.x` vía *rootlessport*), así que `TRUSTED_PROXIES` por defecto (`10.89.10.0/24`, `SYSADMIN.md`) sigue siendo válida **con esta exposición local** (ver los supuestos de abajo) y no se cambia. Hasta que `ADR-088` se acepte, las reglas de redes de `ADR-028` se aplican al resto de servicios. **Supuestos y riesgos aceptados de la red host.** (a) *Staging* es un *host* de **un solo usuario/operador**: los *backends* escuchan en `127.0.0.1` (18001 API, 18080 web) y cualquier proceso local con acceso al *host* puede llamarlos saltándose Traefik y su lista blanca; la lista blanca de la **aplicación** (`platform_ip_allowlist`) tampoco lo evita, porque el *peer* que ve Laravel (`10.89.10.x` vía *rootlessport*) está en `TRUSTED_PROXIES` por defecto y acepta `X-Forwarded-For`. Es una limitación abierta, tratada en `ADR-088 §5.1` y `§6` (confiar solo en la IP concreta que presenta *rootlessport*, un secreto compartido o un *socket* Unix). (b) `net.ipv4.ip_unprivileged_port_start=80` permite que cualquier usuario local enlace 80/443: si Traefik cae o se reinicia, un proceso local podría ocuparlos antes; es un supuesto de *host* mono-usuario, tratado en `ADR-088 §5.2` (alternativa: redirección con `nftables` a puertos altos). (c) El puerto interno `127.0.0.1:8080` (*ping*) no debe abrirse en ninguna regla de cortafuegos de entrada. (d) Con el *socket* de Podman montado en Traefik y red host, un Traefik comprometido controla el Podman de ese usuario y todos los servicios de *loopback*; lo resuelve el proxy de *socket* de `0.10e-d`.

- **`install.sh` aborta con «TENANT_ALLOWED_IPS tiene que estar fijado»** (#693): falta la lista blanca de IP de las rutas de los centros en `plataforma.env` (está cerrada por defecto, `INV-002`); ocurre sobre todo al actualizar un `plataforma.env` ya existente creado antes de #693. Fijarla en CIDR separados por comas (staging: las IP permitidas; producción: `0.0.0.0/0,::/0` de forma explícita) y repetir `install.sh`; si dice que una entrada «no es IP ni CIDR», corregir esa entrada. Sin probar aún con Traefik real.

**Comprobado a mano en el VPS de *staging*** (no con una prueba automatizada): la lista blanca pasa con una IP permitida y bloquea (403) con otra.

**Migraciones.** `plataforma-migrate.container` ejecuta `php artisan migrate --database=pgsql_owner --force`: se aplican con el rol propietario (`plataforma_owner`), no con `plataforma_app`.

### 3b.1 Generar el fichero de secretos

`ADR-037 §7.2`: se genera, nunca se escribe a mano. **Dos ficheros, no uno** (issue #36, hallazgo de la revisión independiente de security-reviewer sobre 0.9b): `postgres.container` no debe ver `APP_KEY` ni las contraseñas de conexión de Laravel, así que tiene su propio fichero más pequeño.

```bash
# Producción/staging real (systemd de sistema):
sudo install -d -m 0755 /etc/plataforma
sudo install -m 0600 -o root -g root infra/quadlet/plataforma.env.example /etc/plataforma/plataforma.env
sudo install -m 0600 -o root -g root infra/quadlet/plataforma-postgres.env.example /etc/plataforma/plataforma-postgres.env
# Rellenar cada valor vacío con:
openssl rand -base64 32
# APP_KEY tiene su propio generador — no uses openssl para esta:
php artisan key:generate --show

# Prueba en WSL2 (systemd --user), ruta equivalente:
install -d -m 0755 ~/.config/plataforma
install -m 0600 infra/quadlet/plataforma.env.example ~/.config/plataforma/plataforma.env
install -m 0600 infra/quadlet/plataforma-postgres.env.example ~/.config/plataforma/plataforma-postgres.env
```

**Los pares `TENANCY_*_PASSWORD`/`DB_*_PASSWORD` no son secretos independientes — son el mismo valor visto por dos consumidores, en dos ficheros distintos** (el script de aprovisionamiento de PostgreSQL crea el rol con `TENANCY_APP_PASSWORD` en `plataforma-postgres.env`; Laravel se conecta con `DB_PASSWORD` en `plataforma.env`; tienen que coincidir carácter a carácter entre los dos ficheros). Genera **una vez** por rol y copia el mismo valor al nombre de variable correspondiente en cada fichero — tres llamadas a `openssl rand`, no seis:

```bash
APP_PW=$(openssl rand -base64 32)       # TENANCY_APP_PASSWORD y DB_PASSWORD
OWNER_PW=$(openssl rand -base64 32)     # TENANCY_OWNER_PASSWORD y DB_OWNER_PASSWORD
PLATFORM_PW=$(openssl rand -base64 32)  # TENANCY_PLATFORM_PASSWORD y DB_PLATFORM_PASSWORD
```

Bug propio encontrado probando este procedimiento (0.9b.5, `compose.prodlike.yaml`): la primera versión de `plataforma.env.example` no tenía `DB_CONNECTION`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` ni los pares `DB_OWNER_*`/`DB_PLATFORM_*` — sin `DB_CONNECTION=pgsql`, Laravel cae a SQLite por defecto (`config/database.php`), y la imagen `prod` no lleva ni `database.sqlite` ni `.env`, así que el fallo habría sido un arranque roto, no una degradación silenciosa — pero sigue siendo un despliegue que no funciona. Corregido en la plantilla.

`APP_KEY` se copia además a un sitio distinto de la copia de la base de datos (`ADR-037 §7.2` punto 4, obligatorio antes de `0.10d`) — sin ella, los datos de categoría especial cifrados son irrecuperables aunque la base de datos se restaure.

**Desde `REQ-AUTH-003` (1.3), perder `APP_KEY` tiene además esta consecuencia sobre el MFA** (`docs/modulos/REQ-AUTH/operacion.md §C.2.2`, literal):

> Perder `APP_KEY`, o restaurar una copia de la base de datos con una clave distinta, **inutiliza todos los factores TOTP del sistema a la vez**. Nadie con MFA puede entrar. Hay que restablecer el MFA de todo el mundo a mano — y quien tiene que hacerlo es un administrador cuyo rol también exige MFA, así que **tampoco puede entrar**. La salida es intervención directa sobre la base de datos.

No hay salida por la aplicación: es intervención directa sobre la base de datos, no un procedimiento que este runbook pueda automatizar.

**Matiz de `REQ-AUTH-003` (1.3b, `docs/modulos/REQ-AUTH/operacion.md §D.10`):** en este mismo escenario, **un usuario con factor de correo activado sí puede entrar** — su verificación no depende de `APP_KEY` (el código se compara por hash SHA-256, no se descifra). No convierte el escenario en recuperable por sí solo (depende de que ese usuario tenga el correo activado y de que el correo transaccional funcione), pero puede ser la diferencia entre "nadie entra" y "un administrador con correo activado entra y restablece el MFA de los demás" antes de recurrir a la intervención directa sobre la base de datos.

### 3b.2 Desplegar una versión

**Paso 0, una sola vez por base de datos (`REQ-CURSO`, `1.10`, `ADR-057 §5.2`)**: la migración `2026_10_07_100200_create_academic_year_write_guard_function` crea `app.assert_academic_year_writable()` en el esquema `app` y el rol propietario solo tiene `USAGE` sobre él. Antes de la primera migración que la incluya, como superusuario sobre la base de la aplicación: `GRANT CREATE ON SCHEMA app TO <rol propietario>;` (en desarrollo `plataforma_owner`: `podman exec -i plataforma-postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c 'GRANT CREATE ON SCHEMA app TO plataforma_owner'`). Es idempotente. Si se omite, `migrate` aborta con un mensaje que contiene este mismo comando, sin tocar nada. Detalle en `SYSADMIN.md`.

```bash
./infra/install.sh <tag>              # producción/staging real, systemd de sistema
./infra/install.sh <tag> --user       # WSL2, systemd de usuario
```

`<tag>` es siempre una versión exacta (`X.Y.Z` en producción, `sha-<7>` en *staging*; la etiqueta móvil `develop` deja de usarse para desplegar en *staging*, `ADR-080 §4.5`) — nunca `latest` (`ADR-037 §5.2`). El script sustituye el *tag* en las unidades y recarga systemd; no arranca nada por sí solo. Después: `systemctl --user daemon-reload`; `systemctl --user restart plataforma-migrate.service` y comprobar `systemctl --user show plataforma-migrate.service -p Result --value` = `success` antes de reiniciar API y *workers*; el resto de unidades generadas, con `start` (no `enable --now`, #695). Es `restart` y no `start` porque `plataforma-migrate` es `oneshot` con `RemainAfterExit`: ya activa, `start` no ejecuta el contenedor y las migraciones del tag nuevo no correrían (#698). En sistema, sin `--user`.

**Escrito, no ejecutado de extremo a extremo en este host** (`SYSADMIN.md §6.2`): `install.sh --user` requiere escribir en `~/.config/containers/systemd/`, bloqueado por el propietario incorrecto del directorio. La lógica que sí se ha ejercido — sustitución de `__TAG__`, generación de unidades systemd válidas a partir de estos ficheros — está verificada por separado (`SYSADMIN.md §6.1`); lo que falta es la instalación automática en este directorio concreto, no el contenido de las unidades.

**Paso 0b, una sola vez por base de datos (`1.11-a`, endurecimiento de `TEMP`)**: `REVOKE TEMPORARY ON DATABASE … FROM PUBLIC` y `GRANT TEMPORARY … TO plataforma_owner` sobre la base de la aplicación, procedimiento y comprobación en `SYSADMIN.md §2` («Endurecimiento de `TEMP`»). El orden respecto al despliegue no es crítico: el código nuevo funciona con y sin el `REVOKE`, y el código anterior funciona con él. Un volumen nuevo ya lo recibe de `01-tenancy.sql.tpl`.

### 3b.3 Reversión

Mismo mecanismo que el despliegue: bajar el *tag* a la versión anterior y reiniciar la unidad.

```bash
./infra/install.sh <tag-anterior> --user
systemctl --user daemon-reload
systemctl --user restart api@1.service web.service traefik.service worker.service worker-long.service
```

**Módulo `acad` (`1.11-b`)**: se revierte con la imagen anterior; sus migraciones **no** se revierten (aditivas; la versión anterior no usa las tablas y su `down()` borraría datos de estructura). El siguiente `platform:sync-registry` de la versión anterior marca `acad` y sus permisos con `retired_at` (concesiones inertes). Detalle en `SYSADMIN.md §2g` y `docs/modulos/REQ-ACAD/operacion.md §9`.

**Períodos lectivos y validación del cambio de fechas (`1.11d-a`, `acad` + `curso`)**: la reversión es **conjunta** (un solo cambio de imagen) y también es «imagen anterior, migraciones sin revertir» (`REQ-ACAD/operacion.md §11.6`, `REQ-CURSO/operacion.md §9.2`):

1. `./infra/install.sh <tag-anterior> --user` y reiniciar `api@1.service web.service traefik.service worker.service worker-long.service`, como en el resto de pasos.
2. **No se ejecuta `migrate:rollback`**: la tabla `academic_periods` es aditiva y la versión anterior no la usa; su `down()` (`DROP TABLE`) borraría períodos configurados por los centros.
3. Efectos de volver atrás: el `PATCH` de fechas de un curso deja de validar los períodos (los cambios hechos así pueden dejar períodos fuera de las fechas del curso, hay que revisarlos al volver a la versión nueva), `acad` deja de exigir `configuracion_academica` y `sync-registry` de la versión anterior marca sus cuatro códigos con `retired_at` (concesiones inertes); `acad:grant-structure-permissions` vuelve a sembrar solo los cuatro de `estructura_academica`. Una etapa con períodos podría borrarse (lógicamente) sin la regla `RN-ACAD-61`: sin daño de integridad, los períodos quedan huérfanos y la versión nueva los lee igualmente (la relación con la etapa usa `withTrashed()`).
4. Al **volver a la versión nueva**: ejecutar `php artisan migrate --database=pgsql_owner --force` (las migraciones de este proyecto se aplican con la conexión propietaria; sin ella `TenantMigration` aborta con «debe ejecutarse dentro de una transacción de pgsql_owner» y la tabla puede quedar a medias: en ese caso, si está vacía, `DROP TABLE academic_periods` y repetir) y `php artisan acad:grant-structure-permissions` para recuperar las ocho concesiones.

**Horarios y asignación docente (`1.12-a`, `acad`)**: también «imagen anterior, migraciones sin revertir» (`REQ-ACAD/operacion.md §12.5`).

1. `./infra/install.sh <tag-anterior> --user` y reiniciar `api@1.service web.service traefik.service worker.service worker-long.service`.
2. **No se ejecuta `migrate:rollback`**: las siete tablas nuevas (`facilities`, `timetables`, `teaching_assignments`, `group_tutors`, `timetable_frames`, `time_slots`, `timetable_sessions`) y los dos únicos de `group_subjects` y `subgroups` son aditivos; la versión anterior no los usa y su `down()` borraría el horario de los centros.
3. Efectos de volver atrás: `sync-registry` de la versión anterior marca los permisos de `asignacion_docente`, `espacios` y `horarios` con `retired_at` (concesiones inertes); `RN-ACAD-96` deja de aplicarse, así que se podría borrar (lógicamente) un grupo, un subgrupo, una asignatura o una etapa con tutores, asignaciones o marcos vivos: huérfanos lógicos sin daño de integridad (FK `NO ACTION`, borrado lógico), que la versión nueva lee igualmente (las relaciones usan `withTrashed()`).
4. Al **volver a la versión nueva**: `php artisan migrate --database=pgsql_owner --force`, `php artisan platform:sync-registry` y `php artisan acad:grant-structure-permissions` para recuperar las concesiones.

**Marcos especiales y reservas (`1.12-b`, `acad`)**: igual, imagen anterior sin `migrate:rollback` (la tabla `facility_bookings` es aditiva y su `down()` borraría las reservas del centro; `REQ-ACAD/operacion.md §12.5 bis`). Con la versión anterior las rutas y los permisos de `reservas` desaparecen o quedan inertes, y un marco especial ya creado se trata como ordinario en la detección de conflictos (solo rechaza de más). Al volver a la versión nueva: `migrate --database=pgsql_owner --force`, `platform:sync-registry` y `acad:grant-structure-permissions`. **No probado** contra una imagen anterior real en esta entrega (solo razonado: la tabla es aditiva y la versión anterior no la lee).

**Unidades familiares, vínculos y revocación (`1.14-a`, `fam_unit`)**: imagen anterior **sin** `migrate:rollback` (las cuatro tablas son aditivas y su `down()` borraría las familias y las revocaciones del centro; `REQ-FAM-UNIT/operacion.md §8`). Efectos de volver atrás: las rutas y los permisos de `fam_unit` desaparecen o quedan inertes (`sync-registry` de la versión anterior los marca con `retired_at`) y **ninguna interfaz de pertenencia familiar existe**, así que ningún módulo puede excluir a un tutor revocado; no hay todavía ningún módulo que entregue menores (`1.14b`), pero no se debe reutilizar la imagen anterior si ese paso ya está desplegado. Al volver a la versión nueva: `php artisan migrate --database=pgsql_owner --force`, `php artisan platform:sync-registry` y `php artisan fam_unit:grant-permissions`. **No probado** sobre un servidor (`OPEN-11`).

**Consentimiento de imagen y textos legales (`1.14-b`, `fam_unit`)**: igual, imagen anterior **sin** `migrate:rollback` (`image_consent_decisions` y `terms_versions` son evidencia del consentimiento; `REQ-FAM-UNIT/operacion.md §9`). Con la versión anterior no existe `ImageConsentPolicy` (aún no hay publicadores) y los permisos nuevos quedan inertes tras el `sync-registry`; el `kind image_consents` de `data_exports` no lo usa nadie. Al volver a la versión nueva: `migrate --database=pgsql_owner --force`, `platform:sync-registry` y `fam_unit:grant-permissions`. Antes de usar el consentimiento hay que **publicar el texto de cada nivel** o todo el alumnado queda `pendiente`. **No probado** sobre un servidor (`OPEN-11`).

**Pantallas de personal de `fam_unit` (`1.14-d`)**: sin cambios de despliegue, solo SPA; reversión = desplegar la versión anterior de la web (sin migraciones que deshacer).

**Condiciones de uso y muro de condiciones (`1.14-c`, `fam_unit`)**: imagen anterior **sin** `migrate:rollback` sobre `terms_acceptances` (`down()` falla con filas, es evidencia legal de qué texto aceptó cada tutor y su reversión exige aprobación expresa; `REQ-FAM-UNIT/operacion.md §10`). Al volver a la versión nueva: `migrate --database=pgsql_owner --force`, `platform:sync-registry` y `fam_unit:grant-permissions` (concede `aceptacion_condiciones.leer`). Sin variables de entorno, servicios ni colas nuevos. **No probado** sobre un servidor (`OPEN-11`).

**Puerta y entrega de menores (`1.14b-b`, `prl`)**: imagen anterior **sin** `migrate:rollback` sobre `student_movements` ni `pickup_alerts` (`down()` falla con filas: son evidencia de a quién se entregó a un menor y de los incidentes). Efecto de volver atrás: desaparecen la puerta, los movimientos y las alertas de la API; **mientras dure, el centro no puede apoyarse en el sistema para entregar menores** y aplica su procedimiento de contingencia (`OPEN-FAM-UNIT-41`: sin modo sin conexión ni listados impresos con fotografías). Señales a vigilar: errores `422 prl.movement.person_not_authorized` (posible intento de recogida indebida o lista desactualizada), alertas `persona_no_autorizada` por día y `429` con `Retry-After` en la puerta (cupo de lectura de la lista de `fam_unit` o de `prl`, `REQ-PRL/operacion.md §12.1`): un conserje que agota el cupo en plena hora de salida necesita que se le restablezca (`RateLimiter::clear`) o que se suba el valor en `apps/api/config/prl.php`/`apps/api/config/fam_unit.php`.

**Bloqueos de la puerta y restricciones de `prl` (`1.14b-d1`)**: imagen anterior; la migración **puede** revertirse (`down()` no se niega con datos y devuelve `GRANT DELETE`; `CA-PRL-034`), pero no hace falta: el código anterior funciona con el esquema nuevo. Señales nuevas a vigilar (`REQ-PRL/operacion.md §12.5` y `§12.6b`): respuestas `503 prl.errors.busy` en la puerta (una transacción ajena retiene un bloqueo más de `prl.lock_timeout_ms`, 3 s; el conserje reintenta), reintentos por interbloqueo y posibles `500` por interbloqueo en escrituras de `fam_unit` (limitación B-1: el orden de bloqueo no es compartido, `fam_unit` no reintenta; un reintento manual lo resuelve). Si los `503` no son casi cero, hay una transacción larga en `fam_unit` o `Core` que investigar. **No probado** sobre un servidor.

**Qué se probó y qué no (2026-10-17)**: *probado* sobre la base de desarrollo, con la tabla vacía, `down()` y `up()` de `2026_10_17_100100_create_academic_periods_table.php` (tabla, disparador `academic_year_write_guard`, política y RLS con `FORCE`, 4 `CHECK`, 6 índices y clave foránea de tres columnas idénticos antes y después; 0 migraciones pendientes), y por lectura del código que la auditoría muestra `auditable_type` como cadena, así que un alias desconocido no falla. *No probado*: el despliegue real de la imagen anterior con datos de períodos ya creados (mismo bloqueo que en pasos anteriores: no hay entorno de despliegue en desarrollo); queda para el primer *staging* (`0.10e`).

Es una operación de segundos porque cada versión es una imagen inmutable en GHCR referenciada por *tag* exacto (`ADR-037 §5.2`) — no hay migración de imagen que deshacer, solo qué proceso arranca. Las migraciones de base de datos son *expand/contract* (`RARQ-DEP-003`): el esquema de la versión anterior sigue siendo compatible, así que revertir el código no exige revertir el esquema.

**No probado de extremo a extremo por el mismo bloqueo de permisos que 3b.2.** Lo que sí se probó de verdad y ejercita el mismo mecanismo de fondo (sustituir la imagen que corre un contenedor sin romper el enrutado): la prueba C de `SYSADMIN.md §6.3` recreó el contenedor de la API con una nueva instancia y Traefik enrutó a la IP nueva sin intervención manual — es la misma propiedad que hace segura una reversión, aplicada a una recreación en vez de a un cambio de *tag*.

**Reversión del endurecimiento de `TEMP` (`1.11-a`)**: no hace falta para volver a la versión anterior (funciona con el `REVOKE` aplicado). Solo si se quisiera restaurar el privilegio por defecto de PostgreSQL: `GRANT TEMPORARY ON DATABASE "<base>" TO PUBLIC`; hacerlo reabre el riesgo documentado en `SYSADMIN.md` y requiere decisión explícita. La migración de índices de claves foráneas (`2026_10_12_100000_add_tenant_foreign_key_indexes`) se revierte con su `down()` (`DROP INDEX CONCURRENTLY IF EXISTS`, solo los índices que su `up()` puede crear), sin pérdida de datos; las FK siguen funcionando, solo se ralentiza el borrado físico del padre.

**Reversión de `REQ-AUTH-003` (1.3)** (`docs/modulos/REQ-AUTH/operacion.md §C.11.2`):

- **Drenar la cola `auth-mail` antes de revertir** — con el *worker* real (`worker.container`, `queue:work` sobre el driver `database`; issue [#128](https://github.com/pirexia/plataforma-educativa/issues/128), §2.8) consumiendo la cola. Los cinco trabajos de correo nuevos de 1.3 (código de segundo factor, código de alta, activación/desactivación, código de respaldo usado) no existen en la versión anterior: si queda alguno pendiente al revertir, el *worker* de la versión anterior fallaría por clase inexistente. Procedimiento: (1) con el *worker* nuevo aún en marcha, esperar a que no quede ni un trabajo de esa cola: `podman exec plataforma-api php artisan tinker --execute='echo DB::table("jobs")->where("queue","auth-mail")->count();'` debe dar `0` (cuenta también los reservados y los retrasados por reintento); (2) comprobar `php artisan core:queue-health` (código de salida 0); (3) solo entonces cambiar el *tag* de `api@1` **y** de `worker` a la versión anterior. Si hay que revertir con la cola sin vaciar (el *worker* está caído o el correo falla), purgar a mano lo pendiente de `auth-mail` por `pgsql_owner` antes de arrancar la versión anterior — esos correos (códigos de un solo uso, ya caducados) no se pueden reenviar. `bo:purge-failed-jobs` (`REQ-BO`) limita el daño de lo que ya haya fallado, pero drenar antes evita generarlo. Sustituye desde `1.6d` (2026-09-21) al comando del framework `queue:prune-failed`, que llevaba desde `0.7` sin poder borrar nada de verdad (apuntaba a la conexión sin privilegio de `DELETE` sobre `failed_jobs`) — si `failed_jobs` sigue creciendo sin límite pese a que el planificador diga que la purga corre a diario, comprobar que es `bo:purge-failed-jobs` el comando programado y no el antiguo (`docs/modulos/REQ-BO/operacion.md §6.2`, y §2.5 de este documento). **Probado** (2026-10-20, entorno de desarrollo): encolar un trabajo real, comprobar que `core:queue-health` pasa a fallar al envejecerlo y que el *worker* lo consume y deja la tabla `jobs` en `0` (resultado literal en `CHANGELOG.md`); **no probado** el cambio de *tag* de la unidad Quadlet del *worker* en un servidor, por el mismo motivo que 3b.2.
- **Revertir con factores MFA ya dados de alta es una degradación silenciosa de seguridad, no una pérdida de datos.** La versión anterior ignora `user_mfa_factors` y hace login de un solo paso: los usuarios que activaron MFA dejan de tener segundo factor **sin que nadie se lo diga**. No se pierde nada — las filas siguen ahí y vuelven a valer al desplegar 1.3 de nuevo — pero mientras dura la reversión, cuentas que un momento antes exigían dos factores solo exigen uno. Hay que saberlo antes de decidir revertir, no descubrirlo después.
- La migración del `CHECK` ampliado de `login_attempts` es de un solo sentido en la práctica (tabla *append-only*, sin `DELETE`): revertir la aplicación **no** exige revertir esa migración.

**Reversión de `REQ-AUTH-003` (1.3b)** (`docs/modulos/REQ-AUTH/datos.md §D.6`): sin aviso previo a los centros (`mfa_allowed_methods` sigue en `["totp"]` salvo que un centro haya activado el correo a propósito, `operacion.md §D.11.1`). **Con una consecuencia real si algún centro sí lo activó**: `migrate:rollback` retira `code_hash`/`code_expires_at` de `user_mfa_factors`; un factor `email` que estuviera confirmado en ese momento queda como un factor válido que la versión anterior **no sabe verificar** — la salida es un restablecimiento de MFA por administrador para esas personas, no una pérdida de datos.

### 3b.4 Notas operativas de las unidades Quadlet

- **`worker-long.container`** (`1.12b`): una sola instancia del *worker* de la cola `long` (`queue:work database_long`, copia y reversión del rollover), mismo entorno y endurecimiento que `worker.container`; `StopTimeout=620` deja acabar la copia en curso. Al cambiar de versión se cambia su *tag* junto con el de `worker` y `api@N`. Antes de **revertir** la entrega de `1.12b`, esperar a que no haya copias ni reversiones en vuelo (`pendiente`, `en_curso`, `revirtiendo`): un *worker* de la versión anterior no conoce sus trabajos y los mandaría a `failed_jobs` (`REQ-CURSO/operacion.md §9.3`).
- **`worker.container`** (issue #128): una sola instancia del *worker* de colas, misma imagen que la API, con su propio fichero de entorno `plataforma-worker.env` (plantilla `infra/quadlet/plataforma-worker.env.example`) y `NoNewPrivileges`/`DropCapability=ALL`. `systemctl --user restart worker.service` para reiniciarlo; al pararlo espera hasta 130 s a que acabe el trabajo en curso (`StopTimeout`). Al cambiar de versión (despliegue o reversión) se cambia el *tag* de `worker` junto con el de `api@N`. Diagnóstico: §2.8.
- **Nunca `systemctl stop plataforma.network`** salvo desmantelamiento completo: es la misma regla que `podman compose down` en desarrollo (`ADR-028 §2`), aplicada a la unidad de red de Quadlet.
- La segunda réplica de la API (`api@2`) existe como plantilla ya escrita pero **no se activa** hasta que haya tráfico real (`ADR-037 §6.4`): `systemctl --user start api@2.service` cuando corresponda (`enable` falla con unidades generadas, #695), sin cambios de fichero.
- El proxy de socket delante de Traefik (`ADR-037 §6.3`) es una tarea de `0.10e`, no de hoy: mientras tanto el socket de Podman está montado de solo lectura y el entorno no tiene datos reales.

### 3b.5 SSO institucional SAML 2.0 (`REQ-AUTH-004`, 1.4c): seguimiento de la dependencia y rotación de la clave del SP

`docs/modulos/REQ-AUTH/operacion.md §G.3.1`, `§G.2.3`. Dos obligaciones permanentes que **no terminan cuando termina el paso** — se adquieren al aprobar `ADR-043 §10` y siguen vigentes mientras el módulo use `onelogin/php-saml`:

**Seguimiento de la dependencia** (`ADR-043 §10.3`, factor autobús 1):

1. Suscripción a los avisos de seguridad de **`onelogin/php-saml`** *y* de **`robrichards/xmlseclibs`** — las dos, no una: `xmlseclibs` es el núcleo de XML-DSig y acumula avisos históricos, uno de ellos un *«critical signature bypass»*.
2. Compromiso de parcheo rápido. El modo de fallo característico de esta familia es *«la firma no se valida y el sistema cree que sí»* — sobre el componente que decide quién entra en un sistema con datos de menores.
3. `xmlseclibs 4.0.0` queda en vigilancia; `php-saml` la fija por debajo. Si `php-saml` mueve esa restricción algún día, **es una actualización a revisar, no a aplicar en automático** — repasar `CA-AUTH-336` (los cuatro indicadores del envoltorio siguen a `true`) tras cualquier subida de versión.
4. Si el mantenedor único desaparece (sin *commits* ni respuesta a avisos durante un periodo prolongado), se reabre la evaluación de un intermediario externo (Keycloak/Authentik) que `ADR-043 §7.2` descartó para este paso — con su propio ADR, no como parche.

**Rotación manual de la clave privada de firma del SP** (`AUTH_SAML_SP_SIGNING_KEY_PATH`/`AUTH_SAML_SP_SIGNING_CERT_PATH`, `SYSADMIN.md §2c`): sin automatizar a propósito — es código sin ejercitar en el camino del acceso hasta que alguien lo rote de verdad.

1. Generar el par de clave/certificado nuevo (mismo procedimiento que la clave original, fuera del repositorio).
2. Sustituir el fichero montado (`:Z`, `0400`) y reiniciar el servicio de la API — la nueva clave entra en vigor en el siguiente arranque, no en caliente.
3. **Avisar a cada centro con `sign_authn_requests = true`**: tienen que volver a descargar nuestros metadatos de SP (`GET /identity-providers/{publicId}/metadata`) y recargarlos en su IdP — mientras no lo hagan, sus `AuthnRequest` firmados con la clave vieja serán rechazados por el IdP en cuanto compruebe la firma contra el certificado nuevo que aún no tiene.
4. Confirmar con `auth.saml.acs.outcome` que ningún proveedor con firma activa empieza a fallar tras el reinicio.

**Reversión de una incidencia con la clave** (comprometida o corrupta): retirar `AUTH_SAML_SP_SIGNING_KEY_PATH`/`AUTH_SAML_SP_SIGNING_CERT_PATH` y reiniciar. Ningún proveedor deja de funcionar — `sign_authn_requests` pasa a no poder activarse (`409` si alguien lo intenta) y los que ya lo tenían activo empiezan a enviar `AuthnRequest` sin firmar, que la mayoría de IdP aceptan igualmente (`funcional.md §G.3.7`). **No es una maniobra inocua para los que exigen la firma** (algunos despliegues de ADFS/Shibboleth): esos centros pierden su SSO hasta que se reconfigure la clave, aunque su acceso con contraseña local sigue intacto (`RN-AUTH-96`).

### 3b.6 Si la migración de datos de documentos aborta (`REQ-CORE-003`, `1.9c`)

Síntoma: `php artisan migrate` falla en `2026_10_02_100200_normalize_people_document_to_catalog` con «Migración de documentos abortada sin modificar ninguna fila», y dos listas de `public_id`. **No se ha modificado ningún dato** (una sola transacción).

1. Si el error es de conexión, comprueba que el contenedor tiene las variables `DB_PLATFORM_*` (`SYSADMIN.md §2e`).
2. **Tipos sin correspondencia** (primera lista): son personas cuyo `document_type` no es `dni`, `nie` ni `pasaporte` (ni una variante de mayúsculas/espacios). Consulta cada una con su `public_id` y corrige el tipo desde la aplicación (la API ya solo admite el catálogo) o, en desarrollo, resiembra. No inventes una correspondencia.
3. **Duplicados creados por la normalización** (segunda lista): dos personas vivas del mismo centro con el mismo documento escrito distinto (`12345678-Z` y `12345678z`). Decide cuál es la persona real, fusiona o corrige la otra y da de baja la sobrante.
4. Vuelve a lanzar `migrate`. Es idempotente.
5. Para volver atrás tras una ejecución correcta no hay `down()`: restaura la copia de seguridad / PITR previa al despliegue (`docs/modulos/REQ-CORE/operacion.md §14.1`).

### 3b.7 Barrido de fotografías de recogida huérfanas (`REQ-FAM-UNIT-005`, `1.14b-a`)

El planificador no tiene servicio desplegado (issue [#453](https://github.com/pirexia/plataforma-educativa/issues/453)), así que `fam_unit:purge-orphan-photos` (programado `daily()` en `routes/console.php`) **no corre solo**. Mientras no lo tenga, se lanza a mano desde el contenedor `api`:

```bash
podman exec plataforma-api php artisan fam_unit:purge-orphan-photos
```

Despacha un trabajo por centro activo a la cola `default` (necesita el *worker*, §2.8). Da de baja la fotografía de las personas que no son tutoras y llevan más de `fam_unit.pickup.photo.orphan_grace_days` días (30) sin autorización vigente ni futura, y borra del *bucket* los objetos de `tenants/<centro>/fam-unit/pickup-photos/` sin fila viva con más de esos días (`docs/modulos/REQ-FAM-UNIT/funcional.md §22` D-58). Es idempotente y no toca los activos de marca. **Probado** solo con el disco local simulado de los tests; **no probado** contra un *bucket* S3 real.

### 3b.8 Revertir la precisión de microsegundos del consentimiento de imagen (issue #483)

`migrate:rollback` de `2026_11_05_100200_widen_consent_verification_timestamps_to_microseconds` redondea `image_consent_decisions.occurred_at` y `guardian_student_links.legal_guardian_verified_at` al segundo más cercano (hasta 0,5 s de diferencia, irrecuperable). Si ya hay decisiones, prefiere desplegar la imagen anterior sin revertir el esquema (es compatible).

### 3b.9 Staging en el VPS: qué está probado y reversión (`ADR-080`, `0.10e-c`; estado a 2026-11-16)

**Tras reiniciar el host** (#707): con `linger`, la pila arranca sola, `api@1` incluida (`DefaultInstance=1` en `api@.container`). Comprobar con `podman ps` (8 contenedores con Mailpit, 7 sin él) y `systemctl --user is-active api@1`. Antes de este cambio `api@1` no arrancaba tras un reinicio (la web servía y `/api/…` caía a la SPA con 200). **No verificado tras el despliegue**: pendiente de desplegar y de repetir `CS-04`.

Staging se instala sin privilegios con `./infra/install.sh <tag> --user` (`SYSADMIN.md §4c`). **Probado a mano el 2026-11-16** (no con una prueba automatizada), con la imagen `sha-a1f27d2`: los siete contenedores en `healthy`; las 130 migraciones con `--database=pgsql_owner`; el almacenamiento S3 real del proveedor (bucket de staging); la lista blanca del *backoffice* (200 con una IP permitida, 403 con otra); `CS-01` verificado por el usuario desde su terminal (el *push* forzado y el borrado de `staging` se rechazan con `GH013`). El corte medido del conjunto API + web + Traefik al redesplegar fue de unos 26 s. Los certificados son de **pruebas** (ACME de staging de la autoridad), por lo que un navegador los marcará como no fiables.

**No ejecutado todavía (a 2026-11-16)**: `CS-02` completo (`main` sigue por detrás de `staging`), `CS-17` (E2E real contra staging) y `CS-19` (relay SMTP real); `CS-05`, `CS-06`, `CS-07` y `CS-09` sin evidencia registrada. Hay 0 centros y 0 usuarios, y `demo:seed` no existe (llega con `1.15b`). Hallazgo abierto #693: la lista blanca solo cubre el *backoffice*, no los routers de los centros.

**Procedimiento de reversión (`CS-08` medido en parte, ver abajo).** Es el mismo de `§3b.3`: reinstalar con el *tag* anterior y reiniciar las unidades, **sin migrar** (las migraciones no se revierten). Al avanzar de nuevo, `restart plataforma-migrate.service` y comprobar `Result=success` (#698).

```bash
./infra/install.sh <tag-anterior> --user
systemctl --user daemon-reload
systemctl --user restart api@1.service web.service traefik.service worker.service worker-long.service   # la reversión NO migra: las migraciones no se revierten
```

Sin `migrate:rollback` (las migraciones son aditivas, `CLAUDE.md §9`).

**Si Traefik registra `permission denied while trying to connect to the docker API at unix:///var/run/docker.sock` y no hay routers (#705).** Casi seguro es SELinux en `enforcing` impidiendo la conexión al socket de Podman. Comprobar: `getenforce` y `systemctl --user cat traefik.service | grep -c -- '--security-opt label=disable'` (debe dar `1`: `traefik.container` lleva `SecurityLabelDisable=true`, provisional hasta `0.10e-d`). Si no sale, la unidad instalada es anterior: reinstalar con `./infra/install.sh <tag> --user`, `systemctl --user daemon-reload` y `systemctl --user restart traefik.service`. **No** pongas el host en `permissive` ni desactives SELinux en otros contenedores. Sin verificar aún en el VPS.

**`CS-08`, 2.ª ejecución (2026-11-16, VPS de staging; hechos verificados a mano, sin prueba automatizada).** Reversión `sha-5072b79` → `sha-ba00db4` en 23,2 s (`install.sh` 0,6 s; API + web + Traefik 16,5 s; `worker` 0,7 s; `worker-long` 1,2 s) y avance de vuelta a `sha-5072b79` en 27,2 s. Corte medido en los *backends* de unos 2,9 s (reversión) y 2,8 s (avance); a través de Traefik, unos 6,1 s en la reversión (22 sondeos fallidos, 000/5xx) y 3,1 s en el avance (12). Un trabajo encolado por `sha-ba00db4` (un *closure* en la cola `cs08`, que ningún *worker* consume) lo procesó `sha-5072b79` con `queue:work --once`: `jobs=0`, `failed_jobs=0` y el correo llegó a Mailpit. Parada de los *workers* con `pcntl` (#696, ya desplegado): `worker` 0,6 s y `worker-long` 0,9 s (antes 130,9 s y 620,8 s sin `pcntl`).

**Salvedades que acompañan a ese resultado, tal cual**: (1) el trabajo es un *closure*, no una clase de negocio, y no lo consumió el *worker* de systemd sino `queue:work --once` de la imagen nueva; (2) ambas versiones comparten esquema y casi todo el código; (3) `plataforma-migrate` no tenía migraciones pendientes (1,0 s): **no se probó una migración real**; (4) al avanzar, los *workers* del destino de la reversión (`sha-ba00db4`, sin `pcntl`) se pararon con `SIGKILL` a mano porque tardan 130 s y 620 s; con una reversión a una imagen sin `pcntl` la parada vuelve a ser lenta; (5) el sondeo es desde el propio VPS (un 403 de la lista blanca cuenta como «arriba»). **Sigue sin ejecutarse** `CS-17`, `CS-19` y `CS-02` completo, y #693 sigue abierto.


## 3c. Ramas: promoción y arreglo urgente (`ADR-080 §4`, paso `0.10e-a`)

**Estado**: el procedimiento, el gancho (`.claude/hooks/block-protected-branch.sh`, probado con su arnés de shell) y los workflows están escritos. **Sin probar** hasta que exista la rama `staging` y su protección en GitHub: que Git rechace un avance no rápido en `staging` y `main` (`CS-01`), que GitHub acepte el *push* de un commit que ya pasó los checks en otra rama con las comprobaciones obligatorias activas, y una promoción completa con etiqueta de ensayo (`CS-02`). Las reglas de protección de `staging` y `main` las crea el propietario en GitHub; no las crea el código.

### 3c.1 Promoción `develop` → `staging` → `main`

Solo la hace la sesión orquestadora o `janitor`, y **`main` solo cuando el propietario lo pide** (publicar una versión). Nunca con el botón de mezclar de GitHub ni con `espera-ci`.

**Primera creación de `staging` (`ADR-080 §4.6`; sin ejecutar a 2026-11-16).** Mientras la rama no exista, el paso 2 siguiente no es aplicable (`origin/staging` no existe). Se crea una sola vez, **después de que el propietario cree las reglas de protección de `staging` y `main` en GitHub**, apuntando al commit de `main` ya reconciliado con `develop`, desde HEAD desacoplado: `git push origin <sha de main, 40 hex>:refs/heads/staging`. A continuación se comprueba `CS-01` (un *push* no avance rápido a `staging` es rechazado) y se ensaya `CS-02`.

1. Elige el commit candidato `<sha>` (40 caracteres hexadecimales) de `develop` con el CI en verde.
2. Desde **HEAD desacoplado** o un *worktree* (el gancho bloquea el *push* con una rama protegida como actual):
   ```bash
   git fetch origin
   git merge-base --is-ancestor origin/staging <sha> && echo "avance rápido posible"
   git checkout --detach <sha>
   git push origin <sha>:refs/heads/staging      # sin --force, sin -f, sin '+'
   ```
   Git rechaza solo un *push* que no sea avance rápido; ese rechazo es la protección. Si lo rechaza, **no se fuerza**: se investiga (`§3c.2`).
3. Despliega `sha-<7>` en staging (`§3b.2`) y comprueba lo que la entrega pida.
4. A petición del propietario, y con el mismo avance rápido: `git push origin <sha>:refs/heads/main` y etiqueta anotada `vX.Y.Z` sobre ese commit (dispara la imagen de versión, `ADR-037 §5.2`).
5. Un PR `develop` → `staging` solo sirve para dejar constancia; **nunca se pulsa «Merge»** en él.

La retención de imágenes (`build-images.yml`, job `prune-old-images`, con `.github/scripts/purgar-imagenes.sh`) lista las versiones del paquete con la API de GitHub y conserva las 10 más recientes, toda versión con etiqueta `vX.Y.Z` y toda versión con la etiqueta `sha-<7>` de los commits a los que apuntan `staging` y `main` en ese momento (más los manifiestos sin etiqueta creados a menos de 120 s de una imagen conservada por esas dos reglas, heurística no verificada contra GHCR). No usa `actions/delete-package-versions`: esa acción aplica `ignore-versions` al digest de la versión, no a sus etiquetas. La lógica de selección se prueba sin red con `.github/scripts/test-purgar-imagenes.sh`; **sin verificar** contra GHCR real (permisos del `GITHUB_TOKEN` sobre `/users/<dueño>/packages/container/…` y comportamiento de los manifiestos sin etiqueta). Límite: lo calcula en cada purga (cada *push* a `develop`) a partir de las ramas del remoto; una imagen de un commit que ya no es la punta de `staging` ni de `main` vuelve a estar sujeta a las 10 versiones más recientes.

**Límites conocidos del gancho** (`.claude/hooks/block-protected-branch.sh`; solo protege las sesiones de Claude Code y se prueba con `.claude/hooks/test-block-protected-branch.sh`):

- No cubre `git merge`, `reset`, `cherry-pick`, `rebase`, `tag -f` ni `branch -f` sobre ramas protegidas.
- No ve comandos dentro de `bash -c`, `env`, `xargs`, alias ni `$(…)`.
- No distingue remotos: analiza el destino, no a qué remoto va.
- No frena un *push* manual del propietario desde su terminal.
- Un texto de varias líneas (cuerpo de un PR, heredoc) con una línea que empiece por `git push …` puede dar un falso bloqueo.
- El guardarraíl definitivo son las reglas de protección de ramas de GitHub, que crea el propietario (`SYSADMIN.md §4`) y que **siguen sin verificar**.

### 3c.2 Arreglo urgente

- **Norma**: el arreglo va por `develop` (`fix/…`, *squash*) y se promociona.
- **Excepción**, solo si `develop` contiene algo que no puede ir aún a staging: rama `fix/…` desde `staging`, PR a `staging` con ***merge commit*** (`gh pr merge --merge`, sin borrar `staging`) y **en la misma sesión** un PR `staging` → `develop` también con *merge commit*, para que `staging` vuelva a ser ancestro de `develop`. Hasta que ese segundo PR se mezcle no hay más promociones. Se anota en `memory.md`. Ninguno de estos PR usa `espera-ci`.
- La reconciliación `main` → `develop` (cuando `main` lleva un commit que `develop` no tiene) se hace igual: PR con *merge commit*, sin borrar la rama de origen.

## 4. Copias de seguridad y recuperación

**No aplica todavía.** El módulo `REQ-BKP` (copias de seguridad, restauración granular en cuatro niveles, copia inmutable) no está implementado, y el proveedor de almacenamiento de copias distinto del host sigue sin decidir (`OPEN-10`). No hay nada que respaldar en un entorno sin datos reales.

## 5. Referencias

- Diagnóstico general: skill `depuracion`.
- Catálogo de bugs ya encontrados y resueltos por paso: `docs/historial/`.
- Configuración de red y contenedores: `SYSADMIN.md`.
- Bloqueantes que impiden pasar de este runbook de desarrollo a uno de producción: `README.md` §"Bloqueantes actuales".
