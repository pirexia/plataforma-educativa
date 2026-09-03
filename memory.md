# memory.md — Estado del proyecto

> Fichero de estado entre sesiones. Claude lo lee al arrancar y lo actualiza tras cada hito.
> Mantenerlo **corto**: si supera unas 150 líneas, archiva lo antiguo en `docs/historial/`.

---

## Estado actual

**Fase**: 0 cerrada en la práctica (lo pendiente de `0.10`-`0.12` es negocio, no código — ver "Bloqueantes"). **Fase 1, bloque A: 1.1, 1.2, 1.2b, 1.3, 1.3b, 1.4 y 1.4b cerrados y mezclados.** Siguiente paso: **`1.4c`** (SAML 2.0, `ADR-043`).

**1.4b · `REQ-AUTH-004` (parte 1/2): SSO institucional OIDC y aprovisionamiento por emparejamiento — CERRADO Y MEZCLADO** (2026-09-01/02). PR [#149](https://github.com/pirexia/plataforma-educativa/pull/149). `architect` (evaluación previa, `ADR-043`) dividió el requisito en 1.4b (OIDC+aprovisionamiento) y 1.4c (SAML, posterior) por cuatro frentes que cambian a la vez en la frontera de protocolo. Aprovisionamiento **solo por emparejamiento** (decisión del usuario, riesgo de `INV-008` con alumnado en el directorio institucional). `db-reviewer` encontró un Alta bloqueante (DDL bloqueante sobre `user_identities`, tabla viva desde 1.4) y `doc-reviewer` 5 Alta (documentos raíz desincronizados, manual de admin sin el autoservicio nuevo), ambos con segunda pasada que a su vez encontró hallazgos Media nuevos introducidos por la propia corrección — todos resueltos y reverificados contra el fichero real, no de palabra. CI encontró en cascada tres huecos reales de entorno que ninguna ejecución local repetida detectó (`AUTH_SSO_ALLOW_INSECURE_DISCOVERY` sin forzar en `phpunit.xml`, sin servidor HTTP en el job de tests, migrando contra la base de datos equivocada). 451 tests Pest + 106 Vitest en verde, 14/14 *checks* de CI. Detalle completo en `docs/historial/1.4b-sso-institucional-oidc.md`.

**1.4 · `REQ-AUTH-002`: login con Google y fusión de cuentas — CERRADO Y MEZCLADO** (2026-08-31/09-01). PR [#143](https://github.com/pirexia/plataforma-educativa/pull/143). Interpretación restrictiva decidida por el usuario: Google nunca crea usuarios nuevos, solo fusiona/vincula con cuenta local ya existente. Hallazgo crítico en plena implementación (issue [#140](https://github.com/pirexia/plataforma-educativa/issues/140), parado y reportado, no decidido por el `implementer`): valor por defecto inseguro de `AUTH_OAUTH_DRIVER`, corregido antes de tocar código. Cuatro cortes de cuota, cada uno protegido con `wip`+push, nada perdido. Revisión independiente en dos pasadas de `doc-reviewer` (2 Alta bloqueantes en la primera, incluida una justificación del `implementer` verificada como **falsa** contra `git log` — corregidas y reverificadas de forma independiente en la segunda, no de palabra); `security-reviewer`/`db-reviewer` sin bloqueantes. 406 tests Pest + 39 Vitest en verde. Detalle completo en `docs/historial/1.4-google-login-fusion-cuentas.md`.

**Norma nueva, en `CLAUDE.md §3` desde 2026-08-27 (v2.2.1)**: relanzar un subagente de ejecución (`implementer`) tras un fallo o corte de cuota no es licencia para decidir alcance por su cuenta — sigue la especificación aprobada al pie de la letra. Motivada por un caso real de `1.3` (ver historial).

**`chore/` de vigencia de documentación + CI — CERRADO Y MEZCLADO** (2026-08-31). Corrigió 4 documentos raíz desactualizados desde hacía 5 fases y una CI rota desde 1.2 (imagen de producción de la API incluida), nunca detectada por falta de permiso de checks del token — resuelto. Detalle completo en `docs/historial/chore-vigencia-documentacion-y-ci.md`.
**1.3b · `REQ-AUTH-003`: MFA — correo como segundo factor, excepciones temporales y administración — CERRADO Y MEZCLADO** (2026-08-27/31). PR [#123](https://github.com/pirexia/plataforma-educativa/pull/123) (*squash*, commit `dd68f48`). Revisión independiente sin hallazgos de seguridad, 2 Media corregidos el mismo día (índices de purga ausentes; dependencias `@vueuse/core`/`@tanstack/vue-table` y manual sin documentar tras la pieza 3). Detalle completo en `docs/historial/1.3b-mfa-correo-excepciones.md`.
**1.3 · `REQ-AUTH-003`: MFA — TOTP, obligatoriedad por rol y restablecimiento — CERRADO Y MEZCLADO** (2026-08-26/27). PR [#107](https://github.com/pirexia/plataforma-educativa/pull/107) (*squash*, commit `cd13e8a`). Dos cortes de cuota consecutivos, un recorte de alcance no autorizado detectado y corregido (`GET /mfa-compliance/users`), pipeline de revisión independiente con 8 hallazgos (0 seguridad, corregidos el resto). Detalle completo en `docs/historial/1.3-mfa-obligatorio-por-rol.md`.

**1.2b · `REQ-AUTH-005` puntos 2-4: sesiones activas, cierre remoto y detección de dispositivo — CERRADO Y MEZCLADO** (2026-08-25/26). PR [#91](https://github.com/pirexia/plataforma-educativa/pull/91) (*squash*, commit `12fe917`). Pipeline de revisión independiente (`security-reviewer`/`db-reviewer`/`doc-reviewer`) con 3 hallazgos Media, todos corregidos y verificados antes de mezclar. Detalle completo en `docs/historial/1.2b-sesiones-activas.md`.
**1.2 · `REQ-AUTH`: autenticación local y sesiones — CERRADO Y MEZCLADO** (2026-08-22/25). PR [#76](https://github.com/pirexia/plataforma-educativa/pull/76) (*squash*, commit `0d34587`). Revisión independiente (`security-reviewer`/`doc-reviewer`) con 2 hallazgos Alta de seguridad y 7 Media de documentación, todos corregidos antes de mezclar. Detalle completo en `docs/historial/1.2-auth-local-sesiones.md`.
**1.1 · `REQ-CORE`: tenants y usuarios — CERRADO Y MEZCLADO** (2026-08-21/22). PR [#56](https://github.com/pirexia/plataforma-educativa/pull/56). Detalle completo en `docs/historial/1.1-core-tenants-usuarios.md`.
**Rama activa**: `develop`, limpia y sincronizada con `origin` tras el merge de `1.4b`. Sin rama de trabajo abierta — toca crear una nueva para `1.4c`.

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
| ADR-042 | Dependencia externa de login con Google: `laravel/socialite ^5.30` tras `ExternalIdentityProvider` |
| ADR-043 | `REQ-AUTH-004` dividido en 1.4b (OIDC + aprovisionamiento por emparejamiento) y 1.4c (SAML, posterior) — SAML rompe a la vez sesión, dependencia, riesgo y ciclo del certificado |

---

## Trabajo en curso

- **`1.4c` · `REQ-AUTH-004` (parte 2/2, SAML 2.0) — EN CURSO, verificado hasta `86009a9` (2026-09-03).** Backend prácticamente completo y verificado por ejecución real: 465/465 Pest en verde (incluye las 14 de `SamlCatalogTest`, nuevo), Pint limpio. Commits verificados de este lote: `feb4c72`..`ed09d16` (núcleo backend: envoltorio `php-saml`, catálogo, certificados, ACS, IdP simulado, purgas, tareas programadas, traducciones, OpenAPI, docs raíz y manual admin), `b959c84` (fix: `SamlMetadataRefreshService` no resucitaba certificados retirados), `a36c480` (fix: índice único de `saml_identity_provider_settings` parcial sobre `deleted_at`), `86009a9` (tests del catálogo SAML + auxiliares de flujo). `4001ef8`/`a7642f1`/`14637eb` tocan `CLAUDE.md`, no este paso — ver esa sección aparte. **Puntos delicados de la especificación revisados y conformes contra el código real**: sin SSO iniciado por el IdP (solo rutas SP-initiated), VO propio `SamlIdentity` (no reutiliza `ExternalIdentity`), clave de firma del SP por fichero+variable de entorno (`AUTH_SAML_SP_SIGNING_KEY_PATH`/`_CERT_PATH`, no en BD), excepción de CSRF acotada a un grupo de rutas propio solo para el ACS (sin lista global de exenciones). **Qué falta con certeza**: `apps/web/` — el frontend, según `funcional.md §G.9` son **dos pantallas modificadas, ninguna nueva** (`/cuenta/seguridad` mínimo; `/administracion/sso` y `/administracion/sso/{public_id}` con soporte de `protocol`, metadatos, certificados y firma de peticiones), con sus i18n (4 idiomas) y tests Vitest — sin empezar al momento de esta nota. Revisión independiente (`security-reviewer`/`db-reviewer`/`doc-reviewer`) todavía no lanzada para este paso.
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
| [#27](https://github.com/pirexia/plataforma-educativa/issues/27) | `Tenant` sin auditoría hasta `admin_action_logs` (1.6). Ver `ADR-036`. No explotable hoy. | Media |
| [#37](https://github.com/pirexia/plataforma-educativa/issues/37) | Redis sin autenticación (`requirepass`). | Baja |
| [#38](https://github.com/pirexia/plataforma-educativa/issues/38) | `infra/quadlet/minio-data.volume` huérfano hasta que exista `minio.container` (0.10d). | Baja |
| [#40](https://github.com/pirexia/plataforma-educativa/issues/40) | Sin escaneo de vulnerabilidades a nivel de imagen del SO (`dependency-scan.yml` solo cubre `composer.lock`/`package-lock.json`). | Baja |
| [#44](https://github.com/pirexia/plataforma-educativa/issues/44) | Contradicción `REQ-CORE-002`/`RMOD-002` sobre quién activa módulos. ADR al arrancar 1.6. | Media |
| [#45](https://github.com/pirexia/plataforma-educativa/issues/45) | Sin análisis antivirus de ficheros subidos (`RSEC-OWASP-012`). Candidato 1.27. | Media |
| [#60](https://github.com/pirexia/plataforma-educativa/issues/60) | `ValidationErrorFormatter` antepone "core." al `code` de cualquier módulo. Requiere decisión con `architect`. | Media |
| [#61](https://github.com/pirexia/plataforma-educativa/issues/61) | Levantar un bloqueo desde canje/restablecimiento reutiliza `UnlockReason::Correo` a falta de un 4º valor en el enumerado aprobado. Decisión razonada, documentada. | Baja |
| [#62](https://github.com/pirexia/plataforma-educativa/issues/62) | `SESSION_LIFETIME` sigue parcheado en `compose.yaml`, no en `apps/api/.env` real (bloqueo de permisos de esta sesión sin diagnosticar) — 3 de 4 puntos "Pendiente" propios del issue sin resolver. Revisado y confirmado vigente el 2026-08-31 (no cerrar solo porque el título diga "resuelto": eso se refiere a la caída puntual del contenedor, no a este trabajo pendiente). | Media |
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
| [#106](https://github.com/pirexia/plataforma-educativa/issues/106) | Suite Pest completa agota `memory_limit=128M` del PHP CLI en local (no afecta a CI). Causa precisa (1.4): `artisan test` lanza `pest` como subproceso que no hereda el flag `-d` del padre. Rodeo: `php -d memory_limit=512M ./vendor/bin/pest` directamente, no `artisan test`. | Baja |
| [#116](https://github.com/pirexia/plataforma-educativa/issues/116) | `/administracion/mfa`: la tabla de cumplimiento muestra filas del tenant antes de elegir rol, mientras el texto dice "Elige un rol para ver su cumplimiento". Detectado en la verificación en navegador de la pieza 3 (1.3b), 2026-08-28. No corregido a propósito (cosmético, no mecánico). | Baja |
| [#117](https://github.com/pirexia/plataforma-educativa/issues/117) | `/mfa-exemptions` sin clave propia de *rate limit* en `auth-local.php` (a diferencia de `mfa_resets_admin`). No explotable (ya protegido por permiso). Revisión independiente de 1.3b, 2026-08-31. No corregido a propósito. | Baja |
| [#120](https://github.com/pirexia/plataforma-educativa/issues/120) | Pantalla `/administracion/mfa` (1.3b, pieza 3) sin test automatizado, solo verificación manual por navegador — mismo patrón débil de frontend que 1.2/1.2b/1.3. Revisión independiente, 2026-08-31. No corregido a propósito. | Baja |
| [#128](https://github.com/pirexia/plataforma-educativa/issues/128) | Ningún *worker* de colas (Horizon/`queue:work`) desplegado pese a 32 clases `ShouldQueue` reales y `QUEUE_CONNECTION=database` por defecto — ni en `compose.yaml` ni en `infra/quadlet/*`. Documentación ya corregida para reflejarlo (`SYSADMIN.md`/`RUNBOOK.md`); el despliegue en sí es una decisión de infraestructura (`ADR-028`/`ADR-037`) fuera del alcance del `chore/` que lo encontró, 2026-08-31. | Alta |
| [#141](https://github.com/pirexia/plataforma-educativa/issues/141) | (1.4) El `302` del *callback* de Google, relativo y correcto en producción (`ADR-028`, un solo origen), aterriza en el puerto de la API y no el de la SPA en el entorno de desarrollo de orígenes separados (`ADR-030`/issue #71) — backend verificado correcto contra BD, solo síntoma de navegación manual en dev. Dos propuestas sin decidir, mismo peso que #71. | Media |
| [#142](https://github.com/pirexia/plataforma-educativa/issues/142) | (1.4, hallazgo de `db-reviewer`) `RecordsAuditTrail` no excluye `last_login_at`/`last_used_at` de ningún evento `updated`, contradice la documentación de `UserIdentity` (1.4) y `MfaFactor` (1.3, mismo problema sin detectar entonces). Toca infraestructura de auditoría compartida — requiere sesión propia. | Media |
| [#145](https://github.com/pirexia/plataforma-educativa/issues/145) | (1.4b) `people.locale` sin `CHECK` y con `DEFAULT` fuera de `{es-ES,en,de,fr}` — columna de `REQ-CORE`, detectada al especificar 1.4b, no agravada por él. | Baja |
| [#146](https://github.com/pirexia/plataforma-educativa/issues/146) | (1.4b) `php artisan serve` de un solo hilo interbloquea el alta desde el **navegador** de un proveedor OIDC auto-referenciado al propio servidor de desarrollo — no afecta a producción (FrankenPHP) ni a CI (Pest es proceso CLI aparte del servidor). | Baja |

---

## Siguiente paso concreto

**`1.4b` cerrado y mezclado el 2026-09-02** (PR [#149](https://github.com/pirexia/plataforma-educativa/pull/149)). Detalle completo en `docs/historial/1.4b-sso-institucional-oidc.md`. Rama local y remota borradas.

**Siguiente paso del plan**: **`1.4c · REQ-AUTH-004 (parte 2/2): SSO institucional — SAML 2.0`** [OPUS + SONNET] — `ADR-043` ya fija el alcance y la secuencia (adaptador SAML sobre el catálogo `identity_providers` ya construido: metadatos, ACS URL por tenant, gestión y rotación de certificados de firma, correlación de petición en servidor porque el `state`-en-sesión de OIDC no aplica). **Dependencia externa sin aprobar todavía**: `ADR-043 §7.3` deja hecha una comparación de las tres bibliotecas SAML PHP candidatas (verificada en vivo contra Packagist el 2026-09-01, con el aviso explícito de que es solo de metadatos, no de código leído) pero **no** aprueba ninguna — el usuario tiene que decidirlo al especificar, igual que `OPEN-AUTH-35`→`ADR-042` en `1.4`. `architect` debe releer esa comparación al arrancar (puede haber cambiado desde entonces) antes de traerla a la fase de decisiones abiertas.

**Notas de proceso que dejó `1.4b` para cualquier paso futuro con CI real**: "pasa en local" no es "pasa en CI" cuando el entorno local lleva meses de estado acumulado — antes de dar un paso por cerrado, empujar a un PR real y esperar los *checks* en verde, no solo la suite local. Tres huecos reales de entorno solo aparecieron así (`AUTH_SSO_ALLOW_INSECURE_DISCOVERY` sin forzar en `phpunit.xml`, sin servidor HTTP en el job de tests para el emisor OIDC simulado, migrando contra la base de datos equivocada — `.env.example` trae `DB_DATABASE=plataforma`, el servicio de CI crea `plataforma_test`). Y una segunda pasada de revisión puede introducir sus propios errores nuevos al corregir los de la primera — verificar también los arreglos de la segunda pasada contra el fichero real, no darlos por buenos.

**Nota para el arranque de sesiones con CI**: el token del MCP de GitHub usado en sesiones anteriores parecía no tener permiso para leer *checks*/Actions (`403` con `gh` CLI); resultó ser que las llamadas del MCP (`mcp__github__pull_request_read` método `get_check_runs`/`get_status`) sí funcionan y usan credenciales distintas a las del `gh` CLI del shell. En 1.4b se confirmó que **`gh pr checks <n>`** (a diferencia de `gh api .../check-runs`) sí funciona con el `gh` CLI del shell — usarlo para el bucle de espera con `Monitor`, y el MCP para inspeccionar un *check* fallido concreto.

**Norma de proceso confirmada en `1.4`**: al cerrar un paso, actualizar `memory.md` (y archivar en `docs/historial/`) **dentro de la última rama del paso, antes de mezclar** — nunca en un PR aparte después. Referenciar el número de PR (ya conocido al crearlo), no esperar al SHA del *squash*.

**Notas de entorno que siguen vigentes**: la SPA para probar en navegador real se sirve desde `demo.plataforma.test:5173` (issue #71, `hosts` de Windows con `127.0.0.1 demo.plataforma.test` ya confirmado). Contenedor `api`: `podman exec -w /var/www/html plataforma-api <cmd>`. Contenedor `web`: `WORKDIR` es `/app`, no `/var/www/html` — `podman exec -w /app plataforma-web <cmd>`. Suite Pest completa necesita `php -d memory_limit=512M` (issue #106). `.env` sigue sin ser editable desde esta sesión (bloqueo de permisos); `SESSION_LIFETIME`/`CORS_ALLOWED_ORIGINS`/`VITE_API_URL` siguen parcheados en `compose.yaml`.
