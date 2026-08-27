# memory.md — Estado del proyecto

> Fichero de estado entre sesiones. Claude lo lee al arrancar y lo actualiza tras cada hito.
> Mantenerlo **corto**: si supera unas 150 líneas, archiva lo antiguo en `docs/historial/`.

---

## Estado actual

**Fase**: 0 cerrada en la práctica (lo pendiente de `0.10`-`0.12` es negocio, no código — ver "Bloqueantes"). **Fase 1, bloque A: 1.1, 1.2, 1.2b y 1.3 cerrados y mezclados.** Siguiente paso libre: `1.3b`, `1.4` o el que decida el usuario.

**Norma nueva, en `CLAUDE.md §3` desde 2026-08-27 (v2.2.1)**: relanzar un subagente de ejecución (`implementer`) tras un fallo o corte de cuota no es licencia para decidir alcance por su cuenta — sigue la especificación aprobada al pie de la letra. Motivada por un caso real de `1.3` (ver historial).

**1.3 · `REQ-AUTH-003`: MFA — TOTP, obligatoriedad por rol y restablecimiento — CERRADO Y MEZCLADO** (2026-08-26/27). PR [#107](https://github.com/pirexia/plataforma-educativa/pull/107) (*squash*, commit `cd13e8a`). Dos cortes de cuota consecutivos, un recorte de alcance no autorizado detectado y corregido (`GET /mfa-compliance/users`), pipeline de revisión independiente con 8 hallazgos (0 seguridad, corregidos el resto). Detalle completo en `docs/historial/1.3-mfa-obligatorio-por-rol.md`.

**1.2b · `REQ-AUTH-005` puntos 2-4: sesiones activas, cierre remoto y detección de dispositivo — CERRADO Y MEZCLADO** (2026-08-25/26). PR [#91](https://github.com/pirexia/plataforma-educativa/pull/91) (*squash*, commit `12fe917`). Pipeline de revisión independiente (`security-reviewer`/`db-reviewer`/`doc-reviewer`) con 3 hallazgos Media, todos corregidos y verificados antes de mezclar. Detalle completo en `docs/historial/1.2b-sesiones-activas.md`.
**1.2 · `REQ-AUTH`: autenticación local y sesiones — CERRADO Y MEZCLADO** (2026-08-22/25). PR [#76](https://github.com/pirexia/plataforma-educativa/pull/76) (*squash*, commit `0d34587`). Revisión independiente (`security-reviewer`/`doc-reviewer`) con 2 hallazgos Alta de seguridad y 7 Media de documentación, todos corregidos antes de mezclar. Detalle completo en `docs/historial/1.2-auth-local-sesiones.md`.
**1.1 · `REQ-CORE`: tenants y usuarios — CERRADO Y MEZCLADO** (2026-08-21/22). PR [#56](https://github.com/pirexia/plataforma-educativa/pull/56). Detalle completo en `docs/historial/1.1-core-tenants-usuarios.md`.
**Rama**: `develop`, limpia y sincronizada con `origin`. Sin rama de trabajo abierta. Todos los *worktrees* y ramas huérfanos de subagentes, de esta sesión y de anteriores, se han limpiado (ramas y directorios borrados).

**Lecciones válidas para cualquier sesión futura con subagentes en *worktrees***: (1) los *worktrees* comparten el mismo `.git` — un commit hecho con el índice desincronizado puede revertir sin querer el trabajo de otro (pasó dos veces en 1.2, autodetectado y corregido ambas). **Antes de commitear: `git fetch` + `git log --oneline -3`.** (2) **`isolation: "worktree"` del `Agent` no siempre parte de la rama actual de la sesión** — en 1.2b, dos de tres agentes lanzados en paralelo con ese parámetro se crearon desde un commit muy antiguo del repositorio (meses atrás), sin nada del trabajo en curso, y corrieron más de una hora así antes de detectarlo. **Verificar siempre** (`git log --oneline -1` dentro del *worktree* del agente, o pedirle que lo confirme al empezar) **antes de dar por buena una revisión o implementación con aislamiento**. Si falla, `TaskStop` + relanzar sin aislamiento (secuencial, en el *checkout* principal) es la salida segura. Y una verificación end-to-end con `curl` **no sustituye una prueba de navegador real**: `curl` ignora restricciones del propio navegador (SameSite, `document.cookie` por host, CORS real) que sí bloquean a un usuario de verdad — pasó con el issue #71 de 1.2, donde el login se dio por "verificado de extremo a extremo" y en realidad no funcionaba en un navegador.

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
| [#60](https://github.com/pirexia/plataforma-educativa/issues/60) | `ValidationErrorFormatter` antepone "core." al `code` de cualquier módulo. Requiere decisión con `architect`. | Media |
| [#61](https://github.com/pirexia/plataforma-educativa/issues/61) | Levantar un bloqueo desde canje/restablecimiento reutiliza `UnlockReason::Correo` a falta de un 4º valor en el enumerado aprobado. Decisión razonada, documentada. | Baja |
| [#65](https://github.com/pirexia/plataforma-educativa/issues/65) | Manuales de usuario no-`admin` (5) sin las pantallas de acceso de 1.2. Ninguno existe todavía. Candidato natural 1.8. | Baja |
| [#69](https://github.com/pirexia/plataforma-educativa/issues/69) | `CA-AUTH-060`-`063` sin test automatizado (necesitan *fixtures* de tenant/branding). | Media |
| [#71](https://github.com/pirexia/plataforma-educativa/issues/71) | Login en desarrollo por navegador exige que la SPA se sirva desde `demo.plataforma.test`, no `localhost` — parche temporal fijado a un único tenant. Decisión definitiva pendiente (¿derivar el host de `window.location`?). | Baja (resuelto en la práctica) |
| [#78](https://github.com/pirexia/plataforma-educativa/issues/78) | Generalizar `REQ-GOB-004` (AMPA) a módulo de varias asociaciones internas por tenant, con roles propios y admin delegado. **Alcance cerrado 2026-08-25**: núcleo común = documentación+actas+calendario+roles+admin delegado; cuotas de socio con impago y firma electrónica son **extensiones opcionales activables por asociación** (no toda asociación las necesita), reutilizando infraestructura de `REQ-FIN`/`REQ-DOC` (independencia solo legal/contable). Investigación de mercado hecha (sin precedente para el admin delegado). Pendiente de `spec-writer`/`architect`. | Planificación |
| [#82](https://github.com/pirexia/plataforma-educativa/issues/82) | Modelar concepto de apoderado/firmante autorizado en `REQ-DOC-002`, general para el núcleo (no solo asociaciones). Surgió al perfilar #78, 2026-08-25. | Planificación |
| [#79](https://github.com/pirexia/plataforma-educativa/issues/79) | Cuota de tenant por espacio de almacenamiento y nº de miembros activos, posible factor de paquetización comercial. Sin modelo de planes/tiers todavía. Idea del usuario 2026-08-25. | Planificación |
| [#80](https://github.com/pirexia/plataforma-educativa/issues/80) | Integración opcional con Google Workspace (Drive) como repositorio documental del centro. El usuario lo pensará con más calma, solo anotado. Idea del usuario 2026-08-25. | Planificación |
| [#81](https://github.com/pirexia/plataforma-educativa/issues/81) | `tenant_id`/RLS en `sessions` del framework, sin paso asignado — endurecimiento futuro (`OPEN-AUTH-10`/`15`). | Media |
| [#86](https://github.com/pirexia/plataforma-educativa/issues/86) | `HomeView.vue` llama a `GET /api/v1/health` (404); el healthcheck real está en `/api/health`, fuera de `v1`. Detectado de pasada verificando `1.2b` en navegador, sin relación con ese módulo. No resuelto. | Baja |
| [#89](https://github.com/pirexia/plataforma-educativa/issues/89) | `infra/quadlet/plataforma.env.example` sin las variables `AUTH_*`/`SESSION_*` de `1.2`/`1.2b`. Plantilla de despliegue incompleta, no bloquea (`OPEN-11` sigue sin resolver). | Media |
| [#90](https://github.com/pirexia/plataforma-educativa/issues/90) | Literal `'—'` sin traducir en `SessionsView.vue` para IP nula. Decisión de convención pendiente (¿clave común de "valor ausente"?), no corrección mecánica. | Baja |
| [#97](https://github.com/pirexia/plataforma-educativa/issues/97) | Fallo de validación YAML preexistente en `apps/api/openapi/components.yaml` (descripción sin comillas, línea ~369, `UserSession.ip_address`, desde 1.2b). Rompe parsers estrictos. Detectado validando el OpenAPI de 1.3, ajeno a él. | Baja |
| [#106](https://github.com/pirexia/plataforma-educativa/issues/106) | Suite Pest completa agota `memory_limit=128M` del PHP CLI en local (no afecta a CI). Rodeo: `php -d memory_limit=512M`. | Baja |

---

## Siguiente paso concreto

**`1.3` cerrado y mezclado** (PR #107, squash) — el usuario confirmó el merge 2026-08-27 tras revisar que toda la documentación quedara al día. Rama `feature/REQ-AUTH-003-1.3-mfa-obligatorio-por-rol` borrada (local y remota). `develop` es la rama activa, limpia y sincronizada con `origin`.

1. **Elegir el siguiente paso** con el usuario: `1.3b` (correo como 2FA, excepciones temporales — modelo de datos `user_mfa_exemptions` ya existe desde `1.3`, solo faltan los 3 endpoints y la lógica —, pantalla de administración si `1.5` se retrasa) o `1.4` (login con Google), según prioridad. No asumir cuál sin confirmar.
2. **Requisito de entorno para probar en navegador real** (issue #71): la SPA se sirve desde `demo.plataforma.test:5173`; necesita `127.0.0.1 demo.plataforma.test` en el `hosts` de **Windows** — confirmado presente.
3. **Nota de entorno, corregida 2026-08-27**: `compose.yaml` ya fija `target: dev` en `api`/`web` (issue #96, cerrado) — cualquier `podman compose build` futuro reconstruye correctamente con Composer/herramientas de desarrollo. Contenedor `api`: `podman exec -w /var/www/html plataforma-api <cmd>`. Contenedor `web`: **`WORKDIR` es `/app`, no `/var/www/html`** — `podman exec -w /app plataforma-web <cmd>`. Suite Pest completa necesita `php -d memory_limit=512M` (128M por defecto no basta, issue #106, ajeno a `Auth`).
4. **`.env` sigue sin ser editable desde esta sesión** (bloqueo de permisos). `SESSION_LIFETIME=480`, `CORS_ALLOWED_ORIGINS` y `VITE_API_URL` siguen parcheados en `compose.yaml` en vez de en sus `.env` respectivos.
