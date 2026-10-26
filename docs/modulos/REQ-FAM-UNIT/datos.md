# REQ-FAM-UNIT · Modelo de datos

> **Estado**: **APROBADA por el usuario** (2026-10-26, todas las recomendaciones de `OPEN-FAM-UNIT-01..21` aceptadas tal cual; ver `funcional.md §13`). Las migraciones se escriben en `1.14-a`/`-b`/`-c` con `db-reviewer` obligatorio. **`1.14-a` implementada (2026-10-26)**: las cuatro tablas de §1.1 a §1.4 (migraciones `2026_10_26_100100` a `100500` de `apps/api/app/Modules/FamUnit/Database/migrations/`; la FK de `principal_guardian_id` va en la tercera, tras crear `guardians`). Índices de §3 no creados por redundantes: `funcional.md §15`, D-12. Las tablas marcadas *condicionada* dependen de `OPEN-FAM-UNIT-05`; las marcadas con una entrega (`1.14-a`/`-b`/`-c`) siguen la partición propuesta en `funcional.md §1.2`.

Todas las tablas son **de tenant**, creadas con `TenantMigration::tenantTable()` (o `tenantTableAppendOnly()` donde se dice), que aporta `id bigint`, `tenant_id`, RLS `ENABLE` + `FORCE` con la política estándar, `UNIQUE (tenant_id, id)` y, en las ordinarias, `created_at`/`updated_at`/`deleted_at` (`TIMESTAMPTZ`) y `created_by`/`updated_by` (`ADR-033`, `ADR-034 §6`/`§7`). Solo se listan las columnas propias. Toda clave foránea a otra tabla de tenant es **compuesta** `(tenant_id, <columna>)` con `TenantMigration::tenantForeignId()` (`ADR-033 §6`). **Ninguna tabla lleva `academic_year_id`** (`ADR-034 §4`: la unidad familiar está expresamente entre las entidades que no dependen del curso), así que no aplica `AR-13` ni el disparador `academic_year_write_guard`. Ninguna usa `ON DELETE CASCADE`/`SET NULL`: las bajas son lógicas y se propagan en servicio, auditadas.

---

## 1. Entidades

### 1.1 `family_units` (`1.14-a`)

| Columna | Tipo | Nulo | Defecto | Descripción |
|---------|------|------|---------|-------------|
| `public_id` | `character(26)` ULID | No | — | `ADR-029` |
| `code` | `text` | No | — | Código único de la unidad en el centro (`REQ-FAM-UNIT-001`). Generación: `OPEN-FAM-UNIT-06`. `CHECK (length(code) BETWEEN 1 AND 40)` [DERIVADA] |
| `principal_guardian_id` | `bigint` | Sí | `NULL` | FK compuesta → `guardians`. Que sea tutor vivo **de esta unidad** se valida en servicio (`RN-FAM-UNIT-02`): una FK no puede expresar «de la misma unidad» sin una columna redundante |

Restricciones: `UNIQUE (tenant_id, code) WHERE deleted_at IS NULL` (`RN-FAM-UNIT-01`).

**Dependencia circular de FK** (`family_units.principal_guardian_id` → `guardians`, `guardians.family_unit_id` → `family_units`): se crean las dos tablas y después se añade la FK de `principal_guardian_id`, en la misma migración. No hace falta que sea `DEFERRABLE`: el principal siempre se designa después de crear el tutor, nunca en la misma sentencia.

### 1.2 `guardians` (`1.14-a`) — faceta de `people` (`ADR-034 §1`)

| Columna | Tipo | Nulo | Descripción |
|---------|------|------|-------------|
| `public_id` | ULID | No | |
| `person_id` | `bigint` | No | FK compuesta → `people` (tabla de `Core`; la FK en base de datos está permitida, la lectura va por la interfaz de `OPEN-FAM-UNIT-03`) |
| `family_unit_id` | `bigint` | No | FK compuesta → `family_units`. Un tutor pertenece a una sola unidad (`§16.2`, `RN-FAM-UNIT-11`) |

