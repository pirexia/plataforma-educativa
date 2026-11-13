# REQ-COM · Permisos

> **Estado: PROPUESTO** (2026-11-13). Se comprueban permisos, nunca roles (`INV-002`, `AR-08`). Denegar por defecto; *deny* prevalece en multi-rol (`RPERM-007`). Ámbitos del vocabulario cerrado de `ADR-044 §4.1`: `todos`, `propios`, `departamento`, `grupo`, `clase`, `unidad_familiar`. Ningún permiso de este módulo es de categoría especial (`AR-09` no se dispara).

## 1. Recursos que aporta el módulo

### 1.1 Catálogo (`declaredPermissions()`)

| `code` | Módulo | `applicable_scopes` | `resource_label_key` | *Endpoints* (`api.md`) |
|---|---|---|---|---|
| `configuracion_avisos.leer` | `core` [`1.19a`] | `['todos']` | `core.resources.configuracion_avisos` | `GET /notification-settings` |
| `configuracion_avisos.actualizar` | `core` [`1.19a`] | `['todos']` | ídem | `PUT /notification-settings` |
| `entrega_aviso.leer` | `core` [`1.19a`] | `['todos']` | `core.resources.entrega_aviso` | `GET /notification-deliveries*` |
| `mensaje.leer` | `com` [`1.19b`] | `['propios']` | `com.resources.mensaje` | `GET /conversations*`, `POST /conversations/{}/read`, descarga de adjuntos |
| `mensaje.crear` | `com` [`1.19b`] | `['todos', 'grupo', 'unidad_familiar']` | ídem | `GET /messaging/contacts`, `POST /conversations`, `POST /conversations/{}/messages`, `POST /message-attachments` |
| `circular.leer` | `com` [`1.19c`] | `['todos', 'grupo', 'propios']` | `com.resources.circular` | `GET /circulars*`, `/recipients`, `/students`, `/stats` |
| `circular.crear` | `com` [`1.19c`] | `['todos', 'grupo']` | ídem | `POST /circulars`, `PATCH /circulars/{}` |
| `circular.eliminar` | `com` [`1.19c`] | `['todos', 'propios']` | ídem | `DELETE /circulars/{}` (solo borradores) |
| `circular.publicar` | `com` [`1.19c`] | `['todos', 'grupo']` | ídem | `audience-preview`, `send`, `schedule` |
| `circular.exportar` | `com` [`1.19d`] | `['todos', 'grupo']` | ídem | `POST /circulars/{}/acknowledgements/export` |
| `plantilla_circular.leer` / `.crear` / `.actualizar` / `.eliminar` | `com` [`1.19c`] | `['todos']` | `com.resources.plantilla_circular` | `/circular-templates*` |

**Autoservicio por identidad (`[]`, lista cerrada de `ADR-053`, `OPEN-COM-04`)**: `/me/notifications*`, `/me/notification-preferences`, `/me/circulars*` (incluido el acuse), `/me/push-subscriptions*`, `GET /push/vapid-public-key`. La autorización es «el recurso es tuyo»: el aviso tiene tu `recipient_user_id`, la circular te tiene entre sus destinatarios congelados. Un test verifica la lista cerrada. Si el usuario prefiere permiso propio, se añadirían `aviso.leer` y `circular_recibida.leer`/`.actualizar` con ámbito `['propios']`, sembrados a todos los roles predefinidos.

**Plataforma**: `POST /api/platform/mail-events` [`1.19f`] no usa permisos de centro: se autentica por firma del proveedor (excepción declarada a «denegar por defecto», como el *endpoint* público de `ADR-082 §4.6`), con límite de ritmo.

### 1.2 Ámbito restringido (`ADR-044 §4.2`, `AR-10`)

| Recurso | Ámbito | Resolutor (orientativo) | Qué acota (resuelve a consulta) |
|---|---|---|---|
| `mensaje` (`leer`) | `propios` | `ConversationPropiosScopeResolver` | `conversations` con fila en `conversation_participants` del usuario |
| `mensaje` (`crear`) | `todos`, `grupo`, `unidad_familiar` | `MessagingContactResolver` | **No acota una consulta del recurso, sino el conjunto de usuarios alcanzables** (`RN-COM-20`). Compone contratos públicos (`TeachingMembership`, `alum`, `FamilyMembership`, `GuardianRecipients`, `PermissionHolders`) y devuelve ids de usuario para un `whereIn` sobre el directorio. Es una forma nueva de resolutor: **la valida `architect` antes de implementar** (`OPEN-COM-28`) |
| `circular` | `grupo` | `CircularGrupoScopeResolver` | Circulares del curso cuyos segmentos son todos `familias_grupo`/`familias_alumno` dentro de `groupIdsFor(u, curso)`, **más** las de autoría propia |
| `circular` | `propios` | `CircularPropiosScopeResolver` | `author_user_id = u` |

