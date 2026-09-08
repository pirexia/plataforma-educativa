# REQ-PERM · Operación

> Paso **1.5**. Complementa `SYSADMIN.md`; aquí sólo lo específico de este paso.
>
> La conclusión primero, porque condiciona todo lo demás: **este paso no añade ninguna variable de entorno, ningún servicio externo, ninguna cola y ninguna tarea programada.** Lo que sí añade es un **orden de despliegue de cuatro pasos que no se puede alterar** (§4) y un **comando de migración de datos que es el paso más fácil de olvidar** (§4.3).

---

## 1. Comportamiento con el módulo activo o inactivo

**`REQ-PERM` no es un módulo activable.** No es un *bounded context* (`ADR-044 §4.10`), no tiene fila en `modules`, no tiene `module_code` y no se puede desactivar. Es infraestructura de framework, igual que el aislamiento de tenant: sin autorización no hay plataforma que desactivar.

Sus endpoints de administración pertenecen a `REQ-CORE`, que tampoco es desactivable (`REQ-CORE/operacion.md §1`). **Ninguna ruta de este paso lleva el *middleware* `module-enabled`.**

Lo que sí hace este paso respecto de `RMOD-009` es **consumir** el estado del módulo para hacer inertes los permisos de módulos no utilizables (`funcional.md §9`). Ver §6.2: ahí hay un hallazgo que reportar antes de implementar.

---

## 2. Variables de entorno

**Ninguna nueva.** Y es una consecuencia buscada, no una casualidad:

| Lo que habría necesitado una variable | Por qué no la hay |
|---------------------------------------|--------------------|
| TTL de la caché de permisos resueltos | **No hay caché** (`ADR-044 §4.7`). La memoización es por instancia y muere con la petición |
| Conmutador para «relajar» la validación de ámbitos | **No existe y no debe existir.** Un permiso que se puede desactivar por variable de entorno es un permiso que alguien desactivará en producción para depurar un incidente. La validación va en el `CHECK` del motor, donde ninguna variable llega |
| Lista de ámbitos admitidos | Es un `enum` de PHP y un `CHECK` de PostgreSQL. Ampliarla exige un ADR (`ADR-044 §4.1`), no una variable |
| Modo «denegar todo» / «permitir todo» | `RPERM-011` ya deniega por defecto. Un modo «permitir todo» sería una puerta trasera con nombre de opción de configuración |

**Si en la implementación aparece la tentación de una variable de entorno en este subsistema, es señal de que algo se está decidiendo en el sitio equivocado.**

---

## 3. Servicios externos y degradación

| Servicio | Uso en este paso | Si no responde |
|----------|------------------|----------------|
| **PostgreSQL** | Todo. Dos consultas indexadas por comprobación, memoizadas por petición | La API no sirve. Sin degradación posible ni deseable |
| **Redis** | **Ninguno.** Este paso no cachea permisos (`ADR-044 §4.7`) | **Sin efecto sobre la autorización.** Un Redis caído degrada colas y caché de otros módulos; la resolución de permisos sigue siendo correcta |
| **S3 / MinIO** | Sólo indirectamente: la exportación de auditoría acotada por ámbito escribe su artefacto | Ya cubierto por `REQ-CORE/operacion.md §3` |

**Que Redis no participe es una propiedad de seguridad, no sólo de simplicidad.** El modo de fallo de una caché de permisos mal invalidada es **conceder lo ya revocado**, y es la única dirección de fallo que `INV-002` no admite. Mientras no exista esa caché, un Redis vacío, reiniciado o desalojado por memoria **no puede abrir un permiso**.

---

## 4. Despliegue: cuatro pasos, en este orden

Alterar el orden no produce un fallo evidente; produce un centro donde un administrador no puede administrar roles y nadie sabe por qué. Por eso va enumerado y por eso `SYSADMIN.md` debe recogerlo.

### 4.1 Paso 1 · Migraciones de esquema

Dos migraciones, ambas aditivas, **y con tratamiento deliberadamente distinto** porque su riesgo es distinto (decisión del usuario, 2026-09-04; `funcional.md §18`, `OPEN-PERM-06`):

