# REQ-FAM-UNIT · API

> **Estado**: **APROBADA por el usuario** (2026-10-26, todas las recomendaciones de `OPEN-FAM-UNIT-01..21` aceptadas tal cual; ver `funcional.md §13`). Los *endpoints* de §2.6 dependen de `OPEN-FAM-UNIT-05`; los de §2.7 y §2.8, de la partición de `funcional.md §1.2`; el muro (§2.8) de `OPEN-FAM-UNIT-12`.

Prefijo: `/api/v1`. Tenant por *host* (`ADR-033 §2`). Todo según `ADR-038`: recurso individual desnudo, colección en `{data, meta}` (§3), filtros planos y valores múltiples separados por comas (§5.2), orden por lista blanca con desempate por `id` (§5.3), errores `application/problem+json` con `type` URN y `errors` como objeto indexado por campo con arrays de `{code, message, params}` (§6), `404` frente a `403` (§6.4).

**Identificadores**: solo `public_id` ULID en rutas y cuerpos (`ADR-029`). El alumno se nombra `student_person` (`public_id` de su **persona**, `funcional.md §7.4`, `OPEN-FAM-UNIT-01`); el tutor se crea a partir del `public_id` de su **usuario** (`user`).

**Cadena de *middleware*** de toda ruta salvo las de autoservicio de §2.8: `RequireAuthenticatedUser` → `module-enabled:fam_unit` → `permission:<código>` (`AR-07a`, `AR-07b`). Las de §2.8 (`/me/terms*`) van con `RequireAuthenticatedUser` → `module-enabled:fam_unit`, autorizadas por identidad (precedente `GET /me`), y amplían la lista cerrada de `AR-07a` (aprobación expresa, `OPEN-FAM-UNIT-12`).

**Ámbito**: los *endpoints* marcados «ámbito» acotan con `ScopedQuery` según la `PermissionDecision` (`ADR-044 §4.2`): con `unidad_familiar`, el listado devuelve solo lo alcanzado (`funcional.md §3.6`) y el detalle de algo no alcanzado responde **`404`** como si no existiera.

**Curso académico**: no aplica; ningún recurso del módulo es por curso.

---

## 1. Representaciones

### 1.1 `FamilyUnit`

```json
{
  "public_id": "01JB0000000000000000000001",
  "code": "UF-000123",
  "principal_guardian": "01JB0000000000000000000010",
  "guardians_count": 2,
  "students_count": 2,
  "created_at": "2026-10-26T09:00:00Z",
  "updated_at": "2026-10-26T09:00:00Z"
}
```

Para un sujeto con ámbito `unidad_familiar`, `students_count` cuenta solo los alumnos que alcanza y `guardians_count` solo los tutores con vínculo activo con esos alumnos (`RN-FAM-UNIT-36`).

### 1.2 `Guardian`

`public_id`, `family_unit` (`public_id`), `user` (`public_id`), `display_name` (de `Core`; cadena vacía si el usuario está borrado, `OPEN-FAM-UNIT-16`), `is_principal`, `created_at`, `updated_at`. **Nunca** correo, teléfono ni documento: son de `Core` y se consultan allí con su permiso (`OPEN-FAM-UNIT-20`).

### 1.3 `FamilyUnitStudent`

`public_id` (de la pertenencia), `family_unit`, `student_person` (`public_id`), `display_name`, `created_at`.

### 1.4 `GuardianStudentLink`

```json
{
  "public_id": "01JB0000000000000000000020",
  "guardian": "01JB0000000000000000000010",
  "student_person": "01JB0000000000000000000030",
  "relationship": "madre",
  "is_legal_guardian": true,
  "legal_guardian_verified_at": "2026-10-26T09:05:00Z",
  "access_level": "lectura_escritura",
  "restricted_categories": [],
  "is_emergency_contact": true,
  "status": "activo",
  "revocation": null,
  "created_at": "2026-10-26T09:05:00Z",
  "updated_at": "2026-10-26T09:05:00Z"
}
```

`revocation` es `{ "reason": "resolucion_judicial", "revoked_at": "…", "revoked_by": "<user public_id>" }` cuando `status = revocado`, y **solo** se incluye si el sujeto tiene `revocacion_tutor.leer`; si no, el campo se omite. Un sujeto con ámbito `unidad_familiar` nunca recibe vínculos revocados (`RN-FAM-UNIT-36`). Enumerados sin traducir (`ADR-038 §3.2`), declarados extensibles en OpenAPI (`§7.3`).

