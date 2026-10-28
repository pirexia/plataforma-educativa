# REQ-FAM-UNIT · API

> **Estado**: **APROBADA por el usuario** (2026-10-26, todas las recomendaciones de `OPEN-FAM-UNIT-01..21` aceptadas tal cual; ver `funcional.md §13`). **`1.14-a` implementada (2026-10-26)**: los 21 *endpoints* de §2.1 a §2.5, documentados en `apps/api/openapi/paths/fam-unit.yaml`; detalles de implementación en `funcional.md §15`. Los *endpoints* de §2.6 dependen de `OPEN-FAM-UNIT-05`; los de §2.7 y §2.8, de la partición de `funcional.md §1.2`; el muro (§2.8) de `OPEN-FAM-UNIT-12`, **resuelta por `ADR-067` (ACEPTADA, 2026-10-28)**: exentas = L3 y `403` sin `params.version`. **Enmendada por `ADR-066` (ACEPTADA, 2026-10-27; `funcional.md §16`)**. **`1.14-b` IMPLEMENTADA (2026-10-27; `funcional.md §18`)**, aprobada por el usuario (`funcional.md §17`): los 11 *endpoints* de §2.7 y §2.8 documentados en `apps/api/openapi/paths/fam-unit.yaml` (32 en total con los de `1.14-a`), con las condiciones previas a datos reales de `funcional.md §13`: §2.7 completo (incluida la lectura del texto publicado, `GET /image-consent-texts`, `OPEN-FAM-UNIT-23`), el cambio de `PATCH /guardian-links/{id}` de §2.4 (`RN-FAM-UNIT-74`, `OPEN-FAM-UNIT-24`) y, de §2.8, la gestión de `/terms-versions` para los dos tipos de imagen; aceptaciones, `/me/terms*` y el muro siguen en `1.14-c`.

Prefijo: `/api/v1`. Tenant por *host* (`ADR-033 §2`). Todo según `ADR-038`: recurso individual desnudo, colección en `{data, meta}` (§3), filtros planos y valores múltiples separados por comas (§5.2), orden por lista blanca con desempate por `id` (§5.3), errores `application/problem+json` con `type` URN y `errors` como objeto indexado por campo con arrays de `{code, message, params}` (§6), `404` frente a `403` (§6.4).

**Identificadores**: solo `public_id` ULID en rutas y cuerpos (`ADR-029`). El alumno se nombra `student_person` (`public_id` de su **persona**, `funcional.md §7.4`, `OPEN-FAM-UNIT-01`); el tutor se crea a partir del `public_id` de su **usuario** (`user`).

**Cadena de *middleware*** de toda ruta salvo las de autoservicio de §2.8: `RequireAuthenticatedUser` → `module-enabled:fam_unit` → `permission:<código>` (`AR-07a`, `AR-07b`). Las de §2.8 (`/me/terms*`) van con `RequireAuthenticatedUser` → `module-enabled:fam_unit`, autorizadas por identidad (precedente `GET /me`), y amplían la lista cerrada de `AR-07a` de 35 a 37 (ampliación L1 de `ADR-067 §6`, aprobada el 2026-10-28): `fam-unit.me-terms.show` y `fam-unit.me-terms-acceptances.store`. Todas las rutas del módulo atraviesan antes el registro de muros de sesión (`require-session-walls`, `ADR-067`).

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

`student_person`, `display_name`, `family_unit`, `level` (`web` | `redes_sociales`), `state` (`autorizo` | `no_autorizo` | `pendiente`), `last_decision_at`. **Representación calculada** (`ADR-066 §5.2`): no hay tabla detrás ni `public_id`; se identifica por `(student_person, level)`. Sin cambios para el cliente respecto a la propuesta aprobada. `last_decision_at` es el `occurred_at` más reciente entre las decisiones que **cuentan** para el estado (`RN-FAM-UNIT-45`, `-72`), o `null` si no hay ninguna [DERIVADA: la proyección eliminada lo definía como la última decisión registrada; **aceptada por el usuario el 2026-10-27**]. Con ámbito `unidad_familiar`, nunca refleja la fecha de una decisión de un tutor revocado (`RN-FAM-UNIT-71`) [DERIVADA; **aceptada por el usuario el 2026-10-27**].

