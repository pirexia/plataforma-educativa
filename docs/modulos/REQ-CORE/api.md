# REQ-CORE · API

> Alcance: paso **1.1**. La frontera de qué entra y qué no está en `funcional.md` §1. Prefijo `/api/v1`, resolución de tenant por host antes de cualquier consulta (`ADR-033 §2`).
>
> **Todo identificador de las rutas y de los cuerpos es el `public_id` ULID** (`ADR-029`). La clave interna `bigint` no sale de la capa de aplicación; exponerla es un fallo de revisión.

---

## 1. Reglas generales

| Aspecto | Decisión |
|---------|----------|
| Autenticación | Cookie de sesión `httpOnly`/`Secure`/`SameSite` con CSRF (`ADR-025`). El flujo de login lo entrega 1.2; en 1.1 los tests autentican con `actingAs()`. |
| Autorización | Cada endpoint declara su permiso `recurso.accion`. Denegar por defecto (`INV-002`, `RPERM-011`). Ver `permisos.md`. |
| Aislamiento | RLS más *scope* global. Un recurso de otro tenant responde `404`, nunca `403` (no se confirma su existencia). |
| Formato de error | `application/problem+json` (RFC 9457). Ver §8.3. |
| Idempotencia | Cabecera `Idempotency-Key` obligatoria donde se indica (`INV-011`). |
| Auditoría | Toda escritura genera registro automático vía *observer* (`INV-003`, `ADR-035`). No hay que hacer nada por endpoint. |
| Módulo desactivado | No aplica a `REQ-CORE` (núcleo, siempre activo). El *middleware* `EnsureModuleEnabled` que 1.1 entrega sirve a los demás módulos (`RMOD-009`). |
| OpenAPI | Todos los endpoints documentados en `apps/api/openapi.yaml` antes del merge (`CLAUDE.md §10`). |

Cabeceras de respuesta comunes: `X-Request-Id` (`INV-013`, generado siempre por el servidor; un `X-Request-Id` entrante del cliente se ignora), `Content-Language` con el idioma resuelto (`ADR-038 §11`).

Envoltura (`ADR-038 §3.1`): el recurso individual va **desnudo**, sin envolver; la colección va como `{"data": [...], "meta": {...}}`; una escritura sin cuerpo devuelve `204`.

---

## 2. Configuración del centro (`REQ-CORE-002`)

### `GET /api/v1/tenant`

Identidad del centro, solo lectura. El ciclo de vida es 1.6 (`funcional.md` §1.1).

- **Permiso**: `configuracion` · `leer` · `todos`
- **Respuesta 200**

```json
{
  "public_id": "01J8...",
  "slug": "miramadrid",
  "name": "Colegio Ficticio Miramadrid",
  "status": "activo"
}
```

- **Errores**: 401, 403, 404 (host sin tenant)

---

### `GET /api/v1/tenant/settings`

- **Permiso**: `configuracion` · `leer` · `todos`
- **Respuesta 200**

```json
{
  "public_id": "01J8...",
  "regional": {
    "default_locale": "es-ES",
    "active_locales": ["es-ES", "en"],
    "timezone": "Europe/Madrid",
    "currency": "EUR",
    "autonomous_community": "MD"
  },
  "fiscal": {
    "legal_name": "Colegio Ficticio Miramadrid S.L.",
    "tax_id": "B00000000",
    "address": "Calle Inventada 1",
    "postal_code": "28000",
    "city": "Madrid",
    "province": "Madrid",
    "country_code": "ES"
  },
  "branding": {
    "color_primary": "#1D4ED8",
    "color_secondary": "#64748B",
    "logo_url": "https://.../signed?...",
    "favicon_url": null,
    "login_background_url": null
  },
  "updated_at": "2026-08-19T09:00:00Z"
}
```

Las tres URLs de branding son **firmadas y de caducidad corta**; se regeneran en cada respuesta y no se cachean en cliente más allá de su vencimiento.

- **Errores**: 401, 403, 404

---

### `PATCH /api/v1/tenant/settings`

Actualización parcial. Se aceptan los grupos `regional`, `fiscal` y `branding` (sin activos, que van por §2.3), y dentro de cada uno solo las claves enviadas.

