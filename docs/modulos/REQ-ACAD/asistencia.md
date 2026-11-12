# REQ-ACAD-003 · Control de asistencia · Especificación del paso `1.13`

| Campo | Valor |
|-------|-------|
| Código | `REQ-ACAD-003` (`docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md §5.4`), con `RMOB-COM-001` (`§14.3`) en lo que toca al paso de lista sin conexión |
| Prioridad | MUST |
| Fase | 1 · paso **`1.13`** del plan, partido en **`1.13-a`**, **`1.13-b`** y **`1.13-c`** (§12) |
| Estado | **PROPUESTO** (2026-11-12). Sin aprobar. Tiene preguntas **bloqueantes** (§14.1): no se implementa hasta decidirlas |
| Clasificación del paso | **crítico** en las tres entregas (§11) |

| Depende de | Documento: `CORE` (`§5.4`). Real: `curso`, `acad`, `alum` y, en `1.13-b`, `fam_unit` (§8.1). **El módulo que la aloja está sin decidir** (`OPEN-ACAD-45`, bloqueante) |
| Esencial | `essential: false` (propuesta) |
| Decisiones vinculantes de partida | `ADR-008` (PWA en fase 1), `ADR-029`, `ADR-033`, `ADR-034 §4`/`§6`/`§7`, `ADR-035`, `ADR-038`, `ADR-044`, `ADR-045`, `ADR-048`, `ADR-053`, `ADR-054`, `ADR-056`, `ADR-057`, **`ADR-063`** (`§5.2`, `§5.3`, `§5.4`), `ADR-065` (`time`), `ADR-071`; contrato de `REQ-CURSO` (`RN-CURSO-20..33`); `REQ-ACAD/funcional.md §18.4.2` y `§18.7`; `REQ-ALUM/funcional.md §7.2` y `§7.4` |

> Escrita el 2026-11-12 (ola 1, agente B2, sin `Bash`: los ficheros quedan sin commitear en el *worktree*). Donde esta especificación añade algo que ni los requisitos ni un ADR fijan, se marca **[DERIVADA]** y se argumenta; lo que es decisión del usuario va como `OPEN-ACAD-NN` con recomendación (§14). Nada de lo aquí escrito está aprobado.

---

## 0. Ubicación del documento, fuentes y hallazgos

### 0.1 Por qué un único fichero `REQ-ACAD/asistencia.md`

| Opción | Valoración |
|--------|-----------|
| **A · Un fichero nuevo `docs/modulos/REQ-ACAD/asistencia.md`, ordenado con las cinco partes de la plantilla** (funcional §1-§4 y §13-§14, datos §5, API §6, permisos §7, operación §11) | **Elegida.** `REQ-ACAD-003` es un requisito de `REQ-ACAD`, así que su carpeta es `REQ-ACAD/` (`CLAUDE.md §6`). Los cinco ficheros de `REQ-ACAD` ya son inmanejables (`funcional.md` supera el límite de lectura de una sola llamada y tiene 21 secciones): añadirles otras ~600 líneas contradice el espíritu de la norma 5 de `CLAUDE.md §6`. Precedente de la misma ola: `REQ-CORE/almacen-objetos.md` |
| B · Añadir §22 a `funcional.md` y secciones a `datos.md`, `api.md`, `permisos.md`, `operacion.md` | Es lo que se hizo en `1.12` y `1.12b`. Descartada por tamaño y porque cinco ediciones grandes en ficheros vivos multiplican el riesgo de conflicto con otras ramas |
| C · Carpeta nueva `docs/modulos/REQ-ASIST/` con los cinco ficheros | Descartada: `REQ-ASIST` no existe como código de requisito (`§0.2` de `REQUISITOS`: los ID no se inventan). Si `OPEN-ACAD-45` crea un módulo propio, su documentación sigue siendo la de `REQ-ACAD-003` |

**Consecuencia que hay que aceptar**: `doc-reviewer` espera cinco ficheros por módulo. Al implementar, cada uno de los cinco de `REQ-ACAD` gana **un párrafo puntero** a la sección correspondiente de este fichero (lo hace la entrega, no esta especificación). Si el usuario prefiere B o C, mover el contenido es mecánico.

**Numeración**: reglas `RN-ACAD-160` a `-199`, criterios `CA-ACAD-160` a `-199`, preguntas `OPEN-ACAD-45` a `-58`. Lo último usado que he visto es `RN-ACAD-157`, `CA-ACAD-159` y `OPEN-ACAD-44` (`funcional.md §18` a `§21`); **no he podido comprobar con una búsqueda** que `1.15-b` (tramo `acad`) no añadiera identificadores posteriores (§15.2).

### 0.2 Fuentes de cada requisito

| Requisito | Fuente |
|-----------|--------|
| Paso de lista digital por el profesorado | `REQ-ACAD-003` punto 1; `REQ-PROF-002` |
| Faltas, retrasos y justificaciones | `REQ-ACAD-003` punto 2 |
| Alertas automáticas a tutores por faltas no justificadas, umbral configurable | `REQ-ACAD-003` punto 3 |
| Informes por alumno, grupo y período | `REQ-ACAD-003` punto 4 |
| Integración con control de acceso | `REQ-ACAD-003` punto 5, **`FUTURO`: no se especifica** |
| La asistencia cuelga de la matrícula | `§16` (`ENROLLMENT ||--o{ ATTENDANCE`); `ADR-034 §4` (dato dependiente del curso) |
| Paso de lista sin cobertura, sincronización y «resolución de conflictos definida» | **`RMOB-COM-001`** (`§14.3`), con `ADR-008` (fase 1 = PWA); `RMOB-COM-002`, `-003`, `-004` |
| El docente ve su asistencia; el tutor de grupo, la de su grupo; la familia, la de sus hijos | `§11.1`; `ADR-063 §5.3`; `REQ-FAM-PORTAL-001` |
| Presencia para la alerta de alumno no recogido | `REQ-PRL-004`; obligación heredada de `1.14b-d4` (`PLAN-IMPLEMENTACION.md`, paso `1.13`; `REQ-PRL/funcional.md`, «Entrega `1.14b-d`») |

### 0.3 Confirmación de `ADR-063 §5.4` (asignación docente por curso académico)

**Confirmado contra documentación y código** (commit `b7995bc`):

- La asignación docente es **entidad propia de `acad`** y **por curso académico**: `teaching_assignments` y `group_tutors` llevan `academic_year_id` y el disparador de `ADR-057` (`RN-ACAD-90`, `-91`); no se deriva del horario (`RN-ACAD-95`, `-99`).
- El **plazo vinculante** de `ADR-063 §5.4` (asignación e interfaz de pertenencia especificadas y aprobadas antes de aprobar `1.13`) **está cumplido**: `REQ-ACAD/funcional.md §18` se aprobó el 2026-10-17 y se implementó en `1.12-a`.
- La interfaz de pertenencia existe: `App\Modules\Acad\Domain\TeachingMembership::groupIdsFor(int $userId, int $academicYearId): array` (ids internos de grupo, tutorías vivas ∪ asignaciones vivas, un subgrupo se proyecta sobre su grupo, `RN-ACAD-98`), enlazada en `AcadServiceProvider::register()` y consumida ya por `Alum\Infrastructure\AlumnoGrupoScopeResolver` y `MatriculaGrupoScopeResolver`.
- **`classPairsFor()` no está construida** (el *docblock* de la interfaz lo dice). El ámbito `clase` de asistencia sería su primer consumidor (§8.2).
- La referencia correcta es **`ADR-063 §5.4`**. `ADR-048` trata de otra cosa. La entrada de `1.13` del plan que he leído ya cita `ADR-063 §5.4`; si queda alguna cita a `ADR-048` como origen de la asignación docente, se corrige (§16).

### 0.4 Contradicciones y huecos (no los resuelvo: cada uno tiene su pregunta)

| # | Hallazgo | Fuentes | Cómo queda |
|---|----------|---------|-----------|
| **H1** | **La asistencia no puede vivir en el módulo `acad` sin crear un ciclo de dependencias.** `§5.4` declara `Attendance` entidad de `REQ-ACAD`, que «depende de `CORE`». Pero la asistencia se registra sobre la matrícula (`§16`), que es de `alum`, y `alum` declara `depends_on: ['curso', 'acad', 'fam_unit']` (`AlumServiceProvider`). Si `acad` consumiera `alum`, el grafo tendría un ciclo y `platform:sync-registry` **aborta el despliegue** (`RMOD-006`). `REQ-ALUM/funcional.md §7.4` ya prevé que `1.13` consuma `EnrollmentRoster`, pero no dice desde qué módulo | `§5.4`, `§5.6`, `§16`, `RMOD-006`, código | **`OPEN-ACAD-45` (bloqueante)**. Esta especificación se escribe con la opción recomendada (módulo nuevo); el modelo de datos, la API y los permisos no cambian con las otras opciones, solo el módulo que los declara |
| **H2** | **No está definido el grano del paso de lista**: por sesión del horario, por día o ambos. `REQ-ACAD-003` dice solo «paso de lista»; `RN-ACAD-126` no obliga a que un grupo tenga horario compuesto | `REQ-ACAD-003`, `RN-ACAD-126` | **`OPEN-ACAD-46` (bloqueante)**: condiciona las tablas |
| **H3** | **«Alertas automáticas a tutores»**: «tutores» puede ser el tutor legal (uso habitual del documento, `REQ-FAM-UNIT`) o el tutor de grupo (`§11.1`). Y avisar a una familia exige `REQ-COM` (`1.19`), que no existe | `REQ-ACAD-003`, `§11.1`, `§5.12` | **`OPEN-ACAD-49`**. `1.13-b` registra la alerta y la muestra en la aplicación; el envío a familias llega con `1.19` (§8.4) |
| **H4** | **«Justificaciones» sin definir**: quién justifica, con qué motivo y con qué documento. Un motivo médico es un dato de salud (categoría especial, `CLAUDE.md §8`) | `REQ-ACAD-003`, `RPERM-012` | **`OPEN-ACAD-48`**. Recomendación: sin motivo ni texto libre ni fichero en `1.13` |
| **H5** | **El alumnado no pertenece a subgrupos**: `enrollments` tiene `group_id` y nada más. Una sesión de desdoble no tiene lista propia | `REQ-ALUM/datos.md §1.2`, `RN-ACAD-93` | `RN-ACAD-167` (lista del grupo entero) y `OPEN-ACAD-55` |
| **H6** | **No existe ninguna pieza de PWA en `apps/web`** (sin *service worker*, sin almacenamiento local, sin dependencia relacionada; comprobado por búsqueda en el repositorio). `RMOB-COM-001` y el plan piden funcionamiento sin conexión | `RMOB-COM-001`, `ADR-008`, código | §10 y **solicitud de ADR** (§10.9). Entrega propia `1.13-c` |
| **H7** | **`RMOB-COM-004`** (borrar datos locales al cerrar sesión) choca con no perder pases sin enviar | `RMOB-COM-004`, `RMOB-COM-001` | **`OPEN-ACAD-52`** |
| **H8** | **Primer permiso de escritura con ámbito `grupo`/`clase`** del producto. `ScopeResolver::constrain()` restringe una consulta; un alta todavía no tiene fila | `ADR-044 §4.2`, `ADR-063 §5.2` | `RN-ACAD-181` [DERIVADA] y `OPEN-ACAD-56` (técnica) |
| **H9** | Las interfaces de `alum` que `1.13` necesita (`EnrollmentRoster`, registro de vetos de anulación de matrícula) están **especificadas y no construidas** (`Alum\Domain` solo contiene `Models`) | `REQ-ALUM/funcional.md §7.2`, código | Se construyen en `1.13-a` con este primer consumidor (`ADR-048`); es trabajo en `alum` y en `acad` que debe aprobarse con esta especificación (§8.2) |

---

## 1. Alcance

### 1.1 Qué entra

| Entrega | Contenido | Requisito |
|---------|-----------|-----------|
| **`1.13-a`** | Pase de lista **en línea**, pensado para móvil: hoja del día del docente, lista del grupo, marcar faltas y retrasos, corregir y anular un pase; tablas `attendance_rolls` y `attendance_marks`; permisos `asistencia.*` con ámbitos `todos`, `grupo` y `clase`; resolutores; interfaces nuevas en `acad` y `alum` (§8.2); veto de anulación de matrícula con asistencia | `REQ-ACAD-003` puntos 1 y 2 (sin justificaciones), `REQ-PROF-002` |
| **`1.13-b`** | Justificación de faltas y retrasos; informes por alumno, por grupo y por intervalo de fechas; umbral configurable y registro de alertas; ámbito `unidad_familiar` en la lectura (API); contrato de presencia para `1.14b-d4` (especificado, no construido) | `REQ-ACAD-003` puntos 2, 3 y 4; `REQ-FAM-PORTAL-001` (solo API) |
| **`1.13-c`** | Paso de lista **sin conexión** en la PWA: caché de la hoja del día, cola de salida, sincronización y resolución de conflictos (§10). **Solo después de aceptarse su ADR** | `RMOB-COM-001`, `-002`, `-003`, `-004` |

### 1.2 Qué NO entra, explícitamente

| Fuera de alcance | Requisito | Por qué | Dónde se retoma |
|------------------|-----------|---------|-----------------|
| Integración con sistemas de control de acceso | `REQ-ACAD-003` punto 5 | **`FUTURO`** (`CLAUDE.md §11`) | No se especifica |
| Envío del aviso de faltas a las familias (correo, *push*, aviso en la aplicación) | `REQ-ACAD-003` punto 3 | No hay motor de notificaciones (`REQ-COM`, `ADR-079` en borrador) | `1.19`: consume el evento de §8.4 |
| Alerta de alumno no recogido y su escalado | `REQ-PRL-004` | Es de `prl`; necesita además #453 y `1.19` | `1.14b-d4`, sobre el contrato de §8.3 |
| Pantallas de asistencia en los portales de familias y alumnado | `REQ-FAM-PORTAL-001`, `§14.4` | Pasos propios | `1.22`, `1.23`. La **API** sí admite `unidad_familiar` (`INV-006`) |
| Panel docente («tareas pendientes: pasar lista») | `REQ-PROF-001` | Paso propio | `1.21`; `1.13-a` aporta la hoja del día |
| Justificación enviada por la familia, justificante adjunto, motivo de la falta | Ninguno lo pide de forma expresa | H4; un motivo médico es categoría especial | `OPEN-ACAD-48`; `1.22` si se decide |
| Minutos de retraso, salida anticipada, observaciones por alumno | Ninguno | Sin requisito | No se declara |
| Exportación de informes (CSV, PDF) | Ninguno (`REQ-ACAD-003` dice «informes», no «exportación») | Sin requisito; el motor de PDF está sin decidir (`ADR-078`) | No se declara `asistencia.exportar` (precedente `REQ-ACAD/permisos.md §2.1`) |
| KPI de absentismo en paneles de dirección | Requisitos de informes y paneles (fase posterior) | Otro módulo | Consumirá una interfaz de lectura cuando exista (`ADR-048`) |
| Asistencia efectiva mensual para la beca 0-3 | `REQ-BEC-001` | Fase 2 | `REQ-BEC`, sobre estos datos |
| Asistencia de actividades extraescolares, comedor, transporte, aula matinal | Sus módulos | Cada servicio tiene su registro | Sus pasos |
| Festivos y días no lectivos | `REQ-AGENDA` | No hay calendario escolar (`OPEN-ACAD-37`) | `1.20`; hasta entonces no se valida (`RN-ACAD-164`) |
| Sustituciones y guardias | `REQ-GUAR` (fase 2), `OPEN-ACAD-35` | Sin asignación, el sustituto no alcanza al grupo por ámbito | Fase 2; mientras tanto pasa lista quien tenga ámbito `todos` (§4) |
| Copia al curso siguiente | `REQ-CURSO-002` | La asistencia no es estructura | Nunca: no hay participante de *rollover* |
| Aplicación nativa, biometría, *push* | `RMOB-AND-*`, `RMOB-IOS-*` | Fase 3 (`ADR-008`) | Fase 3 |
| Retención y supresión de la asistencia | `REQ-PRIV` | Paso propio | `OPEN-ACAD-57` |

