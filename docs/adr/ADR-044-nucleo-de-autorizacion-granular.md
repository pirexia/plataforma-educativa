# ADR-044 · Núcleo de autorización granular (`REQ-PERM`): vocabulario cerrado de ámbitos, resolutores por módulo y división del paso 1.5

**Estado**: **ACEPTADA** (2026-09-04). Las cuatro decisiones de `§10` fueron resueltas por el usuario el mismo día, todas en el sentido de la recomendación de `architect`. Ver `§10` para el registro de cada respuesta.

**Fecha**: 2026-09-04

**Concreta**: sección 11 del documento de requisitos (`RPERM-001` a `RPERM-015`), sección 11.1 (roles predefinidos)

**Se apoya en**: `INV-001` (aislamiento a nivel de framework), `INV-002` (autorización en cada endpoint, denegar por defecto), `INV-003` (auditoría de creación, modificación y borrado), `INV-006` (API primero), `INV-007` (un módulo no importa código interno de otro), `INV-015` (ningún requisito sin test que referencie su ID), `ADR-029` (tipos e identificadores), `ADR-033 §4` y `§7` (aislamiento, tablas de referencia), `ADR-034 §2` (esquema de `Role`/`Permission` completo desde 0.8, resolutor diferido a 1.5), `ADR-035` (política de auditoría por modelo), `ADR-040` (exclusión declarativa de eventos en el *observer*), `ADR-038` (convenciones de API REST), `docs/modulos/REQ-CORE/permisos.md` (catálogo, matriz y siembra de 1.1), `CLAUDE.md §8` (datos de categoría especial en tablas separadas con auditoría de lectura)

