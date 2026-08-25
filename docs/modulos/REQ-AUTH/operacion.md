# REQ-AUTH · Operación

> **Estructura**: las secciones **§1 a §11** son el paso **1.2**, cerrado el 2026-08-25. La **Parte B** (`§B.1` en adelante) es el paso **1.2b** (`funcional.md` Parte B), **pendiente de aprobación**.

> Paso **1.2**. Complementa `SYSADMIN.md` y `RUNBOOK.md`; lo que aquí se describe es específico de este módulo.

---

## 1. Comportamiento con el módulo activo o inactivo

**`REQ-AUTH` no es desactivable** (`RN-AUTH-35`), igual que `REQ-CORE`. Se registra en el catálogo `modules` con `code = 'auth'` y `EnsureModuleEnabled` lo trata como permanentemente habilitado.

Regla operativa que se deriva y que hay que respetar al implementar: **ninguna ruta de este módulo lleva el *middleware* `module-enabled`** (`CA-AUTH-078`). Ponérselo abriría la posibilidad de que una fila mal puesta en `module_subscriptions` dejara a un centro entero sin poder iniciar sesión — es decir, sin forma de entrar a arreglarlo. El modo de fallo es total y la recuperación exigiría acceso a la base de datos.

---

## 2. Variables de entorno

### 2.1 Propias del módulo

| Variable | Uso | Valor en desarrollo |
|----------|-----|---------------------|
| `AUTH_LOGIN_MAX_ATTEMPTS` | Fallos consecutivos que provocan el bloqueo (`RN-AUTH-14`) | `5` |
| `AUTH_PASSWORD_RESET_TTL_MINUTES` | Caducidad del token de restablecimiento (`RN-AUTH-12`) | `60` |
| `AUTH_UNLOCK_TOKEN_TTL_HOURS` | Caducidad del token de desbloqueo (`RN-AUTH-13`) | `24` |
| `AUTH_SESSION_TIMEOUT_DEFAULT_MINUTES` | Valor por defecto de `tenant_settings.session_timeout_minutes` en el aprovisionamiento | `30` |
| `AUTH_SESSION_TIMEOUT_MAX_MINUTES` | Cota superior admitida y base de la guarda de arranque contra `SESSION_LIFETIME` (`RN-AUTH-30`) | `480` |
| `AUTH_LOGIN_ATTEMPT_RETENTION_DAYS` | Purga de `login_attempts` (`datos.md §A.9`) | `90` |
| `AUTH_PASSWORD_MIN_LENGTH` | Longitud mínima (`RN-AUTH-01`). **No es un conmutador de política**: existe para poder endurecerla, y la guarda de arranque rechaza cualquier valor por debajo de 12 | `12` |
| `AUTH_BCRYPT_ROUNDS` | Coste de bcrypt (`RN-AUTH-03`). La guarda rechaza valores por debajo de 12 | `12` |
| `AUTH_RATE_LIMIT_*` | Los cinco límites de §6 | Ver §6 |

Ninguna es un secreto.

### 2.2 De sesión: heredadas del framework, ahora con guarda

