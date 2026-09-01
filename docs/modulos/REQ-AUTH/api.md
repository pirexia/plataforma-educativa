# REQ-AUTH · API

> **Estructura**: las secciones **§1 a §11** son el paso **1.2**, cerrado el 2026-08-25. La **Parte B** (`§B.1` en adelante) es el paso **1.2b** (`funcional.md` Parte B), **implementada y cerrada** el 2026-08-26 (PR [#91](https://github.com/pirexia/plataforma-educativa/pull/91)/[#92](https://github.com/pirexia/plataforma-educativa/pull/92)).

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

> **Fuera de 1.2**: `GET /auth/sessions` (listado de sesiones activas) y `DELETE /auth/sessions` (cierre en todos los dispositivos) son `REQ-AUTH-005` puntos 2-3, paso **1.2b** ([#59](https://github.com/pirexia/plataforma-educativa/issues/59)). **Especificados en la Parte B de este documento** (`§B.2` a `§B.4`).

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
| 3 | `EncryptCookies` | **Nuevo en 1.2** (framework, imprescindible aunque no era necesaria antes de la cookie de sesión). Antes de leer o escribir cualquier cookie cifrada — sin ella, ni la cookie de sesión ni `XSRF-TOKEN` funcionan, y el CSRF fallaría siempre |
| 4 | `AddQueuedCookiesToResponse` | Framework |
| 5 | `StartSession` | **Nuevo en 1.2.** Después del tenant: la sesión pertenece a un tenant ya resuelto |
| 6 | `ValidateCsrfToken` | **Nuevo en 1.2.** Después de la sesión, que es de donde sale el token |
| 7 | `VerifySessionTenant` | **Nuevo en 1.2.** `RN-AUTH-31`: el `tenant_id` del *payload* frente al resuelto por host. Discrepancia ⇒ sesión invalidada, `401` y auditoría |
| 8 | `EnforceSessionIdleTimeout` | **Nuevo en 1.2.** `REQ-AUTH-005` punto 1. Después de la reverificación: no tiene sentido refrescar la actividad de una sesión que se va a invalidar |
| 9 | `ResolveApiLocale` | **Se mueve en 1.2.** Hoy corre en la posición 3 y por tanto `$request->user()` siempre le devuelve `null`: el paso 1 de `ADR-038 §11` no se aplica nunca fuera de los tests con `actingAs()`. Ver `funcional.md §1.4` punto 4 y `CA-AUTH-075` |

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

---
---

# Parte B · Paso 1.2b · API

> Alcance: paso **1.2b** (`funcional.md` Parte B). **Tres endpoints nuevos**, todos bajo `/api/v1/auth/sessions`, todos **por identidad del portador de la cookie** y **ninguno con permiso declarado**.
>
> Mismas convenciones transversales de `ADR-038` que la Parte A; este documento solo señala dónde 1.2b las usa de forma menos obvia (`§B.7`).
>
> **Estado**: implementada, aprobada el 2026-08-25 (`funcional.md §B.14`), cerrada el 2026-08-26.

---

## B.1 Reglas generales: qué cambia respecto de §1

Casi nada, y eso es señal de que el paso encaja donde debe. Solo tres líneas de la tabla de §1 se leen distinto:

| Aspecto | 1.2b |
|---------|------|
| Autorización | Los tres endpoints son **por identidad**, como `DELETE /auth/session` y `POST /auth/password-changes`. **Ningún permiso nuevo** (`permisos.md §B.1`). El `401` sin sesión lo lanza el controlador con `ApiException::unauthenticated()`, que es el patrón que ya sigue `PasswordChangesController` |
| Aislamiento | Además de RLS y del *scope* de tenant, **el `user_id` del solicitante entra en el `WHERE`** (`RN-AUTH-41`). Una sesión de otro usuario del mismo tenant responde `404` exactamente igual que una de otro tenant: la comprobación de propiedad no es una capa de autorización aparte, es parte de la consulta |
| Límite de tasa | **Ninguno nuevo.** Los tres exigen sesión, así que no amplían la superficie anónima que `operacion.md §6` defiende. Los `429` que puedan aparecer son los del limitador global, no de un *bucket* propio |
| Módulo desactivado | Igual que la Parte A: **ninguna ruta lleva `module-enabled`** (`CA-AUTH-078` cubre también estas tres) |
| OpenAPI | Los tres, en `apps/api/openapi/paths/auth.yaml`, antes del merge (`CLAUDE.md §10`) |

### B.1.1 Tipos de error: **1.2b no añade ninguno**

1.2 tuvo que ampliar el catálogo cerrado de `ADR-038 §6.2` con `urn:pge:error:account-locked`, y lo justificó. **1.2b no amplía nada**: los cuatro estados que usa —`401`, `404`, `409`, `422`— ya tienen su `type` y su método en `App\Support\Api\ApiException` (`unauthenticated()`, `notFound()`, `conflict()`, `validation()`).

Se dice en voz alta porque la tentación era un `urn:pge:error:session-already-closed` para el `409`. No hace falta: `ADR-038 §6.3` ya obliga a que el cuerpo lleve `detail` traducido, y el cliente que llama a este endpoint sabe qué estaba intentando hacer. Un `type` propio se justifica cuando el cliente necesita **ramificar** sin analizar texto —que es lo que pasaba con «cuenta bloqueada» frente a «credenciales incorrectas»—, y aquí no hay dos pantallas que distinguir.

---

## B.2 `GET /api/v1/auth/sessions` — mis sesiones activas (`REQ-AUTH-005` punto 3)

Las sesiones vivas del **usuario autenticado**. No admite parámetro de usuario, ni existe forma de pedir las de otro (`RN-AUTH-41`).

- **Permiso**: ninguno declarado — **por identidad**.
- **Cabeceras**: sesión válida. Sin `X-XSRF-TOKEN` (es `GET`).
- **Parámetros de consulta**

| Parámetro | Tipo | Nota |
|-----------|------|------|
| `sort` | `started_at\|-started_at\|last_activity_at\|-last_activity_at` | Por defecto `-last_activity_at` |
| `page`, `per_page` | int | Por defecto 25, máximo 100 |

  **No hay filtro `status`.** El recurso son las sesiones **activas**; una sesión cerrada no es un estado de este listado, es una fila que dejó de pertenecer a él. Añadir `?status=cerradas` convertiría el panel en un historial de accesos, que es lo que `funcional.md §B.1.2` deja fuera del paso y lo que `permisos.md §6` advirtió que necesita su propia decisión de permisos.

- **Respuesta 200**

```json
{
  "data": [
    {
      "public_id": "01J8...",
      "current": true,
      "started_at": "2026-08-25T08:03:11Z",
      "last_activity_at": "2026-08-25T09:41:02Z",
      "ip_address": "88.1.2.3",
      "client": {
        "browser": "Chrome",
        "platform": "Windows",
        "device_type": "escritorio"
      },
      "location": null,
      "device_known": true
    }
  ],
  "meta": { "current_page": 1, "per_page": 25, "total": 3, "last_page": 1 }
}
```

Notas sobre el recurso, todas con consecuencia:

- **`current`** es derivado, no una columna: `true` en la fila cuyo `session_id` coincide con el de la petición en curso. **Exactamente una** fila lo lleva (`CA-AUTH-082`). Es lo que permite a la SPA avisar antes de que alguien cierre su propia sesión sin darse cuenta.
- **`last_activity_at`** no sale de `user_sessions`: se lee de `sessions.last_activity` uniendo por el identificador de sesión (`datos.md §B.2`, «sin columna de última actividad»). Es la única razón por la que el listado toca la tabla del framework, y la toca **solo para leer**.
- **`ip_address`** se devuelve **completa**, no enmascarada. Es la IP del propio titular, mostrada solo a él, y enmascararla («88.1.2.\*») destruiría justo la información que le permite decir «esa no es mi conexión». Consecuencia que hay que aceptar: quien secuestre una sesión ve el historial de IP de las demás sesiones de esa persona. Es estrictamente menos de lo que ya obtiene con la sesión secuestrada.
- **`location`** es **siempre `null` en 1.2b** (`RN-AUTH-47`, `OPEN-AUTH-13`). Se devuelve el campo en vez de omitirlo para que el cliente no tenga que cambiar de forma cuando se resuelva la pregunta abierta; el cliente debe tratar `null` como «desconocida» y no pintar nada, nunca como un error.
- **`device_known`** dice si esa sesión venía de un dispositivo ya reconocido. Es lo que da sentido a la fila para el usuario: una sesión con `device_known: false` es exactamente aquella de la que se le avisó por correo.
- **`client.device_type`** es un enumerado en el idioma del dominio del código (`ADR-038 §3.2`): `escritorio`, `movil`, `tableta`, `bot`, `desconocido`. Lo traduce el cliente por catálogo, y **debe tener rama por defecto** (`ADR-038 §7.3`): un valor que no conozca se muestra en crudo, nunca rompe la tabla.
- **No aparecen, en ningún caso**: el identificador de sesión, el *payload*, el valor o el hash de la cookie `pge_device`, ni el `User-Agent` crudo. El `User-Agent` se omite a propósito aunque esté en la tabla: es ruido para el usuario y una huella para quien lea la respuesta (`RN-AUTH-40`, `CA-AUTH-083`).

- **Efecto colateral**: el listado **cierra perezosamente** las filas cuya sesión ya no existe en `sessions` (`funcional.md §B.4.2` punto 3). Es la única escritura de un `GET` en todo el módulo, y se anota aquí porque una revisión razonable la señalaría: es reconciliación de estado propio, no una acción del usuario, y sigue el precedente exacto del cierre perezoso de bloqueos vencidos de `funcional.md §4.4`.

- **Errores**: `401` (sin sesión), `422` (parámetro de consulta inválido)
- **Paginación**: por página, no por cursor (`ADR-038 §4.2`): un usuario tiene unidades de sesiones, no una tabla de alto crecimiento
- **Idempotencia**: no procede (es `GET`)

---

## B.3 `DELETE /api/v1/auth/sessions/{public_id}` — revocar una sesión (`REQ-AUTH-005` punto 3)

- **Permiso**: ninguno declarado — **por identidad**. La comprobación de propiedad va **en la consulta**, no en un `if` posterior.
- **Cabeceras**: sesión válida; `X-XSRF-TOKEN` obligatoria (`RN-AUTH-29`)
- **Efecto, en una transacción**: se borra la fila de `sessions` y se cierra la de `user_sessions` con `ended_at`, `end_reason = 'revocada_usuario'` y `ended_by` = el solicitante. **La fila se conserva**, igual que la de un bloqueo levantado (`RN-AUTH-18`): `DELETE` describe la desaparición de **la sesión**, no la de la fila.
- **Respuesta 204**
- **Errores**

| Estado | `type` | Cuándo |
|--------|--------|--------|
| `401` | `urn:pge:error:unauthenticated` | Sin sesión |
| `404` | `urn:pge:error:not-found` | Inexistente, **de otro usuario del mismo tenant**, o **de otro tenant**. **Cuerpo idéntico en los tres casos** (`RN-AUTH-41`, `ADR-038 §6.4`, `CA-AUTH-087`) |
| `409` | `urn:pge:error:conflict` | La sesión ya estaba cerrada |
| `403`/`419` | | Token CSRF ausente o inválido |

**Por qué `404` y no `403` cuando la sesión es de otro usuario del mismo tenant.** `ADR-038 §6.4` lo fija para recursos de otro tenant, y aquí se extiende dentro del mismo tenant por el mismo motivo: `403` significa «existe, pero no puedes», y eso convierte el endpoint en un oráculo con el que un usuario del centro podría comprobar si un `public_id` corresponde a una sesión viva de otra persona. `404` no dice nada.

**Revocar la sesión actual está permitido** (`funcional.md §B.4.3` punto 7): responde `204`, destruye la sesión y caduca la cookie, como el logout. No es un caso especial en el contrato; sí lo es en la SPA, que sabe cuál marcó `current` y redirige al login.

- **Idempotencia**: no procede. Un segundo `DELETE` sobre la misma sesión responde `409`, que es el comportamiento correcto y no un duplicado silencioso — mismo criterio que §9.3

---

## B.4 `DELETE /api/v1/auth/sessions` — cierre en todos los dispositivos (`REQ-AUTH-005` punto 2)

- **Permiso**: ninguno declarado — **por identidad**.
- **Cabeceras**: sesión válida; `X-XSRF-TOKEN` obligatoria
- **Parámetros de consulta**

| Parámetro | Tipo | Por defecto | Efecto |
|-----------|------|-------------|--------|
| `scope` | `others\|all` | **`others`** | `others`: cierra todas **salvo la actual**. `all`: cierra **todas, incluida la actual** |

- **Efecto, en una transacción**: se borran las filas de `sessions` correspondientes y se cierran las de `user_sessions` con `end_reason = 'revocada_usuario'` y `ended_by` = el solicitante. Con `scope=all` se destruye además la sesión en curso y se caduca su cookie.
- **Respuesta 204**, también cuando no había ninguna otra sesión que cerrar (`CA-AUTH-091`). Cerrar un conjunto vacío no es un error, por el mismo argumento con el que §2 hizo idempotente el logout.
- **Errores**: `401`; `422` si `scope` no es uno de los dos valores; `403`/`419` por CSRF
- **Idempotencia**: no procede

**Por qué el ámbito va en un parámetro de consulta y no en dos endpoints.** Se consideraron las tres formas:

| Forma | Por qué no, o por qué sí |
|-------|--------------------------|
| **Dos llamadas del cliente**: `DELETE /auth/sessions` (las demás) + `DELETE /auth/session` (logout) | Funciona y no inventa semántica. Pero deja el punto 2 del requisito —«cierre de sesión en todos los dispositivos»— sin un endpoint que lo cumpla, repartido entre dos y sin atomicidad. Un cliente que haga la primera y falle en la segunda deja al usuario creyendo que cerró todo |
| **Endpoint aparte** (`POST /auth/session-purges` o similar) | Un recurso nuevo para expresar un adverbio. `ADR-038` no premia eso |
| **`scope` en la cadena de consulta** ✔ | Una sola operación, atómica, con el requisito cumplido literalmente. Y el valor por defecto es el que **no** expulsa a quien llama: un cliente que se olvide del parámetro nunca se echa a sí mismo del sistema (`RN-AUTH-43`) |

Un parámetro de consulta que modifica el alcance de un `DELETE` es poco habitual, y por eso se argumenta en vez de darse por bueno. La alternativa —cuerpo en un `DELETE`— está desaconsejada y no se usa en ningún endpoint del producto.

---

## B.5 Superficie del módulo tras 1.2b

Amplía la tabla de §7, que sigue siendo el resumen correcto del paso 1.2.

| Método y ruta | Auth | Permiso | Éxito |
|---------------|------|---------|-------|
| `GET /api/v1/auth/sessions` | Sesión (por identidad) | — | 200 |
| `DELETE /api/v1/auth/sessions/{public_id}` | Sesión (por identidad) | — | 204 |
| `DELETE /api/v1/auth/sessions` | Sesión (por identidad) | — | 204 |

**Trece endpoints en total, seis anónimos.** El número de endpoints anónimos **no cambia**, y es el dato relevante: 1.2b crece la superficie del módulo un 30 % sin añadir ni un endpoint a la superficie que hay que defender sin usuario. Cualquier endpoint anónimo que se añada a este módulo en el futuro sigue teniendo que justificarse en la revisión de seguridad (§7).

---

## B.6 Cadena de *middleware* y cookies

**La cadena de §8 no cambia.** Ni un *middleware* nuevo, ni un cambio de orden. Merece decirse porque la alternativa evidente —comprobar en cada petición si la sesión ha sido revocada— habría añadido una posición a una cadena que atraviesa **todas** las peticiones del producto; el diseño de `RN-AUTH-42` (borrar la fila de `sessions`) la hace innecesaria.

**Una cookie nueva en las respuestas del módulo**, y solo en la del login:

| Cookie | Cuándo se emite | Atributos |
|--------|-----------------|-----------|
| `pge_device` | En la respuesta `200` de `POST /auth/session`, **solo** cuando el dispositivo no se reconoce | `HttpOnly`, `Secure`, `SameSite=Lax`, **sin `Domain`** (host-only), 365 días (`RN-AUTH-45`) |

**No se emite en ninguna respuesta anónima**, y en particular **no** en `GET /auth/csrf-cookie` (`funcional.md §B.6.2`). Un identificador persistente de navegador entregado antes de autenticarse es una cookie de seguimiento, no de seguridad.

**No se añade a las excepciones de `EncryptCookies`**: va cifrada como todas, aunque su valor ya sea opaco.

---

## B.7 Convenciones transversales: dónde 1.2b se aparta o matiza

### B.7.1 Un `GET` que escribe

`GET /auth/sessions` cierra filas caducadas al pasar (`§B.2`). No es un `GET` que cambie el estado observable por el cliente —el resultado es idéntico con o sin ese efecto—, sino reconciliación de estado interno, y por eso no rompe la semántica de `ADR-038`. Se documenta porque es la única de todo el módulo y una revisión razonable la señalaría.

### B.7.2 Sin `Idempotency-Key`, otra vez

`ADR-038 §8.1`, mismo criterio de §9.3: la cabecera es obligatoria donde un reintento duplicaría un efecto irreversible o cobrable. Revocar una sesión ya revocada responde `409`; revocar un conjunto vacío responde `204`. Ninguno de los dos es un duplicado silencioso.

### B.7.3 `null` no es un error

`location` viaja como `null` en todas las respuestas de 1.2b. El cliente **no** debe tratarlo como fallo ni como campo ausente: es el hueco declarado de `OPEN-AUTH-13`. Se anota porque es exactamente el tipo de contrato que un cliente escrito con prisa convierte en un `Cannot read property of null`.

### B.7.4 Enumerados

`end_reason` y `client.device_type` son cadenas en minúsculas con `_`, en el idioma del dominio del código (`ADR-038 §3.2`, §9.5). `end_reason` **no se expone en 1.2b** —el listado solo devuelve sesiones vivas—, pero está en el contrato de datos y lo consumirá 1.6.

---

## B.8 Eventos de dominio y webhooks

Dos eventos nuevos publicados, `SessionRevoked` y `NewDeviceDetected` (`funcional.md §B.8.2`). **Ninguno se expone por API**, igual que los siete de §10.

**Webhooks: ninguno**, por el mismo motivo de §11 y con un agravante propio: notificar a un tercero que una persona ha iniciado sesión desde un dispositivo nuevo es enviarle un dato de comportamiento, no un evento de negocio.

---
---

# Parte C · Paso 1.3 · API (`REQ-AUTH-003`)

> **Estructura**: §1-§11 son 1.2 (cerrado). §B.1-§B.8 son 1.2b (cerrado). Esta **Parte C** es el paso **1.3**, **implementada y cerrada** el 2026-08-27 (PR [#107](https://github.com/pirexia/plataforma-educativa/pull/107), commit `cd13e8a`).
>
> Convenciones de `ADR-038` sin excepción, salvo lo que `§C.8` matiza explícitamente.

---

## C.1 Reglas generales: qué cambia respecto de §1 y §B.1

**Nota de partición (`OPEN-AUTH-24`, `funcional.md §C.16`):** la especificación original de este paso incluía también la excepción temporal nominal (recurso `exencion_mfa`, endpoints `GET`/`POST`/`DELETE /mfa-exemptions`) y un listado individualizado de usuarios (`GET /mfa-compliance/users`). El usuario partió el paso en `1.3`/`1.3b` el 2026-08-26, y **1.3 dejó de entregar los dos**. **Corrección del 2026-08-27**: un subagente había agrupado el listado individualizado con la excepción temporal por error — solo esta última estaba en el alcance movido a `1.3b`. El usuario revisó el hallazgo y decidió restaurar `GET /mfa-compliance/users` en `1.3`; `exencion_mfa` y sus tres endpoints **siguen** en `1.3b`. Esta sección documenta lo que `1.3` entrega de verdad, incluida esa restauración.

| Aspecto | 1.3 |
|---------|-----|
| Autenticación | Sin cambios (`ADR-025`). **El segundo paso del login se autoriza con la misma cookie de sesión anónima**, no con un token nuevo (`RN-AUTH-53`) |
| Autorización | De los **10 endpoints nuevos de este módulo**: **2 autorizados por la cookie del desafío** (mecanismo nuevo, `permisos.md §C.4`), **5 por identidad del portador**, **3 con permiso declarado**. Más **1 en `REQ-CORE`** (`PATCH /roles/{public_id}`, permiso `rol.actualizar`). Es la primera vez que este módulo aporta permisos más allá de `bloqueo_cuenta` (`permisos.md §C.3`) |
| Aislamiento | Sin cambios. Recurso de otro tenant ⇒ `404`, nunca `403` (`ADR-038 §6.4`) |
| Idempotencia | **Ningún endpoint exige `Idempotency-Key`** (`§C.8.2`) |
| Auditoría | `INV-003`, **sin ampliar el vocabulario** (`funcional.md §C.10`). Todo por el *observer* de 0.9, salvo `login`, que ya existía |
| Módulo desactivado | No aplica: ninguna ruta lleva `module-enabled` (`RN-AUTH-35`, `CA-AUTH-145`) |
| Límite de tasa | Los anónimos y los de alta/regeneración, por IP y por sujeto. `operacion.md §C.6` |
| OpenAPI | Los 10 en `apps/api/openapi/paths/mfa.yaml`; el `PATCH` de roles en `core.yaml`, que es de quien es el recurso |

### C.1.1 Tipo de error nuevo: **uno**

`ADR-038 §6.2` declara su catálogo *«cerrado y ampliable solo por ADR o por especificación de módulo»*. 1.2 añadió uno (`account-locked`); 1.2b no añadió ninguno. Esta especificación añade **uno**:

| `type` | Estado | Cuándo |
|--------|--------|--------|
| `urn:pge:error:mfa-enrollment-required` | **403** | La sesión está **restringida**: el usuario está obligado a MFA, su plazo de gracia venció y el endpoint no está en la lista blanca del muro (`funcional.md §C.9`) |

**Por qué `403` y no `401`.** La sesión es válida y el usuario está identificado: lo que falta no es autenticación sino una condición de cumplimiento. Devolver `401` haría que el interceptor genérico de la SPA —el que 1.8 construirá— lo tratara como sesión caducada y llevara al usuario al login, del que volvería a salir al mismo muro, en bucle.

**Por qué un `type` propio y no `forbidden`.** El cliente tiene que distinguir «no tienes permiso para esto» de «completa tu alta de MFA y vuelve», que son dos pantallas distintas. Es el mismo argumento con el que `ADR-038 §6.2` separó `module-disabled` de `forbidden` compartiendo estado, y con el que §1.1 separó `account-locked`.

**Lo que este error `403` sí lleva y los demás no**: el cuerpo incluye `grace_deadline_at` (ya vencido) y la ruta del muro, para que la SPA no tenga que pedir `/me` antes de redirigir.

**Qué se reutiliza y no se inventa**: `urn:pge:error:gone` (410) para desafío y alta inexistentes, caducados, consumidos o de otra sesión; `urn:pge:error:conflict` (409) para la desactivación de un factor exigido; `urn:pge:error:validation` (422) para código incorrecto, contraseña actual incorrecta y método no admitido por el tenant.

### C.1.2 **No hay estado nuevo para «se necesita segundo factor»**

Se resuelve con **`202 Accepted`** sobre `POST /auth/session`, no con un error. `§C.2` lo argumenta.

---

## C.2 `POST /api/v1/auth/session` — **modificado**: ahora puede responder `202`

El endpoint de §2 sigue siendo *«el único camino del sistema que crea una sesión»* (`RN-AUTH-21`). Lo que cambia es que **crearla deja de ser lo único que puede hacer**.

- **Permiso**: ninguno. Anónimo. Sin cambios.
- **Cabeceras**: `X-XSRF-TOKEN` obligatoria. Sin cambios.
- **Cuerpo**: sin cambios.
- **Límites de tasa**: sin cambios.

**Respuestas:**

| Estado | Cuándo | Cuerpo |
|--------|--------|--------|
| `200` | Login completo: sin segundo factor exigible, o el usuario está obligado pero **dentro** de su plazo de gracia, o **fuera** de él (sesión restringida) | El recurso de `GET /me`, ahora con el bloque `mfa` de `§C.4` |
| **`202`** | **Credencial correcta y segundo factor exigible.** Se abre desafío y **no se crea sesión autenticada** (`RN-AUTH-52`) | El recurso del desafío, abajo |

Los errores de §2 se conservan **literalmente**: `401` genérico e indistinguible, `423` con bloqueo vivo, `422`, `429` con `Retry-After`, `403`/`419` por CSRF, `404` sin tenant, `503` con tenant suspendido.

**Cuerpo del `202`:**

```json
{
  "public_id": "01JD7...",
  "method": "totp",
  "available_methods": ["totp"],
  "expires_at": "2026-08-26T10:35:00Z",
  "has_unused_recovery_codes": true
}
```

- **`has_unused_recovery_codes` es un booleano y no un número**: decir «te quedan 2» a alguien que aún no ha demostrado ser el titular es información de más.
- **No hay ningún token, ni el `session_id`, ni el secreto, ni el código entregado** (`CA-AUTH-116`).
- **`available_methods` en 1.3 solo puede contener `totp`**: el correo como método de entrega es `1.3b` (`§C.1`), así que hoy nunca hay más de un método entre los que ofrecer.

### C.2.1 Por qué `202` y no `401`, `403` o un `200` polimórfico

| Alternativa | Por qué no |
|-------------|------------|
| **`401` con un `type` propio** | Es lo más común y es lo peor aquí. Todo cliente HTTP genérico —y el interceptor que 1.8 va a escribir— trata `401` como «sesión caducada, al login». Un login que responde `401` para decir «vas bien, sigue» obliga a poner una excepción en el sitio del código que menos excepciones debería tener |
| **`403`** | «Prohibido» es falso: no está prohibido, está a medias |
| **`200` con un cuerpo distinto** | El `200` de este endpoint es, por contrato de §2, *el mismo recurso que `GET /me`*. Devolver a veces un perfil y a veces un desafío bajo el mismo estado obliga a todo cliente tipado a distinguirlos inspeccionando campos. `ADR-038 §7.3` pide precisamente lo contrario: ramas explícitas |
| **Endpoint distinto para «login que puede requerir MFA»** | Dos rutas de login es dos superficies anónimas que proteger, dos límites de tasa y dos sitios donde equivocarse |

**`202 Accepted` describe exactamente lo que pasa**: la petición se ha aceptado y el proceso que inicia no está completo. Además cae en el rango 2xx, así que el cliente propio de la SPA (`src/api/client.ts`, que lanza `ApiError` en todo lo que no sea 2xx) la trata como éxito sin tocar su manejo de errores. Y hay precedente en el propio módulo: `POST /auth/password-reset-requests` responde `202` (§4).

---

## C.3 Segundo paso del login

> **Nota añadida por 1.4 (2026-09-01)**: la superficie de `mfa_challenges` **gana un `GET`** en el paso 1.4, documentado en **`§E.5b`**. No es una modificación de nada de 1.3 —los dos `POST` de esta sección siguen exactamente igual— sino un *endpoint* nuevo que necesita el login federado, que llega al segundo factor por un `302` sin datos. Se anota aquí para que quien busque la superficie de este recurso la encuentre entera desde la Parte C, y no solo desde la Parte E.

### `POST /api/v1/auth/mfa-verifications`

Completa el login superando el desafío abierto.

- **Permiso**: ninguno. **Autorizado por la cookie de sesión que abrió el desafío** (`RN-AUTH-53`). No es «anónimo» en el sentido de §1 —hay una sesión, aunque no autenticada— y no es «por identidad» —no hay identidad todavía—: es una tercera categoría que este endpoint estrena y que `permisos.md §C.4` describe.
- **Cabeceras**: `X-XSRF-TOKEN` obligatoria (`RN-AUTH-29`).
- **Cuerpo**: exactamente **uno** de los dos:

```json
{ "code": "123456" }
```
```json
{ "recovery_code": "H7K2M-9PQR4" }
```

- **Respuesta `200`**: el recurso de `GET /me`. Establece la cookie de sesión con identificador **regenerado** (`RN-AUTH-32`) y emite la cookie `pge_device` si procede (`§B.6.2`).
- **Errores**

| Estado | `type` | Cuándo |
|--------|--------|--------|
| `401` | `urn:pge:error:unauthenticated` | Código incorrecto, código de respaldo incorrecto o ya usado, o código TOTP de un paso ya consumido. **Cuerpo idéntico en los cuatro casos** |
| `410` | `urn:pge:error:gone` | Desafío inexistente, caducado, consumido, con los intentos agotados, o **de otra sesión**. Cuerpo idéntico en los cinco (`RN-AUTH-72`) |
| `422` | `urn:pge:error:validation` | Falta el código, o vienen los dos campos, o el formato no encaja |
| `423` | `urn:pge:error:account-locked` | El fallo de segundo factor alcanzó el umbral de `RN-AUTH-14` (`funcional.md §C.4.4.2`) |
| `429` | `urn:pge:error:too-many-requests` | Con `Retry-After` |
| `403`/`419` | | CSRF |

- **Idempotencia**: no. Repetirlo con el mismo código correcto responde `410` la segunda vez: el desafío ya se consumió.

### `POST /api/v1/auth/mfa-challenges`

Cambia el método del desafío en curso o reenvía su código.

- **Permiso**: ninguno; misma autorización por cookie que el anterior.
- **Cuerpo**: `{"method": "totp"}`
- **Respuesta `200`**: el mismo recurso del desafío de `§C.2` (sin `destination_masked`: en 1.3 no hay ningún método de entrega dado de alta, ver `§C.1`), actualizado.
- **Reglas que la respuesta refleja**: el contador de intentos del desafío **no se reinicia** y `expires_at` **no se mueve** (`RN-AUTH-54`).
- **Errores**: `410` (sin desafío vivo), `422` (método no dado de alta por el usuario entre los que el tenant admite), `429` (límite de reenvíos, `operacion.md §C.6`), `403`/`419`.

---

## C.4 Autoservicio del propio usuario

Los cinco se autorizan **por identidad del portador de la cookie**, sin permiso, igual que `DELETE /auth/session` y `POST /auth/password-changes` (`permisos.md §1`). **Ninguno acepta un identificador de usuario en el cuerpo ni en la ruta** (`RN-AUTH-73`).

### `GET /api/v1/auth/mfa`

Mi estado de MFA.

- **Respuesta `200`** (recurso individual desnudo, `ADR-038 §3.1`):

```json
{
  "factors": [
    { "public_id": "01JD7...", "method": "totp", "confirmed_at": "2026-03-01T09:12:00Z",
      "last_used_at": "2026-08-26T07:41:00Z", "is_preferred": true }
  ],
  "unused_recovery_codes_count": 7,
  "mfa": {
    "enrolled": true,
    "obligated": true,
    "enforced": false,
    "grace_deadline_at": null,
    "days_remaining": null
  }
}
```

- **`factors` solo lista los confirmados** (`RN-AUTH-59`). Un alta a medias no es un factor y no aparece.
- **`mfa` es el mismo bloque que `GET /me`/`POST /auth/session` (`§C.6`)**, repetido aquí para que la pantalla de `/cuenta/seguridad` no tenga que pedir dos recursos.
- **Nunca contiene el secreto, ni un hash, ni un código** (`CA-AUTH-109`).
- **Errores**: `401`.

### `POST /api/v1/auth/mfa-enrollments`

Inicia el alta de un factor. **No lo activa.** **En 1.3, `method` solo admite `totp`** — el correo como método es `1.3b` (`§C.1`); el cuerpo lo declara `email`/`sms` como valores de forma válidos (para no romper el cliente cuando `1.3b` llegue), pero el servidor los rechaza con `422` hoy, sea o no el tenant quien los admita.

- **Cuerpo**: `{"method": "totp"}`
- **Respuesta `201`**:

```json
{
  "public_id": "01JD7...",
  "method": "totp",
  "secret": "JBSWY3DPEHPK3PXP",
  "otpauth_uri": "otpauth://totp/Colegio%20Ficticio:ana.perez%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Colegio%20Ficticio&algorithm=SHA1&digits=6&period=30",
  "expires_at": "2026-08-26T10:40:00Z"
}
```

- **`secret` y `otpauth_uri` salen aquí y en ningún otro sitio nunca más** (`RN-AUTH-55`). Una segunda llamada crea un alta **nueva** con un secreto **nuevo**; no reexpone el anterior.
- **`secret` en texto es obligatorio, no opcional**: es el único camino para quien no puede escanear un QR, y sin él la pantalla no cumple WCAG 2.2 AA (`funcional.md §C.11`).
- **El servidor no devuelve imagen**: el QR lo pinta la SPA a partir de `otpauth_uri` (`OPEN-AUTH-20`).
- **Errores**: `401`; `422` (método no implementado en 1.3, no admitido por el tenant, o ya hay un factor confirmado de ese método); `429`.

### `POST /api/v1/auth/mfa-factors`

Confirma el alta y activa el factor de verdad.

- **Cuerpo**: `{"enrollment": "01JD7...", "code": "123456"}`
- **Respuesta `201`**:

```json
{
  "factor": {
    "public_id": "01JD7...",
    "method": "totp",
    "confirmed_at": "2026-08-26T10:33:00Z"
  },
  "recovery_codes": ["H7K2M-9PQR4", "…"]
}
```

- **`recovery_codes` aparece solo si se han generado** en esta llamada, es decir, solo si era el primer factor confirmado del usuario (`funcional.md §C.4.3`); en los siguientes es `null`.
- **Es la única vez que los códigos salen del servidor.** La pantalla tiene que decirlo antes de que el usuario cierre el diálogo.
- **Errores**: `401`; `422` (código incorrecto — el alta sobrevive y se consume un intento); `410` (alta inexistente, caducada, de otro usuario o con los intentos agotados, cuerpo idéntico); `429`.

### `DELETE /api/v1/auth/mfa-factors/{public_id}`

Desactiva un factor propio.

- **Cuerpo**: `{"current_password": "···"}` — sí, un `DELETE` con cuerpo, y `§C.8.3` lo justifica.
- **Respuesta `204`**.
- **Errores**

| Estado | `type` | Cuándo |
|--------|--------|--------|
| `409` | `urn:pge:error:conflict` | **Algún rol del usuario exige MFA** y este es su último factor utilizable (`RN-AUTH-61`). El cuerpo dice qué roles lo exigen |
| `422` | `urn:pge:error:validation` | Contraseña actual ausente o incorrecta. **`422` y no `401`**, mismo criterio que §5b: la sesión es válida, lo que falla es el dato del formulario |
| `404` | `urn:pge:error:not-found` | Factor inexistente, **de otro usuario** o de otro tenant. Cuerpo idéntico (`RN-AUTH-73`) |
| `401` | | Sin sesión |

### `POST /api/v1/auth/mfa-recovery-codes`

Regenera el juego de códigos de respaldo.

- **Cuerpo**: `{"current_password": "···"}`
- **Respuesta `201`**: `{"recovery_codes": ["…"]}`. Los anteriores dejan de funcionar en el acto.
- **Errores**: `401`; `422` (contraseña ausente o incorrecta); `429`.

---

## C.5 Administración

Los tres declaran permiso. Los tres responden `404` con **cuerpo idéntico** ante un recurso de otro tenant (`ADR-038 §6.4`) y `403` sin permiso.

**Los tres de `/mfa-exemptions` (excepción temporal nominal) NO se entregan en 1.3** — `§C.1`. Se documentarán en su propia sección cuando `1.3b` los entregue. `GET /mfa-compliance/users` (listado individualizado), en cambio, **sí se entrega en 1.3**: restaurado el 2026-08-27 (`§C.1`, decisión del usuario que corrige un recorte no autorizado a `1.3b`).

### `GET /api/v1/mfa-compliance`

Estado de cumplimiento **y** vista previa de un rol, en el mismo endpoint. `REQ-AUTH-003` pide las dos cosas y son la misma consulta con y sin hipótesis (`funcional.md §C.1.1` punto 9, `CA-AUTH-136`).

- **Permiso**: `mfa.leer`
- **Parámetros**: `role={public_id}` (**obligatorio**) y `mfa_required={0|1|true|false}` (opcional)
- **Respuesta `200`**, sin `mfa_required` en la consulta (estado real del rol):

```json
{
  "role": { "public_id": "01J…", "code": "administrador_centro" },
  "mfa_required": true,
  "preview": false,
  "users_total": 3,
  "users_enrolled": 3,
  "users_obligated": 0,
  "users_in_grace": 0,
  "users_enforced": 0,
  "users_exempt": 0
}
```

- **Con `mfa_required` en la consulta**, la misma forma pero `preview: true`: `users_enrolled`/`users_obligated` reflejan la hipótesis (cuántos **quedarían** obligados si el rol pasara a tener ese valor), `users_in_grace`/`users_enforced` van siempre a `0` (no hay obligación real que clasificar en gracia o vencida sobre algo que no se ha guardado), y **no se escribe nada** (`CA-AUTH-136`).
- Es lo que 1.5 consumirá desde el editor de roles para pintar *«este cambio obligará a N usuarios más»* antes de guardar.
- Consulta **por rol**, no un `totals` del centro entero: cada llamada acota explícitamente `role`, para que el coste de la consulta sea el de un rol, no el de toda la población.

### `GET /api/v1/mfa-compliance/users`

Quiénes son. El requisito pide el estado *«consultable por el administrador: usuarios obligados, inscritos y pendientes»* (`funcional.md §C.1.1` punto 9), y eso es una lista, no un contador — es el complemento individualizado de `GET /mfa-compliance`. Restaurado en 1.3 el 2026-08-27 (`§C.1`).

- **Permiso**: `mfa.leer` (el mismo que el agregado — `permisos.md §C.6.1` explica por qué, y no `usuario.leer`)
- **Filtros**: `state`, uno o varios separados por coma (`ADR-038 §5.2`) de entre `obligated`, `enrolled`, `pending`, `past_deadline`, `exempt`. Sin filtro, todos.
  - `obligated` es un alias de conveniencia sobre `pending`+`past_deadline`: el dominio (`MfaObligationState`) no tiene un tercer estado propio con ese nombre — ninguna fila individual del `data` lleva literalmente `state: "obligated"`.
- **Paginación**: por página, como el resto de listados de administración (`ADR-038 §4.3`, mismo patrón que `GET /account-lockouts`)
- **Respuesta `200`**: colección `{"data": [...], "meta": {...}}`:

```json
{
  "data": [
    {
      "user": {
        "public_id": "01J…",
        "given_name": "Marta",
        "family_name_1": "Ruiz",
        "family_name_2": "Soto",
        "email": "marta.ruiz@example.com"
      },
      "state": "pending",
      "grace_deadline_at": "2026-09-02T10:00:00Z",
      "enrolled_methods": [],
      "required_by_roles": ["docente"]
    }
  ],
  "meta": { "current_page": 1, "per_page": 25, "total": 34, "last_page": 2 }
}
```

- **`user` lleva solo campos públicos** (nombre, correo). **Nunca** secretos, hashes ni recuento de códigos de respaldo restantes de nadie.
- **`grace_deadline_at` es `null`** salvo en `pending`/`past_deadline`.
- **`enrolled_methods`** son los métodos con factor confirmado (vacío si ninguno); **`required_by_roles`** son los `code` de los roles vivos del usuario que exigen MFA (vacío si ninguno le exige nada — puede pasar en `enrolled`, MFA voluntario).
- **Quien no está obligado por ningún rol, no está inscrito y no tiene excepción viva, no aparece en el listado**: no es información de cumplimiento.

### `POST /api/v1/mfa-resets`

Restablece el MFA de un usuario (`REQ-AUTH-003`, «recuperación»).

- **Permiso**: `mfa.eliminar`
- **Cuerpo**: `{"user": "01J…", "reason": "Extravío del dispositivo, identidad verificada presencialmente el 26/08"}`
- **Respuesta `204`**
- **Errores**

| Estado | `type` | Cuándo |
|--------|--------|--------|
| `422` | `urn:pge:error:validation` | `reason` ausente o de menos de 10 caracteres (`RN-AUTH-66`) |
| `403` | `urn:pge:error:forbidden` | **El administrador es el propio sujeto** (`RN-AUTH-67`). Es distinto de no tener permiso, y se distingue en el mensaje |
| `404` | `urn:pge:error:not-found` | Usuario inexistente o de otro tenant |

**Efecto** (`funcional.md §C.4.10`): borra sus factores y códigos (incluidos altas sin confirmar), **cierra todas sus sesiones** con `end_reason = 'cambio_credencial'`, escribe la fila de `mfa_resets` con el motivo, reabre la obligación con plazo completo si procede, y **encola** la notificación al afectado. No hay `409`: un usuario sin ningún factor sigue siendo un restablecimiento válido (`factors_removed: 0` en la traza), no un error.

---

## C.6 Ampliación de dos endpoints de `REQ-CORE`

### `PATCH /api/v1/roles/{public_id}` — **nuevo, y acotado a propósito**

Es el interruptor de `mfa_obligatorio` (`RPERM-014`). El argumento completo de por qué vive aquí y no en un sub-recurso de `REQ-AUTH` está en `funcional.md §C.2.2`.

- **Módulo**: `REQ-CORE`. `roles` es su recurso (`INV-007`); lo que aporta 1.3 es el método `update` en `RolesController`, que hoy solo tiene `index` y `show`.
- **Permiso**: `rol.actualizar`, **declarado en el catálogo de `REQ-CORE`** en este paso (`permisos.md §C.5`).
- **Cuerpo en 1.3**: exactamente `{"mfa_required": true}`. **Cualquier otra clave responde `422`.**
- **Respuesta `200`**: el recurso `RoleResource` completo.
- **Errores**: `401`, `403`, `404` (rol de otro tenant), `422` (campo desconocido o tipo incorrecto), `419`/`403` (CSRF).

**Contrato con 1.5, escrito para que no haya que negociarlo entonces**: 1.5 **amplía** el cuerpo admitido de este mismo método —mismo verbo, misma ruta, mismo permiso— con el resto de atributos y las concesiones. **No crea una ruta nueva ni retira esta.** Es *expand* puro sobre la superficie HTTP, y ningún cliente escrito contra 1.3 se rompe.

**Por qué el `422` ante un campo desconocido y no ignorarlo en silencio.** `ADR-038 §8` fija la semántica de `PATCH`: se aplica lo que viene. Ignorar `{"name": "otro"}` haría creer al cliente que renombró el rol. En 1.3 ese campo todavía no se puede escribir, y decirlo es la única respuesta honesta.

### `GET` / `PATCH /api/v1/tenant/settings` — grupo `security`, dos campos más

El grupo `security` de §6 gana `mfa_allowed_methods` y `mfa_grace_period_days`.

- **Permiso**: `configuracion.leer` / `configuracion.actualizar`. **Sin permiso propio**, por el mismo argumento con el que §6 gobernó `session_timeout_minutes` (`permisos.md §4.1`).
- **Validación** (`INV-010`): array no vacío, **`totp` obligatorio**, **`sms` rechazado** con mensaje explícito que dice que no hay proveedor (`RN-AUTH-69`), y `mfa_grace_period_days` entre 1 y 90.
- **Efecto colateral que hay que documentar en la pantalla**: quitar un método **invalida los factores existentes de ese método** y reabre la obligación de sus titulares con plazo completo (`funcional.md §C.4.12`). Esto ocurre **de forma asíncrona**, vía el listener `ReconcileMfaAllowedMethodsChange` sobre `TenantSettingsUpdated` (`INV-012`): la respuesta del `PATCH` es el `TenantSettingsResource` normal, **sin ningún recuento de usuarios afectados** — a diferencia de `PATCH /roles/{public_id}`, este endpoint no tiene modo de vista previa en 1.3.

### `GET /api/v1/me` y el `200` de `POST /auth/session` — bloque `mfa`

El recurso compartido de `UserProfilePresenter` gana:

```json
"mfa": {
  "enrolled": false,
  "obligated": true,
  "enforced": false,
  "grace_deadline_at": "2026-09-02T08:00:00Z",
  "days_remaining": 7
}
```

Es lo que sostiene *«avisos en cada acceso»* del requisito **sin endpoint nuevo y sin correo** (`funcional.md §C.4.8`). Lo reciben `GET /me` (`REQ-CORE`) y el login (`REQ-AUTH`) porque el presentador es de `App\Support` y ninguno de los dos módulos importa código del otro (`INV-007`).

**Es una ampliación aditiva del recurso** (`ADR-038 §7.3`): un cliente escrito contra 1.2 ignora la clave nueva.

---

## C.7 Superficie del módulo tras 1.3

| # | Método y ruta | Autorización | Paso |
|---|---------------|--------------|------|
| 1-10 | Los diez de §7 | | 1.2 |
| 11-13 | Los tres de §B.5 | Identidad | 1.2b |
| 14 | `POST /auth/mfa-verifications` | **Cookie del desafío** | **1.3** |
| 15 | `POST /auth/mfa-challenges` | **Cookie del desafío** | **1.3** |
| 16 | `GET /auth/mfa` | Identidad | **1.3** |
| 17 | `POST /auth/mfa-enrollments` | Identidad | **1.3** |
| 18 | `POST /auth/mfa-factors` | Identidad | **1.3** |
| 19 | `DELETE /auth/mfa-factors/{public_id}` | Identidad | **1.3** |
| 20 | `POST /auth/mfa-recovery-codes` | Identidad | **1.3** |
| 21 | `GET /mfa-compliance` | `mfa.leer` | **1.3** |
| 22 | `GET /mfa-compliance/users` | `mfa.leer` | **1.3** (restaurado 2026-08-27) |
| 23 | `POST /mfa-resets` | `mfa.eliminar` | **1.3** |
| — | `PATCH /roles/{public_id}` | `rol.actualizar` | **1.3, en `REQ-CORE`** |

**Modificados sin romper contrato**: `POST /auth/session` (añade `202`), `GET /me` y `PATCH /tenant/settings` (añaden campos).

**Diez endpoints nuevos en `REQ-AUTH` más uno en `REQ-CORE`.** La superficie del módulo pasa de 13 a 23 en este paso, y **la superficie anónima pasa de 6 a 8** (los dos del desafío no son anónimos del todo —exigen una sesión con desafío vivo— pero son alcanzables sin autenticación y se protegen como tales). Es el dato que más pesa en `OPEN-AUTH-24`.

Los tres de `/mfa-exemptions` (§C.16, `OPEN-AUTH-24`) **no están en esta tabla porque no se han construido en 1.3**: quedan para `1.3b`, junto con el método `email` como segundo factor. `GET /mfa-compliance/users` (fila 22), en cambio, se restauró en 1.3 el 2026-08-27 tras corregirse un recorte no autorizado — ver `§C.1`.

---

## C.8 Convenciones transversales: dónde 1.3 se aparta o matiza

### C.8.1 Un `2xx` que no es el resultado final

`POST /auth/session` puede responder `202` sin haber hecho lo que su nombre dice (`§C.2.1`). Es la matización más visible de este paso y está argumentada; lo que no se hace es esconderla: la respuesta lleva un recurso de tipo distinto y documentado, no un `200` ambiguo.

### C.8.2 Sin `Idempotency-Key`, otra vez

Ninguno de los diez. Los dos del desafío tienen su propia protección contra repetición —el desafío se consume— y los de autoservicio son operaciones que un usuario ejecuta desde una pantalla. `GET /mfa-compliance/users` es una lectura, no hay nada que idempotizar. Los de escritura de administración son los únicos discutibles: un doble envío de `POST /mfa-resets` haría dos restablecimientos. **El segundo es inofensivo** (no quedan factores que borrar; sigue respondiendo `204`, con `factors_removed: 0` en la traza) y escribiría una fila de traza de más, que es preferible a una de menos. Mismo criterio que §9.3 y `§B.7.2`.

### C.8.3 Un `DELETE` con cuerpo

`DELETE /auth/mfa-factors/{public_id}` lleva `current_password` en el cuerpo. RFC 9110 permite cuerpo en `DELETE` pero no le da semántica, y algunos intermediarios lo descartan.

Las alternativas eran peores: **la contraseña en la cadena de consulta** acaba en el registro del proxy, en el historial y en la cabecera `Referer` —exactamente lo que §4.7 prohíbe para los tokens, y una contraseña es peor que un token—; **una cabecera propia** es inventar un mecanismo de reautenticación fuera de toda convención; y **`POST /auth/mfa-factor-removals`** convierte una eliminación en una creación para esquivar un detalle de transporte.

Se elige el cuerpo, y se anota como cosa a verificar en el despliegue real detrás de Traefik (`operacion.md §C.9`). Si algún intermediario lo descarta, la salida es el `POST` con nombre de recurso, no la cadena de consulta.

### C.8.4 `null` no es un error

`GET /auth/mfa` con cero factores devuelve `200` con `factors: []`, no `404`. Igual que `§B.7.3`.

### C.8.5 Enumerados

`method` (`totp`, `email`, `sms`) viaja como **código estable en inglés técnico**, nunca traducido (`ADR-038 §7.3`). En 1.3 el servidor solo confirma factores `totp` — `email` y `sms` se validan como valores de enumerado pero cualquier intento de inscripción con ellos responde `422` (§C.1, deferred a `1.3b`) — y el cliente **debe** tener rama por defecto: los otros dos pueden aparecer el día que existan sin que la SPA cambie.

`trigger` de obligación (`MfaObligationTrigger`, cinco valores: `rol_modificado`, `rol_asignado`, `metodo_retirado`, `restablecimiento`, `exencion_vencida`) es **interno**: sostiene `user_mfa_obligations.trigger` para trazabilidad pero **no se expone en ningún endpoint** de 1.3. `exencion_vencida` existe ya en el enumerado porque el modelo de datos es compartido con `1.3b`, pero ningún flujo de este paso lo produce.

`state` de `GET /mfa-compliance/users` (`§C.5`) tiene **cinco valores válidos como filtro** (`obligated`, `enrolled`, `pending`, `past_deadline`, `exempt`) pero solo **cuatro aparecen como valor de una fila**: `obligated` es un alias de conveniencia sobre `pending`+`past_deadline` que el servidor expande antes de consultar — no hay un tercer estado de dominio con ese nombre (`MfaObligationState` solo tiene `EnGracia`/`Exigible` para quien está obligado, más `enrolled`/`exempt` fuera de ese enumerado). Es la única excepción de este módulo a "el filtro y el campo comparten vocabulario", y se anota aquí para que no se lea como una inconsistencia sin explicar.

---

## C.9 Eventos de dominio y webhooks

Cinco eventos nuevos (`funcional.md §C.9.3`): `MfaFactorConfirmed`, `MfaFactorRemoved`, `MfaReset`, `MfaObligationStarted`, `RecoveryCodeUsed`. **Ninguno se expone por API.**

**Webhooks: ninguno**, y aquí con el agravante más fuerte de los tres que lleva este módulo: notificar a un tercero que una persona ha activado, desactivado o usado un factor de autenticación es enviarle el estado de seguridad de una cuenta ajena. Ni siquiera cuando `REQ-API` traiga el mecanismo general debería este módulo publicar por él sin una decisión propia.

---

# Parte D · Paso 1.3b · API (`REQ-AUTH-003`)

> **Estructura**: §1-§11 son 1.2 (cerrado). `§B.1`-`§B.8` son 1.2b (cerrado). `§C.1`-`§C.9` son 1.3 (cerrado y mezclado, commit `cd13e8a`). Esta **Parte D** es el paso **1.3b**, **implementada y cerrada** el 2026-08-31 (PR [#123](https://github.com/pirexia/plataforma-educativa/pull/123), commit `dd68f48`).
>
> Convenciones de `ADR-038` sin excepción, salvo lo que `§D.6` matiza.

---

## D.1 Reglas generales: qué cambia respecto de `§C.1`

| Aspecto | 1.3b |
|---------|------|
| Autenticación | Sin cambios. El paso 2 del login se sigue autorizando con la cookie de la sesión que abrió el desafío (`RN-AUTH-53`) |
| Autorización | **3 endpoints nuevos, los tres con permiso declarado** (`exencion_mfa.crear`, `.leer`, `.eliminar`). El módulo pasa de 4 a **7 permisos** (`permisos.md §D.3`) |
| Aislamiento | Sin cambios. Recurso de otro tenant ⇒ `404`, nunca `403` (`ADR-038 §6.4`) |
| Idempotencia | **Ningún endpoint exige `Idempotency-Key`** (`§D.6.1`) |
| Auditoría | `INV-003`, **sin ampliar el vocabulario** (`funcional.md §D.8`). Todo por el *observer* |
| Módulo desactivado | No aplica: ninguna ruta lleva `module-enabled` (`RN-AUTH-35`, `CA-AUTH-168`) |
| Límite de tasa | **Ninguno nuevo.** Se reutilizan los seis de `operacion.md §C.6`, y se activa el tope de entregas por desafío que 1.3 dejó sin implementar (`RN-AUTH-79`) |
| OpenAPI | Los tres nuevos y los cinco modificados, en `apps/api/openapi/paths/mfa.yaml` |

### D.1.1 Tipos de error nuevos: **ninguno**

`ADR-038 §6.2` declara su catálogo *«cerrado y ampliable solo por ADR o por especificación de módulo»*. 1.2 añadió uno, 1.2b ninguno, 1.3 uno (`mfa-enrollment-required`). **1.3b no añade ninguno**, y conviene decir qué se reutiliza para que no se invente nada en implementación:

| Situación nueva | `type` reutilizado | Estado |
|-----------------|--------------------|--------|
| Código entregado incorrecto, ya usado o **caducado con el desafío vivo** | `urn:pge:error:unauthenticated` | `401` |
| Tope de entregas de un desafío alcanzado | `urn:pge:error:too-many-requests` | `429` con `Retry-After` |
| Método no admitido por el tenant, motivo corto, caducidad ausente o fuera de rango | `urn:pge:error:validation` | `422` |
| El usuario ya tiene una excepción viva | `urn:pge:error:conflict` | `409` |
| Un administrador intenta concederse una excepción a sí mismo | `urn:pge:error:forbidden` | `403` |
| Excepción o usuario inexistente, de otro tenant, o excepción ya revocada | `urn:pge:error:not-found` | `404` |

**El `401` del código caducado es la decisión que hay que mirar dos veces.** El desafío tiene dos caducidades (`expires_at` del desafío, `code_expires_at` del código) y **solo la primera produce `410`**. Un código caducado dentro de un desafío vivo es indistinguible de un código incorrecto —mismo estado, mismo cuerpo— porque el usuario aún puede reenviar y no hay motivo para echarle al login (`RN-AUTH-78`).

---

## D.2 Endpoints de autoservicio modificados

Los cinco de `§C.4` siguen autorizándose **por identidad del portador de la cookie**, sin permiso, y **ninguno acepta un identificador de usuario** (`RN-AUTH-73`). Lo que cambia:

### `POST /api/v1/auth/mfa-enrollments` — **modificado**: `email` deja de responder `422`

`§C.4` decía que el cuerpo *«declara `email`/`sms` como valores de forma válidos… pero el servidor los rechaza con `422` hoy»*. **1.3b levanta ese rechazo para `email`**; `sms` sigue rechazado (`RN-AUTH-69`, sin proveedor).

- **Cuerpo**: `{"method": "email"}`
- **Respuesta `201`**, distinta de la de TOTP a propósito:

```json
{
  "public_id": "01JD7...",
  "method": "email",
  "destination_masked": "a···z@e···e.com",
  "code_expires_at": "2026-08-27T10:40:00Z",
  "expires_at": "2026-08-27T10:40:00Z"
}
```

- **No hay `secret` ni `otpauth_uri`**, y no es una omisión: en un método de entrega **no hay nada que el usuario deba guardar**. Devolver el código haría el segundo factor decorativo (`RN-AUTH-75`).
- **`code_expires_at` y `expires_at` se devuelven las dos** aunque hoy coincidan por configuración (10 y 10 minutos): son dos plazos con dos variables distintas y la pantalla tiene que contar el del código.
- **Errores**: `401`; `422` (`email` no admitido por el tenant, `sms` en cualquier caso); `409` (ya hay un factor `email` confirmado); `429` (`mfa_enrollment_user`, 10/hora).
- **Efecto colateral documentado**: abrir un alta `email` **invalida el alta `email` sin confirmar** que el usuario tuviera viva (`RN-AUTH-76`). El comportamiento de TOTP no cambia.

### `POST /api/v1/auth/mfa-factors` — **modificado**: ramifica por el método del alta

Mismo contrato de entrada y de salida. Lo que cambia es la verificación interna: contra el secreto en TOTP, contra `code_hash` en un método de entrega (`funcional.md §D.4.1`).

- **Errores**: los de `§C.4` sin cambios, con una precisión: **un código correcto pero caducado responde `422`**, igual que uno incorrecto, y consume un intento. `410` sigue reservado al alta inexistente, vencida, ajena o con los intentos agotados.

### `DELETE /api/v1/auth/mfa-factors/{public_id}` — **sin cambios de contrato**

Se documenta aquí porque su comportamiento **se vuelve observable por primera vez**: con dos factores, retirar uno ya no es retirar «el último». El `409` de `RN-AUTH-61` solo aparece si el que se retira es el último utilizable y ningún rol lo permite (comportamiento ya implementado en `MfaFactorRemovalService`, no un cambio).

**`OPEN-AUTH-27` está resuelta (2026-08-27): este endpoint no gana ninguna comprobación nueva.** Se llegó a plantear un `409` adicional —«no puedes retirar tu TOTP mientras tengas el correo activo»— y **se descartó**: «TOTP no desactivable» es una restricción de tenant y solo de tenant (`RN-AUTH-80`, `funcional.md §D.6`). **Un usuario con TOTP y correo puede retirar el TOTP y quedarse solo con el correo.** Su único `409` sigue siendo el de `RN-AUTH-61`.

---

## D.3 Endpoints del desafío modificados

### `POST /api/v1/auth/session` — **modificado**: el `202` puede traer destino

Sin cambios de contrato. `§C.2` ya documentó el cuerpo del `202`; lo que ocurre es que **dos de sus campos dejan de ser constantes**:

```json
{
  "public_id": "01JD7...",
  "method": "email",
  "available_methods": ["totp", "email"],
  "destination_masked": "a···z@e···e.com",
  "expires_at": "2026-08-27T10:35:00Z",
  "has_unused_recovery_codes": true
}
```

- **`destination_masked` aparece solo si el método en curso entrega algo.** En `totp` la clave **no está presente**, no está en `null`: es un campo que no aplica, no un valor vacío.
- **`available_methods` puede traer ahora más de un elemento**, que es lo que `§C.2` anticipaba (*«en 1.3 solo puede contener `totp`»*).
- **Sigue sin haber ningún token, ni `session_id`, ni el código** (`RN-AUTH-84`, `CA-AUTH-116`).

### `POST /api/v1/auth/mfa-challenges` — **modificado**: entrega real y tope

- **Cuerpo**: `{"method": "email"}` — sin cambios de forma.
- **Respuesta `200`**: el mismo recurso del desafío, **ahora sí con `destination_masked`** cuando procede.
- **Semántica nueva**: pedir el método en el que ya se está **es el reenvío**. No hay endpoint distinto para reenviar, y no lo hay a propósito: son la misma operación (generar y entregar un código para este desafío).
- **Errores**: `410` (sin desafío vivo); `422` (método no dado de alta por el usuario, o no admitido por el tenant); **`429` con `Retry-After`** por dos vías distintas —el límite de tasa `mfa_challenge_session` (3/10 min) y el tope `AUTH_MFA_MAX_DELIVERIES` (3 por desafío)—; `403`/`419` (CSRF).
- **Ni el reenvío ni el cambio prolongan `expires_at` ni reinician `attempts`** (`RN-AUTH-54`, `RN-AUTH-79`).

### `POST /api/v1/auth/mfa-verifications` — **modificado**: acepta el código entregado

Mismo cuerpo (`{"code": "…"}` o `{"recovery_code": "…"}`) y mismas respuestas. El `code` se interpreta según el método **en curso del desafío**, no según lo que el cliente diga: no hay campo `method` en el cuerpo y no lo habrá.

**Errores**: sin cambios. El `401` cubre ahora también «código entregado caducado» (`§D.1.1`).

### D.3.1 `GET /api/v1/auth/mfa` — **modificado**: tres añadidos aditivos

Motivado por `funcional.md §D.2.4`: la pantalla no puede ofrecer el correo sin saber si el tenant lo admite, y ese dato solo salía hasta ahora por `GET /tenant/settings`, que exige `configuracion.leer` — un permiso que una familia o un estudiante no tienen.

```json
{
  "allowed_methods": ["totp", "email"],
  "factors": [
    { "public_id": "01JD7...", "method": "totp", "is_preferred": false,
      "confirmed_at": "2026-03-01T09:12:00Z", "last_used_at": "2026-08-26T07:41:00Z" },
    { "public_id": "01JD8...", "method": "email", "is_preferred": false,
      "destination_masked": "a···z@e···e.com",
      "confirmed_at": "2026-08-27T10:33:00Z", "last_used_at": null }
  ],
  "unused_recovery_codes_count": 7,
  "mfa": {
    "enrolled": true,
    "obligated": true,
    "enforced": false,
    "grace_deadline_at": null,
    "days_remaining": null,
    "exempt_until": null
  }
}
```

1. **`allowed_methods`**: lo que el tenant admite hoy (`mfa_allowed_methods`). **No es un permiso relajado**: es información sobre la configuración del centro que el titular necesita para gestionar su propia seguridad, y no dice nada de nadie más.
2. **`destination_masked`** en los factores de entrega, ausente en `totp`.
3. **`mfa.exempt_until`**: la caducidad de la excepción propia, o `null`. Es lo que permite avisar *«no se te exige MFA hasta el 30 de septiembre»* **sin enviar un solo correo** (`funcional.md §D.4.10`), con el mismo criterio con el que `§C.4.8` resolvió los avisos de gracia con el recurso que la SPA ya pide.

**`exempt_until` solo aparece aquí, no en `GET /me`.** El bloque `mfa` del presentador compartido (`UserProfilePresenter`) **no se toca**: lo consumen dos módulos y ampliarlo obliga a revisar los dos. `/cuenta/seguridad` ya pide este endpoint.

**Los tres son ampliaciones aditivas** (`ADR-038 §7.3`): un cliente escrito contra 1.3 ignora las claves nuevas.

---

## D.4 Excepciones temporales nominales: los tres endpoints

Implementan `§C.4.11`, que 1.3 dejó escrito y explícitamente sin entregar (`§C.1`, nota de partición). Los tres declaran permiso, los tres responden `404` con **cuerpo idéntico** ante un recurso de otro tenant (`ADR-038 §6.4`) y `403` sin permiso.

**El recurso**, común a los tres:

```json
{
  "public_id": "01JD9...",
  "user": {
    "public_id": "01J…",
    "given_name": "Marta",
    "family_name_1": "Ruiz",
    "family_name_2": "Soto",
    "email": "marta.ruiz@example.com"
  },
  "reason": "Sin teléfono compatible hasta la renovación de equipos de octubre",
  "expires_at": "2026-10-15T00:00:00Z",
  "state": "live",
  "granted_by": { "public_id": "01J…", "given_name": "Luis", "family_name_1": "Ortiz" },
  "granted_at": "2026-08-27T11:02:00Z",
  "revoked_by": null,
  "revoked_at": null
}
```

- **`state`** es derivado, no una columna: `live` (`revoked_at IS NULL` y `expires_at > ahora`), `expired` (`revoked_at IS NULL` y `expires_at <= ahora`), `revoked` (`revoked_at` informado). Es la misma técnica que `MfaObligationState` en `GET /mfa-compliance/users` (`§C.8.5`), y aquí **sí** comparten vocabulario el filtro y el campo: los tres valores del filtro son los tres valores posibles de una fila.
- **`user` y `granted_by` llevan solo campos públicos.** Nunca secretos, ni recuento de códigos de respaldo, ni estado de factores.
- **`granted_at` es `created_at`**, renombrado en el recurso porque «cuándo se creó la fila» y «cuándo se concedió» son lo mismo y el segundo nombre es el que entiende quien lee la pantalla.

### `POST /api/v1/mfa-exemptions`

Concede una excepción temporal nominal.

- **Permiso**: `exencion_mfa.crear`
- **Cabeceras**: `X-XSRF-TOKEN` obligatoria (`RN-AUTH-29`)
- **Cuerpo**:

```json
{
  "user": "01J…",
  "reason": "Sin teléfono compatible hasta la renovación de equipos de octubre",
  "expires_at": "2026-10-15T00:00:00Z"
}
```

- **Respuesta `201`** con el recurso completo.
- **Errores**

| Estado | `type` | Cuándo |
|--------|--------|--------|
| `422` | `urn:pge:error:validation` | `reason` ausente o de menos de 10 caracteres; `expires_at` ausente, en el pasado, mal formada, o **a más de `AUTH_MFA_MAX_EXEMPTION_DAYS` (90)** (`RN-AUTH-81`) |
| `403` | `urn:pge:error:forbidden` | **El solicitante es el propio sujeto** (`RN-AUTH-81`). Distinto de no tener permiso, y se distingue en el mensaje — igual que en `POST /mfa-resets` (`§C.5`) |
| `409` | `urn:pge:error:conflict` | El usuario **ya tiene una excepción viva**. Comprobación explícita, no violación del índice |
| `404` | `urn:pge:error:not-found` | Usuario inexistente o de otro tenant |

**Efecto** (`funcional.md §D.4.6`): crea la fila con `granted_by`, **cierra la obligación abierta del usuario** si la hay, y a partir de ese instante `MfaPolicy::resolve()` devuelve `NoObligado` para él — es decir, **si estaba contra el muro, deja de estarlo en su siguiente petición**.

**`expires_at` viaja como `TIMESTAMPTZ` ISO-8601 y se interpreta tal cual, sin truncar al día.** Una excepción «hasta el 15 de octubre» concedida sin hora caduca a las `00:00` de ese día en el huso del centro, no a las 23:59: es lo que dice el valor que se envía, y la pantalla debe mostrar la fecha y la hora efectivas para que no haya sorpresa.

### `GET /api/v1/mfa-exemptions`

Lista las excepciones del centro.

- **Permiso**: `exencion_mfa.leer`
- **Filtros**: `state` (uno o varios separados por coma, `ADR-038 §5.2`, de entre `live`, `expired`, `revoked`; sin filtro, todas) y `user={public_id}`
- **Paginación**: por página, como el resto de listados de administración (`ADR-038 §4.3`, mismo patrón que `GET /account-lockouts` y `GET /mfa-compliance/users`)
- **Orden**: las vivas primero, y dentro de cada grupo por `granted_at` descendente. Quien abre esta pantalla quiere ver **qué está exento ahora**, no el histórico
- **Respuesta `200`**: colección `{"data": [...], "meta": {...}}` con el recurso de arriba
- **Errores**: `401`, `403`, `422` (valor de `state` desconocido)

**Se solapa a propósito con `GET /mfa-compliance/users?state=exempt`**, y hay que decir en qué se diferencian para que nadie los funda en implementación: aquel responde *«quién está exento»* dentro del cumplimiento de un rol; este responde *«qué excepciones hay, quién las concedió, por qué y hasta cuándo»*. **Solo este trae `reason` y `granted_by`**, que es lo que convierte el mecanismo en auditable desde la interfaz y no solo desde `audit_logs`.

### `DELETE /api/v1/mfa-exemptions/{public_id}`

Revoca una excepción antes de su caducidad.

- **Permiso**: `exencion_mfa.eliminar`
- **Cabeceras**: `X-XSRF-TOKEN` obligatoria
- **Cuerpo**: **ninguno.** A diferencia de `DELETE /auth/mfa-factors/{public_id}` (`§C.8.3`), aquí no hay reautenticación que pedir: el actor no está tocando sus propias credenciales, está retirando un permiso que él o un compañero concedió, y ya tiene el permiso que lo autoriza
- **Respuesta `204`**
- **Errores**

| Estado | `type` | Cuándo |
|--------|--------|--------|
| `404` | `urn:pge:error:not-found` | Excepción inexistente, de otro tenant o **ya revocada**. Cuerpo idéntico en los tres (`RN-AUTH-83`) |
| `401` / `403` | | Sin sesión / sin permiso |

**Efecto** (`funcional.md §D.4.8`): escribe `revoked_at`/`revoked_by`, **conserva la fila** (no hay borrado lógico, y por tanto la auditoría registra un `updated`) y **reabre la obligación con plazo de gracia completo** si el usuario sigue reuniendo las condiciones.

**Un administrador sí puede revocar la suya**, a diferencia de concederla (`funcional.md §D.4.8`).

**Una excepción caducada no se revoca, se deja estar.** No hay operación sobre ella: ya no protege nada y su fila es traza.

---

## D.5 Superficie del módulo tras 1.3b

| # | Método y ruta | Autorización | Paso |
|---|---------------|--------------|------|
| 1-13 | Los diez de §7 y los tres de `§B.5` | | 1.2 / 1.2b |
| 14-23 | Los diez de `§C.7` | | 1.3 |
| **24** | `POST /mfa-exemptions` | `exencion_mfa.crear` | **1.3b** |
| **25** | `GET /mfa-exemptions` | `exencion_mfa.leer` | **1.3b** |
| **26** | `DELETE /mfa-exemptions/{public_id}` | `exencion_mfa.eliminar` | **1.3b** |

**Tres endpoints nuevos, ninguno anónimo.** La superficie del módulo pasa de 23 a **26**, y **la superficie anónima no crece**: sigue en 8 (`§C.7`). Es la diferencia importante con 1.3, que añadió dos endpoints alcanzables sin autenticar.

**Modificados sin romper contrato**: `POST /auth/session` (el `202` puede traer `destination_masked` y más de un método), `POST /auth/mfa-enrollments` (acepta `email`), `POST /auth/mfa-factors` (ramifica por método), `POST /auth/mfa-challenges` (entrega real y tope), `POST /auth/mfa-verifications` (acepta el código entregado) y `GET /auth/mfa` (tres campos aditivos).

**Ningún endpoint retirado, ninguna ruta cambiada, ningún campo eliminado.** Un cliente escrito contra 1.3 sigue funcionando sin tocar una línea.

### D.5.1 La pantalla de administración no aporta superficie de API

`OPEN-AUTH-28` se resolvió el 2026-08-27 por incluir en 1.3b una pantalla mínima de administración (`funcional.md §D.1.3`). **No añade ni un endpoint a esta tabla**, y es una condición de su alcance, no una casualidad: las cuatro capacidades que ofrece se cubren con los siete endpoints de administración que ya existen tras la pieza 2.

| Capacidad de la pantalla | Endpoint que consume | Paso en que se entregó |
|--------------------------|----------------------|------------------------|
| Cumplimiento agregado por rol | `GET /mfa-compliance` | 1.3 |
| Listado individualizado de usuarios | `GET /mfa-compliance/users` | 1.3 |
| Vista previa del impacto de `mfa_required` | `GET /mfa-compliance?role=…&mfa_required=…` | 1.3 |
| Conmutar `mfa_required` de un rol | `PATCH /roles/{public_id}` (en `REQ-CORE`) | 1.3 |
| Restablecer el MFA de un usuario | `POST /mfa-resets` | 1.3 |
| Conceder, listar y revocar excepciones | Los tres de `/mfa-exemptions` | **1.3b** |

**Si al construir la pantalla aparece la necesidad de un endpoint nuevo, hay que parar y preguntar**: significa que se está adelantando `1.5` o que la pantalla se está saliendo de lo acotado. No se resuelve añadiendo API «pequeña» sobre la marcha (`CLAUDE.md §3`).

---

## D.6 Convenciones transversales: dónde 1.3b se aparta o matiza

### D.6.1 Sin `Idempotency-Key`, otra vez

Los tres nuevos: `GET` es una lectura; `DELETE` es idempotente por naturaleza (la segunda llamada responde `404`, que es un estado final estable); y `POST /mfa-exemptions` **está protegido por el `409`**, que es una idempotencia mejor que una cabecera: un doble envío no crea dos excepciones porque no puede haber dos vivas. Mismo criterio que `§C.8.2` y §9.3.

### D.6.2 Un campo ausente no es un campo nulo

`destination_masked` **no aparece** en el recurso cuando el método no entrega nada, en vez de aparecer con `null`. Es la única convención nueva de este paso y va contra la costumbre de `§C.8.4` («`null` no es un error»), así que se argumenta: `null` significaría «hay un destino y no lo sé»; la ausencia significa «este método no tiene destino». Un cliente tipado distingue las dos cosas y la segunda es la verdad.

### D.6.3 Enumerados

- `method` (`totp`, `email`, `sms`) sigue viajando como código estable en inglés técnico (`ADR-038 §7.3`). **A partir de 1.3b, `email` es un valor que el cliente verá de verdad**; `sms` sigue sin poder existir.
- `state` de `GET /mfa-exemptions` (`live`, `expired`, `revoked`) es derivado y **comparte vocabulario entre filtro y campo**, a diferencia de `state` en `GET /mfa-compliance/users` (`§C.8.5`).
- `trigger` de obligación sigue siendo **interno** y sin exponerse. `exencion_vencida`, que `§C.8.5` describía como *«existe ya en el enumerado porque el modelo de datos es compartido con 1.3b, pero ningún flujo de este paso lo produce»*, **pasa a producirse** en 1.3b.

### D.6.4 Dos caducidades en el mismo recurso

El desafío expone `expires_at` (suya) y el alta expone `expires_at` y `code_expires_at`. **No se unifican**, aunque hoy coincidan por configuración: son dos plazos con dos variables de entorno y dos consecuencias distintas (`410` frente a `401`, `§D.1.1`). La pantalla cuenta el del código; el servidor comprueba los dos.

---

## D.7 Eventos de dominio y webhooks

**Ningún evento nuevo** (`funcional.md §D.7.3`). Los cinco de `§C.9` cubren lo que este paso produce.

**Webhooks: ninguno**, con el mismo agravante de `§C.9` y uno más: publicar hacia un tercero que una persona está **exenta** de segundo factor es publicar exactamente qué cuenta atacar. Ni cuando `REQ-API` traiga el mecanismo general.

---

# Parte E · Paso 1.4 · API (`REQ-AUTH-002`)

> **Estructura**: §1-§11 son 1.2, `§B.*` es 1.2b, `§C.*` es 1.3 y `§D.*` es 1.3b, los cuatro cerrados. Esta **Parte E** es el paso **1.4**, **implementada** (2026-09-01, rama `feature/REQ-AUTH-002-google-login-fusion-cuentas`, PR [#143](https://github.com/pirexia/plataforma-educativa/pull/143)): describe la API tal como existe, en revisión independiente antes de mezclar.
>
> Convenciones de `ADR-038` sin excepción, salvo lo que `§E.7` matiza — y este paso tiene **una excepción de verdad**, la primera del módulo: el *callback* no habla `problem+json`.
>
> Escrita sobre la **opción A** de `funcional.md §E.3` (una URI de redirección por tenant), **decidida por el usuario el 2026-08-31**: el *callback* aterriza en el host del propio centro y entra por la cadena de *middleware* de `/api/v1` sin excepciones. La dependencia del cliente OAuth y su envoltorio los fija `ADR-042`.

---

## E.1 Reglas generales: qué cambia respecto de §1, `§B.1`, `§C.1` y `§D.1`

| Aspecto | 1.4 |
|---------|-----|
| Autenticación | Sin cambios (`ADR-025`). El *callback* se autoriza con **la misma cookie de sesión anónima que arrancó el flujo**, contra el `state` guardado en su *payload* — el mismo mecanismo que el desafío de MFA (`RN-AUTH-53`, `permisos.md §C.4`), no uno nuevo |
| Autorización | De los **6 endpoints nuevos**: **2 anónimos**, **2 autorizados por posesión de la sesión** (el *callback*, por el `state`; `GET /auth/mfa-challenges`, por el desafío abierto), **2 por identidad del portador**. **Ninguno declara permiso** (`permisos.md §E.1`) |
| Aislamiento | Sin cambios. Recurso de otro tenant ⇒ `404`, nunca `403` (`ADR-038 §6.4`) |
| Idempotencia | **Ningún endpoint exige `Idempotency-Key`** (`§E.7.2`) |
| Auditoría | `INV-003`, **sin ampliar el vocabulario** (`funcional.md §E.8`). Todo por el *observer* de 0.9, más el `login` que ya existía |
| Módulo desactivado | No aplica: ninguna ruta lleva `module-enabled` (`RN-AUTH-35`, `CA-AUTH-231`) |
| Proveedor no configurado (`AUTH_OAUTH_DRIVER=none`, **por defecto**) | **Estado normal, no degradado.** `GET /auth/identity-providers` responde `200` con `data: []`; `POST /auth/oauth-authorizations` responde `422`; el *callback* responde `302 estado_no_valido`; **y los dos de `/auth/identities` funcionan con normalidad**, porque gestionar vínculos ya existentes no necesita proveedor (`operacion.md §E.1`) |
| Límite de tasa | **Cuatro *buckets* propios**: los dos anónimos y el *callback* por IP, y `GET /auth/mfa-challenges` por sesión. **Los dos de `/auth/identities` no llevan *bucket* propio** —exigen sesión, y el `429` que puedan dar es el del limitador global—, mismo criterio que los tres de 1.2b (`§B.1`) y que `DELETE /auth/mfa-factors` de 1.3. `operacion.md §E.6` |
| OpenAPI | Los 5 en `apps/api/openapi/paths/oauth.yaml` antes del *merge* (`CLAUDE.md §10`) |

### E.1.1 Tipos de error nuevos: **ninguno**

`ADR-038 §6.2` declara su catálogo *«cerrado y ampliable solo por ADR o por especificación de módulo»*. 1.2 añadió `account-locked`, 1.2b ninguno, 1.3 añadió `mfa-enrollment-required`, 1.3b ninguno. **1.4 tampoco añade ninguno**, y merece decirse por qué, porque la tentación existía:

| Situación | Qué se reutiliza |
|-----------|------------------|
| Vínculo ya existente, propio o de otro usuario | `urn:pge:error:conflict` (**409**) — mismo criterio que la desactivación de un factor exigido (`§C.1.1`) |
| Desvincular dejaría al usuario sin forma de entrar | `urn:pge:error:conflict` (**409**) |
| Contraseña actual incorrecta al desvincular | `urn:pge:error:validation` (**422**) — mismo criterio que `POST /auth/password-changes` (§5b) |
| Proveedor no configurado, o `provider` desconocido | `urn:pge:error:validation` (**422**) |
| Vínculo de otro tenant, o inexistente | `urn:pge:error:not-found` (**404**), cuerpo idéntico |
| Sesión restringida por el muro de MFA | `urn:pge:error:mfa-enrollment-required` (**403**), el de 1.3, sin cambios |

**Y no hay ningún tipo de error para los fallos del flujo OAuth** —`state` inválido, código caducado, proveedor que no responde, persona que cancela— porque **el *callback* no responde con errores HTTP**: responde `302` con un código de resultado. `§E.4` lo argumenta.

---

## E.2 `GET /api/v1/auth/identity-providers`

Qué proveedores externos admite este host. Lo pide la pantalla de login antes de decidir si pinta el botón (`RN-AUTH-98`).

- **Permiso**: ninguno. **Anónimo**, tenant resuelto por host.
- **Cabeceras**: ninguna especial.
- **Respuesta `200`** (colección, `ADR-038 §3.1`):

```json
{
  "data": [
    { "provider": "google" }
  ],
  "meta": { "total": 1 }
}
```

- **`data: []`** cuando **`AUTH_OAUTH_DRIVER=none`**, que es **el valor por defecto** (`operacion.md §E.2.1`). **No es un error ni un estado degradado**: es el despliegue que no quiere Google, y el que tiene cualquiera recién desplegado hasta que alguien configure el proveedor a propósito (`funcional.md §E.10`).
- **Es el único endpoint de este paso que responde `200` con `driver = none`**, y por eso existe: es el que permite a la pantalla de login decidir sin adivinar (`RN-AUTH-98`). Si respondiera `422` como los del flujo, la SPA no tendría forma de distinguir «no hay proveedor» de «algo va mal» y acabaría pintando el botón por si acaso.
- **Solo `provider`, sin `label_key`.** La primera redacción de esta sección documentaba `label_key` para que la SPA resolviera el texto del botón con un catálogo de traducciones por proveedor — pensado con la vista puesta en el catálogo multiproveedor de `1.4b`. **Retirado en el cierre de 1.4** (hallazgo de `doc-reviewer`): con un solo proveedor (`ADR-042 §4.3`, interfaz de un solo proveedor a propósito), el texto del botón no depende del proveedor sino del `intent` («Continuar con Google» / «Vincular con Google»), y ese texto ya lo resuelve la SPA con su propio catálogo de 4 idiomas (`INV-009`) sin necesitar ninguna clave que el servidor le mande. Era superficie documentada sin ningún consumidor real; cuando `1.4b` traiga varios proveedores, se reintroduce lo que haga falta entonces, con el requisito delante.
- **No lleva `client_id`, ni la URL de autorización, ni nada del proveedor.** Construir la URL es trabajo del servidor (`§E.3`), y publicarla aquí daría un punto de partida del flujo que se salta el CSRF y el límite de tasa.
- **Errores**: `404` (host sin tenant), `429` (*bucket* `identity_providers_ip`, 60/min — `operacion.md §E.6`), `503` (tenant suspendido).
- **Idempotencia**: no procede (`GET`).

---

## E.3 `POST /api/v1/auth/oauth-authorizations`

Arranca el flujo. Devuelve la URL a la que la SPA debe navegar.

- **Permiso**: ninguno. **Anónimo** con `intent = "login"`; **por identidad del portador** con `intent = "link"`, que exige sesión completa.
- **Cabeceras**: `X-XSRF-TOKEN` obligatoria (`RN-AUTH-29`).
- **Cuerpo**:

```json
{ "provider": "google", "intent": "login" }
```

`intent` ∈ `login` | `link`. Cualquier otro valor ⇒ `422`.

- **Respuesta `201`**:

```json
{
  "authorization_url": "https://accounts.google.com/o/oauth2/v2/auth?...",
  "expires_at": "2026-09-01T10:10:00Z"
}
```

- **`201` y no `204`**: hay un recurso que devolver, y sin él el cliente no puede continuar.
- **Sin `public_id`, a propósito.** La única credencial del flujo es la cookie de sesión; dar un identificador invitaría a aceptarlo como forma de continuar desde otro cliente. Mismo argumento que `RN-AUTH-53` y `permisos.md §D.4`.
- **La URL lleva `state`, `code_challenge` y `code_challenge_method=S256`** (`RN-AUTH-91`), y la `redirect_uri` construida con el slug del tenant y el dominio base configurado, **nunca con `$request->getHost()`** (`RN-AUTH-92`, `CA-AUTH-203`).
- **Efecto colateral obligatorio**: el `state`, el verificador PKCE, el `intent` y el proveedor quedan en el *payload* de la sesión del servidor, con caducidad. **No se emite ninguna cookie propia** para esto.

**Errores**:

| Estado | Cuándo |
|--------|--------|
| `422` | **`AUTH_OAUTH_DRIVER=none`** (no hay proveedor externo, y es el estado por defecto); `provider` desconocido; `intent` inválido; `intent = "link"` sin sesión |
| `403` | `urn:pge:error:mfa-enrollment-required` — sesión restringida por el muro de 1.3 y `intent = "link"` (`funcional.md §E.4.4` punto 5) |
| `419`/`403` | CSRF ausente o inválido |
| `429` | Límite de tasa por IP, con `Retry-After` |
| `404` / `503` | Host sin tenant / tenant suspendido |

- **Idempotencia**: no exige `Idempotency-Key`. Repetirlo genera un `state` nuevo que **sustituye** al anterior, que deja de valer en el acto — mismo criterio que `RN-AUTH-11` con los tokens de restablecimiento.

---

## E.4 `GET /api/v1/auth/oauth/google/callback`

**Es el único endpoint del producto que no habla el lenguaje de `ADR-038`, y es a propósito.**

- **Permiso**: ninguno declarado. Se autoriza por **posesión de la sesión que arrancó el flujo**, comparando el `state` del parámetro con el del *payload* en tiempo constante (`RN-AUTH-91`). Es el cuarto mecanismo de `permisos.md §C.4`, sin ampliación.
- **Cabeceras**: ninguna. **Sin CSRF**, porque es un `GET` que llega como navegación desde un tercero; la defensa contra la falsificación de esta petición **es el `state`**, que es exactamente para lo que existe en OAuth2.
- **Parámetros de consulta**: `code` y `state`, o `error` y `state`. Los pone Google, no nosotros.
- **Respuesta**: **siempre `302`**, a `https://{slug}.{base}/entrar/google?resultado=<código>` o, cuando el login se completa, a la ruta de destino de la SPA.

### E.4.1 Por qué `302` y no `problem+json`

| Alternativa | Por qué no |
|-------------|------------|
| **`problem+json` como el resto del módulo** | Quien llega aquí es **un navegador haciendo una navegación de primer nivel**, no el cliente HTTP de la SPA. Un `422` con `application/problem+json` se le pinta al usuario como un volcado JSON en pantalla blanca. No hay ningún cliente programático de este endpoint: su único llamante es Google devolviendo a una persona |
| **`200` con HTML propio** | Obligaría al *backend* a renderizar una pantalla, con su plantilla, su branding y sus cuatro idiomas — duplicando lo que la SPA ya sabe hacer y creando la primera vista servidor del producto |
| **`302` con el detalle del error en la URL** | Es lo que se hace, **pero con una lista cerrada de códigos**, no con texto ni con datos. Un mensaje en la URL acabaría llevando el correo o el motivo, y `§4.7` lo prohíbe |

**El código de resultado es un enumerado cerrado**, y eso es lo que permite traducirlo a cuatro idiomas sin literales en el código (`INV-009`) y lo que impide que un día alguien meta un dato personal en él (`RN-AUTH-93`).

### E.4.2 Códigos de resultado

| Código | Cuándo | Qué ofrece la pantalla |
|--------|--------|------------------------|
| *(ninguno: redirección al destino)* | Login completado | — |
| `segundo_factor` | Se abrió desafío de MFA (`funcional.md §E.4.2` paso 8.3) | Redirige a la pantalla de segundo factor, que ya existe desde 1.3 |
| `alta_mfa_requerida` | Sesión restringida: obligado, sin factor y gracia vencida | Redirige al muro de `§C.4.9`, que ya existe |
| `vinculado` | `intent = link` completado | Vuelve a `/cuenta/seguridad` con el aviso de éxito |
| `sin_cuenta` | **No hay vínculo y, o el correo no venía verificado, o no hay cuenta local.** Un solo código para los dos casos (`datos.md §E.3.2`) | «Si tienes cuenta en este centro, entra con tu contraseña y vincula Google desde tu perfil» — en condicional, sin afirmar nada |
| `cuenta_bloqueada` | Bloqueo vivo para ese correo (`§E.6` de `funcional.md`) | Explica el bloqueo y ofrece el desbloqueo, igual que la pantalla de 1.2 |
| `acceso_denegado` | Usuario `pendiente`, `inactivo` o borrado | Mensaje **genérico**, el mismo que el `401` de `§4.7` |
| `ya_vinculado` | `intent = link` y el usuario ya tenía un vínculo vivo | Explica que hay que desvincular primero |
| `proveedor_ya_vinculado` | Esa cuenta de Google ya está vinculada a otro usuario del centro | Mensaje genérico: **no dice a quién** |
| `cancelado` | La persona canceló en Google (`error=access_denied`) | Vuelve al login, sin dramatismo |
| `estado_no_valido` | `state` ausente, distinto, caducado o ya consumido. **También cubre `AUTH_OAUTH_DRIVER=none` sin rama propia**: con `none` nadie ha podido arrancar el flujo, así que no hay `state` que comparar, y la comprobación del paso 3 —que precede a toda llamada al proveedor— lo resuelve sin que el *callback* tenga que saber qué *driver* hay | «Vuelve a intentarlo» |
| `error_proveedor` | Fallo al canjear el código, o Google no responde | «Inténtalo más tarde o entra con tu contraseña» |

**Ningún código lleva sufijo con el detalle.** `sin_cuenta` no se desdobla, `acceso_denegado` no dice qué estado tiene la cuenta, y `proveedor_ya_vinculado` no nombra al otro usuario. Es la misma disciplina de `§4.7`, aplicada a un canal distinto.

**Efectos**: en el camino de éxito, el *callback* **crea sesión** (`funcional.md §E.4.2` paso 9) y, según el caso, **crea la fila de `user_identities`**. Es, por tanto, un `GET` que escribe — el mismo apartamiento que `§B.7.1` documentó para `GET /auth/sessions`, y aquí es inevitable: la forma de la petición la fija OAuth2, no nosotros.

---

## E.5 Autoservicio del propio usuario

### `GET /api/v1/auth/identities`

Mis cuentas externas vinculadas. Lo pinta el bloque nuevo de `/cuenta/seguridad`.

- **Permiso**: ninguno. **Por identidad del portador de la cookie** (`RN-AUTH-73`: no acepta ningún sujeto en el cuerpo ni en la consulta).
- **Respuesta `200`**:

```json
{
  "data": [
    {
      "public_id": "01JD7...",
      "provider": "google",
      "email_at_link": "n***@gmail.com",
      "link_method": "fusion_automatica",
      "linked_at": "2026-09-01T10:05:00Z",
      "last_login_at": "2026-09-14T08:12:00Z"
    }
  ],
  "meta": { "total": 1 }
}
```

- **`email_at_link` sale enmascarado**, con el mismo `DestinationMasker` que 1.3b introdujo para el destino del código por correo (`§D.4.5`). El titular no necesita ver la dirección entera para reconocer cuál es, y una sesión secuestrada tampoco debe llevarse el correo personal de nadie.
- **`link_method` sí sale**, y es lo que permite al titular distinguir «lo vinculé yo» de «el sistema lo vinculó porque los correos coincidían». Es la mitad visible de `RN-AUTH-97`.
- **Sin paginación**: como mucho hay un vínculo por proveedor, y hoy un proveedor. Se devuelve `meta.total` por coherencia de la envoltura, no porque haya páginas.
- **Errores**: `401` sin sesión; `429` **del limitador global, no de un *bucket* propio** (`operacion.md §E.6`).

### `DELETE /api/v1/auth/identities/{public_id}`

Desvincular.

- **Permiso**: ninguno. Por identidad del portador. El vínculo se busca **por `public_id` más predicado de tenant más `user_id` del portador, en el mismo `WHERE`** — nunca un `find()` seguido de una comprobación en PHP (`permisos.md §E.4`, misma regla que `RN-AUTH-41`).
- **Cabeceras**: `X-XSRF-TOKEN` obligatoria.
- **Cuerpo**:

```json
{ "current_password": "..." }
```

- **Un `DELETE` con cuerpo**, otra vez. Ya ocurre desde 1.3 con `DELETE /auth/mfa-factors/{public_id}` y por el mismo motivo (`§C.8.3`): la contraseña actual no puede viajar en la URL.
- **Respuesta `204`**, sin cuerpo.

**Errores**:

| Estado | Cuándo |
|--------|--------|
| `422` | `current_password` ausente o incorrecta. **No `401`**: la sesión sigue siendo válida (`funcional.md §E.4.5` punto 2). **Cuenta hacia el bloqueo** y escribe en `login_attempts` |
| `409` | `urn:pge:error:conflict` — desvincular dejaría al usuario sin ninguna forma de entrar (`RN-AUTH-96`) |
| `404` | Vínculo inexistente, ya desvinculado, de otro usuario o de otro tenant. **Cuerpo idéntico en los cuatro casos** |
| `401` | Sin sesión |
| `419`/`403` | CSRF |
| `429` | Limitador global, **sin *bucket* propio** (`operacion.md §E.6`). Contra la fuerza bruta de contraseña aquí defiende el **bloqueo de cuenta**, no el límite de tasa: los fallos de `current_password` incrementan el contador de `RN-AUTH-14` |

---

## E.5b `GET /api/v1/auth/mfa-challenges` — el desafío en curso

Devuelve el desafío abierto para esta sesión. Lo necesita `/entrar/google`: el *callback* responde `302` con un código de resultado y **sin datos** (`RN-AUTH-93`), así que tras el redirect la SPA no tiene de dónde leer el método en curso, los alternativos, el destino enmascarado ni la caducidad — datos que en el login local viajan siempre en el cuerpo del `202` de `POST /auth/session`.

> **Por qué está numerado `5b` y por qué vive en la Parte E.** Dos criterios, los dos ya establecidos en este documento y aplicados aquí sin inventar nada:
>
> 1. **Cada Parte documenta lo que su paso entrega, aunque el recurso sea de otro paso o de otro módulo.** El precedente exacto es `§C.6`: 1.3 entregó `PATCH /roles/{public_id}`, un recurso de **`REQ-CORE`**, y lo documentó en la Parte C, no editando la documentación de `REQ-CORE`. Aquí pasa lo mismo un grado más cerca: `mfa_challenges` es un recurso de `REQ-AUTH-003` (Parte C) y el *endpoint* lo entrega 1.4. Reescribir la Parte C sería tocar **el registro de un paso cerrado y mezclado**, que la cabecera de este documento prohíbe explícitamente.
> 2. **La numeración `5b` evita desplazar las referencias cruzadas ya escritas** a `§E.6`-`§E.8` desde este y otros documentos del módulo. Mismo criterio, y mismo motivo, que `§5b` y que `1.2b`/`1.4b` en `PLAN-IMPLEMENTACION.md`.
>
> Queda **nota cruzada en `§C.3`**: quien lea la Parte C buscando la superficie de `mfa_challenges` tiene que encontrar el `GET` desde allí, o la partición por pasos se convierte en documentación escondida.

- **Permiso**: ninguno. **Autorizado por la posesión de la sesión que abrió el desafío**, exactamente el mismo mecanismo que `POST /auth/mfa-verifications` y `POST /auth/mfa-challenges` (`RN-AUTH-53`, `permisos.md §C.4`). **No es un mecanismo nuevo**: es el cuarto de `permisos.md §E.2`, sin ampliación.
- **Cabeceras**: ninguna. **Sin CSRF**, por ser `GET`.
- **Respuesta `200`**: **exactamente el mismo recurso que el `202` de `POST /auth/session`** (`§C.2`, con el `destination_masked` que `§D.3` añadió). No se inventa contrato:

```json
{
  "public_id": "01JD7...",
  "method": "email",
  "available_methods": ["totp", "email"],
  "destination_masked": "a···z@e···e.com",
  "expires_at": "2026-09-01T10:35:00Z",
  "has_unused_recovery_codes": true
}
```

- **`destination_masked` sigue apareciendo solo si el método en curso entrega algo** (`§D.3`): en `totp` la clave **no está presente**, no está en `null`.
- **Sigue sin haber ningún token, ni `session_id`, ni el código entregado** (`RN-AUTH-84`, `RN-AUTH-93`). Este *endpoint* **no amplía en un solo campo** lo que el titular de esa sesión ya podía ver.
- **`Cache-Control: no-store`** obligatorio. Es un `GET` que devuelve estado de autenticación con un destino enmascarado dentro; ningún intermediario ni el propio navegador deben conservarlo.
- **No entrega nada ni consume nada**: no genera código, no encola correo, no toca `attempts`, no toca `deliveries` y **no prolonga `expires_at`**. Es estrictamente de lectura, y es lo que lo distingue del `POST` del mismo recurso, que **sí** entrega (`§D.3`).

**Errores**:

| Estado | Cuándo |
|--------|--------|
| `410` | `urn:pge:error:gone` — **no hay desafío vivo para esta sesión**: inexistente, caducado, consumido, con los intentos agotados o **de otra sesión**. **Cuerpo idéntico en los cinco casos** (`RN-AUTH-53`, mismo criterio que `§C.3`) |
| `429` | *Bucket* propio (`operacion.md §E.6`), con `Retry-After` |
| `404` / `503` | Host sin tenant / tenant suspendido |

**No responde `401`**, y merece decirse: entre el paso 1 y el paso 2 **no hay identidad** (`RN-AUTH-52`), así que «no autenticado» sería una respuesta que describe mal la situación. La ausencia de desafío es `410`, igual que en el resto del flujo.

**Sirve también al login local, y aun así no cambia su contrato.** Un usuario que recarga `/entrar` a mitad del segundo paso hoy pierde los datos del desafío; con este *endpoint* la pantalla puede recuperarlos. **Pero el `202` de `POST /auth/session` sigue trayendo el cuerpo completo** (`§C.2`, `§D.3`): no se retira nada, no se obliga a la SPA a encadenar una segunda petición, y el camino local no se toca en 1.4.

---

## E.6 Superficie del módulo tras 1.4

| Paso | Endpoints | Acumulado |
|------|-----------|-----------|
| 1.2 | 10 | 10 |
| 1.2b | 3 | 13 |
| 1.3 | 10 (+1 en `REQ-CORE`) | 23 |
| 1.3b | 3 | 26 |
| **1.4** | **6** | **32** |

Los seis:

| Método y ruta | Autorización |
|---------------|--------------|
| `GET /api/v1/auth/identity-providers` | Anónimo |
| `POST /api/v1/auth/oauth-authorizations` | Anónimo (`login`) · por identidad (`link`) |
| `GET /api/v1/auth/oauth/google/callback` | Posesión de la sesión que arrancó el flujo |
| **`GET /api/v1/auth/mfa-challenges`** (`§E.5b`) | Posesión de la sesión que abrió el desafío |
| `GET /api/v1/auth/identities` | Identidad del portador |
| `DELETE /api/v1/auth/identities/{public_id}` | Identidad del portador |

**1.4 no modifica ningún endpoint existente y no toca ninguno de `REQ-CORE`.** Es la primera vez desde 1.2b que un paso de este módulo no altera el contrato de `POST /auth/session` ni el recurso de `GET /me`: el login federado **no pasa por ahí**, tiene su propio camino, y termina en la misma sesión.

**Matiz obligatorio, porque la frase anterior se puede leer de más**: no modificar nada existente **no significa que 1.4 no añada superficie a recursos de pasos anteriores**. Sí lo hace, una vez: `GET /auth/mfa-challenges` amplía la superficie de `mfa_challenges`, que es un recurso de `REQ-AUTH-003` entregado en 1.3 (`§E.5b`, con nota cruzada en `§C.3`). Lo que se conserva intacto es el **contrato** de los *endpoints* que ya existían, no el inventario de rutas por recurso.

---

## E.7 Convenciones transversales: dónde 1.4 se aparta o matiza

### E.7.1 Una excepción de verdad a `ADR-038`, la primera del módulo

El *callback* **no responde `problem+json`, no devuelve recursos y siempre responde `302`**. `§E.4.1` tiene el argumento entero. Se registra aquí, y no solo allí, para que la revisión no lo tome por descuido: es el único endpoint del producto cuyo cliente es un navegador que viene de un tercero.

### E.7.2 Sin `Idempotency-Key`, otra vez

Por cuarta vez, y por los motivos de §9.3: ninguna escritura de este paso es una operación de negocio costosa cuyo reintento produzca un duplicado que el usuario pague. El arranque del flujo sustituye el `state` anterior; el *callback* es idempotente por construcción, porque el `state` es de un solo uso (`CA-AUTH-205`); y la desvinculación repetida responde `404`.

### E.7.3 Un `GET` que escribe, otra vez

El *callback* crea una sesión y a veces una fila. Ya ocurría con `GET /auth/sessions` en 1.2b (`§B.7.1`). La diferencia es que allí era una decisión nuestra y aquí la forma la impone el protocolo.

### E.7.4 Un `DELETE` con cuerpo, otra vez

`§C.8.3`, sin cambios.

### E.7.5 Enumerados

Tres, todos cerrados y todos con su `CHECK` o su validación en servidor: `provider` (`google`), `intent` (`login`, `link`) y **el código de resultado del *callback***, que no está en base de datos pero es igual de cerrado (`§E.4.2`) y cuya lista es lo que hace traducible la pantalla.

---

## E.8 Eventos de dominio y webhooks

**Dos eventos nuevos**, `IdentityLinked` e `IdentityUnlinked` (`funcional.md §E.7.3`). **Ningún webhook**: `REQ-API` es fase 2 y sigue sin haber suscriptores externos.

**`UserLoggedIn` se publica igual que siempre** en el login federado. No hay una variante `UserLoggedInFederated`: el hecho es el mismo, y quien necesite la distinción la tiene en `login_attempts.method` (`datos.md §E.3`, con la salvedad de retención de `funcional.md §E.8`).
