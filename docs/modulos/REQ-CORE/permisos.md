# REQ-CORE · Permisos

> Sección 11 del documento de requisitos (`RPERM-001` a `RPERM-015`) aplicada a este módulo. El **resolutor granular** es el paso 1.5 (`ADR-034 §2`), **ya cerrado** — ver `docs/modulos/REQ-PERM/permisos.md` para la matriz completa post-1.5, la siembra de sus cuatro permisos nuevos y las reglas de ámbito. Lo que se fija aquí es el catálogo de permisos de `REQ-CORE`, ahora con `applicable_scopes` operativo.
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

## 2. Catálogo de permisos que declara `REQ-CORE`

`module_code = 'core'`, `is_special_category = false` en todos (este módulo no expone salud, NEAE ni convivencia — §6). `applicable_scopes` operativo desde 1.5 (`REQ-PERM/permisos.md §3.1`) — columna añadida a la derecha; solo `auditoria.leer`/`.exportar` admiten algo distinto de `todos`.

| `code` | Recurso | Acción | Endpoints que lo exigen | `applicable_scopes` |
|--------|---------|--------|--------------------------|----------------------|
| `usuario.leer` | `usuario` | `leer` | `GET /users`, `GET /users/{id}` | `todos` |
| `usuario.crear` | `usuario` | `crear` | `POST /users` | `todos` |
| `usuario.actualizar` | `usuario` | `actualizar` | `PATCH /users/{id}`, `POST /users/{id}/status` | `todos` |
| `usuario.eliminar` | `usuario` | `eliminar` | `DELETE /users/{id}`, `POST /users/{id}/restore`, `GET /users?include_deleted=true` | `todos` |
| `usuario.importar` | `usuario` | `importar` | `POST /user-imports`, `GET /user-imports`, `GET /user-imports/{id}`, `POST /user-imports/{id}/execute`, `DELETE /user-imports/{id}` | `todos` |
| `usuario.exportar` | `usuario` | `exportar` | Reservado a la exportación del listado de usuarios. **Sin endpoint en 1.1** — ver §7 | `todos` |
| `invitacion.leer` | `invitacion` | `leer` | `GET /invitations` | `todos` |
| `invitacion.crear` | `invitacion` | `crear` | `POST /users/{id}/invitations` | `todos` |
| `invitacion.eliminar` | `invitacion` | `eliminar` | `DELETE /invitations/{id}` | `todos` |
| `asignacion_rol.leer` | `asignacion_rol` | `leer` | `GET /users/{id}/roles` | `todos` |
| `asignacion_rol.crear` | `asignacion_rol` | `crear` | `PUT /users/{id}/roles` (al añadir), `POST /users` con `role_ids` | `todos` |
| `asignacion_rol.eliminar` | `asignacion_rol` | `eliminar` | `PUT /users/{id}/roles` (al retirar) — **comprobación implementada en 1.5**, ver §9 de `REQ-PERM/api.md §8.2` | `todos` |
| `rol.leer` | `rol` | `leer` | `GET /roles`, `GET /roles/{id}` | `todos` |
| `rol.crear` | `rol` | `crear` | `POST /roles` (1.5, `RPERM-005`/`006`) | `todos` |
| `rol.actualizar` | `rol` | `actualizar` | `PATCH /roles/{id}` (1.3, acotado a `mfa_required`; editor completo desde 1.5) | `todos` |
| `rol.eliminar` | `rol` | `eliminar` | `DELETE /roles/{id}` (1.5) | `todos` |
| `rol_datos_especiales.actualizar` | `rol_datos_especiales` | `actualizar` | `PATCH /roles/{id}` con `special_data_access`, `POST /roles` con `special_data_access: true` (1.5) | `todos` |
| `permiso_efectivo.leer` | `permiso_efectivo` | `leer` | `GET /users/{id}/effective-permissions` (1.5, `RPERM-009`) | `todos` |
| `permiso.leer` | `permiso` | `leer` | `GET /permissions` | `todos` |
| `configuracion.leer` | `configuracion` | `leer` | `GET /tenant`, `GET /tenant/settings` | `todos` |
| `configuracion.actualizar` | `configuracion` | `actualizar` | `PATCH /tenant/settings`, `PUT`/`DELETE /tenant/settings/assets/{kind}` | `todos` |
| `modulo.leer` | `modulo` | `leer` | `GET /modules` | `todos` |
| `modulo.actualizar` | `modulo` | `actualizar` | `PATCH /module-subscriptions/{id}` (solo `settings`) | `todos` |
| `auditoria.leer` | `auditoria` | `leer` | `GET /audit-logs` | `todos`, `propios` |
| `auditoria.exportar` | `auditoria` | `exportar` | `POST /audit-logs/exports`, `GET /data-exports/{id}` de tipo `audit_logs` | `todos`, `propios` |

