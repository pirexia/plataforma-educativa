# ADR-060 · Suite de tests en paralelo y norma de ejecución

**Estado**: **ACEPTADA** (2026-10-10). El usuario aprobó el plan ese día; este ADR lo registra. **Inmutable**: cualquier cambio posterior exige un ADR nuevo que lo sustituya explícitamente.
**Fecha**: 2026-10-10
**Paso**: transversal, fuera de la numeración del plan. Rama `chore/tests-paralelos`.
**Se apoya en**: `ADR-033 §10` (suite sobre PostgreSQL real, RLS, tres conexiones con roles distintos); `INV-015`; `RN-AUTH-03`; `CLAUDE.md §3` y `§9`; issue [#199](https://github.com/pirexia/plataforma-educativa/issues/199).
**No sustituye** ningún ADR. **No cambia** nada de `ADR-033 §10`: cada proceso sigue corriendo contra PostgreSQL real, con RLS y con `pgsql`/`pgsql_owner`/`pgsql_platform` en sus roles; solo deja de haber una única base de test.
**Afecta a**: `apps/api/tests/` (`bootstrap.php`, `Pest.php`, `TestCase.php`, `tests/Support/`, ficheros de `Feature/Backoffice`), `apps/api/composer.json`, `infra/containers/postgres/`, `.github/workflows/ci-api.yml`, `CLAUDE.md §3`, `.claude/agents/{implementer,test-writer,verificador,security-reviewer,db-reviewer,doc-reviewer}.md`, `SYSADMIN.md`, `CONTRIBUTING.md`.

---

## 1 · Contexto

Medido el 2026-10-10 en el contenedor `plataforma-api` (6 CPU):

- **Serie**: 1093 tests en **451 s**. Coste repartido, no concentrado: los 10 tests más lentos suman el 6 %.
- **Por test, ~400 ms de preparación**: `provisionCoreTenant` ~390 ms, `provisionActiveUser` ~370 ms (de ellos `Hash::make` con coste 12 ~170 ms), `platform:sync-registry` ~85 ms.
- **Ajustes de motor descartados por medición**: `synchronous_commit=off` −5 %; opcache/JIT en CLI 0 %. El cuello es CPU de PHP en un solo núcleo, no PostgreSQL ni E/S.

**Prueba de concepto de paralelo** (paratest ya instalado como dependencia de Pest 4; 6 procesos; bases `plataforma_test_1`…`_6` creadas con `CREATE DATABASE … TEMPLATE plataforma_test` más `GRANT CONNECT` a los tres roles; `tests/bootstrap.php` eligiendo base y base Redis por `TEST_TOKEN`): `Auth` 329 tests en 52 s, `Core` 215 en 36 s, `Curso` 102 en 26 s, el resto de directorios en verde. **Fallan dos cosas**, ambas defectos de la suite y no del paralelo:

1. `Feature/Backoffice`: los *helpers* `bo*()` están definidos dentro de ficheros de test (`PlatformAdminManagementTest.php` y otros cuatro). Un proceso que no carga ese fichero no los tiene. En serie funciona por el orden de carga: es un defecto latente, también en serie con `--filter`.
2. La suite completa: un `afterAll` lanza un error y Collision lo convierte en `TypeError` (`AfterLastTestMethodErrored`). Sin localizar.

Estimación tras arreglarlos: **~100-120 s** para `Unit` + `Feature`.

**Hechos que condicionan el diseño:**

- `plataforma_app` no puede `CREATE DATABASE`, así que el mecanismo propio de Laravel (`ParallelTesting`, que crea `<base>_test_N`) no sirve: se desactiva con `LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES` y las bases se crean fuera, con el superusuario del clúster.
- `CREATE DATABASE … TEMPLATE` **no copia** los privilegios de base (`GRANT CONNECT`, `01-tenancy.sql.tpl:56`) ni los `ALTER DATABASE … SET`, y **falla si la plantilla tiene conexiones abiertas**. En CI el paso «Arrancar servidor de desarrollo» deja `artisan serve` conectado a `plataforma_test`.
- `tests/Concurrency` (`ConcurrentTestCase`, `worker.php`, `curso-worker.php`) lanza procesos hijos que abortan si la base no es exactamente `plataforma_test`. Es intencionado y se mantiene.
- `TestCase` migra de forma perezosa, una vez por proceso (`self::$migrated`), y envuelve cada test en `DatabaseTransactions` **solo** en `pgsql`; lo escrito por `pgsql_owner`/`pgsql_platform` queda confirmado.
- `max_connections=100` en el contenedor de desarrollo y en CI; el issue #199 documenta agotamiento de conexiones en la suite completa (`PlatformSchemaGrantsTest`).
- `CLAUDE.md §9`: nada se instala en el host. El host solo ejecuta `podman`.

## 2 · Qué NO decide este ADR

- **No rebaja el coste de bcrypt en tests.** `RN-AUTH-03` exige bcrypt coste ≥ 12 sin excepción de entorno. Bajarlo a 4 en `testing` ahorraría ~170 ms por usuario creado, pero es una excepción a una regla de negocio y solo la puede decidir el usuario con un cambio de requisito. Queda como alternativa no adoptada (`§7`).
- **No toca `tests/Concurrency`** salvo para ejecutarla aparte (`§4.1`).
- **No cambia el frontend** (Vitest/Playwright).

## 3 · Opciones reales

| Opción | Qué es | Coste en solitario | Mantenimiento a 3 años | Invariantes | Reversibilidad |
|---|---|---|---|---|---|
| **A** · Seguir en serie | Nada | Cero | El tiempo crece lineal con los tests: ~450 s hoy, >15 min con los 53 módulos. Se acaba ejecutando a medias y sin norma | Ninguna afectada, pero `INV-015` se erosiona en la práctica | Total |
| **B** · Solo quitar trabajo repetido (`§4.3`) | Memorizar hash y sincronización | Bajo | Ahorro acotado (~25 %): no cambia la pendiente | Ninguna | Total |
| **C** · Paralelo con una base por proceso + `B` + norma de ejecución | Esta decisión | Medio: un script, `bootstrap.php`, dos arreglos de suite, CI | Escala con los núcleos; una pieza más (el script) | `ADR-033 §10` intacto | Alta: `composer test:serie` sigue existiendo |
| **D** · Paralelo con una sola base y aislamiento por esquema o por transacción | Todos los procesos sobre `plataforma_test` | Alto | Frágil: RLS, roles y datos confirmados por `pgsql_owner`/`pgsql_platform` se pisarían entre procesos | Riesgo real sobre las pruebas de aislamiento (`ADR-033 §10`, tests 2, 8 y 10) | Media |

## 4 · Decisión: opción **C**

### 4.1 · Ejecución en paralelo, local y en CI

1. **Una base PostgreSQL por proceso**, llamada **`plataforma_test_N`** (`N` = `TEST_TOKEN`, de 1 al número de procesos). Clon de `plataforma_test` con `CREATE DATABASE … TEMPLATE plataforma_test OWNER <superusuario>` seguido de `GRANT CONNECT ON DATABASE … TO plataforma_owner, plataforma_app, plataforma_platform`.
2. **Un script idempotente** en `infra/containers/postgres/` (`bases-test-paralelo.sh N [--recrear]`), mismo estilo que `02-tenancy-test-db.sh`, ejecutado con el superusuario del clúster:
   - sin `--recrear`: crea las `plataforma_test_1..N` que falten y reaplica el `GRANT CONNECT` a todas; no toca las que existen;
   - con `--recrear`: las borra y las vuelve a clonar (necesario tras editar en sitio una migración no publicada, `ADR-058 §5`, o si una base queda sucia);
   - si la plantilla tiene conexiones, falla con un mensaje que lo dice, no con el error crudo de PostgreSQL;
   - **desarrollo**: desde el host, `podman exec -i <contenedor postgres> sh -s -- 6 < infra/containers/postgres/bases-test-paralelo.sh`. Nada se instala en el host;
   - **CI**: con el `psql` que el job ya instala, **antes** del paso que arranca `artisan serve` contra `plataforma_test`.

   El esquema de las copias no necesita estar al día: cada proceso migra su propia base en el primer `setUp()` (`TestCase::$migrated`), igual que hoy en serie.
3. **`tests/bootstrap.php`**: si existe `TEST_TOKEN`, fija `DB_DATABASE=plataforma_test_{TEST_TOKEN}`, `REDIS_CACHE_DB = 2 + TEST_TOKEN` (la `2` queda para la ejecución en serie, `phpunit.xml`; con 16 bases Redis caben hasta 13 procesos) y `LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES=1`. **Guarda**: con `TEST_TOKEN` presente, el nombre resultante tiene que casar con `^plataforma_test_[0-9]+$` y el entorno ser `testing`; si no, aborta. Sin `TEST_TOKEN` no cambia nada. Cualquier otra base Redis que use la suite sigue la misma regla de desplazamiento.
4. **Los procesos hijos** se lanzan con `memory_limit=-1` (medido en la prueba de concepto: sin él, los hijos se quedan sin memoria).
5. **`tests/Concurrency` sigue en serie**, en una invocación aparte, contra `plataforma_test`. Sus guardas (`ConcurrentTestCase`, `worker.php`, `curso-worker.php`) **siguen admitiendo solo `plataforma_test`**: no se relajan para aceptar `_N`.
6. **Invocación** (scripts de `apps/api/composer.json`, ejecutados dentro del contenedor `api`; no hay `Makefile` en el repositorio y no se crea uno):

   | Comando | Qué hace |
   |---|---|
   | `composer test` | Suite completa: `Unit` + `Feature` en paralelo y después `Concurrency` en serie. Falla si falla cualquiera de las dos |
   | `composer test:paralelo` | Solo `Unit` + `Feature` en paralelo |
   | `composer test:concurrencia` | Solo `Concurrency`, en serie |
   | `composer test:serie` | La suite entera en serie, como hasta hoy. Para diagnosticar y para la reversión (`§9`) |
   | `php artisan test <ruta> [--filter=…]` | Ejecución parcial durante el trabajo, en serie, contra `plataforma_test` (sin cambio) |

7. **Procesos por defecto**: **6** en desarrollo (los núcleos del contenedor medidos) y **4** en CI (los del *runner* de GitHub). Configurable con la variable `PEST_PROCESOS`; el script de bases recibe el mismo número. Si `PEST_PROCESOS` supera las bases creadas, la guarda de `bootstrap.php` falla con un mensaje que nombra el script.

### 4.2 · Arreglos previos (condición para activar el paralelo)

1. **Ningún *helper* compartido vive en un fichero de test.** Los `bo*()` y cualquier otro que se use desde más de un fichero pasan a `tests/Support/` (o a `tests/Pest.php` si es una función de preparación genérica), cargados por *autoload* o por `Pest.php`. Un *helper* que solo usa su propio fichero puede quedarse en él.
2. **Localizar y corregir el `afterAll`** que provoca `AfterLastTestMethodErrored`, con su causa en el issue que se abra.
3. **Revisar todos los `afterAll`/`beforeAll`** (hoy 4 `afterAll`): ninguno puede depender de datos creados por otro fichero ni borrar datos que otro fichero necesite.

### 4.3 · No repetir trabajo idéntico por test

1. **Hash de la contraseña fija de prueba memorizado por proceso.** Un *helper* de `tests/Support/` calcula una vez `Hash::make(<contraseña fija>)` y lo reutiliza. Sigue siendo bcrypt coste 12 de verdad: **`RN-AUTH-03` intacto**. Los tests que prueban el hash en sí (coste, reamasado, contraseña distinta) llaman a `Hash` directamente y no usan el memorizado.
2. **`platform:sync-registry` una vez por proceso**, con bandera estática como `self::$migrated`. **Condición**: ningún test depende de que se vuelva a sincronizar entre tests. Si alguno lo necesita (porque altera el registro), lo invoca él explícitamente. Y solo vale si la sincronización escribe por una conexión confirmada (`pgsql_owner`/`pgsql_platform`); si escribe por `pgsql` dentro de la transacción del test, se revertiría y esta medida no se aplica (se comprueba en `CA-060-06`).

### 4.4 · Norma de ejecución de la suite

1. **Durante el trabajo** se ejecutan solo los ficheros o directorios afectados.
2. **La suite completa (`composer test`) se ejecuta una vez, en local, antes de abrir el PR**, y en CI en cada *push* al PR. El merge exige CI en verde (sin cambio).
3. **Los revisores no relanzan la suite completa** salvo que su revisión lo requiera (por ejemplo, para reproducir un hallazgo); se apoyan en el resultado del `verificador` atado a un hash de commit y en CI. Pueden ejecutar tests concretos.
4. La norma de `CLAUDE.md §3` sobre «`Verificado: X/X Pest en verde`» se mantiene, y el mensaje dice el alcance: `(suite completa)` o `(ficheros afectados: …)`. Un relanzamiento tras corte de cuota solo se apoya en un `Verificado` de suite completa para dar por buena la suite completa.

**Cambios de normas que esto exige** (los hace la sesión orquestadora, no este ADR):

| Documento | Cambio |
|---|---|
| `CLAUDE.md §3` | Añadir la norma `§4.4` (parcial durante el trabajo; completa una vez antes del PR y en CI; revisores no la relanzan). Precisar el alcance en el mensaje `Verificado`. Citar los comandos de `§4.1.6` |
| `.claude/agents/implementer.md` | Ejecuta solo lo afectado mientras trabaja; pide la suite completa al terminar, antes del PR |
| `.claude/agents/test-writer.md` | Ejecuta sus tests nuevos o modificados y su directorio; no la suite completa |
| `.claude/agents/verificador.md` | Dos modos explícitos: **afectados** (lista de rutas) y **completa** (`composer test`); informa del modo, del hash y del número real de tests |
| `.claude/agents/{security,db,doc}-reviewer.md` | No relanzan la suite completa salvo necesidad justificada en su informe; usan el resultado del `verificador` y de CI |
| `SYSADMIN.md`, `CONTRIBUTING.md` | Script de bases, comandos, `PEST_PROCESOS`, cuándo usar `--recrear` |
| `.github/workflows/ci-api.yml` | Paso de bases antes de `artisan serve`; `composer test` en lugar de la invocación en serie |

## 5 · Motivo

- **El cuello es CPU de PHP en un núcleo** (`§1`): los ajustes del motor no dan nada y el coste está repartido, así que no hay un test que optimizar. Repartir entre núcleos es la única palanca que escala; `B` sola no cambia la pendiente.
- **Una base por proceso conserva íntegro `ADR-033 §10`**: cada proceso ve el mismo motor, los mismos roles, RLS y la misma separación de conexiones que hoy. `D` no puede garantizarlo con datos confirmados por `pgsql_owner`/`pgsql_platform`.
- **Los dos arreglos de `§4.2` son defectos de la suite en sí**, también en serie con `--filter`; arreglarlos tiene valor aunque se abandonara el paralelo.
- **La norma `§4.4` es la mitad del ahorro**: hoy la suite completa se ejecuta varias veces por paso (implementador, revisores, verificador). Ejecutarla una vez en local y una en CI quita más tiempo que el paralelo solo.
- **Reversible**: `composer test:serie` queda como camino de vuelta sin tocar código.

## 6 · Consecuencias

- Una pieza nueva de infraestructura de desarrollo (el script) que hay que ejecutar una vez por clúster y tras ciertas migraciones; se documenta en `SYSADMIN.md`.
- Hasta `PEST_PROCESOS` bases más en el clúster de desarrollo; con 6 clones de una base de test, coste de disco despreciable.
- **Conexiones**: cada proceso abre al menos tres (una por conexión de Laravel). Con 6 procesos, ≥ 18 simultáneas más las de desarrollo, frente al límite de 100. El issue #199 pasa a medirse también en paralelo (`CA-060-07`).
- Los datos confirmados por `pgsql_owner`/`pgsql_platform` siguen acumulándose en cada base entre ejecuciones, como hoy en `plataforma_test`; `--recrear` los limpia.
- Los tests quedan obligados a ser independientes del orden y del fichero vecino; lo que hoy funciona por casualidad de orden aflora como fallo.
- Ningún ADR anterior cambia.

## 7 · Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| **A** · Seguir en serie | 451 s hoy y lineal con los tests; en la práctica empuja a no ejecutar la suite, que es peor para `INV-015` que una pieza más de infraestructura |
| **B** sola | Ahorro acotado (~25 %, `§1`), no escala. Se adopta como parte de `C` |
| **D** · Paralelo sobre una sola base | Rompe la separación de datos confirmados entre procesos y pone en riesgo las pruebas de aislamiento de `ADR-033 §10` |
| **`ParallelTesting` de Laravel creando sus bases** | Exige `CREATEDB` al rol de la aplicación. Darle ese privilegio a `plataforma_app` (o a `plataforma_owner`, roles de clúster compartidos con desarrollo) por comodidad de tests no compensa |
| **Coste bcrypt 4 en `testing`** | **No adoptada; exigiría decisión del usuario** y un cambio de `RN-AUTH-03`, que hoy no admite excepción de entorno. Con `§4.3.1` el ahorro restante es pequeño |
| **`synchronous_commit=off`, opcache/JIT en CLI** | Medidos: −5 % y 0 %. No compensan la diferencia de configuración frente a producción |
| **Relajar las guardas de `Concurrency` para aceptar `plataforma_test_N`** | Son pocos tests y lanzan procesos hijos cuya base se decide fuera de Pest; abrir la guarda a un patrón amplía el riesgo de que un hijo escriba en una base que no es la del test, a cambio de un ahorro pequeño |
| **Crear un `Makefile`** | Duplicaría lo que ya cubren los scripts de `composer`; una herramienta más sin beneficio |

## 8 · Criterios de aceptación

- **CA-060-01** — `composer test` ejecuta `Unit` + `Feature` en paralelo y `Concurrency` en serie, y termina en verde **tres veces seguidas** en el contenedor de desarrollo con 6 procesos.
- **CA-060-02** — El número de tests ejecutados por `composer test` es **igual** al de `composer test:serie` sobre el mismo commit (ningún test se pierde ni se duplica al repartir).
- **CA-060-03** — El tiempo de pared de `composer test` en desarrollo es **≤ 50 % del de `composer test:serie`** sobre el mismo commit (hoy 451 s; objetivo 100-120 s más `Concurrency`). El número real se anota en `SYSADMIN.md`.
- **CA-060-04** — El script de bases es idempotente: ejecutarlo dos veces seguidas sin `--recrear` no falla ni cambia nada; con `--recrear` deja las `N` bases recién clonadas de la plantilla y con `CONNECT` para los tres roles. Con la plantilla en uso, falla con mensaje propio.
- **CA-060-05** — Con `TEST_TOKEN` presente y una base que no case con `^plataforma_test_[0-9]+$`, la suite aborta antes del primer test. `ConcurrentTestCase` y los *workers* siguen abortando con cualquier base distinta de `plataforma_test` (test que lo comprueba o comprobación existente sin cambios).
- **CA-060-06** — Un test comprueba que el hash memorizado es bcrypt con coste 12 (`password_get_info`). Un test comprueba que lo escrito por `platform:sync-registry` sobrevive al final de un test (si no sobrevive, `§4.3.2` no se aplica y se documenta).
- **CA-060-07** — Durante una ejecución de `composer test`, el máximo de conexiones simultáneas a PostgreSQL (`pg_stat_activity`, muestreado) queda **por debajo del 80 % de `max_connections`**. Si no, se cierra antes #199 o se baja `PEST_PROCESOS`, y se anota.
- **CA-060-08** — Ningún fichero de `tests/` define una función usada desde otro fichero de test (comprobación por test de arquitectura o por `grep` en la revisión del PR).
- **CA-060-09** — CI ejecuta `composer test` con 4 procesos y queda en verde; el paso de bases va antes de `artisan serve`.
- **CA-060-10** — `CLAUDE.md §3` y las definiciones de agente de la tabla de `§4.4` recogen la norma, citando este ADR.

## 9 · Riesgos

| Riesgo | Mitigación |
|---|---|
| **Tests con estado global o dependientes del orden** (estáticos, `config()` sin restaurar, caché compartida, fecha fijada sin liberar) que en serie pasaban por casualidad | `CA-060-01` (tres ejecuciones seguidas) los hace aflorar; se arreglan en el test, no se reintentan. Un test inestable no se marca como `skip` sin issue |
| **`afterAll` con datos confirmados en `pgsql_platform`/`pgsql_owner`** que borran o esperan datos de otro fichero | `§4.2.3`; con una base por proceso el daño queda dentro del proceso, pero el defecto se corrige igual |
| **Agotamiento de conexiones** (`max_connections=100`, issue #199) | `CA-060-07`; `PEST_PROCESOS` configurable |
| **Copias con esquema obsoleto** tras editar en sitio una migración no publicada | Cada proceso migra su base; para ediciones en sitio, `--recrear` (documentado en `SYSADMIN.md`) |
| **El emisor OIDC simulado** (`artisan serve` en CI, contenedor `api` en desarrollo) lee su propia base, no la `_N` del proceso | Hoy tampoco ve los datos del test (están en una transacción sin confirmar); se comprueba que la batería de `1.4b` pasa en paralelo (`CA-060-01`/`-09`) |
| **Fallos que solo se dan en paralelo y no se reproducen en local** | `composer test:serie` y `php artisan test <fichero>` siguen disponibles para aislar el caso |

## 10 · Reversión

1. **Vuelta temporal a serie** (por ejemplo, un fallo intermitente sin diagnosticar): en CI, sustituir `composer test` por `composer test:serie`; en local, usar `composer test:serie`. No requiere tocar código de tests ni ADR, pero sí un issue que lo justifique y que se cierre al volver al paralelo.
2. **Abandono definitivo del paralelo**: exige un ADR que sustituya este. Pasos: retirar `test:paralelo` y la rama de `TEST_TOKEN` de `bootstrap.php`, el paso de bases de CI y el script; borrar `plataforma_test_1..N` (`DROP DATABASE`); revertir `CLAUDE.md §3` y los agentes en lo relativo a `§4.4` si también se abandona la norma.
3. **Lo que no se revierte**: los arreglos de `§4.2` y las memorizaciones de `§4.3` son correctos también en serie y se quedan.
