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
    "color_secondary": "#FFFFFF",
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
  "branding": { "color_primary": "#1D4ED8", "color_secondary": "#FFFFFF" }
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
- **Errores**: 401, 403, 404 (`kind` fuera de `{logo, favicon, login-background}`), 413 (excede tamaño), 415 (tipo no admitido), 422 (tipo real distinto del declarado o SVG irreparable)

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
  "color_secondary": "#FFFFFF",
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

> **Fuera de 1.1**: el canje (`POST /api/v1/auth/invitation-redemptions`, con el token en el cuerpo — nunca en la ruta, para que no acabe en logs de proxy, historial ni `Referer` — que fija la contraseña y activa al usuario) pertenece a `REQ-AUTH-001`, paso 1.2. El contrato del token está fijado en `funcional.md` §4.3 para que 1.2 no lo reinvente. Corregido el 2026-08-22 (`REQ-AUTH/funcional.md OPEN-AUTH-08`): la forma `POST /invitations/{token}/accept` era una nota orientativa de 1.1, nunca implementada, y quedaba en contradicción con la especificación vinculante de 1.2.

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
  "meta": { "current_page": 1, "per_page": 25, "total": 16, "last_page": 1 }
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
- **`GET /user-imports` — Respuesta 200**: colección paginada por página (`ADR-038 §4.3`, catálogo de entidades), envuelta en `{"data": [...], "meta": {...}}`, cada elemento con la forma del objeto de abajo.
- **`GET /user-imports/{public_id}` — Respuesta 200**: el recurso **desnudo** (`ADR-038 §3.1`), sin envoltura:

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
| `occurred_at_from`, `occurred_at_to` | Rango sobre `occurred_at`, ISO 8601 con zona, ambos inclusivos. Máximo configurable de ventana. Sufijo `_from`/`_to` de `ADR-038 §5.2` (issue [#266](https://github.com/pirexia/plataforma-educativa/issues/266)); los nombres anteriores `from`/`to` **ya no existen** y, por `ADR-038 §5.2`, se ignoran sin error |
| `actor_id` | ULID de usuario |
| `actor_type` | `user\|system\|console\|import\|platform\|anonymous` |
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
- **Cuerpo**: `{ "format": "csv" }` más **exactamente los filtros estructurados de `GET /audit-logs`**, con los mismos nombres y las mismas reglas de validación (`ADR-054 §8.2`, issue [#267](https://github.com/pirexia/plataforma-educativa/issues/267)): `occurred_at_from`, `occurred_at_to`, `actor_id`, `actor_type`, `event` (array), `auditable_type` (array), `auditable_id`, `module`. Sin `cursor`, `limit`, `sort` ni `q`. Las reglas escalares son las mismas (`IndexAuditLogsRequest::filterRules()`) y el filtrado lo aplica el mismo código que el listado (`AuditLogFilter`); un ULID o un `actor_type` inválido da `422` igual que en el listado
- **Respuesta 202**: `{ "public_id": "01J8...", "status": "pendiente" }`
- **Errores**: `422` si el rango supera el límite de filas configurado o si `format` es `pdf` (**diferido a 1.17**, `funcional.md` §4.6)
- **Efecto**: encola la generación (`INV-012`) y audita la solicitud con `event = 'exported'`. La escritura la hace la clase común `App\Support\Csv\CsvWriter` (`RN-CORE-47`/`48`, issue [#270](https://github.com/pirexia/plataforma-educativa/issues/270)): las celdas de texto que empiezan por un carácter de fórmula (`= + - @`, tab, CR, LF) o por espacio en blanco seguido de `= + - @` se escriben con un apóstrofo delante (`RN-CORE-36`/`48`): un consumidor que parsee el CSV verá ese apóstrofo en el dato
- **Fichero** (esquema fijo, `ADR-054 §8.1`): columnas `occurred_at` (instante ISO 8601 con desfase, p. ej. `2026-01-31T09:15:00+00:00`), `actor`, `actor_type`, `auditable_type`, `auditable_public_id`, `event`, `request_id`; filas por `occurred_at` ascendente y `id`. Dialecto de `ADR-054 §10.3`: coma, UTF-8 con BOM, CRLF, comillas dobles de RFC 4180 sin carácter de escape, cabecera siempre. **Cambio visible respecto a la versión anterior**: el fichero gana BOM y CRLF, y `occurred_at` pasa de `2026-01-31T09:15:00.000000Z` (milisegundos y `Z`) a `2026-01-31T09:15:00+00:00`. Las cabeceras y los códigos (`actor_type`, `event`) siguen como estaban: el idioma de la cabecera quedó decidido por `ADR-055` (`OPEN-054-01` resuelta): son identificadores técnicos.
- **Contrato técnico** (`ADR-055`, `RN-CORE-59`): cabeceras y valores enumerados son los identificadores técnicos de la API, iguales para todos los usuarios y en cualquier idioma, y no dependen de quién lo solicita (la columna `actor` es el nombre de la persona, un dato y no un código; formatos incluidos: ISO 8601, punto decimal). Añadir una columna al final es compatible y consta en `CHANGELOG.md`; renombrar, quitar o reordenar columnas no lo es. Las columnas legibles `<col>_label` (opción C) son una ampliación futura, no implementada.
- **Cambio de contrato (renombrado directo, sin periodo de compatibilidad)**: `from`/`to` → `occurred_at_from`/`occurred_at_to` en `GET /audit-logs` y en este cuerpo (#266). No hay producción (`H0` abierto) y el único consumidor es la SPA (`apps/web/src/modules/core/api/auditLogs.ts`), así que no se aplica expand/contract.

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

---

## 12. Paso 1.8 (`REQ-CORE-008`): sin *endpoints* nuevos

> Estado: **APROBADO** (2026-09-23), con `funcional.md §12`.

**1.8 no añade, modifica ni retira ningún *endpoint*, ni cambia la forma de ninguna respuesta.** Tampoco toca OpenAPI. Es un cliente más de la API existente (`INV-006`).

### 12.1 *Endpoints* que consume el *shell*

| *Endpoint* | Para qué | Autorización | Desde |
|------------|----------|--------------|-------|
| `GET /api/v1/tenant/branding` | Nombre, logotipo, colores e idiomas activos del centro | Anónimo, por *host* | 1.1 (lo pide la capa B de 1.7; 1.8 **no** añade llamadas) |
| `GET /api/v1/me` | Estado de sesión: persona, roles, `permissions`, bloque `mfa` | Identidad | 1.1, ampliado en 1.3 y 1.5 |
| `PATCH /api/v1/me` | Cambio de idioma desde el selector: **solo** `{"person":{"locale":"…"}}` | Identidad | 1.1 |
| `DELETE /api/v1/auth/session` | Cerrar sesión | Identidad (idempotente) | 1.2 |

El panel de inicio **no hace ninguna petición propia** (`funcional.md §12.4`, `RN-CORE-33`). En particular **no** llama a `GET /modules` ni a `GET /me/effective-permissions`: la lista plana de códigos de `/me` basta para decidir visibilidad, y la procedencia de `effective-permissions` es de diagnóstico (1.5b).

### 12.2 Contrato de `GET /me` del que pasa a depender la navegación

1.8 convierte en carga estructural tres campos que hasta ahora solo leían pantallas sueltas. Cualquier cambio futuro sobre ellos es **incompatible** en el sentido de `ADR-038 §7.2` y exige versión:

| Campo | Qué asume el *shell* |
|-------|----------------------|
| `permissions` | Lista de códigos **efectivamente permitidos** para el sujeto, con `deny` aplicado y **sin** los inertes (retirado, módulo no utilizable, categoría especial sin acceso, sin resolutor). Es exactamente lo que devuelve hoy `PermissionResolver::effectivePermissionCodes()` (1.5). Que un permiso de un módulo descontratado **no** aparezca aquí es lo que hace cumplir el criterio 2 de `REQ-CORE-008` sin *endpoint* adicional |
| `mfa.obligated`, `mfa.enrolled`, `mfa.days_remaining` | Bloque «Estado de la cuenta» del panel (`REQ-AUTH/api.md §C.6`) |
| `person.given_name`, `person.locale` | Saludo e idioma con sesión (`RN-CORE-34`) |

`roles` sigue en la respuesta, pero **el *shell* no lo usa para decidir nada** (`RN-CORE-23`); solo podría mostrarse como texto informativo, y 1.8 no lo muestra.

### 12.3 Errores que el cliente interpreta

Ninguno nuevo. La correspondencia de `funcional.md §12.6` se apoya en los `type` URN ya existentes (`ADR-038 §6`): `urn:pge:error:mfa-enrollment-required` (1.3), `urn:pge:error:module-disabled` (1.1, `RMOD-009`), y en el `request_id` que ya lleva todo `problem+json` (`INV-013`).

### 12.4 Paginación

No aplica: 1.8 no consume ningún listado.

### 12.5 `OPEN-CORE-12` y `OPEN-CORE-13`, resueltas — sin efecto sobre este documento

Ambas se resolvieron el 2026-09-23 con la opción que no añade *endpoints*: `OPEN-CORE-12` diferió las pantallas de `REQ-CORE` a `1.9b` (cuando llegue, consumirán los *endpoints* de §2-§8 de este documento **tal como están**; no se prevé ninguno nuevo) y `OPEN-CORE-13` difirió el motor de *widgets* configurables (que sí habría necesitado un recurso nuevo de disposición de panel y preferencia de usuario, con su permiso) al primer paso con un segundo bloque de panel con datos reales.

---

## 13. Paso 1.9 (tablas de datos): sin *endpoints* nuevos

> Estado: **APROBADO** (2026-09-30, decisión del usuario), **ajustado a `ADR-054`, ratificado entero por el usuario el 2026-09-30 (ACEPTADA)**, con `funcional.md §13`. **Implementado** (2026-09-30, `apps/web/src/data-table/`): el cliente consume el contrato de §13.1 y la exportación de §13.2 sin tocar ningún *endpoint*; revisión independiente hecha (sin Crítico/Alto).

**1.9 no añade, modifica ni retira ningún *endpoint*, ni cambia la forma de ninguna respuesta ni de OpenAPI.** El componente de tabla **no hace peticiones por su cuenta**: recibe de cada módulo consumidor la función que llama a su *endpoint* (`funcional.md §13.5`, `RN-CORE-38`).

### 13.1 Contrato que el componente exige a un *endpoint* de listado

Nada nuevo: es `ADR-038` aplicado. Se enumera porque el componente **falla de forma visible** si un *endpoint* no lo cumple:

| Aspecto | Exigencia | Origen |
|---------|-----------|--------|
| Envoltura | `{"data": [...], "meta": {...}}` | `ADR-038 §3.1` |
| Paginación por página | `page`/`per_page` (25 por defecto, 100 máximo, `422` por encima) y `meta.current_page`, `per_page`, `total`, `last_page`; página fuera de rango devuelve `200` con `data: []` | `ADR-038 §4.3` |
| Paginación por cursor | `cursor`/`limit` y `meta.next_cursor`, `has_more`; cursor de otros filtros ⇒ `422`. El cliente pide más solo al activar «Cargar más», nunca por desplazamiento (`RN-CORE-56`); tras un fallo, reintenta con **el mismo** `cursor`; deja de pedir al acumular 1.000 filas (`RN-CORE-52`). El servidor no cambia | `ADR-038 §4.4`, `ADR-054 §2` |
| Orden | `sort` con `-` para descendente, **lista blanca declarada como `enum` en OpenAPI**; una columna solo es `sortable` en el cliente si su `id` está en ese `enum` | `ADR-038 §5.3` |
| Filtros | Parámetro con el mismo nombre que el `id` de la columna; múltiples por comas; rangos `_from`/`_to`; texto libre siempre `q`; parámetro desconocido ignorado; valor inválido ⇒ `422` con `errors.<parámetro>[].message` ya traducido | `ADR-038 §5.2`, `§6.3`, `§13.3` |
| Identidad de fila | `public_id` en cada elemento (o la clave de catálogo de `ADR-051`, declarada por el consumidor) | `ADR-029`, `ADR-051` |

### 13.2 *Endpoints* de exportación que consume

| *Endpoint* | Para qué | Autorización | Desde |
|------------|----------|--------------|-------|
| `POST /api/v1/<recurso>/exports` (el del módulo consumidor) | Solicitar la exportación con los filtros estructurados del listado, **sin `sort`**, sin paginación y **sin `q`**. `202` con `public_id` | `<recurso>.exportar` | Solo existe `POST /audit-logs/exports` (1.1). `usuario.exportar` está declarado **sin *endpoint*** (`permisos.md §7`) |
| `GET /api/v1/data-exports/{public_id}` | Estado y `download_url` firmada. `409` si aún no está lista (el cliente lo trata como «seguir esperando»), `410` si venció | Permiso del recurso exportado **y** ser el solicitante | 1.1 (§8) |

**No existe `GET /data-exports`** (listado propio), y 1.9 **no lo crea** (`OPEN-CORE-25`, opción A, decisión del usuario 2026-09-30): la interfaz avisa de que el enlace se pierde al salir de la vista (`funcional.md §13.14.4`). Se reconsidera en `1.9b`.

**Cuerpo de la solicitud** (`ADR-054 §7.3`, `§8.1`, `§8.2`): **los filtros estructurados del listado y nada más**. Sin `page`/`per_page`/`cursor`, **sin `sort`**, **sin `q`** y **sin lista de columnas**: el fichero tiene esquema fijo por recurso (columnas, nombres, su orden y **el orden de las filas**), definido en el servidor y documentado en su OpenAPI (`OPEN-CORE-23`, opción A). Enviar `sort` sería peor que no enviarlo: por `ADR-038 §5.2` se ignoraría en silencio y el usuario creería que el fichero sigue el orden de la pantalla. Un valor múltiple va como *array* JSON en el cuerpo; la forma por comas de `ADR-038 §5.2` es la de la *query string*, y la traducción entre las dos la hace la función de solicitud del módulo.

**Con `q` activo en la tabla**, la SPA no envía la solicitud: el control está deshabilitado (`funcional.md` `RN-CORE-57`).

### 13.3 Errores que el cliente interpreta

Ninguno nuevo. `422` de filtro va junto a la barra de filtros (`RN-CORE-42`); todos los demás pasan por la correspondencia única de `funcional.md §12.6`.

### 13.4 Norma común de CSV para *endpoints* de exportación futuros

Norma de `ADR-054 §8`-`§10` (`funcional.md` `RN-CORE-46`-`48` y `RN-CORE-58`). **Obliga a los pasos que creen o modifiquen un `POST /<recurso>/exports`, no a 1.9**, que no toca ninguno.

| Aspecto | Norma | Origen |
|---------|-------|--------|
| Generación | Siempre en cola en servidor (`INV-012`), límite de filas con `422` (`RNF-LIM-004`), ámbito del permiso aplicado dentro del trabajo (`RN-PERM-15`), URL firmada de caducidad corta, evento `exported`, retención de siete días. Lo que ya hace 1.1 | `ADR-054 §8.3` |
| Esquema del fichero | Fijo por recurso y documentado en OpenAPI: columnas, nombres, su orden y **el orden de las filas**. La solicitud no acepta `sort` | `OPEN-CORE-23` A, `ADR-054 §8.1` |
| **Paridad de filtros** | El *endpoint* acepta **exactamente los filtros estructurados de su listado**, con los mismos nombres y la misma semántica, salvo paginación, `sort` y `q`. **Test exigible por *endpoint***: todo parámetro de filtro del listado en OpenAPI existe en el esquema de la solicitud de exportación, o el test falla. `POST /audit-logs/exports` **cumple** la regla desde el issue [#267](https://github.com/pirexia/plataforma-educativa/issues/267) (test `ADR-054 §8.2 (#267)` en `AuditLogsEndpointsTest`) | `ADR-054 §8.2` |
| **Texto libre** | **Ningún *endpoint* de exportación acepta `q`**. Si el listado del recurso acepta `q`, la exportación responde **`422`** al recibirlo, con código de error propio del recurso; no lo ignora | `ADR-054 §9`, `RN-CORE-58` |
| Escritura CSV | Una sola clase en `apps/api/app/Support/Csv/`, sin interfaz; tipos de columna declarados por el generador; neutralización solo sobre texto y cabecera, con las dos condiciones de `RN-CORE-48` | `ADR-054 §10.1`/`§10.2` |
| Dialecto | **Coma, UTF-8 con BOM, CRLF**, comillas dobles de RFC 4180 sin carácter de escape, cabecera siempre, sin `sep=` | `OPEN-CORE-24` A, `ADR-054 §10.3` |
| Idioma de cabecera y enumerados | **Resuelto** (`OPEN-054-01`): contrato técnico, sin traducir (`RN-CORE-59`) | `ADR-055` |

**`POST /audit-logs/exports` no cambió en 1.9; cambió después, en la rama `fix/REQ-CORE-005-auditoria-csv-rangos-y-filtros`**: la clase común `CsvWriter` y el dialecto (#270; la salida ganó BOM y CRLF, anotado en `CHANGELOG.md`), los nombres `occurred_at_from`/`occurred_at_to` (#266) y la paridad de filtros (#267) ya están implementados. `OPEN-054-01` (idioma de cabecera y enumerados) quedó resuelta por `ADR-055`: `1.9b` no tiene este bloqueo.

---

## 14. Paso 1.9b (pantallas de gestión): *endpoints* nuevos y cambios

> Estado: **APROBADA** (2026-10-01, decisión del usuario), con `funcional.md §14`. Resueltas las preguntas que afectan a este documento: `OPEN-CORE-32` (B, esquema del CSV de usuarios de §14.1), `-39` (A, S4) y `-31` (B, §14.5). Siguen condicionados a preguntas abiertas S9 (`OPEN-CORE-38`) y S10 (`OPEN-CORE-34`). La numeración `S1`-`S10` es la de `funcional.md §14.11`.
>
> **Implementado en `1.9b`** (2026-10-02): S1 a S7 (la parte de `GET /audit-logs`/`POST /audit-logs/exports` de S7 es de `1.9d`, §14.3). Precisiones de la implementación: (a) el cuerpo de `POST /users/exports` acepta `format` **opcional** (por defecto `csv`; la SPA no lo envía, `CA-CORE-222`); (b) el `422` de `q` lleva el código `core.validation.export_search_not_supported` en `errors.q`; (c) un valor fuera de vocabulario en un filtro de lista por comas (`invitations.status`, `users.locale`) responde `422` con `core.validation.in_list` (regla `App\Support\Api\Rules\InList`, mensaje `core.validation.filter_value_invalid`); (d) `GET /users?status=` y `?role=` siguen sin validar sus valores (como antes de 1.9b, solo aceptan listas): el `POST /users/exports` sí valida `status`, `locale` y `role` (ULID) por elemento; (e) `GET /users/{id}?include_deleted=` valida el booleano con la regla común (`422` si no es `true`/`false`).

Todo lo que sigue cumple `ADR-038` (envoltura, `problem+json`, filtros por comas, `q`, `sort` en lista blanca declarada como `enum` en OpenAPI) y se documenta en `apps/api/openapi/` antes de mezclar (`CLAUDE.md §10`). Salvo S4, ningún cambio altera una respuesta que hoy sea correcta según este documento.

### 14.1 `POST /api/v1/users/exports` (S1, nuevo)

- **Permiso**: `usuario` · `exportar` · `todos` (`permisos.md §2`, declarado desde 1.1 sin *endpoint*). Con `include_deleted: true`, además `usuario` · `eliminar` · `todos` (igual que `GET /users`).
- **Cuerpo** (JSON): `{ "format": "csv" }` más **exactamente los filtros estructurados de `GET /users`**, con los mismos nombres y reglas (`ADR-054 §8.2`); los múltiples como *array*:

```json
{
  "format": "csv",
  "status": ["activo", "inactivo"],
  "role": ["01J8..."],
  "locale": ["es-ES", "en"],
  "include_deleted": false
}
```

- **No acepta** `q` (⇒ `422` con código propio del recurso, `RN-CORE-58`), ni `sort`, `page`, `per_page` (parámetros desconocidos: se ignoran por `ADR-038 §5.2`, salvo `q`, que es conocido del recurso y por eso se rechaza).
- **Respuesta 202**: `{ "public_id": "01J8...", "status": "pendiente" }`.
- **Errores**: 401; 403 (sin `usuario.exportar`, o `include_deleted` sin `usuario.eliminar`); 422 (`q` presente, valor de filtro inválido, `format` distinto de `csv`, o el conjunto supera `CORE_EXPORT_MAX_ROWS`, `RNF-LIM-004`).
- **Efecto**: crea la fila de `data_exports` con `kind = 'users'` y `filters` igual al cuerpo sin `format` (nunca `q`, `ADR-054 §9`), la audita (`created` automático y `exported`, como la de auditoría) y encola `GenerateUserExport` en `core-exports` (`INV-012`).
- **Fichero** (contrato técnico, `ADR-055`; esquema fijo, `ADR-054 §8.1`; `OPEN-CORE-32` = B, decisión del usuario del 2026-10-01). Se documenta igual en OpenAPI (respuesta `text/csv` del objeto descargado, con el esquema de columnas como descripción del fichero, como el de auditoría):

  | # | Columna | Origen | Tipo | Vacío |
  |---|---------|--------|------|-------|
  | 1 | `public_id` | `public_id` | Texto (ULID) | Nunca |
  | 2 | `status` | `status` (código: `pendiente`, `activo`, `inactivo`) | Texto | Nunca |
  | 3 | `deleted_at` | `deleted_at` | Instante ISO 8601 con desfase | Si no está dado de baja |
  | 4 | `created_at` | `created_at` | Instante ISO 8601 con desfase | Nunca |
  | 5 | `email` | `email` | Texto | Nunca |
  | 6 | `given_name` | `person.given_name` | Texto | Nunca |
  | 7 | `family_name_1` | `person.family_name_1` | Texto | Nunca |
  | 8 | `family_name_2` | `person.family_name_2` | Texto | Si no tiene |
  | 9 | `contact_email` | `person.contact_email` | Texto | Si no tiene |
  | 10 | `contact_phone` | `person.contact_phone` | Texto | Si no tiene |
  | 11 | `locale` | `person.locale` | Texto | Nunca |
  | 12 | `roles` | `roles[].code` ordenados alfabéticamente y unidos con `\|` | Texto | Si no tiene roles |

  Cabecera exacta: `public_id,status,deleted_at,created_at,email,given_name,family_name_1,family_name_2,contact_email,contact_phone,locale,roles`. **Filas** ordenadas por `family_name_1`, `given_name` y `public_id`, ascendente. **No contiene** `document_type`, `document_number` ni `birth_date` (minimización, `INV-008`): añadirlos después sería un cambio aditivo (columnas al final, `ADR-055 §2.4`) que requiere decisión expresa del usuario. Enumerados = código técnico; instantes ISO 8601 con desfase; el generador no traduce (`RN-CORE-59`). Escritura con `App\Support\Csv\CsvWriter`, tipos declarados, neutralización de texto (`RN-CORE-48`), dialecto de `ADR-054 §10.3`. Nombre del objeto: `tenants/{tenant_public_id}/exports/{export_public_id}.csv`.
- **Acotado dentro del trabajo**: el trabajo vuelve a aplicar la decisión de permiso del solicitante (`RN-PERM-15`); con el único ámbito admitido (`todos`) no reduce filas, pero la llamada existe para que un ámbito futuro no la necesite añadir.

### 14.2 `GET /api/v1/data-exports/{public_id}` (S3, S4)

Sustituye la descripción de §8 en dos puntos; el resto no cambia.

| Aspecto | Antes (código en `495d19c`) | Después |
|---------|-----------------------------|---------|
| Permiso | `auditoria.exportar` fijo en la ruta | El del `kind` de la exportación, por correspondencia cerrada en código: `audit_logs` → `auditoria.exportar`; `users` → `usuario.exportar`. `kind` sin correspondencia ⇒ `403`. Sigue exigiendo ser el solicitante |
| `status = fallida` | `409` con `core.validation.export_failed` | **`200`** con `{ "public_id", "kind", "status": "fallida", "row_count": null, "download_url": null, "expires_at", "error_code": "<clave>" }` (**`OPEN-CORE-39`**) |
| `pendiente` / `generando` | `409 core.validation.export_not_ready` | Igual (el cliente de 1.9 lo trata como «seguir esperando») |
| `completada` | `200` con `download_url` firmada | Igual |
| Vencida | `410` | Igual |

S4 es un **cambio de contrato** respecto al código, no respecto a este documento (que ya describía `200` con `status`): se anota en `CHANGELOG.md`. Único cliente: la SPA (`H0` abierto).

### 14.3 Cambios compatibles en *endpoints* existentes

| # | *Endpoint* | Cambio | Error nuevo |
|---|-----------|--------|-------------|
| S5 | `GET /users` | `sort` admite `-email` (`enum` completo: `family_name_1`, `-family_name_1`, `email`, `-email`, `created_at`, `-created_at`) | — |
| S6 | `GET /users/{public_id}` | Parámetro `include_deleted` (`true`/`false`, `ADR-038 §5.2`). Con `true` exige además `usuario.eliminar`; un usuario eliminado sin él sigue siendo `404` (`CA-CORE-014`) | `403` si `include_deleted=true` sin `usuario.eliminar` |
| S7 | `GET /invitations` | `status` admite varios valores por comas (`OR`) | `422` si algún valor no es del enumerado |
| S7 | `GET /users` | `locale` admite varios valores por comas; mismo cambio en el cuerpo de `POST /users/exports` (*array*) | `422` ídem |
| S7 | `GET /audit-logs` | `actor_type` y `module` admiten varios valores por comas; en `POST /audit-logs/exports` pasan a admitir *array* (paridad, `ADR-054 §8.2`) | `422` ídem |
| S8 | `GET /user-imports`, `GET /user-imports/{public_id}`, `POST /user-imports` | Cada recurso incluye `created_at` (ya aparecía en el ejemplo de §7) | — |
| S9 | — (trabajo `ValidateUserImport`) | `error_summary[].message` y la columna `message` de `report.csv` en el idioma de quien subió el lote (`OPEN-CORE-38`, issue #285). La forma no cambia | — |

Todos son aditivos en el sentido de `ADR-038 §7`: un cliente que envíe un solo valor, o que no envíe el parámetro nuevo, obtiene lo mismo que antes.

### 14.4 `GET /api/v1/audit-logs/facets` (S10, solo si `OPEN-CORE-34` = B)

- **Permiso**: `auditoria` · `leer` (con su ámbito; las facetas no revelan filas, solo los valores filtrables del catálogo).
- **Respuesta 200** (recurso desnudo, `ADR-038 §3.1`; sin traducir, `ADR-038 §3.2`):

```json
{
  "modules": ["auth", "core"],
  "auditable_types": [
    { "alias": "user", "module": "core" },
    { "alias": "role", "module": "core" }
  ],
  "events": ["created", "updated", "deleted", "restored", "read", "exported", "login", "logout", "password_reset_requested"],
  "actor_types": ["user", "system", "console", "import", "platform", "anonymous"]
}
```

- Los valores del ejemplo son ilustrativos: los reales los fija el código. Sale del catálogo declarado en código (`ModuleCatalog`, *morph map* y vocabularios del `CHECK`), no de consultar `audit_logs`: sin coste por volumen y sin revelar qué entidades tienen registros.
- **Errores**: 401, 403.

### 14.5 Lo que 1.9b consume sin cambiar

Todos los *endpoints* de §2-§8 que enumera `funcional.md §14.3`, tal como están, salvo lo dicho en §14.1-§14.4. Desde `OPEN-CORE-31` = B, también, en 1.9e:

- **`GET /modules`** (§6, `modulo.leer`), en **solo lectura**. No está paginado (`{"data": [...]}` sin `meta`): el cliente lo presenta como una única página (`funcional.md` `RN-CORE-87`). `name` viene traducido por el servidor. **`PATCH /module-subscriptions/{id}` no se consume.**
- **`PATCH /me`** (§3), autoservicio por identidad, sin permiso: la pantalla de perfil envía solo `person.contact_email` y `person.contact_phone` (el idioma lo sigue enviando el selector de 1.8, §12.1). Ningún cambio de contrato. En particular, **no** se tocan `POST /users`, `PATCH /users/{id}`, `DELETE /users/{id}`, `POST /users/{id}/restore`, `POST /users/{id}/status`, `POST /users/{id}/invitations`, `DELETE /invitations/{id}`, `GET`/`PUT /users/{id}/roles`, `GET /roles`, `GET /roles/{id}`, `POST /user-imports`, `POST /user-imports/{id}/execute`, `DELETE /user-imports/{id}`, `GET`/`PATCH /tenant/settings` ni `PUT`/`DELETE /tenant/settings/assets/{kind}`. Las tres vistas migradas de `REQ-AUTH` (`funcional.md §14.13`) consumen sus *endpoints* de `REQ-AUTH` sin cambios.

### 14.6 Errores que el cliente interpreta

Ninguno nuevo en la correspondencia de `funcional.md §12.6`. Los `409` de reglas de negocio (`cannot_modify_self`, `last_school_administrator`, `invitation_requires_pending_user`, `import_not_validated`, `import_already_executed`, `invitation_already_accepted`, `email_duplicate`) se muestran con su `detail` ya traducido por el servidor, sin interpretar el código en el cliente (`ADR-038 §6.3`).

### 14.7 Documentación desincronizada detectada

`§8` (`GET /audit-logs`, parámetro `event`) enumera seis valores; el vocabulario vigente tiene nueve (`datos.md` Parte 0.9, `ADR-039`). No se corrige en esta sección (`funcional.md §14.16`, hallazgo 9).
