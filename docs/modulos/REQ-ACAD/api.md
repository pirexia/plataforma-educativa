# REQ-ACAD · API

> **Estado**: **APROBADA** (2026-10-10), paso **1.11** (`REQ-ACAD-001`, estructura académica), con las recomendaciones de `funcional.md §15` aprobadas y los contratos de `ADR-062` (contenido traducible) y `ADR-063` (ámbitos), ambos ACEPTADOS. La API de períodos lectivos y escalas está especificada en §11 (`1.11d`, APROBADA el 2026-10-16; `OPEN-ACAD-01` = A). **`1.11d-a` (períodos) y `1.11d-b` (escalas) implementadas (2026-10-17, ramas `feature/REQ-ACAD-1-11d-a-periodos` y `feature/REQ-ACAD-1-11d-b-escalas`; la segunda sin PR todavía).** **Implementado en `1.11-b`** (2026-10-12): ver `funcional.md §16` para las desviaciones aprobadas respecto a este documento.

Prefijo: `/api/v1`. Tenant por *host* (`ADR-033 §2`). Todo según `ADR-038`: recurso individual desnudo, colección en `{data, meta}` (§3), paginación por página (§4.2-§4.3: catálogo de entidades nacidas de acción administrativa, decenas a cientos de filas por centro y curso académico), filtros planos y valores múltiples separados por comas (§5.2), orden por lista blanca con desempate por `id` (§5.3), errores `application/problem+json` con `type` URN y `errors` como **objeto indexado por campo** cuyos valores son arrays de `{code, message, params}` (p. ej. `errors.code[0].code`; §6), `404` frente a `403` (§6.4).

**Identificadores**: solo `public_id` ULID en rutas y cuerpos (`ADR-029`). Ningún `code` es identificador de ruta (`ADR-051`).

> **`1.11d`** (especificación **APROBADA** el 2026-10-16): diez *endpoints* nuevos de períodos lectivos y escalas en **§11**; los 27 de arriba no cambian salvo `DELETE /education-levels/{public_id}`, que gana `academic_periods` en `params.dependents` (`RN-ACAD-61`). `acad` aporta además una entrada al `409` de `PATCH /academic-years/{public_id}` de `curso` (`RN-ACAD-62`, §11.7).

**Cadena de *middleware*** de toda ruta (`OPEN-ACAD-10` = no esencial, aprobada): autenticación → `module-enabled:acad` → `permission:<código>` (`AR-07a`, `AR-07b`). La autenticación es `App\Http\Middleware\RequireAuthenticatedUser`, **no** el alias `auth` (desviación de `1.11-b`, `funcional.md §16`; el alias se ordena antes de `resolve-tenant` y da `500` con una cookie real); las rutas de `1.11d` se añaden dentro del mismo grupo `Route::middleware([RequireAuthenticatedUser::class, 'module-enabled:acad'])` de `Http/routes.php`.

**Curso académico** (`REQ-CURSO/api.md §3`): los listados aceptan `academic_year` (`public_id`; omitido ⇒ activo; sin activo ⇒ `404 curso.no_active_year`; inexistente o de otro centro ⇒ `404`). Toda lectura invoca `AcademicYearReadAccess` (`RN-ACAD-08`): curso `cerrado`/`archivado` sin `curso_historico.leer` ⇒ `404`. Toda escritura sobre un curso de solo lectura ⇒ `409 urn:pge:error:academic-year-closed` (disparador, `RN-ACAD-07`). Estos tres comportamientos se aplican a **todos** los *endpoints* de abajo y no se repiten en cada uno.

---

## 1. Representaciones

### 1.1 `EducationLevel`

```json
{
  "public_id": "01JA0000000000000000000001",
  "academic_year": "01JA0000000000000000000000",
  "code": "ESO",
  "name": "Educación Secundaria Obligatoria",
  "name_translations": { "es-ES": "Educación Secundaria Obligatoria", "en": "Compulsory Secondary Education" },
  "stage": "eso",
  "regime": "concertado",
  "position": 3,
  "created_at": "2026-10-10T09:00:00Z",
  "updated_at": "2026-10-10T09:00:00Z"
}
```

- Contenido traducible (`ADR-062 §5.4`): **siempre los dos** campos en la lectura. `name` es la cadena resuelta en el servidor (idioma de la petición según `ResolveApiLocale` → `default_locale` del centro → `es-ES` → primera clave en el orden `es-ES`, `en`, `de`, `fr`) y es **de solo lectura** (`readOnly` en OpenAPI; si llega en el cuerpo de una escritura se ignora, no es `422`). `name_translations` es el objeto almacenado completo, con todas sus claves, incluidas las de idiomas ya no activos. La SPA lo usa para marcar `lang` cuando `name` sale de la caída (WCAG 3.1.2) y para el formulario de edición.
- `stage` y `regime` sin traducir (`ADR-038 §3.2`); enumerados declarados extensibles en OpenAPI (`ADR-038 §7.3`).
- No se exponen `id`, `tenant_id`, `*_id` internos, `created_by`, `updated_by` ni `deleted_at`.

### 1.2 `Course` (curso de etapa)

Igual que `EducationLevel` sin `stage`/`regime`, más `"education_level": "<public_id>"`.

### 1.3 `Group`