Restricciones: `UNIQUE (tenant_id, person_id) WHERE deleted_at IS NULL` (forma fijada por `ADR-034 §1` para toda faceta).

No guarda nombre, correo ni teléfono: son de `people`/`users` (`§16.3`, regla 1). El usuario del tutor se obtiene por la persona (`users.person_id`, único vivo por tenant, `ADR-034 §1`).

### 1.3 `family_unit_students` (`1.14-a`) — pertenencia del alumno

| Columna | Tipo | Nulo | Descripción |
|---------|------|------|-------------|
| `public_id` | ULID | No | Expuesto como identificador de la pertenencia en `DELETE /family-units/{id}/students/{membership}` |
| `family_unit_id` | `bigint` | No | FK compuesta → `family_units` |
| `student_person_id` | `bigint` | No | FK compuesta → `people`. La persona del alumno (`funcional.md §7.4`, `OPEN-FAM-UNIT-01`) |

Restricciones: `UNIQUE (tenant_id, student_person_id) WHERE deleted_at IS NULL` (`RN-FAM-UNIT-20`).

Por qué tabla propia y no `students.family_unit_id`: `students` es de `ALUM` y no existe (C1). Si `1.15` prefiere la columna en su tabla, migrar esta pertenencia es *expand/contract* de una tabla pequeña; lo contrario (que `FAM-UNIT` escriba en una tabla de `ALUM`) violaría `INV-007`.

### 1.4 `guardian_student_links` (`1.14-a`) — vínculo tutor ↔ alumno

| Columna | Tipo | Nulo | Defecto | Descripción |
|---------|------|------|---------|-------------|
| `public_id` | ULID | No | — | |
| `guardian_id` | `bigint` | No | — | FK compuesta → `guardians` |
| `student_person_id` | `bigint` | No | — | FK compuesta → `people` |
| `relationship` | `text` | No | — | `CHECK IN ('padre','madre','tutor_legal','abuelo','apoderado','otro')` (`OPEN-FAM-UNIT-07`) |
| `is_legal_guardian` | `boolean` | No | `false` | Representación legal (patria potestad o tutela) [DERIVADA, `funcional.md §3.3`] |
| `legal_guardian_verified_at` | `TIMESTAMPTZ` | Sí | — | Cuándo verificó el centro la representación |
| `legal_guardian_verified_by` | `bigint` | Sí | — | FK compuesta → `users` |
| `access_level` | `text` | No | `'lectura'` | `CHECK IN ('lectura','lectura_escritura')` (`REQ-FAM-UNIT-001`). Valor por defecto: el más restrictivo [DERIVADA] |
| `restricted_categories` | `jsonb` | No | `'[]'` | Array de categorías restringidas. `CHECK (jsonb_typeof(restricted_categories) = 'array' AND restricted_categories <@ '["salud"]'::jsonb)` con el vocabulario de `OPEN-FAM-UNIT-07` |
| `is_emergency_contact` | `boolean` | No | `false` | `REQ-FAM-UNIT-002` |
| `status` | `text` | No | `'activo'` | `CHECK IN ('activo','revocado')` |
| `revocation_reason` | `text` | Sí | — | `CHECK (revocation_reason IN ('resolucion_judicial'))` |
| `revoked_at` | `TIMESTAMPTZ` | Sí | — | |
| `revoked_by` | `bigint` | Sí | — | FK compuesta → `users` |

Restricciones:

