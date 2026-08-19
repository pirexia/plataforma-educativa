# memory.md — Estado del proyecto

> Fichero de estado entre sesiones. Claude lo lee al arrancar y lo actualiza tras cada hito.
> Mantenerlo **corto**: si supera unas 150 líneas, archiva lo antiguo en `docs/historial/`.

---

## Estado actual

**Fase**: 0 cerrada en la práctica (lo pendiente de `0.10`-`0.12` es negocio, no código — ver abajo). **Fase 1, bloque A, en curso.**
**Paso activo**: **1.1 · `REQ-CORE`: tenants y usuarios** `[OPUS + SONNET]`. Especificación aprobada. **Implementación EN CURSO** (empezada 2026-08-19/20, tres commits en `feature/REQ-1.1-core-tenants-usuarios-wt`, 125/125 tests en verde en cada uno). Ver "Trabajo en curso" para el detalle exacto de qué está hecho y "Siguiente paso concreto" para continuar.
**Rama**: la implementación de 1.1 vive en `feature/REQ-1.1-core-tenants-usuarios-wt`, un *worktree* aislado que apunta al mismo commit que `feature/REQ-1.1-core-tenants-usuarios` (git no permite la misma rama en dos *worktrees*; **hay que fusionar/renombrar antes de cerrar el paso**, ver nota al final de "Siguiente paso concreto"). `develop` intacta.

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

