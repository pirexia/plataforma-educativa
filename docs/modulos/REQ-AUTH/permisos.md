# REQ-AUTH · Permisos

> **Estructura**: las secciones **§1 a §8** son el paso **1.2**, cerrado el 2026-08-25. La **Parte B** (`§B.1` en adelante) es el paso **1.2b** (`funcional.md` Parte B), **pendiente de aprobación**.

> Sección 11 del documento de requisitos (`RPERM-001` a `RPERM-015`) aplicada a este módulo. El **resolutor granular** sigue siendo el paso 1.5 (`ADR-034 §2`); lo que se fija aquí es el catálogo, la matriz y la siembra, para que 1.5 no tenga que inventarlos ni migrarlos.
>
> Fuente de verdad del catálogo: **el código del módulo** (`INV-007`), declarado en `AuthServiceProvider::declaredPermissions()` y materializado en `permissions` por `platform:sync-registry` (`ADR-034 §2`). Esta tabla es su reflejo documental, no su origen.

---

## 1. La observación más importante de este documento

**`REQ-AUTH` es, casi entero, un módulo sin permisos.**

De sus diez endpoints (`api.md §7`; corregido — la especificación original decía nueve, antes de aprobarse `OPEN-AUTH-05`), **ocho no llevan ninguno**: seis son anónimos por diseño —no puede haber permiso donde todavía no hay usuario que autorizar— y dos se autorizan por identidad del portador de la cookie (`DELETE /auth/session` y `POST /auth/password-changes`).

Eso no relaja `INV-002`, lo desplaza: en este módulo la denegación por defecto **no** la aplica el resolutor de permisos, sino tres mecanismos concretos que hay que verificar uno a uno porque no hay un *middleware* que los cubra:

| Mecanismo | Dónde | Qué garantiza |
|-----------|-------|----------------|
| **Posesión de un token de un solo uso** | Canje, restablecimiento, desbloqueo por correo | Solo quien recibió el correo puede ejecutar la acción. El token es de 32 bytes, se persiste solo como hash, se busca por `(tenant_id, hash)` y muere al usarse |
| **Verificación de credencial** | Login | Es el propio acto de autorización |
| **Identidad del portador** | Logout, cambio de contraseña | Actúa sobre la cookie presentada, **nunca** sobre un sujeto identificado por parámetro |

Y una regla estructural que sostiene a las tres: **el `tenant_id` sale siempre del host** (`ADR-033 §2`), nunca del cuerpo. Un token del tenant A presentado en el host del tenant B no encuentra nada, porque la consulta lleva el tenant en el `WHERE` (`RN-AUTH-06`, `RN-AUTH-07`).

La superficie anónima de este módulo (seis endpoints) es la mayor del producto. Lo que la acota está en `api.md §7` y en `operacion.md §6`, no en esta matriz.

---

## 2. Recursos que aporta el módulo

| Recurso | Qué representa |
|---------|----------------|
| `bloqueo_cuenta` | Bloqueo de una cuenta por intentos fallidos consecutivos (`account_lockouts`) |

**Uno solo.** Las **acciones** son las de `RPERM-003` sin excepción (`crear`, `leer`, `actualizar`, `eliminar`, `exportar`, `importar`, `aprobar`, `firmar`, `publicar`): no se inventa ninguna. Por eso el desbloqueo es `bloqueo_cuenta.eliminar` —se elimina el bloqueo— y no una acción `usuario.desbloquear`, exactamente el mismo criterio con el que `REQ-CORE` modeló la invitación como recurso (`invitacion.crear`) en vez de como acción `usuario.invitar`.

Los **ámbitos** son los de `RPERM-004`. En 1.2 **solo se usa `todos`**: §5.6.

---

## 3. Catálogo de permisos que declara `REQ-AUTH` en 1.2

`module_code = 'auth'`, `is_special_category = false` en los dos (§6).

