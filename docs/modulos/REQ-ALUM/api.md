# REQ-ALUM · API

> **Estado**: **APROBADA** (2026-11-06) junto con `funcional.md` (ver su cabecera); lo marcado [DERIVADA] o dependiente de un `OPEN-ALUM` no bloqueante sigue como propuesta. Nombres de ruta y campos [DERIVADA] salvo cita.

Prefijo: `/api/v1`. Todo se ajusta a `ADR-038`: envoltura (`§3`), paginación (`§4`), filtros y orden (`§5`), errores RFC 9457 con `type` URN (`§6`), compatibilidad (`§7`). Toda ruta lleva `RequireAuthenticatedUser`, `module-enabled:alum` **antes** de `permission:` (`AR-07a`, `AR-07b`) y pasa por el muro de sesión (`ADR-067`). Fuera de ámbito o inexistente: **`404`** idéntico (`RN-ALUM-64`). Validación siempre en servidor (`INV-010`).

Errores comunes: `401`; `403` sin permiso; `403 urn:pge:error:module-disabled`; `404`; `409 urn:pge:error:academic-year-closed`; `422` con `errors[]` y códigos `alum.validation.*`; `429` donde se diga.

**Idempotencia** (`ADR-038 §8`, `RN-ALUM-27`): mecanismo existente, *middleware* `RequireIdempotencyKey` y tabla `idempotency_keys`, con el contrato de `§8.2` sin cambios (ULID del cliente; formato inválido o ausencia donde es obligatoria ⇒ `400 urn:pge:error:malformed`; reintento con mismo cuerpo ⇒ respuesta original con `Idempotency-Replayed: true`; cuerpo distinto o primera aún en curso ⇒ `409`; retención 24 h). Obligatoria **solo** en `POST /students` (`§8.1` condición 4). Las demás escrituras están protegidas por unicidad natural o por su transición de estado y no llevan la cabecera (`§8.1`, «no» deliberado).

**Entregas**: `1.15-a` sirve `§1` **sin** los filtros `course`, `group`, `enrollment_status`, sin la matrícula vigente en el listado y sin `enrollments[]` en la ficha, y con `alumno.leer` de ámbitos `todos` y `unidad_familiar`; `1.15-b` añade todo eso y el ámbito `grupo` (`funcional.md §1.2`).

---

## 1. Alumnado (`1.15-a`)

### `GET /students`
- **Permiso**: `alumno.leer` · ámbito `todos`, `grupo`, `unidad_familiar`
- **Parámetros**: `academic_year` (**desde `1.15-b`**: sin matrículas no tiene objeto en `1.15-a`; `public_id`; por defecto el del `AcademicYearContext`; filtra a alumnos con matrícula en ese curso cuando se combina con los siguientes), `course`, `group`, `enrollment_status` (`activa`, `baja`), `q` (nombre, por `PersonDirectory::searchByNameAmong`; mínimo 2 caracteres), `include_deleted` (exige `alumno.eliminar`), `sort` (`name`, `created_at`), paginación.
- **Respuesta 200**: lista de `Student` resumido (`public_id`, `person.public_id`, nombre visible, fecha de nacimiento, matrícula vigente en el curso pedido con curso de etapa y grupo).
- **Errores**: (desde `1.15-b`, como `academic_year`) `404` si `academic_year` es de un curso cerrado y no hay `curso_historico.leer` (`RN-ALUM-23`); `422` filtros inválidos.

### `POST /students`
- **Permiso**: `alumno.crear` · `todos`
- **Cuerpo**: `given_name`, `family_name`, `birth_date`, `document_type`/`document_number` (opcionales, catálogo de `Core` `dni`/`nie`/`pasaporte`), `locale` (opcional), `family_unit` (opcional, `public_id`). Los nombres exactos los fija la ampliación de `Core` (`OPEN-ALUM-19`).
- **Respuesta 201**: `Student` completo.
- **Errores**: `400` (idempotencia); `403` si llega `family_unit` sin el permiso que fije `OPEN-ALUM-31` (recomendación A: `familia.actualizar`); `409` (idempotencia); `422 alum.validation.birth_date_invalid`, `family_unit_not_found`, `person_exists` (cuerpo idéntico sea quien sea la persona, `RN-ALUM-09`), errores de `fam_unit` de su §5.2 traducidos a `alum.validation.*`.
- **Idempotencia**: **obligatoria** (`RN-ALUM-27`): crea una persona sin unicidad natural (el documento es opcional).