### 1.5 `ImageConsent` (estado vigente)

`student_person`, `display_name`, `family_unit`, `level` (`web` | `redes_sociales`), `state` (`autorizo` | `no_autorizo` | `pendiente`), `last_decision_at`. Sin `public_id` propio en la representación: se identifica por `(student_person, level)`.

### 1.6 `ImageConsentDecision`

`public_id`, `student_person`, `level`, `decision`, `guardian` (`public_id`), `recorded_by` (`public_id` de usuario), `channel`, `consent_text_version` (si `OPEN-FAM-UNIT-09`), `occurred_at`. **No** se exponen `ip_address` ni `user_agent` en la API (se conservan como evidencia; consultables en caso de litigio por el procedimiento de `REQ-PRIV-003`).

### 1.7 `TermsVersion`

`public_id`, `version_number`, `title`, `title_translations`, `body`, `body_translations`, `status`, `requires_reacceptance`, `published_at`, `created_at`, `updated_at`. `title`/`body` resueltos en el servidor y de solo lectura (`ADR-062 §5.4`).

### 1.8 `TermsAcceptance`

`public_id`, `user` (`public_id`), `terms_version` (`public_id`), `version_number`, `shown_locale`, `accepted_at`.

---

## 2. Endpoints

Permisos completos en `permisos.md`. Errores comunes a todos (no se repiten): `401`, `403 urn:pge:error:forbidden`, `403 urn:pge:error:module-disabled`, `403 urn:pge:error:terms-acceptance-required` (§2.8), `429`.

### 2.1 Unidades familiares (`1.14-a`)

| Verbo y ruta | Permiso | Ámbito | Respuesta | Errores propios |
|---|---|---|---|---|
| `GET /family-units` | `familia.leer` | sí | 200 colección | 422 parámetro |
| `POST /family-units` | `familia.crear` | — | 201 + `Location` | 422 `code_taken`, `code_invalid` |
| `GET /family-units/{public_id}` | `familia.leer` | sí | 200 | 404 |
| `PATCH /family-units/{public_id}` | `familia.actualizar` | — | 200 | 404, 422 `code_taken`, `principal_not_member` |
| `DELETE /family-units/{public_id}` | `familia.eliminar` | — | 204 | 404, 409 `has_members` |
| `POST /family-units/{public_id}/restore` | `familia.eliminar` | — | 200 | 404, 422 `code_taken` |

`POST` cuerpo: `{ "code": "UF-000123" }` (o vacío si el código lo genera el sistema, `OPEN-FAM-UNIT-06`). `PATCH`: `code`, `principal_guardian` (`public_id` de tutor o `null`).

### 2.2 Tutores (`1.14-a`)

| Verbo y ruta | Permiso | Ámbito | Respuesta | Errores propios |
|---|---|---|---|---|
| `GET /guardians` | `familia.leer` | sí | 200 colección | 422 |
| `POST /guardians` | `familia.crear` | — | 201 | 422 `user_not_found`, `already_exists`, `family_unit_not_found` |
| `GET /guardians/{public_id}` | `familia.leer` | sí | 200 | 404 |
| `PATCH /guardians/{public_id}` | `familia.actualizar` | — | 200 | 404 (el cuerpo no admite `family_unit`: un tutor no cambia de unidad; se da de baja y se crea de nuevo, `RN-FAM-UNIT-12`; decidido por el usuario el 2026-10-26) |
| `DELETE /guardians/{public_id}` | `familia.eliminar` | — | 200 con `{ "removed_links": n, "principal_cleared": bool }` (`RN-FAM-UNIT-12`) | 404 |

`POST` cuerpo: `{ "user": "<public_id>", "family_unit": "<public_id>" }`. Con ámbito `unidad_familiar`, el sujeto alcanza los tutores con vínculo activo con alguno de sus alumnos.

### 2.3 Alumnado de la unidad (`1.14-a`)

