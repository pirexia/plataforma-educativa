# memory.md — Estado del proyecto

> Fichero de estado entre sesiones. Claude lo lee al arrancar y lo actualiza tras cada hito.
> Mantenerlo **corto**: si supera unas 150 líneas, archiva lo antiguo en `docs/historial/`.

---

## Estado actual

**Fase**: 0 — Cimientos
**Paso activo**: 0.1, 0.2, 0.3, 0.4 y 0.5 cerrados. 0.10 sigue abierto.
**Rama**: `feature/0.5-esqueleto-frontend-vue` (colgada de `develop`, sin mergear)
**Última sesión**: cierre de 0.3 (contenedor `web`), cierre de 0.5 (esqueleto Vue), MCP de Laravel Boost y Playwright añadidos. Ver `Trabajo en curso`.

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
| ADR-027 | Podman sobre RHEL 10 en VM VMware; Quadlet en producción |
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
- **MCP añadidos**: Laravel Boost (`composer require laravel/boost --dev` + `php artisan boost:install --mcp --no-interaction`) y Playwright (`@playwright/mcp`), declarados en `.mcp.json` de la raíz (versionado, sin secretos). El instalador de Boost escribió el comando envuelto en `wsl.exe`, que sobra porque ya corremos dentro de WSL2 — corregido a mano a `php apps/api/artisan boost:mcp`. **No van a estar disponibles como herramientas hasta que se abra una sesión nueva** (un MCP añadido a mitad de sesión no se carga solo). PostgreSQL MCP pendiente de 0.8.

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
| P-01 | El subagente `spec-writer` está bien definido en `.claude/agents/spec-writer.md` (modelo Opus) pero no aparece en la lista de subagentes disponibles de la sesión. Anomalía del entorno de Claude Code, no del repositorio. Revisar al empezar la próxima sesión; si persiste, investigar registro de agentes. | Media |
| P-02 | `npm create vue@latest -- --typescript --router ...` no respeta sus propios flags: sigue pidiendo el nombre del paquete de forma interactiva. Intentar automatizarlo con `yes "" \| npm create vue@latest ...` cuelga el proceso al 100% de CPU en vez de fallar limpio. Usar `npm create vite@latest <dir> -- --template vue-ts` y añadir router/Tailwind/shadcn-vue/Vitest/Playwright/ESLint a mano (lo que se hizo en 0.5) evita el problema. No usar `create-vue` en `apps/web` sin resolver esto primero. | Baja |

---

## Siguiente paso concreto

1. Mergear `feature/0.5-esqueleto-frontend-vue` a `develop` (tests, lint y build en verde en `apps/api` y `apps/web`) y borrar la rama.
2. Abrir una sesión nueva de Claude Code para que carguen los MCP de Laravel Boost y Playwright (P-02 no aplica aquí: es simplemente que un `.mcp.json` nuevo no se activa a mitad de sesión) y pasar la comprobación de la sección 7 de `docs/SETUP-CLAUDE-CODE.md` para estos dos.
3. Paso 0.6: CI/CD (GitHub Actions con build, tests, lint, análisis estático y escaneo de dependencias para `apps/api` y `apps/web`; Renovate).
4. Decidir el motor de renderizado PDF (o posponerlo explícitamente a 1.17) antes de que haga falta en 1.17.
5. Comprobar si `spec-writer` (P-01) sigue sin aparecer en `/agents`.
