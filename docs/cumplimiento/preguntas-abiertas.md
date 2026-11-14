# Expediente de cumplimiento · Preguntas abiertas (`OPEN-STO-01` a `-12`)

> **Estado**: borrador de arquitectura (agente A7, ola 1), 2026-11-12. Las recomendaciones son propuestas; **decide quien indica la columna «Decide»**. Lo jurídico lleva **[PVJ]** (pendiente de validación jurídica).

## 0. Procedencia de la numeración (léase antes)

`ADR-076` cita `OPEN-STO-01` a `-12` «tal como figuran en el estudio de la sesión del 2026-11-11», pero ese estudio **nunca se guardó en el repositorio** (`ADR-076 §10`, pregunta 1). Lo único que el repositorio conserva de la lista original es:

- `OPEN-STO-01` = proveedor de producción (`ADR-076`, cabecera).
- `OPEN-STO-02` = sustituto de MinIO; el usuario eligió la opción A, Garage (`ADR-076`, «Origen»).
- `OPEN-STO-04` a `-12` = «ENS, EIPD, ISO propia, DPA, plazos de conservación, salida del proveedor, evidencias» (`ADR-076`, cabecera): siete temas para nueve números, sin orden.
- `OPEN-STO-03`: **no hay ningún rastro** de qué era.

Esta numeración es, por tanto, una **reconstrucción**: respeta los tres anclajes anteriores y asigna el resto. `-03`, `-11` y `-12` son asignación de este documento. Si el usuario conserva la lista original y difiere, prevalece la suya y este fichero se corrige.

Con este fichero queda contestada la pregunta 1 de `ADR-076 §10` (dónde se registran): **aquí**. `ADR-076` es inmutable y no se edita; la respuesta consta en `ADR-083`.

## 1. Resumen

| ID | Tema | Bloquea | Decide | Estado |
|---|---|---|---|---|
| `OPEN-STO-01` | Proveedor S3 de producción | Staging y producción | Usuario | **Decidida** (2026-11-12): OVHcloud 3-AZ condicionado a `ADR-083 §6` |
| `OPEN-STO-02` | Sustituto de MinIO en desarrollo | — | Usuario | **Cerrada** (A: Garage, 2026-11-11, `ADR-076`) |
| `OPEN-STO-03` | Aislamiento entre centros en el proveedor | Configuración de staging | Usuario (técnica) | Abierta |
| `OPEN-STO-04` | ENS y NIS2: si aplican y con qué categoría | Datos reales | Legal, después usuario | **Decidida** (2026-11-12) en cuanto al método: la responde un asesor externo con encargo cerrado antes del primer dato real (`ADR-083 §11`). **La respuesta jurídica sigue pendiente** [PVJ] |
| `OPEN-STO-05` | EIPD: si es obligatoria, quién la hace y cuándo | Datos reales | Legal, con cada centro | Abierta [PVJ] |
| `OPEN-STO-06` | ISO 27001 propia: certificarse o alinearse | No | Negocio | Abierta |
| `OPEN-STO-07` | Cadena de encargo y DPA | Contratar al proveedor y datos reales | Legal y usuario | Abierta [PVJ] |
| `OPEN-STO-08` | Conservación, versionado, bloqueo y supresión en el almacén | Configuración de staging y datos reales | Legal (plazos), técnico (mecanismo) | **Decidida** (2026-11-12) en el mecanismo: dos *buckets* (`ADR-083 §11`). **Plazos pendientes** [PVJ] |
| `OPEN-STO-09` | Salida del proveedor | Datos reales | Usuario (técnica) | Abierta |
| `OPEN-STO-10` | Dónde viven las evidencias y quién las custodia | Archivar la primera evidencia | Usuario | Abierta |
| `OPEN-STO-11` | Cifrado en reposo y custodia de claves | Configuración de staging | Usuario (técnica), con `security-reviewer` | **Decidida** (2026-11-12): cifrado en servidor con claves del proveedor (`ADR-083 §11`); pendiente de comprobar con el proveedor |
| `OPEN-STO-12` | Quién hace la validación jurídica y cuándo | Datos reales | Usuario | **Decidida** (2026-11-12): asesor externo, encargo cerrado antes del primer dato real (`ADR-083 §11`); asesor sin contratar |

