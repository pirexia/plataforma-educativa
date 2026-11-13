# REQ-INF · API

> **Estado**: **PROPUESTO** (2026-11-12/13, ola 1, agente B6), junto con `funcional.md`. Sin aprobar. Nombres de ruta, de campo y de código de error son **[DERIVADA]** salvo cita: los fija esta especificación siguiendo los precedentes de `REQ-ALUM/api.md` y `REQ-FAM-UNIT`. Lo que depende de una `OPEN-INF` se indica en cada sección.

Prefijo: `/api/v1`. Todo se ajusta a `ADR-038`: envoltura (`§3`), paginación (`§4`), filtros y orden (`§5`), errores RFC 9457 con `type` URN (`§6`), compatibilidad (`§7`) e idempotencia (`§8`). Toda ruta lleva `RequireAuthenticatedUser`, `module-enabled:inf` **antes** de `permission:` (`AR-07a`, `AR-07b`) y pasa por el muro de sesión (`ADR-067`). Validación siempre en servidor (`INV-010`). Documentación OpenAPI en `apps/api/openapi/paths/inf.yaml` al implementar (definición de terminado, `CLAUDE.md §10`).

**Reglas comunes a todos los *endpoints***

- **Fuera de ámbito, inexistente, de otro centro, dado de baja o no publicado para la familia: `404` idéntico**, sin fila `read` (precedente `RN-ALUM-64`). En escrituras, el `404` va **antes** que cualquier `422`.
- **Curso cerrado** (`RN-INF-70`, `RN-CURSO-33`): toda lectura que recibe o deduce un curso académico invoca `AcademicYearReadAccess`; sin `curso_historico.leer`, `404` en listado y en detalle. Escritura sobre curso cerrado: `409 urn:pge:error:academic-year-closed` si el actor puede leerlo, `404` si no (precedente #598).
- **Lectura auditada** (`RN-INF-30`, `-51`, `-63`): los *endpoints* marcados **[lectura auditada]** escriben una fila `read` (`AuditRecorder::recordRead`) **antes** de construir la respuesta, con `purpose` de `datos.md §5`, recuento de registros y `public_id` de los partes/informes/planes devueltos; nunca nombres, textos libres ni valores. Si la escritura falla, `500` sin datos. Cupo por centro y usuario (`inf.read_rate_limit`, `operacion.md §1`): superado, `429 urn:pge:error:too-many-requests` con `Retry-After` y el código `inf.errors.read_rate_limited`. Un `404` no escribe fila ni consume cupo.
- **Textos libres** (`detail`, `day_notes`, `observation`, `global_comment`, `notes`, `evolution`, `justification`, `reason`): se recortan; sin caracteres de control salvo salto de línea y tabulador; longitud máxima por campo en `config/inf.php` (`operacion.md §1`) **y** `CHECK` en base de datos (`datos.md`). Excedida, `422 inf.validation.text_too_long`; con caracteres no admitidos, `422 inf.validation.text_invalid`.
- **Referencias** a otras entidades viajan como `public_id` (`ADR-029`): `academic_year`, `education_level`, `group`, `enrollment`, `period`, `indicator`, `scale_value`. Una referencia inexistente, ilegible o de otro centro: `422 inf.validation.<campo>_not_found` en escritura y `404` si es el recurso de la ruta.
- **Fechas** `AAAA-MM-DD`; horas `HH:MM` en la zona horaria del centro; instantes en ISO 8601 con zona (`TIMESTAMPTZ`).
- **Idempotencia** (`ADR-038 §8`): mecanismo existente (`RequireIdempotencyKey`, tabla `idempotency_keys`) con el contrato de `§8.2` sin cambios. **Obligatoria solo** en `POST /infant-ratio-exceptions` y `POST /infant-daily-logs/bulk-entries`, que crean filas sin unicidad natural (`§8.1`, condición 4). Las anotaciones se protegen con `client_ref` (`RN-INF-27`); las demás escrituras, por unicidad natural o por su transición de estado, y no llevan la cabecera («no» deliberado, como `RN-ALUM-27`).

**Errores comunes**: `400 urn:pge:error:malformed`; `401`; `403` sin permiso; `403 urn:pge:error:module-disabled` (`RMOD-009`); `404`; `409 urn:pge:error:academic-year-closed`; `422` con `errors[]` y códigos `inf.validation.*`; `409` de negocio con códigos `inf.conflict.*`; `429` donde se diga.

---

## 1. Ciclo, tramos y ratio (`1.18-a`)

Recurso de permisos: `ciclo_infantil` (`permisos.md §2`), siempre ámbito `todos`. Ninguna lectura de esta sección es auditada como lectura sensible: es configuración sin datos personales, salvo `enrollment` en un consumo de excepción, que se devuelve solo como `public_id`.

### 1.1 Marca de primer ciclo

| Verbo y ruta | Permiso · ámbito | Cuerpo / parámetros | Respuesta | Errores propios |
|--------------|------------------|---------------------|-----------|-----------------|
| `GET /infant-cycle-levels` | `ciclo_infantil.leer` · `todos` | `academic_year` (obligatorio) | `200` lista sin paginar (pocas filas por curso) | — |
| `POST /infant-cycle-levels` | `ciclo_infantil.actualizar` · `todos` | `academic_year`, `education_level`, `age_cutoff_date` | `201` + `Location` | `422 inf.validation.level_not_first_cycle` (no `infantil` o no `privado`, `RN-INF-01`), `level_already_marked`, `cutoff_outside_academic_year` (`RN-INF-02`) |
| `GET /infant-cycle-levels/{public_id}` | `ciclo_infantil.leer` · `todos` | — | `200` | — |
| `PATCH /infant-cycle-levels/{public_id}` | `ciclo_infantil.actualizar` · `todos` | `age_cutoff_date`; desde `1.18-c`, `development_scale` (`OPEN-INF-09`, `datos.md §1.1`) | `200` | `422 cutoff_outside_academic_year`, `scale_not_found` |
| `DELETE /infant-cycle-levels/{public_id}` | `ciclo_infantil.actualizar` · `todos` | — | `204` | `409 inf.conflict.level_has_groups` si algún grupo de la etapa tiene tramo |

Recurso `InfantCycleLevel`: `public_id`, `academic_year {public_id, code}`, `education_level {public_id, name}` (por la interfaz de `acad`), `age_cutoff_date`, `development_scale {public_id, name} | null`, `created_at`, `updated_at`.

### 1.2 Tramo de cada grupo

| Verbo y ruta | Permiso · ámbito | Cuerpo / parámetros | Respuesta | Errores propios |
|--------------|------------------|---------------------|-----------|-----------------|
| `GET /infant-groups` | `ciclo_infantil.leer` · `todos` | `academic_year` (obligatorio), `age_band` | `200` sin paginar | — |
| `POST /infant-groups` | `ciclo_infantil.actualizar` · `todos` | `group`, `age_band` (`0-1`, `1-2`, `2-3`) | `201` | `422 inf.validation.group_not_in_first_cycle` (`RN-INF-03`), `group_already_banded`, `age_band_invalid` |
| `PATCH /infant-groups/{public_id}` | `ciclo_infantil.actualizar` · `todos` | `age_band` | `200` | `409 inf.conflict.age_band_in_use` (`RN-INF-12`) |
| `DELETE /infant-groups/{public_id}` | `ciclo_infantil.actualizar` · `todos` | — | `204` | `409 inf.conflict.age_band_in_use` (`RN-INF-12`) |

El `public_id` de la ruta es el de `infant_groups`, no el del grupo de `acad`. Recurso: `public_id`, `group {public_id, code, name}`, `age_band`, `created_at`, `updated_at`.

### 1.3 Reglas de ratio

| Verbo y ruta | Permiso · ámbito | Cuerpo / parámetros | Respuesta | Errores propios |
|--------------|------------------|---------------------|-----------|-----------------|
| `GET /infant-ratio-rules` | `ciclo_infantil.leer` · `todos` | `academic_year` (obligatorio) | `200` sin paginar (≤ 3 filas por curso) | — |
| `POST /infant-ratio-rules` | `ciclo_infantil.actualizar` · `todos` | `academic_year`, `age_band`, `max_students_per_group` (entero ≥ 1), `legal_reference` (no vacío) | `201` | `422 inf.validation.max_invalid`, `legal_reference_required`, `rule_already_exists` (`RN-INF-04`) |
| `PATCH /infant-ratio-rules/{public_id}` | `ciclo_infantil.actualizar` · `todos` | `max_students_per_group`, `legal_reference` | `200` (bajar por debajo de la ocupación se admite, `RN-INF-08`) | `422` como en `POST` |
| `DELETE /infant-ratio-rules/{public_id}` | `ciclo_infantil.actualizar` · `todos` | — | `204`; los grupos del tramo pasan a «sin control» (`RN-INF-05`) | — |

### 1.4 Estado de ratio

#### `GET /infant-ratio-status`
- **Permiso**: `ciclo_infantil.leer` · `todos`
- **Parámetros**: `academic_year` (obligatorio), `date` (opcional, por defecto hoy en la zona del centro; no futura).
- **Respuesta 200**, sin paginar (un elemento por grupo infantil): `group {public_id, code, name}`, `age_band | null`, `active_enrollments`, `max_students_per_group | null`, `open_exceptions` (concesiones vigentes), `capacity` (`max + open_exceptions`, o `null`), `state` ∈ `sin_control` (sin tramo o sin regla), `con_plazas`, `completo`, `excedido` (`RN-INF-05`, `-08`). Más el estado de la autorización vigente (`§1.7`) en `authorization_state`.
- Las matrículas activas se cuentan por la interfaz de `alum` (`EnrollmentRoster` o equivalente, `funcional.md §7.1`), nunca leyendo `enrollments`.

### 1.5 Excepciones de ratio (solo anexar)

| Verbo y ruta | Permiso · ámbito | Cuerpo / parámetros | Respuesta | Errores propios |
|--------------|------------------|---------------------|-----------|-----------------|
| `GET /infant-ratio-exceptions` | `ciclo_infantil.leer` · `todos` | `academic_year` (obligatorio), `group`, `state` (`vigente`, `consumida`, `anulada`) | `200` paginado por página | — |
| `POST /infant-ratio-exceptions` | `ciclo_infantil.aprobar` · `todos` | `group`, `justification` (obligatoria, no vacía) | `201` | `422 inf.validation.justification_required`, `group_not_banded` |
| `GET /infant-ratio-exceptions/{public_id}` | `ciclo_infantil.leer` · `todos` | — | `200` | — |
| `POST /infant-ratio-exceptions/{public_id}/cancellation` | `ciclo_infantil.aprobar` · `todos` | `reason` (obligatoria) | `200`; anexa la fila `anulacion` (`RN-INF-07`) | `409 inf.conflict.exception_not_open` (ya consumida o anulada) |

- **Idempotencia**: `POST /infant-ratio-exceptions` exige `Idempotency-Key` (crea una plaza sin unicidad natural). La anulación no: la segunda responde `409 exception_not_open`.
- Recurso: `public_id`, `group`, `state` (calculado de las filas anexas), `justification`, `granted_by {public_id, display_name}`, `granted_at`, `consumed_by_enrollment {public_id} | null`, `consumed_at`, `cancelled_by`, `cancelled_at`, `cancellation_reason`.
- No hay `PATCH` ni `DELETE`: la tabla es de solo anexar (`CA-INF-010`). Cualquier otro verbo sobre la ruta, `405`.
- `cancellation_reason` se guarda en la fila `anulacion` (columna `justification`, que en `anulacion` también es obligatoria). **Corrección propuesta a `datos.md §1.4`** incluida en este mismo paso: el `CHECK` de `justification` cubre `concesion` y `anulacion`.

### 1.6 Registro diario de ratio (solo anexar)

#### `GET /infant-ratio-records`
- **Permiso**: `ciclo_infantil.leer` · `todos`
- **Parámetros**: `academic_year` (obligatorio), `group`, `from`, `to`, `exceeded` (booleano), `sort` (`record_date`, por defecto descendente; desempate por `id`).
- **Respuesta 200**: paginado **por página** (acotado: grupos × días lectivos de un curso). Elementos: `group`, `record_date`, `age_band`, `active_enrollments`, `max_allowed`, `exceeded`.
- Escritura solo por la tarea diaria (`operacion.md §4`); ningún *endpoint* escribe aquí.

### 1.7 Autorización administrativa

| Verbo y ruta | Permiso · ámbito | Cuerpo / parámetros | Respuesta | Errores propios |
|--------------|------------------|---------------------|-----------|-----------------|
| `GET /infant-cycle-authorizations` | `ciclo_infantil.leer` · `todos` | `state` (`vigente`, `proxima_a_caducar`, `caducada`) | `200` sin paginar | — |
| `POST /infant-cycle-authorizations` | `ciclo_infantil.actualizar` · `todos` | `reference`, `issuing_body`, `valid_from`, `valid_until` (opcional), `notes` (opcional) | `201` | `422 inf.validation.validity_range_invalid` |
| `GET /infant-cycle-authorizations/{public_id}` | `ciclo_infantil.leer` · `todos` | — | `200` | — |
| `PATCH /infant-cycle-authorizations/{public_id}` | `ciclo_infantil.actualizar` · `todos` | los mismos campos | `200` | ídem |
| `DELETE /infant-cycle-authorizations/{public_id}` | `ciclo_infantil.actualizar` · `todos` | — | `204` (borrado lógico) | — |

`state` se calcula en cada respuesta con el umbral `inf.authorization_expiry_warning_days` (`RN-INF-10`); no se guarda. Ninguna otra operación consulta este estado para bloquear (`RN-INF-11`). Sin curso académico: estas rutas no invocan `AcademicYearReadAccess`.

### 1.8 Efectos en otros módulos (sin *endpoint* propio de `inf`)

| *Endpoint* de otro módulo | Efecto de `inf` (`OPEN-INF-06` = A) | Error |
|---------------------------|-------------------------------------|-------|
| `POST /enrollments` (`alum`) con `group` de un grupo infantil | Veto de alta (`RN-INF-06`); si cabe por excepción, la consume | `409 inf.conflict.ratio_exceeded` |
| `PATCH /enrollments/{public_id}` con `group` (asignación, `RN-ALUM-28`) | Veto de asignación | ídem |
| `POST /group-change-requests/{public_id}/approval` (`alum`) hacia un grupo infantil | Veto de cambio de grupo | ídem |
| `DELETE /groups/{public_id}`, `DELETE /education-levels/{public_id}`, `DELETE /academic-periods/{public_id}` (`acad`) | Veto de borrado si hay tramo, marca, partes, informes o planes (`RN-ACAD-21`) | `409 acad.conflict.has_dependents` con la lista de dependientes de `inf` |
| `DELETE /enrollments/{public_id}` (`alum`, anulación) | Veto si hay partes, informes, planes o un consumo de excepción (`REQ-ALUM §7.2`) | `409 alum.conflict.has_dependents` |

El `409` de ratio lleva en `errors[]` el grupo, la capacidad y la ocupación, nunca nombres de alumnos. El texto traducido del motivo lo muestra la pantalla de `alum` (`CA-INF-083`): la clave `inf.errors.ratio_exceeded` vive en las traducciones de `inf` y la SPA la resuelve por el código. Con `inf` no contratado, ninguno de estos vetos actúa (`funcional.md §9`). Las rutas exactas de `acad` (`/education-levels`, `/academic-periods`) **no se han verificado** contra `apps/api/openapi/paths/acad.yaml`.

---

## 2. Parte diario (`1.18-b`)

Recurso de permisos: `agenda_infantil`. Ámbitos: `todos`, `grupo` (resolutor sobre `group_id` del parte, `permisos.md §3.1`) y `unidad_familiar` (solo partes `publicado` de alumnos con vínculo **activo**). Depende de `OPEN-INF-04` (si = B, esta sección queda bloqueada) y `OPEN-INF-10`.

### `GET /infant-daily-logs/day` · [lectura auditada, `purpose = inf_daily_log_day`]
- **Permiso**: `agenda_infantil.leer` · `todos`, `grupo`. Con solo `unidad_familiar`: `404` (la familia no ve grupos).
- **Parámetros**: `group` (obligatorio, `public_id` del grupo de `acad`), `date` (obligatorio, no futura, `RN-INF-21`).
- **Respuesta 200**, sin paginar (un grupo infantil está acotado por su ratio): una fila por alumno con matrícula activa en el grupo ese día (`RN-INF-20`), con `enrollment {public_id}`, `student {public_id, display_name, birth_date}` (de `PersonDirectory`), y `daily_log` (el parte completo con `entries[]`) o `null` si aún no existe. **No crea partes**: un `GET` no escribe nada salvo la fila `read`.
- **Errores**: `404` si el grupo no existe, no es infantil, o no está en el ámbito; `422 inf.validation.date_in_future`.

### `GET /infant-daily-logs` · [lectura auditada, `purpose = inf_daily_log_history`]
- **Permiso**: `agenda_infantil.leer` · `todos`, `grupo`, `unidad_familiar`
- **Parámetros**: `student` (`public_id` de persona del alumno), `group`, `from`, `to`, `status` (solo personal; con `unidad_familiar` se ignora y vale siempre `publicado`), `limit` (defecto 20, máximo 60), `cursor`. Al menos uno de `student` o `group`; sin ninguno, `422 inf.validation.filter_required` [DERIVADA: evita volcar todo el centro de una vez].
- **Paginación**: **por cursor** (`ADR-038 §4`), orden `log_date` descendente con desempate por `id`: es la tabla de crecimiento continuo del módulo y la familia la recorre como un histórico, sin necesidad de total [DERIVADA, a confirmar contra el criterio objetivo de `§4`].
- **Respuesta 200**: partes con `entries[]` (histórico, `REQ-INF-003`).

### `GET /infant-daily-logs/{public_id}` · [lectura auditada, `purpose = inf_daily_log_day`]
- **Permiso**: `agenda_infantil.leer` · `todos`, `grupo`, `unidad_familiar`
- **Respuesta 200**: recurso `DailyLog`: `public_id`, `academic_year`, `log_date`, `group {public_id, name}` (el del día, `RN-INF-31`), `enrollment {public_id}`, `student {public_id, display_name}`, `mood`, `day_notes`, `status`, `published_at`, `entries[]` (`public_id`, `kind`, `occurred_at`, los campos de su tipo, `detail`, `client_ref`, `created_at`, `updated_at`), `created_at`, `updated_at`. Para la familia, sin `client_ref` ni autoría [DERIVADA: minimización].
- **Errores**: `404` (borrador para la familia, `CA-INF-022`; eliminado, `CA-INF-031`; vínculo revocado, `CA-INF-023`).

### `POST /infant-daily-logs`
- **Permiso**: `agenda_infantil.crear` · `todos`, `grupo`
- **Cuerpo**: `enrollment`, `date`, `mood` (opcional), `day_notes` (opcional).
- **Respuesta 201** + `Location`. El `group_id` se fija con el grupo de la matrícula ese día.
- **Errores**: `404` si la matrícula no está en el ámbito; `422 inf.validation.daily_log_exists` (`RN-INF-20`, protege también el reintento), `enrollment_not_active_on_date`, `date_in_future`, `mood_invalid`.

### `PATCH /infant-daily-logs/{public_id}`
- **Permiso**: `agenda_infantil.actualizar` · `todos`, `grupo`
- **Cuerpo**: `mood`, `day_notes` (al menos uno; `null` borra). Admitido también en `publicado` (`RN-INF-28`).
- **Errores**: `422 mood_invalid`, `inf.validation.no_changes`.

### `DELETE /infant-daily-logs/{public_id}`
- **Permiso**: `agenda_infantil.eliminar` · `todos`
- **Respuesta 204** (borrado lógico del parte y sus anotaciones, `RN-INF-29`).

### `POST /infant-daily-log-entries`
- **Permiso**: `agenda_infantil.crear` · `todos`, `grupo`
- **Cuerpo**: `enrollment`, `date`, `kind` (`comida`, `descanso`, `higiene`, `hito`, `reposicion`), `occurred_at` (opcional), los campos de su tipo (`meal_service`, `meal_amount`; `sleep_minutes`, `sleep_quality`; `hygiene_kind`; `supply_item`), `detail` (opcional; obligatorio en `hito` [DERIVADA: un hito sin texto no dice nada]), `client_ref` (opcional, ULID generado por el cliente).
- **Comportamiento**: si el parte de esa matrícula y fecha no existe, lo crea en `borrador` en la misma transacción [DERIVADA: la vista del día devuelve partes vacíos sin `public_id` (`§2`, primer *endpoint*), y el cliente anota sin un paso previo; es la vía de «pocos toques», `REQ-INF-003`].
- **Respuesta 201** con la anotación y `daily_log {public_id, status}`. **Reenvío con el mismo `client_ref`** (`RN-INF-27`, `CA-INF-026`): `200` con la anotación existente y cabecera `Idempotency-Replayed: true`, sin crear nada; si el cuerpo difiere del original, `409 inf.conflict.client_ref_reused`.
- **Errores**: `404` (matrícula fuera de ámbito); `422 inf.validation.kind_invalid`, `value_invalid` (en el campo concreto, fuera de su vocabulario, `RN-INF-22`), `field_not_allowed_for_kind`, `field_required_for_kind`, `sleep_minutes_invalid`, `enrollment_not_active_on_date`, `date_in_future`, `client_ref_invalid`.

### `PATCH /infant-daily-log-entries/{public_id}` · `DELETE /infant-daily-log-entries/{public_id}`
- **Permiso**: `agenda_infantil.actualizar` · `todos`, `grupo` (también para la baja de una anotación, `permisos.md §2`)
- **Cuerpo** (`PATCH`): los campos de su tipo, `occurred_at`, `detail`. `kind` no se cambia (`422 inf.validation.field_not_editable`).
- **Respuesta**: `200` / `204` (borrado lógico).

### `POST /infant-daily-logs/bulk-entries`
- **Permiso**: `agenda_infantil.crear` · `todos`, `grupo`
- **Cuerpo**: `group`, `date`, `entries[]` (1 a 10 anotaciones con la forma de `POST /infant-daily-log-entries` sin `enrollment`, `date` ni `client_ref`), `excluded_enrollments[]` (opcional, `public_id`).
- **Comportamiento** (`RN-INF-26`): aplica las anotaciones a todas las matrículas activas del grupo ese día salvo las excluidas; crea los partes que falten; **todo o nada** en una transacción.
- **Respuesta 201**: `{affected: n, excluded: m, daily_logs: [{public_id, enrollment}]}`.
- **Errores**: `404` (grupo fuera de ámbito); `422` como en el alta individual, con la posición en `errors[].pointer` (`/entries/2/meal_amount`); `422 inf.validation.excluded_not_in_group` si un excluido no es del grupo ese día.
- **Idempotencia**: **obligatoria** (`Idempotency-Key`, `RN-INF-27`).

### `POST /infant-daily-logs/{public_id}/publication`
- **Permiso**: `agenda_infantil.publicar` · `todos`, `grupo`
- **Respuesta 200**: el parte en `publicado`, con `published_at`. Si ya estaba publicado, `200` sin cambios [DERIVADA: el reintento desde un dispositivo con mala conexión no debe dar error].

### `POST /infant-daily-logs/group-publication`
- **Permiso**: `agenda_infantil.publicar` · `todos`, `grupo`
- **Cuerpo**: `group`, `date`.
- **Respuesta 200**: `{published: n}` (`RN-INF-25`): publica en una operación todos los partes en `borrador` del grupo y día; los que no existen no se crean. Repetirlo responde `{published: 0}`.

---

## 3. Informe de desarrollo (`1.18-c`)

Recurso de permisos: `informe_desarrollo`. Ámbitos como el parte; la familia solo alcanza informes `publicado` vigentes (`RN-INF-48`). Depende de `OPEN-INF-08`, `-09`, `-10`, `-11`, `-21`.

### 3.1 Indicadores

| Verbo y ruta | Permiso · ámbito | Cuerpo / parámetros | Respuesta | Errores propios |
|--------------|------------------|---------------------|-----------|-----------------|
| `GET /infant-development-indicators` | `informe_desarrollo.leer` · cualquiera de sus ámbitos | `academic_year` (obligatorio), `age_band`, `area` | `200` sin paginar, orden por `area` y `position` | — |
| `POST /infant-development-indicators` | `informe_desarrollo.actualizar` · **`todos`** | `academic_year`, `area` (`armonia`, `entorno`, `comunicacion`), `age_band`, `text` (objeto por idioma, `ADR-062`; es-ES obligatorio), `position` | `201` | `422 area_invalid`, `age_band_invalid`, `text_required`, `locale_not_active` |
| `PATCH /infant-development-indicators/{public_id}` | `informe_desarrollo.actualizar` · `todos` | `text`, `position` | `200` | ídem; `409 inf.conflict.indicator_in_use` si cambia `text` y ya tiene valoraciones en un informe `publicado` [DERIVADA: el publicado es inmutable, `RN-INF-49`, y muestra el texto vigente] |
| `DELETE /infant-development-indicators/{public_id}` | `informe_desarrollo.eliminar` · `todos` | — | `204` | `409 inf.conflict.indicator_in_use` si tiene valoraciones |

- Con ámbito `grupo` o `unidad_familiar`, la lectura de indicadores devuelve todos los del curso: no son datos personales y el ámbito no se aplica [DERIVADA]. Escribir con ámbito `grupo`: `403` (`permisos.md §2`).
- `text` se devuelve en el idioma del usuario con el respaldo de `ADR-062` (`CA-INF-041`); con `?all_locales=true` y `informe_desarrollo.actualizar`, el objeto completo para editar.

### 3.2 Informes

| Verbo y ruta | Permiso · ámbito | Notas |
|--------------|------------------|-------|
| `GET /infant-development-reports` · [lectura auditada, `purpose = inf_development_report`] | `informe_desarrollo.leer` · `todos`, `grupo`, `unidad_familiar` | Filtros `academic_year` (obligatorio), `group`, `period`, `kind`, `status` (solo personal), `student`; `include_superseded` (solo personal). Paginado **por página**, orden por nombre visible del alumno y `version` (acotado: grupo × período). La familia solo recibe `publicado` vigentes; **ni los recuentos** revelan informes no publicados (`CA-INF-047`): el total de página se calcula sobre el conjunto ya acotado por el resolutor |
| `GET /infant-development-reports/{public_id}` · [lectura auditada] | ídem | Recurso completo (abajo) |
| `POST /infant-development-reports` | `informe_desarrollo.crear` · `todos`, `grupo` | `enrollment`, `kind` (`periodo`, `final_ciclo`), `period` (obligatorio en `periodo`, prohibido en `final_ciclo`). Copia la escala del ciclo (`infant_cycle_levels.development_scale`). `201`. `422 inf.validation.report_exists` (protege el reintento), `period_required`, `period_not_allowed`, `period_not_in_academic_year`, `scale_not_configured` |
| `PATCH /infant-development-reports/{public_id}` | `informe_desarrollo.actualizar` · `todos`, `grupo` | Solo en `borrador` (`RN-INF-46`). `global_comment`, `ratings[]` (`{indicator, scale_value}`; sustituye la valoración de ese indicador; `scale_value: null` la quita), `area_notes[]` (`{area, observation}`). `409 inf.conflict.report_not_editable` fuera de `borrador`, `inf.conflict.report_immutable` en `publicado`/`sustituido` (`CA-INF-049`); `422 indicator_wrong_band` (`RN-INF-44`), `scale_value_wrong_scale` (`RN-INF-43`), `indicator_not_found` |
| `POST /infant-development-reports/{public_id}/submission` | `informe_desarrollo.actualizar` · `todos`, `grupo` | `borrador → pendiente_validacion`. `422 inf.validation.area_notes_incomplete` (`RN-INF-46`); `409 inf.conflict.report_invalid_transition` |
| `POST /infant-development-reports/{public_id}/validation` | `informe_desarrollo.aprobar` · `todos` | `pendiente_validacion → validado`. `403 inf.forbidden.self_validation` si el actor es `authored_by` (`RN-INF-47`); `409 report_invalid_transition` |
| `POST /infant-development-reports/{public_id}/return` | `informe_desarrollo.aprobar` · `todos` | `reason` obligatorio. `pendiente_validacion → borrador`. `422 inf.validation.reason_required`; `409 report_invalid_transition` |
| `POST /infant-development-reports/{public_id}/publication` | `informe_desarrollo.publicar` · `todos` | `validado → publicado`; si es una rectificación, la versión anterior pasa a `sustituido` en la misma transacción. `409 report_invalid_transition` (`CA-INF-046`). Depende de `OPEN-INF-11` |
| `POST /infant-development-reports/{public_id}/rectification` | `informe_desarrollo.crear` · `todos`, `grupo` | Sobre un `publicado` vigente: crea la versión `n+1` en `borrador`, enlazada por `supersedes`, con copia de valoraciones, observaciones y comentario. `201`. `409 inf.conflict.report_not_rectifiable` (no publicado, ya `sustituido`, o con una rectificación abierta) |
| `DELETE /infant-development-reports/{public_id}` | `informe_desarrollo.eliminar` · `todos` | Solo `borrador` (incluida una rectificación en borrador). `204`. `409 inf.conflict.report_immutable` en `publicado`/`sustituido`; `409 report_not_editable` en `pendiente_validacion`/`validado` |

**Recurso `DevelopmentReport`**: `public_id`, `academic_year`, `kind`, `period {public_id, name} | null`, `version`, `status`, `group`, `enrollment {public_id}`, `student {public_id, display_name}`, `age_band`, `scale {public_id, values[{public_id, label}]}` (etiquetas por la interfaz de `acad`, traducidas), `areas[]` con `{area, label, observation, ratings[{indicator {public_id, text}, scale_value {public_id, label} | null}]}`, `global_comment`, `authored_by`, `submitted_at`, `validated_by`, `validated_at`, `published_by`, `published_at` (`{public_id, display_name}` o `null`; `RN-INF-52`, `CA-INF-053`), `return_reason` (solo personal), `supersedes {public_id, version} | null`, `superseded_by {public_id, version} | null` y, solo para personal, `versions[]` (`{public_id, version, status, published_at}`). **Ningún campo numérico de calificación** (`RN-INF-40`, `CA-INF-040`): las valoraciones son siempre una etiqueta de escala.

Para la familia, `authored_by`, `validated_by` y `published_by` se devuelven con nombre visible [DERIVADA: «las mismas garantías que un boletín» incluyen saber quién firma; si el usuario prefiere ocultarlos, se cambia sin migración].

Sin PDF ni firma en `1.18-c` (`1.18-g`; `ADR-078`, `ADR-082`, ambos `PROPUESTA`; `ADR-082 §4.1` incluye `REQ-INF-002` entre sus consumidores, sin que esta especificación adelante su nivel de firma).

---

## 4. Plan de adaptación (`1.18-d`)

Recurso de permisos: `adaptacion_infantil`. Ámbitos como el parte; la familia solo alcanza planes `publicado` (`RN-INF-61`). Depende de `OPEN-INF-04` y `-10`.

| Verbo y ruta | Permiso · ámbito | Notas |
|--------------|------------------|-------|
| `GET /infant-adaptation-plans` · [lectura auditada, `purpose = inf_adaptation_plan`] | `adaptacion_infantil.leer` · `todos`, `grupo`, `unidad_familiar` | Filtros `academic_year` (obligatorio), `group`, `student`, `status` (solo personal). Paginado por página (acotado: un plan vivo por matrícula) |
| `GET /infant-adaptation-plans/{public_id}` · [lectura auditada] | ídem | Plan con `days[]` (`public_id`, `plan_date`, `planned_entry`, `planned_exit`, `evolution`, `evolution_recorded_at`) |
| `POST /infant-adaptation-plans` | `adaptacion_infantil.crear` · `todos`, `grupo` | `enrollment`, `notes` (opcional), `days[]` (`{date, planned_entry, planned_exit}`, al menos uno). `201`. `422 inf.validation.plan_exists` (`RN-INF-60`, `CA-INF-064`), `day_duplicated`, `exit_before_entry`, `date_outside_academic_year` |
| `PATCH /infant-adaptation-plans/{public_id}` | `adaptacion_infantil.actualizar` · `todos`, `grupo` | `notes`; `days[]` sustituye el calendario: los días con `evolution` anotada no pueden quitarse ni cambiar de fecha (`409 inf.conflict.day_has_evolution`) [DERIVADA: la evolución es histórico]. Admitido también publicado, como el parte (`RN-INF-28`) [DERIVADA] |
| `PATCH /infant-adaptation-plan-days/{public_id}` | `adaptacion_infantil.actualizar` · `todos`, `grupo` | `evolution` (texto libre con la advertencia de `RN-INF-23`). `422 inf.validation.date_in_future` (`RN-INF-62`, `CA-INF-062`) |
| `POST /infant-adaptation-plans/{public_id}/publication` | `adaptacion_infantil.publicar` · `todos`, `grupo` | `borrador → publicado`; repetido, `200` sin cambios |
| `DELETE /infant-adaptation-plans/{public_id}` | `adaptacion_infantil.eliminar` · `todos` | `204` (borrado lógico) |

---

## 5. Paginación, filtrado y ordenación

| Listado | Paginación | Motivo |
|---------|------------|--------|
| `GET /infant-daily-logs` | **Cursor** | Tabla de mayor crecimiento; histórico recorrido hacia atrás; sin total |
| `GET /infant-ratio-exceptions`, `/infant-ratio-records`, `/infant-development-reports`, `/infant-adaptation-plans` | Página | Conjuntos acotados por curso y grupo; la pantalla muestra total |
| Configuración (`/infant-cycle-levels`, `/infant-groups`, `/infant-ratio-rules`, `/infant-ratio-status`, `/infant-cycle-authorizations`, `/infant-development-indicators`) y `/infant-daily-logs/day` | Sin paginar | Decenas de filas como mucho |

Ambas elecciones [DERIVADA], a confirmar contra el criterio objetivo de `ADR-038 §4`. Filtros solo los listados en cada *endpoint*; ningún listado admite `q` (no hay búsqueda por nombre en `inf`; el nombre vive en `Core`). Orden estable con desempate por `id`.

## 6. Exportación de listados

**Ninguna** en estas entregas (`permisos.md §2.1`). El PDF del histórico del parte y del informe es `1.18-g`. Cuando llegue: en cola (`INV-012`), con enlace caducable, permiso `*.exportar` propio y auditoría `exported` con el detalle.

## 7. Eventos de dominio emitidos

Ninguno en `1.18-a` a `-d` (`ADR-048`, `funcional.md §7.3`). Cuando exista el motor de notificaciones de `ADR-079` (PROPUESTA; recomienda motor en `Core` con tipos de aviso declarados por cada módulo), `1.18-j` declarará los avisos de publicación con **enlace autenticado** y sin datos del parte o del informe en el cuerpo ni como adjunto (`RN-INF-73`).

## 8. Webhooks disponibles

Ninguno.

## 9. Resumen de códigos de error propios

Todos con clave de traducción en `lang/{es,en,de,fr}/inf.php` (`AR-12`).

| Código | HTTP | Dónde |
|--------|------|-------|
| `inf.conflict.ratio_exceeded` | 409 | Veto en `alum` (`§1.8`) |
| `inf.conflict.level_has_groups`, `age_band_in_use`, `exception_not_open` | 409 | `§1` |
| `inf.conflict.client_ref_reused` | 409 | `§2` |
| `inf.conflict.report_not_editable`, `report_immutable`, `report_invalid_transition`, `report_not_rectifiable`, `indicator_in_use` | 409 | `§3` |
| `inf.conflict.day_has_evolution` | 409 | `§4` |
| `inf.forbidden.self_validation` | 403 | `§3` |
| `inf.errors.read_rate_limited` | 429 | Lecturas auditadas |
| `inf.validation.*` | 422 | Los citados en cada *endpoint* |
