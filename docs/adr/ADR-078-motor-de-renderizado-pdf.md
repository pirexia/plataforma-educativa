# ADR-078 · Motor de renderizado PDF: WeasyPrint en un servicio propio mínimo, tras la interfaz `PdfRenderer`, con plantillas HTML y CSS sin JavaScript

**Estado**: **PROPUESTA** (ola 1 de arquitectura, agente A2). La acepta el usuario; hasta entonces no se implementa nada.
**Fecha**: 2026-11-12
**Origen**: el servicio de PDF está «pendiente a propósito» desde `PLAN 0.3` («motor sin decidir, ver 1.17») y bloquea `1.17` (boletines), la exportación PDF de `REQ-CORE-005` (`docs/modulos/REQ-CORE/funcional.md §4.6`), `OPEN-ACAD-38` (horarios) y `OPEN-ALUM-15` (listados).
**Afecta a** (cuando se implemente; este ADR no toca nada de ello): `compose.yaml`, `infra/` (imagen y unidad Quadlet nuevas), `apps/api/app/Support/` (contrato nuevo), `apps/api/config/`, CI, y la documentación de `§12`.
**Concreta sin sustituir**: `ADR-010` («servicio contenerizado de generación de PDF») y la fila «PDF» de `CLAUDE.md §1`.
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
| Encaja con `ADR-010` y `CLAUDE.md §1` | Sí | Sí | No sin enmienda |

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
| **B1 · Servicio `pdf` propio**: imagen construida en `infra/containers/pdf/`, envoltorio HTTP mínimo | Aislado de la aplicación: sin credenciales, sin red de salida, límites propios. Cumple `ADR-010` | Código Python propio (objetivo: un fichero, solo biblioteca estándar más WeasyPrint) | **Recomendada** |
| B2 · WeasyPrint dentro de la imagen de `worker`, invocado por CLI | Sin servicio ni envoltorio | El maquetador correría con las credenciales de base de datos y de S3 y con la red del *worker*: un fallo de lectura de ficheros o de SSRF sería grave. Contradice `ADR-010` (servicio contenerizado). Mezcla Python en la imagen PHP | Descartada por seguridad |
| B3 · Imagen de terceros con WeasyPrint | Sin código propio | La documentación oficial solo cita imágenes de un particular; dependencia sin garantías | Descartada |

Contrato del servicio (se concreta en la especificación de la entrega, no aquí):

- `POST /render`: recibe el HTML, los recursos con nombre (hoja de estilo, imágenes) y el perfil de conformidad; devuelve los bytes del PDF o un error tipado. **No recibe URL**: recibe contenido.
- `GET /healthz`.
- Sin estado, sin disco persistente, sin caché entre peticiones (`INV-001`: nada de un centro sobrevive a su petición).
- Imagen fijada por versión **y digest**, dependencias de Python con *hashes*, fuentes dentro de la imagen (`§7.4`). Renovate la sigue como al resto.

### 3.3 Regla de plantillas (la que hace reversible la decisión)

Toda plantilla de PDF de cualquier módulo cumple:

1. **HTML y CSS, sin JavaScript.** Ni `<script>`, ni manejadores, ni gráficos generados en cliente; un gráfico se entrega como SVG o imagen ya calculados en servidor.
2. **Ningún recurso externo.** Hojas de estilo, logotipos e imágenes viajan en la petición y se referencian por nombre; nunca `http(s):`, `file:` ni `@import` remoto.
3. **CSS del subconjunto común** a WeasyPrint y a un navegador: flujo en bloque, tablas, flexbox simple, `@page` con tamaño y márgenes. Lo específico de Paged Media (cajas de margen, elementos en marcha) se confina en una hoja base compartida, que es lo único que habría que rehacer al cambiar de motor.
4. **Estructura semántica obligatoria**: `<html lang>`, `<title>`, encabezados en orden, tablas con `<th scope>`, texto alternativo en toda imagen con significado (`§7.1`).
5. Medidas en `mm` o `pt`; tamaño de página declarado.

---

## 4 · Interfaz propia (`RNF-MANT-007`)

_(pendiente)_

## 5 · Cola y volumen (`INV-012`)

_(pendiente)_

## 6 · Seguridad

_(pendiente)_

## 7 · Accesibilidad, archivado, idiomas y tipografías

_(pendiente)_

## 8 · Firma (PAdES) y relación con `ADR-082`

_(pendiente)_

## 9 · Consecuencias, riesgos y reversión

_(pendiente)_

## 10 · Alternativas descartadas

_(pendiente)_

## 11 · Preguntas abiertas

_(pendiente)_

## 12 · Cambios propuestos en ficheros compartidos

_(pendiente)_

## 13 · Fuentes y lo no verificado

_(pendiente)_
