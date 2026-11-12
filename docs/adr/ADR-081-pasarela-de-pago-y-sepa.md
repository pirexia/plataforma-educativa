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

### 4.5 Firma del mandato (enlace con `ADR-082`)

`ADR-082` (borrador, leído en su rama el 2026-11-12) deja a este ADR el nivel de firma del mandato y recomienda N2. Opciones:

| | A · Papel firmado y escaneado (canal presencial) | B · Aceptación en el portal con evidencia (N1 de `ADR-082`) | C · Firma avanzada por prestador (N2 de `ADR-082`) |
|---|---|---|---|
| Ventaja | Es lo que el centro hace hoy; sin dependencias | Sin coste por firma; sin proveedor | Prueba custodiada por un tercero |
| Riesgo | Trabajo manual de secretaría | Prueba más débil ante una reclamación por cargo no autorizado (13 meses); el titular de la cuenta puede **no tener usuario** en la plataforma | Coste por firma; depende de `OPEN-FIRMA-01` y del prestador |
| Coste de revertir | Nulo | Alto: reforzar un mandato ya aceptado es volver a firmarlo | Bajo |

**Recomendación**: **A siempre disponible** (y es el estado de los mandatos migrados) y **C para los mandatos nuevos en línea** cuando exista el prestador de `ADR-082`. **B no se recomienda** como única prueba: el riesgo es pequeño por recibo pero el coste de corregirlo después es alto, y el titular de la cuenta no siempre es un usuario autenticado. La primera entrega de SEPA **no espera al prestador**: con A y los mandatos migrados es operable. Decide el usuario con validación jurídica: `OPEN-PAY-06`. En cualquier nivel, la evidencia sigue el modelo de `ADR-082 §4.5` (no se inventa otro) y el texto del mandato es texto legal.

---

## 5 · D3 — PSD2 y autenticación reforzada (SCA)

### 5.1 Qué obliga y a quién

La autenticación reforzada del cliente la exige PSD2 a los **proveedores de servicios de pago**, no al comercio ni a su proveedor de software. En un pago con tarjeta la ejecuta el **emisor** mediante 3-D Secure dentro del flujo del adquirente. Lo que sí es responsabilidad de la integración es **no romper ese flujo**: admitir que el pago necesite un paso adicional del pagador y que el resultado llegue más tarde.

### 5.2 Opciones

| | A · Solo pagos iniciados por el pagador, en la página alojada del proveedor | B · Tarjeta guardada y cargos iniciados por el comercio (recurrentes) | C · Integración por API con 3-D Secure gestionado por la plataforma |
|---|---|---|---|
| SCA | La resuelve la página del proveedor; cada pago se autentica | SCA al guardar la tarjeta; los cargos posteriores iniciados por el comercio quedan fuera de SCA si hay acuerdo previo (criterio de la EBA, **vía resumen de búsqueda**, `§14`) | La plataforma orquesta el desafío |
| Coste en solitario | Mínimo | Medio: consentimiento, *tokens*, caducidades, cargos fallidos sin el pagador presente | Alto |
| Alcance PCI | SAQ A (`§6`) | SAQ A si el *token* lo custodia el proveedor | Mayor |
| Encaje | Matrícula, recibo suelto, tienda | Duplica lo que ya resuelve el adeudo SEPA | Ninguno |
| Coste de revertir | Bajo | Medio | Alto |

**Decisión: A.** El cobro recurrente del centro va por adeudo SEPA (`§4`); la tarjeta se reserva para pagos puntuales que inicia el pagador. **B no se construye en la primera entrega** y ningún requisito lo pide de forma literal («pedidos recurrentes» de `REQ-SHOP-004` es fase posterior): `OPEN-PAY-12`. **C se descarta.**

### 5.3 Reglas derivadas

