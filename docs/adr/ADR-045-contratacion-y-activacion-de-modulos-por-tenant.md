# ADR-045 · Contratación de módulos por tenant: una sola potestad, la del Super Admin, con aviso al centro

**Estado**: **ACEPTADA** (2026-09-08). La cuestión central —quién conmuta los módulos de un centro— la resolvió el usuario el mismo día, **en contra de la recomendación de `architect`**, que había propuesto la Opción C (dos columnas). El registro de la decisión, su texto literal y la discrepancia razonada están en `§12`, conforme a `CLAUDE.md §0` («si tras exponer tu objeción el usuario mantiene su decisión, ejecútala y registra la discrepancia»).

**Fecha**: 2026-09-08

**Resuelve**: el issue [#44](https://github.com/pirexia/plataforma-educativa/issues/44) y la pregunta abierta `OPEN-CORE-03` (`docs/modulos/REQ-CORE/funcional.md §2`, `§10`), diferida en 1.1 con severidad de bloqueo para 1.6

**Concreta**: `REQ-CORE-002` (panel de administración del tenant), `RMOD-002`, `RMOD-003`, `RMOD-004`, `RMOD-005`, `RMOD-006`, `RMOD-008`, `RMOD-009`, `RMOD-010` (sección 16.2), `REQ-BO-002` (sección 5.51), `RMOD-007`/`REQ-SAAS-001` (precios por módulo). Deja un consumidor declarado a `REQ-COM-003` (paso 1.19)

**Se apoya en**: `INV-001` (aislamiento a nivel de framework, nunca solo en el controlador), `INV-002` (denegar por defecto), `INV-003` (auditoría de toda modificación), `INV-006` (API primero), `INV-007` (un módulo no importa código interno de otro), `INV-009` (ningún literal en el código), `INV-012` (tareas pesadas en colas), `INV-015` (ningún requisito sin test que referencie su ID), `CLAUDE.md §9` (expand/contract), `ADR-029` (tipos e identificadores), `ADR-033 §5` (roles `plataforma_app`/`plataforma_platform`), `ADR-033 §9` (prefijo de caché por tenant), `ADR-034 §5` (esquema de `module_subscriptions`, catálogo en código, fallo en cerrado), `ADR-035 §8` (`ModuleSubscription` se audita con política `Full`), `ADR-038` (formato de error de la API), `ADR-044 §4.9` (la autorización consume `ModuleAvailability` y queda indiferente a cómo se resuelva este ADR — cláusula que este ADR honra sin cambiar la interfaz)

**Afecta a**: el paso **1.6** (`REQ-BO`), del que es entrada obligatoria de `spec-writer`. Toca `REQ-CORE` (panel del centro, `GET /modules`) y condiciona la facturación de `REQ-SAAS` (paso 3.x). Deja trabajo declarado para **1.19** (`REQ-COM-003`). Todo módulo de fase 2 en adelante hereda estas reglas sin conocerlas: consume `ModuleAvailability`, que no cambia de forma

**No sustituye a ningún ADR anterior.** Amplía `ADR-034 §5`, que fijó una única columna `enabled` dejando escrito, en el mismo párrafo, que el Administrador de Centro «activa y desactiva los contratados» — es decir, `ADR-034` arrastraba la contradicción de `#44` en su propio texto sin haberla decidido. Este ADR la decide **quitándole la potestad al centro**, y no revoca ninguna otra decisión de `ADR-034 §5`: la tabla sigue siendo de tenant con RLS, el catálogo sigue viviendo en el código, la ausencia de fila sigue siendo «desactivado» y descontratar sigue sin borrar datos.

---

## 1 · Contexto

`REQ-CORE-002` dice que el Administrador de Centro puede «activar/desactivar módulos contratados». `RMOD-002` dice que el Super Admin activa/desactiva módulos por tenant desde el backoffice. `module_subscriptions.enabled` es **un solo booleano**.

Un interruptor con dos manos encima no es una ambigüedad de redacción: es una condición de carrera de negocio. El Super Admin contrata `REQ-COMEDOR`, el centro lo apaga porque aún no lo despliega, el operador de plataforma ve `enabled = false` en la matriz de `REQ-BO-002` y lo lee como «no contratado» — y a partir de ahí, o se factura algo que la matriz dice que no está, o se deja de facturar algo que sí lo está. La contradicción no se manifiesta como un error: se manifiesta como una factura.

Hay que decidirlo **ahora** porque `REQ-BO-002` (paso 1.6) es exactamente la pantalla que escribe ese booleano.

### 1.1 · Estado real verificado en el repositorio, no supuesto

Verificado el 2026-09-08 sobre `develop` en `b95be70`:

- **`module_subscriptions`** (`apps/api/database/migrations/2026_08_18_100600_create_modules_table.php`) es tabla de tenant creada con `TenantMigration::tenantTable()`: `public_id` (ULID), `module_code` (FK a `modules.code`), **`enabled boolean DEFAULT false`**, `enabled_at`, `disabled_at`, `reason`, `settings jsonb`, más `created_by`/`updated_by`, `timestamps` y `deleted_at`. RLS `FORCE` con la política `tenant_isolation`, y `UNIQUE (tenant_id, module_code) WHERE deleted_at IS NULL`.
- **`modules`** es tabla de referencia sin `tenant_id`, con `REVOKE INSERT, UPDATE, DELETE` para `plataforma_app` y `plataforma_platform`. Solo la escribe `SyncModuleRegistry` por `pgsql_owner`, desde lo que declara cada `ServiceProvider` vía `DeclaresModuleRegistry::moduleDescriptor()`.
- **`moduleDescriptor()` devuelve hoy `{code, name_key, phase}` y nada más.** **No existe ninguna declaración de dependencias entre módulos, en ningún sitio.** `ADR-034 §5` decidió que las dependencias de `RMOD-006` «viven solo en el código, en el `ServiceProvider` de cada módulo», pero nunca fijó el campo ni el punto de validación: hoy `RMOD-006` no tiene dónde escribirse.
- **Ninguna línea de código de aplicación escribe `enabled`.** Los únicos escritores de la tabla en todo el repositorio son los tests (`ModuleSubscriptionsSchemaTest`). `ModulesController::updateSettings()` **rechaza explícitamente** cualquier petición que traiga `enabled` (`422`, código `core.validation.enabled_not_editable`), tal como 1.1 acotó su alcance al diferir `OPEN-CORE-03`.
- **Permisos existentes**: `modulo.leer` y `modulo.actualizar`, ambos concedidos a `administrador_centro`; `direccion` tiene solo `modulo.leer` (`ProvisionTenantDefaults`). Hoy `modulo.actualizar` es, en la práctica, «editar `settings`» y nada más — el `PATCH` ya rechaza `enabled`.
- **`App\Support\Modules\ModuleAvailability`** es la única definición de «utilizable»: un método, `isEnabled(string $moduleCode): bool`, con dos consumidores — el middleware `EnsureModuleEnabled` (el 403 de `RMOD-009`) y `PermissionResolver` (filtro de inercia `inerte_modulo`, `ADR-044 §4.9`).
- **`EloquentModuleAvailability` lleva `['core', 'auth']` escrito a mano** en la constante privada `ALWAYS_ENABLED`. Ni el backoffice ni el panel del centro pueden ver hoy esa lista.
- **La caché de disponibilidad no se invalida en la escritura.** `Cache::remember("modules:{$code}:enabled", 300, …)` sin ningún `Cache::forget` correspondiente en todo el repositorio (sí lo hay para `tenant:{id}:settings`, en `TenantSettingsCache`). `ADR-034 §5` lo exigía literalmente. Latente hoy —nadie escribe—, deja de serlo en 1.6 (`§8.3`).
- **No existe ninguna infraestructura de notificaciones in-app.** No hay tabla `notifications` ni ninguna migración que la cree; `User` usa el *trait* `Notifiable` heredado del *starter kit* de Laravel, que **sin tabla es inerte** — un `->notify()` por el canal `database` fallaría hoy. `REQ-COM` (mensajería, circulares y notificaciones) es el paso **1.19**, trece pasos después de 1.6.
- **Lo que sí existe y funciona** es el patrón evento de dominio + *job* en cola + correo: once `Modules/*/Domain/Events`, listeners en `Infrastructure/Listeners` y una docena de *jobs* `Send*Email` que hacen `Mail::to()->locale()->send(Mailable)` (`INV-012`). Es la vía probada desde 1.2, y `OPEN-09` (proveedor de correo transaccional) sigue abierta.
- **PostgreSQL 17**; la concesión por defecto de `infra/containers/postgres/init/01-tenancy.sql.tpl` es `SELECT, INSERT, UPDATE, DELETE` a `plataforma_app` y `plataforma_platform` sobre toda tabla nueva.
- **No hay despliegue en producción ni ningún tenant real.**

### 1.2 · El hecho que ordena la decisión

Son **dos decisiones de naturaleza distinta**, no dos vistas de la misma. «La plataforma le vende este módulo a este centro» es comercial: la toma el Super Admin, tiene motivo obligatorio, consecuencia económica (`RMOD-007`) y trazabilidad contractual. «El centro lo tiene encendido esta semana» sería operativa. La pregunta que había que responder no era cómo representar las dos, sino **si la segunda debe existir**.

El usuario ha decidido que **no existe** (`§12.1`): sobre un módulo solo hay una decisión, la comercial, y el centro no participa en ella. Con eso, `module_subscriptions.enabled` recupera un único significado y un único dueño, y la contradicción desaparece por eliminación de uno de los dos actores, no por reparto.

---

## 2 · Qué NO decide este ADR

- **No decide el diseño de la matriz de `REQ-BO-002`** (columnas, filtros, interacción). Fija qué debe mostrar como mínimo (`§4.7`) y nada más.
- **No decide el modelo comercial de planes y tramos de precio.** `RMOD-007` y `REQ-SAAS-001` siguen sin plan concreto y la cuota de tenant sigue fuera de alcance por decisión del usuario (issue [#79](https://github.com/pirexia/plataforma-educativa/issues/79)). Aquí solo se fija **sobre qué columna se factura** (`§4.6`).
- **No decide la autenticación ni los roles internos del backoffice** (`REQ-BO-007`), ni las tablas `platform_admins`/`admin_action_logs`.
- **No decide los estados del tenant** (`REQ-BO-001`: `activo`, `suspendido`, `en_baja`). Un tenant suspendido bloquea a sus usuarios por una vía distinta y anterior; no es un estado de módulo.
- **No construye el sistema de notificaciones de `REQ-COM-003`**, ni adelanta ni una tabla suya (`§4.8`).
- **No escribe ni una línea de migración**, ni reescribe el documento de requisitos. `§10` dice qué hay que reescribir y con qué sentido; lo escribe `spec-writer` en 1.6.

---

## 3 · Opciones reales

Las cuatro que de verdad se podían ejecutar sobre el esquema que existe hoy. Se conservan íntegras, con su evaluación original, porque la elegida no fue la recomendada y el criterio de comparación tiene que quedar visible para quien vuelva a abrir esto.

**Opción A · Un solo interruptor, de la plataforma.** El Administrador de Centro **consulta** sus módulos contratados y no los conmuta. `REQ-CORE-002` se reescribe. Cero migración de esquema.

**Opción B · Un solo interruptor, del centro.** La plataforma contrata implícitamente por plan y el centro decide qué enciende.

**Opción C · Dos columnas con `AND` lógico.** `contracted` (plataforma) y `enabled_by_tenant` (centro) sobre la misma fila, con el estado efectivo materializado.

**Opción D · Dos tablas.** `module_contracts` (plataforma) y `module_activations` (tenant, con RLS), unidas en cada lectura.

### Evaluación

| Criterio | A | B | C | D |
|---|---|---|---|---|
| **Coste de implementación en solitario** | **El más bajo de los cuatro**: ninguna columna nueva, ningún renombrado, ningún cambio de contrato con la SPA. Solo endurecer privilegios y abrir el camino de escritura del backoffice | Nulo en código; alto en producto: obliga a inventar ya el modelo de planes que el issue #79 difirió | Bajo: una migración aditiva y un `AND` | Medio: dos tablas, dos ciclos de vida y un `JOIN` en cada comprobación de `RMOD-009` |
| **Mantenimiento a 3 años** | Aceptable, con un coste real: un centro que quiera dejar de ver un módulo contratado tiene que pedírselo a la plataforma. Con 200 centros es una cola de soporte, pequeña pero permanente | Malo: la plataforma pierde el control por tenant sobre el que se construye `REQ-BO-002` entero | Bueno: dos columnas independientes, un escritor cada una | Bueno, algo más limpio en *ownership*, a cambio del `JOIN` en el camino caliente |
| **Impacto en las invariantes** | Neutro, y el más simple de auditar: un dato, un escritor, un rol de base de datos | **Contradice `INV-002`**: el centro se autoconcede acceso a algo que la plataforma no le ha dado | Neutro y refuerza: el `AND` es denegar-por-defecto aplicado a módulos | Neutro |
| **Reversibilidad** | **Alta, y en los dos sentidos**: A→C es aditivo puro (añadir `enabled_by_tenant` con `DEFAULT true` no cambia el estado efectivo de ninguna fila existente) | Baja: habría que retirar permisos ya concedidos | Alta hacia D, mecánica | Media: volver de D a C es fundir dos tablas con datos de centros reales |

**Recomendación de `architect` (2026-09-08): Opción C.** Argumento: el fallo posible en A no es técnico sino de producto —se borra una capacidad de `REQ-CORE-002` para ahorrar una columna—, y `REQ-CORE-002` no es un requisito accesorio, es la definición del panel del centro.

**Decisión del usuario (2026-09-08): Opción A**, con un matiz que ninguna de las cuatro opciones contemplaba y que cambia su evaluación: **al contratar, se avisa al centro** de que el módulo está disponible (`§4.8`). Texto literal y discrepancia razonada en `§12.1`.

Ese matiz importa. La objeción principal contra A era que el centro queda pasivo y desinformado ante un cambio en su propio panel; con el aviso, A conserva lo que de verdad necesita el Administrador de Centro —enterarse— y renuncia solo a la potestad de conmutar, que es el uso menos frecuente y el que tiene consecuencia comercial. Y la reversibilidad de A→C es aditiva pura: si la cola de soporte aparece, añadir el interruptor del centro más adelante es una columna con `DEFAULT true`, sin migrar ni una fila y sin cambiar el estado efectivo de nadie. Elegir A hoy **no cierra ninguna puerta**, y eso rebaja mucho lo que había en juego en la recomendación de `architect`.

---

## 4 · Decisión

### 4.1 · Una sola potestad y un solo escritor

`module_subscriptions.enabled` significa **una sola cosa**: la plataforma ha contratado este módulo para este centro, y por tanto el centro puede usarlo. La escribe **solo el Super Admin**, desde el backoffice, por la conexión `plataforma_platform` (`ADR-033 §5`), con motivo obligatorio en `reason` (`REQ-BO-002`).

**El Administrador de Centro no conmuta módulos, ni para encender ni para apagar.** Lo que sí conserva, sin cambios respecto a hoy: **leer** sus módulos (`modulo.leer`, `GET /modules`) y **configurar** los contratados (`modulo.actualizar`, `PATCH` sobre `settings`).

### 4.2 · No se renombra `enabled`

Un borrador anterior de este ADR proponía renombrar `enabled` → `contracted` bajo expand/contract. **Se descarta.** Aquel renombrado existía para desambiguar dos columnas que iban a convivir; con una sola potestad no hay ambigüedad que resolver: en `module_subscriptions`, `enabled` quiere decir «esta suscripción está activa», que es exactamente lo que es y lo que se factura.

Renombrar ahora costaría una migración *expand*, un disparador de sincronización, una ventana de dos entregas con cuatro columnas obsoletas, un issue de seguimiento para la fase *contract* y un cambio de contrato con la SPA — todo para cambiar una palabra que ya no engaña a nadie. Es complejidad sin beneficio proporcional, y se dice que no.

**Consecuencia directa: este ADR no cambia el esquema de `module_subscriptions`.** Ni una columna nueva, ni un renombrado, ni columna generada. `enabled`, `enabled_at`, `disabled_at`, `reason` y `settings` se quedan como están y con el significado que `ADR-034 §5` les dio.

### 4.3 · Fallo en cerrado, sin cambios

1. `enabled = true` → módulo utilizable.
2. `enabled = false` → no utilizable; `RMOD-009` responde `403`.
3. **Ausencia de fila = no contratado = no utilizable** (`ADR-034 §5`). Se conserva íntegro.
4. **Solo el backoffice crea filas.** Contratar es el `INSERT`; el centro nunca crea una suscripción.

### 4.4 · El único escritor lo impone el motor, no el controlador

`ALTER DEFAULT PRIVILEGES` concede hoy `SELECT, INSERT, UPDATE, DELETE` a `plataforma_app` y `plataforma_platform` sobre toda tabla nueva. En PostgreSQL un `UPDATE` a nivel de tabla cubre **todas** las columnas y no se puede recortar: hay que revocarlo y volver a concederlo acotado.

1.6 aplica, en una migración, el patrón que ya tiene precedente en el repositorio (`2026_08_17_180000_harden_failed_jobs_grants.php`, `2026_08_18_100900_harden_audit_logs_platform_grants.php`):

- `REVOKE UPDATE, INSERT ON module_subscriptions FROM plataforma_app`.
- `GRANT UPDATE (settings, …) ON module_subscriptions TO plataforma_app` — el centro escribe su configuración y **nada más**.
- `plataforma_platform` conserva `INSERT` y `UPDATE` completos: es la conexión del backoffice.

Esto no es adorno: hoy la garantía de que un centro no se contrata un módulo a sí mismo es **un `if` en `ModulesController::updateSettings()`**. `INV-001` estableció que las restricciones que importan no viven en el controlador, y esta tiene consecuencia económica. Con el `REVOKE`, un fallo futuro en ese controlador —o un controlador nuevo que nadie relacione con esto— deja de poder contratar nada.

**La lista exacta de columnas la fija `spec-writer` y la verifica `db-reviewer`, e incluye las columnas de infraestructura que Eloquent escribe en todo `UPDATE`** (`updated_at`, `updated_by`, y `deleted_at` si algún camino borra lógicamente). Una lista incompleta no falla en la revisión: falla en producción, como error de privilegios en una operación que el usuario cree rutinaria. Es de obligado cumplimiento que exista **un test por revocación** —el centro no puede escribir `enabled`, el centro no puede insertar filas—, con la forma de los de `ModuleSubscriptionsSchemaTest`. Un `REVOKE` que no se prueba no existe (lección del bug 6 de 0.7 con `failed_jobs`, citada en `TenantMigration`).

> **Consecuencia inmediata para 1.6**: `ModuleSubscriptionsSchemaTest` inserta hoy por la conexión `pgsql` (`plataforma_app`); con el `REVOKE INSERT` debe pasar a `pgsql_platform`. No es un ajuste cosmético del test: es la comprobación de que la restricción funciona.

### 4.5 · Dependencias (`RMOD-006`): invariante de escritura

Las dependencias **no entran en la lectura de disponibilidad**. Hacerlo convertiría `isEnabled()` en un recorrido de grafo en el camino caliente de cada petición, sobre un grafo que vive en el código y no en la base de datos. Se aplican como invariante en el único punto de escritura que existe, el backoffice:

- **Al contratar**: no se contrata `M` sin contratar sus dependencias. El backoffice las resuelve automáticamente y las muestra en la vista previa de impacto (`REQ-BO-002`, `RMOD-006`).
- **Al descontratar**: no se descontrata una dependencia de un módulo contratado sin aviso explícito y confirmación, con la lista de arrastrados en la vista previa.
- **Ninguna escritura puede dejar un módulo contratado cuya dependencia no lo esté.** Una sola implementación, en un servicio de dominio de `REQ-CORE`, consumida por la activación individual, por la masiva y por la vista previa.

**Dónde se declaran.** `ADR-034 §5` decidió que las dependencias viven solo en el código y no se desnormalizan; esa decisión **se mantiene**, y este ADR le da el sitio que le faltaba: `moduleDescriptor()` gana `depends_on: list<string>` (por omisión, lista vacía). `SyncModuleRegistry` valida en el despliegue que cada código referenciado existe en el catálogo declarado y que el grafo **no tiene ciclos**, y **aborta el despliegue** si no es así — mismo precedente y mismo motivo que la validación de `applicable_scopes` de `ADR-044`: mejor un despliegue detenido que un catálogo con una arista rota.

**Una arista nueva no reconcilia nada por su cuenta.** Si una versión declara que `M` pasa a depender de `N`, habrá centros con `M` contratado y `N` no. `platform:sync-registry` **informa** y **no corrige**: contratar `N` automáticamente en un despliegue sería un script tomando una decisión comercial facturable sobre 200 centros. Las resuelve un humano desde la matriz, y `REQ-BO-004` (salud del tenant) las muestra como incidencia.

### 4.6 · Se factura sobre `enabled`

`RMOD-007` y `REQ-SAAS-001` cuelgan de esta columna, que ahora es la única. Con una sola potestad la regla es trivial —lo contratado es lo facturado y lo facturado es lo utilizable— y por eso mismo conviene dejarla escrita antes de que exista una factura: es la propiedad que se pierde en cuanto alguien vuelva a proponer un interruptor del lado del centro.

### 4.7 · Lo que la matriz de `REQ-BO-002` tiene que mostrar

Requisito de información, no de diseño. Cada celda tenant × módulo distingue **tres** situaciones:

1. **No contratado** (sin fila, o `enabled = false`).
2. **Contratado** → utilizable por el centro desde ese mismo instante (`§12.2`).
3. **Módulo esencial** (`§4.9`): celda bloqueada, sin conmutador.

La **activación masiva** (`REQ-BO-002`) opera sobre `enabled` y dispara **un aviso por cada centro afectado** (`§4.8`), no uno global. Su vista previa de impacto dice, en número, cuántos centros pasan a tenerlo y cuántas dependencias arrastra.

### 4.8 · El aviso al centro: evento de dominio ahora, notificación real en `REQ-COM`

El usuario pidió que al contratar se avise al Administrador de Centro de la disponibilidad del módulo, en su panel de administración, **sin acción requerida por su parte** (`§12.2`). Cómo se implementa eso sin construir `REQ-COM` trece pasos antes de tiempo:

**1. `REQ-CORE` emite dos eventos de dominio, y son la parte duradera de esta decisión.** `ModuleContracted` y `ModuleDecontracted`, con `tenant_id`, `module_code` y el actor de plataforma, siguiendo el patrón ya establecido en las once clases de `Modules/*/Domain/Events`. Los emite el servicio de contratación de `REQ-CORE`, no el backoffice directamente: el evento debe existir aunque la escritura venga de la activación masiva o de un comando de consola.

**2. En 1.6, el aviso se deriva del dato que ya está en la tabla, sin infraestructura nueva.** No hay tabla `notifications` ni sistema de notificaciones in-app (`§1.1`), y crear uno aquí sería construir `REQ-COM-003` (paso 1.19) por adelantado y sin su especificación, garantizando que se rehace. El panel de módulos del centro muestra, para cada módulo contratado, **desde cuándo lo está** (`enabled_at`, que ya se rellena) y **destaca los contratados recientemente**. Es información derivada: no guarda estado, no necesita marcar nada como leído, se apaga sola con el tiempo y es idempotente ante cualquier reintento.

**3. Cuando llegue `REQ-COM-003` (1.19), un listener de `ModuleContracted` crea la notificación in-app de verdad**, dirigida a los usuarios del tenant que tengan **`modulo.leer`** —hoy `administrador_centro` y `direccion`—, resuelto por permiso y **nunca por una lista de códigos de rol escrita a mano**, siguiendo el precedente de `EloquentMfaPolicy::requiredByRoleCodes()`, que consulta `roles.mfa_required` genéricamente. Con el evento ya emitido desde 1.6, ese paso es un listener y una plantilla: nada de lo que se construya en 1.6 hay que deshacerlo.

**4. Correo: no, todavía.** El patrón *job* en cola + `Mailable` existe y funciona (`§1.1`), pero un aviso comercial por correo a los administradores de 200 centros es una decisión de comunicación con producto detrás (frecuencia, agregación, cancelación de suscripción, `OPEN-09` aún abierta), no un efecto secundario de un `UPDATE`. El evento deja el enganche puesto para cuando se decida.

**Relación con `RMOD-010`, que hay que dejar zanjada.** `RMOD-010` dice que los eventos de dominio de un módulo **desactivado** no se emiten. No hay conflicto: `ModuleContracted` y `ModuleDecontracted` no los emite el módulo contratado, sino **`REQ-CORE`**, que es esencial y siempre está activo (`§4.9`). Son eventos *sobre* un módulo, no *de* un módulo. Además, en el instante en que se emite `ModuleContracted` el módulo ya está contratado y utilizable (`§12.2`), con lo que hasta la lectura más estricta de `RMOD-010` queda satisfecha. La regla general que se deriva y que `spec-writer` debe escribir: **todo evento sobre el ciclo de vida de un módulo pertenece a `REQ-CORE`, nunca al módulo afectado** — si perteneciera al módulo, `ModuleDecontracted` no podría emitirse nunca, porque el módulo ya estaría apagado cuando tocara emitirlo.

### 4.9 · Los módulos esenciales se declaran, no se codifican

`['core', 'auth']` sale de la constante privada `ALWAYS_ENABLED` de `EloquentModuleAvailability` y pasa a `moduleDescriptor()` como `essential: bool` (por omisión, `false`). Para un módulo esencial: `isEnabled()` devuelve `true` sin necesidad de fila —comportamiento idéntico al de hoy— y el backoffice no puede descontratarlo.

Motivo: la matriz de `REQ-BO-002` y el panel del centro necesitan saber qué celdas están bloqueadas, y una lista escrita a mano dentro de una clase de infraestructura no es visible para ninguna de las dos; replicarla en el frontend del backoffice es la manera conocida de que las dos listas se separen. **No se añade columna a `modules`**: el catálogo vive en el código (`ADR-034 §5`) y son 53 módulos leídos en proceso.

### 4.10 · `ModuleAvailability` y `RMOD-009` no cambian

La interfaz sigue siendo un método y un booleano, y `EloquentModuleAvailability` sigue consultando `enabled`. `EnsureModuleEnabled` y `PermissionResolver` **no se tocan**, y la cláusula de `ADR-044 §4.9` («la autorización queda indiferente a cómo se resuelva el issue #44») se cumple literalmente. `RMOD-005` (sin jobs ni listeners) y `RMOD-010` (eventos no emitidos) se resuelven por la misma interfaz.

`RMOD-009` conserva **un solo código de error** (`ApiException::moduleDisabled()`): con una sola potestad solo hay una causa posible —no contratado—, y el mensaje puede decirlo sin ambigüedad y sin revelar plan, precio ni condiciones comerciales. Un borrador anterior proponía dos códigos para distinguir quién había apagado el módulo; esa distinción desaparece con el segundo actor.

---

## 5 · Esquema resultante

**El mismo que hoy.** Se reproduce con la semántica ya sin ambigüedad, porque el valor de este ADR está justamente en fijarla:

```
module_subscriptions  (tabla de tenant, RLS FORCE, UNIQUE (tenant_id, module_code) WHERE deleted_at IS NULL)

  public_id        ulid          UNIQUE
  module_code      text          FK → modules.code

  -- única decisión: la comercial. Escribe solo plataforma_platform (backoffice)
  enabled          boolean       NOT NULL DEFAULT false   -- contratado por la plataforma = utilizable
  enabled_at       timestamptz   NULL                     -- desde cuándo (alimenta el aviso de §4.8)
  disabled_at      timestamptz   NULL
  reason           text          NULL                     -- motivo obligatorio del flujo REQ-BO-002

  -- configuración del módulo para ese centro. Escribe solo plataforma_app (modulo.actualizar)
  settings         jsonb         NULL

  tenant_id, created_by, updated_by, created_at, updated_at, deleted_at
```

**Lo que 1.6 sí cambia en la base de datos** se reduce a una migración de endurecimiento de privilegios (`§4.4`). Ninguna columna se añade, se renombra ni se elimina.

---

## 6 · Migración y expand/contract (`CLAUDE.md §9`)

**No hay cambio de esquema, luego no hay ciclo expand/contract que planificar.** El plan de cuatro renombrados, disparador de sincronización y fase *contract* diferida que contenía el borrador anterior queda **sin efecto**: era el precio de la Opción C y desaparece con ella. Este es el beneficio más tangible de la decisión del usuario y conviene anotarlo como tal.

Lo que 1.6 debe ejecutar, y que `db-reviewer` debe revisar:

1. **Migración de privilegios** (`§4.4`), con sus tests y con la lista de columnas verificada contra lo que Eloquent escribe de verdad.
2. **Ajustar `ModuleSubscriptionsSchemaTest`** a la conexión `pgsql_platform` (`§4.4`).
3. **Camino de escritura del backoffice**: servicio de contratación en `REQ-CORE` (`§4.5`), emisión de los dos eventos (`§4.8`) e invalidación de caché (`§8.3`), que **no es opcional**.
4. **`depends_on` y `essential` en `moduleDescriptor()`**, con la validación de `SyncModuleRegistry` que aborta el despliegue (`§4.5`, `§4.9`).

La única compatibilidad hacia atrás que hay que cuidar es la de los privilegios: un `REVOKE` desplegado antes que el código que deja de escribir esas columnas rompería la versión anterior. Como hoy **ninguna línea de código de aplicación escribe `enabled`** (`§1.1`), esa ventana está vacía y el orden es indiferente — pero conviene que quede dicho por qué, y no por casualidad.

---

## 7 · Motivo

1. **La contradicción se resuelve mejor eliminando un actor que repartiendo el dato.** Dos titulares sobre una decisión exigen un modelo que los separe y una regla de precedencia que alguien tiene que leer; un titular no exige nada. La decisión del usuario compra simplicidad estructural al precio de una capacidad del panel del centro, y ese intercambio es defendible.
2. **Un dato, un escritor, un rol de base de datos.** Con `REVOKE`/`GRANT` de columna, «el centro no puede contratarse un módulo» deja de ser una convención del controlador y pasa a ser una propiedad del motor, que es donde `INV-001` dice que viven las restricciones que importan.
3. **No renombrar es la decisión correcta cuando desaparece el motivo del renombrado** (`§4.2`). Arrastrar un cambio de esquema por inercia del borrador anterior habría sido coste puro.
4. **Las dependencias son una regla de escritura, no una capa de la lectura** (`§4.5`). Se comprueban dos veces al día en vez de mil veces por segundo, y la vista previa de `REQ-BO-002` las necesita ahí de todos modos.
5. **El aviso se resuelve con un evento y un dato que ya existe** (`§4.8`). Construir un sistema de notificaciones in-app en 1.6 sería adelantar `REQ-COM-003` trece pasos, sin su especificación y con garantía de rehacerlo; no construir nada dejaría al centro sin enterarse. El evento de dominio es la pieza que hace que ninguna de las dos cosas ocurra.
6. **Sigue siendo reversible.** A→C es aditivo puro: una columna con `DEFAULT true` que no cambia el estado efectivo de ninguna fila. Si la cola de soporte de `§8.1` se materializa, la vuelta atrás cuesta una migración y este ADR ya tiene evaluada la alternativa.

---

## 8 · Consecuencias

**Buenas**

- `#44` y `OPEN-CORE-03` quedan cerradas, y 1.6 puede especificarse sin decidir modelo de datos por el camino.
- **Coste de esquema cero.** Ninguna columna nueva, ningún renombrado, ninguna ventana expand/contract, ningún cambio de contrato con la SPA en el campo `enabled` de `GET /modules`.
- Los permisos existentes valen tal cual: `modulo.leer` (consultar) y `modulo.actualizar` (configurar `settings`) conservan su significado, y ningún rol gana ni pierde nada.
- `ModuleAvailability`, `EnsureModuleEnabled` y `PermissionResolver` no cambian: `ADR-044 §4.9` se cumple literalmente.
- `RMOD-006` gana por fin sitio donde declararse (`depends_on`) y validación en el despliegue.
- La facturación queda anclada a una columna sin ambigüedad antes de que exista una factura.

**Malas, aceptadas a sabiendas**

1. **El centro pierde una capacidad que el documento de requisitos le daba.** Un centro que contrata el paquete completo y despliega `REQ-COMEDOR` en enero verá el módulo desde septiembre y no podrá ocultarlo por su cuenta: tendrá que pedirlo a la plataforma, o convivir con él. Es el coste que el usuario ha aceptado (`§12.1`). Mitigación parcial: `RMOD-008` oculta en la interfaz lo no contratado, y `settings` permite al centro configurar el módulo aunque no apagarlo. Mitigación real si el problema aparece: A→C, aditivo (`§6`).
2. **El aviso de 1.6 es más débil que una notificación de verdad.** Un realce en el panel solo lo ve quien entra en esa pantalla; no persigue al administrador ni deja constancia de si se leyó. Es deliberado (`§4.8`), y `REQ-COM-003` lo sustituye por lo bueno en 1.19 sin rehacer nada.
3. **Los privilegios de columna son frágiles ante el olvido.** Tras el `REVOKE`, una columna nueva en la tabla no queda concedida automáticamente a `plataforma_app`, y el fallo aparece en tiempo de ejecución, no en la revisión. Mitigación: tests de `§4.4` y nota explícita para `db-reviewer`.
4. **La caché de disponibilidad se vuelve un problema real en 1.6** — ver `§8.3` (numerado aparte por ser un requisito, no solo una consecuencia).

**8.3 · Requisito derivado, de obligado cumplimiento en 1.6: invalidación de caché**

No existe hoy ninguna invalidación de `modules:{code}:enabled` (`§1.1`), contra lo que `ADR-034 §5` exigía. Es inofensivo mientras nadie escriba; en cuanto el backoffice escriba, un cambio tardará hasta 300 s en verse, con un agravante concreto: el prefijo de caché es `t{tenant_id}:`, fijado en `TenantContext::enter()` (`ADR-033 §9`), y **el backoffice escribe desde fuera del contexto del tenant**, de modo que una invalidación ingenua limpiaría la clave equivocada — y una activación masiva la limpiaría equivocada 200 veces. Toda escritura de `enabled` debe invalidar la clave del tenant afectado, **con test de que el backoffice invalida el prefijo correcto**. Es un requisito de esta decisión, no una mejora deseable.

---

## 9 · Alternativas descartadas y por qué

- **Opción C — dos columnas con `AND`** (`§3`). Era la recomendación de `architect`; descartada por decisión del usuario (`§12.1`). Su argumento a favor —conservar íntegro `REQ-CORE-002`— y su coste —una columna, una columna generada, privilegios de columna en dos sentidos y una ventana expand/contract— quedan registrados para quien reabra la cuestión. Sigue siendo el destino natural si aparece la cola de soporte de `§8.1`, y llegar hasta ella es aditivo.
- **Opción B — solo el centro conmuta** (`§3`). Descartada por seguridad y por negocio: un centro que se autoconcede un módulo no contratado es una escalada de privilegio con consecuencia económica.
- **Opción D — dos tablas** (`§3`). Descartada: con una sola potestad no hay nada que separar, y añadiría un `JOIN` al camino de `RMOD-009`, que se ejecuta en cada petición de cada usuario de cada centro.
- **Un tercer estado «contratado, pendiente de que el centro lo acepte».** Descartado explícitamente por el usuario (`§12.2`, «efectivo desde ya»). Habría añadido una máquina de estados, una pantalla de aceptación y la pregunta de qué pasa con lo facturado mientras nadie acepta.
- **Renombrar `enabled` → `contracted`** (`§4.2`). Descartado: el motivo del renombrado desapareció junto con la segunda columna.
- **Construir el sistema de notificaciones in-app en 1.6** (`§4.8`). Descartado: es `REQ-COM-003`, paso 1.19, y hacerlo aquí sin su especificación garantiza rehacerlo. La tabla no existe y el *trait* `Notifiable` de `User` es hoy inerte.
- **Resolver los destinatarios del aviso por lista de códigos de rol.** Descartado: se resuelve por el permiso `modulo.leer`, siguiendo el precedente genérico de `EloquentMfaPolicy`. Una lista escrita a mano deja fuera a cualquier rol personalizado que el centro cree.
- **Meter las dependencias en la lectura de disponibilidad** (`§4.5`). Descartado: cierre transitivo sobre un grafo que vive en el código, en el camino caliente de cada petición.
- **Materializar el grafo de dependencias en `modules`**. Descartado por `ADR-034 §5`, que ya lo razonó: una copia en base de datos de algo que declara el código se desincroniza en el primer despliegue en que alguien olvide correr el comando, y falla en silencio. No se reabre.
- **Correo al contratar** (`§4.8` punto 4). No descartado, diferido: el evento deja el enganche puesto y la decisión de comunicación pertenece a producto, con `OPEN-09` aún abierta.

---

## 10 · Impacto en el documento de requisitos

Este ADR **no reescribe** `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` más allá de añadirse al índice de la sección 18. Lo que sigue es el encargo para `spec-writer` en 1.6, que es quien lo redacta y quien actualiza versión e historial del documento:

| Requisito | Qué cambia |
|---|---|
| `REQ-CORE-002` (§5.1, línea ~453) | «Activar/desactivar módulos contratados» **se sustituye** por: consultar los módulos contratados, **recibir aviso de las nuevas altas** y configurar los contratados (`settings`). **Sin capacidad de conmutar.** Referencia a este ADR |
| `RMOD-002` (§16.2) | «Activar/desactivar» pasa a **«contratar/descontratar»** por tenant, y se hace explícito que es la **única** potestad sobre el interruptor |
| `RMOD-003`, `RMOD-004` | Sin cambio de fondo; aclarar que descontratar no toca datos y que contratar de nuevo los restituye |
| `RMOD-006` | Reglas de dependencia sobre la única potestad (`§4.5`) y comportamiento de `platform:sync-registry` ante una arista nueva (informa, no corrige) |
| `RMOD-009` | Sin cambios (`§4.10`): un solo código de error, una sola causa |
| `RMOD-010` | Añadir la regla derivada: **todo evento del ciclo de vida de un módulo pertenece a `REQ-CORE`, no al módulo afectado** (`§4.8`) |
| `RMOD-007`, `REQ-SAAS-001` | Se factura sobre `enabled`, que es lo contratado (`§4.6`) |
| `REQ-BO-002` (§5.51) | La matriz distingue tres estados y **contratar dispara un aviso al centro**, uno por centro también en la activación masiva (`§4.7`, `§4.8`) |
| `REQ-COM-003` (§5.12) | Gana un consumidor declarado para 1.19: la notificación in-app de `ModuleContracted` (`§4.8` punto 3) |
| `docs/modulos/REQ-CORE/funcional.md §2` y `OPEN-CORE-03` | Pasan a **RESUELTO por `ADR-045`**. `CA-CORE-061` (hoy: «cambiar `enabled` no existe para el centro») **se conserva y se refuerza**: deja de ser una limitación temporal de 1.1 y pasa a ser la regla definitiva, ahora respaldada por privilegios de base de datos |

---

## 11 · Hallazgos fuera del alcance de este ADR, reportados y no corregidos

Conforme a `CLAUDE.md §0` y a la norma de no actuar sobre trabajo ajeno al encargo (issue [#150](https://github.com/pirexia/plataforma-educativa/issues/150)):

1. **`ADR-034 §5` exige invalidar la caché de suscripciones en la escritura y no se implementó.** Latente hoy, bloqueante en 1.6. Detalle y requisito derivado en `§8.3`. No se corrige aquí: es código de 1.6.
2. **`RMOD-006` no tiene hoy dónde declararse.** `ADR-034 §5` dijo «en el `ServiceProvider` de cada módulo» y `moduleDescriptor()` nunca recibió el campo. `§4.5` lo repara como decisión; la implementación es de 1.6.
3. **`ADR-034 §5` contiene, en su propio texto, la contradicción de `#44`.** No se edita: un ADR es inmutable, y este lo amplía sin revocarlo.
4. **`User` usa el *trait* `Notifiable` sin que exista la tabla `notifications`.** Herencia del *starter kit*; hoy es inerte y no rompe nada, pero invita a que alguien escriba `->notify()` y descubra el fallo en ejecución. Candidato a limpieza o a resolverse en `REQ-COM` (1.19); no se toca aquí.

---

## 12 · Registro de las decisiones del usuario

**12.1 · ¿Tiene el centro potestad para conmutar módulos? — NO** (2026-09-08). Texto literal del usuario:

> «el centro no tiene la potestad de encender/apagar módulos, solo el superadmin. al contratar se tiene que ofrecer al administrador del centro su activacion, avisando de su disponibilidad. mediante notificación en el panel de administración del administrador de centro.»

`architect` había recomendado la Opción C (dos columnas, `§3`), argumentando que A borra una capacidad de `REQ-CORE-002` para ahorrar una columna. **La discrepancia queda registrada aquí y la decisión ejecutada**, conforme a `CLAUDE.md §0`. Dos matices que la decisión del usuario aporta y que la recomendación original no había pesado: el aviso al centro cubre la necesidad real —enterarse— que era el fondo de la objeción, y la vuelta atrás A→C es aditiva pura, de modo que la elección no cierra ninguna puerta. El riesgo residual que se acepta está en `§8.1`.

**12.2 · Al contratar, ¿el módulo queda operativo de inmediato o pendiente de que el centro lo acepte? — OPERATIVO DE INMEDIATO** (2026-09-08). Respuesta literal del usuario a la pregunta explícita: **«Efectivo desde ya»**. El aviso al Administrador de Centro es **solo informativo** («ya tienes disponible X»), sin acción requerida por su parte, sin interruptor de su lado y **sin ningún tercer estado intermedio** en el modelo de datos. Es la respuesta que mantiene el esquema en una sola columna booleana (`§4.2`) y la que evita la pregunta de qué se factura mientras nadie acepta.
