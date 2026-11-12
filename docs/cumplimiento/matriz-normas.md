# Expediente de cumplimiento · Matriz de normas

> **Estado**: borrador de arquitectura (agente A7, ola 1). **Fecha de consulta de todas las fuentes: 2026-11-12.**
> **Este documento no declara que la plataforma cumple ninguna norma.** Describe qué dice cada norma, a quién obliga y qué habría que poder demostrar. Toda lectura jurídica lleva la marca **[PVJ]** = *pendiente de validación jurídica* (`OPEN-STO-12`).
> No es asesoramiento legal. Lo redacta un agente de arquitectura, no un abogado.

## 1. Cómo leer la matriz

- **Fuente primaria**: el texto oficial (BOE consolidado, Diario Oficial de la UE). Solo se marca **«verificada»** cuando el texto del precepto se ha leído en esta sesión en la fuente oficial. Si solo se ha comprobado que el documento existe, se dice **«existencia verificada, contenido no leído»**. Lo demás es **«sin verificar»**.
- **Roles** que se dan por supuestos y que **no están decididos** ([PVJ], `OPEN-07`, `OPEN-STO-07`): el **centro** es responsable del tratamiento; la **plataforma** (la entidad jurídica de `OPEN-07`, sin decidir) es encargada; el **proveedor S3** es subencargado.
- **Columnas «plataforma» y «proveedor»**: qué tendría que hacer o acreditar cada uno. Son obligaciones a demostrar, no afirmaciones de que ya se hace.
- **Evidencia**: identificador del [índice de evidencias](indice-evidencias.md). Las evidencias **no se guardan en Git** (repositorio público).

### Cómo se verificó cada fuente

| Fuente | Método | Resultado |
|---|---|---|
| BOE, legislación consolidada | API de datos abiertos del BOE (`boe.es/datosabiertos/api/legislacion-consolidada/id/<ID>/texto/bloque/<bloque>`) y página consolidada | Texto del precepto leído |
| Diario Oficial de la UE | Oficina de Publicaciones (`publications.europa.eu/resource/celex/<CELEX>`, versión en español) | Texto del precepto leído. EUR-Lex devuelve página vacía a clientes automáticos |
| Guías de la AEPD | Petición a la URL del PDF | Responde `200`; **el contenido no se pudo leer** (no hay extractor de PDF en el entorno) |
| Normas ISO | Búsqueda web | **Sin verificar** en `iso.org` |

## 2. Matriz

### 2.1 Protección de datos

