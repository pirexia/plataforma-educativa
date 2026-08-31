# REQ-AUTH · Operación

> **Estructura**: las secciones **§1 a §11** son el paso **1.2**, cerrado el 2026-08-25. La **Parte B** (`§B.1` en adelante) es el paso **1.2b** (`funcional.md` Parte B), **implementada y cerrada** el 2026-08-26 (PR [#91](https://github.com/pirexia/plataforma-educativa/pull/91)/[#92](https://github.com/pirexia/plataforma-educativa/pull/92)).

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

> Alcance: paso **1.2b** (`funcional.md` Parte B). **Estado**: implementada, aprobada el 2026-08-25 (`funcional.md §B.14`), cerrada el 2026-08-26.

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

---
---

# Parte C · Paso 1.3 · Operación (`REQ-AUTH-003`)

> **Estructura**: §1-§11 son 1.2 (cerrado). §B.1-§B.9 son 1.2b (cerrado). Esta **Parte C** es el paso **1.3**, **implementada y cerrada** el 2026-08-27 (PR [#107](https://github.com/pirexia/plataforma-educativa/pull/107), commit `cd13e8a`).

---

## C.1 Comportamiento con el módulo activo o inactivo

Sin cambios: **`REQ-AUTH` no es desactivable** (`RN-AUTH-35`) y ninguna ruta de este paso lleva `module-enabled` (`CA-AUTH-145`).

Lo que sí hay que decir es qué pasa **con el MFA desactivado en un tenant**, que es distinto: un tenant cuyos roles tienen todos `mfa_required = false` y cuyos usuarios no han activado nada funciona **exactamente como en 1.2b**. El login es de un paso, `POST /auth/session` responde `200`, y ninguna de las seis tablas nuevas recibe una sola fila. **El coste de este paso para quien no lo usa es una consulta `EXISTS` por login**, servida por un índice que ya existe.

---

## C.2 Variables de entorno

### C.2.1 Propias del paso

| Variable | Uso | Valor en desarrollo |
|----------|-----|---------------------|
| `AUTH_MFA_CHALLENGE_TTL_MINUTES` | Vida del desafío de segundo factor (`RN-AUTH-54`) | `5` |
| `AUTH_MFA_MAX_ATTEMPTS` | Intentos por desafío y por alta antes de matarlo (`RN-AUTH-54`, `RN-AUTH-59`) | `5` |
| `AUTH_MFA_ENROLLMENT_TTL_MINUTES` | Vida de un alta sin confirmar (`RN-AUTH-59`) | `10` |
| `AUTH_MFA_CODE_TTL_MINUTES` | Vida del código entregado por correo | `10` |
| `AUTH_MFA_MAX_DELIVERIES` | Reenvíos por desafío (`funcional.md §C.4.4.1`) | `3` |
| `AUTH_MFA_RECOVERY_CODE_COUNT` | Códigos de respaldo por juego (`funcional.md §C.4.3`) | `10` |
| `AUTH_MFA_TOTP_WINDOW` | Pasos de tolerancia a cada lado (`RN-AUTH-58`) | `1` |
| `AUTH_MFA_GRACE_DEFAULT_DAYS` | Valor por defecto de `tenant_settings.mfa_grace_period_days` en el aprovisionamiento | `7` |
| `AUTH_MFA_MAX_EXEMPTION_DAYS` | Tope de la caducidad de una excepción (`RN-AUTH-68`) | `90` |
| `AUTH_MFA_FACTOR_PURGE_DAYS` | Retención de factores borrados lógicamente (`datos.md §C.11`) | `30` |
| `AUTH_MFA_CHALLENGE_RETENTION_HOURS` | Retención de desafíos consumidos | `24` |
| `AUTH_RATE_LIMIT_MFA_*` | Los seis límites de `§C.6` | Ver `§C.6` |

**Ninguna es un secreto.** `AUTH_MFA_TOTP_WINDOW` tiene guarda de arranque: un valor por encima de `2` amplía la ventana de validez de un código a más de dos minutos y medio y convierte un código capturado en utilizable; la aplicación aborta.

**No hay ninguna variable de proveedor de SMS**, y es deliberado: no existe proveedor (`funcional.md §C.7`). Añadir `SMS_DRIVER=null` «para dejarlo preparado» es inventar una decisión que no se ha tomado.

### C.2.2 La variable que hay que custodiar de otra forma a partir de este paso: `APP_KEY`

No es nueva. **Lo que cambia es lo que cifra.**

Hasta 1.2b, `APP_KEY` cifraba el *payload* de sesión y los cursores de paginación: cosas regenerables cuyo pérdida obliga a volver a entrar y nada más. **A partir de 1.3 cifra credenciales de usuario** (`user_mfa_factors.secret_encrypted`, `datos.md §C.2`).

Consecuencia, dicha entera:

> Perder `APP_KEY`, o restaurar una copia de la base de datos con una clave distinta, **inutiliza todos los factores TOTP del sistema a la vez**. Nadie con MFA puede entrar. Hay que restablecer el MFA de todo el mundo a mano — y quien tiene que hacerlo es un administrador cuyo rol también exige MFA, así que **tampoco puede entrar**. La salida es intervención directa sobre la base de datos.

`ADR-037 §7.2` punto 4 ya obliga a custodiar `APP_KEY` **separada** de la copia de la base de datos, y `0.10d` lo recoge. **Este paso convierte esa obligación en un requisito de recuperación con consecuencia catastrófica y no en una buena práctica**, y hay que reflejarlo en `SYSADMIN.md` y en `RUNBOOK.md` con esas palabras. `OPEN-AUTH-26`.

### C.2.3 De sesión: sin cambios, con una consecuencia nueva

Las ocho guardas de §2.2 siguen en vigor sin modificación. Una de ellas gana peso:

- **`SESSION_DRIVER=database`** (`RN-AUTH-49`, guarda de arranque desde 1.2b): ahora además es lo que sostiene el desafío. `mfa_challenges` se busca por `session_id` (`RN-AUTH-53`), y con un *driver* de cookie no habría sesión servidor contra la que ligarlo.

---

## C.3 Servicios externos y degradación

| Servicio | Uso nuevo en 1.3 | Si no responde |
|----------|------------------|----------------|
| **PostgreSQL** | Factores, desafíos, obligaciones | Sin degradación posible ni deseable, igual que en §3 |
| **Redis** | Colas, caché y **los tres límites de tasa nuevos** | **El límite no degrada a «sin límite»**: si el almacén del limitador no responde, el endpoint responde `503`. Regla de §3, y aquí es más importante: un limitador abierto sobre `POST /auth/mfa-verifications` es una ventana de fuerza bruta contra seis dígitos |
| **Correo transaccional** | **Método «código por correo»** y los tres avisos de `funcional.md §C.4.13` | Hereda íntegra la degradación de §3 y `OPEN-AUTH-07`: depende de `0.10c`, **sin decidir**. Consecuencia propia y grave: **un usuario cuyo único factor es el correo no puede entrar mientras el correo no salga.** Es el argumento operativo, además del de seguridad de `funcional.md §C.8`, para que `totp` no sea desactivable (`RN-AUTH-69`) |
| **SMS** | **Ninguno.** No hay proveedor | No aplica. La guarda de `mfa_allowed_methods` impide llegar a este caso (`RN-AUTH-69`) |
| **S3 / MinIO** | **No se usa.** Este módulo sigue sin escribir ficheros | — |

**Sin dependencia de tiempo externo, y hay que decirlo porque TOTP invita a pensar lo contrario.** La verificación usa el reloj del servidor. **No se consulta ningún servicio NTP desde la aplicación**: el reloj lo mantiene el sistema operativo del contenedor, y eso es responsabilidad de `SYSADMIN.md`, no de este módulo. Lo que sí es responsabilidad de este documento es dejar escrito el síntoma: **si el reloj del servidor se desvía más de 30 segundos, empiezan a fallar códigos correctos de forma intermitente** (`§C.9`).

---

## C.4 Colas y trabajos (`INV-012`)

| Cola | Trabajo | Disparo | Reintentos |
|------|---------|---------|------------|
| `auth-mail` | `SendMfaChallengeCodeEmail` | Apertura o reenvío de un desafío por correo | **3**, retroceso **corto** (10 s → 60 s) |
| `auth-mail` | `SendMfaEnrollmentCodeEmail` | Alta de un factor por correo | 3, mismo retroceso |
| `auth-mail` | `SendMfaEnabledEmail` | Confirmación de un factor | 3 |
| `auth-mail` | `SendMfaDisabledEmail` | Desactivación por el usuario o restablecimiento por el administrador | 5 |
| `auth-mail` | `SendRecoveryCodeUsedEmail` | Login superado con código de respaldo | 5 |
| `auth-maintenance` | `MaterializeMfaObligations` | Tras `PATCH /roles` con `mfa_required = true`, **y** programado cada hora | 3 |
| `auth-maintenance` | `ReopenExpiredMfaExemptions` | Programado, **cada hora** | — |
| `auth-maintenance` | `PurgeMfaChallenges` | Programado, diario | — |
| `auth-maintenance` | `PurgeMfaEnrollments` | Programado, diario | — |
| `auth-maintenance` | `PurgeMfaFactors` | Programado, diario | — |

**El retroceso de los dos primeros es corto a propósito, y es la única desviación de la política de §4.** Los correos de §4 (recuperación, bloqueo) tienen tokens de 60 minutos y 24 horas: un reintento a los 30 minutos sigue sirviendo. **Un código de segundo factor vive 10 minutos y el desafío 5.** Un reintento con el retroceso exponencial de §4 entregaría el código cuando ya no vale, y el usuario recibiría un correo inútil que además le confundiría. Tres intentos en un minuto y medio o nada.

**Ninguno de los cinco trabajos de correo lleva el código en claro fuera de lo imprescindible**, y **los dos que sí lo llevan implementan `ShouldBeEncrypted`** (issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73)), igual que `SendPasswordResetEmail` y `SendAccountLockedEmail`. Es el mismo patrón de «token en el *payload*» y la misma solución; `queue:prune-failed --hours=24` sigue siendo la segunda capa.

**Los tres avisos (`Enabled`, `Disabled`, `RecoveryCodeUsed`) no llevan token, código ni enlace accionable** (`RN-AUTH-50`). Son la única defensa del titular ante un cambio que no hizo él.

Reglas heredadas que siguen aplicando sin excepción: contexto de tenant por el mecanismo de framework (`ADR-033 §8`), purgas **por tenant** con `RunsPerTenant`, *scheduler* en su propio contenedor (`ADR-037`).

### C.4.1 Qué hace cada tarea de mantenimiento

| Trabajo | Qué hace | Base |
|---------|----------|------|
| `MaterializeMfaObligations` | Crea la fila de `user_mfa_obligations` de los usuarios que han pasado a estar obligados y **no** tienen una abierta. **Idempotente**, garantizado por el índice único parcial de `datos.md §C.5` y no por comprobación de aplicación | `RN-AUTH-65`. Se despacha tras el `PATCH` **y** se programa cada hora, porque el disparo directo puede fallar y el plazo de gracia no puede depender de que un trabajo no se pierda |
| `ReopenExpiredMfaExemptions` | Reabre la obligación de los usuarios cuya excepción ha caducado, con **plazo completo** | `funcional.md §C.4.11` punto 4. Cada hora y no a diario: una excepción que caduca a las 9:00 no debería dejar a alguien sin exigencia hasta la madrugada |
| `PurgeMfaChallenges` | Borra desafíos con más de `AUTH_MFA_CHALLENGE_RETENTION_HOURS` | Artefacto transitorio. **Un día y no cinco minutos**: es lo que permite responder «¿por qué no pudo entrar ayer?» |
| `PurgeMfaEnrollments` | Borra **físicamente** las filas de `user_mfa_factors` sin confirmar y vencidas | **Contienen un secreto cifrado que ya no sirve.** Minimización: material de credencial sin finalidad |
| `PurgeMfaFactors` | Borra **físicamente** las filas borradas lógicamente hace más de `AUTH_MFA_FACTOR_PURGE_DAYS` | `datos.md §C.11`. **Es la única tabla del producto donde el borrado lógico de `INV-004` conserva una credencial viva**, y por eso tiene plazo corto y propio |

**Ninguna toca `audit_logs`** (retención de `REQ-PRIV-006`) **ni `mfa_resets`** (traza permanente, *append-only*).

**Ninguna necesita el rol propietario**, a diferencia de `PurgeLoginAttempts` (§4): las cinco tablas ordinarias no son *append-only*. **`mfa_resets` sí lo es**, y por eso **no tiene purga**: su única salida es el flujo de supresión de la persona, que corre con el rol propietario (`datos.md §C.11`).

---

## C.5 Correos que emite el módulo

**Ocho tras 1.3** (tres de 1.2, uno de 1.2b, cuatro nuevos… y son cinco los trabajos porque el de alta y el de desafío comparten plantilla en dos variantes). Todos en los cuatro idiomas de `ADR-021` (`INV-009`, `CA-AUTH-144`) y en el idioma preferido del destinatario.

| Correo | Contenido | Enlace |
|--------|-----------|--------|
| Código de segundo factor (login) | El código y **cuántos minutos vale**. Aviso de «si no has intentado entrar, cambia tu contraseña» | **Ninguno** |
| Código de alta de factor | Ídem, con el contexto de que se está activando | **Ninguno** |
| Segundo factor activado | Qué método, cuándo, y qué hacer si no fue el titular | **Ninguno** |
| Segundo factor desactivado o restablecido | Ídem, **y quién lo hizo** si fue un administrador. En el restablecimiento **no se incluye el motivo**: es texto escrito para el registro interno, no para el afectado | **Ninguno** |
| Código de respaldo usado | Cuándo y desde qué IP aproximada | **Ninguno** |

Reglas comunes, ampliando §5:

- **Ningún correo de este paso lleva enlace accionable** (`RN-AUTH-50`). Ni uno. El aviso de «tu MFA se ha desactivado» con un botón «no fui yo» sería un endpoint anónimo que revierte una credencial: exactamente lo que no se puede tener.
- **El código nunca va en el asunto.** Un asunto es lo que se ve en la notificación de la pantalla de bloqueo del teléfono, en la vista previa del cliente de correo y en el registro del servidor intermedio.
- **Ninguno revela si la cuenta existe a quien no es su titular**: los cinco solo se envían cuando hay cuenta y factor detrás.
- Remitente y dominio dependen de `0.10c` (**pendiente**). En desarrollo, *mailer* `log`; los tests comprueban que el trabajo **se encola**, no que el correo llega (convención de 1.1).

---

## C.6 Límites de tasa

Es, otra vez, la defensa activa de la superficie que este paso abre. Amplía §6.

| Endpoint | Límite | Clave |
|----------|--------|-------|
| `POST /auth/mfa-verifications` | **10 / min** | IP |
| `POST /auth/mfa-verifications` | **5 / min** | `(tenant_id, session_id)` |
| `POST /auth/mfa-challenges` | **3 / 10 min** | `(tenant_id, session_id)` |
| `POST /auth/mfa-enrollments` | 10 / hora | `(tenant_id, user_id)` |
| `POST /auth/mfa-recovery-codes` | 5 / hora | `(tenant_id, user_id)` |
| `POST /mfa-resets` | 20 / hora | `(tenant_id, user_id del administrador)` |

- **Toda clave incluye el `tenant_id`** (`ADR-033 §9`). Sin cambios respecto de §6.
- **El límite de verificación se lleva por sesión y no por correo**, a diferencia del de login. El correo no está disponible en el paso 2 sin ir a buscarlo, y la sesión es la clave natural: **es lo que identifica el desafío** (`RN-AUTH-53`).
- **El límite de tasa no sustituye al tope de intentos del desafío ni al bloqueo de cuenta.** Son tres cosas con tres propósitos: el límite defiende el servidor y se olvida; el tope de `AUTH_MFA_MAX_ATTEMPTS` mata **ese** desafío; el bloqueo de `RN-AUTH-14` defiende **la cuenta** y persiste (`funcional.md §C.4.4.2`). Quitar cualquiera de los tres deja un hueco distinto.
- **`429` siempre con `Retry-After`** (`ADR-038 §6.5`).
- El punto ciego de §6 —un centro entero detrás de una IP de salida— **empeora en este paso**: si 25 alumnos entran a las 9:00 y todos tienen MFA, son 25 logins **más** 25 verificaciones por la misma IP. El límite por IP de la verificación es 10/min, y se alcanzaría. **Hay que medirlo con `REQ-SEED` (1.15b) antes de fijar el número definitivo**, y la salida probable es que el límite por sesión sea el que defienda y el de IP sea holgado, porque la sesión ya es un identificador que un atacante no puede multiplicar sin coste.

---

## C.7 Caché

**Ninguna nueva, y esa es la decisión.**

`MfaPolicy::resolve()` se ejecuta en cada petición autenticada y consulta `role_user ⋈ roles`, `user_mfa_factors`, `user_mfa_exemptions` y `user_mfa_obligations`. La tentación de cachearlo con un TTL corto es evidente y **se rechaza** (`RN-AUTH-62`): un rol puede cambiar, una excepción revocarse y un factor darse de alta en la petición anterior, y una caché de cinco minutos convertiría las tres cosas en «efectivo dentro de un rato». **En un control de acceso eso es un fallo, no una optimización.**

Lo que sí se hace es **cachear por petición** (memoización en el contenedor de servicios), para que las tres o cuatro veces que se consulte dentro de la misma petición no sean tres o cuatro consultas.

Si la medición con `REQ-SEED` demostrara que el coste importa, la salida correcta **no** es una caché con TTL: es materializar la obligación en una columna del usuario invalidada por evento, que es un cambio de modelo con su propio ADR. No se anticipa (`ADR-034 OPEN-13`).

`TenantSettingsCache` (1.1) sí sirve `mfa_allowed_methods` y `mfa_grace_period_days` como el resto de la configuración, con su invalidación ya existente.

---

## C.8 Métricas y alertas

Amplía §8. Las que este paso obliga a mirar:

| Métrica | Por qué |
|---------|---------|
| **Verificaciones de segundo factor fallidas por minuto** | Es la señal de un ataque contra códigos. Un pico sin pico equivalente de logins fallidos significa que alguien **ya tiene contraseñas** |
| **Ratio de desafíos abiertos / desafíos consumidos con éxito** | Si cae, algo está roto: el correo no sale, el reloj se ha desviado, o la SPA perdió el paso 2 |
| **Bloqueos de cuenta con `outcome` predominante `segundo_factor_invalido`** | Distingue «nos atacan» de «hay un problema de reloj o de entrega». Es lo que justifica haber separado los dos `outcome` en `datos.md §C.7.1` |
| **Usuarios `past_deadline`** por tenant | Personas que ya están contra el muro. Si crece, alguien activó una obligación sin avisar |
| **Restablecimientos de MFA por administrador y por semana** | Un ritmo alto es, o un problema de usabilidad, o ingeniería social. Las dos cosas hay que verlas |
| **Latencia diferencial entre el `401` y el `202` de `POST /auth/session`** | `funcional.md §C.6.3` lo dio por despreciable frente a bcrypt de coste 12. **Es una suposición y hay que medirla**, no darla por buena |

Alerta que este paso añade a la guardia: **cero desafíos consumidos con éxito durante más de N minutos en horario lectivo** mientras siguen abriéndose. Es el síntoma de «nadie puede entrar» y no lo detecta ninguna alerta de las de §8, que miran errores y no ausencias de éxito.

---

## C.9 Problemas conocidos y diagnóstico

Amplía §9. Los característicos de este paso, con su síntoma real:

| Síntoma | Causa probable | Comprobación |
|---------|----------------|--------------|
| **Códigos TOTP correctos que fallan de forma intermitente, para todo el mundo** | **El reloj del servidor se ha desviado.** Es la causa número uno y no se parece a un fallo de código: unos códigos valen y otros no, según el momento del paso de 30 s | `date -u` en el contenedor de la API contra una fuente fiable. Desviación > 30 s ⇒ es esto |
| Códigos que fallan **para un solo usuario** | El reloj **de su dispositivo**. La pantalla debe decírselo tras el segundo fallo (`funcional.md §C.4.4.2`) | Su factor tiene `last_used_at` antiguo o `NULL` |
| **El bloqueo por intentos nunca dispara** contra fallos de segundo factor | `recordSuccess()` sigue llamándose al verificar la contraseña. **Es la regresión de `RN-AUTH-63`** | `login_attempts` con filas `exito` **sin** que exista la fila de `user_sessions` correspondiente. `CA-AUTH-124` es el test que lo caza |
| El usuario entra pero «se le olvida» que pasó el MFA y se lo vuelve a pedir | El desafío se consumió y la sesión no se regeneró en el orden correcto, o `user_sessions` quedó con el `session_id` anterior | La trampa que `§B.9` anotó. `CA-AUTH-118` lo verifica |
| **Todos los factores TOTP dejan de verificar a la vez** | **`APP_KEY` cambió o se restauró una copia sin ella** (`§C.2.2`). No es un fallo de código y no tiene arreglo desde la aplicación | Descifrar `secret_encrypted` de cualquier fila falla. **Es el escenario de `OPEN-AUTH-26`** |
| Un usuario obligado no ve ningún aviso y aparece contra el muro de golpe | No entró durante el plazo de gracia. **Es el comportamiento decidido**, no un fallo (`OPEN-AUTH-22`) | Su fila de `user_mfa_obligations` tiene `obligated_since` de hace más de 7 días y él no tiene `login_attempts` en ese rango |
| **`DELETE /auth/mfa-factors` responde `422` diciendo que falta la contraseña, y sí se envió** | Un intermediario descartó el cuerpo del `DELETE` (`api.md §C.8.3`) | Reproducir con `curl` directo contra la API, saltando Traefik. Si ahí funciona, es el proxy |
| Un administrador no puede entrar y tampoco restablecerse | **Es la regla, no un fallo** (`RN-AUTH-67`). Necesita otro administrador | Procedimiento de `§C.11` |

**Y la trampa de §9 que sigue aplicando**, repetida porque este paso la roza: la marca de última actividad se lee del *payload*, **nunca** de `sessions.last_activity`.

### C.9.1 Procedimiento de restablecimiento de MFA: verificación de identidad

`funcional.md §C.4.10` punto 3 exige *«verificación previa de identidad»* antes de `POST /mfa-resets`, y deja explícito que es **un procedimiento humano, no una casilla en un formulario**. Esto es ese procedimiento — el mismo texto va también en el manual de administrador (`docs/manual-usuario/admin.md`).

**Antes de restablecer el MFA de alguien, quien tiene `mfa.eliminar` verifica su identidad por uno de estos dos caminos:**

1. **Presencial.** La persona se presenta con un documento de identidad válido y quien la atiende la reconoce o coteja el documento contra el registro del centro (`usuario.leer`).
2. **Remota.** Cuando no es posible en persona (ausencia, docencia a distancia), la verificación se hace por un canal **distinto** del que se está intentando recuperar: por ejemplo, una videollamada mostrando el documento de identidad, o una llamada al número de teléfono **ya registrado** en el centro (nunca a un número que la persona proporciona en ese momento, porque eso no verifica nada).

**Lo que no cuenta como verificación**, y hay que decirlo porque es la tentación fácil bajo presión: un correo pidiendo el restablecimiento (el correo puede estar comprometido, que es justamente el escenario contra el que protege el MFA), una llamada entrante sin cotejar el número contra el registro, o «reconocer la voz».

**Qué queda como constancia**: el `reason` de `POST /mfa-resets` (`RN-AUTH-66`, mínimo 10 caracteres) debe describir **qué verificación se hizo**, no solo el motivo de la pérdida — por ejemplo *«presencial, DNI cotejado, perdió el móvil»*, no solo *«perdió el móvil»*. Es el único registro de que la verificación ocurrió, y el motivo se conserva en auditoría (`datos.md §C.11`).

**Si nadie en el centro puede verificar** (un centro con un solo administrador que es quien necesita el restablecimiento): no hay salida por la aplicación (`RN-AUTH-67`, `§C.11.1`). Es intervención directa sobre la base de datos, y es la razón por la que `§C.11.1` exige comprobar que cada tenant tiene más de un `administrador_centro` antes del despliegue.

---

## C.10 Impacto en copias de seguridad y restauración

Amplía §10 y `§B.9`, y **cambia una conclusión**.

- **`APP_KEY` deja de ser opcional en el plan de recuperación.** Una copia de la base de datos **sin** la clave, o con una distinta, restaura un sistema en el que **nadie con MFA puede entrar** (`§C.2.2`). `ADR-037 §7.2` punto 4 y `0.10d` tienen que recogerlo con esas palabras, y el procedimiento de restauración de 1.26 tiene que verificar la clave **antes** de dar la restauración por buena, no después.
- **Las seis tablas entran en la copia general sin nada especial**, salvo `mfa_challenges`, que **puede excluirse**: son desafíos de cinco minutos y ninguno sigue vivo tras una restauración.
- **`user_mfa_factors` es la tabla más crítica del módulo para restaurar.** Perderla es perder los segundos factores de todo el centro, con el mismo efecto que perder `APP_KEY`. **Nunca se excluye.**
- **Una restauración a un punto anterior resucita factores desactivados.** §10 avisaba de que resucita contraseñas antiguas; el equivalente aquí es peor: **un factor que un usuario desactivó porque perdió el dispositivo vuelve a estar activo**, y esa persona no puede entrar. Y a diferencia de las sesiones de `§B.9` —donde `sessions` se vacía y el acceso real no vuelve—, **aquí sí vuelve el efecto**: el factor resucitado es plenamente funcional. **El procedimiento de restauración de 1.26 tiene que incluir un repaso de los restablecimientos de MFA posteriores al punto restaurado** (`mfa_resets` da exactamente esa lista, con motivo y fecha) para rehacerlos.
- **Una restauración también resucita obligaciones ya cumplidas y excepciones ya revocadas.** Es menos grave —se corrige solo en la siguiente evaluación de `MfaPolicy` si el factor existe— pero una excepción revocada que vuelve significa que alguien deja de estar obligado sin que nadie lo decida. Repasar `user_mfa_exemptions` con `revoked_at` posterior al punto restaurado.
- **Nada que copiar fuera de la base de datos**: este módulo sigue sin escribir ficheros. **Salvo `APP_KEY`**, que no es un fichero de este módulo pero sin la cual la copia de este módulo no vale.

---

## C.11 Despliegue

Amplía §11 y `§B.6`. **Este es el paso con el despliegue más delicado del módulo hasta ahora**, y no por las migraciones.

### C.11.1 El día del despliegue, dos roles de todos los tenants pasan a estar obligados

`ProvisionTenantDefaults` sembró `mfa_required = true` en `administrador_centro` y `soporte_plataforma` desde 1.1, y **hasta hoy nadie lo comprobaba** (`permisos.md §5.4`). En cuanto 1.3 esté en producción:

1. Todos los administradores de centro de todos los tenants quedan obligados, con **siete días de gracia** contados desde la primera evaluación.
2. Ninguno lo ha pedido: el valor lo puso la siembra, no una decisión del centro.

**Qué hay que hacer, y no es opcional:**

- **Avisar antes.** No es un cambio que se despliega un viernes por la tarde.
- **Comprobar que cada tenant tiene más de un administrador de centro** antes de que venza el plazo. Un centro con un solo administrador que pierda el dispositivo y los códigos **no tiene salida por la aplicación** (`RN-AUTH-67`), y la única vía es intervención directa sobre la base de datos.
- **Verificar que el `PATCH` de roles funciona en producción el mismo día**, porque es el único interruptor. Es el argumento operativo entero de `funcional.md §C.2.1`.

### C.11.2 Orden y reversión

- **Ocho migraciones, *expand* puro** (`datos.md §C.10`). La migración precede al despliegue.
- **La única con riesgo de bloqueo real es la del `CHECK` de `login_attempts`**, que es la tabla más grande del módulo: si tiene volumen en el momento del despliegue, `NOT VALID` más `VALIDATE CONSTRAINT` posterior.
- **La versión anterior sigue funcionando contra el esquema nuevo**: no conoce las tablas, no escribe los `outcome` nuevos y las columnas de `tenant_settings` tienen valor por defecto. **Un despliegue escalonado con réplicas de las dos versiones funciona**: la antigua hace login de un paso, la nueva de dos, y ningún usuario queda en un estado imposible — lo peor que le pasa a alguien es que un login le pida el segundo factor y el siguiente no.
- **Los *workers* deben procesar trabajos encolados por la versión anterior** (`CLAUDE.md §9`): los cinco trabajos nuevos no existen en la anterior, así que el problema no se da en esa dirección. En la contraria —revertir la aplicación con trabajos nuevos en cola— los cinco fallarían por clase inexistente. **`queue:prune-failed --hours=24` limita el daño**, y el procedimiento de reversión tiene que drenar `auth-mail` antes de revertir.
- **Reversión**: limpia para siete de las ocho migraciones. La del `CHECK` de `login_attempts` **falla si ya se escribió alguna fila con los valores nuevos**, exactamente como la de `ADR-039 §4.6` y por el mismo motivo (*append-only*, sin `DELETE`). En la práctica es de un solo sentido; **revertir la aplicación no exige revertir esa migración**, y es lo que se hará.
- **Reversión de la aplicación con factores ya dados de alta**: la versión anterior ignora `user_mfa_factors` y hace login de un paso. **Los usuarios que activaron MFA dejan de tener segundo factor sin que nadie se lo diga.** No se pierde nada —las filas siguen ahí y vuelven a valer al desplegar de nuevo—, pero es una **degradación silenciosa de seguridad** y tiene que estar escrita en el procedimiento de reversión de `RUNBOOK.md`, no descubrirse.

### C.11.3 Lo que hay que verificar en el entorno real y no se puede verificar en WSL2

- **La entrega de los correos de código**, que depende de `0.10c` (`OPEN-AUTH-07`, `OPEN-09`).
- **Que el cuerpo del `DELETE` llega a través de Traefik** (`api.md §C.8.3`).
- **Que el reloj del contenedor de la API está sincronizado** y se mantiene así (`§C.9`). Es lo que más se parece a una dependencia externa de este paso, y no tiene test.

---

# Parte D · Paso 1.3b · Operación (`REQ-AUTH-003`)

> **Estructura**: §1-§11 son 1.2 (cerrado). `§B.1`-`§B.9` son 1.2b (cerrado). `§C.1`-`§C.11` son 1.3 (cerrado y mezclado, commit `cd13e8a`). Esta **Parte D** es el paso **1.3b**, **implementada y cerrada** el 2026-08-31 (PR [#123](https://github.com/pirexia/plataforma-educativa/pull/123), commit `dd68f48`).

---

## D.1 Comportamiento con el módulo activo o inactivo

Sin cambios: **`REQ-AUTH` no es desactivable** (`RN-AUTH-35`) y ninguna ruta de este paso lleva `module-enabled` (`CA-AUTH-168`).

Lo que sí cambia es **qué significa «MFA desactivado» en un tenant después de este paso**. Con el valor de fábrica `mfa_allowed_methods = ["totp"]`:

- **Nada de 1.3b se activa.** No hay factores de correo, no se encola ningún código, las excepciones no existen porque nadie las concede, y la tarea horaria nueva no encuentra ninguna fila que procesar.
- **El coste para quien no lo usa es cero**: ni una consulta más por login que las que 1.3 ya hacía.

**El correo como segundo factor se activa a propósito y con consecuencia**, y el manual de administración tiene que decirlo con estas palabras: al añadir `email` a los métodos admitidos, el centro acepta que un usuario pueda protegerse **solo** con un factor que no resiste el compromiso de su buzón, al que además va la recuperación de contraseña (`funcional.md §C.8`).

---

## D.2 Variables de entorno

### D.2.1 La única nueva

| Variable | Uso | Valor en desarrollo |
|----------|-----|---------------------|
| `AUTH_MFA_EXEMPTION_REOPEN_WINDOW_HOURS` | Ventana hacia atrás que recorre `ReopenExpiredMfaExemptions` buscando excepciones recién caducadas (`funcional.md §D.4.9`) | `48` |

**No es un secreto.** Su valor no es crítico: la tarea solo **adelanta** el trabajo que `MfaPolicy::resolve()` haría de todas formas en la siguiente petición del titular. Bajarlo a `1` haría que un *scheduler* caído más de una hora dejara obligaciones sin materializar hasta que la persona entrara; subirlo mucho hace que la tarea recorra histórico sin necesidad. 48 horas cubre un fin de semana.

### D.2.2 Las que 1.3 dejó declaradas y este paso empieza a usar de verdad

**Ninguna es nueva.** Las cinco existen en `config/auth-local.php` desde 1.3 y hasta ahora no hacían nada, porque no había método de entrega ni excepciones:

| Variable | Qué pasa a gobernar en 1.3b |
|----------|------------------------------|
| `AUTH_MFA_CODE_TTL_MINUTES` (10) | Vida del código entregado, **tanto en el alta como en el desafío**. Es la caducidad que la pantalla debe contar |
| `AUTH_MFA_MAX_DELIVERIES` (3) | **Estaba configurada y sin leer** (`funcional.md §D.2.3`). Pasa a ser el tope de entregas por desafío, con `429` al superarlo (`RN-AUTH-79`). **Que se lea de la configuración y no esté escrito a mano lo comprueba `CA-AUTH-175`** |
| `AUTH_MFA_FACTOR_PURGE_DAYS` (30) y `AUTH_MFA_CHALLENGE_RETENTION_HOURS` (24) | **Estaban configuradas y sin leer**, porque las purgas que las usan no existían (`§D.4.2`, issue [#109](https://github.com/pirexia/plataforma-educativa/issues/109)). Pasan a gobernar de verdad la retención de `datos.md §D.7` (`RN-AUTH-85`, `CA-AUTH-171`, `CA-AUTH-172`) |
| `AUTH_MFA_MAX_EXEMPTION_DAYS` (90) | Tope de la caducidad de una excepción, validado en el `FormRequest` (`RN-AUTH-81`). El motor solo garantiza que la caducidad **existe** (`datos.md §D.3`) |
| `AUTH_MFA_GRACE_DEFAULT_DAYS` (7) | Sin cambios de valor, con un disparador nuevo: la obligación que se reabre al caducar una excepción usa **el plazo completo** (`RN-AUTH-82`) |
| `AUTH_MFA_ENROLLMENT_TTL_MINUTES` (10) | Sin cambios. Aplica igual al alta por correo |

**Guarda de arranque nueva: ninguna.** La de `AUTH_MFA_TOTP_WINDOW` (`§C.2.1`) sigue siendo la única del bloque MFA. Se ha considerado añadir una para `AUTH_MFA_MAX_DELIVERIES` y **se descarta**: un valor alto no abre una ventana de fuerza bruta —cada entrega genera un código nuevo y los intentos siguen topados por `AUTH_MFA_MAX_ATTEMPTS`—, solo permite gastar más correos, que es lo que el límite de tasa por sesión ya acota.

### D.2.3 `APP_KEY`: sin cambios respecto de `§C.2.2`

Este paso **no añade ninguna columna cifrada**: el código entregado se guarda como hash SHA-256, no cifrado (`RN-AUTH-56`). La consecuencia catastrófica de perder `APP_KEY` sigue siendo exactamente la de `§C.2.2` —todos los factores TOTP dejan de verificar a la vez— **y este paso la matiza en un solo punto, que conviene decir**: un usuario que además tenga factor de correo **sí podría entrar** en ese escenario, porque su verificación no depende de `APP_KEY`. No es una mitigación en la que apoyarse —depende de que ese usuario tenga correo activado y de que el correo transaccional funcione—, pero sí es un dato para el procedimiento de recuperación de `RUNBOOK.md`.

---

## D.3 Servicios externos y degradación

Amplía `§C.3`. **La fila que cambia es la del correo, y cambia de categoría.**

| Servicio | Uso nuevo en 1.3b | Si no responde |
|----------|-------------------|----------------|
| **Correo transaccional** (`0.10c`, `OPEN-09`, `OPEN-AUTH-07`) | **Entrega del segundo factor**, además de los avisos | **Deja de ser una degradación y pasa a ser una interrupción de acceso** para quien tenga el correo como único factor. `§C.3` lo anticipó; 1.3b lo hace real. Es el argumento operativo —además del de seguridad de `§C.8`— para que `totp` no sea desactivable en el tenant (`RN-AUTH-69`) y para que `email` esté **desactivado de fábrica** |
| **Redis** | Colas (`auth-mail`, `auth-maintenance`) y los límites de tasa ya existentes | Sin cambios respecto de `§C.3`: el limitador **no degrada a «sin límite»**, responde `503` |
| **PostgreSQL** | Sin uso nuevo más allá de las dos columnas de `datos.md §D.2` | Sin degradación posible ni deseable |
| **SMS** | **Ninguno.** Sigue sin proveedor | No aplica. El `CHECK` del motor impide llegar a este caso |

**Antes de que un centro real active `email`, `0.10c` tiene que estar resuelto.** No es una recomendación: sin correo transaccional en producción, activar el método entrega un segundo factor que nunca llega. Debe quedar escrito en `SYSADMIN.md` y en el manual de administración como **condición previa a activar el método**, no como nota al pie.

---

## D.4 Colas y trabajos (`INV-012`)

### D.4.1 Los que este paso construye

| Cola | Trabajo | Disparo | Reintentos |
|------|---------|---------|------------|
| `auth-mail` | **`SendMfaChallengeCodeEmail`** | Apertura o reenvío de un desafío con entrega | **3**, retroceso **corto** (10 s → 60 s) |
| `auth-mail` | **`SendMfaEnrollmentCodeEmail`** | Alta de un factor de entrega | 3, mismo retroceso |
| `auth-maintenance` | **`ReopenExpiredMfaExemptions`** | Programado, **cada hora**, por tenant | — |
| `auth-maintenance` | **`MaterializeMfaObligations`** | Programado, **cada hora**, por tenant — además del *listener* `MaterializeMfaObligationsForRole`, que ya existe | 3 |
| `auth-maintenance` | **`PurgeMfaChallenges`** | Programado, **diario**, por tenant | — |
| `auth-maintenance` | **`PurgeMfaEnrollments`** | Programado, **diario**, por tenant | — |
| `auth-maintenance` | **`PurgeMfaFactors`** | Programado, **diario**, por tenant | — |

**Las cuatro últimas son la pieza 4 del alcance** (`funcional.md §D.1.1`): estaban declaradas en `§C.4` desde 1.3 y **nunca se construyeron** (`§D.4.2`, issue [#109](https://github.com/pirexia/plataforma-educativa/issues/109)). Su comportamiento es exactamente el que `§C.4.1` describe —no se reinterpreta nada— y su verificación, `CA-AUTH-170`-`CA-AUTH-174`.

- **Los dos de correo llevan el código en el *payload* y por tanto implementan `ShouldBeEncrypted`** (issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73)), igual que `SendPasswordResetEmail` y `SendAccountLockedEmail`. `queue:prune-failed --hours=24` sigue siendo la segunda capa.
- **El retroceso corto es la única desviación de la política de §4**, y `§C.4` ya la justificó: un código vive 10 minutos y el desafío 5; un reintento con retroceso exponencial entregaría el código cuando ya no vale. **Tres intentos en minuto y medio o nada.**
- **`ReopenExpiredMfaExemptions` corre cada hora y no a diario** (`§C.4.1`): una excepción que caduca a las 9:00 no debería dejar a alguien sin exigencia hasta la madrugada. Es **idempotente** por construcción —`MfaPolicy::materialize()` comprueba excepción viva, factor utilizable, roles y obligación abierta— y el índice único parcial de `user_mfa_obligations` lo garantiza bajo concurrencia. **No marca filas como procesadas y no añade ninguna columna** (`datos.md §D.4`).
- **Se despacha por tenant con `RunsPerTenant`**, desde un **comando horario propio** — **no** desde `auth:purge-maintenance`, que está programado `->daily()`. Es el error fácil de este paso: colgar una tarea horaria de un comando diario la convierte en diaria sin que nada falle.

### D.4.1.1 El comando horario, y por qué hay dos y no uno

Este paso deja **dos comandos de mantenimiento de MFA**, no uno:

| Comando | Cadencia | Qué despacha |
|---------|----------|--------------|
| `auth:purge-maintenance` (**ya existe**, se amplía) | `->daily()` | Las cinco purgas de 1.2/1.2b **más las tres de MFA**: `PurgeMfaChallenges`, `PurgeMfaEnrollments`, `PurgeMfaFactors` |
| `auth:mfa-obligations` (**nuevo**) | `->hourly()` | `MaterializeMfaObligations` y `ReopenExpiredMfaExemptions` |

**Por qué no un solo comando con dos cadencias**: un comando programado dos veces con dos cadencias distintas no existe; y meter las tareas horarias en el diario retrasa hasta 24 horas el arranque de un plazo de gracia y la reapertura de una obligación caducada, que es exactamente lo que `§C.4.1` argumenta que no puede pasar (*«una excepción que caduca a las 9:00 no debería dejar a alguien sin exigencia hasta la madrugada»*).

**Por qué las tres purgas sí van en el comando que ya existe**: son purgas diarias por tenant, idénticas en forma a las cinco que ese comando ya despacha. Crear un comando aparte para ellas sería duplicar el recorrido de tenants sin ganar nada.

**Las dos cadencias se registran en `routes/console.php`**, junto a las tres entradas que ya hay (`CA-AUTH-174`).

### D.4.2 Los cuatro que 1.3 declaró y no existían

`funcional.md §D.2.2` lo documenta con la comprobación hecha: **`PurgeMfaChallenges`, `PurgeMfaEnrollments`, `PurgeMfaFactors` y el `MaterializeMfaObligations` horario están en la tabla de `§C.4` y no existen en el código.** `PurgeAuthMaintenanceCommand` despacha cinco purgas y ninguna es de MFA; `routes/console.php` no programa nada de MFA.

**Consecuencia operativa, dicha entera:**

> Hoy, en cualquier despliegue de 1.3, **los secretos TOTP de los factores borrados lógicamente no se retiran nunca**, y las altas sin confirmar tampoco. `AUTH_MFA_FACTOR_PURGE_DAYS` (30) está configurado y no lo lee nadie. `§C.4.1` dice que `user_mfa_factors` es *«la única tabla del producto donde el borrado lógico de `INV-004` conserva una credencial viva, y por eso tiene plazo corto y propio»*: el plazo está escrito y no se aplica.

**Qué se ha hecho con ello**: **issue [#109](https://github.com/pirexia/plataforma-educativa/issues/109) abierto** (severidad Media, `CLAUDE.md §5`) y **decisión del usuario del 2026-08-27 (`OPEN-AUTH-29`): se corrige en esta misma rama**, no en un `fix/` aparte. Son la **pieza 4** del alcance (`funcional.md §D.1.1`), están en la tabla de `§D.4.1` y se cierran con el mismo PR, enlazando el commit.

El argumento con el que se decidió: 1.3b toca de todos modos ese comando y ese *scheduler* para la tarea horaria de excepciones, y son cuatro clases calcadas de las cinco purgas que ya existen. Hacerlo aparte significaba tocar los mismos tres ficheros dos veces.

**Lo que la implementación no puede confundir**: `ReopenExpiredMfaExemptions` es trabajo **nuevo** de 1.3b (pieza 2) y **no** forma parte del issue [#109](https://github.com/pirexia/plataforma-educativa/issues/109); las otras cuatro **sí**. Comparten cola y cadencia, pero no son la misma deuda ni se cierran con la misma referencia.

**Hasta que este paso se despliegue, la limitación sigue viva en producción**: `RUNBOOK.md` debe recogerla como limitación conocida —con su comprobación (`SELECT count(*) FROM user_mfa_factors WHERE deleted_at IS NOT NULL`) y su retirada manual— y **borrar esa entrada en el mismo PR que la corrige**, no dejarla como fósil.

---

## D.5 Correos que emite el módulo

**Nueve tras 1.3b**: los siete *mailables* que existen hoy (`AccountLockedMail`, `NewDeviceLoginMail`, `PasswordChangedMail`, `PasswordResetMail`, `MfaFactorActivatedMail`, `MfaFactorRemovedMail`, `RecoveryCodeUsedMail`) más los dos de este paso. Todos en los cuatro idiomas de `ADR-021` (`INV-009`, `CA-AUTH-167`) y en el idioma preferido del destinatario.

| Correo | Contenido | Enlace |
|--------|-----------|--------|
| **Código de segundo factor (login)** | El código, **cuántos minutos vale**, y el aviso «si no has intentado entrar, cambia tu contraseña» | **Ninguno** |
| **Código de alta de factor** | El código y su validez, con el contexto de que se está **activando** un segundo factor | **Ninguno** |

Reglas comunes, que `§C.5` ya fijó y este paso convierte en verificables:

- **Ningún correo de este paso lleva enlace accionable** (`RN-AUTH-50`).
- **El código nunca va en el asunto.** Un asunto se ve en la pantalla de bloqueo del teléfono, en la vista previa del cliente de correo y en el registro del servidor intermedio.
- **Los dos correos son deliberadamente distintos**, aunque el código sea idéntico: recibir «alguien está activando un segundo factor en tu cuenta» cuando no has pedido nada es una señal distinta de «alguien está intentando entrar». Comparten plantilla base y no texto.
- **Ninguno revela si la cuenta existe a quien no es su titular**: los dos solo se envían cuando hay cuenta, factor o alta detrás.
- Remitente y dominio dependen de `0.10c` (**pendiente**, `§D.3`). En desarrollo, *mailer* `log`; los tests comprueban que el trabajo **se encola**, no que el correo llega (convención de 1.1).

---

## D.6 Límites de tasa

**Ninguno nuevo.** Los seis de `§C.6` se reutilizan tal cual. Lo que cambia es que **dos de ellos empiezan a defender algo que antes no existía**:

| Endpoint | Límite existente | Qué defiende ahora |
|----------|------------------|--------------------|
| `POST /auth/mfa-challenges` | **3 / 10 min** por `(tenant_id, session_id)` | El **envío de correos**, no solo el cambio de método. Junto con `AUTH_MFA_MAX_DELIVERIES` (3 por desafío) son **dos topes distintos**: el de tasa se olvida a los diez minutos; el del desafío **muere con él** |
| `POST /auth/mfa-enrollments` | 10 / hora por `(tenant_id, user_id)` | El envío de correos de alta, ahora que abrir un alta encola un correo. Es lo que sustituye a un endpoint de reenvío que no existe (`funcional.md §D.4.1`) |

**Los tres endpoints nuevos de excepciones no llevan límite propio**, y hay que decir por qué para que no se lea como un olvido: son operaciones de administración autenticadas, con permiso, que solo tiene `administrador_centro`, y **ninguna encola correo ni entrega nada**. `POST /mfa-resets` sí lo lleva (20/hora) porque revoca todas las sesiones de alguien; conceder una excepción no tiene efecto amplificable. Si en algún momento se le añade notificación (`funcional.md §D.4.10`), **habrá que reconsiderarlo en el mismo cambio**.

**El punto ciego de `§C.6` empeora otra vez**: un centro entero detrás de una IP de salida, con MFA por correo, son N logins **más** N verificaciones **más** los reenvíos de quien no vea llegar el correo. **Sigue pendiente de medir con `REQ-SEED` (1.15b)** antes de fijar el número definitivo del límite por IP; nada de este paso cambia esa recomendación, solo la hace más urgente.

---

## D.7 Caché

**Ninguna nueva, y la decisión de `§C.7` se mantiene sin matices.** `MfaPolicy::resolve()` sigue sin cachearse entre peticiones: una excepción concedida o revocada tiene que ser efectiva en la petición siguiente, y con una caché de cinco minutos no lo sería. **La excepción temporal es precisamente el caso que hace visible el fallo**: un administrador revoca la excepción de alguien y ese alguien sigue entrando sin segundo factor durante cinco minutos.

`TenantSettingsCache` sigue sirviendo `mfa_allowed_methods` con su invalidación existente. **Un cambio en los métodos admitidos ya invalida esa caché**, y de ahí cuelga el listener `ReconcileMfaAllowedMethodsChange` que 1.3 construyó.

---

## D.8 Métricas y alertas

Amplía `§C.8`. Las que este paso obliga a mirar:

| Métrica | Por qué |
|---------|---------|
| **Correos de código encolados frente a desafíos consumidos con éxito** | Si los primeros suben y los segundos no, el correo no está llegando. Es el síntoma que `§C.8` describía como «ratio de desafíos abiertos/consumidos», ahora con la causa concreta separable |
| **Trabajos `SendMfaChallengeCodeEmail` fallidos** | Con retroceso corto y tres intentos, un fallo sostenido significa **nadie con correo puede entrar**. Es la alerta más urgente que añade este paso |
| **Entregas por desafío (`deliveries`) en su tope** | Un pico de desafíos que agotan las tres entregas es «los correos tardan demasiado» o «llegan a un buzón que no es el que la persona mira» |
| **Excepciones vivas por tenant, y su tendencia** | Es el indicador de que la obligatoriedad se está vaciando por la vía administrativa. Un centro con la mitad de su personal exento tiene MFA sobre el papel |
| **Excepciones concedidas por administrador y por semana** | Mismo criterio que los restablecimientos de `§C.8`: un ritmo alto es un problema de usabilidad o ingeniería social, y las dos cosas hay que verlas |
| **Excepciones que caducan en los próximos 7 días** | No es una alerta de guardia: es el dato que la pantalla de administración necesita para que nadie se encuentre el muro por sorpresa (`funcional.md §D.1.3`) |

Alerta que este paso añade a la guardia: **más del X % de los desafíos de un tenant abiertos en `email` sin consumirse durante N minutos en horario lectivo**. Es «el correo se ha caído» visto desde el lado del acceso, y no lo detecta ninguna alerta de `§8` ni de `§C.8`.

---

## D.9 Problemas conocidos y diagnóstico

Amplía `§C.9`. Los característicos de este paso:

| Síntoma | Causa probable | Comprobación |
|---------|----------------|--------------|
| **Un usuario concreto no recibe el código, el resto sí** | Buzón lleno, filtro de correo no deseado, o **el `users.email` no es el que esa persona mira** — recuérdese que el destino es el correo de acceso, no el de contacto (`RN-AUTH-77`) | `failed_jobs` filtrado por ese destinatario; y confirmar con la persona cuál es su correo de acceso |
| **Nadie recibe el código y los avisos de contraseña tampoco llegan** | El correo transaccional entero (`0.10c`) | Es el mismo diagnóstico de §9 para la recuperación de contraseña; no es un problema de MFA |
| **El código llega tarde y ya no vale** | El desafío vive 5 minutos y el código 10: **manda el más corto** (`api.md §D.6.4`). Con la cola saturada, el correo llega después de que el desafío haya muerto | Latencia de la cola `auth-mail`. Si es sistemática, el problema es de capacidad de cola, no de MFA |
| **Un usuario exento sigue viendo el muro** | La obligación abierta no se cerró al conceder la excepción (`RN-AUTH-82`), o la excepción se creó por consola sin pasar por el endpoint | `user_mfa_obligations` del usuario: no debe haber ninguna fila con `resolved_at IS NULL` |
| **Un usuario cuya excepción caducó ayer está contra el muro sin un día de gracia** | **Es el fallo que `RN-AUTH-82` existe para evitar**: se reutilizó la obligación antigua en vez de abrir una nueva | La fila de `user_mfa_obligations` debe ser **nueva**, con `trigger = 'exencion_vencida'` y `grace_deadline_at` a `mfa_grace_period_days` del momento de la reapertura |
| **Las excepciones caducadas no reabren la obligación hasta que la persona entra** | La tarea horaria no corre, o la ventana de `AUTH_MFA_EXEMPTION_REOPEN_WINDOW_HOURS` se quedó corta tras una parada larga del *scheduler* | Es **degradación aceptable**, no un fallo (`funcional.md §D.4.9`): `MfaPolicy::resolve()` es la red de seguridad |
| **`user_mfa_factors` crece y no baja nunca** | Las purgas de la pieza 4 **están escritas pero no registradas en el *scheduler***, o el comando horario nuevo se colgó del diario (`§D.4.1.1`) | `php artisan schedule:list` tiene que mostrar `auth:purge-maintenance` diario **y** `auth:mfa-obligations` horario. Es el fallo más silencioso de este paso: nada falla, solo no se borra nada |
| **Una tarea de mantenimiento solo procesa el primer tenant** | `RunsPerTenant` mal cableado — el fallo característico que `§D.11.3` obliga a verificar | Ejecutar el comando con dos tenants sembrados y comprobar las dos bases de filas (`CA-AUTH-174`) |

---

## D.10 Impacto en copias de seguridad y restauración

Sin cambios respecto de `§C.10`. **Ninguna columna nueva se cifra** (`§D.2.3`), así que este paso no amplía la superficie que depende de `APP_KEY`.

Un matiz para el procedimiento de recuperación de `RUNBOOK.md`: en el escenario catastrófico de `§C.2.2` —`APP_KEY` perdida, todos los TOTP inservibles—, **los usuarios con factor de correo sí pueden entrar**, porque su verificación no depende de la clave. No convierte el escenario en recuperable, pero puede ser la diferencia entre «nadie entra» y «un administrador con correo activado entra y restablece a los demás». **No es una razón para activar el correo**; es un dato que el procedimiento debe recoger.

---

## D.11 Despliegue

### D.11.1 El día del despliegue no pasa nada, y eso es lo importante

A diferencia de 1.3 —donde el despliegue **activaba la obligación de dos roles en todos los tenants existentes** (`§C.11.1`)—, 1.3b es inerte al desplegarse:

- `mfa_allowed_methods` sigue en `["totp"]` en todos los tenants: **nadie puede dar de alta un factor de correo hasta que su centro lo active a propósito**.
- No hay ninguna excepción concedida, así que la tarea horaria nueva no encuentra nada.
- Las dos columnas nuevas son *nullable* y ninguna fila existente las usa.

**No hace falta aviso previo a los centros**, a diferencia de 1.3.

### D.11.2 Orden y reversión

1. Migración aditiva (`datos.md §D.6`): dos columnas y dos `CHECK` `NOT VALID` + `VALIDATE`.
2. `platform:sync-registry` para materializar los tres permisos nuevos, y la concesión a `administrador_centro` en el aprovisionamiento (`permisos.md §D.6`).
3. Despliegue de la aplicación y de los *workers* — **los *workers* antes o a la vez que la API**, para que no haya trabajos `SendMfaChallengeCodeEmail` encolados que ningún *worker* sepa procesar.
4. **Programación de las dos cadencias** en el contenedor del *scheduler* (`ADR-037`, `§D.4.1.1`): `auth:purge-maintenance` diario —ampliado con las tres purgas de MFA— y `auth:mfa-obligations` horario. **Comprobar con `schedule:list` que las dos aparecen**: es lo único que distingue «las tareas existen» de «las tareas corren».
5. **La primera ejecución de las purgas retirará de golpe todo lo acumulado desde que 1.3 se desplegó** (altas vencidas y factores borrados lógicamente hace más de 30 días). Es el efecto buscado y no hay nada que preservar —ese material no tiene finalidad—, pero conviene saber que ese día la tabla encoge de forma visible y que **no es un incidente**.

**Reversión**: `migrate:rollback --step=1` deja el esquema como está hoy. **Con una consecuencia real que hay que escribir en el procedimiento**: si al revertir hay factores `email` confirmados, esas personas quedan con un factor que la versión anterior no sabe verificar, y **la salida es un restablecimiento por administrador**. Por eso la reversión es segura **el día del despliegue** y deja de serlo en cuanto un centro active el método y alguien lo use.

### D.11.3 Lo que hay que verificar en el entorno real y no se puede verificar en WSL2

Amplía `§C.11.3`:

- **Que el correo con el código llega en menos de lo que vive el desafío.** En desarrollo el *mailer* es `log` y la latencia es cero; en producción, con `0.10c` resuelto, hay que medirla contra los 5 minutos del desafío y decidir si `AUTH_MFA_CHALLENGE_TTL_MINUTES` sigue siendo suficiente.
- **Que el asunto no revela el código** en el cliente de correo real y en la notificación del teléfono, no solo en la plantilla.
- **Que el *scheduler* ejecuta las cinco tareas por tenant** y no una sola vez para el primero, que es el fallo característico de `RunsPerTenant` mal cableado (`CA-AUTH-174`).
- **Que las dos cadencias son las que dicen ser**: `schedule:list` en el contenedor real, no en WSL2 con el *scheduler* apagado. Una purga que solo existe en el código no purga (`§D.4.1.1`).
- **Que la pantalla de administración se comporta ante un `403` real** —usuario sin permiso contra el servidor de verdad—, no solo con respuestas simuladas en pruebas de componente (`permisos.md §D.6.3`).
