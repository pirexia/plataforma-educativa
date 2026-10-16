# REQ-CURSO · API

> **Estado**: **APROBADA** (2026-10-07), paso **1.10**, con los ajustes de `ADR-057` (aceptado). **Ampliada el 2026-10-08 por `ADR-059`** (aceptado): *endpoint* de reapertura `POST /academic-years/{public_id}/reopen` y sus códigos de error. **Ampliada el 2026-10-12 por `1.11-a`** (`REQ-ACAD-001`): `GET /academic-years/selectable`. **Ampliada el 2026-10-16 por `1.11d-a`** (`REQ-ACAD-001`, `OPEN-ACAD-24` = B, aprobada por el usuario el 2026-10-16): error `409 curso.conflict.date_change_checks_failed` en `PATCH /academic-years/{public_id}` (`funcional.md §5.6`). Sin *endpoints* nuevos. **Completada el 2026-10-16** (`OPEN-CURSO-25` a `-27`, decisiones del usuario): la lectura del curso con bloqueo compartido (`funcional.md §5.6.1`) es una interfaz interna de `Curso\Domain`, **sin efecto HTTP**; el filtro por módulo contratado alcanza también a `closure_checks_failed` y `reopen_checks_failed` (`RN-CURSO-60`), sin cambiar su forma.

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
  - 422 — `status` ausente o fuera de {`activo`, `cerrado`}
- **Idempotencia**: no. Repetir una transición ya hecha da `409 invalid_transition` (`activo → activo`), que es lo correcto: el cliente sabe que no ha cambiado nada.

### `POST /api/v1/academic-years/{public_id}/reopen`

Reapertura de un curso cerrado (`cerrado → activo`), `ADR-059`; flujo en `funcional.md §4.6`, reglas `RN-CURSO-40` a `-48`. *Endpoint* propio y no un valor más de `…/status` (`ADR-059 §5.1`): exige un campo obligatorio que las otras transiciones no tienen y un permiso distinto, y autorizar según el cuerpo no lo ve `AR-07`.

- **Permiso**: `reapertura_curso_academico` · `actualizar` · `todos` (`OPEN-059-02`, resuelta). `estado_curso_academico.actualizar` no basta.
- **Cuerpo**:

```json
{ "reason": "Se cerró por error antes de las evaluaciones extraordinarias." }
```

- **Validación** (`INV-010`): `reason` obligatorio, texto, recortado, no vacío (`RN-CURSO-43`).
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

### Endpoints que **no** existen

| No existe | Motivo |
|-----------|--------|
| `DELETE /academic-years/{id}` | `OPEN-CURSO-12` |
| Transición a `archivado` | `OPEN-CURSO-09` |
| Reapertura fuera de la ventana T1, o de un curso `archivado` | `ADR-059 §3`, `RN-CURSO-47` |
| Lectura o modificación de `academic_year_reopenings` | *Append-only*; ningún requisito pide su lectura por API |
| `POST /academic-years/exports` | Sin `curso_academico.exportar` (`permisos.md §2.1`) |
| Rollover, promoción, renovación, paquete de cierre | `funcional.md §1.2` |

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

Clave nueva de `1.11d-a` (propuesta; las traducciones `en`/`de`/`fr` las revisa el agente `traductor`, no son textos legales):

| Clave | es | en | de | fr |
|-------|----|----|----|----|
| `curso.conflict.date_change_checks_failed` | Las nuevas fechas del curso académico dejan fuera datos que dependen de ellas. | The new dates of the academic year leave out data that depends on them. | Die neuen Daten des Schuljahres schließen davon abhängige Daten aus. | Les nouvelles dates de l’année scolaire excluent des données qui en dépendent. |

---

## 6. Eventos de dominio emitidos

Especificados en `funcional.md §7.2` (`AcademicYearActivated`, `AcademicYearClosed` y, desde `ADR-059`, `AcademicYearReopened`). **No se emiten en 1.10** (aprobado, `OPEN-CURSO-18`): se emiten con su primer consumidor.

## 7. Webhooks disponibles

Ninguno (`REQ-API-002` es de fase 2).

## 8. OpenAPI

Los siete *endpoints* de §2 implementados (los seis de `1.10` más `GET /academic-years/selectable` de `1.11-a`), más `POST /academic-years/{public_id}/reopen` de `ADR-059` en la entrega que lo implemente, y el `409 curso.conflict.date_change_checks_failed` del `PATCH` con su `errors.date_change[]` en `1.11d-a` (el elemento de `errors.date_change[]` se documenta con la forma genérica `{code, message, params}`; el `code` de `acad` aparece en el ejemplo, no como enumerado cerrado), en `apps/api/openapi.yaml` antes del merge (`CLAUDE.md §10`), con el esquema `AcademicYear`, el enumerado extensible de `status`, la lista blanca de `sort`, el filtro `status` en estilo `form, explode: false`, y el `type` nuevo de §5 añadido al catálogo de errores documentado. Comprobar al tocarlo el problema de validación YAML preexistente del issue #97.
