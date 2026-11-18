# ADR-091 · E2E real en el CI: job condicionado por diff, agregador y pila efímera en el runner

**Estado**: **ACEPTADA** (2026-11-18, el usuario). `OPEN-091-01` = A (check requerido en `develop`, `staging` y `main`, al cerrar `E2E-CI-b`), `OPEN-091-02` = A (`ADR-080` anotado en el PR de aceptación, `§7.2`) y `OPEN-091-03` = A (decisiones **[DERIVADA]** (a)-(f) confirmadas). Estado anterior: `PROPUESTA` (2026-11-17, `architect`).
**Fecha**: 2026-11-17
**Origen**: issue [#737](https://github.com/pirexia/plataforma-educativa/issues/737) y especificación **APROBADA** `docs/proceso/e2e-real-ci.md` (`OPEN-E2ECI-11` = A: «ADR propio, antes de `E2E-CI-b`, escrito por `architect`»). Este ADR **no reabre** ninguna de las 15 `OPEN-E2ECI` ni las decisiones D1-D5 de su `§0`: las registra como decisión arquitectónica y fija lo que la especificación dejaba a un ADR. Lo que este ADR añade sin estar aprobado en la especificación va marcado **[DERIVADA]** con su motivo (convención de la especificación) y se somete a `OPEN-091-03`.
**Sustituye en parte a** (la decisión rige **al aceptarse**: desde entonces el proyecto se compromete a ejecutar `docker` en el CI; el estado «verificado» solo empieza cuando el job exista y esté verde, `§4.3`; la anotación en `ADR-080` sigue `OPEN-091-02`; hasta la aceptación, `ADR-080` rige sin cambios):
- `ADR-080 §5.3`, **punto 4**, solo la frase «Con `docker` el soporte es **de mejor esfuerzo y sin verificar**: no hay trabajo de CI que lo ejecute, y así se dice donde se documente», y solo para el **camino del E2E real que el CI ejecutará**: la suite `core` desde `E2E-CI-b`; `:prl`, `:alum` y `curso-rollover` desde `E2E-CI-c` (`§4.3`). El resto del punto 4 («Podman sigue siendo el runtime por defecto y el único verificado en desarrollo»; `ADR-030` no cambia; el descarte de Docker Desktop sigue en pie) queda **intacto**.
- `ADR-080 §10.2`, `OPEN-STG-11`, solo su segunda mitad («sin trabajo de CI para `docker`»). El nivel 1 de runtime (`§5.3` puntos 1, 2, 3, 5 y 6) y `0.10e-b` no cambian.
- `ADR-080 §8`, fila «El camino con `docker` se rompe sin que nadie lo vea», en la parte que cubre el job (`§4.3`).
`ADR-080` **no se modifica** (inmutable, `CLAUDE.md §6.3`/`§11`). Se le añade **solo** una línea de cabecera, como hizo `ADR-060` con `ADR-089` (commit `9c8cc53`), en el momento que fije `OPEN-091-02`: texto propuesto en `§7.2`.
**Extiende con una excepción declarada**: `ADR-089 §2`, punto 3 («un job saltado o cancelado cuenta como fallo»): aquí un `skipped` es verde **solo** tras la decisión explícita `ejecutar=false` del job `cambios` (`§2.4`, `§5`). `ADR-089` **no se modifica** y su regla sigue rigiendo `Tests (Pest)`.
**Concreta sin sustituir**: `ADR-076 §2.2`/`§2.3` (Garage en CI; lo verde en CI no cubre la columna «No», `§4.4`), `ADR-030` (solo datos sintéticos; Podman en desarrollo), `ADR-080 §6.2` (`CS-03` y `CS-17`, `§6`).
**Se apoya en**: `INV-015`, `RNF-MANT-004`, `RNF-MANT-006`, `CLAUDE.md §1` (dependencias), `§3` (los E2E deben existir y demostrar que detectan el fallo), `§8` y `§10`; `SYSADMIN.md §4` (por qué se quitaron los filtros `paths:`).
**Afecta a** (no los toca este ADR; los cambia `E2E-CI-b`): `.github/workflows/ci-e2e-real.yml` (nuevo), los guiones de `apps/web/scripts/`, `SYSADMIN.md §4`, fila «Tests» de `CLAUDE.md §1`, protección de ramas en GitHub (a mano, el usuario).

Convención: **V** = comprobado leyendo el fichero en `develop` (`08ecc49`); **NV** = no verificado (nada se ha ejecutado en esta sesión).

---

## 1 · Contexto

1. El E2E real (Playwright contra la API real, `npm run test:e2e:real[:prl|:alum]`) **no corre en el CI** (**V**, `CLAUDE.md §1`, `ci-web.yml`): el job `e2e` de `ci-web.yml` solo ejecuta los *specs* simulados, y los reales se saltan solos sin `E2E_REAL_API=1`. La subida a `vue-router` 5 (PR #729) pasó los 8 E2E reales en verde sin ejercitar el *scroll*; solo un *spec* escrito a propósito lo encontró (#737). Hoy, que el E2E real se ejecute depende de que la sesión se acuerde de lanzarlo en local.
2. Los guiones de E2E real están acoplados a la pila de desarrollo: `podman exec` a `plataforma-api` y `plataforma-postgres`, `podman compose` para Garage (**V**, especificación `§1.2`). `0.10e-b` (`ADR-080 §5`) introduce `CONTAINER_RUNTIME` con `podman` por defecto y `docker` admitido, pero `ADR-080 §5.3` punto 4 declara `docker` «de mejor esfuerzo y sin verificar» y `OPEN-STG-11` se resolvió «sin trabajo de CI para `docker`».
3. El CI ya tiene un patrón de agregador para *checks* requeridos (`ADR-089`) y un precedente doloroso: los filtros `paths:` se quitaron de `ci-api.yml` y `ci-web.yml` porque, combinados con *required checks*, **dejaban bloqueado para siempre** cualquier PR que no tocara esas rutas (**V**, `SYSADMIN.md §4`).
4. Llevar el E2E real al CI cambia la topología del CI y los *checks* requeridos de la protección de rama, igual que `ADR-089`: es estructural.

## 2 · Decisión

### 2.1 Pila efímera en el *runner* con `compose.yaml` (`OPEN-E2ECI-02` = A)

El job levanta **`compose.yaml` del repositorio**, nombrando **solo** los servicios `postgres redis api worker garage` (más `worker-long` si una suite lo necesita, `curso-rollover`; lo decide la implementación), con la imagen `dev` de la API **construida en el propio job**. **Nunca `web`**: no tiene perfil en `compose.yaml` (**V**), arrancaría con un `up` sin nombres y su `127.0.0.1:5173` chocaría con el `webServer` de Playwright, que es quien sirve la SPA (con `VITE_API_URL` del guion). Red externa `plataforma-net` creada en el job; `.env` raíz y `apps/api/.env` generados con secretos efímeros (`openssl rand`, `::add-mask::`, patrón de `storage-conformance`); migraciones y `platform:sync-registry`; `infra/garage/init.sh`; nombres `demo.plataforma.test` y `demo2.plataforma.test` en `/etc/hosts`.

Datos: **solo** el centro sintético que crea el propio guion (`e2e-real-tenant.php setup`) con contraseñas aleatorias por ejecución (D4, `ADR-030`, `ADR-080 §3.6`). Las guardas (`APP_ENV`, marca del centro, `E2E_ALLOW_DESTRUCTIVE=1`) **no se relajan** porque la base sea desechable.

**Seguridad del job** (especificación `§12.1`, sin cambios de fondo):
- Nunca `pull_request_target`; los PR de *forks* corren **sin secretos** del repositorio: todos los que el job usa son efímeros y se generan en él.
- Las contraseñas aleatorias que imprime `e2e-real-tenant.php setup` se enmascaran con `::add-mask::` al leerlas, igual que los secretos generados.
- La purga con el superusuario de PostgreSQL (`session_replication_role = replica`, `lib/e2e-purge.sh` y sus copias) conserva sus guardas: `E2E_ALLOW_DESTRUCTIVE=1`, contenedores con prefijo `plataforma-`, `APP_ENV` `local|testing` y la marca del centro sintético; en CI solo alcanza la base efímera del *runner*.
- Los artefactos de fallo (`§2.5`) contienen el informe y las trazas de Playwright; **no** incluyen `.env` ni registros completos de contenedores: las últimas líneas de `api`, `worker` y Garage van al registro del job, con los secretos ya enmascarados.

### 2.2 *Runtime* `docker` en el CI (`OPEN-E2ECI-03` = A)

El job exporta `CONTAINER_RUNTIME=docker` y usa los guiones de `0.10e-b` sin bifurcaciones propias de CI. `0.10e-b` es **prerrequisito** de `E2E-CI-b`. El valor por defecto de los guiones sigue siendo `podman` y en local nada cambia (`CA-E2ECI-07`).

El flujo fija **solo** `CONTAINER_RUNTIME`, nunca el alias de respaldo `CONTAINER_CLI`: `infra/lib/container-runtime.sh` (`0.10e-b`, **V**) devuelve 2 y aborta si ambas están definidas con valores distintos. El alias es una decisión del usuario del 2026-11-17 (*expand/contract*, `CLAUDE.md §9`) que amplía el contrato de una sola variable de `ADR-080 §5.3`, punto 1; su retirada la sigue #755. El job `storage-conformance` de `ci-api.yml` sigue con `CONTAINER_CLI=docker` (**V**) hasta que una entrega posterior retire el alias; este ADR no lo toca.

### 2.3 Flujo propio y disparo por diff sin filtro `paths:` (`OPEN-E2ECI-04`, `-05`, `-06` = A)

1. Fichero **`.github/workflows/ci-e2e-real.yml`**, separado de `ci-web.yml` (cuyo job `e2e` simulado sigue igual).
2. Disparadores: `pull_request` a `develop`, `staging` y `main`; `push` a `develop`. **Sin** `paths:` en `on:` (motivo: `§1.3`) y **nunca** `pull_request_target`. `permissions: contents: read`. *Runner* `ubuntu-26.04` fijado (#609). `concurrency` por rama con cancelación en PR.
3. Job **`cambios`** («E2E real · detección de cambios»): *checkout* con **historia suficiente para comparar con la base del PR** (especificación `§12.1.1`) y `git diff --name-only <base>...HEAD`, **sin acción de terceros**. `ejecutar=true` si alguna ruta casa con:
   - las de `OPEN-E2ECI-05` = A: `apps/web/**`, `apps/api/**`, `compose.yaml`, `infra/containers/**`, `infra/garage/**`, `.github/workflows/ci-e2e-real.yml`;
   - **`infra/lib/**`** [DERIVADA: `0.10e-b` crea `infra/lib/container-runtime.sh`, del que dependen todos los guiones del E2E real; un cambio ahí rompe el job igual que uno de `infra/garage/`. Hueco de la lista aprobada, que es anterior a ese fichero];
   - **`.env.example`** de la raíz [DERIVADA: `compose.yaml` exige `POSTGRES_*`, `TENANCY_*` y `GARAGE_*` del `.env` raíz (**V**); si el job lo genera a partir de `.env.example`, un cambio en él cambia la pila. Si la implementación no lo usa como base, se quita de la lista y se dice en el informe]. `apps/api/.env.example` ya está cubierto por `apps/api/**`.
   - **No** se incluye `infra/compose/compose.prodlike.yaml` ni `infra/quadlet/**`: el job no los usa (la pila es `compose.yaml`); incluirlos solo gastaría *runner*.
   Bordes: en `push` a `develop`, `ejecutar=true` siempre, sin calcular diff; en `pull_request` (también desde *forks*) la base es la rama destino del PR, y el cálculo no necesita secretos. Si el cálculo falla (base no alcanzable, historia insuficiente), el job falla: nunca «no se pudo calcular, así que no corre».
4. Job **`e2e-real`** («E2E real · \<suite\>»): `needs: cambios`, `if: needs.cambios.outputs.ejecutar == 'true'`. En `E2E-CI-b`, solo la suite `core`; en `E2E-CI-c`, todas (`test:e2e:real`, `:prl`, `:alum` en serie, sin cortar las siguientes al fallar una).

### 2.4 Agregador «E2E real (API)», único *check* requerido

Job agregador `needs: [cambios, e2e-real]`, `if: always()`, sin *checkout*. Regla:

- **Verde** si y solo si: (`cambios` = `success` **y** `ejecutar` = `false` **y** `e2e-real` = `skipped`) **o** (`cambios` = `success` **y** `ejecutar` = `true` **y** `e2e-real` = `success`). [DERIVADA: la especificación (`§3` punto 2) admite verde con solo `e2e-real` = `success`; exigir además `cambios` = `success` y `ejecutar` = `true` no cambia ningún caso alcanzable hoy (`e2e-real` depende de `cambios`), pero impide que una edición futura del `needs` o del `if` produzca un verde sin decisión registrada.]
- **Rojo** en cualquier otro caso: `cambios` fallido o cancelado; `e2e-real` fallido o cancelado; `e2e-real` saltado con `ejecutar = true`.
- Es el **único** nombre que se marca como *required check* (a mano, el usuario, `SYSADMIN.md §4`); `cambios` y `e2e-real` no se marcan. Cuándo y en qué ramas: `OPEN-091-01`.

**Un verde exige tests ejecutados**: dentro de `e2e-real`, tras Playwright, un paso falla el job si el informe JSON tiene algún test `skipped` o menos tests pasados que los esperados por suite, fijados en un fichero versionado [DERIVADA en la forma: la especificación dice «un fichero versionado» sin fijar formato ni ubicación; este ADR exige solo que esté en Git y que cambiarlo aparezca en el *diff* del PR, y deja ruta y formato a la implementación]. Sin él, un job con todos los *specs* saltados (sin `E2E_REAL_API`, o con `--pass-with-no-tests` de `e2e-real-alum.sh`) saldría verde: el agujero que este ADR cierra.

### 2.5 Sin reintentos; trazas solo en fallo (D5; `OPEN-E2ECI-14` = A)

- **Sin reintentos**: `playwright.config.ts` ya tiene `retries: 0` y `trace: 'retain-on-failure'` (**V**, #749, `08ecc49`; antes, `retries: 2` con `CI=true`). El job pasa además `--retries=0` explícito, para que un cambio futuro de la configuración no reintroduzca reintentos en silencio en el job requerido. Un fallo deja el job rojo; la sospecha de intermitencia abre *issue* (D5) y el test no se marca `skip` sin decisión del usuario.
- *Reporters* `json` (comprobación de `§2.4`) y `html`.
- Informe y trazas como artefacto **solo si el job falla**, retención **7 días**; `actions/upload-artifact` fijado por SHA (`0.10g-b`). Contienen solo datos sintéticos y contraseñas de un centro que ya no existe.

### 2.6 Presupuesto de tiempo y paralelización (D3; `OPEN-E2ECI-09` = A)

- `timeout-minutes: 30` como techo duro del job; objetivo de reloj **≤ 10 min**.
- Los guiones emiten `E2E_TIEMPO suite=… fase=… segundos=…`; el job lo vuelca a `$GITHUB_STEP_SUMMARY` y a `e2e-tiempos.json`.
- **Disparador de paralelización** (D3): mediana de reloj del job completo > ~10 min en 3 ejecuciones, o la espera bloquea otro trabajo. Entonces se propone un reparto por suites con esos datos y **no se paraleliza sin aprobación del usuario**.
- Una matriz `e2e-real` por suites que **conserve** el nombre del agregador y la regla de `§2.4` (con «todas las entradas de la matriz» donde dice `e2e-real`) **está cubierta por este ADR**, igual que `ADR-089` admite cambiar N en su matriz: `E2E-CI-c2` no necesita ADR nuevo. Sí lo necesita si cambia el nombre del *check* requerido, la regla de verde o el disparo por diff. [DERIVADA: la especificación (`§9`, fila `E2E-CI-c2`) prevé «enmienda del ADR de `-b` si cambia su decisión»; este ADR precisa de antemano qué cambio de topología no altera su decisión, para no escribir un ADR que solo repita este.]

## 3 · Motivo

- **Pila con `compose.yaml` y no servicios de Actions + `artisan serve`**: es la misma pila que usan los guiones en local (FrankenPHP, Garage de la versión y *digest* de `compose.yaml`, que es lo que `ADR-076 §2.2` exige al CI); evita reescribir los guiones (no habría contenedor donde hacer `exec`) y el interbloqueo de `artisan serve` con el navegador (#146). Menos divergencia = menos verdes que no significan nada.
- **`docker` y no Podman en el *runner***: Docker está documentado en los *runners* de GitHub y el repositorio ya lo usa en CI (`storage-conformance`, `CONTAINER_CLI=docker`, **V**); Podman y `podman compose` en `ubuntu-26.04` están **NV**. Y deja de ser cierto que el camino `docker` «no lo ejecuta nadie», que era la razón del «mejor esfuerzo» de `ADR-080 §5.3`.
- **Detección por job y no por `paths:`**: un *required check* que no se dispara deja el PR bloqueado para siempre (**V**, `SYSADMIN.md §4`); un PR solo de documentación debe quedar verde sin pagar ~10 min de *runner* (`CA-E2ECI-02`). Hacerlo con `git diff` evita una acción de terceros (`CLAUDE.md §1`).
- **Agregador con `skipped` admitido solo tras decisión positiva**: conserva el principio de `ADR-089 §2`, punto 3 (un *skipped* no es verde por sí mismo) y lo precisa: solo es verde si `cambios` terminó en `success` y dijo `ejecutar=false`.
- **Sin reintentos**: un reintento que pasa convierte un fallo real en verde (D5). Las trazas solo en fallo bastan para diagnosticar y no publican nada en los verdes.
- **Reversible**: quitar el *check* requerido y borrar `ci-e2e-real.yml`; los guiones siguen funcionando en local con sus valores por defecto. Sin datos ni esquema.

## 4 · Consecuencias

### 4.1 Buenas

- Ningún PR que toque código se mezcla sin el E2E real en verde (desde `E2E-CI-b` con `core`, desde `E2E-CI-c` con todas las suites), y cada `push` a `develop` lo repite (detecta roturas por combinación de dos PR verdes).
- `CA-E2ECI-03` y `-12` dejan probado que el job detecta un fallo y que un job vacío no es verde (`CLAUDE.md §3`).

### 4.2 Malas o con coste

- **Reloj**: cada PR de código espera el job (objetivo ≤ 10 min; el tiempo de construir la imagen `dev` en CI es **NV**). Minutos gratuitos en repositorio público (`ADR-089 §1`).
- **CI con `docker`, desarrollo con Podman**: un fallo que solo aparezca con uno de los dos es posible (montajes `:Z` en `compose.yaml`, que Docker sin SELinux debería ignorar: **NV**; propietario de ficheros creados en `./apps/api` montado, `root` con Docker sin espacio de nombres de usuario frente al usuario con Podman sin privilegios: **NV**; activación de servicios con perfil al nombrarlos en `up`: **NV** en este repositorio). Regla [DERIVADA: la especificación no dice qué hacer cuando los dos *runtimes* discrepan; sin regla, la salida fácil sería saltar el test en CI]: **ambos caminos deben estar verdes**; un rojo solo en CI es un defecto del camino `docker` (*issue*, Media como mínimo, `CLAUDE.md §5`), nunca motivo para `skip` ni para relajar el job. El local con Podman lo vigila `CA-E2ECI-07` y la norma de `CLAUDE.md §3`.
- **Intermitentes**: sin reintentos, #741 (`acad-timetable`) hará rojo el job de `E2E-CI-c` hasta resolverse (`OPEN-E2ECI-15` = A: resuelto antes de cerrar `-c`).
- **Artefactos públicos**: en repositorio público los artefactos los puede descargar cualquiera con sesión en GitHub (alcance exacto **NV**). Aceptado por `OPEN-E2ECI-14` porque solo contienen datos sintéticos.

### 4.3 Qué cambia para `0.10e-b` y qué sigue sin verificar

Desde que el agregador esté **verde en `develop`** (no desde la aceptación de este ADR) [DERIVADA: la aceptación decide; solo una ejecución verde verifica, `CLAUDE.md §0`], con `docker`:

| Camino | Estado |
|--------|--------|
| `e2e-real-api.sh`, `lib/e2e-garage.sh`, `lib/e2e-purge.sh`, `infra/lib/container-runtime.sh`, tal como los usa el job, con la suite `core` | **Verificado por el CI** desde `E2E-CI-b` |
| `infra/garage/init.sh` | Ya lo ejecuta hoy `storage-conformance` con `docker` (`ci-api.yml`, paso «Arrancar e inicializar Garage», **V**), aunque con `docker run` y no con `compose`; el E2E real lo verifica además dentro de la pila de `compose.yaml` desde `E2E-CI-b` |
| `e2e-real-prl.sh`, `e2e-real-alum.sh`, el resto de suites y `e2e/curso-rollover.spec.ts` | **Verificado por el CI** desde `E2E-CI-c`; hasta entonces, «mejor esfuerzo» como dice `ADR-080` |
| `landing-shots.sh`, `infra/containers/postgres/bases-test-paralelo.sh` | **Sin verificar** con `docker` (el job no los ejecuta): sigue `ADR-080 §5.3` punto 4 |
| Quadlet con `docker`, cualquier despliegue de producción o staging con `docker`, `compose.prodlike.yaml` con `docker` | **Sin verificar y sin soporte**: Quadlet sigue siendo la única referencia de producción (`ADR-080 §5.3` punto 3, intacto) |
| Desarrollo con `docker` en una máquina de colaborador | **Sin verificar**: Podman sigue siendo el único runtime verificado en desarrollo (`ADR-030`, intacto) |

`0.10e-b` **no cambia de alcance ni de clasificación** (ordinario): sigue siendo la variable y el contrato; el job de CI es de `E2E-CI-b`. Lo que cambia es el texto que `0.10e-b` escriba sobre `docker` en `SYSADMIN.md`/`CLAUDE.md §1`: «de mejor esfuerzo, salvo el camino del E2E real, que el CI ejecuta con `docker` (`ADR-091`)», y solo cuando `E2E-CI-b` esté mezclado y verde. [DERIVADA: orden de mezcla no previsto en la especificación.] Si `0.10e-b` se mezcla antes, escribe el texto actual de `ADR-080` y `E2E-CI-b` lo actualiza, **incluido** el comentario de cabecera de `infra/lib/container-runtime.sh` («Podman es el único runtime verificado; con `docker` el soporte es de mejor esfuerzo y sin verificar», **V** en la rama de `0.10e-b`), que quedará obsoleto para el camino del E2E real al mezclar `E2E-CI-b`.

### 4.4 Qué no cubre el E2E real en el CI

Un verde de este job **no** prueba (`CLAUDE.md §3`: el informe dice qué se probó y qué no):
- el S3 real del proveedor: Garage cubre solo la columna «Sí» de `ADR-076 §2.3`; la columna «No» (cifrado en servidor, versionado, Object Lock, TLS y comportamiento propio del proveedor) se verifica en staging (`0.10e-e`, `CS-14` a `CS-16`);
- el desarrollo con `docker` en la máquina de un colaborador, ni Quadlet ni ningún despliegue de staging o producción (`§4.3`);
- lo que solo existe en la imagen de producción, con `APP_ENV=production`, HTTPS y MFA obligatorio: es `CS-17`.

## 5 · Relación con `ADR-089`

Mismo patrón (jobs de trabajo + agregador `if: always()` como único requerido) y misma motivación de nombres estables. Diferencia deliberada: aquí `skipped` puede ser verde, **solo** con la decisión explícita de `cambios` (`§2.4`). `ADR-089` no se modifica: su regla sigue rigiendo su agregador `Tests (Pest)`, cuya matriz nunca se salta por diseño.

## 6 · Relación con `ADR-080 §6.2`

- **`CS-03`** («El resultado con `docker`, si alguien lo ejecuta, se anota»): a partir de `E2E-CI-b`, el resultado con `docker` lo anota el CI en cada ejecución; el informe de cierre de `0.10e` enlaza la ejecución en `develop` en vez de dejar «sin verificar» la parte `docker` del camino del E2E real. La parte Podman de `CS-03` no cambia.
- **`CS-17`** (E2E real contra staging): **no** lo decide este ADR. Se comparten los *specs*, su *fixture* y la regla de no reintentar; no se comparten el entorno, la preparación del centro ni el disparo (especificación `§8`). Ninguna entrega de `E2E-CI` escribe en los *specs* algo que solo funcione en el *runner* (`127.0.0.1` o `:8000` nuevos).

## 7 · Alternativas descartadas

### 7.1 Opciones

| Opción | Por qué no |
|--------|-----------|
| Servicios de Actions (PostgreSQL, Redis) + `setup-php` + `php artisan serve` (`OPEN-E2ECI-02` B) | `artisan serve` es de un hilo (interbloqueo con el navegador, #146), no es FrankenPHP y obliga a reescribir los guiones (no hay contenedor donde hacer `exec`) |
| Imagen de producción de GHCR (`OPEN-E2ECI-02` C) | `tests/Support` no viaja en ella; `build-images.yml` no publica imágenes de PR |
| Podman en el *runner* sin tocar los guiones (`OPEN-E2ECI-03` B) | Depende de Podman y `podman compose` en `ubuntu-26.04` (**NV**); mantendría el `podman` escrito a mano que `ADR-080 §5.3` punto 5 prohíbe hacia delante |
| Mantener `OPEN-STG-11` («sin CI para `docker`») y correr el CI con Podman | Igual que la anterior; además dejaría `docker` sin verificar justo cuando hay un job que lo verifica gratis |
| Jobs dentro de `ci-web.yml` (`OPEN-E2ECI-04` B) | Mezcla un job largo y con servicios propios con el *lint*/*build* rápido; complica el agregador y la cancelación |
| Filtro `paths:` en `on:` | *Required check* nunca disparado = PR bloqueado para siempre (**V**, `SYSADMIN.md §4`) |
| `dorny/paths-filter` u otra acción de detección | Dependencia de terceros para lo que hace `git diff` (`CLAUDE.md §1`) |
| Ejecutar siempre, sin detección | ~10 min de *runner* y de espera en cada PR de documentación, sin beneficio |
| Solo en PR, no en `push` a `develop` (`OPEN-E2ECI-06` B) | No detecta roturas por combinación de dos PR verdes por separado |
| Agregador informativo hasta `E2E-CI-c` (`OPEN-E2ECI-08` B) | Rechazada por el usuario; el piloto ya demuestra la detección |
| Reintentos (`retries: 2`) | Reintento silencioso (D5) |
| Paralelizar desde el principio | D3: solo con datos medidos y aprobación |

### 7.2 Anotación de `ADR-080` (propuesta, condicionada a `OPEN-091-02`)

Precedente: `ADR-060` recibió, en el PR de aceptación de `ADR-089` (commit `9c8cc53`), una única línea de cabecera «Sustituido en parte por…», sin tocar su cuerpo; `ADR-088` (`PROPUESTA`) solo se anuncia en el índice hasta aceptarse. El precedente resuelve **la forma** (una línea de cabecera; el cuerpo de `ADR-080` no se toca). **El momento** queda en `OPEN-091-02`, porque aquí, a diferencia de `ADR-089`, la decisión se acepta antes de que exista el job que la cumple: (A) en el PR de aceptación de este ADR; (B) al mezclar `E2E-CI-b`. En cualquiera de los dos, se añade a la cabecera de `ADR-080`, tras la línea «**Sustituye en parte a**» y su lista, esta línea:

> **Sustituido en parte por `ADR-091`** (`ACEPTADA`): de `§5.3` punto 4, solo «con `docker` el soporte es de mejor esfuerzo y sin verificar: no hay trabajo de CI que lo ejecute», y solo para el camino del E2E real que el CI ejecutará con `docker`: la suite `core` desde `E2E-CI-b`; `:prl`, `:alum` y `curso-rollover` desde `E2E-CI-c` (`ADR-091 §4.3`; cada parte cuenta como verificada solo desde su agregador verde en `develop`); de `OPEN-STG-11`, solo «sin trabajo de CI para `docker`». El resto de `§5` (Podman por defecto y único verificado en desarrollo, Quadlet única referencia de producción) sigue vigente.

Y, en el mismo PR que la línea de cabecera, en la fila de `ADR-080` del índice de `§18` se sustituye «`ADR-091` (`PROPUESTA`) propone sustituir en parte» por «sustituido en parte por `ADR-091`».

## 8 · Preguntas abiertas

- **OPEN-091-01 · En qué ramas, y desde cuándo, es requerido «E2E real (API)».** El cuándo ya lo fija `OPEN-E2ECI-08` = A (al cerrar `E2E-CI-b`); queda en qué ramas. Bloquea solo `CA-E2ECI-08`.
  - **(A, recomendada)** En las mismas tres ramas y del mismo modo que los demás *required checks* (`develop`, `staging`, `main`, `SYSADMIN.md §4`): un arreglo urgente por PR a `staging` (`ADR-080 §4.4`) no debe saltarse el E2E real. Comportamiento del *check* requerido frente a las promociones por avance rápido (`git push <sha>:refs/heads/staging`): **NV**; el commit promocionado ya trae el *check* de su `push` a `develop`, pero si GitHub lo exige también en el *push* a `staging` hay que comprobarlo en la primera promoción.
  - (B) Solo en `develop`: menos riesgo de bloquear una promoción, pero un arreglo urgente a `staging` entraría sin E2E real.
- **OPEN-091-02 · Momento de la anotación de `ADR-080`.**
  - **(A, recomendada)** En el PR de aceptación de este ADR (`§7.2`), aunque el job no exista aún: la línea está en futuro y acotada por entrega («ejecutará», «desde `E2E-CI-b`», «desde `E2E-CI-c`»), y `§4.3` fija que el «verificado» empieza con el agregador verde en `develop`. Quien lea `ADR-080` ve la sustitución desde el día en que rige. Mismo momento que en el precedente.
  - (B) Al mezclar `E2E-CI-b`: la cabecera de `ADR-080` solo cambia cuando hay algo ejecutado, a costa de que `ADR-091` quede aceptado sin reflejo en `ADR-080` durante días.
- **OPEN-091-03 · Confirmar las decisiones marcadas [DERIVADA].** Condiciona la implementación de `E2E-CI-b`. Son:
  - (a) Regla del agregador más estricta que la especificación (`§2.4`: exige `cambios` = `success` y `ejecutar` = `true` además de `e2e-real` = `success`) y número esperado de tests en un fichero versionado con ruta y formato libres (`§2.4`).
  - (b) `E2E-CI-c2` no necesita ADR nuevo si la matriz conserva nombre y regla del agregador y el disparo por diff (`§2.6`).
  - (c) «Ambos caminos verdes»: un rojo solo con `docker` es *issue* (Media como mínimo), nunca `skip` (`§4.2`).
  - (d) El «verificado» con `docker` empieza con el agregador verde en `develop`, no con la aceptación (`§4.3`).
  - (e) Si `0.10e-b` se mezcla antes que `E2E-CI-b`, escribe el texto actual de `ADR-080` y `E2E-CI-b` lo actualiza, incluido el comentario de `infra/lib/container-runtime.sh` (`§4.3`).
  - (f) Rutas añadidas al job `cambios`: `infra/lib/**` y `.env.example` de la raíz; excluidas `infra/compose/compose.prodlike.yaml` e `infra/quadlet/**` (`§2.3`).
  - **(A, recomendada)** Confirmarlas todas: ninguna contradice la especificación; (a), (d) y (f) cierran huecos que darían verdes sin significado, y (b), (c) y (e) evitan trabajo o ambigüedad sin añadir complejidad. La sesión refleja (f) en la especificación (`§3`, `§12.1.1` y `OPEN-E2ECI-05`, «añadido el 2026-11-17 tras `0.10e-b`»).
  - (B) Enmendar la especificación para la que no se confirme y retirarla de este ADR antes de aceptarlo.

## 9 · No verificado

Podman y comportamiento de `docker compose` en `ubuntu-26.04` (montajes `:Z`, propietario de ficheros en montajes, activación de servicios con perfil al nombrarlos); tiempo de construcción de la imagen `dev` en CI y si instala dependencias de Composer al arrancar; comportamiento de un *required check* ante promociones por avance rápido; alcance de descarga de artefactos en repositorio público. Nada se ha ejecutado.
