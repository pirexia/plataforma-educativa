# REQ-ALUM · API

> **Estado**: **APROBADA** (2026-11-06) junto con `funcional.md` (ver su cabecera); lo marcado [DERIVADA] o dependiente de un `OPEN-ALUM` no bloqueante sigue como propuesta. Nombres de ruta y campos [DERIVADA] salvo cita.

Prefijo: `/api/v1`. Todo se ajusta a `ADR-038`: envoltura (`§3`), paginación (`§4`), filtros y orden (`§5`), errores RFC 9457 con `type` URN (`§6`), compatibilidad (`§7`). Toda ruta lleva `RequireAuthenticatedUser`, `module-enabled:alum` **antes** de `permission:` (`AR-07a`, `AR-07b`) y pasa por el muro de sesión (`ADR-067`). Fuera de ámbito o inexistente: **`404`** idéntico (`RN-ALUM-64`). Validación siempre en servidor (`INV-010`).

Errores comunes: `401`; `403` sin permiso; `403 urn:pge:error:module-disabled`; `404`; `409 urn:pge:error:academic-year-closed`; `422` con `errors[]` y códigos `alum.validation.*`; `429` donde se diga.

**Idempotencia** (`ADR-038 §8`, `RN-ALUM-27`): mecanismo existente, *middleware* `RequireIdempotencyKey` y tabla `idempotency_keys`, con el contrato de `§8.2` sin cambios (ULID del cliente; formato inválido o ausencia donde es obligatoria ⇒ `400 urn:pge:error:malformed`; reintento con mismo cuerpo ⇒ respuesta original con `Idempotency-Replayed: true`; cuerpo distinto o primera aún en curso ⇒ `409`; retención 24 h). Obligatoria **solo** en `POST /students` (`§8.1` condición 4). Las demás escrituras están protegidas por unicidad natural o por su transición de estado y no llevan la cabecera (`§8.1`, «no» deliberado).

**Entregas**: `1.15-a` sirve `§1` **sin** los filtros `course`, `group`, `enrollment_status`, sin la matrícula vigente en el listado y sin `enrollments[]` en la ficha, y con `alumno.leer` de ámbitos `todos` y `unidad_familiar`; `1.15-b` añade todo eso y el ámbito `grupo` (`funcional.md §1.2`); `1.15-c` añade las solicitudes de cambio de grupo (`§3`) y la fotografía del alumno (`§4`, con `has_photo` en la ficha).

---

## 1. Alumnado (`1.15-a`)

**Implementado en `1.15-a`** (rama `feature/REQ-ALUM-1-15a-alumnado-ambitos`): los seis *endpoints* de esta sección, documentados en `apps/api/openapi/paths/alum.yaml`. Rutas con parámetro `{publicId}` en el código; en este documento `{public_id}`.

