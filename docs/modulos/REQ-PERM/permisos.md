# REQ-PERM · Permisos

> Paso **1.5**. Este documento es doblemente peculiar y conviene decirlo antes de nada: `REQ-PERM` es **el módulo que define cómo funcionan los permisos** y, a la vez, **tiene permisos propios** que se resuelven con su propia maquinaria.
>
> **`REQ-PERM` no declara un catálogo propio.** No es un *bounded context* (`ADR-044 §4.10`), no tiene `module_code`, y no hay un `PermServiceProvider`. Los cuatro permisos nuevos de este paso los declara **`REQ-CORE`**, que es el dueño del recurso `roles`. Esta tabla es el reflejo documental de lo que `CoreServiceProvider::declaredPermissions()` pasará a declarar; **la fuente de verdad es el código** (`INV-007`, `ADR-034 §2`).

---

## 1. Recursos que este paso añade al catálogo de `REQ-CORE`

| Recurso | Qué representa | ¿Nuevo? |
|---------|----------------|---------|
| `rol` | Rol del centro, predefinido o personalizado | Existe desde 1.1 · **gana dos acciones** |
| `rol_datos_especiales` | El atributo `special_data_access` de un rol, **separado del resto del rol** | **Nuevo** (`ADR-044 §4.4`) |
| `permiso_efectivo` | La resolución de permisos de un usuario concreto, con su procedencia | **Nuevo** (decisión del usuario, 2026-09-04; `funcional.md §18`, `OPEN-PERM-01`) |

Las **acciones** son las de `RPERM-003` sin excepción: `crear`, `leer`, `actualizar`, `eliminar`, `exportar`, `importar`, `aprobar`, `firmar`, `publicar`. **No se inventa ninguna.**

Por eso, y siguiendo el criterio ya establecido en este proyecto (`REQ-CORE/permisos.md §1` con `invitacion.crear` en vez de `usuario.invitar`; `REQ-AUTH/permisos.md §2` con `bloqueo_cuenta.eliminar` en vez de `usuario.desbloquear`; `§C.2` con `mfa.eliminar` en vez de `usuario.restablecer_mfa`):

- **`rol_datos_especiales` es un recurso, no la acción `rol.actualizar_datos_especiales`.** El atributo `special_data_access` necesita permiso propio porque es la llave de una categoría entera de dato (`RPERM-012`), y la única forma de dárselo sin inventar una acción es separarlo como recurso.
- **`permiso_efectivo` es un recurso, no la acción `usuario.leer_permisos`.** Lo que se lee no es el usuario: es una **resolución calculada** sobre él.

---

## 2. Catálogo: los cuatro permisos nuevos

`module_code = 'core'`, `is_special_category = false` en los cuatro (§7).

| `code` | Recurso | Acción | `applicable_scopes` | Endpoints que lo exigen |
|--------|---------|--------|---------------------|--------------------------|
| `rol.crear` | `rol` | `crear` | `['todos']` | `POST /roles` (alta y clonación) |
| `rol.eliminar` | `rol` | `eliminar` | `['todos']` | `DELETE /roles/{public_id}` |
| `rol_datos_especiales.actualizar` | `rol_datos_especiales` | `actualizar` | `['todos']` | `PATCH /roles/{public_id}` con la clave `special_data_access`; `POST /roles` con `special_data_access: true` |
| `permiso_efectivo.leer` | `permiso_efectivo` | `leer` | `['todos']` | `GET /users/{public_id}/effective-permissions` (**no** `GET /me/effective-permissions` — §2.2) |

**Los cuatro con `applicable_scopes: ['todos']`, y no es pereza.** Administrar roles es una capacidad sobre el centro entero: no existe «crear roles de mi departamento» ni «leer los permisos efectivos de mi grupo» mientras no exista departamento ni grupo, y declarar hoy un ámbito que nadie puede resolver produciría exactamente el `422` de `funcional.md §3.4`. Si `REQ-ACAD` (1.11) hiciera que «los permisos efectivos de mi grupo» tuviera sentido, ampliar `applicable_scopes` es aditivo y no requiere migración.

### 2.1 Permisos que **no** se declaran, y por qué