| Verbo y ruta | Permiso | Ámbito | Respuesta | Errores propios |
|---|---|---|---|---|
| `GET /family-units/{public_id}/students` | `familia.leer` | sí | 200 colección | 404 |
| `POST /family-units/{public_id}/students` | `familia.actualizar` | — | 201 | 404 (unidad), 422 `student_not_found`, `already_in_unit` |
| `DELETE /family-units/{public_id}/students/{membership}` | `familia.actualizar` | — | 200 `{ "remaining_links": n }` (`RN-FAM-UNIT-22`) | 404 |

`POST` cuerpo: `{ "student_person": "<public_id>" }`. Añadir o quitar alumnos de una unidad es **configurarla** y exige `actualizar`, no `crear`/`eliminar` [DERIVADA, precedente `PUT /groups/{id}/subjects`].

### 2.4 Vínculos (`1.14-a`)

| Verbo y ruta | Permiso | Ámbito | Respuesta | Errores propios |
|---|---|---|---|---|
| `GET /guardian-links` | `vinculo_tutor.leer` | sí | 200 colección | 422 |
| `POST /guardian-links` | `vinculo_tutor.crear` | — | 201 | 422 `already_exists`, `self_link`, `emergency_contact_taken`, `guardian_not_found`, `student_not_found` |
| `GET /guardian-links/{public_id}` | `vinculo_tutor.leer` | sí | 200 | 404 |
| `PATCH /guardian-links/{public_id}` | `vinculo_tutor.actualizar` | — | 200 | 404, 422 (incluido `status`/`revocation` en el cuerpo: `not_editable`) |
| `DELETE /guardian-links/{public_id}` | `vinculo_tutor.eliminar` | — | 204 | 404 |

`POST` cuerpo:

```json
{
  "guardian": "01JB…10",
  "student_person": "01JB…30",
  "relationship": "madre",
  "is_legal_guardian": true,
  "access_level": "lectura_escritura",
  "restricted_categories": [],
  "is_emergency_contact": true
}
```

Marcar `is_legal_guardian = true` rellena `legal_guardian_verified_at`/`_by` con el instante y el usuario de la petición (el centro afirma haberlo verificado). Desmarcarlo los vacía.

### 2.5 Revocación (`1.14-a`)

| Verbo y ruta | Permiso | Respuesta | Errores propios |
|---|---|---|---|
| `POST /guardian-links/{public_id}/revocation` | `revocacion_tutor.crear` | 200 `GuardianStudentLink` con `revocation` y `{ "principal_cleared": bool }` (`RN-FAM-UNIT-38`) | 404, 409 `invalid_transition` |
| `DELETE /guardian-links/{public_id}/revocation` | `revocacion_tutor.eliminar` | 200 `GuardianStudentLink` | 404, 409 `invalid_transition` |

`POST` cuerpo: `{ "reason": "resolucion_judicial" }`. Sin campo de texto libre a propósito (`RN-FAM-UNIT-36`): el detalle va a §2.6. Para leer vínculos revocados con su `revocation` hace falta además `revocacion_tutor.leer` (filtro `status=revocado` de `GET /guardian-links`).

**Idempotencia** (`INV-011`): no se exige `Idempotency-Key`; repetir una revocación ya hecha responde `409 invalid_transition`, que el cliente interpreta como «ya estaba».

### 2.6 Restricción de custodia — *condicionada a `OPEN-FAM-UNIT-05`*

| Verbo y ruta | Permiso | Respuesta | Errores propios |
|---|---|---|---|
| `GET /guardian-links/{public_id}/custody-restriction` | `restriccion_custodia.leer` (especial) | 200; **registra `read`** en `audit_logs` | 404 (también si el vínculo no está revocado o no hay detalle) |
| `PUT /guardian-links/{public_id}/custody-restriction` | `restriccion_custodia.actualizar` (especial) | 200 | 404, 409 `link_not_revoked`, 422 |
| `POST /guardian-links/{public_id}/custody-restriction/document` | `restriccion_custodia.actualizar` | 201 | 404, 422 tipo real o tamaño (`RSEC-OWASP-012`) |
| `GET /guardian-links/{public_id}/custody-restriction/document` | `restriccion_custodia.leer` | 200 con URL firmada de caducidad corta; registra `read` | 404 |

### 2.7 Consentimiento de imagen (`1.14-b`)

