# REQ-CORE · Permisos

> Sección 11 del documento de requisitos (`RPERM-001` a `RPERM-015`) aplicada a este módulo. El **resolutor granular** es el paso 1.5 (`ADR-034 §2`); lo que se fija aquí es el catálogo de permisos, la matriz y la siembra en los roles predefinidos, para que 1.5 no tenga que inventarlos ni migrarlos.
>
> Fuente de verdad del catálogo: **el código del módulo** (`INV-007`), materializado en la tabla `permissions` por `platform:sync-registry` (`ADR-034 §2`). Esta tabla es su reflejo documental, no su origen.

---

## 1. Recursos que aporta el módulo

| Recurso | Qué representa |
|---------|----------------|
| `usuario` | Cuenta de usuario del centro y la persona asociada |
| `invitacion` | Invitación de activación con enlace caducable |
| `asignacion_rol` | Vínculo entre un usuario y un rol (`role_user`) |
| `rol` | Rol del centro, predefinido o personalizado |
| `permiso` | Catálogo de permisos de la plataforma (referencia, solo lectura) |
| `configuracion` | Configuración del centro: regional, fiscal y branding |
| `modulo` | Suscripción del centro a un módulo y su configuración |
| `auditoria` | Registro inmutable de operaciones del tenant |

Las **acciones** son las de `RPERM-003` sin excepción: `crear`, `leer`, `actualizar`, `eliminar`, `exportar`, `importar`, `aprobar`, `firmar`, `publicar`. No se inventa ninguna acción nueva. Por eso la invitación es un **recurso** (`invitacion.crear`) y no una acción `usuario.invitar`, y la asignación de roles es un recurso (`asignacion_rol.crear`) y no `rol.asignar`.

Los **ámbitos** son los de `RPERM-004`: `todos`, `propios`, `departamento`, `grupo`, `clase`, `unidad_familiar`. En 1.1 **solo se usa `todos`**: ver §5.

---

## 2. Catálogo de permisos que declara `REQ-CORE` en 1.1

`module_code = 'core'`, `is_special_category = false` en todos (este módulo no expone salud, NEAE ni convivencia — §6).

| `code` | Recurso | Acción | Endpoints que lo exigen |
|--------|---------|--------|-------------------------|
| `usuario.leer` | `usuario` | `leer` | `GET /users`, `GET /users/{id}` |
| `usuario.crear` | `usuario` | `crear` | `POST /users` |
| `usuario.actualizar` | `usuario` | `actualizar` | `PATCH /users/{id}`, `POST /users/{id}/status` |
| `usuario.eliminar` | `usuario` | `eliminar` | `DELETE /users/{id}`, `POST /users/{id}/restore`, `GET /users?include_deleted=true` |
| `usuario.importar` | `usuario` | `importar` | `POST /user-imports`, `GET /user-imports`, `GET /user-imports/{id}`, `POST /user-imports/{id}/execute`, `DELETE /user-imports/{id}` |
| `usuario.exportar` | `usuario` | `exportar` | Reservado a la exportación del listado de usuarios. **Sin endpoint en 1.1** — ver §7 |
| `invitacion.leer` | `invitacion` | `leer` | `GET /invitations` |
| `invitacion.crear` | `invitacion` | `crear` | `POST /users/{id}/invitations` |
| `invitacion.eliminar` | `invitacion` | `eliminar` | `DELETE /invitations/{id}` |
| `asignacion_rol.leer` | `asignacion_rol` | `leer` | `GET /users/{id}/roles` |
| `asignacion_rol.crear` | `asignacion_rol` | `crear` | `PUT /users/{id}/roles` (al añadir), `POST /users` con `role_ids` |
| `asignacion_rol.eliminar` | `asignacion_rol` | `eliminar` | `PUT /users/{id}/roles` (al retirar) |
| `rol.leer` | `rol` | `leer` | `GET /roles`, `GET /roles/{id}` |
| `permiso.leer` | `permiso` | `leer` | `GET /permissions` |
| `configuracion.leer` | `configuracion` | `leer` | `GET /tenant`, `GET /tenant/settings` |
| `configuracion.actualizar` | `configuracion` | `actualizar` | `PATCH /tenant/settings`, `PUT`/`DELETE /tenant/settings/assets/{kind}` |
| `modulo.leer` | `modulo` | `leer` | `GET /modules` |
| `modulo.actualizar` | `modulo` | `actualizar` | `PATCH /module-subscriptions/{id}` (solo `settings`) |
| `auditoria.leer` | `auditoria` | `leer` | `GET /audit-logs` |
| `auditoria.exportar` | `auditoria` | `exportar` | `POST /audit-logs/exports`, `GET /data-exports/{id}` de tipo `audit_logs` |