| # | Norma y precepto | Fuente primaria | Estado | Qué exige (resumen fiel) | A la plataforma | Al proveedor S3 | Evidencia |
|---|---|---|---|---|---|---|---|
| N-01 | **RGPD**, Reglamento (UE) 2016/679, **art. 28.1** | DOUE L 119, 4.5.2016; CELEX `32016R0679` | Verificada | El responsable elige «únicamente un encargado que ofrezca garantías suficientes». | Poder acreditar ante el centro esas garantías, incluidas las de su subencargado [PVJ] | Aportar garantías documentadas (certificados con alcance, medidas) | `EV-01`, `EV-02`, `EV-03` |
| N-02 | RGPD **art. 28.2 y 28.4** | Ídem | Verificada | El encargado no recurre a otro encargado sin autorización previa por escrito, específica o general, del responsable; con autorización general debe informar de los cambios y dar oportunidad de oponerse. Al subencargado se le imponen por contrato «las mismas obligaciones». | El contrato con cada centro debe autorizar al proveedor S3 como subencargado y fijar cómo se avisa de un cambio. **Cambiar de proveedor S3 no es solo técnico**: exige aviso previo a todos los centros [PVJ] | Firmar un contrato de encargo con las mismas obligaciones; publicar y avisar de sus propios subencargados | `EV-04`, `EV-05`, `EV-06` |
| N-03 | RGPD **art. 28.3 a)-h)** | Ídem | Verificada | Contenido mínimo del contrato: instrucciones documentadas (incluidas transferencias), confidencialidad, medidas del art. 32, asistencia en derechos y en arts. 32-36, supresión o devolución al terminar, información y auditorías. | Contrato de encargo propio con cada centro; y comprobar que el del proveedor cubre cada letra [PVJ] | DPA que cubra las ocho letras | `EV-04`, `EV-06` |
| N-04 | RGPD **art. 32.1** | Ídem | Verificada | Medidas «apropiadas para garantizar un nivel de seguridad adecuado al riesgo», entre ellas cifrado, confidencialidad, integridad, disponibilidad y resiliencia, capacidad de restaurar, y verificación regular de la eficacia. | Análisis de riesgos; cifrado en tránsito y en reposo (`RSEC-ISO-004`); pruebas de restauración (`REQ-BKP-004`); aislamiento por centro (`INV-001`, `ADR-076 §3`) | Cifrado en reposo, redundancia, control de acceso, registros | `EV-02`, `EV-07`, `EV-08`, `EV-12` |
| N-05 | RGPD **art. 33.1 y 33.2** | Ídem | Verificada (33.1); 33.2 citado de memoria, **sin verificar** el literal | El responsable notifica a la autoridad de control «a más tardar 72 horas» desde que tiene constancia. El encargado notifica al responsable sin dilación indebida. | Procedimiento de brechas con plazos internos que dejen margen al centro (`RSEC-GDPR-007`, `REQ-PRIV-004`) | Plazo contractual de aviso de incidentes | `EV-06`, `EV-09` |
| N-06 | RGPD **art. 35.1, 35.3 b) y 35.4** | Ídem | Verificada | EIPD antes del tratamiento si es probable un alto riesgo; en particular con tratamiento a gran escala de categorías especiales. La autoridad publica la lista de tratamientos que la requieren. | La EIPD es obligación del **responsable** (el centro); la plataforma debe asistirle (art. 28.3.f) y aportar la descripción técnica. Si procede y quién la redacta: `OPEN-STO-05` [PVJ] | Información técnica para la EIPD | `EV-10` |
| N-07 | RGPD **art. 44** (y capítulo V) | Ídem | Verificada (art. 44); arts. 45-49 **sin verificar** el literal | Solo hay transferencia a un tercer país si se cumplen las condiciones del capítulo V. | Datos alojados en la UE (`RSEC-GDPR-012`, `RARQ-CLOUD-006`). Analizar si la matriz o los subencargados del proveedor están sujetos a leyes de terceros países [PVJ] | Declarar ubicación de datos, subencargados fuera de la UE y exposición a legislación extracomunitaria | `EV-05`, `EV-11` |
| N-08 | RGPD arts. 5.1.e, 5.1.f, 9, 17, 25, 30 | Ídem | **Sin verificar** el literal en esta sesión | Limitación del plazo de conservación, integridad y confidencialidad, categorías especiales, supresión, protección desde el diseño, registro de actividades. | Plazos de conservación por tipo de objeto (`OPEN-STO-08`); que el versionado y el bloqueo de objetos no impidan una supresión debida; purga de centro (`ADR-077`, en redacción) | Borrado efectivo, incluidas versiones y copias, con plazo declarado | `EV-13` |
| N-09 | **LOPDGDD**, LO 3/2018, **art. 7** | `BOE-A-2018-16673` (consolidado; última actualización que muestra la página: 27/12/2025) | Verificada | El consentimiento del menor solo vale si es «mayor de catorce años»; por debajo, el del titular de la patria potestad o tutela. | Ya recogido en `INV-008`. Sin efecto directo sobre el almacén | — | — |
| N-10 | LOPDGDD **art. 28.2** | Ídem | Verificada (resumen) | Supuestos de mayor riesgo a valorar, entre ellos afectados vulnerables como menores y categorías especiales. | Entra en el análisis de riesgos y en la EIPD | — | `EV-10` |
| N-11 | LOPDGDD **art. 32** (bloqueo) | Ídem | Verificada | Al rectificar o suprimir, el responsable **bloquea** los datos: los reserva e impide su tratamiento salvo para jueces, fiscalía y administraciones, durante el plazo de prescripción; después se destruyen. | Decidir cómo se bloquea un objeto del almacén (no es lo mismo que el borrado lógico de `INV-004`): `OPEN-STO-08` [PVJ] | — | `EV-13` |
| N-12 | LOPDGDD **art. 33** (encargado) | Ídem | Verificada | 33.2: es responsable y no encargado quien «en su propio nombre» establece relaciones con los afectados, o quien usa los datos para fines propios. 33.3: al terminar, el responsable decide si los datos se destruyen, se devuelven o se entregan a otro encargado. 33.4: el encargado puede conservarlos bloqueados mientras puedan derivarse responsabilidades. | La plataforma no usa datos de los centros para fines propios (`RSEC-GDPR-013`). Procedimiento de devolución y destrucción al terminar el contrato con un centro (`OPEN-STO-09`, `ADR-077`) [PVJ] | Devolución y supresión al terminar el contrato | `EV-06`, `EV-14` |
| N-13 | LOPDGDD **art. 34.1.b y 34.3** | Ídem | Verificada | Deben designar delegado de protección de datos «los centros docentes que ofrezcan enseñanzas en cualquiera de los niveles» de la legislación educativa. La designación se comunica a la AEPD en diez días. | La obligación literal es del **centro**. Si la plataforma, como encargada, debe designar el suyo: [PVJ], ligado a `OPEN-07` y `RSEC-GDPR-006` | — | `EV-15` |
| N-14 | LOPDGDD **disposición adicional primera** | Ídem | Verificada | Los responsables del art. 77.1 (sector público) aplican las medidas del ENS. Cuando un tercero presta un servicio «en régimen de concesión, encomienda de gestión o contrato», las medidas «se corresponderán con las de la Administración pública de origen y se ajustarán al Esquema Nacional de Seguridad». | Determina si el ENS alcanza a la plataforma: depende de que el cliente sea un responsable del art. 77.1. Un centro concertado es de titularidad privada: **no se da por supuesto en ningún sentido** (`OPEN-STO-04`) [PVJ] | Ver N-17 | `EV-16` |
| N-15 | LOPDGDD arts. 83 y 92 | Ídem | **Sin verificar** el literal | Derecho a la educación digital; protección de datos de los menores en internet (publicación de imágenes). | Ya tratado por `ADR-066` (consentimiento de imagen). Sin efecto directo sobre la elección de proveedor | — | — |

