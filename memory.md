# memory.md — Estado del proyecto

> Fichero de estado entre sesiones. Claude lo lee al arrancar y lo actualiza tras cada hito.
> Mantenerlo **corto**: si supera unas 150 líneas, archiva lo antiguo en `docs/historial/`.

---

## Estado actual

**Fase**: 0 — Cimientos
**Paso activo**: 0.1-0.6 cerrados. **0.7 (núcleo multi-tenant, paso crítico): los 11 subpasos implementados y verificados (47 tests en `tests/Feature/Tenancy/`, Pint y Larastan en verde).** Pendiente antes de cerrar 0.7 del todo: revisión de `db-reviewer`/`security-reviewer` (obligatoria antes de mezclar a `develop`, `CLAUDE.md` §6), actualizar `PLAN-IMPLEMENTACION.md`, y mezclar `chore/checkpoint-sesion-0.7` (PR #5) y luego `feature/paso-0.7-nucleo-multitenant` a `develop`. Siguiente paso del plan tras cerrar 0.7: **0.8 · Modelo de datos núcleo**.
**Rama**: `feature/paso-0.7-nucleo-multitenant`, creada a partir de la punta de `chore/checkpoint-sesion-0.7` (que a su vez cuelga de `develop`, PR #5 todavía abierto y sin mezclar). `develop` sigue en `8b4c790`, sin cambios. Al mezclar: primero `chore/checkpoint-sesion-0.7` → `develop`, después `feature/paso-0.7-nucleo-multitenant` → `develop` (mismo orden en que se crearon).
**Última sesión (2026-08-17)**: aprobado `ADR-033` por el usuario ("ejecútalo") e implementados sus 11 subpasos de un tirón, sin pausas de confirmación (instrucción explícita del usuario: "continua y no preguntes en los siguientes pasos hasta que termines la 0.7"). Seguimiento de progreso por tareas (herramienta `Task*`) se desconectó a mitad de sesión; desde entonces el único sitio fiable es este fichero, sección `Trabajo en curso`, con el checklist completo y los hallazgos reales de cada subpaso (bugs propios encontrados y corregidos, no solo el código nuevo). Sesión previa (antes de esto) había cerrado el diseño (`ADR-033`) y corregido una discrepancia documental `ADR-024`/`ADR-027`/`ADR-030` no relacionada con 0.7 (`CHANGELOG.md` 2026-08-17), más el fix de filtros `paths:` en CI (PR #5, todavía por mezclar) y quedan pendientes los pasos manuales de GitHub (branch protection, Dependabot alerts, Renovate App — falló por error transitorio, permiso "Checks" del PAT).

---

## Decisiones tomadas

| ADR | Decisión |
|-----|----------|
| ADR-001 | Multi-tenant: BD compartida con `tenant_id` + RLS |
| ADR-002 | Monolito modular, no microservicios |
| ADR-004 | Borrado: lógico / anonimización / purga, en tres niveles |
| ADR-007 | Stack: Laravel + Vue 3 TS + PostgreSQL |
| ADR-008 | Móvil: PWA → Capacitor |
| ADR-015 | Segmento: concertados de Madrid, objetivo Colegio Miramadrid |
| ADR-016 | Complementarios a Raíces/Roble, no sustitutos. Excepción: 0-3 privado |
| ADR-020 | Régimen jurídico por etapa, no por tenant |
| ADR-021 | Idiomas: es-ES, en, de, fr |
| ADR-023 | UI: Tailwind + shadcn-vue + TanStack Table |
| ADR-024 | Infra: Compose sobre VPS → Kubernetes en E2 |
| ADR-025 | Auth SPA: cookie de sesión, prohibido JWT en localStorage |
| ADR-026 | Documentación híbrida: raíz + por módulo |
| ADR-027 | Podman sobre RHEL 10 en VM VMware; Quadlet en producción. Sustituido para desarrollo (E0) por ADR-030 |
| ADR-028 | Topología de red: frontend sin proxy, red externa, `Wants=` no `Requires=` |
| ADR-029 | `public_id` ULID en API y URLs; `TIMESTAMPTZ`, `text`, céntimos enteros |
| ADR-030 | Desarrollo en WSL2, equipo personal, **solo datos sintéticos** |
| ADR-031 | `REQ-TRAN` ampliado a 12 requisitos, SHOULD, fase 2 |
| ADR-032 | Lista maestra única de autorizados a recoger, en `REQ-FAM-UNIT-005` |

---

## Trabajo en curso

- **0.1 cerrado**: `LICENSE` propietaria (titular provisional: Andrés Matías López, pendiente de `OPEN-07`), `.gitignore` con patrones de Python, eliminado `SKILL.md` suelto de la raíz.
- **0.2 cerrado**: MCP de GitHub y Context7 conectados y verificados con las 4 pruebas de `docs/SETUP-ENTORNO.md` §7.4 (issue de prueba #1 creado y cerrado). Plugin `laravel/agent-skills` instalado. 9 agentes con modelo correcto, 10 skills.
- **0.3 cerrado**: Podman, red externa `plataforma-net` y `.wslconfig` ya estaban operativos de una sesión previa. `compose.yaml` con perfil reducido: `postgres` + `redis` + `api` + `web` arrancan por defecto y probados en `healthy`; `minio` detrás de `--profile full`. `SYSADMIN.md` v0.3.0. Pendiente a propósito: servicio de PDF (motor sin decidir, ver 1.17).
- **0.4 cerrado**: Laravel 13 en `apps/api` (PHP 8.4). `app/Modules/<Modulo>/{Domain,Application,Infrastructure,Http}` con autodescubrimiento de `ServiceProvider` (`App\Support\Modules\ModuleServiceProviderDiscovery`), sin módulos reales todavía. `GET /api/health` documentado en `openapi.yaml`. Pest (4 tests) y Larastan nivel 6 en verde. Contenedorizado (`infra/containers/api/Containerfile`), conexión a PostgreSQL verificada desde dentro del contenedor. `routes/web.php` vaciado y `resources/views/welcome.blade.php` eliminada: backend puramente API (`INV-006`), la SPA de `apps/web` es el único cliente web.
- **Bug propio detectado y corregido (0.4)**: el `Containerfile` inicial purgaba `libpq-dev`/`libzip-dev` con `--auto-remove` tras compilar las extensiones, lo que también se llevaba `libpq.so.5`/`libzip.so.4` (dependencias en tiempo de ejecución) y dejaba `pdo_pgsql`/`zip` sin cargar. El healthcheck no lo detectó porque no toca la base de datos — lo encontré al probar `php artisan db:show` dentro del contenedor. Corregido no purgando las `-dev` (imagen de desarrollo, no de producción).
- **0.5 cerrado**: Vue 3 + TS + Vite en `apps/web` (`npm create vite`, no `create-vue`: su instalador interactivo se colgó consumiendo CPU al 100% con `yes ""` — proceso matado a mano, ver P-02). Tailwind v4 + shadcn-vue inicializados con `--defaults --yes`; quitado el `@import` a Google Fonts que trae la plantilla por defecto (llamada a un tercero desde cada carga, cuestión de privacidad con un producto que trata datos de menores). `vue-router`, `AppLayout` + `HomeView` que consume `GET /api/health` de verdad. Cliente API propio en `src/api/client.ts` (fetch nativo, sin librería), con `credentials: 'include'` ya previsto para la cookie de sesión de `ADR-025`. ESLint (flat config) + Prettier, Vitest (4 tests) y Playwright (1 e2e) en verde. Contenedorizado igual que `api` (`infra/containers/web/Containerfile`, mismo `node_modules` del host montado por volumen).
- **MCP añadidos**: Laravel Boost (`composer require laravel/boost --dev` + `php artisan boost:install --mcp --no-interaction`) y Playwright (`@playwright/mcp`), declarados en `.mcp.json` de la raíz (versionado, sin secretos). PostgreSQL MCP pendiente de 0.8.
- **Bug propio detectado y corregido (sesión nueva, post-0.5)**: `laravel-boost` seguía sin conectar (`-32000: Connection closed`) al abrir la sesión nueva prevista para cargarlo. La ruta relativa `apps/api/artisan` en `.mcp.json` solo resuelve si el proceso de Claude Code arranca con cwd en la raíz del repo; esta sesión abrió en `docs/`, así que PHP fallaba con `Could not open input file`. `${CLAUDE_PROJECT_DIR}` no está disponible para expansión en `.mcp.json` en la versión instalada (2.1.231, confirmado por el propio diagnóstico de `claude mcp list`). Corregido envolviendo el comando en `sh -c 'cd "$(git rev-parse --show-toplevel)" && exec php apps/api/artisan boost:mcp'`, portable y sin rutas absolutas de un usuario concreto. Documentado en issue #2 (cerrado), commit `548df67` mergeado a `develop` en `c1160a6`. Verificado con `claude mcp list`: los seis MCP (GitHub, Context7, Playwright, Laravel Boost, MyInvestor, Google Drive) conectan.
- **P-01 posiblemente resuelto**: en esta sesión `spec-writer` sí aparece en la lista de subagentes disponibles. Pendiente confirmar en una sesión futura que no fue un efecto puntual antes de cerrar el problema abierto.
- **0.6 cerrado** (PR #4 mergeado a `develop` en `aa34d35`): `ci-api.yml` (Pest/Pint/Larastan sobre PHP 8.4, runner nativo, sin contenedor), `ci-web.yml` (ESLint/vue-tsc+build/Vitest/Playwright), `dependency-scan.yml` (Trivy filesystem, sin subir SARIF). Corregido de paso `apps/api/composer.json` (`^8.3` → `^8.4`, issue #3). `renovate.json` listo, App sin instalar todavía. Dos bugs encontrados y corregidos en el propio proceso: (1) el e2e de Playwright fallaba en el runner con timeout de `webServer` porque Vite escuchaba en `localhost` sin `--host` explícito (ambigüedad IPv4/IPv6 entre entornos) — fijado `--host 127.0.0.1` en `playwright.config.ts`; (2) `actions/dependency-review-action` no es viable en este repo (privado, sin GitHub Advanced Security disponible en cuenta personal) — sustituido por Trivy. Además, error propio de proceso: un `rm` fuera de `Edit`/`Write` se me olvidó stagear (`git add` solo cubrió los ficheros que creía haber tocado), dejando el workflow viejo trackeado y ejecutándose igualmente — corregido revisando `git status` completo. **Pendiente**: activar *branch protection* en GitHub con los ocho checks (3 de `ci-api.yml` + 4 de `ci-web.yml` + 1 de `dependency-scan.yml`) como *required status checks* (bloqueo de merge real, no automatizable desde Claude Code) — ver `SYSADMIN.md` §4.
- **Bug propio detectado y corregido (post-0.6, previo a activar branch protection)**: `ci-api.yml` y `ci-web.yml` tenían filtro `paths:` (solo se disparaban si el PR tocaba `apps/api/**`/`apps/web/**`). Si esos checks se marcan como *required* en branch protection, cualquier PR que no toque esas rutas (p.ej. uno que solo cambia `memory.md`) nunca los dispara y GitHub bloquea el merge para siempre ("Expected — waiting for status to be reported"). Corregido quitando los filtros `paths:`: ambos workflows corren siempre. Detectado antes de configurar branch protection, no en producción.
- **0.7 en implementación** (rama `feature/paso-0.7-nucleo-multitenant`). Diseño en `docs/adr/ADR-033-implementacion-del-aislamiento-multi-tenant.md`, sección "Plan de implementación" (11 subpasos, commits independientes). Checklist real de esta rama:
  - [x] **0.7.1** Provisión de BD (commit `eb1baa3`, ver arriba). Verificado: `\du` en `plataforma-postgres` muestra los tres roles con los atributos correctos; `podman exec plataforma-api php artisan tinker --execute="echo DB::selectOne('select current_user')->current_user;"` devuelve `plataforma_app`.
  - [x] **0.7.2** `TenantContext` (commit `632e1b4`): `app/Support/Tenancy/TenantContext.php`, singleton (`TenancyServiceProvider`) con `enter/leave/runFor/tenantId()`; `tenantId()` lanza `TenantContextMissing`. `enter()`/`leave()` fijan el GUC de Postgres y el prefijo de caché `t{id}:`. Listener de `ConnectionEstablished` en `TenancyServiceProvider::boot()`.
  - [x] **0.7.3 (parcial, lo imprescindible)**: conexiones `pgsql_owner`/`pgsql_platform` en `config/database.php`, verificadas (`php artisan migrate:status --database=pgsql_owner` y consulta `current_user` vía `pgsql_platform` funcionan). Falta lo no urgente: nada identificado todavía, revisar al llegar a 0.7.4/0.7.5 si hace falta algo más de esta conexión.
  - [x] **0.7.4** (commit `6976a6f`): tabla `tenants` (id + `public_id` ULID vía `App\Support\Database\HasPublicId`, reutilizable, slug único, name, status con CHECK de los 5 estados de REQ-BO-001, timestampsTz+softDeletesTz), política RLS propia `id = app.current_tenant_id()`. Modelo `App\Support\Tenancy\Tenant` en conexión `pgsql_platform` a propósito. 6 tests.
  - [x] **0.7.5** (commit `7b33856`): middleware `App\Http\Middleware\ResolveTenant`, `App\Support\Tenancy\TenantHost::slugFrom()`, `config/tenancy.php` (`base_domain`), primeras claves i18n (`lang/*/tenancy.php`, solo el mensaje de suspensión). 404/503 según estado, resolución cacheada 60s como array plano (cachear el Eloquent completo resultó frágil en Redis). Terminable (`leave()` en `terminate()`). 7 tests + verificación manual por HTTP real. **Bug de entorno encontrado y corregido, no relacionado con el código de 0.7**: `Containerfile` de `apps/api` necesitaba `--no-reload` en `php artisan serve` — sin él, Laravel filtra el entorno del proceso hijo a una lista blanca que no incluye `DB_HOST`/`REDIS_HOST`/variables propias, y toda petición HTTP real daba 500 vacío sin log. Imagen reconstruida.
  - [x] **0.7.6** (commit `f8d3342`): `TenantModel`+`BelongsToTenant`+`TenantScope`, `TenantContext::runAsPlatform()`/`isPlatformMode()`. 4 tests con modelo/tabla desechables. Deadlock real encontrado y documentado: no crear/borrar tablas de test por conexión distinta de la que las usa dentro del mismo test (afterEach corre antes del rollback de Laravel).
  - [x] **0.7.7** (commit `9876cf5`): `Queue::createPayloadUsing()` estampa `tenant_id`; `JobProcessing` entra, `JobProcessed`/`JobFailed`/`JobExceptionOccurred` salen; `Queue::looping()` aborta si el contexto no está limpio. `RunsPerTenant` para comandos. 8 tests. **Dos hallazgos reales, no hipotéticos, documentados en el commit y en el código**: (1) `Queue::$createPayloadCallbacks` es estático de clase, no atado al contenedor — se acumulaba en cada test/reboot de la app; corregido con `createPayloadUsing(null)` antes de re-registrar (mismo riesgo en un futuro Octane). (2) `PendingDispatch` envía el job en su `__destruct()`: si `dispatch()` es la expresión de retorno de un closure pasado a `runFor()`/`eachTenant()`, el envío ocurre DESPUÉS del `finally` que restaura el tenant — job etiquetado con el tenant equivocado. Aviso explícito en el docblock de `TenantContext::runFor()`.
  - [x] **0.7.8** (commit `3cb118a`): `TenantStorage::disk()` (prefijo `tenants/{public_id}/` vía `root` de Flysystem, local y s3 por igual), `TenantContext::rateLimitKey()` (solo la clave; los números de límite son de RMT-005/REQ-BO-003, no inventados aquí). 6 tests. **Diferido a propósito**: autorización de canales de difusión por tenant — no hay `routes/channels.php` ni ninguna función en tiempo real todavía, scaffolding sin consumidor real.
  - [x] **0.7.9** (commit `59b1672`): `config/tenancy.php` `shared_tables` (cuatro categorías; `users` anotada como temporal, pendiente de 0.8), `App\Support\Tenancy\TenantMigration::tenantTable()` (tenant_id+DEFAULT+único(tenant_id,id)+RLS+política en un sitio, por `pgsql_owner` explícito). 3 tests. **Hallazgo real corregido de paso**: `failed_jobs` tenía DML completo para `plataforma_app` desde los GRANT por defecto de 0.7.1 pese a no tener `tenant_id`/RLS — cualquier código de negocio podría leer/borrar fallos de otros tenants. Migración de endurecimiento: REVOKE SELECT/UPDATE/DELETE, deja solo INSERT.
  - [x] **0.7.10** (commit `632e1b4`): suite migrada de SQLite a PostgreSQL real (`plataforma_test`, ver `infra/containers/postgres/init/02-tenancy-test-db.sh` y `SYSADMIN.md` §2b), servicios `postgres:17`+`redis:7` añadidos a `ci-api.yml` con paso de aprovisionamiento de roles. `tests/TestCase.php` migra vía `pgsql_owner` una vez por proceso y envuelve cada test en `DatabaseTransactions` sobre `pgsql` (`plataforma_app`). Verificado: 10/10 tests, Pint y Larastan en verde dentro del contenedor.
  - [x] **0.7.11** (commit `45bfb1d`): `IsolationBatteryTest.php` mapea contra la tabla de diez tests del ADR. #2-6 ya vivían en ficheros previos (referenciados, no duplicados); nuevos: #7 (FK compuesta rechaza padre de otro tenant), #8 (test de esquema recorre `pg_tables` de verdad: tenant_id+RLS o registrada en `config('tenancy.shared_tables')`), #9 (por reflexión, no por `pest-plugin-arch`: la versión instalada no tiene expectativa de herencia — `withoutGlobalScope` fuera de `app/Modules` y todo modelo extiende `TenantModel`), #10 (rol sin `SUPERUSER` ni `BYPASSRLS`). **#1 diferido a propósito**: no hay endpoint de negocio real ni `REQ-AUTH` (1.2) todavía. **Con esto, los 11 subpasos de 0.7 están implementados**: 47 tests en `tests/Feature/Tenancy/`, estable en corridas repetidas.
  - **Reordenación deliberada respecto al ADR**: 0.7.2 necesitaba probarse contra PostgreSQL real (`set_config` no existe en SQLite), así que se adelantó lo imprescindible de 0.7.3 y el 0.7.10 completo antes de cerrar el test de 0.7.2. Documentado en el mensaje del commit `632e1b4`, no en silencio.
  - **Bug propio encontrado y corregido durante 0.7.10**: `phpunit.xml` sin `force="true"` en `<env>` no sobreescribía variables ya presentes como entorno real del contenedor (`apps/api/.env` vía `env_file`) — la suite llevaba desde el paso 0.4 corriendo silenciosamente contra la base de datos de desarrollo real en vez de la configuración de test documentada. Corregido con `force="true"` en todo el bloque + `tests/bootstrap.php` (que además sincroniza `$_ENV`→`$_SERVER`, porque `force` no toca `$_SERVER` y Laravel mira ahí primero). Detalle completo en `SYSADMIN.md` §2b y en el commit.
  - **Nota de proceso**: el seguimiento de estos 11 subpasos se llevaba con la herramienta `Task*` de la sesión, que se desconectó a mitad de trabajo. A partir de ahora el único sitio fiable para saber qué está hecho es este checklist — mantenerlo actualizado en cada commit, no confiar en herramientas de tareas efímeras para esto.
  - **Problema de entorno detectado (no del repositorio)**: a mitad de esta sesión, `/auto-mode-setup` añadió a `.claude/settings.json` la regla `"deny": ["Read(./.env.*)", ...]`. El patrón `./.env.*` es más amplio de lo que probablemente se pretendía: bloquea también `.env.example`/`apps/api/.env.example` (plantillas sin secretos, sí deben poder leerse y editarse) además de los `.env` reales (correcto que estén bloqueados). Mientras no se acote el patrón (p. ej. a `./.env` y `./**/.env` sin el `.*`, o añadiendo excepciones para `*.example`), cualquier sesión futura que necesite tocar un `.env.example` se topará con esto — avisar al usuario si vuelve a pasar.

---

## Bloqueantes

| ID | Descripción | Impacto |
|----|-------------|---------|
| H0 | Sin centro piloto comprometido ni ficheros de exportación de GQdalya | Criterio de salida de fase 1 y `REQ-ONB-003` |
| OPEN-11 | **Dónde se aloja el piloto**. WSL2 no puede alojar datos reales | Hito H0 |
| OPEN-07 | Entidad jurídica y contrato de encargado de tratamiento | Datos reales y facturación |
| OPEN-08 | Dominio y DNS con API para certificado comodín | Multi-tenant, fase 0 |
| OPEN-09 | Proveedor de correo transaccional | `REQ-AUTH`, `REQ-COM` |
| OPEN-10 | Almacenamiento de copias en proveedor distinto del host | `REQ-BKP` |

---

## Problemas abiertos

| ID | Descripción | Severidad |
|----|-------------|-----------|
| P-02 | `npm create vue@latest -- --typescript --router ...` no respeta sus propios flags: sigue pidiendo el nombre del paquete de forma interactiva. Intentar automatizarlo con `yes "" \| npm create vue@latest ...` cuelga el proceso al 100% de CPU en vez de fallar limpio. Usar `npm create vite@latest <dir> -- --template vue-ts` y añadir router/Tailwind/shadcn-vue/Vitest/Playwright/ESLint a mano (lo que se hizo en 0.5) evita el problema. No usar `create-vue` en `apps/web` sin resolver esto primero. | Baja |

---

## Siguiente paso concreto

1. **Cerrar 0.7 del todo**: lanzar `db-reviewer` (obligatorio, hay migraciones nuevas) y `security-reviewer` (obligatorio, aislamiento multi-tenant) sobre la rama `feature/paso-0.7-nucleo-multitenant` antes de mezclar; resolver lo que encuentren; actualizar `PLAN-IMPLEMENTACION.md` marcando 0.7 como cerrado; mezclar `chore/checkpoint-sesion-0.7` → `develop`, luego `feature/paso-0.7-nucleo-multitenant` → `develop`; borrar ambas ramas (local y remota) tras mezclar.
2. Paso **0.8 · Modelo de datos núcleo** `[OPUS]`: migraciones de `AcademicYear`, `Person`, `User`, `Role`, `Permission`, `AuditLog`, `ModuleSubscription` (además de `Tenant`, ya hecho en 0.7.4). Requiere Opus para el diseño (sección 16 del documento de requisitos).
3. Configurar a mano en GitHub (no automatizable desde Claude Code, ver `SYSADMIN.md` §4): **branch protection** con los ocho checks como *required status checks*, Dependabot alerts, GitHub App de Renovate. Falta el permiso "Checks: Read-only" en el PAT del conector `github` (`get_check_runs` sigue devolviendo `403`).
4. Decidir el motor de renderizado PDF (o posponerlo explícitamente a 1.17) antes de que haga falta en 1.17.
