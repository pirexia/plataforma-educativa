# REQ-ALUM · Gestión de alumnos y matrículas · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-ALUM` (sección 5.6 del documento de requisitos: `REQ-ALUM-001` expediente, `-002` matriculación, `-003` grupos y asignaciones) |
| Prioridad | MUST |
| Fase | 1 · paso **1.15** del plan (bloque D, Personas) |
| Depende de | Documento (3.2.25): `CORE, CURSO, ACAD, FAM-UNIT`, igual que lo real: `CORE` (esencial), `CURSO` (esencial; la matrícula lleva `academic_year_id`), `ACAD` (grupos y cursos de etapa) y `FAM-UNIT` (pertenencia del alumno a su unidad). **Aprobado** (`OPEN-ALUM-28` = A, 2026-11-06): `depends_on: ['curso', 'acad', 'fam_unit']`. Aplicado en el documento de requisitos 3.2.25 |
| Esencial | `essential: false`: consecuencia de `OPEN-ALUM-28` = A, porque un esencial no puede depender de `acad` ni de `fam_unit`, que no lo son (`ADR-045`) |
| Estado | **APROBADA** (2026-11-06); lo marcado [DERIVADA] o dependiente de un `OPEN-ALUM` no bloqueante sigue como propuesta. Decisiones del usuario: `OPEN-ALUM-01` = A, `-02` = A, `-03` = A, `-11` = B, `-13` = A, `-16` = A, `-19` = A, `-28` = A. Corregida el mismo día con los hallazgos de `doc-reviewer` (un Alto, siete Medios, Bajos). Quedan abiertas las no bloqueantes de §14.2 (`OPEN-ALUM-31` resuelta A el 2026-11-09). `1.15-a` implementada (T1+T2); `1.15-b` mezclada (PR #604, 2026-11-11); `1.15-c` implementada (T1, T2a, T2b), revisiones aplicadas, pendiente de PR y cierre; `1.15-d` **implementada** (T1 listado, ficha y foto; T2 matrículas, lista de clase con exportación y solicitudes de cambio de grupo; T3 E2E de navegador contra la API real: 14 tests de navegador y 11 HTTP), pendiente de revisiones, PR y cierre; **no probado** en `1.15-d`: la descarga del CSV desde S3 y la fotografía en E2E, aplazados a `S-1b` de `ADR-076` (§11.8, `CA-ALUM-072`) |
| Clasificación del paso: crítico | Ver §2 |
| Decisiones vinculantes de partida | `ADR-004`, `ADR-013`, `ADR-015`, `ADR-016`, `ADR-020`, `ADR-021`, `ADR-029`, `ADR-032`, `ADR-033`, `ADR-034 §1`/`§4`/`§6`, `ADR-035`, `ADR-038` (incluido `§8`, idempotencia), `ADR-044` (enmendado por `ADR-063`), `ADR-045`, `ADR-048`, `ADR-053`, `ADR-054`, `ADR-055`, `ADR-056`, `ADR-057`/`ADR-058`, `ADR-062`, `ADR-063`, `ADR-066`, `ADR-068`, `ADR-069 §4.2`; contratos ya aprobados de `REQ-ACAD/funcional.md §7.2` y `§18.7`, `REQ-FAM-UNIT/funcional.md §7.1`/`§7.4`, `REQ-CURSO/funcional.md §7.1` |

> Donde esta especificación añade algo que ni los requisitos ni un ADR fijan, se marca **[DERIVADA]** con su motivo. Donde falta información o hay contradicción, hay una pregunta abierta (§14) con opciones y recomendación. Las ocho preguntas aprobadas por el usuario se aplican como **decisión**; las que siguen abiertas, como recomendación citada, que cambia si el usuario decide otra cosa.

---

## 0. Terminología, contradicciones y huecos

### 0.1 Terminología (se usa igual en los cinco ficheros)

| Término | Entidad / tabla | Qué es |
|---------|-----------------|--------|
| **Alumno/a** | `Student` / `students` | **Faceta** de una `Person` (`ADR-034 §1`, `§16.1`): la persona en su papel de alumno del centro. No depende del curso académico. Sus datos personales viven en `people` (`Core`, `§16.3` regla 1); esta tabla no los duplica |
| **Expediente** | — (composición) | Pantalla de ficha del alumno (composición en la SPA, no la respuesta de la API): datos personales (de `Core`), tutores y contacto de emergencia (de `fam_unit`), matrículas (de este módulo). En `1.15` no es una tabla propia (§1.3) |
| **Matrícula** | `Enrollment` / `enrollments` | Un alumno en un curso académico, un curso de etapa y, si ya lo tiene, un grupo (`§16.1`, `§16.3` regla 4; grupo opcional por `OPEN-ALUM-11` = B). Eje del que colgarán asistencia, calificaciones y facturación |
| **Lista de clase** | — (consulta) | Las matrículas activas de un grupo (`REQ-ALUM-003`) |
| **Solicitud de cambio de grupo** | `GroupChangeRequest` / `group_change_requests` | Petición de mover una matrícula a otro grupo del mismo curso de etapa, con su aprobación, rechazo o cancelación (`REQ-ALUM-003`; `OPEN-ALUM-13` = A) |
| **Fotografía del alumno** | `StudentPhoto` / `student_photos` | Imagen de la ficha (`REQ-ALUM-001`; entra en `1.15-c`, `OPEN-ALUM-08` = A, decisión del usuario 2026-11-11). **No** es una publicación: `INV-014` rige publicar, no el uso interno |

Términos heredados sin cambio: **curso académico**, **etapa**, **curso de etapa**, **grupo** (`REQ-ACAD/funcional.md §0.1`); **unidad familiar**, **tutor**, **vínculo** (`REQ-FAM-UNIT/funcional.md §0.1`).

Reglas de nombre [DERIVADA]: código de módulo `alum`, prefijo de traducciones y errores `alum.`. Recursos de permiso `alumno` y `matricula` (singular, como `familia`); ninguno se llama como un ámbito.

### 0.2 Contradicciones y huecos detectados

| # | Hallazgo | Fuentes | Cómo queda |
|---|----------|---------|------------|
| C1 | **El requisito MUST de fase 1 contiene piezas de módulos de fase 2.** `REQ-ALUM-001` pide historial médico y alergias (`REQ-SALUD`, fase 2, que depende de `ALUM`), documentación adjunta con versiones (`REQ-DOC`), becas y ayudas (`REQ-BEC`) e incidencias (`REQ-CONV`). `REQ-ALUM-002` pide contrato con firma digital (`REQ-DOC-002`, `ADR-012`) y pago integrado (`REQ-FIN`). Mismo patrón que `REQ-CURSO` C3 | `§5.6`, `§5.0`, `§17` | Alcance aprobado (`OPEN-ALUM-01` = A): esas piezas se difieren a su módulo (`OPEN-ALUM-02` = A, `-03` = A; `-04` a `-07` siguen como recomendación). **Nada de `REQ-ALUM` está marcado `FUTURO`**: diferir no es descartar |
| C2 | **Preinscripción duplicada con `REQ-OFE`.** `REQ-ALUM-002` pide «preinscripción online con formulario configurable» y lista de espera; `REQ-OFE-002` (fase 2, *depende de* `ALUM`) pide lo mismo con más detalle. `ADR-015`/`ADR-016`: en el concertado madrileño la admisión es centralizada y la matrícula oficial vive en Raíces; nuestra matrícula es el **registro interno** del centro | `REQ-ALUM-002`, `REQ-OFE-002`, `ADR-015`, `ADR-016` | `OPEN-ALUM-03` = A: en `1.15` solo la matrícula directa por secretaría (el último estado del flujo, «matriculación») |
| C3 | **Categoría especial sin mecanismo.** El historial médico es salud: tabla separada, cifrado a nivel de campo y auditoría de lectura (`CLAUDE.md §8`, `§16.3` regla 5, `RPERM-012`, `RPERM-015`). No existe ADR de cifrado a nivel de campo (la custodia sigue bloqueada por lo mismo, `REQ-FAM-UNIT/funcional.md` C5) y `AR-09` falla en cuanto un permiso declare `is_special_category = true` | `CLAUDE.md §8`, `RSEC-ISO-008`, `ADR-044 §4.4`, `AR-09` | `OPEN-ALUM-02` = A: diferido a `REQ-SALUD`. Ningún permiso de `1.15` es de categoría especial |
| C4 | **Matrícula «en un curso académico y grupo».** `§16.1` define `Enrollment` con grupo y `§16.2` fija `ENROLLMENT }o--|| GROUP` (grupo obligatorio); `REQ-ALUM-003` habla de **asignar** después el grupo, y un alumno se matricula a menudo antes de que existan los grupos del curso siguiente | `§16.1`, `§16.2`, `REQ-ALUM-003` | `OPEN-ALUM-11` = B: curso de etapa obligatorio, grupo opcional. **Requiere ADR**: `§16` dice que sus entidades núcleo y relaciones «no deben modificarse sin ADR», y pasar `}o--||` a `}o--o|` modifica una relación. El ADR no reabre la decisión: la formaliza y actualiza `§16.1`/`§16.2`. Lo escribió `architect` (`ADR-071`, **`ACEPTADA` el 2026-11-09**) y la sesión aplicó `§16.1`/`§16.2`/`§18` en la versión 3.2.25 del documento de requisitos; `1.15-a` no depende de él |
| C5 | **Dependencias declaradas incompletas.** `§5.6` (y la fila del índice de módulos) dice `CORE, FAM-UNIT`, pero la matrícula referencia grupos y cursos de etapa (`ACAD`) y el curso académico (`CURSO`) | `§5.6`, `§16.2` | `OPEN-ALUM-28` = A. Aplicado en el documento de requisitos 3.2.25 (2026-11-09) |
| C6 | **`INV-008` no lo puede cumplir este módulo solo.** El catálogo de bases legales por campo sigue sin hacer (`OPEN-13`, `REQ-PRIV-006`). Mismo hallazgo que `REQ-FAM-UNIT` C3 | `INV-008`, `RSEC-GDPR-009` | Cobertura **parcial**, condición previa a datos reales (`OPEN-ALUM-29`) |
| C7 | **Salida autónoma y `OPEN-FAM-UNIT-52`/`-45`.** El plan anota en `1.15` que la salida autónoma «cierra `OPEN-FAM-UNIT-52` y `-45`». `REQ-FAM-UNIT §21.13.2` y `REQ-PRL` «Entrega `1.14b-d`» §1 dicen que `-52` se cierra en `1.14b-d2`, tras `1.15`, y que `-45` está «pendiente para transporte» y exige un ADR que sustituya un punto de `ADR-032` (`ADR-068 §10`) | `PLAN 1.15`, `REQ-FAM-UNIT §21.13.2`, `REQ-PRL` «Entrega `1.14b-d`» | **Redacción única** (§7.3): `1.15` deja especificados los contratos y construye la lectura de la fecha de nacimiento; `OPEN-FAM-UNIT-52` y la salida autónoma (`-31`) se cierran en `1.14b-d2`, al construir esos contratos con su primer consumidor; `-45` no la cierra ni `1.15` ni `1.14b-d2` (`OPEN-ALUM-22`). La nota del plan quedó corregida el 2026-11-06 |
| C8 | **Obligación de `#382` posiblemente ya cumplida.** `REQ-ACAD/funcional.md §16` dice que `1.11-b` midió `CA-ACAD-023` con su cifra en `CHANGELOG.md`; el plan pide repetirla en `1.15` «si no se hizo ya en `1.11`» | `PLAN 1.11`, `PLAN 1.15`, `REQ-ACAD §16` | Se mantiene sobre `enrollments`, la tabla de la carga masiva real (`CA-ALUM-060`), citando al lado la cifra de `1.11` |

---

## 1. Alcance (`OPEN-ALUM-01` = A)

### 1.1 Qué entra en `1.15`

| Pieza | Qué se entrega | Requisito |
|-------|----------------|-----------|
| Módulo `alum` | `apps/api/app/Modules/Alum/` con `ServiceProvider` (descriptor, catálogo de permisos, migraciones, *morph map*, resolutores), rutas, `lang/{es,en,de,fr}/alum.php`, `modules.alum`; módulo web `apps/web/src/modules/alum/` | `RMOD-001`, `AR-03`, `AR-11`, `AR-12` |
| Alumnado | Alta (crea la persona por `Core` y la faceta; opcionalmente la pertenencia a una unidad familiar en la misma transacción), consulta, edición de datos personales por `Core`, baja lógica y restauración | `REQ-ALUM-001` (ficha: datos personales) |
| Ficha / expediente | Composición de lectura **de la pantalla** (no de la respuesta de la API): datos personales, tutores con su contacto de emergencia (leídos de `fam_unit`), matrículas (desde `1.15-b`) | `REQ-ALUM-001` |
| Matrícula | Alta, consulta, edición, baja con fecha y motivo, anulación de una matrícula errónea | `REQ-ALUM-002` (último estado del flujo: «matriculación») |
| Listas de clase y exportación | Listado de matrículas por grupo; exportación CSV en cola (`ADR-054`/`ADR-055`) | `REQ-ALUM-003` |
| Cambio de grupo con aprobación | Solicitud, aprobación, rechazo y cancelación (`OPEN-ALUM-13` = A) | `REQ-ALUM-003` |
| Fotografía del alumno | Subida, sustitución, baja y descarga por URL firmada (`1.15-c`, `OPEN-ALUM-08` = A) | `REQ-ALUM-001` |
| Resolutores de ámbito | `unidad_familiar` (`1.15-a`) y `grupo` (`1.15-b`) sobre `alumno` y `matricula` (`OPEN-ALUM-16` = A) | `RPERM-004`, `INV-002` |
| En `acad` | Construcción de `TeachingMembership::groupIdsFor()` (`REQ-ACAD §18.7`) y `GroupDirectory` (`§7.2`), que tienen aquí su primer consumidor; registro de vetos de borrado (`RN-ACAD-21`). Todo en `1.15-b` | `ADR-063 §5.4`, `OPEN-ACAD-43` |
| En `fam_unit` | **Nada que construir**: `FamilyUnitStudents` ya existe (`apps/api/app/Modules/FamUnit/Domain/FamilyUnitStudents.php`, implementada por `Application/FamilyUnitStudentService.php`, enlazada en `FamUnitServiceProvider`); `alum` la consume | `REQ-FAM-UNIT-001`, `§7.4` punto 2 |
| En `Core` | Ampliaciones aditivas de `Core\Domain` aprobadas (`OPEN-ALUM-19` = A): lectura de `people.birth_date` (la columna ya existe) y corrección de datos de una persona con usuario | `INV-007` |
| Medición `#382` | `AcademicYearWriteGuardOverheadTest` repetido sobre `enrollments`, cifra en `CHANGELOG.md` | `ADR-057`, `CA-057-09` |
| Pantallas de personal | Listado y ficha de alumnado, matrícula, lista de clase, solicitudes de cambio de grupo y foto (§10) | `INV-006` |

### 1.2 Entregas [DERIVADA]

Ver §15. `1.15-a` alumnado (sin matrículas) y ámbito `unidad_familiar`; `1.15-b` matrícula, ámbito `grupo`, listas y exportación; `1.15-c` cambio de grupo y fotografía; `1.15-d` pantallas y E2E. **Hasta `1.15-b`, la ficha y el listado de alumnos salen sin matrículas** (sin `enrollments[]` ni filtros `course`, `group` y `enrollment_status`), y el alumno solo es visible con ámbito `todos` o `unidad_familiar`.

### 1.3 Qué NO entra, explícitamente

| Fuera de alcance | Requisito | Por qué | Dónde se retoma |
|------------------|-----------|---------|-----------------|
| Historial médico y alergias | `REQ-ALUM-001` | C3 | `REQ-SALUD` (fase 2), tras ADR de cifrado (`OPEN-ALUM-02` = A) |
| Preinscripción online, revisión, admisión, lista de espera | `REQ-ALUM-002` | C2 | `REQ-OFE` (fase 2) (`OPEN-ALUM-03` = A) |
| Contrato de matrícula, firma digital, pago integrado | `REQ-ALUM-002` | Dependen de `REQ-DOC`, `ADR-012`, `REQ-FIN` (C1) | Fase 2 (`OPEN-ALUM-04`) |
| Documentación adjunta con control de versiones | `REQ-ALUM-001` | `REQ-DOC`; sin antivirus de subidas (#45); minimización | `REQ-DOC` (`OPEN-ALUM-05`) |
| Becas y ayudas | `REQ-ALUM-001` | `REQ-BEC` (fase 2) | `OPEN-ALUM-06` |
| Calificaciones e incidencias del historial | `REQ-ALUM-001` | `REQ-CALIF` (`1.16`), `REQ-CONV` (fase 2) | `OPEN-ALUM-07` |
| Centros anteriores | `REQ-ALUM-001` | Ningún requisito fija sus campos | `OPEN-ALUM-07` |
| Autorizaciones legales en la ficha | `REQ-ALUM-001` | Imagen es de `fam_unit` (`ADR-066`); las demás, de `REQ-AUT` | `OPEN-ALUM-10` |
| Asignación automática a grupos por criterios | `REQ-ALUM-003` | Criterios sin definir | `OPEN-ALUM-14` |
| Documentos oficiales (PDF, CSV de Raíces) | `REQ-ALUM-003` | `ADR-016`; motor de PDF sin decidir | `OPEN-ALUM-15` |
| Importación masiva de alumnado | — (`REQ-ONB-002`) | Importador genérico | `1.24` (`OPEN-ALUM-26`) |
| Promoción y copia de matrículas al curso siguiente | `REQ-CURSO-003` | Paso propio | `1.17b`. `alum` no participa en el *rollover* |
| Asistencia sobre la matrícula | `REQ-ACAD-003` | Paso propio | `1.13` (§7.4) |
| Salida autónoma, alumno no recogido | `REQ-FAM-UNIT-005`, `REQ-PRL-004` | `1.14b-d2`/`-d4` | §7.3 |
| Datos de demostración | `REQ-SEED` | Paso propio | `1.15b` (§7.5) |
| Portales de familias y alumnado | `REQ-FAM-PORTAL`, `REQ-EST` | Pasos propios | `1.22`, `1.23`. La **API** sí admite `unidad_familiar` (`INV-006`) |
| Eventos de dominio | — | `ADR-048` | §7.6 |

---

## 2. Clasificación del paso: **crítico** (`ADR-069 §4.2`)

**Clasificación del paso: crítico.** Basta cualquiera de estas:

- Migraciones que **crean tablas** con RLS (`students`, `enrollments`, `group_change_requests` y `student_photos`).
- *Endpoints*, permisos y **resolutores de ámbito nuevos**, y entrada nueva en el mapa cerrado de `AR-10`.
- *Endpoints* que **leen y escriben datos personales de menores**.
- **Ficheros** (fotografía).
- Ampliación de interfaces públicas que sostienen autorización (`TeachingMembership`, `Core\Domain`).

Revisión: Codex primero (si no está disponible, respaldo de `ADR-050`), `security-reviewer` con su columna, `db-reviewer` (hay migraciones), `doc-precheck` + `doc-reviewer`. La sesión comprueba la clasificación contra el diff.

---

## 3. Actores y roles implicados

| Actor (`§11.1`) | Qué hace en este módulo |
|-----------------|-------------------------|
| Secretaría | Alta de alumnado, matrícula, baja, solicitud y cancelación de cambios de grupo, exportación de listas |
| Administrativo | Consulta |
| Dirección / Jefatura de Estudios | Consulta, exporta y **aprueba o rechaza** cambios de grupo (`OPEN-ALUM-13` = A) |
| Administrador de Centro | Todo |
| Docente | Alumnado y matrículas de **sus grupos** (ámbito `grupo`; nunca `todos`, `ADR-063 §5.4`), desde `1.15-b` |
| Tutor de grupo | Ídem con su grupo de tutoría (`RN-ACAD-98`), desde `1.15-b` |
| Tutor legal / Familia | Por API, alumnos con vínculo **activo** (ámbito `unidad_familiar`); pantallas en `1.22` |
| Estudiante | Nada en `1.15` (`OPEN-ALUM-16` = A: sin `propios`) |

Ninguna regla compara roles (`AR-08`).

---

## 4. Flujos principales

### 4.1 Alta de un alumno

1. Secretaría envía nombre, apellidos, documento (si lo tiene), fecha de nacimiento e idioma, opcionalmente el `public_id` de una unidad familiar, y la cabecera `Idempotency-Key` (`RN-ALUM-27`).
2. En **una** transacción, en dos pasos de `Core`: `PersonRegistrar::registerOrReuse()` registra la persona (nombre, apellidos, documento) y `PersonRegistrar::correctPerson()` escribe en la misma transacción su `birth_date` y `locale`, que `PersonRegistration` no lleva; `alum` crea la faceta; si llega la unidad, `alum` comprueba el permiso fijado por `OPEN-ALUM-31` = A (`familia.actualizar`) e invoca `FamUnit\Domain\FamilyUnitStudents::add()` (reglas de `REQ-FAM-UNIT §5.2`).
3. Cada escritura se audita en su módulo (`INV-003`). `201` con la ficha.

Un fallo en cualquier paso deshace los tres. Si el documento coincide con una persona viva del centro, `422 person_exists` sin revelar quién es (`RN-ALUM-09`).

### 4.2 Matrícula (`1.15-b`)

1. Secretaría elige alumno, curso académico (`activo` o en `planificacion`), curso de etapa, opcionalmente grupo, y fecha de matrícula.
2. El servidor comprueba: alumno vivo (bloqueado con `FOR SHARE`); curso académico escribible y legible; curso de etapa y grupo vivos (bloqueados con `FOR SHARE`, `lockAliveForShare`, `RN-ALUM-26`), del mismo curso académico y el grupo del curso de etapa (`GroupDirectory`); no hay otra matrícula activa del alumno en ese curso académico (`RN-ALUM-21`).
3. `201`. Un reintento de la misma petición responde `422 already_enrolled` por la unicidad natural (`RN-ALUM-27`).

### 4.3 Baja de una matrícula (traslado o baja)

Secretaría indica fecha de baja y motivo (`OPEN-ALUM-12`). La matrícula pasa a `baja` y se conserva entera (`§16.3` regla 4). Otra matrícula del mismo alumno en el mismo curso académico es posible después.

### 4.4 Cambio de grupo (`OPEN-ALUM-13` = A)

1. Quien tiene `matricula.actualizar` solicita mover una matrícula activa a otro grupo **del mismo curso de etapa**, con motivo.
2. Quien tiene `matricula.aprobar` y **no** es el solicitante la aprueba; cualquier titular de `matricula.aprobar` puede rechazarla con nota. Al aprobar, en la misma transacción se cambia el grupo y se cierra la solicitud.
3. Quien tiene `matricula.actualizar` puede cancelar una solicitud pendiente; queda registrado quién y cuándo.
4. Una matrícula sin grupo recibe su **primer** grupo con `PATCH` directo, sin solicitud (asignar no es cambiar) [DERIVADA].
5. **Implementación (`1.15-c` T2a)**: `GroupChangeService`; el cambio de grupo de la matrícula es un método público aditivo de `EnrollmentService` (`moveToGroup`, único camino sancionado, `AR-10`; `update` sigue prohibiéndolo). Bloqueo: curso académico `FOR SHARE` → matrícula `FOR UPDATE` → solicitud `FOR UPDATE`, en el mismo orden para solicitar, aprobar, rechazar y cancelar. `decision_note` es **obligatoria al rechazar** (1-500) y opcional al aprobar y cancelar (si viene, 1-500) [DERIVADA, decisión aprobada]. Anulada la matrícula con una solicitud pendiente, aprobar da `409 enrollment_changed`; rechazar y cancelar siguen siendo posibles.

### 4.5 Lista de clase y exportación

Listado de matrículas activas de un grupo (filtro `group`), con nombre del alumno. Exportación del mismo listado a CSV en cola, con los mismos filtros (`ADR-054 §8.2`), descargable por `data_exports` de `Core`.

### 4.6 Fotografía (`1.15-c`, `OPEN-ALUM-08` = A)

Subida por `App\Support\Files` (`ADR-068`, precedente `pickup_photos`): tipo real, tamaño, sin EXIF, almacenamiento privado; descarga por URL firmada de caducidad corta tras comprobar el permiso (`ADR-013`). La ficha (`GET /students/{id}`) devuelve solo `has_photo` (booleano; decisión del usuario 2026-11-11): la URL sale únicamente de `GET /students/{id}/photo`, que audita cada lectura. Dar de baja al alumno **purga** su foto viva (objeto y fila). Servicio: `StudentPhotoService`. Una foto viva por alumno.

---

## 5. Reglas de negocio

### 5.1 Alumnado

| ID | Regla |
|----|-------|
| `RN-ALUM-01` | Un alumno es una **faceta** de una persona: `UNIQUE (tenant_id, person_id) WHERE deleted_at IS NULL` (`ADR-034 §1`). Una persona con faceta viva no recibe otra ⇒ `422 alum.validation.person_already_student`. Desde el alta no se alcanza: `RN-ALUM-09` comprueba el documento antes y responde `person_exists`; la regla la cierra el índice único (y la comprobación del servicio) |
| `RN-ALUM-02` | `alum` **no guarda** nombre, documento, fecha de nacimiento ni contacto: los lee y escribe por `Core\Domain` (`§16.3` regla 1, `INV-007`). Nunca consulta `people` |
| `RN-ALUM-03` | El alta crea persona, faceta y, si llega `family_unit`, pertenencia, en una sola transacción |
| `RN-ALUM-04` | La unidad familiar es **opcional** en el alta (`OPEN-ALUM-23`). Con unidad, además de `alumno.crear` el sujeto debe tener también `familia.actualizar` de `fam_unit` (`OPEN-ALUM-31` = A, resuelta por el usuario el 2026-11-09); sin él, `403` y no se escribe nada. Añadir o quitar después sigue siendo la API de `fam_unit` |
| `RN-ALUM-05` | Baja lógica (`INV-004`). Un alumno con alguna matrícula `activa` viva no se da de baja ⇒ `409 alum.conflict.has_active_enrollments` [DERIVADA]. La baja **no** borra la persona ni la pertenencia. En `1.15-a`, antes de existir `enrollments`, la comprobación no tiene objeto y la baja se permite; la entrega `1.15-b` la añade |
| `RN-ALUM-06` | Restauración: solo si la persona sigue viva y no tiene otra faceta de alumno viva; si no, `409 alum.conflict.cannot_restore` |
| `RN-ALUM-07` | Corrección de datos personales: el `PATCH` de `alum` los reenvía a `Core`, que audita el cambio de la persona. Siempre por `PersonRegistrar::correctPerson()`, **con o sin usuario** (`OPEN-ALUM-19` = A; `correctUnaccounted` no admite fecha de nacimiento ni documento y `alum` no lo usa) (`RN-ALUM-10`) |
| `RN-ALUM-08` | Fecha de nacimiento obligatoria en el alta [DERIVADA: edad para la salida autónoma y para distinguir menores]; `AAAA-MM-DD`, no futura ⇒ `422 alum.validation.birth_date_invalid`. Se guarda en `people.birth_date` (columna existente) |
| `RN-ALUM-09` | **Documento que coincide con una persona viva del centro** (`OPEN-ALUM-19` = A): `422 alum.validation.person_exists`, con el mismo cuerpo sea cual sea la persona (sin nombre, sin `public_id`, sin decir si es alumno, tutor o personal), y no se crea nada. Secretaría busca a la persona existente por los cauces que su permiso le dé |
| `RN-ALUM-10` | **Corrección de una persona con usuario** (`OPEN-ALUM-19` = A): ampliación aditiva de `Core\Domain` que permite a `alum`, con `alumno.actualizar`, corregir nombre, apellidos, documento, fecha de nacimiento e idioma de la persona del alumno con o sin usuario, siempre por `PersonRegistrar::correctPerson()`; **nunca** credenciales, correo de acceso ni estado del usuario (siguen siendo de `Core`). Auditada por `Core` como cambio de la persona |

### 5.2 Matrícula (`1.15-b`)

| ID | Regla |
|----|-------|
| `RN-ALUM-20` | Una matrícula referencia alumno, curso académico, curso de etapa (obligatorio) y grupo (opcional, `OPEN-ALUM-11` = B). Curso de etapa y grupo son del **mismo** curso académico y el grupo es del curso de etapa; lo garantiza el motor con FK compuestas (`datos.md §1.2`) y el servicio antes con `422 alum.validation.group_not_in_course` / `course_not_in_academic_year` |
| `RN-ALUM-21` | **Una matrícula activa** por alumno y curso académico: índice único parcial. Segunda ⇒ `422 alum.validation.already_enrolled`; la violación por carrera se traduce al mismo `422`, nunca `500` |
| `RN-ALUM-22` | Estados (`OPEN-ALUM-12`): `activa` y `baja`. `baja` exige `withdrawn_on` (≥ `enrolled_on`) y `withdrawal_reason` ∈ {`traslado`, `baja_voluntaria`, `otro`} [DERIVADA]; `CHECK` en el motor. Baja sobre una matrícula ya en `baja` ⇒ `409 alum.conflict.already_withdrawn`. `baja → activa` no existe |
| `RN-ALUM-23` | Alta y edición solo en curso `activo` o `planificacion`; en `cerrado`/`archivado`, `409 academic-year-closed` (`ADR-057`). Toda lectura invoca `AcademicYearReadAccess`: sin `curso_historico.leer`, `404` en listado **y** detalle (`RN-CURSO-25`, `-33`) |
| `RN-ALUM-24` | Curso de etapa, grupo o curso académico del **cuerpo** ilegible o inexistente ⇒ `422 alum.validation.parent_not_found` (o `academic_year_not_found`), indistinguible (precedente `REQ-ACAD §16`, S-3) |
| `RN-ALUM-25` | **Anulación** (`DELETE`): borrado lógico de una matrícula creada por error, sujeto al registro de vetos de `alum` (§7.2; sin consumidores en `1.15-c`, siempre se permite) |
| `RN-ALUM-26` | `alum` registra en el registro de vetos de `acad` (`RN-ACAD-21`) que **un grupo o un curso de etapa con matrículas vivas no se borra** ⇒ `409 acad.conflict.has_dependents` con `enrollments` en `params.dependents`. El registro se construye **antes** de la FK a `groups`. Desde `1.15-c` el grupo tiene además el veto de `RN-ALUM-47` (`group_change_requests`) |
| `RN-ALUM-27` | **Idempotencia** (`INV-011` concretada por `ADR-038 §8`). Mecanismo existente del producto: *middleware* `RequireIdempotencyKey` y tabla `idempotency_keys`; contrato de `§8.2` sin cambios (ULID del cliente, formato inválido o ausencia donde es obligatoria ⇒ `400 urn:pge:error:malformed`; reintento con mismo cuerpo ⇒ respuesta original con `Idempotency-Replayed: true`; mismo cuerpo distinto o primera aún en curso ⇒ `409`; retención 24 h). Dónde, por el criterio de `§8.1`: **`POST /students` obligatoria** (crea una persona sin unicidad natural: el documento es opcional; condición 4). **`POST /enrollments` no la lleva**: el índice único de `RN-ALUM-21` convierte el reintento en `422 already_enrolled`, que es el resultado correcto (el «no deliberado» de `§8.1`; `INV-011` cita «matrículas» como ejemplo y `ADR-038 §8.1` lo concreta como criterio). **`POST /enrollments/{id}/withdrawal`** y **`POST /group-change-requests`** tampoco: el reintento choca con `409 already_withdrawn` o `422 request_already_pending`. `POST /enrollments/exports` sigue lo que haga la exportación de `Core` |
| `RN-ALUM-28` | `PATCH` cambia `enrolled_on` y asigna un **primer** grupo a una matrícula sin grupo. Cambiar un grupo ya asignado ⇒ `422 alum.validation.group_change_requires_request`. Curso académico, curso de etapa y alumno son inmutables ⇒ `422 alum.validation.immutable` |
| `RN-ALUM-29` | La fecha de matrícula cae dentro del curso académico (bordes incluidos), leída con `AcademicYearSharedLock` en la transacción (precedente `RN-ACAD-57`) ⇒ `422 alum.validation.date_outside_academic_year` |

### 5.3 Cambio de grupo (`OPEN-ALUM-13` = A, `1.15-c`)

| ID | Regla |
|----|-------|
| `RN-ALUM-40` | Solo sobre una matrícula `activa` con grupo; destino distinto, vivo y **del mismo curso de etapa** ⇒ si no, `422 alum.validation.group_not_in_course` |
| `RN-ALUM-41` | Como mucho una solicitud `pendiente` por matrícula ⇒ `422 alum.validation.request_already_pending` |
| `RN-ALUM-42` | Estados `pendiente` → `aprobada` / `rechazada` / `cancelada`. Toda transición registra quién (`decided_by`) y cuándo (`decided_at`); en `cancelada`, quien cancela, que puede ser el solicitante. Una solicitud no `pendiente` no admite ninguna transición ni edición ⇒ `409 alum.conflict.request_closed` |
| `RN-ALUM-43` | Aprobar: en una transacción, `FOR UPDATE` de la matrícula; si sigue activa y en el grupo de origen, se cambia el grupo y se cierra la solicitud. Si cambió ⇒ `409 alum.conflict.enrollment_changed` y nada se escribe |
| `RN-ALUM-44` | El solicitante no puede **aprobar** su propia solicitud ⇒ `403` (sí rechazarla o cancelarla). `CHECK` en el motor solo para `aprobada` (`datos.md §1.3`) |
| `RN-ALUM-45` | Motivo y nota de decisión: texto libre acotado (1-500), **no** se registran en `audit_logs.changes` (`ADR-035`) |
| `RN-ALUM-46` | **Ámbito de las solicitudes**: con `grupo`, las solicitudes cuya matrícula (`enrollment_id`) está en un grupo de `groupIdsFor(u, academic_year_id)`; con `unidad_familiar`, **ninguna** (lista vacía y detalle `404`) [DERIVADA: son un trámite interno del centro; la familia ve el grupo resultante en la matrícula]. Fuera de ámbito ⇒ `404` (`RN-ALUM-64`) |
| `RN-ALUM-47` | **Veto de borrado de grupo** (`1.15-c T2a`, decisión del usuario 2026-11-11 = B; `RN-ACAD-21`): `alum` registra un tercer veto, sobre grupos, que cuenta las solicitudes de cambio de grupo `pendiente` vivas con ese grupo de **origen o destino** (`from_group_id` o `to_group_id`) ⇒ `409 acad.conflict.has_dependents` con `group_change_requests` en `params.dependents` (`acad.dependents.group_change_requests`). Las resueltas (`aprobada`, `rechazada`, `cancelada`) no vetan. No aplica al curso de etapa (sus matrículas ya lo vetan, `RN-ALUM-26`). Es independiente del de matrículas: un grupo con ambas devuelve `enrollments` y `group_change_requests`, por ese orden |

### 5.4 Fotografía (`1.15-c`, `OPEN-ALUM-08` = A)

| ID | Regla |
|----|-------|
| `RN-ALUM-50` | Una foto viva por alumno; subir otra da de baja la anterior en la misma transacción |
| `RN-ALUM-51` | Validación y almacenamiento por `App\Support\Files` (`ADR-068 §5.3`): tipo real JPEG/PNG, tamaño máximo `alum.photo.max_bytes` (5 MB, `config/alum.php`), EXIF eliminado, *bucket* privado, URL firmada de caducidad corta tras comprobar permiso (`ADR-013`) |
| `RN-ALUM-52` | Toda respuesta con URL de foto registra una fila `read` por consulta con `AuditRecorder::recordRead` (precedente `OPEN-068-04` = A) |
| `RN-ALUM-53` | La foto **no** se publica desde este módulo; toda publicación futura consulta `ImageConsentPolicy` (`INV-014`) |

### 5.5 Ámbitos (`OPEN-ALUM-16` = A)

| ID | Regla |
|----|-------|
| `RN-ALUM-60` | `grupo` sobre `matricula` (`1.15-b`): matrículas cuyo `group_id` está en `TeachingMembership::groupIdsFor(u, academic_year_id)` del curso de la matrícula (`RN-ACAD-98`) |
| `RN-ALUM-61` | `grupo` sobre `alumno` (`1.15-b`): alumnos con matrícula activa viva en un grupo de `groupIdsFor(u, Y)`, con `Y` según `AcademicYearContext` (`OPEN-ALUM-17`). En `1.15-a` el permiso `alumno.leer` se declara sin `grupo` y `1.15-b` lo añade a `applicable_scopes` (ampliación compatible) |
| `RN-ALUM-62` | `unidad_familiar` sobre `alumno` (`1.15-a`) y `matricula` (`1.15-b`): alumnos cuya persona está en `FamilyMembership` «alumnos alcanzados» por la persona del sujeto (solo vínculos **activos**, `RN-FAM-UNIT-35`), sin categoría |
| `RN-ALUM-63` | Sin caché entre peticiones (`RN-FAM-UNIT-34`, `ADR-044 §4.7`) |
| `RN-ALUM-64` | Fuera de ámbito ⇒ misma respuesta que inexistente (`404`) en detalle, edición, foto, solicitudes y exportación (`ADR-044 §4.2`) |
| `RN-ALUM-65` | Ningún rol predefinido recibe `todos` sobre `alumno` o `matricula` como sustituto de la asignación docente (`ADR-063 §5.4`, issue #471) |

---

## 6. Casos límite y errores

| Caso | Comportamiento |
|------|----------------|
| Docente sin asignación en el curso | Conjunto vacío: listado vacío, detalle `404` |
| Docente que además es tutor legal de un alumno de otro grupo | Unión de ámbitos (`ADR-044`) |
| Tutor con el vínculo revocado | No alcanza al alumno: `404` |
| Matrícula en un curso en `planificacion` sin grupos | Se admite sin grupo (`OPEN-ALUM-11` = B) |
| Grupo borrado con matrículas vivas | `409` (`RN-ALUM-26`) |
| Grupo borrado con solicitudes de cambio de grupo `pendiente` (origen o destino) | `409` (`RN-ALUM-47`) |
| Alumno borrado entre la lectura y el alta de su matrícula | `FOR SHARE` de la faceta en la transacción; grupo y curso de etapa vivos también con `FOR SHARE` (`lockAliveForShare`, `RN-ALUM-26`); `422 alum.validation.student_not_found` [DERIVADA, lección de `RN-PRL-12`] |
| Dos altas simultáneas de matrícula para el mismo alumno y curso | Una `201`, otra `422 already_enrolled` |
| Curso cerrado con una solicitud pendiente | Aprobar ⇒ `409 academic-year-closed`; la solicitud sigue pendiente |
| Módulo `alum` no contratado | `403 urn:pge:error:module-disabled` (`RMOD-009`) |
| `acad` o `fam_unit` descontratado con `alum` contratado | Imposible por `depends_on` (`RMOD-006`, `OPEN-ALUM-28` = A) |
| Alumno que cumple 18 años | Sin cambio en `1.15` (`OPEN-FAM-UNIT-18`) |
| Issue #60 | Comprobar en implementación que los códigos `alum.*` no salen con prefijo `core.` |

---

## 7. Interacción con otros módulos

Toda interfaz vive en `Domain` (nunca `Domain\Models`); ningún módulo lee tablas de otro (`INV-007`, `AR-01`, `AR-02`).

### 7.1 Lo que consume

| De | Qué | Estado |
|----|-----|--------|
| `curso` | `AcademicYearContext`, `AcademicYearDirectory`, `AcademicYearReadAccess`, `AcademicYearSharedLock` | Existen |
| `acad` | `GroupDirectory` | Escrita, no construida: se construye en `1.15-b` (primer consumidor, `REQ-ACAD §7.2`) |
| `acad` | `TeachingMembership::groupIdsFor()` | Escrita, no construida (`REQ-ACAD §18.7`, `OPEN-ACAD-43`): se construye en `1.15-b`, con el resolutor `grupo`, su primer consumidor. `classPairsFor()` no (`OPEN-ALUM-16` = A, sin `clase`) |
| `acad` | Registro de vetos de borrado (`RN-ACAD-21`) | No existe: lo crea `1.15-b` con la forma de `RN-CURSO-30` |
| `fam_unit` | `FamilyMembership` | Existe (`REQ-FAM-UNIT §7.1`) |
| `fam_unit` | `FamilyUnitStudents::add()` | **Existe** (`FamUnit/Domain/FamilyUnitStudents.php`, `Application/FamilyUnitStudentService.php`); se consume. No comprueba permisos: lo hace `alum` según `OPEN-ALUM-31` |
| `Core` | `PersonRegistrar`, `PersonDirectory` | Existen. Ampliación aprobada (`OPEN-ALUM-19` = A) **ya implementada en `1.15-a`**: `PersonDirectory::birthDatesByIds()` (lectura de `people.birth_date`) y `PersonRegistrar::correctPerson()` (corrección de una persona con o sin usuario, `RN-ALUM-10`) |
| `Core` | `data_exports` con un `kind` nuevo | Ampliación del catálogo de tipos (precedente `1.14-b`) |
| núcleo | `TenantModel`, `Auditable`, `AuditRecorder::recordRead`, `App\Support\Files`, `RequireIdempotencyKey`, `ScopedQuery` | Existen |

### 7.2 Interfaces públicas que expone (`Alum\Domain`)

Se **especifican** aquí; se **construyen con su primer consumidor** (`ADR-048`, precedente `TeachingMembership`). Todas: ids internos, una consulta por llamada, memoizadas como mucho por petición, sin efectos, y **fallan en cerrado** (módulo no contratado o alumno desconocido ⇒ «no es alumno», conjunto vacío).

| Interfaz | Operación | Devuelve | Primer consumidor |
|----------|-----------|----------|-------------------|
| `StudentDirectory` | `studentPersonIdsAmong(list<int> personIds)` | Ids de persona con faceta de alumno **viva** | `prl` en `1.14b-d2` (`OPEN-FAM-UNIT-52`) |
| `StudentEnrollmentDirectory` | `currentEnrollmentsFor(list<int> studentPersonIds, date)` | Por alumno, su matrícula `activa` que cubre la fecha: ids de curso académico, curso de etapa, **etapa** y grupo (o nulo) | `1.14b-d2`, `1.13` |
| `EnrollmentRoster` | `activeEnrollmentsOfGroup(int groupId, date)` | Matrículas activas del grupo en la fecha | `1.13` |
| Registro de vetos de borrado de matrícula | `register(check)`, forma de `RN-CURSO-30` | Para que asistencia y calificaciones impidan anular una matrícula con datos | `1.13` |

La **edad** no la expone `alum`: la fecha de nacimiento es de `Core`.

### 7.3 Salida autónoma: redacción única (C7)

**`1.15` deja especificados los contratos que la salida autónoma necesita (`StudentDirectory` y `StudentEnrollmentDirectory`, §7.2) y construye la lectura de `people.birth_date` en `PersonDirectory`, porque la ficha la consume. `OPEN-FAM-UNIT-52` y la salida autónoma (`OPEN-FAM-UNIT-31`) se cierran en `1.14b-d2`, al construir esos contratos con su primer consumidor, como dicen `REQ-FAM-UNIT §21.13.2` y `REQ-PRL` «Entrega `1.14b-d`». `OPEN-FAM-UNIT-45` no la cierra ni `1.15` ni `1.14b-d2`: exige un ADR que sustituya un punto de `ADR-032`.**

Pendiente para `1.14b-d2` (no se decide aquí): dónde se evalúa edad × etapa (`fam_unit` no puede consumir `alum`, sería un ciclo; `OPEN-ALUM-21`) y cómo se reconoce el primer ciclo de Infantil (`education_levels` no tiene marcador; texto traducible del centro).

### 7.4 Lo que `1.15` deja preparado para `1.13` (asistencia)

`EnrollmentRoster`, `StudentEnrollmentDirectory`, el registro de vetos de borrado de matrícula y la matrícula con `academic_year_id` y `group_id` como eje. `alum` no crea tablas de asistencia.

### 7.5 Lo que `1.15` deja preparado para `1.15b` (`REQ-SEED`)

Factorías `database/factories/Alum/{Student,Enrollment}Factory.php` (con `REQ-SEED-005`) y servicios de dominio reutilizables. `alum` no escribe semillas.

### 7.6 Eventos

Ni emite ni consume (`ADR-048`). Candidatos: `EnrollmentWithdrawn` (`REQ-FIN`, `REQ-TRAN`), `StudentGroupChanged` (`1.13`, `REQ-COM`).

---

## 8. Descriptor del módulo (`ADR-045`, `ADR-034 §5`)

| Campo | Valor |
|-------|-------|
| `code` | `alum` |
| `name_key` | `modules.alum` en los cuatro idiomas (es-ES «Alumnado y matrícula») |
| `phase` | `1` |
| `depends_on` | `['curso', 'acad', 'fam_unit']` (`OPEN-ALUM-28` = A) |
| `essential` | `false` |
| `feature_flags` | Ninguno |

## 9. Comportamiento con el módulo desactivado

- API: `403 urn:pge:error:module-disabled` en toda ruta (`module-enabled:alum` antes de `permission:`, `AR-07b`).
- SPA: sin entradas de menú ni rutas montables.
- Interfaces de §7.2, cuando se construyan: fallan en cerrado. Por eso `OPEN-ALUM-21` recomienda que `prl` declare `alum` en `depends_on`.
- Datos: se conservan (`RMOD-003`).

## 10. Interfaz: *shell* del frontend (`ADR-053`)

Propuesta [DERIVADA en nombres y rutas]. `src/data-table`, shadcn-vue, `docs/design-system.md §22`, `locales/{es,en,de,fr}.json` (`INV-009`), WCAG 2.2 AA, 320 px sin desplazamiento horizontal.

| Nombre | Ruta | `meta.permissions` | Menú | Contenido |
|--------|------|--------------------|------|-----------|
| `alum-students` | `/alumnado` | `['alumno.leer']` | Sí | Listado con filtros curso académico, curso de etapa, grupo, estado de matrícula y búsqueda por nombre; alta en diálogo con `alumno.crear`; `meta.academicYearScoped: true` |
| `alum-student-detail` | `/alumnado/:publicId` | `['alumno.leer']` | No | Ficha: datos personales; tutores y contacto de emergencia (de `fam_unit`, con `vinculo_tutor.leer`); estado de imagen (API de `fam_unit`, `OPEN-ALUM-10`); matrículas; foto (`has_photo` de la ficha; la URL firmada se pide a `GET /students/{id}/photo` solo al mostrarla) con subida, sustitución y baja con `alumno.actualizar` (`OPEN-ALUM-08` = A) |
| `alum-class-list` | `/alumnado/listas` | `['matricula.leer']` | Sí | Lista de clase por grupo; exportar con `matricula.exportar` |
| `alum-group-changes` | `/alumnado/cambios-de-grupo` | `['matricula.leer']` | Sí | Solicitudes; aprobar o rechazar con `matricula.aprobar`; solicitar y cancelar con `matricula.actualizar` |

**Decisión del usuario 2026-11-11** (`1.15-d`, aclara la tabla anterior sin reescribirla):

1. **Solicitud de cambio de grupo**: se inicia desde la ficha (matrícula activa con grupo) y desde una acción por fila de la lista de clase; la pantalla `alum-group-changes` solo lista, aprueba, rechaza y cancela.
2. **Matrícula desde la ficha**: alta, baja (`withdrawal`) y anulación, con `matricula.crear`, `matricula.actualizar` y `matricula.eliminar` respectivamente.
3. **Lista de clase**: curso de etapa y grupo opcionales.
4. **Curso académico de solo lectura** (`RN-ACAD-42`): se puede exportar; quedan ocultas las acciones de solicitar, cancelar, aprobar, rechazar, y también el alta, la baja y la anulación de matrícula.

**Límites conocidos de la UI de `1.15-d`**: (a) no hay pantalla para asignar el primer grupo a una matrícula creada sin grupo (`PATCH /enrollments/{id}` existe en la API y ninguna pantalla lo llama; issue #621); (b) sin curso resuelto (ni `?academic_year=` ni curso activo) no se ofrece ninguna acción ni la exportación, y la lista de clase y la pantalla de cambios de grupo avisan de que falta un curso académico y no consultan (`GET /enrollments` y `GET /group-change-requests` exigen `academic_year`).

Bloques del panel: ninguno (#261).

---

## 11. Criterios de aceptación

Pest en `apps/api/tests/Feature/Alum/` (concurrencia en `tests/Concurrency/`), Vitest y Playwright. Cada test cita su `CA-ALUM-NNN`, el `REQ-ALUM-00N` y el `RN-ALUM-*` (`INV-015`). Base PostgreSQL real.

### 11.1 Alumnado (`1.15-a`)

- **CA-ALUM-001** — *Dado* secretaría con `alumno.crear` y `familia.actualizar` (`OPEN-ALUM-31` = A), *cuando* da de alta un alumno con nombre, fecha de nacimiento, una unidad familiar viva e `Idempotency-Key`, *entonces* `201`, existe una persona nueva en `Core`, una faceta en `students` y una pertenencia en `fam_unit`, con tres registros `created` en `audit_logs` (`RN-ALUM-03`).
- **CA-ALUM-002** — *Dado* el alta con una unidad familiar inexistente o de otro centro, *entonces* `422 alum.validation.family_unit_not_found` y ni persona, ni faceta, ni pertenencia (`RN-ALUM-03`).
- **CA-ALUM-003** — *Dado* una persona con faceta de alumno viva, *cuando* se intenta otra, *entonces* `422 alum.validation.person_already_student` (`RN-ALUM-01`). *Implementación `1.15-a`*: el alta por API con un documento ya existente responde `person_exists` (`RN-ALUM-09`, decisión del 2026-11-09), así que este caso se verifica como `person_exists` y por el índice único parcial (`StudentSchemaTest`).
- **CA-ALUM-004a** — *Dado* un alumno, *cuando* se da de baja, *entonces* `204` y la persona y la pertenencia siguen vivas (`RN-ALUM-05`, parte sin matrículas).
- **CA-ALUM-005** — *Dado* un alumno dado de baja, *cuando* se restaura, *entonces* `200`; *y* si la persona ya tiene otra faceta viva, `409 alum.conflict.cannot_restore` (`RN-ALUM-06`).
- **CA-ALUM-006** — *Dado* una fecha de nacimiento futura o con formato inválido, *entonces* `422 alum.validation.birth_date_invalid` (`RN-ALUM-08`).
- **CA-ALUM-007** — *Dado* `PATCH` del nombre de un alumno **sin** usuario, *entonces* `Core` corrige la persona y audita el cambio; `alum` no guarda el nombre (`RN-ALUM-02`, `-07`).
- **CA-ALUM-008** — *Dado* `PATCH` del nombre y la fecha de nacimiento de un alumno **con** usuario, *entonces* `200`, `Core` corrige la persona y lo audita; *y* un `PATCH` que intenta cambiar correo de acceso, contraseña o estado del usuario ⇒ `422` y nada cambia (`RN-ALUM-10`).
- **CA-ALUM-009** — *Dado* un alta cuyo documento coincide con una persona viva del centro (alumno, tutor o personal), *entonces* `422 alum.validation.person_exists` con cuerpo **idéntico** en los tres casos, sin nombre ni `public_id`, y no se crea nada (`RN-ALUM-09`).
- **CA-ALUM-019** — Idempotencia de `POST /students` (`RN-ALUM-27`): sin `Idempotency-Key` ⇒ `400 urn:pge:error:malformed`; con una clave que no es ULID ⇒ `400`; reintento con la misma clave y el mismo cuerpo ⇒ la respuesta original `201` con `Idempotency-Replayed: true` y una sola persona; misma clave con otro cuerpo ⇒ `409`.
- **CA-ALUM-032** — *(`OPEN-ALUM-31` = A, resuelta el 2026-11-09)* *Dado* un sujeto con `alumno.crear` y sin `familia.actualizar`, *cuando* da de alta un alumno con `family_unit`, *entonces* `403` y no se crea nada; *y* sin `family_unit`, `201`.

### 11.2 Ámbitos

- **CA-ALUM-012** *(`1.15-a`)* — **Listado y detalle con `unidad_familiar`**: *dado* un tutor con vínculo activo con A1 y sin vínculo con A2, *entonces* lista solo A1 y A2 da `404`; *y* tras **revocar** su vínculo con A1, en la petición siguiente A1 también da `404` (`RN-ALUM-62`, `-63`).
- **CA-ALUM-014a** *(`1.15-a`)* — *Dado* la siembra, *entonces* `alumno.*` con `todos` solo en `administrador_centro`, `secretaria`, `direccion` y `administrativo`, y `alumno.leer` con `unidad_familiar` en `tutor_legal`; al cierre de `1.15-a` ningún otro rol tiene `alumno.*` (`docente` y `tutor_grupo` reciben `alumno.leer` con `grupo` en `1.15-b`, `CA-ALUM-014b`) (`RN-ALUM-65`).
- **CA-ALUM-018a** *(`1.15-a`)* — **Aislamiento entre tenants** (`INV-001`): con dos centros equivalentes, ningún *endpoint* de alumnado devuelve, modifica ni cuenta alumnos del otro, ni por `public_id` ajeno (`404`).
- **CA-ALUM-010** *(`1.15-b`)* — **Listado y detalle con `grupo`** (`ADR-044 §4.2`): *dado* un docente con asignación en G1 y ninguna en G2, *entonces* lista solo los matriculados en G1 y el detalle de un alumno de G2 es `404` idéntico al de un inexistente (`RN-ALUM-61`, `-64`).
- **CA-ALUM-011** *(`1.15-b`)* — Lo mismo para un **tutor de grupo** con la tutoría de G1 y sin asignaturas (`RN-ACAD-98`).
- **CA-ALUM-013** *(`1.15-b`)* — *Dado* que se retira la asignación del docente en G1, *cuando* pide de nuevo el listado, *entonces* ya no ve a los alumnos de G1 (`RN-ALUM-63`).
- **CA-ALUM-014b** *(`1.15-b`)* — *Dado* la siembra, *entonces* `matricula.*` con `todos` solo en `administrador_centro`, `secretaria`, `direccion` y `administrativo` (según `permisos.md §4`); `docente` y `tutor_grupo` tienen `alumno.leer` y `matricula.leer` solo con `grupo`; `tutor_legal`, `matricula.leer` con `unidad_familiar` (`RN-ALUM-65`, issue #471).
- **CA-ALUM-015** *(`1.15-b`)* — *Dado* un docente con `alumno.leer` de ámbito `grupo` y sin ninguna asignación, *entonces* listado vacío y detalle `404`.
- **CA-ALUM-016** *(`1.15-b`)* — `grupo` sobre **matrícula**: un docente de G1 que filtra por G2 recibe lista vacía; el detalle de una matrícula de G2 es `404` (`RN-ALUM-60`).
- **CA-ALUM-017** *(`1.15-b`)* — `TeachingMembership::groupIdsFor()` devuelve solo grupos con asignación o tutoría **vivas** del curso pedido, en una consulta (`REQ-ACAD §18.7`).
- **CA-ALUM-018b** *(`1.15-b`)* — Aislamiento entre tenants de matrículas y exportaciones.
- **CA-ALUM-004** *(`1.15-b`)* — *Dado* un alumno con una matrícula activa, *cuando* se da de baja, *entonces* `409 alum.conflict.has_active_enrollments`; con la matrícula en `baja`, `204` (`RN-ALUM-05` completa).

### 11.3 Matrícula (`1.15-b`)

- **CA-ALUM-020** *(implementado en `1.15-b`)* — *Dado* un alumno, un curso `activo`, un curso de etapa y un grupo suyo, *cuando* secretaría matricula, *entonces* `201`; *y* la repetición exacta de la petición responde `422 alum.validation.already_enrolled` sin segunda fila (`RN-ALUM-20`, `-21`, `-27`).
- **CA-ALUM-021** *(implementado en `1.15-b`)* — *Dado* un grupo de otro curso de etapa o de otro curso académico, *entonces* `422 group_not_in_course` (o `course_not_in_academic_year`); *y* una inserción directa incoherente la rechaza el motor (`RN-ALUM-20`).
- **CA-ALUM-022** *(implementado en `1.15-b`)* — *Dado* una matrícula activa del alumno en el curso, *cuando* se intenta otra, *entonces* `422 already_enrolled`; con dos altas simultáneas (dos procesos), una `201` y otra `422` (`RN-ALUM-21`).
- **CA-ALUM-023** *(implementado en `1.15-b`)* — *Dado* una matrícula dada de baja con motivo `traslado`, *cuando* se matricula de nuevo al alumno en el mismo curso, *entonces* `201` y la anterior se conserva (`RN-ALUM-22`).
- **CA-ALUM-024** *(implementado en `1.15-b`)* — *Dado* una baja sin `withdrawn_on`, con fecha anterior a la matrícula o con motivo fuera del vocabulario, *entonces* `422`, y el `CHECK` del motor lo rechaza por SQL directo (`RN-ALUM-22`).
- **CA-ALUM-025** *(implementado en `1.15-b`)* — **Curso cerrado, escritura**: matricular o editar en un curso `cerrado` ⇒ `409 urn:pge:error:academic-year-closed` (`RN-ALUM-23`).
- **CA-ALUM-026** *(implementado en `1.15-b`)* — **Curso cerrado, lectura en listado y detalle** (`RN-CURSO-33`): sin `curso_historico.leer`, listado de matrículas de un curso `cerrado`/`archivado` y detalle por `public_id` ⇒ `404`; con él, los datos. Igual para `GET /students?academic_year=<cerrado>`, y esas matrículas no aparecen en la ficha.
- **CA-ALUM-027** *(implementado en `1.15-b`)* — Borrar en `acad` un grupo o un curso de etapa con matrículas vivas ⇒ `409 acad.conflict.has_dependents` con `enrollments` (`RN-ALUM-26`). *(`1.15-c` T2a)* Un grupo con solicitudes de cambio de grupo `pendiente`, de origen o de destino, ⇒ `409` con `group_change_requests`; resueltas, se borra (`RN-ALUM-47`).
- **CA-ALUM-028** *(implementado en `1.15-b`)* — `PATCH` que cambia el grupo de una matrícula con grupo ⇒ `422 group_change_requires_request`; sobre una matrícula sin grupo, asigna el primero con `200` (`RN-ALUM-28`).
- **CA-ALUM-029** *(implementado en `1.15-b`)* — Fecha de matrícula fuera del curso ⇒ `422 date_outside_academic_year` (`RN-ALUM-29`).
- **CA-ALUM-030** *(implementado en `1.15-b`)* — *Dado* un alumno borrado por otra conexión sin confirmar, *cuando* se matricula, *entonces* espera y, al confirmar, `422 alum.validation.student_not_found` sin fila.
- **CA-ALUM-031** *(implementado en `1.15-b`)* — *Dado* una matrícula anulada, *entonces* deja de aparecer en listas y ficha, y el alumno puede matricularse de nuevo en ese curso (`RN-ALUM-25`).
- **CA-ALUM-033** *(implementado en `1.15-b`)* — *Dado* un curso en `planificacion` y un curso de etapa sin grupos, *cuando* se matricula sin `group`, *entonces* `201` con `group = null`, y la matrícula aparece en el listado por curso de etapa y no en ninguna lista de clase (`OPEN-ALUM-11` = B).
- **CA-ALUM-034** *(implementado en `1.15-b`)* — *Dado* una matrícula en `baja`, *cuando* se envía otra baja (también como reintento de la misma petición), *entonces* `409 alum.conflict.already_withdrawn` y nada cambia (`RN-ALUM-22`, `-27`).
- **CA-ALUM-035** *(implementado en `1.15-b`)* — `PATCH` que cambia alumno, curso académico o curso de etapa ⇒ `422 alum.validation.immutable` (`RN-ALUM-28`).
- **CA-ALUM-036** *(implementado en `1.15-b`)* — Curso de etapa o grupo del cuerpo inexistente, de otro centro o de un curso cerrado ilegible para el sujeto ⇒ `422 alum.validation.parent_not_found`, mismo cuerpo en los tres casos (`RN-ALUM-24`).

### 11.4 Listas y exportación (`1.15-b`)

- **CA-ALUM-040** *(implementado en `1.15-b`)* — *Dado* un grupo con 3 matrículas activas y 1 de baja, *cuando* se lista con `group=<G>&status=activa`, *entonces* 3 filas con el nombre del alumno, sin N+1 (test de recuento de consultas).
- **CA-ALUM-041** *(implementado en `1.15-b` T2c)* — *Dado* `matricula.exportar`, *cuando* se pide la exportación con los filtros del listado, *entonces* `202`, se encola, el CSV tiene el esquema cerrado de `api.md §6` y la solicitud queda auditada como `exported`; *y* con `q` ⇒ `422 alum.validation.export_query_not_allowed` (`ADR-054 §9`).
- **CA-ALUM-042** *(implementado en `1.15-b` T2c)* — *Dado* un usuario sin `matricula.exportar` (por ejemplo, un docente con `matricula.leer` de ámbito `grupo`), *entonces* `403`; *y* la exportación con filtro `group` contiene exactamente las filas del listado con los mismos filtros (paridad, `ADR-054 §8.2`).

### 11.5 Cambio de grupo (`1.15-c`)

- **CA-ALUM-050** — *Dado* una matrícula activa en G1, *cuando* secretaría solicita pasarla a G2 del mismo curso de etapa y dirección aprueba, *entonces* la matrícula queda en G2, la solicitud `aprobada` con quién y cuándo, y ambas escrituras auditadas (`RN-ALUM-43`).
- **CA-ALUM-051** — Segunda solicitud para una matrícula con otra pendiente ⇒ `422 request_already_pending` (`RN-ALUM-41`).
- **CA-ALUM-052** — El solicitante intenta aprobar su propia solicitud ⇒ `403`; *y* una fila `aprobada` con `decided_by = requested_by` la rechaza el motor (`RN-ALUM-44`).
- **CA-ALUM-053** — Matrícula dada de baja tras solicitar; al aprobar ⇒ `409 enrollment_changed` y nada cambia (`RN-ALUM-43`).
- **CA-ALUM-054** — Destino de otro curso de etapa ⇒ `422 group_not_in_course` (`RN-ALUM-40`).
- **CA-ALUM-055** — Motivo y nota nunca aparecen en `audit_logs.changes` (`RN-ALUM-45`).
- **CA-ALUM-037** — *Dado* una solicitud pendiente, *cuando* el propio solicitante la cancela, *entonces* `200`, estado `cancelada`, `decided_by` = el solicitante y `decided_at` rellenado; *y* cuando otro titular de `matricula.aprobar` la rechaza con nota, `rechazada` con su autoría (`RN-ALUM-42`).
- **CA-ALUM-038** — *Dado* una solicitud `aprobada`, `rechazada` o `cancelada`, *cuando* se intenta aprobar, rechazar o cancelar, *entonces* `409 alum.conflict.request_closed` y nada cambia (`RN-ALUM-42`).
- **CA-ALUM-039** — **Ámbito de solicitudes, listado y detalle** (`RN-ALUM-46`): *dado* un docente de G1 con `matricula.leer` de ámbito `grupo`, *cuando* lista solicitudes, *entonces* solo ve las de matrículas de G1, y el detalle de una de G2 es `404`; *y* un tutor legal con ámbito `unidad_familiar` recibe lista vacía y `404` en el detalle de una solicitud de su hijo.
- **CA-ALUM-018c** — Aislamiento entre tenants de solicitudes (y de fotos).

### 11.6 Fotografía (`1.15-c`, `OPEN-ALUM-08` = A)

- **CA-ALUM-056** — Fichero que no es JPEG/PNG por su contenido o que supera el tamaño ⇒ `422` y nada se guarda; una imagen válida queda sin EXIF (`RN-ALUM-51`).
- **CA-ALUM-057** — Subir otra foto da de baja la anterior en la misma transacción (`RN-ALUM-50`).
- **CA-ALUM-058** — Fuera de ámbito, la URL de la foto ⇒ `404`; dentro, URL firmada con la caducidad de la primitiva y una fila `read` en `audit_logs` (`RN-ALUM-52`).

### 11.7 Obligaciones transversales

- **CA-ALUM-060** *(`1.15-b`)* — *(`#382`, `ADR-057`, `CA-057-09`)* `AcademicYearWriteGuardOverheadTest` con 20.000 inserciones en `enrollments`, con y sin disparador, mismo método que `1.10`; cifra en `CHANGELOG.md` junto a la de `1.10` (+182 % a +195 %) y la de `CA-ACAD-023`. No es umbral de aprobado.
- **CA-ALUM-061** *(`1.15-b` y `-c`)* — `AR-13` ve el disparador en `enrollments` y `group_change_requests`, sin acciones referenciales.
- **CA-ALUM-062** *(`1.15-a` para `Student`, `1.15-b` para `Enrollment`, `1.15-c` para `GroupChangeRequest`, `StudentPhoto` y `StudentPhotoService`)* — `AR-01`/`AR-02`: `alum` no importa `Domain\Models` de otros módulos ni `Core` fuera de `Core\Domain`; `AR-08`: ninguna regla compara roles; `AR-10`: cada modelo solo por su servicio sancionado.
- **CA-ALUM-063** *(`1.15-a`)* — *Dado* `alum` descontratado, *entonces* toda ruta de `alum` responde `403 module-disabled`.
- **CA-ALUM-064** *(cada entrega)* — Paridad de claves en los cuatro idiomas (`AR-12`, `TranslationParityTest`, `lint:i18n`).

### 11.8 Interfaz y E2E (`1.15-d`)

- **CA-ALUM-070** — Vitest: el listado lleva los filtros a la URL sin `q`; la ficha oculta las secciones de `fam_unit` sin sus permisos; sin acciones de escritura con un curso de solo lectura (`RN-ACAD-42`); la subida de foto muestra el error de tipo o tamaño.
- **CA-ALUM-071** — Accesibilidad: etiquetas, errores asociados, foco en diálogos, 320 px sin desplazamiento horizontal (WCAG 2.2 AA). *Alcance de la comprobación*: el foco en diálogos se comprueba en navegador real (E2E) y en Vitest; los 320 px, en navegador real solo en `/alumnado`, `/alumnado/listas` y `/alumnado/cambios-de-grupo`; la ficha y los diálogos de matrícula **no** se comprueban a 320 px en E2E, y no hay comprobación automática tipo axe.
- **CA-ALUM-072** — **E2E contra la API real** (`CLAUDE.md §3`): secretaría da de alta un alumno en una unidad familiar, lo matricula, ve la lista de clase y exporta; un docente con asignación en ese grupo ve al alumno y otro sin asignación no. Lo ejecuta `verificador`. Contra el almacén S3 real de desarrollo (Garage, `ADR-076`), el E2E descarga por URL firmada el CSV de la exportación (`alum-enrollments-real.spec.ts`, `alum-screens-real.spec.ts`, `alum-group-change-real.spec.ts`) y la fotografía del alumno (`alum-photo-real.spec.ts`: bytes reprocesados, firma alterada `403`, sustitución y borrado); TLS, versionado, *Object Lock* y cifrado en servidor siguen siendo de *staging* (`ADR-076 §2.3`).
  **Estado (`1.15-d` T3, probado contra Garage en `S-1b`, `ADR-076`)**: implementado en `apps/web/e2e/alum-screens-real.spec.ts` (8 tests de navegador: alta y matrícula, lista de clase con exportación, docente con y sin asignación, foco del diálogo de alta y 320 px en tres pantallas) y `alum-group-change-real.spec.ts` (6: solicitar desde la ficha y desde la lista, aprobar por otra persona, autoaprobación rechazada, rechazo con nota obligatoria, curso cerrado), más 16 HTTP de `alum-students-real` (3), `alum-enrollments-real` (8) y `alum-photo-real` (5); en total, 14 de navegador y 16 HTTP. **Probado contra Garage**: la descarga del CSV de la exportación por URL firmada (`alum-enrollments-real`, `alum-screens-real`, `alum-group-change-real`) y la fotografía del alumno (`alum-photo-real`: bytes reprocesados, firma alterada rechazada, sustitución y borrado). **No probado**: TLS, versionado, *Object Lock* y cifrado en servidor, que son de *staging* (`ADR-076 §2.3`); la caducidad de la URL la cubre el test de conformidad, no el E2E; la foto no se prueba en navegador. Cómo se ejecuta: `operacion.md §9`.

---

## 12. Preguntas abiertas de otros módulos que esta especificación toca

| ID | Qué hace `1.15` |
|----|-----------------|
| `OPEN-FAM-UNIT-01` | `OPEN-ALUM-20` |
| `OPEN-FAM-UNIT-44`, `OPEN-068-06` | `StudentDirectory` (contrato) permitirá a `1.14b-d2` acotar la búsqueda de la puerta |
| `OPEN-FAM-UNIT-52`, `-31` | Contratos especificados; se cierran en `1.14b-d2` (§7.3) |
| `OPEN-FAM-UNIT-45` | No se cierra (§7.3, `OPEN-ALUM-22`) |
| `OPEN-ACAD-43` | `TeachingMembership::groupIdsFor()` y `GroupDirectory` se construyen en `1.15-b` |
| `OPEN-13` | Sigue abierta (C6) |

## 13. Condiciones previas

**ADR de `1.15-b`**: `ADR-071` (`ACEPTADA`, 2026-11-09) formaliza `OPEN-ALUM-11` = B en `§16.1`/`§16.2` (C4); aplicado en la versión 3.2.25. Un «antiguo alumno» no es un estado: se deriva (`datos.md §6`, `OPEN-071-02` = A).

**Antes de datos reales**: las de `REQ-FAM-UNIT §13`; catálogo de base legal (`OPEN-13`); plazo de conservación en `1.23b` (`datos.md §6`); DPO sobre la fotografía (`-08` = A, 2026-11-11: la fotografía se implementa en `1.15-c`, pero no se usa con datos reales hasta el visto bueno del DPO).

---

## 14. Preguntas abiertas

### 14.1 Resueltas por el usuario (2026-11-06; `OPEN-ALUM-08`, 2026-11-11)

| ID | Pregunta | Decisión |
|----|----------|----------|
| `OPEN-ALUM-01` | Alcance de `1.15` | **A** · Núcleo: alumnado, ficha compuesta, matrícula, listas, exportación, cambio de grupo y foto (esta, con `-08` = A); lo demás diferido |
| `OPEN-ALUM-02` | Historial médico y alergias | **A** · Diferido a `REQ-SALUD`, tras ADR de cifrado a nivel de campo |
| `OPEN-ALUM-03` | Preinscripción, admisión, lista de espera | **A** · Diferido a `REQ-OFE`; `1.15` solo matrícula directa |
| `OPEN-ALUM-08` | Fotografía del alumno | **A** · En `1.15-c` con `App\Support\Files` y auditoría de lectura (decisión del usuario, 2026-11-11). Condición previa a datos reales: DPO |
| `OPEN-ALUM-11` | Grupo en la matrícula | **B** · Curso de etapa obligatorio, grupo opcional (requiere ADR, C4) |
| `OPEN-ALUM-13` | Cambio de grupo | **A** · Solicitud + aprobación (`matricula.aprobar`), sin autoaprobación; aprueban por defecto `direccion` y `administrador_centro` |
| `OPEN-ALUM-16` | Ámbitos | **A** · `['todos','grupo','unidad_familiar']`; sin `clase` ni `propios` |
| `OPEN-ALUM-19` | Ampliación de `Core\Domain` | **A** · Aditiva: lectura de `birth_date` y corrección (`correctPerson`) de una persona con o sin usuario (`RN-ALUM-10`); documento coincidente ⇒ `422 person_exists` sin revelar quién (`RN-ALUM-09`) |
| `OPEN-ALUM-28` | `depends_on` | **A** · `['curso','acad','fam_unit']` |

### 14.2 Abiertas (con recomendación aplicable)

| ID | Pregunta | Recomendación |
|----|----------|---------------|
| `OPEN-ALUM-04` | Contrato, firma y pago | Diferir a `REQ-DOC`/`ADR-012`/`REQ-FIN` |
| `OPEN-ALUM-05` | Documentación adjunta | Diferir a `REQ-DOC`; sin antivirus (#45) no se aceptan documentos de identidad de menores |
| `OPEN-ALUM-06` | Becas y ayudas | Diferir a `REQ-BEC` |
| `OPEN-ALUM-07` | Historial académico | La ficha muestra las matrículas del centro; calificaciones e incidencias por interfaz de `CALIF`/`CONV`; centros anteriores, tras `H0` (`ADR-018`), sin inventar campos |
| `OPEN-ALUM-09` | Contactos de emergencia | Leer de `fam_unit` (`is_emergency_contact`) y teléfonos de `Core` |
| `OPEN-ALUM-10` | Autorizaciones legales en la ficha | La SPA llama a `GET /image-consents` de `fam_unit`; las demás, `REQ-AUT` |
| `OPEN-ALUM-12` | Estados y motivos de baja | `activa`/`baja`; `traslado`, `baja_voluntaria`, `otro` [DERIVADA] |
| `OPEN-ALUM-14` | Asignación automática | Diferir |
| `OPEN-ALUM-15` | «Listados oficiales» | CSV técnico (`ADR-055`); oficiales en Raíces; PDF con `1.17` |
| `OPEN-ALUM-17` | Curso del ámbito `grupo` sobre `alumno` | El de `AcademicYearContext` |
| `OPEN-ALUM-18` | Siembra | `permisos.md §4`; comando `alum:grant-permissions` |
| `OPEN-ALUM-20` | Identificador del alumno en la API | `public_id` de `students` y `person.public_id`; `fam_unit`/`prl` añaden `student` cuando lo necesiten |
| `OPEN-ALUM-21` | Dónde se evalúa edad × etapa | Lo decide `1.14b-d2`; recomiendo puerto en `FamUnit\Domain` implementado por `alum`, y `prl` con `alum` en `depends_on` |
| `OPEN-ALUM-22` | `OPEN-FAM-UNIT-45` | ADR de `architect` antes de especificar `TRAN` |
| `OPEN-ALUM-23` | Unidad familiar en el alta | Opcional; en la misma transacción si llega |
| `OPEN-ALUM-24` | Personas registradas como alumnos antes de `1.15` | Sin regularización (datos sintéticos) |
| `OPEN-ALUM-25` | Número de expediente o NIA | Sin columna mientras no se pida |
| `OPEN-ALUM-26` | Importación masiva | En `1.24`; la medición, con inserción masiva en test |
| `OPEN-ALUM-27` | Auditoría de lectura de la ficha | Solo con URL de foto |
| `OPEN-ALUM-29` | `INV-008` | Cobertura parcial; `OPEN-13` |
| `OPEN-ALUM-30` | Partición | La de §15 |
| **`OPEN-ALUM-31`** | **Alta con `family_unit`**: `FamilyUnitStudents::add()` no comprueba permisos; el alta de `alum` escribiría en `fam_unit` con solo `alumno.crear` | **A** · Exigir además `familia.actualizar` de `fam_unit` (el permiso de `POST /family-units/{id}/students`; solo admite `todos`, así que no hay comprobación de ámbito adicional) y responder `403` sin escribir si falta. **B** · Basta `alumno.crear`. **Recomiendo A**: con B, `alumno.crear` se convierte en una puerta trasera a una escritura que `fam_unit` protege con su propio permiso; con la siembra recomendada `secretaria` tiene ambos, así que no cambia el uso normal. Condiciona `RN-ALUM-04` y `CA-ALUM-032` | **Resuelta A por el usuario el 2026-11-09** (implementada en `1.15-a`, `CA-ALUM-032`).

---

## 15. Dimensionado (`CLAUDE.md §2` «Ahorro de cuota», puntos 1 y 7)

**Calibración** con `docs/metricas-agentes.md`: los tramos de `implementer` medidos en modo tramos (`1.14b-c1`, `1.14b-c2`) gastaron 24-50 llamadas cada uno (7 observaciones, orientativas); el agente único sin tramos, 197-209. Las cifras de abajo son **estimaciones** sobre esa base, más altas en los tramos con migraciones y servicios transaccionales, que no tienen precedente medido. Cada entrega ≤ 3 tramos. La ejecución final del E2E real la lanza `verificador`, no es tramo.

| Entrega | Tramo | Costura | Contenido | CA | Estimación |
|---------|-------|---------|-----------|----|-----------|
| **`1.15-a`** · alumnado y `unidad_familiar` (sin matrículas) | T1 | Datos y permisos | `make:module Alum --entity=Student`, migración `students`, modelo y *morph map*; catálogo de `alumno` (`alumno.leer` con `['todos','unidad_familiar']`), siembra de `alumno.*` y comando; mapa de `AR-10` para `Student`; ampliación de `Core\Domain` (lectura de `birth_date`, corrección con usuario) | `CA-ALUM-014a`, `-062` (`Student`), `-063`, `-064` | ~45-65 |
| | T2 | Servicios y *endpoints* | `StudentService`, alta transaccional con `FamilyUnitStudents` (existente) y la comprobación de `OPEN-ALUM-31`, idempotencia de `POST /students`, ficha sin matrículas, resolutor `unidad_familiar` de `alumno`, *endpoints* de `api.md §1` sin los filtros de matrícula, OpenAPI, `lang` | `CA-ALUM-001` a `-003`, `-004a`, `-005` a `-009`, `-012`, `-018a`, `-019`, `-032` | ~50-70 |
| **`1.15-b`** · matrícula, `grupo`, listas y exportación | T1 | Datos y permisos | Registro de vetos y `GroupDirectory` en `acad`; `TeachingMembership::groupIdsFor()`; migración `enrollments`; catálogo de `matricula`; `grupo` añadido a `alumno.leer`; siembra de `matricula.*` y de los `grupo`; `kind` de `data_exports`; medición `#382` | `CA-ALUM-014b`, `-017`, `-021` (motor), `-024` (motor), `-027`, `-060`, `-061`, `-062` (`Enrollment`) | ~50-70 |
| | T2 | Servicios y *endpoints* | `EnrollmentService` (bloqueo, fechas), baja, anulación, resolutores `grupo` de `alumno` y `matricula` y `unidad_familiar` de `matricula`, `RN-ALUM-05` completa, filtros de matrícula y `enrollments[]` en `GET /students`, listas, exportación en cola, OpenAPI | `CA-ALUM-004`, `-010`, `-011`, `-013`, `-015`, `-016`, `-018b`, `-020` a `-031`, `-033` a `-036`, `-040` a `-042` | ~60-80 |
| **`1.15-c`** · cambio de grupo y foto | T1 | Datos | Migraciones `group_change_requests` y `student_photos` | `CA-ALUM-052` (motor), `-061`, `-062` (`GroupChangeRequest`) | ~25-40 |
| | T2 | Servicios y *endpoints* | Solicitud, aprobación, rechazo, cancelación, resolutor `grupo` de solicitudes; foto con `App\Support\Files` y `recordRead` | `CA-ALUM-018c`, `-037` a `-039`, `-050` a `-058` | ~45-65 |
| **`1.15-d`** · pantallas y E2E | T1 | UI alumnado | Módulo web (*shell*, `api`, `types`, `locales`), `/alumnado`, ficha, subida de foto | `CA-ALUM-070`, `-071` (parte) | ~40-55 |
| | T2 | UI matrícula | Matrículas en la ficha, lista de clase con exportación, solicitudes de cambio de grupo | `CA-ALUM-070`, `-071` | ~35-50 |
| | T3 | E2E (escribir y depurar *specs*) | `apps/web/e2e/alum-*.spec.ts` contra la API real | `CA-ALUM-072` | ~25-40 |

**Estado de `1.15-d`**: T1, T2 y T3 implementados. **Cerrado en `S-1b` de `ADR-076`** (antes aplazado, no era alcance recortado): descarga del CSV de la exportación desde S3 y E2E de la fotografía, probados contra Garage (`operacion.md §9`).

**Verificación de los CA de T1 que dependen del T2 de su entrega.** `CA-ALUM-062` (`AR-10`), `-063` (`module-disabled` de todas las rutas) y `-014b` (siembra de `grupo`, que exige el resolutor) se escriben con el T1 pero solo pueden ponerse en verde al cerrar el T2 de la misma entrega; igual la siembra `unidad_familiar` de `tutor_legal` en `1.15-a`. El `verificador` los da por válidos con la suite del cierre de la entrega, no con la del T1.

Costuras de reserva: en `1.15-a` T1, «módulo y `students`» / «ampliación de `Core`»; en `1.15-b` T2, «matrícula» / «listas y exportación».

Orden obligatorio: `1.15-a` → `-b` → `-c` → `-d`. `1.15-b` no empieza sin el ADR de C4 y no crea la FK a `groups` sin el registro de vetos (`RN-ACAD-21`).
