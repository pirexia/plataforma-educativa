# ADR-051 · Claves de catálogo declaradas en código como identificador público (enmienda de `ADR-029`)

**Estado**: **ACEPTADA en su parte decidida por el usuario** — las cuatro rutas de *feature flags* direccionadas por `key` (`OPEN-BO-11`, aprobada el 2026-09-21) — y **PROPUESTA en su generalización**: las seis condiciones de §2, la lista declarada de §5 y el test de §5.2 son decisión de `architect` y **exceden lo que el usuario aprobó**, que fue un caso concreto. La implementación de `1.6e` **no depende de esa ratificación**: las cuatro rutas están decididas por las dos vías a la vez.
**Fecha**: 2026-09-21
**Enmienda**: `ADR-029`, sección "Identificadores", tercer punto ("Las rutas y la API usan **exclusivamente** el identificador público"). También la frase «**única** excepción argumentada» de `ADR-038 §4.2` y `§4.4` punto 3, que deja de ser cierta por aritmética — **no se reabre la decisión del cursor cifrado**, que sigue íntegra y es de otra naturaleza (§4.3).
**Se apoya en**: `ADR-034 §5` (catálogo en el código, materializado por `platform:sync-registry`), `ADR-045 §9`, `ADR-038 §4.2`
**No toca**: la clave primaria interna `bigint`, la prohibición de exponerla, `public_id` como convención por defecto, ni ninguna de las convenciones de tipos de `ADR-029`
**Afecta a**: `REQ-BO-005` (sub-paso `1.6e`), `REQ-BO-002` y `REQ-PERM` (§6, hallazgo preexistente), y a los 53 módulos como regla

---

## Contexto

La especificación de `1.6e` (`REQ-BO-005`, motor de *feature flags*) direcciona cuatro rutas por la `key` del *flag* —`/feature-flags/comedor.reserva_v2`— en vez de por `public_id` ULID (`api.md §2.11`). El usuario aprobó esa forma el 2026-09-21. `ADR-029` dice, sin excepción, que las rutas y la API usan **exclusivamente** el identificador público ULID.

`spec-writer` la registró como «excepción nombrada y acotada a `REQ-BO-005`» dentro de la documentación del módulo, y dejó escrita su reserva: una desviación de la letra de un ADR vigente que solo vive en el `datos.md` de un módulo es un cambio de facto registrado fuera del sitio donde el proyecto registra los cambios (`CLAUDE.md §11`). La reserva es correcta y este documento la resuelve.

Al comprobarlo contra el código aparece el dato que decide la forma de la enmienda, y no es el argumento de los *flags*: **la desviación ya existía antes de `1.6e`, en dos sitios, desde `0.8`**. Está en §6 y no se disimula aquí.

Tres cosas quedaban por decidir y ninguna la decide la aprobación del usuario:

1. Dónde se registra la decisión.
2. Si se registra como **excepción nombrada** —una lista que crece— o como **regla general comprobable** —un conjunto de condiciones que se verifican—.
3. Qué se hace con lo que ya estaba desviado y nadie había nombrado.

---

## Decisión

### 1. Qué se enmienda de `ADR-029`

Donde `ADR-029` dice «las rutas y la API usan **exclusivamente** el identificador público», pasa a decir: **las rutas y la API usan `public_id` ULID por defecto, y admiten además una clave de catálogo declarada en código cuando esa clave cumple las seis condiciones de §2, todas a la vez.**

Lo demás de `ADR-029` queda intacto, y conviene enumerarlo porque una enmienda mal leída se convierte en licencia: la clave primaria interna sigue siendo `bigint`, **sigue estando prohibido exponerla** en ruta, cuerpo o parámetro, `public_id` ULID sigue siendo obligatorio en toda entidad de negocio, y las convenciones de tipos no se tocan.

### 2. Las seis condiciones

Una clave natural es admisible como identificador en URL y API **solo si cumple las seis**. No hay ponderación ni «cumple cinco de seis con buen argumento»: fallar una es no cumplir.