### 2.2 Educación

| # | Norma y precepto | Fuente primaria | Estado | Qué exige (resumen fiel) | A la plataforma | Al proveedor S3 | Evidencia |
|---|---|---|---|---|---|---|---|
| N-16 | **LOE**, LO 2/2006, **disposición adicional vigesimotercera** (redacción vigente dada por LO 3/2020, en vigor desde 19/01/2021) | `BOE-A-2006-7899`, bloque `davigesimotercera` (consolidado; última actualización que muestra la página: 08/06/2024) | Verificada | 1: los centros pueden recabar los datos del alumnado «necesarios para el ejercicio de su función educativa». 2: la incorporación al centro «supondrá el tratamiento de sus datos» (la LO 3/2020 quitó la palabra «consentimiento»); la información será «la estrictamente necesaria» y no puede tratarse «con fines diferentes del educativo sin consentimiento expreso». 3: se aplicarán «normas técnicas y organizativas que garanticen su seguridad y confidencialidad»; deber de sigilo del personal. 4: la cesión de datos necesarios para el sistema educativo, preferentemente telemática y sujeta a protección de datos. | Minimización y finalidad educativa: qué objetos se guardan en el almacén y para qué. Prohibido cualquier uso no educativo (`RSEC-GDPR-013`). Medidas de seguridad acreditables. Qué base legal cubre cada tratamiento es del centro [PVJ] | Ningún uso propio de los datos | `EV-06`, `EV-10` |

