# ADR-069 · Codex como primer revisor del pipeline, con reparto del trabajo de revisión por tipo de comprobación

**Estado**: **ACEPTADA** (2026-11-02) por el usuario, con el reparto de `§5` tal como está redactado y la segunda ronda de Medios delegada en Codex. **Condición de uso `OPEN-069-01` confirmada por el usuario el 2026-11-02** (entrenamiento con sus datos desactivado en su cuenta de OpenAI; declaración del usuario, no verificable desde el proyecto): régimen activo. Resueltas el 2026-11-02: `OPEN-069-03` (la sesión lanza Codex por `Bash` con `allow` solo para `review` y `adversarial-review`), `OPEN-069-04` (`spec-writer` clasifica, la sesión lo comprueba contra el diff, la duda es crítico) y `OPEN-069-05` (aprobada con la palanca 5 de ahorro de cuota). Siguen abiertas, con su recomendación, `OPEN-069-02` y `OPEN-069-06`.
**Fecha**: 2026-11-02
**Origen**: decisión del usuario del 2026-11-02 de dar más responsabilidad a Codex (`openai/codex-plugin-cc`, cuota de OpenAI) para gastar menos cuota de Claude en revisiones.
**Sustituye parcialmente**:
- **`ADR-050 §5.5`**, entero (Codex «opcional», «no invocarla no es incumplimiento»). Lo sustituye `§4.1` de este ADR.
- **`ADR-050 §6`**, solo la fila de `ADR-049 §7.1` («la invocación de Codex es opcional, la de los tres no»).
- **`ADR-049 §7.1`**, solo la frase «nunca en lugar de ninguno; los tres siguen siendo obligatorios». **No se edita `ADR-049`**: su fichero no cambia, y el resto de `§7.1` (después de `implementer`, con la suite en verde y el trabajo commiteado) sigue vigente. Esta sustitución no se puede evitar: `§4.3` hace que, en un paso ordinario, Codex ocupe el sitio de `security-reviewer`, y eso contradice esa frase. `CLAUDE.md §11` no permite contradecirla sin decir que se sustituye.

**No toca**: `ADR-049 §5` (solo lectura, comandos prohibidos, `sandbox`, lista `deny`, puerta `Stop`), `§6`, `§7.2` (los hallazgos son candidatos y no tienen autoridad de bloqueo; un hallazgo contrario a un ADR se cierra citándolo), `§9` (reversión); ni `ADR-050 §5.2`, `§5.3` (retirada inmediata), `§5.4` y `§5.6`. Codex sigue fuera de la definición de terminado (`CLAUDE.md §10`). La revisión pasa a ser obligatoria por el **proceso de revisión** (`§4.1`), no por `§10`.
**Depende de**: la norma «Ahorro de cuota» y `docs/metricas-agentes.md`, que hoy solo están en la rama `chore/normas-ahorro-tokens` (commit `74108d0`, **sin mezclar en `develop`**). Este ADR no se puede aplicar hasta que esa rama esté mezclada.

---

## 1 · Contexto, con datos

1. **La cuota de Claude se acaba a mitad de semana** (plan Pro, aviso del usuario del 2026-11-02), y el proyecto durará meses.
2. **Coste medido de las revisiones** (`docs/metricas-agentes.md`, paso `1.14b-a`, el único medido; `subagent_tokens` no cuenta las lecturas de caché, así que estas cifras sirven para comparar, no son el coste real):

   | Agente | Ronda 1 | Ronda 2 |
   |---|---:|---:|
   | `security-reviewer` | 186 k | 102 k |
   | `doc-reviewer` | 128 k | 109 k |
   | `db-reviewer` | 89 k | 62 k |
   | **Total** | **402 k** | **272 k** |

   Lo que gastan las revisiones (674 k) es parecido a lo que gasta `implementer` (849 k). El agente más caro es `security-reviewer` (43 % de lo que cuestan las revisiones). La ronda 2 supone el 40 % del gasto de revisión. En `1.14b-a` se hizo **solo para comprobar que se habían corregido hallazgos Medios**: ninguno era Crítico ni Alto. La norma 3 de «Ahorro de cuota» no la habría evitado, porque esa norma pide segunda ronda también cuando hay Medios.
