# REQ-CALIF · Calificaciones y evaluación · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-CALIF` (sección 5.5 del documento de requisitos: `REQ-CALIF-001` calificaciones, `-002` boletines, `-003` historial, `-004` informes) |
| Prioridad | MUST |
| Fase | 1 · pasos **1.16** (calificaciones) y **1.17** (boletines en PDF) del plan, bloque E |
| Depende de | Documento (3.2.30): `ACAD`. Real: `CURSO` (toda tabla lleva `academic_year_id`), `ACAD` (períodos lectivos, escalas, asignaturas, asignación docente) y `ALUM` (la matrícula es el eje, `§16.3` regla 4). **Propuesta** (`OPEN-CALIF-01`): `depends_on: ['curso', 'acad', 'alum']` |
| Esencial | Propuesta: `essential: false` (un esencial no puede depender de `acad` ni de `alum`, `ADR-045`) |
| Estado | **PROPUESTO** (2026-11-12, agente B5 de la ola 1). Nada se implementa hasta que el usuario apruebe y resuelva las preguntas bloqueantes de §12 |
| Clasificación del paso: crítico | Todas las entregas (`1.16-a` a `-d`, `1.17-a`, `-b`). Ver §2 |
| Decisiones vinculantes de partida | `ADR-004`, `ADR-012`, `ADR-013`, `ADR-016`, `ADR-020`, `ADR-021`, `ADR-029`, `ADR-033`, `ADR-034`, `ADR-035`, `ADR-038`, `ADR-044` (enmendado por `ADR-063`), `ADR-045`, `ADR-048`, `ADR-053`, `ADR-056`, `ADR-057`/`ADR-058`, `ADR-059`, `ADR-062`, `ADR-063 §5.2`-`§5.4`, `ADR-069 §4.2`, `ADR-071`, `ADR-076`; contratos aprobados de `REQ-ACAD/funcional.md §4.8`, `§4.9`, `§7.2`, `§18.7` (`RN-ACAD-63`, `-77`, `-98`), `REQ-ALUM/funcional.md §7.2` y `REQ-CURSO/funcional.md §1.2` (`RN-CURSO-30`). **En propuesta, no vinculantes todavía**: `ADR-078` (motor de PDF), `ADR-079` (notificaciones), `ADR-082` (firma) |

> Lo que esta especificación añade sin que lo fije un requisito o un ADR se marca **[DERIVADA]** con su motivo. Donde falta información hay una pregunta abierta (§12) con opciones y recomendación; el texto aplica la **recomendación** citando la pregunta, y cambia si el usuario decide otra cosa.

---

## 0. Terminología, contradicciones, huecos y dependencias

### 0.1 Terminología (igual en los cinco ficheros)

| Término | Entidad / tabla | Qué es |
|---------|-----------------|--------|
| **Período de evaluación** | `EvaluationPeriod` / `evaluation_periods` | Una evaluación de una etapa en un curso académico: `parcial` (referencia un período lectivo de `acad`), `final` o `extraordinaria` (`OPEN-ACAD-17` = A + Y: «`CALIF` define sus períodos de evaluación referenciando los lectivos») |
| **Evaluación de grupo** | `GroupEvaluation` / `group_evaluations` | Un período de evaluación aplicado a un grupo. Lleva el **estado** (`abierta`, `cerrada`) y la **publicación** (`OPEN-CALIF-02`) |
| **Asignación de escala** | `GradingScaleAssignment` / `grading_scale_assignments` | Qué escala de `acad` usa una etapa por defecto y, si difiere, una asignatura (`OPEN-ACAD-18`: «lo decide `1.16`») |
| **Calificación** | `Grade` / `grades` | La nota de una matrícula en una asignatura y una evaluación de grupo, con comentario cualitativo opcional |
| **Provisional / definitiva** | — (derivado) | Una calificación es **provisional** mientras su evaluación de grupo está `abierta` y **definitiva** cuando está `cerrada` [DERIVADA: una sola fuente de verdad, sin marca por nota] |
| **Elemento de calificación** | `GradingComponent` / `grading_components` | Criterio ponderado de una clase en una evaluación (examen, trabajo…), con su peso (`REQ-CALIF-001`: «medias ponderadas según criterios configurables») |
| **Nota de elemento** | `ComponentGrade` / `component_grades` | La nota de una matrícula en un elemento |
| **Competencia** | `Competency` (entrega `1.16-c`) | Competencia LOMLOE con descriptores (`OPEN-ACAD-19`; `OPEN-CALIF-12`) |
| **Boletín** | `ReportCard` / `report_cards` | Documento PDF **inmutable** con las calificaciones de una matrícula en una evaluación de grupo, en un idioma |
| **Emisión de boletines** | `ReportCardRun` / `report_card_runs` | Lote de generación en cola, con su progreso |
| **Historial académico** | — (composición de lectura) | Calificaciones definitivas de un alumno a lo largo de sus matrículas. `AcademicRecord` de `§5.5` **no** es una tabla en `1.16` [DERIVADA: precedente «expediente» de `REQ-ALUM`] |

Heredados sin cambio: curso académico, etapa, curso de etapa, grupo, asignatura, período lectivo, escala (`REQ-ACAD §0.1`); matrícula (`REQ-ALUM §0.1`); «clase» como **ámbito** = pares (grupo, asignatura) que el usuario imparte (`ADR-063 §5.3`, `RN-ACAD-98`). **«Tutor» a secas no se usa**: «tutor de grupo» es el docente (`group_tutors`), «tutor legal» es la familia.