Estas son las que el issue [#8](https://github.com/pirexia/plataforma-educativa/issues/8) señaló como dependientes del valor por defecto. A partir de 1.2 **dejan de depender de él**:

| Variable | Valor obligatorio | Guarda |
|----------|-------------------|--------|
| `SESSION_DOMAIN` | **Vacía, siempre y en todos los entornos** | **La aplicación aborta el arranque si tiene valor** (`CA-AUTH-001`). Es lo que hace host-only la cookie y lo que impide que la sesión de `centroa.dominio` viaje a `centrob.dominio` (`funcional.md §6`) |
| `SESSION_SECURE_COOKIE` | `true` en producción y *staging*; `false` solo en desarrollo local sobre HTTP | La guarda la fuerza a `true` cuando el entorno es `production` |
| `SESSION_HTTP_ONLY` | `true` siempre | Guarda de arranque |
| `SESSION_SAME_SITE` | `lax` (`RN-AUTH-27`) | Guarda: se rechaza `none` |
| `SESSION_PARTITIONED_COOKIE` | `false` | Solo tiene sentido con `SameSite=None`, que está prohibido |
| `SESSION_LIFETIME` | **≥ `AUTH_SESSION_TIMEOUT_MAX_MINUTES`** (480) | Guarda de arranque (`CA-AUTH-052`). Si fuera menor, el framework mataría sesiones antes que la regla del centro y el ajuste del tenant no significaría nada |
| `SESSION_DRIVER` | `database` | El *timeout* por tenant y la revocación de `RN-AUTH-22` necesitan sesiones consultables por `user_id`. Con `cookie` no habría nada que revocar |
| `SESSION_ENCRYPT` | `true` recomendado | El *payload* guarda `user_id` y `tenant_id`. Mientras `sessions` no tenga RLS (`OPEN-AUTH-10`), cifrarlo acota lo que un volcado de esa tabla revelaría |

**Las guardas corren en todos los entornos, no solo en producción.** Un entorno de desarrollo con cookie de dominio compartido o con `SameSite=None` está probando un modelo de seguridad distinto del que se despliega, que es la peor forma de no enterarse. Mismo patrón que la guarda de `core.documents.validate_check_digit` de 1.1, con su test.

`SYSADMIN.md` y la plantilla de `EnvironmentFile=` de `ADR-037` recogen esta tabla con el motivo de cada línea. **`SESSION_DOMAIN` no se fija nunca**, y así debe estar escrito donde alguien lo lea antes de desplegar, no solo aquí.

---

## 3. Servicios externos y degradación

| Servicio | Uso | Si no responde |
|----------|-----|----------------|
| **PostgreSQL** | Todo, incluida la sesión (`SESSION_DRIVER=database`) | La API no sirve y nadie puede iniciar sesión. Sin degradación posible ni deseable |
| **Redis** | Caché de configuración, colas y **límites de tasa** | La caché degrada a consulta directa. Las colas **no degradan**: sin Redis no salen los correos de recuperación ni de bloqueo. **El límite de tasa no degrada a "sin límite"**: si el almacén del limitador no responde, el endpoint responde `503`, nunca se abre. Un limitador que falla en abierto convierte una caída de caché en una ventana de fuerza bruta |
| **Correo transaccional** | Recuperación de contraseña y aviso de bloqueo | Depende de `0.10c`, **sin decidir** (`OPEN-AUTH-07`). El trabajo reintenta; agotados los reintentos, **el token queda emitido y vivo** pero nadie lo ha recibido. El usuario queda sin poder recuperar su cuenta hasta que un administrador intervenga. Es la degradación más grave del módulo y no tiene rodeo técnico: es una dependencia de negocio |
| **S3 / MinIO** | **No se usa.** Este módulo no escribe ni lee ficheros | — |

Sin *circuit breaker*: no hay integración con un tercero de latencia impredecible más allá del correo, que ya va en cola con reintentos.

---

## 4. Colas y trabajos (`INV-012`)

Ninguna de estas operaciones ocurre en el ciclo de petición HTTP.

| Cola | Trabajo | Disparo | Reintentos |
|------|---------|---------|------------|
| `auth-mail` | `SendPasswordResetEmail` | `POST /auth/password-reset-requests` con cuenta activa detrás | 5, retroceso exponencial (1 min → 30 min) |
| `auth-mail` | `SendAccountLockedEmail` | Creación de un bloqueo **con cuenta existente** | 5, mismo retroceso |
| `auth-mail` | `SendPasswordChangedEmail` | Canje o restablecimiento completado | 3 |
| `auth-maintenance` | `PurgeExpiredPasswordResetTokens` | Programado, diario | — |
| `auth-maintenance` | `PurgeUnlockTokens` | Programado, diario | — |
| `auth-maintenance` | `PurgeLoginAttempts` | Programado, diario | — |
| `auth-maintenance` | `CloseExpiredLockouts` | Programado, **cada 5 minutos** | — |
| (ninguna, comando directo) | `queue:prune-failed --hours=24` | Programado, diario | — |

Reglas de los trabajos, heredadas de `ADR-033 §8` y de lo aprendido en 1.1:

- **El contexto de tenant lo entra y lo sale el mecanismo de framework** (`Queue::createPayloadUsing()` estampa el `tenant_id` al despachar; los *listeners* de `JobProcessing`/`JobProcessed`/`JobFailed` lo gestionan). Ningún trabajo de este módulo lo maneja a mano — la revisión de seguridad de 1.1 ya confirmó que ese mecanismo es suficiente.
- **`SendPasswordResetEmail` y `SendAccountLockedEmail` implementan `ShouldBeEncrypted`** (issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73)): el token en claro que llevan en su *payload* viaja y se almacena cifrado con `APP_KEY`, tanto en la cola normal como si el trabajo agota sus 5 reintentos y cae en `failed_jobs`. `SendInvitationEmail` de 1.1 comparte el mismo patrón de "token en el *payload*" pero **no** implementa `ShouldBeEncrypted` todavía — revisar si corresponde ampliarlo ahí también. `queue:prune-failed --hours=24` (arriba) es la segunda capa: no conservar el *payload* fallido más de un día, ni siquiera cifrado.
- `SendPasswordChangedEmail` **no** lleva token: es un aviso, sin enlace accionable. Es la única defensa que tiene un usuario contra un cambio de contraseña que no hizo él.
- Los tres trabajos de purga se ejecutan **por tenant** con `RunsPerTenant`, no en una pasada global sin contexto.
- `PurgeLoginAttempts` **se ejecuta con el rol propietario**: `tenantTableAppendOnly()` revoca `DELETE` a `plataforma_app` y a `plataforma_platform` (`datos.md §A.9`). Es la misma mecánica que la purga de `audit_logs` de `REQ-PRIV-006`, y si se implementa con el rol de aplicación **fallará en silencio con cero filas borradas**, que es exactamente el síntoma de §9.
- El *scheduler* corre en su propio contenedor, no en el de la API (`ADR-037`).

### 4.1 Qué borra cada purga

| Trabajo | Qué borra | Base |
|---------|-----------|------|
| `PurgeExpiredPasswordResetTokens` | Filas de `password_reset_tokens` con `expires_at` vencido | Artefacto transitorio; el token ya no sirve |
| `PurgeUnlockTokens` | Pone a `NULL` `unlock_token_hash` y `unlock_token_expires_at` de los bloqueos cuyo token venció. **No toca la fila del bloqueo** | Minimización: material de token sin uso posible |
| `PurgeLoginAttempts` | Filas de `login_attempts` con más de `AUTH_LOGIN_ATTEMPT_RETENTION_DAYS` | Correo e IP son datos personales; 90 días (`datos.md §A.9`) |

**Ninguna toca `audit_logs`**: su retención es `REQ-PRIV-006` (`OPEN-CORE-11`).

`CloseExpiredLockouts` no es una purga (no borra ni redacta nada): cierra como `caducidad` los bloqueos vencidos que el camino de login no haya cerrado ya de forma perezosa (`funcional.md §4.4`, `RN-AUTH-38`). Se lista aquí porque comparte cola y mecanismo con las purgas, con periodicidad distinta a propósito (cada 5 minutos, no diaria: un bloqueo vencido sin cerrar ocupa el hueco del índice único parcial de `RN-AUTH-17`).

---

## 5. Correos que emite el módulo

Tres, los tres en los cuatro idiomas de `ADR-021` (`INV-009`, `CA-AUTH-073`) y en el idioma preferido del destinatario (`REQ-CORE-006` capa 2):

