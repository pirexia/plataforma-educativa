# ADR-061 · Actualización de PostgreSQL 17 a 18

**Estado**: **ACEPTADA** (2026-10-10). La decisión de actualizar ahora la tomó el usuario ese día, a raíz del PR de Renovate [#347](https://github.com/pirexia/plataforma-educativa/pull/347); este ADR fija cómo. **Inmutable**: cualquier cambio posterior exige un ADR nuevo que lo sustituya explícitamente.
**Fecha**: 2026-10-10
**Paso**: transversal, fuera de la numeración del plan. Rama `chore/postgres-18`.
**Se apoya en**: `ADR-028` (red y dependencias), `ADR-030` (desarrollo en WSL2, nunca datos reales), `ADR-033 §10` (suite contra PostgreSQL real con RLS y tres roles), `ADR-037` (Quadlet), `ADR-058` (`CA-058-02`, lista de clases de `SQLSTATE` atada a la versión mayor), `ADR-060` (bases de test por proceso); `CLAUDE.md §9` (reversión probada, el host solo ejecuta contenedores).
**Sustituye** solo una fila de `ADR-037`: la que nombra `postgres-data.volume` entre los volúmenes nombrados de Quadlet (`§7` de este ADR). El resto de `ADR-037` sigue vigente. **No cambia** `ADR-001`: «PostgreSQL 17+» incluye 18.
**Afecta a**: `compose.yaml`, `infra/compose/compose.prodlike.yaml`, `infra/quadlet/postgres.container`, `infra/quadlet/postgres-data.volume` (se retira) y `infra/quadlet/postgres-cluster.volume` (nuevo), `.github/workflows/ci-api.yml`, `apps/api/tests/Feature/Curso/AcademicYearWriteGuardTest.php` (`CA-058-02`), `SYSADMIN.md`, `RUNBOOK.md`, `README.md`, `ARCHITECTURE.md`, `CHANGELOG.md`.

---

## 1 · Contexto

El PR de Renovate #347 cambia la etiqueta `postgres:17` → `postgres:18` en `compose.yaml`, `compose.prodlike.yaml` y `infra/quadlet/postgres.container`, y nada más. **Así, tal cual, no funciona en ningún entorno con volumen**, y deja CI en 17 (`ci-api.yml` usa `postgres:17` en los servicios de los jobs `test`, `lint` y `static-analysis`).

### 1.1 · Cambios de la imagen oficial `postgres:18` (comprobados en la documentación de la imagen y en su `docker-entrypoint.sh`)

1. **`PGDATA` pasa a ser específico de la versión**: `/var/lib/postgresql/18/docker` (antes `/var/lib/postgresql/data`). El `VOLUME` declarado pasa a `/var/lib/postgresql` y la documentación pide montar **ahí**, no en `.../data`. El motivo declarado: con el directorio padre montado, una futura subida de versión mayor puede usar `pg_upgrade --link` dentro del mismo volumen.
2. **El *entrypoint* de 18 se niega a arrancar** si encuentra datos antiguos (`PG_VERSION`) en `/var/lib/postgresql` o en `/var/lib/postgresql/data`, **o si `/var/lib/postgresql/data` es un punto de montaje aunque esté vacío** (lo marca como `unused mount/volume`). El mensaje dice que el caso habitual es «actualizar la imagen sin actualizar la base con `pg_upgrade`». Consecuencia directa: las tres definiciones actuales, que montan en `/var/lib/postgresql/data`, fallan al arrancar con 18; y montar el volumen actual (datos de 17 en su raíz) en `/var/lib/postgresql` también falla. **Ningún camino reutiliza el volumen de 17 con la imagen 18.** El fallo es seguro (no arranca, no toca los datos).
3. Además, en 18 `/var/lib/postgresql/data` es un enlace simbólico, y montar sobre él da errores de `runc` en algunos sistemas de ficheros (incidencias `docker-library/postgres#1370` y `#1377`).

### 1.2 · Cambios de PostgreSQL 18 («Migration to Version 18» de las notas de versión) contrastados con el proyecto

| Cambio | ¿Nos afecta? | Por qué |
|---|---|---|
| **`initdb` activa los *checksums* de datos por defecto** (`--no-data-checksums` para desactivarlos) | **Sí, a favor** | Todo clúster nuevo (desarrollo, CI, prodlike, Quadlet) nace con `data_checksums = on`: detección de corrupción de página con un coste de CPU pequeño. Se **adopta el valor por defecto**; no se pasa `POSTGRES_INITDB_ARGS`. Relevante para el futuro: `pg_upgrade` exige el mismo ajuste en origen y destino, y a partir de aquí todos los clústeres lo tienen activo |
| **Contraseñas MD5 obsoletas** (aviso al crearlas; se retirarán en una versión futura) | **No** | `password_encryption` es `scram-sha-256` desde PostgreSQL 14; `01-tenancy.sql.tpl` crea y altera los roles con `PASSWORD %L`, que se cifra con SCRAM; la imagen deriva el método de `pg_hba` de `password_encryption`. Se comprueba igualmente (`CA-061-05`) |
| *Triggers* `AFTER` se ejecutan con el rol activo al encolarse, no al ejecutarse | **No** | El disparador de `ADR-057` es `BEFORE`. El proyecto no usa `SET ROLE`: cada rol es una conexión distinta (`ADR-033`). El único `AFTER` es uno temporal dentro de `AcademicYearWriteGuardTest`, creado y disparado por el mismo rol |
| `VACUUM`/`ANALYZE` recorren hijos de herencia por defecto | No hoy | No hay tablas particionadas todavía; cuando las haya, el comportamiento nuevo es el deseado |
| Tablas particionadas `UNLOGGED` prohibidas | No | No se usan |
| Búsqueda de texto completo con el proveedor de intercalación del clúster | No | No hay índices de texto completo ni `pg_trgm`; además, la restauración por volcado (`§4.2`) reconstruye todos los índices |
| Abreviaturas de zona horaria (prioridad de la sesión) | No | `TIMESTAMPTZ` con desplazamientos ISO 8601 (`ADR-029`) |
| `COPY FROM` CSV ya no trata `\.` como fin de fichero | No | El CSV lo genera PHP (`ADR-054`), no `COPY` |
| RLS, roles, `BYPASSRLS`, privilegios por defecto | Sin cambios | Las notas no incluyen ningún cambio incompatible de RLS; la única novedad es la opción `--no-policies` de `pg_dump`/`pg_restore`, que **no** se usa |
| **Extensiones** | Ninguna | Ni las migraciones ni `infra/containers/postgres/init/` crean extensiones (solo `plpgsql`, incluida en el motor) |
| **Apéndice A, códigos de error**: PostgreSQL 18 añade la clase **`10`** («XQuery Error») | **Sí, en un test** | `CA-058-02` compara `YC001` con la lista de clases de PostgreSQL 17 y **exige versión mayor 17 a propósito** para obligar a repasarla al subir (`ADR-058`). Con 18 el test falla hasta actualizarlo (`§4.4`). `YC` sigue libre en 18 |

## 2 · Qué NO decide este ADR

- **No adopta ninguna funcionalidad nueva de 18** (E/S asíncrona configurable, `uuidv7()`, columnas generadas virtuales, OAuth…). Usarlas exigirá su propia decisión; `ADR-029` sigue fijando ULID como `public_id`.
- **No cambia `max_connections`** ni ningún parámetro del servidor.
- **No decide el método de la próxima subida mayor** (18 → 19): el nuevo punto de montaje deja abierta la puerta a `pg_upgrade --link`, pero se decidirá con su propio ADR y con datos reales de volumen.

## 3 · Opciones reales para migrar los datos de un volumen 17

| Opción | Coste en solitario | Mantenimiento a 3 años | Invariantes | Reversibilidad |
|---|---|---|---|---|
| **A** · `pg_dump` de la base de aplicación con el cliente de 18 + restauración en un clúster 18 **con volumen nuevo**; volcado completo `pg_dumpall` como respaldo | Bajo: comandos de la imagen oficial, sin nada nuevo. En desarrollo, segundos (datos sintéticos) | Ninguno: es el procedimiento documentado por PostgreSQL y no deja piezas | Roles, RLS y permisos los recrea el mismo aprovisionamiento (`01-tenancy.sh`, `02-tenancy-test-db.sh`); el volcado trae tablas, políticas, disparadores, privilegios y propietarios | **Total**: el volumen de 17 queda intacto |
| **B** · `pg_upgrade` | Alto: necesita los binarios de 17 y de 18 en el mismo contenedor. No hay imagen oficial que los traiga; la habitual (`tianon/postgres-upgrade`) es de terceros, y adoptarla es una dependencia nueva (`CLAUDE.md §1`). El volumen actual (datos en la raíz) además no permite `--link` sin reorganizarlo antes | Una dependencia de terceros más para un uso puntual | Igual que A, pero conserva los índices tal cual: un cambio de versión de `glibc` entre las bases Debian de las dos imágenes dejaría intercalaciones con versión distinta sin reconstruir | Media: con `--link` el volumen antiguo deja de ser utilizable tras arrancar el nuevo; sin `--link`, igual que A |
| **C** · Volumen nuevo vacío, `migrate` y *seed* (`REQ-SEED`), sin restaurar nada | Mínimo | Ninguno | Ninguno | Total |

**Decisión: A.** C es aceptable en desarrollo (los datos son sintéticos, `ADR-030`) pero pierde el estado de trabajo sin necesidad y **no** sirve como ensayo del procedimiento que hará falta el día que haya datos que conservar; A cuesta casi lo mismo y es ese ensayo. B solo compensa con volúmenes grandes, que no tenemos, y cuesta una dependencia de terceros.

**Detalles de A** (y por qué cada uno):

- **Volcado con el cliente de la versión nueva** (`pg_dump` de la imagen 18 contra el servidor 17 por la red de contenedores): es lo que recomienda la documentación de PostgreSQL para subir de versión mayor.
- **Se restaura solo la base de aplicación (`plataforma`), en formato `custom`, con `pg_restore --create`**, después de borrar la `plataforma` vacía que crea la inicialización. `--create` trae también los privilegios y ajustes de la propia base (`GRANT CONNECT`). Los objetos de clúster (los tres roles) **no** vienen del volcado: los crea `01-tenancy.sh` desde el mismo `.env`, con los mismos nombres y contraseñas, en SCRAM. Así no hay errores esperados que filtrar a mano (restaurar un `pg_dumpall` sobre un clúster ya aprovisionado produce errores de «ya existe» que habría que distinguir de los reales).
- **Las bases de test no se vuelcan**: `plataforma_test` la crea `02-tenancy-test-db.sh` en la inicialización, y `plataforma_test_1..N` se clonan después con `bases-test-paralelo.sh` (`ADR-060`). Cada proceso migra su base en el primer `setUp()`.
- **`pg_dumpall` completo (sin las bases de test) como respaldo**, fuera del repositorio. No se usa para restaurar; existe por si el volumen antiguo se perdiera antes de dar por cerrada la migración.
- **Volumen con nombre nuevo**, montado en `/var/lib/postgresql`: `postgres-cluster` (`prodlike-postgres-cluster` en prodlike; `postgres-cluster.volume` en Quadlet). El nombre no lleva la versión a propósito: con el montaje nuevo, el mismo volumen alojará `18/docker` y, en su día, `19/docker`. El volumen antiguo (`postgres-data`) **no se toca**: es el camino de vuelta.

## 4 · Decisión

### 4.1 · Definiciones de contenedor

| Fichero | Cambio |
|---|---|
| `compose.yaml` | `image: docker.io/library/postgres:18`; volumen nombrado `postgres-cluster` montado en **`/var/lib/postgresql:Z`**; se declara `postgres-cluster` en `volumes:` y **se mantiene declarado `postgres-data`** (sin montar) con un comentario: es el volumen de 17, camino de vuelta, no se borra salvo decisión del usuario |
| `infra/compose/compose.prodlike.yaml` | `image: docker.io/library/postgres:18`; `prodlike-postgres-cluster:/var/lib/postgresql:Z`; se retira la declaración de `prodlike-postgres-data` (entorno desechable, `SYSADMIN.md §6`) |
| `infra/quadlet/postgres.container` | `Image=docker.io/library/postgres:18`; `Volume=postgres-cluster.volume:/var/lib/postgresql:Z` |
| `infra/quadlet/postgres-cluster.volume` | Nuevo, `[Volume]` `VolumeName=postgres-cluster` |
| `infra/quadlet/postgres-data.volume` | Se retira del repositorio. Retirarlo **no** borra el volumen en un host donde exista |
| `.github/workflows/ci-api.yml` | `image: postgres:18` en los **tres** servicios (`test`, `lint`, `static-analysis`). Sin volúmenes: cada job inicializa un clúster nuevo |

No se fija `PGDATA` a mano ni se pasa `POSTGRES_INITDB_ARGS`: se usan los valores por defecto de la imagen (`/var/lib/postgresql/18/docker`, *checksums* activos). La etiqueta sigue siendo la mayor flotante (`18`), como hoy con `17`; Renovate sigue gestionando las menores.

El cliente `psql` que instala CI (`postgresql-client` de Ubuntu) es de una versión mayor anterior al servidor; para el SQL de `01-tenancy.sql.tpl` y `bases-test-paralelo.sh` es compatible. **No se añade el repositorio de PGDG preventivamente**: si `CA-061-02` falla por el cliente, se decide entonces.

### 4.2 · Procedimiento en desarrollo

Lo ejecuta la sesión principal, desde la raíz del repositorio en WSL2, con la rama `chore/postgres-18` en disco (cambiar los ficheros no afecta a los contenedores que ya corren). **Nunca** `podman compose down`, `podman rm -v` ni `podman volume rm` (`ADR-028`). El volcado contiene *hashes* de contraseñas: va **fuera del repositorio**, con permisos `700`/`600`.

```bash
# 0. Preparación
DIR="$HOME/plataforma-respaldos/pg17-a-pg18-$(date +%Y%m%d-%H%M)"
mkdir -p "$DIR" && chmod 700 "$DIR"
PGPW="$(podman exec plataforma-postgres printenv POSTGRES_PASSWORD)"
podman pull docker.io/library/postgres:18

cat > "$DIR/inventario.sql" <<'SQL'
SELECT 'tabla', table_schema || '.' || table_name,
       (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from %I.%I', table_schema, table_name), false, true, '')))[1]::text
  FROM information_schema.tables
 WHERE table_type = 'BASE TABLE' AND table_schema NOT IN ('pg_catalog', 'information_schema')
UNION ALL
SELECT 'rls', c.oid::regclass::text, c.relrowsecurity::text || '/' || c.relforcerowsecurity::text
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE c.relkind IN ('r', 'p') AND n.nspname IN ('app', 'public')
UNION ALL
SELECT 'acl', c.oid::regclass::text, pg_get_userbyid(c.relowner) || ' ' || coalesce(c.relacl::text, '')
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE c.relkind IN ('r', 'p', 'S', 'v', 'm') AND n.nspname IN ('app', 'public')
UNION ALL
SELECT 'politica', schemaname || '.' || tablename || '.' || policyname, cmd || ' ' || array_to_string(roles, ',')
  FROM pg_policies
UNION ALL
SELECT 'disparador', tgrelid::regclass::text || '.' || tgname, tgenabled::text
  FROM pg_trigger WHERE NOT tgisinternal
UNION ALL
SELECT 'funcion', p.oid::regprocedure::text, pg_get_userbyid(p.proowner)
  FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
 WHERE n.nspname IN ('app', 'public')
UNION ALL
SELECT 'rol', rolname, rolsuper::text || '/' || rolbypassrls::text || '/' || rolcanlogin::text
  FROM pg_roles WHERE rolname LIKE 'plataforma%'
ORDER BY 1, 2;
SQL

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

# 4. Arrancar 18 sobre el volumen nuevo: la inicialización crea roles,
#    `plataforma` y `plataforma_test` (01-tenancy.sh, 02-tenancy-test-db.sh)
podman compose up -d postgres
timeout 180 sh -c 'until podman healthcheck run plataforma-postgres >/dev/null 2>&1; do sleep 2; done'
podman logs plataforma-postgres 2>&1 | grep -E 'PostgreSQL init process complete|ERROR|FATAL'

# 5. Sustituir la `plataforma` recién creada por la restaurada
podman exec plataforma-postgres dropdb -U plataforma plataforma
podman exec -i plataforma-postgres pg_restore -U plataforma --create --exit-on-error -d postgres \
  < "$DIR/plataforma.dump"

# 6. Comprobaciones (CA-061-03 a CA-061-06)
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

Después: `composer test` (suite completa, `ADR-060`) por el `verificador`, con el test de `§4.4` ya actualizado en la rama.

**Prueba de la reversión** (`CA-061-08`, `CLAUDE.md §9`), sin tocar el contenedor de 18: arrancar un 17 desechable sobre el volumen antiguo, sin red ni puertos, y comprobar que sus datos siguen ahí.

```bash
podman run -d --rm --name plataforma-pg17-reversion \
  -v plataforma-educativa_postgres-data:/var/lib/postgresql/data:Z docker.io/library/postgres:17
timeout 120 sh -c 'until podman exec plataforma-pg17-reversion pg_isready -U plataforma >/dev/null 2>&1; do sleep 2; done'
podman exec -i plataforma-pg17-reversion psql -U plataforma -d plataforma -At -v ON_ERROR_STOP=1 \
  < "$DIR/inventario.sql" > "$DIR/inventario-reversion.txt"
diff "$DIR/inventario-antes.txt" "$DIR/inventario-reversion.txt" && echo "volumen 17 utilizable"
podman stop plataforma-pg17-reversion
```

**Si el paso 5 o el 6 fallan**: no seguir; aplicar la reversión (`§8.1`). El volumen nuevo queda para diagnóstico; se puede repetir desde el paso 3 borrándolo **solo** con autorización expresa del usuario (`podman volume rm plataforma-educativa_postgres-cluster`).

**Conservación del volumen antiguo**: `plataforma-educativa_postgres-data` y el directorio `$DIR` se conservan **al menos hasta que la rama esté mezclada y se haya trabajado una semana sobre 18**. Borrarlos es decisión del usuario, no parte de este procedimiento. El volumen antiguo de prodlike (`plataforma-prodlike_prodlike-postgres-data`) es desechable y su borrado también se deja al usuario.

### 4.3 · Procedimiento en CI

Solo el cambio de imagen de `§4.1`. Los servicios no tienen volumen; el paso «Provisionar esquema y roles de tenancy» y el de «Crear las bases de la suite en paralelo» (`ADR-060`) recrean todo en cada ejecución, sin cambios.

### 4.4 · Test atado a la versión mayor (`CA-058-02`)

En `apps/api/tests/Feature/Curso/AcademicYearWriteGuardTest.php`: añadir `'10'` a `$postgresClasses`, cambiar `toBe(17)` por `toBe(18)` y los dos comentarios a «PostgreSQL 18». Es el mecanismo que `ADR-058` previó para cada subida, no un cambio de decisión. **No** se edita el comentario que cita PostgreSQL 17 en la migración ya publicada `2026_10_07_100200_create_academic_year_write_guard_function` (las migraciones publicadas no se reescriben); ni las de `2026_09_11_100100` y `2026_09_22_100200`, cuyo «`ADD COLUMN` instantáneo en PostgreSQL 17» sigue siendo cierto en 18.

### 4.5 · Producción (Quadlet)

**Hoy no hay producción ni datos reales** (`OPEN-11` sin resolver; `RUNBOOK.md §4`: «no aplica todavía»). Con este cambio, **la primera instalación real nacerá ya en 18**, con el volumen `postgres-cluster` vacío; no habrá ningún clúster 17 de producción que migrar.

El procedimiento siguiente queda escrito para el único caso en que haría falta (un host con un clúster 17 con datos, p. ej. un piloto instalado desde una etiqueta anterior a esta) y como patrón de la próxima subida mayor mientras no haya otro ADR. **Condición previa**: ensayarlo entero, reversión incluida, en `compose.prodlike.yaml` con datos sintéticos (`ADR-030`) y anotar el resultado en `SYSADMIN.md` antes de aplicarlo a datos reales.

1. **Ventana de mantenimiento**: `systemctl stop api@1.service web.service` (y cualquier *worker* que exista). Sin escrituras a partir de aquí.
2. **Inventario y volcados** con el cliente de 18 (`podman run --rm --network plataforma-net … postgres:18 pg_dump …`), como en `§4.2` pasos 0 y 2. El volcado contiene **datos personales reales**: se trata como una copia de seguridad (cifrado, fuera del host de aplicación cuando `REQ-BKP`/`OPEN-10` lo permitan, borrado al cerrar la ventana de reversión) y nunca se lleva a desarrollo (`ADR-030`).
3. `systemctl stop postgres.service`; `./infra/install.sh <tag con 18>`; `systemctl daemon-reload`; `systemctl start postgres.service` → inicialización sobre `postgres-cluster` con los *scripts* de `/opt/plataforma/postgres-init`.
4. `dropdb` + `pg_restore --create --exit-on-error` + inventario + `diff` + comprobaciones, como en `§4.2` pasos 5 y 6.
5. **Decisión de continuar o volver, antes de reabrir el tráfico.** Si continúa: `systemctl start api@1.service web.service` y prueba de humo.

## 5 · Motivo

- **El PR de Renovate solo no es desplegable**: el *entrypoint* de 18 rechaza tanto el montaje antiguo como los datos de 17 (`§1.1`). Hay que cambiar el montaje y migrar los datos en el mismo cambio.
- **Volcado y restauración (A)** es el método oficial, sin dependencias nuevas, que reconstruye índices (inmune a cambios de intercalación entre bases de sistema) y deja intacto el volumen antiguo: máxima reversibilidad con coste mínimo para el tamaño actual.
- **Restaurar solo la base de aplicación** y dejar roles y bases de test al aprovisionamiento existente reutiliza piezas ya probadas (`01-tenancy.sh`, `02-tenancy-test-db.sh`, `bases-test-paralelo.sh`) y evita errores esperados que habría que filtrar a mano.
- **Montar en `/var/lib/postgresql`** es lo que pide la imagen y deja preparada la opción de `pg_upgrade --link` para la próxima vez sin decidirla ahora.
- **Actualizar ahora** (decisión del usuario) es más barato que después: sin producción, sin datos reales, y con un solo volumen de desarrollo.

## 6 · Consecuencias

- Todos los clústeres nacen con *checksums* de datos activos.
- Cambia el nombre del volumen de PostgreSQL en los tres entornos con volumen; cualquier documentación o comando que cite `postgres-data` como volumen activo debe actualizarse (`SYSADMIN.md`, `RUNBOOK.md`).
- Un volcado hecho en 18 no se restaura de forma soportada en 17: **una vez reabierto el tráfico sobre 18, volver a 17 pierde lo escrito desde el corte** (`§8`).
- Documentación que se actualiza en la misma rama (no la escribe este ADR):

| Documento | Cambio |
|---|---|
| `SYSADMIN.md` | `§2`: `postgres` (18), volumen `postgres-cluster` montado en `/var/lib/postgresql`, `postgres-data` conservado como vuelta atrás. `§2b`: nota de que la inicialización también crea el clúster con *checksums*. Tabla de CI (`§` de workflows): «PostgreSQL 18 real». Versión e historial |
| `RUNBOOK.md` | Procedimiento nuevo «Subida de versión mayor de PostgreSQL (`ADR-061`)» con los comandos de `§4.2`, la prueba de reversión, la reversión de `§8` y el de producción de `§4.5`; y en `§2.2`, el síntoma del *entrypoint* de 18 que se niega a arrancar por datos antiguos y su causa. Versión e historial |
| `README.md` | Tabla de versiones: «PostgreSQL 18». Versión del documento |
| `ARCHITECTURE.md` | Fila de base de datos: «PostgreSQL 18». Versión |
| `CHANGELOG.md` | Entrada del 2026-10-10 con `ADR-061`, `#347` y lo ejecutado |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` | Cabecera e historial de versiones (la fila del índice `§18` ya la añade este ADR) |

## 7 · Sustitución parcial de `ADR-037`

En la tabla de ficheros de Quadlet de `ADR-037`, la fila «`postgres-data.volume`, `redis-data.volume`, `minio-data.volume` — Volúmenes nombrados» pasa a leerse con **`postgres-cluster.volume`** en lugar de `postgres-data.volume`. Nada más de `ADR-037` cambia.

## 8 · Reversión

### 8.1 · Desarrollo

- **Antes de mezclar** (o con la rama aún sin usar): `podman stop` y `podman rm` de `plataforma-web`, `plataforma-api` y `plataforma-postgres` (sin `-v`), volver a `develop` y `podman compose up -d`: arranca 17 sobre `postgres-data`, intacto. Lo escrito sobre 18 se pierde (datos sintéticos, aceptable). Probada por `CA-061-08`.
- **Después de mezclar**: lo mismo, mediante un PR que restaure imagen, montaje y volumen de 17, con un ADR que sustituya a este. Es reversible mientras exista el volumen antiguo.

### 8.2 · CI

Volver a `postgres:17` en los tres servicios y revertir `§4.4`. Sin estado que migrar.

### 8.3 · Producción (cuando exista)

Antes de reabrir el tráfico (`§4.5` paso 5): `systemctl stop postgres.service`, `./infra/install.sh <tag anterior>`, `systemctl daemon-reload`, `systemctl start postgres.service` (arranca 17 sobre el volumen antiguo, intacto), `systemctl start api@1.service web.service`. **Después de reabrir**, volver a 17 implica perder lo escrito en 18 o una migración lógica a la baja no soportada: la decisión se toma antes de reabrir, no después.

## 9 · Criterios de aceptación

- **CA-061-01** — `compose.yaml`, `compose.prodlike.yaml` y `postgres.container` usan `postgres:18` y montan su volumen en `/var/lib/postgresql`; ninguna definición de contenedor activa monta `/var/lib/postgresql/data` (comprobación por `grep` en la revisión). Existe `postgres-cluster.volume` y no `postgres-data.volume` en `infra/quadlet/`.
- **CA-061-02** — `ci-api.yml` usa `postgres:18` en sus tres servicios y los tres jobs quedan en verde en el PR.
- **CA-061-03** — En desarrollo, tras `§4.2`: `server_version` empieza por `18.` y `data_checksums` es `on`.
- **CA-061-04** — `diff` entre `inventario-antes.txt` e `inventario-despues.txt` vacío: mismas tablas con el mismo número de filas, mismos propietarios y privilegios, mismas políticas RLS, mismos disparadores (incluido el de `ADR-057` en cada tabla con `academic_year_id`), mismas funciones y mismos tres roles con los mismos atributos. `migrate:status` sin migraciones pendientes.
- **CA-061-05** — Ningún rol con contraseña MD5 (`pg_authid.rolpassword LIKE 'md5%'` = 0).
- **CA-061-06** — `\du` muestra `plataforma_owner`, `plataforma_app` y `plataforma_platform` sin `Superuser` y solo `plataforma_platform` con `Bypass RLS` (`SYSADMIN.md §2b`).
- **CA-061-07** — Existen `plataforma_test` y `plataforma_test_1..6` con `CONNECT` para los tres roles, y `composer test` (suite completa, `ADR-060`) termina en verde en desarrollo con el número real de tests anotado; `CA-058-02` pasa con la lista de PostgreSQL 18.
- **CA-061-08** — La prueba de reversión de `§4.2` da `diff` vacío entre `inventario-antes.txt` e `inventario-reversion.txt`.
- **CA-061-09** — `SYSADMIN.md`, `RUNBOOK.md`, `README.md`, `ARCHITECTURE.md`, `CHANGELOG.md` y la cabecera de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` recogen el cambio (`§6`), revisados por `doc-reviewer`.

## 10 · Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| **Mezclar #347 tal cual** | No arranca con ningún volumen (`§1.1`) y deja CI en 17: desarrollo y CI probarían motores distintos, contra `ADR-033 §10` |
| **`pg_upgrade` (B)** | Dependencia de terceros para un uso puntual, sin `--link` posible con el volumen actual, y sin ventaja con el tamaño de datos actual (`§3`) |
| **Volumen nuevo y *seed* sin restaurar (C)** | Válido en desarrollo, pero pierde el estado de trabajo y no ensaya el procedimiento que hará falta con datos reales |
| **Mantener `/var/lib/postgresql/data` fijando `PGDATA`** | Va contra la configuración que pide la imagen, arrastra el problema del enlace simbólico (`§1.1.3`) y renuncia a `pg_upgrade --link` en el futuro, a cambio de no renombrar un volumen |
| **Reutilizar el nombre `postgres-data` para el volumen nuevo** | Obligaría a borrar antes el volumen de 17, que es el camino de vuelta |
| **Desactivar los *checksums* (`--no-data-checksums`)** | Solo tendría sentido para hacer `pg_upgrade` desde un clúster sin ellos; no es el caso y se perdería la detección de corrupción |
| **Fijar una versión menor exacta (`18.x`)** | Hoy la mayor flotante con Renovate funciona igual para 17; cambiarlo es otra decisión, sin relación con esta subida |
| **Instalar el cliente de PGDG en CI de antemano** | Complejidad sin un fallo que la justifique (`§4.1`) |