**Endpoints sin permiso, a propósito y de forma auditada:**

| Endpoint | Por qué |
|----------|---------|
| `GET /tenant/branding` | Sin autenticación. La pantalla de login lo necesita antes de que exista sesión (`funcional.md` §4.8). Su superficie está cerrada por contrato y limitada por tasa. |
| `GET /me`, `PATCH /me` | Autorizado **por identidad del sujeto**, no por permiso. Ver §5: la regla de fondo sigue en vigor, con motivo distinto tras 1.5. |
| `GET /me/effective-permissions` | Autoservicio por identidad, igual que `GET /me` (1.5, `REQ-PERM/permisos.md §2.2`). Nunca `403`, solo `401` sin sesión. |

---

## 3. Matriz recurso × acción × ámbito

Estado tras el cierre de 1.5. `—` significa que el permiso no existe en este módulo. Cada celda es el ámbito que **admite** el permiso (`applicable_scopes`), no lo que se concede a quién (eso es §4).

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `usuario` | `todos` | `todos` | `todos` | `todos` | `todos` (§7) | `todos` | — | — | — |
| `invitacion` | `todos` | `todos` | — | `todos` | — | — | — | — | — |
| `asignacion_rol` | `todos` | `todos` | — | `todos` | — | — | — | — | — |
| `rol` | `todos` | `todos` | `todos` | `todos` | — (§9.3) | — | — | — | — |
| `rol_datos_especiales` | — | — | `todos` | — | — | — | — | — | — |
| `permiso_efectivo` | — | `todos` | — | — | — (§9.3) | — | — | — | — |
| `permiso` | — | `todos` | — | — | — | — | — | — | — |
| `configuracion` | — | `todos` | `todos` | — | — | — | — | — | — |
| `modulo` | — | `todos` | `todos` | — | — | — | — | — | — |
| `auditoria` | — | `todos`, `propios` | — | — | `todos`, `propios` | — | — | — | — |

`auditoria` no tiene `crear`, `actualizar` ni `eliminar` **por diseño**: la tabla es *append-only* con `REVOKE UPDATE, DELETE` en el motor (`ADR-034 §3`) y la escribe el *observer*, no un usuario. Declarar esos permisos sería sugerir que existe una forma de editar el registro.

`rol` y `permiso_efectivo` no tienen `exportar`: resuelto en 1.5 (`REQ-PERM/permisos.md §2.1`) — la vista previa de permisos efectivos es una fotografía calculada para diagnosticar, no un artefacto que sale del sistema.

---

## 4. Asignación en los roles predefinidos