- **Permiso**: `configuracion` · `actualizar` · `todos`
- **Cuerpo** (ejemplo)

```json
{
  "regional": { "active_locales": ["es-ES", "en", "fr"], "default_locale": "es-ES" },
  "branding": { "color_primary": "#1D4ED8", "color_secondary": "#475569" }
}
```

- **Validación** (`INV-010`): `default_locale ∈ active_locales`; `active_locales ⊆ {es-ES,en,de,fr}` y no vacío (`ADR-021`); `timezone` identificador IANA; `currency` ISO 4217; `autonomous_community` del catálogo; colores `^#[0-9A-Fa-f]{6}$`; contraste de la paleta ≥ WCAG 2.2 AA (`RUX-BRAND-006`).
- **Respuesta 200**: el recurso completo, igual que `GET`.
- **Errores**: 401, 403, 422 (con `errors` por campo; el fallo de contraste incluye `ratio` y `required_ratio`)
- **Idempotencia**: no (`PATCH` con cuerpo parcial es naturalmente repetible)

---

### `PUT /api/v1/tenant/settings/assets/{kind}`

`kind ∈ {logo, favicon, login-background}`. Subida `multipart/form-data`, campo `file`.

- **Permiso**: `configuracion` · `actualizar` · `todos`
- **Validación** (`RSEC-OWASP-012`, `RN-CORE-18`): tipo real por contenido; `logo` acepta `image/svg+xml`, `image/png`, `image/webp` (≤ 1 MB); `favicon` acepta `image/png`, `image/x-icon`, `image/svg+xml` (≤ 256 KB); `login-background` acepta `image/jpeg`, `image/png`, `image/webp` (≤ 3 MB, SVG **no** admitido). SVG saneado antes de almacenar.
- **Respuesta 200**: `{ "kind": "logo", "url": "https://.../signed?..." }`
- **Errores**: 401, 403, 413 (excede tamaño), 415 (tipo no admitido), 422 (tipo real distinto del declarado o SVG irreparable)

### `DELETE /api/v1/tenant/settings/assets/{kind}`

- **Permiso**: `configuracion` · `actualizar` · `todos`
- **Respuesta 204**
- **Errores**: 401, 403, 404 (no había activo de ese tipo)

---

### `GET /api/v1/tenant/branding`

**Único endpoint sin autenticación del módulo.** Existe para que la pantalla de login de 1.2 pueda pintarse antes de que haya sesión (`funcional.md` §4.8).

- **Permiso**: ninguno. Tenant resuelto por host.
- **Respuesta 200**

```json
{
  "name": "Colegio Ficticio Miramadrid",
  "color_primary": "#1D4ED8",
  "color_secondary": "#475569",
  "logo_url": "https://.../signed?...",
  "favicon_url": null,
  "login_background_url": null,
  "default_locale": "es-ES",
  "active_locales": ["es-ES", "en"]
}
```

- **Regla no negociable**: la respuesta no contiene ningún campo más. Añadir uno es publicar información en Internet y exige justificación en la revisión de seguridad.
- **Errores**: 404 (host sin tenant), 429 (limitado por IP: es anónimo y enumerable por subdominio)

---

## 3. Usuarios (`REQ-CORE-003`)

### `GET /api/v1/users`

- **Permiso**: `usuario` · `leer` · `todos`
- **Parámetros de consulta**

| Parámetro | Tipo | Nota |
|-----------|------|------|
| `q` | string | Búsqueda sobre nombre, apellidos y correo de acceso |
| `status` | `pendiente\|activo\|inactivo` | Varios valores separados por coma: `status=activo,inactivo` (`ADR-038 §5.2`) |
| `role` | ULID de rol | Varios valores separados por coma |
| `locale` | `es-ES\|en\|de\|fr` | |
| `include_deleted` | bool | Requiere además `usuario.eliminar`; por defecto `false` |
| `sort` | `family_name_1\|-family_name_1\|created_at\|-created_at\|email` | Por defecto `family_name_1` |
| `page`, `per_page` | int | Por defecto 25, máximo 100 |

- **Respuesta 200**

