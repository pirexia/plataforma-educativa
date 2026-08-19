# ADR-038 · Convenciones de la API REST

**Estado**: PROPUESTA
**Fecha**: 2026-08-19
**Resuelve**: `OPEN-CORE-09` (`docs/modulos/REQ-CORE/funcional.md §10`)
**Concreta**: `INV-006` (API primero), `INV-009` (i18n), `INV-011` (idempotencia), `INV-013` (trazabilidad)
**Matiza**: `ADR-029` (§4.4 — el cursor de paginación transporta la clave interna cifrada; se conserva el motivo del ADR, no su letra)
**Precisa**: `ADR-034 §3` (el índice de `audit_logs` necesita desempate — §4.4), `ADR-025` (cliente HTTP de la SPA — §13)
**Corrige**: `docs/modulos/REQ-CORE/api.md §9` (propuesta previa; ver §17 para el listado de cambios)
**Afecta a**: los 53 módulos del producto. `RARQ-API-*`, `RNF-MANT-007`, `CLAUDE.md §10`
**No cierra**: `OPEN-CORE-02`, `OPEN-CORE-03`, `OPEN-CORE-10`

---

## 1. Contexto

El paso `1.1` (`REQ-CORE`) es el **primer módulo con endpoints HTTP reales**. Las decisiones que se tomen al escribirlo no se quedan en él: los 52 módulos restantes copiarán lo que encuentren, porque copiar es lo barato y decidir de nuevo es lo caro. Si la convención se fija por omisión —por lo que se escriba primero— cambiarla después no es refactorizar un módulo, es tocar 53 y romper a todos los clientes a la vez.

`spec-writer` produjo en `api.md §9` una propuesta completa y argumentada, y señaló correctamente que **una convención transversal decidida dentro de la especificación de un módulo no es una decisión arquitectónica**: no es visible desde los otros 52 módulos, no es inmutable y nadie la busca ahí. Este ADR la ratifica en lo esencial y la corrige en siete puntos concretos, cada uno con su motivo.

Tres restricciones acotan todo lo que sigue y explican por qué se descarta la opción sofisticada casi siempre:

1. **Un solo desarrollador durante años.** Toda convención que exija disciplina sostenida para no degradarse está mal elegida. Se prefiere la que el framework impone sola o la que se verifica en CI.
2. **La SPA es un cliente más (`INV-006`), pero es el único cliente que existe.** No hay que optimizar para consumidores hipotéticos; sí hay que evitar que la API se diseñe *contra* el cliente real y obligue a una capa de adaptadores por módulo.
3. **Cuatro idiomas obligatorios (`ADR-021`) y ningún literal en código (`INV-009`).** Esto condiciona el formato de error más de lo que parece: es la diferencia entre escribir cada mensaje una vez o dos.

### 1.1 Por qué ahora y no cuando haya tres módulos

Porque cinco de las seis decisiones son **incompatibles hacia atrás** una vez publicadas. Cambiar la forma de `meta`, el nombre de los filtros, el cuerpo del error o la semántica de `PATCH` rompe a todo cliente escrito contra la versión anterior. La única barata de mover es la política de idempotencia, porque es aditiva. Decidir hoy cuesta una sesión; decidir en el módulo 12 cuesta once módulos y una versión de API.

---

## 2. Qué NO decide este ADR

- **No decide autenticación ni CSRF.** Es `ADR-025` y su implementación es `REQ-AUTH-001` (paso `1.2`).
- **No decide la política de límite de tasa** (qué endpoint tiene qué cupo). Fija solo la **forma** de la respuesta `429` (§6.5).
- **No decide el modelo de permisos.** Es `permisos.md` y la skill `permisos-y-roles`. Aquí solo se fija cuándo se responde `403` y cuándo `404` (§6.4).
- **No decide webhooks salientes ni notificaciones push.** No hay requisito que los pida todavía.
- **No introduce GraphQL, JSON:API ni OData.** Ver §16.
- **No introduce ninguna dependencia nueva** de PHP ni de JavaScript. Todo lo que decide se implementa con Laravel y con el cliente propio de `apps/web/src/api/client.ts` (`RNF-MANT-007`).

---

## 3. Forma de la respuesta: envoltura

La propuesta de `api.md §9` no lo trataba, pero `api.md §2` devuelve el recurso individual **desnudo** y `api.md §3` devuelve las colecciones **envueltas en `data`**. Esa inconsistencia no es un descuido de redacción: es exactamente la decisión que Laravel toma por defecto y al revés (`JsonResource` envuelve también el recurso individual). Sin decisión explícita, cada módulo tirará una moneda.

### 3.1 Decisión

| Caso | Forma |
|---|---|
| Recurso individual | El objeto **desnudo**, sin envoltura. `{"public_id": "...", ...}` |
| Colección | `{"data": [...], "meta": {...}}` |
| Escritura sin cuerpo | `204` sin cuerpo |
| Error | `application/problem+json` (§6) |

Se ejecuta con `JsonResource::withoutWrapping()` en un *service provider*, **una vez y para todo el proyecto**. No queda a criterio de cada módulo.

**Motivo**: la colección necesita un sitio donde poner la paginación, y ese sitio es `meta`; el recurso individual no necesita ninguno, porque lo transversal (`request_id`, idioma) va en cabeceras. Envolver el recurso individual añadiría un nivel de indirección a cada acceso del cliente a cambio de nada. Y `withoutWrapping()` global es preferible a que 53 módulos recuerden aplicarlo: si alguien olvida la envoltura en un módulo, es un fallo visible en el contrato; si alguien olvida `withoutWrapping()`, es un fallo invisible que se descubre en el navegador.

### 3.2 Nombres y tipos

- **`snake_case`** en todas las claves de petición y respuesta, sin excepción. Coincide con las columnas y con la convención de PHP; convertir a `camelCase` en la frontera es un mapeo más que mantener en 53 módulos y una fuente de fallos silenciosos al añadir un campo. La SPA consume `snake_case` directamente.
- **Fechas y horas**: ISO 8601 con zona, siempre UTC en la respuesta (`2026-08-19T09:00:00Z`). Nunca una hora local sin zona (`ADR-029`, `TIMESTAMPTZ`). Las fechas sin hora (`birth_date`) van como `AAAA-MM-DD`.
- **Importes**: entero de céntimos más `currency` ISO 4217 en un objeto (`{"amount": 12550, "currency": "EUR"}`). Nunca un decimal en JSON (`ADR-029`).
- **Identificadores**: siempre `public_id` ULID (`ADR-029`). La clave interna no aparece en ningún cuerpo, ninguna ruta y ningún parámetro. Única excepción argumentada: el interior cifrado del cursor de paginación (§4.4).
- **Enumerados**: cadena en minúsculas con `_`, en el idioma del **dominio del código** (`activo`, `pendiente`), no traducida. La traducción para mostrar la resuelve el cliente o el servidor por catálogo, nunca cambiando el valor.