**Recuento** (alineado el 2026-11-13 con las decisiones del usuario del 2026-11-12 que constan en `ADR-083 §11`): 12 definidas; 1 cerrada (`-02`); 5 decididas por el usuario (`-01`, `-04`, `-08`, `-11`, `-12`), de las que `-04` y `-08` conservan una parte pendiente (respuesta jurídica y plazos); 6 abiertas (`-03`, `-05`, `-06`, `-07`, `-09`, `-10`). **Siguen bloqueando staging**: `-03` (abierta) y las comprobaciones con el proveedor de lo decidido en `-01`, `-08` y `-11` (`ADR-083 §6`). **Siguen bloqueando la entrada de datos reales**: `-05`, `-07`, `-09`, la respuesta del asesor a `-04` y los plazos de `-08`, y la contratación del asesor de `-12`. **No bloquean**: `-06`; `-10` solo bloquea el archivo de evidencias. Ninguna bloquea `S-2` ni el desarrollo con Garage.

## 2. Detalle

### `OPEN-STO-01` · Proveedor S3 de producción

- **Pregunta**: qué servicio de almacenamiento de objetos compatible con S3 se contrata para staging y producción.
- **Opciones y recomendación**: en [`ADR-083`](../adr/ADR-083-proveedor-s3-en-produccion.md). Recomendada: OVHcloud Object Storage Standard 3-AZ, **condicionada** a las confirmaciones por escrito de `ADR-083 §6`.
- **Impacto**: desbloquea staging (`ADR-080`), la conformidad de `ADR-076 §2.3` y `OPEN-10`. No se puede contratar sin entidad jurídica (`OPEN-07`).
- **Bloquea**: staging y producción. **Decide**: usuario.
- **Decidida por el usuario (2026-11-12)**: la recomendación, condicionada a `ADR-083 §6` (`ADR-083 §11`).

### `OPEN-STO-02` · Sustituto de MinIO en desarrollo, CI y E2E

**Cerrada.** Opción A, Garage (usuario, 2026-11-11). Implementada en `S-1a` y `S-1b`. Se lista solo para que la numeración no tenga huecos.

### `OPEN-STO-03` · Aislamiento entre centros en el proveedor *(asignación de este documento)*

- **Pregunta** (es la 6 de `ADR-076 §10`): hoy una sola clave ve el *bucket* entero y el aislamiento entre centros es solo de la aplicación (`PrivateObjectStore`, prefijo `tenants/{public_id}/`). ¿Basta en producción?
- **Opciones**:
  1. **Un *bucket*, una clave, prefijo por centro (Recomendada).** Es lo construido y lo probado en CI. Coste nulo. Riesgo: un fallo de la aplicación o una clave filtrada expone todos los centros; lo mitigan el test de arquitectura de `S-2` y la rotación de la clave. Reversible: pasar a 2 o 3 es mover objetos por prefijo.
  2. Un *bucket* y varias claves con política por prefijo. Defensa en profundidad real solo si la aplicación usa una clave distinta por centro, lo que obliga a gestionar N secretos y a cambiar `PrivateObjectStore`. Depende de que el proveedor soporte políticas por prefijo (**sin verificar** en ninguno).
  3. Un *bucket* por centro. Aislamiento más fuerte y purga de centro trivial (`ADR-077`), a cambio de aprovisionamiento por centro (`ADR-048`), límites de número de *buckets* del proveedor y más superficie de configuración.
- **Recomendación**: 1 para el piloto, con dos condiciones: clave de producción distinta de la de staging y de la de copias, y revisar la decisión cuando haya más de un centro con datos reales o si la EIPD (`OPEN-STO-05`) lo pide. Lo reversible antes que lo óptimo.
- **Bloquea**: la configuración de staging. **Decide**: usuario, con informe de `security-reviewer`.

### `OPEN-STO-04` · ENS y NIS2: ¿aplican a la plataforma?