```json
{
  "data": [
    {
      "public_id": "01J8...",
      "email": "ana.perez@example.com",
      "status": "activo",
      "person": {
        "public_id": "01J8...",
        "given_name": "Ana",
        "family_name_1": "Pérez",
        "family_name_2": "Gómez",
        "contact_email": "ana.perez@example.com",
        "contact_phone": "+34600000000",
        "document_type": "DNI",
        "document_number": "00000000T",
        "birth_date": "1985-04-12",
        "locale": "es-ES"
      },
      "roles": [{ "public_id": "01J8...", "code": "docente", "name": "Docente" }],
      "email_verified_at": null,
      "created_at": "2026-08-19T09:00:00Z",
      "updated_at": "2026-08-19T09:00:00Z",
      "deleted_at": null
    }
  ],
  "meta": { "current_page": 1, "per_page": 25, "total": 137, "last_page": 6 }
}
```

`roles[].name` se resuelve en servidor: literal si el rol es personalizado, traducción de `name_key` si es del sistema (`ADR-034 §2`, `INV-009`).

- **Errores**: 401, 403, 422 (parámetro inválido)

---

### `POST /api/v1/users`

- **Permiso**: `usuario` · `crear` · `todos`. Si `role_ids` viene informado, además `asignacion_rol` · `crear` · `todos` y la comprobación de `RPERM-013`.
- **Cuerpo**

```json
{
  "email": "ana.perez@example.com",
  "person": {
    "given_name": "Ana",
    "family_name_1": "Pérez",
    "family_name_2": "Gómez",
    "birth_date": "1985-04-12",
    "document_type": "DNI",
    "document_number": "00000000T",
    "contact_email": "ana.perez@example.com",
    "contact_phone": "+34600000000",
    "locale": "es-ES"
  },
  "role_ids": ["01J8..."],
  "send_invitation": true
}
```

Obligatorios: `email`, `person.given_name`, `person.family_name_1`. El resto es opcional. `person.locale` toma por defecto `tenant_settings.default_locale`.

- **Respuesta 201**: el recurso de usuario completo, más `invitation` si se emitió.
- **Errores**
  - `422` — validación: correo duplicado entre vivos (`RN-CORE-02`), documento duplicado (`RN-CORE-03`), idioma fuera de los activos (`RN-CORE-13`), formato de documento inválido, rol inexistente.
  - `403` — `RPERM-013`: se intenta asignar un rol con permisos que el solicitante no posee (`RN-CORE-08`).
- **Idempotencia**: no. La unicidad de correo y documento ya impide el duplicado.

---

### `GET /api/v1/users/{public_id}`

- **Permiso**: `usuario` · `leer` · `todos`
- **Respuesta 200**: recurso de usuario.
- **Errores**: 401, 403, 404 (inexistente, eliminado sin `include_deleted`, o de otro tenant — `CA-CORE-073`)

---

### `PATCH /api/v1/users/{public_id}`

- **Permiso**: `usuario` · `actualizar` · `todos`
- **Cuerpo**: cualquier subconjunto de `email` y de los campos de `person`. **No** acepta `status`, `roles` ni `deleted_at`.
- **Efecto colateral**: cambiar `email` revoca las invitaciones vivas (`RN-CORE-11`) y emite `UserEmailChanged`.
- **Respuesta 200**: recurso actualizado.
- **Errores**: 401, 403, 404, 422 (mismas validaciones que el alta)

---

### `DELETE /api/v1/users/{public_id}`

Baja **lógica** (`INV-004`): `deleted_at` informado y `status = 'inactivo'`.

- **Permiso**: `usuario` · `eliminar` · `todos`
- **Respuesta 204**
- **Errores**
  - `409` — es el propio solicitante (`RN-CORE-06`), o es el último Administrador de Centro vivo (`RN-CORE-07`).
  - 401, 403, 404

---

### `POST /api/v1/users/{public_id}/restore`

- **Permiso**: `usuario` · `eliminar` · `todos`
- **Respuesta 200**: recurso restaurado con `status = 'inactivo'` (la reactivación es un cambio de estado aparte, no automática).
- **Errores**: `409` si su correo o documento los ocupa ya un registro vivo; 401, 403, 404