| Correo | Contenido | Enlace |
|--------|-----------|--------|
| Recuperación de contraseña | Aviso de que se solicitó, con caducidad explícita en minutos | `https://{slug}.{base}/restablecer/{token}` |
| Cuenta bloqueada | Aviso del bloqueo, número de intentos y hora | `https://{slug}.{base}/desbloquear/{token}` |
| Contraseña cambiada | Aviso de que se cambió, con hora e indicación de qué hacer si no fue el titular | **Ninguno** (§4) |

Reglas comunes:

- **Ningún correo revela si la cuenta existe a quien no es su titular**: los dos primeros solo se envían cuando hay cuenta detrás, y quien los provoca sin ser el titular no recibe nada distinto del `202`/`423` genérico.
- **El token viaja solo en el enlace**, nunca en el asunto ni en texto plano suelto que un reenvío accidental deje visible fuera de contexto.
- El remitente y el dominio de envío dependen de `0.10c` (**pendiente**). En desarrollo basta el *mailer* `log`, y los tests comprueban que el trabajo **se encola**, no que el correo llega (misma convención que 1.1).

---

## 6. Límites de tasa

Es la única defensa activa de los seis endpoints anónimos, y por eso va en su propia sección.

| Endpoint | Límite | Clave |
|----------|--------|-------|
| `POST /auth/session` | 10 / min | IP |
| `POST /auth/session` | 5 / min | `(tenant_id, email)` |
| `POST /auth/password-reset-requests` | 5 / hora | IP |
| `POST /auth/password-reset-requests` | 3 / hora | `(tenant_id, email)` |
| `POST /auth/invitation-redemptions` · `POST /auth/password-resets` · `POST /auth/account-unlocks` | 10 / hora | IP |
| `GET /auth/csrf-cookie` | 60 / min | IP |

- **Toda clave incluye el `tenant_id`** (`ADR-033 §9`). Un límite compartido entre centros permitiría a un tenant agotar la cuota de otro, que es una fuga de disponibilidad entre tenants aunque no lo sea de datos.
- **El límite por `(tenant, email)` es independiente del bloqueo de cuenta y no lo sustituye.** El bloqueo cuenta fallos consecutivos y persiste; el límite de tasa cuenta peticiones por ventana y se olvida. Uno defiende la cuenta, el otro el servidor.
- **`429` siempre con `Retry-After`** (`ADR-038 §6.5`).
- El límite por IP tiene un punto ciego conocido: un centro entero detrás de una única IP de salida. Con 10 logins por minuto por IP, un aula de informática con 25 alumnos entrando a la vez a las 9:00 llegaría al límite. **Hay que medirlo con `REQ-SEED` (1.15b) antes de fijar el número definitivo** y considerar una lista de IPs de confianza por tenant si el caso es real. Se anota aquí porque es el tipo de límite que se descubre el primer día de clase, no en pruebas.

---

## 7. Caché

| Clave | Contenido | TTL | Invalidación |
|-------|-----------|-----|--------------|
| `tenant:{id}:settings` | Incluye ahora `session_timeout_minutes` | 10 min | En `PATCH /tenant/settings` (`RN-CORE-17`) |

**Este módulo no añade ninguna clave de caché propia**, y es deliberado:

- **El estado de bloqueo no se cachea.** Se consulta en base de datos en cada intento de login. Una caché de 60 segundos aquí significaría que una cuenta recién desbloqueada sigue rechazando durante un minuto, y —peor— que una cuenta recién bloqueada sigue aceptando intentos durante otro.
- **El recuento de fallos consecutivos no se cachea** por el mismo motivo, y porque el índice `(tenant_id, email, attempted_at DESC)` lo resuelve con un recorrido corto.
- La sesión vive en PostgreSQL, no en Redis (§2.2).

Todas las claves llevan **prefijo de tenant** (`ADR-033 §9`). Una clave de caché sin prefijo de tenant es una fuga que la RLS no detecta.

---

## 8. Métricas y alertas

| Métrica | Alerta |
|---------|--------|
| Bloqueos creados por hora y por tenant | Pico ⇒ ataque de fuerza bruta o de relleno de credenciales. `RSEC-OWASP-009` |
| **Bloqueos con `user_id` nulo** (fantasma) | Crecimiento sostenido ⇒ **enumeración de cuentas en curso**. Es la señal más específica del módulo: nadie escribe cinco veces un correo que no existe por error |
| Ratio de `login_attempts` con `credenciales_invalidas` sobre el total | > 30 % sostenido ⇒ ataque o problema de usabilidad real |
| `401` de expiración por inactividad, por tenant | Pico tras cambiar `session_timeout_minutes` ⇒ el centro se ha pasado de restrictivo |
| `423` por hora | Mide el coste operativo del bloqueo indefinido, que es el dato con el que responder a `OPEN-AUTH-03` |
| Correos de `auth-mail` fallidos tras agotar reintentos | > 3 en 1 h ⇒ **incidencia grave**: hay usuarios que no pueden recuperar su cuenta |
| Tokens de restablecimiento emitidos frente a consumidos | Ratio bajo sostenido ⇒ los correos no llegan, aunque el proveedor no dé error |
| Latencia p95 de `POST /auth/session` | Regresión ⇒ revisar `AUTH_BCRYPT_ROUNDS`. Es el único endpoint del sistema cuya lentitud es **deliberada**, así que su umbral no es el de `RNF-PERF-001` (200 ms): con coste 12, entre 200 y 400 ms es normal y correcto |
| `429` por endpoint | Pico en `password-reset-requests` ⇒ intento de usar la plataforma como remitente de correo |
| Sesiones activas por tenant | Comparar con `RNF-PERF-002` (3.000 usuarios, 600 concurrentes) |
| Volumen de `login_attempts` | Crecimiento hacia 20 M ⇒ disparador de particionado (`datos.md §A.8`) |

---

## 9. Problemas conocidos y diagnóstico

