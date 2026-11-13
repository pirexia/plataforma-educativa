# ADR-079 · Notificaciones y comunicaciones salientes

**Estado**: PROPUESTA
**Fecha**: 2026-11-12
**Autor**: `architect` (agente A3 de la ola 1, `docs/proceso/ola1-arquitectura-2026-11.md`)
**Afecta a**: `REQ-CORE-007`, `REQ-COM-001..004`, `REQ-AUTH-001/003`, `REQ-PRL-001/004`, `REQ-FAM-UNIT`, `REQ-AUT-001`, `REQ-SAAS-004`, `REQ-SUP-004`, `RNF-LIM-003`, `RARQ-ARC-005`, `RDB-001`, `RSEC-GDPR-001/008/009`, `INV-001`, `INV-003`, `INV-007`, `INV-008`, `INV-009`, `INV-011`, `INV-012`.
**Reformula**: `OPEN-09` (proveedor de correo transaccional): la parte técnica queda decidida aquí; queda la contratación (`OPEN-079-01`).
**No sustituye ningún ADR.** Precisa, sin cambiarlos, `ADR-045 §4.8` (aviso de módulo contratado), `ADR-008` (PWA en fase 1) y `ADR-073 §3` (correo del ápice de la landing). Roza la recomendación aprobada de `OPEN-FAM-UNIT-33` («evento de `prl` consumido por `COM`»): véase `§4.1` y `OPEN-079-04`.
**Relación con la ola 1**: `ADR-077` (purga de tenant: condiciona tablas, colas y Redis, `§6.4`), `ADR-082` (firma: recordatorios y notificación fehaciente; ver `§4.12` de este ADR), `ADR-083` (proveedor S3: posible proveedor común, `§4.2`). Los tres están en `PROPUESTA` y se han leído en sus ramas el 2026-11-12.

---

## 1. Contexto

### 1.1 Qué piden los requisitos

| Requisito | Qué pide | Fase |
|---|---|---|
| `REQ-CORE-007` | Notificaciones internas (en la aplicación) y externas (correo, SMS, *push*); plantillas personalizables por tenant; cola con reintentos y confirmación de entrega; preferencias por usuario | 1 |
| `REQ-COM-003` (`§5.12`) | Envío masivo, programación, segmentación, estadísticas de entrega y apertura; consumidor del evento `ModuleContracted` (`ADR-045 §4.8`) | 1 |
| `REQ-COM-001/002` | Mensajería interna con confirmación de lectura; circulares con acuse de recibo obligatorio | 1 |
| `REQ-AUTH-001/003` | Correo como único canal de recuperación de contraseña y desbloqueo; MFA por TOTP, SMS y correo | 1 |
| `REQ-PRL-004` | Alerta inmediata si quien recoge no está autorizado, con aviso al tutor principal; alerta de alumno no recogido con escalado | 1 (adelantado) |
| `REQ-PRL-001` | Envío urgente multicanal (*push* + SMS + correo + llamada opcional), prioridad absoluta en la cola, panel de recepción en tiempo real, simulación sin envío real | 2 |
| `REQ-AUT-001`, `REQ-FAM-UNIT` (l. 600), boletines, nóminas y facturas | Envío a tutores por correo, *push* y aviso en la aplicación; «envío automático por email» de documentos | 1-2 |
| `REQ-SAAS-004`, `RNF-LIM-003` | Medición de envíos de SMS y correo por tenant; límite de destinatarios y de ritmo para no ser bloqueados por los proveedores | — |
| `RARQ-ARC-005`, `RNF-MANT-007` | Tiempos de espera, reintentos y cortacircuitos en toda llamada externa; toda dependencia externa tras interfaz propia | — |
| `RMOB-AND-004`, `RMOB-IOS-004` | *Push* por FCM y APNs en la aplicación empaquetada | 3 (`ADR-008`) |

### 1.2 Qué existe hoy (comprobado en el repositorio el 2026-11-12)

- **Correo**: patrón evento de dominio → *listener* → *job* en cola → `Mailable`, con una docena de `Send*Email` en `Auth`, uno en `Core` y uno en `Backoffice`. Colas `auth-mail`, `core-mail` y `bo-mail`, las primeras del `worker`. Transporte `MAIL_MAILER=log` en desarrollo; `MailEnvironmentGuard` aborta el arranque si vale `log` o `array` fuera de `local`/`testing` y `SYSADMIN.md` exige `smtp`.
- **Proveedor de correo**: ninguno. `OPEN-09` y el paso `0.10c` siguen abiertos; de ellos cuelgan `OPEN-AUTH-07` y `OPEN-CORE-04`. Hoy ningún correo sale de verdad: los tests comprueban que el trabajo se encola.
- **SMS**: nada, y es deliberado (`REQ-AUTH/funcional.md §C.7`, `operacion.md §C.2.1`): no hay proveedor, `people.contact_phone` no está verificado, el valor `sms` existe en el enumerado de métodos MFA con una guarda que impide activarlo (`RN-AUTH-69`, `OPEN-AUTH-18`).
- **Avisos en la aplicación**: no hay tabla ni motor. El aviso de módulo contratado se deriva de `module_subscriptions.enabled_at` (`ADR-045 §4.8`).
- **_Push_**: nada. `apps/web` no tiene manifiesto de aplicación web ni *service worker* (`apps/web/public` solo contiene iconos); «PWA en fase 1» de `ADR-008` es hoy una SPA adaptable, no una aplicación instalable.
- **Colas**: *driver* `database`, dos procesos (`worker`, `worker-long`); la prioridad es el orden de las colas en el `worker` (skill `colas-y-workers`). Horizon no está instalado.
- **Dominio**: `adagiox.com` con buzón `founders@` en Arsys y SPF/DMARC del ápice (`ADR-073 §3`). El dominio de la plataforma para los centros sigue en `OPEN-08`.
- **Entregas que esperan este ADR**: `1.19` (`REQ-COM`), `1.14b-d3` (aviso al tutor principal, `RN-PRL-08`, `OPEN-FAM-UNIT-33`) y `1.14b-d4` (alumno no recogido, `OPEN-FAM-UNIT-32`); hasta entonces rige el teléfono a la vista y el registro manual de la llamada (`DP-5`).

### 1.3 Por qué hay que decidir ahora

1. Sin proveedor de correo no hay producción: recuperación de contraseña, invitaciones y el factor de correo de MFA no llegan a nadie.
2. `1.19` y `1.14b-d3`/`-d4` necesitan un contrato único. Si cada módulo sigue creando su propia cola y su propio *job* de correo, a 53 módulos no hay preferencias, ni trazabilidad, ni límites, ni un punto donde aplicar la regla de datos personales en el mensaje.
3. La recogida de menores introduce el primer aviso cuya demora importa. La prioridad debe estar diseñada antes de que exista el primer envío masivo que compita con él.

---

## 2. Alcance: qué decide y qué no

**Decide (propone)**: dónde vive el motor y su contrato; el canal de correo (requisitos del proveedor, dominio de envío, SPF/DKIM/DMARC, proveedor recomendado); SMS; *push*; aviso en la aplicación; plantillas por idioma; categorías, preferencias y base legal; prioridad, colas, reintentos y límites; trazabilidad de entrega; qué datos personales puede llevar un mensaje; estimación de coste.

**No decide**:

- El modelo funcional de mensajería, circulares, acuses y blog (`REQ-COM-001/002/004`): es de `spec-writer` en `1.19`. Aquí solo se fija cómo esas funciones usan los canales.
- El esquema de tablas con detalle de columnas e índices: lo fija la especificación con `db-reviewer`. Aquí van las tablas, su propósito y sus restricciones obligatorias.
- El proveedor de SMS (se difiere con condiciones, `§4.3`) y la llamada de voz de `REQ-PRL-001` (fase 2).
- La notificación fehaciente de `REQ-CONV-002` (`§4.12`).
- *Push* nativo por FCM/APNs de la aplicación empaquetada (fase 3, `ADR-008`).
- Correo entrante (responder a una circular por correo): no está en los requisitos.
- Nada marcado `FUTURO`.

---

## 3. Opciones consideradas

Cada decisión se evalúa contra los cuatro criterios del proyecto: coste de implementación en solitario, mantenimiento a 3 años, impacto en las invariantes y reversibilidad. Precios y capacidades: fuentes y fecha en `§11`; lo que no se pudo leer en la página oficial va marcado **sin verificar**.

### 3.1 D1 · Dónde vive el motor de notificaciones

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **A. Seguir como hoy**: cada módulo con sus *jobs* `Send*Email` y su cola | Cero trabajo ahora | Una cola por módulo en un solo `worker` no escala a 53 módulos; no hay sitio para preferencias, trazabilidad, límites ni la regla de datos personales; cada canal nuevo se reimplementa por módulo | — (es el punto de partida) |
| **B. Motor en `Core`** (`REQ-CORE-007`), tras un contrato público; cada módulo declara sus tipos de aviso y llama al contrato; `REQ-COM` construye encima mensajería, circulares y envío masivo | `Core` es esencial y nunca se descontrata: un aviso de seguridad no depende de que el centro tenga `com`. `Auth` y `Prl` no pasan a depender de `Com` (`INV-007`). Un único punto para preferencias, límites, trazabilidad y auditoría | `Core` crece; hay que vigilar que no absorba la lógica de producto de `COM` | Medio: los módulos solo conocen el contrato; mover la implementación a otro módulo no les afecta |
| **C. Motor en `Com`**; los demás módulos emiten eventos que `Com` escucha | Coincide con la letra de `OPEN-FAM-UNIT-33` y de `ADR-045 §4.8` | `Com` tendría que conocer los eventos de todos los módulos; `Auth` (recuperar contraseña) dependería de un módulo contratable; si `com` no está contratado, el aviso de recogida de un menor no sale | Alto: invierte dependencias en todos los módulos |
| **D. Servicio externo de notificaciones** (Novu autoalojado, Knock, OneSignal) | Preferencias, plantillas y canales ya hechos | Un servicio más que operar en solitario o un encargado más con datos de familias y menores; el aislamiento por tenant y la auditoría quedarían fuera de PostgreSQL y de la RLS | Alto |

