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
| C9 | **«Envío automático por email»** frente a `ADR-079` (propuesta): el boletín se entrega como **enlace autenticado**, nunca adjunto ni con notas en el cuerpo | `REQ-CALIF-002`, `ADR-079` | El correo es un aviso con enlace al portal; se cumple la intención (avisar a los tutores legales) sin sacar datos de menores por correo. **Decidido** por el usuario el 2026-11-12 (`OPEN-079-03`: boletines como enlace autenticado, nunca adjunto); `OPEN-CALIF-25` queda cerrada |
| C10 | **Fuente del catálogo de competencias sin decidir** y sin decir quién las evalúa ni con qué escala | `OPEN-ACAD-19`, `REQ-CALIF-001`, `-004` | Entrega propia `1.16-c`, bloqueada por `OPEN-CALIF-12`; aquí solo su perfil |

### 0.3 Dependencias no implementadas (se declaran antes de seguir)

| Dependencia | Estado (2026-11-12) | Efecto |
|-------------|---------------------|--------|
| Módulo `calif` | No existe (`apps/api/app/Modules/` no lo tiene; `docs/modulos/` tampoco) | Se crea en `1.16-a` con `make:module` |
| `Acad\Domain`: `GradingScaleDirectory`, `AcademicPeriodDirectory`, `SubjectDirectory`, `TeachingMembership::classPairsFor()`, registros de vetos de períodos y escalas | «Escritas, no construidas» (`REQ-ACAD §7.2`, `§18.7`) | Se construyen en `1.16-a`, con `calif` como primer consumidor (`ADR-048`) |
| `Acad\Domain`: `TeachingMembership::tutoredGroupIdsFor()` | **No escrita**: la interfaz solo tiene `groupIdsFor()`, que une tutorías y asignaciones (`apps/api/app/Modules/Acad/Domain/TeachingMembership.php`, `RN-ACAD-98`) | Cambio aditivo propuesto (§7.3, `OPEN-CALIF-06`); se construye en `1.16-b` con el cierre, su primer consumidor (`RN-CALIF-09`) |
| `Core\Domain`: `PersonDirectory::localesByPersonIds()` | **No existe**: `PersonDirectory` no expone `people.locale` (comprobado en `apps/api/app/Modules/Core/Domain/PersonDirectory.php`; la columna sí está en `App\Models\Person`) | Cambio aditivo propuesto (§7.3, `OPEN-CALIF-06`); se construye en `1.17-a` con la emisión (`RN-CALIF-52`) |
| `Alum\Domain`: `EnrollmentRoster`, operación «matrículas por persona» y registro único de vetos de `alum` | Los **construye `1.13`** (`1.13-a` y `1.13-b`, tabla de dueños en `docs/modulos/REQ-ACAD/asistencia.md §8.2.1`, que es la fuente; criterio único de la revisión de coherencia H5, 2026-11-13). Un solo registro de vetos en `alum` para todos los casos (anulación de matrícula, alta, cambio de grupo) | `1.16-a` **solo los consume** y registra su veto en ese registro; no construye nada en `alum`. Sin `1.13-b` mezclada, `1.16-a` queda bloqueada |
| `REQ-SEED` (bloque `calificaciones`) | Sin implementar | El bloque de siembra de este módulo va **en el propio paso `1.16`** (criterio de `REQ-SEED §14`, revisión de coherencia H4); `1.15b` no se amplía |
| Servicio `pdf` y contrato `PdfRenderer` (`ADR-078`) | No existen; ADR en `PROPUESTA` (el usuario aceptó WeasyPrint: `OPEN-PDF-01`, `-02`) | **Bloquea `1.17-a`** (`OPEN-CALIF-20`). Esta especificación **no decide el motor**: solo consume `PdfRenderer` |
| Motor de notificaciones en `Core` (`ADR-079`) y `REQ-COM` (`1.19`) | No existen; ADR en `PROPUESTA` | Bloquea el aviso a familias de `1.17-b` |
| Firma N1 y reautenticación (`ADR-082`, `REQ-DOC`) | No existen; ADR en `PROPUESTA` | Bloquea la validación del tutor de grupo de `1.17-b` |
| `REQ-SEED` (`1.15b`) | Sin implementar | La medición de volumen (`ADR-078 §5.4`) y el E2E usan sus datos; `REQ-SEED-004` pide «calificaciones de una evaluación completa», que aporta el bloque de `REQ-SEED` de `1.16` (fila anterior) |

---

## 1. Alcance y entregas

### 1.1 Entregas [DERIVADA: `CLAUDE.md §2.7`, ≤3 tramos de `implementer` cada una]

| Entrega | Contenido | Tramos previstos | Bloqueada por |
|---------|-----------|------------------|---------------|
| **`1.16-a`** | Módulo `calif`; interfaces y vetos en `acad`; registro del veto de `calif` en el registro único de `alum` (construido por `1.13`); períodos de evaluación; asignación de escalas; evaluaciones de grupo (apertura); calificaciones con comentario; resolutores `clase`, `grupo` y `unidad_familiar`; auditoría de lectura. Solo API | T1 `acad` (interfaces, vetos) y consumo de `alum` · T2 migraciones, permisos y siembra · T3 servicios y *endpoints* | `OPEN-CALIF-01` a `-06`; `1.13-b` mezclada |
| **`1.16-b`** | Elementos ponderados y media calculada; cierre, reapertura y publicación con su registro; visibilidad para familias; validación de cierre del curso (`RN-CURSO-30`); historial académico (lectura) | T1 datos y elementos · T2 transiciones (con `tutoredGroupIdsFor` en `acad`, `RN-CALIF-09`), publicación y validación de cierre · T3 historial y concurrencia | `OPEN-CALIF-07` a `-09` |
| **`1.16-c`** | Competencias LOMLOE con descriptores | Por dimensionar tras la decisión | `OPEN-CALIF-12` (necesita detalle propio antes de implementar) |
| **`1.16-d`** | Pantallas de personal (§10) y E2E contra la API real | T1 configuración y evaluaciones · T2 rejilla de calificación · T3 E2E | Detalle de interfaz (§10.2) |
| **`1.17-a`** | Ajustes del boletín, emisión en cola, plantilla en cuatro idiomas, almacén, descarga auditada, historial de boletines. Sin firma ni aviso | T1 medición del motor con la plantilla (`ADR-078 §5.4`) y datos · T2 trabajos, servicios y *endpoints* · T3 conformidad e idiomas | `ADR-078` aceptada **e implementada**; `OPEN-CALIF-20` a `-24` |
| **`1.17-b`** | Validación del tutor de grupo (N1), aviso a tutores legales con enlace autenticado, pantallas y E2E | T1 validación · T2 aviso · T3 pantallas y E2E | `ADR-079` y `ADR-082` aceptadas e implementadas; `OPEN-CALIF-26` (`OPEN-CALIF-25` cerrada por `OPEN-079-03`) |

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
- Ampliación de interfaces que sostienen autorización (`TeachingMembership::classPairsFor`, `TeachingMembership::tutoredGroupIdsFor`).
- `1.16-d` es solo interfaz, pero expone esos datos y su diff puede tocar rutas y permisos de `shell.ts`: en la duda, crítico.

Revisión: Codex primero; `security-reviewer` con su columna; `db-reviewer` en toda entrega con migraciones; `doc-precheck` y `doc-reviewer`. La sesión comprueba la clasificación contra el diff.

---

## 3. Actores y roles implicados

