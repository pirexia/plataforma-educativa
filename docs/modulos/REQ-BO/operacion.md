# REQ-BO · Operación

> Paso **1.6**, dividido en cinco sub-pasos por decisión del usuario del 2026-09-08 (`funcional.md §12`). Complementa `SYSADMIN.md` y `RUNBOOK.md`; aquí sólo lo específico de este módulo.
>
> **La conclusión primero**: a diferencia de `REQ-PERM`, que no añadía ni una variable de entorno, este paso añade **un segundo *host*, un segundo camino de autenticación, cambios en `infra/quadlet` (§0), variables de entorno nuevas, cuatro tareas programadas y un procedimiento de arranque manual sin el cual nadie puede entrar al backoffice** (§5). Y ese último punto es el que más fácil se olvida: el sistema **se despliega bloqueado a propósito**.

---

## 0. Enrutado y separación de superficie: `infra/quadlet` cambia en `1.6`

**Va primero porque sin esto la separación no existe**, y porque es lo único de este documento que no se arregla desplegando otra vez el código. `ADR-046 §4.3` y `§4.6` lo meten dentro de `1.6` a propósito, aunque `infra/` no sea ámbito habitual de un paso de módulo: *«fuera del ámbito habitual de un paso de módulo, pero inseparable de esta decisión»* (`ADR-046 §8`).

### 0.1 El estado de partida, verificado y no supuesto

**Hoy Traefik enruta sólo por `PathPrefix`, sin `Host()`** (`infra/quadlet/web.container`, `api@.container`; verificado en `ADR-046 §1.1`):

| *Router* | Regla actual | Prioridad |
|---|---|---|
| `plataforma-web` | `PathPrefix(/)` | 1 |
| `plataforma-api` | `PathPrefix(/api)` | 10 |

**Dos consecuencias, las dos decisivas:**

1. **Cualquier *host* que llegue al proxy alcanza el mismo contenedor de API**, incluido `centroa.dominio/api/platform/…`. Sin `Host()`, un grupo de rutas de plataforma sería alcanzable desde el *host* de un centro y la única barrera restante sería la autenticación — que es exactamente el defecto por el que se descartó la Opción C (`funcional.md §3.2`).
2. **`plataforma-web` serviría la SPA de los centros bajo el *host* del backoffice.** Hoy es inofensivo porque sólo hay un *host*; deja de serlo en cuanto exista el segundo.

### 0.2 Lo que hay que cambiar

| # | Cambio | Cuándo | Fichero |
|---|---|---|---|
| 1 | `PathPrefix(...)` pasa a **`Host(...) && PathPrefix(...)`** en los dos *routers* existentes | **`1.6`** | `infra/quadlet/web.container`, `infra/quadlet/api@.container` |
| 2 | ***Router* nuevo para `/api/platform`** bajo el *host* del backoffice | **`1.6`** | `infra/quadlet` |
| 3 | *Middleware* **`ipallowlist`** de Traefik aplicado **sólo** a los *routers* del backoffice | **`1.6`** | Ídem |
| 4 | ***Router* nuevo para la SPA** del backoffice, más la unidad y el `Containerfile` de su contenedor de estáticos, con `Wants=`+`After=` y **nunca** `Requires=` ni `BindsTo=` (`ADR-028`, `CLAUDE.md §9`) | **El paso de interfaz**, posterior a `1.7`/`1.9` | Ídem |

Todo dentro de `ADR-028 §1` (Traefik como único punto de entrada) y **sin tocar `ADR-037`**.

> **Por qué la fila 4 no es de `1.6`, y por qué no contradice a `ADR-046`.** `ADR-046 §4.1` decide **dónde vive** la SPA del backoffice —`apps/backoffice`, proyecto Vite independiente que no comparte *bundle* con `apps/web`— y no **cuándo** se construye. Lo segundo ya estaba decidido por el usuario el 2026-09-08 (`OPEN-BO-08`): los cinco sub-pasos se operan **sólo por API** hasta que existan `1.7` y `1.9` (`funcional.md §12.5`). Desplegar en `1.6` un *router* hacia un contenedor de estáticos vacío no aporta nada y sí añade una unidad que mantener.
>
> **Lo que sí es de `1.6` es la fila 1**, y ahí no hay margen: sin `Host()` en `plataforma-web`, la SPA **de los centros** se sirve bajo el *host* del backoffice (§0.1, consecuencia 2). Esa fila cierra ese agujero **aunque todavía no haya una SPA de backoffice que servir**.

### 0.3 El aviso que no se puede perder: `forwardedHeaders.trustedIPs`

> **Una lista blanca de IP en el *ingress* sólo es real si Traefik ve la IP del cliente.** Si algún día hay otro proxy delante —un balanceador, un CDN, un cortafuegos de aplicación—, hace falta configurar **`forwardedHeaders.trustedIPs`** en el *entrypoint*. **Sin eso, la lista blanca compara contra la dirección del NAT y permite a todo el mundo, sin dar ningún síntoma**: no hay error, no hay log raro, no hay `403` que investigar. Simplemente deja de proteger.

Es el motivo por el que `RN-BO-06` y `RN-BO-07` se cumplen **también en la aplicación** (`api.md §1.1`, puesto 2) y no sólo en el proxy: la configuración de Traefik no la cubre la suite de tests y la aplicación sí. **Las dos capas son obligatorias y ninguna sustituye a la otra** (`funcional.md §3.4`, condiciones 3 y 4).

**Comprobación operativa, no teórica**, que debe constar en `SYSADMIN.md`: tras cualquier cambio en la cadena de proxies, verificar que la IP registrada en `admin_action_logs` para un acceso legítimo es la del cliente y no una dirección interna. Si aparece una dirección de la red del NAT, la lista blanca del *ingress* está desactivada de hecho.

### 0.4 Y el *host* no puede ser cualquiera

`BACKOFFICE_HOST` **no puede ser un subdominio de `TENANCY_BASE_DOMAIN`** (`RN-BO-49`, `ADR-046 §4.4`): `TenantHost::slugFrom()` devuelve la etiqueta más a la izquierda de **cualquier** *host* que termine en `.{TENANCY_BASE_DOMAIN}`, de modo que un backoffice en `admin.{dominio_base}` resolvería el *slug* `admin`, y lo único que impediría que resolviera un centro sería que ninguno se llame así. El nombre concreto sigue bloqueado por `OPEN-08` (`OPEN-BO-12`); **la restricción que ese nombre deberá cumplir ya está decidida**.

---

## 1. Comportamiento con el módulo activo o inactivo

**`REQ-BO` no es un módulo activable.** No tiene fila en `modules`, no tiene `module_code` contratable y no se puede descontratar: es la aplicación **desde la que se contratan los demás**. Ninguna de sus rutas lleva el *middleware* `module-enabled`, y `RMOD-008`/`RMOD-009` no aplican a su superficie.

Lo que sí hace es **escribir el dato del que dependen esos dos requisitos**, y por eso `§4` describe la invalidación de caché con más detalle del que parecería necesario.

---

## 2. Variables de entorno

