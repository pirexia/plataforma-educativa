# REQ-CALIF · API

> **Estado**: PROPUESTO (2026-11-12) junto con `funcional.md`. Los *endpoints* de `1.16-c` no se listan (`OPEN-CALIF-12`).

Prefijo: `/api/v1/...`. Todo se ajusta a `ADR-038`: envoltura (§3), paginación (§4), filtrado y orden (§5), errores RFC 9457 con `type` como URN (§6), versionado (§7).

Cadena de *middleware* de toda ruta: `RequireAuthenticatedUser` → `module-enabled:calif` → `permission:<código>` (`AR-07a`, `AR-07b`). Ninguna ruta es de autoservicio: la lista de excepciones de `AR-07a` no cambia.

## 1. Convenciones del módulo

- **Curso académico** (`REQ-CURSO/funcional.md §3.2`): los listados aceptan `academic_year` (`public_id`); omitido ⇒ el activo; sin activo ⇒ `404 curso.no_active_year`. Ningún alta lo admite en el cuerpo (`RN-CALIF-02`).
- **Lectura de curso cerrado**: `404` sin `curso_historico.leer`, en listado y detalle (`RN-CALIF-01`).
- **Decimales**: cadena en la respuesta; cadena o número en la entrada (`RN-CALIF-06`).
- **Textos traducibles**: `name_translations` en la escritura (fusión por idioma en `PATCH`) y `name` resuelto, de solo lectura, en la respuesta (`ADR-062`).
- **Fuera de ámbito** = `404` idéntico al de inexistente (`RN-CALIF-05`).
- **Auditoría de lectura**: marcada con (L) en cada *endpoint* (`RN-CALIF-08`).
- Errores comunes a todos: `401`, `403` (sin el permiso, o `module-disabled`), `404`, `422`, `429`; `409 urn:pge:error:academic-year-closed` en toda escritura.

## 2. Endpoints de `1.16-a`

### Períodos de evaluación

| Verbo y ruta | Permiso | Notas |
|--------------|---------|-------|
| `GET /evaluation-periods` | `configuracion_evaluacion.leer` · `todos` | Filtros `academic_year`, `education_level`. Orden por defecto `position` |
| `POST /evaluation-periods` | `configuracion_evaluacion.crear` · `todos` | Cuerpo: `education_level`, `kind`, `academic_period` (solo `parcial`), `name_translations`, `position?`. `201`. Errores `422` de `RN-CALIF-10` a `-12`. Reintento ⇒ `422 evaluation_period_exists` (unicidad natural; sin `Idempotency-Key`, criterio de `ADR-038 §8.1`) |
| `GET /evaluation-periods/{public_id}` | `…leer` | |
| `PATCH /evaluation-periods/{public_id}` | `…actualizar` | `name_translations`, `position`. `kind`, `education_level`, `academic_period` ⇒ `422 parent_immutable` |
| `DELETE /evaluation-periods/{public_id}` | `…eliminar` | `204`; `409 calif.conflict.has_dependents` (`RN-CALIF-13`) |

### Asignación de escalas

| Verbo y ruta | Permiso | Notas |
|--------------|---------|-------|
| `GET /grading-scale-assignments` | `configuracion_evaluacion.leer` | Filtros `academic_year`, `education_level`, `subject` |
| `POST /grading-scale-assignments` | `…crear` | `education_level`, `subject?`, `grading_scale`. `422 calif.validation.assignment_exists`, `subject_not_in_level`, `parent_not_found` |
| `PATCH /grading-scale-assignments/{public_id}` | `…actualizar` | Solo `grading_scale`; `409 scale_in_use` |
| `DELETE /grading-scale-assignments/{public_id}` | `…eliminar` | `409 scale_in_use` |

### Evaluaciones de grupo

| Verbo y ruta | Permiso | Notas |
|--------------|---------|-------|
| `POST /evaluation-periods/{public_id}/group-evaluations` | `evaluacion.crear` · `todos` | Cuerpo: `groups` (lista de `public_id`) o `all: true`. `200` con las evaluaciones de grupo resultantes y `created` (recuento). **Idempotente** (`RN-CALIF-21`). `409 scale_not_assigned`, `422 group_not_in_level` |
| `GET /group-evaluations` | `evaluacion.leer` · `todos`, `grupo` | Filtros `academic_year`, `evaluation_period`, `group`, `status`, `published`. Con `grupo`, solo las de `groupIdsFor` |
| `GET /group-evaluations/{public_id}` | `evaluacion.leer` · `todos`, `grupo` | Incluye período, grupo, estado, publicación y las asignaturas que cursa el grupo con su escala resuelta |

### Calificaciones

