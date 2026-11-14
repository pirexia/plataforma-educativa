# ADR-050 · Cierre de la prueba de Codex: se mantiene como verificación adicional permanente y se retira el umbral de `ADR-049 §8` con su reversión por defecto

**Estado**: **ACEPTADA** (2026-09-21). El usuario decidió expresamente, tras ver la tabla de resultados completa de los tres pasos de prueba y el hecho de que el umbral formal de `ADR-049 §8.2` no se cumple: *«No lo desinstalamos, lo utilizamos como herramienta extra de verificación»*. Este ADR formaliza esa decisión, decide lo que esa frase no decide, y deja escrito lo que el usuario debería mirar antes de darla por cerrada del todo (`§11`).

**Fecha**: 2026-09-21

**Resuelve**: la contradicción, abierta desde el cierre de `1.6d` y anotada en `memory.md`, entre lo que `ADR-049 §8.3` manda hacer («revertir sin discusión») y lo que se ha hecho y se quiere seguir haciendo (usar `/codex:review` en cada paso). `CLAUDE.md §0` no permite dejar esa contradicción viva mientras se sigue invocando la herramienta: o se revierte, o se sustituye la regla por escrito. Este ADR hace lo segundo.

**Concreta**: `CLAUDE.md §0` (una decisión escrita no se incumple en silencio), `CLAUDE.md §9` (toda entrega incluye su procedimiento de reversión), `CLAUDE.md §10` (definición de terminado), `CLAUDE.md §11` (una decisión de un ADR solo se cambia con un ADR nuevo que la sustituya)