| `code` | Recurso | Acción | Endpoints que lo exigen |
|--------|---------|--------|-------------------------|
| `bloqueo_cuenta.leer` | `bloqueo_cuenta` | `leer` | `GET /account-lockouts` |
| `bloqueo_cuenta.eliminar` | `bloqueo_cuenta` | `eliminar` | `DELETE /account-lockouts/{public_id}` |

**Endpoints sin permiso, a propósito y de forma razonada:**

| Endpoint | Por qué |
|----------|---------|
| `GET /auth/csrf-cookie` | Anónimo. La SPA lo necesita antes de que exista sesión, igual que `GET /tenant/branding` en 1.1. Superficie cerrada por contrato: `204` sin cuerpo |
| `POST /auth/session` | Anónimo. **Es** el acto de autorización |
| `DELETE /auth/session` | Por identidad del portador de la cookie. Modelarlo como permiso sería absurdo: un usuario sin permisos no podría salir del sistema |
| `POST /auth/invitation-redemptions` | Anónimo. Autorizado por posesión del token; el sujeto todavía no tiene cuenta activa con la que tener permisos |
| `POST /auth/password-reset-requests` | Anónimo. Exigir permiso implicaría estar dentro, que es justo lo que no puede hacer quien olvidó la contraseña |
| `POST /auth/password-resets` | Anónimo. Autorizado por posesión del token |
| `POST /auth/account-unlocks` | Anónimo. Autorizado por posesión del token; su titular está bloqueado y no puede autenticarse |
| `POST /auth/password-changes` | Por identidad del portador de la cookie, igual que el logout y `GET /me` de `REQ-CORE`. `OPEN-AUTH-05`, `api.md §5b` |

**No se declara `bloqueo_cuenta.crear`.** Los bloqueos los crea el sistema al quinto fallo, nunca una persona. Declarar el permiso sugeriría que existe una forma de bloquear a alguien a mano, y no la hay ni la pide ningún requisito. Es el mismo criterio con el que `REQ-CORE/permisos.md §3` dejó `auditoria` sin `crear`/`actualizar`/`eliminar`.

**No se declara `bloqueo_cuenta.actualizar`.** Un bloqueo no se edita: se crea o se levanta.

---

## 4. Matriz recurso × acción × ámbito

Ámbito único en 1.2: `todos` (§5.6). `—` significa que el permiso no existe en este módulo.

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `bloqueo_cuenta` | — (§3) | `todos` | — (§3) | `todos` | — | — | — | — | — |

### 4.1 Permisos de otros módulos que este módulo usa sin declarar

| Permiso | De | Para qué |
|---------|----|----------|
| `configuracion.leer` | `REQ-CORE` | Leer `session_timeout_minutes` en `GET /tenant/settings` (`api.md §6`) |
| `configuracion.actualizar` | `REQ-CORE` | Escribirlo en `PATCH /tenant/settings` |

**No se declara un permiso propio para el timeout de sesión.** El campo vive en el recurso de configuración del centro de 1.1 y se gobierna con su permiso. Crear `sesion.actualizar` para una sola columna dentro de un recurso ajeno partiría en dos la autorización de un mismo formulario y obligaría a la interfaz de 1.8 a comprobar dos permisos para pintar una pantalla. Si en el futuro la configuración de seguridad crece —política de contraseñas por centro, ventana de bloqueo—, ese es el momento de separarla, con varios campos delante y no con uno.

---

## 5. Asignación en los roles predefinidos

Los 16 roles de tenant se siembran en `tenant:provision-defaults` (`REQ-CORE/funcional.md §4.7`). 1.2 **no crea ni modifica ningún rol**: solo añade concesiones de los dos permisos nuevos.

Denegación por defecto (`RPERM-011`): lo que no aparece, no se concede.

| Rol (`code`) | Permisos de `REQ-AUTH` | Ámbito |
|--------------|------------------------|--------|
| `administrador_centro` | `bloqueo_cuenta.leer`, `bloqueo_cuenta.eliminar` | `todos` |
| Los 15 restantes | — | — |

### 5.1 Por qué solo el Administrador de Centro