Nombres [DERIVADA]: módulo `calif`, prefijo de errores y traducciones `calif.`. Recursos de permiso en `permisos.md §1`.

### 0.2 Contradicciones y huecos

Ninguno es una contradicción irresoluble entre requisitos; cada uno queda con su pregunta. Se señalan antes del alcance porque lo condicionan (`CLAUDE.md §0`).

| # | Hallazgo | Fuentes | Cómo queda |
|---|----------|---------|------------|
| C1 | **Dependencias declaradas incompletas.** `§5.5` dice «Depende de: ACAD», pero `§16.2` fija `ENROLLMENT ||--o{ GRADE` y `§16.3` regla 4 hace de la matrícula el eje (`ALUM`); toda tabla lleva curso académico (`CURSO`) | `§5.5`, `§16.2`, `§16.3` | `OPEN-CALIF-01` (precedente `OPEN-ALUM-28`) |
| C2 | **El requisito nombra roles, no permisos.** «Hasta que el administrador o jefe de estudios las publique» | `REQ-CALIF-001`, `RN-PERM-46`, `AR-08` | Se traduce a la acción `publicar` de `RPERM-003`; ninguna regla compara roles |
| C3 | **Reparto de entidades con `ACAD`.** `§5.5` lista `EvaluationPeriod` y `Competency` | `REQ-ACAD §0.2` C1 | Resuelto por `OPEN-ACAD-17`/`-18`/`-19` (aprobadas): períodos lectivos y escalas son de `acad`; este módulo define períodos de evaluación, asignación de escalas y competencias |
| C4 | **`RPERM-008`** («solo durante el período de evaluación») diferido a `1.16` por `ADR-044 §4.5`, sin mecanismo | `§11.2` nota | `OPEN-CALIF-04`: regla de negocio sobre el estado de la evaluación de grupo, sin motor genérico de permisos condicionales |
| C5 | **Raíces es el registro oficial** de la evaluación final en etapas regladas (`ADR-016`). Lo que se califica aquí es el registro **interno** del centro; ningún documento de este módulo es oficial | `ADR-016`, `ADR-020` | Los boletines lo dicen en su plantilla solo si el usuario lo pide (`OPEN-CALIF-23`); no se generan documentos oficiales |
| C6 | **«Actas» no está en `REQ-CALIF`.** El acta de evaluación final firmada es de `REQ-CURSO-003` y el paquete de cierre de `REQ-CURSO-005`, ambos en `1.17b` | `§5.5`, `§5.28`, `PLAN 1.17b` | Este módulo entrega el **dato** del acta (evaluación de grupo cerrada, con quién y cuándo, §4.5) y una interfaz de lectura para `1.17b`; el **documento** acta no se especifica aquí (`OPEN-CALIF-15`) |
| C7 | **`REQ-CALIF-003` y `-004` no caben enteros en `1.16`/`1.17`.** Alertas de riesgo sin umbrales definidos y sin motor de avisos (`1.19`); «plantillas configurables» por el centro es entrada no confiable para el motor de PDF (`ADR-078 §6.1`, `OPEN-PDF-07`) | `§5.5`, `PLAN 1.16`/`1.17` | Entra el historial como lectura; alertas, comparativas e informes personalizables se difieren (`OPEN-CALIF-13`, `-14`). Nada está marcado `FUTURO`: diferir no es descartar |
| C8 | **«Firma digital del boletín por el tutor»** y el criterio de `§5.5` («incluye… la firma digital del tutor») dependen de `REQ-DOC-002` (fase 2) y de `ADR-082` (propuesta: nivel N1, «validación interna previa a publicar»). `ADR-078 §8` admite boletines sin firma electrónica y prohíbe presentar una imagen como firma | `REQ-CALIF-002`, `ADR-012`, `ADR-082 §4.1`, `ADR-078 §8` | `1.17-a` entrega boletines **sin** firma; la validación del tutor de grupo es `1.17-b`, opcional y condicionada (`OPEN-CALIF-26`). El criterio de `§5.5` no se cumple entero hasta entonces |
| C9 | **«Envío automático por email»** frente a `ADR-079` (propuesta): el boletín se entrega como **enlace autenticado**, nunca adjunto ni con notas en el cuerpo | `REQ-CALIF-002`, `ADR-079` | El correo es un aviso con enlace al portal; se cumple la intención (avisar a los tutores legales) sin sacar datos de menores por correo (`OPEN-CALIF-25`) |
| C10 | **Fuente del catálogo de competencias sin decidir** y sin decir quién las evalúa ni con qué escala | `OPEN-ACAD-19`, `REQ-CALIF-001`, `-004` | Entrega propia `1.16-c`, bloqueada por `OPEN-CALIF-12`; aquí solo su perfil |

### 0.3 Dependencias no implementadas (se declaran antes de seguir)

