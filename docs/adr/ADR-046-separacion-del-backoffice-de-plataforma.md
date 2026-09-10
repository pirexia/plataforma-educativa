# ADR-046 · Separación del backoffice de plataforma: superficie, sesión y propósito declarado en `runAsPlatform()`

**Estado**: **ACEPTADA** (2026-09-08). Las tres decisiones estructurales que `spec-writer` dejó bloqueantes (`OPEN-BO-01`, `OPEN-BO-02`, `OPEN-BO-03`) quedan **decididas aquí**, ratificadas por el usuario sin objeción tras su presentación (ninguna de las tres deja una decisión de producto abierta que corresponda a un juicio de negocio, a diferencia de `ADR-045`). Lo único que este ADR **no** puede decidir es el nombre de *host* concreto del backoffice, que sigue bloqueado por `OPEN-08` (`§4.4`).

**Fecha**: 2026-09-08

**Resuelve**: `OPEN-BO-01`, `OPEN-BO-02` y `OPEN-BO-03` (`docs/modulos/REQ-BO/funcional.md §14`), los tres declarados bloqueantes del arranque de `1.6`. Cierra los **puntos 2 y 3** del issue [#6](https://github.com/pirexia/plataforma-educativa/issues/6) (el punto 1 lo cerró `1.5` con `RunAsPlatformArchitectureTest`)

**Concreta**: `REQ-BO` (sección 5.51), `RMT-009`, `ADR-002` (sin ampliarlo ni excepcionarlo), `ADR-033 §2` (resolución por *host*, cookie *host-only*), `ADR-033 §4` (`runAsPlatform()` como única puerta sancionada), `ADR-033 §5` (roles de base de datos), `ADR-033 §7` (tablas compartidas y de plataforma), `ADR-033 §10` (tests que convierten disciplina en *build* roto), `ADR-028 §1` (Traefik como único punto de entrada), `ADR-037 §6` (unidades Quadlet)

**Se apoya en**: `INV-001`, `INV-002`, `INV-003`, `INV-006`, `INV-007`, `INV-012`, `INV-015`, `CLAUDE.md §8` (cookie de sesión, cabeceras), `CLAUDE.md §11` (una decisión de ADR no se cambia sin ADR nuevo)

**Afecta a**: el paso **1.6** (`REQ-BO`), del que es entrada obligatoria de `spec-writer` junto con `ADR-045`. Toca infraestructura compartida (`App\Support\Tenancy`, `App\Support\Audit`), luego afecta a **todo el producto**, no solo a `REQ-BO`: la firma de `runAsPlatform()` cambia para los dos llamadores que existen hoy y para todos los futuros. Condiciona **`REQ-SUP-003`** (impersonación, fase 2) y **1.7/1.9** (interfaz del backoffice)

**No sustituye a ningún ADR anterior.** En particular **no toca `ADR-002`**: la decisión que sigue mantiene el monolito modular y despliegue único. Y **no revoca `ADR-036`** ni el diferimiento de `ADR-033 §4` sobre el rastro de `runAsPlatform()`: lo **cumple**, dándole por fin el mecanismo concreto que aquel ADR dejó como `TODO`.

---

## 1 · Contexto

`REQ-BO` (sección 5.51) exige literalmente: «Aplicación **separada del producto** que usan los centros, con su propio dominio, su propia autenticación y sus propios roles. Un usuario de un tenant nunca puede alcanzar este backoffice, ni siquiera con el rol máximo de su centro.»

Eso fija tres propiedades obligatorias —dominio propio, autenticación propia, roles propios— y **no fija** el diseño técnico que las produce. `spec-writer` evaluó las opciones en `funcional.md §3` y paró, correctamente, porque una de ellas exigía tocar `ADR-002`.

Las tres preguntas están encadenadas y por eso viven en un solo documento: **cómo se separa la superficie** (`§4`) decide **dónde puede vivir la sesión** (`§5`), y las dos juntas definen **qué significa "operación de plataforma"** para la primitiva que cruza la frontera de aislamiento (`§6`).

### 1.1 · Estado real verificado en el repositorio, no supuesto

Verificado el 2026-09-08 sobre `feature/REQ-BO-1.6-backoffice-superadmin` (`2993581`, dos *commits* sobre `develop` en `b95be70`). **Dos de las premisas de `funcional.md §14` son falsas**, y lo digo antes de decidir nada porque cambian la forma de una de las tres respuestas.

**Superficie y enrutado:**

- `ResolveTenant` **no es** *middleware* global del grupo `api`. `bootstrap/app.php` solo hace `prependToGroup('web', ResolveTenant::class)`; en `api` se aplica **explícitamente** como alias `resolve-tenant` sobre el grupo `Route::prefix('v1')` de `routes/api.php`. El propio fichero documenta por qué (`/api/health` tiene que responder sin tenant). `ADR-033 §2` dice «primero del grupo `api` y del grupo `web`»; el código diverge deliberadamente y lo explica.
- **Ya existen dos grupos de rutas fuera del tenant**: `/api/health` y `/api/_sso-simulator/*` (este último solo en `local`/`testing`). Declarar un tercer grupo con pila propia **no es una excepción nueva**: es el patrón vigente.
- `TenantHost::slugFrom()` devuelve `null` si el *host* no termina en `.{TENANCY_BASE_DOMAIN}`, si no hay dominio base configurado, o si el prefijo tiene más de un nivel. `ResolveTenant` con `null` → `abort(404)`.
- **Traefik enruta hoy solo por `PathPrefix`, sin `Host()`**: `plataforma-web` con `PathPrefix(/)` y prioridad 1, `plataforma-api` con `PathPrefix(/api)` y prioridad 10 (`infra/quadlet/web.container`, `api@.container`). **Consecuencia verificada y decisiva**: cualquier *host* que llegue al proxy alcanza el mismo contenedor de API, incluido `centroa.dominio/api/…`. Sin `Host()` en las reglas, un grupo de rutas de plataforma sería alcanzable desde el *host* de un centro.
- `SESSION_DOMAIN` no tiene valor por defecto en `config/session.php` (`env('SESSION_DOMAIN')` → `null`), es decir, cookie ***host-only***, como exige `ADR-033 §2`.
- El *guard* `web` es el único configurado (`config/auth.php`), con el proveedor `users` sobre `App\Models\User`.

**Sesión — la premisa falsa:**

- **`sessions` NO tiene columna `tenant_id`.** Se crea en `database/migrations/0001_01_01_000000_create_users_table.php` con `{id, user_id, ip_address, user_agent, payload, last_activity}` y **ninguna migración posterior la altera**. Está registrada en `config/tenancy.php` bajo `shared_tables.framework`, junto a `migrations`, `job_batches`, `cache`, `cache_locks` y `jobs`: **sin `tenant_id` y sin RLS**, por declaración explícita.
- Lo confirman tres sitios más del propio código: el *docblock* de `DatabaseSessionRevoker` («`sessions` … no tiene `tenant_id` (OPEN-AUTH-10, OPEN-AUTH-15 — endurecimiento futuro, issue [#81](https://github.com/pirexia/plataforma-educativa/issues/81))»), el de la migración de `mfa_challenges` («`sessions` es del framework, no lleva `tenant_id`») y el propio issue #81, cuyo título es *«Planificación: `tenant_id` + RLS en `sessions` del framework (OPEN-AUTH-10), paso propio de endurecimiento»* y sigue **abierto**.
- El vínculo sesión↔tenant lo producen hoy **tres cosas distintas y ninguna es una columna**: la cookie *host-only*, la clave `pge_tenant_id` guardada en el *payload* al autenticarse, y el *middleware* `VerifySessionTenant`, que la reverifica contra el tenant resuelto por *host* en cada petición y, si discrepa, invalida, audita y responde `401`. La tabla de tenant con RLS que sí es fuente de verdad de «qué sesiones hay vivas» es `user_sessions`, no `sessions`.

> **Por tanto, `OPEN-BO-02` está mal planteada.** Su opción (b) —«`sessions.tenant_id` deja de ser obligatoria»— describe una columna **que no existe**, y el argumento con el que `spec-writer` la descarta («debilita `INV-001` con una columna de aislamiento anulable») no aplica a nada real. La pregunta verdadera es otra y se responde en `§5`. La consecuencia práctica es que `docs/modulos/REQ-BO/{funcional,datos}.md` contienen esa premisa falsa y hay que corregirla; **no lo hago yo** (`§11`).

**`runAsPlatform()`:**

- Tres apariciones en `app/`, exactamente las de la lista blanca de `RunAsPlatformArchitectureTest`: la **definición** en `App\Support\Tenancy\TenantContext`, `RunsPerTenant::eachTenant()` y `PurgeExpiredIdempotencyKeys::handle()`. Es decir, **dos llamadores reales**, no tres.
- **Los dos corren sin tenant activo.** `eachTenant()` lo llama para *listar* los tenants activos antes de iterar con `runFor()`; `PurgeExpiredIdempotencyKeys` se despacha desde `routes/console.php` (`Schedule::job(new PurgeExpiredIdempotencyKeys)->daily()`), fuera de todo contexto de tenant. Ninguno tiene sujeto: uno es un *trait* de consola, el otro un *job* programado.
- La implementación **solo conmuta una bandera**. En particular **no limpia `tenantId`**: `platformMode = true` hace que `TenantScope::apply()` retorne sin filtrar y que `TenantModel::getConnectionName()` devuelva `pgsql_platform` (`BYPASSRLS`), pero el tenant anterior sigue ahí.
- Esa combinación —tenant activo **y** modo plataforma— es un peligro ya identificado en el código, no una hipótesis mía: `AuditRecorder::record()` **lanza excepción** en modo plataforma con un comentario que lo explica textualmente («un futuro uso que combine "tenant activo" + "modo plataforma" … escribiría con el `tenant_id` equivocado o violaría el `NOT NULL`»), y `BelongsToTenant` deja de rellenar `tenant_id` en `creating` cuando hay modo plataforma. Hoy no ocurre; `AuditObserverTest` lo documenta como caso que no se da.
- `App\Support\Audit\AuditActor::actingAs()` ya existe y su *docblock* reserva explícitamente un sitio para que `runAsPlatform()` declare el tipo de actor `'platform'`. Está previsto, no construido.
- **`admin_action_logs` no existe todavía**: ninguna migración la crea. `ADR-033 §7` la clasifica como tabla de plataforma y `ADR-036` fijó su creación en `1.6`.

### 1.2 · El hecho que ordena las tres decisiones

`ADR-045 §4.1` acaba de decidir que **el backoffice es el único escritor de `module_subscriptions.enabled`**, y `RN-BO-22` exige que la resolución de dependencias tenga **una sola implementación, en un servicio de dominio de `REQ-CORE`**, consumida por la contratación individual, la masiva y la vista previa.

Eso no es un detalle: es el criterio que decide `OPEN-BO-01` antes que el coste o que `ADR-002`. Un backoffice desplegado aparte **no puede** consumir un servicio de dominio de `REQ-CORE` sin duplicarlo o sin inventarse un contrato entre servicios. Ver `§4.2`.

---

## 2 · Qué NO decide este ADR

Para que nadie lo lea como una decisión que no está aquí:

1. **No decide el nombre de *host* del backoffice.** Bloqueado por `OPEN-08`, igual que todo lo demás de dominio. Se parametriza (`§4.4`).
2. **No decide los roles internos ni el catálogo de capacidades del backoffice.** Eso lo fija `REQ-BO-007` y lo detalla `docs/modulos/REQ-BO/permisos.md`; `funcional.md §4.2` ya argumentó, con `ADR-034 §2` detrás, por qué no son roles de `REQ-PERM`, y este ADR no lo reabre.
3. **No decide el esquema de `admin_action_logs`** ni si `affected_tenant_id` con política RLS encaja en `ADR-033 §7` (`OPEN-BO-10`): eso es materia de `db-reviewer` sobre `datos.md`. Este ADR solo fija **cuándo es obligatorio escribir en ella** (`§6.5`).
4. **No decide `OPEN-BO-04` a `OPEN-BO-10`.** Siguen abiertas.
5. **No adelanta `REQ-SUP-003`** (impersonación). Al contrario: `§6.4` deja escrito que la única combinación que la impersonación necesitaría queda **prohibida** por esta decisión y exigirá un ADR nuevo.
6. **No arregla el issue #81.** `sessions` sigue sin `tenant_id` y sin RLS. Lo que sí hace `§5` es **no heredar esa debilidad** en la tabla nueva.

---

## 3 · Opciones reales

Las que `funcional.md §3.2` dejó vivas, evaluadas contra los cuatro criterios de siempre: coste de implementación en solitario, mantenimiento a tres años, impacto en las invariantes, y reversibilidad.

**Opción C** (misma SPA con «modo backoffice») queda descartada **de antemano y no se reevalúa**: `spec-writer` la descartó por incompatibilidad directa con el requisito —el *bundle* del backoffice viajaría al navegador de cualquier usuario de cualquier centro— y esa lectura es correcta.

| Criterio | **A** · mismo monolito, segundo *guard*, segundo grupo de rutas, SPA propia | **B** · aplicación desplegable independiente (`apps/backoffice-api`) |
|----------|------------------------------------|------------------------------------|
| **Coste de implementación en solitario** | Bajo. El patrón de grupo de rutas fuera del tenant **ya existe y está en producción** (`/api/health`, `/api/_sso-simulator`). Un *guard* nuevo es configuración. La SPA aparte es un segundo proyecto Vite y un segundo `Containerfile`, que hace falta en las dos opciones | Alto. Segundo contenedor, segunda unidad Quadlet, segundo ciclo de *release*, segunda configuración, segundo juego de secretos, y **duplicación o extracción a paquete de `App\Support`** (`Tenancy`, `Audit`, `Api`, `Modules`) |
| **Mantenimiento a 3 años** | Un despliegue, una suite, un `composer.lock`. El riesgo es de **disciplina de enrutado**, que es exactamente lo que este proyecto sabe convertir en *build* roto (`ADR-033 §10`) | Dos artefactos que hay que mantener sincronizados en versión de PHP, dependencias y esquema. Una migración expand/contract pasa a coordinarse entre dos ciclos de *release* en vez de uno (`CLAUDE.md §9`) |
| **Impacto en las invariantes** | `INV-001`: el aislamiento sigue siendo del motor —`plataforma_platform` con `BYPASSRLS` frente a `plataforma_app` sin él, credenciales distintas (`ADR-033 §5`)—, no del enrutado. `INV-007`: `REQ-BO` es un módulo más y no importa código interno de otro | Igual en `INV-001`. **Peor en `INV-007` y en `RN-BO-22`**: la resolución de dependencias de módulos vive en un servicio de dominio de `REQ-CORE` (`ADR-045`, `RMOD-006`) y el backoffice separado o lo duplica —dos implementaciones de una regla facturable— o lo alcanza por HTTP, inventando un contrato entre servicios que `ADR-002` quiso evitar |
| **Reversibilidad** | **Alta**. Si mañana hace falta B, lo que se mueve es el despliegue: el módulo ya tiene su propio *guard*, su propio modelo, su propio grupo de rutas y su propia SPA. Es exactamente el «cada módulo debe poder extraerse a servicio independiente sin reescribir su dominio» de `RARQ-ARC-003` | **Baja**. Volver de B a A significa desmontar un despliegue y refundir dos bases de código. Y el paso a B **exige un ADR que amplíe o excepcione `ADR-002`**, decisión vigente que fija monolito modular hasta la fase 3 |

### Evaluación

**B compra una propiedad real**: el aislamiento entre las dos superficies deja de depender del enrutado y pasa a ser una propiedad del despliegue. Ese beneficio es genuino y hay que reconocerlo antes de descartarlo.

Pero lo compra **caro y antes de tiempo**, y sobre todo lo compra **a cambio de romper una regla de negocio que se acaba de decidir hoy**. `ADR-045` puso al backoffice como único escritor de la contratación de módulos y `RN-BO-22` exige una sola implementación de las dependencias en `REQ-CORE`. Bajo B esa frase es inejecutable: o se duplica la regla —y entonces «una sola implementación» es mentira y la factura de un centro depende de cuál de las dos corrió— o se llama por HTTP, y entonces hemos construido el primer microservicio del proyecto para servir a la pantalla de administración interna, con un equipo de una persona. `ADR-002` descartó microservicios con 4–6 personas; con una, el argumento es más fuerte, no más débil.

Y la propiedad que B compra **es exactamente la que A puede comprar más barata en el sitio correcto**: la primera barrera no tiene por qué ser el código de la aplicación. Puede ser Traefik —una regla `Host()` y una lista blanca de IP en el *ingress*— y luego, además, el código. Eso es defensa en profundidad con el coste de dos etiquetas de Quadlet, no de un segundo despliegue.

Queda un cabo que ninguna de las dos evaluaciones anteriores ató y que es el que de verdad decide la forma de A: **hoy Traefik no enruta por *host***. Sin corregirlo, la Opción A **no cumple el requisito**, porque `centroa.dominio/api/platform/…` alcanzaría el mismo contenedor y la única barrera restante sería la autenticación — es decir, exactamente «una condición en tiempo de ejecución» en vez de una propiedad, que es el motivo por el que se descartó la Opción C. Por eso las condiciones de `§4.3` y `§4.5` **no son recomendaciones: son parte de la decisión**, y sin ellas la decisión no se sostiene.

---

## 4 · Decisión (`OPEN-BO-01`) · Opción **A**, con cinco condiciones vinculantes

**Se adopta la Opción A**: mismo monolito y mismo despliegue de API, con *guard* propio, grupo de rutas propio y SPA propia para el backoffice. **`ADR-002` no se toca.**

La recomendación de `spec-writer` se ratifica en el fondo, y **se amplía en la forma**: sus dos condiciones son necesarias pero no suficientes (les falta el enrutado por *host*, que es el punto donde la opción se cae). Las cinco condiciones siguientes son parte de la decisión, no glosa.

### 4.1 · Superficie de aplicación

- Módulo `App\Modules\Backoffice` como un *bounded context* más, sujeto a `INV-007` como cualquier otro.
- *Guard* de sesión `platform` sobre el proveedor `platform_admins` y el modelo `PlatformAdmin` (**sin `tenant_id`**, `RN-BO-01`). El *guard* `web` no cambia.
- Grupo de rutas `/api/platform/v1`, hermano de `/api/v1` en `routes/api.php`, **con su propia pila de *middleware* declarada de forma explícita y completa**, igual que hoy hace el grupo del ACS de SAML. **Sin `resolve-tenant`, sin `verify-session-tenant`, sin `require-mfa-enrollment`** (los tres son del tenant; el backoffice tiene sus equivalentes propios, y su MFA es `RN-BO-05`, sin exenciones).
- SPA propia en `apps/backoffice`, proyecto Vite independiente con su propio `dist` y su propio contenedor de estáticos. **No comparte *bundle*** con `apps/web`: eso es lo que descartó la Opción C.

### 4.2 · Lo que sigue siendo compartido, y por qué es correcto

El backoffice **sí** consume `App\Support` (`Tenancy`, `Audit`, `Api`) y **sí** consume los servicios de dominio públicos de `REQ-CORE` —empezando por la resolución de dependencias de módulos de `RMOD-006`/`RN-BO-22`—. Eso no es acoplamiento indebido: es `INV-007` bien aplicado, comunicación por interfaces públicas entre dos *bounded contexts* del mismo monolito. Es también, literalmente, la razón por la que la Opción B pierde.

### 4.3 · El *host* de plataforma es una barrera, no una convención

**Condición vinculante y la más importante de las cinco.** Se aplica en tres capas, y la primera no es código:

1. **Traefik enruta por `Host()`.** Las reglas de `web.container` y `api@.container` pasan de `PathPrefix(...)` a `Host(...) && PathPrefix(...)`, y se añaden los dos *routers* del backoffice (SPA y API) bajo su propio `Host()`. Sin esto, la separación no existe a nivel de red. Es un cambio en `infra/quadlet`, dentro de `ADR-028 §1` (Traefik como único punto de entrada) y sin tocar `ADR-037`.
2. **Lista blanca de IP en el *ingress***: *middleware* `ipallowlist` de Traefik sobre los *routers* del backoffice. **Aviso de implementación que no puede perderse**: solo es real si Traefik ve la IP del cliente. Si algún día hay otro proxy delante, hace falta configurar `forwardedHeaders.trustedIPs` en el *entrypoint*; sin eso, la lista blanca compara contra la dirección del NAT y **permite a todo el mundo sin dar ningún síntoma**.
3. **La aplicación vuelve a comprobarlo**, porque la configuración del proxy no está cubierta por la suite de tests y la aplicación sí. Dos *middleware*, ambos primeros de la pila de plataforma:
   - `RequirePlatformHost`: si el *host* de la petición no es el de plataforma → **404**, antes de sesión y antes de credenciales. Mismo criterio que `ResolveTenant` con un *host* desconocido: no se revela que la superficie existe.
   - `EnforcePlatformIpAllowlist`: `RN-BO-06`/`RN-BO-07` (lista vacía = denegar a todos), auditado, antes de comprobar credenciales.

La lista blanca de la aplicación **no sustituye** a la de Traefik ni al revés. `RN-BO-06` y `RN-BO-07` se cumplen en las dos capas.

### 4.4 · El *host* de plataforma no puede ser un subdominio del dominio base de los tenants

Hallazgo propio de esta revisión, no señalado en `funcional.md`, y con consecuencia directa sobre la configuración:

`TenantHost::slugFrom()` devuelve la etiqueta más a la izquierda de **cualquier** *host* que termine en `.{TENANCY_BASE_DOMAIN}`. Si el backoffice se alojara en `admin.plataforma.example` con `TENANCY_BASE_DOMAIN=plataforma.example`, ese *host* resolvería el *slug* `admin`, y lo único que impediría que resolviera un tenant sería que **ningún centro se llame `admin`**. Eso convierte la separación en una colisión de nombres pendiente de ocurrir.

Decisión:

- El *host* del backoffice vive en su **propia variable de entorno** (`BACKOFFICE_HOST`, con su entrada en `config/`), nunca derivado de `TENANCY_BASE_DOMAIN`.
- **No debe ser un subdominio de `TENANCY_BASE_DOMAIN`.** Con `OPEN-08` sin resolver, ningún nombre concreto puede escribirse hoy; lo que sí queda decidido es la restricción que ese nombre deberá cumplir.
- **Defensa en profundidad obligatoria por si alguien la incumple igualmente**: el alta de tenant rechaza (`422`) un *slug* que coincida con la etiqueta del *host* de plataforma, y `RequirePlatformHost` compara contra `BACKOFFICE_HOST`, nunca contra «lo que no resuelve tenant». Con test.

### 4.5 · Test de arquitectura sobre el mapa de rutas, no sobre el texto

`spec-writer` pide «un test que falle si una ruta de plataforma aparece bajo el grupo de tenant o al revés». Se acepta y **se concreta más fuerte**: no por *grep*, sino recorriendo `Route::getRoutes()`, que es la verdad efectiva y no depende de cómo esté escrito el fichero. Cuatro aserciones, en el espíritu de `ADR-033 §10`:

1. Ninguna ruta bajo `/api/platform/*` lleva `resolve-tenant`, `verify-session-tenant` ni `require-mfa-enrollment`.
2. **Toda** ruta bajo `/api/platform/*` lleva la pila de plataforma **completa** y en orden: `RequirePlatformHost`, `EnforcePlatformIpAllowlist`, cookies, sesión de plataforma, CSRF, caducidad, idioma, MFA de plataforma, capacidad. Denegar por defecto se comprueba por presencia, no por ausencia (`INV-002`).
3. Ninguna ruta bajo `/api/v1/*` ni del grupo `web` usa el *guard* `platform`.
4. Ninguna ruta fuera de `/api/platform/*` apunta a un controlador de `App\Modules\Backoffice`.

### 4.6 · Consecuencia sobre la SPA de los centros

Añadir `Host()` a `plataforma-web` es obligatorio, no opcional: hoy su regla es `PathPrefix(/)` con prioridad 1 y, tal cual, **serviría la SPA de los centros bajo el *host* del backoffice**. Es un cambio en `infra/quadlet/web.container` que forma parte de esta decisión y que el paso 1.6 tiene que ejecutar, aunque `infra/` no sea suyo.

---

## 5 · Decisión (`OPEN-BO-02`) · Almacén de sesión propio, en tabla de plataforma

La pregunta, **reformulada** sobre el estado real (`§1.1`), es: *¿la sesión del `platform_admin` comparte la tabla `sessions` del framework con las sesiones de los centros, o tiene la suya?*

**Decisión: la suya.** Coincide en el resultado con la recomendación (a) de `spec-writer`, pero **por un motivo distinto y verificable**, porque el suyo —«no debilitemos una columna de aislamiento anulable»— se apoya en una columna inexistente.

### 5.1 · El motivo real

`sessions` es tabla compartida del framework: **sin `tenant_id`, sin RLS y legible por `plataforma_app`**, que es el rol con el que corre el *runtime* que sirve las peticiones de los centros. Y en el driver `database` de Laravel, `sessions.id` **es** el identificador de sesión.

Si las sesiones de plataforma vivieran ahí, cualquier camino que consiguiera leer esa tabla desde el *runtime* de tenant —una inyección SQL, una consulta descuidada, un *dump* de depuración— obtendría **identificadores de sesión vivos de administradores de plataforma**. Eso es una escalada de tenant a backoffice: exactamente lo que el requisito prohíbe («un usuario de un tenant nunca puede alcanzar este backoffice, ni siquiera con el rol máximo de su centro»), y por una vía que no pasa por la autenticación.

Que hoy `plataforma_app` pueda leer las sesiones de todos los tenants es una debilidad **preexistente y aceptada**, con issue propio ([#81](https://github.com/pirexia/plataforma-educativa/issues/81)) y paso de endurecimiento pendiente. Este ADR **no la arregla** —está fuera de su alcance— pero **prohíbe heredarla**: la superficie más sensible del producto no nace dentro de un problema conocido y sin cerrar.

### 5.2 · Lo que se construye

- Tabla **`platform_sessions`**, tabla de **plataforma** en el sentido de `ADR-033 §7`: sin `tenant_id`, sin RLS, y con **`REVOKE ALL … FROM plataforma_app`** en su migración —precedente literal: `harden_failed_jobs_grants` y `harden_audit_logs_platform_grants`—. Que el *runtime* de un centro no pueda leer una sesión de plataforma deja de ser disciplina y pasa a ser un `GRANT`, que es la forma que este proyecto le da a `INV-001`.
- Debe quedar registrada en `config/tenancy.php` bajo `shared_tables.platform`, o el test de esquema #8 de `ADR-033 §10` falla — y **debe fallar** si alguien la crea sin declararla.
- **Cookie con nombre propio** y distinta de la del tenant, ***host-only*** igual que aquella (`SESSION_DOMAIN` sigue sin valor). Con `§4.3` y `§4.4`, `RMT-009` se cumple por construcción y por partida doble: dominios distintos, cookies distintas, tablas distintas.
- **Vida de sesión propia y configurable**, más corta que la del tenant (`RN-BO-09`), en su propia variable de entorno.
- La selección del almacén es **por grupo de rutas**, mediante un *middleware* que fija la configuración de sesión de plataforma **antes** de `start-session`. No es un truco nuevo: `TenantContext::applyCachePrefix()` ya hace exactamente esta forma de cosa con `cache.prefix` y `Cache::forgetDriver()`, y está probada desde `0.7`.
- El equivalente de `user_sessions` para el backoffice —la fuente de verdad de «qué sesiones de plataforma hay vivas», necesaria para revocar— es tabla de plataforma también, y su diseño es materia de `datos.md`, no de este ADR.

### 5.3 · Lo que explícitamente **no** cambia

`sessions` se queda **exactamente como está**: sin `tenant_id`, sin RLS, en `shared_tables.framework`. Este ADR **no adelanta el issue #81** ni lo bloquea. `VerifySessionTenant`, `DatabaseSessionRevoker` y `user_sessions` no se tocan.

---

## 6 · Decisión (`OPEN-BO-03`) · `runAsPlatform()` recibe un propósito declarado

**Se acepta cambiar la firma**, y se acepta el diagnóstico de `funcional.md §6.2`, que además **queda confirmado por el código**: los dos llamadores reales (`RunsPerTenant::eachTenant()` y `PurgeExpiredIdempotencyKeys::handle()`) son mantenimiento sin sujeto y corren sin tenant activo, luego las dos lecturas literales del issue #6 —auditar cada llamada, comprobar un permiso genérico dentro de la primitiva— son inaplicables a ellos. Lo que se audita es **la operación**, no la primitiva.

La propuesta de `spec-writer` se ratifica y se concreta hasta el punto en que `spec-writer` e `implementer` no tengan que decidir nada. **Tres cambios respecto a su enunciado**, cada uno con su motivo: el propósito de backoffice se parte en **lectura y escritura** (`§6.2`), la primitiva **exige ausencia de tenant activo** (`§6.4`), y la obligación de auditar deja de ser una promesa y pasa a ser una **comprobación al cierre del bloque** (`§6.5`).

### 6.1 · Firma nueva, exacta

```php
namespace App\Support\Tenancy;

enum PlatformAccessPurpose: string
{
    case Mantenimiento       = 'mantenimiento';
    case BackofficeLectura   = 'backoffice_lectura';
    case BackofficeEscritura = 'backoffice_escritura';
}
```

```php
// App\Support\Tenancy\TenantContext
public function runAsPlatform(PlatformAccessPurpose $purpose, Closure $callback): mixed;

// Nuevo, para que AuditRecorder y los tests puedan ramificar sin adivinar:
public function platformPurpose(): ?PlatformAccessPurpose;   // null fuera de modo plataforma
public function isPlatformMode(): bool;                      // sin cambios
```

El propósito es **el primer parámetro y no tiene valor por defecto**, deliberadamente: así ninguna llamada existente sigue compilando sin tocarla, y ningún llamador futuro hereda un propósito por omisión. Los dos llamadores actuales pasan `PlatformAccessPurpose::Mantenimiento`.

El enum vive en `App\Support\Tenancy`, junto a `TenantContext`, `TenantScope` y `TenantContextMissing` — es infraestructura de aislamiento, no de `REQ-BO`. Nombres de caso en español, como `TenantStatus::Activo` y `SessionEndReason::RevocadaUsuario`.

### 6.2 · Por qué tres casos y no dos

`spec-writer` propone dos (`Mantenimiento` / `Backoffice`) y hace que `Backoffice` obligue siempre a escribir en `admin_action_logs`. Eso obligaría a que una operación de solo lectura —el rol `soporte`, que según `REQ-BO-007` es «solo lectura y diagnóstico», listando el inventario de tenants— tuviera que inventarse una acción que registrar. Una obligación que hay que falsear se acaba desactivando.

Con tres casos, cada uno tiene una regla precisa y ninguna es cosmética:

| Propósito | Quién puede | Obligación de auditoría |
|-----------|-------------|-------------------------|
| `Mantenimiento` | Solo con `app()->runningInConsole()` verdadero — comandos, tareas programadas y *workers* de cola, que corren bajo `artisan`. **Desde una petición HTTP lanza excepción**, sin excepciones ni exenciones: `INV-012` ya obliga a que lo pesado vaya en colas, así que no existe un caso legítimo | Ninguna. Sin sujeto no hay nada que registrar (es el argumento del issue #6 invertido, y es correcto) |
| `BackofficeLectura` | Administrador de plataforma autenticado en el *guard* `platform`, con la capacidad que el llamador ya comprobó | Ninguna en `admin_action_logs`. La auditoría de **lectura** de datos de categoría especial (`CLAUDE.md §8`) es otra cosa y sigue su propia regla |
| `BackofficeEscritura` | Igual que la anterior | **Obligatoria**, comprobada al cierre (`§6.5`) |

### 6.3 · Dónde vive la comprobación, sin romper `INV-007`

`TenantContext` está en `App\Support` y **no puede importar `App\Modules\Backoffice`**. Por tanto la primitiva no sabe qué es un administrador de plataforma: lo pregunta.

```php
namespace App\Support\Tenancy;

interface PlatformAccessCheck
{
    /** Antes de abrir el bloque. Lanza si el propósito no es alcanzable desde aquí. */
    public function before(PlatformAccessPurpose $purpose): void;

    /** Al cerrar el bloque, también si el callback lanzó. Lanza si quedó una obligación sin cumplir. */
    public function after(PlatformAccessPurpose $purpose): void;
}
```

- Enlace **por defecto** en `App\Support\Tenancy` (`DefaultPlatformAccessCheck`): permite `Mantenimiento` bajo consola, **deniega los dos propósitos de backoffice**. Consecuencia buscada: mientras `REQ-BO` no exista, los propósitos de backoffice son **inutilizables**, no «permitidos porque todavía no hay nadie que compruebe» (`INV-002`, denegar por defecto).
- El `ServiceProvider` de `App\Modules\Backoffice` **sustituye** el enlace por su implementación, que sí sabe consultar el *guard* `platform` y `admin_action_logs`.
- `after()` se llama en el `finally`, siempre, también si el *callback* lanzó.

### 6.4 · `runAsPlatform()` exige ausencia de tenant activo

**Hardening que no estaba propuesto y que el código pedía a gritos.** La implementación actual no limpia `tenantId`, y el comentario de `AuditRecorder` describe con precisión el fallo que eso permitiría: modo plataforma con tenant activo escribe con el `tenant_id` equivocado o revienta un `NOT NULL`, sin `TenantScope` que filtre y sobre una conexión `BYPASSRLS`.

Decisión: **`runAsPlatform()` lanza excepción si `hasTenant()` es verdadero, con cualquier propósito.** Verificado que **no rompe nada**: los dos llamadores actuales corren sin tenant.

- El acceso a datos de un tenant concreto desde el backoffice se hace fijando `tenant_id` **a mano** dentro del bloque, que es lo que el *docblock* de `BelongsToTenant` ya prescribe para modo plataforma.
- La invalidación de caché de `RN-BO-25`/`ADR-045 §8.3`, que necesita el prefijo `t{id}:`, se hace **fuera** del bloque de plataforma, con `runFor($tenantId, …)`. Este ADR fija que ese es el camino: nunca componer el prefijo a mano desde dentro de modo plataforma.
- **`REQ-SUP-003` (impersonación) es la única funcionalidad prevista que podría querer la combinación prohibida.** No la necesita —una impersonación entra en un tenant *como usuario de ese tenant*, por `plataforma_app`, no en modo plataforma— pero si su diseño de fase 2 concluyera lo contrario, **exigirá un ADR nuevo que sustituya este párrafo**. Queda dicho ahora, no descubierto entonces.
- `AuditRecorder` **conserva** su excepción en modo plataforma bajo `Mantenimiento`: defensa en profundidad, no redundancia a retirar.

### 6.5 · La obligación de auditar se comprueba, no se promete

Un bloque `BackofficeEscritura` que termina sin ninguna entrada en `admin_action_logs` **lanza excepción al cerrarse**. Lo comprueba `after()`, contando las entradas registradas por el grabador de acciones de plataforma dentro del bloque.

Esto no es celo: es **sustituir una barrera que este mismo ADR retira**. Hoy `AuditRecorder` lanza en modo plataforma, y por eso ninguna escritura de plataforma puede pasar desapercibida. Pero `ADR-045` obliga al backoffice a escribir `module_subscriptions`, que es `Auditable` con política `Full` (`ADR-035 §8`) y **tabla de tenant**: su rastro no puede ir a `audit_logs` (no hay tenant en contexto, `§6.4`), tiene que ir a `admin_action_logs`. Si nos limitáramos a silenciar la excepción, cambiaríamos un fallo ruidoso por un silencio. La regla de cierre mantiene el fallo ruidoso y lo pone donde corresponde.

Regla de `AuditRecorder`, precisa:

| Modo | Comportamiento |
|------|----------------|
| Sin modo plataforma | Sin cambios |
| Modo plataforma, propósito `Mantenimiento` | **Lanza**, con el mensaje actual. Sin cambios |
| Modo plataforma, propósito de backoffice | **Retorna en silencio**. El rastro es `admin_action_logs` y su obligación la garantiza `§6.5`, no este método |

### 6.6 · El actor de plataforma

`AuditActor::actingAs('platform', …)` ya está previsto en su propio *docblock* y aquí se ejerce: las entradas de `admin_action_logs` llevan el administrador de plataforma como sujeto, o `actor_type = 'console'` / `'system'` para los actores no humanos de `funcional.md §4.4`. **No se toca el vocabulario de `audit_logs`** de `ADR-039`: `admin_action_logs` es otra tabla, con su propio vocabulario, que fija `datos.md`.

### 6.7 · El test de arquitectura crece, pero no se afloja

`RunAsPlatformArchitectureTest` (`CA-PERM-092`) **mantiene su lista blanca fichero a fichero**. Queda prohibido convertirla en un comodín de directorio del tipo `app/Modules/Backoffice/**`: eso vaciaría el test precisamente en el módulo que más lo necesita.

Consecuencia práctica para `1.6`: el backoffice canaliza **todo** su acceso de plataforma por un conjunto **acotado y nombrado** de clases, que se añaden a la lista blanca una a una y con su justificación en el propio test, como están hoy las tres. Si esa lista crece sin control, es señal de un diseño mal repartido, y el test es el que lo hace visible.

Se añade además una aserción nueva: **ninguna llamada a `runAsPlatform()` en `app/` pasa un propósito calculado en tiempo de ejecución**; el argumento es siempre un caso literal del enum. Un propósito que dependa de una variable es un propósito que un día valdrá lo que convenga.

---

## 7 · Motivo

1. **La Opción B rompía hoy una regla decidida hoy.** `ADR-045` y `RN-BO-22` exigen una sola implementación de las dependencias de módulos en `REQ-CORE`; un backoffice desplegado aparte la duplica o inventa un contrato entre servicios. Ese es el argumento que decide, por encima del coste y por encima de `ADR-002`.
2. **A es la opción reversible.** Deja el módulo listo para extraerse (`RARQ-ARC-003`) sin pagar hoy por el despliegue separado. B no se deshace.
3. **La propiedad que B compraba se compra más barata en el sitio correcto.** `Host()` y `ipallowlist` en Traefik dan la primera barrera fuera del código por el precio de unas etiquetas de Quadlet.
4. **Un `GRANT` es mejor que una intención.** La sesión de plataforma en tabla propia con `REVOKE ALL FROM plataforma_app` convierte «el *runtime* de un centro no lee sesiones de plataforma» en algo que garantiza PostgreSQL, que es la forma que este proyecto le da a `INV-001` desde `ADR-033 §5`.
5. **El propósito declarado convierte un `TODO` de *docblock* en un contrato tipado**, y hace que la primitiva falle en cerrado igual que `tenantId()` — que es la filosofía de `ADR-033 §3` aplicada a la puerta que el propio ADR dejó abierta.
6. **Se corrigen dos premisas falsas antes de construir sobre ellas**, no después. Una especificación aprobada sobre una columna inexistente habría producido una migración inútil y una discusión de invariantes sobre nada.

---

## 8 · Consecuencias

**Buscadas:**

- `REQ-BO` arranca sin tocar `ADR-002`, con un solo despliegue de API y una sola suite.
- «Un usuario de un tenant nunca puede alcanzar este backoffice» pasa a apoyarse en cuatro barreras independientes —`Host()` en Traefik, lista blanca de IP en el *ingress*, `RequirePlatformHost` en la aplicación, y *guard*, cookie y tabla de sesión distintos— en vez de en la autenticación sola.
- El aislamiento entre superficies queda cubierto por tests que fallan el *build* (`§4.5`, `§6.7`), no por revisión humana.
- La combinación «tenant activo + modo plataforma», hasta hoy latente y documentada como peligro en dos ficheros del código, se vuelve **imposible**.

**Costes aceptados:**

- **`infra/quadlet` cambia en `1.6`**: reglas `Host()` en `web.container` y `api@.container`, dos unidades nuevas y el *middleware* `ipallowlist`. Fuera del ámbito habitual de un paso de módulo, pero inseparable de esta decisión.
- **Cambio incompatible de firma en infraestructura compartida.** Dos llamadores en `app/` y cuatro ficheros de test (`TenantModelTest`, `AuditObserverTest`, `CorePurgeJobsTest`, `RunAsPlatformArchitectureTest`) hay que actualizarlos. `AuditObserverTest` cambia de significado: el caso que documentaba —modo plataforma con tenant activo— deja de ser «no ocurre hoy» y pasa a ser «lanza siempre».
- Un segundo proyecto Vite, un segundo contenedor de estáticos y un segundo `Containerfile`.
- La suite crece con los tests de `§4.5`, `§4.4` y `§6.7`.
- `BACKOFFICE_HOST` y la vida de sesión de plataforma son dos variables de entorno más en `plataforma.env.example` y en `SYSADMIN.md`.

**Riesgo residual, dicho en voz alta:** en la Opción A, un error de configuración de rutas expone el backoffice donde no debe, cosa que en B no pasaría. Se mitiga con `§4.5` —que lo convierte en *build* roto— y con `§4.3`, que pone la primera barrera fuera del código. **No se elimina.** Si algún día el proyecto crece hasta tener equipo de operación y varios centros de datos, revisar B es legítimo: exigirá un ADR nuevo que amplíe `ADR-002`, y este documento habrá dejado el terreno preparado.

---

## 9 · Alternativas descartadas y por qué

| Alternativa | Por qué se descarta |
|-------------|---------------------|
| **Opción B**, aplicación desplegable independiente | Duplica o fragmenta la resolución de dependencias de módulos que `ADR-045`/`RN-BO-22` exigen única en `REQ-CORE`; exige ampliar `ADR-002`; coste alto y baja reversibilidad; y la propiedad que compra se obtiene más barata con `§4.3` |
| **Opción C**, misma SPA con «modo backoffice» | Ya descartada por `spec-writer` y ratificada aquí: el *bundle* viajaría al navegador de cualquier usuario de cualquier centro |
| **Opción A sin `Host()` en Traefik** | No cumple el requisito: `centroa.dominio/api/platform/…` alcanzaría la misma API y la única barrera sería la autenticación. Es el mismo defecto por el que cae la Opción C |
| **Añadir `tenant_id` nullable a `sessions`** | La columna no existe (`§1.1`), así que la alternativa es imaginaria. Y crearla nullable para esto sería resolver el issue #81 al revés |
| **Compartir `sessions` cambiando solo el nombre de la cookie** | Barato, pero deja los identificadores de sesión de plataforma en una tabla que `plataforma_app` puede leer (`§5.1`) |
| **Auditar cada llamada a `runAsPlatform()`** (lectura literal del issue #6, punto 3) | Sus dos llamadores reales son mantenimiento sin sujeto; llenaría `admin_action_logs` de ruido y enterraría lo que importa |
| **Comprobar un permiso genérico dentro de la primitiva** (issue #6, punto 2) | Un comando de consola no tiene a quién comprobárselo. Se sustituye por el propósito declarado, que sí distingue los casos |
| **Un solo propósito `Backoffice`** (propuesta de `spec-writer`) | Obligaría a una operación de solo lectura del rol `soporte` a inventarse una acción que registrar; una obligación que hay que falsear acaba desactivándose |
| **Bandera booleana `$audited = true` en vez de enum** | Un booleano no distingue «no aplica» de «todavía no»; y no permite la regla de `§6.4` ni el test de `§6.7` |
| **Mantener `runAsPlatform(Closure $callback)` con el propósito opcional** | Un valor por defecto es el que hereda todo llamador futuro que no piense. Sin defecto, cada nuevo uso obliga a una decisión consciente y a pasar por la lista blanca del test |

---

## 10 · Lo que `spec-writer` debe cambiar en `docs/modulos/REQ-BO/`

Este ADR **no edita** la especificación. Lo que sigue es la lista de lo que hay que rehacer sobre él:

1. **`funcional.md §3` y `§14`/`OPEN-BO-01`**: cerrar con la Opción A y las cinco condiciones de `§4`. Añadir `RN-BO-` nuevas para `RequirePlatformHost` y para la restricción de `§4.4`.
2. **`funcional.md §14`/`OPEN-BO-02` y `datos.md`**: **corregir la premisa falsa** —`sessions` no tiene `tenant_id`— y sustituirla por `platform_sessions` según `§5.2`, con su `REVOKE` y su entrada en `shared_tables.platform`.
3. **`funcional.md §6.2` y `§14`/`OPEN-BO-03`**: firma de `§6.1`, tres propósitos, ausencia de tenant activo, regla de cierre y regla nueva de `AuditRecorder` (`§6.5`).
4. **`api.md`**: pila completa y ordenada del grupo `/api/platform/v1` (`§4.1`, `§4.5` aserción 2).
5. **`operacion.md`**: `BACKOFFICE_HOST`, vida de sesión de plataforma, reglas `Host()` y `ipallowlist` de Traefik, y **el aviso de `forwardedHeaders.trustedIPs` de `§4.3`**, que es donde una lista blanca se vuelve decorativa sin dar síntoma.
6. **Criterios de aceptación**: los cuatro de `§4.5`, el de `§4.4` (*slug* de tenant que colisiona con el *host* de plataforma → `422`), los de `§6` (propósito inválido, tenant activo, bloque de escritura sin entrada en `admin_action_logs`, propósito no literal) y el de `§5.2` (`plataforma_app` no puede leer `platform_sessions`).

---

## 11 · Hallazgos fuera del alcance de este ADR, reportados y no corregidos

Conforme al issue [#150](https://github.com/pirexia/plataforma-educativa/issues/150): se señalan, no se arreglan.

1. **`ADR-033 §2` está desactualizado respecto al código.** Dice que `ResolveTenant` es «primero del grupo `api` y del grupo `web`»; en `api` no es global, se aplica sobre `Route::prefix('v1')`. La divergencia es deliberada y está bien documentada en el código, pero el ADR no la recoge. **Severidad baja** (documentación contra código, `CLAUDE.md §6.6`). Este ADR **no** enmienda `ADR-033`; lo correcto es una nota en el índice o un ADR de enmienda si se considera que cambia el sentido.
2. **`docs/modulos/REQ-BO/funcional.md §14` y `datos.md` afirman que `sessions` tiene `tenant_id`.** Es falso (`§1.1`). Lo corrige `spec-writer` (`§10.2`), no yo.
3. **`funcional.md §6.2` habla de «sus tres llamadores»**; son dos llamadores más la definición del método. Sin consecuencia sobre la decisión, pero la especificación debería decirlo bien.
4. **`plataforma_app` puede leer todas las filas de `sessions`, de todos los tenants.** Preexistente, aceptado, con issue propio ([#81](https://github.com/pirexia/plataforma-educativa/issues/81)) y paso de endurecimiento pendiente. Este ADR no lo toca; solo impide heredarlo (`§5.1`).
5. **`plataforma-web` sirve la SPA de los centros en cualquier *host*** (`PathPrefix(/)`, prioridad 1). Hoy es inofensivo porque solo hay un *host*; deja de serlo en cuanto exista el segundo. Se corrige como parte de `§4.3`, dentro de `1.6`.
