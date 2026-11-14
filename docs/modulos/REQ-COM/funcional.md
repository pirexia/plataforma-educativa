# REQ-COM · Comunicaciones · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-COM` (módulo `com`); la entrega `1.19a` es de `REQ-CORE-007` (módulo `core`) |
| Prioridad | MUST (`REQUISITOS §5.12`) |
| Fase | 1 |
| Depende de | `core` (`REQUISITOS §5.12`). `depends_on` del descriptor: ver `§7` y `OPEN-COM-05` |
| Esencial | No (`essential: false`). El motor de avisos vive en `core`, que sí lo es (`ADR-079 §4.1`) |
| Estado | **PROPUESTO** (2026-11-13, agente B7 de la ola 1). No se implementa nada hasta que el usuario lo apruebe y se decidan las `OPEN-COM-*` bloqueantes |
| Clasificación del paso | **crítico** en las siete entregas (`ADR-069 §4.2`; motivo por entrega en `§0.3`) |

## 0. Estado, fuentes y partición

### 0.1 Fuentes leídas (2026-11-13)

- `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md §5.12` (`REQ-COM-001` a `-004`, l. 973-1000) y `REQ-CORE-007` (notificaciones del sistema).
- `ADR-079` (notificaciones y comunicaciones salientes), **PROPUESTA**, leído entero en la rama del agente A3. Decisiones ya tomadas por el usuario el 2026-11-12: `OPEN-079-01` (Scaleway, Brevo de reserva), `OPEN-079-03` (enlace, nunca adjunto), `OPEN-079-04` (motor en `core`; el aviso de recogida lo dispara un *listener* de `prl`, no `com`) y `OPEN-079-07` (apertura medida por lectura y acuse, sin píxel).
- `ADR-082` (firma por niveles), **ACEPTADA el 2026-11-14**, leído en lo que afecta: `§4.1` (N1), `§4.5` (evidencias), `§4.7` (tutores legales; política `uno` para acuses).
- `PLAN-IMPLEMENTACION.md` (`1.19`, «obligación heredada de `1.14b-d3`/`-d4`»), `REQ-PRL/funcional.md` y `REQ-FAM-UNIT` (`OPEN-FAM-UNIT-32`, `-33`).
- Código en `develop` (búsqueda del MCP de GitHub y lectura local; **no tuve `Grep` ni `Bash`**): `FamUnit\Domain\GuardianRecipients` y `FamilyMembership`, `Acad\Domain\TeachingMembership` (solo `groupIdsFor`), `Core\Domain\UserDirectory`. `Alum\Domain` no expone ninguna interfaz (solo `Models/`).

### 0.2 Qué está decidido fuera de este documento y no se reabre

1. **El motor de avisos vive en `core`** (`ADR-079 §4.1`, `OPEN-079-04` decidida). `com` es **un cliente más** del motor y es dueño de mensajería, circulares, acuses, envío masivo, programación, segmentación y estadísticas.
2. **El aviso de recogida de menores (`1.14b-d3`) y el de alumno no recogido (`1.14b-d4`) los dispara un *listener* del propio `prl`** que llama al contrato de `core`. **`com` no interviene en ellos.** La «obligación heredada» del plan se cumple con la entrega `1.19a` (motor), no con `com` (`§11`).
3. Canales: aviso en la aplicación (base, no desactivable), correo SMTP, Web Push, SMS **reservado sin implementar** (`ADR-079 §4.3`). Tres prioridades: urgente, normal, masiva (`§4.8`).
4. **Avisar y enlazar**: ningún canal externo lleva el contenido de un mensaje ni datos de categoría especial; del menor, como mucho el nombre de pila y solo en el cuerpo del correo (`ADR-079 §4.11`). Ningún adjunto en correo.
5. **Sin píxel de seguimiento ni reescritura de enlaces**: la «apertura» de `REQ-COM-003` es la lectura en la aplicación y el acuse.
6. Textos del producto en es-ES, en, de, fr; contenido del centro por idioma, sin traducción automática; textos legales nunca traducidos automáticamente (`CLAUDE.md §2.9`, `ADR-079 §4.6`).

### 0.3 Partición en entregas (≤3 tramos de `implementer` cada una, `CLAUDE.md §2.7`)

`ADR-079 §6.2` propone partir `1.19` en motor y, después, mensajería y circulares, con el *push* aparte. Concreción:

| Entrega | Contenido | Requisitos | Depende de | Tramos previstos | Clasificación y motivo |
|---|---|---|---|---|---|
| **`1.19a-1`** · Motor de avisos (módulo `core`) | Contrato `Notifier`, catálogo de tipos declarados por módulo, aviso en la aplicación (tabla y API de la campana: listado, recuento, marcar leído), canal de correo genérico, tabla de entregas (necesaria para la idempotencia, `RN-COM-11`), colas `notify-urgent`/`notify`/`notify-bulk` con su alta en `worker`, `compose.yaml`, `core:queue-health` y `QueueWorkerTest`. Sin preferencias: el canal efectivo usa los valores por defecto del producto (`RN-COM-05`) | `REQ-CORE-007` | Nada pendiente (correo real: `OPEN-079-01`, no bloquea el código) | 3: datos / motor, correo y colas / API de la campana y pruebas del contrato | **Crítico**: tablas nuevas con RLS, *endpoints* nuevos, datos personales de terceros y de menores (nombre de pila), autorización por identidad |
| **`1.19a-2`** · Preferencias, límites, traza y UI (módulo `core`) | Preferencias por categoría y canal, valores por defecto del centro, límites por centro (`RN-COM-13`), API de la traza de entregas (`entrega_aviso.leer`) con sus permisos, campana y pantallas de `§8` fila `core`, E2E | `REQ-CORE-007` | `1.19a-1` | 3: datos y permisos / preferencias, límites y traza / UI y E2E | **Crítico**: tablas nuevas con RLS, permisos nuevos, *endpoints* sobre datos de terceros |
| **`1.19b`** · Mensajería interna | Conversaciones individuales y de grupo, mensajes, confirmación de lectura, directorio de contactos alcanzables, adjuntos | `REQ-COM-001` | `1.19a`; contratos nuevos de `acad` y `alum` (`§6.3`); almacén de objetos de `S-2` para adjuntos (`OPEN-COM-09`) | 3: datos, permisos y resolutor de contactos / API y adjuntos / UI y E2E | **Crítico**: tablas nuevas, resolutor de ámbito nuevo, ficheros, datos de menores y terceros |
| **`1.19c`** · Circulares y acuse | Borradores, plantillas, audiencias, vista previa de destinatarios, envío inmediato, bandeja del destinatario, acuse de recibo con evidencia, historial e informe de acuses | `REQ-COM-002`, parte de `REQ-COM-003` (envío masivo y segmentación) | `1.19a`; contratos nuevos de `acad`, `alum` y `core` (`§6.3`) | 3: datos, permisos y audiencias / API / UI y E2E | **Crítico**: tablas nuevas, *endpoints*, lectura auditada de datos de familias de menores |
| **`1.19d`** · Programación, estadísticas y aviso de módulo contratado | Envío programado de circulares, estadísticas de entrega y lectura, exportación del informe de acuses (en cola), *listener* de `ModuleContracted` | Resto de `REQ-COM-003` | `1.19c`; **planificador desplegado** (issue #453, estado **sin verificar**) | 2: programación y estadísticas / exportación, aviso y E2E | **Crítico**: exportación de datos personales, *endpoint* nuevo |
| **`1.19e`** · Web Push | Manifiesto y *service worker* **únicos** de `apps/web`: los crea si `1.13-c` (asistencia sin conexión, `ADR-084`) no lo ha hecho y, si ya existen, los extiende; nunca un segundo *service worker* ni un segundo manifiesto (revisión de coherencia H6, 2026-11-13; lo acuerdan `ADR-079` y `ADR-084`). Suscripciones cifradas, adaptador VAPID tras interfaz propia, alta y baja desde preferencias | `REQ-CORE-007`, `REQ-COM-003` (canal *push*) | `1.19a`; dependencia nueva `minishlink/web-push` (`ADR-079 §4.4`, compatibilidad con PHP 8.4 **sin verificar**) | 2-3 | **Crítico**: dependencia nueva, secretos, tabla con datos de dispositivo |
| **`1.19f`** · Eventos del proveedor de correo | *Endpoint* público de rebotes y quejas, dirección no entregable, baja por queja, aviso al administrador | `REQ-CORE-007` («confirmación de entrega») | `1.19a`; **contratación del proveedor** (`OPEN-079-01`: el *webhook* de Scaleway está **sin verificar**) | 1-2 | **Crítico**: *endpoint* público sin sesión que fija el centro |

Orden: `1.19a-1` → `1.19a-2` → `1.19b` y `1.19c` (secuenciales, nunca en paralelo en la misma rama) → `1.19d`. `1.19e` y `1.19f` pueden ir en cualquier momento después de `1.19a-2`. **`1.14b-d3` solo espera a `1.19a-1`** (necesita el contrato, la prioridad urgente y el estado de la entrega, no las preferencias); `1.14b-d4` espera a `1.19a`, a `1.13` y al planificador.

`1.19a` se parte en `-1` y `-2` (revisión C4, 2026-11-13): entera no cabía en tres tramos. El alcance no cambia. Donde este documento y los otros cuatro ficheros dicen **[`1.19a`]**, se entiende `1.19a-1` + `1.19a-2` con el reparto de la tabla: preferencias y valores por defecto (`RN-COM-05` en lo que toca a preferencias, `RN-COM-06`, `CA-COM-002`, `-003`, `-014`), límites (`RN-COM-13`, `CA-COM-009`) y traza (`CA-COM-013`) son de `-2`; el resto de `§4.1` y de `§9.1`, de `-1` (`CA-COM-012` se cumple en las dos, cada una sobre lo suyo).

**Dónde vive la especificación de `1.19a`.** Es código de `core`, pero se especifica aquí porque el encargo es `1.19` y separarla ahora partiría las referencias cruzadas. Propuesta (`OPEN-COM-01`): al aprobarse, las secciones marcadas **[`1.19a`]** se trasladan sin cambios a `docs/modulos/REQ-CORE/notificaciones.md`, como se hizo con `prl` (`ADR-068 §5.1`).

**Fuera de `1.19`** (no se especifica aquí): `REQ-COM-004` (blog y noticias, `OPEN-COM-02`), SMS (`ADR-079 §4.3`), llamada de voz y panel en tiempo real de `REQ-PRL-001` (fase 2), notificación fehaciente (`ADR-079 §4.12`), correo entrante, autorizaciones con aceptación o rechazo del tutor (`REQ-AUT`, fase 2), migración de los `Send*Email` existentes al motor (`OPEN-079-12`: al tocar cada uno).

## 1. Alcance

### 1.1 Entra

| Requisito | Qué se construye | Entrega |
|---|---|---|
| `REQ-CORE-007` «notificaciones internas (in-app) y externas (email, SMS, push)» | Motor con canales aplicación y correo; *push* en `1.19e`; SMS nombrado en el enumerado sin adaptador | `1.19a`, `1.19e` |
| `REQ-CORE-007` «plantillas personalizables por tenant» | Avisos del producto: plantilla del producto con marca del centro. Contenido del centro: circulares y plantillas de circular por idioma (`ADR-079 §4.6`, opción D8-B) | `1.19a`, `1.19c` |
| `REQ-CORE-007` «cola con reintentos y confirmación de entrega» | Un trabajo por entrega y canal, reintentos, traza con estados; confirmación del proveedor en `1.19f` | `1.19a`, `1.19f` |
| `REQ-CORE-007` (`ADR-079 §4.7`) «preferencias por usuario» | Preferencias por categoría y canal; valores por defecto del centro | `1.19a` |
| `REQ-COM-001` | Mensajería entre usuarios del mismo centro, individual y de grupo, con adjuntos y confirmación de lectura | `1.19b` |
| `REQ-COM-002` | Circulares a perfiles (todos, familias de un grupo, profesorado), plantillas, acuse de recibo obligatorio, historial | `1.19c` |
| `REQ-COM-003` | Envío masivo (es el envío de una circular a una audiencia, `OPEN-COM-03`), segmentación, programación, estadísticas de entrega y lectura, aviso de módulo contratado | `1.19c`, `1.19d` |

### 1.2 No entra

- **Blog y noticias** (`REQ-COM-004`): `OPEN-COM-02`.
- **SMS**: ni proveedor, ni adaptador, ni variable de entorno (`ADR-079 §4.3`; `REQ-AUTH §C.7`).
- **Comunicación con cuentas de estudiante** como destinatarios o participantes: `OPEN-COM-06` (`OPEN-079-08`, `INV-008`).
- **Categoría informativa o comercial** de `ADR-079 §4.7`: `com` no declara ningún tipo de esa categoría en `1.19`, de modo que no se construye registro de consentimiento comercial (`OPEN-COM-07`).
- **Respuesta de la familia a una circular** más allá del acuse (aceptar o rechazar, firmar, elegir opción): es `REQ-AUT` (fase 2). El acuse no es una firma (`RN-COM-40`).
- **Adjuntos en circulares**: `REQ-COM-002` no los pide (`OPEN-COM-10`).
- **Edición o borrado de un mensaje ya enviado**, moderación o lectura de conversaciones ajenas: ningún requisito lo pide (`OPEN-COM-11`, `OPEN-COM-12`).
- **Ámbito `clase`** como destino: `TeachingMembership::classPairsFor` está «escrita, no construida» (`OPEN-COM-08`).
- **Reenvío manual de entregas fallidas desde soporte** (`REQ-SUP-004`): fase de soporte; la traza ya lo hace posible.
- Horas de silencio y resúmenes agrupados (`ADR-079 §4.7` regla 6). Nada `FUTURO`.

## 2. Actores y roles implicados

Se comprueban **permisos**, nunca roles (`permisos.md`). Los roles se citan solo para la siembra por defecto.

| Actor | Qué hace |
|---|---|
| Cualquier usuario del centro | Lee sus avisos en la aplicación y fija sus preferencias (autoservicio por identidad, `OPEN-COM-04`) |
| Dirección, secretaría, administración del centro | Redactan y envían circulares a todo el centro o a cualquier grupo; ven historial, estadísticas e informe de acuses; fijan valores por defecto de avisos; consultan la traza de entregas |
| Docente o tutor de grupo | Envía circulares a las familias de sus grupos (ámbito `grupo`) y se comunica con ellas por mensajería |
| Familia (tutor con cuenta) | Recibe circulares y avisos, acusa recibo, mensajea con el personal que alcanza su ámbito |
| Módulos emisores (`auth`, `prl`, `com`, otros) | Declaran tipos de aviso y llaman al contrato del motor [`1.19a`] |
| Proveedor de correo | Recibe el correo por SMTP; informa de rebotes y quejas (`1.19f`) |

## 3. Flujos principales

### 3.1 [`1.19a`] Emitir un aviso

1. Un módulo, desde su propio *listener* de su propio evento de dominio, llama al contrato `Notifier` con: código del tipo, audiencia **ya resuelta** (ids de usuario del centro), parámetros y, si procede, enlace interno y clave de idempotencia.
2. El motor rechaza el tipo si no está declarado en el catálogo (fallo en cerrado, `RN-COM-01`).
3. Por cada destinatario: crea el aviso en la aplicación (siempre, `RN-COM-03`); decide los canales externos según la categoría del tipo, las preferencias del usuario, los valores por defecto del centro y el estado de su dirección (`RN-COM-05`); crea una fila de entrega por canal externo (`pendiente` u `omitido` con motivo) con clave única (aviso, destinatario, canal) y encola un trabajo por entrega en la cola de su prioridad.
4. El trabajo compone el texto en el idioma del destinatario (viaja en el *payload*), comprueba que la entrega sigue `pendiente` y envía; marca `enviado`, o reintenta y termina en `fallido`.
5. La SPA consulta el recuento de no leídos por sondeo (al cargar, al recuperar el foco y cada intervalo `NOTIFY_POLL_SECONDS`) y muestra la campana.

### 3.2 [`1.19a`] Leer avisos y fijar preferencias

1. El usuario abre la campana: listado de sus avisos (texto compuesto en su idioma actual) y enlace al recurso.
2. Marca uno o todos como leídos. Abrir el enlace no marca leído por sí solo (lo marca la SPA al abrir el aviso).
3. En «Preferencias de avisos» ve las categorías del catálogo con sus canales y activa o desactiva los permitidos (`RN-COM-06`). Seguridad de la cuenta y urgente aparecen como fijas.

### 3.3 [`1.19b`] Mensajería

1. El usuario abre «Nuevo mensaje» y busca destinatarios en el **directorio de contactos alcanzables** (`RN-COM-20`), que solo devuelve usuarios a los que su ámbito le permite escribir.
2. Elige uno (conversación individual) o varios (conversación de grupo, `RN-COM-22`), escribe asunto opcional y texto, adjunta ficheros (`RN-COM-26`) y envía.
3. Si ya existe una conversación individual viva entre ambos, el mensaje se añade a ella (`RN-COM-21`).
4. Cada destinatario recibe el aviso `com.mensaje_nuevo` sin el texto del mensaje (`ADR-079 §4.12.3`).
5. Al abrir la conversación, la SPA marca leído hasta el último mensaje visible; el remitente ve «leído por» por participante (`RN-COM-24`).
6. En cada envío posterior se reevalúa que cada destinatario siga alcanzable (`RN-COM-23`).

### 3.4 [`1.19c`] Circular

1. Quien tiene `circular.crear` crea un borrador: título y cuerpo en el idioma por defecto del centro, opcionalmente en otros idiomas; puede partir de una plantilla (`RN-COM-33`). Marca si exige acuse de recibo (`RN-COM-40`).
2. Define la audiencia combinando segmentos (`RN-COM-30`): todo el centro, familias del centro, familias de uno o varios grupos, familias de alumnos concretos, profesorado. Solo puede elegir grupos de su ámbito (`RN-COM-31`).
3. Pide la vista previa: el servidor resuelve la audiencia y devuelve el número de destinatarios, cuántos se omitirán y por qué, sin enviar nada.
4. Envía (`circular.publicar`). El servidor vuelve a resolver la audiencia en ese instante, congela la lista de destinatarios (`RN-COM-34`), deja la circular `enviada` e inmutable, y llama al motor con prioridad masiva, en lotes (`ADR-079 §4.10.2`).
5. El destinatario ve la circular en «Mis circulares» y en la campana; si exige acuse, pulsa «He leído esta circular» y queda registrada la evidencia (`RN-COM-41`).
6. El emisor consulta el historial y el informe de acuses por destinatario y por alumno (`RN-COM-44`).

### 3.5 [`1.19d`] Programación, estadísticas y aviso de módulo contratado

1. En lugar de enviar, el emisor programa la circular para una fecha y hora futuras; puede cancelar la programación mientras no se haya ejecutado (`RN-COM-50`).
2. Un comando programado ejecuta las circulares vencidas con la misma lógica que el envío inmediato, fijando el centro explícitamente (`INV-001`).
3. Las estadísticas por circular muestran: destinatarios, entregas por canal y estado (`enviado`, `fallido`, `omitido` con motivo), leídas en la aplicación y acusadas (`RN-COM-52`).
4. El informe de acuses se exporta en CSV en cola, con enlace caducable (`RN-COM-53`).
5. Al recibir `ModuleContracted`, `com` crea el aviso in-app «ya tienes disponible este módulo» para los usuarios con `modulo.leer`, resueltos por permiso (`REQUISITOS REQ-COM-003`, `ADR-045 §4.8`).

### 3.6 [`1.19e`] Web Push

1. En Preferencias, el usuario pulsa «Activar notificaciones en este dispositivo» (nunca al cargar la página); el navegador pide permiso y la SPA registra la suscripción.
2. Los avisos con canal *push* permitido se envían con título y texto genéricos más el enlace (`ADR-079 §4.4.6`). Una respuesta 404 o 410 del servicio de *push* borra la suscripción.

### 3.7 [`1.19f`] Rebotes y quejas

1. El proveedor llama al *endpoint* público con un evento firmado.
2. El servidor verifica la firma, localiza la entrega por su identificador opaco, fija el centro y actualiza el estado. Un rebote permanente marca la dirección como no entregable y avisa a quien administra usuarios; una queja da de baja al usuario de la categoría del mensaje (`ADR-079 §4.2.8`).

## 4. Reglas de negocio

### 4.1 [`1.19a`] Motor

- **RN-COM-01** Un tipo de aviso no declarado en el catálogo no se envía: el contrato lanza una excepción y no crea nada. El catálogo se compone de lo que declaran los descriptores (`ADR-079 §4.1.2`).
- **RN-COM-02** Cada tipo declara: código `<módulo>.<nombre>`, categoría (`seguridad_cuenta`, `urgente`, `servicio_educativo`, `administracion_plataforma`, `informativa_comercial`), prioridad (`urgente`, `normal`, `masiva`), canales admitidos y si lleva enlace. El centro no reclasifica un tipo.
- **RN-COM-03** El aviso en la aplicación se crea siempre, aunque los demás canales fallen o estén desactivados, y no se puede desactivar.
- **RN-COM-04** El motor no resuelve audiencias: recibe ids de usuario del centro ya resueltos por el módulo dueño del dato (`ADR-079 §4.1.4`). Descarta, sin error, los ids que no sean usuarios activos del centro (`UserDirectory::activeIdsAmong`) y lo deja en el resultado.
- **RN-COM-05** Canal externo efectivo = admitido por el tipo ∧ permitido por la categoría (`ADR-079 §4.7`) ∧ activo en las preferencias del usuario (o, sin preferencia propia, en el valor por defecto del centro) ∧ destino utilizable (dirección no marcada no entregable; suscripción *push* existente). Si falla una condición, la entrega queda `omitido` con su motivo normalizado.
- **RN-COM-06** Seguridad de la cuenta y urgente no son desactivables en ningún canal. Servicio educativo y administración de la plataforma: desactivables en correo y *push*, salvo tipos con acuse obligatorio (`RN-COM-42`). Informativa o comercial: desactivada por defecto (no hay tipos en `1.19`).
- **RN-COM-07** Se guarda la clave del tipo y sus parámetros, nunca el texto compuesto; el texto se compone al leer o enviar, en el idioma del destinatario (`INV-009`). Excepción: el contenido del centro (circulares) se guarda como contenido por idioma (`§4.3`).
- **RN-COM-08** Ningún parámetro de un aviso lleva datos de categoría especial. Del menor, como mucho el nombre de pila y solo en el cuerpo del correo y en la aplicación; nunca en el asunto, en el *push* ni en SMS (`ADR-079 §4.11.3`). Un test recorre los tipos declarados y falla si un parámetro está marcado como de categoría especial o si un parámetro de alumno aparece en el asunto.
- **RN-COM-09** Correo: siempre versión de texto plano además de HTML; remitente de la plataforma con el nombre del centro como nombre visible, validado contra inyección de cabeceras; `Reply-To` a la dirección de contacto del centro si existe (`ADR-079 §4.2.4`). Sin adjuntos.
- **RN-COM-10** Prioridad y cola: urgente → `notify-urgent` (la primera del `worker`), normal → `notify`, masiva → `notify-bulk` (la última). Urgente envía por todos los canales disponibles a la vez (`ADR-079 §4.8`).
- **RN-COM-11** Idempotencia: la fila de entrega se crea con clave única (aviso, destinatario, canal) antes de encolar; el trabajo solo envía si sigue `pendiente`. El contrato admite una clave de idempotencia del emisor: repetida, devuelve el aviso ya creado sin duplicarlo.
- **RN-COM-12** Reintentos: 3 intentos con espera creciente (normal, masiva) o corta (urgente); agotados, `fallido`, visible en la traza. Nunca `queue:retry` a ciegas.
- **RN-COM-13** Límites por centro (`RNF-LIM-003`): tope de destinatarios por envío masivo y de envíos masivos por periodo. Superarlo rechaza **antes de encolar nada**, con error claro; nunca se trunca. Urgente y seguridad de la cuenta no consumen cuota. Valores: `OPEN-COM-14`.
- **RN-COM-14** Los avisos tienen caducidad opcional; los caducados no se muestran. Retención: `OPEN-079-09`.
- **RN-COM-15** Un trabajo de un centro eliminado o suspendido se descarta sin enviar (`ADR-077 §5.4`, borrador).
- **RN-COM-16** El soporte de plataforma no lee avisos ni destinatarios (`ADR-079 §4.5.6`, `§4.9.6`).

### 4.2 [`1.19b`] Mensajería

- **RN-COM-20** **Directorio de contactos alcanzables.** Un usuario solo puede iniciar conversación o añadir participantes entre los usuarios activos del centro que su ámbito de `mensaje.crear` alcanza y que tienen a su vez `mensaje.leer`. Propuesta de alcance por ámbito (`OPEN-COM-13`, bloqueante):
  - `todos`: cualquier usuario activo del centro (salvo cuentas de estudiante, `OPEN-COM-06`).
  - `grupo`: los tutores con vínculo activo de los alumnos matriculados en sus grupos del curso vigente (`TeachingMembership::groupIdsFor` + contrato nuevo de `alum` + `GuardianRecipients`), más los usuarios con `mensaje.crear` de ámbito `todos`.
  - `unidad_familiar`: los docentes de los grupos de los alumnos con los que tiene vínculo activo (`FamilyMembership::studentPersonIdsReachedBy` + contratos nuevos de `alum` y `acad`), más los usuarios con `mensaje.crear` de ámbito `todos`.
- **RN-COM-21** Una conversación individual entre dos usuarios es única mientras viva: escribir a alguien con quien ya hay una la reutiliza. Las de grupo no se deduplican.
- **RN-COM-22** Una conversación de grupo la crea un usuario con `mensaje.crear`; todos los participantes deben serle alcanzables al crearla. Máximo de participantes: `OPEN-COM-15` (recomendado 50). Los participantes no se añaden ni se quitan después en `1.19` (`OPEN-COM-11`).
- **RN-COM-23** Al enviar cada mensaje se comprueba que todos los demás participantes siguen siendo alcanzables por el remitente. Si alguno no lo es (vínculo revocado, cambio de grupo, baja, pérdida de permiso), el envío se rechaza con `com.conversation.counterpart_unreachable` y la conversación queda de solo lectura para ese remitente. El histórico sigue legible para los participantes.
- **RN-COM-24** Confirmación de lectura: por participante se guarda el último mensaje leído y el instante. El remitente ve, por mensaje, quién lo ha leído. No se puede desactivar en `1.19` (`REQUISITOS` no lo pide; `OPEN-COM-16`).
- **RN-COM-25** Un mensaje no se edita ni se borra una vez enviado (`OPEN-COM-11`). Texto: 1 a 10.000 caracteres (propuesta), texto plano; no se interpreta HTML ni Markdown. La pantalla de redacción de mensajes muestra la misma advertencia que `RN-COM-37`: el mensaje no debe contener datos de salud, NEAE ni convivencia (decisión del usuario, 2026-11-13). Es un texto de privacidad: no se traduce automáticamente.
- **RN-COM-26** Adjuntos: hasta 5 por mensaje y 10 MB cada uno (propuesta, `OPEN-COM-09`); tipo real verificado por contenido contra una lista cerrada **solo de PDF y documentos ofimáticos, sin imágenes** (decisión del usuario, 2026-11-13): una imagen, aunque llegue con otra extensión, se rechaza. Las imágenes quedan fuera de `1.19b` hasta que se resuelva `OPEN-COM-32` (fotos de menores, enlazada a `OPEN-INF-12` y `ADR-066`); almacenamiento privado con el prefijo del centro (`ADR-076`); descarga solo para participantes, con URL firmada de caducidad corta tras comprobar permiso (`CLAUDE.md §8`). Un adjunto subido y no enlazado a un mensaje en 24 h se purga.
- **RN-COM-27** El aviso `com.mensaje_nuevo` lleva el nombre visible del remitente y el enlace, nunca el texto ni el asunto (`ADR-079 §4.12.3`). Varios mensajes seguidos de la misma conversación no generan un aviso por mensaje: se agrupan mientras haya uno no leído de esa conversación (`OPEN-COM-17`).
- **RN-COM-28** Nadie que no sea participante lee una conversación, tampoco la administración del centro ni el soporte (`OPEN-COM-12`).

### 4.3 [`1.19c`] Circulares

- **RN-COM-30** **Segmentos de audiencia** (lista cerrada; se combinan por unión y se deduplica por usuario):
  - `centro`: todos los usuarios activos del centro, salvo cuentas de estudiante (`OPEN-COM-06`).
  - `familias_centro`: tutores con vínculo activo de alumnos con matrícula viva en el curso vigente.
  - `familias_grupo`: ídem, de uno o varios grupos del curso vigente.
  - `familias_alumno`: tutores con vínculo activo de alumnos concretos (ámbito `unidad_familiar` del destino).
  - `profesorado`: usuarios con docencia viva (tutoría o asignación) en el curso vigente (contrato nuevo de `acad`, `OPEN-COM-18`).
  Los requisitos nombran «todos, familias de un grupo, profesorado» (`REQ-COM-002`) y «segmentación» (`REQ-COM-003`); `familias_centro` y `familias_alumno` son la segmentación mínima que lo hace usable (`OPEN-COM-03`).
- **RN-COM-31** Con `circular.publicar` de ámbito `grupo`, solo se admiten segmentos `familias_grupo` de grupos en `groupIdsFor(u, curso)` y `familias_alumno` de alumnos matriculados en esos grupos. `centro`, `familias_centro` y `profesorado` exigen ámbito `todos`. Un segmento fuera de ámbito da `403` sin revelar si el grupo existe.
- **RN-COM-32** Tutores con acceso revocado o vínculo no activo no son destinatarios (los excluye `GuardianRecipients`). Las restricciones de custodia se aplican cuando existan, por el mismo contrato (`OPEN-FAM-UNIT-05`).
- **RN-COM-33** Plantillas de circular: contenido del centro por idioma (título y cuerpo), con marcadores de una **lista cerrada** (`{centro}`, `{fecha_envio}`), sustituidos como texto escapado. **Nunca se evalúa contenido del centro con el motor de vistas.** El cuerpo de una circular y de una plantilla es **texto plano** (decisión del usuario, 2026-11-13, patrón de `ADR-070 §4.2`): el servidor **rechaza** con `422 com.circular.body_invalid` cualquier cuerpo que contenga marcado (etiquetas HTML; el criterio exacto de detección es el de `ADR-070 §4.2`), en lugar de sanearlo; se muestra siempre como texto escapado, con los saltos de línea conservados. **Sin dependencia nueva** (ningún saneador de HTML). Si más adelante se quiere formato, se abrirá una `OPEN-COM` con la dependencia evaluada (`CLAUDE.md §1`); texto plano sigue siendo la opción recomendada. La plantilla se copia en el borrador; cambiarla después no altera circulares existentes.
- **RN-COM-34** Al enviar, se congela la lista de destinatarios (una fila por usuario, con los alumnos por los que es destinatario) y el contenido: la circular enviada no se edita ni se borra. Un usuario que entre después en el segmento no la recibe.
- **RN-COM-35** Idioma: cada destinatario recibe la versión en su idioma si el centro la ha escrito; si no, la del idioma por defecto del centro. Sin traducción automática (`ADR-079 §4.6.3`). La versión en el idioma por defecto es obligatoria para enviar.
- **RN-COM-36** El correo de una circular lleva el título y el enlace a la aplicación, no el cuerpo (`OPEN-COM-19`). Sin adjuntos.
- **RN-COM-37** Toda circular es de categoría `servicio_educativo` (`OPEN-COM-07`). La pantalla de redacción advierte de que no debe contener datos de salud, NEAE, convivencia ni publicidad (`ADR-079 §4.11.6`, `§4.7` regla 1).
- **RN-COM-38** El envío exige `Idempotency-Key`; con la misma clave se devuelve la circular ya enviada (`INV-011`). Enviar una circular ya enviada da `409 com.circular.already_sent`.
- **RN-COM-39** Una circular pertenece al curso académico vigente al crearla (`academic_year_id`, `OPEN-COM-20`). Con el curso cerrado, el disparador de `ADR-057` impide nuevos acuses (`RN-COM-43`) y la lectura exige `curso_historico.leer` para el emisor (`RN-CURSO-33`); el destinatario sigue viendo sus circulares de cursos cerrados (`OPEN-COM-20`).
- **RN-COM-40** **El acuse de recibo no es una firma** en el sentido de `ADR-082`: no acepta ni autoriza nada, solo deja constancia de que el destinatario ha visto la circular. No depende de `REQ-DOC`. Si una comunicación necesita aceptación o rechazo, es una autorización de `REQ-AUT` (fase 2). Aun así, la evidencia sigue el patrón N1 de `ADR-082 §4.5` en lo aplicable (`RN-COM-41`; `OPEN-COM-21`).
- **RN-COM-41** El acuse registra, en una tabla de solo anexar: circular, usuario y persona, alumnos por los que era destinatario, versión de idioma mostrada, resumen SHA-256 del título y cuerpo mostrados, instante del servidor, `ip_address`, `user_agent` (≤512) y `request_id`. El cliente no aporta la hora (`INV-010`). Un segundo acuse del mismo usuario devuelve el primero (idempotente).
- **RN-COM-42** Una circular con acuse obligatorio se entrega siempre por correo además de en la aplicación, aunque el usuario haya desactivado el correo de servicio educativo (`ADR-079 §4.7`, columna de la categoría). No hay recordatorio automático en `1.19` (`REQUISITOS` no lo pide; `OPEN-COM-22`).
- **RN-COM-43** El acuse solo lo puede registrar un destinatario congelado de esa circular, con la circular `enviada` y su curso abierto. Leer la circular en la aplicación marca la lectura (estadística), no el acuse.
- **RN-COM-44** Informe de acuses: por destinatario (acusado o no, instante) y por alumno (con política `uno`: acusado si al menos un tutor destinatario acusó; `ADR-082 §4.7.2` propone `uno` para acuses). Ver el informe es una **lectura auditada** de datos de familias de menores (`INV-003`, skill `auditoria`).
- **RN-COM-45** Borradores: los ve y edita quien los creó; con ámbito `todos`, cualquier borrador del centro (`OPEN-COM-23`). Un borrador se puede borrar (borrado lógico); una circular enviada, no.

### 4.4 [`1.19d`] Programación y estadísticas

- **RN-COM-50** Una circular programada guarda `scheduled_for` (≥ ahora + 5 min, ≤ 90 días; propuesta). Mientras esté `programada` no se edita: se cancela la programación, vuelve a borrador y se edita. Al ejecutarse se reevalúan permiso del emisor y ámbito: si el emisor ya no los tiene, la circular pasa a `fallida_programacion` y se avisa al emisor (`com.circular_programada_fallida`), sin enviar.
- **RN-COM-51** El comando de ejecución es idempotente (bloqueo por fila y estado) y fija el centro de cada circular explícitamente.
- **RN-COM-52** Estadísticas: se calculan desde la traza de entregas y los acuses, sin contadores aparte que puedan divergir (`ADR-079 §4.10.6`). No hay «abierto» de correo (`OPEN-079-07`, decidida).
- **RN-COM-53** Exportación del informe de acuses: CSV en cola con enlace caducable, permiso `circular.exportar` con el mismo ámbito que la lectura, auditada con el detalle de lo exportado; esquema cerrado en `api.md`.
- **RN-COM-54** El aviso de módulo contratado (`com.modulo_contratado`, categoría `administracion_plataforma`, solo aplicación) se dirige a los usuarios con `modulo.leer` en cualquier ámbito, resueltos por permiso (contrato nuevo de `core`, `§6.3`), nunca por lista de roles. Mientras `com` no esté contratado, sigue el comportamiento actual (derivado de `module_subscriptions.enabled_at`).

### 4.5 [`1.19e`] *Push*

- **RN-COM-60** Suscripción por usuario y dispositivo, solo tras gesto del usuario; claves de la suscripción cifradas; 404/410 la borra; el usuario ve y revoca sus dispositivos.
- **RN-COM-61** *Push* nunca es el único canal de un tipo. Título y texto genéricos, sin nombre del menor ni del remitente (`ADR-079 §4.11.3`).

### 4.6 [`1.19f`] Eventos del proveedor

- **RN-COM-70** El *endpoint* verifica firma o secreto, es idempotente, tiene límite de ritmo, no devuelve datos personales y localiza la entrega por un identificador opaco propio que fija el centro; no existe tabla global de correspondencia sin `tenant_id` (`ADR-079 §4.9.4`).
- **RN-COM-71** Rebote permanente: la dirección del usuario se marca no entregable, no se le envía más correo y se avisa a los usuarios con `usuario.actualizar` (o el permiso de gestión de usuarios vigente; **sin verificar** el código). Queja: baja del usuario en la categoría del mensaje.

## 5. Casos límite y errores

| Caso | Comportamiento |
|---|---|
| El módulo emisor llama al motor con un tipo no declarado | Excepción, nada creado (`RN-COM-01`); test de arquitectura |
| Proveedor de correo caído | Cortacircuitos por canal: los trabajos vuelven a la cola con retraso sin gastar intentos; el aviso en la aplicación ya existe (`ADR-079 §4.8.5`) |
| Usuario sin correo o con dirección no entregable | Entrega `omitido` con motivo `sin_direccion` o `no_entregable` |
| Usuario dado de baja entre la resolución y el envío | El trabajo comprueba el estado y marca `omitido` (`usuario_inactivo`) |
| Circular a un grupo sin alumnos o sin tutores con cuenta | La vista previa muestra 0 destinatarios; enviar con 0 destinatarios da `422 com.circular.empty_audience` |
| Audiencia por encima del tope del plan | `422 com.circular.audience_limit_exceeded` antes de encolar (`RN-COM-13`) |
| `fam_unit`, `alum` o `acad` descontratado | Sus contratos devuelven conjuntos vacíos (fallo en cerrado): los segmentos de familias o profesorado quedan vacíos y la vista previa lo dice con el motivo `segmento_no_disponible`; nunca se envía «a todos» como sustituto (`OPEN-COM-05`) |
| Mismo tutor de dos alumnos del grupo | Un solo destinatario con los dos alumnos; un solo acuse cubre a ambos |
| Tutor que además es docente | Recibe la circular una vez; en mensajería, su ámbito efectivo es la unión de lo que sus roles conceden, con *deny* prevaleciendo (`RPERM-007`) |
| Vínculo revocado tras el envío | El tutor sigue viendo la circular ya recibida (fue destinatario); no puede escribir en conversaciones donde dejó de ser alcanzable (`RN-COM-23`). `OPEN-COM-24` |
| Acuse con el curso cerrado | `409 com.circular.year_closed` (el disparador lo impide en base de datos) |
| Doble pulsación en «Enviar» | `Idempotency-Key`: una sola circular enviada (`RN-COM-38`) |
| Adjunto con extensión de PDF y contenido ejecutable | `422 com.attachment.type_mismatch` |
| Conversación con un participante borrado (supresión RGPD) | Su nombre se muestra con el marcador neutro (`UserDirectory::displayNamesByIds` devuelve vacío, #446); sus mensajes permanecen (`OPEN-COM-25`) |
| Programación vencida con el `scheduler` parado | Se ejecuta en la siguiente pasada; la estadística muestra la hora real de envío |

## 6. Interacción con otros módulos

### 6.1 Eventos

| Evento | Emisor | Consumidor | Entrega |
|---|---|---|---|
| `ModuleContracted` (existente) | `core` | *Listener* de `com` → `Notifier` | `1.19d` |
| `MessageSent` (nuevo, `com\Domain\Events`) | `com` | *Listener* de `com` → `Notifier` | `1.19b` |
| `CircularSent` (nuevo) | `com` | *Listener* de `com` → reparto en lotes | `1.19c` |
| Eventos de `prl` (alerta de recogida, no recogido) | `prl` | *Listener* de `prl` → `Notifier` (**no `com`**) | `1.14b-d3`/`-d4` |

### 6.2 Contratos que `com` usa y existen

`Core\Domain\UserDirectory` (`activeIdsAmong`, `displayNamesByIds`, `refsByIds`), `FamUnit\Domain\GuardianRecipients::recipientsFor` (diseñado para este consumidor), `FamUnit\Domain\FamilyMembership::studentPersonIdsReachedBy`, `Acad\Domain\TeachingMembership::groupIdsFor`, `Curso\Domain\AcademicYearContext`/`AcademicYearReadAccess`, y el contrato `Notifier` de `1.19a`.

### 6.3 Contratos que **no existen** y `com` necesita

**El módulo depende de piezas no implementadas.** Cada una es código de otro módulo y necesita aprobación expresa (`INV-007`; las listas de frontera son cerradas):

| Contrato propuesto | Módulo | Para qué | Entrega que lo necesita |
|---|---|---|---|
| `Notifier` y catálogo de tipos en el descriptor | `core` | Todo | `1.19a` (lo construye) |
| `PermissionHolders::activeUserIdsWithPermission(code)` | `core` | `ModuleContracted` (`RN-COM-54`), directorio de contactos (`RN-COM-20`) | `1.19b` |
| Listado de usuarios activos excluidas cuentas de estudiante | `core` | Segmento `centro` | `1.19c` (`OPEN-COM-06`; cómo se reconoce una cuenta de estudiante sin mirar roles: **sin verificar**) |
| `EnrollmentDirectory::studentPersonIdsInGroups(groupIds, yearId)` y `::studentPersonIdsInYear(yearId)`, `::groupIdsOfStudents(studentIds, yearId)` | `alum` | Familias de grupo y de centro; contactos de una familia | `1.19b`, `1.19c`. **Coordinación con la tabla de dueños de `alum`** (`docs/modulos/REQ-ACAD/asistencia.md §8.2.1`, fuente; revisión de coherencia H5, 2026-11-13): `EnrollmentRoster` y la operación «matrículas por persona» las construye `1.13`; `com` las consume y no crea una interfaz paralela. Lo que estas operaciones pidan y aquellas no cubran se añade a esas mismas interfaces de `alum`, con aprobación expresa, en la entrega de `com` que lo necesite. `com` no registra vetos en `alum` |
| `TeachingMembership::teacherUserIdsForGroups(groupIds, yearId)` y `::teacherUserIdsInYear(yearId)` | `acad` | Contactos de una familia; segmento `profesorado` | `1.19b`, `1.19c` |

### 6.4 Avisos de `prl` (`1.14b-d3`, `-d4`)

`com` no los emite ni los consume. Lo que `1.19a` debe garantizar para ellos: tipo de categoría `urgente` y prioridad `urgente` declarable por `prl`; envío por todos los canales disponibles a la vez; estado de la entrega consultable por el emisor (para que conserjería vea enviado/leído junto al teléfono, `ADR-079 §4.8.9`). El objetivo de demora (`OPEN-079-05`) es criterio de aceptación de `1.14b-d3`, no de `com`.

## 7. Descriptor del módulo y comportamiento desactivado

| Campo | Valor |
|---|---|
| `code` | `com` |
| `name_key` | `modules.com` («Comunicaciones», «Communications», «Kommunikation», «Communications») |
| `phase` | `1` |
| `depends_on` | `[]` (propuesta, `OPEN-COM-05`): la mensajería entre personal no necesita `fam_unit`, `alum` ni `acad`; sus contratos ya fallan en cerrado si están descontratados |
| `essential` | `false` |
| Tipos de aviso declarados | `com.mensaje_nuevo` (servicio educativo, normal, aplicación + correo + *push*), `com.circular_nueva` (servicio educativo, masiva, aplicación + correo + *push*), `com.circular_programada_fallida` (servicio educativo, normal, aplicación + correo), `com.modulo_contratado` (administración de la plataforma, normal, solo aplicación) |

**Desactivado** (`RMOD-008`, `RMOD-009`): desaparecen «Mensajes», «Circulares» y «Mis circulares» de la navegación; toda ruta de `com` responde `403` informativo por `module-enabled:com`. Los avisos ya creados en `core` siguen en la campana, y su enlace lleva a una ruta que responde el `403` informativo. Los envíos programados no se ejecutan mientras esté desactivado y se ejecutan al reactivar si siguen dentro de plazo (`OPEN-COM-26`). Los avisos de `core` y `prl` no se ven afectados.

## 8. Interfaz (`ADR-053`)

| Módulo web | Rutas (`meta.permissions`, anyOf) | Navegación | Bloques del panel |
|---|---|---|---|
| `core` [`1.19a`] | `/avisos` (`[]`, autoservicio), `/preferencias/avisos` (`[]`), `/configuracion/avisos` (`configuracion_avisos.actualizar`), `/avisos/entregas` (`entrega_aviso.leer`) | Campana en la cabecera (no es entrada de menú) | — |
| `com` [`1.19b`] | `/mensajes`, `/mensajes/:id` (`mensaje.leer`) | «Mensajes» con contador de no leídas | «Mensajes sin leer» (opcional, cierra #261 si se aprueba, `OPEN-COM-27`) |
| `com` [`1.19c`] | `/circulares`, `/circulares/nueva`, `/circulares/:id` (`circular.leer`, `circular.crear`), `/circulares/plantillas` (`plantilla_circular.leer`), `/mis-circulares`, `/mis-circulares/:id` (`[]`, autoservicio, `OPEN-COM-04`) | «Circulares», «Mis circulares» | «Circulares pendientes de acuse» para el destinatario |

Reglas de UI: componentes de shadcn-vue y TanStack Table (`docs/design-system.md §22`); WCAG 2.2 AA (contador de la campana anunciado a lectores de pantalla con `aria-live="polite"`, sin robar el foco); todos los literales por traducción; el contenido del centro (circulares y plantillas) y los mensajes son texto plano y se muestran siempre como texto escapado (interpolación normal de Vue), nunca con `v-html` (`RN-COM-25`, `RN-COM-33`).

## 9. Criterios de aceptación

IDs `CA-COM-NNN`; cada test los referencia (`INV-015`). Entre corchetes, la entrega.

### 9.1 [`1.19a`] Motor

- **CA-COM-001** *Dado* un módulo que llama al contrato con un tipo no declarado, *cuando* se ejecuta, *entonces* se lanza la excepción del catálogo y no se crea ningún aviso ni entrega.
- **CA-COM-002** *Dado* un tipo de servicio educativo y un usuario con el correo desactivado para esa categoría, *cuando* se emite, *entonces* existe el aviso en la aplicación y la entrega de correo está `omitido` con motivo `preferencia`.
- **CA-COM-003** *Dado* un tipo de seguridad de la cuenta, *cuando* el usuario intenta desactivar su correo por `PUT` de preferencias, *entonces* recibe `422 core.notification.category_not_optional`.
- **CA-COM-004** *Dado* un aviso urgente, *cuando* se emite, *entonces* sus trabajos se encolan en `notify-urgent` y uno por canal a la vez, sin esperar el resultado de otro canal.
- **CA-COM-005** *Dado* el mismo aviso emitido dos veces con la misma clave de idempotencia, *entonces* existe un solo aviso y una sola entrega por canal.
- **CA-COM-006** *Dado* un usuario con idioma `de`, *cuando* lee la lista de avisos, *entonces* el texto está en alemán; *cuando* cambia su idioma a `fr`, *entonces* los mismos avisos se muestran en francés.
- **CA-COM-007** *Dado* el usuario A, *cuando* pide `GET /notifications/{id}` de un aviso del usuario B del mismo centro, *entonces* recibe `404` igual que si no existiera; el listado solo devuelve los suyos.
- **CA-COM-008** *Dado* un tipo cuyo asunto de correo incluyera un parámetro de alumno o un parámetro marcado de categoría especial, *entonces* el test del catálogo falla (`RN-COM-08`).
- **CA-COM-009** *Dado* un envío masivo por encima del tope del centro, *entonces* se rechaza antes de encolar nada y no hay filas de entrega.
- **CA-COM-010** *Dado* el correo de un aviso, *entonces* tiene parte de texto plano y HTML, ningún adjunto, el nombre visible del centro validado (un nombre con salto de línea se rechaza al configurarlo) y ninguna URL firmada de almacenamiento.
- **CA-COM-011** *Dado* el *payload* de un trabajo de entrega, *entonces* contiene `tenantId` e idioma y ningún token ni código en claro; un trabajo de un centro eliminado se descarta sin enviar.
- **CA-COM-012** **Aislamiento** (`INV-001`): con dos centros con avisos, entregas y preferencias equivalentes, ningún *endpoint* de `1.19a` devuelve, modifica ni cuenta datos del otro; la traza del centro B no aparece al personal del centro A.
- **CA-COM-013** *Dado* un usuario sin `entrega_aviso.leer`, *cuando* pide la traza, *entonces* `403`; con el permiso, ve estados e indicio enmascarado del destino, nunca la dirección completa ni el cuerpo.
- **CA-COM-014** *Dado* un cambio de preferencias o de valores por defecto del centro, *entonces* queda una fila de auditoría `updated` con valores antes y después.

### 9.2 [`1.19b`] Mensajería

- **CA-COM-020** *Dado* un docente con `mensaje.crear` de ámbito `grupo`, *cuando* consulta el directorio de contactos, *entonces* solo aparecen los tutores con vínculo activo de alumnos de sus grupos del curso vigente y los usuarios con ámbito `todos`; *cuando* intenta crear una conversación con el tutor de un alumno de otro grupo, *entonces* recibe `422 com.conversation.recipient_not_reachable` (mismo cuerpo exista o no el usuario).
- **CA-COM-021** *Dado* un tutor con ámbito `unidad_familiar`, *entonces* solo alcanza a los docentes de los grupos de sus alumnos y a los usuarios con ámbito `todos`; un tutor con el vínculo revocado no alcanza a los docentes de ese alumno.
- **CA-COM-022** **Listado y detalle** (`ADR-044 §4.2`): *dado* un usuario que no participa en una conversación, *cuando* la pide por `public_id` o pide sus mensajes, *entonces* `404`; el listado solo devuelve conversaciones donde participa.
- **CA-COM-023** *Dado* una conversación individual viva entre A y B, *cuando* A vuelve a escribir a B desde «Nuevo mensaje», *entonces* el mensaje se añade a la existente.
- **CA-COM-024** *Dado* que el vínculo del tutor se revoca, *cuando* el docente envía un mensaje a esa conversación, *entonces* `422 com.conversation.counterpart_unreachable`; ambos siguen viendo el histórico.
- **CA-COM-025** *Dado* que B abre la conversación, *entonces* A ve el mensaje como leído por B con el instante; en una de grupo, por participante.
- **CA-COM-026** *Dado* un mensaje nuevo, *entonces* el aviso y el correo a cada destinatario no contienen el texto ni el asunto del mensaje.
- **CA-COM-027** *Dado* un adjunto PDF válido de 2 MB, *cuando* lo descarga un participante, *entonces* recibe una URL firmada de caducidad corta; un no participante recibe `404`; un fichero cuyo contenido no coincide con su tipo se rechaza con `422`.
- **CA-COM-028** **Aislamiento**: con dos centros, el directorio de contactos, el listado, el detalle y los adjuntos no devuelven nada del otro centro, ni siquiera conociendo el `public_id`.
- **CA-COM-029** *Dado* un envío con la misma `Idempotency-Key`, *entonces* un solo mensaje.
- **CA-COM-030** *Dado* el alta de un mensaje, *entonces* hay fila de auditoría `created` sin el texto del mensaje (política redactada, `datos.md`).
- **CA-COM-031** **Curso cerrado**: no aplica (las conversaciones no tienen `academic_year_id`).
- **CA-COM-032** *Dado* un adjunto cuyo contenido real es una imagen (JPEG, PNG u otra), con su extensión o renombrado como `.pdf`, *cuando* se sube, *entonces* `422 com.attachment.type_mismatch` y no se almacena nada; *dado* un PDF o un documento ofimático válidos, *entonces* `201` (`RN-COM-26`).

### 9.3 [`1.19c`] Circulares

- **CA-COM-040** *Dado* un docente con `circular.publicar` de ámbito `grupo`, *cuando* envía a `familias_grupo` de un grupo suyo, *entonces* la reciben los tutores activos de los alumnos matriculados en ese grupo; *cuando* incluye un grupo ajeno o el segmento `centro`, *entonces* `403` sin enviar nada.
- **CA-COM-041** **Listado y detalle**: *dado* un docente con `circular.leer` de ámbito `grupo`, *cuando* lista, *entonces* ve las circulares cuyos segmentos están dentro de sus grupos y las propias; *cuando* pide por `public_id` una circular a todo el centro creada por dirección, *entonces* `404`.
- **CA-COM-042** *Dado* un tutor de dos alumnos del mismo grupo, *entonces* recibe una sola circular y un solo acuse cubre a ambos en el informe por alumno.
- **CA-COM-043** *Dado* un tutor con el acceso revocado a un alumno, *entonces* no es destinatario por ese alumno.
- **CA-COM-044** *Dado* una circular enviada, *cuando* se intenta `PATCH` o `DELETE`, *entonces* `409 com.circular.not_editable`.
- **CA-COM-045** *Dado* una circular con versión en `es` y `en` y un destinatario con idioma `de`, *entonces* la recibe en el idioma por defecto del centro, sin traducción automática.
- **CA-COM-046** *Dado* un cuerpo de circular o de plantilla que contiene marcado (`<p>`, `<script>`, `<a href="javascript:…">`), *cuando* se crea o se edita, *entonces* `422 com.circular.body_invalid` y no se guarda nada; *dado* un cuerpo de texto plano con saltos de línea, *entonces* se guarda tal cual y la API y la SPA lo devuelven y muestran como texto escapado, con los saltos conservados; un marcador fuera de la lista cerrada se rechaza con `422 com.circular.placeholder_unknown` (`RN-COM-33`).
- **CA-COM-047** *Dado* una circular con acuse obligatorio, *cuando* el destinatario acusa, *entonces* queda una evidencia con instante del servidor, IP, agente de usuario, `request_id`, idioma y resumen del contenido mostrado; *cuando* acusa otra vez, *entonces* recibe la misma evidencia; un no destinatario recibe `404`.
- **CA-COM-048** *Dado* una circular con acuse obligatorio y un usuario con el correo de servicio educativo desactivado, *entonces* su entrega de correo no queda `omitido` por preferencia.
- **CA-COM-049** *Dado* el informe de acuses, *cuando* lo consulta el emisor, *entonces* queda una fila de auditoría `read` con la circular consultada; sin `circular.leer` en ese ámbito, `404`.
- **CA-COM-050** **Curso cerrado** (`RN-CURSO-33`): *dado* un usuario con `circular.leer` sin `curso_historico.leer`, *cuando* pide el listado o el detalle de circulares de un curso cerrado, *entonces* `404`; con `curso_historico.leer`, las ve. El destinatario ve las suyas (`OPEN-COM-20`).
- **CA-COM-051** **Aislamiento**: con dos centros, ningún *endpoint* de circulares, plantillas, bandeja o acuses devuelve, cuenta ni modifica datos del otro; la vista previa de audiencia no cuenta destinatarios del otro centro.
- **CA-COM-052** *Dado* `fam_unit` descontratado, *cuando* se pide la vista previa de `familias_grupo`, *entonces* 0 destinatarios con motivo `segmento_no_disponible`, y el envío da `422 com.circular.empty_audience`.
- **CA-COM-053** *Dado* el envío, *entonces* hay auditoría `created`/`updated` de la circular con el estado `enviada`, el número de destinatarios y los segmentos, sin la lista de personas.

### 9.4 [`1.19d`]

- **CA-COM-060** *Dado* una circular programada, *cuando* llega su hora, *entonces* se envía una sola vez aunque el comando se ejecute dos veces en paralelo.
- **CA-COM-061** *Dado* que el emisor pierde `circular.publicar` antes de la hora, *entonces* la circular queda `fallida_programacion`, no se envía y el emisor recibe el aviso.
- **CA-COM-062** *Dado* las estadísticas, *entonces* los recuentos coinciden con la traza de entregas y con los acuses; no hay campo de «abierto».
- **CA-COM-063** *Dado* `circular.exportar`, *cuando* se pide el CSV, *entonces* se encola, el enlace caduca, el CSV sigue el esquema de `api.md`, los textos se neutralizan contra inyección de fórmulas y queda auditoría `exported`; sin el permiso, `403`.
- **CA-COM-064** *Dado* `ModuleContracted`, *entonces* el aviso llega a los usuarios con `modulo.leer` de cualquier rol, incluido un rol personalizado creado por el centro, y a nadie más.

### 9.5 [`1.19e`] y [`1.19f`]

- **CA-COM-070** *Dado* que el servicio de *push* responde 410, *entonces* la suscripción se borra y la entrega queda `fallido` con motivo `suscripcion_caducada`.
- **CA-COM-071** *Dado* un *push* de cualquier tipo, *entonces* el título y el texto no contienen nombres de alumnos ni de remitentes.
- **CA-COM-072** *Dado* un evento del proveedor con firma inválida, *entonces* `401` sin cambios; con firma válida repetido, un solo cambio de estado.
- **CA-COM-073** *Dado* un rebote permanente, *entonces* la dirección queda no entregable, las siguientes entregas de correo a ese usuario son `omitido` (`no_entregable`) y los administradores de usuarios reciben aviso.

## 10. Preguntas abiertas

Formato: recomendación primero. Ninguna está resuelta aquí.

### 10.1 Bloquean la implementación

| ID | Pregunta | Recomendación | Impacto | Bloquea | Decide |
|---|---|---|---|---|---|
| `OPEN-COM-03` | ¿El «envío masivo» de `REQ-COM-003` es el envío de una circular a una audiencia, o un objeto distinto (aviso breve sin cuerpo)? | **DECIDIDA (usuario, 2026-11-14): una sola entidad, la circular, con o sin acuse**. Segmentos de `RN-COM-30` | Un segundo objeto duplica audiencias, estadísticas y permisos | `1.19c` | Usuario |
| `OPEN-COM-04` | Bandeja de avisos, preferencias, «Mis circulares» y acuse: ¿autoservicio por identidad (`[]`, lista cerrada) o permiso propio? | **DECIDIDA (usuario, 2026-11-14): autoservicio por identidad**: ser destinatario lo decidió un acto autorizado del emisor; con permiso propio, un rol personalizado sin él dejaría a familias sin ver circulares con acuse obligatorio | Amplía la lista cerrada de rutas `[]` (`ADR-053`) | `1.19a`, `1.19c` | Usuario |
| `OPEN-COM-06` | ¿Cuentas de estudiante como destinatarias o participantes? (`OPEN-079-08`) | **DECIDIDA (usuario, 2026-11-14): no en `1.19`**: ni mensajería ni circulares con estudiantes; se decide con `REQ-EST` (`1.23`) | Mensajería adulto-menor sin diseño de salvaguarda (`INV-008`) | `1.19b`, `1.19c` | Usuario y legal |
| `OPEN-COM-13` | Alcance de cada ámbito de `mensaje.crear` (`RN-COM-20`) | **DECIDIDA (usuario, 2026-11-14): lo propuesto**: `todos` a todos; `grupo` a familias de sus grupos y personal de ámbito `todos`; `unidad_familiar` a docentes de sus alumnos y personal de ámbito `todos`. Alternativas: (b) familias solo escriben a la tutoría del grupo; (c) solo el personal inicia, la familia responde | Define quién puede escribir a los tutores de un menor y a un docente: riesgo de acoso o de saturación | `1.19b` | Usuario |
| `OPEN-COM-18` | Contratos nuevos de `alum` y `acad` (`§6.3`) | **DECIDIDA (usuario, 2026-11-14): aprobarlos dentro de `1.19b`/`1.19c`**, primer tramo, con su test de contrato en el módulo dueño | Sin ellos no hay segmentos de grupo ni contactos de familia | `1.19b`, `1.19c` | Usuario |
| `OPEN-COM-20` | ¿Circulares por curso académico (`academic_year_id`)? ¿El destinatario ve las de cursos cerrados? | **DECIDIDA (usuario, 2026-11-14): sí, por curso**; el destinatario ve las suyas siempre (autoservicio por identidad, sin `AcademicYearReadAccess`), el emisor sujeto a `RN-CURSO-33` | Sin curso, el historial crece sin corte y el cierre de curso no congela acuses | `datos.md` de `1.19c` | Usuario |
| `OPEN-079-09` | Retención de avisos y traza (y, por extensión, de mensajes y circulares) | **DECIDIDA (usuario, 2026-11-14, `ADR-079 §9.1`): avisos y traza 12 meses; mensajes y circulares: plazo que fije el centro con un mínimo legal a validar** (`OPEN-COM-25`) | Tamaño de tablas y minimización | `datos.md` de `1.19a`-`c` | Usuario y legal |
| `OPEN-COM-28` | Forma del resolutor de contactos de `mensaje.crear` (`permisos.md §7`) | Validación de `architect` antes de `1.19b` | Resolutor de un tipo nuevo | `1.19b` | Técnico |
| `OPEN-COM-14` | Topes por centro (`RN-COM-13`) | **DECIDIDA (usuario, 2026-11-14): valores por defecto configurables por plan**: 2.000 destinatarios por envío y 20 envíos masivos al día (cifras de partida sin medir) | Sin tope, un centro puede quemar la reputación del dominio | `1.19a` | Usuario |

### 10.2 No bloquean

| ID | Pregunta | Recomendación | Impacto | Decide |
|---|---|---|---|---|
| `OPEN-COM-01` | Ubicación de la especificación de `1.19a` | Trasladar las secciones [`1.19a`] a `docs/modulos/REQ-CORE/notificaciones.md` al aprobar | Organización | Técnico |
| `OPEN-COM-02` | `REQ-COM-004` (blog), MUST de fase 1, fuera de `1.19` | Paso propio `1.19g` tras `1.19c`, o con `REQ-WEB` | Queda un MUST de fase 1 sin paso | Usuario |
| `OPEN-COM-05` | `depends_on` de `com` | `[]`; los contratos ajenos fallan en cerrado | Con dependencias, un centro sin `fam_unit` no tendría ni mensajería interna | Usuario |
| `OPEN-COM-07` | Categoría informativa o comercial y registro de consentimiento | No en `1.19`: toda circular es servicio educativo; el consentimiento se construye con su primer consumidor y `OPEN-079-06` | Si el centro quiere enviar publicidad, no puede hasta entonces | Usuario y legal |
| `OPEN-COM-08` | Ámbito `clase` como destino | Fuera hasta que exista `classPairsFor` | Sin envíos por materia | Usuario |
| `OPEN-COM-09` | Adjuntos: límites y dependencia de `S-2` (`TenantObjectStore`) | 5 × 10 MB; `1.19b` después de `S-2`. Tipos: solo PDF y ofimáticos (decidido por el usuario el 2026-11-13; imágenes en `OPEN-COM-32`) | Si `S-2` se retrasa, adjuntos en un tramo posterior | Usuario |
| `OPEN-COM-32` | ¿Se admiten fotografías (de menores o en las que aparezcan) como adjuntos de mensajes? Enlazada a `OPEN-INF-12` (nivel de consentimiento para fotos de acceso restringido, `REQ-INF`) y a `ADR-066` (consentimiento de imagen) | **A (Recomendada)**: no hasta que se resuelva `OPEN-INF-12`; entonces, imágenes solo con comprobación del consentimiento de imagen vigente de los menores identificables con el nivel que fije esa decisión (`INV-014`), con el mismo mecanismo que `1.18-f`. B: imágenes con una advertencia al remitente y sin comprobar consentimiento. C: no admitir imágenes en mensajería nunca; las fotos van solo por los canales que comprueban consentimiento (galería de `REQ-INF`) | A mantiene `1.19b` implementable ya y reutiliza la decisión de `REQ-INF`. B deja que una foto de un menor sin consentimiento circule entre adultos del centro (`INV-014`). C es lo más seguro y quita una función que las familias usan | Usuario y asesor jurídico |
| `OPEN-COM-10` | Adjuntos en circulares | No (no está en `REQ-COM-002`) | Los centros adjuntan PDF a menudo | Usuario |
| `OPEN-COM-11` | Editar o borrar mensajes, añadir o quitar participantes | No en `1.19` | Errores de envío sin rectificar | Usuario |
| `OPEN-COM-12` | ¿Acceso de dirección a conversaciones ajenas (requerimiento judicial, protección del menor)? | No en `1.19`; si se quiere, un permiso propio con motivo y auditoría de lectura, diseñado aparte | Sin vía interna para investigar un caso | Usuario y legal |
| `OPEN-COM-15` | Máximo de participantes por conversación de grupo | 50 | Conversaciones de grupo como circulares encubiertas | Usuario |
| `OPEN-COM-16` | ¿Desactivar la confirmación de lectura? | No | — | Usuario |
| `OPEN-COM-17` | Agrupar avisos de mensajes de la misma conversación | Sí (`RN-COM-27`) | Saturación de correo | Técnico |
| `OPEN-COM-19` | ¿Cuerpo de la circular en el correo? | No: título y enlace | Familias sin aplicación leen menos; a cambio, la lectura y el acuse se miden | Usuario |
| `OPEN-COM-21` | Evidencia del acuse: ¿basta `RN-COM-41` o se exige reautenticación? | Basta; no es firma | — | Usuario y legal |
| `OPEN-COM-22` | Recordatorio de acuse pendiente | No en `1.19` (no está en `REQ-COM-002`) | Menos acuses | Usuario |
| `OPEN-COM-23` | Visibilidad de borradores ajenos con ámbito `todos` | Sí | Coordinación del equipo directivo | Usuario |
| `OPEN-COM-24` | ¿Un tutor revocado deja de ver circulares ya recibidas por ese alumno? | No: fue destinatario legítimo; las nuevas ya no le llegan | Coherencia con `RN-FAM-UNIT-37` | Usuario |
| `OPEN-COM-25` | Retención y supresión de mensajes de un usuario suprimido | Se conservan con el autor anonimizado; plazo con `OPEN-079-09` | Derecho de supresión frente a la integridad de la conversación del otro | Legal |
| `OPEN-COM-26` | Circulares programadas con `com` desactivado | Se ejecutan al reactivar si siguen en plazo de 24 h; si no, `fallida_programacion` | — | Usuario |
| `OPEN-COM-27` | Bloque del panel «Mensajes sin leer» (cierra #261) | Sí, en `1.19b` | Primer bloque real del panel | Usuario |

Además: `OPEN-COM-28` (bloquea `1.19b`) y `OPEN-COM-29` en `permisos.md §7`; `OPEN-COM-30` y `-31` en `operacion.md §8` (no bloquean). Total: 32 `OPEN-COM-*` (`OPEN-COM-32` añadida en la revisión C4, no bloquea), de las que **8 bloquean** (`-03`, `-04`, `-06`, `-13`, `-14`, `-18`, `-20`, `-28`), más `OPEN-079-09` heredada.

## 11. Cambios propuestos en ficheros compartidos

No se ha tocado ninguno. Los aplica la sesión orquestadora.

| Fichero | Cambio |
|---|---|
| `PLAN-IMPLEMENTACION.md` | Sustituir `1.19` por `1.19a-1`, `1.19a-2` y `1.19b`-`1.19f` (`§0.3`) con sus dependencias; la «obligación heredada de `1.14b-d3`/`-d4`» pasa a `1.19a` y se aclara que el aviso lo dispara `prl` (`OPEN-079-04`). `1.14b-d3` depende **solo de `1.19a-1`**; `1.14b-d4` de `1.19a`, `1.13` y #453. Añadir `1.19g` (blog) si se aprueba `OPEN-COM-02`. `1.19b` tras `S-2` si se aprueba `OPEN-COM-09`. `1.19b` y `1.19c` consumen las piezas de `alum` que construye `1.13` (`REQ-ACAD/asistencia.md §8.2.1`). `apps/web` tiene un solo *service worker* y un solo manifiesto: los crea la primera entrega que llegue (`1.19e` o `1.13-c`) y la otra los extiende (`ADR-079`, `ADR-084`) |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md §5.12` | Nota en `REQ-COM-003`: envío masivo = circular a audiencia (`OPEN-COM-03`); apertura por lectura y acuse (`OPEN-079-07`) |
| `docs/modulos/REQ-FAM-UNIT/funcional.md`, `REQ-PRL/funcional.md` | `OPEN-FAM-UNIT-33`: «evento de `prl` consumido por un *listener* de `prl` que llama al motor de `core` (`ADR-079`, `OPEN-079-04`)»; el aviso depende de `1.19a` |
| `ARCHITECTURE.md §3.4` | Al implementar: contrato `Notifier` y declaración de tipos de aviso como patrones de referencia |
| `SYSADMIN.md`, `RUNBOOK.md` | Los de `ADR-079 §10` y los de `operacion.md` de este módulo |
| `CLAUDE.md §1`, skill `colas-y-workers` | Colas `notify-urgent`, `notify`, `notify-bulk` al implementar `1.19a`, con el orden único `…, notify, notify-bulk, calif-pdf` (`operacion.md §4`) |
| `CHANGELOG.md`, `memory.md`, `README.md` | Entrada de la ola: especificación de `REQ-COM` en `PROPUESTO` |