| Dependencia | Estado (2026-11-12) | Efecto |
|-------------|---------------------|--------|
| Módulo `calif` | No existe (`apps/api/app/Modules/` no lo tiene; `docs/modulos/` tampoco) | Se crea en `1.16-a` con `make:module` |
| `Acad\Domain`: `GradingScaleDirectory`, `AcademicPeriodDirectory`, `SubjectDirectory`, `TeachingMembership::classPairsFor()`, registros de vetos de períodos y escalas | «Escritas, no construidas» (`REQ-ACAD §7.2`, `§18.7`) | Se construyen en `1.16-a`, con `calif` como primer consumidor (`ADR-048`) |
| `Alum\Domain`: `EnrollmentRoster`, registro de vetos de anulación de matrícula | Especificados, se construyen con su primer consumidor (`1.13` o este paso, el que llegue antes) | `1.16-a` los construye si `1.13` no lo ha hecho |
| Servicio `pdf` y contrato `PdfRenderer` (`ADR-078`) | No existen; ADR en `PROPUESTA` (el usuario aceptó WeasyPrint: `OPEN-PDF-01`, `-02`) | **Bloquea `1.17-a`** (`OPEN-CALIF-20`). Esta especificación **no decide el motor**: solo consume `PdfRenderer` |
| Motor de notificaciones en `Core` (`ADR-079`) y `REQ-COM` (`1.19`) | No existen; ADR en `PROPUESTA` | Bloquea el aviso a familias de `1.17-b` |
| Firma N1 y reautenticación (`ADR-082`, `REQ-DOC`) | No existen; ADR en `PROPUESTA` | Bloquea la validación del tutor de grupo de `1.17-b` |
| `REQ-SEED` (`1.15b`) | Sin implementar | La medición de volumen (`ADR-078 §5.4`) y el E2E usan sus datos; `REQ-SEED-004` pide «calificaciones de una evaluación completa», que `1.15b` amplía cuando exista este módulo |

---

## 1. Alcance y entregas

### 1.1 Entregas [DERIVADA: `CLAUDE.md §2.7`, ≤3 tramos de `implementer` cada una]

| Entrega | Contenido | Tramos previstos | Bloqueada por |
|---------|-----------|------------------|---------------|
| **`1.16-a`** | Módulo `calif`; interfaces y vetos en `acad` y `alum`; períodos de evaluación; asignación de escalas; evaluaciones de grupo (apertura); calificaciones con comentario; resolutores `clase`, `grupo` y `unidad_familiar`; auditoría de lectura. Solo API | T1 `acad`/`alum` (interfaces, vetos) · T2 migraciones, permisos y siembra · T3 servicios y *endpoints* | `OPEN-CALIF-01` a `-06` |
| **`1.16-b`** | Elementos ponderados y media calculada; cierre, reapertura y publicación con su registro; visibilidad para familias; validación de cierre del curso (`RN-CURSO-30`); historial académico (lectura) | T1 datos y elementos · T2 transiciones, publicación y validación de cierre · T3 historial y concurrencia | `OPEN-CALIF-07` a `-09` |
| **`1.16-c`** | Competencias LOMLOE con descriptores | Por dimensionar tras la decisión | `OPEN-CALIF-12` (necesita detalle propio antes de implementar) |
| **`1.16-d`** | Pantallas de personal (§10) y E2E contra la API real | T1 configuración y evaluaciones · T2 rejilla de calificación · T3 E2E | Detalle de interfaz (§10.2) |
| **`1.17-a`** | Ajustes del boletín, emisión en cola, plantilla en cuatro idiomas, almacén, descarga auditada, historial de boletines. Sin firma ni aviso | T1 medición del motor con la plantilla (`ADR-078 §5.4`) y datos · T2 trabajos, servicios y *endpoints* · T3 conformidad e idiomas | `ADR-078` aceptada **e implementada**; `OPEN-CALIF-20` a `-24` |
| **`1.17-b`** | Validación del tutor de grupo (N1), aviso a tutores legales con enlace autenticado, pantallas y E2E | T1 validación · T2 aviso · T3 pantallas y E2E | `ADR-079` y `ADR-082` aceptadas e implementadas; `OPEN-CALIF-25`, `-26` |

`1.16-a` y `1.16-b` no dependen de ninguna pieza sin decidir fuera de este módulo. El orden `1.16-d` antes o después de `1.17-a` lo decide la sesión.

### 1.2 Qué NO entra, explícitamente

| Fuera de alcance | Requisito | Por qué | Dónde se retoma |
|------------------|-----------|---------|-----------------|
| Acta de evaluación como documento, firmada | `REQ-CURSO-003`, `-005` | C6 | `1.17b` (`OPEN-CALIF-15`) |
| Decisión de promoción, propuesta automática por suspensos | `REQ-CURSO-003` | Paso propio | `1.17b`; consume `GradingScaleDirectory` y la interfaz de §7.2 |
| Alertas de riesgo académico, comparativas entre períodos, gráficos de progreso | `REQ-CALIF-003` | C7 | `OPEN-CALIF-13` |
| Informes de evaluación personalizables y plantillas configurables | `REQ-CALIF-004` | C7 | `OPEN-CALIF-14` |
| Escala adaptada por alumno | `REQ-NEAE-003` (fase 2) | Categoría especial; necesita `REQ-NEAE` | Fase 2 |
| Evaluación cualitativa e informes de desarrollo de 0-3 | `REQ-INF` | Paso propio | `1.18` (reutiliza escalas cualitativas y este módulo si su especificación lo decide) |
| Exámenes, bancos de preguntas, estadística de resultados | `REQ-ACAD-005` | Otro requisito | Sin paso asignado |
| Exportación a Raíces | `ADR-016`, `REQ-SEC` | Fase 2 | `REQ-SEC` |
| Exportación CSV de calificaciones | Ningún requisito | — | No se declara `exportar` |
| Copia al curso siguiente («criterios de evaluación», `REQ-CURSO-002`) | `REQ-CURSO-002` | Participante del *rollover* | `OPEN-CALIF-16` |
| Vistas en portales de familias, alumnado y docente | `REQ-FAM-PORTAL`, `REQ-EST`, `REQ-PROF` | Pasos propios | `1.21`-`1.23`. La **API** ya admite `unidad_familiar` (`INV-006`) |
| Sello electrónico del centro, firma N2/N3 | `ADR-082` | No se construye ahora | `REQ-DOC` |
| Importación de calificaciones | `REQ-ONB` | Importador genérico | `1.24` |