| Síntoma | Causa probable |
|---------|----------------|
| **La sesión no persiste entre peticiones; cada llamada responde `401`** | `SESSION_DOMAIN` fijado (debería abortar el arranque; si no aborta, la guarda no está puesta), o la SPA sin `credentials: 'include'`, o SPA y API en hosts distintos, lo que `ADR-028` prohíbe |
| **La cookie del tenant A funciona en el tenant B** | Fallo **crítico** (`INV-001`). `SESSION_DOMAIN` con valor, o la reverificación de `RN-AUTH-31` no está en la cadena de *middleware*. Detener el trabajo y resolverlo de inmediato (`CLAUDE.md §5`) |
| **La sesión nunca expira por inactividad** | Se está leyendo `sessions.last_activity` en vez de la marca del *payload*. El controlador de sesión de Laravel refresca esa columna **antes** de que corra ningún *middleware*, así que siempre da «actividad ahora mismo». Es la trampa concreta de este flujo (`funcional.md §4.6`, punto 3) |
| **La sesión expira antes de lo configurado** | `SESSION_LIFETIME` menor que el timeout del tenant (debería abortar el arranque, `CA-AUTH-052`) |
| **`419`/`403` en todas las escrituras** | La SPA no reenvía `X-XSRF-TOKEN`, o `ValidateCsrfToken` está antes de `StartSession` en la cadena (`api.md §8`) |
| **El idioma del usuario autenticado no se aplica** | `ResolveApiLocale` corre antes de `StartSession` y `$request->user()` le devuelve `null`. Es el defecto latente de 1.1 que 1.2 corrige (`CA-AUTH-075`) |
| **Nadie recibe correos de recuperación** | *Worker* de `auth-mail` caído, Redis no disponible, o `0.10c` sin resolver. Comprobar en este orden. **Los tokens siguen emitidos y vivos**: el problema es de entrega, no de emisión |
| **Un usuario dice que no puede entrar y no está bloqueado** | Su `status` es `pendiente` (no canjeó la invitación) o `inactivo` (baja). La respuesta al usuario es un `401` genérico a propósito (`funcional.md §4.7`), así que **esto solo se diagnostica desde dentro**, mirando `GET /users` o `login_attempts.outcome = 'estado_no_activo'` |
| **Enlace de activación o de recuperación devuelve `404`** | El host del enlace no resuelve tenant: `TENANCY_BASE_DOMAIN` mal configurado o DNS sin comodín (`OPEN-08`, paso 0.10b) |
| **`PurgeLoginAttempts` corre sin errores y no borra nada** | Se está ejecutando con `plataforma_app`, que tiene `DELETE` revocado sobre la tabla append-only. Debe usar el rol propietario (§4) |
| **Un token válido devuelve `410`** | Se está presentando en el host equivocado: un token del tenant A en el host del tenant B **es** `410`, y es el comportamiento correcto (`CA-AUTH-033`, `CA-AUTH-042`) |
| **El login tarda ~300 ms** | Es bcrypt con coste 12 haciendo su trabajo. No es una regresión (§8) |
| **Un administrador con `mfa_required = true` entra solo con contraseña** | **Esperado en 1.2**: el atributo está sembrado desde 1.1 y nada lo comprueba hasta 1.3 (`permisos.md §5.4`) |

---

## 10. Impacto en copias de seguridad y restauración

`REQ-BKP` es el paso 1.26 y `0.10d` sigue pendiente. Lo que 1.2 aporta al alcance de esa copia:

- **Base de datos**: dos tablas nuevas (`login_attempts`, `account_lockouts`) más las columnas nuevas de `password_reset_tokens` y `tenant_settings`. Entran en la copia general sin nada especial.
- **`sessions` no debe restaurarse**. Restaurar sesiones desde una copia reintroduce identificadores de sesión que ya deberían estar muertos y, con ellos, sesiones de usuarios que quizá ya no existen. La restauración debe **vaciar** la tabla: el coste es que todo el mundo vuelve a entrar, que es lo correcto tras un incidente. Hay que escribirlo en el procedimiento de 1.26 porque el impulso natural es restaurarlo todo.
- **`login_attempts` puede excluirse del respaldo** si el volumen molesta: es telemetría de 90 días, no dato de negocio. **`account_lockouts` no**: perderla al restaurar desbloquearía a todo el mundo silenciosamente, incluidas las cuentas que estaban bajo ataque en el momento de la copia.
- **Una restauración a un punto anterior puede resucitar contraseñas antiguas.** Si un usuario cambió su contraseña después del punto de restauración, tras restaurar volverá a valer la anterior — y si el cambio se hizo precisamente porque la anterior estaba comprometida, la restauración reabre el compromiso. **Todo procedimiento de restauración debe incluir la invalidación de sesiones y la comunicación a los usuarios afectados**, y esto pertenece al *runbook* de 1.26, no a una nota al pie.
- **Nada que copiar fuera de la base de datos**: este módulo no escribe ficheros.

---

## 11. Despliegue

Orden obligatorio, coherente con expand/contract (`CLAUDE.md §9`):

