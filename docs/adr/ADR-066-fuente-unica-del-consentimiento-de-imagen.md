# ADR-066 · Fuente única del consentimiento de imagen

**Estado**: **ACEPTADA** (2026-10-27, ratificada por el usuario: `OPEN-066-01` calcular en origen, `-02` un texto nuevo no invalida los `autorizo` en `1.14-b`, `-03` recurso `texto_legal`, `-04` solo cuentan las decisiones posteriores a `legal_guardian_verified_at`, `-05` criterio jurídico sobre 14 y 18 años como condición previa a datos reales; el usuario confirma además los **dos niveles** `web` y `redes_sociales`, sin separar por red social). Prerrequisito de `1.14-b` fijado por la aprobación de la especificación de `1.14` (`OPEN-FAM-UNIT-09`).
**Fecha**: 2026-10-27
**Paso**: `1.14 · REQ-FAM-UNIT`, entrega `1.14-b` (`docs/modulos/REQ-FAM-UNIT/funcional.md §3.8`, `§4.6`-`§4.8`, `§7.3`).
**Afecta a**: `REQ-FAM-UNIT-004`, `REQ-AUT-002`, `REQ-AUT-003`, `REQ-PRIV-002`, `INV-014`, `RSEC-GDPR-011`, `REQ-INF-008`, y a todo módulo que publique o sirva imágenes de alumnos (`REQ-COM`, `REQ-INF`, `REQ-WEB`, `REQ-EXTRA`, `REQ-FAM-PORTAL`).
**Se apoya en**: `ADR-032` (precedente: lista maestra única de autorizados de recogida), `ADR-034 §1`/`§6` (facetas sobre `people`; tablas de solo anexar), `ADR-035` (datos personales en auditoría), `ADR-038 §7` (cambios compatibles), `ADR-044 §4.7` (sin caché de autorización entre peticiones), `ADR-048` (contrato síncrono en el módulo dueño; no se construye superficie sin consumidor), `ADR-062` (contenido del centro traducible).
**No sustituye ningún ADR.** Concreta `OPEN-FAM-UNIT-08` (aprobada) y resuelve `OPEN-FAM-UNIT-09` (aprobada en su recomendación, con este ADR como condición).

---

## 1 · Contexto

El consentimiento de uso de imagen de un alumno aparece en tres requisitos de tres módulos distintos:

| Requisito | Módulo | Fase | Qué dice |
|-----------|--------|------|----------|
| `REQ-FAM-UNIT-004` | Unidad familiar | 1 (MUST) | Lo define: dos niveles independientes (`web`, `redes_sociales`), tres estados, bloqueo automático de la publicación, revocación con efecto inmediato, auditoría, informe por unidad familiar |
| `REQ-AUT-002` | Autorizaciones | 2 (SHOULD) | «Autorización de uso de imagen (detallada en `REQ-FAM-UNIT-004`)» dentro de las autorizaciones generales, que tienen plantillas configurables y caducidad |
| `REQ-PRIV-002` | Privacidad | 2 (MUST) | «Registro centralizado de **todos** los consentimientos: imagen, …», con versión del texto aceptado, fecha, hora, IP y canal, y revocación con propagación a los módulos afectados |

Y una invariante que depende de él en todo el producto: `INV-014` («ninguna imagen de un alumno se publica sin comprobar previamente su consentimiento vigente»).

El riesgo es exactamente el que `ADR-032` resolvió para la recogida de menores: **tres sitios donde guardar el mismo dato**. Si `AUT` ofrece una plantilla «uso de imagen» con su propia respuesta, y `PRIV` un registro centralizado con su propia fila, el día que una familia retira el consentimiento en uno de ellos y el publicador consulta otro, se publica la foto de un menor sin consentimiento, y el registro dirá que estaba autorizada. Es un incidente de `INV-014`, de severidad crítica según `CLAUDE.md §5`.

Hay que decidirlo **ahora**, antes de `1.14-b`, porque es la primera entrega que escribe el dato, y porque la especificación aprobada de `1.14` deja abiertos en el modelo de datos dos puntos que dependen de esta decisión: la columna `consent_text_version_id` y la columna `kind` de `terms_versions` (`datos.md §1.6`, `§1.8`).

### 1.1 Hechos verificados (rama `feature/REQ-FAM-UNIT-1-14b-consentimiento-imagen`, sobre `fdfff3b`)