---

## 4. Paginación

### 4.1 Opciones consideradas

| # | Opción | Coste en solitario | Mantenimiento a 3 años | Invariantes | Reversibilidad |
|---|---|---|---|---|---|
| A | Solo por página (`page`/`per_page`) en todo | Muy bajo (es el defecto de Laravel) | **Malo**: `OFFSET` sobre `audit_logs` particionada degrada linealmente y produce filas repetidas o saltadas mientras entran registros | Correcto | Baja: cambiar la forma de `meta` rompe clientes |
| B | Solo por cursor en todo | Medio | Bueno en rendimiento | **Choca con la UI**: no hay `total` ni salto a página N; las tablas de administración de 1.9 lo necesitan | Baja |
| C | **Dos modos con criterio objetivo de cuál aplica** | Bajo | Bueno | Correcto | Media |
| D | Dos modos a criterio del autor de cada módulo | Muy bajo | **Malo**: sin criterio, la elección se hace por costumbre y acaba habiendo cursor donde estorba y `OFFSET` donde duele | Correcto | Media |

La opción D es la que resulta *de facto* si este ADR se limita a decir "hay dos modos". El valor no está en ofrecer dos, está en que el criterio sea comprobable por un tercero sin conocer el módulo.

### 4.2 Decisión: opción C, con criterio objetivo

El criterio **no es** "cardinalidad baja" (no es medible al escribir la especificación) sino el **origen de las filas**:

- **Catálogo de entidades** — las filas nacen de una acción administrativa humana y su número está acotado por el tamaño del centro: usuarios, roles, invitaciones, módulos, alumnos, matrículas, aulas, facturas de un curso. → **paginación por página**.
- **Flujo de eventos** — las filas nacen de la actividad del sistema y su número crece con el tiempo de uso, no con el tamaño del centro: auditoría, notificaciones, mensajes, registros de asistencia, apuntes de conciliación, trazas de importación. → **paginación por cursor**, obligatoria.

La regla operativa: **si la tabla es *append-only* o está particionada por curso, es cursor**. Ese dato ya está en el `datos.md` de cada módulo, así que la elección es verificable en revisión sin discutir estimaciones.

### 4.3 Paginación por página

```
?page=1&per_page=25
```

```json
{ "data": [...], "meta": { "current_page": 1, "per_page": 25, "total": 137, "last_page": 6 } }
```

- `per_page` por defecto **25**, máximo duro **100**. Pedir más devuelve `422`; **no se recorta en silencio** (un recorte silencioso hace que el cliente crea que ha leído todo).
- `page` fuera de rango devuelve `200` con `data: []`, no `404`. Una página vacía es una respuesta válida, no un recurso inexistente.
- `meta` no lleva `links`. Las URLs las construye el cliente, que ya sabe la ruta; incluirlas obliga al servidor a conocer su URL pública, que depende del despliegue (`OPEN-08`, sin resolver).

### 4.4 Paginación por cursor

```
?cursor=<opaco>&limit=50
```

```json
{ "data": [...], "meta": { "next_cursor": "...", "has_more": true } }
```

- `limit` por defecto **50**, máximo duro **200**. Mismo trato que `per_page`.
- Sin `total`. Contar filas de una tabla *append-only* particionada es precisamente lo que se quiere evitar. Si una pantalla necesita un número, es un contador aproximado calculado aparte, no un `COUNT(*)` en la ruta caliente.
- `next_cursor` es `null` cuando `has_more` es `false`.

Tres reglas que la propuesta de `api.md §9` no contemplaba y sin las cuales la paginación por cursor **es incorrecta**, no solo incompleta:

1. **Orden total estricto.** Todo listado por cursor ordena por una tupla cuyo último componente es único. Para `audit_logs` es `(occurred_at DESC, id DESC)`. Sin el desempate, dos filas con el mismo `occurred_at` en el límite de página se pierden o se repiten. **Consecuencia sobre `ADR-034 §3`**: el índice `(tenant_id, occurred_at DESC)` pasa a ser `(tenant_id, occurred_at DESC, id DESC)`. Son 8 bytes por entrada y evita una ordenación en cada página.

2. **El cursor es opaco y cifrado**, no un base64 legible. Se serializa con el cifrado autenticado de Laravel (`Crypt`, AES-256-GCM con `APP_KEY`) y transporta:

   | Campo | Para qué |
   |---|---|
   | `v` | Versión del formato, para poder cambiarlo sin romper clientes |
   | `k` | Tupla de la clave de orden de la última fila entregada |
   | `f` | Huella (hash) del conjunto normalizado de filtros de la petición |
   | `t` | `tenant_id` del emisor |

   - Un cursor manipulado o de otra clave falla al descifrar → `422`, sin consulta a base de datos.
   - Un cursor emitido con otros filtros → `422`. Sin esto, cambiar un filtro a mitad de paginación devuelve un tramo incoherente que parece un fallo de datos.
   - Un cursor de otro tenant → `422`. Defensa en profundidad sobre `INV-001`: la RLS ya lo impediría, pero el cursor no debe ser siquiera un vector que probar.

3. **El interior del cursor lleva la clave interna `bigint`, y eso es una excepción deliberada a `ADR-029`.** Se declara aquí en lugar de disimularla:

   - `ADR-029` prohíbe exponer la clave interna porque **permite enumerar registros**. Un cursor cifrado con AES-GCM no es legible, no es construible por el cliente, no es direccionable y no aparece en ninguna ruta: el motivo de `ADR-029` queda intacto.
   - La alternativa que respetaría la letra —desempatar por `public_id` y añadirlo al índice— cuesta ~26 bytes por entrada en el índice principal de la tabla de mayor crecimiento del sistema, permanentemente, a cambio de nada verificable.
   - Se elige preservar el motivo y pagar 8 bytes en vez de 26. Queda escrito para que una revisión futura no lo lea como un descuido.

### 4.5 Consecuencia de interfaz que hay que asumir, no esconder

Un listado por cursor **no puede tener paginador numerado**. La pantalla de auditoría de `REQ-CORE-005` y todas las de flujo de eventos usarán "cargar más" o desplazamiento infinito, no el mismo componente de tabla que los catálogos. Es un componente más que mantener y es el precio correcto: la alternativa es un paginador numerado que miente o que tarda segundos.

---

## 5. Filtrado, búsqueda y orden

### 5.1 Sintaxis de los filtros: la propuesta era internamente inconsistente

`api.md §9.2` decide "parámetros planos, no sintaxis anidada tipo `filter[status]`" y a la vez dice "repetir un parámetro es un `OR`". **Las dos cosas juntas no funcionan en PHP.** Ante `?status=activo&status=inactivo`, PHP conserva únicamente el último valor: `$request->query('status')` devuelve `"inactivo"` y el filtro se aplica mal, en silencio, sin error. Para que PHP construya un array hace falta escribir `status[]=activo&status[]=inactivo`, que es precisamente la sintaxis con corchetes que la propuesta quería evitar.

