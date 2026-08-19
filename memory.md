# memory.md — Estado del proyecto

> Fichero de estado entre sesiones. Claude lo lee al arrancar y lo actualiza tras cada hito.
> Mantenerlo **corto**: si supera unas 150 líneas, archiva lo antiguo en `docs/historial/`.

---

## Estado actual

**Fase**: 0 cerrada en la práctica (lo pendiente de `0.10`-`0.12` es negocio, no código — ver abajo). **Fase 1, bloque A, en curso.**
**Paso activo**: **1.1 · `REQ-CORE`: tenants y usuarios** `[OPUS + SONNET]`. **Especificación aprobada** (2026-08-19) en `docs/modulos/REQ-CORE/{funcional,api,permisos,operacion,datos}.md` (`spec-writer`, Opus). **`ADR-038` (convenciones de API REST) publicado** por `architect` (`docs/adr/ADR-038-convenciones-api-rest.md`), `api.md`/`datos.md` ya actualizados con sus correcciones. **Nada bloquea ya la implementación** — `implementer` lanzado al cerrar esta entrada. Ver "Siguiente paso concreto" para el resumen completo de decisiones.
**Rama**: `develop` local limpia y sincronizada. Sin ramas de trabajo ni *worktrees* abiertos — **ninguna implementación ha empezado todavía**, todo lo de esta sesión es documentación (spec + ADR) e issues.

**Decisiones de la serie `0.10`-`0.10e`, recogidas punto a punto con el usuario (2026-08-19), ninguna bloquea seguir desarrollando en local**: `0.10` → dirección decidida, **VPS Linux europeo**, proveedor concreto todavía sin elegir. `0.10b` → pendiente de `0.11c` (nombre de marca, sin decidir). `0.10c` (correo transaccional) → pendiente. `0.10d` (destino de copias) → pendiente. `0.10e` (staging) → pendiente de `0.10`. No hacer falta re-preguntar todo esto salvo que el usuario traiga una decisión nueva.

