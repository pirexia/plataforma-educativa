# ADR-055 · El CSV de datos como contrato técnico

**Estado**: **ACEPTADA** (2026-10-01, ratificada por el usuario). La decisión de fondo la tomó el usuario el 2026-10-01: **opción A ahora, con C como ampliación posterior**. El usuario ratificó el ADR entero, incluidas las precisiones de §2.3 y §2.4, la regla del nombre de fichero de §2.2 y la objeción de §3.3 (C contradice parcialmente el argumento de A: si se adopta, la regla pasa a decir expresamente que las columnas técnicas no dependen de quién lo solicita).
**Fecha**: 2026-10-01
**Resuelve**: `OPEN-054-01` (`ADR-054`, sección «Preguntas abiertas»; `docs/modulos/REQ-CORE/funcional.md §13.21`), que bloquea `1.9b`
**Precisa, sin sustituir**: el alcance de «documentos generados» de `ADR-021` (consecuencia) y `CLAUDE.md §7` (idiomas obligatorios), solo para los ficheros de datos de §1. No cambia ninguna decisión de `ADR-021`: los boletines, facturas y notificaciones se siguen emitiendo en el idioma del destinatario.
**Se apoya en**: `ADR-021`, `ADR-038 §3.2` (nombres en `snake_case`, enumerados no traducidos), `ADR-038 §7` (compatibilidad de contrato), `ADR-038 §11` (resolución del idioma), `ADR-054 §8.1` (esquema fijo por recurso), `§10.2` (celdas tipadas, ampliación aditiva) y «Alternativas descartadas» (separador por idioma), `INV-009`, `INV-012`
**No toca**: ningún *endpoint*, permiso, migración ni código. `ADR-054` sigue íntegro y vigente.
**Afecta a**: `1.9b` (primer *endpoint* de exportación nuevo, `POST /users/exports`, y cambios en `POST /audit-logs/exports`), la rama `fix/` del issue #270, y todo paso posterior que añada un generador de CSV de datos

---

## Contexto

`ADR-054 §8.1` fija que las columnas, su nombre, su orden y el orden de las filas de una exportación los define el servidor por recurso y se documentan en OpenAPI. Dejó abierta una cuestión (`OPEN-054-01`): **en qué idioma van la cabecera y los valores enumerados**.

Lo que había en juego:

- `CLAUDE.md §7` exige los cuatro idiomas (es-ES, en, de, fr) «en interfaz, documentos generados y contenido del centro». `ADR-021` concreta qué son los documentos generados: «los boletines, facturas y notificaciones deben emitirse en el idioma del destinatario». **Ningún requisito precisa si un CSV de datos entra en esa categoría.**
- La exportación de auditoría, la única que existe (`GenerateAuditLogExport`, verificado el 2026-10-01 en `522fb92`), escribe una cabecera técnica (`occurred_at`, `actor`, `actor_type`, `auditable_type`, `auditable_public_id`, `event`, `request_id`) y valores técnicos: `event` y `actor_type` son los códigos que guarda la base de datos, y `auditable_type` es el alias del *morph map* (`Relation::enforceMorphMap`). Es coherente con `ADR-054 §8.1`, pero secretaría lo lee mal.
- `ADR-038 §3.2` ya decide lo mismo para la API JSON: claves en `snake_case`, y enumerados como «cadena […] no traducida. La traducción para mostrar la resuelve el cliente o el servidor por catálogo, nunca cambiando el valor».
- `1.9b` añade `POST /users/exports`, el segundo generador. La decisión tiene que estar tomada antes, porque cambiar luego la cabecera de un fichero ya publicado rompe a quien lo procese.

Opciones reales:

| | Qué contiene el fichero |
|---|---|
| **A** | Cabeceras y valores enumerados técnicos, iguales para todos los usuarios e idiomas |
| **B** | Cabeceras y valores traducidos al idioma de quien lo solicita |
| **C** | A, más columnas legibles añadidas al final (`<col>_label`) en el idioma de quien lo solicita |
| **D** | Técnico o traducido según un parámetro de la solicitud |