- `UNIQUE (tenant_id, guardian_id, student_person_id) WHERE deleted_at IS NULL` (`RN-FAM-UNIT-30`).
- `UNIQUE (tenant_id, student_person_id) WHERE is_emergency_contact AND status = 'activo' AND deleted_at IS NULL` (`RN-FAM-UNIT-31`: garantizado por índice, no por comprobación, porque dos peticiones simultáneas lo romperían).
- Coherencia de estado: `CHECK ((status = 'activo' AND revocation_reason IS NULL AND revoked_at IS NULL) OR (status = 'revocado' AND revocation_reason IS NOT NULL AND revoked_at IS NOT NULL))`.
- `CHECK (NOT (is_emergency_contact AND status = 'revocado'))`.
- `CHECK (legal_guardian_verified_at IS NULL OR is_legal_guardian)`.
- La guarda de autovínculo (`RN-FAM-UNIT-21`: la persona del tutor no es la del alumno) se valida en servicio: exigirla en base de datos requeriría desnormalizar `person_id` del tutor en el vínculo.

Al levantar una revocación, `revocation_reason`, `revoked_at` y `revoked_by` vuelven a `NULL`; el histórico de las dos transiciones queda en `audit_logs` con su autor y su instante (`CA-FAM-UNIT-035`).

### 1.5 `custody_restrictions` — *condicionada a `OPEN-FAM-UNIT-05`*

Tabla **separada** de categoría especial (`CLAUDE.md §8`, `§16.3` regla 5). Campos mínimos propuestos, **[DERIVADA]** (ningún requisito los enumera):

| Columna | Tipo | Nulo | Descripción |
|---------|------|------|-------------|
| `public_id` | ULID | No | |
| `guardian_student_link_id` | `bigint` | No | FK compuesta → `guardian_student_links`; `UNIQUE (tenant_id, guardian_student_link_id) WHERE deleted_at IS NULL` |
| `resolution_reference_encrypted` | `text` | No | Referencia de la resolución, **cifrada a nivel de campo** (`RSEC-ISO-008`). Mecanismo y claves: ADR pendiente |
| `resolution_date_encrypted` | `text` | Sí | Fecha de la resolución, cifrada |
| `document_object_key` | `text` | Sí | Clave del documento en el *bucket* privado (`ADR-013`), objeto cifrado; descarga por URL firmada de caducidad corta tras comprobar el permiso |

Política de auditoría `Redacted` (`ADR-035`): nunca se registra ningún valor. Cada lectura registra `read` (`RPERM-015`). Sin índice más allá del único: la consulta es siempre por vínculo.

### 1.6 `image_consent_decisions` (`1.14-b`) — histórico inmutable

`TenantMigration::tenantTableAppendOnly()`: sin `deleted_at`, sin `updated_at`, `REVOKE UPDATE, DELETE` para `plataforma_app`; modelo `AppendOnlyModel` (`ADR-034 §6`, `§16.3` regla 6, `RN-FAM-UNIT-42`). Mismo patrón que `academic_year_reopenings` (`1.11c`).

| Columna | Tipo | Nulo | Descripción |
|---------|------|------|-------------|
| `public_id` | ULID | No | |
| `student_person_id` | `bigint` | No | FK compuesta → `people` |
| `level` | `text` | No | `CHECK IN ('web','redes_sociales')` |
| `decision` | `text` | No | `CHECK IN ('autorizo','no_autorizo')` (`RN-FAM-UNIT-40`) |
| `guardian_id` | `bigint` | No | FK compuesta → `guardians`. Quién decide |
| `recorded_by` | `bigint` | No | FK compuesta → `users`. Quién la registra (el propio tutor o personal del centro) |
| `channel` | `text` | No | `CHECK IN ('portal','presencial')` |
| `consent_text_version_id` | `bigint` | Sí | FK compuesta → `terms_versions` si se aprueba `OPEN-FAM-UNIT-09`; si no, la columna no se crea |
| `occurred_at` | `TIMESTAMPTZ` | No | Fecha y hora de la decisión |
| `ip_address` | `inet` | Sí | IP de la petición |
| `user_agent` | `text` | Sí | |

### 1.7 `image_consents` (`1.14-b`) — estado vigente

