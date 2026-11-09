# ADR-071 · Grupo opcional en la matrícula: `ENROLLMENT }o--o| GROUP` y curso de etapa obligatorio

**Estado**: **ACEPTADA** (2026-11-09; `OPEN-071-01` = A, `-02` = A y `-03` = sí, aprobadas por el usuario el 2026-11-09). La decisión de fondo la tomó el usuario el 2026-11-06 al resolver `OPEN-ALUM-11` con la opción B: curso de etapa obligatorio y grupo opcional. Este ADR no reabre esa decisión. La formaliza porque `§16` del documento de requisitos dice que sus entidades núcleo y relaciones «no deben modificarse sin ADR». Las preguntas de `§9` no cambian la decisión de fondo.
**Fecha**: 2026-11-09
**Origen**: `OPEN-ALUM-11` = B y conflicto C4 de `docs/modulos/REQ-ALUM/funcional.md §0.2`. Es la entrada obligatoria de `1.15-b`: según `funcional.md §15`, «`1.15-b` no empieza sin el ADR de C4».
**Afecta a**: `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md §16.1` (fila `Enrollment`) y `§16.2` (diagrama de relaciones); `REQ-ALUM-001`/`-003`; `docs/modulos/REQ-ALUM/datos.md §1.2` (`enrollments`), `funcional.md` (`RN-ALUM-20`, `-26`, `-28`, `-60`, `CA-ALUM-033`) y `permisos.md` (resolutor `grupo`); los consumidores futuros de la matrícula (asistencia `1.13`, calificaciones `1.16`, `REQ-FIN`).
**Se apoya en**: `ADR-020` (el régimen se resuelve desde la matrícula), `ADR-029` (FK compuestas con `tenant_id`), `ADR-044` enmendado por `ADR-063` (ámbitos `grupo`/`clase`, resolutor por módulo dueño del recurso), `ADR-048` (una interfaz pública se construye con su primer consumidor) y `ADR-057` (bloqueo de escritura en cursos cerrados).
**No sustituye ningún ADR.** Ninguno de los ADR `001`-`070` fija la cardinalidad entre matrícula y grupo. Esa cardinalidad solo está en `§16.1`/`§16.2`, que no es un ADR, y este ADR la modifica de forma explícita. `ADR-020` sigue cumpliéndose sin cambios (`§6.2`).

---

## 1 · Contexto

`§16.1` define `Enrollment` como «matrícula de un alumno en un curso académico y grupo». `§16.2` fija `ENROLLMENT }o--|| GROUP`, es decir, toda matrícula tiene exactamente un grupo. `§16.2` no dibuja ninguna relación entre `ENROLLMENT` y `COURSE`.

La realidad operativa choca con eso. Un alumno se matricula a menudo en el curso siguiente antes de que se creen sus grupos: el curso está en `planificacion` y los grupos se forman tras la matrícula y los repartos. Además, el propio `REQ-ALUM-003` habla de **asignar** después a los alumnos a sus grupos. Si el grupo es obligatorio, la matrícula tiene que esperar a que existan los grupos, o hay que inventar un grupo de relleno.

### 1.1 · Hechos verificados (sobre `develop`, `c07680a`)

1. `enrollments` todavía no existe: se crea en `1.15-b`. `1.15-a` solo creó `students`, que no tiene `academic_year_id` (`datos.md §1.1`).
2. Los destinos de las FK compuestas ya existen en `acad` y están protegidos por `SchemaInvariantsTest`: `courses_tenant_year_id_unique (tenant_id, academic_year_id, id)` (migración `2026_10_13_100200`) y `groups_tenant_course_id_unique (tenant_id, course_id, id)` (migración `2026_10_13_100300`). `1.15` no cambia el esquema de `acad`.
3. `TeachingMembership` (`REQ-ACAD/funcional.md §18.7`), `GroupDirectory` (`§7.2`) y el registro de vetos de borrado (`RN-ACAD-21`) están especificados pero **no construidos** (`OPEN-ACAD-43` = A). Se construyen en `1.15-b`, que es su primer consumidor.

## 2 · Problema

Hay que decidir qué ancla estructural es obligatoria en la matrícula, sin romper cuatro cosas:

- la regla 4 de `§16.3` (`Enrollment` es el eje del que cuelgan calificaciones, asistencia y facturación);
- la resolución del régimen desde la matrícula (`ADR-020`);
- el aislamiento en el motor (`INV-001`);
- el denegar por defecto del ámbito `grupo` (`INV-002`, `ADR-063`).