### 2.3 Seguridad de la información y ciberseguridad

| # | Norma y precepto | Fuente primaria | Estado | Qué exige (resumen fiel) | A la plataforma | Al proveedor S3 | Evidencia |
|---|---|---|---|---|---|---|---|
| N-17 | **ENS**, RD 311/2022, **art. 2.3** | `BOE-A-2022-7191` (consolidado; última versión que muestra la página: 06/11/2024) | Verificada (resumen con citas parciales) | El ENS «también se aplica a los sistemas de información de las entidades del sector privado» cuando «presten servicios o provean soluciones a las entidades del sector público». Los pliegos incluyen los requisitos de conformidad y pueden exigir declaraciones o certificaciones; la exigencia puede extenderse a la cadena de suministro. | Si algún cliente es sector público (centro público, administración educativa) o un pliego lo exige, la plataforma necesitaría conformidad con el ENS **propia**; la del proveedor no la sustituye. Si aplica con centros concertados o privados: `OPEN-STO-04` [PVJ] | Certificado de conformidad con categoría, alcance (servicios y centros de datos) y vigencia | `EV-16`, `EV-17` |
| N-18 | ENS **art. 38** | Ídem | Verificada (resumen) | Categoría media o alta: auditoría para **certificar** la conformidad. Categoría básica: autoevaluación y **declaración**. Las declaraciones y certificaciones se publican. | Categorizar el sistema antes de saber qué procedimiento tocaría (`OPEN-STO-04`) | Certificado publicado y comprobable | `EV-17` |
| N-19 | **NIS2**, Directiva (UE) 2022/2555, **art. 2.1, 2.5.b, 21.2.d, 41 y anexo I §8** | DOUE L 333, 27.12.2022; CELEX `32022L2555` | Verificada | Art. 2.1: se aplica a entidades de los anexos I o II que sean al menos **medianas empresas**. Anexo I §8 incluye a los «proveedores de servicios de computación en nube». Art. 2.5.b: los Estados **pueden** extenderla a «centros de enseñanza». Art. 21.2.d: las medidas incluyen «la seguridad de la cadena de suministro». Art. 41: transposición a más tardar el 17/10/2024. | Si la plataforma (SaaS) entra en el anexo I y por tamaño: [PVJ]. Con una entidad por debajo de mediana empresa, el art. 2.1 no la alcanzaría salvo las excepciones del 2.2 (lectura propia, [PVJ]). Como proveedora de centros, puede recibir exigencias contractuales por cadena de suministro | Es previsiblemente entidad incluida por su tamaño y sector [PVJ] | `EV-18` |
| N-20 | **Transposición española de NIS2** | **No localizada en el BOE** | **Sin verificar con fuente oficial.** Fuentes secundarias (despachos y prensa, consultadas 2026-11-12) coinciden en que a esa fecha no hay ley publicada: el anteproyecto de Ley de Coordinación y Gobernanza de la Ciberseguridad se aprobó en Consejo de Ministros el 14/01/2025 y su tramitación no ha concluido; discrepan sobre en qué fase quedó | Mientras no haya ley, no hay obligaciones nacionales concretas que anotar. | Vigilar la publicación (`OPEN-STO-04`) | — | `EV-18` |
| N-21 | **ISO/IEC 27001** (y 27017, 27018, 27701) | `iso.org` | **Sin verificar** en fuente primaria (norma de pago). La edición que los proveedores declaran es ISO/IEC 27001:2022 | Norma voluntaria y certificable de sistema de gestión de seguridad de la información. | `RSEC-ISO-001` a `-009` y el objetivo de `§1.3` de `REQUISITOS` piden «cumplir» ISO 27001. **Si eso significa certificarse o alinearse no está decidido** (`OPEN-STO-06`). El certificado del proveedor **no certifica a la plataforma** | Certificado vigente cuyo **alcance** incluya el servicio de almacenamiento de objetos y los centros de datos usados; declaración de aplicabilidad | `EV-01`, `EV-02` |
| N-22 | **ISO 9001** | `iso.org` | **Sin verificar.** Fuentes secundarias anunciaban una edición nueva (ISO 9001:2026) para septiembre de 2026; no se ha confirmado su publicación | Norma voluntaria de gestión de la calidad. | `REQUISITOS` solo la cita como indicadores de calidad **del centro** (l. 2041). No hay requisito de que la plataforma se certifique: no se inventa (`CLAUDE.md §11`) | No es un criterio de seguridad; a lo sumo, dato informativo | `EV-03` |