| Verbo y ruta | Permiso | Ámbito | Respuesta | Errores propios |
|---|---|---|---|---|
| `GET /image-consents` | `consentimiento_imagen.leer` | sí | 200 colección (§1.5) | 422 |
| `GET /image-consent-decisions` | `consentimiento_imagen.leer` | sí | 200 colección (§1.6); exige `student_person` | 404 (alumno no alcanzado), 422 |
| `POST /image-consent-decisions` | `consentimiento_imagen.actualizar` | sí | 201 `{ "decision": ImageConsentDecision, "state": ImageConsent }` | 404 (alumno no alcanzado), 422 `not_legal_guardian`, `guardian_required`, `guardian_not_linked` |
| `POST /image-consents/exports` | `consentimiento_imagen.exportar` | — | 202 recurso de exportación de `Core` (`GET /data-exports/{id}`) | 422 `q_not_allowed`, filtros inválidos |

`POST /image-consent-decisions` cuerpo:

```json
{ "student_person": "01JB…30", "level": "redes_sociales", "decision": "no_autorizo", "on_behalf_of": null, "channel": "portal" }
```

- Si el sujeto **es** tutor del alumno (vínculo activo propio), `on_behalf_of` se omite o es su propio tutor, y `channel = portal`.
- Si el sujeto es personal del centro (ámbito `todos`), `on_behalf_of` es obligatorio (`guardian_required`) y debe ser un tutor con vínculo activo y representación legal sobre el alumno (`guardian_not_linked`, `not_legal_guardian`); `channel = presencial`.
- Las decisiones no se editan ni borran: no hay `PATCH` ni `DELETE` (`RN-FAM-UNIT-42`).

**Idempotencia**: no se exige clave; registrar dos veces la misma decisión crea dos filas de histórico sin cambiar el estado, y así debe constar (cada envío es un acto del tutor).

### 2.8 Condiciones de uso (`1.14-c`)

| Verbo y ruta | Permiso | Respuesta | Errores propios |
|---|---|---|---|
| `GET /terms-versions` | `condiciones_uso.leer` | 200 colección | 422 |
| `POST /terms-versions` | `condiciones_uso.crear` | 201 (`borrador`) | 422 traducciones (`ADR-062`) |
| `GET /terms-versions/{public_id}` | `condiciones_uso.leer` | 200 | 404 |
| `PATCH /terms-versions/{public_id}` | `condiciones_uso.actualizar` | 200 | 404, 409 `not_draft`, 422 |
| `DELETE /terms-versions/{public_id}` | `condiciones_uso.eliminar` | 204 (solo `borrador`) | 404, 409 `not_draft` |
| `POST /terms-versions/{public_id}/publish` | `condiciones_uso.publicar` | 200 | 404, 409 `not_draft`, 422 `default_locale_missing` |
| `GET /terms-acceptances` | `aceptacion_condiciones.leer` | 200 colección | 422 |
| `GET /me/terms` | identidad | 200 `{ "required": bool, "version": TermsVersion\|null }` | — |
| `POST /me/terms-acceptances` | identidad | 201 `TermsAcceptance` (200 si ya existía, `RN-FAM-UNIT-64`) | 409 `version_not_current`, 422 |

`POST /terms-versions/{id}/publish` cuerpo: `{ "requires_reacceptance": true }`. `POST /me/terms-acceptances` cuerpo: `{ "terms_version": "<public_id>" }`; el servidor registra el idioma en que se sirvió (`shown_locale`) a partir del idioma de la petición.

**Muro** (`OPEN-FAM-UNIT-12`): mientras el usuario esté obligado y sin aceptación exigible, toda ruta no exenta responde `403` con `type = urn:pge:error:terms-acceptance-required` y `params.version` = `public_id` de la versión pendiente. Exentas (propuesta): `GET /me`, `GET /me/terms`, `POST /me/terms-acceptances`, cierre de sesión, `GET /tenant/branding`.

---

## 3. Paginación, filtrado y ordenación (`ADR-038 §4`, `§5`)