**Endpoints sin permiso, a propósito y de forma auditada:**

| Endpoint | Por qué |
|----------|---------|
| `GET /tenant/branding` | Sin autenticación. La pantalla de login lo necesita antes de que exista sesión (`funcional.md` §4.8). Su superficie está cerrada por contrato y limitada por tasa. |
| `GET /me`, `PATCH /me` | Autorizado **por identidad del sujeto**, no por permiso. Ver §5: con el resolutor provisional, un permiso de ámbito `propios` se comportaría como `todos`. |

---

## 3. Matriz recurso × acción × ámbito

Ámbito único en 1.1: `todos` (§5). `—` significa que el permiso no existe en este módulo; `1.5` significa que el código lo declarará el paso 1.5, no 1.1.

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `usuario` | `todos` | `todos` | `todos` | `todos` | `todos` (§7) | `todos` | — | — | — |
| `invitacion` | `todos` | `todos` | — | `todos` | — | — | — | — | — |
| `asignacion_rol` | `todos` | `todos` | — | `todos` | — | — | — | — | — |
| `rol` | 1.5 | `todos` | 1.5 | 1.5 | — | — | — | — | — |
| `permiso` | — | `todos` | — | — | — | — | — | — | — |
| `configuracion` | — | `todos` | `todos` | — | — | — | — | — | — |
| `modulo` | — | `todos` | `todos` | — | — | — | — | — | — |
| `auditoria` | — | `todos` | — | — | `todos` | — | — | — | — |

`auditoria` no tiene `crear`, `actualizar` ni `eliminar` **por diseño**: la tabla es *append-only* con `REVOKE UPDATE, DELETE` en el motor (`ADR-034 §3`) y la escribe el *observer*, no un usuario. Declarar esos permisos sería sugerir que existe una forma de editar el registro.

`rol` no tiene `exportar`: 1.5 decidirá si su vista previa de permisos efectivos (`RPERM-009`) lo necesita.

---

## 4. Asignación en los roles predefinidos

Los 17 roles de la sección 11.1 se siembran en el aprovisionamiento del tenant (`funcional.md` §4.7) con `is_system = true` y `name_key = 'roles.{code}'`.

### 4.1 Permisos de `REQ-CORE` por rol

Denegación por defecto (`RPERM-011`): lo que no aparece, no se concede.

| Rol (`code`) | Permisos de `REQ-CORE` | Ámbito |
|--------------|------------------------|--------|
| `administrador_centro` | **Todos** los de §2 | `todos` |
| `direccion` | `usuario.leer`, `rol.leer`, `asignacion_rol.leer`, `configuracion.leer`, `modulo.leer` | `todos` |
| `secretaria` | `usuario.leer`, `invitacion.leer` | `todos` |
| `administrativo` | `usuario.leer` | `todos` |
| `docente` | — | — |
| `tutor_grupo` | — | — |
| `orientador` | — | — |
| `coordinador_bienestar` | — | — |
| `estudiante` | — | — |
| `tutor_legal` | — | — |
| `responsable_economico` | — | — |
| `bibliotecario` | — | — |
| `monitor_extraescolares` | — | — |
| `personal_sanitario` | — | — |
| `conserjeria_pas` | — | — |
| `soporte_plataforma` | — (ver §4.4) | — |
| `super_administrador` | **No existe como fila de `roles`** (§4.5) | — |

Los roles sin permisos de `REQ-CORE` no se quedan sin nada útil: acceden a `/me` por identidad y recibirán sus permisos de los módulos de negocio de su ámbito.

**`auditoria.leer` solo lo tiene `administrador_centro`.** Es deliberado: el registro de auditoría es un mapa completo de la actividad de todo el personal del centro, incluida la de Dirección. Ampliarlo a más roles es una decisión del centro que 1.5 permitirá con roles personalizados, no un valor por defecto.

