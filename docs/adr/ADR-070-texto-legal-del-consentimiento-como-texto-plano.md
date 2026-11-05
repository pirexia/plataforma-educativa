# ADR-070 · El texto legal de `terms_versions` es texto plano

**Estado**: **ACEPTADA** (2026-11-05). El usuario aprueba ese día la decisión de fondo: `title` y `body` son texto plano, el servidor rechaza el marcado HTML, el frontend los pinta siempre como texto y el futuro servicio HTML→PDF los escapa. Este ADR la deja por escrito y fija los detalles comprobables. Queda abierta, con su recomendación, `OPEN-070-01` (`§8`).
**Fecha**: 2026-11-05
**Origen**: issue #481 (severidad baja), detectado en la revisión de `1.14-b`.
**Afecta a**: `REQ-FAM-UNIT-003` (condiciones de uso), `REQ-FAM-UNIT-004` (consentimiento de imagen), `REQ-FAM-UNIT-005` (autorización de recogida, `kind = autorizacion_recogida`), `REQ-FAM-PORTAL` (`1.22`), el servicio HTML→PDF (`ARCHITECTURE.md`, tabla del stack de `CLAUDE.md §1`), `INV-006`, `INV-010` y `CLAUDE.md §8`.
**Se apoya en**: `ADR-062` (contenido del centro en `<campo>_translations`, resuelto en servidor), `ADR-066 §5` (texto versionado en `terms_versions`), `ADR-067` (muro de condiciones, que pinta el texto) y `ADR-068` (cuarto `kind`, `autorizacion_recogida`).
**No sustituye ningún ADR.** Complementa `ADR-066`, que es inmutable y no declaraba el formato del texto. Tampoco modifica `ADR-062`: la regla vale solo para `terms_versions`, no para todo el contenido traducible.

---

## 1 · Contexto

`terms_versions` guarda los textos que una familia o un usuario aceptan: las condiciones de uso, los dos niveles del consentimiento de imagen y la autorización de recogida. `title_translations` y `body_translations` son `jsonb` por idioma (`ADR-062`), y la API expone `title` y `body` ya resueltos (`api.md §1.7`, `§1.9`). Ni `ADR-066` ni `api.md` dicen en qué formato está ese texto, y la validación de entrada tampoco lo restringe. Sin contrato, el primer cliente que necesite negritas o listas lo pintará con `v-html`, y eso abre un XSS almacenado. El autor del texto es personal con `texto_legal.crear` y la víctima, cualquier tutor que lo lea en el portal o en el muro. Cuando llegue el servicio HTML→PDF, el mismo texto se incrustará en una plantilla HTML.

### 1.1 · Hechos verificados (rama `fix/REQ-FAM-UNIT-texto-legal-plano`, sobre `b6c81b7`)

1. **Servidor.** `StoreTermsVersionRequest` y `UpdateTermsVersionRequest` validan las dos traducciones con `App\Support\I18n\TranslationsValidator` (recorte, vacío, idiomas activos y longitud: `TITLE_MAX_LENGTH = 200`, `BODY_MAX_LENGTH = 20000`). No hay ninguna restricción de formato. `TermsVersionService::create`, `update` y `publish` son el único camino de escritura, y los errores de negocio salen por `FamUnitErrors::invalid(...)` (precedente: `fam_unit.terms.kind_invalid`, `fam_unit.terms.default_locale_missing`).
2. **Frontend.** Los dos sitios que hoy pintan el texto lo hacen ya como texto, con interpolación `{{ }}` y `whitespace-pre-wrap` de Tailwind: `apps/web/src/modules/fam-unit/views/TermsWallView.vue` (líneas 112-121) y `apps/web/src/modules/fam-unit/components/TermsTextDialog.vue` (33-43). En `apps/web/src` **no hay ningún `v-html`**. El único test que lo prohíbe es `apps/web/src/modules/curso/views/RolloverNoRawHtml.spec.ts`, limitado a las vistas de `curso`.
3. **Datos.** En la base de desarrollo, `terms_versions` está vacía: 0 filas y 0 con marcado. Ningún *seeder* crea textos legales. Por tanto no hay ningún texto publicado con marcado que migrar. Esto importa porque, desde la migración `2026_11_05_100100`, un texto publicado no se puede modificar.
4. **PDF.** Todavía no existe el servicio HTML→PDF ni ninguna plantilla que incruste estos textos. El primer consumidor previsto es `1.22` (`REQ-FAM-PORTAL`).