| Migración | Qué hace | Bloqueo | Detalle |
|-----------|----------|---------|---------|
| `permission_role.scope` → `NOT NULL` + `CHECK` | **Siete sentencias escalonadas**: relleno idempotente, `CHECK NOT VALID`, `VALIDATE`, `SET NOT NULL`, `DROP` del auxiliar, `CHECK` de vocabulario `NOT VALID`, `VALIDATE` | **Ninguno apreciable.** El `VALIDATE` toma `SHARE UPDATE EXCLUSIVE`, que no bloquea lecturas ni escrituras; el `SET NOT NULL` reutiliza el `CHECK` ya validado y no recorre la tabla | `datos.md §2.3` |
| `permissions.applicable_scopes` (`jsonb` anulable) | **Dos sentencias**: `ADD COLUMN` sin defecto y su `CHECK` de forma. **Sin `NOT VALID`/`VALIDATE`** | Instantáneo | `datos.md §3.3` |

**Por qué una lleva siete sentencias y la otra dos**, y conviene que la revisión lo entienda en vez de homogeneizarlo: `permission_role` es tabla **de tenant**, con filas vivas de todos los centros, RLS y escritura concurrente; `permissions` es tabla **de referencia compartida**, sin `tenant_id`, sin RLS, de unas 35 filas y con un único escritor que es un comando de despliegue. Aplicar el patrón cauteloso a la segunda sería ceremonia sin beneficio, y enseñaría a aplicarlo por costumbre en lugar de por análisis — que es lo que lo vuelve inútil el día que de verdad hace falta.

**Compatibilidad con la versión anterior** (`CLAUDE.md §9`): la versión desplegada hoy **siempre escribe `scope = 'todos'`** —`ProvisionTenantDefaults::seedPermissionGrants()` es su único escritor y lo hace de forma literal—, así que el `NOT NULL` no puede romperle nada. La columna nueva le es indiferente porque no la lee.

> **Comprobación obligatoria antes de migrar**, de una línea: buscar todo escritor de `permission_role` en el código desplegado. Si apareciera un segundo escritor que no fija `scope`, **la migración se para y se arregla el escritor primero**. No se supone.

**No hay fase `contract`.** No se elimina ni se renombra nada (`datos.md §2.4`).

### 4.2 Paso 2 · `php artisan platform:sync-registry`

**Paso obligatorio del despliegue, ya documentado en `SYSADMIN.md` desde 0.8.11**, que en 1.5 gana una responsabilidad más: materializar `applicable_scopes` (`ADR-044 §8`).

Debe ejecutarse **después** de la migración (necesita la columna) y **después** de desplegar el código nuevo (lee lo que declaran los `ServiceProvider`).

Qué escribe en este paso:

- Las cuatro filas nuevas de `permissions`: `rol.crear`, `rol.eliminar`, `rol_datos_especiales.actualizar`, `permiso_efectivo.leer`.
- `applicable_scopes` en las **35** filas del catálogo: `['todos','propios']` en `auditoria.leer` y `auditoria.exportar`, `['todos']` en el resto.

**Validación nueva del comando, y falla el despliegue si no pasa**: si un módulo declara en `applicable_scopes` un valor fuera del vocabulario de los seis, el comando **aborta con error** y no escribe nada. Es preferible un despliegue detenido a un catálogo con un ámbito que ningún `CHECK` de `permission_role` aceptará después.

**Si este paso no se ejecuta**: `applicable_scopes` queda `NULL`, que se interpreta como `['todos']`; conceder `auditoria.leer` con ámbito `propios` responde `422`; y los cuatro permisos nuevos no existen, con lo que sus endpoints deniegan. **Falla en cerrado**, que es lo correcto, pero hay que saber que hace falta correrlo (`ADR-034 §6`).

### 4.3 Paso 3 · `php artisan perm:grant-role-administration` · **el paso que se olvida**

`ProvisionTenantDefaults` sólo se ejecuta **en el alta de un tenant**. Los centros que ya existen no reciben las concesiones nuevas por el simple hecho de desplegar: su `administrador_centro` seguiría **sin** `rol.crear`, `rol.eliminar`, `rol_datos_especiales.actualizar` ni `permiso_efectivo.leer`, y por tanto **sin poder usar nada de lo que este paso entrega**.

Hace falta, por tanto, un comando de migración de datos, exactamente como el `auth:grant-lockout-permissions` de 1.2 (`REQ-AUTH/operacion.md §11`, que ya se identificó entonces como «el paso que más fácil se olvidaba del despliegue»).