**Recomendación: B.**

### 3.2 D2 · Base técnica del motor

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **A. `Illuminate\Notifications`** con su canal `database` y canales de la comunidad | Ya está en `laravel/framework`; canales hechos | Su tabla `notifications` (clave UUID, relación polimórfica, `data` como texto) no cumple `ADR-029` ni lleva `tenant_id` para la RLS; adaptarla es pelear con el *framework*; el canal de correo no da trazabilidad por intento | Medio |
| **B. Motor propio y delgado**: tablas propias (`§4.5`, `§4.9`), un *job* por entrega y canal, y `Mail` de Laravel con `Mailable` para el correo, que es el patrón que ya existe y está probado | Tablas conformes a `ADR-029` y RLS desde el principio; idempotencia, prioridad y trazabilidad bajo control; ninguna dependencia nueva para correo y aviso en la aplicación | Hay que escribir el enrutado por canal y las preferencias (poco código, pero propio) | Bajo: los módulos solo ven el contrato |
| **C. Canal de comunidad por cada pieza** (`laravel-notification-channels/*`) sobre A | Menos código propio | Hereda los problemas de A y añade una dependencia por canal | Medio |

**Recomendación: B.**

### 3.3 D3 · Proveedor de correo transaccional (`OPEN-09`)

Requisitos que debe cumplir cualquier candidato: SMTP estándar (el contrato que ya exige `MailEnvironmentGuard`; sin SDK propio), sede y tratamiento en la UE con contrato de encargado (`RSEC-GDPR-008`), firma DKIM con dominio propio y `Return-Path` propio para alinear SPF, y notificación de rebotes y quejas por *webhook* o API.

| Opción | Ventajas | Riesgos | Coste (fuente en `§11`) | Coste de revertir |
|---|---|---|---|---|
| **A. Scaleway Transactional Email** (Francia) | Empresa y jurisdicción de la UE; pago por uso sin cuota; plan superior con IP dedicada y SLA cuando haga falta; posible proveedor común si `ADR-083` elige su S3 | Producto más joven que los especializados; **sin verificar**: *webhooks* de rebote y queja, límites de envío de una cuenta nueva, región y texto del contrato de encargado (la documentación devolvió 403 al consultarla) | **Indirecto, sin verificar** (`§11`): Esencial: 0 €/mes, 300 correos incluidos, 0,25 € por 1.000 adicionales. Scale: 80 €/mes con 100.000 incluidos, 0,20 € por 1.000, IP dedicada y SLA 99,9 % (antes de impuestos) | Bajo: variables `MAIL_*` y registros DNS |
| **B. Brevo** (Francia) | Empresa de la UE; correo y SMS en el mismo proveedor; plan gratuito para pruebas | Orientado a marketing: riesgo de mezclar corrientes y de marca del proveedor en planes bajos; **sin verificar**: precios actuales (la página oficial no devolvió cifras; fuentes de terceros: 300 correos/día gratis, desde 9 USD/mes por 5.000), alojamiento (terceros dicen AWS París) e IP dedicada | Sin verificar | Bajo |
| **C. Mailjet** (grupo Sinch, Suecia) | Infraestructura en la UE según terceros; subcuentas | Precios publicados en USD; cuota mensual desde el primer plan útil; matriz sueca con filiales en EE. UU. (**sin verificar** el alcance) | Gratis: 6.000/mes con tope de 200/día. De pago: 9 USD (8.000/mes), 19 USD (15.000), 29 USD (15.000, IP dedicada y 20 subcuentas) | Bajo |
| **D. Amazon SES en región de la UE** | El más barato y probado; entregabilidad alta | Proveedor estadounidense: transferencia internacional a justificar ante cada centro; el proyecto ha evitado esta clase de proveedor para datos de menores (`ADR-076`, `ADR-083`) | No consultado | Bajo |
| **E. Servidor propio** (Postfix en un contenedor) | Sin encargado adicional | Reputación de IP, listas negras, rebotes y quejas gestionados a mano por una sola persona; es el modo más probable de que los códigos MFA acaben en *spam* | Tiempo, no dinero | Bajo |
| **F. El SMTP del buzón de Arsys** (`ADR-073`) | Ya contratado | Es un buzón de persona, no un servicio transaccional: sin *webhooks*, con límites de envío de buzón, y mezcla la reputación del correo de contacto con la del producto | 0 € | Bajo |

**Recomendación: A (Scaleway), condicionada** a verificar los cuatro puntos marcados antes de contratar (`OPEN-079-01`). Si alguno falla, B.

### 3.4 D4 · SMS

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **A. Sin SMS en fase 1.** El canal queda nombrado en el contrato y no se escribe adaptador, proveedor ni variable de entorno (misma postura que `REQ-AUTH §C.7`). Se decide con `REQ-PRL-001` (fase 2) en un ADR propio | Coste cero; no se contrata nada sin consumidor; no se envía nada a teléfonos sin verificar | `REQ-AUTH-003` sigue cumplido en dos de tres métodos (`OPEN-AUTH-18`); el aviso de recogida no tiene SMS | Nulo |
| **B. Proveedor de la UE ya** (por ejemplo OVHcloud SMS, prepago; o el SMS de Brevo si se elige para correo) | Canal que llega sin aplicación ni datos móviles | Unos 0,04-0,05 € por SMS a España (OVHcloud, `§11`): unas 200 veces el coste de un correo; teléfonos sin verificar; registro de alias de remitente ante la CNMC (`§4.3`); un encargado más | Bajo en código; el contrato y el alias registrado quedan |
| **C. Plataforma global** (Twilio, Sinch, Vonage) | Cobertura y API maduras, voz incluida | Proveedor de fuera de la UE o con filiales fuera; más de lo que hace falta | Bajo |

**Recomendación: A.**

### 3.5 D5 · *Push*

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **A. Web Push estándar** (Push API + VAPID, RFC 8030/8291/8292), enviado desde el servidor con `minishlink/web-push` tras interfaz propia | Sin SDK en la SPA ni contrato con un proveedor de *push* propio; la carga útil viaja cifrada (RFC 8291) hasta el navegador, aunque el **servicio de push del navegador** (en Chrome, infraestructura de Google/FCM) la transporta y recibe metadatos (punto de entrega, momento, tamaño); coste 0 €; sirve a Android, escritorio e iOS | En iPhone y iPad solo funciona si la familia añade la aplicación a la pantalla de inicio (iOS 16.4+): alcance real limitado; exige manifiesto y *service worker* en `apps/web`, que hoy no existen; una dependencia nueva | Bajo: se retira el canal y quedan correo y aviso en la aplicación |
| **B. Firebase Cloud Messaging para web** | Un único SDK, luego reutilizable en la aplicación de fase 3 | Google como encargado desde fase 1 y SDK de terceros en la SPA (choca con la CSP estricta de `CLAUDE.md §8`); no evita la limitación de iOS | Medio |
| **C. Servicio de terceros** (OneSignal, Pushpad) | Panel y segmentación hechos | Encargado adicional con identificadores de dispositivo de familias; coste recurrente | Medio |
| **D. Sin *push* hasta la aplicación empaquetada de fase 3** | Cero trabajo | `REQ-COM-003` y `REQ-AUT-001` piden *push* en fases 1-2; el aviso de recogida se queda en correo y aplicación abierta | — |

**Recomendación: A**, en entrega propia posterior a `1.19` (`§6.2`), nunca como único canal.

### 3.6 D6 · Cómo llega el aviso a la aplicación abierta

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **A. Sondeo**: la SPA pide el recuento de no leídos al cargar, al recuperar el foco y cada cierto intervalo | Sin estado en servidor, sin servicio nuevo, compatible con «aplicación stateless» (`CLAUDE.md §9`) | Latencia de hasta un intervalo; peticiones periódicas por usuario conectado. **Cada sondeo es una petición autenticada que, tal como está hoy `EnforceSessionIdleTimeout`, refresca la marca de actividad** (`REQ-AUTH/funcional.md §4.6`): una pestaña abierta no caducaría nunca y rompería `REQ-AUTH-005` (30 min). Lo resuelve la regla obligatoria de `§4.5` punto 5 | Nulo |
| **B. Server-Sent Events** | Casi inmediato, HTTP normal | Una conexión PHP abierta por usuario: no encaja con el servidor de aplicación actual sin un proceso aparte | Medio |
| **C. WebSocket** (Laravel Reverb) | Tiempo real; serviría al panel de recepción de `REQ-PRL-001` | Servicio nuevo que desplegar, vigilar y asegurar por tenant; dependencia nueva | Medio-alto |

**Recomendación: A.** C se reevalúa con `REQ-PRL-001` (fase 2), que es el primer requisito que pide tiempo real.

### 3.7 D7 · Prioridad del aviso urgente en la cola

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **A. Colas por prioridad en el `worker` actual** (`notify-urgent` la primera; `notify-bulk` al final, solo por delante de las colas de PDF en lote) y un trabajo por entrega, corto | Sin procesos nuevos; un cambio en dos ficheros de despliegue | El urgente espera a que termine el trabajo en curso: segundos normalmente, hasta el `--timeout` de 120 s en el peor caso (una exportación) | Nulo |
| **B. Proceso dedicado `worker-urgent`** que solo lee `notify-urgent` | El urgente nunca espera a otro trabajo; sobrevive a un `worker` atascado | Una unidad Quadlet y un servicio de `compose` más; memoria de un proceso PHP permanente | Bajo |
| **C. Redis + Horizon** | Prioridades, equilibrado y panel | Dependencia elegida y no instalada (`OPEN-BO-23`); no resuelve nada que B no resuelva | Medio |