### 1.6 `ImageConsentDecision`

`public_id`, `student_person`, `level`, `decision`, `guardian` (`public_id`), `recorded_by` (`public_id` de usuario), `channel`, `consent_text_version` (`{ "public_id", "kind", "version_number" }`, o `null` solo en un `no_autorizo` sin texto publicado, `RN-FAM-UNIT-68`), `shown_locale` (o `null`, `RN-FAM-UNIT-43`), `occurred_at`. **No** se exponen `ip_address` ni `user_agent` en la API (se conservan como evidencia; consultables en caso de litigio por el procedimiento de `REQ-PRIV-003`).

### 1.7 `TermsVersion`

`public_id`, `kind` (`condiciones_uso` | `consentimiento_imagen_web` | `consentimiento_imagen_redes`), `version_number`, `title`, `title_translations`, `body`, `body_translations`, `status`, `requires_reacceptance`, `published_at`, `created_at`, `updated_at`. `title`/`body` resueltos en el servidor y de solo lectura (`ADR-062 §5.4`). `kind` es inmutable tras la creación [DERIVADA: cambiarlo rompería la numeración correlativa por tipo; **aceptada por el usuario el 2026-10-27**]; `PATCH` con `kind` ⇒ `422 fam_unit.terms.kind_not_editable`.

### 1.8 `TermsAcceptance`

`public_id`, `user` (`public_id`), `terms_version` (`public_id`), `version_number`, `shown_locale`, `accepted_at`.

### 1.9 `ImageConsentText` (texto publicado de un nivel, `1.14-b`, `RN-FAM-UNIT-75`)

```json
{
  "level": "web",
  "terms_version": { "public_id": "01JB0000000000000000000040", "kind": "consentimiento_imagen_web", "version_number": 2 },
  "title": "…",
  "body": "…",
  "locale": "fr",
  "published_at": "2026-10-27T08:00:00Z"
}
```

Representación de solo lectura, sin `public_id` propio: se identifica por `level`. `title` y `body` resueltos en el servidor en el idioma de la petición con la caída de `ADR-062`; `locale` es el idioma en que vienen de verdad (`es-ES` | `en` | `de` | `fr`), el mismo que se registra en `shown_locale` de una decisión `portal` (`RN-FAM-UNIT-43`, `OPEN-FAM-UNIT-25`) [DERIVADA; aceptada por el usuario el 2026-10-27]. Sin versión `publicada` del tipo del nivel, `terms_version`, `title`, `body`, `locale` y `published_at` son `null`. **No** incluye `title_translations` ni `body_translations` (eso es la gestión de `texto_legal`, §1.7). El `public_id` de `terms_version` es el que el servidor registrará en una decisión `portal` si sigue publicada en ese momento (`RN-FAM-UNIT-69`).

---

## 2. Endpoints

Permisos completos en `permisos.md`. Errores comunes a todos (no se repiten): `401`, `403 urn:pge:error:forbidden`, `403 urn:pge:error:module-disabled`, `403 urn:pge:error:terms-acceptance-required` (§2.8). **No hay `429` general**: la API autenticada no tiene limitador global (paso transversal aparte); el `429` existe solo en revocar y levantar (§2.5), registrar decisiones de imagen y pedir su informe (§2.7, `RN-FAM-UNIT-76`).

### 2.1 Unidades familiares (`1.14-a`)