### `GET /students`
- **Permiso**: `alumno.leer` · ámbito `todos`, `unidad_familiar` en `1.15-a`; `grupo` desde `1.15-b`, con su resolutor (`funcional.md §1.2`)
- **Parámetros**: `academic_year` (**desde `1.15-b`**: sin matrículas no tiene objeto en `1.15-a`; `public_id`; por defecto el del `AcademicYearContext`; filtra a alumnos con matrícula en ese curso cuando se combina con los siguientes), `course`, `group`, `enrollment_status` (`activa`, `baja`) (los tres, desde `1.15-b`), `q` (nombre, por `PersonDirectory::searchByNameAmong`; mínimo 2 caracteres; busca solo entre los alumnos del ámbito del sujeto), `include_deleted` (exige `alumno.eliminar`; sin él, `403`), `sort` (`name`, defecto, o `created_at`; prefijo `-` para descendente; desempate por `id`), `page` y `per_page` (defecto 25, máximo 100).
- **Respuesta 200** (`1.15-a`): lista de `Student` resumido: `public_id`, `person.public_id`, `display_name` (nombre visible), `birth_date` (`AAAA-MM-DD` o `null`), `created_at`, `updated_at` y, solo en una baja listada con `include_deleted`, `deleted_at`. La matrícula vigente en el curso pedido con curso de etapa y grupo llega con `1.15-b`. `enrollment` se acota por el ámbito de `matricula.leer`: sin ese permiso sale `null`; también es `null` sin filtro de curso o año, o si el alumno no tiene matrícula.
- **Errores**: (desde `1.15-b`, como `academic_year`) `404` si `academic_year` es de un curso cerrado y no hay `curso_historico.leer` (`RN-ALUM-23`); `422` filtros inválidos; `403 alum.validation.filter_forbidden` si llega `include_deleted` sin `alumno.eliminar` (`RN-ALUM-05`). **Los filtros `course`, `group` y `enrollment_status` exigen además `matricula.leer`** (revelan matrículas): sin él, `403 alum.validation.filter_forbidden`; con él, solo cuentan las matrículas que su ámbito alcanza (`grupo`, `unidad_familiar`), igual que `GET /enrollments` (revisión de seguridad M1 de `1.15-b`). `academic_year` solo no filtra y no exige `matricula.leer`.

### `POST /students`
- **Permiso**: `alumno.crear` · `todos`
- **Cuerpo**: `given_name`, `family_name_1` y `birth_date` (obligatorios); `family_name_2`, `document_type`/`document_number` (opcionales, catálogo de `Core` `dni`/`nie`/`pasaporte`, par completo), `locale` (opcional, entre los idiomas activos del centro) y `family_unit` (opcional, `public_id`). Los nombres son los de la ampliación de `Core` (`PersonDataCorrection`, `OPEN-ALUM-19`; `family_name` se divide en `family_name_1` y `family_name_2`). Textos de 1 a 255 caracteres sin caracteres de control.
- **Respuesta 201**: `Student` completo (la ficha de abajo) y cabecera `Location`.
- **Errores**: `400` (idempotencia); `403` si llega `family_unit` sin `familia.actualizar` (`OPEN-ALUM-31` = A, no se escribe nada); `409` (idempotencia); `422 alum.validation.required`, `text_invalid`, `birth_date_invalid`, `family_unit_not_found`, `person_exists` (cuerpo idéntico sea quien sea la persona, `RN-ALUM-09`), errores de `Core` del documento y del idioma (`core.validation.*`). El documento se compara **antes** de registrar la persona (`registerOrReuse` reutiliza en silencio), de modo que `person_already_student` no se alcanza desde el alta (la guarda queda por el índice único, `RN-ALUM-01`). Los errores de `fam_unit` al añadir la pertenencia se traducen a `family_unit_not_found` y deshacen todo.
- **Fecha de nacimiento e idioma** (decisión del 2026-11-09): `PersonRegistration` no los lleva; el alta los escribe con `PersonRegistrar::correctPerson()` en la misma transacción, lo que deja un `updated` más en la auditoría de la persona.
- **Idempotencia**: **obligatoria** (`RN-ALUM-27`): crea una persona sin unicidad natural (el documento es opcional).

