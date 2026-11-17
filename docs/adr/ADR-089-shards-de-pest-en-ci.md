# ADR-089 · Shards de Pest en CI: matriz de 4 jobs más `Concurrency` aparte y agregador `Tests (Pest)`

**Estado**: **ACEPTADA** (2026-11-17, por el usuario; propuesta el mismo día).
**Fecha**: 2026-11-17
**Origen**: issue [#714](https://github.com/pirexia/plataforma-educativa/issues/714) (CI: la suite Pest tarda ~12 min). Rama `chore/ci-pest-shards`.
**Sustituye en parte a** `ADR-060 §4.1.7` (invocación y procesos en CI) y `CA-060-09` («CI ejecuta `composer test` con 4 procesos»), **solo en la topología de CI**: el job único pasa a ser una matriz de *shards* (`§2`). `ADR-060` no se modifica (inmutable); conserva su vigencia en todo lo demás: una base por proceso, 4 procesos por *runner*, `Concurrency` en serie contra la base exacta `plataforma_test`, guarda de `bootstrap.php`.
**No cambia** nada de `ADR-033 §10` (PostgreSQL real, RLS, tres roles) ni el comportamiento local de `composer test`.

---

## 1 · Contexto

El job `Tests (Pest)` de `ci-api.yml` tarda ~12 min; el 94 % es `composer test` (preparación ~22 s). El repositorio es público: *runners* estándar de 4 vCPU y minutos gratuitos, de modo que repartir la suite en varios *runners* no tiene coste económico. Pest v5.3.5 (paratest 7.27.5) trae `--shard=i/N`, que reparte los ficheros de test entre ejecuciones independientes.

## 2 · Decisión

1. **Matriz de 4 *shards*** de `Unit` + `Feature` (`N = 4`). Cada *shard* es un job con sus propios servicios PostgreSQL y Redis, su aprovisionamiento, sus 4 bases por proceso (`plataforma_test_1..4`) y 4 procesos de Pest (`ADR-060 §4.1`, sin cambios). `fail-fast: false`: un *shard* rojo no cancela a los demás.
2. **`Concurrency` en un job propio** (quinta entrada de la misma matriz), no repetido en cada *shard* y no en el *shard* 1: es serie por diseño (`ADR-060 §4.1.5`, exige la base exacta `plataforma_test`) y sumarla a un *shard* alargaría el camino crítico. Así el tiempo total es el del *shard* más lento.
3. **Agregador `Tests (Pest)`**: job `test`, `needs: [pest]`, `if: always()`, que falla si el resultado de la matriz no es `success` (un job saltado o cancelado cuenta como fallo: un check *required* daría por bueno un `skipped`). El nombre `Tests (Pest)` sigue existiendo como resultado único; los jobs de la matriz se llaman `Pest shard i/4` y `Pest concurrencia`. Si en GitHub estuvieran marcados como *required*, solo debe serlo el agregador.
4. **Un único sitio para N**: la matriz de `ci-api.yml` (cada entrada lleva su `i/N`). Cambiar N exige tocar esa lista.
5. **Mecanismo**: script nuevo `composer test:shard`, que exige `PEST_SHARD` (p. ej. `1/4`) y pasa `--shard=$PEST_SHARD`. Con `--shard`, Pest 5 ejecuta `--list-tests` con las opciones recibidas y rechaza `--passthru-php` (comprobado), por eso no se añade a `test:paralelo`; el `memory_limit=-1` de los procesos hijos pasa a `phpunit.xml` (`<ini>`). `composer test` y `test:paralelo` no cambian; `@no_additional_args` no se toca.

## 3 · Consecuencias

- Tiempo esperado: el del *shard* más lento (~un cuarto de la suite más ~1-2 min de arranque por job); a medir en el CI del PR. Minutos consumidos: ~4× el arranque y la suma de los *shards*; gratis en repositorio público.
- Cinco aprovisionamientos de PostgreSQL/Redis en vez de uno: más ruido si falla la red del *runner*, mitigado por `fail-fast: false` y por el reintento manual de un solo job.
- El reparto de `--shard` es por fichero y determinista; un fichero muy pesado puede desequilibrar. A revisar con los tiempos reales.
- Cambio de nombres de check: si la protección de rama exigiera los antiguos nombres de job de la matriz (no existían: el job se llamaba `Tests (Pest)`), no hay efecto; el agregador conserva el nombre.

## 4 · Criterios de aceptación

- **CA-089-01** — Los 4 *shards* ejecutan juntos los mismos ficheros que la suite entera de `Unit` + `Feature` (la suma de «N files ran» coincide con el total).
- **CA-089-02** — `Concurrency` corre en un solo job.
- **CA-089-03** — El check `Tests (Pest)` falla si algún job de la matriz falla, se cancela o se salta.
- **CA-089-04** — `composer test` y `composer test:paralelo` no cambian.