- No existe en el código ninguna clase, tabla ni permiso de consentimiento de imagen: `1.14-a` no los declaró a propósito (`funcional.md §15`, D-6).
- `guardian_student_links` ya tiene `is_legal_guardian`, `legal_guardian_verified_at`/`_by`, `status` (`activo`/`revocado`) y `deleted_at` (`datos.md §1.4`). Es todo lo que la regla de combinación necesita para saber quién decide.
- `REQ-AUT` y `REQ-PRIV` son de fase 2 y no tienen especificación. Ninguno de los dos existe todavía en el código.
- Ningún módulo publica imágenes de alumnos todavía. Los primeros serán `REQ-INF` (`1.18`) y `REQ-COM` (`1.19`).

---

## 2 · Qué NO decide este ADR

- **Reglas jurídicas.** Quién ostenta la patria potestad, si el mayor de 14 años consiente por sí mismo (`LOPDGDD` art. 7), qué pasa a los 18 años, o si un cambio del texto obliga a recabar de nuevo el consentimiento. Este ADR fija **dónde** se aplicaría una regla así y qué hace el sistema mientras no exista. La regla la tiene que dar el asesor jurídico, no el código (`OPEN-FAM-UNIT-18`, `§7.4`, `§7.5`).
- **El resto de consentimientos de `REQ-PRIV-002`** (comunicaciones comerciales, evaluación psicopedagógica, salidas, cesión a terceros). Su dueño lo decidirá la especificación de `REQ-PRIV`. Este ADR solo fija que la imagen **no** se mueve allí.
- **Cómo marca un publicador sus fotos** (etiquetado de alumnos en una imagen, reconocimiento, galerías). Es de cada publicador. Aquí se fija solo qué consulta y cuándo.
- **El muro de condiciones de uso** (`OPEN-FAM-UNIT-12`, `1.14-c`). Este ADR toca `terms_versions` solo en lo que comparte con el texto del consentimiento.

---

## 3 · Opciones reales

| | Dueño del dato | Cómo lo usan los demás |
|---|---|---|
| **A** | `fam_unit` (`REQ-FAM-UNIT-004`), desde `1.14-b` y de forma permanente | `AUT`, `PRIV` y los publicadores leen por interfaz y escriben, si alguna vez lo necesitan, por un contrato síncrono del dueño. Nadie guarda copia |
| **B** | `fam_unit` provisionalmente; en fase 2 se **migra** a `PRIV` como registro general de consentimientos | `PRIV` pasa a ser el dueño de todos los consentimientos y `fam_unit` lo consulta |
| **C** | Un registro genérico de consentimientos en `Core` desde ahora (tipo, finalidad, base legal, texto, decisiones) | Todos los módulos, `fam_unit` incluido, escriben y leen ahí |

Descarto de entrada una cuarta, «cada módulo guarda lo suyo y se sincronizan por eventos», porque es justo el fallo de `ADR-032`: dos listas que divergen en cuanto un evento se pierde o llega tarde.

---

## 4 · Evaluación

| Criterio | A · `fam_unit` permanente | B · migrar a `PRIV` en fase 2 | C · registro genérico en `Core` |
|---|---|---|---|
| **Coste ahora (una persona)** | Bajo: la especificación de `1.14-b` ya está escrita sobre esta forma | Igual que A ahora, más una migración de datos de consentimiento (evidencia legal) entre módulos en fase 2 | Alto: un modelo genérico de consentimientos sin catálogo de bases legales (`OPEN-13` sigue abierta) ni requisitos de `PRIV` especificados. Se diseña a ciegas |
| **Mantenimiento a 3 años** | Una implementación y una interfaz. La regla de combinación vive junto a los vínculos que la alimentan | Dos dueños sucesivos y una migración de evidencia que hay que poder probar ante un litigio | Un modelo abstracto con un único caso concreto (imagen). Las abstracciones con un solo caso se rompen con el segundo |
| **Invariantes** | `INV-014` con una sola fuente. `INV-007`: los demás leen por interfaz. `INV-008`: el «quién representa legalmente» ya está aquí | Durante la migración hay dos fuentes, o una ventana sin ninguna: el peor momento posible para `INV-014` | `INV-007`: `Core` tendría que conocer vínculos tutor ↔ alumno (de `fam_unit`) para combinar decisiones, o `fam_unit` tendría que escribir en `Core` cosas de su dominio |
| **Reversibilidad** | Alta. Si `PRIV` necesita ser dueño algún día, se hace con un ADR nuevo y una migración; nada de A lo impide | Baja: compromete hoy una migración de evidencia legal que nadie ha pedido | Baja: un esquema genérico en `Core` con datos reales es caro de deshacer |

**La razón de fondo** por la que A gana: la regla de combinación (`OPEN-FAM-UNIT-08`) depende de **quién es representante legal de cada alumno y con qué vínculo**. Ese dato es de `fam_unit`. Poner el consentimiento en otro módulo obliga a ese módulo a preguntar a `fam_unit` en cada cálculo. La dependencia no desaparece: solo se invierte y se alarga.