### `GET /students/{public_id}`
- **Permiso**: `alumno.leer` · `todos`, `unidad_familiar` en `1.15-a` (`grupo` desde `1.15-b`)
- **Respuesta 200**: ficha. En `1.15-a`: `public_id`, `person.public_id`, `display_name`, `given_name`, `family_name_1`, `family_name_2`, `birth_date`, `locale`, `document_type`, `document_number`, `created_at`, `updated_at` (y `deleted_at` en una baja). **`document_number` va enmascarado** (solo los últimos cuatro caracteres a la vista, `•••••1234`; un número de cuatro o menos, entero oculto): la API de alumnado nunca devuelve el documento completo (decisión del 2026-11-09). Los datos de la persona se leen de `Core` (`PersonDirectory`); `alum` no los guarda (`RN-ALUM-02`). Desde `1.15-b`, `enrollments[]` (las de cursos ilegibles se omiten, `RN-ALUM-23`) se acota por el ámbito de `matricula.leer`: sin ese permiso sale `[]`; `emergency_contact` y `guardians[]` **solo** si el sujeto tiene `vinculo_tutor.leer` con un ámbito que alcance al alumno (se leen de `FamilyMembership`; si no, el campo no aparece) [DERIVADA: no ampliar por la puerta de `alum` lo que `fam_unit` protege]; `has_photo` (`1.15-c`, `OPEN-ALUM-08`, decisión del usuario 2026-11-11): **solo el booleano**; la URL firmada **no** va en la ficha, solo en `GET /students/{public_id}/photo` (§4).
- **Errores**: `404` si no existe, es de otro centro, está dado de baja o el sujeto no lo alcanza (idéntico, `RN-ALUM-64`).

### `PATCH /students/{public_id}`
- **Permiso**: `alumno.actualizar` · `todos`
- **Cuerpo**: `given_name`, `family_name_1`, `family_name_2`, `document_type`/`document_number`, `birth_date` y `locale`; al menos uno, solo cuentan las claves presentes (`null` borra `family_name_2`, `document_type` y `document_number`; el par de documento resultante debe seguir completo). **No** admite `family_unit` (la pertenencia se gestiona en `fam_unit`, `RN-ALUM-04`). Se reenvía a `Core` por `PersonRegistrar::correctPerson()` **con o sin usuario** (`OPEN-ALUM-19` = A; `correctUnaccounted` no admite fecha de nacimiento ni documento). Nunca correo de acceso, credenciales ni estado del usuario.
- **Respuesta 200**: la ficha.
- **Errores**: `404` (antes que cualquier `422`); `422` como en `POST`, con los errores de documento de `Core` bajo el mismo campo plano que el alta (`document_number`, `document_type`; no `person.document_number`); `422 alum.validation.field_not_editable` por cada clave que no sea un dato personal editable (correo, contraseña, estado del usuario, `family_unit`...; `RN-ALUM-10`) y nada cambia; `422 alum.validation.no_changes` con un cuerpo sin datos.

### `DELETE /students/{public_id}` · `POST /students/{public_id}/restore`
- **Permiso**: `alumno.eliminar` · `todos`
- **Errores**: `409 alum.conflict.has_active_enrollments` (`RN-ALUM-05`, desde `1.15-b`; en `1.15-a` la baja siempre cabe); `409 alum.conflict.cannot_restore` (`RN-ALUM-06`: la persona ya no existe o tiene otra faceta de alumno viva); `404` si el alumno no existe (o, en la restauración, no está dado de baja).
- **Respuesta**: `204` / `200` (la ficha). La baja no borra la persona ni su pertenencia a la unidad.

## 2. Matrícula (`1.15-b`)

### `GET /enrollments`
- **Permiso**: `matricula.leer` · `todos`, `grupo`, `unidad_familiar`
- **Parámetros**: `academic_year` (obligatorio [DERIVADA: toda lectura por curso invoca `AcademicYearReadAccess`]), `course`, `group` (lista de clase), `student`, `status`, `sort` (`name`, `enrolled_on`), paginación.
- **Respuesta 200**: `Enrollment` con alumno (nombre visible y `public_id`), curso de etapa y grupo (resumen de `GroupDirectory`), estado y fechas.

### `POST /enrollments`
- **Permiso**: `matricula.crear` · `todos`
- **Cuerpo**: `student`, `academic_year`, `course`, `group` (opcional), `enrolled_on`.
- **Respuesta 201**.
- **Errores**: `422 alum.validation.student_not_found`, `parent_not_found`, `academic_year_not_found`, `course_not_in_academic_year`, `group_not_in_course`, `already_enrolled`, `date_outside_academic_year`; `409 academic-year-closed`.
- **Idempotencia**: no lleva `Idempotency-Key`: el índice único de `RN-ALUM-21` convierte el reintento en `422 already_enrolled` (`ADR-038 §8.1`, `RN-ALUM-27`).

