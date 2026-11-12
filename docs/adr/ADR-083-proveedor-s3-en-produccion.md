# ADR-083 · Proveedor de almacenamiento S3 en staging y producción, y expediente de cumplimiento

**Estado**: **PROPUESTA** (agente A7 de la ola 1, 2026-11-12). La acepta o rechaza el usuario. **No se contrata nada por este ADR.**
**Fecha**: 2026-11-12 (fecha de consulta de todas las fuentes externas)
**Origen**: `OPEN-STO-01` (`ADR-076`, «Fuera de este ADR») y la decisión del usuario del 2026-11-11 de crear el expediente `docs/cumplimiento/`.
**Afecta a**: variables de entorno del disco `s3` en staging y producción, `infra/` (cuando exista staging, `ADR-080`), `SYSADMIN.md`, `RUNBOOK.md`, `PRIVACY.md`. **No toca código**: el contrato es la API S3 (`ADR-076 §2.1`).
**Concreta sin sustituir**: `ADR-013` y `ADR-076`.
**No sustituye ningún ADR.**
**Resuelve**: la pregunta 1 de `ADR-076 §10` (dónde vive el expediente y dónde se registran los `OPEN-STO-*`): en [`docs/cumplimiento/`](../cumplimiento/README.md).
**Se apoya en**: `RSEC-GDPR-012`, `RARQ-CLOUD-006`, `RARQ-INF-007`, `RSEC-ISO-004`, `RSEC-ISO-007`, `REQ-BKP-001`, `RNF-MANT-007`, `INV-001`.
**Depende de**: `OPEN-07` (entidad jurídica que contrata). Sin ella no hay contrato posible.

---

## 1 · Contexto

`ADR-076` fijó la API S3 como contrato único y dejó Garage para desarrollo, CI y E2E, **nunca en servidores**. Falta decidir contra qué servicio real se ejecutan staging y producción. Hay que decidirlo ahora porque:

1. La matriz de `ADR-076 §2.3` deja sin probar todo lo que Garage no implementa: TLS, versionado, Object Lock, cifrado en servidor, ciclo de vida, límites del proveedor. Sin proveedor no hay staging que lo pruebe (`ADR-080`).
2. Los ficheros incluyen documentos de menores y, más adelante, datos de salud (`ADR-013`). La elección tiene una parte jurídica que no puede improvisarse el día que llegue un centro.

La recomendación previa de `architect` (2026-11-11, `memory.md`) era «OVHcloud 3-AZ o Scaleway», **sin verificar**. Este ADR la contrasta con fuentes primarias. Lo que no se pudo comprobar está dicho en cada celda y reunido en `§9`.

### 1.1 Lo que no se sabe y condiciona la decisión

- **Volumen**: no hay ninguna estimación medida de GB por centro. No se inventa. Los precios se dan por TB y se compara el orden de magnitud. Con tres centros de 300 a 1.200 alumnos (`REQ-SEED`) es razonable pensar en decenas o pocos cientos de GB, pero **es una suposición**.
- **Si el ENS aplica** (`OPEN-STO-04`) y **quién contrata** (`OPEN-07`).

## 2 · Criterios

Derivados de requisitos escritos o de preceptos verificados en la [matriz](../cumplimiento/matriz-normas.md):

| # | Criterio | Origen |
|---|---|---|
| C1 | Datos en la UE | `RSEC-GDPR-012`, `RARQ-CLOUD-006` |
| C2 | Garantías acreditables: certificación de seguridad **con el servicio en su alcance** | RGPD art. 28.1 (N-01) |
| C3 | Contrato de encargo y lista de subencargados accesibles | RGPD art. 28.2-28.4 (N-02, N-03) |
| C4 | Exposición a legislación de terceros países | RGPD cap. V (N-07) [PVJ] |
| C5 | No cerrar la puerta al ENS | N-14, N-17, `OPEN-STO-04` |
| C6 | Versionado, Object Lock, cifrado en reposo, ciclo de vida | `RSEC-ISO-004`, `REQ-BKP-001`, `ADR-076 §2.3` |
| C7 | Redundancia y disponibilidad | `RSEC-ISO-007` |
| C8 | Coste, incluido el de salida | Desarrollo en solitario; Data Act (N-23, N-24) |
| C9 | Coste de revertir | Regla del proyecto: lo reversible antes que lo óptimo |

