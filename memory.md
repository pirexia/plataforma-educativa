# memory.md — Estado del proyecto

> Fichero de estado entre sesiones. Claude lo lee al arrancar y lo actualiza tras cada hito.
> Mantenerlo **corto**: si supera unas 150 líneas, archiva lo antiguo en `docs/historial/`.

---

## Estado actual

**Fase**: 0 cerrada en la práctica (lo pendiente de `0.10`-`0.12` es negocio, no código — ver "Bloqueantes"). **Fase 1, bloque A: 1.1 y 1.2 cerrados y mezclados. `1.2b` implementado y en verde, pendiente del pipeline de revisión independiente antes de mezclar (ver más abajo).**

**1.2b · `REQ-AUTH-005` puntos 2-4: sesiones activas, cierre remoto y detección de dispositivo — IMPLEMENTADO, PENDIENTE DE REVISIÓN** (2026-08-25/26). Rama `feature/REQ-AUTH-005-1.2b-sesiones-activas` (push a origin, último commit `d8bc5f2`), colgada de `develop`. Especificación (`docs/modulos/REQ-AUTH/*.md` Parte B) aprobada 2026-08-25 con las 5 decisiones de `funcional.md §B.14`: solo «dispositivo nuevo» sin geolocalización (`OPEN-AUTH-13`); cookie `pge_device` técnica exenta de consentimiento (`OPEN-AUTH-14`); RLS en `sessions` del framework queda para paso propio de endurecimiento futuro, issue [#81](https://github.com/pirexia/plataforma-educativa/issues/81) (`OPEN-AUTH-15`); exclusión declarativa de auditoría vía `ADR-040` (`OPEN-AUTH-16`); `User-Agent` con regex propio, sin dependencia (`OPEN-AUTH-17`). `ADR-040` escrito y con sus 9 puntos de `§8` verificados uno a uno, cerrado.

Implementado: 2 migraciones (`user_known_devices`, `user_sessions`, RLS desde el primer día), `SessionEndReason`/`ClientDeviceType`/`ClientDescriber`/`IpGeolocator` (implementación nula)/`UserSessionDirectory`, `SessionRegistrationService`+`DeviceRecognitionService`, 3 endpoints autoservicio (`GET`/`DELETE /auth/sessions`, `DELETE /auth/sessions/{public_id}`), pantalla `/cuenta/sesiones` (sin `AppLayout`, misma categoría que `/cuenta/contrasena`), 4 idiomas backend+frontend, OpenAPI, `SESSION_DRIVER=database` obligatorio en todo entorno (`RN-AUTH-49`, `phpunit.xml` cambia su valor por defecto). 275 tests backend (24 `CA-AUTH-080`-`103` con test propio, `INV-015`) + 10 frontend, `pint`/`phpstan`/`eslint`/`vue-tsc`/`lint:i18n` limpios. Verificación real en navegador (Playwright MCP contra un tenant de desarrollo con datos sintéticos `@example.com`, `ADR-030`/`REQ-SEED-005`) encontró y corrigió dos fallos que ningún test unitario había cazado: `device_known` siempre daba `true` (issue [#85](https://github.com/pirexia/plataforma-educativa/issues/85)) y la gestión de foco de las confirmaciones de revocar sesión no movía el foco al botón real, sino a su contenedor (WCAG 2.2 AA 2.4.3/2.4.7, sin issue propio, corregido en el mismo commit que lo introdujo).

Desviaciones de la especificación tomadas con criterio propio, documentadas cada una con su commit: (1) el *listener* de `UserDeactivated` que revoca sesiones **no existía** pese a que 1.2/`funcional.md §8.2` ya lo daban por hecho — creado en esta sesión (issue [#84](https://github.com/pirexia/plataforma-educativa/issues/84)). (2) El patrón de test `withCookie()` + `postJson/getJson/deleteJson` de la suite preexistente **no transmite la cookie de sesión** (`prepareCookiesForJsonRequest()` la descarta sin `withCredentials()`) — sustituido por `withCredentials()`+`withUnencryptedCookie()` con reseteo explícito de `Store`/`Guard` entre peticiones simuladas (`resetSessionState()` en `tests/Pest.php`); los tests preexistentes que usan el patrón roto (issue [#83](https://github.com/pirexia/plataforma-educativa/issues/83), fuera del alcance de 1.2b) **se corrigieron después, en rama propia, y ya están mezclados en `develop`** (PR #87, ver "Siguiente paso concreto"). (3) Hallazgo aparte sin relación con 1.2b: `HomeView.vue` llama a `/api/v1/health`, que no existe (issue [#86](https://github.com/pirexia/plataforma-educativa/issues/86), severidad Baja, no resuelto).

**Pipeline de revisión independiente, en curso** (2026-08-26). **Lección de infraestructura, válida para cualquier sesión futura con `isolation: worktree`**: los tres agentes se lanzaron en paralelo con `isolation: "worktree"` y **dos de los tres** (`db-reviewer`, `doc-reviewer`) se crearon desde un commit muy antiguo del repositorio (`e2c6456`, "Add renovate.json"), sin nada del código de `1.2b` — corrieron más de una hora sobre un repositorio vacío de este trabajo antes de detectarlo. El tercero (`security-reviewer`) sí tuvo el código correcto pero sufrió un fallo de entorno propio (perdió `Bash` a mitad de sesión) que le obligó a aplicar su fix por la API de GitHub y dejó un *worktree*/rama huérfanos. **No fiarse de `isolation: worktree` en paralelo sin verificar** (`git log --oneline -1` dentro del *worktree* del agente) **antes de dar por buena una revisión**. Los dos agentes rotos se pararon (`TaskStop`) y se relanzaron **sin aislamiento, uno detrás de otro**, directamente en el *checkout* principal.
- **`security-reviewer`**: hallazgo propio Media (auditoría bajo tenant equivocado, FK violation, issue #88) — fix inicial incompleto (dejaba `Auth::guard('web')->logout()` sin proteger) y su propio test de regresión en rojo (falso positivo de `actingAs()` con `session_id` desconectado del login real). **Verificado, corregido del todo y cerrado por esta sesión** (commit `1d2fb88`). 276 tests en verde.
- **`db-reviewer`** (relanzado sin aislamiento): todo correcto en las migraciones/RLS/tipos/índices. Hallazgo propio Media (INV-015): los tres jobs de retención (`PurgeUserSessions`/`PurgeUserKnownDevices`/`CloseOrphanedUserSessions`) no tenían test — añadido `AuthRetentionJobsTest.php`, encontró y corrigió de paso un `resetSessionState()` que faltaba (commit `a5656e9`). 279 tests en verde. **Cerrado.**
- **`doc-reviewer`** (relanzado sin aislamiento): en curso.

**Siguiente paso concreto**: esperar a que termine `doc-reviewer`. Si no deja hallazgos Alta/Crítica sin resolver, `1.2b` queda lista para PR — **no hacer merge ni PR sin que el usuario lo pida**.
**1.2 · `REQ-AUTH`: autenticación local y sesiones — CERRADO Y MEZCLADO** (2026-08-22/25). PR [#76](https://github.com/pirexia/plataforma-educativa/pull/76) (*squash*, commit `0d34587`). Revisión independiente (`security-reviewer`/`doc-reviewer`) con 2 hallazgos Alta de seguridad y 7 Media de documentación, todos corregidos antes de mezclar. Detalle completo en `docs/historial/1.2-auth-local-sesiones.md`.
**1.1 · `REQ-CORE`: tenants y usuarios — CERRADO Y MEZCLADO** (2026-08-21/22). PR [#56](https://github.com/pirexia/plataforma-educativa/pull/56). Detalle completo en `docs/historial/1.1-core-tenants-usuarios.md`.
**Rama**: `develop`, limpia y sincronizada con `origin`. Sin rama de trabajo abierta. Los tres *worktrees* huérfanos de subagentes de sesiones anteriores se limpiaron al cerrar 1.2 (ramas y directorios borrados).

**Lección de esta sesión, válida para cualquier rama futura trabajada con subagentes en *worktrees* paralelos**: los *worktrees* comparten el mismo `.git` — un commit hecho con el índice desincronizado puede revertir sin querer el trabajo de otro (pasó dos veces en 1.2, autodetectado y corregido ambas). **Antes de commitear: `git fetch` + `git log --oneline -3`.** Y una verificación end-to-end con `curl` **no sustituye una prueba de navegador real**: `curl` ignora restricciones del propio navegador (SameSite, `document.cookie` por host, CORS real) que sí bloquean a un usuario de verdad — pasó con el issue #71 de 1.2, donde el login se dio por "verificado de extremo a extremo" y en realidad no funcionaba en un navegador.

**Decisiones de la serie `0.10`-`0.10e`, recogidas punto a punto con el usuario (2026-08-19), ninguna bloquea seguir desarrollando en local**: `0.10` → dirección decidida, **VPS Linux europeo**, proveedor concreto todavía sin elegir. `0.10b` → pendiente de `0.11c` (nombre de marca, sin decidir). `0.10c` (correo transaccional) → pendiente. `0.10d` (destino de copias) → pendiente. `0.10e` (staging) → pendiente de `0.10`. No hace falta re-preguntar todo esto salvo que el usuario traiga una decisión nueva.

**Normas de proceso vigentes** (en `CLAUDE.md`/skills, se cargan solas, no hace falta repetirlas aquí): cierre automático de sesión al aparecer el aviso de límite de cuota (`CLAUDE.md §3`, skill `cierre-de-sesion` v1.1.2) — la hora de reset **no llega al modelo**, preguntársela siempre al usuario salvo que ya la haya dado.

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
| ADR-039 | `audit_logs.event` pasa de 6 a 9 valores (`+login`, `+logout`, `+password_reset_requested`); `event` describe el hecho, nunca un verbo CRUD que no le corresponde; los tres exigen `User` real (un correo inexistente va a `login_attempts`) y `changes` `NULL`. `audit_logs.actor_type` pasa de 5 a 6 (`+anonymous`, para peticiones sin sesión como `password_reset_requested`). Resuelve `OPEN-AUTH-02` y `OPEN-AUTH-12` |
| ADR-040 | El *observer* de auditoría de 0.9 gana `Auditable::auditExcludedEvents()`, exclusión declarativa por modelo y evento; `UserSession` declara `['created']` (el login ya lo registra vía `login` de `ADR-039`). Resuelve `OPEN-AUTH-16` |

---

## Trabajo en curso

- **1.1 completo**: detalle en `docs/historial/1.1-core-tenants-usuarios.md` (issues #48-#55, hallazgos de revisión independiente, todo cerrado).
- **1.2 completo**: detalle en `docs/historial/1.2-auth-local-sesiones.md` (issues #62-#75, dos rondas de revisión independiente, login por navegador verificado de verdad, todo cerrado).
- **0.1-0.6 cerrados** (2026-08-13/14): repositorio y licencia; MCP de GitHub/Context7/Laravel Boost/Playwright conectados; entorno WSL2+Podman con perfil reducido; Laravel 13 (`apps/api`) y Vue 3+TS+Vite (`apps/web`) contenedorizados con healthcheck; CI/CD (`ci-api.yml`/`ci-web.yml`/`dependency-scan.yml`, Trivy). Detalle y bugs propios de cada uno en el historial de commits — nada pendiente de estos pasos.
- **0.7 cerrado**: núcleo multi-tenant (RLS + scope de Eloquent, tres roles PostgreSQL, middleware `ResolveTenant`). Detalle completo en `docs/historial/0.7-nucleo-multitenant.md`.
- **0.8 cerrado**: modelo de datos núcleo (`Person`/`User`, `Role`/`Permission`, `AuditLog`, `AcademicYear`, `ModuleSubscription`). Detalle completo en `docs/historial/0.8-modelo-de-datos-nucleo.md`.
- **`ADR-035` + 0.9 cerrados**: registro de auditoría (`AuditChangeBuilder`, política de redacción por modelo) e i18n de 4 idiomas. `ADR-036` corrige la exclusión de `Tenant` del mecanismo. Detalle completo en `docs/historial/0.9-auditoria-i18n.md`.
- **`ADR-037` + 0.9b cerrados**: portabilidad del despliegue. Detalle completo en `docs/historial/0.9b-portabilidad-despliegue.md`.
- **Problema de entorno sin resolver**: `.claude/settings.json` bloquea `Read(./.env.*)` de forma más amplia de lo previsto (también `.env.example`). Avisar si una sesión futura necesita tocar un `.env.example`.
- **MCP de Playwright corregido** (2026-08-19): `.mcp.json` fuerza `--browser=chromium`. El binario de Chromium en sí puede no estar instalado en el contenedor `web` tras un `npm ci` (no se descarga solo): `npx playwright install --with-deps chromium` si `npx playwright test` falla con "Executable doesn't exist".

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
| [#8](https://github.com/pirexia/plataforma-educativa/issues/8) | Cookie de sesión "host-only" es el valor por defecto, no reforzado activamente. | Baja |
| [#18](https://github.com/pirexia/plataforma-educativa/issues/18) | Falta `PasswordBrokerRepository` propio con tenant en recuperación de contraseña. Reevaluar si sigue aplicando tras 1.2 (usa su propio repositorio, `PasswordResetTokenArchitectureTest.php`). | Media |
| [#27](https://github.com/pirexia/plataforma-educativa/issues/27) | `Tenant` sin auditoría hasta `admin_action_logs` (1.6). Ver `ADR-036`. No explotable hoy. | Media |
| [#37](https://github.com/pirexia/plataforma-educativa/issues/37) | Redis sin autenticación (`requirepass`). | Baja |
| [#38](https://github.com/pirexia/plataforma-educativa/issues/38) | `infra/quadlet/minio-data.volume` huérfano hasta que exista `minio.container` (0.10d). | Baja |
| [#40](https://github.com/pirexia/plataforma-educativa/issues/40) | Sin escaneo de vulnerabilidades a nivel de imagen del SO (`dependency-scan.yml` solo cubre `composer.lock`/`package-lock.json`). | Baja |
| [#44](https://github.com/pirexia/plataforma-educativa/issues/44) | Contradicción `REQ-CORE-002`/`RMOD-002` sobre quién activa módulos. ADR al arrancar 1.6. | Media |
| [#45](https://github.com/pirexia/plataforma-educativa/issues/45) | Sin análisis antivirus de ficheros subidos (`RSEC-OWASP-012`). Candidato 1.27. | Media |
| [#52](https://github.com/pirexia/plataforma-educativa/issues/52) | Ficheros preexistentes sin `pint --test` limpio (cosmético). | Baja |
| [#58](https://github.com/pirexia/plataforma-educativa/issues/58) | SSO institucional (SAML/OIDC) sin paso asignado hasta `1.4b`. | Planificación |
| [#59](https://github.com/pirexia/plataforma-educativa/issues/59) | Resto de `REQ-AUTH-005` (panel de sesiones activas, cierre remoto, nuevo dispositivo). **Implementado en `1.2b`** (rama `feature/REQ-AUTH-005-1.2b-sesiones-activas`), pendiente del pipeline de revisión independiente antes de mezclar. Cerrar este issue cuando se mezcle. | Planificación |
| [#60](https://github.com/pirexia/plataforma-educativa/issues/60) | `ValidationErrorFormatter` antepone "core." al `code` de cualquier módulo. Requiere decisión con `architect`. | Media |
| [#61](https://github.com/pirexia/plataforma-educativa/issues/61) | Levantar un bloqueo desde canje/restablecimiento reutiliza `UnlockReason::Correo` a falta de un 4º valor en el enumerado aprobado. Decisión razonada, documentada. | Baja |
| [#65](https://github.com/pirexia/plataforma-educativa/issues/65) | Manuales de usuario no-`admin` (5) sin las pantallas de acceso de 1.2. Ninguno existe todavía. Candidato natural 1.8. | Baja |
| [#69](https://github.com/pirexia/plataforma-educativa/issues/69) | `CA-AUTH-060`-`063` sin test automatizado (necesitan *fixtures* de tenant/branding). | Media |
| [#71](https://github.com/pirexia/plataforma-educativa/issues/71) | Login en desarrollo por navegador exige que la SPA se sirva desde `demo.plataforma.test`, no `localhost` — parche temporal fijado a un único tenant. Decisión definitiva pendiente (¿derivar el host de `window.location`?). | Baja (resuelto en la práctica) |
| [#78](https://github.com/pirexia/plataforma-educativa/issues/78) | Generalizar `REQ-GOB-004` (AMPA) a módulo de varias asociaciones internas por tenant, con roles propios y admin delegado. **Alcance cerrado 2026-08-25**: núcleo común = documentación+actas+calendario+roles+admin delegado; cuotas de socio con impago y firma electrónica son **extensiones opcionales activables por asociación** (no toda asociación las necesita), reutilizando infraestructura de `REQ-FIN`/`REQ-DOC` (independencia solo legal/contable). Investigación de mercado hecha (sin precedente para el admin delegado). Pendiente de `spec-writer`/`architect`. | Planificación |
| [#82](https://github.com/pirexia/plataforma-educativa/issues/82) | Modelar concepto de apoderado/firmante autorizado en `REQ-DOC-002`, general para el núcleo (no solo asociaciones). Surgió al perfilar #78, 2026-08-25. | Planificación |
| ~~[#83](https://github.com/pirexia/plataforma-educativa/issues/83)~~ | **Resuelto y mezclado 2026-08-26** (PR #87). Patrón `withCookie()` + `postJson/getJson/deleteJson` corregido en `SessionEndpointTest.php` (CA-AUTH-004/010/016), verificado contra fallo real. | Media (cerrado) |
| [#86](https://github.com/pirexia/plataforma-educativa/issues/86) | `HomeView.vue` llama a `GET /api/v1/health` (404); el healthcheck real está en `/api/health`, fuera de `v1`. Detectado de pasada verificando `1.2b` en navegador, sin relación con ese módulo. No resuelto (severidad Baja, `CLAUDE.md §5`). | Baja |
| [#79](https://github.com/pirexia/plataforma-educativa/issues/79) | Cuota de tenant por espacio de almacenamiento y nº de miembros activos, posible factor de paquetización comercial. Sin modelo de planes/tiers todavía. Idea del usuario 2026-08-25. | Planificación |
| [#80](https://github.com/pirexia/plataforma-educativa/issues/80) | Integración opcional con Google Workspace (Drive) como repositorio documental del centro. El usuario lo pensará con más calma, solo anotado. Idea del usuario 2026-08-25. | Planificación |

---

## Siguiente paso concreto

**Rama abierta: `feature/REQ-AUTH-005-1.2b-sesiones-activas`, colgada de `develop`, empujada a `origin` (commit `47d050b`, incluye el merge de `develop` con el fix de #83).** Working tree limpio, todo commiteado. `1.2b` implementado y en verde (backend 275 tests, frontend 10 + `eslint`/`vue-tsc`/`lint:i18n`, `pint`/`phpstan` limpios) — ver el resumen completo en "Estado actual".

**Issue [#83](https://github.com/pirexia/plataforma-educativa/issues/83) — RESUELTO Y MEZCLADO** (2026-08-26): corregido en rama propia `fix/REQ-AUTH-001-patron-cookie-tests-http` (desde `develop`, independiente de `1.2b`), verificado rompiendo deliberadamente cada mecanismo real (lectura de cookie, logout, `regenerate()`) para confirmar que los tests corregidos detectan el fallo. Mezclado vía PR [#87](https://github.com/pirexia/plataforma-educativa/pull/87) (commit `6d6f6e1`), rama borrada, issue cerrado. `1.2b` ya absorbió ese merge (arriba) — el conflicto en `tests/Pest.php` (ambas ramas añadieron los mismos helpers por separado) se resolvió a mano, y de paso se corrigió un comentario que citaba mal el issue (decía #82, es #83).

1. **Lanzar el pipeline de revisión independiente antes de mezclar `1.2b`** (`CLAUDE.md §5.4`, mismo patrón que 1.2): `db-reviewer` (migraciones de `user_known_devices`/`user_sessions`, RLS), `security-reviewer` (autoservicio por identidad en los tres endpoints nuevos, redacción de `session_id`, cookie `pge_device`), `doc-reviewer` (coherencia de `docs/modulos/REQ-AUTH/*.md` Parte B con lo realmente implementado). **No hacer merge ni PR de `1.2b` sin que el usuario lo pida.**
2. **Issue [#78](https://github.com/pirexia/plataforma-educativa/issues/78), sin empezar, no bloquea nada**: alcance ya cerrado con el usuario (ver tabla de abajo); falta que `spec-writer` lo aborde cuando le toque turno en el plan.
3. **Requisito de entorno para probar en navegador real** (issue #71): `apps/web/vite.config.ts` y `compose.yaml` sirven la SPA desde `demo.plataforma.test:5173`; necesita `127.0.0.1 demo.plataforma.test` en el `hosts` de **Windows** (no el de WSL2) — ya confirmado presente en esta máquina de desarrollo (usado para verificar `1.2b` con Playwright MCP).
4. **Nota de entorno, sigue aplicando**: `.env` no editable desde esta sesión (bloqueo de permisos). Variables inline en cada `Bash`, `podman exec <servicio> printenv`. `SESSION_LIFETIME=480`, `CORS_ALLOWED_ORIGINS` y `VITE_API_URL` siguen parcheados en `compose.yaml` en vez de en sus `.env` respectivos — trasladarlos si algún día `.env` es editable desde la sesión.
