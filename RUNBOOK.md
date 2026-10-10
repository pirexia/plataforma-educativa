# RUNBOOK.md

> **Versión 0.3.5** · 2026-10-10
> Documento vivo: se actualiza en cada fase (`CLAUDE.md` §6). Cubre por ahora únicamente el entorno de **desarrollo** en WSL2 (`ADR-030`) — no hay producción, piloto ni usuarios reales todavía. Los procedimientos de guardia, alertas y recuperación ante desastre de un entorno real se documentarán aquí cuando `OPEN-11` (alojamiento del piloto) se resuelva.

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
podman compose --profile full up -d   # añade minio
podman compose ps
podman compose logs -f <servicio>
```

**Nunca** `podman compose down` salvo en un entorno completamente desechable — borra la red externa `plataforma-net` y rompe la resolución de nombres entre servicios (`ADR-028`, `SYSADMIN.md` §1.3).

### 2.2 Si un contenedor no arranca o no queda `healthy`

1. `podman compose logs <servicio>` primero, no adivinar.
2. Comprobar que la red `plataforma-net` existe: `podman network ls`.
3. Ver `docs/historial/0.7-nucleo-multitenant.md` y `docs/historial/0.8-modelo-de-datos-nucleo.md` — catálogo de bugs de entorno ya encontrados y su solución (deadlocks de test entre conexiones, `.env`/`storage/` ausentes en un *worktree* nuevo, purga agresiva de paquetes `-dev` en el `Containerfile`, etc.). No repetir un diagnóstico ya hecho.
4. **`api` sano un momento y `500`/`unhealthy` al siguiente, sin haber tocado nada del servicio a propósito** (issue [#62](https://github.com/pirexia/plataforma-educativa/issues/62), 2026-08-22): revisar `apps/api/.env` — `SESSION_LIFETIME` debe ser `>= AUTH_SESSION_TIMEOUT_MAX_MINUTES` (`SYSADMIN.md §2c`) desde `REQ-AUTH` (1.2). El código se interpreta por petición (sin *build*), así que un `git pull`/*merge* que traiga 1.2 sin ese valor ajustado tumba el contenedor en la siguiente petición. Si `podman rm`/`--force-recreate` falla con *"has dependent containers"* (acoplamiento `web` → `api` vía `depends_on`), no forzar más: `podman-compose down && podman-compose up -d` recrea la pila completa sin tocar la red externa (`external: true`) ni el volumen `postgres-data`.
5. **Login por navegador da `404` o `419`, aunque la API responda bien por `curl`** (issue [#71](https://github.com/pirexia/plataforma-educativa/issues/71), 2026-08-25): entra por `http://demo.plataforma.test:5173/`, no por `localhost:5173` — la SPA y la API deben compartir host (aunque no puerto) para que la cookie `XSRF-TOKEN` sea legible desde la página (`SYSADMIN.md §2c`, fila `VITE_API_URL`). Requiere `127.0.0.1 demo.plataforma.test` en el `hosts` de **Windows** (no el de la distribución WSL2).
6. **`plataforma-postgres` sale con error al arrancar tras actualizar a `postgres:18`** (`ADR-061`): el *entrypoint* de 18 se niega a arrancar si encuentra datos antiguos (`PG_VERSION`) en `/var/lib/postgresql` o en `/var/lib/postgresql/data`, o si `/var/lib/postgresql/data` es un punto de montaje aunque esté vacío (mensaje `unused mount/volume`; el caso habitual que cita es actualizar la imagen sin `pg_upgrade`). Causa: se montó el volumen de 17 (o el montaje antiguo `.../data`) con la imagen 18. El fallo es seguro, no toca los datos. Solución: no forzar nada; montar el volumen nuevo `postgres-cluster` en `/var/lib/postgresql` (`compose.yaml` de la rama actual) y seguir `§2.7`. Un error de `runc` con enlace simbólico al montar sobre `.../data` tiene el mismo origen (`docker-library/postgres#1370`, `#1377`).
7. Para cualquier otro síntoma: skill `depuracion` (método de diagnóstico y catálogo de fallos característicos del stack).

### 2.3 Si CI falla en un PR

- Revisar el check concreto (`ci-api.yml`, `ci-web.yml`, `dependency-scan.yml`) antes de reintentar sin más.
- Los ocho checks son *required status checks* en `develop` desde el cierre de 0.7 — un PR no se puede mezclar sin ellos en verde, y no se puede saltar con `--no-verify` ni equivalente.

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