| | Condición | Qué impide |
|---|---|---|
| **C1** | **La escribe el código del producto.** El valor lo declara un descriptor de módulo o un enumerado del código, y lo materializa `platform:sync-registry` (`ADR-034 §5`). **Ningún usuario de ningún centro, ni un operador de plataforma por la API, puede crearlo ni elegirlo.** | Que alguien exponga por clave lo que un cliente escribió: `tenants.slug`, `roles.code`, `academic_years.code`, un número de documento, un correo |
| **C2** | **Es dato de catálogo compartido, no de un centro.** El mismo valor en todos los centros, y enumerar el conjunto dice qué tiene el producto, **nunca quiénes son sus clientes ni qué guardan** | Que el motivo real de `ADR-029` —la enumerabilidad del censo de un centro— se erosione por la puerta de atrás (`INV-001`) |
| **C3** | **Única e inmutable mientras la fila viva.** El código **nunca reescribe** el valor: cambiar la clave es declarar otra y marcar `retired_at` en la anterior, jamás un `UPDATE` | Un identificador público que cambia, que es exactamente lo que `public_id` garantiza que no pasa |
| **C4** | **Formato cerrado, validado donde se materializa el catálogo**, con su expresión regular declarada en el `datos.md` del módulo; **y la ruta se declara con la restricción correspondiente** | Que una cadena arbitraria entre como identificador, y que un formato con puntos no encaje como un solo segmento de ruta |
| **C5** | **No contiene dato personal ni permite derivarlo**, ni de categoría especial | El argumento «el código de empleado es único y estable», que lo es y aun así no puede ir en una URL |
| **C6** | **No sustituye al ULID donde el ULID existe.** Si la tabla tiene `public_id`, lo conserva, y el ULID sigue siendo el identificador en **auditoría, exportaciones y toda referencia que deba sobrevivir a la fila** | Que la traza de auditoría quede colgada de un valor que el catálogo puede retirar |

**Sobre C6 hay dos formas de tabla y las dos son legítimas**: las que tienen clave interna `bigint` más `public_id` y ganan la clave como identificador **adicional** (`feature_flags`), y las tablas de referencia cuya clave primaria **ya es** el código y que no llevan `public_id` (`modules`, `permissions`, `ADR-034 §5`). A estas últimas **no se les añade `public_id`**: sería dar un segundo identificador público a una fila que nadie direcciona por ULID, y `ADR-029` no persigue tener dos, persigue no exponer la interna.

### 3. Qué **no** habilita esta enmienda

- **`roles.code` no es admisible, y el caso merece leerse porque es el que prueba que las condiciones hacen trabajo.** Parece una clave natural igual que las otras, pero un centro crea roles propios con el código que quiera (`StoreRoleRequest`, `regex:/^[a-z][a-z0-9_]{2,63}$/`) y la unicidad es por centro (`roles_tenant_code_unique`): falla **C1** y falla **C2**. Los roles se direccionan hoy por `public_id` y **así se quedan**.
- **Un `role_code` dentro del cuerpo de una regla de *flag* no es un identificador de recurso** y no entra en esta regla: es un valor de comparación que deliberadamente no se resuelve contra ninguna fila —un código inexistente se acepta y no expone a nadie (`CA-BO-086`)—.
- **Un segmento de ruta que selecciona un aspecto y no identifica una fila** —`/tenant/settings/assets/{kind}`— nunca estuvo bajo `ADR-029` y sigue sin estarlo. No es una excepción ni necesita esta regla.
- **La analogía no basta.** Un módulo que quiera direccionar por clave natural **demuestra las seis condiciones en su `datos.md`**; citar este ADR o el precedente de `REQ-BO` no es demostrarlas.

### 4. Qué habilita, a fecha de hoy

Exactamente tres claves, y las tres cumplen las seis:

| Clave | Dónde se expone | Estado |
|---|---|---|
| `feature_flags.key` | Cuatro rutas de `REQ-BO/api.md §2.11` | Decidida por el usuario el 2026-09-21; se implementa en `1.6e` |
| `modules.code` | `PUT /tenants/{public_id}/modules/{module_code}` (backoffice) y el cuerpo de `GET /modules` | **Ya expuesta desde `1.6c`**, sin registro. §6 |
| `permissions.code` | `GET /permissions` (identificador único de la respuesta) y el cuerpo de `PUT /roles/{public_id}/permissions` | **Ya expuesta desde `1.1`**, sin registro. §6 |

### 5. La regla es comprobable, o no es una regla

**5.1 · Registro declarado.** La lista de claves admitidas vive **en el código**, en un único registro de soporte, no en este ADR: un ADR es inmutable y una lista que crece no puede vivir dentro de uno. La tabla de §4 es una foto del 2026-09-21, no el censo canónico. Cada módulo que añada una entrada lo anota además en la línea de `public_id` de su checklist de `datos.md`.

**5.2 · Test de arquitectura.** Se recorren las rutas registradas y **todo parámetro de ruta es o `public_id`/`publicId`, o una entrada declarada en ese registro**. Cualquier otro parámetro falla la suite. Es lo que convierte esto en un guardarraíl en vez de en una costumbre: sin el test, la regla se erosiona módulo a módulo, que es exactamente lo que ya pasó (§6).