**Recomendación: A ahora, B con disparador escrito** (`§4.8`).

### 3.8 D8 · Plantillas del centro

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **A. Solo plantillas del producto** (Blade y ficheros de idioma en los cuatro idiomas); el centro personaliza marca, nombre y pie | Lo que ya existe; traducciones bajo control; sin superficie de inyección | No cumple entero «plantillas personalizables por tenant» | — |
| **B. A para avisos del sistema, de cuenta y de seguridad; y contenido propio del centro por idioma para circulares y avisos de `COM`**, con marcadores de una lista cerrada sustituidos como texto | Cumple `REQ-CORE-007` y `REQ-COM-002` donde tiene sentido; el centro no puede romper ni falsear un aviso de seguridad | Hay que almacenar contenido por idioma (`ADR-021`) y decidir qué pasa si falta una traducción | Bajo |
| **C. Editor completo de plantillas** para todos los tipos, evaluadas por el motor de vistas | Máxima flexibilidad | Evaluar Blade escrito por un tenant es ejecución de código en servidor; el centro podría quitar el texto legal o la advertencia de un aviso de seguridad | Alto |

**Recomendación: B.**

---

## 4. Decisión

**Un motor de notificaciones único en `Core`, propio y delgado, con cuatro canales (aviso en la aplicación, correo, Web Push y SMS reservado sin implementar), tres prioridades, categorías con base legal declarada y una fila de traza por cada entrega. El correo sale por SMTP a un proveedor de la UE desde subdominios propios con SPF, DKIM y DMARC. Ningún mensaje que salga de la plataforma lleva datos de categoría especial.**

Todo lo que sigue es propuesta hasta que el usuario la acepte. Los nombres de clases, tablas y colas son orientativos para la especificación, salvo donde se diga «obligatorio».

### 4.1 Motor en `Core` y contrato (D1, D2)

1. **El motor vive en `Core`** como implementación de `REQ-CORE-007`. Expone un contrato público (interfaz en la superficie pública de `Core`, como los contratos de `ADR-048`) con una operación: notificar un **tipo** a una **audiencia** con unos **parámetros**. Los módulos no conocen canales, proveedores ni colas.
2. **Cada módulo declara sus tipos de aviso** en su descriptor (`moduleDescriptor()`, precedente de `ADR-045` y `ADR-053`): código (`<módulo>.<nombre>`), categoría (`§4.7`), prioridad (`§4.8`), canales admitidos y si lleva enlace. Un tipo no declarado no se puede enviar (fallo en cerrado, comprobado en test). El catálogo alimenta la pantalla de preferencias y el registro de actividades de tratamiento.
3. **El módulo de origen dispara el aviso**: su propio *listener* de su propio evento de dominio llama al contrato. Es el patrón que ya existe (evento → *listener* → *job*), cambiando el *job* de correo propio por la llamada al motor.
4. **La audiencia la resuelve quien es dueño del dato, nunca el motor.** «Tutores de un alumno» o «tutor principal» se obtienen por el contrato público de `fam_unit`, que es quien conoce custodia, restricciones y quién es tutor legal. El motor recibe personas o usuarios ya resueltos; no consulta tablas de otros módulos (`INV-007`). Los destinatarios por rol o permiso se resuelven **por permiso**, nunca por lista de códigos de rol (`ADR-045 §4.8`).
5. **`REQ-COM` (`1.19`) es un cliente más del motor**, y dueño de lo que es producto: mensajería, circulares, acuses, envío masivo, programación, segmentación y estadísticas. El *listener* de `ModuleContracted` que `ADR-045 §4.8` asigna a `REQ-COM-003` sigue donde ese ADR lo pone y crea el aviso llamando al contrato.
6. **Avisos de `prl` (`1.14b-d3`/`-d4`)**: el evento lo emite `prl`, como recomienda `OPEN-FAM-UNIT-33`. Este ADR propone que quien lo consume sea un *listener* del propio `prl` que llama al contrato de `Core`, y no un *listener* dentro de `Com`: así el aviso de recogida no depende de que el centro tenga contratado `com`. Es un matiz sobre una recomendación ya aprobada por el usuario, así que no se da por decidido: `OPEN-079-04`.
7. **Los correos que ya existen** (`Send*Email` de `Auth`, `Core` y `Backoffice`) siguen funcionando sin cambios en sus colas. Se pasan al motor cuando se toque cada uno, con expand/contract (el `worker` debe seguir procesando trabajos encolados por la versión anterior, `CLAUDE.md §9`). No se abre una entrega de refactorización para moverlos (`OPEN-079-12`).
8. **Auditoría (`INV-003`)**: el envío masivo y la circular se auditan como operación de negocio en su módulo. La entrega individual no genera fila de auditoría: su registro es la traza de `§4.9`.

### 4.2 Correo (D3; reformula `OPEN-09`)

1. **Contrato: SMTP con TLS.** Sin SDK ni paquete de proveedor. Cambiar de proveedor es cambiar `MAIL_*` y registros DNS. `MailEnvironmentGuard` no cambia.
2. **Proveedor recomendado: Scaleway Transactional Email, plan de pago por uso**, a confirmar por el usuario tras verificar (`OPEN-079-01`): contrato de encargado y región de tratamiento en la UE; *webhook* o API de rebotes y quejas; límites de envío de una cuenta nueva y cómo se amplían; firma DKIM con dominio propio y `Return-Path` propio. Alternativa si falla alguno: Brevo. Si `ADR-083` elige a Scaleway para S3, hay un solo contrato de encargado para las dos piezas; si no, la recomendación no cambia.
3. **Dominio de envío**: subdominios dedicados del dominio de la plataforma (`OPEN-08`), nunca el ápice. El ápice de `adagiox.com` conserva los registros de Arsys de `ADR-073 §3` sin tocarlos.
   - **Dos corrientes en dos subdominios**: una para cuenta, seguridad y avisos individuales; otra para envío masivo (circulares). Una oleada de quejas por una circular no debe llevar a *spam* los códigos MFA. Los nombres concretos se fijan con `OPEN-08`.
   - **Por subdominio**: DKIM de 2048 bits; SPF con `-all` sobre el dominio del `Return-Path`, alineado con el `From`; DMARC propio con informes agregados (`rua`) a un buzón del proyecto, empezando en `p=quarantine` y pasando a `p=reject` tras 30 días sin fallos legítimos en los informes.
   - Los dominios que no envían correo mantienen MX nulo, `v=spf1 -all` y `p=reject` (`ADR-072 §4.3`).
4. **Remitente**: dirección de la plataforma (`no-responder@<subdominio>`), con el nombre del centro como nombre visible y `Reply-To` a la dirección de contacto del centro si la tiene configurada. El nombre visible lo escribe el centro: se valida (longitud, sin saltos de línea ni caracteres de control, sin direcciones ni dominios dentro) para impedir inyección de cabeceras y suplantación.
5. **Dominio propio de cada centro como remitente**: no está en los requisitos; no se hace (`OPEN-079-13`).
6. **Baja con un clic** (`List-Unsubscribe` y `List-Unsubscribe-Post`, RFC 8058) en toda la corriente masiva de categoría informativa o comercial (`§4.7`); no en cuenta, seguridad ni urgentes.
7. **Sin seguimiento de aperturas ni reescritura de enlaces.** El píxel de apertura es un rastreador que exige consentimiento propio, y reescribir enlaces rompe la alineación de dominio y enseña a las familias a pulsar enlaces que no son del centro. La «apertura» de `REQ-COM-003` se mide con la lectura en la aplicación y el acuse de `REQ-COM-002`. Es una interpretación de un requisito: `OPEN-079-07`.
8. **Rebotes y quejas**: un rebote permanente marca la dirección como no entregable, deja de enviársele y se avisa a quien administra los usuarios del centro. Esto importa más que la estadística: un usuario cuyo correo rebota no puede recuperar su contraseña. Una queja de *spam* da de baja al destinatario de la categoría del mensaje.
9. **Desarrollo, CI y E2E**: siguen con `log`/`array`. No se añade ningún servicio de correo de pruebas al `compose`.
10. **Staging** (revisión C2-01, en coherencia con `ADR-080 §3.6`): `MailEnvironmentGuard` impide arrancar con `log` o `array` fuera de `local`/`testing` (`apps/api/app/Support/Mail/MailEnvironmentGuard.php`, comprobado el 2026-11-13), y staging corre con `APP_ENV=production`. Staging usa por tanto **SMTP real al proveedor de `OPEN-079-01`**, en un subdominio de envío del dominio de staging, dentro de la franja gratuita del proveedor (precio sin verificar, `§8.1`). Sirve además para probar SPF, DKIM y DMARC antes de producción. El guard no se toca.

### 4.3 SMS (D4)

1. **No hay SMS en fase 1.** El valor `sms` existe en el enumerado de canales y no se escribe adaptador, interfaz vacía, proveedor ni variable de entorno, igual que decidió `REQ-AUTH §C.7`. `OPEN-AUTH-18` sigue abierta y `RN-AUTH-69` sigue impidiendo activar el método.
2. **Se decide con `REQ-PRL-001` (fase 2), en un ADR propio**, que elegirá proveedor de la UE. Condiciones que ese ADR hereda de este:
   - Solo a **teléfonos verificados** (código de confirmación enviado al propio número). `people.contact_phone` sin verificar no es destino.
   - Solo para categorías de seguridad de cuenta (MFA) y urgentes; nunca circulares ni informativos.
   - Tope por centro y medición por tenant (`REQ-SAAS-004`); el coste por mensaje es unas 200 veces el del correo (`§8`).
   - Alias alfanumérico de remitente **registrado en la CNMC** antes del primer envío. El bloqueo de alias no registrados estaba previsto para el 2026-06-07 y fue aplazado por la Orden TDF/558/2026; la fecha efectiva está **sin verificar**.
   - Texto sin datos personales del menor (`§4.11`).
