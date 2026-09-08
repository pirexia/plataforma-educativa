# REQ-BO · Backoffice de Super Administrador · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-BO` |
| Prioridad | MUST |
| Fase | 1 · Bloque A · **paso 1.6**, dividido en **cinco sub-pasos** por decisión del usuario del 2026-09-08 (§12) |
| Depende de | `REQ-CORE` (1.1), `REQ-AUTH` (1.2/1.3), `REQ-PERM` (1.5), `ADR-033`, `ADR-034`, `ADR-035`, `ADR-036`, `ADR-038`, `ADR-044`, **`ADR-045`** |
| Estado | **PROPUESTO** — revisado el 2026-09-08 con cuatro decisiones del usuario aplicadas (§15). Sigue pendiente de aprobación y de **tres decisiones bloqueantes** que no me corresponden (§14) |
| Módulo (código) | `bo` · `apps/api/app/Modules/Backoffice` · frontend **sin ubicación decidida** (`OPEN-BO-01`) |

> Fuente de verdad: sección 5.51 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` (`REQ-BO-001` a `REQ-BO-007`), más `RMOD-002`/`RMOD-006`, `RMT-007`, `REQ-CORE-001` y la sección 11.1. Desde el 2026-09-08, y sólo para `REQ-BO-005` puntos 1-2, también **`REQ-OPS-002`** (sección 5.49) y **`RARQ-DEP-010`** (sección 8).
> Entrada obligatoria: **`ADR-045`**, cuyo `§10` ya se ha ejecutado sobre el documento de requisitos (versión 3.2.0) y sobre `docs/modulos/REQ-CORE/funcional.md §2`.
> Este documento **no reabre** `ADR-033`, `ADR-034`, `ADR-036`, `ADR-044` ni `ADR-045`.

---

## 0. Lo primero, porque condiciona todo lo demás

Tres afirmaciones que hay que leer antes que nada:

1. **`REQ-BO` es un módulo de fase 1 cuyos siete sub-requisitos citan cuatro módulos de fase 2.** `REQ-BO-003` cuelga entero de `REQ-SAAS` (fase 2); la impersonación de `REQ-BO-004` es `REQ-SUP-003` (fase 2); los *feature flags*, las ventanas de mantenimiento y la exportación de `REQ-BO-005`/`REQ-BO-001` son `REQ-OPS-002`/`REQ-OPS-001`/`REQ-OPS-004` (fase 2, `SHOULD`); y las métricas de ingresos y *churn* de `REQ-BO-006` son `REQ-SAAS-004`. El propio encabezado de §5.51 lo dice: `REQ-BO` «consolida capacidades que hasta ahora estaban repartidas entre `REQ-CORE-001`, `RMOD-002`, `REQ-SAAS`, `REQ-SUP` y `REQ-OPS`». **Consolidar la pantalla no adelanta el motor.** §2 lo desglosa requisito a requisito.

   **De esos cuatro, el usuario ha decidido el 2026-09-08 adelantar exactamente uno**: el motor de *feature flags* de `REQ-OPS-002` (`REQ-BO-005`, puntos 1-2), por el motivo que esta misma especificación daba al señalar su exclusión como «menos firme que las anteriores» — es el único cuya construcción **no depende de ningún módulo que falte**. Entra en `§2.1`, se especifica en `§5.11` y ocupa un sub-paso propio, `1.6e` (`§12.2`). Todo lo demás sigue fuera, y `§2.2` mantiene el motivo de cada exclusión.

2. **Nada de la identidad de plataforma existe.** Verificado sobre el código (§1): no hay `platform_admins`, no hay `admin_action_logs`, hay **un solo *guard*** (`web`), la tabla de sesiones es de tenant, y todo el MFA de 1.3/1.3b está atado a `TenantContext::tenantId()`. El backoffice no es «una pantalla más»: es un segundo sujeto de autenticación, con su propia autorización, su propia auditoría y su propio segundo factor.

3. **La separación de la aplicación —«dominio propio, autenticación propia»— es una decisión estructural que no me corresponde.** §3 evalúa las opciones con sus costes y las consecuencias que el código ya impone, y la deja como `OPEN-BO-01`. **No la doy por decidida en ningún punto de esta especificación**, y por eso `datos.md`, `api.md` y `operacion.md` están escritos para ser válidos bajo cualquiera de las dos opciones vivas.

---

## 1. Estado real del código, verificado y no supuesto

Verificado el 2026-09-08 sobre `feature/REQ-BO-1.6-backoffice-superadmin` (un *commit* de `ADR-045` sobre `develop` en `b95be70`).

### 1.1 Lo que existe y sirve

| Pieza | Dónde | Qué aporta a 1.6 |
|-------|-------|------------------|
| `Tenant` | `app/Support/Tenancy/Tenant.php` | `$fillable = [slug, name, status]`, `public_id` ULID, `SoftDeletes`, conexión `pgsql_platform` |
| `TenantStatus` | `app/Support/Tenancy/TenantStatus.php` | **Los cinco estados de `REQ-BO-001` ya existen**: `EnAlta`, `Activo`, `Suspendido`, `EnBaja`, `Eliminado`, con `CHECK` en la migración de `tenants` |
| `TenantContext` | `app/Support/Tenancy/TenantContext.php` | `enter`/`leave`/`runFor`/`tenantId`/`hasTenant`/`runAsPlatform`/`isPlatformMode`. Prefijo de caché `t{tenant_id}:` |
| Tres conexiones | `config/database.php` | `pgsql` (`plataforma_app`), `pgsql_owner` (`plataforma_owner`, migraciones), `pgsql_platform` (`plataforma_platform`, `BYPASSRLS`) |
| `ModuleSubscription` | `app/Models/ModuleSubscription.php` | `TenantModel`, `Auditable` con política `Full`, `$fillable` incluye `enabled`, `enabled_at`, `disabled_at`, `reason`, `settings` |
| `SyncModuleRegistry` | `app/Support/Modules/SyncModuleRegistry.php` | Corre por `pgsql_owner`, **ya valida `applicable_scopes` y aborta** si hay un valor fuera del vocabulario, y nunca borra (marca `retired_at`). **Es el precedente exacto** de la validación de `depends_on` que pide `ADR-045 §4.5` |
| Endurecimiento de privilegios | `2026_08_17_180000_harden_failed_jobs_grants.php`, `2026_08_18_100900_harden_audit_logs_platform_grants.php` | El patrón `REVOKE`/`GRANT` que `ADR-045 §4.4` manda replicar |
| `audit_logs` | `2026_08_18_100700_create_audit_logs_table.php` | Forma y vocabulario que `admin_action_logs` debe imitar sin mezclarse con ella (`REQ-BO-007`) |
| MFA (1.3/1.3b) | `app/Modules/Auth/…`, seis tablas `mfa_*`/`user_mfa_*` | El **mecanismo** (TOTP, códigos de respaldo, envolturas de `ADR-041`) es reutilizable; el **almacenamiento** no (§1.2) |

### 1.2 Lo que **no** existe, y es lo que hace grande a este paso

| Ausencia | Verificación | Consecuencia |
|----------|--------------|--------------|
| `platform_admins` | No aparece en ninguna migración ni modelo; sólo como texto en un comentario de `TenantContext` | Hay que crearla, con su ciclo de vida completo |
| `admin_action_logs` | Ídem. `ADR-033 §7` la reservó en el registro de tablas compartidas y `ADR-036` la fechó en 1.6 | Sin ella no hay auditoría de plataforma, y `Tenant` sigue sin auditarse (issue [#27](https://github.com/pirexia/plataforma-educativa/issues/27)) |
| Un segundo *guard* | `config/auth.php` define **un solo** *guard*, `web`, sobre el *provider* `users` (modelo `App\Models\User`) | El backoffice necesita *guard*, *provider* y modelo propios |
| Sesión sin tenant | `config/session.php` usa el *driver* `database`; `ADR-034 §8` dejó escrito que 1.2 añade `tenant_id` a `sessions` | **Un `platform_admin` no tiene tenant.** O el backoffice tiene almacén de sesión propio, o `sessions.tenant_id` deja de ser obligatoria — y lo segundo debilita una invariante sobre una tabla viva. Ver `OPEN-BO-02` |
| MFA fuera de tenant | Las seis tablas `mfa_*` llevan `tenant_id`; `EloquentMfaPolicy` llama a `TenantContext::tenantId()` y lanza `TenantContextMissing` sin contexto | El MFA obligatorio de `REQ-BO-007` **no se resuelve reutilizando las tablas de 1.3** |
| Impersonación | Búsqueda de `impersonat*` en `apps/api` y `apps/web`: **cero resultados** | `REQ-BO-004` último punto está por construir entero |
| *Feature flags*, mantenimiento, *early adopters* | **Verificado el 2026-09-08 y sin una sola coincidencia**: ni `feature_flag`, ni `flag`, ni `toggle`, ni `rollout`, ni `canary`, ni `early_adopter` en `apps/api` ni en `apps/web`; ninguna dependencia de `laravel/pennant`, Unleash o Flagsmith en `composer.json` ni en `package.json`; ningún fichero en `config/`; ninguna tabla con `flag` en el nombre | `REQ-BO-005` está por construir entero. Sus **puntos 1-2 entran en alcance** por decisión del usuario del 2026-09-08 (§2.1, §5.11); los puntos 3-4 siguen fuera (§2.2) |
| `plans` | Reservada en `ADR-033 §7`, nunca creada | `REQ-BO-003` no tiene dónde apoyarse |
| Invalidación de la caché de módulos | `Cache::remember("modules:{$code}:enabled", 300, …)` en `EloquentModuleAvailability`, **sin ningún `Cache::forget` correspondiente** | `ADR-045 §8.3`: requisito de obligado cumplimiento de este paso |
| `depends_on` / `essential` | `moduleDescriptor()` devuelve `{code, name_key, phase}` y nada más; `ALWAYS_ENABLED = ['core','auth']` está escrita a mano en `EloquentModuleAvailability` | `ADR-045 §4.5`/`§4.9`: también de obligado cumplimiento |
| `ModuleContracted` / `ModuleDecontracted` | No existen | `ADR-045 §4.8` |

### 1.3 Datos que `REQ-BO-001` pide filtrar y que **no existen todavía**

`REQ-BO-001` pide un listado «con búsqueda y filtros por estado, plan, número de alumnos, CCAA, régimen y módulos activos». De los seis:

| Filtro | Estado real |
|--------|-------------|
| **Estado** | ✅ `tenants.status` |
| **Módulos contratados** | ✅ `module_subscriptions` |
| **CCAA** | ⚠️ Vive en la configuración del centro (`REQ-CORE-001`), no en `tenants`. Se lee, no se duplica (`datos.md §7`) |
| **Plan** | ❌ No existe `plans` ni `plan_id`. Es `REQ-SAAS-001`, fase 2 |
| **Número de alumnos** | ❌ No existe `students`. Es `REQ-ALUM`, paso **1.15** |
| **Régimen** | ❌ Es atributo **de la etapa**, no del tenant (`ADR-020`), y las etapas son `REQ-ACAD`, paso **1.11** |

**No se inventa ninguno de los tres que faltan**, y no se añade una columna «por si acaso» (`ADR-034 OPEN-13`). El listado de 1.6 filtra por lo que hay; los otros tres se añaden cuando exista el dato, y añadir un filtro es un cambio compatible (`ADR-038 §7.2`).

---

## 2. Alcance: qué entra, qué no, y por qué

### 2.1 Entra

| Sub-requisito | Qué parte |
|---------------|-----------|
| `REQ-BO-007` | **Todo**: identidad de plataforma, cuatro roles internos, MFA obligatorio sin excepción, lista blanca de IP, sesión corta con reautenticación, doble autorización genérica, `admin_action_logs` independiente e inmutable y consultable por el centro en lo que le afecte |
| `REQ-BO-001` | Inventario con los filtros que hoy tienen dato (§1.3); alta de tenant por API; las cinco transiciones de estado con motivo obligatorio; suspensión con mensaje configurable y reactivación; baja con período de gracia de 90 días; eliminación con confirmación por nombre y doble autorización; clonación acotada (§5.6) |
| `REQ-BO-002` | **Todo**, tal como lo reescribe `ADR-045`: matriz con tres estados, contratar/descontratar con motivo, dependencias como invariante de escritura, vista previa de impacto, activación masiva con un aviso por centro |
| `RMOD-006`, `RMOD-009`, `RMOD-010` | Los tres derivados de `ADR-045 §11`: `depends_on`/`essential` en el descriptor con validación que aborta el despliegue, los dos eventos de dominio emitidos por `REQ-CORE`, e invalidación de la caché de disponibilidad |
| `REQ-BO-004` | **Parte**: ficha de estado por centro con lo que hoy es observable (§5.9). **Sin impersonación** (§2.2) |
| `REQ-BO-006` | **Parte**: lo computable sin `REQ-SAAS` ni `REQ-ALUM` (§5.10) |
| `REQ-BO-005` **puntos 1-2** | **Todo lo que esos dos puntos dicen**, por decisión del usuario del 2026-09-08: catálogo de *flags* declarado en código, activación por tenant, por rol y por porcentaje, y designación de centros como *early adopters* (§5.11). Adelanta `REQ-OPS-002` —fase 2, `SHOULD`— y con él `RARQ-DEP-010` («el código se despliega apagado tras un *feature flag* y se activa después»). **Los puntos 3-4 de `REQ-BO-005` siguen fuera** (§2.2) |
| Issues [#6](https://github.com/pirexia/plataforma-educativa/issues/6) (puntos 2-3), [#7](https://github.com/pirexia/plataforma-educativa/issues/7), [#27](https://github.com/pirexia/plataforma-educativa/issues/27) | §6 |

### 2.2 No entra, con el motivo de cada exclusión

**`REQ-BO-003` · Planes, límites y facturación → depende de `REQ-SAAS` (fase 2).**
Los cuatro puntos del requisito citan `REQ-SAAS-002` (prorrateo), `RMT-005` (límites), `REQ-SAAS-003` (*dunning*) y un catálogo de planes que no existe. La cuota por almacenamiento y miembros activos ya estaba fuera por decisión del usuario del 2026-08-25 (issue [#79](https://github.com/pirexia/plataforma-educativa/issues/79)), y `ADR-045 §2` deja escrito que el modelo comercial sigue sin decidirse. Construir aquí una tabla `plans` sin plan comercial sería inventar el requisito, que es exactamente lo que `CLAUDE.md §11` prohíbe. **Lo único que 1.6 aporta a la facturación futura es la certeza de sobre qué columna se factura** (`RMOD-007`, `ADR-045 §4.6`), y eso ya está escrito.

**Impersonación (`REQ-BO-004`, último punto) → es `REQ-SUP-003`, fase 2, y merece paso y ADR propios.**
`REQ-SUP-003` exige motivo registrado, duración máxima, banner permanente, **consentimiento previo del Administrador de Centro configurable por tenant**, modo solo lectura por defecto, justificación adicional para escribir, auditoría de todo lo consultado y modificado, e informe periódico al tenant. Es una funcionalidad que atraviesa la frontera de aislamiento a propósito: convierte una sesión de plataforma, sin tenant, en una sesión dentro de un tenant concreto, con los permisos de otra persona. Es, con diferencia, **la funcionalidad más peligrosa del producto**, y no hay nada de ella construido (§1.2). Meterla en el mismo paso que la creación de la identidad de plataforma multiplica el riesgo de las dos.

**Ventanas de mantenimiento, notas de versión y avisos a los centros (`REQ-BO-005`, puntos 3-4) → `REQ-OPS-001` + `REQ-COM` (1.19).**
«Programación y **comunicación**» y «publicación de avisos» son envíos a los usuarios de los centros. No existe infraestructura de notificaciones in-app (`ADR-045 §1.1`) y `REQ-COM` es el paso 1.19. Es el mismo argumento con el que `ADR-045 §4.8` se negó a adelantar `REQ-COM-003` para el aviso de módulo contratado, y aquí aplica igual.

> ***Feature flags* y *early adopters* (`REQ-BO-005`, puntos 1-2) ya no están aquí.** Esta especificación los excluía señalando que la exclusión era «menos firme que las anteriores», porque lo que los sacaba de 1.6 no era una dependencia sino el tamaño. **El usuario decidió el 2026-09-08 incluirlos** (`OPEN-BO-07`, resuelta en §14): pasan a §2.1, se especifican en §5.11 y ocupan el sub-paso `1.6e` (§12.2). Lo que sigue excluido de `REQ-BO-005` son sus puntos 3-4, justo encima.

**Exportación completa durante el período de gracia (`REQ-BO-001`) → `REQ-OPS-004` / `REQ-BKP` (1.26).**
`REQ-BO-001` dice que la baja da 90 días «con exportación completa disponible (`REQ-OPS-004`)». Ese módulo no existe. **Consecuencia que hay que decir en voz alta**: en 1.6 el período de gracia existe pero no hay nada con lo que ejercer la portabilidad durante él. Ver `OPEN-BO-05`.

**Purga física de un tenant eliminado → `REQ-PRIV-006`.**
`ADR-004` define tres niveles y 1.6 implementa el primero: `status = 'eliminado'` más `deleted_at`, acceso revocado, datos conservados. La purga física es el nivel 3 y pertenece al catálogo de retención de `REQ-PRIV-006`, que no existe. El criterio de aceptación de §5.51 —«eliminar con una sola cuenta de administrador se impide»— se cumple entero con el nivel 1. Ver `OPEN-BO-05`.

**Aprovisionamiento de infraestructura para tenants *enterprise* en instancia dedicada (`RMT-004`).**
La nota para el implementador de `REQ-BO-005` obliga a que ese flujo esté «claramente separado en el panel para no confundir ambas operaciones». En 1.6 **no hay flujo dedicado en absoluto**: bajo `ADR-001` el alta es una operación de datos que se completa en segundos, y eso es lo único que se construye. La separación se hará cuando exista el segundo flujo; adelantar hoy una pantalla que distingue dos caminos de los que sólo uno existe es una pantalla que miente.

**Interfaz gráfica del backoffice → paso propio, posterior a 1.7 y 1.9.** Ver §12.5. Confirmado por el usuario el 2026-09-08 (`OPEN-BO-08`, resuelta en §14): los cinco sub-pasos se operan **sólo por API**.

---

## 3. La separación de la aplicación: opciones, costes y por qué **no la decido**

`REQ-BO` dice literalmente: «Aplicación **separada del producto** que usan los centros, con su propio dominio, su propia autenticación y sus propios roles. Un usuario de un tenant nunca puede alcanzar este backoffice, ni siquiera con el rol máximo de su centro.»

Eso fija tres propiedades **obligatorias** —dominio propio, autenticación propia, roles propios— y **no fija** el diseño técnico que las produce. Lo que sigue es lo que el código y los ADR ya imponen, las opciones que quedan vivas, y la pregunta.

### 3.1 Lo que ya está decidido y no se discute

| Restricción | Origen | Consecuencia |
|-------------|--------|--------------|
| `ResolveTenant` es el **primer** *middleware* de los grupos `api` y `web`, resuelve por *host* y lanza `TenantNotResolved` → 404 si el *host* no es de ningún centro | `ADR-033 §2` | El dominio del backoffice **no resuelve ningún tenant**. Sus rutas no pueden pasar por ese grupo: necesitan pila de *middleware* propia. Esto es cierto en **todas** las opciones |
| Cookie de sesión ***host-only***, sin dominio principal | `ADR-033 §2` | La sesión del backoffice queda ligada a su *host* por construcción. Una cookie de `centroa.dominio` **no puede** viajar al dominio del backoffice, ni al revés. `RMT-009` se cumple solo |
| El backoffice escribe por `plataforma_platform` (`BYPASSRLS`), credenciales separadas | `ADR-033 §5` | «Encaja con que `REQ-BO` sea una aplicación aparte», dice el propio ADR |
| Monolito modular hasta la fase 3 | `ADR-002` | Un servicio desplegable independiente **contradice** una decisión vigente y exigiría ADR nuevo |
| El *frontend* no hace de proxy; Traefik enruta `/api/*` bajo el mismo *host* | `ADR-028` | Sea cual sea la opción, el enrutado es de Traefik, no de la SPA |
| El dominio real de la plataforma **no está decidido** | `OPEN-08`, `ADR-038 §6.2` | Ningún nombre de *host* concreto puede escribirse hoy. Se parametriza por variable de entorno |

### 3.2 Las opciones que quedan vivas

**Opción A · Mismo monolito, segundo *guard*, segundo grupo de rutas, SPA propia.**
`platform` como *guard* de sesión sobre el modelo `PlatformAdmin`; grupo de rutas `/api/platform/v1` sin `ResolveTenant` y con su propia pila; una segunda aplicación Vite (`apps/backoffice`) con su propio `dist`, enrutada por Traefik al mismo contenedor de API bajo el *host* de plataforma.
*Coste*: bajo. *Riesgo*: el aislamiento entre las dos superficies depende de la configuración de rutas y de que ningún *middleware* se cuele; hay que probarlo, no confiarlo (§CA-BO-001/002).
*Compatible con* `ADR-002` sin tocarlo.

**Opción B · Aplicación desplegable independiente** (`apps/backoffice-api`), compartiendo únicamente la base de datos por el rol `plataforma_platform`.
*Coste*: alto — segundo contenedor, segundo ciclo de *release*, segunda configuración, duplicación de `App\Support` o extracción a un paquete compartido.
*Beneficio real*: el aislamiento deja de depender de la disciplina de enrutado y pasa a ser una propiedad del despliegue; la lista blanca de IP se aplica en el *ingress* y no en la aplicación.
*Requiere ADR* que ampíe o excepcione `ADR-002`.

**Opción C · Misma SPA con un «modo backoffice».** **Descartada, y sí decido esto**, porque contradice el requisito de forma directa: una sola aplicación servida en los dos dominios significa que el *bundle* del backoffice viaja al navegador de cualquier usuario de cualquier centro. «Un usuario de un tenant nunca puede alcanzar este backoffice» dejaría de ser una propiedad y pasaría a ser una condición en tiempo de ejecución. Se descarta por incompatibilidad con el requisito, no por preferencia.

### 3.3 Por qué paro aquí

Entre A y B hay una diferencia de **naturaleza**, no de detalle: B toca `ADR-002`, que es una decisión arquitectónica vigente. `CLAUDE.md §11` dice que no se cambia una decisión de un ADR sin un ADR nuevo, y `CLAUDE.md §0` que no se rellenan huecos con suposiciones. Además, la elección arrastra a `OPEN-BO-02` (dónde vive la sesión del backoffice) y a cómo se aplica la lista blanca de IP.

**`OPEN-BO-01` (§14) es, por tanto, la primera pregunta que hay que responder, y recomiendo que se resuelva con un ADR propio antes de implementar nada.** `ADR-045` no la toca ni pretende tocarla (`ADR-045 §2`: «No decide la autenticación ni los roles internos del backoffice»).

Mi lectura, ofrecida como insumo y **no** como decisión: **A**, por `ADR-002` y por coste, con dos condiciones que la hacen defendible — (1) la lista blanca de IP se aplica **además** en Traefik, no sólo en la aplicación, de modo que la primera barrera no sea código; y (2) un test de arquitectura que falle si una ruta de plataforma aparece bajo el grupo de tenant o al revés, con el mismo espíritu de «convertir disciplina en *build* roto» de `ADR-033 §10`.

---

## 4. Actores y roles internos

### 4.1 Los cuatro roles de `REQ-BO-007`

| Rol interno | Alcance según el requisito | Lo que puede en 1.6 |
|-------------|---------------------------|---------------------|
| `soporte` | «solo lectura y diagnóstico» | Leer el inventario, la ficha de salud y la auditoría de plataforma. **Nada de escritura.** Su acceso real a los datos de un centro será la impersonación (`REQ-SUP-003`), que no está en este paso |
| `operaciones` | «módulos, límites, flags» | Contratar y descontratar módulos, individual y en masa; suspender y reactivar tenants |
| `comercial` | «planes y facturación» | En 1.6, **casi nada**: no hay planes ni facturación (§2.2). Se declara el rol y se le concede lectura del inventario, para que exista cuando `REQ-SAAS` llegue |
| `superadministrador` | «ciclo de vida y eliminación» | Todo lo anterior más alta, baja y eliminación de tenants, gestión de administradores de plataforma y aprobación de dobles autorizaciones |

### 4.2 Estos roles **no** son roles de `REQ-PERM`, y no es una decisión mía

`ADR-034 §2` ya lo decidió y `REQ-CORE/permisos.md §4.5` lo recoge: *«los roles de plataforma viven en `platform_admins` y sus propias tablas, sin `tenant_id`, en el paso 1.6. Insertar un superadministrador en `roles` sería darle un tenant, que es exactamente lo que no es.»* `super_administrador` no es fila de `roles` desde 1.1, confirmado por el usuario en el issue [#48](https://github.com/pirexia/plataforma-educativa/issues/48).

Lo que sí me toca es **justificar que la decisión sigue siendo la correcta tras 1.5**, porque ahora existe un motor de autorización de verdad y la tentación de reutilizarlo es legítima. Cuatro razones, verificadas contra el código de 1.5:

1. **`roles` es tabla de tenant con RLS `FORCE`** y unicidad `(tenant_id, code)`. Un rol de plataforma necesitaría `tenant_id` nulo, lo que rompe la política `tenant_isolation`, el índice único y el test de esquema #8 de `ADR-033 §10`.
2. **`PermissionResolver` está atado al tenant en tres puntos**: consulta `roles.tenant_id`, aplica el filtro de inercia `inerte_modulo` a través de `ModuleAvailability` —que es una noción **por tenant**— y se registra como `scoped()` dentro de una petición que ya entró en un tenant.
3. **El vocabulario de ámbitos de `RPERM-004` no tiene la dimensión que el backoffice necesita.** Los seis ámbitos (`todos`, `propios`, `departamento`, `grupo`, `clase`, `unidad_familiar`) son ámbitos **dentro** de un centro. El ámbito de un administrador de plataforma es «qué centros», que no está en la lista — y `ADR-044 §4.1` cerró ese vocabulario y sólo admite ampliarlo por ADR nuevo. Forzarlo sería exactamente lo que ese ADR quiso impedir.
4. **`INV-007`**: `REQ-BO` no puede importar código interno de `App\Support\Authorization`.

**Qué sí se reutiliza: la forma, no la maquinaria.** La autorización del backoffice conserva denegación por defecto (`RPERM-011`), verificación en cada *endpoint* (`INV-002`) y comprobación de **capacidad, nunca de código de rol** dentro de los servicios — el error característico que la *skill* `permisos-y-roles` señala el primero. La diferencia con `REQ-PERM` es que **el mapa capacidad → rol vive en el código y no en la base de datos**, porque `REQ-BO-007` enumera exactamente cuatro roles y **ningún requisito pide roles de plataforma personalizados**. Detalle completo en `permisos.md`.

### 4.3 Multi-rol: sí, y por un motivo concreto

Un `platform_admin` puede tener **uno o varios** de los cuatro roles (`datos.md §2.2`). No es generalidad gratuita: `REQ-BO-007` exige que ninguna acción destructiva se haga en un solo paso y que la doble autorización sea de **dos administradores distintos**; con un solo rol por persona, una organización pequeña —que es la situación real de este proyecto— tendría que crear cuentas artificiales para poder operar. Con multi-rol, la separación que de verdad importa (dos **personas**) se conserva intacta y la de roles no estorba.

Resolución multi-rol: **unión de capacidades**. No hay `deny` en el backoffice, y decirlo explícitamente evita que alguien lo añada por simetría con `RPERM-007`: `deny` existe en el tenant porque hay roles personalizados que el centro compone; aquí los roles son cuatro y fijos, y un `deny` sólo añadiría una forma de equivocarse.

### 4.4 Actores no humanos

| Actor | Qué hace |
|-------|----------|
| `console` | Comandos de aprovisionamiento y mantenimiento. Escriben en `admin_action_logs` con `actor_type = 'console'` y sin administrador asociado |
| `system` | Transiciones automáticas: caducidad de una doble autorización, vencimiento del período de gracia |

---

## 5. Flujos principales

### 5.1 Acceso al backoffice (`REQ-BO-007`)

1. La petición llega al *host* de plataforma. **Antes de nada, la lista blanca de IP**: si la dirección de origen no está contenida en ninguna entrada activa, `403` y **entrada en `admin_action_logs`** con `action = 'acceso.rechazado_por_ip'` y sin revelar nada más.
2. Credenciales (`POST /platform/auth/session`). Contraseña verificada contra `platform_admins.password`. Mismos límites de tasa y bloqueo por intentos que `REQ-AUTH-001`, con su propio contador.
3. **Segundo factor, siempre.** No hay período de gracia, no hay exención y no existe el equivalente a `user_mfa_exemptions` de 1.3b (§7.2).
4. Sesión emitida con vida corta (`RN-BO-09`), *host-only*, `httpOnly`, `Secure`, `SameSite`, con CSRF (`ADR-025`).
5. Toda la secuencia queda auditada: intento, éxito o fallo, IP, *user-agent* y `request_id`.

**Un usuario de tenant que llegue al dominio del backoffice** no encuentra ningún *endpoint* que acepte su cookie: la cookie es *host-only* y no viaja, el *guard* es otro y el *provider* apunta a otra tabla. El intento se audita (`CA-BO-002`), que es lo que exige el cuarto criterio de aceptación de §5.51.

### 5.2 Reautenticación para operaciones sensibles (`REQ-BO-007`)

Las operaciones marcadas como sensibles (`api.md §4`) exigen que la sesión haya reautenticado —contraseña **y** segundo factor— dentro de una ventana corta. Se guarda como marca de tiempo **en la sesión**, no en una tabla: es estado de sesión, muere con ella, y una tabla añadiría un ciclo de vida que limpiar sin aportar nada. Si la marca falta o ha caducado: `403` con `urn:pge:error:reauthentication-required`, que la interfaz distingue de un `forbidden` sin analizar texto.

### 5.3 Alta de un tenant (`REQ-BO-001`)

Es **una operación de datos, no un despliegue** (nota para el implementador de `REQ-BO-005`), y debe completarse en segundos.

1. `superadministrador` envía nombre, `slug`, idiomas activos y por defecto, zona horaria, moneda, CCAA y los datos del primer Administrador de Centro.
2. Validación: `slug` único entre los tenants vivos, con formato de etiqueta DNS; idioma por defecto contenido en los activos; los cuatro de `ADR-021`; zona horaria IANA; moneda ISO 4217; CCAA del catálogo.
3. En una transacción por `pgsql_platform`: fila en `tenants` con `status = 'en_alta'`, y **dentro del contexto del tenant recién creado**, todo lo que hoy hace `tenant:provision-defaults` (los 16 roles predefinidos, sus concesiones, la configuración inicial) más la `Person`/`User` del primer administrador y su invitación.
4. Transición a `activo` cuando el aprovisionamiento termina sin error.
5. `admin_action_logs`: `tenant.creado`, con `affected_tenant_id`.

**Esto convierte en `endpoint` lo que hoy es un comando de consola**, que es exactamente lo que `REQ-CORE/funcional.md §1.1` difirió a este paso: *«exponer el alta de tenants por HTTP antes de que exista el backoffice y su registro de auditoría de plataforma sería crear una operación crítica sin trazabilidad»*. Esa trazabilidad es lo que este paso construye. **El comando de consola se conserva**, no se retira: es el camino de arranque del primer tenant y del entorno de desarrollo, y ahora escribe en el mismo registro con `actor_type = 'console'`.

`REQ-ONB-001` (asistente de alta con *checklist* y datos de demostración) es el paso **1.24** y no se adelanta: 1.6 entrega la operación, no el asistente.

### 5.4 Suspensión y reactivación (`REQ-BO-001`)

**Suspender** (`activo` → `suspendido`), con motivo obligatorio y mensaje configurable para los usuarios del centro:

1. Se escribe `tenants.status`, `suspended_at`, `suspension_message` y la fila de `tenant_lifecycle_events`.
2. **En la misma operación** se invalida `tenant-resolution:{slug}` — es el issue [#7](https://github.com/pirexia/plataforma-educativa/issues/7), y `RN-BO-14` lo convierte en regla.
3. A partir de ese instante, cualquier petición a un *host* del centro recibe **`503`** con el mensaje configurado, `Retry-After` (`ADR-038 §6.5`) y ningún dato. Lo dice `ADR-033 §2` y lo repite el primer criterio de aceptación de §5.51.
4. **Se conservan íntegros los datos y las tareas programadas críticas.** Un tenant suspendido no pierde nada y no deja de recibir sus purgas de retención.

**Reactivar** (`suspendido` → `activo`) es la operación inversa, «reversible en un clic», con la misma invalidación de caché y su motivo.

Si `suspension_message` es nulo se sirve un mensaje por defecto del catálogo de traducción de la plataforma, en el idioma resuelto (`INV-009`). El mensaje que escribe el operador es **contenido**, no literal de código, y por eso puede ser un solo texto.

### 5.5 Baja y eliminación (`REQ-BO-001`, `REQ-BO-007`)

**Baja** (`activo` → `en_baja`): motivo obligatorio; se calcula `grace_period_ends_at` a 90 días; el acceso de los usuarios queda bloqueado igual que en la suspensión, con un mensaje propio. Durante la gracia, `REQ-OPS-004` debería ofrecer la exportación completa — y no existe (`OPEN-BO-05`).

**Eliminación** (`en_baja` → `eliminado`): la operación más peligrosa del producto, y por eso lleva **cuatro** cerrojos que se comprueban en este orden:

1. Sólo `superadministrador`, y con reautenticación viva (§5.2).
2. El solicitante escribe el **nombre exacto del tenant**; una diferencia de un carácter es `422`.
3. **Doble autorización** (§5.7): la solicitud queda pendiente y no ejecuta nada.
4. Un `superadministrador` **distinto** aprueba, y sólo entonces se ejecuta.

Efecto en 1.6: `status = 'eliminado'`, `deleted_at`, acceso revocado, datos **conservados**. La purga física es `REQ-PRIV-006` (§2.2, `OPEN-BO-05`).

### 5.6 Clonación de un tenant (`RMT-007`)

Se clona **la configuración, nunca las personas**. En 1.6 eso es: la configuración del centro, los roles y sus concesiones, y las suscripciones de módulo. No se copian usuarios, ni invitaciones, ni auditoría, ni ningún dato personal — no porque no haya más que copiar hoy, sino porque **la regla debe quedar escrita antes de que exista más que copiar**: cuando lleguen alumnos y familias, un clon que arrastre personas sería una cesión de datos entre centros.

El resultado es un tenant nuevo en `en_alta`, con su propio `slug`, que sigue el mismo camino que §5.3 a partir del punto 4.

### 5.7 Doble autorización (`REQ-BO-007`)

Mecanismo **genérico y con vocabulario cerrado**, no una condición dentro de cada operación destructiva. Motivo: `REQ-BO-007` lo exige para «eliminar un tenant, purgar datos o desactivar módulos en masa», y una implementación por operación garantiza que la cuarta operación destructiva que alguien añada se le olvide.

1. **Solicitud**: el operador llama al *endpoint* de la operación con la cabecera de confirmación reforzada. En vez de ejecutar, se crea una `dual_authorization` en estado `pendiente` con la operación **congelada** —sus parámetros exactos y una huella de ellos— y su motivo. Respuesta `202`.
2. **Aprobación**: otro administrador la aprueba. La regla «dos personas distintas» **la impone la base de datos** con un `CHECK`, no el controlador (`datos.md §5`, `RN-BO-19`). Es la restricción más importante del módulo y no puede vivir en PHP, por el mismo argumento con el que `ADR-045 §4.4` sacó del controlador la escritura de `enabled`.
3. **Ejecución**: al aprobar se ejecuta la operación congelada, **con los parámetros de la solicitud y nunca con los que traiga la aprobación**. Si los parámetros hubieran dejado de ser válidos, falla y queda `fallida` con su motivo.
4. **Caducidad**: sin aprobar en su ventana, pasa a `caducada` y no se puede ejecutar.
5. Rechazo explícito con motivo, también auditado.

Las cinco transiciones se escriben en `admin_action_logs`. **Solicitar no es aprobar, y aprobar no es ejecutar**: son tres eventos distintos y los tres se registran.

### 5.8 Contratar y descontratar módulos (`REQ-BO-002`, `ADR-045`)

**Contratar** un módulo `M` para un tenant `T`:

1. Comprobación de capacidad y de que `M` existe en el catálogo y no está `retired_at`.
2. **Cierre de dependencias** (`RMOD-006`): se resuelve el conjunto de dependencias de `M` que `T` no tiene contratadas. La vista previa las muestra; la ejecución las contrata **junto con** `M`, en la misma transacción. Ninguna escritura puede dejar un módulo contratado cuya dependencia no lo esté (`RN-BO-22`).
3. Escritura por `pgsql_platform`: `enabled = true`, `enabled_at = now()`, `reason` con el motivo obligatorio.
4. **Invalidación de la caché** de disponibilidad del tenant afectado, con su prefijo (`ADR-045 §8.3`, §6.3 de este documento).
5. `REQ-CORE` emite **`ModuleContracted`** con `tenant_id`, `module_code` y el actor de plataforma. Lo emite el servicio de contratación de `REQ-CORE`, **no el backoffice**, para que el evento exista también cuando la escritura venga de la activación masiva o de un comando (`ADR-045 §4.8`).
6. `admin_action_logs`: una entrada por módulo, con `affected_tenant_id`.

**Descontratar** es simétrico, con dos diferencias: un módulo **esencial** no se descontrata nunca (`422`), y si otros módulos contratados dependen de `M`, la vista previa los lista y la ejecución exige confirmación explícita — o se arrastran, o la operación se rechaza; no hay tercera vía que deje el grafo roto.

**Activación masiva**: la misma operación sobre un conjunto de tenants. Se ejecuta **en cola** (`INV-012`), con `Idempotency-Key` obligatoria (`ADR-038 §8.1`, criterios 2 y 3: notifica a terceros y opera por lotes), y emite **un evento y un aviso por cada centro afectado**, nunca uno global (`ADR-045 §4.7`). Su vista previa dice, en número, cuántos centros pasan a tenerlo y cuántas dependencias arrastra. La **desactivación** masiva es acción destructiva y pasa por §5.7.

### 5.9 Ficha de salud del tenant (`REQ-BO-004`, parte)

Sólo lo que hoy es observable de verdad. **Nada de valores calculados de mentira.**

| Dato de `REQ-BO-004` | En 1.6 |
|----------------------|--------|
| Versión desplegada | ✅ Es de plataforma, no por tenant. Se muestra una vez |
| Últimas migraciones aplicadas | ✅ De la tabla `migrations`; también de plataforma |
| Jobs en cola / fallidos | ✅ De Horizon y de `failed_jobs`, **filtrados por el `tenant_id` que `ADR-033 §8` estampa en el *payload*** |
| Errores recientes | ⚠️ Recuento de `failed_jobs` del tenant. **Sin agregación de logs de aplicación**: no hay recolector, y `ADR-037` no lo contempla |
| Uso de recursos frente a límites | ❌ No hay límites (`RMT-005` es `REQ-BO-003`) |
| Certificado SSL, caducidad, validación de dominio | ❌ Es infraestructura (Traefik/ACME) y `OPEN-08` sigue abierta. `REQ-CORE/funcional.md §1.2` ya lo difirió |
| Conectores externos, último volcado a Raíces | ❌ `REQ-SEC-004`, fase 2 |
| Reintento de jobs y reenvío de notificaciones (`REQ-SUP-004`) | ✅ Reintento de un job fallido de un tenant, auditado. **Reenvío de notificaciones no**: no hay notificaciones (`REQ-COM`, 1.19) |
| Incoherencias de dependencias de módulo | ✅ `ADR-045 §4.5` pide que se muestren aquí las aristas que `platform:sync-registry` haya reportado y nadie haya resuelto |

### 5.10 Métricas de plataforma (`REQ-BO-006`, parte)

| Métrica | En 1.6 |
|---------|--------|
| Tenants por estado, altas y bajas | ✅ De `tenants` y `tenant_lifecycle_events` |
| Adopción por módulo | ✅ De `module_subscriptions`: cuántos centros tienen contratado cada módulo |
| Alumnos totales | ❌ `REQ-ALUM`, paso 1.15 |
| Ingresos recurrentes, *churn* | ❌ `REQ-SAAS-004`, fase 2 |
| Consumo de recursos frente a límites | ❌ No hay límites |
| Alertas de salud comercial | ❌ Necesita tickets (`REQ-SUP`) e impagos (`REQ-SAAS`) |

**El panel de 1.6 muestra dos números honestos en vez de seis inventados.** Es preferible a rellenar con ceros: un cero indistinguible de «no medido» es peor que la ausencia.

### 5.11 Motor de *feature flags* (`REQ-BO-005` puntos 1-2, adelanta `REQ-OPS-002`)

Entra en alcance por decisión del usuario del 2026-09-08. Lo que sigue es el motor completo, con el mismo rigor que el resto del documento: primero lo que el requisito dice literalmente, después una contradicción que hay que resolver antes de diseñar nada, y después el diseño.

#### 5.11.1 Lo que piden los dos requisitos, textualmente

| Origen | Texto |
|---|---|
| `REQ-BO-005` punto 1 | «Gestión de *feature flags* por tenant, por rol o **por porcentaje** (`REQ-OPS-002`)» |
| `REQ-BO-005` punto 2 | «Designación de tenants como ***early adopters*** para recibir novedades antes que el resto» |
| `REQ-OPS-002` | «Activación de funcionalidades por tenant, por rol o **por porcentaje de usuarios**» · «Despliegue canario y **reversión inmediata sin nuevo despliegue** (`RNF-MANT-005`)» · «**Registro de cambios de flag**» |
| `RARQ-DEP-010` | «Despliegue desacoplado de la activación: el código se despliega apagado tras un *feature flag* y se activa después. Permite revertir una funcionalidad sin revertir el despliegue» |

De aquí salen **cuatro** capacidades, no dos: activación por los tres ejes, cohorte de *early adopters*, interruptor de emergencia (la «reversión inmediata sin nuevo despliegue») y registro de cambios. Las cuatro entran; ninguna es invención mía.

#### 5.11.2 Una contradicción entre los dos requisitos, y cómo se resuelve sin inventar

`REQ-BO-005` dice «por porcentaje», sin decir de qué. `REQ-OPS-002` dice «por porcentaje **de usuarios**». **No son lo mismo y la diferencia importa mucho en este dominio**: un porcentaje de usuarios reparte la funcionalidad *dentro* de un colegio, de modo que dos profesores del mismo claustro ven pantallas distintas, comparan y llaman a secretaría. Un porcentaje de centros expone a colegios enteros y conserva la coherencia interna, que es lo que un centro espera.

Ninguna de las dos lecturas se puede descartar: la primera es la letra de `REQ-OPS-002`, la segunda es la única compatible con «designación de **tenants** como *early adopters*», que es el punto 2 del mismo requisito. Resolverlo eligiendo una sería recortar un requisito; resolverlo dejando que lo elija el operador en cada regla sería trasladarle una decisión técnica que no puede tomar bien.

**Se resuelve así: la unidad de reparto es una propiedad del *flag*, declarada en el código por quien escribe la funcionalidad, no un parámetro de la regla.** El descriptor de cada *flag* declara `rollout_unit` ∈ {`tenant`, `user`}, con `tenant` por defecto. Quien programa la funcionalidad es el único que sabe si repartirla por personas dentro de un mismo centro es seguro —lo es en un cambio de interfaz individual, no lo es en nada que produzca un documento, un cálculo o una vista compartida—. El operador elige **el porcentaje**; no elige la unidad. Así se cumplen las dos frases del requisito y no se le da a un operador de plataforma un botón con el que partir un claustro por la mitad sin saberlo.

#### 5.11.3 Dónde vive cada cosa: catálogo en el código, reglas en la base de datos

Es exactamente el reparto que este proyecto ya ha hecho dos veces —el catálogo de módulos (`ADR-034 §5`, ratificado por `ADR-045 §9`) y el mapa de capacidades del backoffice (`permisos.md §2`)— y por el mismo motivo: *«una copia en base de datos de algo que declara el código se desincroniza en el primer despliegue en que alguien olvide correr el comando, y falla en silencio»*.

| Qué | Dónde | Por qué |
|---|---|---|
| **Qué *flags* existen** (clave, módulo dueño, unidad de reparto, textos) | **Código**, en el descriptor del módulo que los consulta | Un *flag* sólo existe si hay código que lo lee. Crear un *flag* desde una pantalla produce *flags* huérfanos que nadie consulta y que nadie se atreve a borrar |
| **Materialización de ese catálogo** | Tabla `feature_flags`, escrita por `platform:sync-registry` | Da integridad referencial a las reglas y permite listarlos. Nunca borra: marca `retired_at`, igual que `modules` |
| **Reglas de activación** | Tablas `feature_flag_rules` (`datos.md §9`) | Es lo único que cambia con el tiempo, y lo único que un operador escribe |
| **Cohorte de *early adopters*** | Columna `early_adopter_since` en **`tenants`** | Es una designación **del proveedor sobre un centro**, no una preferencia del centro. Por eso **no** va en `tenant_settings`, que es tabla de tenant y el propio centro escribe: un colegio no se declara *early adopter* a sí mismo |

**No se introduce ninguna dependencia externa.** Verificado el 2026-09-08: no hay `laravel/pennant` ni ningún cliente de Unleash o Flagsmith en el proyecto, y no se añade — `CLAUDE.md §1` exige justificar toda dependencia nueva, y aquí el motor son dos tablas y una función determinista.

> **Una consecuencia documental que hay que declarar, no dejar que la encuentre `doc-reviewer`**: el encabezado de la sección **5.49** del documento de requisitos lista `FeatureFlag` entre las **entidades principales de `REQ-OPS`**, y el de la **5.51** no la incluye entre las de `REQ-BO` (que son `Tenant`, `TenantLifecycleEvent`, `ModuleSubscription`, `PlatformAdmin` y `AdminActionLog`). Construir aquí `feature_flags` y `feature_flag_rules` **no cambia de dueño al requisito** —sigue siendo `REQ-OPS-002`, fase 2— pero sí adelanta su modelo de datos a un paso de fase 1. Es coherente con lo que `REQ-BO-005` pide literalmente («gestión de *feature flags*… `REQ-OPS-002`»), y es exactamente el tipo de discrepancia entre secciones que una revisión de documentación debe encontrar explicada en vez de tener que deducirla. **Si el usuario quiere que el documento de requisitos lo refleje**, es una edición de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` §5.49/§5.51 que **no me corresponde hacer a mí** y que la sesión orquestadora debe decidir.

#### 5.11.4 Los tres ejes y el interruptor

| Eje | Qué expresa | Sujeto sobre el que se evalúa |
|---|---|---|
| **Tenant** | «Este centro, nominalmente» | El tenant. Puede **encender o apagar**: es la forma de excluir a un centro concreto de un despliegue del 30 % |
| ***Early adopters*** | «Los centros que hayan aceptado ir por delante» | El tenant, por `early_adopter_since IS NOT NULL` |
| **Porcentaje** | «Un tanto por ciento del parque» | Tenant o usuario, según el `rollout_unit` **del flag** (§5.11.2) |
| **Rol** | «Sólo esta gente, dentro de los centros ya expuestos» | El usuario autenticado, por el **código** de sus roles |
| **Interruptor** (`forced_off`) | «Apagar esto ya, para todos» | El *flag* entero. No es un eje: está por encima de los cuatro |

**El rol no es un cuarto eje de exposición: es un filtro.** Un centro no expuesto no pasa a estarlo porque uno de sus usuarios tenga un rol listado. Se dice así de explícito porque la alternativa —que el rol amplíe— produce el caso en que un profesor de un centro no incluido en el despliegue ve una funcionalidad que su director no ve, y nadie sabe por qué.

#### 5.11.5 Orden de evaluación: uno solo, escrito, y comprobado

`isEnabled(clave)` para un tenant `T` y, cuando lo hay, un usuario `U`:

1. **¿Existe el *flag* en el catálogo y no está `retired_at`?** Si no: **falso**. Una clave desconocida nunca enciende nada (`RN-BO-44`).
2. **¿Está el *flag* en `forced_off`?** Si sí: **falso**, y no se mira ninguna regla. Es la «reversión inmediata sin nuevo despliegue» de `REQ-OPS-002` y `RNF-MANT-005`.
3. **Exposición del centro**, en este orden y **parando en la primera que aplique**:
   1. Regla de tipo `tenant` para `T` → su valor `enabled`, sea `true` o `false`. Lo nominal gana a lo estadístico: nombrar un centro es la expresión más específica de intención que un operador puede escribir.
   2. Regla `early_adopters` activa **y** `T.early_adopter_since IS NOT NULL` → expuesto.
   3. Regla `percentage` con `p` y el cubo de `T` (o de `U`, si `rollout_unit = 'user'`) por debajo de `p` → expuesto.
   4. Regla `global` activa → expuesto.
   5. **Ninguna** → **no expuesto**. No hay valor por defecto encendido (`RN-BO-35`).
4. **Filtro por rol**: si el *flag* tiene alguna regla `role` activa, sólo es verdadero para un `U` cuyos códigos de rol intersequen ese conjunto. **Sin `U` —una cola, un comando de consola, una petición sin sesión— el resultado es falso** (`RN-BO-41`).

#### 5.11.6 El reparto por porcentaje es una función, no un sorteo

`cubo = hash(clave_del_flag ⊕ public_id del sujeto) mod 100`, y expuesto si `cubo < p`. Determinista, sin almacenamiento, sin tabla de asignaciones y sin relleno retroactivo cuando aparece un centro nuevo.

Tres propiedades que se obtienen de elegir esta forma y **no** un sorteo con resultado guardado, y que son la razón de elegirla:

- **Estabilidad**: el mismo centro obtiene la misma respuesta en cada petición, en cada proceso y en cada nodo. Un sorteo por petición haría parpadear la funcionalidad; un sorteo guardado exigiría una fila por (*flag*, centro) y un relleno cada vez que se da de alta un colegio.
- **Monotonía**: subir el porcentaje **nunca** retira a un centro ya expuesto, y bajarlo retira exactamente a los que quedan por encima del umbral. Con asignación aleatoria almacenada eso no se cumple, y «he subido del 10 % al 20 % y tres centros han perdido la función» es un incidente que nadie sabe explicar.
- **Independencia entre *flags***: la clave entra en el hash, así que dos *flags* al 10 % **no** exponen al mismo 10 %. Sin ella, los mismos veinte centros serían siempre el conejillo de indias de todo.

#### 5.11.7 Qué no hace este motor, y hay que decirlo

- **No sustituye a la contratación de módulos.** `module_subscriptions` decide si un centro **tiene** un módulo; un *flag* decide si una funcionalidad concreta está expuesta. Un *flag* al 100 % sobre una funcionalidad de un módulo no contratado **no enciende nada**: la comprobación de módulo es previa, independiente y sigue devolviendo `urn:pge:error:module-disabled` (`RN-BO-45`, `CA-BO-092`).
- **No puede esconder un control de seguridad.** MFA, lista blanca de IP, doble autorización, aislamiento de tenant y comprobación de permisos quedan **fuera** de su alcance por regla (`RN-BO-47`). Es la misma decisión que ya se tomó al negarse a crear una variable de entorno que relajara el MFA (`operacion.md §2`): un conmutador de funcionalidad no es una excepción de seguridad, y el día que lo sea nadie recordará que lo es.
- **No lleva historial propio de negocio.** A diferencia de `tenant_lifecycle_events` (`datos.md §5.1`), aquí la aplicación **no lee** el historial para decidir nada: el estado vigente son las reglas, y quién las cambió es auditoría. El «registro de cambios de *flag*» de `REQ-OPS-002` se cumple con `admin_action_logs`, y no con una segunda tabla que nadie consultaría desde el código.
- **No programa nada en el tiempo.** No hay «enciéndelo el martes»: eso es la ventana de mantenimiento de `REQ-OPS-001`, que sigue fuera de alcance (§2.2).

---

## 6. Los tres issues que este paso retoma

### 6.1 Issue #27 · `Tenant` sin auditoría

`ADR-036` dejó `Tenant` fuera del *observer* de auditoría de tenant con una laguna «documentada y con fecha de cierre»: `admin_action_logs`, paso 1.6. **Este paso la cierra**: las cinco transiciones de estado, el alta, el cambio de `slug` y de nombre, y la clonación quedan en `admin_action_logs` con su actor de plataforma, su motivo y su retención propia.

`Tenant` **sigue sin implementar `Auditable`** y sigue fuera del *morph map* de `audit_logs`: el mecanismo de `ADR-035` exige contexto de tenant activo y `audit_logs` es tabla de tenant. La decisión de `ADR-036` no se revoca, **se cumple**. Recomiendo un ADR breve que declare el mecanismo concreto y cierre formalmente la fila de `ADR-036` (`OPEN-BO-04`).

### 6.2 Issue #6 · `runAsPlatform()` sin autorización ni auditoría

El punto 1 (test de arquitectura) lo cerró 1.5 con `RunAsPlatformArchitectureTest` (`CA-PERM-092`) y una lista de tres excepciones verificadas. Quedan los puntos 2 y 3, re-etiquetados a este paso.

**Y aquí hay que corregir la propuesta original del issue**, porque aplicada al pie de la letra produce algo peor que el problema:

- **Auditar cada llamada a `runAsPlatform()` no sirve.** Dos de sus tres llamadores legítimos son mantenimiento sin sujeto —`RunsPerTenant::eachTenant()` y `PurgeExpiredIdempotencyKeys`—; auditar cada iteración llenaría `admin_action_logs` de entradas sin significado de negocio y dejaría lo que de verdad importa enterrado. **Lo que se audita es la operación** («este administrador suspendió este centro»), no la primitiva que la ejecuta.
- **Comprobar un permiso dentro de la primitiva tampoco sirve**, por lo mismo: un comando de consola no tiene sujeto al que comprobarle nada.

Propuesta concreta, que convierte el `TODO` del *docblock* en un contrato tipado: **`runAsPlatform()` recibe un propósito declarado** —`Mantenimiento` (sin sujeto, no auditado, sólo alcanzable desde consola o cola) o `Backoffice` (exige administrador de plataforma autenticado con la capacidad correspondiente, y la operación que lo envuelve **debe** dejar entrada en `admin_action_logs`)—. Una llamada desde código de módulo sin propósito válido lanza excepción, igual que `tenantId()` lanza sin contexto: fallo en cerrado.

Esto **cambia una firma en `App\Support\Tenancy`**, que es infraestructura compartida por todo el producto. Lo propongo, no lo decido: `OPEN-BO-03`.

### 6.3 Issue #7 · Caché de resolución de tenant

`ResolveTenant` cachea `{id, status}` durante 60 s bajo `tenant-resolution:{slug}`. Hoy es inofensivo porque nadie cambia `status`; **este paso es exactamente el que deja de hacerlo inofensivo**.

`RN-BO-14` lo convierte en regla: **toda escritura de `tenants.status` invalida esa clave en la misma operación**, no como paso posterior. Con test de que un tenant recién suspendido deja de resolver **de inmediato**, no en hasta 60 s (`CA-BO-054`).

> **Cuidado con el hermano de este problema**, que es el que `ADR-045 §8.3` señala y que es más sutil: la caché de disponibilidad de módulo (`modules:{code}:enabled`) lleva el **prefijo de tenant** `t{tenant_id}:` que fija `TenantContext::enter()`, y **el backoffice escribe desde fuera del contexto del tenant**. Una invalidación ingenua limpiaría la clave del prefijo equivocado, y una activación masiva la limpiaría equivocada tantas veces como centros. La invalidación debe entrar en el contexto del tenant afectado, o componer su prefijo explícitamente. Con test (`CA-BO-033`).

---

## 7. Reglas de negocio

### 7.1 Identidad y acceso

| ID | Regla |
|----|-------|
| `RN-BO-01` | Un `platform_admin` **no tiene tenant**. Ninguna de sus tablas lleva `tenant_id` como propiedad, y ninguna consulta suya pasa por el *scope* global de tenant |
| `RN-BO-02` | Un `User` de tenant **nunca** se autentica en el backoffice, y un `platform_admin` **nunca** se autentica en la aplicación de los centros. Son dos *guards*, dos *providers* y dos modelos, sin puente |
| `RN-BO-03` | Denegación por defecto: toda ruta de plataforma exige capacidad explícita (`INV-002`, `RPERM-011`) |
| `RN-BO-04` | La autorización comprueba **capacidad**, nunca código de rol, dentro de servicios y controladores |
| `RN-BO-05` | **MFA obligatorio sin excepción.** Un `platform_admin` sin factor confirmado sólo alcanza los *endpoints* de alta de factor. No hay período de gracia y no existe mecanismo de exención — las excepciones temporales de 1.3b **no aplican** aquí |
| `RN-BO-06` | Sin dirección de origen contenida en la lista blanca activa: `403`, auditado, antes de comprobar credenciales |
| `RN-BO-07` | **Lista blanca vacía = denegar a todos.** Nunca «vacía = permitir». La salida de un bloqueo total es un comando de consola en el servidor, no una condición en el código |
| `RN-BO-08` | Las operaciones sensibles exigen reautenticación viva (§5.2) |
| `RN-BO-09` | La sesión de plataforma tiene vida **más corta** que la de tenant y su valor es configurable, nunca escrito en código |
| `RN-BO-10` | Un `platform_admin` no se suspende, no se elimina y no se cambia los roles **a sí mismo** (equivalente de `RN-CORE-06`) |
| `RN-BO-11` | Debe quedar **siempre al menos un `superadministrador` vivo y activo** (equivalente de `RN-CORE-07`) |

### 7.2 Ciclo de vida del tenant

| ID | Regla |
|----|-------|
| `RN-BO-12` | Transiciones permitidas, y **ninguna más**: `en_alta`→`activo`; `activo`↔`suspendido`; `activo`→`en_baja`; `en_baja`→`activo` (rescate durante la gracia); `en_baja`→`eliminado`. Cualquier otra: `409` |
| `RN-BO-13` | **Toda** transición lleva motivo obligatorio, no vacío, y deja fila en `tenant_lifecycle_events` y en `admin_action_logs` |
| `RN-BO-14` | Toda escritura de `tenants.status` invalida `tenant-resolution:{slug}` **en la misma operación** (issue #7) |
| `RN-BO-15` | Un tenant `suspendido`, `en_baja` o `eliminado` responde **`503`** a cualquier *host* suyo, con el mensaje configurado y sin ningún dato. `404` queda reservado a un *host* que no corresponde a ningún tenant: nunca se revela cuáles existen (`ADR-033 §2`) |
| `RN-BO-16` | La suspensión **no borra, no anonimiza y no detiene las tareas programadas críticas** del centro |
| `RN-BO-17` | `en_baja` fija `grace_period_ends_at` a 90 días. Vencido el plazo, **no se elimina nada de forma automática**: se marca como candidato y se avisa. Una purga automática por temporizador sobre datos de menores no es aceptable sin decisión humana |
| `RN-BO-18` | `eliminado` exige capacidad de `superadministrador`, reautenticación, confirmación por nombre exacto y doble autorización aprobada. En 1.6 es borrado lógico y revocación de acceso; **nunca purga física** (`ADR-004` nivel 1) |
| `RN-BO-19` | En una doble autorización, **quien aprueba no puede ser quien solicita**, y lo garantiza un `CHECK` de base de datos, no el controlador |
| `RN-BO-20` | La ejecución usa **los parámetros congelados en la solicitud**. Si su huella no coincide con lo aprobado, la operación se rechaza |
| `RN-BO-21` | La clonación copia configuración, roles, concesiones y suscripciones de módulo. **Nunca personas, usuarios, invitaciones ni auditoría** |

### 7.3 Módulos (`ADR-045`)

| ID | Regla |
|----|-------|
| `RN-BO-22` | Ninguna escritura puede dejar un módulo contratado cuya dependencia no lo esté. Una sola implementación, en un servicio de dominio de `REQ-CORE`, consumida por la contratación individual, la masiva y la vista previa |
| `RN-BO-23` | Un módulo **esencial** no se descontrata: `422`. Los esenciales se leen del descriptor, no de una lista escrita a mano |
| `RN-BO-24` | Contratar y descontratar exigen **motivo**, que se guarda en `module_subscriptions.reason` |
| `RN-BO-25` | Toda escritura de `enabled` **invalida la caché de disponibilidad del tenant afectado, con su prefijo correcto**, en la misma operación (`ADR-045 §8.3`) |
| `RN-BO-26` | Toda escritura de `enabled` emite `ModuleContracted` o `ModuleDecontracted` **desde `REQ-CORE`**, nunca desde el backoffice (`ADR-045 §4.8`, `RMOD-010`) |
| `RN-BO-27` | La activación masiva emite **un evento por cada centro afectado**, jamás uno agregado |
| `RN-BO-28` | `platform:sync-registry` **aborta el despliegue** si `depends_on` referencia un código inexistente o el grafo tiene ciclos. Ante una arista nueva que deje centros incoherentes, **informa y no corrige** |

### 7.4 Auditoría de plataforma

| ID | Regla |
|----|-------|
| `RN-BO-29` | `admin_action_logs` es **append-only**: `REVOKE UPDATE, DELETE` para todos los roles, sin excepción (`REQ-BO-007`, precedente de `audit_logs`) |
| `RN-BO-30` | **No se mezcla con `audit_logs`.** Son dos tablas, dos vocabularios y dos retenciones. Una acción de plataforma no aparece en la auditoría del tenant y viceversa |
| `RN-BO-31` | Toda entrada que afecte a un centro concreto lleva `affected_tenant_id`, y es lo que permite que el centro la consulte «en lo que le afecte» (`REQ-BO-007`) |
| `RN-BO-32` | La redacción de valores sigue la política de `ADR-035`: se registra que el atributo cambió, no su valor, para identificadores personales, categoría especial, secretos y valores sobredimensionados |
| `RN-BO-33` | **El backoffice no muestra listados de alumnos ni datos personales de los centros** (`REQ-BO-007`, último punto). Muestra métricas, estado y recuentos. El acceso a un dato concreto es impersonación, que no está en este paso |

### 7.5 *Feature flags* (`REQ-BO-005` puntos 1-2)

| ID | Regla |
|----|-------|
| `RN-BO-34` | El **catálogo** de *flags* se declara en el código, en el descriptor del módulo que los consulta —o en el del núcleo si no pertenecen a ninguno—, y se materializa en `feature_flags` con `platform:sync-registry`. **No existe ningún camino para crear un *flag* desde la API**: crear un *flag* es escribir el código que lo lee |
| `RN-BO-35` | **Un *flag* está apagado por defecto.** No hay valor «encendido de fábrica»: la exposición nace únicamente de una regla explícita. Es denegación por defecto aplicada a funcionalidad, con el mismo criterio que `RPERM-011` — y un *flag* encendido para todos sin regla ninguna no es un *flag*, es código entregado |
| `RN-BO-36` | La **unidad de reparto** (`tenant` o `user`) la declara el *flag* en el código, **nunca la regla ni el operador** (§5.11.2). El operador elige el porcentaje; no elige a quién se parte |
| `RN-BO-37` | Orden de evaluación **único** y el de §5.11.5. `forced_off` está por encima de todo y ninguna regla lo sobreescribe; una regla nominal de tenant gana a la cohorte, al porcentaje y a la regla global |
| `RN-BO-38` | El reparto por porcentaje es una **función determinista** de (clave del *flag*, `public_id` del sujeto): sin aleatoriedad, sin asignaciones almacenadas y sin relleno retroactivo. **Subir el porcentaje nunca retira a un sujeto ya expuesto** |
| `RN-BO-39` | La clave del *flag* entra en el hash: **dos *flags* al mismo porcentaje no exponen al mismo conjunto** |
| `RN-BO-40` | Una regla de rol referencia el **código** del rol, no su fila —`roles` es tabla de tenant y una regla de plataforma no puede apuntar a la fila de un centro concreto—. Un código que no existe en un centro **no expone a nadie** y **no es un error de escritura**: funciona igual sobre roles personalizados que aún no existían cuando la regla se escribió |
| `RN-BO-41` | Evaluado **sin sujeto usuario** —cola, comando de consola, petición sin sesión—, un *flag* con reglas de rol es **falso**. Fallo en cerrado, igual que `ModuleAvailability::isEnabled()` fuera de contexto de tenant |
| `RN-BO-42` | Toda escritura de reglas o del estado de un *flag* **incrementa su `rules_version` en la misma transacción**, y esa versión forma parte de la clave de caché de evaluación (`operacion.md §4.3`) |
| `RN-BO-43` | Toda escritura de *flags* exige **motivo** no vacío y deja entrada en `admin_action_logs`, con `affected_tenant_id` cuando la regla nombra a un centro y `NULL` cuando el alcance es global. Es el «registro de cambios de *flag*» de `REQ-OPS-002` |
| `RN-BO-44` | Un *flag* **retirado** (`retired_at`) evalúa siempre falso y sus reglas dejan de admitir escritura. El catálogo **nunca borra**, con el precedente exacto de `SyncModuleRegistry` |
| `RN-BO-45` | **Un *flag* nunca enciende una funcionalidad de un módulo no contratado.** La comprobación de módulo es previa e independiente: `module_subscriptions` decide qué tiene el centro, el *flag* decide qué está expuesto |
| `RN-BO-46` | La designación de ***early adopter*** es del **centro** y no del *flag*: se marca una vez, con motivo, y sirve a todas las reglas de cohorte. Se puede retirar, y retirarla apaga sólo los *flags* que dependían **únicamente** de ella |
| `RN-BO-47` | **Ningún control de seguridad puede quedar detrás de un *flag***: MFA, lista blanca de IP, doble autorización, aislamiento de tenant y comprobación de permisos están fuera del alcance del motor, verificado por test de arquitectura (`CA-BO-096`) |

---

## 8. Casos límite y errores

| Situación | Comportamiento |
|-----------|----------------|
| `slug` repetido con un tenant borrado lógicamente | Se admite si el anterior está `deleted_at`; la unicidad es parcial. **Pero un `slug` reutilizado hereda el historial de DNS y de caché del anterior** — se avisa en la respuesta de vista previa y se audita |
| Aprobar una doble autorización caducada | `409`. La caducidad no se «revive»: se vuelve a solicitar |
| Aprobar dos veces la misma solicitud | La segunda: `409`. Índice único parcial sobre `(action, payload_fingerprint)` pendiente |
| Contratar un módulo ya contratado | Idempotente: no reescribe `enabled_at`, no emite evento, no audita. Un reintento no es un cambio |
| Descontratar un módulo del que dependen otros, sin confirmar | `409` con la lista de arrastrados en `params` |
| Activación masiva sobre un tenant suspendido | Se contrata igual. Suspensión y contratación son ejes distintos (`ADR-045 §2`), y el aviso al centro se emite cuando recupere el acceso |
| Un `platform_admin` pierde su segundo factor | Lo restablece **otro** `superadministrador`, auditado. **Nunca autoservicio**, y **nunca por correo**: sería una vía de recuperación que evita el segundo factor de la cuenta más peligrosa del producto |
| El último `superadministrador` intenta darse de baja | `409` (`RN-BO-11`) |
| Se contrata un módulo cuya dependencia está `retired_at` | `422`. El catálogo nunca borra, pero un módulo retirado no es contratable |
| El *host* del backoffice recibe una cookie de sesión de tenant | No hay *endpoint* que la acepte; `401`, y el intento se audita |
| Suspensión mientras hay jobs del tenant en cola | Los jobs siguen y terminan. Suspender bloquea el **acceso**, no la maquinaria (`RN-BO-16`) |
| Se consulta un *flag* cuya clave no está en el catálogo | **Falso**, sin excepción y sin error. Un `flag('lo_que_sea')` que devolviera verdadero por no encontrarse sería la peor forma posible de fallar |
| Un despliegue **retira** un *flag* que aún tiene reglas | El *flag* queda `retired_at`, evalúa falso y sus reglas se conservan como prueba de lo que estuvo activo. **No se borran**: son el registro de a quién se expuso qué |
| Se baja un porcentaje del 40 % al 10 % | Los centros expuestos son un **subconjunto** de los anteriores; ninguno entra al bajar (`RN-BO-38`) |
| Regla de rol sobre un centro cuyo administrador creó un rol propio con ese mismo código | Lo ve, y es correcto: la regla habla de códigos de rol, no de las filas sembradas en 1.1 (`RN-BO-40`) |
| Un centro deja de ser *early adopter* teniendo además regla nominal de tenant | Sigue expuesto: lo nominal gana (`RN-BO-37`). Retirar la cohorte **no** es una forma de excluir a un centro concreto; para eso está la regla de tenant con `enabled = false` |
| Se contrata un módulo cuyos *flags* ya tenían reglas escritas | Las reglas ya estaban ahí y pasan a tener efecto en ese centro. No es un error: escribir la regla antes de contratar el módulo es la secuencia normal de un despliegue progresivo |
| Se designa *early adopter* a un tenant `suspendido` o `en_baja` | Se admite. Cohorte y estado son ejes distintos, igual que contratación y suspensión (`ADR-045 §2`); no verá nada mientras no recupere el acceso |

---

## 9. Interacción con otros módulos

**Eventos que consume**: ninguno.

**Eventos que provoca, emitidos por `REQ-CORE` y no por este módulo** (`RMOD-010`, `ADR-045 §4.8`):

| Evento | Cuándo | Consumidor previsto |
|--------|--------|---------------------|
| `ModuleContracted` | Al escribir `enabled = true` | 1.6: ninguno (el aviso se deriva de `enabled_at`). **1.19** (`REQ-COM-003`): *listener* que crea la notificación in-app para los usuarios con `modulo.leer` |
| `ModuleDecontracted` | Al escribir `enabled = false` | Ídem |

**Eventos propios de `REQ-BO`** (`TenantSuspended`, `TenantReactivated`, `TenantMarkedForClosure`): se emiten desde el módulo, y en 1.6 **no tienen ningún consumidor**. Se declaran porque `REQ-BKP` (1.26) y `REQ-COM` (1.19) los necesitarán, y porque emitirlos ahora cuesta una clase y añadirlos después obliga a tocar el camino de escritura otra vez.

**`INV-007` en la frontera de los *feature flags*: el backoffice escribe las reglas, pero no las evalúa.** La evaluación (`FeatureFlagEvaluator`) ocurre **dentro** de la petición de un centro, la consume cualquier módulo del producto y por tanto **pertenece a `REQ-CORE`**, junto a `ModuleAvailability`, no a `REQ-BO`. El backoffice sólo escribe `feature_flags`, `feature_flag_rules` y `tenants.early_adopter_since`; nunca expone una función de evaluación al producto. Es el mismo reparto que `ADR-045 §4.8` impuso a los eventos de módulo —los emite `REQ-CORE`, no el backoffice— y por el mismo motivo: el consumidor no debe conocer al escritor.

Dos consecuencias concretas de ese reparto, que hay que verificar antes de implementar y no después:

1. **El filtro por rol necesita los códigos de rol del usuario autenticado**, que son dato de `REQ-PERM`. El evaluador los obtiene por la **interfaz pública** que `REQ-PERM` expone, nunca consultando `roles` o `role_user` por su cuenta (`INV-007`, y el error característico de «consultar directamente tablas de otro módulo»). **Si esa interfaz pública no existe hoy en `REQ-PERM`, añadirla es parte de `1.6e` y debe declararse como ampliación de la superficie pública de `REQ-PERM`, no colarse como un `use` más.** Es lo primero que el implementador tiene que comprobar contra el código.
2. **El evaluador corre en el camino de petición de todos los centros.** Es el único componente de los cinco sub-pasos del que eso es cierto, y por eso su caché no es opcional (`operacion.md §4.3`) y por eso `1.6e` va el último (§12.2).

**`INV-007` en la frontera con `REQ-CORE`**: `REQ-BO` **no escribe `module_subscriptions` directamente**. Llama a un servicio de aplicación con interfaz pública que posee `REQ-CORE` —el mismo que resuelve dependencias, invalida caché y emite los eventos— exactamente como `REQ-CORE` expuso `BulkUserImporter` para que 1.24 no lo reimplemente. Sin eso, la regla de dependencias tendría dos implementaciones y el evento se emitiría desde el sitio equivocado.

---

## 10. Comportamiento con el módulo desactivado

**`REQ-BO` no es un módulo activable.** No tiene fila en `modules`, no tiene `module_code` contratable y no se puede desactivar: es la aplicación desde la que se activan los demás. Ninguna de sus rutas lleva el *middleware* `module-enabled`.

`RMOD-008`/`RMOD-009` no aplican a sus *endpoints*. Lo que sí hace es **escribir** el dato del que esos dos requisitos dependen.

---

## 11. Datos personales y protección de datos

- **`platform_admins` contiene datos personales de empleados del proveedor**, no de menores ni de personal de los centros. Base legal: relación laboral o de servicio. Su tratamiento se documenta en `PRIVACY.md` como tratamiento propio del proveedor.
- **`admin_action_logs` puede contener referencias a datos de un centro** (qué tenant, qué módulo, qué motivo). La política de redacción de `ADR-035` aplica igual (`RN-BO-32`).
- **El backoffice no expone datos personales de alumnos ni familias** (`RN-BO-33`). Es una restricción funcional, no sólo de permisos: los *endpoints* de este módulo no devuelven personas de un tenant, en ningún caso.
- **La retención de `admin_action_logs` es propia** y distinta de la de `audit_logs` (`REQ-BO-007`, `ADR-036`). Su valor concreto es `OPEN-BO-06`.
- **El motor de *feature flags* no trata datos personales.** Sus dos tablas guardan claves, códigos de módulo, códigos de rol, porcentajes y referencias a centros (`datos.md §13`). El reparto por porcentaje se calcula sobre el `public_id` del **tenant** o del **usuario**, y en el segundo caso conviene ser preciso: es un identificador opaco usado como entrada de un hash para decidir si se muestra una pantalla — no se almacena ninguna asignación, no se perfila a nadie y el resultado no se conserva. El filtro por rol lee el rol del usuario **dentro de su propia petición** y tampoco lo guarda.

---

## 12. División del paso · **decisión confirmada por el usuario el 2026-09-08**

> **Ya no es una recomendación.** El usuario confirmó el 2026-09-08 la división en **cuatro pasos** que esta sección proponía, tal cual estaba descrita, y decidió además incluir los *feature flags* de `REQ-BO-005` (puntos 1-2), que §12.3 había dejado sin recomendar en ninguna dirección. Eso añade un **quinto** sub-paso, `1.6e`, cuya ubicación y justificación son §12.3.
>
> Lo que sigue conserva íntegro el razonamiento original —los cortes de la tabla de §12.2 no han cambiado ni una fila— y añade lo que trae la decisión. **`PLAN-IMPLEMENTACION.md` lo actualiza la sesión orquestadora, no esta especificación.**

### 12.1 Por qué se divide

`1.6` tal como está en el plan tiene, sólo en lo que **sí** entra: **once tablas nuevas** —nueve, más las dos del motor de *flags* que trae la decisión del 2026-09-08—, un segundo sujeto de autenticación con su propio MFA, una segunda superficie HTTP, un mecanismo genérico de doble autorización, la migración de privilegios de `ADR-045 §4.4`, los tres derivados de `ADR-045 §11`, tres issues que cerrar y —si `OPEN-BO-01` sale por la Opción B— una segunda aplicación desplegable. Es varias veces el tamaño de `1.5`, que ya se dividió.

Y hay un argumento mejor que el tamaño: **el orden está forzado**. `REQ-BO-002` no se puede implementar antes que `REQ-BO-007`, porque escribir `enabled` desde el backoffice exige que exista alguien autenticado como Super Administrador. Cuando la secuencia ya es obligatoria, dividir no añade riesgo, sólo puntos de corte limpios.

### 12.2 La división, con los cinco sub-pasos

| Paso | Alcance | Por qué corta ahí |
|------|---------|-------------------|
| **1.6** · *Identidad, autorización y auditoría de plataforma* | `REQ-BO-007` completo: `platform_admins` y sus roles, MFA propio sin excepción, lista blanca de IP, sesión corta y reautenticación, `admin_action_logs`, doble autorización genérica. Cierra los puntos 2-3 del issue #6 | Es el chasis. Sin él, ninguno de los otros seis sub-requisitos tiene sujeto que autorizar ni sitio donde auditarse. Termina con algo verificable de punta a punta: un administrador de plataforma que entra, con MFA, desde una IP permitida, y cuyo acceso queda registrado |
| **1.6b** · *Ciclo de vida de tenants* | `REQ-BO-001` completo, sobre el chasis de 1.6. Cierra los issues #7 y #27 | Es la primera operación destructiva real y la primera consumidora de la doble autorización. Separarla permite que el chasis se revise en seguridad **antes** de que exista algo peligroso que hacer con él |
| **1.6c** · *Matriz de módulos* (`ADR-045`) | `REQ-BO-002` completo, la migración de privilegios de `ADR-045 §4.4` y los **tres derivados** de `ADR-045 §11` | Es el paso que `ADR-045` describe punto por punto, y el único cuyo diseño ya está cerrado antes de empezar. Toca `REQ-CORE` (servicio de contratación, eventos, descriptor) más que a `REQ-BO` |
| **1.6d** · *Salud y métricas* | `REQ-BO-004` reducido y `REQ-BO-006` reducido (§5.9, §5.10) | Es el único bloque **enteramente de lectura**. Puede posponerse sin bloquear nada, y su valor crece a medida que existan más módulos que observar |
| **1.6e** · *Motor de* feature flags | `REQ-BO-005` puntos 1-2 completos (§5.11): catálogo declarado en código, `feature_flags` y `feature_flag_rules`, `tenants.early_adopter_since`, evaluador en `REQ-CORE`, caché versionada e interruptor de emergencia | §12.3 |

### 12.3 Dónde encaja el motor de *feature flags*, y por qué ahí

La sección anterior decía que los *flags* «cabrían en `1.6d` o en un paso propio» y no lo recomendaba ni lo desaconsejaba, porque dependía de una decisión que entonces no estaba tomada. **Ahora sí lo está, así que aquí va la decisión con su argumento, al mismo nivel que las otras cuatro: paso propio, `1.6e`, el último de los cinco.**

**Qué necesita, y qué no.** Lo primero es mirar las dependencias, no el tamaño:

| ¿Depende de…? | | Por qué |
|---|---|---|
| `1.6` (chasis) | **Sí, y fuerte** | Una sola escritura de una regla global expone —o apaga— una funcionalidad en los doscientos centros a la vez. Eso exige un sujeto autenticado con MFA, una capacidad que se le compruebe, un motivo obligatorio y una entrada en `admin_action_logs`: **las cuatro cosas las construye `1.6` y ninguna existe antes**. Un motor de *flags* sin actor identificado es un conmutador anónimo sobre todo el parque |
| `1.6b` (ciclo de vida) | **No** | Sólo necesita que `tenants` exista, y existe desde 1.1. La designación de *early adopter* añade una columna a esa tabla, pero no toca ni una transición de estado |
| `1.6c` (matriz de módulos) | **No es dependencia, pero sí orden correcto** | Ver abajo |
| `1.6d` (salud y métricas) | **No**, en ninguna dirección | `1.6d` es sólo lectura y no comparte ni una tabla con esto |

**Por qué después de `1.6c` aunque no dependa de él.** `1.6e` repite dos mecanismos que `1.6c` construye y deja probados: (1) el patrón «se declara en el descriptor, lo materializa `platform:sync-registry`, y una declaración inválida **aborta el despliegue**», que `ADR-045 §4.5`/`§4.9` fija para `depends_on` y que aquí se extiende a las claves de *flag*; y (2) la invalidación de caché por tenant desde fuera del contexto de tenant, que `ADR-045 §8.3` marca como de obligado cumplimiento y que es el error más fácil de cometer de todo el paso. Construir los *flags* antes obligaría a resolver los dos patrones en `1.6e` y a que `1.6c` heredara una forma que su propio ADR no describe — **invirtiendo el orden que `ADR-045` fijó**. El orden correcto es que el paso con el diseño ya cerrado siente el patrón y el paso nuevo lo siga.

**Por qué paso propio y no dentro de `1.6d`.** Dos tablas nuevas, una columna en `tenants`, una extensión del comando de sincronización, ocho *endpoints*, un mecanismo de caché versionada y un evaluador: es de un tamaño comparable a `1.6b`, no a un añadido. Pero el argumento decisivo no es el tamaño, es el **perfil de riesgo**, y es el opuesto al de `1.6d`:

> `1.6d` es el único sub-paso que **no puede romper nada**: sólo lee, y sólo desde el backoffice. `1.6e` es el único que **añade código al camino de petición de todos los centros** — el evaluador corre en cada petición de cada colegio (§9). Meter en el mismo paso lo único que es inofensivo y lo único que puede degradar el producto entero es exactamente la mezcla que hace que una revisión de rendimiento no encuentre a qué prestar atención.

**Y por qué el último de los cinco.** Es el único sub-paso que adelanta un requisito de **fase 2** a la fase 1. Un paso que adelanta trabajo va donde se pueda posponer sin dejar nada a medias, y ese sitio es el final. Hay además una razón incómoda que conviene escribir: **en fase 1 no hay todavía ninguna funcionalidad que merezca ir detrás de un *flag***. Su primer consumidor real llega más tarde, cuando existan módulos que desplegar progresivamente. Eso no lo invalida —`RARQ-DEP-010` quiere el mecanismo listo **antes** de necesitarlo, no después—, pero sí determina su prioridad frente a los otros cuatro, que sostienen operaciones que hacen falta el primer día.

### 12.4 La alternativa de tres pasos

Si cinco parecen demasiados, la fusión que menos duele sigue siendo **1.6 + 1.6b** (chasis y ciclo de vida en el mismo paso), conservando `1.6c`, `1.6d` y `1.6e` separados. Lo que **no** recomiendo fusionar es `1.6c`: es el paso con especificación cerrada, con la migración de privilegios y con los tres derivados de `ADR-045`, y mezclarlo con la construcción de la identidad de plataforma es la mejor forma de que uno de los tres derivados se pierda por el camino — que es exactamente el fallo que `ADR-045 §11` intenta evitar al declararlos.

`1.6d` y `1.6e` son mutuamente independientes y su orden entre sí puede intercambiarse sin coste; lo que **no** puede intercambiarse es que los dos vayan después de `1.6c`.

### 12.5 La interfaz gráfica va después de `1.7` y `1.9`, y hay precedente

**Ninguno de los pasos anteriores entrega pantallas del backoffice.** El *design system* es `1.7`, el *layout* es `1.8` y TanStack Table es `1.9`, **los tres posteriores a 1.6**. La matriz `tenant × módulo` de `REQ-BO-002` es una tabla de dos dimensiones con vista previa de impacto: exactamente el tipo de pantalla que `ADR-044 §6` sacó de `1.5` y llevó a `1.5b`, *«construir la matriz de permisos —la tabla más compleja del producto— antes del sistema de diseño y de TanStack Table garantiza rehacerla»*.

El mismo argumento aplica aquí sin cambiar una palabra, y `INV-006` lo respalda: la API existe antes que la interfaz.

**Consecuencia que hay que aceptar explícitamente**, igual que se aceptó en `OPEN-CORE-02`: al cerrar estos pasos, `REQ-BO` no cumple la definición de terminado de `CLAUDE.md §10`, porque no tiene interfaz accesible ni manual de usuario con capturas. Y aquí duele más que en `REQ-CORE`, porque el operador de plataforma **es** el usuario: sin pantallas, suspender un centro se hace con `curl`. Ver `OPEN-BO-08`.

---

## 13. Criterios de aceptación

Formato `Dado / Cuando / Entonces`, verificables, con el ID de requisito que cubren (`INV-015`).

### 13.1 Identidad y acceso al backoffice (`REQ-BO-007`)

- **`CA-BO-001`** · *Dado* un usuario con el rol máximo de su centro y sesión válida en su tenant, *cuando* llama a cualquier *endpoint* del dominio del backoffice con su cookie, *entonces* recibe `401` y **ningún** dato — la cookie es *host-only* y el *guard* es otro (§5.1, cuarto criterio de §5.51).
- **`CA-BO-002`** · *Dado* ese mismo intento, *cuando* se consulta `admin_action_logs`, *entonces* existe una entrada con IP, `user_agent` y `request_id`.
- **`CA-BO-003`** · *Dado* un `platform_admin` válido, *cuando* accede desde una IP no contenida en ninguna entrada activa de la lista blanca, *entonces* `403` **antes** de que se comprueben sus credenciales, y queda auditado (`RN-BO-06`).
- **`CA-BO-004`** · *Dado* una lista blanca **vacía**, *cuando* cualquiera intenta acceder, *entonces* se deniega. Nunca se interpreta como «sin restricción» (`RN-BO-07`).
- **`CA-BO-005`** · *Dado* un `platform_admin` sin segundo factor confirmado, *cuando* llama a cualquier *endpoint* distinto de los de alta de factor, *entonces* `403` — sin período de gracia y sin exención posible (`RN-BO-05`).
- **`CA-BO-006`** · *Dado* el mecanismo de excepciones temporales de MFA de 1.3b, *cuando* se busca su equivalente para plataforma, *entonces* **no existe ninguna ruta, tabla ni columna** que permita eximir a un administrador de plataforma.
- **`CA-BO-007`** · *Dado* una sesión de plataforma que no ha reautenticado dentro de la ventana, *cuando* invoca una operación sensible, *entonces* `403` con `urn:pge:error:reauthentication-required`, distinguible de `forbidden` sin analizar texto (§5.2).
- **`CA-BO-008`** · *Dado* un `platform_admin` con rol `soporte`, *cuando* intenta cualquier escritura, *entonces* `403` (`REQ-BO-007`, «solo lectura y diagnóstico»).
- **`CA-BO-009`** · *Dado* un `platform_admin` con rol `operaciones`, *cuando* intenta eliminar un tenant, *entonces* `403`: la eliminación es de `superadministrador`.
- **`CA-BO-010`** · *Dado* el único `superadministrador` vivo y activo, *cuando* intenta suspenderse o retirarse el rol, *entonces* `409` (`RN-BO-10`, `RN-BO-11`).
- **`CA-BO-011`** · *Dado* cualquier ruta del backoffice, *cuando* se inspecciona su pila de *middleware*, *entonces* **no incluye `ResolveTenant`** y ninguna ruta de tenant incluye el *guard* de plataforma — verificado por test de arquitectura, no por revisión (§3.3).
- **`CA-BO-012`** · *Dado* un `platform_admin` que perdió su segundo factor, *cuando* pide restablecerlo, *entonces* sólo puede hacerlo otro `superadministrador`, queda auditado, y **no existe ninguna vía de autoservicio ni por correo**.

### 13.2 Auditoría de plataforma (`REQ-BO-007`, issues #6 y #27)

- **`CA-BO-020`** · *Dado* cualquier operación de escritura del backoffice, *cuando* termina con éxito, *entonces* existe una entrada en `admin_action_logs` con actor, acción, sujeto, motivo, `affected_tenant_id` cuando proceda, IP, `user_agent` y `request_id` (`INV-003`, `INV-013`).
- **`CA-BO-021`** · *Dado* una entrada de `admin_action_logs`, *cuando* se intenta actualizarla o borrarla por cualquier rol de base de datos, *entonces* la operación es rechazada **por el motor** (`RN-BO-29`).
- **`CA-BO-022`** · *Dado* un centro, *cuando* consulta desde su propia aplicación las acciones de plataforma que le afectan, *entonces* recibe **sólo** las entradas con su `affected_tenant_id` y ninguna otra, ni siquiera las de alcance global (`RN-BO-31`, `INV-001`).
- **`CA-BO-023`** · *Dado* la suspensión de un tenant, *cuando* se consulta `audit_logs` de ese tenant, *entonces* **no** aparece: la auditoría de plataforma no se mezcla con la del centro (`RN-BO-30`, `ADR-036`).
- **`CA-BO-024`** · *Dado* el ciclo de vida de un `Tenant` (alta, suspensión, reactivación, cambio de `slug`, baja, eliminación), *cuando* se ejecuta cualquiera de esas operaciones, *entonces* queda registrada — **cerrando el issue [#27](https://github.com/pirexia/plataforma-educativa/issues/27)**.
- **`CA-BO-025`** · *Dado* `runAsPlatform()` invocado desde código de módulo sin propósito declarado válido, *entonces* lanza excepción y no ejecuta el *callback* — puntos 2-3 del issue [#6](https://github.com/pirexia/plataforma-educativa/issues/6) (§6.2, sujeto a `OPEN-BO-03`).
- **`CA-BO-026`** · *Dado* el mantenimiento por consola que usa `runAsPlatform()` (`RunsPerTenant`, `PurgeExpiredIdempotencyKeys`), *cuando* se ejecuta sobre N tenants, *entonces* **no** escribe N entradas en `admin_action_logs`: se audita la operación, no la primitiva (§6.2).

### 13.3 Módulos — los tres derivados de `ADR-045 §11` y `REQ-BO-002`

- **`CA-BO-030`** · *Dado* la conexión del centro (`plataforma_app`), *cuando* intenta un `UPDATE` de `module_subscriptions.enabled`, *entonces* **el motor lo rechaza** por falta de privilegio, no la aplicación (`ADR-045 §4.4`; refuerza `CA-CORE-061`).
- **`CA-BO-031`** · *Dado* esa misma conexión, *cuando* intenta un `INSERT` en `module_subscriptions`, *entonces* el motor lo rechaza. Y el mismo `UPDATE` sobre `settings`, `updated_at` y `updated_by` **sí** funciona: la lista de columnas concedidas está completa.
- **`CA-BO-032`** · *(derivado 1, `ADR-045 §8.3`)* *Dado* un módulo contratado desde el backoffice, *cuando* el centro llama de inmediato a un *endpoint* de ese módulo, *entonces* responde `200` **sin esperar** a que expire la caché de 300 s.
- **`CA-BO-033`** · *(derivado 1)* *Dado* una activación masiva sobre varios centros ejecutada **fuera del contexto de tenant**, *cuando* termina, *entonces* la clave invalidada de cada centro es la de **su** prefijo `t{tenant_id}:` y ninguna otra — y un centro no afectado conserva su caché intacta.
- **`CA-BO-034`** · *(derivado 2, `ADR-045 §4.5`/`§4.9`)* *Dado* un módulo que declara en `depends_on` un código que no existe en el catálogo, *cuando* se ejecuta `platform:sync-registry`, *entonces* **aborta con error y no escribe nada**.
- **`CA-BO-035`** · *(derivado 2)* *Dado* un grafo de `depends_on` con un ciclo, *cuando* se ejecuta `platform:sync-registry`, *entonces* aborta con error nombrando el ciclo.
- **`CA-BO-036`** · *(derivado 2)* *Dado* el descriptor de `core` y de `auth`, *cuando* se lee `essential`, *entonces* es `true`, y **`ALWAYS_ENABLED` ha desaparecido** de `EloquentModuleAvailability`: no quedan dos listas que puedan divergir.
- **`CA-BO-037`** · *(derivado 3, `ADR-045 §4.8`)* *Dado* la contratación de un módulo, *cuando* termina, *entonces* se ha emitido **`ModuleContracted`** con `tenant_id`, `module_code` y el actor de plataforma — **y lo emite `REQ-CORE`**, no el backoffice: el mismo evento se emite cuando la escritura viene de la activación masiva o de un comando de consola.
- **`CA-BO-038`** · *(derivado 3)* *Dado* la descontratación de un módulo, *entonces* se emite `ModuleDecontracted`, **aunque el módulo quede apagado** — es la comprobación de que el evento pertenece a `REQ-CORE` y no al módulo afectado (`RMOD-010`).
- **`CA-BO-039`** · *Dado* un módulo `M` que depende de `N`, *cuando* se contrata `M` en un centro sin `N`, *entonces* la vista previa lista `N` y la ejecución contrata ambos en la misma transacción (`RMOD-006`, `RN-BO-22`).
- **`CA-BO-040`** · *Dado* un módulo `N` del que depende `M`, ambos contratados, *cuando* se descontrata `N` sin confirmación explícita, *entonces* `409` con la lista de arrastrados en `params`.
- **`CA-BO-041`** · *Dado* un módulo esencial, *cuando* se intenta descontratar por cualquier vía, *entonces* `422` y la celda de la matriz aparece bloqueada (`RN-BO-23`, `ADR-045 §4.7`).
- **`CA-BO-042`** · *Dado* una activación masiva sobre 3 centros, *cuando* termina, *entonces* se han emitido **3** eventos, uno por centro, y **ninguno** agregado (`RN-BO-27`).
- **`CA-BO-043`** · *Dado* una activación masiva reintentada con la **misma** `Idempotency-Key`, *entonces* devuelve el resultado anterior sin contratar de nuevo ni emitir eventos duplicados (`INV-011`).
- **`CA-BO-044`** · *Dado* la contratación de un módulo ya contratado, *entonces* es idempotente: no reescribe `enabled_at`, no emite evento y no audita.
- **`CA-BO-045`** · *Dado* un módulo desactivado desde el backoffice, *cuando* se vuelve a contratar meses después, *entonces* los datos históricos están íntegros y accesibles (`RMOD-003`, `RMOD-004`, segundo criterio de §5.51).

### 13.4 Ciclo de vida del tenant (`REQ-BO-001`)

- **`CA-BO-050`** · *Dado* un alta desde el backoffice, *cuando* termina, *entonces* el tenant queda `activo` con sus 16 roles predefinidos, sus concesiones, su configuración y el primer Administrador de Centro invitado — **y la operación se completa en segundos, sin ningún paso de infraestructura** (nota para el implementador de `REQ-BO-005`).
- **`CA-BO-051`** · *Dado* un `slug` ya usado por un tenant vivo, *cuando* se intenta el alta, *entonces* `422`.
- **`CA-BO-052`** · *Dado* un tenant suspendido, *cuando* **cualquiera** de sus usuarios intenta acceder, *entonces* ve el mensaje configurado y **ningún dato**; al reactivarlo, todo vuelve a estar disponible **sin pérdida** (primer criterio de §5.51).
- **`CA-BO-053`** · *Dado* un tenant suspendido sin `suspension_message`, *cuando* un usuario accede, *entonces* recibe el mensaje por defecto **en su idioma**, desde el catálogo de traducción (`INV-009`).
- **`CA-BO-054`** · *(issue #7)* *Dado* un tenant recién suspendido, *cuando* llega una petición **en el mismo segundo**, *entonces* ya recibe `503` — la caché `tenant-resolution:{slug}` se invalidó en la misma operación y no en hasta 60 s (`RN-BO-14`).
- **`CA-BO-055`** · *Dado* un *host* que no corresponde a ningún tenant, *entonces* `404`; *dado* un *host* de un tenant suspendido, *entonces* `503`. Nunca se revela qué centros existen (`RN-BO-15`).
- **`CA-BO-056`** · *Dado* una transición no permitida (por ejemplo `suspendido` → `eliminado`), *entonces* `409` (`RN-BO-12`).
- **`CA-BO-057`** · *Dado* cualquier transición, *cuando* se envía sin motivo o con motivo vacío, *entonces* `422` (`RN-BO-13`).
- **`CA-BO-058`** · *Dado* un tenant `en_baja`, *cuando* vence su período de gracia de 90 días, *entonces* queda marcado como candidato y se avisa, y **no se borra nada automáticamente** (`RN-BO-17`).
- **`CA-BO-059`** · *Dado* un tenant suspendido, *cuando* se inspeccionan sus datos y sus tareas programadas, *entonces* están íntegros y siguen ejecutándose (`RN-BO-16`).
- **`CA-BO-060`** · *Dado* una clonación, *cuando* termina, *entonces* el tenant nuevo tiene configuración, roles, concesiones y suscripciones de módulo, y **cero personas, cero usuarios, cero invitaciones y cero entradas de auditoría** copiadas (`RN-BO-21`).

### 13.5 Doble autorización (`REQ-BO-007`)

- **`CA-BO-061`** · *Dado* un intento de eliminar un tenant con **una sola** cuenta de administrador, *entonces* el sistema lo impide y exige la autorización de un segundo administrador (tercer criterio de §5.51).
- **`CA-BO-062`** · *Dado* una solicitud de doble autorización, *cuando* **el propio solicitante** intenta aprobarla, *entonces* **la base de datos la rechaza** — el test escribe directamente por SQL para comprobar que la garantía no vive en el controlador (`RN-BO-19`).
- **`CA-BO-063`** · *Dado* una solicitud aprobada, *cuando* se ejecuta, *entonces* usa los parámetros **congelados en la solicitud**; si su huella no coincide, se rechaza (`RN-BO-20`).
- **`CA-BO-064`** · *Dado* una solicitud caducada, *cuando* se intenta aprobar, *entonces* `409`.
- **`CA-BO-065`** · *Dado* una eliminación de tenant, *cuando* el nombre escrito no coincide **exactamente**, *entonces* `422` y no se crea ninguna solicitud (`RN-BO-18`).
- **`CA-BO-066`** · *Dado* una desactivación **masiva** de módulos, *entonces* también pasa por doble autorización (`REQ-BO-007`: «desactivar módulos en masa»).
- **`CA-BO-067`** · *Dado* el ciclo completo, *cuando* se consulta `admin_action_logs`, *entonces* hay **tres** entradas distintas: solicitud, aprobación y ejecución (§5.7).

### 13.6 Transversales

- **`CA-BO-070`** · *Dado* cualquier *endpoint* de este módulo, *cuando* se llama sin sesión de plataforma, *entonces* `401`; sin la capacidad requerida, `403`. Ninguno responde con datos (`INV-002`).
- **`CA-BO-071`** · *Dado* cualquier recurso expuesto, *cuando* aparece en una URL o en un cuerpo de respuesta, *entonces* se identifica por `public_id` ULID y **nunca** por la clave interna (`ADR-029`). **Excepción propuesta y no decidida**: la `key` de un *feature flag* en la ruta (`OPEN-BO-11`, §14).
- **`CA-BO-072`** · *Dado* cualquier respuesta de error, *entonces* sigue RFC 9457 con `type` en la forma `urn:pge:error:<slug>` y `request_id` presente (`ADR-038 §6`).
- **`CA-BO-073`** · *Dado* cualquier mensaje visible de este módulo, *entonces* existe en `es-ES`, `en`, `de` y `fr`, y no hay literales en el código (`INV-009`).
- **`CA-BO-074`** · *Dado* cualquier *endpoint* del backoffice, *cuando* se recorre su respuesta, *entonces* **no contiene ningún dato personal de alumnos, familias ni personal de los centros** (`RN-BO-33`, `REQ-BO-007`).
- **`CA-BO-075`** · *Dado* la suite completa, *cuando* se ejecuta, *entonces* ninguna tabla nueva de este módulo aparece en el test de esquema #8 de `ADR-033 §10` como incumplimiento: o tiene `tenant_id` con RLS, o está declarada en el registro de tablas compartidas de `config/tenancy.php`.

### 13.7 *Feature flags* (`REQ-BO-005` puntos 1-2 · sub-paso `1.6e`)

- **`CA-BO-080`** · *Dado* un *flag* del catálogo **sin ninguna regla**, *cuando* se evalúa en cualquier centro y para cualquier usuario, *entonces* es **falso** (`RN-BO-35`).
- **`CA-BO-081`** · *Dado* un *flag* con una regla `global` activa y una regla de tenant con `enabled = false` para el centro `A`, *entonces* `A` **no** lo ve y el resto de centros **sí**: lo nominal gana a lo estadístico (`RN-BO-37`).
- **`CA-BO-082`** · *Dado* un *flag* en `forced_off`, *cuando* se evalúa en un centro que tiene regla nominal de tenant a `true` **y** es *early adopter*, *entonces* sigue siendo **falso**: el interruptor está por encima de todas las reglas (`RN-BO-37`, `RNF-MANT-005`).
- **`CA-BO-083`** · *Dado* un reparto al 10 %, *cuando* se evalúa el mismo centro dos veces en procesos distintos, *entonces* da el mismo resultado; y *cuando* el porcentaje sube del 10 % al 20 %, *entonces* **ningún** centro ya expuesto deja de estarlo (`RN-BO-38`).
- **`CA-BO-084`** · *Dado* dos *flags* distintos, ambos al 10 %, *entonces* el conjunto de centros expuestos **no es el mismo** (`RN-BO-39`).
- **`CA-BO-085`** · *Dado* un *flag* expuesto en un centro y con una regla de rol sobre el código `docente`, *entonces* un usuario con ese rol lo ve y un `administrador_centro` no; **y un usuario con un rol personalizado creado por el centro cuyo código es `docente` también lo ve** (`RN-BO-40`).
- **`CA-BO-086`** · *Dado* una regla de rol con un código que **no existe** en un centro, *entonces* la escritura de la regla se acepta sin error y **nadie de ese centro** ve el *flag* (`RN-BO-40`).
- **`CA-BO-087`** · *Dado* un *flag* con reglas de rol, *cuando* se evalúa desde un trabajo en cola o un comando de consola —sin usuario—, *entonces* es **falso** (`RN-BO-41`).
- **`CA-BO-088`** · *Dado* un *flag* con reglas ya cacheadas en varios centros, *cuando* se cambia su regla `global`, *entonces* **todos** los centros ven el valor nuevo de inmediato, **sin** que la operación haya tenido que recorrer los prefijos de caché de los N centros uno a uno (`RN-BO-42`).
- **`CA-BO-089`** · *Dado* dos módulos que declaran en su descriptor la **misma** clave de *flag*, o una clave con formato inválido, *cuando* se ejecuta `platform:sync-registry`, *entonces* **aborta y no escribe nada** — mismo comportamiento que `CA-BO-034` para `depends_on` (`RN-BO-34`).
- **`CA-BO-090`** · *Dado* la API de plataforma completa, *cuando* se busca un camino para **crear** un *flag*, *entonces* **no existe ninguno**: el catálogo sólo se materializa desde el descriptor (`RN-BO-34`).
- **`CA-BO-091`** · *Dado* un *flag* con `retired_at`, *entonces* evalúa **falso** y toda escritura sobre sus reglas devuelve `422`; sus reglas anteriores **siguen consultables** (`RN-BO-44`).
- **`CA-BO-092`** · *Dado* un *flag* al 100 % sobre una funcionalidad de un módulo **no contratado** por el centro, *cuando* el centro llama a esa funcionalidad, *entonces* recibe `urn:pge:error:module-disabled`: **el *flag* no enciende nada** (`RN-BO-45`).
- **`CA-BO-093`** · *Dado* un centro designado *early adopter* con motivo, *entonces* ve todos los *flags* con regla de cohorte activa; *cuando* se le retira la designación, *entonces* deja de ver los que dependían **sólo** de ella y conserva los que tienen además regla nominal o porcentaje que le alcanza (`RN-BO-46`).
- **`CA-BO-094`** · *Dado* un `platform_admin` con rol `soporte`, *cuando* intenta escribir cualquier regla o cambiar el estado de un *flag*, *entonces* `403`; con rol `operaciones`, `200` — «módulos, límites, **flags**» es literal en `REQ-BO-007`.
- **`CA-BO-095`** · *Dado* cualquier escritura de *flags* sin motivo o con motivo vacío, *entonces* `422`; con motivo, *entonces* existe entrada en `admin_action_logs` con `affected_tenant_id` poblado si la regla nombra a un centro y **nulo** si el alcance es global (`RN-BO-43`).
- **`CA-BO-096`** · *Dado* el código completo, *cuando* un test de arquitectura busca invocaciones del evaluador de *flags* desde el *middleware* de MFA, de lista blanca de IP, de autorización, de resolución de tenant o de doble autorización, *entonces* **no encuentra ninguna** (`RN-BO-47`).
- **`CA-BO-097`** · *Dado* la API del **tenant**, *cuando* un usuario consulta sus *flags*, *entonces* recibe **sólo** las claves que evalúan verdadero para él, y **ninguna** de las que están apagadas o en despliegue parcial (`api.md §2.14`).

---

## 14. Preguntas abiertas

**No las resuelvo yo.** Las tres primeras son estructurales y **bloquean el arranque de la implementación**.

**Estado a 2026-09-08**, tras la respuesta del usuario:

| Pregunta | Estado |
|---|---|
| `OPEN-BO-01` · Separación de la aplicación | **Abierta · bloqueante.** En manos de `architect`, con ADR propio en curso |
| `OPEN-BO-02` · Sesión del backoffice | **Abierta · bloqueante.** Ídem, en el mismo ADR |
| `OPEN-BO-03` · Firma de `runAsPlatform()` | **Abierta · bloqueante.** Ídem |
| `OPEN-BO-04` · ADR que cierre `ADR-036` | **Abierta**, no bloqueante |
| `OPEN-BO-05` · Baja sin portabilidad ni purga | **Resuelta 2026-09-08 · riesgo aceptado por el usuario** |
| `OPEN-BO-06` · Retención de `admin_action_logs` | **Abierta**, no bloqueante |
| `OPEN-BO-07` · *Feature flags* | **Resuelta 2026-09-08 · entran en alcance**, en el sub-paso `1.6e` |
| `OPEN-BO-08` · Sin pantallas hasta `1.7`/`1.9` | **Resuelta 2026-09-08 · sólo API** |
| `OPEN-BO-09` · Dos personas para eliminar un tenant | **Resuelta 2026-09-08 · aceptada sin relajar `RN-BO-19`** |
| `OPEN-BO-10` · RLS de `admin_action_logs` | **Abierta**, pendiente de `db-reviewer` y `architect` antes de la primera migración |
| `OPEN-BO-11` · Direccionar un *flag* por su `key` | **Abierta · nueva**, no bloqueante. Surge de la decisión del 2026-09-08 |

**Las tres bloqueantes siguen bloqueando.** Que siete de diez estén resueltas o no bloqueen no autoriza a empezar a implementar (§15).

### `OPEN-BO-01` · ¿Cómo se separa técnicamente la aplicación del backoffice? · **BLOQUEANTE**

§3 evalúa las opciones. `ADR-002` (monolito modular hasta la fase 3) hace que la Opción B —aplicación desplegable independiente— **exija un ADR nuevo**, y `CLAUDE.md §11` no permite cambiar una decisión de ADR sin él. La Opción C queda descartada por incompatibilidad con el requisito.

**Recomiendo que esta pregunta se responda con un ADR propio**, separado de `ADR-045` (que no la toca), decidido por `architect` o por el usuario, **antes** de escribir la primera migración. Arrastra a `OPEN-BO-02` y a cómo se aplica la lista blanca de IP.

### `OPEN-BO-02` · ¿Dónde vive la sesión del backoffice? · **BLOQUEANTE**

`sessions` es tabla de tenant desde 1.2 y un `platform_admin` no tiene tenant. Dos salidas: **(a)** almacén de sesión propio para el *guard* de plataforma; **(b)** `sessions.tenant_id` deja de ser obligatoria.

**(b) debilita una invariante sobre una tabla viva y con sesiones reales**, y una columna de aislamiento anulable es exactamente el tipo de excepción que erosiona `INV-001`. **Recomiendo (a)**, pero el coste real depende de `OPEN-BO-01` y de la nulabilidad efectiva de esa columna, que hay que verificar contra la migración de 1.2 antes de decidir.

### `OPEN-BO-03` · ¿Se acepta cambiar la firma de `runAsPlatform()`? · **BLOQUEANTE de los puntos 2-3 del issue #6**

§6.2 propone que la primitiva reciba un **propósito declarado** (`Mantenimiento` / `Backoffice`) en vez de una comprobación de permiso genérica que sus llamadores de consola no pueden satisfacer. Es una firma de `App\Support\Tenancy`, infraestructura compartida por todo el producto, con tres llamadores hoy y un test de arquitectura de 1.5 apuntándole.

Lo propongo porque la alternativa literal del issue produce auditoría sin valor y comprobaciones imposibles; **no lo decido**, porque toca infraestructura ajena a este módulo.

### `OPEN-BO-04` · ¿Hace falta un ADR que cierre formalmente `ADR-036`?

`ADR-036` dice que su decisión «se sustituye por otra que declare cómo `Tenant` se audita» cuando 1.6 diseñe `admin_action_logs`. Esta especificación lo declara (§6.1), pero una especificación de módulo **no es un ADR** —el mismo argumento con el que `OPEN-CORE-09` acabó siendo `ADR-038`—. Recomiendo un ADR breve; la decisión de escribirlo no es mía.

### `OPEN-BO-05` · Baja y eliminación sin portabilidad ni purga · **RESUELTA 2026-09-08: riesgo aceptado**

> **Decisión del usuario del 2026-09-08: se acepta el riesgo tal como está planteado abajo.** Durante `1.6`/`1.6b`, un centro que se da de baja **no puede exportar sus datos** —`REQ-OPS-004` no existe— y la eliminación es **sólo borrado lógico** —`REQ-PRIV-006` no existe—. **El comportamiento especificado en §5.5 y §7.2 no cambia**: el período de gracia de 90 días existe, la eliminación es `ADR-004` nivel 1, y ni la exportación ni la purga física se adelantan. Queda como deuda declarada contra `REQ-OPS-004` y `REQ-PRIV-006`, no como omisión.

Dos consecuencias encadenadas, que son las que el usuario ha aceptado:

1. El período de gracia de 90 días existe, pero **`REQ-OPS-004` no**, así que durante esos 90 días el centro no tiene con qué ejercer la portabilidad que el requisito le promete.
2. `eliminado` es borrado lógico y revocación de acceso; la **purga física** es `REQ-PRIV-006`, que no existe.

Es coherente con `ADR-004` y con el alcance de fase 1, y no impide cumplir el criterio de aceptación de §5.51. Pero significa que **un centro que se da de baja en 1.6 no puede llevarse sus datos**, y eso es una promesa del producto sin cumplir (`REQ-OPS-004`: «la resistencia al cambio de plataforma nace del miedo al secuestro de datos»). ¿Se acepta, o `REQ-BO-001` espera a que exista la exportación?

### `OPEN-BO-06` · Retención de `admin_action_logs`

`REQ-BO-007` la exige «independiente» de la del tenant y no fija plazo. `REQ-CORE-005` fija 2 años para `audit_logs`. La auditoría de plataforma documenta el acceso del proveedor a los sistemas de sus clientes y es la prueba en un incidente: **argumentaría por un plazo mayor, no menor**, pero es una decisión de cumplimiento, no técnica. Mientras no se decida, 1.6 **no crea ningún ajuste de retención** (`ADR-034 OPEN-13`: no se adelantan columnas por si acaso) y la purga la implementará `REQ-PRIV-006`.

### `OPEN-BO-07` · ¿Entran los *feature flags* de `REQ-BO-005` (puntos 1-2)? · **RESUELTA 2026-09-08: SÍ**

Eran `REQ-OPS-002`, fase 2, `SHOULD`. La pregunta se planteó porque, a diferencia del resto de `REQ-BO-005`, **no dependen de ningún módulo que falte**: el motor es autocontenido.

> **Decisión del usuario del 2026-09-08: entran en el alcance de este paso**, con ese mismo argumento. Consecuencias, todas ya aplicadas a este documento: pasan de §2.2 a §2.1; se especifican en §5.11; añaden `RN-BO-34` a `RN-BO-47` (§7.5) y `CA-BO-080` a `CA-BO-097` (§13.7); ocupan el sub-paso propio **`1.6e`**, el último de los cinco, con la justificación de dependencias en §12.3. `datos.md §9`, `api.md §2.11`, `permisos.md` y `operacion.md` se actualizan en consecuencia.
>
> **Lo que esta decisión no arrastra**: los puntos 3-4 de `REQ-BO-005` —ventanas de mantenimiento, notas de versión y avisos— **siguen fuera** (§2.2), porque esos sí dependen de `REQ-COM` (1.19), que no existe.

### `OPEN-BO-08` · Sin pantallas, ¿cómo se opera el backoffice hasta `1.7`/`1.9`? · **RESUELTA 2026-09-08: sólo API**

§12.5 argumenta que la interfaz debe ir después del *design system* y de TanStack Table, con el precedente literal de `1.5b`. La diferencia con `REQ-CORE` es que allí el usuario final llegaba en 1.8 de todos modos, y aquí **el operador de plataforma es quien tiene que suspender un centro el día que haga falta**.

> **Decisión del usuario del 2026-09-08: se acepta operar sólo por API** hasta que existan `1.7` (sistema de diseño) y `1.9` (TanStack Table). No se construye una interfaz mínima provisional. **Consecuencia aceptada por escrito**: al cerrar los cinco sub-pasos, `REQ-BO` no cumple la definición de terminado de `CLAUDE.md §10` —no hay interfaz accesible ni manual de usuario con capturas— y suspender un centro se hace con `curl` desde una máquina cuya IP esté en la lista blanca. `operacion.md §5` y `§8` son, hasta entonces, la única documentación operativa del módulo, y por eso su nivel de detalle no es opcional.

### `OPEN-BO-09` · La doble autorización exige dos personas reales · **RESUELTA 2026-09-08: aceptada**

`REQ-BO-007` y `RN-BO-19` obligan a que aprueba ≠ solicita, y lo impone la base de datos. En una operación de una sola persona, **eliminar un tenant es literalmente imposible** hasta que existan dos cuentas de administrador de plataforma distintas.

> **Decisión del usuario del 2026-09-08: aceptada, y `RN-BO-19` no se relaja.** Ni por configuración, ni por entorno, ni «para desarrollo» — una excepción de ese tipo acabaría en producción, que es el motivo por el que `operacion.md §2` tampoco crea una variable de entorno para saltarse la doble autorización. **Consecuencia operativa**: el paso 5 del procedimiento de arranque de `operacion.md §5` —crear un **segundo** `superadministrador`— deja de ser una recomendación y es condición para que el sistema sea operable al completo.

### `OPEN-BO-10` · `admin_action_logs` con `affected_tenant_id` y RLS: ¿encaja en `ADR-033 §7`?

`ADR-033 §7` clasifica `admin_action_logs` como tabla de plataforma «sin `tenant_id`, `REVOKE` completo para `plataforma_app` salvo lo imprescindible». `REQ-BO-007` exige además que sea «consultable por el propio centro en lo que le afecte», lo que obliga a una referencia al tenant afectado y a un camino de lectura para `plataforma_app`.

`datos.md §4` propone resolverlo con `affected_tenant_id` más una política RLS `USING (affected_tenant_id = app.current_tenant_id())` y `GRANT SELECT` acotado — la tabla no *pertenece* a un tenant, pero *referencia* a uno. Encaja con el espíritu de `ADR-033` y con cómo se trata la propia tabla `tenants`, pero **toca el registro de tablas compartidas y el test de esquema #8**, así que quiero que lo bendigan `db-reviewer` y `architect` antes de escribir la migración.

### `OPEN-BO-11` · ¿Se puede direccionar un *feature flag* por su `key` en la URL?

`ADR-029` fija `public_id` ULID «en todo lo que se exponga en URL o API». `api.md §2.11` propone que las rutas de *flag* usen su **`key`** (`/feature-flags/comedor.reserva_v2`), que es lo que el código escribe y lo que un operador reconoce, y `datos.md §11` argumenta que la `key` cumple lo que `ADR-029` persigue: única, estable, inmutable, sin cardinalidad filtrada y sin ser una clave interna. El *flag* lleva su `public_id` de todos modos.

**No lo decido porque es la letra de un ADR vigente** (`CLAUDE.md §11`). Si se rechaza, las rutas pasan a `public_id` y **nada más de esta especificación cambia** — por eso no bloquea. Lo señalo en vez de resolverlo por comodidad, que es exactamente cómo se erosiona una convención de identificadores.

---

## 15. ¿Se aprueba esta especificación?

**Sigue sin estar aprobada.** El 2026-09-08 el usuario resolvió cuatro de las diez preguntas abiertas y esta revisión las ha aplicado; **eso no es la aprobación de la especificación**, y tres bloqueantes siguen en pie.

Lo resuelto el 2026-09-08, y ya incorporado a este documento:

| # | Decisión | Dónde queda |
|---|---|---|
| 1 | **División en cuatro pasos, confirmada** tal como estaba descrita | §12.2, sin cambiar una fila de la tabla original |
| 2 | ***Feature flags* dentro de alcance** (`OPEN-BO-07`) | §2.1, §5.11, §7.5, §12.3 (sub-paso `1.6e`), §13.7 |
| 3 | **Sólo API hasta `1.7`/`1.9`** (`OPEN-BO-08`) | §12.5, §14 |
| 4 | **Baja sin portabilidad ni purga, riesgo aceptado** (`OPEN-BO-05`) | §14, sin tocar §5.5 ni §7.2 |
| 5 | **Dos personas reales para eliminar un tenant** (`OPEN-BO-09`), sin relajar `RN-BO-19` | §14, `operacion.md §5` paso 5 |

Lo que **falta** antes de que `implementer` toque una línea:

1. **Las tres decisiones bloqueantes**, que no las decido yo y que están en curso: `OPEN-BO-01` (separación de la aplicación), `OPEN-BO-02` (sesión del backoffice) y `OPEN-BO-03` (firma de `runAsPlatform()`). **Cuando el ADR que las resuelva exista, `datos.md`, `api.md` y `operacion.md` necesitan una segunda pasada** para aplicarlo: hoy están escritos para ser válidos bajo cualquiera de las dos opciones vivas, y eso deja de ser deseable en cuanto haya una elegida.
2. El visto bueno de `db-reviewer` y `architect` a **`OPEN-BO-10`** antes de escribir la primera migración de `admin_action_logs`.
3. La **aprobación explícita del usuario** a esta especificación completa, con los *feature flags* dentro.

**¿Se aprueba la especificación con estos cambios, o hay algo del motor de *feature flags* de §5.11 que revisar antes?** En particular, tres puntos donde he decidido yo y conviene que se ratifiquen: que la **unidad de reparto la declare el código y no el operador** (§5.11.2), que el **rol filtre y no amplíe** (§5.11.4), y que `1.6e` vaya **el último** de los cinco sub-pasos (§12.3).

Lo que **sí** está cerrado y no espera a nadie es el encargo de `ADR-045 §10`: el documento de requisitos está en 3.2.0 con los trece requisitos reescritos, y `OPEN-CORE-03` queda marcado como resuelto en `docs/modulos/REQ-CORE/funcional.md`.