**5.3 · Dónde se implementa.** El registro y el test entran con `1.6e`, que es el sub-paso que estrena la regla. `modules.code` y `permissions.code` se declaran en el mismo registro y pasan a estar cubiertas por el mismo test.

---

## Motivo

**Por qué regla general y no excepción nombrada, que era lo que proponía la especificación del módulo.**

1. **La excepción nombrada sería falsa el día que se escribe.** Decir «una sola excepción, nombrada y acotada» —como hace hoy `CA-BO-071`— es una afirmación comprobable, y al comprobarla contra el código resulta **falsa**: ya hay otras dos (§6). Un criterio de aceptación que afirma algo falso no protege nada; enseña a no creerse los criterios.
2. **El argumento no discrimina, y un argumento que no discrimina no acota.** «Única, estable, inmutable, no filtra cardinalidad, el código ya la escribe» describe la clase entera de «catálogo materializado desde el código». No hay nada en un *feature flag* que no sea igual de cierto en un módulo. Llamar excepción a lo que es una categoría no la contiene: invita a que el siguiente módulo la invoque por parecido, y el parecido no es una condición.
3. **Una lista de excepciones erosiona; un conjunto de condiciones se verifica.** A tres años, la lista crece por analogía, cada entrada cita a la anterior, y nadie vuelve a mirar el argumento original. Las condiciones se comprueban una a una, en el `datos.md` del módulo, y **una de ellas la comprueba la suite**.
4. **Lo reversible sobre lo óptimo.** La regla se revoca en un sitio: se borra el registro de §5.1, el test empieza a fallar y señala exactamente las rutas que hay que cambiar. Una lista de excepciones repartida por cinco `datos.md` no se revoca, se persigue.
5. **El coste de implementación es menor, no mayor.** Regla general: un registro, un test, una línea de checklist. Excepción nombrada: la misma documentación en cada módulo que la pida, más la discusión de si el nuevo caso se parece bastante al anterior, cada vez.

**Por qué las condiciones son seis y no dos.** `ADR-029` no protege la forma del identificador, protege un motivo concreto: que no se pueda enumerar el censo de un centro. Las dos propiedades que la especificación destacaba —única y estable— **no protegen ese motivo**: `tenants.slug` es único y estable, y enumerarlo enumera a los clientes del producto. Las que de verdad lo protegen son **C1** (nadie de fuera escribe el valor) y **C2** (el valor no es de nadie). Sin esas dos, la regla habría sido una licencia con buena redacción.

**Por qué ADR y no una nota en el módulo.** `ADR-038 §4.4` ya sentó el precedente exacto: una excepción argumentada a `ADR-029` —la clave interna dentro del cursor cifrado— declarada en un ADR, «en lugar de disimularla», conservando el motivo del ADR y no su letra. El sitio estaba decidido desde entonces.

---

## Consecuencias

- **`ADR-029` deja de ser absoluto en un punto y hay que decirlo entero**: quien lo lea sin este ADR aplicará una regla que ya no rige. Por eso §1 enumera lo que sigue intacto.
- **`ADR-038 §4.2` y `§4.4` punto 3 dejan de ser exactos** al decir «única excepción argumentada». La decisión del cursor cifrado **no se reabre**: es de otra naturaleza —la clave interna, cifrada, no direccionable— y este ADR no la toca. Lo que cambia es la aritmética de la frase.
- **`REQ-BO/api.md §2.11`, `datos.md §11` y `CA-BO-071` quedan desactualizados en la forma, no en el fondo.** Las cuatro rutas por `key` siguen siendo correctas; lo que cambia es que ya no se sostienen en «excepción acotada a este módulo» sino en seis condiciones que el módulo cumple. **`CA-BO-071` tiene que reformularse**: hoy comprueba «que la excepción es una y está acotada», y eso es literalmente falso en el producto. Es trabajo de `spec-writer`, no de este ADR (§7).
- **`1.6e` gana trabajo real y acotado**: el registro de §5.1, el test de §5.2 y la restricción de ruta que exige C4 —el formato de `key` admite puntos, y sin `where` Laravel no encaja `comedor.reserva_v2` como un segmento—.
- **`subject_public_id` de `admin_action_logs` sigue guardando el ULID del *flag* y no su `key`**, por C6. No cambia.
- **Riesgo aceptado**: la regla es más ancha que el caso que la motivó. Se acota con el test, con la obligación de demostrar las seis en el `datos.md` y con §3, que nombra el caso que **no** pasa (`roles.code`). Si aun así aparece un uso que cumple las seis y resulta mal, se revoca por §5.1 en un sitio.