### `GET /students/{public_id}`
- **Permiso**: `alumno.leer` · `todos`, `grupo`, `unidad_familiar`
- **Respuesta 200**: ficha: datos personales; `enrollments[]` (las de cursos ilegibles se omiten, `RN-ALUM-23`); `emergency_contact` y `guardians[]` **solo** si el sujeto tiene `vinculo_tutor.leer` con un ámbito que alcance al alumno (se leen de `FamilyMembership`; si no, el campo no aparece) [DERIVADA: no ampliar por la puerta de `alum` lo que `fam_unit` protege]; `photo` (`OPEN-ALUM-08`) con URL firmada.

### `PATCH /students/{public_id}`
- **Permiso**: `alumno.actualizar` · `todos`
- **Cuerpo**: los datos personales de `POST` salvo `family_unit` (la pertenencia se gestiona en `fam_unit`, `RN-ALUM-04`). Se reenvían a `Core`: `correctUnaccounted` si la persona no tiene usuario, la ampliación de `RN-ALUM-10` si lo tiene. Nunca correo de acceso, credenciales ni estado del usuario.
- **Errores**: `422` como en `POST`, y `422` si el cuerpo intenta cambiar datos de la cuenta (`RN-ALUM-10`).

### `DELETE /students/{public_id}` · `POST /students/{public_id}/restore`
- **Permiso**: `alumno.eliminar` · `todos`
- **Errores**: `409 alum.conflict.has_active_enrollments` (`RN-ALUM-05`, desde `1.15-b`); `409 alum.conflict.cannot_restore` (`RN-ALUM-06`).
- **Respuesta**: `204` / `200`.

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
- **Errores**: `409 alum.conflict.has_dependents` cuando un consumidor registre veto (ninguno en `1.15`).

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

## 4. Fotografía (`1.15-c`, condicionado a `OPEN-ALUM-08`)

| Verbo y ruta | Permiso · ámbito | Notas |
|--------------|------------------|-------|
| `PUT /students/{public_id}/photo` | `alumno.actualizar` · `todos` | `multipart/form-data`; sustituye (`RN-ALUM-50`); `422` tipo o tamaño |
| `DELETE /students/{public_id}/photo` | `alumno.actualizar` · `todos` | `204` |
| `GET /students/{public_id}/photo` | `alumno.leer` · `todos`, `grupo`, `unidad_familiar` | Devuelve URL firmada de caducidad corta, nunca el binario por la API; registra `read` (`RN-ALUM-52`) |

## 5. Paginación, filtrado y ordenación

Por **página** en `GET /students`, `GET /enrollments` y `GET /group-change-requests` [DERIVADA, a confirmar con el criterio objetivo de `ADR-038 §4` y el precedente de `GET /family-units`]: conjuntos acotados por centro (≤ ~3.000 personas, `ADR-005`) y la lista de clase necesita total. Orden estable con desempate por `id`. Filtros solo los listados; `q` solo en `GET /students`.

## 6. Exportación de listados (`ADR-054`, `ADR-055`) · `1.15-b`

### `POST /enrollments/exports`
- **Permiso**: `matricula.exportar` · `todos` (`grupo` no se admite en `1.15`: `OPEN-ALUM-18`)
- **Cuerpo**: exactamente los filtros de `GET /enrollments` (`academic_year`, `course`, `group`, `status`), sin paginación, `sort` ni `q` (`ADR-054 §8.2`). Con `q` ⇒ `422 alum.validation.export_query_not_allowed`.
- **Respuesta 202**: recurso de `data_exports` de `Core` (`kind = alum_enrollments`); descarga por `GET /data-exports/{id}`; en cola (`INV-012`); auditado `exported` con los filtros.
- **Esquema CSV cerrado** (contrato técnico, `ADR-055`): `enrollment_public_id`, `student_public_id`, `family_name`, `given_name`, `birth_date`, `academic_year_code`, `course_code`, `group_name`, `status`, `enrolled_on`, `withdrawn_on`, `withdrawal_reason`. Sin documento de identidad [DERIVADA: minimización, precedente `1.9b` `OPEN-CORE-32` = B]. Texto neutralizado con `CsvWriter`.

## 7. Eventos de dominio emitidos

Ninguno (`ADR-048`; `funcional.md §7.6`).

## 8. Webhooks disponibles

Ninguno.