Proyección mantenida en la **misma transacción** que cada decisión (`RN-FAM-UNIT-44`), para que la comprobación de `INV-014` sea una lectura por clave y no un recálculo.

| Columna | Tipo | Nulo | Defecto | Descripción |
|---------|------|------|---------|-------------|
| `public_id` | ULID | No | — | |
| `student_person_id` | `bigint` | No | — | FK compuesta → `people` |
| `level` | `text` | No | — | `CHECK IN ('web','redes_sociales')` |
| `state` | `text` | No | `'pendiente'` | `CHECK IN ('autorizo','no_autorizo','pendiente')` |
| `last_decision_at` | `TIMESTAMPTZ` | Sí | — | |

Restricciones: `UNIQUE (tenant_id, student_person_id, level) WHERE deleted_at IS NULL`. La ausencia de fila equivale a `pendiente` (fallo en cerrado); la fila se crea con la primera decisión.

**Bloqueo**: el recálculo toma `SELECT … FOR UPDATE` sobre la fila de `(alumno, nivel)` (creándola si no existe con `INSERT … ON CONFLICT DO NOTHING` y releyéndola bloqueada) antes de leer las decisiones.

### 1.8 `terms_versions` (`1.14-c`)

| Columna | Tipo | Nulo | Defecto | Descripción |
|---------|------|------|---------|-------------|
| `public_id` | ULID | No | — | |
| `version_number` | `integer` | No | — | Correlativo por centro, asignado en servicio bajo bloqueo; `UNIQUE (tenant_id, version_number)` (no parcial: un número no se reutiliza nunca) |
| `title_translations` | `jsonb` | No | — | Columna traducible con el ayudante de `ADR-062 §5.1` (`AR-16`) |
| `body_translations` | `jsonb` | No | — | Ídem. Texto legal del centro |
| `status` | `text` | No | `'borrador'` | `CHECK IN ('borrador','publicada','retirada')` |
| `requires_reacceptance` | `boolean` | No | `false` | Fijado al publicar (`REQ-FAM-UNIT-003`) |
| `published_at` | `TIMESTAMPTZ` | Sí | — | `CHECK ((status = 'borrador') = (published_at IS NULL))` |

Restricciones: `UNIQUE (tenant_id) WHERE status = 'publicada' AND deleted_at IS NULL` (`RN-FAM-UNIT-60`, por índice y no por comprobación). Si se aprueba `OPEN-FAM-UNIT-09`, se añade `kind text CHECK IN ('condiciones_uso','consentimiento_imagen_web','consentimiento_imagen_redes')` y la unicidad pasa a `(tenant_id, kind)`; la columna se crea desde el principio para no migrar después (decisión a tomar junto con `OPEN-FAM-UNIT-09`).

### 1.9 `terms_acceptances` (`1.14-c`) — histórico inmutable

`tenantTableAppendOnly()` y `AppendOnlyModel`, como §1.6.

| Columna | Tipo | Nulo | Descripción |
|---------|------|------|-------------|
| `public_id` | ULID | No | |
| `user_id` | `bigint` | No | FK compuesta → `users` |
| `terms_version_id` | `bigint` | No | FK compuesta → `terms_versions` |
| `shown_locale` | `text` | No | `CHECK IN ('es-ES','en','de','fr')` (`RN-FAM-UNIT-65`) |
| `accepted_at` | `TIMESTAMPTZ` | No | |
| `ip_address` | `inet` | Sí | |
| `user_agent` | `text` | Sí | |

Restricciones: `UNIQUE (tenant_id, user_id, terms_version_id)` (`RN-FAM-UNIT-64`, idempotencia).

---

## 2. Relaciones

