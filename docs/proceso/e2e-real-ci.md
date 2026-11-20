# E2E real en el CI y ampliación de su cobertura (#737)

| Campo | Valor |
|-------|-------|
| Ubicación | `docs/proceso/e2e-real-ci.md` (`OPEN-E2ECI-01`, opción A). **No es un módulo ni un requisito nuevo**: sin tablas, *endpoints*, permisos ni migraciones (por eso no hay `datos`, `api` ni `permisos`) |
| Origen | Issue [#737](https://github.com/pirexia/plataforma-educativa/issues/737) (Media, 2026-11-17) |
| Requisitos que cubre | `INV-015`, `RNF-MANT-004` (CI con build, test, lint, análisis estático y escaneo), `RNF-MANT-006` (aislamiento entre tenants en cada pipeline: el E2E real lo refuerza, no lo sustituye), `CLAUDE.md §3` (E2E contra la API real; los E2E deben existir y demostrar que detectan el fallo) y `§10` |
| Prioridad | La del issue: Media |
| Fase | Fase 0/transversal: proceso y CI. No es un *bounded context*: sin tablas, sin *endpoints*, sin permisos, sin pantallas |
| Depende de | `ADR-089` (patrón de agregador), `ADR-076` (Garage en CI y E2E), `ADR-080 §5` (`0.10e-b`, `CONTAINER_RUNTIME`; **implementado el 2026-11-17 en `0.10e-b`**, con alias de respaldo `CONTAINER_CLI`: ver `OPEN-E2ECI-03`), `ADR-030` (solo datos sintéticos) |
| Esencial | No aplica (no es módulo) |
| Estado | **APROBADA** el 2026-11-17: el usuario resolvió las 15 `OPEN-E2ECI` en la opción recomendada (A). **`E2E-CI-b` (piloto `core`) implementado y mezclado (PR #766, 2026-11-18)**: `core` en verde en Actions y en `develop`; verificados `CA-E2ECI-01` a `-08` y `-12` a `-15` («E2E real (API)» es *required check* en `develop`, `staging` y `main`). **`E2E-CI-c` (todas las suites en el CI) mezclado (PR #776, `d6fc67d`)**: verificados `CA-E2ECI-09` a `-13` y `-16`. **`E2E-CI-c2` (matriz de dos jobs) activado el 2026-11-19 tras la cuarta medición (10:57) y verificado en CI (PR #789)**: `CA-E2ECI-43` a `-46`, reloj mediano 5:50 (`§9.6`). **`E2E-CI-d1` mezclado (PR #786)**. **`-d0` APROBADO por el usuario el 2026-11-18**: matriz en [`e2e-real-ci-matriz.md`](e2e-real-ci-matriz.md), criterios de `-d1`..`-d5` en `§9.4` y `OPEN-E2ECI-16` a `-25` resueltas en la opción A (`§10.1`). **`-d2` en curso (tramos 1 a 3 hechos en local: `CA-E2ECI-27` a `-31c`, `§9.7`; falta el CI de `CA-E2ECI-19`/`-20`)**; `-d3` y siguientes: nada implementado |
| Clasificación del paso: crítico | Por entrega en `§9`: `E2E-CI-b`, `-c` y `-c2` **críticas**; `-d1`..`-dn` ordinarias, **críticas** si el diff toca `apps/api/tests/Support/e2e-real-tenant.php`, los guiones de `apps/web/scripts` o `.github/workflows/ci-e2e-real.yml`; `-e` ordinaria si solo añade *specs* o capturas, **crítica** si cambia `ci-e2e-real.yml` o sube artefactos con trazas; `-a` y `-d0` sin código. La sesión lo comprueba contra el diff; la duda es crítico |

> Convención: **V** = comprobado leyendo el fichero en el *worktree* (`403c78f`); **V-idx** = comprobado en el índice de búsqueda de código de GitHub (commit `fedb617`, dos commits de documentación por detrás de `403c78f`); **NV** = no verificado (esta sesión no tiene `Bash`: no pudo listar directorios, contar ficheros, ejecutar nada ni commitear). Lo que añade esta especificación sin estar en un requisito o ADR va marcado **[DERIVADA]** con su motivo.

---

## 0. Decisiones del usuario que esta especificación NO reabre

| # | Decisión (2026-11-17) |
|---|----------------------|
| D1 | Se lleva el E2E real al CI |
| D2 | Orden: (a) esta especificación; (b) job de CI solo con la suite `core` como piloto (histórico: `E2E-CI-b`); (c) ampliar a todas las suites, `:prl` y `:alum` incluidas; (d) ampliar la cobertura de flujos (matriz flujo × rol) y pruebas visuales |
| D3 | Paralelizar con matriz por suites **solo** al cumplirse un disparador: suite real completa > ~10 min de reloj, o el job supera ese presupuesto, o la espera bloquea otro trabajo. Primer paso entonces: medir el tiempo por suite y proponer el reparto |
| D4 | No se crea un centro `demo` persistente: se espera a `1.15b` (`demo:seed`). El E2E usa el centro sintético que crea su propio guion; `E2E_ALLOW_DESTRUCTIVE=1` solo sobre él |
| D5 | Intermitentes: *issue*, nunca reintento silencioso (ya existe #741 para `acad-timetable`) |

---

## 1. Hechos de partida

### 1.1 El CI de hoy

- **V** `.github/workflows/ci-web.yml`, job `e2e` («Tests e2e (Playwright)»): `npm ci`, `npx playwright install --with-deps chromium`, `npm run test:e2e`. No levanta PostgreSQL, Redis, Garage ni la API. Los *specs* reales se saltan solos (`test.skip(process.env.E2E_REAL_API !== '1', …)`, visto en `e2e/core-scroll-real.spec.ts`).
- **V** `ci-web.yml` se dispara en `pull_request` a `develop`, `main` y `staging`, y en `push` a `develop` y `main`; sin filtro `paths:`. Los comentarios de `ci-api.yml` (líneas 23 y 185) recuerdan que los nombres de job son *required checks* de la protección de rama, que se configuran **a mano** en GitHub (`SYSADMIN.md §4`).
- **V** `apps/web/playwright.config.ts`: `retries: 0` (desde #751; antes `process.env.CI ? 2 : 0`), `fullyParallel: true`, `webServer` = `npm run dev -- --host 127.0.0.1 --port 5173`, `reuseExistingServer: !process.env.CI`, `locale: 'es-ES'`. **Hasta #751 (2026-11-17), con `CI=true` cada test fallido se reintentaba dos veces y, si pasaba en un reintento, Playwright lo daba por bueno («flaky»)**: un reintento silencioso en el sentido de D5. Corregido en #749/#751 (`retries: 0` y `trace: 'retain-on-failure'`).
- **V** `ci-api.yml`: patrón de `ADR-089` (matriz `pest` + agregador `test` con `needs`, `if: always()` y fallo si el resultado no es `success`); Garage en CI arrancado con `docker run` e `infra/garage/init.sh` con `CONTAINER_CLI=docker` y secretos efímeros enmascarados (job `storage-conformance`).

### 1.2 Los guiones de E2E real

- **V** `apps/web/scripts/e2e-real-api.sh` (suites `core`, `acad-structure`, `acad-periods`, `acad-scales`, `acad-timetable`, `curso-rollover`, `fam-unit`; `E2E_ONLY=<suite>` con lista cerrada y salida 2 ante un valor no válido), `e2e-real-prl.sh` (dos centros, modo `door`, `prl-door-api` + `prl-door-real`), `e2e-real-alum.sh` (dos centros, modo `alum`, tres *specs* HTTP y tres de navegador, dos invocaciones por el límite de 10 accesos por minuto y centro), `e2e-vite-api-url.sh` (fija `VITE_API_URL=http://<slug>.plataforma.test:8000/api/v1`), `lib/e2e-garage.sh` (`ensure_garage`) y `lib/e2e-purge.sh` (`purge_tenant_rows`; los guiones de PRL y alumnado llevan su propia copia de la función: **V**, duplicación ya existente).
- **V** Acoplamiento a la pila de desarrollo: `podman exec` al contenedor `plataforma-api` (`tests/Support/e2e-real-tenant.php setup|teardown`) y a `plataforma-postgres` (purga con el superusuario y `session_replication_role = replica`); `podman compose --profile full up -d garage` y recreación de `api`/`worker` con `FILESYSTEM_DISK=s3`; guarda `APP_ENV` `local|testing` en `ensure_garage`. `ADR-080 §5.1` cita además `e2e/curso-rollover.spec.ts` como fichero que invoca `podman` (ejecuta la cola `long` dentro del contenedor de la API, según el comentario de `e2e-real-api.sh`).
- **V** `compose.yaml`: red externa `plataforma-net`, `api` construida desde `infra/containers/api` (`target: dev`) montando `./apps/api`, `env_file: apps/api/.env` y `.env` raíz con `POSTGRES_*`, `TENANCY_*` y `GARAGE_*` obligatorias; `CORS_ALLOWED_ORIGINS: http://demo.plataforma.test:5173`; `TENANCY_BASE_DOMAIN: plataforma.test`.
- **V** `e2e/fixtures/realSession.ts`: `E2E_API_URL` (por defecto `http://127.0.0.1:8000/api/v1`), `E2E_BASE_DOMAIN` (por defecto `plataforma.test`) y cabecera `Host` con `:8000` **escrito a mano**. `e2e/core-scroll-real.spec.ts` ya acepta `E2E_BASE_URL`.
- **V** `ci-api.yml`, comentario del paso «Arrancar servidor de desarrollo»: `php artisan serve` es de un solo hilo y el issue #146 describe un interbloqueo con peticiones **entrantes del navegador**. Es el motivo de no proponer `artisan serve` para el E2E de navegador (`OPEN-E2ECI-02`).

### 1.3 Inventario de *specs*

- **V-idx** `apps/web/e2e/` contiene 34 ficheros `.ts`: 29 *specs* y 5 *fixtures* (`alumReal`, `dataExport`, `realSession`, `tableFixture`, `landingShowcase`). El encargo hablaba de 28 *specs*; son 29 (**V**: `ls apps/web/e2e/*.ts`, comprobado por `doc-reviewer`). `e2e-real-api.spec.ts`, citado en un comentario de `e2e-real-api.sh`, está en `apps/web/scripts/` (**V**), no en `e2e/`.
- **V** *Specs* que ejecutan los guiones reales: `core-roles` (`CA-PERM-133`), `curso-academic-years` (`CA-CURSO-086`), `core-scroll-real` (`RNF-MANT-007`), `acad-structure` (`CA-ACAD-053`), `acad-periods` y `acad-scales` (`CA-ACAD-101`), `acad-timetable` (`CA-ACAD-135`, intermitente #741), `curso-rollover` (`CA-CURSO-147`), `fam-unit-pickup-real` (`CA-FAM-UNIT-142`), `prl-door-api` (`CA-PRL-002`, `CA-PRL-035`) y `prl-door-real` (`CA-PRL-012`), `alum-students-real`, `alum-enrollments-real`, `alum-photo-real`, `alum-screens-real`, `alum-group-change-real`, `data-table-real`. En `core-roles` y `curso-academic-years` el guion filtra con `-g`: conviven tests reales y simulados en el mismo fichero (**NV** cuántos de cada).

### 1.4 Lo que hay hoy en la SPA (para la matriz de `§6`)

**V** Siete módulos web con `shell.ts`: `auth`, `core`, `curso`, `acad`, `fam-unit`, `prl`, `alum`. Todas las pantallas con sesión son **de personal del centro**. **No existe ninguna pantalla de familia ni de estudiante**: el portal de familias es `1.22` (`REQ-FAM-PORTAL`, citado en `REQ-INF/funcional.md §1.3`). Lo único que alcanza cualquier usuario autenticado, incluida una familia, son el autoservicio de cuenta (`/cuenta/*`), el muro de MFA y el muro de condiciones de uso (`/condiciones-de-uso`). La API sí sirve a la familia (`INV-006`): el modo `alum` del guion crea un usuario con rol `tutor_legal` que usan los *specs* HTTP (**V** por las variables `E2E_*_TUTOR_*`; **NV** qué *endpoints* ejercita).

---

## 2. Alcance

### 2.1 Entra

1. Job de CI que ejecuta el E2E real contra una pila efímera en el *runner*, condicionado por el diff, integrado como *check* requerido mediante un agregador que no bloquea los PR que no lo disparan (`§3`, `§12`).
2. Piloto con la suite `core` (`E2E-CI-b`; histórico, hoy el job ejecuta todas), con criterios medibles y la demostración de que el job detecta un fallo (`§4`).
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
| Cambiar el job simulado `e2e` de hoy | Fuera del encargo; su `retries: 2` se corrigió aparte (#749, #751; `OPEN-E2ECI-07`) |
| Implementar el nivel 1 de *runtime* (`0.10e-b`) | Es otro paso del plan; esta especificación solo depende de él según `OPEN-E2ECI-03` |
| Pantallas de familia o de estudiante | No existen (`§1.4`); `1.22` |
| Herramienta nueva de comparación de píxeles | `RN-UI-07` la excluye; cualquier cambio, `OPEN-E2ECI-10` |

---

## 3. Diseño del job (resumen; el detalle operativo está en `§12`)

**Diseño aprobado** (`OPEN-E2ECI-02` a `-06` resueltas en la opción A, 2026-11-17):

1. **Fichero**: flujo nuevo `.github/workflows/ci-e2e-real.yml` [DERIVADA: aislar un job largo y con servicios propios de `ci-web.yml`, cuyo *job* `e2e` simulado sigue igual] (`OPEN-E2ECI-04`).
2. **Tres jobs**, mismo patrón que `ADR-089`:
   - `cambios` («E2E real · detección de cambios»): sin servicios; calcula con `git diff --name-only` contra la base del PR si el cambio toca alguna ruta de la lista de `OPEN-E2ECI-05` (en `push` a `develop`, `ejecutar=true` siempre, `OPEN-E2ECI-06`); salida `ejecutar=true|false`. **Sin acción de terceros** (`dorny/paths-filter` u otra sería dependencia nueva: `CLAUDE.md §1`).
   - `e2e-real` («E2E real · todas las suites» desde `E2E-CI-c`; matriz de dos jobs «E2E real · alum» y «E2E real · resto» desde `E2E-CI-c2`, `§9.6`; en el piloto de `E2E-CI-b` se llamaba «E2E real · core», solo `core`): `needs: cambios`, `if: needs.cambios.outputs.ejecutar == 'true'`. Levanta la pila, prepara el centro sintético con el guion y ejecuta Playwright.
   - Agregador «**E2E real (API)**»: `needs: [cambios, e2e-real]`, `if: always()`. **Verde** si (`cambios` = `success` y `ejecutar` = `false` y `e2e-real` = `skipped`) o (`cambios` = `success` y `ejecutar` = `true` y `e2e-real` = `success`) (más estricta que la redacción original: derivada (a) de `ADR-091`, confirmada el 2026-11-18). **Rojo** en cualquier otro caso: `cambios` fallido o cancelado, `e2e-real` fallido, cancelado, o saltado con `ejecutar = true`. Es el único que se marca como requerido en GitHub (lo hace el usuario a mano, `SYSADMIN.md §4`).
3. **Pila en el *runner*** (`OPEN-E2ECI-02`, recomendada A): `compose.yaml` levantando **solo los servicios nombrados** `postgres redis api worker garage` (más `worker-long` solo si la suite lo necesita: `curso-rollover` ejecuta la cola `long`, `§1.2`; **decidido: `curso-rollover` pasa sin `worker-long`**, runs de `§9.3`) (PostgreSQL 18, Redis 8, `api` con la imagen `dev` construida en el job, que sirve con `php artisan serve` (un hilo; **no FrankenPHP**: fe de erratas del 2026-11-18, `ADR-091` fe de erratas) y necesita `composer install` en el host antes del `compose up` porque el bind mount `./apps/api:/var/www/html` tapa el código de la imagen, `worker`, Garage) y **sin `web`**: ese servicio no tiene perfil en `compose.yaml` (**V**) y arrancaría con cualquier `up` sin nombres, y su puerto `127.0.0.1:5173` chocaría con el `webServer` de Playwright (con `CI=true`, `reuseExistingServer: false`), además de que `e2e-vite-api-url.sh` leería la SPA que encuentre en `:5173`, red externa `plataforma-net` creada en el job, `.env` raíz y `apps/api/.env` generados en el job con secretos efímeros enmascarados (patrón de `storage-conformance`), migraciones y `platform:sync-registry` aplicados, `127.0.0.1 demo.plataforma.test demo2.plataforma.test` añadido a `/etc/hosts` (el *runner* admite `sudo`: **V**, lo usa `ci-api.yml`). Vite lo arranca Playwright (`webServer`) con `VITE_API_URL` del guion.
4. **Datos**: el centro sintético del propio guion (`e2e-real-tenant.php setup`), con contraseñas aleatorias por ejecución (`ADR-080 §3.6`). Nada de `REQ-SEED` persistente (D4). La base es efímera y muere con el *runner*; la guarda `E2E_ALLOW_DESTRUCTIVE=1` y la marca del centro **se mantienen igual** (no se relajan porque la base sea desechable) [DERIVADA: un guion que aprende a saltarse su guarda en CI acaba saltándosela en local].
5. **Sin reintentos**: el job real ejecuta Playwright con `--retries=0` (los guiones pasan `"$@"` a Playwright: **V**). Un test que falla deja el job en rojo; si se sospecha intermitencia, se abre *issue* (D5) y el test no se marca como `skip` sin decisión del usuario. **Un verde exige tests ejecutados**: tras Playwright, un paso falla el job si el informe JSON muestra algún test `skipped` o menos tests pasados de los esperados por suite (los *specs* reales se saltan solos sin `E2E_REAL_API`, y `e2e-real-alum.sh` usa `--pass-with-no-tests`: sin este paso, un job con todo saltado saldría en verde, el mismo agujero que se cierra). El número esperado por suite lo fija la implementación en un fichero versionado.
6. **Presupuesto de tiempo** (`OPEN-E2ECI-09`, recomendado): `timeout-minutes: 30` como techo duro; objetivo de reloj del job ≤ 10 min (el umbral de D3). Superarlo es un disparador de D3, no un fallo.
7. **Evidencias**: resumen de tiempos en `$GITHUB_STEP_SUMMARY` y, si falla, el informe y las trazas de Playwright como artefacto de corta retención (`OPEN-E2ECI-14`). Con `--retries=0` Playwright **no graba trazas** (hasta #751 `playwright.config.ts` usaba `trace: 'on-first-retry'`; hoy `retain-on-failure`) y su *reporter* es `list`: el job fija `--trace=retain-on-failure` y un *reporter* `json` (resumen y comprobación de saltados; un fichero por suite en `E2E_INFORMES_DIR`, que fija `lib/e2e-informe.sh`; verificado por los runs de `§9.3`) más `html` (artefacto). **Riesgo aceptado (#779)**: **Decidido por el usuario (2026-11-18, #779): se mantienen las trazas en el artefacto de fallo; riesgo documentado; #779 se cierra al mezclar.** el artefacto de fallo contiene trazas de Playwright que pueden incluir la contraseña y las cookies de sesión **sintéticas y efímeras** del centro de prueba; en un repositorio público cualquier usuario autenticado de GitHub puede descargarlo durante los 7 días de retención. No se cambia cómo se suben las trazas.

---

## 4. Piloto `core` (`E2E-CI-b`)

### 4.1 Qué se entrega

El flujo de `§3` con la suite `core` (`E2E_ONLY=core`: `core-roles` `CA-PERM-133`, `curso-academic-years` `CA-CURSO-086`, `core-scroll-real` `RNF-MANT-007`), la adaptación mínima de los guiones para correr en el *runner* (`OPEN-E2ECI-03`), la instrumentación de tiempos de `§5`, la demostración de detección de `§4.3` y la actualización de los documentos que dicen que el E2E real no corre en el CI: `SYSADMIN.md §4` (tabla de *jobs* requeridos) y la fila «Tests» de `CLAUDE.md §1` (esta, solo con aprobación del usuario, por rama `chore/` y PR).

### 4.2 Criterios de aceptación

- **CA-E2ECI-01** · *Dado* un PR que modifica un fichero de `apps/web/src`, *cuando* corre el CI, *entonces* el job `e2e-real` se ejecuta, la suite `core` pasa con 3 tests reales ejecutados (no saltados) y el agregador «E2E real (API)» queda en verde. *Prueba*: ejecución del CI en el PR de la entrega; el informe copia el resumen literal (tests ejecutados, pasados, saltados).
- **CA-E2ECI-02** · *Dado* un PR que solo modifica ficheros de `docs/`, *cuando* corre el CI, *entonces* `cambios` termina en `success` con `ejecutar=false`, `e2e-real` queda `skipped` y el agregador queda en **verde**. *Prueba*: PR de prueba solo de documentación (o el propio PR de un paso documental), enlace a la ejecución en el informe.
- **CA-E2ECI-03** · *Dado* un PR que rompe a propósito el comportamiento que cubre `core-scroll-real` (caso de referencia de #729: p. ej. que una navegación a ruta nueva deje de terminar arriba), *cuando* corre el CI, *entonces* `e2e-real` falla en ese test y el agregador queda en **rojo**. *Prueba*: rama desechable `chore/e2e-ci-prueba-rotura` con la rotura, PR en borrador **que nunca se mezcla**, enlace a la ejecución roja en el informe; después se cierra el PR y se borra la rama. Es la prueba de `CLAUDE.md §3` («un E2E nuevo debe demostrar que detecta el fallo») aplicada al job.
- **CA-E2ECI-04** · *Dado* que `cambios` dice `ejecutar=true`, *cuando* `e2e-real` se cancela o se salta por otra causa, *entonces* el agregador queda en rojo. *Prueba*: cancelar a mano una ejecución de `e2e-real` en un PR de prueba; resultado literal en el informe.
- **CA-E2ECI-05** · *Dado* un test real que falla en el primer intento, *cuando* corre en el job, *entonces* no se reintenta y el job queda en rojo. *Prueba*: la misma ejecución de `CA-E2ECI-03`, cuyo informe JSON de Playwright muestra 0 reintentos y cuyo artefacto trae la traza del test fallido.
- **CA-E2ECI-06** · *Dado* el job terminado (verde o rojo), *entonces* `$GITHUB_STEP_SUMMARY` muestra la tabla de tiempos de `§5.2` con todas sus fases, y ningún secreto efímero aparece sin enmascarar en el registro. *Prueba*: revisión del resumen y del registro de la ejecución de `CA-E2ECI-01`.
- **CA-E2ECI-07** · *Dado* el guion de E2E real ejecutado **en local** tras los cambios de la entrega, *cuando* se lanza `npm run test:e2e:real` sin variables nuevas, *entonces* se comporta como antes (mismas suites, mismos resultados) [regresión de los guiones]. *Prueba*: ejecución local con la skill `espera-e2e`, resultado literal en el informe.
- **CA-E2ECI-08** · *Dado* el agregador en verde de forma estable en el PR de la entrega, *cuando* se cierra `E2E-CI-b`, *entonces* el usuario lo ha añadido como *required check* de `develop` (y de `staging`/`main` si así lo decide) y el informe lo dice con la fecha (`OPEN-E2ECI-08`). *Prueba*: la configuración de ramas protegidas en GitHub (la comprueba el usuario) y su confirmación en el informe.

- **CA-E2ECI-12** · *Dado* un job donde los tests reales quedan todos saltados (p. ej. `E2E_REAL_API` sin definir) o en número menor que el esperado, *cuando* termina Playwright, *entonces* el job falla y el agregador queda en rojo. *Prueba*: ejecución desechable en borrador que fuerza el salto, como `CA-E2ECI-03`; enlace en el informe.
- **CA-E2ECI-13** · *Dado* un `push` a `develop` (tras mezclar la entrega), *entonces* el job corre sin condición por diff. *Prueba*: ejecución del CI en el commit de mezcla.
- **CA-E2ECI-14** · *Dado* el flujo `ci-e2e-real.yml`, *entonces* declara `timeout-minutes: 30` y `permissions: contents: read`, y no usa `pull_request_target`. *Prueba*: revisión del YAML en el diff por la sesión y `security-reviewer`.
- **CA-E2ECI-15** · *Dado* una ejecución verde y una roja, *entonces* el artefacto de informe y trazas solo existe en la roja, con retención de 7 días. *Prueba*: las ejecuciones de `CA-E2ECI-01` (sin artefacto) y `CA-E2ECI-03` (con artefacto).

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

### 6.2 Método para llenarla (lo ejecuta `E2E-CI-d0`: inventario por `explorer`, roles, prioridad y partición por `spec-writer`; sin `implementer`)

> Corrección del 2026-11-18: este título decía «lo ejecuta un `explorer` al empezar `E2E-CI-d`», en contradicción con `§9` («`-d0` = `explorer` + `spec-writer`»). Se alinea con `§9`, que es como se hizo: el `explorer` no leyó los `permisos.md` (paso 2), que son trabajo de `spec-writer`. Resultado: [`e2e-real-ci-matriz.md`](e2e-real-ci-matriz.md).

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
| auth | `login`, recuperación, restablecimiento, desbloqueo, invitación (`/entrar`, `/recuperar`, `/restablecer/:token`, `/desbloquear/:token`, `/activar/:token`) | pública | Inicio de sesión como paso previo de todos los *specs* de navegador (**V** en `core-scroll-real`); ningún *spec* real del flujo en sí | Por nombre: `apps/web/e2e/public-auth-shell.spec.ts` |
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
| — | Transversal: *shell*, radio, *design system*, tabla | — | `data-table-real` (tabla TanStack, `RNF-MANT-007`) | Por nombre: `apps/web/e2e/shell.spec.ts` (**V**, simulado), `apps/web/e2e/radius.spec.ts`, `apps/web/e2e/design-system.spec.ts`, `apps/web/e2e/data-table.spec.ts`; `apps/web/e2e/landing-shots.spec.ts` es de la *landing* |

**Familia y estudiante**: sin filas de pantalla, porque no existen (`§1.4`). Para ellos la matriz solo puede tener filas de **API** (prueba HTTP real con cookie y CSRF), y la de estudiante ni eso mientras el guion no cree un usuario estudiante (**NV** si algún *endpoint* de hoy atiende a un estudiante) (`OPEN-E2ECI-13`). *(Superado para el tutor en `-d2`: el muro se prueba en navegador con `e2e-tutor`, `OPEN-E2ECI-25` = B, `§9.7`.)*

---

## 7. Pruebas visuales

> Nota: `RN-UI-07` asigna hoy la generación de capturas a `verificador`; con `OPEN-E2ECI-10` = A pasa a un paso del CI (artefacto de corta retención, fuera de Git), sin cambiar el resto de la política. `E2E-CI-e` lo refleja en `REQ-CORE/capa-visual.md`.

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
| `E2E-CI-b` | Piloto `core` (`§3`, `§4`), instrumentación de tiempos (`§5`), adaptación de guiones al *runner*, `SYSADMIN.md §4` y fila «Tests» de `CLAUDE.md §1` | 2-3 | **Crítico** (duda): toca guiones con purga por superusuario y la guarda `E2E_ALLOW_DESTRUCTIVE`, genera secretos en CI y cambia un *check* requerido de la protección de rama | Codex; `security-reviewer` (su columna: secretos en CI, permisos del `GITHUB_TOKEN`, guardas destructivas); `doc-precheck` y `doc-reviewer` | Sí, antes de implementar (`OPEN-E2ECI-11`); **debe enmendar `ADR-080 §5.3, punto 4`** (con `docker` el soporte es «de mejor esfuerzo» y «no hay trabajo de CI que lo ejecute»: el CI pasa a ejecutarlo) | No |
| `E2E-CI-c` (**hecho y mezclado**, PR #776, `§9.3`) | Todas las suites (`core`, `acad-*`, `curso-rollover`, `fam-unit`, `:prl`, `:alum`) en el job; `curso-rollover.spec.ts` sin `podman` escrito a mano; 3 mediciones y, si salta D3, propuesta de reparto | 1-2 | **Crítico** (mismo motivo que `-b`) | Igual que `-b` | No (lo cubre el de `-b`) | No |
| `E2E-CI-c2` (**ACTIVADO el 2026-11-19** por decisión del usuario tras medir, `§9.5`; **implementado y verificado en CI en el PR #789**, `§9.6`) | Matriz de dos jobs paralelos (`alum` y `resto`) con el mismo agregador | 1 | **Crítico** (cambia la topología de un *check* requerido) | Igual que `-b` | Enmienda del ADR de `-b` si cambia su decisión | No |
| `E2E-CI-d0` (**APROBADO** el 2026-11-18) | Matriz completa (`§6.2`), con prioridad y propuesta de partición de `-d`: [`e2e-real-ci-matriz.md`](e2e-real-ci-matriz.md) (67 filas, 53 huecos: 31 P1, 14 P2, 8 P3; 3 no viables) | — (`explorer` + `spec-writer`) | — | `doc-precheck`, `doc-reviewer` | No | No |
| `E2E-CI-d1`..`-d5` (propuesta de `-d0`, matriz `§4.2`; **`-d1` hecho y mezclado**, PR #786, `§9.5`) | `-d1` autorización en pantalla; `-d2` datos de menores y familias (**EN CURSO, PR #795; tramos 1-3 hechos; `CA-E2ECI-19`/`-20` pendientes**); `-d3` autenticación; `-d4` escrituras restantes; `-d5` lecturas y transversal | ≤3 cada una (`-d1` 2-3, `-d2` 2-3, `-d3` 3, `-d4` 3, `-d5` 1-2) | **Críticas las cinco** (`OPEN-E2ECI-21` = A, 2026-11-18: registrar un *spec* toca los guiones de `apps/web/scripts`; `-d3` toca además `apps/api/tests/Support/e2e-real-tenant.php`, que crea usuarios, roles y datos de menores sintéticos y tiene guardas de entorno) | Codex; `security-reviewer` si crítico; `doc-precheck`, `doc-reviewer` | No | No |
| `E2E-CI-e` | Pruebas visuales según lo que se decida en `OPEN-E2ECI-10` | 1-2 | Ordinario si solo añade *specs* o capturas; **crítico** si cambia `ci-e2e-real.yml` o sube artefactos con trazas | Codex; `doc-precheck`, `doc-reviewer` | Sí si se adopta comparación con referencias, porque cambia `RN-UI-07` (`OPEN-E2ECI-10`) | No |

**E2E / prueba que cubre cada criterio**: `CA-E2ECI-01` a `-08` y `-12` a `-15` en `§4.2` (cada uno lleva su prueba). Para `E2E-CI-c`: `CA-E2ECI-09` a `-11` y `-16` (`§9.1`). Para `-d1`..`-d5`: `CA-E2ECI-17` a `-42`, más `-22b`, `-26b`, `-31b`, `-31c`, `-42b` y `-42c` (`§9.4`, de `-d0`, aprobados el 2026-11-18). Para `-c2`: `CA-E2ECI-43` a `-46` (`§9.6`). Para `-e`: los fija la sesión al decidirse `OPEN-E2ECI-10`; no se escriben antes de tener esos datos, y la entrega no empieza sin ellos.

### 9.1 Criterios de `E2E-CI-c`

- **CA-E2ECI-09** · *Dado* un PR que toca `apps/api`, *cuando* corre el CI, *entonces* el job ejecuta las siete suites de `e2e-real-api.sh`, la de `:prl` y la de `:alum`, y el resumen muestra por suite tests ejecutados/pasados/saltados, con 0 saltados por falta de `E2E_REAL_API`. *Prueba*: ejecución del CI del PR de la entrega.
- **CA-E2ECI-10** · *Dado* una rotura deliberada en una suite distinta de `core` (p. ej. el flujo de `alum-group-change-real`), *cuando* corre el CI, *entonces* el agregador queda en rojo. *Prueba*: PR desechable en borrador, como `CA-E2ECI-03`.
- **CA-E2ECI-11** · *Dado* tres ejecuciones completas en CI, *entonces* el informe del paso recoge mediana y máximo por suite y del job, y dice si se cumple el disparador de D3. *Prueba*: los tres resúmenes enlazados.

- **CA-E2ECI-16** · *Dado* que `acad-timetable` (#741) está en el job, *cuando* se cierra `E2E-CI-c`, *entonces* #741 está cerrado y tres ejecuciones completas seguidas no fallan en esa suite. *Prueba*: enlace al issue cerrado y a los tres resúmenes de `CA-E2ECI-11`.

### 9.3 Estado y evidencias de `E2E-CI-c` (2026-11-18)

Todas las suites corren en un único job «E2E real · todas las suites» (`ci-e2e-real.yml`; el agregador «E2E real (API)» evalúa `needs.e2e-real.result`, no el nombre del job). Run **44326426824** (PR #776, borrador).

- **`CA-E2ECI-09`** · verificado. Intento 1 en verde: job 14:38:22 a 14:46:01 (**7 min 39 s**). Recuento real de Playwright: `prl` 3, `alum-http` 16, `alum-browser` 20, `core` 3 y `acad-structure`, `acad-periods`, `acad-scales`, `acad-timetable`, `curso-rollover` y `fam-unit` 1 cada una; coincide con `apps/web/e2e/e2e-real-esperados.json` (0 saltados). `curso-rollover` pasó **sin `worker-long`**.
- **`CA-E2ECI-10`** · verificado. Rotura deliberada en `prl` (se quitó la cabecera `Idempotency-Replayed` en `StudentMovementsController`): run **44332315618** (PR desechable #777, cerrado sin mezclar, rama borrada). Job y agregador en rojo; `prl` 2 pasados y 1 fallado; el resto de suites se ejecutaron y pasaron (sin corte entre suites); artefacto `e2e-real-informe-fallo` subido.
- **`CA-E2ECI-11`** · verificado. Tres ejecuciones completas en verde del mismo run (intentos 1, 2 y 3): job 7:39 (14:38:22 a 14:46:01), 8:22 (14:47:13 a 14:55:35) y 8:15 (14:56:19 a 15:04:34). **Mediana 8:15, máximo 8:22.** Por debajo de ~10 min: **no se cumple el disparador de `D3`**, no se propone `E2E-CI-c2` y `timeout-minutes: 30` se mantiene definitivo (holgura ×3,6 sobre el máximo).

  Tiempo de la fase `playwright` por suite (segundos de reloj del guion, línea `E2E_TIEMPO`; `alum` agrupa `alum-http` y `alum-browser`), intentos 1, 2 y 3 → mediana / máximo:

  | Suite | Intentos 1 / 2 / 3 | Mediana | Máximo |
  |---|---|---:|---:|
  | `prl` | 21 / 25 / 21 | 21 | 25 |
  | `alum` | 95 / 98 / 87 | 95 | 98 |
  | `core` | 24 / 29 / 20 | 24 | 29 |
  | `acad-structure` | 15 / 18 / 13 | 15 | 18 |
  | `acad-periods` | 13 / 14 / 11 | 13 | 14 |
  | `acad-scales` | 12 / 14 / 9 | 12 | 14 |
  | `acad-timetable` | 31 / 37 / 29 | 31 | 37 |
  | `curso-rollover` | 18 / 19 / 16 | 18 | 19 |
  | `fam-unit` | 9 / 11 / 8 | 9 | 11 |

  Estas cifras (guion, incluye arranque de Playwright) difieren en 1-2 s de las que Playwright informa de sí mismo (p. ej. `prl` 19,8 s en el intento 1). Los segundos de `setup`/`teardown` son de 1 a 7 s por suite.
- **`CA-E2ECI-12`** (aplicado a `-c`) · verificado. PR desechable #778 (cerrado, rama borrada), run **44335425615**: con el esperado de `prl` subido a 4, «E2E real INCOMPLETO (suite=prl pasados=3 saltados=0 esperados=4)»; job y agregador en rojo.
- **`CA-E2ECI-16`** · verificado. #741 **cerrado** (2026-11-17); `acad-timetable` pasó en las tres ejecuciones de `CA-E2ECI-11`.
- **Guarda de `jq` (#780) verificada en CI**: el commit `76d0e8e` (guarda ante lectura fallida o vacía de `e2e-real-esperados.json`), ya en `c792290`, pasó en el run **44338603732**: job «E2E real · todas las suites» 15:41:58 a 15:50:25 (**8 min 27 s**), agregador verde, 23 checks en verde y 1 saltado. Esa cuarta ejecución es el **máximo observado del job (8:27)**; la tabla oficial de `CA-E2ECI-11` (tres ejecuciones: mediana 8:15, máximo 8:22) no se recalcula, y la conclusión (por debajo de ~10 min, holgura ≈×3,5 del `timeout-minutes: 30`) no cambia. #780 se cierra al mezclar. **Decidido por el usuario (2026-11-18, #779): se mantienen las trazas en el artefacto de fallo; riesgo documentado; #779 se cierra al mezclar.**
- **Pendiente tras el merge**: `CA-E2ECI-13` aplicado a `-c` (el job corre en el `push` a `develop` con `-c`) y, solo desde ese verde, `docker` cuenta como verificado también para `:prl`, `:alum` y `curso-rollover` (`ADR-091 §4.3`).

### 9.5 Mediciones y evidencias de `E2E-CI-d1` (2026-11-18/19)

Tres ejecuciones completas en CI del PR #786 (run 44418662154, intentos 1 a 3), todas **verdes**, con la suite ya ampliada (`prl` 9, `alum-browser` 39; `CA-E2ECI-19` y `-20`):

| Intento | Job «E2E real · todas las suites» |
|---|---|
| 1 | 7 min 13 s |
| 2 | 8 min 22 s (23:20:24 → 23:28:46) |
| 3 | 9 min 15 s (23:29:09 → 23:38:24) |

**Mediana del job 8:22; máximo 9:15.** El disparador de D3 (~10 min) **no se cumple**, pero la holgura sobre el máximo es de unos 45 s y `d2` lo superará con alta probabilidad (cifras de coste sin medir en la matriz §4.3); el intento 3 fue más lento en todas las suites a la vez (ruido del *runner*). Tiempos por suite en segundos (líneas `E2E_TIEMPO ... fase=playwright`; mediana / máximo de los tres intentos): `prl` 27 / 33, `alum` 136 / 141, `core` 24 / 28, `acad-structure` 15 / 18, `acad-periods` 12 / 15, `acad-scales` 11 / 14, `acad-timetable` 32 / 38, `curso-rollover` 17 / 20, `fam-unit` 10 / 11. `alum` pasa de 95 s (mediana de `E2E-CI-c`) a 136 s con los 19 tests nuevos de `d1`; `prl` de 21 a 27 s. **Cuarta observación (push a `develop` tras mezclar #786, run 44488277802, 2026-11-19): job 10 min 57 s (08:31:18 → 08:42:15), verde; supera el umbral de ~10 min (disparador de D3 en la práctica; causa de la dispersión sin investigar).** **Propuesta**: decidir la partición en matriz (`E2E-CI-c2`) antes de empezar `d2`, no después; la decisión es del usuario (`§5.3`).

### 9.6 `E2E-CI-c2`: matriz de dos jobs (2026-11-19; implementado y verificado en CI, PR #789)

**Cobertura por el ADR**: `ADR-091` (línea 80) ya dice que una matriz que conserve el nombre del agregador y la regla de `§2.4` está cubierta; `E2E-CI-c2` no necesitó enmienda ni ADR nuevo (`D3` exigía la aprobación del usuario, registrada aquí).

**Decisión del usuario (2026-11-19, tras medir, `§5.3`)**: partir el job en dos jobs paralelos. Medido: job único ~9-11 min (instalación ~55 s, pila ~85 s, suites ~340 s); el pico de 10:57 sigue sin causa identificada. Reparto: (1) `alum` (`test:e2e:real:alum`; `alum-http` y `alum-browser`, ~135-140 s de Playwright) y (2) `resto` (`test:e2e:real`, las siete suites de `e2e-real-api.sh`, y `test:e2e:real:prl`; ~150-200 s de Playwright; medido: 350 s de job con ~140 s de coste fijo, ver abajo). Estimación sin medir: cada job paga la instalación y la pila (~140 s) más sus suites, es decir ~5-6 min de reloj frente a ~9-11.

**Cómo está hecho (`ci-e2e-real.yml`)**: el job `e2e-real` pasa a `strategy.matrix.include` con `grupo`, `ordenes` y `claves`, `fail-fast: false` (una suite en rojo no oculta el resultado del otro grupo; sin reintentos, D5), nombre `E2E real · <grupo>` y mismo `timeout-minutes: 30`. Cada hijo corre en **su propio *runner***, con su pila, su centro, sus secretos efímeros y su `.env` (nada compartido; los puertos 127.0.0.1, los nombres `plataforma-*` y la red `plataforma-net` son locales de cada máquina virtual, sin choque). La comprobación de tests esperados verifica solo las claves del grupo y falla si las claves del grupo no coinciden con `e2e-real-esperados.json` (un test de Vitest exige además que la unión de los dos grupos sea exactamente el conjunto de esperados: ninguna suite se pierde). Los tiempos llevan el identificador del job (`E2E_TIEMPO suite=ci-<grupo> fase=…`) y los artefactos nombre propio por grupo (`e2e-real-tiempos-<grupo>`, `e2e-real-informe-fallo-<grupo>`, solo el de fallo si falla).

**Agregador «E2E real (API)»**: sin cambios de código ni de nombre. `needs.e2e-real.result` de una matriz es `success` solo si **todos** los hijos terminan en `success`; con un hijo en `failure` o `cancelled` no lo es, y la regla de `ADR-091 §2.4` (verde solo con `ejecutar=true` y `e2e-real=success`, o `ejecutar=false` y `e2e-real=skipped`) da rojo. Si `cambios` decide `ejecutar=false`, la matriz entera queda `skipped` y el agregador pasa como hoy.

- **CA-E2ECI-43** · *Dado* un PR que toca `apps/api`, *cuando* corre el CI, *entonces* corren los dos jobs `E2E real · alum` y `E2E real · resto`, en paralelo, cada uno con 0 saltados y los recuentos de `e2e-real-esperados.json` de sus claves (`alum` 16 y 39; `resto` las ocho restantes). *Prueba*: ejecución del CI del PR.
- **CA-E2ECI-44** · *Dado* el workflow, *entonces* el agregador conserva el nombre «E2E real (API)», `needs: [cambios, e2e-real]` y la regla de `ADR-091 §2.4`; y *dado* un PR que no toca rutas del E2E, *entonces* la matriz queda `skipped` y el agregador en verde. *Prueba*: Vitest (`e2e-real-ci.spec.ts`) y un PR solo de documentación.
- **CA-E2ECI-45** · *Dado* una rotura deliberada en una suite del grupo `alum` (un `expect` falso en un spec de `alum-browser`), *cuando* corre el CI, *entonces* `E2E real · alum` y el agregador quedan en rojo y `E2E real · resto` termina y pasa. *Prueba*: PR desechable en borrador (nunca se mezcla).
- **CA-E2ECI-46** · *Dado* una rotura deliberada en una suite del grupo `resto` (un `expect` falso en `acad-structure.spec.ts`; nota: `core-authorization-real.spec.ts` NO sirve, lo ejecuta el guion de `alum`), *cuando* corre el CI, *entonces* `E2E real · resto` y el agregador quedan en rojo y `E2E real · alum` termina y pasa. *Prueba*: PR desechable en borrador. Con `CA-E2ECI-45` demuestra que el agregador detecta el fallo en **cualquiera** de los dos jobs.
- **Verificado (2026-11-19, PR #789, run 44616908495, tres intentos en verde)**: `alum` 336, 335 y 330 s (mediana 335, máximo 336); `resto` 483, 350 y 347 s (mediana 350, máximo 483). Reloj del paso = el mayor de los dos: mediana 5:50, máximo 8:03 (antes 9-11 min). `CA-E2ECI-43` y `-44` cumplidos. `CA-E2ECI-45` demostrado (PR borrador #790, run 44629728669: `alum` y agregador en rojo, `resto` en verde) y `CA-E2ECI-46` demostrado (PR borrador #792, run 44631993276: `resto` y agregador en rojo, `alum` en verde); ambos PR se cerraron sin mezclar y sus ramas se borraron. Un primer intento de `-46` con `core-authorization-real.spec.ts` falló en `alum`, porque esa spec corre en el guion de `alum`.
- **Causa de la dispersión de tiempos (identificada el 2026-11-19)**: el paso `npx playwright install-deps chromium` (paquetes del sistema por `apt`) tardó 170 s en `resto` del intento 1 (21,9 MB a 182 kB/s desde el espejo de Ubuntu) frente a 10-37 s en el resto; en el run 44488277802 (10:57) el paso de instalación tardó 257 s. Es ruido de red del espejo, no de las suites (que varían ≤ 30 s). La caché del navegador (`E2E-CI-c1`, PR #788) ahorra 15-25 s pero no toca esta parte. Mejora posible, sin hacer: lanzar la instalación de paquetes en segundo plano mientras corren `npm ci` y la pila.

### 9.7 `E2E-CI-d2`: datos de menores y familias (2026-11-20; en curso, rama `feature/E2E-CI-d2-menores-y-familias`)

**Decisión del usuario (2026-11-20)**: `OPEN-E2ECI-25` = **B** (el tutor `e2e-tutor` en navegador solo para el muro de condiciones, excepción nominal a `OPEN-E2ECI-13`), porque el muro (`RN-FAM-UNIT-61`) solo alcanza a tutores con vínculo activo, no al personal.

**Tramos** (≤ 3, secuenciales; criterios en `§9.4`, filas en `e2e-real-ci-matriz.md §4.2` y `§4.4`):

1. **Tramo 1 (hecho)**: `CA-E2ECI-27` (HTTP del tutor, T-02 a T-06) y `CA-E2ECI-28` (revocar y levantar desde la ficha de familia, F-03). T-01 y la parte de alumnos de T-06 ya estaban cubiertas por `alum-students-real` (`CA-ALUM-012`, `CA-ALUM-014a`) y no se repiten. **T-07 (muro por HTTP) no entra en este tramo** (`CA-E2ECI-27` no lo incluye; va con `CA-E2ECI-31`, tramo 3), porque publicar `condiciones_uso` haría que el tutor recibiera `403 terms-acceptance-required` fuera de la lista exenta en el resto de specs de `alum`.
2. **Tramo 2 (hecho)**: `CA-E2ECI-29` (F-04 y F-05) y `CA-E2ECI-30` (L-03 y L-04). El resto de filas de `-d2` (F-01, F-06, F-07, L-07, T-07) queda para el tramo 3.
3. **Tramo 3 (hecho en local)**: `CA-E2ECI-31` (F-06 y T-07), `CA-E2ECI-31b` (F-01) y `CA-E2ECI-31c` (L-07). **F-07** (consulta de aceptaciones por HTTP) no tiene criterio `CA-E2ECI` en `§9.4` y no entró en este tramo (alcance cerrado a `-31`, `-31b` y `-31c`): queda como hueco de la matriz a decidir por la sesión.

**Evidencias por criterio**

- **CA-E2ECI-27** · cerrado en local. `apps/web/e2e/alum-tutor-http-real.spec.ts` (5 tests en `alum-http`, unidades, alumnos, vínculos, matrículas y autorizados propios, sin tocar `e2e-real-tenant.php`): T-03 (familia, tutores, vínculos solo los suyos; vínculo revocado `404` en detalle y ausente del listado; el filtro `status=revocado` es `403` sin `revocacion_tutor.leer`), T-04 (consentimiento de imagen: lista, textos, decisión `portal` propia `201`, `404` al ajeno y al alumno del vínculo revocado), T-05 (autorizaciones y lista de recogida), T-02 (matrículas), T-06 (`403` en 14 escrituras de familia, tutores, vínculos, revocación y autorizados).
- **CA-E2ECI-28** · cerrado en local. `apps/web/e2e/alum-guardian-revocation-real.spec.ts` (1 test en `alum-browser`): el administrador revoca en pantalla (diálogo de confirmación), la ficha muestra «Revocado» con el motivo, el tutor recibe `404` por HTTP; levanta la revocación y vuelve a `200`.
- **CA-E2ECI-18 (roturas deliberadas, local)**: T-03, T-05, T-02, T-06 y `CA-E2ECI-28` fallan al romper la función; T-04 falla al incluir vínculos revocados en el alcance. Nota: la ocultación de vínculos revocados tiene **tres capas redundantes** (alcance del tutor, estado en el resolutor y `hideRevokedWithoutPermission`); quitar una sola no hace fallar T-03, hay que quitar las tres. Detalle en el informe del tramo.
- Recuentos de `e2e-real-esperados.json`: `alum-http` 16 a 21 y `alum-browser` 39 a 40.
- **CA-E2ECI-29** · cerrado en local. `apps/web/e2e/alum-image-consent-real.spec.ts` (3 tests en `alum-browser`, en serie): (F-05) el administrador crea y publica en pantalla (`/configuracion/condiciones-de-uso`) un texto de **consentimiento de imagen web** (NO `condiciones_uso`, que dejaría al tutor bajo el muro; esa pantalla gestiona los tres tipos) y el tutor lo lee por HTTP (`image-consent-texts`); (F-04) secretaría registra una decisión `presencial` en nombre del tutor por HTTP (la SPA no registra decisiones, `api/consent.ts`) y el tutor ve el estado `autorizo` y el histórico con canal `presencial`; secretaría ve el consentimiento en `/familias/consentimientos`, exporta con el botón «Exportar» (`202`, cola `default`, la procesa `worker`) y el CSV se descarga de Garage por la URL firmada con `downloadExportCsv` (cabecera del esquema cerrado, `web=autorizo`, `redes_sociales=pendiente`). Sin `127.0.0.1` ni `:8000` nuevos. Alumno y vínculo propios; tutor de `tutorGuardian`. Robustez: «Nueva versión» sale dos veces con la tabla vacía (cabecera y estado vacío), se usa `.first()`.
- **CA-E2ECI-30** · cerrado en local. `apps/web/e2e/alum-student-detail-real.spec.ts` (4 tests en `alum-browser`, en serie; fixture nuevo `fixtures/syntheticPng.ts`): secretaría corrige el nombre en la ficha; sube, sustituye (el almacén sirve el PNG nuevo, distinguido por anchura 64 a 96) y quita la fotografía; da de baja desde la ficha y restaura desde el listado con «Incluir bajas» (la restauración **no está en la ficha**, está en el listado); cada paso con lectura HTTP. Un docente con ámbito `grupo` ve la ficha de su alumno sin «Corregir datos», «Dar de baja», «Sustituir» ni «Quitar» y la API responde `403` a `PATCH`, `DELETE` y `PUT photo`. No duplica el HTTP de `alum-students-real` ni de `alum-photo-real`.
- **CA-E2ECI-18 (roturas deliberadas del tramo 2, local; cada una revertida)**: `TermsVersionService::publish` deja el estado en borrador, falla F-05 (no aparece «Versión publicada.»); `ImageConsentService` guarda `channel='portal'`, falla F-04 decisión (`Expected: "presencial" Received: "portal"`); `GenerateImageConsentsExport` escribe `pendiente` en la columna de estado (worker reiniciado), falla el CSV (`Expected: "autorizo" Received: "pendiente"`); `StudentDetailView.save` no llama a `updateStudent`, falla la edición (`toContainText "Alumna"`, recibido «Alumno Ficha…»); `deleteStudentPhoto` anulado, falla «quitar» (no vuelve «El alumno no tiene fotografía.»); sustitución de foto anulada, falla (`Expected: 96 Received: 64`); `restoreStudent` anulado y `deleteStudent` anulado, fallan baja/restauración (`GET students/… 404` esperando 200; `200` esperando `404`); `canUpdate` sin comprobar `alumno.actualizar`, falla la ocultación de acciones del docente; ruta `PATCH /students` con `alumno.leer`, falla el `403` (`Received: 200`).
- Recuentos de `e2e-real-esperados.json`: `alum-browser` 40 a 47 (`alum-http` sigue en 21).
- **Tiempo local del guion `alum` tras el tramo 2** (una sola ejecución): 3 min 51 s (Playwright 204 s, 21/21 + 47/47 en verde); tras el tramo 1, 3 min 34 s (181 s): +17 s (+23 s de Playwright). Acumulado de `-d2` hasta ahora: +67 s sobre los 2 min 44 s de partida.
- **CA-E2ECI-31** · cerrado en local. `apps/web/e2e/alum-terms-wall-real.spec.ts` (1 test en `alum-browser`, `OPEN-E2ECI-25` = B): el administrador publica `condiciones_uso` por API; antes de aceptar, el tutor `e2e-tutor` (con un vínculo activo propio del spec, `tutorGuardian`) recibe `403` con `terms-acceptance-required` en `GET /students/{id}` y `GET /me/terms` (exenta) devuelve `required: true` (T-07); inicia sesión **en el navegador** por la pantalla de acceso, llega a `/condiciones-de-uso` (muro `bare`, sin navegación), no puede salir a `/`, acepta y continúa; después su API responde `200` y `required: false` (F-06). Antes de publicar se comprueba como contraste que el tutor trabaja con normalidad. Único test de navegador con el tutor.
- **CA-E2ECI-31b** · cerrado en local. `apps/web/e2e/alum-family-unit-real.spec.ts` (1 test en `alum-browser`): secretaría, en `/familias`, crea la unidad, añade como tutora a una persona usuaria **nueva** (creada por API sin roles ni invitación; por `usuario.leer` se elige por el buscador o, si no, por su identificador: el spec acepta ambas formas), añade como alumno a una persona alumno sintética (creada por API **sin unidad**, patrón `createStudent`; por el identificador de su persona, no hay buscador) y crea el vínculo; la ficha muestra tutor, alumno y vínculo «Activo/Madre»; lecturas HTTP de `guardians`, `family-units/{id}/students` y `guardian-links`; repetir el vínculo muestra en el formulario «Ya existe un vínculo entre ese tutor y ese alumno.» (junto al campo) y el servidor no crea otro. **Elegibilidad del tutor (la duda NV)**: `GuardianService::create` solo exige un usuario vivo `pendiente` o `activo` que no sea ya tutor; no pide rol ni faceta, así que `e2e-real-tenant.php` no se toca.
- **CA-E2ECI-31c** · cerrado en local. `apps/web/e2e/alum-enrollment-detail-real.spec.ts` (3 tests en serie en `alum-browser`; solo pantalla, el HTTP ya lo cubren `CA-ALUM-024` y `CA-ALUM-031` en `alum-enrollments-real`): secretaría da de baja una matrícula activa desde la ficha (diálogo con fecha y motivo «Traslado»), la ficha la muestra «Baja» sin acciones y `GET /enrollments/{id}` da `status=baja`, `withdrawn_on` y `withdrawal_reason`; con una segunda matrícula posterior (tras la baja se admite otra del mismo curso), la anula desde la ficha (confirmación), la ficha la quita, `GET /enrollments/{id}` da `404` y la de baja se conserva; un docente con `matricula.leer` de ámbito `grupo` ve la matrícula de su alumno sin «Dar de baja la matrícula», «Anular matrícula» ni «Matricular» y la API le responde `403` a la baja y a la anulación.
- **CA-E2ECI-18 (roturas deliberadas del tramo 3, local; cada una revertida, `git status` limpio salvo lo propio)**: `TermsAcceptanceWall::blocks` devuelve `false`, falla CA-31 (`Expected: 403 Received: 200`); `EnrollmentService::withdraw` guarda `status='activa'`, falla la baja de CA-31c (la fila no pasa a «Baja», `element(s) not found`); `EnrollmentService::annul` sin `delete()`, falla la anulación (`toHaveCount(0)`, `Received: 1`); `FamilyUnitStudentService::add` sin `save()`, falla F-01 (la celda del alumno no aparece, `element(s) not found`); `LinkFormDialog` sin pintar el error de `guardian`, falla F-01 (`toContainText "Ya existe un vínculo entre ese tutor y ese alumno."`, `element(s) not found`); `canWithdraw` sin comprobar `matricula.actualizar` en `StudentDetailView`, falla la ocultación del docente (`toHaveCount(0)` de «Dar de baja la matrícula», `Received: 1`). **Hallazgo de la rotura**: quitar solo la comprobación de «vínculo duplicado» de `GuardianLinkService::create` **no** hace fallar F-01, porque el índice único de la base de datos (`QueryException` `23505`, mismo mensaje `already_exists`) la respalda; es una defensa redundante deliberada, no un hueco de la prueba (el test comprueba el mensaje en el formulario, y esa rotura sí se detectó en `LinkFormDialog`).
- **Orden y estado compartido (muro)**: publicar `condiciones_uso` somete a `e2e-tutor` al muro **para el resto de la ejecución** del guion (el centro se crea una vez por ejecución). Los demás specs que usan al tutor son `alum-enrollments-real` y `alum-tutor-http-real` (`alum-http`, otra invocación que corre antes) y `alum-guardian-revocation-real` y `alum-image-consent-real` (`alum-browser`); todos ordenan alfabéticamente **antes** de `alum-terms-wall-real`, y tras él solo hay `core-authorization-real` y `data-table-real`, que no usan al tutor ni dependen de que no haya condiciones publicadas (comprobado con `grep` de `tutorEmail`, `tutorUser`, `e2e-tutor`, `condiciones_uso`, `me/terms`). `fam-unit-terms-wall.spec.ts` es de datos simulados y `fam-unit`/`prl`/`core` usan otros centros. Un spec nuevo que use al tutor debe ordenar antes de `alum-terms-wall-real`. **Consecuencia**: `alum-terms-wall-real` no es idempotente dentro del mismo centro (una segunda ejecución con el tutor ya aceptado ve `200` donde espera `403`): vale con `retries: 0` y centro nuevo por ejecución; **no** debe lanzarse dos veces con filtros posicionales (con `npm run test:e2e:real:alum -- <fichero>` los argumentos se **suman** a la lista de cada invocación del guion y el spec corre en las dos: así falló mi primer intento).
- **Cierre de las revisiones (`security-reviewer`)**: **M-1** (la traza de Playwright del test del muro puede contener la contraseña del tutor, `staff_password`, aleatoria por ejecución y de un centro sintético destruido al terminar, y la cookie): se mantiene, cubierto por la decisión del usuario del 2026-11-18 sobre trazas (issue #779). **B-1** corregido: el `forbidden()` de T-06 comprueba además que el cuerpo del `403` no sea `terms-acceptance-required`. **B-2** (`404` sin comprobar el cuerpo) y **B-3** (acoplamiento por orden alfabético del spec del muro) quedan en un issue que abre la sesión.
- Recuentos de `e2e-real-esperados.json`: `alum-browser` 47 a 52 (`alum-http` sigue en 21). Specs registrados en `apps/web/scripts/e2e-real-alum.sh`.
- **Tiempo local del guion `alum` tras el tramo 3** (una sola ejecución): 4 min 54 s (Playwright 264 s, 21/21 + 52/52 en verde); tras el tramo 2, 3 min 51 s (204 s): +63 s (+60 s de Playwright). **Acumulado de `-d2`: +130 s** sobre los 2 min 44 s de partida (el reloj del CI se mide en las tres ejecuciones de `CA-E2ECI-20`, pendientes).
- **Cierre de los 3 tramos y criterios comunes (local)**: `CA-E2ECI-17` (cada flujo de `-d2` tiene test real con ID en el título, registrado y contado: cumplido salvo F-07, sin criterio propio), `-18` (roturas por test, arriba y en los tramos 1 y 2: cumplido), `-21` (datos sintéticos, sin relajar guardas, sin `127.0.0.1` ni `:8000` nuevos, `e2e-real-tenant.php` intacto: cumplido). **Pendientes del CI**: `CA-E2ECI-19` (agregador en verde con 21 + 52 y 0 saltados) y `CA-E2ECI-20` (tres ejecuciones con mediana y máximo y comprobación del disparador de `D3`); no se ha abierto PR.
- **Lección de orden y estado compartido**: Playwright ejecuta los ficheros de una invocación por **orden alfabético**, no por el de la línea de órdenes del guion; y el tutor `e2e-tutor` solo puede ser tutor una vez en el centro (`fam_unit.guardian.already_exists`). `alum-students-real` lo da de alta, así que el spec del tutor se llama `alum-tutor-http-real` (ordena después) y ambos specs nuevos reutilizan su ficha con `tutorGuardian` (`fixtures/alumReal.ts`). Con el primer nombre (`alum-guardian-http-real`) rompía a `alum-students-real` en la suite completa.
- **Tiempo local del guion `alum`** (`npm run test:e2e:real:alum`, de punta a punta): antes 2 min 44 s (Playwright 140 s); después 3 min 34 s (Playwright 181 s): +50 s (+41 s de Playwright; medición local de una sola ejecución).

### 9.2 Bloqueos y dependencias

- `E2E-CI-b` tiene como prerrequisitos `0.10e-b` (nivel 1 de *runtime*, `OPEN-E2ECI-03` = A) y el ADR de `OPEN-E2ECI-11` = A, escrito por `architect`, que enmienda `ADR-080 §5.3, punto 4`; el plan de `0.10e-b` lo refleja.
- El *issue* aparte por `retries: 2` del job simulado es #749 (`OPEN-E2ECI-07`).
- La intermitencia de `acad-timetable` (#741) hará rojo el job de `E2E-CI-c` de forma aleatoria; con D5 eso no se tapa con reintentos: #741 debe estar resuelto antes de cerrar `-c`, o el usuario decide excluir esa suite del job mientras siga abierto (`OPEN-E2ECI-15`).

### 9.4 Criterios de `E2E-CI-d1`..`-d5` (de `-d0`, aprobados por el usuario el 2026-11-18)

Filas, suites y flujos exigidos: [`e2e-real-ci-matriz.md`](e2e-real-ci-matriz.md) `§4.2`, `§4.4` y `§4.5`. Holgura de tiempo de partida (`§9.3`, tabla oficial de tres ejecuciones 7:39, 8:22 y 8:15): ≈ **1:45** sobre la mediana (8:15) y ≈ **1:38** sobre el máximo (8:22) hasta el disparador de `D3` (~10 min); las cifras de coste por entrega de la matriz (`§4.2`-`§4.3`) son suposiciones sin medir. Clasificación: **críticas las cinco** (`OPEN-E2ECI-21` = A) (cada una registra *specs* en un guion de `apps/web/scripts` y `-d3` toca `e2e-real-tenant.php`).

**Comunes a cada entrega `-dN`**

- **CA-E2ECI-17** · *Dado* el diff de la entrega, *entonces* cada flujo de su lista (`matriz §4.4`) tiene al menos un test real nuevo cuyo título referencia el ID del requisito o criterio que cubre, registrado en el guion de su suite y contado en `apps/web/e2e/e2e-real-esperados.json`. *Prueba*: revisión del diff por la sesión y `doc-reviewer`.
- **CA-E2ECI-18** · *Dado* cada test nuevo, *cuando* se rompe a propósito la función que cubre (en local o en un PR desechable en borrador que nunca se mezcla), *entonces* ese test falla; el informe recoge por test qué se rompió y el resultado literal (`CLAUDE.md §3`). *Prueba*: el informe de la entrega.
- **CA-E2ECI-19** · *Dado* el PR de la entrega, *cuando* corre el CI, *entonces* el agregador «E2E real (API)» queda en verde con los recuentos nuevos y **0 saltados** en todas las suites (regla de `§3` punto 5). *Prueba*: ejecución del CI enlazada.
- **CA-E2ECI-20** · *Dado* la entrega mezclada, *entonces* el informe recoge tres ejecuciones completas en CI con mediana y máximo del job y por suite, y dice si se cumple el disparador de `D3`; si se cumple, la siguiente entrega `-d` no empieza sin la propuesta de reparto de `§5.3` presentada al usuario. *Prueba*: los tres resúmenes enlazados.
- **CA-E2ECI-21** · *Dado* el diff, *entonces* solo hay datos sintéticos (`REQ-SEED-005`), ninguna guarda de los guiones ni de `e2e-real-tenant.php` se relaja, ningún *spec* añade `127.0.0.1` ni `:8000` nuevos (`§8`) y el guion local se comporta como antes salvo por los tests añadidos (`CA-E2ECI-07`). *Prueba*: revisión del diff y ejecución local con `espera-e2e`.

**`-d1` · autorización en pantalla**

- **CA-E2ECI-22** · *Dado* cada par rol × ruta de `matriz §4.5` en el que el rol **no tiene el permiso** de la ruta, *cuando* ese usuario navega a ella, *entonces* ve el estado «sin acceso» sin que la vista se monte (`CA-CORE-095`), la entrada no aparece en su menú (`CA-CORE-100`) y la petición HTTP real al *endpoint* principal de la ruta responde `403` (`CA-CORE-070`). *Prueba*: test real.
- **CA-E2ECI-22b** · *Dado* cada par de `matriz §4.5` en el que el rol **tiene el permiso con un ámbito restringido** (p. ej. `docente` con `matricula.leer` de ámbito `grupo` en la lista de clase de un grupo en el que no imparte), *cuando* navega a la ruta, *entonces* la vista se monta, la entrada sí aparece en el menú y no se muestra ninguna fila ajena (estado vacío); por HTTP real el listado del recurso ajeno devuelve una lista vacía y su detalle `404`, nunca `403` (`ADR-038 §6.4`, `RN-ALUM-60`). *Prueba*: test real.
- **CA-E2ECI-23** · *Dado* un usuario en una pantalla que sí puede abrir, *cuando* le falta el permiso de una acción (docente en la estructura académica, secretaría en la puerta, conserje en una alerta), *entonces* la acción no se ofrece (`RN-CORE-61`) y su *endpoint* responde `403` por HTTP real. *Prueba*: test real.
- **CA-E2ECI-24** · *Dado* el administrador, *cuando* da de alta un usuario **con un rol elegido en el propio formulario de alta** (`core-user-new`, `role_ids`), *entonces* la ficha del usuario muestra ese rol (asignar desde la ficha y ver los permisos efectivos ya lo cubre `core-roles`, `CA-PERM-133`, y no se repite); *cuando* el usuario tiene dos roles y uno deniega lo que el otro permite, *entonces* los permisos efectivos lo muestran denegado (`RPERM-007`); y *cuando* intenta desactivarse o borrarse a sí mismo, *entonces* la pantalla lo impide (los botones «Desactivar» y «Dar de baja» y los roles de su propia ficha están deshabilitados, `CA-CORE-211`), el servidor responde `409` (`core.validation.cannot_modify_self`) a `POST /users/{id}/status` y `DELETE /users/{id}` por HTTP real (`INV-010`, sin forzar el DOM) y el estado no cambia (`RN-CORE-06`). *Enmienda 2026-11-18 (decisión del usuario, `E2E-CI-d1`; la cláusula «a sí mismo» se reformuló en `d1-b` para no probar un camino que ningún usuario puede seguir)*: «dejar el centro sin administrador» (`RN-CORE-07`) sale del E2E real porque el guion solo crea un administrador y el `409` de «a sí mismo» se evalúa antes; se cubre con Pest (issue #785). *Prueba*: test real.
- **CA-E2ECI-25** · *Dado* una reserva creada por un docente, *cuando* otro docente intenta cancelarla, *entonces* el servidor responde `404` (HTTP real) y la pantalla lo muestra sin romperse, y el autor sí puede (`CA-ACAD-139`). *Enmienda 2026-11-18 (decisión del usuario, `E2E-CI-d1`)*: se retira «acción no disponible en pantalla»: `BookingsView.vue` ofrece «Cancelar» a todo docente porque `/me.permissions` no distingue el ámbito `propios` y decide el servidor (`INV-010`); ocultar el botón sería un cambio de producto fuera de `d1`. *Prueba*: test real.
- **CA-E2ECI-26** · *Dado* una invitación pendiente, *cuando* el administrador la reenvía y la revoca desde la pantalla, *entonces* el listado refleja cada cambio; y secretaría la ve sin esas acciones. *Prueba*: test real.
- **CA-E2ECI-26b** · *Dado* un usuario con dos roles asignados, *cuando* el administrador le retira uno desde la región «Roles» de su ficha y guarda, *entonces* la ficha muestra solo el rol restante y sus permisos efectivos dejan de incluir lo que solo concedía el rol retirado (C-04; añadir un rol ya lo cubre `CA-PERM-133`). *Prueba*: test real.

**`-d2` · datos de menores y familias**

- **CA-E2ECI-27** · *Dado* el tutor del modo `alum` vinculado a un alumno, *cuando* consulta por HTTP real familia, vínculos, consentimientos, autorizados y matrículas, *entonces* recibe solo lo de sus alumnos, `404` en lo ajeno, nunca vínculos revocados (`RN-FAM-UNIT-36`) y `403` en las escrituras que no tiene. *Prueba*: test HTTP real.
- **CA-E2ECI-28** · *Dado* un vínculo activo, *cuando* el administrador lo revoca desde la ficha de familia, *entonces* la ficha lo muestra revocado y la petición siguiente del tutor sobre ese alumno responde `404`; al levantar la revocación, vuelve a `200`. *Prueba*: test real (navegador + HTTP).
- **CA-E2ECI-29** · *Dado* un texto de consentimiento publicado por el administrador desde la pantalla de textos legales, *cuando* secretaría registra una decisión presencial y exporta el informe, *entonces* el tutor ve la decisión por HTTP y el CSV se descarga de Garage por la URL firmada. *Prueba*: test real.
- **CA-E2ECI-30** · *Dado* secretaría en la ficha de un alumno, *cuando* edita, da de baja, restaura y cambia la fotografía, *entonces* cada cambio se refleja en la ficha y en una lectura HTTP real; y un docente con ámbito `grupo` no ve esas acciones. *Prueba*: test real.
- **CA-E2ECI-31** · *Dado* una versión de condiciones publicada, *cuando* un usuario sujeto al muro inicia sesión, *entonces* llega al muro, acepta y continúa, y antes de aceptar su API responde `403 terms-acceptance-required`; quién es ese usuario en navegador lo decide `OPEN-E2ECI-25`. *Prueba*: test real.
- **CA-E2ECI-31b** · *Dado* secretaría en `/familias`, *cuando* da de alta una unidad familiar, un tutor y un vínculo tutor ↔ alumno con una de las personas alumno sintéticas del guion, *entonces* la ficha de la unidad muestra al alumno, al tutor y el vínculo activo, y una lectura HTTP real lo confirma; un error de validación del servidor (p. ej. vínculo duplicado) se muestra en el formulario (F-01). *Prueba*: test real.
- **CA-E2ECI-31c** · *Dado* un alumno con matrícula activa, *cuando* secretaría la da de baja (`withdrawal`) y, sobre otra, la anula desde la ficha, *entonces* la ficha refleja cada estado y una lectura HTTP real de `GET /enrollments/{id}` lo confirma; un docente con ámbito `grupo` no ve esas acciones (L-07; si `alum-enrollments-real` ya lo cubre por HTTP, solo la parte de pantalla). *Prueba*: test real.

**`-d3` · autenticación** (condicionada a `OPEN-E2ECI-16`, `-17` y `-18`)

- **CA-E2ECI-32** · *Dado* la pantalla de acceso, *cuando* se envía una credencial errónea, *entonces* se muestra el mensaje genérico y no hay sesión (`GET /me` ⇒ `401`). *Prueba*: test real.
- **CA-E2ECI-33** · *Dado* un usuario obligado a MFA sin exención (dato nuevo del guion, `OPEN-E2ECI-17`), *cuando* inicia sesión, *entonces* llega al muro de MFA; da de alta el TOTP, cierra sesión y vuelve a entrar con segundo factor; y el administrador le restablece el MFA desde `/administracion/mfa`. *Prueba*: test real.
- **CA-E2ECI-34** · *Dado* el administrador en `/administracion/mfa`, *cuando* concede una exención a otro usuario y la revoca, *entonces* el listado lo refleja; e intentar concedérsela a sí mismo se rechaza (`CA-AUTH-161`). *Prueba*: test real.
- **CA-E2ECI-35** · *Dado* el mecanismo de `OPEN-E2ECI-16`, *cuando* se invita a un usuario, se pide recuperar la contraseña o se bloquea una cuenta, *entonces* el test obtiene el enlace, completa canje, restablecimiento o desbloqueo y la persona inicia sesión. *Prueba*: test real.
- **CA-E2ECI-36** · *Dado* el acceso con Google y por SSO institucional, *entonces* el informe declara el E2E real no viable, nombra los tests Pest que lo sustituyen y enlaza el *issue* abierto (`CLAUDE.md §3`); la pantalla de SSO se cubre solo en listado y validación del formulario. *Prueba*: informe e *issue*.

**`-d4` · escrituras restantes de personal**

- **CA-E2ECI-37** · *Dado* cada flujo de escritura de `-d4` (`matriz §4.2`), *cuando* se completa por la interfaz, *entonces* al menos un error de validación del servidor se muestra en el formulario y el resultado se comprueba con una lectura HTTP real. *Prueba*: test real.
- **CA-E2ECI-38** · *Dado* una escritura hecha por el propio *spec*, *cuando* el administrador filtra la auditoría, *entonces* la encuentra, y su exportación se descarga de Garage. *Prueba*: test real.

**`-d5` · lecturas y transversal**

- **CA-E2ECI-39** · *Dado* una pantalla por módulo con *shell*, *cuando* se cambia el idioma a `en`, `de` y `fr`, *entonces* el menú y el título de la pantalla cambian de idioma y se mantienen tras recargar (mecanismo de persistencia: **NV**, lo confirma el `explorer` de `-d5`). *Prueba*: test real.
- **CA-E2ECI-40** · *Dado* las mismas pantallas, *cuando* se conmuta el tema claro/oscuro, *entonces* el cambio se aplica sin errores en la consola del navegador (la comparación visual es `E2E-CI-e`). *Prueba*: test real.
- **CA-E2ECI-41** · *Dado* una anchura de 320 px, *cuando* se abre una pantalla de cada módulo que **aún no** tiene comprobación de 320 px en E2E real (`core`, `curso`, `fam-unit`, `prl`; `alum` ya la tiene en `alum-screens-real`, `CA-ALUM-071`, y `acad` en `acad-scales`/`acad-periods`, `CA-ACAD-101`), *entonces* el menú es accesible y la página no tiene desplazamiento horizontal. *Prueba*: test real.
- **CA-E2ECI-42** · *Dado* los listados de usuarios y de cursos, *cuando* se filtran y ordenan, *entonces* el estado queda en la URL (sin `q` ni `cursor`, `RN-CORE-54`) y las filas coinciden con las de la API real para los mismos parámetros. *Prueba*: test real.
- **CA-E2ECI-42b** · *Dado* `direccion` (modo `alum`), *cuando* abre `/administracion/modulos`, *entonces* ve los módulos contratados del centro de pruebas en solo lectura, sin acciones de configuración (C-12, `RN-CORE-87`); y *cuando* `secretaria` abre `/administracion/cursos` y la ficha de un curso, *entonces* los ve sin acciones de alta, edición, activación ni cierre (U-01). Cada lectura se contrasta con la API real. *Prueba*: test real.
- **CA-E2ECI-42c** · *Dado* una pantalla con selector de curso académico (`meta.academicYearScoped`), *cuando* el usuario elige otro curso en el selector del *shell*, *entonces* la URL pasa a llevar `?academic_year=<id>`, el contenido es el de ese curso según la API real y, al recargar, el selector conserva la elección (X-06, `RN-ACAD-40`). *Prueba*: test real.

---

## 10. Preguntas abiertas (resueltas: `OPEN-E2ECI-01` a `-15` el 2026-11-17; `-16` a `-25`, de `-d0`, el 2026-11-18 en `§10.1`) *`OPEN-E2ECI-25` se reabrió y quedó en B el 2026-11-20 (véase `§10.1` y `§9.7`).*

**Resolución (2026-11-17, el usuario): las 15 primeras, en la opción A (recomendada).** Se conserva el texto de las alternativas como registro de lo que se descartó. Consecuencias: especificación en un solo fichero (`01`); pila con `compose.yaml` en el *runner* (`02`); `0.10e-b` es **prerrequisito** de `E2E-CI-b` (`03`); ADR propio escrito por `architect` antes de `E2E-CI-b` (`11`); #741 resuelto antes de cerrar `E2E-CI-c` (`15`); política visual de `RN-UI-07` sin cambios (`10`); *issue* aparte para `retries: 2` del job simulado (`07`).

En cada una, la opción recomendada va primero y marcada.

- **OPEN-E2ECI-01 · Dónde vive esta especificación.** `REQ-INF` **no es infraestructura**: es «Primer ciclo de Educación Infantil 0-3» (**V**, `docs/modulos/REQ-INF/funcional.md`), así que no encaja. El ámbito de escritura de `spec-writer` es `docs/modulos/REQ-XXX/`, de ahí la carpeta provisional `REQ-E2E-CI` (mismo precedente que el anexo de `ADR-090`, que se dejó en el ámbito de su autor y se señaló para que la sesión lo moviera).
  - **(A, recomendada)** La sesión la mueve a `docs/proceso/e2e-real-ci.md` (un solo fichero: funcional + operación) y borra la carpeta; precedente: `docs/design-system.md` y `docs/i18n.md`, que no son `REQ-*` porque no son *bounded context*.
  - (B) Se queda en `docs/modulos/REQ-E2E-CI/` con sus cinco ficheros (tres de ellos «no aplica»).
- **OPEN-E2ECI-02 · Cómo corre la pila en el *runner*.**
  - **(A, recomendada)** `compose.yaml` con perfil `full` e imagen `dev` de la API construida en el job: es la pila que usan los guiones en local (la API sirve con `artisan serve`, no con FrankenPHP: fe de erratas del 2026-11-18); menos divergencia.
  - (B) Servicios de GitHub Actions (PostgreSQL, Redis) + `setup-php` + `php artisan serve`, como `ci-api.yml`: más rápido de arrancar, pero `artisan serve` es de un hilo (#146, interbloqueo con el navegador), no es FrankenPHP y obliga a reescribir los guiones (no hay contenedor `plataforma-api` donde hacer `exec`).
  - (C) Imagen de producción de GHCR: descartable, `tests/Support` no viaja en ella y `build-images.yml` no publica imágenes de PR.
- **OPEN-E2ECI-03 · Qué *runtime* de contenedores en el *runner*.** Los guiones escriben `podman` a mano y `CONTAINER_RUNTIME` no existía (`0.10e-b`, implementado el 2026-11-17 con alias de respaldo `CONTAINER_CLI`).
  - **(A, recomendada)** Hacer antes `0.10e-b` (un tramo, `ADR-080 §6.1`) y usar `CONTAINER_RUNTIME=docker` en CI, igual que `storage-conformance` usa `CONTAINER_CLI=docker`: Docker en los *runners* de GitHub está documentado y ya se usa en este repositorio.
  - (B) Usar Podman en el *runner* sin tocar los guiones: depende de que la imagen `ubuntu-26.04` traiga Podman y `podman compose` (**NV**).
- **OPEN-E2ECI-04 · Fichero del flujo.** **(A, recomendada)** `ci-e2e-real.yml` nuevo. (B) Jobs dentro de `ci-web.yml`.
- **OPEN-E2ECI-05 · Qué rutas disparan el job.**
  - **(A, recomendada)** `apps/web/**`, `apps/api/**`, `compose.yaml`, `infra/containers/**`, `infra/garage/**`, `infra/lib/**` (añadido el 2026-11-17 tras `0.10e-b`: `infra/lib/container-runtime.sh`, del que dependen todos los guiones), el `.env.example` de la raíz (añadido el 2026-11-17, `ADR-091`; se retira si la implementación no lo usa como base del `.env` del *runner*) y el propio flujo: un cambio en la imagen, en Garage o en la definición de la pila rompe el E2E igual que uno de código.
  - (B) Solo `apps/web/**` y `apps/api/**` (lo que dice el encargo).
- **OPEN-E2ECI-06 · `push` a `develop`.** **(A, recomendada)** Sí, sin condición por diff: detecta roturas por combinación de dos PR verdes por separado. (B) Solo en PR.
- **OPEN-E2ECI-07 · `retries: 2` del job simulado de hoy.** Contradice D5 (reintento silencioso), pero es ajeno a este encargo.
  - **(A, recomendada)** Abrir *issue* aparte (Media) y tratarlo fuera de estas entregas; el job real usa `--retries=0` desde el primer día. **Hecho**: #749 resuelto en #751 (2026-11-17).
  - (B) Corregirlo dentro de `E2E-CI-b` (cambia `playwright.config.ts` y puede volver rojo el job simulado si hay intermitentes ocultos).
- **OPEN-E2ECI-08 · Cuándo pasa a requerido el agregador.** **(A, recomendada)** Al cerrar `E2E-CI-b`, con `CA-E2ECI-01` a `-05` cumplidos; lo marca el usuario en GitHub. (B) Informativo hasta cerrar `E2E-CI-c`.
- **OPEN-E2ECI-09 · Presupuesto de tiempo.** **(A, recomendada)** `timeout-minutes: 30` y objetivo ≤ 10 min de reloj, que es el disparador de D3. (B) Otro valor que fije el usuario.
- **OPEN-E2ECI-10 · Pruebas visuales.**
  - **(A, recomendada)** Mantener la política de `RN-UI-07`: un job (o paso) genera capturas por pantalla × tema como **artefacto de CI de corta retención**, fuera de Git y sin comparación automática; las revisa el usuario cuando un PR cambia algo visual. Sin dependencia nueva y sin cambiar ninguna norma.
  - (B) `toHaveScreenshot` de Playwright (ya instalado, no es dependencia nueva) con referencias **en Git**: detecta regresiones sin revisión humana, pero contradice «fuera de Git» y «sin herramienta de comparación de píxeles» de `RN-UI-07`; exige ADR que lo sustituya y una regla para regenerar referencias (fuentes y renderizado del *runner* fijados).
  - (C) `toHaveScreenshot` con referencias guardadas como artefacto de la última ejecución en `develop` (fuera de Git): compara sin ensuciar el repositorio, pero la caducidad de artefactos y la primera referencia dan rojos sin cambio real; también exige ADR.
- **OPEN-E2ECI-11 · ADR propio.** **(A, recomendada)** Sí, antes de `E2E-CI-b`, escrito por `architect`: cambia la topología del CI y los *checks* requeridos, igual que `ADR-089`. Título y alcance propuestos en el informe. (B) Sin ADR: basta esta especificación.
- **OPEN-E2ECI-12 · Prioridad de la matriz.** **(A, recomendada)** Por riesgo: datos de menores y decisiones de permisos primero, luego escrituras, luego lecturas. (B) Por módulo, en el orden del plan.
- **OPEN-E2ECI-13 · Familia y estudiante.** **(A, recomendada)** Hasta `1.22`, solo pruebas HTTP reales de la API que ya les sirve (las del `tutor_legal` del modo `alum`), y ninguna fila de pantalla. (B) Esperar a `1.22` para cubrir esos roles. *Excepción nominal por `OPEN-E2ECI-25` = B (2026-11-20): el tutor en navegador solo para el muro de condiciones; véase `§9.7`.*
- **OPEN-E2ECI-14 · Artefactos en fallo.** **(A, recomendada)** Subir informe y trazas de Playwright solo si el job falla, con retención de 7 días: contienen solo datos sintéticos y las contraseñas aleatorias de un centro que ya no existe. En repositorio público los artefactos los puede descargar cualquier usuario con sesión en GitHub (**NV** el alcance exacto). (B) No subir trazas.
- **OPEN-E2ECI-15 · `acad-timetable` (#741) en el job.** **(A, recomendada)** Resolver #741 antes de cerrar `E2E-CI-c`; el job incluye la suite desde el principio. (B) Excluirla del job, con decisión explícita del usuario y anotada, hasta cerrar #741.

### 10.1 Preguntas de `E2E-CI-d0` (RESUELTAS el 2026-11-18)

**Resolución (2026-11-18, el usuario, «Acepto todo»): las diez, `OPEN-E2ECI-16` a `-25`, en la opción A (recomendada); `OPEN-E2ECI-25` se reabrió y quedó en B el 2026-11-20 (véase su texto y `§9.7`).** Incluye la **aceptación expresa del riesgo de `-16`**: con el *mailer* `log`, el enlace con el token en claro de invitación, restablecimiento o desbloqueo queda en un registro de la aplicación (ver la corrección de la premisa más abajo: es `laravel.log`, no el registro del contenedor); se acepta porque el centro y las credenciales son sintéticos y efímeros (mismo criterio que #779). **Corrección de la premisa (2026-11-18, briefing de `E2E-CI-d1`, verificado en el código)**: con `LOG_STACK=single` el correo va al fichero `storage/logs/laravel.log` (compartido por `api` y `worker`), **no** a `docker logs`, y el artefacto de fallo no sube `apps/api/storage`; el enlace solo podría quedar en las trazas de Playwright si el *spec* navega a él (NV). El riesgo aceptado es, por tanto, menor que el descrito; no hay ningún *helper* que lea `laravel.log` hoy (`d3`). Se conserva el texto de las alternativas como registro de lo descartado. Contexto en [`e2e-real-ci-matriz.md`](e2e-real-ci-matriz.md).

- **OPEN-E2ECI-16 · Cómo obtiene el E2E los enlaces de correo** (invitación, recuperación, desbloqueo; A-02..A-04). El token solo existe en claro en el correo (`REQ-AUTH/permisos.md §1`: se persiste como *hash*), así que no puede leerse de la base.
  - **(A, recomendada)** Configurar en el *runner* y en local un *mailer* que deje el correo legible sin servicio nuevo (p. ej. el *driver* `log` de Laravel) y que el *spec* lo lea del registro del contenedor de la API. Sin dependencia nueva; **NV** cuál es hoy el *mailer* de la pila de desarrollo. **Riesgo**: el enlace con el token en claro (invitación, restablecimiento, desbloqueo) queda en el registro del contenedor, y el job vuelca las últimas líneas de los registros de `api`, `worker` y Garage cuando falla (`§12.1.2` paso 9) y sube trazas como artefacto (`OPEN-E2ECI-14`, #779). Son tokens de un solo uso de un centro sintético que muere con el *runner*, el mismo tipo de riesgo aceptado en #779 para contraseñas y cookies; **Aceptado expresamente por el usuario el 2026-11-18** al elegir A (sin enmascarar ni excluir esas líneas del volcado).
  - (B) Añadir un capturador de correo (p. ej. Mailpit) a `compose.yaml`: servicio e imagen nuevos, sujetos a `CLAUDE.md §1` y a revisión de `contenedores-y-red`.
  - (C) No cubrir esos flujos en E2E real: se declaran no viables con su sustituto Pest y un *issue*.
- **OPEN-E2ECI-17 · MFA en E2E real** (A-09..A-12).
  - **(A, recomendada)** `e2e-real-tenant.php setup` crea un usuario sintético más con un rol obligado a MFA y **sin** exención (entrega crítica), y el *fixture* calcula el código TOTP con `node:crypto` (HMAC-SHA1, RFC 6238), sin dependencia nueva.
  - (B) Lo mismo con una librería TOTP de npm (dependencia nueva, `CLAUDE.md §1`).
  - (C) No cubrirlo en E2E real (sustituto Pest e *issue*).
- **OPEN-E2ECI-18 · Google y SSO institucional** (A-05, A-06, A-13).
  - **(A, recomendada)** Declararlos no viables en E2E real (no hay proveedor de identidad en el *runner* y las guardas `RN-AUTH-113` rechazan destinos internos, como debe ser); cubrir la pantalla de SSO en listado y validación; sustituto Pest e *issue*.
  - (B) Un proveedor OIDC simulado en contenedor: servicio nuevo y, probablemente, una excepción a las guardas de descubrimiento solo en pruebas, que no se recomienda.
- **OPEN-E2ECI-19 · Roles predefinidos que el guion no crea** (`administrativo`, `tutor_grupo`, `orientador`; `direccion` en modo `door`).
  - **(A, recomendada)** No añadirlos: sus concesiones en estos módulos son subconjuntos o copias de las de roles que sí existen (`administrativo` ⊂ `secretaria` en lectura; `tutor_grupo` = `docente` en `alum` y `acad`; `orientador` solo lee `acad`), y la siembra la prueban los tests Pest de cada módulo. P-02 se cubre con el administrador.
  - (B) Añadir `administrativo`, `tutor_grupo` y `direccion` (en `door`) al guion (entrega crítica; más inicios de sesión, `OPEN-E2ECI-23`).
- **OPEN-E2ECI-20 · Accesibilidad (`A`) en E2E real.**
  - **(A, recomendada)** Aserciones explícitas (roles, nombres, foco, `alt`), el precedente ya aprobado de `OPEN-FAM-UNIT-62` A, sin `@axe-core/playwright`.
  - (B) Añadir `@axe-core/playwright` (dependencia nueva, `CLAUDE.md §1`).
- **OPEN-E2ECI-21 · Clasificación de las entregas `-d`.** `§9` dice «ordinario; crítico si toca guiones…», pero registrar un *spec* nuevo en su guion (`CLAUDE.md §3`) ya toca `apps/web/scripts`.
  - **(A, recomendada)** Clasificar `-d1`..`-d5` como **críticas** (en caso de duda, crítico).
  - (B) Enmendar `§9`: añadir un *spec* a la lista de un guion y subir su recuento en `e2e-real-esperados.json`, sin otro cambio en el guion, es ordinario.
- **OPEN-E2ECI-22 · Dónde viven los tests de autenticación de `-d3`.**
  - **(A, recomendada)** En la suite `core` (modo por defecto), ampliando su `setup` con el usuario de `OPEN-E2ECI-17`.
  - (B) Suite nueva `auth` con su propio centro: aísla los bloqueos y el límite de accesos, a costa de ≈ 10-15 s más de `setup`/`teardown` y arranque.
- **OPEN-E2ECI-23 · Límite de 10 accesos por minuto y centro.** `-d1` y `-d3` multiplican los inicios de sesión.
  - **(A, recomendada)** Un inicio de sesión por actor y reutilización del estado de sesión (patrón `loginState` de `fixtures/alumReal.ts`); si no basta, repartir actores entre los dos centros que ya crean `prl` y `alum`. Nunca subir el límite en CI.
  - (B) Aceptar las esperas por `429` y medirlas (`§5.1`).
- **OPEN-E2ECI-24 · Orden de las entregas P1.**
  - **(A, recomendada)** `-d1` (permisos) → `-d2` (menores) → `-d3` (autenticación, condicionada a `-16`..`-18`).
  - (B) `-d2` antes que `-d1` (los datos de menores son el riesgo legal mayor).
- **OPEN-E2ECI-25 · El muro de condiciones en navegador** (F-06). `OPEN-E2ECI-13` = A excluye filas de pantalla para la familia, pero `§1.4` dice que el muro lo alcanza cualquier usuario autenticado, también una familia.
  - **(A, recomendada)** Probar el muro en navegador con un usuario de personal sujeto a él (si las condiciones aplican al personal: **NV**, lo confirma el `explorer` de `-d2` en `REQ-FAM-UNIT/funcional.md §19`) y, para el tutor, solo por HTTP (`T-07`).
  - (B) Permitir al tutor en navegador solo para el muro, como excepción nominal a `OPEN-E2ECI-13`.
  - **Resuelta en B por el usuario el 2026-11-20 (`E2E-CI-d2`)**: el muro (`RN-FAM-UNIT-61`) solo alcanza a tutores con vínculo activo, no al personal del centro; por eso la opción A (probarlo con un usuario de personal) no es viable. Se permite al tutor `e2e-tutor` en navegador **solo** para el muro (`CA-E2ECI-31`, tramo 3 de `-d2`), como excepción nominal a `OPEN-E2ECI-13`; el resto de la familia sigue por HTTP.

---

## 11. Lo no verificado (resumen)

Podman en `ubuntu-26.04`; qué tests de `core-roles` y `curso-academic-years` son reales; roles de cada flujo; cobertura simulada por contenido (solo por nombre de fichero); interacción cubierta por cada *spec* salvo `core-scroll-real`; si la imagen `dev` de la API instala dependencias al arrancar; tiempo de construcción de la imagen en CI; alcance de descarga de artefactos en repositorio público. Ningún fichero se ha ejecutado.

**Añadido por `-d0` (2026-11-18)**: `§11.1` del documento de requisitos no se leyó (los roles salen de `REQ-CORE/permisos.md §4.1`); cuerpos de los *specs* reales salvo los fragmentos citados en la matriz (el resto, solo por cabecera); contenido de `alum-enrollments-real` (filas T-02 y L-07); qué usuarios quedan sujetos al muro de condiciones (F-06); *mailer* de la pila de desarrollo (`OPEN-E2ECI-16`); 320 px en `acad-periods` (citado por `doc-reviewer`, no leído); contraseña por defecto de la fábrica de usuarios; todas las estimaciones de tiempo de `matriz §4.2`-`§4.3`, que son suposiciones sin medir.

---

## 12. Operación (flujo y guiones propuestos)

Estado: **APROBADO** (2026-11-17; `OPEN-E2ECI-02` a `-06` y `-09` resueltas en A). Detalle operativo de `§3`. Nada implementado: los pasos se describen, no se escriben.

### 12.1. Flujo `ci-e2e-real.yml`

| Aspecto | Diseño | Fuente |
|---------|-----------|--------|
| Disparadores | `pull_request` a `develop`, `staging`, `main`; `push` a `develop` | Igual que `ci-web.yml`; `push` según `OPEN-E2ECI-06` |
| Permisos del `GITHUB_TOKEN` | `contents: read`, nada más | Issue #401, patrón de los flujos actuales |
| *Runner* | `ubuntu-26.04` fijado (#609) | Patrón de los flujos actuales |
| `concurrency` | Grupo por rama con `cancel-in-progress` en PR [DERIVADA: dos ejecuciones del mismo PR no aportan nada y gastan *runner*] | — |
| Jobs | `cambios` → `e2e-real` (matriz de dos jobs desde `E2E-CI-c2`, `§9.6`) → agregador «E2E real (API)» | `§3` |
| Seguridad | Nunca `pull_request_target`; los PR de forks corren sin secretos (todos son efímeros y se generan en el job); las contraseñas aleatorias que imprime `e2e-real-tenant.php setup` se enmascaran con `::add-mask::` al leerlas | `CLAUDE.md §8`, `ADR-030` |
| Acciones de terceros | Ninguna. `actions/upload-artifact` es de primera parte pero **nueva** en este repositorio: se fija por SHA según `0.10g-b` | `CLAUDE.md §1` |

#### 12.1.1 Job `cambios`

- `actions/checkout` con historia suficiente para comparar con la base del PR.
- `git diff --name-only <base>...HEAD` y comparación con la lista de rutas de `OPEN-E2ECI-05`; en `push` a `develop`, `ejecutar=true` siempre.
- Salida `ejecutar`. Si el cálculo falla, el job falla (y con él el agregador): nunca «no se pudo calcular, así que no corre».

#### 12.1.2 Job `e2e-real`

Pasos en orden (con `OPEN-E2ECI-02` = A y `-03` = A):

1. *Checkout*; Node 26 con caché de `apps/web/package-lock.json`; `npm ci`; `npx playwright install --with-deps chromium`.
2. Secretos efímeros (`POSTGRES_PASSWORD`, `TENANCY_*_PASSWORD`, `GARAGE_*`, `APP_KEY`) generados con `openssl rand`, enmascarados con `::add-mask::` y escritos en `.env` raíz y `apps/api/.env` del *runner* (copia de `apps/api/.env.example` más los valores de CI). Mismo patrón que `storage-conformance` en `ci-api.yml`. Mueren con el *runner*.
3. `127.0.0.1 demo.plataforma.test demo2.plataforma.test` en `/etc/hosts` (con `sudo`).
4. Red externa `plataforma-net` creada (compose la exige: `external: true`).
5. `compose up -d --wait postgres redis`; **`composer install` en el host** (`apps/api`; el bind mount tapa el código de la imagen y la imagen `dev` no instala dependencias, `ADR-091 §10`); `compose up -d --build postgres redis api worker garage` (sin `web`, ver `§3` punto 3) con `FILESYSTEM_DISK=s3` (**V** en Actions el 2026-11-18, run 44284498445; sin caché de capas de la imagen `dev`: su tiempo está en el resumen del job).
6. Migraciones con el rol propietario (`--database=pgsql_owner`) y `platform:sync-registry`; espera a `/api/health`; `infra/garage/init.sh`. Antes, crear `apps/web/.env` (copia de `.env.example`): `docker compose` valida todo `compose.yaml` aunque `web` no se levante.
7. `E2E_ONLY=core npm run test:e2e:real -- --retries=0 --trace=retain-on-failure --reporter=json,html` en `E2E-CI-b`; en `E2E-CI-c`, las tres órdenes (`test:e2e:real`, `:prl`, `:alum`) en serie, cada una con esas opciones, y el job falla si falla cualquiera (sin cortar las siguientes, para medir todas); desde `E2E-CI-c2` (`§9.6`) ese reparto es por grupo de la matriz: `alum` ejecuta una orden (`:alum`) y `resto` dos (`test:e2e:real` y `:prl`); después, la comprobación de tests ejecutados de `§3` punto 5.
8. Siempre: tabla de tiempos en `$GITHUB_STEP_SUMMARY` y `e2e-tiempos.json` como artefacto (`§5.2`).
9. Solo si falla: informe y trazas de Playwright como artefacto, 7 días (`OPEN-E2ECI-14`); últimas líneas de los registros de `api`, `worker` y Garage en el registro del job.

`timeout-minutes: 30` (`OPEN-E2ECI-09`).

#### 12.1.3 Agregador «E2E real (API)»

`needs: [cambios, e2e-real]` (con `E2E-CI-c2`, `e2e-real` es una matriz y su resultado agrega a todos los hijos, `§9.6`), `if: always()`, sin *checkout* (se ejecuta en la raíz, como el agregador de `ci-api.yml`). Regla en `§3`, punto 2. Es el único nombre que se añade a los *required checks*, a mano, por el usuario (`SYSADMIN.md §4`); los nombres de `cambios` y `e2e-real` no se marcan.

### 12.2. Cambios previstos en los guiones (sin escribirlos aquí)

| Guion | Cambio | Entrega |
|-------|--------|---------|
| `e2e-real-api.sh`, `e2e-real-prl.sh`, `e2e-real-alum.sh`, `lib/e2e-garage.sh`, `lib/e2e-purge.sh`, `e2e/curso-rollover.spec.ts` | Invocar el *runtime* por `CONTAINER_RUNTIME` (`0.10e-b`) en vez de `podman` escrito a mano | `0.10e-b` (prerrequisito de `E2E-CI-b`, `OPEN-E2ECI-03` = A); `curso-rollover.spec.ts` en `E2E-CI-c` (**corrección del 2026-11-18**: ya usaba `resolveContainerRuntime()`, no tenía `podman` escrito a mano; `-c` no necesitó tocarlo) |
| Los tres guiones | Línea `E2E_TIEMPO …` por fase y suite | `E2E-CI-b` (las tres, para que `-c` solo active suites) |
| Ninguno | **No se relaja** ninguna guarda (`APP_ENV`, marca del centro, `E2E_ALLOW_DESTRUCTIVE`) | — |

`lib/e2e-garage.sh` e `infra/garage/init.sh` usan hoy `CONTAINER_CLI` y `ADR-080` define `CONTAINER_RUNTIME`: se unificó en `CONTAINER_RUNTIME` dentro de `0.10e-b` (2026-11-17; alcance de `ADR-080 §5.1`, que incluye también `landing-shots.sh` e `init.sh`), con `CONTAINER_CLI` como alias de respaldo por decisión del usuario; su retirada la sigue #755.

La duplicación de `purge_tenant_rows` en `e2e-real-prl.sh` y `e2e-real-alum.sh` frente a `lib/e2e-purge.sh` existe hoy; unificarla no entra salvo que el cambio de *runtime* obligue a tocar las tres copias (en ese caso, se dice en el informe; no se refactoriza en silencio).

### 12.3. Variables de entorno

| Variable | Dónde | Valor en CI |
|----------|-------|-------------|
| `CI` | GitHub | `true` (desde #751 `playwright.config.ts` ya no reintenta; `--retries=0` se mantiene explícito en el job como defensa; #749) |
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
