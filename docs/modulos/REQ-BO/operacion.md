# REQ-BO · Operación

> Paso **1.6**, dividido en cinco sub-pasos por decisión del usuario del 2026-09-08 (`funcional.md §12`). Complementa `SYSADMIN.md` y `RUNBOOK.md`; aquí sólo lo específico de este módulo.
>
> **La conclusión primero**: a diferencia de `REQ-PERM`, que no añadía ni una variable de entorno, este paso añade **un segundo *host*, un segundo camino de autenticación, variables de entorno nuevas, tres tareas programadas y un procedimiento de arranque manual sin el cual nadie puede entrar al backoffice** (§5). Y ese último punto es el que más fácil se olvida: el sistema **se despliega bloqueado a propósito**.

---

## 1. Comportamiento con el módulo activo o inactivo

**`REQ-BO` no es un módulo activable.** No tiene fila en `modules`, no tiene `module_code` contratable y no se puede descontratar: es la aplicación **desde la que se contratan los demás**. Ninguna de sus rutas lleva el *middleware* `module-enabled`, y `RMOD-008`/`RMOD-009` no aplican a su superficie.

Lo que sí hace es **escribir el dato del que dependen esos dos requisitos**, y por eso `§4` describe la invalidación de caché con más detalle del que parecería necesario.

---

## 2. Variables de entorno

| Variable | Para qué | Nota |
|---|---|---|
| `BO_HOST` | *Host* del backoffice | **Nunca un literal en el código.** `OPEN-08` (dominio de la plataforma) sigue abierta: hasta que se cierre, en desarrollo es un `*.plataforma.test` y en despliegue lo fija Traefik |
| `BO_SESSION_LIFETIME` | Vida de la sesión de plataforma, en minutos (`RN-BO-09`) | **Más corta que la del producto.** El valor concreto es de operación, no de código |
| `BO_REAUTH_WINDOW` | Ventana de reautenticación para operaciones sensibles (`RN-BO-08`) | En minutos. Del orden de una decena |
| `BO_DUAL_AUTH_TTL` | Vida de una solicitud de doble autorización | Corto. Una solicitud de eliminar un centro que sigue viva una semana es una firma pendiente que nadie recuerda |
| `DB_PLATFORM_USERNAME` / `DB_PLATFORM_PASSWORD` | Rol `plataforma_platform` (`ADR-033 §5`) | **Ya existen** desde 0.7. Este paso es el primero que las usa de verdad en un camino de petición HTTP |
| `BO_FLAG_CACHE_TTL` | Vida de una entrada de la caché de evaluación de *flags*, en segundos (§4.3) | Sub-paso `1.6e`. **Es una red de seguridad, no el mecanismo de invalidación**: éste es `rules_version`, y funciona en el acto. El TTL sólo limita cuánto sobreviven las entradas huérfanas de versiones anteriores. Del orden de minutos |

**Variables que deliberadamente no existen**, y conviene dejarlo escrito porque las tres son tentadoras:

| Lo que habría llevado una variable | Por qué no la hay |
|---|---|
| Conmutador para **desactivar la lista blanca de IP** | Es una puerta trasera con nombre de opción de configuración. Se desactivaría «un momento para depurar» y se quedaría así. La forma de operar sin restricción de red es una entrada `0.0.0.0/0` en la tabla: **visible, auditada y con autor** |
| Conmutador para **relajar el MFA** | `REQ-BO-007` dice «sin excepción». Una variable que lo relaja convierte «sin excepción» en «salvo que alguien exporte esto» |
| Conmutador para **saltar la doble autorización en desarrollo** | Es exactamente el mecanismo que acaba en producción. El entorno de desarrollo crea **dos** administradores; cuesta lo mismo y prueba lo que de verdad se va a usar |
| Variable para **forzar el valor de un *feature flag*** (`FEATURE_X=true`) | Es la forma más rápida de tener un *flag* encendido en producción **sin autor, sin motivo y sin auditoría**, y de que su valor real dependa de en qué contenedor caiga la petición. El estado de un *flag* vive en su tabla, con su `reason` y su entrada en `admin_action_logs` (`RN-BO-43`). Para apagarlo ya, está `forced_off`, que es igual de rápido y **sí** deja rastro (`api.md §2.12`) |
| Conmutador para **desactivar el motor de *flags*** | Un motor desactivado tendría que devolver algo, y las dos respuestas posibles son malas: `false` para todo apaga funcionalidades ya entregadas a centros reales, y `true` para todo enciende de golpe lo que está a medias. Si el motor no puede leer sus tablas, la respuesta correcta es la de `RN-BO-35` —falso por defecto— y no hay nada que configurar |

