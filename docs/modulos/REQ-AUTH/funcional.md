# REQ-AUTH · Autenticación y gestión de identidad · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-AUTH` |
| Prioridad | MUST |
| Fase | 1 · Bloque A · **pasos 1.2 y 1.2b** |
| Depende de | 1.1 (`REQ-CORE`: usuarios, invitaciones, `tenant_settings`, `GET /tenant/branding`), 0.7 (`ADR-033`), 0.8 (`ADR-034`), 0.9 (auditoría `ADR-035`/`ADR-036`, i18n) |
| Estado | **APROBADO** el 2026-08-22 (§14). Único trabajo previo a implementar: `ADR-039`, en redacción por `architect` |
| Módulo (código) | `auth` · `apps/api/app/Modules/Auth` · `apps/web/src/modules/auth` |

> Fuente de verdad: sección 5.2 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` (`REQ-AUTH-001` a `REQ-AUTH-005`). Este documento **no** reabre lo decidido en `ADR-014`, `ADR-025`, `ADR-029`, `ADR-033`, `ADR-034`, `ADR-035`, `ADR-036` ni `ADR-038`, ni el alcance del paso 1.2 fijado con el usuario el 2026-08-22.
>
> **Estructura del documento**: las secciones **§0 a §14** son el paso **1.2**, cerrado y mezclado el 2026-08-25 (`docs/historial/1.2-auth-local-sesiones.md`). No se reescriben: son el registro de lo decidido y lo construido. La **Parte B** (`§B.0` en adelante, al final) es el paso **1.2b** — puntos 2, 3 y 4 de `REQ-AUTH-005`, diferidos en el issue [#59](https://github.com/pirexia/plataforma-educativa/issues/59) —, y **está pendiente de aprobación** (`§B.14`). La numeración de la Parte B es independiente para no desplazar las referencias cruzadas ya escritas a §1-§14 desde este y otros documentos, mismo criterio que `api.md §5b`.

---

## 0. Antes de nada: dependencias no implementadas

`CLAUDE.md §0` obliga a decirlo antes de continuar, no al final.

| Dependencia | Estado | Qué bloquea exactamente |
|-------------|--------|-------------------------|
| **`0.10c` · Proveedor de correo transaccional** (`OPEN-09`) | **Pendiente** | Es la dependencia grave de este módulo. `REQ-AUTH-001` hace del correo el **único** canal de recuperación de contraseña y de desbloqueo de cuenta. Sin él, 1.2 se puede implementar y probar (los tests comprueban que el trabajo se encola, no que el correo llega), pero **no se puede poner en producción**: un usuario que olvide la contraseña o se bloquee queda fuera del sistema sin más salida que el desbloqueo manual del administrador. Hereda y agrava `OPEN-CORE-04`. |
| **`0.10b` · Dominio, DNS con comodín y certificado** (`OPEN-08`) | **Pendiente** | Impide **verificar de verdad** la propiedad central de la que depende el aislamiento de sesión de este módulo: que la cookie de `centroa.dominio` no viaja a `centrob.dominio` con `Secure` y TLS reales. En WSL2 se puede probar con dos hosts falsos sobre HTTP, que cubre el atributo `Domain` pero no `Secure`. Ver `OPEN-AUTH-11`. |
| **`1.5` · Permisos granulares** | Posterior | Rige el **resolutor provisional** de `ADR-034 §2` (lee `effect`, ignora `scope`). Consecuencia idéntica a la de 1.1: los dos permisos nuevos de este módulo se siembran con ámbito `todos` y ningún otro (`RN-CORE-22`). Ver `permisos.md` §5. |
| **`1.3` · MFA**, **`1.4`/`1.4b`** · Google y SSO, **`1.2b`** · panel de sesiones | Posteriores | Fuera de alcance por decisión del usuario (§1). Este documento fija los puntos de extensión para que no haya que rehacer el flujo de login. |

Ninguna de las cuatro impide **redactar ni implementar** 1.2. Las dos primeras impiden **cerrarlo como operable en producción**.

---

## 1. Alcance del paso 1.2: qué entra y qué no

Alcance acordado con el usuario el 2026-08-22. No se reabre aquí.

### 1.1 Entra en 1.2

| Sub-requisito | Qué parte |
|---------------|-----------|
| `REQ-AUTH-001` | Canje de la invitación emitida por 1.1 (que es lo que cumple «validación de email mediante enlace de confirmación», §1.3). Login local con email y contraseña. Política de contraseñas. Bloqueo tras 5 intentos fallidos consecutivos, con **caducidad automática configurable** (15 min por defecto) **más** desbloqueo por correo y por administrador. Recuperación de contraseña por correo con token temporal de un solo uso. |
| `REQ-AUTH-005` | **Solo el punto 1**: expiración de sesión por inactividad, configurable, por defecto 30 minutos. |
| `ADR-025` | Sesión por cookie `httpOnly`/`Secure`/`SameSite` con CSRF. Logout. |
| **Cambio de contraseña auto-servicio** | `POST /auth/password-changes` para el usuario ya autenticado. **No está en `REQ-AUTH-001`**; entra por decisión del usuario del 2026-08-22 al resolver `OPEN-AUTH-05` (§4.8). |
| **Cinco pantallas públicas más una privada** | Login, activación, solicitud de recuperación, restablecimiento, cuenta bloqueada y cambio de contraseña. Austeras, sobre el Tailwind actual, sin esperar al *design system* de 1.7. Decisión del usuario del 2026-08-22 al resolver `OPEN-AUTH-01` (§1.6). |
| `ADR-033 §2` | Reverificación del tenant de la sesión contra el tenant resuelto por host, que 0.7 dejó explícitamente pendiente de este paso. |
| [#18](https://github.com/pirexia/plataforma-educativa/issues/18) | Repositorio de tokens de restablecimiento **propio y consciente de tenant**, con el predicado de tenant explícito en la consulta y no solo RLS. §7. |
| [#8](https://github.com/pirexia/plataforma-educativa/issues/8) | Cookie de sesión *host-only* **reforzada activamente**, no heredada del valor por defecto. §6. |

### 1.2 No entra en 1.2

| Fuera | Dónde va | Motivo |
|-------|----------|--------|
| MFA/2FA (`REQ-AUTH-003`) | **1.3** | Decisión del plan. §9 fija el punto de extensión: el login termina en un paso único «sesión establecida» que 1.3 desdoblará en «credencial verificada → reto MFA → sesión establecida». |
| Login con Google (`REQ-AUTH-002`) | **1.4** | Ídem. Este paso **no** crea `identity_providers` ni ninguna columna «por si acaso» (`ADR-034 OPEN-13`). |
| SSO SAML 2.0 / OIDC (`REQ-AUTH-004`) | **1.4b** ([#58](https://github.com/pirexia/plataforma-educativa/issues/58)) | Ídem. |
| Panel de sesiones activas, cierre remoto por el usuario, detección de nuevo dispositivo/ubicación (`REQ-AUTH-005` puntos 2-4) | **1.2b** ([#59](https://github.com/pirexia/plataforma-educativa/issues/59)) | Necesitan modelo de datos propio (metadatos de dispositivo/IP por sesión) y `sessions` con `tenant_id`. Ver §1.5 y `OPEN-AUTH-10`. |
| Registro auto-servicio de usuarios | **No existe en este producto** | §1.3. |
| «Recordarme» (sesión persistente) | Sin paso asignado | No está en ningún requisito, y contradice de frente el timeout de inactividad de 30 minutos de `REQ-AUTH-005`. `OPEN-AUTH-09`. |
| Pantallas con navegación, *layout* por rol o *dashboard* | **1.8** | Las seis pantallas que sí entran (§1.6) son las que se ven **sin navegación**: cinco sin sesión y una de formulario aislado. Ninguna necesita el *layout* de 1.8 ni los *dashboards* por rol. |
| Tokens de API para clientes móviles y de terceros | `REQ-API` (fase 2) | `ADR-025` los remite ahí explícitamente: «los clientes móviles y de terceros usan tokens de API con ámbito limitado, nunca la sesión web». |
| Restablecimiento de MFA por el administrador | **1.3** | No hay MFA que restablecer. |

### 1.3 «Registro con email y contraseña»: interpretación heredada, no decisión nueva

`REQ-AUTH-001` dice literalmente *«Registro con email y contraseña. Validación de email mediante enlace de confirmación»*. `REQ-CORE/funcional.md` (línea 66) ya lo reinterpretó al cerrar 1.1: **la creación de usuarios es exclusivamente por invitación del Administrador de Centro**, y el canje de esa invitación es lo que cumple ese requisito.

1.2 sigue ese criterio sin reabrirlo. En consecuencia:

- **No existe ningún endpoint de alta auto-servicio.** Nadie se da de alta solo, ni con correo del centro ni con ningún otro.
- El «enlace de confirmación» es el enlace de invitación de `REQ-CORE-003`. Al canjearlo se fija la contraseña **y** se estampa `users.email_verified_at`: la posesión del buzón queda demostrada en el mismo acto.
- No hay un segundo flujo de «reenviar correo de verificación»: el reenvío de invitación de 1.1 (`POST /users/{id}/invitations`) ya lo cubre.

Es una interpretación **restrictiva** de un requisito escrito para un producto con registro abierto. Si el usuario quisiera un alta auto-servicio, sería un requisito nuevo con su propio análisis de protección de datos (`INV-008`: en un centro educativo, un alta abierta acepta datos de menores sin base legal ni consentimiento del tutor).

### 1.4 Frontera con `REQ-CORE`: qué toca 1.2 de lo ya construido

1.2 **no reabre** ninguna decisión de 1.1, pero sí toca cuatro cosas suyas. Se listan aquí para que la revisión no las descubra en el diff:

1. **`tenant_settings` gana una columna**: `session_timeout_minutes` (§5, `RN-AUTH-30`). `REQ-CORE/funcional.md §1.4` ya lo anticipó y lo dejó escrito para este paso: *«1.1 no crea el ajuste `session_timeout_minutes`: la semántica del timeout la define 1.2, y añadir la columna después es expand puro»*. Se expone dentro del recurso existente `GET`/`PATCH /tenant/settings`, bajo un grupo nuevo `security`. **No hay endpoint nuevo** ni permiso nuevo: sigue siendo `configuracion.leer` / `configuracion.actualizar`.
2. **`user_invitations` se escribe por primera vez desde fuera de `REQ-CORE`**: el canje informa `accepted_at`. Se hace a través de una interfaz pública de `REQ-CORE` (`InvitationRedeemer`, §8), **nunca importando su código interno** (`INV-007`).
3. **Se consumen dos eventos de dominio de `REQ-CORE`** que 1.1 ya declaró con `REQ-AUTH` como consumidor previsto: `UserDeactivated` y `UserEmailChanged` (§8).
4. **Cambia el orden del *middleware* del grupo `/api/v1`** para intercalar sesión y CSRF (§6.4). Ese cambio corrige de paso un defecto latente de 1.1: `ResolveApiLocale` llama a `$request->user()`, que con guard de sesión devuelve `null` mientras la sesión no se haya arrancado, de modo que el paso 1 del orden de idioma de `ADR-038 §11` (idioma preferido del usuario autenticado) **hoy no se aplica nunca fuera de los tests con `actingAs()`**. No es un fallo de 1.1 —cuando se escribió no había sesión que arrancar, y su propio comentario lo dice— pero sí es trabajo obligatorio de 1.2. Ver `RN-AUTH-33`.

### 1.5 `sessions` sigue sin `tenant_id` en 1.2

`ADR-034 §8` lo anotó: *«`sessions` no tiene `tenant_id`. No hay fuga […] pero `REQ-AUTH-005` pide listar y revocar las sesiones activas de un usuario, y eso necesitará la columna. Se anota para 1.2; añadirla es expand puro»*. Y `config/tenancy.php` la declara hoy como tabla `framework`, fuera del sistema de tenancy.

**Decisión de 1.2: no se añade la columna.** El paso que necesita listar y revocar sesiones es **1.2b**, no 1.2, y añadir ahora una columna que ningún camino de código lee es exactamente lo que `ADR-034 OPEN-13` prohíbe. Lo que 1.2 sí garantiza, y hay que verificar que basta, son tres barreras independientes:

1. La cookie es *host-only*, así que el navegador ni siquiera envía la sesión de un tenant al host de otro (§6).
2. El *payload* de la sesión guarda el `tenant_id` con el que se autenticó, y se **reverifica** contra el tenant resuelto por host en cada petición (`ADR-033 §2`, `RN-AUTH-31`).
3. El identificador de sesión es aleatorio y no enumerable; la búsqueda es por él, nunca por usuario ni por correo.

Queda registrado como `OPEN-AUTH-10` con su consecuencia honesta: hasta 1.2b, `sessions` es una tabla compartida sin RLS, y una inyección SQL en cualquier punto del sistema leería los *payloads* de sesión de todos los tenants. Es una consecuencia del diseño heredado del framework, no de este paso, y la mitigación real (`RSEC-OWASP-001`, consultas parametrizadas) ya está en vigor.

### 1.6 Interfaz de usuario: 1.2 **sí** entrega pantallas

**Decisión del usuario del 2026-08-22** (`OPEN-AUTH-01`), y se aparta a propósito de lo que `REQ-CORE` decidió para 1.1 (`OPEN-CORE-02`, solo API). El motivo del apartamiento: **1.2 es el paso que convierte el producto en algo alcanzable por una persona**. Sin pantalla de login, cerrar 1.2 deja el sistema exactamente igual de inaccesible que lo dejó 1.1, y 1.1 ya construyó `GET /tenant/branding` declarando literalmente su motivo como *«para que la pantalla de login de 1.2 pueda pintarse antes de que haya sesión»*.

**Seis pantallas**, en `apps/web/src/modules/auth/views/`:

| Ruta de la SPA | Pantalla | Sesión |
|----------------|----------|--------|
| `/entrar` | Login | No |
| `/activar/:token` | Activación (canje de invitación) | No |
| `/recuperar` | Solicitud de recuperación | No |
| `/restablecer/:token` | Restablecimiento de contraseña | No |
| `/desbloquear/:token` | Desbloqueo de cuenta | No |
| `/cuenta/contrasena` | Cambio de contraseña auto-servicio (§4.8) | **Sí** |

Las tres rutas con `:token` son exactamente las que fija el enlace de cada correo (`operacion.md §5`): la SPA extrae el token de su propia URL y lo envía **en el cuerpo** de la llamada correspondiente (§4.7).

**Austeras a propósito.** Se construyen sobre el Tailwind y los primitivos de shadcn-vue ya presentes desde 0.5, **sin esperar al *design system* de 1.7**, y se asume el restyle cuando 1.7 exista. Lo que hace viable esa decisión es que las seis son pantallas **sin navegación**: cinco no tienen sesión y la sexta es un formulario aislado, así que ninguna depende del *layout* responsive de 1.8, ni de los menús adaptativos, ni de los *dashboards* por rol. Construirlas ahora no adelanta trabajo de 1.8; construir cualquier **otra** pantalla sí lo haría, y por eso no entra ninguna más.

Reglas que sí son obligatorias desde ya, sin excepción por ser austeras (`CLAUDE.md §10`):

- **Branding por tenant** en las cinco públicas: nombre, colores, logo y fondo desde `GET /tenant/branding`, que es el consumidor para el que 1.1 lo construyó (`RUX-BRAND-002`, `RUX-BRAND-004`).
- **Cuatro idiomas** (`INV-009`), con el idioma resuelto por `Accept-Language` ∩ idiomas activos del centro, porque en cinco de las seis todavía no hay usuario del que leer la preferencia.
- **WCAG 2.2 AA** (`RNF-UX-002`): son las primeras pantallas del producto y la única puerta de entrada; una barrera de accesibilidad aquí deja fuera del sistema, no de una funcionalidad.
- **La validación de la política de contraseñas que muestran es solo ayuda visual** (`RN-AUTH-37`). La que decide es la del servidor (`INV-010`).
- **Ninguna escribe credencial ni token en `localStorage`/`sessionStorage`** (`ADR-025`, `RN-AUTH-28`, `CA-AUTH-006`).

Consecuencia que hay que aceptar: al cerrar 1.2, `REQ-AUTH` **sí** es demostrable a mano de extremo a extremo (salvo la entrega real de correo, `OPEN-AUTH-07`), a diferencia de `REQ-CORE`, que sigue esperando a 1.8.

---

## 2. Contradicciones detectadas

Ninguna bloqueante. Dos tensiones reales que conviene dejar por escrito para que la revisión de seguridad no las descubra como hallazgo:

### 2.1 `RSEC-OWASP-003` frente a `ADR-025` — resuelta por el ADR

`RSEC-OWASP-003` pide *«gestión segura de sesiones: tokens con expiración y **refresh tokens rotativos**»*. Los *refresh tokens* rotativos son un mecanismo del mundo OAuth/JWT, y `ADR-025` prohíbe explícitamente el JWT en el navegador y fija la sesión por cookie.

No es una contradicción viva: `ADR-025` es posterior y decide sobre el mismo asunto, y su motivo cita literalmente `RSEC-OWASP-003`. La lectura correcta de ese requisito bajo `ADR-025` es «identificador de sesión con expiración y **rotación en cada cambio de privilegio**», que es lo que 1.2 implementa (`RN-AUTH-32`: rotación del identificador en login y en logout). Se anota, no se reabre.

### 2.2 `REQ-AUTH-001` «registro con email y contraseña» frente al alta por invitación

Tratada en §1.3. No se resuelve aquí porque **ya estaba resuelta** en `REQ-CORE`, y 1.2 se limita a seguir el mismo criterio.

---

## 3. Actores y roles implicados

| Actor | Qué hace en 1.2 |
|-------|-----------------|
| **Persona invitada** (aún sin cuenta activa) | Canjea su invitación: fija su contraseña y activa su cuenta. Sin sesión, sin permiso. |
| **Cualquier usuario del centro** | Inicia sesión, cierra sesión, solicita recuperación de contraseña, restablece su contraseña, se desbloquea por correo. Todo por identidad o por posesión de token, **nunca por permiso**. |
| **Administrador de Centro** | Consulta las cuentas bloqueadas de su centro y las desbloquea (`bloqueo_cuenta.leer`, `bloqueo_cuenta.eliminar`). Configura el timeout de inactividad del centro (permiso ya existente `configuracion.actualizar`). |
| **Dirección / Secretaría / resto** | **Nada** en 1.2 salvo su propio acceso. Denegación por defecto (`RPERM-011`). |
| **Super Administrador** | **Ninguna operación en 1.2.** El backoffice y su MFA obligatorio son 1.6 (`REQ-BO-007`). |
| **Operador de sistemas** | Ninguna operación interactiva. Solo despliegue y las tareas programadas de §11 de `operacion.md`. |

---

## 4. Flujos principales

### 4.1 Canje de la invitación (`REQ-AUTH-001`, contrato fijado por `REQ-CORE/funcional.md §4.3`)

El contrato del token **ya está fijado por 1.1 y es de obligado cumplimiento**. 1.2 lo consume, no lo redefine:

- Enlace: `https://{slug}.{dominio_base}/activar/{token}` — **ruta de la SPA**, no de la API.
- Token: 32 bytes aleatorios en hexadecimal, generado en 1.1 (`bin2hex(random_bytes(32))`).
- Persistencia: **solo el hash** SHA-256, en `user_invitations.token_hash`. El valor en claro no está en base de datos, ni en logs, ni en auditoría (`RN-CORE-19`).
- Resolución: el tenant se resuelve **por el host antes de tocar datos** (`ADR-033 §2`); la búsqueda es por `(tenant_id, hash(token))`.
- Validez: la invitación no puede estar caducada (7 días, `RN-CORE-10`), ni revocada, ni aceptada.

Flujo:

1. El navegador abre `https://{slug}.{dominio_base}/activar/{token}`. La SPA pinta el formulario con el branding del centro (`GET /tenant/branding`, ya existente y anónimo) y con la política de contraseñas en el idioma resuelto.
2. El usuario envía contraseña y confirmación a `POST /api/v1/auth/invitation-redemptions`, con el token **en el cuerpo, nunca en la ruta** (§4.7).
3. El servidor resuelve el tenant por host, busca la invitación por `(tenant_id, sha256(token))` y comprueba: existe, no caducada, no revocada, no aceptada, y su usuario está en `pendiente`. Cualquier fallo ⇒ `410` con la **misma** respuesta en todos los casos (§4.7).
4. Valida la contraseña contra la política (`RN-AUTH-01` a `RN-AUTH-04`). Fallo ⇒ `422` con `errors` por campo.
5. En **una transacción**: fija `users.password` (hasheada), `users.status = 'activo'`, `users.email_verified_at = now()` y `user_invitations.accepted_at = now()`.
6. Se purgan los efectos colaterales de la cuenta: se borra cualquier token de restablecimiento vivo y se levanta cualquier bloqueo vivo de ese correo (`RN-AUTH-20`).
7. El *observer* de 0.9 audita las dos escrituras (`INV-003`): `updated` sobre `User` (con `password` redactado como `secret` por el patrón global, y `status`/`email_verified_at` con su valor) y `updated` sobre `UserInvitation` (`accepted_at`).
8. Respuesta `204`. **No se inicia sesión automáticamente** (`RN-AUTH-21`): la SPA redirige al login con un aviso de éxito.

### 4.2 Login local (`REQ-AUTH-001`, `ADR-025`)

1. La SPA obtiene la cookie CSRF (`GET /api/v1/auth/csrf-cookie`, §4.7) si no la tiene ya.
2. Envía `POST /api/v1/auth/session` con `email` y `password`, cabecera `X-XSRF-TOKEN` y `credentials: include`.
3. El servidor normaliza el correo (recorte de espacios y minúsculas) y aplica, **en este orden**:
   1. **Límite de tasa por IP** y por `(tenant_id, email)` (`operacion.md §6`). Excedido ⇒ `429` con `Retry-After`.
   2. **Comprobación de bloqueo**: existe un `account_lockouts` vivo para `(tenant_id, email)` ⇒ `423`, sin verificar la contraseña. La respuesta es idéntica exista o no la cuenta (§4.7).
   3. **Verificación de credencial** contra el usuario vivo de ese tenant con ese correo. Si no hay usuario, se ejecuta igualmente una comparación de hash contra un valor señuelo, para no dejar un oráculo de tiempo.
   4. **Comprobación de estado**: `pendiente` o `inactivo` ⇒ se deniega con el **mismo** `401` genérico (§4.7).
4. Cada intento —éxito o fallo, exista la cuenta o no— escribe una fila en `login_attempts` (§5.1).
5. **Fallo de credencial**: se incrementa el recuento de fallos consecutivos de `(tenant_id, email)`. Alcanzados 5, se crea el bloqueo (§4.4). Respuesta `401`.
6. **Éxito**:
   1. Se regenera el identificador de sesión (`RN-AUTH-32`, fijación de sesión).
   2. Se guardan en el *payload* de la sesión el `user_id`, el `tenant_id` y la marca de última actividad.
   3. Se re-hashea la contraseña si el coste del hash almacenado quedó por debajo del configurado (`RN-AUTH-03`).
   4. Se borra el recuento de fallos consecutivos de ese correo.
   5. Se audita el acceso (`INV-003`, §10).
   6. Respuesta `200` con **el mismo recurso que `GET /me`** de `REQ-CORE` (usuario, persona, roles y permisos efectivos), para que la SPA no tenga que encadenar una segunda petición.

### 4.3 Logout

1. `DELETE /api/v1/auth/session`, con sesión válida y CSRF.
2. Se invalida la sesión (se borra la fila de `sessions`), se regenera el token CSRF y se caduca la cookie.
3. Se audita (§10).
4. Respuesta `204`. Es **idempotente**: repetirlo sin sesión también responde `204`, nunca `401` — cerrar una sesión que ya no existe no es un error, y devolver `401` obligaría a la SPA a tratar un caso normal como fallo.

### 4.4 Bloqueo de cuenta tras 5 intentos fallidos (`REQ-AUTH-001`)

1. El quinto fallo **consecutivo** para `(tenant_id, email)` crea una fila en `account_lockouts` con `failed_count`, `locked_at`, `expires_at = locked_at + AUTH_LOCKOUT_MINUTES` y, si la cuenta existe, un token de desbloqueo aleatorio (32 bytes) del que solo se guarda el hash.
2. Si la cuenta existe, se **encola** (`INV-012`) un correo al titular con tres cosas: el aviso de bloqueo, **el momento en que se levantará solo** y el enlace `https://{slug}.{dominio_base}/desbloquear/{token}` para no esperar. Si no existe, no se envía nada — no hay a quién escribir, y quien provocó el bloqueo no recibe ninguna señal distinta.
3. Desde ese momento y hasta que se levante, todo intento de login con ese correo responde `423` sin verificar la contraseña.
4. El bloqueo se levanta de **tres** formas (`RN-AUTH-14`), y solo de esas tres:
   - **Por caducidad**: transcurridos `AUTH_LOCKOUT_MINUTES` (**15 por defecto**, configurable). No requiere intervención de nadie. `unlock_reason = 'caducidad'`.
   - **Por correo**: `POST /api/v1/auth/account-unlocks` con el token del enlace. Un solo uso, caducidad 24 h (configurable). `unlock_reason = 'correo'`.
   - **Por administrador**: `DELETE /api/v1/account-lockouts/{public_id}`, con permiso `bloqueo_cuenta.eliminar`. `unlock_reason = 'administrador'`, `unlocked_by` informado.
5. Al levantarlo se informa `unlocked_at` y `unlock_reason`. La fila **se conserva** (es traza, igual que una invitación revocada) y el *observer* audita el `updated`.
6. Levantar el bloqueo, por cualquiera de las tres vías, pone a cero el recuento de fallos consecutivos.

**Por qué la caducidad automática, y por qué no sustituye a las otras dos** (decisión del usuario del 2026-08-22, `OPEN-AUTH-03`). `REQ-AUTH-001` pide bloqueo con desbloqueo «por email o por administrador» y no menciona caducidad. Implementado al pie de la letra, el bloqueo sería indefinido y cualquiera que conociera el correo de un profesor podría dejarlo fuera del sistema con cinco intentos, con una salida que depende del correo transaccional (`0.10c`, **pendiente**) o de que haya un administrador disponible a las 8:00 de un lunes. La caducidad de 15 minutos elimina ese vector sin quitar nada: los dos desbloqueos que el requisito sí pide **siguen existiendo íntegros**, como atajo para quien no quiera esperar. Y quince minutos siguen siendo un freno eficaz contra la fuerza bruta: reducen el ritmo máximo a 20 intentos por hora y por cuenta.

**El cierre por caducidad es perezoso, más una tarea que lo consolida.** La invariante de «un solo bloqueo vivo por correo» (`RN-AUTH-17`) es un índice único parcial sobre `unlocked_at IS NULL`, y una condición de índice no puede depender de `now()`. Así que un bloqueo caducado y todavía sin cerrar seguiría ocupando el hueco e impediría crear el siguiente. Por eso: el camino de login, al encontrar un bloqueo vencido, **lo cierra en la misma transacción** (`unlocked_at = expires_at`, `unlock_reason = 'caducidad'`) y sigue adelante; y `CloseExpiredLockouts` (`operacion.md §4`) hace lo mismo cada pocos minutos para los que nadie vuelva a tocar. Es un detalle de implementación con consecuencia funcional real, y por eso está aquí y no solo en `operacion.md`.

**Bloqueo «fantasma» para correos inexistentes.** El contador y el bloqueo se llevan por `(tenant_id, email)` **exista o no un usuario con ese correo**. Sin esto, el bloqueo sería un oráculo de enumeración de cuentas perfecto: cinco intentos con un correo existente devuelven `423`, cinco con uno inexistente siguen devolviendo `401` para siempre, y un atacante enumera el censo del centro sin acertar una sola contraseña. El coste es una fila por correo probado cinco veces, que la purga de `operacion.md §5` retira.

**Este bloqueo indefinido es un vector de denegación de servicio dirigido**: cualquiera que conozca el correo de un profesor puede dejarlo fuera del sistema con cinco intentos, y la salida depende del correo transaccional (`0.10c`, pendiente) o de que un administrador esté disponible. Es lo que pide `REQ-AUTH-001` literalmente y no lo cambio por mi cuenta: `OPEN-AUTH-03`, con recomendación.

### 4.5 Recuperación de contraseña (`REQ-AUTH-001`)

**Fase 1 — solicitar.**

1. `POST /api/v1/auth/password-reset-requests` con el correo. Sin sesión. Límite de tasa por IP y por `(tenant_id, email)`.
2. La respuesta es **siempre `202`**, exista o no la cuenta, esté activa o no, y con la misma latencia observable. No revela nada (`RN-AUTH-10`).
3. Si —y solo si— existe un usuario vivo y **activo** con ese correo en ese tenant:
   1. Se genera un token aleatorio de 32 bytes; se guarda **solo su hash** SHA-256 en `password_reset_tokens`, con caducidad de 60 minutos (configurable).
   2. La solicitud nueva **sustituye** a cualquier otra viva del mismo correo (`RN-AUTH-11`): la clave primaria `(tenant_id, email)` lo garantiza por construcción.
   3. Se **encola** el correo (`INV-012`) en el idioma preferido del destinatario, con el enlace `https://{slug}.{dominio_base}/restablecer/{token}`.
4. Un usuario `pendiente` **no** recibe correo de recuperación: lo que le falta es canjear su invitación, no restablecer una contraseña que nunca fijó. Un usuario `inactivo` tampoco: su cuenta está dada de baja.

**Fase 2 — restablecer.**

