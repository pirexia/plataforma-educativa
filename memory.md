# memory.md — Estado del proyecto

> Fichero de estado entre sesiones. Claude lo lee al arrancar y lo actualiza tras cada hito.
> Mantenerlo **corto**: si supera unas 150 líneas, archiva lo antiguo en `docs/historial/`.

---

## Estado actual

**Fase**: 0 — Cimientos
**Paso activo**: 0.1-0.6 cerrados. **0.7 (núcleo multi-tenant, paso crítico): diseño terminado y verificado (`ADR-033`), pendiente de aprobación del usuario para empezar la implementación (0.7.1-0.7.11).**
**Rama**: `chore/checkpoint-sesion-0.7` (colgada de `develop`, PR #5 abierto; `chore/cierre-0.6-cicd` ya está mezclada en `develop`, ver commit `8b4c790`)
**Última sesión (2026-08-17)**: punto de control de arranque, P-01 cerrado (`spec-writer` estable). Subagente `architect` diseñó 0.7 en segundo plano (se atascó una vez por 600s sin progreso, se reanudó con `SendMessage` y terminó bien) y entregó `docs/adr/ADR-033-implementacion-del-aislamiento-multi-tenant.md`: RLS como barrera primaria, scope de Eloquent como ergonomía secundaria, tres roles de BD, claves foráneas compuestas `(tenant_id, id)`, veto a PgBouncer en modo *transaction*, suite de tests migrada de SQLite a PostgreSQL real. Verificado contra el repo: ADR bien numerado y referenciado, citas de requisitos (`RMT-001/002/008/009`, `RNF-MANT-006`, `INV-001`, `ADR-014`) reales. Al revisar el ADR con el usuario se detectó y corrigió un problema de coherencia documental **no relacionado con 0.7**: la sección 18 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` (fuente canónica de ADR ≤027) tenía `ADR-024` desactualizado (aún decía "VPS europeo") y **`ADR-027` no aparecía en ningún sitio del documento**; `ARCHITECTURE.md` tenía el mismo apéndice desincronizado con su propia tabla de §4.2; `README.md` se contradecía en dos filas consecutivas ("WSL2" vs "VM VMware" como host). Corregido en los cuatro ficheros (`REQUISITOS` → 3.1.1, `ARCHITECTURE.md` → 2.0.1, `README.md` → 2.4.1) con la cadena real de sustituciones `ADR-024` → `ADR-027` → `ADR-030`. Detalle en `CHANGELOG.md`. Además, se detectó y corrigió que `ci-api.yml`/`ci-web.yml` tenían filtros `paths:` que habrían bloqueado para siempre cualquier PR sin cambios de código si se configuraban como *required status checks*; quitados, y corregido el recuento de checks (ocho, no seis) en `memory.md`/`PLAN-IMPLEMENTACION.md`/`SYSADMIN.md`. PR #5 abierto para validar que los ocho checks corren antes de configurar *branch protection*. Pasos manuales de GitHub en curso (guía dada): branch protection, Dependabot alerts, Renovate App (falló por error transitorio de GitHub, pendiente reintento del usuario), permiso "Checks" del PAT del conector `github`.

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
- **0.7 en curso**: subagente `architect` (Opus) lanzado en segundo plano para diseñar resolución de tenant por subdominio, scope global obligatorio en Eloquent, RLS en PostgreSQL, mitigación de fugas por caché/colas compartidas, estrategia de tests de aislamiento en Pest, y ADR nuevo si procede (revisando antes qué de ADR-028 a ADR-032 ya tiene fichero propio en `/docs/adr/`). Sin resultado todavía en el momento de este commit.

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

1. **Aprobar `ADR-033`** (usuario) y, si se aprueba, empezar la implementación de 0.7 en Sonnet siguiendo los 11 pasos del ADR (0.7.1 provisión de BD/roles/función `app.current_tenant_id()` → 0.7.11 batería de diez tests de aislamiento). Punto de partida: `docs/adr/ADR-033-implementacion-del-aislamiento-multi-tenant.md`, sección "Plan de implementación".
2. Cerrar el PR #5 (`chore/checkpoint-sesion-0.7` → `develop`) una vez estén los ocho checks en verde, y **antes** configurar a mano en GitHub (no automatizable desde una sesión de Claude Code, ver `SYSADMIN.md` §4): **branch protection en `develop`/`main` con los ocho checks de CI como *required status checks*** (sin esto los workflows corren pero no bloquean el merge), activar Dependabot alerts, instalar la GitHub App de Renovate. También falta el permiso "Checks: Read-only" en el PAT de grano fino del conector `github` (`claude mcp get github`): "Commit statuses" ya se añadió y funciona, pero `get_check_runs` sigue devolviendo `403` — revisar que ambos permisos, no solo uno, queden marcados y guardados en https://github.com/settings/personal-access-tokens.
3. Decidir el motor de renderizado PDF (o posponerlo explícitamente a 1.17) antes de que haga falta en 1.17.