**Conservación**: `plataforma-educativa_postgres-data` y `$DIR` se conservan al menos hasta que la rama esté mezclada y se haya trabajado una semana sobre 18. Borrarlos es decisión del usuario. El volumen antiguo de prodlike (`plataforma-prodlike_prodlike-postgres-data`) es desechable; su borrado también se deja al usuario.

**Producción (Quadlet)**: hoy no aplica (sin producción ni datos reales, `§4`); la primera instalación nacerá en 18 con `postgres-cluster` vacío. Para un host con un clúster 17 con datos, **condición previa**: ensayar entero, reversión incluida, en `compose.prodlike.yaml` con datos sintéticos (`ADR-030`) y anotar el resultado en `SYSADMIN.md`.

1. Ventana de mantenimiento: `systemctl stop api@1.service web.service` (y cualquier *worker* que exista).
2. Inventario y volcados con el cliente de 18, como en los pasos 0 y 2 de arriba. El volcado contiene datos personales reales: se trata como copia de seguridad (cifrado, fuera del host de aplicación cuando `REQ-BKP`/`OPEN-10` lo permitan, borrado al cerrar la ventana de reversión) y nunca se lleva a desarrollo (`ADR-030`).
3. `systemctl stop postgres.service`; `./infra/install.sh <tag con 18>`; `systemctl daemon-reload`; `systemctl start postgres.service` (inicialización sobre `postgres-cluster` con los *scripts* de `/opt/plataforma/postgres-init`).
4. `dropdb` + `pg_restore --create --exit-on-error` + inventario + `diff` + comprobaciones, como en los pasos 5 y 6.
5. Decidir continuar o volver **antes** de reabrir el tráfico. Continuar: `systemctl start api@1.service web.service` y prueba de humo. Volver: `systemctl stop postgres.service`, `./infra/install.sh <tag anterior>`, `systemctl daemon-reload`, `systemctl start postgres.service` (17 sobre el volumen antiguo, intacto), `systemctl start api@1.service web.service`. **Después de reabrir el tráfico**, volver a 17 pierde lo escrito en 18.

---

## 3. Guardias (on-call)

**No aplica todavía.** No hay entorno de producción, no hay usuarios reales, no hay SLA que cumplir. Esta sección se escribe cuando exista alojamiento del piloto (`OPEN-11`) y el primer centro real.

## 3b. Despliegue y reversión (`ADR-037`)

Escrito, y probado **en parte** en WSL2 — ver `SYSADMIN.md §6` para el detalle exacto de qué está verificado y qué no. La topología completa (red, cinco servicios, imágenes `prod` reales) y las tres pruebas de resiliencia obligatorias se ejecutaron de verdad con `compose.prodlike.yaml` (`SYSADMIN.md §6.3`). El ciclo de vida por unidades Quadlet reales (`install.sh`, `systemctl --user`) **no se ha podido ejecutar en este host concreto**: `~/.config/containers` pertenece a `root`, un problema de permisos preexistente sin relación con este paso (`SYSADMIN.md §6.2`) — se corrige con un `sudo chown` que esta sesión no puede ejecutar sin contraseña interactiva.

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

`<tag>` es siempre una versión exacta (`X.Y.Z` en producción, `sha-<7>` o `develop` en *staging*) — nunca `latest` (`ADR-037 §5.2`). El script sustituye el *tag* en las unidades y recarga systemd; no arranca nada por sí solo.

**Escrito, no ejecutado de extremo a extremo en este host** (`SYSADMIN.md §6.2`): `install.sh --user` requiere escribir en `~/.config/containers/systemd/`, bloqueado por el propietario incorrecto del directorio. La lógica que sí se ha ejercido — sustitución de `__TAG__`, generación de unidades systemd válidas a partir de estos ficheros — está verificada por separado (`SYSADMIN.md §6.1`); lo que falta es la instalación automática en este directorio concreto, no el contenido de las unidades.

### 3b.3 Reversión

Mismo mecanismo que el despliegue: bajar el *tag* a la versión anterior y reiniciar la unidad.

```bash
./infra/install.sh <tag-anterior> --user
systemctl --user restart api@1.service web.service
```

Es una operación de segundos porque cada versión es una imagen inmutable en GHCR referenciada por *tag* exacto (`ADR-037 §5.2`) — no hay migración de imagen que deshacer, solo qué proceso arranca. Las migraciones de base de datos son *expand/contract* (`RARQ-DEP-003`): el esquema de la versión anterior sigue siendo compatible, así que revertir el código no exige revertir el esquema.