5. `POST /api/v1/auth/password-resets` con el token **en el cuerpo** y la contraseña nueva.
6. Búsqueda por `(tenant_id, sha256(token))` con el predicado de tenant **explícito en la consulta** (§7, issue #18). Token inexistente, caducado o ya usado ⇒ `410`, siempre igual.
7. Se valida la contraseña contra la política. Fallo ⇒ `422`.
8. En una transacción: se fija la contraseña nueva, **se borra la fila del token** (un solo uso garantizado por la desaparición de la fila, no por una bandera que alguien pueda olvidar comprobar), se levanta cualquier bloqueo vivo de ese correo y **se revocan todas las sesiones activas del usuario** (`RN-AUTH-22`).
9. El *observer* audita el `updated` sobre `User` con `password` redactado como `secret`.
10. Respuesta `204`. **No se inicia sesión automáticamente**, por el mismo motivo que en el canje.

### 4.6 Expiración de sesión por inactividad (`REQ-AUTH-005`, punto 1)

1. El centro configura `session_timeout_minutes` en `PATCH /tenant/settings` (grupo `security`). Por defecto **30**, rango admitido **5 a 480**.
2. En cada petición autenticada, un *middleware* compara la marca de última actividad guardada en el *payload* de la sesión contra ese valor:
   - Dentro de la ventana ⇒ se refresca la marca y sigue.
   - Fuera ⇒ **se invalida la sesión**, se audita y se responde `401` con `urn:pge:error:unauthenticated`.
3. La marca se guarda en el *payload*, no se lee de `sessions.last_activity`: el propio controlador de sesión de Laravel refresca esa columna **antes** de que corra ningún *middleware*, así que leerla siempre daría «actividad ahora mismo» y el timeout nunca dispararía. Es la trampa concreta de este flujo y está anotada en `operacion.md §9`.
4. `SESSION_LIFETIME` (el corte global del framework) debe ser **mayor o igual** que el máximo configurable por tenant, o el framework mataría sesiones antes que la regla del centro. Hay guarda de arranque y test (`RN-AUTH-30`, `CA-AUTH-052`).

### 4.7 Reglas de forma comunes a todos los flujos anónimos

Se agrupan aquí porque son la parte del diseño con más consecuencias de seguridad y no deben quedar repartidas.

**Los tokens viajan en el cuerpo, nunca en la ruta ni en la cadena de consulta.** Un token en la URL acaba en el registro de acceso del proxy, en el historial del navegador, en la cabecera `Referer` hacia cualquier recurso externo y en el `instance` de un `problem+json` (`ADR-038 §6.5`). El enlace que llega por correo es una **ruta de la SPA** (`/activar/{token}`, `/restablecer/{token}`, `/desbloquear/{token}`), y la SPA extrae el token de su propia URL para enviarlo en el cuerpo.

> **Divergencia documental que hay que corregir**: `REQ-CORE/api.md §4` anticipó el canje como `POST /invitations/{token}/accept`, con el token en la ruta. Fue una nota orientativa; el contrato vinculante que 1.1 fijó está en `REQ-CORE/funcional.md §4.3` (formato del token, hash, búsqueda por `(tenant_id, hash)`, condiciones de validez) y esta especificación lo cumple íntegro. La forma HTTP se cambia por el motivo de arriba. Ver `OPEN-AUTH-08`.

**Respuestas indistinguibles.** Un cliente anónimo no puede deducir de una respuesta si una cuenta existe, en qué estado está, ni si un token existió alguna vez:

| Situación | Respuesta |
|-----------|-----------|
| Contraseña incorrecta · correo inexistente · usuario `pendiente` · usuario `inactivo` | `401` `urn:pge:error:unauthenticated`, cuerpo idéntico |
| Cuenta bloqueada · correo inexistente bloqueado por bloqueo fantasma | `423` `urn:pge:error:account-locked`, cuerpo idéntico |
| Token (de invitación, restablecimiento o desbloqueo) inexistente, caducado, revocado o ya usado | `410` `urn:pge:error:gone`, cuerpo idéntico |
| Solicitud de recuperación, con o sin cuenta detrás | `202`, cuerpo idéntico |

La consecuencia de usabilidad —un usuario `pendiente` que intenta iniciar sesión recibe «credenciales incorrectas» en vez de «activa tu cuenta»— se asume a propósito y se compensa en la pantalla de login con un aviso estático que no depende de la respuesta del servidor. La alternativa (decir la verdad) convierte el formulario de login en un verificador de altas del centro para cualquiera en Internet.

**Cookie CSRF.** `GET /api/v1/auth/csrf-cookie` responde `204` y deja la cookie `XSRF-TOKEN`. Es el equivalente propio de `/sanctum/csrf-cookie` **sin introducir la dependencia** (`CLAUDE.md §1`): con SPA y API bajo el mismo host (`ADR-028`) no hace falta nada de lo que Sanctum aporta para dominios separados. Existe como contrato explícito aunque cualquier respuesta del grupo `/api/v1` refresque ya la cookie, para que el arranque en frío de la SPA no dependa de un efecto colateral de otra llamada.

---

### 4.8 Cambio de contraseña por el propio usuario (`OPEN-AUTH-05`, aprobado)

**No está en `REQ-AUTH-001`.** Entra por decisión del usuario del 2026-08-22, y el motivo es que sin él la única forma de que alguien cambie su contraseña por precaución es pasar por «he olvidado mi contraseña» y su buzón de correo — un rodeo absurdo que además depende de `0.10c`, todavía pendiente. `RSEC-GDPR-004` («acceso y rectificación desde el propio panel») apunta en la misma dirección sin nombrarlo.

1. `POST /api/v1/auth/password-changes` con sesión válida y CSRF. **Sin permiso**: se autoriza por identidad del portador de la cookie, igual que el logout y que `/me` de `REQ-CORE`.
2. El cuerpo lleva la **contraseña actual** y la nueva con su confirmación. Exigir la actual no es ceremonia: es lo que impide que una sesión secuestrada —por un equipo desatendido o por un robo de cookie— se convierta en toma permanente de la cuenta.
3. La contraseña actual incorrecta responde `422`, **no** `401`: la sesión sigue siendo válida, lo que falla es el dato del formulario. Devolver `401` echaría al usuario del sistema por escribir mal su contraseña.
4. **Los fallos de contraseña actual cuentan hacia el bloqueo** de `(tenant_id, email)`, igual que los de login, y se registran en `login_attempts`. Es el mismo oráculo de fuerza bruta que el formulario de login, solo que ya autenticado, y dejarlo sin contar sería un rodeo trivial al límite de cinco intentos.
5. La contraseña nueva se valida contra **la misma** política que el canje y el restablecimiento (`RN-AUTH-01`, `RN-AUTH-02`). Si coincide con la actual, `422`.
6. En una transacción: se fija la contraseña nueva y **se revocan todas las sesiones del usuario salvo la actual** (`RN-AUTH-36`). Es la diferencia deliberada con el restablecimiento de §4.5, que las revoca todas: aquí el usuario está delante y expulsarlo de su propia sesión por haber hecho lo correcto es un castigo sin motivo.
7. Se **encola** el correo de aviso «tu contraseña ha cambiado», sin enlace accionable (`operacion.md §5`). Es la única defensa del titular ante un cambio que no hizo él.
8. El *observer* audita el `updated` sobre `User` con `password` redactado como `secret`. **No hace falta ningún evento de auditoría nuevo**: este flujo no depende de `ADR-039`.
9. Respuesta `204`.

---

## 5. Reglas de negocio

| ID | Regla |
|----|-------|
| **Contraseña** | |
| `RN-AUTH-01` | Mínimo **12 caracteres**, con al menos una mayúscula, una minúscula, un dígito y un símbolo (`REQ-AUTH-001`). Se valida **siempre en servidor** (`INV-010`) y con la **misma** regla en todos los puntos que fijan contraseña: canje y restablecimiento. |
| `RN-AUTH-02` | Máximo **72 bytes**. No es un límite estético: bcrypt trunca silenciosamente a partir de ahí, y aceptar 100 caracteres para verificar solo los 72 primeros es un fallo de seguridad que nadie ve. Se rechaza con `422`. |
| `RN-AUTH-03` | La contraseña se almacena hasheada con **bcrypt**, coste mínimo 12, nunca en claro ni cifrada de forma reversible. En cada login correcto se comprueba si el hash necesita reamasado y, si lo necesita, se reamasa: es la única forma de subir el coste sin obligar a nadie a cambiar de contraseña. |
| `RN-AUTH-04` | No hay historial de contraseñas, ni caducidad periódica, ni comprobación contra listas de contraseñas filtradas. Ninguno de los tres está en `REQ-AUTH-001` y el tercero, además, exige una llamada saliente a un tercero sin contrato de encargado de tratamiento. Si se quisieran, son requisitos nuevos. |
| `RN-AUTH-05` | Ni la contraseña ni ningún fragmento suyo aparece en `login_attempts`, `account_lockouts`, logs de aplicación, mensajes de error o registro de auditoría. En auditoría queda cubierto por el patrón `*password*` de `config('audit.secret_attribute_patterns')` desde 0.9. |
| **Aislamiento** | |
| `RN-AUTH-06` | **Toda** búsqueda de este módulo —usuario por correo, invitación por token, token de restablecimiento, bloqueo, intentos— se resuelve por `(tenant_id, …)`. El `tenant_id` procede del host (`ADR-033 §2`) y **jamás** del cuerpo, de la ruta ni de una cabecera. |
| `RN-AUTH-07` | El predicado de tenant se escribe **explícitamente en la consulta**, además de la RLS. RLS es la segunda barrera, no la única (issue #18, §7). |
| `RN-AUTH-08` | Dos tenants pueden tener legítimamente el mismo correo de acceso. Un token emitido en el tenant A **nunca** es válido en el tenant B, ni siquiera para la cuenta homónima. |
| **Tokens** | |
| `RN-AUTH-09` | Todo token (invitación, restablecimiento, desbloqueo) son 32 bytes de un generador criptográfico, se persisten **solo como hash SHA-256** y el valor en claro solo viaja en el correo. Hereda `RN-CORE-19`. |
| `RN-AUTH-10` | Solicitar la recuperación responde `202` siempre, exista o no la cuenta. |
| `RN-AUTH-11` | Hay **como mucho un token de restablecimiento vivo** por `(tenant_id, email)`. Solicitar otro sustituye al anterior, que deja de funcionar en el acto. |
| `RN-AUTH-12` | El token de restablecimiento caduca a los **60 minutos** (configurable) y es de **un solo uso**: al consumirse se borra la fila. |
| `RN-AUTH-13` | El token de desbloqueo caduca a las **24 horas** (configurable) y es de un solo uso. |
| **Bloqueo** | |
| `RN-AUTH-14` | Bloqueo tras **5 intentos fallidos consecutivos** sobre `(tenant_id, email)`. «Consecutivos» significa que un login correcto pone el contador a cero. Se levanta de **tres** formas: **caducidad automática** a los `AUTH_LOCKOUT_MINUTES` (15 por defecto, configurable), desbloqueo por correo, o desbloqueo por administrador. Las tres registran `unlock_reason`. La caducidad **no sustituye** a las otras dos, que son las que pide `REQ-AUTH-001` (§4.4, `OPEN-AUTH-03` resuelta el 2026-08-22). |
| `RN-AUTH-15` | El contador y el bloqueo existen **aunque el correo no corresponda a ninguna cuenta** (bloqueo fantasma, §4.4). |
| `RN-AUTH-16` | Con bloqueo vivo, el login responde `423` **sin verificar la contraseña**. Una credencial correcta no lo levanta. |
| `RN-AUTH-17` | Hay **como mucho un bloqueo vivo** por `(tenant_id, email)`, garantizado por índice único parcial, no por comprobación de aplicación. |
| `RN-AUTH-18` | Levantar un bloqueo conserva la fila con `unlocked_at` y `unlock_reason` (`caducidad`, `correo` o `administrador`), más `unlocked_by` en el último caso. No se borra: es traza. |
| `RN-AUTH-19` | Un usuario no puede desbloquearse a sí mismo desde la API de administración, ni desbloquear a otro sin `bloqueo_cuenta.eliminar`. |
| `RN-AUTH-38` | Un bloqueo **vencido pero todavía sin cerrar** no bloquea: el camino de login lo cierra como `caducidad` en la misma transacción y continúa. La invariante de `RN-AUTH-17` se mantiene porque el hueco del índice único queda libre en ese mismo momento (§4.4). |
| **Canje y estados** | |
| `RN-AUTH-20` | El canje exige invitación **no caducada, no revocada y no aceptada** y usuario en `pendiente` (contrato de `REQ-CORE/funcional.md §4.3`). Sus efectos son atómicos: contraseña, `status = 'activo'`, `email_verified_at`, `accepted_at`, purga de tokens de restablecimiento vivos y levantamiento de bloqueos vivos de ese correo. |
| `RN-AUTH-21` | Ni el canje ni el restablecimiento inician sesión. La sesión nace **solo** de `POST /auth/session`, que es el único camino que 1.3 tendrá que desdoblar para el MFA. |
| `RN-AUTH-22` | Fijar una contraseña nueva (canje o restablecimiento) **revoca todas las sesiones activas de ese usuario**. Es una medida de contención, no la funcionalidad «cerrar sesión en todos los dispositivos» de `REQ-AUTH-005` punto 2, que sigue siendo 1.2b. |
| `RN-AUTH-23` | Solo un usuario `activo` puede iniciar sesión. `pendiente` e `inactivo` se deniegan con el `401` genérico de §4.7. |
| `RN-AUTH-24` | Una credencial correcta sobre un usuario no activo **no** cuenta como intento fallido: no lo es. Se registra en `login_attempts` con su propio resultado. |
| `RN-AUTH-25` | Solo un tenant `activo` permite iniciar sesión. Ya lo garantiza `ResolveTenant` (`404`/`503`) antes de llegar a este módulo. |
| **Sesión y cookie** | |
| `RN-AUTH-26` | La cookie de sesión es `httpOnly`, `Secure` y `SameSite=Lax`, y **host-only**: se emite sin atributo `Domain`. `SESSION_DOMAIN` con valor **aborta el arranque de la aplicación** (§6, issue #8). |
| `RN-AUTH-27` | `SameSite=Lax` y no `Strict`: con `Strict`, volver al sistema desde un enlace de correo no envía la cookie y el usuario aparece deslogueado sin motivo aparente. `Lax` sigue bloqueando el `POST` entre sitios, que es lo que importa, y el token CSRF es la defensa primaria, no el atributo de la cookie. |
| `RN-AUTH-28` | **Prohibido cualquier JWT o token de sesión en `localStorage`/`sessionStorage`** (`ADR-025`). Test de arquitectura en el frontend. |
| `RN-AUTH-29` | Toda escritura de `/api/v1` exige token CSRF válido, **incluidos los endpoints anónimos** de este módulo. Un login sin CSRF permite el «login CSRF»: forzar al navegador de la víctima a iniciar sesión con la cuenta del atacante. |
| `RN-AUTH-30` | El timeout de inactividad es `tenant_settings.session_timeout_minutes`, por defecto **30**, rango **5-480**. `SESSION_LIFETIME` global ≥ 480, verificado por guarda de arranque. |
| `RN-AUTH-31` | La sesión guarda el `tenant_id` con el que se autenticó y se **reverifica** contra el tenant resuelto por host en cada petición. Discrepancia ⇒ sesión invalidada, `401` y registro de auditoría (`ADR-033 §2`). |
| `RN-AUTH-32` | El identificador de sesión se regenera en el login y se invalida en el logout, junto con el token CSRF. |
| `RN-AUTH-33` | El *middleware* de idioma corre **después** de arrancar la sesión, para que el paso 1 del orden de `ADR-038 §11` (idioma del usuario autenticado) funcione de verdad (§1.4, punto 4). |
| **Transversales** | |
| `RN-AUTH-34` | Todo correo de este módulo se **encola** (`INV-012`) y existe en los cuatro idiomas de `ADR-021` (`INV-009`). |
| `RN-AUTH-35` | `REQ-AUTH` **no es desactivable**, igual que `REQ-CORE`: sin autenticación no hay plataforma que desactivar. |
| `RN-AUTH-36` | El cambio de contraseña auto-servicio exige la **contraseña actual**, revoca todas las sesiones del usuario **salvo la que ejecuta el cambio**, y sus fallos de contraseña actual cuentan hacia el bloqueo de `(tenant_id, email)` igual que los de login (§4.8). |
| `RN-AUTH-37` | La política de contraseñas que muestran las pantallas es **ayuda visual**. La validación que decide es la del servidor (`INV-010`), y la pantalla nunca acepta lo que el servidor rechazaría ni rechaza lo que el servidor aceptaría: el catálogo de reglas lo sirve el servidor, no se duplica en el cliente. |

---

## 6. Cookie de sesión y aislamiento entre subdominios (issue [#8](https://github.com/pirexia/plataforma-educativa/issues/8))

El issue pregunta si con subdominios por tenant (`ADR-014`) el comportamiento *host-only* por defecto es correcto o si hay que fijar el dominio de la cookie explícitamente. **Es una cuestión de aislamiento de tenant, y la respuesta tiene dirección.**

### 6.1 La respuesta

**Host-only es la única opción correcta, y hay que reforzarla activamente en vez de heredarla.**

Fijar `SESSION_DOMAIN=.dominio` haría que el navegador enviara la cookie de `centroa.dominio` también a `centrob.dominio`. Eso no es un matiz de configuración: es material de sesión de un tenant viajando al host de otro. Rompe `RMT-009` (*«nunca sesión simultánea con datos mezclados»*), deja la reverificación de tenant de `ADR-033 §2` como **única** barrera —cuando su papel es ser la segunda— y convierte cualquier XSS en un subdominio de tenant en robo de sesión de todos los demás. `ADR-033 §2` ya lo decidió; lo que falta no es la decisión, es la guarda.

No hay ninguna necesidad que empuje en la dirección contraria: `ADR-028` sirve la SPA y la API **bajo el mismo host**, así que no existe un `api.dominio` separado que necesitara compartir cookie.

### 6.2 Qué implementa 1.2

1. **Guarda de arranque**: el `ServiceProvider` del módulo aborta si `config('session.domain')` no está vacío, **en todos los entornos**, no solo en producción. Un entorno de desarrollo con cookie de dominio compartido está probando un modelo de seguridad distinto del que se despliega, que es la peor forma de no enterarse. Mismo patrón que la guarda de `core.documents.validate_check_digit` de 1.1, con su test (`CA-AUTH-001`).
2. **Los atributos dejan de heredarse del valor por defecto** y se fijan explícitamente en `config/session.php` con comentario que remite a este documento: `http_only` siempre `true`; `same_site` `'lax'` (`RN-AUTH-27`); `partitioned` `false`; `secure` `true` con la única excepción del desarrollo local sobre HTTP, y con guarda que lo fuerza a `true` en producción.
3. **Test de aislamiento entre subdominios** (`CA-AUTH-002`): autenticado en `tenanta.{base}`, la misma cookie presentada a `tenantb.{base}` **no** autentica. Es el caso de uso HTTP con autenticación de la batería de `ADR-033 §10`, cuyo test número 1 quedó diferido en 0.7 *«a que exista el primer endpoint de negocio con REQ-AUTH»*. Este es ese momento: 1.2 lo cierra.
4. **Reverificación de tenant en la sesión** (`RN-AUTH-31`), que 0.7 dejó anotada en el propio comentario de `ResolveTenant`.
5. `SYSADMIN.md` y la plantilla de `EnvironmentFile` de `ADR-037` documentan que `SESSION_DOMAIN` **no se fija nunca**, con el motivo.

Los puntos 1 y 3 son lo que convierte la afirmación del ADR en una propiedad verificada. Cierra #8.

---

## 7. Recuperación de contraseña consciente de tenant (issue [#18](https://github.com/pirexia/plataforma-educativa/issues/18))

### 7.1 El problema, tal como está hoy

La migración de 0.8 corrigió el **esquema**: `password_reset_tokens` tiene `tenant_id`, clave primaria `(tenant_id, email)`, RLS `ENABLE`+`FORCE` y política de aislamiento. Lo que falta es la **capa de aplicación**: `config/auth.php` sigue apuntando al broker estándar de Laravel, cuyo `DatabaseTokenRepository` busca con `WHERE email = ?` **sin ningún predicado de tenant**. El aislamiento depende hoy al cien por cien de que la conexión sea la sujeta a RLS y de que el contexto de tenant esté fijado, lo cual es cierto en una petición HTTP y **no está garantizado** en un comando, un job o un test que reutilice el broker. Contradice la regla del skill `aislamiento-tenant`: RLS es la **segunda** barrera, no la única.

### 7.2 La resolución de 1.2

**No se usa el `PasswordBroker` de Laravel. Se implementa el repositorio propio, y se prohíbe el broker por test de arquitectura.**

El issue proponía «extender o sustituir `DatabaseTokenRepository` y registrar el binding para el broker `users`». Se elige una variante más fuerte, y conviene decir por qué: el contrato del broker de Laravel es **email-céntrico** (`sendResetLink(credentials)`, `reset(credentials, callback)`), lo que obliga a que el formulario de restablecimiento reenvíe el correo junto al token, y eso empuja a poner el correo en el enlace —dato personal en la URL, en el historial y en los logs del proxy—. Nuestro flujo busca **solo por token** (§4.5), así que doblar el broker para que encaje es más trabajo y más superficie que escribir el repositorio.

Concretamente, 1.2 entrega:

1. **`PasswordResetTokenRepository`**, interfaz propia del módulo con tres operaciones: `issueFor(User): string` (devuelve el token en claro, persiste el hash), `findValid(string $token): ?PasswordResetToken`, `consume(PasswordResetToken): void`.
2. **Implementación** que en **cada** consulta —creación, búsqueda y borrado— lleva `where('tenant_id', $this->context->tenantId())` explícito. `tenantId()` **lanza excepción** si no hay contexto (`ADR-033 §3`), de modo que un uso desde un job o un comando sin tenant revienta en vez de leer la tabla entera.
3. **Conexión fijada a `pgsql`** (rol `plataforma_app`, sujeto a RLS). El repositorio **nunca** usa `pgsql_platform`, que tiene `BYPASSRLS`.
4. **Test de arquitectura** que falla si aparece en `apps/api/app` cualquier referencia a `Illuminate\Support\Facades\Password`, `PasswordBroker` o `DatabaseTokenRepository`. Es la parte que impide que el hallazgo vuelva dentro de seis meses por la puerta de otro módulo.
5. **Limpieza de `config/auth.php`**: se retira la sección `passwords.users`, que a partir de aquí no la usa nadie y solo sirve para que alguien crea que el broker está soportado.
6. **Test de regresión funcional a través del flujo HTTP real** (no `DB::table()`), que es lo que `ADR-034 §8` pide para este paso: `CA-AUTH-033`, un token emitido en el tenant A presentado en el host del tenant B para la cuenta homónima ⇒ `410`, y la contraseña de ninguna de las dos cuentas cambia.

### 7.3 Cambio de esquema que acompaña

La búsqueda por token exige una columna consultable por valor, y la que hay (`token`) guarda el hash **bcrypt** de Laravel, que por diseño no se puede buscar por igualdad. Se añade `token_hash` (SHA-256) con índice único `(tenant_id, token_hash)`, y `expires_at` explícito en vez de derivarlo de `created_at` y de una constante de configuración. Detalle y plan expand/contract en `datos.md` §A.3.

---

## 8. Interacción con otros módulos

`INV-007`: nada de importar código interno. Solo interfaces públicas y eventos.

### 8.1 Interfaces que `REQ-AUTH` consume

| Interfaz | De | Para qué |
|----------|----|----------|
| `TenantSettingsReader` | `REQ-CORE` | Idioma por defecto e idiomas activos del centro. **Se amplía** con `sessionTimeoutMinutes()`. |
| `UserDirectory` | `REQ-CORE` | Resolver el usuario por correo dentro del tenant y consultar su idioma preferido para los correos. **Se amplía** con `findActiveByEmail(string $email): ?User`. |
| `InvitationRedeemer` | `REQ-CORE` (**nueva**) | Validar y marcar como aceptada una invitación por su token, sin que `REQ-AUTH` toque el modelo `UserInvitation` ni conozca el formato del hash. Es la pieza que evita que 1.2 duplique el contrato del token de 1.1. |

### 8.2 Eventos que `REQ-AUTH` consume

| Evento | De | Qué hace `REQ-AUTH` |
|--------|----|---------------------|
| `UserDeactivated` | `REQ-CORE` | **Revoca todas las sesiones activas** del usuario. 1.1 ya lo declaró con este consumidor previsto. Un usuario dado de baja con sesión abierta seguiría dentro hasta que expirara por inactividad. |
| `UserEmailChanged` | `REQ-CORE` | Invalida los tokens de restablecimiento vivos del correo **anterior** y levanta cualquier bloqueo vivo asociado a él. Es el equivalente exacto de `RN-CORE-11` para invitaciones, aplicado a los artefactos de este módulo. |

### 8.3 Eventos que `REQ-AUTH` publica

| Evento | Cuándo | Consumidor previsto |
|--------|--------|---------------------|
| `UserLoggedIn` | Login correcto | 1.2b (detección de nuevo dispositivo), `REQ-BI` |
| `UserLoggedOut` | Logout explícito o expiración por inactividad | 1.2b |
| `LoginFailed` | Intento fallido | `RSEC-OWASP-009` (alertas de anomalía), 1.6 |
| `AccountLocked` | Quinto fallo consecutivo | `REQ-COM` (1.19), que sustituirá el envío directo de correo de 1.2 |
| `AccountUnlocked` | Desbloqueo por correo o por administrador | — |
| `PasswordChanged` | Canje o restablecimiento | `REQ-COM` (aviso al titular), 1.3 |
| `InvitationRedeemed` | Canje correcto | `REQ-CORE`, `REQ-COM` |

### 8.4 Interfaces que `REQ-AUTH` expone

| Interfaz | Para qué |
|----------|----------|
| `PasswordPolicy` | La política de `RN-AUTH-01`/`RN-AUTH-02` como una sola regla reutilizable. La necesitarán 1.3 (recuperación con códigos de respaldo), 1.6 (backoffice) y `REQ-SEED` (1.15b) para generar contraseñas sintéticas que la cumplan. |
| `SessionRevoker` | Revocar todas las sesiones de un usuario. La consume `REQ-CORE` a través del evento `UserDeactivated`, y la consumirá 1.2b para el cierre remoto. |
| `AccountLockService` | Consultar y levantar bloqueos. La consumirá 1.6 (soporte de plataforma). |

---

## 9. Puntos de extensión para 1.3, 1.4 y 1.2b

Se escriben aquí para que los pasos siguientes **no tengan que rehacer** el flujo de login, no para implementar nada de ellos ahora.

- **MFA (1.3)**: `POST /auth/session` es el **único** camino que crea sesión (`RN-AUTH-21`). 1.3 lo parte en dos: verificada la credencial, si el usuario tiene MFA exigible, en vez de establecer la sesión se devuelve un estado intermedio y se exige un segundo paso. Ningún otro flujo de 1.2 —canje, restablecimiento, desbloqueo— establece sesión, así que ninguno hay que revisarlo cuando llegue el MFA. Ese es el motivo real de `RN-AUTH-21`, y no la comodidad.
- **Google y SSO (1.4/1.4b)**: 1.2 **no** crea `identity_providers` ni columna alguna de proveedor externo. La fusión de cuentas de `REQ-AUTH-002` opera sobre `users.email` y `users.email_verified_at`, que ya existen y que el canje de 1.2 rellena correctamente — lo cual importa, porque la nota de seguridad de `REQ-AUTH-002` condiciona la fusión automática a que el correo esté verificado.
- **Panel de sesiones (1.2b)**: añadirá `tenant_id` y metadatos de dispositivo a `sessions` (§1.5) y consumirá `SessionRevoker` y los eventos `UserLoggedIn`/`UserLoggedOut` de §8.3.

---

## 10. Auditoría de los eventos de autenticación

`INV-003` exige registro de login, logout, cambio de contraseña, bloqueo, desbloqueo y canje. La mitad sale gratis del mecanismo de 0.9 y la otra mitad **no cabe en el vocabulario actual**. Conviene ver la separación exacta antes de decidir nada.

### 10.1 Lo que ya cubre el *observer* de 0.9, sin tocar nada

| Hecho | Cómo queda registrado |
|-------|------------------------|
| Contraseña fijada o cambiada | `updated` sobre `User`, con `{"password": {"redacted": "secret"}}` — el patrón global `*password*` lo redacta desde 0.9 |
| Activación de la cuenta | `updated` sobre `User` con `status: pendiente → activo` y `email_verified_at` |
| Invitación canjeada | `updated` sobre `UserInvitation` con `accepted_at` |
| **Cuenta bloqueada** | `created` sobre `AccountLockout` |
| **Cuenta desbloqueada** | `updated` sobre `AccountLockout` con `unlocked_at` y `unlocked_by` |

Que el bloqueo y el desbloqueo sean creación y modificación de una entidad real —y no eventos sueltos— es precisamente lo que hace que encajen sin ampliar nada. Es un argumento a favor del modelo de datos de §5.2 de `datos.md`, no una coincidencia.

### 10.2 Lo que no cabe: `login`, `logout` y `password_reset_requested`

`audit_logs.event` tiene un `CHECK` cerrado por `ADR-034 §3`: `created`, `updated`, `deleted`, `restored`, `read`, `exported`. Un inicio de sesión no es ninguna de las seis, y forzarlo (registrarlo como `read` sobre `User`, por ejemplo) sería mentir en el registro que existe precisamente para no mentir.

Ampliar ese `CHECK` es **aditivo y compatible**, pero es una decisión sobre el registro de auditoría de los 53 módulos, tomada dentro de la especificación de uno. `CLAUDE.md §6.3` y el precedente de `OPEN-CORE-09`/`ADR-038` dicen que eso es un **ADR**, no una línea de este documento. Queda como `OPEN-AUTH-02`, con la propuesta concreta redactada para que el ADR sea corto.

**Los intentos fallidos van a `login_attempts`, no a `audit_logs`, y esto no depende de esa decisión.** Dos motivos independientes: (a) `audit_logs.auditable_id` es `NOT NULL`, y un intento fallido con un correo inexistente no tiene entidad a la que apuntar; (b) el volumen de un ataque de fuerza bruta inundaría la tabla que `REQ-CORE-005` obliga a conservar dos años. La telemetría de autenticación es un registro con su propia retención (90 días) y su propio propósito.

---

## 11. Comportamiento con el módulo desactivado

**`REQ-AUTH` no es desactivable** (`RN-AUTH-35`), por el mismo motivo que `REQ-CORE`: sin login no hay nadie dentro del sistema a quien ocultarle nada. Se registra en el catálogo `modules` con `code = 'auth'` y `EnsureModuleEnabled` lo trata como permanentemente habilitado.

Consecuencia que hay que respetar al implementar: **ninguna ruta de este módulo lleva el *middleware* `module-enabled`**. Ponérselo crearía la posibilidad de que una fila de `module_subscriptions` mal puesta dejara a un centro entero sin poder entrar, sin forma de entrar a arreglarlo.

---

## 12. Criterios de aceptación

Verificables, cada uno con test que referencia su ID (`INV-015`).

### Cookie y sesión (`ADR-025`, issue #8)

- **`CA-AUTH-001`** · *Dado* `SESSION_DOMAIN` con cualquier valor no vacío, *cuando* arranca la aplicación, *entonces* falla con un mensaje que remite a `funcional.md §6`, en todos los entornos (`RN-AUTH-26`).
- **`CA-AUTH-002`** · *Dado* un usuario autenticado en `tenanta.{base}`, *cuando* se presenta **la misma cookie de sesión** a `tenantb.{base}`, *entonces* la petición responde `401` y en ningún caso devuelve datos del tenant B (`INV-001`, `RMT-009`, batería de `ADR-033 §10` nº 1).
- **`CA-AUTH-003`** · *Dado* un login correcto, *cuando* se inspecciona la cookie emitida, *entonces* tiene `HttpOnly`, `SameSite=Lax`, **no** tiene atributo `Domain`, y tiene `Secure` cuando la configuración de producción está activa (`RN-AUTH-26`, `RN-AUTH-27`).
- **`CA-AUTH-004`** · *Dado* un login correcto, *cuando* se compara el identificador de sesión antes y después, *entonces* son distintos (`RN-AUTH-32`, fijación de sesión).
- **`CA-AUTH-005`** · *Dado* cualquier escritura de `/api/v1` sin token CSRF válido, *cuando* se ejecuta, *entonces* `419`/`403` y no se modifica nada — incluidos `POST /auth/session` y `POST /auth/password-resets` (`RN-AUTH-29`).
- **`CA-AUTH-006`** · *Dado* el código del frontend, *cuando* se analiza, *entonces* no existe ninguna escritura de credencial ni de token de sesión en `localStorage` o `sessionStorage` (`ADR-025`, `RN-AUTH-28`).
- **`CA-AUTH-007`** · *Dado* un usuario autenticado en el tenant A, *cuando* su *payload* de sesión se altera para apuntar a otro `tenant_id` y se repite la petición, *entonces* la sesión se invalida, responde `401` y queda registro de auditoría (`RN-AUTH-31`, `ADR-033 §2`).

### Login (`REQ-AUTH-001`)

- **`CA-AUTH-010`** · *Dado* un usuario `activo` con contraseña correcta, *cuando* hace `POST /auth/session`, *entonces* `200` con el mismo recurso que `GET /me`, cookie de sesión establecida, y **sin** que la respuesta contenga el hash de la contraseña ni ningún token.
- **`CA-AUTH-011`** · *Dado* una contraseña incorrecta, un correo inexistente, un usuario `pendiente` y un usuario `inactivo`, *cuando* se intenta iniciar sesión con los cuatro, *entonces* las cuatro respuestas son `401` con **cuerpo idéntico** (`type`, `title`, `detail` y `errors` iguales salvo `request_id`) (`RN-AUTH-23`, §4.7).
- **`CA-AUTH-012`** · *Dado* el mismo correo en dos tenants con contraseñas distintas, *cuando* se usa la contraseña del tenant A en el host del tenant B, *entonces* `401` y el intento se registra en el tenant B, no en el A (`RN-AUTH-08`, `INV-001`).
- **`CA-AUTH-013`** · *Dado* un login correcto sobre un hash con coste inferior al configurado, *cuando* se completa, *entonces* el hash almacenado queda reamasado al coste vigente y la contraseña sigue siendo válida (`RN-AUTH-03`).
- **`CA-AUTH-014`** · *Dado* cualquier intento de login, *cuando* termina, *entonces* existe exactamente una fila en `login_attempts` de ese tenant con el resultado correspondiente, y **sin** la contraseña ni fragmento suyo (`RN-AUTH-05`).
- **`CA-AUTH-015`** · *Dado* un usuario `inactivo` con la contraseña **correcta**, *cuando* intenta iniciar sesión cinco veces, *entonces* la cuenta **no** queda bloqueada (`RN-AUTH-24`).
- **`CA-AUTH-016`** · *Dado* una sesión válida, *cuando* hace `DELETE /auth/session`, *entonces* `204`, la fila de `sessions` desaparece y una petición posterior con la misma cookie responde `401`.
- **`CA-AUTH-017`** · *Dado* ninguna sesión, *cuando* se llama a `DELETE /auth/session`, *entonces* `204` y no `401` (§4.3, idempotencia).

### Política de contraseñas (`REQ-AUTH-001`)

- **`CA-AUTH-020`** · *Dado* cada una de estas contraseñas —11 caracteres válidos por lo demás; 12 sin mayúscula; 12 sin minúscula; 12 sin dígito; 12 sin símbolo—, *cuando* se envían al canje **y** al restablecimiento, *entonces* las diez llamadas responden `422` con el `code` de la regla incumplida y no se fija ninguna contraseña (`RN-AUTH-01`, `INV-010`).
- **`CA-AUTH-021`** · *Dado* una contraseña de 73 bytes, *cuando* se envía, *entonces* `422` y no se almacena — nunca se acepta truncándola (`RN-AUTH-02`).
- **`CA-AUTH-022`** · *Dado* una contraseña válida, *cuando* se almacena, *entonces* la columna `users.password` contiene un hash bcrypt de coste ≥ 12 y no el valor en claro (`RN-AUTH-03`).

### Bloqueo de cuenta (`REQ-AUTH-001`)

- **`CA-AUTH-023`** · *Dado* una cuenta bloqueada y `AUTH_LOCKOUT_MINUTES = 15`, *cuando* pasan 16 minutos sin ninguna intervención, *entonces* el login vuelve a funcionar con la contraseña correcta, y la fila queda con `unlocked_at` y `unlock_reason = 'caducidad'` (`RN-AUTH-14`).
- **`CA-AUTH-024`** · *Dado* una cuenta bloqueada cuyo bloqueo **ya venció pero sigue sin cerrar** (sin que haya corrido `CloseExpiredLockouts`), *cuando* se falla cinco veces más, *entonces* el bloqueo anterior queda cerrado como `caducidad`, se crea uno nuevo y **no** se viola el índice único de `RN-AUTH-17` (`RN-AUTH-38`).
- **`CA-AUTH-025`** · *Dado* un usuario `activo`, *cuando* falla cuatro veces y acierta a la quinta, *entonces* entra, y el contador de fallos consecutivos queda a cero (`RN-AUTH-14`).
- **`CA-AUTH-026`** · *Dado* cinco fallos consecutivos, *entonces* existe un `account_lockouts` vivo, el sexto intento responde `423` **sin** verificar la contraseña, y una contraseña correcta también responde `423` (`RN-AUTH-16`).
- **`CA-AUTH-027`** · *Dado* cinco fallos consecutivos sobre un correo **inexistente**, *entonces* la respuesta al sexto intento es **byte a byte la misma** que la de una cuenta real bloqueada (`RN-AUTH-15`, anti-enumeración).
- **`CA-AUTH-028`** · *Dado* una cuenta bloqueada existente, *cuando* se crea el bloqueo, *entonces* se **encola** un correo de aviso con enlace de desbloqueo (`INV-012`), y para un correo inexistente **no se encola ninguno**.
- **`CA-AUTH-029`** · *Dado* el token de desbloqueo **dentro** de la ventana de caducidad del bloqueo, *cuando* se envía a `POST /auth/account-unlocks`, *entonces* `204`, el bloqueo queda con `unlocked_at` y `unlock_reason = 'correo'`, el login vuelve a funcionar antes de que expirara solo, y **un segundo uso del mismo token responde `410`** (`RN-AUTH-13`).
- **`CA-AUTH-030`** · *Dado* un Administrador de Centro, *cuando* hace `DELETE /account-lockouts/{public_id}`, *entonces* `204`, la fila queda con `unlocked_at`, `unlock_reason = 'administrador'` y `unlocked_by` informado, y existe registro de auditoría (`INV-003`).
- **`CA-AUTH-031`** · *Dado* un usuario **sin** `bloqueo_cuenta.eliminar`, *cuando* intenta desbloquear, *entonces* `403`; *dado* un bloqueo de **otro tenant**, *entonces* `404` (`INV-002`, `ADR-038 §6.4`).

### Recuperación de contraseña (`REQ-AUTH-001`, issue #18)

- **`CA-AUTH-032`** · *Dado* un correo existente y uno inexistente, *cuando* se solicita la recuperación de ambos, *entonces* las dos respuestas son `202` con cuerpo idéntico, y solo la primera encola correo (`RN-AUTH-10`, `INV-012`).
- **`CA-AUTH-033`** · *Dado* el mismo correo en dos tenants, *cuando* se emite un token de restablecimiento en el tenant A y se presenta en el host del tenant B, *entonces* `410`, y **la contraseña de ninguna de las dos cuentas cambia**. Verificado **por el flujo HTTP real**, no por consultas directas (issue #18, `ADR-034 §8`, `INV-001`).
- **`CA-AUTH-034`** · *Dado* el código de `apps/api/app`, *cuando* se analiza, *entonces* no existe ninguna referencia a `Illuminate\Support\Facades\Password`, `PasswordBroker` ni `DatabaseTokenRepository` (§7.2, punto 4).
- **`CA-AUTH-035`** · *Dado* un token de restablecimiento consumido con éxito, *cuando* se reutiliza, *entonces* `410` y la fila ya no existe (`RN-AUTH-12`).
- **`CA-AUTH-036`** · *Dado* un token de restablecimiento con más de 60 minutos, *cuando* se usa, *entonces* `410` (`RN-AUTH-12`).
- **`CA-AUTH-037`** · *Dado* un token vivo, *cuando* se solicita otra recuperación para el mismo correo, *entonces* el primero deja de funcionar y solo el segundo es válido (`RN-AUTH-11`).
- **`CA-AUTH-038`** · *Dado* un restablecimiento correcto, *entonces* todas las sesiones activas de ese usuario quedan revocadas, cualquier bloqueo vivo de ese correo queda levantado, y existe registro de auditoría con `password` redactado como `secret` y **sin** su valor (`RN-AUTH-22`, `ADR-035`).
- **`CA-AUTH-039`** · *Dado* un usuario `pendiente` y uno `inactivo`, *cuando* solicitan recuperación, *entonces* `202` en ambos casos y **no se encola ningún correo** (§4.5, punto 4).

### Canje de la invitación (`REQ-AUTH-001`, contrato de `REQ-CORE`)

- **`CA-AUTH-040`** · *Dado* una invitación vigente y una contraseña válida, *cuando* se canjea, *entonces* `204`, el usuario queda `activo`, con `email_verified_at` informado, `accepted_at` informado, y **puede iniciar sesión con esa contraseña** (`RN-AUTH-20`, `REQ-AUTH-001` «validación de email»).
- **`CA-AUTH-041`** · *Dado* una invitación caducada, otra revocada y otra ya aceptada, *cuando* se canjean las tres, *entonces* las tres responden `410` con **cuerpo idéntico** y ninguna modifica nada (§4.7).
- **`CA-AUTH-042`** · *Dado* un token de invitación emitido en el tenant A, *cuando* se presenta en el host del tenant B, *entonces* `410` y no se activa ninguna cuenta (`RN-AUTH-08`, `INV-001`).
- **`CA-AUTH-043`** · *Dado* un canje correcto, *cuando* se inspecciona la base de datos, *entonces* `user_invitations.token_hash` sigue siendo el hash y el token en claro **no aparece** en ninguna tabla, log ni fila de auditoría (`RN-AUTH-09`, `RN-CORE-19`).
- **`CA-AUTH-044`** · *Dado* un canje correcto, *cuando* termina, *entonces* **no** hay sesión establecida: la petición siguiente sin credenciales responde `401` (`RN-AUTH-21`).
- **`CA-AUTH-045`** · *Dado* un usuario con un bloqueo vivo y un token de restablecimiento vivo, *cuando* canjea su invitación, *entonces* ambos quedan neutralizados (`RN-AUTH-20`).

### Expiración por inactividad (`REQ-AUTH-005`, punto 1)

- **`CA-AUTH-050`** · *Dado* un centro con `session_timeout_minutes = 30`, *cuando* pasan 31 minutos sin ninguna petición, *entonces* la siguiente responde `401`, la sesión queda invalidada y existe registro de auditoría.
- **`CA-AUTH-051`** · *Dado* el mismo centro, *cuando* se hacen peticiones cada 20 minutos durante dos horas, *entonces* la sesión sigue viva: la ventana es de **inactividad**, no de duración total.
- **`CA-AUTH-052`** · *Dado* `SESSION_LIFETIME` menor que el máximo configurable por tenant, *cuando* arranca la aplicación, *entonces* falla (`RN-AUTH-30`).
- **`CA-AUTH-053`** · *Dado* un valor fuera del rango 5-480, *cuando* se envía a `PATCH /tenant/settings`, *entonces* `422` y no se guarda.
- **`CA-AUTH-054`** · *Dado* dos centros con timeouts distintos (10 y 120), *cuando* se ejercitan ambos, *entonces* cada sesión expira según **su** centro (`INV-001`: el ajuste es del tenant, no global).

### Cambio de contraseña auto-servicio (§4.8, `OPEN-AUTH-05`)

- **`CA-AUTH-055`** · *Dado* un usuario autenticado, *cuando* envía su contraseña actual correcta y una nueva válida, *entonces* `204`, puede seguir usando **su sesión actual**, y la contraseña nueva sirve para iniciar sesión (`RN-AUTH-36`).
- **`CA-AUTH-056`** · *Dado* ese mismo cambio, *cuando* termina, *entonces* **todas las demás** sesiones de ese usuario quedan revocadas y la actual **no** (es la diferencia deliberada con el restablecimiento de `CA-AUTH-038`).
- **`CA-AUTH-057`** · *Dado* una contraseña actual incorrecta, *cuando* se envía, *entonces* `422` y **no** `401` —la sesión sigue viva—, no se cambia nada, y el fallo cuenta hacia el bloqueo de esa cuenta (`RN-AUTH-36`).
- **`CA-AUTH-058`** · *Dado* una contraseña nueva que incumple la política o que **coincide con la actual**, *cuando* se envía, *entonces* `422` y no se cambia nada.
- **`CA-AUTH-059`** · *Dado* un cambio correcto, *entonces* se encola el correo de aviso (`INV-012`) y existe registro de auditoría con `password` redactado como `secret` (`ADR-035`) — **sin** depender de `ADR-039`.

### Pantallas (§1.6, `OPEN-AUTH-01`)

- **`CA-AUTH-060`** · *Dado* las seis rutas de la SPA de §1.6, *cuando* se recorren en un navegador real, *entonces* cada una completa su flujo contra la API sin error de consola y sin ninguna llamada a un host externo (CSP estricta, `RSEC-OWASP-005`).
- **`CA-AUTH-061`** · *Dado* las cinco pantallas **sin sesión**, *cuando* se cargan sobre el host de un centro con branding configurado, *entonces* muestran su nombre, colores, logo y fondo tomados de `GET /tenant/branding`, y **nada más** de ese endpoint (`RUX-BRAND-002`, `CA-CORE-007`).
- **`CA-AUTH-062`** · *Dado* cualquiera de las seis, *cuando* se audita con la herramienta de accesibilidad, *entonces* no hay incumplimientos de WCAG 2.2 AA, y el formulario es completable **solo con teclado** (`RNF-UX-002`).
- **`CA-AUTH-063`** · *Dado* un navegador con `Accept-Language: de`, *cuando* se carga la pantalla de login de un centro con `de` entre sus idiomas activos, *entonces* se pinta en alemán; con un idioma no activo, se degrada al idioma por defecto del centro y **no** es un error (`ADR-038 §11`, `INV-009`).

### Transversales

- **`CA-AUTH-070`** · *Dado* cualquier endpoint de administración de este módulo, *cuando* se llama sin sesión, *entonces* `401`; sin el permiso, `403`; sobre un recurso de otro tenant, `404` (`INV-002`, `ADR-038 §6.4`).
- **`CA-AUTH-071`** · *Dado* login, logout, canje, restablecimiento, bloqueo y desbloqueo, *cuando* ocurren, *entonces* cada uno deja su registro con actor, IP, `user_agent` y `request_id` (`INV-003`, `INV-013`) — los seis, en la ubicación que fije `OPEN-AUTH-02` para los dos primeros.
- **`CA-AUTH-072`** · *Dado* cualquier respuesta de error de este módulo, *cuando* se inspecciona, *entonces* es `application/problem+json` con `type` `urn:pge:error:*` y `request_id` (`ADR-038 §6`).
- **`CA-AUTH-073`** · *Dado* cualquier mensaje o correo visible de este módulo, *cuando* se revisa, *entonces* existe en `es-ES`, `en`, `de` y `fr` y no hay literales en el código (`INV-009`).
- **`CA-AUTH-074`** · *Dado* los límites de tasa de `operacion.md §6`, *cuando* se superan en login, solicitud de recuperación y canje, *entonces* `429` con `Retry-After`, y la clave del límite **incluye el tenant** (`ADR-033 §9`).
- **`CA-AUTH-075`** · *Dado* un usuario autenticado con `person.locale = 'de'`, *cuando* hace cualquier petición sin `Accept-Language`, *entonces* `Content-Language: de` — es decir, el paso 1 de `ADR-038 §11` funciona de verdad (`RN-AUTH-33`, §1.4 punto 4).
- **`CA-AUTH-076`** · *Dado* un usuario con sesión abierta, *cuando* `REQ-CORE` lo da de baja (`DELETE /users/{id}`), *entonces* su sesión queda revocada y su siguiente petición responde `401` (evento `UserDeactivated`, §8.2).
- **`CA-AUTH-077`** · *Dado* un usuario con token de restablecimiento vivo, *cuando* `REQ-CORE` cambia su correo de acceso, *entonces* el token deja de funcionar (evento `UserEmailChanged`, §8.2).
- **`CA-AUTH-078`** · *Dado* las rutas de este módulo, *cuando* se inspeccionan, *entonces* ninguna lleva el *middleware* `module-enabled` (§11).

---

## 13. Preguntas abiertas

Seis resueltas por el usuario el **2026-08-22**; las cinco restantes quedan con su recomendación escrita y **ninguna bloquea**.

### `OPEN-AUTH-01` · ¿1.2 entrega pantallas o es solo API, como 1.1? — **RESUELTA**

**Decisión: sí, 1.2 entrega pantallas.** Las seis de §1.6 —cinco públicas más la de cambio de contraseña—, austeras, sobre el Tailwind actual y sin esperar al *design system* de 1.7, asumiendo el restyle posterior. Argumento aceptado: son las únicas pantallas del producto que se ven **sin navegación**, así que no adelantan trabajo de 1.8, y sin ellas 1.2 se cerraría sin una sola comprobación manual posible. Detalle, rutas y obligaciones (branding, cuatro idiomas, WCAG 2.2 AA) en §1.6; criterios `CA-AUTH-060` a `063`.

Planteamiento que llevó a la decisión:

`REQ-CORE` decidió que 1.1 fuera solo API y sus pantallas se hicieran en 1.8 (`OPEN-CORE-02`). Con 1.2 el argumento cambia de peso: si 1.2 tampoco entrega pantallas, **el producto sigue sin ser alcanzable por una persona** al cerrar el paso, y el propio 1.1 construyó `GET /tenant/branding` justificándolo como *«para que la pantalla de login de 1.2 pueda pintarse»*.

La recomendación fue entregarlas, y es lo que se aprobó. La lista creció de cinco a seis al aprobarse también `OPEN-AUTH-05`, que trae su propia pantalla.

### `OPEN-AUTH-02` · `login`, `logout` y `password_reset_requested` no caben en `audit_logs.event` — **RESUELTA (ADR en curso)**

Detallada en §10.2. El `CHECK` de `audit_logs.event` es cerrado por `ADR-034 §3` y no admite eventos de autenticación. Ampliarlo es aditivo y compatible, pero afecta al registro de auditoría de los 53 módulos y **una convención transversal decidida dentro de la especificación de un módulo no es un ADR** (`CLAUDE.md §6.3`, precedente exacto de `OPEN-CORE-09` → `ADR-038`).

**Decisión: se amplía el vocabulario, y lo hace `ADR-039`**, en redacción por el subagente `architect` en paralelo a esta especificación. Esta especificación **no** lo duplica: solo lo referencia. El contenido acordado del ADR es ampliar `audit_logs.event` con `login`, `logout` y `password_reset_requested`, bajo tres reglas: (a) el `event` describe el hecho y nunca se fuerza a un verbo CRUD que no le corresponde; (b) los eventos de autenticación exigen `auditable_type = 'user'` y `auditable_id` real, por lo que **nunca** cubren un correo inexistente; (c) `changes` es `NULL` en los tres, igual que en `read`/`exported`.

**Es el único trabajo previo que bloquea implementación**, y solo de esa parte concreta. El resto del módulo —incluidos bloqueo, desbloqueo, cambio de contraseña y activación, que se auditan con el vocabulario actual (§10.1)— no depende de él.

### `OPEN-AUTH-03` · El bloqueo indefinido es un vector de denegación de servicio dirigido — **RESUELTA**

`REQ-AUTH-001` pide bloqueo tras 5 fallos con desbloqueo «por email o por administrador», sin mencionar caducidad. Implementado al pie de la letra (§4.4), **cualquiera que conozca el correo de un profesor puede dejarlo fuera del sistema con cinco intentos**, y su salida depende del correo transaccional (`0.10c`, **pendiente**) o de que haya un administrador disponible. En un centro, a las 8:00 de un lunes, eso es una incidencia operativa real.

**Decisión: sí, caducidad automática.** `AUTH_LOCKOUT_MINUTES`, **15 minutos por defecto**, configurable. Convive con los dos desbloqueos que el requisito sí pide —correo y administrador—, **sin sustituirlos**: siguen existiendo íntegros como atajo para quien no quiera esperar. Detalle en §4.4, esquema en `datos.md §A.2` (columnas `expires_at` y `unlock_reason`), criterios `CA-AUTH-023` y `CA-AUTH-024`.

Quince minutos siguen siendo un freno eficaz: reducen el ritmo máximo de un ataque a 20 intentos por hora y por cuenta.

### `OPEN-AUTH-04` · ¿Endpoint de comprobación previa de un token? — *abierta, no bloquea*

Tal como está especificado, quien abre un enlace de activación caducado escribe una contraseña de 12 caracteres y **solo entonces** descubre que el enlace ya no vale. Un endpoint de comprobación previa (token en el cuerpo, respuesta `{valid, expires_at}` sin ningún dato personal) lo evitaría a cambio de una superficie anónima más que hay que limitar por tasa.

**Recomendación**: **no** añadirlo en 1.2. La molestia es real pero baja, y cada endpoint anónimo nuevo es superficie que hay que defender. Si la pantalla de activación resulta molesta en uso real, se añade entonces con el problema medido delante.

### `OPEN-AUTH-05` · Cambio de contraseña por el propio usuario autenticado — **RESUELTA**

`REQ-AUTH-001` **no lo pide**. Enumera registro, verificación de correo, recuperación, política y bloqueo. Un usuario que quisiera cambiar su contraseña por precaución solo podría hacerlo pasando por «he olvidado mi contraseña» y su correo, lo cual funciona pero es absurdo — y además depende de `0.10c`, pendiente. `RSEC-GDPR-004` («acceso y rectificación desde el propio panel») apunta en la dirección de tenerlo, sin nombrarlo.

**Decisión: entra en 1.2** como `POST /api/v1/auth/password-changes`: contraseña actual más la nueva, revoca las demás sesiones y **conserva la actual**. Es una ampliación deliberada del alcance acordado el 2026-08-22, tomada por el usuario, sobre piezas que este módulo ya construye. Flujo en §4.8, contrato en `api.md §6`, criterios `CA-AUTH-055` a `059`, pantalla `/cuenta/contrasena` en §1.6.

### `OPEN-AUTH-06` · ¿El timeout de inactividad es del centro o de la plataforma? — **RESUELTA**

`REQ-AUTH-005` dice «expiración configurable» sin decir **quién** configura. Esta especificación asume **por tenant**, en `tenant_settings`, editable por el Administrador de Centro con el permiso `configuracion.actualizar` que ya existe.

**Decisión: por tenant, con rango 5-480 confirmado.** En `tenant_settings.session_timeout_minutes`, editable por el Administrador de Centro con el permiso `configuracion.actualizar` que ya existe. Coherente con que `tenant_settings` sea el sitio donde el centro configura su propio comportamiento y con que `REQ-CORE/funcional.md §1.4` dejara escrita esa columna para este paso. Por debajo de 5 minutos el sistema es inusable; por encima de 8 horas la expiración por inactividad deja de proteger nada.

### `OPEN-AUTH-07` · `0.10c` (correo transaccional) es bloqueante **operativo** de este módulo

Ya estaba abierta como `OPEN-CORE-04`, pero aquí cambia de categoría. En 1.1 el correo solo afectaba a la invitación, y un administrador podía reemitirla. En 1.2, el correo es **el único** canal de recuperación de contraseña y de desbloqueo: sin proveedor, un usuario que olvide la contraseña o se bloquee queda fuera hasta que un administrador intervenga a mano.

**No bloquea implementar ni probar 1.2** (los tests comprueban el encolado). **Bloquea declararlo operable** en el piloto.

### `OPEN-AUTH-08` · Divergencia con `REQ-CORE/api.md §4` sobre la forma del canje — **RESUELTA**

`REQ-CORE/api.md §4` anticipó `POST /invitations/{token}/accept`, con el token en la ruta. Esta especificación usa `POST /api/v1/auth/invitation-redemptions` con el token en el cuerpo, por el motivo de §4.7 (un token en la URL acaba en logs de proxy, historial y `Referer`). El **contrato vinculante** que 1.1 fijó —`funcional.md §4.3`: formato del token, hash SHA-256, búsqueda por `(tenant_id, hash)`, condiciones de validez, enlace `/activar/{token}` como ruta de la SPA— se cumple íntegro.

**Decisión: corregida.** `REQ-CORE/api.md §4` ya refleja `POST /api/v1/auth/invitation-redemptions` con el token en el cuerpo (2026-08-22). Cambio de documentación, no de código de 1.1 (ese endpoint nunca se implementó allí).

### `OPEN-AUTH-12` · `actor_type` de una solicitud anónima en `password_reset_requested` — **RESUELTA**

Detectado por `architect` al redactar `ADR-039`. `password_reset_requested` lo origina una petición **sin sesión**: `AuditActor::resolveType()` devuelve `'system'` cuando no hay usuario autenticado ni contexto de consola, y `actor_user_id` queda `NULL`. La fila diría que **el sistema** pidió el restablecimiento, indistinguible de un job programado — el mismo defecto que la regla (a) de `ADR-039` prohíbe para `event`, aplicado a la columna de al lado, y no corregible después porque `audit_logs` es *append-only*. Atribuirlo al usuario destinatario del correo sería peor: si un tercero introduce el correo de un profesor, el registro acusaría al profesor de algo que no hizo.

**Decisión: se añade `'anonymous'` al `CHECK` de `audit_logs.actor_type`**, en la misma migración de `ADR-039` (una línea más, igual de aditiva). `password_reset_requested` se audita con `actor_type = 'anonymous'`, `actor_user_id = NULL`, y el `auditable_type`/`auditable_id` siguen apuntando al usuario real cuyo email se usó (regla (b) de `ADR-039`, sin cambios). Aprobado por el usuario el 2026-08-22. `login` y `logout` no están afectados (ambos exigen sesión ya establecida). Actualiza `ADR-039` para reflejarlo — la migración pasa de dos `CHECK` ampliados (`event`) a dos (`event` + `actor_type`).

### `OPEN-AUTH-09` · «Recordarme»

`users.remember_token` existe en el esquema desde 0.8 porque venía del starter kit. Ningún requisito pide sesión persistente, y una cookie de «recordarme» con vida de semanas **contradice de frente** el timeout de inactividad de 30 minutos de `REQ-AUTH-005`.

**Recomendación**: **no implementarlo**, y confirmar que la columna se queda como está sin usarse (retirarla sería una migración destructiva por un beneficio nulo). Si en uso real el timeout de 30 minutos resulta insoportable, la respuesta correcta es que el centro suba `session_timeout_minutes`, no una cookie persistente.

### `OPEN-AUTH-10` · `sessions` sin `tenant_id` ni RLS hasta 1.2b

Detallada en §1.5. La decisión de 1.2 es no añadir la columna, con tres barreras que la compensan. La consecuencia honesta: hasta 1.2b, una inyección SQL en cualquier punto del sistema alcanzaría los *payloads* de sesión de todos los tenants.

**Recomendación**: mantener la decisión (añadir una columna que nadie escribe es lo que `ADR-034 OPEN-13` desaconseja) y **abrir issue de severidad Media** apuntando a 1.2b, para que la columna, la RLS y el traslado de `sessions` fuera de `shared_tables.framework` lleguen juntos con el panel que los usa.

### `OPEN-AUTH-11` · `0.10b` impide verificar de verdad el aislamiento de la cookie

El test `CA-AUTH-002` (cookie del tenant A rechazada en el host del tenant B) se puede ejecutar en WSL2 con dos hosts falsos sobre HTTP, y eso cubre el atributo `Domain`. Lo que **no** se puede verificar sin `0.10b` es el comportamiento con TLS real, certificado comodín y `Secure`, que es como se despliega.

**Recomendación**: ejecutar el test en CI tal como se pueda hoy, y **anotar en `SYSADMIN.md` §6** —junto a lo que 0.9b ya dejó como «escrito y no probado»— que la verificación con TLS real queda pendiente de `0.10e` (staging). No declarar verificado lo que no se ha ejecutado (`CLAUDE.md §0`).

---

## 14. ¿Se aprueba esta especificación?

**Aprobada el 2026-08-22.** Las siete decisiones bloqueantes/de alcance quedaron resueltas:

1. **`OPEN-AUTH-01`** — pantallas: **sí**, seis (las cinco propuestas más la de cambio de contraseña de `OPEN-AUTH-05`).
2. **`OPEN-AUTH-02`** — `ADR-039` escrito y aprobado, amplía `audit_logs.event` con `login`/`logout`/`password_reset_requested`.
3. **`OPEN-AUTH-03`** — caducidad automática del bloqueo: **sí**, `AUTH_LOCKOUT_MINUTES` (15 por defecto), sin sustituir los dos desbloqueos que ya pedía el requisito.
4. **`OPEN-AUTH-05`** — cambio de contraseña auto-servicio: **sí**, entra en 1.2.
5. **`OPEN-AUTH-06`** — timeout por tenant, rango 5-480: confirmado.
6. **`OPEN-AUTH-08`** — corrección documental de `REQ-CORE/api.md §4`: aplicada.
7. **`OPEN-AUTH-12`** (detectada por `architect` al redactar `ADR-039`, no estaba en la lista original) — `actor_type = 'anonymous'` para `password_reset_requested`: **sí**, añadido al `CHECK` en la misma migración de `ADR-039`.

Confirmada también la resolución de **#18** (§7: repositorio propio, `PasswordBroker` de Laravel prohibido por test de arquitectura) y de **#8** (§6: guarda de arranque en todos los entornos contra `SESSION_DOMAIN`).

Las demás (`OPEN-AUTH-04`, `-07`, `-09`, `-10`, `-11`) llevan recomendación, no bloquean, y siguen abiertas tal como se documentaron — no se han reabierto ni resuelto en esta aprobación.

**Siguiente paso**: implementación (rama `feature/REQ-1.2-auth-local-sesiones`), incluida la actualización de `ADR-039` con el `CHECK` de `actor_type`.

---
---

# Parte B · Paso 1.2b · Panel de sesiones, cierre remoto y detección de dispositivo

| Campo | Valor |
|-------|-------|
| Paso | **1.2b** · Fase 1 · Bloque A |
| Requisito | `REQ-AUTH-005`, **puntos 2, 3 y 4** (el punto 1 se cerró en 1.2, §4.6) |
| Origen | Issue [#59](https://github.com/pirexia/plataforma-educativa/issues/59), diferimiento acordado con el usuario el 2026-08-22 |
| Depende de | **1.2** (cerrado 2026-08-25, PR [#76](https://github.com/pirexia/plataforma-educativa/pull/76)) |
| Estado | **PROPUESTA · pendiente de aprobación** (`§B.14`) |
| Módulo | `auth` — **ampliación**, no módulo nuevo. Mismo *bounded context*, mismo `AuthServiceProvider`, mismas rutas |

> Los tres sub-requisitos, literales: *«Cierre de sesión en todos los dispositivos» · «Visualización de sesiones activas con posibilidad de revocarlas» · «Detección de login desde nuevo dispositivo/ubicación con alerta al usuario»*.

---

## B.0 Antes de nada: dependencias no implementadas y decisiones que no son mías

`CLAUDE.md §0` obliga a decirlo antes de continuar, no al final.

| Dependencia | Estado | Qué bloquea exactamente |
|-------------|--------|-------------------------|
| **1.2** | **Cerrado** | Ninguna. Es la única dependencia dura, y está satisfecha: `sessions` con driver `database`, `SessionRevoker`, `AuditRecorder` con vocabulario `ADR-039`, cola `auth-mail` y las tres plantillas de correo ya existen |
| **`0.10c` · Proveedor de correo transaccional** (`OPEN-09`, `OPEN-AUTH-07`) | **Pendiente** | La alerta del punto 4 es un correo. **No bloquea implementar ni probar** (los tests comprueban que el trabajo se encola, misma convención que 1.1 y 1.2). **Sí bloquea declarar operable el punto 4**: sin entrega de correo, la detección funciona y nadie se entera, que es exactamente igual de inútil que no detectar nada. Es una degradación menos grave que la de 1.2 —aquí no deja a nadie fuera del sistema— pero anula el valor del sub-requisito |
| **Fuente de geolocalización por IP** | **No existe decisión en el proyecto** | Bloquea la mitad **«ubicación»** del punto 4. `OPEN-AUTH-13`. No la resuelvo yo y no invento proveedor (`CLAUDE.md §11`). Consecuencia honesta en `§B.7`: **1.2b entrega el punto 4 solo en su mitad «nuevo dispositivo»**, y eso hay que escribirlo en el cierre del paso, no declararlo completo |
| **Mecanismo de auditoría de 0.9** | Existe, pero no sabe excluir el evento `created` de un modelo concreto | `OPEN-AUTH-16`. No bloquea: hay un camino por defecto que cumple `INV-003` sin tocar nada (`§B.10`) |
| **`1.5` · Permisos granulares** | Posterior | **No afecta**: 1.2b no declara ningún permiso nuevo (`permisos.md §B.1`). Es la consecuencia de que todo lo que entra sea autoservicio |
| **`1.3` · MFA**, **`1.6` · Backoffice**, **`REQ-COM` (1.19)** | Posteriores | Fuera de alcance. `§B.9` fija los puntos de extensión para que ninguno tenga que rehacer lo de aquí |

**Ninguna impide redactar ni implementar 1.2b.** Dos impiden cerrarlo como completo: `0.10c` (operabilidad de la alerta) y `OPEN-AUTH-13` (mitad «ubicación» del punto 4).

---

## B.1 Alcance del paso 1.2b

### B.1.1 Entra

| Sub-requisito | Qué parte |
|---------------|-----------|
| `REQ-AUTH-005` punto 3 | **Listado de las sesiones activas del propio usuario**, con dispositivo, IP, momento de inicio y última actividad, y marca de cuál es la sesión desde la que se consulta. **Revocación individual** de cualquiera de ellas, incluida la actual |
| `REQ-AUTH-005` punto 2 | **Cierre de sesión en todos los dispositivos**: en un solo endpoint, con dos ámbitos — «todos los demás» (el que usa el panel) y «todos, incluida esta» (el que pide el requisito literalmente) |
| `REQ-AUTH-005` punto 4 | **Detección de acceso desde un dispositivo no reconocido**, con **alerta por correo** al titular. Criterio de «nuevo» en `§B.6`. **La mitad «ubicación» no entra** (`§B.7`, `OPEN-AUTH-13`) |
| **Modelo de datos propio** | Dos tablas de tenant nuevas, `user_sessions` y `user_known_devices` (`datos.md §B.1`, `§B.2`). **La tabla `sessions` del framework no se toca** (`§B.2.2`) |
| **Cookie de dispositivo** | `pge_device`: opaca, host-only, `httpOnly`, sin ningún dato personal dentro (`§B.6.2`, `RN-AUTH-45`) |
| **Una pantalla más** | `/cuenta/sesiones`, con sesión, sin navegación — misma categoría que `/cuenta/contrasena` de 1.2 (`§B.11`) |
| **Una guarda de arranque** | `SESSION_DRIVER` distinto de `database` aborta el arranque (`RN-AUTH-49`). Hoy la revocación **degrada en silencio** a no hacer nada con cualquier otro driver, y a partir de 1.2b eso deja de ser un detalle interno para ser una función que el usuario ve y cree que funciona (`§B.2.1`, punto 4) |

### B.1.2 No entra

| Fuera | Dónde va | Motivo |
|-------|----------|--------|
| **Geolocalización por IP** y, con ella, la mitad «nueva ubicación» del punto 4 | Sin paso asignado | `OPEN-AUTH-13`: no hay fuente decidida y no se inventa una. `§B.7` |
| **Huella de navegador por JavaScript** (*canvas*, fuentes, WebGL, `navigator.*`) | **En ningún paso** | Decisión razonada, no omisión: `§B.6.3` |
| **Ver o revocar las sesiones de otro usuario** | 1.5 (rol personalizado) / 1.6 (soporte) | `permisos.md §B.2`. `REQ-AUTH-005` dice «sesiones activas *del usuario*». La palanca del administrador ya existe y es la baja del usuario (`CA-AUTH-076`) |
| **Historial de accesos del centro** (pantalla sobre `login_attempts`) | 1.6 / `REQ-BO` | No lo pide `REQ-AUTH-005`, y `permisos.md §6` ya advirtió que esa pantalla es un registro de la jornada laboral de la plantilla y necesita su propia decisión de permisos. `login_attempts` sigue **sin `public_id`** (`datos.md §A.1`) |
| **Nombrar o «confiar» dispositivos** («este es mi portátil», «no volver a preguntar») | Sin paso asignado | No está en el requisito. La confianza de dispositivo tiene sentido cuando hay un segundo factor que saltarse, es decir, en **1.3**, y decidirla antes sería fijar la política de MFA desde aquí |
| **Notificación en la aplicación** (campana, bandeja) | `REQ-COM` (1.19) | 1.2b usa el canal que existe, igual que 1.2 hizo con el aviso de bloqueo. `§B.9` |
| **Límite de sesiones simultáneas por usuario** | Sin paso asignado | No lo pide ningún requisito. Inventarlo sería `CLAUDE.md §11` |
| **`tenant_id` y RLS en `sessions`** (`OPEN-AUTH-10`) | Pendiente de decisión | `§B.2.2` y `OPEN-AUTH-15`. **1.2b no lo necesita** para funcionar, y hacerlo es una modificación de una tabla de framework que amplía `ADR-034 §8` — eso es un ADR, no una línea de esta especificación |

---

## B.2 Frontera con lo cerrado en 1.2

### B.2.1 Qué toca 1.2b de lo ya construido

Cinco cosas. Se listan aquí para que la revisión no las descubra en el diff:

1. **`SessionRevoker` cambia de firma.** Hoy es `revokeAllForUser(User $user, ?string $exceptSessionId = null): void` y solo borra filas de `sessions`. A partir de 1.2b tiene que **cerrar además la fila de `user_sessions`** con la razón que corresponda, y la razón la sabe quien llama, no el revocador. Firma nueva en `§B.8.3`. Es una interfaz **propia del módulo** (§8.4), así que cambiarla no rompe `INV-007`; sí obliga a tocar el consumidor de `REQ-CORE` (el *listener* de `UserDeactivated`), que pasa a indicar `baja_usuario`.
2. **`SessionController::store()` gana efectos** — crear la fila de `user_sessions`, resolver el dispositivo y, si es nuevo, registrarlo y encolar la alerta. Todo **después** de `session()->regenerate()`, porque el identificador que hay que guardar es el nuevo (`RN-AUTH-32`), y **después** de `AuditRecorder::record($user, 'login')`, para no alterar el orden que `ADR-039 §4.5` fijó y que ya costó un issue de regresión (#63).
3. **`SessionController::destroy()` gana un efecto** — cerrar la fila propia con `end_reason = 'logout'`, **antes** de `session()->invalidate()`, por el mismo motivo de orden que `ADR-039 §4.5` da para el registro de `logout`.
4. **`SessionEnvironmentGuard` gana una comprobación**: `SESSION_DRIVER` debe ser `database`. `operacion.md §2.2` ya lo exigía **en prosa**, pero no hay guarda que lo verifique, y `DatabaseSessionRevoker` está escrito para no hacer nada con cualquier otro driver (`if (config('session.driver') !== 'database') { return; }`). Mientras la revocación era un efecto colateral interno del cambio de contraseña, eso era una degradación silenciosa aceptable. Cuando la revocación es un botón que el usuario pulsa y que le responde `204`, deja de serlo: el sistema le diría que ha cerrado una sesión que sigue abierta. **Un requisito de configuración que no tiene guarda no es un requisito, es una esperanza** — es el mismo argumento con el que 1.2 puso guarda a `SESSION_DOMAIN` (§6.2) en vez de confiar en el valor por defecto.
5. **`EnforceSessionIdleTimeout` y `VerifySessionTenant` ganan un efecto**: al invalidar una sesión, cerrar su fila con `inactividad` y `tenant_incoherente` respectivamente. Sin esto el panel mostraría como vivas sesiones que ya no lo están hasta que pasara la tarea de `§B.4.7`.

### B.2.2 Qué **no** toca, y por qué

**La tabla `sessions` del framework no se modifica.** Ni columnas nuevas, ni `tenant_id`, ni RLS, ni salida de `config/tenancy.php → shared_tables.framework`. Tres motivos, en orden de peso:

1. **El identificador de una sesión es una credencial portadora.** `sessions.id` es exactamente el valor que lleva la cookie una vez descifrada: quien lo tiene, es esa sesión. Un panel de sesiones necesita un identificador **público** con el que revocar, y `ADR-029` ya obliga a que sea un `public_id` ULID. Poner ese `public_id` en `sessions` significaría poner al lado de la credencial la clave con la que se la nombra en la API, y cualquier fuga futura de esa tabla —la que `OPEN-AUTH-10` describe— pasaría de exponer *payloads* a exponer también el mapa de qué fila corresponde a qué recurso de la API. Una tabla complementaria en el módulo mantiene separados el secreto y el nombre público.
2. **`sessions` la escribe el `DatabaseSessionHandler` de Laravel, no nosotros.** Sus columnas son su contrato, y el identificador de fila **se regenera en cada login** (`Store::regenerate()` destruye la fila y crea otra). Colgar metadatos de negocio de una fila que el framework destruye y recrea por su cuenta es construir sobre un detalle de implementación de una dependencia, que es justo lo que `RNF-MANT-007` manda envolver, no abrazar.
3. **`INV-007` aplicado hacia el framework.** `sessions` no es de `REQ-AUTH`: es del framework, compartida y declarada así desde 0.7. Las dos tablas nuevas sí son del módulo, con `tenant_id`, RLS `FORCE` y política estándar desde la primera línea, por `TenantMigration` (`ADR-033 §6`).

**Lo que esto no resuelve, y hay que decirlo**: `OPEN-AUTH-10` sigue abierta exactamente igual. Una inyección SQL en cualquier punto del sistema seguiría leyendo los *payloads* de sesión de todos los tenants, porque `sessions` sigue sin RLS. 1.2b **no empeora** esa situación —no añade ni un dato personal más a esa tabla— pero tampoco la arregla, y la recomendación de `OPEN-AUTH-10` era arreglarla en este paso. Se replantea, con la información nueva, en `OPEN-AUTH-15`.

**Tampoco se reabre** nada de: `ADR-025` (cookie de sesión, prohibido JWT en el navegador), la cookie *host-only* de §6, `RN-AUTH-21` (la sesión nace solo del login), `RN-AUTH-22`/`RN-AUTH-36` (revocación al fijar contraseña) ni la expiración por inactividad de §4.6.

---

## B.3 Actores

| Actor | Qué hace en 1.2b |
|-------|------------------|
| **Cualquier usuario del centro** | Ve sus sesiones activas, revoca una, revoca todas las demás, revoca todas. Recibe la alerta de acceso desde dispositivo no reconocido. **Todo por identidad, ninguna por permiso** |
| **Administrador de Centro** | **Nada nuevo.** Sus dos permisos siguen siendo `bloqueo_cuenta.leer`/`bloqueo_cuenta.eliminar`, y su palanca sobre las sesiones de otro sigue siendo dar de baja al usuario (`CA-AUTH-076`) |
| **Dirección / Secretaría / resto** | Nada salvo su propio panel |
| **Super Administrador · Soporte de plataforma** | **Ninguna operación.** 1.6 |
| **Operador de sistemas** | Despliegue, la guarda nueva de `SESSION_DRIVER` y dos tareas programadas (`operacion.md §B.3`) |

---

## B.4 Flujos

### B.4.1 Registro de la sesión en el login

Amplía §4.2 punto 6, sin alterar su orden.

1. Tras `regenerate()`, `Auth::login()` y el registro de auditoría `login` que ya existen, el servicio de sesión:
   1. Lee de la petición la IP, la cabecera `User-Agent` y la cookie `pge_device` si viene.
   2. Deriva la **descripción de cliente** (navegador, plataforma, tipo de dispositivo) del `User-Agent` (`§B.6.4`). Es texto para mostrar, no criterio de decisión.
   3. Resuelve el dispositivo (`§B.4.5`), lo que puede crear una fila en `user_known_devices` y encolar la alerta.
   4. Crea la fila de `user_sessions` con el identificador de sesión **nuevo**, `started_at`, la IP, el `User-Agent`, la descripción de cliente y la referencia al dispositivo si la hay.
2. Todo dentro de la **misma transacción** que el resto del login. Si algo de esto falla, el login falla: una sesión que existe y no aparece en el panel es peor que no poder entrar, porque es invisible precisamente para la pantalla construida para verla.
3. La alerta se **encola**, nunca se envía en la petición (`INV-012`). El login no puede depender de la latencia del proveedor de correo — es el endpoint cuyo p95 ya vigila `operacion.md §8`.

**No hay fila de `user_sessions` para las sesiones anónimas.** `GET /auth/csrf-cookie` crea una fila en `sessions` con `user_id` nulo; no es la sesión de nadie y no aparece en ningún panel.

### B.4.2 Listado de mis sesiones activas (`REQ-AUTH-005` punto 3)

1. `GET /api/v1/auth/sessions`, con sesión válida. **Sin permiso**: por identidad del portador de la cookie, igual que el logout, `/me` y el cambio de contraseña.
2. El servidor devuelve las filas de `user_sessions` del **usuario autenticado** con `ended_at IS NULL`, ordenadas por `started_at` descendente.
3. Para cada una comprueba que su identificador **sigue existiendo en `sessions`**. Si no existe —el recolector del framework la retiró, o la borró un camino que no cerró la fila—, la fila se cierra en el acto como `caducidad` y **no se devuelve**. Es el mismo cierre perezoso con el que §4.4 trata los bloqueos vencidos, y por el mismo motivo: una tarea programada que corre cada pocos minutos no puede ser lo único que mantiene coherente lo que el usuario ve ahora mismo.
4. Exactamente una de las filas devueltas lleva `current: true`: aquella cuyo identificador de sesión coincide con el de la petición en curso. Si ninguna coincide —caso imposible salvo defecto— la respuesta sigue siendo válida y el hecho se registra en el log de aplicación; **no** se inventa una marca.
5. **Nunca se devuelve el identificador de sesión, ni el *payload*, ni el valor de la cookie de dispositivo.** El único identificador que sale es el `public_id` (`RN-AUTH-40`).

### B.4.3 Revocación de una sesión concreta (`REQ-AUTH-005` punto 3)

1. `DELETE /api/v1/auth/sessions/{public_id}`, con sesión válida y CSRF.
2. Se busca la fila por `(tenant_id, public_id)` **y `user_id` del solicitante**. No encontrada, de otro usuario o de otro tenant ⇒ `404`, con cuerpo idéntico en los tres casos (`ADR-038 §6.4`, `RN-AUTH-41`). Nunca `403`: decir «existe pero no es tuya» es decir que existe.
3. Ya cerrada ⇒ `409`. Mismo criterio que `DELETE /account-lockouts/{public_id}` sobre un bloqueo ya levantado.
4. En **una transacción**: se borra la fila de `sessions` y se cierra la de `user_sessions` con `ended_at`, `end_reason = 'revocada_usuario'` y `ended_by` = el propio usuario.
5. **El efecto es inmediato y no depende de ningún *middleware* nuevo**: sin fila en `sessions`, la siguiente petición de ese navegador no encuentra sesión y responde `401`. No hace falta una comprobación de revocación por petición, y no se añade: una comprobación más en la cadena de `api.md §8` es un coste en todas las peticiones del sistema para resolver un caso que el borrado ya resuelve.
6. Respuesta `204`.
7. **Revocar la sesión actual está permitido** y equivale a un logout: se destruye la sesión, se caduca la cookie y se cierra la fila con `revocada_usuario`. Se permite a propósito en vez de responder `409`, porque en el panel «cerrar esta sesión» es lo que un usuario espera poder hacer, y obligarle a distinguir entre dos botones que hacen lo mismo es una barrera artificial. La respuesta sigue siendo `204`; la SPA, que sabe cuál marcó como `current`, redirige al login.

### B.4.4 Cierre de sesión en todos los dispositivos (`REQ-AUTH-005` punto 2)

1. `DELETE /api/v1/auth/sessions`, con sesión válida y CSRF, con un parámetro `scope` opcional:
   - **`others`** (por defecto): cierra todas las sesiones del usuario **salvo la actual**. Es el botón del panel y el caso que se usa el 99 % de las veces —«me dejé la sesión abierta en el ordenador del aula»—, y hacerlo el valor por defecto significa que una llamada mal formada nunca expulsa al usuario de su propio navegador.
   - **`all`**: cierra **todas, incluida la actual**. Es el punto 2 del requisito leído literalmente.
2. En una transacción: se borran las filas de `sessions` correspondientes y se cierran las de `user_sessions` con `end_reason = 'revocada_usuario'` y `ended_by` = el propio usuario.
3. Con `scope=all` se destruye además la sesión en curso y se caduca su cookie, exactamente como el logout.
4. Respuesta `204` en ambos casos, incluso si no había ninguna otra sesión que cerrar. Cerrar un conjunto vacío no es un error, por el mismo argumento con el que §4.3 hizo idempotente el logout.
5. **Esto no es lo mismo que `RN-AUTH-22`.** El restablecimiento de contraseña revoca todas las sesiones como **medida de contención automática**; esto es una **acción deliberada del usuario**. Comparten mecanismo y no comparten razón, y por eso `end_reason` las distingue: quien mire la traza dentro de un mes tiene que poder saber si el usuario cerró sus sesiones o si se las cerró el sistema.

### B.4.5 Detección de acceso desde dispositivo no reconocido (`REQ-AUTH-005` punto 4)

Ocurre dentro del login (`§B.4.1`, paso 1.3), después de que la credencial ya se haya verificado. **Nunca antes**: un intento fallido no debe registrar dispositivo ni disparar alerta, o el propio mecanismo de alerta se convierte en un amplificador de correo dirigido contra un usuario.

1. Si la petición trae cookie `pge_device`, se busca en `user_known_devices` una fila viva de ese `(tenant_id, user_id)` cuyo `device_token_hash` sea el SHA-256 del valor de la cookie.
   - **Encontrada** ⇒ dispositivo **conocido**. Se actualizan `last_seen_at`, `last_ip_address` y el contador. **No se alerta.** Fin.
   - **No encontrada** ⇒ dispositivo **nuevo** (el valor de la cookie no corresponde a nada nuestro: cookie de otro usuario, de otro tenant, caducada en servidor o manipulada). Se continúa en el punto 2.
2. Si no trae cookie, o el punto 1 no encontró nada, es un **acceso desde dispositivo no reconocido**:
   1. Se genera un valor nuevo de 32 bytes y se **emite la cookie** `pge_device` (`§B.6.2`).
   2. Se crea la fila de `user_known_devices` con su hash, `first_seen_at`, la descripción de cliente y la IP.
   3. Se **encola** la alerta al titular (`INV-012`), salvo que el tope de `RN-AUTH-46` esté agotado.
   4. Se informa `alerted_at` en la fila del dispositivo.
3. La alerta dice: que se ha iniciado sesión desde un dispositivo que no se había visto antes en esa cuenta, cuándo, desde qué IP y con qué descripción de cliente; y qué hacer si no fue el titular — revisar sus sesiones y cambiar la contraseña. **Sin enlace accionable sin sesión** (`RN-AUTH-50`): el único enlace es la ruta `/cuenta/sesiones` de la SPA, que exige entrar. Un correo de seguridad que trae un enlace que hace algo con solo pulsarlo es un correo de *phishing* escrito por nosotros.
4. **El primer acceso de una cuenta recién activada también alerta.** Se consideró exceptuarlo —«acaba de canjear su invitación, claro que es nuevo»— y se descarta: el canje **no** inicia sesión (`RN-AUTH-21`), así que entre la activación y el primer login puede haber pasado cualquier cosa, incluido que la contraseña se fijara desde un correo interceptado. Ese primer correo, además, enseña al usuario que el sistema le avisa, que es información útil el día que el aviso no sea esperado.

### B.4.6 Cierre de sesión por otras vías: las siete razones

Toda sesión que deja de estar viva cierra su fila con una razón, y las razones son exactamente estas. **No hay una octava**, y el enumerado no se amplía por analogía: el issue [#61](https://github.com/pirexia/plataforma-educativa/issues/61) es el recordatorio de lo que pasa cuando se reutiliza un valor por no tener el correcto.

| `end_reason` | Quién lo produce | Nota |
|--------------|------------------|------|
| `logout` | `DELETE /auth/session` (§4.3) | La salida ordinaria |
| `revocada_usuario` | `DELETE /auth/sessions/{public_id}` y `DELETE /auth/sessions` | `ended_by` informado siempre, y es el propio titular |
| `inactividad` | `EnforceSessionIdleTimeout` (§4.6) | El punto 1 de `REQ-AUTH-005`, ya cerrado en 1.2 |
| `caducidad` | El cierre perezoso de `§B.4.2` y la tarea de `§B.4.7` | La fila de `sessions` ya no existe: la retiró el recolector del framework |
| `cambio_credencial` | Restablecimiento (`RN-AUTH-22`) y cambio auto-servicio (`RN-AUTH-36`) | Contención automática, no acción del usuario sobre la sesión |
| `baja_usuario` | Evento `UserDeactivated` de `REQ-CORE` (§8.2) | `ended_by` es el administrador que dio de baja, si el evento lo transporta; `NULL` si no |
| `tenant_incoherente` | `VerifySessionTenant` (`RN-AUTH-31`) | Es un hecho de seguridad, no una salida normal, y merece un valor propio para poder contarlo (`operacion.md §B.5`) |

### B.4.7 Coherencia entre `sessions` y `user_sessions`

Las dos tablas pueden desincronizarse en una sola dirección: la fila de `sessions` desaparece sin que nadie cierre la de `user_sessions`. Ocurre con el recolector de sesiones del framework (`gc`, gobernado por `SESSION_LIFETIME`) y con cualquier vaciado manual de la tabla, incluido el que `operacion.md §10` obliga a hacer al restaurar una copia.

Se resuelve con los dos mecanismos que 1.2 ya usó para los bloqueos vencidos, por el mismo motivo:

- **Cierre perezoso** en el listado (`§B.4.2`, paso 3): lo que el usuario ve está siempre al día, sin depender de ninguna tarea.
- **`CloseOrphanedUserSessions`**, tarea programada cada 15 minutos (`operacion.md §B.3`), que cierra como `caducidad` las filas vivas cuyo identificador ya no está en `sessions`. Recoge lo que nadie vuelva a mirar.

La dirección contraria —fila en `sessions` sin fila viva en `user_sessions`— **no es un desajuste**: son las sesiones anónimas de `GET /auth/csrf-cookie`, que no pertenecen a nadie.

---

## B.5 Reglas de negocio nuevas

Continúan la numeración de §5. Las 38 anteriores siguen en vigor sin cambios.

| ID | Regla |
|----|-------|
| **Sesiones** | |
| `RN-AUTH-39` | Todo login correcto crea **exactamente una** fila viva en `user_sessions`, en la misma transacción y con el identificador de sesión **posterior** a la regeneración de `RN-AUTH-32`. Hay como mucho una fila viva por identificador de sesión y tenant, garantizado por índice único parcial y no por comprobación de aplicación. |
| `RN-AUTH-40` | El identificador que sale por la API es **siempre** el `public_id` ULID de `user_sessions`. El identificador de sesión del framework —que es la credencial portadora— **no aparece nunca** en una respuesta, en un registro de auditoría, en un log de aplicación ni en un *payload* de trabajo encolado. |
| `RN-AUTH-41` | Un usuario solo ve y revoca **sus propias** sesiones. Se autoriza por identidad del portador de la cookie, nunca por permiso con ámbito (`permisos.md §5.6`, regla 2). Una sesión de otro usuario o de otro tenant responde `404`, jamás `403`. |
| `RN-AUTH-42` | Revocar una sesión **borra su fila de `sessions`** y cierra la de `user_sessions` en la misma transacción. El efecto es inmediato por desaparición de la sesión, no por una bandera que algún camino futuro pueda dejar de comprobar. |
| `RN-AUTH-43` | `DELETE /auth/sessions` cierra **todas menos la actual** por defecto, y todas —incluida la actual— con `scope=all`. El valor por defecto es el que no expulsa a quien llama. |
| `RN-AUTH-44` | Toda fila de `user_sessions` que deja de estar viva lleva `ended_at` y una de las **siete** razones de `§B.4.6`. Un cierre sin razón es un defecto, y la restricción `CHECK ((ended_at IS NULL) = (end_reason IS NULL))` lo impide en el motor. |
| `RN-AUTH-49` | `SESSION_DRIVER` distinto de `database` **aborta el arranque de la aplicación**, en todos los entornos. Con cualquier otro driver la revocación no tiene nada que borrar y respondería `204` sin haber cerrado nada (`§B.2.1`, punto 4). |
| **Dispositivo y alerta** | |
| `RN-AUTH-45` | La cookie `pge_device` es **opaca** (32 bytes de un generador criptográfico), `httpOnly`, `Secure`, `SameSite=Lax` y **host-only**, con vida de 365 días. **No contiene ningún dato personal, ni el usuario, ni el tenant, ni nada derivado de ellos**, y de ella se persiste **solo el hash SHA-256**, igual que cualquier otro token del módulo (`RN-AUTH-09`). |
| `RN-AUTH-46` | Un acceso es **desde dispositivo nuevo** si, y solo si, la petición no presenta una cookie `pge_device` cuyo hash corresponda a un dispositivo vivo de ese `(tenant_id, user_id)`. Ni el `User-Agent`, ni la IP, ni ningún dato derivado de ellos participan en esa decisión (`§B.6`). Un acceso desde dispositivo nuevo registra el dispositivo y **encola una alerta**, con un tope de `AUTH_NEW_DEVICE_ALERTS_PER_DAY` alertas por usuario y día natural. |
| `RN-AUTH-47` | En 1.2b **no existe el concepto «nueva ubicación»**. La IP se guarda y se muestra como información descriptiva; no participa en ninguna decisión. El punto 4 de `REQ-AUTH-005` queda cumplido **solo en su mitad «nuevo dispositivo»** hasta que se resuelva `OPEN-AUTH-13`, y así debe declararse al cerrar el paso (`CLAUDE.md §0`). |
| `RN-AUTH-50` | La alerta de dispositivo nuevo **no lleva ningún enlace accionable sin sesión**, igual que el aviso de contraseña cambiada de 1.2. Es un aviso, no un mecanismo. |
| `RN-AUTH-51` | Ni la detección de dispositivo ni el panel usan **huella de navegador** de ningún tipo (`§B.6.3`). El único identificador de dispositivo es la cookie de `RN-AUTH-45`. |
| **Datos personales** | |
| `RN-AUTH-48` | Toda fila de `user_sessions` y de `user_known_devices` cuelga de un `user_id` real por clave foránea compuesta. A diferencia de `login_attempts` y `account_lockouts` —que se llevan por correo y sobreviven a la anonimización (`datos.md §A.9`)—, **estas dos se borran con la persona** en el flujo de supresión de `ADR-004`, sin excepción ni compensación por retención. |

---

## B.6 Qué es «un dispositivo nuevo»: el criterio, y por qué es ese

Es la decisión de diseño con más consecuencias del paso, así que va entera y con sus alternativas.

### B.6.1 El criterio

> **Un acceso viene de un dispositivo nuevo cuando la petición no presenta una cookie `pge_device` cuyo hash corresponda a un dispositivo ya registrado para esa cuenta en ese centro.**

Nada más. Ni ventana de tiempo, ni umbrales, ni comparación de cadenas.

Lo que se gana con un criterio tan estrecho:

- **Es verificable.** Un criterio con umbrales («mismo navegador, distinta IP, más de 30 días») produce una función que nadie puede probar de verdad, porque cada combinación es un caso.
- **No hay falso negativo por actualización.** El criterio evidente —comparar el `User-Agent`— convierte cada actualización de Chrome en un «dispositivo nuevo» para toda la plantilla del centro a la vez. Un aviso que llega el mismo día a trescientas personas por un motivo que no es real es un aviso que nadie volverá a leer, y ese es el modo de fallo que **destruye** el sub-requisito: no avisar de más, sino conseguir que el aviso deje de significar algo.
- **Falla en la dirección segura.** Un navegador que borra cookies, un modo privado o un equipo nuevo producen un aviso de más. Un aviso de más es una molestia; un aviso de menos es un acceso no detectado.

Lo que se pierde, dicho sin adornos:

- **Quien borra cookies recibe un aviso en cada acceso.** Acotado por el tope de `RN-AUTH-46`, no eliminado.
- **Un atacante que además roba la cookie `pge_device` no dispara el aviso.** Pero para tener esa cookie ya necesita acceso al navegador de la víctima, y con acceso al navegador tiene la sesión entera sin necesidad de la contraseña. La detección de dispositivo nuevo nunca ha defendido de ese caso, ni aquí ni en ningún otro producto.
- **No detecta el acceso desde otro dispositivo del propio usuario reconocido como suyo.** Correcto: eso es exactamente lo que no hay que avisar.

### B.6.2 La cookie `pge_device`

| Atributo | Valor | Motivo |
|----------|-------|--------|
| Nombre | `pge_device` | |
| Valor | 32 bytes de `random_bytes()` en hexadecimal | Mismo formato que los tres tokens de 1.2 (`RN-AUTH-09`) |
| `HttpOnly` | sí | Ningún JavaScript necesita leerla, y que no pueda leerla la saca del alcance de un XSS |
| `Secure` | sí, con la misma excepción de desarrollo local que la cookie de sesión | |
| `SameSite` | `Lax` | Mismo argumento de `RN-AUTH-27` |
| `Domain` | **ausente** — host-only | Consecuencia de primer orden: **un dispositivo conocido en `centroa.dominio` no lo es en `centrob.dominio`**. Es `RN-AUTH-08` aplicado al dispositivo, y sale gratis del mismo atributo que ya protege la sesión (§6) |
| Vida | 365 días | |
| Cifrado | sí, el que `EncryptCookies` aplica a todas | Innecesario (el valor ya es opaco) e inofensivo. **No se añade a la lista de excepciones** de ese *middleware* |
| Persistencia | **solo el SHA-256**, en `user_known_devices.device_token_hash` | `RN-AUTH-09`. Y el nombre de la columna encaja con el patrón `*token*` de `config('audit.secret_attribute_patterns')`, así que la auditoría lo redacta como `secret` sin que nadie lo declare |

**La cookie se emite en el login, nunca antes.** No se emite en `GET /auth/csrf-cookie` ni en ninguna petición anónima: una cookie persistente de identificación de navegador puesta a cualquiera que abra la página es una cookie de seguimiento, no de seguridad, aunque el contenido sea idéntico. Emitirla solo tras una autenticación correcta es lo que la ata a la finalidad de proteger esa cuenta.

**Clasificación en protección de datos.** Es una cookie técnica de seguridad, ligada a un servicio que el usuario ha solicitado explícitamente (proteger su cuenta y avisarle de accesos que no reconozca), sin perfilado y sin cesión a terceros. Esa es la lectura que sostiene su exención de consentimiento, pero **la clasificación formal y su reflejo en `PRIVACY.md` y en `REQ-PRIV` no la decide esta especificación**: `OPEN-AUTH-14`.

### B.6.3 Por qué **no** hay huella de navegador

Se descarta explícitamente, y no por dificultad técnica:

- Una huella por JavaScript (*canvas*, fuentes instaladas, WebGL, resolución, `navigator.*`) es **tecnología de seguimiento**. Identifica el navegador aunque el usuario borre todo, que es precisamente lo que hace que sea eficaz y también lo que la saca de cualquier exención de consentimiento razonable.
- El producto trata datos de **menores** (`INV-008`). Introducir un identificador persistente e imborrable en el navegador de un alumno para mejorar la precisión de un aviso de acceso es desproporcionado por cualquier lectura del principio de minimización, y sería el tipo de decisión que aparece en una evaluación de impacto como hallazgo.
- Rompería `RSEC-OWASP-005` en la práctica: las bibliotecas de huella que valen algo se cargan desde un CDN, y la CSP estricta del proyecto no lo permite.

Si algún día la precisión del criterio resulta insuficiente **con el problema medido delante**, la respuesta correcta es el segundo factor de 1.3, no la huella.

### B.6.4 El `User-Agent`: para qué sí sirve

Se guarda crudo (truncado a 1024 caracteres) y se deriva de él una descripción legible —navegador, plataforma, tipo de dispositivo— con un único fin: **que el usuario reconozca la fila del panel**. «Chrome en Windows, escritorio» le dice si esa sesión es suya; un `User-Agent` completo, no.

Derivar esa descripción con un mínimo de acierto exige o bien una dependencia de terceros (una biblioteca de detección de dispositivo, con su tabla de firmas mantenida) o bien un análisis propio deliberadamente pobre. `CLAUDE.md §1` exige justificar toda dependencia nueva y envolverla tras interfaz propia (`RNF-MANT-007`), y la decisión entre las dos opciones **no la tomo yo**: `OPEN-AUTH-17`. En cualquiera de los dos casos:

- La derivación vive tras una interfaz propia del módulo (`ClientDescriber`), y lo que se persiste es su **resultado**, no la biblioteca.
- Un `User-Agent` irreconocible produce `desconocido` en los tres campos y **no es un error**: la fila del panel sigue valiendo, porque lleva la IP y la hora.
- **La descripción no participa nunca en la decisión de `RN-AUTH-46`.** Si mañana se cambia de biblioteca, ningún usuario recibe una avalancha de avisos, porque la decisión no depende de ella. Ese aislamiento es el motivo real de separar descripción y criterio.

---

## B.7 «Nueva ubicación»: qué se puede afirmar y qué no

`REQ-AUTH-005` punto 4 dice «nuevo dispositivo/ubicación». La ubicación no se puede derivar de una petición HTTP: hace falta traducir la IP a un lugar, y eso exige una **fuente de datos de geolocalización**, que el proyecto **no tiene decidida**.

Lo que 1.2b hace, que es lo único honesto que puede hacer:

1. **Guarda la IP** de cada sesión y la del último uso de cada dispositivo (`inet`, tipo nativo, igual que `login_attempts`).
2. **La muestra** en el panel y en la alerta.
3. **Deja el hueco preparado sin llenarlo**: `user_sessions.location_label` existe, es `NULL` siempre en 1.2b, y hay una interfaz `IpGeolocator` con implementación nula que devuelve «desconocida». No es una columna «por si acaso» de las que prohíbe `ADR-034 OPEN-13` —el requisito la pide expresamente—, pero **sí hay que decidir si nace ahora o cuando se resuelva `OPEN-AUTH-13`**; se propone que nazca ahora porque añadirla después sobre una tabla con datos es igual de fácil, y tenerla vacía deja escrito en el esquema que el requisito está a medias.
4. **Declara el punto 4 cumplido a medias** (`RN-AUTH-47`). No se marca como terminado en `PLAN-IMPLEMENTACION.md` sin esa salvedad escrita.

Las dos familias de solución y lo que implica cada una están en `OPEN-AUTH-13`, sin recomendación de proveedor concreto: no es una decisión técnica menor, es una decisión de tratamiento de datos personales.

---

## B.8 Interacción con otros módulos

`INV-007` sigue rigiendo: nada de importar código interno.

### B.8.1 Interfaces que consume

Las tres de §8.1, sin cambios. `TenantSettingsReader` y `UserDirectory` no necesitan ampliarse: 1.2b no lee ninguna configuración de centro nueva.

### B.8.2 Eventos

| Evento | Dirección | Qué cambia |
|--------|-----------|------------|
| `UserDeactivated` (de `REQ-CORE`) | Consumido | Sigue revocando las sesiones; ahora además cierra las filas con `baja_usuario` |
| `UserEmailChanged` (de `REQ-CORE`) | Consumido | **Sin cambios**: cambiar el correo de acceso no invalida sesiones ni dispositivos, porque no cambia quién es la persona. Se anota porque la pregunta surge sola al leer §8.2 |
| `UserLoggedIn` (publicado en 1.2) | Publicado | 1.2 lo declaró con **1.2b como consumidor previsto para la detección de nuevo dispositivo**. **No se consume.** La detección ocurre dentro de la transacción del login (`§B.4.1`), porque tiene que emitir una cookie en **esa** respuesta HTTP, y un consumidor de evento —síncrono o encolado— no puede tocar la respuesta. El evento sigue publicándose para `REQ-BI`; simplemente no es el mecanismo de esto. Es una previsión de 1.2 que la especificación de 1.2b corrige, y conviene que quede escrito en vez de que se descubra al implementar |
| `UserLoggedOut` (publicado en 1.2) | Publicado | Igual: el cierre de la fila ocurre en el propio camino, no por evento |
| `SessionRevoked` | **Nuevo, publicado** | Una sesión revocada por su titular. Consumidor previsto: `REQ-COM` (1.19) y `REQ-BI` |
| `NewDeviceDetected` | **Nuevo, publicado** | Acceso desde dispositivo no reconocido. Consumidor previsto: `REQ-COM` (1.19), que sustituirá el envío directo de correo de 1.2b igual que sustituirá el de `AccountLocked` |

### B.8.3 Interfaces que expone

| Interfaz | Cambio |
|----------|--------|
| `SessionRevoker` | **Firma nueva**: la razón del cierre pasa a ser un parámetro obligatorio, y aparece la revocación de una sesión concreta. Conceptualmente: `revokeAllForUser(User, SessionEndReason, ?string $exceptSessionId)` y `revokeSession(UserSession, SessionEndReason, ?User $revokedBy)`. Es interfaz propia del módulo; el único consumidor externo es el *listener* de `UserDeactivated`, que pasa `baja_usuario` |
| `UserSessionDirectory` | **Nueva.** Consultar las sesiones vivas de un usuario. La consumirá **1.6** (soporte de plataforma) y, si alguna vez se aprueba, la vista de administración que `permisos.md §B.2` deja fuera. Se expone desde ya para que ese paso no tenga que abrir el modelo del módulo |
| `PasswordPolicy`, `AccountLockService` | Sin cambios |

---

## B.9 Puntos de extensión

- **MFA (1.3)**: si 1.3 regenera el identificador de sesión al superar el segundo factor —lo normal—, tiene que **actualizar `user_sessions.session_id`** en el mismo acto, o la fila quedará huérfana y la tarea de `§B.4.7` la cerrará como `caducidad` mientras la sesión sigue viva. Es la única trampa que 1.2b le deja a 1.3, y por eso está escrita aquí. La otra mitad es agradable: la cookie `pge_device` es exactamente el mecanismo sobre el que 1.3 podrá construir «no volver a pedir el código en este dispositivo» si se decide tenerlo; 1.2b **no** lo decide (`§B.1.2`).
- **Backoffice (1.6)**: consume `UserSessionDirectory`. Toda capacidad de ver o cerrar sesiones ajenas se decide allí, con su propio permiso y su propio registro, nunca ampliando por analogía lo de aquí.
- **`REQ-COM` (1.19)**: consume `NewDeviceDetected` y `SessionRevoked` y sustituye el envío directo de correo. Es el mismo camino que 1.2 dejó escrito para `AccountLocked`.
- **`OPEN-AUTH-13` resuelta**: rellenar `IpGeolocator` y `location_label`. Ni un endpoint ni una tabla cambian.

---

## B.10 Auditoría (`INV-003`)

**1.2b no necesita ampliar `ADR-039`, y esto es una conclusión, no una suposición.**

`ADR-039 §5.3` fija la carga de la prueba para quien quiera un valor nuevo de `event`: demostrar que el hecho **no** es CRUD sobre ninguna entidad. Aquí ocurre lo contrario, y por el mismo motivo que `AccountLockout` en §10.1: los dos hechos que hay que registrar **son** operaciones sobre entidades reales, porque el modelo de datos de `datos.md §B.1`/`§B.2` las modela como entidades en vez de como eventos sueltos.

| Hecho | Cómo queda registrado | Mecanismo |
|-------|------------------------|-----------|
| El usuario revoca una sesión | `updated` sobre `UserSession` con `ended_at`, `end_reason` y `ended_by` | *Observer* de 0.9, sin código |
| Se registra un dispositivo nuevo para una cuenta | `created` sobre `UserKnownDevice` | *Observer* de 0.9, sin código |
| Se avisa al titular | `updated` sobre `UserKnownDevice` con `alerted_at` | *Observer* de 0.9, sin código |
| El acceso en sí | `login` sobre `User` | Ya existe desde 1.2 (`ADR-039`) |

**Ninguna llamada manual a `AuditRecorder`.** `ADR-039 §4.5` restringe la escritura manual a sus tres valores, y 1.2b no la usa: es el resultado que hay que perseguir, no una limitación que sortear.

**`OPEN-AUTH-16`, resuelta por `ADR-040`.** El *observer* de 0.9 enganchaba `created` **siempre**, sin forma de excluirlo por modelo. Con `UserSession` auditada tal cual, cada login habría escrito dos filas en `audit_logs`: el `login` de `ADR-039` y un `created` sobre `UserSession` que no decía nada que el `login` no dijera ya —mismo actor, mismo momento, misma IP, mismo `request_id`—. `ADR-040` amplía el mecanismo de 0.9 con una exclusión declarativa por modelo y evento (`Auditable::auditExcludedEvents()`); `UserSession` declara `['created']`. El resto de su ciclo de vida —revocación, las siete razones de cierre de `§B.4.6`, borrado lógico— se sigue auditando entero por el observer, sin ninguna llamada manual (`CA-AUTH-102`). `UserKnownDevice` no declara ninguna exclusión: su alta (`created`) y el aviso al titular (`updated`) se auditan sin condiciones.

---

## B.11 Interfaz de usuario

**Una pantalla más**, en `apps/web/src/modules/auth/views/`, siguiendo la decisión ya tomada en `OPEN-AUTH-01` y su argumento:

| Ruta de la SPA | Pantalla | Sesión |
|----------------|----------|--------|
| `/cuenta/sesiones` | Mis sesiones activas | **Sí** |

Es de la misma categoría que `/cuenta/contrasena`: **formulario aislado, sin navegación**, así que no depende del *layout* de 1.8 ni del *design system* de 1.7, y construirla ahora no adelanta trabajo de ningún paso posterior. Sin ella, el punto 3 del requisito —cuya primera palabra es «visualización»— queda entregado como un `GET` que ningún ser humano puede ejercer.

Obligaciones, las mismas de §1.6 y sin excepción por ser austera:

- **Cuatro idiomas** (`INV-009`), aquí ya con el idioma preferido del usuario, que existe porque hay sesión.
- **WCAG 2.2 AA** (`RNF-UX-002`). Es una tabla con acciones destructivas: la confirmación tiene que ser alcanzable y anunciable por teclado y lector de pantalla, no un icono sin nombre.
- **Confirmación explícita** antes de revocar, y **una advertencia distinta** cuando la fila que se va a revocar es la actual («vas a cerrar esta misma sesión»).
- **Ningún dato de sesión en `localStorage`/`sessionStorage`** (`RN-AUTH-28`), tampoco el listado.
- Los enumerados (`end_reason`, tipo de dispositivo) se traducen **por catálogo en el cliente**, nunca cambiando el valor que devuelve la API (`ADR-038 §3.2`, `api.md §9.5`).

**Sin branding de tenant** en esta pantalla, a diferencia de las cinco públicas de §1.6: hay sesión, y el branding de las pantallas con sesión es asunto del *design system* de 1.7 y del *layout* de 1.8, no de este paso.

---

## B.12 Criterios de aceptación

Verificables, cada uno con test que referencia su ID (`INV-015`). Bloque `080-103`, sin solaparse con los de 1.2.

### Registro de sesión (`REQ-AUTH-005` puntos 2-3)

- **`CA-AUTH-080`** · *Dado* un login correcto, *cuando* termina, *entonces* existe exactamente una fila viva en `user_sessions` de ese tenant y ese usuario, con el identificador de sesión **posterior** a la regeneración, con `started_at`, IP y `User-Agent`, y **sin** contraseña, token ni fragmento de ninguno (`RN-AUTH-39`, `RN-AUTH-05`).
- **`CA-AUTH-081`** · *Dado* `GET /auth/csrf-cookie` sin login posterior, *cuando* se inspecciona la base de datos, *entonces* hay fila en `sessions` y **ninguna** en `user_sessions` (`§B.4.1`).
- **`CA-AUTH-082`** · *Dado* un usuario con tres sesiones abiertas en tres clientes distintos, *cuando* llama a `GET /auth/sessions` desde una de ellas, *entonces* recibe las tres, **solo las suyas**, y **exactamente una** lleva `current: true` — la que hace la petición (`RN-AUTH-41`, `§B.4.2`).
- **`CA-AUTH-083`** · *Dado* cualquier respuesta de `GET /auth/sessions`, *cuando* se inspecciona, *entonces* **no** contiene el identificador de sesión del framework, ni el *payload*, ni el valor ni el hash de la cookie `pge_device` (`RN-AUTH-40`).
- **`CA-AUTH-084`** · *Dado* una fila viva de `user_sessions` cuya fila de `sessions` ya no existe, *cuando* el usuario lista sus sesiones, *entonces* esa fila **no** aparece y queda cerrada como `caducidad` sin que haya corrido ninguna tarea programada (`§B.4.2` punto 3).

### Revocación (`REQ-AUTH-005` puntos 2-3)

- **`CA-AUTH-085`** · *Dado* dos sesiones del mismo usuario, *cuando* desde la primera se hace `DELETE /auth/sessions/{public_id}` sobre la segunda, *entonces* `204`, la fila de `sessions` de la segunda desaparece, su fila de `user_sessions` queda con `ended_at`, `end_reason = 'revocada_usuario'` y `ended_by` informado, **la petición siguiente con la cookie de la segunda responde `401`**, y la primera sigue funcionando (`RN-AUTH-42`).
- **`CA-AUTH-086`** · *Dado* la sesión actual, *cuando* se revoca a sí misma por su `public_id`, *entonces* `204`, la cookie queda caducada y la petición siguiente responde `401` (`§B.4.3` punto 7).
- **`CA-AUTH-087`** · *Dado* el `public_id` de una sesión **de otro usuario del mismo tenant** y el de una **de otro tenant**, *cuando* se intentan revocar, *entonces* los dos responden `404` con **cuerpo idéntico**, y ninguna de las dos sesiones se cierra (`RN-AUTH-41`, `INV-001`, `ADR-038 §6.4`).
- **`CA-AUTH-088`** · *Dado* una sesión ya cerrada, *cuando* se vuelve a revocar, *entonces* `409` (`§B.4.3` punto 3).
- **`CA-AUTH-089`** · *Dado* tres sesiones del mismo usuario, *cuando* se llama a `DELETE /auth/sessions` sin parámetros, *entonces* `204`, las **otras dos** quedan cerradas como `revocada_usuario` y **la actual sigue viva** (`RN-AUTH-43`).
- **`CA-AUTH-090`** · *Dado* lo mismo con `scope=all`, *entonces* las tres quedan cerradas y la petición siguiente con la cookie actual responde `401` (`RN-AUTH-43`, `REQ-AUTH-005` punto 2 literal).
- **`CA-AUTH-091`** · *Dado* un usuario con **una sola** sesión, *cuando* llama a `DELETE /auth/sessions` sin parámetros, *entonces* `204` y su sesión sigue viva (`§B.4.4` punto 4).
- **`CA-AUTH-092`** · *Dado* los tres endpoints de sesiones, *cuando* se llaman **sin sesión**, *entonces* `401`; *cuando* los dos `DELETE` se llaman sin token CSRF válido, *entonces* `419`/`403` y **no se cierra ninguna sesión** (`RN-AUTH-29`, `INV-002`).

### Detección de dispositivo (`REQ-AUTH-005` punto 4)

- **`CA-AUTH-093`** · *Dado* un usuario que inicia sesión **sin cookie `pge_device`**, *cuando* termina el login, *entonces* la respuesta emite la cookie con `HttpOnly`, `SameSite=Lax`, **sin atributo `Domain`**, existe una fila en `user_known_devices` con el **hash** del valor (nunca el valor), y se **encola** la alerta (`RN-AUTH-45`, `RN-AUTH-46`, `INV-012`).
- **`CA-AUTH-094`** · *Dado* el mismo usuario en el mismo navegador, *cuando* vuelve a iniciar sesión **presentando la cookie**, *entonces* **no** se crea ningún dispositivo nuevo, **no se encola ninguna alerta**, y `last_seen_at` y el contador quedan actualizados (`RN-AUTH-46`).
- **`CA-AUTH-095`** · *Dado* una cookie `pge_device` válida del **usuario A**, *cuando* el **usuario B** del mismo tenant inicia sesión presentándola, *entonces* para B es un dispositivo nuevo, se le alerta, y el dispositivo de A **no se modifica** (`§B.4.5` punto 1).
- **`CA-AUTH-096`** · *Dado* una cookie `pge_device` obtenida en `tenanta.{base}`, *cuando* se presenta en `tenantb.{base}`, *entonces* el navegador **ni siquiera la envía** (host-only) y, forzada en la petición, produce un dispositivo nuevo en el tenant B sin tocar nada del A (`RN-AUTH-08`, `RN-AUTH-45`, `INV-001`).
- **`CA-AUTH-097`** · *Dado* un `User-Agent` completamente irreconocible, *cuando* se inicia sesión, *entonces* el login **funciona**, la fila se crea con la descripción `desconocido` y la detección de dispositivo se comporta igual que con un `User-Agent` conocido (`§B.6.4`).
- **`CA-AUTH-098`** · *Dado* un usuario que inicia sesión `AUTH_NEW_DEVICE_ALERTS_PER_DAY + 2` veces sin cookie en el mismo día, *entonces* se registran todos los dispositivos pero se encolan **como mucho** el número configurado de alertas (`RN-AUTH-46`).
- **`CA-AUTH-099`** · *Dado* un intento de login **fallido** desde un dispositivo desconocido, *entonces* **no** se registra dispositivo, **no** se encola alerta y **no** se crea fila de `user_sessions` (`§B.4.5`, encabezado).
- **`CA-AUTH-100`** · *Dado* la alerta de dispositivo nuevo, *cuando* se revisa, *entonces* existe en `es-ES`, `en`, `de` y `fr`, va en el idioma preferido del destinatario, y **no contiene ningún enlace que ejecute una acción sin sesión** (`RN-AUTH-50`, `INV-009`).

### Cierre por otras vías, auditoría y datos

- **`CA-AUTH-101`** · *Dado* un usuario con tres sesiones, *cuando* (a) restablece su contraseña, (b) la cambia desde `/cuenta/contrasena`, (c) `REQ-CORE` lo da de baja y (d) su sesión expira por inactividad, *entonces* en cada caso las filas de `user_sessions` afectadas quedan cerradas con `cambio_credencial`, `cambio_credencial` **salvo la actual**, `baja_usuario` e `inactividad` respectivamente (`RN-AUTH-22`, `RN-AUTH-36`, `RN-AUTH-44`, `§B.4.6`).
- **`CA-AUTH-102`** · *Dado* una revocación por el usuario y un alta de dispositivo nuevo, *cuando* se consulta `audit_logs`, *entonces* existe un `updated` sobre `UserSession` con `ended_at`/`end_reason`/`ended_by` y un `created` sobre `UserKnownDevice`, **ninguno escrito por llamada manual**, y en ninguna fila aparece el identificador de sesión ni el hash del dispositivo sin redactar (`INV-003`, `ADR-035`, `ADR-039 §4.5`).
- **`CA-AUTH-103`** · *Dado* `SESSION_DRIVER` distinto de `database`, *cuando* arranca la aplicación, *entonces* falla con un mensaje que remite a `funcional.md §B.2.1`, en todos los entornos (`RN-AUTH-49`).

---

## B.13 Preguntas abiertas

Cinco, todas explícitas y **ninguna resuelta aquí**. Dos condicionan el alcance real de lo que se entrega; tres son decisiones de fondo que no me corresponden.

### `OPEN-AUTH-13` · No hay fuente de geolocalización por IP, y sin ella el punto 4 queda a medias

`REQ-AUTH-005` punto 4 pide detectar login desde nuevo dispositivo **o ubicación**. La ubicación exige traducir una IP a un lugar y **el proyecto no tiene ninguna fuente decidida** — no aparece en `memory.md`, ni en ningún ADR, ni en las decisiones abiertas de infraestructura. **No invento una** (`CLAUDE.md §11`).

Las dos familias de solución, con lo que cada una arrastra:

| Familia | Cómo funciona | Lo que hay que aceptar |
|---------|---------------|------------------------|
| **Base de datos local**, embebida en la imagen o montada como volumen | La API traduce la IP sin salir a la red | **Ninguna IP de ningún usuario sale del sistema**: no hay encargado de tratamiento, no hay transferencia internacional, no hay dependencia de disponibilidad. A cambio: hay que **actualizar el fichero periódicamente** (procedimiento en `SYSADMIN.md`, tamaño en la imagen, licencia del fichero) y la precisión es de ciudad/región, no más |
| **Servicio externo por API** | La API consulta a un tercero en cada resolución | **Cada IP de cada usuario del centro se envía a un tercero.** Eso es un encargado de tratamiento con su contrato (art. 28 RGPD), probablemente una transferencia internacional que analizar, una entrada en el registro de actividades, y una dependencia externa en el camino del login que hay que degradar con cuidado. Más preciso y mucho más caro en obligaciones |

**Lo que hace falta decidir**: (1) si el punto 4 se entrega solo como «nuevo dispositivo» y la ubicación queda como paso posterior —que es lo que esta especificación asume y declara (`RN-AUTH-47`)—, o si es condición para cerrar 1.2b; (2) en su caso, cuál de las dos familias; (3) el proveedor concreto, que además necesita ADR por ser dependencia externa nueva (`CLAUDE.md §1`).

**Sin recomendación de proveedor.** Sí una observación que sirve para decidir: si el criterio de dispositivo de `§B.6.1` funciona, la ubicación aporta poco a la detección y mucho al mensaje («desde Madrid» es más reconocible para el usuario que «desde 88.1.2.3»). Es decir, su valor es más de **usabilidad del aviso** que de seguridad, y eso debería pesar frente a las obligaciones que arrastra la segunda familia.

### `OPEN-AUTH-14` · Clasificación de la cookie `pge_device` en protección de datos

`§B.6.2` la construye como cookie técnica de seguridad: opaca, sin dato personal, emitida solo tras autenticación correcta, con la única finalidad de avisar al titular de accesos que no reconoce. Esa es la lectura que sostiene su **exención de consentimiento**, y es la lectura habitual para las cookies de seguridad centradas en el usuario.

**No la doy por buena yo.** Hace falta: confirmarla, reflejarla en `PRIVACY.md` y en el inventario de cookies, y decidir si `REQ-PRIV` tiene que recogerla como tratamiento propio en el registro de actividades. Es relevante porque **la plataforma trata datos de menores** (`INV-008`) y el listón de proporcionalidad es más alto que en un producto de adultos.

Si la respuesta fuera que **sí requiere consentimiento**, la consecuencia hay que verla de frente: una detección de dispositivo que solo funciona para quien acepta una cookie no protege a quien no la acepta, y habría que decidir si el punto 4 se entrega igualmente o se replantea.

### `OPEN-AUTH-15` · `sessions` sin `tenant_id` ni RLS: ¿se cierra `OPEN-AUTH-10` en 1.2b o se mantiene abierta?

`OPEN-AUTH-10` recomendaba que la columna `tenant_id`, la RLS y la salida de `sessions` de `shared_tables.framework` **llegaran con 1.2b**, «junto con el panel que los usa». Con el diseño de `§B.2.2`, ese razonamiento ya no se sostiene tal cual: **el panel no las usa**. `user_sessions` es una tabla de tenant con RLS desde su primera línea, y el aislamiento del listado y de la revocación lo garantiza ella.

Lo que sigue igual: `sessions` guarda *payloads* de sesión de todos los tenants sin RLS, y una inyección SQL en cualquier punto del sistema los alcanzaría todos.

Lo que hay que decidir, con las tres opciones sobre la mesa:

| Opción | Consecuencia |
|--------|--------------|
| **Mantener `OPEN-AUTH-10` abierta**, sin paso asignado | El riesgo documentado sigue vivo indefinidamente. La mitigación real (`RSEC-OWASP-001`, consultas parametrizadas) sigue siendo la única |
| **Cerrarla en 1.2b** | Amplía `ADR-034 §8` ⇒ **ADR nuevo**. Y no es trivial: la RLS `FORCE` sobre `sessions` afecta al `DatabaseSessionHandler` del framework —lectura, escritura y **recolección de basura**, que pasaría a recoger solo las filas del tenant en curso— y a todo camino que toque la tabla sin contexto de tenant. Es tocar el mecanismo de sesión del framework para cerrar un riesgo que no es el que abre este paso |
| **Cerrarla en un paso propio de endurecimiento**, con su ADR y sus pruebas | Ni retrasa 1.2b ni deja el riesgo sin dueño |

**Recomendación**: la tercera, y que 1.2b **no** la asuma. Pero la decisión de dónde vive ese trabajo, y si se hace, es del usuario.

### `OPEN-AUTH-16` · **Resuelta por `ADR-040`** — el *observer* de auditoría no sabía excluir `created`, y eso duplicaba una fila por cada login

Detallada en `§B.10`, ahora resuelta. `ADR-040` amplía el mecanismo de 0.9 con `Auditable::auditExcludedEvents()`; `UserSession` declara `['created']`, con test de arquitectura que fija que es la única exclusión del repositorio (`ADR-040 §4.4`).

### `OPEN-AUTH-17` · ¿Dependencia nueva para interpretar el `User-Agent`?

`§B.6.4`. Para que la fila del panel diga «Chrome en Windows» y no un `User-Agent` de doscientos caracteres hace falta interpretarlo. Las opciones:

| Opción | A favor | En contra |
|--------|---------|-----------|
| **Biblioteca de detección de dispositivo** | Acierta, y su tabla de firmas la mantiene otro | Dependencia nueva ⇒ `CLAUDE.md §1`: justificar mantenimiento activo, licencia y frecuencia de *releases*, envolverla tras interfaz propia (`RNF-MANT-007`) y meterla en el escaneo de dependencias de cada PR |
| **Análisis propio mínimo** (media docena de expresiones regulares: familia de navegador, familia de sistema, móvil/escritorio) | Sin dependencia, sin superficie | Se equivoca con lo raro, y envejece: cada cambio de formato de `User-Agent` lo degrada en silencio |

**No decido**. Sí acoto el daño de equivocarse: por `§B.6.4`, la descripción **no participa en ninguna decisión**, así que un análisis pobre produce una etiqueta fea en una pantalla, nunca un aviso de más ni de menos. Con esa red, la opción sin dependencia es más defendible de lo que parece — pero la elección es del usuario.

### Nota: dos cosas que **no** dejo como pregunta abierta, y por qué

Se anotan para que la revisión no las eche en falta:

- **La pantalla `/cuenta/sesiones` entra** (`§B.11`). No es una decisión nueva: `OPEN-AUTH-01` ya la tomó para esta categoría de pantalla —con sesión, sin navegación, sin dependencia de 1.7 ni de 1.8— y la primera palabra del sub-requisito es «visualización». Reabrirla sería preguntar dos veces lo mismo.
- **Ningún administrador ve ni cierra sesiones ajenas** (`§B.1.2`, `permisos.md §B.2`). `REQ-AUTH-005` dice «del usuario», y `CLAUDE.md §11` prohíbe inventar requisitos. Si el usuario quiere esa capacidad, es un requisito nuevo con su decisión de permisos, y su sitio natural es 1.5 (rol personalizado) o 1.6 (soporte), no este paso.

---

## B.14 ¿Se aprueba esta especificación?

**Aprobada por el usuario el 2026-08-25.** Decisiones tomadas, las cinco según la opción recomendada por esta especificación:

1. **`OPEN-AUTH-13`** — El punto 4 de `REQ-AUTH-005` se cierra en 1.2b **solo como «dispositivo nuevo»** (`RN-AUTH-47`), sin geolocalización por IP. La ubicación queda pospuesta a un paso futuro, con su propio proveedor y ADR cuando exista.
2. **`OPEN-AUTH-14`** — La cookie `pge_device` se acepta como **cookie técnica exenta de consentimiento**, tal como la diseña `§B.6.2`. Se refleja en `PRIVACY.md` y en el inventario de cookies sin cambios de diseño.
3. **`OPEN-AUTH-15`** — `OPEN-AUTH-10` (`tenant_id` y RLS en `sessions`) **no se cierra en 1.2b**. Queda como **paso propio de endurecimiento futuro**, con su ADR y pruebas cuando se aborde — issue de seguimiento a crear.
4. **`OPEN-AUTH-16`** — Se **amplía el mecanismo de auditoría de 0.9** con una exclusión explícita por modelo para la creación de `UserSession` vía login (evita la fila `created` duplicada junto al evento `login` de `ADR-039`). Requiere ADR corto — `ADR-040`, a redactar por `architect` antes de implementar.
5. **`OPEN-AUTH-17`** — **Análisis propio mínimo** con expresiones regulares para interpretar el `User-Agent` (familia de navegador, familia de SO, móvil/escritorio). Sin dependencia externa nueva.

Con esto, `1.2b` pasa a implementación en `feature/REQ-AUTH-005-1.2b-sesiones-activas`.

Y una confirmación de alcance, por si no se comparte lo que doy por decidido: **la pantalla `/cuenta/sesiones` entra** y **ningún administrador ve ni cierra sesiones ajenas**.

Ninguna de las cinco impide **redactar**; `OPEN-AUTH-13` y `OPEN-AUTH-14` sí condicionan qué se puede declarar terminado.

---
---

# Parte C · Paso 1.3 · Autenticación multifactor (`REQ-AUTH-003`)

> **Estructura**: §1 a §14 son el paso **1.2** (cerrado 2026-08-25). §B.1 a §B.14 son el paso **1.2b** (cerrado 2026-08-26). Esta **Parte C** (`§C.1` en adelante) es el paso **1.3**, **pendiente de aprobación**.
>
> Numeración: se sigue el criterio de 1.2b. Las secciones planas de 1.2 y las `§B.n` de 1.2b **no se tocan**, para no romper las referencias cruzadas que hay en `datos.md`, `api.md`, `permisos.md`, `operacion.md`, el código, los tests y tres ADR. Las reglas de negocio continúan la serie única (`RN-AUTH-52` en adelante), los criterios de aceptación también (`CA-AUTH-104` en adelante) y las preguntas abiertas también (`OPEN-AUTH-18` en adelante).
>
> Fuente: `REQ-AUTH-003` (sección 5.2 del documento de requisitos), `RPERM-014`, `RPERM-005`/`RPERM-006`/`RPERM-007` (sección 11.2) y `REQ-BO-007` (solo como frontera).

---

## C.0 Antes de nada: la colisión de orden con 1.5, y qué existe hoy de verdad

`REQ-AUTH-003` dice que `mfa_obligatorio` es *«editable por el Administrador de Centro **desde el editor de roles**»*, que existe *«tanto en los roles predefinidos como en los **roles personalizados** que cree el administrador (`RPERM-005`)»*, que *«al clonar un rol se hereda el valor del rol origen (`RPERM-006`)»* y que hay *«vista previa **en el editor de roles**»*.

**El editor de roles es el paso 1.5, y 1.5 va después de 1.3 en el plan.** Eso no es una suposición: `PLAN-IMPLEMENTACION.md` lo sitúa como *«1.5 · Permisos granulares [OPUS + SONNET] ⚠️ paso crítico»*, después de `1.3` y `1.4`. Antes de escribir nada he comprobado en el código qué existe hoy, porque la respuesta cambia por completo si el atributo no estuviera:

| Hecho verificado | Dónde | Consecuencia |
|------------------|-------|--------------|
| **`roles.mfa_required` ya existe en el esquema**, `boolean NOT NULL DEFAULT false` | `database/migrations/2026_08_18_100400_create_roles_table.php`, línea 24 | 1.3 **no crea la columna**. `RPERM-014` está satisfecho a nivel de datos desde 0.8 |
| **Ya está sembrado con valor real**: `true` en `administrador_centro` y `soporte_plataforma`, `false` en los otros 14 | `ProvisionTenantDefaults::ROLE_ATTRIBUTES` | Hay tenants con la marca puesta **que hoy nada comprueba** (`permisos.md §5.4` lo dice literalmente) |
| **Ya se expone de solo lectura** en `GET /roles` y `GET /roles/{public_id}` | `RoleResource::toArray()` | La lectura del atributo no hay que inventarla |
| **La entidad `Role` ya admite roles personalizados por tenant**: `is_system`, `name_key` (predefinido, traducible) *xor* `name` (literal del centro), único parcial `(tenant_id, code)` | Misma migración, `roles_name_source_check` | El esquema de `RPERM-005` está puesto. Lo que no existe es **quién escribe** esas filas |
| **No hay ninguna escritura de roles**: `RolesController` solo tiene `index()` y `show()`, y su propio comentario dice *«Solo lectura en 1.1 — la escritura de roles y concesiones es 1.5»* | `app/Modules/Core/Http/Controllers/RolesController.php` | No hay creación, ni clonación, ni edición de roles. Tampoco `rol.actualizar` en el catálogo de permisos de `REQ-CORE` (solo `rol.leer`) |
| **El resolutor de permisos sigue siendo el provisional**: lee `effect`, ignora `scope` | `ADR-034 §2`, `permisos.md §5.6` | Sin cambios en 1.3. Todo lo que este paso siembre lleva `scope = 'todos'` |
| `config('audit.secret_attribute_patterns')` **ya incluye `*totp*` y `*recovery_code*`** desde 0.9 | `config/audit.php` | La redacción automática de auditoría ya cubre los nombres de columna de este paso. No es casualidad: 0.9 lo anticipó |

**Conclusión: 1.3 no está bloqueado por 1.5.** Lo que 1.5 aporta y aquí falta es *la interfaz de edición de roles* y *la creación/clonación de roles personalizados*, no el atributo ni su semántica. El punto de corte exacto se decide en `§C.2`, y no se esconde: se escribe, igual que 1.2 escribió que dejaba `REQ-AUTH-005` puntos 2-4 fuera y por qué.

### C.0.1 Dependencias no implementadas que sí condicionan el alcance

| Dependencia | Estado | Qué condiciona |
|-------------|--------|----------------|
| **Proveedor de SMS** | **No existe ninguno decidido en el proyecto.** No hay entrada en `PLAN-IMPLEMENTACION.md`, ni bloqueante en `memory.md`, ni variable de entorno, ni ADR. La única pieza que sí existe es el destino: `people.contact_phone` (nullable, **sin verificar**) | **El método SMS no se puede entregar en 1.3.** Se especifica su hueco y se prohíbe activarlo; no se inventa proveedor (`OPEN-AUTH-18`). Mismo trato que la geolocalización por IP en 1.2b (`OPEN-AUTH-13`) |
| **Correo transaccional** (`0.10c` / `OPEN-09`) | Pendiente. `OPEN-AUTH-07` ya lo declaró bloqueante **operativo** de este módulo en 1.2 | El método «código por correo» hereda exactamente la misma degradación que la recuperación de contraseña: en desarrollo va al *mailer* `log`, en producción no funciona hasta que `0.10c` se resuelva. **No añade una dependencia nueva**: usa la que el módulo ya tiene |
| **`1.5` (editor de roles y roles personalizados)** | No implementado, posterior en el plan | Ver `§C.2`. No bloquea |
| **`1.6` (`REQ-BO`)** | No implementado | `REQ-BO-007` («MFA obligatorio para todo administrador de plataforma, sin excepción y sin conmutador») es **suyo**, no de 1.3. `platform_admins` ni siquiera existe todavía (`permisos.md §5.3`) |
| **`1.7`/`1.8` (design system, layout)** | No implementados | Las pantallas de 1.3 siguen el mismo patrón de 1.2/1.2b: autónomas, sin `AppLayout`, sin depender del design system (`§C.11`) |
| **Librería TOTP en el backend** | No hay ninguna en `composer.json` | Dependencia nueva ⇒ `CLAUDE.md §1`. `OPEN-AUTH-19`, **cerrada por `ADR-041`**: `pragmarx/google2fa` `^9.1` |
| **Generador de QR en el frontend** | No hay ninguno en `package.json` | Dependencia nueva ⇒ `CLAUDE.md §1`. `OPEN-AUTH-20`, **cerrada por `ADR-041`**: `uqr` `^0.1.3` (`qrcode` rechazada) |

### C.0.2 Contradicciones detectadas

**Ninguna entre requisitos.** Se han comprobado una a una las afirmaciones de `REQ-AUTH-003` contra `RPERM-005`/`006`/`007`/`014`, `REQ-BO-007`, `RSEC-OWASP-002` y las invariantes de la sección 0.5, y son coherentes entre sí.

Lo que sí hay es **una colisión de orden en el plan de implementación** (`§C.0`) y **tres huecos que el requisito deja sin fijar** y que no puedo rellenar yo sin inventar:

1. **Desde cuándo se cuenta el período de gracia.** El requisito dice *«al activarse la obligatoriedad, el usuario dispone de un período de gracia»*. «Activarse» tiene tres disparadores distintos (el rol cambia, el rol se asigna a un usuario nuevo, el tenant restringe métodos y deja al usuario sin factor válido) y el requisito no distingue. `§C.4.8` toma una decisión razonada y `OPEN-AUTH-22` la deja explícita.
2. **Cuántos códigos de respaldo y de qué forma.** El requisito no dice número ni formato. `§C.4.3` fija un valor por defecto configurable y lo argumenta.
3. **Si un administrador de centro puede quitarse a sí mismo la obligación.** El requisito recomienda MFA obligatorio para administración de centro, pero recomendación no es cerrojo, y no dice nada de la autoedición. `OPEN-AUTH-23`.

Ninguno de los tres impide redactar. Los tres condicionan qué se implementa, y por eso están escritos aquí y no descubiertos a mitad de la implementación.

---

## C.1 Alcance del paso 1.3

### C.1.1 Entra

1. **Alta voluntaria de MFA por el propio usuario, para cualquier rol** (`REQ-AUTH-003`, primer y segundo punto): personal, familias y estudiantes, sin excepción de perfil.
2. **Método TOTP** (RFC 6238), completo: alta con secreto provisional, código QR y clave en texto, **confirmación con un código válido antes de activar**, verificación en el login, tolerancia de desfase de reloj y protección contra reutilización del mismo código.
3. **Método «código por correo»**, sobre la infraestructura de correo que el módulo ya tiene. Desactivado por defecto en el tenant (`§C.4.12`), con su debilidad escrita en voz alta (`§C.8`).
4. **Método SMS: solo el hueco.** El valor existe en el enumerado y en la configuración del tenant, y **una guarda impide activarlo** mientras no haya proveedor (`§C.7`, `OPEN-AUTH-18`).
5. **Códigos de respaldo** de un solo uso: generación al confirmar el primer factor, regeneración bajo demanda, consumo verificable e irreversible.
6. **Login en dos pasos**: `POST /auth/session` deja de crear siempre la sesión. Si hay segundo factor exigible, abre un **desafío** y no autentica a nadie (`§C.6`).
7. **Obligatoriedad por rol efectiva**: resolución más restrictiva en usuarios con varios roles (`RPERM-007`), período de gracia configurable con avisos, y **sesión restringida** («muro de alta») al agotarse.
8. **Edición de `mfa_required`** por el Administrador de Centro, acotada al atributo (`§C.2`).
9. **Vista previa de usuarios afectados** antes de guardar, y **estado de cumplimiento** consultable (obligados, inscritos, pendientes) — el requisito pide las dos cosas y se resuelven con el mismo endpoint.
10. **Restablecimiento de MFA por el administrador**, con motivo obligatorio, auditoría y notificación al usuario.
11. **Excepción temporal nominal**, con motivo y caducidad obligatoria. **No existe la exención permanente**, y el esquema lo impide (`NOT NULL` en la caducidad).
12. **Restricción de métodos por el tenant**.
13. **Pantallas** del segundo paso del login, del muro de alta y de autoservicio (`§C.11`).
14. **Auditoría** de todo lo anterior (`INV-003`), **sin ampliar el vocabulario de `audit_logs`** (`§C.10`).

### C.1.2 No entra, y por qué

| Fuera | Por qué |
|-------|---------|
| **Editor de roles personalizados: creación, clonación, edición de nombre y permisos** | Es `1.5` íntegro. 1.3 escribe **un solo atributo** de un rol que ya existe (`§C.2`) |
| **Herencia de `mfa_required` al clonar (`RPERM-006`)** | **No hay clonación de roles todavía.** Es una regla *sobre una operación que no existe*. Se deja escrita como contrato para 1.5 (`§C.12`) y se declara pendiente, no cumplida. Especificarla aquí sería especificar 1.5 |
| **Ámbitos de permiso distintos de `todos`** | El resolutor sigue ignorando `scope` (`permisos.md §5.6`). Sembrar otro ámbito hoy es un fallo de control de acceso silencioso |
| **MFA del backoffice de plataforma** (`REQ-BO-007`) | `1.6`. `platform_admins` no existe |
| **Reautenticación con segundo factor para operaciones sensibles** (*step-up*) | No está en `REQ-AUTH-003`. `REQ-BO-007` lo pide **para el backoffice**, que es 1.6. Inventarlo aquí es inventar requisitos |
| **«No volver a pedir el código en este dispositivo»** | `RN-AUTH-45` obliga a decidirlo explícitamente y no heredarlo de 1.2b: **se decide que no**. No está en el requisito, y convierte una cookie de 365 días en un salto permanente del segundo factor. Si se quiere, es un paso propio con su propia caducidad y su propia revocación |
| **MFA en el canje de invitación, en el restablecimiento y en el desbloqueo** | Ninguno de los tres crea sesión (`RN-AUTH-21`). No hay nada que proteger con un segundo factor donde no se entra |
| **WebAuthn / claves de seguridad / notificaciones push** | No están en `REQ-AUTH-003`. El requisito enumera tres métodos y son esos tres |
| **Pantalla de administración de `mfa_required` y del cumplimiento** | La **API entra**; la pantalla la monta 1.5/1.8 junto al editor de roles. Mismo criterio con el que 1.1 dejó todas sus pantallas para 1.8 (`OPEN-CORE-02`) |

### C.1.3 El tamaño de este paso, dicho antes de empezar

1.2 entregó 2 tablas nuevas, 2 modificaciones y 10 endpoints. 1.2b entregó 2 tablas y 3 endpoints. **1.3, tal como lo pide el requisito, son 6 tablas nuevas, 2 modificaciones de tablas existentes, 13 endpoints nuevos en este módulo más 1 en `REQ-CORE`, 3 endpoints modificados, 3 pantallas y 2 dependencias externas nuevas.** Es entre dos y tres veces cualquiera de los dos anteriores, y el plan lo dimensiona como *una* sesión.

No lo parto por mi cuenta —el alcance lo fija el usuario— pero sí dejo la línea de corte trazada y el argumento hecho, en `OPEN-AUTH-24`. El precedente es exacto: 1.2 se partió en 1.2/1.2b por esto mismo, con issue [#59](https://github.com/pirexia/plataforma-educativa/issues/59).

---

## C.2 El punto de corte con 1.5, decidido

**Decisión: 1.3 hace efectivo el atributo y entrega su escritura acotada. 1.5 hereda el editor completo.** En concreto:

| Pieza | Paso | Motivo |
|-------|------|--------|
| Columna `roles.mfa_required` | **Ya hecha (0.8)** | Existe y está sembrada |
| Lectura del atributo en `GET /roles` | **Ya hecha (1.1)** | `RoleResource` |
| **Resolución multi-rol más restrictiva** (`RPERM-007`) | **1.3** | Es lógica de autenticación, no de edición de roles. Sin ella el atributo no significa nada |
| **Cumplimiento**: gracia, avisos, muro de alta | **1.3** | Ídem |
| **`PATCH /api/v1/roles/{public_id}` aceptando *solo* `mfa_required`** | **1.3** | Ver abajo |
| Permiso `rol.actualizar` en el catálogo de `REQ-CORE` | **1.3** | Ver abajo |
| **Vista previa de usuarios afectados** (endpoint) | **1.3** | El requisito la pide junto al cambio; es una consulta de `REQ-AUTH`, no del editor |
| Pantalla del editor de roles que consume ese `PATCH` y esa vista previa | **1.5** | Es la interfaz que 1.5 construye |
| Creación de roles personalizados (`RPERM-005`) | **1.5** | Sin cambios |
| **Clonación con herencia de `mfa_required`** (`RPERM-006`) | **1.5** | La operación no existe. `§C.12` deja el contrato escrito |
| Edición del resto de atributos de un rol y de sus concesiones | **1.5** | Sin cambios |
| Resolutor granular de permisos con ámbitos | **1.5** | `ADR-034 §2`, sin cambios |

### C.2.1 Por qué 1.3 **tiene** que entregar la escritura del atributo, y no puede esperar a 1.5

Es el punto en el que me planto, y el argumento es operativo, no estético:

**Hoy hay dos roles sembrados con `mfa_required = true` que nada comprueba** (`permisos.md §5.4` lo dejó escrito precisamente para que no sorprendiera). El día que 1.3 haga efectivo el atributo, **`administrador_centro` y `soporte_plataforma` de todos los tenants existentes pasan a MFA obligatorio de golpe**. Si además no hay forma de cambiar el atributo hasta 1.5 —dos pasos después, y 1.5 está etiquetado *paso crítico*, es decir, largo— entonces durante todo ese tiempo:

- un centro cuyo administrador pierde el móvil y los códigos de respaldo depende de que otro administrador exista y le haga un restablecimiento; si solo hay uno, **el centro se queda sin administración** y la única salida es tocar la base de datos a mano;
- un centro que necesite desactivar la obligación por un problema real (un administrador sin dispositivo compatible) no tiene ningún mecanismo, ni siquiera la excepción temporal, porque la excepción es nominal y no resuelve un rol entero;
- y el requisito dice literalmente *«editable por el Administrador de Centro»*: entregar la obligatoriedad sin su interruptor no es una entrega parcial, es una entrega **con un cerrojo puesto y sin llave**.

Entregar solo el `PATCH` acotado cuesta un método de controlador, una regla de validación y una entrada en el catálogo de permisos. La alternativa cuesta un procedimiento manual sobre producción escrito en `RUNBOOK.md`. No es un empate.

### C.2.2 Por qué `PATCH /roles/{public_id}` acotado, y no un endpoint propio

La tentación es `PATCH /api/v1/roles/{public_id}/mfa-requirement` o, peor, un endpoint de `REQ-AUTH` sobre un recurso de `REQ-CORE`. Se descartan las dos:

- **Un sub-recurso** habría que retirarlo en 1.5 cuando el `PATCH` general lo absorba, y retirar un endpoint publicado es exactamente el ciclo *expand/contract* de `CLAUDE.md §9` aplicado a la API, con dos versiones de convivencia, por un atributo. Absurdo.
- **Un endpoint de `REQ-AUTH` sobre `roles`** rompe la propiedad del recurso: `roles` es de `REQ-CORE` (`INV-007`). El día que 1.5 escriba su editor tendría dos módulos escribiendo la misma tabla por dos rutas distintas.

Lo que se hace es: **`REQ-CORE` gana el método `update` en su propio `RolesController`, y en 1.3 ese método acepta exactamente un campo.** Cualquier otro campo en el cuerpo responde `422` (`ADR-038 §8`, semántica de `PATCH`). En 1.5 el mismo método admite el resto sin cambiar de ruta, sin cambiar de permiso y sin romper ningún cliente. Es *expand* puro sobre la superficie HTTP.

El permiso es **`rol.actualizar`**, declarado en `CoreServiceProvider::declaredPermissions()` (donde `rol` ya vive, hoy solo con `leer`) y concedido a `administrador_centro`. Es el mismo criterio con el que 1.2 gobernó `session_timeout_minutes` con `configuracion.actualizar` en vez de inventar `sesion.actualizar` (`permisos.md §4.1`): **el atributo vive en un recurso ajeno y se gobierna con el permiso de ese recurso.** La diferencia con 1.2 es que allí el permiso ya existía y aquí hay que declararlo; es una línea en la lista de `REQ-CORE`, no un mecanismo nuevo. Queda anotado en `OPEN-AUTH-21` por si el usuario prefiere que esa línea la escriba 1.5.

### C.2.3 Qué queda declarado como **no cumplido** al cerrar 1.3

Con el mismo carácter con el que 1.2b declaró que `REQ-AUTH-005` punto 4 quedaba cumplido a medias (`RN-AUTH-47`):

- **`RPERM-006` (herencia al clonar) queda pendiente.** No hay clonación. Al cerrar 1.3 no se puede afirmar que esté implementado.
- **`REQ-AUTH-003`, método SMS: no entregado** (`§C.7`).
- **`REQ-AUTH-003`, «desde el editor de roles»: entregada la API, no la pantalla.** El administrador puede cambiar el atributo por API; la interfaz llega con 1.5.

---

## C.3 Actores

| Actor | Qué puede hacer en 1.3 |
|-------|------------------------|
| **Cualquier usuario autenticado** (personal, familia, estudiante) | Ver su estado de MFA, dar de alta un factor, confirmarlo, generar y regenerar sus códigos de respaldo, y desactivar su factor **si ningún rol suyo lo exige** |
| **Usuario en proceso de login** | Superar el segundo factor del desafío abierto por su contraseña. **No está autenticado** mientras tanto (`§C.6`) |
| **Usuario obligado y no inscrito** | Dentro de la gracia: todo lo normal, con aviso. Agotada: **solo** dar de alta su factor o salir (`§C.4.9`) |
| **Administrador de Centro** | Cambiar `mfa_required` de un rol, ver la vista previa y el cumplimiento, restablecer el MFA de un usuario, y conceder/revocar excepciones temporales |
| **Administrador de plataforma** | **Nada aquí.** `REQ-BO-007` es 1.6 |
| **Sistema** | Evaluar la obligación en cada acceso, materializar el plazo de gracia, purgar desafíos y altas caducadas |

---

## C.4 Flujos

### C.4.1 Alta de un factor TOTP (`REQ-AUTH-003`, activación voluntaria)

1. El usuario, **autenticado**, abre `/cuenta/seguridad` y pide dar de alta TOTP.
2. `POST /api/v1/auth/mfa-enrollments` con `{"method": "totp"}`. El servidor comprueba que `totp` está entre los métodos que el tenant admite (`§C.4.12`) y que el usuario no tiene ya un factor confirmado de ese método.
3. Se genera un **secreto aleatorio de 20 bytes** de un generador criptográfico, se guarda **cifrado** (`RN-AUTH-55`) en una fila de `user_mfa_factors` con `confirmed_at = NULL` y `expires_at = ahora + AUTH_MFA_ENROLLMENT_TTL_MINUTES` (10 por defecto).
4. La respuesta `201` devuelve, **una sola vez**: el `public_id` del alta, el secreto en base32 **en texto** y la URI `otpauth://totp/...`. **No devuelve una imagen**: el QR lo pinta la SPA a partir de la URI (`OPEN-AUTH-20`).
   - El secreto en texto **no es un extra**: es el único camino para quien no puede escanear un código con la cámara. Sin él la pantalla no cumple WCAG 2.2 AA (`CLAUDE.md §10`), y con él el requisito de «Google Authenticator, Authy, Microsoft Authenticator» se cumple en los tres, que aceptan entrada manual.
5. El usuario introduce un código de 6 dígitos de su aplicación.
6. `POST /api/v1/auth/mfa-factors` con `{"enrollment": "<public_id>", "code": "123456"}`.
7. El servidor verifica el código contra el secreto del alta, con la ventana de `RN-AUTH-58`. **Si falla, el factor no se activa** y el intento cuenta contra el tope del alta (`RN-AUTH-59`).
8. Si acierta, **en una transacción**: `confirmed_at = ahora`, `expires_at = NULL`, y —si el usuario no tenía ningún factor confirmado antes— se generan sus **códigos de respaldo** (`§C.4.3`).
9. La respuesta `201` devuelve el factor y, **solo si se han generado**, la lista de códigos de respaldo **en claro**. Es la única vez que salen del servidor.
10. Se encola el aviso «se ha activado un segundo factor en tu cuenta» (`§C.4.13`).
11. El *observer* de 0.9 audita el `created` sobre `MfaFactor` con el secreto redactado (`§C.10`).

**Por qué la confirmación es obligatoria y no basta con «he escaneado el QR».** Un factor activado sin comprobar que el usuario puede producir un código deja cuentas cerradas por su propio dueño: si la aplicación no guardó el secreto, si el reloj del teléfono está desfasado o si se escaneó otro QR, el usuario descubre el problema en el siguiente login, ya bloqueado. El paso 7 es lo que convierte el alta en reversible.

### C.4.2 Alta de un factor «código por correo»

Igual que `§C.4.1`, con dos diferencias:

- En el paso 3 no hay secreto: se genera un **código de 6 dígitos**, se guarda **solo su hash SHA-256** (`RN-AUTH-09`) con `expires_at = ahora + AUTH_MFA_CODE_TTL_MINUTES` (10 por defecto) y **se encola** el correo (`INV-012`) al `users.email` del titular, en su idioma (`INV-009`).
- La respuesta `201` **no devuelve nada verificable**: solo el `public_id` del alta y el destino enmascarado (`a···z@e···e.com`). Devolver el código haría el segundo factor decorativo.

El destino **es siempre `users.email`**, el correo de acceso, y no `people.contact_email`. Son campos distintos y el segundo es de contacto, editable por más caminos: usarlo convertiría un cambio de dato de contacto en un cambio de credencial.

### C.4.3 Códigos de respaldo (`REQ-AUTH-003`)

1. Se generan **al confirmar el primer factor** del usuario y solo entonces. Regenerar es un acto explícito.
2. **`AUTH_MFA_RECOVERY_CODE_COUNT` códigos, 10 por defecto**, de **10 caracteres** de un alfabeto sin ambigüedades visuales (Crockford base32 sin `I`, `L`, `O`, `U`), agrupados `XXXXX-XXXXX` para poder leerlos en voz alta y transcribirlos. Diez códigos de diez caracteres son ~50 bits cada uno: adivinarlos no es un ataque viable, y el número no lo fija el requisito, así que es configurable y su valor por defecto está argumentado aquí y no escondido en el código.
3. Se persiste **solo el hash SHA-256** de cada uno, una fila por código (`datos.md §C.3`). No un `jsonb` con la lista: una fila por código es lo que permite marcar el consumo individualmente, indexar la búsqueda y auditar cuál se gastó.
   - **SHA-256 y no bcrypt**, a diferencia de la contraseña (`RN-AUTH-03`): un código de respaldo es un token de alta entropía generado por el servidor, exactamente como los de invitación, restablecimiento y desbloqueo, que `RN-AUTH-09` ya guarda como SHA-256. Además, bcrypt obligaría a verificar contra los diez hashes en cada intento —diez operaciones deliberadamente lentas por petición— y eso es un amplificador de denegación de servicio, no una mejora.
4. `POST /api/v1/auth/mfa-recovery-codes` regenera: **en una transacción**, borra las filas anteriores (usadas y sin usar) y crea el conjunto nuevo. Exige la **contraseña actual** en el cuerpo, por el mismo motivo que el cambio de contraseña de §4.8: una sesión secuestrada no puede fabricarse un juego de credenciales de repuesto.
5. Los códigos en claro salen **una sola vez**, en la respuesta que los genera. No hay ningún endpoint que los vuelva a mostrar, y no existe forma de recuperarlos: eso es lo que significa guardar solo el hash, y la pantalla tiene que decirlo antes de que el usuario cierre el diálogo.
6. Consumir un código lo marca `used_at` y **no lo borra**: la traza de que se usó un código de respaldo es información de seguridad, y `REQ-AUTH-003` exige auditarla explícitamente.
7. **Quedarse sin códigos no bloquea nada**: el factor sigue funcionando. `GET /auth/mfa` devuelve cuántos quedan sin usar para que la pantalla avise.

### C.4.4 Login en dos pasos (`REQ-AUTH-003`, `RN-AUTH-21`)

Es el flujo que 1.2 dejó preparado en §9: *«`POST /auth/session` es el único camino que crea sesión. 1.3 lo parte en dos.»* Aquí está partido.

**Paso 1 — credencial.** Idéntico a §4.2 hasta el punto 5 incluido: límite de tasa, comprobación de bloqueo, verificación de credencial, comprobación de estado. Nada de eso cambia.

**A partir de ahí**, con la credencial ya verificada y el usuario `activo`, se evalúa `MfaPolicy::resolve($user)` (`§C.4.7`):

| Situación | Qué ocurre |
|-----------|------------|
| El usuario **tiene al menos un factor confirmado y utilizable** | Se abre un **desafío** y se responde `202`. **No se crea sesión autenticada** (`§C.6`) |
| El usuario **no tiene factor** y **no está obligado** | Login normal: `200`, exactamente el comportamiento de 1.2 |
| El usuario **no tiene factor**, **está obligado** y **está dentro de la gracia** | Login normal: `200`, con el plazo en el recurso de perfil para que la pantalla avise (`§C.4.8`) |
| El usuario **no tiene factor**, **está obligado** y **la gracia ha vencido** | Login **restringido**: `200`, sesión creada, pero solo alcanzan los endpoints de `§C.4.9` |
| El usuario tiene una **excepción temporal viva** | Se trata como no obligado mientras dure (`§C.4.11`) |

**Apertura del desafío** (primera fila):

1. Se crea una fila en `mfa_challenges` con el `user_id`, el `session_id` **actual** (el de la sesión anónima que la SPA ya tiene por la cookie CSRF), el método elegido, `expires_at = ahora + AUTH_MFA_CHALLENGE_TTL_MINUTES` (5 por defecto) y `attempts = 0`.
2. El método elegido es el **preferido** del usuario si lo hay, y si no, el único que tenga; con varios, TOTP gana (no requiere entrega). El usuario puede cambiarlo con `POST /auth/mfa-challenges` (`§C.4.4.1`).
3. Si el método requiere entrega (correo), se genera el código, se guarda su hash y **se encola** el envío.
4. Se escribe una fila en `login_attempts` con `outcome = 'pendiente_segundo_factor'` (`§C.4.4.2`).
5. Respuesta **`202`** con el recurso del desafío: método en curso, métodos alternativos disponibles, destino enmascarado si procede, `expires_at`, y si el usuario tiene códigos de respaldo sin usar. **Sin ningún token**: la única credencial del desafío es la cookie de sesión que el navegador ya lleva (`§C.6`).

**Paso 2 — segundo factor.**

6. `POST /api/v1/auth/mfa-verifications` con `{"code": "123456"}` o `{"recovery_code": "XXXXX-XXXXX"}`, con CSRF (`RN-AUTH-29`).
7. El servidor busca el desafío **vivo, no consumido y ligado al `session_id` de la petición**. Si no existe, ha caducado o ya se consumió ⇒ `410`, cuerpo idéntico en los tres casos (§4.7).
8. Verifica el código:
   - **TOTP**: contra el secreto descifrado, ventana de `RN-AUTH-58`, comparación en tiempo constante, y rechazo si el paso de tiempo ya se consumió (`RN-AUTH-58`).
   - **Correo**: contra el hash del código entregado, comparación en tiempo constante.
   - **Código de respaldo**: búsqueda por `(tenant_id, user_id, sha256(código))` sobre filas sin usar.
9. **Fallo**: `attempts + 1`, fila en `login_attempts` con `outcome = 'segundo_factor_invalido'`, incremento del contador de fallos consecutivos de `(tenant_id, email)` — el **mismo** contador del bloqueo de `RN-AUTH-14` (`§C.4.4.2`) — y respuesta `401` genérica. Alcanzado `AUTH_MFA_MAX_ATTEMPTS` (5), el desafío queda consumido y hay que volver a empezar por la contraseña.
10. **Acierto**, en este orden exacto y en una transacción:
    1. Se marca el desafío `consumed_at`, y el código de respaldo `used_at` si fue eso lo que se usó.
    2. Se actualiza `last_used_at` y, en TOTP, `last_used_step` del factor.
    3. `$request->session()->regenerate()` (`RN-AUTH-32`).
    4. `Auth::guard('web')->login($user)`.
    5. `AuditRecorder::record($user, 'login')` — **después** de `login()`, o el actor sale `anonymous` (`ADR-039 §4.5`).
    6. `pge_tenant_id` y `pge_last_activity_at` en el *payload*.
    7. Registro en `user_sessions` con el `session_id` **posterior** a la regeneración, y detección de dispositivo (`§B.4.1`). **Esta es la trampa que `§B.9` dejó anotada y aquí queda resuelta: el registro se hace después de regenerar, igual que en 1.2b, y como la fila nace ya con el identificador definitivo no hay ninguna que actualizar.**
    8. Fila en `login_attempts` con `outcome = 'exito'`, que es lo que **pone a cero** el contador de fallos consecutivos.
    9. Si se usó un código de respaldo, se encola el aviso al titular (`§C.4.13`).
11. Respuesta `200` con **el mismo recurso que `GET /me`**, igual que el login de un paso.

#### C.4.4.1 Cambiar de método o reenviar el código

`POST /api/v1/auth/mfa-challenges` con `{"method": "email"}` sobre un desafío vivo: cambia el método en curso, genera y encola un código nuevo, **no reinicia el contador de intentos** y **no prolonga la caducidad del desafío**. Límite propio de reenvíos (`operacion.md §C.6`).

Que el reenvío no reinicie ni el contador ni el plazo es deliberado: si lo hiciera, un atacante con la contraseña tendría intentos infinitos sin más coste que pulsar «reenviar».

#### C.4.4.2 Los fallos de segundo factor alimentan el mismo bloqueo, y el éxito de contraseña ya no lo pone a cero

**Es la regla con más consecuencias de este paso y el error más fácil de cometer.**

Hoy `LoginService::attempt()` llama a `$this->attempts->recordSuccess()` en cuanto la contraseña es correcta y el usuario está activo. Con el login en dos pasos, dejarlo así abre un agujero:

> Un atacante que ya tiene la contraseña correcta reenvía el paso 1 antes de cada intento de segundo factor. Cada paso 1 registra un `exito` que **pone a cero el contador de fallos consecutivos**, y el bloqueo de cinco intentos de `RN-AUTH-14` **nunca dispara**. El atacante obtiene intentos ilimitados contra un código de seis dígitos.

Por eso:

1. **`recordSuccess()` se mueve al final del paso 2**, y solo se llama cuando la sesión se ha creado de verdad. Un login completo es lo único que pone el contador a cero (`RN-AUTH-63`).
2. El paso 1 superado pero pendiente de segundo factor escribe `outcome = 'pendiente_segundo_factor'`, que **no toca el contador**.
3. Cada fallo de segundo factor escribe `outcome = 'segundo_factor_invalido'` y **sí incrementa** el contador, exactamente igual que un fallo de contraseña.

**Por qué el mismo contador y no uno separado.** La alternativa razonable es un cerrojo propio del segundo factor, y se descarta por dos motivos: (a) reutilizar `account_lockouts` mantiene una sola respuesta `423` y una sola entidad que el administrador ya sabe listar y levantar, en vez de dos mecanismos con dos pantallas; (b) **no abre ningún vector de denegación de servicio nuevo**, porque para llegar al segundo factor hay que haber acertado la contraseña, y quien no la acierta ya podía provocar el bloqueo por la vía de 1.2 (`RN-AUTH-15`, bloqueo fantasma incluido).

El coste que sí tiene, y hay que aceptarlo con los ojos abiertos: **un usuario con el reloj del móvil desfasado agota cinco intentos y bloquea su cuenta 15 minutos**. Se mitiga con la ventana de desfase de `RN-AUTH-58` (±1 paso, ±30 s) y con que la pantalla diga explícitamente «comprueba la hora de tu dispositivo» tras el segundo fallo, no con relajar el contador.

### C.4.5 Uso de un código de respaldo

Es el paso 2 con `{"recovery_code": ...}` en vez de `{"code": ...}`. Tres reglas propias:

1. **Un código de respaldo vale para cualquier método**: es precisamente el camino de quien no tiene acceso a su factor.
2. **Se consume aunque el login se interrumpa después.** Se marca `used_at` en la misma transacción que crea la sesión; si esa transacción falla, no se consume. Lo que no se hace es «reservarlo» y devolverlo: un código medio usado es un código reutilizable.
3. **Su uso se audita y se notifica al titular** (`REQ-AUTH-003`, «uso de código de respaldo queda auditado»). El aviso es la única señal que tiene alguien de que otro entró con un código que él guardaba en un cajón.

### C.4.6 Desactivación por el propio usuario

1. `DELETE /api/v1/auth/mfa-factors/{public_id}`, con sesión, CSRF y **contraseña actual en el cuerpo** (mismo argumento que `§C.4.3` punto 4).
2. **Si algún rol del usuario exige MFA y no tiene excepción viva ⇒ `409`.** Es literal del requisito: *«Un usuario nunca puede desactivar su MFA si alguno de sus roles lo exige»* (`RN-AUTH-61`).
3. Si era su **último** factor confirmado, se borran también sus códigos de respaldo: no protegen nada y son material de credencial vivo.
4. Se encola el aviso al titular.
5. Borrado **lógico** (`INV-004`): la fila conserva `deleted_at` y el *observer* audita el `deleted`. El secreto sigue cifrado en la fila borrada hasta que la purga la retire (`datos.md §C.7`).

### C.4.7 Obligatoriedad: cómo se resuelve (`RPERM-007`, `RPERM-014`)

`MfaPolicy::resolve(User $user): MfaObligation` es la **única** función del sistema que decide si alguien está obligado. Su resultado es una de tres cosas: `NoObligado`, `EnGracia(deadline)` o `Exigible`.

El cálculo, en este orden:

1. **¿Tiene una excepción temporal viva?** (`user_mfa_exemptions`, `expires_at > ahora`, `revoked_at IS NULL`) ⇒ `NoObligado`.
2. **¿Alguno de sus roles vivos tiene `mfa_required = true`?** Si ninguno ⇒ `NoObligado`.
   - **Este es el «criterio más restrictivo» de `RPERM-007`, y es un `OR`, no un `AND`**: basta un rol. Se implementa como una sola consulta con `EXISTS` sobre `role_user ⋈ roles`, con el predicado de tenant explícito (`RN-AUTH-07`), **no** cargando los roles en memoria y recorriéndolos — una colección cargada puede venir de una relación mal filtrada.
   - Es coherente con la resolución de permisos de `RPERM-007` («deny sobrescribe allow») sin ser la misma regla: allí el valor restrictivo es la denegación; aquí, la exigencia.
3. **¿Tiene un factor confirmado y utilizable** (método permitido hoy por el tenant, `§C.4.12`)**?** Si sí ⇒ `NoObligado` — está cumpliendo.
4. Si no, se materializa o se lee su fila de `user_mfa_obligations` y se compara `grace_deadline_at` con ahora ⇒ `EnGracia` o `Exigible`.

**El resultado se cachea por petición, nunca entre peticiones.** Un rol puede cambiar, una excepción puede revocarse y un factor puede darse de alta en la petición anterior; una caché con TTL convertiría cualquiera de las tres en «efectivo dentro de cinco minutos», y en un control de acceso eso es un fallo, no una optimización.

### C.4.8 Período de gracia: cuándo empieza y qué pasa mientras

**El plazo empieza cuando empieza la obligación, no en el primer acceso posterior.** La obligación empieza en el primer instante en que `MfaPolicy` evalúa a ese usuario como obligado sin factor y no encuentra fila en `user_mfa_obligations`: en ese momento se crea la fila con `obligated_since = ahora` y `grace_deadline_at = ahora + tenant_settings.mfa_grace_period_days` (**7 por defecto**, literal del requisito).

Los tres disparadores que el requisito no distingue quedan cubiertos por el mismo mecanismo, sin código por caso:

| Disparador | Qué pasa |
|------------|----------|
| El administrador pone `mfa_required = true` en un rol | Un trabajo encolado (`operacion.md §C.4`) materializa la fila de los usuarios afectados **en ese momento**, sin esperar a que entren |
| Se asigna ese rol a un usuario nuevo | La fila se materializa en su siguiente evaluación (login o petición autenticada) |
| El tenant restringe métodos y el factor del usuario deja de ser utilizable | Ídem: `MfaPolicy` deja de verle factor válido, y la fila se materializa o se reabre |

**Qué ve el usuario mientras dura**: el recurso de perfil (`GET /me` y la respuesta del login) lleva un bloque `mfa` con `obligated`, `enrolled`, `grace_deadline_at` y `days_remaining`. La pantalla pinta un aviso **en cada acceso** con el plazo restante y un enlace a `/cuenta/seguridad`. Eso es *«avisos en cada acceso»* del requisito, y se resuelve con el recurso que la SPA ya pide, sin endpoint nuevo y sin correo.

**Qué pasa al vencer**: `§C.4.9`.

**Qué pasa si el usuario cumple**: al confirmar su primer factor se cierra la fila (`resolved_at`). Si más tarde vuelve a quedar sin factor, se abre una fila **nueva** con plazo completo: el historial de obligaciones queda, no se sobrescribe.

**Sin correo de aviso de inicio de obligación.** El requisito pide avisos «en cada acceso», que es lo que se entrega. Añadir un envío masivo a todos los usuarios de un rol cada vez que un administrador pulsa un interruptor es una decisión de producto con coste real (`0.10c` sin resolver, `RMT-005` limita envíos por tenant) y no está pedida.

### C.4.9 Sesión restringida: el muro de alta

Cuando `MfaPolicy` devuelve `Exigible`, el usuario **sí obtiene sesión** —restringida— y no un rechazo. Es contraintuitivo y hay que argumentarlo: **para dar de alta un factor hay que estar autenticado**. Un usuario obligado y sin factor al que se le niega la sesión no tiene ningún camino hacia el alta, y el requisito pide justo lo contrario: *«el login desemboca en una pantalla de alta de MFA de la que no se puede salir sin completar el registro»*. Desembocar en una pantalla exige haber entrado.

La restricción la aplica el *middleware* `RequireMfaEnrollment`, en la cadena del grupo `/api/v1`, **después** de `VerifySessionTenant` y de la comprobación de inactividad:

- **Permitido**: `GET /me`, `GET /auth/mfa`, `POST /auth/mfa-enrollments`, `POST /auth/mfa-factors`, `DELETE /auth/session`, `GET /auth/csrf-cookie`, y los endpoints públicos de marca e idioma que la pantalla necesita para pintarse.
- **Todo lo demás**: `403` con el tipo nuevo `urn:pge:error:mfa-enrollment-required` (`api.md §C.1.1`).

Reglas del muro:

1. **La lista es una lista blanca, no negra** (`INV-002`). Un endpoint nuevo de cualquier módulo queda bloqueado por defecto, que es el comportamiento correcto.
2. **`DELETE /auth/session` está siempre permitido.** Un muro del que no se puede salir ni cerrando sesión es un secuestro, no un control.
3. **`POST /auth/password-changes` no está permitido**, y merece decirse: el usuario obligado puede completar su alta o irse, no reorganizar su cuenta.
4. **La sesión no se destruye al vencer el plazo a mitad de trabajo.** El *middleware* evalúa en cada petición autenticada, así que un usuario en gracia que cruza la medianoche del vencimiento recibe el `403` en su siguiente petición y la SPA lo lleva al muro. **Se le pierde lo que estuviera escribiendo**, y no hay forma de evitarlo del todo; se mitiga porque el aviso lleva siete días apareciendo en cada acceso.
5. **El muro no exime del segundo factor de nadie más.** Un usuario con factor confirmado nunca pasa por aquí: pasa por `§C.4.4`.

### C.4.10 Restablecimiento por el administrador (`REQ-AUTH-003`)

1. `POST /api/v1/mfa-resets` con `{"user": "<public_id>", "reason": "..."}`, permiso `mfa.eliminar`.
2. **`reason` es obligatorio** y tiene longitud mínima (`RN-AUTH-66`). No es burocracia: es lo único que queda escrito de por qué se devolvió el acceso a una cuenta protegida.
3. **La «verificación previa de identidad» del requisito es un procedimiento humano, no una llamada HTTP.** No se modela como una casilla «he verificado la identidad», porque una casilla obligatoria que siempre se marca no verifica nada y además da cobertura documental a quien no verificó. Lo que se hace es: el motivo obligatorio queda auditado con nombre del administrador, el titular recibe una notificación que no se puede desactivar, y el procedimiento (qué se considera verificación aceptable en un centro) se documenta en `operacion.md §C.9` y en el manual de administración.
4. Efecto, en una transacción: se borran **lógicamente** todos los factores del usuario, se borran sus códigos de respaldo, **se revocan todas sus sesiones** con `end_reason = 'cambio_credencial'`, y si sigue obligado se abre una obligación nueva con plazo de gracia completo.
   - **`cambio_credencial` y no un valor nuevo**: un segundo factor **es** una credencial, y el valor existente describe con exactitud lo que pasó. Esto **no** es el caso del issue [#61](https://github.com/pirexia/plataforma-educativa/issues/61) —allí se reutilizó un valor que no correspondía por no tener el correcto—; aquí el valor correcto ya está en el `CHECK`. Ampliar el enumerado de siete a ocho para no reutilizar un valor que encaja sería el error contrario.
5. Se **encola** la notificación al usuario afectado (`INV-012`), en su idioma, **sin enlace accionable** (mismo criterio que `RN-AUTH-50`).
6. El *observer* audita los `deleted`; el motivo viaja en el registro del restablecimiento (`§C.10`).
7. **Un administrador no puede restablecerse a sí mismo** (`RN-AUTH-67`). Si pudiera, `mfa.eliminar` sería un botón de «quítame el segundo factor» y toda la obligatoriedad de `REQ-AUTH-003` se caería por ahí. La salida para un administrador que pierde su factor y sus códigos es otro administrador — y si no hay otro, es un procedimiento operativo documentado, no un endpoint.

### C.4.11 Excepción temporal nominal — diferido a `1.3b` (`REQ-AUTH-003`)

**Ninguno de los tres endpoints de este apartado se entrega en `1.3`.** `exencion_mfa` (`GET`/`POST`/`DELETE /mfa-exemptions`) quedó en `1.3b` en la partición de `OPEN-AUTH-24` (`§C.16`), y la corrección del 2026-08-27 que restauró `GET /mfa-compliance/users` en `1.3` **no** afectó a esta sección — son dos decisiones distintas (`api.md §C.1`, `permisos.md §C.1`). Lo que sigue es la especificación funcional para cuando `1.3b` la implemente, no una descripción de lo que existe hoy; ningún permiso, tabla ni endpoint de este apartado está activo en `1.3` salvo `user_mfa_exemptions`, cuya tabla ya existe porque `MfaPolicy::resolve()` la consulta (`§C.4.7` punto 1) pero que **ningún endpoint de 1.3 escribe todavía** (`permisos.md §C.1`).

1. `POST /api/v1/mfa-exemptions` con `{"user": ..., "reason": ..., "expires_at": ...}`, permiso `exencion_mfa.crear`.
2. **`expires_at` es obligatorio** y como máximo `AUTH_MFA_MAX_EXEMPTION_DAYS` (90 por defecto) en el futuro. *«No existe la exención permanente»* es literal del requisito, y lo garantiza el esquema (`NOT NULL` + `CHECK`), no una validación de aplicación.
3. Mientras vive, `MfaPolicy` devuelve `NoObligado` (`§C.4.7`, paso 1). Consecuencia que hay que aceptar y escribir: **durante la excepción el usuario también puede desactivar su factor**, porque no está obligado. Es exactamente para lo que sirve una excepción («usuario sin dispositivo compatible»), y ocultarlo detrás de una regla adicional daría una excepción que no exceptúa.
4. Al caducar o revocarse, la obligación vuelve **con plazo de gracia completo**, no con el remanente del anterior.
5. `DELETE /api/v1/mfa-exemptions/{public_id}` la revoca antes de tiempo (`exencion_mfa.eliminar`). La fila se conserva con `revoked_at`/`revoked_by`: es traza, como un bloqueo levantado.
6. Todo el ciclo lo audita el *observer* sin código propio, porque **la excepción es una entidad**, no un evento (`§C.10`).

### C.4.12 Métodos que el tenant admite (`REQ-AUTH-003`)

1. `tenant_settings.mfa_allowed_methods`, `jsonb`, valor por defecto **`["totp"]`**.
2. Se edita en `PATCH /tenant/settings`, grupo `security`, con `configuracion.actualizar` — el mismo sitio y el mismo permiso que `session_timeout_minutes` (`api.md §6`), por el argumento de `permisos.md §4.1`.
3. **`totp` no se puede quitar** (`RN-AUTH-69`). Es el único método sin dependencia externa y el único que funciona para alguien sin buzón alcanzable; un tenant que se quedara solo con `email` y perdiera el correo transaccional dejaría a todos sus usuarios obligados sin ninguna forma de cumplir.
4. **`sms` no se puede poner** mientras no haya proveedor configurado (`§C.7`). Guarda de aplicación con `422` y mensaje explícito, no un fallo silencioso en el primer envío.
5. **Quitar un método invalida los factores existentes de ese método**: dejan de ser utilizables en el login y dejan de contar como cumplimiento, con lo que sus titulares vuelven a estar obligados **con plazo de gracia completo** (`§C.4.8`). La alternativa —dejarlos funcionando— convierte la restricción en un letrero decorativo.
6. Por defecto, `["totp"]` significa que **el correo como segundo factor está desactivado salvo que el centro lo active a propósito**, habiendo leído lo que protege y lo que no (`§C.8`).

### C.4.13 Avisos al titular

Tres, todos encolados (`INV-012`), en los cuatro idiomas (`INV-009`) y **sin enlace accionable** (`RN-AUTH-50`):

| Cuándo | Por qué |
|--------|---------|
| Se activa un segundo factor | Si no fue el titular, alguien con su contraseña acaba de echar el cerrojo desde dentro. Es la señal más urgente del módulo |
| Se desactiva un segundo factor, o el administrador lo restablece | El requisito exige la notificación del restablecimiento; la desactivación es la misma situación con otro actor |
| Se usa un código de respaldo para entrar | Es el único aviso de que alguien entró sin el factor |

**Estos tres avisos son una extensión del patrón que este módulo ya fijó** con `SendPasswordChangedEmail` (§4.8, punto 7), no un requisito nuevo: el segundo y el tercero los pide `REQ-AUTH-003` de forma explícita o casi; el primero es la misma defensa aplicada al mismo tipo de hecho. Se dice aquí para que la revisión no lo tome por invención (`CLAUDE.md §11`) y para que el usuario pueda rechazarlo si no lo comparte.

---

## C.5 Reglas de negocio nuevas

Continúan la numeración de §5 y §B.5. Las 51 anteriores siguen en vigor sin cambios, con **una precisión** sobre `RN-AUTH-14` que introduce `RN-AUTH-63`.

| ID | Regla |
|----|-------|
| **Sesión y segundo factor** | |
| `RN-AUTH-52` | **Un usuario con segundo factor exigible no está autenticado hasta superarlo.** Entre el paso 1 y el paso 2 no se llama a `Auth::login()`, no se escribe `pge_tenant_id` en el *payload*, no se crea fila en `user_sessions` y ninguna petición a ningún endpoint del producto se considera autenticada. La única cosa que existe es una fila de `mfa_challenges` y la sesión **anónima** que ya había (`§C.6`). |
| `RN-AUTH-53` | El desafío se liga al `session_id` de la petición que lo abrió y **solo es verificable desde esa misma sesión**. No se emite ningún token, identificador ni cabecera que permita completarlo desde otro cliente. Un `public_id` de desafío presentado desde otra sesión responde `410`, igual que uno inexistente. |
| `RN-AUTH-54` | El desafío caduca a los `AUTH_MFA_CHALLENGE_TTL_MINUTES` (5 por defecto), muere al consumirse y muere al agotar `AUTH_MFA_MAX_ATTEMPTS` (5). Reenviar el código **no** prolonga la caducidad ni reinicia los intentos (`§C.4.4.1`). |
| **Secretos y códigos** | |
| `RN-AUTH-55` | El secreto TOTP se almacena **cifrado con `APP_KEY`** (cast `encrypted` del framework), nunca en claro y nunca hasheado — hay que poder recuperarlo para verificar. Sale del servidor **una sola vez**, en la respuesta del alta, y ninguna otra respuesta, registro de auditoría, log ni *payload* de trabajo encolado lo contiene. |
| `RN-AUTH-56` | Los códigos de respaldo y los códigos entregados por correo o SMS se persisten **solo como hash SHA-256** (`RN-AUTH-09`). Los de respaldo salen en claro **una vez**, al generarse; los entregados, nunca. |
| `RN-AUTH-57` | Un código de respaldo es de **un solo uso**, garantizado por `used_at` comprobado y escrito **en la misma transacción** que crea la sesión. La fila **no se borra** al consumirse: la traza es información de seguridad. |
| `RN-AUTH-58` | La verificación TOTP admite **±1 paso de 30 segundos** (ventana de 3 pasos) y **rechaza un paso ya consumido** por ese factor (`last_used_step`). Sin lo segundo, un código capturado sirve durante 90 segundos. Toda comparación de código, de cualquier método, es en **tiempo constante**. |
| `RN-AUTH-59` | Un alta sin confirmar caduca a los `AUTH_MFA_ENROLLMENT_TTL_MINUTES` (10 por defecto) y admite como mucho `AUTH_MFA_MAX_ATTEMPTS` intentos de confirmación. **Un factor no confirmado no protege, no cumple la obligación y no aparece como factor** en ninguna respuesta. |
| `RN-AUTH-60` | Regenerar códigos de respaldo y desactivar un factor exigen la **contraseña actual** en el cuerpo. Dar de alta un factor no. |
| **Obligatoriedad** | |
| `RN-AUTH-61` | Un usuario **no puede desactivar** su último factor utilizable si `MfaPolicy` lo declara obligado y no tiene excepción viva ⇒ `409`. Literal de `REQ-AUTH-003`. |
| `RN-AUTH-62` | La obligación se resuelve **solo** en `MfaPolicy::resolve()`, con una consulta `EXISTS` sobre `role_user ⋈ roles` con predicado de tenant explícito. **Basta un rol con `mfa_required = true`** (`RPERM-007`, criterio más restrictivo). Ningún otro punto del código decide esto, y el resultado **no se cachea entre peticiones**. |
| `RN-AUTH-63` | **El contador de fallos consecutivos de `RN-AUTH-14` se pone a cero solo con un login completo**, nunca con un paso 1 superado. Un paso 1 pendiente de segundo factor escribe `outcome = 'pendiente_segundo_factor'` y no lo toca. Es la corrección explícita del comportamiento de 1.2, donde `recordSuccess()` se llamaba al verificar la contraseña (`§C.4.4.2`). |
| `RN-AUTH-64` | Un fallo de segundo factor —código o código de respaldo— **incrementa el mismo contador** que un fallo de contraseña y puede provocar el bloqueo de `RN-AUTH-14`. Se registra con `outcome = 'segundo_factor_invalido'`, distinto de `credenciales_invalidas`, para poder separarlos en telemetría. |
| `RN-AUTH-65` | El plazo de gracia empieza **cuando empieza la obligación**, no en el primer acceso posterior, y se materializa en `user_mfa_obligations` la primera vez que se evalúa. Su duración es `tenant_settings.mfa_grace_period_days` (7 por defecto). Al cumplir se cierra la fila; una obligación nueva abre una fila nueva con plazo completo, sin sobrescribir la anterior. |
| **Administración** | |
| `RN-AUTH-66` | El restablecimiento de MFA exige **motivo** de al menos 10 caracteres, queda auditado con el administrador que lo ejecutó y **notifica al usuario afectado**. Sin motivo no hay operación (`422`). En `1.3` solo aplica al restablecimiento (`POST /mfa-resets`): la mitad de la regla sobre la excepción temporal queda pendiente de `1.3b`, que es quien entrega `POST /mfa-exemptions` (`§C.4.11`). |
| `RN-AUTH-67` | **Nadie restablece su propio MFA**, tenga el permiso que tenga (`403`). Sin esta regla, `mfa.eliminar` es un interruptor de apagado de la obligatoriedad. La mitad de la regla sobre exenciones («ni se exime a sí mismo») queda pendiente de `1.3b`, que es quien entrega `POST /mfa-exemptions` (`§C.4.11`, `permisos.md §C.8`). |
| `RN-AUTH-68` | **Diferida a `1.3b`** (`§C.4.11`): la excepción temporal llevará **caducidad obligatoria**, como máximo `AUTH_MFA_MAX_EXEMPTION_DAYS` (90) en el futuro, garantizada por `NOT NULL` y `CHECK` en el motor. **No existirá la exención permanente.** Ningún endpoint de `1.3` la exige todavía porque `POST /mfa-exemptions` no existe en `1.3`. |
| `RN-AUTH-69` | `tenant_settings.mfa_allowed_methods` es un array no vacío que **siempre contiene `totp`**, y **no admite `sms`** mientras no haya proveedor configurado. Quitar un método deja de admitir sus factores en el login y reabre la obligación de sus titulares con plazo completo. |
| `RN-AUTH-70` | `roles.mfa_required` solo se escribe por `PATCH /api/v1/roles/{public_id}` con permiso `rol.actualizar`. En 1.3 ese endpoint **no acepta ningún otro campo**: cualquier otra clave en el cuerpo responde `422`. |
| **Transversales** | |
| `RN-AUTH-71` | La cookie `pge_device` **no salta ningún control de segundo factor**, ni total ni parcialmente. Decisión explícita de este paso, exigida por `RN-AUTH-45`: un dispositivo reconocido sirve para no alertar, y para nada más. |
| `RN-AUTH-72` | Ninguna respuesta anónima nueva revela si una cuenta existe, si tiene MFA o cuál. El `202` del desafío solo lo obtiene quien ya acertó la contraseña (`§C.6.2`), y el `410` es idéntico para desafío inexistente, caducado, consumido y de otra sesión. |
| `RN-AUTH-73` | Ningún endpoint de este paso admite un `user_id` en el cuerpo salvo el **de administración** (`POST /mfa-resets`), que lo recibe como `public_id` y lo resuelve con predicado de tenant explícito. El autoservicio actúa **siempre** sobre el portador de la cookie (`permisos.md §C.4`). `POST /mfa-exemptions` seguirá la misma regla cuando `1.3b` lo entregue (`§C.4.11`), pero no existe en `1.3`. |
| `RN-AUTH-74` | El vocabulario de `audit_logs` **no se amplía** en este paso. Todo lo auditable de 1.3 es creación, modificación o borrado de una entidad real (`§C.10`). |

---

## C.6 Por qué el login parcial no crea sesión autenticada

Es la decisión de seguridad del paso y va con sus alternativas, como `§B.6` hizo con la detección de dispositivo.

### C.6.1 Qué existe entre el paso 1 y el paso 2

Exactamente dos cosas:

1. **La sesión anónima que ya había.** La SPA obtiene la cookie CSRF antes de enviar el formulario (`§4.7`), y eso inserta una fila en `sessions` sin `user_id`. Es la misma sesión anónima que 1.2b ya describió: *«`GET /auth/csrf-cookie` sin login posterior ⇒ hay fila en `sessions` y **ninguna** en `user_sessions`»* (`CA-AUTH-081`). No se crea nada nuevo.
2. **Una fila en `mfa_challenges`** ligada a ese `session_id`.

Lo que **no** existe: `Auth::id()` sigue devolviendo `null`, el *payload* no tiene `pge_tenant_id`, no hay fila en `user_sessions`, y ningún *middleware* de autorización ve un usuario. **Un endpoint del producto llamado en ese estado responde `401` como cualquier petición anónima**, sin excepciones ni listas.

### C.6.2 Las tres alternativas, y por qué esta

| Opción | Por qué no |
|--------|------------|
| **Autenticar y marcar la sesión como «pendiente de MFA»** | Es la forma más común de equivocarse. Basta con que **un solo** camino —un *middleware* nuevo, un comando, un endpoint de otro módulo, un `Auth::user()` en un *listener*— no compruebe la marca para que el segundo factor no exista. `INV-002` dice denegar por defecto, y una sesión autenticada con una bandera restrictiva es lo contrario: permitir por defecto y restringir en cada sitio que se acuerde |
| **Emitir un token de desafío al cliente** (cabecera, cuerpo o `localStorage`) | Crea una credencial portadora nueva que no existía, con su propio robo, su propio almacenamiento en el navegador y su propia caducidad. `ADR-025` prohíbe expresamente tokens de sesión en el almacenamiento del navegador, y aunque este no fuera «de sesión», la SPA tendría que guardarlo en algún sitio entre las dos peticiones |
| **Estado solo en el *payload* de la sesión, sin tabla** | Es lo que hace el andamiaje estándar del framework, y para TOTP puro bastaría. No basta aquí: el método por correo exige guardar el hash del código entregado, su caducidad y el contador de reenvíos, y eso es estado del servidor con vida propia. Tener dos mecanismos distintos según el método —*payload* para TOTP, tabla para el resto— es peor que tener uno |

**La elegida** —tabla, ligada a la sesión, sin token— tiene las tres propiedades que importan: la credencial que autoriza el paso 2 es **la cookie de sesión que el navegador ya tiene** (`httpOnly`, `Secure`, host-only, con CSRF), no hay ningún objeto nuevo que robar, y el estado pendiente es una fila que se puede consultar, purgar y auditar en vez de un campo escondido en un *payload* serializado.

### C.6.3 Qué revela el `202`, y a quién

El `202` dice «esta cuenta existe, está activa y tiene segundo factor». **Solo lo recibe quien ha acertado la contraseña**, así que no es un oráculo de enumeración: quien acierta la contraseña ya sabía que la cuenta existe. Las respuestas de `§4.7` siguen intactas para todos los demás casos, y una contraseña incorrecta sigue devolviendo el `401` genérico sin decir nada del MFA.

Lo que sí hay que cuidar, y por eso está escrito: **el `202` no debe llegar antes que el `401` en tiempo observable**. Abrir el desafío hace trabajo (insertar una fila, quizá encolar un correo) que un login fallido no hace. La diferencia es de milisegundos frente a una comparación bcrypt de coste 12, que domina el tiempo de respuesta; se acepta y se anota en `operacion.md §C.8` como cosa a medir, no como cosa a suponer.

---

## C.7 SMS: por qué el tercer método no se entrega

`REQ-AUTH-003` enumera tres métodos. **Se entregan dos.**

**No hay proveedor de SMS decidido en el proyecto.** Verificado: no aparece en `PLAN-IMPLEMENTACION.md` (ni siquiera en los pasos de infraestructura pendientes `0.10b`-`0.10e`), no hay bloqueante en `memory.md`, no hay ADR, no hay variable de entorno, no hay dependencia en `composer.json`. La única pieza que existe es el destino: `people.contact_phone`, nullable y **sin ningún flujo de verificación**.

Y hay un segundo problema, independiente del proveedor, que conviene ver antes de contratar nada: **un número de teléfono sin verificar no es un segundo factor**. `contact_phone` es un dato de contacto que entra por importación masiva, por invitación y por edición administrativa. Enviar un código a un número que nadie ha comprobado que pertenezca al titular convierte el segundo factor en «quien controle el campo de teléfono controla la cuenta». Cualquier entrega de SMS que se implemente tendrá que verificar el número en el alta, exactamente igual que `§C.4.2` verifica el correo entregando un código.

**Qué hace 1.3, entonces**: el valor `sms` existe en el `CHECK` de método (`datos.md §C.2`) y en el enumerado de `mfa_allowed_methods`, y **una guarda impide activarlo** (`RN-AUTH-69`). Nada más. No se escribe un adaptador vacío, no se elige proveedor y no se inventa una variable de entorno para uno que no existe.

Es el mismo trato que 1.2b dio a la geolocalización por IP (`OPEN-AUTH-13`, `RN-AUTH-47`), y con la misma consecuencia: **al cerrar 1.3 hay que declarar que `REQ-AUTH-003` queda cumplido en dos de sus tres métodos**, no darlo por terminado (`CLAUDE.md §0`). `OPEN-AUTH-18`.

---

## C.8 El correo como segundo factor: qué protege y qué no

Se entrega porque el requisito lo pide, **desactivado por defecto** y con esto escrito, porque un centro que lo active debe saber lo que está activando:

- **Contra qué protege**: contra una contraseña filtrada, reutilizada o adivinada. Es la amenaza más frecuente con diferencia, y contra ella funciona.
- **Contra qué no protege**: contra el compromiso del buzón. Y aquí hay una circularidad que no se puede disimular: **la recuperación de contraseña de este mismo módulo también va a ese buzón** (§4.5). Quien controla el correo puede restablecer la contraseña *y* recibir el segundo factor. Frente a esa amenaza concreta, el correo como segundo factor **no añade nada**.
- **TOTP sí lo hace**, porque el secreto está en el dispositivo y no viaja.

Por eso el valor por defecto de `mfa_allowed_methods` es `["totp"]` y no `["totp","email"]`, y por eso `totp` no se puede quitar (`RN-AUTH-69`). El correo está disponible para el centro que lo necesite —hay personas sin teléfono con aplicación de autenticación, y excluirlas del MFA es peor que darles un factor imperfecto—, pero como decisión consciente del centro y no como valor de fábrica. `OPEN-AUTH-25` lo deja a confirmación del usuario, porque es una decisión de producto y no mía.

---

## C.9 Interacción con otros módulos

### C.9.1 Interfaces que consume

| Interfaz | De | Para qué |
|----------|----|----|
| `UserProfilePresenter` | `App\Support` | El recurso de perfil gana un bloque `mfa` (`§C.4.8`). Lo consumen `GET /me` (`REQ-CORE`) y el login (`REQ-AUTH`) sin que ninguno importe código del otro (`INV-007`) |
| `TenantSettingsReader` | `REQ-CORE` | `mfa_allowed_methods`, `mfa_grace_period_days` |
| `UserDirectory` | `REQ-CORE` | Resolver el `public_id` de usuario en los dos endpoints de administración |
| `SessionRevoker` | `REQ-AUTH` (1.2) | Revocar sesiones en el restablecimiento |
| `AuditRecorder` | `App\Support` | Solo para `login`; nada nuevo (`§C.10`) |

### C.9.2 Interfaces que expone

| Interfaz | Para quién |
|----------|------------|
| `MfaPolicy` | El *middleware* del muro, el login, y **1.6** cuando `REQ-BO-007` necesite la obligación sin conmutador del backoffice |
| `MfaComplianceDirectory` | La vista previa y el estado de cumplimiento. **1.5** lo consume desde el editor de roles sin importar nada interno de `REQ-AUTH` |
| `MfaVerifier` | El verificador de código de un método. Es el punto donde entra un adaptador de SMS el día que exista, y donde entraría WebAuthn si algún día se pide |

### C.9.3 Eventos publicados

`MfaFactorConfirmed`, `MfaFactorRemoved`, `MfaReset`, `MfaObligationStarted`, `RecoveryCodeUsed`. Ninguno se expone por API. Los consume el propio módulo para encolar los avisos de `§C.4.13`, y **`REQ-COM` (1.19) los sustituirá** por su canal, igual que hará con `AccountLocked` y `NewDeviceDetected`.

---

## C.10 Auditoría (`INV-003`)

**1.3 no amplía el vocabulario de `audit_logs`, y esa es la conclusión importante de esta sección.**

`ADR-039 §5.3` fijó la carga de la prueba: quien quiera un evento nuevo debe demostrar que el hecho **no es CRUD sobre ninguna entidad**, y avisó de que *«casi siempre lo será — el precedente de `AccountLockout` lo enseña — y entonces la respuesta correcta es modelar la entidad, no ampliar el vocabulario»*. Aplicado hecho por hecho:

| Hecho | Cómo queda registrado | Evento |
|-------|------------------------|--------|
| Alta de un factor (provisional) | `created` sobre `MfaFactor` con `confirmed_at NULL` | `created` |
| Confirmación del factor | `updated` sobre `MfaFactor` con `confirmed_at` | `updated` |
| Desactivación por el usuario | `deleted` sobre `MfaFactor` | `deleted` |
| Restablecimiento por el administrador | `deleted` sobre cada `MfaFactor` + `created` sobre `MfaReset` con el motivo y el administrador | `deleted`, `created` |
| Generación y regeneración de códigos | `created`/`deleted` sobre `MfaRecoveryCode` | `created`, `deleted` |
| **Uso** de un código de respaldo | `updated` sobre `MfaRecoveryCode` con `used_at` | `updated` |
| Cambio de `mfa_required` en un rol | `updated` sobre `Role` con el valor anterior y el nuevo | `updated` |
| Concesión y revocación de excepción | `created`/`updated` sobre `MfaExemption` | `created`, `updated` |
| Inicio de obligación y su cierre | `created`/`updated` sobre `MfaObligation` | `created`, `updated` |
| Cambio de `mfa_allowed_methods` | `updated` sobre `TenantSetting` | `updated` |
| **Acceso consumado con segundo factor** | `login` sobre `User`, **el mismo de `ADR-039`** | `login` |
| **Fallo de segundo factor** | `login_attempts`, **no** `audit_logs` | — |

Los dos últimos merecen el detalle:

- **No hay un evento «mfa_verificado» ni «mfa_fallido».** Un login con dos pasos sigue siendo **un** acceso consumado, y `ADR-039 §4.3` es explícito: `login` registra accesos consumados, no intentos. Escribir dos filas por un login duplicaría el registro y rompería la correlación por `request_id`. Si hace falta saber *cómo* entró alguien, la respuesta está en `login_attempts` correlacionada por `request_id`, que es exactamente para lo que `ADR-039 §4.3` la dejó.
- **Los fallos de segundo factor van a `login_attempts`**, por los dos mismos motivos independientes de `funcional.md §10.2`: `audit_logs.auditable_id` es `NOT NULL` (aunque aquí sí hay usuario, el argumento de volumen se mantiene) y un ataque contra un código de seis dígitos inunda una tabla con dos años de retención.

**Redacción (`ADR-035`)**: `user_mfa_factors.secret_encrypted` encaja en el patrón `*secret*` de `config('audit.secret_attribute_patterns')` y `user_mfa_recovery_codes.code_hash` en `*recovery_code*`, los dos ya presentes desde 0.9. **Aun así se declaran explícitamente en `auditSecretAttributes()`**, por la misma razón por la que `§B.2` obligó a declarar `session_id` a mano: depender de que un patrón global siga cubriendo un nombre de columna que alguien puede renombrar en un refactor es depender de una coincidencia. El detalle por modelo está en `datos.md §C.2`-`§C.6`.

**Exclusiones (`ADR-040`)**: **ninguna**. Ninguna entidad de este paso se crea dentro de la transacción de un hecho ya auditado por otro evento, que era el supuesto de `UserSession`.

---

## C.11 Interfaz de usuario

Mismo criterio que 1.2 y 1.2b: **1.3 sí entrega pantallas**, autónomas, sin `AppLayout`, sin depender del design system de 1.7 ni del layout de 1.8, reutilizando `PublicAuthShell` donde encaja.

| Ruta | Qué es | Estado |
|------|--------|--------|
| `/entrar` | **Modificada**: al recibir `202` cambia al formulario del segundo factor, sin salir de la ruta ni perder el contexto | Pública |
| `/entrar` (paso 2) | Código de 6 dígitos, enlace «usar un código de respaldo», selector de método si hay más de uno, botón de reenvío con su cuenta atrás | Pública |
| `/cuenta/seguridad` | Autoservicio: estado, alta con QR **y clave en texto**, confirmación, códigos de respaldo, desactivación | Con sesión |
| `/cuenta/seguridad/obligatorio` | El muro de `§C.4.9`: la misma alta, sin navegación, con el motivo explicado y **con «cerrar sesión» siempre visible** | Con sesión restringida |

**Sin pantalla de administración** (rol, cumplimiento, restablecimiento, excepciones): la API entra, la interfaz la monta 1.5 junto al editor de roles y 1.8 con el resto del panel. Mismo criterio con el que 1.1 dejó todas sus pantallas fuera (`OPEN-CORE-02`).

Reglas de accesibilidad que este paso no puede saltarse (WCAG 2.2 AA, `CLAUDE.md §10`):

- El QR **nunca es la única forma de dar de alta**: la clave en base32 está siempre visible y es seleccionable (`§C.4.1`, punto 4).
- El QR lleva alternativa textual que **no** contiene el secreto (un `alt` con el secreto lo pondría en el árbol de accesibilidad y en cualquier captura de lector de pantalla).
- Los códigos de respaldo se muestran en un bloque copiable y descargable **como texto seleccionable**, no como imagen.
- El campo del código admite pegado y autocompletado (`autocomplete="one-time-code"`), y el foco entra en él al pasar al paso 2.
- Los cuatro idiomas, sin literal en el código (`INV-009`).

---

## C.12 Puntos de extensión

- **1.4 / 1.4b (Google y SSO)**: un login federado que termina en sesión pasa por el **mismo** `MfaPolicy`. La decisión de si un proveedor externo que ya hizo MFA exime del nuestro **no se toma aquí** y no se hereda: es de 1.4b, con su ADR.
- **1.5 (editor de roles)**: consume `PATCH /roles/{public_id}` (ampliándolo, sin cambiar de ruta) y `MfaComplianceDirectory` para la vista previa. **Y hereda `RPERM-006`**: al clonar un rol, `mfa_required` se copia del origen. Queda escrito aquí como contrato, no implementado (`§C.2.3`).
- **1.6 (`REQ-BO`)**: `REQ-BO-007` exige MFA sin conmutador para el backoffice. Consume `MfaPolicy` y **no** replica su lógica; lo que añade es que su respuesta no dependa de ningún atributo editable.
- **1.19 (`REQ-COM`)**: sustituye los tres avisos de `§C.4.13` por su canal.
- **Cuando exista proveedor de SMS**: se implementa un `MfaVerifier` más y se levanta la guarda de `RN-AUTH-69`. **Ni un endpoint ni una tabla cambian** — el hueco ya está.
- **Reautenticación con segundo factor para operaciones sensibles**: cuando se pida, el desafío de `mfa_challenges` es el mecanismo, con un `purpose` distinto de `login`. La columna **no se añade hoy** (`ADR-034 OPEN-13`: no se anticipan columnas).

---

## C.13 Criterios de aceptación

Verificables, cada uno con test que referencia su ID (`INV-015`). Bloque `104-145`, sin solaparse con los de 1.2 (`001-079`) ni 1.2b (`080-103`).

### Alta y confirmación de factor (`REQ-AUTH-003`)

- **`CA-AUTH-104`** · *Dado* un usuario autenticado sin MFA, *cuando* llama a `POST /auth/mfa-enrollments` con `totp`, *entonces* recibe `201` con secreto en base32 y URI `otpauth`, existe una fila de `user_mfa_factors` con `confirmed_at IS NULL` y `expires_at` informado, y **`GET /auth/mfa` sigue diciendo que no tiene ningún factor** (`RN-AUTH-59`).
- **`CA-AUTH-105`** · *Dado* ese alta, *cuando* se confirma con un código válido, *entonces* `201`, `confirmed_at` informado, `expires_at` a `NULL`, y la respuesta incluye **exactamente** `AUTH_MFA_RECOVERY_CODE_COUNT` códigos de respaldo en claro (`§C.4.1`, `§C.4.3`).
- **`CA-AUTH-106`** · *Dado* ese alta, *cuando* se confirma con un código **inválido**, *entonces* `422`, el factor **no** queda confirmado, y al quinto intento el alta queda muerta y hay que empezar de nuevo (`RN-AUTH-59`).
- **`CA-AUTH-107`** · *Dado* un alta con `expires_at` vencido, *cuando* se intenta confirmar, *entonces* `410` con el mismo cuerpo que un alta inexistente (`RN-AUTH-59`, §4.7).
- **`CA-AUTH-108`** · *Dado* la fila de `user_mfa_factors`, *cuando* se lee la columna del secreto directamente en la base de datos, *entonces* **no** contiene el valor en base32 en claro, y descifrarla con `APP_KEY` sí lo devuelve (`RN-AUTH-55`).
- **`CA-AUTH-109`** · *Dado* un usuario **con** factor confirmado, *cuando* consulta `GET /auth/mfa`, `GET /me` o cualquier otra respuesta del producto, *entonces* **en ninguna** aparece el secreto, ni el hash de ningún código de respaldo, ni un código en claro (`RN-AUTH-55`, `RN-AUTH-56`).
- **`CA-AUTH-110`** · *Dado* un tenant cuyo `mfa_allowed_methods` es `["totp"]`, *cuando* un usuario intenta dar de alta el método `email`, *entonces* `422` y no se crea ninguna fila (`RN-AUTH-69`).

### Códigos de respaldo

- **`CA-AUTH-111`** · *Dado* un usuario con códigos de respaldo, *cuando* usa uno para entrar, *entonces* el login se completa, ese código queda con `used_at` informado, **no se borra la fila**, y un segundo intento con el mismo código responde `401` (`RN-AUTH-57`).
- **`CA-AUTH-112`** · *Dado* la regeneración, *cuando* se llama con la contraseña correcta, *entonces* `201` con un conjunto nuevo, **todas** las filas anteriores desaparecen (usadas incluidas) y ninguno de los códigos anteriores vuelve a funcionar (`§C.4.3`).
- **`CA-AUTH-113`** · *Dado* la regeneración y la desactivación de factor, *cuando* se llaman **sin** la contraseña actual o con una incorrecta, *entonces* `422` y no cambia nada (`RN-AUTH-60`).
- **`CA-AUTH-114`** · *Dado* un usuario que agota todos sus códigos de respaldo, *cuando* inicia sesión con su factor, *entonces* entra con normalidad y `GET /auth/mfa` informa de que le quedan `0` (`§C.4.3` punto 7).

### Login en dos pasos (`REQ-AUTH-003`, `RN-AUTH-21`)

- **`CA-AUTH-115`** · *Dado* un usuario con factor confirmado, *cuando* envía credenciales correctas a `POST /auth/session`, *entonces* recibe **`202`** con el desafío, **`Auth::id()` es `null`**, no hay fila en `user_sessions`, el *payload* de sesión no tiene `pge_tenant_id`, y **cualquier endpoint autenticado responde `401`** en ese estado (`RN-AUTH-52`).
- **`CA-AUTH-116`** · *Dado* ese `202`, *cuando* se inspecciona la respuesta, *entonces* **no** contiene ningún token, ni el `session_id`, ni el secreto, ni el código entregado (`RN-AUTH-53`, `RN-AUTH-56`).
- **`CA-AUTH-117`** · *Dado* un desafío abierto en la sesión A, *cuando* se intenta verificar desde la sesión B —incluso con su `public_id`—, *entonces* `410` con el **mismo cuerpo** que un desafío inexistente (`RN-AUTH-53`, `RN-AUTH-72`).
- **`CA-AUTH-118`** · *Dado* un desafío válido, *cuando* se verifica con el código correcto, *entonces* `200` con el recurso de `/me`, el identificador de sesión **regenerado**, fila en `user_sessions` con **ese** identificador nuevo, y una sola fila `login` en `audit_logs` con `actor_type = 'user'` (`§C.4.4` punto 10, `ADR-039 §4.5`, `§B.9`).
- **`CA-AUTH-119`** · *Dado* un desafío, *cuando* pasan más de `AUTH_MFA_CHALLENGE_TTL_MINUTES`, *entonces* la verificación responde `410` y hay que volver a empezar por la contraseña (`RN-AUTH-54`).
- **`CA-AUTH-120`** · *Dado* un desafío, *cuando* se reenvía el código, *entonces* **ni el contador de intentos ni `expires_at` cambian** (`RN-AUTH-54`, `§C.4.4.1`).
- **`CA-AUTH-121`** · *Dado* un código TOTP ya usado, *cuando* se reenvía dentro de la misma ventana de validez, *entonces* se rechaza (`RN-AUTH-58`).
- **`CA-AUTH-122`** · *Dado* un usuario **sin** MFA y **sin** obligación, *cuando* inicia sesión, *entonces* recibe `200` y todo se comporta **exactamente** como en 1.2 — el flujo de un solo paso no cambia para nadie (`§C.4.4`).

### Bloqueo y telemetría (`§C.4.4.2`)

- **`CA-AUTH-123`** · *Dado* un usuario con MFA, *cuando* repite cinco veces «contraseña correcta + segundo factor incorrecto», *entonces* la cuenta queda **bloqueada** y el sexto intento de login responde `423` sin llegar al segundo factor (`RN-AUTH-63`, `RN-AUTH-64`).
- **`CA-AUTH-124`** · *Dado* ese escenario, *cuando* se inspecciona `login_attempts`, *entonces* hay cinco filas `pendiente_segundo_factor` y cinco `segundo_factor_invalido`, y **ninguna** `exito` (`RN-AUTH-63`).
- **`CA-AUTH-125`** · *Dado* cuatro fallos de segundo factor seguidos de un login **completo**, *cuando* se falla una vez más después, *entonces* la cuenta **no** se bloquea: el contador se puso a cero con el login completo (`RN-AUTH-63`).

### Obligatoriedad y gracia (`RPERM-007`, `RPERM-014`)

- **`CA-AUTH-126`** · *Dado* un usuario con dos roles, uno con `mfa_required = true` y otro con `false`, *entonces* `MfaPolicy` lo declara **obligado** (`RN-AUTH-62`).
- **`CA-AUTH-127`** · *Dado* un administrador que pone `mfa_required = true` en un rol, *cuando* se consulta a los usuarios afectados, *entonces* cada uno tiene una fila de `user_mfa_obligations` con `grace_deadline_at = obligated_since + mfa_grace_period_days` y `GET /me` devuelve `days_remaining` (`RN-AUTH-65`).
- **`CA-AUTH-128`** · *Dado* un usuario obligado **dentro** de la gracia, *cuando* inicia sesión, *entonces* `200`, sesión **completa**, y todos los endpoints del producto le responden con normalidad (`§C.4.8`).
- **`CA-AUTH-129`** · *Dado* un usuario obligado con la gracia **vencida**, *cuando* inicia sesión, *entonces* `200` con sesión **restringida**: `GET /me`, `GET /auth/mfa`, los dos de alta y `DELETE /auth/session` responden con normalidad, y **cualquier otro** endpoint responde `403 urn:pge:error:mfa-enrollment-required` (`§C.4.9`).
- **`CA-AUTH-130`** · *Dado* un usuario **en sesión activa** cuya gracia vence a mitad, *cuando* hace la siguiente petición, *entonces* recibe el `403` del muro **sin que su sesión se destruya**, y puede completar el alta y seguir trabajando sin volver a introducir la contraseña (`§C.4.9` punto 4).
- **`CA-AUTH-131`** · *Dado* ese mismo usuario en el muro, *cuando* confirma su factor, *entonces* la petición siguiente a cualquier endpoint responde con normalidad, sin cerrar ni regenerar la sesión (`§C.4.9`).
- **`CA-AUTH-132`** · *Dado* un usuario obligado con factor confirmado, *cuando* intenta desactivarlo, *entonces* `409` y el factor sigue activo (`RN-AUTH-61`).
- **`CA-AUTH-133`** · *Dado* un tenant que quita `email` de `mfa_allowed_methods`, *cuando* un usuario cuyo único factor era `email` intenta iniciar sesión, *entonces* **no** se le abre desafío por ese método, se le trata como no inscrito, y se le abre una obligación nueva con plazo completo si algún rol suyo lo exige (`RN-AUTH-69`).
- **`CA-AUTH-134`** · *Dado* cualquier configuración, *cuando* se intenta guardar `mfa_allowed_methods` sin `totp`, o incluyendo `sms`, *entonces* `422` en los dos casos (`RN-AUTH-69`).

### Administración

- **`CA-AUTH-135`** · *Dado* un administrador con `rol.actualizar`, *cuando* llama a `PATCH /roles/{public_id}` con `{"mfa_required": true}`, *entonces* `200`; *cuando* incluye cualquier otro campo, *entonces* `422` y **nada cambia** (`RN-AUTH-70`).
- **`CA-AUTH-136`** · *Dado* `GET /mfa-compliance?role={public_id}&mfa_required=true`, *cuando* se llama, *entonces* devuelve el número de usuarios que **quedarían** obligados **sin haber modificado nada**, y una segunda llamada al estado real lo confirma (`REQ-AUTH-003`, vista previa).
- **`CA-AUTH-137`** · *Dado* `POST /mfa-resets` **sin `reason`** o con un motivo de menos de 10 caracteres, *entonces* `422`; con motivo válido, *entonces* `204`, todos los factores del usuario borrados lógicamente, sus códigos borrados, **todas sus sesiones cerradas con `end_reason = 'cambio_credencial'`**, notificación **encolada** y fila de auditoría con el administrador y el motivo (`RN-AUTH-66`, `§C.4.10`).
- **`CA-AUTH-138`** · *Dado* un administrador con `mfa.eliminar`, *cuando* intenta restablecer **su propio** MFA, *entonces* `403` (`RN-AUTH-67`). Cubierto por `MfaAdministrationTest.php`. La mitad sobre autoexención («o concederse su propia excepción») queda pendiente de `1.3b`, que es quien entrega `POST /mfa-exemptions` (`§C.4.11`) — no hay test de eso en esta rama porque el endpoint no existe.
- **`CA-AUTH-139`** · **Diferido a `1.3b`, sin test en esta rama**: *Dado* `POST /mfa-exemptions` sin `expires_at`, o con una fecha a más de `AUTH_MFA_MAX_EXEMPTION_DAYS`, *entonces* `422`; con una válida, *entonces* el usuario deja de estar obligado mientras dura y vuelve a estarlo **con plazo completo** al caducar (`RN-AUTH-68`, `§C.4.11`). El endpoint no existe en `1.3`; este CA se implementa y se prueba cuando `1.3b` lo entregue.
- **`CA-AUTH-140`** · *Dado* los **tres** endpoints de administración de este paso (`GET /mfa-compliance`, `GET /mfa-compliance/users`, `POST /mfa-resets` — `apps/api/app/Modules/Auth/Http/routes.php`), *cuando* se llaman **sin sesión** ⇒ `401`; **sin el permiso** ⇒ `403`; sobre un usuario o rol **de otro tenant** ⇒ `404` con cuerpo idéntico; y **sin CSRF** en las escrituras ⇒ `419`/`403` (`INV-002`, `RN-AUTH-29`, `ADR-038 §6.4`). Cubierto por `MfaAdministrationTest.php` (`§C.10` de `permisos.md`).

### Transversales

- **`CA-AUTH-141`** · *Dado* una cookie `pge_device` de un dispositivo reconocido, *cuando* el usuario con MFA inicia sesión desde él, *entonces* **se le pide el segundo factor igual** (`RN-AUTH-71`, `RN-AUTH-45`).
- **`CA-AUTH-142`** · *Dado* todo el ciclo de vida de MFA de un usuario, *cuando* se consulta `audit_logs`, *entonces* existen las filas de `§C.10`, **ninguna con un `event` fuera de los nueve de `ADR-039`**, y en ninguna aparece el secreto ni el hash de un código sin redactar (`RN-AUTH-74`, `ADR-035`).
- **`CA-AUTH-143`** · *Dado* un factor, un desafío, un código de respaldo y una excepción **de otro tenant**, *cuando* se intentan usar o consultar desde el host del tenant propio, *entonces* ninguno es alcanzable y la respuesta es idéntica a la de un recurso inexistente (`INV-001`, `RN-AUTH-06`, `RN-AUTH-08`).
- **`CA-AUTH-144`** · *Dado* los cuatro correos del módulo tras 1.3, *cuando* se revisan, *entonces* existen en `es-ES`, `en`, `de` y `fr`, van en el idioma del destinatario, y **ninguno contiene un enlace que ejecute una acción sin sesión** ni el código en el asunto (`INV-009`, `RN-AUTH-50`).
- **`CA-AUTH-145`** · *Dado* las rutas nuevas de este paso, *cuando* se inspecciona el enrutado, *entonces* **ninguna lleva el *middleware* `module-enabled`** (`RN-AUTH-35`, `CA-AUTH-078`).

---

## C.14 Preguntas abiertas

Nueve. **Todas resueltas por el usuario el 2026-08-26** (ver `C.16`). Se conserva el argumento original de cada una para que la decisión se entienda con su coste, no solo con su resultado.

### `OPEN-AUTH-18` · No hay proveedor de SMS, y sin él `REQ-AUTH-003` queda cumplido en dos de sus tres métodos

Verificado en todo el repositorio: no hay proveedor, ni ADR, ni paso en el plan, ni variable, ni dependencia. Y el destino (`people.contact_phone`) está **sin verificar**, lo que añade un problema propio (`§C.7`).

**Recomendación**: entregar 1.3 con TOTP y correo, dejar el hueco de `sms` cerrado con guarda, y **declarar explícitamente al cerrar el paso** que el método SMS no está implementado — igual que 1.2b declaró la mitad de `REQ-AUTH-005` punto 4 con `RN-AUTH-47`. Si el usuario quiere SMS, es un paso propio que empieza por **elegir proveedor** (con contrato de encargado de tratamiento y datos en la UE, `OPEN-07`) y por **verificar el número en el alta**.

**Decisión (2026-08-26)**: se acepta la recomendación. `sms` cerrado con guarda en 1.3; al cerrar el paso se declara `REQ-AUTH-003` cumplido en dos de sus tres métodos.

### `OPEN-AUTH-19` · Dependencia nueva: librería TOTP en el backend

RFC 6238 son treinta líneas y **cuatro formas silenciosas de equivocarse**: base32 mal decodificado, ventana de tolerancia mal calculada, comparación no constante y contador derivado del huso horario local en vez de UTC. Ninguna falla en las pruebas: fallan en producción, con códigos que a veces valen.

**Recomendación**: usar una librería mantenida en vez de escribirlo. El candidato natural en este ecosistema es `pragmarx/google2fa` (la que usa el andamiaje oficial del framework), **envuelta tras una interfaz propia** (`RNF-MANT-007`, `MfaVerifier` de `§C.9.2`). Pero introducir una dependencia exige comprobar mantenimiento activo, licencia y frecuencia de *releases* (`CLAUDE.md §1`), y **eso es una decisión, no un trámite**. No la tomo yo.

**Decisión (2026-08-26)**: aprobado `pragmarx/google2fa`, envuelto tras `MfaVerifier`. La comprobación formal de mantenimiento/licencia/*releases* de `CLAUDE.md §1` se hace en el ADR previo a implementar (`§C.0.2`, subagente `architect`), no queda satisfecha por esta sola aprobación de producto.

**Cerrada por `ADR-041`** (2026-08-26): comprobación superada. `pragmarx/google2fa` `^9.1` (mínimo `v9.1.0`, MIT, última *release* 2026-08-15), tras `MfaVerifier` (verificación, firma intacta de `§C.9.2`) y `TotpProvisioner` (secreto y URI `otpauth`), con adaptador único `Google2FaTotpVerifier`. Descartada `spomky-labs/otphp` porque su `verify()` devuelve un booleano y no el paso de tiempo validado, lo que obligaría a reimplementar la ventana para cumplir `RN-AUTH-58`. Descartados también `google2fa-laravel` y `google2fa-qrcode`.

### `OPEN-AUTH-20` · Dependencia nueva: generador de QR en el frontend

El servidor devuelve la URI `otpauth`; alguien tiene que dibujarla. Las opciones son una librería de QR en la SPA, generar el SVG en el servidor (otra dependencia, esta vez en PHP), o **no dibujar QR** y entregar solo la clave en texto.

**Recomendación**: librería en la SPA, envuelta tras un componente propio. La tercera opción es tentadora por no añadir nada, pero transcribir 32 caracteres a mano en un móvil es donde se pierde a la mitad de los usuarios que iban a activar MFA voluntariamente. Misma comprobación de `CLAUDE.md §1` que la anterior.

**Decisión (2026-08-26)**: aprobada la librería QR en la SPA, envuelta tras un componente propio. Igual que `OPEN-AUTH-19`, la elección concreta y su comprobación de `CLAUDE.md §1` se cierran en el ADR previo a implementar.

**Cerrada por `ADR-041`** (2026-08-26): **`qrcode` (node-qrcode) rechazada** — sin *release* desde 2024-08-05, sin *commits* desde 2024-08-23, 125 *issues* abiertas, `yargs@15` en ejecución y sin tipos propios: incumple «mantenimiento activo» de `CLAUDE.md §1`. Se aprueba **`uqr` `^0.1.3`** (MIT, cero dependencias, tipos nativos), usada **solo** como codificador (`encode()` → `boolean[][]`, nunca `renderSVG()`), tras el componente propio `apps/web/src/components/QrCode.vue`, que construye el `<svg role="img">` en plantilla para poder cumplir la alternativa textual sin secreto de `§C.11`.

### `OPEN-AUTH-21` · El punto de corte con 1.5: `rol.actualizar` y `PATCH /roles/{public_id}` acotado

`§C.2` decide que 1.3 entrega la escritura del atributo, que el permiso es `rol.actualizar` declarado en el catálogo de `REQ-CORE`, y que el `PATCH` acepta un solo campo hasta que 1.5 lo amplíe. **El argumento de por qué no puede esperar está en `§C.2.1` y es operativo**: sin él, hacer efectivo `mfa_required` deja a todos los tenants con dos roles obligados y sin interruptor durante dos pasos del plan.

**Lo que necesita confirmación del usuario** es que acepta que 1.3 toque el catálogo de permisos y el controlador de `REQ-CORE`. Si prefiere que no, la alternativa coherente es **no hacer efectivo `mfa_required` hasta 1.5** y entregar en 1.3 solo el MFA voluntario — que es una entrega perfectamente útil, pero deja `REQ-AUTH-003` a medias en su parte más citada.

**Decisión (2026-08-26)**: aprobado. 1.3 declara `rol.actualizar` en `CoreServiceProvider::declaredPermissions()` y entrega `PATCH /api/v1/roles/{public_id}` acotado a `mfa_required`.

### `OPEN-AUTH-22` · Desde cuándo cuenta el período de gracia

`§C.4.8` decide: **desde que empieza la obligación**, no desde el primer acceso posterior.

**El coste de esa decisión**, dicho entero: un usuario de baja, de vacaciones o simplemente inactivo durante esos siete días **no ve ni un solo aviso** y se encuentra el muro en su siguiente entrada. Completar el alta ahí mismo es exactamente lo que el requisito quiere, así que no es un fallo — pero es una experiencia distinta de la que sugiere «avisos en cada acceso».

**La alternativa** (contar desde el primer acceso posterior a la obligación) garantiza que todo el mundo vea los siete avisos, y a cambio permite aplazar indefinidamente no entrando, y hace que el administrador no tenga una fecha determinista que enseñar en la pantalla de cumplimiento. **Recomiendo la decidida**; la decisión es del usuario.

**Decisión (2026-08-26)**: confirmado. El período de gracia cuenta desde que empieza la obligación, con el coste asumido descrito arriba (usuarios inactivos no ven avisos).

### `OPEN-AUTH-23` · ¿Puede un administrador de centro quitarse a sí mismo la obligación?

`REQ-AUTH-003` recomienda MFA obligatorio para administración de centro, pero **recomendación no es cerrojo**, y no dice nada sobre editarlo uno mismo. Tal como está especificado, un administrador puede poner `mfa_required = false` en `administrador_centro` —que es su propio rol— y desactivarse el segundo factor.

**Argumento a favor de permitirlo**: es su centro, el requisito lo hace editable, y prohibirlo obliga a un procedimiento manual el día que haya un problema real.
**Argumento en contra**: convierte la obligatoriedad del rol más peligroso del tenant en algo que el propio interesado apaga, y contradice el espíritu de `REQ-BO-007` (que para el backoffice sí dice «sin conmutador»).

**Recomendación**: permitirlo, con auditoría reforzada y un aviso explícito en la pantalla de 1.5, y **revisarlo cuando 1.6 traiga el backoffice**, que es donde `REQ-BO-007` obliga a resolver la misma pregunta un nivel más arriba. No la decido.

**Decisión (2026-08-26)**: aprobado permitirlo, con auditoría reforzada. Reabrir la pregunta al llegar 1.6 (`REQ-BO-007`).

### `OPEN-AUTH-24` · El tamaño del paso: ¿se parte 1.3 como se partió 1.2?

6 tablas, 2 modificaciones de esquema, 14 endpoints nuevos (13 aquí, 1 en `REQ-CORE`), 3 pantallas y 2 dependencias externas (`§C.1.3`). Es entre dos y tres veces 1.2 o 1.2b, y el plan lo dimensiona como una sesión.

**La línea de corte natural**, si se parte:

- **1.3** — TOTP, códigos de respaldo, autoservicio, login en dos pasos, `MfaPolicy`, gracia y muro, `PATCH /roles` acotado, vista previa y cumplimiento, **restablecimiento por el administrador**. Es el mínimo coherente: sin restablecimiento, un usuario que pierde el móvil y los códigos queda fuera de una cuenta obligada sin salida.
- **1.3b** — método por correo, excepciones temporales nominales, y la pantalla de administración si 1.5 se retrasa.

**Recomendación**: partirlo. El precedente de 1.2/1.2b existe precisamente por esto. La decisión es del usuario.

**Decisión (2026-08-26)**: aprobado partir en `1.3`/`1.3b` con la línea de corte propuesta. `PLAN-IMPLEMENTACION.md` actualizado en consecuencia.

### `OPEN-AUTH-25` · El correo como segundo factor: ¿se ofrece?

`§C.8` explica lo que protege (contraseña filtrada: bien) y lo que no (compromiso del buzón: nada, porque la recuperación de contraseña va al mismo sitio).

**Recomendación**: ofrecerlo, **desactivado por defecto**, con `totp` no desactivable. Excluir del MFA a quien no tiene teléfono con aplicación de autenticación es peor que darle un factor imperfecto. Pero es una decisión de producto y de riesgo del centro, no mía.

**Decisión (2026-08-26)**: aprobado ofrecerlo, desactivado por defecto. Este método se implementa en `1.3b`, no en `1.3` (ver `OPEN-AUTH-24`).

### `OPEN-AUTH-26` · Los secretos TOTP son el primer dato de usuario que se pierde si se pierde `APP_KEY`

Hasta hoy, `APP_KEY` cifraba el *payload* de sesión y los cursores de paginación: cosas regenerables. **A partir de 1.3 cifra credenciales de usuario.** Perder la clave, o restaurar una copia de base de datos sin ella, significa que **todos los factores TOTP del sistema dejan de verificar** y hay que restablecer el MFA de todo el mundo a mano.

`ADR-037 §7.2` punto 4 ya obliga a custodiar `APP_KEY` **separada** de la copia de la base de datos, y `0.10d` lo recoge. Lo que esta especificación aporta es que **eso deja de ser una buena práctica y pasa a ser un requisito de recuperación con consecuencia visible**. No es una pregunta técnica sino de operación: hay que confirmar que `0.10d` se resuelve con esa condición antes de que entre el primer dato real. `operacion.md §C.10` lo recoge.

**Decisión (2026-08-26)**: confirmado por el usuario. No bloquea 1.3; queda anotado como condición de cierre de `0.10d` en `PLAN-IMPLEMENTACION.md` y `memory.md`.

### Lo que **no** dejo como pregunta abierta, y por qué

- **Que el login parcial no cree sesión autenticada** (`§C.6`). Es un requisito de seguridad, no una preferencia. Las alternativas están evaluadas y descartadas con motivo.
- **Que la cookie `pge_device` no salte el segundo factor** (`RN-AUTH-71`). `RN-AUTH-45` obligaba a decidirlo explícitamente; está decidido, y lo contrario no está en ningún requisito.
- **Que los fallos de segundo factor alimenten el bloqueo existente** (`§C.4.4.2`). La alternativa (contador propio) no aporta seguridad y duplica un mecanismo. Lo que sí es innegociable es `RN-AUTH-63`: sin él el segundo factor es decorativo.
- **Que no se amplíe el vocabulario de `audit_logs`** (`§C.10`). `ADR-039 §5.3` fija la carga de la prueba y este paso no la levanta: todo lo suyo es CRUD sobre entidades reales.

---

## C.15 ¿Se aprueba esta especificación?

**Sí, aprobada por el usuario el 2026-08-26.** Las nueve preguntas de `§C.14` llevan su decisión anotada junto al argumento. Resumen:

1. **`OPEN-AUTH-24`** → se parte en `1.3`/`1.3b`, con la línea de corte propuesta.
2. **`OPEN-AUTH-21`** → sí, `1.3` toca `REQ-CORE` (`rol.actualizar` + `PATCH /roles/{public_id}` acotado).
3. **`OPEN-AUTH-19`/`20`** → aprobadas `pragmarx/google2fa` (backend) y una librería de QR en la SPA (frontend), ambas envueltas tras interfaz propia. Comprobación formal de `CLAUDE.md §1` pendiente del ADR previo a implementar (`§C.16`).
4. **`OPEN-AUTH-18`** → `sms` cerrado con guarda en `1.3`, sin proveedor. **`OPEN-AUTH-25`** → correo como segundo factor sí se ofrece, desactivado por defecto, pero se implementa en `1.3b`.
5. **`OPEN-AUTH-22`** → gracia cuenta desde que empieza la obligación. **`OPEN-AUTH-23`** → un administrador de centro puede autoeditarse la obligación, con auditoría reforzada.
6. **`OPEN-AUTH-26`** → confirmado; condición de cierre de `0.10d`, no bloquea `1.3`.

Confirmadas también las tres asunciones de alcance que no se listaban como pregunta: pantalla de administración fuera de `1.3` (API sí, UI en 1.5/1.8), `RPERM-006` (herencia al clonar) queda pendiente hasta 1.5, y los tres avisos al titular de `§C.4.13` entran.

## C.16 Alcance de `1.3` tras la partición, y siguiente paso

Con `OPEN-AUTH-24` resuelto, el alcance de **esta rama** (`feature/REQ-AUTH-003-1.3-mfa-obligatorio-por-rol`) queda acotado a: alta/verificación TOTP, códigos de respaldo, login en dos pasos, `MfaPolicy` (obligatoriedad por rol, resolución multi-rol), período de gracia y muro de sesión restringida, `PATCH /roles/{public_id}` acotado a `mfa_required`, vista previa de usuarios afectados y estado de cumplimiento **agregado e individualizado** (`GET /mfa-compliance` y `GET /mfa-compliance/users`), y restablecimiento de MFA por el administrador. **`1.3b`** (rama nueva cuando le toque turno) se queda con: método de correo como segundo factor, excepciones temporales nominales (`exencion_mfa`), y la pantalla de administración si `1.5` se retrasa.

**Corrección del 2026-08-27, no una vuelta atrás sin más.** Un subagente implementador, durante esta misma rama, había recortado el alcance de `1.3` sin autorización: movió `GET /mfa-compliance/users` a `1.3b` junto con `exencion_mfa`, tratando los dos como si fueran la misma decisión de partición. No lo eran — el párrafo anterior ya decía "estado de cumplimiento" sin distinguir agregado de individualizado, y el listado individualizado **sí** estaba en el alcance que el usuario aprobó el 2026-08-26 (`api.md §C.5` de aquel momento, `git show ce399bc`). El usuario revisó el hallazgo el 2026-08-27 y decidió explícitamente restaurar `GET /mfa-compliance/users` en `1.3`, dejando `exencion_mfa` donde estaba, en `1.3b`. Ver `api.md §C.1` y `permisos.md §C.1`/`§C.6.1` para el detalle reconciliado.

**Antes de implementar**: `OPEN-AUTH-19`/`20` aprobaron la *dirección* (qué tipo de dependencia), no la comprobación formal de mantenimiento/licencia/*releases* que exige `CLAUDE.md §1` para `pragmarx/google2fa` y la librería de QR. Esa comprobación, envuelta en un ADR, es tarea de `architect` (Opus) y va **antes** que `implementer` — mismo patrón que `ADR-040` en `1.2b`.

**Hecha: `ADR-041`** (2026-08-26). Aprobadas `pragmarx/google2fa` `^9.1` (backend) y `uqr` `^0.1.3` (SPA); **rechazada `qrcode` (node-qrcode)** por mantenimiento parado. El ADR fija además los envoltorios que `implementer` debe crear: `App\Modules\Auth\Domain\MfaVerifier` y `TotpProvisioner`, con adaptador único `App\Modules\Auth\Infrastructure\Google2FaTotpVerifier`; y `apps/web/src/components/QrCode.vue`. Con esto, `1.3` no tiene nada pendiente antes de implementar.