| Aspecto | Decisión |
|---------|----------|
| Alcance | Concede a `administrador_centro`, en **cada tenant vivo**, los **cuatro** permisos de `permisos.md §5` —`rol.crear`, `rol.eliminar`, `rol_datos_especiales.actualizar`, `permiso_efectivo.leer`— con `effect = 'allow'` y `scope = 'todos'`. Los mismos que `ProvisionTenantDefaults::ADMIN_CENTRO_PERMISSIONS` pasa a sembrar en los tenants nuevos: **una sola lista, en un solo sitio**, consumida por los dos caminos |
| `rol_datos_especiales.actualizar` | Se concede **también aquí**, y no concede acceso a ningún dato: `administrador_centro` sigue sin `special_data_access`, así que puede **delegar** el permiso pero no ejercerlo (`permisos.md §5`, `§7.4`) |
| Idempotencia | **Obligatoria.** Si la concesión ya existe, no hace nada y no escribe fila de auditoría |
| Ejecución por tenant | Con `RunsPerTenant`, fijando el contexto de tenant en cada iteración. **Nunca en una pasada global sin contexto** (`REQ-CORE/operacion.md §4`) |
| Escritura | Por el modelo (`PermissionRole::create()`), **nunca** por `attach()`/`sync()` — `RN-PERM-19`. Así queda auditada |
| Actor de auditoría | `AuditActor::actingAs('console')`, como la siembra |
| Requisito previo | El paso 4.2. Sin él, la FK `permission_code → permissions.code` falla |
| Reversión | No la tiene, y no la necesita: revocar una concesión es una operación normal de la API, auditada |

**Si este paso no se ejecuta**: los centros existentes no ven ningún error; simplemente reciben `403` al intentar crear un rol, y el síntoma es indistinguible de «no tengo permiso», que es exactamente lo que hace este fallo difícil de diagnosticar. Por eso está aquí y por eso debe entrar en `SYSADMIN.md`.

### 4.4 Paso 4 · Verificación posterior al despliegue

Cuatro comprobaciones, todas de una línea:

| # | Comprobación | Resultado esperado |
|---|--------------|--------------------|
| 1 | `SELECT count(*) FROM permission_role WHERE scope IS NULL OR scope NOT IN ('todos','propios','departamento','grupo','clase','unidad_familiar')` | `0` |
| 2 | `SELECT count(*) FROM permissions WHERE code IN ('rol.crear','rol.eliminar','rol_datos_especiales.actualizar','permiso_efectivo.leer') AND retired_at IS NULL` | `4` |
| 3 | `SELECT applicable_scopes FROM permissions WHERE code = 'auditoria.leer'` | `["todos", "propios"]` |
| 4 | Por tenant: `administrador_centro` tiene las concesiones nuevas | Una fila por permiso concedido |

### 4.5 Reversión

Probada, como exige `CLAUDE.md §9`:

1. Desplegar la versión anterior del código.
2. Ejecutar `platform:sync-registry` con esa versión: los cuatro permisos nuevos quedan marcados `retired_at` (**nunca se borran**, `ADR-034 §2`), así que la FK de las concesiones ya escritas sigue siendo válida.
3. Ejecutar el `down()` de las dos migraciones si se quiere volver al esquema exacto.

**Dos cosas que hay que decir en voz alta sobre esta reversión:**

- **Las concesiones creadas por el paso 4.3 se quedan.** Apuntan a permisos retirados que ninguna ruta de la versión anterior exige, así que **no conceden nada**. Es un residuo inerte, no un riesgo.
- **Revertir la migración de `scope` reabre el fallo silencioso** que `ADR-044 §1.2` describe: un ámbito que el resolutor antiguo ignora. Por eso el `down()` es para un despliegue fallido, **nunca** para «desactivar temporalmente la validación» con el código nuevo en producción.

---

## 5. Colas, trabajos y tareas programadas (`INV-012`)

**Ninguno nuevo.** Este paso no encola nada y no programa nada.

Dos efectos sobre trabajos que **ya existen** y que hay que tener presentes:

### 5.1 `GenerateAuditLogExport` acota por ámbito

`POST /audit-logs/exports` de un rol con `auditoria.exportar` de ámbito `propios` debe generar un artefacto que contenga **sólo** las filas del solicitante.

> **La restricción se aplica dentro del trabajo, no sólo al aceptar la petición.** Un trabajo en cola no tiene sesión: si la acotación viviera únicamente en el controlador, el trabajo consultaría sin restricción y el fichero saldría completo. Es el error característico que la *skill* `permisos-y-roles` llama «olvidar `exportar`», en su forma más difícil de ver.