---

## Decisión

### 1. Qué es un «CSV de datos»

A efectos de este ADR, un **CSV de datos** es el fichero que la API genera con filas de un recurso del sistema: hoy, el de `POST /audit-logs/exports`; después, el de todo `POST /<recurso>/exports` de `ADR-054 §8`.

**Un CSV de datos no es un «documento generado»** en el sentido de `ADR-021` y `CLAUDE.md §7`. Esos documentos están **dirigidos a un destinatario** (un boletín a una familia, una factura a un pagador, una notificación a un usuario), y su idioma es una propiedad de ese destinatario. Un CSV de datos es una **representación tabular de los datos del recurso**, el mismo contrato que la API expone en JSON pero en otro formato. Por eso le aplica `ADR-038 §3.2` y no `ADR-021`.

**Queda fuera de este ADR el informe de errores de una importación** (`report.csv` de `ValidateUserImport`). No es una exportación de datos de un recurso, sino la respuesta a quien subió un fichero: su forma es la de `errors` de `ADR-038 §6.3` (código estable más mensaje legible). Ver el hallazgo 1.

### 2. Opción A: el CSV de datos es un contrato técnico estable

#### 2.1 Regla

1. **Cabeceras**: identificadores técnicos en `snake_case`. Si la columna corresponde a un campo del recurso en la API, **lleva el mismo nombre que ese campo** (`ADR-038 §3.2`). Si no corresponde a ninguno (por ejemplo, `actor` en auditoría), lleva un identificador `snake_case` propio, documentado en el esquema del fichero en OpenAPI (`ADR-054 §8.1`).
2. **Valores enumerados**: el **código técnico**, el mismo que devuelve la API para ese campo, sin traducir (`event`, `actor_type`, `auditable_type`, `status`, `role`…). El idioma de los propios códigos es el que ya fija `ADR-038 §3.2` («idioma del dominio del código»). Este ADR no lo cambia.
3. **El fichero no depende de quién lo solicita**: dos usuarios con idiomas distintos que exportan el mismo recurso con los mismos filtros, en el mismo instante y con el mismo ámbito de permiso, obtienen el mismo contenido.
4. **Toda la interfaz que dispara y comunica la exportación** (control, estados, avisos, errores, caducidad y el texto del manual) **sigue en los cuatro idiomas** (`INV-009`, `ADR-021`). Lo que no se traduce es el contenido del fichero, no la experiencia que lo rodea.

#### 2.2 Regla operativa para quien escriba un generador de CSV de datos nuevo

- Declara el esquema del fichero (columnas, nombre, tipo según `ADR-054 §10.2`, orden de columnas y de filas) en el generador y en OpenAPI.
- Nombra cada columna con el campo de la API que representa, en `snake_case`. No inventes un nombre «más legible».
- Escribe el código del enumerado, nunca su etiqueta. **El generador no llama a `__()`, `trans()` ni a ningún catálogo de traducción.** Un generador de CSV de datos que traduce es un fallo de revisión.
- El nombre del fichero también es técnico (hoy, `<public_id>.csv`). No lleva el nombre del recurso traducido ni la fecha en formato local.
- Los textos de la interfaz que rodean la exportación van en el catálogo del módulo, en los cuatro idiomas, como cualquier otro literal.

#### 2.3 Precisión: los formatos tampoco dependen del idioma

Es consecuencia directa de §2.1 punto 3 y se hace explícita para que nadie la deduzca al revés:

- Instantes en ISO 8601 con desfase, y fechas sin hora como `AAAA-MM-DD` (`ADR-054 §10.2`, `ADR-038 §3.2`). Nunca `01/10/2026`.
- Enteros sin separador de miles.
- Un decimal que el generador formatee como texto (`ADR-054 §10.2`) usa **punto** como separador decimal y no lleva separador de miles. Nunca coma decimal según el idioma.

#### 2.4 Precisión: qué es compatible en el contrato del fichero

Por analogía con `ADR-038 §7`:

- **Compatible** (aditivo): añadir una columna **al final** del esquema. Se documenta en OpenAPI y en `CHANGELOG.md`, porque es visible para quien procesa el fichero aunque no lo rompa si lee por nombre de columna o solo por las posiciones existentes.
- **Compatible**: añadir un valor a un enumerado, igual que en la API (`ADR-038 §7.3`). Quien procese el fichero debe tolerar códigos que no conoce.
- **Incompatible**: renombrar, quitar o reordenar una columna existente, cambiar el formato de un valor o cambiar el orden de las filas. Exige un ADR que lo justifique y una entrada en `CHANGELOG.md` que lo marque como cambio que rompe.

Los cambios de dialecto ya decididos en `ADR-054 §10.3` (BOM y CRLF en la exportación de auditoría) siguen su propio camino. Ya se dijo allí que van a `CHANGELOG.md`.

### 3. Opción C: ampliación posterior, no se implementa ahora

C añade, **al final del esquema y sin tocar ninguna columna técnica**, una columna legible `<col>_label` por cada columna de enumerado que lo justifique (`event_label`, `status_label`…), con la etiqueta en el idioma de quien solicita la exportación. Es una ampliación **aditiva** en el sentido de `ADR-054 §10.2` y de §2.4 de este ADR.

**C no está decidida para ningún recurso ni se implementa en `1.9b`.** Ningún paso la añade sin cumplir lo siguiente.

#### 3.1 Cuándo se reconsidera

- Con **demanda real de un centro piloto** (`H0`, `ADR-019`), no por hipótesis. Una petición del equipo de desarrollo o «secretaría lo leería mejor» sin un centro detrás no es motivo.
- La propuesta indica el recurso concreto. C se adopta **por recurso**, no para todos a la vez.

#### 3.2 Qué hay que decidir entonces, en un ADR nuevo o en la especificación del paso con ratificación del usuario

1. **Catálogo de etiquetas por módulo en los cuatro idiomas**: dónde vive (catálogo de servidor del módulo dueño del recurso, `INV-007`), qué columnas lo llevan, y el test que comprueba que cada código del enumerado tiene etiqueta en los cuatro idiomas. Un código sin etiqueta escribe el propio código (rama por defecto, misma lógica que `ADR-038 §7.3`), nunca una celda vacía.
2. **De dónde sale el idioma del solicitante dentro del trabajo en cola.** El trabajo no pasa por el *middleware* de `ADR-038 §11`, y el idioma por defecto del proceso no es el del usuario (ver hallazgo 1). Las vías que habrá que comparar son guardar el idioma resuelto en la solicitud (una columna de `data_exports`, por *expand*, con su clasificación de auditoría de `ADR-035`) o resolverlo en el trabajo con la cadena de `ADR-038 §11` sin `Accept-Language`. Este ADR no elige.
3. **Las columnas técnicas no cambian**: ni nombre, ni orden, ni formato, ni posición relativa. Las `_label` van siempre detrás de todas las técnicas.
4. **Política para columnas nuevas** posteriores a la adopción de C: si una columna técnica nueva va antes o después del bloque de etiquetas. Cualquiera de las dos rompe a alguien (quien lee por posición o quien espera las etiquetas al final), así que se decide de forma explícita y una sola vez para todo el producto.
5. **Neutralización**: una etiqueta es una celda de texto y pasa por la neutralización de `ADR-054 §10.2` como cualquier otra, aunque venga de un catálogo propio.
6. **`CHANGELOG.md`**: añadir las columnas de C es un cambio de contrato visible y se documenta allí, con el recurso y las columnas afectadas.

#### 3.3 Lo que C cambia de §2.1 punto 3, dicho sin rodeos

Con C, **las columnas `_label` sí dependen de quién solicita el fichero**. Es justo la propiedad que A protege y el motivo con el que `ADR-054` descartó el separador por idioma. Se acepta porque el daño queda acotado: la parte del fichero que un programa procesa (las columnas técnicas) sigue sin depender del solicitante, y quien lee por nombre de columna no nota la diferencia. Si C se adopta, la regla de §2.1 punto 3 pasa a leerse como «**las columnas técnicas** no dependen de quién lo solicita», y el ADR o la especificación que adopte C lo dice expresamente.

