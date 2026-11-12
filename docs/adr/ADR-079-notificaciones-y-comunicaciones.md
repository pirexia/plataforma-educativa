# ADR-079 · Notificaciones y comunicaciones salientes

**Estado**: PROPUESTA
**Fecha**: 2026-11-12
**Autor**: `architect` (agente A3 de la ola 1, `docs/proceso/ola1-arquitectura-2026-11.md`)
**Afecta a**: `REQ-CORE-007`, `REQ-COM-001..004`, `REQ-AUTH-001/003`, `REQ-PRL-001/004`, `REQ-FAM-UNIT`, `REQ-AUT-001`, `REQ-SAAS-004`, `REQ-SUP-004`, `RNF-LIM-003`, `RARQ-ARC-005`, `RDB-001`, `RSEC-GDPR-001/008/009`, `INV-001`, `INV-003`, `INV-007`, `INV-008`, `INV-009`, `INV-011`, `INV-012`.
**Reformula**: `OPEN-09` (proveedor de correo transaccional): la parte técnica queda decidida aquí; queda la contratación (`OPEN-079-01`).
**No sustituye ningún ADR.** Precisa, sin cambiarlos, `ADR-045 §4.8` (aviso de módulo contratado), `ADR-008` (PWA en fase 1) y `ADR-073 §3` (correo del ápice de la landing). Roza la recomendación aprobada de `OPEN-FAM-UNIT-33` («evento de `prl` consumido por `COM`»): véase `§4.1` y `OPEN-079-04`.
**Relación con la ola 1**: `ADR-077` (purga de tenant: condiciona tablas, colas y Redis, `§6.4`), `ADR-082` (firma: recordatorios y notificación fehaciente, `§4.12`), `ADR-083` (proveedor S3: posible proveedor común, `§4.2`). Los tres están en `PROPUESTA` y se han leído en sus ramas el 2026-11-12.

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
| **A. Scaleway Transactional Email** (Francia) | Empresa y jurisdicción de la UE; pago por uso sin cuota; plan superior con IP dedicada y SLA cuando haga falta; posible proveedor común si `ADR-083` elige su S3 | Producto más joven que los especializados; **sin verificar**: *webhooks* de rebote y queja, límites de envío de una cuenta nueva, región y texto del contrato de encargado (la documentación devolvió 403 al consultarla) | Esencial: 0 €/mes, 300 correos incluidos, 0,25 € por 1.000 adicionales. Scale: 80 €/mes con 100.000 incluidos, 0,20 € por 1.000, IP dedicada y SLA 99,9 % (antes de impuestos) | Bajo: variables `MAIL_*` y registros DNS |
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
| **A. Web Push estándar** (Push API + VAPID, RFC 8030/8291/8292), enviado desde el servidor con `minishlink/web-push` tras interfaz propia | Sin proveedor intermedio ni SDK en la SPA; el contenido viaja cifrado de extremo a extremo hasta el navegador; coste 0 €; sirve a Android, escritorio e iOS | En iPhone y iPad solo funciona si la familia añade la aplicación a la pantalla de inicio (iOS 16.4+): alcance real limitado; exige manifiesto y *service worker* en `apps/web`, que hoy no existen; una dependencia nueva | Bajo: se retira el canal y quedan correo y aviso en la aplicación |
| **B. Firebase Cloud Messaging para web** | Un único SDK, luego reutilizable en la aplicación de fase 3 | Google como encargado desde fase 1 y SDK de terceros en la SPA (choca con la CSP estricta de `CLAUDE.md §8`); no evita la limitación de iOS | Medio |
| **C. Servicio de terceros** (OneSignal, Pushpad) | Panel y segmentación hechos | Encargado adicional con identificadores de dispositivo de familias; coste recurrente | Medio |
| **D. Sin *push* hasta la aplicación empaquetada de fase 3** | Cero trabajo | `REQ-COM-003` y `REQ-AUT-001` piden *push* en fases 1-2; el aviso de recogida se queda en correo y aplicación abierta | — |

**Recomendación: A**, en entrega propia posterior a `1.19` (`§6.2`), nunca como único canal.

### 3.6 D6 · Cómo llega el aviso a la aplicación abierta

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **A. Sondeo**: la SPA pide el recuento de no leídos al cargar, al recuperar el foco y cada cierto intervalo | Sin estado en servidor, sin servicio nuevo, compatible con «aplicación stateless» (`CLAUDE.md §9`) | Latencia de hasta un intervalo; peticiones periódicas por usuario conectado | Nulo |
| **B. Server-Sent Events** | Casi inmediato, HTTP normal | Una conexión PHP abierta por usuario: no encaja con el servidor de aplicación actual sin un proceso aparte | Medio |
| **C. WebSocket** (Laravel Reverb) | Tiempo real; serviría al panel de recepción de `REQ-PRL-001` | Servicio nuevo que desplegar, vigilar y asegurar por tenant; dependencia nueva | Medio-alto |

**Recomendación: A.** C se reevalúa con `REQ-PRL-001` (fase 2), que es el primer requisito que pide tiempo real.

### 3.7 D7 · Prioridad del aviso urgente en la cola

| Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|
| **A. Colas por prioridad en el `worker` actual** (`notify-urgent` la primera; `notify-bulk` la última) y un trabajo por entrega, corto | Sin procesos nuevos; un cambio en dos ficheros de despliegue | El urgente espera a que termine el trabajo en curso: segundos normalmente, hasta el `--timeout` de 120 s en el peor caso (una exportación) | Nulo |
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

_Pendiente._

## 5. Motivo

_Pendiente._

## 6. Consecuencias

_Pendiente._

## 7. Alternativas descartadas y por qué

_Pendiente._

## 8. Coste

_Pendiente._

## 9. Preguntas abiertas

_Pendiente._

## 10. Cambios propuestos en ficheros compartidos

_Pendiente._

## 11. Fuentes y lo no verificado

_Pendiente._
