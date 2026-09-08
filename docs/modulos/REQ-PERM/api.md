# REQ-PERM · API

> Paso **1.5**. Prefijo `/api/v1`. **Todo es API** (`INV-006`): 1.5b construirá la interfaz sobre exactamente estos endpoints, sin necesitar nada más del backend.
>
> Todo lo que sigue se ajusta a **`ADR-038`** (convenciones de la API REST): envoltura (§3), paginación (§4), filtrado y orden (§5), error RFC 9457 con `type` como URN (§6), versionado (§7), idempotencia (§8) y semántica de `PATCH`/`PUT` (§9). Este documento **no repite** el ADR; sólo señala dónde este paso lo usa de forma menos obvia.
>
> **Todo identificador de rutas y cuerpos es el `public_id` ULID** salvo el `code` de un permiso, que es su clave natural y pública (`datos.md §7`).

---

## 1. Inventario

| Ruta | Verbo | Estado en 1.5 | Permiso |
|------|-------|---------------|---------|
| `/roles` | `GET` | Existe desde 1.1 · **sin cambios** | `rol.leer` |
| `/roles` | `POST` | **Nuevo** (`RPERM-005`, `RPERM-006`) | `rol.crear` |
| `/roles/{public_id}` | `GET` | Existe desde 1.1 · respuesta ampliada | `rol.leer` |
| `/roles/{public_id}` | `PATCH` | Existe desde 1.3 · **ampliado** | `rol.actualizar` (+ `rol_datos_especiales.actualizar` para una clave) |
| `/roles/{public_id}` | `DELETE` | **Nuevo** | `rol.eliminar` |
| `/roles/{public_id}/permissions` | `PUT` | **Nuevo** | `rol.actualizar` |
| `/permissions` | `GET` | Existe desde 1.1 · respuesta ampliada | `permiso.leer` |
| `/users/{public_id}/roles` | `GET` | Existe desde 1.1 · sin cambios | `asignacion_rol.leer` |
| `/users/{public_id}/roles` | `PUT` | Existe desde 1.1 · **reglas ampliadas** | `asignacion_rol.crear` (ver §8.2) |
| `/users/{public_id}/effective-permissions` | `GET` | **Nuevo** (`RPERM-009`) | `permiso_efectivo.leer` |
| `/me/effective-permissions` | `GET` | **Nuevo** (`RPERM-009`, autoservicio) | **Ninguno** · por identidad del portador de la cookie |

**Ningún endpoint nuevo lleva `Idempotency-Key`.** Ninguno cumple los cuatro criterios de `ADR-038 §8.1`: no mueven dinero, no envían nada a terceros, no ejecutan lotes sobre varias entidades, y `POST /roles` está protegido de duplicados por el índice único `(tenant_id, code) WHERE deleted_at IS NULL`, que convierte el reintento en un `422` — que es el resultado correcto. Es el mismo «no» deliberado que el ADR argumenta para `POST /users`.

**Ninguna ruta de este paso lleva el *middleware* `module-enabled`.** `REQ-CORE` no es desactivable (`REQ-CORE/operacion.md §1`).

---

## 2. Cambios en las respuestas existentes

### 2.1 `GET /api/v1/permissions`

- **Permiso**: `permiso` · `leer` · `todos`
- **Parámetros**: `module_code`, `resource`, `include_retired` (por defecto `false`) — sin cambios
- **Respuesta 200**: dos campos nuevos por elemento

```json
{
  "data": [
    {
      "code": "auditoria.leer",
      "resource": "auditoria",
      "action": "leer",
      "module_code": "core",
      "is_special_category": false,
      "applicable_scopes": ["todos", "propios"],
      "grantable_scopes": ["todos", "propios"],
      "retired_at": null
    },
    {
      "code": "usuario.leer",
      "resource": "usuario",
      "action": "leer",
      "module_code": "core",
      "is_special_category": false,
      "applicable_scopes": ["todos"],
      "grantable_scopes": ["todos"],
      "retired_at": null
    }
  ]
}
```