---

## Motivo

| Criterio | **A** (elegida) | B · traducido al solicitante | C · A + `_label` | D · según parámetro |
|---|---|---|---|---|
| Coste en solitario hoy | Nulo: es lo que ya hace auditoría | Catálogo de cabeceras y de cada enumerado por módulo, en 4 idiomas, más idioma en el trabajo | El de B, sin romper A | El de B más el de A, más un parámetro |
| Mantenimiento a 3 años | Ninguno adicional: el contrato es el de la API | Cada columna o código nuevo, en 4 idiomas y en todos los generadores | Igual que B, solo en los recursos que la adopten | El doble de combinaciones que probar por recurso |
| Invariantes | `INV-009` intacta: la interfaz se traduce; el fichero no tiene literales de interfaz | `INV-009` cumplida, pero el fichero deja de ser contrato (`ADR-054 §8.1`) | Contrato técnico intacto | Contrato técnico condicionado a un parámetro |
| Reversibilidad | **Alta**: pasar a C es aditivo | **Baja**: cuando un centro tenga hojas o macros sobre cabeceras en español, volver atrás las rompe | Media: quitar columnas es incompatible (§2.4) | **Baja**: retirar un parámetro público es incompatible (`ADR-038 §7`) |

Se elige A por tres razones:

1. **Coherencia con lo ya decidido.** `ADR-038 §3.2` no traduce los enumerados de la API. `ADR-054 §8.1` hizo del esquema del fichero un contrato estable. Un CSV con otra regla sería la misma información con dos contratos.
2. **Es lo más reversible.** A deja C abierta como ampliación aditiva. B y D, en cambio, crean dependencias en los centros (hojas de cálculo, macros, integraciones) que impiden volver atrás.
3. **No hay demanda medida.** «Secretaría lo leería mejor» es razonable pero no está respaldado por ningún centro. Pagar hoy cuatro catálogos por módulo para un problema hipotético es la complejidad sin beneficio proporcional que este proyecto descarta por norma.

---

## Consecuencias

**Positivas**

- `1.9b` puede añadir `POST /users/exports` sin catálogo de etiquetas ni idioma en el trabajo.
- La exportación de auditoría ya cumple la regla de §2 y no hay que tocarla por este motivo.
- El esquema del fichero se documenta una sola vez, junto al de la API, con los mismos nombres.
- Un fichero exportado es reproducible y comparable entre usuarios, lo que también sirve para atender una incidencia.

**Negativas y riesgos aceptados**

- Secretaría ve `created`, `updated`, `exported` o los códigos de estado tal como los guarda el sistema. **Mitigación**: el manual de usuario de quien tenga permiso de exportar documenta, por cada exportación, el significado de cada columna y de cada código. Esa tabla del manual sí se escribe en los idiomas del manual.
- Una cabecera técnica en inglés (`occurred_at`) puede convivir con códigos en español (`activo`) en el mismo fichero. Es la regla vigente de `ADR-038 §3.2`, no una novedad de este ADR.
- Si un centro piloto pide etiquetas, el coste de C se paga entonces y por recurso (§3).

**Documentos que hay que actualizar al ratificarse este ADR** (no se editan aquí; `architect` solo escribe en `docs/adr/` y en el índice de la sección 18):