## 3 · Opciones consideradas

Leyenda: **V** = leído en la página oficial del proveedor en esta sesión. **B** = visto solo en el resumen de una búsqueda que cita la página oficial (la página no se pudo abrir). **T** = fuente de terceros. **SV** = sin verificar.

### 3.1 Tabla comparativa

| Criterio | A · OVHcloud Object Storage Standard 3-AZ | B · Scaleway Object Storage Multi-AZ | C · AWS S3, región Europa (España) | D · Hetzner Object Storage |
|---|---|---|---|---|
| Sede de la matriz | Francia | Francia (grupo Iliad) | Estados Unidos | Alemania |
| C1 Regiones UE | 3-AZ en la región de París (**V**, nota de prensa de lanzamiento). Otras regiones 3-AZ, p. ej. Milán: **SV** | `fr-par`, `nl-ams`, `pl-waw` (**B**) | Región en España (**T**); que S3 esté en ella: **SV** | Falkenstein, Núremberg (DE), Helsinki (FI) (**V**) |
| C2 ISO 27001 | ISO/IEC 27001:2022, 27017, 27018 y 27701; la tabla de productos lista **«Object Storage» y «Object Storage-3AZ»** como cubiertos (**V**, documentación actualizada el 02/06/2026). Centros de datos en alcance: **SV** | ISO/IEC 27001:2022, certificado BSI `IS 787020`, **caduca el 05/12/2026** (**B**). El alcance que muestra el registro de BSI habla de alojamiento *bare metal* (Dedibox, Elastic Metal): **que Object Storage esté en el alcance ISO no está confirmado** | Declarada (**T**); alcance por servicio: **SV** | ISO/IEC 27001:2022, alcance «servicios de alojamiento y centros de datos» en las tres sedes (**B**); Object Storage no se nombra |
| C2 Otras | SOC 1, 2 y 3; CSA STAR nivel 1; HDS (con Object Storage en la tabla HDS); SecNumCloud; C5 citado sin estado (**V**, página de conformidad; alcance por producto **SV**) | HDS con Object Storage en el alcance (**B**, registro BSI `HDS 809731`). SOC 2: **no encontrado**. SecNumCloud: en proceso, no concedida (**B**) | Múltiples (**SV** aquí) | BSI C5 tipo 2 según su página alemana; su página inglesa dice lo contrario (**B**, contradictorio) |
| C5 ENS | La página de conformidad declara «Conformidad con la certificación de alto nivel Esquema Nacional de Seguridad (ENS)» (**V**). **Sin categoría formal, alcance, certificador ni vigencia**; la documentación de certificaciones por producto no menciona el ENS | **No encontrado** | «ENS High certified», 31 regiones «including Spain» (**V**, página de AWS). Que S3 y la región de España estén en el certificado: **SV** (remite al PDF) | **No encontrado** |
| C3 DPA y subencargados | Contrato de tratamiento de datos, lista de subencargados y condiciones de Public Cloud publicados como PDF (**V**, existencia; **contenido no leído**) | Lista de subencargados pública, última revisión julio de 2025 (**B**). DPA descargable desde la consola (**B**) | **SV** | **SV** |
| C4 Terceros países | Matriz en la UE; tiene filial en EE. UU. (página `us.ovhcloud.com`). Efecto: [PVJ] | Matriz en la UE. [PVJ] | Matriz en EE. UU.: exige análisis de transferencias y de acceso por autoridades [PVJ] | Matriz en la UE. [PVJ] |
| C6 Versionado | Sí (**V**, nota de prensa) | Sí (**B**) | Sí (**SV** aquí; es el servicio de referencia) | Sí (**V**) |
| C6 Object Lock | Sí, WORM (**V**); no en Cold Archive | Sí, exige versionado (**B**) | Sí (**SV** aquí) | Sí (**V**) |
| C6 Cifrado en reposo | SSE-C y SSE-OMK, claves gestionadas por OVHcloud (**V**, FAQ del 06/03/2026). **Si está activo por defecto: SV** | SSE-C, SSE-KMS, SSE-ONE (**B**) | **SV** aquí | «Opcional» (**V**) |
| C6 Ciclo de vida | Sí (**V**) | **SV** | **SV** aquí | Solo caducidad de objetos (**V**) |
| C7 Redundancia | Tres zonas; SLA 99,99 %; durabilidad declarada de once nueves (**V**) | Tres zonas; SLA 99,9 % (**B**) | **SV** aquí | **No declara** réplica entre sedes ni SLA (**V**: la página no lo dice) |
| C8 Precio de almacenamiento | 0,00001917 €/GB-hora, «about 14 EUR/TB/month» en el primer tramo (**V**, FAQ; la página de precios no se pudo leer entera). IVA: **SV** | ~0,0146 €/GB-mes, es decir ~14,6 €/TB-mes (**B**) | ~0,023 a 0,0245 USD/GB-mes en regiones europeas (**T**; la de España **SV**) | 6,49 €/mes sin IVA con 1 TB incluido; exceso a 0,0087 €/TB-hora (**V**) |
| C8 Salida de datos y peticiones | Sin cargo por tráfico de salida ni por llamadas a la API (**V**) | Sin cargo por peticiones (**B**); **tráfico de salida SV** | ~0,09 USD/GB de salida (**T**) | 1 TB de salida incluido; el precio del exceso es ilegible en la página (**SV**) |
| C9 Coste de revertir | Bajo: salida gratuita | Bajo si la salida es barata (**SV**) | Medio: la salida se paga por GB hasta el 12/01/2027 (N-24) | Bajo |