Es una decisión, no una omisión. **Desbloquear una cuenta es un acto de recuperación de acceso**: quien puede hacerlo puede, en combinación con el control del buzón de correo, devolver el acceso a una cuenta que estaba defendiéndose de un ataque en curso. Concederlo a Secretaría «para que puedan ayudar por teléfono» convierte un mostrador en un punto de ingeniería social contra la cuenta de Dirección.

Los dos candidatos que uno pensaría primero se descartan con motivo:

- **`direccion`**: tiene `usuario.leer` en `REQ-CORE` pero ninguna escritura sobre usuarios. Darle desbloqueo sería su primera capacidad de escritura sobre cuentas ajenas, y llegaría por la puerta de atrás.
- **`secretaria`** y **`administrativo`**: son quienes más llamadas de «no puedo entrar» reciben, y por eso mismo son el objetivo natural de una llamada falsa.

Si un centro concreto necesita repartirlo, **1.5 lo permitirá con un rol personalizado**, que es donde esa decisión pertenece: la toma el centro, con nombre y apellidos, y queda en auditoría. No es un valor por defecto de la plataforma. Es exactamente el mismo argumento con el que `REQ-CORE/permisos.md §4.1` dejó `auditoria.leer` solo en `administrador_centro`.

### 5.2 `soporte_plataforma`

**Sin permisos de `REQ-AUTH`**, igual que en 1.1. Su acceso real es *impersonation* auditada (`REQ-SUP-003`). Un rol del proveedor con capacidad de desbloquear cuentas en cualquier centro sería una puerta permanente, y además redundante: 1.6 le dará lo que necesite por su propia vía, con su propio registro.

### 5.3 `super_administrador`

**No es una fila de `roles`** (`ADR-034 §2`, `REQ-CORE/permisos.md §4.5`). Vive en `platform_admins`, sin `tenant_id`, y lo suyo es el paso 1.6.

### 5.4 `mfa_obligatorio` (`RPERM-014`)

`roles.mfa_required` se sembró en 1.1 con `true` para `administrador_centro` y `soporte_plataforma`. **1.2 no lo cambia y no lo aplica**: la exigencia efectiva es 1.3.

Merece decirse en voz alta porque es contraintuitivo: al cerrar 1.2 existe un atributo `mfa_required = true` en la base de datos **que nada comprueba todavía**. Un administrador con esa marca inicia sesión solo con contraseña. No es un fallo de 1.2 —el plan pone el MFA en 1.3— pero sí es una expectativa que hay que desactivar en la revisión, y una razón más para que el bloqueo por intentos y la política de contraseñas de este paso sean estrictos: **en 1.2 la contraseña es el único factor que existe.**

### 5.5 `acceso_datos_especiales` (`RPERM-015`)

Sin cambios. `REQ-AUTH` no expone categoría especial (§6).

### 5.6 Ámbitos en 1.2: por qué los dos permisos son `todos`

Rige la misma **regla de seguridad** que `REQ-CORE/permisos.md §5`, y hay que repetirla porque sigue en vigor: entre 1.1 y 1.5, el resolutor provisional de `ADR-034 §2` **lee `permission_role.effect` e ignora `permission_role.scope`**. Una concesión con ámbito `propios` se evalúa hoy exactamente igual que una con ámbito `todos`.

Aplicado a este módulo: sembrar `bloqueo_cuenta.leer` con ámbito `propios` —pensando en «que cada uno vea si está bloqueado»— daría a ese rol el **listado completo de cuentas bloqueadas del centro**, que es un mapa de quién ha tenido problemas de acceso y de qué correos existen. Fallo de control de acceso silencioso, activo durante tres pasos del plan.

Reglas derivadas, verificables:

1. **Toda fila de `permission_role` creada en 1.2 lleva `scope = 'todos'`** (`RN-CORE-22`). Verificado por el test de catálogo de §8.
2. **El autoservicio no se modela como permiso con ámbito.** El logout se autoriza por identidad del portador de la cookie (§1), no por `sesion.eliminar` con ámbito `propios`. Es una comprobación de identidad y no depende del resolutor.
3. **1.5 hereda la responsabilidad** de introducir los ámbitos restringidos junto con el resolutor que los evalúa, en el mismo paso. Nunca antes.