| Campo | Qué es |
|-------|--------|
| `applicable_scopes` | Los ámbitos que **el permiso admite**, según lo declara su módulo. `null` en la columna se devuelve como `["todos"]` |
| `grantable_scopes` | Los que **se pueden conceder ahora mismo**: `applicable_scopes` menos los que no tienen resolutor registrado |

**La diferencia entre los dos campos es exactamente «este ámbito existe pero su módulo todavía no ha llegado».** Es lo que permitirá a la matriz de 1.5b mostrar `grupo` en gris con explicación en vez de ofrecerlo y devolver `422`, y evita tener que añadir un endpoint de catálogo de ámbitos.

Añadir dos campos a una respuesta es **compatible** (`ADR-038 §7.2`).

**Este listado sigue sin paginar, y es deliberado.** Es una tabla de referencia acotada (unas 35 filas hoy, unos pocos cientos con los 53 módulos) que la matriz de permisos necesita **entera** para pintarse. Paginarla convertiría una pantalla en N peticiones y, peor, cambiar hoy a paginación por defecto sería **incompatible** (`ADR-038 §7.2`): un cliente que hoy lee todo recibiría 25 filas y creería haber leído el catálogo. Si algún día el tamaño lo exige, se añade `page`/`per_page` como parámetros **opcionales**, que sí es aditivo.

### 2.2 `GET /api/v1/roles/{public_id}`

Sin cambios de forma: ya devuelve `scope` por concesión desde 1.1. Lo que cambia es que **el valor significa algo**.

```json
{
  "public_id": "01J8...",
  "code": "coordinacion_auditoria",
  "name": "Coordinación de auditoría",
  "is_system": false,
  "mfa_required": false,
  "special_data_access": false,
  "users_count": 2,
  "permissions": [
    { "code": "auditoria.leer", "resource": "auditoria", "action": "leer", "effect": "allow", "scope": "propios" }
  ]
}
```

---

## 3. `POST /api/v1/roles` — alta y clonación (`RPERM-005`, `RPERM-006`)

- **Permiso**: `rol` · `crear` · `todos`
- **Idempotencia**: no (§1)

### 3.1 Alta desde cero

```json
{
  "code": "coordinacion_auditoria",
  "name": "Coordinación de auditoría",
  "mfa_required": false,
  "special_data_access": false,
  "permissions": [
    { "code": "auditoria.leer", "effect": "allow", "scope": "propios" }
  ]
}
```

| Campo | Obligatorio | Reglas |
|-------|-------------|--------|
| `code` | Sí | `^[a-z][a-z0-9_]{2,63}$`. Único vivo por tenant |
| `name` | Sí | Literal del centro, no clave de traducción. Un rol personalizado lleva `name` y **nunca** `name_key`: el `CHECK` `roles_name_source_check` exige exactamente uno de los dos |
| `mfa_required` | No (por defecto `false`) | `RPERM-014`. Ponerlo a `true` **no exige permiso adicional**: es una restricción, no una escalada |
| `special_data_access` | No (por defecto `false`) | Ponerlo a `true` exige `rol_datos_especiales.actualizar` **y** poseerlo (`funcional.md §5.3`) |
| `permissions` | No (por defecto `[]`) | Cada entrada con `code`, `effect` y **`scope` obligatorio**. Sujeta entera a las validaciones de §5 |
| `is_system` | **No es un campo** | Siempre `false`. Enviarlo es `422` |
| `name_key` | **No es un campo** | Reservado a los roles del aprovisionamiento |

- **Respuesta 201**: el recurso, con la forma de `GET /roles/{public_id}`. Cabecera `Location`.
- **Errores**
  - `422` — `code` con formato inválido, `code` ya usado (`core.validation.role_code_taken`), `name` vacío, `is_system` o `name_key` presentes, o cualquiera de los `422` de §5
  - `403` — `RPERM-013` sobre alguna concesión (§5.4), o `special_data_access: true` sin poder (`funcional.md §5.3`)
  - 401
- **Auditoría**: `created` sobre `Role` más un `created` por concesión