**`configuracion.actualizar` solo lo tiene `administrador_centro`.** Cambia la identidad visual, los idiomas y los datos fiscales del centro.

### 4.2 `mfa_obligatorio` (`RPERM-014`)

Todo rol lleva el atributo `roles.mfa_required` desde 0.8. En 1.1 **solo se siembra su valor**; la exigencia efectiva la implementa 1.3.

| Rol | `mfa_required` | Motivo |
|-----|----------------|--------|
| `administrador_centro` | **`true`** | Puede crear, modificar y dar de baja a cualquier usuario del centro, cambiar su configuración y leer todo el registro de auditoría. Es la cuenta cuyo compromiso entrega el centro entero. |
| `soporte_plataforma` | **`true`** | Rol interno del proveedor con *impersonation* auditada (`REQ-SUP-003`). |
| Resto | `false` en 1.1 | **Decisión de 1.3**, no de 1.1 |

Recomendación explícita para 1.3, no aplicada aquí: `direccion`, `orientador`, `coordinador_bienestar`, `personal_sanitario` y `responsable_economico` son candidatos claros a `mfa_required = true` por el tipo de dato que manejan. Fijarlo ahora sería adelantar una decisión de un paso que todavía no ha visto el problema completo (período de gracia, resolución restrictiva en multi-rol).

### 4.3 `acceso_datos_especiales` (`RPERM-015`)

Atributo `roles.special_data_access`, también sembrado en 1.1 y consumido por los módulos que expongan categoría especial.

| Rol | `special_data_access` |
|-----|-----------------------|
| `orientador` | `true` (atención a la diversidad, informes psicopedagógicos) |
| `coordinador_bienestar` | `true` (protocolos de protección del menor, LOPII) |
| `personal_sanitario` | `true` (fichas de salud, medicación) |
| Resto, incluido `administrador_centro` | `false` |

**`administrador_centro` con `special_data_access = false` es intencionado.** `RPERM-012` exige que los permisos sobre datos de categoría especial estén separados y **no incluidos en ningún rol por defecto**. Administrar el centro no es tratar datos de salud; concederlo por comodidad convertiría la cuenta más usada en la más peligrosa.

### 4.4 `soporte_plataforma`

Se siembra el rol (existe en la sección 11.1) **sin ningún permiso de `REQ-CORE`**. Su acceso real es *impersonation* auditada, que especifica `REQ-SUP-003`, no una concesión directa de permisos. Sembrarlo con permisos aquí sería crear una puerta permanente del proveedor dentro de cada centro.

### 4.5 `super_administrador`

**No es una fila de `roles`.** `ADR-034 §2` lo decidió: los roles de plataforma viven en `platform_admins` y sus propias tablas, sin `tenant_id`, en el paso 1.6. «Insertar un superadministrador en `roles` sería darle un tenant, que es exactamente lo que no es.»

---

## 5. Ámbitos en 1.1: por qué todo es `todos`

Este apartado es una **regla de seguridad**, no una nota de estilo.

El resolutor provisional que rige entre 1.1 y 1.5 (`ADR-034 §2`) **lee `permission_role.effect` e ignora `permission_role.scope`**. Es decir: una concesión con ámbito `propios` se evalúa hoy exactamente igual que una con ámbito `todos`.

Consecuencia: si 1.1 sembrara, por ejemplo, `usuario.leer` con ámbito `propios` para el rol `docente` pensando en «que cada uno vea su ficha», ese docente vería **el censo completo del centro** hasta que 1.5 implementara la resolución de ámbito. Sería un fallo de control de acceso silencioso, activo durante cuatro pasos del plan, y detectable solo leyendo el resolutor.

Reglas derivadas, verificables:

1. **Toda fila de `permission_role` creada en 1.1 lleva `scope = 'todos'`.** Test de esquema: `SELECT count(*) FROM permission_role WHERE scope IS DISTINCT FROM 'todos'` debe ser cero al terminar el aprovisionamiento (`CA-CORE-042`).
2. **El autoservicio no se modela como permiso con ámbito.** `GET /me` y `PATCH /me` se autorizan comprobando que el sujeto es el propio usuario autenticado. Es una comprobación de identidad, no de permiso, y por tanto no depende del resolutor.
3. **1.5 hereda la responsabilidad** de introducir los ámbitos restringidos junto con el resolutor que los evalúa, en el mismo paso. Nunca antes.