| Variable | Para qué | Nota |
|---|---|---|
| **`BACKOFFICE_HOST`** | *Host* del backoffice, con su entrada propia en `config/` (`ADR-046 §4.4`) | **Nunca un literal en el código y nunca derivada de `TENANCY_BASE_DOMAIN`** — ni por concatenación, ni por valor por defecto, ni «para desarrollo». Es lo que compara `RequirePlatformHost` (`RN-BO-48`) y **no puede ser un subdominio del dominio base de los tenants** (`RN-BO-49`, §0.4). `OPEN-08` sigue abierta: hasta que se cierre, en desarrollo es un *host* propio de `*.test` que **no** termina en el dominio base, y en despliegue lo fija Traefik (§0.2) |
| `BO_SESSION_COOKIE` | Nombre de la cookie de sesión de plataforma | **Distinto del de la cookie del producto**, y *host-only* como aquella (`SESSION_DOMAIN` sigue sin valor). Con §0 y `RN-BO-49`, `RMT-009` se cumple por construcción: dominios distintos, cookies distintas, tablas distintas |
| `BO_SESSION_LIFETIME` | Vida de la sesión de plataforma, en minutos (`RN-BO-09`) | **Más corta que la del producto**, y **propia**: no se deriva de `SESSION_LIFETIME`. La sesión vive en `platform_sessions`, no en `sessions` (`datos.md §2.6`), y su caducidad es de operación, no de código |
| `BO_REAUTH_WINDOW` | Ventana de reautenticación para operaciones sensibles (`RN-BO-08`) | En minutos. Del orden de una decena |
| `BO_DUAL_AUTH_TTL` | Vida de una solicitud de doble autorización | Corto. Una solicitud de eliminar un centro que sigue viva una semana es una firma pendiente que nadie recuerda |
| `DB_PLATFORM_USERNAME` / `DB_PLATFORM_PASSWORD` | Rol `plataforma_platform` (`ADR-033 §5`) | **Ya existen** desde 0.7. Este paso es el primero que las usa de verdad en un camino de petición HTTP |
| `BO_FLAG_CACHE_TTL` | Vida de una entrada de la caché de evaluación de *flags*, en segundos (§4.3) | Sub-paso `1.6e`. **Es una red de seguridad, no el mecanismo de invalidación**: éste es `rules_version`, y funciona en el acto. El TTL sólo limita cuánto sobreviven las entradas huérfanas de versiones anteriores. Del orden de minutos |

**Variables que deliberadamente no existen**, y conviene dejarlo escrito porque las tres son tentadoras:

| Lo que habría llevado una variable | Por qué no la hay |
|---|---|
| Conmutador para **desactivar la lista blanca de IP** | Es una puerta trasera con nombre de opción de configuración. Se desactivaría «un momento para depurar» y se quedaría así. La forma de operar sin restricción de red es una entrada `0.0.0.0/0` en la tabla: **visible, auditada y con autor** |
| Conmutador para **saltarse `RequirePlatformHost`** | Mismo argumento, y peor consecuencia: apagarlo devuelve el producto al estado de §0.1, en el que el backoffice es alcanzable desde el *host* de cualquier centro. En desarrollo se resuelve **dando un valor a `BACKOFFICE_HOST`**, que es lo que se va a usar en producción, no evitando la comprobación |
| Conmutador para **relajar el MFA** | `REQ-BO-007` dice «sin excepción». Una variable que lo relaja convierte «sin excepción» en «salvo que alguien exporte esto» |
| Conmutador para **saltar la doble autorización en desarrollo** | Es exactamente el mecanismo que acaba en producción. El entorno de desarrollo crea **dos** administradores; cuesta lo mismo y prueba lo que de verdad se va a usar |
| Variable para **forzar el valor de un *feature flag*** (`FEATURE_X=true`) | Es la forma más rápida de tener un *flag* encendido en producción **sin autor, sin motivo y sin auditoría**, y de que su valor real dependa de en qué contenedor caiga la petición. El estado de un *flag* vive en su tabla, con su `reason` y su entrada en `admin_action_logs` (`RN-BO-43`). Para apagarlo ya, está `forced_off`, que es igual de rápido y **sí** deja rastro (`api.md §2.12`) |
| Variable para **acortar el período de gracia** de los 90 días (`1.6b`) | `REQ-BO-001` fija 90 días. Una variable que los acorte es una forma de saltarse el plazo de portabilidad de un centro **sin que quede rastro de que alguien lo decidió**, y acabaría puesta a un valor pequeño «para probar» en un entorno que un día es producción. El plazo vive en `config/backoffice.php` como constante del producto, no como configuración de despliegue (`RN-BO-61`). **Acortar un plazo concreto, para un centro concreto, es una decisión que necesita nombre y auditoría** — y hoy no existe: el único camino es rescatar y volver a dar de baja |
| Conmutador para **desactivar el motor de *flags*** | Un motor desactivado tendría que devolver algo, y las dos respuestas posibles son malas: `false` para todo apaga funcionalidades ya entregadas a centros reales, y `true` para todo enciende de golpe lo que está a medias. Si el motor no puede leer sus tablas, la respuesta correcta es la de `RN-BO-35` —falso por defecto— y no hay nada que configurar |

---

## 3. Servicios externos y degradación

| Servicio | Uso | Si no responde |
|---|---|---|
| **PostgreSQL** (`plataforma_platform`) | Todo, **incluida la sesión de plataforma**: `platform_sessions` es tabla, no Redis (`datos.md §2.6`) | El backoffice no sirve. Sin degradación posible ni deseable |
| **PostgreSQL** (`plataforma_app`) | Aprovisionamiento de un tenant nuevo, que entra en su contexto | Ídem |
| **Redis** | Límite de tasa y las **cachés que hay que invalidar** (§4), incluida la de evaluación de *flags*. **No la sesión de plataforma** | Ver §4: el modo de fallo importa |
| **Correo** | Invitación del primer administrador del centro nuevo, e invitación de un administrador de plataforma | Sigue pendiente `OPEN-09` (proveedor transaccional). En desarrollo, `log`. Los tests comprueban que el trabajo se **encola**, no que el correo llegue |
| **Traefik / ACME** | Enrutado por `Host()` del *host* de plataforma, su `ipallowlist` y su certificado | Sin él no hay backoffice. **Y su configuración sí es parte de este paso** (§0): `ADR-046` mete las reglas de `infra/quadlet` dentro de `1.6`. Lo que sigue dependiendo de `OPEN-08` es el **nombre** del *host*, no las reglas |

**Redis caído: qué pasa exactamente.** La caché de disponibilidad de módulo falla a consultar la base de datos, que es correcto y sólo más lento. Lo que **no** puede ocurrir es lo contrario: que un Redis vacío o desalojado por memoria haga que un módulo no contratado parezca contratado. `EloquentModuleAvailability` falla en cerrado fuera de contexto de tenant y esa propiedad se conserva.

**Lo mismo, y por el mismo motivo, para los *flags***: un Redis vacío hace que el evaluador consulte la base de datos y devuelva **lo mismo** que devolvía, sólo más despacio. Un Redis vacío nunca puede encender un *flag*: la ausencia de entrada en caché no es «expuesto», es «hay que calcularlo», y el cálculo sin reglas da falso (`RN-BO-35`). **Si lo que cae es PostgreSQL, el evaluador tampoco inventa**: sin poder leer las reglas, todo *flag* es falso, que es la dirección segura — se pierde funcionalidad nueva, nunca se enciende la que no estaba.

---

## 4. La invalidación de caché, que es requisito y no mejora

`ADR-045 §8.3` lo marca como **de obligado cumplimiento en 1.6**. Hay **tres** cachés distintas —dos que este paso rompe y una que crea— y confundirlas es el error probable. **Las tres se invalidan de forma diferente, y eso no es una inconsistencia: es que los tres patrones de escritura son distintos** (§4.4).

### 4.1 `tenant-resolution:{slug}` — issue [#7](https://github.com/pirexia/plataforma-educativa/issues/7) · sub-paso `1.6b`

`ResolveTenant` cachea `{id, status}` durante 60 s. Hasta hoy nadie cambiaba `status`; **este sub-paso es el que deja de hacerlo cierto**.

- **Toda** escritura de `tenants.status` la invalida **en la misma operación** (`RN-BO-14`), no en un paso posterior ni en un *listener* que alguien pueda desregistrar.
- El **cambio de `slug`** invalida **dos** claves: la vieja y la nueva.
- **El alta y la clonación invalidan la clave de su `slug` nuevo.** Parece inútil y no lo es: un `slug` de un tenant eliminado se puede reutilizar, y la entrada del anterior puede seguir viva (`funcional.md §5.4.2`).
- Test: un tenant recién suspendido responde `503` **en el mismo segundo**, no en hasta 60 s (`CA-BO-054`).