| No se declara | Motivo |
|---------------|--------|
| `rol.exportar` | `REQ-CORE/permisos.md §3` dejó abierto si `RPERM-009` necesitaría exportación. **No la necesita**: la vista previa es una fotografía calculada para diagnosticar, no un artefacto que salga del sistema. Un CSV con la capacidad efectiva de toda la plantilla es un mapa de qué cuenta atacar, ordenado por utilidad — exactamente el argumento con el que `REQ-AUTH/permisos.md §C.6` dejó `mfa` sin `exportar`. Si algún día se pide, es un requisito nuevo con su propio permiso y su propia auditoría de exportación |
| `permiso_efectivo.exportar` | Ídem, con más motivo |
| `permiso.crear` / `.actualizar` / `.eliminar` | El catálogo `permissions` es tabla de referencia con `REVOKE INSERT, UPDATE, DELETE`; sólo lo escribe `platform:sync-registry` como propietario. Declarar esos permisos sugeriría que existe una forma de editarlo, y no la hay. Mismo criterio con el que `auditoria` no tiene `crear` |
| `concesion.crear` / `.eliminar` (recurso propio para `permission_role`) | **Se consideró y se descarta.** Una concesión no es una entidad que el centro administre por sí misma: es parte de la definición del rol, y la API la trata como subcolección (`PUT /roles/{id}/permissions`). Separarla obligaría a comprobar dos permisos para guardar una fila de la matriz, y el día que alguien simplificara quitaría el que no entendiera — el argumento literal de `REQ-AUTH/permisos.md §C.6.1` |
| `asignacion_rol.actualizar` | Una asignación no se edita: se crea o se retira. Ya era así en 1.1 |

### 2.2 Endpoint sin permiso, a propósito y de forma razonada

Uno solo, y entra en la misma casilla que `GET /me` de `REQ-CORE` y que los tres endpoints de `/auth/sessions` de `REQ-AUTH` (`REQ-AUTH/permisos.md §B.1`): **autoservicio autorizado por identidad del portador de la cookie**.

| Endpoint | Por qué no lleva permiso |
|----------|---------------------------|
| `GET /me/effective-permissions` | Devuelve **la resolución del portador de la cookie**, sin ningún parámetro de sujeto. Un usuario tiene que poder saber siempre qué puede hacer: 1.8 lo necesita para pintar su menú sin enlaces muertos (`REQ-CORE-008`), y sin él la SPA sólo podría descubrirlo **provocando `403`**. Modelarlo como permiso permitiría configurarlo a `false`, y un centro que lo hiciera dejaría a su plantilla sin forma de entender su propia interfaz |

Decisión del usuario del 2026-09-04 (`funcional.md §18`, `OPEN-PERM-02`); la forma técnica —ruta propia y no una condición dentro de la ruta de administración— se argumenta en `funcional.md §7.11`.

**Esto no crea `permiso_efectivo.leer` con ámbito `propios`.** Ver §5.3.

---

## 3. `applicable_scopes` de los permisos que ya existen

Este paso recorre el catálogo **completo** de `REQ-CORE` y `REQ-AUTH` y declara el ámbito de cada permiso. La regla de la que se parte es conservadora: **`['todos']` salvo que exista hoy un resolutor que dé sentido a otra cosa**.

### 3.1 `REQ-CORE`

| Permiso | `applicable_scopes` | Motivo si no es sólo `todos` |
|---------|---------------------|-------------------------------|
| `usuario.leer`, `.crear`, `.actualizar`, `.eliminar`, `.importar`, `.exportar` | `['todos']` | El censo del centro no se ve «a medias» con las entidades que existen hoy. `propios` sería el autoservicio, y **el autoservicio no se modela como permiso con ámbito** (`REQ-CORE/permisos.md §5` regla 2, vigente) |
| `invitacion.leer`, `.crear`, `.eliminar` | `['todos']` | |
| `asignacion_rol.leer`, `.crear`, `.eliminar` | `['todos']` | |
| `rol.leer`, `.crear`, `.actualizar`, `.eliminar` | `['todos']` | §2 |
| `rol_datos_especiales.actualizar` | `['todos']` | §2 |
| `permiso_efectivo.leer` | `['todos']` | §2 |
| `permiso.leer` | `['todos']` | Catálogo de plataforma; no hay «mis permisos» que acotar |
| `configuracion.leer`, `.actualizar` | `['todos']` | Hay una sola configuración por centro |
| `modulo.leer`, `.actualizar` | `['todos']` | |
| **`auditoria.leer`** | **`['todos', 'propios']`** | **El caso real de 1.5** (`funcional.md §6`). `propios` = las entradas en las que el sujeto es el actor |
| **`auditoria.exportar`** | **`['todos', 'propios']`** | Mismo recurso, misma restricción. **Declararlo aquí no es opcional**: dejar `exportar` fuera sería el error característico que la *skill* llama «olvidar `exportar`» — se podría conceder lectura acotada y exportación total del mismo dato |