Ningún hecho cambia la decisión aprobada: no hay datos con marcado, nadie usa HTML hoy y el frontend ya cumple en lo esencial.

## 2 · Problema

Hay que fijar un formato para el texto legal que cumpla cuatro condiciones: que sea seguro en cualquier cliente (SPA, portal, PDF y los que vengan, `INV-006`); que lo imponga el servidor (`INV-010`); que la persona que acepta lea exactamente lo que se guardó, porque el texto versionado es la prueba de qué se consintió, y que no exija una pieza nueva que mantener.

## 3 · Opciones reales

| | Opción | Coste en solitario | Mantenimiento a 3 años | Invariantes | Reversibilidad |
|---|---|---|---|---|---|
| **A** | **Texto plano**. Se rechaza el marcado al escribir y se pinta como texto en todos los clientes | Muy bajo: una comprobación en el servicio, un código de error y un test por cliente | Nulo: ninguna dependencia | Cumple `INV-010`. Es seguro por construcción en todo cliente que escape | **Alta**: pasar después a Markdown o a HTML es ampliar el formato, y todo texto plano ya guardado sigue siendo válido |
| B | HTML saneado con lista blanca (p. ej. `HTMLPurifier` en el servidor y `DOMPurify` en el cliente) | Medio-alto: dos dependencias nuevas que justificar (`CLAUDE.md §1`, `RNF-MANT-007`), una política de etiquetas y tests de evasión | Alto: los saneadores necesitan actualizaciones de seguridad y la lista blanca se discute con cada petición nueva | Seguro mientras el saneado sea correcto **en cada cliente**, incluido el PDF | Baja: una vez publicados textos con HTML (inmutables), ya no se puede volver a texto plano sin reinterpretarlos |
| C | Markdown renderizado a HTML | Medio: una biblioteca de Markdown con HTML crudo desactivado en servidor o cliente, más el saneado de la salida | Medio: una dependencia y diferencias de dialecto entre el renderizado web y el del PDF | Seguro solo si el HTML crudo está desactivado en todos los renderizadores | Media: el Markdown guardado queda atado a un dialecto concreto |

## 4 · Decisión: opción A

### 4.1 · Contrato

`title` y `body` de `terms_versions`, y cada valor de `title_translations` y `body_translations`, son **texto plano**: una secuencia de caracteres Unicode en la que los saltos de línea (`\n`) son la única estructura. No son HTML ni Markdown. Lo que se guarda es lo que se lee, carácter a carácter. La regla vale para **todos** los `kind` (`condiciones_uso`, `consentimiento_imagen_web`, `consentimiento_imagen_redes`, `autorizacion_recogida`) y para cualquier `kind` que se añada.

### 4.2 · Servidor: el marcado se rechaza, nunca se limpia

1. **Qué se considera marcado.** Un valor contiene marcado si encuentra la expresión `/<\s*[a-zA-Z!\/?]/u`. Esa expresión detecta la apertura o el cierre de una etiqueta, un comentario `<!--`, `<!DOCTYPE`, `<![CDATA[` y una instrucción de procesamiento `<?`. Un `<` suelto (por ejemplo, «menores de < 14 años») **no** es marcado y se admite. Las referencias de carácter (`&amp;`, `&#60;`) no ejecutan nada si se pintan como texto, así que no se rechazan: se muestran literalmente (`OPEN-070-01`).
2. **Dónde.** En `TermsVersionService`, que es el único camino de escritura (hecho 1), y no solo en los *requests*. Así lo cumplen también los futuros comandos o *seeders* que escriban textos (`INV-010`). La comprobación se aplica:
   - en `create`, a todos los idiomas de las dos traducciones;
   - en `update`, al objeto ya fusionado de cada campo que llegue;
   - en `publish`, otra vez, sobre el borrador. Así ningún borrador anterior a esta regla puede publicarse con marcado, y la inmutabilidad del publicado deja de ser un riesgo.
3. **Error.** `422` con `FamUnitErrors::invalid("<campo>_translations.<locale>", 'fam_unit.terms.markup_not_allowed', ['locale' => <locale>])`, con la misma forma que los errores de `TranslationsValidator` y un error por idioma afectado. La clave está traducida en los cuatro idiomas (`INV-009`). Se rechaza, nunca se elimina el marcado: quitar etiquetas sin avisar cambiaría en silencio el texto que alguien va a consentir.
4. **No va en `TranslationsValidator`.** La regla es propia del texto legal, no de todo el contenido traducible de `ADR-062`. Ampliar la primitiva compartida obligaría a decidir el formato de todos los campos `_translations` de todos los módulos, y eso no se ha pedido.

