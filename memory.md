# memory.md — Estado del proyecto

> Fichero de estado entre sesiones. Claude lo lee al arrancar y lo actualiza tras cada hito.
> Solo estado **vigente**. Lo cerrado y mezclado se resume en 1-3 líneas; el detalle original, íntegro, está en `docs/proceso/memoria-archivo.md` (y `docs/historial/`). Si supera ~150 líneas o ~12 KB, archiva lo antiguo en `docs/proceso/`.

---

## Estado actual

**Fase 0** cerrada en la práctica (lo pendiente de `0.10`-`0.12` es negocio, no código; ver «Bloqueantes»). **Fase 1: bloques A, B y C completos y mezclados; bloque D en curso** (`1.14` completa; `1.15` completa (`1.15-a` a `-d` mezcladas); `1.14b` en curso). Siguiente: ver «Trabajo en curso».

**Pasos cerrados y mezclados**: de `1.1` a `1.15-d`, `S-1a` y `S-1b` (PR #56 a #629); lista con PR en `docs/proceso/memoria-archivo.md` («Archivado el 2026-11-13»). Bloques A, B y C completos; bloque D con `1.14` y `1.15` completas y `1.14b` en curso (`-d2`, `-d3`, `-d4` esperan a `1.15`, `1.19a-1` y `1.13`/`1.19a`/#453).

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
| 001-047 | Decisiones condensadas de `001` a `047` (multi-tenant, stack, red, auditoría, auth, `REQ-PERM`, `REQ-BO`): íntegras en `docs/proceso/memoria-archivo.md` («Archivado el 2026-11-13») y, canónicas, en `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md §18` |
| 049, 050 | Codex: segunda opinión solo lectura; `050` sustituye `049 §8`/`§9` y lo mantiene como verificación permanente y opcional |
| 056 | Estandarización de módulos: reglas `AR-01`…`AR-12` con excepciones que solo pueden reducirse (ampliar exige aprobación del usuario); `make:module` en `1.11b` |
| 059-068 | Ver `docs/adr/` (`060` ejecución de la suite; `061` PostgreSQL 18; `062`/`063` `REQ-ACAD`; `064` capa visual; `066` consentimiento de imagen; `067` muros de sesión; `068` autorizados y entrega de menores) |
| 071, 076 | `071` grupo opcional en la matrícula (antiguo alumno derivado); `076` almacenamiento de objetos: API S3 como contrato único, Garage fijado por versión en desarrollo, CI y E2E (nunca en servidores), `TenantObjectStore` con el prefijo del centro impuesto por la implementación |

---
## Trabajo en curso

**CIERRE 2026-11-13 · ola 1 de arquitectura TERMINADA y mezclada** (PR #636-#651 y #653; plan 2.3.23, REQUISITOS 3.2.31, `CLAUDE.md` 2.12.0): ADR-077 a 083 en `PROPUESTA` y especificaciones `S-2`, `1.13`, `1.15a`, `1.15b`, `1.16-1.17`, `1.18` y `1.19` en `PROPUESTO`, más `docs/cumplimiento/`; métricas en `docs/metricas-agentes.md`. Decisiones del usuario del 2026-11-12/13 anotadas en cada documento. **Siguen sin decidir** las `OPEN-*` bloqueantes de cada documento (p. ej. `OPEN-UI-03/-04/-06/-12`, `OPEN-ACAD-47`, `OPEN-STG-04/-05/-09`, `OPEN-PAY-03/-07`, `OPEN-COM-*`, `OPEN-INF-*`, `OPEN-CALIF-*`). **`ADR-084` (asistencia sin conexión, bloquea `1.13-c`) y `ADR-085` (salud 0-3, `1.18-h`) están reservados y sin redactar.** Pendiente de aprobación expresa del usuario: `CLAUDE.md §11` (documentos de identidad) junto con `REQ-SEED-005`, `REQ-CORE-005` (`audit_logs`) y el cuerpo de REQUISITOS; `1.11d` sigue `[~]` en el plan aunque `-a` y `-b` están mezclados. Fable 5.2 se quedó sin créditos: B6, B7 y los correctores se hicieron con Opus. #654 (purga de imágenes) **resuelto** en #655 y comprobado. **Lecciones**: (1) el hook `block-protected-branch.sh` lee la rama del directorio de partida: usar `git -C <worktree>` y no `cd`; (2) **nunca borrar la rama remota de un PR si su mezcla falló**: cierra el PR (pasó con #572-#575); (3) `git worktree add` es `<ruta> <rama-base>` y un subagente creado con el directorio principal en otro worktree arranca de esa rama (pasó con B6); (4) un worktree sin cambios al cortarse la cuota se limpia solo: recrearlo antes de reanudar; (5) `architect` solo escribe en `docs/adr/`, `docs/cumplimiento/` y §18 de REQUISITOS. **Del usuario**: `podman volume rm plataforma-educativa_minio-data` y marcar el job `Conformidad del almacén (Garage)` como *check* requerido (`S-1b`); landing bloqueada por DNSSEC de Arsys (**superado el 2026-11-14**, ver el párrafo «Landing» más abajo). **Hallazgos sin issue**: `RequireIdempotencyKey` no filtra por `expires_at` y cuatro trabajos de exportación aceptan un `tenantPublicId` vacío (`S-2` lo elimina); `REQ-ACAD/funcional.md §19.1` aún dice que `classPairsFor` no tiene consumidor previo (actualizar al implementar `1.13-a1`).

**Landing `adagiox.com` (`ADR-072` a `ADR-075` y `ADR-086`, issue #579)** · estado 2026-11-14: T1-T4 mezclados; despliegue **apagado** (`LANDING_DEPLOY_ENABLED` sin crear). **Hecho**: las cuatro zonas (`.com`, `.es`, `.eu`, `.org`; el cuarto es `.org`, no `.net`) están en Cloudflare con `johnny`/`keyla` (`.com` y `.eu` `active`; `.es` y `.org` esperaban a Arsys); redirecciones 301 de `www` y de los secundarios por reglas de zona (comprobadas en `www.adagiox.com` y `.eu`); `wrangler.jsonc` con `custom_domain` del ápice (#660); DNSSEC activo en Cloudflare para `.com` en `pending`; marca cerrada (sin «AdagioX» en TMview ni OEPM); copy validado; buzón `founders@adagiox.com` funciona; bloqueos de LaLiga aceptados, se sigue con Cloudflare (`ADR-086`); secretos del *environment* `landing-production` creados; token Workers Admin con Workers Routes Write en `adagiox.com`. **Falta**: (1) **DS en Arsys** (soporte; key tag 2371, alg. 13, digest SHA-256 en `GET /zones/<id>/dnssec`), no bloquea; (2) confirmar que `.es` y `.org` pasaron a `active` y probar sus redirecciones; (3) MX nulo, SPF `-all` y DMARC `p=reject` en los secundarios y DMARC de `.com`; (4) crear `LANDING_DEPLOY_ENABLED = true`, hacer la *release* `develop` → `main` (con `require-ci-green`), comprobar cabeceras en `https://adagiox.com`, **probar `wrangler rollback`** y bajar el token a Editor de producto; (5) la reversión real contra Cloudflare sigue **no probada**. `sign-in` queda desfasada al implementar `1.15a` (`ADR-064`): regenerarla entonces.

**Renovate (cierre 2026-11-13)**: mezclados con CI en verde #577 y #571 (Redis 8) y #570 (Node 26); el CI de `develop` pasó entero tras las tres. **#572-#575 (acciones de Docker) NO están mezclados**: `gh pr merge` falla sobre `.github/workflows/` porque el token no tiene el permiso `workflow` (los mezcla el usuario desde la web o se amplía el permiso), y mi script borró sus ramas tras el fallo, lo que **cerró** los PR; Renovate los recreó como **#656 (build-push v7), #657 (login v4) y #658 (metadata v6)**; falta el de `setup-buildx` (#575), que Renovate recrea desde el panel #12. Abiertos además: **#576** (Node 26 imagen, sin checks) y **#466** (majors de web, 6 fallos sin triar). Dependabot: 3 alertas. Los fallos de #572-#575 eran de la ventana de DNS del 2026-11-11 (con rebase pasaron 15/15).

**Bajo pendiente**: `README.md`: la tabla de versiones se alineó el 2026-11-13 (REQUISITOS 3.2.31, plan 2.3.23); sin revisar el recuento «69 ADR en el índice» de su mapa de documentos (hacerlo en el próximo cierre de fase, `CLAUDE.md §6.7`).

**Reglas `allow` de Codex (#555)**: corregidas en `.claude/settings.json` (sintaxis ` *` y versión del plugin fijada a `1.0.6`; un comodín en la ruta aprobaría opciones intercaladas sin preguntar). **Al actualizar el plugin de Codex, cambiar `1.0.6` en las cuatro reglas.** Sin avisos de permisos en `claude -p`. **No probado**: una revisión real de Codex con las reglas nuevas (cuota de OpenAI agotada hasta 2026-12-02).

**Pendientes antiguos (de `1.14-c`/`1.14-d` y de pasos anteriores)**: íntegros en `docs/proceso/memoria-archivo.md` («Archivado el 2026-11-13 (cierre)»); siguen vigentes hasta que se resuelvan: consentimiento de imagen en en/de/fr, `CA-FAM-UNIT-084`, #471, #475, #470, `SECURITY.md`/`PRIVACY.md` desfasados (#446), borrar el volumen PG17.

**Issues abiertos por decisión**: #378 (resto de B3: rutas `platform/v1` y rutas fuera de `App\Modules`), #371 (purga física de tenant: `1.23b`, obligatorio antes de datos reales), #366 (sin parche), `OPEN-059-05`/`-06`, #428, #453, #454, #455, #457, #461 (de `1.12b`), #434, #431 (decisiones de `1.15a`), #430, #418 y #419 (índices MFA; medir con datos reales), #421, #422.

---
## Bloqueantes

| ID | Descripción | Impacto |
|----|-------------|---------|
| H0 | Sin centro piloto comprometido ni ficheros de exportación de GQdalya | Criterio de salida de fase 1 y `REQ-ONB-003` |
| OPEN-11 | **Dónde se aloja el piloto**. WSL2 no puede alojar datos reales | Hito H0 |
| OPEN-07 | Entidad jurídica y contrato de encargado de tratamiento | Datos reales y facturación |
| OPEN-08 | Dominio y DNS con API para certificado comodín (la usan también `ADR-079` y `ADR-080`) | Multi-tenant, fase 0 |
| OPEN-09 | Proveedor de correo transaccional (**reformulada por `ADR-079`: `OPEN-079-01` y `-02`**) | `REQ-AUTH`, `REQ-COM` |
| OPEN-10 | Almacenamiento de copias en proveedor distinto del host (relacionada con `ADR-083 §7`) | `REQ-BKP` |

---

## Problemas abiertos

Texto completo de cada issue en GitHub; descripción original en `docs/proceso/memoria-archivo.md`. (#128 resuelto en PR #456: retirado de la lista.)

- **Media**: #6 `runAsPlatform()` sin autorización ni auditoría (puntos 2-3 en 1.6); #27 `Tenant` sin auditoría hasta `admin_action_logs` (`ADR-036`); #45 sin antivirus de subidas (cand. 1.27); #60 `ValidationErrorFormatter` antepone «core.» (requiere `architect`); #69 `CA-AUTH-060`-`063` sin test; #81 RLS en `sessions` sin paso asignado; #89 `plataforma.env.example` sin `AUTH_*`/`SESSION_*`; #141 `302` del *callback* de Google aterriza en el puerto de la API en dev (dos propuestas sin decidir); #142 `RecordsAuditTrail` no excluye `last_login_at`/`last_used_at` (sesión propia); #261 punto de extensión de bloques del panel no construido (`ADR-053 §5.2.4`); #305 `GET /me` 403 podría encadenar recargas (sin verificar).
- **Planificación (ideas/pendientes de spec)**: #78 AMPA generalizada a asociaciones por tenant (alcance cerrado 2026-08-25; `spec-writer`/`architect`); #82 apoderado/firmante en `REQ-DOC-002`; #79 cuota de tenant por almacenamiento/miembros; #80 integración opcional con Google Drive.

---

## Siguiente paso concreto

0. **Siguiente (2026-11-13)**: elegir el paso con el usuario. Candidatos: `1.15a-a` (capa visual, solo `apps/web`, decidida) y después `1.15a-b` y `1.15b`; o contestar las `OPEN-*` bloqueantes de `1.13`, `1.16-1.19`. Antes: mezclar #656-#658 desde la web (necesitan el permiso `workflow`) y revisar el panel de Renovate #12.


**Prueba de Codex cerrada: `ADR-050` (2026-09-21)**, detalle en `docs/historial/adr-050-cierre-prueba-codex.md`. Sin resolver, no bloqueantes: (1) política de datos de la capa gratuita de ChatGPT, decisión del usuario; (2) `SECURITY.md`/`PRIVACY.md` sin reflejar la salida permanente de código hacia Codex (`doc-reviewer`, próximo cierre de fase).

**Lecciones de proceso vigentes**: (1) un hallazgo de «falta cobertura» puede esconder bugs reales: no se cierra hasta que el test nuevo pase. (2) Ningún subagente actúa fuera de su encargo (ni «arreglar» ni revertir): para y reporta (#150). (3) «Pasa en local» no es «pasa en CI»: `composer analyse`/`pint --test`/`npm run lint` sobre el estado final completo. (4) Dos *worktrees* de agente no ejecutan la suite completa a la vez contra la misma base de test (deadlock, #226). (5) Relanzar con `Agent` en vez de `SendMessage` crea un subagente nuevo, no continúa. (6) Un fallo en un módulo que el diff no toca puede ser el entorno (procesos que no comparten sistema de ficheros en un *worktree*). (7) No cerrar un issue por su título: comprobar su sección «Pendiente» (#62). (8) No lanzar revisores que ejecuten Pest mientras corre la suite completa (comparten base, dan 404 intermitentes); no lanzar dos `composer test` a la vez. (9) Las migraciones se aplican con `php artisan migrate --database=pgsql_owner`. (10) Un *check* registrado en el arranque no debe inyectar por constructor un servicio que arrastre `PermissionResolver` (`CA-PERM-030`).

**Notas de entorno vigentes**: SPA en navegador real desde `demo.plataforma.test:5173` (#71). Contenedor `api`: `podman exec -w /var/www/html plataforma-api <cmd>`; `web`: `podman exec -w /app plataforma-web <cmd>`. Antes de la suite, `podman start plataforma-api` (simulador SSO; los 74 rojos de Auth eran eso). Suite Pest: `php -d memory_limit=512M` (#106). `gh pr checks <n>` para el bucle de espera de CI. `.env` no es editable desde esta sesión. Un *worktree* de agente tiene su propio `storage/`, distinto del de cualquier contenedor en marcha: los mecanismos de test que cruzan procesos por sistema de ficheros compartido pueden desincronizarse (`1.6d`). El clasificador de permisos puede bloquear `gh pr merge` y la edición de `settings.json` hasta orden expresa del usuario.
