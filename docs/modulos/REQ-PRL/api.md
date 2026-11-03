# REQ-PRL · Puerta y entrega de menores · API

> **Origen del contenido.** Traslado **sin cambios de contenido** (salvo las secciones de implementación de `1.14b-b`, que son nuevas) de lo marcado `prl` en `docs/modulos/REQ-FAM-UNIT/` (especificación del paso `1.14b`, aprobada el 2026-10-29), tal como exige `ADR-068 §5.1`. La numeración de IDs (`RN-PRL-*`, `CA-PRL-*`, `OPEN-*`, secciones `§21.x`/`§8.x`) se conserva tal cual para que las referencias cruzadas existentes sigan siendo válidas. Las referencias a `§N` sin más indican la sección de `REQ-FAM-UNIT/funcional.md`, `datos.md`, `api.md`, `permisos.md` u `operacion.md` del fichero homónimo de ese módulo.

### 8.4 Puerta (`prl`, `1.14b-b`)

| Verbo y ruta | Permiso | Respuesta | Errores propios |
|---|---|---|---|
| `GET /door/students?q=` | `entrega_menor.leer` | 200 colección (máx. 20, sin paginar): `student_person`, `display_name`, `family_unit_code`. `q` de 2 a 100 caracteres | 422 |
| `GET /door/students/{studentPersonPublicId}/pickup-list` | `entrega_menor.leer` | 200 `{ data: [PickupListEntry], meta: { as_of, protocol_available } }`; lectura auditada con finalidad `puerta` y límite | 404, 429 |
| `POST /student-movements` | `entrega_menor.crear` | 201 `StudentMovement`; con `Idempotency-Key` repetida, la respuesta original | 422 `prl.movement.person_not_authorized`, `prl.movement.verification_requires_document`, `prl.movement.reason_required`, `validation.*`; 409 `idempotency_mismatch` |
| `GET /student-movements` | `entrega_menor.leer` | 200 colección, cursor `(occurred_at DESC, id DESC)`; filtros `student_person`, `date`, `direction`, `off_schedule` | 422 |
| `GET /student-movements/{id}` | `entrega_menor.leer` | 200 | 404 |
| `POST /pickup-alerts` | `alerta_entrega.crear` | 201 `PickupAlert` con `protocol` (texto del centro o mínimo del producto, `OPEN-FAM-UNIT-40`) y los teléfonos del principal y del contacto de emergencia | 404 (alumno), 422 |
| `GET /pickup-alerts` | `alerta_entrega.leer` | 200 colección (página); filtros `status`, `date`, `student_person` | 422 |
| `GET /pickup-alerts/{id}` | `alerta_entrega.leer` | 200 | 404 |
| `PATCH /pickup-alerts/{id}` | `alerta_entrega.actualizar` | 200: `principal_contacted_at`, y cierre con `resolution` (+ `resolution_notes`) | 404, 409 `prl.alert.closed` |

`POST /student-movements` cuerpo:

```json
{
  "student_person": "01JB…30",
  "direction": "salida",
  "picked_up_by": "01JB…50",
  "verification_method": "foto",
  "delivered_by": null,
  "off_schedule": false,
  "off_schedule_reason": null,
  "notes": null,
  "corrects": null,
  "correction_reason": null
}
```

El servidor resuelve `basis` y la referencia (vínculo o autorización) con `AuthorizedPickupList` **en la transacción** (`RN-PRL-02`); el cliente no los envía. `delivered_by` nulo = quien registra; si se indica, debe ser un usuario vivo y activo del centro.

**Representaciones**: `StudentMovement` = `public_id`, `student_person`, `direction`, `off_schedule`, `off_schedule_reason`, `notes`, `picked_up_by` (`person` + `display_name`), `basis`, `verification_method`, `delivered_by`, `recorded_by`, `corrects`, `occurred_at`. `PickupAlert` = `public_id`, `student_person`, `student_display_name`, `kind`, `presenter_description`, `status`, `raised_by`, `raised_by_display_name`, `raised_at`, `principal_contacted_at`, `resolution`, `resolution_notes`, `resolved_by`, `resolved_by_display_name`, `resolved_at`. **Enmienda 2026-11-03 (`1.14b-c2`)**: los tres `*_display_name` se añaden (compatible hacia atrás) para que la interfaz muestre nombres y no `public_id`; solo el nombre visible (sin documento, teléfono ni fecha de nacimiento), resuelto por lote por `Core\Domain\PersonDirectory`/`UserDirectory` (`INV-007`, una consulta por tipo, sin N+1), vacío si la persona o el usuario está borrado y `resolved_by_display_name` `null` mientras la alerta está abierta. Permisos y auditoría de lectura, sin cambios.