3. **Qué se sabe de Codex** (`ADR-050 §1.2`): se ejecutó tres veces, todas sobre el backoffice (`1.6b` a `1.6d`). **Los 9 hallazgos que propuso se aceptaron todos**, y uno de ellos fue de severidad Alta y no lo había visto ningún revisor (#224). Lo que encontró eran sobre todo fallos que detecta alguien con experiencia general sin conocer el proyecto: carreras, transacciones (`after_commit`), validación de entrada. En cambio, en el calibrado **no encontró ninguno de los 4 hallazgos ya conocidos**, y al menos 3 de ellos dependían de las convenciones del proyecto (`NOT VALID`/`VALIDATE`, `TenantContext::runFor()`, una regla de negocio de `REQ-BO`).
4. **Desde `ADR-050` (2026-09-21) Codex no se ha usado en ningún paso.** No aparece en `CHANGELOG.md` en los más de 20 pasos posteriores. Como era opcional, dejó de usarse, y por eso no hay datos de Codex sobre módulos con datos de menores.
5. **Cómo se le puede dar contexto a Codex** (comprobado en el plugin 1.0.6): `/codex:review` no acepta instrucciones propias (`validateNativeReviewRequest` las rechaza). `/codex:adversarial-review` sí las acepta, como texto de enfoque, y su prompt ya pone el foco en autorización, aislamiento de tenant, carreras y migraciones. Codex puede **leer** todo el repositorio, porque su `sandbox` solo limita la escritura (`ADR-049 §5.3`). Por tanto, que «no haya leído ningún ADR» se debe a que nadie se lo ha pedido, no a que no pueda.
6. **Qué parte del plan quedaría como paso ordinario si se clasifica por dominio, como propone la petición.** De los 17 pasos pendientes de la fase 1, unos 14 tocan datos de menores, autenticación, ficheros o purga (`1.13`, `1.15`, `1.16`-`1.19`, `1.21`-`1.27`). Solo `1.15a`, `1.15b` y, quizá, `1.20` serían ordinarios. **Con esa clasificación, el régimen ordinario se aplicaría a menos del 20 % de los pasos y apenas se ahorraría nada.**

## 2 · Problema

Gastar menos cuota de Claude en revisión **sin perder revisión en lo que conoce el proyecto**: aislamiento de tenant, RLS, permisos y ámbitos, auditoría (`ADR-035`) y convenciones de esquema. En esas comprobaciones los datos dicen que Codex no rinde. Además, si fallan, el resultado es una incidencia Crítica según `CLAUDE.md §5`: fuga entre tenants o exposición de datos de menores.

## 3 · Opciones reales

| # | Opción | Ahorro de Claude | Riesgo de que se escape un fallo en convenciones del proyecto | Coste de mantenerla | Reversibilidad |
|---|---|---|---|---|---|
| A | **No hacer nada** (`ADR-050`: Codex opcional) | 0. Codex lleva más de 20 pasos sin usarse | Igual que hoy | Nulo | — |
| B | **Clasificar el paso por dominio, como en la petición** (paso crítico = toca datos de menores, autenticación…; Codex sustituye a los revisores en los pasos ordinarios) | Bajo: se aplica a menos del 20 % de los pasos (`§1.6`) | Bajo | Bajo | Alta |
| C | **Codex como único revisor de código**, con Claude solo para la documentación | Alto: unos 450 k por paso | **Alto**: en el calibrado Codex no encontró ninguno de los hallazgos de convenciones, y aquí serían pasos con datos de menores | Bajo | Alta, pero un fallo que se escapa no tiene vuelta atrás |
| **D** | **Codex primero en todos los pasos, y reparto de comprobaciones**: Codex hace las de experiencia general; `security-reviewer` se queda solo con las de convenciones del proyecto; la verificación de correcciones Medias pasa a Codex | Medio. Seguro: los 164 k de la ronda 2 de `db-reviewer` y `security-reviewer` en `1.14b-a`. Probable: parte de la ronda 1 de seguridad (se medirá) | Bajo: lo que Codex hace peor sigue revisándolo Claude | Medio: un contexto fijo para Codex y una checklist dividida en dos | **Alta**: se vuelve a `ADR-050` desactivando el régimen (`§6`) |
| E | **Que Codex implemente los tramos mecánicos** (`/codex:rescue`) | Potencialmente el mayor (`implementer` gasta 849 k) | **Alto**, por los motivos de `§4.6` | Alto | Baja: el código ya está escrito |

## 4 · Decisión: opción D

### 4.1 · Codex es obligatorio y va primero

En todo paso que cambie código (`apps/`, `infra/`), Codex revisa **primero**, después de `implementer` y con la suite en verde y el trabajo commiteado. Lo hace con `/codex:adversarial-review --base develop` y el contexto de `§4.4`. `/codex:review`, que no admite contexto, puede añadirse como pasada complementaria. Revisar con Codex deja de ser opcional, pero **no entra en `CLAUDE.md §10`** y sus hallazgos **siguen sin autoridad de bloqueo** (`ADR-049 §7.2`, que no cambia): la sesión orquestadora los clasifica como cualquier otro. Que Codex no encuentre nada **no prueba nada** en las comprobaciones de convenciones (`§4.2`, columna derecha).

### 4.2 · Reparto de la checklist de seguridad (en vez de dividir los pasos por dominio)

| Codex (experiencia general; el calibrado demostró que rinde) | `security-reviewer` (convenciones del proyecto; el calibrado demostró que Codex falla) |
|---|---|
| Inyección y SQL concatenado (punto 3) | Tenant filtrado en el framework **y** RLS; `tenant_id` que llega del cliente (punto 1) |
| Validación de entrada, respuestas `500` en vez de `422`, límites de paginación | Permiso, recurso y **ámbito** (`ADR-044`), denegar por defecto (punto 2) |
| Carreras, bloqueos de fila, idempotencia, `after_commit`, fallos a medias | IDOR con `public_id` frente al `bigint` interno (punto 4) |
| Secretos en código o logs (punto 6) | Ficheros: tipo real, almacenamiento privado, URL firmada, prefijo de tenant (punto 5) |
| Operaciones destructivas sin confirmación (punto 8, en su parte técnica) | Categoría especial, cifrado y auditoría de lectura (punto 7) |
| Regresiones del diff y calidad de los tests (`§4.5`) | Sesión, cookie y CSRF (puntos 9 y 10); auditoría `INV-003`/`ADR-035` (punto 11); que el código respete los ADR del paso |

**Paso crítico**: el diff toca alguna de estas cosas: migración que crea una tabla o cambia RLS, `GRANT` o un `CHECK` de auditoría; un *endpoint*, *policy*, permiso, resolutor de ámbito o *middleware* de autorización, nuevo o modificado; autenticación o sesión; ficheros; datos de categoría especial; un *endpoint* que lee o escribe datos personales de menores o de terceros; dinero; tablas de plataforma o que cruzan tenants. Lo marca la especificación del paso y la sesión orquestadora lo comprueba contra el diff. **En caso de duda, el paso es crítico.**

- **Paso crítico**: Codex, y después `security-reviewer` con **solo la columna derecha**, sobre el **diff**. Recibe la lista de decisiones aprobadas y los hallazgos de Codex ya clasificados, para no repetirlos ni reabrirlos. Si, mientras revisa, ve un hallazgo de la columna izquierda que Codex no señaló, lo comunica: es un dato para `§5`.
- **Paso ordinario**: solo Codex. `security-reviewer` se lanza únicamente si Codex trae un Crítico o un Alto aceptado en la clasificación, o si Codex no está disponible (`§6`).

### 4.3 · `db-reviewer` y `doc-reviewer` no cambian (aquí corrijo la petición)

- **`db-reviewer` se lanza en toda migración, también en los pasos ordinarios.** La petición lo dejaba condicionado a que Codex trajera un Crítico o Alto. No lo acepto: el único hallazgo de esquema del calibrado (`NOT VALID`/`VALIDATE`, *expand/contract*) Codex no lo encontró; lo que vale en una migración son justo las convenciones del proyecto (`ADR-029`, RLS), y además `db-reviewer` es el revisor más barato (89 k). Codex puede hacer una segunda lectura de las migraciones, pero nunca sustituye a `db-reviewer`.
- **`doc-reviewer`, precedido de `doc-precheck`, no cambia**: Codex no conoce la estructura documental ni la regla 7 de vigencia de los documentos raíz.

### 4.4 · Contexto que se le da a Codex

Un texto fijo y versionado en la skill (`contexto-codex.md`), que se pasa como enfoque a `adversarial-review`. Contiene:

1. Las invariantes `INV-*` de `CLAUDE.md §7`.
2. Cómo funciona aquí el aislamiento: `TenantContext`, RLS y `public_id`.
3. Las reglas de seguridad de `CLAUDE.md §8`.
4. **Las rutas de los ADR del paso y de su especificación, con la orden de leerlos antes de opinar.** Leerlos gasta cuota de OpenAI, no de Claude.
5. Las decisiones aprobadas que no se reabren.
6. La regla de cierre de `ADR-049 §7.2`.
7. El formato de salida: severidad según `CLAUDE.md §5`, fichero y línea, y **una lista de lo que ha comprobado y lo que no**.

Esa lista la declara el propio Codex y no prueba nada, pero hace visible el hueco. Así se responde a lo que pedía el punto 3 de la petición («revisar solo lo que Codex no cubrió»), que tal cual no se puede comprobar: Codex no informa de qué ha cubierto. Por eso el ámbito de `security-reviewer` lo fija la columna derecha de `§4.2`, no lo que Codex diga haber cubierto.

### 4.5 · Segunda ronda y otras tareas que pasan a Codex

- **Comprobar que las correcciones de Medios y Bajos están bien**: lo hace Codex, sobre el rango de commits de la corrección y con la lista de hallazgos como enfoque. El revisor de Claude que corresponda **solo** vuelve a entrar si se corrigió un Crítico o un Alto, y solo para esos hallazgos.
- **También pasan a Codex**: la revisión de los tests del diff (si cada test comprueba lo que dice su nombre, si cubre los caminos de error, si hay aserciones vacías), la búsqueda de regresiones en el diff y la segunda lectura de las migraciones.

### 4.6 · Codex no implementa (opción E descartada)

`/codex:rescue` sigue prohibido. Motivos:

1. **No hay ninguna barrera técnica que impida que escriba** (`ADR-049 §5.1`), y se ejecutaría sobre el checkout principal, que monta el contenedor. Es el caso del issue #150.
2. **No ha leído las normas del proyecto.** Fidelidad literal a la especificación (`CLAUDE.md §3`), los cuatro idiomas (`INV-009`), `ADR-029`, `ADR-056`, `AR-*`. Un tramo que se aparte de la especificación cuesta, en revisión y corrección, más cuota de Claude de la que ahorra.
3. **Trazabilidad**: los commits con `Verificado`, la nota viva y la atribución no tienen un responsable con las normas cargadas.
4. **Ejecutar `rescue` dispara la retirada inmediata** (`ADR-050 §5.3` punto 1).

Si algún día se quiere probar, hará falta un ADR propio, con un *worktree* aislado y un tramo de prueba (`ADR-050 §5.6`).

### 4.7 · Cómo se lanza

Hoy los comandos del plugin no los puede invocar el modelo (`disable-model-invocation: true`). Hay dos formas: que el usuario teclee el comando, o que la sesión llame al script del plugin (`codex-companion.mjs`) por `Bash`, **solo** con los subcomandos `review` y `adversarial-review`. Lo decide el usuario en `OPEN-069-03`.

## 5 · Medición, fecha de revisión y vuelta atrás

**Qué se registra** en `docs/metricas-agentes.md`, en una tabla «Codex» con una fila por paso:

- Si el paso fue crítico u ordinario, y las líneas del diff.
- Hallazgos de Codex propuestos, aceptados y descartados, por severidad.
- **Escapes**: hallazgos válidos de severidad Media o superior, de la **columna izquierda** de `§4.2`, que encuentran después `security-reviewer`, `db-reviewer`, CI, la prueba E2E o un issue posterior atribuible al diff del paso.
- Tokens de los revisores de Claude, comparados con lo que se habría gastado sin este régimen (`1.14b-a`, normalizado por cada 1000 líneas de diff).
- Si Codex estuvo disponible o no.

En los pasos críticos, la medición de escapes no cuesta nada: `security-reviewer` revisa igualmente después de Codex.

**Fecha de revisión**: al cerrar el 6.º paso con este régimen o el **2027-01-31**, lo que llegue primero. La revisión no es un aprobado o suspenso: siguiendo la lección de `ADR-050 §9`, ninguna medición que no se pueda repetir decide por sí sola, y con menos de 15 hallazgos en total el resultado solo orienta. **Se propone al usuario volver a `ADR-050`** si ocurre alguna de estas tres cosas:

1. **Escapa algún Crítico o Alto de la columna izquierda en un paso ordinario.** Esto no espera a la fecha de revisión: el régimen ordinario se suspende en el acto, todos los pasos pasan a tratarse como críticos y se avisa al usuario en la misma sesión.
2. Codex tiene más descartados que aceptados, con 10 hallazgos o más.
3. El ahorro en la mediana de tokens de revisión por cada 1000 líneas de diff es inferior al 25 %.

Las tres condiciones de retirada inmediata de `ADR-050 §5.3` **siguen activas sin cambios**.

## 6 · Si Codex no está disponible o se retira

Si se agota la cuota de OpenAI, el servicio falla o se aplica `ADR-050 §5.3`, se vuelve en ese mismo paso al régimen anterior: `security-reviewer` completo y la ronda 2 como hoy. **Las definiciones de los agentes conservan la checklist completa**, para que volver atrás sea solo dejar de usar el modo acotado. Hay un riesgo que se acepta a sabiendas: **la condición `ADR-050 §5.3` punto 2 retira Codex cuando lleguen datos reales (H0, `OPEN-11`)**, y a partir de ese momento las revisiones vuelven a costar lo de hoy.

## 7 · Consecuencias

**A favor**:

- Se ahorran, con seguridad, las rondas 2 de seguridad y de BD cuando solo hay Medios (164 k en `1.14b-a`).
- Codex vuelve a usarse y produce datos sobre módulos con datos de menores.
- Las comprobaciones de convenciones del proyecto siguen en Claude.

**En contra**:

- Más pasos en el pipeline y más trabajo de clasificación para la sesión orquestadora, que gasta cuota de Claude. El ahorro neto es lo que se mide en `§5`.
- Cada paso depende de un servicio externo con cuota propia, que también puede agotarse.
- **Sale código del proyecto a OpenAI en todos los pasos**, no solo en algunos.

**Datos personales**:

- **A Codex solo llega código y datos ficticios** (`ADR-030`, `REQ-SEED-005`). Nunca datos reales: si aparecen, se retira (`ADR-050 §5.3` punto 2).
- Este ADR añade una obligación: **los ficheros de prueba que están en el árbol no pueden contener imágenes de personas reales.** Por ejemplo, `apps/web/e2e/fixtures/pickup-photo-e2e.jpg`, que está en el checkout principal sin commitear y que **no he comprobado**. Codex puede leer cualquier fichero del árbol.
- La política de entrenamiento de la cuenta de OpenAI (`ADR-050 §11`) sigue sin decidirse, y con este régimen pesa más: es `OPEN-069-01`.
- **`SECURITY.md` y `PRIVACY.md` siguen sin mencionar Codex** (`ADR-049 §15` punto 2; lo agravaron `ADR-050 §14` y ahora este ADR).

**Cambios que hacen falta después y que este ADR no hace** (lo hace la sesión orquestadora o quien corresponda):

1. Skill `revision-con-codex`: el nuevo régimen, `contexto-codex.md`, cómo se lanza y qué hacer si Codex no está.
2. `CLAUDE.md §2`: el párrafo «Segunda opinión externa» y la norma 3 de «Ahorro de cuota».
3. `.claude/agents/security-reviewer.md`: un modo acotado a la columna derecha, que recibe los hallazgos de Codex. `db-reviewer.md` también recibe esos hallazgos.
4. `.claude/settings.json`: si se acepta `OPEN-069-03`, permitir solo `review` y `adversarial-review` y denegar `task`, `transfer` y `task-worker`.
5. `docs/metricas-agentes.md`: la tabla de `§5`.
6. `PLAN-IMPLEMENTACION.md`: el anexo A y que cada paso indique si es crítico u ordinario.
7. `SECURITY.md` y `PRIVACY.md`, `CHANGELOG.md` y la sección 20 del documento de requisitos.

## 8 · Alternativas descartadas

- **A, no hacer nada**: no ahorra nada. Además, en los más de 20 pasos con Codex opcional se ha usado cero veces.
- **B, clasificar por dominio**: se aplicaría a menos del 20 % de los pasos (`§1.6`), así que ahorra poco. Su idea buena, que el riesgo decide cuánta revisión hace falta, se conserva en `§4.2`, pero aplicada a la superficie que toca el diff.
- **C, Codex como único revisor de código**: la única medición que existe de cómo rinde Codex en convenciones del proyecto es 0 de 4 (al menos 3 de ellos de convenciones), y en estos módulos un fallo que se escapa es una fuga de datos de menores.
- **E, Codex implementa**: descartada por los motivos de `§4.6`.
- **Pasar los revisores a Haiku**: `CLAUDE.md §2` lo prohíbe («nunca se rebajan a Haiku»).
- **Activar la puerta `Stop`**: sigue prohibida por `ADR-049 §5.4`.

## 9 · Preguntas abiertas para el usuario

- **`OPEN-069-01`** · Política de entrenamiento de la cuenta de OpenAI (`ADR-050 §11`, sin resolver). Con revisión obligatoria en cada paso, todo el código del proyecto sale hacia OpenAI. **Recomendación**: desactivar el entrenamiento en la cuenta, o pasar a una capa de pago, **como condición para aceptar este ADR**.
- **`OPEN-069-02`** · ¿Contexto solo como enfoque de `adversarial-review`, o también un `AGENTS.md` en la raíz para `/codex:review`? **Recomendación**: de momento solo el enfoque. El `AGENTS.md` solo si se comprueba que la revisión nativa lo carga, porque sería un documento raíz más sujeto a la regla 7 de vigencia.
- **`OPEN-069-03`** · ¿Lanza Codex la sesión, por `Bash` y con permisos acotados, o lo teclea el usuario? **Recomendación**: que lo lance la sesión, con `allow` para exactamente esos dos subcomandos y `deny` para los demás. Si no, el régimen depende de que el usuario esté presente en cada paso.
- **`OPEN-069-04`** · ¿Quién clasifica el paso como crítico u ordinario? **Recomendación**: `spec-writer` lo marca en la especificación, la sesión orquestadora lo comprueba contra el diff y, en caso de duda, el paso es crítico.
- **`OPEN-069-05`** · ¿La ronda 2 de `doc-reviewer`, cuando solo hay Medios y Bajos, puede hacerla `doc-precheck` sobre una lista cerrada de hallazgos? Ahorraría unos 109 k de Sonnet por paso. **Recomendación**: sí. `doc-reviewer` solo vuelve a entrar si se corrigió un Alto.
- **`OPEN-069-06`** · Cuando llegue H0 (`ADR-050 §5.3` punto 2), ¿se acepta que este régimen termine y que las revisiones vuelvan a costar lo de hoy? ¿O se estudia antes una capa de pago de OpenAI con garantías contractuales? **Recomendación**: aceptar que termina y decidirlo con un ADR nuevo antes de H0, no después.