### 3.2 Opción E · Garage autoalojado en producción

Excluida por `ADR-076 §2.2` salvo ADR nuevo: un nodo único no da durabilidad y Garage no implementa versionado, Object Lock ni cifrado gestionado. Añadiría además la operación de un clúster de almacenamiento a un desarrollo en solitario, y la responsabilidad íntegra de C2 recaería en la plataforma, sin certificado de nadie. **Descartada.**

### 3.3 Valoración

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **A · OVHcloud 3-AZ** | Único de matriz europea que declara ENS; tabla ISO que nombra el producto exacto; SLA más alto; salida gratuita; contratos públicos | La declaración ENS no tiene alcance ni categoría visibles: puede no cubrir Object Storage. El 3-AZ confirmado solo en París. Los contratos no se han leído | Bajo: copiar y cambiar variables; sin coste de salida |
| **B · Scaleway Multi-AZ** | Precio equivalente; tres regiones UE; tres modos de cifrado; lista de subencargados con historial | Alcance ISO sobre Object Storage sin confirmar y certificado a punto de caducar; sin ENS ni SOC 2 localizados; SLA un escalón por debajo; su web rechaza clientes automáticos, así que nada es **V** | Bajo si la salida es barata (por confirmar) |
| **C · AWS S3 (España)** | ENS con categoría alta declarada; datos en España; el servicio S3 de referencia (ninguna duda de compatibilidad) | Matriz estadounidense: el análisis de C4 es el más difícil y el más caro de defender ante un centro [PVJ]; precio más alto y salida de pago | Medio |
| **D · Hetzner** | El más barato a pequeña escala; simple | No declara redundancia entre sedes ni SLA; sin ENS; ciclo de vida limitado; información de C5 contradictoria | Bajo |