### 2.4 Portabilidad y cambio de proveedor

| # | Norma y precepto | Fuente primaria | Estado | Qué exige (resumen fiel) | A la plataforma | Al proveedor S3 | Evidencia |
|---|---|---|---|---|---|---|---|
| N-23 | **Reglamento de Datos (Data Act)**, Reglamento (UE) 2023/2854, **art. 25** | DOUE serie L, 22.12.2023; CELEX `32023R2854` | Verificada | El contrato con un proveedor de servicios de tratamiento de datos fija por escrito el cambio de proveedor: período transitorio máximo de **treinta días naturales**, plazo máximo de preaviso que «no excederá de dos meses», especificación de los datos exportables y un período mínimo de extracción de al menos treinta días naturales. | **Doble posición** [PVJ]: (a) como **cliente** del proveedor S3, tiene esos derechos; (b) como **proveedora** de un SaaS a los centros, podría estar ella misma obligada a ofrecerlos (enlaza con `RSEC-GDPR-003` y `REQ-BKP-002`, exportación por centro). Plan de salida: `OPEN-STO-09` | Cláusulas de cambio conformes en sus condiciones | `EV-06`, `EV-14` |
| N-24 | Data Act **art. 29** | Ídem | Verificada | «A partir del 12 de enero de 2027» no se impone al cliente ningún coste por el cambio; hasta entonces, solo costes reducidos y directamente ligados al cambio. | Tenerlo en cuenta al valorar el coste de revertir `ADR-083` | Informar de los costes de cambio antes de contratar | `EV-14` |
| N-25 | Data Act **art. 32** | Ídem | Verificada (apartado 1) | Los proveedores adoptan medidas para impedir el acceso de administraciones de terceros países a **datos no personales** que se encuentren en la Unión cuando entre en conflicto con el Derecho de la Unión. | No cubre los datos personales (para esos rige N-07) | Declarar sus medidas | `EV-11` |
| N-26 | Data Act **art. 50** | Ídem | Verificada | Aplicable «a partir del 12 de septiembre de 2025». | Ya es aplicable a la fecha de consulta | Ídem | — |

### 2.5 Guías de la AEPD (no son norma; orientan la interpretación)