**Tres detalles de implementación que deciden si esto funciona o sólo lo parece:**

| # | Detalle | Qué pasa si se ignora |
|---|---|---|
| 1 | **La clave vive bajo el prefijo base, no bajo `t{tenant_id}:`** | Nada, porque es correcto y sale solo: `ResolveTenant` escribe esa clave **antes** de que `TenantContext::enter()` cambie `cache.prefix`, y el backoffice corre sin tenant. **Se escribe aquí para que nadie «arregle» este caso copiando el mecanismo de §4.2**, que es el del caso contrario y produciría una clave que nadie lee |
| 2 | **Se invalida al confirmar la transacción, no dentro** | Entre el `forget` y el `COMMIT`, una petición concurrente relee el valor **anterior** y lo vuelve a cachear 60 s. El resultado es un tenant suspendido que sigue sirviendo: exactamente el defecto que se está cerrando, reintroducido por el arreglo. `CA-BO-113` |
| 3 | **La caché es compartida (Redis), no de proceso** | Con un almacén `array` o `file`, la invalidación sólo alcanza al nodo que escribe y los demás siguen sirviendo el estado viejo. **No da ningún síntoma en desarrollo con un proceso**, que es lo que lo hace peligroso |

> **Y una comprobación que no es de caché pero se descubre aquí**: `ResolveTenant` responde hoy `404` —no `503`— para `en_baja` y `eliminado`, y a un tenant con `deleted_at` ni siquiera lo encuentra. `RN-BO-50` lo corrige y `CA-BO-110`/`CA-BO-111` lo verifican. Es del mismo sub-paso porque lo activa la misma funcionalidad (`funcional.md §5.4.1`).

### 4.2 `modules:{code}:enabled` — `ADR-045 §8.3`, y es la difícil

La clave **no lleva `tenant_id`**: el aislamiento lo da el prefijo `t{tenant_id}:` que `TenantContext::enter()` fija sobre `config('cache.prefix')`, forzando `Cache::forgetDriver()` para que el almacén se reconstruya. `REQ-PERM/operacion.md §6.2` ya verificó que el mecanismo funciona y dejó escrito que la clave «parece insegura al leerla suelta».

**El problema de 1.6 es el otro lado**: el backoffice escribe **desde fuera del contexto del tenant**. Una invalidación ingenua limpiaría la clave del prefijo equivocado —el del backoffice, que no tiene tenant— y **una activación masiva la limpiaría equivocada tantas veces como centros**, dejando a los 200 esperando 300 s cada uno.

| Requisito | Verificación |
|---|---|
| Toda escritura de `enabled` invalida la clave **del tenant afectado, con su prefijo** | `CA-BO-032`: el centro llama a un *endpoint* del módulo recién contratado y responde `200` **de inmediato** |
| Una masiva invalida el prefijo correcto de **cada** centro | `CA-BO-033`: se comprueba además que un centro **no** afectado conserva su caché intacta |

La forma recomendada es entrar en el contexto de cada tenant afectado (`TenantContext::runFor()`) para invalidar, en vez de componer el prefijo a mano: componer una cadena de prefijo en dos sitios es la manera conocida de que los dos se separen.

> **Esta es la parte de `1.6c` que más fácil se implementa a medias y que no da ningún síntoma visible en desarrollo con un solo tenant.** Con un centro, cualquier invalidación parece funcionar. El test de `CA-BO-033` necesita **al menos dos**.

### 4.3 Evaluación de *feature flags* — sub-paso `1.6e`

La tercera, y la única que este paso **crea** en lugar de romper. Diseño en `datos.md §9.5`; aquí lo que hay que saber para operarla.

- **Clave**: incluye la clave del *flag*, el sujeto y **`rules_version`** del *flag*. Vive bajo el prefijo `t{tenant_id}:` como todo lo demás, porque se rellena dentro de la petición de un centro.
- **Invalidación**: **no la hay, en el sentido habitual.** Toda escritura de estado o de reglas incrementa `rules_version` en la misma transacción (`RN-BO-42`), y eso deja **inalcanzables de golpe** todas las entradas de todos los centros, sin tocar ninguna clave. Las huérfanas caducan por `BO_FLAG_CACHE_TTL`.
- **Por qué no se hace como §4.2**: una regla `global`, de cohorte o de porcentaje afecta a **todos** los centros. Recorrer doscientos prefijos dentro de la petición de escritura es lo que `INV-012` prohíbe; hacerlo en cola dejaría una ventana en la que unos centros ven el valor nuevo y otros el viejo — precisamente durante el minuto en que alguien está apagando algo que se ha roto.
- **Verificación**: `CA-BO-088`, y su segunda mitad es la importante — el cambio se ve en **todos** los centros de inmediato **y** la operación de escritura no ha recorrido los N prefijos.

### 4.4 Las tres cachés, en una tabla, para que nadie las unifique

| Caché | Qué invalida una escritura | Mecanismo | Sub-paso |
|---|---|---|---|
| `tenant-resolution:{slug}` | Un centro | Borrado explícito de la clave, en la misma operación | `1.6b` |
| `modules:{code}:enabled` | Un centro | Borrado explícito **entrando en el contexto de ese tenant** (`ADR-045 §8.3`) | `1.6c` |
| Evaluación de *flags* | **Todos** los centros | **Versión en la clave**: nada se borra, todo queda inalcanzable | `1.6e` |

> **La reacción natural de una revisión posterior será proponer un solo mecanismo para las tres, y hay que decir por qué no.** No se unifican porque no resuelven el mismo problema: las dos primeras son «una escritura, un centro» y la tercera es «una escritura, todos los centros». Aplicar el mecanismo de la tercera a las dos primeras añadiría una columna de versión a `tenants` y a `module_subscriptions` para un caso que no la necesita; aplicar el de las dos primeras a la tercera produce una invalidación que no termina dentro de la petición. Cada una usa lo que le corresponde, y esta tabla existe para que la próxima persona no tenga que volver a razonarlo.

---

## 5. Arranque: el sistema se despliega bloqueado

**No hay ningún administrador de plataforma, ninguna entrada en la lista blanca de IP, y las dos ausencias deniegan** (`RN-BO-07`). Es correcto y es deliberado: la alternativa —una cuenta por defecto o una lista blanca que vacía significa «todo»— es la vulnerabilidad más repetida de la historia de los paneles de administración.

Procedimiento de arranque, **en este orden**, y `SYSADMIN.md` debe recogerlo:

| # | Paso | Nota |
|---|---|---|
| 0 | **Reglas `Host()` e `ipallowlist` de Traefik desplegadas** (§0.2), y `BACKOFFICE_HOST` fijada | **Antes que nada.** Sin `Host()`, la SPA de los centros se sirve bajo el *host* del backoffice y el grupo `/api/platform` es alcanzable desde el *host* de cualquier centro (§0.1). Comprobar además §0.3 si hay algún proxy delante de Traefik |
| 1 | Migraciones por `pgsql_owner` | Incluidas la de `platform_sessions` y `platform_admin_sessions` con su `REVOKE ALL … FROM plataforma_app` (`datos.md §2.6`, `§12` fila 1b) y la de privilegios de `module_subscriptions` (`datos.md §7`) |
| 2 | `php artisan platform:sync-registry` | Gana la validación de `depends_on` que **aborta el despliegue** ante código inexistente o ciclo (`CA-BO-034`, `CA-BO-035`) y, desde `1.6e`, la del catálogo de *feature flags*: clave duplicada entre dos módulos, clave con formato inválido o `module_code` inexistente **también abortan** (`CA-BO-089`). Si aborta, **no se sigue**: se arregla el descriptor. **Es un solo comando y un solo punto de aborto**, a propósito: un segundo comando de sincronización sería un segundo sitio del que olvidarse |
| 3 | `php artisan bo:allow-ip <cidr> --description="…"` | **Desde el servidor.** Sin esto no entra nadie, ni con credenciales correctas |
| 4 | `php artisan bo:create-admin --email=… --role=superadministrador` | Crea sin contraseña utilizable y emite invitación. `actor_type = 'console'` en `admin_action_logs` |
| 5 | **Repetir el paso 4 para un segundo `superadministrador`** | **No es opcional.** Con una sola cuenta, eliminar un tenant es imposible por diseño (`RN-BO-19`, `OPEN-BO-09`) |
| 6 | El primer administrador canjea su invitación, fija contraseña y **da de alta su segundo factor** antes de alcanzar nada más | `RN-BO-05` |

