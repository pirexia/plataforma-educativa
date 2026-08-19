# memory.md — Estado del proyecto

> Fichero de estado entre sesiones. Claude lo lee al arrancar y lo actualiza tras cada hito.
> Mantenerlo **corto**: si supera unas 150 líneas, archiva lo antiguo en `docs/historial/`.

---

## Estado actual

**Fase**: 0 cerrada en la práctica (lo pendiente de `0.10`-`0.12` es negocio, no código — ver abajo). **Fase 1, bloque A, en curso.**
**Paso activo**: **1.1 · `REQ-CORE`: tenants y usuarios** `[OPUS + SONNET]`. Especificación aprobada. **Implementación EN CURSO, avanzada** (empezada 2026-08-19/20, diez commits en `feature/REQ-1.1-core-tenants-usuarios-wt`, 159/159 tests en verde, verificado con dos ejecuciones completas consecutivas de la suite tras cada commit desde el cuarto). Toda la infraestructura transversal y la mayoría de los endpoints de lectura/escritura están hechos y probados; **faltan importación masiva, subida de activos de marca, purgas programadas, OpenAPI y el cliente TS**. Ver "Trabajo en curso" para el detalle exacto y "Siguiente paso concreto" para continuar sin releer código.
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

- **1.1 en curso, diez commits** (rama `feature/REQ-1.1-core-tenants-usuarios-wt`, del más antiguo al más reciente): `d009bdc` formato de error `problem+json` (`ApiException`/`ProblemResponseFactory`/`ValidationErrorBag`/`ApiFormRequest`) y catálogo de error+validación en 4 idiomas · `d7172e8` 6 migraciones aditivas (`tenant_settings`, `user_invitations`, `user_imports`, `data_exports`, `idempotency_keys`, `people.locale`→`es-ES`), 5 modelos, `CoreServiceProvider`, `TenantSettingsReader`+caché, `ResolveApiLocale`, `User implements Authenticatable` (solo para `actingAs()`, sin login real) · `2424a52` `PermissionResolver` (provisional, ADR-034 §2), `RequirePermission`/`EnsureModuleEnabled`, `IssueUserInvitation`+`SendInvitationEmail`, `tenant:provision-defaults`/`ProvisionTenantDefaults` (16 roles, no 17 — issue #48), `DocumentNumberValidator` · `8726acd` memory.md · `dac9c1c` **primeros endpoints**: `GET /tenant`, `GET/PATCH /tenant/settings`, `GET /tenant/branding` (hallazgo propio: `Cache::flush()` en tests por Redis real forzado en `phpunit.xml`) · `e58eedf` `PATCH /tenant/settings` con `ContrastRatioCalculator` (WCAG 4.5:1) y `AutonomousCommunity` · `55c14cd` **CRUD de usuarios completo** + `/me` (`CreateUser`/`UpdateUser`/`SchoolAdministratorGuard`, `QueryBoolean` — hallazgo propio: la regla `boolean` de Laravel no acepta la palabra "true" en query string) · `0622cd8` **invitaciones** (`GET /invitations`, `POST .../invitations`, `DELETE /invitations/{id}`) — **hallazgo propio Alta, issue #49**: `TenancyServiceProvider` vaciaba el contexto de tenant tras cualquier job con `QUEUE_CONNECTION=sync` (primer job de negocio real del proyecto), corregido guardando/restaurando el contexto anterior en vez de un `leave()` incondicional · `9bb0c9f` **roles/permisos/módulos** de solo lectura + `PUT /users/{id}/roles` · `105c498` **auditoría con cursor cifrado** (`CursorCodec`, `AuditQuery`) + **exportación asíncrona** (`ExportRequestService`, `GenerateAuditLogExport`, `GET /data-exports/{id}`); corregido también el índice de `audit_logs` a `(tenant_id, occurred_at DESC, id DESC)` que `ADR-038 §17.8` dejó pendiente.
  - **Verificado contra PostgreSQL+Redis reales** (contenedor compartido `plataforma-postgres`, `.env` propio de este *worktree*, gitignored; los tests usan `plataforma_test`+Redis DB 2 vía `phpunit.xml`, no el `.env`). 159/159 tests, dos ejecuciones consecutivas sin intermitencia tras cada commit.
  - **`CA-CORE-*` cubiertos con test HTTP o de servicio**: 001-024, 040-043, 050-054, 060-062, 070 (401/403 en varios endpoints), 074. **Sin empezar**: 025-035 (importación masiva — tampoco tiene consumidor la tabla `idempotency_keys` todavía), 005-006 (subida de activos de marca: `PUT/DELETE /tenant/settings/assets/{kind}`, saneado de SVG), 041 solo parcialmente (405 en un intento de `PATCH /roles/{id}`, falta el mismo test para `DELETE`), 071-073 (parcialmente cubiertos de forma indirecta, sin test dedicado), 075 (i18n de 4 idiomas: los literales SÍ están traducidos en los 4, pero no hay un test que lo verifique sistemáticamente).
  - **Interfaces públicas (`INV-007`) que faltan**: `UserDirectory`, `BulkUserImporter` (`TenantSettingsReader`, `AuditQuery`, `ExportRequestService` ya existen y están en uso).
  - **Eventos de dominio (funcional.md §7) que faltan**: `TenantSettingsUpdated`, `UserImportCompleted` (los otros siete ya existen: `UserCreated`, `UserDeactivated`, `UserRestored`, `UserRolesChanged`, `UserEmailChanged`, `InvitationIssued`, `InvitationRevoked` — todos emitidos desde su endpoint salvo `UserRolesChanged`, que sí se emite).
  - **Purgas programadas sin empezar**: `PurgeExpiredInvitations`, `PurgeImportArtifacts`, `PurgeExpiredExports`, `PurgeOrphanBrandingAssets`, `PurgeExpiredIdempotencyKeys` (operacion.md §4).
  - **OpenAPI** (`apps/api/openapi/components.yaml` + `paths/core.yaml`) y **cliente TS** (`apps/web/src/modules/core/{api,types,locales}/`) sin empezar — ninguno de los endpoints construidos hasta ahora está documentado en OpenAPI todavía.
  - **Cuatro issues propios abiertos esta sesión, ninguno bloquea seguir**: [#48](https://github.com/pirexia/plataforma-educativa/issues/48) (Media, "17 roles" vs 16 reales), [#49](https://github.com/pirexia/plataforma-educativa/issues/49) (Alta, ya corregido — contexto de tenant tras un job síncrono).
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
| [#49](https://github.com/pirexia/plataforma-educativa/issues/49) | `TenancyServiceProvider` vaciaba el contexto de tenant tras cualquier job con `QUEUE_CONNECTION=sync` (worker real vs. job en línea dentro de la misma petición). **Ya corregido** en el mismo commit (`0622cd8`): se guarda/restaura el contexto anterior en vez de un `leave()` incondicional. | Alta (cerrada) |

---

## Siguiente paso concreto

**Continuar la implementación de 1.1 en `feature/REQ-1.1-core-tenants-usuarios-wt`** (worktree en `.claude/worktrees/agent-a922ac7923ee13e1e`, `.env` propio ya configurado contra el Postgres/Redis compartidos — ver nota de entorno más abajo). Config/endpoints/roles/módulos/auditoría ya cerrados y probados (ver "Trabajo en curso"). Orden recomendado, cada uno con commit y tests `CA-CORE-*` antes de seguir al siguiente:

1. **Subida de activos de marca** (`api.md` §2.2, `funcional.md` §4.2): `PUT/DELETE /tenant/settings/assets/{kind}`. Tipo real por contenido (`finfo`, nunca extensión/`Content-Type`), saneado de SVG con `DOMDocument` (quitar `<script>`, `on*`, `<foreignObject>`, referencias externas — **sin dependencia nueva**), clave `tenants/{tenant_public_id}/branding/{kind}/{ulid}.{ext}`, activo anterior no se borra en la petición (purga diferida 24h, ver punto 4). `CA-CORE-005/006`. Usa `Storage::fake()` en los tests, como ya hace `AuditLogsEndpointsTest`.
2. **Importación masiva de usuarios** (`api.md` §7): dos fases, `POST /user-imports` (sube+valida, cola `core-imports`, `ValidateUserImport`), `GET /user-imports`/`{id}`, `POST /user-imports/{id}/execute` (**primer y único consumidor de la tabla `idempotency_keys` en 1.1** — construir aquí el middleware/trait de idempotencia de ADR-038 §8, no antes), `DELETE /user-imports/{id}`. Reutiliza `CreateUser`/`IssueUserInvitation` fila a fila, cada una en su propia transacción (funcional.md §4.4 paso 7). `CA-CORE-030` a `035`. Emite `UserImportCompleted` (evento por crear).
3. **`BulkUserImporter`** (interfaz pública, `INV-007`, funcional.md §7): la envolvería el propio importador del paso 2 — sale prácticamente gratis si el servicio de ejecución ya está bien separado del controlador.
4. **Purgas programadas** (`operacion.md` §4, `App\Modules\Core\Infrastructure\Jobs`, registradas en `routes/console.php` con `Schedule::job(...)->daily()`, **por tenant**, no en una pasada global sin contexto — patrón ya usado en `SendInvitationEmail`/`GenerateAuditLogExport`): `PurgeExpiredInvitations`, `PurgeImportArtifacts`, `PurgeExpiredExports`, `PurgeOrphanBrandingAssets`, `PurgeExpiredIdempotencyKeys` (esta última no es por tenant en el filtro, pero sí en el borrado — revisar `datos.md` §A.9). `CA-CORE-035` necesita `PurgeImportArtifacts` en concreto.
5. **`UserDirectory`** (interfaz pública, funcional.md §7: resolución por `public_id` + idioma preferido, para que `REQ-COM` no consulte `users`/`people` directamente) y **evento `TenantSettingsUpdated`** (emitirlo desde `TenantSettingsController::update()`, que hoy no lo hace).
6. **`CA-CORE-071`/`072`/`073`/`075`**: no tienen test dedicado todavía (están cubiertos indirectamente por los tests existentes, pero sin una prueba que los referencie explícitamente por ID). Escribir un test transversal (p. ej. `TransversalCoreTest.php`) que los ejercite de forma explícita, tal como exige `INV-015`.
7. **OpenAPI** (`apps/api/openapi/components.yaml` + `paths/core.yaml`, ADR-038 §12.2) — **sin empezar, y ningún endpoint construido hasta ahora está documentado**. Si el tiempo no llega para la comprobación de paridad rutas↔spec en CI, dejarlo anotado como pendiente en vez de fingir que existe (`CLAUDE.md` §10).
8. **Cliente TS** (`apps/web/src/modules/core/{api,types,locales}/`, sin pantallas — `OPEN-CORE-02`): tipar los ~25 endpoints ya construidos.
9. **Al terminar los 76 `CA-CORE-*`**: `security-reviewer`/`doc-reviewer` como subagentes independientes (no autorrevisión), como en 0.8/0.9/0.9b. Actualizar `docs/modulos/REQ-CORE/*.md` si algo se implementó distinto de lo especificado — desviaciones conocidas hasta ahora: issue #48 (16 roles, no 17) e issue #49 (ya corregido, infraestructura de colas).
10. **Nota de entorno para retomar**: este *worktree* tiene su propio `.env` (gitignored, no tocar el del *worktree* principal) apuntando al Postgres/Redis compartidos (`podman ps` para confirmar que `plataforma-postgres`/`plataforma-redis` siguen arriba). Los tests (`php artisan test`) usan la base `plataforma_test` y Redis DB 2 vía `phpunit.xml` (`force="true"`), **no** el `.env` — no hay que sincronizar ambos. `storage/framework/{views,cache/data,sessions}` y `storage/logs` los crea `mkdir -p` si faltan (gitignored, no se versionan).
11. **Antes de cerrar el paso**: la rama de trabajo es `feature/REQ-1.1-core-tenants-usuarios-wt`, no `feature/REQ-1.1-core-tenants-usuarios` (mismo commit base, pero nombre de rama distinto porque git no permite la misma rama en dos *worktrees* a la vez). Fusionar/renombrar antes del PR final.
12. Decidir el motor de renderizado PDF (o posponerlo explícitamente a 1.17) antes de que haga falta ahí — no bloquea nada de lo anterior.
13. Los issues previos de `Problemas abiertos` no bloquean nada del trabajo actual; están correctamente diferidos a los pasos donde existe el código que los necesita (1.2/1.5/1.6/`REQ-BO-001`/`0.10d`).