---

## 5 · Decisión: opción A

### 5.1 Dueño único

`fam_unit` (`REQ-FAM-UNIT-004`) es la **única fuente de verdad** del consentimiento de imagen de un alumno: las decisiones, el texto al que se refieren y el estado vigente. Ningún otro módulo guarda decisiones, estados, marcas ni copias de él, ni en tablas propias, ni en columnas de sus entidades, ni en caché entre peticiones.

### 5.2 El estado vigente se calcula desde la fuente; no hay proyección

El estado vigente (`autorizo`, `no_autorizo`, `pendiente`) de un alumno en un nivel se **calcula en cada consulta** a partir de dos datos de `fam_unit`: los vínculos vivos del alumno y las decisiones registradas. **No se mantiene una tabla de estado vigente** (`image_consents`, `datos.md §1.7`, desaparece).

Esto se aparta de la especificación aprobada y es la decisión de este ADR que más conviene revisar. Motivo:

1. **El estado no depende solo de las decisiones, también de quién decide.** Con la regla de unanimidad (§5.4), si un alumno tiene un único representante legal que decidió `autorizo`, su estado es `autorizo`. Cuando secretaría da de alta el vínculo del segundo progenitor con representación legal, el estado tiene que pasar a `pendiente` **en ese instante**. Con una proyección, eso obliga a recalcular el estado desde el servicio de vínculos en cada alta, baja, restauración y cambio de `is_legal_guardian`, y desde la baja de un tutor (`RN-FAM-UNIT-12`). La especificación solo lo recalcula al registrar una decisión (`RN-FAM-UNIT-44`). Un solo disparador olvidado deja un `autorizo` caducado, y eso es una foto publicada sin consentimiento. Calcularlo desde la fuente no puede quedarse desfasado.
2. **La proyección no tiene fila para quien nunca decidió** (`datos.md §1.7`: «la fila se crea con la primera decisión»). El listado «quién no ha decidido», que es para lo que existe el índice `(tenant_id, level, state)`, no vería a los alumnos sin ninguna decisión, que son justo los `pendiente` que el centro tiene que perseguir.
3. **El volumen no lo justifica.** Decenas de decisiones por alumno a lo largo de su escolaridad, cientos o pocos miles de alumnos por centro (`datos.md §3`). Una consulta con `DISTINCT ON` sobre un índice adecuado resuelve un lote de alumnos en una sola lectura.
4. **Es lo más reversible.** Añadir una proyección más adelante, si una medición lo pide, es un cambio aditivo. Quitar una con datos y consumidores no lo es.

Consecuencia: desaparecen el bloqueo de fila de `RN-FAM-UNIT-44` y el riesgo de carrera que lo justificaba. Dos decisiones simultáneas son dos filas, y el cálculo siempre ve las dos.

### 5.3 Interfaz pública: `ImageConsentPolicy`

Vive en `App\Modules\FamUnit\Domain` y se registra desde el `ServiceProvider` del módulo (`funcional.md §7`). Contrato funcional (las firmas exactas son de implementación):

| Consulta | Devuelve |
|----------|----------|
| `isPublishable(nivel, alumnos)` | `true` solo si **todos** los alumnos tienen estado `autorizo` en ese nivel |
| `blockingAmong(nivel, alumnos)` | Ids de persona de los alumnos que no están en `autorizo` |

Reglas del contrato:

1. **Entrada**: el nivel es un enumerado público del propio `Domain` (`web`, `redes_sociales`), y los alumnos van como ids internos de persona (`people.id`, igual que `FamilyMembership`). El centro es el del contexto de tenant, nunca un parámetro.
2. **Una lista vacía es un error de programación** y lanza una excepción; **no** se interpreta como «publicable». Una foto sin alumnos identificables no necesita consultar; un publicador que pasa una lista vacía por error no debe publicar.
3. **Por defecto se deniega.** «No publicable», con el alumno entre los que bloquean, si:
   - el alumno no tiene ninguna decisión;
   - no tiene ningún representante legal con vínculo vivo (§5.4);
   - no existe, o es de otro centro;
   - el módulo `fam_unit` no está contratado (`funcional.md §9`).
4. **Una consulta por llamada**, sin N+1, sea cual sea el número de alumnos. Se memoiza como mucho dentro de la petición, nunca entre peticiones (`ADR-044 §4.7`, `RN-FAM-UNIT-34`).
5. **Sin efectos**: no escribe, no audita lecturas (no es dato de categoría especial, `permisos.md §5`) y no emite eventos.