1. **Todo pago con tarjeta es asíncrono para la plataforma.** Crear la sesión no es cobrar. El estado final llega por notificación autenticada del proveedor (`§7.2`), no por el navegador.
2. **La URL de retorno no prueba nada.** Sirve para llevar al usuario a una pantalla de «estamos confirmando tu pago» que consulta el estado en la API. Nunca cambia el estado del cobro (`INV-010`).
3. **Los adeudos SEPA son operaciones iniciadas por el beneficiario** y quedan fuera de SCA (criterio de la EBA, **vía resumen de búsqueda**). La excepción conocida, los mandatos electrónicos emitidos con intervención del banco del deudor, no se usa aquí.
4. **PSD3 y el Reglamento de Servicios de Pago**: en las fuentes consultadas el 2026-11-12 el texto estaba acordado y pendiente de publicación oficial, con aplicación a unos 21 meses de su entrada en vigor (fuentes secundarias, **sin verificar**). Con la opción A el impacto esperado es nulo, porque la SCA la sigue ejecutando el proveedor. Se revisa cuando se publique.

---

## 6 · D4 — Alcance PCI DSS

### 6.1 El objetivo

Que el centro (el comercio) pueda acogerse al cuestionario más corto, **SAQ A**, y que la plataforma no almacene, procese ni transmita datos de tarjeta en ningún punto.

### 6.2 Opciones

| | A · Redirección completa a la página de pago del proveedor | B · Campos de pago del proveedor incrustados en nuestra página (*iframe*) | C · Formulario propio que envía la tarjeta al proveedor |
|---|---|---|---|
| Cuestionario | SAQ A | SAQ A, con un criterio de elegibilidad añadido sobre *scripts* | SAQ A-EP o SAQ D |
| Qué carga exige en nuestra SPA | Ninguna sobre *scripts* | Demostrar que la página no es vulnerable a ataques por *scripts*, o garantía escrita del proveedor | Inventario e integridad de *scripts*, detección de cambios, y más |
| Experiencia | El usuario sale un momento de la aplicación | Integrada | Integrada |
| CSP | No cambia | Hay que abrir `script-src` y `frame-src` al proveedor | Idem |
| Coste de revertir | Bajo | Medio | Alto |

Fuente: PCI SSC, FAQ 1588 (febrero de 2025), consultada el 2026-11-12. El criterio de elegibilidad de SAQ A v4.0.1 sobre *scripts* se aplica a quien **incrusta** la página o el formulario de pago de un tercero; **no** se aplica a quien **redirige** al cliente al proveedor (redirección HTTP 30x, `meta` o JavaScript) ni a quien delega del todo el pago (por ejemplo, un enlace de pago). El texto del SAQ A no se ha leído directamente (`§14`).

**Decisión: A.** Es la única opción que no añade ninguna carga de cumplimiento a la SPA y que no toca la CSP estricta (`CLAUDE.md §8`). La pérdida de integración visual es un coste de experiencia asumible en un pago puntual. **B se descarta mientras no haya un motivo de negocio medido**; **C se prohíbe**.

### 6.3 Reglas no negociables

1. **Ningún campo, de ninguna pantalla ni de ningún endpoint, recoge número de tarjeta, caducidad o código de seguridad.** Tampoco en texto libre, notas de secretaría, adjuntos o importaciones.
2. **No se cobra con tarjeta por teléfono tecleándola en la plataforma.** Si un centro quiere cobrar así, lo hace en el terminal de su banco, fuera de la plataforma.
3. **De una tarjeta solo se guarda lo que devuelve el proveedor y sirve al justificante**: marca y cuatro últimos dígitos, más el identificador opaco de la operación. Nada más.
4. **La redirección se construye en servidor** con la URL que entrega el proveedor para esa sesión. El frontend no compone URL de pago ni parámetros firmados.
5. **Los registros** (aplicación, auditoría, trabajos fallidos) no guardan el cuerpo de las notificaciones del proveedor sin filtrar: se guarda el evento ya traducido y un *hash* del original; el original, si se conserva para depurar, va cifrado y con caducidad corta.
6. **Test de arquitectura** (propuesta, siguiente `AR` libre de `ADR-056`): en el esquema real no existe ninguna columna cuyo nombre case con una lista de patrones de dato de tarjeta. Barato y evita el descuido.

### 6.4 Quién rellena qué