### `GET /enrollments/{public_id}`
- **Permiso**: `matricula.leer` · `todos`, `grupo`, `unidad_familiar`

### `PATCH /enrollments/{public_id}`
- **Permiso**: `matricula.actualizar` · `todos`
- **Cuerpo**: `enrolled_on`; `group` solo si la matrícula no tiene grupo (`RN-ALUM-28`).
- **Errores**: `422 group_change_requires_request`, `immutable`; `409 academic-year-closed`.

### `POST /enrollments/{public_id}/withdrawal`
- **Permiso**: `matricula.actualizar` · `todos`
- **Cuerpo**: `withdrawn_on`, `withdrawal_reason`.
- **Errores**: `409 alum.conflict.already_withdrawn`; `422`.
- **Idempotencia**: no lleva `Idempotency-Key`: el reintento encuentra la matrícula ya en `baja` y responde `409 already_withdrawn` sin cambiar nada (`RN-ALUM-27`).

### `DELETE /enrollments/{public_id}`
- **Permiso**: `matricula.eliminar` · `todos` (anulación, `RN-ALUM-25`)
- **Errores**: `409 alum.conflict.has_dependents` cuando un consumidor registre veto (ninguno en `1.15-b`). Las solicitudes de cambio de grupo no impiden anular una matrícula; ver `RN-ALUM-47` para el veto sobre grupos.

## 3. Cambio de grupo (`1.15-c`, `OPEN-ALUM-13` = A)

Ámbitos de lectura (`RN-ALUM-46`): con `grupo`, solicitudes cuya matrícula está en un grupo del sujeto; con `unidad_familiar`, ninguna (lista vacía, detalle `404`). Sin `Idempotency-Key`: el único de solicitud pendiente y las transiciones de estado protegen el reintento.

| Verbo y ruta | Permiso · ámbito | Notas |
|--------------|------------------|-------|
| `GET /group-change-requests` | `matricula.leer` · `todos`, `grupo` (`unidad_familiar` ⇒ lista vacía) | Filtros `academic_year` (obligatorio), `status`, `group` |
| `POST /group-change-requests` | `matricula.actualizar` · `todos` | `enrollment`, `to_group`, `reason`. `422 request_already_pending`, `group_not_in_course` |
| `GET /group-change-requests/{public_id}` | `matricula.leer` · `todos`, `grupo` | Fuera de ámbito o con `unidad_familiar` ⇒ `404` |
| `POST /group-change-requests/{public_id}/approval` | `matricula.aprobar` · `todos` | `decision_note` opcional. `403` si es el solicitante (`RN-ALUM-44`); `409 request_closed`, `enrollment_changed`, `academic-year-closed` |
| `POST /group-change-requests/{public_id}/rejection` | `matricula.aprobar` · `todos` | `decision_note` obligatoria [DERIVADA]; el solicitante puede rechazar la suya. `409 request_closed` |
| `POST /group-change-requests/{public_id}/cancellation` | `matricula.actualizar` · `todos` | Solo `pendiente`; registra quién cancela en `decided_by` (`RN-ALUM-42`). `409 request_closed` |