| # | Opción | Coste | Mantenimiento | Nota |
|---|---|---|---|---|
| A | `status=activo&status=inactivo` | — | — | **No funciona en PHP.** Descartada por hechos, no por gusto |
| B | `status[]=activo&status[]=inactivo` | Bajo | Correcto | Nativo en Laravel (`array` + `Rule::in`), pero contradice la premisa de "planos" y ensucia la URL |
| C | **`status=activo,inactivo`** | Bajo | Correcto | Un `explode(',')` en una regla de validación reutilizable. URL corta —relevante con varios ULID de 26 caracteres—. Es la forma `style: form, explode: false` nativa de OpenAPI 3.1 |

### 5.2 Decisión

- **Parámetros planos y nombrados.** Nada de `filter[...]`, `sort[...]` ni mini-lenguajes de consulta (`?filter=status:eq:activo`). Motivo: cada parámetro se documenta uno a uno en OpenAPI, se valida con las reglas normales del framework, y no existe la tentación de traducir una expresión de cliente a SQL.
- **Un valor múltiple se envía separado por comas** (opción C): `status=activo,inactivo`, `role=01J8...,01J9...`. Aplica solo a enumerados e identificadores, que por construcción no contienen comas. Un valor de texto libre nunca es múltiple.
- **Semántica**: comas dentro de un parámetro son `OR`; parámetros distintos son `AND`. No hay forma de expresar `OR` entre campos distintos, y no se añadirá: quien la necesite, que pida un endpoint.
- **Rangos**: sufijos `_from` y `_to`, ambos inclusivos (`occurred_at_from`, `birth_date_to`). No se usa `gte`/`lte` ni notación de intervalo.
- **Booleanos**: `true`/`false`. No se aceptan `1`, `0`, `si`, `on`.
- **Búsqueda de texto libre**: el parámetro se llama **`q`** en todos los módulos, sin excepción, y OpenAPI declara sobre qué campos busca en cada endpoint. Prohibidos `search`, `query`, `term`, `filtro`. Es una convención de nombre y cuesta cero imponerla desde el primer módulo.
- Un parámetro **desconocido** se **ignora**. No devuelve `422`. Motivo: es la única política compatible con la regla de versionado de §7 —un cliente antiguo que arrastre un parámetro retirado debe seguir funcionando— y evita que añadir un filtro nuevo en el cliente antes que en el servidor rompa la pantalla entera.
- Un parámetro **conocido con valor inválido** devuelve `422`. Ignorarlo silenciosamente devolvería datos que el usuario cree filtrados y no lo están; en un producto con datos de menores, eso es un incidente de privacidad, no una molestia.

### 5.3 Orden

- Parámetro **`sort`**, con prefijo `-` para descendente: `sort=-created_at`.
- **Lista blanca cerrada por endpoint**, declarada en OpenAPI como `enum`. Nunca se acepta un nombre de columna que llegue del cliente. Un valor fuera de la lista es `422`.
- **Una sola columna. No hay orden multi-columna.** Es un "no" explícito: cada combinación adicional es un índice más que justificar en `postgres-rendimiento`, y ningún requisito lo pide. Consecuencia directa: TanStack Table se configura con `enableMultiSort: false` (§13.3). Si algún día hace falta, admitir `sort=-a,b` es un cambio compatible (§7).
- Todo listado tiene **orden por defecto explícito y determinista**, declarado en OpenAPI. Un listado sin `ORDER BY` en PostgreSQL no garantiza orden estable entre páginas: es el fallo clásico de "una fila aparece dos veces".

---

## 6. Formato de error

### 6.1 Base: RFC 9457, ratificada

`application/problem+json` para **toda** respuesta `4xx` y `5xx`, sin excepción y en todos los módulos. Es un estándar, no hay que inventar ni documentar la forma, y hay clientes que ya la entienden.

```json
{
  "type": "urn:pge:error:validation",
  "title": "Los datos enviados no son válidos",
  "status": 422,
  "detail": "El idioma preferido no está entre los idiomas activos del centro.",
  "instance": "/api/v1/users",
  "request_id": "01J8...",
  "errors": {
    "person.locale": [
      { "code": "core.validation.locale_not_active", "message": "El idioma «de» no está activo en este centro.", "params": { "locale": "de" } }
    ]
  }
}
```

### 6.2 Corrección 1: `type` es un URN, no una URL

La propuesta usaba `https://plataforma/errors/validation`. Dos problemas concretos:

- El nombre de dominio del producto **no está decidido** (`OPEN-08`). Escribir hoy un `https://` con un dominio inventado significa que el día que exista el dominio real hay dos opciones, ambas malas: cambiarlo —rompiendo a todo cliente que use `type` como discriminador— o dejar para siempre una URL falsa en cada respuesta de error del sistema.
- Una `type` con esquema `https` invita a que alguien la abra. Si no resuelve, parece un fallo del servidor; si resuelve, hay que mantener una página por cada tipo de error.

**Decisión**: `type` es un URN con la forma **`urn:pge:error:<slug>`**. Estable, sin dependencia de DNS, imposible de confundir con documentación navegable, y válido como identificador según RFC 9457 (que solo exige una referencia URI, no que sea dereferenciable). No se usan referencias relativas: un cliente conforme las resolvería contra la URL de la petición y produciría una URL de aspecto real que devuelve `404`.

Catálogo inicial, **cerrado y ampliable solo por ADR o por especificación de módulo**:

| `type` | Estado | Cuándo |
|---|---|---|
| `urn:pge:error:malformed` | 400 | JSON inválido, cabecera obligatoria ausente |
| `urn:pge:error:unauthenticated` | 401 | Sin sesión o sesión caducada |
| `urn:pge:error:forbidden` | 403 | Autenticado, sin el permiso requerido |
| `urn:pge:error:module-disabled` | 403 | El módulo no está activo en este tenant (`RMOD-009`) |
| `urn:pge:error:not-found` | 404 | Inexistente **o de otro tenant** |
| `urn:pge:error:method-not-allowed` | 405 | |
| `urn:pge:error:conflict` | 409 | Regla de negocio o transición de estado inválida |
| `urn:pge:error:gone` | 410 | Recurso vencido (descarga caducada) |
| `urn:pge:error:payload-too-large` | 413 | |
| `urn:pge:error:unsupported-media-type` | 415 | |
| `urn:pge:error:validation` | 422 | Validación de cuerpo o de parámetros |
| `urn:pge:error:too-many-requests` | 429 | Límite de tasa |
| `urn:pge:error:internal` | 500 | |
| `urn:pge:error:unavailable` | 503 | Mantenimiento o dependencia caída |