El cuestionario es del **comercio**, es decir, del **centro**, ante su adquirente. La plataforma no es comercio (salvo en `REQ-SAAS`, fuera de este ADR). Queda una pregunta que no puedo cerrar: al controlar la redirección, la plataforma puede ser considerada **proveedor de servicios** del centro a efectos de PCI DSS, y un adquirente puede pedir al centro una declaración sobre ella. Qué documento se entrega a los centros para su SAQ A es `OPEN-PAY-04` (cumplimiento; **sin verificar**).

---

## 7 · D5 — Idempotencia y máquina de estados del cobro

### 7.1 Opciones para garantizar «un cobro, un efecto»

| | A · Solo `Idempotency-Key` de la API (`ADR-038 §8`) | B · Tres capas: API, proveedor y buzón de notificaciones | C · Consulta periódica al proveedor, sin *webhooks* |
|---|---|---|---|
| Cubre el doble clic y el reintento del cliente | Sí | Sí | Sí, con A |
| Cubre el reintento nuestro hacia el proveedor | No | Sí | No |
| Cubre la notificación repetida o desordenada | No | Sí | No aplica |
| Cubre la notificación perdida | No | Sí, con el barrido de `§7.2` punto 6 | Sí |
| Coste | Ya existe | Medio | Bajo, pero lento y con límites de uso del proveedor |
| Coste de revertir | — | Bajo | Bajo |

**Decisión: B**, con C como **red de seguridad** dentro de B (no como mecanismo principal).

### 7.2 Las tres capas

1. **Entrada (cliente → API).** `Idempotency-Key` obligatoria (`ADR-038 §8`, `INV-011`) en: crear una sesión de pago, pedir un reembolso, registrar un cobro manual, generar una remesa, marcar una remesa como presentada, importar devoluciones o un extracto. No se crea otro mecanismo: se usa `idempotency_keys`.
2. **Regla de negocio además de la clave.** Una deuda admite **como máximo un intento de pago vivo**. Un segundo intento sobre la misma deuda devuelve la sesión viva o un error de conflicto; nunca abre otra. Y una deuda incluida en una remesa viva **no se puede pagar con tarjeta** sin sacarla antes de la remesa (y a la inversa). Es restricción de base de datos (`§4.3`).
3. **Salida (API → proveedor).** Toda llamada que crea o mueve dinero lleva la clave de idempotencia del proveedor, derivada del `public_id` del intento. Un reintento por fallo de red reutiliza la misma clave. Las llamadas al proveedor se hacen **fuera de la transacción de base de datos** y nunca con una transacción abierta esperando a la red.
4. **Entrada (proveedor → API): buzón de notificaciones.** Cada notificación se **verifica** (firma del proveedor, con tolerancia de reloj), se **guarda** con unicidad sobre (proveedor, identificador del evento) y se **responde** de inmediato; el efecto se aplica en cola (`INV-012`). Una notificación repetida no hace nada. Los manejadores no suponen orden: aplican el evento solo si la transición es válida desde el estado actual y, ante la duda, **consultan el estado al proveedor**.
5. **Comprobación de coherencia.** Importe y moneda de la notificación deben coincidir con los del intento. Si no coinciden, el evento va a **cuarentena** y se alerta; no se aplica.
6. **Barrido.** Un trabajo programado consulta al proveedor los intentos que siguen pendientes pasado un margen, para cubrir la notificación que no llegó. Los workers deben poder procesar notificaciones encoladas por la versión anterior (`CLAUDE.md §9`).

### 7.3 El cobro como libro de movimientos