| Verbo y ruta | Permiso | Notas |
|--------------|---------|-------|
| `GET /group-evaluations/{public_id}/grades` (L) | `calificacion.leer` · `todos`, `grupo`, `clase`, `unidad_familiar` | Filtro `subject` (obligatorio con ámbito solo `clase` si se quiere una sola; sin él, todas las clases alcanzables). Devuelve matrículas (nombre del alumno por `PersonDirectory`) × asignaturas con `value`, `scale_value`, `label`, `is_passing`, `comment`, `status` (`provisional`/`definitiva`) y, desde `1.16-b`, `calculated_value`. Sin paginar, tope 2.000 celdas (`422 calif.validation.view_too_large`) [DERIVADA, precedente `RN-ACAD-127`] |
| `PUT /group-evaluations/{public_id}/subjects/{subject_public_id}/grades` | `calificacion.actualizar` · `todos`, `clase` | Cuerpo: `grades`: lista de `{enrollment, value? \| scale_value?, comment?}`. Conjunto de la clase, todo o nada (`RN-CALIF-33`). `200` con el estado resultante. `409 evaluation_closed`, `scale_not_assigned`; `422` de `RN-CALIF-30`, `-31`, `-32` en `grades.<n>.<campo>`. **Idempotente**. Concurrencia: `FOR SHARE` de la evaluación de grupo y bloqueo de aviso por clase (`RN-CALIF-33`); dos `PUT` simultáneos nunca dan `500` (`CA-CALIF-028`) |
| `GET /grades/{public_id}` (L) | `calificacion.leer` · los cuatro ámbitos | Detalle; para `unidad_familiar`, solo publicada (`RN-CALIF-26`) |

No hay `POST` ni `DELETE` de una calificación suelta: se escribe por conjunto [DERIVADA: una sola vía sancionada para `AR-10`]. Un cliente que quiere cambiar una sola envía una lista de un elemento.

## 3. Endpoints de `1.16-b`

| Verbo y ruta | Permiso | Notas |
|--------------|---------|-------|
| `GET /group-evaluations/{id}/subjects/{subject}/components` | `calificacion.leer` · `todos`, `grupo`, `clase` | Elementos y sus notas por matrícula (L). **No** admite `unidad_familiar`: los elementos son trabajo interno del docente [DERIVADA] |
| `PUT /group-evaluations/{id}/subjects/{subject}/components` | `calificacion.actualizar` · `todos`, `clase` | `components`: conjunto completo `{public_id?, name, weight}` (`RN-CALIF-40`, `-41`, `-45`). `422 weights_must_sum_100`, `components_not_applicable`, `component_not_in_class` |
| `PUT /group-evaluations/{id}/subjects/{subject}/component-grades` | `calificacion.actualizar` · `todos`, `clase` | `grades`: lista de `{component, enrollment, value \| null}`. Todo o nada |
| `POST /group-evaluations/{public_id}/closure` | `evaluacion.aprobar` · `todos`, `grupo` | Cuerpo: `confirm_missing?`. `200`. `409 missing_grades` (`params.missing`), `already_closed`. Con solo `grupo`, el grupo debe estar en `tutoredGroupIdsFor`; si no, `404` (`RN-CALIF-09`) |
| `POST /group-evaluations/{public_id}/reopening` | `evaluacion.aprobar` · `todos`, `grupo` | Cuerpo: `reason` (1-500). `409 calif.conflict.not_closed`. Con solo `grupo`, como `closure` (`RN-CALIF-09`) |
| `POST /group-evaluations/{public_id}/publication` | `evaluacion.publicar` · `todos` | `200`; `409 not_closed`; desde `1.17-b`, `409 report_cards_not_validated` |
| `POST /evaluation-periods/{public_id}/publication` | `evaluacion.publicar` · `todos` | En bloque: `200` con `{published, already_published, still_open}` |
| `GET /group-evaluations/{public_id}/transitions` | `evaluacion.leer` · `todos`, `grupo` | Registro de cierres, reaperturas y publicaciones; el motivo solo con `evaluacion.aprobar` |
| `GET /students/{public_id}/academic-record` (L) | `calificacion.leer` · los cuatro ámbitos | Historial (`funcional.md §4.6`). El alumno fuera de todo ámbito del sujeto ⇒ `404`. Filtro `academic_year` opcional |

`closure`, `reopening` y `publication` no llevan `Idempotency-Key`: el reintento choca con `409` o es inocuo (criterio de `ADR-038 §8.1`, precedente `RN-ALUM-27`).

## 4. Endpoints de `1.17-a`

