# REQ-PERM · Núcleo de autorización granular · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-PERM` (sección 11 del documento de requisitos; `RPERM-001` a `RPERM-015`) |
| Prioridad | MUST |
| Fase | 1 · paso **1.5** del plan |
| Depende de | `REQ-CORE` (1.1, cerrado), `REQ-AUTH` (1.2/1.2b/1.3/1.3b/1.4/1.4b/1.4c, cerrados) |
| Estado | **ACEPTADA** e **IMPLEMENTADA** — aprobada por el usuario el 2026-09-04 (§19), implementada y verificada el 2026-09-07 (`memory.md`, commit `57f6f85`). **Paso 1.5b (interfaz, más tres cambios de servidor): §20, APROBADA el 2026-10-05 (decisión del usuario), **IMPLEMENTADO** en `feature/REQ-PERM-ui-roles` (partes `apps/api` y `apps/web` hechas; pendiente solo de revisión y merge)** — §0-§19 no se reabren salvo la corrección de redacción de §8 (issue #170, decisión del usuario del 2026-10-05) |
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
| **Interfaz gráfica** de alta/clonación de roles, matriz de concesión y pantalla de permisos efectivos | **1.5b**, después de `1.9` — especificada en **§20** (aprobada, 2026-10-05) | `ADR-044 §6`/`§10.1`. Construir la tabla más compleja del producto antes del sistema de diseño (`1.7`) y de TanStack Table (`1.9`) garantiza rehacerla |
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
| `PUT` de concesiones de un rol | Cada entrada `allow` **nueva**, o **cuyo efecto o ámbito cambia** respecto de lo guardado: el ámbito resultante debe estar en lo efectivo del solicitante. Una entrada `allow` idéntica a la guardada no se comprueba. Retirar una entrada no exige nada. *(Redacción corregida el 2026-10-05, issue [#170](https://github.com/pirexia/plataforma-educativa/issues/170): decía «se añade o amplía», que sugería que estrechar no se comprueba. «Estrechar» no es una categoría: ver `RN-PERM-24`, §20.2)* |
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
| `RN-PERM-20` | El cambio de roles de un usuario se audita con estado anterior leído **antes** del cambio; lo que se compara, se audita y se comprueba (`RPERM-013`, `asignacion_rol.eliminar` al retirar) es el estado releído con el bloqueo de `protect()` tomado (issue #350) |
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
- **`CA-PERM-042`** — *Dado* un solicitante sin un código, *cuando* intenta asignar un rol que lo concede, *entonces* recibe `403` (sucesor de `CA-CORE-017`); la respuesta lleva `errors.grant[0].code` = `core.authorization.cannot_grant_unheld_role_permission`, **sin** `params`, y su `detail` no contiene el código ni el ámbito (issue #352, `api.md §8.4`).
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

---

## 20. Paso 1.5b · Interfaz: editor de roles y vista previa de permisos efectivos

| Campo | Valor |
|-------|-------|
| Paso | **1.5b** (`PLAN-IMPLEMENTACION.md`, Bloque B, tras `1.9`-`1.9f`), rama `feature/REQ-PERM-ui-roles` (`ADR-044 §5`) |
| Requisitos | `RPERM-005` (creación de roles personalizados **desde interfaz gráfica**), `RPERM-006` (clonación), `RPERM-009` (vista previa de permisos efectivos), y como interfaz de lo ya implementado: `RPERM-001`/`-003`/`-004` (matriz), `RPERM-007` (procedencia de un `deny`), `RPERM-012`/`-015` (categoría especial), `RPERM-013` (lo que no se posee no se ofrece), `RPERM-014` (`mfa_required` en el alta). Transversales: `RUX-003`/`-004`/`-006`, `RUX-RESP-004`/`-005`/`-007`, `RNF-UX-002`, `INV-002`/`-006`/`-009`/`-010` |
| Alcance | Fijado por el plan y el encargo: listado de roles con acciones, **detalle de rol con sus concesiones** (sustituye `RN-CORE-75` y cierra `OPEN-CORE-36` = A), **alta, clonación, edición y baja** de rol, **edición de concesiones** recurso × acción × ámbito, y **permisos efectivos con procedencia** de un usuario (`GET /users/{id}/effective-permissions`). Primer consumidor del **modo `local`** de `src/data-table` (`OPEN-CORE-26` = B, `ADR-054 §2.1`) |
| Decisiones vinculantes | `ADR-038`, `ADR-044`, `ADR-052`, `ADR-053`, `ADR-054`, `ADR-055` (todas ACEPTADAS); `REQ-CORE/funcional.md §12`, `§13`, `§14` (reglas `RN-CORE-*` que aquí se citan, no se reescriben); **issue #170** resuelto por el usuario el 2026-10-05 (§20.2) |
| Depende de | 1.5 (API), 1.7, 1.8, 1.9, 1.9b-1.9f (`ConfirmDialog`/`useConfirm`, filtros `twoState`/`label`/`multiple`/`initial`, `hideSinglePageFooter`). **Todos implementados.** Ninguna dependencia no implementada |
| Código afectado | `apps/web` (pantallas en `src/modules/core/`, la rejilla de edición `src/modules/core/components/roles/RolePermissionMatrix.vue`, ampliaciones **aditivas** de `src/data-table/`, y el test de `RN-CORE-53` con su segunda lista, §20.12) **y `apps/api`**: S-PERM-1 (`users_count` en el detalle), S-PERM-2 (`resource_label`) y la regla nueva `RN-PERM-47` (§20.2.1), con su OpenAPI (`api.md §14`). **Sin migraciones** |
| Estado | **APROBADA el 2026-10-05** (decisión del usuario). Las diez preguntas `OPEN-PERM-08` a `-17` están resueltas (§20.20); `RN-CORE-53` modificada por aprobación expresa (§20.12). **Implementada** en `feature/REQ-PERM-ui-roles` (`apps/api` y `apps/web` hechas; pendiente de revisión y merge) |

> **Identificadores**: reglas `RN-PERM-24` a `RN-PERM-47`, criterios `CA-PERM-045` a `CA-PERM-049` (servidor) y `CA-PERM-100` a `CA-PERM-137`, preguntas `OPEN-PERM-08` a `OPEN-PERM-17` (todas resueltas). Siguientes libres tras `RN-PERM-23`, `CA-PERM-044`/`-093` y `OPEN-PERM-07` de este documento. Las reglas `RN-CORE-*` citadas son de `REQ-CORE/funcional.md`; la única modificación de una regla ajena (`RN-CORE-53`) está en §20.12, **aprobada expresamente por el usuario el 2026-10-05**, y su reflejo en `REQ-CORE` lo escribe quien tenga ese ámbito (§20.21).

### 20.0 Estado de partida verificado (2026-10-05, rama `feature/REQ-PERM-ui-roles`, `c460eba`)

Lectura de código por ruta conocida (la sesión de especificación no tenía herramienta de búsqueda ni ejecución); lo que no se pudo comprobar se dice como tal.

- **Rutas de servidor** (`apps/api/app/Modules/Core/Http/routes.php`): existen las once de `api.md §1`, con el permiso documentado en cada una.
- **`GET /roles/{public_id}`** (`RolesController::show()`): carga `permissionGrants.permission` pero **no** `withCount('users')`; `RoleResource` emite `users_count` con `whenCounted`, así que **la clave no aparece** en el detalle (sí en el listado). Contradice `api.md §2.2` (hallazgo 1 de §20.19).
- **`GET /roles`**: `per_page` se lee con `$request->integer('per_page', 25)` sin validación visible en el controlador; **no se ha comprobado** si `PagePaginatedResponse` aplica el máximo de 100 de `ADR-038 §4.3` (hallazgo 4).
- **`PUT /roles/{id}/permissions`** (`ReplaceRolePermissions`): comprueba `RPERM-013` sobre toda entrada `allow` **nueva o cuyo efecto o ámbito cambia**, con `PermissionResolver::ownsScope()` (`decision->permitted && hasScope`); se salta las `allow` idénticas a lo guardado y todas las `deny`; no comprueba nada al retirar. La comprobación ocurre **antes** de escribir: un `403` no deja nada a medias. **No** comprueba `is_system` ni protege las concesiones de ningún rol concreto (hallazgo 2).
- **`GET /permissions`**: sin paginar; devuelve `applicable_scopes` y `grantable_scopes`; `module_code` y `resource` admiten **un solo valor** (los permisos efectivos admiten lista por comas).
- **Permisos efectivos** (`EffectivePermissionsController`, `PermissionResolver::decideAll()`): una fila por **cada código no retirado del catálogo**, concedido o no; los no concedidos llegan con `decision: "denegado"` y `sources: []`. `meta.roles[].name` traducido por el servidor. Un usuario dado de baja ⇒ `404`.
- **`GET /me`** (`UserProfilePresenter`): `roles[]` con `public_id`, `code`, `name`; `permissions` como lista de **códigos** sin ámbito. **No** expone `special_data_access`.
- **Frontend**: `core-roles` (`/administracion/roles`, `RolesView.vue`) existe en solo lectura (`RN-CORE-75`); no existe `core-role-detail` (`OPEN-CORE-36` = A). **`CA-CORE-240`** exige que el listado de roles **no** tenga ningún control de crear, clonar, editar ni borrar: **1.5b lo incumple por diseño** y tiene que sustituirlo (§20.4). No se ha leído `RolesView.vue`.
- **`src/data-table`**: `DataTableMode = 'page' | 'cursor'`; no hay modo `local`. Las celdas admiten *slots* con nombre por columna (`defineSlots` de `DataTable.vue`); **no se ha comprobado** que el *slot* de una columna que no sea de acciones se pinte igual en la vista de tarjetas. La lista de excepciones de `RN-CORE-53` está **vacía** desde 1.9f (`CA-CORE-255`).
- **Componentes base disponibles** (`docs/design-system.md §12`): `button`, `badge`, `radio-group`, `input`, `textarea`, `select`, `label`, `table`, `sheet`, `dropdown-menu`, `alert-dialog` (+ `ConfirmDialog`/`useConfirm` de aplicación). Casillas nativas con `accent-primary-on-background`. **No** hay `checkbox`, `switch`, `tabs` ni `tooltip` vendorizados, y esta especificación **no** los necesita.

### 20.1 Alcance

#### 20.1.1 Entra en 1.5b

| # | Qué | Requisitos |
|---|-----|------------|
| 1 | Listado de roles con «Nuevo rol» y enlace a la ficha (amplía `core-roles`) | `RPERM-005` |
| 2 | **Ficha de rol**: datos, recuento de titulares, concesiones (lista en modo `local`), acciones | `RPERM-009` (de un rol), `OPEN-CORE-36` = A |
| 3 | **Alta** de rol personalizado y **clonación** | `RPERM-005`, `RPERM-006`, `RPERM-014` |
| 4 | **Edición de datos** del rol: `name`, `special_data_access` y, según `OPEN-PERM-11`, `mfa_required` | `RPERM-015`, `RN-PERM-11` |
| 5 | **Editor de concesiones**: tres estados por permiso (`ADR-044 §6`) y ámbito, con lo que no se posee deshabilitado y explicado (#170) | `RPERM-001`, `-004`, `-013` |
| 6 | **Baja** de rol | `RN-PERM-16`, `-17` |
| 7 | **Permisos efectivos de un usuario** con procedencia y motivo de inercia, enlazados desde la ficha del usuario | `RPERM-009`, `RPERM-007` |
| 8 | **Modo `local`** de `src/data-table` (ampliación aditiva, §20.11) | `OPEN-CORE-26` = B, `ADR-054 §2.1` |
| 9 | **Test de servidor que fija #170** (`CA-PERM-045`) y corrección de la redacción de `api.md §5.4`, §8 de este documento y `permisos.md §8` | Issue #170 |
| 10 | **Servidor**: S-PERM-1 (`users_count` en el detalle de rol, `OPEN-PERM-17` = A) y S-PERM-2 (`resource_label`, `OPEN-PERM-09` = A) (`api.md §14.3`). S-PERM-3 **no** entra (`OPEN-PERM-12` = A) | `api.md §2.2`, `RPERM-002` |
| 11 | **Servidor**: regla nueva `RN-PERM-47`, el centro no pierde nunca la capacidad completa de administración (§20.2.1, hallazgo 2 de §20.19, decisión del usuario del 2026-10-05) | `RPERM-013`, `RN-CORE-07` |

#### 20.1.2 No entra en 1.5b

| Fuera | Dónde va | Motivo |
|-------|----------|--------|
| Pantalla de autoservicio «Mis permisos» (`GET /me/effective-permissions`) | Sin paso (`OPEN-PERM-14` = A, 2026-10-05) | No está en el alcance del plan ni del encargo; añadirla amplía la lista cerrada de `RN-CORE-24` |
| Asignación de roles a un usuario | Ya existe (1.9b, `OPEN-CORE-43` = A) | No se duplica. La ficha de rol enlaza al listado de usuarios filtrado por rol |
| `RPERM-008` (permisos condicionales) | 1.16 | `ADR-044 §4.5` |
| Herencia de roles | Descartada | `ADR-044 §4.6`; `OPEN-PERM-05` sigue abierta en el documento de requisitos |
| Exportación de concesiones o de permisos efectivos | No se hace | `permisos.md §2.1` |
| Nombre de rol personalizado en cuatro idiomas | Sin paso | Limitación declarada desde 0.8 (`permisos.md §10`); ver hallazgo 5 de §20.19 |
| Edición del `code` de un rol | No se hace | Inmutable por contrato (`api.md §4`) |

### 20.2 Issue #170 aplicado: `RPERM-013` en el editor (decisión del usuario, 2026-10-05)

> **Decisión del usuario (2026-10-05)**: se mantiene el comportamiento **actual y estricto** de `ReplaceRolePermissions`. `RPERM-013` compara pares (código, ámbito) con `todos` como **única** absorción (§8, orden parcial, no retícula). «Estrechar» **no es una categoría**: cualquier entrada `allow` nueva o cuyo ámbito (o efecto) cambie a uno que el solicitante no posee se comprueba y da `403`; retirar nunca se comprueba; `deny` nunca se comprueba. La frase «Restringir siempre se permite» de `api.md §5.4` era un error de redacción.

- **`RN-PERM-24` · `RPERM-013` en el reemplazo de concesiones** *(servidor, vigente desde 1.5; redacción fijada el 2026-10-05)*. En `PUT /roles/{id}/permissions`, toda entrada `allow` que no sea idéntica (mismo efecto y mismo ámbito) a la guardada para ese código pasa si y solo si el solicitante posee de forma efectiva ese código con el ámbito **nuevo**, o con `todos`. Las entradas `allow` idénticas a lo guardado, las retiradas y todas las `deny` no se comprueban. Un fallo deja el rol sin cambios y responde `403` con `errors.grant[0].code` = `core.authorization.cannot_grant_unheld_permission` y `errors.grant[0].params` = `{code, scope}` (`api.md §9.2.1`). *Excepción (issue #352, decisión del usuario del 2026-10-05)*: en `PUT /users/{id}/roles`, `POST /users` con `role_ids` y, desde el issue #356 (decisión del usuario del 2026-10-06), `POST /roles` con `clone_from`, donde las concesiones salen del rol asignado u origen y no del cuerpo, el `403` lleva `errors.grant[0].code` = `core.authorization.cannot_grant_unheld_role_permission` **sin `params`** y un `detail` genérico que no nombra código ni ámbito (`api.md §8.4`).
- **Correcciones de redacción aplicadas** (el mismo día, sin cambio de comportamiento ni de código): `api.md §5.4` (reescrita con tabla de casos), §8 de este documento (fila de `PUT`) y `permisos.md §8` («Denegar», no «Restringir»).
- **Test que fija el comportamiento**: `CA-PERM-045` (§20.17.1). No existe hoy ninguno que cubra el estrechamiento sin posesión; `CA-PERM-040`/`-041` cubren ampliar y absorber.
- **Consecuencia para la interfaz**: la matriz **deshabilita** los ámbitos que el solicitante no posee y lo explica (`RN-PERM-33`), **salvo** el valor ya guardado, que siempre puede conservarse (no se comprueba).

#### 20.2.1 Protección de la capacidad de administración del centro (decisión del usuario, 2026-10-05)

`RPERM-013` tiene una consecuencia que 1.5 no cerró (hallazgo 2 de §20.19): un permiso que **ningún** usuario del centro posee ya **no lo puede conceder nadie del centro**. `RN-CORE-07` garantiza que exista un usuario vivo y activo con el **rol** `administrador_centro`, pero no que ese rol (ni nadie) conserve sus **permisos**: retirar `rol.actualizar` al rol `administrador_centro`, denegarlo desde otro rol asignado a todos los administradores (un `deny` es ciego, no se comprueba y no se hace inerte, `RN-PERM-07`/`-13`) o retirar `usuario.crear` a todos sus titulares deja al centro sin salida hasta una intervención de plataforma que hoy no existe (`REQ-SUP-003`, fase 2).

- **`RN-PERM-47` · El centro nunca pierde la capacidad completa de administración** *(servidor)*. Sea **A** el conjunto de permisos que el aprovisionamiento concede a `administrador_centro` (la constante única `ProvisionTenantDefaults::ADMIN_CENTRO_PERMISSIONS`, que ya incluye los cuatro de `ROLE_ADMINISTRATION_PERMISSIONS` y los de `REQ-AUTH`), restringido a los códigos **no retirados** del catálogo y de **módulos utilizables** por el tenant. El centro **cumple** la regla si existe **al menos un usuario vivo y en estado `activo`** que posee **de forma efectiva** (§4.1, con `deny`, inercias y todo) **todos** los códigos de A con ámbito `todos` (`unrestricted`). Una escritura que haría pasar al centro de **cumplir** a **no cumplir** se rechaza con **`409`**, con la misma forma de error de conflicto que ya usa `RN-CORE-07` (`type` `urn:pge:error:conflict`) y `errors.administration_capacity[0]` con `code` `core.validation.administration_capacity_lost` y `params.codes` (`api.md §9.2.1`, §14.5): una **lista** con la unión, ordenada y sin repetidos, de los códigos de A que, tras la escritura, no posee cada usuario que cumplía la condición antes de ella (un usuario que la escritura da de baja o desactiva cuenta como que no posee ninguno); **no se guarda nada**. Una escritura sobre un centro que **ya** no cumplía antes **no** se rechaza por esta regla (si se rechazara, impediría precisamente las escrituras que lo reparan).

  | Decisión dentro de la regla | Elegida | Motivo |
  |------------------------------|---------|--------|
  | Qué conjunto se protege | A completo, no solo `rol.actualizar` | Por `RPERM-013`, cualquier código de A que nadie posea queda **irrecuperable**: quien conserve `rol.actualizar` no puede volver a conceder `usuario.crear` si no lo tiene. Proteger solo la administración de roles dejaría perder el resto en silencio |
  | Por qué esa constante y no el rol `administrador_centro` | Se lee la **lista de permisos**, no el rol | La regla no depende de ningún código de rol (`RN-PERM-46`, regla 6 de la *skill* `permisos-y-roles`): la cumple igual un rol personalizado con esos permisos. Y es **una sola lista, en un solo sitio**, la que ya consumen el aprovisionamiento y `perm:grant-role-administration` |
  | Un mismo usuario, no la unión de varios | **Un mismo usuario** | Para volver a conceder un código hacen falta **a la vez** `rol.actualizar` (o `asignacion_rol.crear`) **y** poseer ese código. Repartidos entre personas distintas, ninguna puede reparar nada |
  | `activo`, no `pendiente` ni `inactivo` | Igual que `RN-CORE-07` | Un usuario que no puede iniciar sesión no puede reparar nada |
  | `409`, no `422` | **`409`** | El cuerpo es válido y el solicitante está autorizado; lo que lo impide es el **estado actual del centro**. Es la misma naturaleza y el mismo código que `RN-CORE-07` (`409`) y que `RN-PERM-17`; un `422` haría creer que el formulario está mal rellenado |
  | Módulos no utilizables y permisos retirados | Fuera de A | Una concesión inerte por módulo (§9) no la puede tener nadie de forma efectiva; incluirla haría que **descontratar un módulo desde la plataforma** bloqueara toda escritura de roles del centro |

  **Dónde se aplica** (toda escritura del centro que puede reducir la posesión efectiva de alguien): `PUT /roles/{id}/permissions`, `PUT /users/{id}/roles` **con cambio efectivo** del conjunto de roles, `DELETE /users/{id}`, `POST /users/{id}/status` **solo al pasar a `inactivo`** y `PATCH /roles/{id}` **solo si `special_data_access` cambia de valor** (hoy no afecta a A, que no contiene permisos de categoría especial; se evalúa igualmente para que la regla no dependa de esa casualidad). Las exclusiones de las tres últimas (ajuste del 2026-10-05, propuesto por `implementer` y aceptado) son coherentes con la regla: en ellas el estado resultante es idéntico al anterior o más amplio, y por tanto no puede pasar de cumplir a no cumplir. **No** se aplica a `POST /roles` (un rol nuevo no tiene titulares) ni a `DELETE /roles/{id}` (`RN-PERM-17` ya impide eliminar un rol con titulares). Las escrituras de plataforma (descontratación de módulos, `REQ-BO`) quedan fuera: no son escrituras del centro y A ya excluye los módulos no utilizables.

  **Orden de comprobación**: después de la validación de forma y de `RPERM-013`/`RN-PERM-24` (un `403` o un `422` se devuelven antes), y antes de escribir. En `PUT /users/{id}/roles`, después de `RN-CORE-06`/`-07`, que conservan su respuesta.

  **Concurrencia**: dos escrituras simultáneas que por separado cumplen pueden, juntas, dejar al centro sin titular completo. La comprobación y la escritura se **serializan por tenant** dentro de la misma transacción (bloqueo de transacción por tenant, p. ej. `pg_advisory_xact_lock` con una clave derivada del tenant y de esta regla); el mecanismo concreto lo elige `implementer` y lo revisa `security-reviewer`. No añade tabla ni columna.

  **Cálculo**: con el mismo resolutor que autoriza las peticiones (`RN-PERM-22`), sobre el estado **resultante** de la escritura dentro de la transacción. La implementación puede acotar los candidatos (por ejemplo, a los titulares de algún rol con una concesión `allow` de `rol.actualizar`) siempre que el resultado sea el mismo que evaluarlos a todos; si un candidato fuera de esa acotación pudiera cumplir, la acotación es incorrecta.

  **En la interfaz**: el `409` se muestra con el `detail` del servidor y la lista de permisos afectados con sus etiquetas (`RN-PERM-36`, punto 8). La interfaz **no** anticipa la regla (`RN-CORE-61`).

### 20.3 Inventario de pantallas y rutas

Todas en el régimen `app`, registradas en `src/modules/core/shell.ts` (`RN-CORE-60`, `ADR-053 §1`): los *endpoints* son de `REQ-CORE` (`ADR-044 §4.10`) y **no existe un módulo de frontend `perm`**, igual que no existe `App\Modules\Perm`. Sección `administracion`. **Ninguna ruta nueva usa `permissions: []`**: la lista cerrada de `RN-CORE-24` sigue en siete.

| Ruta (nombre · ruta) | `meta.permissions` (anyOf) | Entrada de menú | *Endpoints* que consume (con el permiso que exige cada uno; sin él, no se pide, `RN-CORE-62`) |
|----------------------|-----------------------------|-----------------|------------------------------------------------------------------------------------------------|
| `core-roles` · `/administracion/roles` *(existe)* | `rol.leer` | `core.roles` (existe) | `GET /roles` |
| `core-role-new` · `/administracion/roles/nuevo` | `rol.crear` | — (acción del listado) | `POST /roles`; `GET /roles` (solo con `rol.leer`, para derivar la posesión de `special_data_access`, `RN-PERM-31`) |
| `core-role-detail` · `/administracion/roles/:publicId` | `rol.leer` | — | `GET /roles/{id}`; `GET /permissions` (solo con `permiso.leer`, enriquecimiento); `DELETE /roles/{id}` (solo con `rol.eliminar`) |
| `core-role-clone` · `/administracion/roles/:publicId/clonar` | `rol.crear` | — (acción de la ficha) | `GET /roles/{id}` del origen (solo con `rol.leer`; sin él, la acción de clonar no se ofrece en ninguna parte); `POST /roles` con `clone_from` |
| `core-role-edit` · `/administracion/roles/:publicId/editar` | `rol.actualizar` | — (acción de la ficha) | `GET /roles/{id}` (con `rol.leer`); `PATCH /roles/{id}` |
| `core-role-permissions` · `/administracion/roles/:publicId/permisos` | `rol.actualizar` | — (acción de la ficha) | `GET /roles/{id}` (`rol.leer`), `GET /permissions` (`permiso.leer`), `GET /me/effective-permissions` (sin permiso), `PUT /roles/{id}/permissions` |
| `core-user-effective-permissions` · `/administracion/usuarios/:publicId/permisos` | `permiso_efectivo.leer` | — (acción de la ficha de usuario) | `GET /users/{id}/effective-permissions` |

- Rutas secundarias con `titleKey`/`breadcrumbKey` en `core.*` y `breadcrumbParent`: las de rol, `core-roles` (y las tres que cuelgan de una ficha, `core-role-detail`); la de permisos efectivos, `core-user-detail`. Si el usuario no tiene el permiso de la ruta padre, la miga de pan se comporta como en 1.9b (lo resuelve `ADR-053`; no se especifica nada nuevo aquí).
- **`RN-PERM-25` · Rutas y permiso de pantalla.** `meta.permissions` es el permiso de la **escritura o lectura principal** de la pantalla (columna 2), nunca un rol ni una unión. Cuando el contenido principal necesita además un permiso de lectura que la ruta no puede exigir (`ADR-053 §3` es *anyOf*) —el editor de concesiones necesita `rol.leer` y `permiso.leer`; el formulario de edición, `rol.leer`—, la vista **no pide** el *endpoint* que no puede usar y pinta un estado propio: «Para editar las concesiones necesitas además {permiso}», con el nombre del permiso que falta, en lugar del contenido. No es un `403` provocado (`RN-CORE-62`).
- Se añade a la ficha de usuario (`core-user-detail`, 1.9b) la acción **«Ver permisos efectivos»**, visible solo con `permiso_efectivo.leer` (`RN-CORE-61`), y a la ficha de rol el enlace **«Ver usuarios con este rol»** al listado de usuarios con el filtro `role=<public_id>` ya existente (solo con `usuario.leer`; el filtro de rol del listado exige además `rol.leer`, que la ficha ya tiene).

### 20.4 Listado de roles (`core-roles`, amplía 1.9d)

Tabla `core.roles` sin cambios de columnas, modo `page` (el *endpoint* está paginado; **no** pasa a `local`).

- La columna nombre (`rowHeader`) pasa a ser **enlace a la ficha** (`core-role-detail`).
- Acción de la barra **«Nuevo rol»** con `rol.crear`.
- **Sustituye a `RN-CORE-75`** («solo lectura») y a **`CA-CORE-240`**, que fallaría en cuanto exista «Nuevo rol». El sustituto es `CA-PERM-101`. La nota en `REQ-CORE/funcional.md §14.8` que remita aquí la escribe quien tenga ese ámbito al cerrar el paso (§20.21); **el test de `CA-CORE-240` se reescribe en el mismo *commit* que añade la acción**, no después.

### 20.5 Ficha de rol (`core-role-detail`)

Cabecera: nombre (literal del centro o traducido por el servidor en roles del sistema), `code` (texto técnico, monoespaciado, con etiqueta «Código interno»), tipo (del sistema / personalizado), «MFA obligatorio» (sí/no), «Acceso a datos de categoría especial» (sí/no), titulares (`users_count`, que el detalle devuelve con S-PERM-1, `OPEN-PERM-17` = A).

**Concesiones**: tabla `core.role_grants` en **modo `local`** (§20.11) sobre `permissions[]` de `GET /roles/{id}` — es un campo de un recurso, no un *endpoint* paginado, igual que `error_summary` en `RN-CORE-74`; con el modo `local` ya no hace falta la «página única sintética». `rowKey` = `code`.

| `id` | Cabecera | Contenido | Ordenable | Tarjeta |
|------|----------|-----------|-----------|---------|
| `resource` | Recurso | Etiqueta del recurso (`RN-PERM-28`). `rowHeader` | Sí | `title` |
| `action` | Acción | Etiqueta traducida de la acción (`RN-PERM-27`) | Sí | `subtitle` |
| `effect` | Efecto | «Permitir» / «Denegar» (texto, nunca solo color) | Sí | `field` |
| `scope` | Ámbito | Etiqueta del ámbito; en un `deny`, «Cualquier ámbito» (`RN-PERM-34`) | Sí | `field` |
| `special` | Categoría especial | Solo si se cargó `GET /permissions`: insignia «Categoría especial» en los `is_special_category`; si el rol no tiene `special_data_access`, además «Inerte: este rol no tiene acceso a datos especiales» | No | `field` |
| `code` | Código | `code` técnico. Oculta por defecto | Sí | `field` |

Filtros (locales): `effect` (`enum`), búsqueda por etiqueta de recurso, de acción y `code`. Texto de vacío: «Este rol no concede ni deniega ningún permiso».

**Acciones** (cada una solo con su permiso, `RN-CORE-61`; `RN-PERM-26`):

| Acción | Permiso | Visible cuando |
|--------|---------|----------------|
| Editar datos | `rol.actualizar` | Siempre (en un rol del sistema el nombre no es editable, `RN-PERM-40`) |
| Editar concesiones | `rol.actualizar` | Solo en roles personalizados (`is_system = false`, `OPEN-PERM-10` = B); en los del sistema, el texto que remite a «Clonar» (`RN-PERM-40`) |
| Clonar | `rol.crear` (y `rol.leer`, que la ficha ya exige) | Siempre |
| Eliminar | `rol.eliminar` | Solo `is_system = false` (`RN-PERM-39`) |
| Ver usuarios con este rol | `usuario.leer` | `users_count > 0` o desconocido |

### 20.6 Alta y clonación (`core-role-new`, `core-role-clone`)

- **`RN-PERM-29` · Alta.** Formulario con `name` (obligatorio; ayuda: «se muestra igual en todos los idiomas»), `code` (obligatorio; se **propone** a partir del nombre —minúsculas, sin tildes, espacios a `_`, recortado a 64— y el usuario puede editarlo mientras no haya guardado; ayuda: «identificador interno; no podrá cambiarse»), `mfa_required` (casilla, desmarcada por defecto) y `special_data_access` (`RN-PERM-31`). Validación de formato de `code` en cliente (`^[a-z][a-z0-9_]{2,63}$`) solo como comodidad (`INV-010`); `422` del servidor por campo (`RN-CORE-65`), incluido `role_code_taken`. El alta **no** envía concesiones: tras `201`, mensaje con `role="status"` y navegación **al editor de concesiones del rol nuevo** (`core-role-permissions`, `OPEN-PERM-16` = A). `is_system` y `name_key` nunca se envían.
- **`RN-PERM-30` · Clonación.** Ruta con el `public_id` del origen. La pantalla muestra el rol de origen (nombre, número de concesiones, `mfa_required`, `special_data_access`) y pide `name` y `code` (misma regla que el alta). Explica en texto que se copian las concesiones y `mfa_required`, que **no** se copian los titulares, y que el rol nuevo queda **desligado** del origen (`ADR-044 §4.6`). Si el origen tiene `special_data_access = true`, un aviso previo dice que la clonación solo será posible si el solicitante puede activar ese atributo (`RN-PERM-31`); el botón **no** se deshabilita por eso (el servidor decide). `POST /roles` con `clone_from`, `code`, `name` y **nada más** (`permissions` y `clone_from` son excluyentes). Errores: `422 clone_requires_special_data_access` y `422 clone_source_not_found` con el mensaje del servidor; `403` con `errors.grant[0].code` = `cannot_grant_unheld_permission`: el `detail` del servidor y el permiso y el ámbito de `errors.grant[0].params` con sus etiquetas (`api.md §9.2.1`), más el texto «El rol de origen concede algo que tú no tienes; no se ha creado nada». Tras `201`, a la ficha del rol nuevo.
- **`RN-PERM-31` · `special_data_access` en alta y edición.** El control solo existe si el solicitante tiene `rol_datos_especiales.actualizar` (`/me.permissions`). Si además tiene `rol.leer`, la vista **deriva** si el solicitante posee el atributo cruzando `/me.roles[].public_id` con `GET /roles` (que ya trae `special_data_access`); si **no** lo posee, el control se pinta **deshabilitado** con el texto «No puedes activar este atributo: ninguno de tus roles tiene acceso a datos de categoría especial» (es el caso por defecto de `administrador_centro`, `permisos.md §5`). Sin `rol.leer`, el control se pinta habilitado y el servidor decide (`403 special_data_access_not_held` con su `detail`). Desactivar el atributo de un rol que lo tiene exige el mismo permiso (`api.md §4`). Junto al control, siempre: «Las concesiones de datos de categoría especial solo surten efecto en roles con este atributo» (`RN-PERM-10`).

### 20.7 Edición de datos del rol (`core-role-edit`)

`PATCH /roles/{id}` con **solo las claves modificadas** (`RN-CORE-65`). Campos: `name` (no en roles del sistema: se muestra como texto con la explicación «el nombre de un rol del sistema no se edita»), `special_data_access` (`RN-PERM-31`) y `mfa_required` **en solo lectura**, con enlace a `/administracion/mfa`, donde ya se edita desde 1.3 (`OPEN-PERM-11` = A; en el alta sí es editable, y la clonación lo copia del origen). `code` se muestra y **nunca** se envía. Activar `special_data_access` pide confirmación (`RN-CORE-64`) que dice que el rol pasará a hacer efectivas sus concesiones de categoría especial para **{users_count}** titulares; desactivarlo, que dejarán de surtir efecto.

### 20.8 Editor de concesiones (`core-role-permissions`)

#### 20.8.1 Forma

**Decidido: matriz literal** (`OPEN-PERM-08` = A, 2026-10-05), construida a medida en **`src/modules/core/components/roles/RolePermissionMatrix.vue`** sobre `@/components/ui/table`, como **rejilla de edición** de la segunda lista de `RN-CORE-53` (§20.12, aprobada). No usa `src/data-table` ni `@tanstack/vue-table` (`RN-CORE-37` no tiene excepciones) ni `<table` crudo.

- **Una matriz por módulo**, en el orden del catálogo, cada una precedida de un encabezado con el nombre del módulo (rama por defecto: código crudo) y con `caption` propio («Concesiones de {módulo} para {rol}»).
- **Filas**: un recurso del módulo por fila, ordenadas por su etiqueta (`RN-PERM-28`) con `Intl.Collator` del idioma activo; cabecera de fila `th scope="row"` con la etiqueta del recurso y, si **alguno** de sus permisos es `is_special_category`, la insignia «Categoría especial».
- **Columnas**: las acciones de `RPERM-003` **que existen en algún permiso de ese módulo**, en el orden de `RPERM-003` (crear, leer, actualizar, eliminar, exportar, importar, aprobar, firmar, publicar), con `th scope="col"` y la etiqueta traducida (`RN-PERM-27`). Una acción que ningún permiso del módulo tiene no aparece como columna (evita columnas vacías: la mayoría de recursos tienen de una a cuatro acciones, `permisos.md §4.1`).
- **Celda sin permiso** (el par recurso × acción no existe en el catálogo): el valor vacío común (`DataTableEmptyValue`, exportado por `src/data-table`; marca visual oculta al lector de pantalla y «Sin valor» solo para él, `CA-CORE-201`). Sin control.
- **Celda con permiso**: un **botón** que muestra el estado en texto —«Sin conceder», «Permitir · {ámbito}» o «Denegar»— y, como texto breve y no solo como color, las marcas que apliquen: «Modificado», «Inerte» (`RN-PERM-35`), «Solo retirar o denegar» (`RN-PERM-33` punto 1), «Error» (`RN-PERM-36`). Nombre accesible con la identidad de la celda y la acción: «{recurso} · {acción}: {estado}. Modificar». Al activarlo se abre un **panel de edición** de esa celda (componente `sheet`, ya vendorizado; `role="dialog"`, foco atrapado, `Esc` cierra, el foco vuelve al botón de la celda) con: el título «{recurso} · {acción}», el código técnico, el control de tres estados (`radio-group`, `RN-PERM-32`), el selector de ámbito (`select`, `RN-PERM-33`), la explicación de «Denegar» (`RN-PERM-34`), los avisos completos de la celda y su error del servidor. Los cambios del panel se aplican al estado de edición al cerrarlo con «Aplicar»; «Cancelar» o `Esc` lo cierran sin cambios. **Nada se envía al servidor hasta «Guardar»** (`RN-PERM-36`).
- **Controles fuera de la matriz** (no son una tabla de datos ni la vuelven una): selector de módulo (todos o uno), búsqueda por etiqueta de recurso que oculta las filas que no coinciden, y la casilla «Solo recursos con cambios sin guardar». Un recuento «{n} cambios sin guardar» y la leyenda de `RN-PERM-33` encima.
- **Tamaño de pantalla**: la rejilla **no** se convierte en tarjetas: su propósito es comparar las acciones de un recurso y una acción entre recursos, que es el criterio de excepción de `ADR-054 §4`. Cuando no cabe (siempre a 320 px), la matriz se desplaza en horizontal **dentro de su contenedor**, con la columna de recurso fija (`position: sticky`) para no perder la referencia; el contenedor es enfocable, tiene nombre accesible («Matriz de concesiones de {módulo}, desplazable») y se desplaza con el teclado; la página nunca se desplaza en horizontal (`CA-CORE-080`).
- **Accesibilidad propia**, que la rejilla no hereda de ningún componente y por eso se fija aquí: `RN-CORE-44` salvo la columna `rowHeader` única (`th scope="row"` por recurso, `th scope="col"` por acción, sin `role="grid"` ni navegación con flechas: se recorre con `Tab`, celda por celda, en orden de lectura); botones de celda ≥ 44 × 44 px con puntero grueso (`RUX-RESP-007`); texto en `rem`; solo *tokens* semánticos.
- **Roles del sistema** (`OPEN-PERM-10` = B): la misma matriz en **solo lectura** —las celdas son texto, no botones; no hay «Guardar» ni panel— con el texto que remite a «Clonar».

> **Lectura de §20.8.2-§20.8.5**: donde esas reglas dicen «fila», en la matriz aprobada se lee «**celda**» (un permiso = un par recurso × acción), y los controles y avisos de la fila están en el **panel de edición** de la celda, con sus marcas breves repetidas en el botón de la celda. Las reglas no cambian de contenido.

#### 20.8.2 Modelo de edición

- **`RN-PERM-32` · Estado por permiso y conjunto enviado.** Al cargar, la vista toma una **instantánea** de `permissions[]` de `GET /roles/{id}`. Cada código del catálogo tiene un estado editable: **«Sin conceder»** (ninguna fila), **«Permitir»** con un ámbito, o **«Denegar»**. El control de tres estados es un `radio-group` (componente base) por fila, con nombre accesible que incluye la identidad de la fila («Concesión de Usuarios · leer»). Un código que está en la instantánea y ya no está en el catálogo cargado (retirado entre medias) se conserva tal cual en el cuerpo y se muestra en un aviso aparte. **El cuerpo del `PUT` es el estado completo deseado** (`api.md §5`): una entrada por código en «Permitir» o «Denegar», ninguna por «Sin conceder»; las entradas que el usuario **no ha tocado** se envían **idénticas** a la instantánea (mismo `effect` y `scope`), para que el servidor no las compruebe (`RN-PERM-24`). Los cambios sin guardar se marcan en cada celda con texto («Modificado»), no solo con color, y un contador encima de la matriz dice cuántos hay; el estado de edición vive en la vista, indexado por `code`, nunca en el DOM ni en ningún almacenamiento (`RN-CORE-50`): cambiar de módulo, buscar o filtrar no lo pierde.
- **`RN-PERM-34` · Denegar.** Al elegir «Denegar», el selector de ámbito se sustituye por el texto «Cualquier ámbito» y una explicación persistente asociada a la fila: «Denegar anula este permiso para todos los titulares del rol, en cualquier ámbito y aunque otro de sus roles lo conceda» (`RN-PERM-06`, `ADR-044 §4.3`). Se envía `scope: "todos"` (la API exige el campo y no lo evalúa, `api.md §5`). «Denegar» está **siempre** disponible: no se comprueba `RPERM-013` (`RN-PERM-13`).

#### 20.8.3 Qué ámbitos se ofrecen, y por qué no otros (UX de #170)

- **`RN-PERM-33` · Ámbitos ofrecidos, deshabilitados y explicados.** El selector de ámbito (componente base `select`) de una fila en «Permitir» lista **todos** los `applicable_scopes` del permiso, en el orden del vocabulario de `RN-PERM-01`, cada uno en uno de estos cuatro estados, calculados en cliente como **comodidad** (el servidor decide, `RN-PERM-24`):

  | Estado de la opción | Cuándo | Qué ve el usuario |
  |---------------------|--------|-------------------|
  | **Disponible** | Está en `grantable_scopes` **y** el solicitante lo posee (`api.md §14.2`: su fila en `GET /me/effective-permissions` es `permitido` y `unrestricted` o el ámbito está en `scopes`) | La etiqueta del ámbito |
  | **Valor guardado** | Es el ámbito de la instantánea para esta fila, con efecto `allow`, **aunque** el solicitante no lo posea | La etiqueta y «(actual)». Siempre seleccionable: conservarlo no se comprueba (`RN-PERM-24`). Si el usuario lo cambia por otro y vuelve a él antes de guardar, la fila vuelve a contar como no modificada |
  | **No disponible: no lo tienes** | Está en `grantable_scopes` pero el solicitante no lo posee | Opción deshabilitada con el texto **dentro de la propia opción**: «{ámbito} — no puedes concederlo: tú no tienes este permiso con este ámbito» |
  | **No disponible: aún no existe** | Está en `applicable_scopes` y **no** en `grantable_scopes` (sin resolutor, `RN-PERM-04`) | Opción deshabilitada con: «{ámbito} — todavía no se puede conceder: lo aportará un módulo que aún no está disponible» |

  Además:
  1. **«Permitir» deshabilitado** en una fila si el solicitante no posee **ningún** ámbito disponible de ese código **y** la instantánea de la fila no es `allow`. La fila muestra en `notes`, con un icono decorativo y **texto**: «Solo puedes denegar o dejar sin conceder este permiso: tú no lo tienes» (o «… lo tienes vetado por una denegación» si su fila de `/me/effective-permissions` tiene una fuente `deny`; «… tu concesión no surte efecto ({motivo de inercia})» si solo tiene fuentes inertes). Es la explicación que pide la decisión de #170 y la que hace legible `CA-PERM-044`.
  2. **El motivo nunca va solo en un `title` ni en un *tooltip*** (WCAG 1.3.1, 1.4.13; `RUX-ICON-003` pide *tooltip* para iconos, no sustituye al texto): el texto de las opciones deshabilitadas es parte del nombre accesible de la opción, y la nota de la fila está asociada al grupo de controles con `aria-describedby`.
  3. **Leyenda permanente** encima de la matriz con los dos motivos de deshabilitación y la regla en una frase: «No puedes conceder un permiso, ni con un ámbito, que tú no tengas. Siempre puedes retirarlo o denegarlo.»
  4. Si `GET /me/effective-permissions` falla, el editor **no** se pinta con todo habilitado: estado de error con «Reintentar» (sin esa respuesta no puede explicar nada y se convertiría en una máquina de `403`).
  5. Los ámbitos de `grantable_scopes` del solicitante se recalculan al recargar el editor; **no** se refrescan en segundo plano.

#### 20.8.4 Categoría especial y módulos no utilizables

- **`RN-PERM-35` · Avisos de inercia en la matriz.** En una fila con `is_special_category = true`: insignia «Categoría especial» y, **si el rol no tiene `special_data_access`** y la fila está en «Permitir», el aviso «Esta concesión no surtirá efecto: el rol no tiene acceso a datos de categoría especial» (`RN-PERM-10`). Se puede guardar (el servidor la acepta y la hace inerte, §5.1); la interfaz avisa, **no** bloquea ni activa el atributo por su cuenta. Los permisos de módulos no utilizables por el centro **se muestran** (`OPEN-PERM-13` = A): si el solicitante tiene `modulo.leer`, la vista pide `GET /modules` y marca la matriz de cada módulo no contratado con «Módulo no contratado: estas concesiones no surten efecto mientras no se contrate», sin bloquear la edición (`RMOD-004`: reactivar restaura); sin `modulo.leer`, no lo pide ni marca nada (`RN-CORE-62`). Hoy no ocurre: `core` y `auth` no son desactivables (§9).

#### 20.8.5 Guardar

- **`RN-PERM-36` · Guardado.** «Guardar» está deshabilitado sin cambios (no sale ninguna petición). Al pulsarlo:
  1. Comprobación de concurrencia (`RN-PERM-37`, `OPEN-PERM-12` = A).
  2. **Confirmación** (`RN-CORE-64`, `ConfirmDialog`): resumen con el nombre del rol y los recuentos de permisos **concedidos**, **cambiados de ámbito**, **denegados** y **retirados**, la lista de los cambios (etiquetas, no códigos), y la consecuencia: «Afecta de inmediato a {users_count} titulares» (sin caché, §4.6). Si el solicitante es **titular del rol** (su `/me.roles` contiene el `public_id`), un aviso adicional: «Eres titular de este rol: los cambios también te afectan a ti, y no podrás volver a conceder lo que te retires». `Esc` cancela sin petición.
  3. `PUT /roles/{id}/permissions` con el conjunto completo (`RN-PERM-32`).
  4. **`200`**: la instantánea pasa a ser la respuesta (sin otra petición), los marcadores de cambio desaparecen, mensaje con `role="status"`, y se recarga `/me` y `/me/effective-permissions` si el solicitante es titular del rol (su propio menú y su propia posesión pueden haber cambiado, `ADR-053 §6`).
  5. **`403` `cannot_grant_unheld_permission`**: no se guardó nada. Se muestra el `detail` del servidor con `role="alert"` en el resumen de errores encima de la matriz, la celda de `errors.grant[0].params.code` muestra el error (texto, asociado a sus controles con `aria-describedby`) con `aria-invalid` en su grupo, el filtro de módulo se ajusta para que esa celda sea visible y el foco va al resumen. Los cambios sin guardar **se conservan**.
  6. **`422`**: cada error `permissions.<i>.<campo>` se lleva a la fila del código que ocupaba la posición `<i>` en el cuerpo enviado (la vista conserva esa correspondencia), con el `message` del servidor; resumen y foco como en el punto 5. Caso esperable: un ámbito que dejó de ser concedible entre la carga y el guardado (`scope_resolver_missing`) o un permiso retirado (`permission_retired`).
  7. **`404`**: el rol ya no existe: estado «no encontrado» y enlace al listado.
  8. **`409` `administration_capacity_lost`** (`RN-PERM-47`): no se guardó nada. Resumen con el `detail` del servidor y la lista `errors.administration_capacity[0].params.codes` con sus etiquetas, el texto «Si guardas esto, nadie del centro conservaría todos los permisos de administración. Concede primero esos permisos a otra persona», y los cambios sin guardar se conservan.
  9. Otros (`401`, `403` de la puerta, `429`, `5xx`): correspondencia común de §12.6 de `REQ-CORE`, conservando los cambios en memoria mientras la vista siga montada.

#### 20.8.6 Concurrencia

- **`RN-PERM-37` · Edición simultánea.** `PUT` reemplaza el conjunto **completo**: si dos administradores editan el mismo rol, el segundo en guardar **revierte en silencio** los cambios del primero en filas que el segundo ni tocó (no solo «la última escritura gana» por campo, `ADR-038 §10`). Tratamiento decidido (`OPEN-PERM-12` = A, 2026-10-05, solo cliente): justo antes de abrir la confirmación, la vista vuelve a pedir `GET /roles/{id}` y compara `permissions[]` con la instantánea; si difieren, **no** abre la confirmación ni envía nada, y muestra «Otra persona ha cambiado las concesiones de este rol desde que lo abriste», con la lista de códigos que cambiaron y la acción «Recargar» (que descarta los cambios propios, previa confirmación). Ventana residual entre la comprobación y el `PUT`: aceptada y dicha. La concurrencia optimista en servidor (S-PERM-3) **no entra en 1.5b**; queda como posible decisión de arquitectura posterior, que aplicaría a todo `PUT` de colección del producto.

#### 20.8.7 Salir con cambios sin guardar

- **`RN-PERM-38` · Cambios sin guardar** (`OPEN-PERM-15` = A). Una guarda de navegación interna que, con cambios sin guardar, pide confirmación con `ConfirmDialog` —«Tienes {n} cambios sin guardar. Si sales, se perderán»— y un manejador de `beforeunload` registrado **solo mientras haya cambios** y retirado al guardar o descartar. No contradice `CA-CORE-202`, que prohíbe `beforeunload` en el aviso de exportaciones, donde salir no pierde nada.

### 20.9 Baja de rol

- **`RN-PERM-39` · Eliminar un rol.** Acción solo en roles con `is_system = false` y con `rol.eliminar` (`is_system` es un dato del rol, no un código de rol: no choca con `RN-CORE-23`). Con `users_count > 0` conocido, el botón se pinta **deshabilitado** con el texto «No se puede eliminar un rol con titulares ({n}). Retíraselo antes a cada usuario» y el enlace «Ver usuarios con este rol» (`RN-PERM-17`); es comodidad: el recuento puede estar desfasado y el servidor decide. `users_count` llega en el detalle por S-PERM-1 (`OPEN-PERM-17` = A); si faltara (respuesta de una versión anterior de la API durante un despliegue), el botón se habilita y decide el servidor. Confirmación (`RN-CORE-64`) que nombra el rol y dice que la baja **no se deshace desde la interfaz** (es lógica en servidor, `INV-004`, pero no hay restauración de roles). `204` ⇒ navegación al listado con mensaje `role="status"`. `409` con `errors.role[0].code` = `role_has_assignments` ⇒ `detail` del servidor, el recuento de `errors.role[0].params.users_count` y el enlace a los usuarios. `409 role_is_system` ⇒ `detail` (no debería ocurrir desde la interfaz).

### 20.10 Permisos efectivos de un usuario (`core-user-effective-permissions`)

- **`RN-PERM-41` · Pantalla.** Cabecera con el nombre de `meta.subject.display_name`, sus roles (`meta.roles`, cada uno enlazado a su ficha si se tiene `rol.leer`), «Calculado el {computed_at}» (fecha y hora del idioma activo) y la acción **«Recalcular»** (vuelve a pedir; sin caché, `ADR-044 §4.7`). Una frase fija explica qué es: «Lo que esta persona puede hacer ahora mismo, calculado con las mismas reglas que aplica la plataforma en cada petición» (`RN-PERM-22`). Tabla `core.effective_permissions` en **modo `local`** sobre la respuesta completa (`api.md §7.3`: no se pagina en servidor), `rowKey` = `code`, sin estado en la URL salvo lo que permite `RN-CORE-54` (decidido: **no** lo declara, para no dejar en el historial qué se miraba de quién).

  | `id` | Cabecera | Contenido | Ordenable | Tarjeta |
  |------|----------|-----------|-----------|---------|
  | `resource` | Recurso | Etiqueta del recurso + insignia «Categoría especial». `rowHeader` | Sí | `title` |
  | `action` | Acción | Etiqueta de la acción | Sí | `subtitle` |
  | `decision` | Resultado | «Permitido» / «Denegado», con icono decorativo y **texto** | Sí | `field` |
  | `scopes` | Ámbitos | «Sin restricción» si `unrestricted`; si no, las etiquetas de `scopes`; valor vacío común si denegado | No | `field` |
  | `sources` | Procedencia | Lista (`ul`) con una entrada por fuente: nombre del rol (enlace con `rol.leer`), «Permite» / «Deniega», ámbito, y si `inert`, «No surte efecto: {motivo}» (`RN-PERM-42`) | No | `field` |
  | `module_code` | Módulo | Oculta por defecto | Sí | `field` |
  | `code` | Código | Oculta por defecto | Sí | `field` |

  Filtros locales: resultado (`enum`), módulo (`enum`, opciones derivadas de las filas), «Incluir permisos sin ninguna concesión» (`boolean` de dos estados, **desmarcado por defecto**: la respuesta trae una fila por cada código del catálogo y la inmensa mayoría son «Denegado» sin procedencia, que no explican nada) y búsqueda por etiqueta de recurso, acción, `code` y nombre de rol. Texto de vacío con el filtro por defecto: «Esta persona no tiene ningún permiso concedido ni denegado por sus roles». La tabla recarga al cambiar el idioma (`RN-CORE-63`: los nombres de rol vienen traducidos).

- **`RN-PERM-42` · Procedencia que explica la decisión.** Cuando una fila está **denegada y alguna fuente es `deny`**, la celda de resultado añade «Denegado por «{rol}»: una denegación anula cualquier concesión» (el tercer ejemplo de `api.md §7.1`). Cuando está denegada y **todas** sus fuentes `allow` son inertes, añade «Concedido, pero sin efecto» con el motivo. Motivos de inercia traducidos (`RN-PERM-27`) con rama por defecto que muestra el código (`api.md §7.2`: enumerado extensible). Es el «valor entero» de la pantalla (§7.10): **nunca** se presenta una concesión inerte igual que una ausente.
- **`RN-PERM-45` · Sin salida del dato.** Ni exportación, ni impresión dedicada, ni copia al almacenamiento del navegador (`RN-CORE-50`, `permisos.md §2.1`, §5.2: el mapa de capacidades de una persona es información de ataque). En la URL solo el `public_id` del sujeto.
- **`404`** ⇒ «no encontrado» (usuario inexistente, de otro centro o dado de baja). Sin `permiso_efectivo.leer`, la acción de la ficha de usuario no existe y la ruta no se ofrece (`RN-CORE-62`).

### 20.11 Modo `local` de `src/data-table` (ampliaciones del componente)

Primer consumidor real (`OPEN-CORE-26` = B, `ADR-054 §2.1`: «lo añade dentro de `src/data-table` el primer paso que tenga un consumidor real, sin tocar este ADR: es aditivo»). Dos consumidores en 1.5b: concesiones de la ficha de rol y permisos efectivos. El editor de concesiones **no** lo usa (`OPEN-PERM-08` = A: rejilla de edición propia, §20.8.1).

- **`RN-PERM-43` · Cuándo se usa.** Solo sobre colecciones **documentadas como no paginadas** en el `api.md` del recurso (hoy: `GET /permissions`, los dos de permisos efectivos, `GET /modules`) o sobre un **campo de un recurso** (`permissions[]` de un rol, `error_summary` de una importación). **Nunca** para paginar en cliente un *endpoint* paginado pidiendo `per_page=100`. Es el mismo criterio objetivo de `ADR-038 §4.2` aplicado al tercer modo.
- **`RN-PERM-44` · Comportamiento del modo `local`.** Ampliaciones **aditivas**, sin cambiar el comportamiento de `page` ni de `cursor`:

  | # | Ampliación | Contrato |
  |---|------------|----------|
  | E1 | `DataTableMode` gana `'local'`; la función de petición del consumidor devuelve `{ data: Row[] }` (sin `meta`) y recibe solo `{ signal }` | El componente la llama **una vez** al montar y en cada `refresh()`. Solo gana la última respuesta (`RN-CORE-41`) |
  | E2 | Paginación en cliente | Con el mismo paginador, las mismas opciones 25/50/100 (25 por defecto) y `hideSinglePageFooter`. Cambiar orden, filtros o búsqueda vuelve a la página 1. Si la página actual queda fuera de rango, pasa a la última, sin petición |
  | E3 | Ordenación en cliente sobre las columnas `sortable` | Compara el valor de la columna (`value(row)` o `row[id]`) con `Intl.Collator` del idioma activo (`sensitivity: 'base'`, `numeric: true`); los valores vacíos van **al final** en los dos sentidos; desempate estable por `rowKey`. Ciclo de `RN-CORE-39`. El consumidor puede declarar un comparador propio por columna (`compare`), necesario para el orden de dominio de las acciones (§20.8.1) |
  | E4 | Filtros en cliente | `enum` (múltiple y de selección única) y `boolean` (dos y tres estados). En modo `local` cada filtro declara **`rowValue(row)`** (`string \| string[]` para `enum`, `boolean` para `boolean`); una fila pasa un `enum` si su valor (o alguno de sus valores) está entre los elegidos. `dateRange` y `entity` **no** se admiten en `local` en 1.5b: si se declaran, se ignoran y se avisa por consola (mismo mecanismo que `RN-CORE-94`) |
  | E5 | Búsqueda en cliente | Con `searchable`, el consumidor declara `searchText(row)`. Coincide si **cada** término (separado por espacios) aparece como subcadena, sin distinguir mayúsculas ni tildes (normalización NFD sin diacríticos y minúsculas del idioma activo). Se mantiene la espera de `RN-CORE-40` (300 ms) para no anunciar por pulsación. `q` nunca va a la URL (`RN-CORE-54`) |
  | E6 | Anuncios | La región `aria-live` anuncia una vez por resultado aplicado (carga, filtro, búsqueda), con el recuento filtrado («12 de 35 permisos»), plural correcto en los cuatro idiomas |
  | E7 | Estado en la URL | Permitido con las reglas de `RN-CORE-54` (página, `per_page`, orden, enumerados, booleanos; nunca `q`). Ningún consumidor de 1.5b lo declara |
  | E8 | Sin exportación | `exportConfig` con `mode="local"` se ignora y se avisa por consola; los tipos lo impiden donde TypeScript pueda expresarlo (`RN-CORE-46`: la SPA nunca genera ficheros; y los consumidores de 1.5b no exportan, `permisos.md §2.1`) |
  | E9 | Estados | Carga, vacío (con y sin filtros, `§13.10`), error con «Reintentar» (repite la carga). `RN-CORE-45` no aplica |
  | E10 | Celdas con *slots* | Los *slots* por columna (enlaces a la ficha de un rol, lista de procedencia) se pintan **también** en la vista de tarjetas (en el `dd` del campo), con el mismo contenido; el foco y el orden de tabulación siguen el orden del documento. Si hoy no fuera así (§20.0, no comprobado), se amplía de forma aditiva |

  Lo que **no** cambia: el contrato de columnas de `§13.4`, `RN-CORE-37`/`38` (TanStack sigue en un solo fichero; el modo `local` puede usar los modelos de ordenación/filtrado/paginación de cliente de TanStack **dentro** de `src/data-table`, o calcularlos a mano: decisión de implementación), `RN-CORE-43` (preferencias de columnas), `RN-CORE-50` y `RN-CORE-53`.

### 20.12 `RN-CORE-53` y la matriz: modificación APROBADA (2026-10-05)

`REQ-CORE §13.15` y `ADR-054 §1.4` exigen que, si la matriz se construye fuera del componente, **esta especificación proponga modificar `RN-CORE-53` y el usuario lo apruebe**. La lista de excepciones está vacía desde 1.9f y **solo puede reducirse**. **El usuario eligió `OPEN-PERM-08` = A y aprobó expresamente el 2026-10-05 el texto siguiente, tal cual**:

> **Modificación de `RN-CORE-53` — APROBADA expresamente por el usuario el 2026-10-05 (`OPEN-PERM-08` = A)**
>
> Se añade a `RN-CORE-53` un párrafo y una **segunda lista**, distinta de la de excepciones de tablas de datos:
>
> «**Rejillas de edición.** Una rejilla de edición es una vista cuyas celdas son **controles de formulario** que editan un único recurso (no un listado de filas que se leen), y cuyo propósito es comparar ese recurso en dos ejes a la vez. Las rejillas de edición pueden importar `@/components/ui/table`, y solo ellas, si figuran en una **lista cerrada de rejillas de edición** escrita en el propio test, separada de la lista de excepciones de tablas de datos. Cada entrada nombra el fichero, la especificación que la justifica y la aprobación del usuario. **La lista de rejillas solo crece con una especificación aprobada expresamente por el usuario que la nombre**; ninguna sesión de implementación la amplía. La lista de excepciones de tablas de datos sigue vacía y sigue sin poder crecer. Una rejilla de edición no puede contener `<table` crudo ni importar `@tanstack/vue-table` (`RN-CORE-37` no tiene excepciones), y cumple `RN-CORE-44` salvo `rowHeader` único, con `th scope="row"` por recurso y `th scope="col"` por acción.»
>
> Lista inicial: `src/modules/core/components/roles/RolePermissionMatrix.vue` (`REQ-PERM/funcional.md §20.8`, `OPEN-PERM-08` = A).
>
> Cambios asociados: `CA-CORE-200` se reescribe para comprobar las dos listas (la de tablas, subconjunto del conjunto vacío; la de rejillas, igual a la lista aprobada), con casos fijos que prueban que una rejilla no listada falla; `ADR-054 §1.4` no se edita (es inmutable): la precisión se registra aquí y en `REQ-CORE §13.3` como nota.

**Aplicación en 1.5b**: el test de arquitectura de `RN-CORE-53` (`src/data-table/architecture.spec.ts`, `CA-CORE-200`/`CA-CORE-255`) gana la segunda lista con **exactamente** `RolePermissionMatrix.vue`, en el mismo *commit* que crea ese fichero (`CA-PERM-129`). La nota en `REQ-CORE/funcional.md §13.3`/`§13.16` que refleje la regla modificada la escribe quien tenga ese ámbito (§20.21); hasta entonces, **este apartado es la fuente de la regla vigente**.

**Alternativas descartadas** (2026-10-05), conservadas como contexto: **B**, la tabla del componente en modo `local` con una fila por permiso y los controles en las celdas (no modificaba `RN-CORE-53`, pero perdía la lectura en dos ejes); **C**, un formulario agrupado por recurso sin tabla, con la precisión más estricta de prohibir `role="table"`/`grid`/`row` fuera de `src/data-table/**` (accesible, pero sin la comparación entre recursos). Ninguna de las dos se implementa; la precisión de C **no** se añade al test.

### 20.13 Reglas transversales de las pantallas de 1.5b

- **`RN-PERM-26` · Acciones por permiso.** `RN-CORE-61` y `RN-CORE-62` sin cambios: toda acción se muestra si y solo si `/me.permissions` contiene el permiso de su *endpoint*, y ninguna vista pide un *endpoint* cuyo permiso el usuario no tiene. `rol.crear` y `rol.actualizar` no implican `permiso.leer` ni `rol.leer`, y cada pantalla lo trata por separado (`RN-PERM-25`).
- **`RN-PERM-46` · Nada por código de rol.** Ninguna decisión de la interfaz compara un código de rol (`administrador_centro`, `orientador`…) (`RN-CORE-23`, `CA-CORE-102`, regla 6 de la *skill* `permisos-y-roles`): se decide por permiso, por `is_system`, por `special_data_access` o por identidad (`/me.roles[].public_id`). Funciona igual sobre un rol que todavía no existe.
- **`RN-PERM-27` · Vocabulario cerrado traducido en cliente.** Acciones (`RPERM-003`, nueve), ámbitos (`RPERM-004`, seis), efectos (`allow`/`deny`), decisiones (`permitido`/`denegado`) y motivos de inercia (cuatro, extensible) se traducen en el cliente en `core.permissions.vocabulary.*`, en `es`, `en`, `de` y `fr`, con **rama por defecto que muestra el código** (`ADR-038 §7.3`). El valor técnico nunca se traduce (`ADR-038 §3.2`).
- **`RN-PERM-28` · Etiqueta del recurso** (`OPEN-PERM-09` = A). `resource_label` tal como llega del servidor (S-PERM-2), y toda vista que lo muestre vuelve a pedir sus datos al cambiar de idioma (`RN-CORE-63`). Si una respuesta no lo trae (durante un despliegue escalonado), rama por defecto: el código del recurso en crudo; **nunca** un catálogo de recursos de otros módulos escrito en `src/modules/core` (`INV-007`).
- **`RN-PERM-40` · Roles del sistema** (`OPEN-PERM-10` = B). El nombre de un rol `is_system` no se edita (`name_key`, `INV-009`) y el rol no se elimina (`RN-PERM-16`). Sus **concesiones** se muestran en **solo lectura** en la interfaz, con el texto «Los roles del sistema no se modifican desde aquí. Clónalo para crear una versión propia», y la acción «Clonar» destacada. La API sigue admitiendo esas escrituras (la interfaz no es seguridad, `INV-002`); lo que protege al centro es `RN-PERM-47`.
- **Estados**: componentes y correspondencia de errores de `REQ-CORE §12.6`/`§13.10`; una ficha cuyo `GET` responde `404` pinta «no encontrado» (`CA-CORE-263`).
- **Privacidad**: `RN-CORE-50` se extiende a todas las pantallas de 1.5b (ni concesiones, ni permisos efectivos, ni el estado del editor en ningún almacenamiento del navegador ni en la URL). Las únicas escrituras en `localStorage` son las claves `plataforma.table.core.role_grants` y `plataforma.table.core.effective_permissions` (`RN-CORE-43`, sin datos de fila); la matriz de concesiones no guarda nada (no es una tabla del componente).
- **i18n** (`INV-009`): todo texto nuevo en `core.roles.*` (listado, ficha, alta, clonación, edición, baja, editor), `core.effectivePermissions.*` y `core.permissions.vocabulary.*`, en `es`, `en`, `de` y `fr`, con `useT` (nunca `vue-i18n` directo, #259); fechas y cifras con los formateadores de `src/data-table`. Las claves nuevas del componente en `dataTable.*` (anuncio «{n} de {total}», avisos del modo `local` si los hubiera) también en los cuatro idiomas. Pasa `npm run lint:i18n`.
- **Accesibilidad (WCAG 2.2 AA)**: semántica de tabla de `RN-CORE-44` (en la matriz, con la salvedad de §20.8.1); panel de edición de celda con foco atrapado y devuelto al botón de la celda; todo control de fila o de celda con nombre accesible que incluye la identidad de la fila (2.4.6); grupos de opciones con nombre de grupo; opciones deshabilitadas con su motivo en el nombre accesible y nota de fila con `aria-describedby` (1.3.1, 3.3.2); nada distinguido solo por color —modificado, denegado, inerte, categoría especial— (1.4.1); errores con `role="alert"`, foco al resumen y `aria-invalid` (3.3.1, 3.3.3); confirmaciones con foco atrapado y devuelto (2.4.3); objetivos táctiles ≥ 44 px en puntero grueso (`RUX-RESP-007`); sin arrastre (2.5.7); sin desplazamiento horizontal de la página a 320 px (1.4.10).

### 20.14 Cambios de servidor

**En alcance de 1.5b** (decisión del usuario, 2026-10-05; detalle en `api.md §14`):

| # | Cambio | Criterios |
|---|--------|-----------|
| S-PERM-1 | `users_count` en `GET /roles/{public_id}` y en las respuestas de escritura del rol (corrige la contradicción con `api.md §2.2`) | `CA-PERM-135` |
| S-PERM-2 | `resource_label` traducido por el módulo dueño en `GET /permissions`, permisos efectivos y `permissions[]` del rol | `CA-PERM-134` |
| `RN-PERM-47` | El centro nunca pierde la capacidad completa de administración (§20.2.1) | `CA-PERM-046` a `-049` |
| #170 | Test que fija el comportamiento estricto de `RPERM-013` (sin cambio de código de producción) | `CA-PERM-045` |

**Fuera**: S-PERM-3 (concurrencia optimista en servidor; `OPEN-PERM-12` = A, se resuelve en cliente). Ninguno de los cuatro exige migración.

### 20.15 Índice de reglas de 1.5b

| ID | Regla | Dónde |
|----|-------|-------|
| `RN-PERM-24` | **(servidor)** `RPERM-013` en el reemplazo de concesiones: toda `allow` nueva o cambiada exige poseer el ámbito nuevo; idénticas, retiradas y `deny`, nunca (#170) | §20.2 |
| `RN-PERM-25` | Rutas en `core/shell.ts`; permiso de ruta = permiso principal; estado propio si falta un permiso de lectura auxiliar | §20.3 |
| `RN-PERM-26` | Acciones por permiso; sin peticiones a ciegas | §20.13 |
| `RN-PERM-27` | Vocabulario cerrado traducido en cliente, con rama por defecto | §20.13 |
| `RN-PERM-28` | Etiqueta del recurso: `resource_label` del servidor (S-PERM-2) | §20.13 |
| `RN-PERM-29` | Alta de rol | §20.6 |
| `RN-PERM-30` | Clonación de rol | §20.6 |
| `RN-PERM-31` | `special_data_access`: permiso, posesión derivada, servidor como autoridad | §20.6 |
| `RN-PERM-32` | Modelo de edición: instantánea, tres estados, conjunto completo, intactas idénticas | §20.8.2 |
| `RN-PERM-33` | Ámbitos disponibles, guardados y no disponibles con motivo (UX de #170) | §20.8.3 |
| `RN-PERM-34` | Denegar: «cualquier ámbito», `scope: todos`, siempre disponible | §20.8.2 |
| `RN-PERM-35` | Avisos de inercia (categoría especial, módulo) en la matriz | §20.8.4 |
| `RN-PERM-36` | Guardado: confirmación con resumen, `403`/`422` por fila, cambios conservados | §20.8.5 |
| `RN-PERM-37` | Concurrencia: comprobación previa en cliente, sin `PUT` si el rol cambió | §20.8.6 |
| `RN-PERM-38` | Cambios sin guardar: guarda interna y `beforeunload` solo con cambios | §20.8.7 |
| `RN-PERM-39` | Baja de rol | §20.9 |
| `RN-PERM-40` | Roles del sistema: concesiones en solo lectura en la interfaz; se clonan | §20.13 |
| `RN-PERM-41` | Pantalla de permisos efectivos | §20.10 |
| `RN-PERM-42` | Procedencia que explica la decisión; inerte ≠ ausente | §20.10 |
| `RN-PERM-43` | Modo `local`: solo colecciones sin paginar o campos de un recurso | §20.11 |
| `RN-PERM-44` | Modo `local`: ampliaciones E1-E10 | §20.11 |
| `RN-PERM-45` | Permisos efectivos sin exportación ni almacenamiento | §20.10 |
| `RN-PERM-46` | Ninguna decisión de interfaz por código de rol | §20.13 |
| `RN-PERM-47` | **(servidor)** Ninguna escritura del centro deja sin titular completo (un mismo usuario activo con todo el conjunto de administración sembrado, `todos`, efectivo) a un centro que lo tenía: `409` | §20.2.1 |

### 20.16 Casos límite

| Situación | Comportamiento |
|-----------|----------------|
| El solicitante edita un rol que concede permisos que él no tiene | Esas filas conservan su valor (enviado idéntico, no comprobado); solo puede retirarlas o denegarlas (`RN-PERM-33`) |
| El solicitante cambia una fila no poseída a otro ámbito y la devuelve a su valor antes de guardar | Cuenta como no modificada y se envía idéntica (`RN-PERM-32`) |
| El solicitante se retira a sí mismo `rol.actualizar` editando un rol del que es titular | Aviso en la confirmación (`RN-PERM-36`). El servidor lo permite **si** queda otro usuario activo con el conjunto completo de administración; si no, `409` (`RN-PERM-47`) |
| Se retira o deniega un permiso de administración a un rol personalizado que tiene el único administrador activo con todo el conjunto | `409` con `errors.administration_capacity[0].params.codes` (`RN-PERM-47`); nada se guarda |
| Se asigna a todos los administradores un rol con `deny` de `usuario.crear` | `PUT /users/{id}/roles` del último que conservaba el conjunto completo ⇒ `409` (`RN-PERM-47`). El `deny` cuenta aunque no se compruebe con `RPERM-013` |
| Se desactiva o se da de baja al único usuario con el conjunto completo, habiendo otros `administrador_centro` a los que les falta algún permiso | `RN-CORE-07` lo permitiría (sigue habiendo titular del rol); `RN-PERM-47` responde `409` |
| Centro recién aprovisionado cuyo administrador sigue `pendiente` (invitación sin canjear) | El centro **no** cumple todavía; ninguna escritura se rechaza por `RN-PERM-47` (solo se rechaza pasar de cumplir a no cumplir). Cumple en cuanto el administrador queda `activo` |
| Dos administradores retiran a la vez permisos que, juntos, dejarían al centro sin titular completo | Las escrituras se serializan por tenant; la segunda ve el estado de la primera y responde `409` (§20.2.1, concurrencia) |
| Se descontrata desde la plataforma un módulo cuyos permisos estaban en el conjunto | Salen del conjunto (no utilizables): la regla no bloquea por ellos |
| Otro administrador cambia el rol mientras se edita | `RN-PERM-37` |
| Un ámbito pasa a tener resolutor (módulo nuevo desplegado) con el editor abierto | Sigue deshabilitado hasta recargar; el `PUT` lo aceptaría (§20.8.3, punto 5) |
| Un permiso se retira del catálogo con el editor abierto | El `PUT` responde `422 permission_retired` en esa fila (`RN-PERM-36`, punto 6) |
| El rol se elimina con el editor abierto | `404` ⇒ «no encontrado» |
| Concesión de categoría especial en un rol sin el atributo | Se guarda, con aviso de inercia (`RN-PERM-35`) |
| Clonar `orientador` siendo `administrador_centro` | `422 clone_requires_special_data_access` con su mensaje; no se crea nada (§7.5) |
| Más de 100 roles en el centro | El selector de clonación no existe (se clona desde la ficha); la derivación de `RN-PERM-31` pide páginas de `GET /roles` hasta cubrir los roles propios o, si el servidor no admite `per_page` 100, se queda en «desconocido» y el control se habilita |
| Permisos efectivos de un usuario sin roles | Todas las filas «Denegado» sin procedencia; con el filtro por defecto, estado vacío con su texto |
| Usuario con `deny` y `allow` del mismo código | «Denegado por «{rol}»…» (`RN-PERM-42`) |
| Cambio de idioma con el editor abierto y cambios sin guardar | Se retraducen etiquetas; la recarga de `GET /permissions` (por `resource_label`, `RN-CORE-63`) **no** toca el estado de edición, que es por `code` |
| `GET /me/effective-permissions` falla al abrir el editor | Estado de error con «Reintentar»; el editor no se pinta sin esa información (`RN-PERM-33`, punto 4) |
| 320 px | Ficha y permisos efectivos: tarjetas. Matriz: desplazamiento horizontal interno con la columna de recurso fija; el panel de edición de la celda ocupa la pantalla. Nunca desplazamiento horizontal de la página |

### 20.17 Criterios de aceptación

Vitest salvo los marcados **[Pest]** o **[Playwright]**. Cada test cita su ID (`INV-015`). Todos son firmes: las preguntas de las que dependían están resueltas (2026-10-05).

#### 20.17.1 Servidor (#170, `RN-PERM-47`)

- **`CA-PERM-045`** [Pest] [`RPERM-013`, `RN-PERM-24`, issue #170] · **Dado** un rol `R` con `auditoria.leer` `allow` `todos` (sembrado sin pasar por la API) y un solicitante con `rol.actualizar` **sin ninguna** concesión de `auditoria.leer`, **cuando** envía `PUT /roles/R/permissions` con `auditoria.leer` `allow` `propios`, **entonces** `403` con `type` `urn:pge:error:forbidden`, `errors.grant` con exactamente una entrada cuyo `code` es `core.authorization.cannot_grant_unheld_permission`, `message` no vacío y `params` exactamente `{code: "auditoria.leer", scope: "propios"}`, **sin** clave `params` de primer nivel en el cuerpo, y la fila de `R` sigue siendo `allow` `todos` (sin fila nueva en `audit_logs`); **y cuando** envía el mismo conjunto con `auditoria.leer` `allow` `todos` **idéntico** más un `deny` nuevo de `usuario.eliminar`, **entonces** `200` (la idéntica no se comprueba y el `deny` tampoco); **y cuando** envía el conjunto sin `auditoria.leer`, **entonces** `200` y la concesión queda retirada; **y dado** un solicitante con `auditoria.leer` `allow` `propios` (y sin `todos`), **cuando** estrecha `R` de `todos` a `propios`, **entonces** `200`; **y cuando** ese mismo solicitante intenta pasar una fila `deny` de `auditoria.leer` a `allow` `todos`, **entonces** `403`.
- **`CA-PERM-046`** [Pest] [`RN-PERM-47`] · **Dado** un centro con un único usuario activo, Ana, titular de `administrador_centro` recién sembrado, y un rol personalizado `gestion` concedido por ella con un subconjunto de sus permisos, **cuando** Ana envía `PUT /roles/{administrador_centro}/permissions` sin `rol.actualizar`, **entonces** `409` con `type` `urn:pge:error:conflict`, `errors.administration_capacity[0].code` = `core.validation.administration_capacity_lost` y `errors.administration_capacity[0].params.codes` igual a `["rol.actualizar"]` (lista JSON), y las concesiones del rol no cambian (ninguna fila nueva en `audit_logs`); **y cuando** envía el mismo cuerpo pasando `usuario.crear` a `deny`, **entonces** `409` con `params.codes` igual a `["usuario.crear"]`; **y cuando** retira a la vez `rol.actualizar` y `usuario.crear`, `params.codes` es `["rol.actualizar","usuario.crear"]` (unión ordenada); **y dado** un segundo usuario activo, Luis, titular también de `administrador_centro`, la primera petición **sigue** respondiendo `409` (el rol es el mismo y ambos lo pierden); **y dado** que Luis tiene además un rol personalizado con **todos** los permisos del conjunto con `todos`, la petición responde `200`.
- **`CA-PERM-047`** [Pest] [`RN-PERM-47`, `RN-CORE-07`] · **Dado** dos usuarios activos con el rol `administrador_centro`, Ana y Luis, y un rol personalizado `restriccion` con un único `deny` de `mfa.eliminar`, **cuando** Ana asigna `restriccion` a Luis con `PUT /users/{luis}/roles`, **entonces** `200` (Ana conserva el conjunto completo); **y cuando** después Luis asigna `restriccion` a Ana, **entonces** `409` con `errors.administration_capacity[0].params.codes` igual a `["mfa.eliminar"]`, y los roles de Ana no cambian; **y dado** ese mismo estado (Luis con `restriccion`, Ana con el conjunto completo), **cuando** Luis desactiva a Ana con `POST /users/{ana}/status`, **entonces** `409`, aunque `RN-CORE-07` lo permitiría (Luis sigue siendo un `administrador_centro` activo), y `params.codes` es la lista **completa** del conjunto (una usuaria desactivada no posee ninguno); **y** `DELETE /users/{ana}` responde igual; **y** reenviar a Luis su mismo conjunto de roles (`PUT` sin cambio efectivo), reactivar a alguien o un `PATCH /roles/{id}` que envía `special_data_access` con el valor que ya tenía **no** pasan por la comprobación (responden como sin esta regla).
- **`CA-PERM-048`** [Pest] [`RN-PERM-47`] · **Dado** un centro recién aprovisionado cuyo único administrador está `pendiente`, **cuando** se escribe cualquier concesión de un rol personalizado, **entonces** la escritura no se rechaza por `RN-PERM-47`; **y dado** un permiso del conjunto marcado `retired_at` o de un módulo de prueba desactivado, **cuando** se retira del único titular completo, **entonces** `200` (no forma parte del conjunto efectivo).
- **`CA-PERM-049`** [Pest] [`RN-PERM-47`, concurrencia] · **Dado** dos usuarios activos con el conjunto completo y dos peticiones simultáneas que retiran, cada una, el conjunto a uno de ellos, **cuando** se ejecutan en paralelo (dos conexiones, transacciones solapadas), **entonces** exactamente una responde `200` y la otra `409`, y al terminar el centro sigue cumpliendo la regla. *(Si el entorno de test no permite solapar transacciones de forma fiable, `implementer` lo reporta en lugar de omitirlo, y el criterio se cubre con un test del mecanismo de serialización. **Estado real**: el test implementado cubre el mecanismo —el bloqueo `pg_advisory_xact_lock` por tenant lo retiene `protect()` mientras escribe y no afecta a otro tenant— y una escritura secuencial que ve el estado de la anterior; **no** solapa dos transacciones reales. **Actualizado el 2026-10-06 (issue #351)**: `tests/Concurrency/RealConcurrencyTest.php` solapa dos procesos PHP reales (conexión y transacción propias, datos confirmados y limpiados al terminar) y verifica que de dos bajas de los dos únicos titulares completos una se hace y la otra responde `409 administration_capacity_lost`; la sincronización usa `pg_locks` (sin `sleep` de duración fija). Cubre las **bajas**; el solapamiento real de dos `PUT /roles/{id}/permissions` no se prueba, solo el determinista de `ConcurrentSnapshotTest`.)*

#### 20.17.2 Navegación y permisos de pantalla

- **`CA-PERM-100`** [`RN-PERM-25`, `ADR-053 §2`] · **Dado** el registro ensamblado, **entonces** existen las seis rutas nuevas de §20.3 con `meta.layout === 'app'` y `meta.permissions` exactamente igual a la columna 2; la constante de rutas con `[]` del test de coherencia sigue teniendo **siete** entradas; y los cinco tests de `ADR-053 §2` siguen en verde.
- **`CA-PERM-101`** [`RN-PERM-26`, sustituye a `CA-CORE-240`] · **Dado** `/administracion/roles` con `rol.leer` y sin `rol.crear`, **entonces** no hay «Nuevo rol» y cada nombre es un enlace a su ficha; **y con** `rol.crear`, existe «Nuevo rol» y lleva a `core-role-new`. El test de `CA-CORE-240` queda retirado en el mismo *commit*, con referencia a este criterio.
- **`CA-PERM-102`** [`RN-PERM-26`, `RN-PERM-40`, `RN-CORE-62`] · **Dado** la ficha de un rol del sistema y un usuario con `rol.leer`, `rol.actualizar`, `rol.crear` y `rol.eliminar` y sin `permiso.leer`, **entonces** no existe «Eliminar», el nombre no es editable en `core-role-edit`, existe «Clonar», **no** sale ninguna petición a `GET /permissions` y la columna «Categoría especial» no se pinta; **y dado** un usuario con solo `rol.leer`, ninguna acción de escritura está en el documento.
- **`CA-PERM-103`** [`RN-PERM-25`] · **Dado** un usuario con `rol.actualizar` sin `permiso.leer`, **cuando** abre `core-role-permissions`, **entonces** no se pide `GET /permissions` ni `PUT`, y la vista muestra el estado «necesitas además» con el nombre del permiso que falta.

#### 20.17.3 Alta, clonación y edición

- **`CA-PERM-104`** [`RN-PERM-29`, `RPERM-005`, `RPERM-014`] · **Dado** el alta con nombre «Coordinación de auditoría», **entonces** el código propuesto es `coordinacion_de_auditoria`; **cuando** se guarda con `mfa_required` marcado, el cuerpo de `POST /roles` es exactamente `{"code":"coordinacion_de_auditoria","name":"Coordinación de auditoría","mfa_required":true}` (sin `is_system`, `name_key`, `special_data_access` ni `permissions` con concesiones); **cuando** responde `422` con `errors.code[0].code = core.validation.role_code_taken`, el mensaje aparece bajo el campo con `aria-invalid="true"` y el foco va a él; **y cuando** responde `201`, se navega a `core-role-permissions` del rol creado (`OPEN-PERM-16` = A).
- **`CA-PERM-105`** [`RN-PERM-31`, `RN-PERM-11`] · **Dado** un usuario sin `rol_datos_especiales.actualizar`, **entonces** el alta y la edición no contienen el control de `special_data_access`; **dado** uno con el permiso y `rol.leer` cuyos roles de `/me` tienen todos `special_data_access = false` en `GET /roles`, **entonces** el control está deshabilitado con el texto traducido de no posesión; **y dado** uno con el permiso y sin `rol.leer`, el control está habilitado, no se pide `GET /roles`, y un `403` con `detail` `core.authorization.special_data_access_not_held` se muestra junto al control con `role="alert"`.
- **`CA-PERM-106`** [`RN-PERM-30`, `RPERM-006`] · **Dado** la clonación de un rol con `special_data_access = true`, **entonces** se muestra el aviso previo; **cuando** se guarda, el cuerpo de `POST /roles` contiene exactamente `clone_from`, `code` y `name`; **cuando** responde `422 clone_requires_special_data_access`, se muestra el mensaje del servidor y no se navega; **y cuando** responde `403` con `errors.grant[0]` = `{code: "core.authorization.cannot_grant_unheld_permission", message, params: {code: "auditoria.leer", scope: "todos"}}`, el texto muestra el permiso con su etiqueta y el ámbito traducido, y dice que no se ha creado nada.
- **`CA-PERM-107`** [`RN-PERM-29`, `RN-CORE-65`, `ADR-038 §9.2`] · **Dado** la edición de un rol personalizado en la que solo cambia el nombre, **cuando** se guarda, **entonces** el cuerpo de `PATCH /roles/{id}` es exactamente `{"name":"…"}` y nunca contiene `code` ni `permissions`.

#### 20.17.4 Editor de concesiones

- **`CA-PERM-108`** [`RN-PERM-32`, `RN-PERM-34`] · **Dado** un rol con `auditoria.leer` `allow` `propios` y `configuracion.actualizar` `deny` `todos`, **cuando** se pasa `usuario.leer` a «Permitir» con `todos`, se pasa `configuracion.actualizar` a «Sin conceder» y se guarda confirmando, **entonces** el cuerpo del `PUT` contiene exactamente `{auditoria.leer, allow, propios}` (idéntica a la instantánea) y `{usuario.leer, allow, todos}`, y no contiene `configuracion.actualizar`; **y dado** que se elige «Denegar» en el panel de una celda, el selector de ámbito desaparece, aparece «Cualquier ámbito» con su explicación y la entrada se envía con `scope: "todos"`.
- **`CA-PERM-109`** [`RN-PERM-33`, issue #170, `CA-PERM-045`] · **Dado** un solicitante cuyo `/me/effective-permissions` tiene `auditoria.leer` `permitido` con `scopes: ["propios"]` y `unrestricted: false`, un catálogo con `auditoria.leer` `applicable_scopes` `["todos","propios","grupo"]` y `grantable_scopes` `["todos","propios"]`, y un rol cuya instantánea es `auditoria.leer` `allow` `todos`, **entonces** en esa fila: `todos` está seleccionado y marcado «(actual)» y es seleccionable; `propios` está disponible; `grupo` está deshabilitado con el texto de «aún no existe» en su nombre accesible; **y dado** el mismo rol sin concesión de `auditoria.leer`, `todos` está deshabilitado con el texto de «no lo tienes»; **y dado** un código que el solicitante no posee con ningún ámbito y que el rol no concede, «Permitir» está deshabilitado, «Denegar» y «Sin conceder» están habilitados, y la nota de la fila con el texto «Solo puedes denegar o dejar sin conceder…» está asociada al grupo por `aria-describedby`; **y** la leyenda de la regla está en el documento.
- **`CA-PERM-110`** [`RN-PERM-33`] · **Dado** un solicitante cuya fila de un código tiene una fuente `deny`, **entonces** la nota de esa fila dice que lo tiene vetado por una denegación; **y dado** uno cuya única fuente es `allow` inerte por `inerte_datos_especiales`, la nota dice que su concesión no surte efecto con el motivo traducido.
- **`CA-PERM-111`** [`RN-PERM-36`] · **Dado** cambios sin guardar, **cuando** el `PUT` responde `403` con `errors.grant[0].code` = `core.authorization.cannot_grant_unheld_permission` y `errors.grant[0].params.code` = `"auditoria.leer"`, **entonces** el `detail` aparece en el resumen con `role="alert"` y recibe el foco, la fila de `auditoria.leer` muestra el error y su grupo lleva `aria-invalid="true"`, y **todos** los cambios sin guardar siguen en pantalla; **y dado** un `422` con `errors["permissions.1.scope"]`, el mensaje aparece en la fila del código que ocupaba la posición 1 del cuerpo enviado.
- **`CA-PERM-112`** [`RN-PERM-36`, `RN-CORE-64`] · **Dado** dos concesiones nuevas, un cambio de ámbito, una denegación y una retirada, con `users_count = 7`, **cuando** se pulsa «Guardar», **entonces** no sale el `PUT` hasta confirmar; el diálogo muestra los cuatro recuentos, la lista con etiquetas y «7»; `Esc` lo cierra sin petición y devuelve el foco a «Guardar»; **y dado** que `/me.roles` contiene el `public_id` del rol, el diálogo incluye el aviso de titularidad. **Y dado** que no hay cambios, «Guardar» está deshabilitado y no sale ninguna petición.
- **`CA-PERM-137`** [`RN-PERM-36` punto 8, `RN-PERM-47`] · **Dado** cambios sin guardar, **cuando** el `PUT` responde `409` con `errors.administration_capacity[0].code` = `core.validation.administration_capacity_lost` y `errors.administration_capacity[0].params.codes` = `["rol.actualizar"]`, **entonces** el resumen muestra el `detail` del servidor con `role="alert"`, la etiqueta de «Roles · actualizar» y el texto de cómo resolverlo, el foco va al resumen y todos los cambios sin guardar siguen en pantalla; **y** la interfaz no deshabilita de antemano ninguna celda por esta regla (`RN-CORE-61`).
- **`CA-PERM-113`** [`RN-PERM-37`, `OPEN-PERM-12` = A] · **Dado** cambios sin guardar, **cuando** se pulsa «Guardar» y el `GET /roles/{id}` previo devuelve `permissions[]` distinto de la instantánea, **entonces** no se abre la confirmación ni sale ningún `PUT`, aparece el aviso con la lista de códigos cambiados, y «Recargar» pide confirmación antes de descartar.
- **`CA-PERM-114`** [`RN-PERM-35`, `RN-PERM-10`] · **Dado** un rol con `special_data_access = false` y un permiso de prueba `is_special_category`, **cuando** su fila pasa a «Permitir», **entonces** aparece el aviso de inercia en texto y el guardado no se bloquea; **y dado** el mismo permiso en un rol con el atributo, el aviso no aparece.
- **`CA-PERM-115`** [`RN-PERM-38`, `OPEN-PERM-15` = A] · **Dado** cambios sin guardar, **cuando** se navega a otra ruta, **entonces** aparece la confirmación y cancelar mantiene la ruta y los cambios; **y** hay un manejador de `beforeunload` registrado mientras haya cambios y **ninguno** tras guardar o descartar.
- **`CA-PERM-116`** [`RN-PERM-32`, `RN-CORE-50`, §20.8.1] · **Dado** el editor con cambios en la matriz de `core`, **cuando** se elige el módulo `auth`, se busca un recurso y se vuelve a «todos», **entonces** los cambios siguen marcados («Modificado» en texto en el botón de la celda) y el contador no cambia; **cuando** se abre el panel de una celda, se cambia el estado y se pulsa «Cancelar» (o `Esc`), **entonces** la celda no cambia y el foco vuelve a su botón; **y** ningún almacenamiento del navegador contiene códigos de permiso ni el estado de edición.
- **`CA-PERM-136`** [§20.8.1, `RN-CORE-44`, `RN-CORE-53` modificada] · **Dado** el editor con un catálogo de prueba de dos módulos, **entonces** hay una matriz por módulo con `caption`; las columnas son exactamente las acciones presentes en ese módulo, en el orden de `RPERM-003`, con `th scope="col"`; cada recurso es un `th scope="row"`; un par recurso × acción inexistente contiene el valor vacío común y ningún control; cada celda con permiso contiene un único botón cuyo nombre accesible incluye recurso, acción y estado; **y** no hay `role="grid"` ni `<table` crudo.
- **`CA-PERM-117`** [`RN-PERM-40`, `OPEN-PERM-10` = B] · **Dado** la ficha de un rol `is_system`, **entonces** no existe «Editar concesiones» y aparece el texto que remite a «Clonar»; **y** la ruta `core-role-permissions` de un rol del sistema pinta la matriz en solo lectura sin «Guardar».

#### 20.17.5 Baja

- **`CA-PERM-118`** [`RN-PERM-39`, `RN-PERM-17`] · **Dado** un rol personalizado con `users_count = 3`, **entonces** «Eliminar» está deshabilitado con el texto traducido con «3» y el enlace a los usuarios con `role=<public_id>`; **dado** `users_count = 0`, al pulsar «Eliminar» no sale ninguna petición hasta confirmar, y con `204` se navega al listado con mensaje `role="status"`; **y dado** un `409` con `errors.role[0].code` = `core.validation.role_has_assignments` y `errors.role[0].params.users_count` = `2`, se muestra el `detail` con `role="alert"` y el texto con «2»; **y** [Pest] `DELETE /roles/{id}` de un rol con dos titulares responde exactamente esa forma, sin `params` de primer nivel.

#### 20.17.6 Permisos efectivos

- **`CA-PERM-119`** [`RN-PERM-41`, `RPERM-009`] · **Dado** un usuario con `permiso_efectivo.leer` en la ficha de otro usuario, **entonces** existe «Ver permisos efectivos»; al abrirla sale **exactamente una** petición `GET /users/{id}/effective-permissions` sin parámetros de paginación, y la cabecera muestra el nombre, los roles y la fecha de cálculo formateada; **y sin** el permiso, la acción no está y no sale ninguna petición.
- **`CA-PERM-120`** [`RN-PERM-42`, `RPERM-007`] · **Dado** la respuesta de ejemplo de `api.md §7.1`, **entonces** `auditoria.leer` muestra «Permitido», el ámbito «Propios» y su fuente; `salud.leer` muestra «Denegado», «Concedido, pero sin efecto» y el motivo traducido de `inerte_datos_especiales`; `configuracion.actualizar` muestra «Denegado por «Cuenta restringida»…» y las dos fuentes; **y** un `inert_reason` desconocido se muestra en crudo sin error.
- **`CA-PERM-121`** [`RN-PERM-41`, `RN-PERM-44` E4/E5] · **Dado** una respuesta con 40 filas de las que 3 tienen fuentes, **entonces** se ven 3 filas y el anuncio dice «3 de 40»; **cuando** se marca «Incluir permisos sin ninguna concesión», se ven 40 **sin nueva petición**; **y cuando** se busca «audito» (sin tilde ni mayúsculas), quedan las filas de auditoría.
- **`CA-PERM-122`** [`RN-PERM-45`, `RN-CORE-50`] · **Dado** la pantalla de permisos efectivos, **entonces** no existe control de exportación, la URL no cambia al filtrar, buscar u ordenar, y ningún almacenamiento del navegador contiene datos de filas; **cuando** se cambia el idioma, sale exactamente una petición nueva (`RN-CORE-63`); **y cuando** la petición responde `404`, se pinta «no encontrado».

#### 20.17.7 Modo `local` de `src/data-table`

Con tablas de prueba, como en 1.9.

- **`CA-PERM-123`** [`RN-PERM-44` E1/E2] · **Dado** una tabla de prueba `mode="local"` con 120 filas, **entonces** la función de petición se llama una vez, se pintan 25 filas y «página 1 de 5»; al elegir 50 por página, «página 1 de 3»; cambiar de página, de `per_page`, de orden o de filtro **no** llama a la función de petición; **y** `refresh()` la llama exactamente una vez más.
- **`CA-PERM-124`** [`RN-PERM-44` E3] · **Dado** valores «Álvaro», «alba», «Zoe», vacío y «10», «9», **cuando** se ordena ascendente y descendente, **entonces** el orden sigue `Intl.Collator` del idioma activo con `numeric` («9» antes que «10»), «alba» y «Álvaro» juntos, y el vacío **último en los dos sentidos**; **y** con un `compare` declarado, se usa ese.
- **`CA-PERM-125`** [`RN-PERM-44` E4] · **Dado** un `enum` local con `rowValue` múltiple y un `boolean` de dos estados, **entonces** filtran por la función declarada; **y dado** un `dateRange` o un `entity` declarado en una tabla `local`, se ignora y se emite un aviso por consola, sin error.
- **`CA-PERM-126`** [`RN-PERM-44` E5/E6, `RN-CORE-40`] · **Dado** cinco pulsaciones en la búsqueda con 50 ms entre ellas, **entonces** el filtrado y el anuncio ocurren una sola vez, 300 ms después de la última, con el recuento correcto en `es`, `en`, `de` y `fr`.
- **`CA-PERM-127`** [`RN-PERM-44` E8, `RN-CORE-46`] · **Dado** una tabla `local` con `exportConfig`, **entonces** no hay control de exportación y se avisa por consola.
- **`CA-PERM-128`** [§20.8.1, `ADR-054 §4`, `RUX-RESP-004`, `RUX-RESP-007`, WCAG 1.4.10/2.1.1] **[Playwright]** · **Dado** el editor de concesiones a 320 px con puntero grueso, **entonces** existe la `table` de cada módulo (no tarjetas), `scrollWidth ≤ clientWidth` del documento, el contenedor de cada matriz es enfocable con `Tab`, tiene nombre accesible traducido y, con el foco en él, la flecha derecha aumenta su `scrollLeft`; la columna de recurso sigue visible tras desplazar; los botones de celda miden al menos 44 × 44 px; y el panel de edición de una celda se abre, se recorre con teclado y devuelve el foco al cerrarse.
- **`CA-PERM-129`** [`RN-CORE-37`, `RN-CORE-53` modificada (§20.12), `CA-CORE-200`, `CA-CORE-255`] · **Dado** el repositorio tras 1.5b, **entonces** el test de arquitectura de `RN-CORE-53` tiene dos listas: la de excepciones de tablas de datos, **vacía** y sin poder crecer, y la de rejillas de edición, **exactamente** `src/modules/core/components/roles/RolePermissionMatrix.vue`; la rejilla importa `@/components/ui/table` y no importa `@tanstack/vue-table` ni contiene `<table`; con casos fijos que prueban que el test falla ante una rejilla no listada que importe `@/components/ui/table`, ante una segunda entrada añadida a la lista de rejillas sin cambiar la constante aprobada, y ante una entrada de la lista que ya no importe `@/components/ui/table`; **y** todas las tablas `page`/`cursor`/`local` existentes mantienen sus tests en verde.

#### 20.17.8 Transversales

- **`CA-PERM-130`** [`RN-PERM-27`, `INV-009`] · **Dado** los cuatro `locales/*.json`, **entonces** las nueve acciones, los seis ámbitos, los dos efectos, las dos decisiones y los cuatro motivos de inercia tienen etiqueta en `es`, `en`, `de` y `fr`; toda clave nueva de 1.5b existe en los cuatro; `npm run lint:i18n` termina sin hallazgos; **y** un valor de cualquiera de esos vocabularios desconocido se pinta en crudo.
- **`CA-PERM-131`** [`RN-PERM-46`, `RN-CORE-23`, `CA-CORE-102`] · **Dado** `src/` salvo tests, **entonces** `CA-CORE-102` sigue en verde: ningún fichero nuevo de 1.5b contiene un literal de código de rol.
- **`CA-PERM-132`** [`RUX-004`, WCAG 2.2 AA] **[Playwright]** · **Dado** el alta, la ficha, el editor y los permisos efectivos, **cuando** se recorren solo con teclado, **entonces** todo control se alcanza y acciona en orden de documento, las opciones deshabilitadas se anuncian con su motivo (comprobado en el árbol de accesibilidad), los diálogos atrapan y devuelven el foco, y nada queda por debajo de 44 × 44 px con puntero grueso.
- **`CA-PERM-133`** [`RPERM-005`, `RPERM-009`, `RPERM-013`] **[Playwright]**, contra la API real · **Dado** un administrador recién aprovisionado, **cuando** crea el rol «Revisión propia», le concede `auditoria.leer` con `propios`, se lo asigna a un usuario desde su ficha y abre sus permisos efectivos, **entonces** `auditoria.leer` aparece «Permitido» con «Propios» y procedencia «Revisión propia»; **y** en el editor del mismo rol, `rol_datos_especiales.actualizar` del administrador permite «Permitir» con `todos`, mientras que el control de `special_data_access` en la edición del rol está deshabilitado (el administrador no posee el atributo, `permisos.md §5`).
- **`CA-PERM-134`** [`RN-PERM-28`, S-PERM-2, `OPEN-PERM-09` = A] · **Dado** el catálogo, **entonces** [Pest] todo recurso de `GET /permissions` trae `resource_label` no vacío en `es`, `en`, `de` y `fr` y lo mismo en las filas de los permisos efectivos y en `permissions[]` de `GET /roles/{id}`; **y** [Vitest] la interfaz pinta `resource_label` tal cual y, si falta, el código en crudo.
- **`CA-PERM-135`** [S-PERM-1, `api.md §2.2`, `OPEN-PERM-17` = A] [Pest] · **Dado** un rol con dos titulares, **entonces** `GET /roles/{id}` devuelve `users_count: 2`, igual que su fila de `GET /roles`, y las respuestas de `PATCH /roles/{id}` y `PUT /roles/{id}/permissions` de ese rol también lo traen; **y** un rol recién creado con `POST /roles` responde `users_count: 0`.

### 20.18 Tests requeridos (por fichero, orientativo)

| Fichero | Criterios |
|---------|-----------|
| `apps/api/tests/Feature/Perm/ReplaceRolePermissionsNarrowingTest.php` (nombre libre) | `CA-PERM-045` |
| `apps/api/tests/Feature/Perm/AdministrationCapacityTest.php` (nombre libre) | `CA-PERM-046` a `-049` |
| `apps/api/tests/…` (roles y catálogo) | `CA-PERM-134` (parte Pest), `CA-PERM-135` |
| `src/data-table/DataTable.local.spec.ts` | `CA-PERM-123` a `-127` |
| `src/data-table/architecture.spec.ts` | `CA-PERM-129` (segunda lista de `RN-CORE-53`) |
| `src/modules/core/components/roles/RolePermissionMatrix.spec.ts` | `CA-PERM-136` |
| `src/navigation/modules.spec.ts` (existente) | `CA-PERM-100` |
| `src/modules/core/views/RolesView.spec.ts` | `CA-PERM-101` (retira `CA-CORE-240`) |
| `src/modules/core/views/RoleDetailView.spec.ts` | `CA-PERM-102`, `-117`, `-118` |
| `src/modules/core/views/RoleFormView.spec.ts` (alta, clonación, edición) | `CA-PERM-104` a `-107` |
| `src/modules/core/views/RolePermissionsView.spec.ts` | `CA-PERM-103`, `-108` a `-116`, `-137` |
| `src/modules/core/views/UserEffectivePermissionsView.spec.ts` | `CA-PERM-119` a `-122` |
| `src/modules/core/locales/*.spec.ts` | `CA-PERM-130` |
| `e2e/core-roles.spec.ts` [Playwright] | `CA-PERM-128`, `-132`, `-133` |

### 20.19 Hallazgos fuera del ámbito de esta especificación

No se corrigen aquí (`CLAUDE.md §5`); para que la sesión orquestadora abra el issue que corresponda:

1. **`GET /roles/{public_id}` no devuelve `users_count`**, aunque `api.md §2.2` lo documenta (`RolesController::show()` no hace `withCount`). Código y documentación se contradicen: **Media** (`CLAUDE.md §6.6`). **Se corrige en 1.5b** (S-PERM-1, `OPEN-PERM-17` = A); el issue, si se abre, se cierra con ese paso.
2. **[Se resuelve en 1.5b con `RN-PERM-47`, decisión del usuario del 2026-10-05, §20.2.1. Texto original conservado:]** **Bloqueo sin salida de la administración de roles por la vía de las concesiones.** `PUT /roles/{id}/permissions` no impide que un administrador retire (o deniegue) `rol.actualizar`, `asignacion_rol.crear` o `rol.crear` al rol `administrador_centro` —ni a ningún otro—, y `RN-CORE-07` solo garantiza que exista un **usuario** con el **rol** `administrador_centro`, no que ese rol conserve sus permisos. Por `RPERM-013`, una vez retirado nadie del centro podría volver a concederlo: el centro quedaría sin poder administrar roles hasta una intervención de plataforma, que hoy no existe (`REQ-SUP-003` es fase 2). Es posible **por API** con independencia de lo que haga la interfaz. **Severidad propuesta: Media** (pérdida de capacidad sin pérdida de datos, sin rodeo dentro del centro). Propuesta: una regla de servidor análoga a `RN-CORE-07` —p. ej. «tras la escritura, al menos un usuario vivo y activo conserva, de forma efectiva, `rol.actualizar` y `asignacion_rol.crear` con `todos`», `409` si no— decidida por el usuario y, si toca `ADR-044`, con ADR propio. `OPEN-PERM-10` (recomendada B) reduce el riesgo en la interfaz, **no** lo elimina.
3. **`GET /permissions` filtra `module_code` y `resource` por un solo valor**, mientras los permisos efectivos admiten listas por comas (`ADR-038 §5.2`). **Baja**; 1.5b no lo necesita (filtra en cliente).
4. **`GET /roles` lee `per_page` sin validación visible** en el controlador; no se ha comprobado si `PagePaginatedResponse` aplica el máximo de 100 de `ADR-038 §4.3` (un `per_page` grande devolvería todo el catálogo de roles en una petición). **Por verificar**; si no lo aplica, **Baja**.
5. **Nombre de rol personalizado en un solo idioma.** `CLAUDE.md §7` exige los cuatro idiomas también en «contenido del centro»; `roles.name` es un literal único, limitación **declarada y aceptada desde 0.8** (`permisos.md §10`). No es una contradicción nueva, pero 1.5b es la primera pantalla donde un centro **escribe** ese nombre: la ayuda del campo lo dice (`RN-PERM-29`). Si se quiere resolver, es una decisión de esquema (`ADR-034`), fuera de este paso.
6. **`REQ-CORE/funcional.md` queda desfasado en cuatro puntos al implementar 1.5b**: `RN-CORE-75` y `CA-CORE-240` (§14.8/§14.18: «roles solo lectura»), `§13.5` («dos modos en 1.9, y solo dos»: con 1.5b son tres), `§13.15` (filas de «Permisos efectivos» y «Matriz de concesión») y **`RN-CORE-53`/`CA-CORE-200` en `§13.3`/`§13.16`/`§13.18`** (modificada por §20.12, aprobada). Necesitan una nota que remita aquí, escrita por quien tenga ese ámbito (§20.21). Y `RN-CORE-07` queda **complementada** (no sustituida) por `RN-PERM-47` en `DELETE /users/{id}`, `POST /users/{id}/status` y `PUT /users/{id}/roles`: `REQ-CORE/permisos.md §8` y `api.md` de `REQ-CORE` deben citarla.
7. **`PLAN-IMPLEMENTACION.md`, línea de 1.5b**: la actualiza la sesión orquestadora (fuera de mi ámbito de escritura).
8. **[Resuelto el 2026-10-06, issue #349: `RN-CORE-07` no serializaba; ahora se relee bajo el bloqueo de `RN-PERM-47`. Texto original conservado:]** **No verificado: serialización de `RN-CORE-07`.** `RN-PERM-47` exige serializar por tenant la comprobación y la escritura (§20.2.1). Si `RN-CORE-07` no lo hace hoy, tiene la misma carrera (dos bajas simultáneas de los dos últimos administradores). No lo he comprobado en el código. Propuesta: que `implementer`, al añadir el bloqueo de `RN-PERM-47` en esas mismas rutas, compruebe si cubre también `RN-CORE-07` y lo reporte; si no, issue **Media**.

### 20.20 Preguntas del paso 1.5b — todas RESUELTAS (decisión del usuario, 2026-10-05)

| ID | Pregunta | Decisión (2026-10-05) | Aplicada en |
|----|----------|-----------------------|-------------|
| `OPEN-PERM-08` | Forma de la matriz y `RN-CORE-53` | **A**: matriz literal como rejilla de edición; modificación de `RN-CORE-53` aprobada expresamente, tal cual. B y C, **descartadas** | §20.8.1, §20.12, `CA-PERM-128`, `-129`, `-136` |
| `OPEN-PERM-09` | Etiquetas legibles de los recursos | **A**: `resource_label` del módulo dueño (S-PERM-2), en alcance | `RN-PERM-28`, `api.md §14.3`, `CA-PERM-134` |
| `OPEN-PERM-10` | Concesiones de los roles del sistema en la interfaz | **B**: solo lectura; se clonan | `RN-PERM-40`, §20.8.1, `CA-PERM-117` |
| `OPEN-PERM-11` | `mfa_required` en la edición del rol | **A**: editable en el alta; en la edición, lectura con enlace a `/administracion/mfa` | §20.7 |
| `OPEN-PERM-12` | Concurrencia en el editor | **A**: comprobación previa en cliente. S-PERM-3 **fuera** de 1.5b | `RN-PERM-37`, `CA-PERM-113` |
| `OPEN-PERM-13` | Permisos de módulos no utilizables en la matriz | **A**: se muestran; se marcan con `modulo.leer` | `RN-PERM-35` |
| `OPEN-PERM-14` | Autoservicio «Mis permisos» | **A**: fuera de 1.5b; `RN-CORE-24` sigue en siete rutas | §20.1.2 |
| `OPEN-PERM-15` | Salir del editor con cambios sin guardar | **A**: guarda interna y `beforeunload` solo con cambios | `RN-PERM-38`, `CA-PERM-115` |
| `OPEN-PERM-16` | Flujo del alta | **A**: alta sin concesiones y paso directo al editor | `RN-PERM-29`, `CA-PERM-104` |
| `OPEN-PERM-17` | `users_count` en la ficha | **A**: S-PERM-1 dentro de 1.5b | §20.5, `RN-PERM-39`, `CA-PERM-135` |

Además, el usuario decidió el 2026-10-05 **incluir en 1.5b la regla de servidor del hallazgo 2** (`RN-PERM-47`, §20.2.1). Siguen abiertas, sin cambio y sin bloquear 1.5b, `OPEN-PERM-04` (emitir `RolePermissionsChanged` sin consumidor; ya se emite) y `OPEN-PERM-05` (herencia en `REQ-CORE-004`, fuera de mi ámbito). **No surge ninguna pregunta nueva**: las decisiones internas de `RN-PERM-47` (conjunto protegido, un mismo usuario, `409`) se toman con su motivo en §20.2.1.

Lo que sigue conserva, para cada pregunta, las opciones y el razonamiento que se presentaron al usuario.

#### `OPEN-PERM-08` · Forma de la matriz y `RN-CORE-53` — **RESUELTA (2026-10-05): A**; B y C descartadas

La matriz es la pantalla que motivó `ADR-044 §6` («la tabla más compleja del producto: recurso × acción × ámbito, tres estados por celda»). `RN-CORE-53` obliga a que toda tabla pase por el componente, con una lista de excepciones vacía que solo se reduce.

- **A** · **Matriz literal**: una fila por recurso, una columna por cada una de las nueve acciones, y en cada celda existente el control de tres estados y el ámbito; construida a medida sobre `@/components/ui/table`. **Exige modificar `RN-CORE-53`** con el texto de §20.12 (segunda lista cerrada de «rejillas de edición»). A 320 px necesita desplazamiento interno (`ADR-054 §4`: comparar una columna entre filas). Coste: un componente propio con su accesibilidad (cabeceras en dos ejes, nombres de control con recurso **y** acción, foco en una rejilla de hasta 9 × N controles), sus estados y sus tarjetas o su desplazamiento, sin la ayuda de los tests del componente.
- **B** · **Tabla del componente en modo `local`, una fila por permiso** (§20.8.1), con los controles en las celdas. No modifica `RN-CORE-53`: es exactamente su caso de uso, y `RN-CORE-44` ya admite controles en filas («una tabla de datos con controles no es una rejilla»). Reutiliza búsqueda, filtros, orden, tarjetas, anuncios y estados, y el modo `local` hay que construirlo igualmente para los permisos efectivos. Pierde la vista «de un vistazo» de las nueve acciones en columnas; la compensa el orden por defecto (recurso, y acciones en el orden de `RPERM-003`, contiguas) y el filtro por módulo.
- **C** · **Formulario agrupado por recurso** (`fieldset`/`legend` por recurso, una línea por acción), sin tabla. No modifica `RN-CORE-53` (no es una tabla), con la precisión **más estricta** de §20.12 para que nadie la use como coartada. Accesible y adaptable por naturaleza, pero sin búsqueda, filtros ni paginación salvo que se construyan aparte.

> **Decisión del usuario (2026-10-05): A**, con aprobación expresa del texto de §20.12 tal cual. La recomendación de esta especificación era B; se conserva abajo como contexto, no rige. B y C quedan **descartadas** (§20.12).

**Recomendación original (no rige): B.** Es la única que no abre una vía de excepción en una regla que se diseñó para no tenerla, reutiliza un componente con 1.9-1.9f de tests detrás, y escala a los «pocos cientos» de permisos del catálogo con 53 módulos —donde una matriz de 9 columnas casi vacías (la mayoría de recursos tiene una a cuatro acciones; ver la matriz de `permisos.md §4.1`) es más difícil de recorrer que una lista filtrable—. A es defendible si el usuario valora por encima de todo ver las acciones en columnas; entonces el texto de §20.12 está listo para aprobarse. **El usuario pidió que la propuesta de modificación de `RN-CORE-53` se presentara: está en §20.12, y no rige salvo que elija A.**

#### `OPEN-PERM-09` · Etiquetas legibles de los recursos — **RESUELTA (2026-10-05): A**

`RPERM-002` habla de «alumnos, calificaciones, facturas…»; la API devuelve `resource` como código técnico (`usuario`, `proveedor_identidad`, `bloqueo_cuenta`). Las acciones y los ámbitos son vocabulario cerrado y el cliente los traduce (`RN-PERM-27`); los **recursos** los declara cada módulo y crecen con cada uno.

- **A** · **S-PERM-2**: el módulo dueño declara la clave de traducción de cada recurso junto a `declaredPermissions()`, el servidor devuelve `resource_label` en el idioma de la petición en las cuatro respuestas que lo nombran, y un test de servidor exige que todo recurso la tenga en los cuatro idiomas. Mismo patrón que `roles.name` traducido (`RN-CORE-63`). Cuesta tocar `apps/api` en 1.5b (claves en `lang/*` de `core` y `auth`, un campo en cuatro respuestas, OpenAPI).
- **B** · Catálogo de etiquetas en el cliente de `core` para los recursos de `core` y `auth`, con rama por defecto (código crudo) para los demás. Barato hoy; **convierte a `core` en el sitio donde se acumulan los nombres de los recursos de los 53 módulos**, que es la dependencia que `INV-007` quiere evitar, y diverge en silencio cada vez que un módulo declare un recurso.
- **C** · Cada módulo de frontend aporta las etiquetas de sus recursos por su `shell.ts` (registro de `ADR-053`). Respeta `INV-007` en cliente, pero amplía la forma de `ModuleShell` que fija `ADR-053` (necesitaría su precisión) y un módulo sin interfaz propia dejaría sus recursos sin nombre.

**Recomendación: A**: un solo dueño de cada nombre (quien declara el permiso), comprobado en servidor, y ninguna lista de recursos ajenos en el cliente. Si el usuario no quiere tocar `apps/api` en 1.5b, la interfaz funciona con la rama por defecto (`RN-PERM-28`) y A se hace después **sin** cambiar la interfaz; lo que no recomiendo es B.

#### `OPEN-PERM-10` · Concesiones de los roles del sistema — **RESUELTA (2026-10-05): B**

La API permite editar las concesiones de los 16 roles del sistema (`ReplaceRolePermissions` no mira `is_system`) y ningún requisito lo prohíbe ni lo pide; `RPERM-006` presenta la clonación como «punto de partida».

- **A** · Se editan como cualquier otro, con los avisos de `RN-PERM-36`.
- **B** · **Solo lectura en la interfaz**; para cambiarlos, se clonan. La API sigue permitiéndolo (la interfaz no es seguridad, `INV-002`).
- **C** · Editables salvo los que concedan algún permiso de administración de roles (`rol.*`, `asignacion_rol.*`), decidido por **permiso**, no por código de rol.

**Recomendación: B.** Tres motivos: (1) reduce desde la interfaz el bloqueo sin salida del hallazgo 2 (retirar `rol.actualizar` a `administrador_centro`); (2) los comandos de migración de datos de despliegue que conceden permisos a roles del sistema (`perm:grant-role-administration`, `auth:grant-lockout-permissions`) dan por hecho que esos roles siguen como se sembraron, y un centro que los haya editado recibiría concesiones que había retirado a propósito; (3) es la lectura de `RPERM-006`. Coste: un centro que quiera, por ejemplo, quitar algo a `direccion` tiene que clonarlo y reasignar a sus titulares. **No sustituye** a la regla de servidor que propone el hallazgo 2.

#### `OPEN-PERM-11` · `mfa_required` en la edición del rol — **RESUELTA (2026-10-05): A**

`POST /roles` lo admite (`RPERM-014`) y `PATCH /roles/{id}` también; `/administracion/mfa` (1.3) ya lo edita y `REQ-CORE §14.1.2` decidió no duplicarlo en 1.9b.

- **A** · Editable en el alta y en la clonación (heredado); en la edición, solo lectura con enlace a `/administracion/mfa`.
- **B** · Editable también en la edición del rol.

**Recomendación: A**, por el mismo motivo que `OPEN-CORE-37`: dos formularios para el mismo dato acaban divergiendo (el de MFA explica gracia y muro de sesión restringida; el del rol no lo haría).

#### `OPEN-PERM-12` · Concurrencia en el editor — **RESUELTA (2026-10-05): A** (S-PERM-3 fuera de 1.5b)

`ADR-038 §10` dice «la última escritura gana», y §14 de este documento lo acepta. Con un `PUT` de colección completa el efecto es peor que perder un campo: se **revierten** cambios ajenos en filas que el segundo administrador ni miró (§20.8.6).

- **A** · Comprobación previa en cliente (`RN-PERM-37`): sin cambio de servidor; ventana residual pequeña entre la comprobación y el `PUT`.
- **B** · S-PERM-3 (`ETag`/`If-Match`, `412`): cierra la ventana; precisa `ADR-038 §10` y exige ADR propio.
- **C** · Aceptar la última escritura sin más; el rastro queda en `audit_logs`.

**Recomendación: A en 1.5b, y B como decisión de arquitectura aparte** (aplica a todo `PUT` de colección del producto, no solo a este). C no es aceptable sin que el usuario lo diga: es la pantalla que cambia lo que puede hacer media plantilla.

#### `OPEN-PERM-13` · Permisos de módulos no utilizables en la matriz — **RESUELTA (2026-10-05): A**

`GET /permissions` devuelve el catálogo entero, contrate o no el centro cada módulo; una concesión de un módulo no utilizable es inerte (§9). La interfaz solo sabe qué módulos están contratados con `modulo.leer` (`GET /modules`).

- **A** · Se muestran todos; con `modulo.leer`, los de módulos no contratados llevan «Módulo no contratado» y el aviso de inercia; sin él, nada.
- **B** · Se ocultan los de módulos no contratados (exige `modulo.leer` o un dato nuevo del servidor).
- **C** · Campo nuevo `module_usable` en `GET /permissions` (dato de tenant en una respuesta de catálogo).

**Recomendación: A.** Hoy no cambia nada (`core` y `auth` no son desactivables) y conceder por adelantado lo de un módulo que se contratará es legítimo (`RMOD-004`: reactivar restaura). Se reconsidera con el primer módulo desactivable (1.11).

#### `OPEN-PERM-14` · Autoservicio «Mis permisos» — **RESUELTA (2026-10-05): A**

`GET /me/effective-permissions` existe (1.5) y su pantalla no está en el alcance.

- **A** · Fuera de 1.5b; el *endpoint* sigue sirviendo al menú (1.8) y al editor (§20.8.3).
- **B** · Dentro: `/cuenta/permisos` con `[]`, que amplía la lista cerrada de `RN-CORE-24` de siete a ocho.

**Recomendación: A.** No lo pide el plan ni el encargo, y ampliar `RN-CORE-24` merece su propia decisión.

#### `OPEN-PERM-15` · Cambios sin guardar al salir del editor — **RESUELTA (2026-10-05): A**

- **A** · Guarda de navegación interna con `ConfirmDialog` y `beforeunload` solo mientras haya cambios (`RN-PERM-38`).
- **B** · Solo la guarda interna.
- **C** · Nada: se pierden.

**Recomendación: A.** Es la única pantalla del producto en la que un usuario puede acumular decenas de cambios antes de guardar. No choca con `CA-CORE-202` (allí salir no pierde nada).

#### `OPEN-PERM-16` · Flujo del alta — **RESUELTA (2026-10-05): A**

`POST /roles` admite concesiones iniciales (§7.4), pero `clone_from` y `permissions` son excluyentes (`api.md §3.2`).

- **A** · El alta crea el rol **sin concesiones** y lleva directamente a su editor (`core-role-permissions`). Una sola pantalla de concesiones para alta y edición.
- **B** · La matriz dentro del alta, con un único `POST`.

**Recomendación: A.** Evita una segunda implementación de la matriz y sus reglas (`RN-PERM-32`-`38`) en otro formulario. Coste: un rol recién creado existe unos instantes sin permisos (inocuo: no tiene titulares) y el alta deja dos registros de auditoría en vez de uno.

#### `OPEN-PERM-17` · `users_count` en la ficha de rol — **RESUELTA (2026-10-05): A**

Hallazgo 1 de §20.19.

- **A** · Corregir en 1.5b (S-PERM-1): una línea en `RolesController::show()` (y en las respuestas de escritura) más `CA-PERM-135`.
- **B** · Rama `fix/` propia antes de 1.5b.
- **C** · La ficha prescinde de `users_count` (la baja queda siempre habilitada y el servidor decide con `409`).

**Recomendación: A** (o B si el usuario prefiere no mezclar `apps/api` en 1.5b): corrige una contradicción código/documentación de severidad Media y la ficha la necesita para `RN-PERM-39`.

### 20.21 Documentación a actualizar al cerrar 1.5b

- Este documento: estado de §20 (aprobada/implementada) y notas de implementación.
- `api.md §14` (lo que se apruebe de S-PERM-*), `permisos.md §12`, `operacion.md §10`, `datos.md §9` (ya redactados como propuesta).
- `REQ-CORE/funcional.md`: notas en `§13.5`, `§13.15`, `§14.8` (`RN-CORE-75`) y `§14.18` (`CA-CORE-240`) que remitan aquí, y **la modificación aprobada de `RN-CORE-53` y `CA-CORE-200`** (§20.12) en `§13.3`/`§13.16`/`§13.18`. `REQ-CORE/permisos.md §8` y `REQ-CORE/api.md` (rutas de usuario): cita de `RN-PERM-47` junto a `RN-CORE-07`. **Fuera de mi ámbito de escritura.**
- `docs/design-system.md §12.3c`: inventario de consumidores de `ConfirmDialog` (roles) y de `sheet` (panel de edición de celda); ningún componente base nuevo.
- `docs/i18n.md`: espacios `core.roles.*`, `core.effectivePermissions.*`, `core.permissions.vocabulary.*`; y en servidor, las claves de `resource_label` de `core` y `auth` (S-PERM-2) y el mensaje de `core.validation.administration_capacity_lost`.
- `PRIVACY.md §2.1b`: dos claves `plataforma.table.core.*` nuevas.
- `docs/manual-usuario/admin.md`: roles personalizados, clonación, concesiones (con la regla de `RPERM-013` y la de `RN-PERM-47` explicadas para el administrador) y permisos efectivos.
- `SECURITY.md`: `RN-PERM-47` como salvaguarda de la autorización del centro.
- `CHANGELOG.md` y OpenAPI (`apps/api/openapi/paths/core.yaml`: `users_count`, `resource_label`, `409` nuevo en cinco rutas).

### 20.22 ¿Se aprueba esta especificación?

**Sí: APROBADA por el usuario el 2026-10-05; implementada en `feature/REQ-PERM-ui-roles`, pendiente de revisión y merge.** Decisiones de esa fecha:

1. `OPEN-PERM-08` = **A**, con aprobación **expresa** del texto de §20.12 (segunda lista cerrada de rejillas de edición en `RN-CORE-53`, `RolePermissionMatrix.vue` como única entrada). B y C descartadas.
2. `OPEN-PERM-09` = A, `-10` = B, `-11` = A, `-12` = A, `-13` = A, `-14` = A, `-15` = A, `-16` = A, `-17` = A.
3. S-PERM-1 y S-PERM-2 **en alcance**: el paso toca `apps/api`. S-PERM-3 fuera.
4. Regla de servidor `RN-PERM-47` (hallazgo 2) **en alcance** (§20.2.1).
5. Issue #170: comportamiento estricto, redacción corregida, `CA-PERM-045`.

**Revisores**: `security-reviewer` (autorización, `RN-PERM-47`, serialización) y `doc-reviewer`; `db-reviewer` no hace falta (sin migraciones), salvo que la implementación de la serialización de `RN-PERM-47` toque esquema, que esta especificación no prevé.