Separar `forbidden` de `module-disabled` con el mismo `403` importa: el cliente debe poder mostrar "no tienes permiso" frente a "este módulo no está contratado", que son mensajes distintos, sin analizar texto.

### 6.3 Corrección 2: `errors` lleva clave **y** mensaje ya traducido

La propuesta decidía que `errors` llevara **solo claves de traducción**, "para que el cliente decida cómo presentarlas". Llevado a sus consecuencias, eso obliga a que la SPA mantenga el catálogo de mensajes de validación de los 53 módulos, en los 4 idiomas obligatorios, **duplicado** respecto al del servidor. Dos catálogos que hay que mantener sincronizados es exactamente el tipo de trabajo que un desarrollador en solitario deja de hacer al tercer mes; y el modo de fallo es el peor posible: una clave que el cliente no conoce se muestra como texto vacío o como `core.validation.locale_not_active` en la cara del usuario.

**Decisión**: cada entrada de `errors` es un objeto con tres campos:

- **`code`** — la clave de traducción. Es lo que el cliente usa para lógica (resaltar un campo, ofrecer una acción concreta, contar). Estable; cambiarla es incompatible (§7).
- **`message`** — el mensaje **ya renderizado por el servidor** desde su catálogo, en el idioma resuelto (§11). Cumple `INV-009` igual de bien: nada está escrito en el código, sale del sistema de traducción. Lo que cambia es *dónde* se renderiza, no si se traduce.
- **`params`** — los valores de interpolación (`{"max": 100}`, `{"locale": "de"}`). Permiten que un cliente que sí quiera su propio texto lo componga, sin obligar a ninguno a hacerlo.

Así el mensaje se escribe **una sola vez**, en el servidor; la SPA funciona sin catálogo propio de errores; y el cliente que quiera personalizar sigue pudiendo, por `code` y `params`. No se pierde nada respecto a la propuesta y se elimina un catálogo entero de mantenimiento.

`title` y `detail` van igualmente traducidos. `detail` describe **este** fallo concreto; `title` es genérico del tipo.

### 6.4 `403` frente a `404`: ratificado

Se responde `403` cuando el recurso **existe en el tenant del solicitante** y le falta permiso; `404` cuando no existe **o pertenece a otro tenant**. Nunca se confirma la existencia de un recurso ajeno (`CA-CORE-073`, `INV-001`). Esta regla es de seguridad, no de estilo, y aplica a los 53 módulos sin excepción.

### 6.5 Reglas de los errores que la propuesta no cubría

- **`429` y `503` incluyen `Retry-After`.** Sin esa cabecera el cliente solo puede adivinar, y adivinar mal es reintentar en bucle contra un servidor ya saturado.
- **`5xx` nunca lleva `detail` con información interna.** Ni mensaje de excepción, ni traza, ni nombre de tabla, ni SQL. `title`/`detail` genéricos y `request_id`; la causa real vive únicamente en el log correlacionado por ese `request_id` (`INV-013`). Es la regla que hace que un fallo sea diagnosticable sin ser explotable.
- **`request_id` está presente en todo cuerpo de error**, siempre, incluidos `401` y `500`. Es el único dato que convierte un "me ha fallado" del usuario en una consulta de log de un segundo.
- **`instance`** es la ruta de la petición, sin parámetros de consulta (pueden contener datos personales que acabarían en logs de cliente).
- Un error de **validación de parámetros de consulta** usa el mismo `422` y la misma forma que uno de cuerpo, con el nombre del parámetro como clave de `errors`.

---

## 7. Versionado y evolución

### 7.1 Decisión

- Prefijo de ruta **`/api/v1`**. Ratificado: es explícito, cacheable, trivial de enrutar en Traefik y visible en cualquier log. No se usa negociación por cabecera (`Accept: application/vnd...`): invisible en el navegador, invisible en los logs y una fuente de fallos de caché a cambio de elegancia.
- **`v1` es la versión de todo el producto**, no una por módulo. Un `v2` de un solo módulo obligaría al cliente a mantener dos bases de rutas.

### 7.2 Qué es compatible y qué no — enumerado, no descrito

Sin esta lista, "no romper la compatibilidad" es retórica. Con ella, es comprobable en revisión.

**Compatible (va en `v1`):**

- Añadir un endpoint.
- Añadir un campo a una respuesta.
- Añadir un campo **opcional** a una petición.
- Añadir un parámetro de consulta opcional.
- Relajar una validación.
- Añadir un valor a un enumerado de respuesta **si el enumerado se declaró extensible** (§7.3).

**Incompatible (exige `v2` o el procedimiento de §7.4):**

- Eliminar o renombrar un campo de la respuesta.
- Cambiar el tipo o el formato de un campo.
- Añadir un campo obligatorio a una petición, o hacer obligatorio uno que era opcional.
- Endurecer una validación existente.
- Cambiar el código HTTP o el `type` de un caso ya existente.
- Cambiar el significado de un `code` de `errors`.
- Cambiar el orden por defecto de un listado.

### 7.3 Todos los enumerados de respuesta son extensibles

Añadir un valor a un enumerado parece aditivo y **no lo es** para un cliente TypeScript con uniones discriminadas y `switch` exhaustivo: al llegar un valor nuevo, o falla la comprobación de tipos en compilación, o el `switch` cae en un caso que no existe en ejecución. Y este producto va a añadir valores a enumerados constantemente (estados de matrícula, tipos de incidencia, métodos de pago).

**Decisión**: todo enumerado de respuesta se documenta en OpenAPI como extensible, y **la SPA está obligada a tener rama por defecto** en todo tratamiento de un enumerado, mostrando el código en crudo antes que fallar. Es una regla de cliente, pero se decide aquí porque es la contrapartida que hace segura una regla de servidor.

### 7.4 `v2` es el último recurso, no el plan

Ser honesto sobre lo que un desarrollador en solitario puede sostener: **mantener dos versiones de API en paralelo no va a ocurrir**. La expectativa realista es que `v1` dure lo que dure el producto.

Por eso, cuando un cambio incompatible sea inevitable, el camino preferente **no** es abrir `v2` sino el mismo procedimiento *expand/contract* que `CLAUDE.md §9` ya impone a las migraciones de base de datos:

1. **Expand** — se añade el campo, el parámetro o el endpoint nuevo **al lado** del antiguo. Ambos funcionan.
2. Se marca el antiguo con las cabeceras `Deprecation` y `Sunset` (RFC 8594) y se documenta en OpenAPI con `deprecated: true`.
3. Se migra la SPA.
4. **Contract** — se retira el antiguo, nunca antes de la fecha de `Sunset`.

Que la regla de evolución de la API sea *la misma* que la del esquema no es una coincidencia elegante: significa que hay un solo procedimiento que recordar en vez de dos, y que el ritmo de una entrega es uniforme de la base de datos al cliente.