### 5.1 Salida de un bloqueo total

Es el escenario que hay que tener escrito **antes** de que ocurra: la lista blanca se queda sin ninguna entrada que cubra a nadie, o el único administrador pierde su segundo factor.

| Situación | Salida |
|---|---|
| Nadie cubierto por la lista blanca | `php artisan bo:allow-ip` desde el servidor. **Requiere acceso al *host***, que es exactamente la barrera que se quiere |
| El único administrador pierde su segundo factor | `php artisan bo:reset-mfa <email>` desde el servidor, auditado como `console`. **No hay vía por correo ni por la API** (`CA-BO-012`) |
| No queda ningún `superadministrador` | `bo:create-admin`. `RN-BO-11` impide llegar ahí por la API, pero no impide un borrado por base de datos |
| **`1.6b`** · Un tenant se queda atascado en `en_alta` porque su aprovisionamiento falló | `php artisan bo:retry-provisioning <slug>`, que reencola un trabajo **idempotente** (§6.1). Auditado como `console`. **No es un *endpoint***: no hace falta capacidad nueva ni pantalla, y el camino de recuperación de este módulo ya es la consola. **Y nunca se arregla escribiendo `status = 'activo'` a mano**: dejaría un centro marcado como listo sin roles, sin configuración y sin administrador (`RN-BO-52`, `funcional.md §5.3.5`) |
| **Todo el backoffice responde `404`** | `BACKOFFICE_HOST` no coincide con el *host* por el que se entra, o la regla `Host()` de Traefik apunta a otro nombre. Se corrige en configuración y despliegue (§0), **no en la aplicación**: `RequirePlatformHost` está haciendo exactamente lo que debe (`RN-BO-48`) |

**Los cuatro comandos exigen acceso de consola al servidor y los cuatro escriben en `admin_action_logs`** —los tres del chasis y el que añade `1.6b`—. Un mecanismo de recuperación sin rastro sería una puerta trasera con otro nombre.

---

## 6. Colas y tareas programadas (`INV-012`)

### 6.1 Trabajos en cola

| Trabajo | Cuándo | Nota |
|---|---|---|
| `ProvisionTenant` | Tras crear un tenant | Los 16 roles, sus concesiones, la configuración y el primer administrador. **Debe completarse en segundos** (nota para el implementador de `REQ-BO-005`): es operación de datos, no despliegue. **Es la fase 2 del alta** (`funcional.md §5.3.3`): al terminar escribe la transición `en_alta` → `activo` e invalida la caché de resolución. **No implementa el aprovisionamiento**: llama a `TenantProvisioner::provision()`, el contrato público de `REQ-CORE` que fija `ADR-048` (`RN-BO-53`). El fallo le llega como **excepción**, no como valor de retorno: la captura, escribe `tenant.aprovisionamiento_fallido` con el mensaje en `context` y deja que Laravel agote los reintentos |
| `RunModuleRollout` | Activación masiva | Idempotente por `Idempotency-Key`. Emite **un evento por centro** (`RN-BO-27`) e invalida **el prefijo de cada centro** (§4.2) |
| `CloneTenant` | Clonación | Copia configuración operativa, roles, concesiones y suscripciones, **en una sola lectura transaccional del origen**. **Nunca personas** (`RN-BO-21`, `RN-BO-59`): crea un primer administrador nuevo a partir del cuerpo de la petición. **Tampoco implementa la copia de lo que es de `REQ-CORE`** —`tenant_settings`, `roles` y `permission_role`—: llama a `TenantProvisioner::provisionFromTemplate()`, que es donde ocurre la lectura transaccional del origen (`ADR-048 §4.5`, `funcional.md §5.6.2`). Lo único que copia el propio trabajo es **`module_subscriptions`**, del que el backoffice es único escritor por `ADR-045 §4.1` |
| `RevokeTenantSessions` | **Sólo** tras ejecutarse una eliminación (`1.6b`) | Cierra todas las sesiones vivas de los usuarios de ese centro con `baja_usuario`. **Entra en el contexto del tenant con `runFor()`**; no toca ningún otro. **No se encola al suspender ni al dar de baja** (`RN-BO-55`), y no hay ningún *endpoint* que lo dispare por su cuenta (`permisos.md §5`) |

**Los cuatro trabajos de tenant son idempotentes y hay que probarlo, no suponerlo.** `ProvisionTenantDefaults` ya lo es —comprueba la existencia de `TenantSetting` antes de escribir, verificado sobre el código—, y de ahí sale la propiedad que hace posible `bo:retry-provisioning` (§5.1): reintentar un alta a medias no duplica roles, concesiones, personas ni invitaciones (`CA-BO-108`). **Desde `ADR-048 §4.3` esa idempotencia deja de ser silenciosa**: el contrato devuelve `TenantProvisioningOutcome::Provisioned` o `AlreadyProvisioned`, de modo que `bo:retry-provisioning` puede decirle al operador si reparó algo o si no había nada que reparar, y `CA-BO-108` puede comprobarlo sin contar filas. **`provisionFromTemplate()` es idempotente por la misma comprobación** (`ADR-048 §5.3`).

**Los tres entran y salen del contexto de cada tenant con `runFor()`.** `ADR-033 §8` estampa el `tenant_id` en el *payload* de todo trabajo automáticamente — pero **estos trabajos los encola el backoffice, que no tiene tenant**: el tenant afectado viaja como dato del trabajo, no como contexto heredado, y el trabajo entra en él explícitamente. Es la diferencia que hace que `Queue::looping()` no aborte el *worker*.

### 6.2 Tareas programadas

| Tarea | Frecuencia | Qué hace |
|---|---|---|
| `bo:expire-dual-authorizations` | Cada 15 min | Pasa a `caducada` lo vencido. `actor_type = 'system'` |
| `bo:close-orphaned-sessions` | **Cada 15 min** | Cierra como `caducidad` las filas vivas de `platform_admin_sessions` cuyo `session_id` ya no está en `platform_sessions`: anula `session_id`, fija `ended_at` y escribe `end_reason = 'caducidad'`. §6.3 |
| `bo:check-grace-periods` | Diaria | Marca los tenants cuyo período de gracia venció y **avisa**. **No borra nada** (`RN-BO-17`). Detalle en §6.4 |
| `bo:purge-mfa-challenges` | Cada hora | Desafíos caducados, igual que su homóloga de 1.3 |

**Los *feature flags* no añaden ni un trabajo en cola ni una tarea programada**, y merece una frase porque es lo contrario de lo que se espera de un motor de despliegue progresivo. No hay nada que barrer —el reparto por porcentaje se calcula, no se almacena (`RN-BO-38`)—, nada que caducar —una regla vive hasta que alguien la cambia— y nada que recalcular cuando aparece un centro nuevo, que es justamente la propiedad por la que se eligió una función determinista en vez de una tabla de asignaciones. **Si en la implementación aparece un job de «recalcular cubos» o de «sincronizar exposiciones», el diseño se ha desviado de `funcional.md §5.11.6`** y hay que volver a él, no añadir el job.

> **`bo:check-grace-periods` no borra, y es una decisión.** Una purga automática por temporizador sobre los datos de un centro entero —con datos de menores dentro— no puede depender de que nadie haya olvidado prorrogar el plazo. Marca y avisa; borrar exige una persona, doble autorización y `REQ-PRIV-006` (`funcional.md §2.2`, `OPEN-BO-05`).