---

### `POST /api/v1/users/{public_id}/status`

Alta y baja administrativa entre `activo` e `inactivo`. Separado de `PATCH` porque es una transición de estado con reglas propias, no una edición de campo.

- **Permiso**: `usuario` · `actualizar` · `todos`
- **Cuerpo**: `{ "status": "activo" }`
- **Errores**
  - `409` — transición no permitida (`pendiente` solo sale por canje de invitación, `RN-CORE-04`), o dejaría al centro sin Administrador de Centro activo (`RN-CORE-07`), o es el propio solicitante (`RN-CORE-06`).

---

### `GET /api/v1/me` · `PATCH /api/v1/me`

Autoservicio del perfil propio. **No requiere permiso**: se autoriza por identidad del sujeto, no por ámbito `propios` (`funcional.md` §1.3 — con el resolutor provisional, el ámbito no se evalúa y `propios` se comportaría como `todos`).

- **`GET` respuesta 200**: recurso de usuario del solicitante, con sus roles y sus permisos efectivos resueltos (útil para que la interfaz decida qué mostrar).
- **`PATCH` cuerpo aceptado**: `person.locale`, `person.contact_email`, `person.contact_phone`. Cualquier otro campo se **ignora** silenciosamente y no se modifica (`CA-CORE-018`).
- **Errores**: 401, 422

---

## 4. Invitaciones (`REQ-CORE-003`)

### `GET /api/v1/invitations`

- **Permiso**: `invitacion` · `leer` · `todos`
- **Parámetros**: `status` (`vigente|caducada|revocada|aceptada`), `page`, `per_page`
- **Respuesta 200**

```json
{
  "data": [
    {
      "public_id": "01J8...",
      "user": { "public_id": "01J8...", "email": "ana.perez@example.com" },
      "status": "vigente",
      "expires_at": "2026-08-26T09:00:00Z",
      "created_at": "2026-08-19T09:00:00Z",
      "accepted_at": null,
      "revoked_at": null
    }
  ],
  "meta": { "current_page": 1, "per_page": 25, "total": 4, "last_page": 1 }
}
```

`status` es **derivado**, no una columna: `aceptada` si `accepted_at`, `revocada` si `revoked_at`, `caducada` si `expires_at < now()`, `vigente` en otro caso. El token nunca aparece.

---

### `POST /api/v1/users/{public_id}/invitations`

Emite o reemite. Revoca la invitación viva anterior (`RN-CORE-09`).

- **Permiso**: `invitacion` · `crear` · `todos`
- **Cuerpo**: vacío
- **Respuesta 201**: el recurso de invitación (**sin token**)
- **Errores**
  - `409` — el usuario no está en `pendiente` (`RN-CORE-12`)
  - `429` — límite de reenvíos por usuario y hora (evita usar la plataforma como remitente de correo)
  - 401, 403, 404

---

### `DELETE /api/v1/invitations/{public_id}`

Revoca. La fila se conserva con `revoked_at` (no se borra: es traza).

- **Permiso**: `invitacion` · `eliminar` · `todos`
- **Respuesta 204**
- **Errores**: `409` si ya está aceptada; 401, 403, 404

> **Fuera de 1.1**: el canje (`POST /invitations/{token}/accept`, que fija la contraseña y activa al usuario) pertenece a `REQ-AUTH-001`, paso 1.2. El contrato del token está fijado en `funcional.md` §4.3 para que 1.2 no lo reinvente.

---

## 5. Roles y permisos (parte de `REQ-CORE-004` que entra en 1.1)

**Solo lectura**, salvo la asignación de roles a usuarios. La escritura de roles y concesiones es 1.5.

### `GET /api/v1/roles`

- **Permiso**: `rol` · `leer` · `todos`
- **Respuesta 200**

```json
{
  "data": [
    {
      "public_id": "01J8...",
      "code": "administrador_centro",
      "name": "Administrador de Centro",
      "is_system": true,
      "mfa_required": true,
      "special_data_access": false,
      "users_count": 2
    }
  ],
  "meta": { "current_page": 1, "per_page": 25, "total": 17, "last_page": 1 }
}
```