`v2` queda reservado para un rediseño que no se pueda expresar como suma de cambios aditivos. Si llega ese día, la decisión será un ADR nuevo.

---

## 8. Idempotencia

### 8.1 Dónde es obligatoria: criterio, no lista

`INV-011` dice "endpoints de escritura críticos" y pone tres ejemplos. Un criterio para 53 módulos:

**`Idempotency-Key` es obligatoria en toda petición `POST` que cumpla al menos una de estas condiciones:**

1. Mueve dinero o genera un apunte contable.
2. Envía correo, SMS o notificación a un tercero.
3. Ejecuta un proceso por lotes sobre varias entidades.
4. Crea un recurso **sin restricción de unicidad natural** que impida el duplicado.

**No es obligatoria** cuando una restricción de unicidad en base de datos ya hace la operación naturalmente idempotente. `POST /users` es el ejemplo: el índice único sobre el correo del tenant convierte el reintento en un `422`, que es el resultado correcto. Añadir idempotencia ahí sería una tabla y una comprobación más para proteger de algo que ya está protegido. **Es un "no" deliberado**: la idempotencia se pone donde falta protección, no donde queda bonita.

`PUT`, `PATCH` y `DELETE` no la llevan: son idempotentes por definición si se implementan bien (§9), y exigir la cabecera sugeriría que no lo son.

### 8.2 Contrato

- Cabecera **`Idempotency-Key`**, con un **ULID generado por el cliente**. Otro formato es `400`.
- **Ausencia de la cabecera donde es obligatoria es `400`, no `422`.** Corrección respecto a `api.md §7`: `422` significa "el cuerpo es sintácticamente válido pero semánticamente incorrecto"; una cabecera obligatoria que falta no es un fallo de validación del cuerpo, y mezclar ambos casos obliga al cliente a inspeccionar `errors` para saber qué le pasa. `type: urn:pge:error:malformed`.
- **Reintento con la misma clave y el mismo cuerpo** → se devuelve la **respuesta original**: el mismo estado (si fue `202`, es `202`, no `200`) y el mismo cuerpo, más la cabecera **`Idempotency-Replayed: true`**. Corrección respecto a `api.md §7`, que describía la repetición dentro de la viñeta de `409` y a la vez decía que devuelve `200`: eran dos cosas incompatibles y forzar `200` rompería a un cliente que esperaba `202`.
- **Misma clave, cuerpo distinto** → **`409`**. Sin esta comprobación, un fallo del cliente que reutilice una clave hace que una operación distinta se trague en silencio y devuelva el resultado de otra. Se guarda un hash del cuerpo normalizado y se compara.
- **Misma clave mientras la primera aún se ejecuta** → **`409`**. La inserción de la fila con índice único actúa de cerrojo; no hace falta ningún mecanismo adicional.
- **Ventana de retención: 24 horas.** Suficiente para cualquier reintento razonable (red, recarga, reintento de cola) y corto para no acumular. Pasada la ventana, la misma clave se trata como nueva.

### 8.3 Dónde se almacena: PostgreSQL, no Redis

La propuesta decía "el servidor almacena la clave con el resultado" sin decir dónde. Es la parte que más importa.

| # | Opción | Problema |
|---|---|---|
| A | Redis con TTL de 24 h | **Redis es la caché** (`CLAUDE.md §1`). Un `FLUSHALL`, un reinicio sin persistencia o un desalojo por memoria borran las claves. El modo de fallo es un cobro duplicado o un envío masivo repetido: exactamente lo que `INV-011` existe para impedir |
| B | **Tabla en PostgreSQL, con `tenant_id`, purgada por tarea programada** | Durable, transaccional con la operación que protege, incluida en la copia de seguridad, y el índice único hace de cerrojo sin código extra |

**Decisión: opción B.** Tabla `idempotency_keys` con único sobre `(tenant_id, endpoint, idempotency_key)`, columnas para el hash del cuerpo, el estado, el cuerpo de la respuesta y `expires_at`. Purga diaria. Está en el ámbito del tenant, así que la clave de un centro no colisiona ni se consulta desde otro (`INV-001`).

Una clave de idempotencia que no sobrevive a un reinicio no es idempotencia; es una caché con nombre engañoso.

---

## 9. `PATCH` frente a `PUT`, y semántica de la escritura parcial

### 9.1 Reparto, ratificado

| Verbo | Uso |
|---|---|
| `POST /recursos` | Crear |
| `POST /recursos/{id}/accion` | Transición de estado o acción de dominio con reglas propias (`/status`, `/restore`, `/execute`) |
| `PATCH /recursos/{id}` | Actualización **parcial** del recurso |
| `PUT /recursos/{id}/subcoleccion` | Reemplazo **completo** de una colección subordinada (`/users/{id}/roles`) |
| `PUT /recursos/{id}/binario` | Reemplazo de un activo binario (`/tenant/settings/assets/{kind}`) |
| `DELETE /recursos/{id}` | Baja lógica (`INV-004`) |

**No se usa `PUT` sobre el recurso principal.** Obligaría al cliente a reenviar el recurso entero y, con ello, a borrar los campos que su versión no conoce todavía — que es precisamente el fallo que la regla de versionado de §7 intenta evitar.

Los verbos de acción (`POST /{id}/status`) no son una violación de REST que haya que disculpar: una transición de estado con reglas de negocio propias, permiso propio y errores propios **no es la edición de un campo**, y modelarla como `PATCH {"status": "activo"}` obliga a esconder esas reglas dentro de la validación de un campo cualquiera.

### 9.2 Lo que la propuesta no decidía y provoca el fallo más común

`api.md §9.6` dice "actualización parcial" sin definir **cómo se distingue "no toques este campo" de "vacía este campo"**. En Laravel eso se decide entre `$request->has()` y `$request->filled()`, y elegir mal produce el fallo clásico: el usuario borra el segundo apellido, el servidor ignora el `null` y el dato sigue ahí. Con 53 módulos, esto se decide una vez o se equivoca 53 veces.

**Decisión — semántica de `PATCH`, obligatoria en todos los módulos:**

1. **Clave ausente** → el campo **no se toca**.
2. **Clave presente con `null`** → el campo se **vacía** (si es anulable; si no, `422`).
3. **Cadena vacía `""`** → **`422`**. Para vaciar se usa `null`. Aceptar ambos crea dos representaciones del mismo estado y una de ellas acaba guardándose en la base de datos.
4. **Objetos anidados se fusionan** en profundidad: `{"regional": {"timezone": "..."}}` no borra `regional.currency`.
5. **Arrays se reemplazan enteros.** No hay fusión de arrays. `active_locales` enviado sustituye al anterior.
6. **Un campo JSON de forma libre** (`module_subscriptions.settings`) se **reemplaza entero**, no se fusiona. Fusionar recursivamente un objeto opaco es indecidible: no hay forma de expresar "borra esta clave de dentro".
7. **La implementación decide con `$request->has()`, nunca con `filled()`.** `filled()` trata `null` como ausente y rompe la regla 2. Es una línea en la revisión de código y evita el fallo en 53 módulos.