## 4 · Decisión propuesta

1. **Proveedor primario para staging y producción: OVHcloud Object Storage, clase Standard 3-AZ, región de París.**
2. **Condicionada**: la decisión solo es firme cuando OVHcloud confirme **por escrito** los puntos de `§6` marcados como condición. Si falla alguno, se aplica `§5`.
3. **Scaleway Multi-AZ queda como alternativa preparada**, no contratada: es el destino del ensayo de salida (`OPEN-STO-09`) y el candidato natural para `OPEN-10`, que este ADR **no** decide.
4. **Staging y producción usan el mismo proveedor y la misma clase**, con *buckets* y claves distintos. Probar en staging contra un servicio distinto del de producción vaciaría de sentido `ADR-076 §2.3`.
5. La configuración concreta del *bucket* (aislamiento, versionado y retención, cifrado) no la fija este ADR: son `OPEN-STO-03`, `-08` y `-11`, cada una con su recomendación en el [expediente](../cumplimiento/preguntas-abiertas.md).

## 5 · Qué pasa si una condición falla

| Si OVHcloud responde que… | Entonces |
|---|---|
| Object Storage 3-AZ no está en el alcance ISO 27001 del centro de datos usado | Falla C2: se pasa a B si Scaleway sí lo acredita; si ninguno, se replantea con C |
| Su conformidad ENS no cubre Object Storage, **y** `OPEN-STO-04` concluye que el ENS aplica | Falla C5: la única opción con ENS alto declarado es C; habría que asumir el análisis de C4 con asesor |
| Su conformidad ENS no cubre Object Storage, **y** el ENS no aplica | Se mantiene A; se anota la limitación para futuras ventas a sector público |
| El DPA no cubre alguna letra del art. 28.3 o los subencargados salen de la UE sin garantías | Decide el asesor (`OPEN-STO-12`) |
| El cifrado en reposo no está activo por defecto | Se activa explícitamente y se comprueba en staging; no cambia la elección |

## 6 · Lo que hay que pedir por escrito al proveedor antes de contratar

Las respuestas son evidencias: se archivan **fuera de Git** y se anotan en el [índice](../cumplimiento/indice-evidencias.md).

| # | Petición | Condición de la decisión | Evidencia |
|---|---|---|---|
| P-01 | Certificado ISO/IEC 27001 vigente y declaración de alcance que nombre Object Storage 3-AZ y los centros de datos de la región | **Sí** | `EV-01`, `EV-02` |
| P-02 | Certificado de conformidad con el ENS: categoría, alcance (¿incluye Object Storage?), entidad certificadora, vigencia | **Sí** si `OPEN-STO-04` concluye que aplica | `EV-17` |
| P-03 | Informe SOC 2 tipo II vigente y si cubre Object Storage (suele exigir acuerdo de confidencialidad) | No | `EV-03` |
| P-04 | Contrato de tratamiento de datos vigente, con fecha de versión, y entidad del grupo que firma con un cliente español | **Sí** | `EV-06` |
| P-05 | Lista de subencargados aplicable a Object Storage, con país, y mecanismo y plazo de aviso de cambios | **Sí** | `EV-05` |
| P-06 | Confirmación de que los datos y sus réplicas permanecen en la UE, incluida la réplica externa opcional | **Sí** | `EV-11` |
| P-07 | Posición por escrito sobre requerimientos de autoridades de terceros países | No ([PVJ]) | `EV-11` |
| P-08 | Si el cifrado en reposo está activo por defecto, con qué algoritmo, y quién custodia las claves en SSE-OMK | **Sí** | `EV-12` |
| P-09 | Plazo de borrado efectivo de un objeto suprimido, incluidas versiones y copias internas | No | `EV-13` |
| P-10 | Plazo contractual de notificación de incidentes de seguridad | No | `EV-09` |
| P-11 | Condiciones de salida: preaviso, período de extracción y costes | No | `EV-14` |
| P-12 | Precio vigente con y sin IVA, tramos y precio de la réplica externa | No | `EV-19` |
| P-13 | Si admite políticas de acceso por prefijo y varias claves por *bucket* | No (alimenta `OPEN-STO-03`) | `EV-20` |
| P-14 | Regiones 3-AZ disponibles además de París | No | `EV-19` |