- **La deuda** (lo que se debe por una factura o por un cargo) y **los movimientos** (cobro, devolución, reembolso, contracargo, ajuste manual) son cosas distintas. Los movimientos son de **solo anexar**; un error se corrige con el movimiento contrario, no editando.
- **El estado de la factura (`REQ-FIN-004`) se deriva** del saldo de sus movimientos: pagada parcialmente, pagada, vencida. Así una devolución SEPA a las seis semanas reabre la factura sin lógica especial.
- **Un cobro puede aplicarse a varias facturas y una factura recibir varios cobros** (lo exige «pagada parcialmente» y lo pide la transferencia que paga dos recibos). La aplicación es explícita y auditable.
- **Estados de un intento de pago** (propuesta): creado → pendiente (redirigido) → confirmado | fallido | caducado | cancelado. Después de confirmado: reembolsado en parte o del todo; disputado → ganado | perdido.
- **Estados de un adeudo dentro de una remesa** (propuesta): incluido → presentado → cobrado (provisional) → devuelto, con motivo. No hay estado «firme»: a efectos informativos se muestra cuándo vence el plazo de 8 semanas.
- **Importes**: `bigint` en céntimos y moneda en columna `text` con `CHECK` (`ADR-029`). Solo `EUR` hasta que un requisito pida otra. Ningún `float` en ningún punto, tampoco al hablar con el proveedor. Los redondeos del prorrateo y de los descuentos pertenecen al motor de `REQ-BEC`/`REQ-FIN`, no al cobro: **el cobro nunca recalcula un importe**.
- **Reembolsos**: siempre por el mismo medio y contra el cobro original, nunca por encima de lo cobrado; con permiso propio, motivo obligatorio y auditoría. Un adeudo SEPA recién cobrado puede ser reembolsado por nosotros **y** devuelto por el deudor: antes de reembolsar se avisa del riesgo de doble abono (advertencia que la documentación de Stripe recoge para su propio producto).

### 7.4 Las notificaciones entrantes y el aislamiento de tenant

Una notificación del proveedor llega **sin sesión y sin tenant**. Reglas:

1. **El tenant se resuelve a partir de un dato autenticado por la firma**: la cuenta del proveedor que viene en el evento, o un identificador opaco del centro en la ruta cuya firma se verifica con el secreto de **ese** centro. Nunca a partir de un parámetro sin verificar.
2. Resuelto el tenant, el trabajo en cola **fija el contexto de tenant explícitamente** (`INV-001`, skill `aislamiento-tenant`). Un evento cuyo tenant no se resuelve va a cuarentena, sin `tenant_id`, con caducidad corta; esa tabla debe figurar en el registro de purga de `ADR-077 §5.3`.
3. **Es la segunda excepción de CSRF de la aplicación** (la primera es el ACS de SAML, `SECURITY.md §2.1`) y una ruta sin `permission:` (excepción nominal de `AR-07a`). La sustitución equivalente es la firma del proveedor. Grupo de rutas propio, límite de peticiones, sin cookies, y documentada en `SECURITY.md` con el mismo rigor que la de SAML.
4. La respuesta a una notificación **no revela** si el tenant o el intento existen.

---

## 8 · D6 — Conciliación

`REQ-FIN-005` pide «conciliación bancaria automática». Hay dos conciliaciones distintas y hacen falta las dos:

- **Con el banco**: qué entró en la cuenta del centro (remesas, transferencias, liquidaciones del proveedor).
- **Con el proveedor**: qué cobros componen cada liquidación y qué comisiones se descontaron.

### 8.1 Opciones para obtener los movimientos del banco

| | A · El centro sube el extracto en fichero | B · Conexión automática por agregador de cuentas (PSD2/AIS) | C · Solo marcado manual |
|---|---|---|---|
| Automatización | Media: subir un fichero | Alta | Nula |
| Dependencias | Ninguna | Un proveedor nuevo, que es además **encargado de tratamiento de datos bancarios**; consentimiento del centro que hay que renovar periódicamente (**sin verificar** el plazo vigente) | Ninguna |
| Coste en solitario | Medio: analizar uno o dos formatos | Medio-alto: contrato, alta, renovaciones, incidencias por banco | Bajo |
| Cumple «automática» | Sí en el casamiento; no en la obtención | Sí | No |
| Coste de revertir | Bajo | Medio | — |

**Decisión: A en la primera entrega**, con el alta manual de C siempre disponible. **B queda como adaptador posterior** tras una interfaz de «fuente de movimientos bancarios»: `OPEN-PAY-13`. Formatos a admitir: los que entregue la banca del centro piloto (el extracto normalizado de la banca española y/o su equivalente ISO 20022); se fijan al especificar, con un fichero real **anonimizado** del centro como caso de prueba (`ADR-030`: nunca un extracto real en el repositorio).