### 3.2 Clonación

```json
{
  "clone_from": "01J8...",
  "code": "docente_ampliado",
  "name": "Docente con acceso ampliado"
}
```

- `clone_from` es el `public_id` del rol origen, **del mismo tenant**.
- Se copian **las concesiones** (`code`, `effect`, `scope`) y **`mfa_required`**.
- Se copia `special_data_access` **sólo si el solicitante puede activarlo**; si no, `422` con `core.validation.clone_requires_special_data_access` y **no se crea nada**. No se degrada en silencio (`funcional.md §7.5`).
- **No** se copian `code`, `name`, `is_system` (siempre `false`) ni las asignaciones a usuarios.
- Se puede clonar un rol `is_system`; lo que no se puede es **crear** uno `is_system`.
- `clone_from` y `permissions` son **mutuamente excluyentes** ⇒ `422`. Mezclarlos haría ambiguo si la lista sustituye o amplía lo clonado, y una ambigüedad en la tabla más sensible del sistema no se resuelve con una convención implícita.
- **La copia es completa: no hay herencia.** Editar el origen después **no afecta** al clon (`ADR-044 §4.6`).

- **Errores adicionales**: `422` si `clone_from` no existe o es de otro tenant (`core.validation.clone_source_not_found` — indistinguible, por `ADR-038 §6.4`), `403` si el rol origen concede algo que el solicitante no tiene (`RPERM-013`)

---

## 4. `PATCH /api/v1/roles/{public_id}` — editor completo

La **misma ruta y el mismo permiso** que 1.3, ampliada tal como `RolesController::update()` dejó documentado en su propio docblock (`ADR-044 §4.10`).

- **Permiso base**: `rol` · `actualizar` · `todos`
- **Semántica**: `ADR-038 §9.2` sin excepciones — clave ausente no toca el campo, `null` vacía, `""` es `422`
- **Idempotencia**: no (`PATCH` parcial es naturalmente repetible)

| Clave | Permiso adicional | Reglas |
|-------|-------------------|--------|
| `name` | — | Sólo en roles con `is_system = false`. Un rol de sistema lleva `name_key` traducida (`INV-009`) ⇒ `422` |
| `mfa_required` | — | Comportamiento **idéntico** al de 1.3, incluido el evento `RoleMfaRequirementChanged` cuando la obligación empieza (`false → true`) |
| `special_data_access` | **`rol_datos_especiales.actualizar`** + posesión | Enviarla con sólo `rol.actualizar` ⇒ `403` |
| `code` | — | **No editable** ⇒ `422`. Es la referencia estable del rol |
| `permissions` | — | **No se admite aquí** ⇒ `422`. Las concesiones van por `PUT /roles/{id}/permissions` (§5) |

> **Lo que 1.5 retira**: hoy `RolesController::update()` rechaza con `422` (`core.validation.role_patch_field_not_allowed`) toda clave distinta de `mfa_required`. Ese guardarraíl se sustituye por la lista de arriba; **no se elimina sin sustituto**. Sigue siendo `422` cualquier clave fuera de la tabla.

- **Errores**: `401`; `403` (sin permiso, o `special_data_access` sin poder); `404` (inexistente o de otro tenant); `422` (clave no admitida, `name` en rol de sistema, `code` presente)
- **Auditoría**: `updated` sobre `Role` — ya funciona desde 0.9

---

## 5. `PUT /api/v1/roles/{public_id}/permissions` — concesión y revocación

Reemplazo **completo** del conjunto de concesiones del rol. Es `PUT` de colección subordinada según `ADR-038 §9.1`, por el mismo motivo que `PUT /users/{id}/roles`: «este rol concede exactamente esto» es idempotente y evita estados intermedios.

- **Permiso**: `rol` · `actualizar` · `todos`
- **Cuerpo**

```json
{
  "permissions": [
    { "code": "auditoria.leer",     "effect": "allow", "scope": "propios" },
    { "code": "usuario.leer",       "effect": "allow", "scope": "todos" },
    { "code": "configuracion.actualizar", "effect": "deny", "scope": "todos" }
  ]
}
```

