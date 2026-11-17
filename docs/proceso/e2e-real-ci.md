# E2E real en el CI y ampliación de su cobertura (#737)

| Campo | Valor |
|-------|-------|
| Ubicación | `docs/proceso/e2e-real-ci.md` (`OPEN-E2ECI-01`, opción A). **No es un módulo ni un requisito nuevo**: sin tablas, *endpoints*, permisos ni migraciones (por eso no hay `datos`, `api` ni `permisos`) |
| Origen | Issue [#737](https://github.com/pirexia/plataforma-educativa/issues/737) (Media, 2026-11-17) |
| Requisitos que cubre | `INV-015`, `RNF-MANT-004` (CI con build, test, lint, análisis estático y escaneo), `RNF-MANT-006` (aislamiento entre tenants en cada pipeline: el E2E real lo refuerza, no lo sustituye), `CLAUDE.md §3` (E2E contra la API real; los E2E deben existir y demostrar que detectan el fallo) y `§10` |
| Prioridad | La del issue: Media |
| Fase | Fase 0/transversal: proceso y CI. No es un *bounded context*: sin tablas, sin *endpoints*, sin permisos, sin pantallas |
| Depende de | `ADR-089` (patrón de agregador), `ADR-076` (Garage en CI y E2E), `ADR-080 §5` (`0.10e-b`, `CONTAINER_RUNTIME`; **no implementado**: ver `OPEN-E2ECI-03`), `ADR-030` (solo datos sintéticos) |
| Esencial | No aplica (no es módulo) |
| Estado | **APROBADA** el 2026-11-17: el usuario resolvió las 15 `OPEN-E2ECI` en la opción recomendada (A). **Nada implementado** |
| Clasificación del paso: crítico | Por entrega en `§9`: `E2E-CI-b`, `-c` y `-d` **críticas**; `E2E-CI-e` ordinaria. La sesión lo comprueba contra el diff |

> Convención: **V** = comprobado leyendo el fichero en el *worktree* (`403c78f`); **V-idx** = comprobado en el índice de búsqueda de código de GitHub (commit `fedb617`, dos commits de documentación por detrás de `403c78f`); **NV** = no verificado (esta sesión no tiene `Bash`: no pudo listar directorios, contar ficheros, ejecutar nada ni commitear). Lo que añade esta especificación sin estar en un requisito o ADR va marcado **[DERIVADA]** con su motivo.

---

## 0. Decisiones del usuario que esta especificación NO reabre

| # | Decisión (2026-11-17) |
|---|----------------------|
| D1 | Se lleva el E2E real al CI |
| D2 | Orden: (a) esta especificación; (b) job de CI solo con la suite `core` como piloto; (c) ampliar a todas las suites, `:prl` y `:alum` incluidas; (d) ampliar la cobertura de flujos (matriz flujo × rol) y pruebas visuales |
| D3 | Paralelizar con matriz por suites **solo** al cumplirse un disparador: suite real completa > ~10 min de reloj, o el job supera ese presupuesto, o la espera bloquea otro trabajo. Primer paso entonces: medir el tiempo por suite y proponer el reparto |
| D4 | No se crea un centro `demo` persistente: se espera a `1.15b` (`demo:seed`). El E2E usa el centro sintético que crea su propio guion; `E2E_ALLOW_DESTRUCTIVE=1` solo sobre él |
| D5 | Intermitentes: *issue*, nunca reintento silencioso (ya existe #741 para `acad-timetable`) |

---

## 1. Hechos de partida

### 1.1 El CI de hoy

- **V** `.github/workflows/ci-web.yml`, job `e2e` («Tests e2e (Playwright)»): `npm ci`, `npx playwright install --with-deps chromium`, `npm run test:e2e`. No levanta PostgreSQL, Redis, Garage ni la API. Los *specs* reales se saltan solos (`test.skip(process.env.E2E_REAL_API !== '1', …)`, visto en `e2e/core-scroll-real.spec.ts`).
- **V** `ci-web.yml` se dispara en `pull_request` a `develop`, `main` y `staging`, y en `push` a `develop` y `main`; sin filtro `paths:`. El comentario del job `lint` recuerda que los nombres de job son *required checks* de la protección de rama, que se configuran **a mano** en GitHub (`SYSADMIN.md §4`).
- **V** `apps/web/playwright.config.ts`: `retries: process.env.CI ? 2 : 0`, `fullyParallel: true`, `webServer` = `npm run dev -- --host 127.0.0.1 --port 5173`, `reuseExistingServer: !process.env.CI`, `locale: 'es-ES'`. **Con `CI=true` (siempre en GitHub Actions) cada test fallido se reintenta dos veces y, si pasa en un reintento, Playwright lo da por bueno («flaky»)**: eso es un reintento silencioso en el sentido de D5. Afecta ya al job simulado de hoy (`OPEN-E2ECI-07`).
- **V** `ci-api.yml`: patrón de `ADR-089` (matriz `pest` + agregador `test` con `needs`, `if: always()` y fallo si el resultado no es `success`); Garage en CI arrancado con `docker run` e `infra/garage/init.sh` con `CONTAINER_CLI=docker` y secretos efímeros enmascarados (job `storage-conformance`).

### 1.2 Los guiones de E2E real

- **V** `apps/web/scripts/e2e-real-api.sh` (suites `core`, `acad-structure`, `acad-periods`, `acad-scales`, `acad-timetable`, `curso-rollover`, `fam-unit`; `E2E_ONLY=<suite>` con lista cerrada y salida 2 ante un valor no válido), `e2e-real-prl.sh` (dos centros, modo `door`, `prl-door-api` + `prl-door-real`), `e2e-real-alum.sh` (dos centros, modo `alum`, tres *specs* HTTP y tres de navegador, dos invocaciones por el límite de 10 accesos por minuto y centro), `e2e-vite-api-url.sh` (fija `VITE_API_URL=http://<slug>.plataforma.test:8000/api/v1`), `lib/e2e-garage.sh` (`ensure_garage`) y `lib/e2e-purge.sh` (`purge_tenant_rows`; los guiones de PRL y alumnado llevan su propia copia de la función: **V**, duplicación ya existente).
- **V** Acoplamiento a la pila de desarrollo: `podman exec` al contenedor `plataforma-api` (`tests/Support/e2e-real-tenant.php setup|teardown`) y a `plataforma-postgres` (purga con el superusuario y `session_replication_role = replica`); `podman compose --profile full up -d garage` y recreación de `api`/`worker` con `FILESYSTEM_DISK=s3`; guarda `APP_ENV` `local|testing` en `ensure_garage`. `ADR-080 §5.1` cita además `e2e/curso-rollover.spec.ts` como fichero que invoca `podman` (ejecuta la cola `long` dentro del contenedor de la API, según el comentario de `e2e-real-api.sh`).
- **V** `compose.yaml`: red externa `plataforma-net`, `api` construida desde `infra/containers/api` (`target: dev`) montando `./apps/api`, `env_file: apps/api/.env` y `.env` raíz con `POSTGRES_*`, `TENANCY_*` y `GARAGE_*` obligatorias; `CORS_ALLOWED_ORIGINS: http://demo.plataforma.test:5173`; `TENANCY_BASE_DOMAIN: plataforma.test`.
- **V** `e2e/fixtures/realSession.ts`: `E2E_API_URL` (por defecto `http://127.0.0.1:8000/api/v1`), `E2E_BASE_DOMAIN` (por defecto `plataforma.test`) y cabecera `Host` con `:8000` **escrito a mano**. `e2e/core-scroll-real.spec.ts` ya acepta `E2E_BASE_URL`.
- **V** `ci-api.yml`, comentario del paso «Arrancar servidor de desarrollo»: `php artisan serve` es de un solo hilo y el issue #146 describe un interbloqueo con peticiones **entrantes del navegador**. Es el motivo de no proponer `artisan serve` para el E2E de navegador (`OPEN-E2ECI-02`).

### 1.3 Inventario de *specs*

- **V-idx** `apps/web/e2e/` contiene 34 ficheros `.ts`: 29 *specs* y 5 *fixtures* (`alumReal`, `dataExport`, `realSession`, `tableFixture`, `landingShowcase`). El encargo habla de 28 *specs*; la diferencia (un fichero) **NV**. `e2e-real-api.spec.ts`, citado en un comentario de `e2e-real-api.sh`, está en `apps/web/scripts/` (**V**), no en `e2e/`.
- **V** *Specs* que ejecutan los guiones reales: `core-roles` (`CA-PERM-133`), `curso-academic-years` (`CA-CURSO-086`), `core-scroll-real` (`RNF-MANT-007`), `acad-structure` (`CA-ACAD-053`), `acad-periods` y `acad-scales` (`CA-ACAD-101`), `acad-timetable` (`CA-ACAD-135`, intermitente #741), `curso-rollover` (`CA-CURSO-147`), `fam-unit-pickup-real` (`CA-FAM-UNIT-142`), `prl-door-api` (`CA-PRL-002`, `CA-PRL-035`) y `prl-door-real` (`CA-PRL-012`), `alum-students-real`, `alum-enrollments-real`, `alum-photo-real`, `alum-screens-real`, `alum-group-change-real`, `data-table-real`. En `core-roles` y `curso-academic-years` el guion filtra con `-g`: conviven tests reales y simulados en el mismo fichero (**NV** cuántos de cada).

### 1.4 Lo que hay hoy en la SPA (para la matriz de `§6`)

**V** Siete módulos web con `shell.ts`: `auth`, `core`, `curso`, `acad`, `fam-unit`, `prl`, `alum`. Todas las pantallas con sesión son **de personal del centro**. **No existe ninguna pantalla de familia ni de estudiante**: el portal de familias es `1.22` (`REQ-FAM-PORTAL`, citado en `REQ-INF/funcional.md §1.3`). Lo único que alcanza cualquier usuario autenticado, incluida una familia, son el autoservicio de cuenta (`/cuenta/*`), el muro de MFA y el muro de condiciones de uso (`/condiciones-de-uso`). La API sí sirve a la familia (`INV-006`): el modo `alum` del guion crea un usuario con rol `tutor_legal` que usan los *specs* HTTP (**V** por las variables `E2E_*_TUTOR_*`; **NV** qué *endpoints* ejercita).

---

## 2. Alcance

### 2.1 Entra

1. Job de CI que ejecuta el E2E real contra una pila efímera en el *runner*, condicionado por el diff, integrado como *check* requerido mediante un agregador que no bloquea los PR que no lo disparan (`§3`, `§12`).
2. Piloto con la suite `core` (`E2E-CI-b`), con criterios medibles y la demostración de que el job detecta un fallo (`§4`).
3. Plan de medición antes de decidir paralelización (`§5`).
4. Ampliación a todas las suites (`E2E-CI-c`).
5. Matriz «flujo × rol × cobertura E2E hoy» como plantilla, el método para llenarla y la ampliación de cobertura que salga de ella (`§6`, `E2E-CI-d`).
6. Pruebas visuales: política y decisión abierta (`§7`, `E2E-CI-e`).
7. Relación con `CS-17` (`§8`).

### 2.2 No entra

| Fuera | Motivo / dónde |
|-------|----------------|
| E2E contra staging (`CS-17`) | Especificación propia (`ADR-080 §6.2`, nota viva de `0.10e-c`). Aquí solo se dice qué se comparte (`§8`) |
| Centro `demo` persistente, `demo:seed` | D4; `1.15b` |
| Paralelización por suites | Solo tras el disparador de D3 (`§5`) |
| Cambiar el job simulado `e2e` de hoy | Fuera del encargo; su `retries: 2` se señala como `OPEN-E2ECI-07` |
| Implementar el nivel 1 de *runtime* (`0.10e-b`) | Es otro paso del plan; esta especificación solo depende de él según `OPEN-E2ECI-03` |
| Pantallas de familia o de estudiante | No existen (`§1.4`); `1.22` |
| Herramienta nueva de comparación de píxeles | `RN-UI-07` la excluye; cualquier cambio, `OPEN-E2ECI-10` |

---

## 3. Diseño del job (resumen; el detalle operativo está en `§12`)

**Propuesta**, condicionada a `OPEN-E2ECI-02` a `-06`:

1. **Fichero**: flujo nuevo `.github/workflows/ci-e2e-real.yml` [DERIVADA: aislar un job largo y con servicios propios de `ci-web.yml`, cuyo *job* `e2e` simulado sigue igual] (`OPEN-E2ECI-04`).
2. **Tres jobs**, mismo patrón que `ADR-089`:
   - `cambios` («E2E real · detección de cambios»): sin servicios; calcula con `git diff --name-only` contra la base del PR (o contra el commit anterior en `push`) si el cambio toca alguna ruta de la lista de `OPEN-E2ECI-05`; salida `ejecutar=true|false`. **Sin acción de terceros** (`dorny/paths-filter` u otra sería dependencia nueva: `CLAUDE.md §1`).
   - `e2e-real` («E2E real · \<suite\>», en el piloto solo `core`): `needs: cambios`, `if: needs.cambios.outputs.ejecutar == 'true'`. Levanta la pila, prepara el centro sintético con el guion y ejecuta Playwright.
   - Agregador «**E2E real (API)**»: `needs: [cambios, e2e-real]`, `if: always()`. **Verde** si (`cambios` = `success` y `ejecutar` = `false` y `e2e-real` = `skipped`) o (`e2e-real` = `success`). **Rojo** en cualquier otro caso: `cambios` fallido o cancelado, `e2e-real` fallido, cancelado, o saltado con `ejecutar = true`. Es el único que se marca como requerido en GitHub (lo hace el usuario a mano, `SYSADMIN.md §4`).
3. **Pila en el *runner*** (`OPEN-E2ECI-02`, recomendada A): `compose.yaml` con el perfil `full` (PostgreSQL 18, Redis 8, `api` FrankenPHP con la imagen `dev` construida en el job, `worker`, Garage), red externa `plataforma-net` creada en el job, `.env` raíz y `apps/api/.env` generados en el job con secretos efímeros enmascarados (patrón de `storage-conformance`), migraciones y `platform:sync-registry` aplicados, `127.0.0.1 demo.plataforma.test demo2.plataforma.test` añadido a `/etc/hosts` (el *runner* admite `sudo`: **V**, lo usa `ci-api.yml`). Vite lo arranca Playwright (`webServer`) con `VITE_API_URL` del guion.
4. **Datos**: el centro sintético del propio guion (`e2e-real-tenant.php setup`), con contraseñas aleatorias por ejecución (`ADR-080 §3.6`). Nada de `REQ-SEED` persistente (D4). La base es efímera y muere con el *runner*; la guarda `E2E_ALLOW_DESTRUCTIVE=1` y la marca del centro **se mantienen igual** (no se relajan porque la base sea desechable) [DERIVADA: un guion que aprende a saltarse su guarda en CI acaba saltándosela en local].
5. **Sin reintentos**: el job real ejecuta Playwright con `--retries=0` (los guiones pasan `"$@"` a Playwright: **V**). Un test que falla deja el job en rojo; si se sospecha intermitencia, se abre *issue* (D5) y el test no se marca como `skip` sin decisión del usuario.
6. **Presupuesto de tiempo** (`OPEN-E2ECI-09`, recomendado): `timeout-minutes: 30` como techo duro; objetivo de reloj del job ≤ 10 min (el umbral de D3). Superarlo es un disparador de D3, no un fallo.
7. **Evidencias**: resumen de tiempos en `$GITHUB_STEP_SUMMARY` y, si falla, el informe y las trazas de Playwright como artefacto de corta retención (`OPEN-E2ECI-14`).

---

## 4. Piloto `core` (`E2E-CI-b`)

### 4.1 Qué se entrega

El flujo de `§3` con la suite `core` (`E2E_ONLY=core`: `core-roles` `CA-PERM-133`, `curso-academic-years` `CA-CURSO-086`, `core-scroll-real` `RNF-MANT-007`), la adaptación mínima de los guiones para correr en el *runner* (`OPEN-E2ECI-03`), la instrumentación de tiempos de `§5` y la demostración de detección de `§4.3`.

### 4.2 Criterios de aceptación

- **CA-E2ECI-01** · *Dado* un PR que modifica un fichero de `apps/web/src`, *cuando* corre el CI, *entonces* el job `e2e-real` se ejecuta, la suite `core` pasa con 3 tests reales ejecutados (no saltados) y el agregador «E2E real (API)» queda en verde. *Prueba*: ejecución del CI en el PR de la entrega; el informe copia el resumen literal (tests ejecutados, pasados, saltados).
- **CA-E2ECI-02** · *Dado* un PR que solo modifica ficheros de `docs/`, *cuando* corre el CI, *entonces* `cambios` termina en `success` con `ejecutar=false`, `e2e-real` queda `skipped` y el agregador queda en **verde**. *Prueba*: PR de prueba solo de documentación (o el propio PR de un paso documental), enlace a la ejecución en el informe.
- **CA-E2ECI-03** · *Dado* un PR que rompe a propósito el comportamiento que cubre `core-scroll-real` (caso de referencia de #729: p. ej. que una navegación a ruta nueva deje de terminar arriba), *cuando* corre el CI, *entonces* `e2e-real` falla en ese test y el agregador queda en **rojo**. *Prueba*: rama desechable `chore/e2e-ci-prueba-rotura` con la rotura, PR en borrador **que nunca se mezcla**, enlace a la ejecución roja en el informe; después se cierra el PR y se borra la rama. Es la prueba de `CLAUDE.md §3` («un E2E nuevo debe demostrar que detecta el fallo») aplicada al job.
- **CA-E2ECI-04** · *Dado* que `cambios` dice `ejecutar=true`, *cuando* `e2e-real` se cancela o se salta por otra causa, *entonces* el agregador queda en rojo. *Prueba*: cancelar a mano una ejecución de `e2e-real` en un PR de prueba; resultado literal en el informe.
- **CA-E2ECI-05** · *Dado* un test real que falla en el primer intento, *cuando* corre en el job, *entonces* no se reintenta y el job queda en rojo. *Prueba*: la misma ejecución de `CA-E2ECI-03`, cuyo informe de Playwright muestra 0 reintentos.
- **CA-E2ECI-06** · *Dado* el job terminado (verde o rojo), *entonces* `$GITHUB_STEP_SUMMARY` muestra la tabla de tiempos de `§5.2` con todas sus fases, y ningún secreto efímero aparece sin enmascarar en el registro. *Prueba*: revisión del resumen y del registro de la ejecución de `CA-E2ECI-01`.
- **CA-E2ECI-07** · *Dado* el guion de E2E real ejecutado **en local** tras los cambios de la entrega, *cuando* se lanza `npm run test:e2e:real` sin variables nuevas, *entonces* se comporta como antes (mismas suites, mismos resultados) [regresión de los guiones]. *Prueba*: ejecución local con la skill `espera-e2e`, resultado literal en el informe.
- **CA-E2ECI-08** · *Dado* el agregador en verde de forma estable en el PR de la entrega, *cuando* se cierra `E2E-CI-b`, *entonces* el usuario lo ha añadido como *required check* de `develop` (y de `staging`/`main` si así lo decide) y el informe lo dice con la fecha (`OPEN-E2ECI-08`).

### 4.3 Cómo se demuestra que el job detecta el fallo

Además de `CA-E2ECI-03` (rotura de comportamiento), la entrega deja constancia de que la **condición** funciona en los dos sentidos (`CA-E2ECI-02` y `-04`): un agregador que diera verde cuando el job no corrió por error sería el mismo agujero que tenía el CI antes de #737.

---

## 5. Medición previa a cualquier paralelización

### 5.1 Qué se mide

| Fase | Dónde | Por qué |
|------|-------|---------|
| Instalación (`npm ci`, navegador de Playwright, construcción o descarga de la imagen de la API, dependencias de Composer) | CI | Coste fijo por job: decide si una matriz compensa (cada *shard* lo repite, `ADR-089 §3`) |
| Pila lista (servicios sanos, migraciones, `platform:sync-registry`, Garage inicializado) | CI y local | Coste fijo por job |
| Por suite: `setup` del centro, ejecución de Playwright, `teardown` y purga | CI y local | Es lo que se repartiría |
| Esperas por `429` (límite de accesos) | CI y local | Puede dominar el tiempo y no se arregla con más *runners* del mismo IP de salida (**NV** si cada *runner* sale por IP distinta; la API ve `127.0.0.1` en todo caso, porque la pila está en el propio *runner*) |
| Reloj total del job | CI | Disparador de D3 |

### 5.2 Con qué salida

- Cada guion emite una línea por fase con formato fijo, p. ej. `E2E_TIEMPO suite=<suite> fase=<setup|playwright|teardown> segundos=<n>` [DERIVADA: una línea parseable sirve igual en local y en CI sin dependencia nueva].
- En CI, un paso final convierte esas líneas en una tabla Markdown en `$GITHUB_STEP_SUMMARY` y en un fichero `e2e-tiempos.json` subido como artefacto.
- Al cerrar `E2E-CI-c`, el informe del paso recoge al menos **3 ejecuciones** en CI de la suite completa (mediana y máximo por suite) [DERIVADA: una sola medida no distingue ruido de tendencia; tres es el mínimo razonable, a recalibrar].

### 5.3 Regla de decisión

Si la mediana del reloj del job completo supera ~10 min (D3), la sesión propone un reparto por suites con los datos de `§5.2` (agrupando suites para que cada job quede por debajo de la suite más larga más el coste fijo) y lo presenta al usuario. **No se paraleliza sin esa propuesta aprobada.**

---

## 6. Matriz «flujo × rol × cobertura E2E hoy»

### 6.1 Plantilla

| Módulo | Ruta (nombre) | Flujo | Permiso de la ruta | Roles que lo ejercen | E2E real hoy | E2E simulado hoy | Interacción cubierta | Hueco |
|--------|---------------|-------|--------------------|----------------------|--------------|------------------|----------------------|-------|

*Interacción cubierta*: códigos `M` menú y migas, `T` tabla con orden/filtro/paginación y estado en URL, `F` formulario con validación de servidor, `D` diálogo o confirmación, `S` *scroll* y foco, `I` idioma (es/en/de/fr), `C` tema claro/oscuro, `A` accesibilidad (teclado, axe), `R` responsive.

### 6.2 Método para llenarla (lo ejecuta un `explorer` al empezar `E2E-CI-d`, sin `implementer`)

1. Rutas y permisos: de cada `apps/web/src/modules/*/shell.ts` (`§6.3` ya lo hace con lo leído).
2. Roles: de `docs/modulos/REQ-*/permisos.md`, concesiones por defecto de los roles predefinidos (`§11.1` del documento de requisitos). **Nunca se deduce un rol por el nombre de la pantalla**: se comprueba el permiso. Los roles personalizados no entran en la matriz (cualquier rol puede tener cualquier permiso); entran los predefinidos y los que crea el guion (`setup` modos por defecto, `door` y `alum`: administrador, secretaría, conserjería, dirección, docente ×2, `tutor_legal`).
3. E2E real: *specs* con `test.skip(process.env.E2E_REAL_API !== '1')` que el guion ejecuta (con su filtro `-g`); E2E simulado: *specs* con `page.route` (u otro simulador) que corren en el job `e2e` de hoy.
4. Interacción cubierta: por búsqueda en cada *spec* (`toHaveURL(/sort=/)`, `getByRole('dialog')`, `setViewportSize`, `emulateMedia`, cambio de idioma, etc.), y lectura cuando la búsqueda no sea concluyente.
5. Hueco y prioridad: por riesgo (`OPEN-E2ECI-12`, recomendada): primero lo que expone datos de menores o decide permisos, después escrituras, después lecturas.
6. Lo que no se pueda comprobar se marca **NV**, nunca se rellena.

### 6.3 Primera pasada (lo comprobable sin `Bash`)

Columna «Roles»: **NV** en todas las filas (requiere el paso 2). «E2E simulado» e «Interacción»: **NV** salvo donde se dice. «Por nombre» = el nombre del fichero sugiere la relación; su contenido no se ha leído.

| Módulo | Ruta | Permiso (V) | E2E real hoy | E2E simulado hoy |
|--------|------|-------------|--------------|------------------|
| auth | `login`, recuperación, restablecimiento, desbloqueo, invitación (`/entrar`, `/recuperar`, `/restablecer/:token`, `/desbloquear/:token`, `/activar/:token`) | pública | Inicio de sesión como paso previo de todos los *specs* de navegador (**V** en `core-scroll-real`); ningún *spec* real del flujo en sí | Por nombre: `public-auth-shell.spec.ts` |
| auth | `/entrar/google`, `/entrar/sso` | pública | Ninguno | **NV** |
| auth | `/cuenta/contrasena`, `/cuenta/sesiones`, `/cuenta/seguridad` | `[]` (autoservicio) | Ninguno | Por nombre: `auth-sessions.spec.ts` |
| auth | `/cuenta/seguridad/obligatorio` (muro MFA) | `[]` | Ninguno | **NV** |
| auth | `/administracion/mfa` | `mfa.leer` y otros cinco | Ninguno | **NV** |
| auth | `/administracion/sso` (+ nuevo, edición) | `proveedor_identidad.leer` | Ninguno | **NV** |
| core | `/administracion/usuarios` (+ nuevo, ficha, edición) | `usuario.leer` / `.crear` / `.actualizar` | `core-scroll-real` (**V**: menú, orden de tabla y *scroll*; `S`, `M`, `T` parcial) | Por nombre: `core-users.spec.ts` |
| core | `/administracion/usuarios/:id/permisos` | `permiso_efectivo.leer` | **NV** (posible en `core-roles`) | **NV** |
| core | `/administracion/invitaciones` | `invitacion.leer` | Ninguno | **NV** |
| core | `/administracion/importaciones` (+ detalle) | `usuario.importar` | Ninguno | **NV** |
| core | `/administracion/roles` (+ nuevo, ficha, clonar, editar, permisos) | `rol.leer` / `.crear` / `.actualizar` | `core-roles` (`CA-PERM-133`); `core-scroll-real` navega a la lista | `core-roles.spec.ts` (parte simulada, **NV** cuánta) |
| core | `/administracion/auditoria` | `auditoria.leer` | Ninguno | Por nombre: `core-audit.spec.ts` |
| core | `/administracion/centro` (+ marca) | `configuracion.leer` | Ninguno | Por nombre: `core-settings.spec.ts` |
| core | `/administracion/modulos` | `modulo.leer` | Ninguno | **NV** |
| core | `/cuenta/perfil` | `[]` | Ninguno | **NV** |
| curso | `/administracion/cursos` (+ nuevo, ficha, edición) | `curso_academico.*` | `curso-academic-years` (`CA-CURSO-086`) | Parte simulada del mismo fichero (**NV**) |
| curso | asistente y detalle de apertura del curso siguiente | `apertura_curso_academico.crear` / `.leer` | `curso-rollover` (`CA-CURSO-147`) | **NV** |
| acad | `/academico/estructura` y fichas de etapa, curso, grupo, asignatura | `estructura_academica.leer` | `acad-structure` (`CA-ACAD-053`); `acad-periods` (`CA-ACAD-101`, ficha de etapa) | **NV** |
| acad | `/academico/escalas` (+ nueva, ficha, edición) | `configuracion_academica.*` | `acad-scales` (`CA-ACAD-101`) | **NV** |
| acad | `/academico/horarios`, `/academico/marcos/:id` | `horarios.leer` | `acad-timetable` (`CA-ACAD-135`, intermitente #741) | **NV** |
| acad | `/academico/espacios` | `espacios.leer` | **NV** (`acad-timetable` crea un aula: no se sabe si por la pantalla) | **NV** |
| acad | `/academico/reservas` | `reservas.leer` | Ninguno por nombre | **NV** |
| fam-unit | `/familias`, `/familias/:id` | `familia.leer` | `fam-unit-pickup-real` (`CA-FAM-UNIT-142`, sección «Personas autorizadas» de la ficha) | Por nombre: `fam-unit-staff.spec.ts` |
| fam-unit | `/familias/consentimientos` | `consentimiento_imagen.leer` | Ninguno por nombre | **NV** |
| fam-unit | `/configuracion/condiciones-de-uso` | `texto_legal.leer` | Ninguno por nombre | **NV** |
| fam-unit | `/condiciones-de-uso` (muro) | `[]` | Ninguno | Por nombre: `fam-unit-terms-wall.spec.ts` |
| prl | `/conserjeria`, `/conserjeria/alumnos/:id` | `entrega_menor.leer` | `prl-door-real` (`CA-PRL-012`) y `prl-door-api` (HTTP) | **NV** |
| prl | `/conserjeria/alertas` (+ detalle) | `alerta_entrega.leer` | **NV** (posible en `prl-door-*`) | **NV** |
| alum | `/alumnado`, `/alumnado/:id` | `alumno.leer` | `alum-screens-real`, `alum-students-real` (HTTP), `alum-photo-real` (HTTP, Garage) | **NV** |
| alum | `/alumnado/listas` | `matricula.leer` | **NV** (posible en `alum-screens-real` o `data-table-real`) | **NV** |
| alum | `/alumnado/cambios-de-grupo` | `matricula.leer` | `alum-group-change-real` | **NV** |
| — | Transversal: *shell*, radio, *design system*, tabla | — | `data-table-real` (tabla TanStack, `RNF-MANT-007`) | Por nombre: `shell.spec.ts`, `radius.spec.ts`, `design-system.spec.ts`, `data-table.spec.ts`; `landing-shots.spec.ts` es de la *landing* |

**Familia y estudiante**: sin filas de pantalla, porque no existen (`§1.4`). Para ellos la matriz solo puede tener filas de **API** (prueba HTTP real con cookie y CSRF), y la de estudiante ni eso mientras el guion no cree un usuario estudiante (**NV** si algún *endpoint* de hoy atiende a un estudiante) (`OPEN-E2ECI-13`).

---

## 7. Pruebas visuales

### 7.1 Política vigente que aplica

`docs/modulos/REQ-CORE/capa-visual.md` (**V-idx**):

- **`RN-UI-07`**: ante el cambio global de radio (`ADR-064 §7`), antes de mezclar, `verificador` genera **capturas antes y después** de una lista cerrada de pantallas (acceso, panel de inicio, listado de usuarios con menú abierto, diálogo de confirmación, formulario de ajustes, matriz de permisos, horarios) **en claro y oscuro, fuera de Git**, y **el usuario las revisa**. «No se añade ninguna herramienta de comparación de píxeles (dependencia nueva sin requisito).»
- **`CA-UI-024`** (procedimiento): existen esas capturas, fuera de Git, y el informe del paso recoge la revisión del usuario. Según `memory.md` (**V-idx**), quedó **pendiente por decisión del usuario** al cerrar `1.15a-a`.

Contraste a tener en cuenta: las capturas de la *landing* (`landing-shots`, `ADR-075`) **sí** se guardan en Git (`CA-UI-025`, «un único *commit* de imágenes»). Son dos políticas para dos fines distintos.

### 7.2 La decisión que falta (`OPEN-E2ECI-10`)

`RN-UI-07` y `CA-UI-024` se escribieron para **un** cambio (el radio), no como política general de regresión visual. #737 pide «capturas de referencia por pantalla y tema». Llevarlo al CI cambia la naturaleza: una referencia que se compara en cada PR. Las opciones están en `§10`; esta especificación **no** la toma. Lo que sí fija [DERIVADA]: cualquier opción usa solo datos sintéticos, viewport y locale fijos, y los dos temas; y ninguna opción que compare píxeles se adopta sin decidir antes dónde viven las referencias, porque `RN-UI-07` dice hoy «fuera de Git».

---

## 8. Relación con `CS-17` (E2E real contra staging, `ADR-080 §6.2`)

| Se comparte | No se comparte |
|-------------|----------------|
| Los *specs* de `apps/web/e2e/` y sus *fixtures*: el mismo test debe poder apuntar a CI, local o staging por variables (`E2E_BASE_URL`, `E2E_API_URL`, `E2E_BASE_DOMAIN` ya existen; `realSession.ts` fija `:8000` en la cabecera `Host`, lo que impide hoy un destino HTTPS en 443) | El entorno: CI usa una pila efímera con imagen `dev`, `APP_ENV` `local`/`testing` y HTTP; staging usa la imagen de producción, `APP_ENV=production`, HTTPS con certificado de pruebas y MFA obligatorio según rol |
| La forma de los resultados (`§5.2`) y la regla de no reintentar (D5) | La preparación del centro: en CI, el guion `e2e-real-tenant.php` dentro del contenedor `dev`; en staging, `tests/Support` **no viaja en la imagen de producción** y el usuario decidió un **contenedor de un solo uso** con la misma imagen (`ADR-080 §3.6` opción (a); nota viva de `0.10e-c`) |
| El inventario y la matriz de `§6` | El disparo: CI en cada PR que toque código; staging en cada promoción o ventana (`ADR-090` D1 y su anexo, `docs/adr/ADR-090-anexo-plan-parcheo-y-mantenimiento.md`, que prevé que `CS-17` sustituya al humo), lanzado a mano |
| | La guarda destructiva: en CI la base entera es desechable; en staging la base es compartida y persistente entre ventanas, así que el *teardown* solo puede tocar el centro marcado |

**Consecuencia para el diseño [DERIVADA]**: ninguna entrega de este documento debe escribir en los *specs* algo que solo funcione en el *runner* (p. ej. `127.0.0.1` o `:8000` nuevos). Hacer los *specs* agnósticos al destino es trabajo de `CS-17`, no de aquí; aquí solo se prohíbe empeorarlo.

---

## 9. Entregas

Estimación en tramos de `implementer` (`CLAUDE.md §2.7`, ≤3 tramos ≈ 250 llamadas por entrega), **sin medir**: no hay datos de un tramo de CI en `docs/metricas-agentes.md` que esta sesión haya leído. Riesgo propio de estas entregas: cada iteración contra el CI es *push* + espera, y eso consume reloj, no llamadas.

| Entrega | Contenido | Tramos | Clasificación | Revisión | ADR | Migraciones |
|---------|-----------|--------|---------------|----------|-----|-------------|
| `E2E-CI-a` | Esta especificación | — (`spec-writer`) | — | `doc-precheck`, `doc-reviewer` | — | No |
| `E2E-CI-b` | Piloto `core` (`§3`, `§4`), instrumentación de tiempos (`§5`), adaptación de guiones al *runner* | 2-3 | **Crítico** (duda): toca guiones con purga por superusuario y la guarda `E2E_ALLOW_DESTRUCTIVE`, genera secretos en CI y cambia un *check* requerido de la protección de rama | Codex; `security-reviewer` (su columna: secretos en CI, permisos del `GITHUB_TOKEN`, guardas destructivas); `doc-precheck` y `doc-reviewer` | Sí, antes de implementar (`OPEN-E2ECI-11`) | No |
| `E2E-CI-c` | Todas las suites (`core`, `acad-*`, `curso-rollover`, `fam-unit`, `:prl`, `:alum`) en el job; `curso-rollover.spec.ts` sin `podman` escrito a mano; 3 mediciones y, si salta D3, propuesta de reparto | 1-2 | **Crítico** (mismo motivo que `-b`) | Igual que `-b` | No (lo cubre el de `-b`) | No |
| `E2E-CI-c2` | Solo si D3 se dispara y el usuario aprueba el reparto: matriz por suites con su agregador | 1 | **Crítico** (cambia la topología de un *check* requerido) | Igual que `-b` | Enmienda del ADR de `-b` si cambia su decisión | No |
| `E2E-CI-d0` | Matriz completa (`§6.2`), con prioridad y propuesta de partición de `-d` | — (`explorer` + `spec-writer`) | — | `doc-precheck`, `doc-reviewer` | No | No |
| `E2E-CI-d1`..`-dn` | Nuevos E2E reales por grupo de flujos, cada uno ≤3 tramos; tamaño y número salen de `-d0` | ≤3 cada una | **Crítico** si el diff toca `apps/api/tests/Support/e2e-real-tenant.php` (crea usuarios, roles y datos de menores sintéticos; tiene guardas de entorno) o los guiones; **ordinario** si solo añade *specs* | Codex; `security-reviewer` si crítico; `doc-precheck`, `doc-reviewer` | No | No |
| `E2E-CI-e` | Pruebas visuales según lo que se decida en `OPEN-E2ECI-10` | 1-2 | Ordinario (salvo que la opción elegida cambie algo de seguridad, que no se prevé) | Codex; `doc-precheck`, `doc-reviewer` | Sí si se adopta comparación con referencias, porque cambia `RN-UI-07` (`OPEN-E2ECI-10`) | No |

**E2E / prueba que cubre cada criterio**: `CA-E2ECI-01` a `-08` en `§4.2` (cada uno lleva su prueba). Para `E2E-CI-c`: `CA-E2ECI-09` a `-11` (`§9.1`). Para `-d` y `-e`: los fija `-d0` y la decisión de `OPEN-E2ECI-10`.

### 9.1 Criterios de `E2E-CI-c`

- **CA-E2ECI-09** · *Dado* un PR que toca `apps/api`, *cuando* corre el CI, *entonces* el job ejecuta las siete suites de `e2e-real-api.sh`, la de `:prl` y la de `:alum`, y el resumen muestra por suite tests ejecutados/pasados/saltados, con 0 saltados por falta de `E2E_REAL_API`. *Prueba*: ejecución del CI del PR de la entrega.
- **CA-E2ECI-10** · *Dado* una rotura deliberada en una suite distinta de `core` (p. ej. el flujo de `alum-group-change-real`), *cuando* corre el CI, *entonces* el agregador queda en rojo. *Prueba*: PR desechable en borrador, como `CA-E2ECI-03`.
- **CA-E2ECI-11** · *Dado* tres ejecuciones completas en CI, *entonces* el informe del paso recoge mediana y máximo por suite y del job, y dice si se cumple el disparador de D3. *Prueba*: los tres resúmenes enlazados.

### 9.2 Bloqueos y dependencias

- `E2E-CI-b` necesita resueltas `OPEN-E2ECI-02` a `-06`, `-08`, `-09` y `-11` (y escrito el ADR si `-11` = sí).
- `OPEN-E2ECI-03` puede convertir `0.10e-b` (nivel 1 de *runtime*) en prerrequisito.
- La intermitencia de `acad-timetable` (#741) hará rojo el job de `E2E-CI-c` de forma aleatoria; con D5 eso no se tapa con reintentos: #741 debe estar resuelto antes de cerrar `-c`, o el usuario decide excluir esa suite del job mientras siga abierto (`OPEN-E2ECI-15`).

---

## 10. Preguntas abiertas (resueltas)

**Resolución (2026-11-17, el usuario): las 15, en la opción A (recomendada).** Se conserva el texto de las alternativas como registro de lo que se descartó. Consecuencias: especificación en un solo fichero (`01`); pila con `compose.yaml` en el *runner* (`02`); `0.10e-b` es **prerrequisito** de `E2E-CI-b` (`03`); ADR propio escrito por `architect` antes de `E2E-CI-b` (`11`); #741 resuelto antes de cerrar `E2E-CI-c` (`15`); política visual de `RN-UI-07` sin cambios (`10`); *issue* aparte para `retries: 2` del job simulado (`07`).

En cada una, la opción recomendada va primero y marcada.

- **OPEN-E2ECI-01 · Dónde vive esta especificación.** `REQ-INF` **no es infraestructura**: es «Primer ciclo de Educación Infantil 0-3» (**V**, `docs/modulos/REQ-INF/funcional.md`), así que no encaja. El ámbito de escritura de `spec-writer` es `docs/modulos/REQ-XXX/`, de ahí la carpeta provisional `REQ-E2E-CI` (mismo precedente que el anexo de `ADR-090`, que se dejó en el ámbito de su autor y se señaló para que la sesión lo moviera).
  - **(A, recomendada)** La sesión la mueve a `docs/proceso/e2e-real-ci.md` (un solo fichero: funcional + operación) y borra la carpeta; precedente: `docs/design-system.md` y `docs/i18n.md`, que no son `REQ-*` porque no son *bounded context*.
  - (B) Se queda en `docs/modulos/REQ-E2E-CI/` con sus cinco ficheros (tres de ellos «no aplica»).
- **OPEN-E2ECI-02 · Cómo corre la pila en el *runner*.**
  - **(A, recomendada)** `compose.yaml` con perfil `full` e imagen `dev` de la API construida en el job: es la pila que usan los guiones en local, con FrankenPHP; menos divergencia.
  - (B) Servicios de GitHub Actions (PostgreSQL, Redis) + `setup-php` + `php artisan serve`, como `ci-api.yml`: más rápido de arrancar, pero `artisan serve` es de un hilo (#146, interbloqueo con el navegador), no es FrankenPHP y obliga a reescribir los guiones (no hay contenedor `plataforma-api` donde hacer `exec`).
  - (C) Imagen de producción de GHCR: descartable, `tests/Support` no viaja en ella y `build-images.yml` no publica imágenes de PR.
- **OPEN-E2ECI-03 · Qué *runtime* de contenedores en el *runner*.** Los guiones escriben `podman` a mano y `CONTAINER_RUNTIME` no existe aún (`0.10e-b` sin hacer).
  - **(A, recomendada)** Hacer antes `0.10e-b` (un tramo, `ADR-080 §6.1`) y usar `CONTAINER_RUNTIME=docker` en CI, igual que `storage-conformance` usa `CONTAINER_CLI=docker`: Docker en los *runners* de GitHub está documentado y ya se usa en este repositorio.
  - (B) Usar Podman en el *runner* sin tocar los guiones: depende de que la imagen `ubuntu-26.04` traiga Podman y `podman compose` (**NV**).
- **OPEN-E2ECI-04 · Fichero del flujo.** **(A, recomendada)** `ci-e2e-real.yml` nuevo. (B) Jobs dentro de `ci-web.yml`.
- **OPEN-E2ECI-05 · Qué rutas disparan el job.**
  - **(A, recomendada)** `apps/web/**`, `apps/api/**`, `compose.yaml`, `infra/containers/**`, `infra/garage/**` y el propio flujo: un cambio en la imagen, en Garage o en la definición de la pila rompe el E2E igual que uno de código.
  - (B) Solo `apps/web/**` y `apps/api/**` (lo que dice el encargo).
- **OPEN-E2ECI-06 · `push` a `develop`.** **(A, recomendada)** Sí, sin condición por diff: detecta roturas por combinación de dos PR verdes por separado. (B) Solo en PR.
- **OPEN-E2ECI-07 · `retries: 2` del job simulado de hoy.** Contradice D5 (reintento silencioso), pero es ajeno a este encargo.
  - **(A, recomendada)** Abrir *issue* aparte (Media) y tratarlo fuera de estas entregas; el job real usa `--retries=0` desde el primer día.
  - (B) Corregirlo dentro de `E2E-CI-b` (cambia `playwright.config.ts` y puede volver rojo el job simulado si hay intermitentes ocultos).
- **OPEN-E2ECI-08 · Cuándo pasa a requerido el agregador.** **(A, recomendada)** Al cerrar `E2E-CI-b`, con `CA-E2ECI-01` a `-05` cumplidos; lo marca el usuario en GitHub. (B) Informativo hasta cerrar `E2E-CI-c`.
- **OPEN-E2ECI-09 · Presupuesto de tiempo.** **(A, recomendada)** `timeout-minutes: 30` y objetivo ≤ 10 min de reloj, que es el disparador de D3. (B) Otro valor que fije el usuario.
- **OPEN-E2ECI-10 · Pruebas visuales.**
  - **(A, recomendada)** Mantener la política de `RN-UI-07`: un job (o paso) genera capturas por pantalla × tema como **artefacto de CI de corta retención**, fuera de Git y sin comparación automática; las revisa el usuario cuando un PR cambia algo visual. Sin dependencia nueva y sin cambiar ninguna norma.
  - (B) `toHaveScreenshot` de Playwright (ya instalado, no es dependencia nueva) con referencias **en Git**: detecta regresiones sin revisión humana, pero contradice «fuera de Git» y «sin herramienta de comparación de píxeles» de `RN-UI-07`; exige ADR que lo sustituya y una regla para regenerar referencias (fuentes y renderizado del *runner* fijados).
  - (C) `toHaveScreenshot` con referencias guardadas como artefacto de la última ejecución en `develop` (fuera de Git): compara sin ensuciar el repositorio, pero la caducidad de artefactos y la primera referencia dan rojos sin cambio real; también exige ADR.
- **OPEN-E2ECI-11 · ADR propio.** **(A, recomendada)** Sí, antes de `E2E-CI-b`, escrito por `architect`: cambia la topología del CI y los *checks* requeridos, igual que `ADR-089`. Título y alcance propuestos en el informe. (B) Sin ADR: basta esta especificación.
- **OPEN-E2ECI-12 · Prioridad de la matriz.** **(A, recomendada)** Por riesgo: datos de menores y decisiones de permisos primero, luego escrituras, luego lecturas. (B) Por módulo, en el orden del plan.
- **OPEN-E2ECI-13 · Familia y estudiante.** **(A, recomendada)** Hasta `1.22`, solo pruebas HTTP reales de la API que ya les sirve (las del `tutor_legal` del modo `alum`), y ninguna fila de pantalla. (B) Esperar a `1.22` para cubrir esos roles.
- **OPEN-E2ECI-14 · Artefactos en fallo.** **(A, recomendada)** Subir informe y trazas de Playwright solo si el job falla, con retención de 7 días: contienen solo datos sintéticos y las contraseñas aleatorias de un centro que ya no existe. En repositorio público los artefactos los puede descargar cualquier usuario con sesión en GitHub (**NV** el alcance exacto). (B) No subir trazas.
- **OPEN-E2ECI-15 · `acad-timetable` (#741) en el job.** **(A, recomendada)** Resolver #741 antes de cerrar `E2E-CI-c`; el job incluye la suite desde el principio. (B) Excluirla del job, con decisión explícita del usuario y anotada, hasta cerrar #741.

---

## 11. Lo no verificado (resumen)

Recuento de *specs* (29 frente a 28) Podman en `ubuntu-26.04`; qué tests de `core-roles` y `curso-academic-years` son reales; roles de cada flujo; cobertura simulada por contenido (solo por nombre de fichero); interacción cubierta por cada *spec* salvo `core-scroll-real`; si la imagen `dev` de la API instala dependencias al arrancar; tiempo de construcción de la imagen en CI; alcance de descarga de artefactos en repositorio público. Ningún fichero se ha ejecutado.

---

## 12. Operación (flujo y guiones propuestos)

Estado: **PROPUESTO** (2026-11-17). Detalle operativo de `§3`. Todo lo que sigue depende de `OPEN-E2ECI-02` a `-06` y `-09`; se escribe con la opción recomendada de cada una y cambia si el usuario decide otra cosa. Sin código: los pasos se describen, no se escriben.

### 12.1. Flujo `ci-e2e-real.yml` (propuesto)

| Aspecto | Propuesta | Fuente |
|---------|-----------|--------|
| Disparadores | `pull_request` a `develop`, `staging`, `main`; `push` a `develop` | Igual que `ci-web.yml`; `push` según `OPEN-E2ECI-06` |
| Permisos del `GITHUB_TOKEN` | `contents: read`, nada más | Issue #401, patrón de los flujos actuales |
| *Runner* | `ubuntu-26.04` fijado (#609) | Patrón de los flujos actuales |
| `concurrency` | Grupo por rama con `cancel-in-progress` en PR [DERIVADA: dos ejecuciones del mismo PR no aportan nada y gastan *runner*] | — |
| Jobs | `cambios` → `e2e-real` → agregador «E2E real (API)» | `§3` |

#### 12.1.1 Job `cambios`

- `actions/checkout` con historia suficiente para comparar con la base del PR.
- `git diff --name-only <base>...HEAD` y comparación con la lista de rutas de `OPEN-E2ECI-05`; en `push` a `develop`, `ejecutar=true` siempre.
- Salida `ejecutar`. Si el cálculo falla, el job falla (y con él el agregador): nunca «no se pudo calcular, así que no corre».

#### 12.1.2 Job `e2e-real`

Pasos en orden (propuesta con `OPEN-E2ECI-02` = A y `-03` = A):

1. *Checkout*; Node 26 con caché de `apps/web/package-lock.json`; `npm ci`; `npx playwright install --with-deps chromium`.
2. Secretos efímeros (`POSTGRES_PASSWORD`, `TENANCY_*_PASSWORD`, `GARAGE_*`, `APP_KEY`) generados con `openssl rand`, enmascarados con `::add-mask::` y escritos en `.env` raíz y `apps/api/.env` del *runner* (copia de `apps/api/.env.example` más los valores de CI). Mismo patrón que `storage-conformance` en `ci-api.yml`. Mueren con el *runner*.
3. `127.0.0.1 demo.plataforma.test demo2.plataforma.test` en `/etc/hosts` (con `sudo`).
4. Red externa `plataforma-net` creada (compose la exige: `external: true`).
5. `compose --profile full up -d` (PostgreSQL 18, Redis 8, `api`, `worker`, Garage) con `FILESYSTEM_DISK=s3`; construcción de la imagen `dev` de la API (con caché de capas si es viable: **NV** el mecanismo, decisión de implementación); espera a `/api/health`.
6. `composer install` dentro del contenedor si la imagen `dev` no lo hace sola (**NV**), migraciones con el rol propietario y `platform:sync-registry`; `infra/garage/init.sh`.
7. `E2E_ONLY=core npm run test:e2e:real -- --retries=0` en `E2E-CI-b`; en `E2E-CI-c`, las tres órdenes (`test:e2e:real`, `:prl`, `:alum`) en serie, cada una con `--retries=0`, y el job falla si falla cualquiera (sin cortar las siguientes, para medir todas).
8. Siempre: tabla de tiempos en `$GITHUB_STEP_SUMMARY` y `e2e-tiempos.json` como artefacto (`§5.2`).
9. Solo si falla: informe y trazas de Playwright como artefacto, 7 días (`OPEN-E2ECI-14`); últimas líneas de los registros de `api`, `worker` y Garage en el registro del job.

`timeout-minutes: 30` (`OPEN-E2ECI-09`).

#### 12.1.3 Agregador «E2E real (API)»

`needs: [cambios, e2e-real]`, `if: always()`, sin *checkout* (se ejecuta en la raíz, como el agregador de `ci-api.yml`). Regla en `§3`, punto 2. Es el único nombre que se añade a los *required checks*, a mano, por el usuario (`SYSADMIN.md §4`); los nombres de `cambios` y `e2e-real` no se marcan.

### 12.2. Cambios previstos en los guiones (sin escribirlos aquí)

| Guion | Cambio | Entrega |
|-------|--------|---------|
| `e2e-real-api.sh`, `e2e-real-prl.sh`, `e2e-real-alum.sh`, `lib/e2e-garage.sh`, `lib/e2e-purge.sh`, `e2e/curso-rollover.spec.ts` | Invocar el *runtime* por `CONTAINER_RUNTIME` (`0.10e-b`) en vez de `podman` escrito a mano | `0.10e-b` o `E2E-CI-b`/`-c` según `OPEN-E2ECI-03` |
| Los tres guiones | Línea `E2E_TIEMPO …` por fase y suite | `E2E-CI-b` (las tres, para que `-c` solo active suites) |
| Ninguno | **No se relaja** ninguna guarda (`APP_ENV`, marca del centro, `E2E_ALLOW_DESTRUCTIVE`) | — |

La duplicación de `purge_tenant_rows` en `e2e-real-prl.sh` y `e2e-real-alum.sh` frente a `lib/e2e-purge.sh` existe hoy; unificarla no entra salvo que el cambio de *runtime* obligue a tocar las tres copias (en ese caso, se dice en el informe; no se refactoriza en silencio).

### 12.3. Variables de entorno

| Variable | Dónde | Valor en CI |
|----------|-------|-------------|
| `CI` | GitHub | `true` (activa `retries: 2` de `playwright.config.ts`: de ahí `--retries=0` explícito) |
| `E2E_ONLY` | Guion | `core` en `E2E-CI-b`; vacía en `-c` |
| `CONTAINER_RUNTIME` | Guiones | `docker` (si `OPEN-E2ECI-03` = A) |
| `E2E_ALLOW_DESTRUCTIVE` | Guiones | La exportan los propios guiones, como hoy |
| `VITE_API_URL` | Guion (`e2e-vite-api-url.sh`) | `http://demo.plataforma.test:8000/api/v1`, como hoy |
| `FILESYSTEM_DISK` | `compose` | `s3` |
| Secretos de `§12.1.2`, paso 2 | `.env` del *runner* | Aleatorios por ejecución, enmascarados |

### 12.4. Reversión

Quitar el agregador de los *required checks* (usuario, en GitHub) y borrar `ci-e2e-real.yml`. Los guiones siguen funcionando en local con los valores por defecto (`CA-E2ECI-07`). Sin datos ni esquema que revertir.

### 12.5. Coste

Repositorio público: minutos de *runner* estándar gratuitos (`ADR-089 §1`). El coste real es el reloj de espera de cada PR que toque código; de ahí el presupuesto de `OPEN-E2ECI-09` y la medición de `§5`.