`public_id`, `academic_year`, `course` (`public_id`), `code`, `name` (texto simple), `position`, `created_at`, `updated_at`. Aquí, y en `Subgroup`, `name` **no** es traducible y **sí** se escribe (`POST`/`PATCH`); la regla «`name` es de solo lectura» de `ADR-062 §5.4` solo afecta a las entidades con `name_translations` (etapa, curso de etapa, asignatura).

### 1.4 `Subgroup`

`public_id`, `academic_year`, `group` (`public_id`), `code`, `name`, `position`, `created_at`, `updated_at`.

### 1.5 `Subject`

`public_id`, `academic_year`, `course` (`public_id`), `code`, `name`, `name_translations`, `position`, `created_at`, `updated_at`.

### 1.6 Asignaturas de un grupo

`{"data": [Subject…]}` — lista de las asignaturas asignadas (representación `Subject`), sin paginar: el máximo es el número de asignaturas de un curso de etapa (decenas) **[DERIVADA]**. La fila de `group_subjects` no se expone.

---

## 2. Endpoints

Permisos: todos sobre el recurso `estructura_academica` con ámbito `todos` (`permisos.md`). `L` = `leer`, `C` = `crear`, `A` = `actualizar`, `E` = `eliminar`.

| Verbo y ruta | Permiso | Respuesta | Errores propios (además de 401, 403, `module-disabled`, 404 de curso y `academic-year-closed`) |
|---|---|---|---|
| `GET /education-levels` | L | 200 colección | 422 parámetro inválido |
| `POST /education-levels` | C | 201 + `Location` | 422 |
| `GET /education-levels/{public_id}` | L | 200 | 404 |
| `PATCH /education-levels/{public_id}` | A | 200 | 404, 422 |
| `DELETE /education-levels/{public_id}` | E | 204 | 404, 409 `has_dependents` (con cursos o, desde `1.11d-a`, con períodos lectivos vivos: `academic_periods` en `params.dependents`, `RN-ACAD-61`) |
| `GET /courses` | L | 200 colección | 422 |
| `POST /courses` | C | 201 | 422 |
| `GET /courses/{public_id}` | L | 200 | 404 |
| `PATCH /courses/{public_id}` | A | 200 | 404, 422 |
| `DELETE /courses/{public_id}` | E | 204 | 404, 409 |
| `GET /groups` | L | 200 colección | 422 |
| `POST /groups` | C | 201 | 422 |
| `GET /groups/{public_id}` | L | 200 | 404 |
| `PATCH /groups/{public_id}` | A | 200 | 404, 422 |
| `DELETE /groups/{public_id}` | E | 204 | 404, 409 |
| `GET /groups/{public_id}/subgroups` | L | 200 colección | 404 |
| `POST /groups/{public_id}/subgroups` | C | 201 | 404 (grupo de la ruta inexistente, borrado o de otro centro), 422 (campos del cuerpo; nunca `parent_not_found`) |
| `GET /subgroups/{public_id}` | L | 200 | 404 |
| `PATCH /subgroups/{public_id}` | A | 200 | 404, 422 |
| `DELETE /subgroups/{public_id}` | E | 204 | 404 |
| `GET /subjects` | L | 200 colección | 422 |
| `POST /subjects` | C | 201 | 422 |
| `GET /subjects/{public_id}` | L | 200 | 404 |
| `PATCH /subjects/{public_id}` | A | 200 | 404, 422 |
| `DELETE /subjects/{public_id}` | E | 204 | 404, 409 |
| `GET /groups/{public_id}/subjects` | L | 200 (§1.6) | 404 |
| `PUT /groups/{public_id}/subjects` | A | 200 (§1.6) | 404, 422 `subject_not_in_course` |

27 *endpoints*. En los listados anidados (`/groups/{id}/subgroups`, `/groups/{id}/subjects`) el curso académico es el del grupo: no admiten `academic_year`. En todas las rutas `/groups/{public_id}/…`, el grupo es recurso de la ruta: inexistente, borrado o de otro centro ⇒ `404` (`ADR-038 §6.4`); el `422 acad.validation.parent_not_found` es solo para padres que llegan en el cuerpo (`funcional.md §4.3`, `CA-ACAD-007`).

### 2.1 Cuerpos

**`POST /education-levels`**

```json
{ "academic_year": "01JA…", "code": "ESO", "name_translations": { "es-ES": "Educación Secundaria Obligatoria" }, "stage": "eso", "regime": "concertado", "position": 3 }
```

`academic_year` obligatorio (`RN-CURSO-22`); `position` opcional (`RN-ACAD-04`). `name_translations` debe contener la clave del **idioma por defecto del centro** (`default_locale`, que puede no ser `es-ES`, `OPEN-062-01`), y solo claves de idiomas activos (`ADR-062 §5.2` punto 1, `RN-ACAD-03`).

**`POST /courses`**: `{ "education_level": "…", "code": "2ESO", "name_translations": {…}, "position": 2 }`.
**`POST /groups`**: `{ "course": "…", "code": "A", "name": "2º ESO A", "position": 1 }`.
**`POST /groups/{id}/subgroups`**: `{ "code": "D1", "name": "Desdoble 1", "position": 1 }`.
**`POST /subjects`**: `{ "course": "…", "code": "MAT", "name_translations": {…}, "position": 1 }`.

En ninguna alta de hijo se admite `academic_year` (`422 acad.validation.academic_year_not_allowed`, `RN-ACAD-05`).

