# SYSADMIN.md

> **Versión 0.4.0** · 2026-08-14
> Documento vivo: se actualiza en cada fase (`CLAUDE.md` sección 6), no solo al final. Cubre por ahora únicamente el entorno de **desarrollo** en WSL2 (`ADR-030`); el alojamiento del piloto y de producción se documentará aquí cuando `OPEN-11` se resuelva.

---

## 1. Entorno de desarrollo (WSL2)

Máquina personal, Ubuntu sobre WSL2. Ver `docs/SETUP-ENTORNO.md` para la puesta en marcha completa desde cero.

### 1.1 Límite de recursos

`.wslconfig` (en `%UserProfile%` de Windows):

```
[wsl2]
memory=10GB
processors=6
swap=4GB
```

### 1.2 Podman

Rootless, con socket de usuario persistente:

```bash
systemctl --user enable --now podman.socket
sudo loginctl enable-linger $USER   # los contenedores sobreviven al cierre de la terminal
```

Registros configurados en `~/.config/containers/registries.conf` (`docker.io`, `quay.io`, `ghcr.io`).

### 1.3 Red

Una única red externa, creada una vez y **nunca destruida** (`ADR-028`):

```bash
podman network create --subnet 10.89.10.0/24 plataforma-net
```

`podman compose down` está prohibido salvo en un entorno completamente desechable: borra la red y rompe la resolución de nombres entre servicios.

---

## 2. `compose.yaml`

Vive en la raíz del repositorio. Perfil reducido por defecto (`ADR-030`): solo lo imprescindible para trabajar a diario.

| Servicio | Perfil | Puerto (solo loopback) |
|----------|--------|------------------------|
| `postgres` (17) | por defecto | `127.0.0.1:5432` |
| `redis` (7) | por defecto | `127.0.0.1:6379` |
| `api` (Laravel, PHP 8.4) | por defecto | `127.0.0.1:8000` |
| `web` (Vue 3 + Vite) | por defecto | `127.0.0.1:5173` |
| `minio` | `full` | `127.0.0.1:9000` (API), `127.0.0.1:9001` (consola) |

`api` monta `apps/api/` como volumen (código en vivo, sin rebuild para cada cambio) y usa `apps/api/.env`, con `DB_HOST` y `REDIS_HOST` sobreescritos a los nombres de servicio (`postgres`, `redis`) porque dentro de la red de contenedores no valen `127.0.0.1`. Para Artisan desde el host (`php artisan migrate`, etc.) el `.env` sigue apuntando a `127.0.0.1` con los puertos publicados.

