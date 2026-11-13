# REQ-COM · API

> **Estado: PROPUESTO** (2026-11-13). Prefijo `/api/v1`. Todo según `ADR-038`: envoltura `{ data, meta }`, paginación por cursor o por página según `§4`, filtros y orden de `§5`, errores RFC 9457 con `type` como URN y código propio del módulo, enumerados extensibles (`§7.3`). Rutas de `com` con la cadena `RequireAuthenticatedUser` → `module-enabled:com` → `permission:<código>`; rutas de `core` sin `module-enabled` (esencial). Las rutas de autoservicio por identidad (`[]`) dependen de `OPEN-COM-04`. **Nombres de ruta orientativos** (el prefijo `/me/` para autoservicio está **sin verificar** contra las rutas existentes; se imita la convención vigente).

## 1. Endpoints

### 1.1 [`1.19a`] Avisos y preferencias (`core`)

| Verbo y ruta | Permiso · ámbito | Respuesta | Errores propios | Idempotencia |
|---|---|---|---|---|
| `GET /me/notifications` | Identidad (destinatario) | 200 colección por cursor `(created_at DESC, id DESC)`: `public_id`, `type`, `category`, `title`, `body` (compuestos en el idioma actual), `link`, `read_at`, `created_at`. Filtro `unread=true` | 422 | — |
| `GET /me/notifications/unread-count` | Identidad | 200 `{ data: { count } }`; `Cache-Control: no-store` | 429 | — |
| `POST /me/notifications/{notification}/read` | Identidad | 200 el aviso | 404 (de otro usuario o inexistente, mismo cuerpo) | Sí (marcar dos veces no cambia `read_at`) |
| `POST /me/notifications/read-all` | Identidad | 200 `{ data: { updated } }` | — | Sí |
| `GET /me/notification-preferences` | Identidad | 200 matriz categoría × canal con `enabled`, `optional` (si se puede cambiar) y `source` (`usuario`, `centro`, `producto`) | — | — |
| `PUT /me/notification-preferences` | Identidad | 200 la matriz | 422 `core.notification.category_not_optional`, `core.notification.channel_unknown` | Sí |
| `GET /notification-settings` | `configuracion_avisos.leer` · `todos` | 200 valores por defecto del centro por categoría desactivable y canal; nombre visible del remitente y `Reply-To` | — | — |
| `PUT /notification-settings` | `configuracion_avisos.actualizar` · `todos` | 200 | 422 `core.notification.sender_name_invalid` (saltos de línea, control, dirección o dominio), `core.notification.reply_to_invalid`, `core.notification.category_not_optional` | Sí |
| `GET /notification-deliveries` | `entrega_aviso.leer` · `todos` | 200 colección por página: `public_id`, `type`, `channel`, `status`, `skip_reason`, `failure_reason`, `attempts`, `recipient` (`public_id` + nombre visible), `destination_hint`, instantes. Filtros `status`, `channel`, `type`, `recipient`, `from`, `to`, `origin` | 422 | — |
| `GET /notification-deliveries/{delivery}` | `entrega_aviso.leer` · `todos` | 200 | 404 | — |

**El contrato `Notifier` no es un *endpoint***: lo llaman los módulos en proceso. Firma conceptual (no es código): `notify(typeCode, recipientUserIds, params, link?, originKey?, idempotencyKey?, simulate=false) → NotificationBatchResult` con ids creados, ids descartados y motivo (`RN-COM-04`).

### 1.2 [`1.19b`] Mensajería (`com`)