- **`ADR-054`**: es inmutable y no se edita. Su fila del índice de la sección 18 pasa de «Deja abierta `OPEN-054-01`» a «`OPEN-054-01` resuelta por `ADR-055`». Esa fila sí la puede cambiar `architect` al ratificarse.
- **`docs/modulos/REQ-CORE/funcional.md §13.21`**: `OPEN-054-01` pasa de «abierta; bloquea `1.9b`» a resuelta, con remisión a este ADR. Añadir a §13 (junto a `RN-CORE-47`/`48`) una regla de negocio con la norma de §2.2 y un criterio de aceptación que la pruebe: el generador no traduce, y la cabecera coincide con el esquema documentado en OpenAPI.
- **`docs/modulos/REQ-CORE/api.md`**: el esquema del CSV de auditoría, declarado como contrato técnico, con la remisión a este ADR.
- **`SECURITY.md`**: no procede. La decisión no cambia ningún control de seguridad, y la neutralización de las futuras `_label` ya la cubre `ADR-054 §10.2`.
- **`README.md`**: no procede salvo que cite el estado de los ADR; lo contrasta `doc-reviewer`.
- **Manual de usuario** (`docs/manual-usuario/admin.md`, el único que existe hoy, y `secretaria.md` cuando se cree), al llegar la pantalla de exportación en `1.9b`: tabla de columnas y códigos de cada exportación.
- **`PLAN-IMPLEMENTACION.md`**: `1.9b` deja de estar bloqueado por `OPEN-054-01`.
- **`memory.md`**: la decisión y su ADR.
- **`CHANGELOG.md`**: entrada del paso que cierre la ratificación.

---

## Alternativas descartadas

- **B · Cabeceras y valores traducidos al idioma del solicitante.** El fichero pasa a depender de quién lo pide: dos usuarios exportan los mismos datos y obtienen ficheros distintos. Es el mismo criterio con el que `ADR-054` («Alternativas descartadas») descartó el separador por idioma. Además rompe el contrato de `ADR-054 §8.1`, porque un programa que lee la columna `event` no la encuentra si el solicitante usa el alemán. Tiene un coste permanente por módulo (cada columna y cada código en cuatro idiomas, en cada generador, con sus tests) y es difícil de revertir: en cuanto un centro construya hojas sobre cabeceras traducidas, volver a las técnicas le rompe el trabajo.
- **D · Técnico o traducido según un parámetro de la solicitud.** El fichero depende de quién lo pide y de cómo. Suma los costes de A y B y duplica lo que hay que probar en cada recurso. El parámetro acabaría en `data_exports.filters`, y por tanto en `audit_logs.changes` (`ADR-054 §9`), sin aportar nada a la auditoría. Una vez publicado en la API, retirarlo es un cambio incompatible (`ADR-038 §7`). Es la opción menos reversible de las cuatro.
- **Tratar el CSV de datos como «documento generado» y aplicarle `ADR-021` sin más.** Es B con otro nombre. `ADR-021` se refiere a documentos con destinatario (boletines, facturas, notificaciones) y no dice nada de ficheros de datos. Extenderlo sin requisito que lo pida sería inventarlo.
- **Implementar C ya, en `1.9b`.** No hay demanda real y obliga a resolver ahora el idioma dentro del trabajo en cola (§3.2 punto 2), con una migración en `data_exports`. Es complejidad anticipada sin beneficio medido. Como es aditiva, esperar no cuesta nada.

---

## Hallazgos fuera del ámbito de este ADR

No se corrigen aquí.

1. **El informe de errores de la importación de usuarios no sale en el idioma de quien importa.** `ValidateUserImport` (trabajo en cola) escribe `message` con `__('core.validation.import_unknown_header')` y los demás mensajes. En un trabajo en cola no corre el *middleware* `ResolveApiLocale` (`ADR-038 §11`), así que `__()` usa `config('app.locale')`, que vale `en` por defecto (`apps/api/config/app.php`, `APP_LOCALE`). Afecta tanto a `report.csv` como a `user_imports.error_summary`. El mensaje es texto dirigido a un usuario y debería salir en su idioma (`ADR-038 §6.3`, `INV-009`). **No verificado en ejecución**: es lectura de código, y habría que confirmar que ningún otro punto fija el idioma del trabajo. Severidad propuesta: **Media** (`CLAUDE.md §5`: incumplimiento de invariante con rodeo posible). Es además la prueba concreta del problema de §3.2 punto 2: resolverlo para la importación da la pieza que C necesitará.
2. `ValidateUserImport::writeReport` sigue llamando a `fputcsv` sin neutralizar. Ya está anotado en el issue #270 y en `SECURITY.md`; se recuerda solo porque el informe queda fuera del ámbito de este ADR (§1) y sigue dentro del de `ADR-054 §10`.
