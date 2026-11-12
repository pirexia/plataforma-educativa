# ADR-081 · Pasarela de pago y adeudos SEPA: el centro es el comercio y el acreedor, el cobro con tarjeta se delega por redirección en un proveedor tras interfaz propia, y la domiciliación sale como remesa bancaria `pain.008`

**Estado**: **PROPUESTA** (ola 1 de arquitectura, agente A5, 2026-11-12). La acepta el usuario. Nada de lo que aquí se decide está implementado ni especificado a nivel de módulo: `REQ-FIN` no tiene carpeta en `docs/modulos/`.
**Fecha**: 2026-11-12
**Origen**: `docs/proceso/ola1-arquitectura-2026-11.md §5` (fila A5). Decisión transversal previa a especificar `REQ-FIN`, `REQ-BEC` y `REQ-TRAN` (fase 2) y el pago de matrícula de `REQ-ALUM-002`.
**Afecta a**: `REQ-FIN-005` (cobros, SEPA, morosidad, conciliación), `REQ-FIN-004` (estados de factura), `REQ-ALUM-002` (pago de matrícula), `REQ-FAM-PORTAL-003` (pago online de recibos), `REQ-TRAN-009` (impago de transporte), `REQ-SHOP-002` y `REQ-API-003` (pasarelas), `REQ-ONB-003` (migración de domiciliaciones vigentes), `REQ-SAAS-003` (solo en lo que se deja fuera, `§2.3`).
**Concreta sin sustituir**: `ADR-029` (importes en enteros de céntimos, `public_id` ULID), `ADR-038 §8` (`Idempotency-Key`), `ADR-031` (el transporte se factura con `REQ-FIN`), `ADR-076` (justificantes y ficheros de remesa en el almacén privado).
**No sustituye ningún ADR.**
**Se apoya en**: `INV-001`, `INV-002`, `INV-003`, `INV-007`, `INV-010`, `INV-011`, `INV-012`, `RNF-MANT-007`, `CLAUDE.md §8` y `§9`.
**Depende de (misma ola, sin leer por estar en redacción paralela)**: `ADR-079` (notificaciones salientes: recordatorios y preavisos de cargo), `ADR-082` (firma digital: firma electrónica del mandato), `ADR-078` (PDF: orden de domiciliación y justificantes).

**Convención de este documento.** «**Requisito**» remite a un ID del documento de requisitos. «**Propuesta**» es diseño de este ADR que ningún requisito pide de forma literal y que el usuario acepta o rechaza. «**Sin verificar**» es un dato técnico o legal que no pude contrastar con fuente primaria el 2026-11-12 (`§14`). Los nombres de tablas, clases y permisos son **orientativos**: los fija `spec-writer` en `docs/modulos/REQ-FIN/`.

---

## 1 · Contexto

### 1.1 Qué piden los requisitos

| Requisito | Texto relevante |
|---|---|
| `REQ-FIN-005` | «Integración con pasarelas de pago (Stripe, Redsys, PayPal, transferencia)», «Domiciliación bancaria SEPA con gestión de mandatos», recordatorios automáticos, «gestión de morosidad con escalado de alertas», «conciliación bancaria automática» |
| `REQ-FIN` (cabecera) | Entidades `Payment`, `PaymentMethod`, `Mandate` |
| `REQ-FIN-004` | Estados de factura: borrador, emitida, enviada, **pagada parcialmente**, pagada, vencida, anulada |
| `REQ-ALUM-002` | «Pago de matrícula integrado con pasarelas de pago» |
| `REQ-FAM-PORTAL-003` | «Pago online de recibos», historial de pagos |
| `REQ-TRAN-009` | Ante impago el sistema **avisa** y **no suspende automáticamente** el servicio a un menor; la decisión es del centro y queda registrada |
| `REQ-SHOP-002`, `REQ-API-003` | Pasarelas (Stripe, Redsys, PayPal); en tienda, además, transferencia y «pago a plazos» |
| `REQ-ONB-003` | El perfil de migración trae «domiciliaciones SEPA vigentes, saldos y recibos pendientes» |
| `REQ-PROV-003` | «Vencimientos, previsión de pagos y remesas» (pagos **a** proveedores) |
| `REQ-SAAS-003` | Cobro **al centro** «por domiciliación, tarjeta o transferencia» y *dunning* |
| `INV-011` | Los endpoints de escritura críticos (**pagos**) aceptan clave de idempotencia |
| `RNF-MANT-007` | Las pasarelas de pago van tras interfaz propia |