| Verbo y ruta | Permiso · ámbito | Respuesta | Errores propios | Idempotencia |
|---|---|---|---|---|
| `GET /messaging/contacts?q=` | `mensaje.crear` · `todos`/`grupo`/`unidad_familiar` | 200 máx. 20 sin paginar: `public_id`, `display_name`, `context` (p. ej. «Tutor de: Nombre de pila» o «Docente»; nunca apellidos del alumno, documento ni contacto). `q` de 2 a 100 caracteres | 422, 429 | — |
| `GET /conversations` | `mensaje.leer` · `propios` | 200 cursor `(last_message_at DESC, id DESC)`: `public_id`, `kind`, `subject`, `participants` (nombres visibles), `last_message_preview` (≤ 120 caracteres, solo en la API, nunca en avisos), `unread_count`, `can_post` | 422 | — |
| `POST /conversations` | `mensaje.crear` · ámbito que alcance a todos los participantes | 201 `Conversation` con el primer mensaje; 200 si reutiliza una individual existente (`RN-COM-21`) | 422 `com.conversation.recipient_not_reachable` (mismo cuerpo exista o no el usuario), `com.conversation.too_many_participants`, `com.message.body_invalid`, `com.attachment.not_found` | `Idempotency-Key` obligatoria |
| `GET /conversations/{conversation}` | `mensaje.leer` · `propios` | 200 | 404 si no participa | — |
| `GET /conversations/{conversation}/messages` | `mensaje.leer` · `propios` | 200 cursor `(id DESC)`: `public_id`, `sender`, `body`, `attachments` (`public_id`, nombre, tipo, tamaño), `created_at`, `read_by` (participantes e instante) | 404 | — |
| `POST /conversations/{conversation}/messages` | `mensaje.crear` · alcance vigente sobre todos los demás participantes (`RN-COM-23`) | 201 `Message` | 404, 422 `com.conversation.counterpart_unreachable`, `com.message.body_invalid`, `com.attachment.not_found`; 429 | `Idempotency-Key` obligatoria |
| `POST /conversations/{conversation}/read` | `mensaje.leer` · `propios` | 200 `{ last_read_message, last_read_at }`; cuerpo `{ "up_to": "<public_id del mensaje>" }` | 404, 422 | Sí (no retrocede) |
| `POST /message-attachments` | `mensaje.crear` · cualquiera de sus ámbitos | 201 `{ public_id, name, media_type, size }`; `multipart/form-data` | 413, 415, 422 `com.attachment.type_mismatch`, `com.attachment.too_many_pending` | No |
| `GET /message-attachments/{attachment}/download` | `mensaje.leer` · `propios` (participante de la conversación del mensaje) | 200 `{ url, expires_at }` URL firmada de caducidad corta (`ADR-076`); `Cache-Control: no-store` | 404 | — |

### 1.3 [`1.19c`] Circulares (`com`)