### 8.2 Reglas de casamiento

1. **Casamiento exacto, automático**: una remesa presentada casa con su abono por el identificador de la remesa y el importe total; una transferencia casa por la referencia de pago de `§3.3`; una liquidación del proveedor casa por su identificador.
2. **Casamiento aproximado, solo como sugerencia**: importe y fecha próximos. **Nunca se aplica solo**: lo confirma un usuario con permiso. Un sistema que concilia solo «por parecido» acaba dando por pagada la factura equivocada.
3. **Una línea de extracto se concilia una sola vez.** Importar dos veces el mismo extracto no duplica líneas (clave: cuenta, fecha, importe, referencia y posición o *hash* de la línea).
4. **Las liquidaciones del proveedor llegan netas de comisiones**: una línea del banco corresponde a muchos cobros menos comisiones y reembolsos. Se modela la liquidación como agrupación, y la comisión como gasto del centro (dato que necesita `REQ-FIN-006`), nunca como menor cobro de la familia.
5. **Lo que no casa queda visible** en una bandeja de pendientes con antigüedad. La conciliación no «cierra» en silencio.
6. **Deshacer una conciliación** es un movimiento más, con motivo y auditoría.

---

## 9 · D7 — Impagos

### 9.1 Qué es un impago

Tres orígenes, un solo tratamiento: adeudo **devuelto** (`§4.4`), pago con tarjeta **fallido o disputado**, y factura **vencida** sin cobro. En los tres la deuda vuelve a estar pendiente, con su motivo y su fecha.

### 9.2 Opciones de tratamiento

| | A · Solo listado de morosidad | B · Política configurable por centro: recordatorios, reintento y escalado, con decisiones humanas registradas | C · Automatismo completo: reintentos, recargos y suspensión de servicios |
|---|---|---|---|
| Cumple `REQ-FIN-005` | No («recordatorios automáticos», «escalado de alertas») | Sí | Sí, y lo excede |
| Riesgo | Trabajo manual | Configuración por centro que hay que explicar | **Suspender un servicio a un menor por una decisión automática**; recargos sin base contractual |
| Coste de revertir | — | Bajo | Alto (daño ya hecho) |

**Decisión: B.** **C se descarta**: `REQ-TRAN-009` prohíbe la suspensión automática del transporte y el mismo razonamiento vale para cualquier servicio que reciba un menor.

### 9.3 Reglas

1. **Recordatorios** (`REQ-FIN-005`): calendario configurable por centro (antes del vencimiento, al vencer, después). Los envía el canal de `ADR-079`; este ADR solo emite el evento. Son comunicaciones transaccionales al **pagador**, no al alumno, en el idioma del destinatario (`INV-009`); sus plantillas son texto del centro o de la plataforma, no texto legal, salvo las que anuncien consecuencias contractuales.
2. **Reintento de un adeudo devuelto**: solo por falta de saldo, con un máximo configurable, **incluyéndolo en una remesa posterior** (nunca un fichero automático sin que nadie lo presente). Un adeudo devuelto por mandato inválido, revocado o por rechazo del deudor **no se reintenta**: requiere intervención.
3. **Escalado**: niveles configurables (aviso a administración, a dirección). Cada cambio de nivel queda registrado.
4. **Ninguna suspensión automática de un servicio a un menor.** Para transporte es requisito (`REQ-TRAN-009`). **Propuesta**: que sea regla de plataforma para todos los servicios (comedor, aula matinal, extraescolares): el sistema **avisa**, la decisión la toma una persona del centro con permiso y queda registrada con motivo. `OPEN-PAY-14`.
5. **Recargos e intereses**: no los pide ningún requisito. No se especifican. La repercusión de la comisión bancaria de devolución es `OPEN-PAY-10`.
6. **La morosidad es un dato sensible en la práctica** aunque no sea de categoría especial: su consulta lleva permiso propio, no se muestra a docentes, y **nunca aparece en vistas del alumno** ni en listados operativos (lista de comedor, hoja de ruta). El análisis de morosidad de `REQ-FIN-008` se agrega por defecto.
7. **Acuerdos de pago** (aplazar o fraccionar una deuda): no están en los requisitos; no se especifican.