- **Pregunta**: si el ENS (RD 311/2022, art. 2.3; LOPDGDD DA 1.ª) y NIS2 alcanzan a la plataforma, y con qué categoría.
- **Lo verificado** (matriz, N-14, N-17, N-19): el ENS alcanza al sector privado que presta servicios **al sector público**. NIS2 parte de la mediana empresa y su transposición española no consta publicada a 2026-11-12 (sin verificar con fuente oficial).
- **Opciones**:
  1. **No asumir nada y preguntarlo (Recomendada).** Consulta jurídica concreta: «¿un centro concertado, de titularidad privada y sostenido con fondos públicos, es a estos efectos sector público o impone el ENS a su proveedor?». Mientras tanto, elegir un proveedor S3 **que no cierre la puerta** (uno que declare conformidad con el ENS).
  2. Asumir que no aplica (los clientes iniciales son concertados y privados). Barato hoy; si la respuesta fuera la contraria o un pliego lo exigiera, habría que rehacer la elección de proveedor y abordar una certificación propia con prisa.
  3. Asumir que aplica y preparar ya la conformidad propia. Coste alto y desproporcionado para un desarrollo en solitario sin centro firmado.
- **Impacto**: condiciona el criterio «ENS» de `ADR-083` y la venta a centros públicos.
- **Bloquea**: datos reales. **Decide**: legal; después, usuario.
- **Decidida por el usuario (2026-11-12)** en cuanto a quién responde: el asesor externo de `OPEN-STO-12`, con la pregunta clave sobre los centros concertados (`ADR-083 §11`). La respuesta sigue pendiente.

### `OPEN-STO-05` · EIPD

- **Pregunta**: si el tratamiento exige evaluación de impacto (RGPD art. 35), quién la redacta y cuándo.
- **Lo verificado** (N-06): la EIPD es obligación del responsable (el centro); se exige en particular con categorías especiales a gran escala; el encargado asiste. La lista de la AEPD del art. 35.4 (G-05) **no se ha podido leer**.
- **Opciones**:
  1. **La plataforma prepara una EIPD modelo que cada centro adopta y firma (Recomendada).** Es lo que un centro espera de su proveedor y lo que permite el art. 28.3.f. Se redacta antes del primer dato real, con asesor. Coste: un documento vivo más.
  2. Dejarla íntegramente al centro. Jurídicamente defendible [PVJ], comercialmente débil: el centro no conoce la arquitectura.
  3. No hacerla y documentar por qué no es obligatoria. Solo si el asesor lo sostiene tras leer G-05; con datos de salud y de menores parece difícil [PVJ].
- **Bloquea**: datos reales. **Decide**: legal, con cada centro.

### `OPEN-STO-06` · ISO 27001 propia

- **Pregunta**: `REQUISITOS §2.1` (l. 195) y `§7.1` piden «cumplir» ISO 27001. ¿Significa certificar a la plataforma o alinear sus controles?
- **Opciones**:
  1. **Alinear sin certificar, y decirlo así (Recomendada).** Los `RSEC-ISO-001` a `-009` se implementan y se evidencian; no se afirma certificación. Coste asumible en solitario. Reversible: certificarse después aprovecha lo hecho.
  2. Certificarse antes del primer centro. Auditoría externa, coste y mantenimiento anual; no hay cifra verificada.
  3. Apoyarse solo en el certificado del proveedor. **Descartada**: el certificado del proveedor cubre su servicio, no la plataforma; presentarlo como propio sería engañoso.
- **Bloquea**: nada técnico. **Decide**: negocio (usuario).
- **Nota**: ISO 9001 no es requisito de la plataforma en `REQUISITOS`; no se abre pregunta para no inventarlo.

### `OPEN-STO-07` · Cadena de encargo y DPA

- **Pregunta**: quién firma qué. Centro (responsable) → plataforma (encargada) → proveedor S3 (subencargado).
- **Lo verificado** (N-02, N-03, N-12): hace falta autorización escrita del responsable para el subencargado, aviso de cambios y las mismas obligaciones en cascada.
- **Depende de** `OPEN-07` (entidad jurídica que firma): **sin ella no se puede firmar ni el contrato con el proveedor ni el de encargo con un centro**.
- **Opciones**:
  1. **Autorización general de subencargados en el contrato con el centro, con lista publicada y preaviso de cambios (Recomendada).** Permite cambiar de proveedor S3 sin renegociar con cada centro; coherente con `REQ-PRIV-005` (inventario de subencargados). El plazo de preaviso lo fija el asesor.
  2. Autorización específica por subencargado. Más garantista; cada cambio de proveedor exige el consentimiento de todos los centros, lo que en la práctica impide revertir `ADR-083`.
  3. Que cada centro contrate su propio almacenamiento. Rompe el modelo SaaS y `ADR-076` (un contrato S3, una configuración).