## 3 · Opciones reales

| | Opción | Coste en solitario | Mantenimiento a 3 años | Invariantes | Reversibilidad |
|---|---|---|---|---|---|
| A | **Grupo obligatorio**, como dice hoy `§16.2` | Nulo en el esquema. Alto en la operación: no se puede matricular hasta que existan los grupos | Bajo en código, pero empuja al centro a crear grupos provisionales para poder matricular | Cumple todas | Alta: relajar a nulo después es una migración *expand* trivial |
| **B** | **Curso de etapa obligatorio y grupo opcional** (`OPEN-ALUM-11` = B) | Bajo: una columna anulable, una FK `MATCH SIMPLE`, un `PATCH` de primera asignación y una regla en el resolutor | Bajo. Cada consumidor que necesite grupo filtra `group_id IS NOT NULL` | Cumple todas, si el ámbito `grupo` falla en cerrado sobre los nulos (`§5.3`) | Media. Volver a A obliga a asignar grupo a todas las matrículas sin él antes de poner `NOT NULL`. Hoy no hay ninguna |
| C | Grupo de relleno por curso de etapa («sin asignar») con grupo obligatorio | Medio: hay que sembrarlo, ocultarlo en listados, ratios, horarios y en la asignación docente, y prohibir que se borre | Alto: cada módulo nuevo tiene que acordarse de excluirlo | **Riesgo en `INV-002`**: quien tenga asignación o tutoría en ese grupo ve a todos los alumnos sin grupo del curso | Baja: los datos quedan contaminados por filas ficticias |
| D | Entidad previa (reserva o preinscripción) sin grupo, que se convierte en matrícula con grupo obligatorio | Alto: una entidad, un ciclo de vida y una conversión más. Solapa con `REQ-ALUM-002` y `REQ-OFE` (fase 2) | Alto: dos sitios donde «el alumno está matriculado» | Cumple, pero duplica dónde se aplica `RN-ALUM-21` | Media |

## 4 · Evaluación

- **A** traslada el problema a la operación del centro, que acaba en C hecho a mano y sin control.
- **C** es la peor: añade filas ficticias a la estructura académica y abre una vía de lectura amplia a través del ámbito `grupo`.
- **D** adelanta trabajo de `REQ-OFE`/`REQ-ALUM-002`, que el plan no tiene en `1.15`. Añade complejidad sin un beneficio proporcional.
- **B** es la más barata. Mantiene la coherencia en el motor con el curso de etapa como ancla obligatoria, y su único efecto en la autorización se resuelve fallando en cerrado.

## 5 · Decisión: opción B

### 5.1 · Modelo conceptual (`§16`)

1. **`§16.1`, fila `Enrollment`.** Descripción: «Matrícula de un alumno en un curso académico y un curso de etapa, con grupo cuando se le asigna». Notas críticas: se conserva «Entidad bisagra entre alumno y estructura académica».
2. **`§16.2`.** `ENROLLMENT }o--|| GROUP : asignado_a` pasa a `ENROLLMENT }o--o| GROUP : asignado_a`. Se añade `COURSE ||--o{ ENROLLMENT : matricula_en`, porque el ancla obligatoria, que antes era el grupo, pasa a ser el curso de etapa y el diagrama debe mostrarla (`OPEN-071-01`).
3. **`§16.3` no cambia.** La regla 4 se mantiene: todo lo que cuelga de la matrícula sigue colgando de ella, no del grupo.

### 5.2 · Esquema (lo concreta `datos.md §1.2`, ya aprobado)

1. `course_id NOT NULL`, con FK compuesta `(tenant_id, academic_year_id, course_id)` → `courses (tenant_id, academic_year_id, id)`.
2. `group_id` anulable, con FK compuesta `(tenant_id, course_id, group_id)` → `groups (tenant_id, course_id, id)` en modo **`MATCH SIMPLE`**. Si `group_id` es nulo, el motor no comprueba esa FK. La coherencia del tenant, del curso académico y del curso de etapa la garantiza igualmente la FK de `course_id`.
3. Ninguna restricción liga la presencia del grupo al estado del curso académico ni al estado de la matrícula: una matrícula `activa` sin grupo es válida en `planificacion` y en `activo` (`funcional.md §4.2` paso 1, `RN-ALUM-23`). En cursos `cerrado` o `archivado` no se puede asignar grupo (`ADR-057`).