**No probado de extremo a extremo por el mismo bloqueo de permisos que 3b.2.** Lo que sí se probó de verdad y ejercita el mismo mecanismo de fondo (sustituir la imagen que corre un contenedor sin romper el enrutado): la prueba C de `SYSADMIN.md §6.3` recreó el contenedor de la API con una nueva instancia y Traefik enrutó a la IP nueva sin intervención manual — es la misma propiedad que hace segura una reversión, aplicada a una recreación en vez de a un cambio de *tag*.

**Reversión de `REQ-AUTH-003` (1.3)** (`docs/modulos/REQ-AUTH/operacion.md §C.11.2`):

- **Drenar la cola `auth-mail` antes de revertir** — procedimiento documentado para cuando exista un *worker* real consumiéndola. **A día de hoy no hay ningún *worker* de colas desplegado** (issue [#128](https://github.com/pirexia/plataforma-educativa/issues/128), `SYSADMIN.md`): los trabajos despachados a `auth-mail` quedan en la tabla `jobs` sin procesar, así que este procedimiento de drenado no se ha podido probar de extremo a extremo todavía. Los cinco trabajos de correo nuevos de 1.3 (código de segundo factor, código de alta, activación/desactivación, código de respaldo usado) no existen en la versión anterior: si queda alguno pendiente en la cola al revertir, un *worker* de la versión anterior fallaría por clase inexistente en cuanto se despliegue uno. `bo:purge-failed-jobs` (`REQ-BO`) limita el daño, pero drenar antes evita generarlo. Sustituye desde `1.6d` (2026-09-21) al comando del framework `queue:prune-failed`, que llevaba desde `0.7` sin poder borrar nada de verdad (apuntaba a la conexión sin privilegio de `DELETE` sobre `failed_jobs`) — si `failed_jobs` sigue creciendo sin límite pese a que el planificador diga que la purga corre a diario, comprobar que es `bo:purge-failed-jobs` el comando programado y no el antiguo (`docs/modulos/REQ-BO/operacion.md §6.2`, y §2.5 de este documento).
- **Revertir con factores MFA ya dados de alta es una degradación silenciosa de seguridad, no una pérdida de datos.** La versión anterior ignora `user_mfa_factors` y hace login de un solo paso: los usuarios que activaron MFA dejan de tener segundo factor **sin que nadie se lo diga**. No se pierde nada — las filas siguen ahí y vuelven a valer al desplegar 1.3 de nuevo — pero mientras dura la reversión, cuentas que un momento antes exigían dos factores solo exigen uno. Hay que saberlo antes de decidir revertir, no descubrirlo después.
- La migración del `CHECK` ampliado de `login_attempts` es de un solo sentido en la práctica (tabla *append-only*, sin `DELETE`): revertir la aplicación **no** exige revertir esa migración.

**Reversión de `REQ-AUTH-003` (1.3b)** (`docs/modulos/REQ-AUTH/datos.md §D.6`): sin aviso previo a los centros (`mfa_allowed_methods` sigue en `["totp"]` salvo que un centro haya activado el correo a propósito, `operacion.md §D.11.1`). **Con una consecuencia real si algún centro sí lo activó**: `migrate:rollback` retira `code_hash`/`code_expires_at` de `user_mfa_factors`; un factor `email` que estuviera confirmado en ese momento queda como un factor válido que la versión anterior **no sabe verificar** — la salida es un restablecimiento de MFA por administrador para esas personas, no una pérdida de datos.

### 3b.4 Notas operativas de las unidades Quadlet

- **Nunca `systemctl stop plataforma.network`** salvo desmantelamiento completo: es la misma regla que `podman compose down` en desarrollo (`ADR-028 §2`), aplicada a la unidad de red de Quadlet.
- La segunda réplica de la API (`api@2`) existe como plantilla ya escrita pero **no se activa** hasta que haya tráfico real (`ADR-037 §6.4`): `systemctl --user enable --now api@2.service` cuando corresponda, sin cambios de fichero.
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

## 4. Copias de seguridad y recuperación

**No aplica todavía.** El módulo `REQ-BKP` (copias de seguridad, restauración granular en cuatro niveles, copia inmutable) no está implementado, y el proveedor de almacenamiento de copias distinto del host sigue sin decidir (`OPEN-10`). No hay nada que respaldar en un entorno sin datos reales.

## 5. Referencias

- Diagnóstico general: skill `depuracion`.
- Catálogo de bugs ya encontrados y resueltos por paso: `docs/historial/`.
- Configuración de red y contenedores: `SYSADMIN.md`.
- Bloqueantes que impiden pasar de este runbook de desarrollo a uno de producción: `README.md` §"Bloqueantes actuales".
