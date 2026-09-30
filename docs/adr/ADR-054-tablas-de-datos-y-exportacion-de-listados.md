# ADR-054 · Tablas de datos y exportación de listados

**Estado**: **PROPUESTA** (2026-09-30). Las decisiones de alcance las tomó el usuario el 2026-09-30 (`OPEN-CORE-19`: sin virtualización; `OPEN-CORE-20`: tarjetas por debajo de 768 px con desplazamiento interno como opción por tabla; `OPEN-CORE-21`: visibilidad más «restablecer»; `OPEN-CORE-22` a `-29`: la recomendación de la especificación). Las precisiones y correcciones que este ADR añade sobre la especificación (§2.3, §5.3, §6.2, §8.2, §8.3, §9, §10.1, §10.2) son de `architect` y **no se aplican sin ratificación expresa del usuario** (lección de `ADR-050`/`ADR-051`/`ADR-053`).
**Fecha**: 2026-09-30
**Resuelve**: los siete puntos de `docs/modulos/REQ-CORE/funcional.md §13.22`, y fija la norma común de CSV que `SECURITY.md` (fila «Exportaciones generadas (CSV)») y el issue [#270](https://github.com/pirexia/plataforma-educativa/issues/270) remiten al paso `1.9`
**Precisa, sin sustituir el resto**: `ADR-038 §4.5` y la última frase de `ADR-038 §13.3` (§2.2 de este ADR). **Amplía**: `RN-CORE-36` (§10.2)
**Se apoya en**: `ADR-023` (TanStack Table), `ADR-029`, `ADR-035 §2`/`§3`/`§5`, `ADR-038 §4`/`§5`/`§7`/`§8.1`/`§13.3`, `ADR-052 §2` y `§5` (preferencia local; frontera del *design system*), `ADR-053` (patrón de registro explícito y tests de coherencia), `RNF-MANT-007`, `RNF-COMP-004`, `RNF-LIM-004`, `RPERM-003`, `RUX-RESP-004`, `INV-002`, `INV-007`, `INV-009`, `INV-012`
**No toca**: ningún *endpoint*, permiso ni migración en `1.9`. La norma de servidor de §8-§10 obliga a los pasos que creen o modifiquen *endpoints* de exportación, no a `1.9`
**Afecta a**: `1.9` (entrada obligatoria), `1.9b` (primer consumidor: usuarios y auditoría), `1.5b`, la rama `fix/` del issue #270, y a todo paso posterior que añada un listado o una exportación

---

## Contexto

`1.9` introduce el componente de tabla que copiarán los 53 módulos. Es a los listados de `apps/web` lo que `ADR-038` fue a la API y `ADR-053` a la navegación. La especificación (`funcional.md §13`) deja siete decisiones transversales para este ADR (`§13.22`), y el usuario resolvió el 2026-09-30 las tres bloqueantes y aprobó las demás con la recomendación.

### Estado verificado el 2026-09-30 (rama `feature/1.9-tablas-de-datos`, `fc7dd42`)

- `@tanstack/vue-table` `^8.21.3` es dependencia de `apps/web`. `@tanstack/vue-virtual` no está instalada. La única tabla existente es `MfaComplianceArea.vue`, que importa TanStack directamente.
- **Exportación**: solo existe `POST /audit-logs/exports`. `EloquentExportRequestService::request()` crea la fila de `data_exports` con `filters` tal cual llega y la audita dos veces: el `created` automático de `RecordsAuditTrail` y un `exported` manual. `DataExport` declara política **`Full`**, así que **el contenido de `filters` se copia entero en `audit_logs.changes`**, tabla inmutable con dos años de retención. El tope de `ADR-035 §5` (256 caracteres) no atrapa un apellido.
- **Filtros de la exportación de auditoría frente a los de su listado**: `GET /audit-logs` acepta `from`, `to`, `actor_id`, `actor_type`, `event`, `auditable_type`, `auditable_id` y `module`. `POST /audit-logs/exports` acepta **solo** `from`, `to`, `event` y `auditable_type` (`$request->only(...)`). Un filtro por actor aplicado en pantalla **no llega al fichero**, y por la regla de «parámetro desconocido se ignora» (`ADR-038 §5.2`) no hay error: el fichero contiene más de lo que el usuario cree haber filtrado.
- **Nombres del rango de fechas de auditoría**: el listado y la exportación usan `from`/`to` (código y `REQ-CORE/api.md` línea 542), no `occurred_at_from`/`occurred_at_to` como exige `ADR-038 §5.2`. Con la regla «`id` de columna = nombre del parámetro» (`ADR-038 §13.3`), un filtro de rango sobre la columna `occurred_at` enviaría parámetros que el servidor ignora en silencio.
- **CSV**: `GenerateAuditLogExport::neutralizeCsvCell` es privado, acepta `string|int|float|null` y convierte todo a cadena antes de neutralizar (`-5` sale como `'-5`). `ValidateUserImport::writeReport` llama a `fputcsv` sin neutralizar. Ambos usan el dialecto por defecto de PHP (coma, sin BOM, `\n`, carácter de escape `\`).

Los tres últimos puntos no los creó la especificación, pero determinan varias reglas de este ADR.

---

## Decisión

### 1. Envoltura de TanStack Table

1. El componente vive en **`apps/web/src/data-table/`**, de nivel de aplicación, fuera de `components/ui` (tiene texto propio traducido, prohibido dentro de la frontera de `ADR-052 §5`) y fuera de `src/modules/core` (lo consumen todos los módulos, `INV-007`).
2. **Importación única** (`RN-CORE-37`): solo `src/data-table/**` importa `@tanstack/vue-table`, sea estática, dinámica o de tipos. Los módulos declaran columnas y fuente de datos con **tipos propios** de `src/data-table` (contrato de columnas de `funcional.md §13.4`), nunca con tipos de TanStack.
3. **Frontera** (`RN-CORE-38`): `src/data-table/**` no importa `src/modules/**` ni construye URLs de *endpoints*. Recibe del módulo una función de petición escrita en su `api/`. Puede importar `vue-router`, y solo para el estado en URL de §6.
4. **Toda tabla de datos pasa por el componente** (`OPEN-CORE-29` A): fuera de `src/data-table/**`, nadie importa `@/components/ui/table`, salvo una **lista cerrada de excepciones nominales escrita en el propio test**. La matriz de concesión de `1.5b` es la primera candidata, y entra en la lista en el paso que la construya, con su justificación.
5. Las reglas 2 a 4 son **tests de arquitectura de Vitest** con casos fijos que prueban que el test sabe fallar. Es el patrón de `docs/design-system.md §10`.

### 2. Paginación: dos modos en `1.9` y lectura de `ADR-038 §4.5`

#### 2.1 Modos

| Modo | Cuándo (criterio de `ADR-038 §4.2`, no a gusto) | Control |
|------|------------------------------------------------|---------|
| `page` | Catálogo de entidades | Paginador numerado, total, `per_page` ∈ {25, 50, 100} |
| `cursor` | Flujo de eventos | «Cargar más», sin total ni números de página |

El modo `local` (colecciones documentadas como no paginadas) **no existe en `1.9`** (`OPEN-CORE-26` B). Lo añade dentro de `src/data-table` el primer paso que tenga un consumidor real, sin tocar este ADR: es aditivo.

#### 2.2 Lectura de `ADR-038 §4.5` y `§13.3`: confirmada, con una precisión

`ADR-038 §4.5` dice que los listados por cursor no usan «el mismo componente de tabla que los catálogos», y `§13.3` que «es un componente distinto». **La lectura de la especificación es compatible**: un contenedor de paginación distinto sobre el mismo contrato de columnas, el mismo pintado, los mismos estados y la misma accesibilidad.

El motivo de `ADR-038 §4.5` es que un cursor **no puede** tener paginador numerado ni total, y que intentarlo miente o tarda segundos. Ese motivo se cumple entero si el modo `cursor` no tiene paginador, no pide `total` y no alimenta el modelo de paginación de TanStack (`pageCount: -1`). «Un componente más que mantener» era la estimación de coste de `ADR-038`, no una exigencia. Duplicar la tabla para cumplirla al pie de la letra duplicaría también la semántica de tabla, las tarjetas, los anuncios y los estados, que es justo lo que más se rompe al divergir.

**Precisión**: `ADR-038 §4.5` admitía «cargar más **o desplazamiento infinito**». Este ADR **retira el desplazamiento infinito** para todo el producto. Deja inalcanzable con teclado todo lo que hay debajo de la lista, obliga a gestionar anuncios y foco sin una acción explícita del usuario que los ancle, y es incompatible con el tope de §3. «Cargar más» es un botón: tiene foco, nombre y una acción que anunciar.

#### 2.3 Reglas del modo `cursor` que la especificación no cerraba

- Cambiar orden o filtros **reinicia la lista sin `cursor`** (un cursor de otros filtros es `422`, `ADR-038 §4.4` regla 2).
- Si falla una petición de «cargar más», **se conservan las filas ya cargadas** y «Reintentar» repite la petición **con el mismo cursor**. No reinicia la lista. Perder cuatrocientas filas por un `503` transitorio es el fallo que el usuario no perdona.
- El `cursor` **nunca** va a la URL (§6).

### 3. Virtualización: no, con tope de filas en modo `cursor`

Decisión del usuario (`OPEN-CORE-19` A).

- **Sin virtualización en `1.9`** y sin `@tanstack/vue-virtual`. La virtualización sale del alcance del paso.
- **Tope de filas acumuladas en modo `cursor`**: constante única `MAX_CURSOR_ROWS = 1000` en `src/data-table`, **no configurable por tabla** (no hay caso que lo pida; añadir la opción es aditivo). Cuando las filas acumuladas alcanzan o superan el tope, «Cargar más» se sustituye por un aviso traducido para acotar los filtros o exportar, con la acción de exportar si la tabla la ofrece. Ninguna petición de paginación sale a partir de ahí.
- La cifra es de diseño, sin medición detrás, y se revisa con datos de volumen de `REQ-SEED` (`1.15b`). Cambiarla no requiere ADR.
- **Si algún paso necesita virtualizar**, lo decide un ADR nuevo con la diligencia de dependencia de `CLAUDE.md §1` (mantenimiento, licencia, ritmo de versiones). **Este ADR no la ha hecho y no aprueba ninguna biblioteca.** La regla 2 de §1 garantiza que ese cambio quede dentro de `src/data-table` sin tocar a ningún consumidor.

La línea de `1.9` en `PLAN-IMPLEMENTACION.md` («…virtualización y exportación») deja de ser cierta y hay que ajustarla.

### 4. Tablas en móvil (`RUX-RESP-004`)

Decisión del usuario (`OPEN-CORE-20` A).

- **Norma**: por debajo de **768 px** (`--breakpoint-md`, `RN-CORE-29`), la tabla se sustituye por una **lista de tarjetas** construida con el mismo contrato de columnas (campo `card`). En el DOM hay **una sola** de las dos representaciones a la vez.
- **Excepción**: desplazamiento horizontal **dentro de su contenedor**, declarado por la tabla (opción del componente, no un estilo del consumidor). **Criterio** para usarla: el propósito de la tabla es comparar valores de una misma columna entre filas (calificaciones, importes, series), de modo que partirla en tarjetas destruye la comparación. La especificación del paso que declara la excepción lo justifica en una línea. Preferir la excepción «porque las tarjetas cuestan más» no es criterio.
- **En los dos casos**, la página nunca se desplaza en horizontal a 320 px (`CA-CORE-080`). El contenedor desplazable es enfocable, tiene nombre accesible y se desplaza con teclado: WCAG 1.4.10 exceptúa las tablas de datos del *reflow*, pero no del acceso por teclado.

### 5. Columnas configurables y su persistencia

#### 5.1 Alcance

Decisión del usuario (`OPEN-CORE-21` A): **visibilidad y «restablecer»**. Sin reordenar ni redimensionar. La columna que identifica la fila (`rowHeader`) y la de acciones no son ocultables, así que la tabla nunca se queda sin columnas.

#### 5.2 Persistencia en `localStorage`: confirmada

La configuración se guarda en `localStorage` con la clave **`plataforma.table.<tableId>`** y el valor **exactamente** `{"v":1,"hidden":["<id>",…]}`. Solo contiene `id` de columna: nunca un dato de fila, un filtro ni el texto de búsqueda. Un valor ilegible o con `v` desconocido se descarta. Los `id` que ya no existen se ignoran y se aplica el resto. Todo acceso va en `try/catch`.

Se confirma la justificación de la especificación, que es la segunda aplicación del criterio de `ADR-052 §2`/P2:

- **Es preferencia de presentación y no necesita ser fiable.** Perderla cuesta volver a ocultar una columna.
- **En servidor** exigiría tabla, *endpoint*, permiso de autoservicio y migración sin que ningún requisito pida sincronizarla entre dispositivos. Si un centro lo pide, es aditivo: un *endpoint* que siembra la clave local.
- **Solo en memoria** la haría inútil en la práctica.

El origen del navegador es el centro (resolución por *host*, igual que `plataforma.brand`), así que la configuración no se mezcla entre centros. **Dos usuarios que comparten navegador comparten configuración de columnas**, y la clave **no se borra al cerrar sesión**. Se acepta porque la clave no contiene nada de ninguno de los dos. La clave entra en el inventario de `PRIVACY.md §2.1b`.

#### 5.3 Corrección: cómo se garantiza que `tableId` es único

La especificación (`CA-CORE-176`) pide comprobar la unicidad sobre «el registro de tablas de la SPA ensamblado». **Ese registro no existe**, y crearlo solo para esto es desproporcionado. Una colisión de `tableId` hace que dos tablas compartan qué columnas están ocultas: una molestia, no un fallo de seguridad. Meter las tablas en el `shell.ts` de `ADR-053` cambiaría la forma de ese ADR por un riesgo menor.

Decisión: `tableId` es un **literal** con forma `<modulo>.<nombre>`, y un test de Vitest **recorre las fuentes** de `src/modules/**` y comprueba su forma y su unicidad, con casos fijos que prueban que detecta un duplicado. Es la misma técnica de escaneo que ya usan los tests de frontera de `docs/design-system.md §10`. Un `tableId` que no sea literal (calculado) es un fallo del test.

### 6. Estado de la tabla en la URL

Decisión del usuario (`OPEN-CORE-22` A), con tres precisiones de `architect` (§6.2).

#### 6.1 Qué va a la URL

Página, `per_page`, orden, enumerados, booleanos y rangos de fechas se reflejan en la *query* de la ruta. **`q` nunca**, ni en la URL ni en `history.state`: la *query* queda en el historial y, en una recarga completa, en los registros de acceso del servidor, y un `q=López` es un dato personal. Es el mismo motivo por el que `ADR-038 §6.5` quita la *query* de `instance`. Los identificadores que puedan aparecer en un enumerado (`role`, `actor_id`) son ULID seudónimos (`ADR-029`) y se aceptan.

#### 6.2 Precisiones

1. **Opt-in por tabla y como máximo una por ruta.** Dos tablas en la misma vista sincronizando con la misma *query* se pisarían los parámetros. La tabla principal de la vista lo declara; las demás quedan en memoria.
2. **El `cursor` nunca va a la URL.** Es opaco, está ligado a los filtros que lo emitieron y restaurarlo tras una recarga no aporta nada: la lista se reinicia desde el principio, que es lo correcto en un flujo de eventos.
3. Un valor restaurado desde la URL que el servidor rechaza (`422`) sigue `RN-CORE-42`, y «Limpiar filtros» lo resuelve. Un valor que el servidor ignora no se puede detectar en el cliente: esa es otra razón para que los listados cumplan `ADR-038 §5.2` con exactitud (ver hallazgo 1).

### 7. Exportación: parte del cliente

1. **La SPA nunca genera el fichero de una exportación** (`RN-CORE-46`): ni CSV, ni XLSX, ni ningún formato, ni con las filas ya cargadas. Se confirma el motivo de la especificación: `exportar` es una acción de permiso distinta de `leer` (`RPERM-003`, `INV-002`), toda exportación se audita, el ámbito acota el artefacto dentro del trabajo, una página no es el listado (`RNF-LIM-004`) e `INV-012`.
2. El control de exportación aparece si el consumidor declara `canExport`, calculado con `<recurso>.exportar` de `/me.permissions`, **nunca con el rol** (`RN-CORE-23`, `RN-CORE-51`).
3. La solicitud lleva **los filtros estructurados del listado visible** y nada más: ni `page`, `per_page`, `cursor` ni `sort` (§8.1), **ni `q`** (§9).
4. **Con una búsqueda `q` activa, el control de exportación está deshabilitado** y dice por qué («borra la búsqueda para exportar; los demás filtros sí se aplican»). No se exporta ignorando `q`: el usuario creería que el fichero está filtrado por su búsqueda.
5. **Consulta del estado** (`RN-CORE-49`): una sola en vuelo por exportación. Espera inicial de **2 s**, que se duplica en cada consulta hasta un máximo de **30 s**, y se deja de consultar a los **10 min** desde la solicitud, con la acción «Comprobar de nuevo», que reinicia el ciclo. La consulta se detiene al desmontar la vista. `409` se trata como «aún no está lista»; `410`, como caducada. Los valores se ratifican tal como los propuso la especificación. La cota existe por el issue [#128](https://github.com/pirexia/plataforma-educativa/issues/128): sin *worker* desplegado, una exportación se queda en `pendiente` para siempre.
6. **Descarga por enlace** a `download_url`, con la caducidad visible. Sin `fetch` del fichero, sin `Blob` y sin `URL.createObjectURL`.
7. **Salir de la vista** (`OPEN-CORE-25` A): mientras hay una exportación en curso, la vista muestra **junto al estado** un aviso permanente de que, si sales, el fichero se seguirá generando pero no podrás descargarlo desde aquí. **Sin diálogo de confirmación en la navegación ni `beforeunload`.** Un diálogo bloquea lo que la especificación dice no bloquear, y `beforeunload` no se dispara en la navegación interna de la SPA. El listado «Mis exportaciones» (`GET /data-exports`) se reconsidera en `1.9b`.
8. Sin `Idempotency-Key`: la solicitud no cumple ningún criterio de `ADR-038 §8.1`. El control se deshabilita mientras la solicitud está en vuelo.

### 8. Exportación: norma para todo *endpoint* de exportación

Obliga a los pasos que creen o modifiquen un `POST /<recurso>/exports`, no a `1.9`.

#### 8.1 Esquema fijo por recurso (`OPEN-CORE-23` A)

Las columnas del fichero, sus nombres, su orden y **el orden de las filas** los define el servidor por recurso y los documenta en OpenAPI. No dependen de las columnas visibles ni del orden en pantalla: el fichero es un contrato estable para quien lo procesa después, y un fichero que depende de la configuración del navegador de cada usuario es irreproducible y no queda en `data_exports.filters`.

**Corrección a la especificación**: por eso la solicitud **no lleva `sort`** (`funcional.md §13.14.1` punto 2 y `CA-CORE-188` dicen «los mismos filtros y orden»). Enviarlo sería peor que no enviarlo: por `ADR-038 §5.2` un parámetro desconocido se ignora, y el usuario creería que el fichero sigue el orden de la pantalla.

#### 8.2 Paridad de filtros con el listado

Un *endpoint* de exportación **acepta exactamente los filtros estructurados de su listado**, con los mismos nombres y la misma semántica, salvo paginación, `sort` y `q`. En el cuerpo JSON, un valor múltiple va como *array* JSON. La coma de `ADR-038 §5.2` es la forma de la *query string*, y la traducción entre las dos la hace la función de solicitud del módulo.

**Por qué es regla de servidor y no del componente**: `ADR-038 §5.2` ignora los parámetros desconocidos. Un *endpoint* de exportación al que le falte un filtro del listado exporta **en silencio** más de lo que el usuario ve filtrado. `ADR-038` llama a eso incidente de privacidad cuando ocurre en un listado, y en un fichero que sale del sistema es peor. Hacerlo en el cliente (una lista de «filtros exportables» por tabla) añade una segunda declaración que puede divergir, y protege solo a la SPA, no a otro cliente de la API (`INV-006`).

**Test exigible por *endpoint***: todo parámetro de filtro del listado en OpenAPI existe en el esquema de la solicitud de exportación, o el test falla. **`POST /audit-logs/exports` no cumple esta regla hoy** (hallazgo 2).

#### 8.3 Lo demás, ratificado

Generación en cola (`INV-012`), límite de filas con `422` (`RNF-LIM-004`), ámbito del permiso aplicado dentro del trabajo (`RN-PERM-15`), URL firmada de caducidad corta, evento `exported`, retención de siete días. Todo es lo que ya hace `1.1`, sin cambios. **XLSX** sigue fuera: exige una dependencia PHP nueva con su diligencia, y ningún consumidor la pide.

### 9. Texto libre y `data_exports.filters`

**El riesgo de la especificación es real, y es más concreto de lo que dice.** No es solo que `q` quede en `data_exports`, que se purga a los siete días. Con la política `Full` de `DataExport`, el `created` automático copia `filters` en `audit_logs.changes`, tabla inmutable y con dos años de retención. Ahí la supresión se ejerce por retención de la fila entera, precisamente porque `ADR-035 §1` garantiza que no contiene valores del sujeto. Un `q=López` rompería esa garantía, y el tope de 256 caracteres de `ADR-035 §5` no lo atrapa.

**Regla**: ningún texto libre introducido por un usuario llega a `audit_logs` a través de `data_exports`. En concreto:

1. **Ningún *endpoint* de exportación acepta `q`** mientras un paso no justifique lo contrario con un caso real. Si el listado del recurso acepta `q`, la exportación responde **`422`** cuando lo recibe, con código de error propio del recurso. No se ignora: `q` es un parámetro conocido del recurso, y ignorarlo produciría el fichero «filtrado que no lo está» de §8.2. `ADR-038 §5.2` define `q` como el único texto libre de un listado, así que la regla cubre todo el texto libre.
2. **Si un paso necesita exportar con búsqueda**, la vía es esta y no otra: el texto va en una **columna propia de `data_exports`, fuera de `filters`**, añadida por *expand*, y `DataExport` pasa de `Full` a **`Selective`** con lista de inclusión de todo lo demás (`ADR-035 §2`: la columna nueva se redacta como `identifier`, con sus banderas de vacío). El cambio de política edita el test del registro de modelos `Full` de `ADR-035 §2`, que es exactamente la revisión consciente que ese test fuerza.
3. **Vías descartadas para ese caso**: redactar `filters` entero (se pierde qué se exportó, que es lo que `funcional.md §4.6` punto 4 quiere auditar); guardar un *hash* de `q` (prohibido por `ADR-035 §3`: un apellido tiene poca entropía y su *hash* es el dato); confiar en el tope de tamaño (no atrapa cadenas cortas).

Hoy no ocurre: la exportación de auditoría no acepta `q`. La regla existe para que el primer *endpoint* de exportación de usuarios (`1.9b`, cuyo listado sí acepta `q`) no la estrene.

### 10. Norma común de CSV

Aplica a todo generador de CSV de `apps/api`. **No se implementa en `1.9`**, que no añade ningún generador: se implementa en la rama `fix/` del issue #270, tras la ratificación de este ADR y **antes del primer paso que añada un segundo generador de CSV** (`OPEN-CORE-24`).

#### 10.1 Una sola vía de escritura (`RN-CORE-47`)

- Una **clase única** en `apps/api/app/Support/Csv/` (infraestructura compartida, como `App\Support\Audit`, para que cualquier módulo la use sin importar código interno de `Core`, `INV-007`). El nombre concreto lo elige quien la implemente.
- **Corrección a la especificación**: sin par interfaz + implementación. `RNF-MANT-007` pide envolver **dependencias externas**, y `fputcsv` es PHP. No hay una segunda implementación previsible, y la propia clase ya es el punto único. Una interfaz aquí sería una abstracción sin beneficio.
- **Test de arquitectura**: ninguna llamada a `fputcsv` (ni a `SplFileObject::fputcsv`) fuera de esa clase. Entra con la clase.

#### 10.2 Celdas tipadas y neutralización solo sobre texto (`RN-CORE-48`, amplía `RN-CORE-36`)

- **Cada generador declara el tipo de cada columna en su esquema** (§8.1), y la clase escribe según ese tipo. **Nunca infiere el tipo del contenido**, porque entonces una cadena `"-5"` y un entero `-5` se tratarían igual según cómo llegaran. Tipos admitidos: texto, entero, fecha/instante y nulo. Los enteros (incluidos los céntimos de `ADR-029`) se escriben sin apóstrofo: `-5` sigue siendo `-5`. Los instantes se escriben en ISO 8601 con desfase. No se admite coma flotante: un decimal (una nota) lo formatea el generador como texto. Añadir un tipo decimal cuando haga falta es aditivo.
- **La neutralización recibe solo cadenas** y se aplica a toda celda de texto y a la cabecera. Se antepone un apóstrofo cuando se cumple cualquiera de estas dos condiciones:
  1. el primer carácter es `=`, `+`, `-`, `@`, tabulador, retorno de carro o salto de línea (`RN-CORE-36`, vigente);
  2. **el primer carácter que no es espacio en blanco** es `=`, `+`, `-` o `@`, tras uno o más espacios en blanco (espacios Unicode incluidos). Como expresión: `/^(?:[=+\-@\t\r\n]|[\s\p{Z}]+[=+\-@])/u`.
- **Sobre la propuesta de #270** (neutralizar todo valor que empiece por cualquier espacio en blanco, `/^[\s=+\-@]/`): se adopta **la protección, no la regla**. La segunda condición cubre el caso que #270 señala (`" =1+1"`), y así `" Juan"` no gana un apóstrofo que no protege de nada. Es defensa en profundidad y no un fallo explotable demostrado: **no se ha verificado** contra versiones concretas de Excel, LibreOffice o Sheets que un espacio inicial active la fórmula. Se añade porque su coste es nulo. Quedan fuera los signos de ancho completo (`＝`) y otras variantes Unicode: no hay constancia de que se evalúen, y se acepta como riesgo residual.

#### 10.3 Dialecto (`OPEN-CORE-24` A)

**Coma, UTF-8 con BOM, fin de línea CRLF, comillas dobles de RFC 4180 (una comilla dentro del campo se duplica) y sin carácter de escape** (`escape: ''` en PHP; el `\` por defecto no es RFC 4180 y deja sin duplicar la comilla de un campo que contiene `\"`). Cabecera siempre presente. Sin la línea `sep=`, que arregla Excel y rompe cualquier otro lector.

Consecuencia aceptada: en Excel con configuración regional española, un doble clic puede mostrar todo en una columna. Se documenta en el manual cómo importar con separador. No hay dato de uso real de centros que respalde otra opción. La exportación de auditoría gana BOM y CRLF al adoptar la clase: es un cambio visible en el fichero y va al `CHANGELOG.md`.

---

## Motivo

| Criterio | **Componente único envuelto, servidor genera, sin virtualizar** (elegida) | TanStack importado por cada módulo | Exportación en el cliente con las filas cargadas | Virtualizar el modo `cursor` ya |
|---|---|---|---|---|
| Coste en solitario | Medio: un directorio, dos modos, tests de frontera | El más bajo hoy | Bajo | Alto: dependencia, `aria-rowcount`/`aria-rowindex`, tarjetas de altura variable |
| Mantenimiento a 3 años | TanStack 8 → 9 se cambia en un directorio | El cambio de versión toca cincuenta módulos | Cada tabla, su generador | Accesibilidad frágil en el componente más usado |
| Invariantes | `INV-002`, `INV-007`, `INV-012` intactos | `RNF-MANT-007` roto desde el primer consumidor | **Rompe `INV-002`** (leer ⇒ exportar) y **deja sin auditar** | Riesgo sobre WCAG 2.2 AA (`RNF-UX-002`) |
| Reversibilidad | Alta: añadir `local`, virtualización u orden de columnas es aditivo dentro de `src/data-table` | Baja una vez hay cincuenta usos | — | Media |

**Sobre §9 y §10**, el criterio es el mismo de `ADR-035`: el dato que nunca se escribe no necesita mantenimiento, y una regla que falla en cerrado (rechazar `q`, neutralizar por tipo declarado) es más barata de sostener que una que depende de que cada autor se acuerde.

---

## Consecuencias

**Positivas**

- Un módulo nuevo pinta un listado declarando columnas y una función de petición. Accesibilidad, tarjetas, estados y exportación le vienen dados.
- La norma de CSV que `SECURITY.md` prometía queda fijada y es comprobable por test.
- La trampa de `data_exports.filters` queda cerrada antes del primer *endpoint* que podía activarla.

**Negativas y riesgos aceptados**

- Más de mil filas de un flujo de eventos no se ven en pantalla: se acota o se exporta.
- No se puede exportar el resultado de una búsqueda de texto libre hasta que un paso lo justifique y pague la columna aparte de §9.2.
- La configuración de columnas se comparte entre usuarios de un mismo navegador y sobrevive al cierre de sesión.
- Hasta que se resuelva #128, ninguna exportación termina fuera de un entorno con `queue:work` arrancado a mano. La interfaz se comporta bien en ese caso (§7.5), pero no funciona.
- El CSV con coma se abre mal con doble clic en Excel en español.

**Cambios obligatorios en `docs/modulos/REQ-CORE/funcional.md §13`** (los hace quien mantiene esa especificación, no este ADR):

- `§13.1.2`, `§13.13`, `RN-CORE-37` y `CA-CORE-165`: sin virtualización. El criterio deja de ser condicional y la regla deja de mencionar la biblioteca de virtualización.
- `§13.5`: dos modos en `1.9`, sin `local`. Retirar `CA-CORE-199`. Añadir las reglas de §2.3 (reintento de «cargar más» con el mismo cursor, sin desplazamiento infinito).
- `§13.9` y `CA-CORE-177`-`179`: dejan de estar condicionados. Criterio de la excepción de desplazamiento según §4.
- `CA-CORE-176`: verificación por escaneo de fuentes y `tableId` literal `<modulo>.<nombre>` (§5.3), no «registro ensamblado».
- `CA-CORE-198` y `RN-CORE-38`: estado en URL opcional por tabla, como máximo una por ruta, y sin `cursor` en la URL (§6.2).
- `§13.14.1` punto 2 y `CA-CORE-188`: la solicitud **no** lleva `sort` (§8.1), y el control está deshabilitado con `q` activo (§7.4). Añadir el criterio correspondiente.
- `§13.14.3` último punto y `datos.md`: sustituir la alternativa abierta por la regla de §9.
- `§13.14.4`: el aviso es permanente junto al estado, sin diálogo ni `beforeunload` (§7.7).
- `RN-CORE-47`: una clase, sin interfaz (§10.1). `RN-CORE-48`: tipos declarados por esquema y la segunda condición de neutralización (§10.2).
- `RN-CORE-49`: valores fijados (§7.5).
- `§13.21`/`§13.22`/`§13.23`: marcar como resueltas `OPEN-CORE-19` a `-29` y remitir aquí.

---

## Alternativas descartadas

- **Un segundo componente de tabla para el modo cursor**, leyendo `ADR-038 §4.5` al pie de la letra: duplica semántica, tarjetas, estados y anuncios sin servir al motivo del ADR (§2.2).
- **Desplazamiento infinito**: ver §2.2.
- **Virtualizar ahora**, con `@tanstack/vue-virtual` o con una solución propia: coste de accesibilidad sin un caso que no se resuelva con el tope y la exportación. Además, la diligencia de la dependencia no está hecha.
- **Solo desplazamiento horizontal en móvil**, o **solo tarjetas**: la primera es peor de usar a 320 px con más de tres columnas; la segunda deja sin salida a las tablas comparativas.
- **Persistir la configuración de columnas en servidor**, o **solo en memoria**: ver §5.2.
- **Registro de tablas en el `shell.ts` de `ADR-053`** para comprobar `tableId`: cambia la forma de otro ADR por un riesgo menor (§5.3).
- **`q` en la URL**: ver §6.1.
- **Exportación en el cliente**: ver Motivo y §7.1.
- **Las columnas visibles como contenido del fichero** (`OPEN-CORE-23` B): ver §8.1.
- **Lista de «filtros exportables» declarada en el cliente**: ver §8.2.
- **Exportar ignorando `q`**, **redactar `filters` entero**, **guardar un *hash* de `q`**: ver §9.
- **Interfaz + implementación para el escritor CSV**, o **una biblioteca CSV externa**: ninguna aporta nada sobre `fputcsv` con el dialecto fijado, y la segunda sería una dependencia nueva sin necesidad.
- **Neutralizar todo valor con espacio inicial** (propuesta literal de #270): ver §10.2.
- **Punto y coma o separador por idioma** (`OPEN-CORE-24` B/C): no es RFC 4180, invierte el problema para los centros de otros idiomas, o hace que el fichero dependa de quién lo pidió.

---

## Preguntas abiertas

- **`OPEN-054-01` · Idioma de la cabecera y de los valores enumerados del CSV.** `CLAUDE.md §7` exige los cuatro idiomas en los «documentos generados». La exportación de auditoría escribe hoy nombres técnicos de columna (`occurred_at`) y códigos (`event`), coherente con un contrato estable para programas (§8.1), pero poco legible para secretaría. Este ADR **no lo decide**: no hay requisito que precise si un CSV de datos es un «documento generado» en ese sentido, y rellenarlo sería inventar. Lo tiene que resolver el usuario antes del primer *endpoint* de exportación nuevo (`1.9b`). **No bloquea `1.9`.**

---

## Hallazgos fuera del ámbito de este ADR

No se corrigen aquí (`architect` solo escribe en `docs/adr/` y en el índice de la sección 18):

1. **`GET /audit-logs` y `POST /audit-logs/exports` usan `from`/`to`, no `occurred_at_from`/`occurred_at_to`** (`AuditLogsController`, `IndexAuditLogsRequest`, `REQ-CORE/api.md` línea 542), contra `ADR-038 §5.2`. Código y documento de módulo coinciden entre sí; los dos contradicen el ADR. Con la regla «`id` = parámetro» de `ADR-038 §13.3`, la pantalla de auditoría de `1.9b` enviaría un rango que el servidor ignora en silencio. **Severidad Media** (`CLAUDE.md §6.6`). Propuesta: en `1.9b`, antes de la pantalla, aceptar además los nombres conformes (cambio compatible, `ADR-038 §7`) y retirar los antiguos por *expand/contract*.
2. **`POST /audit-logs/exports` no tiene paridad de filtros con su listado** (le faltan `actor_id`, `actor_type`, `auditable_id` y `module`): incumple §8.2. El ámbito del permiso se sigue aplicando dentro del trabajo, así que no sale nada que el usuario no pueda leer, pero el fichero no corresponde a lo que ve. **Severidad Media.** Se corrige antes de que `1.9b` conecte el botón de exportar de auditoría.
3. **`SECURITY.md`**, fila «Exportaciones generadas (CSV)»: enumera los caracteres de `RN-CORE-36` **sin el salto de línea**, que el código y `RN-CORE-36` sí incluyen. Tiene que remitir a este ADR y a la segunda condición de §10.2.
4. **`PLAN-IMPLEMENTACION.md`**, línea de `1.9`: sigue diciendo «virtualización» (§3).
5. **`PRIVACY.md §2.1b`**: falta la clave `plataforma.table.<tableId>` (§5.2). Entra con la implementación de `1.9`.
6. El historial de versiones de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` necesita una fila por el alta de este ADR en el índice; queda fuera de la sección 18.
