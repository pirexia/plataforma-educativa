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

`web` monta `apps/web/` igual que `api` (mismo `node_modules` del host: WSL2 es Linux, no hay problema de binarios nativos entre host y contenedor). `apps/web/.env` apunta `VITE_API_URL` a `http://localhost:8000/api` **sin cambiarlo dentro del contenedor**: quien ejecuta ese `fetch` es el navegador en Windows, no el contenedor, así que necesita la URL publicada en loopback, no el nombre de servicio interno.

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
| `ci-api.yml` | `Tests (Pest)`, `Lint (Pint)`, `Análisis estático (Larastan)` | Pest (`composer test`), Pint (`composer lint`), Larastan nivel 6 (`composer analyse`). PHP 8.4, sin contenedor: runner nativo con `shivammathur/setup-php`. Los tests usan SQLite en memoria (`phpunit.xml`), no requieren PostgreSQL en CI. |
| `ci-web.yml` | `Lint (ESLint)`, `Typecheck y build (vue-tsc + Vite)`, `Tests unitarios (Vitest)`, `Tests e2e (Playwright)` | ESLint, `vue-tsc -b` + build de Vite, Vitest, Playwright (Chromium, instalado con `--with-deps` en el propio job). El test e2e no depende de la API real: `HomeView` degrada a un mensaje de error visible si la petición falla, que es lo que el test comprueba. |
| `dependency-scan.yml` | `Trivy (composer.lock, package-lock.json)` | `aquasecurity/trivy-action` escanea `composer.lock` y `package-lock.json` en modo filesystem. Falla en severidad `HIGH`/`CRITICAL` con corrección disponible (`ignore-unfixed: true`). |

**Por qué Trivy y no `actions/dependency-review-action`**: se probó primero ese action nativo de GitHub, pero falla con *"Dependency review is not supported on this repository"* — el repo es privado y ese action necesita GitHub Advanced Security, que en una cuenta personal (no Enterprise) no se puede activar aunque se pague aparte. Trivy corre en el propio job sin depender de ninguna funcionalidad de plan de GitHub, y sin subir SARIF al tab Security (esa subida también está gateada por GHAS en repos privados).

**Pendiente de configurar manualmente** (no automatizable desde una sesión de Claude Code, requiere al propietario del repositorio):

1. **Branch protection** en `develop` y `main`: marcar como *required status checks* los ocho jobs de la tabla anterior (tres de `ci-api.yml`, cuatro de `ci-web.yml`, uno de `dependency-scan.yml`). Sin esto los workflows se ejecutan pero no bloquean el merge.
2. **Dependabot alerts**: activar en Settings → Security → *Dependabot alerts* para ver vulnerabilidades en dependencias ya mezcladas (Trivy en CI solo escanea lo que hay en cada PR en el momento de ejecutarse, no vigila el repo de forma continua).
3. **Renovate**: instalar la GitHub App desde `github.com/apps/renovate` sobre este repositorio. La configuración ya está en `renovate.json` (raíz): agrupa por `apps/api`/`apps/web`, ejecución semanal los lunes, sin automerge, alertas de vulnerabilidad con prioridad inmediata.
4. **Permiso de lectura de checks para el conector MCP de GitHub**: el PAT de grano fino usado por el `github` MCP de Claude Code (`claude mcp get github`) no tiene permiso de "Checks"/"Commit statuses", así que Claude Code no puede leer el resultado de estos workflows por API (`403`). Añadir esos dos permisos de solo lectura al token en https://github.com/settings/personal-access-tokens.

## 5. Pendiente de documentar aquí

- Alojamiento del piloto y producción (`OPEN-11`, bloqueante de H0).
- Quadlet/systemd para producción (`infra/`), cuando exista destino.
- Procedimiento de copia de seguridad (`REQ-BKP`, paso 1.26).
- Procedimiento de reversión de despliegue (sección 9 de `CLAUDE.md`).