---

## 2. Clasificación del paso: **crítico** (`ADR-069 §4.2`)

**Clasificación del paso: crítico**, en las seis entregas. Basta cualquiera de estas:

- Migraciones que **crean tablas** con RLS (todas las de `datos.md`).
- *Endpoints*, permisos y **resolutores de ámbito nuevos** (`clase` por primera vez en el producto), y entradas nuevas en el mapa cerrado de `AR-10`.
- *Endpoints* que **leen y escriben datos personales de menores** (calificaciones, comentarios, boletines).
- **Ficheros** (`1.17`: PDF en el almacén, URL firmada).
- Ampliación de interfaces que sostienen autorización (`TeachingMembership::classPairsFor`).
- `1.16-d` es solo interfaz, pero expone esos datos y su diff puede tocar rutas y permisos de `shell.ts`: en la duda, crítico.

Revisión: Codex primero; `security-reviewer` con su columna; `db-reviewer` en toda entrega con migraciones; `doc-precheck` y `doc-reviewer`. La sesión comprueba la clasificación contra el diff.

---

## 3. Actores y roles implicados

| Actor (`§11.1`) | Qué hace |
|-----------------|----------|
| Administrador de Centro | Todo, incluida la configuración |
| Dirección / Jefatura de Estudios | Configura evaluaciones, abre, cierra, reabre y **publica**; emite boletines; lee todo |
| Docente | Califica **sus clases** (ámbito `clase`); define sus elementos ponderados |
| Tutor de grupo | Lee las calificaciones de **su grupo** (ámbito `grupo`), cierra su evaluación de grupo, emite y valida sus boletines |
| Secretaría | Lee calificaciones y boletines (`§11.1`: «documentación») |
| Tutor legal / Familia | Por API, calificaciones y boletines **publicados** de los alumnos con vínculo activo (ámbito `unidad_familiar`); pantallas en `1.22` |
| Estudiante | Nada en `1.16`/`1.17` (`OPEN-CALIF-05`) |
| Orientador, resto | Nada sembrado (`OPEN-CALIF-05`) |

Ninguna regla compara roles (`AR-08`); los roles personalizados funcionan igual.

---

## 4. Flujos principales

### 4.1 Configurar la evaluación de un curso académico (`1.16-a`)

1. Con `configuracion_evaluacion.crear`, por etapa: alta de períodos de evaluación (`parcial` ligado a un período lectivo; `final`; `extraordinaria`), con nombre traducible (`ADR-062`).
2. Asignación de escala por etapa (obligatoria para poder calificar) y, donde difiera, por asignatura.
3. Lectura siempre con `AcademicYearReadAccess`; escritura rechazada por el disparador en cursos cerrados.

### 4.2 Abrir la evaluación de los grupos (`1.16-a`)

Con `evaluacion.crear`: `POST /evaluation-periods/{id}/group-evaluations` para todos los grupos vivos de la etapa o una lista. Crea una evaluación de grupo `abierta` por grupo que no la tenga (idempotente).

### 4.3 Calificar (`1.16-a`)

1. El docente abre una evaluación de grupo y una de sus asignaturas. El servidor devuelve las matrículas activas del grupo (`EnrollmentRoster`) con su calificación, si existe, y la escala resuelta.
2. Envía el conjunto de calificaciones de la clase (`PUT`, idempotente). Cada una: valor numérico o valor cualitativo según la escala, y comentario opcional.
3. El servidor comprueba permiso y ámbito `clase` (o `todos`), evaluación `abierta`, matrícula activa en el grupo, asignatura cursada por el grupo, valor válido en la escala. Escribe por instancia (`AR-15`), audita.

### 4.4 Elementos ponderados y media (`1.16-b`)

1. El docente define los elementos de su clase en esa evaluación con pesos que suman 100.
2. Introduce las notas de elemento. El servidor calcula la media ponderada por matrícula y la ofrece como **propuesta** (`calculated_value`); la calificación es la que el docente guarda (`OPEN-CALIF-07`).

### 4.5 Cerrar, reabrir y publicar (`1.16-b`)