- **Bloquea**: contratar al proveedor con datos reales. **Decide**: legal y usuario.

### `OPEN-STO-08` · Conservación, versionado, bloqueo y supresión en el almacén

- **Pregunta**: qué plazo de conservación tiene cada tipo de objeto y cómo conviven el versionado y el Object Lock (que impiden borrar) con la supresión y el bloqueo (N-08, N-11) y con la copia inmutable de `REQ-BKP-001`.
- **Opciones**:
  1. **Dos *buckets* con reglas distintas (Recomendada).** El de datos vivos: versionado con caducidad corta de versiones no actuales (valor a fijar, [PVJ]) y **sin** Object Lock, para que una supresión sea efectiva en plazo conocido. El de copias: Object Lock con retención igual a la política de copias. El plazo máximo en que un dato suprimido sobrevive en copias se documenta y se comunica.
  2. Un *bucket* con versionado y Object Lock. Simple, pero un objeto suprimido sigue existiendo hasta que venza la retención: choca con N-08 salvo plazo muy corto.
  3. Sin versionado ni Object Lock en datos; solo copias externas. Menos protección ante borrado accidental o malicioso; incumpliría la copia inmutable si tampoco la hay en copias.
- **Lo que falta**: la tabla de plazos por tipo de objeto es de `REQ-PRIV-006` (catálogo de retención) y no existe todavía. Este expediente no la inventa.
- **Bloquea**: configuración de staging (mecanismo) y datos reales (plazos). **Decide**: legal los plazos; técnico el mecanismo. Coordinar con `ADR-077` y `OPEN-10`.
- **Decidida por el usuario (2026-11-12)** en el mecanismo: opción 1 (`ADR-083 §11`). Los plazos siguen abiertos.
- **Dependencias** (anotadas el 2026-11-13, revisión C1, `C1-07`): los plazos de esta pregunta dependen de **`OPEN-PURGA-03`** (`ADR-077 §5.10`: tope de retención de toda copia con datos personales, propuesto en 90 días sin respaldo en requisitos) y chocan con la retención escalonada de **`REQ-BKP-001`**, que incluye copias **«anuales»**: o se acotan por ese tope o necesitan justificación jurídica. La retención de Object Lock del *bucket* de copias no puede superar ese tope (`ADR-083 §6`, P-16).

### `OPEN-STO-09` · Salida del proveedor

- **Pregunta**: cómo se sale del proveedor S3, en cuánto tiempo y con qué prueba.
- **Lo verificado** (N-23, N-24): preaviso máximo de dos meses, transición de treinta días, sin costes de cambio desde el 12/01/2027.
- **Opciones**:
  1. **Plan de salida escrito y ensayado una vez en staging (Recomendada).** Copia de *bucket* a *bucket* con una herramienta S3 estándar hacia un segundo proveedor, comprobación por recuento y suma de verificación, cambio de variables de entorno (`ADR-076 §2.1`). El ensayo mide el tiempo real. Va a `RUNBOOK.md`.
  2. Plan escrito sin ensayo. Más barato; un plan que no se ha ejecutado es una suposición (mismo criterio que `REQ-BKP-004`).
  3. Réplica continua en un segundo proveedor. Salida casi inmediata, coste doble y más complejidad; se solapa con `OPEN-10`.
- **Bloquea**: datos reales. **Decide**: usuario (técnica).

### `OPEN-STO-10` · Dónde viven las evidencias

- **Pregunta**: el repositorio es público; certificados, contratos firmados, informes de auditoría bajo acuerdo de confidencialidad y la EIPD no pueden estar en Git. ¿Dónde, quién las custodia y cómo se evita perderlas?
- **Opciones**:
  1. **Carpeta privada cifrada fuera del repositorio, con copia, y en Git solo el [índice](indice-evidencias.md) (Recomendada).** El índice lleva identificador, qué es, fecha, vigencia y huella SHA-256 del fichero, nunca su contenido ni su ubicación exacta. El sitio concreto (gestor documental, almacenamiento cifrado personal) lo elige el usuario.
  2. Repositorio Git privado aparte. Da historial; los PDF binarios engordan el repositorio y un cambio de visibilidad por error los publica.
  3. El propio almacén S3 de producción. Circular: las evidencias sobre el proveedor quedarían en el proveedor.