La interfaz **no** recibe edad, curso ni tipo de publicación más allá del nivel. Si una regla jurídica futura cambia quién decide (§5.6), cambia el cálculo **dentro** de `fam_unit` y el contrato sigue igual. Es la ventaja de tener una sola fuente.

### 5.4 Quién decide y regla de combinación (`OPEN-FAM-UNIT-08`, aprobada)

**Quién decide** de un alumno: los tutores con un vínculo **vivo** (`deleted_at IS NULL`) con `is_legal_guardian = true` sobre ese alumno, **estén `activo` o `revocado`**. Es la recomendación aprobada de `OPEN-FAM-UNIT-08`: revocar quita el acceso, no la patria potestad (`RN-FAM-UNIT-37`). Un tutor sin representación legal (abuelo, apoderado) no decide.

**Decisión vigente de cada uno**: su última decisión para ese alumno y nivel, por `(occurred_at, id)`.

**Estado vigente**:

| Condición (sobre quienes deciden) | Estado |
|---|---|
| Alguno tiene como decisión vigente `no_autorizo` | `no_autorizo` |
| Hay al menos uno y **todos** tienen como decisión vigente `autorizo` | `autorizo` |
| Cualquier otro caso: nadie decide, o alguno no ha decidido todavía | `pendiente` |

`pendiente` y `no_autorizo` bloquean igual (`INV-014`). Los dos niveles son independientes (`RN-FAM-UNIT-47`). Una decisión de un tutor que ya no está entre quienes deciden (vínculo dado de baja o sin representación legal) se conserva como evidencia, pero no cuenta.

**Cómo se registra la decisión de un tutor revocado.** El tutor revocado no alcanza al alumno con su ámbito `unidad_familiar` y no puede registrarla él mismo (`CA-FAM-UNIT-053`). La registra el centro en su nombre por el canal `presencial`, con `consentimiento_imagen.actualizar` de ámbito `todos` **y** `revocacion_tutor.leer`. Sin este último permiso, el tutor revocado es invisible (`RN-FAM-UNIT-40` de `§5.3`) y la petición responde igual que con un tutor no vinculado.

> **Falta validación jurídica.** La aprobación del usuario del 2026-10-26 condiciona la unanimidad, y que el revocado siga contando, a validarla con el DPO o la asesoría del centro piloto **antes de cargar datos reales**. Este ADR no levanta esa condición: la repite. Si la asesoría la cambia, cambia la tabla de este apartado (dentro de `fam_unit`, sin tocar el contrato de §5.3) y hace falta un ADR que sustituya a este en esa parte.

### 5.5 Texto del consentimiento versionado (`OPEN-FAM-UNIT-09`)

1. **Mismo mecanismo que las condiciones de uso**, como aprobó la recomendación de `OPEN-FAM-UNIT-09`: la tabla `terms_versions` con una columna `kind` **desde su creación**: `condiciones_uso`, `consentimiento_imagen_web` y `consentimiento_imagen_redes`. Un tipo por nivel, porque los dos niveles son consentimientos distintos con finalidades distintas. Texto y título traducibles (`ADR-062`), redactados por el centro. El agente `traductor` no los toca.
2. Como mucho **una versión `publicada` por centro y tipo**. La numeración es correlativa por centro y tipo. Una versión `publicada` o `retirada` no se edita ni se borra; solo se puede borrar un `borrador`. Una versión a la que apunte una decisión es evidencia (§5.8).
3. **Cada decisión guarda la versión del texto** con la que se tomó (`consent_text_version_id`), como pide `REQ-PRIV-002`.
4. **`autorizo` exige una versión**: no se puede autorizar sin texto publicado para ese nivel. Si no lo hay, la petición se rechaza y el estado sigue `pendiente`. Un consentimiento sin texto no está informado, y así el defecto vuelve a ser denegar. **`no_autorizo` no la exige**: negarse no necesita información previa y no debe poder bloquearse por falta de texto. Se registra la versión si existe. En base de datos: `CHECK (decision = 'no_autorizo' OR consent_text_version_id IS NOT NULL)`.
5. Por el canal `portal`, la versión es la publicada en ese momento. Por el canal `presencial` (papel firmado antes), el centro puede indicar una versión `publicada` o `retirada` del mismo tipo, nunca un `borrador`. Por defecto, la publicada.
6. **Publicar una versión nueva no altera ningún estado.** Las decisiones anteriores siguen contando con la versión a la que se refieren, y el informe y el histórico la muestran, para que el centro sepa qué familias decidieron sobre un texto anterior. Si un cambio de texto debe invalidar los `autorizo` anteriores es una cuestión jurídica (`OPEN-066-02`). Si la respuesta es sí, con el cálculo en origen (§5.2) basta con que solo cuenten las decisiones tomadas desde una versión mínima: es un filtro en la consulta, sin trabajos masivos. No se construye hasta que haya respuesta (`ADR-048`).