1. **Cierre** (`evaluacion.aprobar`): la evaluación de grupo pasa a `cerrada`; sus calificaciones son definitivas y no admiten escritura. Si faltan calificaciones, exige confirmación explícita (`OPEN-CALIF-09`). Queda registrado quién y cuándo: es el dato del acta (C6).
2. **Publicación** (`evaluacion.publicar`): solo de una evaluación `cerrada`. Desde ese instante las familias la ven. También en bloque para todo un período.
3. **Reapertura** (`evaluacion.aprobar`, motivo obligatorio): vuelve a `abierta`. Si estaba publicada, **se retira la publicación** (`OPEN-CALIF-08`). Los boletines ya emitidos no se tocan; al reemitir quedan sustituidos (§4.7).
4. Cada transición deja una fila en un registro de solo anexar (`datos.md §1.7`).

### 4.6 Historial académico (`1.16-b`)

`GET /students/{public_id}/academic-record`: calificaciones definitivas del alumno agrupadas por curso académico, matrícula y evaluación. Para un sujeto con `unidad_familiar`, solo evaluaciones publicadas. Los cursos `cerrado`/`archivado` solo entran con `curso_historico.leer` (`OPEN-CALIF-18`).

### 4.7 Emitir boletines (`1.17-a`)

1. Con `boletin.crear`, sobre una evaluación de grupo `cerrada` (o todas las cerradas de un período): `POST /report-card-runs`. La petición **solo** valida, crea la emisión y los boletines en `pendiente`, encola y responde `202` (`INV-012`, `ADR-078 §5.1`).
2. Un trabajo **por boletín** (`ADR-078 §5.2`): fija el centro en `TenantContext` y el idioma del boletín, compone el HTML ya traducido y escapado, llama a `PdfRenderer` con el perfil `archival` (`OPEN-CALIF-24`), guarda el objeto en el almacén privado y después la fila con su huella.
3. Idiomas: un boletín por matrícula y por cada **idioma distinto** de sus destinatarios (tutores legales con vínculo activo), más el idioma por defecto del centro (`OPEN-CALIF-21`).
4. Un boletín emitido no se regenera (`ADR-078 §7.2`). Reemitir tras una reapertura crea boletines nuevos y marca los anteriores `sustituido`.
5. Descarga: `GET /report-cards/{id}/download` comprueba permiso, ámbito y, para familias, publicación; registra la lectura y devuelve una URL firmada de caducidad corta (`ADR-013`).

### 4.8 Validación del tutor de grupo y aviso a familias (`1.17-b`, condicionado)

1. Si el centro lo activa, el tutor de grupo valida cada boletín (o el lote de su grupo) con `boletin.firmar`: evidencia N1 de `ADR-082` (`OPEN-CALIF-26`). Con la opción activa no se publica una evaluación con boletines sin validar.
2. Al publicar, el motor de `ADR-079` avisa a cada tutor legal con vínculo activo, en su idioma, con un **enlace autenticado** al boletín. El mensaje no lleva el PDF ni ninguna calificación (`OPEN-CALIF-25`).

---

## 5. Reglas de negocio

### 5.1 Comunes

| ID | Regla |
|----|-------|
| `RN-CALIF-01` | Toda tabla del módulo salvo `report_card_settings` lleva `academic_year_id` y el disparador de `ADR-057`: escritura en curso `cerrado`/`archivado` ⇒ `409 urn:pge:error:academic-year-closed`. Toda lectura (listado **y** detalle) invoca `AcademicYearReadAccess` ⇒ `404` sin `curso_historico.leer` (`RN-CURSO-25`, `-33`) |
| `RN-CALIF-02` | El curso académico de una fila es el de su padre y es inmutable; ningún alta de hijo admite `academic_year` (`422 calif.validation.academic_year_not_allowed`); padres inmutables (`422 calif.validation.parent_immutable`). Precedente `RN-ACAD-05`, `-06` |
| `RN-CALIF-03` | Padre del **cuerpo** inexistente, borrado, de otro centro o de un curso ilegible ⇒ `422 calif.validation.parent_not_found`, indistinguible; recurso de la **ruta** en esos casos ⇒ `404` (precedente `REQ-ACAD §16`, S-3) |
| `RN-CALIF-04` | `calif` no importa modelos de otros módulos ni consulta sus tablas: lee por `Curso\Domain`, `Acad\Domain`, `Alum\Domain`, `FamUnit\Domain` y `Core\Domain` (`INV-007`, `AR-01`). Las FK en base de datos a `enrollments`, `groups`, `subjects`, `academic_periods`, `grading_scales` y `grading_scale_values` sí están permitidas |
| `RN-CALIF-05` | Fuera de ámbito ⇒ la misma respuesta que inexistente (`404`) en detalle, escritura, descarga e historial; en listados, solo lo propio (`ADR-044 §4.2`). Sin caché de pertenencia entre peticiones (`ADR-044 §4.7`) |
| `RN-CALIF-06` | Decimales en la API como **cadena** con las cifras de la escala; la entrada admite cadena o número JSON (`RN-ACAD-75`, `OPEN-ACAD-26` = A) |
| `RN-CALIF-07` | **Auditoría de escritura** (`INV-003`): todo modelo es `Auditable`; escrituras por instancia (`AR-15`). El valor de una calificación se registra; los textos libres (comentario, motivo de reapertura) **no** se registran en `changes`, solo el atributo (`ADR-035`; precedente `RN-ALUM-45`; `OPEN-CALIF-11`) |
| `RN-CALIF-08` | **Auditoría de lectura** [DERIVADA: son datos personales de menores; precedente `RN-ALUM-52`]: toda respuesta que devuelve calificaciones, historial o la URL de un boletín registra **una** fila `read` por consulta con `AuditRecorder::recordRead` (recurso, filtros y referencia del alumno o del grupo; nunca valores). `OPEN-CALIF-10` |