### 3.2 `REQ-AUTH`

Los once permisos del módulo pasan a `['todos']`, sin excepción y sin cambiar nada más de ese módulo:

`bloqueo_cuenta.leer`, `bloqueo_cuenta.eliminar`, `mfa.leer`, `mfa.eliminar`, `exencion_mfa.crear`, `exencion_mfa.leer`, `exencion_mfa.eliminar`, `proveedor_identidad.leer`, `proveedor_identidad.crear`, `proveedor_identidad.actualizar`, `proveedor_identidad.eliminar`.

**Se consideró `propios` para `bloqueo_cuenta.leer` y se descarta**, y merece dejarlo escrito porque parece razonable: «que cada uno vea si está bloqueado». No sirve, por lo que `REQ-AUTH/permisos.md §5.6` ya argumentó — un usuario bloqueado no puede autenticarse, así que nunca llegaría a un endpoint con sesión. El caso de uso no existe.

**Editar el catálogo de otro módulo.** `applicable_scopes` de `REQ-AUTH` lo declara `AuthServiceProvider`, no `REQ-CORE`: cada módulo declara los suyos (`INV-007`, `REQ-AUTH/permisos.md §C.5`). 1.5 toca ese fichero para añadir una clave a once entradas; no mueve ningún permiso de dueño.

---

## 4. Matriz recurso × acción × ámbito

Estado del catálogo **después** de 1.5. Cada celda dice **qué ámbitos admite el permiso**, no qué se concede a quién (eso es §5). `—` significa que el permiso no existe.

### 4.1 `REQ-CORE`

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|-----------|
| `usuario` | `todos` | `todos` | `todos` | `todos` | `todos` | `todos` | — | — | — |
| `invitacion` | `todos` | `todos` | — | `todos` | — | — | — | — | — |
| `asignacion_rol` | `todos` | `todos` | — | `todos` | — | — | — | — | — |
| `rol` | **`todos`** ⬅ 1.5 | `todos` | `todos` | **`todos`** ⬅ 1.5 | — (§2.1) | — | — | — | — |
| `rol_datos_especiales` | — | — | **`todos`** ⬅ 1.5 | — | — | — | — | — | — |
| `permiso_efectivo` | — | **`todos`** ⬅ 1.5 | — | — | — (§2.1) | — | — | — | — |
| `permiso` | — | `todos` | — | — | — | — | — | — | — |
| `configuracion` | — | `todos` | `todos` | — | — | — | — | — | — |
| `modulo` | — | `todos` | `todos` | — | — | — | — | — | — |
| `auditoria` | — | **`todos`, `propios`** ⬅ 1.5 | — | — | **`todos`, `propios`** ⬅ 1.5 | — | — | — | — |

### 4.2 `REQ-AUTH`

| Recurso | crear | leer | actualizar | eliminar |
|---------|-------|------|------------|----------|
| `bloqueo_cuenta` | — | `todos` | — | `todos` |
| `mfa` | — | `todos` | — | `todos` |
| `exencion_mfa` | `todos` | `todos` | — | `todos` |
| `proveedor_identidad` | `todos` | `todos` | `todos` | `todos` |

**Sólo dos celdas de todo el producto admiten un ámbito distinto de `todos` al cerrar 1.5.** Es poco a propósito: `ADR-044 §8` pide **un** resolutor real probado de punta a punta, no un motor con seis ámbitos de mentira. Las celdas que faltan las llenarán `REQ-ACAD` (1.11), `REQ-FAM-UNIT` (1.14) y `REQ-CALIF` (1.16) cuando aporten las entidades sobre las que se resuelven.

---

## 5. Asignación en los roles predefinidos