```mermaid
erDiagram
    family_units ||--o{ guardians : "agrupa (un tutor, una unidad)"
    family_units ||--o{ family_unit_students : "agrupa (un alumno, una unidad)"
    family_units |o--o| guardians : "principal_guardian_id"
    people ||--o| guardians : "faceta 0..1"
    people ||--o| family_unit_students : "persona del alumno"
    guardians ||--o{ guardian_student_links : "vincula"
    people ||--o{ guardian_student_links : "alumno (puede ser de otra unidad)"
    guardian_student_links ||--o| custody_restrictions : "condicionada"
    people ||--o{ image_consent_decisions : "alumno"
    guardians ||--o{ image_consent_decisions : "decide"
    users ||--o{ image_consent_decisions : "registra"
    people ||--o{ image_consents : "estado vigente por nivel"
    terms_versions ||--o{ terms_acceptances : "versión aceptada"
    users ||--o{ terms_acceptances : "acepta"
```

`people` y `users` son de `Core`: este módulo los referencia por clave foránea y los **lee** solo por interfaz (`INV-007`, `OPEN-FAM-UNIT-03`).

---

## 3. Índices

Además de los únicos de §1 (que ya sirven a sus consultas) y del índice `(tenant_id, <columna>)` que `tenantForeignId()` crea en cada FK desde #407:

| Índice | Consulta que lo necesita |
|--------|---------------------------|
| `guardian_student_links (tenant_id, guardian_id) WHERE status = 'activo' AND deleted_at IS NULL` | El **resolutor `unidad_familiar`** y `FamilyMembership`: alumnos alcanzados por el tutor del sujeto. Se ejecuta en cada petición de un tutor; es la consulta más caliente del módulo |
| `guardian_student_links (tenant_id, student_person_id) WHERE deleted_at IS NULL` | Tutores de un alumno (`FamilyMembership`, `GuardianRecipients`, ficha); `isAccessRevoked()` |
| `family_unit_students (tenant_id, family_unit_id) WHERE deleted_at IS NULL` | Alumnos de una unidad (ficha, regla `RN-FAM-UNIT-03`) |
| `guardians (tenant_id, family_unit_id) WHERE deleted_at IS NULL` | Tutores de una unidad |
| `image_consent_decisions (tenant_id, student_person_id, level, occurred_at DESC, id DESC)` | Recálculo del estado vigente y histórico paginado por cursor |
| `image_consents (tenant_id, level, state)` | Listado y exportación filtrados por nivel y estado («quién no ha decidido») |
| `terms_acceptances (tenant_id, user_id, accepted_at DESC)` | Comprobación del muro en cada petición de un tutor obligado (`RN-FAM-UNIT-62`) |
| `family_units (tenant_id, code text_pattern_ops) WHERE deleted_at IS NULL` | Búsqueda por prefijo de código en el listado. **Solo si** el listado ofrece búsqueda `q`; si no, se retira (un índice sin consulta es deuda) |

Sin particionado: volumen de cientos a pocos miles de filas por centro; las dos tablas de solo anexar crecen con las decisiones de familias (decenas por alumno a lo largo de su escolaridad). Evaluado y descartado (`ADR-029`).

---

## 4. Política de auditoría por modelo (`ADR-035`) y *morph map*

Todo modelo extiende `TenantModel` (o `AppendOnlyModel`), implementa `Auditable` (`AR-06`) y tiene alias en `Relation::enforceMorphMap` del `ServiceProvider` del módulo.