---

## 3. Servicios externos y degradación

| Servicio | Uso | Si no responde |
|---|---|---|
| **PostgreSQL** (`plataforma_platform`) | Todo | El backoffice no sirve. Sin degradación posible ni deseable |
| **PostgreSQL** (`plataforma_app`) | Aprovisionamiento de un tenant nuevo, que entra en su contexto | Ídem |
| **Redis** | Sesión de plataforma (según `OPEN-BO-02`), límite de tasa, y las **cachés que hay que invalidar** (§4), incluida la de evaluación de *flags* | Ver §4: el modo de fallo importa |
| **Correo** | Invitación del primer administrador del centro nuevo, e invitación de un administrador de plataforma | Sigue pendiente `OPEN-09` (proveedor transaccional). En desarrollo, `log`. Los tests comprueban que el trabajo se **encola**, no que el correo llegue |
| **Traefik / ACME** | Enrutado del *host* de plataforma y su certificado | Sin él no hay backoffice. **No es parte de este módulo**: es despliegue, y depende de `OPEN-08` |

**Redis caído: qué pasa exactamente.** La caché de disponibilidad de módulo falla a consultar la base de datos, que es correcto y sólo más lento. Lo que **no** puede ocurrir es lo contrario: que un Redis vacío o desalojado por memoria haga que un módulo no contratado parezca contratado. `EloquentModuleAvailability` falla en cerrado fuera de contexto de tenant y esa propiedad se conserva.

**Lo mismo, y por el mismo motivo, para los *flags***: un Redis vacío hace que el evaluador consulte la base de datos y devuelva **lo mismo** que devolvía, sólo más despacio. Un Redis vacío nunca puede encender un *flag*: la ausencia de entrada en caché no es «expuesto», es «hay que calcularlo», y el cálculo sin reglas da falso (`RN-BO-35`). **Si lo que cae es PostgreSQL, el evaluador tampoco inventa**: sin poder leer las reglas, todo *flag* es falso, que es la dirección segura — se pierde funcionalidad nueva, nunca se enciende la que no estaba.

---

## 4. La invalidación de caché, que es requisito y no mejora

`ADR-045 §8.3` lo marca como **de obligado cumplimiento en 1.6**. Hay **tres** cachés distintas —dos que este paso rompe y una que crea— y confundirlas es el error probable. **Las tres se invalidan de forma diferente, y eso no es una inconsistencia: es que los tres patrones de escritura son distintos** (§4.4).

### 4.1 `tenant-resolution:{slug}` — issue [#7](https://github.com/pirexia/plataforma-educativa/issues/7)

`ResolveTenant` cachea `{id, status}` durante 60 s. Hasta hoy nadie cambiaba `status`; **este paso es el que deja de hacerlo cierto**.

- **Toda** escritura de `tenants.status` la invalida **en la misma operación** (`RN-BO-14`), no en un paso posterior ni en un *listener* que alguien pueda desregistrar.
- El **cambio de `slug`** invalida **dos** claves: la vieja y la nueva.
- Test: un tenant recién suspendido responde `503` **en el mismo segundo**, no en hasta 60 s (`CA-BO-054`).

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
| 1 | Migraciones por `pgsql_owner` | Incluida la de privilegios de `module_subscriptions` (`datos.md §7`) |
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

**Los tres comandos exigen acceso de consola al servidor y los tres escriben en `admin_action_logs`.** Un mecanismo de recuperación sin rastro sería una puerta trasera con otro nombre.

---

## 6. Colas y tareas programadas (`INV-012`)

### 6.1 Trabajos en cola

| Trabajo | Cuándo | Nota |
|---|---|---|
| `ProvisionTenant` | Tras crear un tenant | Los 16 roles, sus concesiones, la configuración y el primer administrador. **Debe completarse en segundos** (nota para el implementador de `REQ-BO-005`): es operación de datos, no despliegue |
| `RunModuleRollout` | Activación masiva | Idempotente por `Idempotency-Key`. Emite **un evento por centro** (`RN-BO-27`) e invalida **el prefijo de cada centro** (§4.2) |
| `CloneTenant` | Clonación | Copia configuración, roles, concesiones y suscripciones. **Nunca personas** (`RN-BO-21`) |

**Los tres entran y salen del contexto de cada tenant con `runFor()`.** `ADR-033 §8` estampa el `tenant_id` en el *payload* de todo trabajo automáticamente — pero **estos trabajos los encola el backoffice, que no tiene tenant**: el tenant afectado viaja como dato del trabajo, no como contexto heredado, y el trabajo entra en él explícitamente. Es la diferencia que hace que `Queue::looping()` no aborte el *worker*.