| Verbo y ruta | Permiso · ámbito | Respuesta | Errores propios | Idempotencia |
|---|---|---|---|---|
| `GET /circulars` | `circular.leer` · `todos`/`grupo`/`propios` | 200 página: `public_id`, `title` (idioma del usuario o por defecto), `status`, `requires_ack`, `segments`, `author`, `sent_at`, `scheduled_for`, `recipient_count`, `ack_count`. Filtros `status`, `academic_year`, `requires_ack`, `from`, `to`, `q` (título). Orden `sent_at`, `created_at` | 404 (curso cerrado sin `curso_historico.leer`), 422 | — |
| `POST /circulars` | `circular.crear` · `todos`/`grupo` | 201 borrador | 422 `com.circular.locale_invalid`, `com.circular.placeholder_unknown`, `com.circular.segment_invalid`, `validation.*`; 403 segmento fuera de ámbito | No |
| `GET /circulars/{circular}` | `circular.leer` | 200 con contenidos por idioma y segmentos | 404 | — |
| `PATCH /circulars/{circular}` | `circular.crear` (autor, o ámbito `todos` en borradores ajenos, `OPEN-COM-23`) | 200 | 404, 409 `com.circular.not_editable`, 422 | No |
| `DELETE /circulars/{circular}` | `circular.eliminar` | 204 (borrado lógico de un borrador) | 404, 409 `com.circular.not_editable` | Sí |
| `POST /circulars/{circular}/audience-preview` | `circular.publicar` | 200 `{ recipient_count, skipped: [{ reason, count }], by_segment: [...] }`; sin nombres | 403, 404, 422 | — |
| `POST /circulars/{circular}/send` | `circular.publicar` · ámbito que cubra todos los segmentos (`RN-COM-31`) | 202 circular en `enviando` (pasa a `enviada` al congelar destinatarios, en la misma transacción que la crea; el reparto en lotes va en cola) | 403, 404, 409 `com.circular.already_sent`, 422 `com.circular.empty_audience`, `com.circular.audience_limit_exceeded`, `com.circular.default_locale_missing`, `com.circular.year_closed` | `Idempotency-Key` obligatoria |
| `GET /circulars/{circular}/recipients` | `circular.leer` (mismo ámbito que el detalle) | 200 página: destinatario (`public_id`, nombre visible), alumnos (nombre visible), `read_at`, `acknowledged_at`. Filtro `acknowledged`, `student`. **Lectura auditada** (`RN-COM-44`) | 404 | — |
| `GET /circulars/{circular}/students` | `circular.leer` | 200 página: alumno, `acknowledged` (política `uno`), tutores destinatarios y su estado. **Lectura auditada** | 404 | — |
| `GET /circular-templates`, `GET /circular-templates/{template}` | `plantilla_circular.leer` · `todos` | 200 | 404 | — |
| `POST /circular-templates`, `PATCH /circular-templates/{template}`, `DELETE /circular-templates/{template}` | `plantilla_circular.crear` / `.actualizar` / `.eliminar` · `todos` | 201 / 200 / 204 | 422 `com.circular.placeholder_unknown` | — |
| `GET /me/circulars` | Identidad (destinatario, `OPEN-COM-04`) | 200 cursor `(sent_at DESC, id DESC)`: `public_id`, título en su idioma, `requires_ack`, `acknowledged_at`, `read_at`, `sent_at`, alumnos por los que la recibe | 422 | — |
| `GET /me/circulars/{circular}` | Identidad | 200 contenido en su idioma (o el por defecto) y `locale_shown`; marca `first_read_at` | 404 si no es destinatario | — |
| `POST /me/circulars/{circular}/acknowledgement` | Identidad | 201 evidencia (`acknowledged_at`, `locale_shown`); 200 con la primera si ya existía | 404, 409 `com.circular.year_closed`, 422 `com.circular.ack_not_required` | Sí (natural, `UNIQUE`) |

Cuerpo de `POST /circulars`:

```json
{
  "requires_ack": true,
  "contents": [
    { "locale": "es", "title": "Salida al museo", "body_html": "<p>…</p>" },
    { "locale": "en", "title": "Museum trip", "body_html": "<p>…</p>" }
  ],
  "segments": [
    { "kind": "familias_grupo", "group": "01JB…20" },
    { "kind": "familias_alumno", "student_person": "01JB…31" }
  ],
  "template": null
}
```

### 1.4 [`1.19d`]

| Verbo y ruta | Permiso · ámbito | Respuesta | Errores propios | Idempotencia |
|---|---|---|---|---|
| `POST /circulars/{circular}/schedule` | `circular.publicar` | 200 circular `programada`; cuerpo `{ "scheduled_for": "…Z" }` | 404, 409 `com.circular.not_editable`, 422 `com.circular.schedule_out_of_range` | `Idempotency-Key` |
| `DELETE /circulars/{circular}/schedule` | `circular.publicar` | 200 circular `borrador` | 404, 409 `com.circular.not_scheduled` | Sí |
| `GET /circulars/{circular}/stats` | `circular.leer` | 200 `{ recipients, in_app: { read }, email: { enviado, entregado, fallido, omitido: { <motivo>: n } }, push: {…}, acknowledged }` | 404 | — |
| `POST /circulars/{circular}/acknowledgements/export` | `circular.exportar` · mismo ámbito que la lectura | 202 trabajo de exportación (infraestructura de exportación existente de `core`, `ExportRequestService`) | 403, 404, 422 `com.export.q_not_allowed` | `Idempotency-Key` |

### 1.5 [`1.19e`] *Push* (`core`)

| Verbo y ruta | Permiso | Respuesta | Errores |
|---|---|---|---|
| `GET /push/vapid-public-key` | Identidad | 200 clave pública | — |
| `GET /me/push-subscriptions` | Identidad | 200 lista: `public_id`, `device_label`, `created_at`, `last_used_at` (nunca el *endpoint* ni las claves) | — |
| `POST /me/push-subscriptions` | Identidad | 201; cuerpo con la suscripción del navegador | 422 `core.push.subscription_invalid` |
| `DELETE /me/push-subscriptions/{subscription}` | Identidad | 204 | 404 |