3. **La llamada del conserje sigue siendo el canal garantizado** del aviso de recogida (`DP-5`): el aviso automático la complementa, no la sustituye (`§4.8`).

### 4.4 *Push* (D5)

1. **Web Push estándar con VAPID**, enviado desde el servidor. Sin Firebase y sin servicio intermedio.
2. **Dependencia nueva: `minishlink/web-push`**, tras una interfaz propia (`RNF-MANT-007`). Comprobado el 2026-11-12 en la API de GitHub: licencia MIT, última versión `v11.0.0` (2026-07-23), última actividad 2026-09-09, no archivado. **Sin verificar**: compatibilidad con PHP 8.4 y extensiones que exige; se comprueba al instalarla. Es la única dependencia nueva de este ADR.
3. **Requisitos previos en `apps/web`**: manifiesto de aplicación web y *service worker* del mismo origen. Hoy no existen; es trabajo de la entrega de *push*, no de `1.19`.
4. **Suscripción**: se pide permiso solo tras un gesto del usuario en la pantalla de preferencias, nunca al cargar. Se guarda por usuario y dispositivo en una tabla con `tenant_id`; las claves de la suscripción son secretos y van cifradas. Una respuesta 404 o 410 del servicio de *push* borra la suscripción.
5. **Claves VAPID**: un par de la plataforma, en el gestor de secretos, con procedimiento de rotación en `SYSADMIN.md`.
6. **Contenido**: título y texto genéricos más un enlace a la aplicación (`§4.11`). Aunque el contenido viaja cifrado, la notificación se muestra en la pantalla de bloqueo.
7. **Limitación conocida**: en iPhone y iPad solo llega a quien ha añadido la aplicación a la pantalla de inicio (iOS 16.4 o posterior). Por eso el *push* **nunca es el único canal** de ningún tipo de aviso, y el manual de familias debe explicar la instalación.
8. **Formato**: se envía el formato que entienden todos los navegadores; adoptar el formato declarativo de WebKit (iOS 18.4) se decide en la especificación.
9. FCM y APNs nativos llegan con la aplicación empaquetada de fase 3 (`ADR-008`) como dos adaptadores más del mismo canal.

### 4.5 Aviso en la aplicación (D6)

1. **Tabla de avisos en `Core`**, con `tenant_id` y RLS (`INV-001`), claves según `ADR-029`, destinatario, tipo, parámetros, enlace interno, instante de lectura y caducidad opcional.
2. **Se guarda la clave del tipo y sus parámetros, no el texto.** El texto se compone al leer, en el idioma actual del usuario (`INV-009`); cambiar de idioma cambia también los avisos antiguos. Excepción: el contenido que escribe el centro (circulares) se guarda como contenido, por idioma (`§4.6`).
3. **Los parámetros no llevan datos de categoría especial** (`§4.11`): el aviso enlaza al recurso y el recurso aplica sus permisos.
4. **Es el canal base**: todo aviso se crea en la aplicación aunque los demás canales fallen o estén desactivados. No se puede desactivar.
5. **Entrega a la SPA por sondeo** del recuento de no leídos (al cargar, al recuperar el foco y a intervalo). Sin WebSocket ni SSE en fase 1.
   - **Obligatorio (revisión C2-02, `REQ-AUTH-005`)**: el *endpoint* de recuento de no leídos **no refresca la marca de actividad** de la sesión. Hoy `EnforceSessionIdleTimeout` la refresca en toda petición autenticada (`apps/api/app/Http/Middleware/EnforceSessionIdleTimeout.php`, comprobado el 2026-11-13); se añade una excepción explícita y nominal para esa ruta: la petición **sí** comprueba la caducidad (si la sesión ha caducado responde `401`), pero no la prolonga. Test que referencia `REQ-AUTH-005`: con sondeos cada minuto y sin otra actividad, la sesión caduca a los 30 minutos.
   - Además, la SPA solo sondea con la pestaña visible (`document.visibilityState`). Esto ahorra peticiones, pero **no sustituye** la excepción anterior: una pestaña visible y desatendida tampoco debe mantener viva la sesión.
6. **API** (`INV-006`): listar, contar no leídos, marcar leído uno o todos. Solo el destinatario lee sus avisos (`INV-002`); el soporte de plataforma no los lee.
7. `RDB-001` cita «notificaciones» entre las tablas a particionar: el particionado y la retención los fija la especificación con `db-reviewer` (`OPEN-079-09`).

### 4.6 Plantillas e idiomas (D8)

1. **Avisos del producto** (cuenta, seguridad, urgentes, plataforma): plantillas del producto, en código y en los ficheros de idioma, en es-ES, en, de y fr. El centro personaliza marca (logotipo, nombre, pie), no el texto.
2. **Contenido del centro** (circulares y los avisos de `COM` que la especificación de `1.19` declare personalizables): se almacena por idioma (`ADR-021`), con marcadores de una **lista cerrada por tipo**, sustituidos como texto escapado. **Nunca se evalúa una plantilla escrita por un tenant con el motor de vistas** (sería ejecución de código en el servidor). El HTML del centro se sanea contra una lista de etiquetas permitidas.
3. **Idioma**: el del destinatario, que viaja en el *payload* del trabajo (skill `colas-y-workers`, regla 2). Si el centro no ha escrito su contenido en ese idioma, se envía en el idioma por defecto del centro, sin traducción automática.
4. **Textos legales, de consentimiento, de privacidad y de seguridad** de las plantillas: **traducción humana, sin automática** (`CLAUDE.md §2`, punto 9). Se redactan en es-ES y una persona los traduce a en, de y fr; ni la sesión ni `traductor` los traducen.
5. **Todo correo lleva versión de texto plano** además de HTML, y cumple WCAG 2.2 AA en lo aplicable (contraste, texto alternativo, no depender de imágenes).
6. Un mismo aviso produce un texto por canal: completo en la aplicación, sobrio en el correo, mínimo en *push* (`§4.11`).

### 4.7 Categorías, preferencias y base legal

El responsable del tratamiento es el centro; la plataforma es encargada y el proveedor de correo, subencargado (`RSEC-GDPR-008`). La columna de base legal es una **propuesta técnica pendiente de validación jurídica** (`OPEN-079-06`).

| Categoría | Ejemplos | Base legal propuesta | ¿El usuario puede desactivarla? |
|---|---|---|---|
| **Seguridad de la cuenta** | Código MFA, restablecer contraseña, acceso desde dispositivo nuevo, cambio de factor | Ejecución del servicio y seguridad del tratamiento (RGPD 6.1.b y 32) | No |
| **Urgente** | Persona no autorizada en la recogida, alumno no recogido, emergencia de `REQ-PRL-001` | Misión de interés público del centro (6.1.e, LOE DA 23.ª) e interés vital en emergencias (6.1.d) | No |
| **Obligación contractual o legal** (decidida por el usuario el 2026-11-13, revisión C2-03) | Preaviso de un adeudo SEPA y aviso de devolución de un adeudo (`ADR-081`); otros que un módulo declare con la misma justificación | Ejecución del contrato (6.1.b) u obligación legal del responsable (6.1.c), según el tipo; propuesta pendiente de `OPEN-079-06` | **No**, en ningún canal declarado por el tipo. Contenido mínimo declarado por tipo (regla 7) |
| **Servicio educativo** | Calificaciones, faltas, circulares, autorizaciones pendientes, recordatorios de firma (`ADR-082`), mensajes | Función educativa del centro (6.1.e) u obligación legal (6.1.c), según el caso | En la aplicación, no. Correo y *push*: sí, por categoría, salvo los tipos que el centro marque como de acuse obligatorio (`REQ-COM-002`) |
| **Administración de la plataforma** | Módulo contratado, mantenimiento, límites del plan | Ejecución del contrato con el centro (6.1.b) | En la aplicación, no. Correo: sí |
| **Informativa o comercial** | Noticias del centro, oferta de extraescolares o servicios de pago, novedades del producto | **Consentimiento previo** (LSSI art. 21, RGPD 6.1.a), salvo la excepción de relación contractual previa por servicios similares, que debe valorar un jurista | Desactivada por defecto. Baja en cada mensaje y con un clic |

Reglas:

1. **La categoría es del tipo de aviso y la declara el módulo** (`§4.1`); el centro no puede reclasificar un tipo. Enviar publicidad dentro de una circular «educativa» es un incumplimiento del centro como responsable: la pantalla de envío masivo obliga a elegir categoría y lo advierte.
2. **Preferencias por usuario** (`REQ-CORE-007`): por categoría y canal, dentro de lo que la tabla permite. El centro fija los valores por defecto de las categorías desactivables.
3. **El consentimiento de la categoría informativa o comercial se registra** con fecha, texto mostrado y versión (`RSEC-GDPR-001`), y su retirada también. Dónde vive ese registro (probablemente el de `REQ-PRIV`) lo fija la especificación.
4. **Menores como destinatarios** (cuentas de estudiante): solo aviso en la aplicación por defecto; correo y *push* a un menor quedan como pregunta (`OPEN-079-08`, `INV-008`, `RSEC-GDPR-009`).
5. **Quién recibe los avisos de un alumno** (todos los tutores, el principal, y qué pasa con custodias restringidas) lo decide `fam_unit` (`§4.1` punto 4). El motor no tiene una regla propia.
6. No se incluyen horas de silencio ni resúmenes agrupados: no están en los requisitos.
7. **Categoría «obligación contractual o legal»** (decidida por el usuario el 2026-11-13): el usuario no puede desactivarla porque el aviso es lo que cumple una obligación (p. ej. el preaviso SEPA). A cambio, **cada tipo de esta categoría declara en su descriptor su contenido mínimo** (`§4.1` punto 2), que es el único que lleva: importe, fecha, referencia (del mandato, del recibo o de la factura) y, si aplica, identificador de acreedor; **ningún dato del alumno** (`§4.11`, `INV-008`). Un módulo no puede meter en esta categoría un aviso que no responda a una obligación: el uso se justifica en la especificación del tipo y lo revisa `security-reviewer`. Los canales son los que declare el tipo; el aviso en la aplicación siempre.