La sección 11.1 enumera 17 roles, pero solo **16** se siembran como fila de `roles` en el aprovisionamiento del tenant (`funcional.md` §4.7) con `is_system = true` y `name_key = 'roles.{code}'`: `super_administrador` no es uno de ellos (§4.5). Confirmado por el usuario, issue [#48](https://github.com/pirexia/plataforma-educativa/issues/48).

### 4.1 Permisos de `REQ-CORE` por rol

Denegación por defecto (`RPERM-011`): lo que no aparece, no se concede.

| Rol (`code`) | Permisos de `REQ-CORE` | Ámbito |
|--------------|------------------------|--------|
| `administrador_centro` | **Todos** los de §2, incluidos `rol.crear`, `rol.eliminar`, `rol_datos_especiales.actualizar` y `permiso_efectivo.leer` (1.5, `OPEN-PERM-07`) | `todos` |
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

## 5. Ámbitos: nota histórica (1.1-1.4c) y regla vigente tras 1.5

**Esta sección describía una regla de seguridad que ya se ha cumplido y cerrado.** Se conserva, no se borra (`ADR-044 §8`), porque el motivo por el que existió es exactamente lo que `REQ-PERM`/1.5 vino a cerrar y una revisión futura debe poder leerlo.

### 5.1 Regla histórica, cerrada por 1.5

Entre 1.1 y 1.4c, el resolutor provisional (`ADR-034 §2`) **leía `permission_role.effect` e ignoraba `permission_role.scope`**: una concesión con ámbito `propios` se evaluaba exactamente igual que una con ámbito `todos`. La regla 1 de entonces —«toda fila creada lleva `scope = 'todos'`», verificada por `CA-CORE-042`— existía para que ese ámbito ignorado nunca se usara para nada distinto de `todos` y así no abriera un acceso total en silencio.

`CA-CORE-042` queda **absorbido por `CA-PERM-003`** (`REQ-PERM/permisos.md §9.6`), que es la versión más fuerte de la misma comprobación: ahora garantizada por `CHECK` en el motor (`RN-PERM-02`), no solo por un test sobre lo que sembraba el aprovisionamiento.

### 5.2 Regla vigente: el autoservicio no se modela como permiso con ámbito

**Sigue en vigor después de 1.5**, con un motivo distinto (`REQ-PERM/permisos.md §5.3`): ya no es que el ámbito no se evalúe —`propios` sobre `auditoria` funciona de verdad desde 1.5—, es que **un permiso puede ponerse a `false`**, y un usuario tiene que poder saber siempre qué puede hacer. `GET /me`/`PATCH /me` y, desde 1.5, `GET /me/effective-permissions` se autorizan comprobando identidad, nunca permiso.

### 5.3 Lo que 1.5 entregó

Resolutor completo (`App\Support\Authorization`), vocabulario cerrado de seis ámbitos con `CHECK` en `permission_role.scope`, y un resolutor real —`propios` sobre `auditoria`— probado de punta a punta. Detalle completo en `docs/modulos/REQ-PERM/`.

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

**Cerrado.** `rol.actualizar` lo declaró 1.3; `rol.crear` y `rol.eliminar`, 1.5. La concesión y revocación de permisos a un rol **no recibe permiso propio**: `PUT /roles/{id}/permissions` se gobierna con `rol.actualizar` (`REQ-PERM/permisos.md §2.1`) — separarlo obligaría a comprobar dos permisos para guardar una fila de la matriz.

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
- **`CA-CORE-042`** — **absorbido por `CA-PERM-003`** (§5.1): ninguna fila de `permission_role` con `scope` nulo o fuera del vocabulario, garantizado por `CHECK`.
- **`CA-CORE-017`** — `RPERM-013` verificado con un caso real; **ampliado por `CA-PERM-040`-`044`** (`REQ-PERM/permisos.md §9.6`), que lo prueban comparando pares (código, ámbito).
- **`CA-CORE-070`** — todo endpoint del módulo responde `401` sin sesión y `403` sin permiso.
- **`CA-CORE-073`** — recurso de otro tenant ⇒ `404`.
- Test de catálogo: tras `platform:sync-registry`, la tabla `permissions` contiene exactamente los 25 códigos de §2 con `module_code = 'core'`, ninguno marcado `retired_at`, y cada uno con su `applicable_scopes`.

Los criterios propios de `REQ-PERM` (`CA-PERM-001` a `CA-PERM-093`) están en `docs/modulos/REQ-PERM/funcional.md §17`.
