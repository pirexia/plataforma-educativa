# REQ-AUTH · API

> Alcance: paso **1.2**. La frontera de qué entra y qué no está en `funcional.md §1`. Prefijo `/api/v1`, resolución de tenant por host antes de cualquier consulta (`ADR-033 §2`).
>
> Convenciones transversales de **`ADR-038`**: envoltura, paginación, filtrado, formato de error, versionado, idempotencia y semántica de `PATCH`/`PUT`. Este documento no las repite; señala solo dónde este módulo las usa de forma menos obvia (§9).
>
> **Todo identificador de rutas y cuerpos es el `public_id` ULID** (`ADR-029`). **Ningún token viaja en la ruta ni en la cadena de consulta** (`funcional.md §4.7`).

---

## 1. Reglas generales

| Aspecto | Decisión |
|---------|----------|
| Autenticación | Cookie de sesión `httpOnly`/`Secure`/`SameSite=Lax`, **host-only**, con CSRF (`ADR-025`, `funcional.md §6`). Prohibido JWT en almacenamiento del navegador |
| Autorización | Denegar por defecto (`INV-002`). La mayoría de endpoints de este módulo son **anónimos por diseño** o **por identidad**; los dos de administración declaran su permiso. Ver `permisos.md` |
| Aislamiento | RLS más *scope* global más predicado explícito de tenant (`RN-AUTH-07`). Un recurso de otro tenant responde `404`, nunca `403` (`ADR-038 §6.4`) |
| Formato de error | `application/problem+json` (RFC 9457), `type` como URN `urn:pge:error:<slug>` (`ADR-038 §6`) |
| Idempotencia | **Ningún endpoint de este módulo exige `Idempotency-Key`** (§9.3) |
| Auditoría | `INV-003`. Bloqueo, desbloqueo, cambio de contraseña y activación los registra el *observer* de 0.9 sin código por endpoint; login y logout dependen de `OPEN-AUTH-02` |
| Módulo desactivado | **No aplica**: `REQ-AUTH` no es desactivable. Ninguna ruta lleva el *middleware* `module-enabled` (`funcional.md §11`, `CA-AUTH-078`) |
| Límite de tasa | Todos los endpoints anónimos, por IP **y** por `(tenant_id, email)`. Ver `operacion.md §6` |
| OpenAPI | Todos documentados en `apps/api/openapi/paths/auth.yaml` antes del merge (`CLAUDE.md §10`) |

Cabeceras de respuesta comunes: `X-Request-Id` (`INV-013`), `Content-Language` (`ADR-038 §11`). En `429`, además `Retry-After`.

Envoltura (`ADR-038 §3.1`): recurso individual **desnudo**; colección `{"data": [...], "meta": {...}}`; escritura sin cuerpo `204`.

### 1.1 Tipo de error nuevo que aporta este módulo

`ADR-038 §6.2` declara su catálogo *«cerrado y ampliable solo por ADR o por especificación de módulo»*. Esta especificación añade uno:

| `type` | Estado | Cuándo |
|--------|--------|--------|
| `urn:pge:error:account-locked` | **423** | La cuenta está bloqueada por intentos fallidos (`REQ-AUTH-001`) |

**Por qué `423` y no `401` o `403`.** El cliente necesita distinguir tres situaciones que exigen tres pantallas distintas —«credenciales incorrectas», «no tienes permiso» y «tu cuenta está bloqueada, revisa tu correo»— sin analizar texto, que es el mismo argumento con el que `ADR-038 §6.2` separó `module-disabled` de `forbidden` con el mismo `403`. `423 Locked` (RFC 4918) describe exactamente esto.

**Y por qué revelar el bloqueo no es una fuga**: lo sería si solo ocurriera con cuentas existentes. Por eso el bloqueo también existe para correos que no corresponden a ninguna cuenta (`RN-AUTH-15`), y la respuesta es idéntica en ambos casos (`CA-AUTH-027`).

Requiere un método nuevo `ApiException::accountLocked()`, que hoy no existe entre los trece de `App\Support\Api\ApiException`.

---

## 2. Sesión (`ADR-025`, `REQ-AUTH-001`)

### `GET /api/v1/auth/csrf-cookie`

Semilla de la cookie CSRF para el arranque en frío de la SPA. Equivalente propio de `/sanctum/csrf-cookie` **sin la dependencia** (`funcional.md §4.7`).

