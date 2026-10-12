# REQ-ACAD · API

> **Estado**: **APROBADA** (2026-10-10), paso **1.11** (`REQ-ACAD-001`, estructura académica), con las recomendaciones de `funcional.md §15` aprobadas y los contratos de `ADR-062` (contenido traducible) y `ADR-063` (ámbitos), ambos ACEPTADOS. La API de períodos lectivos y escalas se especificará al abrir `1.11d` (`OPEN-ACAD-01` = A).

Prefijo: `/api/v1`. Tenant por *host* (`ADR-033 §2`). Todo según `ADR-038`: recurso individual desnudo, colección en `{data, meta}` (§3), paginación por página (§4.2-§4.3: catálogo de entidades nacidas de acción administrativa, decenas a cientos de filas por centro y curso académico), filtros planos y valores múltiples separados por comas (§5.2), orden por lista blanca con desempate por `id` (§5.3), errores `application/problem+json` con `type` URN y `errors` como **objeto indexado por campo** cuyos valores son arrays de `{code, message, params}` (p. ej. `errors.code[0].code`; §6), `404` frente a `403` (§6.4).

**Identificadores**: solo `public_id` ULID en rutas y cuerpos (`ADR-029`). Ningún `code` es identificador de ruta (`ADR-051`).

**Cadena de *middleware*** de toda ruta (`OPEN-ACAD-10` = no esencial, aprobada): autenticación → `module-enabled:acad` → `permission:<código>` (`AR-07a`, `AR-07b`).

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
| `DELETE /education-levels/{public_id}` | E | 204 | 404, 409 `has_dependents` |
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
| `urn:pge:error:validation` | 422 | `acad.validation.name_required` | Nombre vacío (grupo, subgrupo; no traducible) |
| `urn:pge:error:validation` | 422 | `acad.validation.stage_invalid` | `RN-ACAD-10` |
| `urn:pge:error:validation` | 422 | `acad.validation.regime_invalid` | `RN-ACAD-11` |
| `urn:pge:error:validation` | 422 | `acad.validation.position_invalid` | `position < 1` |
| `urn:pge:error:validation` | 422 | `acad.validation.academic_year_required` | Alta de etapa sin `academic_year` |
| `urn:pge:error:validation` | 422 | `acad.validation.academic_year_not_found` | `academic_year` del cuerpo inexistente o de otro centro |
| `urn:pge:error:validation` | 422 | `acad.validation.academic_year_not_allowed` | `academic_year` en el alta de un hijo (`RN-ACAD-05`) |
| `urn:pge:error:validation` | 422 | `acad.validation.parent_not_found` | Padre **del cuerpo** (`education_level`, `course`) ausente, inexistente, borrado o de otro centro. Un padre **de la ruta** (`/groups/{public_id}/…`) en esa situación es `404`, no este código |
| `urn:pge:error:validation` | 422 | `acad.validation.parent_immutable` | `PATCH` con padre o curso académico (`RN-ACAD-06`) |
| `urn:pge:error:validation` | 422 | `acad.validation.subject_not_in_course` | `PUT /groups/{id}/subjects` con una asignatura ajena al curso de etapa (`RN-ACAD-30`) |
| `urn:pge:error:conflict` | 409 | `acad.conflict.has_dependents` | `RN-ACAD-20`; `params.dependents` |
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

Los 27 *endpoints* en `apps/api/openapi.yaml` antes del merge (`CLAUDE.md §10`), con los esquemas de §1 (`name` `readOnly`; `name_translations` como objeto con propiedades `es-ES`, `en`, `de`, `fr`, sin propiedades adicionales, y anulables solo en el esquema de `PATCH`), los enumerados extensibles `stage` y `regime`, las listas blancas de `sort`, los filtros en estilo `form, explode: false`, los códigos comunes `translations.*` y el *endpoint* de `curso` de §7. Comprobar el problema de validación YAML del issue #97 al tocarlo.