A Scaleway, como alternativa: las mismas P-01 (¿está Object Storage en el alcance ISO y se ha renovado el certificado que caduca el 05/12/2026?), P-04, P-05 y el precio del tráfico de salida.

## 7 · Consecuencias

**Buenas**

- Staging puede existir y probar la columna derecha de `ADR-076 §2.3`.
- El coste es marginal al volumen previsible: del orden de 14 € por TB y mes, sin coste de salida (cifras de `§3.1`, por confirmar con P-12).
- La elección no entra en el código: cambiarla es configuración más una copia de datos.

**Malas o a vigilar**

- La recomendación descansa en declaraciones del proveedor cuyo alcance no se ha visto. Por eso es condicional.
- El 3-AZ confirmado está en una sola región (París). Las tres zonas no protegen de un fallo de la región ni de un error del proveedor: la copia fuera del proveedor sigue siendo necesaria (`REQ-BKP-001`, l. 2434; `OPEN-10`).
- Cambiar de proveedor exige avisar a los centros (RGPD art. 28.2): la reversibilidad técnica es alta, la contractual depende de `OPEN-STO-07`.
- Comportamientos del SDK: `ADR-076 §4` dejó las sumas de verificación como variable por entorno; hay que comprobar el valor con el proveedor real en staging. **Sin probar.**

**Relación con `OPEN-10`** (no se decide aquí): si el primario es OVHcloud, la copia debería ir a un proveedor distinto y también en la UE; Scaleway encaja. Se deja como sugerencia para quien resuelva `OPEN-10`.

**Requisitos afectados**: `RARQ-INF-007`, `RSEC-GDPR-012`, `RSEC-GDPR-008`, `RSEC-ISO-004`, `RSEC-ISO-007`, `REQ-BKP-001`, `REQ-PRIV-005`, `REQ-PROV-004`.

## 8 · Reversión

Volver atrás o cambiar a otra opción: (1) crear *bucket* y clave en el nuevo proveedor; (2) copiar de *bucket* a *bucket* con una herramienta S3 estándar y verificar recuento y sumas; (3) cambiar las variables de entorno del disco `s3`; (4) repetir la conformidad de `ADR-076 §5` en staging; (5) avisar a los centros según su contrato. Las URL firmadas ya emitidas caducan en minutos (`ADR-076 §10`, pregunta 5), así que no quedan enlaces colgando. El procedimiento se ensaya una vez (`OPEN-STO-09`) y se escribe en `RUNBOOK.md`.

## 9 · Lo no verificado

- **Scaleway**: su web devuelve `403` a clientes automáticos. Todo lo suyo viene de resúmenes de búsqueda o del registro de BSI. Nada es lectura directa.
- **OVHcloud**: la tabla de precios de Public Cloud no se pudo leer completa; el precio sale de su FAQ y de la nota de prensa de lanzamiento (2025). Los PDF del contrato de datos y de subencargados no se han leído. No se ha consultado el registro del CCN de entidades certificadas en el ENS. La región 3-AZ de Milán que citaba la recomendación previa no aparece en ninguna fuente.
- **AWS**: precios de la región de España y alcance por servicio del certificado ENS.
- **Hetzner**: contrato de datos, redundancia real y estado de C5.
- **Ningún proveedor se ha probado**: no se ha creado cuenta, ni *bucket*, ni se ha ejecutado el test de conformidad contra ninguno. La compatibilidad con `aws-sdk-php` y con las URL prefirmadas de `PrivateObjectStore` es una expectativa.
- **IVA** de todos los precios y **volumen** real de datos.
- Toda lectura jurídica (C3, C4, C5): [PVJ].