| Verbo y ruta | Permiso | Ámbito | Respuesta | Errores propios |
|---|---|---|---|---|
| `GET /family-units` | `familia.leer` | sí | 200 colección | 403 (`include_deleted` sin `familia.eliminar`, D-2), 422 parámetro |
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
| `POST /guardians` | `familia.crear` | — | 201 | 422 `user_not_found`, `user_not_eligible`, `already_exists`, `family_unit_not_found` |
| `GET /guardians/{public_id}` | `familia.leer` | sí | 200 | 404 |
| `PATCH /guardians/{public_id}` | `familia.actualizar` | — | 200 | 404, 422 `not_editable` (el cuerpo no admite `family_unit`: un tutor no cambia de unidad; se da de baja y se crea de nuevo, `RN-FAM-UNIT-12`/`-14`; decidido por el usuario el 2026-10-26) |
| `DELETE /guardians/{public_id}` | `familia.eliminar` | — | 200 con `{ "removed_links": n, "principal_cleared": bool }` (`RN-FAM-UNIT-12`) | 404, 409 `link.revoked_not_deletable` (algún vínculo `revocado` bloquea la baja entera, `RN-FAM-UNIT-39`) |

`POST` cuerpo: `{ "user": "<public_id>", "family_unit": "<public_id>" }`. `user_not_eligible`: el usuario no está `pendiente` ni `activo` (`RN-FAM-UNIT-10`). Con ámbito `unidad_familiar`, el sujeto alcanza los tutores con vínculo activo con alguno de sus alumnos.

### 2.3 Alumnado de la unidad (`1.14-a`)

| Verbo y ruta | Permiso | Ámbito | Respuesta | Errores propios |
|---|---|---|---|---|
| `GET /family-units/{public_id}/students` | `familia.leer` | sí | 200 colección | 404 |
| `POST /family-units/{public_id}/students` | `familia.actualizar` | — | 201 | 404 (unidad), 422 `student_not_found`, `already_in_unit` |
| `DELETE /family-units/{public_id}/students/{membershipPublicId}` | `familia.actualizar` | — | 200 `{ "remaining_links": n }` (`RN-FAM-UNIT-22`) | 404 |

`POST` cuerpo: `{ "student_person": "<public_id>" }`. Añadir o quitar alumnos de una unidad es **configurarla** y exige `actualizar`, no `crear`/`eliminar` [DERIVADA, precedente `PUT /groups/{id}/subjects`].

### 2.4 Vínculos (`1.14-a`)

| Verbo y ruta | Permiso | Ámbito | Respuesta | Errores propios |
|---|---|---|---|---|
| `GET /guardian-links` | `vinculo_tutor.leer` | sí | 200 colección (sin vínculos `revocado` si falta `revocacion_tutor.leer`, `RN-FAM-UNIT-40`) | 403 (`status=revocado` sin `revocacion_tutor.leer`, D-2), 422 |
| `POST /guardian-links` | `vinculo_tutor.crear` | — | 201 | 422 `already_exists`, `self_link`, `emergency_contact_taken`, `guardian_not_found`, `student_not_found` |
| `GET /guardian-links/{public_id}` | `vinculo_tutor.leer` | sí | 200 | 404 (también un vínculo `revocado` sin `revocacion_tutor.leer`, `RN-FAM-UNIT-40`) |
| `PATCH /guardian-links/{public_id}` | `vinculo_tutor.actualizar` | — | 200 | 404 (también un vínculo `revocado` sin `revocacion_tutor.leer`), 422 (`status`, `revocation`, `guardian` o `student_person` en el cuerpo: `link.not_editable`; `link.emergency_contact_revoked` si el vínculo está revocado) |
| `DELETE /guardian-links/{public_id}` | `vinculo_tutor.eliminar` | — | 204 | 404 (también un `revocado` sin `revocacion_tutor.leer`, `RN-FAM-UNIT-40`), 409 `link.revoked_not_deletable` (`RN-FAM-UNIT-39`) |

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

Marcar `is_legal_guardian = true` rellena `legal_guardian_verified_at`/`_by` con el instante y el usuario de la petición (el centro afirma haberlo verificado). Desmarcarlo los vacía. (`RN-FAM-UNIT-74`, `OPEN-FAM-UNIT-24`, aprobado por el usuario el 2026-10-27): solo se rellenan en la **transición** `false → true` del `PATCH` o al crear el vínculo con `true`; un `PATCH` que reenvía `is_legal_guardian = true` sobre un vínculo que ya lo tiene **no** los cambia. Regla confirmada, **ya cumplida por `GuardianLinkService` de `1.14-a`** (`funcional.md §18`, D-14); la descripción del `PATCH` en `apps/api/openapi/paths/fam-unit.yaml` la documenta. Prueba de regresión: `CA-FAM-UNIT-102`.