1. **Migraciones**: dos tablas nuevas (aditivas) y dos `ALTER` *expand* (`datos.md §A.3`, `§A.4`). Ninguna es destructiva; ninguna toca `sessions`.
2. **Despliegue de la aplicación.** Las guardas de arranque de §2.2 se evalúan aquí, en **todos** los entornos, no solo producción: un `EnvironmentFile` con `SESSION_DOMAIN` fijado, o con `SESSION_LIFETIME` por debajo de `AUTH_SESSION_TIMEOUT_MAX_MINUTES` (480 por defecto), impide que el contenedor arranque — y como la aplicación se interpreta por petición sin necesidad de reconstruir imagen, un entorno que ya estaba corriendo con un `.env` desajustado se cae en la primera petición tras el despliegue, no en el arranque del proceso. Ocurrió de verdad en un entorno de desarrollo al aterrizar este mismo paso (issue [#62](https://github.com/pirexia/plataforma-educativa/issues/62)). Es el comportamiento buscado, pero hay que saberlo antes de desplegar a las 8:00 de un lunes — conviene verificar el fichero de entorno (`SYSADMIN.md §2c`) **antes** de la ventana, no después de que un healthcheck empiece a fallar.
3. **`php artisan platform:sync-registry`** — materializa `bloqueo_cuenta.leer` y `bloqueo_cuenta.eliminar` y la entrada `auth` del catálogo. Sin este paso, `GET`/`DELETE /account-lockouts` deniegan por defecto, que es correcto pero desconcertante.
4. **`php artisan auth:grant-lockout-permissions`** — concede `bloqueo_cuenta.leer`/`bloqueo_cuenta.eliminar` al rol `administrador_centro` de cada tenant existente. El aprovisionamiento de 1.1 solo corre en el alta de un centro nuevo, así que los centros ya creados **no** reciben los permisos nuevos por sí solos; este comando es la migración de datos idempotente que los concede (una segunda ejecución no duplica filas). **Es el paso que más fácil se olvida** y su síntoma es un `403` inexplicable en un centro que existía antes del despliegue.
5. Reinicio de los *workers* para que recojan las colas `auth-mail` y `auth-maintenance`.

**Reversión.** El código revierte limpiamente: las migraciones son aditivas y su `down()` elimina tablas y columnas que ninguna otra referencia. Dos avisos que hay que leer antes de revertir:

- **Al revertir, nadie puede iniciar sesión** — se vuelve al estado de 1.1, donde no existe login. No es una degradación parcial: es la pérdida total del acceso interactivo. Cualquier reversión de esta entrega es, en la práctica, una parada del servicio para los usuarios.
- **Las contraseñas fijadas durante la vigencia de la entrega se conservan** (están en `users.password`, que 1.1 ya tenía), igual que los usuarios activados. Revertir no deshace las activaciones: deja usuarios `activo` con contraseña válida y sin ningún camino para usarla. Al volver a desplegar, todo funciona sin intervención.

---
---

# Parte B · Paso 1.2b · Operación

> Alcance: paso **1.2b** (`funcional.md` Parte B). **Estado**: propuesta, pendiente de `funcional.md §B.14`.

---

## B.1 Comportamiento con el módulo activo o inactivo

Sin cambios: **`REQ-AUTH` sigue sin ser desactivable** (`RN-AUTH-35`), y **ninguna de las tres rutas nuevas lleva el *middleware* `module-enabled`** (`CA-AUTH-078`). El argumento de §1 se aplica igual: una fila mal puesta en `module_subscriptions` no puede poder dejar a un centro sin poder entrar — ni, ahora, sin poder cerrar una sesión comprometida.

---

## B.2 Variables de entorno

### B.2.1 Propias del paso

| Variable | Uso | Valor en desarrollo |
|----------|-----|---------------------|
| `AUTH_DEVICE_COOKIE_TTL_DAYS` | Vida de la cookie `pge_device` (`RN-AUTH-45`) | `365` |
| `AUTH_NEW_DEVICE_ALERTS_PER_DAY` | Tope de alertas de dispositivo nuevo por usuario y día natural (`RN-AUTH-46`) | `5` |
| `AUTH_USER_SESSION_RETENTION_DAYS` | Purga de `user_sessions` cerradas (`datos.md §B.7`) | `90` |
| `AUTH_KNOWN_DEVICE_RETENTION_DAYS` | Purga de dispositivos sin uso (`datos.md §B.7`) | `365` |
| `AUTH_USER_AGENT_MAX_LENGTH` | Truncado del `User-Agent` antes de persistirlo (`datos.md §B.2`) | `1024` |

Ninguna es un secreto.

**Sobre `AUTH_NEW_DEVICE_ALERTS_PER_DAY = 5`.** Es un número puesto sin medición y hay que decirlo. Existe para un caso concreto: el navegador que rechaza o borra cookies en cada cierre, para el que **todos** los accesos son «dispositivo nuevo» y que sin tope recibiría un correo por login. Cinco deja pasar el uso normal de una persona con varios equipos y corta la avalancha. **Hay que revisarlo con `REQ-SEED` (1.15b)** antes de fijarlo, igual que `operacion.md §6` obliga a revisar el límite de tasa por IP: es el mismo tipo de número que se descubre mal el primer día de clase y no en pruebas.

**Y una consecuencia del tope que hay que aceptar conscientemente**: con el tope agotado, un acceso desde dispositivo nuevo **se registra pero no se avisa**, y `user_known_devices.alerted_at` queda `NULL` (`datos.md §B.1`). Es decir, el tope puede silenciar precisamente la alerta que importaba. Se acepta porque la alternativa —avisar siempre— produce un buzón lleno de avisos que nadie lee, que silencia lo mismo con más ruido; y porque la columna `alerted_at` deja la traza de que ocurrió, que es lo que permite investigarlo después.

### B.2.2 Guarda nueva sobre una variable heredada

| Variable | Valor obligatorio | Guarda |
|----------|-------------------|--------|
| `SESSION_DRIVER` | `database` | **Nueva en 1.2b**: la aplicación **aborta el arranque** con cualquier otro valor, en todos los entornos (`RN-AUTH-49`, `CA-AUTH-103`) |

§2.2 ya exigía `database` **en prosa**, sin nada que lo comprobara, y `DatabaseSessionRevoker` está escrito para no hacer nada si el driver es otro. Mientras la revocación era un efecto colateral interno del cambio de contraseña, la degradación silenciosa era tolerable. Con el panel de sesiones deja de serlo: el usuario pulsaría «cerrar sesión», recibiría `204` y la sesión seguiría abierta. **Un requisito de configuración sin guarda no es un requisito, es una esperanza** — es el mismo razonamiento con el que 1.2 puso guarda a `SESSION_DOMAIN` (`funcional.md §6.2`) en vez de confiar en el valor por defecto.

Las guardas de §2.2 siguen todas en vigor sin cambios, y `SYSADMIN.md` y la plantilla de `EnvironmentFile=` de `ADR-037` recogen la línea nueva con su motivo, no solo esta tabla.

---

## B.3 Colas y trabajos (`INV-012`)

| Cola | Trabajo | Disparo | Reintentos |
|------|---------|---------|------------|
| `auth-mail` | `SendNewDeviceLoginEmail` | Acceso desde dispositivo no reconocido, con tope no agotado | 3, retroceso exponencial |
| `auth-maintenance` | `CloseOrphanedUserSessions` | Programado, **cada 15 minutos** | — |
| `auth-maintenance` | `PurgeUserSessions` | Programado, diario | — |
| `auth-maintenance` | `PurgeUserKnownDevices` | Programado, diario | — |

Reglas de estos cuatro, además de las de §4 que siguen aplicando:

- **`SendNewDeviceLoginEmail` transporta identificadores, no datos personales.** Su *payload* lleva el `user_id` y el `public_id` de la sesión, y el trabajo **relee** la fila para componer el correo. Es la diferencia con `SendPasswordResetEmail`, que **tiene** que llevar el token en claro porque en base de datos solo está el hash: aquí no hay nada que no se pueda releer, así que no hay motivo para que la IP y la descripción del cliente viajen por Redis y acaben en `failed_jobs` si el correo falla. Es la lección del issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73) aplicada antes de tropezar con ella.
- **Por lo mismo, `SendNewDeviceLoginEmail` no necesita `ShouldBeEncrypted`.** Un `user_id` y un ULID no son material sensible. Se anota explícitamente porque la revisión de seguridad, con el precedente de #73 y #75 delante, preguntará por ello — y la respuesta correcta no es «se lo ponemos por si acaso», es que el *payload* no lleva nada que cifrar.
- **`SendNewDeviceLoginEmail` no lleva enlace accionable** (`RN-AUTH-50`), como `SendPasswordChangedEmail`. Es un aviso.
- **Si la fila de la sesión ya no existe cuando el trabajo se ejecuta** —el usuario la revocó en los segundos siguientes—, el correo **se envía igualmente** con la información que quede del dispositivo. Avisar de un acceso que ya se cerró sigue siendo información útil; no avisar porque el propio usuario reaccionó rápido sería perder el aviso justo en el caso en que hubo reacción.
- Los tres trabajos de mantenimiento se ejecutan **por tenant** con `RunsPerTenant`, como los de §4.
- **Ninguno necesita el rol propietario.** `user_sessions` y `user_known_devices` son tablas de tenant ordinarias, sin el `REVOKE DELETE` de `tenantTableAppendOnly()`. Es la diferencia con `PurgeLoginAttempts`, cuyo modo de fallo característico —«corre sin errores y no borra nada»— **no puede darse aquí** (§9).
- El *scheduler* corre en su propio contenedor, no en el de la API (`ADR-037`).

### B.3.1 Qué hace cada tarea de mantenimiento

| Trabajo | Qué hace | Base |
|---------|----------|------|
| `CloseOrphanedUserSessions` | Cierra como `caducidad` las filas vivas de `user_sessions` cuyo identificador ya no está en `sessions` | `funcional.md §B.4.7`. **No es una purga**: no borra ni redacta nada. Comparte cola con las purgas y no periodicidad, igual que `CloseExpiredLockouts` |
| `PurgeUserSessions` | Borra físicamente las filas con `ended_at` anterior a `AUTH_USER_SESSION_RETENTION_DAYS` | IP y `User-Agent` son datos personales; 90 días (`datos.md §B.7`) |
| `PurgeUserKnownDevices` | Borra físicamente las filas con `last_seen_at` anterior a `AUTH_KNOWN_DEVICE_RETENTION_DAYS` | Un dispositivo cuya cookie ya caducó no reconoce nada: conservarlo es guardar un identificador de navegador sin finalidad |

**Ninguna toca `audit_logs`** ni `sessions`. La retención de la primera es `REQ-PRIV-006`; la segunda la gobierna el recolector del framework con `SESSION_LIFETIME`.

**Por qué `CloseOrphanedUserSessions` cada 15 minutos y no cada 5 como `CloseExpiredLockouts`.** Un bloqueo vencido sin cerrar **ocupa el hueco** del índice único parcial de `RN-AUTH-17` e impide crear el siguiente: es un problema funcional que corre contra el reloj. Una sesión huérfana sin cerrar no bloquea nada —el índice único de `user_sessions` es por identificador de sesión, y el del framework nunca se repite— y además el listado la cierra perezosamente en cuanto alguien mira (`funcional.md §B.4.2`). La tarea solo recoge lo que nadie vuelva a mirar, así que 15 minutos sobran.

---

## B.4 Correos que emite el módulo

**Cuatro**, con el nuevo. Los cuatro en los cuatro idiomas de `ADR-021` (`INV-009`, `CA-AUTH-100`) y en el idioma preferido del destinatario (`REQ-CORE-006` capa 2).

| Correo | Contenido | Enlace |
|--------|-----------|--------|
| Recuperación de contraseña | §5 | `/restablecer/{token}` |
| Cuenta bloqueada | §5 | `/desbloquear/{token}` |
| Contraseña cambiada | §5 | **Ninguno** |
| **Acceso desde un dispositivo nuevo** | Momento del acceso, IP, descripción del cliente («Chrome · Windows · escritorio») y qué hacer si no fue el titular: revisar sus sesiones y cambiar la contraseña | `https://{slug}.{base}/cuenta/sesiones` — **ruta de la SPA que exige sesión**. No ejecuta ninguna acción por sí solo (`RN-AUTH-50`) |

Reglas comunes de §5, más dos propias del cuarto:

- **El correo no dice nunca «alguien ha accedido a tu cuenta».** Dice que se ha accedido desde un dispositivo que no se había visto antes, que es lo que el sistema sabe. La diferencia importa: la mayoría de estos avisos serán el propio usuario estrenando un portátil, y un mensaje alarmista los entrena a ignorarlo.
- **No incluye ubicación en 1.2b** (`RN-AUTH-47`, `OPEN-AUTH-13`). La plantilla se escribe con el hueco preparado —si `location` es `null`, no se pinta la línea— para que resolver la pregunta abierta no obligue a rehacer cuatro traducciones.

---

## B.5 Métricas y alertas

Ampliación de §8:

| Métrica | Alerta |
|---------|--------|
| Alertas de dispositivo nuevo por hora y tenant | Pico ⇒ o un despliegue ha invalidado cookies, o hay relleno de credenciales con éxito. Las dos merecen mirarse |
| **Alertas suprimidas por tope** (`AUTH_NEW_DEVICE_ALERTS_PER_DAY` agotado) | Cualquier valor sostenido > 0 ⇒ el tope está mal calibrado, o hay un cliente que no conserva cookies. **Es la métrica que dice si el mecanismo está funcionando o silenciándose** |
| Ratio de logins con dispositivo nuevo sobre el total | Debe caer y estabilizarse bajo tras las primeras semanas. Si se queda alto, la cookie no persiste —CSP, atributos mal puestos, un proxy que la elimina— y **el punto 4 del requisito no está funcionando aunque nadie vea un error** |
| Revocaciones por usuario y día | Pico en una cuenta ⇒ un usuario reaccionando a algo. Merece contacto, no una alerta automática |
| `end_reason = 'tenant_incoherente'` | **Cualquier valor distinto de cero es un incidente** (`INV-001`, `RN-AUTH-31`). Es la única razón de cierre que no ocurre en operación normal, y por eso tiene valor propio en el enumerado |
| Filas vivas de `user_sessions` frente a filas de `sessions` con `user_id` no nulo | Divergencia sostenida ⇒ algún camino cierra la sesión sin cerrar la fila, o `CloseOrphanedUserSessions` no corre. Es la comprobación de salud del diseño de `funcional.md §B.4.7` |
| Sesiones activas por tenant | Ya estaba en §8; **ahora es consultable de verdad**, sin leer la tabla del framework |
| Volumen de `audit_logs` por evento | Vigilar `created` sobre `UserSession` si se implementa la opción (a) de `OPEN-AUTH-16`: es el dato con el que responder esa pregunta |

---

## B.6 Despliegue

Orden obligatorio, coherente con expand/contract (`CLAUDE.md §9`):

1. **Migraciones**: `user_known_devices` primero, `user_sessions` después (la segunda referencia a la primera). Las dos son aditivas; **ninguna toca una tabla existente**, así que ninguna puede bloquear escrituras de la versión en marcha (`datos.md §B.6`).
2. **Despliegue de la aplicación.** La guarda nueva de `SESSION_DRIVER` se evalúa aquí, **en todos los entornos**. Aplica el aviso del issue [#62](https://github.com/pirexia/plataforma-educativa/issues/62) sin cambios: un `EnvironmentFile` desajustado tumba el contenedor en la primera petición tras el despliegue, no al arrancar el proceso. **Verificar el fichero de entorno antes de la ventana** (`SYSADMIN.md §2c`), no después de que el healthcheck empiece a fallar.
3. **Reinicio de los *workers*** para que recojan el trabajo nuevo de `auth-mail` y los tres de `auth-maintenance`.

**No hay paso 4.** 1.2b **no** necesita `platform:sync-registry` ni ningún comando de concesión de permisos, porque no declara ninguno (`permisos.md §B.1`). Se dice explícitamente porque el paso equivalente de 1.2 —`auth:grant-lockout-permissions`— era «el que más fácil se olvida» (§11, punto 4), y aquí la respuesta correcta a «¿me he olvidado del comando?» es que no existe.

**Reversión.** Limpia, y notablemente menos grave que la de 1.2:

- **Nadie se queda fuera del sistema.** Revertir 1.2b devuelve al estado de 1.2: login, logout, recuperación y cambio de contraseña siguen funcionando exactamente igual. Se pierden el panel, el cierre remoto y la alerta.
- **Las sesiones abiertas sobreviven.** `sessions` no se toca, así que nadie tiene que volver a entrar. Es la consecuencia buena de la decisión de `funcional.md §B.2.2`.
- **Se pierde el historial de sesiones y de dispositivos** al eliminar las dos tablas. Es información de seguridad, no de negocio, y su pérdida no impide operar.
- **Las cookies `pge_device` siguen en los navegadores** y dejan de significar nada. Al volver a desplegar, todas se presentarán como desconocidas y **todo el mundo recibirá un aviso de dispositivo nuevo a la vez**. No es un fallo, pero hay que saberlo antes de revertir y volver a desplegar en el mismo día: el tope de `AUTH_NEW_DEVICE_ALERTS_PER_DAY` acota el daño por usuario, no el volumen agregado de correo saliente.

---

## B.7 Servicios externos, degradación y caché

| Servicio | Uso nuevo | Si no responde |
|----------|-----------|----------------|
| **PostgreSQL** | Las dos tablas nuevas, en el camino del login | Sin degradación: `funcional.md §B.4.1` pone el registro de sesión **dentro** de la transacción del login a propósito. Una sesión que existe y no aparece en el panel es peor que no poder entrar |
| **Redis** | Cola `auth-mail` para la alerta | La alerta **no degrada a nada**: sin Redis no sale. El acceso sí se registra, así que el panel dice la verdad aunque el correo no llegue |
| **Correo transaccional** | La alerta de dispositivo nuevo | `0.10c`, **sin decidir** (`OPEN-AUTH-07`). Menos grave que en 1.2 —aquí nadie se queda fuera del sistema—, pero **anula el sub-requisito**: la detección funciona y nadie se entera |
| **Geolocalización por IP** | **Ninguno.** No hay servicio | `OPEN-AUTH-13`. La implementación nula devuelve «desconocida» y `location` viaja `null`; **no hay llamada saliente que pueda fallar**, que es una propiedad que conviene no perder al resolver la pregunta abierta |

**Caché: este paso no añade ninguna clave**, y es deliberado por el mismo motivo de §7:

- **El estado de una sesión no se cachea.** Se consulta en base de datos. Una caché de 60 segundos significaría que una sesión recién revocada sigue apareciendo como viva en el panel durante un minuto, justo después de que el usuario haya pulsado el botón para cerrarla.
- **El reconocimiento de dispositivo no se cachea.** Ocurre una vez por login, con un índice único que lo resuelve en una lectura.

---

## B.8 Problemas conocidos y diagnóstico

Ampliación de §9. Los trece síntomas de allí siguen vigentes.

| Síntoma | Causa probable |
|---------|----------------|
| **El panel está vacío pese a tener sesión abierta** | La fila de `user_sessions` no se creó en el login. Comprobar que el registro va **después** de `session()->regenerate()`: si va antes, guarda el identificador viejo y la fila queda huérfana desde el primer segundo (`funcional.md §B.2.1`, punto 2) |
| **Una sesión revocada sigue funcionando** | `SESSION_DRIVER` distinto de `database` (debería abortar el arranque, `CA-AUTH-103`), o la revocación cierra la fila de `user_sessions` sin borrar la de `sessions`. La fila que manda es la del framework: sin borrarla no pasa nada (`RN-AUTH-42`) |
| **El panel muestra sesiones que ya no existen** | El cierre perezoso del listado no está implementado y solo actúa `CloseOrphanedUserSessions`. Síntoma característico: las filas fantasma desaparecen solas a los 15 minutos (`funcional.md §B.4.7`) |
| **Todo el mundo recibe aviso de dispositivo nuevo en cada acceso** | La cookie `pge_device` no persiste. Por orden: atributos mal puestos (`Secure` sobre HTTP en desarrollo la descarta en silencio), un proxy que elimina cookies desconocidas, o `SESSION_DOMAIN`/host distinto entre la SPA y la API — la misma familia de causas del issue [#71](https://github.com/pirexia/plataforma-educativa/issues/71) |
| **Nadie recibe aviso de dispositivo nuevo, y tampoco hay errores** | Tres candidatos, en este orden: el tope diario agotado (mirar `alerted_at IS NULL`, §B.5), el *worker* de `auth-mail` caído, o `0.10c` sin resolver. **Los tres son silenciosos**, y por eso la métrica de alertas suprimidas de §B.5 no es opcional |
| **El aviso llega en cada actualización del navegador** | Alguien ha metido el `User-Agent` en el criterio de reconocimiento. **No es el criterio** (`RN-AUTH-46`): el criterio es solo la cookie, y la descripción de cliente no participa en ninguna decisión (`funcional.md §B.6.4`) |
| **Un usuario ve sesiones que no son suyas** | Fallo **crítico** (`INV-001`, `RN-AUTH-41`). El `user_id` del solicitante no está en el `WHERE`. Detener el trabajo y resolverlo de inmediato (`CLAUDE.md §5`) |
| **`DELETE` de una sesión ajena responde `403`** | Debe responder `404` (`api.md §B.3`). Un `403` confirma que el recurso existe, y eso es un oráculo de sesiones vivas ajenas |
| **Aparecen filas de `user_sessions` con `user_id` nulo** | Imposible por esquema (`tenantForeignId()` es `NOT NULL`). Si el error aparece al insertar, se está intentando registrar una sesión anónima: `GET /auth/csrf-cookie` **no** crea fila aquí (`funcional.md §B.4.1`) |
| **El identificador de sesión aparece en una fila de auditoría** | `session_id` no está declarado en `auditSecretAttributes` del modelo. **No lo cubre ningún patrón automático** (`datos.md §B.2`). Es una fuga de credenciales a una tabla *append-only* con dos años de retención: severidad **Crítica**, y la tabla no admite `UPDATE` para corregirla |

---

## B.9 Impacto en copias de seguridad y restauración

Amplía §10, y **una de sus notas cambia de consecuencia**:

- **Base de datos**: dos tablas nuevas. Entran en la copia general sin nada especial.
- **`user_sessions` puede excluirse del respaldo**; `user_known_devices` **no**. Perder los dispositivos conocidos al restaurar no rompe nada, pero hace que **todos los usuarios reciban un aviso de dispositivo nuevo en su siguiente acceso**, justo después de un incidente y justo cuando el ruido es más caro. Conservarla evita esa avalancha.
- **`sessions` sigue sin restaurarse** (§10), y ahora hay que añadir la consecuencia sobre este paso: al vaciarla, **todas las filas vivas de `user_sessions` quedan huérfanas a la vez**. `CloseOrphanedUserSessions` las cerrará como `caducidad` en el siguiente ciclo, y el panel dirá la verdad desde el primer momento gracias al cierre perezoso. **No hay que borrar `user_sessions` a mano tras restaurar**, y conviene que esté escrito en el procedimiento de 1.26 porque el impulso natural es hacerlo.
- **Una restauración a un punto anterior resucita sesiones revocadas.** §10 ya avisaba de que resucita contraseñas antiguas; el equivalente aquí es que una sesión que el usuario cerró deliberadamente —quizá porque sospechaba de ella— vuelve a constar como viva en `user_sessions`. Como `sessions` se vacía, **el acceso real no vuelve**: la fila resucitada se cierra como `caducidad` sin que nadie pueda usarla. Es el resultado correcto, y merece estar escrito para que nadie lo interprete como un fallo al leer el panel después de restaurar.
- **Nada que copiar fuera de la base de datos**: este módulo sigue sin escribir ficheros.