---

## Alternativas descartadas y por qué

| Alternativa | Por qué no |
|---|---|
| **Dejarlo en `docs/modulos/REQ-BO/`, como excepción nombrada** | Es la que proponía la especificación. Registra fuera de sitio un cambio de decisión (`CLAUDE.md §11`), es invisible para quien revise otro módulo, y su afirmación central —«una sola excepción»— es falsa contra el código |
| **No enmendar: rutas de *flags* por `public_id`** | No está en discusión, el usuario decidió el 2026-09-21. Y aunque lo estuviera, no arregla nada: `modules.code` y `permissions.code` seguirían desviadas |
| **Reescribir `ADR-029` en su fichero** | Los ADR son inmutables (`CLAUDE.md §6` regla 3). Quien lea el histórico tiene que poder ver qué se decidió y qué se enmendó después |
| **Regla amplia: «cualquier clave natural única y estable»** | Es la versión de la regla sin C1 ni C2, y admite `tenants.slug` y el número de documento de un alumno. Es la licencia que `ADR-029` existe para negar |
| **Regla sin test, solo documentada** | Es la que ya teníamos de facto, y produjo dos desviaciones silenciosas en trece meses (§6). Una regla que nadie comprueba es una costumbre |
| **Amnistiar `modules.code` y `permissions.code` de paso, sin nombrarlas** | Convertiría una enmienda en un encubrimiento. Se regularizan hacia delante **y** se reporta el fallo de proceso (§6) |
| **Mantener la lista de claves dentro de este ADR** | Obligaría a editar un documento inmutable cada vez que se añada una. Por eso la lista vive en el código y §4 es una foto fechada |

---

## Hallazgo reportado y no corregido aquí

**La desviación de `ADR-029` es anterior a `1.6e` y nunca se registró. Severidad Media** (documentación contra código, `CLAUDE.md §5`; sin impacto de seguridad: ninguno de los dos códigos filtra nada de ningún centro).

- **`permissions.code`**, desde `1.1`. La tabla se crea con `$table->text('code')->primary()` y **sin `public_id`** (`2026_08_18_100500_create_permissions_table.php`). `GET /api/v1/permissions` devuelve `code` como único identificador y `PUT /roles/{public_id}/permissions` identifica cada permiso por `code` en el cuerpo.
- **`modules.code`**, desde `0.8` en el cuerpo y desde `1.6c` en una **ruta**: `PUT /tenants/{public_id}/modules/{module_code}` (`app/Modules/Backoffice/Http/routes.php:147`). Tabla creada igual, `code` como clave primaria y sin `public_id`.

**Lo que importa no es que estén expuestas —cumplen las seis condiciones y por eso §4 las admite— sino que nadie lo registró.** `ADR-034 §5` (2026-08-18, ocho días después de `ADR-029`) fijó esa forma de tabla de referencia y **no nombró la divergencia**, y desde entonces dos revisiones de esquema, tres cierres de fase y `doc-reviewer` pasaron por encima sin verla. Es el mismo modo de fallo que `CLAUDE.md §6` regla 7 ya documentó para los documentos raíz: **el checklist no miraba ahí**.

Este ADR lo regulariza hacia delante y **no lo absuelve retroactivamente**. Issue de severidad Media abierto por la sesión orquestadora: [#238](https://github.com/pirexia/plataforma-educativa/issues/238), con la propuesta ya escrita: declarar ambas claves en el registro de §5.1 dentro de `1.6e`, sin tocar ni una migración ni una ruta.

---

## Preguntas abiertas

- **`OPEN-051-01` · ¿Ratifica el usuario la generalización?** Lo aprobado el 2026-09-21 fue el caso de `REQ-BO-005`. Las seis condiciones, el registro y el test son decisión de `architect`. **No bloquea `1.6e`**: si la generalización se rechaza, las cuatro rutas siguen decididas y este documento se reduce a la excepción nombrada que proponía la especificación, con el hallazgo de §6 igual de abierto.
- **`OPEN-051-02` · ¿Dónde vive exactamente el registro de §5.1?** Hay dos sitios razonables —una constante de `App\Support` o el descriptor de cada módulo— y la elección es de implementación, no de arquitectura. La decide `1.6e` al escribirlo, con una condición que sí es de arquitectura: **un solo sitio, y el test lo lee de ahí**.