| Verbo y ruta | Permiso | Notas |
|--------------|---------|-------|
| `GET /report-card-settings` | `configuracion_evaluacion.leer` | |
| `PATCH /report-card-settings` | `configuracion_evaluacion.actualizar` | `footer_translations` (fusión; texto plano); `tutor_validation_required` (`1.17-b`) |
| `POST /report-card-runs` | `boletin.crear` · `todos`, `grupo` | Cuerpo: `group_evaluation` **o** `evaluation_period` (este solo con `todos`). **`202`** con la emisión o emisiones en `pendiente`. No genera nada en la petición (`INV-012`). `409 not_closed`, `run_in_progress`; `503 calif.unavailable.pdf_renderer` solo si el contrato no está configurado, nunca por carga. Con solo `grupo`, la evaluación de grupo debe ser de un grupo de `tutoredGroupIdsFor`; si no, `404` (`RN-CALIF-09`) |
| `GET /report-card-runs` · `GET /report-card-runs/{public_id}` | `boletin.leer` · `todos`, `grupo` | Estado y recuentos para el progreso; la SPA consulta periódicamente |
| `GET /report-cards` | `boletin.leer` · `todos`, `grupo`, `unidad_familiar` | Filtros `academic_year`, `group_evaluation`, `student`, `status`, `locale`. Es el «historial de boletines por alumno y curso» de `REQ-CALIF-002`. Para `unidad_familiar`: solo `generado` de evaluaciones publicadas |
| `GET /report-cards/{public_id}` | Ídem | Metadatos: evaluación, alumno, idioma, estado, páginas, tamaño, fecha. **Nunca** `object_key` |
| `GET /report-cards/{public_id}/download` (L) | Ídem | `200` con `{url, expires_at}`: URL firmada de caducidad corta, misma forma que `GET /students/{id}/photo` (`ADR-013`). `409 calif.conflict.report_card_not_ready` si no está `generado` |

## 5. Endpoints de `1.17-b` (condicionados)

| Verbo y ruta | Permiso | Notas |
|--------------|---------|-------|
| `POST /report-cards/{public_id}/validation` | `boletin.firmar` · `todos`, `grupo` | Evidencia N1 (`ADR-082`). Si `OPEN-FIRMA-06` se aprueba, exige reautenticación reciente. Idempotente por usuario y huella |
| `POST /group-evaluations/{public_id}/report-card-validations` | Ídem | Valida en bloque los boletines vigentes del grupo |

En los dos, con solo ámbito `grupo`, el grupo del boletín o de la ruta debe estar en `tutoredGroupIdsFor`; si no, `404` sin evidencia (`RN-CALIF-09`, `CA-CALIF-073`).

El aviso a familias no tiene *endpoint* propio: lo dispara la publicación (enlace autenticado, nunca adjunto: `OPEN-079-03`, decidida el 2026-11-12).

## 6. Paginación, filtrado y ordenación

- **Por página** (`ADR-038 §4`) en `GET /evaluation-periods`, `/grading-scale-assignments`, `/group-evaluations`, `/report-card-runs`, `/report-cards` y `/transitions`: conjuntos pequeños y acotados por curso académico, con salto a página en la tabla de la interfaz. **A confirmar en implementación contra el criterio objetivo de `ADR-038 §4`**, en particular para `/report-cards` de un centro grande (miles por curso).
- **Sin paginar**, con tope: la rejilla de calificaciones y los elementos (una clase tiene decenas de filas).
- Orden: `sort` con los campos indicados en cada listado; por defecto `position`/`created_at` y desempate por `id`.
- `q` no se admite en ningún listado del módulo en `1.16`/`1.17` [DERIVADA: ningún requisito pide búsqueda de texto sobre notas].

## 7. Exportación de listados (`ADR-054`, `ADR-055`)

**No aplica.** El módulo no declara `exportar`: ningún requisito pide exportar calificaciones a CSV (`funcional.md §1.2`). La descarga de un boletín es `leer` sobre un documento ya emitido, no una exportación. Si más adelante se pide, sigue `ADR-054`/`ADR-055` (cola, paridad de filtros, sin `q`, `CsvWriter`).

## 8. Códigos de error propios

Prefijo `calif.`; mensajes en `lang/{es,en,de,fr}/calif.php` (`INV-009`).

| Estado | Códigos |
|--------|---------|
| `409` | `calif.conflict.has_dependents`, `scale_not_assigned`, `scale_in_use`, `evaluation_closed`, `already_closed`, `not_closed`, `missing_grades`, `run_in_progress`, `report_card_not_ready`, `report_cards_not_validated`, `unpublished_evaluations` (este último solo como entrada de la validación de cierre de `curso`) |
| `422` | `calif.validation.kind_invalid`, `academic_period_required`, `not_applicable`, `evaluation_period_exists`, `assignment_exists`, `subject_not_in_level`, `group_not_in_level`, `subject_not_in_group`, `enrollment_not_in_group`, `value_out_of_range`, `decimal_precision_exceeded`, `value_not_in_scale`, `not_applicable_to_scale_type`, `too_many_items`, `view_too_large`, `components_not_applicable`, `weights_must_sum_100`, `component_not_in_class`, `parent_not_found`, `parent_immutable`, `academic_year_not_allowed`, `reason_required` |

Ningún `params` lleva nombres, documentos ni valores de calificaciones (`ADR-035 §5`).

## 9. Eventos de dominio emitidos

`GroupEvaluationPublished`, especificado y emitido con su primer consumidor (`funcional.md §7.4`).

## 10. Webhooks disponibles

Ninguno (`REQ-API` es de fase 2).

## 11. OpenAPI

Cada *endpoint* se documenta en `apps/api/openapi/paths/calif.yaml` en la entrega que lo crea (`INV-006`, definición de terminado).