### `GET /api/v1/roles/{public_id}`

Añade las concesiones del rol:

```json
{
  "permissions": [
    { "code": "usuario.leer", "resource": "usuario", "action": "leer", "effect": "allow", "scope": "todos" }
  ]
}
```

- **Permiso**: `rol` · `leer` · `todos`

### `GET /api/v1/permissions`

Catálogo de la plataforma (tabla de referencia, sin `tenant_id`). Lo necesitará la interfaz de 1.5; en 1.1 es informativo.

- **Permiso**: `permiso` · `leer` · `todos`
- **Parámetros**: `module_code`, `resource`, `include_retired` (por defecto `false`)
- **Respuesta 200**: lista de `{ code, resource, action, module_code, is_special_category, retired_at }`

### `GET /api/v1/users/{public_id}/roles`

- **Permiso**: `asignacion_rol` · `leer` · `todos`

### `PUT /api/v1/users/{public_id}/roles`

Reemplaza el conjunto completo de roles del usuario. Se usa `PUT` y no `POST`/`DELETE` por rol porque la operación es «este usuario tiene exactamente estos roles», que es idempotente y evita estados intermedios donde el usuario se queda sin ninguno.

- **Permiso**: `asignacion_rol` · `crear` · `todos` (y `asignacion_rol` · `eliminar` · `todos` si el conjunto retira alguno)
- **Cuerpo**: `{ "role_ids": ["01J8...", "01J8..."] }`
- **Respuesta 200**: los roles resultantes
- **Errores**
  - `403` — algún rol concede un permiso que el solicitante no posee (`RPERM-013`, `RN-CORE-08`)
  - `409` — retiraría el rol `administrador_centro` al último que lo tiene (`RN-CORE-07`), o el solicitante se está modificando a sí mismo (`RN-CORE-06`)
  - `422` — algún `role_id` no existe en el tenant
  - `404` — algún `role_id` pertenece a otro tenant (indistinguible de inexistente, por diseño)
- **Emite**: `UserRolesChanged`

---

## 6. Módulos (`RMOD-008`)

### `GET /api/v1/modules`

- **Permiso**: `modulo` · `leer` · `todos`
- **Respuesta 200**

```json
{
  "data": [
    {
      "public_id": "01J8...",
      "module_code": "acad",
      "name": "Estructura académica",
      "phase": "1",
      "enabled": true,
      "enabled_at": "2026-08-19T09:00:00Z",
      "disabled_at": null,
      "settings": {}
    }
  ]
}
```

`name` sale de `modules.name_key` traducido (`INV-009`). Un módulo del catálogo sin fila de suscripción aparece con `enabled: false` y `public_id: null` (fallo en cerrado, `ADR-034 §5`).

### `PATCH /api/v1/module-subscriptions/{public_id}`

Solo `settings`. **`enabled` no es modificable por esta API en 1.1** (`funcional.md` §2, contradicción `OPEN-CORE-03`).

- **Permiso**: `modulo` · `actualizar` · `todos`
- **Cuerpo**: `{ "settings": { "...": "..." } }`
- **Errores**: `422` si el cuerpo incluye `enabled`, con mensaje que remite a que la operación no está disponible; 401, 403, 404

---

## 7. Importación de usuarios (`REQ-CORE-003`)

Esquema de columnas **fijo**. Sin mapeo visual ni reversibilidad (`funcional.md` §1.10).

Cabecera obligatoria, en este orden, con separador `;` o `,` autodetectado y codificación UTF-8 (con o sin BOM):

```
email;given_name;family_name_1;family_name_2;document_type;document_number;birth_date;contact_email;contact_phone;locale;roles
```

`roles` admite varios códigos de rol separados por `|`. `birth_date` en ISO 8601 (`AAAA-MM-DD`). Columnas vacías se tratan como nulas salvo las obligatorias (`email`, `given_name`, `family_name_1`).

### `POST /api/v1/user-imports`

- **Permiso**: `usuario` · `importar` · `todos`
- **Cuerpo**: `multipart/form-data`, campo `file` (`text/csv`, ≤ 10 MB, ≤ 20 000 filas), campo opcional `send_invitations` (bool, por defecto `true`)
- **Respuesta 202**