- **Permiso**: ninguno. Anónimo, tenant resuelto por host.
- **Respuesta 204**: sin cuerpo. Deja la cookie `XSRF-TOKEN` (legible por JavaScript, por diseño: es el token que la SPA reenvía en `X-XSRF-TOKEN`) y, si no existía, la cookie de sesión anónima.
- **Errores**: 404 (host sin tenant), 429
- **Idempotencia**: no procede (es `GET`)

---

### `POST /api/v1/auth/session`

Login local. **Único camino del sistema que crea una sesión** (`RN-AUTH-21`) — y por tanto el único que 1.3 tendrá que desdoblar para el MFA.

- **Permiso**: ninguno. Anónimo.
- **Cabeceras**: `X-XSRF-TOKEN` obligatoria (`RN-AUTH-29`)
- **Cuerpo**

```json
{
  "email": "ana.perez@example.com",
  "password": "···"
}
```

- **Respuesta 200**: el mismo recurso que `GET /api/v1/me` de `REQ-CORE` — usuario, persona, roles y permisos efectivos —, para que la SPA no encadene una segunda petición. Establece la cookie de sesión con identificador **regenerado** (`RN-AUTH-32`).
- **Errores**

| Estado | `type` | Cuándo |
|--------|--------|--------|
| `401` | `urn:pge:error:unauthenticated` | Contraseña incorrecta, correo inexistente, usuario `pendiente` o usuario `inactivo`. **Cuerpo idéntico en los cuatro casos** (`CA-AUTH-011`) |
| `423` | `urn:pge:error:account-locked` | Cuenta bloqueada. Se responde **sin verificar la contraseña**; una contraseña correcta tampoco entra (`RN-AUTH-16`) |
| `422` | `urn:pge:error:validation` | Falta `email` o `password`, o `email` no tiene forma de correo |
| `429` | `urn:pge:error:too-many-requests` | Límite por IP o por `(tenant, email)`. Con `Retry-After` |
| `403`/`419` | | Token CSRF ausente o inválido |
| `404` | `urn:pge:error:not-found` | Host sin tenant |
| `503` | `urn:pge:error:unavailable` | Tenant suspendido (`ResolveTenant`) |

- **Idempotencia**: no. Repetir un login correcto es un login nuevo, no un duplicado a evitar.

---

### `DELETE /api/v1/auth/session`

Logout. Invalida la sesión, borra su fila de `sessions`, regenera el token CSRF y caduca la cookie.

- **Permiso**: ninguno declarado — **por identidad**: cierra la sesión del portador de la cookie, nunca la de otro.
- **Cabeceras**: `X-XSRF-TOKEN` obligatoria
- **Respuesta 204**
- **Errores**: 429; `403`/`419` por CSRF
- **Sin `401`**, deliberadamente: cerrar una sesión que ya no existe **no es un error** (`CA-AUTH-017`). Devolver `401` obligaría a la SPA a tratar como fallo el caso más normal del mundo —pulsar «salir» con la sesión ya expirada— y a envolverlo en un `try/catch` que oculta fallos de verdad.