---

## 6. Datos de categoría especial

**`REQ-CORE` no expone datos de categoría especial** (salud, NEAE, convivencia). Ninguno de sus permisos lleva `is_special_category = true`.

Matices que sí conviene registrar:

- `people.document_number` y `people.birth_date` son datos personales identificativos, no de categoría especial. Su tratamiento en el registro de auditoría lo cubre la política `Selective` de `ADR-035` (se registra que el atributo cambió, nunca su valor).
- `GET /audit-logs` **no** es un permiso de categoría especial, pero es el permiso más sensible del módulo: por eso solo lo tiene `administrador_centro` (§4.1) y por eso la consulta se limita a lo que ya está almacenado, sin poder recuperar valores redactados.
- La **auditoría reforzada de lectura** de `RPERM-015` (evento `read` en `audit_logs`, ya previsto en el vocabulario de `event`) no se dispara en 1.1 porque no hay lectura de categoría especial que auditar. El mecanismo existe desde 0.9.

---

## 7. Permisos declarados sin endpoint en 1.1

Solo uno, y con motivo:

- **`usuario.exportar`** — `REQ-CORE-003` no pide explícitamente exportar el listado de usuarios, pero `RPERM-003` incluye `exportar` como acción y la primitiva `data_exports` ya existe en este módulo. Se declara el permiso y **no** se implementa el endpoint en 1.1, para que la interfaz de 1.8/1.9 (exportación desde la tabla de datos, `RUX`/TanStack Table) no tenga que añadir un permiso nuevo y ejecutar `platform:sync-registry` en caliente.

Si esto se considera adelantarse, la alternativa es retirarlo del catálogo de 1.1 y que lo declare el paso que implemente el endpoint. Es reversible: añadir un permiso al catálogo es idempotente y sin migración.

**Permisos que 1.1 NO declara y que corresponden a 1.5**: `rol.crear`, `rol.actualizar`, `rol.eliminar`, y cualquier permiso sobre la concesión y revocación de permisos a un rol. Se dejan a 1.5 porque es quien implementa sus endpoints y porque `platform:sync-registry` los añadirá sin migración cuando su código los declare (`ADR-034 §2`).

---

## 8. Reglas de autorización que no son un permiso

Comprobaciones adicionales que ningún permiso cubre y que hay que implementar explícitamente:

| Regla | Dónde |
|-------|-------|
| `RPERM-013` — nadie concede un permiso que no posee | `POST /users` con `role_ids` y `PUT /users/{id}/roles`. Se compara el conjunto de permisos concedidos por los roles destino contra los permisos efectivos del solicitante. Falta alguno ⇒ `403` (`CA-CORE-017`) |
| `RN-CORE-06` — nadie se da de baja ni se cambia los roles a sí mismo | `DELETE /users/{id}`, `POST /users/{id}/status`, `PUT /users/{id}/roles` ⇒ `409` |
| `RN-CORE-07` — siempre al menos un `administrador_centro` vivo y activo | Mismas rutas ⇒ `409` |
| Aislamiento de tenant | RLS más *scope* global (`INV-001`). Un `public_id` de otro tenant ⇒ `404`, nunca `403` |
| Descarga de exportaciones | Solo el usuario que la solicitó, además del permiso (`GET /data-exports/{id}`) |

---

## 9. Verificación

- **`CA-CORE-019`** — un usuario sin permisos recibe `403` en `GET /users`.
- **`CA-CORE-042`** — ninguna fila de `permission_role` con `scope` distinto de `todos` (§5).
- **`CA-CORE-017`** — `RPERM-013` verificado con un caso real (asignar un rol con `auditoria.leer` sin poseerlo).
- **`CA-CORE-070`** — todo endpoint del módulo responde `401` sin sesión y `403` sin permiso.
- **`CA-CORE-073`** — recurso de otro tenant ⇒ `404`.
- Test de catálogo: tras `platform:sync-registry`, la tabla `permissions` contiene exactamente los códigos de §2 con `module_code = 'core'`, y ninguno marcado `retired_at`.