### 1.2 Qué hay hoy

Nada de cobro. No existe `docs/modulos/REQ-FIN/`, no hay ninguna dependencia de pago en `apps/api/composer.json` y `ARCHITECTURE.md` solo recoge el principio de `RNF-MANT-007`. Sí existen, y este ADR los reutiliza: la tabla `idempotency_keys` y el modelo `IdempotencyKey` (`ADR-038 §8`), el almacén privado con URL firmada (`ADR-076`), las colas (`worker` y `worker-long`) y la auditoría (`INV-003`).

### 1.3 Por qué se decide antes de especificar `REQ-FIN`

Tres cosas son caras de mover una vez escritas y condicionan el modelo de datos de `REQ-FIN`:

1. **Quién es el comercio y el acreedor.** De ello depende por dónde pasa el dinero, quién responde ante una devolución, a quién se le pide un cuestionario PCI y si la plataforma necesita licencia de servicios de pago.
2. **Que un cobro no es un hecho instantáneo ni definitivo.** Un adeudo SEPA puede devolverse semanas después de darse por cobrado (`§4.4`). Si «pagada» se modela como un booleano de la factura, hay que rehacerlo.
3. **El alcance PCI DSS.** Una sola pantalla que recoja un número de tarjeta mete a la plataforma en un régimen de cumplimiento que un desarrollador en solitario no puede sostener.

### 1.4 Hechos del dominio que condicionan la decisión

- Los pagadores son **familias (consumidores)**. El esquema SEPA aplicable es el **básico (Core)**; el B2B no aplica a consumidores.
- El cobro dominante de un centro es **recurrente y mensual** (`REQ-FIN-003`, recibo único consolidado). El pago con tarjeta es la vía **puntual** (matrícula, recibo suelto desde el portal, tienda).
- El centro ya cobra hoy: tiene banco, **identificador de acreedor SEPA** y mandatos firmados. `REQ-ONB-003` exige traerlos. Un mandato es válido para **un acreedor concreto**; cambiar de identificador de acreedor obliga a enmendar o volver a recoger los mandatos (`§4.2`).
- Los importes van en **céntimos enteros** (`ADR-029`); la única moneda con respaldo en requisitos es el euro.

---

## 2 · Decisión (resumen)

| N.º | Decisión | Sección |
|---|---|---|
| D1 | **El centro es el comercio y el acreedor.** El dinero **nunca** pasa por una cuenta de la plataforma. La plataforma integra el proveedor de pago del centro tras una interfaz propia (`RNF-MANT-007`) con un adaptador por proveedor. Primer adaptador de tarjeta: `OPEN-PAY-01` (recomendado: Stripe con cuenta conectada del centro y cargo directo; Redsys por redirección el segundo) | `§3` |
| D2 | **La domiciliación SEPA sale como remesa bancaria**: la plataforma genera el fichero ISO 20022 `pain.008` (esquema básico) con el identificador de acreedor del centro, el centro lo presenta en su banco y la plataforma importa las devoluciones. El adeudo por API de un proveedor de pago queda como adaptador **posterior y opcional** tras la misma interfaz | `§4` |
| D3 | **La autenticación reforzada (SCA) la ejecuta el emisor en la página del proveedor.** La plataforma no implementa SCA. En la primera entrega la tarjeta solo se usa en pagos **iniciados por el pagador**; no hay tarjeta guardada ni cargos recurrentes con tarjeta | `§5` |
| D4 | **Ningún dato de tarjeta entra en la plataforma.** Integración solo por **redirección completa** a la página de pago alojada del proveedor (objetivo: SAQ A del comercio). Sin campos incrustados ni *iframes* de pago | `§6` |
| D5 | **Idempotencia en tres capas** (entrada por API, salida al proveedor, entrada de *webhooks*) y **cobro como máquina de estados con libro de movimientos**, nunca un booleano en la factura | `§7` |
| D6 | **Conciliación por importación de extracto** que sube el centro (primera entrega) más conciliación de liquidaciones del proveedor; la conexión bancaria automática (PSD2/AIS) queda como adaptador posterior | `§8` |
| D7 | **Impagos**: toda devolución o fallo reabre la deuda con su motivo; recordatorios y escalado configurables por centro; **ninguna suspensión automática de un servicio a un menor** | `§9` |

