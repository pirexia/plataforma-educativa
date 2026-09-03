# REQ-AUTH · Permisos

> **Estructura**: las secciones **§1 a §8** son el paso **1.2**, cerrado el 2026-08-25. La **Parte B** (`§B.1` en adelante) es el paso **1.2b** (`funcional.md` Parte B), **implementada y cerrada** el 2026-08-26 (PR [#91](https://github.com/pirexia/plataforma-educativa/pull/91)/[#92](https://github.com/pirexia/plataforma-educativa/pull/92)).

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

> Alcance: paso **1.2b** (`funcional.md` Parte B). **Estado**: implementada, aprobada el 2026-08-25 (`funcional.md §B.14`), cerrada el 2026-08-26.

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

---
---

# Parte C · Paso 1.3 · Permisos (`REQ-AUTH-003`)

> **Estructura**: §1-§8 son 1.2 (cerrado). §B.1-§B.6 son 1.2b (cerrado). Esta **Parte C** es el paso **1.3**, **implementada y cerrada** el 2026-08-27 (PR [#107](https://github.com/pirexia/plataforma-educativa/pull/107), commit `cd13e8a`).
>
> Fuente de verdad del catálogo: **el código** (`AuthServiceProvider::declaredPermissions()`), materializado por `platform:sync-registry`. Esta tabla es su reflejo documental.

---

## C.1 La conclusión, primero: 1.3 rompe la racha

§1 abría diciendo que *«`REQ-AUTH` es, casi entero, un módulo sin permisos»*, y §B.1 confirmaba que 1.2b **no declaraba ninguno**. **1.3 declara dos, y además obliga a declarar uno en `REQ-CORE`.**

No es una desviación: es la consecuencia directa de que `REQ-AUTH-003` sea el primer requisito de este módulo con superficie de administración real. Hasta ahora lo único que un administrador podía hacer aquí era levantar un bloqueo. Ahora puede **cambiar la política de seguridad de un rol entero** y **devolver el acceso a una cuenta protegida**. Las dos son decisiones sobre otras personas, y las dos necesitan permiso.

Lo que **no** cambia es la otra mitad de §1: de los diez endpoints de este paso, **siete siguen sin permiso** —dos autorizados por la cookie del desafío y cinco por identidad del portador— y ahí la denegación por defecto (`INV-002`) sigue sin aplicarla ningún *middleware*, sino los mecanismos de `§C.4`. (Corrección de cuenta, 2026-08-27: esta frase decía antes "ocho... dos... seis", una aritmética que nunca cuadró con los nueve endpoints que tenía 1.3 antes de restaurar `GET /mfa-compliance/users` — ver la nota de partición.)

**Nota de partición (`OPEN-AUTH-24`, `funcional.md §C.16`):** la especificación original de este paso incluía también el recurso `exencion_mfa` (conceder/revocar una excepción temporal nominal) y el listado individualizado de usuarios (`GET /mfa-compliance/users`). El usuario partió el paso en `1.3`/`1.3b` el 2026-08-26. **Corrección del 2026-08-27**: un subagente había movido los dos a `1.3b` por error — solo `exencion_mfa` estaba en lo que el usuario decidió mover. El usuario revisó el hallazgo y restauró `GET /mfa-compliance/users` en `1.3`, con el mismo permiso `mfa.leer` que el agregado (`§C.6.1`). `exencion_mfa` **sigue** en `1.3b`; esta sección documenta **solo** lo que `1.3` declara. La tabla `user_mfa_exemptions` ya existe desde 1.3 (`datos.md §C.6`) porque `MfaPolicy::resolve()` la consulta (§C.4.7 punto 1 de `funcional.md`), pero **ningún endpoint de 1.3 escribe en ella todavía**.

---

## C.2 Recursos que aporta el paso

| Recurso | Qué representa |
|---------|----------------|
| `mfa` | La configuración y el estado de segundo factor **de los usuarios del centro**, visto desde la administración: cumplimiento, vista previa y restablecimiento |

**Uno.** Las **acciones** son las de `RPERM-003` sin excepción; no se inventa ninguna. Los **ámbitos** son los de `RPERM-004`, y en 1.3 **solo se usa `todos`**, por el mismo motivo estructural de §5.6 que sigue vigente hasta 1.5.

**Por qué el restablecimiento es `mfa.eliminar` y no una acción `usuario.restablecer_mfa`.** Se elimina el MFA de alguien; el verbo es `eliminar`. Es exactamente el criterio con el que §2 modeló el desbloqueo como `bloqueo_cuenta.eliminar` en vez de `usuario.desbloquear`, y con el que `REQ-CORE` modeló la invitación como recurso.

---

## C.3 Catálogo de permisos que declara `REQ-AUTH` en 1.3

`module_code = 'auth'`, `is_special_category = false` en los dos (`§C.7`).

| `code` | Recurso | Acción | Endpoints que lo exigen |
|--------|---------|--------|-------------------------|
| `mfa.leer` | `mfa` | `leer` | `GET /mfa-compliance`, `GET /mfa-compliance/users` |
| `mfa.eliminar` | `mfa` | `eliminar` | `POST /mfa-resets` |

**Total del módulo tras 1.3: cuatro permisos** (los dos de `bloqueo_cuenta` más estos dos).

**No se declara `mfa.crear` ni `mfa.actualizar`.** Nadie activa el MFA de otra persona: activarlo exige el dispositivo del titular. Declarar `mfa.crear` sugeriría que existe una forma administrativa de dar de alta un factor ajeno, y no la hay ni la pide ningún requisito. Es el mismo criterio con el que §3 no declaró `bloqueo_cuenta.crear`.

### C.3.1 Endpoints sin permiso, a propósito y de forma razonada

| Endpoint | Por qué |
|----------|---------|
| `POST /auth/mfa-verifications` | **Es** el acto de autorización, igual que `POST /auth/session`. Autorizado por la cookie que abrió el desafío (`§C.4`) |
| `POST /auth/mfa-challenges` | Ídem. Actúa sobre el desafío de la propia sesión, jamás sobre otro |
| `GET /auth/mfa` | Por identidad. Devuelve **el estado del portador de la cookie**, sin parámetro de sujeto |
| `POST /auth/mfa-enrollments` | Por identidad. Nadie da de alta un factor ajeno |
| `POST /auth/mfa-factors` | Por identidad, más posesión del código, que es lo que demuestra el control del dispositivo |
| `DELETE /auth/mfa-factors/{public_id}` | Por identidad, más **contraseña actual**. Modelarlo como permiso sería absurdo por el mismo motivo que el logout: un usuario sin permisos no podría gestionar su propia seguridad |
| `POST /auth/mfa-recovery-codes` | Por identidad, más contraseña actual |

---

## C.4 Cómo se autoriza lo que no lleva permiso

§1 enumeraba tres mecanismos (posesión de token, verificación de credencial, identidad del portador). **1.3 añade un cuarto**, y conviene nombrarlo porque no encaja limpiamente en ninguno de los tres:

| Mecanismo | Dónde | Qué garantiza |
|-----------|-------|----------------|
| **Posesión de la sesión que abrió el desafío** | `POST /auth/mfa-verifications`, `POST /auth/mfa-challenges` | El desafío se busca por `(tenant_id, session_id)` **de la petición**, no por un identificador del cuerpo. **No hay sesión autenticada** —`Auth::id()` es `null`— y aun así hay una credencial: la cookie de sesión anónima, que es `httpOnly`, `Secure`, host-only y con CSRF. Presentar el `public_id` del desafío desde otra sesión responde `410`, igual que uno inexistente (`RN-AUTH-53`) |
| **Identidad más reautenticación** | `DELETE /auth/mfa-factors`, `POST /auth/mfa-recovery-codes` | La identidad del portador **no basta** para tocar credenciales: hace falta la contraseña actual, exactamente como en §4.8. Una sesión secuestrada no puede desactivar el segundo factor ni fabricarse códigos de repuesto |

**Es un mecanismo nuevo y hay que revisarlo como tal.** La tentación al implementarlo es aceptar el `public_id` del desafío del cuerpo y buscar por él; eso convierte un identificador que viaja en una respuesta HTTP en una credencial, y basta con un log de proxy o un `Referer` para robarlo. **La búsqueda es por `session_id`, y el `public_id` solo sirve para que el cliente sepa de qué habla.**

---

## C.5 El permiso que este paso obliga a declarar en `REQ-CORE`

**`rol.actualizar`**, `resource = 'rol'`, `action = 'actualizar'`, `is_special_category = false`, `module_code = 'core'`.

Hoy `CoreServiceProvider::declaredPermissions()` declara `'rol' => ['leer']`. Este paso lo convierte en `['leer', 'actualizar']` y concede el nuevo a `administrador_centro` en `ProvisionTenantDefaults::ADMIN_CENTRO_PERMISSIONS`.

**Por qué lo declara `REQ-CORE` y no `REQ-AUTH`.** `roles` es un recurso de `REQ-CORE` (`INV-007`). Un permiso lo declara el módulo dueño del recurso, siempre; si lo declarara `REQ-AUTH`, el test de catálogo de §8 —que comprueba que `permissions` contiene **exactamente** los permisos de cada módulo— empezaría a mentir sobre quién es dueño de qué, y 1.5 tendría que retirarlo y volver a declararlo desde el otro lado.

**Por qué se declara en 1.3 y no se espera a 1.5.** El argumento completo está en `funcional.md §C.2.1` y es operativo: hacer efectivo `mfa_required` sin entregar su interruptor deja a todos los tenants existentes con dos roles obligados y sin forma de cambiarlo durante dos pasos del plan. **Es exactamente el mismo patrón con el que 1.2 usó `configuracion.actualizar` de `REQ-CORE` para `session_timeout_minutes`** (§4.1), con una sola diferencia: allí el permiso ya existía y aquí hay que añadir una palabra a una lista.

**Queda a confirmación del usuario** (`OPEN-AUTH-21`), porque toca el catálogo de otro módulo y eso no es una decisión que deba tomar sola la especificación de este.

### C.5.1 Permisos de otros módulos que este paso usa sin declarar

| Permiso | De | Para qué |
|---------|----|----------|
| `rol.actualizar` | `REQ-CORE` (**declarado en este paso**, `§C.5`) | `PATCH /roles/{public_id}` con `mfa_required` |
| `rol.leer` | `REQ-CORE` | La pantalla de 1.5 necesita listar roles antes de cambiar ninguno |
| `configuracion.leer` / `configuracion.actualizar` | `REQ-CORE` | `mfa_allowed_methods` y `mfa_grace_period_days` en `GET`/`PATCH /tenant/settings` (`api.md §C.6`). **Sin permiso propio**, mismo argumento que §4.1 |
| `usuario.leer` | `REQ-CORE` | **No se usa.** `GET /mfa-compliance/users` devuelve datos de usuario (nombre, correo) pero exige `mfa.leer`, no `usuario.leer` — ver `§C.6.1` |

---

## C.6 Matriz recurso × acción × ámbito

Ámbito único en 1.3: `todos`. `—` significa que el permiso no existe en este módulo.

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `bloqueo_cuenta` (1.2) | — | `todos` | — | `todos` | — | — | — | — | — |
| `mfa` | — (§C.3) | `todos` | — (§C.3) | `todos` | — | — | — | — | — |

**`mfa` sin `exportar`, y merece decirse.** Un CSV con quién tiene y quién no tiene segundo factor es un mapa de las cuentas más fáciles de atacar en el centro, ordenado por facilidad. `REQ-AUTH-003` pide que el estado sea *consultable*, no exportable, y la diferencia entre las dos cosas es un fichero que sale del sistema y acaba en un correo. Si algún día se pide, es un requisito nuevo con su propio permiso y su propia auditoría de exportación (`REQ-CORE-005` ya tiene el mecanismo).

### C.6.1 Por qué `GET /mfa-compliance` y `GET /mfa-compliance/users` exigen `mfa.leer` y no `rol.leer` ni `usuario.leer`

Los dos comparten permiso, pero por motivos distintos — conviene separarlos, no tratarlos como si fuera el mismo argumento repetido dos veces.

**`GET /mfa-compliance`** devuelve **solo recuentos agregados** por rol (obligados, inscritos, en gracia, exigibles) — nunca nombres ni correos de usuarios individuales (`api.md §C.5`, `funcional.md §C.1.1` punto 9). Aun así merece permiso propio y no uno prestado, por dos motivos:

1. **El recuento en sí es información de ataque.** Cuántos usuarios de un rol carecen de segundo factor es un indicador de qué cuenta conviene atacar primero, aunque no diga cuál. `rol.leer` (que casi cualquier rol de gestión tiene, `§C.7.6`) no es el permiso pensado para exponer eso.
2. **La vista previa hipotética escribe una decisión antes de guardarla.** `?mfa_required=true` simula el efecto de una `PATCH /roles` que todavía no se ha hecho. Es una consulta sobre una decisión de seguridad, no sobre el catálogo de roles: por eso el permiso es del recurso `mfa`, no de `rol`.

**`GET /mfa-compliance/users`** (restaurado en 1.3 el 2026-08-27, `§C.1`) sí es distinto: devuelve nombre y correo de usuarios (`api.md §C.5`), que es justo lo que `usuario.leer` gobierna. Y aun así el permiso es `mfa.leer`, no `usuario.leer`, por dos motivos — el argumento original de la especificación, restaurado junto con el endpoint:

1. **Lo que hace peligroso a ese listado no son los nombres, es el filtro por `state`.** `usuario.leer` lo tienen `direccion`, `secretaria` y `administrativo` en 1.1. Reutilizarlo aquí daría a esos tres roles la lista de **quién no tiene segundo factor**, que es información de ataque, no de gestión.
2. **Un permiso más restrictivo no se puede obtener componiendo dos menos restrictivos.** Exigir `mfa.leer` **y** `usuario.leer` sería más correcto en teoría y peor en la práctica: dos comprobaciones para una pantalla, y el día que alguien simplifique quitará la que no entienda.

`mfa.leer` es, por tanto, **más restrictivo que `usuario.leer` y no lo sustituye**: quien lo tiene ve estos datos en este contexto —cumplimiento, agregado o individualizado— y nada más.

---

## C.7 Asignación en los roles predefinidos

Los 16 roles de tenant se siembran en `tenant:provision-defaults`. **1.3 no crea ni modifica ningún rol**: añade concesiones y lee un atributo que ya existe.

Denegación por defecto (`RPERM-011`): lo que no aparece, no se concede.

| Rol (`code`) | Permisos de `REQ-AUTH` en 1.3 | Ámbito |
|--------------|-------------------------------|--------|
| `administrador_centro` | `mfa.leer`, `mfa.eliminar`, **más `rol.actualizar` de `REQ-CORE`** | `todos` |
| Los 15 restantes | — | — |

### C.7.1 Por qué solo el Administrador de Centro, otra vez

§5.1 argumentó que desbloquear una cuenta es un acto de recuperación de acceso y por eso no se reparte. **Restablecer el MFA de alguien es lo mismo elevado al cuadrado**: quien puede hacerlo puede retirar el segundo factor de la cuenta de Dirección y, con el control del buzón o una contraseña filtrada, entrar. Es la capacidad más peligrosa que este módulo ha declarado hasta hoy.

Los candidatos que uno pensaría primero se descartan con el mismo argumento reforzado:

- **`secretaria` y `administrativo`** son quienes reciben las llamadas de «he perdido el móvil», y por eso mismo son el objetivo natural de una llamada falsa. El requisito exige *«verificación previa de identidad»*, y una verificación telefónica hecha por quien está deseando resolver la incidencia no es una verificación.
- **`direccion`** tiene `usuario.leer` y ninguna escritura sobre cuentas. Darle el restablecimiento de MFA sería su primera capacidad de escritura sobre credenciales ajenas, y llegaría por la puerta de atrás.

Si un centro concreto necesita repartirlo, **1.5 lo permitirá con un rol personalizado**: la decisión la toma el centro, con nombre y apellidos, y queda en auditoría. No es un valor por defecto de la plataforma.

### C.7.2 `soporte_plataforma`

**Sin permisos de `REQ-AUTH`**, igual que en 1.1, 1.2 y 1.2b. Un rol del proveedor capaz de restablecer el MFA de cualquier usuario de cualquier centro sería una llave maestra permanente sobre la autenticación de todo el producto. Su acceso real es *impersonation* auditada (`REQ-SUP-003`), y 1.6 le dará lo que necesite por su propia vía y con su propio registro.

**Su `mfa_required = true` sí empieza a tener efecto** en este paso, que es lo que 1.1 sembró y `permisos.md §5.4` avisó de que nadie comprobaba todavía.

### C.7.3 `super_administrador`

**No es una fila de `roles`** (`ADR-034 §2`). `REQ-BO-007` exige MFA sin conmutador para el backoffice, y eso es **1.6**, no 1.3 (`funcional.md §C.1.2`).

### C.7.4 `mfa_obligatorio` (`RPERM-014`) — la nota de §5.4, cerrada

§5.4 escribió, con razón, que al cerrar 1.2 existía *«un atributo `mfa_required = true` en la base de datos que nada comprueba todavía»* y que *«un administrador con esa marca inicia sesión solo con contraseña»*.

**1.3 cierra esa nota.** A partir de este paso el atributo se lee en cada evaluación de `MfaPolicy` (`RN-AUTH-62`) y tiene consecuencia: obligación, plazo de gracia y muro. Con dos consecuencias de despliegue que `operacion.md §C.6` desarrolla y que aquí solo se enuncian:

1. **En todos los tenants existentes, `administrador_centro` y `soporte_plataforma` pasan a estar obligados el día del despliegue**, con siete días de gracia contados desde ese momento.
2. **Ese es el único momento del proyecto en que la obligación se activa sin que nadie la haya pedido**, porque el valor lo puso la siembra de 1.1 y no una decisión del centro. Merece aviso previo, y es exactamente por eso que `funcional.md §C.2.1` insiste en que el interruptor tiene que llegar en el mismo paso.

### C.7.5 `acceso_datos_especiales` (`RPERM-015`)

Sin cambios. `REQ-AUTH` sigue sin exponer categoría especial (`§C.9`).

### C.7.6 Ámbitos en 1.3: por qué los dos son `todos`

Rige la **regla de seguridad** de §5.6, que sigue en vigor sin matices: entre 1.1 y 1.5, el resolutor provisional de `ADR-034 §2` **lee `permission_role.effect` e ignora `permission_role.scope`**. Una concesión con ámbito `propios` se evalúa hoy exactamente igual que una con ámbito `todos`.

Aplicado a este paso, y el ejemplo es peor que el de 1.2: sembrar `mfa.leer` con ámbito `propios` —pensando en «que cada uno vea su estado»— daría a ese rol **el recuento agregado de cumplimiento de todo el centro, y desde la restauración de `GET /mfa-compliance/users`, la identidad de quién no cumple**. Es decir, exactamente la información que `§C.6.1` acaba de argumentar que no se reparte, entregada por un ámbito que nadie evalúa.

Reglas derivadas, verificables:

1. **Toda fila de `permission_role` creada en 1.3 lleva `scope = 'todos'`** (`RN-CORE-22`). Verificado por el test de catálogo de `§C.10`.
2. **El autoservicio no se modela como permiso con ámbito.** `GET /auth/mfa` no es `mfa.leer` con ámbito `propios`: es una comprobación de identidad que no pasa por el resolutor. Es la misma regla 2 de §5.6, y sigue siendo la que evita que un ámbito no evaluado abra un listado.
3. **1.5 hereda la responsabilidad** de introducir los ámbitos restringidos junto con el resolutor que los evalúa, en el mismo paso. Nunca antes.

---

## C.8 Reglas de autorización que no son un permiso

Ampliación de §7 y `§B.4`. Es la parte de este documento que la revisión de seguridad debe recorrer entera, ahora con ocho filas más.

| Regla | Dónde | Efecto |
|-------|-------|--------|
| **`RN-AUTH-52` — un usuario con segundo factor pendiente no está autenticado** | Todo el producto | Entre el paso 1 y el paso 2, `Auth::id()` es `null` y **cualquier** endpoint autenticado responde `401`. No hay bandera, no hay lista de excepciones y no hay sesión «a medias» que algún camino futuro pueda dar por buena (`funcional.md §C.6`) |
| **`RN-AUTH-53` — el desafío se busca por `session_id`, no por `public_id`** | `POST /auth/mfa-verifications`, `POST /auth/mfa-challenges` | Un `public_id` que viaja en una respuesta HTTP **no es una credencial**. Buscar por él convertiría un log de proxy en una toma de sesión. Un desafío de otra sesión ⇒ `410`, idéntico a inexistente |
| **`RN-AUTH-63` — el contador de bloqueo solo se pone a cero con un login completo** | Paso 1 y paso 2 | Sin esto, repetir el paso 1 antes de cada intento de código da **intentos ilimitados** contra seis dígitos. Es el fallo más fácil de introducir de todo el paso, y es una regresión sobre código de 1.2 que hoy funciona bien y que hay que **cambiar** (`funcional.md §C.4.4.2`) |
| **`RN-AUTH-61` — nadie desactiva un factor que su rol exige** | `DELETE /auth/mfa-factors` | `409`, literal del requisito. La comprobación es `MfaPolicy::resolve()`, no una lectura suelta de `roles.mfa_required` en el controlador |
| **`RN-AUTH-67` — nadie restablece su propio MFA** | `POST /mfa-resets` | `403`. Sin esta regla, `mfa.eliminar` **es** el interruptor de apagado de toda la obligatoriedad: quien lo tiene se quita el factor cuando quiera. Es el equivalente de `RN-AUTH-19` («nadie se desbloquea a sí mismo»), con la diferencia de que aquí **sí es alcanzable** —el administrador está autenticado— y por tanto hay que comprobarlo de verdad, no confiar en que sea imposible por construcción. La mitad de la regla sobre exenciones («ni se exime a sí mismo») queda pendiente de `1.3b`, que es quien entrega `POST /mfa-exemptions` (`§C.1`) |
| **`RN-AUTH-73` — el autoservicio nunca acepta un sujeto por parámetro** | Los siete de `§C.3.1` | Ni `user_id` en el cuerpo, ni en la ruta, ni en la cadena de consulta. El sujeto **es** el portador de la cookie. Un `public_id` de usuario en un `FormRequest` de autoservicio de este módulo es un fallo de revisión |
| **La propiedad del factor va en el `WHERE`, no en un `if`** | `DELETE /auth/mfa-factors/{public_id}` | Misma regla que `RN-AUTH-41` para las sesiones. Un `find()` seguido de `if ($factor->user_id !== $user->id)` es un fallo de revisión: basta que un camino futuro olvide el `if`. Factor de otro usuario ⇒ `404`, nunca `403` |
| **`RN-AUTH-71` — la cookie `pge_device` no salta ningún control de segundo factor** | Login | Decisión explícita de este paso, que `RN-AUTH-45` obligaba a tomar y prohibía heredar. Un dispositivo reconocido sirve para no alertar, y para nada más |
| **`RN-AUTH-55`/`RN-AUTH-56` — el secreto y los códigos salen una vez y nunca más** | Respuestas, auditoría, logs, *payloads* encolados | El secreto TOTP sale en la respuesta del alta; los códigos, en la que los genera. **Ninguna otra respuesta del producto los contiene**, y la redacción en auditoría se declara a mano además de encajar en el patrón global (`datos.md §C.2`, `§C.3`) |
| **El muro de alta es una lista blanca** | *Middleware* `RequireMfaEnrollment` | `INV-002`. Un endpoint nuevo de cualquier módulo queda bloqueado por defecto para una sesión restringida, que es el comportamiento correcto. **`DELETE /auth/session` está siempre permitido**: un muro del que no se puede salir ni cerrando sesión es un secuestro |

Y siguen en vigor, sin excepción, las ocho de §7 y las cinco de `§B.4`: `RN-AUTH-06` (el `tenant_id` sale del host), `RN-AUTH-07` (predicado explícito además de RLS), `RN-AUTH-29` (CSRF en toda escritura, **incluidas las dos del desafío**) y las demás.

---

## C.9 Datos de categoría especial

**Sigue sin haberlos.** `REQ-AUTH` no expone salud, NEAE ni convivencia, y ninguno de sus cuatro permisos lleva `is_special_category = true`. La auditoría reforzada de lectura de `RPERM-015` no se dispara aquí.

Lo que 1.3 **sí** añade al inventario de datos sensibles del módulo, y que no hay que confundir con «no sensible»:

- **Credenciales de segundo factor.** Un secreto TOTP es material que permite generar códigos válidos indefinidamente. Es la primera columna **cifrada en reposo** del producto (`datos.md §C.2`), y su custodia depende de `APP_KEY` (`datos.md §C.11.1`, `OPEN-AUTH-26`).
- **Un mapa de qué cuentas están peor protegidas — y, desde 1.3, de quiénes son.** `GET /mfa-compliance` da el recuento por rol; `GET /mfa-compliance/users` (restaurado el 2026-08-27) da la identidad de cada persona detrás de ese recuento. Los dos son información de ataque: dicen a qué rol, y a quién exactamente, conviene atacar primero. Por eso `mfa.leer` es un permiso propio y estrecho, más restrictivo que `usuario.leer` (`§C.6.1`), y por eso `mfa` **no tiene `exportar`** (`§C.6`).
- **Texto libre escrito por un administrador sobre otra persona**, en `mfa_resets.reason`. No es categoría especial por sí mismo, pero puede contenerla según lo que se escriba («perdió el móvil ingresado en el hospital»). Se borra con la persona, solo lo lee quien tiene el permiso, y el manual de administración debe advertir de que se registra y de quién puede leerlo (`datos.md §C.11`). `user_mfa_exemptions.reason` tendrá la misma consideración cuando `1.3b` entregue el endpoint que lo escribe; la columna existe ya (`§C.1`) pero nadie la usa todavía.

---

## C.10 Verificación

- **`CA-AUTH-140`** — los endpoints de administración de este paso (`GET /mfa-compliance`, `POST /mfa-resets`): `401` sin sesión, `403` sin permiso, `404` sobre recurso de otro tenant con **cuerpo idéntico**, `419`/`403` sin CSRF en las escrituras. `GET /mfa-compliance/users`, restaurado el 2026-08-27, no tiene CA numerado propio: la misma comprobación de sesión/permiso/aislamiento está en su test dedicado de `MfaAdministrationTest.php` (referenciado como `REQ-AUTH-003`, no un CA nuevo — `funcional.md §C.13`).
- **`CA-AUTH-138`** — un administrador con `mfa.eliminar` **no puede restablecerse a sí mismo**: `403` (`RN-AUTH-67`).
- **`CA-AUTH-115`** — con segundo factor pendiente, **cualquier** endpoint autenticado responde `401` (`RN-AUTH-52`).
- **`CA-AUTH-117`** — desafío presentado desde otra sesión: `410` con el mismo cuerpo que uno inexistente (`RN-AUTH-53`).
- **`CA-AUTH-129`** — con sesión restringida, solo responden los siete endpoints de la lista blanca; **cualquier otro** devuelve `403 urn:pge:error:mfa-enrollment-required`.
- **`CA-AUTH-141`** — un dispositivo reconocido **no salta** el segundo factor (`RN-AUTH-71`).
- **`CA-AUTH-109`** — ninguna respuesta del producto contiene el secreto, el hash de un código de respaldo ni un código en claro fuera de las dos respuestas que los emiten.
- **`CA-AUTH-135`** — `PATCH /roles/{public_id}` exige `rol.actualizar` y **rechaza con `422` cualquier campo que no sea `mfa_required`**.
- **`CA-AUTH-145`** — ninguna ruta de este módulo lleva `module-enabled`, **incluidas las diez nuevas**.
- **Test de catálogo, ampliado**: tras `platform:sync-registry`, `permissions` contiene **exactamente cuatro** filas con `module_code = 'auth'` —`bloqueo_cuenta.leer`, `bloqueo_cuenta.eliminar`, `mfa.leer`, `mfa.eliminar`—, ninguna con `retired_at`, ninguna con `is_special_category = true`, y **ninguna fila de `permission_role` de este módulo con `scope` distinto de `todos`**.
- **Test de catálogo de `REQ-CORE`, ampliado en una fila**: `rol.actualizar` existe con `module_code = 'core'` y está concedido **solo** a `administrador_centro`. Si aparece declarado con `module_code = 'auth'`, alguien ha puesto el permiso en el módulo equivocado (`§C.5`).

---

# Parte D · Paso 1.3b · Permisos (`REQ-AUTH-003`)

> **Estructura**: §1-§8 son 1.2 (cerrado). `§B.1`-`§B.6` son 1.2b (cerrado). `§C.1`-`§C.10` son 1.3 (cerrado y mezclado, commit `cd13e8a`). Esta **Parte D** es el paso **1.3b**, **implementada y cerrada** el 2026-08-31 (PR [#123](https://github.com/pirexia/plataforma-educativa/pull/123), commit `dd68f48`).
>
> Fuente de verdad del catálogo: **el código** (`AuthServiceProvider::declaredPermissions()`), materializado por `platform:sync-registry`. Esta tabla es su reflejo documental.

---

## D.1 La conclusión, primero

`§C.1` decía que *«1.3 rompe la racha»* declarando dos permisos donde el módulo no tenía casi ninguno. **1.3b declara tres más, todos del mismo recurso, y cierra el hueco que la partición de `OPEN-AUTH-24` dejó abierto**: `permisos.md §C.1` anotó que el recurso `exencion_mfa` quedaba en 1.3b y que *«ningún endpoint de 1.3 escribe en `user_mfa_exemptions` todavía»*. Este paso lo cambia.

Lo que **no** cambia:

- **De los seis endpoints de autoservicio y desafío que 1.3b modifica, ninguno gana permiso.** Siguen autorizándose por identidad del portador o por la cookie del desafío, con los cuatro mecanismos de `§C.4` sin ampliación.
- **El ámbito sigue siendo `todos` en todo lo que este paso siembra**, por el mismo motivo estructural de `§5.6`/`§C.7.6`: el resolutor provisional de `ADR-034 §2` **ignora `scope`** hasta 1.5.
- **`REQ-AUTH` sigue sin exponer categoría especial** (`§D.6`).
- **No se toca el catálogo de `REQ-CORE`**, a diferencia de 1.3 (`rol.actualizar`, `§C.5`). 1.3b es un paso enteramente dentro de su módulo.

---

## D.2 Recursos que aporta el paso

| Recurso | Qué representa |
|---------|----------------|
| `exencion_mfa` | La **excepción temporal nominal** a la obligatoriedad de MFA: quién está exento, por qué, hasta cuándo y quién lo decidió |

**Uno**, y ya estaba nombrado en `§C.4.11` y `§C.2` de 1.3 — no se inventa aquí. Las **acciones** son las de `RPERM-003` sin excepción.

**Por qué un recurso propio y no acciones nuevas sobre `mfa`.** Podría parecer que conceder una excepción es «actualizar el MFA de alguien» y que bastaría con `mfa.actualizar`. Se descarta por dos motivos:

1. **La excepción es una entidad con vida propia**, con motivo, caducidad, autor y traza de revocación — exactamente el mismo argumento por el que `AccountLockout` es un recurso y no un atributo del usuario (`funcional.md §10.1`), y por el que `MfaReset` es una tabla y no una fila de `audit_logs` (`datos.md §C.6.1`). Un recurso con ciclo de vida propio se gobierna con sus propios permisos.
2. **Separarlo permite repartirlo distinto.** Un centro puede querer que dirección **vea** quién está exento sin poder **conceder** exenciones. Con `mfa.actualizar` eso no se puede expresar; con tres acciones sobre `exencion_mfa`, sí — el día que 1.5 permita roles personalizados.

**Por qué `mfa` no gana ninguna acción nueva.** Sigue sin `crear` y sin `actualizar` por el argumento de `§C.3`: nadie activa ni modifica el MFA de otra persona. **Y sigue sin `exportar`** por el de `§C.6`: un CSV de quién no tiene segundo factor es un mapa de ataque. **La excepción tampoco tiene `exportar`, y por el mismo motivo elevado**: una lista de exentos es la lista de las cuentas privilegiadas que hoy entran solo con contraseña.

---

## D.3 Catálogo de permisos que declara `REQ-AUTH` en 1.3b

`module_code = 'auth'`, `is_special_category = false` en los tres (`§D.6`).

| `code` | Recurso | Acción | Endpoints que lo exigen |
|--------|---------|--------|-------------------------|
| `exencion_mfa.crear` | `exencion_mfa` | `crear` | `POST /mfa-exemptions` |
| `exencion_mfa.leer` | `exencion_mfa` | `leer` | `GET /mfa-exemptions` |
| `exencion_mfa.eliminar` | `exencion_mfa` | `eliminar` | `DELETE /mfa-exemptions/{public_id}` |

**Total del módulo tras 1.3b: siete permisos** — los dos de `bloqueo_cuenta` (1.2), los dos de `mfa` (1.3) y estos tres.

**Por qué `eliminar` y no `actualizar` para la revocación.** Revocar deja la fila con `revoked_at` y no borra nada (`RN-AUTH-83`), así que en rigor es una actualización. Se declara `eliminar` porque **es el mismo criterio que ya usa el módulo dos veces**: `bloqueo_cuenta.eliminar` levanta un bloqueo que tampoco borra la fila (§2), y `mfa.eliminar` restablece un MFA que borra lógicamente (`§C.2`). El permiso describe **lo que el actor hace desde fuera** —retirar algo que estaba vigente—, no la operación SQL que ocurre dentro. Cambiar de criterio ahora obligaría a explicar por qué tres cosas iguales se llaman distinto.

**Por qué `leer` es un permiso separado y no va incluido en `crear`.** Denegación por defecto (`RPERM-011`, `INV-002`): quien puede conceder no obtiene por arrastre el derecho a leer el histórico de motivos escritos por otros administradores sobre otras personas. Son dos capacidades y son dos filas.

### D.3.1 Endpoints sin permiso, sin cambios

Los siete de `§C.3.1` siguen exactamente igual, incluidos los cinco que 1.3b modifica (`POST /auth/mfa-enrollments`, `POST /auth/mfa-factors`, `DELETE /auth/mfa-factors/{public_id}`, `POST /auth/mfa-challenges`, `POST /auth/mfa-verifications`) y `GET /auth/mfa`.

**El que hay que mirar dos veces es `GET /auth/mfa`**, porque 1.3b le añade tres campos (`api.md §D.3.1`) y uno de ellos —`exempt_until`— es información sobre una decisión administrativa. **Sigue sin permiso, y es correcto**: devuelve **el estado del portador de la cookie**, sin parámetro de sujeto (`RN-AUTH-73`), y decirle a una persona que no se le exige MFA hasta el 30 de septiembre no revela nada de nadie más. Lo que **no** hace es exponer el motivo ni quién la concedió: eso vive en `GET /mfa-exemptions`, que sí tiene permiso.

---

## D.4 Cómo se autoriza lo que no lleva permiso

**Sin mecanismos nuevos.** Los cuatro de `§1` y `§C.4` siguen siendo los únicos: posesión de token, verificación de credencial, identidad del portador y **posesión de la sesión que abrió el desafío**.

Este paso amplía el cuarto en un punto que conviene decir porque es donde se cometería el error: **el reenvío de un código sigue autorizándose por `session_id`, igual que la verificación** (`RN-AUTH-53`). La tentación en implementación es aceptar el `public_id` del desafío en el cuerpo del reenvío —«total, solo manda un correo»— y eso convertiría un identificador que viaja en una respuesta HTTP en **una palanca para enviar correos a la dirección de otra persona**, sin autenticarse. La búsqueda es por `session_id`, siempre, y el `public_id` solo sirve para que el cliente sepa de qué habla.

---

## D.5 Matriz recurso × acción × ámbito

Ámbito único en 1.3b: `todos`. `—` significa que el permiso no existe en este módulo.

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `bloqueo_cuenta` (1.2) | — | `todos` | — | `todos` | — | — | — | — | — |
| `mfa` (1.3) | — (`§C.3`) | `todos` | — (`§C.3`) | `todos` | — (`§C.6`) | — | — | — | — |
| **`exencion_mfa`** (1.3b) | `todos` | `todos` | — (`§D.3`) | `todos` | — (`§D.2`) | — | — | — | — |

**`exencion_mfa` sin `actualizar`, y merece decirse.** No hay prórroga (`funcional.md §D.1.2`): alargar una excepción es revocarla y conceder otra, con dos filas de auditoría en lugar de una edición silenciosa. En un mecanismo cuya única función es **relajar una obligación de seguridad**, que cada decisión deje su propia fila no es burocracia: es la diferencia entre «se le concedieron tres excepciones de un mes» y «alguien editó una fecha cuatro veces».

---

## D.6 Asignación en los roles predefinidos

Los 16 roles de tenant se siembran en `tenant:provision-defaults`. **1.3b no crea ni modifica ningún rol**: añade tres concesiones.

Denegación por defecto (`RPERM-011`): lo que no aparece, no se concede.

| Rol (`code`) | Permisos de `REQ-AUTH` que gana en 1.3b | Ámbito |
|--------------|------------------------------------------|--------|
| `administrador_centro` | `exencion_mfa.crear`, `exencion_mfa.leer`, `exencion_mfa.eliminar` | `todos` |
| Los 15 restantes | — | — |

### D.6.1 Por qué solo el Administrador de Centro, por tercera vez

`§5.1` lo argumentó para el desbloqueo y `§C.7.1` para el restablecimiento. **Con la excepción el argumento es el más fuerte de los tres**, y conviene verlo entero porque es contraintuitivo: parece la operación más inocua de las tres —no toca credenciales, no borra nada, caduca sola— y es la más peligrosa.

> Restablecer el MFA de alguien le deja **sin** segundo factor y **obligado**: en cuanto entre, el muro le exige darlo de alta otra vez. **Conceder una excepción le deja sin segundo factor y sin obligación**, durante hasta 90 días, y además le permite **desactivar el factor que tuviera** (`§C.4.11` punto 3). Es la única operación del producto que apaga la obligatoriedad de `REQ-AUTH-003` para una persona concreta sin dejarla en un estado que el sistema empuje a corregir.

De ahí las tres consecuencias que este paso fija y no negocia:

1. **Solo `administrador_centro`**, con el mismo razonamiento reforzado de `§C.7.1` sobre `secretaria`, `administrativo` y `direccion`: quien recibe la llamada de «no puedo activar el MFA» es exactamente el objetivo de una llamada falsa, y aquí la llamada falsa no pide un desbloqueo temporal sino **tres meses sin segundo factor**.
2. **Nadie se concede una excepción a sí mismo** (`RN-AUTH-81`). Sin esa regla, `exencion_mfa.crear` **es** el interruptor de apagado de la obligatoriedad para quien lo tiene: se concede 90 días, desactiva su factor y ya está. Es el mismo agujero que `RN-AUTH-67` cerró en el restablecimiento, y aquí es peor porque no deja al actor en estado obligado.
3. **`soporte_plataforma` sigue sin ningún permiso de `REQ-AUTH`**, igual que en 1.1, 1.2, 1.2b y 1.3. Un rol del proveedor capaz de eximir de MFA a cualquier usuario de cualquier centro sería una llave maestra con caducidad de 90 días y renovable.

Si un centro necesita repartirlo —por ejemplo, que secretaría **vea** las exenciones sin poder concederlas—, **1.5 lo permitirá con un rol personalizado**: la decisión la toma el centro, con nombre y apellidos, y queda en auditoría. Y para eso hace falta que `leer` sea un permiso separado, que es justo lo que `§D.3` argumenta.

### D.6.2 Ámbitos en 1.3b: por qué los tres son `todos`

Rige la **regla de seguridad** de `§5.6`, sin matices: entre 1.1 y 1.5 el resolutor provisional **lee `effect` e ignora `scope`**.

Aplicado a este paso, el ejemplo es tan malo como el de `§C.7.6`: sembrar `exencion_mfa.leer` con ámbito `propios` —pensando en «que cada uno vea la suya»— daría a ese rol **la lista completa de exentos del centro, con motivo y autor**, entregada por un ámbito que nadie evalúa. Y «que cada uno vea la suya» no se modela así: se modela con `GET /auth/mfa`, que devuelve `exempt_until` del portador y **no pasa por el resolutor** (`§D.3.1`, regla 2 de `§5.6`).

**Toda fila de `permission_role` creada en 1.3b lleva `scope = 'todos'`** (`RN-CORE-22`), verificado por el test de catálogo de `§D.8`.

### D.6.3 La pantalla de administración **no añade ningún permiso**

`OPEN-AUTH-28` se resolvió el 2026-08-27 por incluir en 1.3b una pantalla mínima de administración de MFA (`funcional.md §D.1.3`). **No aporta ni un permiso nuevo ni un endpoint nuevo** (`api.md §D.5.1`): consume los que ya existen.

| Área de la pantalla | Permiso que exige el servidor | Declarado en |
|---------------------|-------------------------------|--------------|
| Cumplimiento (agregado, individualizado y vista previa) | `mfa.leer` | 1.3 (`§C.3`) |
| Conmutador de `mfa_required` del rol | `rol.actualizar` (**de `REQ-CORE`**) | 1.3 (`§C.5`) |
| Restablecimiento de MFA | `mfa.eliminar` | 1.3 (`§C.3`) |
| Excepciones: conceder / listar / revocar | `exencion_mfa.crear` / `.leer` / `.eliminar` | **1.3b** (`§D.3`) |

**`administrador_centro` ya tiene los seis** tras `§D.6`, así que la pantalla funciona sin tocar el aprovisionamiento más allá de las tres concesiones nuevas.

Tres reglas de esta pantalla que son de autorización y no de interfaz:

1. **La denegación por defecto sigue viviendo en el servidor** (`INV-002`). La ruta de la SPA no es un control de acceso: si un usuario sin permiso la abre, el endpoint responde `403` y la pantalla lo muestra. **Ocultar un botón no autoriza nada**, y enseñarlo tampoco desautoriza: las dos cosas se deciden en el *middleware* `permission:`.
2. **Cada área comprueba su propio permiso, y no hay un «permiso de pantalla».** Alguien con `mfa.leer` pero sin `exencion_mfa.crear` ve el cumplimiento y recibe `403` al intentar conceder una excepción. Ese caso **no existe hoy** entre los roles predefinidos —`administrador_centro` los tiene todos— pero existirá en cuanto 1.5 traiga los roles personalizados, y la pantalla tiene que comportarse bien entonces sin rehacerse.
3. **La pantalla no es un camino alternativo a nada.** Todo lo que hace pasa por los mismos endpoints, con los mismos permisos, la misma CSRF y la misma auditoría que una llamada por API (`INV-006`: la UI es un cliente más).


---

## D.7 Reglas de autorización que no son un permiso

Amplía `§7`, `§B.4` y `§C.8`. Es la parte que la revisión de seguridad debe recorrer entera, ahora con cinco filas más.

| Regla | Dónde | Efecto |
|-------|-------|--------|
| **`RN-AUTH-81` — nadie se exime a sí mismo** | `POST /mfa-exemptions` | `403`. Es la mitad que `§C.8` dejó pendiente de este paso. Sin ella, `exencion_mfa.crear` es el interruptor de apagado de la obligatoriedad (`§D.6.1`) |
| **El sujeto de la excepción va en el `WHERE`, resuelto con predicado de tenant explícito** | Los tres de `/mfa-exemptions` | `RN-AUTH-07`. Un `public_id` de usuario de otro tenant ⇒ `404`, nunca `403`, nunca un registro creado. Es la única entrada de este paso que acepta un sujeto ajeno, y por eso es la que hay que revisar (`RN-AUTH-73`) |
| **La excepción se busca por `public_id` + predicado de tenant, y la propiedad no se comprueba con un `if`** | `DELETE /mfa-exemptions/{public_id}` | Misma regla que `RN-AUTH-41` y que `§C.8` para los factores. Un `find()` seguido de una comprobación en PHP es un fallo de revisión |
| **`RN-AUTH-84` — el código entregado no sale por ninguna respuesta** | Alta, desafío, `GET /auth/mfa`, auditoría, logs, *payloads* | El único sitio donde el código aparece es el correo al titular. Su hash no aparece en ninguno, y se declara secreto a mano en el modelo (`datos.md §D.2`) |
| **El reenvío se autoriza por `session_id`, no por el `public_id` del desafío** | `POST /auth/mfa-challenges` | `§D.4`. Aceptar el `public_id` convertiría ese endpoint en un enviador de correos a direcciones ajenas sin autenticación |

Y siguen en vigor, sin excepción, las de `§7`, `§B.4` y `§C.8`: `RN-AUTH-06` (el `tenant_id` sale del host), `RN-AUTH-07`, `RN-AUTH-29` (CSRF en toda escritura, **incluidas las dos del desafío y las dos nuevas de excepciones**), `RN-AUTH-52`, `RN-AUTH-53`, `RN-AUTH-61`, `RN-AUTH-63`, `RN-AUTH-67`, `RN-AUTH-71` y `RN-AUTH-73`.

---

## D.8 Datos de categoría especial

**Sigue sin haberlos.** `REQ-AUTH` no expone salud, NEAE ni convivencia, y ninguno de sus **siete** permisos lleva `is_special_category = true`. La auditoría reforzada de lectura de `RPERM-015` no se dispara aquí.

Lo que 1.3b **sí** añade al inventario de datos sensibles del módulo, ampliando `§C.9`:

- **La lista de personas exentas de segundo factor.** Es el mapa de ataque de `§C.9` en su versión más concentrada: no «quién no ha activado todavía», sino **quién está autorizado a no tener**, con fecha de fin. Por eso `exencion_mfa.leer` es un permiso propio y estrecho y por eso el recurso **no tiene `exportar`** (`§D.5`).
- **Texto libre escrito por un administrador sobre otra persona**, en `user_mfa_exemptions.reason`. `permisos.md §C.9` ya anticipó que *«tendrá la misma consideración cuando 1.3b entregue el endpoint que lo escribe»*, y aquí se cumple: no es categoría especial por sí mismo, pero puede contenerla según lo que se escriba («no tiene móvil porque está de baja por…»). Se borra con la persona, solo lo lee quien tiene el permiso, y **el manual de administración debe advertir de que se registra, de quién puede leerlo y de que no debe contener datos de salud** — exactamente igual que con `mfa_resets.reason`.
- **La dirección de correo como destino de un factor.** Ya está en el sistema desde 1.1; lo que cambia es que ahora **es material de autenticación**, y por eso solo sale enmascarada (`RN-AUTH-84`, `funcional.md §D.4.5`) incluso hacia quien ya acertó la contraseña.

---

## D.9 Verificación

- **`CA-AUTH-166`** — los tres endpoints de excepciones: `401` sin sesión, `403` sin el permiso correspondiente, `404` sobre usuario o excepción de otro tenant con **cuerpo idéntico**, `419`/`403` sin CSRF en las dos escrituras.
- **`CA-AUTH-161`** — un administrador con `exencion_mfa.crear` **no puede concederse una excepción a sí mismo** (`403`), y **sí puede revocar la suya** (`RN-AUTH-81`).
- **`CA-AUTH-162`** — una segunda excepción viva sobre el mismo usuario responde `409`, **no un error de base de datos**.
- **`CA-AUTH-165`** — revocar conserva la fila, escribe `revoked_at`/`revoked_by`, produce una fila de auditoría `updated` (no `deleted`) y la segunda revocación responde `404`.
- **`CA-AUTH-152`** — ninguna respuesta del producto contiene el código entregado ni su hash, y el destino sale siempre enmascarado.
- **`CA-AUTH-168`** — ninguna ruta de este módulo lleva `module-enabled`, **incluidas las tres nuevas**.
- **`CA-AUTH-169` — test de catálogo, ampliado**: tras `platform:sync-registry`, `permissions` contiene **exactamente siete** filas con `module_code = 'auth'` —`bloqueo_cuenta.leer`, `bloqueo_cuenta.eliminar`, `mfa.leer`, `mfa.eliminar`, `exencion_mfa.crear`, `exencion_mfa.leer`, `exencion_mfa.eliminar`—, ninguna con `retired_at`, ninguna con `is_special_category = true`, y **ninguna fila de `permission_role` de este módulo con `scope` distinto de `todos`**.
- **Test de concesión**: los tres permisos nuevos están concedidos **solo** a `administrador_centro`. Si aparecen en cualquier otro rol predefinido, alguien ha repartido la capacidad de apagar la obligatoriedad de MFA (`§D.6.1`).
- **`CA-AUTH-176`** — la pantalla `/administracion/mfa` con los permisos exigidos responde en sus cuatro áreas, y **sin ellos el servidor responde `403`** sin que la pantalla lo oculte ni redirija al login (`§D.6.3`).
- **Test de catálogo de `REQ-CORE`: sin cambios.** 1.3b **no toca** el catálogo de otro módulo, a diferencia de 1.3 — **tampoco por la pantalla de administración**, que consume `rol.actualizar` tal como 1.3 lo declaró (`§D.6.3`). Si aparece una fila nueva con `module_code = 'core'` en esta rama, es un error.

---

# Parte E · Paso 1.4 · Permisos (`REQ-AUTH-002`)

> **Estructura**: §1-§8 son 1.2, `§B.*` es 1.2b, `§C.*` es 1.3 y `§D.*` es 1.3b, los cuatro cerrados. Esta **Parte E** es el paso **1.4**, **implementada** (2026-09-01, rama `feature/REQ-AUTH-002-google-login-fusion-cuentas`, PR [#143](https://github.com/pirexia/plataforma-educativa/pull/143)): describe la autorización tal como existe, en revisión independiente antes de mezclar.
>
> Fuente de verdad del catálogo: **el código** (`AuthServiceProvider::declaredPermissions()`), materializado por `platform:sync-registry`. Esta tabla es su reflejo documental.

---

## E.1 La conclusión, primero: **1.4 no declara ningún permiso**

Como 1.2b, y por un motivo distinto del suyo. En 1.2b no había permiso porque **nadie gestiona las sesiones de otro**. Aquí es más simple todavía: **`REQ-AUTH-002` describe solo autoservicio**. Iniciar sesión con Google, vincular la cuenta propia, desvincular la cuenta propia. No hay en el requisito una sola frase sobre que alguien administre los vínculos de otra persona.

El catálogo del módulo **sigue teniendo exactamente siete permisos** tras este paso: los dos de `bloqueo_cuenta` (1.2), los dos de `mfa` (1.3) y los tres de `exencion_mfa` (1.3b). `CA-AUTH-232` lo verifica.

Lo que **no** cambia tampoco:

- **No se toca el catálogo de `REQ-CORE`**, a diferencia de 1.3 (`rol.actualizar`, `§C.5`).
- **`REQ-AUTH` sigue sin exponer categoría especial** (`§E.5`).
- **Ningún endpoint nuevo acepta un sujeto ajeno** (`RN-AUTH-73`): los dos de autoservicio actúan siempre sobre el portador de la cookie, y el *callback* sobre quien resuelva el proveedor para la sesión que arrancó el flujo.

**Que no haya permisos no significa que no haya autorización.** `INV-002` no admite excepciones, y `§E.2` describe qué autoriza cada uno de los **seis** *endpoints* — los cinco del flujo de Google más `GET /auth/mfa-challenges`, que 1.4 añade sobre un recurso de 1.3 para que la pantalla de resultado del *callback* pueda pintar el segundo factor (`api.md §E.5b`).

---

## E.2 Cómo se autoriza lo que no lleva permiso

**Sin mecanismos nuevos.** Los cuatro de `§1`, `§C.4` y `§D.4` siguen siendo los únicos:

| Endpoint | Mecanismo | Detalle |
|----------|-----------|---------|
| `GET /auth/identity-providers` | **Anónimo**, tenant por host | No revela nada de nadie: dice qué proveedores admite el despliegue, no quién los usa |
| `POST /auth/oauth-authorizations` con `intent = "login"` | **Anónimo**, con CSRF y límite de tasa | |
| `POST /auth/oauth-authorizations` con `intent = "link"` | **Identidad del portador de la cookie** | Sesión completa. Una sesión **restringida** por el muro de MFA no llega: el endpoint no está en la lista blanca de `§C.4.9` |
| `GET /auth/oauth/google/callback` | **Posesión de la sesión que arrancó el flujo** | El cuarto mecanismo, el que 1.3 estrenó con el desafío de MFA. Aquí la prueba es el `state` guardado en el *payload*, comparado en tiempo constante y consumido en el acto |
| `GET /auth/mfa-challenges` (`api.md §E.5b`) | **Posesión de la sesión que abrió el desafío** | El mismo cuarto mecanismo, esta vez idéntico al de `POST /auth/mfa-verifications`: el desafío se busca **por `session_id`** (`RN-AUTH-53`). Sin desafío vivo para esa sesión, `410` — **nunca `401`**, porque entre el paso 1 y el paso 2 no hay identidad que reclamar (`RN-AUTH-52`) |
| `GET /auth/identities` | Identidad del portador | Sin parámetro de sujeto |
| `DELETE /auth/identities/{public_id}` | Identidad del portador **más contraseña actual** | La contraseña no es un permiso: es la reautenticación de `RN-AUTH-60`, ya usada en 1.3 para desactivar un factor |

**El punto donde se cometería el error**, y por eso se dice como `§D.4` dijo el suyo: la tentación en implementación es aceptar el `public_id` de la autorización —o del vínculo— como forma de continuar el flujo desde otra sesión. **No existe tal `public_id` en el arranque, y el del vínculo no autoriza nada** (`api.md §E.3`). La única credencial del flujo es la cookie, siempre.

---

## E.3 Matriz recurso × acción × ámbito

Sin cambios respecto de 1.3b. `—` significa que el permiso no existe en este módulo.

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `bloqueo_cuenta` (1.2) | — | `todos` | — | `todos` | — | — | — | — | — |
| `mfa` (1.3) | — | `todos` | — | `todos` | — | — | — | — | — |
| `exencion_mfa` (1.3b) | `todos` | `todos` | — | `todos` | — | — | — | — | — |
| **`identidad_externa`** (1.4) | **—** | **—** | **—** | **—** | **—** | — | — | — | — |

La fila de `identidad_externa` está **entera vacía a propósito**, y se escribe en vez de omitirse porque la pregunta «¿y el administrador?» se hace sola. La respuesta está en `OPEN-AUTH-34`: el requisito no lo pide, así que no se concede. **Denegación por defecto** (`RPERM-011`).

Si `OPEN-AUTH-34` se resolviera por sí, serían `identidad_externa.leer` y `identidad_externa.eliminar`, con ámbito `todos` mientras rija el resolutor provisional (`§5.6`), concedidos **solo a `administrador_centro`** por el mismo argumento de `§5.1`, `§C.7.1` y `§D.6.1`. Queda escrito para que la decisión, si llega, no tenga que rehacer el análisis.

---

## E.4 Reglas de autorización que no son un permiso

Amplía `§7`, `§B.4`, `§C.8` y `§D.7`. Es la parte que la revisión de seguridad debe recorrer entera.

| Regla | Dónde | Efecto |
|-------|-------|--------|
| **`RN-AUTH-91` — el `state` es de un solo uso y se compara en tiempo constante** | *Callback* | Sin él, el *callback* es una petición falsificable desde cualquier sitio: es **la** defensa CSRF de ese endpoint, que no lleva token porque es una navegación de un tercero |
| **`RN-AUTH-92` — la `redirect_uri` no sale de `$request->getHost()`** | Arranque del flujo | El `Host` lo controla el cliente. Se construye con el slug ya resuelto y el dominio base configurado (`CA-AUTH-203`) |
| **`RN-AUTH-94` — el login federado no salta ninguna comprobación** | *Callback* | Bloqueo, estado de la cuenta y `MfaPolicy` completo. **Un camino de autenticación nuevo que se salta el segundo factor es un camino de evasión del segundo factor**, y es el error más caro que se puede cometer en este paso |
| **El vínculo se busca por `public_id` + tenant + `user_id` del portador, en el mismo `WHERE`** | `DELETE /auth/identities/{public_id}` | Misma regla que `RN-AUTH-41` y que `§C.8` para los factores. Un `find()` seguido de una comprobación en PHP es un fallo de revisión |
| **`RN-AUTH-89` — la unicidad del vínculo la garantiza el índice, no un `if`** | Fusión y vinculación | Una comprobación previa tiene condición de carrera; el índice único parcial no (`CA-AUTH-223`) |
| **`RN-AUTH-87` — sin `email_verified` no hay fusión, y lo garantiza también el `CHECK`** | Fusión | La restricción `link_method <> 'fusion_automatica' OR email_verified_at_link` (`datos.md §E.2`) hace que ni un error de servicio pueda escribir una fusión no verificada |
| **`RN-AUTH-99` — ningún usuario se crea desde un login federado** | *Callback* | Decidido el 2026-08-31 (`OPEN-AUTH-31`, interpretación restrictiva). Es una regla de **autorización**, no de alcance: crear una cuenta es la concesión de acceso más grande que existe, y aquí la concedería un desconocido con una cuenta de Google |
| **Una sesión restringida por el muro no puede vincular** | `POST /auth/oauth-authorizations` con `intent = "link"` | La lista blanca de `§C.4.9` es blanca: lo que no está, no pasa. El endpoint nuevo **no se añade a ella**, y es correcto |

Y siguen en vigor, sin excepción, las de `§7`, `§B.4`, `§C.8` y `§D.7`: `RN-AUTH-06` (el `tenant_id` sale del host), `RN-AUTH-07` (predicado explícito además de RLS), `RN-AUTH-29` (**CSRF en toda escritura**, incluidas las dos de este paso — el *callback* no es una escritura iniciada por nuestro cliente y su defensa es el `state`), `RN-AUTH-52`, `RN-AUTH-53`, `RN-AUTH-61`, `RN-AUTH-63`, `RN-AUTH-67`, `RN-AUTH-71`, `RN-AUTH-73` y `RN-AUTH-81`.

---

## E.5 Datos de categoría especial

**Sigue sin haberlos.** `REQ-AUTH` no expone salud, NEAE ni convivencia, y ninguno de sus siete permisos lleva `is_special_category = true`. La auditoría reforzada de lectura de `RPERM-015` no se dispara aquí.

Lo que 1.4 **sí** añade al inventario de datos sensibles del módulo, ampliando `§C.9` y `§D.8`:

- **El identificador de una persona en un sistema de un tercero** (`user_identities.subject`). No es una credencial —conocerlo no permite entrar— pero **sí permite correlacionar a esa persona fuera de este producto**. Por eso va declarado como no registrable por `ADR-035` (`datos.md §E.2`) y por eso no sale por ninguna API: `GET /auth/identities` devuelve `public_id`, proveedor y correo enmascarado, nunca el `sub`.
- **La dirección de correo personal**, que es lo que suele haber al otro lado de una cuenta de Google en un centro. Entra en el producto por este paso y **solo sale enmascarada** (`api.md §E.5`), incluso hacia el propio titular.
- **El hecho de tener cuenta de Google vinculada**, para el conjunto del centro. No hay ningún endpoint que lo liste —por eso `identidad_externa` no tiene ni `leer` ni `exportar`—, y si `OPEN-AUTH-34` lo trae, ese listado hereda el criterio de `§D.2`: **sin `exportar`**, porque un CSV de quién entra con qué es un mapa de por dónde atacar.

---

## E.6 Verificación

- **`CA-AUTH-232` — test de catálogo, ampliado**: tras `platform:sync-registry`, `permissions` sigue conteniendo **exactamente siete** filas con `module_code = 'auth'`, ninguna con `retired_at`, ninguna con `is_special_category = true`, y ninguna fila de `permission_role` de este módulo con `scope` distinto de `todos`. **Si aparece una octava en esta rama, alguien ha declarado un permiso que el requisito no pide.**
- **Test de catálogo de `REQ-CORE`: sin cambios.** 1.4 **no toca** el catálogo de otro módulo. Una fila nueva con `module_code = 'core'` en esta rama es un error.
- **`CA-AUTH-215`** — un `public_id` de vínculo de otro tenant responde `404`, nunca `403`, y la fila del otro tenant sigue viva.
- **`CA-AUTH-218`** — una sesión restringida por el muro recibe `403 urn:pge:error:mfa-enrollment-required` al intentar vincular.
- **`CA-AUTH-216`/`CA-AUTH-217`** — el login federado **no** salta el segundo factor (`RN-AUTH-94`). Es el test que más importa de todo el paso.
- **`CA-AUTH-201`** — sin CSRF no se arranca el flujo y **no queda `state` en la sesión**.
- **`CA-AUTH-225`** — sin contraseña actual no se desvincula, y el fallo cuenta hacia el bloqueo.
- **`CA-AUTH-231`** — ninguna de las **seis** rutas lleva `module-enabled`.
- **`CA-AUTH-237`** — `GET /auth/mfa-challenges` presentado **desde una sesión que no abrió el desafío** responde `410` con cuerpo idéntico al de «no hay desafío», y **nunca** devuelve el desafío de otra sesión (`RN-AUTH-53`, `api.md §E.5b`).

---

# Parte F · Paso 1.4b · Permisos (`REQ-AUTH-004`)

> **Estructura**: §1-§8 son 1.2, `§B.*` es 1.2b, `§C.*` es 1.3, `§D.*` es 1.3b y `§E.*` es 1.4, los cinco cerrados. Esta **Parte F** es el paso **1.4b**, **implementada** (pendiente de revisión independiente y de mezclar a `develop`).
>
> Fuente de verdad del catálogo: **el código** (`AuthServiceProvider::declaredPermissions()`), materializado por `platform:sync-registry`. Esta tabla es su reflejo documental.

---

## F.1 La conclusión, primero: **1.4b declara cuatro permisos**

Y rompe la racha de 1.4, que no declaró ninguno. **El motivo es una decisión del usuario, no una interpretación**: `ADR-043 §8.3` se resolvió el 2026-09-01 en **autoservicio del centro**. Configurar el proveedor de identidad de un centro **es una operación de administración de tenant**, con cinco *endpoints* de escritura y lectura sobre una entidad nueva, y `INV-002` no admite un solo *endpoint* sin autorización verificada.

El contraste con 1.4 es exacto y merece decirse, porque es el mismo módulo con dos resultados opuestos en dos pasos consecutivos:

- **1.4 no declaró ninguno** porque `REQ-AUTH-002` describe **solo autoservicio del titular**: iniciar sesión, vincular lo propio, desvincular lo propio. No hay en el requisito una frase sobre administrar lo de otro.
- **1.4b declara cuatro** porque `REQ-AUTH-004` describe una **integración del centro**: *«SAML 2.0 para sistemas de identidad institucionales»*, *«OIDC para Azure AD / Entra ID, Google Workspace»*. Un IdP institucional no es de nadie en particular: es del centro, y alguien del centro lo configura.

El catálogo del módulo pasa de **siete a once** permisos: los dos de `bloqueo_cuenta` (1.2), los dos de `mfa` (1.3), los tres de `exencion_mfa` (1.3b) y los **cuatro** de `proveedor_identidad` (1.4b). `CA-AUTH-305` lo verifica.

Lo que **no** cambia:

- **No se toca el catálogo de `REQ-CORE`**, a diferencia de 1.3 (`rol.actualizar`, `§C.5`). El recurso nuevo es de este módulo.
- **`REQ-AUTH` sigue sin exponer categoría especial** (`§F.8`).
- **Ningún *endpoint* del flujo de login acepta un sujeto ajeno** (`RN-AUTH-73`): el *callback* actúa sobre quien resuelva el proveedor para la sesión que arrancó el flujo, y los dos de `/auth/identities` sobre el portador de la cookie.
- **`OPEN-AUTH-34` sigue abierta y este paso no la resuelve.** Administrar los vínculos **de otros usuarios** sigue sin estar en el requisito, y el recurso `identidad_externa` sigue con su fila entera vacía (`§F.6`). **Configurar un proveedor no es ver los vínculos de nadie**, y confundir las dos cosas sería resolver por la puerta de atrás una pregunta abierta.

---

## F.2 Recursos que aporta el paso

| Recurso | Qué es | Por qué recurso propio |
|---------|--------|------------------------|
| **`proveedor_identidad`** | El emisor OIDC que un centro cataloga: metadatos, credencial, dominios admitidos, modo de aprovisionamiento y conmutador de activación | No es una acción más de `configuracion` de `REQ-CORE`, y hay que argumentarlo porque la alternativa era real. `configuracion.actualizar` gobierna `tenant_settings`, que es **un escalar por ajuste y una fila por centro**. Un proveedor es **una entidad con ciclo de vida propio y varias instancias por centro** (`datos.md §F.6`). Es el mismo criterio con el que `exencion_mfa` fue recurso propio y no una acción de `mfa` (`§D.2`) |

**Y un recurso que este paso deliberadamente no crea**: `credencial_proveedor_identidad`. Las dos operaciones sobre credenciales van con `proveedor_identidad.actualizar` (`§F.4`).

---

## F.3 Catálogo de permisos que declara `REQ-AUTH` en 1.4b

| Código | Recurso | Acción | Categoría especial | Qué autoriza |
|--------|---------|--------|--------------------|--------------|
| `proveedor_identidad.leer` | `proveedor_identidad` | `leer` | No | `GET /identity-providers` y su detalle |
| `proveedor_identidad.crear` | `proveedor_identidad` | `crear` | No | `POST /identity-providers`, con su validación de descubrimiento |
| `proveedor_identidad.actualizar` | `proveedor_identidad` | `actualizar` | No | `PATCH /identity-providers/{id}`, los **dos** de credenciales y `POST .../discovery-refreshes` |
| `proveedor_identidad.eliminar` | `proveedor_identidad` | `eliminar` | No | `DELETE /identity-providers/{id}` (borrado lógico) |

**Cuatro acciones y no dos**, a diferencia de `bloqueo_cuenta` y `mfa`, que solo tienen `leer` y `eliminar`. Aquí el ciclo de vida completo existe de verdad —se crea, se consulta, se modifica y se retira— y el vocabulario de `ADR-038`/`RPERM` lo cubre sin inventar nada. **No se añade `exportar`**, y es deliberado: `§F.8`.

### F.3.1 Endpoints sin permiso, a propósito y de forma razonada

| Endpoint | Por qué no lleva permiso |
|----------|--------------------------|
| `GET /auth/identity-providers` | **Anónimo** por diseño: la pantalla de login tiene que saber qué botones pintar antes de que nadie se identifique (`RN-AUTH-98`). Devuelve **solo** identificador opaco y nombre visible; ni emisor, ni `client_id`, ni dominios (`api.md §F.6`) |
| `POST /auth/oauth-authorizations` | **Anónimo** con `intent = login`; **por identidad del portador** con `intent = link`. Sin cambios respecto de `§E.2` |
| `GET /auth/oauth/oidc/callback` | **Posesión de la sesión que arrancó el flujo**, probada por el `state`. Es el cuarto mecanismo de `§C.4`, **sin ampliación** |
| `GET` / `DELETE /auth/identities` | **Identidad del portador**, sin cambios respecto de `§E.2` |

---

## F.4 Por qué las credenciales no tienen permiso propio

Es la decisión discutible de esta Parte y va con su alternativa, como `§C.6.1` y `§D.6.3` hicieron con las suyas.

**La alternativa era `proveedor_identidad.crear`/`eliminar` para la configuración y un recurso `credencial_proveedor_identidad` para el material sensible**, de modo que «quién ve el catálogo» y «quién carga la credencial» pudieran separarse.

**Se descarta, por tres motivos en orden de peso:**

1. **Hoy no separaría a nadie.** Los cuatro permisos se conceden **solo** a `administrador_centro` (`§F.6`), y un recurso más produciría **dos permisos que siempre se conceden juntos a la misma persona**. Es complejidad sin beneficio proporcional, exactamente el criterio con el que `ADR-043 §4.3` descartó el mapeo libre.
2. **La separación que de verdad importa ya está, y no es de permisos: es de lectura.** El riesgo de una credencial no es quién la carga, sino quién la lee, y **nadie la lee**: no sale por ninguna API, ni enmascarada (`RN-AUTH-112`, `CA-AUTH-266`). Un permiso de lectura sobre algo que no se puede leer sería ceremonia.
3. **Cargar una credencial es modificar la configuración del proveedor**, en el sentido que importa: cambia lo que el sistema envía al IdP. `actualizar` lo describe bien.

**Si algún día hiciera falta separar** —un centro donde el jefe de estudios configura y solo el director carga la credencial—, es un recurso más y dos permisos más, sin tabla ni *endpoint* nuevos. **El hueco ya está** y queda escrito para que la decisión, si llega, no tenga que rehacer este análisis.

---

## F.5 Cómo se autoriza lo que sí lleva permiso, y las dos guardas que no son permisos

**Los ocho *endpoints* de administración van con `Gate` sobre su permiso, denegando por defecto** (`RPERM-011`, `INV-002`). Y llevan **dos guardas más** que no son permisos y que la revisión tiene que recorrer:

1. **El muro de MFA se aplica.** Ninguna de las ocho rutas está en la lista blanca de `§C.4.9`, y **es correcto**: un administrador obligado a MFA con la gracia vencida **no configura el IdP de su centro**; primero da de alta su segundo factor. Es el mismo criterio con el que el muro deja fuera `POST /auth/password-changes` y `POST /auth/oauth-authorizations` con `intent = link` (`CA-AUTH-300`).
2. **El recurso se busca por `public_id` más predicado de tenant, en el mismo `WHERE`.** Nunca un `find()` seguido de una comprobación en PHP (`RN-AUTH-41`, `§E.4`). Vale para el proveedor y para la credencial anidada, que además se busca **por su proveedor padre en la misma consulta**: una credencial de otro proveedor del mismo tenant presentada bajo el `public_id` equivocado es un `404`, no un borrado ajeno.

---

## F.6 Matriz recurso × acción × ámbito

`—` significa que el permiso no existe en este módulo.

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `bloqueo_cuenta` (1.2) | — | `todos` | — | `todos` | — | — | — | — | — |
| `mfa` (1.3) | — | `todos` | — | `todos` | — | — | — | — | — |
| `exencion_mfa` (1.3b) | `todos` | `todos` | — | `todos` | — | — | — | — | — |
| `identidad_externa` (1.4) | — | — | — | — | — | — | — | — | — |
| **`proveedor_identidad`** (1.4b) | **`todos`** | **`todos`** | **`todos`** | **`todos`** | **—** | — | — | — | — |

**La fila de `identidad_externa` sigue entera vacía**, y este paso **no la rellena**. `OPEN-AUTH-34` sigue abierta con el mismo argumento de `§E.3`: el requisito no pide que un administrador vea ni retire el vínculo de otro. **Que 1.4b traiga permisos de administración no arrastra a este recurso**, y decirlo es el punto: es exactamente la clase de deriva que convierte «el requisito no lo pide» en «ya que estamos».

**Los cuatro ámbitos son `todos`**, por el mismo motivo de `§5.6`, `§C.7.6` y `§D.6.2`: el resolutor de ámbitos es provisional hasta 1.5, y **ningún ámbito más estrecho tiene sentido aquí de todos modos**. Un proveedor de identidad no cuelga de un grupo, ni de una etapa, ni de un curso: es del centro entero. Es el caso más claro de `todos` que ha aparecido en el módulo.

---

## F.7 Asignación en los roles predefinidos

**Solo `administrador_centro`**, los cuatro. Por cuarta vez, y con el argumento de `§5.1`, `§C.7.1` y `§D.6.1`, más uno propio de este paso que lo refuerza:

> **Quien configura el proveedor de identidad decide de quién se fía el sistema para dejar entrar.** Un `client_id` apuntado a otro emisor, o un `allowed_email_domains` vacío en un centro con Workspace, es la diferencia entre «entra el personal del centro» y «entra cualquiera con una cuenta en cualquier sitio». **Es la concesión de acceso más amplia que un rol de centro puede hacer**, y por eso no baja de `administrador_centro` sin una decisión explícita del producto.

- **`direccion` y `secretaria` no lo reciben.** No es una omisión: `RPERM-011` deniega por defecto, y ampliarlo es una decisión de 1.5 con el editor de roles delante.
- **`soporte_plataforma`**: sin cambios (`§5.2`). No es un rol de tenant.
- **`super_administrador`**: sin cambios (`§5.3`). El backoffice es 1.6 y `ADR-043 §8.3` dejó esta configuración fuera de él expresamente.
- **`mfa_obligatorio` (`RPERM-014`)**: `administrador_centro` ya lo tiene desde 1.3 (`§C.7.4`), así que **quien configura el SSO está obligado a segundo factor**. Es coherente y conviene decirlo: la cuenta que puede cambiar de quién se fía el sistema no entra con solo una contraseña.
- **`acceso_datos_especiales` (`RPERM-015`)**: sin cambios. Ninguno de los cuatro permisos nuevos lo lleva ni lo requiere (`§F.8`).
- **`RPERM-012`**: ninguno de los cuatro es de categoría especial, y **el aprovisionamiento no puede conceder ninguno** (`RN-AUTH-110`).

---

## F.8 Datos de categoría especial

**Sigue sin haberlos.** `REQ-AUTH` no expone salud, NEAE ni convivencia, y ninguno de sus **once** permisos lleva `is_special_category = true`. La auditoría reforzada de lectura de `RPERM-015` no se dispara aquí.

Lo que 1.4b **sí** añade al inventario de datos sensibles del módulo, ampliando `§C.9`, `§D.8` y `§E.5`:

- **La credencial de cliente del centro frente a su IdP** (`identity_provider_secrets.client_secret`). **No es un dato personal**: es material de autenticación de la plataforma frente a un tercero. Su compromiso no da acceso a ninguna cuenta por sí solo —hace falta además el `code` de un usuario— pero permite **suplantar a la plataforma frente al IdP del centro**. Por eso está cifrada, no sale por ninguna vía y no aparece en `audit_logs` con su valor (`RN-AUTH-112`, `datos.md §F.3`).
- **El mapa de integración del centro**: emisor, `client_id` y dominios admitidos. No es dato personal, pero **describe cómo entra el personal de un centro**, y por eso `GET /auth/identity-providers`, que es anónimo, **no lo publica** (`api.md §F.6`).
- **Nada nuevo sobre personas.** `identity_providers` e `identity_provider_secrets` **no contienen ni un dato personal** (`datos.md §F.10`), y es la primera vez en este módulo que se puede afirmar de una tabla nueva.

**Y sin `exportar`**, con el criterio de `§D.2` y `§E.5`: un CSV de la configuración de identidad de los centros es un mapa de por dónde atacar. La pantalla lo enseña; no hay descarga.

---

## F.9 Reglas de autorización que no son un permiso

Amplía `§7`, `§B.4`, `§C.8`, `§D.7` y `§E.4`. **Es la parte que la revisión de seguridad debe recorrer entera, y este paso es el que más añade desde 1.3.**

| Regla | Dónde | Efecto |
|-------|-------|--------|
| **`RN-AUTH-113` — cinco guardas sobre toda URL que el servidor descargue por indicación del tenant** | `POST /identity-providers`, `PATCH` con `discovery_url`, `POST .../discovery-refreshes` | **Es la superficie nueva con más peso de seguridad del paso.** Sin ellas, un administrador de centro convierte al servidor en su cliente HTTP contra la red interna, y **ve el resultado en el mensaje de error**. Las guardas se aplican **también en cada redirección** (`CA-AUTH-262`, `CA-AUTH-263`) |
| **`RN-AUTH-112` — la credencial no sale en claro para nadie** | Los ocho de administración, `audit_logs`, el registro de aplicación | Ni el administrador que la cargó puede releerla. Se descifra solo dentro del canje de código, en memoria (`CA-AUTH-266`) |
| **`RN-AUTH-103` — el proveedor sale de la sesión, jamás de la URL** | *Callback* institucional | Una ruta con el identificador del proveedor dentro sería un parámetro controlado por quien llega, en el *endpoint* que crea sesiones (`CA-AUTH-278`) |
| **`RN-AUTH-104` — cinco validaciones del `id_token`, con `nonce` obligatorio** | *Callback* | Es lo que sustituye a la verificación de firma (`funcional.md §F.3.2`). **Sin `nonce`, un `id_token` capturado se puede reproducir**; sin `aud`, un `id_token` emitido para otro cliente del mismo emisor vale aquí (`CA-AUTH-276`, `CA-AUTH-277`) |
| **`RN-AUTH-105` — sin `sub` no hay identidad, y nunca se identifica por correo** | *Callback* | Identificar por correo haría que quien adquiera un correo liberado heredara una cuenta (`ADR-042 §3`, trampa 3). **Fallo en cerrado, con salida indistinguible** (`CA-AUTH-279`) |
| **`RN-AUTH-107` — la restricción por dominio se comprueba antes de resolver identidad** | *Callback* | Comprobarla después dejaría un camino donde se consulta el censo con un correo de un dominio ajeno. Y el `hd` de Google **no es redundante** con el dominio del correo (`CA-AUTH-284`) |
| **`RN-AUTH-108` — ningún `Person` ni `User` se crea** | *Callback* | Es una regla de **autorización**, no de alcance, con las palabras de `§E.4`: crear una cuenta es la concesión de acceso más grande que existe (`CA-AUTH-287`) |
| **`RN-AUTH-110` — el emparejamiento no concede ni un rol** | *Callback* | `RPERM-011` deja a una cuenta sin roles sin ver una pantalla; el aprovisionamiento **no puede ser el agujero** por el que se concedan, y menos los de categoría especial (`RPERM-012`, `CA-AUTH-292`) |
| **`RN-AUTH-111` — el SSO no salta ninguna comprobación** | *Callback* | Bloqueo, estado y `MfaPolicy` completo. **Un camino de autenticación nuevo que se salta el segundo factor es un camino de evasión del segundo factor**, y sigue siendo el error más caro que se puede cometer en este módulo (`CA-AUTH-299`) |
| **`RN-AUTH-102` — un proveedor no activo no arranca el flujo** | `POST /auth/oauth-authorizations` | Ocultar el botón no es una defensa (`INV-010`). La comprobación es de servidor en los dos sitios (`CA-AUTH-270`) |
| **`RN-AUTH-101` — un `public_id` de otro tenant es `404`, y en el *endpoint* anónimo es `422` indistinguible** | Los ocho de administración; `POST /auth/oauth-authorizations` | La asimetría es deliberada y está argumentada en `api.md §F.1`: en el anónimo, un `404` distinguible sería un comprobador de qué centros tienen SSO (`CA-AUTH-265`, `CA-AUTH-271`) |
| **El muro de MFA cubre las ocho rutas de administración** | Lista blanca de `§C.4.9` | La lista es blanca: lo que no está, no pasa. **Las ocho no se añaden**, y es correcto (`CA-AUTH-300`) |

Y siguen en vigor, sin excepción, las de `§7`, `§B.4`, `§C.8`, `§D.7` y `§E.4`: `RN-AUTH-06` (el `tenant_id` sale del host), `RN-AUTH-07` (predicado explícito además de RLS), `RN-AUTH-29` (**CSRF en toda escritura**, incluidas las ocho de este paso — el *callback* no es una escritura iniciada por nuestro cliente y su defensa es el `state`), `RN-AUTH-41`, `RN-AUTH-52`, `RN-AUTH-53`, `RN-AUTH-61`, `RN-AUTH-63`, `RN-AUTH-67`, `RN-AUTH-71`, `RN-AUTH-73`, `RN-AUTH-81`, `RN-AUTH-89`, `RN-AUTH-91`, `RN-AUTH-92`, `RN-AUTH-93` y `RN-AUTH-96`.

---

## F.10 Verificación

- **`CA-AUTH-305` — test de catálogo, ampliado**: tras `platform:sync-registry`, `permissions` contiene **exactamente once** filas con `module_code = 'auth'`, ninguna con `retired_at`, ninguna con `is_special_category = true`, y ninguna fila de `permission_role` de este módulo con `scope` distinto de `todos`. **Si aparece una duodécima en esta rama, alguien ha declarado un permiso que el requisito no pide.**
- **Test de catálogo de `REQ-CORE`: sin cambios.** 1.4b **no toca** el catálogo de otro módulo. Una fila nueva con `module_code = 'core'` en esta rama es un error.
- **Test de asignación**: los cuatro permisos nuevos están **solo** en `administrador_centro` tras `tenant:provision-defaults`. Si aparecen en `direccion` o `secretaria`, es una ampliación de alcance no aprobada (`§F.7`).
- **`CA-AUTH-265`** — cualquiera de las ocho rutas con un `public_id` de otro tenant responde `404`, nunca `403`, y la fila del otro tenant sigue viva.
- **`CA-AUTH-262`/`CA-AUTH-263`** — las cinco guardas de descubrimiento, incluida la revalidación **en cada redirección**. Es el test que más importa de la mitad de administración.
- **`CA-AUTH-266`** — la credencial no aparece en el detalle, ni en la colección, ni en `audit_logs`.
- **`CA-AUTH-299`/`CA-AUTH-300`** — el SSO institucional **no** salta el segundo factor, y el muro cubre las ocho rutas de administración. Son los tests que más importan de la mitad de flujo.
- **`CA-AUTH-287`** — ninguna fila nueva en `people` ni en `users` tras un emparejamiento.
- **`CA-AUTH-294`** — dos proveedores del mismo centro con el mismo `subject` producen dos vínculos independientes sobre dos usuarios distintos. **Con la clave de 1.4 este test falla**, y por eso existe.
- **`CA-AUTH-306`** — ninguna de las **nueve** rutas lleva `module-enabled`.

---

# Parte G · Paso 1.4c · SSO institucional (SAML 2.0) — Permisos (`REQ-AUTH-004`)

> **Estructura**: §1-§8 son 1.2, `§B.*` es 1.2b, `§C.*` es 1.3, `§D.*` es 1.3b, `§E.*` es 1.4 y `§F.*` es 1.4b, los seis cerrados. Esta **Parte G** es el paso **1.4c**, **APROBADA** el 2026-09-02 (`funcional.md §G.14`).
>
> `INV-002` (denegar por defecto) y `ADR-038 §6.4` (`404` y nunca `403` ante un identificador de otro tenant) sin excepción.

---

## G.1 La conclusión, primero: **1.4c no declara ningún permiso**

Y vuelve a la situación de 1.4, después de que 1.4b declarara cuatro. **El motivo no es que este paso sea pequeño** —tiene cinco *endpoints* nuevos y cuatro tablas—, sino que **no aporta ningún recurso ni ninguna acción que el catálogo no cubra ya**.

El argumento, en una frase: **configurar un proveedor SAML es configurar un proveedor de identidad**, y ese recurso existe desde 1.4b con sus cuatro acciones y su ciclo de vida completo. Añadir `proveedor_identidad_saml.*` sería tener dos recursos para la misma cosa según un detalle de protocolo que el administrador ni siquiera experimenta como una distinción: entra en la misma pantalla, en la misma lista, y da de alta «el sistema de identidad de mi centro».

**El catálogo del módulo sigue en once permisos**: los dos de `bloqueo_cuenta` (1.2), los dos de `mfa` (1.3), los tres de `exencion_mfa` (1.3b) y los cuatro de `proveedor_identidad` (1.4b). **`CA-AUTH-361` lo verifica, y está redactado para fallar si alguien declara un duodécimo.**

El contraste con los tres pasos anteriores del requisito, que es lo que hace la decisión comprobable en vez de cómoda:

- **1.4 no declaró ninguno** porque `REQ-AUTH-002` describe **solo autoservicio del titular**.
- **1.4b declaró cuatro** porque `REQ-AUTH-004` describe una **integración del centro**, con una entidad nueva y cinco *endpoints* de administración sobre ella.
- **1.4c no declara ninguno** porque **la entidad ya existe**. Lo que este paso añade son **más columnas, más tablas hijas y más operaciones sobre el mismo recurso**, y `ADR-038` no crea un permiso por cada tabla: lo crea por cada recurso que el usuario reconoce como tal.

Lo que **no** cambia:

- **No se toca el catálogo de `REQ-CORE`**, a diferencia de 1.3 (`rol.actualizar`, `§C.5`). Una fila nueva con `module_code = 'core'` en esta rama es un error.
- **`REQ-AUTH` sigue sin exponer categoría especial** (`§G.6`).
- **Ningún *endpoint* del flujo de acceso acepta un sujeto ajeno** (`RN-AUTH-73`): el ACS actúa sobre quien resuelva la **fila de correlación que nosotros emitimos**, no sobre quien diga el cuerpo de la petición.
- **`OPEN-AUTH-34` sigue abierta y este paso tampoco la resuelve.** Administrar los vínculos **de otros usuarios** sigue sin estar en el requisito, y el recurso `identidad_externa` sigue con su fila entera vacía (`§F.6`).

---

## G.2 Recursos que aporta el paso: **ninguno**

Y hay que argumentar por qué, porque las tres tablas nuevas con `public_id` o sin él son candidatas obvias que se descartan:

| Candidato descartado | Por qué no es recurso |
|----------------------|------------------------|
| **`certificado_proveedor_identidad`** | Es la respuesta exacta que `§F.4` dio para las credenciales, y el argumento **no ha cambiado al cambiar de protocolo**: un certificado no tiene sentido fuera de su proveedor, no se lista ni se busca por sí mismo, y quien puede modificar un proveedor puede modificar su material de verificación. Las dos operaciones van con `proveedor_identidad.actualizar`. **Un permiso separado permitiría un rol que puede cargar certificados y no ver el proveedor al que pertenecen** — una combinación sin significado que alguien tendría que explicar |
| **`configuracion_saml`** | Es una tabla hija 1:1 sin identidad propia, sin `public_id` y sin ningún *endpoint* que la direccione: se crea, se lee y se modifica siempre a través del `public_id` del padre (`api.md §G.2`). **Un permiso para algo que no tiene URL propia no se puede ejercer por separado** |
| **`peticion_saml` / `asercion_consumida`** | Son **estado transitorio de protocolo**, no entidades de negocio. No se listan, no se editan, no se borran a mano y **no tienen ni un solo *endpoint***. Su ciclo entero lo gobierna el servidor |
| **`metadatos_sp`** | No es una entidad: es una **proyección de lectura** del proveedor y del *host* del tenant. Se lee con `proveedor_identidad.leer`, que es exactamente lo que es (`api.md §G.3`) |

---

## G.3 Matriz recurso × acción × ámbito

**Sin una sola fila nueva.** Se reproduce la de `§F.6` en lo que 1.4c ejerce, con la columna de qué *endpoint* del paso cubre cada permiso:

| Recurso | Acción | Ámbito | Qué autoriza **en 1.4c** |
|---------|--------|--------|---------------------------|
| `proveedor_identidad` | `leer` | `todos` | `GET .../{public_id}/metadata` (`api.md §G.3`), y los campos SAML del listado y del detalle |
| `proveedor_identidad` | `crear` | `todos` | `POST /identity-providers` con `protocol: "saml"`, con su validación de metadatos |
| `proveedor_identidad` | `actualizar` | `todos` | `PATCH /identity-providers/{public_id}`, las **dos** operaciones de certificados y `POST .../metadata-refreshes` |
| `proveedor_identidad` | `eliminar` | `todos` | `DELETE /identity-providers/{public_id}`, sin cambios |
| `identidad_externa` | — | — | **Fila entera vacía, sin cambios.** `OPEN-AUTH-34` |

**El ámbito sigue siendo `todos` en los cuatro, y no `propios`**: un proveedor de identidad **es del centro**, no de quien lo dio de alta. Un ámbito `propios` significaría que el administrador que configuró el IdP es el único que puede corregirlo, lo que convierte una baja de personal en una integración huérfana. Sin cambios respecto de `§F.6`, y se repite porque es la clase de cosa que se copia mal.

---

## G.4 Endpoints sin permiso, a propósito y de forma razonada

**Uno solo**, y es el más delicado del módulo.

### `POST /api/v1/auth/saml/{public_id}/acs`

**No lleva permiso, y no puede llevarlo**: es una petición **anónima**, que llega desde el IdP del centro, **sin cookie de sesión** y por tanto sin usuario al que autorizar. Es la misma situación de los dos *callbacks* de 1.4 y 1.4b, **con una diferencia sustantiva que hay que escribir porque cambia dónde está la garantía**:

- **En OIDC**, la prueba de posesión es **la cookie de sesión** más el `state` guardado en su *payload*. Quien no arrancó el flujo en ese navegador no tiene ninguna de las dos.
- **En SAML no hay cookie** (`ADR-043 §2.1`), así que la prueba de posesión es **la fila de `saml_auth_requests`**: una petición viva, no consumida, no caducada y emitida por nosotros, cuyo `request_id` case con el `InResponseTo` de la aserción.

Es el **cuarto mecanismo de autorización** de `§C.4` —posesión de un artefacto de un solo uso emitido por el servidor—, ejercido sobre un artefacto que vive en base de datos en vez de en la sesión. **No es un mecanismo nuevo; es el mismo con otro almacén.**

**Y aquí está lo que esta sección tiene que dejar dicho con todas las letras**: como el ACS **tampoco lleva `csrf`** (`api.md §G.7.1`), **la correlación es la única barrera que queda entre un `POST` anónimo y una sesión autenticada**. No es defensa en profundidad: es la defensa. De ahí que:

1. **`RN-AUTH-120` (no se acepta SSO iniciado por el IdP) no sea una preferencia de prudencia sino una precondición de seguridad** de la excepción de CSRF (`ADR-043 §10.9` decisión 4). Sin `InResponseTo` que correlacionar, un atacante puede hacer que el navegador de la víctima entregue una aserción legítima **de la cuenta del atacante**, y la víctima queda operando en una cuenta ajena que el atacante luego lee: ***login CSRF*** sin mitigación.
2. **`RN-AUTH-121` (consumo atómico de un solo uso)** cierre la repetición contra la misma petición, y **`RN-AUTH-122`** la de la misma aserción contra otra petición.
3. **`RN-AUTH-118` (la llave nunca se elige por el contenido del mensaje)** impida que quien envía el mensaje elija con qué certificado se le comprueba.

**Los cuatro tests que sostienen esto son `CA-AUTH-341`, `CA-AUTH-342`, `CA-AUTH-343` y `CA-AUTH-344`**, y `CA-AUTH-346` comprueba que la excepción de CSRF está donde se dijo y **solo ahí**.

**Y lo que este *endpoint* explícitamente sí hace, aunque no lleve permiso**: aplica `MfaPolicy::resolve()` entero (`RN-AUTH-129`, `CA-AUTH-354`), el bloqueo de cuenta (`CA-AUTH-357`) y la comprobación de estado `activo` (`CA-AUTH-358`). **No llevar permiso no es no autorizar**: es autorizar por otro mecanismo y aplicar todas las guardas del login local después.

**Los cuatro *endpoints* nuevos de administración sí llevan permiso**, sin excepción, y ninguno estrena mecanismo: son `proveedor_identidad.leer` y `proveedor_identidad.actualizar` sobre rutas nuevas.

---

## G.5 Asignación en los roles predefinidos

**Sin cambios.** Los cuatro permisos de `proveedor_identidad` siguen **solo** en `administrador_centro` tras `tenant:provision-defaults` (`§F.7`).

**No se amplía a `direccion` ni a `secretaria`**, y la tentación existe porque «configurar el SSO» suena a tarea de dirección. **No**: quien puede catalogar un IdP puede decidir **qué sistema externo dice quién es quién** en el centro. Con SAML eso es aún más literal que con OIDC — cargar un certificado de firma es decidir **qué clave se acepta como prueba de identidad de cualquiera del centro**. Es una operación de administración de la plataforma dentro del centro, y `RPERM-012` es el criterio: los permisos con consecuencia sobre el acceso no se reparten por comodidad.

**Ningún rol nuevo, y ningún permiso de este módulo con `scope` distinto de `todos`.**

---

## G.6 Datos de categoría especial

**`REQ-AUTH` sigue sin exponer ni un permiso con `is_special_category = true`**, y 1.4c no lo cambia.

Merece una comprobación explícita porque este paso maneja material criptográfico y podría parecer que sí:

- **Un certificado del IdP es una clave pública.** No es dato personal ni categoría especial: es material público de una institución (`ADR-043 §10.6`).
- **La clave privada de firma del SP es un secreto de plataforma**, no de una persona, y **no se expone por ninguna API** (`RN-AUTH-127`, `CA-AUTH-334`). No hay permiso que la gobierne porque no hay *endpoint* que la sirva.
- **Una aserción SAML sí contiene datos personales** —nombre, correo, atributos del directorio—, y por eso **no se persiste ninguna**: de una aserción solo sobreviven su `ID` y su `NotOnOrAfter` (`CA-AUTH-363`, `datos.md §G.4.2`). **No hay dato personal que permisar porque no hay dato personal guardado.**
- **`RPERM-012` sigue cumpliéndose sin esfuerzo**: el aprovisionamiento no concede ni un rol (`RN-AUTH-129`, `CA-AUTH-353`), así que no puede ser el agujero por el que se concedan permisos de categoría especial.

---

## G.7 Reglas de autorización que no son un permiso

Las de `§F.9` siguen vigentes sin cambios. **Tres se ejercen de forma distinta en SAML y hay que decirlo:**

| Regla | Cómo se ejerce en 1.4c |
|-------|------------------------|
| **`RN-AUTH-101` · aislamiento por `public_id`** | Los cinco *endpoints* nuevos responden **`404` y nunca `403`** ante un `public_id` de otro tenant (`CA-AUTH-317`). **Incluido el ACS**, que además responde `302` con `estado_no_valido` en vez de `404` cuando el `public_id` no resuelve a un proveedor SAML activo — porque distinguir «este proveedor no existe» de «esta aserción no correlaciona» en una ruta anónima sería **un comprobador de qué centros tienen SAML** (`funcional.md §G.10.2`) |
| **`RN-AUTH-102` · un proveedor no activo no arranca el flujo** | Sin cambios, **y con una guarda más**: un proveedor SAML activo **sin certificado de firma vigente** tampoco arranca (`RN-AUTH-128`, `api.md §G.6`) |
| **`RN-AUTH-116` · aislamiento entre tenants en el propio protocolo** | **Es nuevo y no tiene equivalente en OIDC.** `entityId` de SP y ACS URL se derivan del *host* del tenant. Un `entityId` compartido haría textualmente válida en el centro B una aserción emitida legítimamente para el A: **fuga entre tenants por diseño, `INV-001`, severidad crítica de `CLAUDE.md §5`**. Con `Destination`, `Audience` y ruta del ACS todos por tenant quedan **tres barreras independientes**, y `CA-AUTH-339` las prueba **de una en una** |

**Y una regla de autorización que este paso hereda y que conviene no perder de vista**: `RN-AUTH-96` (*«el SSO nunca es la única puerta»*) sigue en vigor **sin excepción**, y en este paso es además lo que **degrada la caducidad de un certificado de caída total a molestia** (`ADR-043 §10.6`). No es solo una regla de disponibilidad: es lo que sostiene que el material criptográfico de un tercero pueda vencerse sin dejar a nadie fuera.

---

## G.8 Verificación

- **`CA-AUTH-361` — test de catálogo**: tras `platform:sync-registry`, `permissions` contiene **exactamente once** filas con `module_code = 'auth'`, ninguna con `retired_at`, ninguna con `is_special_category = true`, y ninguna fila de `permission_role` de este módulo con `scope` distinto de `todos`. **Si aparece una duodécima en esta rama, alguien ha declarado un permiso que el requisito no pide.**
- **Test de catálogo de `REQ-CORE`: sin cambios.** 1.4c **no toca** el catálogo de otro módulo.
- **Test de asignación**: los cuatro permisos de `proveedor_identidad` siguen **solo** en `administrador_centro`. Si aparecen en `direccion` o `secretaria`, es una ampliación de alcance no aprobada (`§G.5`).
- **`CA-AUTH-317`** — cualquiera de las rutas de administración con un `public_id` de otro tenant responde `404`, nunca `403`, y la fila del otro tenant sigue viva.
- **`CA-AUTH-339`** — una aserción legítima del centro A entregada en el ACS del centro B se rechaza, y **las tres barreras la rechazan por separado**. Es el test que más importa de esta parte: es `INV-001` en el protocolo, no en la consulta.
- **`CA-AUTH-341`/`342`/`343`/`344`** — las cuatro protecciones de la correlación. **Son lo que hace defendible la excepción de CSRF** (`§G.4`).
- **`CA-AUTH-346`** — el ACS es la **única** ruta sin `csrf`, en grupo propio, y **no existe ninguna lista global `validateCsrfTokens(except:)`**.
- **`CA-AUTH-352`/`353`** — ninguna fila nueva en `people` ni en `users`, y ni un rol concedido, tras un emparejamiento SAML.
- **`CA-AUTH-354`** — el SSO institucional SAML **no** salta el segundo factor.
- **`CA-AUTH-334`** — la clave privada de firma del SP no aparece en ninguna respuesta de la API ni en ninguna línea del registro.
- **`CA-AUTH-350`** — ninguna de las cinco rutas nuevas lleva `module-enabled`.
