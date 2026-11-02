# REQ-PRL · Puerta y entrega de menores · Datos

> **Origen del contenido.** Traslado **sin cambios de contenido** (salvo las secciones de implementación de `1.14b-b`, que son nuevas) de lo marcado `prl` en `docs/modulos/REQ-FAM-UNIT/` (especificación del paso `1.14b`, aprobada el 2026-10-29), tal como exige `ADR-068 §5.1`. La numeración de IDs (`RN-PRL-*`, `CA-PRL-*`, `OPEN-*`, secciones `§21.x`/`§8.x`) se conserva tal cual para que las referencias cruzadas existentes sigan siendo válidas. Las referencias a `§N` sin más indican la sección de `REQ-FAM-UNIT/funcional.md`, `datos.md`, `api.md`, `permisos.md` u `operacion.md` del fichero homónimo de ese módulo.

### 8.4 `prl` · `student_movements` (`1.14b-b`) — solo anexar

`tenantTableAppendOnly()`, `AppendOnlyModel`, `REVOKE UPDATE, DELETE` para `plataforma_app` (`RN-PRL-04`).

| Columna | Tipo | Nulo | Descripción |
|---------|------|------|-------------|
| `public_id` | ULID | No | |
| `student_person_id` | `bigint` | No | FK compuesta → `people` |
| `direction` | `text` | No | `CHECK IN ('entrada','salida')` |
| `off_schedule` | `boolean` | No | Fuera de horario (`RN-PRL-07`) |
| `off_schedule_reason` | `text` | Sí | `CHECK IN ('medico','familiar','actividad','retraso','otro')`; `CHECK (NOT off_schedule OR off_schedule_reason IS NOT NULL)` |
| `notes` | `text` | Sí | `CHECK (length(notes) <= 500)`; obligatoria si `off_schedule_reason = 'otro'` |
| `picked_up_by_person_id` | `bigint` | Sí | FK compuesta → `people`. Obligatoria en toda `salida` de `1.14b`: `CHECK (direction = 'entrada' OR picked_up_by_person_id IS NOT NULL)` (la salida autónoma de `1.14b-d` relajará el `CHECK` con su especificación) |
| `basis` | `text` | Sí | `CHECK IN ('tutor','tercero','puntual')`; presente si y solo si hay persona que recoge |
| `guardian_student_link_id` | `bigint` | Sí | FK compuesta → `guardian_student_links` (base `tutor`). La FK entre módulos está permitida en base de datos; la lectura va por interfaz (`INV-007`) |
| `pickup_authorization_id` | `bigint` | Sí | FK compuesta → `pickup_authorizations` (base `tercero`/`puntual`). `CHECK` de coherencia entre `basis` y la referencia presente |
| `verification_method` | `text` | Sí | `CHECK IN ('foto','documento','foto_y_documento')`; presente si hay persona que recoge |
| `delivered_by` | `bigint` | No | FK compuesta → `users`: quién entrega |
| `recorded_by` | `bigint` | No | FK compuesta → `users` |
| `corrects_movement_id` | `bigint` | Sí | FK compuesta → `student_movements` (corrección, `RN-PRL-04`); `correction_reason` `text` obligatorio con ella |
| `idempotency_key` | `text` | Sí | `UNIQUE (tenant_id, recorded_by, idempotency_key) WHERE idempotency_key IS NOT NULL` (`RN-PRL-09`), junto con el *hash* del cuerpo en `idempotency_body_hash` `text` |
| `occurred_at` | `TIMESTAMPTZ` | No | Instante del servidor |

### 8.5 `prl` · `pickup_alerts` (`1.14b-b`)

| Columna | Tipo | Nulo | Descripción |
|---------|------|------|-------------|
| `public_id` | ULID | No | |
| `student_person_id` | `bigint` | No | FK compuesta → `people` |
| `kind` | `text` | No | `CHECK IN ('persona_no_autorizada')` en `1.14b`; `alumno_no_recogido` lo añade `1.14b-d` |
| `presenter_description` | `text` | No | Nombre declarado y documento exhibido por quien se presenta; `CHECK (length(...) <= 500)` |
| `status` | `text` | No | `CHECK IN ('abierta','cerrada')` |
| `raised_by` / `raised_at` | `bigint` / `TIMESTAMPTZ` | No | FK → `users` |
| `principal_contacted_at` | `TIMESTAMPTZ` | Sí | Llamada manual al tutor principal (`OPEN-FAM-UNIT-33`) |
| `resolution` | `text` | Sí | `CHECK IN ('no_entregado','recogido_por_autorizado','autorizacion_puntual_registrada','otro')` |
| `resolution_notes` | `text` | Sí | `CHECK (length(...) <= 1000)` |
| `resolved_by` / `resolved_at` | `bigint` / `TIMESTAMPTZ` | Sí | `CHECK ((status = 'cerrada') = (resolution IS NOT NULL AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL))` |
| `idempotency_key`, `idempotency_body_hash` | `text` | Sí | Como §8.4 |

