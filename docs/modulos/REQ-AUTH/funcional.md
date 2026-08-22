# REQ-AUTH · Autenticación y gestión de identidad · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-AUTH` |
| Prioridad | MUST |
| Fase | 1 · Bloque A · **paso 1.2** |
| Depende de | 1.1 (`REQ-CORE`: usuarios, invitaciones, `tenant_settings`, `GET /tenant/branding`), 0.7 (`ADR-033`), 0.8 (`ADR-034`), 0.9 (auditoría `ADR-035`/`ADR-036`, i18n) |
| Estado | **APROBADO** el 2026-08-22 (§14). Único trabajo previo a implementar: `ADR-039`, en redacción por `architect` |
| Módulo (código) | `auth` · `apps/api/app/Modules/Auth` · `apps/web/src/modules/auth` |

> Fuente de verdad: sección 5.2 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` (`REQ-AUTH-001` a `REQ-AUTH-005`). Este documento **no** reabre lo decidido en `ADR-014`, `ADR-025`, `ADR-029`, `ADR-033`, `ADR-034`, `ADR-035`, `ADR-036` ni `ADR-038`, ni el alcance del paso 1.2 fijado con el usuario el 2026-08-22.

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