### 4.3 · Frontend: siempre como texto

1. `title` y `body` se pintan solo con interpolación de texto (`{{ }}`, `textContent`). **Prohibido** `v-html`, `innerHTML`, `outerHTML` e `insertAdjacentHTML` en cualquier componente que los muestre: muro, diálogo de texto, gestión de versiones, asistente de recogida, portal de `1.22` y los que vengan.
2. Los saltos de línea se muestran con `white-space: pre-line` (clase `whitespace-pre-line` de Tailwind). Los dos componentes actuales usan `whitespace-pre-wrap` (hecho 2) y pasan a `pre-line`. Así los espacios repetidos se agrupan como en un texto normal, y basta un salto de línea para separar párrafos.
3. Un test por componente lo comprueba, con el patrón de `RolloverNoRawHtml.spec.ts`.

### 4.4 · Servicio HTML→PDF (`1.22` y siguientes)

Toda plantilla que incruste `title` o `body` los escapa antes, con la interpolación que escapa del motor (`{{ }}` en Blade, nunca `{!! !!}`) o con `e()`. Los saltos de línea se resuelven con `white-space: pre-line` en la plantilla, no convirtiendo `\n` en `<br>` sobre el texto sin escapar. Si algún día hiciera falta `nl2br`, se aplica **después** de escapar: `nl2br(e($body))`. Esta obligación forma parte de la especificación de `1.22` y de cualquier entrega que genere un PDF con estos textos.

### 4.5 · Documentación

`api.md §1.7` y `§1.9` declaran que `title`, `body` y sus traducciones son texto plano. El esquema OpenAPI (`apps/api/openapi/paths/fam-unit.yaml`, `TermsVersion` e `ImageConsentText`) lo dice en la `description` de cada campo y añade `fam_unit.terms.markup_not_allowed` a las respuestas `422` de `POST`, `PATCH` y `publish`. El catálogo de errores de `api.md` incluye la clave nueva.

## 5 · Motivo

- **La seguridad no depende de que el filtro de entrada acierte.** La defensa real es pintar como texto (`§4.3`, `§4.4`): un texto que se escapa al pintarse no ejecuta nada, aunque contenga marcado. El rechazo en servidor completa esa defensa. Fija el contrato para los clientes futuros, evita que alguien escriba HTML esperando que se interprete y garantiza que lo que se ve coincide con lo que se guardó. Por eso basta una expresión sencilla, y no hace falta un analizador de HTML.
- **El texto es la prueba del consentimiento.** Con texto plano, el contenido de la versión aceptada no depende de un renderizador, un dialecto o una lista blanca que cambien con el tiempo. Con HTML o Markdown, el mismo texto guardado podría verse distinto en la web y en el PDF, o dentro de dos años.
- **Coste y reversibilidad.** A no añade dependencias ni piezas que mantener, y es la única opción que permite cambiar de idea después: ampliar a un formato rico más adelante es compatible con todo lo publicado. Ir de B o C a texto plano no lo es, porque los textos publicados son inmutables. La petición que justificaría B o C (formato enriquecido) no existe hoy, así que su complejidad no aporta nada.
- **Coherencia con lo que ya hay.** El frontend ya pinta como texto y no hay datos con marcado (`§1.1`). La decisión fija por escrito lo que ya se hace y lo hace obligatorio.

## 6 · Consecuencias

**A favor**: el XSS almacenado queda cerrado en todos los clientes, presentes y futuros. El contrato es claro para `1.22` y para el PDF, no hay dependencias nuevas y el coste de implementación es mínimo.

**En contra**:

- El centro no puede usar negritas, enlaces ni listas con formato en sus textos legales. La estructura se consigue con saltos de línea, numeración escrita («1.», «a)») y mayúsculas. Si un centro necesita formato de verdad, hará falta un ADR nuevo que amplíe el formato (probablemente Markdown restringido, opción C) y que diga cómo se pintan las versiones ya publicadas.
- Un texto pegado desde un procesador de textos o una web con etiquetas se rechaza, y quien lo pegó tiene que limpiarlo. Es lo buscado: el texto que se consiente no se altera en silencio.
- Un `<` seguido de una letra en un texto legítimo (por ejemplo, «<a» en una abreviatura) se rechaza. Es poco probable en un texto legal, y el mensaje de error indica el idioma afectado.

