# memory.md — Estado del proyecto

> Fichero de estado entre sesiones. Claude lo lee al arrancar y lo actualiza tras cada hito.
> Solo estado **vigente**. Lo cerrado y mezclado se resume en 1-3 líneas; el detalle original, íntegro, está en `docs/proceso/memoria-archivo.md` (y `docs/historial/`). Si supera ~150 líneas o ~12 KB, archiva lo antiguo en `docs/proceso/`.

---

## Estado actual

**Fase 0** cerrada en la práctica (lo pendiente de `0.10`-`0.12` es negocio, no código; ver «Bloqueantes»). **Fase 1: bloques A, B y C completos y mezclados; bloque D en curso** (`1.14` completa, `1.14b` en curso). Siguiente: ver «Siguiente paso concreto».

**Pasos cerrados y mezclados** (detalle en `docs/proceso/memoria-archivo.md` y `docs/historial/`):
- `1.1` tenants/usuarios (#56), `1.2` auth local (#76), `1.2b` sesiones activas (#91), `1.3` MFA TOTP (#107), `1.3b` MFA correo/excepciones (#123): 2026-08-21/31.
- `1.4` login Google (#143), `1.4b` SSO OIDC (#149), `1.4c` SSO SAML (#160): 2026-08-31/09-04. `chore/` de alcance declarado de subagentes (2026-09-04).
- `1.5` `REQ-PERM` núcleo de autorización (#171, `ADR-044`), `1.5b` editor de roles (#354, 2026-10-05).
- `1.6e` `REQ-BO-005` motor de *feature flags* y mantenimiento posterior (2026-09-22). `1.7`, `1.8`, `1.9`, `1.9b`-`1.9f` (2026-09-23 a 10-04): interfaz de `REQ-CORE` completa, lista de excepciones de `RN-CORE-53` vacía.
- `1.7b` estandarización de módulos (#379, `ADR-056`), `1.10` `REQ-CURSO` ciclo de vida del curso (#386, 2026-10-08), limpieza previa a `1.11` (`ADR-059`/`060`/`061`, dependencias, 2026-10-10).
- `1.11-a` (#417), `1.11-b` módulo `acad` (#423), tanda Bajas (#425), `ADR-064`/`1.15a` en el plan (#432), `1.11b` generador `make:module` (#441), `1.11c` reapertura de curso (#443), `1.11d-a` períodos lectivos (#435), `1.11d-b` escalas de calificación (#439): 2026-10-12/17.
- `1.12-a` horarios (#447), `1.12-b` marcos especiales y reservas (#449), `1.12b` rollover del curso (#460, `worker-long` ratificado, `CLAUDE.md` 2.7.3): 2026-10-19/21.
- `1.14` especificación (#467), `1.14-a` unidades familiares (#468; Bajas #476), `1.14-b` consentimiento de imagen (#478, `ADR-066`), `1.14-c` condiciones de uso y muros de sesión (#487, `ADR-067`), `1.14-d` pantallas de personal (#489): 2026-10-26/28.
- `1.14b` especificación y `ADR-068` (#492), `1.14b-a` autorizados de recogida (#497): 2026-10-29/11-02.

**Lecciones vigentes para subagentes en *worktrees***: (1) los *worktrees* comparten el mismo `.git`: antes de commitear, `git fetch` + `git log --oneline -3`. (2) `isolation: "worktree"` debe partir de la rama actual (`worktree.baseRef` fijado a `"head"` en `.claude/settings.json` desde 2026-09-04): comprobar `git log --oneline -1` dentro del *worktree* la primera vez; si falla, `TaskStop` + relanzar sin aislamiento. (3) Una verificación con `curl` no sustituye una prueba de navegador real (SameSite, CORS, `document.cookie`; caso del issue #71).

**Series `0.10`-`0.10e` (2026-08-19)**: `0.10` VPS Linux europeo (proveedor sin elegir); `0.10b` (marca, `0.11c`), `0.10c` correo, `0.10d` copias y `0.10e` staging, pendientes. No re-preguntar salvo decisión nueva.

**Modelos (`CLAUDE.md` 2.9.0)**: restauración de la configuración del 08/10 en `docs/proceso/restauracion-configuracion.md`. Vigilar informes poco fiables de agentes Haiku (cifras de tests inventadas, traducciones que rompen parámetros, escrituras desde `explorer`).

**Procesos vigentes** (en `CLAUDE.md`/skills): cierre automático al aviso de cuota (la hora de reset no llega al modelo: preguntarla); ahorro de cuota y E2E real (`§2`, `§3`).

**Centro `demo`**: `tests/Support/e2e-real-tenant.php setup demo` (admin `e2e-admin@example.com`, contraseña en la conversación del 2026-10-15); `npm run test:e2e:real` lo recrea con otra contraseña (permitido sin pedir permiso). Demo persistente = `REQ-SEED` (`1.15b`).

---

## Decisiones tomadas

Vigentes, condensadas (texto y razonamiento completos en el ADR citado, `docs/adr/` o `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` §18):

| ADR | Decisión vigente |
|-----|------------------|
| 001, 002, 004 | Multi-tenant BD compartida con `tenant_id` + RLS; monolito modular; borrado lógico / anonimización / purga |
| 007, 008, 023 | Laravel + Vue 3 TS + PostgreSQL; móvil PWA → Capacitor; Tailwind + shadcn-vue + TanStack Table |
| 015, 016, 020, 021 | Segmento concertados de Madrid (Colegio Miramadrid); complementarios a Raíces/Roble (excepción 0-3 privado); régimen jurídico por etapa; idiomas es-ES, en, de, fr |
| 024, 025, 026, 027 | Compose sobre VPS → Kubernetes en E2; auth SPA por cookie (prohibido JWT en `localStorage`); documentación híbrida; Podman y Quadlet (concretado por `037`) |
| 028, 029, 030, 037 | Topología de red; `public_id` ULID, `TIMESTAMPTZ`, `text`, céntimos; WSL2 con solo datos sintéticos; `compose.yaml` solo desarrollo, producción solo Quadlet, imágenes en GHCR, FrankenPHP clásico |
| 031, 032 | `REQ-TRAN` ampliado a 12 requisitos (SHOULD, fase 2); lista maestra única de autorizados a recoger en `REQ-FAM-UNIT-005` |
| 035, 036, 039, 040 | `audit_logs.changes` sin valores identificativos; `Tenant` auditado en `admin_action_logs`; `audit_logs.event` 9 valores y `actor_type` 6; `Auditable::auditExcludedEvents()` |
| 042, 043 | `laravel/socialite ^5.30` tras `ExternalIdentityProvider`; `REQ-AUTH-004` dividido en 1.4b (OIDC) y 1.4c (SAML) |
| 044 | `REQ-PERM`: ámbitos cerrados con `ScopeResolver` por módulo; el resolutor acota consultas; multi-rol por unión con `deny` ciego al ámbito; categoría especial en conjunción |
| 045, 046, 047 | `REQ-BO`: contratación de módulos (potestad del Super Admin); superficie separada (Opción A, `platform_sessions`, `runAsPlatform`); categoría «plataforma con visibilidad por tenant afectado» (`affected_tenant_id`) |
| 049, 050 | Codex: segunda opinión solo lectura; `050` sustituye `049 §8`/`§9` y lo mantiene como verificación permanente y opcional |
| 056 | Estandarización de módulos: reglas `AR-01`…`AR-12` con excepciones que solo pueden reducirse (ampliar exige aprobación del usuario); `make:module` en `1.11b` |
| 059-068 | Ver `docs/adr/` (`060` ejecución de la suite; `061` PostgreSQL 18; `062`/`063` `REQ-ACAD`; `064` capa visual; `066` consentimiento de imagen; `067` muros de sesión; `068` autorizados y entrega de menores) |

---
## Trabajo en curso

**`1.14b-a` · CERRADO Y MEZCLADO (PR #497, `d30678d`, 2026-11-02)** · rama `feature/REQ-FAM-UNIT-1-14ba-autorizados-recogida`, HEAD verificado `e9c28b0`. **Verificado (suite completa, contenedor `plataforma-api`)**: 2145 Pest en verde (2093 Unit+Feature, 52 Concurrency), 7 omitidos; Pint y Larastan limpios. Revisiones en dos rondas, sin Críticos ni Altos. Decisiones del usuario: orden persona → autorización → foto (`OPEN-FAM-UNIT-48`), dependencia S3 (D-67), límite y auditoría de lectura en `/pickup-persons` (D-66), `no_vigente` para toda causa. Abiertas `OPEN-FAM-UNIT-49` (teléfono de otros tutores) y `-50` (modo plataforma sin fila `read`). **Pendiente del usuario**: añadir `FILESYSTEM_DISK=s3` y `AWS_*` a `apps/api/.env.example` (permiso denegado a los agentes). **No probado**: ciclo real contra S3/MinIO (la imagen no se descarga), reversión en servidor, planificador (#453). Entorno: se reconstruyó la imagen `api` (ya con `gd`/`exif`) y se borró el contenedor `plataforma-web` parado. Bajas a abrir como issue: `GenerateImageConsentsExport` usa el disco por defecto sin `PrivateObjectStore`; `plataforma-worker-long.env.example` sin `AWS_*`. **Siguiente paso**: `1.14b-b` (módulo `prl`; antes crear `docs/modulos/REQ-PRL/`) en sesión nueva. `1.14b-c` (pantallas) seguirá el orden de `OPEN-FAM-UNIT-48`.

**`1.14b` · ESPECIFICACIÓN APROBADA Y `ADR-068` ACEPTADA (2026-10-29/30), MEZCLADAS (PR #492, squash `1674363`)**. Cuatro entregas: `1.14b-a` (hecha), `1.14b-b` (módulo `prl`; antes crear `docs/modulos/REQ-PRL/`), `1.14b-c` (pantallas), `1.14b-d` (diferido: salida autónoma, alerta de no recogido, avisos). Lista maestra calculada en cada consulta (sin tabla de lista; excluye revocados con el predicado de `isAccessRevoked()`, resuelve #473). Siguen abiertas `OPEN-FAM-UNIT-31..33, 37..43, 46, 47` y `-45` (transporte). Condiciones previas a datos reales: `OPEN-08`, 14 y 18 años, `OPEN-066-02`, base legal del tercero (`OPEN-FAM-UNIT-36`). Issues relevantes: #486, #488, #490, #491, #493.

**`1.14b-b` · IMPLEMENTADA Y REVISADA (2026-11-02), MEZCLADA (PR #504)** · rama `feature/REQ-PRL-1-14bb-puerta-entrega-menores` (rebasada sobre `develop` `68bfeec`, HEAD `b97252f`). Módulo `prl`: `student_movements` (solo anexar, `REVOKE UPDATE/DELETE`) y `pickup_alerts`, 9 *endpoints*, permisos `entrega_menor.*` y `alerta_entrega.*`, docs trasladadas a `docs/modulos/REQ-PRL/`. **Verificado**: suite completa en el contenedor sobre `85790d2`: 2154 + 55 Pest en verde (6 + 1 omitidos), Pint y Larastan limpios, **E2E real contra la API (`npm run test:e2e:real:prl`) 1/1 en verde**; después, `b97252f` (disco local solo falla en cerrado en `production`/`staging`; compose usa local por defecto y S3 con el perfil `full`) solo con 338 tests afectados en verde; la suite completa la repite el CI. Revisiones: `db-reviewer` 0 C/A/M (4 Bajos), `security-reviewer` 2 Medios corregidos (auditoría y cupo en alertas y movimientos, test de `unidad_familiar`), `doc-reviewer` 3 Medios corregidos. **Primera revisión real con Codex (`ADR-069`)**: 1 Medio (carrera en `PickupAlertService::raise()`), tratado como parte de `OPEN-FAM-UNIT-51` y diferido a `1.14b-d`; sin falsos positivos aparentes. Decisiones del usuario: DP-1 aprobada, DP-9 TOCTOU aceptado y diferido, auditoría y cupo en alertas y movimientos, S3 solo obligatorio en producción. Abiertas `OPEN-FAM-UNIT-51..53` (con recomendación), `-40` (protocolo mínimo fijo, `prl.protocol.minimal` solo en es-ES), base legal del tercero. Issue #501 (fallo de Backoffice corregido en el paso). **No probado**: S3/MinIO real, reversión en servidor, planificador (#453). **Siguiente paso**: `1.14b-c1`. Ver `docs/metricas-agentes.md`.

**`1.14b-c` · ESPECIFICACIÓN DE UI APROBADA (2026-11-02), `1.14b-c1` IMPLEMENTADA, REVISADA Y MEZCLADA (2026-11-03, PR #513, `11ebe6f`)** · §23 de `REQ-FAM-UNIT/funcional.md` (escrita por `spec-writer`/Opus, ~198k tokens, 37 llamadas), todas las recomendaciones aceptadas por el usuario, salvo `-59`, que decidió en **B** (claves [NT] en es-ES en los cuatro ficheros; los textos en/de/fr los incorpora el revisor humano en un commit aparte, y `CA-FAM-UNIT-143` se cierra con él); **clasificación: crítico**. **Nota viva `1.14b-c1`**: rama `feature/REQ-FAM-UNIT-1-14bc1-pantallas-autorizados`, IMPLEMENTADA Y REVISADA, verificada hasta `50f272d` (T1 `df565f0`, T2 `d548e9e` + `d7346a2`, T3 `98701f7`, fix `0902515`, correcciones `76fe7c3` y `50f272d`): 146/146 Vitest (`src/modules/fam-unit` y `scripts`), `lint`, `lint:i18n`, `vue-tsc` y **E2E real `CA-FAM-UNIT-142` 1/1** (S3 falso `e2e-fake-s3.php`; **S3/MinIO real no probado**). Revisiones: Codex en tres rondas (ronda 1: 1 Alto de búsqueda obsoleta y 2 Medios; ronda 2: 1 Alto, carrera introducida por la corrección; ronda 3: approve) y `security-reviewer` (0 C/A; Bajos en #510, #511, #512; nombre dividido #509). Decisiones del usuario (2026-11-03): excepción nominal de `URL.createObjectURL` en `PickupPhotoUpload.vue`, `409 pickup.withdrawn` cierra el diálogo, nombre dividido a #509, `Card`/`Alert` a mano (regenerar con `shadcn-vue add` con red). `famUnit.pickup.list.empty` dejada en es-ES en en/de/fr. Quedan: suite web completa de cierre, `doc-precheck`, PR y CI, mezcla; después `1.14b-c2`. Partición `1.14b-c1` (`fam-unit`: T1 lectura, T2 escritura, T3 E2E real `CA-FAM-UNIT-142`) y `1.14b-c2` (`prl`: T1 módulo y lectura, T2 salida/entrada/alerta, T3 E2E real `CA-PRL-012/-013`), tramos secuenciales; sin `db-reviewer`; Codex primero (`OPEN-069-01` confirmada por el usuario el 2026-11-02). Sin `@axe-core/playwright`. Pendiente del usuario: revisión humana e incorporación de los textos [NT] (`OPEN-FAM-UNIT-59` B) y texto del protocolo (`-40`). Issue #507 (desfases de versión en documentos raíz, Bajo). Rama de proceso `chore/skills-revision-y-aprobacion-1-14b-c` (aprobación, `CLAUDE.md` 2.10.2, revisión de skills).

**Pendiente del usuario (`1.14-c`/`1.14-d`)**: (1) ¿se concede `revocacion_tutor.crear` a secretaría o se corrige `CA-FAM-UNIT-084`?; (2) estados de consentimiento en en/de/fr; (3) traducir `legalHint`, `confirmRevoke.description` y `termsWall.accept` (#488; este último en es-ES hasta validación jurídica); (4) alta de alumno por `public_id` de persona, provisional hasta `1.15`. Sin probar: E2E real de `1.14-d` (falta alumno de prueba y contratar `fam_unit` en `e2e-real-tenant.php`, D-51), informe de `1.14-b` contra S3 y worker reales, `STATE_SQL` con miles de alumnos (medir en `1.15b`). Bajas de `1.14-b` abiertas: B-2, B-4, L1, L2, L3, L5.

**Pendientes de pasos anteriores**: #471 (para `1.15`), #475 (AR-16, requiere aprobación del usuario), #470 (limitador global); `TeachingMembership` y `GradingScaleDirectory` «escritas, no construidas» (`OPEN-ACAD-43`, `ADR-048`); `SECURITY.md`/`PRIVACY.md` desfasados (#446); Medio de #438; marcar `make-module` como *required check*. **Decisión del usuario** (`ADR-061`): borrar el volumen `plataforma-educativa_postgres-data` (PG17) y `~/plataforma-respaldos/pg17-a-pg18-20261010-1655/`. `refsByIds()` confirmada: actualizar la nota «pendiente» de `REQ-CORE/funcional.md` y `REQ-ACAD funcional.md §19.1 I-1` en la próxima entrega que los toque.

**Issues abiertos por decisión**: #378 (resto de B3: rutas `platform/v1` y rutas fuera de `App\Modules`), #371 (purga física de tenant: `1.23b`, obligatorio antes de datos reales), #366 (sin parche), `OPEN-059-05`/`-06`, #428, #453, #454, #455, #457, #461 (de `1.12b`), #434, #431 (decisiones de `1.15a`), #430, #418 y #419 (índices MFA; medir con datos reales), #421, #422.

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

Texto completo de cada issue en GitHub; descripción original en `docs/proceso/memoria-archivo.md`. (#128 resuelto en PR #456: retirado de la lista.)

- **Media**: #6 `runAsPlatform()` sin autorización ni auditoría (puntos 2-3 en 1.6); #27 `Tenant` sin auditoría hasta `admin_action_logs` (`ADR-036`); #45 sin antivirus de subidas (cand. 1.27); #60 `ValidationErrorFormatter` antepone «core.» (requiere `architect`); #69 `CA-AUTH-060`-`063` sin test; #81 RLS en `sessions` sin paso asignado; #89 `plataforma.env.example` sin `AUTH_*`/`SESSION_*`; #141 `302` del *callback* de Google aterriza en el puerto de la API en dev (dos propuestas sin decidir); #142 `RecordsAuditTrail` no excluye `last_login_at`/`last_used_at` (sesión propia); #261 punto de extensión de bloques del panel no construido (`ADR-053 §5.2.4`); #305 `GET /me` 403 podría encadenar recargas (sin verificar).
- **Baja**: P-02 usar `npm create vite`, no `create-vue`; #7 caché de tenant no se invalida al suspender; #8 cookie «host-only» no reforzada; #37 Redis sin `requirepass`; #38 `minio-data.volume` huérfano (0.10d); #40 sin escaneo de imagen del SO; #61 `UnlockReason::Correo` reutilizado; #65 manuales no-`admin` sin pantallas de 1.2 (cand. 1.8); #86 `HomeView.vue` llama a `/api/v1/health` (404); #97 YAML inválido en `openapi/components.yaml` (~l.369); #106 Pest agota memoria: `php -d memory_limit=512M ./vendor/bin/pest`; #116, #117, #120 (1.3b: tabla previa al rol, *rate limit*, sin test); #145 `people.locale` sin `CHECK`; #146 `artisan serve` interbloquea OIDC en dev; #170 `ReplaceRolePermissions` más estricto que `REQ-PERM/api.md §5.4` (**decidir antes de la matriz de `1.5b`**); #254, #255 (cabeceras de versión sin mecanismo automático), #258 (`OPEN-CORE-15`), #277, #278, #281, #289, #291 (15 tests SAML fallan en el PHP del host), #294-#298, #304, #312, #318, #319, #330, #333, #334.
- **Planificación (ideas/pendientes de spec)**: #78 AMPA generalizada a asociaciones por tenant (alcance cerrado 2026-08-25; `spec-writer`/`architect`); #82 apoderado/firmante en `REQ-DOC-002`; #79 cuota de tenant por almacenamiento/miembros; #80 integración opcional con Google Drive.

---

## Siguiente paso concreto

1. **Mezclar `1.14b-c1`** (PR y CI de la rama `feature/REQ-FAM-UNIT-1-14bc1-pantallas-autorizados`, tras `doc-reviewer`) y después **`1.14b-c2`** (conserjería de `prl`; tramos T1-T3 de §23.10 de `REQ-FAM-UNIT/funcional.md`, E2E real `CA-PRL-012/-013`; Codex primero). Antes, sesión nueva (CLAUDE.md §3). `CA-FAM-UNIT-143` se cierra con la revisión humana de los textos [NT].
1b. **Renovate: 10 PR abiertos (#496, #495, #466, #465, #464, #463, #445, #444, #411, #410) se tratan cuando el punto `1.14` esté completo** (decisión del usuario, 2026-11-02), uno a uno: Node 26 (#495, #496), Redis 8 (#465, #411) y las acciones de Docker (#444, #445, #463, #464) son saltos de versión mayor.
1c. **Seguimiento de seguridad `php2shell`/`node2shell` (2026-11-02)**: Node del host actualizado a 24.22.2 (CVE-2026-111497, corregido en 24.22.1); imagen web reconstruida con Node 24.22.2; API ya en PHP 8.4.27 (corregido en 8.4.27, CVE-2026-109618/-109619). Pendiente: reiniciar Claude Code y `pm2 update`, retirar `nvm uninstall 24.19.0` tras comprobar, hacer `pull` de la base en el CI para producción, y **contrastar las versiones corregidas con php.net y nodejs.org** (las saqué de blogs de seguridad).
2. Orden del plan después: `1.15` (alumnado y matrícula), `1.13` (asistencia, tras `1.15`), `1.15a` (capa visual, `ADR-064`) antes de `1.15b`.
3. `1.23b` (purga física de tenant, #371, [OPUS + SONNET]): obligatorio antes de datos reales y del importador `1.24`.
4. Pendiente con permiso del usuario: decidir el Medio de #438 y si se exige el ámbito `todos` del rollover (#461).

**Prueba de Codex cerrada: `ADR-050` (2026-09-21)**, detalle en `docs/historial/adr-050-cierre-prueba-codex.md`. Sin resolver, no bloqueantes: (1) política de datos de la capa gratuita de ChatGPT, decisión del usuario; (2) `SECURITY.md`/`PRIVACY.md` sin reflejar la salida permanente de código hacia Codex (`doc-reviewer`, próximo cierre de fase).

**Lecciones de proceso vigentes**: (1) un hallazgo de «falta cobertura» puede esconder bugs reales: no se cierra hasta que el test nuevo pase. (2) Ningún subagente actúa fuera de su encargo (ni «arreglar» ni revertir): para y reporta (#150). (3) «Pasa en local» no es «pasa en CI»: `composer analyse`/`pint --test`/`npm run lint` sobre el estado final completo. (4) Dos *worktrees* de agente no ejecutan la suite completa a la vez contra la misma base de test (deadlock, #226). (5) Relanzar con `Agent` en vez de `SendMessage` crea un subagente nuevo, no continúa. (6) Un fallo en un módulo que el diff no toca puede ser el entorno (procesos que no comparten sistema de ficheros en un *worktree*). (7) No cerrar un issue por su título: comprobar su sección «Pendiente» (#62). (8) No lanzar revisores que ejecuten Pest mientras corre la suite completa (comparten base, dan 404 intermitentes); no lanzar dos `composer test` a la vez. (9) Las migraciones se aplican con `php artisan migrate --database=pgsql_owner`. (10) Un *check* registrado en el arranque no debe inyectar por constructor un servicio que arrastre `PermissionResolver` (`CA-PERM-030`).

**Notas de entorno vigentes**: SPA en navegador real desde `demo.plataforma.test:5173` (#71). Contenedor `api`: `podman exec -w /var/www/html plataforma-api <cmd>`; `web`: `podman exec -w /app plataforma-web <cmd>`. Antes de la suite, `podman start plataforma-api` (simulador SSO; los 74 rojos de Auth eran eso). Suite Pest: `php -d memory_limit=512M` (#106). `gh pr checks <n>` para el bucle de espera de CI. `.env` no es editable desde esta sesión. Un *worktree* de agente tiene su propio `storage/`, distinto del de cualquier contenedor en marcha: los mecanismos de test que cruzan procesos por sistema de ficheros compartido pueden desincronizarse (`1.6d`). El clasificador de permisos puede bloquear `gh pr merge` y la edición de `settings.json` hasta orden expresa del usuario.