### 5.2 Períodos de evaluación y asignación de escalas

| ID | Regla |
|----|-------|
| `RN-CALIF-10` | Un período de evaluación pertenece a una etapa (y a su curso académico). `kind` ∈ {`parcial`, `final`, `extraordinaria`} (`422 calif.validation.kind_invalid`), inmutable. `parcial` exige `academic_period` vivo de la **misma etapa**; `final` y `extraordinaria` lo prohíben (`422 calif.validation.academic_period_required` / `not_applicable`) |
| `RN-CALIF-11` | Como mucho un período de evaluación vivo por período lectivo, y como mucho un `final` y un `extraordinaria` vivos por etapa (`422 calif.validation.evaluation_period_exists`; índices únicos parciales) [DERIVADA: evita dos «primera evaluación» de la misma etapa] |
| `RN-CALIF-12` | Nombre traducible (`name_translations`, reglas de `RN-ACAD-03`); `position` como `RN-ACAD-04`. Sin fechas propias: las de un `parcial` son las de su período lectivo [DERIVADA: no inventar] |
| `RN-CALIF-13` | No se borra un período de evaluación con evaluaciones de grupo vivas ⇒ `409 calif.conflict.has_dependents` |
| `RN-CALIF-14` | `calif` registra en `acad` (`RN-ACAD-63`) el veto de **borrado** de un período lectivo referenciado por un período de evaluación vivo, y el de cambio de `period_type`. El cambio de fechas se admite [DERIVADA: ninguna regla de este módulo depende de ellas] |
| `RN-CALIF-15` | **Asignación de escala** (`OPEN-CALIF-03`): una fila por etapa (por defecto) y, opcionalmente, por asignatura de un curso de esa etapa. La escala es del **mismo curso académico** (FK compuesta). Resolución: la de la asignatura; si no hay, la de la etapa; si no hay, no se puede calificar (`409 calif.conflict.scale_not_assigned`). Única viva por (etapa, asignatura — `NULL` cuenta como valor) |
| `RN-CALIF-16` | No se cambia ni se borra una asignación con calificaciones vivas que la usan ⇒ `409 calif.conflict.scale_in_use`. Cada calificación guarda la escala con la que se puso |
| `RN-CALIF-17` | `calif` registra en `acad` (`RN-ACAD-77`) el veto sobre una escala **en uso** (asignada o con calificaciones): no se borra ni cambian `min_value`, `max_value`, `decimals`, `passing_min_value`, ni se borra un valor o cambia su `is_passing` o sus límites de tramo. Sí cambian código, nombre y etiquetas [DERIVADA: así «aprobado» es estable y se deriva, sin copiarlo en cada nota] |

### 5.3 Evaluaciones de grupo

| ID | Regla |
|----|-------|
| `RN-CALIF-20` | Una evaluación de grupo es (período de evaluación, grupo), única entre las vivas; el grupo es de un curso de la etapa del período (`422 calif.validation.group_not_in_level`). Nace `abierta` |
| `RN-CALIF-21` | Abrir es idempotente: los grupos que ya la tienen se devuelven sin cambios. Sin asignación de escala de la etapa ⇒ `409 scale_not_assigned` |
| `RN-CALIF-22` | **`RPERM-008`** (`OPEN-CALIF-04`): solo se escriben calificaciones, elementos y notas de elemento en una evaluación de grupo `abierta`; en `cerrada` ⇒ `409 calif.conflict.evaluation_closed`. Sin evaluación de grupo no hay dónde calificar |
| `RN-CALIF-23` | **Cierre**: `abierta → cerrada`, con `FOR UPDATE` de la fila. Si alguna matrícula activa del grupo carece de calificación en alguna asignatura que el grupo cursa, `409 calif.conflict.missing_grades` con `params.missing` (recuento, sin identificar alumnos) salvo `confirm_missing: true` (`OPEN-CALIF-09`). Cerrar una ya cerrada ⇒ `409 calif.conflict.already_closed` |
| `RN-CALIF-24` | **Publicación**: solo `cerrada` (`409 calif.conflict.not_closed`); idempotente (publicar una publicada devuelve `200` sin escribir). En bloque: publica todas las `cerrada` sin publicar del período y devuelve cuántas quedaron `abierta` |
| `RN-CALIF-25` | **Reapertura**: `cerrada → abierta`, motivo obligatorio (1-500, texto plano). Retira la publicación si la había (`OPEN-CALIF-08`). Solo con el curso `activo` o `planificacion` (lo impone el disparador) |
| `RN-CALIF-26` | **Visibilidad diferida** (`REQ-CALIF-001`; regla de negocio **además** del permiso): un sujeto cuyo acceso a una calificación, historial o boletín proviene **solo** del ámbito `unidad_familiar` lo ve únicamente si su evaluación de grupo está `cerrada` y publicada. Lo no publicado responde como inexistente |
| `RN-CALIF-27` | Rectificar una nota tras el cierre del **curso académico** exige reabrir el curso (`ADR-059`), después la evaluación de grupo. No hay otra vía (`OPEN-CALIF-19`, resuelve `OPEN-CURSO-19`) |
| `RN-CALIF-28` | `calif` registra en `curso` (`RN-CURSO-30`) la validación de cierre del curso: falla si alguna evaluación de grupo viva está `abierta` o `cerrada` sin publicar (`REQ-CURSO-005`: «no se puede cerrar con calificaciones sin publicar»). Entrada sin identificadores ni nombres: `code` `calif.conflict.unpublished_evaluations`, `params` = `{open, unpublished}` |
| `RN-CALIF-29` | No se borra un grupo con evaluaciones de grupo vivas, ni una asignatura (ni se quita de un grupo) con calificaciones vivas: `calif` lo registra en los vetos de `acad` (`RN-ACAD-21`, ampliado, §7.3). No se anula una matrícula con calificaciones vivas: veto en `alum` (`REQ-ALUM §7.2`) |