### 6.2 Tareas programadas

| Tarea | Frecuencia | Qué hace |
|---|---|---|
| `bo:expire-dual-authorizations` | Cada 15 min | Pasa a `caducada` lo vencido. `actor_type = 'system'` |
| `bo:check-grace-periods` | Diaria | Marca los tenants cuyo período de gracia venció y **avisa**. **No borra nada** (`RN-BO-17`) |
| `bo:purge-mfa-challenges` | Cada hora | Desafíos caducados, igual que su homóloga de 1.3 |

**Los *feature flags* no añaden ni un trabajo en cola ni una tarea programada**, y merece una frase porque es lo contrario de lo que se espera de un motor de despliegue progresivo. No hay nada que barrer —el reparto por porcentaje se calcula, no se almacena (`RN-BO-38`)—, nada que caducar —una regla vive hasta que alguien la cambia— y nada que recalcular cuando aparece un centro nuevo, que es justamente la propiedad por la que se eligió una función determinista en vez de una tabla de asignaciones. **Si en la implementación aparece un job de «recalcular cubos» o de «sincronizar exposiciones», el diseño se ha desviado de `funcional.md §5.11.6`** y hay que volver a él, no añadir el job.

> **`bo:check-grace-periods` no borra, y es una decisión.** Una purga automática por temporizador sobre los datos de un centro entero —con datos de menores dentro— no puede depender de que nadie haya olvidado prorrogar el plazo. Marca y avisa; borrar exige una persona, doble autorización y `REQ-PRIV-006` (`funcional.md §2.2`, `OPEN-BO-05`).

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
| «Entro pero no puedo hacer nada» | `mfa_enrolled_at` del administrador | `RN-BO-05`: sin factor confirmado sólo se alcanza `/mfa/*` |
| «Contrato un módulo y el centro sigue viendo 403» | Que la escritura invalide la caché **con el prefijo del tenant** | §4.2. Con un solo tenant en desarrollo esto **parece funcionar** aunque esté mal |
| «Suspendo un centro y sigue entrando» | Que la transición invalide `tenant-resolution:{slug}` | §4.1, issue #7 |
| «No puedo eliminar un tenant de pruebas» | Cuántos `superadministrador` hay | `RN-BO-19`: hacen falta **dos personas**. §5 paso 5 |
| «`platform:sync-registry` aborta el despliegue» | El mensaje: código inexistente o ciclo | `CA-BO-034`/`CA-BO-035`. **Es el comportamiento correcto**: mejor un despliegue detenido que un catálogo con una arista rota |
| «Un centro tiene `M` sin `N` y el sistema no lo arregla» | Ficha de salud del centro | `ADR-045 §4.5`: el comando **informa y no corrige**. Contratar automáticamente sería tomar una decisión comercial facturable sobre 200 centros |
| «La aprobación de una doble autorización falla con `409`» | Si aprobador y solicitante son la misma persona | `RN-BO-19`, y lo rechaza la base de datos, no el controlador |
| «El centro no ve las acciones de plataforma que le afectan» | Que la fila tenga `affected_tenant_id` | `RN-BO-31`. Las de alcance global (`NULL`) **no las ve ningún tenant**, y es correcto |
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