Esto es, en la práctica, JSON Merge Patch (RFC 7386) con la regla 3 y la 6 añadidas. **El `Content-Type` sigue siendo `application/json`**, no `application/merge-patch+json`: no se implementa la norma completa, y anunciar un tipo que no se cumple del todo es peor que no anunciarlo.

### 9.3 `PUT` de colección

- Acepta el array vacío como "quítalos todos", sujeto a las reglas de negocio (`RN-CORE-07` impide quedarse sin administrador).
- Es idempotente por construcción: enviar dos veces el mismo conjunto deja el mismo estado y **no genera un segundo registro de auditoría** si no hubo cambio efectivo.
- Devuelve `200` con el estado resultante, no `204`. El cliente necesita ver qué quedó.

---

## 10. Concurrencia: última escritura gana, y por qué se dice que no a `ETag`

Dos administrativos editan la misma ficha a la vez. Opciones reales:

| # | Opción | Coste | Valor |
|---|---|---|---|
| A | **Última escritura gana** | Cero | El conflicto es raro (un centro tiene 2-5 usuarios con permiso de edición sobre la misma entidad) y, cuando ocurre, **`audit_logs` deja el rastro exacto de qué se perdió y quién lo sobrescribió** (`INV-003`). Es detectable y reversible a mano |
| B | `ETag` + `If-Match` en todo `PATCH` | Alto: cálculo y propagación del `ETag` en 53 módulos, y manejo del `412` en cada formulario de la SPA | Protege de un suceso poco frecuente y ya auditado |
| C | Bloqueo pesimista | Muy alto: expiración, liberación, interfaz de "en edición por…" | Desproporcionado |

**Decisión: opción A.** No hay control de concurrencia optimista en `v1`.

La razón por la que decir que no aquí es seguro: **añadir `ETag`/`If-Match` después es un cambio aditivo** según §7.2 (una cabecera de respuesta nueva y una de petición opcional). Se puede introducir en los tres o cuatro endpoints donde llegue a doler, sin tocar los otros cincuenta y sin abrir `v2`. Implementarlo hoy en todos es pagar por adelantado un seguro contra un riesgo que la auditoría ya mitiga.

---

## 11. Cabeceras transversales, idioma y `request_id`

Consecuencia directa de §6.3: si el servidor renderiza los mensajes, hay que fijar **con qué idioma**.

**Orden de resolución del idioma**, aplicado por *middleware* único para toda la API:

1. `person.locale` del usuario autenticado, si existe y está entre los `active_locales` del tenant.
2. `Accept-Language`, intersectado con los `active_locales` del tenant.
3. `tenant_settings.default_locale`.

El idioma resuelto se devuelve siempre en **`Content-Language`**. Un idioma pedido y no disponible **no es un error**: se degrada al siguiente de la lista.

**`request_id`** (`INV-013`):

- Lo genera **siempre el servidor**, como ULID, al entrar la petición.
- Se devuelve en la cabecera **`X-Request-Id`** en toda respuesta, con éxito o con error, y en el cuerpo de todo `problem+json`.
- **Un `X-Request-Id` que llegue del cliente se ignora.** Aceptarlo es dejar que un tercero escriba en los logs y correlacione peticiones ajenas. Si algún día hace falta trazar de extremo a extremo, se hará con `traceparent` (W3C) desde Traefik, que es infraestructura de confianza (`ADR-028`).
- El mismo `request_id` se propaga a los trabajos en cola que la petición encole (`INV-012`), para que la traza no se corte en el borde de la petición.

Cabeceras de respuesta obligatorias en toda respuesta de la API: `X-Request-Id`, `Content-Language`. En errores `429`/`503`, además `Retry-After`.

---

## 12. Cómo se documenta esto en OpenAPI

`CLAUDE.md §10` exige que todo endpoint esté documentado en OpenAPI, e `INV-006` exige que **exista ahí antes** que en la SPA. Hoy `apps/api/openapi.yaml` tiene 39 líneas y un endpoint. Con 53 módulos serán miles de líneas, y sin decisión acabará siendo un fichero que nadie abre y que miente.

### 12.1 Generado desde el código: descartado

| # | Opción | Por qué se descarta o se elige |
|---|---|---|
| A | Generar desde anotaciones o tipos (Scramble, L5-Swagger) | **Invierte `INV-006`.** Si la especificación se genera del código, el contrato deja de preceder a la implementación y pasa a describirla: ya no se puede escribir el endpoint en OpenAPI, revisarlo y luego implementarlo. Además introduce una dependencia que hay que justificar (`CLAUDE.md §1`) y que condiciona cómo se escriben los controladores |
| B | Un único `openapi.yaml` a mano | Inmanejable a partir del módulo 10; conflictos constantes; obliga a cargar miles de líneas de contexto para tocar un endpoint |
| C | **Escrito a mano, dividido por módulo, con `components` compartidos** | Elegida |

### 12.2 Decisión

```
apps/api/openapi.yaml                  raíz: info, servers, y $ref a cada módulo
apps/api/openapi/components.yaml       Problem, PageMeta, CursorMeta, parámetros comunes, formato ULID
apps/api/openapi/paths/core.yaml       rutas de REQ-CORE
apps/api/openapi/paths/<modulo>.yaml   una por módulo
```

- **`components.yaml` es el que hace cumplir este ADR sin depender de la memoria de nadie.** Contiene, definidos una sola vez y referenciados por `$ref` desde los 53 módulos: el esquema `Problem` (§6), `PageMeta` y `CursorMeta` (§4), los parámetros `page`, `per_page`, `cursor`, `limit`, `sort`, `q`, la cabecera `Idempotency-Key`, y el formato `ulid`. Un módulo que redefina cualquiera de ellos en lugar de referenciarlo es un fallo de revisión visible en el `diff`.
- **`$ref` entre ficheros hermanos**, sin paso de empaquetado. OpenAPI 3.1 lo admite y las herramientas de validación lo resuelven; no se introduce ninguna herramienta nueva ahora.
- **Comprobación de paridad en CI**: un comando de consola que compara las rutas registradas en Laravel con las declaradas en la especificación y **falla si hay alguna en un lado y no en el otro**. Son unas decenas de líneas sin dependencias, y convierte `CLAUDE.md §10` de norma que se recuerda en norma que se cumple sola. Sin esto, la documentación de API se desincroniza; es cuestión de meses, no de si ocurre.

---

## 13. Consecuencias sobre la SPA (`INV-006`)

`INV-006` dice que la UI es un cliente más. La comprobación honesta no es filosófica: es si el cliente Vue puede consumir estas convenciones **sin una capa de adaptadores por módulo**. La respuesta es sí, con tres condiciones y **dos defectos que hay que corregir antes**.