### 5.4 Calificaciones

| ID | Regla |
|----|-------|
| `RN-CALIF-30` | Una calificación es (evaluación de grupo, matrícula, asignatura), única entre las vivas. La asignatura debe cursarla el grupo (`422 calif.validation.subject_not_in_group`); la matrícula debe estar `activa` en ese grupo al escribir (`422 calif.validation.enrollment_not_in_group`) |
| `RN-CALIF-31` | Valor según la escala resuelta (`GradingScaleDirectory`): `numerica`/`mixta` ⇒ `value` dentro de `[min, max]` y con no más decimales que la escala (`422 calif.validation.value_out_of_range` / `decimal_precision_exceeded`); `cualitativa` ⇒ `scale_value` vivo de esa escala (`422 calif.validation.value_not_in_scale`). El campo del otro tipo ⇒ `422 calif.validation.not_applicable_to_scale_type` |
| `RN-CALIF-32` | `comment` opcional, texto plano 1-2000, sin HTML (`REQ-CALIF-001`: «comentarios cualitativos»). Se admite comentario sin valor [DERIVADA]. Contenido del docente, **no** traducible |
| `RN-CALIF-33` | Escritura por conjunto: `PUT` de una clase con la lista de matrículas; cada elemento crea, actualiza o, con valor y comentario `null`, borra (lógico) la calificación. Todo o nada en una transacción; sin cambios no escribe ni audita (precedente `RN-ACAD-32`). Tope 100 elementos (`422 calif.validation.too_many_items`) |
| `RN-CALIF-34` | Ámbito de escritura: `clase` ⇒ el par (grupo, asignatura) está en `classPairsFor(u, curso)`; `todos` ⇒ cualquiera. Un docente **nunca** califica una clase que no imparte, ni siquiera de un grupo del que es tutor |
| `RN-CALIF-35` | Ámbito de lectura: `clase` ⇒ calificaciones de sus pares; `grupo` ⇒ todas las asignaturas de los grupos de `groupIdsFor(u, curso)`; `unidad_familiar` ⇒ las de matrículas de alumnos alcanzados por vínculo **activo** (`FamilyMembership`) y solo publicadas (`RN-CALIF-26`). Unión entre ámbitos de roles distintos; `deny` gana (`ADR-044`) |
| `RN-CALIF-36` | `is_passing` se **deriva** al leer con `GradingScaleDirectory` («¿es aprobado en esta escala?»); no se guarda (`RN-CALIF-17` lo hace estable) |
| `RN-CALIF-37` | La matrícula que pasa a `baja` o cambia de grupo conserva sus calificaciones en la evaluación de grupo de origen; no se copian al grupo nuevo (`OPEN-CALIF-17`) |

### 5.5 Elementos ponderados (`1.16-b`, `OPEN-CALIF-07`)

| ID | Regla |
|----|-------|
| `RN-CALIF-40` | Solo en clases con escala `numerica` o `mixta` (`422 calif.validation.components_not_applicable`). Elementos de (evaluación de grupo, asignatura): `name` texto plano 1-120 y `weight` entero 1-100. Se escriben como conjunto completo sincronizado por `public_id` (reglas de `RN-ACAD-88`); tope 30 |
| `RN-CALIF-41` | La suma de pesos de los elementos vivos de una clase debe ser **100** (`422 calif.validation.weights_must_sum_100`) |
| `RN-CALIF-42` | Nota de elemento: mismo rango y decimales que la escala de la clase (`RN-CALIF-31`) |
| `RN-CALIF-43` | `calculated_value` = Σ(nota × peso) / 100, redondeado al número de decimales de la escala, mitad hacia arriba, con aritmética decimal exacta (nunca coma flotante). `null` si falta alguna nota de elemento. Se calcula al leer; no se guarda |
| `RN-CALIF-44` | La media es una **propuesta**: la calificación es la que el docente guarda con `RN-CALIF-33`. La representación incluye ambas |
| `RN-CALIF-45` | No se borra un elemento con notas vivas sin enviar el conjunto completo que lo excluye; el borrado arrastra sus notas (lógico, por instancia), como `RN-ACAD-76` |

### 5.6 Boletines (`1.17`)

