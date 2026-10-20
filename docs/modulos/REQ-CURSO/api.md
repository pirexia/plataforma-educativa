# REQ-CURSO · API

> **Estado**: **Implementada el 2026-10-17 en `1.11d-a`** (punto de extensión del cambio de fechas, `RN-CURSO-49` a `-60`, `CA-CURSO-108` a `-122`), **y la reapertura de un curso cerrado** (`ADR-059`, `RN-CURSO-40` a `-48`, `CA-CURSO-087` y `-100` a `-107`, y la mitad de reapertura de `CA-CURSO-121`) **implementada el 2026-10-17 en `1.11c`**. **APROBADA** (2026-10-07), paso **1.10**, con los ajustes de `ADR-057` (aceptado). **Ampliada el 2026-10-08 por `ADR-059`** (aceptado): *endpoint* de reapertura `POST /academic-years/{public_id}/reopen` y sus códigos de error. **Ampliada el 2026-10-12 por `1.11-a`** (`REQ-ACAD-001`): `GET /academic-years/selectable`. **Ampliada el 2026-10-16 por `1.11d-a`** (`REQ-ACAD-001`, `OPEN-ACAD-24` = B, aprobada por el usuario el 2026-10-16): error `409 curso.conflict.date_change_checks_failed` en `PATCH /academic-years/{public_id}` (`funcional.md §5.6`). Sin *endpoints* nuevos. **Completada el 2026-10-16** (`OPEN-CURSO-25` a `-27`, decisiones del usuario): la lectura del curso con bloqueo compartido (`funcional.md §5.6.1`) es una interfaz interna de `Curso\Domain`, **sin efecto HTTP**; el filtro por módulo contratado alcanza también a `closure_checks_failed` y `reopen_checks_failed` (`RN-CURSO-60`), sin cambiar su forma. **Ampliada el 2026-10-20 por `1.12b`** (`REQ-CURSO-002`, apertura del curso siguiente; **APROBADA 2026-10-20 por el usuario**, no implementada): recurso `AcademicYearRollover` y seis *endpoints* bajo `/academic-year-rollovers` (§2.1), con el campo booleano `copy_teaching_assignments` (`OPEN-CURSO-29`, decisión del usuario; `funcional.md RN-CURSO-82`), un `409` nuevo en `POST /academic-years/{public_id}/status` (`rollover_in_progress`, `OPEN-CURSO-36` = A) y los códigos de §5.1.

Prefijo: `/api/v1`. Resolución de tenant por *host* antes de cualquier consulta (`ADR-033 §2`). Todo lo que sigue se ajusta a `ADR-038`: recurso individual desnudo, colección en `{data, meta}` (§3), paginación por página (§4.3), filtros planos con valores múltiples separados por comas (§5.2), orden por lista blanca (§5.3), errores `application/problem+json` con `type` URN y `errors[].{code,message,params}` (§6), `403` frente a `404` (§6.4).

**Todo identificador es `public_id` ULID** (`ADR-029`). `code` del curso **no** es identificador de ruta (`datos.md §5`, `ADR-051`).

Módulo **esencial** (aprobado, `OPEN-CURSO-01`): sus rutas **no** llevan `module-enabled:` y nunca responden `module-disabled`.

---

## 1. Recurso `AcademicYear`

```json
{
  "public_id": "01JA0000000000000000000000",
  "code": "2026-2027",
  "starts_on": "2026-09-01",
  "ends_on": "2027-08-31",
  "status": "planificacion",
  "created_at": "2026-10-07T09:00:00Z",
  "updated_at": "2026-10-07T09:00:00Z"
}
```

- `status` viaja **sin traducir** (`ADR-038 §3.2`); la SPA traduce la etiqueta. Enumerado **declarado extensible** en OpenAPI (`ADR-038 §7.3`) aunque los cuatro valores existan desde 0.8, para no atar a los clientes si `archivado` llega con matices.
- No se exponen `id`, `tenant_id`, `created_by`, `updated_by` ni `deleted_at`.

---

## 2. Endpoints

### `GET /api/v1/academic-years`

- **Permiso**: `curso_academico` · `leer` · `todos`
- **Paginación**: por página (`ADR-038 §4.2`: catálogo de entidades nacidas de acción administrativa). `page`, `per_page` (defecto 25, máximo 100; más ⇒ `422`).
- **Filtros**: `status` (enumerado, múltiple: `status=activo,cerrado`). Sin `q` (decenas de filas por centro; ningún requisito pide búsqueda).
- **Orden**: `sort` ∈ {`starts_on`, `-starts_on`, `code`, `-code`}; defecto **`-starts_on`**, desempate por `id DESC` (determinista, `ADR-038 §5.3`).
- **Respuesta 200**: `{"data": [AcademicYear…], "meta": {"current_page", "per_page", "total", "last_page"}}`
- **Errores**: 401, 403, 422 (parámetro conocido con valor inválido)
- **Idempotencia**: lectura

### `GET /api/v1/academic-years/current`

Curso **activo** del centro. Consumidor: el selector y la cabecera de las pantallas con datos por curso.

- **Permiso**: **autoservicio sin permiso** (por identidad del portador de la cookie, como `GET /me`), aprobado en `OPEN-CURSO-15`. Amplía con esta única ruta la lista cerrada de excepciones de `AR-07a` (`RouteAuthorizationTest.php`), ampliación aprobada por el usuario con esa recomendación.
- **Respuesta 200**: el `AcademicYear` activo.
- **Errores**: 401; **404** `urn:pge:error:not-found` con `errors.academic_year[0].code = "curso.no_active_year"` cuando no hay curso activo (`funcional.md §6`). [DERIVADA] Se elige `404` y no `200` con cuerpo `null` para mantener el recurso individual desnudo (`ADR-038 §3.1`) y que el cliente lo trate como estado conocido por `code`.
- **Orden de rutas**: se registra **antes** que `/{public_id}` (o con restricción de formato ULID en el parámetro) para que `current` no se interprete como un `public_id`.

### `GET /api/v1/academic-years/selectable`