### 2.1 Regla de oro

> **La plataforma calcula cuánto se debe, pide al proveedor o al banco del centro que lo cobre, y anota lo que el proveedor o el banco dicen que ha pasado. No custodia dinero, no ve tarjetas y no da por cobrado nada que no haya confirmado un tercero por un canal autenticado.**

### 2.2 Dentro de este ADR

Modelo de integración, proveedores candidatos, remesa `pain.008` y mandatos, devoluciones, SCA, alcance PCI, idempotencia, estados del cobro, conciliación, impagos, y las reglas de seguridad y datos personales que de ello se derivan.

### 2.3 Fuera de este ADR (se dice para que nadie lo dé por decidido)

- **`REQ-SAAS-003`** (la plataforma cobra al centro). Ahí el comercio es la plataforma, no el centro: otro titular, otro contrato y otro alcance PCI. La interfaz de `§3.4` **no debe suponer que comercio = tenant**, pero el cobro del SaaS se decide en su propio ADR (`OPEN-PAY-09`).
- **`REQ-PROV-003`** (remesas de pago a proveedores, `pain.001`). El generador de ficheros ISO 20022 se diseña para admitir otro mensaje; no se especifica aquí.
- **`REQ-SHOP-002`** «pago a plazos» y cupones; **`REQ-FIN-002`** factura electrónica; **`REQ-FIN-006`** contabilidad (este ADR solo fija qué eventos de cobro debe poder asentar).
- **Motor de descuentos y becas** (`REQ-BEC`): el cobro recibe un importe ya calculado. La única regla que `REQ-BEC` hereda de aquí está en `§9.4`.
- **Bizum**: no aparece en los requisitos. No se especifica (`CLAUDE.md §11`). Se anota en `OPEN-PAY-05` porque condiciona la elección de proveedor.
- **Texto legal del mandato y de la cláusula de preaviso**: redacción legal, no se escribe aquí ni se traduce automáticamente (`CLAUDE.md §2`, punto 9).

---

## 3 · D1 — Modelo de integración y proveedores de pago con tarjeta

### 3.1 Primera pregunta: quién es el comercio

| | A · El centro contrata y la plataforma integra | B · Modelo de plataforma con cuentas conectadas | C · La plataforma cobra y reparte |
|---|---|---|---|
| Qué es | Cada centro tiene su contrato con su banco o proveedor (su TPV virtual, su cuenta). La plataforma guarda sus credenciales y opera en su nombre | La plataforma se da de alta como plataforma en un proveedor; cada centro es una **cuenta conectada** con su propia verificación de identidad; el cargo se crea **en la cuenta del centro** (cargo directo) | La plataforma es el comercio; el dinero entra en su cuenta y luego transfiere al centro |
| Por dónde pasa el dinero | Familia → proveedor → centro | Familia → proveedor → centro (la plataforma puede cobrar una comisión de aplicación) | Familia → proveedor → **plataforma** → centro |
| Devoluciones y contracargos | Contra el centro | Contra el saldo de la cuenta conectada (cargo directo) | Contra la plataforma |
| Coste de implementación en solitario | Medio: un adaptador por proveedor y un secreto por centro | Medio-bajo: un adaptador, un *webhook* de plataforma, alta guiada por el proveedor | Alto: tesorería, liquidaciones, contabilidad de fondos ajenos |
| Mantenimiento a 3 años | Custodia de N secretos de centro; cada banco con su contrato | Un contrato de plataforma; obligaciones de plataforma ante el proveedor | Riesgo regulatorio permanente |
| Riesgo principal | Secretos de cobro de terceros en nuestra base (`§10.3`) | Dependencia de un proveedor y de sus condiciones de plataforma | **Custodiar fondos de terceros es un servicio de pago regulado** (PSD2); sin licencia ni agente, la plataforma no debe hacerlo. *Sin verificar* el encaje exacto de las exclusiones; no hace falta: basta con no entrar |
| Coste de revertir | Bajo (interfaz propia) | Medio (migrar cuentas conectadas y mandatos) | Muy alto |