El trabajo recibe, por tanto, el sujeto y la decisión ya resueltos como parte de su carga —identificadores públicos, nunca objetos—, y vuelve a resolver el ámbito al arrancar, con su propio contexto de tenant. `data_exports.requested_by` ya existe desde 1.1 y es la columna que lo hace posible sin añadir ninguna.

### 5.2 El aprovisionamiento de un tenant escribe más filas de auditoría

Al hacer `PermissionRole` auditable (`datos.md §5.2`), `tenant:provision-defaults` pasa a escribir **una fila de `audit_logs` por concesión sembrada**, con `actor_type = 'console'`.

Son unas decenas de filas por alta de centro. **Es correcto según `INV-003` y no es una regresión**; se documenta aquí para que nadie lo lea como tal al comparar el coste de un aprovisionamiento antes y después de este paso.

---

## 6. Métricas, alertas y hallazgos de operación

### 6.1 Qué observar

| Señal | Por qué | Umbral |
|-------|---------|--------|
| Consultas de resolución por petición | Deben ser **dos**, gracias a la memoización de `scoped()`. Si crecen, la instancia se está recreando dentro de la petición | Más de dos por petición HTTP es un defecto, no una carga |
| Latencia de las dos consultas del resolutor | `ADR-044 §4.7` difiere la caché **a una medición**, no a una intuición. Sin este dato, la conversación sobre caché no se puede tener | Se registra; **no se alarma** con un solo centro |
| Crecimiento de `audit_logs` por eventos de `PermissionRole` | Alimenta el disparador de revisión de particionado de `ADR-034 §3` (50 M filas) | Sin alarma propia |
| Tasa de `422` con `core.validation.scope_resolver_missing` | Un pico significa que alguien intenta conceder ámbitos que aún no existen — probablemente una interfaz mal informada | Informativo |

**No se alarma sobre «permisos denegados».** Un `403` es el funcionamiento normal de `RPERM-011`, no un incidente, y alarmar sobre él enseñaría a ignorar la alarma.

### 6.2 La interfaz de disponibilidad de módulo que hay que extraer

`ADR-044 §4.9` fija que el resolutor consuma **un único booleano** —«¿este tenant puede usar este módulo ahora?»— **a través de una interfaz que posee `REQ-CORE`**. Hoy esa interfaz **no existe**: `EnsureModuleEnabled` es el único punto y consulta el modelo `ModuleSubscription` directamente.

1.5 la extrae, y no es cosmética: sin ella, el núcleo de autorización —que vive en `App\Support\Authorization`— tendría que alcanzar un modelo de `App\Modules\Core`, y el *middleware* y el resolutor tendrían **dos lecturas independientes** del mismo booleano, con dos comportamientos de caché y dos oportunidades de divergir. Con la interfaz hay una sola definición de «utilizable», en un solo sitio.

> **Sobre la caché de ese booleano** — verificado el 2026-09-04, **no hay hallazgo**. La clave de `EnsureModuleEnabled` es `"modules:{$moduleCode}:enabled"`, sin `tenant_id` aparente, pero el aislamiento lo aporta el **prefijo de tenant** de `ADR-033 §9`: `TenantContext::applyCachePrefix()` cambia `config('cache.prefix')` y llama a `Cache::forgetDriver(config('cache.default'))` para forzar la re-resolución del *store* con el prefijo nuevo. El mecanismo funciona como `REQ-CORE/operacion.md §1` lo documenta. Queda escrito porque la clave **parece** insegura al leerla suelta, y conviene que la próxima revisión no gaste el rato en volver a comprobarlo.

**Consecuencia de diseño que la interfaz debe respetar**: quien la implemente sigue obligado a resolverse **dentro del contexto de tenant activo**. Un consumidor que la llame sin contexto —un trabajo de cola mal construido, por ejemplo— debe obtener `false` y no un valor de otro centro. Fallo en cerrado, igual que en el *middleware* de hoy.

---

## 7. Problemas conocidos y diagnóstico

La pregunta que este subsistema va a generar es siempre la misma —«¿por qué esta persona no ve esto?»— y **el endpoint de permisos efectivos es la herramienta de diagnóstico, no una pantalla de lujo**. Esta tabla es su guía de lectura.