| # | Documento | URL | Estado | Para qué sirve aquí |
|---|---|---|---|---|
| G-01 | Guía para centros educativos | `https://www.aepd.es/guias/guia-centros-educativos.pdf` | Existencia verificada, **contenido no leído** | Marco general del tratamiento en centros; trata las plataformas educativas y el contrato de encargo |
| G-02 | Principios básicos para la contratación y uso de plataformas educativas digitales por las administraciones educativas y centros docentes (un resultado de búsqueda lo fecha en marzo de 2026: **sin verificar**) | `https://www.aepd.es/documento/apd-plataformas-educativas-administraciones-centros.pdf` | Existencia verificada, **contenido no leído** | Es el documento más directamente aplicable a este producto. **Debe leerlo una persona antes de cerrar `OPEN-STO-05` y `-07`** |
| G-03 | Guía para clientes que contraten servicios de *cloud computing* | `https://www.aepd.es/guias/guia-cloud-clientes.pdf` | Existencia verificada, **contenido no leído** | Qué preguntar al proveedor |
| G-04 | Directrices para la elaboración de contratos entre responsables y encargados | `https://www.aepd.es/guias/guia-directrices-contratos.pdf` | Existencia verificada, **contenido no leído** | Contenido del contrato de encargo |
| G-05 | Lista de tipos de tratamientos que requieren EIPD (art. 35.4 RGPD) | `https://www.aepd.es/documento/listas-dpia-es-35-4.pdf` | Existencia verificada, **contenido no leído** | Determinar si la EIPD es obligatoria (`OPEN-STO-05`) |
| G-06 | Gestión del riesgo y evaluación de impacto en tratamientos de datos personales | `https://www.aepd.es/guias/gestion-riesgo-y-evaluacion-impacto-en-tratamientos-datos-personales.pdf` | Existencia verificada, **contenido no leído** | Método de la EIPD |
| G-07 | Guía para la notificación de brechas de datos personales | `https://www.aepd.es/guias/guia-brechas-seguridad.pdf` | Existencia verificada, **contenido no leído** | Procedimiento de brechas |
| G-08 | Orientaciones sobre aplicaciones que almacenan datos de alumnos en la nube | `https://www.aepd.es/guias/guia-orientaciones-apps-datos-alumnos.pdf` | Existencia verificada, **contenido no leído** | Uso de servicios en la nube con datos de alumnos |

## 3. Lo que la matriz exige al almacén de objetos, en concreto

Solo lo que sale de un requisito ya escrito o de un precepto verificado. Nada de esta lista está decidido: cada punto remite a su pregunta abierta.

| Capacidad | De dónde sale | Garage (dev/CI) la prueba | Pregunta |
|---|---|---|---|
| Datos alojados en la UE | `RSEC-GDPR-012`, `RARQ-CLOUD-006`, N-07 | No aplica | `OPEN-STO-01` |
| Cifrado en reposo (AES-256) | `RSEC-ISO-004`, N-04 | **No** (`ADR-076 §2.3`) | `OPEN-STO-11` |
| TLS en el *endpoint* | `RSEC-ISO-004` | **No** | — (staging, `ADR-076 §2.3`) |
| Copia inmutable (WORM) | `REQ-BKP-001` | **No** (sin Object Lock) | `OPEN-STO-08`, `OPEN-10` |
| Versionado y retención compatibles con la supresión | N-08, N-11 | **No** | `OPEN-STO-08` |
| Restauración probada, RPO < 1 h y RTO < 4 h | `RSEC-ISO-007`, `REQ-BKP-004` | **No** | `OPEN-STO-08`, `OPEN-10` |
| Segunda región o proveedor para copias, en la UE | `REQ-BKP-001` (l. 2434) | No aplica | `OPEN-10` |
| Aislamiento entre centros más allá de la aplicación | `INV-001`, `ADR-076 §10` pregunta 6 | Solo el de aplicación | `OPEN-STO-03` |
| Salida del proveedor en plazo | N-23, N-24, N-12 | No aplica | `OPEN-STO-09` |

**Consecuencia** (ya dicha en `ADR-076 §2.3`): ninguna de las filas con «No» puede darse por probada con el CI en verde. Se verifican en staging contra el proveedor real y así debe decirlo el informe de cada entrega.

## 4. Límites de esta matriz

- No cubre normativa autonómica (`REQUISITOS §4.3`: «la plataforma debe ser configurable por CCAA»), ni la LOPIVI, ni eIDAS (`ADR-082`), ni PCI-DSS (`ADR-081`), ni la normativa fiscal de conservación de facturas. Quedan fuera del encargo.
- `REQUISITOS §4.3` no menciona ENS, NIS2 ni el Reglamento de Datos. Esta matriz **no los convierte en requisitos**: los analiza porque los cita `ADR-076` y el encargo. Si deben entrar en `§4.3`, lo decide el usuario (ver README, «Cambios propuestos en ficheros compartidos»).
- Las normas cambian. Cada fila lleva fecha de consulta; pasado un año, o al contratar al proveedor, se vuelve a comprobar.