---

## 6. Datos de categoría especial

**`REQ-AUTH` no expone datos de categoría especial** (salud, NEAE, convivencia). Ninguno de sus dos permisos lleva `is_special_category = true`, y la auditoría reforzada de lectura de `RPERM-015` no se dispara aquí.

Sí trata dos categorías de dato que conviene no confundir con «no sensible»:

- **Credenciales.** No son categoría especial en el sentido del art. 9 RGPD, pero su compromiso da acceso a todo lo demás. Se almacenan hasheadas (`RN-AUTH-03`), nunca se registran en auditoría (patrón `*password*` de 0.9) y no aparecen ni fragmentadas en `login_attempts` (`RN-AUTH-05`).
- **Direcciones IP y correos en `login_attempts`.** Son datos personales tratados por interés legítimo en la seguridad del tratamiento (art. 32 RGPD). Su acotación es la retención de 90 días (`datos.md §A.9`), no un permiso: en 1.2 **ningún endpoint expone esa tabla**. El día que 1.2b o 1.6 construyan la pantalla de accesos, ese paso deberá declarar su propio permiso y decidir quién lo tiene — y esa decisión no es trivial, porque un historial de accesos es un registro de la jornada laboral de la plantilla, con lo que eso implica (`REQ-JOR`, y el mismo argumento con el que `auditoria.leer` se restringió en 1.1).

---

## 7. Reglas de autorización que no son un permiso

Comprobaciones que ningún permiso cubre y que hay que implementar explícitamente. Es la parte de este documento que la revisión de seguridad debe recorrer entera:

| Regla | Dónde | Efecto |
|-------|-------|--------|
| **Posesión del token** es la autorización de tres endpoints | Canje, restablecimiento, desbloqueo | Token inválido, caducado, usado o **de otro tenant** ⇒ `410` con cuerpo idéntico |
| `RN-AUTH-06` — el `tenant_id` sale del host, jamás del cuerpo | Los diez endpoints | Un `tenant_id` en un `FormRequest` de este módulo es un fallo de revisión (`ADR-033 §2`) |
| `RN-AUTH-07` — predicado de tenant **explícito** además de RLS | Repositorio de tokens de restablecimiento, bloqueos, intentos | Issue [#18](https://github.com/pirexia/plataforma-educativa/issues/18): RLS es la segunda barrera, no la única |
| `RN-AUTH-16` — bloqueo antes que credencial | Login | Con bloqueo vivo se responde `423` **sin comprobar la contraseña**. Comprobarla primero filtraría, por tiempo de respuesta, si era correcta |
| `RN-AUTH-23` — solo `activo` inicia sesión | Login | `pendiente` e `inactivo` reciben el `401` genérico, indistinguible |
| `RN-AUTH-31` — reverificación del tenant de la sesión | *Middleware* `VerifySessionTenant` | Discrepancia ⇒ sesión invalidada, `401` y auditoría (`ADR-033 §2`) |
| `RN-AUTH-29` — CSRF en toda escritura, **incluidas las anónimas** | Los seis endpoints anónimos de escritura | Un login sin CSRF permite el «login CSRF»: forzar al navegador de la víctima a iniciar sesión con la cuenta del atacante y que trabaje dentro de ella sin saberlo |
| Aislamiento de tenant en el listado y el desbloqueo | `GET`/`DELETE /account-lockouts` | Un `public_id` de otro tenant ⇒ `404`, nunca `403` (`ADR-038 §6.4`) |
| `RN-AUTH-19` — nadie se desbloquea a sí mismo por la API de administración | `DELETE /account-lockouts/{public_id}` | Es imposible por construcción, no por comprobación: un usuario bloqueado no puede autenticarse, así que no puede llegar a un endpoint con sesión. Se anota para que nadie «arregle» esto permitiendo el acceso parcial de una cuenta bloqueada |

---

## 8. Verificación

- **`CA-AUTH-031`** — sin `bloqueo_cuenta.eliminar` ⇒ `403`; bloqueo de otro tenant ⇒ `404`.
- **`CA-AUTH-070`** — todo endpoint de administración de este módulo responde `401` sin sesión, `403` sin permiso y `404` sobre recurso ajeno.
- **`CA-AUTH-005`** — toda escritura sin CSRF válido se rechaza, **incluidos los endpoints anónimos**.
- **`CA-AUTH-011`**, **`CA-AUTH-027`**, **`CA-AUTH-041`** — respuestas indistinguibles en credencial, bloqueo y token.
- **`CA-AUTH-042`**, **`CA-AUTH-033`** — token de invitación y token de restablecimiento del tenant A rechazados en el host del tenant B.
- **`CA-AUTH-078`** — ninguna ruta de este módulo lleva el *middleware* `module-enabled`.
- Test de catálogo: tras `platform:sync-registry`, la tabla `permissions` contiene **exactamente** `bloqueo_cuenta.leer` y `bloqueo_cuenta.eliminar` con `module_code = 'auth'`, ninguno marcado `retired_at`, y ninguna fila de `permission_role` de este módulo con `scope` distinto de `todos` (§5, `RN-CORE-22`).

---
---

# Parte B · Paso 1.2b · Permisos

> Alcance: paso **1.2b** (`funcional.md` Parte B). **Estado**: propuesta, pendiente de `funcional.md §B.14`.

---

## B.1 La conclusión, primero: **1.2b no declara ningún permiso**

El catálogo del módulo sigue siendo **exactamente el mismo** después de 1.2b: `bloqueo_cuenta.leer` y `bloqueo_cuenta.eliminar`. Ni un recurso nuevo, ni una acción nueva, ni una fila nueva en `permissions`, ni una concesión nueva en ningún rol.

No es un descuido ni una simplificación. Es la consecuencia directa de que **los tres endpoints que trae 1.2b son autoservicio puro**, y §1 ya explicó cómo se autoriza el autoservicio en este módulo: por **identidad del portador de la cookie**, que es el tercero de los tres mecanismos de esa tabla. Los tres endpoints nuevos entran en esa casilla junto al logout y al cambio de contraseña:

| Endpoint | Cómo se autoriza | Por qué no es un permiso |
|----------|------------------|--------------------------|
| `GET /auth/sessions` | Identidad del portador | Un usuario sin permisos tiene que poder ver sus propias sesiones. Modelarlo como permiso permitiría configurarlo a `false`, y un centro que lo hiciera dejaría a su plantilla sin forma de detectar un acceso ajeno |
| `DELETE /auth/sessions/{public_id}` | Identidad del portador | Ídem, y agravado: quitarle a alguien la capacidad de cerrar su propia sesión comprometida no protege nada de nadie |
| `DELETE /auth/sessions` | Identidad del portador | Ídem |

Y sobre todo: `permisos.md §5.6`, **regla 2**, escrita en 1.2 y en vigor —*«El autoservicio no se modela como permiso con ámbito. El logout se autoriza por identidad del portador de la cookie, no por `sesion.eliminar` con ámbito `propios`»*—. 1.2b es el primer paso que **pone a prueba** esa regla con una funcionalidad que sí parece pedir un recurso, y la regla aguanta.

**Recuerdo de por qué importa**: entre 1.1 y 1.5 el resolutor provisional de `ADR-034 §2` **lee `effect` e ignora `scope`**. Un permiso `sesion.leer` sembrado con ámbito `propios` —pensando «que cada uno vea las suyas»— se evaluaría hoy como `todos`. Es decir: **crear ese permiso con la intención correcta daría, durante tres pasos del plan, acceso al listado completo de sesiones activas del centro**, que es un mapa en tiempo real de quién está conectado y desde dónde. Ese es el fallo silencioso concreto que la regla 2 evita, y es la razón por la que no basta con «lo arreglamos en 1.5».

---

## B.2 Lo que 1.2b **no** concede a ningún administrador, y por qué

Ni `administrador_centro`, ni `direccion`, ni `secretaria`, ni `soporte_plataforma` obtienen capacidad alguna de **ver o cerrar las sesiones de otra persona**. Es una decisión, no una omisión, con tres argumentos independientes:

1. **El requisito no lo pide.** `REQ-AUTH-005` punto 3 dice «visualización de sesiones activas **del usuario**». Añadir una vista de administración sería inventar un requisito (`CLAUDE.md §11`), y no uno cualquiera.
2. **Un listado de sesiones activas del centro es vigilancia laboral.** Es el mismo argumento que §6 hizo sobre `login_attempts` y que `REQ-CORE/permisos.md §4.1` hizo sobre `auditoria.leer`, aquí en su versión más aguda: `login_attempts` dice a qué hora entró alguien; un panel de sesiones activas dice **quién está conectado ahora mismo, desde dónde y con qué dispositivo**, actualizado en vivo. Eso tiene implicaciones de `REQ-JOR` y de protección de datos que no se resuelven concediendo un permiso.
3. **El administrador ya tiene la palanca que necesita**, y es proporcionada: dar de baja al usuario (`DELETE /users/{id}` de `REQ-CORE`) revoca todas sus sesiones por el evento `UserDeactivated` (`CA-AUTH-076`). Un administrador que necesita cortar el acceso de alguien puede hacerlo hoy; lo que no puede es observarlo.

**Dónde va esa capacidad si algún día se quiere**: en **1.5** como rol personalizado —decisión del centro, con nombre y apellidos y con auditoría— o en **1.6** con el registro propio del soporte de plataforma. No como valor por defecto de la plataforma, exactamente igual que se argumentó para `bloqueo_cuenta.eliminar` en §5.1. Para eso 1.2b expone la interfaz `UserSessionDirectory` (`funcional.md §B.8.3`): el día que se decida, el paso que lo implemente no tiene que abrir el modelo de este módulo.

---

## B.3 Matriz recurso × acción × ámbito

Sin cambios respecto de §4. Se reproduce completa porque una matriz que no cambia es un resultado, no una ausencia, y la revisión tiene que poder verificarlo de un vistazo.

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `bloqueo_cuenta` | — (§3) | `todos` | — (§3) | `todos` | — | — | — | — | — |
| ~~`sesion`~~ | — | — | — | — | — | — | — | — | — |

**`sesion` no existe como recurso**, y la fila está tachada a propósito para que quien busque «dónde están los permisos de sesiones» encuentre la respuesta aquí en vez de suponer que falta. Si 1.5 o 1.6 lo crean para la vista de administración de `§B.2`, será **su** catálogo el que lo declare, con **su** ámbito y **su** decisión de a quién se concede.

**Roles**: los 16 siguen exactamente como en §5. 1.2b **no crea, no modifica y no concede nada**, y por tanto **no necesita un comando de migración de datos** equivalente al `auth:grant-lockout-permissions` de 1.2 (`operacion.md §11`, punto 4). Ese comando fue el paso que más fácil se olvidaba del despliegue anterior; que aquí no haya ninguno es una propiedad del diseño que conviene aprovechar y no estropear.

---

## B.4 Reglas de autorización que no son un permiso

Ampliación de §7. Son las comprobaciones que **ningún permiso cubre** y que hay que implementar y revisar explícitamente — la parte de este documento que la revisión de seguridad debe recorrer entera, ahora con cuatro filas más.

| Regla | Dónde | Efecto |
|-------|-------|--------|
| **`RN-AUTH-41` — el `user_id` del solicitante va en el `WHERE`, no en un `if`** | Los tres endpoints de `/auth/sessions` | La propiedad de la sesión es parte de la consulta, no una comprobación posterior. Un `find($publicId)` seguido de `if ($session->user_id !== $user->id)` es un fallo de revisión: basta con que un camino futuro olvide el `if` |
| **Sesión de otro usuario del mismo tenant ⇒ `404`, nunca `403`** | `DELETE /auth/sessions/{public_id}` | `403` significa «existe pero no puedes» y convierte el endpoint en un oráculo de sesiones vivas ajenas. Extiende dentro del tenant lo que `ADR-038 §6.4` fija entre tenants (`api.md §B.3`) |
| **`RN-AUTH-40` — el identificador de sesión no sale nunca** | Respuestas, auditoría, logs, *payloads* de trabajos encolados | El identificador de sesión **es** la credencial. Su redacción en auditoría no la cubre ningún patrón automático y hay que declararla a mano (`datos.md §B.2`); en las respuestas la cubre el *resource*, y en los *payloads*, la regla de pasar identificadores públicos y no objetos |
| **`RN-AUTH-46` — la detección de dispositivo ocurre después de verificar la credencial** | Login | Registrar dispositivo o alertar antes de saber que la contraseña es correcta convierte el mecanismo de alerta en un amplificador de correo dirigido: cualquiera que conozca el correo de un profesor podría inundar su buzón con avisos escribiendo contraseñas al azar desde navegadores distintos |
| **`RN-AUTH-45` — la cookie `pge_device` no autentica ni autoriza nada** | Login | Es una señal para decidir si avisar, y nada más. Presentar una cookie de dispositivo conocido **no** salta ningún control, ni ahora ni cuando exista MFA: quien la use para eso en 1.3 tendrá que decidirlo explícitamente, no heredarlo |

Y siguen en vigor, sin excepción, las ocho de §7: `RN-AUTH-06` (el `tenant_id` sale del host), `RN-AUTH-07` (predicado explícito además de RLS), `RN-AUTH-29` (CSRF en toda escritura) y las demás.

---

## B.5 Datos de categoría especial

**Sigue sin haberlos.** `REQ-AUTH` no expone salud, NEAE ni convivencia, y ninguno de sus dos permisos lleva `is_special_category = true`. La auditoría reforzada de lectura de `RPERM-015` no se dispara aquí.

Lo que 1.2b **sí** añade al inventario de datos personales del módulo, y que §6 obliga a no confundir con «no sensible»:

- **Un identificador persistente de navegador** (la cookie `pge_device`). No es categoría especial y no contiene ningún dato personal por sí mismo, pero es un identificador que sobrevive a la sesión, y su clasificación formal en protección de datos está **abierta** (`OPEN-AUTH-14`).
- **Un registro de desde dónde y con qué se conecta cada persona.** IP, cliente y momento, por sesión. Acotado por retención (90 días, `datos.md §B.7`), por no existir ninguna vista de administración que lo agregue (`§B.2`) y por borrarse con la persona (`RN-AUTH-48`) — tres acotaciones, ninguna de ellas un permiso, porque **no hay ningún permiso que conceda acceso a esto**: solo lo ve su titular.

---

## B.6 Verificación

- **`CA-AUTH-092`** — los tres endpoints responden `401` sin sesión, y los dos `DELETE` rechazan la escritura sin CSRF válido (`INV-002`, `RN-AUTH-29`).
- **`CA-AUTH-087`** — sesión de otro usuario del mismo tenant y sesión de otro tenant: **`404` con cuerpo idéntico** en los dos casos.
- **`CA-AUTH-082`** — el listado devuelve **solo** las sesiones del solicitante.
- **`CA-AUTH-083`** — ninguna respuesta contiene el identificador de sesión, el *payload* ni material de la cookie de dispositivo.
- **`CA-AUTH-102`** — la revocación queda auditada, y ninguna fila de auditoría contiene el identificador de sesión sin redactar.
- **`CA-AUTH-078`** — ninguna ruta de este módulo lleva `module-enabled`, **incluidas las tres nuevas**.
- **Test de catálogo, ampliado**: tras `platform:sync-registry`, `permissions` sigue conteniendo **exactamente** `bloqueo_cuenta.leer` y `bloqueo_cuenta.eliminar` con `module_code = 'auth'`. Es decir: **el mismo test de §8 tiene que seguir pasando sin tocarlo**. Si 1.2b lo hace fallar, alguien ha declarado un permiso que esta especificación dice que no existe.