---

## 2. Actores, conceptos y flujos

### 2.1 Actores

Se comprueban **permisos, nunca roles** (`AR-08`); los roles de esta tabla son solo la siembra propuesta (§7.3).

| Actor | Qué hace |
|-------|----------|
| Docente | Pasa lista de las sesiones que imparte; consulta la asistencia de sus clases |
| Tutor de grupo | Pasa lista de su grupo, consulta toda la asistencia de su grupo, justifica, atiende alertas |
| Dirección / Jefatura | Todo sobre todos los grupos; configura el umbral |
| Secretaría | Consulta y justifica (propuesta, `OPEN-ACAD-51`) |
| Tutor legal | Lee la asistencia de sus hijos por la API (`1.13-b`); pantalla en `1.22` |

### 2.2 Conceptos

| Término | Tabla | Qué es |
|---------|-------|--------|
| **Pase de lista** | `attendance_rolls` | El hecho de que alguien pasó lista a un **grupo** en una **fecha**, bien de una **sesión del horario** (pase de sesión), bien del día entero (pase diario). Por curso académico |
| **Marca** | `attendance_marks` | Una falta o un retraso de una matrícula en un pase. **Solo se guardan las excepciones**: quien está en la lista del grupo y no tiene marca, asistió (`RN-ACAD-166`) |
| **Lista del grupo** | — (de `alum`) | Las matrículas `activa` del grupo en la fecha (`EnrollmentRoster`) |
| **Hoja del día** | — | Lo que un usuario puede pasar en una fecha: sus sesiones del horario de ese día y sus grupos, cada uno con su lista y su pase si existe |
| **Justificación** | columnas de la marca | Quién y cuándo dio por justificada una marca |
| **Alerta de faltas** | `attendance_alerts` (`1.13-b`) | Registro de que una matrícula alcanzó el umbral de faltas sin justificar |
| **Ajustes** | `attendance_settings` (`1.13-b`) | El umbral del centro |

### 2.3 Flujos

**F1 · Pasar lista (docente, móvil, `1.13-a`)**

1. Abre «Pasar lista»: la hoja del día de hoy (`GET /attendance/day-sheet`), con sus sesiones ordenadas por hora y la siguiente destacada.
2. Elige una sesión (o un grupo, para el pase diario): ve la lista con todo el grupo **presente por defecto**.
3. Toca a cada alumno ausente (un toque: falta; segundo toque: retraso; tercero: presente).
4. Pulsa «Guardar»: `POST /attendance-rolls`. Una clase sin ausencias son **dos toques** (abrir y guardar).
5. Corrige después reabriendo el pase: `PUT /attendance-rolls/{id}` con el conjunto completo de marcas y la versión que leyó.

**F2 · Pase diario o de otro grupo (tutor, jefatura)**: la hoja del día admite `group` para pedir la lista de un grupo concreto que el usuario alcanza.

**F3 · Justificar (`1.13-b`)**: en la ficha de asistencia del alumno se elige un intervalo de fechas y se justifican todas sus marcas sin justificar del intervalo (`POST /attendance-justifications`); se puede retirar la justificación de una marca.

**F4 · Consultar informes (`1.13-b`)**: resumen por grupo e intervalo (una fila por alumno con sus contadores) y detalle por alumno (sus marcas).

**F5 · Umbral y alertas (`1.13-b`)**: dirección fija el umbral; cuando una matrícula lo alcanza se crea una alerta, visible para quien alcanza a ese alumno, que la marca como atendida.

**F6 · Sin conexión (`1.13-c`)**: §10.

---

## 3. Reglas de negocio

### 3.1 Comunes

| ID | Regla |
|----|-------|
| `RN-ACAD-160` | Toda tabla de asistencia **salvo `attendance_settings`** lleva `academic_year_id` con `tenantForeignId()` y el disparador de `ADR-057`: escribir en un curso `cerrado`/`archivado` ⇒ `409 academic-year-closed`; toda lectura invoca `AcademicYearReadAccess` ⇒ `404` sin `curso_historico.leer` (`RN-CURSO-33`). El curso académico de un pase es el de su grupo; el de una marca y el de una alerta, el de su pase o matrícula. No se admite `academic_year` en el cuerpo de una escritura (`422 academic_year_not_allowed`, precedente `RN-ACAD-91`) |
| `RN-ACAD-161` | Padres inmutables: un pase no cambia de grupo, fecha ni sesión; una marca no cambia de pase ni de matrícula (`422 parent_immutable`). Equivocarse de grupo es anular y volver a pasar |
| `RN-ACAD-162` | Ninguna respuesta contiene un identificador interno (`ADR-029`): solo `public_id`. De cada alumno se devuelve **nombre visible y `public_id` de la matrícula**, nada más: ni fotografía, ni fecha de nacimiento, ni contacto, ni documento (minimización, `INV-008`) |

### 3.2 Pase de lista (`1.13-a`)

| ID | Regla |
|----|-------|
| `RN-ACAD-163` | **Grano** (`OPEN-ACAD-46`, recomendación C). Un pase es (grupo, fecha, sesión del horario **opcional**). Con sesión es un **pase de sesión**; sin ella, un **pase diario**. Para un mismo grupo y fecha, o hay **un** pase diario o hay pases de sesión, **nunca las dos cosas** ⇒ `422 acad.validation.roll_kind_conflict` **[DERIVADA]**: mezclarlos contaría dos veces la misma ausencia y haría contradictorios los informes. No hay ajuste de «modo» por etapa: el modo de cada día lo fija el primer pase |
| `RN-ACAD-164` | **Fecha**: fecha civil del centro (`date`, zona `timezone` del centro), dentro del curso académico del grupo (`422 roll_outside_academic_year`, fechas leídas con `AcademicYearSharedLock`) y **no futura** (`422 roll_date_in_future`). Se admite pasar lista de un día anterior mientras el curso sea escribible **[DERIVADA]**: olvidar un pase es habitual y sin esto no se podría reparar (`OPEN-ACAD-54`). No se valida festivo ni día no lectivo (no hay calendario, `1.20`) |
| `RN-ACAD-165` | **Sesión**: la sesión del horario debe estar viva, ser del mismo curso académico, ser de ese grupo (la asignación docente de la sesión es de una asignatura de ese grupo, también si es de un subgrupo) y **ocurrir ese día**: el día de la semana de su franja coincide con el de la fecha y su marco es el vigente ese día (el `especial` de la etapa si la fecha cae en su vigencia; si no, el `ordinaria`; `RN-ACAD-124`) ⇒ si no, `422 acad.validation.session_not_on_date`. Inexistente, borrada, de otro centro o de otro curso ⇒ `422 parent_not_found`. Lo responde una interfaz de `Acad\Domain` (§8.2), no una consulta a tablas de `acad`. El pase guarda la **asignatura** de la sesión (desnormalizada; la asignación de una sesión es inmutable, `RN-ACAD-95`, `-122`) |
| `RN-ACAD-166` | **Solo excepciones.** Un pase guarda una marca por cada alumno con `falta` o `retraso`; no guarda filas de «presente». «Asistió» = matrícula en la lista del grupo en esa fecha, con pase y sin marca viva **[DERIVADA]**: con marca por alumno un centro de 1.200 alumnos y seis sesiones generaría del orden de 1,2 millones de filas por curso y otras tantas entradas de auditoría (`INV-003`, `AR-15`); con excepciones, del orden de una vigésima parte |
| `RN-ACAD-167` | **Lista**: las matrículas `activa` y vivas del grupo que cubren la fecha (`enrolled_on ≤ fecha`), de `EnrollmentRoster` (§8.2), ordenadas por nombre. En una sesión de **subgrupo** la lista es la del grupo entero (H5): el docente marca solo a los suyos. Una marca nueva exige que la matrícula esté en esa lista ⇒ si no, `422 acad.validation.enrollment_not_in_roll_group`. Limitación aceptada: tras un cambio de grupo aprobado, la lista de una fecha pasada es la del grupo **actual** de cada matrícula (`alum` no guarda histórico de grupo); las marcas ya existentes no se revalidan |
| `RN-ACAD-168` | **Marca**: `mark_type` ∈ {`falta`, `retraso`} (`422 mark_type_invalid`). Una marca viva por (pase, matrícula). Sin minutos, sin motivo, sin texto libre |
| `RN-ACAD-169` | **Alta de un pase**: `POST /attendance-rolls` con `group`, `date`, `timetable_session` opcional y `marks` (lista completa, puede ser vacía: «todos presentes»). Si ya existe un pase vivo con esa clave ⇒ `409 acad.conflict.roll_exists` con `params.roll` (`public_id`) y `params.version`, y no escribe nada. Exige `Idempotency-Key` (`RequireIdempotencyKey`, `INV-011`): repetir la misma petición devuelve el resultado original |
| `RN-ACAD-170` | **Corrección**: `PUT /attendance-rolls/{id}` con la lista **completa** de marcas (precedente de conjunto de `RN-ACAD-88`/`-97`: la que no cambia no se toca, la nueva se crea, la ausente se da de baja lógica, la que cambia de tipo se actualiza; todo por instancia, `AR-15`) y **`version` obligatoria** (la que el cliente leyó). Si no coincide con la actual ⇒ `409 acad.conflict.roll_version_conflict` con el estado actual del pase en `params` (versión, marcas, quién y cuándo lo modificó por última vez) y **no cambia nada** (control optimista; base de la resolución de conflictos de §10.5). Sin cambios efectivos no escribe ni sube la versión. Cada escritura efectiva suma 1 a `version` |
| `RN-ACAD-171` | **Marcas justificadas**: un `PUT` no puede dar de baja ni cambiar de tipo una marca **justificada** ⇒ `422 acad.validation.mark_justified` (`params.enrollments`), y no cambia nada. Antes hay que retirar la justificación (`RN-ACAD-185`). Así una justificación nunca desaparece por la sincronización de un dispositivo que no la conocía |
| `RN-ACAD-172` | **Autoría y momento**: `taken_by` es quien crea el pase; `updated_by`, el último que lo corrige. `recorded_at` es el instante que **declara el dispositivo** (cuándo se pasó lista de verdad, relevante sin conexión): opcional, no puede ser futuro en más de 5 minutos ni anterior a la fecha del pase (`422 recorded_at_invalid`); es informativo y **no** sustituye a `created_at` ni a `audit_logs.occurred_at`, que pone el servidor (`INV-010`) |
| `RN-ACAD-173` | **Anulación**: `DELETE /attendance-rolls/{id}` da de baja lógica el pase y sus marcas (por instancia). Con alguna marca justificada ⇒ `409 acad.conflict.has_dependents` con `attendance_justifications`. Tras anular, la clave queda libre para un pase nuevo |
| `RN-ACAD-174` | **Serialización** **[DERIVADA]**: toda escritura de pases de un (grupo, fecha) se serializa (forma en `datos` §5.4) antes de comprobar `RN-ACAD-163` y `-169`. Un solo servicio escribe pases y marcas. Dos altas simultáneas de la misma clave ⇒ una `201` y otra `409 roll_exists`, nunca dos pases ni `500` |
| `RN-ACAD-175` | **Veto de anulación de matrícula**: el módulo registra en `alum` un veto (`REQ-ALUM/funcional.md §7.2`): una matrícula con marcas vivas no se **anula** (borrado). La **baja** (`status = 'baja'`) no se veta: es el caso normal de un alumno que se va, y sus marcas se conservan |
| `RN-ACAD-176` | **Veto de borrado de estructura**: un grupo con pases vivos no se borra (`StructureDeletionVetoRegistry` de `acad`, precedente `RN-ALUM-26`). Una **sesión del horario** con pases: `OPEN-ACAD-53` (recomendación: se puede borrar; el pase conserva la referencia y la asignatura) |

### 3.3 Autorización y ámbitos (`1.13-a`; `unidad_familiar` en `1.13-b`)

| ID | Regla |
|----|-------|
| `RN-ACAD-180` | **Proyección de ámbitos sobre un pase** (`ADR-063 §5.3`), con el curso académico **de la fila** (precedente `RN-ALUM-60`): `grupo` = pases cuyo grupo está en `groupIdsFor(u, curso del pase)`; `clase` = pases **de sesión** cuyo par (grupo, asignatura) está en `classPairsFor(u, curso del pase)`. Un pase **diario** no tiene asignatura: **no se alcanza por `clase`**, solo por `grupo` o `todos`. Una marca hereda el alcance de su pase. Varios ámbitos se unen con `OR` (`ADR-044 §4.2`); sin contención entre `grupo` y `clase` (`ADR-063 §5.3`) |
| `RN-ACAD-181` | **Escritura con ámbito restringido** (H8) **[DERIVADA]**: crear un pase con `asistencia.crear` de ámbito `grupo` o `clase` se autoriza **insertando el pase y comprobando, en la misma transacción y por `ScopedQuery`, que la fila creada es alcanzable** con la decisión de permiso del actor; si no lo es, la transacción se deshace y la respuesta es `404` (la misma que si el grupo no existiera, `ADR-044 §4.2`). Así el alta usa el mismo resolutor que la lectura y no hay una segunda vía de autorización que pueda divergir (`OPEN-ACAD-56`). Corregir y anular buscan el pase por `ScopedQuery` con el permiso de su acción |
| `RN-ACAD-182` | **`unidad_familiar`** (`1.13-b`, solo `asistencia.leer`): marcas de matrículas de alumnos alcanzados por la persona del sujeto en `FamilyMembership` (solo vínculos **activos**: un tutor con el acceso revocado no ve nada, `REQ-FAM-UNIT-002`). Con ese ámbito **no se ve quién pasó lista** ni ningún otro alumno: la respuesta lleva fecha, tipo, asignatura, si está justificada y nada más |
| `RN-ACAD-183` | Fuera de ámbito ⇒ misma respuesta que inexistente (`404`) en detalle, corrección, anulación, justificación y alertas; en listados e informes, filas ausentes. Sin caché entre peticiones (`ADR-044 §4.7`). Ningún rol predefinido recibe `todos` como sustituto de la asignación docente (`ADR-063 §5.4`) |