### 4.8 Prioridad, colas y reintentos (D7)

**Tres prioridades**, declaradas por tipo:

| Prioridad | Para qué | Cola | Canales |
|---|---|---|---|
| **Urgente** | Categoría urgente y códigos de un solo uso | `notify-urgent`, la **primera** del `worker` | Todos los disponibles **a la vez**, sin esperar al fallo de uno para probar otro |
| **Normal** | Avisos individuales | `notify`, tras las colas `*-mail` actuales | Según preferencias |
| **Masiva** | Circulares y envíos a segmentos | `notify-bulk`, la **penúltima** del `worker`, solo por delante de `calif-pdf` (`ADR-078 §5.3`) | Según preferencias |

1. **Un trabajo por entrega** (un destinatario, un canal), corto: `timeout` muy por debajo del `retry_after` de la conexión y tiempo de espera de red explícito hacia el proveedor (`RARQ-ARC-005`). Un envío masivo es un trabajo que reparte en lotes, no un trabajo que envía a mil personas.
2. **Aislamiento del urgente, opción A ahora**: con colas por prioridad, un urgente espera como mucho a que acabe el trabajo en curso. **Disparadores para pasar al proceso dedicado `worker-urgent` (opción B)**, cualquiera de los tres: se implementa `REQ-PRL-001`; la espera en cola de `notify-urgent` supera el objetivo que el usuario fije (`OPEN-079-05`) en la medición real; o **llega el primer consumidor de PDF en lote** (`1.17`, `ADR-078`) y la medición de `ADR-078 §5.4` (que incluye la espera de `notify-urgent` con el lote de 1.200 documentos en marcha, hasta 60 s cada uno) no cumple ese objetivo (revisión C2-09). B es un cambio de despliegue, no de código.
3. **Toda cola nueva se añade a `worker.container` y a `compose.yaml`** en la misma entrega (`QueueWorkerTest`), y a la comprobación `core:queue-health`.
   **Orden único de colas del `worker`** (criterio común de la ola 1, 2026-11-13; el mismo en `ADR-078 §5.3` y en la operación de `REQ-CALIF` y `REQ-COM`): lo más urgente primero y los lotes al final, con un solo último puesto. Sobre las colas que existen hoy (comprobado el 2026-11-13 en `compose.yaml` e `infra/quadlet/worker.container`): `notify-urgent`, `auth-mail`, `core-mail`, `bo-mail`, `notify`, `default`, `auth-maintenance`, `core-maintenance`, `backoffice-maintenance`, `core-exports`, `core-imports`, `notify-bulk`, `calif-pdf`. Fijo: `notify-urgent` la primera, `notify` tras las colas `*-mail`, `notify-bulk` la penúltima y `calif-pdf` la última. `QueueWorkerTest` hoy comprueba que cada cola esté en un proceso, no su orden: se propone fijar estas tres posiciones (`§10`).
