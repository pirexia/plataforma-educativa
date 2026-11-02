# REQ-PRL · Puerta y entrega de menores · API

> **Origen del contenido.** Traslado **sin cambios de contenido** de lo marcado `prl` en `docs/modulos/REQ-FAM-UNIT/` (especificación del paso `1.14b`, aprobada el 2026-10-29), tal como exige `ADR-068 §5.1`. La numeración de IDs (`RN-PRL-*`, `CA-PRL-*`, `OPEN-*`, secciones `§21.x`/`§8.x`) se conserva tal cual para que las referencias cruzadas existentes sigan siendo válidas. Las referencias a `§N` sin más indican la sección de `REQ-FAM-UNIT/funcional.md`, `datos.md`, `api.md`, `permisos.md` u `operacion.md` del fichero homónimo de ese módulo.

### 8.4 Puerta (`prl`, `1.14b-b`)

| Verbo y ruta | Permiso | Respuesta | Errores propios |
|---|---|---|---|
| `GET /door/students?q=` | `entrega_menor.leer` | 200 colección (máx. 20, sin paginar): `student_person`, `display_name`, `family_unit_code`. `q` de 2 a 100 caracteres | 422 |
| `GET /door/students/{student_person}/pickup-list` | `entrega_menor.leer` | 200 `{ data: [PickupListEntry], meta: { as_of, protocol_available } }`; lectura auditada con finalidad `puerta` y límite | 404, 429 |
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

**Representaciones**: `StudentMovement` = `public_id`, `student_person`, `direction`, `off_schedule`, `off_schedule_reason`, `notes`, `picked_up_by` (`person` + `display_name`), `basis`, `verification_method`, `delivered_by`, `recorded_by`, `corrects`, `occurred_at`. `PickupAlert` = `public_id`, `student_person`, `kind`, `presenter_description`, `status`, `raised_by`, `raised_at`, `principal_contacted_at`, `resolution`, `resolution_notes`, `resolved_by`, `resolved_at`.

### 8.5 Paginación y orden (filas de `prl`)

| Listado | Paginación | Orden |
|---------|-----------|-------|
| `GET /pickup-lists/{…}`, `GET /door/…/pickup-list` | Sin paginar | Tutores primero, después terceros y puntuales, por `display_name` |
| `GET /door/students` | Máx. 20, sin paginar | Coincidencia de prefijo, después `display_name` |
| `GET /student-movements` | Cursor | Fijo |
| `GET /pickup-alerts` | Página | `raised_at` desc |