Opciones del selector de curso académico (añadido en `1.11-a`, `REQ-ACAD-001`, `OPEN-ACAD-11`, issue #409, `CA-ACAD-043`). `GET /academic-years` exige `curso_academico.leer`, que casi ningún rol tiene, y un selector que solo funcionara para el administrador no serviría.

- **Permiso**: **autoservicio sin permiso** (por identidad del portador de la cookie), como `current`. Amplía con esta única ruta la lista cerrada de excepciones de `AR-07a` (hoy 35 rutas), ampliación aprobada por el usuario con la especificación de `1.11`.
- **Respuesta 200**: `{"data": [AcademicYear…]}`, sin `meta` (no se pagina: decenas de filas por centro) y sin parámetros. Siempre los cursos `activo` y `planificacion` del centro; con `curso_historico.leer` (`RN-CURSO-25`), también `cerrado` y `archivado`. Orden `-starts_on`, desempate `id DESC`. Nunca cursos de otro centro (`TenantScope`).
- **Errores**: 401. Sin `403`: no hay permiso que comprobar; el permiso solo decide qué estados incluye.
- **Orden de rutas**: se registra **antes** que `/{public_id}`.
- **No sustituye a `GET /academic-years`**: quien no tiene `curso_academico.leer` sigue recibiendo `403` allí. La lectura de los *datos* de un curso cerrado sigue protegida por `AcademicYearReadAccess` en cada módulo: que el selector ofrezca un curso no concede su lectura.

### `GET /api/v1/academic-years/{public_id}`

- **Permiso**: `curso_academico` · `leer` · `todos`
- **Respuesta 200**: `AcademicYear`
- **Errores**: 401, 403, 404 (inexistente o de otro centro)

### `POST /api/v1/academic-years`

- **Permiso**: `curso_academico` · `crear` · `todos`
- **Cuerpo**:

```json
{ "code": "2027-2028", "starts_on": "2027-09-01", "ends_on": "2028-08-31" }
```

- **Validación** (`INV-010`, `funcional.md §5.1`): `code` obligatorio, recortado, no vacío, único en el centro; fechas obligatorias en `AAAA-MM-DD`; `ends_on > starts_on`; sin solape (`RN-CURSO-05`); **`status` no admitido** (`422`, `RN-CURSO-03`).
- **Respuesta 201**: `AcademicYear` con `status: "planificacion"` y cabecera `Location`.
- **Errores**: 401, 403, 409 (`curso.conflict.planning_exists`), 422
- **Idempotencia**: sin `Idempotency-Key` (`INV-011` la exige en pagos, matrículas y envíos masivos; aquí la unicidad del código y el índice de planificación hacen que un reintento produzca `409`/`422`, no un duplicado)

### `PATCH /api/v1/academic-years/{public_id}`

- **Permiso**: `curso_academico` · `actualizar` · `todos`
- **Cuerpo**: cualquier subconjunto de `code`, `starts_on`, `ends_on` (semántica de `PATCH` de `ADR-038`). `status` ⇒ `422 curso.validation.status_not_editable`.
- **Precondición**: curso en `planificacion` (`RN-CURSO-06`, `OPEN-CURSO-10`), evaluada bajo `FOR UPDATE` sobre la fila del curso desde `1.11d-a` (`RN-CURSO-51`).
- **Validaciones del cambio de fechas** (desde `1.11d-a`, `funcional.md §5.6`): si `starts_on` o `ends_on` resultantes difieren de los actuales, y solo tras pasar las validaciones propias (`422`), se ejecutan las validaciones registradas por los módulos contratados en el centro (`RN-CURSO-50` a `-52`). La primera es de `acad`: ningún período lectivo vivo puede quedar fuera de las fechas nuevas (`RN-ACAD-62`).
- **Respuesta 200**: `AcademicYear` completo.
- **Errores**:
  - 401, 403, 404
  - 409 `curso.conflict.not_editable` — el curso no está en `planificacion`
  - 409 `curso.conflict.date_change_checks_failed` — alguna validación del cambio de fechas falla; una entrada por validación en `errors.date_change[]`, con el `code`/`message`/`params` del módulo dueño (`RN-CURSO-53`). Nada cambia
  - 422
- **Idempotencia**: sí (naturalmente repetible)

Ejemplo de `409 date_change_checks_failed` con la entrada de `acad` (`code`, `message` y `params` fijados por `REQ-ACAD`, `RN-ACAD-62`; `params` sin datos personales ni identificadores de períodos, `RN-CURSO-53`):

```json
{
  "type": "urn:pge:error:conflict",
  "title": "…",
  "status": 409,
  "detail": "Las nuevas fechas del curso académico dejan fuera datos que dependen de ellas.",
  "instance": "/api/v1/academic-years/01JA0000000000000000000000",
  "request_id": "01J8…",
  "errors": {
    "date_change": [
      {
        "code": "acad.conflict.periods_outside_academic_year",
        "message": "Hay períodos lectivos que quedarían fuera del curso académico: sus fechas deben incluir del 2026-09-08 al 2027-06-20.",
        "params": { "count": 1, "periods_starts_on": "2026-09-08", "periods_ends_on": "2027-06-20" }
      }
    ]
  }
}
```

### `POST /api/v1/academic-years/{public_id}/status`

Transición de estado. Forma del precedente `POST /users/{public_id}/status` de `REQ-CORE`.

- **Permiso**: `estado_curso_academico` · `actualizar` · `todos` (`OPEN-CURSO-13`)
- **Cuerpo**: `{"status": "activo"}` o `{"status": "cerrado"}`. Otros valores ⇒ `422`.
- **Respuesta 200**: `AcademicYear` con el estado nuevo.
- **Errores**:
  - 401, 403, 404
  - 409 `curso.conflict.active_exists` — activar con otro activo; `params`: `public_id`, `code` del activo
  - 409 `curso.conflict.invalid_transition` — `params`: `from`, `to`. Incluye `cerrado → activo`: la reapertura no se ejecuta por este *endpoint* sino por `…/reopen` (`funcional.md RN-CURSO-12`, `RN-CURSO-40`)
  - 409 `curso.conflict.closure_checks_failed` — alguna validación de cierre registrada falla; una entrada por validación en `errors.closure[]`, cada una con el `code`/`message`/`params` que aporte el módulo dueño de la validación (`RN-CURSO-30`; vacío en 1.10). Desde `1.11d-a`, solo cuentan las validaciones de módulos contratados en el centro (`RN-CURSO-60`)
  - 409 `curso.conflict.rollover_in_progress` — (`1.12b`, `OPEN-CURSO-36` = A, aprobada) activar un curso que es destino de una copia en `pendiente`, `en_curso` o `revirtiendo`; `params`: `public_id`, `status` de la copia (`funcional.md RN-CURSO-71`)
  - 422 — `status` ausente o fuera de {`activo`, `cerrado`}
- **Idempotencia**: no. Repetir una transición ya hecha da `409 invalid_transition` (`activo → activo`), que es lo correcto: el cliente sabe que no ha cambiado nada.

### `POST /api/v1/academic-years/{public_id}/reopen`

Reapertura de un curso cerrado (`cerrado → activo`), `ADR-059`; flujo en `funcional.md §4.6`, reglas `RN-CURSO-40` a `-48`. *Endpoint* propio y no un valor más de `…/status` (`ADR-059 §5.1`): exige un campo obligatorio que las otras transiciones no tienen y un permiso distinto, y autorizar según el cuerpo no lo ve `AR-07`.

- **Permiso**: `reapertura_curso_academico` · `actualizar` · `todos` (`OPEN-059-02`, resuelta). `estado_curso_academico.actualizar` no basta.
- **Cuerpo**:

```json
{ "reason": "Se cerró por error antes de las evaluaciones extraordinarias." }
```

- **Validación** (`INV-010`): `reason` obligatorio, texto, recortado, no vacío (`RN-CURSO-43`), de **como mucho 1000 caracteres** (límite de implementación, el mismo que los demás motivos del producto; más largo es el `422` de forma ordinario).
- **Precondiciones**, comprobadas dentro de la transacción tras el `FOR UPDATE` sobre la fila del curso, en este orden **[DERIVADA]** (`RN-CURSO-41`): curso en `cerrado`; ningún otro curso `activo`; es el cerrado más reciente del centro; ninguna validación de reapertura registrada falla. **Sin plazo de fecha** (`OPEN-059-03`, resuelta).
- **Respuesta 200**: `AcademicYear` con `status: "activo"` (`CA-059-01`). La fila de `academic_year_reopenings` no se expone: ningún requisito pide un *endpoint* de lectura de reaperturas; la consulta es por `audit_logs` (`created` de `academic_year_reopening`) **[DERIVADA]**.
- **Errores**:
  - 401, 403, 404 (inexistente o de otro centro, `CA-059-08`)
  - 409 `curso.conflict.invalid_transition` — el curso no está en `cerrado` (`planificacion`, `activo`, `archivado`); `params`: `from`, `to`
  - 409 `curso.conflict.active_exists` — hay otro curso `activo`, incluida la carrera con una activación simultánea traducida desde el índice único; `params`: `public_id`, `code` del activo
  - 409 `curso.conflict.reopen_not_latest` — existe un curso `cerrado` o `archivado` con `starts_on` posterior; `params`: `public_id`, `code` de ese curso **[DERIVADA]**
  - 409 `curso.conflict.reopen_checks_failed` — alguna validación de reapertura registrada falla; una entrada por validación en `errors.reopen[]`, con el `code`/`message`/`params` del módulo dueño (`RN-CURSO-45`; vacío hoy; solo validaciones de módulos contratados, `RN-CURSO-60`). Forma simétrica a `closure_checks_failed` **[DERIVADA]**
  - 422 `curso.validation.reason_required` — `reason` ausente o vacío tras recortar
- **Idempotencia**: no. Repetir una reapertura ya hecha da `409 invalid_transition` (`activo → activo`): el cliente sabe que no ha cambiado nada y no se crea una segunda fila de reapertura.

## 2.1 Apertura del curso siguiente (*rollover*, `1.12b`, APROBADA 2026-10-20)

Flujo en `funcional.md §4.7`; reglas `RN-CURSO-61` a `-82`; participante de `acad` en `REQ-ACAD/funcional.md §21`. Recurso plano `/academic-year-rollovers` **[DERIVADA]**: la copia tiene identidad propia (estado consultable, reversión), como `data_exports`. **Orden de rutas**: `elements` y `preview` se registran **antes** que `/{public_id}` (o con restricción de formato ULID en el parámetro), como `current`/`selectable`. Módulo esencial: sin `module-enabled:`.

### Representaciones

**`AcademicYearRolloverElement`** (catálogo):

```json
{
  "code": "acad.groups",
  "module": "acad",
  "label": "Grupos",
  "requires": ["acad.courses"],
  "allowed": true,
  "bundle": null
}
```

`label` lo resuelve el módulo dueño en el idioma de la petición; `allowed` = el usuario tiene el `createPermission` del elemento (`RN-CURSO-78`). El permiso en sí no se expone. `bundle`: `null`, o `"teaching_assignments"` para los elementos que solo se copian con `copy_teaching_assignments: true` y no pueden ir en `elements` (`RN-CURSO-82`); la interfaz los presenta como **una** casilla.

**`AcademicYearRolloverPreview`** (simulación):

```json
{
  "academic_year": { "public_id": "01JB…", "code": "2027-2028", "status": "planificacion", "starts_on": "2027-09-01", "ends_on": "2028-08-31" },
  "source": { "public_id": "01JA…", "code": "2026-2027", "status": "activo", "starts_on": "2026-09-01", "ends_on": "2027-08-31" },
  "date_offset_days": 365,
  "copy_teaching_assignments": true,
  "elements": [
    { "code": "acad.education_levels", "label": "Etapas", "to_copy": 2, "skipped": [], "adjusted": [], "date_range": null, "blocking": [] },
    { "code": "acad.academic_periods", "label": "Períodos lectivos", "to_copy": 3, "skipped": [], "adjusted": [], "date_range": { "starts_on": "2027-09-08", "ends_on": "2028-06-19" }, "blocking": [] },
    { "code": "acad.timetable_sessions", "label": "Sesiones del horario", "to_copy": 28, "skipped": [{ "reason": "teacher_inactive", "label": "Profesorado que ya no está activo", "count": 2 }], "adjusted": [{ "reason": "facility_removed", "label": "Se copian sin aula (aula dada de baja)", "count": 1 }], "date_range": null, "blocking": [] }
  ],
  "blocking": [],
  "executable": true
}
```

- Los resúmenes de curso son la forma pública de `AcademicYear` reducida a esos campos (sin fechas de auditoría).
- `copy_teaching_assignments`: el valor pedido (`RN-CURSO-82`). Con `false`, `elements` no incluye los elementos del paquete.
- `skipped` (filas del origen que no se copian) y `adjusted` (filas que se copian con algún cambio): `[{ "reason": "<código del módulo>", "label": "<texto traducido por el módulo>", "count": n }]` **[DERIVADA: `label`]**, para que la SPA no tenga que conocer los motivos de cada módulo. Sin nombres ni identificadores de personas (`RN-CURSO-73`). Con `acad` hoy: `teacher_inactive`, `tutor_inactive`, `frames_not_copied` en `skipped` y `facility_removed` en `adjusted`, solo en los elementos del paquete (`REQ-ACAD/funcional.md §21`); los demás elementos los llevan vacíos.
- `date_range`: menor inicio y mayor fin resultantes, solo en elementos con fechas; `null` en el resto.
- `blocking` de un elemento y `blocking` general (incidencias de `curso`, `RN-CURSO-66`): `[{ "code", "message", "params", "element" }]`, mensajes traducidos; sin datos personales.

**`AcademicYearRollover`** (copia):

```json
{
  "public_id": "01JC…",
  "academic_year": "01JB…",
  "source_academic_year": "01JA…",
  "status": "completado",
  "elements": ["acad.education_levels", "acad.courses"],
  "copy_teaching_assignments": false,
  "date_offset_days": 365,
  "result": { "elements": [ { "code": "acad.education_levels", "copied": 2, "skipped": [], "adjusted": [] }, { "code": "acad.courses", "copied": 3, "skipped": [], "adjusted": [] } ] },
  "failure": null,
  "revert_failure": null,
  "revertible": true,
  "started_at": "2027-05-10T08:00:01Z",
  "finished_at": "2027-05-10T08:00:04Z",
  "reverted_at": null,
  "created_at": "2027-05-10T08:00:00Z",
  "updated_at": "2027-05-10T08:00:04Z"
}
```

- `status` sin traducir (`ADR-038 §3.2`), enumerado declarado extensible (`§7.3`): `pendiente`, `en_curso`, `completado`, `fallido`, `revirtiendo`, `revertido`.
- `academic_year` y `source_academic_year`: `public_id` de destino y origen (convención de §3).
- `elements`: la selección efectiva (con la casilla, incluye los elementos del paquete); `copy_teaching_assignments`: el valor pedido. En `result`, `skipped` y `adjusted` llevan `{reason, count}`; la etiqueta se resuelve al leer **[DERIVADA]**.
- `failure`/`revert_failure`: `{ "code": "rollover_blocked" | "target_not_planning" | "target_changed" | "internal_error", "errors": [{ "code", "message", "params", "element" }] }` o `null`. Los `message` se guardan traducidos al idioma de quien pidió la operación **[DERIVADA]**; la SPA traduce `code` con su catálogo y usa `message` solo para las entradas de módulos.
- `revertible`: calculado (`status = completado` y destino en `planificacion`); orientativo: el servidor decide al revertir (`INV-002`).
- **No** se exponen `id`, `tenant_id`, `created_by`, `reverted_by`, `deleted_at` ni los *items* **[DERIVADA]**: quién hizo la operación está en `audit_logs`; los *items* son internos.

### `GET /api/v1/academic-year-rollovers/elements`

- **Permiso**: `apertura_curso_academico` · `crear` · `todos`.
- **Respuesta 200**: `{"data": [AcademicYearRolloverElement…]}` sin `meta` (catálogo cerrado declarado en código, decenas como mucho; no se pagina). Orden: el de `RN-CURSO-62` (módulos en orden de dependencia; dentro de cada módulo, el que declare). Solo módulos utilizables en el centro.
- **Errores**: 401, 403.

### `GET /api/v1/academic-year-rollovers/preview`

Simulación (*dry-run*), `RN-CURSO-65`. Sin efectos: ni escribe ni bloquea filas.

- **Permiso**: `apertura_curso_academico` · `crear` · `todos`, y el `createPermission` de cada elemento pedido (`RN-CURSO-78`).
- **Parámetros**: `academic_year` (destino, `public_id`, **obligatorio**: no rige aquí el «omitido ⇒ activo» de §3, porque el destino nunca es el activo), `source` (`public_id`, obligatorio), `elements` (códigos separados por comas, `ADR-038 §5.2`; sin elementos de paquete), `copy_teaching_assignments` (`true` o `false`; omitido ⇒ `false`; otro valor ⇒ `422` de forma) (`RN-CURSO-82`).
- **Respuesta 200**: `AcademicYearRolloverPreview`, también cuando `executable` es `false` (las incidencias son el contenido del informe, no un error).
- **Errores**: 401; 403 (sin el permiso propio; o sin el de algún elemento, con `errors.elements[]` de entradas `curso.rollover.element_forbidden`, `params.element`); 409 `curso.conflict.rollover_target_not_planning`; 422 (`curso.validation.rollover_target_not_found`, `rollover_source_required`, `rollover_source_not_found`, `rollover_source_invalid`, `rollover_elements_required`, `rollover_element_unknown`, `rollover_element_not_selectable`, `rollover_element_requires`, `rollover_teaching_assignments_unavailable`).
- **Idempotencia**: lectura.

### `POST /api/v1/academic-year-rollovers`

Solicitud de ejecución, `RN-CURSO-68`.

- **Permiso**: el de la simulación.
- **Cuerpo**:

```json
{ "academic_year": "01JB…", "source": "01JA…", "elements": ["acad.education_levels", "acad.courses", "acad.groups"], "copy_teaching_assignments": false }
```

`copy_teaching_assignments`: booleano JSON, opcional, por defecto `false` (`RN-CURSO-82`); con `true`, la selección del ejemplo daría `422 rollover_element_requires` (faltan `acad.subjects`, `acad.group_subjects` y `acad.subgroups`).

- **Respuesta 202**: `AcademicYearRollover` en `pendiente`, cabecera `Location: /api/v1/academic-year-rollovers/{public_id}`. El trabajo se encola tras confirmar.
- **Errores**: 401; 403 (como la simulación); 409 `rollover_target_not_planning`, `rollover_exists` (`params.public_id`, `params.status`), `rollover_blocked` (una entrada por incidencia en `errors.rollover[]`, con `element`); 422 (los de la simulación).
- **Idempotencia**: sin `Idempotency-Key` (`INV-011` no la exige aquí); repetir con una copia viva da `409 rollover_exists` (`RN-CURSO-81`).

Ejemplo de `409 rollover_blocked`:

```json
{
  "type": "urn:pge:error:conflict",
  "title": "…",
  "status": 409,
  "detail": "La copia no se puede ejecutar: revisa los motivos.",
  "instance": "/api/v1/academic-year-rollovers",
  "request_id": "01J8…",
  "errors": {
    "rollover": [
      {
        "code": "acad.rollover.target_not_empty",
        "message": "El curso de destino ya tiene datos de «Etapas» (1). Quítalos de la selección o bórralos antes.",
        "params": { "element": "acad.education_levels", "count": 1 }
      }
    ]
  }
}
```

### `GET /api/v1/academic-year-rollovers`

- **Permiso**: `apertura_curso_academico` · `leer` · `todos`.
- **Paginación**: por página (`ADR-038 §4.2`), `page`, `per_page` (defecto 25, máximo 100).
- **Filtros**: `academic_year` (destino; omitido ⇒ curso activo, convención de §3; sin activo ⇒ `404 curso.no_active_year`), `source` (`public_id`), `status` (enumerado, múltiple). Destino `cerrado`/`archivado` sin `curso_historico.leer` ⇒ `404` (`RN-CURSO-80`, `-33`); `academic_year` o `source` de otro centro o inexistentes ⇒ `404`.
- **Orden**: `sort` ∈ {`created_at`, `-created_at`}; defecto `-created_at`, desempate `id DESC`.
- **Respuesta 200**: `{"data": [AcademicYearRollover…], "meta": {…}}`.
- **Errores**: 401, 403, 404, 422.

### `GET /api/v1/academic-year-rollovers/{public_id}`

- **Permiso**: `apertura_curso_academico` · `leer` · `todos`; además `AcademicYearReadAccess` sobre el destino (`RN-CURSO-80`).
- **Respuesta 200**: `AcademicYearRollover`.
- **Errores**: 401, 403, 404 (inexistente, de otro centro, o destino cerrado sin `curso_historico.leer`).

### `POST /api/v1/academic-year-rollovers/{public_id}/revert`

Solicitud de reversión, `RN-CURSO-74`.

- **Permiso**: `apertura_curso_academico` · `eliminar` · `todos`. **Sin cuerpo.**
- **Respuesta 202**: `AcademicYearRollover` en `revirtiendo`. El trabajo se encola tras confirmar.
- **Errores**: 401, 403, 404; 409 `rollover_not_revertible` (`params.status`), `rollover_target_not_planning`, `rollover_revert_module_unavailable` (`params.module`), `rollover_revert_blocked` (una entrada por incidencia en `errors.rollover_revert[]`).
- **Idempotencia**: no; repetir da `409 rollover_not_revertible` (`revirtiendo`/`revertido`).

### Endpoints que **no** existen

| No existe | Motivo |
|-----------|--------|
| `DELETE /academic-years/{id}` | `OPEN-CURSO-12` |
| Transición a `archivado` | `OPEN-CURSO-09` |
| Reapertura fuera de la ventana T1, o de un curso `archivado` | `ADR-059 §3`, `RN-CURSO-47` |
| Lectura o modificación de `academic_year_reopenings` | *Append-only*; ningún requisito pide su lectura por API |
| `POST /academic-years/exports` | Sin `curso_academico.exportar` (`permisos.md §2.1`) |
| Promoción, renovación, paquete de cierre | `funcional.md §1.2` |
| Borrado o modificación de una copia (`DELETE`/`PATCH /academic-year-rollovers/{id}`) | Una copia no se borra ni se edita: se revierte (`RN-CURSO-74`) **[DERIVADA]** |
| Lectura de `year_rollover_items` | Internos de la reversión; ningún requisito pide verlos (`funcional.md §1.4`) |
| Creación del curso destino dentro de la copia | Lo crea `POST /academic-years` (`RN-CURSO-63`, `OPEN-CURSO-31` = A) |

---

## 3. Convención para recursos de **otros** módulos que dependen del curso

Convención de `funcional.md §3.2`, **ratificada sin cambios por `ADR-057 §5.8`**. La implementarán `ACAD` y siguientes, no 1.10.

| Caso | Convención |
|------|------------|
| Colección dependiente del curso | Parámetro de consulta **`academic_year`** = `public_id`. Omitido ⇒ curso activo; sin activo ⇒ `404` con `curso.no_active_year`. Un `public_id` inexistente o de otro centro ⇒ `404` |
| Recurso individual dependiente del curso | Su representación incluye **`academic_year`** con el `public_id` del curso (no el objeto anidado) |
| Alta de una entidad sin padre con curso | El cuerpo lleva `academic_year` explícito; **no hay curso por omisión en escrituras** (`RN-CURSO-22`) |
| Escritura sobre entidad de curso de solo lectura | `409` `urn:pge:error:academic-year-closed` (§5) |
| Lectura de curso `cerrado`/`archivado` sin `curso_historico.leer` | `404` (`RN-CURSO-25`) |
| Exportación de un listado dependiente del curso | `academic_year` es un filtro estructurado más y entra en la paridad de filtros (`ADR-054 §8.2`) |

El nombre del parámetro, `academic_year`, sigue `snake_case` y el criterio de `ADR-038 §5.2` (planos y nombrados) y coincide con el nombre del campo en la representación.

---

## 4. Ejemplo de error de curso de solo lectura

```json
{
  "type": "urn:pge:error:academic-year-closed",
  "title": "El curso académico está cerrado",
  "status": 409,
  "detail": "El curso 2025-2026 está cerrado y sus datos son de solo lectura. No se pueden crear, modificar ni eliminar datos de un curso cerrado.",
  "instance": "/api/v1/…",
  "request_id": "01J8…",
  "errors": {
    "academic_year": [
      { "code": "curso.academic_year_closed", "message": "…", "params": { "code": "2025-2026", "status": "cerrado" } }
    ]
  }
}
```

`title`, `detail` y `message` salen del catálogo de `curso` en el idioma resuelto (`ADR-038 §6.3`, `§11`). Es el «indica el motivo» del criterio de aceptación de `§5.28`.

**Origen del error** (`ADR-057 §5.5`): el disparador de base de datos lanza `SQLSTATE` **`YC001`** con el `public_id` del curso en el mensaje. El módulo `curso` registra en su `ServiceProvider` el mapeo de `QueryException` con `YC001` a su excepción de dominio de solo lectura, que el `ProblemResponseFactory` existente presenta como cualquier otro error de API; el núcleo no conoce el `SQLSTATE`. El traductor resuelve `params.code` y `params.status` por el modelo de `Curso`; si no puede (p. ej. transacción abortada sin revertir), responde igualmente `409` con el mismo `type` y un `detail` sin código de curso. La comprobación previa consultiva `AcademicYearWriteGuard` produce la misma respuesta.

---

## 5. Catálogo de errores del módulo

| `type` | Estado | `code` | Cuándo |
|--------|--------|--------|--------|
| `urn:pge:error:validation` | 422 | `curso.validation.code_required` | Código vacío tras recortar |
| `urn:pge:error:validation` | 422 | `curso.validation.code_taken` | Código ya usado en el centro (incluida la carrera sobre el índice único) |
| `urn:pge:error:validation` | 422 | `curso.validation.ends_before_start` | `ends_on <= starts_on` |
| `urn:pge:error:validation` | 422 | `curso.validation.dates_overlap` | Solape (`RN-CURSO-05`); `params.code` |
| `urn:pge:error:validation` | 422 | `curso.validation.status_not_editable` | `status` en `POST`/`PATCH` |
| `urn:pge:error:validation` | 422 | `curso.validation.reason_required` | Reapertura sin motivo o con motivo vacío tras recortar (`ADR-059`, `RN-CURSO-43`) |
| `urn:pge:error:conflict` | 409 | `curso.conflict.planning_exists` | Ya hay un curso en planificación |
| `urn:pge:error:conflict` | 409 | `curso.conflict.active_exists` | Ya hay un curso activo |
| `urn:pge:error:conflict` | 409 | `curso.conflict.invalid_transition` | Transición no admitida |
| `urn:pge:error:conflict` | 409 | `curso.conflict.not_editable` | `PATCH` fuera de `planificacion` |
| `urn:pge:error:conflict` | 409 | `curso.conflict.closure_checks_failed` | Checklist de cierre (vacío en 1.10) |
| `urn:pge:error:conflict` | 409 | `curso.conflict.reopen_not_latest` | Reapertura de un curso que no es el cerrado más reciente (`ADR-059`, `RN-CURSO-41`) |
| `urn:pge:error:conflict` | 409 | `curso.conflict.reopen_checks_failed` | Validaciones de reapertura (vacías hoy, `RN-CURSO-45`) |
| `urn:pge:error:conflict` | 409 | `curso.conflict.date_change_checks_failed` | `PATCH` de fechas rechazado por alguna validación registrada por un módulo contratado; entradas en `errors.date_change[]` (`RN-CURSO-49` a `-53`, `1.11d-a`) |
| `urn:pge:error:not-found` | 404 | `curso.no_active_year` | Sin curso activo (`current` y lecturas por omisión) |
| **`urn:pge:error:academic-year-closed`** (nuevo: ampliación del catálogo cerrado de `ADR-038 §6.2` por `ADR-057 §5.5`) | 409 | `curso.academic_year_closed` | Escritura sobre datos de un curso `cerrado`/`archivado`, desde cualquier módulo; traducido desde `SQLSTATE` `YC001` del disparador (§4) |

**Riesgo conocido**: issue #60 (`ValidationErrorFormatter` antepone `core.` al `code` de cualquier módulo). `curso` es el primer módulo de negocio con códigos propios y lo comprobará de verdad (`funcional.md §14`).

Todos los mensajes, en `lang/{es,en,de,fr}/curso.php` (`INV-009`, `AR-12`).

Clave nueva de `1.11d-a` (las traducciones `en`/`de`/`fr` son textos de partida y se revisan al implementar con el agente `traductor`; no son textos legales):

| Clave | es | en | de | fr |
|-------|----|----|----|----|
| `curso.conflict.date_change_checks_failed` | Las nuevas fechas del curso académico dejan fuera datos que dependen de ellas. | The new dates of the academic year leave out data that depends on them. | Die neuen Daten des Schuljahres schließen davon abhängige Daten aus. | Les nouvelles dates de l’année scolaire excluent des données qui en dépendent. |

### 5.1 Códigos de `1.12b` (apertura del curso siguiente, APROBADA 2026-10-20)

| `type` | Estado | `code` | Cuándo |
|--------|--------|--------|--------|
| `urn:pge:error:validation` | 422 | `curso.validation.rollover_target_not_found` | Destino inexistente o de otro centro (`RN-CURSO-63`) |
| `urn:pge:error:validation` | 422 | `curso.validation.rollover_source_required` | Falta `source` |
| `urn:pge:error:validation` | 422 | `curso.validation.rollover_source_not_found` | Origen inexistente, de otro centro, o `cerrado` sin `curso_historico.leer` |
| `urn:pge:error:validation` | 422 | `curso.validation.rollover_source_invalid` | Origen en `planificacion`, igual al destino o no anterior a él |
| `urn:pge:error:validation` | 422 | `curso.validation.rollover_elements_required` | `elements` vacía |
| `urn:pge:error:validation` | 422 | `curso.validation.rollover_element_unknown` | Elemento desconocido o de un módulo no utilizable; `params.element` |
| `urn:pge:error:validation` | 422 | `curso.validation.rollover_element_requires` | Falta un elemento requerido; `params.element`, `params.requires` |
| `urn:pge:error:validation` | 422 | `curso.validation.rollover_element_not_selectable` | Un elemento de paquete enviado en `elements` (`RN-CURSO-82`); `params.element` |
| `urn:pge:error:validation` | 422 | `curso.validation.rollover_teaching_assignments_unavailable` | `copy_teaching_assignments: true` sin ningún elemento del paquete en módulos utilizables (`RN-CURSO-82`) |
| `urn:pge:error:forbidden` | 403 | `curso.rollover.element_forbidden` (entrada de `errors.elements[]`) | Sin el `createPermission` de un elemento (`RN-CURSO-78`); `params.element` |
| `urn:pge:error:conflict` | 409 | `curso.conflict.rollover_target_not_planning` | Destino fuera de `planificacion` (copiar o revertir) |
| `urn:pge:error:conflict` | 409 | `curso.conflict.rollover_exists` | Ya hay una copia viva hacia ese destino; `params.public_id`, `params.status` |
| `urn:pge:error:conflict` | 409 | `curso.conflict.rollover_blocked` | Alguna incidencia impide ejecutar; entradas en `errors.rollover[]` |
| `urn:pge:error:conflict` | 409 | `curso.conflict.rollover_in_progress` | Activar un destino con copia o reversión en vuelo (`OPEN-CURSO-36` = A) |
| `urn:pge:error:conflict` | 409 | `curso.conflict.rollover_not_revertible` | Revertir una copia que no está en `completado`; `params.status` |
| `urn:pge:error:conflict` | 409 | `curso.conflict.rollover_revert_module_unavailable` | Revertir con un módulo participante no utilizable; `params.module` |
| `urn:pge:error:conflict` | 409 | `curso.conflict.rollover_revert_blocked` | Datos añadidos después dependen de lo copiado; entradas en `errors.rollover_revert[]` |

Incidencias propias de `curso` (entradas de `blocking[]`, `errors.rollover[]` o `failure.errors[]`): `curso.rollover.exists`, `curso.rollover.empty`, `curso.rollover.element_unavailable` (módulo descontratado entre la solicitud y la ejecución). Las de cada módulo las fija su especificación (`acad`: `REQ-ACAD/funcional.md §21`, `RN-ACAD-148` y `-150`). Etiquetas de estado de la copia: `curso.rollover.status.<estado>`; motivos de fallo: `curso.rollover.failure.<code>`. Etiqueta del recurso de permiso: `curso.permissions.resources.apertura_curso_academico`.

Textos de partida (no legales; los revisa `traductor` al implementar):

| Clave | es | en | de | fr |
|-------|----|----|----|----|
| `curso.validation.rollover_target_not_found` | El curso académico de destino no existe. | The target academic year does not exist. | Das Zielschuljahr existiert nicht. | L’année scolaire de destination n’existe pas. |
| `curso.validation.rollover_source_required` | Indica el curso académico de origen. | Specify the source academic year. | Gib das Ausgangsschuljahr an. | Indiquez l’année scolaire d’origine. |
| `curso.validation.rollover_source_not_found` | El curso académico de origen no existe. | The source academic year does not exist. | Das Ausgangsschuljahr existiert nicht. | L’année scolaire d’origine n’existe pas. |
| `curso.validation.rollover_source_invalid` | El curso de origen debe estar activo o cerrado y empezar antes que el de destino. | The source year must be active or closed and start before the target year. | Das Ausgangsschuljahr muss aktiv oder abgeschlossen sein und vor dem Zielschuljahr beginnen. | L’année d’origine doit être active ou clôturée et commencer avant l’année de destination. |
| `curso.validation.rollover_elements_required` | Selecciona al menos un elemento que copiar. | Select at least one item to copy. | Wähle mindestens ein Element zum Kopieren aus. | Sélectionnez au moins un élément à copier. |
| `curso.validation.rollover_element_unknown` | No se puede copiar «:element». | “:element” cannot be copied. | „:element“ kann nicht kopiert werden. | « :element » ne peut pas être copié. |
| `curso.validation.rollover_element_requires` | Para copiar «:element» hay que copiar también «:requires». | To copy “:element” you must also copy “:requires”. | Um „:element“ zu kopieren, muss auch „:requires“ kopiert werden. | Pour copier « :element », il faut aussi copier « :requires ». |
| `curso.validation.rollover_element_not_selectable` | «:element» no se selecciona por separado: se copia con la asignación docente. | “:element” is not selected separately: it is copied with the teaching assignments. | „:element“ wird nicht einzeln ausgewählt: Es wird mit der Lehrkräftezuweisung kopiert. | « :element » ne se sélectionne pas séparément : il est copié avec les affectations des enseignants. |
| `curso.validation.rollover_teaching_assignments_unavailable` | No se puede copiar la asignación docente: ningún módulo contratado la ofrece. | Teaching assignments cannot be copied: no subscribed module offers them. | Die Lehrkräftezuweisung kann nicht kopiert werden: Kein gebuchtes Modul bietet sie an. | Les affectations des enseignants ne peuvent pas être copiées : aucun module souscrit ne les propose. |
| `curso.rollover.element_forbidden` | No tienes permiso para crear «:element». | You do not have permission to create “:element”. | Du hast keine Berechtigung, „:element“ anzulegen. | Vous n’avez pas l’autorisation de créer « :element ». |
| `curso.conflict.rollover_target_not_planning` | Solo se puede copiar en un curso académico en planificación, ni revertir una copia de otro. | You can only copy into, or revert a copy of, an academic year in planning. | Nur in ein Schuljahr in Planung kann kopiert oder eine Kopie zurückgesetzt werden. | On ne peut copier vers une année en planification, ni annuler une copie, que si elle est en planification. |
| `curso.conflict.rollover_exists` | Este curso académico ya tiene una copia en curso o completada. Para repetirla, reviértela antes. | This academic year already has a copy in progress or completed. Revert it before repeating it. | Für dieses Schuljahr gibt es bereits eine laufende oder abgeschlossene Kopie. Setze sie zurück, bevor du sie wiederholst. | Cette année scolaire a déjà une copie en cours ou terminée. Annulez-la avant de la recommencer. |
| `curso.conflict.rollover_blocked` | La copia no se puede ejecutar: revisa los motivos. | The copy cannot be run: check the reasons. | Die Kopie kann nicht ausgeführt werden: Prüfe die Gründe. | La copie ne peut pas être exécutée : vérifiez les motifs. |
| `curso.conflict.rollover_in_progress` | Hay una copia o una reversión en curso sobre este curso académico. Espera a que termine. | A copy or a revert is in progress for this academic year. Wait until it finishes. | Für dieses Schuljahr läuft eine Kopie oder eine Rücksetzung. Warte, bis sie abgeschlossen ist. | Une copie ou une annulation est en cours pour cette année scolaire. Attendez qu’elle se termine. |
| `curso.conflict.rollover_not_revertible` | Esta copia no se puede revertir en su estado actual. | This copy cannot be reverted in its current status. | Diese Kopie kann in ihrem aktuellen Status nicht zurückgesetzt werden. | Cette copie ne peut pas être annulée dans son état actuel. |
| `curso.conflict.rollover_revert_module_unavailable` | No se puede revertir: el módulo «:module» no está contratado. | It cannot be reverted: the “:module” module is not subscribed. | Rücksetzung nicht möglich: Das Modul „:module“ ist nicht gebucht. | Annulation impossible : le module « :module » n’est pas souscrit. |
| `curso.conflict.rollover_revert_blocked` | No se puede revertir: hay datos añadidos después que dependen de lo copiado. | It cannot be reverted: data added afterwards depends on what was copied. | Rücksetzung nicht möglich: Später hinzugefügte Daten hängen vom Kopierten ab. | Annulation impossible : des données ajoutées ensuite dépendent de ce qui a été copié. |
| `curso.rollover.empty` | Con esta selección no hay nada que copiar. | There is nothing to copy with this selection. | Mit dieser Auswahl gibt es nichts zu kopieren. | Il n’y a rien à copier avec cette sélection. |
| `curso.rollover.exists` | Este curso académico ya tiene una copia en curso o completada. | This academic year already has a copy in progress or completed. | Für dieses Schuljahr gibt es bereits eine laufende oder abgeschlossene Kopie. | Cette année scolaire a déjà une copie en cours ou terminée. |
| `curso.rollover.element_unavailable` | El módulo de «:element» ya no está contratado. | The module of “:element” is no longer subscribed. | Das Modul von „:element“ ist nicht mehr gebucht. | Le module de « :element » n’est plus souscrit. |
| `curso.rollover.status.pendiente` / `en_curso` / `completado` / `fallido` / `revirtiendo` / `revertido` | En cola / En curso / Completada / Fallida / Revirtiendo / Revertida | Queued / In progress / Completed / Failed / Reverting / Reverted | In der Warteschlange / Läuft / Abgeschlossen / Fehlgeschlagen / Wird zurückgesetzt / Zurückgesetzt | En file d’attente / En cours / Terminée / Échouée / Annulation en cours / Annulée |
| `curso.rollover.failure.rollover_blocked` / `target_not_planning` / `target_changed` / `internal_error` | Algo impedía copiar / El curso de destino ya no está en planificación / Los datos cambiaron mientras se copiaba; vuelve a intentarlo / Error interno; no se ha copiado nada | Something prevented the copy / The target year is no longer in planning / Data changed during the copy; try again / Internal error; nothing was copied | Etwas hat das Kopieren verhindert / Das Zielschuljahr ist nicht mehr in Planung / Die Daten haben sich beim Kopieren geändert; versuche es erneut / Interner Fehler; nichts wurde kopiert | Un obstacle a empêché la copie / L’année de destination n’est plus en planification / Les données ont changé pendant la copie ; réessayez / Erreur interne ; rien n’a été copié |
| `curso.permissions.resources.apertura_curso_academico` | Apertura del curso siguiente | Opening of the next academic year | Eröffnung des nächsten Schuljahres | Ouverture de l’année scolaire suivante |

**Textos de la casilla del asistente** (web, `locales/{es,en,de,fr}.json` de `curso`; nombre final de la clave según la convención de los `locales` del módulo, **[DERIVADA: claves]**; textos de partida no legales, los revisa `traductor`):

| Clave | es | en | de | fr |
|-------|----|----|----|----|
| `rollover.wizard.teachingAssignments.label` | Copiar la asignación docente | Copy teaching assignments | Lehrkräftezuweisung kopieren | Copier les affectations des enseignants |
| `rollover.wizard.teachingAssignments.help` | Copia el profesorado de cada asignatura y grupo y las tutorías; si copias también los marcos horarios, también las sesiones del horario. Se omiten las personas que ya no están activas en el centro. Revisa después lo copiado. | Copies the teachers of each subject and group and the group tutors; if you also copy the timetable frames, the timetable sessions too. People who are no longer active at the school are left out. Review what was copied afterwards. | Kopiert die Lehrkräfte je Fach und Gruppe sowie die Gruppentutorate; wenn du auch die Stundenrahmen kopierst, auch die Stundenplaneinheiten. Nicht mehr aktive Personen werden ausgelassen. Prüfe das Kopierte anschließend. | Copie les enseignants de chaque matière et groupe et les tutorats de groupe ; si vous copiez aussi les cadres horaires, les séances de l’emploi du temps également. Les personnes qui ne sont plus actives dans l’établissement sont omises. Vérifiez ensuite ce qui a été copié. |
| `rollover.wizard.teachingAssignments.forbidden` | No tienes permiso para crear asignaciones docentes, tutorías o sesiones del horario. | You do not have permission to create teaching assignments, group tutors or timetable sessions. | Du hast keine Berechtigung, Lehrkräftezuweisungen, Gruppentutorate oder Stundenplaneinheiten anzulegen. | Vous n’avez pas l’autorisation de créer des affectations des enseignants, des tutorats de groupe ou des séances. |
| `rollover.wizard.teachingAssignments.requiresMarked` | Se han marcado también: :elements. | Also selected: :elements. | Ebenfalls ausgewählt: :elements. | Également sélectionnés : :elements. |

Entradas, etiquetas de elementos y motivos de `acad`: `REQ-ACAD/funcional.md §21.6` (en `lang/{es,en,de,fr}/acad.php`).

---

## 6. Eventos de dominio emitidos

Especificados en `funcional.md §7.2` (`AcademicYearActivated`, `AcademicYearClosed` y, desde `ADR-059`, `AcademicYearReopened`). **No se emiten en 1.10** (aprobado, `OPEN-CURSO-18`): se emiten con su primer consumidor. **Excepción desde `1.11c`**: `AcademicYearReopened` **sí se emite** (`Curso\Domain\Events\AcademicYearReopened`, tras el *commit* de la reapertura, sin el motivo), porque el alcance del paso lo incluye; hoy sin consumidores (`funcional.md §4.6`, «Implementación en `1.11c`»).

## 7. Webhooks disponibles

Ninguno (`REQ-API-002` es de fase 2).

## 8. OpenAPI

Los siete *endpoints* de §2 implementados (los seis de `1.10` más `GET /academic-years/selectable` de `1.11-a`), más `POST /academic-years/{public_id}/reopen` de `ADR-059`, implementado en `1.11c`, y el `409 curso.conflict.date_change_checks_failed` del `PATCH` con su `errors.date_change[]`, implementado en `1.11d-a` (el elemento de `errors.date_change[]` se documenta con la forma genérica `{code, message, params}`; el `code` de `acad` aparece en el ejemplo, no como enumerado cerrado), documentados en `apps/api/openapi/paths/curso.yaml` (`CLAUDE.md §10`), con el esquema `AcademicYear`, el enumerado extensible de `status`, la lista blanca de `sort`, el filtro `status` en estilo `form, explode: false`, y el `type` nuevo de §5 añadido al catálogo de errores documentado. Comprobar al tocarlo el problema de validación YAML preexistente del issue #97.

**`1.12b`** (en la entrega que lo implemente): los seis *endpoints* de §2.1 en `apps/api/openapi/paths/curso.yaml`, con los esquemas `AcademicYearRolloverElement` (con `bundle`), `AcademicYearRolloverPreview` y `AcademicYearRollover` (con `copy_teaching_assignments` y `skipped`/`adjusted`), el parámetro y el campo booleano `copy_teaching_assignments`, el enumerado extensible de `status`, la respuesta `202` con `Location`, las entradas genéricas `{code, message, params, element}` de `errors.rollover[]`, `errors.rollover_revert[]` y `errors.elements[]` (los códigos de módulos, en ejemplos, no como enumerado cerrado) y el `409 rollover_in_progress` de `POST /academic-years/{public_id}/status`.
