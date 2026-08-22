# REQ-AUTH · Operación

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

Reglas de los trabajos, heredadas de `ADR-033 §8` y de lo aprendido en 1.1:

- **El contexto de tenant lo entra y lo sale el mecanismo de framework** (`Queue::createPayloadUsing()` estampa el `tenant_id` al despachar; los *listeners* de `JobProcessing`/`JobProcessed`/`JobFailed` lo gestionan). Ningún trabajo de este módulo lo maneja a mano — la revisión de seguridad de 1.1 ya confirmó que ese mecanismo es suficiente.
- **Ningún trabajo lleva un token en claro persistido.** `SendPasswordResetEmail` y `SendAccountLockedEmail` reciben el token en claro **en su *payload***, igual que `SendInvitationEmail` de 1.1, y ese *payload* vive en Redis hasta que el trabajo se ejecuta. Es la misma exposición que 1.1 ya aceptó, con la misma acotación: el trabajo se consume en segundos y el token caduca en minutos u horas. **Consecuencia operativa que hay que respetar: los trabajos fallidos de `auth-mail` no se conservan con su *payload* legible más allá de lo imprescindible**, y `failed_jobs` no es un sitio donde inspeccionar cómodamente un fallo de este módulo.
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
2. **Despliegue de la aplicación.** Las guardas de arranque de §2.2 se evalúan aquí: **un `EnvironmentFile` con `SESSION_DOMAIN` fijado impide que el contenedor arranque.** Es el comportamiento buscado, pero hay que saberlo antes de desplegar a las 8:00 de un lunes — conviene verificar el fichero de entorno **antes** de la ventana.
3. **`php artisan platform:sync-registry`** — materializa `bloqueo_cuenta.leer` y `bloqueo_cuenta.eliminar` y la entrada `auth` del catálogo. Sin este paso, `GET`/`DELETE /account-lockouts` deniegan por defecto, que es correcto pero desconcertante.
4. **Concesión de los dos permisos al rol `administrador_centro` de cada tenant existente.** El aprovisionamiento de 1.1 solo corre en el alta de un centro nuevo, así que los centros ya creados **no** reciben los permisos nuevos por sí solos. Hace falta una migración de datos idempotente que los conceda, o volver a ejecutar la parte de siembra de permisos de `tenant:provision-defaults` (que es idempotente por diseño, `CA-CORE-074`). **Es el paso que más fácil se olvida** y su síntoma es un `403` inexplicable en un centro que existía antes del despliegue.
5. Reinicio de los *workers* para que recojan las colas `auth-mail` y `auth-maintenance`.

**Reversión.** El código revierte limpiamente: las migraciones son aditivas y su `down()` elimina tablas y columnas que ninguna otra referencia. Dos avisos que hay que leer antes de revertir:

- **Al revertir, nadie puede iniciar sesión** — se vuelve al estado de 1.1, donde no existe login. No es una degradación parcial: es la pérdida total del acceso interactivo. Cualquier reversión de esta entrega es, en la práctica, una parada del servicio para los usuarios.
- **Las contraseñas fijadas durante la vigencia de la entrega se conservan** (están en `users.password`, que 1.1 ya tenía), igual que los usuarios activados. Revertir no deshace las activaciones: deja usuarios `activo` con contraseña válida y sin ningún camino para usarla. Al volver a desplegar, todo funciona sin intervención.