**Decisión.** Se fija el **principio común a A y B**: el centro es el comercio (y el acreedor SEPA), y el dinero liquida en la cuenta del centro. **C se descarta** sin matices: añade riesgo regulatorio, responsabilidad por contracargos y una tesorería que nadie ha pedido. A y B **no son excluyentes**: son dos formas de adaptador tras la misma interfaz (un proveedor por redirección con credenciales del centro es A; un proveedor con cuentas conectadas es B).

Fuente de la columna B: documentación de Stripe Connect, «Create a charge» (cargo directo: el pago aparece en el saldo de la cuenta conectada; reembolsos y contracargos reducen el saldo de la cuenta conectada; «works best for platforms that provide software as a service»), consultada el 2026-11-12.

### 3.2 Segunda pregunta: qué proveedor se integra primero

`REQ-FIN-005` nombra **Stripe, Redsys, PayPal y transferencia**. Ninguno se elimina; se **ordena** su construcción. Cada adaptador es una dependencia nueva sujeta a `CLAUDE.md §1` (mantenimiento, licencia, frecuencia de versiones), que se comprueba **al implementar**, no aquí.

| | Stripe (cuenta conectada, cargo directo, página alojada) | Redsys (TPV virtual del banco del centro, redirección) | PayPal |
|---|---|---|---|
| Encaje con `§3.1` | B | A | A |
| Relación del centro | Alta nueva del centro en el proveedor (verificación de identidad del proveedor) | El centro suele tenerla ya con su banco, o la pide a su banco | Cuenta de empresa del centro |
| Coste de implementación en solitario | El más bajo: API documentada, entorno de pruebas, *webhooks* firmados, SDK oficial de PHP (`stripe/stripe-php`: licencia MIT, versión publicada el 2026-11-11 según Packagist, consultado el 2026-11-12) | Medio: formulario firmado, notificación HTTP al comercio, documentación oficial dispersa (los manuales oficiales localizados son de 2015-2019; portal de desarrolladores **sin verificar**, `§14`) | Medio; aporta poco a un recibo escolar |
| Comisiones | Tarifa pública por operación del proveedor, más el coste de plataforma | Las que el centro negocie con su banco | Tarifa pública del proveedor |
| Adeudo SEPA por API | Sí (`§4.1`, opción B) | No (es un TPV de tarjeta) | No |
| Coste de revertir | Medio | Bajo | Bajo |

**No se fija ningún precio**: las tarifas cambian y dependen de la negociación de cada centro (`OPEN-PAY-02`).

**Recomendación técnica: Stripe primero, Redsys segundo, PayPal el último y solo si un centro lo pide.** Motivo: con un solo desarrollador pesa más el coste de construir y mantener el primer adaptador que la comisión, que además paga el centro y no la plataforma; y el modelo de cuenta conectada evita custodiar secretos de cobro de cada centro. **Objeción que el usuario debe sopesar**: si el centro piloto ya tiene TPV virtual con su banco y no quiere abrir otra relación ni pagar otra tarifa, el orden correcto es el inverso. Es una decisión **de negocio**, no técnica: `OPEN-PAY-01`.

### 3.3 «Transferencia»

`REQ-FIN-005` la lista como medio de pago. No es una integración: es un cobro que la plataforma **no inicia**. Decisión: la factura muestra los datos bancarios del centro (`REQ-FIN-004`) y una **referencia de pago** única; el cobro se registra al conciliar el extracto (`§8`) o a mano por un usuario con permiso, con auditoría (`INV-003`). **Propuesta**: la referencia es corta, sin datos personales y con dígito de control, para que un error al teclearla no case con otra factura.

### 3.4 La interfaz propia (`RNF-MANT-007`)

Reglas que la especificación de `REQ-FIN` debe respetar (los nombres son orientativos):