- **Bloquea**: archivar la primera evidencia. **Decide**: usuario.
- **Regla ya fijada por el usuario** (2026-11-11, `memory.md`): evidencias fuera de Git, solo su índice.

### `OPEN-STO-11` · Cifrado en reposo y custodia de claves *(asignación de este documento)*

- **Pregunta**: `RSEC-ISO-004` exige cifrado en reposo AES-256. ¿Con claves de quién?
- **Opciones**:
  1. **Cifrado en servidor con claves gestionadas por el proveedor, más el cifrado de campo que ya exigen los requisitos para categorías especiales (Recomendada).** Transparente para la aplicación y para las URL firmadas. Protege frente a robo de discos, no frente al proveedor ni a una orden dirigida a él [PVJ].
  2. SSE-C (la aplicación envía la clave en cada petición). El proveedor no guarda la clave. Coste: custodia y rotación propias, perder la clave es perder los datos, y **las URL prefirmadas con SSE-C obligan al navegador a enviar cabeceras con la clave**, lo que rompe la descarga directa que usa hoy `PrivateObjectStore`. Sin probar.
  3. Cifrado en la aplicación antes de subir. Máxima independencia del proveedor; obliga a que toda descarga pase por la API (adiós a la URL firmada directa, `ADR-013`) y a diseñar la gestión de claves. Es un ADR propio.
- **Recomendación**: 1 ahora; 3 solo para los objetos de categoría especial si la EIPD lo pide. Comprobar en staging que el cifrado por defecto está activo (`ADR-076 §2.3`: Garage no lo prueba).
- **Bloquea**: configuración de staging. **Decide**: usuario, con `security-reviewer`.
- **Decidida por el usuario (2026-11-12)**: opción 1 (`ADR-083 §11`); comprobar en staging que está activo.

### `OPEN-STO-12` · Validación jurídica del expediente *(asignación de este documento)*

- **Pregunta**: todo lo marcado [PVJ] necesita a alguien con capacidad para validarlo. ¿Quién y cuándo?
- **Antecedente**: `ADR-073` aceptó publicar la landing «sin asesor». Aquí el riesgo es otro: datos de menores y de salud.
- **Opciones**:
  1. **Asesor externo de protección de datos, contratado para un encargo cerrado antes del primer dato real (Recomendada).** Entregables: respuesta a `OPEN-STO-04`, `-05`, `-07` y `-08`, y revisión del contrato de encargo. Se le entrega este expediente como punto de partida, lo que acota el coste.
  2. Validar solo con las guías de la AEPD, sin asesor. Coste nulo; nadie con responsabilidad profesional firma la lectura. No recomendable con categorías especiales.
  3. Delegarlo en el delegado de protección de datos del primer centro. Sirve para el tratamiento del centro, no para las obligaciones propias de la plataforma como encargada.
- **Bloquea**: datos reales. **Decide**: usuario. Hasta entonces, **ningún documento del proyecto afirma cumplimiento**.
- **Decidida por el usuario (2026-11-12)**: opción 1 (`ADR-083 §11`). Asesor sin contratar a 2026-11-13.

## 3. Relación con preguntas ya existentes

| Existente | Relación |
|---|---|
| `OPEN-07` (entidad jurídica) | Condición previa de `OPEN-STO-01` (contratar) y `-07`. No se resuelve aquí |
| `OPEN-10` (proveedor de almacenamiento para copias, distinto del host) | No se resuelve aquí. `ADR-083 §7` propone cómo encaja, sin decidirlo |
| `OPEN-11` (dónde se aloja el piloto) | Independiente; conviene que cómputo y almacén estén en la misma región. Lo trata `ADR-080` |
| `ADR-076 §10`, preguntas 4 y 5 | Siguen abiertas; no son de cumplimiento |
| `ADR-076 §10`, pregunta 6 | Pasa a ser `OPEN-STO-03` |