- `permissions` es **obligatorio y puede ser el array vacío** («quítalas todas»), como exige `ADR-038 §9.3`.
- **`scope` es obligatorio en cada entrada.** No hay valor por defecto y **no se admite `null`**: omitirlo equivale a conceder acceso total, que es el error característico que la *skill* `permisos-y-roles` describe, y por eso se rechaza en la API igual que en el esquema (`datos.md §2.2`).
- En un `deny`, `scope` sigue siendo obligatorio por uniformidad de forma, pero **no se evalúa**: `deny` es ciego al ámbito (`funcional.md §4.2`). Se recomienda `todos` y la API no impone otra cosa.
- Un mismo `code` **no puede aparecer dos veces** ⇒ `422`. Coincide con el único de `permission_role` (`funcional.md §4.5`).

### 5.1 Validación, en este orden exacto

| # | Comprobación | Fallo |
|---|--------------|-------|
| 1 | El `code` existe en `permissions` | `422` `core.validation.permission_not_found` |
| 2 | El `code` no está `retired_at` | `422` `core.validation.permission_retired` |
| 3 | `effect ∈ {allow, deny}` | `422` |
| 4 | `scope` está en el vocabulario de los seis | `422` |
| 5 | `scope ∈ applicable_scopes` del permiso | `422` `core.validation.scope_not_applicable` |
| 6 | `scope = 'todos'` **o** hay resolutor registrado para `(scope, resource)` | `422` `core.validation.scope_resolver_missing` |
| 7 | `RPERM-013` sobre lo que se **añade o amplía** (§5.4) | `403` |
| 8 | El rol existe en el tenant | `404` |

El orden importa: las comprobaciones de forma van antes que la de autorización, para que un cuerpo mal formado no produzca un `403` que parezca un problema de permisos.

### 5.2 Respuesta

- **200** con el rol completo, forma de `GET /roles/{public_id}`. No `204`: el cliente necesita ver qué quedó (`ADR-038 §9.3`).

### 5.3 Efectos

- **Auditoría** (`datos.md §5.2`): un `created` por concesión añadida, un `updated` por concesión cuyo `effect` o `scope` cambia, un `deleted` por concesión retirada. **Nada si el conjunto enviado es idéntico al que había** (`ADR-038 §9.3`).
- **Emite** `RolePermissionsChanged` (ver `funcional.md OPEN-PERM-04`).
- **Efecto inmediato**: sin caché (`ADR-044 §4.7`), la siguiente petición de cualquier titular del rol ya resuelve con el conjunto nuevo.

### 5.4 `RPERM-013` en este endpoint

Se compara **sólo lo que se añade o amplía**:

- Una entrada `allow` nueva, o una cuyo `scope` pasa a uno que el solicitante no posee ⇒ se comprueba.
- Retirar una entrada, o estrecharla a un ámbito que el solicitante sí posee ⇒ **no se comprueba nada**. Restringir siempre se permite.
- Las entradas `deny` **no se comprueban nunca** (`funcional.md §7.7`): nadie necesita poseer un permiso para prohibírselo a otro.

La comparación es de pares: el ámbito concedido debe estar en el conjunto efectivo del solicitante para ese código, **con `todos` absorbiendo cualquier ámbito** (`ADR-044 §4.8`).

Fallo ⇒ `403` con `detail` que nombra el primer código y ámbito que lo provoca. **`403` y no `422`**: es una decisión de autorización, no de forma del cuerpo.

---

## 6. `DELETE /api/v1/roles/{public_id}` — baja de rol

- **Permiso**: `rol` · `eliminar` · `todos`
- **Respuesta 204**
- **Errores**
  - `409` `core.validation.role_is_system` — es un rol del aprovisionamiento (`is_system = true`)
  - `409` `core.validation.role_has_assignments` — tiene asignaciones vivas. El cuerpo incluye `params.users_count` para que el cliente pueda decirlo con un número
  - `404` — inexistente o de otro tenant
  - 401, 403