| Actor (`§11.1`) | Qué hace |
|-----------------|----------|
| Administrador de Centro | Todo, incluida la configuración |
| Dirección / Jefatura de Estudios | Configura evaluaciones, abre, cierra, reabre y **publica**; emite boletines; lee todo |
| Docente | Califica **sus clases** (ámbito `clase`); define sus elementos ponderados |
| Tutor de grupo | Lee las calificaciones de **su grupo** (ámbito `grupo`), cierra su evaluación de grupo, emite y valida sus boletines; cerrar, reabrir, emitir y validar, solo en los grupos que **tutoriza** (`RN-CALIF-09`) |
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
3. Idiomas: un boletín por matrícula y por cada **idioma distinto** de sus destinatarios (tutores legales con vínculo activo), más el idioma por defecto del centro (`OPEN-CALIF-21`). El idioma de cada tutor legal se lee con `PersonDirectory::localesByPersonIds()` (cambio aditivo en `Core`, §7.3); `calif` no lee `people` (`INV-007`).
4. Un boletín emitido no se regenera (`ADR-078 §7.2`). Reemitir tras una reapertura crea boletines nuevos y marca los anteriores `sustituido`.
5. Descarga: `GET /report-cards/{id}/download` comprueba permiso, ámbito y, para familias, publicación; registra la lectura y devuelve una URL firmada de caducidad corta (`ADR-013`).

### 4.8 Validación del tutor de grupo y aviso a familias (`1.17-b`, condicionado)

1. Si el centro lo activa, el tutor de grupo valida cada boletín (o el lote de su grupo) con `boletin.firmar`: evidencia N1 de `ADR-082` (`OPEN-CALIF-26`). Con la opción activa no se publica una evaluación con boletines sin validar.
2. Al publicar, el motor de `ADR-079` avisa a cada tutor legal con vínculo activo, en su idioma, con un **enlace autenticado** al boletín. El mensaje no lleva el PDF ni ninguna calificación (decisión del usuario del 2026-11-12, `OPEN-079-03`; `OPEN-CALIF-25` cerrada).

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
| `RN-CALIF-09` | **Ámbito `grupo` en acciones de tutoría** (`OPEN-CALIF-30`): el resolutor (`grupo`, `evaluacion`/`boletin`) usa `groupIdsFor`, que une tutorías **y** asignaciones (`RN-ACAD-98`); para **cerrar, reabrir** (`evaluacion.aprobar`), **emitir** (`boletin.crear`) y **validar** (`boletin.firmar`) eso no basta. Cuando el sujeto tiene la acción solo con ámbito `grupo` (sin `todos`), el servicio sancionado exige además que el grupo de la evaluación de grupo esté en `TeachingMembership::tutoredGroupIdsFor(u, curso)` (tutorías vivas del curso, sin asignaciones; §7.3); si no, `404` idéntico al de inexistente (`RN-CALIF-05`). Se comprueba en la acción individual y en la de bloque (validación en bloque, `api.md §5`), antes de leer el cuerpo. La lectura no cambia. Se comprueba el ámbito, nunca el rol (`AR-08`) |

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
| `RN-CALIF-33` | Escritura por conjunto: `PUT` de una clase con la lista de matrículas; cada elemento crea, actualiza o, con valor y comentario `null`, borra (lógico) la calificación. Todo o nada en una transacción; sin cambios no escribe ni audita (precedente `RN-ACAD-32`). Tope 100 elementos (`422 calif.validation.too_many_items`). **Bloqueos**, en este orden fijo: (1) `FOR SHARE` de la fila de la evaluación de grupo, que convive con otros `PUT` y excluye el `FOR UPDATE` del cierre (`RN-CALIF-23`), y después se comprueba que sigue `abierta`; (2) bloqueo de aviso de transacción (`pg_advisory_xact_lock`) con clave derivada de (evaluación de grupo, asignatura), que serializa dos `PUT` de la **misma clase** sin frenar los de otras clases del grupo. La derivación de la clave y su espacio propio del módulo se fijan en implementación. Lo mismo para los `PUT` de elementos y de notas de elemento (`1.16-b`). Dos `PUT` concurrentes nunca acaban en `500` ni en violación de unicidad (`CA-CALIF-028`) |
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
| `RN-CALIF-52` | Idioma del boletín ∈ idiomas activos del centro; conjunto por matrícula según `OPEN-CALIF-21`. El idioma de cada tutor legal se lee con `PersonDirectory::localesByPersonIds()` (§7.3); un tutor sin idioma informado no aporta ninguno y rige el del centro [DERIVADA]. El trabajo fija el idioma explícitamente; todos los literales salen del sistema de traducción (`INV-009`); el contenido del centro traducible se resuelve con su cadena de reserva (`ADR-062`); el comentario del docente va tal cual |
| `RN-CALIF-53` | Contenido (`REQ-CALIF-002`): logotipo y datos del centro (de `Core`), datos del alumno (nombre, curso de etapa, grupo), evaluación, asignaturas con calificación, etiqueta de tramo y comentario, tutor o tutores de grupo por nombre, pie configurable. Nada más: sin fotografía, sin documento de identidad, sin datos de salud |
| `RN-CALIF-54` | Plantilla conforme a `ADR-078 §3.3`: HTML y CSS sin JavaScript, ningún recurso externo, estructura semántica, `<html lang>`. Todo dato escapado; el pie del centro es **texto plano** traducible (`ADR-070`, `ADR-078 §6.2` control 8) |
| `RN-CALIF-55` | Un boletín es **inmutable**: objeto escrito una vez, con huella SHA-256, tamaño y páginas. Estados: `pendiente → generado` \| `fallido`; `generado → sustituido` al reemitir. `PdfRenderingFailed` no se reintenta; `PdfRendererUnavailable` sí (`ADR-078 §4`) |
| `RN-CALIF-56` | El trabajo es idempotente: si el boletín ya tiene objeto y huella, termina sin regenerar. Primero el objeto, después la fila |
| `RN-CALIF-57` | Descarga: permiso `boletin.leer` y ámbito; `RN-CALIF-26` para familias; boletín `generado` (un `sustituido` solo con ámbito `todos` o `grupo`); auditoría de lectura (`RN-CALIF-08`); URL firmada de caducidad corta. El PDF nunca se sirve por una ruta pública ni se adjunta a un mensaje |
| `RN-CALIF-58` | Reemitir crea boletines nuevos; los anteriores `generado` de la misma matrícula, evaluación e idioma pasan a `sustituido` en la misma transacción que marca el nuevo `generado` |
| `RN-CALIF-59` | (`1.17-b`) Validación del tutor de grupo: opcional por centro, nivel N1 de `ADR-082`, sobre el boletín `generado` y su huella; no modifica el PDF. Con la opción activa, publicar exige todos los boletines vigentes validados (`409 calif.conflict.report_cards_not_validated`). Con ámbito `grupo`, solo valida el tutor de grupo **de ese grupo** (`RN-CALIF-09`, `tutoredGroupIdsFor`): quien solo imparte clase en él recibe `404`. `OPEN-CALIF-26` |
| `RN-CALIF-60` | (`1.17-b`) Aviso: al publicar, un aviso por tutor legal con vínculo activo y por alumno, en el idioma del destinatario, con enlace autenticado; sin PDF ni calificaciones en el mensaje; un tutor con el acceso revocado no recibe nada. Decidido (`OPEN-079-03`, 2026-11-12; `OPEN-CALIF-25` cerrada) |

---

## 6. Casos límite y errores