4. **Reintentos**: los que ya tiene el `worker` (3 intentos) con espera creciente para normal y masiva, y espera corta para urgente. Agotados, la entrega queda `fallida` y visible (`§4.9`); se reenvía desde soporte (`REQ-SUP-004`), nunca con `queue:retry` a ciegas.
5. **Cortacircuitos por canal**: si el proveedor falla de forma repetida, los trabajos se devuelven a la cola con retraso en vez de gastar sus intentos, y se alerta a operación. El aviso en la aplicación no depende de ningún proveedor y sale siempre.
6. **Idempotencia**: la fila de entrega se crea con una clave única (aviso, destinatario, canal) antes de encolar, y el trabajo solo envía si la entrega sigue pendiente. La cola entrega al menos una vez: se acepta un correo duplicado excepcional antes que uno perdido. El envío masivo acepta clave de idempotencia en su endpoint (`INV-011`).
7. **Tenant, idioma y secretos**: `tenantId` e idioma en el *payload*; ningún token ni código en claro (`ShouldBeEncrypted`, issues #73 y #75). Un trabajo de un centro `eliminado` se descarta sin enviar (`ADR-077 §5.4`).
8. **Programación de envíos** (`REQ-COM-003`) y **escalado del no recogido** (`1.14b-d4`) necesitan el planificador desplegado (issue #453): dependencia, no decisión de este ADR.
9. **Aviso de recogida (`1.14b-d3`)**: al abrirse la alerta, aviso urgente al tutor principal por todos los canales disponibles; la pantalla de conserjería muestra el estado de la entrega (enviado, entregado, leído) junto al teléfono; el protocolo sigue pidiendo la llamada y su registro manual. El aviso no cierra la alerta ni cuenta como contacto hecho.

### 4.9 Trazabilidad de entrega

1. **Una fila por entrega** (aviso × destinatario × canal), con `tenant_id` y RLS: estado, instantes, número de intentos, identificador del mensaje en el proveedor y motivo normalizado del fallo.
2. **Estados**: `pendiente` → `enviado` (aceptado por el proveedor) → `entregado` (confirmado por el proveedor, si lo confirma) o `rebotado` / `queja` / `fallido`; y `omitido` cuando no se envía por preferencias, falta de consentimiento, dirección no entregable o límite. `omitido` guarda el motivo: es la respuesta a «¿por qué no le llegó?».
3. **No se guarda el cuerpo del mensaje ni la dirección completa.** Se guarda a quién (persona o usuario) y un indicio enmascarado del destino para soporte. El contenido se puede recomponer desde el tipo y los parámetros.
4. **Recepción de eventos del proveedor**: un endpoint público de plataforma, con verificación de firma o secreto, idempotente, con límite de ritmo y sin datos personales en la respuesta. No hay sesión: el centro se fija explícitamente antes de buscar nada.
   - **Resolución del centro con RLS** (revisión C2-05): con RLS no se puede buscar la entrega sin conocer antes el centro. El identificador opaco propio que viaja en el mensaje lleva el **`public_id` del centro, el `public_id` de la entrega y un HMAC** de ambos con un secreto de la plataforma (mismo patrón que `ADR-081 §7.4`). El receptor verifica la firma del proveedor, después el HMAC, fija el centro y solo entonces busca la entrega. Un HMAC inválido no fija nada y no revela existencia.
   - **Condición sobre el proveedor (`OPEN-079-01`)**: que devuelva en sus eventos de rebote, queja y entrega una **cabecera o metadato propio** del mensaje original. Si no lo hiciera, la correspondencia «identificador del proveedor → centro» exige una **tabla de plataforma sin `tenant_id`**, que solo es admisible registrada como excepción en `ADR-077` (la registra allí el agente que lo corrige, no este ADR) y en el registro de tablas compartidas de `ADR-047`.
   - **Excepciones que declara este ADR** (revisión C2-08): el endpoint es una **excepción de CSRF** (los eventos llegan del proveedor, sin cookie ni token) y una ruta sin `permission:` (**excepción nominal de `AR-07a`**, `ADR-056`). La sustitución equivalente es la firma del proveedor más el HMAC del identificador. Grupo de rutas propio, sin cookies, con límite de peticiones; se documenta en `SECURITY.md` con el rigor de la del ACS SAML (`§2.1`), dentro de un apartado común de «excepciones de webhooks» que comparte con `ADR-081 §7.4` (`§10`).
5. **Lectura**: el instante de lectura del aviso en la aplicación y, para circulares, el acuse de `REQ-COM-002`. No hay «abierto» de correo (`§4.2` punto 7).
6. **Quién ve la traza**: el personal del centro con permiso propio, sobre los envíos de su centro; el soporte de plataforma ve estados y recuentos, no destinatarios, salvo por el mecanismo de acceso con propósito declarado de `ADR-046`.
7. **Retención**: propuesta de 12 meses para la traza de entrega y purga por lotes; pendiente de decisión (`OPEN-079-09`).

### 4.10 Límites y medición

1. **Por centro**: tope de destinatarios por envío masivo y de envíos por periodo, configurables por plan (`RNF-LIM-003`, `RMT-005`). Superarlo rechaza el envío con un error claro antes de encolar nada; nunca se trunca en silencio.
2. **Reparto justo**: el envío masivo de un centro se encola en lotes espaciados para que no ocupe la cola masiva entera delante de otros centros. La cola en base de datos no reparte por tenant por sí sola.
3. **Global**: ritmo de salida por debajo del límite del proveedor, con margen.
4. **Urgente y seguridad de cuenta no consumen cuota ni se limitan por plan.** Sí conservan los límites contra abuso que ya tiene `Auth`.
5. **Contadores** en Redis bajo el prefijo del centro (`t{tenant_id}:`, `ADR-033 §9`), que es además lo que la purga de `ADR-077` barre.
6. **Medición** (`REQ-SAAS-004`): se cuenta desde la traza de entregas por canal y centro; no hay contador aparte que pueda divergir.
7. **Simulación** (`REQ-PRL-001`): el motor admite un modo de prueba por envío que recorre todo el camino y marca la entrega como simulada sin llamar al proveedor.

### 4.11 Datos personales en el mensaje

Un correo, un SMS o una notificación en pantalla de bloqueo salen del control de acceso de la plataforma: los lee quien tenga el dispositivo. Reglas obligatorias:

1. **Avisar y enlazar.** El mensaje dice que hay algo y enlaza a la aplicación; el contenido se ve tras iniciar sesión, con sus permisos. Aplica a calificaciones, faltas, incidencias, pagos y documentos.
2. **Nunca datos de categoría especial** (salud, NEAE, convivencia; `CLAUDE.md §8`) en ningún canal externo, ni en el texto del aviso en la aplicación: solo dentro del recurso.
3. **Del menor, como mucho el nombre de pila, y solo en el cuerpo del correo y en la aplicación.** Nunca en el asunto del correo, en el título o texto del *push* ni en un SMS. Sin apellidos, grupo, fotografía ni documento.
4. **Sin adjuntos con datos personales.** Boletines, nóminas, facturas y certificados se envían como aviso con enlace a la descarga autenticada, no como PDF adjunto. Los requisitos dicen «envío automático por email»; esta lectura necesita confirmación (`OPEN-079-03`).
5. **Enlaces**: siempre al dominio del centro en la plataforma, sin *tokens* de sesión. Las URL firmadas de `ADR-076` (15 minutos) no van en correos: el enlace lleva a la aplicación y esta pide la URL firmada tras autenticar. Los enlaces de un solo uso que ya existen en `Auth` no cambian.
6. **Contenido libre del centro** (circulares): la regla no se puede imponer por código. La pantalla de redacción lo advierte y la documentación del centro lo recoge; la responsabilidad es del centro.
7. **Registros**: ni el cuerpo ni la dirección aparecen en logs (`MailEnvironmentGuard` existe por eso), ni en `failed_jobs` en claro.
8. **Proveedor**: recibe dirección, asunto y cuerpo. Con las reglas 1 a 4, lo que ve es mínimo. Se pide la retención de registros más corta que ofrezca y se anota en `PRIVACY.md`.

### 4.12 Lo que este motor no es

1. **Notificación fehaciente** (`REQ-CONV-002`, `ADR-082` `OPEN-FIRMA-17`): un correo ordinario con traza de entrega no prueba la recepción frente a terceros. Es un servicio de entrega electrónica certificada de un prestador de confianza. Se acepta la propuesta de `ADR-082`: se contrata con el prestador de firma y su aviso de «tiene una notificación» sí usa este motor (`OPEN-079-10`).
2. **Recordatorios y peticiones de firma** (`ADR-082`, que los remite a este ADR): son tipos de aviso de la categoría de servicio educativo, declarados por el módulo de firma; sin nada especial en el motor.
3. **Mensajería entre personas** (`REQ-COM-001`): la conversación vive en `Com`; el motor solo avisa de que hay un mensaje nuevo, sin su texto.

## 5. Motivo

1. **Motor en `Core` y no en `Com`.** El argumento es de dependencias, no de gusto: `Auth` necesita correo para existir y `prl` necesita avisar de un menor; ninguno puede depender de un módulo que un centro puede no tener contratado. `REQ-CORE-007` ya sitúa las notificaciones del sistema en `Core` y la entidad `Notification` figura entre las suyas. `Com` conserva todo lo que es producto.
2. **Motor propio y no `Illuminate\Notifications`.** Lo que el *framework* ahorra es el enrutado por canal, que es poco código; lo que cuesta es una tabla que incumple `ADR-029` y no tiene `tenant_id`. El patrón de correo que ya funciona (`Mailable` + *job*) se conserva íntegro.
3. **SMTP como contrato.** Es lo único que hace reversible la elección de proveedor: el riesgo de equivocarse con Scaleway se reduce a una tarde de DNS y variables de entorno. Por eso se puede recomendar un proveedor con cuatro puntos sin verificar: equivocarse es barato.
4. **Proveedor de la UE.** El cuerpo de los correos lleva direcciones de familias y nombres de pila de menores. Es el mismo criterio que el proyecto aplica al almacenamiento (`ADR-076`, `ADR-083`), y evita explicar una transferencia internacional a cada centro.
5. **Sin SMS todavía.** No hay consumidor en fase 1 que lo exija por requisito (`REQ-PRL-004` pide «aviso», no SMS), los teléfonos no están verificados, cuesta dos órdenes de magnitud más que el correo y obliga a un registro administrativo. Contratar ahora es pagar complejidad sin beneficio proporcional. Lo que protege al menor hoy es la llamada del conserje, que ya existe.
6. **Web Push estándar.** Es la única opción de *push* sin un contrato con un proveedor de *push* propio ni un SDK de terceros en la SPA. No es «sin intermediario»: el servicio de push de cada navegador (en Chrome, infraestructura de Google/FCM) transporta el mensaje; la carga útil va cifrada (RFC 8291), pero ese servicio recibe metadatos (punto de entrega, momento y tamaño). Por eso los servicios de *push* figuran como subencargados en `OPEN-079-15`. Su limitación en iOS es real y por eso nunca es canal único; aun así, es el único canal inmediato que llega con la aplicación cerrada sin coste por mensaje.
7. **Sondeo y no WebSocket.** Nada en fase 1 necesita menos de un minuto de latencia en la aplicación abierta; el urgente ya va por correo y *push*. Un servicio de tiempo real se justificará con el panel de `REQ-PRL-001`, no antes.
8. **Colas por prioridad y no proceso dedicado, por ahora.** El peor caso de espera es acotado y conocido (un trabajo en curso), y el paso al proceso dedicado es un cambio de despliegue con disparador escrito. Se prioriza lo reversible sobre lo óptimo.
9. **Avisar y enlazar.** Es la única regla que hace compatibles los requisitos de «enviar por correo» con `CLAUDE.md §8`: el correo no tiene control de acceso, ni caducidad, ni auditoría de lectura.
10. **Sin píxel de apertura.** Mide mal (los clientes de correo precargan o bloquean imágenes) y añade un tratamiento que necesita consentimiento. El acuse en la aplicación mide lo que el centro quiere saber: quién lo ha leído.

---

## 6. Consecuencias

### 6.1 Buenas

- `OPEN-09` deja de bloquear por indefinición: queda una contratación con requisitos escritos.
- `1.19`, `1.14b-d3` y `-d4` tienen un contrato común. `prl` deja de depender de `com` para avisar.
- Un solo punto para preferencias, límites, trazabilidad, medición y la regla de datos personales.
- Cambiar de proveedor de correo, o añadir SMS, FCM o APNs, no toca ningún módulo.
- La purga de tenant (`ADR-077`) alcanza todo lo nuevo sin registrar excepciones: tablas con `tenant_id`, contadores bajo el prefijo del centro, `tenantId` en el *payload*.

### 6.2 Malas y costes

- `Core` gana responsabilidad y tablas de mucho crecimiento (una fila por entrega).
- **`1.19` no cabe en una entrega** (`CLAUDE.md §2`, punto 7): motor, aviso en la aplicación, preferencias y traza son una entrega; mensajería y circulares, otra u otras. Se propone partirla (`§10`). El *push* es una entrega aparte, porque exige manifiesto y *service worker* (`OPEN-079-11`).
- Una dependencia nueva (`minishlink/web-push`) cuando llegue el *push*.
- Un endpoint público nuevo (eventos del proveedor): superficie de ataque que revisar como paso crítico.
- Dos formas de enviar correo conviven mientras los `Send*Email` antiguos no se migren (`OPEN-079-12`).
- La «apertura» de `REQ-COM-003` y el «envío por email» de boletines, nóminas y facturas se cumplen con una lectura que el usuario debe confirmar (`OPEN-079-07`, `OPEN-079-03`).
- `REQ-AUTH-003` sigue sin su tercer método (`OPEN-AUTH-18`).
- En iPhone el *push* llega solo a quien instale la aplicación: hay que explicarlo en el manual de familias y no prometer más.

### 6.3 Invariantes y seguridad

| Regla | Cómo se cumple |
|---|---|
| `INV-001` | Todas las tablas nuevas con `tenant_id` y RLS; `tenantId` en el *payload*; el endpoint de eventos fija el centro explícitamente; contadores con prefijo del centro |
| `INV-002` | Cada usuario solo lee sus avisos; la traza tiene permiso propio; el envío masivo, también |
| `INV-003` | Envío masivo, circular y cambios de preferencias y consentimiento, auditados; la entrega individual queda en la traza |
| `INV-006` | Avisos, preferencias, suscripciones de *push* y traza, por API documentada antes que por pantalla |
| `INV-007` | Los módulos solo conocen el contrato de `Core`; la audiencia la resuelve el dueño del dato por su contrato |
| `INV-008` | Sin datos del menor fuera de la aplicación más allá del nombre de pila; menores como destinatarios, restringidos (`OPEN-079-08`) |
| `INV-009` | Clave y parámetros, no texto; cuatro idiomas; idioma del destinatario en el *payload* |
| `INV-011` | Clave única por entrega; clave de idempotencia en el envío masivo |
| `INV-012` | Todo envío externo en cola; reparto masivo en lotes |
| `INV-015` | Cada tipo de aviso con test que referencie el requisito; test de que un tipo no declarado no se envía y de que ninguna plantilla externa admite parámetros de categoría especial |
| `CLAUDE.md §8` | Secretos (SMTP, VAPID, secreto de eventos) en el gestor; claves de suscripción cifradas; el *service worker* y el *push* no relajan la CSP; nada de categoría especial en el mensaje |
| `CLAUDE.md §9` | Colas nuevas compatibles con trabajos de la versión anterior; sin estado en disco; sin proceso nuevo en fase 1 |

### 6.4 Relación con `ADR-077` (purga de tenant)

- Tablas nuevas con `tenant_id`: las barre el catálogo. Ninguna tabla de plataforma nueva, **salvo** la correspondencia de `§4.9` punto 4 si el proveedor de correo no devuelve metadato propio en sus eventos; en ese caso, excepción registrada en `ADR-077` y `ADR-047`.
- Trabajos con `tenantId` en el *payload*, en la misma posición que los actuales, para que la purga de `jobs` y `failed_jobs` los encuentre.
- Contadores y cortacircuitos por centro bajo `t{tenant_id}:`. El cortacircuitos **global** por canal va bajo el prefijo base y no contiene datos de ningún centro.
- **Datos en terceros**: el proveedor de correo conserva registros y listas de supresión con direcciones; los servicios de *push* conservan los puntos de entrega. La purga debe pedir su borrado o documentar su caducidad: es exactamente el caso que `ADR-077` deja previsto para recursos de terceros. Se propone a `ADR-077` que lo recoja (`§10`).

---

## 7. Alternativas descartadas y por qué

1. **Motor en `Com` con eventos de todos los módulos** (D1-C): invierte dependencias y deja los avisos de seguridad a merced de la contratación de un módulo.
2. **Seguir con un *job* de correo por módulo** (D1-A): no hay dónde poner preferencias, límites ni trazabilidad; a 53 módulos, una cola por módulo en un `worker` es inmanejable.
3. **Servicio externo de notificaciones** (D1-D): un encargado o un servicio más, con el aislamiento por tenant fuera de PostgreSQL.
4. **`Illuminate\Notifications` y canales de comunidad** (D2-A, D2-C): tabla no conforme a `ADR-029`, sin `tenant_id`.
5. **Amazon SES** (D3-D): proveedor estadounidense para datos de familias y menores, cuando hay alternativa europea a coste despreciable.
6. **Servidor de correo propio** (D3-E): la entregabilidad es un trabajo continuo que una persona sola no puede sostener; el fallo es silencioso (correo en *spam*).
7. **SMTP del buzón de Arsys** (D3-F): no es un servicio transaccional y mezcla reputaciones.
8. **SMS ya** (D4-B, D4-C): sin requisito de fase 1 que lo exija, sin teléfonos verificados y con coste por mensaje.
9. **Interfaz o adaptador vacío de SMS «para dejarlo preparado»**: es inventar una decisión no tomada (`REQ-AUTH/operacion.md §C.2.1`).
10. **Firebase o un servicio de *push* de terceros** (D5-B, D5-C): encargado y SDK adicionales sin resolver la limitación de iOS.
11. **Esperar a fase 3 para el *push*** (D5-D): los requisitos lo piden antes.
12. **WebSocket o SSE en fase 1** (D6-B, D6-C): servicio nuevo sin requisito que lo necesite todavía.
13. **Redis + Horizon para priorizar** (D7-C): dependencia no instalada que no resuelve nada que no resuelva un segundo proceso.
14. **Canales en cascada para el urgente** (probar *push*, luego correo): ahorra mensajes a costa de minutos en el único caso en que importan.
15. **Editor de plantillas evaluadas por el motor de vistas** (D8-C): ejecución de código escrito por un tenant.
16. **Píxel de apertura y reescritura de enlaces**: `§5` punto 10.
17. **Adjuntar el PDF al correo**: saca el documento del control de acceso y de la auditoría de lectura.
18. **Guardar el texto ya compuesto del aviso**: fija el idioma del momento del envío y duplica datos personales.
19. **Tabla global «identificador del mensaje → centro»** como primera opción para los eventos del proveedor: dato de tenant sin `tenant_id`, invisible a la purga si no se registra. Solo se admite como último recurso y registrada (`§4.9` punto 4).
20. **Dominio propio de cada centro como remitente**: no está en los requisitos y multiplica la gestión de DNS por centro.

---

## 8. Coste

Precios consultados el 2026-11-12 (`§11`), antes de impuestos. **Los de Scaleway son indirectos y están sin verificar** (las páginas oficiales devolvieron 403; `§11.1`): las cifras de `§8.1` valen como orden de magnitud, no como precio. **Los volúmenes son hipótesis de trabajo, no datos**: no hay ningún centro en producción.

### 8.1 Correo (Scaleway, pago por uso: 0,25 € por 1.000 tras 300 gratis; indirecto, sin verificar)

| Hipótesis | Correos al mes | Coste al mes |
|---|---|---|
| Desarrollo y pruebas con dominio real | < 300 | 0 € |
| Un centro, 1.000 cuentas, 40 correos por cuenta | 40.000 | ≈ 9,9 € |
| Cinco centros iguales | 200.000 | ≈ 49,9 € |

El plan Scale (80 €/mes, 100.000 incluidos, 0,20 € por 1.000) no es más barato hasta ≈ 1,2 millones de correos al mes; antes de eso solo se justifica por la IP dedicada y el SLA. Cada destinatario en copia cuenta como un correo.

### 8.2 SMS (referencia: OVHcloud a España, ≈ 0,049 € por SMS en el paquete de 1.000 créditos)

| Hipótesis | SMS | Coste |
|---|---|---|
| Un aviso de emergencia a 1.000 teléfonos | 1.000 | ≈ 49 € por aviso |
| MFA por SMS: 50 empleados, 20 accesos al mes | 1.000 | ≈ 49 € al mes por centro |

Un SMS cuesta lo que unos 200 correos. Por eso el tope por centro y la medición son condición de `§4.3`.

### 8.3 Resto

- *Push* y aviso en la aplicación: 0 € de proveedor.
- Dominio y DNS: sin coste adicional sobre `OPEN-08`.
- Desarrollo: motor, aviso en la aplicación, preferencias, traza y eventos del proveedor caben en una entrega de hasta 3 tramos de `implementer` (estimación sin medir); el *push*, otra más corta.
- Mantenimiento a 3 años: informes DMARC que alguien tiene que leer, rotación de claves DKIM y VAPID, reputación del dominio y altas de alias si llega el SMS. Es trabajo recurrente de operación, pequeño pero real: va a `RUNBOOK.md`.

---

## 9. Preguntas abiertas

**Decidida por el usuario (2026-11-12)**: `OPEN-079-04`, según la recomendación de este ADR: el motor vive en `Core` y el aviso de recogida de menores lo dispara un *listener* del propio `prl`, no `Com`. Esto matiza la recomendación aprobada de `OPEN-FAM-UNIT-33` («consumido por `COM`»): un centro sin `com` contratado sigue recibiendo avisos de recogida. El ADR continúa en `PROPUESTA` mientras queden abiertas las demás.

**Decididas por el usuario (2026-11-12), según la recomendación**: `OPEN-079-01` (proveedor de correo: Scaleway Transactional Email, con Brevo de reserva; precios sin verificar), `OPEN-079-07` (la «apertura» se mide por lectura y acuse en la aplicación, sin píxel de seguimiento) y `OPEN-079-03` (boletines, nóminas y facturas se envían como enlace a descarga autenticada, nunca como adjunto).

**Decidida por el usuario (2026-11-13), revisión C2-03**: se añade la categoría de aviso «obligación contractual o legal», no desactivable por el usuario y con contenido mínimo declarado por tipo, sin datos del alumno (`§4.7`, regla 7). `ADR-081` declara con ella el preaviso SEPA y el aviso de devolución.

### 9.1 Bloquean

| ID | Pregunta | Recomendación | Impacto | Bloquea | Decide |
|---|---|---|---|---|---|
| `OPEN-079-01` | Proveedor de correo (sustituye a `OPEN-09`) | Scaleway Transactional Email, pago por uso, tras verificar contrato de encargado y región, eventos de rebote y queja **que devuelvan una cabecera o metadato propio del mensaje original** (`§4.9` punto 4), límites de cuenta nueva y DKIM con dominio propio. Si falla alguno, Brevo | Sin proveedor no sale ningún correo real | `0.10c` y la operación en producción. **No** la implementación (contrato SMTP) | Usuario |
| `OPEN-079-02` | Dominio y subdominios de envío | Dos subdominios del dominio de la plataforma (individual y masivo), nunca el ápice | Depende de `OPEN-08` | `0.10c` | Usuario |
| `OPEN-079-04` | Motor en `Core` y avisos de `prl` sin depender de `com` (matiza la recomendación aprobada de `OPEN-FAM-UNIT-33`) | Sí: el *listener* es de `prl` y llama al contrato de `Core` | Si se mantiene «consumido por `COM`», hay que declarar `com` esencial o aceptar que un centro sin `com` no recibe avisos de recogida | `1.19` y `1.14b-d3` | Usuario |
| `OPEN-079-07` | «Estadísticas de apertura» sin píxel de seguimiento | Medir lectura en la aplicación y acuse; sin píxel ni reescritura de enlaces | Con píxel: consentimiento propio y peor entregabilidad | Estadísticas de `1.19` | Usuario, con criterio legal |
| `OPEN-079-09` | Retención de avisos y de traza de entrega | 12 meses, purga por lotes | Tamaño de tablas y deber de minimización | `datos.md` de `1.19` | Usuario, con criterio legal |
| `OPEN-079-05` | Objetivo de demora del aviso urgente | Del hecho a la entrega al proveedor, percentil 95 por debajo de 60 s; colas por prioridad ahora y `worker-urgent` si no se cumple o al llegar `REQ-PRL-001` | Un objetivo más estricto obliga ya al proceso dedicado | Criterios de aceptación de `1.14b-d3` | Usuario |
| `OPEN-079-03` | Boletines, nóminas y facturas «por email»: adjunto o enlace | Aviso con enlace a descarga autenticada; nunca adjunto | Con adjunto: datos personales fuera del control de acceso | Especificaciones de esos módulos; no `1.19` | Usuario, con criterio legal |
| `OPEN-079-06` | Bases legales de `§4.7` y alcance de la excepción de LSSI art. 21 | Validar la tabla con un jurista; mientras, la categoría informativa o comercial solo con consentimiento previo | Envíos comerciales sin base válida son sancionables | Solo el uso de la categoría informativa o comercial | Legal |
| `OPEN-079-15` | Subencargados (proveedor de correo; servicios de *push*) en el contrato con los centros | Listarlos en `PRIVACY.md` y en el contrato de encargado | Depende de `OPEN-07` | Datos reales | Usuario y legal |

### 9.2 No bloquean

| ID | Pregunta | Recomendación | Impacto | Decide |
|---|---|---|---|---|
| `OPEN-079-08` | Correo y *push* a cuentas de estudiantes menores | Solo aviso en la aplicación; decidir con `REQ-EST` | `1.23` | Usuario y legal |
| `OPEN-079-10` | Notificación fehaciente de `REQ-CONV-002` (`OPEN-FIRMA-17`) | Entrega certificada del prestador de `ADR-082`; este motor solo da el aviso | Fase 2 | Usuario |
| `OPEN-079-11` | Cuándo entra el *push* | Entrega propia tras el motor; `1.14b-d3` no la espera y gana el canal cuando exista | Plan | Usuario |
| `OPEN-079-12` | Migrar los `Send*Email` existentes al motor | Al tocar cada uno; sin entrega de refactorización | Dos caminos de correo conviven | Técnico |
| `OPEN-079-13` | Dominio propio del centro como remitente | No: no está en los requisitos | — | Negocio |
| `OPEN-079-14` | SMS para el aviso de recogida ya en fase 1 | No; la llamada del conserje es el canal garantizado | Adelantaría proveedor, verificación de teléfonos y alias | Usuario |
| `OPEN-079-16` | Formato declarativo de Web Push y versiones mínimas de navegador | Decidir en la especificación del *push* | — | Técnico |

---

## 10. Cambios propuestos en ficheros compartidos

No se ha tocado ninguno. Los aplica la sesión orquestadora al integrar.

| Fichero | Cambio |
|---|---|
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md §18` | Fila `ADR-079` en el índice (estado `PROPUESTA`). En «Decisiones abiertas vivas», `OPEN-09` pasa a: «Reformulada por `ADR-079`: contrato y requisitos decididos; queda la contratación (`OPEN-079-01`) y el dominio (`OPEN-079-02`, `OPEN-08`)». Historial de versiones |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md §5.12` y `§5.35` (si el usuario acepta) | Nota en `REQ-COM-003`: la apertura se mide por lectura y acuse (`OPEN-079-07`). Nota en `REQ-PRL`: `REQ-PRL-004` no depende de `COM` para avisar, sino del motor de `REQ-CORE-007` |
| `PLAN-IMPLEMENTACION.md` | `0.10c`: remitir a `ADR-079 §4.2` y `OPEN-079-01/02`. `1.19`: partir en motor (`REQ-CORE-007`: contrato, aviso en la aplicación, preferencias, traza, eventos del proveedor) y, después, mensajería y circulares; añadir una entrega de *push* (manifiesto, *service worker*, suscripciones). `1.14b-d3`: depende de la entrega del motor, no de todo `1.19` |
| `ARCHITECTURE.md` | Apartado del motor de notificaciones en `Core`: contrato, tipos declarados por módulo, canales, prioridades y colas |
| `SYSADMIN.md` | Registros DNS de los subdominios de envío (SPF, DKIM, DMARC) y su comprobación; variables `MAIL_*` del proveedor; colas `notify-urgent`, `notify`, `notify-bulk` en el orden único de `§4.8` punto 3; claves VAPID y su rotación; secreto del endpoint de eventos |
| `RUNBOOK.md` | Proveedor de correo caído; subida de rebotes o quejas; dominio en lista negra; lectura de informes DMARC; reenvío de entregas fallidas |
| `SECURITY.md` | Endpoint público de eventos del proveedor como **excepción de CSRF y de `AR-07a`** declarada por este ADR (`§4.9` punto 4), en un apartado común de «excepciones de webhooks» junto a la de `ADR-081 §7.4`; `§2.1` deja de decir «primera y única excepción». Regla de datos personales en mensajes (`§4.11`); actualizar la mención de `OPEN-09` (l. 84) |
| `PRIVACY.md` | Proveedor de correo como subencargado; servicios de *push* de los navegadores; categorías y bases legales (pendientes de `OPEN-079-06`); retención de la traza |
| `CLAUDE.md §1` | Cuando se implemente: fila «Colas» con las colas nuevas; fila nueva «Notificaciones» (motor en `Core`, SMTP a proveedor de la UE, Web Push) |
| `.claude/skills/colas-y-workers` | Cuando se implemente: tabla de colas en el orden único de `§4.8` punto 3 y la regla «un trabajo por entrega» |
| `apps/api/tests/Feature/Core/QueueWorkerTest.php` (lo escribe la entrega que añada las colas) | Fijar en `compose.yaml` y `worker.container` que `notify-urgent` es la primera, `notify-bulk` la penúltima y `calif-pdf` la última |
| `.claude/skills/datos-personales`, `i18n-cuatro-idiomas` | Regla «avisar y enlazar» y regla de plantillas del centro sin motor de vistas |
| `README.md`, `CHANGELOG.md`, `memory.md` | Tabla de versiones, entrada de la ola, y `OPEN-09` reformulada en la lista de abiertas |
| `ADR-077` (rama de A1, no es fichero compartido) | Añadir a su regla de recursos de terceros el proveedor de correo (registros y lista de supresión) y los puntos de entrega de *push* |
| `REQ-AUTH/funcional.md`, `REQ-CORE/funcional.md`, `REQ-BO/operacion.md` | `OPEN-AUTH-07`, `OPEN-CORE-04` y las menciones de `0.10c` apuntan a `OPEN-079-01` |

**Hallazgos fuera de mi ámbito** (no corregidos, para la orquestadora):

1. `apps/web` no tiene manifiesto ni *service worker*: «PWA en fase 1» (`ADR-008`) no está construida ni figura como paso en el plan que he leído. No es una contradicción todavía (la fase 1 no está cerrada), pero ningún paso la recoge.
2. La tabla de módulos de requisitos (l. 399) declara que `REQ-PRL` depende de `COM`; con este ADR `REQ-PRL-004` no lo necesita. Se propone la nota de arriba, no se ha editado.

---

## 11. Fuentes y lo no verificado

### 11.1 Fuentes (consultadas el 2026-11-12)

| Dato | Fuente | Fiabilidad |
|---|---|---|
| Precios de Scaleway Transactional Email | Resumen de búsqueda sobre `scaleway.com/en/pricing/managed-services/` y `scaleway.com/en/transactional-email-tem/` | **Indirecta**: las dos páginas y la documentación devolvieron 403 a la lectura directa |
| Precios y planes de Mailjet | `mailjet.com/pricing/`, leída directamente (cifras en USD) | Directa |
| Precios de Brevo | Solo webs de terceros; `brevo.com/pricing/` no devolvió cifras | **Sin verificar** |
| Precio de SMS a España | `ovhcloud.com/es-es/sms/prices/`, leída directamente | Directa |
| Requisitos de Gmail para remitentes | `support.google.com/a/answer/81126`, leída directamente | Directa |
| Web Push en iOS desde 16.4, solo con aplicación en pantalla de inicio y tras gesto del usuario | `webkit.org/blog/13878/` | Directa |
| Web Push declarativo (iOS y iPadOS 18.4) | `webkit.org/blog/16535/` | Directa |
| `minishlink/web-push` (`web-push-libs/web-push-php`): MIT, `v11.0.0` del 2026-07-23, actividad hasta 2026-09-09 | API de GitHub | Directa |
| Registro de alias de SMS de la CNMC y aplazamiento del bloqueo (Orden TDF/558/2026) | Resumen de búsqueda sobre notas de `cnmc.es` | **Indirecta** |
| Estado del código (colas, correo, `apps/web`, `composer.json`, `laravel/framework` `v13.40.0` en `composer.lock`) | Repositorio, rama `develop` en `b7995bc` | Directa |
| `MailEnvironmentGuard` (rechaza `log`/`array` fuera de `local`/`testing`), `EnforceSessionIdleTimeout` (refresca `pge_last_activity_at` en cada petición autenticada) y `QUEUE_CONNECTION=database` en `apps/api/.env.example` (consultado el 2026-11-13) | Repositorio, rama `develop` en `786c94e` | Directa |

### 11.2 Sin verificar

1. Scaleway: eventos de rebote y queja por *webhook*, región de tratamiento, texto del contrato de encargado, límites de envío de una cuenta nueva, `Return-Path` propio.
2. Brevo: precios, alojamiento, IP dedicada, precio de SMS a España.
3. Estructura societaria de Mailjet/Sinch y su efecto en transferencias internacionales.
4. Fecha efectiva del bloqueo de alias de SMS no registrados y texto de la Orden TDF/558/2026 en el BOE.
5. Requisitos vigentes de Yahoo y Microsoft para remitentes (solo fuentes de terceros).
6. Compatibilidad de `minishlink/web-push` `v11` con PHP 8.4 y extensiones que exige.
7. Que el píxel de apertura exija consentimiento: es el criterio que este ADR asume a partir de las directrices del CEPD sobre el artículo 5.3 de la Directiva de privacidad electrónica, **no consultadas** en esta sesión.
8. Toda la columna de base legal de `§4.7`, la cita de la LOE y el alcance de la excepción del artículo 21 de la LSSI: propuesta técnica sin validación jurídica.
9. Los volúmenes de `§8` son hipótesis.
10. Qué expone hoy `fam_unit` para resolver «tutor principal» y «todos los tutores» con custodias restringidas: no se ha leído su contrato; lo comprueba la especificación.
11. Que `core:queue-health` y `QueueWorkerTest` acepten las colas nuevas sin más cambio que la lista.
12. Que el servicio de push de Chrome sea infraestructura de Google/FCM y qué metadatos exactos reciben los servicios de push de cada navegador (afirmación del revisor C2-12; no consultada en documentación oficial en esta sesión).
13. Que Scaleway (o Brevo) devuelva en sus eventos una cabecera o metadato propio del mensaje original (`§4.9` punto 4, `OPEN-079-01`).
14. La franja gratuita del proveedor para el correo de staging (`§4.2` punto 10).