- **Efecto**: borrado **lógico** (`INV-004`) del rol y de sus concesiones
- **Auditoría**: `deleted` sobre `Role` más un `deleted` por concesión

**No hay cascada sobre las asignaciones, y es deliberado** (`funcional.md §7.9`): arrastrar la baja cambiaría en silencio lo que pueden hacer varias personas a la vez.

---

## 7. Permisos efectivos (`RPERM-009`)

Dos endpoints, **un solo controlador y un solo cálculo**. La diferencia está entera en cómo se autoriza cada ruta.

| Ruta | Sujeto | Autorización |
|------|--------|--------------|
| `GET /users/{public_id}/effective-permissions` | El usuario que indica la ruta | Permiso `permiso_efectivo` · `leer` · `todos` |
| `GET /me/effective-permissions` | **El portador de la cookie** | **Por identidad**, sin permiso (§7.4) |

### 7.1 `GET /api/v1/users/{public_id}/effective-permissions`

- **Permiso**: `permiso_efectivo` · `leer` · `todos` — recurso nuevo declarado por `REQ-CORE`, concedido **sólo a `administrador_centro`** por defecto (decisión del usuario, 2026-09-04; `funcional.md §18`, `OPEN-PERM-01`)
- **Parámetros**

| Parámetro | Nota |
|-----------|------|
| `module_code` | Acota al catálogo de un módulo. Valores múltiples separados por coma (`ADR-038 §5.2`) |
| `resource` | Ídem |
| `include_inert` | Booleano, por defecto **`true`**. A `false` omite las concesiones inertes |

- **Respuesta 200**

```json
{
  "data": [
    {
      "code": "auditoria.leer",
      "resource": "auditoria",
      "action": "leer",
      "module_code": "core",
      "is_special_category": false,
      "decision": "permitido",
      "scopes": ["propios"],
      "unrestricted": false,
      "sources": [
        {
          "role": { "public_id": "01J8...", "code": "coordinacion_auditoria", "name": "Coordinación de auditoría" },
          "effect": "allow",
          "scope": "propios",
          "inert": false,
          "inert_reason": null
        }
      ]
    },
    {
      "code": "salud.leer",
      "resource": "salud",
      "action": "leer",
      "module_code": "salud",
      "is_special_category": true,
      "decision": "denegado",
      "scopes": [],
      "unrestricted": false,
      "sources": [
        {
          "role": { "public_id": "01J9...", "code": "auxiliar_enfermeria", "name": "Auxiliar de enfermería" },
          "effect": "allow",
          "scope": "todos",
          "inert": true,
          "inert_reason": "inerte_datos_especiales"
        }
      ]
    },
    {
      "code": "configuracion.actualizar",
      "resource": "configuracion",
      "action": "actualizar",
      "module_code": "core",
      "is_special_category": false,
      "decision": "denegado",
      "scopes": [],
      "unrestricted": false,
      "sources": [
        {
          "role": { "public_id": "01JA...", "code": "administrador_centro", "name": "Administrador de Centro" },
          "effect": "allow",
          "scope": "todos",
          "inert": false,
          "inert_reason": null
        },
        {
          "role": { "public_id": "01JB...", "code": "cuenta_restringida", "name": "Cuenta restringida" },
          "effect": "deny",
          "scope": "todos",
          "inert": false,
          "inert_reason": null
        }
      ]
    }
  ],
  "meta": {
    "subject": { "public_id": "01J8...", "display_name": "Ana Pérez" },
    "roles": [
      { "public_id": "01JA...", "code": "administrador_centro", "name": "Administrador de Centro" }
    ],
    "computed_at": "2026-09-04T09:00:00Z"
  }
}
```

**Cómo se lee el tercer ejemplo**, que es el que justifica el campo `sources`: el permiso está **denegado pese a existir un `allow`**, porque otro rol lleva un `deny` y `deny` veta el código entero (`RPERM-007`). Sin la procedencia, un administrador vería «denegado» junto a una casilla marcada en la matriz y no tendría forma de saber por qué.