### 6.3 El barrido de sesiones huérfanas de plataforma (`ADR-047 §5.1`)

*Job* `CloseOrphanedPlatformSessions` y comando `bo:close-orphaned-sessions`, en `app/Modules/Backoffice/Infrastructure/`, con el **precedente exacto** de `CloseOrphanedUserSessions` del módulo `Auth` (`REQ-AUTH/operacion.md §B.3` y `§B.3.1`), del que copia forma y periodicidad.

| Aspecto | Aquí | Su homóloga de `1.2b` |
|---|---|---|
| Qué cierra | Filas vivas de `platform_admin_sessions` sin fila en `platform_sessions` | Filas vivas de `user_sessions` sin fila en `sessions` |
| Cómo las cierra | `session_id` a nulo, `ended_at` fijado, `end_reason = 'caducidad'` | Ídem, con su propio vocabulario |
| **¿Es una purga?** | **No.** No borra ni redacta ninguna fila. Comparte cola con las purgas y **no** periodicidad, igual que `CloseExpiredLockouts` | Ídem |
| Contexto de tenant | **Ninguno.** Corre **fuera** de todo tenant, sobre dos tablas de plataforma. **No usa `RunsPerTenant`** | Se ejecuta **por tenant**, con `RunsPerTenant` |
| Rol de base de datos | `plataforma_platform`: `plataforma_app` no puede ni leer estas dos tablas (`datos.md §2.6`, `§2.7`) | `plataforma_app`; son tablas de tenant ordinarias |

**Por qué existe, y por qué no es opcional.** `datos.md §2.7.1` decide, siguiendo `ADR-047 §5.1`, que `session_id` **no lleva clave foránea**: en el instante del `INSERT` la fila de `platform_sessions` todavía no existe —el *driver* la escribe al final de la petición— y una clave foránea rompería **todos** los inicios de sesión de plataforma. El barrido es lo que se pone en su lugar, y resuelve dos cosas que la clave foránea no resolvía ninguna:

1. **`end_reason = 'caducidad'` está en el `CHECK` de la tabla y sin este barrido no tiene ningún escritor.** El recolector del *driver* borra de `platform_sessions` y no toca `platform_admin_sessions`. Sin barrido, toda sesión caducada se queda con `ended_at IS NULL` **para siempre**, y el índice `(platform_admin_id, started_at DESC) WHERE ended_at IS NULL` —que es **la consulta de la revocación**— devuelve sesiones muertas. Suspender a alguien por un incidente mostraría datos falsos, que es justo el caso de uso con el que esa tabla se justifica (§8).
2. Un `ON DELETE SET NULL` sólo actúa cuando el recolector borra y **no puede escribir columnas ajenas**: nunca fijaría `ended_at` ni `end_reason`.

**Por qué cada 15 minutos y no cada 5**, con el mismo razonamiento que su homóloga: una sesión huérfana sin cerrar **no bloquea nada** —no ocupa el hueco de ningún índice único, a diferencia de un bloqueo vencido en `CloseExpiredLockouts`— y lo único que produce mientras tanto es una fila de más entre las sesiones vivas de un administrador. Quince minutos acotan esa ventana sin convertir un barrido de mantenimiento en trabajo de camino caliente.

> **`1.6` no tiene el cierre perezoso que `1.2b` puso en su listado de sesiones** (`REQ-AUTH/funcional.md §B.4.2`), y no se adelanta: no hay pantalla de sesiones de plataforma (`funcional.md §12.5`) ni *endpoint* que las liste (`datos.md §2.7`). **La tarea, en cambio, hace falta desde el primer día**, precisamente porque sin cierre perezoso nada más cierra esas filas — y la revocación, que sí existe en `1.6`, lee ese índice.

Verificado por `CA-BO-104`.

### 6.4 `bo:check-grace-periods`: qué hace exactamente, y qué **no** hace (`1.6b`)

`RN-BO-17` dice «se marca como candidato y se avisa». Las dos palabras son ambiguas y las dos se dan por implementadas con facilidad, así que aquí van sin margen:

**Para cada tenant con `status = 'en_baja'`, `grace_period_ends_at < now()` y `grace_period_expired_at IS NULL`:**

1. Escribe `tenants.grace_period_expired_at = now()`. **Una sola vez**: la condición de la consulta lo garantiza y es lo que hace la tarea idempotente frente a una ejecución diaria (`CA-BO-122`).
2. Escribe **una** entrada en `admin_action_logs` con `action = 'tenant.gracia_vencida'`, `actor_type = 'system'` y `affected_tenant_id`.
3. Emite la señal de §7 para que el vencimiento sea visible en operación.

**Y no hace nada más. En concreto, no hace estas cuatro:**

| No hace | Por qué |
|---|---|
| **No borra ni anonimiza nada** | `RN-BO-17`. Una purga por temporizador sobre datos de un centro entero —con datos de menores— no puede depender de que nadie haya olvidado prorrogar el plazo. Borrar exige una persona, doble autorización y `REQ-PRIV-006` |
| **No transita el tenant a `eliminado`** | Ídem, y además `RN-BO-18` exige cuatro cerrojos que una tarea programada no puede cumplir: no hay solicitante, no hay reautenticación y no hay dos personas |
| **No escribe en `tenant_lifecycle_events`** | No ha habido transición. Esa tabla es la máquina de estados, no un diario (`datos.md §6.2`) |
| **No notifica a nadie**, ni al centro ni al operador | No existe infraestructura de notificaciones hasta `REQ-COM` (paso 1.19). **«Avisar» es, en `1.6b`, exactamente los puntos 2 y 3 de arriba más el filtro `grace_expired` del inventario** (`api.md §3.3`). Se dice así de claro porque «y se avisa» es la clase de frase que se da por construida sin que nadie haya construido el aviso |

**El tenant sigue siendo rescatable después de vencer**, y el rescate pone `grace_period_expired_at` a nulo (`RN-BO-58`): si el plazo vuelve a vencer tras una segunda baja, la tarea vuelve a marcarlo y a registrarlo, que es lo correcto.

---

## 7. Métricas y alertas

| Señal | Por qué | Umbral |
|---|---|---|
| `acceso.rechazado_por_ip` | Alguien conoce el *host* del backoffice y no está en la red permitida. **Es la señal más valiosa de este módulo** | **Alarma** ante cualquier ráfaga |
| Fallos de autenticación de plataforma | Fuerza bruta contra la cuenta más peligrosa del producto | Alarma sobre tasa, no sobre eventos sueltos |
| `dual_authorizations` caducadas sin resolver | Operaciones destructivas que se piden y nadie atiende. Un patrón sostenido significa que el proceso no funciona y acabará en presión para saltárselo | Informativo, revisado |
| Duración de `ProvisionTenant` | Debe ser de segundos | **Más de un minuto es un defecto**, no una carga |
| Aristas de dependencia incoherentes reportadas por `platform:sync-registry` | Centros con `M` contratado y `N` no, tras una versión que añadió la arista (`ADR-045 §4.5`) | Se muestran en la ficha de salud del centro (`funcional.md §5.9`), **no se corrigen solas** |
| Crecimiento de `admin_action_logs` | Alimenta el disparador de revisión de particionado de `datos.md §4.4` | 10 M de filas |
| Uso de `forced_off` | Cada vez que se usa, algo se ha roto en producción. **Es la señal de calidad más directa que produce este módulo** | **Informativo por evento, alarma por repetición** sobre el mismo *flag*: apagar dos veces la misma funcionalidad significa que se volvió a encender sin arreglarla |
| *Flags* estancados en despliegue parcial | Un *flag* que lleva semanas al 30 % es un despliegue que nadie terminó: deuda con dos caminos de código vivos y una funcionalidad que unos centros tienen y otros no sin que nadie sepa por qué | Informativo, revisado. **No se resuelve solo ni se sube el porcentaje automáticamente**: subirlo es una decisión de producto |
| Latencia del evaluador de *flags* | Corre en **cada petición de cada centro** (`funcional.md §9`). Es el único componente de los cinco sub-pasos del que eso es cierto | Cualquier degradación medible del percentil 95 es un defecto, no una carga |