**Última sesión (2026-08-18/19)**: cerrado **0.9b · Portabilidad del despliegue**, pedido explícitamente por el usuario fuera de plan: seguir desarrollando en WSL2, pero dejar preparado lo necesario para instalar en *staging*/producción sobre un VPS genérico sin decidir todavía el proveedor (`OPEN-11` sigue abierta). `architect` redactó `ADR-037`: `compose.yaml` solo desarrollo; producción/*staging* solo **Quadlet** (paridad por `Containerfile` multi-etapa); imágenes en **GHCR** (plan Free confirmado por el usuario, retención desde el primer commit); **FrankenPHP modo clásico** (sin Octane/*worker*, por `INV-001`); secretos por `EnvironmentFile=`. Implementado por un `fork` en *worktree* aislado, con un corte real por límite de cuota a mitad de trabajo (primera vez que se disparó el cierre automático de `CLAUDE.md §3`) y retomado tras el reset sin pérdida. Revisión independiente (`security-reviewer`/`doc-reviewer`) encontró 7 hallazgos Media, todos corregidos por la orquestadora directamente, y 4 Baja diferidos a propósito (issues [#37](https://github.com/pirexia/plataforma-educativa/issues/37)/[#38](https://github.com/pirexia/plataforma-educativa/issues/38)/[#40](https://github.com/pirexia/plataforma-educativa/issues/40)). Detalle completo en `docs/historial/0.9b-portabilidad-despliegue.md`.

**Normas de proceso nuevas de esta sesión** (en `CLAUDE.md`/skills, se cargan solas, no hace falta repetirlas aquí): cierre automático de sesión al aparecer el aviso de límite de cuota (`CLAUDE.md §3`, skill `cierre-de-sesion` v1.1.2) — la hora de reset **no llega al modelo** (la app cliente la muestra en su interfaz, no como texto de sistema), preguntársela siempre al usuario salvo que ya la haya dado.

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
| ADR-027 | Podman; Quadlet en producción. Concretado por `ADR-037` |
| ADR-028 | Topología de red: frontend sin proxy, red externa, `Wants=` no `Requires=` |
| ADR-029 | `public_id` ULID en API y URLs; `TIMESTAMPTZ`, `text`, céntimos enteros |
| ADR-030 | Desarrollo en WSL2, **solo datos sintéticos**. Enmendado por `ADR-037` (`compose.yaml` no es de producción) |
| ADR-031 | `REQ-TRAN` ampliado a 12 requisitos, SHOULD, fase 2 |
| ADR-032 | Lista maestra única de autorizados a recoger, en `REQ-FAM-UNIT-005` |
| ADR-035 | `audit_logs.changes` no registra valores identificativos; supresión por retención, no por edición (resuelve `OPEN-12`) |
| ADR-036 | `Tenant` fuera del *observer* de auditoría de tenant; se audita en `admin_action_logs` (paso 1.6) |
| ADR-037 | `compose.yaml` solo desarrollo; producción/*staging* solo Quadlet; imágenes en GHCR; FrankenPHP modo clásico; secretos por `EnvironmentFile=` |

---

## Trabajo en curso

- **0.1-0.6 cerrados** (2026-08-13/14): repositorio y licencia; MCP de GitHub/Context7/Laravel Boost/Playwright conectados; entorno WSL2+Podman con perfil reducido; Laravel 13 (`apps/api`) y Vue 3+TS+Vite (`apps/web`) contenedorizados con healthcheck; CI/CD (`ci-api.yml`/`ci-web.yml`/`dependency-scan.yml`, Trivy). Detalle y bugs propios de cada uno en el historial de commits — nada pendiente de estos pasos.
- **0.7 cerrado**: núcleo multi-tenant (RLS + scope de Eloquent, tres roles PostgreSQL, middleware `ResolveTenant`). Detalle completo en `docs/historial/0.7-nucleo-multitenant.md`.
- **0.8 cerrado**: modelo de datos núcleo (`Person`/`User`, `Role`/`Permission`, `AuditLog`, `AcademicYear`, `ModuleSubscription`). Detalle completo en `docs/historial/0.8-modelo-de-datos-nucleo.md`.
- **`ADR-035` + 0.9 cerrados**: registro de auditoría (`AuditChangeBuilder`, política de redacción por modelo) e i18n de 4 idiomas. `ADR-036` corrige la exclusión de `Tenant` del mecanismo. Detalle completo en `docs/historial/0.9-auditoria-i18n.md`.
- **`ADR-037` + 0.9b cerrados**: portabilidad del despliegue. Ver `Estado actual` y `docs/historial/0.9b-portabilidad-despliegue.md`.
- **Problema de entorno sin resolver**: `.claude/settings.json` bloquea `Read(./.env.*)` de forma más amplia de lo previsto (también `.env.example`). Avisar si una sesión futura necesita tocar un `.env.example`.
- **MCP de Playwright corregido** (2026-08-19): pedía el canal "chrome" (Google Chrome), no instalado y no instalable sin `sudo` interactivo en esta sesión. `.mcp.json` ahora fuerza `--browser=chromium`, que el propio proyecto ya trae instalado (Playwright de test de `apps/web`). Si una sesión futura ve el mismo error de canal, comprobar que esta configuración sigue vigente antes de investigar de nuevo.
- **Captura del frontend actual enviada al usuario** (2026-08-19): `AppLayout`+`HomeView` funcionando de verdad contra `GET /api/health`, con i18n. Nada de negocio todavía — eso es 1.1 en adelante.

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
| P-02 | `create-vue` cuelga el instalador interactivo — usar `npm create vite` en su lugar (ver 0.5). | Baja |
| [#6](https://github.com/pirexia/plataforma-educativa/issues/6) | `TenantContext::runAsPlatform()` sin control de autorización ni auditoría. Retomar en 1.5. | Media |
| [#7](https://github.com/pirexia/plataforma-educativa/issues/7) | Caché de resolución de tenant no se invalida al suspender. Retomar en REQ-BO-001. | Baja |
| [#8](https://github.com/pirexia/plataforma-educativa/issues/8) | Cookie de sesión "host-only" es el valor por defecto, no reforzado activamente. Revisar en 1.2. | Baja |
| [#18](https://github.com/pirexia/plataforma-educativa/issues/18) | Falta `PasswordBrokerRepository` propio con tenant en recuperación de contraseña. No explotable hasta 1.2. | Media |
| [#27](https://github.com/pirexia/plataforma-educativa/issues/27) | `Tenant` sin auditoría hasta `admin_action_logs` (1.6). Ver `ADR-036`. No explotable hoy. | Media |
| [#37](https://github.com/pirexia/plataforma-educativa/issues/37) | Redis sin autenticación (`requirepass`). | Baja |
| [#38](https://github.com/pirexia/plataforma-educativa/issues/38) | `infra/quadlet/minio-data.volume` huérfano hasta que exista `minio.container` (0.10d). | Baja |
| [#40](https://github.com/pirexia/plataforma-educativa/issues/40) | Sin escaneo de vulnerabilidades a nivel de imagen del SO (`dependency-scan.yml` solo cubre `composer.lock`/`package-lock.json`). | Baja |

---

## Siguiente paso concreto

1. **Comprobar que `docs/adr/ADR-038-convenciones-api-rest.md` está publicado** (encargado a `architect` al cierre de esta entrada). Si el ADR corrige algo de la propuesta de `docs/modulos/REQ-CORE/api.md` §9, actualizar `api.md` antes de seguir. Referenciarlo desde la sección 18 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` si `architect` no lo dejó hecho.
2. **Lanzar `implementer`/`fork` (Sonnet)** con la especificación ya aprobada de `docs/modulos/REQ-CORE/{funcional,api,permisos,operacion,datos}.md`. Resumen de lo que hay que implementar (detalle completo en `funcional.md`):
   - Cuatro tablas nuevas, aditivas: `tenant_settings`, `user_invitations`, `user_imports`, `data_exports`.
   - CRUD de usuarios con baja lógica, invitaciones (emisión/reenvío/revocación, token solo hash), importación CSV en dos fases (validar sin escribir → ejecutar con `Idempotency-Key`), asignación de roles (solo lectura de catálogo, resolutor provisional ignora `scope` — **todo permiso se siembra con ámbito `todos`**, ver `permisos.md` §5), configuración del centro con validación de contraste WCAG en la paleta, subida de activos de marca con saneado de SVG, `GET /tenant/branding` público, `/me` autoservicio, consulta+exportación CSV de auditoría, `EnsureModuleEnabled` (`RMOD-009`), comando `tenant:provision-defaults` (siembra los 17 roles + primer admin).
   - **1.1 es solo API, sin pantallas** (decisión aprobada, `OPEN-CORE-02`): nada en `views/`/`components/` de `apps/web/src/modules/core/`, solo `api/`/`types/`/`locales/`. Las pantallas se construyen en el paso 1.8.
   - Validación de dígito de control de DNI/NIE: conmutador por entorno, **forzado a validar en producción**, desactivado en dev/test para que 1.15b (`REQ-SEED`) pueda sembrar documentos inválidos a propósito (`OPEN-CORE-06`, decisión (b)).
   - Tras implementar: `security-reviewer`/`doc-reviewer` como subagentes independientes (no solo autorrevisión del fork), como en 0.8/0.9/0.9b.
3. **Cuatro issues abiertos durante la especificación de 1.1, ninguno bloquea la implementación**: [#44](https://github.com/pirexia/plataforma-educativa/issues/44) (Media, contradicción `REQ-CORE-002`/`RMOD-002` sobre quién activa módulos — ADR al arrancar 1.6), [#45](https://github.com/pirexia/plataforma-educativa/issues/45) (Media, sin análisis antivirus de ficheros subidos, `RSEC-OWASP-012` — candidato paso 1.27), [#46](https://github.com/pirexia/plataforma-educativa/issues/46) (Baja, `locale` por defecto `'es'` vs `es-ES` — se unifica en `es-ES` al implementar 1.1), [#47](https://github.com/pirexia/plataforma-educativa/issues/47) (Media, dos bugs reales en `apps/web/src/api/client.ts` detectados por `architect` al escribir `ADR-038` — **ya corregidos** en esta sesión, pendiente de commit y de cerrar el issue enlazándolo).
4. Decidir el motor de renderizado PDF (o posponerlo explícitamente a 1.17) antes de que haga falta ahí.
5. Los issues previos de `Problemas abiertos` no bloquean nada del trabajo actual; están correctamente diferidos a los pasos donde existe el código que los necesita (1.2/1.5/1.6/`REQ-BO-001`/`0.10d`).