```json
{ "public_id": "01J8...", "status": "subido", "created_at": "2026-08-19T09:00:00Z" }
```

- **Errores**: 401, 403, 413, 415, 422

### `GET /api/v1/user-imports` · `GET /api/v1/user-imports/{public_id}`

- **Permiso**: `usuario` · `importar` · `todos`
- **Respuesta 200**

```json
{
  "public_id": "01J8...",
  "original_filename": "personal-2026.csv",
  "status": "validado",
  "row_count": 5,
  "error_count": 2,
  "created_count": null,
  "error_summary": [
    { "line": 3, "column": "email", "code": "duplicado_en_fichero", "message": "..." },
    { "line": 5, "column": "document_number", "code": "formato_invalido", "message": "..." }
  ],
  "report_url": "https://.../signed?...",
  "validated_at": "2026-08-19T09:01:00Z",
  "executed_at": null
}
```

`error_summary` trae como mucho 50 entradas; el informe completo está en `report_url` (CSV, URL firmada de caducidad corta). Los `code` de error son claves de traducción (`INV-009`), no texto.

### `POST /api/v1/user-imports/{public_id}/execute`

- **Permiso**: `usuario` · `importar` · `todos`
- **Cabecera obligatoria**: `Idempotency-Key`, ULID generado por el cliente (`INV-011`, `ADR-038 §8`)
- **Respuesta 202**: el recurso con `status: "ejecutando"`
- **Repetición con la misma clave y el mismo cuerpo**: se devuelve la respuesta original (`202`, mismo cuerpo) con la cabecera `Idempotency-Replayed: true`. No es un error (`ADR-038 §8.2`).
- **Errores**
  - `400` — `Idempotency-Key` ausente o con formato distinto de ULID (`urn:pge:error:malformed`)
  - `409` — el estado no es `validado` (`fallido`, `ejecutando`, `completado`); o la misma clave llega con un cuerpo distinto; o la misma clave llega mientras la primera ejecución todavía está en curso
  - 401, 403, 404
- **Emite**: `UserImportCompleted` al terminar

### `DELETE /api/v1/user-imports/{public_id}`

Descarta un lote no ejecutado y borra su fichero fuente y su informe del bucket.

- **Permiso**: `usuario` · `importar` · `todos`
- **Respuesta 204**
- **Errores**: `409` si ya está ejecutado o ejecutándose

---

## 8. Auditoría (`REQ-CORE-005`)

### `GET /api/v1/audit-logs`

- **Permiso**: `auditoria` · `leer` · `todos`
- **Parámetros**

| Parámetro | Nota |
|-----------|------|
| `from`, `to` | Rango sobre `occurred_at`, ISO 8601 con zona. Máximo configurable de ventana |
| `actor_id` | ULID de usuario |
| `actor_type` | `user\|system\|console\|import\|platform` |
| `event` | `created\|updated\|deleted\|restored\|read\|exported`, repetible |
| `auditable_type` | Alias del *morph map* (`user`, `person`, `role`, …), repetible |
| `auditable_id` | `public_id` de la entidad, para el historial de un registro concreto |
| `module` | Código de módulo; se resuelve a los alias que ese módulo declara |
| `cursor`, `limit` | Paginación por cursor. `limit` por defecto 50, máximo 200. `cursor` es **opaco y cifrado** (`Crypt`, AES-256-GCM), nunca base64 legible: transporta la tupla de orden `(occurred_at, id)`, una huella de los filtros de la petición y el `tenant_id` del emisor (`ADR-038 §4.4`). Un cursor que no descifra, que llega con filtros distintos a los de su emisión, o de otro tenant, es `422` sin consulta a base de datos. |

- **Respuesta 200**

```json
{
  "data": [
    {
      "public_id": "01J8...",
      "occurred_at": "2026-08-19T09:00:00Z",
      "actor": { "public_id": "01J8...", "display_name": "Ana Pérez" },
      "actor_type": "user",
      "auditable_type": "user",
      "auditable_public_id": "01J8...",
      "event": "updated",
      "changes": {
        "status": { "from": "pendiente", "to": "activo" },
        "document_number": { "redacted": "identifier", "from_empty": false, "to_empty": false }
      },
      "ip_address": "203.0.113.10",
      "user_agent": "Mozilla/5.0 ...",
      "request_id": "01J8..."
    }
  ],
  "meta": { "next_cursor": "eyJ...", "has_more": true }
}
```