### 9.4 Lo que heredan `REQ-BEC` y `REQ-TRAN`

- **`REQ-BEC`**: una beca o un descuento modifica el **importe de la deuda antes de cobrarla**; no es un movimiento de cobro. Si una beca se concede o se retira después de cobrado el recibo, el ajuste es un abono o un cargo nuevo (rectificativa de `REQ-FIN-002`), no una edición del cobro. Las becas que el centro cobra de una administración son un cobro de un tercero que concilia por `§8`, no por la pasarela.
- **`REQ-TRAN`**: el transporte es una línea de la factura consolidada (`ADR-031`); no tiene cobro propio ni habla con la pasarela (`§3.4`, regla 4).
- **`REQ-ALUM-002`**: la matrícula se formaliza al recibir el evento de cobro confirmado, no al volver del proveedor (`§5.3`, regla 2). Si el pago de matrícula es condición para formalizarla o puede quedar pendiente es una regla de negocio de `REQ-ALUM`, no de este ADR.

---

## 10 · Invariantes, seguridad y datos personales

### 10.1 Invariantes

| Invariante | Cómo se cumple |
|---|---|
| `INV-001` | Todas las tablas del cobro llevan `tenant_id` y RLS (`ADR-033`). El caso difícil es la notificación entrante: `§7.4`. Las credenciales de un centro solo se cargan con el contexto de ese centro |
| `INV-002` | Permisos propios y separados (propuesta de reparto, nombres a fijar): ver cobros, registrar cobro manual, reembolsar, gestionar mandatos, ver IBAN completo, generar remesa, descargar remesa, marcar presentada, importar devoluciones, conciliar, configurar el proveedor. **Configurar el proveedor y reembolsar no entran en ningún rol por defecto salvo el de administración del centro.** El tutor solo ve y paga **sus** deudas (ámbito por unidad familiar o pagador) |
| `INV-003` | Auditoría de toda escritura; los movimientos son de solo anexar. Auditoría de **lectura** del IBAN completo y de las descargas de remesa y de mandato (skill `auditoria`). La política de redacción no guarda IBAN ni identificadores de proveedor en `changes` |
| `INV-004` | Mandatos, remesas, cobros y movimientos no se borran físicamente |
| `INV-006` | Todo por API documentada; el portal y la SPA son clientes |
| `INV-007` | Solo `REQ-FIN` habla con el puerto de pagos; los demás módulos usan su interfaz pública y sus eventos (`§3.4`) |
| `INV-008` | El pagador es un adulto. Al proveedor y al banco **no se envían datos del alumno** (`§10.4`) |
| `INV-009` | Estados, motivos de devolución y mensajes del proveedor se traducen a claves propias en los cuatro idiomas; nunca se muestra al usuario el texto crudo del proveedor o del banco |
| `INV-010` | Importe, deuda y estado se calculan en servidor; el cliente solo indica **qué** deuda paga, nunca **cuánto** |
| `INV-011` | `§7` |
| `INV-012` | Generación de remesa, importaciones, aplicación de notificaciones, barrido y recordatorios, en cola. La remesa de un centro grande va a la cola `long` si supera el `retry_after` de la conexión ordinaria |
| `INV-015` | Tests con ID de requisito; adaptador falso y test de conformidad (`§3.4`); pruebas de regresión obligatorias en `§11.3` |

### 10.2 Secretos y datos bancarios

- **Credenciales del proveedor por centro** (opción A de `§3.1`): son secretos de cobro de un tercero. `CLAUDE.md §8` pide secretos fuera del código y en gestor de secretos; un secreto **por tenant y dado de alta por el propio centro** no encaja en un gestor estático. Propuesta: cifrado a nivel de campo con clave distinta de la de la base, nunca devuelto por la API una vez guardado (solo «configurado / no configurado» y los últimos caracteres), rotación documentada. Con cuentas conectadas (opción B) **no hay secreto por centro**, solo el identificador de la cuenta: es uno de los motivos de la recomendación de `§3.2`. `OPEN-PAY-03`.
- **Secretos de la plataforma** (clave de plataforma del proveedor, secreto de firma de notificaciones): en el gestor de secretos, por entorno; claves de prueba en desarrollo, CI y staging, **nunca** claves reales fuera de producción.
- **IBAN**: `§4.2`. En exportaciones y listados va enmascarado salvo permiso expreso.
- **Datos de prueba** (`REQ-SEED-005`): IBAN de prueba de entidad no real, tarjetas de prueba del proveedor, identificador de acreedor ficticio. Ningún dato bancario real en desarrollo (`ADR-030`).