### 5.3 · Autorización: ámbito `grupo` en cerrado sobre los nulos

1. El resolutor `grupo` de `matricula` (`RN-ALUM-60`) filtra `group_id IN TeachingMembership::groupIdsFor(u, academic_year_id)`. En SQL, `NULL IN (…)` nunca es verdadero, así que **una matrícula sin grupo no la ve nadie a través del ámbito `grupo`**: ni docentes ni tutores de grupo. Esto se declara **comportamiento querido** (`INV-002`, denegar por defecto), no efecto secundario. Ninguna implementación puede «arreglarlo» con `OR group_id IS NULL`.
2. Lo mismo vale para `alumno.leer` con ámbito `grupo`: a un docente le llega un alumno solo a través de una matrícula viva **con** grupo de uno de sus grupos.
3. Las matrículas sin grupo solo son visibles con ámbito `todos` (secretaría, dirección, administración) o `unidad_familiar` (el tutor legal, sobre su propio alumno).

### 5.4 · Primera asignación frente a cambio de grupo

1. Dar el **primer** grupo a una matrícula que no lo tiene es una modificación directa (`PATCH`, `RN-ALUM-28`). Se audita como cualquier modificación, con `group_id` de nulo a valor (`INV-003`).
2. Cambiar un grupo ya asignado pasa siempre por solicitud (`group_change_requests`, `1.15-c`). Por eso `from_group_id` es `NOT NULL` allí.
3. No existe la operación inversa (dejar sin grupo una matrícula que lo tenía). Si hiciera falta, requeriría una decisión nueva.

### 5.5 · Vetos de borrado (`RN-ACAD-21`, `RN-ALUM-26`)

El registro de vetos de `acad` se construye **antes** que la FK a `groups`, como exige `funcional.md §15`. `alum` registra **dos** vetos independientes:

- un **grupo** con matrículas vivas que lo referencian no se borra;
- un **curso de etapa** con matrículas vivas no se borra, **con o sin grupo**.

El veto del curso de etapa no puede deducirse del de sus grupos: las matrículas sin grupo solo están protegidas por él. La FK solo impide el borrado físico; el borrado lógico (`deleted_at`) lo impide el veto.

## 6 · Encaje con invariantes y ADR

### 6.1 · Invariantes

- **`INV-001`**: todas las FK son compuestas con `tenant_id` (`ADR-029`). Un nulo en `group_id` no debilita el aislamiento, porque la fila sigue anclada al tenant por `student_id`, `academic_year_id` y `course_id`.
- **`INV-002`**: el ámbito `grupo` falla en cerrado sobre los nulos (`§5.3`).
- **`INV-003`**: el alta sin grupo, la primera asignación y la anulación se auditan. La primera asignación queda como un cambio de `group_id` en el registro de auditoría.
- **`INV-007`**: `alum` declara la FK a `groups`, igual que en el precedente de la FK a `people` en `students`, pero **lee** el grupo solo a través de `GroupDirectory`, pregunta por la pertenencia solo a través de `TeachingMembership` y se apunta a los vetos solo a través del registro de `Acad\Domain`. No importa código interno de `acad`.

### 6.2 · ADR

- **`ADR-020`**: el régimen se resuelve por matrícula → curso de etapa → etapa. Como el curso de etapa es obligatorio, toda matrícula tiene régimen aunque no tenga grupo. Si el ancla hubiera sido solo el grupo, una matrícula sin grupo se habría quedado sin régimen. B lo evita.
- **`ADR-048`**: `GroupDirectory`, `TeachingMembership::groupIdsFor()` y el registro de vetos se construyen en `1.15-b` porque `alum` es su primer consumidor.
  - El «primer consumidor previsto» de `REQ-ACAD §7.2` y `§18.7` (`1.13`/`1.16`) era una previsión, no una regla. `OPEN-ACAD-43` = A se cumple tal como está escrita.
  - `ADR-063 §5.4` fija un plazo para **especificar**, y ya está cumplido. Este ADR no lo toca.
- **`ADR-044`/`ADR-063`**: el resolutor `grupo` lo registra `alum` como dueño de `matricula` y de `alumno` (`ADR-063 §5.2` punto 2). `acad` solo aporta el dato.
- **`ADR-057`**: sin cambios. La primera asignación de grupo es una escritura y queda bloqueada en cursos cerrados.

## 7 · Consecuencias