**Requisitos afectados**: `REQ-FAM-UNIT-003`, `-004` y `-005` (formato de su texto), `REQ-FAM-PORTAL` (pintado y PDF). No cambia ningún dato ni ninguna migración: no hace falta tocar el esquema y no hay textos que migrar.

## 7 · Criterios de aceptación

Cada uno con un test que cite `ADR-070` y el `REQ` correspondiente (`INV-015`):

- **CA-070-01**: un `POST /terms-versions` con `<b>`, `</p>`, `<script>`, `<!-- -->` o `<img src=x onerror=…>` en cualquier idioma de `title_translations` o `body_translations` responde `422` con `fam_unit.terms.markup_not_allowed` en la ruta `<campo>_translations.<locale>` y no crea nada.
- **CA-070-02**: lo mismo con `PATCH /terms-versions/{id}`. El borrador queda como estaba.
- **CA-070-03**: un borrador con marcado escrito directamente en la base (simula un dato anterior a la regla) no se publica: `POST /terms-versions/{id}/publish` responde `422 fam_unit.terms.markup_not_allowed` y el borrador sigue en `borrador`.
- **CA-070-04**: se aceptan y se devuelven intactos un texto con saltos de línea, un `<` suelto («menores de < 14 años»), `&amp;` y caracteres no latinos.
- **CA-070-05**: la comprobación se aplica en `TermsVersionService` (un test de servicio sin HTTP la cubre), no solo en el *request*.
- **CA-070-06** (Vitest): `TermsWallView.vue` y `TermsTextDialog.vue` no contienen `v-html`, `innerHTML`, `outerHTML` ni `insertAdjacentHTML`, y el contenedor del cuerpo usa `whitespace-pre-line`. Un `body` con `<b>x</b>` se muestra literalmente: `textContent` contiene `<b>x</b>` y no aparece ningún elemento `b`.
- **CA-070-07** (`1.22`): toda vista o plantilla de PDF que muestre `title` o `body` entra en el test de `CA-070-06`, o en uno equivalente para Blade (sin `{!!` sobre estos campos). El HTML generado para un `body` con `<` contiene `&lt;`.
- **CA-070-08**: `api.md` y el OpenAPI declaran el formato de texto plano y el error nuevo, y la clave `fam_unit.terms.markup_not_allowed` existe en los cuatro idiomas (`TranslationParityTest`).

## 8 · Preguntas abiertas

- **`OPEN-070-01`** (RESUELTA por el usuario el 2026-11-05: no se rechazan; también confirmado `pre-line`): ¿se rechazan también las referencias de carácter HTML (`&amp;`, `&nbsp;`, `&#60;`)? **Recomendación: no.** Pintadas como texto no ejecutan nada, rechazarlas no añade seguridad y en texto legal casi no aparecen. Si se aprobara rechazarlas, bastaría con ampliar la expresión de `§4.2.1`, sin cambiar nada más.

## 9 · Alternativas descartadas

- **HTML saneado con lista blanca (B).** Añade dos dependencias de seguridad que mantener y una política de etiquetas que discutir. Además, la seguridad pasaría a depender de que el saneado sea correcto en cada cliente, PDF incluido. Una vez publicados textos con HTML (inmutables), ya no se podría volver atrás. No hay ninguna petición de formato que lo justifique.
- **Markdown (C).** Menos peligroso que B, pero también necesita una dependencia, desactivar el HTML crudo en todos los renderizadores y mantener la paridad de dialecto entre la web y el PDF, todo para un formato que nadie ha pedido. Si algún día se pide, es la ampliación natural desde A, con un ADR nuevo.
- **Limpiar el marcado en vez de rechazarlo.** Cambiaría en silencio el texto que se va a consentir, y quien lo escribió no sabría qué se ha guardado. Contradice el sentido de un texto versionado como prueba.
- **Llevar la regla a `TranslationsValidator` para todo el contenido traducible.** Decidiría el formato de todos los campos `_translations` del proyecto, y eso no se ha pedido. Si otros campos necesitan la misma regla, se extrae entonces a `App\Support` con su propio ADR.
- **Prohibir `v-html` en todo `apps/web` con una regla de ESLint.** Es razonable y barato, pero va más allá del issue #481 y afecta a todo el frontend. Si se quiere, se propone aparte; no forma parte de esta decisión. Los tests por componente de `§4.3.3` bastan para cerrar el riesgo del texto legal.