### 5.6 Revocación de acceso, mayoría de edad y 14 años

- **Revocación de acceso del tutor** (`REQ-FAM-UNIT-002`): no cambia el estado de imagen ni saca al tutor de quienes deciden (§5.4). Sí le impide ver el estado y el histórico de ese alumno, y registrar decisiones por sí mismo. A quien ve el histórico con ámbito `unidad_familiar` no se le muestran las decisiones de tutores cuyo vínculo con ese alumno esté revocado. Cuentan para el estado, pero mostrarlas revelaría la existencia de esa persona y su relación con el centro (`RN-FAM-UNIT-36`).
- **14 años (`LOPDGDD` art. 7) y 18 años**: **sin regla** (`OPEN-FAM-UNIT-18` sigue abierta y la aprobación del usuario fija no inventarla). Mientras no la haya, quienes deciden son los de §5.4 sea cual sea la edad del alumno. Este ADR fija solo dónde iría la regla: en la definición de quién decide de §5.4, dentro de `fam_unit`, sin cambiar el contrato de §5.3. Si la regla hace que decida el propio alumno, `image_consent_decisions.guardian_id` tendrá que admitir otro tipo de decisor. Será un cambio *expand* (columna nueva y relajar el `NOT NULL`) con su propia especificación. **No se prepara ahora** (`ADR-048`). Lo que sí se dice es que el comportamiento actual con alumnado de 14 años o más (ESO y Bachillerato) puede no ser conforme, y por eso se propone como condición previa a datos reales (`OPEN-066-05`).

### 5.7 Qué hacen los demás módulos

| Módulo | Qué hace | Qué **no** hace |
|---|---|---|
| **Publicadores** (`REQ-COM`, `REQ-INF`, `REQ-WEB`, `REQ-EXTRA`, galerías de `REQ-FAM-PORTAL`) | Consultan `ImageConsentPolicy` **al publicar y al servir** cada imagen (`funcional.md §7.3`). Una foto de grupo solo es publicable si lo son todos los alumnos identificables (`REQ-INF-008`). El «marcado automático» de fotos se calcula al servir | Guardar el estado o una marca de consentimiento como base de la decisión de publicar o mostrar. Esperar un evento para retirar una imagen |
| **`REQ-AUT`** (`REQ-AUT-002`, `-003`) | Si muestra la autorización de imagen junto a las demás autorizaciones generales, o en el historial de `REQ-AUT-003`, la **lee** de `fam_unit` | Ofrecer una plantilla, un tipo de autorización o una respuesta de «uso de imagen». Sus plantillas configurables (`REQ-AUT-002`) no pueden crear un consentimiento de imagen paralelo, ni general ni por actividad. La caducidad de `REQ-AUT-002` no se aplica a la imagen (`OPEN-FAM-UNIT-19`: no se renueva por curso) |
| **`REQ-PRIV`** (`REQ-PRIV-002`) | Su registro centralizado **agrega** el consentimiento de imagen leyéndolo de `fam_unit`. Los campos que pide (versión del texto, fecha, hora, IP, canal) ya se guardan en `1.14-b`. Si ofrece retirar el consentimiento desde su bandeja, lo hace llamando a un contrato de escritura de `fam_unit` (síncrono, en el módulo dueño, patrón de `ADR-048`) | Guardar filas propias del consentimiento de imagen. Propagar la revocación: no hace falta, porque los publicadores consultan en origen |
| **`REQ-FAM-PORTAL`** (`1.22`) | Pantallas del tutor sobre la API HTTP de `fam_unit` (`INV-006`) | — |

**Interfaces que no se construyen en `1.14-b`**: una de lectura de estados e histórico para `AUT` y `PRIV` (`ImageConsentRecords`) y una de escritura (`ImageConsentRecorder`). No tienen consumidor hasta fase 2 (`ADR-048`). Las define la especificación de su primer consumidor, sobre el mismo servicio de dominio que usan los *endpoints* de `fam_unit` (una sola implementación). **Eventos de dominio: ninguno.** La propagación de `REQ-PRIV-002` se cumple porque la consulta va siempre a la fuente, no por avisos.

### 5.8 Auditoría y retención