| ID | Regla |
|----|-------|
| `RN-CALIF-50` | Solo se emiten boletines de evaluaciones de grupo `cerrada` (`409 calif.conflict.not_closed`). Una emisión `pendiente` o `en_curso` por evaluación de grupo como mucho (`409 calif.conflict.run_in_progress`) |
| `RN-CALIF-51` | La petición HTTP no genera ningún PDF (`INV-012`). Un trabajo por boletín, en la cola `calif-pdf`; la carga del trabajo son identificadores, nunca HTML ni datos del alumno (`ADR-078 §5.2`, `CLAUDE.md §9`) |
| `RN-CALIF-52` | Idioma del boletín ∈ idiomas activos del centro; conjunto por matrícula según `OPEN-CALIF-21`. El trabajo fija el idioma explícitamente; todos los literales salen del sistema de traducción (`INV-009`); el contenido del centro traducible se resuelve con su cadena de reserva (`ADR-062`); el comentario del docente va tal cual |
| `RN-CALIF-53` | Contenido (`REQ-CALIF-002`): logotipo y datos del centro (de `Core`), datos del alumno (nombre, curso de etapa, grupo), evaluación, asignaturas con calificación, etiqueta de tramo y comentario, tutor o tutores de grupo por nombre, pie configurable. Nada más: sin fotografía, sin documento de identidad, sin datos de salud |
| `RN-CALIF-54` | Plantilla conforme a `ADR-078 §3.3`: HTML y CSS sin JavaScript, ningún recurso externo, estructura semántica, `<html lang>`. Todo dato escapado; el pie del centro es **texto plano** traducible (`ADR-070`, `ADR-078 §6.2` control 8) |
| `RN-CALIF-55` | Un boletín es **inmutable**: objeto escrito una vez, con huella SHA-256, tamaño y páginas. Estados: `pendiente → generado` \| `fallido`; `generado → sustituido` al reemitir. `PdfRenderingFailed` no se reintenta; `PdfRendererUnavailable` sí (`ADR-078 §4`) |
| `RN-CALIF-56` | El trabajo es idempotente: si el boletín ya tiene objeto y huella, termina sin regenerar. Primero el objeto, después la fila |
| `RN-CALIF-57` | Descarga: permiso `boletin.leer` y ámbito; `RN-CALIF-26` para familias; boletín `generado` (un `sustituido` solo con ámbito `todos` o `grupo`); auditoría de lectura (`RN-CALIF-08`); URL firmada de caducidad corta. El PDF nunca se sirve por una ruta pública ni se adjunta a un mensaje |
| `RN-CALIF-58` | Reemitir crea boletines nuevos; los anteriores `generado` de la misma matrícula, evaluación e idioma pasan a `sustituido` en la misma transacción que marca el nuevo `generado` |
| `RN-CALIF-59` | (`1.17-b`) Validación del tutor de grupo: opcional por centro, nivel N1 de `ADR-082`, sobre el boletín `generado` y su huella; no modifica el PDF. Con la opción activa, publicar exige todos los boletines vigentes validados (`409 calif.conflict.report_cards_not_validated`). `OPEN-CALIF-26` |
| `RN-CALIF-60` | (`1.17-b`) Aviso: al publicar, un aviso por tutor legal con vínculo activo y por alumno, en el idioma del destinatario, con enlace autenticado; sin PDF ni calificaciones en el mensaje; un tutor con el acceso revocado no recibe nada. `OPEN-CALIF-25` |

---

## 6. Casos límite y errores

| Caso | Comportamiento |
|------|----------------|
| Docente sin asignación en el curso | Listados vacíos; detalle y escritura `404` |
| Docente tutor del grupo A que imparte Matemáticas en B | Lee todo A y todo B con `grupo` (`RN-ACAD-98` une tutorías y asignaciones); con `clase` solo Matemáticas de B; **escribe** solo Matemáticas de B |
| Docente que además es tutor legal de un alumno | Unión de ámbitos: como familia ve solo lo publicado de su hijo; como docente, sus clases |
| Tutor legal con vínculo revocado | `404`; no recibe aviso ni ve boletines |
| Dos docentes de la misma clase guardan a la vez | Serializados por `FOR UPDATE` de la evaluación de grupo; gana el último, ambos auditados |
| Cierre mientras un docente guarda | El `PUT` toma `FOR SHARE` de la evaluación de grupo y el cierre `FOR UPDATE`: o entra antes del cierre o recibe `409 evaluation_closed` |
| Cambio de la asignación de escala con notas | `409 scale_in_use` |
| Asignatura retirada del grupo con calificaciones | `409 acad.conflict.has_dependents` (veto, `RN-CALIF-29`) |
| Matrícula dada de baja a mitad de evaluación | Sus notas se conservan y se leen; no admite notas nuevas |
| Curso académico cerrado | Todo en solo lectura (`409`); lectura con `curso_historico.leer` |
| Reapertura de una evaluación publicada con boletines emitidos | Publicación retirada; boletines intactos; las familias dejan de verlos hasta nueva publicación |
| Servicio `pdf` caído durante una emisión | Los trabajos reintentan con espera; la emisión sigue `en_curso`; agotados los intentos, el boletín queda `fallido` y la emisión `completada_con_errores` |
| Alumno sin tutores legales con vínculo activo | Boletín solo en el idioma por defecto del centro; ningún aviso |
| Módulo `calif` no contratado | `403 urn:pge:error:module-disabled` (`RMOD-009`) |
| Comentario con marcado HTML | Se guarda como texto y se escapa al presentar y al maquetar |
| Issue #60 | Comprobar en implementación que los códigos `calif.*` no salen con prefijo `core.` |

<!-- CONTINUARÁ: §7 a §13 -->