**Afecta a**: el paso **1.5** y el paso nuevo **1.5b** que esta decisión propone crear; es entrada obligatoria de `spec-writer` para ambos. Condiciona **1.6** (`REQ-BO`), **1.8** (dashboards por rol), **1.11**–**1.14** (`REQ-ACAD`, `REQ-FAM-UNIT`: registran ámbitos), **1.16** (`REQ-CALIF`: primer consumidor real de `RPERM-008`) y **1.7b** (issue [#163](https://github.com/pirexia/plataforma-educativa/issues/163): gana cuatro tests de arquitectura concretos)

**No sustituye a ningún ADR anterior.** Ejerce el diferimiento que `ADR-034 §2` dejó escrito («el esquema completo ahora, el resolutor en 1.5») y resuelve las cuatro cuestiones que aquel ADR nombró como «decisiones de 1.5»: permisos condicionales, vista previa de permisos efectivos, caché de permisos resueltos y herencia viva de roles.

---

## 1 · Contexto

El paso 0.8 escribió cinco tablas (`roles`, `role_user`, `permissions`, `permission_role`, y la referencia `modules`) y **ninguna semántica**. El paso 1.1 sembró los diecisiete roles predefinidos de la sección 11.1 y un catálogo de veinte permisos de `REQ-CORE`. Entre 1.1 y hoy, cuatro pasos (`1.2`, `1.3`, `1.3b`, `1.4`/`b`/`c`) han autorizado sus endpoints con un resolutor provisional de cuarenta líneas.

Este paso escribe la semántica. Lo que se decida aquí lo heredan los 53 módulos, y no como estilo: como la única barrera entre un docente y el expediente de un alumno que no es suyo. `INV-002` es la segunda invariante más crítica del producto después del aislamiento de tenant.

### 1.1 Estado real verificado en el repositorio, no supuesto

Verificado el 2026-09-04 sobre `develop` en `2181289`:

- **`App\Support\Authorization\PermissionResolver`** existe y hace exactamente lo que su docblock dice: lee `permission_role.effect`, **ignora `permission_role.scope`**, aplica `deny` sobre `allow` por código de permiso y no cachea. Dos consultas por comprobación.
- **`App\Http\Middleware\RequirePermission`** es el único punto de aplicación: `permission:usuario.leer` en la ruta, `401` sin sesión, `403` sin permiso. Devuelve un booleano; no sabe nada de ámbitos ni puede acotar una consulta.
- **`permission_role.scope`** es `text` **nullable y sin `CHECK`**. Todas las filas que existen hoy valen `'todos'`, garantizado por el criterio de aceptación `CA-CORE-042`.
- **`permissions`** es tabla de referencia sin `tenant_id`, con `REVOKE INSERT, UPDATE, DELETE` para `plataforma_app` y `plataforma_platform`; solo la escribe `platform:sync-registry` como `pgsql_owner`, a partir de lo que declara cada `ServiceProvider` de módulo vía `DeclaresModuleRegistry::declaredPermissions()`. Un permiso retirado se marca `retired_at`, nunca se borra.
- **`roles.mfa_required` y `roles.special_data_access`** existen y están sembrados. `mfa_required` **ya lo consume** `EloquentMfaPolicy::requiredByRoleCodes()`, y lo hace **genéricamente** (`where('roles.mfa_required', true)`), sin ninguna lista de códigos de rol escrita a mano: un rol personalizado con `mfa_required = true` ya funcionaría hoy. `special_data_access` **no lo lee nadie todavía**.
- **`PATCH /roles/{public_id}`** existe desde 1.3, acotado por código a la única clave `mfa_required`; cualquier otra clave responde `422`. El editor completo estaba explícitamente diferido a 1.5, sobre la misma ruta y el mismo permiso `rol.actualizar`.
- **No existe ninguna entidad académica ni familiar.** Las migraciones son `tenants`, `academic_years`, `people`, `users`, `roles`, `permissions`, `modules`, `audit_logs` más las de `REQ-CORE` y `REQ-AUTH`. **No hay departamento, grupo, clase ni unidad familiar.** `REQ-ACAD` es el paso 1.11 y `REQ-FAM-UNIT` el 1.14, ambos posteriores a 1.5.
- **`Role` es `Auditable` (`AuditValuePolicy::Full`); `PermissionRole` no lo es**, y `role_user` se escribe con `$user->roles()->sync($newRoleIds)` en `UserRolesController::replace()`, que **no dispara eventos de modelo**. Es decir: hoy **la concesión de un permiso a un rol y la asignación de un rol a un usuario no dejan rastro en `audit_logs`** — hallazgo de severidad Alta abierto como issue [#165](https://github.com/pirexia/plataforma-educativa/issues/165), en alcance de 1.5 (`§4.11`).
- **Cero tests de arquitectura** (`arch()`) en el repositorio, confirmado por el issue [#163](https://github.com/pirexia/plataforma-educativa/issues/163).

### 1.2 El hecho incómodo que ordena todo este ADR

`RPERM-004` define seis ámbitos: `todos`, `propios`, `departamento`, `grupo`, `clase`, `unidad_familiar`. **Cuatro de los seis no se pueden implementar en 1.5, porque las entidades sobre las que se resuelven no existen y no existirán hasta 1.11 y 1.14.**

No es un detalle de calendario: es la restricción que decide la forma del sistema. Hay tres maneras de tratarla y solo una es defendible (`§3`). Y hay una trampa concreta que ya se documentó una vez y que no puede repetirse: `docs/modulos/REQ-CORE/permisos.md §5` tuvo que escribir una **regla de seguridad** —toda fila de `permission_role` lleva `scope = 'todos'`, con test de esquema— porque un ámbito que el resolutor **ignora en silencio** convierte una concesión restringida en acceso total sin que nadie lo note. El sistema que se construya aquí no puede dejar viva ni una sola vía por la que un ámbito se ignore en silencio.

---

## 2 · Qué NO decide este ADR

Delimitarlo importa, porque este paso toca la tabla más sensible del sistema y la tentación de arrastrar cosas es alta.

- **No resuelve el issue [#44](https://github.com/pirexia/plataforma-educativa/issues/44)** (contradicción `REQ-CORE-002`/`RMOD-002` sobre quién activa módulos). Es una decisión de `1.6` y sigue siéndolo. Lo que sí hace este ADR es dejar la autorización **indiferente** a cómo se resuelva (`§4.9`).
- **No resuelve el issue [#60](https://github.com/pirexia/plataforma-educativa/issues/60)** (prefijo `core.` en `ValidationErrorFormatter`). Es una convención de códigos de error de API (`ADR-038`), no de autorización; meterla aquí sería ensuciar un ADR de permisos con un asunto ajeno. Se decide en su propio sitio, con `ADR-038` delante.
- **No decide los permisos de plataforma** (`super_administrador`, `platform_admins`, `admin_action_logs`). `ADR-034 §2` ya estableció que el superadministrador **no es una fila de `roles`**, y sus tablas las crea `1.6`. Este ADR fija el punto de encaje (`§4.10`) y nada más.
- **No inventa permisos de módulos futuros.** Cada módulo declara los suyos (`INV-007`); aquí se fija el contrato con el que los declara, no su contenido.
- **No decide el diseño visual** del editor de roles ni de la vista previa. Eso es `1.5b` sobre el sistema de diseño de `1.7`.

---

## 3 · Opciones reales sobre el ámbito (`RPERM-004`)

No las teóricas: las tres que de verdad se pueden ejecutar en solitario con `REQ-ACAD` a seis pasos de distancia.

**Opción A · Motor completo ahora, con ámbitos de mentira.** 1.5 implementa los seis ámbitos, inventando qué significa `grupo` antes de que exista un grupo, con tablas puente propias en el subsistema de permisos.

**Opción B · Contrato ahora, resolutores cuando exista la entidad.** 1.5 fija el vocabulario cerrado de los seis ámbitos, el contrato con el que un módulo registra el resolutor de un ámbito, y **solo implementa los resolutores cuyas entidades existen hoy**. `REQ-ACAD` (1.11) registra `departamento`, `grupo` y `clase` cuando crea esas entidades; `REQ-FAM-UNIT` (1.14) registra `unidad_familiar`. Un permiso no puede concederse con un ámbito sin resolutor registrado.

**Opción C · Sin ámbitos hasta 1.11.** 1.5 hace roles, matriz, deny/allow y vista previa; el ámbito espera a que haya sobre qué resolverlo.

### Evaluación

| Criterio | A | B | C |
|---|---|---|---|
| Coste de implementación en solitario | Alto: hay que inventar y luego tirar el modelo de grupo | Medio | Bajo |
| Mantenimiento a 3 años | Malo: dos modelos de grupo compitiendo, y el falso gana por ser el que ya está en `permission_role` | Bueno | Malo: 8–10 módulos construidos contra una API sin ámbito, y su reajuste no es una migración, es reescribir sus consultas de listado una por una |
| Impacto en las invariantes | Riesgo directo sobre `INV-002` e `INV-007`: el núcleo acabaría sabiendo qué es un grupo | Neutro | `INV-002` queda a medias durante 6 pasos, con `permisos.md §5` como único parche |
| Reversibilidad | Baja: retirar un modelo de ámbito ya sembrado en centros es migración de datos de autorización | **Alta**: registrar un resolutor es añadir una clase; el vocabulario es un `CHECK` | Media, pero el coste no está en el esquema sino en el código de los módulos ya escritos |

**Se elige B.** El argumento decisivo no es de coste sino de dirección del fallo: en A y en C el sistema puede quedar en un estado donde una concesión restringida se comporta como acceso total —A porque el ámbito falso no filtra nada real, C porque el ámbito no se evalúa—, que es exactamente el fallo silencioso que `permisos.md §5` ya tuvo que contener con un test. En B, un ámbito sin resolutor **no se puede ni conceder**, y si por cualquier vía existiera, **deniega**. Falla en cerrado en los dos extremos.

---

## 4 · Decisión

### 4.1 El vocabulario de ámbitos es **cerrado y central**; lo que cada módulo aporta es el **resolutor**, no el nombre

Los seis ámbitos de `RPERM-004` (`todos`, `propios`, `departamento`, `grupo`, `clase`, `unidad_familiar`) son vocabulario **del dominio educativo entero**, no de un módulo. `grupo` significa lo mismo en calificaciones, en comunicaciones y en asistencia. Se fijan en el núcleo, en un `enum` de PHP y en un `CHECK` de `permission_role.scope`, y **añadir un séptimo exige un ADR nuevo**.

Esto **rechaza explícitamente** la idea de que cada módulo registre sus propios nombres de ámbito. Con un vocabulario abierto:

1. La vista previa de permisos efectivos (`RPERM-009`) deja de ser explicable: un administrador de centro vería ámbitos inventados por módulos que no conoce, sin forma de compararlos entre sí.
2. La resolución de conflictos entre roles (`RPERM-007`) se vuelve indecidible: no se puede comparar un `scope` del módulo X con uno del módulo Y sin una relación de contención que nadie ha definido.
3. Cincuenta y tres módulos producirían cincuenta y tres sinónimos de «lo mío» a tres años vista.

Lo que sí es de cada módulo, y lo que de verdad hacía falta resolver para `INV-007`, son **dos cosas distintas**:

- **Qué ámbitos admite cada permiso.** `declaredPermissions()` gana una clave: `applicable_scopes`. `usuario.leer` admite `['todos']`; `calificacion.actualizar` admitirá `['todos', 'grupo', 'clase']`; `expediente.leer` admitirá `['todos', 'unidad_familiar']`. Conceder un ámbito no admitido responde `422`, no se guarda.
- **Cómo se resuelve un ámbito sobre un recurso.** El módulo **propietario de la entidad** implementa el resolutor. El núcleo nunca sabe qué es un grupo.

`INV-007` queda satisfecho en la dirección correcta: el núcleo define la interfaz y el vocabulario; el módulo implementa; nadie importa el código interno de nadie.

### 4.2 El resolutor de ámbito acota consultas, no devuelve booleanos

Es la corrección de fondo sobre el mecanismo actual. Un ámbito **no** es una respuesta sí/no en la puerta del endpoint: `usuario.leer` con ámbito `grupo` significa que el listado devuelve menos filas **y** que el detalle de una fila ajena responde `404`. Un *middleware* que solo autoriza o rechaza no puede expresar eso, y es el error característico que la *skill* `permisos-y-roles` llama «verificar solo en el listado y no en el detalle».

Por tanto la autorización pasa a tener **dos salidas obligatorias y una sola fuente**:

1. **Puerta** (`RequirePermission`, sin cambios de semántica): ¿el sujeto tiene este permiso con **algún** ámbito no denegado? No ⇒ `403`. Sigue siendo lo primero que ocurre y sigue estando en la ruta.
2. **Acotación** (nueva): el conjunto de ámbitos concedidos se resuelve en un objeto de valor `PermissionDecision` y se aplica a la consulta. Cada ámbito distinto de `todos` aporta un `Closure` de restricción que el módulo propietario provee; el conjunto se aplica como **unión** (`OR`), y el detalle de un recurso se comprueba contra la misma restricción.

Consecuencia que hay que decir en voz alta: **el paso 2 no se puede garantizar por el framework como se garantiza el aislamiento de tenant.** `INV-001` se cumple con un *global scope* más RLS porque «este dato es de otro colegio» es una condición uniforme sobre una columna; «este alumno no es de tu grupo» no lo es. Lo honesto es reconocer que aquí la red es más floja y compensarlo con tres cosas explícitas, no con una afirmación tranquilizadora:

- Una **única** API sancionada (`ScopedQuery`/`PermissionDecision`); cualquier otra forma de listar un recurso permisionado es una desviación.
- Un **test de arquitectura** que falle si un controlador de módulo consulta un modelo permisionado sin pasar por ella (candidato para 1.7b, `§8`).
- Un **criterio de aceptación por recurso con ámbito restringido**: existe un test de acceso denegado en listado **y** en detalle. Sin él, `INV-015` no se cumple.

### 4.3 Resolución con varios roles: conjunto de ámbitos permitidos, y `deny` veta el código entero

`RPERM-007` dice «deny sobrescribe allow» sin matices. Se implementa literalmente:

1. Para cada código de permiso, se reúnen todas las concesiones `allow` de todos los roles vivos del sujeto. El resultado es el **conjunto unión** de sus ámbitos.
2. Si existe **una sola** fila `deny` para ese código, **en cualquier rol y con cualquier ámbito**, el conjunto queda **vacío** y el permiso está denegado.
3. Conjunto vacío ⇒ denegado (`RPERM-011`, denegación por defecto).

**`deny` es ciego al ámbito, a propósito.** La alternativa —`deny(X, grupo)` resta `grupo` pero deja `propios`— exige una retícula de contención entre ámbitos (`todos ⊃ departamento ⊃ grupo ⊃ clase`, con `propios` y `unidad_familiar` fuera de esa cadena) cuyo comportamiento es imposible de explicar a la persona que administra un colegio, y que la vista previa de `RPERM-009` no podría representar. Un `deny` sirve para decir «este rol no toca calificaciones jamás»; para **estrechar** un acceso ya existe la herramienta natural, que es conceder un ámbito más pequeño.

Y el criterio de reversibilidad decide el empate: pasar de «ciego al ámbito» a «resta por ámbito» más adelante **abre** permisos, se nota al probar y es un cambio de código sin migración; el camino inverso **cierra** permisos en centros en producción. Se elige la dirección cuyo error se descubre antes.

**El conjunto, no un ganador único**, porque `propios` y `unidad_familiar` no son comparables con `grupo`: forzar un orden total obligaría a inventar cuál gana entre «los míos» y «los de mi grupo», que no tiene respuesta correcta. La unión de restricciones (`OR`) sí la tiene y es la interpretación más natural de tener dos roles.

### 4.4 Categoría especial (`RPERM-012`, `RPERM-015`): conjunción, y sobre el rol **que concede**

Existen dos piezas y hasta ahora nadie había dicho cómo se combinan: `permissions.is_special_category` (propiedad del permiso, la declara el módulo) y `roles.special_data_access` (atributo del rol, `RPERM-015`).

**Regla**: para un permiso con `is_special_category = true`, **solo cuentan las concesiones que vengan de un rol con `special_data_access = true`**. Una concesión de categoría especial hecha desde un rol sin el atributo es inerte: no se aplica y la vista previa la muestra como tal.

El matiz «sobre el rol que concede» no es cosmético, es el que cierra el agujero. La lectura ingenua —«el usuario tiene algún rol con `special_data_access`»— permite que alguien con `orientador` (que sí lo tiene) más un rol personalizado cualquiera que conceda `salud.leer` acabe leyendo salud por la fuerza de un rol que no tiene nada que ver. Es el *confused deputy* clásico y se evita filtrando por concesión, no por usuario.

Consecuencias que `spec-writer` debe recoger:

- **`special_data_access` no se cambia con `rol.actualizar`.** Necesita permiso propio. Como las acciones de `RPERM-003` son cerradas, se declara un **recurso** nuevo: `rol_datos_especiales.actualizar` — mismo criterio con el que `permisos.md §1` convirtió la invitación en recurso en vez de inventar la acción `usuario.invitar`.
- **`RPERM-013` cubre también el atributo**: nadie activa `special_data_access` en un rol si él mismo no lo tiene.
- **Auditoría reforzada de lectura** (`RPERM-015`): el contrato queda fijado aquí —todo módulo que exponga un permiso `is_special_category` emite evento `read` en `audit_logs`— y lo verifica un test de arquitectura, pero **1.5 no tiene ningún recurso de categoría especial que auditar**: `REQ-CORE` no expone ninguno (`permisos.md §6`). Se fija el contrato, no se simula el consumidor.
- Se mantiene sin tocar la decisión de `permisos.md §4.3`: `administrador_centro` tiene `special_data_access = false`. Administrar un centro no es tratar datos de salud.

### 4.5 Permisos condicionales (`RPERM-008`): **diferidos**, y sin columna reservada

Se difieren a `REQ-CALIF` (1.16), que es donde aparece el primer consumidor real.

El ejemplo del propio requisito —«solo durante el período de evaluación»— depende del calendario académico (`REQ-CURSO`, 1.10) y de los períodos de evaluación (`REQ-CALIF`, 1.16). Ninguno existe. Construir hoy un motor de condiciones significa inventar un lenguaje de expresiones sin un solo caso real que lo valide, y garantizar que se reescribe cuando llegue el primero. Es exactamente el tipo de complejidad sin beneficio proporcional que hay que rechazar.

Además, una parte grande de lo que se suele meter en `RPERM-008` **no es un permiso**: que una calificación sea visible solo una vez publicada es una regla de negocio que se comprueba **además** del permiso, no dentro de él. La propia *skill* `permisos-y-roles` ya lo separa así. Confundirlos mete lógica académica dentro del motor de autorización, que es lo contrario de `INV-007`.

**Y no se reserva columna.** Una `conditions jsonb NULL` sin semántica repetiría exactamente la trampa que `permission_role.scope` creó entre 1.1 y 1.5 y que obligó a escribir una regla de seguridad y un test para contenerla. La diferencia con aquel caso es decisiva: `scope` **tenía que** existir en 0.8 porque 1.1 sembraba concesiones reales; aquí **nada entre 1.5 y 1.16 escribe una condición**. Añadir la columna cuando exista el motor es `expand` puro y reversible. Queda escrito para que nadie lo «mejore» adelantándolo.

### 4.6 Sin herencia viva de roles: solo clonación

`ADR-034 §2` dejó abierto si `roles` gana `parent_role_id`, señalando que `RPERM-006` pide *clonación* (copia en el alta, sin columna) mientras `REQ-CORE-004` menciona de pasada *herencia con override*.

**Decisión: clonación, sin `parent_role_id`.** Es un «no» y es el sitio de decirlo. La herencia viva se combina con `deny` sobre `allow` y con conjuntos de ámbitos para producir una resolución de cuatro capas (padre → hijo → varios roles → ámbitos) que la vista previa de `RPERM-009` no podría explicar en una pantalla, y que convertiría cada incidencia de «por qué este usuario ve esto» en una investigación. `REQ-CORE-004` la menciona en una frase; nadie ha descrito un caso de uso. El coste de equivocarse es asimétrico: añadirla después es **una columna anulable**, `expand` puro; quitarla cuando los centros ya tengan jerarquías montadas es migración de datos de autorización en producción.

### 4.7 Sin caché de permisos resueltos; memoización por petición

`ADR-034 §2` también dejó abierta la caché. **No se implementa en 1.5.** Se memoiza por instancia con `scoped()`, exactamente el patrón que `EloquentMfaPolicy` ya usa y que ya está probado en este repositorio: una instancia por petición HTTP, reiniciada entre trabajos de cola.

Motivo, y no es el rendimiento: una caché de permisos necesita invalidarse al cambiar un rol, una concesión, una asignación o al borrar un rol, y hacerlo a la vez en varios contenedores de aplicación y en los *workers*. Cuando esa invalidación falla, **falla en abierto**: el sistema concede un permiso que ya se revocó. Es la única dirección de fallo que `INV-002` no puede admitir. Dos consultas indexadas sobre tablas pequeñas por tenant no son el cuello de botella del sistema, y si alguna vez lo fueran se decidirá **con una medición**, como se hizo con el sobrecoste de RLS en 0.8.12 (cuatro corridas, media ~1,24 %), no con una intuición.

### 4.8 `RPERM-013` con ámbitos: subconjunto, con `todos` absorbiendo

`permisos.md §8` ya implementa «nadie concede lo que no tiene» comparando **códigos**. Con ámbitos, la comparación es de pares (código, conjunto de ámbitos):

> El conjunto de ámbitos que se concede debe ser **subconjunto** del que posee el otorgante para ese código, con una única regla de absorción: quien posee `todos` posee cualquier ámbito.

Es una relación de orden parcial con una sola excepción, no una retícula: se puede explicar en una frase y se puede probar. Cualquier otra contención (¿`departamento` incluye `grupo`?) exigiría saber qué es un departamento, que es precisamente lo que el núcleo no debe saber (`§4.1`).

### 4.9 Módulo desactivado: se comprueba **antes** que el permiso, y es indiferente al issue #44

`RMOD-009` exige que las APIs de módulos desactivados respondan `403`. Se comprueba **antes** de resolver permisos, y un permiso cuyo `module_code` no esté utilizable por el tenant **es inerte**: no concede aunque esté concedido, y la vista previa lo muestra como inerte por módulo, no como no concedido.

Esto **no requiere resolver el issue [#44](https://github.com/pirexia/plataforma-educativa/issues/44)**: el resolutor consume un único booleano «¿este tenant puede usar este módulo ahora?» a través de una interfaz que posee `REQ-CORE`. Que detrás haya un booleano o los dos estados (contratado por la plataforma / habilitado por el centro) que #44 acabe decidiendo no cambia una línea del núcleo de autorización. Se deja escrito para que 1.6 no crea que tiene que tocar permisos.

### 4.10 Dónde vive el código, y por qué `REQ-PERM` **no** es un módulo nuevo

- **El núcleo de autorización vive en `App\Support\Authorization`**, no en un módulo. Mismo argumento que `INV-001`: la autorización es infraestructura de framework, y si viviera en `App\Modules\Core`, el módulo `Auth` tendría que importarla desde otro módulo para autorizar sus endpoints — violación directa de `INV-007` el primer día.
- **Los contratos que implementan los módulos** (`ScopeResolver`, la ampliación de `DeclaresModuleRegistry`) van en `App\Support\Authorization\Contracts`.
- **Los endpoints de administración de roles y concesiones se quedan en `App\Modules\Core`**, donde ya están `RolesController`, `PermissionsController` y `UserRolesController`. `PATCH /roles/{public_id}` se amplía sobre la misma ruta y el mismo permiso, como `RolesController` ya tiene documentado.
- **No se crea `App\Modules\Perm`.** Un módulo cuyo único contenido fuera un controlador que necesita el modelo `Role` de `Core` incumpliría `INV-007` desde el primer commit.

`REQ-PERM` es, por tanto, **prefijo de requisito y carpeta de documentación**, no un *bounded context*. Ver `§5`.

### 4.11 Auditoría de roles y permisos (`RPERM-010`): es alcance de 1.5 y hoy no existe

`PermissionRole` no implementa `Auditable` y `role_user` se escribe con `sync()`, que no dispara eventos de modelo. Hoy **conceder un permiso a un rol y asignar un rol a un usuario no dejan rastro**. Es un incumplimiento vivo de `INV-003` y `RPERM-010` sobre la escritura más sensible del sistema, y **1.5 lo cierra** (issue [#165](https://github.com/pirexia/plataforma-educativa/issues/165)).

Restricciones de diseño para `spec-writer`:

- `PermissionRole` pasa a `Auditable`. Sus concesiones son **creaciones**, y `ADR-040` excluyó `created` solo para `UserSession` de forma declarativa por modelo: aquí **la creación debe registrarse**, y hay que comprobarlo explícitamente, no suponerlo.
- La asignación de roles **no puede seguir auditándose por `observer`**, porque `sync()` no los dispara. Requiere registro explícito del cambio con estado anterior y posterior, sin excepciones ni «casos en los que no hace falta».
- La política de valor es `Full` en las tres tablas: un código de permiso y un código de rol no son datos personales (`ADR-035`).

---

## 5 · Identificador de requisito: `REQ-PERM`

La sección 11 no tiene identificador paraguas; solo los quince `RPERM-NNN`. Se propone:

- **Prefijo paraguas: `REQ-PERM`**, por continuidad con la familia `RPERM-NNN` ya escrita y por distinción clara frente a `REQ-CORE` y `REQ-AUTH`.
- **Los `RPERM-001`…`RPERM-015` se conservan intactos** como identificadores atómicos. Ya están referenciados en `ADR-034`, en `docs/modulos/REQ-CORE/permisos.md`, en la *skill* `permisos-y-roles` y en el código. Renumerarlos no aporta nada y rompe rastros.
- **Rama**: `feature/REQ-PERM-nucleo-autorizacion` (y `feature/REQ-PERM-ui-roles` para 1.5b).
- **Commits**: `tipo(perm): descripción [REQ-PERM]`, añadiendo el `RPERM-NNN` concreto cuando el commit cierre uno.
- **Documentación**: `docs/modulos/REQ-PERM/{funcional,datos,api,permisos,operacion}.md`. Carpeta propia y no un apéndice de `REQ-CORE`, porque quince requisitos con su modelo de datos, sus endpoints y su matriz harían inmanejable la documentación de `REQ-CORE` (regla 6.5 de `CLAUDE.md`). El `README.md` de esa carpeta debe decir explícitamente que el código vive en `App\Support\Authorization` más `App\Modules\Core`, para que nadie busque un `App\Modules\Perm` que no existe.

Esto **exige un cambio en la sección 11 del documento de requisitos** (declarar `REQ-PERM` como paraguas) que este ADR no puede hacer por sí mismo. Ver `§10.2`.

---

## 6 · División del paso: **1.5** (núcleo y API) y **1.5b** (interfaz de roles)

**Sí, se divide.** El corte va por `INV-006` («API primero: la UI es un cliente más»), que es la costura que el producto ya declara y que por tanto no cuesta nada abrir.

**1.5 · Núcleo de autorización granular** — `RPERM-001`, `-002`, `-003`, `-004` (vocabulario y contrato + resolutores existentes), `-005`/`-006` como **API**, `-007`, `-010`, `-011`, `-012`, `-013`, `-014`, `-015`; `-009` como endpoint. Incluye: vocabulario cerrado y `CHECK`; `applicable_scopes` en `declaredPermissions()`; contrato `ScopeResolver`; resolutor con conjuntos de ámbitos, veto de `deny` y conjunción de categoría especial; `PermissionDecision` y la API de acotación de consultas; CRUD de roles y clonación; concesión y revocación; `RPERM-013` con ámbitos; auditoría de las tres tablas (`§4.11`); `GET /users/{public_id}/effective-permissions` con procedencia.

**1.5b · Editor de roles y vista previa de permisos efectivos** — `RPERM-005`, `-006` y `-009` como **interfaz**: alta y clonación de roles, matriz de concesión recurso × acción × ámbito, pantalla de permisos efectivos por usuario.

### Por qué ahí, y no por tamaño

1. **La costura ya existe**: 1.5b no necesita nada del backend que 1.5 no vaya a exponer igualmente, porque `INV-006` obliga a exponerlo.
2. **Lo que bloquea a los demás es solo la mitad de atrás.** 1.6, 1.8 y los 53 módulos dependen del motor y de la matriz. **Ninguno depende del editor gráfico.**
3. **Construir la matriz de permisos antes de 1.7 y 1.9 garantiza rehacerla.** Es la tabla más compleja del producto —recurso × acción × ámbito, tres estados por celda— y `1.7` fija el sistema de diseño y `1.9` las tablas de datos con TanStack Table. Es el mismo argumento con el que el issue #163 difiere el generador de módulos: hacerlo antes es firmar que se rehace.
4. **El perfil de riesgo es distinto.** 1.5 toca la barrera de seguridad del producto entero y necesita `security-reviewer` con lupa; 1.5b es una pantalla de administración. Mezclarlos hace que la revisión de seguridad tenga que leer componentes Vue para llegar al resolutor.

Por (3), la recomendación es que **1.5b se sitúe después de 1.9**, no inmediatamente después de 1.5. El coste de esa espera es acotado y conocido: entre 1.5 y 1.5b los roles personalizados se crean por API y no por pantalla. No hay centro en producción antes del hito H0, y ningún paso intermedio necesita la pantalla.

### Efecto sobre issues y pasos existentes

- **[#163](https://github.com/pirexia/plataforma-educativa/issues/163) / 1.7b**: no cambia de sitio ni de justificación —sigue siendo posterior a 1.7— y su premisa se confirma: 1.5 fija la matriz que usarán los 53 módulos. Gana cuatro candidatos concretos de test de arquitectura (`§8`). Si 1.5b se coloca después de 1.9, 1.7b queda después de 1.5b, lo que es mejor todavía: observará el patrón de módulo con el sistema de permisos ya completo.
- **[#6](https://github.com/pirexia/plataforma-educativa/issues/6)**: su punto 1 (test de arquitectura que impida usar `runAsPlatform()` fuera de la lista de excepciones) encaja en 1.5, que es el paso de autorización, y es barato. Sus puntos 2 y 3 (comprobación de permiso de plataforma y registro en `admin_action_logs`) **no pueden hacerse en 1.5**: ni `platform_admins` ni `admin_action_logs` existen hasta 1.6. El issue debe reetiquetarse a 1.6 tras cerrar su punto 1.
- **[#44](https://github.com/pirexia/plataforma-educativa/issues/44)** y **[#27](https://github.com/pirexia/plataforma-educativa/issues/27)**: sin efecto, siguen siendo de 1.6 (`§4.9`).

---

## 7 · Motivo

Resumido, para que se pueda contrastar sin releer el ADR:

1. **El vocabulario cerrado con resolutores abiertos** es lo único que satisface a la vez `INV-007` (el núcleo no sabe qué es un grupo) y la explicabilidad de `RPERM-009` (un administrador ve seis ámbitos comparables, no cincuenta y tres sinónimos).
2. **Un ámbito sin resolutor no se puede conceder, y si existiera denegaría.** Es la respuesta directa al fallo que `permisos.md §5` tuvo que contener con una regla y un test: no queda ninguna vía por la que un ámbito se ignore en silencio.
3. **Todo lo diferido se difiere hacia expand reversible** (condiciones, herencia, caché): en los tres casos el coste de añadirlo después es una columna anulable o una clase nueva, y el coste de quitarlo después de haberlo sembrado en centros reales es una migración de datos de autorización.
4. **Todos los empates se rompen hacia el fallo en cerrado.** `deny` ciego al ámbito, sin caché, conjunción en categoría especial, `403` de módulo desactivado antes que el permiso. Cuando este subsistema se equivoca en abierto, el daño es un expediente de un menor leído por quien no debía.
5. **La división por `INV-006`** aísla la revisión de seguridad del trabajo de interfaz y evita construir la tabla más compleja del producto antes de tener el sistema de diseño con el que se construye.

---

## 8 · Consecuencias

**Buenas**

- `INV-002` deja de ser «un booleano por endpoint» y pasa a cubrir la fila, que es donde `RPERM-004` siempre estuvo.
- 1.6 y los 53 módulos reciben un contrato estable: declarar permisos con sus ámbitos aplicables, e implementar un resolutor si aportan una entidad de ámbito.
- `RPERM-010` e `INV-003` quedan cumplidos sobre roles, concesiones y asignaciones, que hoy no lo están.
- La vista previa de permisos efectivos comparte código con la aplicación real, así que no puede mentir. **Esto es una restricción de diseño, no una aspiración**: si la vista previa tuviera su propia lógica, sería una segunda implementación de la autorización, y divergiría.

**Malas, y hay que asumirlas**

- **La acotación por ámbito no la garantiza el framework.** Un módulo puede olvidarse de aplicarla y el fallo es silencioso hacia abierto. Se mitiga con API única, test de arquitectura y criterio de aceptación por recurso, pero no se elimina. Es la mayor deuda estructural que este paso deja abierta y conviene que esté escrita, no supuesta.
- **1.5 entrega un motor con muy pocos ámbitos ejercitados de verdad.** Para que no sea andamiaje sin consumidor —lo que este mismo ADR reprocha en otros sitios—, 1.5 debe llevar **al menos un resolutor real distinto de `todos`, probado de punta a punta**. El candidato con datos hoy es `propios` sobre `auditoria.leer` (un rol que solo ve las entradas de auditoría en las que él es el actor): es útil, es real y prueba el contrato entero. Sin ese caso, el contrato no está verificado y `INV-015` no se cumple.
- **Un cambio de esquema**, el único: `permission_role.scope` pasa a `NOT NULL` con `CHECK` sobre los seis valores. Todas las filas existentes valen `'todos'` (`CA-CORE-042`), así que es `expand` seguido de validación, pero pasa por `db-reviewer` y por la *skill* `migracion-segura` como cualquier otro.
- **`platform:sync-registry` gana una responsabilidad más** (`applicable_scopes`). Si no se ejecuta en un despliegue, un permiso nuevo no existe y su endpoint deniega: falla en cerrado, que es lo correcto, pero refuerza lo que `ADR-034 §6` ya obliga a documentar en `SYSADMIN.md`.
- **La documentación de `REQ-CORE` queda parcialmente desfasada** en cuanto 1.5 se implemente: `permisos.md §3` (columna «1.5»), `§5` (la regla «todo es `todos`» pierde vigencia y debe reemplazarse por la regla de `§4.1`, no borrarse sin más) y `§7`. Es trabajo de cierre del paso, no opcional.
- **Cuatro tests de arquitectura pendientes** que este ADR nombra y que 1.7b/#163 debería recoger: (1) ningún control de acceso comprueba código de rol en vez de permiso; (2) todo permiso `is_special_category` tiene emisión de evento `read`; (3) todo recurso con ámbito restringido se consulta por la API sancionada; (4) `runAsPlatform()` no aparece fuera de su lista de excepciones (issue #6). El (4) puede adelantarse a 1.5.

---

## 9 · Alternativas descartadas y por qué

- **Ámbitos registrables por módulo con nombres propios** — descartada en `§4.1`: rompe la comparabilidad entre roles (`RPERM-007`) y la explicabilidad de la vista previa (`RPERM-009`), y produce sinónimos a escala de 53 módulos.
- **Motor completo de los seis ámbitos en 1.5** (opción A de `§3`) — descartada: obliga a inventar qué es un grupo seis pasos antes de que `REQ-ACAD` lo defina, y el modelo falso quedaría dentro del subsistema de permisos, que es el peor sitio posible para un modelo que hay que tirar.
- **Aplazar los ámbitos a 1.11** (opción C de `§3`) — descartada: entre 8 y 10 módulos se construirían contra una API sin ámbito, y retrofitarlos no es una migración de esquema sino reescribir sus consultas de listado y de detalle una por una.
- **`deny` que resta por ámbito** — descartada en `§4.3`: exige una retícula de contención inexplicable, y su error se descubre tarde porque cierra permisos en vez de abrirlos.
- **Herencia viva de roles (`parent_role_id`)** — descartada en `§4.6`: cuarta capa de resolución sin caso de uso descrito, con coste asimétrico de retirada.
- **Motor de condiciones en 1.5** — descartada en `§4.5`: sin consumidor real hasta 1.16 y con parte de sus casos siendo reglas de negocio, no permisos.
- **Columna `conditions` reservada sin semántica** — descartada en `§4.5`: repetiría la trampa de `scope` entre 1.1 y 1.5, y esta vez sin la razón que la justificaba entonces.
- **Caché de permisos resueltos en Redis** — descartada en `§4.7`: su modo de fallo es conceder lo ya revocado, y no hay medición que justifique el riesgo.
- **Crear un módulo `App\Modules\Perm`** — descartada en `§4.10`: incumpliría `INV-007` en su primer commit al necesitar el modelo `Role` de `Core`.
- **Un solo paso 1.5 con la interfaz incluida** — descartada en `§6`: construye la tabla más compleja del producto antes del sistema de diseño que la sostiene, y obliga a la revisión de seguridad a atravesar código de interfaz para llegar al resolutor.

---

## 10 · Preguntas abiertas — resueltas por el usuario el 2026-09-04

Las cuatro decisiones se registran aquí con la respuesta dada, para que `spec-writer` no tenga que reconstruirlas de la conversación.

### 10.1 · ¿Se divide 1.5 en 1.5 y 1.5b, y dónde se sitúa 1.5b? — RESUELTO: dividir, 1.5b después de 1.9

Decisión: la recomendación de `§6` completa. `PLAN-IMPLEMENTACION.md` queda actualizado con **1.5 · Núcleo de autorización granular** en su sitio actual del bloque A y **1.5b · Editor de roles y vista previa de permisos efectivos** movido al final del bloque B, después de **1.9 · Tablas de datos**.

### 10.2 · ¿Se adopta `REQ-PERM` como identificador paraguas de la sección 11? — RESUELTO: sí

Decisión: `REQ-PERM` según `§5`. La sección 11 del documento de requisitos queda actualizada con el paraguas; los `RPERM-001`…`RPERM-015` se mantienen intactos.

### 10.3 · ¿Se difiere `RPERM-008` (permisos condicionales) a `REQ-CALIF` (1.16)? — RESUELTO: sí, diferido, sin columna reservada

Decisión: la recomendación de `§4.5` completa. `RPERM-008` queda fuera del alcance de 1.5 y pasa a ser requisito de entrada de `REQ-CALIF` (1.16), sin ninguna columna `conditions`/similar reservada en el esquema de 1.5.

### 10.4 · ¿Entra en 1.5 el punto 1 del issue [#6](https://github.com/pirexia/plataforma-educativa/issues/6)? — RESUELTO: sí

Decisión: el punto 1 (test de arquitectura que impida `runAsPlatform()` fuera de su lista de excepciones) entra en el alcance de 1.5. Los puntos 2 y 3 del issue quedan re-etiquetados a 1.6, por depender de tablas (`platform_admins`, `admin_action_logs`) que no existen hasta ese paso.

---

## 11 · Verificación de este ADR

Este ADR se considera correctamente aplicado cuando, al cerrar 1.5, se cumple:

- Ninguna fila de `permission_role` con `scope` fuera del vocabulario de `§4.1`, garantizado por `CHECK` en el motor y no solo por código.
- Conceder un permiso con un ámbito sin resolutor registrado responde `422`; una fila así, inyectada a mano, **deniega** al resolver.
- Existe al menos un resolutor de ámbito distinto de `todos` con test de listado **y** de detalle (`§8`).
- Una concesión de un permiso `is_special_category` desde un rol con `special_data_access = false` no concede nada, y hay test que lo demuestra.
- Una sola fila `deny` anula todas las concesiones `allow` de ese código en todos los roles del sujeto, con test.
- Crear un rol, clonar un rol, conceder, revocar y asignar dejan fila en `audit_logs` con estado anterior y posterior.
- La vista previa de permisos efectivos se calcula con el mismo código que la aplicación, verificable por lectura: no hay una segunda implementación de la resolución.