Tabla ordinaria (con `deleted_at` por `INV-005`, pero sin *endpoint* de borrado: la alerta es evidencia).

### 8.6 Índices (filas de `prl`)

| Índice | Consulta |
|--------|----------|
| `student_movements (tenant_id, student_person_id, occurred_at DESC, id DESC)` | Historial de un alumno (cursor) |
| `student_movements (tenant_id, occurred_at DESC, id DESC)` | Salidas del día en conserjería |
| `pickup_alerts (tenant_id, status, raised_at DESC) WHERE deleted_at IS NULL` | Alertas abiertas |

### 8.7 Auditoría y *morph map* (filas de `prl`)

| Modelo | Alias | Política | Notas |
|--------|-------|----------|-------|
| `StudentMovement` (`prl`) | `student_movement` | `Selective` | Códigos y fechas registrados; personas, usuarios, `notes` y `correction_reason` redactados |
| `PickupAlert` (`prl`) | `pickup_alert` | `Selective` | `presenter_description` y `resolution_notes` redactados |

### 8.8 Implementación de `1.14b-b`

> **Estado**: **IMPLEMENTADA** (2026-11-02): migraciones `apps/api/app/Modules/Prl/Database/migrations/2026_11_02_100100_create_student_movements_table.php` y `..._100200_create_pickup_alerts_table.php` (aditivas, tablas nuevas y vacías: `ADD CONSTRAINT` sin `NOT VALID` e índices sin `CONCURRENTLY` son inocuos). `db-reviewer` obligatorio.

- **`student_movements`**: `tenantTableAppendOnly()` (sin `deleted_at`, `updated_at` ni `created_by`/`updated_by`; `recorded_by` es el autor), `REVOKE UPDATE, DELETE` de `plataforma_app` **y** de `plataforma_platform`. Las claves foráneas a `guardian_student_links` y `pickup_authorizations` son `NO ACTION` (la acción por defecto; una autorización que respalda una salida no se borra) y están en base de datos como **evidencia**: ninguna columna guarda personas autorizadas ni el estado de una autorización (`CA-068-07`, comprobado por test). La FK de corrección (`corrects_movement_id`) se añade tras crear la tabla porque necesita el `UNIQUE (tenant_id, id)`.
- **`CHECK`s** hechos cumplir por el motor (todos con test en `PrlSchemaTest`): `direction`, `off_schedule_reason`, fuera de horario con motivo, motivo `otro` con nota, `notes` ≤ 500, salida con persona que recoge, `basis`, `verification_method`, persona/base/método juntos o ninguno, base coherente con la referencia presente, corrección con motivo, idempotencia con *hash*.
- **`pickup_alerts`**: `tenantTable()` (con `deleted_at` por `INV-005`, sin *endpoint* de borrado). Además del `CHECK` de cierre de `§8.5` se añade `pickup_alerts_open_without_resolution_check` (una abierta no lleva resolución, instante, autor ni notas de resolución; `funcional.md DP-6`).
- **Índices**: los de `§8.6`, el único parcial de idempotencia de cada tabla (`(tenant_id, recorded_by | raised_by, idempotency_key) WHERE idempotency_key IS NOT NULL`) y, por `AR-16`, un índice **completo** (`indpred IS NULL`) con prefijo `(tenant_id, columna)` para cada clave foránea compuesta (comprobado en `PrlSchemaTest`).
- **`down()`**: se niega (`RuntimeException`) si hay filas en cualquier centro (evidencia ante un incidente con un menor; `apps/api/tests/Concurrency/PrlConcurrencyTest.php`).
- **Longitud de la clave de idempotencia**: `CHECK (idempotency_key IS NULL OR length(idempotency_key) <= 255)` en ambas tablas (`..._idempotency_key_length_check`); la petición ya la limita a 128 (`funcional.md DP-13`).