### 10.3 Despliegue (`CLAUDE.md §9`)

- La API sigue sin estado: ficheros de remesa y extractos van al almacén S3, no a disco.
- El proveedor necesita alcanzar **una** ruta pública de notificaciones; no se abre nada más. Las llamadas salientes al proveedor exigen salida a Internet desde `api` y `worker`: se anota para `ADR-028` (red) y para el perfil de staging de `ADR-080`.
- En staging y en E2E real se usa el **entorno de pruebas del proveedor**; lo que no se puede probar sin un banco real (presentar una remesa, recibir una devolución auténtica) se declara como no probado en el informe del paso (`CLAUDE.md §3`), igual que el S3 real en `ADR-076 §2.3`.
- Migraciones *expand/contract*; los estados son `text` con `CHECK` (`ADR-029`), así añadir un estado no bloquea.

### 10.4 Datos personales y minimización

1. **Al proveedor de tarjeta** se envía: importe, moneda, un concepto genérico (número de factura o referencia) y, como metadatos, solo `public_id` opacos. **No** el nombre del alumno, ni el curso, ni el concepto detallado (comedor, beca, NEAE, medicación), que pueden revelar circunstancias de un menor.
2. **En la remesa SEPA** el concepto del adeudo viaja al banco del deudor y aparece en su extracto. Recomendación: nombre del centro y referencia de la factura, **sin** el nombre del alumno. Muchos centros lo incluyen hoy por costumbre: `OPEN-PAY-15` (legal y negocio).
3. **Papel de cada tercero** (encargado o responsable independiente), contrato de encargo, ubicación de los datos y transferencias internacionales: van al **expediente de cumplimiento** y al registro de actividades de `REQ-PRIV`, antes de activar un proveedor con datos reales. No se afirma nada aquí sobre el estatus de ningún proveedor (**sin verificar**). `OPEN-PAY-16`.
4. **Conservación.** Cobros, facturas, remesas y mandatos son documentación contable y mercantil con plazo legal de conservación a cargo del **centro** (responsable). El plazo concreto lo fija la asesoría dentro del catálogo de `REQ-PRIV-006` (`OPEN-PAY-17`); no se escribe de memoria. Consecuencia: el derecho de supresión de un pagador **no borra** sus cobros mientras dure la obligación legal (`ADR-004`); se bloquean.
5. **Purga de un centro** (`ADR-077`, borrador leído en su rama el 2026-11-12). Su criterio es que la plataforma es encargado: **primero se devuelve, después se destruye**, y lo que tiene obligación de conservación lo conserva el centro. Este ADR se ajusta a eso sin excepción propia:
   - Las tablas del cobro llevan `tenant_id` y caen en la regla general de purga; **no se pide conservarlas** tras la purga.
   - La **exportación previa** debe incluir cobros, movimientos, mandatos con su documento, ficheros de remesa, devoluciones y conciliaciones en formato legible, porque son lo que el centro está obligado a conservar. Se propone como requisito a `ADR-077` (`§15`).
   - **Recursos en terceros** (`ADR-077 §5.3`): antes de purgar se desconecta la cuenta conectada o se invalidan las credenciales del centro, se retiran sus rutas de notificación y se cancelan intentos vivos. Lo que el proveedor de pago conserve por sus propias obligaciones legales no lo controla la plataforma y debe constar en el certificado de purga como excepción declarada.
   - **Un centro con remesas presentadas y sin conciliar, o con adeudos dentro del plazo de devolución, puede purgarse** (la relación bancaria es del centro), pero la pantalla de baja debe advertirlo.

---

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