**No se alarma sobre `403`.** Es el funcionamiento normal de la denegación por defecto, y alarmar sobre él enseñaría a ignorar la alarma — mismo criterio que `REQ-PERM/operacion.md §6.1`. **Sí se alarma sobre `403` por IP**, que es otra cosa: no es un usuario sin permiso, es alguien que no debería estar llamando a esa puerta.

---

## 8. Problemas conocidos y diagnóstico

| Síntoma | Primera comprobación | Causa probable |
|---|---|---|
| «Nadie puede entrar al backoffice recién desplegado» | `SELECT count(*) FROM platform_ip_allowlist WHERE enabled` | §5, pasos 3-4. **Es el fallo más probable de este despliegue** y es intencionado |
| «El backoffice devuelve `404` en todo, incluso en `/csrf-cookie`» | `BACKOFFICE_HOST` frente al `Host` con el que se entra, y la regla `Host()` del *router* | `RN-BO-48`. **Un `404` aquí no es una ruta que falta**: es `RequirePlatformHost` funcionando (§0.4, §5.1) |
| «La lista blanca de IP no bloquea a nadie» | La IP que queda registrada en `admin_action_logs` para un acceso legítimo | **§0.3**: si es una dirección interna o del NAT, falta `forwardedHeaders.trustedIPs` y la lista del *ingress* está desactivada de hecho, **sin dar ningún síntoma**. La de la aplicación sigue aplicando; la del proxy no |
| «Bajo el *host* del backoffice se sirve la SPA de los centros» | La regla del *router* `plataforma-web` | §0.1, consecuencia 2: sigue en `PathPrefix(/)` con prioridad 1 y le falta el `Host()` |
| «Al suspender a un administrador sigue dentro» | `platform_admin_sessions` con `ended_at IS NULL` para ese administrador | `datos.md §2.7`: suspender debe **revocar** sus sesiones vivas, no sólo impedir que vuelva a entrar |
| «La lista de sesiones vivas de un administrador muestra sesiones que ya no existen» | Que `bo:close-orphaned-sessions` esté en el planificador y corriendo | §6.3. **Sin esa tarea, `end_reason = 'caducidad'` no tiene ningún escritor** y toda sesión caducada queda con `ended_at IS NULL` para siempre. Síntoma característico: las filas fantasma desaparecen solas a los 15 minutos cuando la tarea sí corre |
| «Ningún administrador de plataforma puede iniciar sesión, y falla la inserción de `platform_admin_sessions`» | Si alguien ha añadido la clave foránea `session_id → platform_sessions.id` | `datos.md §2.7.1`, `ADR-047 §5.1`: **esa columna no lleva clave foránea a propósito**. La fila referenciada no existe todavía en el instante del `INSERT`, y la escritura va en transacción con el login |
| «Una consulta del backoffice devuelve filas de todos los tenants sin pasar por `runAsPlatform()`» | Si el *middleware* de sesión de plataforma toca `database.default` o llama a `DB::setDefaultConnection()` | `datos.md §2.6.3`. **Sólo puede fijar `session.connection = 'pgsql_platform'`.** Dejar `pgsql_platform` como conexión por defecto pone `BYPASSRLS` de fondo en todo el grupo de rutas y vacía `ADR-046 §6` sin dar ningún síntoma. Es lo que `CA-BO-105` existe para impedir |
| «Entro pero no puedo hacer nada» | `mfa_enrolled_at` del administrador | `RN-BO-05`: sin factor confirmado sólo se alcanza `/mfa/*` |
| «Contrato un módulo y el centro sigue viendo 403» | Que la escritura invalide la caché **con el prefijo del tenant** | §4.2. Con un solo tenant en desarrollo esto **parece funcionar** aunque esté mal |
| «Suspendo un centro y sigue entrando» | Que la transición invalide `tenant-resolution:{slug}` | §4.1, issue #7. **Y si invalida pero sigue fallando en algunos nodos y no en otros**: el almacén de caché no es compartido (§4.1, detalle 3) |
| «Suspendo un centro y sigue entrando **sólo durante unos segundos**» | Dónde se ejecuta la invalidación respecto del `COMMIT` | §4.1, detalle 2: invalidar **dentro** de la transacción deja que una petición concurrente recachee el valor anterior. Va en el `afterCommit` |
| «Un centro dado de baja o eliminado responde `404` en vez de `503`» | El mapa de estados de `ResolveTenant` y si la búsqueda incluye borrados lógicos | `RN-BO-50`. **Es el comportamiento de hoy y es un defecto**, no una decisión: `funcional.md §5.4.1` |
| «Un tenant lleva horas en `en_alta`» | `failed_jobs` del tenant y la última entrada `tenant.aprovisionamiento_fallido` | `funcional.md §5.3.5`. Se repara con `bo:retry-provisioning`, **nunca** escribiendo `status` a mano (§5.1) |
| «El alta ha devuelto `201` pero el centro no tiene roles» | `provisioning.state` de la ficha | Es normal durante unos segundos: el alta tiene **dos fases** (`RN-BO-52`). Si el estado es `fallido`, es la fila de arriba |
| «Elimino un tenant y sus usuarios siguen con la sesión abierta» | Que `RevokeTenantSessions` esté encolado y el *worker* corriendo | `RN-BO-55`. Mientras tanto, el `503` ya los bloquea: la revocación es para que no queden credenciales vivas, no para cerrar la puerta |
| «Suspendo un centro y a sus usuarios se les ha cerrado la sesión» | **Es un defecto** | `RN-BO-55`: suspender **no** revoca. Si ocurre, alguien ha encolado la revocación en la transición equivocada y ha roto «al reactivarlo, todo vuelve sin pérdida» |
| «El registro se llena de avisos de gracia vencida, uno por día» | `tenants.grace_period_expired_at` | §6.4: la tarea sólo actúa sobre los que tienen esa columna a nulo. Si se reescribe, la condición de la consulta está mal |
| «He clonado un centro y le faltan el logo y el CIF» | **No es un fallo** | `funcional.md §5.6.2`: no se copian a propósito, y la respuesta de la operación lo dice en `not_copied` (`api.md §2.4.3`) |
| «No puedo eliminar un tenant de pruebas» | Cuántos `superadministrador` hay | `RN-BO-19`: hacen falta **dos personas**. §5 paso 5 |
| «`platform:sync-registry` aborta el despliegue» | El mensaje: código inexistente o ciclo | `CA-BO-034`/`CA-BO-035`. **Es el comportamiento correcto**: mejor un despliegue detenido que un catálogo con una arista rota |
| «Un centro tiene `M` sin `N` y el sistema no lo arregla» | Ficha de salud del centro | `ADR-045 §4.5`: el comando **informa y no corrige**. Contratar automáticamente sería tomar una decisión comercial facturable sobre 200 centros |
| «La aprobación de una doble autorización falla con `409`» | Si aprobador y solicitante son la misma persona | `RN-BO-19`, y lo rechaza la base de datos, no el controlador |
| «El centro no ve las acciones de plataforma que le afectan» | Que la fila tenga `affected_tenant_id` | `RN-BO-31`. Las de alcance global (`NULL`) **no las ve ningún tenant**, y es correcto |
| «`GET /api/v1/platform-actions` falla con error de privilegios de PostgreSQL» | Qué columnas pide la consulta | `datos.md §4.3`: `plataforma_app` tiene `SELECT` sobre **seis columnas enumeradas** y ninguna más. Un `SELECT *`, un `ORDER BY id` o cualquier `reason`/`context`/`ip_address` en la proyección da este error. **Es el comportamiento correcto y es ruidoso a propósito** (`ADR-047 §4.4`); se arregla en la consulta, **no ampliando el `GRANT`** |
| «Un cursor de auditoría de plataforma devuelve `422`» | Que lo haya emitido **esta** sesión | `api.md §3.2`: el cursor lleva el `platform_admin_id`, no un `tenant_id` |
| «Este centro ve una funcionalidad que no debería» | `GET /tenants/{id}/feature-flags` y su campo **`matched_by`** | `api.md §2.13`. Dice **por qué regla** está expuesto: nominal, cohorte o porcentaje. Sin ese campo, se acaba adivinando |
| «He cambiado un porcentaje y no pasa nada» | Que `rules_version` se haya incrementado | §4.3. Si la versión no sube, la escritura no invalidó nada y todos los centros siguen leyendo la entrada anterior hasta que caduque el TTL |
| «El *flag* está al 100 % y el centro sigue recibiendo 403» | Si el centro tiene contratado el módulo | `RN-BO-45`: son dos comprobaciones distintas y la de módulo va primero. **Un *flag* no enciende un módulo no contratado** (`CA-BO-092`) |
| «He subido el porcentaje y un centro ha perdido la funcionalidad» | **Es un defecto, no un efecto** | `RN-BO-38`: el reparto es monótono. Si ocurre, el cálculo del cubo no es determinista —o entra algo que cambia entre peticiones— y hay que revisarlo antes de seguir |
| «Los mismos centros son siempre los conejillos de indias» | Que la clave del *flag* entre en el hash | `RN-BO-39`. Si no entra, todos los repartos al mismo porcentaje caen sobre el mismo conjunto |
| «Un rol nuevo del centro no ve el *flag* que le corresponde» | El **código** del rol contra el de la regla | `RN-BO-40`: la regla compara códigos. Un código que no existe en ese centro no expone a nadie, y eso **no** es un error de la regla |
| «El *flag* funciona en la petición web pero no en un job» | Si tiene reglas de rol | `RN-BO-41`: sin sujeto usuario, un *flag* con filtro de rol es falso. Es fallo en cerrado, no un defecto |
| «`platform:sync-registry` aborta por una clave de *flag*» | Clave duplicada entre dos módulos, formato inválido o `module_code` inexistente | `CA-BO-089`. **Comportamiento correcto**: un catálogo de *flags* con una clave ambigua enciende cosas distintas según qué módulo cargue primero |