| Modelo | Alias | Política | Atributos registrados / notas |
|--------|-------|----------|--------------------------------|
| `FamilyUnit` | `family_unit` | `Selective` | `principal_guardian_id`, `deleted_at`, `created_by`, `updated_by`. `code` se redacta como `identifier` [DERIVADA]: un código de familia identifica indirectamente a una familia concreta |
| `Guardian` | `guardian` | `Full` | Solo ids internos y la unidad; ningún dato personal |
| `FamilyUnitStudent` | `family_unit_student` | `Full` | Ídem |
| `GuardianStudentLink` | `guardian_student_link` | `Selective` | `relationship`, `is_legal_guardian`, `legal_guardian_verified_at`, `access_level`, `restricted_categories`, `is_emergency_contact`, `status`, `revocation_reason`, `revoked_at`, `deleted_at`, `created_by`, `updated_by`. Todos son códigos o marcas, sin texto libre (`RN-FAM-UNIT-36`: el detalle judicial nunca vive aquí) |
| `CustodyRestriction` (condicionada) | `custody_restriction` | `Redacted` | Ningún valor. Lectura auditada (`read`) |
| `ImageConsentDecision` | `image_consent_decision` | `Full` | Append-only; su `created` es el registro. `ip_address`/`user_agent` se registran como `identifier` redactado si la política lo exige (decisión de implementación con `db-reviewer`) |
| `ImageConsent` | `image_consent` | `Full` | Proyección; registra `state` y `last_decision_at` |
| `TermsVersion` | `terms_version` | `Full` | Los textos largos se redactan solos como `oversized` (`ADR-035 §4`); el desglose por idioma de `ADR-062 §5.5` |
| `TermsAcceptance` | `terms_acceptance` | `Full` | Append-only |

Ninguna exclusión de eventos (`ADR-040`).

---

## 5. Checklist obligatorio

- [x] `tenant_id` primero en todos los índices de §3.
- [x] RLS en cada tabla nueva, por `tenantTable()`/`tenantTableAppendOnly()` (`IsolationBatteryTest` #8).
- [x] `academic_year_id`: **no aplica** en ninguna tabla (`ADR-034 §4`), luego tampoco `AR-13`.
- [x] `created_at`/`updated_at`/`deleted_at`/`created_by`/`updated_by` en las ordinarias; las de solo anexar usan `occurred_at`/`accepted_at` y `recorded_by`/`user_id` (mismo criterio que `audit_logs`).
- [x] Claves foráneas compuestas, `CHECK` de enumerados y de coherencia de estado en base de datos.
- [x] Política de auditoría y alias de *morph map* declarados (§4).
- [x] `TIMESTAMPTZ`, `text`, sin `ENUM` (`AR-04`); `public_id` ULID en toda entidad expuesta (`AR-05`). **Claves de catálogo expuestas en URL: ninguna** (`ADR-051 §5.1`).
- [x] Importes: no aplica.
- [ ] `NULLS NOT DISTINCT`: no se necesita; todas las unicidades son sobre columnas `NOT NULL` o parciales.
- [ ] **Datos de categoría especial**: `custody_restrictions`, condicionada a `OPEN-FAM-UNIT-05`. `AR-09` saltará en cuanto se declare su permiso: la especificación del ADR debe sustituir el cable trampa por la regla real.
- [x] Particionado evaluado y descartado (§3).

---

## 6. Retención y supresión (`ADR-004`, `ADR-035`, `REQ-PRIV-006`)

El catálogo de retención por entidad es trabajo de `1.23b`/`REQ-PRIV-006`, **sin especificar todavía**; no se inventan plazos aquí. Lo que sí fija este módulo:

| Tabla | Nivel de `ADR-004` previsto | Por qué |
|-------|-----------------------------|---------|
| `family_units`, `guardians`, `family_unit_students`, `guardian_student_links` | Borrado lógico mientras el alumno esté en el centro; anonimización o purga después, según el catálogo | El vínculo y su revocación son la prueba ante un incidente con un menor (`REQ-FAM-UNIT-005`): no se purgan mientras pueda hacer falta |
| `image_consent_decisions`, `terms_acceptances` | Conservación como evidencia del consentimiento mientras dure el tratamiento y el plazo de prescripción que fije el catálogo | Una prueba de consentimiento que se borra deja de probar; supresión por retención, nunca por edición (`ADR-035`) |
| `custody_restrictions` | El plazo más corto compatible con la protección del menor | Categoría especial; minimización |

La purga física de un tenant completo debe incluir estas nueve tablas en el orden de dependencias de §2 (issue #371, `1.23b`).