- **Once tablas nuevas entran en la copia de plataforma** (`REQ-BKP-001`), las nueve del chasis más `feature_flags` y `feature_flag_rules`.
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
| `SYSADMIN.md` | El procedimiento de arranque de §5 completo, con el paso 5 (**segundo `superadministrador`**) marcado como obligatorio; los tres comandos de recuperación de §5.1; las variables de §2; el *host* del backoffice en la configuración de Traefik |
| `SECURITY.md` | **Un sujeto de autenticación nuevo en el producto.** Deja de haber un solo tipo de cuenta. Hay que describir: los cuatro roles internos, el MFA incondicional, la lista blanca de IP, la doble autorización y la auditoría de plataforma independiente. Al cerrar `1.6e`, además: **ningún control de seguridad vive detrás de un *feature flag*** (`RN-BO-47`), y un *flag* no concede acceso a datos (`permisos.md §5.3`) |
| `ARCHITECTURE.md` (`1.6e`) | El motor de *feature flags*: catálogo declarado en código, reglas en base de datos, evaluador en `REQ-CORE` y no en `REQ-BO`, y **por qué el reparto por porcentaje es una función determinista y no una tabla** |
| `CONTRIBUTING.md` (`1.6e`) | Cómo se declara un *flag* nuevo en el descriptor de un módulo, y la regla de que **un *flag* se crea escribiendo el código que lo consulta** — no hay pantalla que los cree (`RN-BO-34`). Es la parte que un desarrollador nuevo necesita y que no está en ningún otro sitio |
| `PRIVACY.md` | `platform_admins` es un tratamiento de datos personales **del personal del proveedor**, con base legal propia (relación laboral o de servicio), distinto de los tratamientos en los que somos encargados. Y el acceso del proveedor a los sistemas del cliente queda documentado con su registro |
| `ARCHITECTURE.md` | La segunda superficie HTTP y su frontera, según se resuelva `OPEN-BO-01`. Si sale la Opción B, además un contenedor nuevo en el diagrama |
| `RUNBOOK.md` | §5.1 (salida de un bloqueo total) y §8 (diagnóstico) |
| `CHANGELOG.md` | Una entrada por cada sub-paso cerrado (`CLAUDE.md §6.7`) |
| `docs/manual-usuario/admin.md` | **Nada.** El Administrador de Centro no alcanza el backoffice. Lo que sí cambia en su manual es consecuencia de `ADR-045`: **ya no activa ni desactiva módulos**, sólo los consulta y configura. Es una capacidad que el manual describía y que desaparece |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` | **Ya actualizado** a 3.2.0 por el encargo de `ADR-045 §10`, con su fila de historial |
| `docs/modulos/REQ-CORE/funcional.md` | **Ya actualizado**: §2 y `OPEN-CORE-03` marcados como resueltos, `CA-CORE-061` reforzado |
| `docs/modulos/REQ-CORE/permisos.md` | Pendiente al cerrar `1.6c`: la fila de `modulo.actualizar` debe decir que su alcance es **sólo `settings`**, respaldado por privilegio de columna y no sólo por validación |
| `docs/modulos/REQ-CORE/funcional.md` | **Pendiente al cerrar `1.6e`, y detectado en la revisión del 2026-09-08**: su `§2.3` enumera lo que `1.6` añade a `REQ-CORE` —camino de escritura de módulos, los dos eventos, invalidación de caché— y **no menciona el evaluador de *feature flags***, que esta especificación asigna a `REQ-CORE` y no a `REQ-BO` (`funcional.md §9`). No es una contradicción: `§2.3` se escribió antes de que los *flags* entraran en alcance. Pero si `1.6e` cierra sin añadir esa línea, `REQ-CORE` acaba siendo dueño de un componente que su propia especificación no documenta |
| `docs/modulos/REQ-CORE/api.md` | Pendiente al cerrar `1.6e`: `GET /api/v1/feature-flags` es ruta suya, no de plataforma (`api.md §2.14`). Igual que `GET /api/v1/platform-actions` al cerrar `1.6` |

---

## 11. Reversión (`CLAUDE.md §9`)

| # | Paso | Nota |
|---|---|---|
| 1 | Desplegar la versión anterior | |
| 2 | Retirar el *host* del backoffice de Traefik | Sin él, la superficie deja de existir aunque el código siga desplegado |
| 3 | `down()` de las migraciones, **en orden inverso** | Las de tabla son `DROP` limpios: nada las referencia desde el producto |
| 4 | Restituir los privilegios de `module_subscriptions` | `GRANT UPDATE, INSERT ON module_subscriptions TO plataforma_app` |

**Dos cosas que hay que decir en voz alta sobre esta reversión:**

- **`DROP TABLE admin_action_logs` destruye la auditoría de plataforma.** Es lo único de este módulo cuya pérdida no es recuperable rehaciendo trabajo. Si hay actividad real, **se vuelca antes**, y el volcado se custodia con el mismo cuidado que la tabla.
- **Revertir el paso 4 reabre el agujero que `ADR-045 §4.4` cierra**: el centro vuelve a poder escribir `enabled` si algún día un controlador se lo permite. Es aceptable para un despliegue fallido y **nunca** como forma de «desactivar temporalmente la restricción» con el código nuevo en producción.

**Sobre la reversión de `1.6e` en concreto**: `DROP TABLE feature_flags, feature_flag_rules` deja **todos los *flags* apagados**, porque sin catálogo ni reglas el evaluador devuelve falso (`RN-BO-35`). Es la dirección segura y no hay que hacer nada más: se pierden funcionalidades en despliegue parcial, no se enciende ninguna. Lo que sí se pierde y **no** se recupera rehaciendo trabajo es el registro de **a qué centros se expuso qué y cuándo** — las reglas son esa prueba (`datos.md §13`) —, así que si hay despliegues progresivos vivos se vuelcan antes, igual que `admin_action_logs`.

**Lo que la reversión no puede deshacer**: los módulos ya contratados desde el backoffice se quedan contratados. Es dato de negocio correcto, no residuo — descontratarlos es una operación normal, no una reversión.