1. **Para los consumidores de la matrícula** (`1.13`, `1.16`, `REQ-FIN`):
   - Toda lista «por grupo» (lista de clase, pase de lista, `EnrollmentRoster` por grupo) **excluye** las matrículas sin grupo (`CA-ALUM-033`).
   - Toda lista «por curso de etapa» las **incluye**.
   - Un módulo que necesite grupo obligatoriamente (por ejemplo, el pase de lista) no puede operar sobre una matrícula sin él, y lo debe decir su especificación.
   - La facturación y las calificaciones cuelgan de la matrícula (regla 4 de `§16.3`) y no dependen del grupo.
2. **Riesgo operativo**: en un curso `activo` puede haber alumnos matriculados que no aparecen en ninguna lista de clase y que, por tanto, ningún docente ve. Esto es consecuencia directa de B y de `§5.3`. Este ADR no decide si hace falta aviso o indicador (`OPEN-071-02`).
3. **Reversibilidad**: pasar más adelante a grupo obligatorio, en todo el sistema o desde cierto estado del curso, es un *contract* que exige antes una migración de datos (asignar grupo a las matrículas sin él) y una regla nueva. Requiere ADR.
4. **Coste añadido a `1.15-b`**: ninguno respecto a la especificación aprobada. Todo lo que esta decisión implica ya está en `datos.md §1.2`, `RN-ALUM-20`/`-26`/`-28`/`-60` y `CA-ALUM-028`/`-033`.

## 8 · Alternativas descartadas

- **A (grupo obligatorio)**: obliga a crear los grupos antes de matricular, en contra de `REQ-ALUM-003` («asignación» de grupos posterior). En la práctica degenera en C.
- **C (grupo de relleno)**: contamina la estructura académica y abre una vía de lectura amplia a través del ámbito `grupo` (`INV-002`).
- **D (entidad previa)**: adelanta `REQ-OFE`/`REQ-ALUM-002` (fase 2), duplica dónde «está matriculado» un alumno y complica `RN-ALUM-21`. Complejidad sin beneficio proporcional en `1.15`.

## 9 · Preguntas abiertas

| ID | Pregunta | Opciones | Recomendación |
|----|----------|----------|---------------|
| `OPEN-071-01` | ¿Se añade a `§16.2` la relación `COURSE \|\|--o{ ENROLLMENT`? Va más allá de pasar `}o--\|\|` a `}o--o\|`, aunque es lo que hace visible el ancla obligatoria | **A** · Sí, en este ADR. **B** · No: solo se cambia la cardinalidad del grupo | **A**: sin ella, el diagrama no refleja que la matrícula tiene un ancla estructural obligatoria **Resuelta: A** (usuario, 2026-11-09). |
| `OPEN-071-02` | En un curso `activo`, ¿basta con que las matrículas activas sin grupo sean válidas indefinidamente, sin aviso ni bloqueo? | **A** · Sí. Lo que haga falta se decide con `1.13`. **B** · Se añade ya un filtro o indicador «sin grupo» en el listado de matrículas | Sin recomendación: es funcional, no estructural, y no bloquea `1.15-b`. La especificación aprobada ya permite filtrar por curso de etapa **Resuelta: A** (usuario, 2026-11-09). Una matrícula `activa` sin grupo es válida sin límite de tiempo, sin aviso ni bloqueo; el filtro o indicador «sin grupo» se decide con `1.13`. Un «antiguo alumno» no es un estado: se deriva (sin matrícula `activa` y con alguna en `baja` o en un curso `cerrado`/`archivado`). Usos futuros (descuento a hermanos, `REQ-CRM-004`) y su base legal se resuelven en `1.23b` (`OPEN-13`); el plazo de conservación no puede borrar el hecho de haber sido alumno si una bonificación depende de él. |
| `OPEN-071-03` | ¿Se pasa este ADR a `ACEPTADA` directamente, puesto que el fondo ya está decidido (precedente: `ADR-070`)? | Sí / No | Sí, una vez respondidas `-01` y `-02` **Resuelta: sí** (usuario, 2026-11-09). |

## 10 · Ajustes que aplica la sesión (no los edita `architect`)

Según `REQ-ALUM/operacion.md` («Documento de requisitos»), en una misma subida de versión del documento de requisitos con su entrada en el historial:

1. `§16.1` y `§16.2` según `§5.1`;
2. fila de `ADR-071` en el índice de `§18`;
3. `§5.6` y fila de `REQ-ALUM` del índice de módulos (`OPEN-ALUM-28` = A), que ya estaba pendiente.