Los 16 roles de tenant se siembran en `tenant:provision-defaults` (`ProvisionTenantDefaults`). **1.5 no crea, no elimina y no renombra ningún rol**: añade concesiones de los permisos nuevos y **nada más**.

Denegación por defecto (`RPERM-011`): lo que no aparece, no se concede.

| Rol (`code`) | Permisos que **añade** 1.5 | Ámbito |
|--------------|----------------------------|--------|
| `administrador_centro` | `rol.crear`, `rol.eliminar`, `rol_datos_especiales.actualizar`, `permiso_efectivo.leer` | `todos` |
| Los 15 restantes | — | — |

**Los cuatro, a `administrador_centro` y a nadie más.** `rol_datos_especiales.actualizar` incluido, por decisión del usuario del 2026-09-04 (`funcional.md §18`, `OPEN-PERM-07`), que es la que evita un bloqueo sin salida: conceder un permiso exige poseerlo (`RPERM-013`), así que si no lo tuviera nadie, **nadie podría concedérselo a nadie jamás** y ningún centro podría crear un rol personalizado con acceso a categoría especial.

**Y aun teniéndolo, `administrador_centro` no puede usarlo por sí mismo**: le falta `special_data_access`, que sigue en `false` a propósito (§7.4). Puede **delegar** el permiso a un rol que sí tenga el atributo, pero no ejercerlo. **Activar `special_data_access` en un rol exige, por tanto, dos personas distintas**, y las dos operaciones quedan enteras en auditoría. Es una propiedad del diseño, no un efecto colateral: conviene no «arreglarla» dando `special_data_access` al administrador.

Se siembra en `ProvisionTenantDefaults::ADMIN_CENTRO_PERMISSIONS` para los tenants nuevos, y en los existentes lo aplica el comando de migración de datos de `operacion.md §4.3`.

### 5.1 Por qué sólo el Administrador de Centro, una vez más

Es la tercera vez que este proyecto llega a la misma conclusión (`REQ-CORE/permisos.md §4.1`, `REQ-AUTH/permisos.md §5.1` y `§C.7.1`), y aquí el argumento es el más fuerte de los tres:

**Quien administra roles administra todo lo demás.** Un rol con `rol.crear` y `rol.actualizar` puede fabricarse la capacidad que quiera —acotada por `RPERM-013`, que es precisamente lo que impide que se la fabrique **por encima de sí mismo**— y asignarla a quien quiera. Repartir esto es repartir la administración del centro.

Los candidatos habituales se descartan con el mismo razonamiento que ya se aplicó dos veces:

- **`direccion`** tiene `rol.leer` desde 1.1 y ninguna escritura sobre roles. Darle `rol.crear` sería su primera capacidad de escritura sobre la autorización del centro, y llegaría por la puerta de atrás.
- **`secretaria`** y **`administrativo`** son quienes reciben las peticiones de «dale acceso a esto a fulano», y por eso mismo son el objetivo natural de una petición mal comprobada.

**Un centro que quiera repartirlo puede hacerlo desde el primer día de 1.5**, con un rol personalizado, decidido por el propio centro, con nombre y apellidos y con auditoría (§8). Ya no es una promesa aplazada a un paso futuro: **es exactamente lo que este paso entrega**.

### 5.2 `permiso_efectivo.leer`: por qué no lo tiene `direccion`

`GET /users/{public_id}/effective-permissions` devuelve el **mapa completo de lo que otra persona puede hacer** en el centro. Es información de dos naturalezas a la vez:

1. **De gestión**: «¿por qué este profesor no ve esto?» es la pregunta que el endpoint existe para responder.
2. **De ataque**: la lista ordenada de quién tiene las capacidades más peligrosas del centro.

Por la segunda, el permiso es propio y restrictivo, y **no se compone reutilizando `rol.leer` ni `asignacion_rol.leer`** — que hoy tiene `direccion` y que, reutilizados, ampliarían el acceso sin que nadie lo hubiera decidido. Es el argumento literal de `REQ-AUTH/permisos.md §C.6.1`: «un permiso más restrictivo no se puede obtener componiendo dos menos restrictivos».

### 5.3 El autoservicio sigue sin modelarse como permiso con ámbito