- **1.1 en curso, tres commits** (rama `feature/REQ-1.1-core-tenants-usuarios-wt`):
  1. `d009bdc` — formato de error `application/problem+json` (`ApiException`, `ProblemResponseFactory`, `ValidationErrorFormatter`/`ValidationErrorBag`, `ApiFormRequest`), catálogo de errores y validación en 4 idiomas (`lang/{es,en,de,fr}/{errors,validation}.php`).
  2. `d7172e8` — 6 migraciones aditivas bajo `app/Modules/Core/Database/migrations/` (`tenant_settings`, `user_invitations`, `user_imports`, `data_exports`, `idempotency_keys`, normalización `people.locale` `'es'`→`'es-ES'`, issue [#46](https://github.com/pirexia/plataforma-educativa/issues/46)); 5 modelos Eloquent en `Domain/Models` con política de auditoría; `CoreServiceProvider` (permisos declarados, morph map, migraciones); `TenantSettingsReader` + caché; `ResolveApiLocale` (ADR-038 §11); `User implements Authenticatable` (solo para que `actingAs()` funcione, sin login real); `withoutWrapping()` global.
  3. `2424a52` — `PermissionResolver` (resolutor provisional ADR-034 §2: lee `effect`, ignora `scope`, `deny` gana a `allow`); middlewares `RequirePermission`/`EnsureModuleEnabled`; `IssueUserInvitation` + `SendInvitationEmail` (cola `core-mail`) + `InvitationMail`; comando `tenant:provision-defaults` con `ProvisionTenantDefaults` (16 roles, no 17 — issue [#48](https://github.com/pirexia/plataforma-educativa/issues/48), contradicción `funcional.md §4.7`/`CA-CORE-040` vs `permisos.md §4.5`); `DocumentNumberValidator` (DNI/NIE) con `core.documents.validate_check_digit` forzado en producción; `lang/{es,en,de,fr}/{core,roles,modules}.php`.
  - **Verificado contra PostgreSQL real** (contenedor `plataforma-postgres` compartido con el otro *worktree*, `.env` propio de este *worktree*, gitignored): migraciones aplicadas, `platform:sync-registry` + `tenant:provision-defaults` ejecutados dos veces (idempotencia confirmada a mano antes de escribir el test), datos de humo limpiados después.
  - **Todavía sin empezar**: ningún controlador HTTP ni ruta real (el fichero `app/Modules/Core/Http/routes.php` existe pero está vacío), `Idempotency-Key`/tabla `idempotency_keys` sin consumidor todavía, saneado de SVG, importación CSV, exportación de auditoría, OpenAPI (`apps/api/openapi/`), cliente TS/`types`/`locales` de `apps/web/src/modules/core/`, la mayoría de los 76 `CA-CORE-*` (solo cubiertos hasta ahora: 040, 042, 074, y parte de 070/071 vía los middlewares sin endpoint que los ejercite todavía).
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
| [#44](https://github.com/pirexia/plataforma-educativa/issues/44) | Contradicción `REQ-CORE-002`/`RMOD-002` sobre quién activa módulos. ADR al arrancar 1.6. No bloquea 1.1 (solo lectura). | Media |
| [#45](https://github.com/pirexia/plataforma-educativa/issues/45) | Sin análisis antivirus de ficheros subidos (`RSEC-OWASP-012`). Candidato 1.27. | Media |
| [#48](https://github.com/pirexia/plataforma-educativa/issues/48) | `funcional.md §4.7`/`CA-CORE-040` dicen "17 roles predefinidos"; `permisos.md §4.5` dice que Super Administrador no es fila de `roles` (16 filas reales, respaldado por `ADR-034 §2`). Resuelto en el código con 16 (`ProvisionTenantDefaults`); pendiente que el usuario confirme para corregir la documentación. | Media |

---

## Siguiente paso concreto

**Continuar la implementación de 1.1 en `feature/REQ-1.1-core-tenants-usuarios-wt`** (worktree en `.claude/worktrees/agent-a922ac7923ee13e1e`). Infraestructura transversal y aprovisionamiento ya cerrados (ver "Trabajo en curso"); **falta toda la superficie HTTP**. Orden recomendado, cada uno con su commit y sus tests `CA-CORE-*` antes de seguir al siguiente:

1. **Endpoints de configuración del centro** (`api.md` §2): `GET /tenant`, `GET/PATCH /tenant/settings` (con validación de contraste WCAG 2.2 AA — falta escribir el cálculo de ratio), `PUT/DELETE /tenant/settings/assets/{kind}` (saneado de SVG con `DOMDocument`, sin dependencia nueva), `GET /tenant/branding` (único endpoint sin auth). `CA-CORE-001` a `007`.
2. **Usuarios** (`api.md` §3): `GET/POST/PATCH/DELETE /users`, `/restore`, `/status`, `GET/PATCH /me`. Aquí se ejercitan de verdad `RequirePermission`, `RN-CORE-02/03/06/07/08`, `RPERM-013`. `CA-CORE-010` a `019`.
3. **Invitaciones** (`api.md` §4): `GET /invitations`, `POST /users/{id}/invitations` (ya existe `IssueUserInvitation`, solo falta el controlador), `DELETE /invitations/{id}`. `CA-CORE-020` a `024`.
4. **Roles/permisos/módulos, solo lectura + asignación** (`api.md` §5-6): `GET /roles`, `GET /roles/{id}`, `GET /permissions`, `GET/PUT /users/{id}/roles`, `GET /modules`, `PATCH /module-subscriptions/{id}` (solo `settings`, `422` si llega `enabled`). `CA-CORE-040/041/043/060/061/062`.
5. **Importación masiva** (`api.md` §7): `idempotency_keys` sigue sin consumidor — construir el middleware/trait de idempotencia de ADR-038 §8 aquí, no antes (es el único endpoint de 1.1 que la necesita). `CA-CORE-030` a `035`.
6. **Auditoría** (`api.md` §8): paginación por cursor cifrado (`Crypt`, AES-256-GCM, ADR-038 §4.4) — no reutilizar la paginación por página de los pasos 1-4. `CA-CORE-050` a `054`.
7. **Transversales que faltan**: purga programada (`PurgeExpiredInvitations`, `PurgeImportArtifacts`, `PurgeExpiredExports`, `PurgeOrphanBrandingAssets`, `PurgeExpiredIdempotencyKeys`), eventos de dominio que faltan (`UserDeactivated`, `UserRestored`, `UserRolesChanged`, `UserEmailChanged`, `TenantSettingsUpdated`, `UserImportCompleted` — los tres ya creados son `UserCreated`/`InvitationIssued`/`InvitationRevoked`), interfaces públicas que faltan (`UserDirectory`, `BulkUserImporter`, `AuditQuery`, `ExportRequestService` — `TenantSettingsReader` ya existe).
8. **OpenAPI** (`apps/api/openapi/components.yaml` + `paths/core.yaml`, ADR-038 §12.2) y **cliente TS** (`apps/web/src/modules/core/{api,types,locales}/`, sin pantallas — `OPEN-CORE-02`), a la vez que cada bloque de endpoints, no al final: si se deja para el final no queda tiempo y se declara terminado sin ello.
9. **Al terminar los 76 `CA-CORE-*`**: `security-reviewer`/`doc-reviewer` como subagentes independientes (no autorrevisión), como en 0.8/0.9/0.9b. Actualizar `docs/modulos/REQ-CORE/*.md` si algo se implementó distinto de lo especificado (ninguna desviación conocida hasta ahora, salvo el issue #48).
10. **Antes de cerrar el paso**: la rama de trabajo es `feature/REQ-1.1-core-tenants-usuarios-wt`, no `feature/REQ-1.1-core-tenants-usuarios` (mismo commit base, pero es un nombre de rama distinto porque git no permite la misma rama en dos *worktrees* a la vez). Hay que fusionar/renombrar antes del PR final: o se hace `git push` de `-wt` y el PR sale de ahí, o se renombra una vez liberado el otro *worktree*.
11. Decidir el motor de renderizado PDF (o posponerlo explícitamente a 1.17) antes de que haga falta ahí — no bloquea nada de lo anterior.
12. Los issues previos de `Problemas abiertos` no bloquean nada del trabajo actual; están correctamente diferidos a los pasos donde existe el código que los necesita (1.2/1.5/1.6/`REQ-BO-001`/`0.10d`).
