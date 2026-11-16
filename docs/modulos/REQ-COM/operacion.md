# REQ-COM · Operación

> **Estado: PROPUESTO** (2026-11-13). Lo marcado [`1.19a`], [`1.19e`] y [`1.19f`] es operación del motor de `core` (`ADR-079`).

## 1. Variables de entorno

| Variable | Entrega | Uso | Valor en desarrollo, CI y E2E |
|---|---|---|---|
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` | Existentes | SMTP con TLS al proveedor de la UE (`ADR-079 §4.2`; `OPEN-079-01`). `MailEnvironmentGuard` no cambia | `log` / `array` |
| `MAIL_FROM_ADDRESS` (corriente individual) y `NOTIFY_BULK_FROM_ADDRESS` (corriente masiva) | `1.19a` | Dos subdominios de envío (`ADR-079 §4.2.3`; `OPEN-079-02`, depende de `OPEN-08`) | Valores ficticios `@example.com` |
| `NOTIFY_BULK_MAILER` | `1.19a` | Segundo *mailer* SMTP para la corriente masiva (mismo proveedor, otro dominio de envío) | `log` |
| `NOTIFY_POLL_SECONDS` | `1.19a` | Intervalo de sondeo de la SPA (propuesta 60 s); lo expone la API de configuración pública de la SPA | 60 |
| `NOTIFY_BULK_BATCH_SIZE`, `NOTIFY_BULK_BATCH_DELAY_SECONDS` | `1.19a` | Reparto en lotes del envío masivo (`ADR-079 §4.10.2`); propuesta 200 y 30 s | 200 / 0 |
| `NOTIFY_GLOBAL_RATE_PER_MINUTE` | `1.19a` | Ritmo de salida por debajo del límite del proveedor (**límite del proveedor sin verificar**) | Sin límite |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | `1.19e` | Claves de la plataforma, en el gestor de secretos; rotación en `SYSADMIN.md` | Par de prueba generado en el arranque de CI, nunca en el repositorio |
| `MAIL_EVENTS_SIGNING_SECRET` | `1.19f` | Verificación de eventos del proveedor | Valor de prueba en CI |

Los valores de tope por centro (`RN-COM-13`) **no** son variables de entorno: van por plan (`RMT-005`, `OPEN-COM-14`).

## 2. Servicios externos

| Servicio | Si no responde |
|---|---|
| Proveedor de correo (SMTP) | Tiempo de espera de red explícito; cortacircuitos por canal con estado en Redis bajo el prefijo base (global) y por centro bajo `t{tenant_id}:` (`ADR-033 §9`); los trabajos vuelven a la cola con retraso sin gastar intentos; alerta a operación. El aviso en la aplicación ya existe (`RN-COM-03`) |
| Servicios de Web Push de los navegadores [`1.19e`] | Igual; 404/410 borra la suscripción |
| Almacén S3 (adjuntos) [`1.19b`] | La subida responde `503` con error tipado; la conversación sigue funcionando sin adjuntos |
| Planificador (`schedule:run`) [`1.19d`] | Las circulares programadas se ejecutan en la siguiente pasada; alerta si una circular lleva más de 15 min vencida (propuesta) |

## 3. Despliegue: registro de módulos y permisos (`ADR-034 §5`)

- `platform:sync-registry` materializa: el módulo `com` (`phase 1`, `depends_on: []`, `essential: false`), los permisos de `permisos.md §1.1` y los tipos de aviso declarados por cada descriptor (si el catálogo de tipos se persiste; propuesta: **no se persiste**, vive en código y lo expone `core` en memoria, como el catálogo de módulos; `OPEN-COM-30`).
- Aborta si un ámbito de `applicable_scopes` no pertenece al vocabulario cerrado o si `depends_on` no se cumple. Qué hacer: corregir el descriptor y volver a desplegar; no hay estado a medias, el comando es transaccional (**sin verificar** que lo sea; el implementador lo comprueba en `ADR-034 §5`).
- Siembra de `permisos.md §3` para centros existentes: comando de concesión idempotente de `core`, ejecutado una vez tras el despliegue.

## 4. Colas, tareas programadas y trabajos

| Pieza | Entrega | Detalle |
|---|---|---|
| Cola `notify-urgent` | `1.19a` | **La primera** del `worker` (`ADR-079 §4.8`). Disparador para pasar a `worker-urgent` dedicado: `REQ-PRL-001` o incumplir el objetivo de `OPEN-079-05` |
| Cola `notify` | `1.19a` | Tras las `*-mail` actuales |
| Cola `notify-bulk` | `1.19a` | Tras `notify` y **delante de `calif-pdf`**, que es el único último puesto del `worker`. **Orden único de colas** (criterio de la revisión de coherencia H3, 2026-11-13; el mismo en `ADR-078 §5.3`, `ADR-079 §4.8` y `REQ-CALIF/operacion.md §4`): `…, notify, notify-bulk, calif-pdf`, lo más urgente primero y los lotes después. Si `calif-pdf` ya está en la lista cuando llega `1.19a`, `notify` y `notify-bulk` se insertan delante de ella. El mismo orden se propone para `SYSADMIN.md`, la skill `colas-y-workers` y `QueueWorkerTest` (ficheros compartidos, los aplica la orquestadora) |
| Alta de colas | `1.19a` | En `infra/quadlet/worker.container` **y** en el perfil `worker` de `compose.yaml` en la misma entrega (`QueueWorkerTest`), y en `core:queue-health`. Que estos acepten las colas sin más cambio que la lista está **sin verificar** (`ADR-079 §11.2`) |
| `DeliverNotification` (un trabajo por entrega y canal) | `1.19a` | `tenantId` e idioma en el *payload*; `ShouldBeEncrypted` si lleva cualquier secreto; `timeout` muy por debajo del `retry_after` de la conexión `database` (valor **sin verificar** en `config/queue.php`); 3 intentos con espera creciente |
| `DispatchCircular` (reparto en lotes) | `1.19c` | Cola `notify-bulk`; crea avisos y entregas por lote y se reprograma con retraso para el siguiente; idempotente por circular y lote |
| `com:dispatch-scheduled-circulars` | `1.19d` | Cada minuto en el planificador; recorre circulares vencidas de todos los centros, fija el centro de cada una y la envía con bloqueo de fila (`RN-COM-51`). Depende de issue #453 (planificador desplegado, **estado sin verificar**) |
| Exportación del informe de acuses | `1.19d` | Infraestructura de exportación existente de `core` |
| `com:purge-orphan-attachments` | `1.19b` | Diario; adjuntos sin mensaje con más de 24 h: borra objeto y fila |
| `core:purge-notifications` | `1.19a` | Diario; retención de `OPEN-079-09`, por lotes (o `DROP` de partición si se particiona) |

**Compatibilidad entre versiones** (`CLAUDE.md §9`): las colas nuevas no reciben trabajos de la versión anterior; los `Send*Email` existentes siguen en sus colas sin cambios (`OPEN-079-12`).

## 5. Métricas y alertas

| Métrica | Origen | Alerta propuesta |
|---|---|---|
| Espera en cola de `notify-urgent` (p95) | Traza: `sent_at − queued_at` | > 60 s (`OPEN-079-05`) |
| Entregas `fallido` por canal y hora | Traza | > 5 % en 15 min |
| Cortacircuitos abierto | Redis | Siempre |
| Rebotes y quejas por día [`1.19f`] | Traza | Quejas > 0,1 % de lo enviado (umbral orientativo de los grandes buzones, **sin verificar**) |
| Circulares programadas vencidas sin ejecutar | `circulars` | > 15 min |
| Envíos por centro y canal | Traza (`REQ-SAAS-004`) | Informativa |

## 6. Problemas conocidos y diagnóstico

- **«No me ha llegado el correo»**: buscar la entrega en `GET /notification-deliveries` por destinatario; `omitido` dice por qué (`preferencia`, `no_entregable`, `limite`…). Sin proveedor contratado, ningún correo sale en producción (`OPEN-079-01`).
- **Correo en *spam***: revisar SPF, DKIM y DMARC del subdominio (`SYSADMIN.md`, `ADR-079 §4.2.3`) y los informes DMARC.
- **Una circular tarda en llegar a todos**: el reparto es por lotes espaciados a propósito; la estadística muestra el avance.
- **Una familia no ve la circular**: comprobar si era destinataria congelada (`circular_recipients`); vínculo no activo o revocado al enviar la excluye (`RN-COM-32`).
- **iPhone sin *push***: solo llega con la aplicación añadida a la pantalla de inicio (iOS 16.4+); está en el manual de familias (`ADR-079 §4.4.7`).

## 7. Impacto en copias de seguridad y restauración

- Tablas nuevas de tenant: entran en la copia de PostgreSQL y en PITR sin cambios.
- Adjuntos: objetos bajo el prefijo del centro del almacén S3 (`ADR-076`); su copia sigue la del almacén.
- **Restaurar no reenvía**: tras una restauración, las entregas `pendiente` restauradas se marcan `fallido` con motivo `restauracion` por un comando de recuperación, en lugar de enviarse de nuevo sin control (propuesta para `RUNBOOK.md`, `OPEN-COM-31`).
- Las listas de supresión del proveedor no se restauran desde la plataforma: viven en el proveedor.

## 8. Preguntas abiertas propias de este fichero

| ID | Pregunta | Recomendación | Bloquea | Decide |
|---|---|---|---|---|
| `OPEN-COM-30` | ¿Persistir el catálogo de tipos de aviso en base de datos? | No: vive en código, como el registro de navegación (`ADR-053`) | No | Técnico |
| `OPEN-COM-31` | Comportamiento de entregas pendientes tras restaurar | Marcarlas `fallido` (`restauracion`) y no reenviar | No | Usuario |