### 13.1 Dos defectos reales del cliente actual que este ADR destapa

Leído `apps/web/src/api/client.ts`:

```ts
response = await fetch(`${baseUrl}${path}`, {
  credentials: 'include',
  headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...init.headers },
  ...init,
})
```

1. **`...init` se expande *después* de `headers`.** Si quien llama pasa `init.headers`, el objeto `headers` cuidadosamente fusionado en la línea anterior **se reemplaza entero**: se pierden `Accept` y `Content-Type`. La fusión de la línea 28 es código muerto justo en el caso en que importa. Esto choca de frente con §8: mandar `Idempotency-Key` es pasar una cabecera por llamada, y hacerlo hoy tiraría silenciosamente el `Content-Type`.
2. **`Content-Type: application/json` se fija siempre**, incluso con un cuerpo `FormData`. Para las subidas *multipart* de `PUT /tenant/settings/assets/{kind}` y `POST /user-imports` el navegador debe fijar él mismo el `Content-Type` con su `boundary`; imponerlo a mano hace que el servidor no pueda analizar el cuerpo. Ambos endpoints están en 1.1.

Los dos son defectos previos a este ADR, no consecuencias suyas. Se corrigen al implementar 1.1 (severidad media, con issue, `CLAUDE.md §5`).

### 13.2 Lo que el cliente necesita, y no es un rediseño

`client.ts` ya llama a `response.json()` sin mirar el `Content-Type`, así que `application/problem+json` se analiza sin tocar nada. Lo que hay que añadir es aditivo:

- Fusión correcta de cabeceras y omisión de `Content-Type` cuando el cuerpo es `FormData` (§13.1).
- `Accept: application/problem+json, application/json`.
- Tipar `ApiError.body` como `Problem` y exponer `type`, `errors` y `request_id`. Que `errors[campo][n].message` venga renderizado (§6.3) significa que un formulario puede pintar el error **sin catálogo propio**: es lo que hace que la SPA sea un cliente delgado de verdad y no una réplica de la lógica del servidor.
- Base del cliente en `/api/v1`, de modo que las rutas que se pasan sean `/users`, no `/v1/users`. Hoy la base por defecto es `.../api`, lo que dejaría el `v1` disperso por todas las llamadas.
- Serialización de listas por comas (§5.2) en un único ayudante de construcción de `query`, no en cada vista.

Ninguna de estas convenciones exige una librería HTTP externa; `RNF-MANT-007` se mantiene.

### 13.3 TanStack Table (`ADR-023`, tablas de datos de 1.9)

Encaje directo, sin adaptadores, si se respetan tres cosas:

| Convención | Configuración |
|---|---|
| Paginación por página (§4.3) | `manualPagination: true`, `rowCount: meta.total`, `pageIndex = current_page - 1`, `pageSize = per_page` |
| Orden de una columna (§5.3) | `manualSorting: true`, **`enableMultiSort: false`**, `sorting[0]` → `sort=-columna` |
| Filtros planos (§5.2) | `manualFiltering: true`; **el `id` de la columna debe ser igual al nombre del parámetro de consulta**, con lo que `columnFilters` se serializa sin tabla de correspondencias |

La tercera es la que ahorra más trabajo a lo largo de 53 módulos, y solo cuesta nombrar bien las columnas desde el primer día.

Los listados por cursor (§4.4) **no** usan el modelo de paginación de TanStack Table: `pageCount: -1` y "cargar más". Es un componente distinto, y está asumido en §4.5.

---

## 14. Relación con decisiones anteriores

| Decisión | Relación |
|---|---|
| `ADR-025` (sesión por cookie) | Intacta. Este ADR no toca autenticación; §13 solo corrige defectos del cliente |
| `ADR-029` (identificadores) | **Matizada** en §4.4, de forma explícita y argumentada: el cursor cifrado transporta la clave interna. Se conserva el motivo (no enumerabilidad); la regla general —nada de claves internas en rutas, cuerpos ni parámetros legibles— sigue siendo absoluta |
| `ADR-033` (aislamiento) | Reforzada: el cursor lleva el tenant (§4.4) y la regla `404` frente a `403` (§6.4) es la que impide confirmar la existencia de recursos ajenos |
| `ADR-034 §3` (`audit_logs`) | **Precisada**: el índice `(tenant_id, occurred_at DESC)` pasa a `(tenant_id, occurred_at DESC, id DESC)`. No cambia ninguna decisión, completa una |
| `ADR-035` (auditoría) | Intacta. §6.5 refuerza que el error no filtra lo que la auditoría no guarda |
| `ADR-023` (shadcn-vue + TanStack Table) | Intacta. §13.3 fija cómo se consume, no cambia la elección |
| `CLAUDE.md §9` (expand/contract) | **Extendida a la API** en §7.4: mismo procedimiento para el esquema y para el contrato |

---

## 15. Consecuencias

**Positivas**

- Los 52 módulos siguientes copian de un sitio con autoridad, no de lo que hizo el primero.
- `components.yaml` (§12.2) y la comprobación de paridad en CI convierten la mitad de este ADR en una norma que se cumple sola.
- La SPA no mantiene catálogo de mensajes de error (§6.3): un catálogo en vez de dos, en cuatro idiomas.
- La regla de `PATCH` (§9.2) elimina de antemano el fallo de "no se puede borrar un campo opcional", que aparecería en la mayoría de los 53 módulos.
- La evolución de la API y la del esquema siguen el mismo procedimiento (§7.4): un solo ritmo de entrega que recordar.

**Costes que se aceptan**

- **Dos componentes de listado** en la SPA: tabla paginada y flujo con "cargar más" (§4.5).
- **Una tabla más** (`idempotency_keys`) y su purga diaria (§8.3).
- **Un renderizador de excepciones propio** para `problem+json`, más el `withoutWrapping()` global (§3.1, §6). Se escribe una vez, en 1.1.
- **`components.yaml` hay que escribirlo antes que el primer endpoint**, no después.
- Sin control de concurrencia (§10): un conflicto de edición simultánea se resuelve leyendo `audit_logs`, a mano.
- Sin orden multi-columna (§5.3) y sin `OR` entre campos distintos (§5.2). Ambos son "no" conscientes.

**Reversibilidad**

| Decisión | ¿Reversible? |
|---|---|
| Idempotencia (dónde y cuándo) | **Alta**: aditiva, se amplía endpoint a endpoint |
| Control de concurrencia | **Alta**: `ETag`/`If-Match` es aditivo (§10) |
| Orden multi-columna | **Alta**: `sort=-a,b` es aditivo (§5.3) |
| Formato del cursor | **Alta**: el campo `v` del interior permite cambiarlo sin romper clientes (§4.4) |
| Sintaxis de filtros y de orden | **Media**: se puede aceptar una sintaxis nueva junto a la vieja, pero la vieja no se retira sin migrar el cliente |
| Envoltura, formato de error, versionado, semántica de `PATCH` | **Baja**: cambiarlas rompe a todo cliente. Es exactamente por eso por lo que se deciden aquí y ahora, y no dentro de la especificación de un módulo |