---

## 9. Impacto en copias de seguridad y restauración

- **Trece tablas nuevas entran en la copia de plataforma** (`REQ-BKP-001`): las nueve del chasis, `platform_sessions` y `platform_admin_sessions`, y `feature_flags` y `feature_flag_rules`. **`platform_sessions` es la única que no vale la pena restaurar**: sus filas caducan solas y restaurarla sólo revive sesiones que ya deberían haber muerto — se copia porque copiar la base entera es más simple que excluir una tabla, y se vacía al restaurar.
- **Ninguna entra en la copia por tenant** (`REQ-BKP-002`): no pertenecen a ningún centro. La única excepción a considerar cuando `REQ-BKP` llegue son las filas de `admin_action_logs` y `tenant_lifecycle_events` **de ese centro**, que sí forman parte de lo que le corresponde llevarse. Las dos de *flags* tampoco: describen el despliegue del producto, no el centro.
- **Restaurar la base de datos de plataforma a un punto anterior revierte el estado de los *flags***, y eso puede **encender** algo que se había apagado con `forced_off`. Es el único caso de este módulo en el que una restauración va en la dirección insegura, y por eso debe comprobarse **antes** de dar el servicio por restaurado: la lista de *flags* en `forced_off` es corta y se revisa en un minuto. Debe constar en el procedimiento de `REQ-BKP-003`.
- **`admin_action_logs` y `tenant_lifecycle_events` son *append-only***: una restauración a un punto anterior **pierde entradas de auditoría**, y eso es una pérdida de prueba, no de dato operativo. Debe constar en el informe posterior a toda restauración (`REQ-BKP-003`).
- **Restaurar a un punto anterior a este despliegue** deja el sistema sin administradores de plataforma: hay que rehacer §5 completo.
- **Nada de este módulo vive fuera de PostgreSQL** salvo la sesión y las cachés, que se reconstruyen solas. No hay artefactos en S3.

---

## 10. Impacto en documentos raíz

Trabajo de cierre del paso, **no opcional** (`CLAUDE.md §6`, regla 7):