### 1.6 [`1.19f`] Eventos del proveedor (`core`, plataforma)

| Verbo y ruta | Autenticación | Respuesta | Errores |
|---|---|---|---|
| `POST /api/platform/mail-events` (fuera de `/api/v1`, como las rutas de plataforma) | Firma o secreto del proveedor (forma exacta **sin verificar**: depende de `OPEN-079-01`); sin sesión ni CSRF; límite de ritmo por IP | 204 siempre que la firma sea válida (también si el evento es desconocido o repetido); sin datos personales en la respuesta | 401 firma inválida; 429 |

## 2. Paginación, filtrado y ordenación (`ADR-038 §4`, `§5`)

| Listado | Paginación | Motivo |
|---|---|---|
| `/me/notifications`, `/conversations`, `/conversations/{}/messages`, `/me/circulars` | Cursor | Crecen sin límite y se leen desde lo más reciente; inserciones concurrentes |
| `/notification-deliveries`, `/circulars`, `/circulars/{}/recipients`, `/circulars/{}/students` | Página | Consultas de gestión con filtros y total |
| `/messaging/contacts` | Sin paginar, máx. 20 | Búsqueda por prefijo |

Filtros desconocidos o mal formados: `422`. `q` en `/circulars` busca por título sin distinguir mayúsculas.

## 3. Exportación del informe de acuses [`1.19d`] (`ADR-054`, `ADR-055`)

- En cola (`INV-012`), enlace caducable, permiso `circular.exportar`, auditoría `exported` con circular y número de filas.
- **Paridad de filtros**: `acknowledged`, `student` (los de `GET /circulars/{}/recipients`). **Sin `q`**: `422 com.export.q_not_allowed`.
- **Esquema CSV cerrado**: `circular_public_id`, `circular_title`, `recipient_public_id`, `recipient_display_name`, `student_public_ids` (separados por `;`), `student_display_names`, `read_at`, `acknowledged_at`, `locale_shown`. Sin IP ni agente de usuario (evidencia, no informe). Textos neutralizados contra inyección de fórmulas (`CsvWriter`). Fechas en ISO 8601 UTC.

## 4. Errores

Códigos propios (claves en `lang/*/com.php` y `lang/*/core.php`, cuatro idiomas): los listados en las tablas de `§1`, más los de forma `com.validation.*` (`required`, `reference_invalid`, `enum_invalid`, `text_invalid`, `datetime_invalid`, `cursor_invalid`, `idempotency_key_invalid`, `empty_body`). `403` de ámbito no revela la existencia del objeto ajeno; un recurso fuera de ámbito por `public_id` responde `404` (`ADR-044 §4.2`). `module-enabled:com` responde el `403` informativo de `RMOD-009`.

## 5. Eventos de dominio emitidos

| Evento | Carga | Consumidores |
|---|---|---|
| `Com\Domain\Events\MessageSent` | `tenantId`, `conversationId`, `messageId`, `senderUserId`, ids de destinatarios | *Listener* propio → `Notifier` |
| `Com\Domain\Events\CircularSent` | `tenantId`, `circularId`, `recipientCount` | *Listener* propio → reparto en lotes |
| `Com\Domain\Events\CircularAcknowledged` | `tenantId`, `circularId`, `userId` | Ninguno en `1.19` (lo deja disponible para `REQ-FAM-PORTAL`; si no hay consumidor al implementar, **no se crea**, `ADR-048`) |

Sin datos personales en las cargas más allá de ids internos.

## 6. Webhooks disponibles

Ninguno saliente. El único entrante es el de `1.19f`.

## 7. OpenAPI

Rutas de `com` en `apps/api/openapi/paths/com.yaml`; las de `core` en su fichero; esquemas `Notification`, `NotificationDelivery`, `NotificationPreferences`, `Conversation`, `Message`, `MessageAttachment`, `Circular`, `CircularRecipient`, `CircularAcknowledgement`, `CircularStats` en `components.yaml`, con enumerados extensibles.