| Listado | Paginación | Por qué | Filtros | Orden (lista blanca) |
|---------|-----------|---------|---------|----------------------|
| `GET /family-units` | Página | Entidades de alta administrativa: cientos a pocos miles por centro | `q` (prefijo de código), `has_principal`, `include_deleted` (solo con `familia.eliminar`) | `code` (defecto), `created_at` |
| `GET /guardians` | Página | Ídem | `family_unit`, `user` | `created_at` |
| `GET /family-units/{id}/students` | Sin paginar | Decenas como mucho por unidad [DERIVADA] | — | `display_name` |
| `GET /guardian-links` | Página | Ídem | `family_unit`, `guardian`, `student_person`, `status` (`revocado` exige `revocacion_tutor.leer`), `is_emergency_contact`, `is_legal_guardian` | `created_at` |
| `GET /image-consents` | Página | Una fila por alumno y nivel | `family_unit`, `student_person`, `level`, `state` | `last_decision_at`, `state` |
| `GET /image-consent-decisions` | Cursor sobre `(occurred_at DESC, id DESC)` | Histórico de solo anexar que crece sin límite natural | `student_person` (obligatorio), `level` | Fijo |
| `GET /terms-versions` | Página | Decenas | `status` | `version_number` (defecto desc) |
| `GET /terms-acceptances` | Cursor sobre `(accepted_at DESC, id DESC)` | Solo anexar, crece con cada tutor y versión | `terms_version`, `user` | Fijo |

Filtrar por `student_person` o `family_unit` que el sujeto no alcanza no es error: devuelve vacío (el ámbito se aplica antes que el filtro). Un `public_id` mal formado ⇒ `422`.

---

## 4. Exportación: informe de consentimientos (`ADR-054`, `ADR-055`)

- `POST /image-consents/exports` (`consentimiento_imagen.exportar`). En cola (`INV-012`) sobre la primitiva `data_exports` de `Core` con un `kind` nuevo `image_consents` (ampliación de su catálogo, `OPEN-FAM-UNIT-11`), enlace caducable, solicitud auditada como `exported` con los filtros aplicados.
- **Paridad de filtros** con `GET /image-consents` (`family_unit`, `student_person`, `level`, `state`), sin paginación ni orden. **Sin `q`**: si llega, `422 fam_unit.export.q_not_allowed` (`ADR-054 §9`).
- Ámbito: el permiso `exportar` solo admite `todos` (`permisos.md`), así que no hay que acotar dentro del trabajo; si algún día admitiera `unidad_familiar`, el filtro se aplicaría **dentro del trabajo en cola**, no solo en la petición (`REQ-PERM/funcional.md §6.3`).
- **Esquema CSV cerrado** (`ADR-055`), una fila por alumno y nivel, sin literales de interfaz, texto libre neutralizado por `CsvWriter`:

| Columna | Contenido |
|---------|-----------|
| `family_unit_code` | Código de la unidad |
| `student_person_public_id` | ULID |
| `student_display_name` | Nombre visible |
| `level` | `web` \| `redes_sociales` |
| `state` | `autorizo` \| `no_autorizo` \| `pendiente` |
| `last_decision_at` | ISO 8601 UTC o vacío |

---

## 5. Eventos de dominio emitidos

Ninguno en `1.14` (`ADR-048`, `funcional.md §7.6`). Las interfaces públicas de `funcional.md §7` sustituyen a los eventos para todo lo que necesita efecto inmediato.

## 6. Webhooks disponibles

Ninguno.

## 7. Códigos de error propios

Todos con prefijo `fam_unit.` y traducidos en los cuatro idiomas: `family_unit.code_taken`, `family_unit.code_invalid`, `family_unit.principal_not_member`, `family_unit.has_members`, `guardian.already_exists`, `student.already_in_unit`, `link.already_exists`, `link.self_link`, `link.emergency_contact_taken`, `link.invalid_transition`, `link.not_editable`, `custody.link_not_revoked` (condicionado), `image_consent.not_legal_guardian`, `image_consent.guardian_required`, `image_consent.guardian_not_linked`, `terms.not_draft`, `terms.default_locale_missing`, `terms.version_not_current`, `export.q_not_allowed`, `validation.user_not_found`, `validation.student_not_found`, `validation.guardian_not_found`, `validation.family_unit_not_found`. Error transversal nuevo: `urn:pge:error:terms-acceptance-required` (`OPEN-FAM-UNIT-12`).

Nota: el issue #60 (`ValidationErrorFormatter` antepone `core.` al `code` de cualquier módulo) sigue abierto; estos códigos heredarán su resolución.