- `image_consent_decisions` es de **solo anexar**: `tenantTableAppendOnly()`, `REVOKE UPDATE, DELETE` para el rol de aplicación y `AppendOnlyModel` (`ADR-034 §6`, `§16.3` regla 6). Corregir una decisión es registrar otra. Cada alta queda en `audit_logs` (`INV-003`). La política para `ip_address`/`user_agent` se decide con `db-reviewer` (`datos.md §4`, `ADR-035`).
- Las versiones del texto se auditan al crearlas, editarlas en borrador y publicarlas. Las publicadas o retiradas son inmutables y no se borran. La clave foránea desde las decisiones es `RESTRICT`.
- **Retención**: las decisiones y las versiones de texto a las que apuntan se conservan como evidencia del consentimiento mientras dure el tratamiento y el plazo que fije el catálogo de `REQ-PRIV-006` (`datos.md §6`). Este ADR no inventa plazos. La supresión, cuando llegue, es por retención de la fila entera, nunca por edición (`ADR-035`). Una versión no se purga mientras exista una decisión que la referencie.
- **Restauración de copias**: restaurar a un punto anterior a un `no_autorizo` devolvería la publicabilidad. Se trata en `REQ-BKP` (`1.26`) como prevé `OPEN-FAM-UNIT-21`. No se resuelve aquí.

---

## 6 · Motivo (resumen)

Una sola fuente porque `INV-014` es un control preventivo sobre menores, y un control así solo es fiable si no hay otra copia que consultar por error. Esa fuente es `fam_unit` porque ahí está el dato del que depende la decisión: quién representa legalmente a cada alumno. El estado se calcula en origen porque la alternativa, una proyección, se desfasa en cuanto cambia el conjunto de quienes deciden, y el desfase solo puede ir en la dirección peligrosa: un `autorizo` que ya no lo es. Todo lo que no tiene consumidor todavía (interfaces para `AUT` y `PRIV`, decisión del propio alumno, invalidación por cambio de texto) se deja como forma prevista y no se construye.

---

## 7 · Consecuencias

**Positivas**

- `INV-014` depende de una sola consulta, en un solo módulo, que deniega por defecto.
- `REQ-AUT` y `REQ-PRIV` nacen en fase 2 sabiendo que la imagen no es suya, como `REQ-TRAN-005` con `ADR-032`.
- Las preguntas jurídicas pendientes (`OPEN-08`, `-18`, `OPEN-066-02`) se resuelven, cuando lleguen, cambiando el cálculo de `fam_unit` y sin tocar a los consumidores.

**Negativas, que hay que aceptar**

- **Se desvía de la especificación aprobada** en el estado vigente (§5.2) y adelanta `terms_versions` de `1.14-c` a `1.14-b` (§5.5). Requiere ratificación y una enmienda de la especificación (§9).
- Con la regla de §5.4, la imagen de un alumno con un progenitor revocado queda bloqueada hasta que el centro registre en papel la decisión de ese progenitor. Es fallo en cerrado y es lo aprobado, pero en el piloto se notará.
- `autorizo` no se puede registrar hasta que el centro publique el texto de ese nivel. Sin texto, todo el alumnado está `pendiente`.
- Cada consulta de la política lee vínculos y decisiones. Con el volumen previsto es una lectura indexada. Si un publicador sirve galerías grandes y la medición lo pide, se añade una proyección (aditivo) con su ADR.
- Mientras no haya regla para 14 y 18 años, deciden los tutores también cuando la ley quizá diga otra cosa (§5.6).

---

## 8 · Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| **B** · Dueño provisional en `fam_unit` y migración a `PRIV` en fase 2 | Compromete hoy una migración de evidencia legal entre módulos que nadie ha pedido, con una ventana de dos fuentes. Si algún día hace falta, A no la impide |
| **C** · Registro genérico de consentimientos en `Core` | Diseño a ciegas sin catálogo de bases legales (`OPEN-13`) ni especificación de `PRIV`. `Core` tendría que conocer los vínculos de `fam_unit` para combinar decisiones |
| Plantilla «uso de imagen» en `REQ-AUT` | Segunda lista. Es literalmente el fallo de `ADR-032` |
| Consentimiento de imagen por actividad (en `REQ-AUT-001`) | Ningún requisito lo pide, y sería un tercer estado que `ImageConsentPolicy` tendría que combinar. Si un centro lo necesita, es un requisito nuevo con su propio ADR |
| Proyección `image_consents` mantenida en la transacción (especificación aprobada) | Se desfasa al cambiar quién decide y no tiene fila para quien no decidió nunca (§5.2) |
| Tabla propia de textos de consentimiento, separada de `terms_versions` | Duplica el mecanismo de versiones, publicación y traducción que `1.14-c` construye de todos modos. La recomendación aprobada de `OPEN-FAM-UNIT-09` ya eligió reutilizarlo |
| Avisar a los publicadores por evento cuando cambia un consentimiento | No basta (`funcional.md §7.3`, regla 1), y daría a los publicadores una razón para guardar copia |
| Lista vacía = publicable | Un error del consumidor publicaría. Se rechaza con excepción (§5.3) |