| Síntoma | Primera comprobación | Causa probable |
|---------|----------------------|----------------|
| «Le he dado el permiso y no lo tiene» | `GET /users/{id}/effective-permissions` | Otro de sus roles lleva un **`deny`** del mismo código: veta el código entero, en cualquier rol y con cualquier ámbito (`RN-PERM-06`) |
| «El permiso aparece concedido pero no surte efecto» | El campo `inert_reason` de ese código | `inerte_datos_especiales` (rol sin `special_data_access`), `inerte_modulo`, `inerte_sin_resolutor` o `inerte_permiso_retirado` |
| «Ve menos filas de las que debería» | El campo `scopes` de ese código | Tiene un ámbito restringido, no `todos`. Funcionando como se pidió |
| «El detalle devuelve 404 y el recurso existe» | Ídem | La fila no satisface su restricción de ámbito. **`404` es deliberado** (`api.md §9.3`); un `403` sería un oráculo de filas ajenas |
| «No puedo conceder el ámbito `grupo`» | `grantable_scopes` en `GET /permissions` | No hay resolutor registrado: llega con `REQ-ACAD` (1.11) o `REQ-FAM-UNIT` (1.14) |
| «No puedo activar `special_data_access`» | Los roles del solicitante | `RPERM-013` sobre el atributo: quien lo activa debe tenerlo. `administrador_centro` **no lo tiene, a propósito** (`permisos.md §7.4`) |
| «El administrador no puede crear roles tras el despliegue» | Paso **4.3** | El comando de migración de datos no se ejecutó. Es el fallo más probable de este despliegue |
| «Un permiso nuevo de un módulo no existe» | Paso **4.2** | `platform:sync-registry` no se ejecutó |
| «El cambio de roles no aparece en auditoría» | Que la escritura vaya por el servicio y no por `sync()` directo | `RN-PERM-19`/`RN-PERM-20`. Es literalmente el issue [#165](https://github.com/pirexia/plataforma-educativa/issues/165) reapareciendo |

**Regla de diagnóstico que ahorra la mitad de estos casos**: la vista previa se calcula con **el mismo código** que la aplicación real (`RN-PERM-22`). Si dice «permitido» y el endpoint responde `403`, no es que la vista previa mienta: es que el `403` viene de otro sitio —módulo desactivado, sesión, MFA, o una regla de negocio que no es un permiso (`permisos.md §8`)—. Empezar por ahí en lugar de por el resolutor ahorra el rato.

---

## 8. Impacto en copias de seguridad y restauración

**Ninguno estructural.** No hay tabla nueva, no hay almacén externo, no hay estado fuera de PostgreSQL.

Dos precisiones:

- **No hay nada que reconstruir tras una restauración.** Al no existir caché de permisos (`ADR-044 §4.7`), una restauración deja la autorización correcta en cuanto la base de datos está en pie. Es una ventaja concreta de haber dicho que no a la caché, y conviene que esté escrita.
- **Restaurar una copia anterior al despliegue revierte las concesiones del paso 4.3.** Tras cualquier restauración a un punto previo a 1.5 con el código de 1.5 desplegado, hay que **volver a ejecutar los pasos 4.2 y 4.3**. Es el mismo tipo de reaplicación que `REQ-BKP-005` ya exige para el registro de supresiones, y por el mismo motivo: son datos que un comando escribió, no que la aplicación genere sola.

---

## 9. Impacto en `SYSADMIN.md` y `CHANGELOG.md`

Trabajo de cierre del paso, no opcional (`CLAUDE.md §6`):

| Documento | Qué añadir |
|-----------|------------|
| `SYSADMIN.md` | Los cuatro pasos de §4 en el procedimiento de despliegue, con **`perm:grant-role-administration` marcado como obligatorio para tenants existentes**; la responsabilidad nueva de `platform:sync-registry`; y las cuatro comprobaciones de §4.4 |
| `CHANGELOG.md` | Una entrada por el paso cerrado (`CLAUDE.md §6.7`) |
| `SECURITY.md` | El modelo de autorización deja de ser «booleano por endpoint» y pasa a cubrir la fila. Es un cambio en la descripción de la arquitectura de seguridad del producto, no un detalle de módulo |
| `docs/modulos/REQ-CORE/permisos.md` | La lista completa de `permisos.md §9` de esta carpeta |
| `docs/modulos/REQ-AUTH/permisos.md` | `§5.6`, `§B.1` y `§C.7.6` quedan marcados como cerrados por 1.5, **no borrados** (`permisos.md §9.2`) |
| `docs/modulos/REQ-CORE/datos.md` | Añadir `PermissionRole` a la tabla de modelos auditables (`datos.md §5.5`) y `roles` a la lista de inclusión de auditoría de `User` (`datos.md §5.3.1`) |