### 3.4 Justificación (`1.13-b`)

| ID | Regla |
|----|-------|
| `RN-ACAD-184` | Justificar es una **acción del centro** (`asistencia.aprobar`): `POST /attendance-justifications` con `enrollment`, `from` y `to` (fechas, `to ≥ from`, intervalo máximo 31 días ⇒ `422 justification_range_invalid`) justifica **todas** las marcas vivas sin justificar de esa matrícula con fecha de pase en el intervalo que el actor alcanza. Guarda `justified_by` y `justified_at` en cada una (por instancia). Sin marcas que justificar ⇒ `200` con `justified: 0`. No guarda motivo, texto ni fichero (`OPEN-ACAD-48`) |
| `RN-ACAD-185` | Retirar la justificación de una marca: `DELETE /attendance-marks/{id}/justification` (`asistencia.aprobar`); la marca vuelve a contar como no justificada. Una marca no justificada ⇒ `204` sin cambios |
| `RN-ACAD-186` | Se justifican faltas **y** retrasos. Una justificación no borra una alerta ya creada (`RN-ACAD-190`) |

### 3.5 Informes (`1.13-b`)

| ID | Regla |
|----|-------|
| `RN-ACAD-187` | **Por grupo e intervalo**: `GET /attendance-summary` con `group`, `from`, `to` devuelve una fila por matrícula de la lista actual del grupo con seis contadores: faltas justificadas y sin justificar y retrasos, separando marcas de **pase diario** y de **pase de sesión** (no se suman: un día y una sesión no son la misma unidad), más el número de pases diarios y de sesión del grupo en el intervalo (el denominador). Con ámbito `clase`, solo cuentan los pases alcanzados |
| `RN-ACAD-188` | **Por alumno**: `GET /attendance-marks` con `enrollment` (y `from`, `to` opcionales) lista sus marcas, paginadas, orden `-date`, con asignatura y franja si es de sesión |
| `RN-ACAD-189` | **Por período**: la API recibe fechas; la interfaz ofrece los períodos lectivos de la etapa como atajo que rellena `from`/`to` (`OPEN-ACAD-50`). El intervalo debe caer en **un** curso académico (`422 range_outside_academic_year`) |

### 3.6 Umbral y alertas (`1.13-b`)