> **Fuera de 1.2**: `GET /auth/sessions` (listado de sesiones activas) y `DELETE /auth/sessions` (cierre en todos los dispositivos) son `REQ-AUTH-005` puntos 2-3, paso **1.2b** ([#59](https://github.com/pirexia/plataforma-educativa/issues/59)).

---

## 3. Canje de la invitación (`REQ-AUTH-001`, contrato de `REQ-CORE/funcional.md §4.3`)

### `POST /api/v1/auth/invitation-redemptions`

Fija la contraseña de un usuario `pendiente` y lo activa. El enlace del correo es `https://{slug}.{dominio_base}/activar/{token}`, que es una **ruta de la SPA**: la SPA extrae el token de su propia URL y lo envía aquí en el cuerpo (`funcional.md §4.7`, `OPEN-AUTH-08`).

- **Permiso**: ninguno. Anónimo; la autorización **es** la posesión del token.
- **Cabeceras**: `X-XSRF-TOKEN` obligatoria
- **Cuerpo**

```json
{
  "token": "3f7a…",
  "password": "···",
  "password_confirmation": "···"
}
```

- **Efectos, en una transacción** (`RN-AUTH-20`): `users.password`, `users.status = 'activo'`, `users.email_verified_at = now()`, `user_invitations.accepted_at = now()`; purga de tokens de restablecimiento vivos y levantamiento de bloqueos vivos de ese correo.
- **Respuesta 204**. **No inicia sesión** (`RN-AUTH-21`, `CA-AUTH-044`): la SPA redirige al login.
- **Errores**

| Estado | `type` | Cuándo |
|--------|--------|--------|
| `410` | `urn:pge:error:gone` | Token inexistente, caducado, revocado, ya aceptado, de otro tenant, o cuyo usuario ya no está `pendiente`. **Cuerpo idéntico en todos los casos** (`CA-AUTH-041`, `CA-AUTH-042`) |
| `422` | `urn:pge:error:validation` | La contraseña incumple la política (`RN-AUTH-01`, `RN-AUTH-02`) o no coincide con la confirmación. `errors` lleva el `code` de la regla concreta y su `message` traducido (`ADR-038 §6.3`) |
| `429` | | Límite por IP |
| `404` | | Host sin tenant |

- **Idempotencia**: no hace falta cabecera. Un segundo canje del mismo token devuelve `410` porque la invitación ya está aceptada, que es el comportamiento correcto y no un duplicado silencioso.

---

## 4. Recuperación de contraseña (`REQ-AUTH-001`, issue [#18](https://github.com/pirexia/plataforma-educativa/issues/18))

### `POST /api/v1/auth/password-reset-requests`

- **Permiso**: ninguno. Anónimo.
- **Cabeceras**: `X-XSRF-TOKEN` obligatoria
- **Cuerpo**: `{ "email": "ana.perez@example.com" }`
- **Respuesta 202**: **siempre**, exista o no la cuenta, esté activa o no (`RN-AUTH-10`). Cuerpo vacío.
- **Efecto** (solo si hay usuario vivo y **`activo`** con ese correo en ese tenant): se emite el token, se sustituye cualquier otro vivo del mismo correo (`RN-AUTH-11`) y se **encola** el correo (`INV-012`) en el idioma preferido del destinatario, con el enlace `https://{slug}.{dominio_base}/restablecer/{token}`.
- **Errores**

| Estado | Cuándo |
|--------|--------|
| `422` | Falta `email` o no tiene forma de correo. **Es el único caso en que este endpoint no devuelve `202`**, y no filtra nada: solo dice que la cadena enviada no es un correo |
| `429` | Límite por IP y por `(tenant, email)`. Es la defensa real contra usar la plataforma como remitente de correo hacia terceros |

- **Idempotencia**: no procede. Repetir sustituye el token anterior, que es el comportamiento pedido.

---

### `POST /api/v1/auth/password-resets`

- **Permiso**: ninguno. Anónimo; la autorización es la posesión del token.
- **Cabeceras**: `X-XSRF-TOKEN` obligatoria
- **Cuerpo**

```json
{
  "token": "9c1e…",
  "password": "···",
  "password_confirmation": "···"
}
```

- **Búsqueda**: por `(tenant_id, sha256(token))`, con el predicado de tenant **explícito en la consulta** además de RLS (`RN-AUTH-07`, `funcional.md §7`). **Sin correo en el cuerpo**: no hace falta y meterlo obligaría a llevarlo en el enlace.
- **Efectos, en una transacción**: contraseña nueva, **borrado de la fila del token** (un solo uso, `RN-AUTH-12`), levantamiento de bloqueos vivos de ese correo y **revocación de todas las sesiones activas** del usuario (`RN-AUTH-22`).
- **Respuesta 204**. **No inicia sesión.**
- **Errores**

| Estado | `type` | Cuándo |
|--------|--------|--------|
| `410` | `urn:pge:error:gone` | Token inexistente, caducado, ya usado o **de otro tenant** (`CA-AUTH-033`). Cuerpo idéntico |
| `422` | `urn:pge:error:validation` | La contraseña incumple la política o no coincide con la confirmación |
| `429` | | Límite por IP |

---

## 5. Desbloqueo de cuenta (`REQ-AUTH-001`)

### `POST /api/v1/auth/account-unlocks`

Desbloqueo por el propio titular, con el token del correo de aviso. Enlace `https://{slug}.{dominio_base}/desbloquear/{token}`.

- **Permiso**: ninguno. Anónimo; la autorización es la posesión del token.
- **Cabeceras**: `X-XSRF-TOKEN` obligatoria
- **Cuerpo**: `{ "token": "b40d…" }`
- **Efecto**: informa `unlocked_at` (`unlocked_by` queda `NULL`: fue el titular) y pone a cero el recuento de fallos consecutivos. La fila **se conserva** (`RN-AUTH-18`).
- **Respuesta 204**
- **Errores**: `410` si el token es inexistente, caducado, ya usado o de otro tenant, con cuerpo idéntico (`CA-AUTH-029`); `429`

---

### `GET /api/v1/account-lockouts`

Cuentas bloqueadas del centro. Es el único listado de este módulo.

- **Permiso**: `bloqueo_cuenta` · `leer` · `todos`
- **Parámetros de consulta**

| Parámetro | Tipo | Nota |
|-----------|------|------|
| `status` | `vigente\|levantado` | Varios valores separados por coma (`ADR-038 §5.2`). Por defecto `vigente` |
| `q` | string | Búsqueda por correo |
| `sort` | `locked_at\|-locked_at` | Por defecto `-locked_at` |
| `page`, `per_page` | int | Por defecto 25, máximo 100 |

- **Respuesta 200**

```json
{
  "data": [
    {
      "public_id": "01J8...",
      "email": "ana.perez@example.com",
      "user": { "public_id": "01J8...", "email": "ana.perez@example.com" },
      "status": "vigente",
      "failed_count": 5,
      "locked_at": "2026-08-22T09:00:00Z",
      "unlocked_at": null,
      "unlocked_by": null
    }
  ],
  "meta": { "current_page": 1, "per_page": 25, "total": 3, "last_page": 1 }
}
```

`status` es **derivado**, no una columna: `levantado` si `unlocked_at`, `vigente` en otro caso. Es el mismo patrón que `REQ-CORE` usó con el estado de la invitación.

`user` es `null` en un **bloqueo fantasma** (correo sin cuenta, `RN-AUTH-15`). Es información útil para el administrador: un puñado de bloqueos con `user: null` es la firma de alguien probando correos al azar contra el centro, no de un profesor que olvidó su contraseña.

**Ni `unlock_token_hash` ni ningún material de token aparece nunca en la respuesta.**

- **Errores**: 401, 403, 422 (parámetro inválido)
- **Paginación**: por página, no por cursor (`ADR-038 §4.2`: es un catálogo acotado, no una tabla de alto crecimiento)

---

### `DELETE /api/v1/account-lockouts/{public_id}`

Desbloqueo por administrador.

- **Permiso**: `bloqueo_cuenta` · `eliminar` · `todos`
- **Efecto**: informa `unlocked_at` y `unlocked_by` con el solicitante, y pone a cero el recuento. **No borra la fila** — es traza (`RN-AUTH-18`); `DELETE` describe la desaparición del **bloqueo**, no la de la fila, igual que `DELETE /invitations/{id}` de 1.1 conserva la invitación revocada.
- **Respuesta 204**
- **Errores**

| Estado | Cuándo |
|--------|--------|
| `401` | Sin sesión |
| `403` | Sin `bloqueo_cuenta.eliminar` |
| `404` | Inexistente **o de otro tenant** (`ADR-038 §6.4`) |
| `409` | El bloqueo ya está levantado |

- **Auditoría**: `updated` sobre `AccountLockout` con `unlocked_at` y `unlocked_by`, por el *observer* de 0.9 (`funcional.md §10.1`)

---

## 5b. Cambio de contraseña auto-servicio (`OPEN-AUTH-05`, aprobado)

> Sección añadida al implementar (2026-08-22): faltaba en el resumen de §7 y en el cuerpo de este documento pese a estar aprobada y descrita en `funcional.md §4.8` — incoherencia de documentación detectada y corregida en la misma sesión (`CLAUDE.md §6.6`). Numerada `5b` para no desplazar las referencias cruzadas ya escritas a §6-§11 en este y otros documentos del módulo (mismo criterio que `1.2b`/`1.4b` en `PLAN-IMPLEMENTACION.md`).

### `POST /api/v1/auth/password-changes`

Cambio de contraseña por el propio usuario ya autenticado. No está en `REQ-AUTH-001`; entra por decisión del usuario del 2026-08-22 al resolver `OPEN-AUTH-05` (`funcional.md §4.8`).

- **Permiso**: ninguno declarado — **por identidad**, igual que el logout y `GET /me`.
- **Cabeceras**: `X-XSRF-TOKEN` obligatoria; sesión válida
- **Cuerpo**

```json
{
  "current_password": "···",
  "password": "···",
  "password_confirmation": "···"
}
```

- **Efectos, en una transacción**: fija la contraseña nueva y **revoca todas las sesiones del usuario salvo la actual** (`RN-AUTH-36`) — a diferencia del restablecimiento de §4, que las revoca todas. Encola el correo «tu contraseña ha cambiado», sin enlace accionable (`operacion.md §5`).
- **Respuesta 204**
- **Errores**

| Estado | `type` | Cuándo |
|--------|--------|--------|
| `401` | `urn:pge:error:unauthenticated` | Sin sesión |
| `422` | `urn:pge:error:validation` | Contraseña actual incorrecta (**no** `401`: la sesión sigue siendo válida, funcional.md §4.8 punto 3), contraseña nueva que incumple la política, o igual a la actual |
| `423` | `urn:pge:error:account-locked` | Los fallos de contraseña actual cuentan hacia el mismo bloqueo que el login (funcional.md §4.8 punto 4); si ya está bloqueada, `423` |

- **Auditoría**: `updated` sobre `User` con `password` redactado como `secret`, por el *observer* de 0.9. Sin evento nuevo: no depende de `ADR-039`.
- **Idempotencia**: no procede — un reintento con la misma contraseña actual (ya cambiada) fallaría con `422`, que es el comportamiento correcto.

---

## 6. Ampliación de un endpoint de `REQ-CORE`

### `GET` / `PATCH /api/v1/tenant/settings` — grupo `security`

**No es un endpoint nuevo.** El recurso de configuración del centro de 1.1 gana un grupo, con los permisos que ya tiene (`configuracion.leer`, `configuracion.actualizar`) y sin ruta nueva:

```json
{
  "regional": { "...": "..." },
  "fiscal":   { "...": "..." },
  "branding": { "...": "..." },
  "security": {
    "session_timeout_minutes": 30
  }
}
```

- **Validación** (`INV-010`): entero entre **5 y 480** (`RN-AUTH-30`, rango pendiente de `OPEN-AUTH-06`). Fuera de rango ⇒ `422`.
- **Efecto**: invalida la caché `tenant:{id}:settings` en la escritura (`RN-CORE-17`) y se aplica **en la petición siguiente** de cualquier sesión de ese centro, sin necesidad de que nadie vuelva a entrar.
- **Auditoría**: `updated` sobre `TenantSettings` **con el valor**, no redactado (`datos.md §A.4`): es una decisión de seguridad del centro y hay que poder ver quién bajó el timeout a 480 minutos.

Bajar el timeout **no expulsa retroactivamente** a las sesiones que ya llevaban más de ese tiempo inactivas: la comprobación se hace en la petición siguiente de cada sesión, que es cuando esas sesiones morirán. No hace falta ninguna operación en masa.

---

## 7. Resumen de la superficie del módulo

| Método y ruta | Auth | Permiso | Éxito |
|---------------|------|---------|-------|
| `GET /api/v1/auth/csrf-cookie` | Anónimo | — | 204 |
| `POST /api/v1/auth/session` | Anónimo | — | 200 |
| `DELETE /api/v1/auth/session` | Por identidad | — | 204 |
| `POST /api/v1/auth/invitation-redemptions` | Token en cuerpo | — | 204 |
| `POST /api/v1/auth/password-reset-requests` | Anónimo | — | 202 |
| `POST /api/v1/auth/password-resets` | Token en cuerpo | — | 204 |
| `POST /api/v1/auth/account-unlocks` | Token en cuerpo | — | 204 |
| `POST /api/v1/auth/password-changes` | Sesión (por identidad) | — | 204 |
| `GET /api/v1/account-lockouts` | Sesión | `bloqueo_cuenta.leer` | 200 |
| `DELETE /api/v1/account-lockouts/{public_id}` | Sesión | `bloqueo_cuenta.eliminar` | 204 |

**Diez endpoints, seis de ellos anónimos** (corregido: la cuenta original de "nueve" se fijó antes de aprobarse `OPEN-AUTH-05`, §5b, que añadió `password-changes` sin actualizar este resumen — ver la nota de §5b). Los seis anónimos son la mayor superficie del producto —hasta ahora solo existía `GET /tenant/branding`— y por eso llevan límite de tasa obligatorio (`operacion.md §6`) y respuesta indistinguible (`funcional.md §4.7`). Cualquier endpoint anónimo que se añada a este módulo en el futuro debe justificarse en la revisión de seguridad igual que se justificó `GET /tenant/branding` en 1.1.

---

## 8. Cadena de *middleware* del grupo `/api/v1`

Cambia con 1.2 y hay que fijar el orden, porque un intercambio de dos posiciones aquí es un fallo de seguridad silencioso.

| # | *Middleware* | Por qué ahí |
|---|--------------|-------------|
| 1 | `AssignRequestId` | `INV-013`. Antes que nada, para que hasta un `404` de tenant tenga `request_id` |
| 2 | `ResolveTenant` | `ADR-033 §2`: **antes de sesión y de autenticación**, y antes de cualquier acceso a datos |
| 3 | `AddQueuedCookiesToResponse` | Framework |
| 4 | `StartSession` | **Nuevo en 1.2.** Después del tenant: la sesión pertenece a un tenant ya resuelto |
| 5 | `ValidateCsrfToken` | **Nuevo en 1.2.** Después de la sesión, que es de donde sale el token |
| 6 | `VerifySessionTenant` | **Nuevo en 1.2.** `RN-AUTH-31`: el `tenant_id` del *payload* frente al resuelto por host. Discrepancia ⇒ sesión invalidada, `401` y auditoría |
| 7 | `EnforceSessionIdleTimeout` | **Nuevo en 1.2.** `REQ-AUTH-005` punto 1. Después de la reverificación: no tiene sentido refrescar la actividad de una sesión que se va a invalidar |
| 8 | `ResolveApiLocale` | **Se mueve en 1.2.** Hoy corre en la posición 3 y por tanto `$request->user()` siempre le devuelve `null`: el paso 1 de `ADR-038 §11` no se aplica nunca fuera de los tests con `actingAs()`. Ver `funcional.md §1.4` punto 4 y `CA-AUTH-075` |

`/api/health` (fuera de `v1`) **no** entra en esta cadena: el *healthcheck* del contenedor no tiene subdominio de tenant y debe responder sin sesión. Es el comportamiento actual y no cambia.

---

## 9. Convenciones transversales: dónde este módulo se aparta o matiza

Ratificadas en `ADR-038`. Solo lo no obvio:

### 9.1 Errores indistinguibles frente a `errors` detallado

`ADR-038 §6.3` fija que `errors` lleve `code`, `message` y `params` por campo. Este módulo lo cumple **en la validación** (política de contraseñas, formato de correo) y **no** en las respuestas de credencial, token o bloqueo: ahí el cuerpo es idéntico caso a caso, sin `errors`, por lo dicho en `funcional.md §4.7`. No es una excepción al ADR —el ADR no obliga a detallar lo que no es un error de campo— pero conviene que la revisión lo lea escrito.

### 9.2 `429` y `Retry-After`

`ADR-038 §6.5` exige `Retry-After` en `429`. Aquí importa más que en ningún otro módulo: los clientes de estos endpoints son formularios de personas nerviosas que reintentan.

### 9.3 Sin `Idempotency-Key` en ningún endpoint

`ADR-038 §8.1` da el criterio: la cabecera es obligatoria donde un reintento duplicaría un efecto irreversible o cobrable. Ninguno de los diez endpoints cumple eso. Un login repetido es un login; un canje repetido devuelve `410`; un restablecimiento repetido devuelve `410`; una solicitud de recuperación repetida sustituye el token anterior; un cambio de contraseña repetido con la misma "actual" (ya sustituida) falla con `422`, que es el comportamiento correcto, no un duplicado silencioso. **Añadir la cabecera aquí sería ceremonia sin efecto**, y la disciplina de `INV-011` se erosiona más rápido exigiéndola donde no hace falta que no exigiéndola donde sí.

### 9.4 `PATCH` de `tenant/settings`

Semántica de fusión de `ADR-038 §9.2`, ya implementada en 1.1. `session_timeout_minutes` es escalar: ausente no toca el campo, `null` es `422` (la columna es `NOT NULL`).

### 9.5 Enumerados

`outcome` de `login_attempts` y `status` derivado de `account_lockouts` son cadenas en minúsculas con `_`, en el idioma del dominio del código (`ADR-038 §3.2`): `vigente`, `levantado`, `credenciales_invalidas`. La traducción para mostrar la resuelve el cliente por catálogo, nunca cambiando el valor.

---

## 10. Eventos de dominio

Emitidos y consumidos: `funcional.md §8`. Ninguno se expone por API en 1.2.

## 11. Webhooks

Ninguno. La notificación saliente de eventos de seguridad hacia sistemas de terceros no está requerida en `REQ-AUTH` y sería, además, una decisión con implicaciones de protección de datos propias.