Un usuario **sí** puede consultar sus propios permisos efectivos desde 1.5 (decisión del usuario, 2026-09-04). Y **no se hace con `permiso_efectivo.leer` de ámbito `propios`**, sino por **identidad del portador de la cookie** en una ruta propia (`GET /me/effective-permissions`, §2.2), igual que `GET /me`, `DELETE /auth/session` y los tres endpoints de `/auth/sessions` (`REQ-AUTH/permisos.md §B.1`).

La regla 2 de `REQ-CORE/permisos.md §5` —«el autoservicio no se modela como permiso con ámbito»— **sigue en vigor después de 1.5**, aunque su justificación original desaparezca (§9.2). El motivo ya no es que el ámbito no se evalúe; es que **un permiso puede ponerse a `false` y el autoservicio no debe poder desactivarse**.

### 5.4 `soporte_plataforma`

**Sin permisos de este paso**, igual que en 1.1, 1.2, 1.2b, 1.3, 1.3b y 1.4. Un rol del proveedor capaz de crear roles y conceder permisos dentro de cualquier centro sería una llave maestra permanente sobre la autorización de todo el producto. Su acceso real es *impersonation* auditada (`REQ-SUP-003`), y 1.6 le dará lo que necesite por su propia vía y con su propio registro.

### 5.5 `super_administrador`

**No es una fila de `roles`** (`ADR-034 §2`, `REQ-CORE/permisos.md §4.5`). No aparece en ninguna resolución de este motor y la administración de roles de tenant no le llega por aquí (`funcional.md §10`).

---

## 6. `mfa_obligatorio` (`RPERM-014`)

**Sin cambios en la siembra.** `administrador_centro` y `soporte_plataforma` siguen siendo los dos únicos roles con `mfa_required = true`, tal como se sembró en 1.1 y como 1.3 lo hizo efectivo.

Lo que 1.5 aporta es **verificar que el atributo funciona sobre roles que no existían cuando se escribió el mecanismo**:

- Un rol **personalizado** creado con `mfa_required: true` en el alta obliga a sus titulares **exactamente igual** que uno predefinido, con el mismo período de gracia y el mismo muro de sesión restringida (`CA-PERM-060`).
- Se espera que funcione **sin tocar código**: `EloquentMfaPolicy::requiredByRoleCodes()` consulta `where('roles.mfa_required', true)` de forma genérica, sin ninguna lista de códigos de rol escrita a mano. Es la regla 6 de la *skill* `permisos-y-roles` cumplida por construcción desde 1.3.
- **No se supone: se prueba.** Un mecanismo que «debería funcionar» sobre entidades que nunca han existido no está verificado (`INV-015`).

**Recomendación no aplicada, otra vez.** `REQ-CORE/permisos.md §4.2` recomendó a 1.3 marcar `direccion`, `orientador`, `coordinador_bienestar`, `personal_sanitario` y `responsable_economico`. 1.3 no lo hizo y 1.5 **tampoco lo hace**: cambiar la obligatoriedad por defecto de cinco roles no es una decisión del paso que construye el motor de permisos, y activarla sin aviso obligaría a MFA a media plantilla el día del despliegue (`REQ-AUTH/permisos.md §C.7.4` describe lo que costó hacerlo con dos roles).

---

## 7. Datos de categoría especial (`RPERM-012`, `RPERM-015`)

### 7.1 Ninguno de los permisos de este paso es de categoría especial

Los cuatro permisos nuevos llevan `is_special_category = false`, **incluido `rol_datos_especiales.actualizar`**.

Es una distinción que hay que hacer con cuidado porque es contraintuitiva: `is_special_category` marca los permisos que **dan acceso a un dato** de salud, NEAE o convivencia. `rol_datos_especiales.actualizar` no da acceso a ningún dato: **da acceso al interruptor**. Marcarlo como categoría especial lo sometería a la conjunción de `funcional.md §5.1` —sólo contaría desde un rol con `special_data_access`— y produciría un bucle: para poder conceder la llave habría que tener ya la llave.

La protección del interruptor no es `is_special_category`, es **`RPERM-013` aplicado al atributo** (`funcional.md §5.3`): quien lo activa debe tenerlo. Es la regla que sí cierra el agujero, y no depende de marcar nada.

### 7.2 `REQ-PERM` no expone datos de categoría especial

No hay salud, NEAE ni convivencia en `roles`, `role_user`, `permissions` ni `permission_role`. **Este módulo no trata datos de categoría especial: gobierna el acceso a ellos.**