| Caso | Comportamiento |
|------|----------------|
| Docente sin asignación en el curso | Listados vacíos; detalle y escritura `404` |
| Docente tutor del grupo A que imparte Matemáticas en B | Lee todo A y todo B con `grupo` (`RN-ACAD-98` une tutorías y asignaciones); con `clase` solo Matemáticas de B; **escribe** solo Matemáticas de B. Cierra, reabre, emite y valida en A; en B, `404` (`RN-CALIF-09`, `CA-CALIF-044`, `-065`, `-073`) |
| Docente que además es tutor legal de un alumno | Unión de ámbitos: como familia ve solo lo publicado de su hijo; como docente, sus clases |
| Tutor legal con vínculo revocado | `404`; no recibe aviso ni ve boletines |
| Dos docentes de la misma clase guardan a la vez | Ambos toman `FOR SHARE` de la evaluación de grupo (compatibles) y se serializan por el bloqueo de aviso de (evaluación de grupo, asignatura) (`RN-CALIF-33`); gana el último en confirmar, ambos `200` y auditados, nunca `500` (`CA-CALIF-028`). Dos clases distintas del mismo grupo no se esperan |
| Cierre mientras un docente guarda | El `PUT` toma `FOR SHARE` de la evaluación de grupo y el cierre `FOR UPDATE`: o entra antes del cierre o, al obtener el bloqueo, la ve `cerrada` y recibe `409 evaluation_closed` (`CA-CALIF-036`) |
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

---

## 7. Interacción con otros módulos

Toda interfaz vive en `Domain` (nunca `Domain\Models`); ningún módulo lee tablas de otro (`INV-007`, `AR-01`, `AR-02`).

### 7.1 Lo que consume

| De | Qué | Estado |
|----|-----|--------|
| `curso` | `AcademicYearContext`, `AcademicYearDirectory`, `AcademicYearReadAccess`; registro de validaciones de cierre (`RN-CURSO-30`) | Existen |
| `acad` | `GroupDirectory`, `TeachingMembership::groupIdsFor()` | Existen (`1.15-b`) |
| `acad` | `TeachingMembership::tutoredGroupIdsFor()` (`RN-CALIF-09`) | **No escrita**: cambio aditivo de §7.3, `1.16-b` |
| `acad` | `TeachingMembership::classPairsFor()`, `SubjectDirectory`, `AcademicPeriodDirectory`, `GradingScaleDirectory`, registros de vetos de períodos y escalas | Escritas, no construidas: `1.16-a` T1 (§7.3) |
| `alum` | `EnrollmentRoster`, `StudentEnrollmentDirectory`, registro de vetos de anulación de matrícula | Especificadas; se construyen con su primer consumidor (§7.3) |
| `fam_unit` | `FamilyMembership` («alumnos alcanzados», solo vínculos activos) | Existe |
| `Core` | `PersonDirectory::localesByPersonIds()` (idioma de cada tutor legal, `RN-CALIF-52`) | **No existe**: cambio aditivo de §7.3, `1.17-a` |
| `Core` | `PersonDirectory` (nombre del alumno y del tutor de grupo), `UserDirectory`, `TenantSettingsReader`, marca del centro, `AuditRecorder::recordRead` | Existen; comprobar en implementación que la lectura de la marca tiene interfaz en `Core\Domain` y, si no, pedir su ampliación antes de escribirla |
| núcleo | `TenantModel`, `Auditable`, `ScopedQuery`, `App\Support\I18n`, `App\Support\Files` (`PrivateObjectStore`, o `TenantObjectStore` si `S-2` está cerrada) | Existen |
| `App\Support\Pdf` | `PdfRenderer`, `PdfDocument`, `PdfProfile`, `FakePdfRenderer` (`ADR-078 §4`) | **No existen** (`OPEN-CALIF-20`) |
| `Core` (`ADR-079`) | Motor de notificaciones | **No existe** |

### 7.2 Interfaces públicas que expone (`Calif\Domain`) [DERIVADA]

Se especifican aquí y se construyen con su primer consumidor (`ADR-048`). Ids internos, una consulta por llamada, sin efectos, fallo en cerrado (módulo no contratado ⇒ conjunto vacío).

| Interfaz | Operación | Devuelve | Primer consumidor |
|----------|-----------|----------|-------------------|
| `FinalGradesReader` | `definitiveGradesOf(list<int> enrollmentIds, int evaluationPeriodId)` | Por matrícula y asignatura: valor y si es aprobado, solo de evaluaciones `cerrada` | `1.17b` (`REQ-CURSO-003`: propuesta por número de suspensos) |
| `GroupEvaluationReader` | `closureOf(int groupId, int evaluationPeriodId)` | Estado, quién cerró y cuándo | `1.17b` (dato del acta, C6) |

### 7.3 Cambios que este paso pide en otros módulos (aditivos; requieren aprobación, `OPEN-CALIF-06`)

| Módulo | Cambio | Motivo |
|--------|--------|--------|
| `acad` | Construir `classPairsFor()` (un `SELECT` de asignaciones vivas, `RN-ACAD-98`), `SubjectDirectory` (resumen: id, curso de etapa, etapa, `code`, `nameTranslations`; y «asignaturas que cursa un grupo»), `AcademicPeriodDirectory` y `GradingScaleDirectory` con la forma de `REQ-ACAD §7.2` | Primer consumidor |
| `acad` | Registros de vetos de períodos lectivos y de escalas (`RN-ACAD-63`, `-77`), invocados por sus servicios de borrado y edición | Obligación escrita para `1.16` |
| `acad` | Añadir a `TeachingMembership` el método `tutoredGroupIdsFor(int $userId, int $academicYearId): list<int>`: ids internos de los grupos de las **tutorías vivas** del usuario en ese curso (`group_tutors`), **sin** las asignaciones; misma forma que `groupIdsFor` (solo filas vivas del centro actual, una consulta, memoizada por petición, sin bloqueos) | `RN-CALIF-09`: `groupIdsFor` une tutorías y asignaciones (`RN-ACAD-98`), y con él un tutor cerraría o validaría en grupos donde solo imparte. Primer consumidor: cierre (`1.16-b`) |
| `acad` | Ampliar `StructureEntity` del registro de vetos de estructura con `subject` y el veto de `PUT /groups/{id}/subjects` al quitar una asignatura (`RN-CALIF-29`) | Hoy solo cubre `group` y `course` (comprobado en `apps/api/app/Modules/Acad/Domain/StructureEntity.php`). **Coordinación con `REQ-INF` (`1.18`)**: su especificación amplía el mismo enumerado con la etapa y añade `EducationLevelDirectory`, y su veto sobre períodos lectivos depende del registro de vetos de períodos que construye `1.16-a` T1 (fila anterior). Orden propuesto: **`1.16-a` antes de `1.18`**; si `1.18` llegara antes, construiría el registro de períodos y las dos ampliaciones de `StructureEntity` (`subject` y etapa) como un solo cambio. Lo fija la sesión en `PLAN-IMPLEMENTACION.md` (§13) |
| `Core` | Añadir a `PersonDirectory` el método `localesByPersonIds(list<int> $personIds): array<int, ?string>`: el `locale` de esas personas **vivas** del centro, por id interno, en una consulta; las inexistentes, borradas o de otro centro no aparecen (forma de `birthDatesByIds`) | `RN-CALIF-52`: el boletín sale en el idioma de cada tutor legal y `PersonDirectory` no expone `people.locale` (comprobado). Primer consumidor: emisión (`1.17-a`) |
| `alum` | **Ninguno que construir**: `EnrollmentRoster`, la operación «matrículas por persona» (para el resolutor `unidad_familiar`) y el registro único de vetos los construye `1.13` (`docs/modulos/REQ-ACAD/asistencia.md §8.2.1`, fuente). `1.16-a` los consume y registra en ese registro su veto sobre la anulación de matrícula | Criterio único de la revisión de coherencia H5 (2026-11-13) |
| `curso` | Ninguno: `calif` solo registra su validación de cierre | — |

La documentación de `REQ-ACAD`, `REQ-ALUM` y `REQ-CORE` la actualiza la sesión (fuera del ámbito de escritura de esta especificación).

