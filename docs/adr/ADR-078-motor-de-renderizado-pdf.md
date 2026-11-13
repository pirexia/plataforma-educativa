# ADR-078 · Motor de renderizado PDF: WeasyPrint en un servicio propio mínimo, tras la interfaz `PdfRenderer`, con plantillas HTML y CSS sin JavaScript

**Estado**: **PROPUESTA** (ola 1 de arquitectura, agente A2). La acepta el usuario; hasta entonces no se implementa nada.
**Fecha**: 2026-11-12
**Origen**: el servicio de PDF está «pendiente a propósito» desde `PLAN 0.3` («motor sin decidir, ver 1.17») y bloquea `1.17` (boletines), la exportación PDF de `REQ-CORE-005` (`docs/modulos/REQ-CORE/funcional.md §4.6`), `OPEN-ACAD-38` (horarios) y `OPEN-ALUM-15` (listados).
**Afecta a** (cuando se implemente; este ADR no toca nada de ello): `compose.yaml`, `infra/` (imagen y unidad Quadlet nuevas), `apps/api/app/Support/` (contrato nuevo), `apps/api/config/`, CI, y la documentación de `§12`.
**Concreta sin sustituir**: `ADR-007` (stack: «servicio contenerizado de generación de PDF») y la fila «PDF» de `CLAUDE.md §1`.
**No sustituye ningún ADR.** Si el usuario eligiera la opción C (Typst), habría que enmendar la fila «PDF» de `CLAUDE.md §1` (dice «HTML→PDF»): ver `OPEN-PDF-01`.
**Se apoya en**: `INV-001`, `INV-003`, `INV-007`, `INV-009`, `INV-012`, `RNF-MANT-007`, `RNF-COMP-004` (PDF/A para documentos con valor archivístico), `RNF-UX-002` (WCAG 2.2 AA y EN 301 549), `ADR-012` (firma por prestador cualificado, sin criptografía propia), `ADR-021` (idiomas), `ADR-028` (red), `ADR-030` (perfil reducido), `ADR-076` (almacén de objetos), `CLAUDE.md §8` y `§9`.
**Depende de**: `ADR-082` (firma digital), **que no existe todavía** (rama `chore/adr-082-firma-digital-y-apoderado` sin el fichero a 2026-11-12). `§8` fija solo la frontera entre ambos; lo demás queda en `OPEN-PDF-05`.

---

## 1 · Contexto

### 1.1 Qué hay hoy

- **Ningún motor ni servicio de PDF.** `compose.yaml` lo deja dicho en un comentario; `apps/api/composer.json` no tiene ninguna librería de PDF (comprobado con `grep` el 2026-11-12).
- **Colas**: *driver* `database`, proceso `worker` (`--timeout=120`, colas por módulo como `core-exports`) y proceso `worker-long` (`--timeout=600`, `retry_after` 900). La tabla `job_batches` existe. `QueueWorkerTest` exige que toda cola con `->onQueue()` esté en uno de los dos procesos.
- **Almacén**: `PrivateObjectStore` (`App\Support\Files`, `ADR-076`), claves `tenants/<public_id>/<área>/<ULID>.<ext>`, descarga por URL firmada de caducidad corta.
- **Tipografía de la SPA**: pila del sistema, sin `@font-face` (`ADR-052 P4`, `docs/design-system.md`). Dentro de un contenedor esa pila no significa nada: el PDF necesita fuentes propias (`§7.4`).

### 1.2 Qué piden los requisitos (y qué no)

| Requisito | Qué pide | Lo que exige al motor |
|---|---|---|
| `REQ-CALIF-002` | Boletines por período, logo y datos del centro, firma del tutor, pie configurable, «formato profesional», firma digital, envío por correo | Documento paginado con marca del centro; generación en lote; PDF firmable después |
| `PLAN 1.17`, `REQ-INF` | Emisión en el idioma del destinatario (`ADR-021`) | Cuatro idiomas, `lang` correcto, partición de palabras por idioma |
| `REQ-CALIF-004`, `REQ-SEC-001` | Plantillas configurables por centro | Entrada parcialmente no confiable (`§6`) |
| `REQ-CORE-005`, `REQ-ACAD-002`, `REQ-ALUM-003` y otros | «Exportación a PDF» de listados, horarios y auditoría | Tablas largas de varias páginas, cabecera repetida |
| `RNF-COMP-004` | PDF/A en documentos con valor archivístico | PDF/A verificable |
| `RNF-UX-002` | WCAG 2.2 AA y EN 301 549 | PDF etiquetado; el objetivo razonable es PDF/UA-1 (`OPEN-PDF-03`) |
| `INV-012` | PDF en lote, siempre en cola | Un trabajo por documento |
| `REQ-SEED` (`PLAN 1.15b`) | Centros de 300 a 1.200 alumnos | Lote de 1.200 boletines por evaluación |

**No está en los requisitos y este ADR no lo inventa**: qué documentos concretos tienen «valor archivístico», un tiempo máximo para el lote, un PDF único con todos los boletines de un grupo, ni tipografías subidas por el centro para los PDF. Van como `OPEN-PDF-*` en `§11`.

### 1.3 Restricciones que pesan en la decisión

1. **Una sola persona mantiene esto.** Cada pieza nueva es una pieza que parchear durante tres años.
2. **El contenido son datos personales de menores**, y parte de la plantilla la configura el centro. El motor procesa entrada que no es del todo de confianza.
3. **Desarrollo en WSL2 con 10-11 GB** (`ADR-030`) y un *staging* que se quiere barato (`ADR-080`, en redacción): el consumo en reposo del servicio importa.
4. **Reversibilidad**: el motor debe poder cambiarse sin reescribir las plantillas de todos los módulos.

---

## 2 · Opciones evaluadas

Datos de mantenimiento, licencia y lanzamientos consultados el **2026-11-12** con la API de GitHub (`gh api repos/<repo>` y `/releases`) y la documentación oficial de cada proyecto (lista completa en `§13`).

### 2.1 Comprobación de `CLAUDE.md §1` (mantenimiento, licencia, frecuencia)

| | A · Gotenberg (Chromium) | B · WeasyPrint | C · Typst |
|---|---|---|---|
| Qué es | Servicio HTTP en Go que dirige Chromium sin cabeza; imagen oficial | Biblioteca y CLI en Python que maqueta HTML y CSS sin navegador | Compilador de un lenguaje de marcado propio (no HTML); binario único |
| Licencia | MIT. Su motor PDF interno nuevo, `litho`, es AGPL-3.0, pero Gotenberg lo distribuye bajo MIT como titular (README de `litho`) | BSD-3-Clause | Apache-2.0 |
| Última versión | `v9.4.0` (2026-11-10) | `v70.0` (2026-09-08), **corrección de seguridad** (CVE-2026-55073) | `v0.15.1` (2026-07-17), sigue en 0.x |
| Frecuencia | 1-3 al mes en la serie 8; **`v9.0.0` salió el 2026-11-05 y lleva 5 versiones en 6 días** | 4-5 al año (65.0 en 2025-03 … 70.0 en 2026-09) | 2-3 menores al año, con cambios incompatibles entre menores (sin verificar caso a caso) |
| Actividad | 364 commits en 3 meses | 127 commits en 3 meses | 131 commits en 3 meses |
| Concentración | Un mantenedor (~1.000 commits; el siguiente humano, 5) | Dos mantenedores principales (4.434 y 1.311 commits) y una empresa detrás (CourtBouillon) | Empresa (Typst GmbH), varios autores |
| Empaquetado | Imagen oficial; variante `-chromium` de **463 MB comprimida** (Docker Hub, `9.4.0-chromium`, amd64) | **Sin imagen oficial** ni servicio HTTP: hay que construir imagen y envoltorio | Binario; imagen oficial sin verificar |
| PDF/A | Posterior al render: etapa `remediate` de `litho` (`v0.0.10`). «Chromium no longer makes PDF/A on its own» (guía de migración a 9) | Nativo al escribir: `pdf/a-1b` … `pdf/a-4f` | Nativo: A-1b … A-4e |
| PDF/UA | Etiquetado nativo de Chromium (`nativeTaggedPdf`) y `remediate` a PDF/UA-1 | Nativo: `pdf/ua-1`, `pdf/ua-2`; «the result is not guaranteed» (doc.) | Nativo: PDF/UA-1; etiquetado por defecto |
| CSS paginado | El del navegador; cabecera y pie como plantillas aparte | Paged Media nivel 3: cajas de margen, elementos en marcha, contadores de página | No aplica (no es CSS) |
| JavaScript | Sí, activo por defecto (`--chromium-disable-javascript=false`) | No hay motor de JavaScript (no lo afirma la página consultada; a confirmar en la prueba de `§5.4`) | No aplica |