## 10 · Alternativas descartadas

| Alternativa | Motivo |
|---|---|
| Garage autoalojado | `§3.2` |
| Hetzner (D) | No declara redundancia entre sedes ni SLA, y sin ENS. Válido para pruebas, no para documentos de menores con RPO y RTO comprometidos |
| AWS S3 (C) como primario | Mejor posición en ENS, peor en C4 y en coste de salida. Se conserva como salida si el ENS resulta exigible y OVHcloud no lo acredita (`§5`) |
| Scaleway (B) como primario | Dos incógnitas en C2 (alcance y caducidad del certificado) y ninguna señal de ENS. Queda como alternativa preparada |
| Dos proveedores activos a la vez desde el primer día | Duplica coste, configuración y superficie de fallo sin un centro firmado. Complejidad sin beneficio proporcional hoy |
| Otros proveedores (IONOS, Exoscale, Backblaze, Wasabi, Cloudflare R2, Azure, Google) | No evaluados en esta sesión; no se descartan por ningún defecto conocido. `ADR-076 §9` ya descartó usar Azure o Google con *driver* nativo. Si el usuario quiere alguno, se añade con el mismo método |

## 11 · Preguntas para el usuario

**Decidida por el usuario (2026-11-12)**: `OPEN-STO-01`, según la recomendación de este ADR: OVHcloud Object Storage 3-AZ (París), **condicionado** a las peticiones P-01 a P-14 de `§6`. No se contrata nada hasta tener las respuestas por escrito y la entidad jurídica (`OPEN-07`). Scaleway queda como alternativa preparada. Sigue sin verificarse ninguna certificación con fuente primaria. El ADR continúa en `PROPUESTA` mientras queden abiertas las demás.

**Decidida también el 2026-11-12**: `OPEN-STO-12` y `-04`, según la recomendación: lo jurídico (ENS, NIS2, base legal de datos de menores) lo valida un **asesor externo con encargo cerrado antes del primer dato real**. Hasta entonces toda la matriz de `docs/cumplimiento/` sigue «pendiente de validación jurídica». Pregunta clave para el asesor: si el ENS alcanza a la plataforma por tener clientes concertados.

**Decididas por el usuario (2026-11-12), según la recomendación**: `OPEN-STO-08` y `-11` (dos buckets: datos vivos con versionado de caducidad corta y sin Object Lock, y copias con Object Lock; cifrado en servidor con claves del proveedor). Pendientes de comprobar con el proveedor (`§6`) antes de contratar.

Las de cumplimiento están en [`preguntas-abiertas.md`](../cumplimiento/preguntas-abiertas.md). Las propias de este ADR:

| ID | Pregunta | Recomendada | Bloquea |
|---|---|---|---|
| `OPEN-STO-01` | ¿Se acepta OVHcloud 3-AZ condicionado a `§6`? | **Sí**, y enviar ya las peticiones P-01 a P-14 | Staging |
| `OPEN-083-01` | ¿Quién envía las peticiones de `§6` y con qué identidad, si `OPEN-07` sigue abierta? | **El usuario, como consulta precontractual**: no exige entidad constituida y adelanta semanas. Alternativas: esperar a `OPEN-07`; o abrir una cuenta de prueba personal sin datos reales solo para el test de conformidad | No (pero retrasa `§4.2`) |
| `OPEN-083-02` | ¿Se abre ya una cuenta de prueba, **solo con datos sintéticos**, para ejecutar el test de conformidad de `ADR-076 §5` contra el proveedor? | **Sí**, tras aceptar este ADR y dentro de `ADR-080`: es la única forma de convertir en hecho lo que `§9` deja como expectativa. Alternativas: esperar a tener staging completo; o no probar hasta producción (descartable) | Staging |
