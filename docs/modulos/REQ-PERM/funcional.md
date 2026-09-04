# REQ-PERM · Núcleo de autorización granular · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-PERM` (sección 11 del documento de requisitos; `RPERM-001` a `RPERM-015`) |
| Prioridad | MUST |
| Fase | 1 · paso **1.5** del plan |
| Depende de | `REQ-CORE` (1.1, cerrado), `REQ-AUTH` (1.2/1.2b/1.3/1.3b/1.4/1.4b/1.4c, cerrados) |
| Estado | **PROPUESTO** — pendiente de aprobación antes de implementar |
| Decisión de arquitectura vinculante | `ADR-044` (ACEPTADA, 2026-09-04) |

> **Todo lo que sigue se deriva de `ADR-044`.** Donde esta especificación añade una decisión que el ADR no fija, se marca con **[DERIVADA]** y se argumenta. Donde falta información que no me corresponde inventar, hay una **pregunta abierta** en §13 y **no** una decisión.

---

## 1. Alcance

### 1.1 Qué entra en 1.5

| Requisito | Qué se entrega en este paso |
|-----------|------------------------------|
| `RPERM-001` | Matriz recurso × acción × ámbito **operativa**: el ámbito deja de ser una columna decorativa y pasa a evaluarse |
| `RPERM-002` | Los recursos siguen siendo los que declara cada módulo. 1.5 no inventa recursos de módulos futuros (`ADR-044 §2`) |
| `RPERM-003` | Acciones cerradas, sin cambios: `crear`, `leer`, `actualizar`, `eliminar`, `exportar`, `importar`, `aprobar`, `firmar`, `publicar` |
| `RPERM-004` | **Vocabulario cerrado de los seis ámbitos** (`enum` de PHP + `CHECK` en base de datos), contrato `ScopeResolver`, y **los resolutores cuyas entidades existen hoy** |
| `RPERM-005` | Creación de roles personalizados **como API** (`POST /roles`) |
| `RPERM-006` | Clonación de roles **como API** (`POST /roles` con `clone_from`) |
| `RPERM-007` | Resolución multi-rol: unión de ámbitos de las concesiones `allow`, con `deny` **ciego al ámbito** que veta el código entero |
| `RPERM-009` | **Dos endpoints**: `GET /users/{public_id}/effective-permissions` (administración, permiso `permiso_efectivo.leer`) y `GET /me/effective-permissions` (autoservicio, por identidad — decisión del usuario 2026-09-04, §7.11), ambos con procedencia y motivo de inercia |
| `RPERM-010` | Auditoría de roles, concesiones y asignaciones (issue [#165](https://github.com/pirexia/plataforma-educativa/issues/165)) |
| `RPERM-011` | Denegación por defecto, ahora también en el ámbito: conjunto vacío ⇒ denegado |
| `RPERM-012` | Categoría especial: conjunción sobre el **rol que concede** |
| `RPERM-013` | «Nadie concede lo que no tiene», ahora con ámbitos: subconjunto con `todos` absorbiendo |
| `RPERM-014` | `mfa_obligatorio` en roles personalizados: se **verifica** que el alta con el atributo dispara lo mismo que el `PATCH` que ya existe |
| `RPERM-015` | `acceso_datos_especiales` con recurso propio `rol_datos_especiales.actualizar`, y **contrato** de auditoría reforzada de lectura |

Además, y fuera de la numeración `RPERM`:

- **Un cambio de esquema, el único**: `permission_role.scope` pasa a `NOT NULL` con `CHECK` sobre los seis valores (`datos.md §3`).
- **El punto 1 del issue [#6](https://github.com/pirexia/plataforma-educativa/issues/6)**: test de arquitectura que impida `runAsPlatform()` fuera de su lista de excepciones (§11).
- **Al menos un resolutor real distinto de `todos`, probado de punta a punta**: `propios` sobre el recurso `auditoria` (§6). Sin él, el contrato no está verificado y `INV-015` no se cumple (`ADR-044 §8`).

### 1.2 Qué NO entra, explícitamente

| Fuera de alcance | Dónde va | Motivo |
|------------------|----------|--------|
| **Interfaz gráfica** de alta/clonación de roles, matriz de concesión y pantalla de permisos efectivos | **1.5b**, después de `1.9` | `ADR-044 §6`/`§10.1`. Construir la tabla más compleja del producto antes del sistema de diseño (`1.7`) y de TanStack Table (`1.9`) garantiza rehacerla |
| `RPERM-008` — permisos condicionales | **1.16** (`REQ-CALIF`) | `ADR-044 §4.5`/`§10.3`. **Sin columna reservada** en el esquema de 1.5: una `conditions jsonb NULL` sin semántica repetiría la trampa que `scope` creó entre 1.1 y 1.5 |
| Herencia viva de roles (`roles.parent_role_id`) | Descartada | `ADR-044 §4.6`. Solo clonación. Añadirla después es una columna anulable; quitarla después de sembrada es migración de datos de autorización |
| Caché de permisos resueltos (Redis o similar) | Descartada | `ADR-044 §4.7`. Su modo de fallo es **conceder lo ya revocado**, la única dirección que `INV-002` no admite. Solo memoización por petición |
| Resolutores de `departamento`, `grupo`, `clase`, `unidad_familiar` | `REQ-ACAD` (1.11) y `REQ-FAM-UNIT` (1.14) | Sus entidades no existen. El vocabulario y el `CHECK` sí los admiten desde hoy |
| Permisos de plataforma (`super_administrador`, `platform_admins`, `admin_action_logs`) | **1.6** (`REQ-BO`) | `ADR-034 §2`, `ADR-044 §2`. 1.5 fija el punto de encaje (§10) y nada más |
| Puntos 2 y 3 del issue [#6](https://github.com/pirexia/plataforma-educativa/issues/6) | **1.6** | Dependen de `platform_admins` y `admin_action_logs`, que no existen hasta ese paso |
| Issue [#44](https://github.com/pirexia/plataforma-educativa/issues/44) (quién activa módulos) e issue [#60](https://github.com/pirexia/plataforma-educativa/issues/60) (prefijo `core.`) | 1.6 y `ADR-038` respectivamente | `ADR-044 §2`. La autorización queda **indiferente** a cómo se resuelva #44 (§9) |

### 1.3 Dependencias no implementadas y cómo se tratan

**Cuatro de los seis ámbitos de `RPERM-004` no tienen entidad sobre la que resolverse.** No hay departamento, grupo, clase ni unidad familiar en el esquema: `REQ-ACAD` es 1.11 y `REQ-FAM-UNIT` es 1.14, ambos posteriores.

Esto **no bloquea** 1.5 porque `ADR-044 §3` eligió la opción B: el vocabulario y el contrato ahora, los resolutores cuando exista la entidad. La consecuencia operativa, que es la regla de seguridad central de este paso:

> **Conceder un permiso con un ámbito sin resolutor registrado responde `422` y no se guarda. Si una fila así llegara a existir por cualquier vía —siembra, migración, escritura directa en base de datos—, el resolutor la trata como inerte y el permiso queda denegado.**

Falla en cerrado por los dos extremos. Es la respuesta directa al fallo que `docs/modulos/REQ-CORE/permisos.md §5` tuvo que contener con una regla y un test: entre 1.1 y hoy, un ámbito que el resolutor ignoraba en silencio convertía una concesión restringida en acceso total.

---

## 2. Actores y roles implicados

| Actor | Qué hace en este módulo |
|-------|--------------------------|
| **Administrador de Centro** (`administrador_centro`) | Único rol predefinido con capacidad de administrar roles y concesiones. Crea, clona, edita y da de baja roles personalizados; concede y revoca permisos; asigna roles a usuarios; consulta permisos efectivos |
| **Cualquier usuario autenticado** | Es **sujeto** de la resolución en cada petición. No opera sobre este módulo |
| **Roles personalizados del centro** | Ciudadanos de primera (`permisos-y-roles`, regla 6). Toda regla de este documento funciona sobre un rol que todavía no existe: **no hay ninguna lista de códigos de rol escrita en el código** |
| **Módulos de negocio (52 restantes)** | Consumidores del contrato: declaran sus permisos con `applicable_scopes` y, si aportan una entidad de ámbito, registran su `ScopeResolver` |
| **`soporte_plataforma`** | Sin permisos de este módulo, igual que en 1.1-1.4c. Su acceso real es *impersonation* auditada (`REQ-SUP-003`) |
| **`super_administrador`** | **No es una fila de `roles`** (`ADR-034 §2`). Fuera de alcance (§1.2) |

---

## 3. Conceptos y contrato

### 3.1 Los seis ámbitos son vocabulario cerrado y central (`ADR-044 §4.1`)

| Ámbito | Significado | ¿Resolutor en 1.5? |
|--------|-------------|--------------------|
| `todos` | Sin restricción de fila dentro del tenant | **No necesita resolutor.** Es la ausencia de restricción |
| `propios` | Las filas en las que el sujeto es el titular o el actor | **Sí** — `auditoria` (§6) |
| `departamento` | Las filas del departamento del sujeto | No. `REQ-ACAD`, 1.11 |
| `grupo` | Las filas del grupo del sujeto | No. `REQ-ACAD`, 1.11 |
| `clase` | Las filas de la clase del sujeto | No. `REQ-ACAD`, 1.11 |
| `unidad_familiar` | Las filas de la unidad familiar del sujeto | No. `REQ-FAM-UNIT`, 1.14 |

**Añadir un séptimo ámbito exige un ADR nuevo.** Se rechaza expresamente que cada módulo registre nombres propios de ámbito: con vocabulario abierto, la vista previa de `RPERM-009` deja de ser explicable, la resolución de conflictos de `RPERM-007` se vuelve indecidible, y 53 módulos producirían 53 sinónimos de «lo mío».

Lo que **sí** es de cada módulo son dos cosas distintas: qué ámbitos admite cada permiso, y cómo se resuelve un ámbito sobre un recurso.

### 3.2 `applicable_scopes`: qué ámbitos admite cada permiso

`DeclaresModuleRegistry::declaredPermissions()` gana la clave `applicable_scopes` por permiso. Ejemplos:

| Permiso | `applicable_scopes` | Nota |
|---------|---------------------|------|
| `usuario.leer` | `['todos']` | El censo del centro no se ve «a medias» con las entidades de hoy |
| `auditoria.leer` | `['todos', 'propios']` | El caso real de 1.5 (§6) |
| `auditoria.exportar` | `['todos', 'propios']` | Mismo recurso, misma restricción |
| `calificacion.actualizar` | `['todos', 'grupo', 'clase']` | **Lo declarará `REQ-CALIF` (1.16)**, no 1.5 |
| `expediente.leer` | `['todos', 'unidad_familiar']` | **Lo declarará su módulo**, no 1.5 |

Reglas:

1. **La omisión de `applicable_scopes` equivale a `['todos']`.** **[DERIVADA]** Es el valor que deja el sistema exactamente como está hoy y el que menos permite: un módulo que no se entere del cambio no gana la capacidad de conceder ámbitos restringidos, solo la conserva de conceder `todos`. Falla en cerrado respecto de la novedad, no respecto del estado previo.
2. **`todos` está siempre implícitamente admitido.** Un permiso que declarara `['propios']` sin `todos` sería un permiso que nadie puede conceder de forma total, lo que no lo pide ningún requisito y complicaría la siembra actual.
3. **Conceder un ámbito no admitido por el permiso responde `422`** y no se guarda (`ADR-044 §4.1`).
4. **Declarar en `applicable_scopes` un ámbito sin resolutor registrado no es un error de despliegue**: el permiso lo admite, pero conceder ese ámbito responde `422` mientras no exista el resolutor (§3.4, regla 3). Así `REQ-CALIF` puede declarar `['todos','grupo','clase']` en 1.16 aunque su resolutor llegue con `REQ-ACAD`, sin que el despliegue reviente.

### 3.3 El resolutor de ámbito **acota consultas**, no devuelve booleanos (`ADR-044 §4.2`)

Es la corrección de fondo sobre el mecanismo actual. Un ámbito no es una respuesta sí/no en la puerta del endpoint: `auditoria.leer` con ámbito `propios` significa que **el listado devuelve menos filas y que el detalle de una fila ajena responde `404`**.

La autorización pasa a tener **dos salidas obligatorias y una sola fuente**:

| # | Salida | Qué responde | Dónde |
|---|--------|--------------|-------|
| 1 | **Puerta** | ¿El sujeto tiene este permiso con **algún** ámbito no denegado? No ⇒ `403` | *Middleware* `RequirePermission` en la ruta. **Semántica sin cambios** |
| 2 | **Acotación** | Qué filas puede ver o tocar | Objeto de valor `PermissionDecision` aplicado a la consulta por la API sancionada |

**Consecuencia que hay que decir en voz alta y que esta especificación no disimula**: el paso 2 **no lo garantiza el framework** como sí garantiza el aislamiento de tenant. `INV-001` se cumple con un *global scope* más RLS porque «este dato es de otro colegio» es una condición uniforme sobre una columna; «este alumno no es de tu grupo» no lo es. Se compensa con tres cosas explícitas y ninguna afirmación tranquilizadora:

1. Una **única API sancionada**. Cualquier otra forma de listar un recurso permisionado es una desviación, no un estilo alternativo.
2. Un **test de arquitectura** que falle si un controlador de módulo consulta un modelo permisionado sin pasar por ella. Es el candidato (3) de `ADR-044 §8`, recogido por `1.7b`/[#163](https://github.com/pirexia/plataforma-educativa/issues/163) — **no se implementa en 1.5**, porque hoy solo hay un recurso con ámbito restringido y el patrón que el test tendría que reconocer aún no ha aparecido en varios módulos.
3. Un **criterio de aceptación por recurso con ámbito restringido**: test de acceso denegado en **listado y en detalle**. Sin los dos, `INV-015` no se cumple.

Esta es la mayor deuda estructural que el paso deja abierta, y queda escrita en lugar de supuesta (`ADR-044 §8`).

### 3.4 El contrato `ScopeResolver`

Vive en `App\Support\Authorization\Contracts`. Lo implementa el **módulo propietario de la entidad**, nunca el núcleo.

**Forma del contrato** (descripción funcional; la firma exacta es trabajo de implementación):

| Elemento | Qué aporta |
|----------|------------|
| El **ámbito** que resuelve | Uno de los seis del `enum`. Un resolutor resuelve exactamente un ámbito |
| El **recurso** sobre el que lo resuelve | El `resource` de `RPERM-002` (`auditoria`, `calificacion`, …) |
| Una **restricción de consulta** | Recibe el constructor de consulta y el sujeto; devuelve el constructor con la restricción aplicada. Es un predicado sobre filas, nada más |

**Tres decisiones de forma, y las tres tienen motivo:**

1. **El registro es por par `(ámbito, recurso)`, no por par `(ámbito, código de permiso)`.** **[DERIVADA]** La restricción depende de qué filas se están consultando, que es propiedad del recurso, no de la acción: `auditoria.leer` y `auditoria.exportar` con ámbito `propios` restringen exactamente las mismas filas. Registrar por código de permiso obligaría a repetir la misma restricción una vez por acción y abriría la puerta a que dos acciones del mismo recurso divergieran, que es precisamente el fallo «verifica en el listado y no en el detalle» trasladado de sitio.

2. **El detalle se comprueba con la misma restricción que el listado, no con un método aparte.** **[DERIVADA, y es una restricción de diseño, no una preferencia]** La comprobación de una fila concreta se hace aplicando la restricción a una consulta acotada a esa fila y viendo si devuelve algo. Un contrato con dos métodos —`constrain()` para listar y `permits()` para el detalle— tendría dos implementaciones por resolutor que **pueden divergir**, y la divergencia es exactamente el IDOR clásico que la *skill* `permisos-y-roles` llama «verificar solo en el listado y no en el detalle». Con un solo predicado, divergir es imposible por construcción.

3. **El núcleo nunca sabe qué es un grupo.** El registro se hace desde el `ServiceProvider` del módulo propietario, contra un registro que expone el núcleo. `INV-007` queda satisfecho en la dirección correcta: el núcleo define interfaz y vocabulario, el módulo implementa, nadie importa código interno de nadie.

**Regla 3 del registro**: conceder un permiso con un ámbito distinto de `todos` para el que **no hay resolutor registrado** responde `422`. Y una fila así, si existiera, **deniega** al resolver. Los dos extremos cerrados.

### 3.5 `PermissionDecision` y la API sancionada de acotación

`PermissionDecision` es un objeto de valor inmutable, resultado de resolver un código de permiso para un sujeto. Contiene:

| Dato | Para qué |
|------|----------|
| Si está permitido | La puerta (`RequirePermission`) |
| El **conjunto** de ámbitos concedidos | La acotación |
| Si el conjunto contiene `todos` | Atajo: sin restricción de fila |
| La **procedencia** de cada concesión y de cada denegación | La vista previa de `RPERM-009` (§7.7) |
| El **motivo de inercia** de las concesiones que no cuentan | Ídem: distinguir «no concedido» de «concedido pero inerte» |

La API sancionada (`ScopedQuery`) hace dos cosas y solo dos:

- **Acotar una consulta**: aplica la unión (`OR`) de las restricciones de los ámbitos del conjunto. Si el conjunto contiene `todos`, no aplica ninguna restricción.
- **Comprobar una fila**: la misma unión, sobre una consulta acotada a esa fila.

> **La vista previa de permisos efectivos usa este mismo código.** Es una restricción de diseño, no una aspiración: si la vista previa tuviera lógica propia, sería una segunda implementación de la autorización y divergiría (`ADR-044 §8`).

---

## 4. Resolución con varios roles (`RPERM-007`)

Un profesor que además es padre de un alumno del centro es el caso real: no puede ver como profesor lo que no le corresponde ni como padre lo que solo ve el claustro.

### 4.1 El algoritmo, en el orden exacto en que se evalúa

Para un sujeto `S` y un código de permiso `C`:

1. **Roles vivos.** Se reúnen los roles de `S` no eliminados, por asignaciones no eliminadas.
2. **Veto por `deny`.** Si existe **una sola** fila `deny` para `C` en **cualquiera** de esos roles y **con cualquier ámbito**, el conjunto queda **vacío** y `C` está denegado. No se evalúa nada más.
3. **Concesiones `allow`.** Se reúnen todas las filas `allow` para `C` en esos roles. Cada una pasa cuatro filtros de inercia y, si falla alguno, **no aporta su ámbito**:

   | Filtro | La concesión es inerte si… | Motivo declarado |
   |--------|---------------------------|------------------|
   | Catálogo | El permiso no existe en `permissions` o está `retired_at` | `inerte_permiso_retirado` |
   | Módulo | El `module_code` del permiso no es utilizable por el tenant (§9) | `inerte_modulo` |
   | Categoría especial | `permissions.is_special_category = true` **y** el rol que concede tiene `special_data_access = false` (§5) | `inerte_datos_especiales` |
   | Resolutor | El ámbito es distinto de `todos` y no hay resolutor registrado para `(ámbito, recurso)` (§3.4) | `inerte_sin_resolutor` |

4. **Unión.** El resultado es el **conjunto unión** de los ámbitos supervivientes.
5. **Absorción.** Si `todos` está en el conjunto, la decisión es sin restricción de fila; los demás ámbitos del conjunto son redundantes y no se aplican.
6. **Conjunto vacío ⇒ denegado** (`RPERM-011`, denegación por defecto).

### 4.2 `deny` es ciego al ámbito, a propósito

`RPERM-007` dice «deny sobrescribe allow» sin matices y se implementa literalmente. La alternativa —`deny(C, grupo)` resta `grupo` pero deja `propios`— exige una retícula de contención entre ámbitos cuyo comportamiento es imposible de explicar a quien administra un colegio y que la vista previa de `RPERM-009` no podría representar.

Un `deny` sirve para decir «este rol no toca calificaciones jamás». Para **estrechar** un acceso ya existe la herramienta natural, que es conceder un ámbito más pequeño.

Y el criterio de reversibilidad rompe el empate: pasar de «ciego al ámbito» a «resta por ámbito» más adelante **abre** permisos, se nota al probar y no necesita migración; el camino inverso **cierra** permisos en centros en producción.

### 4.3 `deny` no se hace inerte nunca **[DERIVADA]**

Los cuatro filtros de inercia de §4.1 punto 3 se aplican **solo a las concesiones `allow`**. Una fila `deny` cuenta siempre: aunque su módulo esté desactivado, aunque el rol que la lleva no tenga `special_data_access`, aunque su ámbito no tenga resolutor y aunque el permiso esté retirado.

Es la lectura obligada de `ADR-044 §7` punto 4 («todos los empates se rompen hacia el fallo en cerrado»). Hacer inerte un `deny` es la única variante de este algoritmo que **abre** un permiso por un efecto lateral, y por tanto la única que no se puede admitir.

### 4.4 El conjunto, no un ganador único

`propios` y `unidad_familiar` no son comparables con `grupo`. Forzar un orden total obligaría a inventar cuál gana entre «los míos» y «los de mi grupo», que no tiene respuesta correcta. La unión de restricciones (`OR`) sí la tiene y es la interpretación más natural de tener dos roles.

### 4.5 Una sola concesión por rol y código **[DERIVADA]**

Dentro de **un mismo rol**, un código de permiso tiene como mucho **una** fila: un `effect` y un `scope`. Es lo que ya impone la unicidad de `permission_role` desde 0.8, y coincide con el modelo de «tres estados por celda» (`allow` / `deny` / nada) con el que `ADR-044 §6` describe la matriz de 1.5b.

**Limitación conocida, y hay que decirla**: un rol no puede conceder a la vez `propios` y `grupo` sobre el mismo código. Un centro que necesite esa combinación la obtiene con **dos roles**, y la unión de §4.1 hace el resto. Si algún día resulta insuficiente, relajar la unicidad es `expand` puro; endurecerla después no lo sería.

### 4.6 Memoización, no caché (`ADR-044 §4.7`)

La resolución se memoiza **por instancia del resolutor**, con el resolutor registrado como `scoped()`: una instancia por petición HTTP, reiniciada entre trabajos de cola. Es exactamente el patrón que `EloquentMfaPolicy` ya usa y que ya está probado en este repositorio.

**No hay caché compartida y no se añadirá en este paso.** El motivo no es el rendimiento: una caché de permisos necesita invalidarse al cambiar un rol, una concesión, una asignación o al borrar un rol, y hacerlo a la vez en varios contenedores de aplicación y en los *workers*. Cuando esa invalidación falla, **falla en abierto**. Si algún día el coste se demuestra, se decidirá **con una medición**, como se hizo con el sobrecoste de RLS en 0.8.12.

---

## 5. Datos de categoría especial (`RPERM-012`, `RPERM-015`)

### 5.1 La regla: conjunción **sobre el rol que concede**

Existen dos piezas y hasta ahora nadie había dicho cómo se combinan: `permissions.is_special_category` (propiedad del permiso, la declara el módulo) y `roles.special_data_access` (atributo del rol).

> Para un permiso con `is_special_category = true`, **solo cuentan las concesiones que vengan de un rol con `special_data_access = true`**. Una concesión de categoría especial hecha desde un rol sin el atributo es **inerte**: no se aplica, y la vista previa la muestra como tal, no como inexistente.

El matiz «sobre el rol que concede» no es cosmético: es el que cierra el agujero. La lectura ingenua —«el usuario tiene algún rol con `special_data_access`»— permitiría que alguien con `orientador` (que sí lo tiene) más un rol personalizado cualquiera que conceda `salud.leer` acabase leyendo salud por la fuerza de un rol que no tiene nada que ver. Es el *confused deputy* clásico y se evita filtrando por concesión, no por usuario.

### 5.2 `special_data_access` no se cambia con `rol.actualizar`

Necesita permiso propio. Como las acciones de `RPERM-003` son cerradas, se declara un **recurso** nuevo: **`rol_datos_especiales`**, con la única acción `actualizar`.

Es el mismo criterio con el que `REQ-CORE/permisos.md §1` convirtió la invitación en recurso (`invitacion.crear`) en vez de inventar la acción `usuario.invitar`, y con el que `REQ-AUTH` modeló el desbloqueo como `bloqueo_cuenta.eliminar`.

### 5.3 `RPERM-013` cubre también el atributo

**Nadie activa `special_data_access` en un rol si él mismo no lo tiene.** «Tenerlo» significa que el solicitante tiene al menos un rol vivo con `special_data_access = true`.

Es una comprobación de sujeto, no de concesión, y es deliberadamente distinta de la de §8: aquí no se está transfiriendo un permiso concreto sino la **llave** de toda una categoría de dato.

### 5.4 Auditoría reforzada de lectura: se fija el contrato, no se simula el consumidor

`RPERM-015` exige auditoría de **lectura**, no solo de escritura, sobre datos de categoría especial. El contrato queda fijado aquí:

> **Todo módulo que exponga un permiso con `is_special_category = true` emite un evento `read` en `audit_logs` en cada lectura de ese dato.**

Y lo verificará el test de arquitectura (2) de `ADR-044 §8`, que recoge `1.7b`.

**Pero 1.5 no tiene ningún recurso de categoría especial que auditar.** `REQ-CORE` no expone ninguno (`REQ-CORE/permisos.md §6`) y `REQ-AUTH` tampoco (`REQ-AUTH/permisos.md §6`). Se fija el contrato y **no se simula el consumidor**: inventar hoy un recurso de salud para poder probar el mecanismo sería exactamente el andamiaje sin consumidor que `ADR-044` reprocha en otros sitios.

Lo que sí entra en 1.5 y sí se prueba es la **inercia** de §5.1, que no necesita ningún dato de salud real: basta un permiso de prueba marcado `is_special_category` en el catálogo de test.

### 5.5 `administrador_centro` sigue con `special_data_access = false`

Se mantiene sin tocar la decisión de `REQ-CORE/permisos.md §4.3`. **Administrar un centro no es tratar datos de salud**, y concederlo por comodidad convertiría la cuenta más usada en la más peligrosa.

Consecuencia directa y que hay que aceptar: `administrador_centro` **no puede** activar `special_data_access` en ningún rol (§5.3) ni clonar `orientador`, `coordinador_bienestar` o `personal_sanitario` conservando el atributo (§7.3). Es el comportamiento correcto; la alternativa vacía `RPERM-012` de contenido.

---

## 6. El resolutor real de 1.5: `propios` sobre `auditoria`

`ADR-044 §8` es explícito: **1.5 debe llevar al menos un resolutor real distinto de `todos`, probado de punta a punta**, o el contrato no está verificado.

### 6.1 Por qué este y no otro

Es el único candidato con datos hoy. `auditoria` es un recurso de `REQ-CORE` con dos permisos (`auditoria.leer`, `auditoria.exportar`), un listado real (`GET /audit-logs`), un detalle implícito (el filtro por `auditable_id`) y una exportación (`POST /audit-logs/exports`). Y `audit_logs.actor_user_id` es exactamente la columna que define «lo mío» sin necesidad de ninguna entidad académica.

### 6.2 Qué significa `propios` sobre `auditoria`

> Un rol con `auditoria.leer` de ámbito `propios` ve **únicamente las entradas de auditoría en las que él es el actor**. No ve las de nadie más.

Es útil de verdad, no un ejemplo de laboratorio: es exactamente lo que un centro querría conceder a Dirección o a Secretaría para que puedan revisar su propio rastro sin obtener el mapa completo de la actividad de todo el personal, que es el motivo por el que `REQ-CORE/permisos.md §4.1` dejó `auditoria.leer` solo en `administrador_centro`.

### 6.3 Lo que este resolutor NO cambia

- **No se concede a ningún rol predefinido en 1.5.** Se declara `applicable_scopes: ['todos','propios']` en `auditoria.leer` y `auditoria.exportar`, se registra el resolutor, y se prueba. La decisión de repartirlo es del centro, con un rol personalizado, exactamente como argumentaron `REQ-AUTH/permisos.md §5.1` y `§C.7.1`.
- **No convierte `GET /audit-logs` en autoservicio.** Un usuario sin `auditoria.leer` sigue recibiendo `403`. `propios` acota a quien ya tiene el permiso; no lo concede a nadie.
- **La exportación se acota igual.** `POST /audit-logs/exports` de un rol con ámbito `propios` genera un artefacto que contiene **solo** sus filas, y el filtro se aplica dentro del trabajo en cola, no solo en la petición. Es el error característico que la *skill* llama «olvidar `exportar`».

---

## 7. Flujos principales

Todos son **API** (`INV-006`). Las rutas y los cuerpos están en `api.md`.

### 7.1 Autorizar una petición (la puerta)

1. Se resuelve el tenant por host (`ADR-033 §2`). Sin tenant ⇒ `404`.
2. Sin sesión ⇒ `401`.
3. **Módulo desactivado ⇒ `403` `urn:pge:error:module-disabled`** (§9). Se comprueba **antes** que el permiso.
4. Se resuelve el permiso declarado en la ruta (§4.1). Denegado ⇒ `403` `urn:pge:error:forbidden`.
5. Permitido: la petición continúa, con su `PermissionDecision` disponible para la acotación.

### 7.2 Listar un recurso con ámbito restringido

1. Todo lo de §7.1.
2. El controlador construye su consulta y la pasa por la API sancionada con su `PermissionDecision`.
3. Si el conjunto contiene `todos`, no se aplica restricción de fila.
4. Si no, se aplica la **unión** (`OR`) de las restricciones de sus ámbitos.
5. El resultado se pagina según `ADR-038 §4`.

### 7.3 Leer el detalle de una fila con ámbito restringido

1. Todo lo de §7.1.
2. Se comprueba la fila con **la misma** restricción (§3.4, decisión 2).
3. Si no la satisface ⇒ **`404`**, nunca `403`. `403` significa «existe pero no puedes» y convertiría el endpoint en un oráculo de filas ajenas. Extiende dentro del tenant lo que `ADR-038 §6.4` fija entre tenants y lo que `REQ-AUTH/permisos.md §B.4` ya aplicó a las sesiones.

### 7.4 Crear un rol personalizado (`RPERM-005`)

1. Permiso `rol.crear`.
2. Se valida el nombre (literal del centro, no clave de traducción: `ADR-034 §2`) y el `code`, único vivo por tenant.
3. `is_system` es **siempre `false`**; no es un campo del cuerpo.
4. `mfa_required` opcional. Ponerlo a `true` **no exige permiso adicional**: es una restricción, no una escalada. **[DERIVADA]**
5. `special_data_access` opcional. Ponerlo a `true` exige **además** `rol_datos_especiales.actualizar` **y** que el solicitante lo tenga (§5.3). Si no, `422`.
6. Se admite un conjunto inicial de concesiones en el mismo alta, sujeto entero a `RPERM-013` (§8).
7. Auditoría: `created` sobre `Role` (ya funciona) y `created` sobre cada `PermissionRole` (§12).

**`RPERM-014`, verificación explícita**: un rol personalizado recién creado con `mfa_required = true` debe **obligar a MFA a sus titulares exactamente igual** que si el atributo se hubiera puesto con el `PATCH` que existe desde 1.3. `EloquentMfaPolicy::requiredByRoleCodes()` ya lo consulta genéricamente (`where('roles.mfa_required', true)`), sin lista de códigos escrita a mano, así que se espera que funcione sin tocar código. **No se supone: se prueba** (`CA-PERM-060`).

### 7.5 Clonar un rol (`RPERM-006`)

Copia en el momento del alta. **No hay herencia viva** (`ADR-044 §4.6`): el rol clonado queda desde ese instante desligado del origen y editarlo no afecta al otro.

1. Permiso `rol.crear`.
2. Se copian: las concesiones (`permission_role`) y `mfa_required`.
3. **No** se copian: `code`, `name`, `is_system` (siempre `false`), ni las asignaciones a usuarios.
4. `special_data_access` se copia **solo si el solicitante puede activarlo** (§5.3 y `rol_datos_especiales.actualizar`). Si no puede, la clonación responde **`422`** y no se guarda. **[DERIVADA]** Degradar el atributo a `false` en silencio dejaría un rol con nombre de `orientador` y sin acceso a lo que su nombre promete, que es peor que un error explícito; y `CLAUDE.md §5` prohíbe arreglar cosas en silencio.
5. Todas las concesiones copiadas pasan por `RPERM-013` (§8). Clonar un rol con más permisos de los que uno tiene responde `403`.
6. Se puede clonar un rol `is_system`; lo que no se puede es crear otro rol `is_system`.

### 7.6 Editar un rol

`PATCH /roles/{public_id}`, la **misma ruta y el mismo permiso** que 1.3 dejó acotados a `mfa_required` (`ADR-044 §4.10`). 1.5 abre el resto de claves:

| Clave | Permiso | Nota |
|-------|---------|------|
| `name` | `rol.actualizar` | Solo en roles con `is_system = false`. Un rol de sistema lleva `name_key` traducida (`INV-009`) |
| `mfa_required` | `rol.actualizar` | Ya funcionaba desde 1.3, sin cambios de semántica |
| `special_data_access` | **`rol_datos_especiales.actualizar`** + posesión (§5.3) | Enviarlo con solo `rol.actualizar` ⇒ `403` |
| `code` | — | **No editable.** Es la referencia estable del rol; cambiarlo rompería la siembra y las referencias de despliegue |

Semántica de `PATCH` según `ADR-038 §9.2`, sin excepciones.

### 7.7 Conceder y revocar permisos a un rol

Los tres estados por celda de la matriz: `allow`, `deny`, y ninguno.

1. Permiso `rol.actualizar`.
2. Cada entrada lleva `code`, `effect` y `scope`. **`scope` es obligatorio**; no hay valor por defecto (§`datos.md` §3.3).
3. Validación, en este orden:
   - El `code` existe en `permissions` y no está `retired_at` ⇒ si no, `422`.
   - El `scope` está en `applicable_scopes` del permiso ⇒ si no, `422`.
   - El `scope` es `todos` o tiene resolutor registrado ⇒ si no, `422` (§3.4, regla 3).
   - `RPERM-013` (§8) ⇒ si no, `403`.
4. Auditoría: `created`, `updated` o `deleted` sobre `PermissionRole` (§12).

**Un `deny` no está sujeto a `RPERM-013`.** **[DERIVADA]** Denegar es restringir, y nadie necesita poseer un permiso para prohibírselo a otro. Someter el `deny` a la misma comprobación impediría a un administrador cerrar una capacidad que él mismo no tiene, que es justo al revés de lo que el requisito busca.

### 7.8 Asignar roles a un usuario

`PUT /users/{public_id}/roles`, ruta y semántica ya existentes desde 1.1. 1.5 no la cambia salvo en dos cosas:

1. `RPERM-013` pasa a comparar **pares (código, conjunto de ámbitos)**, no solo códigos (§8).
2. **La operación deja registro de auditoría explícito con estado anterior y posterior** (§12.2). Hoy no lo deja: es el issue [#165](https://github.com/pirexia/plataforma-educativa/issues/165).

Siguen vigentes sin cambios `RN-CORE-06` (nadie se cambia los roles a sí mismo ⇒ `409`) y `RN-CORE-07` (siempre al menos un `administrador_centro` vivo y activo ⇒ `409`).

### 7.9 Dar de baja un rol

1. Permiso `rol.eliminar`.
2. **Un rol `is_system` no se puede eliminar** ⇒ `409`. Los 16 roles predefinidos son parte del aprovisionamiento del tenant.
3. **Un rol con asignaciones vivas no se puede eliminar** ⇒ `409`, con el recuento de usuarios afectados. **[DERIVADA]** Arrastrar la baja hasta las asignaciones cambiaría en silencio lo que pueden hacer varias personas a la vez, que es exactamente la clase de efecto que `RPERM-010` existe para poder reconstruir. Se obliga a reasignar primero, de forma explícita y auditada.
4. Borrado **lógico** (`INV-004`).
5. Sus concesiones (`permission_role`) se dan de baja con él, y cada baja se audita.

### 7.10 Consultar los permisos efectivos de un usuario (`RPERM-009`)

`GET /users/{public_id}/effective-permissions`. Devuelve, **calculado con el mismo código que la aplicación real**, para cada código de permiso del catálogo utilizable:

| Campo | Contenido |
|-------|-----------|
| Decisión | `permitido` / `denegado` |
| Conjunto de ámbitos | Vacío si denegado |
| **Procedencia** | Qué rol o roles aportan cada concesión y cada denegación, con su ámbito |
| **Motivo de inercia** | Para cada concesión que existe pero no cuenta: `inerte_permiso_retirado`, `inerte_modulo`, `inerte_datos_especiales`, `inerte_sin_resolutor` |

La distinción entre **«no concedido»** e **«concedido pero inerte»** es el valor entero de este endpoint: sin ella, un administrador que concede `salud.leer` desde un rol sin `special_data_access` ve el permiso en la matriz, no ve efecto, y no tiene forma de saber por qué.

El permiso que lo protege es **`permiso_efectivo.leer`**, concedido sólo a `administrador_centro` por defecto (decisión del usuario, 2026-09-04; `§18`, `OPEN-PERM-01`).

### 7.11 Consultar los permisos efectivos propios (autoservicio)

`GET /me/effective-permissions`. **Ruta propia, sin permiso, autorizada por identidad del portador de la cookie.**

> **Decisión del usuario (2026-09-04)**: el autoservicio entra en 1.5. La forma técnica quedó delegada en esta especificación (`§18`, `OPEN-PERM-02`).

**Por qué existe.** 1.8 (dashboards por rol) tiene que pintar el menú de cada usuario sin enlaces muertos, y `REQ-CORE-008` lo pide literalmente: «el dashboard muestra únicamente opciones, módulos y acciones permitidas para su rol». Sin esto, la SPA sólo puede descubrir lo que puede hacer **provocando `403`**, que es una forma pésima de construir una interfaz y además contamina los registros de seguridad con denegaciones que no son incidentes.

**Por qué una ruta propia y no una condición dentro de la ruta de administración.** La decisión invoca el patrón de `GET /me` y de `/auth/sessions`, y **en los dos casos ese patrón es una ruta separada**, no una rama condicional dentro de un endpoint de administración:

1. **Una ruta cuyo `permission:` a veces aplica y a veces no es un control que un refactor rompe en silencio.** Con dos rutas, la autorización de cada una es **estática**: `/me/effective-permissions` no lleva `permission:` y nunca lo llevará; `/users/{public_id}/effective-permissions` lo lleva siempre y sin excepción. Ninguna revisión tiene que razonar sobre cuándo se aplica.
2. **`permisos.md` puede declararlo como lo que es.** Entra en la tabla de «endpoints sin permiso, a propósito y de forma razonada» junto a `GET /me` y a los tres de `/auth/sessions`, en lugar de convertir una fila de la matriz en una nota al pie.
3. **No inventa un ámbito.** El autoservicio **no** se modela como `permiso_efectivo.leer` con ámbito `propios`. La regla 2 de `REQ-CORE/permisos.md §5` sigue en vigor después de 1.5, con motivo nuevo: un permiso puede ponerse a `false`, y **un usuario tiene que poder saber siempre qué puede hacer**. Un centro que desactivara eso dejaría a su plantilla sin forma de entender su propia interfaz.

**Consecuencia menor y documentada**: un usuario sin `permiso_efectivo.leer` que llame a `/users/{su_propio_public_id}/effective-permissions` recibe `403`, no su propia respuesta. Es coherente —esa ruta es de administración— y la ruta de autoservicio está a un carácter de distancia. Es exactamente lo que ocurre hoy con `GET /me` frente a `GET /users/{propio_id}`.

**Cuerpo y semántica idénticos** a los de §7.10, calculados con el mismo código: mismos campos, misma procedencia, mismos motivos de inercia. Un usuario ve **su propia** procedencia, lo que es información sobre sí mismo y sobre roles del centro que su propio menú ya delata.

---

## 8. `RPERM-013` con ámbitos (`ADR-044 §4.8`)

`REQ-CORE/permisos.md §8` ya implementa «nadie concede lo que no tiene» comparando **códigos**. Con ámbitos, la comparación es de pares:

> El conjunto de ámbitos que se concede debe ser **subconjunto** del que posee el otorgante para ese código, con **una única regla de absorción: quien posee `todos` posee cualquier ámbito**.

Es una relación de orden parcial con una sola excepción, no una retícula: se explica en una frase y se puede probar. Cualquier otra contención (¿`departamento` incluye `grupo`?) exigiría saber qué es un departamento, que es precisamente lo que el núcleo no debe saber (§3.1).

**Dónde se aplica**, sin excepciones:

| Operación | Qué se compara |
|-----------|----------------|
| `POST /roles` con concesiones | Cada `(code, scope)` del alta contra lo efectivo del solicitante |
| `POST /roles` con `clone_from` | Cada `(code, scope)` del rol origen |
| `PUT`/`PATCH` de concesiones de un rol | Cada `(code, scope)` que se **añade o amplía**. Retirar no exige nada |
| `PUT /users/{id}/roles` | La unión de lo que confieren los roles que se **añaden** |
| `POST /users` con `role_ids` | Ídem |
| `special_data_access = true` | El solicitante debe tenerlo (§5.3). Regla de sujeto, no de par |

**No se aplica** a las filas `deny` (§7.7).

**Qué es «lo efectivo del solicitante»**: el resultado de §4.1 sobre el propio solicitante, con las mismas inercias. Un administrador cuyo `salud.leer` es inerte por §5.1 **no puede concederlo**, que es la respuesta correcta y la que hace que el agujero del *confused deputy* no se pueda abrir tampoco por esta vía.

---

## 9. Módulo desactivado (`RMOD-009`)

Se comprueba **antes** de resolver permisos, y un permiso cuyo `module_code` no sea utilizable por el tenant **es inerte**: no concede aunque esté concedido, y la vista previa lo muestra como inerte por módulo, no como no concedido.

**Esto no requiere resolver el issue [#44](https://github.com/pirexia/plataforma-educativa/issues/44).** El resolutor consume un **único booleano** —«¿este tenant puede usar este módulo ahora?»— a través de una interfaz que posee `REQ-CORE`. Que detrás haya un booleano o los dos estados (contratado por la plataforma / habilitado por el centro) que #44 acabe decidiendo **no cambia una línea** del núcleo de autorización. Queda escrito para que 1.6 no crea que tiene que tocar permisos.

`REQ-CORE` y `REQ-AUTH` no son desactivables, así que en 1.5 este filtro no cambia el resultado de ninguna resolución real. Se implementa y se prueba igualmente, con un módulo de prueba desactivado, porque el primer módulo desactivable llega en 1.11 y para entonces el mecanismo tiene que existir.

---

## 10. Punto de encaje de los permisos de plataforma

1.5 **no decide** los permisos de plataforma y **no crea** ninguna tabla suya. Lo único que fija es que **no se mezclan**:

- El `super_administrador` **no es una fila de `roles`** y no aparece en ninguna resolución de este motor (`ADR-034 §2`).
- La resolución de este documento opera **siempre** dentro de un tenant resuelto. Sin tenant no hay decisión posible, y eso es lo correcto.
- Cuando 1.6 cree `platform_admins` y `admin_action_logs`, su autorización será **otro mecanismo**, con su propio registro. Reutilizar este sería darle un tenant al superadministrador, que es exactamente lo que no es.

---

## 11. Test de arquitectura: `runAsPlatform()` (issue [#6](https://github.com/pirexia/plataforma-educativa/issues/6), punto 1)

`TenantContext::runAsPlatform()` es, según `ADR-033 §4`, «la única puerta sancionada para leer entre tenants desde código de negocio», y **no tiene ninguna salvaguarda verificable**. `withoutGlobalScope(TenantScope::class)` sí la tiene desde 0.7 (`IsolationBatteryTest`, test #9): un test de arquitectura que rompe el build si aparece fuera de una lista de excepciones.

1.5 replica ese mecanismo, no lo reinventa:

1. Test que **falla** si `runAsPlatform` aparece en cualquier fichero de `apps/api/app/` fuera de una **lista de excepciones explícita, enumerada fichero a fichero** en el propio test.
2. La lista se construye con los usos legítimos, **verificados uno a uno**, no con un patrón de carpeta. Inventario verificado el 2026-09-04 sobre la rama de este paso:

   | Fichero | Uso | ¿Legítimo? |
   |---------|-----|------------|
   | `app/Support/Tenancy/TenantContext.php` | La definición | Sí, evidentemente |
   | `app/Support/Tenancy/RunsPerTenant.php` | Listar los tenants activos para comandos por tenant | Sí. Es el uso que el propio issue #6 reconoce |
   | `app/Modules/Core/Infrastructure/Jobs/PurgeExpiredIdempotencyKeys.php` | Purga de claves vencidas en una sola pasada | Sí, pero **es un uso desde código de módulo**, que es exactamente el patrón que el issue temía. Se admite porque es una purga de mantenimiento sin sujeto y sin salida de datos; queda enumerado para que se vea, no escondido tras una regla de carpeta |

   **Son tres, no dos.** El issue #6 se escribió en 0.7 cuando `app/Modules/` estaba vacío y afirmaba que sólo había dos apariciones; el tercer uso llegó después. Es la mejor prueba de que el test hace falta. Los tres usos son de **lectura**; ninguno escribe entre tenants. Hay además tres apariciones en `tests/`, que quedan fuera del ámbito del test (sólo mira `apps/api/app/`).

3. Añadir un uso nuevo obliga a editar el test, lo que fuerza una decisión consciente y hace que aparezca en la revisión.

> **Corrección de un dato de `ADR-044 §1.1`**: el ADR afirma «cero tests de arquitectura (`arch()`) en el repositorio». No es exacto: existe `apps/api/tests/Feature/Auth/PasswordResetTokenArchitectureTest.php`, que usa `arch()` de Pest (`^4.7`). No cambia ninguna decisión del ADR —sigue siendo cierto que el mecanismo está casi sin usar— pero conviene que 1.5 y `1.7b`/[#163](https://github.com/pirexia/plataforma-educativa/issues/163) partan del dato correcto: **hay un precedente de forma que copiar**, no hay que inventarla.

Los puntos 2 y 3 del issue (comprobación de permiso de plataforma y registro en `admin_action_logs`) **no se pueden hacer en 1.5**: ninguna de las dos tablas existe hasta 1.6. **El issue debe reetiquetarse a 1.6 al cerrar su punto 1**, no cerrarse.

---

## 12. Auditoría de roles, concesiones y asignaciones (`RPERM-010`, issue [#165](https://github.com/pirexia/plataforma-educativa/issues/165))

Hoy **conceder un permiso a un rol y asignar un rol a un usuario no dejan rastro en `audit_logs`**. Es un incumplimiento vivo de `INV-003` y de `RPERM-010` sobre la escritura más sensible del sistema —la que cambia lo que una persona puede hacer dentro del centro— y **1.5 lo cierra**.

### 12.1 `permission_role`: por *observer*, con una condición

`PermissionRole` pasa a implementar `Auditable` con política `AuditValuePolicy::Full` (un código de permiso y un código de rol no son datos personales, `ADR-035`).

Tres cosas que hay que hacer y no suponer:

1. **`created` debe registrarse.** Una concesión de permiso **es** una creación. `ADR-040` excluyó `created` de forma declarativa **solo** para `UserSession`, y su test de arquitectura (`ADR-040 §4.4`) fija que esa es la única exclusión del repositorio. `PermissionRole` **no declara ninguna exclusión**, y ese test debe seguir pasando sin tocarlo.
2. **`Full` está sujeto a registro explícito con test** (`ADR-035 §2`): el conjunto de modelos que declaran `Full` se comprueba contra una lista fija en el test de arquitectura. Añadir `PermissionRole` **obliga a editar ese test**, que es el efecto buscado: la decisión aparece en la revisión.
3. **Las concesiones se escriben por el modelo, nunca por la relación.** `attach()`, `detach()` y `sync()` sobre una relación `belongsToMany` **no disparan eventos de modelo**: usarlas dejaría a `PermissionRole` auditable sobre el papel y mudo en la práctica, que es exactamente el estado del que venimos. Toda escritura de `permission_role` pasa por el modelo (`create` / `update` / `delete`), y hay test que lo demuestra escribiendo por la ruta de la API y comprobando la fila de `audit_logs`.

### 12.2 `role_user`: registro explícito, porque el *observer* no puede

`UserRolesController::replace()` escribe con `$user->roles()->sync($newRoleIds)`, y `sync()` no dispara eventos de modelo. **No es un descuido que se pueda arreglar cambiando una política**: el mecanismo de `ADR-035` no llega ahí.

La asignación de roles se audita con **registro explícito**, con estado anterior y posterior y sin excepciones:

| Aspecto | Decisión |
|---------|----------|
| Sujeto auditado | El **usuario** cuyos roles cambian (`auditable_type = 'user'`) |
| Evento | `updated` — **verificado en el código el 2026-09-04** (`§18`, `OPEN-PERM-03`): ni `AuditRecorder::record()` ni `RecordsAuditTrail` restringen el valor de `event` en el camino manual; la única restricción es el `CHECK` de base de datos, que incluye `updated` desde el origen. **No se añade ningún valor nuevo al vocabulario** y no hace falta ADR |
| `changes` | Una entrada `roles` con `from` y `to`, ambos como **listas de códigos de rol** ordenadas |
| Redacción | Ninguna: un código de rol no es un dato personal |
| Cuándo | **Solo si hay cambio efectivo.** `ADR-038 §9.3` ya lo exige para el `PUT` de colección: enviar dos veces el mismo conjunto no genera una segunda fila |
| Dónde | En el servicio que realiza el cambio, dentro de la misma transacción que el `sync()` |

**Dos matices que la implementación no puede saltarse:**

1. **El estado anterior se lee antes del `sync()`**, no se reconstruye después. Reconstruirlo a partir del cuerpo de la petición registraría lo que el cliente pidió, no lo que había.
2. **`'roles'` tiene que añadirse a la lista de inclusión de auditoría de `User`.** `User` declara política `Selective` con la lista `['status', 'email_verified_at', 'deleted_at', 'created_by', 'updated_by']`, y `AuditChangeBuilder` **redacta como `identifier` todo lo que no esté en ella** (fallo en cerrado, `ADR-035 §2`). Sin este cambio, la fila quedaría como `{"roles": {"redacted": "identifier"}}`: auditable sobre el papel e **inútil en la práctica**, que es exactamente el estado del que este paso viene. El detalle completo y su justificación están en `datos.md §5.3.1`.

### 12.3 Qué queda cubierto al cerrar el paso

| Operación | Registro |
|-----------|----------|
| Crear un rol | `created` sobre `Role` — **ya funciona** desde 0.9 |
| Clonar un rol | `created` sobre `Role` más un `created` por concesión copiada |
| Editar un rol (`name`, `mfa_required`, `special_data_access`) | `updated` sobre `Role` — ya funciona |
| Dar de baja un rol | `deleted` sobre `Role` más un `deleted` por concesión |
| Conceder un permiso | `created` sobre `PermissionRole` — **nuevo** |
| Cambiar el efecto o el ámbito de una concesión | `updated` sobre `PermissionRole` — **nuevo** |
| Revocar un permiso | `deleted` sobre `PermissionRole` — **nuevo** |
| Cambiar los roles de un usuario | `updated` sobre `User` con `roles: {from, to}` — **nuevo** |

### 12.4 La tabla de `ADR-035 §8` queda desfasada

`ADR-035 §8` enumera los modelos auditables y su política. `PermissionRole` no está. **Un ADR es inmutable y no se edita** (`CLAUDE.md §11`): la actualización se hace en `docs/modulos/REQ-PERM/datos.md §5` y en `docs/modulos/REQ-CORE/datos.md` (que ya reproduce esa tabla como reflejo documental), no tocando el ADR.

---

## 13. Reglas de negocio

Numeradas y verificables. Las que ya existen en otros módulos se citan, no se reescriben.

| ID | Regla |
|----|-------|
| `RN-PERM-01` | El vocabulario de ámbitos es cerrado: `todos`, `propios`, `departamento`, `grupo`, `clase`, `unidad_familiar`. Un séptimo exige ADR nuevo |
| `RN-PERM-02` | Toda fila de `permission_role` tiene `scope` no nulo y dentro del vocabulario, garantizado por `CHECK` en el motor y no solo por código |
| `RN-PERM-03` | Conceder un ámbito no incluido en los `applicable_scopes` del permiso ⇒ `422` |
| `RN-PERM-04` | Conceder un ámbito distinto de `todos` sin resolutor registrado para `(ámbito, recurso)` ⇒ `422` |
| `RN-PERM-05` | Una fila con ámbito sin resolutor, si existiera, **deniega** al resolver. Nunca se ignora en silencio |
| `RN-PERM-06` | Una sola fila `deny` para un código, en cualquier rol y con cualquier ámbito, vacía el conjunto de ese código |
| `RN-PERM-07` | Los filtros de inercia se aplican solo a las concesiones `allow`. Un `deny` nunca se hace inerte |
| `RN-PERM-08` | Conjunto de ámbitos vacío ⇒ denegado (`RPERM-011`) |
| `RN-PERM-09` | `todos` en el conjunto absorbe: no se aplica ninguna restricción de fila |
| `RN-PERM-10` | Una concesión de un permiso `is_special_category` desde un rol con `special_data_access = false` es inerte |
| `RN-PERM-11` | `special_data_access` solo se cambia con `rol_datos_especiales.actualizar` y solo por quien lo tiene |
| `RN-PERM-12` | Se concede `(code, scope)` solo si el conjunto concedido es subconjunto del propio, con `todos` absorbiendo |
| `RN-PERM-13` | Las filas `deny` no están sujetas a `RN-PERM-12` |
| `RN-PERM-14` | El detalle de una fila que no satisface la restricción de ámbito responde `404`, nunca `403` |
| `RN-PERM-15` | La exportación se acota con la misma restricción que el listado, dentro del trabajo en cola |
| `RN-PERM-16` | Un rol `is_system` no se crea, no se elimina y no cambia de `code` ni de `name` |
| `RN-PERM-17` | Un rol con asignaciones vivas no se elimina ⇒ `409` |
| `RN-PERM-18` | La comprobación de módulo desactivado precede a la de permiso |
| `RN-PERM-19` | Toda escritura de `permission_role` pasa por el modelo, nunca por `attach`/`detach`/`sync` |
| `RN-PERM-20` | El cambio de roles de un usuario se audita con estado anterior leído **antes** del cambio |
| `RN-PERM-21` | Un rol personalizado con `mfa_required = true` obliga a sus titulares exactamente igual que uno predefinido |
| `RN-PERM-22` | La vista previa de permisos efectivos se calcula con el mismo código que la aplicación real |
| `RN-PERM-23` | El autoservicio de permisos efectivos se autoriza **por identidad** en ruta propia, nunca como permiso con ámbito `propios`, y su sujeto sale de la sesión y **entra en la consulta**, jamás de un parámetro |
| `RN-CORE-06` | *(vigente, 1.1)* Nadie se cambia los roles a sí mismo ⇒ `409` |
| `RN-CORE-07` | *(vigente, 1.1)* Siempre al menos un `administrador_centro` vivo y activo ⇒ `409` |

---

## 14. Casos límite y errores

| Caso | Comportamiento |
|------|----------------|
| Usuario **sin ningún rol** | Denegado en todo. No es un error: es `RPERM-011` funcionando |
| Usuario con **dos roles**, uno `allow todos` y otro `deny` del mismo código | Denegado. El `deny` gana siempre (`RN-PERM-06`) |
| Usuario con `allow propios` en un rol y `allow todos` en otro | Sin restricción de fila. `todos` absorbe (`RN-PERM-09`) |
| Usuario con `allow propios` y `allow grupo` en dos roles distintos | Unión: ve lo suyo **o** lo de su grupo (`OR`) |
| Rol que concede `salud.leer` sin `special_data_access` | Concesión **inerte**. La vista previa la muestra con motivo `inerte_datos_especiales`, no como inexistente |
| Concesión de un permiso cuyo módulo se desactiva después | Inerte con motivo `inerte_modulo`. **La fila no se borra**: reactivar el módulo restaura el acceso (`RMOD-004`) |
| Permiso marcado `retired_at` tras retirarse del código | Inerte con motivo `inerte_permiso_retirado`. La fila histórica se conserva (`ADR-034 §2`) |
| Fila con ámbito `grupo` inyectada a mano en base de datos | **Deniega** (`RN-PERM-05`), y el `CHECK` la admite solo si el valor está en el vocabulario |
| Fila con `scope` fuera del vocabulario inyectada a mano | Imposible: el `CHECK` la rechaza en el motor |
| `administrador_centro` intenta clonar `orientador` | `422`: no puede activar `special_data_access` (§5.5). **Es el comportamiento correcto**, no un fallo |
| Rol clonado de uno que concede más de lo que el solicitante tiene | `403` (`RPERM-013`) |
| Se elimina el único rol que concedía un permiso a alguien | Ese alguien pasa a denegado en la siguiente petición. Sin caché, el efecto es inmediato (§4.6) |
| Dos administradores editan el mismo rol a la vez | Última escritura gana (`ADR-038 §10`). El rastro de lo perdido está en `audit_logs` |
| Un `PUT /users/{id}/roles` que no cambia nada | `200` con el estado, **sin** fila de auditoría (`ADR-038 §9.3`) |
| Recurso de otro tenant | `404`, nunca `403` (`ADR-038 §6.4`) |
| Trabajo en cola que resuelve permisos | Instancia nueva del resolutor por trabajo. La memoización no cruza trabajos (§4.6) |

---

## 15. Interacción con otros módulos

**Nunca por dependencia directa de código** (`INV-007`).

### 15.1 Lo que este núcleo expone

| Interfaz | Quién la consume |
|----------|------------------|
| `ScopeResolver` (contrato) | Todo módulo que aporte una entidad de ámbito. `REQ-ACAD` (1.11) y `REQ-FAM-UNIT` (1.14) son los dos siguientes |
| `applicable_scopes` en `declaredPermissions()` | Los 53 módulos |
| `PermissionDecision` + API sancionada de acotación | Todo módulo con un recurso permisionado |

### 15.2 Lo que este núcleo consume

| Interfaz | De quién |
|----------|----------|
| «¿Este tenant puede usar este módulo ahora?» (booleano) | `REQ-CORE` (§9) |
| Roles y asignaciones (`Role`, `role_user`) | `REQ-CORE`. Los endpoints de administración viven **en** `App\Modules\Core` precisamente por esto (`ADR-044 §4.10`) |
| `MfaPolicy` | `REQ-AUTH`. 1.5 **no la toca**: solo verifica que `mfa_required` en un rol personalizado se comporta igual (`RN-PERM-21`) |

### 15.3 Eventos de dominio

| Evento | Cuándo | Consumidor previsto |
|--------|--------|---------------------|
| `UserRolesChanged` | *(ya existe desde 1.1)* Cambio del conjunto de roles de un usuario | `REQ-AUTH` (recalcular obligación de MFA) |
| `RoleMfaRequirementChanged` | *(ya existe desde 1.3)* `mfa_required` pasa de `false` a `true` | `REQ-AUTH`. 1.5 **no cambia su semántica**: sigue emitiéndose sólo cuando la obligación empieza, nunca cuando termina |
| `RolePermissionsChanged` | **Nuevo.** Cambio en las concesiones de un rol | Ninguno en 1.5. Se emite porque el día que exista caché (`ADR-044 §4.7`, descartada hoy) o un panel de plataforma, la señal debe existir antes que su consumidor y no al revés |

**Sin webhooks.** Ningún requisito los pide para este módulo.

---

## 16. Comportamiento con el módulo desactivado

**No aplica.** `REQ-PERM` no es un módulo activable: es infraestructura de framework (`ADR-044 §4.10`), igual que el aislamiento de tenant. No tiene fila en `modules`, no se puede desactivar, y sus endpoints de administración pertenecen a `REQ-CORE`, que tampoco es desactivable (`REQ-CORE/operacion.md §1`).

Lo que sí hace este paso respecto de `RMOD-008`/`RMOD-009` está en §9: **consumir** el estado del módulo para hacer inertes los permisos de módulos no utilizables.

---

## 17. Criterios de aceptación

Formato `Dado / Cuando / Entonces`. Todos verificables por test automatizado y todos referencian su requisito (`INV-015`).

### Vocabulario y contrato de ámbitos (`RPERM-004`)

- **`CA-PERM-001`** — *Dado* el esquema desplegado, *cuando* se intenta insertar una fila de `permission_role` con `scope = 'departamento_x'`, *entonces* el motor la rechaza por `CHECK`.
- **`CA-PERM-002`** — *Dado* el esquema desplegado, *cuando* se intenta insertar una fila de `permission_role` con `scope` nulo, *entonces* el motor la rechaza por `NOT NULL`.
- **`CA-PERM-003`** — *Dado* que ninguna migración anterior dejó filas con `scope` nulo o fuera del vocabulario, *cuando* se ejecuta la migración de este paso, *entonces* completa sin error y `SELECT count(*) FROM permission_role WHERE scope IS NULL OR scope NOT IN (...)` devuelve cero (sucesor de `CA-CORE-042`).
- **`CA-PERM-004`** — *Dado* un permiso con `applicable_scopes = ['todos']`, *cuando* se intenta concederlo con ámbito `propios`, *entonces* la respuesta es `422` y no se guarda ninguna fila.
- **`CA-PERM-005`** — *Dado* el ámbito `grupo`, que no tiene resolutor registrado en 1.5, *cuando* se intenta conceder un permiso con ese ámbito, *entonces* la respuesta es `422` con el código de error de resolutor ausente.
- **`CA-PERM-006`** — *Dado* una fila de `permission_role` con ámbito `grupo` **inyectada directamente en base de datos**, *cuando* el sujeto pide ese permiso, *entonces* la resolución la trata como inerte y el permiso queda **denegado**.
- **`CA-PERM-007`** — *Dado* un módulo que no declara `applicable_scopes` para un permiso, *cuando* se sincroniza el catálogo, *entonces* ese permiso admite exactamente `['todos']`.

### El resolutor real: `propios` sobre `auditoria` (`RPERM-004`, `ADR-044 §8`)

- **`CA-PERM-010`** — *Dado* un usuario con un rol que concede `auditoria.leer` con ámbito `propios`, y entradas de auditoría suyas y de otros, *cuando* pide `GET /audit-logs`, *entonces* la respuesta contiene **únicamente** las entradas en las que él es el actor.
- **`CA-PERM-011`** — *Dado* el mismo usuario, *cuando* consulta el historial de una entidad cuyas entradas son de otro actor, *entonces* la respuesta es `404`, no `403` ni una lista vacía con `200`.
- **`CA-PERM-012`** — *Dado* el mismo usuario con `auditoria.exportar` de ámbito `propios`, *cuando* solicita una exportación y el trabajo se ejecuta, *entonces* el artefacto generado contiene **solo** sus filas.
- **`CA-PERM-013`** — *Dado* un usuario **sin** `auditoria.leer`, *cuando* pide `GET /audit-logs`, *entonces* la respuesta es `403`. El ámbito `propios` acota a quien ya tiene el permiso; no lo concede.
- **`CA-PERM-014`** — *Dado* un usuario con `auditoria.leer` de ámbito `todos`, *cuando* pide `GET /audit-logs`, *entonces* ve todas las entradas del tenant y ninguna de otro tenant.

### Resolución multi-rol (`RPERM-007`, `RPERM-011`)

- **`CA-PERM-020`** — *Dado* un usuario con dos roles, uno con `allow` y otro con `deny` del mismo código, *cuando* se resuelve, *entonces* está denegado, **cualquiera que sea el ámbito de las dos filas**.
- **`CA-PERM-021`** — *Dado* un usuario con `allow propios` en un rol y `allow todos` en otro, *cuando* lista el recurso, *entonces* no se aplica ninguna restricción de fila.
- **`CA-PERM-022`** — *Dado* un usuario con dos roles que conceden ámbitos distintos y no comparables, *cuando* lista el recurso, *entonces* ve la **unión** de ambas restricciones.
- **`CA-PERM-023`** — *Dado* un usuario sin ningún rol, *cuando* pide cualquier endpoint permisionado, *entonces* recibe `403`.
- **`CA-PERM-024`** — *Dado* un rol cuyo módulo está desactivado para el tenant, *cuando* se resuelve un permiso que concede, *entonces* la concesión es inerte y el permiso queda denegado.
- **`CA-PERM-025`** — *Dado* un `deny` en un rol cuyo módulo está desactivado, *cuando* se resuelve, *entonces* el `deny` **sigue vetando** el código.

### Categoría especial (`RPERM-012`, `RPERM-015`)

- **`CA-PERM-030`** — *Dado* un permiso con `is_special_category = true` concedido desde un rol con `special_data_access = false`, *cuando* se resuelve, *entonces* **no concede nada** y el motivo declarado es `inerte_datos_especiales`.
- **`CA-PERM-031`** — *Dado* el mismo permiso concedido desde un rol con `special_data_access = true`, *cuando* se resuelve, *entonces* sí concede.
- **`CA-PERM-032`** — *Dado* un usuario con un rol que tiene `special_data_access = true` **y** otro rol distinto que concede el permiso especial sin el atributo, *cuando* se resuelve, *entonces* **no concede** (la conjunción es por concesión, no por usuario).
- **`CA-PERM-033`** — *Dado* un usuario con `rol.actualizar` pero sin `rol_datos_especiales.actualizar`, *cuando* envía `PATCH /roles/{id}` con `special_data_access`, *entonces* recibe `403` y el atributo no cambia.
- **`CA-PERM-034`** — *Dado* un usuario con `rol_datos_especiales.actualizar` pero **sin** ningún rol con `special_data_access`, *cuando* intenta activarlo en un rol, *entonces* recibe `403` (`RPERM-013` sobre el atributo).

### `RPERM-013` con ámbitos

- **`CA-PERM-040`** — *Dado* un solicitante con `auditoria.leer` de ámbito `propios`, *cuando* intenta conceder ese código con ámbito `todos`, *entonces* recibe `403`.
- **`CA-PERM-041`** — *Dado* un solicitante con `auditoria.leer` de ámbito `todos`, *cuando* concede ese código con ámbito `propios`, *entonces* se guarda (absorción).
- **`CA-PERM-042`** — *Dado* un solicitante sin un código, *cuando* intenta asignar un rol que lo concede, *entonces* recibe `403` (sucesor de `CA-CORE-017`).
- **`CA-PERM-043`** — *Dado* un solicitante sin un código, *cuando* añade una fila `deny` de ese código a un rol, *entonces* la operación se **acepta**.
- **`CA-PERM-044`** — *Dado* un solicitante cuyo permiso es inerte por categoría especial, *cuando* intenta concederlo, *entonces* recibe `403`.

### Roles: alta, clonación, edición, baja (`RPERM-005`, `RPERM-006`)

- **`CA-PERM-050`** — *Dado* `rol.crear`, *cuando* se crea un rol personalizado, *entonces* queda con `is_system = false` y con `name` literal, y `code` es único vivo en el tenant.
- **`CA-PERM-051`** — *Dado* un rol origen, *cuando* se clona, *entonces* el nuevo rol tiene las mismas concesiones y editarlo después **no afecta** al origen.
- **`CA-PERM-052`** — *Dado* un rol origen con `special_data_access = true` y un solicitante que no puede activarlo, *cuando* se clona, *entonces* la respuesta es `422` y no se crea nada.
- **`CA-PERM-053`** — *Dado* un rol `is_system`, *cuando* se intenta eliminar, *entonces* la respuesta es `409`.
- **`CA-PERM-054`** — *Dado* un rol personalizado con usuarios asignados, *cuando* se intenta eliminar, *entonces* la respuesta es `409` con el recuento de afectados.
- **`CA-PERM-055`** — *Dado* un rol personalizado sin asignaciones, *cuando* se elimina, *entonces* queda con borrado lógico y sus concesiones también.
- **`CA-PERM-056`** — *Dado* un rol `is_system`, *cuando* se intenta cambiar su `code`, *entonces* la respuesta es `422`.

### `mfa_obligatorio` en roles personalizados (`RPERM-014`)

- **`CA-PERM-060`** — *Dado* un rol personalizado creado **de alta** con `mfa_required = true`, *cuando* un usuario con ese rol inicia sesión, *entonces* queda obligado a MFA **exactamente igual** que si el atributo se hubiera puesto con `PATCH /roles/{id}`, con el mismo período de gracia y el mismo muro.
- **`CA-PERM-061`** — *Dado* un usuario con dos roles, uno con `mfa_required = true` y otro sin él, *cuando* se resuelve la obligación, *entonces* queda obligado (resolución restrictiva en multi-rol, `REQ-AUTH-003`).

### Permisos efectivos (`RPERM-009`)

- **`CA-PERM-070`** — *Dado* un usuario con varios roles, *cuando* se consulta `GET /users/{public_id}/effective-permissions`, *entonces* cada código lleva su decisión, su conjunto de ámbitos y **de qué rol viene** cada concesión y cada denegación.
- **`CA-PERM-071`** — *Dado* una concesión inerte, *cuando* se consulta el endpoint, *entonces* aparece marcada como inerte **con su motivo**, distinguible de «no concedido».
- **`CA-PERM-072`** — *Dado* cualquier usuario, *cuando* se compara la respuesta del endpoint con lo que la aplicación real permite en cada endpoint permisionado, *entonces* coinciden. Verificable por lectura del código: **no hay una segunda implementación de la resolución**.
- **`CA-PERM-073`** — *Dado* un usuario **sin ningún permiso**, *cuando* pide `GET /me/effective-permissions`, *entonces* recibe `200` con su propia resolución. El autoservicio **no** depende de ningún permiso (§7.11).
- **`CA-PERM-074`** — *Dado* un usuario sin `permiso_efectivo.leer`, *cuando* pide `GET /users/{public_id}/effective-permissions` con **el `public_id` de otra persona**, *entonces* recibe `403`; y *cuando* lo pide con **el suyo propio**, *entonces* recibe **`403` también**, porque esa ruta es de administración y su autorización es estática (§7.11).
- **`CA-PERM-075`** — *Dado* un usuario, *cuando* se comparan `GET /me/effective-permissions` y `GET /users/{su_public_id}/effective-permissions` pedidos por un administrador, *entonces* devuelven **la misma resolución**: comparten controlador y código de cálculo.

### Auditoría (`RPERM-010`, `INV-003`)

- **`CA-PERM-080`** — *Dado* un rol, *cuando* se le concede un permiso por la API, *entonces* aparece una fila `created` sobre `PermissionRole` en `audit_logs` con el código, el efecto y el ámbito.
- **`CA-PERM-081`** — *Dado* una concesión existente, *cuando* se revoca, *entonces* aparece una fila `deleted`.
- **`CA-PERM-082`** — *Dado* una concesión existente, *cuando* cambia su ámbito o su efecto, *entonces* aparece una fila `updated` con `from` y `to`.
- **`CA-PERM-083`** — *Dado* un usuario, *cuando* se cambia su conjunto de roles con `PUT /users/{id}/roles`, *entonces* aparece una fila `updated` sobre `user` con `changes.roles.from` y `changes.roles.to` como listas de códigos.
- **`CA-PERM-084`** — *Dado* un usuario, *cuando* se envía el **mismo** conjunto de roles que ya tenía, *entonces* **no** aparece ninguna fila de auditoría.
- **`CA-PERM-085`** — *Dado* el repositorio completo, *cuando* se ejecuta el test de arquitectura de `ADR-040 §4.4`, *entonces* sigue pasando: `UserSession` es la **única** exclusión declarada y `PermissionRole` no declara ninguna.
- **`CA-PERM-086`** — *Dado* el repositorio completo, *cuando* se ejecuta el test de arquitectura de `ADR-035 §2` sobre los modelos `Full`, *entonces* la lista incluye `PermissionRole` de forma explícita.
- **`CA-PERM-087`** — *Dado* el código de la API, *cuando* se buscan escrituras de `permission_role`, *entonces* ninguna usa `attach`, `detach` ni `sync`.

### Aislamiento y superficie (`INV-001`, `INV-002`)

- **`CA-PERM-090`** — *Dado* un rol de otro tenant, *cuando* se referencia por `public_id`, *entonces* la respuesta es `404`, nunca `403`.
- **`CA-PERM-091`** — *Dado* cualquier endpoint nuevo de este paso, *cuando* se llama sin sesión, *entonces* responde `401`; y sin el permiso que declara su ruta, `403`. **Única excepción, y es de catálogo, no de olvido**: `GET /me/effective-permissions` responde `401` sin sesión y **nunca `403`**, porque no declara ningún permiso (§7.11, `permisos.md §2.2`).
- **`CA-PERM-092`** — *Dado* el repositorio completo, *cuando* se ejecuta el test de arquitectura de `runAsPlatform()`, *entonces* falla si aparece fuera de su lista de excepciones enumerada (issue [#6](https://github.com/pirexia/plataforma-educativa/issues/6), punto 1).
- **`CA-PERM-093`** — *Dado* un módulo de prueba desactivado para el tenant, *cuando* se llama a un endpoint suyo, *entonces* la respuesta es `403` con `type: urn:pge:error:module-disabled`, **antes** de evaluar ningún permiso.

---

## 18. Preguntas abiertas y decisiones tomadas

De las siete cuestiones que esta especificación levantó, **cinco quedaron resueltas el 2026-09-04** y se registran aquí con su respuesta, para que `implementer` no tenga que reconstruirlas de la conversación —el mismo formato con el que `ADR-044 §10` registró las suyas—. **Quedan dos vivas y ninguna bloquea.**

| ID | Estado |
|----|--------|
| `OPEN-PERM-01` | **RESUELTA** (decisión del usuario, 2026-09-04) |
| `OPEN-PERM-02` | **RESUELTA** (decisión del usuario, 2026-09-04) |
| `OPEN-PERM-03` | **RESUELTA** (verificada en el código, 2026-09-04) |
| `OPEN-PERM-04` | **ABIERTA**, no bloquea |
| `OPEN-PERM-05` | **ABIERTA**, fuera de mi ámbito de escritura; no bloquea a 1.5 |
| `OPEN-PERM-06` | **RESUELTA** (decisión del usuario, 2026-09-04) |
| `OPEN-PERM-07` | **RESUELTA** (decisión del usuario, 2026-09-04) |

### `OPEN-PERM-01` · Permiso de `GET /users/{public_id}/effective-permissions` — RESUELTO: recurso nuevo `permiso_efectivo`

`ADR-044 §6` pone el endpoint en alcance pero no decía qué permiso lo protege, y no era una elección menor: la respuesta es el mapa completo de lo que otra persona puede hacer en el centro.

> **Decisión del usuario (2026-09-04)**: la recomendación de esta especificación. Se declara un **recurso nuevo `permiso_efectivo` con la acción `leer`**, declarado por `REQ-CORE`, y se concede **sólo a `administrador_centro`** por defecto.

Se descarta reutilizar `rol.leer` o `asignacion_rol.leer`: los tiene hoy `direccion` y varios roles de gestión, que pasarían a ver la capacidad efectiva de cualquier compañero sin que nadie lo hubiera decidido. Es el patrón del proyecto —recurso nuevo antes que acción inventada, y permiso propio antes que reutilizar uno menos restrictivo (`REQ-AUTH/permisos.md §C.6.1`)—.

**Aplicada en**: `api.md §1` y `§7`; `permisos.md §1`, `§2`, `§4.1`, `§5` y `§5.2`.

### `OPEN-PERM-02` · Autoservicio de permisos efectivos propios — RESUELTO: sí, por identidad, en 1.5

**1.8 (dashboards por rol) necesita saber qué puede hacer el usuario actual** para pintar su menú sin enlaces muertos, y `REQ-CORE-008` lo pide expresamente.

> **Decisión del usuario (2026-09-04)**: **sí, entra en 1.5**, autorizado **por identidad** y sin permiso, con el mismo patrón que `GET /me` y que los tres endpoints de `/auth/sessions` (`REQ-AUTH/permisos.md §B.1`).

**Forma técnica, que la decisión delegó en esta especificación**: una **ruta propia `GET /me/effective-permissions`**, no una condición dentro de la ruta de administración. El razonamiento está en §7.11 y se resume en una frase: el patrón que la decisión invoca —`GET /me`, `GET /auth/sessions`— es en los dos casos **una ruta separada**, no una rama condicional dentro de un endpoint de administración, y una ruta cuyo `permission:` a veces aplica y a veces no es precisamente la clase de control que un refactor futuro rompe en silencio.

**Aplicada en**: §7.11 de este documento; `api.md §1` y `§7.3`; `permisos.md §2.2` y `§5.3`.

### `OPEN-PERM-03` · `event` en la escritura manual de auditoría — RESUELTO: no hay restricción, se implementa tal cual

§12.2 audita el cambio de roles con una llamada **manual** y `event = 'updated'`, y `ADR-039 §4.5` había fijado la escritura manual con tres valores admitidos (`login`, `logout`, `password_reset_requested`). La duda era si el código había materializado esa lista como una restricción.

> **Verificado en el código (2026-09-04)**: **no la hay.** `AuditRecorder::record()` y `RecordsAuditTrail` no restringen el valor de `event`; la única restricción es el `CHECK` de base de datos, que incluye `updated` desde el origen y que la migración `app/Modules/Auth/Database/migrations/2026_08_22_100100_widen_audit_logs_vocabulary_for_auth.php` **amplió a nueve valores, no restringió**.

Escribir la auditoría de asignación de roles como `'updated'` funciona sin cambios, sin ADR nuevo y sin decisión adicional. Los tres valores de `ADR-039 §4.5` describen lo que **aquel** paso escribía manualmente; nunca fueron una lista cerrada de lo que el mecanismo admite.

**Aplicada en**: §12.2 de este documento y `datos.md §5.3`, donde la nota `[DERIVADA]` pasa a estar **verificada**.

### `OPEN-PERM-04` · ¿Se declara `RolePermissionsChanged` sin consumidor?

§15.3 propone emitirlo. Es defendible (la señal antes que el consumidor) y también es defendible lo contrario (`ADR-044` reprocha el andamiaje sin consumidor en varios sitios). Es barato en las dos direcciones y no bloquea nada; se señala para que se decida a propósito y no por omisión.

### `OPEN-PERM-05` · Contradicción viva en el documento de requisitos · **NO ES UNA DECISIÓN MÍA**

`REQ-CORE-004` (§5.1 del documento de requisitos) sigue diciendo, literalmente:

> «Herencia de roles con posibilidad de override.»

**`ADR-044 §4.6` la descarta explícitamente**, y `ADR-034 §2` ya había señalado que `RPERM-006` (clonación) y `REQ-CORE-004` (herencia) son cosas distintas que el documento mezcla.

La sección 11 **sí** recibió su nota al pie cuando se difirió `RPERM-008`; `REQ-CORE-004` **no** recibió la equivalente para la herencia. Mientras no la reciba, el documento de requisitos y el ADR aceptado se contradicen en un punto que ya está implementado en un sentido concreto.

**Me detengo aquí y lo señalo**, como exige `CLAUDE.md §0`. La corrección —una nota al pie en `REQ-CORE-004` que remita a `ADR-044 §4.6`, del mismo estilo que la de `RPERM-008`— no la puedo hacer yo: escribo únicamente en `docs/modulos/REQ-PERM/`.

### `OPEN-PERM-06` · Alcance de «el único cambio de esquema» de `ADR-044 §8` — RESUELTO: son dos casos distintos

`ADR-044 §8` describe el cambio de `permission_role.scope` como «un cambio de esquema, el único», dentro de la lista de consecuencias **malas que hay que asumir**. Pero `§4.1` decide que cada permiso declare `applicable_scopes` y `§8` dice que `platform:sync-registry` gana esa responsabilidad, lo que **exige una columna en `permissions`**.

> **Decisión del usuario (2026-09-04)**: **son dos casos de categoría distinta**. `applicable_scopes` en `permissions` es una **migración simple (`ADD COLUMN`)** y **no necesita el tratamiento cauteloso `expand`/`contract`** que sí requiere `permission_role.scope`.

El motivo, que es el que hace la distinción defendible y no una excepción de conveniencia: `permission_role` lleva filas vivas de todos los centros, tiene RLS y su `SET NOT NULL` puede bloquear la tabla; `permissions` es **tabla de referencia compartida**, sin `tenant_id`, sin RLS, de unas 35 filas, escrita únicamente por un comando de despliegue. Añadirle una columna anulable no toca datos de ningún tenant y no puede bloquear nada apreciable. «El único» de `ADR-044 §8` se refiere a los cambios **con riesgo sobre datos existentes de tenant**, que es exactamente el contexto de la lista en la que aparece.

**Aplicada en**: `datos.md §3` y `operacion.md §4.1`, donde la migración de `applicable_scopes` queda descrita como un `ADD COLUMN` simple y no con el patrón `CHECK NOT VALID → VALIDATE`.

### `OPEN-PERM-07` · Siembra de `rol_datos_especiales.actualizar` — RESUELTO: a `administrador_centro`

`ADR-044 §4.4` crea el recurso pero no decía a quién se concede, y la respuesta no era libre: una de las opciones producía un bloqueo del que no se sale.

Las dos reglas que interactúan: activar `special_data_access` en un rol exige (a) el permiso `rol_datos_especiales.actualizar` y (b) que el solicitante **tenga él mismo** `special_data_access` (§5.3). Y `administrador_centro` tiene `special_data_access = false`, a propósito (§5.5).

> **Decisión del usuario (2026-09-04)**: la recomendación de esta especificación. **Se siembra a `administrador_centro`**, que **puede delegarlo pero no puede usarlo por sí mismo**, porque le falta la posesión del atributo.

Consecuencia buscada: **activar `special_data_access` en un rol exige dos personas distintas** —quien concede el permiso y quien lo ejerce desde un rol que sí tiene el atributo— y las dos operaciones quedan enteras en auditoría. Mantiene la administración de roles donde ya está y no relaja `RPERM-012`.

Las otras dos opciones y por qué no:

| Opción descartada | Motivo |
|---|---|
| **No sembrarlo a nadie** | **Bloqueo sin salida.** Conceder un permiso exige poseerlo (`RPERM-013`); si nadie lo tiene, nadie puede concedérselo a nadie, nunca, en ningún centro, y **ningún centro podría crear jamás un rol personalizado con acceso a categoría especial**. Descartada por incorrecta, no por preferencia |
| **Sembrarlo a `orientador`, `coordinador_bienestar` y `personal_sanitario`** | Tampoco bloquea y es la lectura más literal de `RPERM-012`, pero esos tres roles **no tienen hoy ningún permiso de `REQ-CORE`** (`REQ-CORE/permisos.md §4.1`): sería su primera capacidad de administración, y sería de administración de la autorización del centro, en manos de perfiles clínicos y de orientación |

**Aplicada en**: `permisos.md §5`, `§7.4` y `§9.5`; `operacion.md §4.3`.

---

## 19. Antes de implementar

**Ninguna pregunta bloqueante queda viva.** Las cinco que lo eran se resolvieron el 2026-09-04 (§18) y están aplicadas en los cinco documentos de esta carpeta.

**Especificación APROBADA por el usuario el 2026-09-04**, incluida la forma técnica de `OPEN-PERM-02` (ruta separada `GET /me/effective-permissions`). `implementer` puede empezar.

### 19.1 Estado de las siete cuestiones

| ID | Asunto | Estado | Dónde está aplicada |
|----|--------|--------|---------------------|
| `OPEN-PERM-01` | Permiso de `GET /users/{id}/effective-permissions` | **Resuelta** · recurso `permiso_efectivo.leer`, sólo `administrador_centro` | `api.md §1`, `§7`; `permisos.md §1`, `§2`, `§4.1`, `§5` |
| `OPEN-PERM-02` | Autoservicio de permisos efectivos propios | **Resuelta** · sí, en 1.5, ruta propia `GET /me/effective-permissions` por identidad | §7.11; `api.md §1`, `§7.4`; `permisos.md §2.2`, `§5.3` |
| `OPEN-PERM-03` | `event` en la escritura manual de auditoría | **Resuelta** · verificado: no hay restricción de código | §12.2; `datos.md §5.3` |
| `OPEN-PERM-04` | Emitir `RolePermissionsChanged` sin consumidor | **Abierta, no bloquea** | §15.3; `api.md §11` |
| `OPEN-PERM-05` | `REQ-CORE-004` sigue diciendo «herencia con override» | **Abierta, no bloquea a 1.5** · la corrige quien escribe en el documento de requisitos | `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md §5.1` — **fuera de mi ámbito** |
| `OPEN-PERM-06` | Alcance de «el único cambio de esquema» | **Resuelta** · `ADD COLUMN` simple para `applicable_scopes` | `datos.md §3`; `operacion.md §4.1` |
| `OPEN-PERM-07` | Siembra de `rol_datos_especiales.actualizar` | **Resuelta** · a `administrador_centro` | `permisos.md §5`, `§7.4`, `§9.5`; `operacion.md §4.3` |
