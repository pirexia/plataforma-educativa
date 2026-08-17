# memory.md — Estado del proyecto

> Fichero de estado entre sesiones. Claude lo lee al arrancar y lo actualiza tras cada hito.
> Mantenerlo **corto**: si supera unas 150 líneas, archiva lo antiguo en `docs/historial/`.

---

## Estado actual

**Fase**: 0 — Cimientos
**Paso activo**: 0.1-0.7 cerrados y mezclados a `develop`. **Siguiente: 0.8 · Modelo de datos núcleo** `[OPUS]` — sin empezar.
**Rama**: `develop`, limpia, sincronizada con `origin/develop` (`caaf84e`). Sin ramas de trabajo abiertas: `chore/checkpoint-sesion-0.7` y `feature/paso-0.7-nucleo-multitenant` mezcladas (PR #5 y #9) y borradas, local y remoto.
**Última sesión (2026-08-17)**: cerrado el paso 0.7 (núcleo multi-tenant) completo — diseño, 11 subpasos de implementación, revisión de `db-reviewer`/`security-reviewer`, corrección de hallazgos, y mezcla a `develop`. Detalle completo archivado en `docs/historial/0.7-nucleo-multitenant.md`. Resumen en `Trabajo en curso`.

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
- **0.6 cerrado** (PR #4 mergeado a `develop` en `aa34d35`): `ci-api.yml` (Pest/Pint/Larastan sobre PHP 8.4, runner nativo, sin contenedor), `ci-web.yml` (ESLint/vue-tsc+build/Vitest/Playwright), `dependency-scan.yml` (Trivy filesystem, sin subir SARIF). Corregido de paso `apps/api/composer.json` (`^8.3` → `^8.4`, issue #3, **cerrado el 2026-08-18**: el código se corrigió en esta sesión pero el issue se quedó abierto por descuido, detectado y corregido al arrancar 0.8). `renovate.json` listo, App sin instalar todavía. Dos bugs encontrados y corregidos en el propio proceso: (1) el e2e de Playwright fallaba en el runner con timeout de `webServer` porque Vite escuchaba en `localhost` sin `--host` explícito (ambigüedad IPv4/IPv6 entre entornos) — fijado `--host 127.0.0.1` en `playwright.config.ts`; (2) `actions/dependency-review-action` no es viable en este repo (privado, sin GitHub Advanced Security disponible en cuenta personal) — sustituido por Trivy. Además, error propio de proceso: un `rm` fuera de `Edit`/`Write` se me olvidó stagear (`git add` solo cubrió los ficheros que creía haber tocado), dejando el workflow viejo trackeado y ejecutándose igualmente — corregido revisando `git status` completo. **Pendiente**: activar *branch protection* en GitHub con los ocho checks (3 de `ci-api.yml` + 4 de `ci-web.yml` + 1 de `dependency-scan.yml`) como *required status checks* (bloqueo de merge real, no automatizable desde Claude Code) — ver `SYSADMIN.md` §4.
- **Bug propio detectado y corregido (post-0.6, previo a activar branch protection)**: `ci-api.yml` y `ci-web.yml` tenían filtro `paths:` (solo se disparaban si el PR tocaba `apps/api/**`/`apps/web/**`). Si esos checks se marcan como *required* en branch protection, cualquier PR que no toque esas rutas (p.ej. uno que solo cambia `memory.md`) nunca los dispara y GitHub bloquea el merge para siempre ("Expected — waiting for status to be reported"). Corregido quitando los filtros `paths:`: ambos workflows corren siempre. Detectado antes de configurar branch protection, no en producción.
- **0.7 cerrado** (núcleo multi-tenant, paso crítico; PR #5 y #9 mezclados a `develop` en `dc15584`/`caaf84e`; ramas borradas). Diseño en `docs/adr/ADR-033-implementacion-del-aislamiento-multi-tenant.md`; RLS de PostgreSQL como barrera primaria, scope de Eloquent (`TenantModel`/`BelongsToTenant`/`TenantScope`) como ergonomía secundaria; tres roles PostgreSQL sin `SUPERUSER`; middleware `ResolveTenant` por subdominio; colas, caché y almacenamiento conscientes de tenant; helper `TenantMigration::tenantTable()` para tablas de negocio futuras. 47 tests en `tests/Feature/Tenancy/`, estable en corridas repetidas. `db-reviewer` y `security-reviewer` corrieron antes de mezclar (obligatorio, `CLAUDE.md` §6): sin bloqueantes; 1 hallazgo Alta y 2 Media del `db-reviewer`, todos corregidos; `security-reviewer` abrió 3 issues de seguimiento no bloqueantes ([#6](https://github.com/pirexia/plataforma-educativa/issues/6), [#7](https://github.com/pirexia/plataforma-educativa/issues/7), [#8](https://github.com/pirexia/plataforma-educativa/issues/8), ver `Problemas abiertos`). **Detalle completo (checklist de los 11 subpasos, los 7 bugs propios encontrados, y el porqué de cada decisión) archivado en `docs/historial/0.7-nucleo-multitenant.md`** — consultar ahí antes de tocar `app/Support/Tenancy/` en 0.8, no reconstruir el razonamiento desde cero.
- **Problema de entorno detectado durante 0.7 (no del repositorio, sigue sin resolver)**: `/auto-mode-setup` añadió a `.claude/settings.json` la regla `"deny": ["Read(./.env.*)", ...]`. El patrón `./.env.*` es más amplio de lo que probablemente se pretendía: bloquea también `.env.example`/`apps/api/.env.example` (plantillas sin secretos, sí deben poder leerse y editarse) además de los `.env` reales (correcto que estén bloqueados). Avisar al usuario si una sesión futura necesita tocar un `.env.example` y se topa con esto.

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
| [#6](https://github.com/pirexia/plataforma-educativa/issues/6) | `TenantContext::runAsPlatform()` sin control de autorización ni auditoría — cualquier código con acceso al contenedor puede cruzar tenants sin dejar rastro. Retomar cuando exista el sistema de permisos (1.5). | Media |
| [#7](https://github.com/pirexia/plataforma-educativa/issues/7) | La caché de resolución de tenant (`ResolveTenant`, 60s) no se invalida al suspender un tenant: sigue entrando hasta que expira. Retomar en REQ-BO-001 (backoffice, suspensión de tenants) u 0.8. | Baja |
| [#8](https://github.com/pirexia/plataforma-educativa/issues/8) | La cookie de sesión "host-only" es el valor por defecto de Laravel (`SESSION_DOMAIN` sin fijar), no algo reforzado activamente en el código — anotar para no darlo por garantizado si algo cambia esa config sin querer. Revisar al implementar `REQ-AUTH` (1.2). | Baja |

---

## Siguiente paso concreto

1. **Empezar 0.8 · Modelo de datos núcleo** `[OPUS]` (siguiente paso del plan, todavía sin empezar). Migraciones de `AcademicYear`, `Person`, `User`, `Role`, `Permission`, `AuditLog`, `ModuleSubscription` (`Tenant` ya está hecho, 0.7.4). Requiere Opus para el diseño (sección 16 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`) — comprobar modelo activo de la sesión antes de empezar (feedback guardada: contrastar modelo vs. etiqueta del paso). Usar `App\Support\Tenancy\TenantMigration::tenantTable()` (0.7.9) para las tablas de negocio: ya aplica `tenant_id`+RLS+auditoría en un sitio, no reinventarlo. Antes de escribir código, leer `docs/historial/0.7-nucleo-multitenant.md` para no repetir los 7 bugs ya encontrados (deadlocks de test entre conexiones, `pgsql_owner` explícito, etc.).
2. Configurar a mano en GitHub (no automatizable desde Claude Code, ver `SYSADMIN.md` §4): **branch protection** con los ocho checks como *required status checks*, Dependabot alerts. Falta el permiso "Checks: Read-only" en el PAT del conector `github` (`get_check_runs` sigue devolviendo `403`). **Renovate**: la GitHub App ya está instalada (usuario, 2026-08-18); queda mezclar la PR onboarding [#10](https://github.com/pirexia/plataforma-educativa/pull/10) ("Configure Renovate", contra `main`) para activarla — sin eso no empieza a abrir PRs de dependencias.
3. Decidir el motor de renderizado PDF (o posponerlo explícitamente a 1.17) antes de que haga falta en 1.17.
4. Los issues [#6](https://github.com/pirexia/plataforma-educativa/issues/6)/[#7](https://github.com/pirexia/plataforma-educativa/issues/7)/[#8](https://github.com/pirexia/plataforma-educativa/issues/8) (ver `Problemas abiertos`) no bloquean 0.8; revisar si 0.8 o 1.5/1.6 son el momento natural de resolverlos.
