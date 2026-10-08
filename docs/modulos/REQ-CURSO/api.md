# REQ-CURSO · API

> **Estado**: **APROBADA** (2026-10-07), paso **1.10**, con los ajustes de `ADR-057` (aceptado).

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
- **Precondición**: curso en `planificacion` (`RN-CURSO-06`, `OPEN-CURSO-10`).
- **Respuesta 200**: `AcademicYear` completo.
- **Errores**: 401, 403, 404, 409 (`curso.conflict.not_editable`), 422
- **Idempotencia**: sí (naturalmente repetible)

### `POST /api/v1/academic-years/{public_id}/status`

Transición de estado. Forma del precedente `POST /users/{public_id}/status` de `REQ-CORE`.

- **Permiso**: `estado_curso_academico` · `actualizar` · `todos` (`OPEN-CURSO-13`)
- **Cuerpo**: `{"status": "activo"}` o `{"status": "cerrado"}`. Otros valores ⇒ `422`.
- **Respuesta 200**: `AcademicYear` con el estado nuevo.
- **Errores**:
  - 401, 403, 404
  - 409 `curso.conflict.active_exists` — activar con otro activo; `params`: `public_id`, `code` del activo
  - 409 `curso.conflict.invalid_transition` — `params`: `from`, `to`
  - 409 `curso.conflict.closure_checks_failed` — alguna validación de cierre registrada falla; una entrada por validación en `errors.closure[]`, cada una con el `code`/`message`/`params` que aporte el módulo dueño de la validación (`RN-CURSO-30`; vacío en 1.10)
  - 422 — `status` ausente o fuera de {`activo`, `cerrado`}
- **Idempotencia**: no. Repetir una transición ya hecha da `409 invalid_transition` (`activo → activo`), que es lo correcto: el cliente sabe que no ha cambiado nada.

### Endpoints que **no** existen en 1.10

| No existe | Motivo |
|-----------|--------|
| `DELETE /academic-years/{id}` | `OPEN-CURSO-12` |
| Transición a `archivado` | `OPEN-CURSO-09` |
| Reapertura (`cerrado → activo`) | `OPEN-CURSO-08` |
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
| `urn:pge:error:conflict` | 409 | `curso.conflict.planning_exists` | Ya hay un curso en planificación |
| `urn:pge:error:conflict` | 409 | `curso.conflict.active_exists` | Ya hay un curso activo |
| `urn:pge:error:conflict` | 409 | `curso.conflict.invalid_transition` | Transición no admitida |
| `urn:pge:error:conflict` | 409 | `curso.conflict.not_editable` | `PATCH` fuera de `planificacion` |
| `urn:pge:error:conflict` | 409 | `curso.conflict.closure_checks_failed` | Checklist de cierre (vacío en 1.10) |
| `urn:pge:error:not-found` | 404 | `curso.no_active_year` | Sin curso activo (`current` y lecturas por omisión) |
| **`urn:pge:error:academic-year-closed`** (nuevo: ampliación del catálogo cerrado de `ADR-038 §6.2` por `ADR-057 §5.5`) | 409 | `curso.academic_year_closed` | Escritura sobre datos de un curso `cerrado`/`archivado`, desde cualquier módulo; traducido desde `SQLSTATE` `YC001` del disparador (§4) |

**Riesgo conocido**: issue #60 (`ValidationErrorFormatter` antepone `core.` al `code` de cualquier módulo). `curso` es el primer módulo de negocio con códigos propios y lo comprobará de verdad (`funcional.md §14`).

Todos los mensajes, en `lang/{es,en,de,fr}/curso.php` (`INV-009`, `AR-12`).

---

## 6. Eventos de dominio emitidos

Especificados en `funcional.md §7.2` (`AcademicYearActivated`, `AcademicYearClosed`). **No se emiten en 1.10** (aprobado, `OPEN-CURSO-18`): se emiten con su primer consumidor.

## 7. Webhooks disponibles

Ninguno (`REQ-API-002` es de fase 2).

## 8. OpenAPI

Los seis *endpoints* de §2 en `apps/api/openapi.yaml` antes del merge (`CLAUDE.md §10`), con el esquema `AcademicYear`, el enumerado extensible de `status`, la lista blanca de `sort`, el filtro `status` en estilo `form, explode: false`, y el `type` nuevo de §5 añadido al catálogo de errores documentado. Comprobar al tocarlo el problema de validación YAML preexistente del issue #97.