---

## 9 · Cambios que pide a la especificación de `REQ-FAM-UNIT` (no los aplica este ADR)

Los aplica `spec-writer` cuando el usuario ratifique este ADR, antes de que `implementer` empiece `1.14-b`.

**`funcional.md`**

1. `§1.2`: `1.14-b` crea `terms_versions` (con `kind`) y sus *endpoints* de gestión y publicación, limitados a los dos tipos de imagen. `condiciones_uso` se rechaza con `422` hasta `1.14-c`, para que no se pueda publicar unas condiciones sin muro. `1.14-c` conserva aceptaciones, muro y el tipo `condiciones_uso`.
2. `§3.8`: el estado vigente se calcula a partir de quienes deciden y sus decisiones (§5.2, §5.4), sin proyección. Se elimina la referencia a «pendiente de `OPEN-FAM-UNIT-08`».
3. `RN-FAM-UNIT-41`: quienes deciden son los representantes legales con vínculo **vivo, `activo` o `revocado`**. El revocado solo por canal `presencial`, con `revocacion_tutor.leer` (§5.4). Hoy dice «vínculo activo», en contradicción con la recomendación aprobada de `OPEN-08`.
4. `RN-FAM-UNIT-35`: aclarar que «un vínculo revocado no puede registrar decisiones de imagen» se refiere al tutor por sí mismo. El centro sí puede registrarlas en su nombre.
5. `RN-FAM-UNIT-43`: la versión del texto deja de ser condicional. Se añade `shown_locale` (idioma en que se mostró el texto por el canal `portal`, mismo criterio que `RN-FAM-UNIT-65`). Esto último es una propuesta derivada que el usuario puede rechazar sin afectar al resto.
6. `RN-FAM-UNIT-44`: se sustituye. Sin proyección no hay bloqueo de fila; dos decisiones simultáneas son dos filas.
7. `RN-FAM-UNIT-45`: la regla deja de estar «pendiente»; queda la tabla de §5.4 con la condición jurídica.
8. `RN-FAM-UNIT-46`: añadir que una lista vacía lanza una excepción.
9. Reglas nuevas: `autorizo` exige versión publicada del texto de su nivel; `no_autorizo` no (§5.5.4). Versión elegible por canal (§5.5.5). Publicar un texto nuevo no altera estados (§5.5.6). En el histórico con ámbito `unidad_familiar` no aparecen las decisiones de tutores revocados respecto de ese alumno (§5.6).
10. `§7.3`: contrato de §5.3 (enumerado de nivel, lista vacía, denegación por defecto, una consulta, sin efectos) y prohibiciones a los consumidores de §5.7, para que cada publicador las copie en su especificación.
11. `§7.6`: añadir que el consentimiento de imagen **no** emite eventos ni para `REQ-PRIV` (§5.7). Hoy cita «`REQ-PRIV` (cambios de consentimiento)» como consumidor previsible de un evento.
12. `§12.6`:
    - `CA-FAM-UNIT-055`: una fila por cada caso de la tabla de verdad, incluidos el revocado que no decidió, el tutor sin representación legal que autoriza (no cuenta) y el alumno sin nadie que decida.
    - `CA-FAM-UNIT-058`: pasa a «dos decisiones simultáneas quedan ambas y el estado es el de la regla», sin bloqueo.
    - `CA` nuevos: alta de un segundo representante legal que cambia `autorizo` a `pendiente` sin ninguna decisión nueva; baja de ese vínculo que lo devuelve; `autorizo` sin texto publicado, `422`; `no_autorizo` sin texto, `201`; decisión del revocado registrada por el centro con y sin `revocacion_tutor.leer`; histórico de un tutor que no muestra las decisiones del revocado; lista vacía, excepción; listado y exportación con `state=pendiente` que incluyen alumnos sin ninguna decisión.
13. `§13`: marcar `OPEN-FAM-UNIT-08` y `-09` como concretadas por `ADR-066`.
14. **Defectos de numeración detectados** (no son de este ADR, pero afectan a `1.14-b`):
    - `RN-FAM-UNIT-40` está duplicada: en `§5.3` (visibilidad de revocados) y en `§5.4` (niveles y decisiones).
    - `CA-FAM-UNIT-050` está duplicado: límite de revocaciones en `§12.3` y alumno sin decisiones en `§12.6`.
    - Hay dos secciones `## 14`.
    - Los tests referencian estos IDs (`INV-015`), así que hay que renumerar antes de escribir los de `1.14-b`.