### 7.3 El contrato de auditoría reforzada de lectura queda fijado aquí

`RPERM-015` exige auditoría de **lectura**, no sólo de escritura, sobre datos de categoría especial. `ADR-044 §4.4` fija el contrato en este paso:

> **Todo módulo que exponga un permiso con `is_special_category = true` emite un evento `read` en `audit_logs` en cada lectura de ese dato.**

El mecanismo existe desde 0.9 (`read` está en el `CHECK` de `audit_logs.event` y `changes` va a `NULL` en ese evento). El test de arquitectura que lo hará cumplir es el candidato (2) de `ADR-044 §8`, que recoge `1.7b`/[#163](https://github.com/pirexia/plataforma-educativa/issues/163).

**Y 1.5 no tiene ningún consumidor que auditar.** Ni `REQ-CORE` ni `REQ-AUTH` exponen categoría especial. Se fija el contrato y **no se simula el consumidor**: inventar hoy un recurso de salud para poder probar el mecanismo sería el andamiaje sin consumidor que `ADR-044` reprocha en otros sitios.

Lo que **sí** se prueba en 1.5, y no necesita ningún dato de salud real, es la **inercia** de `funcional.md §5.1`, con un permiso marcado `is_special_category` en el catálogo de test (`CA-PERM-030` a `CA-PERM-032`).

### 7.4 `administrador_centro` sigue con `special_data_access = false`

Sin cambios respecto de `REQ-CORE/permisos.md §4.3`. **Administrar un centro no es tratar datos de salud.**

Consecuencia directa y aceptada: `administrador_centro` **no puede** activar `special_data_access` en ningún rol ni clonar `orientador`, `coordinador_bienestar` o `personal_sanitario` conservando el atributo. Recibirá `403` y `422` respectivamente, y **es el comportamiento correcto**: la alternativa vacía `RPERM-012` de contenido.

**Quién sí puede**: cualquier usuario que tenga a la vez `rol_datos_especiales.actualizar` y un rol con `special_data_access = true`. Por defecto no lo cumple nadie —el administrador tiene el permiso pero no el atributo; `orientador`, `coordinador_bienestar` y `personal_sanitario` tienen el atributo pero no el permiso—, así que **el centro tiene que decidirlo explícitamente**, concediendo el permiso a uno de esos tres roles o a un rol personalizado. Es exactamente el reparto que `OPEN-PERM-07` resolvió el 2026-09-04 (§5): dos personas, dos decisiones, todo en auditoría.

---

## 8. Reglas de autorización que no son un permiso

Ampliación de `REQ-CORE/permisos.md §8`. Son las comprobaciones que **ningún permiso cubre** y que la revisión de seguridad debe recorrer entera.

| Regla | Dónde | Efecto |
|-------|-------|--------|
| **`RPERM-013` con ámbitos** — nadie concede lo que no tiene, comparando pares `(código, ámbito)` con `todos` absorbiendo | `POST /roles`, `PUT /roles/{id}/permissions`, `PUT /users/{id}/roles`, `POST /users` con `role_ids` | `403` con `detail` que nombra el par que lo provoca (`api.md §9.2`) |
| **`RPERM-013` sobre el atributo** — nadie activa `special_data_access` si él mismo no lo tiene | `POST /roles`, `PATCH /roles/{id}` | `403`. Es comprobación de **sujeto**, no de par |
| **Las filas `deny` no están sujetas a `RPERM-013`** | `PUT /roles/{id}/permissions` | Se aceptan siempre. Restringir nunca necesita poseer |
| **Un `deny` nunca se hace inerte** | Resolutor | Aunque su módulo esté desactivado, su permiso retirado, su ámbito sin resolutor o su rol sin `special_data_access` (`funcional.md §4.3`) |
| **La restricción de ámbito se comprueba en listado, detalle y exportación** | Todo recurso con ámbito restringido | Detalle que no la satisface ⇒ **`404`**, nunca `403` (`api.md §9.3`) |
| **Módulo no utilizable ⇒ `403` antes que el permiso** | `EnsureModuleEnabled` | `urn:pge:error:module-disabled`, distinguible de `forbidden` sin analizar texto |
| `RN-CORE-06` *(vigente)* — nadie se cambia los roles a sí mismo | `PUT /users/{id}/roles` | `409` |
| `RN-CORE-07` *(vigente)* — siempre al menos un `administrador_centro` vivo y activo | `DELETE /users/{id}`, `POST /users/{id}/status`, `PUT /users/{id}/roles` | `409` |
| **Un rol `is_system` no se crea, no se elimina, no cambia de `code` ni de `name`** | `POST`/`PATCH`/`DELETE /roles` | `409` o `422` según el caso |
| **Un rol con asignaciones vivas no se elimina** | `DELETE /roles/{id}` | `409` con `params.users_count` |
| Aislamiento de tenant | Todas las rutas | Un `public_id` de otro tenant ⇒ `404`, nunca `403` |

---

## 9. Qué queda desfasado en `docs/modulos/REQ-CORE/permisos.md`

`ADR-044 §8` lo anticipa: «la documentación de `REQ-CORE` queda parcialmente desfasada en cuanto 1.5 se implemente… Es trabajo de cierre del paso, no opcional». Aquí está la lista exacta, para que el cierre no tenga que reconstruirla.

### 9.1 `§2` — catálogo

Añadir las cuatro filas de §2 de este documento, y añadir la columna `applicable_scopes` a la tabla (§3.1).

### 9.2 `§5` — «Ámbitos en 1.1: por qué todo es `todos`» · **se reemplaza, no se borra**

Es el punto más delicado del cierre y `ADR-044 §8` insiste en él: la regla **pierde vigencia**, pero borrarla sin más perdería el motivo por el que se escribió.

| Regla de `§5` | Estado tras 1.5 |
|---------------|-----------------|
| Regla 1 — «toda fila de `permission_role` creada en 1.1 lleva `scope = 'todos'`», con `CA-CORE-042` | **Reemplazada** por `RN-PERM-02`: toda fila lleva un `scope` no nulo **del vocabulario**, garantizado por `CHECK` en el motor y no sólo por test. `CA-CORE-042` queda **absorbido** por `CA-PERM-003`, que es más fuerte |
| Regla 2 — «el autoservicio no se modela como permiso con ámbito» | **Sigue en vigor**, con motivo nuevo (§5.3 de este documento) |
| Regla 3 — «1.5 hereda la responsabilidad de introducir los ámbitos restringidos junto con el resolutor que los evalúa» | **Cumplida.** Es literalmente lo que hace este paso |
| El párrafo que explica **por qué** la regla existía (el resolutor provisional ignora `scope`) | **Se conserva como nota histórica**, marcada como cerrada y con enlace a `ADR-044 §1.2`. Es la razón por la que el vocabulario es cerrado y por la que un ámbito sin resolutor deniega; sin ella, una revisión futura leería el `CHECK` como burocracia |

**Lo mismo aplica a `REQ-AUTH/permisos.md §5.6`, `§B.1` (último párrafo) y `§C.7.6`**, que repiten la misma regla de seguridad tres veces. Las tres pierden vigencia a la vez y las tres deben quedar marcadas como cerradas por 1.5, no borradas.

### 9.3 `§3` — la columna «1.5» de la matriz

`REQ-CORE/permisos.md §3` marca `rol` · crear / actualizar / eliminar con el literal `1.5`. Las tres pasan a `todos`. Y la nota «`rol` no tiene `exportar`: 1.5 decidirá si su vista previa lo necesita» se resuelve: **no lo necesita** (§2.1).

### 9.4 `§7` — permisos declarados sin endpoint

- «Permisos que 1.1 NO declara y que corresponden a 1.5: `rol.crear`, `rol.actualizar`, `rol.eliminar`, y cualquier permiso sobre la concesión y revocación de permisos a un rol» ⇒ **cerrado**. `rol.actualizar` lo declaró 1.3; los otros dos, este paso; y la concesión **no** recibe permiso propio (§2.1).
- `usuario.exportar` sigue declarado sin endpoint. **1.5 no lo cambia**: no es su asunto y sigue esperando a la exportación desde la tabla de datos de 1.9.

### 9.5 `§4.1` — asignación por rol

Añadir a `administrador_centro` los cuatro permisos de §5 de este documento: `rol.crear`, `rol.eliminar`, `rol_datos_especiales.actualizar` y `permiso_efectivo.leer`, todos con ámbito `todos`. Y añadir a la tabla de «endpoints sin permiso» de `REQ-CORE/permisos.md §2` la fila de `GET /me/effective-permissions` (§2.2 de este documento), junto a `GET /me` y `PATCH /me`.

### 9.6 `§9` — verificación

`CA-CORE-042` queda absorbido por `CA-PERM-003`. `CA-CORE-017` (`RPERM-013` con un caso real) sigue vigente y se **amplía** con `CA-PERM-040` a `CA-PERM-044`, que lo prueban con ámbitos.

---

## 10. Traducciones (`INV-009`, `ADR-021`)

Todo lo que este paso añade y es visible por una persona va por el sistema de traducción, en **es-ES, en, de y fr**:

| Qué | Dónde |
|-----|-------|
| Los doce códigos de error de `api.md §9.1` | `lang/{es,en,de,fr}/core.php` (o `validation.php`, según el patrón que siga `ValidationErrorFormatter`) |
| Las dos claves de `detail` de `403` de `api.md §9.2` | Ídem |
| Los seis nombres de ámbito, para la matriz de 1.5b | Clave por ámbito, p. ej. `scopes.todos`, `scopes.propios`… **1.5 las crea aunque no las pinte**: el valor del enumerado (`todos`) es el del dominio del código y **no se traduce** (`ADR-038 §3.2`); lo que se traduce es su etiqueta |
| Los cuatro motivos de inercia de `api.md §7.2` | Ídem, como etiquetas |

**Ningún nombre de rol personalizado se traduce.** `roles.name` es contenido del centro y lleva literal, no `name_key` (`ADR-034 §2`). La limitación —un rol personalizado tiene un solo nombre, no cuatro— está declarada desde 0.8 y este paso no la cambia.

---

## 11. Verificación

Los criterios completos están en `funcional.md §17`. Los que verifican **esta** matriz:

- **Test de catálogo, ampliado**: tras `platform:sync-registry`, `permissions` contiene **exactamente** los códigos de `REQ-CORE` de §4.1 con `module_code = 'core'`, los once de `REQ-AUTH` con `module_code = 'auth'`, ninguno marcado `retired_at`, y **cada uno con su `applicable_scopes`**. Es el mismo test de `REQ-CORE/permisos.md §9` y de `REQ-AUTH/permisos.md §8`: si 1.5 lo hace fallar, alguien ha declarado un permiso que esta especificación dice que no existe.
- **`CA-PERM-003`** — ninguna fila de `permission_role` con `scope` nulo o fuera del vocabulario (sucesor de `CA-CORE-042`).
- **`CA-PERM-091`** — todo endpoint nuevo responde `401` sin sesión y `403` sin permiso (`CA-CORE-070` aplicado a este paso).
- **`CA-PERM-090`** — rol de otro tenant ⇒ `404` (`CA-CORE-073`).
- **`CA-PERM-013`** — un usuario sin `auditoria.leer` recibe `403`, tenga o no ámbito `propios` alguien más (`CA-CORE-019`).
- **Test de siembra**: tras `tenant:provision-defaults`, los 15 roles distintos de `administrador_centro` **no** tienen ninguno de los cuatro permisos nuevos, y `administrador_centro` los tiene los cuatro con ámbito `todos`, `rol_datos_especiales.actualizar` incluido.
- **`CA-PERM-073`/`CA-PERM-074`** — `GET /me/effective-permissions` responde `200` a un usuario **sin ningún permiso**, y `GET /users/{id}/effective-permissions` responde `403` sin `permiso_efectivo.leer` **aunque el `public_id` sea el suyo propio** (§2.2, `funcional.md §7.11`).
- **Test del bloqueo que `OPEN-PERM-07` evita**: `administrador_centro`, recién sembrado, **puede conceder** `rol_datos_especiales.actualizar` a otro rol (tiene el permiso) y **no puede** activar `special_data_access` por sí mismo (le falta el atributo). Los dos lados en el mismo test, porque el valor de la decisión está en que se cumplan a la vez.
- **Test de rol personalizado**: crear por API un rol con `auditoria.leer` de ámbito `propios`, asignarlo, y comprobar el listado y el detalle. **Es el test que demuestra que la regla 6 de la *skill* se cumple**: ninguna parte del motor conoce el código de ese rol, porque no existía cuando se escribió.
