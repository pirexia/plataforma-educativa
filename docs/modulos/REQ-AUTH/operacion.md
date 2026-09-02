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

---

# Parte E · Paso 1.4 · Operación (`REQ-AUTH-002`)

> **Estructura**: §1-§11 son 1.2, `§B.*` es 1.2b, `§C.*` es 1.3 y `§D.*` es 1.3b, los cuatro cerrados. Esta **Parte E** es el paso **1.4**, **implementada** (2026-09-01, rama `feature/REQ-AUTH-002-google-login-fusion-cuentas`, PR [#143](https://github.com/pirexia/plataforma-educativa/pull/143)): describe la operación tal como existe, en revisión independiente antes de mezclar.
>
> Escrita sobre la **opción A** de `funcional.md §E.3` (una URI de redirección por tenant), **decidida por el usuario el 2026-08-31**. Su coste operativo —un paso manual por centro y un tope de URIs registradas— está en `§E.12.2`, y es el apartado que hay que llevar a `SYSADMIN.md`.

---

## E.1 Comportamiento con el módulo activo o inactivo

**`REQ-AUTH` sigue sin ser desactivable** (`RN-AUTH-35`) y **ninguna ruta de este paso lleva `module-enabled`** (`CA-AUTH-231`), por el motivo de §1: una fila mal puesta en `module_subscriptions` no puede dejar a un centro sin poder entrar.

Lo que este paso introduce, y es distinto, es un **eje de configuración que no existía**: el proveedor externo puede estar **no configurado**. No es una degradación, es la situación por defecto, y **tiene un valor propio y explícito** (`AUTH_OAUTH_DRIVER=none`, `§E.2.1`), no la ausencia de configuración cayendo en otra cosa:

| Estado | Qué ocurre |
|--------|------------|
| **`AUTH_OAUTH_DRIVER=none`** — el valor por defecto | `GET /auth/identity-providers` devuelve `data: []`, la pantalla **no pinta el botón**, `POST /auth/oauth-authorizations` responde `422` y el *callback* responde `302` con `resultado=estado_no_valido`. **Todo el login local funciona igual, y el arranque de la aplicación no se ve afectado en ningún entorno** |
| `AUTH_OAUTH_DRIVER=google` con credenciales | El botón aparece y el flujo funciona |
| `AUTH_OAUTH_DRIVER=google` con credenciales incorrectas | El botón aparece y el flujo termina en `error_proveedor`. **Es el fallo silencioso de este paso** y por eso hay alerta (`§E.8`) |
| `AUTH_OAUTH_DRIVER=fake` en `local`/`testing` | Proveedor simulado (`§E.10`) |
| `AUTH_OAUTH_DRIVER=fake` fuera de `local`/`testing` | **La aplicación no arranca**, a propósito (`§E.2.1`, guarda 1) |

**Los dos endpoints de autoservicio no dependen del proveedor.** `GET /auth/identities` y `DELETE /auth/identities/{public_id}` siguen funcionando con `driver=none`, y es deliberado: un centro que desactive Google después de que haya vínculos creados **tiene que dejar que sus usuarios los vean y los retiren**. Un vínculo que no se puede desvincular porque se apagó el proveedor es un dato personal atrapado.

**Google nunca es la única puerta.** No hay ningún estado del sistema en el que un usuario dependa de Google para entrar: siempre tiene su contraseña (`RN-AUTH-96`, y la guarda de `funcional.md §E.4.5`). Es la propiedad que hace que una caída de Google sea una molestia y no una incidencia.

---

## E.2 Variables de entorno

### E.2.1 Propias del paso

| Variable | Uso | Valor por defecto | Valor en desarrollo |
|----------|-----|-------------------|---------------------|
| `AUTH_OAUTH_DRIVER` | **Tres valores: `none`, `google`, `fake`.** Selecciona la implementación del proveedor, cuya forma fija `ADR-042`. `none` = **sin proveedor externo** (`§E.1`); `google` = el real; `fake` = el simulado (`§E.10`). **Guarda de arranque: `fake` aborta la aplicación si `APP_ENV` no es `local` ni `testing`, en todos los entornos** | **`none`** | **`fake`, fijado explícitamente** |
| `AUTH_GOOGLE_CLIENT_ID` | Identificador del cliente OAuth. No es secreto, pero identifica el despliegue. Solo se lee con `driver = google` | *(vacío)* | *(vacío)* |
| `AUTH_GOOGLE_CLIENT_SECRET` | **Secreto.** Va en el gestor de secretos de `ADR-037 §7`, **nunca en `.env` versionado ni en la unidad Quadlet en claro**. Solo se lee con `driver = google` | *(vacío)* | *(vacío)* |
| `AUTH_OAUTH_STATE_TTL_MINUTES` | Vida del `state` y del verificador PKCE en el *payload* de la sesión (`RN-AUTH-91`) | `10` | `10` |
| `AUTH_RATE_LIMIT_OAUTH_START_PER_IP` | Arranques de flujo por IP y minuto (`oauth_start_ip`) | `10` | `10` |
| `AUTH_RATE_LIMIT_OAUTH_CALLBACK_PER_IP` | *Callbacks* por IP y minuto (`oauth_callback_ip`) | `20` | `20` |
| `AUTH_RATE_LIMIT_IDENTITY_PROVIDERS_PER_IP` | Consultas del catálogo de proveedores por IP y minuto (`identity_providers_ip`) | `60` | `60` |
| `AUTH_RATE_LIMIT_MFA_CHALLENGE_READ_PER_SESSION` | Lecturas del desafío en curso por sesión y minuto (`mfa_challenge_read_session`, `§E.6`) | `30` | `30` |

**Guardas de arranque, en todos los entornos** —mismo patrón que `SESSION_DOMAIN` (§2.2) y que la política de contraseñas—:

1. **`AUTH_OAUTH_DRIVER=fake` con `APP_ENV` distinto de `local`/`testing` ⇒ la aplicación no arranca.** Es la guarda más importante que introduce este paso: un proveedor de identidad simulado en producción **es una evasión completa de la autenticación**, la peor configuración incorrecta que este repositorio admite. Y por eso la ruta del proveedor simulado tampoco se registra fuera de esos entornos (`CA-AUTH-230`): dos barreras, no una.
2. **`AUTH_OAUTH_DRIVER=google` con `AUTH_GOOGLE_CLIENT_SECRET` vacío ⇒ la aplicación no arranca.** Sin esto, el sistema levanta con el botón pintado y todo el mundo termina en `error_proveedor` sin que nadie sepa por qué.
3. **`AUTH_OAUTH_DRIVER=google` con `APP_URL` sobre `http` ⇒ la aplicación no arranca fuera de `local`.** Google no admite URIs de redirección sin TLS, y un despliegue así solo puede fallar.

**`none` no dispara ninguna guarda, en ningún entorno.** Es la propiedad que hace que el valor por defecto sea seguro de desplegar, y es la razón de que exista como valor propio.

#### Por qué `none` es un valor y no la ausencia de valor (issue [#140](https://github.com/pirexia/plataforma-educativa/issues/140))

Este apartado nace de un defecto **de esta especificación**, detectado durante la implementación, y se deja escrito porque el modo de fallo es de los que se repiten.

La versión anterior de esta tabla documentaba solo dos valores —`google` y `fake`— y ponía `fake` en la columna «valor en desarrollo» sin decir cuál era el valor **por defecto**. Con esa lectura, lo razonable al implementar era que la ausencia de configuración cayera en `fake`. Y `fake` es exactamente el valor que la guarda 1 prohíbe fuera de `local`/`testing`.

**Resultado: desplegar 1.4 en producción sin fijar `AUTH_OAUTH_DRIVER` tumbaba el arranque de la aplicación entera** — no el login con Google, sino `REQ-AUTH` completo y con él la plataforma, que es el modo de fallo total del que §1 dice que no puede existir. Y contradecía de frente lo que `§E.12.1` prometía: que el día del despliegue no cambia nada para nadie.

Las dos mitades eran correctas por separado; el hueco estaba entre ellas. La lección, que vale más allá de este paso: **un valor por defecto que dispara una guarda de arranque no es un valor por defecto, es una trampa.** Todo interruptor de este módulo con una guarda detrás necesita un valor de reposo explícito que no la dispare nunca.

**La guarda 1 no se relaja** — sigue siendo correcta y deliberadamente estricta. Lo que se arregla es que el estado de reposo tenga nombre propio.

#### Los entornos de desarrollo fijan `fake` explícitamente

Consecuencia directa de lo anterior: **`local` ya no hereda `fake` por un defecto implícito**. `AUTH_OAUTH_DRIVER=fake` se escribe a mano en `apps/api/.env.example` y en el servicio de la API de `compose.yaml`, junto al resto de variables del entorno de desarrollo.

Cuesta una línea en dos ficheros y compra que **el valor por defecto del código sea el único seguro en cualquier entorno**, en vez de uno que solo es seguro en el entorno donde se escribió.

Ninguna variable de sesión cambia. `SESSION_ENCRYPT=true` gana un motivo más —ahora el *payload* guarda también el verificador PKCE—, pero sigue siendo la misma recomendación de §2.2, y el material que guarda vive diez minutos.

### E.2.2 `APP_KEY`: **sin cambios respecto de `§C.2.2`**

Y conviene decirlo, porque 1.3 sí la cambió de categoría. **1.4 no añade ninguna columna cifrada en reposo**: no se guardan tokens del proveedor (`RN-AUTH-95`) ni secretos de ningún tipo en `user_identities`. Perder `APP_KEY` sigue teniendo exactamente la consecuencia que `§C.11.1` describe —los factores TOTP dejan de verificar— y **este paso no la agrava**.

Lo que sí hay que custodiar como secreto nuevo es `AUTH_GOOGLE_CLIENT_SECRET`, con el procedimiento de `ADR-037 §7`. Su compromiso no da acceso a ninguna cuenta por sí solo —hace falta además el `code` de un usuario— pero permite suplantar a nuestra aplicación frente a Google.

---

## E.3 Servicios externos y degradación

| Servicio | Uso | Si no responde |
|----------|-----|----------------|
| **Google (`accounts.google.com`, endpoint de *token*, `userinfo`)** | Todo el flujo federado | **El login con Google deja de funcionar; el login local no se entera.** El *callback* responde `error_proveedor` y la pantalla ofrece entrar con contraseña. **Sin *circuit breaker***: no hay reintento que hacer, porque la persona está esperando delante del navegador; se falla rápido y se ofrece la alternativa. **Con tiempo de espera corto y explícito** en el cliente HTTP: sin él, una caída de Google convierte cada *callback* en un trabajador de la API bloqueado |
| PostgreSQL | La fila de vínculo y la sesión | Sin cambios respecto de §3 |
| Redis | Límites de tasa | Sin cambios: **el limitador no degrada a «sin límite»**, responde `503` (§3) |
| Correo transaccional | Los tres avisos de `funcional.md §E.4.7` | Depende de `0.10c`. El trabajo reintenta; agotados los reintentos, **el vínculo queda hecho y el titular no se ha enterado**. Es la degradación relevante de este paso: el aviso es la única defensa del titular ante una fusión que no hizo él |
| S3 / MinIO | **No se usa** | — |

---

## E.4 Colas y trabajos (`INV-012`)

| Cola | Trabajo | Disparo | Reintentos |
|------|---------|---------|------------|
| `auth-mail` | `SendIdentityLinkedEmail` | Fusión automática o vinculación desde el perfil | 3, mismo retroceso que `SendPasswordChangedEmail` |
| `auth-mail` | `SendIdentityUnlinkedEmail` | Desvinculación | 3 |

**Ninguna tarea de mantenimiento nueva**, y es una diferencia real con 1.3 y 1.3b. Este paso **no crea ningún artefacto transitorio en base de datos que purgar**: el `state` y el verificador PKCE viven en el *payload* de la sesión y mueren con ella, que ya tiene su propio ciclo. `user_identities` no tiene filas provisionales.

Reglas heredadas que siguen aplicando sin excepción: contexto de tenant entrado y salido por el mecanismo de framework, ejecución por tenant con `RunsPerTenant` donde proceda, y el *scheduler* en su propio contenedor (`ADR-037`).

**Los dos correos nuevos no llevan token ni enlace accionable** (`RN-AUTH-97`), así que —a diferencia de `SendPasswordResetEmail` y `SendAccountLockedEmail`— **no necesitan `ShouldBeEncrypted`** (issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73)): su *payload* no contiene material de credencial. Sí contiene el correo del destinatario, como todos los del módulo.

---

## E.5 Correos que emite el módulo

Dos más, los dos en los cuatro idiomas de `ADR-021` (`INV-009`) y en el idioma preferido del destinatario:

| Correo | Contenido | Enlace |
|--------|-----------|--------|
| Cuenta de Google vinculada | Qué cuenta (**enmascarada**), cuándo, y **si fue una fusión automática o una vinculación desde el perfil**. Qué hacer si no fue el titular | **Ninguno** |
| Cuenta de Google desvinculada | Qué cuenta (enmascarada) y cuándo | **Ninguno** |

Reglas comunes con los tres que ya existen (§5, `§C.5`, `§D.5`):

- **Sin enlace accionable** (`RN-AUTH-50`). Un correo de «alguien vinculó una cuenta a la tuya» con un botón para deshacerlo es un correo de suplantación esperando a ocurrir.
- **La dirección de Google va enmascarada** también en el correo, con el mismo `DestinationMasker` de `§D.4.5`. El titular la reconoce; quien intercepte el correo no se lleva una dirección personal completa.
- **Distinguir fusión de vinculación es la parte útil del aviso.** «Vinculaste tu cuenta» es esperable; «el sistema vinculó tu cuenta porque los correos coincidían» es lo que alguien tiene que poder reconocer como ajeno.

---

## E.6 Límites de tasa

**Cuatro *buckets* nuevos, y dos endpoints deliberadamente sin *bucket* propio.** Amplía §6 y `§C.6`, con sus mismos criterios.

| Endpoint | Límite | Clave (*bucket*) |
|----------|--------|------------------|
| `POST /auth/oauth-authorizations` | **10 / min** | IP — `oauth_start_ip` |
| `GET /auth/oauth/google/callback` | **20 / min** | IP — `oauth_callback_ip` |
| `GET /auth/identity-providers` | **60 / min** | IP — `identity_providers_ip` |
| `GET /auth/mfa-challenges` (`api.md §E.5b`) | **30 / min** | `(tenant_id, session_id)` — `mfa_challenge_read_session` |
| `GET /auth/identities` | **ninguno propio** | Ver abajo |
| `DELETE /auth/identities/{public_id}` | **ninguno propio** | Ver abajo |

- **Toda clave incluye el `tenant_id`** (`ADR-033 §9`), sin cambios respecto de §6 y `§C.6`.
- **`GET /auth/identity-providers` va a 60/min por IP, el mismo número que `GET /auth/csrf-cookie`** (§6), y por el mismo motivo: es un `GET` anónimo, barato, sin efectos, que la SPA pide **en cada carga de la pantalla de login**. Un límite estrecho aquí rompe el uso normal antes que ningún abuso. Es el análogo más cercano que tiene el módulo y se le copia el valor a propósito, en vez de inventar uno.
- **`GET /auth/mfa-challenges` se lleva por sesión y no por IP**, igual que `POST /auth/mfa-verifications` (`§C.6`): **la sesión es lo que identifica el desafío** (`RN-AUTH-53`). Y va **mucho más holgado que el `POST` de verificación** (30/min frente a 5/min) porque **no adivina nada**: es una lectura sin efectos, que no entrega código, no consume intentos y no prolonga la caducidad (`api.md §E.5b`). Lo que defiende es el servidor, no la cuenta. Aun así lleva *bucket*, y no se deja al limitador global, porque **es alcanzable sin autenticación** —basta una sesión anónima con desafío abierto— y §6 fija que toda superficie anónima de este módulo lleva su defensa activa.

**Por qué los dos de `/auth/identities` no llevan *bucket* propio**, que es la parte que hay que argumentar y no dar por hecha:

1. **Exigen sesión**, así que no amplían la superficie anónima que §6 defiende. Es literalmente el criterio que `api.md §B.1` fijó para los tres *endpoints* de sesiones activas de 1.2b —listar y revocar en autoservicio, la forma más parecida que hay en el módulo— y que dejó escrito que *«los `429` que puedan aparecer son los del limitador global, no de un bucket propio»*.
2. **`DELETE /auth/identities/{public_id}` verifica la contraseña actual, y aun así tampoco lo lleva**, exactamente igual que `DELETE /auth/mfa-factors/{public_id}` de 1.3, que tiene la misma forma y **no aparece en la tabla de `§C.6`**. La razón es la buena: **contra la fuerza bruta de contraseña ahí no defiende el límite de tasa, defiende el bloqueo de cuenta**, porque sus fallos incrementan el contador de `RN-AUTH-14` (`RN-AUTH-96`, `funcional.md §E.4.5` punto 2). El límite se olvida en una ventana; el bloqueo persiste. Añadirle un *bucket* propio no cerraría ningún hueco y **crearía la inconsistencia de tratar distinto a dos endpoints idénticos**.

**No hay límite por `(tenant_id, email)`** en ninguno de los cuatro, a diferencia de los anónimos de 1.2, y hay que decir por qué: **en el arranque del flujo no hay correo todavía**, y en el *callback* el correo lo pone Google, no el cliente. El límite por sujeto en el camino federado lo aporta el bloqueo de cuenta, que sí se aplica (`funcional.md §E.6`).

- **`429` siempre con `Retry-After`** (`ADR-038 §6.5`).
- **El limitador sigue sin degradar a «sin límite»**: si su almacén no responde, `503` (§3).
- **El punto ciego de §6 y `§C.6` —un centro entero detrás de una IP de salida— no empeora en este paso.** Un login federado sustituye a uno local, no se suma: son 1 arranque + 1 *callback* por persona en vez de 1 `POST /auth/session`. Lo único que sí se suma es `GET /auth/identity-providers`, y por eso va a 60/min. **Sigue pendiente de medir con `REQ-SEED` (1.15b)**, sin cambios en esa recomendación.

---

## E.7 Caché

**Ninguna caché nueva.**

Y una advertencia concreta para la implementación del envoltorio de `ADR-042`, porque es el sitio donde se metería una caché sin querer: **el flujo no debe verificar la firma del *ID token* contra el JWKS de Google**, porque **no lo necesita**. El *token* se obtiene en una llamada servidor a servidor sobre TLS contra el *endpoint* de Google; la verificación de firma protege contra un *token* recibido por un canal no fiable, que no es el caso. Tomar el camino de verificación por JWKS obligaría a descargar y cachear el juego de claves de Google, con su invalidación y su modo de fallo propio, para no ganar nada. Si la librería elegida ofrece los dos caminos —y `laravel/socialite` los ofrece: usa `userinfo` con el *access token*, y solo verifica JWKS si se le pasa un *ID token*—, **se usa el que no verifica firmas**, y queda escrito aquí para que no se elija el otro por parecer más seguro.

`GET /auth/identity-providers` tampoco se cachea: lee configuración de proceso, no base de datos.

---

## E.8 Métricas y alertas

| Métrica | Alerta |
|---------|--------|
| `auth.oauth.callback.outcome` por código de resultado | **`error_proveedor` por encima del 5 % en 15 minutos ⇒ aviso.** Es la señal de credenciales mal configuradas o de Google caído, y sin ella el fallo es silencioso: cada usuario lo ve, nadie lo agrega |
| `auth.oauth.callback.outcome{estado_no_valido}` | Un pico sostenido es, o un problema de cookies en algún navegador, o alguien probando el *callback* a mano |
| `auth.identity.merged` | **Cualquier ráfaga es sospechosa.** Las fusiones son eventos raros: una por persona y por centro, como mucho. Diez en un minuto significa algo que hay que mirar |
| `auth.identity.unlinked` | Ídem |
| `login_attempts` con `outcome = 'federado_sin_vinculo'` | Volumen alto desde pocas IP: alguien probando qué correos tienen cuenta. **No es un oráculo** (`funcional.md §E.4.6`), pero sigue siendo actividad que merece mirarse |

---

## E.9 Problemas conocidos y diagnóstico

| Síntoma | Causa probable | Comprobación |
|---------|----------------|--------------|
| Todo *callback* responde `estado_no_valido` | La cookie de sesión no llega en la navegación de vuelta | `SESSION_SAME_SITE` debe ser `lax`, **nunca `strict`** (`RN-AUTH-27`): con `strict` la cookie no viaja en una navegación que viene de Google y **el flujo no puede funcionar de ninguna manera**. Es el fallo más probable de este paso |
| Google responde `redirect_uri_mismatch` | La URI de este tenant no está registrada, o difiere en el esquema, el puerto o la barra final | La consola de Google, contra la URI que construye `RN-AUTH-92`. Se compara **carácter a carácter** |
| Google responde `invalid_client` | Secreto incorrecto o cliente de otro proyecto | El gestor de secretos |
| **La aplicación no arranca tras desplegar 1.4** | `AUTH_OAUTH_DRIVER=fake` fuera de `local`/`testing` (guarda 1), o `google` sin secreto (guarda 2) | El mensaje de la guarda lo dice. **Salida inmediata: `AUTH_OAUTH_DRIVER=none` y reinicio** (`§E.12.3`), que devuelve el sistema al estado de reposo sin tocar nada más. Es el modo de fallo del issue [#140](https://github.com/pirexia/plataforma-educativa/issues/140), cerrado con el valor `none` por defecto y `CA-AUTH-235` |
| El botón no aparece | `AUTH_OAUTH_DRIVER=none`, que es el estado por defecto | `GET /auth/identity-providers` debe devolver el proveedor. Si devuelve `[]`, el *driver* es `none` o faltan credenciales (`§E.1`) |
| El botón aparece y todo falla con `error_proveedor` | Credenciales presentes pero incorrectas, o Google inalcanzable desde el contenedor | Salida de red del contenedor de la API y el *log* de aplicación, donde va el detalle que **no** se le da al usuario |
| Un usuario entra con Google y **no** le piden el segundo factor | **Incidencia de severidad crítica.** Es una evasión del segundo factor | `RN-AUTH-94` y `CA-AUTH-216`. Se detiene el trabajo en curso y se resuelve de inmediato (`CLAUDE.md §5`) |
| Se creó un usuario a partir de un login de Google | **Incidencia crítica.** `RN-AUTH-99`: en 1.4 ese camino **no existe** (`OPEN-AUTH-31`, resuelta en restrictivo el 2026-08-31) | El código no debe tener ese camino. Si aparece un `users`/`people` nuevo con origen federado, es un fallo de implementación, no una configuración |
| **En desarrollo (WSL2)**, el navegador aterriza en un `404` servido por la API tras completar el proveedor simulado, aunque el backend resolvió la identidad y creó la sesión correctamente | El `302` del *callback* es **relativo** (`api.md §E.4`, correcto para la topología de un solo origen de producción/*staging* vía Traefik, `ADR-028`) y se resuelve contra el origen de la petición en curso — la **API** (`:8000`), no la SPA (`:5173`), en el entorno de orígenes separados de `ADR-030`/issue #71 | **No es un fallo del flujo**: compruébese en base de datos (`user_identities`, `login_attempts` con `outcome=exito`/`method=google`) que la identidad se resolvió bien, y navegue manualmente el navegador a la misma ruta bajo `:5173` — la sesión ya es válida y la SPA la sirve con normalidad. Detalle completo y propuesta de solución de desarrollo en el issue [#141](https://github.com/pirexia/plataforma-educativa/issues/141) (severidad Media, no bloquea 1.4, no afecta a producción) |

---

## E.10 Desarrollo sin credenciales reales: el proveedor simulado

**Esto no es una comodidad: es la única forma de que este paso se pueda desarrollar y probar en el entorno que el proyecto tiene.**

### E.10.1 Por qué no se puede usar Google de verdad en WSL2

`ADR-030` fija el desarrollo en WSL2, y el entorno sirve `{slug}.{TENANCY_BASE_DOMAIN}` sobre HTTP. **Google exige que la URI de redirección de un cliente de tipo «aplicación web» sea `https` sobre un dominio público registrable**; la excepción de `http://localhost` no sirve, porque `ResolveTenant` necesita un host con forma `{slug}.{base}` y `localhost` no la tiene (`TenantHost::slugFrom()` devuelve `null` ⇒ `404`).

Es decir: **no hay combinación de configuración que permita completar un flujo real de Google en el entorno de desarrollo actual**, y depende de `0.10b` (dominio, DNS con comodín y certificado), pendiente. Hay que verificar el detalle exacto contra la consola de Google antes de implementar, pero el diseño **no debe depender de que la respuesta sea una u otra**.

### E.10.2 Qué se entrega en su lugar

Un segundo `IdentityProvider` (`funcional.md §E.7.2`), **`FakeIdentityProvider`**, tras la misma interfaz — que es exactamente para lo que `RNF-MANT-007` obliga a envolver la dependencia:

1. `AUTH_OAUTH_DRIVER=fake`. La `authorization_url` que devuelve `POST /auth/oauth-authorizations` apunta a **una ruta de la propia API**, registrada **solo** en `local`/`testing`.
2. Esa ruta pinta un formulario mínimo con `sub`, `email` y una casilla `email_verified`, y al enviarlo redirige al *callback* real con un `code` que el proveedor simulado sabe canjear.
3. **El resto del flujo es el de verdad**: el mismo `state`, el mismo PKCE, el mismo *callback*, la misma resolución de identidad, la misma fusión, el mismo `MfaPolicy`. Lo único simulado es de dónde salen los *claims*.
4. Es también lo que usan los tests: **`RN-AUTH-87` se prueba de verdad** —con `email_verified` a `false` y a `true`— sin depender de una cuenta de Google real, que es algo que en un test no debe existir jamás.

### E.10.3 Las dos barreras que impiden que llegue a producción

1. **Guarda de arranque**: `AUTH_OAUTH_DRIVER=fake` fuera de `local`/`testing` **aborta la aplicación**, en todos los entornos (`§E.2.1`).
2. **La ruta del proveedor simulado no se registra** fuera de esos entornos, y hay test que lo comprueba con `APP_ENV=production` (`CA-AUTH-230`).

Dos y no una, porque lo que hay al otro lado de un descuido aquí **no es una funcionalidad rota: es cualquiera entrando como cualquiera**.

### E.10.4 Lo que queda sin verificar, y hay que decirlo al cerrar

Los pasos 1.2, 1.2b, 1.3 y 1.3b se cerraron con **verificación en navegador real**. **1.4 no podrá cerrarse así** mientras `0.10b` siga pendiente: lo que se verificará en navegador es el flujo completo con el proveedor simulado, más las pruebas de contrato contra las respuestas documentadas de Google. Queda pendiente, en un entorno con dominio público, comprobar: que la URI registrada coincide, que el consentimiento se muestra con los tres *scopes* pedidos, que `email_verified` llega como se espera para una cuenta de Workspace y para una de consumo, y que la cookie de sesión viaja en la navegación de vuelta con `Secure` y TLS reales. **Está escrito aquí para que se convierta en tarea y no en un olvido.**

---

## E.11 Impacto en copias de seguridad y restauración

**Ninguno nuevo.** `user_identities` es una tabla de tenant ordinaria y entra en la copia como el resto. **No contiene material cifrado**, así que —a diferencia de 1.3— **restaurar una copia sin la `APP_KEY` correspondiente no rompe nada de este paso**: los vínculos siguen resolviendo, porque el `sub` está en claro y no es un secreto.

Lo que sí hay que tener a mano en una recuperación es **`AUTH_GOOGLE_CLIENT_SECRET`**, que vive en el gestor de secretos y no en la copia de la base de datos (`ADR-037 §7.2`). Sin él, el login local funciona y el federado no — que es exactamente la degradación aceptable de `§E.3`.

---

## E.12 Despliegue

### E.12.1 El día del despliegue no cambia nada para nadie

Y es lo importante. **Y es cierto por una razón concreta, no por casualidad: el valor por defecto de `AUTH_OAUTH_DRIVER` es `none`** (`§E.2.1`), que no dispara ninguna guarda de arranque en ningún entorno.

Desplegado sin tocar una sola variable, el sistema queda exactamente como estaba: mismo login, mismas pantallas, mismo comportamiento, con una tabla vacía y una columna nueva con valor por defecto (`datos.md §E.7`). **El botón aparece el día que alguien configura el proveedor, no el día que se despliega el código.** Es la diferencia con 1.3, donde el despliegue activó la obligación de MFA de dos roles en todos los tenants (`§C.11.1`).

**Esto hay que poder afirmarlo con un test, no con un párrafo** — y la primera versión de esta especificación lo afirmaba solo con un párrafo, que es de donde salió el issue [#140](https://github.com/pirexia/plataforma-educativa/issues/140). Lo cubre **`CA-AUTH-235`**: arranque con `APP_ENV=production` y **sin `AUTH_OAUTH_DRIVER` fijado**, que debe completar sin excepción.

### E.12.2 Trabajo manual nuevo en el alta de cada tenant

**Este es el coste operativo de la decisión del 2026-08-31** (`funcional.md §E.3.5`, opción A). Dar de alta un centro deja de ser solo un clic en el backoffice: hay que **registrar `https://{slug}.{base}/api/v1/auth/oauth/google/callback` en la consola de Google** antes de que nadie de ese centro pueda usar el botón.

Tres cosas que hay que escribir en `SYSADMIN.md` y en el procedimiento de alta, no solo aquí:

1. **Es un paso manual, fuera del producto.** Si se olvida, el centro tiene el botón y todos sus usuarios reciben `redirect_uri_mismatch` (`§E.9`). **El alta de tenant no lo detecta ni lo avisa**: el fallo aparece cuando la primera persona pulsa el botón.
2. **Hay un tope de URIs registradas por cliente OAuth**, que hay que **verificar en la consola antes del primer despliegue** y **anotar como límite duro de número de centros** con este diseño.
3. **Un centro con dominio propio (`RMT-008`) necesita su propia URI**, y `RMT-008` no está implementado todavía.

**El punto 2 es el disparador de la migración a la opción B**, y por eso es una cifra y no una impresión: cuando el número de centros se acerque al tope, se retoma `funcional.md §E.3.3` con su propio ADR, y a cambio del registro manual aparecen una tabla fuera del sistema de tenancy, un host adicional con su certificado y una excepción a `§4.7`. **Conviene vigilarlo antes de llegar**, porque migrar con centros en producción significa cambiar la URI registrada de todos ellos a la vez.

### E.12.3 Orden y reversión

1. Migraciones (`datos.md §E.7`), antes del código. Son *expand* puras.
2. Despliegue de la aplicación **sin tocar ninguna variable nueva**. `AUTH_OAUTH_DRIVER` queda en `none` por defecto, el sistema arranca y queda idéntico al anterior. **No hay que fijar nada para que este paso sea seguro**, que es justo lo que el issue [#140](https://github.com/pirexia/plataforma-educativa/issues/140) demostró que no se cumplía.
3. Registro de la URI en la consola de Google y carga del secreto en el gestor de secretos.
4. Configuración de `AUTH_OAUTH_DRIVER=google` **más** `AUTH_GOOGLE_CLIENT_ID`/`SECRET`, y reinicio. **El botón aparece aquí.** Los tres valores van juntos: `google` sin secreto no arranca (guarda 2), y es a propósito — es preferible que el reinicio falle a que el centro entero descubra el problema pulsando el botón.

**La reversión es limpia y tiene dos escalones**, según lo que se quiera deshacer:

- **Apagar solo Google**: `AUTH_OAUTH_DRIVER=none` y reinicio. Sin tocar la base de datos, sin desplegar y sin retirar las credenciales. Los vínculos ya creados dejan de servir para entrar, **pero sus titulares siguen pudiendo verlos y retirarlos** (`§E.1`), y **nadie se queda fuera**, porque nadie depende de Google para entrar.
- **Revertir la aplicación**: la migración del `CHECK` de `login_attempts` es de un solo sentido si ya hay filas con el valor nuevo (`datos.md §E.7`), como todas las anteriores del mismo tipo, y revertir la aplicación no exige revertirla.

**Volver a `none` es la primera maniobra ante cualquier incidencia del proveedor**, y conviene que esté escrita aquí y no haya que deducirla: es un cambio de variable y un reinicio, no toca datos y no es destructiva.

### E.12.4 Lo que hay que verificar en el entorno real y no se puede verificar en WSL2

`§E.10.4`, entero. Es la lista concreta, y es más larga que la de cualquier paso anterior del módulo.

---

# Parte F · Paso 1.4b · Operación (`REQ-AUTH-004`)

> **Estructura**: §1-§11 son 1.2, `§B.*` es 1.2b, `§C.*` es 1.3, `§D.*` es 1.3b y `§E.*` es 1.4, los cinco cerrados. Esta **Parte F** es el paso **1.4b**, **implementada** (pendiente de revisión independiente y de mezclar a `develop`).
>
> Escrita sobre `ADR-043` (**ACEPTADA**) y sobre las tres decisiones del usuario del 2026-09-01. **El cambio operativo mayor del paso es que `APP_KEY` gana responsabilidad por segunda vez** (`§F.2.2`), y el segundo es que **el trabajo manual de alta de tenant que 1.4 introdujo desaparece** (`§F.12.2`).

---

## F.1 Comportamiento con el módulo activo o inactivo

**`REQ-AUTH` sigue sin ser desactivable** (`RN-AUTH-35`) y **ninguna ruta de este paso lleva `module-enabled`** (`CA-AUTH-306`), **tampoco las ocho de administración**: un administrador que no puede corregir la configuración de su IdP porque una fila de `module_subscriptions` está mal es el mismo fallo total de §1 con otra ropa.

Lo que este paso introduce es un eje de configuración **por tenant y en base de datos**, no por despliegue. Sus estados:

| Estado | Qué ocurre |
|--------|------------|
| **Sin proveedores catalogados** — el estado de **todos** los tenants el día del despliegue | `GET /auth/identity-providers` devuelve exactamente lo que devolvía en 1.4; las ocho rutas de administración responden con normalidad y colección vacía. **Nada cambia para nadie** (`§F.12.1`) |
| Proveedor catalogado y **no activo** | No se pinta y **no arranca el flujo** aunque se llame a mano (`RN-AUTH-102`) |
| Proveedor activo con credencial vigente | El botón aparece y el flujo funciona |
| Proveedor activo **sin credencial vigente** | `POST /auth/oauth-authorizations` responde `422` **y se emite alerta** (`§F.8`). Es el estado en que un centro cree tener SSO y no lo tiene. **Solo se alcanza retirando la última credencial de un proveedor ya activo**, porque activarlo sin credencial responde `409` (`api.md §F.3`) |
| Proveedor activo con credencial **caducada en el IdP** | El canje falla ⇒ `error_proveedor` para todo el centro. **Es el fallo con mayor impacto del paso**, y por eso hay ventana de rotación, aviso a 30 días y alerta (`§F.4`, `§F.8`) |
| Emisor inalcanzable | `error_proveedor`. **El login local sigue funcionando** |

**El SSO institucional nunca es la única puerta.** No hay ningún estado del sistema en el que un usuario dependa del IdP de su centro para entrar: siempre tiene su contraseña (`RN-AUTH-96`, y la guarda de `§E.4.5`). Es la propiedad que hace que una caída del IdP del centro sea una molestia y no una incidencia — y es la propiedad que `OPEN-AUTH-39` pondría en juego si cambiara de signo (`funcional.md §F.0.3` punto 3).

**Los dos *endpoints* de autoservicio de vínculos no dependen del proveedor**, igual que en `§E.1`: `GET /auth/identities` y `DELETE /auth/identities/{public_id}` siguen funcionando con un proveedor desactivado o borrado. Un vínculo que no se puede retirar porque se apagó el proveedor es un dato personal atrapado.

---

## F.2 Variables de entorno

### F.2.1 Propias del paso

| Variable | Uso | Valor por defecto | Valor en desarrollo |
|----------|-----|-------------------|---------------------|
| `AUTH_SSO_DISCOVERY_TIMEOUT_SECONDS` | Tiempo de espera de la descarga del documento de descubrimiento (`funcional.md §F.4.2` guarda 4) | `5` | `5` |
| `AUTH_SSO_DISCOVERY_MAX_BYTES` | Tope de tamaño de ese documento | `262144` | `262144` |
| `AUTH_SSO_DISCOVERY_MAX_REDIRECTS` | Guarda 3 | `3` | `3` |
| `AUTH_SSO_DISCOVERY_REFRESH_DAYS` | Antigüedad a partir de la cual la tarea programada refresca un proveedor (`§F.4`) | `7` | `7` |
| `AUTH_SSO_SECRET_EXPIRY_WARNING_DAYS` | Antelación del aviso de caducidad de credencial (`§F.4`) | `30` | `30` |
| `AUTH_SSO_CLOCK_SKEW_SECONDS` | Tolerancia de reloj al validar `exp`/`iat` del `id_token` (`RN-AUTH-104`) | `120` | `120` |
| `AUTH_SSO_TOKEN_TIMEOUT_SECONDS` | Tiempo de espera del canje de código/`userinfo` con el emisor | `5` | `5` |
| `AUTH_SSO_ALLOW_INSECURE_DISCOVERY` | **Permite `http` en el descubrimiento y en los *endpoints* del emisor.** Existe **solo** para el emisor simulado de `§F.10`. **Guarda de arranque: aborta la aplicación si es `true` y `APP_ENV` no es `local` ni `testing`, en todos los entornos** | **`false`** | **`true`, fijado explícitamente** |
| `AUTH_RATE_LIMIT_OIDC_CALLBACK_PER_IP` | *Callbacks* institucionales por IP y minuto (`oidc_callback_ip`) | `20` | `20` |
| `AUTH_RATE_LIMIT_SSO_DISCOVERY_PER_TENANT` | Validaciones de descubrimiento por tenant y minuto (`sso_discovery_tenant`) | `6` | `6` |
| `AUTH_RATE_LIMIT_SSO_SECRET_PER_TENANT` | Cargas de credencial por tenant y minuto (`sso_secret_tenant`) | `6` | `6` |

**Guardas de arranque, en todos los entornos** —mismo patrón que `SESSION_DOMAIN` (§2.2), la política de contraseñas y las tres de `§E.2.1`—:

1. **`AUTH_SSO_ALLOW_INSECURE_DISCOVERY=true` con `APP_ENV` distinto de `local`/`testing` ⇒ la aplicación no arranca.** Con `http` admitido, el documento que decide **dónde se autentica el personal de un centro** viaja en claro y lo puede reescribir cualquiera en el camino. Es la guarda más importante que introduce este paso, y es la hermana exacta de la guarda 1 de `§E.2.1`.
2. **La ruta del emisor simulado no se registra** fuera de `local`/`testing`, con test que lo comprueba con `APP_ENV=production` (`§F.10.3`). **Dos barreras, no una**, por el mismo motivo que en 1.4: lo que hay al otro lado de un descuido aquí no es una funcionalidad rota, es cualquiera entrando como cualquiera.

**Y una propiedad que hay que poder afirmar y no solo escribir** (lección del issue [#140](https://github.com/pirexia/plataforma-educativa/issues/140), `§E.2.1`): **ninguna variable de este paso tiene un valor por defecto que dispare una guarda de arranque.** `AUTH_SSO_ALLOW_INSECURE_DISCOVERY` vale `false` por defecto, que es el valor seguro en cualquier entorno, y el entorno de desarrollo lo fija a `true` **a mano** en `apps/api/.env.example` y en `compose.yaml`, nunca por herencia. Lo cubre `CA-AUTH-310`.

**Ninguna variable nueva lleva credenciales de ningún tenant**, y es el cambio de modelo del paso: en 1.4 el secreto era del despliegue (`AUTH_GOOGLE_CLIENT_SECRET`, `EnvironmentFile=`); aquí es **de cada centro** y vive cifrado en base de datos, porque un `EnvironmentFile=` cambiaría con cada alta de tenant y exigiría reiniciar el servicio (`ADR-043 §8.2`).

**`AUTH_OAUTH_DRIVER` y `AUTH_GOOGLE_CLIENT_*` no cambian.** El *driver* global de 1.4 sigue existiendo, con sus tres valores, sus tres guardas y su valor por defecto `none`.

### F.2.2 `APP_KEY`: gana responsabilidad, **por segunda vez**

Y hay que decirlo en voz alta, porque es la consecuencia de operación que este paso introduce y es de las que no se notan hasta que se necesitan.

1.3 convirtió `APP_KEY` en custodio de los secretos TOTP (`§C.2.2`, `§C.11.1`). 1.4 **no la tocó** (`§E.2.2`). **1.4b la vuelve a cargar**: a partir de este paso, `APP_KEY` cifra **la credencial de cliente de cada proveedor de cada tenant** (`datos.md §F.3`).

Consecuencias concretas, encadenadas:

- **Perder `APP_KEY` deja sin SSO institucional a todos los tenants a la vez**, además de romper la verificación de los factores TOTP. La salida es la misma que en `§C.11.1`: no hay recuperación criptográfica, hay reconfiguración — cada centro vuelve a cargar su credencial. **Es recuperable, y por eso no es catastrófico**; pero es una llamada a 400 centros el día que ocurra.
- **Rotar `APP_KEY` exige re-cifrar estas filas**, exactamente igual que las de `user_mfa_factors`. El procedimiento de rotación de `§C.11.1` **tiene que incluir esta tabla**, y si no la incluye, el día de la rotación el SSO se cae sin que nadie lo relacione. **Es la línea de este documento que más fácil es no leer y más cara es de descubrir.**
- **Las copias de seguridad contienen ahora material sensible cifrado de todos los tenants** (`§F.11`). `ADR-043 §8.2` lo anticipó como la implicación de la decisión, y se acepta con esa implicación a la vista.

**Lo que este paso *no* agrava**: no se guarda ningún `access_token`, `refresh_token` ni `id_token` (`RN-AUTH-95`, `CA-AUTH-307`). El único material nuevo cifrado en reposo es la credencial de cliente, que es **de la plataforma frente a un tercero**, no de ninguna persona.

---

## F.3 Servicios externos y degradación

| Servicio | Uso | Si no responde |
|----------|-----|----------------|
| **El IdP de cada centro** (descubrimiento, autorización, *token*, opcionalmente `userinfo`) | Todo el flujo institucional de ese centro | **El SSO de ese centro deja de funcionar; el resto de centros y el login local no se enteran.** El *callback* responde `error_proveedor` y la pantalla ofrece entrar con contraseña. **Sin *circuit breaker***, por el motivo de `§E.3`: la persona está esperando delante del navegador, se falla rápido y se ofrece la alternativa. **Con tiempo de espera corto y explícito** en el cliente HTTP: sin él, una caída del IdP de un centro convierte cada *callback* en un trabajador de la API bloqueado, y con `N` centros eso es un modo de fallo compartido |
| **El IdP de cada centro** (refresco programado del descubrimiento) | `§F.4` | **Se conservan los *endpoints* anteriores**, se estampa `discovery_failed_at` y se avisa. Un emisor momentáneamente inalcanzable **no deja a un centro sin SSO** |
| PostgreSQL | Catálogo, credenciales, vínculos y sesión | Sin cambios respecto de §3 |
| Redis | Límites de tasa | Sin cambios: **el limitador no degrada a «sin límite»**, responde `503` (§3) |
| Correo transaccional | El aviso de emparejamiento de `funcional.md §F.4.6` | Depende de `0.10c`. El trabajo reintenta; agotados los reintentos, **el vínculo queda hecho y el titular no se ha enterado**. Misma degradación que `§E.3`, con el mismo peso: el aviso es la única defensa del titular ante un vínculo que no hizo él |
| S3 / MinIO | **No se usa** | — |

**Un modo de fallo compartido que hay que anotar y que 1.4 no tenía**: con un solo proveedor global, un IdP lento afectaba a un flujo. Con `N` proveedores de `N` centros, **un IdP lento de un centro consume trabajadores de la API que sirven a todos los demás**. El tiempo de espera corto es lo que acota el daño, y por eso es explícito y no heredado del cliente HTTP por defecto. **Se vigila con `auth.oidc.callback.duration` (`§F.8`)**, y si algún día no basta, la salida conocida es un *bulkhead* por proveedor — que **no se implementa hoy** porque sería complejidad sin medida detrás.

---

## F.4 Colas y trabajos (`INV-012`)

| Cola | Trabajo | Disparo | Reintentos |
|------|---------|---------|------------|
| `auth-mail` | `SendIdentityMatchedEmail` | Emparejamiento en un login institucional | 3, mismo retroceso que `SendIdentityLinkedEmail` |

**Dos tareas de mantenimiento nuevas, y ninguna borra nada** (`datos.md §F.10`):

| Comando | Cadencia | Qué hace |
|---------|----------|----------|
| `auth:refresh-oidc-discovery` | **Diaria** | Para cada proveedor **activo** cuyo `discovery_fetched_at` sea anterior a `AUTH_SSO_DISCOVERY_REFRESH_DAYS`, revalida el documento con **las mismas cinco guardas** que el alta y actualiza los *endpoints*. **Si falla, conserva los anteriores** y estampa `discovery_failed_at`. Ejecuta **por tenant** con `RunsPerTenant` |
| `auth:warn-expiring-client-secrets` | **Diaria** | Marca como *«caduca pronto»* toda credencial vigente cuya `expires_at` esté a menos de `AUTH_SSO_SECRET_EXPIRY_WARNING_DAYS`, emite la métrica de `§F.8` y **avisa al administrador del centro**. Ejecuta por tenant |

**Por qué el refresco es diario y no horario**: un documento de descubrimiento cambia de *endpoints* una vez cada varios años. Diario con ventana de siete días es holgado y no golpea a `N` emisores externos cada hora, que es exactamente la clase de tráfico que un IdP institucional acaba bloqueando.

**Por qué el aviso de caducidad es un comando y no un disparador en el login**: en el login no hay tiempo (`INV-012`) y, sobre todo, **el aviso tiene que salir aunque nadie entre** — un centro en agosto con la credencial venciendo el 1 de septiembre es exactamente el caso que hay que cubrir.

**Las cinco tareas que ya existen no cambian.** `PurgeLoginAttempts` sigue con sus 90 días y la columna `method` no altera nada.

Reglas heredadas que siguen aplicando sin excepción: contexto de tenant entrado y salido por el mecanismo de framework, ejecución por tenant con `RunsPerTenant`, y el *scheduler* en su propio contenedor (`ADR-037`).

**El correo nuevo no lleva token ni enlace accionable** (`RN-AUTH-97`), así que —como los dos de 1.4— **no necesita `ShouldBeEncrypted`** (issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73)): su *payload* no contiene material de credencial. Sí contiene el correo del destinatario, como todos los del módulo.

---

## F.5 Correos que emite el módulo

**Uno más**, en los cuatro idiomas de `ADR-021` (`INV-009`) y en el idioma preferido del destinatario:

| Correo | Contenido | Enlace |
|--------|-----------|--------|
| Cuenta vinculada con el proveedor del centro | **Qué proveedor** (por su nombre visible), cuándo, y **que fue el sistema quien lo vinculó por coincidencia de correo**, no el titular. Qué hacer si no lo reconoce | **Ninguno** |

Reglas comunes con los cinco que ya existen (§5, `§C.5`, `§D.5`, `§E.5`):

- **Sin enlace accionable** (`RN-AUTH-50`).
- **Distinguir el emparejamiento de la vinculación manual es la parte útil del aviso**, igual que en `§E.5`: «vinculaste tu cuenta» es esperable; «el sistema la vinculó» es lo que alguien tiene que poder reconocer como ajeno.
- **El aviso nombra el proveedor del centro, no el correo del directorio.** El titular reconoce «Entra ID del centro» sin que haga falta repetirle su dirección.

**Una consecuencia de volumen que hay que anticipar** (`funcional.md §F.4.6`): el día que un centro de 400 personas activa el emparejamiento se encolan hasta 400 avisos a medida que la gente entra. **No es una ráfaga sospechosa, es el comportamiento esperado**, y por eso la alerta de `§F.8` se define por proveedor recién activado y no por volumen absoluto.

---

## F.6 Límites de tasa

**Tres *buckets* nuevos, y cinco *endpoints* deliberadamente sin *bucket* propio.** Amplía §6, `§C.6` y `§E.6`, con sus mismos criterios.

| Endpoint | Límite | Clave (*bucket*) |
|----------|--------|------------------|
| `GET /auth/oauth/oidc/callback` | **20 / min** | IP — `oidc_callback_ip` |
| `POST /identity-providers` y `POST .../discovery-refreshes` | **6 / min** | `(tenant_id)` — `sso_discovery_tenant` |
| `POST .../secrets` | **6 / min** | `(tenant_id)` — `sso_secret_tenant` |
| Los otros cinco de administración | **ninguno propio** | Ver abajo |

- **Toda clave incluye el `tenant_id`** (`ADR-033 §9`), sin cambios.
- **`sso_discovery_tenant` va por tenant y no por sesión ni por IP, y es la decisión que hay que argumentar.** Lo que se defiende aquí **no es la cuenta ni el servidor: son terceros**. Un administrador con sesión legítima que repita la validación en bucle convierte nuestra API en un generador de tráfico contra el emisor que él elija. Por sesión, dos pestañas duplican el límite; por IP, un centro entero detrás de una salida NAT comparte el de todos. **Por tenant es la unidad que corresponde al daño**: es el centro quien responde de lo que pide su administrador. Es la primera vez que este módulo pone un *bucket* con esa clave, y por eso se escribe.
- **`sso_secret_tenant` es un límite antiabuso, no antiadivinanza**: cargar credenciales en bucle no adivina nada, pero llena la tabla y el registro de auditoría. Seis por minuto es holgadísimo para una operación que se hace dos veces al año.
- **`oidc_callback_ip` copia el valor de `oauth_callback_ip` de `§E.6`** (20/min), a propósito y no por inventar uno: es el análogo exacto, y dos límites distintos para dos *callbacks* iguales sería una inconsistencia que alguien tendría que explicar.

**Por qué los otros cinco de administración no llevan *bucket* propio**, que es lo que hay que argumentar y no dar por hecho:

1. **Exigen sesión completa y un permiso concedido solo a `administrador_centro`** (`permisos.md §F.7`), así que no amplían ninguna superficie anónima. Es literalmente el criterio de `api.md §B.1` para los de 1.2b y el de `§E.6` para los dos de `/auth/identities`.
2. **Ninguno hace una petición saliente ni verifica una credencial.** Los dos que sí hacen una petición saliente **sí** llevan *bucket*, y ese es el criterio que separa a unos de otros. **Un límite que no cierra ningún hueco y crea una inconsistencia no se pone** (`§E.6`, mismo argumento).

**No hay límite por `(tenant_id, email)`** en ninguno de los tres, por el mismo motivo de `§E.6`: en el arranque del flujo no hay correo todavía, y en el *callback* lo pone el IdP, no el cliente. El límite por sujeto en el camino institucional lo aporta el bloqueo de cuenta, que sí se aplica (`RN-AUTH-111`).

- **`429` siempre con `Retry-After`** (`ADR-038 §6.5`).
- **El limitador sigue sin degradar a «sin límite»**: si su almacén no responde, `503` (§3).
- **El punto ciego de §6 —un centro entero detrás de una IP de salida— no empeora**: un login institucional sustituye a uno local, no se suma. Sigue pendiente de medir con `REQ-SEED` (1.15b), sin cambios en esa recomendación.

---

## F.7 Caché

**Ninguna caché de framework nueva**, y una decisión de diseño que hace innecesaria la que sería obvia.

**Los *endpoints* del emisor no se cachean: se guardan en la fila** (`datos.md §F.2`). Es deliberado, y la alternativa —descubrir en cada login y cachear el resultado en Redis— se descarta por tres motivos: haría depender cada login de una petición saliente, metería un modo de fallo nuevo entre el usuario y su sesión, y añadiría una invalidación que nadie recuerda al depurar. **Con los *endpoints* en la fila, un login no habla con nadie salvo con el propio IdP.**

**Y la advertencia de `§E.7` sigue vigente y se refuerza**: **el flujo no debe verificar la firma del `id_token` contra el JWKS del emisor**. `funcional.md §F.3.2` tiene el argumento entero, con el respaldo de OpenID Connect Core `§3.1.3.7`. Tomar ese camino obligaría a descargar, cachear e invalidar el juego de claves de **cada emisor de cada tenant**, con su propio modo de fallo, para la misma garantía. **Si la librería ofrece los dos caminos, se usa el que no verifica firmas**, y queda escrito aquí —como en 1.4— para que no se elija el otro por parecer más seguro.

`GET /auth/identity-providers` **sí consulta base de datos ahora**, a diferencia de 1.4, donde leía configuración de proceso. Es una consulta por `(tenant_id, is_enabled)` con índice, en cada carga de la pantalla de login. **No se cachea**: el resultado tiene que cambiar en el acto cuando un administrador activa o desactiva un proveedor, y una caché con invalidación por evento aquí es más código y más modos de fallo que la consulta que evita.

---

## F.8 Métricas y alertas

| Métrica | Alerta |
|---------|--------|
| `auth.oidc.callback.outcome` por código de resultado y por proveedor | **`error_proveedor` por encima del 5 % en 15 minutos para un proveedor ⇒ aviso.** Es la señal de credencial caducada, `client_id` incorrecto o emisor caído, y sin ella el fallo es silencioso: cada usuario lo ve, nadie lo agrega |
| `auth.oidc.callback.outcome{estado_no_valido}` | Pico sostenido: problema de cookies en algún navegador, o alguien probando el *callback* a mano |
| **`auth.oidc.callback.outcome{dominio_no_permitido}`** | **Volumen alto en un proveedor recién configurado ⇒ aviso al administrador del centro.** Casi siempre significa que declaró mal el dominio, y el síntoma que ve él es «no entra nadie» |
| **`auth.oidc.idtoken.invalid` por motivo** (`iss`, `aud`, `exp`, `iat`, `nonce`) | **Cualquier cosa que no sea cero merece mirarse.** Un `id_token` que no valida no es un error de usuario: es configuración incorrecta o alguien probando. **`nonce` distinto de cero es lo más grave de esta tabla**: es el síntoma de una reproducción |
| **`auth.oidc.callback.duration` por proveedor** | **Percentil 95 por encima del tiempo de espera configurado ⇒ aviso.** Es la señal del modo de fallo compartido de `§F.3`: un IdP lento consumiendo trabajadores que sirven a todos los centros |
| **`auth.sso.provider.enabled_without_secret`** | **Cualquier valor distinto de cero ⇒ aviso.** Un proveedor activo sin credencial vigente es un centro que cree tener SSO y no lo tiene (`§F.1`) |
| **`auth.sso.secret.expiring`** por proveedor | **Cualquier valor distinto de cero ⇒ aviso**, con la antelación de `AUTH_SSO_SECRET_EXPIRY_WARNING_DAYS`. Es lo que evita la caída total sin aviso de `funcional.md §F.3.5` |
| **`auth.sso.discovery.refresh_failed`** por proveedor | **Tres días seguidos ⇒ aviso.** Un fallo aislado es ruido; tres días es un emisor que cambió algo |
| **`auth.sso.discovery.blocked`** por código de guarda | **Cualquier `destino_no_publico` ⇒ aviso de seguridad, no operativo.** Es un administrador de centro apuntando nuestro servidor a la red interna: puede ser un error de copiar y pegar, y puede no serlo. **La guarda ya lo impidió**; el aviso existe para que alguien lo mire (`RN-AUTH-113`) |
| `auth.identity.matched` por proveedor | **Ráfaga en un proveedor que lleva activo más de una semana ⇒ aviso.** No se define por volumen absoluto: la primera semana de cada centro es legítimamente una ráfaga (`§F.5`) |
| `login_attempts` con `outcome = 'federado_sin_vinculo'` y `method = 'sso'` | Volumen alto desde pocas IP: alguien probando qué correos tienen cuenta. **No es un oráculo** (`funcional.md §F.4.5`), pero sigue siendo actividad que merece mirarse |

---

## F.9 Problemas conocidos y diagnóstico

| Síntoma | Causa probable | Comprobación |
|---------|----------------|--------------|
| Todo *callback* responde `estado_no_valido` | La cookie de sesión no llega en la navegación de vuelta | `SESSION_SAME_SITE` debe ser `lax`, **nunca `strict`** (`RN-AUTH-27`, `§E.9`). Sigue siendo el fallo más probable de un flujo federado |
| El IdP responde `redirect_uri_mismatch` | La URI registrada por el centro no coincide | La pantalla de administración muestra la URI exacta (`api.md §F.2`). Se compara **carácter a carácter**: esquema, puerto y barra final incluidos |
| El IdP responde `invalid_client` | `client_id` incorrecto, o **credencial caducada o revocada en el IdP** | `secret_status` en la pantalla, y `expires_at` de la credencial vigente. **Es la causa que más va a aparecer a partir del segundo año** |
| Todo falla con `error_proveedor` y `auth.oidc.idtoken.invalid{aud}` sube | El `client_id` catalogado no es el del cliente que emite el *token* — típico tras rehacer el registro en el IdP | El `client_id` del catálogo contra el del IdP |
| `auth.oidc.idtoken.invalid{nonce}` distinto de cero | **Incidencia de severidad alta.** O hay un problema de sesión, o alguien está reproduciendo un `id_token` | `RN-AUTH-104`, `CA-AUTH-276`. Se investiga, no se sube el umbral |
| Nadie entra y `dominio_no_permitido` sube | `allowed_email_domains` mal declarado, o el emisor manda el correo en otro *claim* | El dominio declarado contra el del correo real. Y `email_claim`: Entra ID federado con AD suele mandar `upn`, no `email` (`funcional.md §F.5.1`) |
| **Nadie entra y `sin_cuenta` sube en un centro con Entra ID** | **El `id_token` de Entra ID no trae `email`.** Es el caso de interoperabilidad más probable del paso: sin el *claim* opcional configurado o sin dirección en el directorio, `email` no viene | La salida es `claims_source = 'userinfo'` o `email_claim = 'upn'`, según lo que ese inquilino publique (`funcional.md §F.3.2`) |
| Un centro se queda sin SSO de un día para otro, sin haber tocado nada | **Credencial caducada.** Es el modo de fallo que la ventana de rotación y el aviso de 30 días existen para evitar | `auth.sso.secret.expiring` de las semanas anteriores. **Si el aviso salió y nadie lo atendió, el problema es del procedimiento, no del diseño** |
| La aplicación no arranca tras desplegar 1.4b | `AUTH_SSO_ALLOW_INSECURE_DISCOVERY=true` fuera de `local`/`testing` (guarda 1) | El mensaje de la guarda lo dice. **Salida inmediata: quitar la variable y reiniciar.** Es el mismo modo de fallo que el issue [#140](https://github.com/pirexia/plataforma-educativa/issues/140) y por eso el valor por defecto es el seguro (`CA-AUTH-310`) |
| Un administrador ve `destino_no_publico` al validar una URL correcta | El emisor está detrás de una resolución interna, o hay un DNS partido | **La guarda es correcta y no se relaja.** Un IdP institucional accesible solo desde la red interna del servidor no es un IdP que el navegador de un docente pueda alcanzar |
| **Un usuario entra por SSO y no le piden el segundo factor** | **Incidencia de severidad crítica.** Es una evasión del segundo factor | `RN-AUTH-111`, `CA-AUTH-299`. Se detiene el trabajo en curso y se resuelve de inmediato (`CLAUDE.md §5`) |
| **Aparece una `Person` o un `User` creado por un login SSO** | **Incidencia crítica.** `RN-AUTH-108`: ese camino **no existe** en 1.4b | El código no debe tenerlo. `CA-AUTH-287` es el test que lo cubre |
| **Dos personas distintas entran en la misma cuenta desde dos IdP del mismo centro** | **Incidencia crítica.** Es el defecto de `ADR-043 §3.6` sin corregir | `CA-AUTH-294`. Si aparece, la clave de `user_identities` no se re-tecleó bien |
| El SSO funciona y el botón no aparece | El proveedor está catalogado y **no activo**, o `GET /auth/identity-providers` se está cacheando en algún intermediario | `is_enabled` en el catálogo; y `Cache-Control` de la respuesta (`§F.7`) |
| **En `local`, dar de alta un proveedor cuya `discovery_url` apunta al propio servidor de desarrollo se queda en `sin_respuesta` desde el navegador** | `php artisan serve` (fase `dev` del `Containerfile`) atiende **una petición a la vez**: la petición entrante del alta ocupa el único hilo, y la petición saliente de la validación —al mismo servidor— no puede atenderse hasta que la primera termine. Interbloqueo, resuelto solo por el tiempo de espera | No es un defecto del validador: la suite Pest usa la misma URL y pasa siempre (el proceso de test no ocupa el hilo del servidor HTTP), y en producción (`frankenphp php-server`, concurrente) no se da. Issue [#146](https://github.com/pirexia/plataforma-educativa/issues/146), severidad baja, sin resolver a propósito |

---

## F.10 Desarrollo sin un IdP comercial: el emisor simulado

**A diferencia de 1.4, este paso sí se puede recorrer entero en el entorno de desarrollo**, y es la mejor noticia operativa que trae.

### F.10.1 Por qué aquí sí, y en 1.4 no

`operacion.md §E.10.1` explicó por qué 1.4 no podía probarse de verdad en WSL2: **Google** exige que la `redirect_uri` sea `https` sobre un dominio público registrable, y `ADR-030` sirve `{slug}.{TENANCY_BASE_DOMAIN}` sobre HTTP. **La restricción la ponía el emisor, no nosotros.**

Aquí el emisor **lo elige el centro**, y en desarrollo lo elegimos nosotros. Un emisor OIDC conforme servido por la propia API permite recorrer el flujo **real**: descubrimiento con sus cinco guardas, `state`, PKCE `S256`, `nonce`, canje de código contra un `token_endpoint`, `id_token` con sus cinco validaciones, restricción por dominio, emparejamiento, `MfaPolicy` y creación de sesión. **Lo único simulado es quién firma el documento y de dónde salen los *claims*.**

### F.10.2 Qué se entrega

Un emisor OIDC mínimo servido por la propia API, **registrado solo en `local`/`testing`**:

1. `/.well-known/openid-configuration` con un documento válido: `issuer`, `authorization_endpoint`, `token_endpoint`, `response_types_supported` con `code` y `code_challenge_methods_supported` con `S256`.
2. Una pantalla de autorización mínima con `sub`, `email`, casilla `email_verified` y **campo `hd`**, para poder probar `CA-AUTH-284` de verdad.
3. Un `token_endpoint` que valida el `code_verifier` y devuelve un `id_token` con el `nonce` recibido.
4. **El resto del flujo es el de verdad.** Es también lo que usan los tests: `RN-AUTH-104` se prueba **negativamente** —`nonce` cambiado, `aud` de otro cliente, `exp` vencido, `iss` distinto— sin depender de ningún IdP real.

**Se descarta añadir un contenedor de IdP real (Keycloak) al entorno de desarrollo**, y conviene decir por qué porque es la alternativa obvia: `ADR-030` fija un perfil reducido en WSL2, y un servicio con su propia base de datos y su propio ciclo de actualizaciones es exactamente lo que ese perfil evita. El emisor simulado **cuesta cero contenedores** y ejercita el mismo código nuestro. Queda anotado como la vía a retomar el día que haya que probar interoperabilidad de verdad contra un IdP completo.

### F.10.3 Las dos barreras que impiden que llegue a producción

1. **Guarda de arranque**: `AUTH_SSO_ALLOW_INSECURE_DISCOVERY=true` fuera de `local`/`testing` **aborta la aplicación** (`§F.2.1`).
2. **La ruta del emisor simulado no se registra** fuera de esos entornos, con test que lo comprueba con `APP_ENV=production`.

**Dos y no una**, por el mismo motivo que en `§E.10.3`.

### F.10.4 Lo que queda sin verificar, y hay que decirlo al cerrar

**La lista es mucho más corta que la de 1.4**, y es lo importante. Lo que **sí** se verificará en navegador real con el emisor simulado: el flujo entero, las cinco guardas de descubrimiento, las cinco validaciones del `id_token`, la restricción por dominio con y sin `hd`, el emparejamiento, el segundo factor y la rotación de credenciales.

Queda pendiente de un entorno con dominio público (`0.10b`) y de un IdP comercial:

- Que el documento de descubrimiento de **Entra ID** valide nuestras cinco guardas tal cual, y **si el `id_token` trae `email`** o hay que ir a `userinfo`/`upn` (`§F.9`). **Es lo primero que hay que comprobar**: determina si un centro con Entra ID funciona sin tocar nada.
- Que el *claim* `hd` de **Google Workspace** llegue como se espera para una cuenta de Workspace y **no** llegue para una de consumo con dirección del mismo dominio, que es lo que `CA-AUTH-284` afirma.
- Que la caducidad de una credencial de Entra ID se comporte como se supone al canjear.
- Que la cookie de sesión viaje en la navegación de vuelta con `Secure` y TLS reales.

**Está escrito aquí para que se convierta en tarea y no en un olvido**, igual que `§E.10.4`.

---

## F.11 Impacto en copias de seguridad y restauración

**Sí lo hay, y es nuevo.** `§E.11` pudo afirmar que 1.4 no lo tenía porque `user_identities` no contiene material cifrado. **Aquí no se puede afirmar lo mismo.**

- `identity_providers` es una tabla de tenant ordinaria y entra en la copia como el resto. **No contiene datos personales ni material cifrado.**
- **`identity_provider_secrets` contiene material cifrado con `APP_KEY`**, de todos los tenants. Es la segunda tabla del producto en esa situación, después de `user_mfa_factors` (`§C.10`, `§C.11.1`).

Consecuencias, encadenadas y concretas:

1. **Restaurar una copia sin la `APP_KEY` correspondiente deja sin SSO institucional a todos los centros restaurados.** El login local funciona, el de Google funciona, y el institucional no — hasta que cada centro vuelva a cargar su credencial. **Es recuperable**, y por eso no bloquea una restauración; pero hay que saberlo **antes** de la restauración, no durante.
2. **`APP_KEY` tiene que estar en el procedimiento de recuperación al mismo nivel que las credenciales de base de datos.** Ya lo estaba desde 1.3 por los factores TOTP (`§C.11.1`); este paso **añade un segundo motivo** y sube la consecuencia de «algunos usuarios no verifican su segundo factor» a «ningún centro entra por su IdP».
3. **Una copia de la base de datos es ahora, además, un almacén de credenciales cifradas de terceros.** `ADR-043 §8.2` lo anticipó como la implicación aceptada de la decisión. Lo que hay que escribir en el procedimiento: **una copia y su `APP_KEY` no se custodian juntas**.

**Lo que no cambia**: `AUTH_GOOGLE_CLIENT_SECRET` sigue viviendo en el gestor de secretos y no en la copia (`ADR-037 §7.2`, `§E.11`).

---

## F.12 Despliegue

### F.12.1 El día del despliegue no cambia nada para nadie

Y es cierto por una razón concreta, no por casualidad: **ningún tenant tiene proveedores catalogados** y **ninguna variable nueva tiene un valor por defecto que dispare una guarda de arranque** (`§F.2.1`).

Desplegado sin tocar una sola variable, el sistema queda exactamente como estaba: mismo login, mismos botones, mismo comportamiento, con dos tablas vacías, una columna nullable nueva y un valor más en un enumerado (`datos.md §F.7`). **El SSO de un centro aparece el día que su administrador lo configura y lo activa, no el día que se despliega el código.**

**Esto hay que poder afirmarlo con un test, no con un párrafo** — es la lección del issue [#140](https://github.com/pirexia/plataforma-educativa/issues/140), y la cubre `CA-AUTH-310`: arranque con `APP_ENV=production` **sin fijar ninguna variable nueva**, que debe completar sin excepción.

### F.12.2 El trabajo manual de 1.4 **desaparece**, y aparece otro en otro sitio

`§E.12.2` dejó anotado el coste operativo de 1.4: registrar a mano `https://{slug}.{base}/api/v1/auth/oauth/google/callback` en **nuestra** consola de Google al dar de alta cada centro, con un **tope de URIs por cliente OAuth** señalado como límite duro de número de centros.

**En SSO institucional ese coste no existe** (`ADR-043 §5.1`): cada centro registra la URI **en su propio IdP**. No hay acumulación en un solo sitio, no hay tope común y **no hay disparador de migración a la opción B**. Conviene decirlo porque cambia el cálculo, y porque el tope de `§E.12.2` **sigue vigente para el botón global de Google de 1.4** y no debe darse por resuelto: son dos integraciones distintas con dos costes distintos.

Lo que sí aparece, y va a `SYSADMIN.md` y al manual de administración de centro:

1. **El alta de un proveedor es trabajo del centro, en dos sistemas.** Registra nuestra `redirect_uri` en su IdP y pega aquí su URL de descubrimiento y su `client_id`. **Si registra mal la URI, el fallo aparece cuando la primera persona pulsa el botón**, no al configurar: no hay forma de comprobarlo desde aquí sin un usuario real (`funcional.md §F.4.1`).
2. **La credencial caduca, y renovarla es trabajo recurrente del centro.** El aviso a 30 días existe para eso. **Va en el procedimiento de alta, no solo en la pantalla**: un aviso que llega a una dirección que nadie lee es un aviso que no existe.
3. **Retirar una credencial aquí no la revoca en el IdP.** Hay que hacer las dos cosas (`api.md §F.4`).

### F.12.3 Orden y reversión

1. Migraciones (`datos.md §F.7`), antes del código. Son *expand*, con la retirada de dos índices argumentada.
2. Despliegue de la aplicación **sin tocar ninguna variable nueva**. El sistema arranca y queda idéntico al anterior. **No hay que fijar nada para que este paso sea seguro.**
3. A partir de ahí, **cada centro configura el suyo cuando quiere**. No hay ninguna acción del operador de plataforma.

**La reversión tiene tres escalones**, según lo que se quiera deshacer:

- **Apagar el SSO de un centro**: su administrador desactiva el proveedor. Un `PATCH`, sin reinicio, sin tocar base de datos. **Nadie se queda fuera** porque nadie depende del IdP para entrar (`RN-AUTH-96`), y **los vínculos siguen viéndose y pudiendo retirarse** (`§F.1`).
- **Apagar el SSO de todos los centros**: no hay interruptor global, y **es correcto que no lo haya**: un interruptor que un operador puede pulsar y que deja a 400 centros sin su vía de acceso habitual es más peligroso que útil. Si hiciera falta, la maniobra es desactivar los proveedores por consola con la conexión de plataforma, y queda registrada en `admin_action_logs`.
- **Revertir la aplicación**: la migración del `CHECK` de `login_attempts` es de un solo sentido si ya hay filas con `method = 'sso'` (`datos.md §F.7`), como todas las anteriores del mismo tipo, y **revertir la aplicación no exige revertirla**. **Lo que sí hay que saber**: revertir las migraciones **con vínculos institucionales vivos no es seguro y falla ruidosamente**, que es el comportamiento correcto (`datos.md §F.7`).

### F.12.4 Lo que hay que verificar en el entorno real y no se puede verificar en WSL2

`§F.10.4`, entero. **Es más corta que la de 1.4**, y esa es la diferencia con el paso anterior.

---

# Parte G · Paso 1.4c · SSO institucional (SAML 2.0) — Operación (`REQ-AUTH-004`)

> **Estructura**: §1-§12 son 1.2, `§B.*` es 1.2b, `§C.*` es 1.3, `§D.*` es 1.3b, `§E.*` es 1.4 y `§F.*` es 1.4b, los seis cerrados. Esta **Parte G** es el paso **1.4c**, **especificada y pendiente de aprobación**.
>
> Escrita sobre `ADR-043 §10`. **La decisión 8 —sin intermediario externo, dependencia SAML directa— tiene consecuencias de operación permanentes**, y están en `§G.3`.

---

## G.1 Comportamiento con el módulo activo o inactivo

**`REQ-AUTH` sigue sin ser desactivable** (`RN-AUTH-35`), y **ninguna ruta de este paso lleva `module-enabled`** (`CA-AUTH-350`), **tampoco el ACS**. Se dice explícitamente porque el ACS es la primera ruta del módulo que podría parecer «de integración» y no «de acceso»: un centro que no puede recibir la aserción de su IdP porque una fila de `module_subscriptions` está mal es **el mismo fallo total con otra ropa**.

**El catálogo sin proveedores SAML es el estado normal**, y el día del despliegue **no cambia nada para nadie** (`§G.12.1`). El ACS existe desde el primer minuto y, mientras ningún `public_id` resuelva a un proveedor SAML activo, responde `302` con `estado_no_valido` a cualquier cosa que llegue — **nunca `404`** (`funcional.md §G.10.2`).

**Un centro sin SAML no nota este paso.** Un centro con SAML y sin certificado vigente **tampoco pierde el acceso**: `RN-AUTH-96` garantiza que la contraseña local nunca deja de ser puerta válida, y esa propiedad es la que convierte la caducidad de un certificado en una degradación en vez de una caída (`ADR-043 §10.6`).

---

## G.2 Variables de entorno

### G.2.1 Propias del paso

| Variable | Uso | Valor por defecto | Valor en desarrollo |
|----------|-----|-------------------|---------------------|
| `AUTH_SAML_SP_SIGNING_KEY_PATH` | **Ruta al fichero con la clave privada de firma del SP** (`funcional.md §G.3.7`). Vacía = no se puede activar `sign_authn_requests` en ningún proveedor | **vacía** | vacía |
| `AUTH_SAML_SP_SIGNING_CERT_PATH` | Ruta al certificado público correspondiente. **Es el que se publica en nuestros metadatos de SP** cuando la firma está activa | **vacía** | vacía |
| `AUTH_SAML_METADATA_TIMEOUT_SECONDS` | Tiempo de espera de la descarga de metadatos del IdP (guarda 4 de `§F.4.2`, reutilizada) | `5` | `5` |
| `AUTH_SAML_METADATA_MAX_BYTES` | Tope de tamaño del documento de metadatos | `524288` | `524288` |
| `AUTH_SAML_METADATA_MAX_REDIRECTS` | Guarda 3, reutilizada | `3` | `3` |
| `AUTH_SAML_METADATA_MAX_DEPTH` | **Tope de profundidad de anidamiento del XML.** Guarda contra la bomba de expansión (`api.md §G.4`) | `20` | `20` |
| `AUTH_SAML_METADATA_REFRESH_DAYS` | Antigüedad a partir de la cual la tarea programada refresca un proveedor SAML de origen URL (`§G.4`) | `7` | `7` |
| `AUTH_SAML_AUTH_REQUEST_RETENTION_HOURS` | Antigüedad a partir de la cual se purgan las filas de `saml_auth_requests` consumidas o caducadas (`§G.4`) | `24` | `24` |
| `AUTH_SAML_MIN_CERTIFICATE_KEY_BITS` | Tamaño mínimo de clave aceptado al cargar un certificado del IdP (`RN-AUTH-126`) | `2048` | `2048` |
| `AUTH_SAML_ALLOW_INSECURE_METADATA` | **Permite `http` en la URL de metadatos y en el `SingleSignOnService`.** Existe **solo** para el IdP simulado de `§G.10`. **Guarda de arranque: aborta la aplicación si es `true` y `APP_ENV` no es `local` ni `testing`** | **`false`** | **`true`, fijado explícitamente** |
| `AUTH_RATE_LIMIT_SAML_ACS_PER_IP` | Entregas al ACS por IP y minuto (`saml_acs_ip`) | `20` | `20` |
| `AUTH_RATE_LIMIT_SSO_METADATA_PER_TENANT` | Validaciones de metadatos por tenant y minuto (`sso_metadata_tenant`) | `6` | `6` |
| `AUTH_RATE_LIMIT_SSO_CERTIFICATE_PER_TENANT` | Cargas de certificado por tenant y minuto (`sso_certificate_tenant`) | `6` | `6` |

**Se reutilizan sin cambios y no se duplican**: `AUTH_SSO_CLOCK_SKEW_SECONDS` (tolerancia de reloj, ahora también para `NotBefore`/`NotOnOrAfter`, `RN-AUTH-119`) y `AUTH_SSO_SECRET_EXPIRY_WARNING_DAYS` (antelación del aviso, ahora también para certificados del IdP, `CA-AUTH-335`). **Reutilizarlas es deliberado**: dos variables distintas para la misma antelación de aviso serían dos números que un operador tendría que mantener sincronizados sin ningún motivo.

### G.2.2 Guardas de arranque, en todos los entornos

Mismo patrón que `SESSION_DOMAIN` (§2.2), las tres de `§E.2.1` y las dos de `§F.2.1`:

1. **`AUTH_SAML_ALLOW_INSECURE_METADATA=true` con `APP_ENV` distinto de `local`/`testing` ⇒ la aplicación no arranca.** Con `http` admitido, el documento que declara **con qué certificado se verifica quién entra en un centro** viaja en claro y lo puede reescribir cualquiera en el camino. Es la hermana exacta de la guarda 1 de `§F.2.1`, y **aquí es peor**, porque en OIDC lo que se reescribiría son URLs y aquí es material criptográfico de confianza.
2. **`AUTH_SAML_SP_SIGNING_KEY_PATH` informada y el fichero no legible ⇒ la aplicación no arranca.** Una clave configurada y ausente es peor que ninguna clave: los proveedores con `sign_authn_requests = true` fallarían **en el camino del acceso**, uno a uno, sin que nada lo agregue. **Fallar al arrancar es fallar donde alguien lo ve.**
3. **La ruta del IdP SAML simulado no se registra** fuera de `local`/`testing`, con test que lo comprueba con `APP_ENV=production` (`§G.10.3`). **Dos barreras, no una.**

**Y la propiedad que hay que poder afirmar y no solo escribir** (lección del issue [#140](https://github.com/pirexia/plataforma-educativa/issues/140)): **ninguna variable de este paso tiene un valor por defecto que dispare una guarda de arranque.** `AUTH_SAML_ALLOW_INSECURE_METADATA` vale `false`; las dos rutas de clave valen **vacío**, que desactiva la guarda 2 entera. **Un despliegue de 1.4c sin tocar una sola variable arranca** (`CA-AUTH-365`).

### G.2.3 `APP_KEY` **no** gana responsabilidad, y es la buena noticia del paso

`§F.2.2` tuvo que escribir que `APP_KEY` ganaba responsabilidad por segunda vez, al cifrar el `client_secret` de cada centro. **Aquí no ocurre, y es consecuencia directa de dos hechos, no de un cuidado especial:**

- **SAML no tiene secreto de cliente.** Usa certificados, y el del IdP es **material público** que no se cifra en reposo (`RN-AUTH-127`). `ADR-043 §10.10` lo anotó como uno de los dos temores que *«se cierran con hallazgos»*: `§8.2` **se reduce en vez de resolverse**.
- **La clave privada del SP es de plataforma y no cambia con ninguna alta de tenant**, así que cabe en `ADR-037 §7` (`EnvironmentFile=` y fichero montado) y **no repite el camino a base de datos**. Es la recomendación de `OPEN-AUTH-44`, **bloqueante**: si se decidiera la salida B —cifrada con `APP_KEY`—, `APP_KEY` ganaría responsabilidad **por tercera vez** y perderla dejaría además sin firmar todas las peticiones de todos los centros.

**Custodia del fichero de clave, si se aprueba la salida A** (`SYSADMIN.md` y `RUNBOOK.md`):

- Montado con la etiqueta `:Z` (`CLAUDE.md §9`), permisos `0400` y propiedad del usuario del servicio.
- **Fuera del repositorio, sin excepción** (`CLAUDE.md §4`: claves y certificados nunca se suben).
- **En el procedimiento de recuperación**: un restablecimiento sin este fichero deja fuera de servicio la firma de peticiones de **todos** los centros que la tengan activa.
- **Sin rotación automática** (`funcional.md §G.3.7`). Rotarla es reemplazar el fichero y **pedir a cada centro que vuelva a cargar nuestros metadatos**. Se documenta y no se automatiza: automatizar una rotación que nadie ha ejecutado nunca es código sin ejercitar en el camino del acceso.

---

## G.3 Servicios externos y degradación

**Un servicio externo nuevo por centro —su IdP— y ningún servicio de plataforma nuevo.** `ADR-043 §10.9` decisión 8: **sin intermediario externo**, dependencia SAML directa.

| Servicio | Si no responde | Impacto |
|----------|----------------|---------|
| **IdP SAML de un centro** (durante el acceso) | La persona no vuelve, o vuelve con un `Status` de fallo | **Solo ese centro**, y **solo su vía de SSO**: la contraseña local sigue funcionando (`RN-AUTH-96`) |
| **IdP SAML de un centro** (durante el refresco de metadatos) | Se estampa `metadata_failed_at` y **se conserva todo lo anterior** | **Ninguno inmediato.** El SSO sigue funcionando con lo catalogado (`CA-AUTH-326`) |

**Y una diferencia estructural con 1.4b que conviene decir en voz alta, porque mejora**: en OIDC, **cada login hace una petición saliente** al `token_endpoint` del emisor, y `§F.8` tuvo que poner una alerta sobre `auth.oidc.callback.duration` porque *«un IdP lento consume trabajadores que sirven a todos los centros»*. **En el perfil Web Browser SSO de SAML no hay canal trasero**: la aserción llega firmada en el `POST` del navegador. **El ACS no habla con nadie.** Un IdP SAML lento afecta al navegador de su usuario, no a nuestros trabajadores. **Es el modo de fallo compartido de `§F.3` que este paso no hereda.**

### G.3.1 La dependencia de `php-saml`: la consecuencia de operación permanente

**Es la parte de este paso que no termina cuando termina el paso**, y `ADR-043 §2.3` y `§10.3` la exigieron por escrito. Va aquí, en operación, y **a `RUNBOOK.md`**, porque no es una tarea de implementación:

1. **Suscripción a los avisos de seguridad de `onelogin/php-saml` *y* de `robrichards/xmlseclibs`.** Las dos, no una: `xmlseclibs` es el núcleo de XML-DSig y acumula cuatro avisos históricos, uno de ellos un *«critical signature bypass»*.
2. **Compromiso de parcheo rápido.** El modo de fallo característico de esta familia es *«la firma no se valida y el sistema cree que sí»* (`ADR-043 §2.3`), sobre el componente que decide quién entra en un sistema con datos de menores.
3. **`xmlseclibs 4.0.0` (2026-08-22) queda en vigilancia.** `php-saml` sigue anclado en `^3.1.5`. El día que mueva esa restricción, **es una actualización a revisar, no a aplicar en automático**.
4. **Factor autobús 1** (`ADR-043 §10.3`): 15 de 16 *commits* del último año son de un solo mantenedor. Se acepta a sabiendas; la mitigación es que MIT + 6.779 líneas hacen el *fork* una salida real. **Si el mantenedor desaparece, el disparador de `ADR-043 §7.2` —volver a evaluar un intermediario externo— se reabre con su propio ADR.**

**El escaneo de dependencias de cada PR (`CLAUDE.md §8`) sí funciona sobre esta biblioteca**, y esa fue la razón de elegirla: **publica avisos**. `ADR-043 §10.1` documentó que sobre `litesaml/lightsaml` ese control **no funciona** —un salto de autenticación completo pasó por todos los escáneres en verde—, y esa es la diferencia entre tener un control y creer que se tiene.

---

## G.4 Colas y trabajos (`INV-012`)

**Tres tareas programadas nuevas y ningún trabajo en cola nuevo.**

| Tarea | Frecuencia | Qué hace |
|-------|------------|----------|
| **`auth:refresh-saml-metadata`** | **Diaria** | Refresca los metadatos de los proveedores SAML **de origen URL** con más de `AUTH_SAML_METADATA_REFRESH_DAYS` de antigüedad. **Añade certificados nuevos y no retira ninguno** (`RN-AUTH-125`, `CA-AUTH-325`). Si falla, conserva todo y estampa `metadata_failed_at`. Precedente directo: `auth:refresh-oidc-discovery` |
| **`auth:warn-expiring-idp-certificates`** | **Diaria** | Avisa de los certificados del IdP cuya `not_after` está a menos de `AUTH_SSO_SECRET_EXPIRY_WARNING_DAYS`, con avisos escalonados y marca visible en la pantalla del proveedor (`CA-AUTH-335`). Hermana de `auth:warn-expiring-client-secrets` |
| **`auth:purge-saml-correlation`** | **Diaria** | Purga `saml_auth_requests` consumidas o caducadas con más de `AUTH_SAML_AUTH_REQUEST_RETENTION_HOURS`, y `saml_consumed_assertions` cuyo `not_on_or_after` ya pasó con margen |

**La purga es lo que 1.4b no necesitó**, y la diferencia es estructural: el `state` de OIDC vive en la sesión y muere con ella; **en SAML el estado equivalente vive en base de datos** porque el ACS llega sin cookie (`ADR-043 §2.1`). **Lo que se persiste hay que purgarlo.**

**Y se purga sin bloquear**, con el patrón de `2026_08_31_100100_add_purge_indexes_to_mfa_tables.php` e issues [#118](https://github.com/pirexia/plataforma-educativa/issues/118)/[#119](https://github.com/pirexia/plataforma-educativa/issues/119): **borrado por lotes acotados, con el índice que sirve exactamente a la consulta de purga** (`datos.md §G.4`), nunca un `DELETE` masivo en una transacción. Se dice porque es el error que este proyecto ya cometió una vez.

**Ningún trabajo en cola nuevo.** El aviso de emparejamiento (`SendIdentityMatchedEmail`) **ya existe desde 1.4b y se reutiliza tal cual** (`funcional.md §G.4.6`): el hecho es el mismo y el nombre visible del proveedor es igual de válido para un IdP SAML.

**La validación de metadatos es síncrona y no va a cola**, exactamente igual que el descubrimiento en 1.4b y por el mismo motivo: **el administrador está esperando y necesita el resultado para corregir**. `INV-012` habla de tareas pesadas, y descargar y analizar un XML con tope de tamaño y de tiempo de espera no lo es.

---

## G.5 Correos que emite el módulo

**Ninguno nuevo** (`funcional.md §G.4.6`).

**Y una consecuencia que se hereda literalmente de `§F.5`**: el día que un centro de 400 personas activa el emparejamiento con su IdP SAML se encolan hasta 400 avisos. La alerta sobre `auth.identity.matched` sigue definida **por proveedor recién activado**, no por volumen absoluto — la primera semana de cada centro es legítimamente una ráfaga.

**El aviso de vencimiento de certificado no es un correo al titular**: es un aviso operativo y una marca en la pantalla de administración, con el mismo criterio que el de credenciales de 1.4b. **Quien tiene que actuar es el administrador del centro, no las 400 personas.**

---

## G.6 Límites de tasa

**Tres *buckets* nuevos.** Amplía §6, `§C.6`, `§E.6` y `§F.6` con sus mismos criterios.

| Endpoint | Límite | Clave (*bucket*) |
|----------|--------|------------------|
| **`POST /auth/saml/{public_id}/acs`** | **20 / min** | IP — `saml_acs_ip` |
| `POST /identity-providers` con metadatos por URL, y `POST .../metadata-refreshes` | **6 / min** | `(tenant_id)` — `sso_metadata_tenant` |
| `POST .../certificates` | **6 / min** | `(tenant_id)` — `sso_certificate_tenant` |

- **Toda clave incluye el `tenant_id`** (`ADR-033 §9`), sin cambios.
- **`saml_acs_ip` copia el valor de `oidc_callback_ip` y de `oauth_callback_ip`** (20/min), a propósito y no por inventar uno: **es el análogo exacto**, y tres límites distintos para tres puntos de retorno iguales sería una inconsistencia que alguien tendría que explicar.
- **`sso_metadata_tenant` va por tenant, y no por sesión ni por IP**, por el mismo argumento que `sso_discovery_tenant` en `§F.6`, que aquí vale igual: **lo que se defiende no es la cuenta ni el servidor, son terceros**. Un administrador con sesión legítima que repita la validación en bucle convierte nuestra API en un generador de tráfico contra el IdP que él elija.
- **`sso_metadata_tenant` no se aplica cuando el origen es XML pegado**, y es una diferencia deliberada con 1.4b: **un XML pegado no genera tráfico saliente contra nadie**, así que aplicarle el *bucket* que existe para proteger a terceros sería un límite que no cierra ningún hueco (`§E.6`, mismo argumento).
- **`sso_certificate_tenant` es antiabuso, no antiadivinanza**: cargar certificados en bucle no adivina nada, pero llena la tabla y el registro de auditoría.

**Los cuatro *endpoints* nuevos restantes no llevan *bucket* propio** por el criterio ya establecido en `§F.6`: exigen sesión completa y un permiso concedido solo a `administrador_centro`, y **ninguno hace una petición saliente**. `GET .../metadata` es una lectura que no toca la red.

**El ACS sí lo lleva, y es el que más importa**, porque es **anónimo y sin CSRF**. Sin él, la validación de firma XML —que es cara— sería un amplificador de denegación de servicio desde cualquier origen. **El límite se aplica antes de tocar el XML** (`funcional.md §G.4.3` punto 6).

- **`429` siempre con `Retry-After`** (`ADR-038 §6.5`). En el ACS, **el `429` es la única respuesta del *endpoint* que no es un `302`**, y se acepta: un navegador limitado por tasa no es un navegador al que haya que dar un código de resultado de producto.
- **El limitador sigue sin degradar a «sin límite»**: si su almacén no responde, `503` (§3).

---

## G.7 Caché

**Ninguna caché de framework nueva**, y la misma decisión de diseño que la hace innecesaria: **los datos del IdP se guardan en la fila, no se cachean** (`datos.md §G.3`, `§G.5`). Un acceso SAML **no habla con nadie**, ni siquiera con el IdP.

**Los certificados admisibles se consultan en cada aserción**, con el índice `(tenant_id, identity_provider_id) WHERE deleted_at IS NULL AND retired_at IS NULL`. **No se cachean**, y merece el argumento: son unas pocas filas por proveedor, la consulta es por índice, y **el resultado tiene que cambiar en el acto** cuando un administrador retira un certificado comprometido. Una caché con invalidación por evento aquí es más código y más modos de fallo que la consulta que evita — el mismo razonamiento con el que `§F.7` decidió no cachear `GET /auth/identity-providers`.

**Y una advertencia que hay que escribir aquí para que no se elija mal, en la línea de la que `§F.7` dejó sobre el JWKS**: **el envoltorio no debe descargar nada durante la validación de una aserción.** `php-saml` admite configuraciones que resuelven material por red; **no se usan**. Todo lo que hace falta para verificar una firma está en la fila y en `identity_provider_certificates`, puesto ahí por un administrador o por el refresco programado. **Si la biblioteca ofrece los dos caminos, se usa el que no sale a la red**, por la misma razón que en 1.4b: meter una petición saliente entre el usuario y su sesión es un modo de fallo nuevo para la misma garantía.

---

## G.8 Métricas y alertas

| Métrica | Alerta |
|---------|--------|
| `auth.saml.acs.outcome` por código de resultado y por proveedor | **`error_proveedor` por encima del 5 % en 15 minutos para un proveedor ⇒ aviso.** Es la señal de certificado caducado, `entityId` mal registrado o IdP reconfigurado, y sin ella el fallo es silencioso: cada usuario lo ve, nadie lo agrega |
| **`auth.saml.assertion.invalid` por motivo** (`firma`, `issuer`, `destination`, `audience`, `ventana`, `recipient`, `in_response_to`, `repetida`) | **Cualquier cosa que no sea cero merece mirarse, y `firma` es incidencia de seguridad, no ruido** (`funcional.md §G.4.5`). Una aserción cuya firma no valida no es un error de usuario: es configuración incorrecta **o alguien probando**. `repetida` distinto de cero es lo más grave de esta tabla: es el síntoma de un intento de reproducción |
| **`auth.saml.acs.outcome{estado_no_valido}`** | **Pico sostenido ⇒ aviso de seguridad.** Agrupa el `InResponseTo` ausente —es decir, **SSO iniciado por el IdP o *login CSRF* intentado**— con el consumido y el caducado. **Es la métrica que vigila la excepción de CSRF**, y por eso no se agrega con las demás |
| **`auth.saml.provider.enabled_without_certificate`** | **Cualquier valor distinto de cero ⇒ aviso.** Un proveedor activo sin certificado vigente es un centro que cree tener SSO y no lo tiene (`RN-AUTH-128`) |
| **`auth.saml.certificate.expiring`** por proveedor | **Cualquier valor distinto de cero ⇒ aviso**, con la antelación de `AUTH_SSO_SECRET_EXPIRY_WARNING_DAYS`. **Es lo que evita el modo de fallo que `ADR-043 §2.4` describe**: caída del SSO de un centro el día del vencimiento, con un mensaje que no apunta al certificado |
| **`auth.saml.metadata.refresh_failed`** por proveedor | **Tres días seguidos ⇒ aviso.** Un fallo aislado es ruido; tres días es un IdP que cambió algo |
| **`auth.saml.metadata.blocked`** por código de guarda | **Cualquier `destino_no_publico` ⇒ aviso de seguridad, no operativo.** Es un administrador de centro apuntando nuestro servidor a la red interna. **La guarda ya lo impidió**; el aviso existe para que alguien lo mire (`RN-AUTH-113`) |
| **`auth.saml.acs.first_failure`** por proveedor recién activado | **El primer acceso fallido de un proveedor recién activado ⇒ aviso al administrador del centro.** Es lo que compensa que el alta **no verifique que el IdP nos conoce** (`funcional.md §G.4.1`), cosa que no se puede comprobar sin un usuario real recorriendo el flujo |
| `auth.identity.matched` por proveedor | Sin cambios respecto de `§F.8`: ráfaga en un proveedor activo desde hace más de una semana ⇒ aviso |
| `login_attempts` con `outcome = 'federado_sin_vinculo'` y `method = 'sso'` | Sin cambios. **No es un oráculo**, pero sigue siendo actividad que merece mirarse |

**Lo que no se mide, y por qué**: **no hay métrica de duración del ACS por proveedor**, a diferencia de `auth.oidc.callback.duration`. No hace falta: **el ACS no hace ninguna petición saliente** (`§G.3`), así que no puede quedarse esperando a un tercero. Su duración es la de una validación de firma local, que es trabajo de CPU acotado. **Poner la métrica sería copiar una alerta de 1.4b sin su motivo.**

---

## G.9 Problemas conocidos y diagnóstico

| Síntoma | Causa probable | Comprobación |
|---------|----------------|--------------|
| **Todo acceso responde `estado_no_valido`** | El IdP está enviando aserciones **no solicitadas** (SSO iniciado por el IdP), típico si el centro configuró el enlace desde su portal | **No se soporta y no se va a soportar** (`RN-AUTH-120`, `ADR-043 §10.9` decisión 4). La salida es que el acceso empiece **siempre** en nuestra pantalla de login. **No es un fallo: es la precondición de seguridad de la excepción de CSRF** |
| Todo acceso responde `error_proveedor` y `auth.saml.assertion.invalid{firma}` sube | **Certificado rotado en el IdP y no cargado aquí** | Los certificados vigentes del proveedor. **Es la causa que más va a aparecer a partir del segundo año**, y es exactamente para lo que existe el aviso de vencimiento |
| `auth.saml.assertion.invalid{audience}` o `{destination}` sube | El centro registró en su IdP un `entityID` o una ACS URL que no son los nuestros | La pantalla muestra los valores exactos y el botón de descarga de metadatos (`api.md §G.3`). Se comparan **carácter a carácter**: esquema, puerto y barra final incluidos |
| **`auth.saml.assertion.invalid{firma}` distinto de cero sin rotación de por medio** | **Incidencia de severidad alta.** O el IdP cambió algo sin avisar, o alguien está probando firmas | `RN-AUTH-117`, `CA-AUTH-337`. **Se investiga, no se sube ningún umbral y no se relaja ningún indicador** |
| **`auth.saml.assertion.invalid{repetida}` distinto de cero** | **Incidencia de severidad alta.** Es un intento de reproducción de aserción | `RN-AUTH-122`, `CA-AUTH-344`. La protección funcionó; lo que hay que averiguar es de dónde vino |
| El IdP rechaza nuestro `AuthnRequest` con un error de firma | `sign_authn_requests` activo y la clave de plataforma no es la que el centro registró | El certificado público de nuestros metadatos contra el que tenga cargado el centro. **Rotar la clave obliga a que todos los centros recarguen los metadatos** (`§G.2.3`) |
| El IdP rechaza el `AuthnRequest` porque espera que vaya firmado | `sign_authn_requests` apagado y el IdP lo exige — típico de algunos despliegues de ADFS y Shibboleth | Activar el conmutador. **Si responde `409`, es que no hay clave de plataforma configurada** (`RN-AUTH-128`), y eso es una tarea del operador, no del centro |
| **Un centro con ADFS no puede integrarse en absoluto** | **Está cifrando la aserción** (`EncryptedAssertion`) | **No se soporta en 1.4c** (`OPEN-AUTH-46`). La salida es que el centro desactive el cifrado; el transporte ya es TLS y la aserción va firmada |
| **Un IdP conforme es rechazado y firma solo la aserción, no la respuesta** | **`wantMessagesSigned = true`**, restricción aceptada a conciencia (`funcional.md §G.3.5`) | **Es lo primero que hay que comprobar contra un IdP comercial** (`§G.10.4`). Si ocurre, es **un cambio de una línea de configuración con su propio test y su propia decisión**, no un rediseño |
| Nadie empareja y `sin_cuenta` sube | El `email_attribute` configurado no es el que ese IdP emite | El nombre exacto del atributo. En ADFS suele ser `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress`; en Shibboleth, `urn:oid:0.9.2342.19200300.100.1.3` |
| Nadie empareja, `sin_cuenta` sube y el IdP emite `NameID` opaco | El `NameIDFormat` es `persistent` y **no hay `email_attribute`** | **No debería haberse podido catalogar así**: el `CHECK` de `datos.md §G.3` lo impide. Si ocurre, es un defecto del `CHECK` |
| Un administrador ve `destino_no_publico` al validar una URL correcta | El IdP está detrás de una resolución interna | **La guarda es correcta y no se relaja.** Un IdP institucional accesible solo desde la red interna del servidor no es un IdP que el navegador de un docente pueda alcanzar |
| El acceso completa y la SPA aterriza sin sesión | La cookie fijada en la respuesta del ACS no viajó en el `302` | **Es el escenario que `§G.10.4` manda comprobar en navegador real.** Si ocurre, la reserva declarada es el vale opaco de un solo uso (`funcional.md §G.3.2`), **que no se implementa por adelantado** |
| **Un usuario entra por SAML y no le piden el segundo factor** | **Incidencia de severidad crítica.** Es una evasión del segundo factor | `RN-AUTH-129`, `CA-AUTH-354`. Se detiene el trabajo en curso y se resuelve de inmediato (`CLAUDE.md §5`) |
| **Aparece una `Person` o un `User` creado por un acceso SAML** | **Incidencia crítica.** Ese camino **no existe**, y además **la base de datos no lo permite** (`CHECK` de `provisioning_mode`) | `CA-AUTH-352`. Si aparece, alguien amplió el `CHECK` |
| **Una aserción de un centro es aceptada en otro** | **Incidencia crítica: fuga entre tenants** (`INV-001`) | `CA-AUTH-339`. Las tres barreras —ruta del ACS, `Destination`, `Audience`— tienen que rechazarla **cada una por separado** |
| El ACS responde `419` | La ruta quedó dentro del grupo de `/api/v1` en vez de en su grupo propio | `CA-AUTH-346`, `CA-AUTH-347`. **Es un fallo de despliegue de rutas, no de protocolo** |

---

## G.10 Desarrollo sin un IdP comercial: el IdP SAML simulado

### G.10.1 Por qué hace falta, y por qué aquí importa más que en 1.4b

`§F.10.1` explicó que 1.4b sí se podía probar entero en desarrollo. **En 1.4c no es que se pueda: es que hay que poder, y por un motivo distinto.**

Lo que hay que probar aquí **no es el camino feliz**: son los rechazos. **Firma alterada, `Audience` de otro tenant, `InResponseTo` inventado, aserción repetida, ventana vencida, `NameID` ausente, `Issuer` que no casa.** Ninguna de esas pruebas se puede hacer contra un IdP real, porque **un IdP real no emite aserciones inválidas a petición**. Sin un emisor bajo nuestro control, `RN-AUTH-117` a `RN-AUTH-123` **no tendrían ni un test negativo**, que es tanto como no tenerlas (`INV-015`).

**Es la razón por la que `funcional.md §G.14` pone el IdP simulado y las pruebas negativas *antes* del ACS en el orden de implementación.**

### G.10.2 Qué se entrega

**Un IdP SAML simulado servido por la propia API**, hermano del emisor OIDC de `§F.10.2`, **fuera del grupo de tenant** (es un emisor de plataforma, no de un centro) y bajo el mismo prefijo `_sso-simulator`:

- Un *endpoint* de **metadatos** con `entityID`, `SingleSignOnService` HTTP-Redirect y un `KeyDescriptor use="signing"` con un certificado **generado para desarrollo**.
- Un *endpoint* de **SSO** que recibe el `AuthnRequest`, extrae su `ID` y devuelve un formulario de auto-envío al ACS con una aserción firmada.
- **Y lo que lo hace útil de verdad: modificadores de comportamiento** para emitir a propósito una aserción **sin firmar**, **con firma alterada**, **con `Audience` de otro tenant**, **sin `InResponseTo`**, **con `InResponseTo` inventado**, **caducada**, **repetida** o **sin `NameID`**. Es lo que convierte cada regla de `RN-AUTH-117` a `RN-AUTH-123` en un test negativo real.

**El certificado y la clave del IdP simulado son de desarrollo y se generan en el arranque del entorno**, nunca se commitean (`CLAUDE.md §4`) y nunca son los mismos que ningún material de producción.

### G.10.3 Las dos barreras que impiden que llegue a producción

Mismo patrón que 1.4 y 1.4b, **y por el mismo motivo, que aquí es aún más literal**: lo que hay al otro lado de un descuido no es una funcionalidad rota, **es un emisor bajo control de cualquiera capaz de firmar aserciones que nuestro ACS aceptaría**.

1. **La ruta no se registra** fuera de `local`/`testing`, con test que lo comprueba con `APP_ENV=production` (`CA-AUTH-366`).
2. **Guarda de arranque en el propio controlador**, que aborta si se le invoca fuera de esos entornos.

**Dos barreras, no una.** Y una tercera de hecho, que conviene nombrar aunque no sea de este mecanismo: **un centro tendría que catalogar el IdP simulado como proveedor suyo y cargar su certificado** para que sus aserciones fueran aceptadas — cosa que `RN-AUTH-118` hace imposible por accidente, porque los certificados admisibles se fijan por proveedor.

### G.10.4 Lo que queda sin verificar, y hay que decirlo al cerrar

**Es más larga que la de 1.4b, y esa es la diferencia con el paso anterior.** El IdP simulado prueba nuestra mitad del protocolo entera; **no prueba la interoperabilidad**, que en SAML es donde están las sorpresas.

| Sin verificar | Por qué | Riesgo |
|---------------|---------|--------|
| **Que un IdP comercial real firme *también* la `Response` y no solo la `Assertion`** | `wantMessagesSigned = true` lo exige (`funcional.md §G.3.5`) | **Es lo primero que hay que comprobar**, contra ADFS, Entra ID y Shibboleth. Si un IdP conforme no puede, es un cambio de una línea **con su propia decisión**, no un rediseño |
| **Que la cookie de sesión fijada por el ACS viaje en el `302` en todos los navegadores** | El ACS fija la cookie en una respuesta a un `POST` entre sitios, y la SPA la recibe en una navegación `GET` de nivel superior a nuestro propio origen | **Si algún navegador no la envía**, la reserva declarada es el vale opaco de un solo uso (`funcional.md §G.3.2`). **No se implementa por adelantado**: es complejidad real a cambio de un problema que puede no existir, y averiguarlo cuesta una prueba |
| **La interoperabilidad de `NameIDFormat` y de los nombres de atributo** | Cada despliegue de ADFS y Shibboleth los configura a mano | Es el motivo de `OPEN-AUTH-43` y de que `email_attribute` sea texto libre validado |
| **TLS real, dominio público y certificado** | `0.10b` (`OPEN-08`) sigue pendiente | **Menos bloqueante que en 1.4b**: el flujo entero se recorre sin dominio público. Lo que no se puede es integrar con un IdP institucional de verdad |
| **El comportamiento con `EncryptedAssertion`** | No se soporta (`OPEN-AUTH-46`) | Un centro con ADFS cifrando **no puede integrarse** hasta que lo desactive. **Se documenta, no se descubre** |
| **Reloj desincronizado de verdad** | `AUTH_SSO_CLOCK_SKEW_SECONDS` (120) se prueba con relojes simulados | Un IdP con más de dos minutos de desfase real rechazaría todo, con un síntoma que no apunta al reloj |

---

## G.11 Impacto en copias de seguridad y restauración

**Cuatro tablas más en la copia, y ningún cambio de procedimiento.**

**Y una propiedad que hay que afirmar explícitamente, porque es la que `§F.11` no pudo afirmar**: **ninguna de las cuatro tablas nuevas contiene un secreto cifrado.** `identity_provider_secrets` metió en la copia de seguridad `client_secret` cifrados con `APP_KEY`, con la consecuencia de que **la copia sin `APP_KEY` es inservible para esa tabla**. Aquí no: los certificados del IdP son **material público**, y la clave privada del SP **no está en base de datos** (`§G.2.3`, `datos.md §G.1`).

**Consecuencia práctica de la restauración:**

- **Restaurar la base de datos sin nada más devuelve el SSO SAML a funcionar**, siempre que los IdP de los centros no hayan rotado sus certificados entretanto.
- **Salvo la firma de peticiones**: los proveedores con `sign_authn_requests = true` **necesitan además el fichero de clave privada**, que **no viaja en la copia de la base de datos**. Va en el procedimiento de recuperación como un artefacto aparte, con el mismo tratamiento que `APP_KEY` (`§G.2.3`).
- **`saml_auth_requests` y `saml_consumed_assertions` restauradas son irrelevantes y no molestan**: las filas de correlación habrán caducado, y las aserciones consumidas solo pueden causar **rechazos de más**, nunca aceptaciones de más. **Es la dirección segura del error**, y merece decirse: una restauración a un punto anterior **no reabre** ninguna ventana de repetición dentro del `NotOnOrAfter` de una aserción ya consumida, porque el índice único sigue ahí.

---

## G.12 Despliegue

### G.12.1 El día del despliegue no cambia nada para nadie

1. **Migraciones**: seis (`datos.md §G.7`). Las cuatro tablas nuevas no las usa nadie todavía; las dos modificaciones son **aditivas y compatibles con la versión anterior** (`datos.md §G.7.2`, `CA-AUTH-314`).
2. **Despliegue de la aplicación sin tocar ninguna variable nueva.** El sistema arranca y queda idéntico al anterior (`CA-AUTH-365`). **No hay que fijar nada para que este paso sea seguro**: las dos rutas de clave valen vacío y `AUTH_SAML_ALLOW_INSECURE_METADATA` vale `false`.
3. **La única acción del operador de plataforma, y es opcional**: generar y montar el par de clave/certificado del SP **si algún centro va a necesitar peticiones firmadas**. Sin él, todo lo demás funciona; lo único que no se puede es activar `sign_authn_requests` (`409`).
4. **A partir de ahí, cada centro configura el suyo cuando quiere.**

**El ACS se despliega y queda vivo desde el primer minuto**, respondiendo `302` con `estado_no_valido` a todo. **Es correcto y es lo que se quiere**: una ruta que existe y no reconoce nada es preferible a una que aparece cuando el primer centro cataloga un proveedor.

### G.12.2 Orden y reversión

**La reversión tiene tres escalones**, según lo que se quiera deshacer:

- **Apagar el SSO SAML de un centro**: su administrador desactiva el proveedor. Un `PATCH`, sin reinicio, sin tocar base de datos. **Nadie se queda fuera** (`RN-AUTH-96`), y **los vínculos siguen viéndose y pudiendo retirarse** desde el perfil.
- **Apagar la firma de peticiones en toda la plataforma**: retirar la variable de la clave y reiniciar. Los proveedores con `sign_authn_requests = true` dejarían de poder firmar, así que **no es una maniobra inocua** y solo tiene sentido si la clave se ha comprometido — en cuyo caso el procedimiento completo incluye pedir a cada centro afectado que recargue nuestros metadatos.
- **Revertir la aplicación**: **mientras no haya ninguna fila con `protocol = 'saml'`, revertir el esquema es limpio y completo.** En cuanto un centro cataloga un proveedor SAML, **revertir la migración 1 falla ruidosamente**, porque devolver las siete columnas a `NOT NULL` es imposible con una fila SAML dentro (`datos.md §G.7.3`). **Es el comportamiento correcto y no se suaviza**: a partir de ahí, revertir el esquema deja de ser una operación de despliegue y pasa a ser una decisión con pérdida de datos.

**Como en todos los pasos anteriores del módulo, revertir la aplicación no exige revertir las migraciones**, y esa es la vía normal de vuelta atrás.

### G.12.3 Lo que hay que verificar en el entorno real y no se puede verificar en WSL2

`§G.10.4`, entero. **Es más larga que la de 1.4b**, y esa es la diferencia honesta con el paso anterior: SAML tiene mucha más superficie de interoperabilidad que OIDC, y **la parte que un IdP simulado no puede cubrir es precisamente la que decide si un centro real puede integrarse**.

### G.12.4 Documentación raíz que este paso obliga a tocar, y no después

**`SECURITY.md` gana una entrada, y no es opcional** (`ADR-043 §10.10`, `CLAUDE.md §6.7`): **la primera excepción de CSRF de la aplicación**, qué ruta, qué pila de *middleware*, y **qué la mitiga** — la correlación de un solo uso y el «no» al SSO iniciado por el IdP, **no la ausencia de riesgo**.

**`RUNBOOK.md` gana la obligación de seguimiento de `php-saml` y `xmlseclibs`** (`§G.3.1`), y el procedimiento de rotación manual de la clave del SP (`§G.2.3`).

**`SYSADMIN.md` gana la custodia del fichero de clave** y las tres guardas de arranque de `§G.2.2`.

Se escribe aquí, en la especificación, **con el precedente de los issues [#111](https://github.com/pirexia/plataforma-educativa/issues/111)-[#114](https://github.com/pirexia/plataforma-educativa/issues/114) y [#125](https://github.com/pirexia/plataforma-educativa/issues/125) delante**: la documentación raíz se quedó atrás durante cinco cierres de fase seguidos porque nadie la puso en el alcance del paso. **Aquí está en el alcance del paso.**