**Implementación (`1.15-c` T2a)** [DERIVADA donde se indica]:
- **Cuerpos**: `POST /group-change-requests` → `enrollment` y `to_group` (`public_id`) y `reason` (1-500, obligatorio); `approval`, `rejection` y `cancellation` → `decision_note` (1-500; **obligatoria** solo en `rejection`, opcional en las otras dos). Los textos se recortan, sin caracteres de control salvo salto de línea y tabulador (`INV-010`).
- **Recurso** [DERIVADA: la especificación no fijaba su forma]: `public_id`, `enrollment {public_id, student {public_id, display_name}}`, `academic_year {public_id, code}`, `from_group` y `to_group {public_id, code, name}`, `reason`, `status`, `requested_by` y `decided_by` (`{public_id, display_name}` o `null`), `decided_at`, `decision_note`, `created_at`, `updated_at`. Listado paginado por página, la más reciente primero (desempate por `id`). `201` con `Location`.
- **Errores `422`** (`alum.validation.*`): `request_already_pending` (en `enrollment`), `group_not_in_course` (en `to_group`; también destino igual al origen), `parent_not_found` (grupo inexistente o ilegible), `enrollment_not_active`, `enrollment_without_group`, `reason_invalid`, `decision_note_required`, `decision_note_invalid`, `required`. Matrícula o solicitud inexistente, de otro centro, ilegible o fuera de ámbito: `404`.
- **Errores `409`** (`alum.conflict.*`): `request_closed` (en `request`), `enrollment_changed` (en `enrollment`; aprobar con la matrícula en baja, anulada o ya no en el grupo de origen); y `urn:pge:error:academic-year-closed` (curso cerrado; `404` si el actor no puede leerlo, #598). **`403 alum.forbidden.self_approval`**: el solicitante intenta aprobar la suya.
- **Veto de grupos** (`RN-ALUM-47`): `DELETE /groups/{id}` en `acad` ⇒ `409 acad.conflict.has_dependents` con `group_change_requests` si hay solicitudes `pendiente` de origen o destino.

## 4. Fotografía (`1.15-c`, `OPEN-ALUM-08` = A)

| Verbo y ruta | Permiso · ámbito | Notas |
|--------------|------------------|-------|
| `PUT /students/{public_id}/photo` | `alumno.actualizar` · `todos` | `multipart/form-data`, campo `file`; sustituye (`RN-ALUM-50`); `200 {"has_photo": true}`; `422` tipo o tamaño |
| `DELETE /students/{public_id}/photo` | `alumno.actualizar` · `todos` | `204`; idempotente (sin foto también `204`) |
| `GET /students/{public_id}/photo` | `alumno.leer` · `todos`, `grupo`, `unidad_familiar` | `200 {"url", "expires_at"}` (URL firmada de 5 minutos, `Cache-Control: no-store`), nunca el binario por la API; registra `read` (`RN-ALUM-52`) |

- **Errores `422`** (`alum.photo.*`, en `errors.file`; **todos** son `422`, no `413` ni `415` como en `fam_unit`: `CA-ALUM-056`): `file_required` (sin fichero), `type_unsupported` (tipo real distinto de JPEG o PNG: SVG, HEIC, WebP...), `invalid_image` (el contenido no coincide con la extensión, no se puede leer o supera los píxeles de entrada o las dimensiones) y `too_large` (supera `alum.photo.max_bytes`, 5 MB). Alumno inexistente, de otro centro o dado de baja: `404` antes que cualquier `422`. Con el almacenamiento caído, `PUT` es `503` con `Retry-After` y no escribe nada.
- **`GET`**: `404` idéntico para alumno fuera de ámbito, inexistente, de otro centro, dado de baja o **sin foto** (`RN-ALUM-64`), sin fila `read`. Límite de **300 lecturas por centro y usuario y hora** (`alum.photo.read_rate_limit`): superado, `429` con `Retry-After` y `alum.errors.photo_read_rate_limited`. La fila `read` (`alum_student_photo`, contexto `purpose`, `entries` y `student` con el ULID del alumno; sin nombre ni clave de objeto) se escribe **antes** de devolver la URL.
- La imagen guardada es una **nueva** (EXIF aplicado y descartado, lado mayor <= 1024 px, sin metadatos), en `tenants/<centro>/alum/student-photos/<ULID>.<ext>`. El original no se guarda.

## 5. Paginación, filtrado y ordenación

Por **página** en `GET /students`, `GET /enrollments` y `GET /group-change-requests` [DERIVADA, a confirmar con el criterio objetivo de `ADR-038 §4` y el precedente de `GET /family-units`]: conjuntos acotados por centro (≤ ~3.000 personas, `ADR-005`) y la lista de clase necesita total. Orden estable con desempate por `id`. Filtros solo los listados; `q` solo en `GET /students`. **Límite conocido** (#600): el listado ordenado por nombre carga en memoria las matrículas del curso, porque el nombre vive en `Core` (`INV-007`); aceptable hasta ~1.200 filas por centro y curso, sin tope nuevo.

## 6. Exportación de listados (`ADR-054`, `ADR-055`) · `1.15-b`

### `POST /enrollments/exports`
- **Permiso**: `matricula.exportar` · `todos` (`grupo` no se admite en `1.15`: `OPEN-ALUM-18`)
- **Cuerpo**: exactamente los filtros de `GET /enrollments` (`academic_year`, `course`, `group`, `status`), sin paginación, `sort` ni `q` (`ADR-054 §8.2`). Con `q` ⇒ `422 alum.validation.export_query_not_allowed`.
- **Respuesta 202**: recurso de `data_exports` de `Core` (`kind = alum_enrollments`); descarga por `GET /data-exports/{id}`; en cola (`INV-012`); auditado `exported` con los filtros.
- **Implementación (`1.15-b` T2c)**: `202` con `{public_id, status}`; los filtros van en el cuerpo JSON (`academic_year` **obligatorio** como en el listado: sin él `422 alum.validation.required`, inexistente o ilegible `404`, `RN-ALUM-23`; `status` fuera de `activa`/`baja` `422 alum.validation.status_invalid`). Cola `default` (`operacion.md §4`); el trabajo recalcula al ejecutar el permiso `matricula.exportar` y el módulo, y falla en cerrado (`core.export.generation_failed`) si faltan. Filas por `id` ascendente; `family_name` = `family_name_1` y `family_name_2` unidos por un espacio [DERIVADA: el esquema cerrado tiene una sola columna]; fechas como `AAAA-MM-DD`; celdas vacías para lo ausente. Los filtros quedan en el rastro de auditoría de la solicitud (`created` de `data_exports`, además de `exported`).
- **Tope de filas** (#599, `RNF-LIM-004`): como `audit_logs` y `users`, la solicitud cuenta lo que se exportaría y, si supera `core.export_max_rows`, responde `422 core.validation.export_range_too_large` (en `filters`) antes de encolar y sin crear la solicitud.
- **Escrituras sobre matrículas de un curso ilegible** (#598): `PATCH`, baja y anulación aplican la misma comprobación de legibilidad que el detalle: sin `curso_historico.leer`, `404` (no `409 academic-year-closed`).
- **Límite de tasa** (decisión del usuario, 2026-11-11; patrón de `ConsentRateLimit`): por centro y usuario, contando todos los intentos; `alum.export.rate_limit` en `config/alum.php` (10 por hora por defecto [DERIVADA]); superado, `429 urn:pge:error:too-many-requests` con `Retry-After` y `alum.errors.export_rate_limited`.
- **Esquema CSV cerrado** (contrato técnico, `ADR-055`): `enrollment_public_id`, `student_public_id`, `family_name`, `given_name`, `birth_date`, `academic_year_code`, `course_code`, `group_name`, `status`, `enrolled_on`, `withdrawn_on`, `withdrawal_reason`. Sin documento de identidad [DERIVADA: minimización, precedente `1.9b` `OPEN-CORE-32` = B]. Texto neutralizado con `CsvWriter`.

## 7. Eventos de dominio emitidos

Ninguno (`ADR-048`; `funcional.md §7.6`).

## 8. Webhooks disponibles

Ninguno.