**`datos.md`**

15. `§1.6`: `consent_text_version_id` siempre presente, nulo solo con `no_autorizo` (`CHECK` de §5.5.4), FK compuesta `RESTRICT` a una versión cuyo `kind` corresponda al nivel (validado en servicio). `shown_locale` nulo (ver punto 5).
16. `§1.7`: se elimina `image_consents`.
17. `§1.8`: `kind` desde la creación, con `CHECK` de los tres valores. Unicidad de la publicada en `(tenant_id, kind)`; numeración en `UNIQUE (tenant_id, kind, version_number)`. `requires_reacceptance` solo con sentido en `condiciones_uso`: `CHECK (kind = 'condiciones_uso' OR NOT requires_reacceptance)`.
18. `§3`: el índice de decisiones pasa a `(tenant_id, student_person_id, level, guardian_id, occurred_at DESC, id DESC)` para servir el `DISTINCT ON` por tutor. Se elimina el de `image_consents`. El listado de pendientes parte de `family_unit_students` vivos.
19. `§4`: se elimina el modelo `ImageConsent` del *morph map* y de la tabla de auditoría.
20. `§6`: las versiones de texto referenciadas por decisiones son evidencia, con la misma retención.

**`api.md`**

21. `§1.5`: `ImageConsent` es una representación calculada (ya no tiene `public_id`; sin cambios para el cliente).
22. `§1.6`: `consent_text_version` deja de ser condicional.
23. `§2.7`: `consent_text_version` opcional en el cuerpo, solo con `channel = presencial`. `on_behalf_of` admite un tutor revocado con `revocacion_tutor.leer`. Errores nuevos `fam_unit.image_consent.text_not_published` y `fam_unit.image_consent.text_version_invalid`, en los cuatro idiomas.
24. `§2.8`: filtro y campo `kind` en `/terms-versions`.

**`permisos.md`**

25. Mapa de `AR-10`: `consentimiento_imagen` pasa a `ImageConsentDecision` solo.
26. Recurso de las versiones de texto: ver `OPEN-066-03`.

**`operacion.md`**

27. Orden de migraciones: `terms_versions` (con `kind`) antes de `image_consent_decisions`, ambas en `1.14-b`; `terms_acceptances` en `1.14-c`. Ya no se crea `image_consents`.

---

## 10 · Preguntas abiertas (las decide el usuario)

| ID | Pregunta | Recomendación |
|---|---|---|
| `OPEN-066-01` | Ratificar el cálculo en origen sin proyección (§5.2), que se aparta de `datos.md §1.7` aprobado | Ratificarlo. Si se prefiere la proyección, la especificación tiene que enumerar todos los puntos de recálculo del servicio de vínculos y añadir un `CA` por cada uno, y crear filas `pendiente` para todo alumno de una unidad |
| `OPEN-066-02` | ¿Una versión nueva del texto invalida los `autorizo` dados con versiones anteriores? | Cuestión jurídica: consultarla con la asesoría junto con `OPEN-08`. En `1.14-b`, no los invalida (§5.5.6). Si la respuesta es sí, se implementa como filtro por versión mínima, sin trabajo masivo |
| `OPEN-066-03` | Nombre del recurso de permisos de las versiones de texto. La especificación lo llama `condiciones_uso`, y con este ADR también publica textos de consentimiento de imagen | Cambiar el nombre a un recurso neutro (`texto_legal`) **ahora**, mientras ningún permiso está declarado ni sembrado (`funcional.md §15`, D-6): cuesta un cambio de documento. Cambiarlo después es migrar concesiones de roles. Alternativa: conservar `condiciones_uso` y asumir el nombre engañoso. Las rutas `/terms-versions` se conservan con `kind` |
| `OPEN-066-04` | Si un tutor pierde y recupera la representación legal (o se vuelve a verificar), ¿vuelven a contar sus decisiones anteriores? | Que solo cuenten las decisiones con `occurred_at` posterior a `legal_guardian_verified_at`, que falla en cerrado y es un filtro en la consulta. Alternativa: que cuenten siempre, por ser un caso raro |
| `OPEN-066-05` | Alumnado de 14 años o más y de 18 o más (`OPEN-FAM-UNIT-18`) | Añadir a la lista de condiciones previas a datos reales, junto a la validación de `OPEN-08`. Si el piloto tiene ESO o Bachillerato, un criterio jurídico sobre quién consiente la imagen del mayor de 14 años. No bloquea `1.14-b` con datos sintéticos |