1. **Un puerto, varios adaptadores.** El dominio habla con un contrato propio («crear sesión de pago», «consultar estado», «reembolsar», «verificar y traducir una notificación entrante»). Ningún tipo, excepción ni identificador del SDK de un proveedor cruza el puerto. Mismo patrón que `PrivateObjectStore` en `ADR-076`.
2. **El puerto recibe el titular del cobro como parámetro** (hoy: la cuenta de proveedor de un tenant). No da por hecho que el comercio sea el tenant (`§2.3`).
3. **Capacidades declaradas.** Cada adaptador declara qué sabe hacer (pago por redirección, reembolso parcial, adeudo SEPA, consulta de liquidaciones). El dominio pregunta; no hay `if (proveedor === …)` fuera del adaptador.
4. **Dónde vive.** Recomendación: contrato y adaptadores en el núcleo (`App\Support\Payments`, como `App\Support\Files`), y el dominio del cobro (deuda, intentos, mandatos, remesas) en el módulo de `REQ-FIN`. `REQ-ALUM` (matrícula), `REQ-TRAN`, `REQ-SHOP` y el portal **no hablan con la pasarela**: piden a `REQ-FIN` un cargo por su interfaz pública y escuchan sus eventos de dominio (`INV-007`). Alternativas y pregunta: `OPEN-PAY-08`.
5. **Un adaptador falso de pruebas** con el mismo contrato y un **test de conformidad** que pasan todos los adaptadores (mismo criterio que `ADR-076 §5`), para que la suite no dependa de la red.
6. **Eventos de dominio mínimos** (propuesta): cobro confirmado, cobro fallido, cobro reembolsado, cobro disputado, adeudo devuelto. Son lo que consumen `REQ-ALUM` (formalizar la matrícula), `REQ-FIN-006` (asiento) y las notificaciones (`ADR-079`).

---

## 4 · D2 — Domiciliación SEPA: mandatos, remesas `pain.008` y devoluciones

### 4.1 Opciones

| | A · Remesa bancaria: la plataforma genera `pain.008`, el centro la presenta en su banco | B · Adeudo por API de un proveedor de pago | C · Conexión directa con el banco (EBICS, canal *host-to-host* o API del banco) |
|---|---|---|---|
| Acreedor | El centro, con **su** identificador | El del proveedor por defecto; el propio del centro si el proveedor lo admite | El centro |
| Mandatos existentes (`REQ-ONB-003`) | **Siguen valiendo**: mismo acreedor, misma referencia | Hay que volver a recogerlos o migrarlos con el proveedor | Siguen valiendo |
| Automatización | Parcial: dos pasos manuales (subir la remesa, traer las devoluciones) | Total: API y *webhooks* | Total, banco a banco |
| Coste por recibo | El que el centro ya tiene con su banco | Tarifa del proveedor por adeudo, más cargo por devolución | El del banco, más el canal |
| Coste de implementación en solitario | Medio: generar XML válido, importar devoluciones, sin dependencia externa en ejecución | Bajo si el proveedor ya está integrado por `§3` | Alto: un contrato y un canal por banco |
| Mantenimiento a 3 años | Cambios de versión del mensaje (`§4.3`) y variantes por banco | Cambios de API del proveedor | Certificados y canales por banco |
| Riesgo principal | Error humano entre generar y presentar; variantes de formato entre bancos | Límites y revisión del proveedor; el impago se resuelve fuera del proveedor | No está al alcance de un centro pequeño (**sin verificar** la oferta de la banca española) |
| Coste de revertir | Bajo | Medio-alto (mandatos atados al identificador de acreedor) | Alto |

Datos comprobados de la opción B con Stripe (documentación «SEPA Direct Debit payments», consultada el 2026-11-12): solo esquema básico; confirmación del pago «al menos 6 días hábiles» después del envío; **límite de 10.000 EUR por operación** y, para cuentas nuevas, **límite semanal inicial de 10.000 EUR**; las disputas «son definitivas y no se pueden recurrir»; con cuentas conectadas hace falta la capacidad `sepa_debit_payments` por cuenta y **un mandato por cuenta conectada**; tras cobrar en real, el identificador de acreedor no se cambia desde el panel (hay que pedirlo a soporte). El límite semanal inicial es, por sí solo, incompatible con la remesa mensual de un centro mediano mientras no se amplíe.

**Decisión: A en la primera entrega.** Motivos:

1. **Continuidad de los mandatos.** Es el argumento que decide: `REQ-ONB-003` trae domiciliaciones vigentes y una migración que obligue a cientos de familias a firmar de nuevo es un freno de adopción.
2. **No cambia el coste ni la relación bancaria del centro**, que es una decisión de negocio que no nos corresponde imponer.
3. **Ninguna dependencia externa en ejecución**: es generar un fichero y leer otro.
4. **Reversible**: B se añade después como adaptador con capacidad «adeudo SEPA» (`§3.4`, regla 3) para el centro que lo prefiera, sin tocar el dominio.

**C se descarta** para la primera entrega y no se reserva hueco para ella.

