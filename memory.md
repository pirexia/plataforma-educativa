# memory.md — Estado del proyecto

> Fichero de estado entre sesiones. Claude lo lee al arrancar y lo actualiza tras cada hito.
> Mantenerlo **corto**: si supera unas 150 líneas, archiva lo antiguo en `docs/historial/`.

---

## Estado actual

**Fase**: 0 cerrada en la práctica (lo pendiente de `0.10`-`0.12` es negocio, no código — ver "Bloqueantes"). **Fase 1, bloque A: 1.1, 1.2, 1.2b y 1.3 cerrados y mezclados. `1.3b` con las 4 piezas completas y verificadas**, pendiente solo de revisión independiente y merge (detalle en "Siguiente paso concreto").

**Norma nueva, en `CLAUDE.md §3` desde 2026-08-27 (v2.2.1)**: relanzar un subagente de ejecución (`implementer`) tras un fallo o corte de cuota no es licencia para decidir alcance por su cuenta — sigue la especificación aprobada al pie de la letra. Motivada por un caso real de `1.3` (ver historial).

**1.3 · `REQ-AUTH-003`: MFA — TOTP, obligatoriedad por rol y restablecimiento — CERRADO Y MEZCLADO** (2026-08-26/27). PR [#107](https://github.com/pirexia/plataforma-educativa/pull/107) (*squash*, commit `cd13e8a`). Dos cortes de cuota consecutivos, un recorte de alcance no autorizado detectado y corregido (`GET /mfa-compliance/users`), pipeline de revisión independiente con 8 hallazgos (0 seguridad, corregidos el resto). Detalle completo en `docs/historial/1.3-mfa-obligatorio-por-rol.md`.

**1.2b · `REQ-AUTH-005` puntos 2-4: sesiones activas, cierre remoto y detección de dispositivo — CERRADO Y MEZCLADO** (2026-08-25/26). PR [#91](https://github.com/pirexia/plataforma-educativa/pull/91) (*squash*, commit `12fe917`). Pipeline de revisión independiente (`security-reviewer`/`db-reviewer`/`doc-reviewer`) con 3 hallazgos Media, todos corregidos y verificados antes de mezclar. Detalle completo en `docs/historial/1.2b-sesiones-activas.md`.
**1.2 · `REQ-AUTH`: autenticación local y sesiones — CERRADO Y MEZCLADO** (2026-08-22/25). PR [#76](https://github.com/pirexia/plataforma-educativa/pull/76) (*squash*, commit `0d34587`). Revisión independiente (`security-reviewer`/`doc-reviewer`) con 2 hallazgos Alta de seguridad y 7 Media de documentación, todos corregidos antes de mezclar. Detalle completo en `docs/historial/1.2-auth-local-sesiones.md`.
**1.1 · `REQ-CORE`: tenants y usuarios — CERRADO Y MEZCLADO** (2026-08-21/22). PR [#56](https://github.com/pirexia/plataforma-educativa/pull/56). Detalle completo en `docs/historial/1.1-core-tenants-usuarios.md`.
**Rama activa**: `feature/REQ-AUTH-003-1.3b-mfa-correo-excepciones` (pusheada a `origin`, sin merge todavía — 1.3b no está cerrado). `develop` sigue limpia y sincronizada.

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
| [#109](https://github.com/pirexia/plataforma-educativa/issues/109) | 4 tareas de mantenimiento de MFA (`PurgeMfaChallenges`/`PurgeMfaEnrollments`/`PurgeMfaFactors`/`MaterializeMfaObligations`) declaradas en `operacion.md §C.4` desde 1.3 pero ausentes del código. **Cerrado 2026-08-27**: las cuatro escritas, registradas en `auth:purge-maintenance`/`auth:mfa-obligations` nuevo y programadas en `routes/console.php` (pieza 4 de `1.3b`, commit `40fa40a`). | Resuelta |
| [#110](https://github.com/pirexia/plataforma-educativa/issues/110) | `Route::getController()` cachea el controlador en el propio objeto `Route` entre peticiones HTTP simuladas de un mismo test Pest — un controlador con una dependencia `scoped()` en el constructor (p. ej. `SessionController` con `MfaPolicy`) puede quedarse con un valor obsoleto si el test cambia el estado subyacente entre dos llamadas a la misma ruta. Detectado escribiendo `CA-AUTH-152` (1.3b). Arreglado solo en `tests/Pest.php` (`resetSessionState()` ahora también `flushController()` de cada ruta); no explotable en producción con el modo de despliegue actual (`ADR-037`). | Media |
| [#111](https://github.com/pirexia/plataforma-educativa/issues/111) | `SECURITY.md` (sin tocar desde el paso 0.9) describe ausencia de autenticación/MFA/permisos que ya existen desde 1.1-1.3. Detectado en auditoría de vigencia de documentación raíz, 2026-08-27. **Siguiente paso tras cerrar `1.3b`.** | Media |
| [#112](https://github.com/pirexia/plataforma-educativa/issues/112) | `README.md` desactualizado: "Fase 0, 0.1-0.5 cerrados" + tabla de versiones desincronizada de 5 documentos. Misma auditoría. **Siguiente paso tras cerrar `1.3b`.** | Baja |
| [#113](https://github.com/pirexia/plataforma-educativa/issues/113) | `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`: falta la fila 3.1.1 en su propio historial de versiones. Misma auditoría. **Siguiente paso tras cerrar `1.3b`.** | Baja |
| [#114](https://github.com/pirexia/plataforma-educativa/issues/114) | `PRIVACY.md §2.1` no cataloga la cookie de sesión/`XSRF-TOKEN` de 1.2 en el RAT. Misma auditoría. **Siguiente paso tras cerrar `1.3b`.** | Media |
| [#115](https://github.com/pirexia/plataforma-educativa/issues/115) | `MfaResetService::reset()` lanzaba el 403 de autorrestablecimiento sin `detailKey` — idéntico al 403 genérico de permiso, contra lo que `api.md §C.5` ya documentaba desde 1.3 ("se distingue en el mensaje"). Detectado implementando el self-check análogo de excepciones (1.3b, `RN-AUTH-81`). **Corregido en el mismo commit** (`c7f276a`) que añade `MfaExemptionService`, con el mismo criterio en los dos sitios y test ampliado (`CA-AUTH-138`). | Resuelta |

---

## Siguiente paso concreto

**`1.3b` en curso** (`REQ-AUTH-003`), rama `feature/REQ-AUTH-003-1.3b-mfa-correo-excepciones`. Especificación aprobada y commiteada (`8b4e6df`, Parte D de los 5 ficheros de `docs/modulos/REQ-AUTH/`), con las tres preguntas abiertas resueltas (`OPEN-AUTH-27` solo a nivel de tenant, `OPEN-AUTH-28` sí incluir pantalla, `OPEN-AUTH-29` las 4 tareas dentro de esta rama).

**Estado real a 2026-08-27, verificado (no solo el resumen del último subagente):**

1. **Pieza 1 (correo como segundo factor) — completa y commiteada** (`9db171d`, sobre la migración `9b60629`): alta/confirmación/login en dos pasos con `email`, `DestinationMasker`, `MfaDeliveryCode`, dos correos nuevos, `GET /auth/mfa` ampliado. `CA-AUTH-146`-`159` + `167` ampliado, todos en verde.
2. **Pieza 2 (excepciones temporales) — completa y commiteada** (`c7f276a`): `MfaExemptionService`, tres endpoints (`/mfa-exemptions`), tres permisos nuevos (`exencion_mfa.crear/leer/eliminar`, solo `administrador_centro`), `ReopenExpiredMfaExemptions` (la clase; su registro en el *scheduler* es de la pieza 4). De paso, corregido el hallazgo `#115` (mensaje del 403 de autorrestablecimiento). `CA-AUTH-139`, `160`-`166`, `169` + test de concesión, todos en verde.
3. **Pieza 4 (las cuatro tareas de mantenimiento de #109) — completa y commiteada** (`40fa40a`), issue `#109` cerrado: `PurgeMfaEnrollments`/`PurgeMfaFactors`/`PurgeMfaChallenges` añadidas a `auth:purge-maintenance` (diario); comando nuevo `auth:mfa-obligations` (horario) despacha `MaterializeMfaObligations` y `ReopenExpiredMfaExemptions`; las dos cadencias registradas en `routes/console.php`. `CA-AUTH-170`-`175`, todos en verde.
4. **Pieza 3 (pantalla `/administracion/mfa`) — COMPLETA y commiteada** (`4861e39`), tras el frontend recuperado de la pieza 1 (`0fe7716`). **`1.3b` tiene ya las 4 piezas completas.** No aporta ni un endpoint ni un permiso nuevo (consume los siete que ya existían tras la pieza 2), cuatro áreas (cumplimiento, conmutador `mfa_required` con vista previa, restablecimiento, excepciones), `CA-AUTH-176`. Detalle completo en `funcional.md §D.1.3`/`§D.9.1`.
5. **Verificado en navegador real (Playwright MCP), 2026-08-28, tenant `demo` (id 211), usuario `admin@example.com` con rol `administrador_centro`** (contraseña fijada vía `tinker` solo para esta verificación, dato sintético): (a) login con correo como 2FA — selector de método (`RadioGroup`) con TOTP y correo, destino enmascarado `a···n@e···e.com`, cuenta atrás de reenvío, código real leído del *log* de correo (`MAIL_MAILER=log`, sin *worker* de colas corriendo por defecto en el contenedor — hubo que vaciar la cola `auth-mail` a mano con `queue:work --stop-when-empty` para que el job se procesara) y verificación correcta; (b) pantalla `/administracion/mfa`: cumplimiento con recuentos y listado tras elegir rol, vista previa del conmutador `mfa_required` marcada explícitamente como simulación con confirmación explícita, concesión de una excepción temporal (`POST` `201`) y su revocación (`DELETE` `204`) verificadas por red, y el autorrestablecimiento bloqueado con el mensaje distinguido "No puedes restablecer tu propio MFA." mostrado en la propia área, sin redirección. Sin hallazgos Alta/Crítica. Un detalle Baja observado y no corregido (no es mecánico ni acotado): al entrar en la pantalla sin haber elegido rol todavía, la tabla de cumplimiento aparece con las dos únicas filas del tenant ya cargadas mientras el texto de encima sigue diciendo "Elige un rol para ver su cumplimiento" — inconsistencia cosmética de estado inicial, no de datos (al elegir el rol explícitamente todo cuadra). Sin issue abierto todavía.
6. **Hallazgo de test-infra corregido de paso, documentado como `#110`** (severidad Media): `Route::getController()` cachea el controlador entre peticiones HTTP simuladas de un mismo test — afecta solo a tests que llaman la misma ruta dos veces esperando que un binding `scoped()` refleje un cambio de estado intermedio. Arreglado en `tests/Pest.php` (`resetSessionState()`), no en producción.
7. **Todo verificado por herramienta, no solo por el resumen de los subagentes**: `pint --test`/`phpstan` limpios en todo `apps/api`; 169 tests Pest de `Auth`/`Core` en verde; frontend con `eslint`/`vue-tsc`+`vite build`/`npm run test` (Vitest, 20 tests)/`lint:i18n` (24 ficheros `.vue`, sin literales) todos limpios. **Lo único que falta para cerrar `1.3b`**: la revisión independiente (`security-reviewer`/`db-reviewer`/`doc-reviewer`) y el merge — lo hace la sesión orquestadora a continuación, no un `implementer`.
7b. **Corrección 2026-08-27, 22h (ya resuelta)**: un seguimiento anterior daba la pieza 1 por completa sin verificar `apps/web` — faltaban las 3 pantallas de autoservicio y `MfaEmailEnrollment.vue` que `funcional.md §D.1.4`/§D.9 ya contaban en el tamaño aprobado del paso. Detectado por el `implementer` de la pieza 3 antes de tocar código, y completado en el commit `0fe7716`.
8. **Requisito de entorno para probar en navegador real** (issue #71): la SPA se sirve desde `demo.plataforma.test:5173`; necesita `127.0.0.1 demo.plataforma.test` en el `hosts` de **Windows** — confirmado presente.
9. **Nota de entorno**: `compose.yaml` ya fija `target: dev` en `api`/`web` (issue #96, cerrado). Contenedor `api`: `podman exec -w /var/www/html plataforma-api <cmd>`. Contenedor `web`: **`WORKDIR` es `/app`, no `/var/www/html`** — `podman exec -w /app plataforma-web <cmd>`. Suite Pest completa necesita `php -d memory_limit=512M` (128M por defecto no basta, issue #106, ajeno a `Auth`).
10. **`.env` sigue sin ser editable desde esta sesión** (bloqueo de permisos). `SESSION_LIFETIME=480`, `CORS_ALLOWED_ORIGINS` y `VITE_API_URL` siguen parcheados en `compose.yaml` en vez de en sus `.env` respectivos.
11. **Tras cerrar `1.3b`** (mezclado a `develop`, cierre de sesión completo por `CLAUDE.md §3`): siguiente paso es un `chore/` dedicado para corregir los 4 issues de vigencia de documentación abiertos en la auditoría de 2026-08-27 (`#111` `SECURITY.md`, `#112` `README.md`, `#113` `docs/REQUISITOS-...md`, `#114` `PRIVACY.md`). Decisión explícita del usuario de no interrumpir `1.3b` para esto.
12. **Esta sesión cierra aquí** (entre piezas del plan, `CLAUDE.md §3`): la pieza 3 empieza en una sesión nueva, con contexto limpio, exactamente el punto que `funcional.md §D.1.4` señaló como el único recortable sin dejar el paso incoherente si hacía falta parar.