---

## 16. Alternativas descartadas

- **JSON:API.** Resuelve de verdad los problemas que aquí se deciden (envoltura, paginación, errores, relaciones), y por eso se consideró en serio. Se descarta porque impone `filter[...]`, `page[...]`, documentos con `type`/`id`/`attributes`/`relationships` e `included`: cada respuesta se vuelve dos o tres veces más grande y **cada componente de la SPA necesita un desnormalizador**. Se pagaría una norma completa para consumirla desde un único cliente propio. La parte útil —envoltura consistente, errores estructurados, dos modos de paginación— ya está en este ADR sin el resto del peso.
- **GraphQL.** Elimina el problema del filtrado y del sobre-envío, y a cambio traslada al servidor los problemas de N+1, coste de consulta, autorización campo a campo y limitación de profundidad. Con `INV-002` (denegar por defecto, permiso por endpoint) y `INV-001` (aislamiento en el marco de trabajo), un único punto de entrada con consultas arbitrarias es una superficie de autorización mucho peor que 300 endpoints con su permiso declarado. No.
- **Negociación de versión por cabecera `Accept`.** Invisible en logs y en el navegador, propensa a fallos de caché intermedia. `/api/v1` se ve en cualquier traza.
- **`filter[campo]=valor`.** No aporta nada frente a `campo=valor` salvo un espacio de nombres que no hace falta, y es el primer paso hacia un mini-lenguaje de consulta (`filter[campo][gte]`) que acaba traduciéndose a SQL. La lista blanca de §5.3 y §5.2 es más segura y más simple.
- **Envolver también el recurso individual en `data`** (el defecto de Laravel). Un nivel de indirección en cada acceso a cambio de una uniformidad que las cabeceras ya proporcionan.
- **`errors` con solo claves de traducción.** Obligaría a duplicar el catálogo de mensajes de 53 módulos en 4 idiomas dentro de la SPA (§6.3).
- **Idempotencia en Redis.** Una idempotencia que no sobrevive a un reinicio no es idempotencia (§8.3).
- **OpenAPI generado desde el código.** Invierte `INV-006` (§12.1).
- **`ETag`/`If-Match` desde el principio.** Coste alto en 53 módulos frente a un riesgo que la auditoría ya deja detectable y reparable (§10).
- **HATEOAS / `_links`.** Obliga al servidor a conocer su URL pública, que depende de un despliegue aún sin decidir (`OPEN-08`), y ningún cliente real navegaría por esos enlaces.

---

## 17. Cambios que este ADR exige en documentos ya escritos

Este ADR **no edita** otros documentos. La lista, para que la aplique la sesión que implemente 1.1:

**`docs/modulos/REQ-CORE/api.md`**

1. §9 se sustituye por una referencia a este ADR. Una convención transversal no vive en la especificación de un módulo (`OPEN-CORE-09`, que queda **cerrada**).
2. §8, `GET /audit-logs`: el cursor pasa a ser opaco y cifrado, con las tres reglas de §4.4.
3. §3, `GET /users`: los parámetros repetibles (`status`, `role`) pasan a separados por comas (§5.2), no repetidos. **Tal como estaba escrito no habría funcionado en PHP.**
4. §7, `POST /user-imports/{id}/execute`: `Idempotency-Key` ausente devuelve **`400`**, no `422`; la repetición devuelve el **estado original** (`202`) con `Idempotency-Replayed: true`, y se saca de la viñeta de `409`; se añade `409` por cuerpo distinto con la misma clave y por ejecución aún en curso.
5. §9.3: `type` pasa a `urn:pge:error:<slug>` (§6.2) y `errors` pasa a `{code, message, params}` (§6.3).
6. §1: se añade a las cabeceras comunes que `X-Request-Id` lo genera el servidor y se ignora el entrante.
7. Se hace explícito que el recurso individual va sin envoltura y la colección con `data`/`meta` (§3.1).

**`docs/modulos/REQ-CORE/datos.md`** y **`ADR-034 §3`**

8. Índice de `audit_logs`: `(tenant_id, occurred_at DESC)` → `(tenant_id, occurred_at DESC, id DESC)` (§4.4).
9. Tabla nueva `idempotency_keys` en el ámbito del tenant (§8.3).

**`docs/modulos/REQ-CORE/funcional.md`**

10. `OPEN-CORE-09` queda cerrada por este ADR.

**`apps/web/src/api/client.ts`**

11. Los dos defectos de §13.1, con issue de severidad media (`CLAUDE.md §5`).

**`docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` §18**

12. Añadir `ADR-038` a la tabla de ADR en fichero propio.

---

## 18. Motivo

Un solo criterio ordena las diez decisiones de este ADR: **decidir ahora solo lo que es caro de cambiar después, y decir que no a todo lo demás.**

De ahí sale todo lo concreto, no al revés:

- Se deciden con detalle la **envoltura, el formato de error, el versionado y la semántica de `PATCH`** (§3, §6, §7, §9) porque son las cuatro cosas cuya reversibilidad es baja: cambiarlas rompe a todo cliente a la vez. Son el motivo por el que este ADR se escribe antes de 1.1 y no después.
- Se dice que **no** al control de concurrencia (§10), al orden multi-columna (§5.3), al `OR` entre campos, a JSON:API, a GraphQL y a generar OpenAPI desde el código (§16). En todos los casos el argumento es el mismo y es comprobable: **la funcionalidad que se rechaza es aditiva**, así que rechazarla hoy no cierra ninguna puerta, mientras que aceptarla obliga a mantenerla en 53 módulos desde el primer día.
- Donde la propuesta de `spec-writer` se corrige, **no es por preferencia estética**. La sintaxis de filtros no funcionaba en PHP (§5.1). El cursor sin desempate ni firma es incorrecto, no incompleto (§4.4). `errors` sin mensaje renderizado duplica un catálogo entero de mantenimiento (§6.3). La idempotencia sin decir dónde se almacena admite una implementación en Redis que anula su propósito (§8.3). `PATCH` sin definir el trato del `null` produce un fallo concreto y conocido (§9.2). Cada corrección tiene un modo de fallo asociado que se puede describir.
- Las decisiones se apoyan en **mecanismos que se cumplen solos** siempre que existe uno: `withoutWrapping()` global en vez de recordarlo por módulo, `components.yaml` con `$ref` en vez de reescribir el esquema de error, comprobación de paridad de rutas en CI en vez de disciplina documental, índice único de base de datos como cerrojo de idempotencia en vez de código de sincronización. Con un solo desarrollador y tres años por delante, una convención que depende de recordarla ya está rota; solo que aún no se sabe.