### 8.5 Paginación y orden (filas de `prl`)

| Listado | Paginación | Orden |
|---------|-----------|-------|
| `GET /pickup-lists/{…}`, `GET /door/…/pickup-list` | Sin paginar | Tutores primero, después terceros y puntuales, por `display_name` |
| `GET /door/students` | Máx. 20, sin paginar | Coincidencia de prefijo, después `display_name` |
| `GET /student-movements` | Cursor | Fijo |
| `GET /pickup-alerts` | Página | `raised_at` desc |

### 8.6 Errores (filas de `prl`)

`prl.movement.person_not_authorized` (`422`, campo `picked_up_by`; el mismo cuerpo para «no figura», «excluida por revocación» y «la persona no existe»), `prl.movement.verification_requires_document` (`422`, campo `verification_method`), `prl.movement.reason_required` (`422`, campo `off_schedule_reason` o `notes`), `prl.alert.closed` (`409`, campo `status`), `prl.idempotency_mismatch` (`409`, campo `idempotency_key`). Códigos de forma (`422`, `prl.validation.*`): `required`, `reference_invalid`, `enum_invalid`, `text_invalid`, `boolean_invalid`, `datetime_invalid`, `student_not_found`, `user_not_found`, `reference_not_found`, `entry_without_pickup`, `off_schedule_reason_unexpected`, `cursor_invalid`, `idempotency_key_invalid`, `field_not_editable`, `notes_without_resolution`, `empty_body`, `contact_time_out_of_range`. Los mensajes son las claves `lang/*/prl.php`.

### 8.7 Implementación de `1.14b-b`

> **Estado**: **IMPLEMENTADA** (2026-11-02). OpenAPI en `apps/api/openapi/paths/prl.yaml` (y los esquemas `StudentMovement`, `PickupAlert`, `DoorStudent`, `DoorPickupList`... de `openapi/components.yaml`); decisiones de detalle en `funcional.md` (`DP-1` a `DP-10`). Rutas con la cadena `RequireAuthenticatedUser` → `module-enabled:prl` → `permission:<código>`; ningún permiso de `prl` admite ámbito distinto de `todos`.

- **`Idempotency-Key`** (`POST /student-movements`, `POST /pickup-alerts`): opcional, de 1 a 128 caracteres ASCII visibles, por usuario que registra (`funcional.md DP-4`). Repetición con el mismo cuerpo: `201` con el recurso original y `Idempotency-Replayed: true`.
- **`StudentMovement`** añade `correction_reason` (`DP-7`). **`PickupAlert`** añade, en el alta y en el detalle **mientras está abierta**, `protocol` y `contacts` (`{ principal_phone, emergency_phone }`, calculados al leer, `DP-5`); el listado y la alerta cerrada no los llevan.
- **`GET /door/students/{studentPersonPublicId}/pickup-list`**: lectura auditada de `fam_unit` con finalidad `puerta` (una fila `read` por consulta, `CA-PRL-010`) y el cubo de lectura compartido de `RN-FAM-UNIT-93` (`429` con `Retry-After`); `Cache-Control: no-store`; `404` si el alumno no existe o es de otro centro.
- **`GET /door/students?q=`**: de 2 a 100 caracteres (`422` fuera de rango), máximo 20 resultados, por comienzo de nombre o apellidos sin distinguir mayúsculas.
- **`GET /student-movements`**: cursor opaco y cifrado ligado a los filtros y al centro (`422` si no corresponde); `limit` de 1 a 200 (por defecto 50). **`GET /pickup-alerts`**: página de 25 por defecto (máximo 100).
- **No existen** `PATCH`/`DELETE` de movimientos (`405`) ni `DELETE` de alertas (`405`): son evidencia.
- **Protocolo**: en `1.14b-b` solo existe el **texto mínimo fijo** del producto (`funcional.md DP-5`); el texto propio del centro (`OPEN-FAM-UNIT-40`) sigue abierto.
- **Lecturas con datos de personas** (`funcional.md DP-11`, decisión del usuario del 2026-11-02): `POST /pickup-alerts`, `GET` y `PATCH /pickup-alerts/{id}` (alerta abierta), `GET /student-movements` y `GET /student-movements/{id}` escriben una fila `read` sin valores y consumen el cupo de lectura de `prl` (`429` con `Retry-After`).
- **Parámetros de ruta**: en código y en OpenAPI `{studentPersonPublicId}` (`ADR-051 §5.2`); el texto de `§8.4` escribe `{student_person}` por herencia de la especificación.