### 4.2 Mandatos (`REQ-FIN-005`, entidad `Mandate`)

Reglas para la especificación:

- **Un mandato pertenece a un acreedor (el centro) y a una cuenta de cargo.** Datos mínimos: referencia única del mandato (máximo 35 caracteres en el mensaje), identificador de acreedor del centro, nombre del titular de la cuenta, IBAN, fecha de firma, tipo (recurrente o único), esquema (básico) y estado.
- **El titular de la cuenta no es necesariamente un tutor ni una persona del censo** (un abuelo, una empresa). El mandato guarda el titular como dato propio y, **si** coincide con una `Person`, la enlaza. Quién paga qué parte de la deuda de un alumno (custodia compartida, varios pagadores) **no está en los requisitos**: `OPEN-PAY-11`.
- **Estados** (propuesta): pendiente de firma, activo, suspendido por el centro, revocado por el deudor, cancelado, caducado. Solo un mandato activo entra en una remesa.
- **Prueba del mandato.** El acreedor debe poder aportar el mandato si el banco del deudor lo pide tras una reclamación por cargo no autorizado. El documento firmado se guarda en el almacén privado (`ADR-076`), con su *hash*, y su descarga se audita. Firma manuscrita escaneada o firma electrónica: depende de `ADR-082` (`OPEN-PAY-06`).
- **Enmiendas.** Cambio de IBAN o de referencia: se conserva el histórico y la remesa siguiente lleva la información de enmienda del mensaje. Un cambio de IBAN **no** es editar una celda: es una versión nueva del mandato con su fecha.
- **Caducidad por inactividad**: un mandato sin adeudos durante 36 meses deja de ser utilizable (regla del esquema; **sin verificar** contra el *rulebook* vigente, `§14`). La plataforma lo marca caducado con un trabajo programado.
- **Mandatos migrados** (`REQ-ONB-003`): entran como activos con origen «migrado», con su referencia y fecha de firma originales y **sin documento adjunto** si la exportación no lo trae; el centro sigue custodiando el original. Se señalan en pantalla para que el centro lo sepa.
- **IBAN**: dato financiero de una persona. Propuesta: cifrado a nivel de campo, con los cuatro últimos dígitos en claro para mostrar y un índice ciego para buscar duplicados; el IBAN completo solo se muestra con permiso propio y deja **auditoría de lectura** (`§10.2`). Validación de estructura y dígitos de control en servidor (`INV-010`).

### 4.3 Remesas

- **Formato.** ISO 20022 `pain.008`, esquema básico, según la guía de la banca española (cuaderno de la AEB para adeudos directos SEPA en XML). La guía más reciente localizada es la versión de **octubre de 2025**, que describe la presentación en `pain.008.001.08` y las devoluciones en `pain.002.001.10` (resultado de búsqueda del 2026-11-12; **no pude abrir el PDF**, `§14`). La versión anterior `pain.008.001.02` sigue circulando y las fuentes consultadas **discrepan** sobre su fecha de retirada (noviembre de 2025 según un banco, noviembre de 2026 según otro).
- **Decisión**: el generador se construye con la **versión del mensaje como parámetro por centro** y se valida siempre contra el XSD oficial de esa versión antes de entregar el fichero. Se implementa primero `pain.008.001.08`; la `.02` solo si el banco del centro piloto la exige (`OPEN-PAY-07`). No se implementa el fichero plano antiguo.
- **Generación en cola** (`INV-012`), nunca en la petición HTTP. El `POST` que la pide lleva `Idempotency-Key` (`ADR-038 §8`).
- **El fichero generado es inmutable.** Se guarda en el almacén privado con su *hash*; se descarga por URL firmada de caducidad corta (`CLAUDE.md §8`) y cada descarga se audita. Corregir una remesa es **anularla y generar otra**, no editar el fichero.
- **Identificadores deterministas y únicos**: el identificador de mensaje, el de bloque de pago y el `EndToEndId` de cada adeudo se derivan de los `public_id` (ULID, 26 caracteres, caben en los 35 del campo). El `EndToEndId` es la clave con la que casa la devolución.
- **Una deuda solo puede estar en una remesa viva.** Restricción en base de datos (índice único parcial), no en el controlador: es la defensa contra el **doble cargo**, que es el peor fallo posible de este módulo.
- **Estados de la remesa** (propuesta): borrador → generada → presentada (lo marca un usuario, con fecha) → cobrada con o sin devoluciones → cerrada; y anulada desde borrador o generada. «Presentada» es una **declaración del usuario**: la plataforma no sabe si el banco la aceptó hasta que se concilia (`§8`).
- **Tipo de secuencia** (primero, recurrente, último, único): lo calcula la plataforma a partir del historial del mandato. Si el primer adeudo de un mandato debe seguir marcándose como «primero» es una regla que cambió en el esquema; **sin verificar**, se toma de la guía vigente al implementar.
- **Fecha de cobro y plazo de presentación**: los plazos mínimos entre presentación y cargo los fija el esquema y los acorta o alarga cada banco. La plataforma **no los codifica como constantes**: son configuración del centro, con un valor por defecto conservador.
- **Preaviso al deudor.** El esquema exige avisar al deudor antes de cada cargo: por defecto con **14 días naturales**, o con menos si así se pactó (dato citado en la documentación de Stripe sobre el *rulebook*, consultada el 2026-11-12). Propuesta: el envío del recibo mensual (`REQ-FIN-005`, «envío automático por email de las facturas») hace de preaviso si incluye importe, fecha de cargo, referencia del mandato e identificador de acreedor; el plazo pactado es texto del mandato (`OPEN-PAY-06`).