**`PATCH`** (semántica de `ADR-038`, subconjunto de claves): editables `code`, `position`; en etapas, `stage` y `regime`; en etapas, cursos de etapa y asignaturas, `name_translations`; en grupos y subgrupos, `name`. Claves de padre o curso académico ⇒ `422 acad.validation.parent_immutable` (`RN-ACAD-06`).

`name_translations` en `PATCH` se **fusiona por idioma** (`ADR-038 §9.2` regla 4, `ADR-062 §5.2` punto 2), no se reemplaza:

```json
{ "name_translations": { "fr": "Mathématiques", "de": null } }
```

cambia o añade `fr`, quita `de` y deja intactos los demás idiomas (también los de idiomas ya no activos). `""` ⇒ `422 translations.empty_value`; una clave no nula de un idioma no activo ⇒ `422 translations.locale_not_active`; quitar el idioma por defecto actual (o dejar la fila sin él) ⇒ `422 translations.default_locale_required`.

**`PUT /groups/{id}/subjects`**: `{ "subjects": ["<public_id>", …] }`. Lista vacía = quitar todas. Idempotente (`RN-ACAD-32`).

### 2.2 Idempotencia

Sin `Idempotency-Key` (`INV-011` la exige en pagos, matrículas y envíos masivos): un reintento de alta produce `422 code_taken`, no un duplicado. `PATCH`, `PUT` y `DELETE` son naturalmente repetibles (un segundo `DELETE` responde `404`).

---

## 3. Paginación, filtrado y ordenación

Paginación por **página** en los cuatro listados de nivel superior: `page`, `per_page` (defecto 25, máximo 100; más ⇒ `422`). Los dos listados anidados no se paginan (decenas de filas como máximo) **[DERIVADA]**; si la medición dice otra cosa, añadir paginación es compatible.

| Listado | Filtros | `sort` (defecto primero) |
|---|---|---|
| `GET /education-levels` | `academic_year`, `stage` (múltiple), `regime` (múltiple) | `position`, `-position`, `code`, `-code` |
| `GET /courses` | `academic_year`, `education_level` (múltiple) | `position`, `-position`, `code`, `-code` |
| `GET /groups` | `academic_year`, `course` (múltiple), `education_level` (múltiple) | `position`, `-position`, `code`, `-code` |
| `GET /subjects` | `academic_year`, `course` (múltiple), `education_level` (múltiple) | `position`, `-position`, `code`, `-code` |
| `GET /groups/{id}/subgroups` | — | `position`, luego `code` |

Un filtro por padre (`course`, `education_level`) de **otro** curso académico distinto del `academic_year` indicado (o del activo, si se omite) devuelve colección vacía, no error **[DERIVADA]**: los filtros se combinan con `AND`. Un `public_id` de padre inexistente en un filtro ⇒ colección vacía (`ADR-038 §5.2`: filtros, no recursos). Sin `q`: ningún requisito pide búsqueda de texto en la estructura.

## 4. Exportación de listados

No hay. No se declara `estructura_academica.exportar` (`permisos.md §2.1`).

## 5. Ejemplo de error de borrado con dependientes

```json
{
  "type": "urn:pge:error:conflict",
  "title": "No se puede borrar",
  "status": 409,
  "detail": "El curso 2ESO tiene grupos o asignaturas. Bórralos antes de borrar el curso.",
  "instance": "/api/v1/courses/01JA…",
  "request_id": "01J8…",
  "errors": {
    "dependents": [
      { "code": "acad.conflict.has_dependents", "message": "…", "params": { "dependents": ["groups", "subjects"] } }
    ]
  }
}
```

## 6. Catálogo de errores del módulo