| ID | Regla |
|----|-------|
| `RN-ACAD-190` | **Umbral** (`OPEN-ACAD-49`, recomendación): un entero `unjustified_absence_threshold` del centro (`NULL` = sin alertas; 1 a 999). Cuando el número de **faltas** vivas sin justificar de una matrícula en su curso académico pasa a ser múltiplo exacto del umbral por **incremento** (N, 2N, 3N…), se crea una alerta con ese recuento. Como mucho una alerta por (matrícula, recuento). Los retrasos no cuentan. Bajar el recuento (justificar, corregir) no borra alertas; volver a alcanzar un recuento ya alertado no la duplica |
| `RN-ACAD-191` | La evaluación se hace **en la misma transacción** que la escritura que añade faltas (alta o corrección de un pase), solo para las matrículas afectadas: es una cuenta por matrícula, no una tarea pesada (`INV-012` no aplica). No necesita planificador (#453) |
| `RN-ACAD-192` | Una alerta se **atiende** (`PATCH /attendance-alerts/{id}` con `status: "atendida"`; guarda quién y cuándo) y no se reabre ni se borra por la API. La ve quien alcanza la matrícula con `asistencia.leer` de ámbito `todos` o `grupo` (no `clase` ni `unidad_familiar`: es una herramienta de tutoría) |
| `RN-ACAD-193` | Cambiar el umbral no recalcula ni borra alertas existentes; rige para las escrituras posteriores |

---

## 4. Casos límite y errores

| Caso | Comportamiento |
|------|----------------|
| Docente sin asignación en el curso | Hoja del día vacía; listados vacíos; detalle `404` |
| Sustituto sin asignación en ese grupo | No alcanza el grupo: pasa lista jefatura (ámbito `todos`) hasta que existan guardias (fase 2). Limitación escrita en el manual |
| Dos docentes de la misma clase (`RN-ACAD-94`) pasan la misma sesión a la vez | Uno `201`; el otro `409 roll_exists` y la interfaz le abre el pase existente |
| El tutor justifica mientras el docente corrige el pase | Justificar **no** sube la versión del pase (no cambia el conjunto de marcas). El `PUT` del docente se acepta si no toca la marca justificada; si la quita o le cambia el tipo ⇒ `422 mark_justified` (`RN-ACAD-171`) |
| Pase diario existente y alguien intenta un pase de sesión ese día (o al revés) | `422 roll_kind_conflict` |
| Grupo sin matrículas en la fecha | Lista vacía; se puede guardar el pase (consta que se pasó) |
| Alumno dado de baja con efecto anterior a un pase ya guardado | Sus marcas se conservan; no aparece en la lista de pases nuevos |
| Alumno cambiado de grupo | Marcas antiguas, con su pase del grupo anterior; el tutor nuevo no las ve por `grupo` (el pase es del otro grupo). Con `todos` se ven todas. Limitación aceptada (`OPEN-ACAD-55`) |
| Matrícula sin grupo (`ADR-071`) | No está en ninguna lista: no se le puede pasar lista |
| Sesión del horario movida de franja tras pasar lista | El pase conserva sesión, fecha y asignatura; no se revalida |
| Curso cerrado mientras había pases sin sincronizar | `409 academic-year-closed`; el dispositivo lo muestra como no enviable y permite descartarlo (§10.6) |
| Usuario que pasó lista, después desactivado o borrado | El pase se conserva; el nombre se resuelve por `UserDirectory` (`acad.staff.removed` si procede, precedente `CA-ACAD-158`) |
| Docente que además es tutor legal de un alumno de otro grupo | Unión de ámbitos; por `unidad_familiar` solo ve lo de `RN-ACAD-182` |
| Módulo no contratado | `403 urn:pge:error:module-disabled` antes del permiso (`RMOD-009`) |
| `alum` o `acad` descontratado con asistencia contratada | Imposible por `depends_on` (`RMOD-006`) |

---

## 5. Modelo de datos

Todas las tablas son **de tenant**, creadas con `TenantMigration::tenantTable()`: `id bigint`, `tenant_id`, RLS `ENABLE` + `FORCE` con la política estándar, `UNIQUE (tenant_id, id)`, `created_at`/`updated_at`/`deleted_at` (`TIMESTAMPTZ`) y `created_by`/`updated_by` (`ADR-033`, `ADR-034 §6`/`§7`, `INV-005`). Solo se listan columnas propias. Toda FK a otra tabla de tenant es **compuesta** `(tenant_id, …)` con `tenantForeignId()`. `academic_year_id` se declara con `tenantForeignId($blueprint, 'academic_year_id', 'academic_years')`, que engancha `academic_year_write_guard` (`ADR-057`, `AR-13`); **ninguna** FK usa `CASCADE`, `SET NULL` ni `SET DEFAULT`. Sin `ENUM`: `text` + `CHECK` (`AR-04`). `public_id` `character(26)` ULID con único propio (`AR-05`). Todo es aditivo (*expand*): ninguna tabla existente cambia. No hay importes. `db-reviewer` obligatorio.

Las FK a tablas de `acad` (`groups`, `subjects`, `timetable_sessions`), de `alum` (`enrollments`) y del núcleo (`users`) están permitidas; **la lectura va siempre por interfaces de `Domain`** (`INV-007`, `AR-01`).

### 5.1 `attendance_rolls` — pase de lista · `1.13-a`

| Columna | Tipo | Nulo | Defecto | Descripción |
|---------|------|------|---------|-------------|
| `public_id` | `character(26)` | No | — | ULID |
| `academic_year_id` | `bigint` | No | — | El del grupo. Con disparador |
| `group_id` | `bigint` | No | — | FK compuesta `(tenant_id, academic_year_id, group_id)` → `groups`, para que el curso no pueda divergir. **Comprobar** que `groups` tiene el único `(tenant_id, academic_year_id, id)`; si no, lo decide `db-reviewer` (FK simple más validación del servicio) |
| `roll_date` | `date` | No | — | Fecha civil del centro (`ADR-038 §3.2`) |
| `timetable_session_id` | `bigint` | Sí | `NULL` | FK compuesta → `timetable_sessions`. `NULL` = pase diario |
| `subject_id` | `bigint` | Sí | `NULL` | Asignatura de la sesión, desnormalizada para el ámbito `clase` y los informes (`RN-ACAD-165`). FK compuesta → `subjects` |
| `version` | `integer` | No | `1` | Control optimista (`RN-ACAD-170`). `CHECK (version >= 1)` |
| `taken_by` | `bigint` | No | — | FK compuesta → `users` |
| `recorded_at` | `TIMESTAMPTZ` | Sí | — | Instante declarado por el dispositivo (`RN-ACAD-172`) |

Restricciones:

- `CHECK ((timetable_session_id IS NULL) = (subject_id IS NULL))`.
- Unicidad del pase de sesión: `UNIQUE (tenant_id, timetable_session_id, roll_date) WHERE timetable_session_id IS NOT NULL AND deleted_at IS NULL`.
- Unicidad del pase diario: `UNIQUE (tenant_id, group_id, roll_date) WHERE timetable_session_id IS NULL AND deleted_at IS NULL`.
- La exclusión diario/sesión de `RN-ACAD-163` **no** la garantiza el motor: la garantiza el servicio bajo la serialización de §5.4.

### 5.2 `attendance_marks` — marca · `1.13-a` (justificación: `1.13-b`)

| Columna | Tipo | Nulo | Descripción |
|---------|------|------|-------------|
| `public_id` | `character(26)` | No | ULID |
| `academic_year_id` | `bigint` | No | El del pase (desnormalizado para el disparador y `AcademicYearReadAccess`) |
| `roll_id` | `bigint` | No | FK compuesta `(tenant_id, academic_year_id, roll_id)` → `attendance_rolls (tenant_id, academic_year_id, id)` (exige ese único en `attendance_rolls`, patrón `enrollments_tenant_year_id_unique`) |
| `enrollment_id` | `bigint` | No | FK compuesta `(tenant_id, academic_year_id, enrollment_id)` → `enrollments (tenant_id, academic_year_id, id)` (el único ya existe desde `1.15-c`) |
| `mark_type` | `text` | No | `CHECK (mark_type IN ('falta','retraso'))` |
| `justified_at` | `TIMESTAMPTZ` | Sí | `1.13-b`. Columnas creadas ya en `1.13-a` para no migrar dos veces una tabla que crecerá |
| `justified_by` | `bigint` | Sí | FK compuesta → `users`. `CHECK ((justified_at IS NULL) = (justified_by IS NULL))` |

Restricción: `UNIQUE (tenant_id, roll_id, enrollment_id) WHERE deleted_at IS NULL` (`RN-ACAD-168`).

### 5.3 `attendance_settings` y `attendance_alerts` · `1.13-b`

**`attendance_settings`** — una fila por centro, **sin `academic_year_id`** (el umbral es del centro, no del curso; no lo congela el cierre):

| Columna | Tipo | Nulo | Descripción |
|---------|------|------|-------------|
| `public_id` | `character(26)` | No | ULID |
| `unjustified_absence_threshold` | `integer` | Sí | `NULL` = sin alertas. `CHECK (unjustified_absence_threshold BETWEEN 1 AND 999)` |

Restricción: `UNIQUE (tenant_id) WHERE deleted_at IS NULL`. La fila se crea al primer `PUT` (crear-o-leer bajo el único, precedente `RN-ACAD-115`); sin fila, el umbral es `NULL`. **Alternativa a comprobar en implementación**: si `Core` ya ofrece a los módulos un almacén de ajustes del centro con escritura (`TenantSettingsReader` solo lee), usarlo y no crear esta tabla; decide `architect` (`OPEN-ACAD-58`).

**`attendance_alerts`**:

| Columna | Tipo | Nulo | Defecto | Descripción |
|---------|------|------|---------|-------------|
| `public_id` | `character(26)` | No | — | ULID |
| `academic_year_id` | `bigint` | No | — | El de la matrícula. Con disparador |
| `enrollment_id` | `bigint` | No | — | FK compuesta con el curso → `enrollments` |
| `group_id` | `bigint` | No | — | Grupo de la matrícula al crearse la alerta (para el ámbito `grupo`) |
| `absence_count` | `integer` | No | — | Recuento que la disparó. `CHECK (absence_count >= 1)` |
| `threshold` | `integer` | No | — | Umbral vigente al crearla |
| `status` | `text` | No | `'abierta'` | `CHECK (status IN ('abierta','atendida'))` |
| `attended_by` | `bigint` | Sí | — | FK compuesta → `users` |
| `attended_at` | `TIMESTAMPTZ` | Sí | — | `CHECK ((status = 'atendida') = (attended_by IS NOT NULL AND attended_at IS NOT NULL))` |

Restricción: `UNIQUE (tenant_id, enrollment_id, absence_count) WHERE deleted_at IS NULL` (`RN-ACAD-190`).

### 5.4 Serialización de escrituras de un (grupo, fecha)

`RN-ACAD-174` necesita exclusión mutua entre escrituras del mismo grupo y fecha. Tres formas; **la elige `db-reviewer`/`architect` antes de la migración**:

| Opción | Ventajas | Riesgos |
|--------|----------|---------|
| **A (recomendada)** · Bloqueo consultivo de transacción (`pg_advisory_xact_lock`) con clave derivada de (centro, grupo, fecha), tomado por el único servicio que escribe pases | Sin tabla ni fila ancla; no bloquea filas de `acad`; se libera solo | Si otro escritor futuro no lo toma, la exclusión se pierde: por eso un solo servicio (precedente `RN-ACAD-125`) y un criterio de concurrencia con dos procesos (`CA-ACAD-170`) |
| B · Fila ancla `attendance_days (grupo, fecha, tipo)` con único, a la que referencian los pases con FK compuesta por tipo | El motor garantiza la exclusión diario/sesión | Una tabla y una escritura más por pase; más auditoría |
| C · `FOR UPDATE` de la fila del grupo | Sin nada nuevo | Bloquea una fila de `acad` desde otro módulo (necesita interfaz); contención con ediciones de estructura |

Orden de bloqueos: `FOR SHARE` del curso (`AcademicYearSharedLock`) → serialización de (grupo, fecha) → filas del pase. Nunca `FOR UPDATE` del curso.

### 5.5 Índices

| Tabla | Índice | Consulta |
|-------|--------|----------|
| `attendance_rolls` | Los dos únicos parciales de §5.1 | Clave natural; hoja del día por sesión |
| `attendance_rolls` | `(tenant_id, academic_year_id, group_id, roll_date)` | Listado e informes por grupo e intervalo; ámbito `grupo`; cubre las FK a curso y grupo por prefijo |
| `attendance_rolls` | `(tenant_id, academic_year_id, id)` único | Destino de la FK compuesta de las marcas |
| `attendance_rolls` | `(tenant_id, group_id, subject_id)` `WHERE subject_id IS NOT NULL` | Ámbito `clase` (pares) |
| `attendance_marks` | Único parcial `(tenant_id, roll_id, enrollment_id)` | Una marca por alumno y pase; marcas de un pase |
| `attendance_marks` | `(tenant_id, academic_year_id, enrollment_id) WHERE deleted_at IS NULL` | Informe por alumno; recuento del umbral; ámbito `unidad_familiar`; veto de anulación de matrícula |
| `attendance_alerts` | Único parcial `(tenant_id, enrollment_id, absence_count)`; `(tenant_id, academic_year_id, status, created_at DESC, id DESC)` | `RN-ACAD-190`; bandeja de alertas |

Índices de FK restantes: los añade `tenantForeignId()` o los pide `AR-16`; `db-reviewer` quita redundancias.

**Particionado: no, con disparador de revisión escrito.** Con marcas solo de excepciones (`RN-ACAD-166`), un centro de 1.200 alumnos produce del orden de decenas de miles de marcas y de pases por curso (estimación **sin medir**: depende del absentismo real y de cuántas sesiones se pasen). Si `attendance_marks` superara los 20 millones de filas en una instalación, se revisa el particionado por `academic_year_id`, que la clave compuesta con el curso ya hace posible (aviso de `REQ-CURSO/funcional.md` sobre `RDB-001`). `CA-ACAD-176` repite la medición de #382 sobre `attendance_marks`.

### 5.6 Auditoría (`ADR-035`) y *morph map*

| Modelo | Alias | Política | No registrado |
|--------|-------|----------|---------------|
| `AttendanceRoll` | `attendance_roll` | `Full` | — (ids, fecha, versión) |
| `AttendanceMark` | `attendance_mark` | `Full` | — (tipo, ids, instantes) |
| `AttendanceSetting` | `attendance_setting` | `Full` | — |
| `AttendanceAlert` | `attendance_alert` | `Full` | — |

Ninguna tabla guarda nombre, documento ni texto libre. Escrituras **por instancia** (`AR-15`). `recorded_at` aparece en la entrada `created` del pase, de modo que la diferencia entre el momento declarado y el de recepción queda trazada.

---

## 6. API

Convenciones de `ADR-038`: prefijo `/api/v1`, JSON, errores `application/problem+json` con `code` estable y `params`, fechas `YYYY-MM-DD`, instantes ISO 8601 con zona, paginación `page`/`per_page` (máximo 100) con `meta`, `Accept-Language` para los mensajes. Todas las rutas llevan `RequireAuthenticatedUser`, `module-enabled:<código>` y `permission:` (`AR-07a`, `AR-07b`). Toda ruta nueva entra en `openapi.yaml`.

### 6.1 *Endpoints*

| # | Verbo y ruta | Permiso | Entrega | Qué hace |
|---|--------------|---------|---------|----------|
| 1 | `GET /attendance/day-sheet` | `asistencia.leer` | a | Hoja del día. `date` (por defecto hoy en la zona del centro), `group` opcional. Devuelve las sesiones del horario del usuario ese día y, con `group`, ese grupo; cada entrada con grupo, asignatura, franja, **lista** (`enrollment`, `name`) y el pase existente (`public_id`, `version`, `marks`) o `null`. Tope: 20 entradas (`422 day_sheet_too_large`). Es también la carga que la PWA guarda para trabajar sin conexión (§10) |
| 2 | `GET /attendance-rolls` | `asistencia.leer` | a | Listado paginado. `academic_year` **obligatorio**; filtros `group`, `from`, `to`, `timetable_session`. Orden `-roll_date`, `id` |
| 3 | `GET /attendance-rolls/{public_id}` | `asistencia.leer` | a | Detalle con marcas, lista y `version` |
| 4 | `POST /attendance-rolls` | `asistencia.crear` | a | Alta (`RN-ACAD-169`). `Idempotency-Key` obligatoria |
| 5 | `PUT /attendance-rolls/{public_id}` | `asistencia.actualizar` | a | Corrección por conjunto con `version` (`RN-ACAD-170`) |
| 6 | `DELETE /attendance-rolls/{public_id}` | `asistencia.eliminar` | a | Anulación (`RN-ACAD-173`) |
| 7 | `GET /attendance-marks` | `asistencia.leer` | b | Marcas de una matrícula (`enrollment` obligatorio; `from`, `to`) |
| 8 | `GET /attendance-summary` | `asistencia.leer` | b | Resumen por grupo e intervalo (`RN-ACAD-187`). Sin paginar; un grupo tiene decenas de filas |
| 9 | `POST /attendance-justifications` | `asistencia.aprobar` | b | Justifica un intervalo (`RN-ACAD-184`). `Idempotency-Key` obligatoria |
| 10 | `DELETE /attendance-marks/{public_id}/justification` | `asistencia.aprobar` | b | Retira la justificación |
| 11 | `GET /attendance-settings` | `configuracion_asistencia.leer` | b | Umbral |
| 12 | `PUT /attendance-settings` | `configuracion_asistencia.actualizar` | b | Fija el umbral |
| 13 | `GET /attendance-alerts` | `asistencia.leer` | b | Paginado. `academic_year` obligatorio; `status`, `group` |
| 14 | `PATCH /attendance-alerts/{public_id}` | `asistencia.actualizar` | b | Marca como atendida |

Ningún *endpoint* de exportación ni de borrado de marcas sueltas (una marca se quita corrigiendo el pase).

### 6.2 Cuerpos principales

**`POST /attendance-rolls`** (petición): `group` (`public_id`), `date`, `timetable_session` (`public_id` o `null`), `recorded_at` (opcional), `marks`: lista de `{enrollment, type}` con `type` ∈ `falta`/`retraso`, sin matrículas repetidas (`422 marks_duplicated`), como mucho 200 (`422 marks_too_many`).

**`PUT /attendance-rolls/{id}`**: `version` (entero), `recorded_at` (opcional), `marks` (lista completa).

**Respuesta de un pase**: `public_id`, `academic_year` (`public_id`), `group` (`public_id`, `name`), `date`, `kind` (`diaria`/`sesion`), `timetable_session` (`public_id`, franja) o `null`, `subject` (`public_id`, nombre resuelto) o `null`, `version`, `taken_by` y `updated_by` (`public_id`, nombre visible), `recorded_at`, `created_at`, `updated_at`, `marks`: `{public_id, enrollment, name, type, justified}`.

### 6.3 Códigos de error propios

Prefijo de `code` según el módulo que decida `OPEN-ACAD-45` (aquí `acad.` por continuidad; con módulo propio sería el suyo).

| HTTP | `code` | Cuándo |
|------|--------|--------|
| `422` | `…validation.roll_kind_conflict` | `RN-ACAD-163` |
| `422` | `…validation.roll_outside_academic_year`, `roll_date_in_future`, `recorded_at_invalid` | `RN-ACAD-164`, `-172` |
| `422` | `…validation.session_not_on_date`, `parent_not_found`, `parent_immutable`, `academic_year_not_allowed` | `RN-ACAD-160`, `-161`, `-165` |
| `422` | `…validation.enrollment_not_in_roll_group`, `mark_type_invalid`, `marks_duplicated`, `marks_too_many` | `RN-ACAD-167`, `-168` |
| `422` | `…validation.mark_justified` | `RN-ACAD-171` |
| `422` | `…validation.justification_range_invalid`, `range_outside_academic_year`, `threshold_invalid`, `day_sheet_too_large` | `1.13-b`, hoja del día |
| `409` | `…conflict.roll_exists` (`params.roll`, `params.version`) | `RN-ACAD-169` |
| `409` | `…conflict.roll_version_conflict` (`params.version`, `params.marks`, `params.updated_by`, `params.updated_at`) | `RN-ACAD-170` |
| `409` | `…conflict.has_dependents` | `RN-ACAD-173` |
| `409` | `urn:pge:error:academic-year-closed` | Curso de solo lectura (de `curso`) |
| `404` | — | Inexistente, de otro centro, fuera de ámbito o de curso ilegible |
| `403` | `urn:pge:error:forbidden` / `module-disabled` | Sin permiso / módulo no contratado |
| `428` o `400` | el de `RequireIdempotencyKey` | Falta `Idempotency-Key` (el código existente; **comprobar** cuál devuelve) |

`params` de un conflicto de versión contiene nombres de alumnos del propio pase que el actor ya alcanza; ningún error revela datos de un pase fuera de ámbito.

---

## 7. Permisos y ámbitos

### 7.1 Catálogo (matriz recurso × acción × ámbito)

| Código | Ámbitos admitidos (`applicable_scopes`) | Categoría especial | Entrega |
|--------|------------------------------------------|--------------------|---------|
| `asistencia.leer` | `todos`, `grupo`, `clase`; **`unidad_familiar` desde `1.13-b`** (ampliación compatible, precedente `RN-ALUM-61`) | No | a / b |
| `asistencia.crear` | `todos`, `grupo`, `clase` | No | a |
| `asistencia.actualizar` | `todos`, `grupo`, `clase` | No | a |
| `asistencia.eliminar` | `todos`, `grupo` | No | a |
| `asistencia.aprobar` (justificar) | `todos`, `grupo` | No | b |
| `configuracion_asistencia.leer` | `todos` | No | b |
| `configuracion_asistencia.actualizar` | `todos` | No | b |

Sin `exportar` ni `importar` (sin *endpoint*; `REQ-ACAD/permisos.md §2.1`). `resource_label_key` en los cuatro idiomas. La asistencia **no** es categoría especial: no hay motivo ni dato de salud (`RN-ACAD-168`, `-184`); si `OPEN-ACAD-48` introdujera un motivo médico, pasaría a serlo y exigiría tabla separada cifrada, permiso propio y auditoría de lectura (`RPERM-012`, `CLAUDE.md §8`).

### 7.2 Resolutores (los registra el módulo dueño del recurso, `ADR-063 §5.2`)

| Par (ámbito, recurso) | Restricción | Consume | Entrega |
|-----------------------|-------------|---------|---------|
| (`grupo`, `asistencia`) | `RN-ACAD-180` | `TeachingMembership::groupIdsFor()` (existe) | a |
| (`clase`, `asistencia`) | `RN-ACAD-180` | `TeachingMembership::classPairsFor()` (**a construir**, §8.2) | a |
| (`unidad_familiar`, `asistencia`) | `RN-ACAD-182` | `FamilyMembership` (existe) y una interfaz de `alum` que traduzca personas de alumno a matrículas (**a construir**, §8.2) | b |

Los resolutores actúan sobre los modelos `AttendanceRoll`, `AttendanceMark` y `AttendanceAlert`; un modelo inesperado, un usuario sin asignación o un curso ilegible ⇒ ninguna fila (fallo en cerrado, patrón `AlumnoGrupoScopeResolver`). El recurso `asistencia` entra en el **mapa cerrado de `AR-10`**, que solo se amplía con aprobación expresa: **esta especificación la pide**. Toda consulta de esos modelos pasa por `ScopedQuery` desde un único servicio sancionado.

### 7.3 Siembra propuesta de roles predefinidos (`OPEN-ACAD-51`)

| Rol | Concesiones |
|-----|-------------|
| `administrador_centro` | Todo, `todos` |
| `direccion` | `asistencia.*` y `configuracion_asistencia.*`, `todos` |
| `secretaria` | `asistencia.leer` y `asistencia.aprobar`, `todos` |
| `docente` | `asistencia.leer`, `.crear`, `.actualizar` con **`clase`** |
| `tutor_grupo` | `asistencia.leer`, `.crear`, `.actualizar`, `.eliminar`, `.aprobar` con **`grupo`** |
| `orientador` | `asistencia.leer`, `todos` |
| `tutor_legal` | `asistencia.leer` con `unidad_familiar` (`1.13-b`) |
| `estudiante`, resto | Nada (el portal del alumnado es `1.23`) |

Ningún rol docente recibe `todos` (`ADR-063 §5.4`, `RN-ALUM-65`). Consecuencia: un docente que no es tutor **no puede hacer el pase diario** de un grupo (un pase diario no se alcanza por `clase`, `RN-ACAD-180`); en etapas con pase diario, quien lo pasa necesita `grupo`. Comando de siembra con la forma de `acad:grant-structure-permissions` (comprueba `retired_at IS NULL`).

---

## 8. Interacción con otros módulos, descriptor y módulo desactivado

### 8.1 Descriptor (recomendación de `OPEN-ACAD-45`: módulo nuevo)

| Campo | Valor propuesto |
|-------|-----------------|
| `code` | `asistencia` |
| `name_key` | `modules.asistencia` (es «Asistencia», en «Attendance», de «Anwesenheit», fr «Assiduité») |
| `phase` | `1` |
| `depends_on` | `['curso', 'acad', 'alum']` en `1.13-a`; se añade `'fam_unit'` en `1.13-b` (ya es dependencia transitiva por `alum`) |
| `essential` | `false` |
| `feature_flags` | Ninguno |

Generado con `php artisan make:module` (`ADR-056 §3.5`). Sin ciclo: `alum` no depende de él.

### 8.2 Lo que consume, y lo que hay que construir en otros módulos

| De | Qué | Estado | Trabajo |
|----|-----|--------|---------|
| `curso` | `AcademicYearContext`, `AcademicYearDirectory`, `AcademicYearReadAccess`, `AcademicYearSharedLock` | Existen | — |
| `acad` | `TeachingMembership::groupIdsFor()` | Existe | — |
| `acad` | **`TeachingMembership::classPairsFor(int $userId, int $academicYearId): list<array{group_id: int, subject_id: int}>`** | Especificada (`REQ-ACAD/funcional.md §18.7`), **no construida** | Se construye en `1.13-a` (primer consumidor, `ADR-048`); `CA-ACAD-114` ya prueba la consulta |
| `acad` | **Interfaz nueva de lectura del horario** (nombre propuesto `TimetableSessionDirectory`): sesiones de un usuario en una fecha (con grupo, asignatura, franja y marco vigente ese día), y resolver una sesión por `public_id` con «¿ocurre en esta fecha?» (`RN-ACAD-165`) | **No existe**; `§18.7` la anuncia como hueco («portales y PDF leerán las sesiones por una interfaz de `Acad\Domain`») | **Ampliación de `acad` a aprobar con esta especificación.** Ids internos, solo filas vivas, memoizada por petición |
| `acad` | `GroupDirectory` (resolver grupo por `public_id`, nombre), nombre de asignatura; `StructureDeletionVetoRegistry` | Existen (`1.15-b`); **comprobar** que hay resolución del nombre traducido de una asignatura por id | Si falta, ampliación mínima a aprobar |
| `alum` | **`EnrollmentRoster::activeEnrollmentsOfGroup(int $groupId, date)`** | Especificada (`REQ-ALUM/funcional.md §7.2`), no construida | Se construye en `1.13-a`. Debe devolver id de matrícula, `public_id` e id de persona |
| `alum` | **Registro de vetos de anulación de matrícula** | Especificado, no construido | Se construye en `1.13-a` (`RN-ACAD-175`) |
| `alum` | **Traducción persona de alumno → matrículas** para (`unidad_familiar`, `asistencia`) | No especificada con esa firma (`StudentEnrollmentDirectory::currentEnrollmentsFor` es por fecha) | **Ampliación de `alum` a aprobar** (`1.13-b`): matrículas de un conjunto de personas de alumno en un curso |
| `fam_unit` | `FamilyMembership` | Existe | `1.13-b` |
| `Core` | `UserDirectory` (nombres de quien pasó lista), `PersonDirectory` (nombre visible de alumnos por id de persona) | Existen; **comprobar** que `PersonDirectory` devuelve nombres visibles por lote | Si falta, ampliación mínima a aprobar antes de escribirla (precedente I-1) |
| núcleo | `TenantModel`, `Auditable`, `ScopedQuery`, `RequireIdempotencyKey` | Existen | — |

Ninguna de estas ampliaciones se escribe sin que el usuario la apruebe con esta especificación (`REQ-ACAD/funcional.md §18.7`: «ampliarla es un cambio que debe aprobarse antes de escribirlo»).

### 8.3 Contrato de presencia para `1.14b-d4` (obligación heredada): especificado, **no construido**

`REQ-PRL-004` necesita saber quién estaba hoy en el centro para la alerta de «alumno no recogido al cierre». `1.13` deja escrito el contrato; **lo construye `1.14b-d4`** con su consumidor (`ADR-048`), que además necesita #453 (planificador) y `1.19` (canal).

| Interfaz (en el `Domain` del módulo de asistencia) | Operación | Devuelve |
|-----------------------------------------------------|-----------|----------|
| `AttendancePresence` | `absentPersonIdsOn(date, list<int> studentPersonIds)` | De esas personas de alumno, las que constan **ausentes**: su **último** pase del día (por franja; el diario si es el único) tiene una `falta` viva |
| | `hasRollOn(date, list<int> studentPersonIds)` | Para cuáles existe algún pase ese día (sin pase no se sabe nada: `prl` no debe dar por presente a quien nadie pasó lista) |

Falla en cerrado (módulo no contratado ⇒ «no se sabe»). La semántica exacta («último pase», corte horario) la confirma la especificación de `1.14b-d4`; aquí queda como **propuesta**. Consecuencia para `prl`: tendría que declarar el módulo de asistencia en `depends_on` o tratar su ausencia como «no se sabe» (lo decide `1.14b-d4`, como `OPEN-ALUM-21`).

### 8.4 Eventos

| Evento | Cuándo | Consumidor previsto | En `1.13` |
|--------|--------|---------------------|-----------|
| `UnjustifiedAbsenceThresholdReached` (ids de matrícula, grupo y curso, recuento; **sin nombres**) | Al crear una alerta (`RN-ACAD-190`) | `REQ-COM` (`1.19`): aviso a los tutores legales con vínculo activo y, si se decide, al tutor de grupo | **Contrato escrito, no emitido** (`ADR-048`: sin consumidor no se construye). `1.19` lo activa. Hasta entonces la alerta solo se ve en la aplicación: **`REQ-ACAD-003` punto 3 queda cubierto parcialmente** y así debe constar en el plan |

No consume eventos. `UserDeactivated` no cambia nada (los pases se conservan).

### 8.5 Módulo desactivado

API: `403 urn:pge:error:module-disabled` en toda ruta (`RMOD-009`). SPA: sin entradas ni rutas (`RMOD-008`); la caché local de asistencia se borra al detectarlo (§10.6). Interfaces de §8.3: fallan en cerrado. Datos: se conservan (`RMOD-003`). Sin tareas programadas ni *listeners* (`RMOD-005`).

---

## 9. Interfaz (*shell*) y textos

### 9.1 Rutas

| Nombre | Ruta | `meta.permissions` | Menú | Contenido | Entrega |
|--------|------|--------------------|------|-----------|---------|
| `attendance-day` | `/asistencia` | `['asistencia.leer']` | Sí | Hoja del día (F1); selector de fecha (no futura) y de grupo | a |
| `attendance-roll` | `/asistencia/pase` (clave en la URL: `?group=&date=&session=`) | `['asistencia.leer']` | No | Lista con marcas; «Guardar» solo con `asistencia.crear`/`.actualizar` y curso escribible | a |
| `attendance-group-report` | `/asistencia/informes` | `['asistencia.leer']` | Sí | Resumen por grupo e intervalo; acceso al detalle por alumno | b |
| `attendance-student` | `/asistencia/alumno/:enrollment` | `['asistencia.leer']` | No | Marcas del alumno; justificar con `asistencia.aprobar` | b |
| `attendance-alerts` | `/asistencia/alertas` | `['asistencia.leer']` | Sí | Bandeja de alertas | b |
| `attendance-settings` | `/asistencia/ajustes` | `['configuracion_asistencia.leer']` | Sí, `administracion` | Umbral | b |

`meta.academicYearScoped: true` en informes y alertas; la hoja del día usa siempre el curso que contiene la fecha.

### 9.2 Pase de lista en móvil (`RUX-RESP`, `ADR-054`, WCAG 2.2 AA)

- Diseñada **primero a 320 px**: lista de una columna, una fila por alumno con nombre y un control de tres estados (presente, falta, retraso) con objetivo táctil de al menos 44 × 44 px (WCAG 2.5.8 pide 24; se elige 44 por uso con una mano) y sin gestos como única vía (WCAG 2.5.1, 2.5.7).
- El estado se comunica con **texto e icono**, nunca solo con color (WCAG 1.4.1); cada control es un grupo de botones de opción con nombre accesible «Asistencia de {nombre}».
- Contador visible «N faltas, M retrasos» con `aria-live="polite"`; «Guardar» fijo al pie.
- Sin fotografía (`RN-ACAD-162`).
- Estado de envío siempre con texto: «Guardado», «Sin guardar» y, en `1.13-c`, «Pendiente de enviar» y «Conflicto».
- Componentes de shadcn-vue; reglas de `docs/design-system.md §22`; sin dependencia nueva en `1.13-a` y `1.13-b`.

### 9.3 Textos (`INV-009`)

Todo literal en `lang/{es,en,de,fr}/<código>.php` y `apps/web/src/modules/<módulo>/locales/{es,en,de,fr}.json`; paridad por `AR-12` y `lint:i18n`. Tratamiento formal en `de` («Sie») y `fr` («vous»). Términos de partida: «Pase de lista» / «Roll call» / «Anwesenheitskontrolle» / «Appel»; «Falta» / «Absence» / «Fehlzeit» / «Absence»; «Retraso» / «Late arrival» / «Verspätung» / «Retard»; «Justificada» / «Excused» / «Entschuldigt» / «Justifiée». Los revisa `traductor` (más de 15 claves). **No son textos legales**, salvo el aviso de privacidad del modo sin conexión (§10.6), que **no se traduce automáticamente** y necesita redacción y revisión humana en los cuatro idiomas.

---

## 10. Paso de lista sin conexión: decisión de arquitectura (borrador de ADR)

> **Esta sección es una propuesta, no una decisión.** Introduce el primer *service worker* del producto, el primer almacenamiento de datos personales de menores en el dispositivo y, según la opción, una dependencia nueva. Es una decisión estructural que `CLAUDE.md §6.3` manda fijar en un ADR. **Pido número a la orquestadora** (§10.9); no tomo ninguno. `1.13-c` no se implementa hasta que ese ADR esté aceptado.

### 10.1 Contexto y restricciones

| Restricción | Fuente |
|-------------|--------|
| El paso de lista debe funcionar sin cobertura y sincronizar al recuperarla, **con resolución de conflictos definida** | `RMOB-COM-001` |
| En fase 1 el canal móvil es la **PWA**; no hay aplicación nativa hasta la fase 3 | `ADR-008`, `§14` |
| Sin datos sensibles en claro en el dispositivo; borrado local al cerrar sesión o tras revocación remota | `RMOB-COM-003`, `-004` |
| Actualización forzada ante cambios incompatibles de API | `RMOB-COM-002` |
| Autenticación por cookie de sesión `httpOnly` con CSRF; prohibido un token en `localStorage`; sesión con caducidad por inactividad (30 minutos por defecto) | `CLAUDE.md §8`, `REQ-AUTH-005` |
| Toda regla se valida en el servidor; la autorización se comprueba en cada petición | `INV-010`, `INV-002` |
| Dependencia nueva solo justificada y tras una interfaz propia | `CLAUDE.md §1`, `RNF-MANT-007` |
| Hoy `apps/web` no tiene *service worker*, manifiesto de aplicación ni almacenamiento local | Búsqueda en el repositorio (H6) |

Lo que hace difícil el problema: (1) cuando el docente recupera cobertura, **su sesión habrá caducado** casi siempre; (2) lo que se guarda para trabajar sin conexión son **nombres de menores**; (3) dos personas pueden haber tocado el mismo pase.

### 10.2 Opciones

| | Opción | Ventajas | Riesgos | Coste de revertir |
|---|--------|----------|---------|-------------------|
| **A (recomendada)** | **Cola de salida propia en IndexedDB, gestionada por la aplicación**, más un *service worker* que solo sirve el armazón de la aplicación (HTML, JS, CSS). La sincronización la lanza la propia aplicación cuando está abierta y hay red | Funciona igual en todos los navegadores; el envío pasa por el cliente de API normal (cookie, CSRF, errores, idioma); la aplicación puede pedir que se vuelva a iniciar sesión antes de enviar; los conflictos se resuelven en pantalla | No sincroniza con la aplicación cerrada: el docente debe volver a abrirla. Hay que escribir y probar la cola | Bajo: es código del módulo web; quitarlo no toca la API |
| B | **Background Sync** del navegador (p. ej. con el módulo de Workbox): el *service worker* reintenta las peticiones fallidas en segundo plano | Envía aunque la aplicación esté cerrada | **No está disponible en Safari ni en Firefox** (MDN lo marca como de disponibilidad limitada; consultado el 2026-11-12, tabla de compatibilidad no vista directamente: **sin verificar** versión a versión), así que en iPhone y iPad haría falta igualmente la opción A. El reintento ciego desde el *service worker* no puede renovar una sesión caducada ni mostrar un conflicto | Medio: dos caminos de envío que mantener |
| C | **Sin modo sin conexión real**: reintento en memoria mientras la pestaña siga abierta | Nulo en arquitectura | Incumple `RMOB-COM-001`: cerrar la pestaña o que el sistema la descarte pierde el pase | — |
| D | Adelantar la aplicación nativa | Almacenamiento seguro del sistema, sincronización en segundo plano | Contradice `ADR-008` (fase 3); stack sin decidir (`ADR-007`); meses de trabajo | Alto |

**Recomendación: A.** B no evita construir A (por iOS) y añade un segundo camino; C incumple el requisito; D cambia un ADR vigente. El *service worker* de A puede escribirse a mano (decenas de líneas) o generarse con una librería de precarga: **la elección de librería y su comprobación de mantenimiento, licencia y frecuencia de versiones (`CLAUDE.md §1`) es del ADR**, con fuentes actuales; no fijo aquí ninguna de memoria.

### 10.3 Qué se guarda en el dispositivo y cuánto dura

| Dato | Para qué | Caducidad propuesta |
|------|----------|---------------------|
| Armazón de la aplicación (sin datos) | Abrir sin red | Hasta la siguiente versión |
| **Hoja del día** del usuario (sesiones, grupos, y por alumno **nombre y `public_id` de matrícula**; `RN-ACAD-162`) con la versión de cada pase existente | Pasar lista sin red | **Solo la fecha de hoy**; se borra al acabar el día (zona del centro) o a las **18 horas** de descargarse, lo que ocurra antes; y siempre al cerrar sesión |
| **Cola de salida**: pases pendientes (`public_id` de grupo, sesión y matrículas, tipo de marca, `recorded_at`, versión de base, clave de idempotencia). **Sin nombres** | Enviar al recuperar red | Hasta enviarse o hasta que el usuario lo descarte. A los 7 días sin enviar se marca «caducado: revisar» y no se envía solo (`OPEN-ACAD-52`) |
| Identidad mínima (centro, `public_id` del usuario) | Atar caché y cola a un usuario y un centro | Con la caché |

Nunca en el dispositivo: fotografías, contacto, documentos, justificaciones, alertas, informes, listas de otros días, credenciales ni *tokens*.

**Cifrado en reposo** (`RMOB-COM-003`): lo guardado en IndexedDB va cifrado con una clave simétrica generada en el navegador como **no extraíble** (Web Crypto). Hay que decir con claridad lo que esto protege y lo que no: protege frente a quien lee los ficheros del perfil del navegador; **no** protege frente a un XSS ni frente a quien usa el navegador desbloqueado del docente. La defensa real es guardar poco y poco tiempo (tabla de arriba), más la CSP estricta que ya exige `CLAUDE.md §8`.

**Revocación remota** (`RMOB-COM-004`): un dispositivo sin red no puede recibirla. La garantía es la **caducidad corta** de la hoja del día; al primer contacto con la API, un `401`, un `403` del módulo o un cambio de usuario borran caché y, según `OPEN-ACAD-52`, la cola.

### 10.4 Sincronización

1. **Precarga**: al abrir «Pasar lista» con red, la aplicación descarga la hoja del día (`GET /attendance/day-sheet`) y la guarda cifrada. Sin esa precarga no hay modo sin conexión ese día: la pantalla lo dice («Abre Pasar lista con conexión al empezar la jornada»).
2. **Trabajo sin red**: cada «Guardar» escribe un elemento en la cola con una **clave de idempotencia** nueva (ULID) y `recorded_at`. La interfaz lo muestra como «Pendiente de enviar».
3. **Envío**: con la aplicación abierta y red, la cola se envía **en orden y de uno en uno** por el cliente de API normal: `POST /attendance-rolls` si el pase no existía en la precarga, `PUT /attendance-rolls/{id}` con `version` si existía.
4. **Sesión caducada** (`401` o `419`): el envío se detiene, la cola **se conserva** y se pide iniciar sesión (con MFA si el rol lo exige). Solo se reanuda si quien entra es **el mismo usuario del mismo centro**; si es otro, `OPEN-ACAD-52`.
5. **Reintentos**: error de red o `5xx` ⇒ reintento con espera creciente, con la **misma** clave de idempotencia (`INV-011`); `429` respeta `Retry-After`. Un `4xx` de negocio no se reintenta: pasa a «Requiere revisión» con el mensaje del servidor.
6. **Idempotencia**: doble garantía, la clave de idempotencia y la clave natural del pase (`409 roll_exists` devuelve el pase existente y su versión, y el cliente continúa como corrección).

### 10.5 Conflictos: resolución definida (`RMOB-COM-001`)

Base: control optimista por `version` (`RN-ACAD-170`). **El servidor nunca fusiona ni sobrescribe en silencio**; decide el cliente con estas reglas, deterministas:

| Situación | Resolución |
|-----------|------------|
| El pase no existía en la precarga y sigue sin existir | Se crea |
| El pase no existía y **otro lo creó** (`409 roll_exists`) | Se trata como conflicto de versión con base vacía |
| Conflicto de versión, cambios **en alumnos distintos** (lo que cambió el dispositivo respecto a su base no toca a ningún alumno que haya cambiado en el servidor) | **Fusión automática**: se aplican los cambios del dispositivo sobre el estado actual del servidor y se reenvía con la versión nueva. Se avisa con un texto («Se han combinado tus cambios con los de {nombre}») |
| Conflicto de versión, **mismo alumno con valores distintos** | **No se decide solo.** Pantalla de conflicto: por cada alumno afectado, «en el centro consta X (por {nombre}, {hora}); tú marcaste Y», y el docente elige. Hasta que elija, el pase queda en «Conflicto» y no se envía |
| El dispositivo quita o cambia una marca ya **justificada** (`422 mark_justified`) | La justificación gana: la marca se conserva y se informa |
| `422 roll_kind_conflict`, `enrollment_not_in_roll_group`, `session_not_on_date`, `404`, `409 academic-year-closed` | No reintentable: «Requiere revisión», con el motivo; el docente lo corrige en línea o lo descarta. **Descartar exige confirmación** y nunca es automático |

Por qué no «gana el último que escribe»: dos docentes de la misma clase (`RN-ACAD-94`) o el docente y el tutor pueden marcar a alumnos distintos, y el último pisaría las faltas del primero sin que nadie lo viera. Por qué no fusionar en el servidor: obligaría a enviar deltas en vez de conjuntos y a un segundo camino de escritura; el conjunto completo con versión es más simple de probar (`INV-015`).

### 10.6 Sesión, cambio de usuario y privacidad

- La caché y la cola están **atadas a un usuario y un centro**. Otro usuario en el mismo dispositivo nunca ve la hoja del día ni la cola del anterior.
- **Cerrar sesión con pases sin enviar** (H7, `OPEN-ACAD-52`). Recomendación: se avisa («Tienes N pases sin enviar; si cierras sesión se perderán») y, si se confirma, **se borra todo**, como manda `RMOB-COM-004`. No se conserva nada cifrado «para luego».
- **Aviso de privacidad** al activar el modo sin conexión por primera vez en un dispositivo: qué se guarda, cuánto tiempo, y que no debe usarse en un dispositivo compartido sin cerrar sesión. Texto **legal**: redacción y traducción humanas (§9.3).
- **Dispositivos personales del profesorado**: que el centro permita guardar nombres de alumnos en el móvil personal de un docente es una decisión del responsable del tratamiento (el centro). **Pendiente de validación jurídica** (`OPEN-ACAD-47`); técnicamente se ofrece un ajuste del centro para desactivar el modo sin conexión.
- **Versión incompatible** (`RMOB-COM-002`): cada elemento de la cola lleva la versión de su formato; si la API deja de admitirla, responde con un error específico y la aplicación obliga a actualizar **antes** de enviar, sin perder la cola. El *service worker* nuevo no toma el control con una cola a medio enviar.

### 10.7 Límites conocidos de la plataforma web (a contrastar en el ADR)

| Hecho | Fuente y fecha | Consecuencia |
|-------|----------------|--------------|
| La API Background Synchronization no es de base amplia: disponible en navegadores Chromium, no en Firefox ni en Safari | MDN, «Background Synchronization API», consultado el 2026-11-12 por búsqueda (la tabla de compatibilidad no cargó: **verificar en el ADR**) | Motivo principal para A |
| Safari borra el almacenamiento escribible por *script* (IndexedDB y registro del *service worker* incluidos) de un sitio tras siete días de uso de Safari sin interacción con él; las aplicaciones web añadidas a la pantalla de inicio llevan su propio contador | WebKit (anuncio de ITP de 2020 y aclaraciones posteriores), consultado el 2026-11-12 por búsqueda. Fuentes de 2020-2022: **sin verificar para las versiones actuales de iOS** | Una cola sin enviar más de una semana en un iPhone puede perderse. Mitigación: aviso de pases pendientes al abrir, recomendación de instalar la aplicación en la pantalla de inicio, y `1.13-c` debe **probarse en un iPhone real** |
| Cuotas de almacenamiento y persistencia (`navigator.storage.persist()`) varían por navegador | **Sin verificar** | El volumen es de kilobytes; aun así el ADR lo confirma |

### 10.8 Qué no cubre esta propuesta

Sincronización con la aplicación cerrada; consulta de informes sin conexión; justificar sin conexión; uso sin conexión por familias; aplicación nativa (fase 3).

### 10.9 Solicitud de ADR

**Pido a la orquestadora el siguiente número libre (`ADR-084` en adelante)** para «Paso de lista sin conexión en la PWA: almacenamiento local de datos de menores, sincronización y conflictos». Debe decidir, con fuentes actuales: opción A/B/C/D, librería o *service worker* propio, cifrado y caducidades de §10.3, política de conflictos de §10.5, cierre de sesión con cola pendiente, y la matriz de navegadores soportados. Autor: `architect`. Estado de salida: `PROPUESTA`. Las secciones §10.1 a §10.8 son su borrador.

---

## 11. Clasificación, auditoría, datos de menores y operación

### 11.1 Clasificación del paso: **crítico** (`ADR-069 §4.2`)

| Entrega | Clasificación | Motivos (basta uno) |
|---------|---------------|---------------------|
| `1.13-a` | **crítico** | Migraciones que crean tablas con RLS; *endpoints*, permisos y **resolutores de ámbito** nuevos; ampliación del mapa de `AR-10`; *endpoints* que leen y escriben datos personales de menores |
| `1.13-b` | **crítico** | Tablas nuevas; permiso y resolutor nuevos (`unidad_familiar`); datos de menores expuestos a familias (custodia) |
| `1.13-c` | **crítico** | Sesión y autenticación (reanudación tras caducar); datos personales de menores fuera del servidor. Aunque casi todo sea código web, la duda es crítico |

Revisión: Codex primero, `security-reviewer` con su columna y `db-reviewer` en `-a` y `-b`.

### 11.2 Auditoría (`INV-003`)

- Toda alta, corrección, anulación, justificación, retirada de justificación, cambio de umbral y atención de alerta deja su entrada (`created`/`updated`/`deleted`) por los modelos `Auditable` (§5.6), con actor, IP, agente de usuario y valores antes/después.
- Un pase sincronizado tarde queda auditado **con el instante real de recepción**; `recorded_at` es el declarado.
- **Auditoría de lectura: no.** No es categoría especial (`RPERM-015` la exige para salud, NEAE y convivencia). Precedente: las listas de `alum` no registran lectura salvo la fotografía.
- La caché local no se audita (el servidor no la ve): lo auditable es la petición de la hoja del día, que es una lectura ordinaria.

### 11.3 Datos de menores (`INV-008`) y protección de datos

| Punto | Propuesta |
|-------|-----------|
| Base legal | El registro de asistencia es parte de la función educativa del centro: obligación legal o misión de interés público (art. 6.1.c/e RGPD; LOE, DA 23.ª), **no consentimiento**. **Pendiente de validación jurídica** (`OPEN-ACAD-47`); se registra en el registro de actividades (`PRIVACY.md`, §16) |
| Minimización | Solo excepciones (`RN-ACAD-166`); sin motivo, texto libre ni fichero (`RN-ACAD-168`, `-184`); respuestas sin foto ni contacto (`RN-ACAD-162`); errores e informes sin datos de terceros |
| Categoría especial | Ninguna mientras no haya motivo de la falta (H4) |
| Custodia | La lectura por familias usa solo vínculos activos (`RN-ACAD-182`); un tutor con acceso revocado no ve nada y no recibirá avisos (`1.19`) |
| Dispositivo | §10.3 y §10.6 |
| Retención | Sin decidir (`OPEN-ACAD-57`); hasta entonces se conserva con el curso académico |
| Datos de prueba | Solo `REQ-SEED` (`ADR-030`): nombres ficticios, nunca datos reales |

### 11.4 Operación

| Aspecto | Valor |
|---------|-------|
| Despliegue | Migraciones aditivas (*expand*); compatibles con la versión anterior, que no conoce las tablas. Reversión: `down()` elimina tablas nuevas; sin datos previos que perder en el primer despliegue. `1.13-c` despliega un *service worker*: su reversión es publicar uno que se desregistre y borre cachés (procedimiento a probar y escribir en `RUNBOOK.md`) |
| Variables de entorno | Ninguna nueva en `-a` y `-b`. `-c`: las que fije el ADR (caducidades como configuración del módulo, no secretos) |
| Colas | Ninguna: ningún trabajo pesado (`RN-ACAD-191`). No depende de Horizon ni del planificador (#453) |
| Tareas programadas | Ninguna |
| Caché de servidor | Ninguna entre peticiones (`ADR-044 §4.7`) |
| Límites | La sincronización de una cola puede emitir varias peticiones seguidas: **comprobar** el limitador de peticiones vigente de la API para que una cola de una jornada (hasta ~8 pases) no lo dispare |
| Observabilidad | `request_id` en cada envío (`INV-013`); contador de conflictos de versión en los registros, sin datos personales |
| CSP y cabeceras | El *service worker* se sirve desde el mismo origen de la SPA; **comprobar** en `1.13-c` que la CSP vigente lo permite (`worker-src`) sin relajarla |

---

## 12. Partición en entregas y tramos (`CLAUDE.md §2`, puntos 1 y 7)

El paso **no cabe en tres tramos**: se parte en tres entregas, cada una de ≤ 3 tramos secuenciales. Estimaciones **sin calibrar** contra `docs/metricas-agentes.md` (no leído); la sesión las ajusta.

| Entrega | Tramo | Costura | Contenido | Criterios | Estimación |
|---------|-------|---------|-----------|-----------|-----------|
| **`1.13-a`** | T1 | Datos y permisos | `classPairsFor` y `TimetableSessionDirectory` en `acad`; `EnrollmentRoster` y registro de vetos en `alum`; módulo (`make:module`); migraciones de `attendance_rolls` y `attendance_marks`; catálogo de permisos, resolutores `grupo` y `clase`, mapa de `AR-10`; siembra | `CA-ACAD-160`, `-161`, `-171` a `-176` | 80-100 llamadas |
| | T2 | Servicios y *endpoints* | Servicio único de pases; *endpoints* 1 a 6; OpenAPI; concurrencia | `CA-ACAD-162` a `-170` | 80-100 |
| | T3 | Interfaz | Hoja del día y pase en móvil; textos; *specs* de E2E (la ejecución final, `verificador`) | `CA-ACAD-177`, `-178` | 60-80 |
| **`1.13-b`** | T1 | Datos y permisos | Tablas de ajustes y alertas; `asistencia.aprobar`, `configuracion_asistencia.*`; ampliación de `alum` y resolutor `unidad_familiar` | `CA-ACAD-185`, `-186`, `-189` | 60-80 |
| | T2 | Servicios y *endpoints* | *Endpoints* 7 a 14; umbral en la transacción del pase | `CA-ACAD-180` a `-184`, `-187`, `-188` | 80-100 |
| | T3 | Interfaz | Informes, ficha del alumno, alertas, ajustes; E2E | `CA-ACAD-190` | 60-80 |
| **`1.13-c`** | T1 | Almacén local | *Service worker*, manifiesto, almacén cifrado, precarga y caducidades, borrado al cerrar sesión | `CA-ACAD-191`, `-192`, `-196` | 60-90 |
| | T2 | Sincronización | Cola, envío, reanudación tras iniciar sesión, fusión y pantalla de conflicto, versión incompatible | `CA-ACAD-193` a `-195`, `-197` | 80-100 |
| | T3 | E2E | Playwright con red cortada contra la API real; prueba manual en iPhone | `CA-ACAD-198`, `-199` | 40-60 |

Si T1 de `1.13-a` se alarga, su costura de reserva es «interfaces de `acad` y `alum`» / «módulo, migraciones y permisos», lo que la convertiría en `1.13-a1` y `1.13-a2`. Orden: `-a` → `-b` → `-c`; `-c` puede ir antes que `-b` si el usuario prioriza el modo sin conexión (solo depende de `-a` y del ADR).

---

## 13. Criterios de aceptación

Pest en `apps/api/tests/Feature/<Módulo>/` (concurrencia en `tests/Concurrency/`), Vitest y Playwright en `apps/web`. Cada test referencia su `CA-ACAD-NNN`, `REQ-ACAD-003` y las reglas que cita (`INV-015`); los de `1.13-c`, además `RMOB-COM-001`. Base de datos real (`ADR-033 §10`). «Centro de prueba»: un curso `activo` con el grupo 2A (asignaturas MAT y LEN, un subgrupo D1), cuatro matrículas activas, la docente P con asignación de MAT en 2A, el docente Q con LEN en 2A, la tutora T de 2A, y una sesión de MAT de P los lunes; el grupo 2B con el docente R. Datos ficticios (`REQ-SEED-005`).

### 13.1 Pase de lista (`1.13-a`)

- **`CA-ACAD-160`** (`RN-ACAD-160`, `ADR-057`, `AR-13`) — Las tablas con `academic_year_id` de §5 tienen el disparador; ninguna FK tiene acción referencial distinta de `NO ACTION`; con el curso `cerrado`, crear, corregir o anular un pase ⇒ `409 academic-year-closed` y nada cambia; `AR-13` en verde sin excepciones.
- **`CA-ACAD-161`** (`RN-ACAD-160`, `RN-CURSO-33`) — *Dado* un usuario con `asistencia.leer` de ámbito `todos` y sin `curso_historico.leer`, *cuando* pide el listado de pases de un curso `cerrado` y *cuando* pide por `public_id` un pase de ese curso, *entonces* `404` en ambos; con `curso_historico.leer`, los datos. Lo mismo para marcas, resumen y alertas en `1.13-b` (`CA-ACAD-189`).
- **`CA-ACAD-162`** (`RN-ACAD-166`, `-169`) — *Dada* P y un lunes del curso, *cuando* envía `POST /attendance-rolls` con 2A, la fecha, su sesión de MAT y una `falta` y un `retraso`, *entonces* `201` con `kind: "sesion"`, `version: 1`, `subject` MAT, dos marcas y `taken_by` P; hay una fila en `attendance_rolls`, **dos** en `attendance_marks` (ninguna de los dos alumnos presentes) y entradas `created` con alias `attendance_roll` y `attendance_mark`. Con `marks: []` ⇒ `201` y cero marcas.
- **`CA-ACAD-163`** (`RN-ACAD-169`, `INV-011`) — Repetir la misma petición con la misma `Idempotency-Key` ⇒ la respuesta original y ninguna fila nueva; con otra clave ⇒ `409 roll_exists` con `params.roll` y `params.version`; sin `Idempotency-Key` ⇒ el error del *middleware*.
- **`CA-ACAD-164`** (`RN-ACAD-163`) — Con un pase diario vivo de 2A en una fecha, un pase de sesión de 2A ese día ⇒ `422 roll_kind_conflict`, y al revés; tras anular el diario, el de sesión ⇒ `201`.
- **`CA-ACAD-165`** (`RN-ACAD-164`, `-165`) — Fecha de mañana ⇒ `422 roll_date_in_future`; fecha fuera del curso ⇒ `422 roll_outside_academic_year`; la sesión de los lunes con fecha de martes ⇒ `422 session_not_on_date`; una sesión de 2B con `group` 2A ⇒ `422 session_not_on_date` o `parent_not_found` (se fija al implementar); una fecha de la semana pasada dentro del curso ⇒ `201`; `academic_year` en el cuerpo ⇒ `422 academic_year_not_allowed`.
- **`CA-ACAD-166`** (`RN-ACAD-167`, `-168`) — Marca de una matrícula de 2B, de una matrícula dada de baja antes de la fecha o inexistente ⇒ `422 enrollment_not_in_roll_group` y nada escrito; `type: "ausente"` ⇒ `422 mark_type_invalid`; la misma matrícula dos veces ⇒ `422 marks_duplicated`. La lista de una sesión del subgrupo D1 es la de todo 2A.
- **`CA-ACAD-167`** (`RN-ACAD-170`) — *Dado* el pase de `CA-ACAD-162` (versión 1), *cuando* `PUT` con `version: 1` y un conjunto que conserva la falta, cambia el retraso a falta y añade una falta, *entonces* `200`, `version: 2`, y en auditoría una `updated` y una `created` (la conservada, ninguna); con el mismo conjunto otra vez y `version: 2` ⇒ `200`, sigue `version: 2` y sin entradas nuevas; quitar una marca ⇒ `deleted_at` en ella y una `deleted`.
- **`CA-ACAD-168`** (`RN-ACAD-170`) — `PUT` con `version: 1` cuando la actual es 2 ⇒ `409 roll_version_conflict` con `params.version: 2`, las marcas actuales, `updated_by` y `updated_at`; nada cambia. `PUT` sin `version` ⇒ `422`.
- **`CA-ACAD-169`** (`RN-ACAD-161`, `-172`, `-173`) — `group`, `date` o `timetable_session` en un `PUT` ⇒ `422 parent_immutable`; `recorded_at` una hora en el futuro ⇒ `422 recorded_at_invalid`; `DELETE` ⇒ `204`, pase y marcas con `deleted_at` y sus `deleted`, y la clave queda libre.
- **`CA-ACAD-170`** (`RN-ACAD-174`; dos procesos reales) — Dos `POST` simultáneos con la misma clave natural y distinta `Idempotency-Key` ⇒ exactamente una `201` y un `409 roll_exists`; *y en otra corrida*, un pase diario y uno de sesión simultáneos del mismo grupo y fecha ⇒ exactamente una `201` y un `422 roll_kind_conflict`; nunca dos pases ni `500`.

### 13.2 Permisos, ámbitos y aislamiento (`1.13-a`)

- **`CA-ACAD-171`** (`RN-ACAD-180`, `ADR-044 §4.2`; **listado y detalle**) — *Dada* P con `asistencia.leer` de ámbito `clase`, y pases de MAT (suyo), de LEN (de Q) y diario en 2A, y uno en 2B: el listado devuelve **solo** el de MAT; el detalle de cualquiera de los otros tres ⇒ `404`, igual que un `public_id` inexistente. *Dada* T con ámbito `grupo`: ve los tres de 2A y `404` en el de 2B. Con `todos`: los cuatro.
- **`CA-ACAD-172`** (`RN-ACAD-181`) — P (`asistencia.crear`, `clase`) crea el pase de su sesión ⇒ `201`; el de la sesión de LEN de Q, un pase diario de 2A o cualquiera de 2B ⇒ `404` y **ninguna fila ni entrada de auditoría** (la transacción se deshace). T (`grupo`) crea el diario de 2A ⇒ `201`; el de 2B ⇒ `404`. Corregir y anular: mismo reparto (`PUT`/`DELETE` fuera de ámbito ⇒ `404`).
- **`CA-ACAD-173`** (`RN-ACAD-180`) — Una asignación de P en otro curso académico no le da alcance sobre pases de este; al borrar su asignación deja de alcanzarlos en la petición siguiente (sin caché entre peticiones); un usuario sin asignación recibe hoja del día vacía y listado vacío.
- **`CA-ACAD-174`** (`permisos` §7; `AR-07a`, `AR-07b`, `AR-10`) — Cada *endpoint* responde `401` sin sesión y `403` sin su permiso; con el módulo no contratado, `403 module-disabled` antes del permiso; tras `platform:sync-registry` y la siembra, los roles predefinidos tienen exactamente las concesiones de §7.3 y ningún rol docente tiene `todos`; conceder `asistencia.crear` con `unidad_familiar` o `departamento` ⇒ `422`; `AR-10` en verde con el recurso `asistencia` en el mapa y ninguna consulta de los modelos fuera del servicio sancionado.
- **`CA-ACAD-175`** (`INV-001`) — Con dos centros equivalentes, ningún *endpoint* lee, cuenta, modifica ni borra datos del otro; `public_id` ajeno en ruta ⇒ `404`; en cuerpo (grupo, sesión, matrícula) ⇒ `422` de su campo; ningún nombre de alumno del otro centro aparece en una respuesta ni en `params` de un error.
- **`CA-ACAD-176`** (`RN-ACAD-162`, `-175`, `-176`, `ADR-029`, #382) — Ninguna respuesta contiene una clave `id`/`*_id` ni foto, documento, fecha de nacimiento o contacto; anular una matrícula con marcas vivas ⇒ el `409` del veto y la matrícula sigue; darla de **baja** ⇒ se admite y las marcas se conservan; borrar un grupo con pases vivos ⇒ `409 has_dependents`; `AcademicYearWriteGuardOverheadTest` repetido con `attendance_marks` y la cifra anotada.

### 13.3 Interfaz (`1.13-a`)

- **`CA-ACAD-177`** (§9.2; Vitest) — A 320 px la lista es de una columna sin desplazamiento horizontal; cada alumno tiene un grupo de opciones con nombre accesible y objetivos de al menos 44 × 44 px; el estado se lee como texto; un pase sin ausencias se guarda con dos activaciones desde la hoja del día; «Guardar» no aparece sin permiso o con el curso de solo lectura; `409 roll_exists` abre el pase existente; `lint:i18n` limpio y `AR-11` en verde.
- **`CA-ACAD-178`** (Playwright contra la **API real**, `CLAUDE.md §3`) — Como docente: abrir la hoja del día, pasar lista de una sesión con una falta y un retraso, reabrir y corregir; como tutora: ver el pase y hacer el diario de otro día; como docente de otro grupo: no ver nada de 2A.

### 13.4 Justificación, informes y alertas (`1.13-b`)

- **`CA-ACAD-180`** (`RN-ACAD-184`, `-186`) — *Dada* una matrícula con tres marcas sin justificar en tres días y T con `asistencia.aprobar` (`grupo`), *cuando* `POST /attendance-justifications` con un intervalo que cubre dos, *entonces* `200` con `justified: 2`, esas dos con `justified_at` y `justified_by` T y una `updated` cada una; repetir ⇒ `justified: 0`; intervalo de 40 días o `to < from` ⇒ `422 justification_range_invalid`; una matrícula de 2B ⇒ `404`.
- **`CA-ACAD-181`** (`RN-ACAD-171`, `-173`, `-185`) — Con una marca justificada: un `PUT` del pase que la quita o le cambia el tipo ⇒ `422 mark_justified` y nada cambia; uno que la conserva y cambia otras ⇒ `200`; `DELETE` del pase ⇒ `409 has_dependents`; `DELETE …/justification` ⇒ `204` y la marca vuelve a contar; después el `PUT` que la quita ⇒ `200`.
- **`CA-ACAD-182`** (`RN-ACAD-187`) — El resumen de 2A en un intervalo devuelve una fila por matrícula con los seis contadores exactos de un conjunto de datos fijo (pases diarios y de sesión separados) y los dos denominadores; con ámbito `clase`, P solo ve contadores de MAT; intervalo que cruza dos cursos ⇒ `422 range_outside_academic_year`.
- **`CA-ACAD-183`** (`RN-ACAD-188`) — `GET /attendance-marks` de una matrícula devuelve sus marcas paginadas en orden de fecha descendente con asignatura y franja; sin `enrollment` ⇒ `422`; matrícula fuera de ámbito ⇒ `404`.
- **`CA-ACAD-184`** (`RN-ACAD-190`, `-191`, `-193`) — Con umbral 3: la tercera `falta` sin justificar de una matrícula crea **una** alerta (`absence_count: 3`) en la misma transacción que el pase; la cuarta y la quinta, ninguna; la sexta, otra; un `retraso` no cuenta; justificar una y volver a tener tres no duplica la alerta de 3; con umbral `NULL`, ninguna; cambiar el umbral a 2 no crea ni borra alertas por sí solo. Si el pase falla, no queda alerta.
- **`CA-ACAD-185`** (`RN-ACAD-192`) — T ve y atiende la alerta de un alumno de 2A (`200`, `attended_by`, `attended_at`); atender otra vez ⇒ `200` sin cambios; P (`clase`) y un tutor legal no la ven (`404` y listado vacío); una alerta de 2B ⇒ `404` para T.
- **`CA-ACAD-186`** (`RN-ACAD-182`, `REQ-FAM-UNIT-002`; **listado y detalle**) — *Dado* un tutor legal con vínculo activo con un alumno de 2A y `asistencia.leer` (`unidad_familiar`): `GET /attendance-marks` de la matrícula de su hijo devuelve fecha, tipo, asignatura y `justified`, **sin** `taken_by` ni otros alumnos; la de otro alumno ⇒ `404`; `GET /attendance-rolls`, el resumen y las alertas ⇒ vacío o `404`. Con el vínculo **revocado** ⇒ `404` en la petición siguiente.
- **`CA-ACAD-187`** (ajustes) — `PUT /attendance-settings` con 5 ⇒ `200` y una entrada de auditoría; con 0 o 1000 ⇒ `422 threshold_invalid`; con `null` ⇒ alertas desactivadas; dos `PUT` simultáneos iniciales ⇒ una sola fila.
- **`CA-ACAD-188`** (§8.4, `ADR-048`) — El contrato del evento `UnjustifiedAbsenceThresholdReached` está documentado y **no hay código que lo emita** ni *listener* registrado; el contrato `AttendancePresence` está documentado y no construido.
- **`CA-ACAD-189`** — `CA-ACAD-161`, `-174` y `-175` para los *endpoints* 7 a 14.
- **`CA-ACAD-190`** (Vitest y Playwright contra la API real) — Justificar un intervalo desde la ficha del alumno, ver el resumen del grupo, fijar el umbral, provocar una alerta y atenderla; textos en los cuatro idiomas (`AR-12`).

### 13.5 Sin conexión (`1.13-c`; condicionados al ADR de §10.9)

- **`CA-ACAD-191`** (§10.3) — Tras abrir «Pasar lista» con red, el almacén local contiene la hoja del día cifrada (ningún nombre de alumno legible en IndexedDB) y solo la de hoy; pasadas 18 horas o al cambiar el día del centro, deja de estar disponible y se borra.
- **`CA-ACAD-192`** (`RMOB-COM-004`, §10.6) — Al cerrar sesión sin pendientes, el almacén queda vacío; con pendientes, se pide confirmación y, confirmada, queda vacío; otro usuario que inicia sesión en el mismo navegador no ve ni hoja ni cola del anterior.
- **`CA-ACAD-193`** (`RMOB-COM-001`, §10.4) — Con la red cortada, guardar un pase lo deja «Pendiente de enviar» y sobrevive a recargar la página; al volver la red se envía solo, el servidor tiene el pase con `recorded_at` del momento sin red, y el estado pasa a «Guardado». Un corte **después** de que el servidor lo aceptara y antes de recibir la respuesta no crea un segundo pase al reintentar (misma `Idempotency-Key`).
- **`CA-ACAD-194`** (§10.4 punto 4) — Con la sesión caducada al recuperar la red, la cola no se pierde, se pide iniciar sesión y, tras hacerlo el mismo usuario, se envía; si entra otro usuario, se aplica lo decidido en `OPEN-ACAD-52` y nunca se envía con su identidad.
- **`CA-ACAD-195`** (§10.5) — (a) Otro usuario marcó a un alumno **distinto** mientras tanto ⇒ fusión automática: el servidor acaba con las dos marcas y se muestra el aviso. (b) Marcó al **mismo** alumno con otro valor ⇒ pantalla de conflicto, nada se envía hasta elegir, y lo elegido es lo que queda. (c) La marca fue justificada ⇒ se conserva y se informa. (d) El curso se cerró ⇒ «Requiere revisión», no se reintenta, y descartar pide confirmación.
- **`CA-ACAD-196`** (§10.6) — El aviso de privacidad aparece la primera vez en cada dispositivo, en el idioma del usuario, y sin aceptarlo no se guarda nada; con el modo desactivado por el centro, la pantalla funciona solo en línea y no hay almacén local.
- **`CA-ACAD-197`** (`RMOB-COM-002`) — Con un elemento de formato no admitido por la API, la aplicación exige actualizar antes de enviar y la cola se conserva.
- **`CA-ACAD-198`** (Playwright contra la API real, red cortada con el propio Playwright) — Flujo completo: precarga, corte, dos pases, vuelta, envío.
- **`CA-ACAD-199`** (prueba manual, registrada en el informe del paso) — Lo mismo en un iPhone y en un Android reales, instalada y sin instalar; se anota lo que no funcione. **No se declara verde sin haberla hecho.**

---

## 14. Preguntas abiertas (no las resuelvo; cada una con recomendación)

**Decidida por el usuario (2026-11-12)**: `OPEN-ACAD-45`, según la recomendación: la asistencia vive en un **módulo nuevo `asistencia`** con `depends_on: ['curso','acad','alum']`, lo que evita el ciclo con `alum`. Siguen abiertas `OPEN-ACAD-46`, `-47` y `-52`, que bloquean, y el ADR de lista sin conexión (número `ADR-084`, asignado por la orquestadora).

**Decididas por el usuario (2026-11-12), según la recomendación**: `OPEN-ACAD-46` (el grano del pase admite ambos en una tabla: por sesión del horario o diario, excluyentes por grupo y día) y `OPEN-ACAD-52` (cola de salida propia en IndexedDB, control optimista por versión, fusión automática si los alumnos son distintos y pantalla de conflicto si coinciden; al cerrar sesión con pases pendientes se avisa y se borra todo). Esta última exige el ADR de lista sin conexión (`ADR-084`), que no está escrito. Sigue abierta `OPEN-ACAD-47` (base legal de nombres de menores en móviles personales, pendiente de validación jurídica).

### 14.1 Bloqueantes: sin decidirlas no se implementa

| ID | Pregunta | Opciones | **Recomendación** e impacto | Bloquea | Decide |
|----|----------|----------|-----------------------------|---------|--------|
| `OPEN-ACAD-45` | **¿En qué módulo vive la asistencia?** (H1: `acad` no puede consumir `alum` sin ciclo) | **A** · Módulo nuevo `asistencia`, `depends_on: ['curso','acad','alum']`. **B** · Dentro de `alum` (ya depende de `acad`). **C** · Dentro de `acad`, con una interfaz que `alum` implemente (inversión) | **A.** Sin ciclo y con dependencias declaradas; se contrata y se apaga por separado; no engorda `alum` ni `acad`. B mezcla en `alum` permisos y pantallas de otra naturaleza y ata la asistencia a su contratación. C deja una dependencia real sin declarar (`RMOD-006` no la vería) y FK de `acad` hacia tablas de `alum`. Impacto de A: un módulo más en el catálogo y en el backoffice; el ID del requisito sigue siendo `REQ-ACAD-003`; conviene anotar en `§5.4` que `Attendance` se implementa fuera de `acad` | `1.13-a` | Usuario |
| `OPEN-ACAD-46` | **Grano del pase de lista** (H2) | **A** · Solo por sesión del horario. **B** · Solo diario por grupo. **C** · Ambos en una tabla, excluyentes por grupo y día (`RN-ACAD-163`) | **C.** A obliga a componer el horario para poder pasar lista (y deja fuera Infantil y Primaria, que suelen pasar una vez); B impide el control por asignatura de Secundaria y deja sin uso el ámbito `clase`. C cuesta una regla de exclusión y dos contadores en los informes. Si el usuario prefiere simplicidad, B es la más barata y C la contiene | `1.13-a` (tablas) | Usuario (negocio) |
| `OPEN-ACAD-47` | **Base legal del registro de asistencia y permiso para guardar nombres de alumnos en el dispositivo del docente** | **A** · Obligación legal/interés público, sin consentimiento; modo sin conexión permitido con ajuste del centro para desactivarlo. **B** · Modo sin conexión solo en dispositivos del centro. **C** · Sin modo sin conexión | **A**, con **validación jurídica antes de datos reales** (no antes de implementar con datos de `REQ-SEED`). Impacto: texto en `PRIVACY.md` y aviso legal de §10.6 | Datos reales; `1.13-c` | Legal y usuario |
| `OPEN-ACAD-52` | **Cerrar sesión, o entrar otro usuario, con pases sin enviar** (H7) | **A** · Avisar y, si se confirma, borrar todo (`RMOB-COM-004` literal). **B** · Conservar la cola cifrada (sin nombres) hasta que vuelva el mismo usuario. **C** · Impedir cerrar sesión sin red | **A.** B deja datos de un usuario en un dispositivo donde ya no tiene sesión, contra el requisito; C atrapa al usuario. Impacto: un docente que cierre sesión sin cobertura pierde esos pases (avisado) | `1.13-c` | Usuario |
| ADR de §10.9 | Arquitectura del modo sin conexión | §10.2 | **A** (cola propia) | `1.13-c` | Usuario, con ADR de `architect` |

### 14.2 No bloqueantes: se puede implementar con la recomendación y cambiar después

| ID | Pregunta | Opciones | **Recomendación** e impacto | Decide |
|----|----------|----------|-----------------------------|--------|
| `OPEN-ACAD-48` | **Justificación** (H4): ¿motivo, texto, justificante adjunto, petición desde la familia? | **A** · Solo «justificada», quién y cuándo. **B** · Motivo de lista cerrada sin valores de salud. **C** · Texto libre y fichero | **A.** C y cualquier motivo médico convierten el dato en categoría especial (tabla separada cifrada, permiso propio, auditoría de lectura). La petición desde la familia, con `1.22` | Usuario; legal si no es A |
| `OPEN-ACAD-49` | **Umbral y destinatario de la alerta** (H3) | **A** · Un entero por centro, sobre faltas sin justificar acumuladas en el curso, alerta en cada múltiplo; visible para tutor de grupo y jefatura; aviso a tutores legales cuando exista `1.19`. **B** · Ventana móvil (N en 30 días). **C** · Umbral por etapa | **A.** Es lo mínimo que cumple «umbral configurable»; B y C son aditivas. Hasta `1.19` el punto 3 del requisito queda **parcial** | Usuario (negocio) |
| `OPEN-ACAD-50` | **«Informes por período»**: ¿la API recibe un período lectivo o fechas? | **A** · Fechas; la interfaz ofrece los períodos como atajo. **B** · Interfaz nueva de `acad` para resolver un período | **A.** Evita otra ampliación de `acad` sin consumidor. Impacto: quien no pueda leer los períodos lectivos elige fechas a mano | Técnico |
| `OPEN-ACAD-51` | **Siembra de roles** (§7.3) | **X** · La de §7.3. **Y** · Docente con `grupo` en vez de `clase` | **X.** Es la lectura de `§11.1` y de `ADR-063 §5.3`. Y es más cómoda (el docente ve todo el grupo) pero menos estricta | Usuario |
| `OPEN-ACAD-53` | **¿Se puede borrar una sesión del horario con pases?** | **A** · Sí; el pase conserva referencia y asignatura. **B** · Veto (`409`) | **A.** B impediría rehacer el horario a mitad de curso. Exige que `acad` no vete; nada que construir | Técnico |
| `OPEN-ACAD-54` | **¿Hasta cuándo se puede pasar o corregir lista de un día pasado?** | **A** · Mientras el curso sea escribible. **B** · Ventana configurable (p. ej. 7 días) para el ámbito `clase` | **A** ahora; B si el centro piloto lo pide (no hay requisito). Todo cambio queda auditado | Usuario (negocio) |
| `OPEN-ACAD-55` | **Subgrupos y cambios de grupo** (H5) | **A** · Lista del grupo entero; sin histórico de grupo. **B** · Pertenencia del alumnado a subgrupos e histórico (cambio en `alum`) | **A.** B es un cambio del modelo de `alum` sin requisito. Límite escrito en §4 | Usuario |
| `OPEN-ACAD-56` | **Autorización de un alta con ámbito restringido** (H8) | **A** · Insertar y comprobar por `ScopedQuery` en la misma transacción (`RN-ACAD-181`). **B** · Ampliar el contrato del núcleo con una operación «¿puede crear sobre este padre?» (ADR que enmiende `ADR-044`) | **A.** No toca el núcleo y reutiliza el resolutor. Si `architect` o `security-reviewer` prefieren B, es un ADR previo a `1.13-a` | Técnico (`architect`) |
| `OPEN-ACAD-57` | **Retención de la asistencia** | Plazo y forma de supresión | Sin recomendación de fondo: es de `REQ-PRIV` y de la norma educativa aplicable; **pendiente de validación jurídica** | Legal |
| `OPEN-ACAD-58` | **Dónde vive el umbral** | **A** · Tabla propia `attendance_settings`. **B** · Ajustes del centro en `Core`, si admiten escritura por módulos | **A** salvo que B ya exista sin ampliar `Core` | Técnico |

---

## 15. Riesgos y lo no comprobado

### 15.1 Riesgos

| Riesgo | Severidad | Mitigación |
|--------|-----------|------------|
| Nombres de menores en dispositivos personales, fuera del control del centro | **Alta** | Solo hoy, 18 horas, cifrado, borrado al cerrar sesión, ajuste para desactivar, aviso legal; `OPEN-ACAD-47`; ADR |
| Pases perdidos en iOS por el borrado de almacenamiento a los siete días o por cerrar sesión sin red | Media | Aviso de pendientes, instalación en pantalla de inicio, `CA-ACAD-199` en dispositivo real; sin verificar en versiones actuales |
| Primer resolutor `clase` y primera escritura con ámbito: un error abre o cierra de más | **Alta** | `CA-ACAD-171` a `-173` con accesos denegados en listado, detalle y escritura; `security-reviewer`; `AR-10` |
| El trabajo en `acad` y `alum` (cuatro interfaces) alarga T1 de `1.13-a` | Media | Costura de reserva (§12); son interfaces ya especificadas salvo `TimetableSessionDirectory` |
| Exclusión diario/sesión no garantizada por el motor | Media | Un solo servicio, serialización (§5.4), `CA-ACAD-170` con dos procesos, `db-reviewer` |
| «Presente» implícito: un pase de sesión de subgrupo da por presentes a alumnos que no eran de esa sesión | Baja | Solo afecta al contrato de presencia (§8.3), que usa la `falta` y la existencia de pase, no el «presente»; `OPEN-ACAD-55` |
| Punto 3 del requisito (alertas a tutores) sin envío hasta `1.19` | Media | Dicho en §1.2, §8.4 y en el plan; la alerta existe y se ve en la aplicación |
| La especificación envejece si `acad`/`alum` cambian antes de implementarse | Baja | Briefing de `explorer` antes de cada tramo (`CLAUDE.md §2`) |

### 15.2 Lo que no he podido comprobar (sin `Bash` ni búsqueda local; solo lecturas dirigidas y la búsqueda de código de GitHub sobre `b7995bc`)

- Que no existan identificadores `RN-ACAD`, `CA-ACAD` u `OPEN-ACAD` posteriores a `-157`, `-159` y `-44`.
- Que `groups` tenga el único `(tenant_id, academic_year_id, id)` (§5.1) y qué versión de PostgreSQL se usa.
- Qué devuelve exactamente `RequireIdempotencyKey` cuando falta la cabecera y cuánto conserva las claves (una cola de varios días necesita que el reintento siga siendo reconocible; si no, la clave natural lo cubre).
- Que `PersonDirectory` devuelva nombres visibles por lote y que `acad` exponga el nombre de una asignatura por id.
- El limitador de peticiones y la CSP vigentes (§11.4).
- Los hechos de navegador de §10.7 en versiones actuales.
- `docs/metricas-agentes.md` (estimaciones de §12 sin calibrar) y los borradores `ADR-079` (notificaciones) y `ADR-080`, que no he leído.
- No he leído `REQ-ACAD/{datos,api,permisos,operacion}.md` enteros: las convenciones las tomo de `funcional.md §18`, de `REQ-ALUM` y del código.

---

## 16. Cambios propuestos en ficheros compartidos (no los edito; los aplica la orquestadora)

| Fichero | Cambio propuesto |
|---------|------------------|
| `PLAN-IMPLEMENTACION.md` | Partir `1.13` en `1.13-a`, `1.13-b` y `1.13-c` (§12), marcarlas **críticas** y con especificación **propuesta, no aprobada**. Anotar: `1.13-c` exige el ADR de §10.9 aceptado; `REQ-ACAD-003` punto 3 queda parcial hasta `1.19`; la obligación heredada de `1.14b-d4` se cumple con el contrato de §8.3, que **construye `1.14b-d4`**. Si queda alguna cita de `ADR-048` como origen de la asignación docente, sustituirla por `ADR-063 §5.4` |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` (cabecera, `§5.4`, índice de ADR de `§18`) | Si se aprueba `OPEN-ACAD-45` = A: nota en `§5.4` de que `Attendance` se implementa en un módulo propio que depende de `ALUM` (H1), sin cambiar el ID. Alta del ADR de §10.9 en el índice cuando tenga número |
| `docs/modulos/REQ-ACAD/funcional.md` (no es de la lista de compartidos, pero es de otra entrega: **no lo toco**) | Cabecera: `-003` especificada en `asistencia.md`. `§18.7`: `classPairsFor` y `TimetableSessionDirectory` con primer consumidor `1.13-a`. Al implementar, párrafos puntero en los cinco ficheros (§0.1) |
| `docs/modulos/REQ-ALUM/funcional.md §7.2` (ídem) | `EnrollmentRoster` devuelve también id de persona; nueva operación persona de alumno → matrículas de un curso (`1.13-b`) |
| `docs/modulos/REQ-PRL/funcional.md` (ídem) | Referencia al contrato `AttendancePresence` de §8.3 para `1.14b-d4` |
| `ARCHITECTURE.md` | Módulo `asistencia` en el mapa de módulos y dependencias; en `§3.4`, primer ejemplo de resolutor `clase` y de escritura con ámbito; sección de PWA cuando exista el ADR |
| `PRIVACY.md` | Tratamiento «control de asistencia»: finalidad, base legal (**pendiente de validación jurídica**), categorías, destinatarios (familias), almacenamiento temporal en dispositivo, retención sin decidir |
| `SECURITY.md` | Datos en el dispositivo: qué, cuánto y límites del cifrado (§10.3) |
| `SYSADMIN.md`, `RUNBOOK.md` | Con `1.13-c`: despliegue y retirada de un *service worker* |
| `CHANGELOG.md` | Entrada de la ola: especificación de `1.13` propuesta |
| `memory.md` | `1.13` especificada y **sin aprobar**; cuatro preguntas bloqueantes y un ADR por numerar; H1 (ciclo `acad` ↔ `alum`) |
| `docs/manual-usuario/{docente,direccion,familia}.md` | Al implementar cada entrega |

---

**¿Se aprueba esta especificación?** No paso a implementación (ni lo hace nadie) hasta que el usuario decida las bloqueantes de §14.1 y apruebe expresamente las ampliaciones de `acad` y `alum` de §8.2 y la del mapa de `AR-10` de §7.2.