### 7.4 Eventos de dominio

Se **especifican**; se emiten con su primer consumidor (`ADR-048`, precedente `OPEN-CURSO-18`): `GroupEvaluationPublished` (centro, evaluación de grupo, instante; sin datos personales) lo consume el aviso de `1.17-b`. `calif` no consume eventos.

---

## 8. Descriptor del módulo (`ADR-045`, `ADR-034 §5`)

| Campo | Valor propuesto |
|-------|-----------------|
| `code` | `calif` |
| `name_key` | `modules.calif` (es «Calificaciones y evaluación», en «Grades and assessment», de «Noten und Bewertung», fr «Notes et évaluation») |
| `phase` | `1` |
| `depends_on` | `['curso', 'acad', 'alum']` (`OPEN-CALIF-01`) |
| `essential` | `false` |
| `feature_flags` | Ninguno |

Generación: `make:module Calif --code=calif …` sin `--entity`; los modelos se crean a mano con la política de auditoría de `datos.md §4`.

## 9. Comportamiento con el módulo desactivado

- API: toda ruta lleva `module-enabled:calif` antes de `permission:` ⇒ `403 urn:pge:error:module-disabled` (`RMOD-009`).
- Interfaz: rutas, navegación y bloques del `shell` ocultos (`RMOD-008`).
- Las validaciones y vetos que `calif` registra en `curso`, `acad` y `alum` **no se ejecutan** con el módulo no contratado (`RN-CURSO-60`, `ModuleAvailability`). Límite conocido y heredado (#597): al reactivar pueden existir referencias que el veto habría impedido romper; las FK `NO ACTION` y el borrado lógico evitan la pérdida de datos.
- Los trabajos de boletines encolados antes de desactivar terminan `fallido` sin generar (comprueban la disponibilidad al empezar) [DERIVADA].
- Los boletines ya emitidos se conservan en el almacén; su retención no depende del módulo (`datos.md §5`).

---

## 10. Interfaz: *shell* del frontend (`ADR-053`)

### 10.1 Inventario (`1.16-d` y `1.17-b`)

Módulo web `apps/web/src/modules/calif/` (las siete piezas y los dos registros de `AR-11`). Todas con `meta.layout` de aplicación y `meta.academicYearScoped: true`.

| Nombre | Ruta | `meta.permissions` (anyOf) | Menú | Contenido | Entrega |
|--------|------|----------------------------|------|-----------|---------|
| `calif-config` | `/evaluacion/configuracion` | `['configuracion_evaluacion.leer']` | Sí | Períodos de evaluación por etapa y asignación de escalas | `1.16-d` |
| `calif-group-evaluations` | `/evaluacion/grupos` | `['evaluacion.leer']` | Sí | Tabla de evaluaciones de grupo (`src/data-table`) con estado y publicación; abrir, cerrar, reabrir, publicar | `1.16-d` |
| `calif-grading` | `/evaluacion/grupos/:id` | `['calificacion.leer']` | No | Rejilla de calificación de una clase: matrículas × (elementos, propuesta, nota, comentario) | `1.16-d` |
| `calif-academic-record` | `/evaluacion/alumnos/:id` | `['calificacion.leer']` | No (se llega desde la ficha del alumno) | Historial académico | `1.16-d` |
| `calif-report-cards` | `/evaluacion/boletines` | `['boletin.leer']` | Sí | Emisiones con progreso, boletines, descarga, validación | `1.17-b` |
| `calif-report-settings` | `/evaluacion/boletines/ajustes` | `['configuracion_evaluacion.leer']` | No | Pie y opciones del boletín | `1.17-b` |

Sección de navegación y bloques del panel de inicio: sin decidir (`OPEN-CALIF-29`).

### 10.2 Lo que falta para implementar la interfaz

La rejilla de calificación **no es una tabla de datos**: es una rejilla de edición, y `RN-CORE-53` mantiene una lista cerrada de ellas (precedente `RolePermissionMatrix.vue`, `OPEN-PERM-08`). Añadirla exige aprobación expresa. Las reglas y criterios de interfaz (teclado, guardado, estados vacíos, móvil, WCAG 2.2 AA, `docs/design-system.md §22`) se escriben como ampliación de este fichero **antes** de `1.16-d`, como se hizo en `REQ-PERM §20` y `REQ-FAM-UNIT §23`; no se inventan aquí. Requisitos que esa ampliación debe cumplir: `RNF-UX-006` (≤3 clics desde el panel para calificar), `RUX-RESP-004`, `INV-009`.

---

## 11. Criterios de aceptación

Cada criterio tiene un test que cita su ID (`INV-015`). Entre paréntesis, la entrega.

### 11.1 Configuración (`1.16-a`)

- **`CA-CALIF-001`** *Dado* un usuario con `configuracion_evaluacion.crear`, *cuando* crea un período `parcial` con un período lectivo vivo de la misma etapa, *entonces* `201`, hereda el curso académico de la etapa y queda auditado `created` con el nombre desglosado por idioma.
- **`CA-CALIF-002`** *Dado* un período lectivo de **otra** etapa, o un `final` con `academic_period`, *cuando* se crea, *entonces* `422` con el código de `RN-CALIF-10` y nada se escribe.
- **`CA-CALIF-003`** *Dado* un período de evaluación vivo sobre un período lectivo, *cuando* se crea otro sobre el mismo, o dos peticiones simultáneas lo intentan, *entonces* una sola `201` y la otra `422 evaluation_period_exists`, nunca `500`.
- **`CA-CALIF-004`** *Dado* un período lectivo referenciado por un período de evaluación vivo, *cuando* se borra en `acad`, *entonces* `409 acad.conflict.has_dependents` (veto de `RN-CALIF-14`); con `calif` no contratado en el centro, el veto no se ejecuta.
- **`CA-CALIF-005`** *Dado* una etapa con escala por defecto y una asignatura con escala propia, *cuando* se resuelve la escala de cada asignatura, *entonces* la propia gana y las demás usan la de la etapa; sin ninguna, calificar da `409 scale_not_assigned`.
- **`CA-CALIF-006`** *Dado* una asignación de escala con calificaciones vivas, *cuando* se cambia o borra, *entonces* `409 scale_in_use`; y editar en `acad` el umbral o un valor de esa escala da `409` por el veto de `RN-CALIF-17`, mientras que cambiar su nombre o etiquetas da `200`.
- **`CA-CALIF-007`** *Dado* una escala de otro curso académico, *cuando* se asigna, *entonces* `422 parent_not_found` (y la FK compuesta lo impediría en el motor).

### 11.2 Evaluaciones de grupo y calificaciones (`1.16-a`)

- **`CA-CALIF-010`** *Dado* un período de evaluación de una etapa con tres grupos, *cuando* se abre para todos dos veces, *entonces* existen exactamente tres evaluaciones de grupo `abierta` y la segunda llamada no escribe ni audita.
- **`CA-CALIF-011`** *Dado* un docente con `calificacion.actualizar` en ámbito `clase` asignado a Matemáticas del grupo A, *cuando* guarda las calificaciones de esa clase, *entonces* `200`, una fila por matrícula y auditoría `created`/`updated` con el valor y sin el comentario.
- **`CA-CALIF-012`** *Dado* ese docente, *cuando* intenta guardar Lengua del grupo A (que no imparte) o Matemáticas del grupo B, *entonces* `404` con el mismo cuerpo que un recurso inexistente y nada se escribe.
- **`CA-CALIF-013`** *Dado* ese docente, que además es tutor del grupo A, *cuando* intenta escribir una asignatura de A que no imparte, *entonces* `404` (`RN-CALIF-34`).
- **`CA-CALIF-014`** (listado **y** detalle, `ADR-044 §4.2`) *Dado* un docente con `calificacion.leer` solo en `clase`, *cuando* lista las calificaciones de una evaluación de grupo, *entonces* solo ve las de sus pares; y *cuando* pide por `public_id` una calificación de otra clase, *entonces* `404`.
- **`CA-CALIF-015`** (listado **y** detalle) *Dado* un tutor de grupo con `calificacion.leer` en `grupo`, *cuando* lista, *entonces* ve todas las asignaturas de su grupo y ninguna de otros; el detalle de una calificación de otro grupo es `404`.
- **`CA-CALIF-016`** *Dado* un valor fuera del rango, con más decimales que la escala, un `scale_value` de otra escala o el campo del tipo equivocado, *cuando* se guarda, *entonces* `422` con el código de `RN-CALIF-31` en el elemento afectado y **ninguna** calificación del conjunto se escribe.
- **`CA-CALIF-017`** *Dado* una matrícula en `baja`, de otro grupo o de otro centro en el conjunto, *cuando* se guarda, *entonces* `422 enrollment_not_in_group` sin revelar a quién pertenece.
- **`CA-CALIF-018`** *Dado* un `PUT` idéntico al estado guardado, *cuando* se repite, *entonces* `200` sin escritura ni auditoría.
- **`CA-CALIF-019`** *Dado* una escala `mixta`, *cuando* se lee una calificación, *entonces* trae el valor como cadena con los decimales de la escala, la etiqueta del tramo resuelta en el idioma pedido e `is_passing` derivado.
- **`CA-CALIF-020`** *Dado* una matrícula con calificaciones vivas, *cuando* se anula en `alum`, *entonces* `409` por el veto; *dado* un grupo con evaluaciones de grupo vivas o una asignatura con calificaciones, *cuando* se borran o se quita la asignatura del grupo, *entonces* `409 acad.conflict.has_dependents`.
- **`CA-CALIF-021`** (curso cerrado, listado **y** detalle; `RN-CURSO-33`) *Dado* un usuario con `calificacion.leer` y sin `curso_historico.leer`, *cuando* lista las calificaciones de un curso `cerrado` y *cuando* pide una por `public_id`, *entonces* `404` como si no existiera; con `curso_historico.leer` recibe los datos. Lo mismo para períodos de evaluación, asignaciones, evaluaciones de grupo, elementos y boletines.
- **`CA-CALIF-022`** *Dado* un curso `cerrado`, *cuando* se intenta cualquier escritura del módulo, *entonces* `409 urn:pge:error:academic-year-closed` (criterio de `§5.28`: «el sistema lo impide e indica el motivo»).
- **`CA-CALIF-023`** (aislamiento, `INV-001`) *Dado* dos centros con datos equivalentes, *cuando* un usuario del primero usa cualquier *endpoint* del módulo con identificadores del segundo, *entonces* ninguno devuelve, modifica ni cuenta datos del otro; las tablas del módulo pasan `IsolationBatteryTest`.
- **`CA-CALIF-024`** *Dado* cualquier lectura que devuelve calificaciones, *cuando* responde `200`, *entonces* existe una fila `read` en `audit_logs` con el actor, el recurso y los filtros, sin valores; una respuesta `404` o `403` no la crea.
- **`CA-CALIF-025`** *Dado* la siembra de un centro nuevo y el comando `calif:grant-permissions` sobre uno existente, *cuando* se ejecutan, *entonces* los roles predefinidos tienen exactamente las concesiones de `permisos.md §4`; `docente` no tiene `todos` sobre `calificacion`; el comando es idempotente y no toca roles personalizados.
- **`CA-CALIF-026`** *Dado* el módulo no contratado, *cuando* se llama a cualquier ruta, *entonces* `403 module-disabled`; `./vendor/bin/pest --group=arch` en verde con las ampliaciones aprobadas de `AR-10`.
- **`CA-CALIF-027`** *Dado* `classPairsFor(u, curso)`, *cuando* el usuario tiene una asignación a un subgrupo, *entonces* el par se proyecta sobre el grupo (`RN-ACAD-98`); asignaciones borradas o de otro curso no cuentan.
- **`CA-CALIF-028`** (dos procesos reales; `RN-CALIF-33`) *Dado* dos docentes con `calificacion.actualizar` sobre la misma clase de una evaluación de grupo `abierta`, *cuando* envían a la vez dos `PUT` con conjuntos distintos que crean calificaciones de las mismas matrículas, *entonces* ambos responden `200`, ninguno `500` ni violación de unicidad, queda una sola calificación viva por (matrícula, asignatura) con el valor del último en confirmar, y los dos cambios están auditados.

### 11.3 Elementos, transiciones, publicación e historial (`1.16-b`)

- **`CA-CALIF-030`** *Dado* elementos con pesos 40, 40 y 20 y notas 6, 7 y 9 en una escala 0-10 con un decimal, *cuando* se lee la clase, *entonces* `calculated_value` es `"7.0"`; si falta una nota de elemento, es `null`.
- **`CA-CALIF-031`** *Dado* pesos que no suman 100, o una clase con escala `cualitativa`, *cuando* se guardan los elementos, *entonces* `422` (`RN-CALIF-41`, `-40`).
- **`CA-CALIF-032`** *Dado* una media cuyo tercer decimal es 5 (p. ej. 6,95 con un decimal), *cuando* se calcula, *entonces* redondea hacia arriba (`"7.0"`) y el resultado es idéntico en cien ejecuciones (aritmética decimal exacta).
- **`CA-CALIF-033`** *Dado* una propuesta `"6.5"`, *cuando* el docente guarda `"7"`, *entonces* la calificación es `"7"` y la representación conserva ambas.
- **`CA-CALIF-034`** *Dado* una evaluación de grupo con calificaciones pendientes, *cuando* se cierra sin `confirm_missing`, *entonces* `409 missing_grades` con el recuento; con `confirm_missing: true`, `200`, estado `cerrada` y una fila de transición con actor e instante.
- **`CA-CALIF-035`** *Dado* una evaluación `cerrada`, *cuando* un docente guarda calificaciones, elementos o notas de elemento, *entonces* `409 evaluation_closed`.
- **`CA-CALIF-036`** (dos procesos reales) *Dado* un cierre y un `PUT` de calificaciones simultáneos, *entonces* o el `PUT` confirma antes del cierre o recibe `409`; nunca queda una calificación escrita después del instante de cierre.
- **`CA-CALIF-037`** *Dado* una evaluación `abierta`, *cuando* se publica, *entonces* `409 not_closed`; *dado* una `cerrada`, `200` y `published_at` fijado; repetirlo no escribe.
- **`CA-CALIF-038`** (criterio de `§5.5`) *Dado* una evaluación de grupo sin publicar, *cuando* un tutor legal con vínculo activo lista las calificaciones de su hijo, pide una por `public_id`, pide el historial o lista sus boletines, *entonces* no ve ninguna calificación de ese período (lista vacía, `404`).
- **`CA-CALIF-039`** *Dado* la evaluación publicada, *cuando* ese tutor legal consulta, *entonces* ve las de su hijo y de ningún otro alumno del grupo; un tutor con el vínculo revocado recibe `404`.
- **`CA-CALIF-040`** *Dado* una evaluación publicada, *cuando* se reabre con motivo, *entonces* pasa a `abierta`, `published_at` queda nulo, la familia deja de verla y hay dos filas de transición (reapertura y retirada); sin motivo, `422`.
- **`CA-CALIF-041`** *Dado* un curso `activo` con una evaluación de grupo `abierta` o `cerrada` sin publicar, *cuando* se cierra el curso, *entonces* `curso` responde su `409` de validaciones de cierre con la entrada `calif.conflict.unpublished_evaluations` y sus recuentos, sin identificadores.
- **`CA-CALIF-042`** *Dado* un alumno con matrículas en dos cursos académicos, *cuando* un usuario con `todos` y `curso_historico.leer` pide el historial, *entonces* recibe las calificaciones definitivas de ambos agrupadas por curso; sin `curso_historico.leer`, el curso cerrado no aparece.
- **`CA-CALIF-043`** *Dado* un docente en ámbito `clase`, *cuando* pide el historial de un alumno de sus clases, *entonces* solo ve sus asignaturas; de un alumno ajeno, `404`.
- **`CA-CALIF-044`** (`RN-CALIF-09`) *Dado* un usuario con `evaluacion.aprobar` solo en ámbito `grupo`, tutor de grupo de A y con una asignación docente en B, *cuando* cierra o reabre la evaluación de grupo de B, *entonces* `404` con el mismo cuerpo que un recurso inexistente, nada cambia y no hay fila de transición; *cuando* cierra la de A, `200`; y sigue leyendo las evaluaciones de A y de B con `evaluacion.leer` en `grupo`.

### 11.4 Boletines (`1.17-a`)

- **`CA-CALIF-050`** *Dado* una evaluación de grupo `cerrada` con 25 matrículas, *cuando* se pide la emisión, *entonces* la respuesta es `202` en menos del tiempo de `RNF-PERF-001`, no se ha llamado a `PdfRenderer` durante la petición y hay un trabajo por boletín en la cola `calif-pdf`.
- **`CA-CALIF-051`** *Dado* una evaluación `abierta`, o una emisión en curso, *cuando* se pide otra, *entonces* `409` (`RN-CALIF-50`).
- **`CA-CALIF-052`** *Dado* un alumno con dos tutores legales activos con idiomas `de` y `es-ES` y un centro con idioma por defecto `es-ES`, *cuando* termina la emisión, *entonces* existen exactamente dos boletines (`de`, `es-ES`); un tutor revocado no aporta idioma.
- **`CA-CALIF-053`** *Dado* un boletín en cada uno de los cuatro idiomas, *cuando* se inspecciona el `PdfDocument` recibido por `FakePdfRenderer`, *entonces* `lang` es el del boletín, ningún literal de plantilla queda sin traducir (paridad de claves `calif.report_card.*`), el nombre de la asignatura sale por la cadena de reserva de `ADR-062`, el perfil es `archival` y no hay ninguna referencia `http(s):`, `file:` ni `<script>`.
- **`CA-CALIF-054`** *Dado* un comentario del docente y un pie del centro con marcado HTML, *cuando* se maqueta, *entonces* llegan escapados como texto.
- **`CA-CALIF-055`** *Dado* un trabajo de boletín ejecutado dos veces, *cuando* termina el segundo, *entonces* hay un solo objeto en el almacén y la huella no cambia.
- **`CA-CALIF-056`** *Dado* `PdfRendererUnavailable`, *cuando* el trabajo falla, *entonces* se reintenta; *dado* `PdfRenderingFailed`, queda `fallido` sin reintento y la emisión termina `completada_con_errores` con el recuento.
- **`CA-CALIF-057`** *Dado* un trabajo en el *worker*, *cuando* se ejecuta, *entonces* fija el centro antes de leer nada y el objeto queda bajo el prefijo del centro; un trabajo con el identificador de un boletín de otro centro no genera nada (`INV-001`).
- **`CA-CALIF-058`** (listado **y** detalle) *Dado* un tutor de grupo con `boletin.leer` en `grupo`, *cuando* lista, *entonces* solo ve los de su grupo; el detalle y la descarga de uno ajeno son `404`.
- **`CA-CALIF-059`** *Dado* un boletín `generado` de una evaluación publicada, *cuando* el tutor legal pide la descarga, *entonces* recibe una URL firmada de caducidad corta y queda una fila `read`; sin publicar, `404` y ninguna URL.
- **`CA-CALIF-060`** *Dado* una reemisión tras reabrir y volver a cerrar, *cuando* termina, *entonces* los boletines anteriores están `sustituido`, sus objetos siguen en el almacén sin modificar y la familia solo ve los vigentes.
- **`CA-CALIF-061`** (criterio de `§5.5`, parte de marca) *Dado* un boletín publicado, *cuando* se genera, *entonces* la petición a `PdfRenderer` lleva el logotipo del centro como recurso con nombre y los datos del centro. La parte «firma digital del tutor» es `CA-CALIF-071`.
- **`CA-CALIF-062`** (conformidad, trabajo de CI aparte con el servicio real, `ADR-078 §4` regla 4) *Dado* la plantilla del boletín en los cuatro idiomas, *cuando* se maqueta con el motor real, *entonces* el resultado es un PDF con fuentes incrustadas, etiquetado y con el perfil pedido. **No se ejecuta en la suite ordinaria.**
- **`CA-CALIF-063`** (medición, `ADR-078 §5.4`; sin umbral propio: los fija `OPEN-PDF-04`) *Dado* 1.200 boletines con datos de `REQ-SEED`, *cuando* se emiten, *entonces* se anotan tiempo total, mediana, percentil 95 y memoria en `CHANGELOG.md`.
- **`CA-CALIF-064`** (E2E contra la API real) Emisión de un grupo y descarga del PDF desde el almacén con una cookie de sesión real.
- **`CA-CALIF-065`** (`RN-CALIF-09`) *Dado* un usuario con `boletin.crear` solo en ámbito `grupo`, tutor de grupo de A y con una asignación docente en B, ambas evaluaciones `cerrada`, *cuando* pide la emisión de B, *entonces* `404`, no se crea ninguna emisión ni se encola nada; la de A responde `202`.
- **`CA-CALIF-066`** (`RN-CALIF-52`, §7.3) *Dado* un tutor legal con vínculo activo y `locale` `fr` en su persona, *cuando* se calculan los idiomas de la emisión, *entonces* se leen con `PersonDirectory::localesByPersonIds()` en una consulta, sale un boletín en `fr`, y una persona de otro centro o borrada pasada en la lista no aparece en el resultado.

### 11.5 Validación y aviso (`1.17-b`, condicionados a `ADR-082` y `ADR-079`)

- **`CA-CALIF-070`** *Dado* la validación activada y un boletín sin validar, *cuando* se publica la evaluación, *entonces* `409 report_cards_not_validated`.
- **`CA-CALIF-071`** *Dado* un tutor de grupo con `boletin.firmar` en `grupo`, *cuando* valida un boletín de su grupo, *entonces* queda una evidencia de solo anexar con la huella del PDF, el PDF no cambia, y el de otro grupo es `404`.
- **`CA-CALIF-073`** (`RN-CALIF-09`, `RN-CALIF-59`) *Dado* un usuario con `boletin.firmar` solo en ámbito `grupo`, tutor de grupo de A y con una asignación docente en B, *cuando* valida un boletín de B, uno a uno o en bloque, *entonces* `404`, no queda ninguna evidencia, y sigue pudiendo leer los boletines de B con `boletin.leer` en `grupo`.
- **`CA-CALIF-072`** *Dado* la publicación de una evaluación, *cuando* se procesan los avisos, *entonces* cada tutor legal activo recibe uno en su idioma con un enlace que exige sesión; el mensaje no contiene ninguna calificación, ni el PDF adjunto, ni una URL firmada del almacén; el tutor revocado no recibe nada.

Los criterios de interfaz (`CA-CALIF-080` en adelante) se escriben con la ampliación de §10.2.

---

## 12. Preguntas abiertas

No se resuelve ninguna aquí. «Decide»: U = usuario, N = negocio, L = legal, T = técnico (con el usuario). La primera opción es la **recomendada**.

### 12.1 Bloquean `1.16-a`

| ID | Pregunta | Opciones | Recomendación e impacto | Decide |
|----|----------|----------|-------------------------|--------|
| `OPEN-CALIF-01` | `depends_on` y esencialidad (C1) | **A** `['curso','acad','alum']`, no esencial. **B** literal `['acad']` | **A**: B deja contratar `calif` sin matrículas, que son su eje. Exige corregir «Depende de» en `§5.5` | U |
| `OPEN-CALIF-02` | ¿Dónde vive el estado de una evaluación (abierta, cerrada, publicada)? | **A** por grupo (`group_evaluations`), con acciones en bloque. **B** por período y etapa. **C** por clase | **A**: la junta de evaluación y el boletín son por grupo; pasar después de B a A es migrar datos, de A a B es una acción en bloque. C multiplica estados sin requisito que lo pida | U |
| `OPEN-CALIF-03` | Asignación de escalas (`OPEN-ACAD-18`) | **A** por etapa con excepción por asignatura. **B** solo por asignatura. **C** solo por etapa | **A**: una fila configura una etapa entera y admite la asignatura que se califica distinto (p. ej. cualitativa). C no cubre «mixta» por asignatura | U |
| `OPEN-CALIF-04` | `RPERM-008` (C4) | **A** regla de negocio: se califica solo con la evaluación de grupo `abierta`. **B** motor genérico de permisos condicionales (ADR nuevo sobre `ADR-044`) | **A**: cubre el único caso que cita el requisito sin columna ni motor nuevos; B es semanas de trabajo en el núcleo de autorización sin otro caso de uso | U |
| `OPEN-CALIF-05` | Ámbitos, siembra y mapa de `AR-10` (`permisos.md §3`, `§4`) | **A** la tabla de `permisos.md §4` (docente `clase`; tutor de grupo `grupo`; familia `unidad_familiar`; estudiante y orientador sin nada). **B** además `propios` para estudiante | **A**: `propios` necesita la relación usuario → alumno que llega con `1.23`; ampliar `applicable_scopes` después es compatible. La ampliación de `AR-10` exige aprobación expresa | U |
| `OPEN-CALIF-06` | Cambios aditivos en `acad`, `alum` y `Core` (§7.3) | **A** aprobarlos, cada uno en la entrega de su primer consumidor (`1.16-a` T1 los ya escritos; `tutoredGroupIdsFor` en `1.16-b`; `localesByPersonIds` en `1.17-a`). **B** entrega previa separada | **A**: casi todos son interfaces ya escritas en sus especificaciones; `tutoredGroupIdsFor` y `localesByPersonIds` son nuevos, un método de lectura cada uno, con la forma de sus vecinos. B solo si T1 no cabe | U |

### 12.2 Bloquean `1.16-b`

| ID | Pregunta | Opciones | Recomendación e impacto | Decide |
|----|----------|----------|-------------------------|--------|
| `OPEN-CALIF-07` | Media ponderada: ¿propuesta o nota impuesta? Pesos y redondeo | **A** propuesta editable, pesos enteros que suman 100, redondeo mitad hacia arriba. **B** la media es la nota. **C** pesos libres normalizados | **A**: el redondeo y el criterio final son del docente (mismo patrón que `REQ-CURSO-003`, «siempre editable»); B obliga a fijar reglas de redondeo que ningún requisito da | U |
| `OPEN-CALIF-08` | Reabrir una evaluación ya publicada | **A** retira la publicación; los boletines emitidos se conservan y se sustituyen al reemitir. **B** sigue visible con los datos antiguos. **C** prohibido reabrir lo publicado | **A**: la familia nunca ve datos en revisión; C deja sin vía la corrección de un error | U |
| `OPEN-CALIF-09` | Cierre con calificaciones pendientes | **A** se permite con confirmación explícita. **B** se bloquea siempre. **C** se permite sin aviso | **A**: hay alumnos sin nota legítimamente (exenciones, incorporaciones tardías) y ningún requisito lo prohíbe | U |

### 12.3 Bloquean `1.16-c`

| ID | Pregunta | Opciones | Recomendación e impacto | Decide |
|----|----------|----------|-------------------------|--------|
| `OPEN-CALIF-12` | Competencias LOMLOE: fuente del catálogo, quién evalúa y con qué escala (`OPEN-ACAD-19`) | **A** catálogo que define el centro por curso académico y etapa (código, nombre y descriptores traducibles), evaluado por evaluación de grupo y matrícula con una escala cualitativa del centro. **B** catálogo oficial sembrado por la plataforma. **C** evaluación por competencia **y** asignatura | **A**: B exige fijar textos normativos estatales y autonómicos con fuente verificada y mantenerlos; C multiplica por diez la carga del docente. Tras decidir, `1.16-c` necesita su propio detalle (tablas, *endpoints*, criterios) antes de implementarse | U, L (textos oficiales si B) |

### 12.4 Bloquean `1.17-a`

| ID | Pregunta | Opciones | Recomendación e impacto | Decide |
|----|----------|----------|-------------------------|--------|
| `OPEN-CALIF-20` | ¿Quién y cuándo construye el servicio `pdf` y `PdfRenderer`? | **A** paso propio previo a `1.17-a` (aceptar `ADR-078`, construir servicio, contrato y test de conformidad con una plantilla de referencia). **B** dentro de `1.17-a` | **A**: con el motor dentro, `1.17-a` no cabe en tres tramos y mezcla infraestructura con negocio. Esta especificación no decide el motor | U |
| `OPEN-CALIF-21` | Idiomas de cada boletín (`ADR-078 §7.3` lo deja a esta especificación) | **A** uno por idioma distinto de los tutores legales activos, más el del centro. **B** uno por destinatario. **C** siempre los cuatro. **D** solo el del centro | **A**: cumple `ADR-021` con el mínimo de documentos; B duplica PDF idénticos; C cuadruplica el lote; D incumple «idioma del destinatario». A y B necesitan leer `people.locale`, que `PersonDirectory` no expone hoy: cambio aditivo `localesByPersonIds()` (§7.3, `OPEN-CALIF-06`) | U |
| `OPEN-CALIF-22` | ¿Publicar la evaluación publica también sus boletines? | **A** un solo acto. **B** publicación separada del boletín | **A**: un solo interruptor de visibilidad, coherente con el criterio de `§5.5` | U |
| `OPEN-CALIF-23` | Contenido: imagen de firma del tutor de grupo, aviso de «documento no oficial», pie | **A** sin imagen de firma (nombre del tutor de grupo y, cuando exista, la línea de validación N1); pie de texto plano traducible; aviso de no oficialidad fijo en etapas regladas. **B** imagen de firma subida por el docente | **A**: `ADR-078 §8` prohíbe presentar una imagen como firma, y subirla añade un fichero personal más que custodiar. El aviso de no oficialidad deriva de `ADR-016`, pero su texto es legal: no se traduce automáticamente | U, L (texto) |
| `OPEN-CALIF-24` | Perfil de PDF y retención del boletín | **A** `archival` (`ADR-078 §7.2`, P1) y conservación según el catálogo de `ADR-077`. **B** `accessible` | **A**: es un documento emitido. El plazo de conservación del historial académico interno no está fijado: pendiente de validación jurídica | U, L |

### 12.5 Bloquean `1.17-b`

| ID | Pregunta | Opciones | Recomendación e impacto | Decide |
|----|----------|----------|-------------------------|--------|
| `OPEN-CALIF-25` | Entrega a familias (C9) | **Cerrada**: A, aviso por el motor de `ADR-079` con enlace autenticado, sin PDF ni notas | **Decidida por el usuario el 2026-11-12** (`OPEN-079-03`: boletines como enlace autenticado, nunca adjunto). Ya no bloquea; `1.17-b` sigue exigiendo `ADR-079` aceptada e implementada | U (decidida) |
| `OPEN-CALIF-26` | Validación del tutor de grupo (C8) | **A** N1 de `ADR-082`, opcional por centro, evidencia en tabla de solo anexar de `calif` hasta que exista `REQ-DOC`. **B** esperar a `REQ-DOC`. **C** obligatoria siempre | **A** si `ADR-082` se acepta con N1 para boletines; la reautenticación depende de `OPEN-FIRMA-06`. Hasta entonces el criterio de `§5.5` queda cumplido solo en la parte de marca | U, L |

### 12.6 No bloquean (rige la recomendación mientras no se decida)

| ID | Pregunta | Recomendación | Decide |
|----|----------|---------------|--------|
| `OPEN-CALIF-10` | Nivel de auditoría de lectura | **A** una fila `read` por consulta en todo *endpoint* que devuelve calificaciones (`RN-CALIF-08`). B solo descarga de boletín e historial. A es más estricta y su volumen es acotado por petición, no por fila | U, L |
| `OPEN-CALIF-11` | Política de valor de auditoría de `Grade` | **A** `Selective`: valor registrado, comentario no. B `Full`. C sin valores | U, L |
| `OPEN-CALIF-13` | Alertas de riesgo, comparativas y gráficos (`REQ-CALIF-003`) | Diferir a un paso tras `1.19`, con umbrales definidos por el usuario; el historial de `1.16-b` es su base | U |
| `OPEN-CALIF-14` | Informes y plantillas configurables (`REQ-CALIF-004`) | Diferir hasta resolver `OPEN-PDF-07` (contenido del centro en plantillas) | U |
| `OPEN-CALIF-15` | Acta de evaluación como documento | En `1.17b`, sobre `GroupEvaluationReader` y `FinalGradesReader` | U |
| `OPEN-CALIF-16` | Participante de `calif` en el *rollover* (períodos de evaluación y asignación de escalas) | Entrega pequeña aparte tras `1.16-b`; el contrato de `curso` lo hace aditivo | U |
| `OPEN-CALIF-17` | Cambio de grupo a mitad de evaluación | Las notas se quedan en el grupo de origen (`RN-CALIF-37`); no se copian | U |
| `OPEN-CALIF-18` | Historial de cursos cerrados para familias | Se respeta el contrato: solo con `curso_historico.leer`; sembrarlo o no a `tutor_legal` se decide en `1.22` | U |
| `OPEN-CALIF-19` | Rectificación tras el cierre del curso (`OPEN-CURSO-19`) | Sin vía propia: reapertura de `ADR-059` | U |
| `OPEN-CALIF-27` | `INV-008`: base legal de calificaciones y comentarios | Cobertura parcial; condición previa a datos reales junto con `OPEN-13` (precedente `OPEN-ALUM-29`). Un comentario libre puede acabar conteniendo datos de salud o NEAE: la interfaz lo advierte, pero no hay control técnico | L |
| `OPEN-CALIF-28` | Particionado de `grades` y `component_grades` por curso académico | No particionar en fase 1 (`datos.md §3.1`); revisar con `db-reviewer` y con volumen de `REQ-SEED` | T |
| `OPEN-CALIF-29` | Sección de navegación y bloques del panel | Sección académica existente; sin bloques de panel hasta `1.21` | U |
| `OPEN-CALIF-30` | Consecuencias de `RN-ACAD-98` (`groupIdsFor` une tutorías y asignaciones) sobre el ámbito `grupo`. **Lectura**: un usuario con `grupo` lee todas las asignaturas de todos los grupos donde tutoriza o imparte. **Escritura de tutoría** (cerrar, reabrir, emitir, validar): con solo `groupIdsFor`, un tutor de A haría todo eso en B, donde solo da clase | **Lectura**: aceptarlo, es el equipo docente del grupo (si no, sembrar a `docente` solo `clase`, ya propuesto, y reservar `grupo` a `tutor_grupo`). **Escritura**: **A** (recomendada, aplicada en el texto) `RN-CALIF-09`, con `grupo` solo en grupos de `tutoredGroupIdsFor` (cambio aditivo en `acad`, §7.3); **B** un ámbito nuevo (p. ej. «grupo tutorizado») en el catálogo, que exige enmendar `ADR-044`/`ADR-063`; **C** aceptar que todo el equipo docente cierre y emita. A es el cambio más pequeño y reversible; C contradice «su grupo» de `§11.1` | U |

---

## 13. Cambios propuestos en ficheros compartidos

No los aplica esta especificación (`docs/proceso/ola1-arquitectura-2026-11.md §2`); los aplica la sesión al integrar.

| Fichero | Cambio propuesto |
|---------|------------------|
| `PLAN-IMPLEMENTACION.md` | `1.16` pasa a `[OPUS + SONNET]` ⚠️ crítico, partido en `1.16-a` a `-d`; `1.17` en `1.17-a` y `-b`; nota de que `1.17-a` exige el paso del servicio `pdf` (`OPEN-CALIF-20`) y `1.17-b` exige `ADR-079` y `ADR-082`; `1.17b` gana la nota de que el acta consume `Calif\Domain`; `1.16` incluye su bloque de `REQ-SEED` (calificaciones; criterio de `REQ-SEED §14`, sin ampliar `1.15b`); `1.16-a` depende de `1.13-b` (`EnrollmentRoster`, «matrículas por persona» y registro único de vetos de `alum`, construidos en `1.13`; `REQ-ACAD/asistencia.md §8.2.1`); dependencia **`1.16-a` → `1.18`** (registro de vetos de períodos lectivos y ampliación de `StructureEntity`, §7.3) o, si el orden se invierte, nota en `1.18` de que construye ambas ampliaciones como un solo cambio |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` | `§5.5`: «Depende de: CURSO, ACAD, ALUM» (si `OPEN-CALIF-01` = A); historial de versiones. Sin tocar el índice de ADR |
| `CHANGELOG.md` | Entrada de la ola: especificación de `REQ-CALIF` (`1.16`, `1.17`) |
| `memory.md` | Especificación propuesta, lista de preguntas bloqueantes por entrega, dependencias de `ADR-078`/`-079`/`-082` |
| `ARCHITECTURE.md §3.4` | Al implementar: referencia del patrón «resolutor de ámbito `clase`» |
| `SYSADMIN.md` | Al implementar `1.17-a`: cola `calif-pdf` en el `worker` (lista de colas y comprobación de salud) |
| `compose.yaml` e `infra/quadlet/worker.container` (configuración, no documentación: los cambia `implementer` en `1.17-a`, no esta especificación) | En la **misma entrega** que crea el trabajo: `calif-pdf` al final de `--queue` del servicio `worker` (perfil `worker`) y del `Exec=` de la unidad, y en la lista `--queues` de `core:queue-health` de ambos (`HealthCmd=` y `healthcheck`). Detalle en `operacion.md §4` |
| `docs/modulos/REQ-ACAD/`, `REQ-ALUM/`, `REQ-CURSO/`, `REQ-CORE/` (no compartidos, pero fuera de esta carpeta) | `REQ-ACAD §7.2`/`§18.7`: interfaces construidas, vetos y `tutoredGroupIdsFor` (§7.3); `REQ-ALUM §7.2`: operación nueva; `REQ-CURSO §15`: `OPEN-CURSO-19` resuelta por `OPEN-CALIF-19`; `REQ-CORE`: `PersonDirectory::localesByPersonIds()` (§7.3) |

---

¿Se aprueba esta especificación, con las decisiones de §12.1 para `1.16-a`, antes de pasar a implementación?