Los modelos `Conversation` y `Circular` entran en el mapa cerrado de `AR-10`: solo `ScopedQuery` y los ficheros sancionados los consultan. El acceso por relación (mensajes de una conversación, destinatarios de una circular) lo cubren los criterios de aceptación de listado **y** detalle (`CA-COM-022`, `CA-COM-041`).

**Regla para `circular.publicar` y `.crear` con ámbito `grupo`**: además del resolutor de lectura, cada segmento del cuerpo se valida contra `groupIdsFor(u, curso)` y, para `familias_alumno`, contra la matrícula del alumno en esos grupos (`RN-COM-31`). Fallo en cerrado: si un contrato no responde, se rechaza.

## 2. Matriz recurso × acción × ámbito

| Recurso | crear | leer | actualizar | eliminar | exportar | publicar |
|---|---|---|---|---|---|---|
| `configuracion_avisos` | — | `todos` | `todos` | — | — | — |
| `entrega_aviso` | — | `todos` | — | — | — | — |
| `mensaje` | `todos`, `grupo`, `unidad_familiar` | `propios` | — | — | — | — |
| `circular` | `todos`, `grupo` | `todos`, `grupo`, `propios` | (lo cubre `crear` en borradores) | `todos`, `propios` | `todos`, `grupo` | `todos`, `grupo` |
| `plantilla_circular` | `todos` | `todos` | `todos` | `todos` | — | — |

No hay `importar`, `aprobar` ni `firmar`: ningún requisito los pide (el acuse no es firma, `RN-COM-40`).

## 3. Asignación en roles predefinidos (propuesta)

Siembra por `ProvisionTenantDefaults` y por el comando de concesión para centros existentes, idempotente y sin conceder nada si falta un permiso (#387). **Los códigos `docente` y `tutor_legal` están sin verificar** en `ProvisionTenantDefaults` (la siembra de `prl` dice «sin siembra para docentes ni `tutor_legal`», `OPEN-FAM-UNIT-42`); el implementador usa los códigos reales o lo reporta.

| Rol | Permisos | Ámbito |
|---|---|---|
| `administrador_centro` | Todos los de `§1.1` | `todos` (`mensaje.leer`: `propios`) |
| `direccion` | `circular.*`, `plantilla_circular.*`, `mensaje.crear`, `entrega_aviso.leer`, `configuracion_avisos.*` | `todos` (`mensaje.leer`: `propios`) |
| `secretaria` | `circular.*`, `plantilla_circular.*`, `mensaje.crear`, `entrega_aviso.leer` | `todos` (`mensaje.leer`: `propios`) |
| `docente` | `circular.leer`, `.crear`, `.publicar`, `.exportar`; `circular.eliminar`; `plantilla_circular.leer`; `mensaje.crear`; `mensaje.leer` | `grupo` (`circular.eliminar`: `propios`; `plantilla_circular.leer`: `todos`; `mensaje.leer`: `propios`) |
| `tutor_legal` | `mensaje.crear`, `mensaje.leer` | `unidad_familiar`, `propios` |
| `conserjeria_pas` | Ninguno | — |

Las bandejas de avisos y de circulares no se siembran: son autoservicio (`OPEN-COM-04`).

## 4. Datos de categoría especial

Ningún recurso de este módulo expone salud, NEAE ni convivencia por diseño (`RN-COM-08`, `RN-COM-37`). Los textos libres pueden contenerlos por error del usuario; por eso nadie salvo los participantes lee un mensaje (`RN-COM-28`) y los avisos nunca llevan el texto. No se declara ningún permiso con `is_special_category = true`.

## 5. Auditoría de lectura

| Lectura | Auditoría | Motivo |
|---|---|---|
| `GET /circulars/{}/recipients`, `/students` y exportación | `read` / `exported` con la circular | Revela qué familias de qué menores han acusado (`INV-003`, skill `auditoria`) |
| `GET /notification-deliveries*` | `read` sin valores | Revela a quién se avisó de qué |
| Lectura de las propias conversaciones, avisos y circulares | Sin auditoría de lectura | Es el propio interesado leyendo lo que se le dirigió; auditarla multiplicaría filas sin valor de control (`OPEN-COM-29`) |

## 6. MFA

Recomendación, **no se aplica** (lo decide el usuario): un rol con `circular.publicar` de ámbito `todos` debería llevar `mfa_obligatorio`, porque con esa cuenta se puede escribir a todas las familias del centro en nombre del centro (vector de suplantación y *phishing*).

## 7. Preguntas abiertas propias de este fichero

| ID | Pregunta | Recomendación | Bloquea | Decide |
|---|---|---|---|---|
| `OPEN-COM-28` | Forma del resolutor de contactos de `mensaje.crear` (acota usuarios, no el recurso) | Que `architect` lo valide como extensión del contrato de `ADR-044` o lo sustituya por una regla de servicio con test de aceptación | `1.19b` | Técnico (`architect`) |
| `OPEN-COM-29` | ¿Auditar la lectura de las propias conversaciones? | No | No | Usuario |