**Se apoya en**: `ADR-049` completo (del que solo toca `§8` y la condición de activación de `§9`), `ADR-030` (el entorno de desarrollo no puede alojar datos reales bajo ningún concepto, que es lo que hace tolerable la exposición residual), `ADR-037` (las credenciales de desarrollo no se reutilizan en el alojamiento), el issue [#150](https://github.com/pirexia/plataforma-educativa/issues/150) (un agente no actúa sobre trabajo ajeno a su encargo, que es por lo que la reversión «automática» de `§8.3` no se ejecutó sola)

**Afecta a**: el **proceso de revisión** de todos los pasos siguientes de `PLAN-IMPLEMENTACION.md`. **No afecta a ninguna línea de código entregado**, por el mismo motivo que `ADR-049`: el plugin no entra en `composer.json` ni en `package.json`, no se despliega y ningún artefacto del producto depende de él. Obliga a actualizar dos textos que hoy describen una prueba que ya ha terminado —la *skill* `revision-con-codex` y el párrafo de `CLAUDE.md §2`—, que **este ADR no edita** (`§14`).

**Sustituye a `ADR-049 §8` en su totalidad y a `§8.3` punto 1.** Re-hoga en `§5.3` de este ADR los puntos 2, 3 y 4 de `§8.3`, que **siguen vigentes sin cambio de contenido**. **`ADR-049 §9` no se sustituye**: se conserva íntegro y solo cambia lo que lo activa (`§5.2`). **Todo lo demás de `ADR-049` queda intacto**, y `§6` lo enumera expresamente para que nadie tenga que deducirlo. No toca ninguna invariante, ningún requisito de las secciones 1-17, ni `CLAUDE.md §10`.

---

## 1 · Contexto

### 1.1 · De dónde viene esta decisión

`ADR-049` (2026-09-14) autorizó instalar `openai/codex-plugin-cc` como **prueba acotada**: solo lectura, sin autoridad de bloqueo, con un umbral de éxito escrito de antemano (`§8`) y un procedimiento de reversión (`§9`). El motivo de escribir el umbral antes de empezar era explícito y correcto: *«sin un umbral escrito de antemano, la evaluación se resuelve por la impresión del día»*.

La prueba se ha ejecutado entera. Los tres puntos de medición que `§8.2` exigía —el calibrado más los dos pasos siguientes que se cerrasen tras la instalación— están cerrados, y cada uno dejó su registro en `CHANGELOG.md` y en `memory.md`, tal como `§8.2` obligaba. Esto ya es un resultado en sí mismo: **la prueba no se abandonó a medias ni se evaluó de memoria**, que es la forma habitual en que estas cosas mueren.

### 1.2 · Resultado, con las cifras reales y verificadas

Verificado el 2026-09-21 contra `CHANGELOG.md`, `memory.md` y el histórico de *commits*, no recordado:

| Paso | Fecha | Cobertura (≥ 2 de 4 conocidos de `1.6b`) | Precisión (≤ 1 descartado por aceptado) | Aportación diferencial (≥ 1 que ningún revisor humano produjera) |
|---|---|---|---|---|
| **Calibrado**, PR [#204](https://github.com/pirexia/plataforma-educativa/pull/204) (`1.6b`) | 2026-09-14 | **0 de 4 — NO cumple** | No registrada en su momento (hueco de proceso; reconstruida después: 3 propuestos, 3 aceptados, 0 descartados — cumple) | — (el calibrado no puntuaba en este eje) |
| **Primer paso de prueba**, `1.6c` / PR [#214](https://github.com/pirexia/plataforma-educativa/pull/214) | 2026-09-16 | No aplica (solo se medía en el calibrado) | 3 propuestos, **3 aceptados, 0 descartados** — cumple | **Sí**, y con holgura: issue [#224](https://github.com/pirexia/plataforma-educativa/issues/224) |
| **Segundo paso de prueba**, `1.6d` / PR [#229](https://github.com/pirexia/plataforma-educativa/pull/229) | 2026-09-21 | No aplica | 3 propuestos, **3 aceptados, 0 descartados** — cumple | **No en este paso**: los tres hallazgos son validación de parámetros de consulta, del tipo que una revisión humana atenta también encuentra |

Lo que hay detrás de las casillas, porque las cifras solas engañan en los dos sentidos:

- **El calibrado falló el eje que medía y acertó uno que no se le pedía.** No reprodujo ninguno de los cuatro hallazgos ya documentados de `1.6b` ([#200](https://github.com/pirexia/plataforma-educativa/issues/200) a [#203](https://github.com/pirexia/plataforma-educativa/issues/203)). En cambio, sobre ese mismo *diff* ya cerrado y ya revisado por tres revisores, encontró **tres condiciones de carrera reales** que nadie había visto: doble resolución concurrente de una `dual_authorization` sin bloqueo de fila ([#205](https://github.com/pirexia/plataforma-educativa/issues/205)), `CloneTenant` no idempotente ante fallo parcial —lo que dejaba `bo:retry-provisioning` sin salida— ([#206](https://github.com/pirexia/plataforma-educativa/issues/206)) y transición de ciclo de vida de tenant sin `lockForUpdate()` ([#207](https://github.com/pirexia/plataforma-educativa/issues/207)). Los tres se confirmaron contra el código real y se corrigieron el 2026-09-15 (PR [#212](https://github.com/pirexia/plataforma-educativa/pull/212)), cada uno con un test de regresión que se comprobó que falla sin su arreglo. **Y la revisión humana que fue a comprobar esos arreglos encontró dos más del mismo patrón**: [#209](https://github.com/pirexia/plataforma-educativa/issues/209) (**Crítica**, eliminación aprobada de tenant ejecutable sobre un tenant recién rescatado) y [#210](https://github.com/pirexia/plataforma-educativa/issues/210) (Media). Es decir: el hallazgo del revisor externo **abrió una veta** que la revisión propia explotó hasta una incidencia Crítica en el camino más sensible del módulo.
- **`1.6c` es el caso que decide.** El issue #224 (severidad **Alta**): una descontratación masiva de módulos aprobada podía ejecutarse **pese a que la autorización quedara `Fallida`**, porque `RunModuleRollout::dispatch()` corría dentro de la transacción de `DualAuthorizationService::execute()` con las tres conexiones de cola en `after_commit => false`. Ninguno de los tres revisores obligatorios —`db-reviewer`, `security-reviewer`, `doc-reviewer`— lo había visto. Ese es exactamente el eje que `§8.2` punto 3 definió como la razón de ser de la herramienta.
- **`1.6d` es un paso honesto y flojo.** Tres hallazgos reales y corregidos (validación de fechas mal formadas que producían `500` en vez de `422`, y `limit=0`/negativo en la paginación de `failed-jobs`), pero del tipo que un revisor humano atento produce. No resta; tampoco suma en el eje diferencial, y este ADR no va a fingir lo contrario.

En total, a lo largo de los tres puntos de medición: **9 hallazgos propuestos, 9 aceptados, 0 descartados**, más los tres bugs del calibrado que no entraban en ningún baremo. La cifra de descartes es la que más importa para el coste real, porque `§8.2` punto 2 lo dijo bien: *«un revisor que obliga a leer diez cosas para encontrar una cuesta cuota de la que sí es escasa: la atención del usuario»*. Con la muestra que hay, ese coste ha sido **cero**.

### 1.3 · La evaluación formal según la letra de `§8.2`/`§8.3`: fracaso

No hay ambigüedad y no se va a maquillar. `§8.2` exige las **tres condiciones a la vez**; `§8.3` punto 1 manda revertir *«sin discusión»* si falla alguna al cerrar el segundo paso de prueba; y `§8.3` cierra con *«el resultado por defecto es revertir»*.

La Cobertura falló, en el único punto donde se medía, y **esa medición no se repite**: era específica de un *diff* con respuesta ya conocida y, en el momento en que las respuestas están en el contexto de la sesión, una segunda ejecución ya no mide nada. Luego, por la letra de `ADR-049`, **el resultado formal de la prueba es fracaso**, y lo que procedía era `§9`.

Se deja escrito así, sin adornos, porque la alternativa —reinterpretar el umbral hasta que dé aprobado— es peor que cambiarlo. Este ADR **no dice que la prueba se superara**. Dice que la regla que la juzgaba estaba mal construida, y la sustituye hacia delante. Es lo que `CLAUDE.md §11` exige y lo que `ADR-049 §7.2` exigiría de cualquier otro: una decisión escrita no se incumple, se sustituye.

### 1.4 · Por qué la reversión «automática» no se ejecutó sola, y qué revela eso

`§8.3` presentaba la reversión como automática y sin discusión. No se ejecutó. La sesión orquestadora se detuvo, presentó al usuario la tabla completa de `§1.2` y preguntó si prefería ejecutar `§9` o enmendar el criterio.

**Esa parada fue correcta**, y conviene decir por qué, porque no es obvio: la reversión de `§9` toca `.claude/settings.json`, `.codex/config.toml`, una *skill* del proyecto y un párrafo de `CLAUDE.md` —**configuración compartida y versionada**— y desinstala una herramienta que el usuario pidió instalar. Ninguna sesión ni subagente tiene autoridad para eso por su cuenta; es la misma lógica del issue #150 y de `CLAUDE.md §11`. Un ADR puede ordenar una acción; no puede convertir en «automática» una acción que solo el usuario puede legítimamente autorizar.

Lo que esto revela es un **defecto de construcción de `§8.3`**, no de quien lo aplicó: una cláusula que se declara automática pero que nadie del proyecto puede ejecutar sin preguntar **no es automática, es una obligación de preguntar disfrazada**. Queda anotado en `§3.2` como el quinto defecto del umbral, y corregido en `§5.6`.

### 1.5 · Lo que decidió el usuario, y sobre qué datos

El 2026-09-21, tras ver la tabla de `§1.2` completa —con el 0/4 de Cobertura en primera línea, sin suavizar—, el usuario decidió: ***«No lo desinstalamos, lo utilizamos como herramienta extra de verificación»***.

Consta expresamente que vio antes de decidir: que el único eje que falla es una medición cerrada e irrepetible; que la Precisión fue perfecta en los tres puntos; que la Aportación diferencial se cumplió de verdad en `1.6c` con un issue de severidad Alta; y que la letra del ADR mandaba desinstalar. **No es una petición a ciegas**, y por eso este ADR la ratifica en vez de objetarla. `CLAUDE.md §0` obliga a plantarse cuando el usuario decide sin datos o contra los datos; aquí decidió **con** los datos y contra una regla propia que los datos han puesto en cuestión, que es exactamente el caso en que corresponde escribir un ADR nuevo y no discutir.

### 1.6 · Por qué esto merece un ADR y no una nota en `memory.md`

Tres motivos, ninguno de estilo:

1. **Cambia una decisión escrita e inmutable.** `CLAUDE.md §11` es literal: una decisión de un ADR solo se cambia con otro ADR que la sustituya. Dejar esto en `memory.md` sería precisamente el «cambiar un ADR en silencio» que esa norma prohíbe, con el agravante de que `memory.md` se reescribe cada sesión y `ADR-049 §8` seguiría diciendo, para siempre, que la herramienta debía haberse desinstalado en septiembre.
2. **Cambia el estatus de una pieza del pipeline de revisión de temporal a permanente**, y eso arrastra consecuencias que nadie ha discutido: la aceptación de la política de datos de `ADR-049 §2.1` se dio *«para esta fase de prueba»*, y una fase que no termina no es una fase (`§11`).
3. **Deja sin condición de activación un procedimiento de reversión.** `CLAUDE.md §9` exige que toda entrega tenga su reversión probada. Si se retira el marco de prueba sin decir qué pasa con `§9`, queda una herramienta permanente sin camino de salida escrito — el error opuesto al que se estaba corrigiendo, y más difícil de detectar.

---

## 2 · Qué **NO** decide este ADR

1. **No decide mantener la herramienta.** Eso lo decidió el usuario (`§1.5`) y aquí se ratifica; lo que este ADR decide es **con qué régimen** se mantiene, qué se retira de `ADR-049` y qué no.
2. **No convierte a Codex en parte de la definición de terminado** (`CLAUDE.md §10`). `ADR-049 §3.3` ya lo excluía y sigue excluido: un paso se cierra sin Codex igual que se cerraba antes, y su ausencia no es un incumplimiento de proceso (`§5.5`).
3. **No reabre el alcance de solo lectura, ni los comandos prohibidos, ni la puerta de revisión.** `/codex:rescue`, `/codex:transfer` y la puerta `Stop` siguen prohibidos exactamente igual (`§6`).
4. **No relaja la lista `deny` de `.claude/settings.json`** ni ninguna de las tres capas de protección de `ADR-049 §5.3`.
5. **No decide la política de datos con permanencia indefinida.** La plantea como punto abierto del usuario (`§11`), con la recomendación que corresponde, y no la resuelve por su cuenta.
6. **No edita `CLAUDE.md`, ni la *skill* `revision-con-codex`, ni `SECURITY.md`/`PRIVACY.md`, ni `CHANGELOG.md`.** Están fuera del ámbito de escritura de `architect`; `§14` los reporta.
7. **No arregla nada de lo que encuentra fuera de su alcance** (`CLAUDE.md §5`, issue #150).

---

## 3 · ¿Medía el umbral de Cobertura lo que de verdad importaba?

Esta es la pregunta de fondo, y merece respuesta con criterio y no con conveniencia. Mi respuesta es que **no, y que el defecto era anterior al resultado**: el umbral de Cobertura era un mal instrumento el día que se escribió, no solo el día que dio mal. Lo escribió `architect` y lo corrige `architect`, que es como debe ser.

### 3.1 · Qué medía exactamente

`§8.1`/`§8.2` punto 1 pedían: sobre un *diff* ya cerrado, ya revisado y con las respuestas ya escritas en cuatro issues, **reproducir al menos dos de esos cuatro hallazgos**. Es decir, medía **solape retrospectivo con el trabajo que los revisores humanos ya habían hecho**. En una frase: medía si Codex **adivina el pasado**, no si **aporta valor futuro**.

Como aproximación a la competencia de un revisor, no es una idea absurda —es el equivalente a un examen con respuestas conocidas—. Como puerta binaria con consecuencia de desinstalación, tiene cinco defectos, y cuatro de ellos son visibles sin conocer el resultado.

### 3.2 · Los cinco defectos

**1 · La medición irreversible iba primero y tenía derecho de veto sobre todo lo demás.** Este es el defecto grave. `§8.2` construyó una conjunción de tres condiciones en la que **una se mide una sola vez, al principio, y no se puede repetir por construcción** (una vez conocidas las respuestas, el examen está contaminado). El resultado es que, desde el minuto en que el calibrado salió 0/4, **la prueba era imposible de superar hiciera lo que hiciera la herramienta durante los meses siguientes**. Dos pasos de evidencia real quedaron sin poder alterar el veredicto. Un criterio de evaluación cuyo resultado queda fijado antes de recoger dos tercios de la evidencia no es un criterio de evaluación: es una decisión tomada de antemano con apariencia de medición. Si se hubiera visto esto el 2026-09-14, el umbral se habría escrito con las tres condiciones ponderadas, o con la Cobertura como indicador informativo y no como veto.

**2 · Tamaño de muestra sin significado.** Cuatro ítems, un *diff*, una ejecución, una formulación del *prompt*. Una puerta binaria sobre n = 4 y sin repetición no distingue una herramienta incapaz de una herramienta que ese día miró hacia otro lado. `ADR-041` y `ADR-042` fijaron el listón del proyecto en comprobar dependencias **con datos y no de memoria**; este umbral cumplía la forma de esa exigencia sin cumplir el fondo, porque el dato que producía no soportaba la conclusión que se le colgaba.

**3 · Medía a Codex por conocimiento que `ADR-049` da por sentado que no tiene.** Esto es una incoherencia interna del propio `ADR-049`. `§7.2` afirma, con razón y como premisa permanente, que **Codex no ha leído ni un solo ADR de este proyecto**, y de ahí deriva que un hallazgo suyo contrario a un ADR es ruido. Pero `§8.1` lo califica sobre cuatro ítems de los que, al menos tres, dependen de convención de proyecto y no de competencia general: `NOT VALID` + `VALIDATE CONSTRAINT` es disciplina *expand/contract* de `CLAUDE.md §9` y de la *skill* `migracion-segura`; que un tenant `eliminado` no pueda cambiar de nombre y de *slug* es una regla de negocio de `REQ-BO`; y `TenantContext::runFor()` en un trabajo en cola es el mecanismo de aislamiento de `ADR-033`, que un revisor externo no puede inferir del *diff* sin haber leído el ADR. **Un baremo no puede suspender a un candidato por ignorar justo aquello que el mismo documento declara que el candidato no puede saber.** (Valoración hecha sobre el enunciado de los cuatro hallazgos tal como `ADR-049 §8.1` los recoge, no sobre una relectura de los cuatro issues; el matiz no cambia la conclusión, pero conviene que conste de qué evidencia sale.)

**4 · Estaba en tensión con el eje que sí importaba.** `§8.2` punto 3 declara, con todas las letras, que si todo lo que Codex encuentra ya lo encontraban los tres revisores existentes, entonces *«no aporta valor: aporta una segunda copia del mismo valor, pagada con exposición de código a un tercero»*. Es decir: `ADR-049` sabía perfectamente que **el valor de un revisor adicional está en la ortogonalidad**. Y a la vez le exigía puntuar en un eje que premia el solape. No son estrictamente contradictorios —una herramienta podría encontrar las cuatro conocidas *y* otras nuevas—, pero sí están **mal alineados**: el eje de Cobertura penaliza precisamente el perfil de revisor que el eje de Aportación diferencial busca, y el resultado real fue la ilustración perfecta de esa tensión. Un revisor que mira otro plano del problema (concurrencia, `after_commit`, bloqueos de fila) saca 0 en «encuentra lo mismo que ellos» y saca la nota máxima en «encuentra lo que ellos no ven». Eso no es un mal revisor: es exactamente el revisor que se quería.

**5 · La consecuencia era inejecutable por quien tenía que ejecutarla.** Ya argumentado en `§1.4`: `§8.3` declaró automática una reversión que toca configuración versionada y compartida y que ninguna sesión puede acometer sin autorización del usuario. Escribir «sin discusión» en una cláusula que obliga a discutir es un defecto de redacción con consecuencias reales — genera exactamente la contradicción que ha habido que resolver con este ADR.

### 3.3 · Lo que de esto **no** se puede concluir

Tres cosas, para que nadie las deduzca de más:

1. **No se concluye que la prueba se aprobara.** No se aprobó. Se concluye que la regla que la juzgaba era un mal instrumento y se sustituye **hacia delante**, no hacia atrás.
2. **No se concluye que los umbrales escritos de antemano sean mala idea.** La intuición de `ADR-049 §8` —no evaluar por sensación— era correcta y sigue siéndolo. Lo que falló fue el diseño de uno de los tres ejes, no el principio. La lección transferible, que vale para cualquier evaluación futura de una herramienta en este proyecto, está en `§9`.
3. **No se concluye que la evidencia a favor sea abundante.** Son nueve hallazgos en dos pasos y tres bugs en un calibrado. Es una muestra **pequeña**, coherente y verificada, no una estadística. Afirmar «precisión perfecta» es cierto sobre n = 9 y no autoriza a esperar lo mismo dentro de diez pasos. `§10` lo recoge como riesgo.

---

## 4 · Opciones reales

Dada la decisión del usuario de mantener la herramienta, lo que quedaba por decidir es el régimen. Las opciones no teóricas eran tres:

| # | Opción | En qué consiste |
|---|---|---|
| **A** | **Retirar el marco de prueba por completo** | Se van `§8` y `§9`. La herramienta pasa a ser pieza permanente, sujeta solo al régimen ordinario de `§7.2` y a la caducidad de `§5.6` |
| **B** | **Sustituir por revalidación periódica** | Cada N pasos se reevalúa con un umbral nuevo si sigue aportando valor diferencial, con la retirada como consecuencia posible |
| **C** | **Retirar solo la parte de *valor* del marco, conservando la de *integridad* y el procedimiento de retirada** | Se van `§8.1`, `§8.2`, `§8.3` punto 1 y la reversión por defecto. Se quedan `§8.3` puntos 2-4 y `§9` íntegro, con otra condición de activación. La medición continua es la que `§7.2` ya obliga a hacer, sin ritual nuevo |

| | A · retirar todo | B · revalidación periódica | C · retirar solo el valor |
|---|---|---|---|
| **Coste de implementación en solitario** | Nulo | Bajo de escribir, **recurrente de cumplir**: un umbral nuevo, una cuenta de pasos, una evaluación que alguien tiene que acordarse de hacer | Nulo: todo lo que exige ya se hace hoy (`§7.2`) |
| **Mantenimiento a 3 años** | Ninguno, y ese es justo el problema: nada obliga a volver a mirar, y desaparece el camino de salida escrito | **El ritual se abandona a la tercera vez.** Es el argumento con el que `ADR-049 §4` descartó la opción D (el procedimiento manual repetitivo que no se sigue), aplicado aquí mismo | La procedencia de cada hallazgo queda en su issue y en `CHANGELOG.md`, que es donde ya está. El registro sobrevive porque no depende de que nadie se acuerde |
| **Impacto en las invariantes** | Ninguno sobre el producto. Sobre el proceso, **deja una dependencia sin procedimiento de retirada, contra `CLAUDE.md §9`** | Ninguno | Ninguno. `§7.2`, `§5.3` y `§9` de `ADR-049` siguen sosteniendo todo lo que sostenían |
| **Reversibilidad** | **Se pierde escrita**: hay que reconstruir `§9` el día que haga falta | Alta | **Alta e intacta**: `§9` sigue siendo seis órdenes y ningún dato que migrar |
| **Honestidad del régimen** | Correcta, pero incompleta: dice que la prueba acabó y calla qué pasa si un día conviene salir | **Falsa precisión**: un umbral binario nuevo sobre muestras igual de pequeñas repite el defecto de `§3.2` puntos 1 y 2 con otro nombre | Dice exactamente lo que hay: la prueba terminó, el uso es permanente y condicionado, y la salida está escrita |

**B se descarta por dos motivos independientes, cada uno suficiente.** El primero es de coherencia interna: instituir una revalidación periódica con umbral binario es reproducir, con otro calendario, el defecto que `§3.2` acaba de identificar —decidir sobre muestras de tres o cuatro hallazgos con una consecuencia catastrófica—, y hacerlo después de haberlo diagnosticado por escrito sería un error con los ojos abiertos. El segundo es de realismo: este es un proyecto **en solitario** con la atención como recurso más escaso, y `ADR-049 §4` ya razonó, para descartar la opción D, que *«un procedimiento manual repetitivo en un proyecto en solitario no se sigue»*. Ese argumento no deja de valer porque ahora me convenga lo contrario.

**A se descarta por una sola cosa, pero grave**: se lleva por delante `§9`. Retirar el procedimiento de reversión de una herramienta **en el momento exacto en que deja de ser temporal y pasa a ser permanente** es exactamente al revés de lo que hay que hacer. Una pieza que va a estar años tiene *más* necesidad de camino de salida escrito, no menos, y `CLAUDE.md §9` no distingue entre infraestructura y herramienta. Además `§8.3` contiene, mezcladas con el umbral, tres condiciones que **no tienen nada que ver con si la prueba salió bien**: que el plugin escriba en el árbol, que aparezca un dato real, o que el proyecto se archive o pierda la licencia. Esas son condiciones de integridad y de diligencia sobre una dependencia externa, y borrarlas junto al umbral sería una regresión de seguridad camuflada de simplificación.

---

## 5 · Decisión

**Opción C.** Concretamente, y todo lo que sigue es vinculante:

### 5.1 · La prueba de `ADR-049 §8` queda **cerrada, evaluada y retirada**

`ADR-049 §8` (`§8.1`, `§8.2` y `§8.3` punto 1) **queda sustituido por este ADR y deja de aplicar**. Con él se retiran:

- Los tres umbrales simultáneos de `§8.2` (Cobertura, Precisión, Aportación diferencial) como **puerta de decisión**.
- El calibrado de `§8.1`, que ya se ejecutó y no se repite.
- La cláusula *«el resultado por defecto es revertir»* y la reversión por fallo de umbral.
- El vocabulario de «prueba», «fase de prueba», «aprobado/reprobado» y «pasos de prueba» aplicado a esta herramienta. **La prueba terminó el 2026-09-21.**

El resultado de la prueba, para el registro histórico y para cualquiera que lea los dos ADR dentro de dos años, es el de `§1.2` y `§1.3`: **umbral formal no cumplido por el eje de Cobertura; Precisión y Aportación diferencial cumplidas; la herramienta se mantiene por decisión expresa e informada del usuario, con el criterio de `§3` sobre por qué ese eje no medía lo que importaba.** No se registra como éxito.

### 5.2 · `ADR-049 §9` se conserva íntegro; cambia solo lo que lo activa

El procedimiento de reversión de `ADR-049 §9` —los siete pasos, con la verificación por `git diff` sobre `.claude/settings.json` y la regla de que *«se ejecuta de verdad, no se simula»*— **sigue vigente palabra por palabra**. Lo único que cambia es su condición de activación:

- **Antes**: se disparaba por fallo del umbral de `§8.2`.
- **Ahora**: es el **procedimiento ordinario de retirada** de esta herramienta, y se ejecuta cuando concurra cualquiera de las condiciones de `§5.3`, o cuando el usuario decida retirarla por cualquier motivo y sin necesidad de justificarlo (`§5.6`).

Que `§9` siga escrito es la condición que hace defendible la permanencia. Lo que hacía tolerable la instalación en `ADR-049 §10` era *«la asimetría entre coste y riesgo (…) el coste de equivocarse es desinstalar un plugin que no escribió nada»*. Esa asimetría solo sigue siendo cierta mientras el camino de vuelta esté escrito y sea barato.

### 5.3 · Las condiciones de retirada **no relacionadas con el valor** siguen vivas y son inmediatas

Se re-hogan aquí los puntos 2, 3 y 4 de `ADR-049 §8.3`, **sin cambio de contenido**. Se ejecuta `§9`, sin discusión y sin necesidad de evaluación alguna, si ocurre cualquiera de estas:

1. **Se detecta una sola vez** que el plugin ha escrito en el árbol de trabajo, o que se ha ejecutado `/codex:rescue`, `/codex:transfer` o la puerta de revisión `Stop`. Una vez: no hay segunda oportunidad y no hay atenuantes.
2. **Aparece un dato personal real** en esta máquina o en este repositorio, en los términos de `ADR-049 §5.6` (cierre de `OPEN-11`, llegada del centro piloto, cualquier exportación). Los datos ficticios de `REQ-SEED-005` no cuentan. Esta cláusula es **automática y no admite ponderación**, y es la única de todo este ADR que no depende de ningún juicio.
3. **El proyecto `openai/codex-plugin-cc` queda archivado, cambia de licencia, o aparece un aviso de seguridad sin corregir.** Con 499 *issues* abiertas y sin *commits* desde el 2026-07-08 (`ADR-049 §1.2`), la respuesta a un fallo del plugin es desinstalar, no esperar a que alguien lo arregle.

Aquí sí procede decir «sin discusión», y con la corrección de `§1.4` hecha: estas tres se **detienen de inmediato** (no se invoca ningún comando `/codex:*` a partir de ese momento) y la ejecución material de `§9` se le pide al usuario en la misma sesión en que se detecten, porque toca configuración versionada. Detener no requiere autorización; desinstalar sí.

### 5.4 · La medición continua es la que `§7.2` ya obliga a hacer. **No se instituye ninguna revalidación periódica**

No hay umbral nuevo, no hay contador de pasos, no hay revisión cada N. El registro que sostiene cualquier decisión futura sobre esta herramienta es el que `ADR-049 §7.2` ya exige y que ya se está practicando:

1. **Todo hallazgo aceptado** entra por la tabla de severidad de `CLAUDE.md §5` como cualquier otro, y **su procedencia queda etiquetada** en el issue y en la entrada de `CHANGELOG.md` del paso (`/codex:review`, `/codex:adversarial-review`). Ya se hace así en las entradas de `1.6c` y `1.6d`.
2. **Todo hallazgo descartado** se anota en una línea, con el motivo, en la nota de cierre del paso. Esta parte no se relaja: es la que impide que el mismo descarte vuelva en cada ejecución y es el único dato que mide el coste real de la herramienta.

Eso **es** la evaluación continua, y su virtud es que no cuesta nada porque ya forma parte del trabajo. Si dentro de veinte pasos alguien quiere saber si Codex sigue aportando, la respuesta se obtiene contando issues etiquetados en `CHANGELOG.md`, no ejecutando un ritual que nadie mantuvo.

**Se retira expresamente** la obligación de `§8.2` de anotar en `memory.md` el recuento de propuestos/aceptados/descartados como registro de prueba: `memory.md` es estado vivo y se reescribe; lo que tiene que sobrevivir vive en el issue y en `CHANGELOG.md`.

### 5.5 · Estatus: herramienta **opcional y permanente**, nunca condición de cierre

Lo que el usuario llamó *«herramienta extra de verificación»* se concreta así, porque esa frase no basta para resolver los casos dudosos:

- **Permanente**: deja de tener fecha de caducidad por evaluación. Sigue teniéndola por dato real (`§5.3` punto 2).
- **Opcional**: se invoca cuando aporte, según el protocolo de la *skill* `revision-con-codex` y en el sitio del pipeline que fija `ADR-049 §7.1` (después de `implementer`, con la suite en verde y el trabajo commiteado, antes o en paralelo a los tres revisores). **No invocarla en un paso no es un incumplimiento de proceso**, no bloquea un cierre y no requiere justificarse. Es una diferencia de fondo con `db-reviewer`, `security-reviewer` y `doc-reviewer`, que sí son obligatorios donde hoy lo son.
- **«Verificación» no significa verificación en el sentido de `CLAUDE.md §10`.** No entra en la definición de terminado, ni hoy ni por acumulación de costumbre. `ADR-049 §3.3` lo excluyó y este ADR lo mantiene excluido: meterlo en `§10` exigiría **otro** ADR que sustituya a este y al `§3.3` de aquel.
- **Sin autoridad de bloqueo, igual que antes.** Un hallazgo suyo es un candidato; uno que contradiga un ADR vigente es ruido y se cierra citando el ADR (`ADR-049 §7.2`, intacto). Que haya acertado nueve de nueve **no le da autoridad**; solo hace más fácil olvidarse de contrastar, que es justo el riesgo de `§10` punto 2.

### 5.6 · Quién puede retirarla, y sin tener que justificarlo

**El usuario puede ordenar la retirada en cualquier momento, sin evaluación previa, sin umbral y sin motivo escrito**, ejecutándose `ADR-049 §9`. No hace falta un ADR nuevo para retirarla: este ADR ya contempla su retirada como una salida ordinaria y prevista, igual que `§5.3` la contempla como automática. Lo que sí exigiría un ADR nuevo es lo contrario: **ampliar** su alcance (permitir `rescue`/`transfer`, armar la puerta `Stop`, o meterla en `CLAUDE.md §10`).

Esta asimetría es deliberada y es la forma que toma aquí el principio de `CLAUDE.md` de preferir lo reversible: **salir es barato y no requiere ceremonia; entrar más adentro requiere decisión escrita.**

---

## 6 · Qué de `ADR-049` queda vigente y este ADR **no toca**

Enumerado para que quien lea los dos documentos juntos no tenga que deducirlo. Todo lo siguiente sigue en vigor **sin cambio alguno**:

| Sección de `ADR-049` | Contenido | Estado |
|---|---|---|
| `§1` | Contexto y comprobación de `CLAUDE.md §1` (licencia Apache-2.0, 7 *releases*, 68 días sin *commits*, 499 *issues* abiertas, datos del 2026-09-14) | **Vigente**. Los datos son del 2026-09-14 y no se actualizan aquí; `§5.3` punto 3 y `§10` punto 4 cubren su deterioro |
| `§2` | Las tres decisiones del usuario: credencial de capa gratuita, alcance de solo lectura, ámbito `project` | **Vigente**, con la salvedad de `§11`: la aceptación de la política de datos se dio *«para esta fase de prueba»* y la fase ha terminado |
| `§5.1` | Comandos permitidos y **prohibidos** (`/codex:rescue`, `/codex:transfer`), incluida la corrección de que **no existe barrera técnica**: el *sandbox* no impide que `rescue` escriba | **Vigente e intacto.** Es la norma más importante que sobrevive |
| `§5.2` | `sandbox_mode = "read-only"` + `approval_policy = "never"`; obligación de verificar la configuración **efectiva** y no el fichero; réplica en `~/.codex/config.toml` por el *bug* [#30001](https://github.com/openai/codex/issues/30001) | **Vigente e intacto** |
| `§5.3` | Las tres capas de protección; que la lista `deny` **no se puede replicar** en Codex y pasa a ser el **filtro de salida** de la máquina; la auditoría previa de los tres `.env` | **Vigente e intacto.** No se relaja ni gana excepciones |
| `§5.4` | La puerta de revisión `Stop` permanece **desactivada como norma** | **Vigente e intacto** |
| `§5.5` | Ámbito `project`; qué queda versionado y qué no | **Vigente** |
| `§5.6` | **Cláusula de caducidad automática ante un dato real** | **Vigente, y reforzada**: `§5.3` punto 2 de este ADR la repite como condición de retirada inmediata. Es la única caducidad que sobrevive a la permanencia |
| `§6` | `RNF-MANT-007` para una herramienta de desarrollo: la interfaz propia es **el protocolo documentado**, no código. Regla general derivada para toda herramienta futura | **Vigente e intacto**, y este ADR no lo reabre |
| `§7.1` | Sitio en el pipeline: después de `implementer`, con suite en verde y trabajo commiteado; **antes o en paralelo** a los tres revisores, **nunca en lugar de ninguno**; los tres siguen siendo obligatorios | **Vigente**, con la única precisión de `§5.5` de este ADR: la invocación de Codex es **opcional**, la de los tres no |
| `§7.2` | **Autoridad del veredicto: ninguna de bloqueo.** Triaje, registro de descartes con motivo, y que un hallazgo contrario a un ADR vigente es ruido y se cierra citando el ADR | **Vigente, intacto y ahora más importante que nunca** (`§10` punto 2). Es el régimen ordinario al que queda sujeta la herramienta |
| `§9` | Procedimiento de reversión (siete pasos) | **Vigente e intacto en su contenido**; cambia solo su condición de activación (`§5.2` de este ADR) |
| `§10`, `§11`, `§12` | Motivo, consecuencias y riesgo residual, incluido que `--help` **dispara una revisión real** y la prohibición de sintaxis exploratoria | **Vigentes**. `§10` y `§11` se leen ahora con el resultado de `§1.2` a la vista |
| `§15` | Hallazgos reportados y no corregidos | **Vigentes**, y `§14` de este ADR añade el estado actual del punto 2 (`SECURITY.md`/`PRIVACY.md`) |

---

## 7 · Qué cambia respecto a `ADR-049`, en una tabla

| Asunto | `ADR-049` decía | A partir de este ADR |
|---|---|---|
| **Naturaleza del uso** | Prueba acotada de tres puntos de medición | **Uso permanente**, sin fecha de caducidad por evaluación |
| **Umbral de éxito/fracaso** (`§8.1`, `§8.2`) | Tres condiciones simultáneas: Cobertura ≥ 2/4, Precisión ≤ 1 descartado por aceptado, ≥ 1 aportación diferencial | **Retirado.** No hay umbral ni puerta binaria (`§5.1`) |
| **Resultado de la prueba** | Pendiente de evaluar | **Evaluada y registrada** (`§1.2`): umbral formal **no cumplido** por Cobertura; Precisión y Aportación diferencial cumplidas |
| **Resultado por defecto** (`§8.3`) | *«Revertir»* si no hay evaluación escrita | **Retirado**: la prueba terminó y no hay nada que evaluar por defecto |
| **Retirada por fallo de umbral** (`§8.3` punto 1) | Reversión *«sin discusión»* | **Retirada.** No existe ya un umbral que pueda fallar |
| **Retirada por escritura en el árbol / `rescue` / `transfer` / puerta `Stop`** (`§8.3` punto 2) | Reversión a la primera detección | **Igual, vigente** (`§5.3` punto 1), con la precisión de que detener es inmediato y desinstalar lo autoriza el usuario |
| **Retirada por dato real** (`§8.3` punto 3, `§5.6`) | Caducidad automática | **Igual, vigente y reforzada** (`§5.3` punto 2). Es la única caducidad que queda |
| **Retirada por archivo/licencia/aviso de seguridad** (`§8.3` punto 4) | Reversión | **Igual, vigente** (`§5.3` punto 3) |
| **Procedimiento de reversión** (`§9`) | Consecuencia del fracaso de la prueba | **Conservado íntegro** como **procedimiento ordinario de retirada** (`§5.2`) |
| **Registro por ejecución** | Propuestos/aceptados/descartados en `memory.md`, como dato de la prueba | **Procedencia etiquetada en el issue y en `CHANGELOG.md`**; descartes con motivo en la nota de cierre (`§5.4`). Sin ritual nuevo |
| **Revalidación periódica** | No contemplada | **Expresamente descartada** (`§4`, `§12`) |
| **Obligatoriedad** | Implícita en el protocolo del paso | **Explícitamente opcional**: no invocarla no es incumplimiento y no bloquea un cierre (`§5.5`) |
| **Autoridad del veredicto** (`§7.2`) | Ninguna de bloqueo | **Sin cambio.** Ninguna de bloqueo |
| **Definición de terminado** (`CLAUDE.md §10`) | Codex fuera | **Sin cambio.** Codex sigue fuera |
| **Alcance de comandos** (`§5.1`) | Solo lectura; `rescue`/`transfer` prohibidos | **Sin cambio** |
| **Quién puede retirarla** | El umbral, automáticamente | **El usuario, en cualquier momento y sin justificarlo**, más las tres condiciones automáticas de `§5.3` |

---

## 8 · Motivo

Tres frases, como manda el formato de este proyecto:

1. **La regla estaba mal construida y la evidencia era buena, y ese orden importa.** No se mantiene la herramienta *a pesar* de haber fallado la prueba: se mantiene porque el eje que falló medía solape retrospectivo con revisores humanos sobre una muestra de cuatro ítems irrepetible y medida antes de recoger dos tercios de la evidencia (`§3.2`), mientras que los dos ejes que medían valor real —precisión del triaje y hallazgos que nadie más produjo— se cumplieron sobre trabajo real, con un issue de severidad Alta ([#224](https://github.com/pirexia/plataforma-educativa/issues/224)) y una veta de condiciones de carrera que terminó destapando una incidencia Crítica ([#209](https://github.com/pirexia/plataforma-educativa/issues/209)). Lo que se corrige es el instrumento, no el veredicto: el veredicto formal fue fracaso y así queda escrito.
2. **La permanencia obliga a conservar la salida, no a borrarla.** Todo lo que hacía tolerable instalar esto en `ADR-049 §10` —que no escribe, que no se despliega, que se quita en una tarde— sigue siendo cierto exactamente mientras `§9` siga escrito y las tres condiciones de integridad de `§5.3` sigan armadas. Retirar el marco de prueba y llevarse por delante el camino de vuelta habría convertido una decisión reversible en una permanente sin que nadie lo notara, que es la forma más común de acumular deuda de proceso.
3. **Un ritual de revalidación que nadie va a cumplir es peor que no tener ninguno**, porque da la falsa sensación de que hay control. `ADR-049 §4` ya usó ese argumento para descartar la opción D; aplicarlo aquí es coherencia, no conveniencia. La medición que sobrevive es la que ya se hace sin esfuerzo: cada hallazgo, con su procedencia, en su issue y en `CHANGELOG.md`.

---

## 9 · Lección transferible para la próxima herramienta que se evalúe

`ADR-049 §6` dejó una regla general derivada para toda herramienta de desarrollo futura. Este ADR deja otra, del mismo rango y por el mismo motivo —volverá a hacer falta—:

**Cuando se instale una herramienta a prueba con criterio escrito de antemano, el criterio cumple estas cuatro condiciones, o no se escribe:**

1. **Ninguna condición irrepetible tiene derecho de veto.** Si un eje solo se puede medir una vez (porque la respuesta queda conocida, porque el estado inicial no se recupera), es **indicador informativo**, nunca puerta. La puerta la forman los ejes que se pueden volver a medir.
2. **No se mide a la herramienta por conocimiento que el propio documento declara que no tiene.** Si el ADR afirma que la herramienta ignora las convenciones del proyecto, el baremo no puede consistir en convenciones del proyecto.
3. **Se mide valor marginal, no solape.** La pregunta correcta sobre un revisor adicional es «qué encuentra que los demás no», no «encuentra lo mismo que los demás». Un eje que premia el solape penaliza precisamente el perfil que se buscaba.
4. **Ninguna consecuencia se declara «automática» si quien la detecta no tiene autoridad para ejecutarla.** Si toca configuración versionada, credenciales o alcance del proyecto, la cláusula correcta es «se detiene el uso de inmediato y se eleva al usuario en la misma sesión», no «se revierte sin discusión».

---

## 10 · Riesgo residual de mantenerla sin el marco de prueba

Se enumeran para que estén escritos, no para tranquilizar. Los cinco se **aceptan**; el sexto es un punto abierto y va en `§11`.

1. **Desaparece la fecha que obligaba a volver a mirar.** El valor real de una prueba acotada no era el umbral: era que **algo con fecha se mira**. Una pieza permanente deja de mirarse. Contención: `ADR-049 §5.6` y `§5.3` punto 2 de este ADR siguen siendo una caducidad dura y automática ante el primer dato real, y el momento en que se dispara (cierre de `OPEN-11`, llegada del centro piloto) es exactamente el momento en que conviene revisar todo lo demás. **Aceptado.**
2. **La autoridad se cuela por la costumbre, y ahora con mejor excusa.** `ADR-049 §12.1` lo avisó cuando la herramienta no tenía historial. Ahora tiene nueve de nueve aceptados, y **eso empeora el riesgo en vez de mejorarlo**: cuanto más acierta, más cuesta parar a contrastar un hallazgo contra el ADR que lo contradice. Contención: `§7.2` intacto, la obligación de anotar descartes con motivo, y la advertencia explícita de `§5.5` de este ADR — el historial no otorga autoridad. **Aceptado.**
3. **La muestra es pequeña.** Nueve hallazgos en dos pasos no predicen los próximos veinte. Que la precisión decaiga es perfectamente posible, y cuando decaiga el coste lo paga la atención del usuario. Contención: es visible sin instrumentación nueva, porque los descartes se anotan uno a uno (`§5.4`), y el usuario puede retirarla en cualquier momento sin justificarse (`§5.6`). **Aceptado.**
4. **Los datos de diligencia de `ADR-049 §1.2` envejecen.** El 2026-09-14 eran 68 días sin *commits* y 499 *issues* abiertas; hoy son ~75 días más y nadie los ha vuelto a mirar. Para una prueba de dos pasos daba igual; para una pieza permanente, menos. Contención: `§5.3` punto 3 (archivo, licencia, aviso de seguridad) sigue armado, y la respuesta ante un fallo del plugin sigue siendo desinstalar, no esperar. **Aceptado**, con la anotación de que conviene volver a comprobar esos datos la próxima vez que se toque el asunto.
5. **La exposición residual de secretos de desarrollo de `ADR-049 §5.3` deja de ser «de una prueba de dos pasos» y pasa a ser continua.** El propio `ADR-049` la calificó de *«real, acotada y proporcionada a una prueba de dos pasos»*. Esa proporcionalidad cambia al hacerla indefinida. Lo que no cambia es el fondo: por `ADR-030` este entorno no puede contener datos reales, y por `ADR-037` sus credenciales no se reutilizan en el alojamiento. **Aceptado**, sin fingir que sea cero, y con la obligación de que la auditoría de los tres `.env` de `§5.3` capa 3 se repita ante cualquier máquina nueva o cualquier credencial nueva que entre en el árbol.

---

## 11 · Punto abierto que la decisión del usuario **no** resuelve y no decide `architect`

**La aceptación de la política de datos de `ADR-049 §2.1` se dio expresamente *«para esta fase de prueba»*, y este ADR termina esa fase.**

El texto es literal: el usuario aceptó la capa gratuita de la suscripción ChatGPT, advertido de que *«salvo desactivación expresa en su cuenta de OpenAI, el contenido puede usarse para entrenar modelos»*, **«explícitamente para medir si aporta valor antes de decidir si merece la pena estudiar la política de datos con más cuidado»**. Ese antes ha llegado: ya se ha medido, y la respuesta ha sido que sí aporta.

Luego la pregunta que quedaba aplazada **está ahora sobre la mesa y no la puede responder `architect`**, porque depende de la cuenta del usuario, de su suscripción y de cuánto le importa que el código fuente de este proyecto pueda usarse para entrenar:

> Con uso **indefinido** en vez de una prueba de dos pasos, ¿se mantiene la capa gratuita tal cual, se desactiva el entrenamiento en la cuenta de OpenAI, o se pasa a una capa de pago con otra política?

Las tres son legítimas y ninguna bloquea nada hoy. Lo que **no** es legítimo es dejarlo sin responder y que dentro de un año siga saliendo código bajo unos términos que se aceptaron para una prueba que terminó en septiembre de 2026. **Recomendación de `architect`, que no es decisión**: comprobar si la cuenta permite desactivar el entrenamiento y, si lo permite, hacerlo — es gratis, no cambia nada del funcionamiento y elimina el único coste de `ADR-049 §11` que crecía con el tiempo.

Este punto **no bloquea la aceptación de este ADR** ni el uso de la herramienta mientras tanto. Queda aquí escrito, y si el usuario quiere que se siga formalmente, corresponde abrirlo como issue o como `OPEN-NN` en la sección 18 del documento de requisitos — decisión suya, no de `architect`.

---

## 12 · Alternativas descartadas y por qué

| Alternativa | Por qué no |
|---|---|
| **Ejecutar `ADR-049 §9` tal cual y desinstalar** | Era lo que mandaba la letra, y se le presentó al usuario como primera opción con los datos completos. Descartada por decisión expresa e informada del usuario (`§1.5`), y con un argumento de fondo que este ADR hace suyo: se estaría retirando por fallar un eje que medía si la herramienta adivina el pasado, justo después de que encontrara un bug de severidad Alta que tres revisores no vieron |
| **Reinterpretar `§8.2` para declarar la prueba superada** («la Cobertura no era aplicable», «dos de tres bastan») | La peor salida de todas. Es cambiar un ADR en silencio, contra `CLAUDE.md §11`, y deja el precedente de que un umbral escrito se relee hasta que dé el resultado que conviene. Si la regla estaba mal, se **sustituye** por escrito y se dice que estaba mal; no se estira |
| **Retirar `§8` y `§9` por completo** (opción A) | Deja una herramienta permanente sin procedimiento de retirada escrito, contra `CLAUDE.md §9`, y se lleva por delante las tres condiciones de integridad de `§8.3` puntos 2-4, que nunca tuvieron que ver con si la prueba salía bien |
| **Revalidación periódica cada N pasos con umbral nuevo** (opción B) | Repite, con otro calendario, los defectos 1 y 2 de `§3.2`: umbral binario sobre muestras de tres o cuatro hallazgos con consecuencia de desinstalación. Y es un procedimiento manual recurrente en un proyecto en solitario, que `ADR-049 §4` ya razonó que no se sigue a la tercera vez |
| **Repetir el calibrado sobre otro PR ya cerrado para «darle otra oportunidad» a la Cobertura** | No mide nada nuevo: el defecto del eje es de diseño (`§3.2`), no de qué *diff* se eligió. Y consumiría atención del usuario en producir un dato que, salga como salga, no debería decidir nada |
| **Hacer obligatoria la invocación de Codex en cada paso** | Lo convertiría de facto en condición de cierre sin decirlo, erosionando `CLAUDE.md §10` por la puerta de atrás. El usuario pidió una herramienta *extra*, no un cuarto revisor obligatorio, y `ADR-049 §3.3` exige un ADR propio para tocar `§10` |
| **Aprovechar este ADR para permitir `/codex:rescue` en ramas de prueba**, ya que la herramienta ha demostrado criterio | Acertar en revisión no dice nada sobre escribir en el árbol, y la protección sigue siendo inexistente: `ADR-049 §5.1` verificó que el *sandbox* **no** lo impide. Es el escenario del issue #150 sin *worktree* aislado. Sigue prohibido y este ADR no lo reabre |
| **Armar la puerta de revisión `Stop`, ya que el triaje ha salido barato** | Los dos motivos de `ADR-049 §5.4` siguen intactos: consumo de dos cuotas en bucle, y supeditar `CLAUDE.md §10` al veredicto de un tercero que no ha leído ningún ADR. Sigue desactivada como norma |
| **Dejar la decisión registrada solo en `memory.md`** | `memory.md` se reescribe cada sesión, y `ADR-049 §8` seguiría diciendo para siempre que esto debió desinstalarse. `CLAUDE.md §11` obliga a un ADR |
| **No escribir nada y seguir usando la herramienta** | Es lo único que estaba descartado de entrada: mantener viva una contradicción entre lo escrito y lo que se hace es exactamente lo que `CLAUDE.md §0` prohíbe |

---

## 13 · Reparto de la decisión: qué es del usuario y qué de `architect`

Para que dentro de dos años se sepa quién decidió qué, como hicieron `ADR-048 §10` y `ADR-049 §14`:

**Decidido por el usuario** (2026-09-21, con los datos de `§1.2` a la vista):

1. **No desinstalar.** La herramienta se mantiene.
2. **Su papel**: *«herramienta extra de verificación»*.

**Decidido por `architect`** en este ADR, porque la frase del usuario no lo resuelve y alguien tenía que hacerlo:

3. Qué se retira exactamente de `ADR-049` y qué no (`§5.1`, `§6`, `§7`).
4. Que **`§9` se conserva íntegro** y pasa a ser el procedimiento ordinario de retirada (`§5.2`).
5. Que las tres condiciones de integridad de `§8.3` puntos 2-4 **siguen armadas** (`§5.3`).
6. Que **no se instituye revalidación periódica** y la medición continua es la de `§7.2` (`§5.4`).
7. Que la invocación es **opcional** y **no entra en `CLAUDE.md §10`** (`§5.5`).
8. Que el usuario puede retirarla cuando quiera sin justificarlo, pero ampliar su alcance exige ADR nuevo (`§5.6`).
9. El juicio de `§3` sobre por qué el umbral de Cobertura estaba mal construido, y la regla derivada de `§9` para futuras evaluaciones de herramientas.

**Sometido al usuario y no decidido aquí**: el punto abierto de `§11` (política de datos con uso indefinido).

---

## 14 · Hallazgos fuera del ámbito de escritura de este ADR, reportados y no corregidos

`architect` escribe únicamente en `docs/adr/` y en la sección 18 del documento de requisitos. Lo siguiente queda **reportado y sin tocar** (`CLAUDE.md §5`, issue [#150](https://github.com/pirexia/plataforma-educativa/issues/150)):

1. **La *skill* `revision-con-codex` describe una prueba que ya ha terminado** (severidad **Media**, como incoherencia de documentación con una decisión vigente). Su sección *«Evaluación de la prueba (`ADR-049 §8`)»* dice todavía que *«mientras la prueba esté abierta (calibrado sobre PR #204 + dos pasos siguientes), cada ejecución se registra en `memory.md`»* y que *«el resultado por defecto, si no hay evaluación escrita al cerrar el segundo paso de prueba, es revertir»*. Ambas frases quedan derogadas por `§5.1` y `§5.4` de este ADR y deben sustituirse por el régimen permanente. **No la edita `architect`.**
2. **`CLAUDE.md §2` afirma que la prueba está en curso** (severidad **Media**, mismo motivo): *«Prueba acotada y reversible: `ADR-049 §8` fija su criterio de éxito/fracaso»*. Hay que sustituir esa referencia por `ADR-050`, indicando que la prueba terminó, que el uso es permanente y **opcional**, y que las condiciones de retirada son las de `§5.3`. **No lo edita `architect`.**
3. **La sección 20.2 (historial de cambios) y la cabecera de versión del documento de requisitos necesitan su entrada para `ADR-050`** (versión 3.2.5). La sección 20 **está fuera del ámbito de escritura de `architect`**, que solo alcanza la sección 18; la fila del índice de ADR sí se añade. Corresponde a la sesión orquestadora.
4. **`CHANGELOG.md` necesita la entrada de esta decisión**, incluida la evaluación final de la prueba de `§1.2` con sus cifras. **No lo edita `architect`.**
5. **Sigue abierto el punto 2 de `ADR-049 §15`**: `SECURITY.md` y `PRIVACY.md` no contemplan la salida de código fuente hacia una herramienta de terceros en el entorno de desarrollo (severidad **Baja** entonces). **Este ADR lo agrava**: lo que era una prueba de dos pasos es ahora una práctica permanente del proyecto, y un documento de seguridad que no menciona una salida permanente de código hacia un tercero está incompleto, no solo desactualizado. Corresponde a `doc-reviewer` en el siguiente cierre de fase, por la regla 7 de `CLAUDE.md §6`.
6. **Hueco de proceso ya consumado, anotado para que no se repita**: la Precisión del calibrado del 2026-09-14 **no se registró en su momento** y hubo que reconstruirla después, pese a que `ADR-049 §8.2` lo exigía expresamente (*«sin ese registro no hay evaluación posible»*). Salió bien por poco. La contención hacia delante es `§5.4` de este ADR: el registro vive en el issue y en `CHANGELOG.md`, no en la memoria de la sesión.