### 7.2 Motivos de inercia

| `inert_reason` | Significado |
|----------------|-------------|
| `inerte_permiso_retirado` | El permiso ya no lo declara ningún módulo (`retired_at`) |
| `inerte_modulo` | El módulo del permiso no es utilizable por este tenant (`RMOD-009`) |
| `inerte_datos_especiales` | Concesión de categoría especial desde un rol sin `special_data_access` (`RPERM-012`) |
| `inerte_sin_resolutor` | Ámbito distinto de `todos` sin resolutor registrado |

Es un enumerado de respuesta y por tanto **extensible** (`ADR-038 §7.3`): el cliente está obligado a tener rama por defecto.

### 7.3 Tres propiedades no negociables de estos endpoints

1. **Se calculan con el mismo código que la aplicación real.** Si tuvieran lógica propia serían una segunda implementación de la autorización y divergirían (`ADR-044 §8`). Verificable por lectura del código, no sólo por test.
2. **Distinguen «no concedido» de «concedido pero inerte».** Es su valor entero.
3. **No paginan.** Es una fotografía calculada que sólo tiene sentido entera, y los filtros `module_code`/`resource` cubren la necesidad de acotarla. Es el mismo argumento que §2.1.

- **Errores de §7.1**: 401, 403, 404 (usuario inexistente o de otro tenant)

### 7.4 `GET /api/v1/me/effective-permissions` — autoservicio

**Ruta propia, sin permiso, autorizada por identidad del portador de la cookie**, igual que `GET /me` y que los tres endpoints de `/auth/sessions` (`REQ-AUTH/permisos.md §B.1`). Decisión del usuario del 2026-09-04; la forma técnica se argumenta en `funcional.md §7.11`.

- **Permiso**: **ninguno**. No lleva *middleware* `permission:` y no lo llevará nunca
- **Sujeto**: **siempre** el usuario autenticado. **No acepta ningún parámetro de sujeto**, ni en la ruta ni en el cuerpo ni en la consulta
- **Parámetros**: los mismos tres de §7.1 (`module_code`, `resource`, `include_inert`)
- **Respuesta 200**: **idéntica en forma** a la de §7.1, con `meta.subject` referido al propio solicitante
- **Errores**: `401` sin sesión. **No hay `403` ni `404` posibles**: no hay sujeto que buscar ni permiso que faltar

**La regla de implementación que hace que esto sea seguro**, y es la misma que `RN-AUTH-41` fijó para `/auth/sessions`: el identificador del solicitante sale de la sesión y **entra en la consulta**, nunca se compara después con un valor recibido. Un `find($publicId)` seguido de un `if` es un fallo de revisión aquí, porque basta con que un camino futuro olvide el `if`.

**Por qué el autoservicio no se modela como `permiso_efectivo.leer` con ámbito `propios`**: `permisos.md §5.3`. Un permiso puede ponerse a `false`, y un usuario tiene que poder saber siempre qué puede hacer.

---

## 8. Cambios en `PUT /api/v1/users/{public_id}/roles`

Ruta, verbo y cuerpo **sin cambios** desde 1.1. Cambian dos cosas dentro.

### 8.1 `RPERM-013` pasa a comparar pares

Hoy `UserRolesController::assertActorCanGrant()` compara **códigos**: reúne los `permission_code` con `effect = 'allow'` de los roles que se añaden y comprueba que estén todos en los permisos efectivos del solicitante.

A partir de 1.5 compara **pares (código, ámbito)** con la regla de absorción de `ADR-044 §4.8`, y usa el conjunto efectivo del solicitante calculado por el motor nuevo — **con sus inercias**. Un solicitante cuyo `salud.leer` es inerte por categoría especial no puede conferirlo asignando un rol, que es la respuesta correcta y la que cierra el *confused deputy* también por esta vía.

### 8.2 Un hueco preexistente que hay que decidir, no arrastrar

`docs/modulos/REQ-CORE/api.md §5` documenta este endpoint como:

> «**Permiso**: `asignacion_rol` · `crear` · `todos` (y `asignacion_rol` · `eliminar` · `todos` si el conjunto retira alguno)»

**La ruta declara únicamente `permission:asignacion_rol.crear`.** La comprobación de `asignacion_rol.eliminar` al retirar un rol **no está implementada**. Hoy no tiene consecuencia práctica —el único rol con `asignacion_rol.crear` es `administrador_centro`, que también tiene `eliminar`— pero 1.5 es precisamente el paso que hace posible un rol personalizado con uno y no con el otro, y a partir de ahí la documentación mentiría sobre una comprobación de autorización.

Está reportado aparte como hallazgo (severidad **Media**, `CLAUDE.md §5`) y **no lo resuelvo aquí**: o se implementa la comprobación en 1.5, o se corrige la documentación de `REQ-CORE`. Es una decisión, no una omisión que pueda tomar la especificación de otro módulo.

### 8.3 Auditoría

`updated` sobre `user` con `changes.roles.{from,to}` como listas de códigos de rol (`datos.md §5.3`). Nada si no hubo cambio efectivo.

Siguen vigentes sin cambios `RN-CORE-06` (`409` al modificarse a sí mismo) y `RN-CORE-07` (`409` si dejaría al centro sin administrador vivo).

---

## 9. Formato de error

`application/problem+json` (RFC 9457) con `type` como URN (`ADR-038 §6`). Ninguna forma nueva; sólo códigos nuevos dentro de `errors`.

```json
{
  "type": "urn:pge:error:validation",
  "title": "Los datos enviados no son válidos",
  "status": 422,
  "detail": "El ámbito «grupo» no puede concederse todavía: su resolutor no está registrado.",
  "instance": "/api/v1/roles/01J8.../permissions",
  "request_id": "01J8...",
  "errors": {
    "permissions.0.scope": [
      {
        "code": "core.validation.scope_resolver_missing",
        "message": "El ámbito «grupo» no puede concederse todavía: lo aportará el módulo de estructura académica.",
        "params": { "scope": "grupo", "resource": "calificacion" }
      }
    ]
  }
}
```

### 9.1 Códigos nuevos

| `code` | Estado | Cuándo |
|--------|--------|--------|
| `core.validation.scope_not_applicable` | 422 | El ámbito no está en `applicable_scopes` del permiso |
| `core.validation.scope_resolver_missing` | 422 | Ámbito distinto de `todos` sin resolutor registrado |
| `core.validation.permission_not_found` | 422 | El `code` no existe en el catálogo |
| `core.validation.permission_retired` | 422 | El `code` está `retired_at` |
| `core.validation.permission_duplicated` | 422 | El mismo `code` dos veces en el cuerpo |
| `core.validation.role_code_taken` | 422 | `code` de rol ya usado vivo en el tenant |
| `core.validation.role_code_immutable` | 422 | Se intentó cambiar el `code` |
| `core.validation.role_name_system` | 422 | Se intentó poner `name` a un rol `is_system` |
| `core.validation.clone_source_not_found` | 422 | `clone_from` inexistente o de otro tenant |
| `core.validation.clone_requires_special_data_access` | 422 | Clonar un rol con `special_data_access` sin poder activarlo |
| `core.validation.role_is_system` | 409 | Se intentó eliminar un rol del aprovisionamiento |
| `core.validation.role_has_assignments` | 409 | Se intentó eliminar un rol con asignaciones vivas |

**Los cuatro mensajes van traducidos a los cuatro idiomas** (`INV-009`, `ADR-021`), renderizados por el servidor (`ADR-038 §6.3`). Ni uno solo se escribe en el código.

### 9.2 `403` con motivo

Los `403` de `RPERM-013` y de `special_data_access` necesitan **decir por qué**, o el administrador no puede corregir nada.

Hoy `ApiException::forbidden()` no acepta clave de detalle, mientras que `ApiException::conflict()` sí. 1.5 le añade una clave opcional, con la misma forma:

| Clave de `detail` | Cuándo |
|-------------------|--------|
| `core.authorization.cannot_grant_unheld_permission` | `RPERM-013`. `params`: `{ "code": "...", "scope": "..." }` |
| `core.authorization.special_data_access_not_held` | Se intentó activar `special_data_access` sin poseerlo |

**Es un cambio compatible**: añadir `detail` donde antes había uno genérico no rompe a ningún cliente (`ADR-038 §7.2`). Y **no se filtra nada**: el mensaje habla de lo que el solicitante intentaba hacer, no de datos ajenos.

### 9.3 `403` frente a `404`, y la regla que este paso añade

| Situación | Respuesta |
|-----------|-----------|
| Sin sesión | `401` |
| Recurso de otro tenant | `404` (`ADR-038 §6.4`) |
| Existe en el tenant, falta el permiso (**la puerta**) | `403` |
| Módulo no utilizable por el tenant | `403` `urn:pge:error:module-disabled`, **antes** de evaluar el permiso |
| Existe, hay permiso, pero **la fila no satisface la restricción de ámbito** | **`404`** |

La última fila es nueva y es de seguridad, no de estilo: `403` significa «existe pero no puedes» y convertiría cualquier detalle en un oráculo de filas ajenas dentro del propio centro. Extiende dentro del tenant lo que `ADR-038 §6.4` fija entre tenants, y sigue el precedente que `REQ-AUTH/permisos.md §B.4` ya sentó para las sesiones.

---

## 10. Paginación, filtrado y orden

| Listado | Modo | Motivo (`ADR-038 §4.2`) |
|---------|------|--------------------------|
| `GET /roles` | **Por página**, sin cambios desde 1.1 | Catálogo de entidades: las filas nacen de una acción administrativa y su número está acotado por el tamaño del centro |
| `GET /permissions` | **Sin paginar** | §2.1 |
| `GET /users/{id}/effective-permissions` y `GET /me/effective-permissions` | **Sin paginar** | §7.3 |
| `GET /audit-logs` acotado por ámbito `propios` | **Por cursor**, sin cambios | Flujo de eventos, tabla *append-only*. El ámbito **no cambia el modo de paginación**: se aplica como una restricción más dentro de la misma consulta, antes del cursor |

Esa última fila importa más de lo que parece: el cursor de `ADR-038 §4.4` transporta una **huella de los filtros** de la petición. La restricción de ámbito **no es un filtro de la petición** —no la envía el cliente y no puede cambiarla—, así que **no entra en la huella**; entra en la consulta. Si entrara en la huella, un cambio de rol a mitad de paginación invalidaría el cursor con un `422` confuso en lugar de, simplemente, devolver menos filas.

---

## 11. Eventos de dominio emitidos

| Evento | Cuándo | Consumidor |
|--------|--------|------------|
| `UserRolesChanged` | Ya existe desde 1.1 · cambio del conjunto de roles | `REQ-AUTH` (obligación de MFA) |
| `RoleMfaRequirementChanged` | Ya existe desde 1.3 · `mfa_required` pasa de `false` a `true` | `REQ-AUTH`. **También debe emitirse en el alta de un rol con `mfa_required: true`** si hubiera titulares, lo que en un alta nunca ocurre; se documenta para que la implementación no lo dé por hecho al revés |
| `RolePermissionsChanged` | **Nuevo** · cambio en las concesiones de un rol | Ninguno en 1.5 · ver `funcional.md OPEN-PERM-04` |

## 12. Webhooks

Ninguno. Ningún requisito los pide para este módulo.

## 13. OpenAPI

Todos los endpoints nuevos y los tres modificados se documentan en `apps/api/openapi/paths/core.yaml` **antes** de implementarlos (`INV-006`, `CLAUDE.md §10`), referenciando por `$ref` los componentes comunes de `apps/api/openapi/components.yaml` (`Problem`, `PageMeta`, formato `ulid`) en lugar de redefinirlos (`ADR-038 §12.2`). La comprobación de paridad de rutas en CI debe seguir en verde.