`changes` se devuelve **tal cual está almacenado**. `ADR-035` garantiza que ningún valor redactado llegó a escribirse; la API no redacta nada por su cuenta ni debe intentar «rellenar» lo redactado (`CA-CORE-052`).

`actor.display_name` se resuelve por FK en el momento de la consulta, nunca desde una copia desnormalizada (`ADR-034 §3`): si la persona se anonimiza, aquí aparece anonimizada.

**Paginación por cursor y no por página** porque el orden es `(occurred_at DESC, id DESC)` sobre una tabla *append-only* de alto crecimiento: el desplazamiento por `OFFSET` degrada linealmente y produce resultados inestables mientras entran filas nuevas. El índice `(tenant_id, occurred_at DESC, id DESC)` de `datos.md` (desempatado por `id` desde `ADR-038 §4.4`) es exactamente el que sirve esta consulta.

- **Errores**: 401, 403, 422 (rango excesivo, cursor inválido)

### `POST /api/v1/audit-logs/exports`

- **Permiso**: `auditoria` · `exportar` · `todos`
- **Cuerpo**: los mismos filtros de `GET /audit-logs` más `{ "format": "csv" }`
- **Respuesta 202**: `{ "public_id": "01J8...", "status": "pendiente" }`
- **Errores**: `422` si el rango supera el límite de filas configurado o si `format` es `pdf` (**diferido a 1.17**, `funcional.md` §4.6)
- **Efecto**: encola la generación (`INV-012`) y audita la solicitud con `event = 'exported'`

### `GET /api/v1/data-exports/{public_id}`

Estado y descarga de una exportación. Primitiva compartida (`funcional.md` §7).

- **Permiso**: el del recurso exportado — para una exportación de auditoría, `auditoria` · `exportar` · `todos`. Además, **solo el solicitante** puede descargarla.
- **Respuesta 200**

```json
{
  "public_id": "01J8...",
  "kind": "audit_logs",
  "status": "completada",
  "row_count": 12043,
  "download_url": "https://.../signed?...",
  "expires_at": "2026-08-26T09:00:00Z"
}
```

- **Errores**: 401, 403, 404, `409` si aún no está completada (`status` `pendiente`/`generando`) y se pide la descarga, `410` si ya venció

---

## 9. Convenciones transversales

Ratificadas en **`docs/adr/ADR-038-convenciones-api-rest.md`** (`OPEN-CORE-09`, cerrada). Aplican a los 53 módulos, no solo a `REQ-CORE`; este documento no las repite, solo señala dónde este módulo las usa de forma menos obvia:

- **Paginación**: por página en catálogos (usuarios, roles, invitaciones, módulos, importaciones), por cursor cifrado en `audit-logs` (§8, ADR §4).
- **Filtrado**: valores múltiples de `status`/`role` en `GET /users` van separados por coma, no repetidos (ADR §5.1 — la sintaxis repetida no funciona en PHP).
- **Error**: `application/problem+json`, `type` como URN `urn:pge:error:<slug>`, `errors` con `{code, message, params}` ya traducido por el servidor (ADR §6).
- **Idempotencia**: `Idempotency-Key` obligatoria en `POST /user-imports/{id}/execute`; ausente es `400`; repetición devuelve el estado original con `Idempotency-Replayed: true`; misma clave con cuerpo distinto o en curso es `409` (§7, ADR §8).
- **`PATCH`/`PUT`**: semántica de fusión de `ADR-038 §9.2` (clave ausente no toca el campo, `null` lo vacía, `""` es `422`, arrays se reemplazan enteros) — aplica a `PATCH /tenant/settings` y `PATCH /users/{id}`.

---

## 10. Eventos de dominio emitidos

Listados en `funcional.md` §7 con su consumidor previsto.

## 11. Webhooks

Ninguno en 1.1. La integración saliente por webhook no está requerida en `REQ-CORE`.