| Documento | Qué añadir |
|---|---|
| `SYSADMIN.md` | El procedimiento de arranque de §5 completo —**incluido el paso 0**, las reglas de Traefik—, con el paso 5 (**segundo `superadministrador`**) marcado como obligatorio; los tres comandos de recuperación de §5.1; las variables de §2, con `BACKOFFICE_HOST` y su restricción de `RN-BO-49`; **§0 entero**: las reglas `Host()` e `ipallowlist` de `infra/quadlet`, **el aviso de `forwardedHeaders.trustedIPs` de §0.3 y su comprobación operativa** |
| `ARCHITECTURE.md` (`ADR-047`) | **La quinta categoría de la taxonomía de tablas**: «plataforma con visibilidad por tenant afectado» —columna `affected_tenant_id`, política `tenant_visibility` de solo lectura, `GRANT SELECT` de columnas enumeradas—, y la convención de nombres que la acompaña: **`tenant_id` significa propiedad y sólo propiedad**, en los 53 módulos. Es la parte de `ADR-047` que vincula a todo el proyecto y no sólo a este paso |
| `SECURITY.md` (`ADR-047`) | **Los datos personales del personal del proveedor no son alcanzables desde el *runtime* de un centro**, y lo garantiza un `GRANT` de columna, no la proyección de un *endpoint*. Con ello, `plataforma_app` gana `SELECT` sobre un subconjunto de columnas de dos tablas que no son suyas: **es superficie nueva, creada a sabiendas**, y `ADR-047 §8` la deja anotada como riesgo residual acotado —política que falla en cerrado en los dos sentidos, columnas enumeradas, tests que lo comprueban—. Debe constar como riesgo aceptado, no descubrirse después |
| `SECURITY.md` | **Un sujeto de autenticación nuevo en el producto.** Deja de haber un solo tipo de cuenta. Hay que describir: los cuatro roles internos, el MFA incondicional, la lista blanca de IP **en sus dos capas** (§0.3), la doble autorización y la auditoría de plataforma independiente. Y las **cuatro barreras** que sostienen «un usuario de un tenant nunca alcanza el backoffice» (`ADR-046 §8`): `Host()` en Traefik, `ipallowlist` en el *ingress*, `RequirePlatformHost` en la aplicación, y *guard*, cookie y **tabla de sesión** distintos. Al cerrar `1.6e`, además: **ningún control de seguridad vive detrás de un *feature flag*** (`RN-BO-47`), y un *flag* no concede acceso a datos (`permisos.md §5.3`) |
| `ARCHITECTURE.md` (`1.6e`) | El motor de *feature flags*: catálogo declarado en código, reglas en base de datos, evaluador en `REQ-CORE` y no en `REQ-BO`, y **por qué el reparto por porcentaje es una función determinista y no una tabla** |
| `CONTRIBUTING.md` (`1.6e`) | Cómo se declara un *flag* nuevo en el descriptor de un módulo, y la regla de que **un *flag* se crea escribiendo el código que lo consulta** — no hay pantalla que los cree (`RN-BO-34`). Es la parte que un desarrollador nuevo necesita y que no está en ningún otro sitio |
| `PRIVACY.md` | `platform_admins` es un tratamiento de datos personales **del personal del proveedor**, con base legal propia (relación laboral o de servicio), distinto de los tratamientos en los que somos encargados. Y el acceso del proveedor a los sistemas del cliente queda documentado con su registro |
| `ARCHITECTURE.md` | La segunda superficie HTTP y su frontera, **según la decide `ADR-046 §4`**: mismo monolito y mismo despliegue de API, con *guard*, grupo de rutas y **SPA propia (`apps/backoffice`)**. En el diagrama entra un contenedor nuevo —el de estáticos del backoffice— y **dos *routers* de Traefik enrutados por `Host()`**, no por `PathPrefix`. Y la frontera de `App\Support\Tenancy`: `runAsPlatform()` con propósito declarado (`funcional.md §6.2`) |
| `RUNBOOK.md` | §5.1 (salida de un bloqueo total) y §8 (diagnóstico). **Al cerrar `1.6b`**: el tenant atascado en `en_alta` y su reparación con `bo:retry-provisioning`, y el diagnóstico del `503`/`404` por estado |
| `SECURITY.md` (`1.6b`) | **Qué revoca y qué no revoca cada transición de un centro** (`RN-BO-55`): eliminar cierra todas las sesiones de sus usuarios; suspender y dar de baja las dejan vivas y las bloquea `ResolveTenant`. Y el mapa de respuestas por estado de `RN-BO-50`, que es lo que garantiza que un centro sin acceso no devuelva **ningún** dato |
| `PRIVACY.md` (`1.6b`) | **Un centro eliminado conserva íntegros todos sus datos, incluidos los de menores, sin plazo de purga definido** (`funcional.md §5.5.3`). Es el nivel 1 de `ADR-004` y es deuda declarada contra `REQ-PRIV-006`, con el riesgo aceptado por el usuario el 2026-09-08 (`OPEN-BO-05`). **Tiene que constar como decisión, no descubrirse en una auditoría** |
| `SYSADMIN.md` (`1.6b`) | La tarea diaria `bo:check-grace-periods` y qué significa exactamente su marca (§6.4), y el comando `bo:retry-provisioning` |
| `CHANGELOG.md` | Una entrada por cada sub-paso cerrado (`CLAUDE.md §6.7`) |
| `docs/manual-usuario/admin.md` | **Nada.** El Administrador de Centro no alcanza el backoffice. Lo que sí cambia en su manual es consecuencia de `ADR-045`: **ya no activa ni desactiva módulos**, sólo los consulta y configura. Es una capacidad que el manual describía y que desaparece |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` | **Ya actualizado** a 3.2.0 por el encargo de `ADR-045 §10`, con su fila de historial |
| `docs/modulos/REQ-CORE/funcional.md` | **Ya actualizado**: §2 y `OPEN-CORE-03` marcados como resueltos, `CA-CORE-061` reforzado |
| `docs/modulos/REQ-CORE/funcional.md` | **Pendiente al cerrar `1.6b`** (`ADR-048 §7`, `§11` punto 3): su `§7` enumera las cinco interfaces públicas que `REQ-CORE` expone en su `Domain` y tendrá que añadir la sexta, **`TenantProvisioner`**. Mientras no se añada, `REQ-CORE` es dueño de un contrato público que su propia especificación no documenta — el mismo defecto que `1.6e` arrastra con el evaluador de *feature flags*, dos filas más abajo |
| `docs/modulos/REQ-CORE/permisos.md` | Pendiente al cerrar `1.6c`: la fila de `modulo.actualizar` debe decir que su alcance es **sólo `settings`**, respaldado por privilegio de columna y no sólo por validación |
| `docs/modulos/REQ-CORE/funcional.md` | **Pendiente al cerrar `1.6e`, y detectado en la revisión del 2026-09-08**: su `§2.3` enumera lo que `1.6` añade a `REQ-CORE` —camino de escritura de módulos, los dos eventos, invalidación de caché— y **no menciona el evaluador de *feature flags***, que esta especificación asigna a `REQ-CORE` y no a `REQ-BO` (`funcional.md §9`). No es una contradicción: `§2.3` se escribió antes de que los *flags* entraran en alcance. Pero si `1.6e` cierra sin añadir esa línea, `REQ-CORE` acaba siendo dueño de un componente que su propia especificación no documenta |
| `docs/modulos/REQ-CORE/api.md` | Pendiente al cerrar `1.6e`: `GET /api/v1/feature-flags` es ruta suya, no de plataforma (`api.md §2.14`). Igual que `GET /api/v1/platform-actions` al cerrar `1.6` |

---

## 11. Reversión (`CLAUDE.md §9`)

| # | Paso | Nota |
|---|---|---|
| 1 | Desplegar la versión anterior | |
| 2 | Retirar de Traefik los *routers* del backoffice que estén desplegados | Sin ellos, la superficie deja de existir aunque el código siga desplegado (§0.2) |
| 3 | **Decidir qué se hace con las reglas `Host()` de `plataforma-web` y `plataforma-api`** | **No se revierten sin pensarlo.** Volver a `PathPrefix` restituye el estado de §0.1, en el que cualquier *host* alcanza la API. Con el backoffice retirado eso es lo que había antes de este paso y es tolerable; **si el backoffice se va a volver a desplegar, se dejan puestas** |
| 4 | `down()` de las migraciones, **en orden inverso** | Las de tabla son `DROP` limpios: nada las referencia desde el producto. `DROP TABLE platform_sessions` **cierra todas las sesiones de plataforma vivas**, que es el efecto correcto de retirar la superficie |
| 5 | Restituir los privilegios de `module_subscriptions` | `GRANT UPDATE, INSERT ON module_subscriptions TO plataforma_app` |

**Dos cosas que hay que decir en voz alta sobre esta reversión:**

- **`DROP TABLE admin_action_logs` destruye la auditoría de plataforma.** Es lo único de este módulo cuya pérdida no es recuperable rehaciendo trabajo. Si hay actividad real, **se vuelca antes**, y el volcado se custodia con el mismo cuidado que la tabla.
- **Revertir el paso 5 reabre el agujero que `ADR-045 §4.4` cierra**: el centro vuelve a poder escribir `enabled` si algún día un controlador se lo permite. Es aceptable para un despliegue fallido y **nunca** como forma de «desactivar temporalmente la restricción» con el código nuevo en producción.

**Sobre la reversión de `1.6b` en concreto**, tres cosas y ninguna es inofensiva:

- **`DROP COLUMN` de las cuatro columnas de `tenants` pierde datos vivos**: el mensaje de suspensión que un operador redactó y, sobre todo, **la fecha de fin del período de gracia de los centros que estén de baja**. Ese plazo es un compromiso con un cliente y no se puede reconstruir de memoria — `tenant_lifecycle_events` lo conserva en la fila de la baja, así que **se recupera de ahí antes de revertir**, no después.
- **`ResolveTenant` vuelve a responder `404` donde ahora responde `503`**, y un centro suspendido vuelve a quedar accesible hasta 60 s tras el cambio de estado (issue #7 reabierto). Si se revierte el código pero se dejan las columnas, **no se opera ninguna suspensión hasta volver a desplegar**.
- **Los tenants que estén en `en_baja` o `eliminado` siguen estándolo.** Es dato de negocio correcto, no residuo: revertir el despliegue no reabre un centro cerrado, y reabrirlo es una transición, no una reversión.

**Sobre la reversión de `1.6e` en concreto**: `DROP TABLE feature_flags, feature_flag_rules` deja **todos los *flags* apagados**, porque sin catálogo ni reglas el evaluador devuelve falso (`RN-BO-35`). Es la dirección segura y no hay que hacer nada más: se pierden funcionalidades en despliegue parcial, no se enciende ninguna. Lo que sí se pierde y **no** se recupera rehaciendo trabajo es el registro de **a qué centros se expuso qué y cuándo** — las reglas son esa prueba (`datos.md §13`) —, así que si hay despliegues progresivos vivos se vuelcan antes, igual que `admin_action_logs`.

**Lo que la reversión no puede deshacer**: los módulos ya contratados desde el backoffice se quedan contratados. Es dato de negocio correcto, no residuo — descontratarlos es una operación normal, no una reversión.