### 2.5 Revocación (`1.14-a`)

| Verbo y ruta | Permiso | Respuesta | Errores propios |
|---|---|---|---|
| `POST /guardian-links/{public_id}/revocation` | `revocacion_tutor.crear` | 200 `GuardianStudentLink` con `revocation` y `{ "principal_cleared": bool }` (`RN-FAM-UNIT-38`) | 404, 409 `invalid_transition`, 429 |
| `DELETE /guardian-links/{public_id}/revocation` | `revocacion_tutor.eliminar` | 200 `GuardianStudentLink` | 404, 409 `invalid_transition`, 429 (`RN-FAM-UNIT-66`) |

`POST` cuerpo: `{ "reason": "resolucion_judicial" }`. Sin campo de texto libre a propósito (`RN-FAM-UNIT-36`): el detalle va a §2.6. Para leer vínculos revocados con su `revocation` hace falta además `revocacion_tutor.leer` (filtro `status=revocado` de `GET /guardian-links`).

**Límite** (`RN-FAM-UNIT-66`, issue #470): `POST` y `DELETE …/revocation` tienen un cubo propio cada uno, por centro **y** usuario, de 30 intentos por hora (`config/fam_unit.php`, `revocation.rate_limit`); cuenta todos los intentos, también los que acaban en `404` o `409`. Superado: `429 urn:pge:error:too-many-requests` con `Retry-After` y el texto `fam_unit.errors.revocation_rate_limited` en el idioma de la petición. Las lecturas y las demás escrituras no tienen límite.

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
| `POST /image-consent-decisions` | `consentimiento_imagen.actualizar` | sí | 201 `{ "decision": ImageConsentDecision, "state": ImageConsent }` (estado calculado tras la escritura); **200** con lo mismo si la decisión es idéntica a la vigente (`RN-FAM-UNIT-77`, sin fila ni auditoría) | 403 (un tutor con `unidad_familiar` no actúa por otro), 404 (alumno no alcanzado), 409 `text_version_outdated` (`RN-FAM-UNIT-69`), 422 `not_legal_guardian`, `guardian_required`, `guardian_not_linked`, `text_not_published`, `text_version_invalid`, `validation.channel_invalid`, `validation.student_not_found` (el centro, alumno inexistente), 429 (`RN-FAM-UNIT-76`) |
| `POST /image-consents/exports` | `consentimiento_imagen.exportar` | — | 202 recurso de exportación de `Core` (`GET /data-exports/{id}`) | 422 `q_not_allowed`, filtros inválidos, 429 (`RN-FAM-UNIT-76`) |
| `GET /image-consent-texts` | `consentimiento_imagen.leer` | **no filtra** (ver abajo) | 200 colección **sin paginar** de `ImageConsentText` (§1.9), una entrada por nivel, en orden `web`, `redes_sociales` | 422 (`level` inválido) |

**`GET /image-consent-texts`** (`RN-FAM-UNIT-75`, `OPEN-FAM-UNIT-23` aprobada el 2026-10-27). Lectura del texto **publicado** del consentimiento de cada nivel, en el idioma de la petición, para que un tutor sepa qué está autorizando antes de `POST /image-consent-decisions` por el canal `portal`. Filtro opcional `level` (`web` | `redes_sociales`). Cadena: `RequireAuthenticatedUser` → `module-enabled:fam_unit` → `permission:consentimiento_imagen.leer`. Admite el permiso con ámbito `todos` o `unidad_familiar` y **no acota la respuesta por ámbito** [DERIVADA; aceptada por el usuario el 2026-10-27: también la lee un tutor sin vínculos activos]: el texto es del centro, igual para todos, sin datos de ningún alumno ni tutor. Nunca devuelve `borrador` ni `retirada`; para eso está `GET /terms-versions` con `texto_legal.leer`. Respuesta en `{ "data": [...] }` sin `meta` de paginación (dos elementos como máximo, `ADR-038 §4`). **OpenAPI previsto**: ruta en `apps/api/openapi/paths/fam-unit.yaml`, esquema `ImageConsentText` con los campos anulables de §1.9 y `level` como enumerado extensible (`ADR-038 §7.3`), respuestas `200`, `401`, `403` (`forbidden` y `module-disabled`) y `422`.

`GET /image-consents` y la exportación recorren los alumnos **vivos** de las unidades (`family_unit_students`) y calculan su estado: `state=pendiente` incluye a los alumnos sin ninguna decisión (`CA-FAM-UNIT-095`). `GET /image-consent-decisions` con ámbito `unidad_familiar` omite las decisiones de tutores cuyo vínculo con ese alumno está revocado (`RN-FAM-UNIT-71`).

`POST /image-consent-decisions` cuerpo:

```json
{ "student_person": "01JB…30", "level": "redes_sociales", "decision": "no_autorizo", "on_behalf_of": null, "channel": "portal", "consent_text_version": null }
```

- Si el sujeto **es** tutor del alumno (vínculo **activo** propio con representación legal), `on_behalf_of` se omite o es su propio tutor, y `channel = portal` (opcional; si viene, debe corresponder al modo, `422 validation.channel_invalid`). **El cliente envía en `consent_text_version` la versión que el tutor vio** (la de `GET /image-consent-texts`; decisión del usuario del 2026-10-27, `RN-FAM-UNIT-69`): con `autorizo` es obligatoria (`422 validation.required`); si ya no es la `publicada`, `409 text_version_outdated` y no se escribe nada; de otro nivel, borrador o inexistente, `422 text_version_invalid`. Con `no_autorizo` es opcional y no se exige vigente. `shown_locale` = idioma **realmente mostrado** del texto (el de la petición si hay traducción; si no, el idioma por defecto del centro por la caída de `ADR-062`; el mismo que `locale` de `GET /image-consent-texts`, `RN-FAM-UNIT-43`, `OPEN-FAM-UNIT-25`).
- Si el sujeto es personal del centro (ámbito `todos`), `on_behalf_of` es obligatorio (`guardian_required`) y debe ser uno de **quienes deciden** sobre el alumno: tutor con vínculo **vivo**, `activo` **o `revocado`**, y representación legal (`RN-FAM-UNIT-41`, `ADR-066 §5.4`); `channel = presencial`. Sin vínculo vivo ⇒ `guardian_not_linked`; sin representación legal ⇒ `not_legal_guardian`. **Un tutor revocado solo se admite si el sujeto tiene además `revocacion_tutor.leer`**; sin él, se responde exactamente igual que con un tutor no vinculado (`422 guardian_not_linked`, mismo cuerpo, `RN-FAM-UNIT-40`).
- `consent_text_version` con `channel = presencial`: **opcional**, una versión `publicada` o `retirada` del tipo del nivel; nunca `borrador` ni otro tipo (`422 text_version_invalid`). Sin él, la `publicada` (`RN-FAM-UNIT-69`).
- `decision = autorizo` sin versión `publicada` del nivel ⇒ `422 text_not_published` y no se escribe nada; `no_autorizo` se acepta igual, con `consent_text_version = null` si no hay texto (`RN-FAM-UNIT-68`).
- Las decisiones no se editan ni borran: no hay `PATCH` ni `DELETE` (`RN-FAM-UNIT-42`).

**Idempotencia** (`RN-FAM-UNIT-77`, decisión del usuario del 2026-10-27): no se exige clave, pero una decisión **idéntica a la vigente que cuenta** del mismo tutor, alumno y nivel (misma decisión y misma versión del texto) responde `200` con la decisión existente y el estado actual, sin insertar fila ni auditar; cualquier otra es una fila nueva, `201`. **Límite** (`RN-FAM-UNIT-76`): por centro y usuario, 60 por hora por defecto (`config/fam_unit.php`, `consent.rate_limit.decision`), contando todos los intentos; superado, `429` con `Retry-After` y `fam_unit.errors.consent_rate_limited`. El informe (`POST /image-consents/exports`) tiene su propio cubo (10 por hora).

### 2.8 Textos legales (`/terms-versions`: **`1.14-b`** para los tipos de imagen) y condiciones de uso (`1.14-c`)

Las rutas `/terms-versions` se conservan (`OPEN-066-03`); el recurso de permisos pasa de `condiciones_uso` a **`texto_legal`**. En **`1.14-b`** sirven los tipos `consentimiento_imagen_web` y `consentimiento_imagen_redes`; `kind = condiciones_uso` en `POST` ⇒ `422 fam_unit.terms.kind_not_available` hasta `1.14-c` (`RN-FAM-UNIT-73`). `GET /terms-acceptances`, `/me/terms*` y el muro son de `1.14-c`.

| Verbo y ruta | Permiso | Entrega | Respuesta | Errores propios |
|---|---|---|---|---|
| `GET /terms-versions` | `texto_legal.leer` | `1.14-b` | 200 colección; filtro `kind` | 422 |
| `POST /terms-versions` | `texto_legal.crear` | `1.14-b` | 201 (`borrador`) | 422 traducciones (`ADR-062`), `validation.required` (`kind` ausente), `terms.kind_invalid` (`kind` fuera del vocabulario), `terms.kind_not_available` |
| `GET /terms-versions/{public_id}` | `texto_legal.leer` | `1.14-b` | 200 | 404 |
| `PATCH /terms-versions/{public_id}` | `texto_legal.actualizar` | `1.14-b` | 200 | 404, 409 `not_draft`, 422 (`kind_not_editable`) |
| `DELETE /terms-versions/{public_id}` | `texto_legal.eliminar` | `1.14-b` | 204 (solo `borrador`) | 404, 409 `not_draft` |
| `POST /terms-versions/{public_id}/publish` | `texto_legal.publicar` | `1.14-b` | 200; retira la `publicada` anterior **del mismo tipo** | 404, 409 `not_draft`, 422 `default_locale_missing`, 422 `terms.requires_reacceptance_not_allowed` (`requires_reacceptance = true` en un tipo de imagen; `CHECK` de `datos.md §1.8`) |
| `GET /terms-acceptances` | `aceptacion_condiciones.leer` | `1.14-c` | 200 colección | 422 |
| `GET /me/terms` | identidad | `1.14-c` | 200 `{ "required": bool, "version": TermsVersion\|null }` | — |
| `POST /me/terms-acceptances` | identidad | `1.14-c` | 201 `TermsAcceptance` (200 si ya existía, `RN-FAM-UNIT-64`) | 409 `version_not_current`, 422 |

`POST /terms-versions` cuerpo: `{ "kind": "consentimiento_imagen_web", "title_translations": {…}, "body_translations": {…} }`. `POST /terms-versions/{id}/publish` cuerpo: `{ "requires_reacceptance": true }` (solo `condiciones_uso`; en un tipo de imagen debe omitirse o ser `false`). Publicar un texto de imagen **no altera ningún estado** de consentimiento (`RN-FAM-UNIT-70`). `POST /me/terms-acceptances` cuerpo: `{ "terms_version": "<public_id>" }`; el servidor registra el idioma en que se sirvió (`shown_locale`) a partir del idioma de la petición.

**Muro** (`ADR-067`, ACEPTADA el 2026-10-28; resuelve `OPEN-FAM-UNIT-12`): mientras el usuario esté obligado y sin aceptación exigible, toda ruta de `api/v1` no exenta responde `403` con `type = urn:pge:error:terms-acceptance-required` (ampliación L5 del catálogo de `ADR-038 §6.2`), con `title` y `detail` traducidos por `type` en los cuatro idiomas y **sin miembros de extensión**: no lleva `params.version` (`OPEN-067-05`); la pantalla del muro obtiene la versión pendiente de `GET /me/terms`. Lo aplica `RequireSessionWalls` (alias `require-session-walls`, posición 10 del grupo `v1`) con `TermsAcceptanceWall` como segundo muro, detrás del de MFA (`ADR-067 §5.3`, `§5.7`).

Rutas exentas del muro de condiciones (lista cerrada **L3**, `ADR-067 §6`, 6 nombres de ruta):

| Nombre de ruta | Verbo y ruta |
|---|---|
| `core.me.show` | `GET /me` |
| `fam-unit.me-terms.show` | `GET /me/terms` |
| `fam-unit.me-terms-acceptances.store` | `POST /me/terms-acceptances` |
| `auth.session.destroy` | Cierre de sesión |
| `auth.csrf-cookie` | Renovación del *token* CSRF (sin ella la SPA no puede enviar el `POST` de aceptación) |
| `core.tenant.branding` | `GET /tenant/branding` |

`PATCH /me` (`core.me.update`) **no** está exento (`OPEN-067-04`). Por la precedencia con exención heredada (`ADR-067 §5.4`, `OPEN-067-01`), las rutas exentas del muro de MFA tampoco evalúan este muro, así que la lista **efectiva** suma 3: `GET /auth/mfa`, `POST /auth/mfa-enrollments` y `POST /auth/mfa-factors`. Con el MFA también pendiente manda el muro de MFA: `POST /me/terms-acceptances` responde `403 urn:pge:error:mfa-enrollment-required` hasta cumplirlo. Con el módulo no contratado el muro no se evalúa (`ADR-067 §5.9`). Toda ruta exenta está además en `AR-07a` (`CA-067-02`).

---

## 3. Paginación, filtrado y ordenación (`ADR-038 §4`, `§5`)

| Listado | Paginación | Por qué | Filtros | Orden (lista blanca) |
|---------|-----------|---------|---------|----------------------|
| `GET /family-units` | Página | Entidades de alta administrativa: cientos a pocos miles por centro | `q` (prefijo de código), `has_principal`, `include_deleted` (solo con `familia.eliminar`) | `code` (defecto), `created_at` |
| `GET /guardians` | Página | Ídem | `family_unit`, `user` | `created_at` |
| `GET /family-units/{id}/students` | Sin paginar | Decenas como mucho por unidad [DERIVADA] | — | `display_name` |
| `GET /guardian-links` | Página | Ídem | `family_unit`, `guardian`, `student_person`, `status` (`revocado` exige `revocacion_tutor.leer`, si no `403`; sin ese permiso el listado tampoco incluye revocados, `RN-FAM-UNIT-40`), `is_emergency_contact`, `is_legal_guardian` | `created_at` |
| `GET /image-consents` | Página | Una fila por alumno y nivel | `family_unit`, `student_person`, `level`, `state` | `last_decision_at`, `state` |
| `GET /image-consent-decisions` | Cursor sobre `(occurred_at DESC, id DESC)` | Histórico de solo anexar que crece sin límite natural | `student_person` (obligatorio), `level` | Fijo |
| `GET /terms-versions` | Página | Decenas | `kind`, `status` | `version_number` (defecto desc; desempate por `id`) |
| `GET /image-consent-texts` | Sin paginar | Una entrada por nivel: dos como máximo | `level` | Fijo (`web`, `redes_sociales`) |
| `GET /terms-acceptances` | Cursor sobre `(accepted_at DESC, id DESC)` | Solo anexar, crece con cada tutor y versión | `terms_version`, `user` | Fijo |

Filtrar por `student_person` o `family_unit` que el sujeto no alcanza no es error: devuelve vacío (el ámbito se aplica antes que el filtro). Un `public_id` mal formado ⇒ `422`.

---

## 4. Exportación: informe de consentimientos (`ADR-054`, `ADR-055`)

- `POST /image-consents/exports` (`consentimiento_imagen.exportar`). En cola (`INV-012`) sobre la primitiva `data_exports` de `Core` con un `kind` nuevo `image_consents` (ampliación de su catálogo, `OPEN-FAM-UNIT-11`), enlace caducable, solicitud auditada como `exported` con los filtros aplicados. Respuesta `202 { public_id, status }`; el estado y la descarga, en `GET /data-exports/{public_id}`. El trabajo está en `FamUnit` (cola `default`) y recorre la solicitud por `Core\Domain\ExportJobLifecycle`; sin tope de filas (`funcional.md §18`, D-18).
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

Ninguno en `1.14` (`ADR-048`, `funcional.md §7.6`). El consentimiento de imagen **no emite eventos**, tampoco para `REQ-PRIV` (`ADR-066 §5.7`). Las interfaces públicas de `funcional.md §7` sustituyen a los eventos para todo lo que necesita efecto inmediato.

## 6. Webhooks disponibles

Ninguno.

## 7. Códigos de error propios

Todos con prefijo `fam_unit.` y traducidos en los cuatro idiomas: `family_unit.code_taken`, `family_unit.code_invalid`, `family_unit.principal_not_member`, `family_unit.has_members`, `guardian.already_exists`, `guardian.user_not_eligible`, `student.already_in_unit`, `link.already_exists`, `link.self_link`, `link.emergency_contact_taken`, `link.invalid_transition`, `link.not_editable`, `link.emergency_contact_revoked`, `link.revoked_not_deletable` (`409`, `RN-FAM-UNIT-39`: en `DELETE /guardian-links/{id}` y `DELETE /guardians/{id}`), `guardian.not_editable`, `custody.link_not_revoked` (condicionado), `image_consent.not_legal_guardian`, `image_consent.guardian_required`, `image_consent.guardian_not_linked`, `image_consent.text_not_published` (`RN-FAM-UNIT-68`), `image_consent.text_version_invalid` (`RN-FAM-UNIT-69`), `terms.kind_not_available` (`RN-FAM-UNIT-73`) [DERIVADA, aceptada el 2026-10-27], `terms.kind_not_editable` [DERIVADA, aceptada el 2026-10-27], `terms.kind_invalid` (`kind` fuera del vocabulario en `POST /terms-versions`; si falta, `validation.required`) [DERIVADA, 2026-10-27], `terms.requires_reacceptance_not_allowed` (`requires_reacceptance = true` al publicar un tipo de imagen) [DERIVADA, 2026-10-27], `terms.not_draft`, `terms.default_locale_missing`, `terms.version_not_current`, `export.q_not_allowed`, `errors.revocation_rate_limited` (`429`), `errors.consent_rate_limited` (`429`, `RN-FAM-UNIT-76`), `image_consent.text_version_outdated` (`409`, `RN-FAM-UNIT-69`), y, de `1.14-b` (`funcional.md §18`, D-19), `validation.level_invalid`, `validation.decision_invalid`, `validation.channel_invalid`, `validation.state_invalid` y `validation.cursor_invalid`, `validation.user_not_found`, `validation.student_not_found`, `validation.guardian_not_found`, `validation.family_unit_not_found`, y, de `1.14-a` (`funcional.md §15`, D-7), `validation.principal_guardian_not_found`, `validation.relationship_invalid`, `validation.access_level_invalid`, `validation.restricted_category_invalid`, `validation.reason_invalid`, `validation.required`, `validation.boolean_invalid`, `validation.reference_invalid` y `validation.filter_forbidden` (este último con `403`: `include_deleted` sin `familia.eliminar` o `status=revocado` sin `revocacion_tutor.leer`). Error transversal nuevo: `urn:pge:error:terms-acceptance-required` (`403`, ampliación L5 del catálogo de `ADR-038 §6.2` por `ADR-067 §5.5`; sin miembros de extensión).

Nota: el issue #60 (`ValidationErrorFormatter` antepone `core.` al `code` de cualquier módulo) sigue abierto; estos códigos heredarán su resolución.