### 4.4 Devoluciones

Un adeudo básico puede deshacerse en tres momentos, y el modelo debe admitir los tres:

| Momento | Quién | Plazo |
|---|---|---|
| Rechazo antes del cargo | Banco (formato, cuenta inexistente, mandato) | Antes de la fecha de cobro |
| Devolución tras el cargo | Banco del deudor (sin saldo, cuenta cerrada) | Pocos días hábiles tras el cargo (**sin verificar** el número exacto) |
| Reembolso a petición del deudor | El deudor, **sin dar motivo** | **8 semanas** desde el cargo |
| Reclamación por cargo no autorizado | El deudor | Hasta **13 meses** desde el cargo |

Los plazos de 8 semanas y 13 meses constan en la documentación de Stripe sobre el esquema (consultada el 2026-11-12) y en fuentes secundarias españolas; la fuente primaria (el *rulebook* del EPC) devolvió un error al consultarla (`§14`).

Consecuencias de diseño:

1. **«Cobrado» por adeudo es provisional.** La factura pasa a pagada cuando la remesa se da por cobrada, pero una devolución posterior **la reabre**. Por eso el estado de la factura se **deriva** de un libro de movimientos (`§7.3`) y no se guarda como hecho final.
2. **Importación de devoluciones**: por fichero del banco (mensaje de estado o extracto) y, siempre, **alta manual** de una devolución por un usuario con permiso, porque no todos los bancos entregan el fichero a un centro pequeño. La importación es idempotente: el mismo fichero dos veces no duplica devoluciones (clave: remesa + `EndToEndId` + fecha).
3. **Motivo normalizado.** Se guarda el código de motivo del banco tal cual y una categoría propia (sin saldo, cuenta inválida, mandato inválido o revocado, rechazo del deudor, otro). El catálogo de códigos se toma de la guía bancaria vigente al implementar; no se escribe de memoria.
4. **Efecto sobre el mandato**: una devolución por mandato inválido, revocado o cuenta cerrada **suspende el mandato** y exige intervención; una devolución por falta de saldo no.
5. **Comisión de devolución del banco**: si el centro la repercute a la familia es decisión de negocio y tiene límites legales de consumo (`OPEN-PAY-10`). Técnicamente es una línea más de deuda, nunca un importe sumado en silencio.

---

## 5 · D3 — PSD2 y autenticación reforzada (SCA)

_Pendiente._

## 6 · D4 — Alcance PCI DSS

_Pendiente._

## 7 · D5 — Idempotencia y máquina de estados del cobro

_Pendiente._

## 8 · D6 — Conciliación

_Pendiente._

## 9 · D7 — Impagos

_Pendiente._

## 10 · Invariantes, seguridad y datos personales

_Pendiente._

## 11 · Consecuencias, riesgos y reversión

_Pendiente._

## 12 · Alternativas descartadas

_Pendiente._

## 13 · Preguntas abiertas

_Pendiente._

## 14 · Fuentes y lo no verificado

_Pendiente._

## 15 · Cambios propuestos en ficheros compartidos

_Pendiente._