### 2.2 Evaluación contra los cuatro criterios

**A · Gotenberg con Chromium**

- *Coste en solitario*: el más bajo. Imagen oficial, API HTTP por formulario, ningún código de servicio propio.
- *Mantenimiento a 3 años*: el más alto. Chromium recibe parches de seguridad continuos y la imagen hay que subirla casi cada mes. La versión 9 tiene **una semana**, sustituye cuatro herramientas maduras (pdfcpu, QPDF, pdftk, ExifTool) por un motor propio en `v0.0.10`, y la conformidad PDF/A y PDF/UA depende justo de esa pieza. El soporte de la serie 8 no está declarado. `go.mod` fija `chromedp` a una versión antigua por un fallo abierto (#1535). Un solo mantenedor.
- *Invariantes y seguridad*: es un navegador completo con JavaScript procesando HTML que en parte configura el centro. Las protecciones existen pero **vienen desactivadas**: `--chromium-deny-private-ips=false`, *webhook* y `downloadFrom` activos, sin autenticación, sin límite de cuerpo. Hay que endurecer por configuración y vigilar que cada versión no lo cambie.
- *Reversibilidad*: buena hacia B si las plantillas no usan JavaScript.

**B · WeasyPrint en servicio propio**

- *Coste en solitario*: medio. Hay que escribir y mantener un `Containerfile` y un envoltorio HTTP de un solo *endpoint* (`§3.2`). Es **código en un lenguaje nuevo para el proyecto (Python)**: es el coste real de esta opción y por eso va como decisión del usuario (`OPEN-PDF-02`).
- *Mantenimiento a 3 años*: bajo-medio. 4-5 versiones al año, sin navegador que parchear. Dependencia de sistema: Pango ≥ 1.44 (doc. oficial).
- *Invariantes y seguridad*: la superficie más pequeña de las tres opciones HTML. Toda carga de recursos pasa por un `url_fetcher` sustituible: se puede denegar **todo** lo que no venga en la propia petición (`§6`). La documentación oficial admite sus riesgos (render largo, bucles, valores enormes) y receta lo que `§6` aplica: límites de tiempo y memoria, sin root, contenedor.
- *Conformidad*: PDF/A y PDF/UA se generan al escribir, no se reparan después.
- *Riesgo principal*: **velocidad**. Es Python; la documentación dice «Tables are known to be slow, especially when they are rendered on multiple pages». El boletín es una tabla corta por documento (riesgo bajo); un listado de 1.200 filas es una tabla larga (riesgo real). No está medido: `§5.4` lo convierte en condición de aceptación.
- *Reversibilidad*: buena hacia A (mismo HTML y CSS, otro adaptador).

**C · Typst**

- *Coste en solitario*: medio-alto. Las plantillas se escriben en un lenguaje propio; no se reutiliza Blade ni el sistema de traducción tal cual; el escapado de datos es otro.
- *Mantenimiento a 3 años*: incierto. Sigue en 0.x.
- *Invariantes y seguridad*: la mejor base técnica (binario único, muy rápido, sin red por diseño, PDF/A y PDF/UA nativos y etiquetado por defecto).
- *Reversibilidad*: **la peor**. Salir de Typst es reescribir todas las plantillas. Además contradice «HTML→PDF» de `CLAUDE.md §1`.

### 2.3 Tabla resumen

| Criterio | A · Gotenberg | B · WeasyPrint | C · Typst |
|---|---|---|---|
| Coste de implementación | Bajo | Medio | Medio-alto |
| Mantenimiento a 3 años | Alto (navegador, serie 9 recién nacida) | Bajo-medio (envoltorio propio) | Incierto (0.x) |
| Superficie de ataque | Grande | Pequeña | Mínima |
| PDF/A y PDF/UA | Reparación posterior, motor `v0.0.x` | Nativos | Nativos |
| Velocidad en lote | Buena (sin medir) | **Riesgo** (sin medir) | Muy buena (sin medir) |
| Coste de revertir | Bajo (a B) | Bajo (a A) | Alto |
| Encaja con `ADR-007` y `CLAUDE.md §1` | Sí | Sí | No sin enmienda |

---

## 3 · Decisión propuesta

### 3.1 Motor: WeasyPrint (opción B), con Gotenberg como vuelta atrás documentada

Se propone **WeasyPrint ≥ 70.0** (la 70.0 es corrección de seguridad; ninguna anterior es admisible) por este argumento, en orden de peso:

1. **Lo que se genera son documentos, no páginas web.** Boletines, certificados y listados necesitan paginación, cabeceras repetidas, numeración y conformidad PDF/A y PDF/UA. WeasyPrint hace eso de forma nativa; con Chromium, la conformidad depende hoy de un motor de reparación de una semana de vida.
2. **Superficie de ataque.** Con datos de menores y plantillas que toca el centro, un maquetador sin JavaScript cuyo acceso a recursos se cierra con una función es más defendible que un navegador que hay que endurecer con una docena de opciones.
3. **Ritmo de mantenimiento asumible por una persona**: 4-5 versiones al año frente al goteo de Chromium.
4. **Es reversible**: la regla de plantillas de `§3.3` mantiene abierta la opción A sin tocar los módulos.

Contra este argumento, y dicho sin suavizar: **B obliga a mantener código propio en Python** y **su velocidad no está medida**. Si el usuario no acepta lo primero (`OPEN-PDF-02`) o la prueba de `§5.4` falla, la decisión pasa a **A**, con el endurecimiento de `§6.3`, fijando la serie que en ese momento tenga soporte declarado.

### 3.2 Empaquetado: imagen propia y envoltorio HTTP de un solo *endpoint*

Opciones reales de empaquetado para B:

| Opción | Ventaja | Riesgo | Veredicto |
|---|---|---|---|
| **B1 · Servicio `pdf` propio**: imagen construida en `infra/containers/pdf/`, envoltorio HTTP mínimo | Aislado de la aplicación: sin credenciales, sin red de salida, límites propios. Cumple `ADR-007` | Código Python propio (objetivo: un fichero de aplicación WSGI sobre WeasyPrint y un servidor WSGI mínimo, `§3.2.1`) | **Recomendada** |
| B2 · WeasyPrint dentro de la imagen de `worker`, invocado por CLI | Sin servicio ni envoltorio | El maquetador correría con las credenciales de base de datos y de S3 y con la red del *worker*: un fallo de lectura de ficheros o de SSRF sería grave. Contradice `ADR-007` (servicio contenerizado). Mezcla Python en la imagen PHP | Descartada por seguridad |
| B3 · Imagen de terceros con WeasyPrint | Sin código propio | La documentación oficial solo cita imágenes de un particular; dependencia sin garantías | Descartada |

Contrato del servicio (se concreta en la especificación de la entrega, no aquí):

- `POST /render`: recibe el HTML, los recursos con nombre (hoja de estilo, imágenes) y el perfil de conformidad; devuelve los bytes del PDF o un error tipado. **No recibe URL**: recibe contenido.
- `GET /healthz`.
- Sin estado, sin disco persistente, sin caché entre peticiones (`INV-001`: nada de un centro sobrevive a su petición).
- Imagen fijada por versión **y digest**, dependencias de Python con *hashes*, fuentes dentro de la imagen (`§7.4`). Renovate la sigue como al resto.

#### 3.2.1 Servidor WSGI del envoltorio (decidido por el usuario el 2026-11-13, revisión C2-11)

El envoltorio es una aplicación WSGI de un fichero; quien escucha en el puerto, limita, mata y recicla es un **servidor WSGI mínimo, dependencia justificada** según `CLAUDE.md §1`. El código propio no reimplementa ninguno de esos controles. La dependencia queda envuelta tras la interfaz propia del servicio: el contrato HTTP de `§3.2` (`POST /render`, `GET /healthz`) y, del lado de la aplicación, `PdfRenderer` (`§4`). Cambiar de servidor no toca ningún módulo ni el adaptador PHP.

**Descartado `http.server` de la biblioteca estándar**: su documentación oficial dice «`http.server` is not recommended for production. It only implements basic security checks» (`docs.python.org/3/library/http.server.html`, consultado el 2026-11-13). Además obligaría a escribir a mano el tiempo máximo, la muerte del proceso y el reciclado.

Opciones reales (datos de la API de GitHub y de la documentación de cada proyecto, consultados el **2026-11-13**; la versión concreta **no se fija aquí**, la fija la entrega y la sigue Renovate):

| | **Gunicorn** (`benoitc/gunicorn`) | Waitress (`Pylons/waitress`) | Granian (`emmett-framework/granian`) |
|---|---|---|---|
| Licencia | MIT (fichero `LICENSE` del repositorio; la API de GitHub no la clasifica: `NOASSERTION`) | ZPL 2.1 (fichero `LICENSE.txt`) | BSD-3-Clause |
| Lanzamientos | 25.3.0 (2026-03), 26.0.0 (2026-05), 26.1.0 y 26.2.x (2026-08/09), 27.0.0 (2026-11-10) y 27.0.1 (2026-11-12) | Última 3.0.2 (2024-11-16); ninguna en casi dos años | 2.8.x, de una a tres al mes (2.8.8 el 2026-11-11) |
| Actividad | ≥ 100 commits desde 2026-08-13 (tope de la consulta) | 8 commits desde 2026-08-13 | 51 commits desde 2026-08-13 |
| Muerte por tiempo | `timeout`: el maestro mata y rehace al *worker* silencioso; con *workers* `sync` acota la duración de cada petición | No mata la petición (hilos) | `workers-kill-timeout` es para el apagado, no por petición (README) |
| Reciclado | `max_requests` + `max_requests_jitter` | No | `workers-lifetime` (por tiempo, ≥ 60 s) y `workers-max-rss` |
| Límite de tamaño | Solo línea y cabeceras (`limit_request_line`, `limit_request_fields`, `limit_request_field_size`); **no hay límite del cuerpo** | `max_request_body_size` (sin verificar en la documentación de la versión vigente) | Sin verificar |

**Recomendación: Gunicorn con *workers* `sync`**. Es el único de los tres que da de serie la muerte por tiempo de un render y el reciclado por número de renders, que son los dos controles del riesgo que la documentación de WeasyPrint reconoce (render largo o bucle, memoria que crece). Waitress publica poco y no mata ni recicla; Granian se descarta por no matar por petición. Configuración que la entrega concreta (valores propuestos, a ajustar con la medida de `§5.4`): `workers` 2 (la concurrencia fija de `§5.3`), `timeout` 60 (el umbral de `OPEN-PDF-04`), `max_requests` con *jitter* para reciclar cada N renders, `preload_app` para importar WeasyPrint una vez antes de crear los procesos, y los tres `limit_request_*`.

**Lo que no cumple sin código propio, dicho sin suavizar**: Gunicorn no limita el tamaño del cuerpo (referencia de opciones, `docs/content/reference/settings.md` del repositorio, consultada el 2026-11-13). El límite de tamaño queda en dos sitios: (a) `HttpPdfRenderer` rechaza antes de enviar un documento por encima del máximo (lado PHP, es donde se monta la petición), y (b) el envoltorio responde `413` si `Content-Length` falta o supera el máximo, **antes de leer el cuerpo**. Es una comprobación de una línea, la única que se escribe a mano; la segunda barrera es el límite de memoria del contenedor (`§6.2`, nº 5). Si se quisiera cero código propio en este punto, la alternativa es un proxy delante del servicio, que es una pieza más: no compensa.

**Sin verificar**: compatibilidad de Gunicorn 27 con la versión de Python de la imagen y cambios incompatibles entre 26 y 27 (la 27.0.0 tiene tres días a 2026-11-13); concentración de mantenedores de Gunicorn; el valor por defecto y la semántica exacta de `max_request_body_size` de Waitress y si Granian tiene un límite de cuerpo.

### 3.3 Regla de plantillas (la que hace reversible la decisión)

Toda plantilla de PDF de cualquier módulo cumple:

1. **HTML y CSS, sin JavaScript.** Ni `<script>`, ni manejadores, ni gráficos generados en cliente; un gráfico se entrega como SVG o imagen ya calculados en servidor.
2. **Ningún recurso externo.** Hojas de estilo, logotipos e imágenes viajan en la petición y se referencian por nombre; nunca `http(s):`, `file:` ni `@import` remoto.
3. **CSS del subconjunto común** a WeasyPrint y a un navegador: flujo en bloque, tablas, flexbox simple, `@page` con tamaño y márgenes. Lo específico de Paged Media (cajas de margen, elementos en marcha) se confina en una hoja base compartida, que es lo único que habría que rehacer al cambiar de motor.
4. **Estructura semántica obligatoria**: `<html lang>`, `<title>`, encabezados en orden, tablas con `<th scope>`, texto alternativo en toda imagen con significado (`§7.1`).
5. Medidas en `mm` o `pt`; tamaño de página declarado.

---

## 4 · Interfaz propia (`RNF-MANT-007`)

Ningún módulo conoce el motor. El contrato vive en `App\Support\Pdf` (mismo criterio que `App\Support\Files`, `ADR-076`): es infraestructura transversal, no un módulo, así que usarlo no rompe `INV-007`.

Forma del contrato (se describe, no se implementa en este ADR):

| Pieza | Papel |
|---|---|
| `PdfRenderer` | Interfaz única: recibe un `PdfDocument` y devuelve un `RenderedPdf`, o lanza una excepción tipada |
| `PdfDocument` | Objeto de valor inmutable: HTML ya resuelto, recursos por nombre (bytes y tipo), idioma (`es-ES`, `en`, `de`, `fr`), título y **perfil de conformidad** |
| `PdfProfile` | Enumerado cerrado propio, no las cadenas del motor: `accessible` (etiquetado, objetivo PDF/UA-1) y `archival` (PDF/A; nivel exacto en `OPEN-PDF-03`) |
| `RenderedPdf` | Bytes, número de páginas, perfil aplicado y huella SHA-256 |
| Excepciones | `PdfRenderingFailed` (el motor rechazó la entrada: no se reintenta), `PdfRendererUnavailable` (servicio caído o tiempo agotado: se reintenta), `PdfResourceMissing` (la plantilla cita un recurso que no viaja en la petición) |
| `HttpPdfRenderer` | Único adaptador: habla con el servicio `pdf` por el cliente HTTP de Laravel. No añade ninguna dependencia a `composer.json` |
| `FakePdfRenderer` | Doble para Pest: registra los documentos pedidos y devuelve un PDF mínimo válido. La suite no levanta el contenedor |

Reglas del contrato:

1. **El HTML llega resuelto.** Traducción (`INV-009`), formato de fechas e importes, escapado de datos y marca del centro se resuelven en la aplicación antes de llamar. El motor no conoce centros, idiomas ni permisos.
2. **El contrato no escribe en el almacén.** Devuelve bytes; quien llama los guarda por `PrivateObjectStore` (`ADR-076`). Así el servicio `pdf` no necesita credenciales de nada.
3. **El contrato no audita ni autoriza.** `INV-002` e `INV-003` son del módulo que genera y del *endpoint* que descarga.
4. **Test de conformidad** (precedente `ADR-076 §5`): un trabajo de CI aparte levanta el servicio real y comprueba, con plantillas de referencia en los cuatro idiomas, que el resultado es un PDF, que las fuentes están incrustadas, que un recurso externo citado **no** se descarga y que el perfil pedido se cumple (`OPEN-PDF-08` decide con qué validador).

Lo que **no** entra en el contrato: unir, dividir, sellar o firmar PDF. Si un módulo lo necesitara, es otra decisión (`§8`, `§10`).

---

## 5 · Cola y volumen (`INV-012`)

### 5.1 Regla

**Ningún PDF se genera en la petición HTTP**, ni siquiera uno solo: el servicio puede estar reiniciándose y el tiempo de un render no está acotado por diseño. El *endpoint* crea el registro del documento en estado pendiente, encola y responde; el cliente consulta el estado y, cuando está listo, obtiene una URL firmada de caducidad corta. Es el patrón que ya usa `data_exports` (`REQ-CORE/funcional.md §4.6`).

### 5.2 Un trabajo por documento

- Un lote de boletines es **N trabajos de un documento**, agrupados con `Bus::batch` (la tabla `job_batches` ya existe) para dar progreso. Nunca un trabajo que genere 1.200.
- Motivo: cabe en el `--timeout=120` del `worker` actual, un fallo afecta a un alumno y no al lote, y el reintento es barato.
- **Idempotencia**: cada trabajo se identifica por el registro del documento; si ya tiene objeto y huella, termina sin regenerar. El objeto se escribe una vez y el registro se actualiza en la misma transacción lógica (primero el objeto, después la fila; un objeto huérfano lo limpia la purga, no al revés).
- **Aislamiento** (`INV-001`): el trabajo lleva el centro y lo fija en `TenantContext` antes de leer nada (skill `aislamiento-tenant`). El idioma se fija **explícitamente** con el del destinatario; en un *worker* no hay petición de la que heredarlo.
- **Compatibilidad entre versiones** (`CLAUDE.md §9`): la carga del trabajo son identificadores, no HTML ni datos del alumno; un trabajo encolado por la versión anterior se resuelve con la plantilla vigente.

### 5.3 Dónde se consumen

Opciones reales:

| Opción | Ventaja | Riesgo | Coste de revertir |
|---|---|---|---|
| **Q1 · Cola propia por módulo (p. ej. `calif-pdf`) en el `worker` actual, al final de la lista de prioridad** | Ningún proceso nuevo. `queue:work` atiende las colas en orden: el correo y lo demás pasan siempre antes que el lote | Un solo proceso: el lote es en serie | Nulo |
| Q2 · Proceso `worker-pdf` dedicado | Paralelismo y aislamiento del resto | Tercer proceso que desplegar, vigilar y documentar; más memoria en WSL2 | Bajo |
| Q3 · Cola `long` | Ya existe | `--tries=1` y `retry_after` 900 pensados para una copia única, no para 1.200 trabajos cortos; bloquearía el *rollover* | Bajo |

**Recomendación: Q1**, y pasar a Q2 solo con la medida de `§5.4` en la mano (`OPEN-PDF-09`). La cola nueva se añade a la orden del `worker` en `compose.yaml` y en `infra/quadlet/worker.container` y a `core:queue-health`; `QueueWorkerTest` lo exige.

**Q1 procesa en serie** (revisión C2-10): con un solo proceso `worker`, el lote llega al servicio de documento en documento; la concurrencia 2 del servicio solo se usa con Q2. Por eso `§5.4` mide la configuración Q1 real y, aparte, Q2.

**Convivencia con el aviso urgente** (revisión C2-09, en coherencia con `ADR-079 §4.8`): con colas por prioridad, un trabajo de `notify-urgent` espera como mucho a que acabe el documento en curso, y un documento puede tardar hasta 60 s (`OPEN-PDF-04`). El objetivo de `OPEN-079-05` (percentil 95 por debajo de 60 s) **no es garantizable** con el lote en marcha sin medirlo. Regla: la medición de `§5.4` incluye la espera de `notify-urgent` con el lote en curso; si no cumple el objetivo, el primer consumidor de PDF en lote (`1.17`) es disparador de `worker-urgent` (`ADR-079 §4.8`, tercer disparador).

En el servicio `pdf`: concurrencia pequeña y fija (propuesta: 2 renders simultáneos), con **procesos prearrancados que importan WeasyPrint una vez y se reciclan cada N renders** (`max_requests` de `§3.2.1`), no un proceso por render: importar WeasyPrint por documento no cabe en el presupuesto de ~1,5 s por documento que implican 1.200 boletines en 30 min (revisión C2-10). Si los dos procesos están ocupados, la petición espera en la cola de conexiones del servidor; el tiempo máximo del cliente HTTP en `HttpPdfRenderer` la acota y su vencimiento es `PdfRendererUnavailable`, que el trabajo reintenta con espera.

### 5.4 Prueba de aceptación del motor (condición de la decisión)

La velocidad de WeasyPrint **no está medida** y es el riesgo que puede tumbar la opción B. Antes de construir nada sobre el motor, el primer tramo de la entrega mide, con datos de `REQ-SEED`:

1. 1.200 boletines de una o dos páginas **con la configuración Q1 real** (un proceso `worker` consumiendo la cola, servicio con procesos prearrancados y reciclado de `§3.2.1`): tiempo total, tiempo por documento (mediana y percentil 95) y memoria máxima del servicio. **Aparte**, la misma carga con Q2 (dos consumidores), solo para saber qué se gana si Q1 no cumple.
2. Un listado de 1.200 filas en una sola tabla de varias páginas: tiempo y memoria.
3. Consumo en reposo del contenedor.
4. **Espera de `notify-urgent` con el lote en marcha**: se encolan avisos urgentes durante el lote y se mide del encolado al inicio del trabajo (percentil 95), contra el objetivo de `OPEN-079-05` (`§5.3`).

Umbrales **propuestos** (no salen de ningún requisito; los fija el usuario, `OPEN-PDF-04`): lote de 1.200 boletines en ≤ 30 min con un solo `worker`; ningún documento individual por encima de 60 s; memoria del servicio ≤ 512 MB. Si no se cumplen ni ajustando la plantilla (bloques en vez de tablas, que es lo que recomienda la documentación), **se cambia a la opción A antes de escribir más plantillas**, no después.

---

## 6 · Seguridad

### 6.1 Modelo de amenaza

El HTML mezcla tres orígenes: plantilla del proyecto (confiable), datos de personas (no confiables: un nombre puede traer marcado) y configuración del centro (logotipo, pie de página, textos; no confiable). El logotipo puede ser SVG (`RUX-BRAND-001`), y un SVG tiene los mismos riesgos que el HTML según la propia documentación de WeasyPrint. Riesgos: SSRF hacia servicios internos, lectura de ficheros del contenedor, fuga de datos entre centros, agotamiento de recursos y fuga de datos personales por registros.

### 6.2 Controles (opción B)

| # | Control | Cierra |
|---|---|---|
| 1 | **El servicio no carga nada que no venga en la petición.** El `url_fetcher` se sustituye por uno que resuelve solo los nombres de recurso recibidos y rechaza todo lo demás (`http`, `https`, `ftp`, `file`, redirecciones). Un recurso ausente es un error, no una descarga | SSRF, lectura de ficheros |
| 2 | **Sin red de salida.** El contenedor `pdf` vive en una red interna sin puerta de enlace y no publica puerto; **solo lo alcanza `worker`** (y `worker-pdf` si se pasa a Q2): `api` no se une a esa red porque ningún PDF se genera en la petición (`§5.1`; revisión C2-13). Es el control principal; el nº 1 es la segunda barrera. Encaje con `ADR-028` (red externa, sin IP a mano) y con `ADR-080`: `§12` | SSRF aunque falle el nº 1 |
| 3 | **Sin secretos.** Ni variables de base de datos, ni de S3, ni clave de aplicación. No monta volúmenes | Robo de credenciales |
| 4 | **Contenedor mínimo**: usuario sin privilegios, sistema de ficheros de solo lectura con `tmpfs`, todas las *capabilities* retiradas, `no-new-privileges`, SELinux en `enforcing` | Escalada |
| 5 | **Límites**: tamaño máximo de petición, tiempo máximo por render con muerte del proceso, límite de memoria del contenedor, reciclado de procesos. Los da el servidor WSGI y el contenedor (`§3.2.1`), salvo el tamaño del cuerpo, que se comprueba en `HttpPdfRenderer` y con `Content-Length` en el envoltorio | Render infinito, valores CSS enormes (riesgos que la documentación oficial reconoce) |
| 6 | **Sin estado entre peticiones**: nada en disco ni en memoria sobrevive a un render | Fuga entre centros (`INV-001`) |
| 7 | **Registros sin contenido**: solo identificador de petición, tamaño, páginas, duración y código de error. Nunca HTML, nombres ni rutas de recursos | Datos de menores en registros |
| 8 | **Escapado en origen**: todo dato va por la salida escapada de Blade; la salida sin escapar queda prohibida en plantillas de PDF salvo sobre contenido generado por el propio servidor. El texto configurable del centro se trata como texto plano (precedente `ADR-070`) hasta que `OPEN-PDF-07` diga otra cosa | Inyección de marcado |
| 9 | **Versión mínima 70.0** (CVE-2026-55073: imágenes EPS y sustitución del `url_fetcher`) y escaneo de dependencias de la imagen en cada PR (`CLAUDE.md §8`): Trivy de `.github/workflows/dependency-scan.yml` se extiende a `infra/containers/pdf/` (requisitos con *hashes* e imagen construida), sin herramienta nueva (`§12`) | Fallos conocidos, cadena de suministro |
| 10 | **Descarga**: el PDF sale siempre por URL firmada de caducidad corta, tras comprobar permiso en el *endpoint*; la lectura de documentos con datos de categoría especial se audita (skill `auditoria`) | `INV-002`, `INV-003`, `CLAUDE.md §8` |

**No se añade autenticación entre `worker` y `pdf`** (ni *token* ni mTLS): en una red interna sin salida, quien alcanza el servicio ya es un contenedor propio, y un secreto más es otra pieza que rotar. Si `ADR-080` cambiara el modelo de red (p. ej. un servicio compartido entre entornos), esta decisión se revisa.

### 6.3 Si se acaba en la opción A

Lo anterior se mantiene (2 a 8 y 10) y además, como mínimo: JavaScript desactivado, *webhook* y `downloadFrom` desactivados, denegación de IP privadas y públicas en Chromium, límite de cuerpo y de cola, y contexto de navegador no compartido. Los nombres exactos de las opciones se toman de la documentación de la versión que se fije, no de este ADR: en la serie 9 varias protecciones vienen desactivadas por defecto (`§2.2`).

## 7 · Accesibilidad, archivado, idiomas y tipografías

### 7.1 Accesibilidad del PDF

`RNF-UX-002` exige WCAG 2.2 AA y EN 301 549, y un boletín descargado es parte del producto. Se propone:

1. **Todo PDF se genera etiquetado**, sin excepción y sin opción de desactivarlo. Es la base sin la cual un lector de pantalla no puede leer el documento.
2. **El objetivo de conformidad es PDF/UA-1** para los documentos dirigidos a familias, alumnos y personal (`OPEN-PDF-03` fija el alcance exacto). No PDF/UA-2: exige PDF 2.0 y el soporte en lectores es menor (sin verificar el estado actual de los lectores).
3. **La accesibilidad la pone la plantilla, no el motor.** La documentación de WeasyPrint lo dice así: la condición principal es un HTML correcto, con `<title>` y `lang`. De ahí la regla 4 de `§3.3`, que se comprueba en el test de conformidad y en la revisión de cada plantilla.
4. **El contraste de la marca del centro** ya lo valida `RUX-BRAND-006`; la plantilla usa los mismos colores validados y no introduce otros.
5. **Límite honesto**: el motor declara que el resultado «is not guaranteed». Pasar un validador automático no equivale a ser accesible; la primera plantilla (boletín) se revisa además con un lector de pantalla antes de cerrar `1.17`. No se declara conformidad PDF/UA en ningún documento público del producto hasta tener esa revisión.

### 7.2 Archivado (PDF/A)

`RNF-COMP-004` pide PDF/A «para documentos con valor archivístico» y no dice cuáles. Opciones reales:

| Opción | Ventaja | Riesgo | Coste de revertir |
|---|---|---|---|
| **P1 · Dos perfiles: `archival` para documentos emitidos (boletines, informes, certificados, actas, nóminas) y `accessible` para listados y exportaciones de trabajo** | Cumple el requisito donde aplica; los listados no cargan con las restricciones de PDF/A | Hay que clasificar cada tipo de documento (lo hace cada módulo en su especificación) | Bajo: cambiar el perfil de un tipo afecta solo a lo que se genere después |
| P2 · Todo en PDF/A | Una sola regla | Restricciones innecesarias en documentos efímeros; si PDF/A y PDF/UA no pudieran combinarse en un fichero, se perdería accesibilidad en todos | Bajo |
| P3 · PDF/A solo cuando un módulo lo pida | Mínimo trabajo | Incumple `RNF-COMP-004` por omisión | Alto: los ya emitidos no se convierten solos |

**Recomendación: P1.** Nivel propuesto para `archival`: **PDF/A-2a o PDF/A-3a** (las variantes «a» exigen etiquetado, con lo que archivo y accesibilidad no compiten). La documentación de WeasyPrint recomienda la parte 3 cuando es posible; la parte 4 se descarta porque exige PDF 2.0 y es incompatible con PDF/UA-1 (documentación de Typst, que lo explica con precisión). **Sin verificar**: que WeasyPrint pueda declarar a la vez PDF/A-«a» y PDF/UA-1 en un mismo fichero (su opción `pdf_variant` admite un solo valor según la referencia consultada). Se comprueba en la prueba de `§5.4`; si no se puede, `archival` es PDF/A-«a» etiquetado sin la declaración PDF/UA, y se anota.

Consecuencias de PDF/A que afectan a las plantillas: fuentes siempre incrustadas, sin transparencias en la parte 1 (motivo para no usarla), perfil de color declarado, sin contenido externo ni JavaScript (ya prohibidos por `§3.3`).

**Un documento emitido no se regenera**: es un objeto inmutable con su huella. Si cambian los datos, se emite otro. Es condición para firmarlo (`§8`) y para que el archivo tenga sentido. Retención y supresión: catálogo de `ADR-077` (`OPEN-PDF-10`).

### 7.3 Cuatro idiomas

- El motor es agnóstico: recibe HTML ya traducido. El idioma del documento es el **del destinatario** (`ADR-021`, `PLAN 1.17`), fijado en el trabajo (`§5.2`), y se declara en `<html lang>`: lo necesitan el lector de pantalla y la partición de palabras.
- Todos los literales de plantilla salen del sistema de traducción (`INV-009`), con paridad de claves en los cuatro idiomas. Los textos legales de un documento no se traducen automáticamente (`CLAUDE.md §2`, punto 9).
- El contenido del centro traducible se resuelve en servidor con su cadena de reserva (`ADR-062`) antes de llamar al motor.
- Un boletín para dos tutores con idiomas distintos son **dos documentos**, cada uno con su registro. Si eso es lo que se quiere lo decide la especificación de `1.17` (B5), no este ADR.
- Partición automática (`hyphens: auto`): WeasyPrint la soporta con `lang` y los diccionarios de Pyphen; **sin verificar** la calidad en alemán, que es donde más importa por la longitud de las palabras. Entra en el test de conformidad.
- Los cuatro idiomas usan alfabeto latino: una sola familia tipográfica con cobertura latina extendida basta. Las lenguas cooficiales previstas (`ca`, `eu`, `gl`) tampoco cambian esto.

### 7.4 Tipografías

La pila del sistema de la SPA no existe dentro del contenedor, y PDF/A obliga a incrustar la fuente. Opciones reales:

| Opción | Ventaja | Riesgo | Coste de revertir |
|---|---|---|---|
| **T1 · Una familia de licencia abierta dentro de la imagen del servicio, igual para todos los centros** | Resultado idéntico en desarrollo, CI y producción; licencia comprobada una vez; ficheros pequeños por subconjunto | El PDF no usa la «tipografía» de la marca del centro, si la tuviera | Bajo: cambiar la familia es reconstruir la imagen; los ya emitidos no cambian |
| T2 · Catálogo cerrado de 3-4 familias en la imagen, elegible por centro | Algo de marca | Cada familia multiplica las pruebas de maquetación por plantilla e idioma | Medio |
| T3 · Fuentes subidas por el centro | Marca completa | Ficheros no confiables procesados por el motor, licencias que no controlamos, maquetaciones rotas | Alto |

**Recomendación: T1** para la primera entrega; T2 solo si un centro lo pide; T3 no. La familia concreta **no se fija en este ADR**: su licencia (debe permitir incrustación y redistribución en la imagen, tipo SIL OFL) y su cobertura se comprueban al elegirla, con fuente y fecha (`OPEN-PDF-06`). La línea 1058 de los requisitos habla de «tipografía» dentro del *branding*; si eso alcanza a los PDF no está dicho, y por eso es pregunta abierta y no decisión.

---

## 8 · Firma (PAdES) y relación con `ADR-082`

`ADR-082` está en redacción (esqueleto sin contenido a 2026-11-12). Este ADR fija solo lo que es del motor y deja lo demás allí.

**Lo que este ADR decide:**

1. **El motor no firma.** `ADR-012` manda integrar un prestador cualificado y no desarrollar criptografía propia. El servicio `pdf` no recibe claves ni certificados, y `PdfRenderer` no tiene operación de firma. (Gotenberg 9 anuncia firma y sellado de tiempo en su motor interno; no es motivo para elegirlo: custodiar la clave sería nuestro, que es justo lo que `ADR-012` evita.)
2. **Orden fijo**: maquetar con el perfil definitivo → guardar el objeto y su huella → firmar por el contrato que defina `ADR-082` → guardar el PDF firmado como **objeto nuevo**, sin sobrescribir el original.
3. **Un PDF firmado no se vuelve a procesar.** Ni se regenera, ni se optimiza, ni se le añade nada: cualquier reescritura invalida la firma. PAdES añade la firma como actualización incremental; de eso se encarga el firmante, no nosotros.
4. **La «firma del tutor» como imagen** en el cuerpo del boletín (`REQ-CALIF-002`, «personalización») es contenido de plantilla, un recurso más de la petición. No es una firma electrónica y la plantilla no debe presentarla como tal.

**Lo que queda para `ADR-082` (`OPEN-PDF-05`):**

- Qué nivel de firma lleva cada documento y quién firma (el tutor, el centro con sello, ambos).
- Si la firma es visible y, en ese caso, si la plantilla debe reservar un hueco o un campo de firma en una posición conocida. **Sin verificar**: que WeasyPrint pueda crear un campo de firma vacío; si `ADR-082` lo exigiera y no pudiera, el hueco se reserva como zona en blanco y el campo lo crea el firmante.
- Compatibilidad entre el nivel de PDF/A elegido y el perfil PAdES del prestador (sellos de tiempo y validación a largo plazo). **Sin verificar**; es condición que `ADR-082` debe pedir al prestador, y puede obligar a ajustar el nivel de `§7.2`.
- Custodia de evidencias y verificación pública por código seguro (`REQ-SEC-001`): no son del motor; el código o el QR que se imprima en el documento es un dato más de la plantilla.

Consecuencia para el plan: `1.17` puede entregar boletines **sin firma electrónica** con este ADR aceptado; la firma entra cuando `ADR-082` esté aceptado. `1.17b` ya excluye la firma en su propio enunciado.

## 9 · Consecuencias, riesgos y reversión

### 9.1 Consecuencias

- **Un servicio nuevo** (`pdf`) en `compose.yaml` (perfil `full`, no en el reducido de `ADR-030`) y una unidad Quadlet, con `Wants=` + `After=` desde `worker` y nunca `Requires=` (`ADR-028`): si `pdf` cae, el `worker` sigue y los trabajos de PDF reintentan.
- **Python entra en el repositorio**, confinado a `infra/containers/pdf/`: un envoltorio de un fichero, su `Containerfile` y sus requisitos con *hashes* (WeasyPrint y el servidor WSGI de `§3.2.1`, dependencia justificada). No entra en `apps/`. Necesita su propia comprobación en CI (construcción de la imagen y test de conformidad); no se añade una cadena de herramientas de Python más allá de eso.
- **Ninguna dependencia nueva en `composer.json` ni en `package.json`.**
- **Las plantillas de PDF son Blade propias**, no componentes de la SPA: la fila de `ARCHITECTURE.md` que dice «reutilizando el design system» pasa a significar reutilizar los *tokens* (colores, escala) en una hoja de impresión, no los componentes Vue (`§12`).
- **Entrega propia antes de `1.17`**, de infraestructura, dimensionada para ≤ 3 tramos (`CLAUDE.md §2`, punto 7): (1) imagen, envoltorio y prueba de `§5.4`; (2) contrato, adaptador, doble de pruebas, cola y test de conformidad; (3) hoja base de impresión, plantilla de referencia en cuatro idiomas y documentación. Paso **crítico** a efectos de revisión (servicio nuevo que procesa datos de menores): `security-reviewer` tras Codex.
- Desbloquea, cada una como entrega pequeña posterior: boletines (`1.17`), PDF de auditoría (`REQ-CORE-005`), horarios (`OPEN-ACAD-38`) y listados (`OPEN-ALUM-15`). Este ADR no las ordena (`OPEN-PDF-11`).

### 9.2 Riesgos

| Riesgo | Probabilidad | Efecto | Mitigación |
|---|---|---|---|
| WeasyPrint demasiado lento para el lote o para tablas largas | Media | Cambio de motor | Prueba de `§5.4` **antes** de escribir plantillas; regla de `§3.3` |
| El envoltorio propio crece (colas internas, caché, autenticación) | Media | Deuda en un lenguaje ajeno al proyecto | Alcance cerrado en `§3.2`: un *endpoint*, sin estado. Cualquier ampliación exige ADR |
| PDF/A-«a» y PDF/UA-1 no combinables en un fichero | Desconocida | `archival` sin declaración PDF/UA | `§7.2`; se sabe en el primer tramo |
| El perfil PAdES del prestador no encaja con el nivel de PDF/A | Desconocida | Reajuste del nivel | `§8`; lo emitido sin firma no se ve afectado |
| Un fallo de seguridad en WeasyPrint o en Pango | Baja-media (ya hubo uno en 2026) | Parche urgente | Sin red y sin secretos: el alcance de un fallo queda en el propio contenedor y en el documento en curso |
| WeasyPrint pierde a sus dos mantenedores | Baja | Motor sin parches | Opción A como salida; mismas plantillas |
| Diferencias de maquetación al cambiar de versión | Media | Documentos que cambian de aspecto | Imagen fijada por digest; el test de conformidad compara número de páginas de las plantillas de referencia |

### 9.3 Reversión

- **Antes de implementar**: no aceptar el ADR; no hay nada que deshacer.
- **De B a A** (el caso probable): nuevo adaptador de `PdfRenderer`, cambio de servicio en `compose.yaml` y Quadlet, rehacer la hoja base de paginación y repasar cada plantilla en los cuatro idiomas. Los módulos no cambian. Los documentos ya emitidos no se tocan: son objetos inmutables.
- **De cualquiera a C**: reescritura de todas las plantillas. Por eso no se recomienda empezar por C ni dejar que el número de plantillas crezca sin haber pasado la prueba de `§5.4`.
- **Retirada del servicio**: se quita el contenedor; los trabajos pendientes fallan con `PdfRendererUnavailable` y quedan en `failed_jobs`, sin pérdida de datos.

---

## 10 · Alternativas descartadas

| Alternativa | Motivo |
|---|---|
| **Gotenberg con Chromium** (opción A) | No se descarta: es la vuelta atrás. No es la primera opción por la superficie de un navegador con datos de menores, por el ritmo de parches y porque la serie 9 (2026-11-05) apoya la conformidad PDF/A y PDF/UA en un motor propio en `v0.0.10` |
| **Typst** (opción C) | Técnicamente el mejor motor de los tres, pero sale del HTML: reversión cara, 0.x, y contradice `CLAUDE.md §1`. Reconsiderable cuando llegue a 1.0, con ADR nuevo |
| **Servicio propio con Playwright o Puppeteer** | Es reimplementar Gotenberg a mano: más código propio que B y la misma superficie que A |
| **dompdf o mPDF dentro del proceso PHP** | Contradicen `ADR-007` (servicio contenerizado); el render correría con las credenciales del `worker`. dompdf es LGPL-2.1 con soporte CSS limitado y sin PDF/UA (sin verificar al detalle); mPDF es GPL-2.0 y su última publicación en GitHub Releases es de 2022 (puede publicar solo por etiquetas: sin verificar) |
| **wkhtmltopdf** | Repositorio archivado; última versión de 2020 |
| **Motores comerciales (Prince, PDFreactor)** | Calidad y conformidad muy altas, pero licencia de pago por servidor (precios sin verificar) y dependencia de un proveedor. La interfaz de `§4` permite adoptarlos si un cliente lo exigiera |
| **PDF generado en el navegador del usuario (hoja de impresión)** | Ya descartado en `OPEN-ACAD-38` (opción B): no es un documento emitido, no es PDF/A ni reproducible, y viola `INV-006` al dejar la lógica en la interfaz |
| **Firmar con el motor interno de Gotenberg 9** | `ADR-012`: sin criptografía ni custodia de claves propias |
| **WeasyPrint dentro de la imagen del `worker`** | `§3.2`, opción B2 |
| **Envoltorio sobre `http.server` de la biblioteca estándar** | Su documentación lo desaconseja en producción y obligaría a escribir a mano tiempo máximo, muerte del proceso y reciclado (`§3.2.1`; decidido por el usuario el 2026-11-13) |
| **Waitress o Granian como servidor WSGI** | Waitress: sin lanzamientos desde 2024-11 y sin muerte ni reciclado por petición. Granian: no mata por petición (`§3.2.1`) |

---

## 11 · Preguntas abiertas

**Decididas por el usuario (2026-11-12)**: `OPEN-PDF-01` (motor: WeasyPrint, con Gotenberg como vuelta atrás) y `OPEN-PDF-02` (se acepta el envoltorio propio mínimo en Python, un endpoint y un fichero, en `infra/containers/pdf/`), ambas según la recomendación de este ADR. La prueba de rendimiento de `§5.4` sigue siendo condición del primer tramo: si no se cumple, rige la vuelta atrás. El ADR sigue en `PROPUESTA` mientras queden abiertas `OPEN-PDF-03` y `-04`, que también bloquean. Las filas siguientes conservan el razonamiento original.

**Decidida por el usuario (2026-11-13), revisión C2-11**: el envoltorio corre sobre un servidor WSGI mínimo como dependencia justificada (`CLAUDE.md §1`), envuelto tras la interfaz propia del servicio, con límites de tamaño, muerte por tiempo y reciclado del servidor y no escritos a mano; descartado `http.server`. La elección concreta (Gunicorn, recomendada) y sus datos están en `§3.2.1`.

**Decididas por el usuario (2026-11-12), según la recomendación**: `OPEN-PDF-04` (umbrales de la prueba de aceptación previa a escribir plantillas: 1.200 boletines en 30 minutos o menos con un worker, ningún documento por encima de 60 s y servicio de 512 MB o menos; si no se cumplen, rige la vuelta atrás a Gotenberg con las mismas plantillas).

Convención: **R** es la recomendación de este ADR. «Bloquea» se refiere a la implementación de la entrega del motor, salvo que se diga otra cosa.

### 11.1 Bloquean

| ID | Pregunta | Opciones | Recomendación e impacto | Decide |
|---|---|---|---|---|
| `OPEN-PDF-01` | ¿Qué motor? | **R: B** WeasyPrint · A Gotenberg con Chromium · C Typst | B (`§3.1`). Con A: menos código propio, más superficie y más parches. Con C: hay que enmendar `CLAUDE.md §1` y aceptar una reversión cara | Usuario |
| `OPEN-PDF-02` | ¿Se acepta mantener un servicio propio mínimo en Python (un *endpoint*, un fichero, `infra/containers/pdf/`)? | **R: Sí** · No | Si es no, `OPEN-PDF-01` pasa a A: B no tiene empaquetado oficial y las otras formas de empaquetarlo se descartan por seguridad (`§3.2`) | Usuario |
| `OPEN-PDF-04` | ¿Qué umbrales hacen aceptable el motor en la prueba de `§5.4`? | **R:** 1.200 boletines en ≤ 30 min con un `worker`, ningún documento > 60 s, servicio ≤ 512 MB · otros valores · sin umbral | Sin umbral, la prueba no decide nada y el riesgo de velocidad se descubre con las plantillas ya escritas | Usuario, con negocio (¿cuánto puede esperar un centro la noche de publicación?) |
| `OPEN-PDF-03` | ¿Qué documentos son `archival` (PDF/A) y a cuáles se les exige PDF/UA-1? | **R: P1** de `§7.2`: `archival` para documentos emitidos, `accessible` para listados; todos etiquetados · P2 todo PDF/A · P3 a demanda | Bloquea la plantilla del boletín (`1.17`), no la construcción del motor. Con P3 se incumple `RNF-COMP-004` | Negocio y legal; el nivel exacto, técnico tras `§5.4` |

### 11.2 No bloquean

| ID | Pregunta | Opciones | Recomendación e impacto | Decide |
|---|---|---|---|---|
| `OPEN-PDF-05` | Firma: nivel, firmante, firma visible, campo reservado y compatibilidad PAdES con PDF/A | Depende de `ADR-082` | **R:** el motor no firma (`§8`); `1.17` entrega sin firma electrónica hasta que `ADR-082` se acepte. Bloquea solo la parte firmada de `REQ-CALIF-002` | `ADR-082` (usuario y legal) |
| `OPEN-PDF-06` | Tipografía de los PDF | **R: T1** una familia de licencia abierta en la imagen · T2 catálogo cerrado · T3 subidas por el centro | T1; la familia se elige comprobando licencia y cobertura. Aclara además si la «tipografía» del *branding* alcanza a los PDF | Usuario |
| `OPEN-PDF-07` | Plantillas «configurables» por centro (`REQ-CALIF-004`, `REQ-SEC-001`): ¿parámetros o HTML libre? | **R:** parámetros estructurados (logotipo, colores, textos como texto plano, bloques que se activan) · HTML libre saneado · editor visual | HTML libre convierte al centro en autor de entrada no confiable y multiplica las pruebas de accesibilidad. Lo concreta la especificación de cada módulo | Usuario, en la especificación de `1.17` |
| `OPEN-PDF-08` | ¿Con qué se valida PDF/A y PDF/UA en CI? | **R:** veraPDF en el trabajo de conformidad, fuera de la suite Pest · solo comprobaciones propias (fuentes, etiquetas, metadatos) · validación manual | veraPDF es una herramienta nueva solo de CI (Java); mantenimiento, licencia e imagen **sin verificar**: se comprueban antes de adoptarla (`CLAUDE.md §1`). Sin validador, la conformidad es una afirmación sin prueba | Técnico; la dependencia la aprueba el usuario |
| `OPEN-PDF-09` | ¿Cola en el `worker` actual o proceso dedicado? | **R: Q1** cola por módulo en `worker`, última en prioridad · Q2 `worker-pdf` · Q3 cola `long` | Q1; se revisa con la medida de `§5.4` | Técnico |
| `OPEN-PDF-10` | Conservación de los PDF generados | **R:** los emitidos se guardan como objetos inmutables con la retención que fije el catálogo de `ADR-077`; las exportaciones de trabajo caducan como `data_exports` (7 días) · regenerar siempre · guardar todo | Regenerar un documento emitido rompe firma y archivo. Los plazos no son de este ADR | Legal y negocio (`ADR-077`) |
| `OPEN-PDF-11` | Orden de las exportaciones pendientes (auditoría, horarios, listados) | **R:** todas después de `1.17`, cada una como entrega pequeña · alguna antes | `1.17` es quien valida el motor con un caso real; adelantar otra reparte el riesgo en dos frentes | Usuario (plan) |
| `OPEN-PDF-12` | ¿El servicio `pdf` entra en el *staging* barato y cómo queda su red interna con un *runtime* agnóstico? | Depende de `ADR-080` | **R:** sí entra, con la misma red sin salida; si el *runtime* no ofreciera red interna, se compensa con reglas de salida y se documenta | `ADR-080` (técnico) |

---

## 12 · Cambios propuestos en ficheros compartidos

No se ha tocado ninguno. Los aplica la sesión orquestadora al integrar, y solo los de la opción que se acepte.

| Fichero | Cambio propuesto |
|---|---|
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` §18 (índice) | Fila `ADR-078`: «Motor de renderizado PDF: WeasyPrint en servicio propio mínimo tras `PdfRenderer`; plantillas HTML y CSS sin JavaScript ni recursos externos; un trabajo por documento en cola; PDF etiquetado, PDF/A para documentos emitidos; el motor no firma (`ADR-082`). Concreta `ADR-007`. **Estado: `PROPUESTA`**». Entrada en el historial de versiones |
| `CLAUDE.md §1`, fila «PDF» | Al aceptar: «**Elegido y no instalado**: WeasyPrint en servicio propio (`ADR-078`); plantillas sin JavaScript ni recursos externos», con el issue que lo siga. Hasta que exista el servicio, la fila debe seguir diciendo que no está instalado |
| `PLAN-IMPLEMENTACION.md` | (a) Paso nuevo de infraestructura **antes de `1.17`**, «Servicio de PDF (`ADR-078`)», en tres tramos (`§9.1`), marcado crítico; el número lo asigna la orquestadora. (b) En `1.17`: «motor según `ADR-078`; firma electrónica según `ADR-082`, no incluida si no está aceptado». (c) En `0.3`: sustituir «motor sin decidir, ver 1.17» por la referencia a `ADR-078` |
| `ARCHITECTURE.md` | Línea 34: «Plantillas HTML propias con los *tokens* del design system, sin JavaScript (`ADR-078`)». Tablas de dimensionado (líneas ~331-362): las cifras del servicio PDF (≈1 GB en picos, 4 GB por nodo) son previas a la decisión; sustituirlas por lo medido en `§5.4`. Añadir `App\Support\Pdf` a la lista de contratos transversales y la red interna del servicio al diagrama |
| `SYSADMIN.md` | Línea 87: retirar «no está definido todavía»; documentar el servicio `pdf` (perfil `full`, límites, sin red de salida, sin secretos), la cola nueva y cómo actualizar la imagen por digest |
| `.github/workflows/dependency-scan.yml` | Trivy cubre ya el repositorio entero (`scan-type: fs`, `scan-ref: "."`, comprobado el 2026-11-13), así que los requisitos de `infra/containers/pdf/` entran solos al existir; falta añadir el escaneo de la **imagen** `pdf` construida (mismo Trivy, `scan-type: image`). Sin herramienta nueva (revisión C2-14) |
| `RUNBOOK.md` | Entradas nuevas: «el servicio `pdf` no responde» (los trabajos reintentan; qué mirar) y «un lote de boletines va lento» |
| `README.md`, `CHANGELOG.md`, `memory.md` | Lo habitual al cerrar la entrega; nada antes |
| `docs/modulos/REQ-CORE/funcional.md §4.6`, `REQ-ACAD` (`OPEN-ACAD-38`), `REQ-ALUM` (`OPEN-ALUM-15`) | No son ficheros compartidos del guion, pero tampoco de este encargo: donde dicen «motor sin decidir», referenciar `ADR-078` cuando se acepte |
| `.claude/skills/colas-y-workers`, `contenedores-y-red` | Añadir la regla «un trabajo por documento, nunca PDF en la petición» y el servicio `pdf` sin red de salida. Requiere aprobación del usuario |
| `docs/modulos/REQ-CALIF/` (agente B5) | B5 debe leer `§3.3`, `§5.2`, `§7` y `§8`: reglas de plantilla, un documento por destinatario e idioma, perfil `archival`, boletín sin firma electrónica hasta `ADR-082` |

**Para `ADR-082` (agente A6)**: este ADR le pide tres cosas (`§8`): que el contrato de firma reciba un PDF ya guardado y devuelva otro objeto; que diga si hace falta campo o hueco de firma visible; y que exija al prestador compatibilidad con el nivel de PDF/A de `§7.2`.

---

## 13 · Fuentes y lo no verificado

### 13.1 Fuentes (consultadas el 2026-11-12 salvo las marcadas 2026-11-13)

| Dato | Fuente |
|---|---|
| Licencia, estado, lanzamientos, commits y contribuyentes de Gotenberg, WeasyPrint, Typst, dompdf, mPDF y wkhtmltopdf | API de GitHub: `repos/<org>/<repo>`, `/releases`, `/contributors`, `/commits?since=2026-08-12` |
| Novedades e incompatibilidades de Gotenberg 9; `litho` y `lok` | Notas de `v9.0.0` y `v9.4.0` (GitHub Releases); `https://gotenberg.dev/docs/getting-started/upgrade` |
| Licencia de `litho` (AGPL-3.0, distribuido bajo MIT dentro de Gotenberg) y su versión (`v0.0.10`) | `LICENSE` y `README` de `gotenberg/litho`; `go.mod` de `gotenberg/gotenberg` |
| Valores por defecto de seguridad y capacidad de Gotenberg | `https://gotenberg.dev/docs/configuration` |
| Tamaño de la imagen `9.4.0-chromium` | API pública de Docker Hub (tamaño comprimido, amd64) |
| WeasyPrint 70.0: corrección de seguridad CVE-2026-55073 | Notas de `v70.0` (GitHub Releases) |
| WeasyPrint: PDF/A, PDF/UA, rendimiento de tablas | `https://doc.courtbouillon.org/weasyprint/stable/common_use_cases.html` |
| WeasyPrint: riesgos de seguridad y mitigaciones, ausencia de imagen oficial, Pango ≥ 1.44 | `https://doc.courtbouillon.org/weasyprint/stable/first_steps.html` |
| WeasyPrint: valores de `pdf_variant`, `url_fetcher`, soporte CSS | `https://doc.courtbouillon.org/weasyprint/stable/api_reference.html` |
| Typst: PDF/A, PDF/UA-1, incompatibilidad de PDF/UA-1 con PDF 2.0 | `https://typst.app/docs/reference/pdf/` (versión 0.15.1) |
| Servidor WSGI: licencias, lanzamientos y actividad de Gunicorn, Waitress y Granian (consultado el **2026-11-13**) | API de GitHub: `repos/<org>/<repo>`, `/releases`, `/commits?since=2026-08-13`; ficheros `LICENSE` |
| Opciones `timeout`, `max_requests`, `max_requests_jitter`, `preload_app` y `limit_request_*` de Gunicorn; ausencia de límite de cuerpo (2026-11-13) | `docs/content/reference/settings.md` de `benoitc/gunicorn` (rama principal) |
| Opciones de *workers* de Granian (2026-11-13) | `README.md` de `emmett-framework/granian` |
| `http.server` desaconsejado en producción (2026-11-13) | `https://docs.python.org/3/library/http.server.html` |
| Trivy en CI (2026-11-13) | `.github/workflows/dependency-scan.yml` del repositorio |
| Estado del repositorio | `grep` sobre `compose.yaml`, `infra/quadlet/`, `apps/api/config/queue.php`, `apps/api/composer.json` y la documentación citada |

### 13.2 Sin verificar

1. **Velocidad y memoria de cualquiera de los tres motores** con nuestras plantillas: no se ha ejecutado nada (este encargo no escribe código). Es el objeto de `§5.4`.
2. Que WeasyPrint **no ejecute JavaScript**: es lo esperable en un maquetador sin navegador, pero ninguna de las páginas consultadas lo afirma.
3. Combinación de **PDF/A-«a» y PDF/UA-1** en un mismo fichero con WeasyPrint.
4. Creación de **campos de firma vacíos** con WeasyPrint y compatibilidad **PAdES con PDF/A** del prestador que elija `ADR-082`.
5. Calidad de la **partición de palabras en alemán** y dependencias de Python transitivas de WeasyPrint (se inventarían al construir la imagen).
6. **Soporte de la serie 8 de Gotenberg** tras la 9: la guía de migración no lo dice.
7. Consumo en reposo de Chromium en Gotenberg y soporte actual de cajas de margen de `@page` en Chromium.
8. **veraPDF** (mantenimiento, licencia, imagen) y la licencia de la familia tipográfica que se elija.
9. Estado de los lectores de PDF respecto a PDF/UA-2 y precios de los motores comerciales.
10. El contenido de `ADR-082` y de `ADR-080`: ambos estaban en redacción al cerrar este documento.
11. Servidor WSGI (`§3.2.1`): compatibilidad de Gunicorn 27 con el Python de la imagen, cambios incompatibles 26→27, concentración de mantenedores; límite de cuerpo de Waitress y de Granian.
12. Que la espera de `notify-urgent` con el lote en marcha cumpla `OPEN-079-05`: se mide en `§5.4`, punto 4.