`web` monta `apps/web/` igual que `api` (mismo `node_modules` del host: WSL2 es Linux, no hay problema de binarios nativos entre host y contenedor). `apps/web/.env` apunta `VITE_API_URL` a `http://localhost:8000/api/v1` **sin cambiarlo dentro del contenedor**: quien ejecuta ese `fetch` es el navegador en Windows, no el contenedor, así que necesita la URL publicada en loopback, no el nombre de servicio interno. **Desde `REQ-AUTH` (1.2, issue [#71](https://github.com/pirexia/plataforma-educativa/issues/71)), `compose.yaml` sobrescribe ese valor** a `http://demo.plataforma.test:8000/api/v1` — ver `§2c` para el porqué y el requisito de `hosts` que conlleva.

```bash
# Arranque diario
podman compose up -d

# Con almacenamiento de objetos, cuando se prueba subida de ficheros
podman compose --profile full up -d

# Estado
podman compose ps

# NUNCA: podman compose down (ver 1.3)
podman compose stop
```

Credenciales en `.env` (gitignored), a partir de `.env.example`.

**Pendiente, a propósito:**
- El servicio de renderizado HTML→PDF no está definido todavía: falta decidir el motor concreto (ver paso 1.17 del plan). No se ha fijado una dependencia sin esa decisión.
- Workers de Horizon se añaden cuando haya colas reales que procesar (a partir de 1.1).

---

## 2b. Provisión de tenancy (`ADR-033`)

El aislamiento multi-tenant (paso 0.7) necesita, además de la base de datos, un esquema `app`, una función auxiliar y **tres roles de PostgreSQL** que no crea Laravel: son objetos de clúster, no del ORM.

`infra/containers/postgres/init/01-tenancy.sh` + `01-tenancy.sql.tpl` los provisionan. La imagen oficial de `postgres` ejecuta automáticamente cualquier script en `docker-entrypoint-initdb.d/` (montado ahí por `compose.yaml`) **solo cuando el volumen se inicializa por primera vez**. En un volumen ya existente (el caso normal al añadir esto a un entorno en marcha) hay que aplicarlo a mano una vez:

```bash
podman exec -i plataforma-postgres psql -v ON_ERROR_STOP=1 \
  --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  -v owner_password="$TENANCY_OWNER_PASSWORD" \
  -v app_password="$TENANCY_APP_PASSWORD" \
  -v platform_password="$TENANCY_PLATFORM_PASSWORD" \
  -v dbname="$POSTGRES_DB" \
  < infra/containers/postgres/init/01-tenancy.sql.tpl

# Y, si ya había tablas creadas por el rol bootstrap (típico la primera vez):
podman exec -i plataforma-postgres psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'EOF'
DO $$
DECLARE r RECORD;
BEGIN
    FOR r IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP
        EXECUTE format('ALTER TABLE public.%I OWNER TO plataforma_owner', r.tablename);
    END LOOP;
    FOR r IN SELECT sequencename FROM pg_sequences WHERE schemaname = 'public' LOOP
        EXECUTE format('ALTER SEQUENCE public.%I OWNER TO plataforma_owner', r.sequencename);
    END LOOP;
END
$$;
EOF
```

El script es idempotente (vuelve a ejecutarse sin duplicar roles ni romper permisos), así que también sirve como comprobación de que todo sigue como debería.

| Rol | Uso | Atributos |
|-----|-----|-----------|
| `plataforma_owner` | Propietario de las tablas. Ejecuta las migraciones (`php artisan migrate --database=pgsql_owner`) | Sin `SUPERUSER`, sujeto a sus propias políticas RLS por `FORCE` |
| `plataforma_app` | Runtime de la API y de los workers (conexión `pgsql`, la que usa Laravel por defecto) | Sin `SUPERUSER`, **sin `BYPASSRLS`** |
| `plataforma_platform` | Backoffice y mantenimiento entre tenants (conexión `pgsql_platform`) | `BYPASSRLS`, credenciales propias |

`POSTGRES_USER` (`plataforma`, superusuario de la imagen oficial) queda **solo** para tareas de arranque del contenedor: no lo usa la aplicación.

**Verificación**: `podman exec plataforma-postgres psql -U plataforma -d plataforma -c '\du'` debe mostrar los tres roles sin `Superuser`, y solo `plataforma_platform` con `Bypass RLS`.

### Base de datos de test

`infra/containers/postgres/init/02-tenancy-test-db.sh` crea además `plataforma_test` (mismo clúster, mismos roles — son objetos de clúster, no de una base concreta) y le aplica el mismo `01-tenancy.sql.tpl`. Automático en volumen nuevo; en uno existente, a mano una vez:

```bash
podman exec -i plataforma-postgres psql -v ON_ERROR_STOP=1 --username plataforma --dbname plataforma \
  -v test_db="plataforma_test" -v owner="plataforma" <<'EOF'
SELECT format('CREATE DATABASE %I OWNER %I', :'test_db', :'owner')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'test_db') \gexec
EOF

podman exec -i plataforma-postgres psql -v ON_ERROR_STOP=1 --username plataforma --dbname plataforma_test \
  -v owner_password="$TENANCY_OWNER_PASSWORD" -v app_password="$TENANCY_APP_PASSWORD" \
  -v platform_password="$TENANCY_PLATFORM_PASSWORD" -v dbname="plataforma_test" \
  < infra/containers/postgres/init/01-tenancy.sql.tpl
```

`apps/api/phpunit.xml` (ADR-033 §10) apunta `DB_DATABASE` a `plataforma_test` con `force="true"` en todas sus variables de entorno — **imprescindible**: PHPUnit sin `force` no sobreescribe una variable que ya exista como entorno real del proceso, y `apps/api/.env` (vía `env_file` en `compose.yaml`) ya define todas estas claves. Sin `force`, la suite entera es un no-op silencioso que corre contra la base de datos de desarrollo real — así estuvo desde el paso 0.4 hasta que se detectó en 0.7. Además, `force="true"` solo actualiza `$_ENV`/`getenv()`, no `$_SERVER` (que es lo que Laravel mira primero), así que `apps/api/tests/bootstrap.php` sincroniza ambos antes de que la aplicación arranque.

Las migraciones de test corren una vez por proceso vía la conexión `pgsql_owner` (`Tests\TestCase::setUp()`); cada test va envuelto en una transacción sobre `pgsql` (rol `plataforma_app`, el mismo que usa la API real) con `DatabaseTransactions`, no `RefreshDatabase`. Correr los tests como `plataforma_app` y no como `plataforma_owner` importa: si faltara un `GRANT` que la API necesita de verdad, un test que corriera como el propietario no lo detectaría.

`CACHE_STORE=redis` en los tests (no `array`): el prefijo de caché por tenant se aplica reconstruyendo el store con `Cache::forgetDriver()`, y el store `array` pierde todo su contenido en cada reconstrucción (es un array en memoria de la instancia, no un backend compartido), así que un test de aislamiento de caché sobre `array` daría verde sin haber probado nada. `REDIS_CACHE_DB=2` en tests, distinto del `1` de desarrollo, para no compartir claves.

---

## 2c. Variables de entorno propias de la aplicación

Más allá de credenciales de base de datos/Redis (`.env.example`), la aplicación tiene variables de configuración propias que un operador podría necesitar tocar:

| Variable | Por defecto | Fichero | Para qué |
|----------|-------------|---------|----------|
| `AUDIT_MAX_VALUE_LENGTH` | `256` | `apps/api/config/audit.php` | Tope de caracteres (sobre el valor codificado en JSON) que un valor de `audit_logs.changes` puede alcanzar antes de redactarse como `oversized` (`ADR-035` §5). Nunca se trunca: o entra entero, o no entra. Subirlo aumenta el tamaño medio de fila de la tabla más grande del sistema; bajarlo redacta más agresivamente. Sin necesidad de reiniciar más que el propio proceso PHP (config cacheable estándar de Laravel). |
| `SESSION_LIFETIME` | `120` (skeleton de Laravel, **insuficiente**) | `apps/api/.env` | **Requisito de despliegue de `REQ-AUTH` (1.2, issue [#62](https://github.com/pirexia/plataforma-educativa/issues/62)).** Debe ser `>= AUTH_SESSION_TIMEOUT_MAX_MINUTES` (480 por defecto, `RN-AUTH-30`) en **todos** los entornos: `SessionEnvironmentGuard` lo comprueba en cada arranque de la aplicación (no solo producción, issue #8) y **aborta con `RuntimeException` si no se cumple** — un `SESSION_LIFETIME` insuficiente tumba el contenedor entero, no solo las rutas de `REQ-AUTH`. Verificar y corregir `apps/api/.env` **antes** de desplegar 1.2 en cualquier entorno nuevo, incluido el propio WSL2 de desarrollo si se reprovisiona desde cero. Ocurrió de verdad en esta sesión (2026-08-22): ver issue #62 para el incidente completo y el parche temporal en `compose.yaml` (`environment: SESSION_LIFETIME: "480"`, pendiente de trasladar a `.env` de verdad). |
| `AUTH_SESSION_TIMEOUT_MAX_MINUTES` | `480` | `apps/api/config/auth-local.php` | Techo del rango que un centro puede configurar en `session_timeout_minutes` (`PATCH /tenant/settings`, grupo `security`, rango `5-480`, `RN-AUTH-30`). Subirlo exige subir `SESSION_LIFETIME` a la par, o `SessionEnvironmentGuard` aborta el arranque (ver fila anterior). |
| `AUTH_LOCKOUT_MINUTES` | `15` | `apps/api/config/auth-local.php` | Caducidad automática del bloqueo de cuenta tras 5 intentos fallidos (`RN-AUTH-14`, `OPEN-AUTH-03`). No sustituye al desbloqueo por correo ni por administrador. |
| `SESSION_DRIVER` | `database` (**obligatorio**) | `apps/api/.env` | **Requisito de despliegue de `REQ-AUTH-005` (1.2b, `RN-AUTH-49`).** `SessionEnvironmentGuard` aborta el arranque de la aplicación, en todos los entornos, si vale cualquier otra cosa: con otro driver, `DatabaseSessionRevoker` no tiene fila que borrar y la revocación del panel de sesiones respondería `204` sin haber cerrado nada. Verificar `apps/api/.env` **antes de la ventana de despliegue**, no después de que el *healthcheck* empiece a fallar (`operacion.md §B.6`, issue [#62](https://github.com/pirexia/plataforma-educativa/issues/62)). |
| `AUTH_DEVICE_COOKIE_TTL_DAYS` | `365` | `apps/api/config/auth-local.php` | Vida de la cookie `pge_device` que sostiene la detección de dispositivo nuevo (`RN-AUTH-45`, 1.2b). |
| `AUTH_NEW_DEVICE_ALERTS_PER_DAY` | `5` | `apps/api/config/auth-local.php` | Tope de alertas de dispositivo nuevo por usuario y día natural (`RN-AUTH-46`, 1.2b). Puesto sin medición: revisar con `REQ-SEED` (1.15b) antes de considerarlo definitivo (`operacion.md §B.2.1`). |
| `AUTH_USER_SESSION_RETENTION_DAYS` | `90` | `apps/api/config/auth-local.php` | Purga física de `user_sessions` cerradas (`PurgeUserSessions`, `datos.md §B.7`, 1.2b). |
| `AUTH_KNOWN_DEVICE_RETENTION_DAYS` | `365` | `apps/api/config/auth-local.php` | Purga física de `user_known_devices` sin uso (`PurgeUserKnownDevices`, `datos.md §B.7`, 1.2b). |
| `AUTH_USER_AGENT_MAX_LENGTH` | `1024` | `apps/api/config/auth-local.php` | Truncado del `User-Agent` antes de persistirlo en `user_sessions`/`user_known_devices` (1.2b), para que una cabecera hostil no entre tal cual en una tabla de tenant. |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:5173`, sobrescrito por `compose.yaml` a `http://demo.plataforma.test:5173` | `apps/api/config/cors.php` | Orígenes desde los que el navegador puede hacer peticiones con `credentials: 'include'` (issue [#68](https://github.com/pirexia/plataforma-educativa/issues/68)). **Solo importa en desarrollo**: producción/*staging* comparten origen SPA+API vía Traefik (`ADR-028`), sin petición cross-origin de por medio. Lista separada por comas si se añade un puerto de depuración adicional. Ver la fila `VITE_API_URL` de abajo para el porqué del valor actual. |
| `VITE_API_URL` | `http://localhost:8000/api/v1` (`apps/web/.env`), sobrescrito por `compose.yaml` a `http://demo.plataforma.test:8000/api/v1` | `apps/web/.env` / `compose.yaml` (servicio `web`) | **Requisito de despliegue de `REQ-AUTH` (1.2, issue [#71](https://github.com/pirexia/plataforma-educativa/issues/71)).** La resolución de tenant por subdominio (`ADR-014`) exige que el host de cada petición a la API termine en `TENANCY_BASE_DOMAIN`; un valor de host plano (`localhost`) hace que `TenantHost::slugFrom()` no resuelva ningún tenant y el login por navegador dé `404`. Pero apuntar solo la API al host de tenant **sin mover también la SPA** no basta: `document.cookie` de una página en `localhost` no puede leer una cookie fijada por `demo.plataforma.test` (el puerto no importa, el host sí) — el síntoma pasa de `404` a `419` (CSRF). La SPA debe navegarse por `http://demo.plataforma.test:5173/`, no por `http://localhost:5173/`, y por eso `CORS_ALLOWED_ORIGINS` (fila de arriba) también cambia. Requiere además `apps/web/vite.config.ts` con `server.allowedHosts` (Vite ≥ 6 bloquea con `403` cualquier `Host` no reconocido) y una entrada en el `hosts` del sistema que ejecuta el navegador — en WSL2, el de **Windows**, no el de la distribución: `127.0.0.1 demo.plataforma.test`. Parche temporal fijado a un solo tenant de desarrollo (`demo`); ver el issue para la decisión definitiva pendiente. |

## 3. Comprobación rápida

```bash
podman compose ps                                    # los cuatro servicios "healthy"
podman exec plataforma-postgres pg_isready -U plataforma
podman exec plataforma-redis redis-cli ping
curl -s http://127.0.0.1:8000/api/health
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:5173/
```

---

## 4. CI/CD (GitHub Actions)

Tres workflows en `.github/workflows/`, disparados por `push`/`pull_request` sobre `develop` y `main`. `ci-api.yml` y `ci-web.yml` corrían solo si el PR tocaba `apps/api/**`/`apps/web/**` respectivamente; se quitó ese filtro `paths:` porque combinado con *required status checks* en branch protection dejaba bloqueado para siempre cualquier PR que no tocara esas rutas (p.ej. uno que solo cambia documentación) — GitHub no considera "aprobado" un check requerido que nunca se dispara. Ahora los tres workflows corren siempre.

| Workflow | Jobs (nombre mostrado en GitHub) | Qué cubre |
|----------|------|-----------|
| `ci-api.yml` | `Tests (Pest)`, `Lint (Pint)`, `Análisis estático (Larastan)` | Pest (`composer test`), Pint (`composer lint`), Larastan nivel 6 (`composer analyse`). PHP 8.4, sin contenedor: runner nativo con `shivammathur/setup-php`. Los tests corren contra **PostgreSQL 17 real** como *service* del job, aprovisionado con el mismo script de roles/RLS que desarrollo (`infra/containers/postgres/init/01-tenancy.sql.tpl`) — no SQLite: `ADR-033` §10 exige que la batería de aislamiento se ejecute contra RLS de verdad, que SQLite no tiene. |
| `ci-web.yml` | `Lint (ESLint)`, `Typecheck y build (vue-tsc + Vite)`, `Tests unitarios (Vitest)`, `Tests e2e (Playwright)` | ESLint + comprobación de literales sin traducir (`INV-009`, `npm run lint:i18n`, mismo job a propósito — ver comentario en `ci-web.yml`), `vue-tsc -b` + build de Vite, Vitest, Playwright (Chromium, instalado con `--with-deps` en el propio job). El test e2e no depende de la API real: `HomeView` degrada a un mensaje de error visible si la petición falla, que es lo que el test comprueba. |
| `dependency-scan.yml` | `Trivy (composer.lock, package-lock.json)` | `aquasecurity/trivy-action` escanea `composer.lock` y `package-lock.json` en modo filesystem. Falla en severidad `HIGH`/`CRITICAL` con corrección disponible (`ignore-unfixed: true`). |

**Por qué Trivy y no `actions/dependency-review-action`**: se probó primero ese action nativo de GitHub, pero falla con *"Dependency review is not supported on this repository"* — el repo es privado y ese action necesita GitHub Advanced Security, que en una cuenta personal (no Enterprise) no se puede activar aunque se pague aparte. Trivy corre en el propio job sin depender de ninguna funcionalidad de plan de GitHub, y sin subir SARIF al tab Security (esa subida también está gateada por GHAS en repos privados).

**Pendiente de configurar manualmente** (no automatizable desde una sesión de Claude Code, requiere al propietario del repositorio):

1. **Branch protection** en `develop` y `main`: marcar como *required status checks* los ocho jobs de la tabla anterior (tres de `ci-api.yml`, cuatro de `ci-web.yml`, uno de `dependency-scan.yml`). Sin esto los workflows se ejecutan pero no bloquean el merge.
2. **Dependabot alerts**: activar en Settings → Security → *Dependabot alerts* para ver vulnerabilidades en dependencias ya mezcladas (Trivy en CI solo escanea lo que hay en cada PR en el momento de ejecutarse, no vigila el repo de forma continua).
3. **Renovate**: instalar la GitHub App desde `github.com/apps/renovate` sobre este repositorio. La configuración ya está en `renovate.json` (raíz): agrupa por `apps/api`/`apps/web`, ejecución semanal los lunes, sin automerge, alertas de vulnerabilidad con prioridad inmediata.
4. **Permiso de lectura de checks para el conector MCP de GitHub**: el PAT de grano fino usado por el `github` MCP de Claude Code (`claude mcp get github`) no tiene permiso de "Checks"/"Commit statuses", así que Claude Code no puede leer el resultado de estos workflows por API (`403`). Añadir esos dos permisos de solo lectura al token en https://github.com/settings/personal-access-tokens.

## 5. Pendiente de documentar aquí

- Alojamiento del piloto y producción (`OPEN-11`, bloqueante de H0).
- Procedimiento de copia de seguridad (`REQ-BKP`, paso 1.26).

## 6. Portabilidad del despliegue (`ADR-037`, paso `0.9b`)

**No se espera ya a que exista destino** (`OPEN-11`). Lo que sigue está escrito y, salvo que se diga explícitamente lo contrario, **probado de verdad en WSL2**, no simulado — `CLAUDE.md §0` prohíbe declarar probado lo que no se ha verificado.

### 6.1 Qué existe

| Pieza | Dónde | Estado |
|-------|-------|--------|
| `Containerfile` multi-etapa de `api` (FrankenPHP, modo clásico) | `infra/containers/api/Containerfile` | Construido de verdad (`podman build --target prod`), imagen arranca |
| `Containerfile` multi-etapa de `web` (nginx de estáticos, sin `proxy_pass`) | `infra/containers/web/Containerfile` | Construido de verdad, imagen arranca |
| Publicación de imágenes en GHCR | `.github/workflows/build-images.yml` | Escrito, con retención desde el primer commit (`ADR-037 §5.1`). **No verificado de extremo a extremo**: requiere un `push` real a `develop` o un PR, que esta sesión de implementación no puede disparar por sí misma |
| Unidades Quadlet (`plataforma.network`, `postgres`/`redis`/`api@`/`web`/`traefik`, `plataforma-migrate`) | `infra/quadlet/` | Validación en seco correcta con **ambos** generadores (`podman-system-generator` y `podman-user-generator`, `-dryrun`): las 10 unidades generan systemd válido sin errores, `ExecStart=`/`Wants=`/`After=`/`HealthCmd=` correctos. **Arranque real automático desde `~/.config/containers/systemd/` NO verificado en este host** — ver §6.2, bloqueado por un problema de permisos preexistente, no por las unidades |
| Topología completa (red, `postgres`/`redis`/`api`/`web`/`traefik`, imágenes `prod` reales) | `infra/compose/compose.prodlike.yaml` | **Arrancada de verdad** (`podman compose up -d`) y usada para ejecutar las tres pruebas obligatorias de `ARCHITECTURE.md §4.3` — ver §6.3, las tres pasaron |
| Instalador (sustitución de *tag*, `daemon-reload`) | `infra/install.sh` | Escrito; la parte de sustitución de `__TAG__` y copia de ficheros es lógica simple ya ejercida a mano durante la prueba de generación. El flujo completo con `--user` no se pudo ejecutar por el mismo bloqueo de §6.2 |
| Convención de secretos (`EnvironmentFile=`, plantilla sin valores) | `infra/quadlet/plataforma.env.example`, `RUNBOOK.md §3b.1` | Escrita y **corregida tras un bug propio real** (ver §6.4): sin `DB_CONNECTION=pgsql` explícito, Laravel cae a SQLite por defecto — descubierto al conectar la API de verdad contra PostgreSQL en `compose.prodlike.yaml`, no en teoría |
| Procedimiento de despliegue y de reversión | `RUNBOOK.md §3b` | Escrito. La reversión (bajar *tag*, reiniciar unidad) no se pudo probar en una unidad Quadlet real por el bloqueo de §6.2, pero es equivalente a lo ya verificado en las pruebas de resiliencia (recrear un contenedor con una imagen distinta sin romper el enrutado, §6.3 prueba C) |

### 6.2 Bloqueante de entorno: `~/.config/containers` con propietario incorrecto

**No es un fallo de las unidades Quadlet ni de `install.sh`.** En este host, `~/.config/containers` pertenece a `root:root` (`drwxr-xr-x`), probablemente por un `sudo` anterior no relacionado con este paso — el usuario normal no tiene permiso de escritura para crear `~/.config/containers/systemd/`, que es donde Quadlet busca las unidades por defecto en modo `--user`.

Comprobado que no es un problema de las unidades: `systemctl --user set-environment QUADLET_UNIT_DIRS=...` seguido de `daemon-reload` **no** hace que el generador real recoja el directorio alternativo (los generadores de systemd se invocan con un entorno propio, no heredan `set-environment`) — solo funciona invocando el binario del generador a mano con la variable en el propio comando, que es como se hizo la validación en seco de §6.1.

**Sin acceso a `sudo` interactivo desde esta sesión** (`sudo -n true` falla: pide contraseña), así que no se ha podido corregir. Comando de corrección, para ejecutar manualmente:

```bash
sudo chown -R "$USER:$USER" ~/.config/containers
```

Tras corregirlo, `infra/install.sh <tag> --user` debería funcionar tal cual está escrito. **No se ha vuelto a intentar el arranque automático después de este hallazgo** porque corregir permisos del sistema con `sudo` está fuera del alcance de lo que una sesión de implementación debe hacer sin que se le pida explícitamente.

### 6.3 Las tres pruebas obligatorias de `ARCHITECTURE.md §4.3` — resultado real

Ejecutadas contra `compose.prodlike.yaml` con las imágenes `prod` reales (no simulado, no las imágenes de desarrollo):

| # | Prueba | Resultado |
|---|--------|-----------|
| A | Reiniciar la API sin que caiga el frontend | **Pasa.** `podman restart` sobre el contenedor de la API; `web` siguió respondiendo `200` durante todo el reinicio, sin interrupción |
| B | Reiniciar PostgreSQL y que la API reconecte sola | **Pasa, verificado con una consulta real a la base de datos** (`php artisan db:show` desde el contenedor de la API, que nunca se reinició), no con el *endpoint* `/api/health` — se descubrió que ese *endpoint* no toca la base de datos en absoluto, así que reutilizarlo aquí habría sido una verificación falsa |
| C | Recrear la API y que Traefik siga enrutando | **Pasa.** `podman rm -f` + recreación con `podman compose up -d api`; la IP del contenedor cambió (confirmado, `10.89.0.5` tras recrear); Traefik enrutó a la IP nueva sin ninguna intervención manual, por descubrimiento vía el socket de Podman (`ADR-028 §4`) |

Las tres se ejecutaron en ese orden, sobre la misma pila levantada una sola vez, sin reiniciar nada entre pruebas salvo lo que cada prueba pedía.

### 6.4 Bug propio encontrado y corregido: `DB_CONNECTION` ausente

Al conectar la imagen `prod` real de la API contra PostgreSQL por primera vez (preparando la prueba B), `php artisan db:show` falló con un error de SQLite (`database.sqlite` no existe). `apps/api/config/database.php` usa `env('DB_CONNECTION', 'sqlite')` — sin la variable, Laravel asume SQLite por defecto, y la imagen `prod` no lleva ni `.env` ni `database.sqlite`. `infra/quadlet/plataforma.env.example` (la plantilla real de producción) tenía el mismo hueco: le faltaban `DB_CONNECTION`, `DB_DATABASE`, `DB_USERNAME`/`DB_PASSWORD` y los pares `DB_OWNER_*`/`DB_PLATFORM_*` de los tres roles de `ADR-033`. Corregido en la plantilla y en `compose.prodlike.yaml`; documentada además la relación obligatoria entre `TENANCY_*_PASSWORD` (los crea el aprovisionamiento de PostgreSQL) y `DB_*_PASSWORD` (los usa Laravel para conectar) — son el mismo secreto, no dos independientes, y generarlos por separado habría roto la conexión (`RUNBOOK.md §3b.1`).

### 6.5 Qué NO se puede verificar en WSL2, y queda escrito sin probar

`ADR-037 §6.5` punto 3, literal:

- **SELinux en `enforcing`**: WSL2 no lo tiene. Las etiquetas `:Z` de los volúmenes están escritas en todas las unidades, pero no se ha probado que SELinux las respete de verdad en el host de destino.
- **`loginctl enable-linger` y arranque en el arranque real del sistema**: en WSL2 el usuario ya está "siempre activo" al abrir una sesión; no hay equivalente real a un reinicio de servidor sin login.
- **TLS con certificado comodín**: bloqueado por `OPEN-08` (dominio y DNS, paso `0.10b`). La unidad de Traefik sirve solo HTTP hoy.
- **Cifras de rendimiento de cualquier tipo**: `ADR-030` ya advierte que las mediciones en este equipo son orientativas, no concluyentes.

### 6.6 Riesgo de cuota de GHCR (`ADR-037 §5.1`)

Plan de GitHub de este repositorio: **Free** (confirmado por el propietario el 2026-08-18, no asumido). Límite aproximado para paquetes privados: del orden de 500 MB de almacenamiento y 1 GB/mes de transferencia — **cifra exacta a reconfirmar en `docs.github.com`**, puede cambiar. La política de retención de `build-images.yml` (10 últimas versiones `sha-` de `develop`, todas las `vX.Y.Z` conservadas siempre) está activa desde el primer commit del workflow, no como mejora posterior. Si la cuota resultara insuficiente en la práctica, la salida documentada en el ADR es un registro propio (`registry:2`) en el VPS cuando exista, sin cambios en la aplicación.