| `type` | Estado | `code` | Cuándo |
|--------|--------|--------|--------|
| `urn:pge:error:validation` | 422 | `acad.validation.code_required` | Código vacío tras recortar |
| `urn:pge:error:validation` | 422 | `acad.validation.code_taken` | Código repetido entre hermanos vivos, también por carrera (`RN-ACAD-01`) |
| `urn:pge:error:validation` | 422 | `translations.default_locale_required` | **Código común** (`ADR-062 §5.2` punto 4, no de `acad`): falta, o se quita en `PATCH`, el idioma por defecto del centro |
| `urn:pge:error:validation` | 422 | `translations.locale_not_active` | Código común: clave de un idioma no activo en el centro |
| `urn:pge:error:validation` | 422 | `translations.empty_value` | Código común: valor vacío tras recortar |
| `urn:pge:error:validation` | 422 | `translations.invalid_characters` | Código común (añadido en `1.11-a`): el valor contiene NUL, controles C0 (salvo tabulación y saltos de línea), DEL o UTF-8 inválido; PostgreSQL no puede guardarlo en `jsonb` |
| `urn:pge:error:validation` | 422 | `translations.too_long` | Código común (añadido en `1.11-a`, #413): el valor supera la longitud máxima (en caracteres, tras recortar) que declara el módulo; cada módulo pasa `maxLength` al validador |
| `urn:pge:error:validation` | 422 | `acad.validation.invalid_characters` | `code` o `name` (texto simple) con NUL, controles C0 (salvo tabulación y saltos de línea), DEL o UTF-8 inválido, antes de recortar; además, `code` con saltos de línea. Añadido en la ronda de revisión de `1.11-b` (S-2): el código común `translations.invalid_characters` es por idioma y no encaja con un texto simple. Los parámetros de consulta y `public_id` con esos caracteres son `422` (`core.validation.plain_text`) o se tratan como inexistentes |
| `urn:pge:error:validation` | 422 | `acad.validation.name_required` | Nombre vacío (grupo, subgrupo; no traducible) |
| `urn:pge:error:validation` | 422 | `acad.validation.stage_invalid` | `RN-ACAD-10` |
| `urn:pge:error:validation` | 422 | `acad.validation.regime_invalid` | `RN-ACAD-11` |
| `urn:pge:error:validation` | 422 | `acad.validation.position_invalid` | `position < 1` |
| `urn:pge:error:validation` | 422 | `acad.validation.academic_year_required` | Alta de etapa sin `academic_year` |
| `urn:pge:error:validation` | 422 | `acad.validation.academic_year_not_found` | `academic_year` del cuerpo inexistente, de otro centro o de un curso de solo lectura que el usuario no puede leer (sin `curso_historico.leer`; indistinguible de uno inexistente) |
| `urn:pge:error:validation` | 422 | `acad.validation.academic_year_not_allowed` | `academic_year` en el alta de un hijo (`RN-ACAD-05`) |
| `urn:pge:error:validation` | 422 | `acad.validation.parent_not_found` | Padre **del cuerpo** (`education_level`, `course`) ausente, inexistente, borrado o de otro centro, o de un curso de solo lectura que el usuario no puede leer (sin `curso_historico.leer`; indistinguible de uno inexistente). Un padre **de la ruta** (`/groups/{public_id}/…`) en esa situación es `404`, no este código |
| `urn:pge:error:validation` | 422 | `acad.validation.parent_immutable` | `PATCH` con padre o curso académico (`RN-ACAD-06`) |
| `urn:pge:error:validation` | 422 | `acad.validation.subject_not_in_course` | `PUT /groups/{id}/subjects` con una asignatura ajena al curso de etapa (`RN-ACAD-30`) |
| `urn:pge:error:conflict` | 409 | `acad.conflict.has_dependents` | `RN-ACAD-20`; `params.dependents`. Desde `1.11d-a`, el borrado de una etapa con períodos lectivos vivos incluye `academic_periods` en `params.dependents` (`RN-ACAD-61`) |
| `urn:pge:error:not-found` | 404 | — | Recurso inexistente, de otro centro, o de curso cerrado sin `curso_historico.leer` (mismo cuerpo en los tres casos) |
| `urn:pge:error:academic-year-closed` | 409 | `curso.academic_year_closed` | Lo produce y traduce `curso` (`REQ-CURSO/api.md §4`) |
| `urn:pge:error:not-found` | 404 | `curso.no_active_year` | Lo produce `curso` |

**Riesgo conocido**: issue #60 (`ValidationErrorFormatter` antepone `core.`). Comprobar en la implementación si sigue reproduciéndose con `acad.*` y `translations.*` (el prefijo final de los códigos comunes depende de #60, `ADR-062 §5.2`); si sí, se resuelve antes de mezclar.

Mensajes de `acad.*` en `lang/{es,en,de,fr}/acad.php`; los de `translations.*`, en el catálogo compartido que cree la implementación de `App\Support\I18n` (`INV-009`, `AR-12`).

## 7. Cambios en la API de `curso` que este paso incluye (aprobados)

No son de esta carpeta; la sesión principal actualiza `docs/modulos/REQ-CURSO/`.

| Cambio | Origen | Efecto |
|---|---|---|
| `AcademicYearDirectory` gana búsqueda por identificador interno (una y varias) | `OPEN-ACAD-12` | Interno (PHP), sin cambio HTTP (`CA-ACAD-042`) |
| `GET /api/v1/academic-years/selectable`, autoservicio sin permiso: cursos `activo` y `planificacion` y, con `curso_historico.leer`, `cerrado`/`archivado`; representación `AcademicYear`, sin paginar, orden `-starts_on`; se registra antes que `/{public_id}` | `OPEN-ACAD-11`, issue #409 | Amplía en esa única ruta la lista cerrada de `AR-07a` (aprobado) (`CA-ACAD-043`) |

## 8. Eventos de dominio emitidos

Ninguno (`funcional.md §7.3`).

## 9. Webhooks

Ninguno (`REQ-API-002`, fase 2).

## 10. OpenAPI

Los 27 *endpoints* (37 con `1.11d`, §11.8) en `apps/api/openapi.yaml` antes del merge (`CLAUDE.md §10`), con los esquemas de §1 (`name` `readOnly`; `name_translations` como objeto con propiedades `es-ES`, `en`, `de`, `fr`, sin propiedades adicionales, y anulables solo en el esquema de `PATCH`), los enumerados extensibles `stage` y `regime`, las listas blancas de `sort`, los filtros en estilo `form, explode: false`, los códigos comunes `translations.*` y el *endpoint* de `curso` de §7. Comprobar el problema de validación YAML del issue #97 al tocarlo.

---

## 11. `1.11d` · Períodos lectivos y escalas de calificación (APROBADA)

Todo lo de la cabecera de este documento aplica igual: `ADR-038`, solo `public_id`, cadena de *middleware*, curso académico de referencia, `404` de lectura de curso cerrado (`RN-ACAD-52`) y `409 academic-year-closed` en escritura. Permisos sobre el recurso **`configuracion_academica`** (`permisos.md §2`), ámbito `todos`.

### 11.1 Representación `AcademicPeriod`

```json
{
  "public_id": "01JB0000000000000000000010",
  "academic_year": "01JA0000000000000000000000",
  "education_level": "01JA0000000000000000000001",
  "period_type": "trimestre",
  "position": 1,
  "name": "Primer trimestre",
  "name_translations": { "es-ES": "Primer trimestre", "en": "First term" },
  "starts_on": "2026-09-08",
  "ends_on": "2026-12-22",
  "created_at": "2026-10-16T09:00:00Z",
  "updated_at": "2026-10-16T09:00:00Z"
}
```

`period_type` sin traducir (enumerado extensible, `ADR-038 §7.3`); fechas `AAAA-MM-DD`; `name` resuelto y `readOnly` (`ADR-062 §5.4`).

### 11.2 Representación `GradingScale`

```json
{
  "public_id": "01JB0000000000000000000020",
  "academic_year": "01JA0000000000000000000000",
  "code": "MIX10",
  "name": "De 0 a 10 con calificación",
  "name_translations": { "es-ES": "De 0 a 10 con calificación" },
  "scale_type": "mixta",
  "min_value": "0.0",
  "max_value": "10.0",
  "decimals": 1,
  "passing_min_value": "5.0",
  "values": [
    { "public_id": "01JB…21", "code": "IN", "position": 1, "label": "Insuficiente", "label_translations": { "es-ES": "Insuficiente" }, "is_passing": false, "range_min": "0.0", "range_max": "4.9" },
    { "public_id": "01JB…22", "code": "SU", "position": 2, "label": "Suficiente", "label_translations": { "es-ES": "Suficiente" }, "is_passing": true, "range_min": "5.0", "range_max": "5.9" }
  ],
  "created_at": "2026-10-16T09:00:00Z",
  "updated_at": "2026-10-16T09:00:00Z"
}
```

- `min_value`, `max_value`, `passing_min_value`, `range_min`, `range_max`: **cadena** con exactamente `decimals` cifras decimales (`RN-ACAD-75`, `OPEN-ACAD-26` = A, decidida); `null` en la cualitativa (escala) y en la cualitativa (`range_*` de los valores).
- `decimals`: entero, `null` en la cualitativa.
- `values`: siempre presente, en orden de `position`; vacío en la numérica. Solo valores vivos. En la **mixta**, `is_passing` es **derivado** (`range_min ≥ passing_min_value`, `RN-ACAD-87`) y `readOnly`; en la cualitativa es el guardado.
- Un valor no tiene *endpoint* ni `Location` propios: su `public_id` sirve para la sincronización (`RN-ACAD-88`) y para las futuras FK de `CALIF`.

### 11.3 *Endpoints*

| Verbo y ruta | Permiso | Respuesta | Errores propios (además de 401, 403, `module-disabled`, 404 de curso y `academic-year-closed`) |
|---|---|---|---|
| `GET /academic-periods` | `configuracion_academica.leer` | 200 colección | 422 parámetro inválido |
| `POST /academic-periods` | `configuracion_academica.crear` | 201 + `Location` | 422 |
| `GET /academic-periods/{public_id}` | `configuracion_academica.leer` | 200 | 404 |
| `PATCH /academic-periods/{public_id}` | `configuracion_academica.actualizar` | 200 | 404, 422 |
| `DELETE /academic-periods/{public_id}` | `configuracion_academica.eliminar` | 204 | 404 |
| `GET /grading-scales` | `configuracion_academica.leer` | 200 colección | 422 parámetro inválido |
| `POST /grading-scales` | `configuracion_academica.crear` | 201 + `Location` | 422 |
| `GET /grading-scales/{public_id}` | `configuracion_academica.leer` | 200 | 404 |
| `PATCH /grading-scales/{public_id}` | `configuracion_academica.actualizar` | 200 | 404, 422 |
| `DELETE /grading-scales/{public_id}` | `configuracion_academica.eliminar` | 204 | 404 |

**Diez *endpoints*** (37 en el módulo). Nombres de ruta Laravel: `acad.academic-periods.{index,store,show,update,destroy}` y `acad.grading-scales.{index,store,show,update,destroy}`; parámetro `{publicId}` (`ADR-051`: solo `public_id`). Controladores nuevos `AcademicPeriodsController` y `GradingScalesController` con el patrón de `CoursesController` (servicio de aplicación por recurso, `StructureYears` para el curso académico, `FormRequest` por operación, `JsonResource` por representación).

### 11.4 Cuerpos

**`POST /academic-periods`**

```json
{ "education_level": "01JA…01", "period_type": "trimestre", "name_translations": { "es-ES": "Primer trimestre" }, "starts_on": "2026-09-08", "ends_on": "2026-12-22", "position": 1 }
```

Obligatorios: `education_level`, `period_type`, `name_translations` (con el idioma por defecto del centro), `starts_on`, `ends_on`. `position` opcional. `academic_year` ⇒ `422 acad.validation.academic_year_not_allowed`. El campo que lleva cada error de validación de un período es el de la tabla de §11.7 (columna «Campo»).

**`PATCH /academic-periods/{id}`**: editables `period_type`, `name_translations` (fusión por idioma), `starts_on`, `ends_on`, `position`. `education_level`/`academic_year` ⇒ `422 acad.validation.parent_immutable`.

**`POST /grading-scales`**

```json
{
  "academic_year": "01JA…00",
  "code": "CUAL",
  "name_translations": { "es-ES": "Insuficiente a Sobresaliente" },
  "scale_type": "cualitativa",
  "values": [
    { "code": "IN", "label_translations": { "es-ES": "Insuficiente" }, "is_passing": false },
    { "code": "SU", "label_translations": { "es-ES": "Suficiente" }, "is_passing": true },
    { "code": "BI", "label_translations": { "es-ES": "Bien" }, "is_passing": true },
    { "code": "NT", "label_translations": { "es-ES": "Notable" }, "is_passing": true },
    { "code": "SB", "label_translations": { "es-ES": "Sobresaliente" }, "is_passing": true }
  ]
}
```

Numérica: `{ "academic_year": "…", "code": "NUM10", "name_translations": {…}, "scale_type": "numerica", "min_value": "0", "max_value": "10", "decimals": 2, "passing_min_value": "5" }`. Mixta: los cuatro campos numéricos y `values` con `{code, label_translations, range_min, range_max}`. Campos por tipo: `funcional.md §4.9.2`, `RN-ACAD-72`, `-81`, `-82`, `-84`.

**`PATCH /grading-scales/{id}`**: editables `code`, `name_translations` (fusión), `min_value`, `max_value`, `decimals`, `passing_min_value` (solo numérica/mixta) y `values`. `values`, si llega, es el **conjunto completo** (`RN-ACAD-88`):

```json
{ "values": [
  { "public_id": "01JB…23", "label_translations": { "fr": "Bien" } },
  { "public_id": "01JB…21" },
  { "code": "MH", "label_translations": { "es-ES": "Matrícula de honor" }, "is_passing": true }
] }
```

- Elemento con `public_id`: actualiza ese valor con las claves presentes (subconjunto, semántica `PATCH`; `label_translations` se fusiona por idioma).
- Elemento sin `public_id`: alta; todas sus claves obligatorias.
- Valor vivo ausente: borrado lógico.
- El orden de la lista es el nuevo `position`.
- `scale_type` ⇒ `422 acad.validation.scale_type_immutable`; `academic_year` ⇒ `422 acad.validation.parent_immutable`.

Rutas de error en los elementos: `values.<n>.<campo>` (índice de la lista enviada, base 0), p. ej. `errors["values.2.code"][0].code = "acad.validation.code_taken"`. Los errores de la lista entera van en `errors.values`.

### 11.5 Paginación, filtrado y ordenación

Paginación por página en los dos listados (catálogo de nacimiento administrativo, `ADR-038 §4.2`): `page`, `per_page` (defecto 25, máximo 100).

| Listado | Filtros | `sort` (defecto primero) |
|---|---|---|
| `GET /academic-periods` | `academic_year` (omitido ⇒ activo), `education_level` (múltiple), `period_type` (múltiple) | `starts_on`, `-starts_on`, `position`, `-position` (desempate: con `sort=starts_on`/`-starts_on`, `position` y luego `id`; con `sort=position`/`-position`, `starts_on` y luego `id`; los desempates van siempre en orden ascendente, `RN-ACAD-60`) |
| `GET /grading-scales` | `academic_year` (omitido ⇒ activo), `scale_type` (múltiple) | `code`, `-code` (desempate `id`) |

Filtros combinados con `AND`; un `education_level` de otro curso académico o inexistente ⇒ colección vacía (`ADR-038 §5.2`, como `api.md §3`). Un valor de `period_type`/`scale_type` fuera de vocabulario ⇒ `422`. Sin `q` (ningún requisito pide búsqueda) y sin orden por nombre (`ADR-062 §5.4`).

### 11.6 Idempotencia

Como §2.2: sin `Idempotency-Key`. Un reintento de alta de escala da `422 code_taken`; uno de período, `422 period_overlap` (mismo intervalo en la misma etapa). `PATCH` con `values` idéntico no escribe (`RN-ACAD-88`).

### 11.7 Catálogo de errores nuevos

Además de los de §6, que se reutilizan con el mismo significado (`code_required`, `code_taken`, `invalid_characters`, `position_invalid`, `academic_year_required`, `academic_year_not_found`, `academic_year_not_allowed`, `parent_not_found`, `parent_immutable`, `translations.*`, `has_dependents` con `academic_periods`):

| `type` | Estado | `code` | Cuándo | Regla |
|--------|--------|--------|--------|-------|
| `urn:pge:error:validation` | 422 | `acad.validation.period_type_invalid` | Tipo de período fuera de vocabulario | `RN-ACAD-55` |
| `urn:pge:error:validation` | 422 | `acad.validation.date_invalid` | Fecha ausente o no `AAAA-MM-DD` válida | `RN-ACAD-56` |
| `urn:pge:error:validation` | 422 | `acad.validation.period_ends_before_start` | `ends_on ≤ starts_on` | `RN-ACAD-56` |
| `urn:pge:error:validation` | 422 | `acad.validation.period_outside_academic_year` | Fuera de las fechas del curso; `params.academic_year_starts_on`, `params.academic_year_ends_on` | `RN-ACAD-57` |
| `urn:pge:error:validation` | 422 | `acad.validation.period_overlap` | Solapa con otro período vivo de la etapa; `params.period` | `RN-ACAD-58` |
| `urn:pge:error:validation` | 422 | `acad.validation.period_type_mismatch` | Tipo distinto del de los demás períodos de la etapa; `params.period_type` | `RN-ACAD-59` |
| `urn:pge:error:validation` | 422 | `acad.validation.scale_type_invalid` | Tipo de escala fuera de vocabulario | `RN-ACAD-70` |
| `urn:pge:error:validation` | 422 | `acad.validation.scale_type_immutable` | `scale_type` en un `PATCH` | `RN-ACAD-70` |
| `urn:pge:error:validation` | 422 | `acad.validation.scale_field_required` | Falta un campo numérico en numérica/mixta | `RN-ACAD-72` |
| `urn:pge:error:validation` | 422 | `acad.validation.not_applicable_to_scale_type` | Campo que no aplica al tipo (numéricos en cualitativa, `values` en numérica, `is_passing` en tramo, `range_*` en cualitativa) | `RN-ACAD-72`, `-81`, `-82`, `-84` |
| `urn:pge:error:validation` | 422 | `acad.validation.decimal_invalid` | No es un decimal con punto, o fuera de `numeric(5,2)` | `RN-ACAD-73` |
| `urn:pge:error:validation` | 422 | `acad.validation.decimals_invalid` | `decimals` ∉ {0, 1, 2} | `RN-ACAD-73` |
| `urn:pge:error:validation` | 422 | `acad.validation.decimal_precision_exceeded` | Más decimales de los declarados; `params.decimals` | `RN-ACAD-73` |
| `urn:pge:error:validation` | 422 | `acad.validation.range_invalid` | `min_value ≥ max_value` | `RN-ACAD-73` |
| `urn:pge:error:validation` | 422 | `acad.validation.passing_out_of_range` | Umbral fuera de `[min, max]` | `RN-ACAD-74` |
| `urn:pge:error:validation` | 422 | `acad.validation.values_required` | Cualitativa o mixta sin valores | `RN-ACAD-81` |
| `urn:pge:error:validation` | 422 | `acad.validation.values_too_many` | Más de 100 elementos; `params.max` | `RN-ACAD-89` |
| `urn:pge:error:validation` | 422 | `acad.validation.is_passing_required` | Valor cualitativo sin `is_passing` booleano | `RN-ACAD-82` |
| `urn:pge:error:validation` | 422 | `acad.validation.passing_value_required` | Ningún valor cualitativo aprobado | `RN-ACAD-82` |
| `urn:pge:error:validation` | 422 | `acad.validation.value_not_in_scale` | `public_id` de valor ajeno, borrado, inexistente o repetido | `RN-ACAD-88` |
| `urn:pge:error:validation` | 422 | `acad.validation.band_range_invalid` | `range_min > range_max` o tramo fuera del rango de la escala | `RN-ACAD-84` |
| `urn:pge:error:validation` | 422 | `acad.validation.bands_not_contiguous` | Tramos con huecos, solapes, fuera de orden o sin cubrir la escala | `RN-ACAD-85` |
| `urn:pge:error:validation` | 422 | `acad.validation.passing_threshold_inside_band` | El umbral no coincide con el inicio de un tramo | `RN-ACAD-86` |

**Campo (`errors.<campo>`) de cada error de período.** La especificación aprobada no fijó en qué clave de `errors` va cada código; lo fijó la implementación (`AcademicPeriodService::assertValidState`, `PeriodFields`) y la interfaz (`funcional.md §10.5`) lo usa para pintar el error junto a su campo. Es contrato desde `1.11d-a`:

| `code` | Campo |
|---|---|
| `acad.validation.period_type_invalid` | `period_type` |
| `acad.validation.date_invalid` | el de la fecha inválida: `starts_on` o `ends_on` |
| `acad.validation.period_ends_before_start` | `ends_on` |
| `acad.validation.period_outside_academic_year` | `starts_on` si empieza antes del curso, `ends_on` si acaba después; **ambos** si se dan las dos (mismo `code` y mismos `params` en cada uno) |
| `acad.validation.period_type_mismatch` | `period_type` |
| `acad.validation.period_overlap` | `starts_on` |
| `acad.validation.parent_not_found` | `education_level` |

**Campo (`errors.<campo>`) de cada error de escala** (`1.11d-b`). Tampoco lo fijó la especificación aprobada; lo fijó la implementación (`GradingScaleValidator`, docblock de la clase; `GradingScaleService`) y la interfaz (`scaleForm.ts`, `distributeErrors`) lo usa para pintar cada error junto a su campo. Es contrato desde `1.11d-b`. `<n>` es el índice (base 0) de la lista **resultante** de `values`; si el `PATCH` no envía `values`, el orden guardado. Un solo `422` acumula todos los errores independientes.

| `code` (`acad.validation.…`) | Campo |
|---|---|
| `code_required`, `invalid_characters` (escala) | `code` |
| `code_taken` (escala: código usado por otra escala viva del curso, o carrera con otra alta, o intercambio de códigos entre valores guardados en un `PATCH`) | `code` |
| `code_required`, `invalid_characters`, `code_taken` (valor; este último solo si el código se repite **dentro de la lista enviada**) | `values.<n>.code` |
| Traducciones (`translations.default_locale_required`, etc.) | `name_translations`; en un valor, `values.<n>.label_translations` |
| `scale_field_required`, `decimal_invalid`, `decimal_precision_exceeded`, `not_applicable_to_scale_type` (escala) | el campo afectado: `min_value`, `max_value`, `passing_min_value` o `decimals` |
| `scale_field_required`, `decimal_invalid`, `decimal_precision_exceeded`, `not_applicable_to_scale_type` (tramo o valor) | `values.<n>.range_min`, `values.<n>.range_max` o `values.<n>.is_passing` |
| `decimals_invalid` | `decimals` |
| `range_invalid` | `max_value` |
| `passing_out_of_range` | `passing_min_value` |
| `band_range_invalid` | `values.<n>.range_min` si el tramo empieza antes de la escala; `values.<n>.range_max` si acaba después o si `range_min > range_max` |
| `values_required`, `values_too_many`, `passing_value_required`, `bands_not_contiguous`, `passing_threshold_inside_band` | `values` |
| `not_applicable_to_scale_type` por `values` en una numérica | `values` |
| `value_not_in_scale` | `values.<n>.public_id` |
| `is_passing_required` | `values.<n>.is_passing` |
| `academic_year_required`, `academic_year_not_found`, `parent_immutable`, `scale_type_invalid`, `scale_type_immutable` | `academic_year` / `scale_type` (alta y `PATCH` rechazan los campos inmutables sin escribir nada) |

Reglas de forma que la implementación fijó y la especificación no (`funcional.md §17.4`): un campo inaplicable al tipo enviado como `null` (o `values: []` en una numérica) se trata como **ausente**; `values` presente pero no lista o `[]` en una cualitativa o mixta ⇒ `values_required` (para conservar los valores guardados, no se envía `values`); el orden de las comprobaciones de lista es: forma (`values_required`), tamaño (`values_too_many`), cada elemento, y solo si todos los elementos son válidos las reglas entre valores (`passing_value_required`, `bands_not_contiguous`, `passing_threshold_inside_band`). Los decimales se comparan como enteros en centésimas, nunca como `float`.

Las comprobaciones de estado de `assertValidState` se detienen en la primera que falla, por este orden: fechas ordenadas, dentro del curso, un solo tipo por etapa, sin solape.

**Código de `acad` que no sale de un *endpoint* de `acad`** (`RN-ACAD-62`, desde `1.11d-a`): `acad.conflict.periods_outside_academic_year` es el `code` de la entrada que la validación de `acad` aporta a `errors.date_change[]` del `409 urn:pge:error:conflict` / `curso.conflict.date_change_checks_failed` de **`PATCH /academic-years/{public_id}`** (`REQ-CURSO/api.md`). `message` traducido al idioma resuelto; `params` exactamente `count` (entero, períodos vivos que quedarían fuera), `periods_starts_on` y `periods_ends_on` (`AAAA-MM-DD`: menor inicio y mayor fin de todos los períodos vivos del curso académico). Sin `public_id`, nombre ni etapa de ningún período (`REQ-CURSO RN-CURSO-53`, `ADR-035 §5`):

```json
{ "code": "acad.conflict.periods_outside_academic_year", "message": "Hay períodos lectivos que quedarían fuera del curso académico: sus fechas deben incluir del 2026-09-08 al 2027-06-20.", "params": { "count": 1, "periods_starts_on": "2026-09-08", "periods_ends_on": "2027-06-20" } }
```

Los *endpoints* de períodos no ganan ningún código por `OPEN-CURSO-25` = B: un alta o edición que espera a un `PATCH` de fechas y queda fuera de las fechas nuevas responde el `422 acad.validation.period_outside_academic_year` de siempre (`RN-ACAD-57`).

Mensajes en `lang/{es,en,de,fr}/acad.php` (`funcional.md §10.5.5`). La violación de `grading_scales_tenant_year_code_unique` o `grading_scale_values_tenant_scale_code_unique` por carrera se traduce a `422 code_taken` (`WritesStructure::inTransaction`, `23505`), nunca `500`; una violación de `CHECK` (`23514`) o FK (`23503`) que llegue al motor es un *bug* del servicio (`operacion.md §8`).

### 11.8 OpenAPI

**Estado (`1.11d-a` y `1.11d-b`)**: implementado en `apps/api/openapi/paths/acad.yaml` y `components.yaml` (verificado por `AcadOpenApiTest`); la cabecera de `acad.yaml` ya está actualizada. Lo que sigue es la especificación de partida, conservada como referencia.

En `apps/api/openapi/paths/acad.yaml` (fichero existente, enlazado desde `openapi.yaml`): los diez *endpoints* con `operationId` `listAcademicPeriods`, `createAcademicPeriod`, `getAcademicPeriod`, `updateAcademicPeriod`, `deleteAcademicPeriod`, `listGradingScales`, `createGradingScale`, `getGradingScale`, `updateGradingScale`, `deleteGradingScale`, etiqueta `Acad / Configuración académica`, y en `components.yaml` los esquemas `AcademicPeriod`, `GradingScale`, `GradingScaleValue`, sus variantes de alta y `PATCH` (traducciones anulables solo en `PATCH`; `public_id` opcional solo en el elemento de `values` del `PATCH`), un esquema `DecimalString` (`type: string`, `pattern: '^-?\d{1,3}(\.\d{1,2})?$'`, entrada también `number` con `oneOf`), los enumerados extensibles `period_type` y `scale_type`, y las listas blancas de `sort`. **El comentario de cabecera de `acad.yaml` debe corregirse**: hoy dice que toda ruta exige `estructura_academica.<acción>`, y las diez nuevas exigen `configuracion_academica.<acción>`.

### 11.9 Eventos y webhooks

Ninguno (`funcional.md §7.3`).
