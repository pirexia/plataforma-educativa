# REQ-BO · Backoffice de Super Administrador · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-BO` |
| Prioridad | MUST |
| Fase | 1 · Bloque A · **paso 1.6**, dividido en **cinco sub-pasos** por decisión del usuario del 2026-09-08 (§12) |
| Depende de | `REQ-CORE` (1.1), `REQ-AUTH` (1.2/1.3), `REQ-PERM` (1.5), `ADR-033`, `ADR-034`, `ADR-035`, `ADR-036`, `ADR-038`, `ADR-044`, **`ADR-045`**, **`ADR-046`**, **`ADR-047`** |
| Estado | **`1.6` (chasis): IMPLEMENTADA, cerrada y mezclada a `develop`** — especificación aprobada por el usuario el 2026-09-08 (`ADR-046`/`ADR-047` aplicados, `OPEN-BO-13` resuelta), revisada de forma independiente en dos pasadas (`db-reviewer`/`security-reviewer`/`doc-reviewer`, issues #173-#186), con todos los hallazgos Alta/Media corregidos (§15.1). · **`1.6b` (ciclo de vida de tenants, `REQ-BO-001`): IMPLEMENTADA, cerrada y mezclada a `develop`** el 2026-09-14 (PR [#204](https://github.com/pirexia/plataforma-educativa/pull/204), [#208](https://github.com/pirexia/plataforma-educativa/pull/208), [#212](https://github.com/pirexia/plataforma-educativa/pull/212); §15.2) — añadió `RN-BO-50` a `RN-BO-62`, `CA-BO-106` a `CA-BO-127` y tres preguntas abiertas nuevas (`OPEN-BO-14` a `OPEN-BO-16`), de las cuales **`ADR-048` (2026-09-11) cierra `OPEN-BO-15`** —contrato síncrono `TenantProvisioner` en `REQ-CORE`, dos métodos, sin evento— y con ella un incumplimiento de `INV-007` en la clonación que la especificación no había nombrado (§5.6.2). Cierra los issues [#7](https://github.com/pirexia/plataforma-educativa/issues/7) y [#27](https://github.com/pirexia/plataforma-educativa/issues/27). · **`1.6c` (matriz de módulos, `REQ-BO-002`): IMPLEMENTADA, cerrada y mezclada a `develop`** el 2026-09-16 (PR [#214](https://github.com/pirexia/plataforma-educativa/pull/214); §15.3) — añade `RN-BO-63` a `RN-BO-82`, `CA-BO-128` a `CA-BO-148` y las tres preguntas abiertas `OPEN-BO-17` a `OPEN-BO-19`, las tres resueltas y aplicadas. **Los dieciséis criterios de §13.3 y los veintiuno de §13.3.1 están cubiertos por tests**, 676/676 Pest de la suite completa en verde. Revisión independiente en dos pasadas (`db-reviewer`/`security-reviewer`/`doc-reviewer`) más dos pasos de prueba de `/codex:review` (`ADR-049 §8`): doce issues encontrados, los doce cerrados (detalle completo en §15.3.1). Hallazgo diferencial de Codex, el más relevante: issue [#224](https://github.com/pirexia/plataforma-educativa/issues/224) (Alta) — una descontratación masiva aprobada podía ejecutarse pese a que la autorización acabara `Fallida`, por un `dispatch()` de cola dentro de una transacción con `after_commit=false`; ningún revisor humano lo había visto. · **`1.6d` (salud y métricas, `REQ-BO-004`/`REQ-BO-006` reducidos): IMPLEMENTADA, PR [#229](https://github.com/pirexia/plataforma-educativa/pull/229) en revisión independiente antes de mezclar** (especificación aprobada el 2026-09-16, §15.4) — añade `RN-BO-83` a `RN-BO-98`, `CA-BO-149` a `CA-BO-166` y cuatro preguntas abiertas nuevas (`OPEN-BO-20` a `OPEN-BO-23`), **las cuatro resueltas el mismo día**, y **sin una sola migración, tabla ni columna nueva**. Corrige una afirmación falsa de §5.9 —los trabajos en cola **no** salen de Horizon, que no está instalado, lo que llevó a corregir `CLAUDE.md §1` a la versión 2.5.2— y declara tres hallazgos de privilegios anteriores al sub-paso. **El de severidad Alta entra en su alcance por decisión del usuario**: `queue:prune-failed` llevaba desde `0.7` sin poder borrar nada, y con él la segunda capa del issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73) estaba documentada y no aplicada; lo sustituye **`bo:purge-failed-jobs`** (implementado y verificado el 2026-09-21), única tarea programada que añade el sub-paso (§5.9.7). 32/32 Pest del fichero de este sub-paso en verde, suite completa verificada en verde de forma independiente. Revisión con `/codex:review` (`ADR-049 §8`, tercer paso de prueba): 3 hallazgos P2 de validación de parámetros de consulta, los tres aceptados y corregidos. Revisión independiente (`db-reviewer`/`security-reviewer`/`doc-reviewer`): sin hallazgos Crítico/Alto en código; hallazgos de índice (Media, deuda declarada, no bloqueante) y de documentación (correcciones cruzadas a `REQ-AUTH`/`REQ-CORE`/`RUNBOOK.md` y vigencia de documentos raíz, corregidas en esta misma sesión). Detalle completo en `CHANGELOG.md`. · `1.6e`: sin empezar |
| Módulo (código) | `bo` · `apps/api/app/Modules/Backoffice` · frontend **`apps/backoffice`**, SPA propia sin *bundle* compartido con `apps/web` (`ADR-046 §4.1`), **construida en el paso de interfaz posterior a `1.7`/`1.9`** (§12.5) |

> Fuente de verdad: sección 5.51 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` (`REQ-BO-001` a `REQ-BO-007`), más `RMOD-002`/`RMOD-006`, `RMT-007`, `REQ-CORE-001` y la sección 11.1. Desde el 2026-09-08, y sólo para `REQ-BO-005` puntos 1-2, también **`REQ-OPS-002`** (sección 5.49) y **`RARQ-DEP-010`** (sección 8).
> Entradas obligatorias: **`ADR-045`**, cuyo `§10` ya se ha ejecutado sobre el documento de requisitos (versión 3.2.0) y sobre `docs/modulos/REQ-CORE/funcional.md §2`; **`ADR-046`** (ACEPTADA, 2026-09-08), que resuelve `OPEN-BO-01`, `OPEN-BO-02` y `OPEN-BO-03` y cuyo `§10` está aplicado a los cinco ficheros de este módulo; y **`ADR-047`** (ACEPTADA, 2026-09-08), que resuelve `OPEN-BO-10`, crea la categoría «plataforma con visibilidad por tenant afectado» y fija la convención de nombres `affected_tenant_id` para todo el proyecto.
> Este documento **no reabre** `ADR-033`, `ADR-034`, `ADR-036`, `ADR-044`, `ADR-045`, `ADR-046` ni `ADR-047`.

---

## 0. Lo primero, porque condiciona todo lo demás

Tres afirmaciones que hay que leer antes que nada:

1. **`REQ-BO` es un módulo de fase 1 cuyos siete sub-requisitos citan cuatro módulos de fase 2.** `REQ-BO-003` cuelga entero de `REQ-SAAS` (fase 2); la impersonación de `REQ-BO-004` es `REQ-SUP-003` (fase 2); los *feature flags*, las ventanas de mantenimiento y la exportación de `REQ-BO-005`/`REQ-BO-001` son `REQ-OPS-002`/`REQ-OPS-001`/`REQ-OPS-004` (fase 2, `SHOULD`); y las métricas de ingresos y *churn* de `REQ-BO-006` son `REQ-SAAS-004`. El propio encabezado de §5.51 lo dice: `REQ-BO` «consolida capacidades que hasta ahora estaban repartidas entre `REQ-CORE-001`, `RMOD-002`, `REQ-SAAS`, `REQ-SUP` y `REQ-OPS`». **Consolidar la pantalla no adelanta el motor.** §2 lo desglosa requisito a requisito.

   **De esos cuatro, el usuario ha decidido el 2026-09-08 adelantar exactamente uno**: el motor de *feature flags* de `REQ-OPS-002` (`REQ-BO-005`, puntos 1-2), por el motivo que esta misma especificación daba al señalar su exclusión como «menos firme que las anteriores» — es el único cuya construcción **no depende de ningún módulo que falte**. Entra en `§2.1`, se especifica en `§5.11` y ocupa un sub-paso propio, `1.6e` (`§12.2`). Todo lo demás sigue fuera, y `§2.2` mantiene el motivo de cada exclusión.

2. **Nada de la identidad de plataforma existe.** Verificado sobre el código (§1): no hay `platform_admins`, no hay `admin_action_logs`, hay **un solo *guard*** (`web`), no hay ningún almacén de sesión que sirva a un sujeto sin tenant, y todo el MFA de 1.3/1.3b está atado a `TenantContext::tenantId()`. El backoffice no es «una pantalla más»: es un segundo sujeto de autenticación, con su propia autorización, su propia auditoría y su propio segundo factor.

   > **Corrección de una premisa falsa de la revisión anterior de este documento**, señalada por `ADR-046 §1.1` y `§11.2`: esta especificación afirmaba que `sessions` es «tabla de tenant» y que lleva `tenant_id`. **Es falso.** `sessions` se crea en `0001_01_01_000000_create_users_table.php` con `{id, user_id, ip_address, user_agent, payload, last_activity}`, ninguna migración posterior la altera, y está declarada en `config/tenancy.php` bajo `shared_tables.framework`: **sin `tenant_id` y sin RLS**. El endurecimiento de esa tabla es el issue [#81](https://github.com/pirexia/plataforma-educativa/issues/81), **abierto**. Todo lo que este documento decía apoyándose en esa columna queda sustituido por el diseño de `platform_sessions` que fija `ADR-046 §5.2` (§1.2, §5.1, §14).

3. **La separación de la aplicación —«dominio propio, autenticación propia»— la decide `ADR-046`, no esta especificación.** §3 conserva la evaluación de opciones y sus costes porque sigue siendo el razonamiento que sostiene la decisión, pero el resultado ya no está abierto: **Opción A** —mismo monolito, *guard* propio, grupo de rutas propio y SPA propia— **con cinco condiciones vinculantes** (`ADR-046 §4`), de las cuales la más importante no es código: **Traefik tiene que enrutar por `Host()`**, cosa que hoy no hace. `datos.md`, `api.md` y `operacion.md` ya **no** están escritos para ser válidos bajo dos opciones: están escritos sobre la Opción A.

---

## 1. Estado real del código, verificado y no supuesto

Verificado el 2026-09-08 sobre `feature/REQ-BO-1.6-backoffice-superadmin` (un *commit* de `ADR-045` sobre `develop` en `b95be70`), y **reverificado el mismo día por `ADR-046 §1.1`** sobre esa misma rama en `2993581`. De esa reverificación salieron **dos correcciones a este documento**: la premisa falsa sobre `sessions` (§0 punto 2, §1.2) y el recuento de llamadores de `runAsPlatform()` (§6.2).

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
| Almacén de sesión para un sujeto sin tenant | `config/session.php` usa el *driver* `database` sobre `sessions`. **Verificado (`ADR-046 §1.1`): `sessions` NO tiene `tenant_id`**, está en `shared_tables.framework` sin RLS, y **`plataforma_app` puede leer todas sus filas** —debilidad preexistente y aceptada, issue [#81](https://github.com/pirexia/plataforma-educativa/issues/81), abierto—. El vínculo sesión↔tenant lo producen hoy la cookie *host-only*, la clave `pge_tenant_id` del *payload* y el *middleware* `VerifySessionTenant`, no una columna | **`ADR-046 §5` decide: tabla propia `platform_sessions`**, tabla de plataforma, con `REVOKE ALL … FROM plataforma_app` en su migración. El motivo no es «no debilitar una columna» —esa columna no existe— sino **no heredar el issue #81** en la superficie más sensible del producto: en el *driver* `database`, `sessions.id` **es** el identificador de sesión, y dejar ahí los de plataforma sería una escalada de tenant a backoffice que no pasa por la autenticación (§5.1, `datos.md §2.6`) |
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

## 3. La separación de la aplicación: opciones, costes y la decisión de `ADR-046`

> **Estado de esta sección: cerrada.** La escribí evaluando opciones y parando en `OPEN-BO-01`, porque una de ellas exigía tocar `ADR-002`. **`ADR-046` (ACEPTADA, 2026-09-08) decide la Opción A con cinco condiciones vinculantes**, ratifica en el fondo la lectura que yo ofrecía como insumo y la **corrige en la forma**: mis dos condiciones eran necesarias pero **no suficientes**, porque les faltaba el enrutado por *host*, que es justo el punto donde la Opción A se cae. §3.1 a §3.3 conservan el razonamiento —sigue siendo lo que sostiene la decisión— y **§3.4 recoge lo decidido**, que es lo vinculante.

`REQ-BO` dice literalmente: «Aplicación **separada del producto** que usan los centros, con su propio dominio, su propia autenticación y sus propios roles. Un usuario de un tenant nunca puede alcanzar este backoffice, ni siquiera con el rol máximo de su centro.»

Eso fija tres propiedades **obligatorias** —dominio propio, autenticación propia, roles propios— y **no fija** el diseño técnico que las produce. Lo que sigue es lo que el código y los ADR ya imponen, las opciones que quedan vivas, y la pregunta.

### 3.1 Lo que ya está decidido y no se discute

| Restricción | Origen | Consecuencia |
|-------------|--------|--------------|
| `ResolveTenant` resuelve por *host* y lanza `TenantNotResolved` → 404 si el *host* no es de ningún centro | `ADR-033 §2` | El dominio del backoffice **no resuelve ningún tenant**. Sus rutas no pueden pasar por ese grupo: necesitan pila de *middleware* propia. Esto es cierto en **todas** las opciones |
| Un grupo de rutas fuera del tenant **no es una excepción nueva**: ya existen `/api/health` y `/api/_sso-simulator/*` | Código, verificado en `ADR-046 §1.1` | Declarar `/api/platform/v1` con pila propia y explícita es **el patrón vigente**, no una desviación |
| **Traefik enruta hoy sólo por `PathPrefix`, sin `Host()`** | `infra/quadlet/web.container`, `api@.container`, verificado en `ADR-046 §1.1` | **Cualquier *host* que llegue al proxy alcanza el mismo contenedor de API**, incluido `centroa.dominio/api/…`. Sin `Host()` en las reglas, un grupo de rutas de plataforma sería alcanzable desde el *host* de un centro. Es lo que hace que la condición 1 de §3.4 no sea una recomendación |
| Cookie de sesión ***host-only***, sin dominio principal | `ADR-033 §2` | La sesión del backoffice queda ligada a su *host* por construcción. Una cookie de `centroa.dominio` **no puede** viajar al dominio del backoffice, ni al revés. `RMT-009` se cumple solo |
| El backoffice escribe por `plataforma_platform` (`BYPASSRLS`), credenciales separadas | `ADR-033 §5` | «Encaja con que `REQ-BO` sea una aplicación aparte», dice el propio ADR |
| Monolito modular hasta la fase 3 | `ADR-002` | Un servicio desplegable independiente **contradice** una decisión vigente y exigiría ADR nuevo |
| El *frontend* no hace de proxy; Traefik enruta `/api/*` bajo el mismo *host* | `ADR-028` | Sea cual sea la opción, el enrutado es de Traefik, no de la SPA |
| El dominio real de la plataforma **no está decidido** | `OPEN-08`, `ADR-038 §6.2` | Ningún nombre de *host* concreto puede escribirse hoy. Se parametriza por variable de entorno |

### 3.2 Las opciones que quedan vivas

**Opción A · Mismo monolito, segundo *guard*, segundo grupo de rutas, SPA propia.** — **la elegida** (§3.4)
`platform` como *guard* de sesión sobre el modelo `PlatformAdmin`; grupo de rutas `/api/platform/v1` sin `ResolveTenant` y con su propia pila; una segunda aplicación Vite (`apps/backoffice`) con su propio `dist`, enrutada por Traefik al mismo contenedor de API bajo el *host* de plataforma.
*Coste*: bajo. *Riesgo*: el aislamiento entre las dos superficies depende de la configuración de rutas y de que ningún *middleware* se cuele; hay que probarlo, no confiarlo — y es exactamente lo que hacen las cuatro aserciones de `CA-BO-011` y `CA-BO-013` a `CA-BO-015`.
*Compatible con* `ADR-002` sin tocarlo.

**Opción B · Aplicación desplegable independiente** (`apps/backoffice-api`), compartiendo únicamente la base de datos por el rol `plataforma_platform`. — **descartada** (`ADR-046 §9`)
*Coste*: alto — segundo contenedor, segundo ciclo de *release*, segunda configuración, duplicación de `App\Support` o extracción a un paquete compartido.
*Beneficio real*: el aislamiento deja de depender de la disciplina de enrutado y pasa a ser una propiedad del despliegue; la lista blanca de IP se aplica en el *ingress* y no en la aplicación. **Ese beneficio es genuino y `ADR-046 §3` lo reconoce antes de descartarlo** — pero se compra más barato en el sitio correcto, con `Host()` e `ipallowlist` en Traefik (§3.4, condiciones 2 y 3).
*Requiere ADR* que amplíe o excepcione `ADR-002`. **Y rompe `RN-BO-22`**: la resolución de dependencias de módulos vive en un servicio de dominio de `REQ-CORE` y un backoffice desplegado aparte o la duplica —dos implementaciones de una regla facturable— o la alcanza por HTTP, inventando el primer microservicio del proyecto para servir a la pantalla de administración interna. Ese, y no el coste, es el argumento que decide (`ADR-046 §7.1`).

**Opción C · Misma SPA con un «modo backoffice».** **Descartada, y sí decido esto**, porque contradice el requisito de forma directa: una sola aplicación servida en los dos dominios significa que el *bundle* del backoffice viaja al navegador de cualquier usuario de cualquier centro. «Un usuario de un tenant nunca puede alcanzar este backoffice» dejaría de ser una propiedad y pasaría a ser una condición en tiempo de ejecución. Se descarta por incompatibilidad con el requisito, no por preferencia.

### 3.3 Por qué paré aquí, y qué aportó pararme

Entre A y B hay una diferencia de **naturaleza**, no de detalle: B toca `ADR-002`, que es una decisión arquitectónica vigente. `CLAUDE.md §11` dice que no se cambia una decisión de un ADR sin un ADR nuevo, y `CLAUDE.md §0` que no se rellenan huecos con suposiciones. Además, la elección arrastraba a `OPEN-BO-02` (dónde vive la sesión del backoffice) y a cómo se aplica la lista blanca de IP.

Mi lectura, ofrecida entonces como insumo y no como decisión, fue **A** con dos condiciones: lista blanca de IP también en Traefik, y un test de arquitectura que falle si una ruta de plataforma aparece bajo el grupo de tenant o al revés. `ADR-046 §4` la ratifica en el fondo **y la corrige en la forma**: esas dos condiciones son necesarias pero **no suficientes**, porque no cubrían el enrutado por *host* —y sin él, `centroa.dominio/api/platform/…` alcanza el mismo contenedor y la única barrera restante es la autenticación, que es exactamente el defecto por el que se descartó la Opción C—.

### 3.4 La decisión (`ADR-046 §4`): **Opción A con cinco condiciones vinculantes**

Las cinco son **parte de la decisión, no glosa**: sin ellas, `ADR-046` deja escrito que la decisión no se sostiene.

| # | Condición | Dónde se cumple |
|---|---|---|
| 1 | **Superficie de aplicación propia.** Módulo `App\Modules\Backoffice` sujeto a `INV-007` como cualquier otro; *guard* de sesión `platform` sobre el *provider* `platform_admins` y el modelo `PlatformAdmin`, **sin `tenant_id`** (`RN-BO-01`); grupo de rutas `/api/platform/v1`, hermano de `/api/v1`, **con pila de *middleware* declarada de forma explícita y completa**, sin `resolve-tenant`, sin `verify-session-tenant` y sin `require-mfa-enrollment`; SPA propia en `apps/backoffice`, proyecto Vite independiente que **no comparte *bundle*** con `apps/web` —esto fija **dónde vive**; su construcción es del paso de interfaz, posterior a `1.7`/`1.9` (§12.5)—. El *guard* `web` no cambia | `api.md §0`, `§1` y `§1.1`; `permisos.md §5` |
| 2 | **Traefik enruta por `Host()`.** Las reglas de `web.container` y `api@.container` pasan de `PathPrefix(...)` a `Host(...) && PathPrefix(...)`, y se añaden los *routers* del backoffice bajo su propio `Host()` —el de `/api/platform` en `1.6`; el de la SPA cuando exista la SPA, en el paso de interfaz (`operacion.md §0.2`)—. **Sin esto la separación no existe a nivel de red**, y `plataforma-web`, con su `PathPrefix(/)` de prioridad 1, serviría la SPA de los centros bajo el *host* del backoffice (`ADR-046 §4.6`) | `operacion.md §0.1` y `§0.2` |
| 3 | **Lista blanca de IP también en el *ingress***: *middleware* `ipallowlist` de Traefik sobre los *routers* del backoffice, **además** de `RN-BO-06`/`RN-BO-07` en la aplicación. Ninguna de las dos capas sustituye a la otra. Con el aviso de `forwardedHeaders.trustedIPs` que `operacion.md §0.3` recoge y que es donde una lista blanca se vuelve decorativa **sin dar ningún síntoma** | `operacion.md §0.3` |
| 4 | **La aplicación vuelve a comprobar el *host***, porque la configuración del proxy no la cubre la suite de tests y la aplicación sí. Dos *middleware*, los dos primeros de la pila: `RequirePlatformHost` (`RN-BO-48`) y `EnforcePlatformIpAllowlist` (`RN-BO-06`/`RN-BO-07`). Y el *host* del backoffice **no puede ser subdominio de `TENANCY_BASE_DOMAIN`** (`RN-BO-49`) | `RN-BO-48`, `RN-BO-49`; `api.md §1.1`; `operacion.md §2` |
| 5 | **Test de arquitectura sobre `Route::getRoutes()`, no sobre el texto de los ficheros.** Cuatro aserciones (`ADR-046 §4.5`), en el espíritu de `ADR-033 §10`: nada de plataforma lleva los tres *middleware* de tenant; **toda** ruta de plataforma lleva la pila completa **y en orden**; ninguna ruta de tenant ni del grupo `web` usa el *guard* `platform`; ninguna ruta fuera de `/api/platform/*` apunta a un controlador de `App\Modules\Backoffice` | `CA-BO-011`, `CA-BO-013`, `CA-BO-014`, `CA-BO-015` |

**Lo que sigue siendo compartido, y es correcto** (`ADR-046 §4.2`): el backoffice consume `App\Support` (`Tenancy`, `Audit`, `Api`) y los **servicios de dominio públicos** de `REQ-CORE`, empezando por la resolución de dependencias de módulos de `RMOD-006`/`RN-BO-22`. Eso no es acoplamiento indebido: es `INV-007` bien aplicado entre dos *bounded contexts* del mismo monolito, y es literalmente la razón por la que la Opción B pierde — un backoffice desplegado aparte o duplica esa regla facturable o la alcanza por HTTP.

**Riesgo residual, dicho en voz alta y no eliminado** (`ADR-046 §8`): en la Opción A, un error de configuración de rutas expone el backoffice donde no debe, cosa que en B no pasaría. Lo mitigan la condición 5 —que lo convierte en *build* roto— y las condiciones 2 y 3, que ponen la primera barrera fuera del código. No lo eliminan.

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

0. **Antes que la aplicación, la red.** Traefik enruta por `Host()` y aplica su *middleware* `ipallowlist` sobre los *routers* del backoffice (§3.4, condiciones 2 y 3). Una petición al *host* de un centro **nunca** llega a este grupo de rutas.
1. **`RequirePlatformHost`**, primer *middleware* de la pila: si el *host* de la petición no coincide con `BACKOFFICE_HOST`, **`404`**, antes de sesión y antes de credenciales. Mismo criterio que `ResolveTenant` con un *host* desconocido: **no se revela que la superficie existe** (`RN-BO-48`, `CA-BO-016`). La comparación es contra `BACKOFFICE_HOST`, nunca contra «lo que no resuelve tenant».
2. **`EnforcePlatformIpAllowlist`**: si la dirección de origen no está contenida en ninguna entrada activa, `403` y **entrada en `admin_action_logs`** con `action = 'acceso.rechazado_por_ip'` y sin revelar nada más (`RN-BO-06`, `RN-BO-07`).
3. Credenciales (`POST /auth/session`). Contraseña verificada contra `platform_admins.password`. Mismos límites de tasa y bloqueo por intentos que `REQ-AUTH-001`, con su propio contador.
4. **Segundo factor, siempre.** No hay período de gracia, no hay exención y no existe el equivalente a `user_mfa_exemptions` de 1.3b (§7.2).
5. Sesión emitida con vida corta (`RN-BO-09`), sobre **`platform_sessions`** y con **cookie de nombre propio**, *host-only*, `httpOnly`, `Secure`, `SameSite`, con CSRF (`ADR-025`). El almacén se selecciona **por grupo de rutas**, con un *middleware* que fija la configuración de sesión de plataforma **antes** de `start-session` — mismo patrón que `TenantContext::applyCachePrefix()` con `cache.prefix` y `Cache::forgetDriver()`, probado desde `0.7` (`ADR-046 §5.2`, `datos.md §2.6`).
6. Toda la secuencia queda auditada: intento, éxito o fallo, IP, *user-agent* y `request_id`.

**Un usuario de tenant que llegue al dominio del backoffice** no encuentra ningún *endpoint* que acepte su cookie, y ahora por **cuatro** barreras independientes en vez de una: `Host()` en Traefik, lista blanca de IP en el *ingress*, `RequirePlatformHost` en la aplicación, y *guard*, cookie y **tabla de sesión** distintos. La cookie es *host-only* y no viaja, el *guard* es otro, el *provider* apunta a otra tabla y el almacén de sesión es otro. El intento se audita (`CA-BO-002`), que es lo que exige el cuarto criterio de aceptación de §5.51.

### 5.2 Reautenticación para operaciones sensibles (`REQ-BO-007`)

Las operaciones marcadas como sensibles (`api.md §4`) exigen que la sesión haya reautenticado —contraseña **y** segundo factor— dentro de una ventana corta. Se guarda como marca de tiempo **en la sesión**, no en una tabla: es estado de sesión, muere con ella, y una tabla añadiría un ciclo de vida que limpiar sin aportar nada. Si la marca falta o ha caducado: `403` con `urn:pge:error:reauthentication-required`, que la interfaz distingue de un `forbidden` sin analizar texto.

### 5.3 Alta de un tenant (`REQ-BO-001`) · sub-paso `1.6b`

Es **una operación de datos, no un despliegue** (nota para el implementador de `REQ-BO-005`), y debe completarse en segundos.

#### 5.3.1 Qué recibe el *endpoint*, y qué **no** recibe aunque el requisito lo nombre

`REQ-BO-001` describe el alta como «aprovisionamiento completo desde el panel (datos del centro, `slug`, **dominio**, **plan**, idiomas, **etapas y su régimen jurídico**), que dispara el asistente de *onboarding* (`REQ-ONB-001`)». **Cuatro de esas cosas no existen todavía**, y el alta de `1.6b` no las inventa:

| Campo del requisito | En `1.6b` |
|---|---|
| Nombre, `slug`, idiomas activos y por defecto, zona horaria, moneda, CCAA | ✅ Entran. Los cinco últimos van a `tenant_settings` (`REQ-CORE`), que es donde viven (`datos.md §7` de `REQ-CORE`) |
| Datos del primer Administrador de Centro (correo, nombre, apellidos) | ✅ Entran. Sin ellos el centro nace sin nadie que pueda entrar |
| **Dominio propio** | ❌ No existe el dominio personalizado (`OPEN-08`, `REQ-CORE/funcional.md §1.2`). El centro se alcanza por su `slug` bajo `TENANCY_BASE_DOMAIN` |
| **Plan** | ❌ No existe `plans` (§1.3). Es `REQ-SAAS-001`, fase 2 |
| **Etapas y su régimen jurídico** | ❌ Las etapas son `REQ-ACAD`, paso **1.11**, y el régimen es atributo **de la etapa** (`ADR-020`) |
| **Asistente de *onboarding*** | ❌ `REQ-ONB-001` es el paso **1.24**. `1.6b` entrega la operación, no el asistente, y **no encola nada que lo simule** |

**Motivo obligatorio también en el alta.** `RN-BO-13` exige motivo en **toda** transición y `tenant_lifecycle_events.reason` es `NOT NULL` (`datos.md §5.2`): el alta escribe la primera fila de esa tabla, luego `reason` es obligatorio en el cuerpo y `422` si falta o va vacío. No es burocracia: es la única forma de que meses después se sepa por qué existe un centro que nadie recuerda haber dado de alta.

#### 5.3.2 Validación, completa

1. `slug`: formato de etiqueta DNS (`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`), **único entre los tenants vivos** —la unicidad es parcial, `WHERE deleted_at IS NULL` (verificado: `2026_08_18_100800_partial_unique_tenants_slug.php`)—, `422` con `bo.tenant.slug_taken` si está ocupado.
2. **`slug` distinto de la etiqueta del *host* de plataforma**, `422` con `bo.tenant.slug_reserved` (`RN-BO-49`, `CA-BO-017`).
3. Idioma por defecto contenido en los activos; todos ellos entre los cuatro de `ADR-021`.
4. Zona horaria IANA; moneda ISO 4217; CCAA del catálogo de `REQ-CORE`.
5. Correo del primer administrador con formato válido. **No se comprueba contra ningún otro tenant**: la misma persona puede administrar dos centros con dos cuentas independientes, que es literalmente `RMT-009`.

#### 5.3.3 El alta ocurre en **dos fases**, y esto resuelve una incoherencia entre dos ficheros de esta especificación

La revisión anterior decía, en este mismo sitio, que el alta hacía todo «en una transacción por `pgsql_platform`», mientras que `operacion.md §6.1` declaraba `ProvisionTenant` como **trabajo en cola** y `§7` le ponía una alarma de duración. **Son dos diseños distintos y no pueden convivir.** `1.6b` resuelve la incoherencia a favor del trabajo en cola, por tres motivos y no por gusto:

- **`INV-012`**: el aprovisionamiento escribe 16 roles, su matriz de concesiones, la configuración, una `Person`, un `User` y una invitación. Es un lote, y los lotes no van en el ciclo de petición.
- **El estado `en_alta` existe exactamente para esta ventana.** Si el alta fuera atómica y síncrona, `en_alta` sería un estado por el que nada pasa nunca — y `REQ-BO-001` lo pone el primero de su máquina de estados.
- **El reintento es gratis.** `ProvisionTenantDefaults` ya es idempotente (verificado: comprueba la existencia de `TenantSetting` antes de escribir nada), así que un trabajo que falla a medias se vuelve a lanzar sin efectos duplicados.

| Fase | Quién | Qué hace |
|---|---|---|
| **1 · síncrona, dentro de la petición** | El *endpoint*, con `runAsPlatform(BackofficeEscritura, …)` | Valida; inserta la fila de `tenants` con `status = 'en_alta'`; escribe `tenant_lifecycle_events` (`from_status = NULL`, `to_status = 'en_alta'`) y `admin_action_logs` (`tenant.creado`); **invalida `tenant-resolution:{slug}`** (§6.3); encola `ProvisionTenant`. Responde **`201`** con el tenant en `en_alta` |
| **2 · en cola** | `ProvisionTenant` | Entra en el contexto del tenant con `runFor()`, llama al aprovisionamiento de `REQ-CORE` (§5.3.4), y al terminar sin error escribe la transición `en_alta` → `activo`, su fila de auditoría (`tenant.actualizado`, actor `system`) y **vuelve a invalidar `tenant-resolution:{slug}`** |

**La respuesta `201` no miente**: el recurso existe, y su `status` dice `en_alta`. El cliente que quiera esperar consulta la ficha; no hay *polling* obligatorio porque la fase 2 dura segundos.

#### 5.3.4 `REQ-BO` **no** aprovisiona: se lo pide a `REQ-CORE` (`INV-007`) · **el mecanismo lo fija `ADR-048`**

El aprovisionamiento vive hoy en `App\Modules\Core` (`ProvisionTenantDefaults`, invocado por `tenant:provision-defaults`). **El backoffice no puede importarlo**: `INV-007` prohíbe que un módulo use código interno de otro. Se consume por **interfaz pública de `REQ-CORE`**, exactamente como `ADR-045 §4.8` obligó a que la contratación de módulos y sus eventos vivieran en `REQ-CORE` y no aquí.

> **`ADR-048` (2026-09-11) decide el mecanismo concreto y con ello `OPEN-BO-15`**: **contrato síncrono declarado en `Core\Domain`**, no evento emitido por `REQ-BO`. El evento se descarta por tres motivos de fondo —no devuelve resultado ni propaga fallo, y §5.3.3/§5.3.5 necesitan las dos cosas; el reintento obligaría a reemitir un hecho falso; y el vocabulario de `REQ-CORE` acabaría viviendo en una clase de `Backoffice`—. Lo que sigue es lo que hay que implementar, no una opción entre varias.

**El contrato** (`ADR-048 §4.1`, `§4.2`, `§4.3`):

```php
namespace App\Modules\Core\Domain;

interface TenantProvisioner
{
    public function provision(Tenant $t, TenantInitialSettings $s, TenantAdministrator $a): TenantProvisioningOutcome;
    public function provisionFromTemplate(Tenant $source, Tenant $target, TenantAdministrator $a): TenantProvisioningOutcome;
}
```

`TenantInitialSettings` y `TenantAdministrator` son objetos de valor de `Core\Domain` — **no ocho parámetros sueltos**, porque la firma va a crecer (§5.3.1 ya nombra cuatro campos que llegarán) y con objetos de valor cada llegada es una propiedad nueva y no una rotura para los tres llamadores. `TenantProvisioningOutcome` tiene dos casos, `Provisioned` y `AlreadyProvisioned`: hace **observable** la idempotencia que `CA-BO-108` tiene que demostrar y que hoy es silenciosa. **El fallo viaja como excepción, no como un tercer caso**, para que no se pueda ignorar por descuido.

Cuatro consecuencias que el implementador tiene que comprobar **antes** de escribir nada, y no después:

1. **La firma actual no basta.** Hoy es `provision(Tenant $tenant, string $adminEmail, string $adminGivenName, string $adminFamilyName): void` y crea un `TenantSetting` **vacío**. El alta de `1.6b` trae idiomas, zona horaria, moneda y CCAA, así que la interfaz pública tiene que aceptarlos y escribirlos en `tenant_settings`. **Ampliar esa superficie pública es trabajo de `1.6b`** y se declara como tal en `REQ-CORE` (`ADR-048 §10` enumera exactamente qué se toca), no se cuela como un `use` más.
2. **El comando de consola se conserva y pasa a llamar a la misma interfaz.** Es el camino de arranque del primer tenant y del entorno de desarrollo (`operacion.md §5`), y desde `1.6b` escribe en el mismo registro con `actor_type = 'console'`. Dos caminos, una sola implementación — el mismo criterio con el que `RN-BO-22` exige una sola implementación de las dependencias de módulo. Gana **opciones nuevas** para los ajustes iniciales, cada una con el valor por defecto que ya tiene su columna, de modo que arrancar un centro por consola no exige teclear nada nuevo.
3. **`ProvisionTenantDefaults` no se mueve ni se renombra** (`ADR-048 §4.4`). Pasa a `implements TenantProvisioner` y se enlaza en `CoreServiceProvider`. La asimetría con las cinco implementaciones `Eloquent*` de `Core\Infrastructure` es deliberada y está escrita para que nadie la «arregle».
4. **Quién valida qué** (`ADR-048 §4.7`): el `422` con clave de traducción es de **`REQ-BO`**, en su `FormRequest` (§5.3.2). `REQ-CORE` comprueba **coherencia** —idioma por defecto contenido en los activos, zona IANA, moneda `^[A-Z]{3}$`, CCAA del catálogo— y lanza `InvalidArgumentException`, **sin clave de traducción**: llegar ahí con un valor inválido es un defecto de programación, no un error del operador. `Core\Domain\AutonomousCommunity::CODES` es superficie pública y `REQ-BO` lo importa para validar, en vez de duplicar diecinueve códigos.

**`1.6b` no emite ningún evento de dominio nuevo** (`ADR-048 §4.6`). El hecho «este tenant ya está aprovisionado» ya tiene dos registros duraderos —la fila `en_alta` → `activo` de `tenant_lifecycle_events` y la de `admin_action_logs`—, y un `TenantProvisioned` sin oyente sería una tercera fuente de verdad del mismo hecho. Cuando `REQ-ONB` (1.24) lo necesite, añadirlo es una línea en `REQ-CORE` y no necesita ADR.

**Un efecto lateral que es un arreglo, no un extra** (`ADR-048 §5.1`): `createAdministrator()` escribe hoy `locale = 'es-ES'` literal, y como `IssueUserInvitation` sólo consulta `defaultLocale()` cuando ese campo es nulo, **la invitación del primer administrador sale siempre en español, aunque el centro sea alemán**. Con la configuración ya disponible, el idioma del primer administrador pasa a ser el del centro. **Orden obligatorio dentro de la transacción**: `tenant_settings` se escribe **antes** que la `Person` y **antes** que la invitación; al revés, el correo vuelve a salir en español sin que nada falle visiblemente.

**Esto convierte en *endpoint* lo que hoy es un comando de consola**, que es exactamente lo que `REQ-CORE/funcional.md §1.1` difirió a este paso: *«exponer el alta de tenants por HTTP antes de que exista el backoffice y su registro de auditoría de plataforma sería crear una operación crítica sin trazabilidad»*. Esa trazabilidad es lo que este paso construye.

#### 5.3.5 Qué pasa si la fase 2 falla, que es la pregunta que nadie hace hasta que pasa

Un tenant se queda en `en_alta` con la configuración a medias. **No hay transición a la que ir**: `RN-BO-12` no admite `en_alta` → nada salvo `activo`, y no se inventa un sexto estado para un caso de operación (`ADR-034 OPEN-13`). Lo que hace `1.6b`:

- El trabajo agota sus reintentos y queda en `failed_jobs`, donde ya es visible por la ficha de salud del centro (§5.9). **Esta frase es inexacta y `1.6d` lo descubrió**: el trabajo lo despacha el backoffice **sin tenant activo**, así que su `payload.tenant_id` es nulo y **no aparece bajo el filtro del centro**. Lo que sí lo hace visible en la ficha es la entrada de auditoría del punto siguiente, que sí lleva `affected_tenant_id`. No se reescribe aquí porque la salida de fondo está sujeta a `OPEN-BO-20` (§5.9.3).
- Se escribe **una** entrada en `admin_action_logs` con `action = 'tenant.aprovisionamiento_fallido'`, `actor_type = 'system'` y el error en `context`. **Es un valor nuevo del vocabulario cerrado** y entra por migración (`datos.md §4.2`), como hizo el issue #173 con los dos de invitación.
- **No** se escribe fila en `tenant_lifecycle_events`: no ha habido transición, y esa tabla es la historia de la máquina de estados, no un registro de intentos (`datos.md §5.1`).
- La reparación es **un comando de consola**, `bo:retry-provisioning <slug>`, y no un *endpoint*: no hace falta capacidad nueva, no hace falta pantalla, y el camino de recuperación de este módulo ya es la consola (`operacion.md §5.1`). Reencola el mismo trabajo, que es idempotente.
- Un tenant en `en_alta` **no sirve tráfico** (§5.4.1): sus usuarios no existen todavía.

> **Esto es una decisión de especificación, no un requisito nuevo.** `REQ-BO-001` da por hecho que el alta funciona y no dice qué pasa cuando no. Dejarlo sin escribir produciría lo de siempre: un tenant zombi en `en_alta` que alguien «arregla» a mano por SQL, sin rastro.

`REQ-ONB-001` (asistente de alta con *checklist* y datos de demostración) es el paso **1.24** y no se adelanta: `1.6b` entrega la operación, no el asistente.

### 5.4 Suspensión y reactivación (`REQ-BO-001`) · sub-paso `1.6b`

**Suspender** (`activo` → `suspendido`), con motivo obligatorio y mensaje configurable para los usuarios del centro:

1. Se escribe `tenants.status`, `suspended_at`, `suspension_message` y la fila de `tenant_lifecycle_events`.
2. **En la misma operación** se invalida `tenant-resolution:{slug}` — es el issue [#7](https://github.com/pirexia/plataforma-educativa/issues/7), y `RN-BO-14` lo convierte en regla. El mecanismo exacto, con el detalle que hace falta para no implementarlo mal, está en §6.3.
3. A partir de ese instante, cualquier petición a un *host* del centro recibe **`503`** con el mensaje configurado, `Retry-After` (`ADR-038 §6.5`) y ningún dato. Lo dice `ADR-033 §2` y lo repite el primer criterio de aceptación de §5.51.
4. **Se conservan íntegros los datos y las tareas programadas críticas.** Un tenant suspendido no pierde nada y no deja de recibir sus purgas de retención.

**Reactivar** (`suspendido` → `activo`) es la operación inversa, «reversible en un clic», con la misma invalidación de caché y su motivo. **Pone a nulo `suspended_at` y `suspension_message`**, y lo segundo es una decisión: un mensaje que sobrevive a la reactivación reaparecería, literal y desactualizado, la próxima vez que ese centro se suspenda por un motivo distinto. El coste de volver a escribirlo es un campo; el de servir el mensaje equivocado lo paga el centro delante de sus familias.

Si `suspension_message` es nulo se sirve un mensaje por defecto del catálogo de traducción de la plataforma, en el idioma resuelto (`INV-009`). El mensaje que escribe el operador es **contenido**, no literal de código, y por eso puede ser un solo texto.

#### 5.4.1 `ResolveTenant` hoy **no cumple `RN-BO-15`**, y arreglarlo es parte de este sub-paso

Verificado sobre el código (`app/Http/Middleware/ResolveTenant.php`), no supuesto:

```php
$cached = Cache::remember("tenant-resolution:{$slug}", 60, /* … {id, status} … */);
// null                       -> abort(404)
// TenantStatus::Suspendido   -> abort(503, __('tenancy.suspended'))
// cualquier otro != Activo   -> abort(404)
```

De ahí salen **tres defectos**, los tres de `1.6b` porque los tres los activa este sub-paso:

| # | Defecto | Por qué importa ahora |
|---|---|---|
| 1 | **`en_baja` y `eliminado` responden `404`**, no `503` | `RN-BO-15` dice `503` para los tres estados sin acceso, y reserva `404` a *«un host que no corresponde a ningún tenant»*. Hoy da igual porque nadie llega a esos estados; **`1.6b` es el sub-paso que crea el camino para llegar** |
| 2 | **Un tenant `eliminado` lleva `deleted_at`, y `Tenant` usa `SoftDeletes`** | La búsqueda por `slug` no lo encuentra, devuelve `null`, y el `404` del punto 1 se produce además **por la vía equivocada**: no es «este estado no da acceso», es «este centro no existe». La resolución tiene que buscar **incluyendo los borrados lógicos** |
| 3 | **El mensaje del `503` es un literal de catálogo fijo** (`tenancy.suspended`) | `suspension_message` no existe todavía y el `503` no lo sirve. `1.6b` lo introduce (`datos.md §6`) |

**Lo que `1.6b` deja escrito, y `RN-BO-50` convierte en regla**, es el mapa completo:

| Situación | Respuesta | Cuerpo |
|---|---|---|
| Ningún tenant con ese `slug`, vivo ni borrado | **`404`** | Genérico, indistinguible de una ruta inexistente |
| `en_alta` | **`503`** | Mensaje por defecto «en preparación» del catálogo, con `Retry-After` |
| `activo` | Pasa | — |
| `suspendido` | **`503`** | `suspension_message`, o el del catálogo si es nulo |
| `en_baja` | **`503`** | Mensaje propio de baja del catálogo, distinto del de suspensión: el centro tiene que poder distinguir «parado» de «terminando» |
| `eliminado` | **`503`** | Mensaje propio de cierre del catálogo |

**Y una consecuencia que hay que aceptar en voz alta**: bajo esta regla, el *host* de un centro eliminado sigue respondiendo `503` para siempre, lo que revela que ese centro existió. Se acepta porque la alternativa —`404`— rompería `RN-BO-15` en su propio terreno: si un `eliminado` devolviera `404`, el `404` dejaría de significar «aquí no hay nadie» y pasaría a significar dos cosas distintas. Quien quiera que ese nombre deje de responder, retira su DNS, que es donde se decide de verdad.

#### 5.4.2 Un `slug` puede pertenecer a la vez a un tenant vivo y a uno eliminado

La unicidad es **parcial** (`WHERE deleted_at IS NULL`), y §8 ya admite la reutilización de un `slug` de un tenant borrado lógicamente. Con la búsqueda del punto 2 de §5.4.1 —que incluye los borrados— eso deja de ser inofensivo: la consulta puede devolver dos filas. **La resolución prefiere siempre al vivo**; sólo si no hay ninguno vivo mira los borrados, y entre ellos el de `deleted_at` más reciente. Sin esa regla, dar de alta un centro reutilizando el `slug` de uno cerrado le serviría el `503` del muerto.

#### 5.4.3 Qué pasa con las sesiones de los usuarios del centro

La pregunta es legítima porque `1.2b` construyó `user_sessions` y un revocador, y la tentación de usarlos aquí es inmediata. **La respuesta es que una suspensión no revoca sesiones, y sí lo hace una eliminación:**

| Transición | ¿Revoca `user_sessions`? | Por qué |
|---|---|---|
| `activo` → `suspendido` | **No** | La barrera es `ResolveTenant`: **ninguna** petición al *host* del centro pasa de ahí, tenga o no cookie. Revocar sería trabajo sobre miles de filas para cerrar una puerta que ya está cerrada, y **rompería la promesa del requisito**: «al reactivarlo, todo vuelve a estar disponible sin pérdida» y «reversible en un clic» — un claustro entero obligado a volver a autenticarse tras una suspensión de veinte minutos no es un clic |
| `activo` → `en_baja` | **No** | Mismo argumento: `en_baja` es reversible durante 90 días (`RN-BO-12`, rescate) |
| `en_baja` → `eliminado` | **Sí**, todas | Es terminal. La revocación no busca cerrar la puerta —ya está cerrada— sino **no dejar credenciales vivas apuntando a un centro que ya no se opera**: si un día se retirase el `503` por error de configuración, no debe quedar ni una sesión utilizable. Se ejecuta **en cola**, dentro del contexto del tenant, con `end_reason = 'baja_usuario'` del vocabulario ya existente de `SessionEndReason` (`REQ-AUTH`) — **no se amplía ese enumerado**: son sesiones de usuarios de tenant y su vocabulario es de `REQ-AUTH`, no de este módulo |

> **La asimetría es deliberada y es la parte que una revisión va a querer unificar.** No se unifica: suspender es una medida operativa reversible y revocar sesiones la haría cara de deshacer; eliminar es definitivo y no tiene deshacer que encarecer.
>
> **Y lo que ninguna de las tres hace es tocar los datos.** `RN-BO-16` es explícito para la suspensión y `1.6b` lo extiende a las tres: ni borra, ni anonimiza, ni detiene las tareas programadas críticas del centro.

### 5.5 Baja y eliminación (`REQ-BO-001`, `REQ-BO-007`) · sub-paso `1.6b`

**Baja** (`activo` → `en_baja`): motivo obligatorio; se calcula `grace_period_ends_at` a 90 días; el acceso de los usuarios queda bloqueado igual que en la suspensión, con un mensaje propio (§5.4.1). Durante la gracia, `REQ-OPS-004` debería ofrecer la exportación completa — y no existe (`OPEN-BO-05`, riesgo aceptado por el usuario el 2026-09-08).

**Rescate** (`en_baja` → `activo`): la baja es reversible mientras dure la gracia. Pone a nulo `grace_period_ends_at` y `grace_period_expired_at` (`datos.md §6`), invalida la caché y escribe `tenant.rescatado`. **No hay plazo para rescatar distinto del de la gracia**: vencida ésta, el centro sigue en `en_baja` y sigue siendo rescatable —nada se borra solo (`RN-BO-17`)— pero ya aparece marcado como candidato a eliminación.

#### 5.5.1 Los 90 días, y qué ocurre exactamente cuando vencen

`RN-BO-17` dice que vencido el plazo **no se elimina nada de forma automática**: se marca como candidato y se avisa. `1.6b` concreta las dos palabras que ahí quedaban sueltas:

- **«Marca»** es la columna `tenants.grace_period_expired_at` (`datos.md §6`), escrita **una sola vez** por la tarea diaria `bo:check-grace-periods`. No es una columna «por si acaso» (`ADR-034 OPEN-13`): sin ella, la tarea no sabe si ya avisó y volvería a escribir una entrada de auditoría **cada día y para siempre** sobre el mismo centro. La alternativa —preguntárselo a `admin_action_logs`— está descartada por el argumento de `datos.md §5.1`: la lógica de negocio no depende del formato del registro de auditoría.
- **«Avisa»** es, en `1.6b`, exactamente tres cosas: una entrada en `admin_action_logs` con `action = 'tenant.gracia_vencida'` y `actor_type = 'system'`; la aparición del centro en el filtro correspondiente de `GET /tenants`; y una señal de `operacion.md §7`. **No es una notificación a nadie**, ni al centro ni al operador, porque no hay infraestructura de notificaciones (`REQ-COM`, paso 1.19) y `1.6b` no la inventa. Se dice así de claro porque «y se avisa» es la clase de frase que se da por implementada sin que nadie haya construido el aviso.
- **El plazo no es configurable por variable de entorno.** `REQ-BO-001` fija 90 días; una variable que lo acorte es una forma de saltarse el periodo de gracia sin que quede rastro (`operacion.md §2`).

#### 5.5.2 Eliminación: los cuatro cerrojos

**Eliminación** (`en_baja` → `eliminado`) es la operación más peligrosa del producto, y por eso lleva **cuatro** cerrojos que se comprueban en este orden:

1. Sólo `superadministrador`, y con reautenticación viva (§5.2).
2. El solicitante escribe el **nombre exacto del tenant**; una diferencia de un carácter es `422` y **no se crea ninguna solicitud** (`CA-BO-065`). La comparación es **literal**: sin recortar espacios interiores, sin plegar mayúsculas y sin normalizar acentos. Un cerrojo que perdona diferencias no es un cerrojo, es un aviso.
3. **Doble autorización** (§5.7): la solicitud queda pendiente y no ejecuta nada.
4. Un `superadministrador` **distinto** aprueba, y sólo entonces se ejecuta.

**Efecto de la ejecución**, en este orden y en una transacción: `status = 'eliminado'`, `deleted_at`, fila de `tenant_lifecycle_events` con su `dual_authorization_id` —que el `CHECK` de la tabla exige (`datos.md §5.2`)—, `admin_action_logs` con `tenant.eliminado`, invalidación de caché, y **encolado** de la revocación de sesiones del centro (§5.4.3). Los datos del centro se **conservan íntegros**.

#### 5.5.3 Borrado lógico frente a eliminación real: dónde queda `INV-004` y dónde el RGPD

Es la confusión más probable de este sub-paso, y conviene dejar los tres niveles separados por escrito:

| Nivel de `ADR-004` | Qué es | En `1.6b` |
|---|---|---|
| **1 · Borrado lógico** | `status = 'eliminado'` más `deleted_at` en `tenants`. El centro deja de ser alcanzable; **ni una fila de sus datos se toca** | ✅ **Es lo único que hace `1.6b`**, y cumple `INV-004` literalmente: la entidad crítica no se borra físicamente, se marca |
| **2 · Anonimización** | Sustituir datos personales conservando la fila | ❌ No aplica a un tenant: el sujeto no es una persona |
| **3 · Purga física** | Borrar de verdad los datos del centro | ❌ Es `REQ-PRIV-006`, que no existe (§2.2). **Ningún camino de código de `1.6b` borra un solo dato de un tenant** |

Tres precisiones que evitan tres errores distintos:

1. **`deleted_at` en `tenants` no borra nada del tenant.** Las filas de sus tablas siguen ahí, con su `tenant_id`, bajo su RLS. Lo que desaparece es la puerta, no la casa. Quien lea `INV-004` y espere una cascada de borrados lógicos por todo el esquema no la va a encontrar, y es correcto que no la encuentre.
2. **La eliminación de un tenant no es el derecho de supresión de nadie.** El derecho de supresión de `ADR-004` es de un interesado —un alumno, una familia— sobre sus datos, y se ejerce dentro del centro. Cerrar un centro no ejerce ningún derecho: **crea la obligación** de decidir qué se hace con datos de menores que siguen ahí. Esa decisión es `REQ-PRIV-006` y su catálogo de retención, y `1.6b` **no la adelanta ni la simula**.
3. **La consecuencia hay que decirla, no esconderla**: al cerrar `1.6b`, un centro eliminado conserva indefinidamente todos sus datos —incluidos los de menores— sin ningún plazo de purga definido. Es exactamente el riesgo que `OPEN-BO-05` describe y que el usuario aceptó el 2026-09-08. Queda como **deuda declarada contra `REQ-PRIV-006`**, con su fecha, y no como una omisión que alguien descubra en una auditoría.

#### 5.5.4 Una contradicción entre dos ficheros de esta especificación, señalada y no resuelta por mí

**`dual_authorizations.action` admite `tenant.baja`** —está en el `CHECK` de la migración ya desplegada y en el *enum* `DualAuthorizationAction` del chasis—, pero `api.md §2.4` clasifica la baja como «transición simple» con respuesta `200`, y §5.5 sólo pone doble autorización en la eliminación. **Las dos cosas no pueden ser ciertas a la vez.**

Esta especificación está escrita contra la segunda lectura —**la baja no exige doble autorización**— por tres razones:

1. `REQ-BO-007` enumera las operaciones que la exigen y son **tres**: *«eliminar un tenant, purgar datos o desactivar módulos en masa»*. La baja no está.
2. La baja es **reversible** durante 90 días por diseño (`RN-BO-12`, rescate). La doble autorización existe para lo irreversible.
3. Exigir dos personas para iniciar una baja comercial —que es una operación de rutina, acordada con el cliente— produce el efecto conocido: se pide con antelación «para tenerla firmada», y la doble autorización se convierte en un trámite que se resuelve por adelantado.

**El valor `tenant.baja` del vocabulario se conserva** aunque no lo escriba nadie: retirarlo exigiría una migración sobre un `CHECK` desplegado, y dejarlo no cuesta nada. Si el usuario decide lo contrario, el cambio es acotado —la baja pasa a responder `202` con su solicitud pendiente, como la eliminación— y no toca ninguna otra parte. Queda como **`OPEN-BO-14`** (§14).

### 5.6 Clonación de un tenant (`RMT-007`) · sub-paso `1.6b`

Se clona **la configuración, nunca las personas**. En `1.6b` eso es: la configuración del centro, los roles y sus concesiones, y las suscripciones de módulo. No se copian usuarios, ni invitaciones, ni auditoría, ni ningún dato personal — no porque no haya más que copiar hoy, sino porque **la regla debe quedar escrita antes de que exista más que copiar**: cuando lleguen alumnos y familias, un clon que arrastre personas sería una cesión de datos entre centros.

#### 5.6.1 Lo que dice el requisito, y lo que no dice

`REQ-BO-001` dice, entero: *«Clonación de un tenant como plantilla para acelerar altas (`RMT-007`)»*. Y `RMT-007` dice: *«Plantillas de tenant para acelerar el alta de nuevos centros (`REQ-ONB-001`)»*. **Ninguno de los dos menciona datos**, los dos dicen «plantilla», y los dos apuntan al *onboarding*. La lectura conservadora —se clona la **estructura**, no el contenido— no es una restricción que yo añada: es lo único que los dos textos sostienen. Clonar datos de un centro real en otro sería, además, una cesión de datos personales entre responsables distintos sin base legal ninguna (`INV-008`, `PRIVACY.md`).

#### 5.6.2 Inventario exacto de qué se copia y qué no

| Origen | ¿Se copia? | Nota |
|---|:---:|---|
| `tenant_settings` — idiomas, zona horaria, moneda, CCAA, tiempo de sesión, métodos de MFA, periodo de gracia de MFA | ✅ | Es la plantilla: lo que se quiere no volver a teclear |
| `tenant_settings` — identidad fiscal (`legal_name`, `tax_id`, dirección fiscal) | ❌ | Son **datos del centro origen**, no configuración reutilizable. Un clon que arrastre el CIF de otro colegio es un error de datos esperando a que alguien emita algo con él |
| `tenant_settings` — marca (colores, logo, favicon, fondo de acceso) | ❌ | Ídem: los objetos de almacenamiento están segregados por tenant en su ruta, y copiar la referencia sin copiar el objeto produce un enlace roto; copiar el objeto es copiar la identidad visual de otro centro |
| `roles` y su matriz de concesiones (`permission_role`), **incluidos los roles personalizados** que el centro origen haya creado | ✅ | Es el otro motivo real para clonar: una configuración de permisos afinada es trabajo de semanas |
| `module_subscriptions` | ✅ | Con `enabled_at = now()` y un `reason` propio que dice que vienen de un clon. **No se copian `enabled_at`/`disabled_at` del origen**: el clon no ha tenido esos módulos desde 2024 |
| `users`, `people`, `user_invitations`, `user_sessions`, `user_identities` | ❌ | `RN-BO-21`, sin excepción |
| `audit_logs`, `tenant_lifecycle_events`, `admin_action_logs` del origen | ❌ | La historia no se hereda: el clon es un centro nuevo y su primera fila de historial es su propia alta |
| `status`, `suspended_at`, `suspension_message`, `grace_period_ends_at`, `grace_period_expired_at` | ❌ | El clon nace en `en_alta`, aunque el origen esté suspendido |
| `early_adopter_since` | ❌ | Es una **designación del proveedor sobre un centro concreto** (`datos.md §6.1`). Heredarla metería a un centro nuevo en despliegues progresivos sin que nadie lo haya decidido |
| Cualquier dato de negocio de los módulos contratados | ❌ | En `1.6b` no existe ninguno; la regla se escribe **ahora** para que exista antes que el dato |

> **Tres de esas cuatro tablas son de `REQ-CORE`, y eso no estaba dicho.** `tenant_settings`, `roles` y `permission_role` viven en `App\Modules\Core`; tal como estaba escrita esta sección, el trabajo `CloneTenant` de `REQ-BO` tendría que leerlas y escribirlas directamente — **exactamente la infracción de `INV-007` que `RN-BO-53` prohíbe para el alta**, tres secciones más arriba. Lo detectó `ADR-048 §1.2` al leer el código y se corrige aquí, antes de que exista implementación: **la copia de esas tres la ejecuta `REQ-CORE`**, por el segundo método del mismo contrato, `provisionFromTemplate(Tenant $source, Tenant $target, TenantAdministrator $a)`. La lista de qué columnas de `tenant_settings` son «operativas» y cuáles no vive con ellas, en `REQ-CORE`, que es quien las conoce.
>
> **`module_subscriptions` es la excepción y la copia `REQ-BO`**, sin que sea una incoherencia: `ADR-045 §4.1` decidió que el backoffice es su **único escritor**, por `pgsql_platform`. Meterla en el contrato de `REQ-CORE` revocaría esa decisión sin ADR que la sustituya. La frontera que queda es limpia: **`REQ-CORE` es dueño de lo que el centro *es*; `REQ-BO`, de lo que el proveedor le ha *vendido*** (`ADR-048 §4.5`).

#### 5.6.3 El clon necesita un administrador, y por eso el cuerpo lo trae

Si el clon no copia personas y tampoco ejecuta el aprovisionamiento por defecto —que es lo que crearía al primer administrador—, **nadie podría entrar en él nunca**. Por eso `POST /tenants/{public_id}/clone` recibe los mismos datos del primer Administrador de Centro que el alta, y **lo crea nuevo**: una `Person` y un `User` propios, con su propia invitación. No se copia a nadie; se da de alta a alguien. `RN-BO-21` se respeta a la letra.

#### 5.6.4 Restricciones sobre el origen, y por qué

- **El origen no puede estar `eliminado`**: `422` con `bo.tenant.clone_source_invalid`. Clonar un centro cerrado resucitaría su configuración sin que nadie lo haya decidido, y es justo el caso en que nadie recuerda por qué estaba cerrado.
- **El origen sí puede estar `suspendido` o `en_baja`.** Suspensión y clonación son ejes distintos, igual que suspensión y contratación (`ADR-045 §2`), y el caso real —clonar la configuración de un centro que se va para montar el que llega— es legítimo.
- **El origen no puede estar `en_alta`**: su configuración todavía no está completa (§5.3.3) y el clon saldría a medias sin que nada lo indique.
- **La lectura del origen es un único punto en el tiempo.** Todo lo que se copia se lee dentro de una transacción, de una vez, para que un cambio concurrente en el origen no produzca un clon mitad viejo mitad nuevo. **Esto es, además, lo que descarta la alternativa aparentemente más sencilla** de que `REQ-BO` leyera la plantilla y se la pasara a `REQ-CORE` como datos: entre la lectura y la escritura habría una ventana, y `REQ-BO` seguiría leyendo tablas ajenas (`ADR-048 §9`). Por eso la lectura del origen ocurre **dentro** de `provisionFromTemplate()`, y `provisionFromTemplate()` es idempotente por la misma comprobación que `provision()` — la existencia de `tenant_settings` en el destino (`ADR-048 §5.3`).

El resultado es un tenant nuevo en `en_alta`, con su propio `slug` —validado igual que en el alta, `RN-BO-49` incluido—, que transita a `activo` al terminar el trabajo, con la misma gestión de fallo de §5.3.5. Su fila de auditoría es `tenant.clonado`, con el `public_id` del origen en `context`.

### 5.7 Doble autorización (`REQ-BO-007`)

Mecanismo **genérico y con vocabulario cerrado**, no una condición dentro de cada operación destructiva. Motivo: `REQ-BO-007` lo exige para «eliminar un tenant, purgar datos o desactivar módulos en masa», y una implementación por operación garantiza que la cuarta operación destructiva que alguien añada se le olvide.

1. **Solicitud**: el operador llama al *endpoint* de la operación con la cabecera de confirmación reforzada. En vez de ejecutar, se crea una `dual_authorization` en estado `pendiente` con la operación **congelada** —sus parámetros exactos y una huella de ellos— y su motivo. Respuesta `202`.
2. **Aprobación**: otro administrador la aprueba. La regla «dos personas distintas» **la impone la base de datos** con un `CHECK`, no el controlador (`datos.md §5`, `RN-BO-19`). Es la restricción más importante del módulo y no puede vivir en PHP, por el mismo argumento con el que `ADR-045 §4.4` sacó del controlador la escritura de `enabled`.
3. **Ejecución**: al aprobar se ejecuta la operación congelada, **con los parámetros de la solicitud y nunca con los que traiga la aprobación**. Si los parámetros hubieran dejado de ser válidos, falla y queda `fallida` con su motivo.
4. **Caducidad**: sin aprobar en su ventana, pasa a `caducada` y no se puede ejecutar.
5. Rechazo explícito con motivo, también auditado.

Las cinco transiciones se escriben en `admin_action_logs`. **Solicitar no es aprobar, y aprobar no es ejecutar**: son tres eventos distintos y los tres se registran.

### 5.8 Contratar y descontratar módulos (`REQ-BO-002`, `ADR-045`) · sub-paso `1.6c`

**Contratar** un módulo `M` para un tenant `T`:

1. Comprobación de capacidad y de que `M` existe en el catálogo y no está `retired_at`.
2. **Cierre de dependencias** (`RMOD-006`): se resuelve el conjunto de dependencias de `M` que `T` no tiene contratadas. La vista previa las muestra; la ejecución las contrata **junto con** `M`, en la misma transacción. Ninguna escritura puede dejar un módulo contratado cuya dependencia no lo esté (`RN-BO-22`).
3. Escritura por `pgsql_platform`: `enabled = true`, `enabled_at = now()`, `reason` con el motivo obligatorio.
4. **Invalidación de la caché** de disponibilidad del tenant afectado, con su prefijo (`ADR-045 §8.3`, §6.3 de este documento).
5. `REQ-CORE` emite **`ModuleContracted`** con `tenant_id`, `module_code` y el actor de plataforma. Lo emite el servicio de contratación de `REQ-CORE`, **no el backoffice**, para que el evento exista también cuando la escritura venga de la activación masiva o de un comando (`ADR-045 §4.8`).
6. `admin_action_logs`: una entrada por módulo, con `affected_tenant_id`.

**Descontratar** es simétrico, con dos diferencias: un módulo **esencial** no se descontrata nunca (`422`), y si otros módulos contratados dependen de `M`, la vista previa los lista y la ejecución exige confirmación explícita — o se arrastran, o la operación se rechaza; no hay tercera vía que deje el grafo roto.

**Activación masiva**: la misma operación sobre un conjunto de tenants. Se ejecuta **en cola** (`INV-012`), con `Idempotency-Key` obligatoria (`ADR-038 §8.1`, criterios 2 y 3: notifica a terceros y opera por lotes), y emite **un evento y un aviso por cada centro afectado**, nunca uno global (`ADR-045 §4.7`). Su vista previa dice, en número, cuántos centros pasan a tenerlo y cuántas dependencias arrastra. La **desactivación** masiva es acción destructiva y pasa por §5.7.

> Lo anterior es el flujo tal como lo escribió la revisión del chasis, y **sigue siendo cierto entero**. Lo que falta para poder implementarlo —qué recibe cada *endpoint*, en qué transacción ocurre cada escritura, qué pasa cuando falla la invalidación o el evento, y qué impide que dos operaciones concurrentes dejen el grafo roto— es §5.8.1 a §5.8.9.

#### 5.8.1 Estado real del código, verificado y no supuesto

Verificado el **2026-09-15** sobre `develop` en `91adac6`, con el mismo método que §1 aplicó al chasis y §5.3 a `1.6b`. **La conclusión primero, porque contradice lo que parecía razonable suponer: de los dieciséis criterios de `§13.3` no hay ni uno satisfecho hoy.** Lo que sí existe son los precedentes sobre los que se construyen.

| Pieza | Estado real |
|---|---|
| Tabla `module_subscriptions` | ✅ **Existe y no le falta ninguna columna.** `2026_08_18_100600_create_modules_table.php`, creada con `TenantMigration::tenantTable()`: `public_id` ULID, `module_code` (FK → `modules.code`), `enabled boolean DEFAULT false`, `enabled_at`, `disabled_at`, `reason`, `settings jsonb`, más `id`, `tenant_id`, `created_by`, `updated_by`, `timestamps` y `deleted_at`. Índice `UNIQUE (tenant_id, module_code) WHERE deleted_at IS NULL`. **`ADR-045 §4.2` —«este ADR no cambia el esquema»— sigue siendo literalmente cierto: `1.6c` no añade ni una columna** (`datos.md §7`) |
| Modelo `ModuleSubscription` | ✅ Existe. `TenantModel`, `Auditable` con política `Full`, `$fillable` con los cinco campos de negocio |
| Catálogo `modules` | ✅ Existe, con `REVOKE INSERT, UPDATE, DELETE … FROM plataforma_app, plataforma_platform` en su propia migración. Sólo lo escribe `SyncModuleRegistry` por `pgsql_owner` |
| `SyncModuleRegistry` | ✅ Existe y **ya aborta el despliegue** ante un `applicable_scopes` fuera del vocabulario (`encodeApplicableScopes()`, `InvalidArgumentException`). **Es el precedente exacto**, línea a línea, de las validaciones que pide `ADR-045 §4.5` |
| `moduleDescriptor()` | ⚠️ Devuelve **`{code, name_key, phase}` y nada más**. Búsqueda de `depends_on` en todo `apps/api`: **cero resultados**. `essential` aparece **una sola vez**, y es el comentario de `ALWAYS_ENABLED` |
| Quién declara descriptor | **Dos módulos**: `CoreServiceProvider` (`core`) y `AuthServiceProvider` (`auth`). `BackofficeServiceProvider` **no** implementa la interfaz, a propósito (§10) |
| `EloquentModuleAvailability` | ⚠️ `ALWAYS_ENABLED = ['core','auth']` escrita a mano, y `Cache::remember("modules:{$moduleCode}:enabled", 300, …)` **sin ningún `Cache::forget` en todo el repositorio** |
| `ModuleContracted` / `ModuleDecontracted` | ❌ **Cero resultados.** No existen |
| Privilegios de `module_subscriptions` | ❌ **Ninguna migración hace `REVOKE` ni `GRANT` sobre esa tabla.** Los privilegios por defecto de `01-tenancy.sql.tpl` siguen intactos: `plataforma_app` puede insertar, actualizar `enabled` y leer todas las columnas |
| Superficie de backoffice para módulos | ❌ **No existe ninguna.** `app/Modules/Backoffice/Http/routes.php` no declara ni `/modules` ni `/module-rollouts`; no hay controlador con `Module` en el nombre en todo el módulo |
| `DualAuthorizationAction` | ✅ **Ya admite `modulo.descontratar_masivo`**, en el *enum* y en el `CHECK` de la migración del chasis, **ya desplegado**. `1.6c` es quien le da su primer escritor |
| Patrón de escritura concurrente | ✅ Existe y está probado: `DualAuthorizationService::approve()`/`reject()` y `TenantLifecycleService::executeSimpleTransition()`/`executeApprovedDeletion()` releen la fila con `lockForUpdate()` **dentro** de la transacción (issues #205, #207, #209, #210). Es el patrón que §5.8.5 aplica aquí **desde el principio, no como parche** |

**Los dieciséis criterios de `§13.3`, uno a uno:**

| Criterio | ¿Satisfecho hoy? | Prueba |
|---|:---:|---|
| `CA-BO-030`, `CA-BO-031` (privilegios) | **No** | No existe la migración de `datos.md §7` |
| `CA-BO-032`, `CA-BO-033` (invalidación) | **No** | No hay `Cache::forget` y no hay camino de escritura |
| `CA-BO-034`, `CA-BO-035` (`depends_on`) | **No** | `depends_on` no existe en ningún sitio |
| `CA-BO-036` (`essential`) | **No** | `ALWAYS_ENABLED` sigue en `EloquentModuleAvailability:28` |
| `CA-BO-037` a `CA-BO-045` | **No** | Ni los eventos ni los *endpoints* existen |

> **Dos consecuencias sobre tests ya escritos, y la segunda no estaba nombrada en ninguna parte de esta especificación.** `ADR-045 §4.4` y `datos.md §7.3` avisan de que `ModuleSubscriptionsSchemaTest` inserta hoy por la conexión `pgsql` y tiene que pasar a `pgsql_platform` con el `REVOKE INSERT`. **`SyncModuleRegistryTest` hace exactamente lo mismo** —inserta una fila de `module_subscriptions` por `pgsql` dentro del contexto de un tenant, para comprobar que retirar un módulo del código conserva las suscripciones— y **también** tiene que cambiar de conexión. Que el segundo no estuviera nombrado es la forma concreta en que un `REVOKE` rompe un test que nadie relacionaba con él.

#### 5.8.2 Dónde vive cada pieza, y por qué no puede vivir en el backoffice

`ADR-045 §4.5` exige *«una sola implementación, en un servicio de dominio de `REQ-CORE`, consumida por la activación individual, por la masiva y por la vista previa»*, y `§4.8` que los dos eventos los emita *«el servicio de contratación de `REQ-CORE`, no el backoffice directamente»*. Traducido a la frontera de `INV-007`, y siguiendo el precedente **exacto** que `ADR-048` fijó para el aprovisionamiento —interfaz en `Core\Domain`, enlace en `CoreServiceProvider`, implementación dentro de `REQ-CORE`—:

| Pieza | Dueño | Motivo |
|---|---|---|
| Catálogo de descriptores (`code`, `name_key`, `phase`, **`depends_on`**, **`essential`**) | `REQ-CORE` | Es lo que ya materializa `platform:sync-registry`, y lo consume `EloquentModuleAvailability` en cada petición |
| Cierre de dependencias y detección de ciclos | `REQ-CORE` | `RN-BO-22`: una sola implementación. Dos —una en el backoffice para la vista previa y otra en el dominio para la escritura— es la forma conocida de que la vista previa mienta |
| Escritura de `enabled`/`enabled_at`/`disabled_at`/`reason` | `REQ-CORE`, sobre `pgsql_platform` | La **potestad** es del backoffice (`ADR-045 §4.1`) y el **rol de base de datos** también; la **implementación** es de quien posee la tabla y el invariante |
| Invalidación de `modules:{code}:enabled` | `REQ-CORE` | La clave la escribe `EloquentModuleAvailability`, que es suya. Quien inventa la clave la borra |
| `ModuleContracted` / `ModuleDecontracted` | `REQ-CORE` | `ADR-045 §4.8`, `RMOD-010`: todo evento del ciclo de vida de un módulo pertenece a `REQ-CORE`, nunca al módulo afectado |
| `admin_action_logs`, capacidades, reautenticación, doble autorización, `Idempotency-Key` | **`REQ-BO`** | Son su tabla y sus reglas. `REQ-CORE` no puede escribir `admin_action_logs` (`INV-007`) |

**El contrato, con la forma de `ADR-048 §4.1`** (dos interfaces en `Core\Domain`, porque leer el catálogo y escribir una suscripción son dos capacidades con dos públicos distintos — el evaluador de permisos necesita la primera y no la segunda):

```php
namespace App\Modules\Core\Domain;

interface ModuleCatalog          // lectura; la consume también EloquentModuleAvailability
{
    /** @return list<ModuleDescriptor> */
    public function all(): array;
    public function find(string $code): ?ModuleDescriptor;
    /** Cierre transitivo hacia abajo, sin el propio $code y sin esenciales. */
    public function dependenciesOf(string $code): array;
    /** Módulos declarados que dependen, transitivamente, de $code. */
    public function dependentsOf(string $code): array;
}

interface ModuleContracting      // escritura; sólo la consume REQ-BO
{
    public function preview(int $tenantId, ModuleChange $change): ModuleContractingPreview;
    /** Fase 1: valida, bloquea, recalcula el cierre y escribe. No invalida ni emite. */
    public function apply(int $tenantId, ModuleChange $change): ModuleContractingOutcome;
    /** Fase 2: invalida la caché de cada centro y emite los eventos. Ver §5.8.6. */
    public function publish(ModuleContractingOutcome $outcome): void;
}
```

`ModuleChange` es un objeto de valor `{module_code, enabled, reason, cascade}`; `ModuleContractingOutcome` enumera **lo que de verdad cambió** —módulo a módulo, con la dirección y si fue principal o arrastrado— y es lo que `REQ-BO` recorre para escribir `admin_action_logs`. **Un `Outcome` vacío es un resultado legítimo y frecuente** (`RN-BO-69`): contratar lo ya contratado no cambia nada.

> **Por qué `apply()` y `publish()` son dos métodos y no uno, que es la pregunta que va a hacer la revisión.** No es estilo: **lo fuerza `ADR-046 §6.4`.** La fase 1 corre dentro de `runAsPlatform(BackofficeEscritura, …)`, donde está **prohibido** que haya tenant activo; la invalidación necesita el prefijo `t{tenant_id}:` y por tanto necesita entrar en el contexto del centro con `runFor()`, que es justamente la combinación prohibida. Las dos cosas no caben en el mismo bloque, y §6.2.4 ya lo dejó escrito: *«la invalidación … se hace **fuera** del bloque de plataforma»*. Un contrato de un solo método obligaría a violar esa regla o a componer el prefijo a mano, que `operacion.md §4.2` prohíbe expresamente.

**Ampliar otra vez la superficie pública de `REQ-CORE` es trabajo de `1.6c`** y se declara como tal, con la lista cerrada de lo que se toca, igual que hizo `ADR-048 §10`: dos interfaces y tres tipos nuevos en `Core\Domain`; dos claves nuevas en `DeclaresModuleRegistry::moduleDescriptor()`; la implementación en `Core\Infrastructure`; dos líneas de enlace en `CoreServiceProvider`; la retirada de `ALWAYS_ENABLED` de `EloquentModuleAvailability`; y las dos clases de evento en `Core\Domain\Events`. **No se toca ninguna migración, columna, *endpoint*, permiso ni regla de negocio de `REQ-CORE`.** Ver `OPEN-BO-18`.

> **Una incoherencia interna de esta especificación, detectada al escribir lo anterior y corregida sólo en la redacción.** §9 dice, en su frontera con `REQ-CORE`: *«`REQ-BO` **no escribe `module_subscriptions` directamente**»*; §5.6.2 dice, sobre la clonación: *«**`module_subscriptions` es la excepción y la copia `REQ-BO`**»*, y así está implementado y mezclado desde `1.6b` (`CloneTenant` usa `ModuleSubscription::query()->updateOrCreate()`). **Las dos frases se refieren a operaciones distintas y ninguna decisión cambia**: la de §9 habla de **contratar y descontratar**, que es lo único que `ADR-045 §4.5`/`§4.8` manda al servicio de `REQ-CORE`; la de §5.6.2 habla del **sembrado inicial de un clon**, que copia un conjunto ya coherente y no emite eventos —el clon nace en `en_alta` y todavía no tiene un solo usuario al que avisar—. §9 queda redactada de forma que lo diga. **No se cambia `CloneTenant`**, y no es un hallazgo que corregir: es una frase de esta especificación que era más ancha que la decisión que describía.

#### 5.8.3 `depends_on` y `essential`: qué valida el despliegue, y qué deja pasar

Las dos claves entran en `moduleDescriptor()` con valor por omisión —`[]` y `false`— para que los dos módulos que declaran descriptor hoy sigan compilando sin tocarlos, salvo `core` y `auth`, que pasan a declarar `essential: true` y con ello **vacían `ALWAYS_ENABLED`** (`CA-BO-036`).

`platform:sync-registry` gana **tres** validaciones, todas con la forma de la que ya existe para `applicable_scopes` —excepción, sin escribir nada, despliegue detenido— y **ninguna de ellas corrige nada**:

| # | Validación | Motivo |
|---|---|---|
| 1 | Todo código de `depends_on` existe en el catálogo **declarado** (no en la tabla `modules`, que puede tener retirados) | `ADR-045 §4.5`. `CA-BO-034` |
| 2 | El grafo no tiene ciclos, y el error **nombra el ciclo** | Ídem. `CA-BO-035` |
| 3 | **Un módulo `essential` no declara dependencias de módulos no esenciales** | Derivada, y es la única de las tres que no está escrita en `ADR-045`. Sale de juntar sus dos frases: un esencial *«devuelve `true` sin necesidad de fila»* (`§4.9`) y *«ninguna escritura puede dejar un módulo contratado cuya dependencia no lo esté»* (`§4.5`). Un esencial que dependiera de algo descontratable sería un módulo siempre utilizable que se puede romper sin que nadie lo haya tocado, y **ninguna escritura lo impediría**, porque el esencial no tiene fila que bloquear. Se valida en el despliegue porque es el único sitio donde se puede (`RN-BO-64`) |

**Lo que el comando sigue dejando pasar, y es correcto**: una arista nueva que deje centros incoherentes —`M` contratado y `N` no— **informa y no corrige** (`RN-BO-28`, `ADR-045 §4.5`). Contratar `N` en 200 centros es una decisión comercial facturable y no la toma un comando de despliegue. `1.6c` le da a esa incoherencia dos sitios donde verse: la ficha de `GET /tenants/{public_id}/modules` (`api.md §2.6.2`) y la señal de `operacion.md §7`.

**El catálogo se resuelve una vez por proceso** (`RN-BO-63`). No es un detalle: `EloquentModuleAvailability::isEnabled()` corre en **cada petición de cada usuario de cada centro** —por `EnsureModuleEnabled` y por el filtro de inercia de `PermissionResolver` (`ADR-044 §4.9`)— y hoy resuelve `essential` con un `in_array` sobre una constante. Sustituir eso por un escaneo de ficheros (`ModuleServiceProviderDiscovery`, que es lo que usa el comando de consola) o por una consulta a `modules` sería cambiar una comparación en memoria por trabajo de disco o de base de datos en el camino más caliente del sistema. El catálogo se construye desde los `ServiceProvider` **ya registrados** por el contenedor, que Laravel arranca de todos modos.

#### 5.8.4 Qué recibe cada *endpoint* y qué se valida, en orden

Los cuerpos exactos están en `api.md §2.6`; aquí, el orden de comprobación, que es lo que decide qué error ve el operador cuando fallan dos cosas a la vez.

**`PUT /tenants/{public_id}/modules/{module_code}`** — cuerpo `{enabled, reason, cascade}`:

1. Capacidad `modulo.contratar` (`permisos.md §4.4`) y, con `enabled: false`, reautenticación viva (`OPEN-BO-17`).
2. El tenant existe y **su estado admite escritura de módulos**: `activo`, `suspendido` y `en_baja` sí; `en_alta` y `eliminado` **no**, `409` (`RN-BO-71`).
3. `reason` presente y no vacío, `422` (`RN-BO-24`).
4. `module_code` existe en el catálogo declarado, `404` si no.
5. El módulo **no es esencial**, en ninguna de las dos direcciones, `422` con `bo.module.essential` (`RN-BO-65`).
6. Con `enabled: true`, el módulo **no está `retired_at`**, `422` con `bo.module.retired`. Con `enabled: false` sí se admite retirado (`RN-BO-70`).
7. **A partir de aquí todo ocurre dentro de la transacción** (§5.8.5), porque el cierre de dependencias depende del estado real del tenant en ese instante y no del que tenía cuando se calculó la vista previa.

**`POST /tenants/{public_id}/modules/preview`** hace del 2 al 6 y devuelve el cierre **sin escribir nada y sin bloquear nada** (`RN-BO-68`).

**`POST /module-rollouts`** — cuerpo `{module_code, enabled, reason, cascade, tenant_public_ids[]}`:

- Todo lo anterior, **más** `Idempotency-Key` obligatoria (`ADR-038 §8.1`) y capacidad `modulo.contratar_masivo`.
- `tenant_public_ids` es una **lista explícita**, no vacía y sin duplicados. **No hay selector por filtro** (`RN-BO-81`): «todos los centros activos» lo compone el cliente desde `GET /tenants`, y eso es precisamente lo que hace que el conjunto se pueda congelar en una doble autorización y auditar después. Un filtro cambia de contenido entre la vista previa, la solicitud y la aprobación, y `RN-BO-20` congela parámetros, no consultas.
- Las comprobaciones **por centro** —estado, si ya lo tiene, qué arrastra— **no se hacen aquí**: se hacen dentro del lote, centro a centro, y lo que no procede se **omite y se reporta** en vez de abortar el lote (`RN-BO-78`). Rechazar la petición entera porque uno de doscientos centros está `en_alta` convertiría una operación de rutina en un juego de adivinanzas.
- Con `enabled: false`, **no se encola nada**: se crea la `dual_authorization` (§5.8.7).

#### 5.8.5 La transacción, el bloqueo y por qué el bloqueo no va donde parece

El cierre de dependencias se calcula sobre el conjunto de suscripciones del centro, y ese conjunto lo puede estar cambiando otra petición. El caso concreto que hay que impedir, y que ningún `CHECK` puede: **A contrata `M`, que depende de `N`; B descontrata `N` a la vez.** Las dos calculan su cierre sobre el mismo estado de partida, las dos lo pasan, y el resultado es `M` contratado sin `N` — exactamente lo que `RN-BO-22` dice que ninguna escritura puede producir.

Es el mismo tipo de restricción que `datos.md §3.3` y `§6.4` resolvieron con `lockForUpdate()` tras los issues #205, #207, #209 y #210, y se aplica **desde el principio y no como parche posterior**. Con una diferencia que importa:

> **El bloqueo no puede ir sobre las filas de `module_subscriptions`.** Contratar **crea** filas, y no se puede bloquear lo que todavía no existe: dos peticiones que contratan módulos distintos del mismo centro no se verían la una a la otra. El punto de serialización es la **fila de `tenants`** (`SELECT … FOR UPDATE`), que existe siempre y es única por centro. Es el mismo recurso que bloquea `TenantLifecycleService`, y que lo sea es una propiedad buscada: una eliminación no debe colarse en mitad de un cierre de dependencias, ni al revés.

Orden dentro de la fase 1, sin margen:

1. `runAsPlatform(PlatformAccessPurpose::BackofficeEscritura, …)` — con `hasTenant()` falso, que la primitiva exige (`§6.2.4`).
2. `DB::transaction(…)`.
3. `SELECT … FROM tenants WHERE id = ? FOR UPDATE`, y **releer el `status`** sobre esa lectura bloqueada: si dejó de admitir escritura entre el paso 2 de §5.8.4 y este, `409`.
4. Leer el conjunto de `module_subscriptions` del centro **fijando `tenant_id` a mano** dentro del bloque, que es lo que el *docblock* de `BelongsToTenant` prescribe para modo plataforma.
5. **Recalcular el cierre** sobre esa lectura. Si difiere de lo que el cliente confirmó, se aplica **el recálculo** y la respuesta dice lo que de verdad se hizo (`RN-BO-68`).
6. Escribir `module_subscriptions` por `pgsql_platform`: `enabled`, `enabled_at` o `disabled_at`, y `reason` **literal del operador en todas las filas tocadas, arrastradas incluidas** (`RN-BO-72`).
7. Escribir `admin_action_logs`: **una entrada por módulo cambiado**, con `affected_tenant_id`, y `cascaded_from` en `context` para las arrastradas.
8. `COMMIT`, y cierre del bloque de plataforma.

**Dos escrituras que el backoffice no hace, y hay que decirlo porque las dos parecen obligatorias:**

- **`created_by` y `updated_by` se quedan nulos** (`RN-BO-73`). Son columnas de una tabla de tenant y referencian a `users` **de ese centro**; un administrador de plataforma no es un usuario de ningún centro (`RN-BO-01`), y escribir ahí su identificador sería la referencia de otra persona con el mismo número. **Verificado el 2026-09-15 sobre `TenantMigration::tenantTable()`: las dos llevan clave foránea compuesta `(tenant_id, created_by) REFERENCES users (tenant_id, id)`**, así que el intento **falla ruidosamente** y no en silencio — que es la mejor de las dos formas de equivocarse y conviene saber que la tenemos. El actor de la operación vive donde tiene que vivir: `admin_action_logs.actor_platform_admin_id`.
- **No queda fila en `audit_logs` del centro** (`RN-BO-74`). `ModuleSubscription` es `Auditable` con política `Full`, pero la escritura ocurre bajo `BackofficeEscritura`, donde `AuditRecorder::record()` **retorna en silencio** por decisión de `ADR-046 §6.5` (§6.2.4). Es correcto y es `RN-BO-30`: la auditoría de plataforma no se mezcla con la del centro. El centro ve la operación por `GET /api/v1/platform-actions` (`api.md §2.9`). Se escribe aquí para que nadie lea ese silencio como un defecto y «lo arregle».

#### 5.8.6 La fase 2: invalidación y eventos, y qué pasa exactamente si fallan

Ésta es la pregunta que el encargo de este sub-paso pide responder y que no estaba escrita en ninguna parte.

La fase 2 corre **fuera** del bloque de plataforma y **después** del `COMMIT` (`RN-BO-75`), por el motivo de §5.8.2, y hace dos cosas por cada centro afectado:

1. `TenantContext::runFor($tenantId, fn () => Cache::forget("modules:{$code}:enabled"))`, **una vez por módulo cambiado**. Nunca componiendo el prefijo `t{id}:` a mano (`operacion.md §4.2`), y nunca con un almacén de proceso: la caché es Redis y es compartida (`operacion.md §4.1`, detalle 3).
2. Emitir `ModuleContracted` o `ModuleDecontracted`, **uno por centro y por módulo**, jamás uno agregado (`RN-BO-27`).

**Si la fase 2 falla —Redis caído, por ejemplo— la petición no falla** (`RN-BO-76`). La escritura ya está confirmada y auditada; devolver `500` sería mentir sobre algo que ya ocurrió e invitar al operador a reintentarlo. Lo que se hace: se registra, se produce la señal de operación de `operacion.md §7`, y **el TTL de 300 s de la propia caché es el peor caso acotado**. Esa es, exactamente, la razón por la que ese TTL existe y por la que no debe subirse.

> **Y una carrera residual que hay que declarar en voz alta en vez de dejar que la encuentre una revisión.** Entre el `COMMIT` y el `forget` hay una ventana en la que un lector concurrente puede haber leído el valor **anterior** de la base de datos y escribirlo en caché **después** del `forget`, dejándolo vivo hasta 300 s. Es la misma carrera que `RN-BO-51` acepta con 60 s para `tenant-resolution`, y por el mismo motivo: invalidar **dentro** de la transacción no la cierra, la agranda. **No se elimina aquí**, y el disparador queda escrito: si alguna vez importa de verdad, la solución es el mecanismo de clave versionada de `1.6e` (`datos.md §9.5`), no un TTL más corto — y `operacion.md §4.4` explica por qué los dos mecanismos no se unifican hoy.

**Todo *listener* de los dos eventos es encolado** (`RN-BO-77`). En `1.6c` no hay ninguno —`ADR-045 §4.8` punto 3 deja el primero para `1.19`—, y la regla se escribe **antes** de que exista, porque un *listener* síncrono que lanzara convertiría un fallo de terceros en el fallo de una escritura ya confirmada, que es el mismo defecto del párrafo anterior por otra puerta.

#### 5.8.7 La masiva: un lote por dirección, una transacción por centro

`RunModuleRollout` (`operacion.md §6.1`) recorre la lista congelada de centros y, **para cada uno**, ejecuta las fases 1 y 2 completas de §5.8.5 y §5.8.6. Tres decisiones, con su motivo:

- **Una transacción por centro, no una sobre N** (`RN-BO-78`). Una sola transacción mantendría N bloqueos de fila de `tenants` durante todo el lote —parando de paso cualquier transición de estado de esos centros— y haría que el fallo del centro 57 deshiciera los 56 que ya estaban bien. Los centros se procesan en **orden ascendente de `tenants.id`**, para que dos lotes concurrentes no se interbloqueen.
- **Un fallo por centro no aborta el lote**: se registra y se continúa. Al final, **una** entrada `modulo.masivo_ejecutado` con `affected_tenant_id` **nulo** —es un resumen de la operación, no algo que le pase a un centro— y los recuentos en `context`: cuántos se pidieron, cuántos cambiaron, cuáles se omitieron y por qué, y cuáles fallaron. Los centros que sí cambiaron tienen además su entrada propia con su `affected_tenant_id`; los que fallaron **no tienen ninguna**, y eso es coherente: no les pasó nada.
- **Un lote no mezcla direcciones** (`RN-BO-79`). El cuerpo declara **un** `enabled` para todo el lote. Un lote mixto tendría que pasar entero por doble autorización —encareciendo una contratación de rutina— o partirse por dentro, y partirlo haría que la mitad se ejecutara de inmediato y la otra quedara pendiente de un segundo administrador: un `202` que significa dos cosas a la vez.

**La descontratación masiva pasa por §5.7**, y su ejecución tiene una particularidad que ninguna de las otras dos acciones del vocabulario tiene: **es asíncrona**. Al aprobarla, el servicio valida el *payload* congelado —los centros existen, el módulo existe, no es esencial— y **encola** el lote; `executed_at` marca **ese** instante, no el final (`RN-BO-80`). Es deliberado: una `dual_authorization` registra **la autorización**, no el resultado de doscientas operaciones independientes, y hacer que su estado dependiera de todas ellas le daría un estado que no significa nada. El resultado por centro está en `admin_action_logs`, que es donde se consulta.

#### 5.8.8 El motivo del operador, y a quién se lo estamos enseñando sin querer

**`1.6c` es el sub-paso que hace que `module_subscriptions.reason` contenga algo.** Hasta hoy nadie escribe `enabled` (`ADR-045 §1.1`) y esa columna es siempre nula; a partir de este sub-paso guarda texto libre redactado por un operador de plataforma: «impago del segundo trimestre», «rescisión, contrato 2026/27», «pruebas del comercial X».

Y `module_subscriptions` es **tabla de tenant**: su RLS le da al centro sus propias filas, y el `REVOKE` de `datos.md §7` sólo quita `INSERT` y `UPDATE`. **El `SELECT` sigue siendo de tabla completa, luego el centro puede leer ese motivo.**

Es exactamente el mismo problema que `datos.md §4.3.1` y `§5.3.1` resolvieron para `admin_action_logs` y `tenant_lifecycle_events`, con un criterio único que el usuario ratificó el 2026-09-08: **ningún texto libre escrito por un operador del proveedor cruza el `GRANT`**. La diferencia es que aquellas dos tablas nacían con el `GRANT` acotado y ésta ya existe con el `GRANT` abierto — y que aquí el argumento es **el mismo paralelo que §6.3 usó con el issue #7**: era inofensivo mientras nadie escribiera, y este sub-paso es el que deja de hacerlo cierto.

La recomendación es cerrarlo por columnas, con su coste dicho: `ModuleSubscription` tendría que declarar una proyección explícita en vez de `SELECT *`, y eso toca el `GET /modules` y el `PATCH settings` que `REQ-CORE` ya tiene en producción. **No lo decido**, porque cambia una migración ya especificada y toca *endpoints* de otro módulo: `OPEN-BO-19`, con la forma exacta en `datos.md §7.7`.

#### 5.8.9 Lo que este sub-paso deliberadamente **no** hace

- **No añade ni una columna** a `module_subscriptions` (`ADR-045 §4.2`, §5.8.1).
- **No materializa `depends_on` ni `essential` en `modules`** (`ADR-034 §5`, `ADR-045 §9`, `datos.md §8`). No se reabre.
- **No construye el aviso al centro.** `ADR-045 §4.8` punto 2 lo resuelve con `enabled_at`, que ya se rellena, y el realce en el panel del centro es trabajo de la interfaz de `REQ-CORE`, no de este sub-paso. La notificación de verdad es `REQ-COM-003`, paso 1.19.
- **No devuelve la potestad al centro.** `CA-CORE-061` se conserva y se refuerza: a partir de `1.6c` lo respalda un `REVOKE`, no un `if` (`ADR-045 §10`).
- **No toca `ModuleAvailability`, `EnsureModuleEnabled` ni `PermissionResolver`** (`ADR-045 §4.10`). Lo único que cambia dentro de `EloquentModuleAvailability` es de dónde sale `essential` y que ahora hay quien invalida su caché.

### 5.9 Ficha de salud del tenant (`REQ-BO-004`, parte) · sub-paso `1.6d`

Sólo lo que hoy es observable de verdad. **Nada de valores calculados de mentira.**

| Dato de `REQ-BO-004` | En `1.6d` |
|----------------------|--------|
| Versión desplegada | ✅ Es de plataforma, no por tenant. Se muestra una vez, marcada como de alcance global (`RN-BO-97`) |
| Últimas migraciones aplicadas | ✅ De la tabla `migrations`; también de plataforma |
| Jobs en cola / fallidos | ⚠️ De las tablas **`jobs` y `failed_jobs`** del *driver* `database`, filtrados por el `tenant_id` que `ADR-033 §8` estampa en el *payload*. **No de Horizon: Horizon no existe en este proyecto** (§5.9.1). Y ese filtro **no alcanza a los trabajos que despacha el propio backoffice** (§5.9.3) |
| Errores recientes | ⚠️ Recuento de `failed_jobs` del tenant en una ventana. **Sin agregación de logs de aplicación**: no hay recolector, y `ADR-037` no lo contempla |
| Uso de recursos frente a límites | ❌ No hay límites (`RMT-005` es `REQ-BO-003`) |
| Certificado SSL, caducidad, validación de dominio | ❌ Es infraestructura (Traefik/ACME) y `OPEN-08` sigue abierta. `REQ-CORE/funcional.md §1.2` ya lo difirió |
| Conectores externos, último volcado a Raíces | ❌ `REQ-SEC-004`, fase 2 |
| Reintento de jobs y reenvío de notificaciones (`REQ-SUP-004`) | ✅ Reintento de un job fallido de un tenant, auditado (§5.9.4). **Reenvío de notificaciones no**: no hay notificaciones (`REQ-COM`, 1.19) |
| Incoherencias de dependencias de módulo | ✅ `ADR-045 §4.5` pide que se muestren aquí las aristas que `platform:sync-registry` haya reportado y nadie haya resuelto. **Ya se calculan desde `1.6c`** (`api.md §2.6.2`): la ficha las reutiliza, no las recalcula |
| Estado del aprovisionamiento | ✅ **Añadido por `1.6d` y no está en el requisito**: es el `provisioning.state` que `api.md §2.4.1` ya deriva, y es la única fuente que hoy responde a «¿por qué lleva este centro dos horas en `en_alta`?» (§5.9.3) |

> Lo anterior es el inventario tal como lo escribió la revisión del chasis, **corregido en dos filas** y ampliado en una. Lo que falta para poder implementarlo —de qué tabla sale cada número, quién puede leerlo, por qué conexión, y qué pasa con los trabajos que el backoffice despacha sin tenant— es §5.9.1 a §5.9.6.

#### 5.9.1 Estado real del código, verificado y no supuesto

Verificado el **2026-09-16** sobre `develop` en `80397c2`, con el mismo método que §1 aplicó al chasis, §5.3 a `1.6b` y §5.8.1 a `1.6c`. **La conclusión primero, porque corrige una afirmación de esta misma especificación**: §5.9 decía que los trabajos en cola salen «de Horizon». **Horizon no existe en este proyecto y nada lo instala.**

| Pieza | Estado real |
|---|---|
| **Horizon** | ❌ **No existe.** `composer.json` no declara `laravel/horizon` —las cinco dependencias de producción son `laravel/framework`, `laravel/socialite`, `laravel/tinker`, `onelogin/php-saml` y `pragmarx/google2fa`—, no hay `config/horizon.php` y no hay ninguna unidad ni servicio suyo en `infra/`. `CLAUDE.md §1` lo nombra en la tabla de *stack* como intención; **la intención no es una dependencia instalada**, y añadirla exige justificarla (`CLAUDE.md §1`). Ver `OPEN-BO-23` |
| Cola por defecto | `config/queue.php`: `'default' => env('QUEUE_CONNECTION', 'database')`, y `'failed' => ['driver' => 'database-uuids', 'table' => 'failed_jobs']`. **El observatorio de colas de `1.6d` son dos tablas de PostgreSQL**, no un panel de Redis |
| `failed_jobs` | ✅ Existe (`0001_01_01_000002_create_jobs_table.php`): `{id, uuid UNIQUE, connection, queue, payload, exception, failed_at}`. **Sin `tenant_id`** — el tenant vive dentro de `payload`. Declarada en `shared_tables.platform` de `config/tenancy.php` |
| Privilegios de `failed_jobs` | ✅ **Endurecida desde `0.7`** (`2026_08_17_180000_harden_failed_jobs_grants.php`): `REVOKE SELECT, UPDATE, DELETE … FROM plataforma_app`. Conserva sólo `INSERT`, que es lo que el *worker* necesita para registrar su fallo. Su propio *docblock* dice que *«leer y gestionar la cola de fallos es un asunto de plataforma (`REQ-BO-004`)»*: **esta migración se escribió para este sub-paso, dos meses antes de que existiera** |
| `jobs` y `job_batches` | ⚠️ Existen y están declaradas en **`shared_tables.framework`, no en `platform`**, y **ninguna migración las endurece**: `plataforma_app` conserva `SELECT`, `INSERT`, `UPDATE` y `DELETE` sobre las dos. Es **necesario** para `jobs` —el *worker* corre por esa conexión y tiene que reservar y borrar—, y es un hueco declarado para `job_batches` (§5.9.6) |
| Estampado del tenant | ✅ `TenancyServiceProvider::registerTenantAwareQueues()`: `Queue::createPayloadUsing()` escribe `['tenant_id' => …]` **en la raíz del *payload*** y el *listener* de `JobProcessing` entra en ese tenant. **`null` cuando quien despacha no tiene tenant activo** — que es exactamente el caso del backoffice (§5.9.3) |
| `migrations` | ✅ Tabla del framework, declarada en `shared_tables.framework`. Legible por `plataforma_platform` |
| Versión desplegada | ✅ `config/app.php` declara `'version' => env('APP_VERSION', '0.1.0')`. **Existe la clave y existe la variable**; lo que hay que asegurar es que el despliegue la fije desde la etiqueta de imagen de `ADR-037` (`operacion.md §2`) |
| `job.reintentado` | ✅ **Ya está en el `CHECK` desplegado** de `admin_action_logs.action` (`2026_09_08_101000_create_admin_action_logs_table.php`, último valor de la lista). **`1.6d` no necesita ninguna migración de vocabulario** — es el único de los cinco sub-pasos del que eso es cierto |
| `salud.leer`, `job.reintentar`, `metrica.leer` | ❌ **No están en `PlatformCapability`.** Están en `permisos.md §3` y `§4` desde el chasis, y el *docblock* del propio `enum` dice que se declaran *«en `1.6c`/`1.6d`/`1.6e`, cuando exista el *endpoint* que las necesite»*. `1.6d` declara **exactamente esas tres** y ninguna más |
| Superficie de salud y métricas | ❌ **No existe ninguna.** `app/Modules/Backoffice/Http/routes.php` no declara `/health`, ni `/failed-jobs`, ni `/metrics`; no hay controlador con `Health`, `Metrics` ni `Job` en el nombre |
| `bo:retry-provisioning` | ⚠️ **Existe y está mal conectado.** `RetryProvisioningCommand` consulta `DB::table('failed_jobs')` por la **conexión por defecto** —`pgsql`, rol `plataforma_app`— y delega en `Artisan::call('queue:retry')`, que usa `config('queue.failed.database')`, es decir `DB_CONNECTION`, **la misma**. Con el `REVOKE SELECT, UPDATE, DELETE` de arriba desplegado, ese camino **no puede funcionar** en ningún entorno con los tres roles de `ADR-033 §5` aprovisionados: falla con error de privilegios. **No tiene test propio** —sólo una simulación del caso dentro de `CA-BO-108`—, que es por lo que nadie lo ha notado. Lo arregla `1.6d` reutilizando su servicio (§5.9.4) |
| `queue:prune-failed` | ⚠️ **Mismo defecto, y con peor consecuencia.** `routes/console.php` la programa a diario con `--hours=24` y el comentario dice para qué: es la **segunda capa del issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73)** —«un job de correo que agota sus 5 reintentos se queda en `failed_jobs` con un token de un solo uso en el *payload*»—. Usa el mismo proveedor del framework, luego el mismo `DB_CONNECTION`, luego el mismo `REVOKE DELETE`: **esa purga no puede borrar nada**, y la mitigación que cierra #73 no existe de hecho. Es el tercer caso del mismo defecto y el único con consecuencia de datos personales (§5.9.6) |

> **Y una consecuencia sobre el issue [#128](https://github.com/pirexia/plataforma-educativa/issues/128), que hay que decir para que nadie la lea como un bloqueo.** Ese issue registra que **no hay ningún *worker* desplegado todavía**. `1.6d` **no depende de que lo haya**: lee dos tablas y escribe en una tercera, y las tres existen y se llenan con `QUEUE_CONNECTION=database` sin más. Lo que sí produce un *worker* ausente es que `jobs` crezca y `failed_jobs` no —los trabajos se encolan y nadie los procesa—, y eso la ficha de salud lo enseña **tal cual**: es información, no un defecto de la ficha. Lo que `1.6d` **no** puede es probarse de extremo a extremo contra un despliegue real sin *worker*; sus tests usan la cola síncrona y la de base de datos, como el resto de la suite.

#### 5.9.2 Qué devuelve la ficha, bloque a bloque

Cuatro bloques, y **cada uno dice de dónde sale**, porque mezclar en una sola ficha datos de plataforma y datos del centro es la forma de que alguien busque «la versión de este centro»:

| Bloque | Alcance | Fuente |
|---|---|---|
| **Plataforma** | Global, idéntico para todos los centros | `config('app.version')` y las últimas migraciones aplicadas de la tabla `migrations` |
| **Estado del centro** | Del centro | `tenants`: `status`, `suspended_at`, `grace_period_ends_at`, `grace_period_expired_at`, y el `provisioning.state` derivado de `api.md §2.4.1` |
| **Trabajos del centro** | Del centro, **por `payload.tenant_id`** | Recuento de `jobs` pendientes, recuento de `failed_jobs`, recuento de `failed_jobs` en la ventana reciente, y la marca de tiempo del fallo más reciente. **Sólo alcanza a los trabajos despachados dentro del tenant** (§5.9.3), y **sólo a las últimas 24 horas** en cuanto la purga del hallazgo 1 de §5.9.6 funcione |
| **Última incidencia de plataforma** | Del centro, **por `affected_tenant_id`** | La entrada más reciente de `admin_action_logs` con `action IN ('tenant.aprovisionamiento_fallido', 'tenant.gracia_vencida')`. Es lo que cubre el caso de §5.3.5 y lo que el bloque anterior **no** puede cubrir (§5.9.3) |
| **Módulos** | Del centro | Recuento de contratados y **la lista de incoherencias de dependencia** que `1.6c` ya calcula (`api.md §2.6.2`). No se recalcula aquí: `RN-BO-22` exige una sola implementación y eso alcanza también a leerla |

**Un campo cuya fuente no existe no aparece** (`RN-BO-83`). Y su recíproco, que es la mitad que se olvida: **un recuento que sí se ha medido y vale cero se devuelve**, porque ahí el cero significa «medido y son cero» y no «no medido».

#### 5.9.3 A qué centro pertenece un trabajo: una contradicción de esta especificación, señalada y no resuelta por mí

**`ADR-033 §8` estampa en el *payload* el tenant que estaba activo al despachar.** Verificado sobre el código (§5.9.1): `Queue::createPayloadUsing()` escribe `tenant_id` con `TenantContext::tenantId()` cuando lo hay, y **`null` cuando no**.

**Los cuatro trabajos del backoffice se despachan sin tenant activo, y no por descuido**: `ADR-046 §6.4` **prohíbe** que haya tenant activo dentro de un bloque de plataforma, y `operacion.md §6.1` ya lo dice con todas las letras — *«estos trabajos los encola el backoffice, que no tiene tenant: el tenant afectado viaja como dato del trabajo, no como contexto heredado»*. Luego `ProvisionTenant`, `CloneTenant`, `RunModuleRollout` y `RevokeTenantSessions` llevan **`payload.tenant_id` nulo**.

**Consecuencia, y es la contradicción:** una lista de `failed_jobs` filtrada por `payload.tenant_id = T` **no contiene ninguno de esos cuatro**. Y esta especificación afirma dos veces lo contrario:

| Dónde | Qué afirma | Por qué es falso |
|---|---|---|
| §5.3.5 | *«El trabajo agota sus reintentos y queda en `failed_jobs`, **donde ya es visible por la ficha de salud del centro** (§5.9)»* | `ProvisionTenant` se despacha sin tenant: su fila existe en `failed_jobs`, pero no bajo el filtro del centro |
| `operacion.md §8` | «Un tenant lleva horas en `en_alta`» → primera comprobación: *«`failed_jobs` **del tenant**»* | Ídem |

**No lo resuelvo yo** (`OPEN-BO-20`), porque la salida limpia —que el estampado de `ADR-033 §8` sepa distinguir «el tenant en el que corro» de «el tenant al que afecto»— toca **infraestructura compartida por todo el producto** y la letra de un ADR vigente (`CLAUDE.md §11`), exactamente como `runAsPlatform()` tocaba `App\Support\Tenancy` y acabó necesitando `ADR-046 §6`.

**Lo que `1.6d` escribe mientras tanto, y es honesto sin inventar nada**: el bloque «trabajos del centro» de §5.9.2 se llama así y **no** «todos los trabajos que le afectan», y la ficha lleva **un quinto dato** que sí cubre el caso de §5.3.5 sin tocar ninguna infraestructura: **la última incidencia de plataforma que afecta al centro**, leída de `admin_action_logs` con `affected_tenant_id = T` y `action IN ('tenant.aprovisionamiento_fallido', 'tenant.gracia_vencida')`. Esa entrada **ya se escribe desde `1.6b`** (`datos.md §4.2.1`) y ya lleva el centro afectado, que es justo lo que al *payload* le falta.

> **Y hay que decir qué queda sin cubrir aun así**: `RunModuleRollout` fallido sobre un centro concreto no deja entrada propia —`RN-BO-78` es explícito: los centros fallidos **no tienen entrada propia** porque «no les pasó nada»—, así que el fallo de un lote sobre un centro sigue sin verse desde su ficha. Se ve desde `modulo.masivo_ejecutado`, que es de alcance global. **No se cambia `RN-BO-78` para arreglar esto**: sería reinterpretar una regla aprobada desde un sub-paso posterior, que es exactamente lo que `CLAUDE.md §3` prohíbe.

#### 5.9.4 Reintento de un trabajo fallido

`REQ-BO-004` pide «reintento de jobs» y `REQ-SUP-004` lo repite. Es **la única escritura de todo `1.6d`**, y tiene tres particularidades que deciden si funciona o sólo lo parece.

**1 · No puede apoyarse en el mecanismo por defecto del framework** (`RN-BO-86`). `queue:retry` y el `FailedJobProvider` usan `config('queue.failed.database')`, que es `DB_CONNECTION` —la conexión `pgsql`, rol `plataforma_app`—, y ese rol tiene **`REVOKE SELECT, UPDATE, DELETE`** sobre `failed_jobs` desde `0.7`. Todo el camino —localizar por `uuid`, reencolar y borrar la fila— corre por **`pgsql_platform`**, dentro de `runAsPlatform(PlatformAccessPurpose::BackofficeEscritura, …)`, cuya obligación de auditar (`ADR-046 §6.5`) la cumple la entrada `job.reintentado`.

**2 · El *payload* se reencola literal y nunca se recompone** (`RN-BO-85`). Dentro de ese *payload* viaja el `tenant_id` que `ADR-033 §8` estampó, y es **lo único** que hace que el *worker* vuelva a entrar en el contexto correcto al procesarlo. Recomponer el trabajo desde el backoffice —que por construcción no tiene tenant— produciría un *payload* con `tenant_id` nulo, y ese trabajo correría **sin contexto de tenant, sobre la conexión `plataforma_app`, escribiendo sin filtro de RLS**. Es el modo de fallo más grave de este sub-paso, no da ningún síntoma inmediato, y por eso es regla y tiene test propio (`CA-BO-152`).

**3 · Se ejecuta dentro de la petición, no en cola** (`RN-BO-89`). Es un `INSERT` en `jobs` y un `DELETE` en `failed_jobs`. `INV-012` habla de trabajo pesado; encolar un reintento es encolar el encolado.

**El orden, sin margen:**

1. Capacidad `job.reintentar` (`permisos.md §4.5`) y reautenticación viva (`OPEN-BO-21`).
2. El tenant existe y **su estado admite reintento**: `en_alta`, `activo`, `suspendido` y `en_baja` sí; `eliminado` **no**, `409` con `bo.job.tenant_state_invalid` (`RN-BO-87`).
3. `reason` presente y no vacío, `422` con `bo.job.reason_required`.
4. La fila de `failed_jobs` con ese `uuid` existe **y su `payload.tenant_id` es el del centro de la ruta**; si no, **`404`** — no `403`: un `403` confirmaría que ese `uuid` existe en otro centro (`CA-BO-153`).
5. Dentro de `runAsPlatform(BackofficeEscritura, …)` y de una transacción por `pgsql_platform`: se reencola el *payload* tal cual sobre **la conexión y la cola que la propia fila declara** (`failed_jobs.connection`, `failed_jobs.queue`), con los intentos reiniciados; se borra la fila; se escribe `admin_action_logs` con `action = 'job.reintentado'`, `affected_tenant_id`, `subject_public_id = uuid` y el motivo.
6. `200` con el recuento actualizado de trabajos fallidos del centro.

**Tres precisiones sobre la entrada de auditoría**, verificadas contra `AdminActionLogRecorder::record()` y no supuestas:

- **`action = 'job.reintentado'` ya está en el `enum` `AdminActionLogAction` y en el `CHECK` desplegado.** No hay migración de vocabulario y no hay que tocar el enumerado (§5.9.1).
- **`subject_type` estrena un valor: `'failed_job'`.** Los cuatro que se escriben hoy son `platform` (el valor por omisión del grabador), `tenant`, `dual_authorization` y `module_subscription`. Esa columna **no tiene `CHECK`** —a diferencia de `action`—, así que el valor nuevo no es una migración, pero **sí es vocabulario** y se declara en `datos.md §4.1` como los otros cuatro. No se reutiliza `tenant`: el sujeto de la acción es el trabajo, y el centro ya viaja en `affected_tenant_id`.
- **`subject_id` queda nulo y `subject_public_id` lleva el `uuid` del trabajo.** El `failed_jobs.id` es una clave interna de una tabla del framework que no se expone nunca (`ADR-029`), y el `uuid` es lo que el operador tiene delante y lo que sobrevive al borrado de la fila — que es precisamente lo que el reintento hace con ella.

**Lo que no hay, y hay que decirlo porque las tres se esperan:**

- **No hay reintento masivo ni «reintentar todo»** (`RN-BO-88`). `REQ-BO-004` pide «reintento de jobs», uno a uno. Un `retry all` reejecutaría efectos secundarios —correos a familias incluidos— sobre todos los centros a la vez sin que nadie haya mirado ninguno: es una acción destructiva, y `REQ-BO-007` exige doble autorización a las destructivas. Si algún día hace falta, entra por §5.7 con su propio valor de vocabulario, no por una bandera en este *endpoint*.
- **No hay reenvío de notificaciones fallidas.** `REQ-BO-004` lo pide junto al reintento y **no hay notificaciones**: `REQ-COM` es el paso 1.19. Mismo argumento con el que `ADR-045 §4.8` se negó a adelantarlo para el aviso de módulo contratado.
- **No hay borrado de un trabajo fallido.** Un `DELETE /failed-jobs/{uuid}` sería «haz desaparecer la prueba de que esto falló» sin ningún rastro de negocio. El único camino por el que una fila de `failed_jobs` desaparece en `1.6d` es un reintento, que sí queda auditado con su actor y su motivo.

**Y `bo:retry-provisioning` pasa a usar el mismo servicio** (§5.9.1, última fila). Dos caminos —consola y API—, una sola implementación, exactamente el criterio con el que `RN-BO-22` exige una sola implementación de las dependencias de módulo y con el que `ADR-048` conservó el comando de aprovisionamiento llamando a la misma interfaz. El comando sigue sin necesitar capacidad y sigue auditándose con `actor_type = 'console'`.

#### 5.9.5 Qué **no** devuelve la ficha, y por qué es una regla y no una omisión

**El *payload* de un trabajo contiene datos personales del centro.** No es una posibilidad teórica: `SendInvitationEmail` serializa el correo y el nombre del invitado, `SendPasswordResetEmail` y `SendAccountLockedEmail` lo mismo, y cualquier trabajo futuro sobre alumnado llevará dentro a un menor. `RN-BO-33` y `REQ-BO-007` prohíben que el backoffice muestre datos personales de los centros, y `CA-BO-074` lo comprueba recorriendo **todas** las respuestas del módulo.

Por eso `RN-BO-84`: la ficha y el listado devuelven **`uuid`, `queue`, `connection`, `failed_at`, la clase del trabajo** —`payload.displayName`, que es un nombre de clase PHP y no un dato— **y la clase de la excepción**. Nunca el *payload*, nunca la traza completa.

> **Queda un borde, y lo señalo en vez de resolverlo** (`OPEN-BO-22`): **el mensaje** de la excepción. Sin él, el diagnóstico —que es literalmente el título de `REQ-BO-004`— no existe: «`QueryException`» no le dice nada a nadie. Con él, un mensaje de PostgreSQL puede arrastrar el valor que violó una restricción, y ese valor puede ser el correo de una familia. Esta especificación está escrita contra **devolverlo**, porque la alternativa vacía el requisito, y deja escrito que es una excepción consciente a `RN-BO-33` y no un descuido.

#### 5.9.6 Tres hallazgos sobre privilegios, y `1.6d` corrige dos

Los tres salen de leer el código para escribir esto, los tres son **anteriores** a este sub-paso y ninguno es suyo. Se escriben aquí —y se abren como issue— en vez de arreglarse en silencio (`CLAUDE.md §5`, issue [#150](https://github.com/pirexia/plataforma-educativa/issues/150)).

**Los tres comparten una sola causa raíz, y conviene nombrarla antes que los síntomas**: `failed_jobs` se endureció en `0.7` dejando a `plataforma_app` sólo `INSERT`, pero **el proveedor de trabajos fallidos del framework sigue apuntando a esa misma conexión** (`config('queue.failed.database')` es `env('DB_CONNECTION')`). Todo lo que el framework hace con `failed_jobs` que no sea insertar —listar, reintentar, purgar— **falla por privilegios**. Es la contrapartida exacta del bug 6 de `0.7`: entonces faltaba el `REVOKE`; ahora sobra respecto de quien lo usa.

| # | Hallazgo | ¿Lo corrige `1.6d`? | Severidad propuesta |
|---|---|---|---|
| 1 | **`queue:prune-failed` no puede borrar nada.** Programada a diario en `routes/console.php` con `--hours=24` **como segunda capa del issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73)**: los *payloads* de `SendPasswordResetEmail` y `SendAccountLockedEmail` llevan un token de un solo uso y, aunque van cifrados (`ShouldBeEncrypted`), la decisión era no conservarlos más de 24 horas. **Esa purga lleva desde `0.7` sin borrar una sola fila.** | **Sí** (decisión del usuario, 2026-09-16). Comando propio del módulo por `pgsql_platform`: **§5.9.7**, `RN-BO-98`, `CA-BO-166` | **Alta**: una mitigación de datos personales que se creyó desplegada y no lo está |
| 2 | **`bo:retry-provisioning` no puede funcionar** (§5.9.1). Sin test propio, por eso nadie lo ha notado | **Sí.** Pasa a usar el servicio de §5.9.4, por `pgsql_platform`. Regresión: `CA-BO-164` | Media |
| 3 | **`jobs` y `job_batches` no están endurecidas.** `plataforma_app` puede leer, actualizar y borrar **todas** las filas de las dos, y el *payload* de un trabajo en cola lleva dentro datos del centro que lo despachó. Para `jobs` es **inevitable** con el *driver* `database` —el *worker* corre por esa conexión y tiene que reservar y borrar—, y por eso está en `shared_tables.framework` y no en `platform`. **Para `job_batches` no lo es**, y nada del producto la usa todavía | **No.** Endurecer `job_batches` no lo necesita este sub-paso y tocar `jobs` exige decidir antes por qué conexión corre el *worker* | Media (`job_batches`) · Declarada y aceptada (`jobs`) |

> **Y una consecuencia operativa del hallazgo 1 que hay que tener presente ahora que se arregla**: en cuanto esa purga funcione, **la ficha de salud sólo verá los fallos de las últimas 24 horas**, y `bo:retry-provisioning` sólo podrá reparar un aprovisionamiento durante ese mismo plazo. No es un defecto de `1.6d` —es la retención que el issue #73 fijó a propósito— pero **hoy no se nota porque la purga no borra, y a partir de `1.6d` sí se notará**. Es lo más parecido a una regresión visible que produce este sub-paso, y por eso está en `operacion.md §8` y no sólo aquí.

#### 5.9.7 El arreglo del hallazgo Alta: `bo:purge-failed-jobs` · **decisión del usuario, 2026-09-16: entra en `1.6d`**

**Qué se arregla, en una frase**: `queue:prune-failed` lleva desde `0.7` sin borrar una sola fila, y con ella la segunda capa del issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73) —*«nunca guardar más de lo necesario, ni siquiera cifrado»*— **está documentada y no aplicada**. El arreglo entra aquí porque `1.6d` es el sub-paso que construye el único camino de acceso que le falta: `pgsql_platform` sobre `failed_jobs` (`RN-BO-86`).

**El diseño, sin margen:**

| Pieza | Decisión |
|---|---|
| **Comando** | **`bo:purge-failed-jobs`**, en `app/Modules/Backoffice/Infrastructure/Console/`. El prefijo y el verbo son los del módulo —`bo:purge-mfa-challenges`, `bo:purge-idempotency-keys`—, no los del framework: `prune` es de Laravel y `purge` es de este proyecto |
| **Conexión** | **`pgsql_platform`**, el único rol con `DELETE` sobre `failed_jobs` (`RN-BO-86`). Un `DELETE FROM failed_jobs WHERE failed_at < now() - interval`, sin más |
| **Contexto** | **Ninguno.** `failed_jobs` es tabla de plataforma (`shared_tables.platform`): corre fuera de todo tenant y **no usa `RunsPerTenant`**, exactamente como `CloseOrphanedPlatformSessions` (`operacion.md §6.3`) |
| **`runAsPlatform()`** | **No.** Sigue el precedente que ya existe en el módulo —`CloseOrphanedPlatformSessions` escribe por `pgsql_platform` directamente— y **no inventa una forma nueva**. Queda dicho que si algún día se decide que toda escritura por `pgsql_platform` pase por la primitiva (issue [#219](https://github.com/pirexia/plataforma-educativa/issues/219), Baja, documentado sin corregir), este comando entra en ese lote con los demás, no antes |
| **Plazo** | **24 horas**, las del issue #73. Constante literal en `config/backoffice.php`, **sin `env()`** — sería la primera clave de ese fichero que no lee del entorno, y **eso es exactamente el punto** (`RN-BO-98`): una variable que la alargue anula en silencio una mitigación de datos personales. Mismo criterio que `RN-BO-61` con los 90 días del período de gracia |
| **Programación** | `Schedule::command('bo:purge-failed-jobs')->daily()` en `routes/console.php`, **sustituyendo** la línea `Schedule::command('queue:prune-failed', ['--hours' => 24])->daily()`. **Sustituyendo, no acompañando**: dejar las dos significa que una de ellas falla a diario en silencio, que es el estado del que se sale |
| **Auditoría** | **Ninguna entrada en `admin_action_logs`.** No hay sujeto ni decisión: es mantenimiento de retención, igual que las otras dos purgas del módulo, y `ADR-046 §6.2` ya decidió que el mantenimiento sin sujeto no tiene nada que registrar. El recuento de filas borradas va al registro de la tarea, que es donde se mira |

**Una decisión que se aparta de los otros dos comandos `bo:` del módulo, y hay que defenderla: este comando ejecuta el borrado él mismo, no despacha un trabajo en cola.** `bo:purge-idempotency-keys` y `bo:close-orphaned-sessions` despachan `PurgePlatformIdempotencyKeys` y `CloseOrphanedPlatformSessions` a la cola `backoffice-maintenance`. Aquí **no**, por dos motivos y no por gusto:

1. **Una purga encolada no purga si no hay quien la procese.** Hoy **no hay ningún *worker* desplegado** (issue [#128](https://github.com/pirexia/plataforma-educativa/issues/128)). Poner la mitigación de #73 detrás de una cola que nadie vacía es repetir el mismo fallo con otra forma: seguiría documentada y sin aplicarse. El planificador, en cambio, **sí** corre en su propio contenedor (`ADR-037`).
2. **Un trabajo que limpia `failed_jobs` puede acabar dentro de `failed_jobs`.** Si falla, ensucia justo la tabla que existe para vaciar, y se queda ahí hasta que la siguiente ejecución —que también podría fallar— lo borre. Es un lazo que no aporta nada.

**Y un `DELETE` acotado por fecha no es trabajo pesado**, así que `INV-012` no obliga a encolarlo: la regla habla de lo que no cabe en el ciclo de una petición, y esto ni siquiera ocurre en una petición.

**Lo que este arreglo toca fuera de `REQ-BO`, enumerado y cerrado** —porque retirar una línea del planificador afecta a documentación de otros dos módulos y a un documento raíz (`operacion.md §10`)—:

| Dónde | Qué dice hoy | Qué hay que corregir |
|---|---|---|
| `routes/console.php` | `Schedule::command('queue:prune-failed', ['--hours' => 24])->daily();` con un comentario que atribuye la línea al issue #73 | Se sustituye por la del comando nuevo, **conservando el comentario y su referencia al issue**: el motivo de la línea no cambia, cambia quién la ejecuta |
| `docs/modulos/REQ-AUTH/operacion.md` | Su tabla de trabajos programados lista `queue:prune-failed --hours=24` como «comando directo», y **tres párrafos más** lo citan como *«la segunda capa»* de #73 | Las cuatro pasan a nombrar `bo:purge-failed-jobs` y a decir que **la purga es de `REQ-BO`**, no de `REQ-AUTH`. Es coherente con `INV-007` y con el *docblock* de la migración de `0.7`: *«gestionar la cola de fallos es un asunto de plataforma (`REQ-BO-004`)»* |
| `docs/modulos/REQ-CORE/operacion.md` | Menciona la misma tarea | Ídem |
| `RUNBOOK.md` | Ídem | Ídem |
| `CHANGELOG.md`, `docs/historial/*` | La nombran al relatar `1.2` y el cierre de #73 | **No se tocan.** Son historia y decían la verdad cuando se escribieron |

> **Ninguna de esas cuatro correcciones es opcional y ninguna es de `doc-reviewer` «si le da tiempo»**: una tarea programada retirada que sigue documentada en tres sitios es exactamente el tipo de desincronización que motivó la regla 7 de `CLAUDE.md §6`.

**Y una verificación que ya está disponible y conviene aprovechar**: `MfaMaintenanceJobsTest` inspecciona el planificador de verdad —`app(Schedule::class)`, busca el evento por su `command` y comprueba su expresión— para `auth:purge-maintenance`. `CA-BO-166` usa **ese mismo patrón**, en las dos direcciones: que `bo:purge-failed-jobs` esté programado y que `queue:prune-failed` **ya no lo esté**. **Verificado que hoy ningún test comprueba la línea que se retira**, así que sustituirla no rompe nada existente — y esa ausencia de test es, precisamente, por lo que el defecto ha vivido dos meses.

### 5.10 Métricas de plataforma (`REQ-BO-006`, parte) · sub-paso `1.6d`

| Métrica | En `1.6d` |
|---------|--------|
| Tenants por estado, altas y bajas | ✅ De `tenants` y `tenant_lifecycle_events` (§5.10.1) |
| Adopción por módulo | ✅ De `module_subscriptions` sobre el **catálogo declarado**: cuántos centros tienen contratado cada módulo (§5.10.2) |
| Alumnos totales | ❌ `REQ-ALUM`, paso 1.15 |
| Ingresos recurrentes, *churn* | ❌ `REQ-SAAS-004`, fase 2 |
| Consumo de recursos frente a límites | ❌ No hay límites |
| Alertas de salud comercial | ❌ Necesita tickets (`REQ-SUP`) e impagos (`REQ-SAAS`) |

**El panel de `1.6d` muestra dos números honestos en vez de seis inventados.** Es preferible a rellenar con ceros: un cero indistinguible de «no medido» es peor que la ausencia.

#### 5.10.1 Tenants por estado, altas y bajas: las tres definiciones, sin ambigüedad

Son tres frases del requisito y las tres admiten dos lecturas. Se fijan aquí porque una métrica cuya definición no está escrita es una métrica que cambia de significado cada vez que alguien la reimplementa.

| Métrica | Definición exacta | La lectura que se descarta, y por qué |
|---|---|---|
| **Tenants por estado** | `COUNT(*)` agrupado por `tenants.status`, sobre los cinco valores de `TenantStatus`, **incluyendo los borrados lógicos** (`RN-BO-91`) | Excluir `deleted_at` dejaría la fila `eliminado` **siempre a cero**, porque la eliminación escribe las dos cosas a la vez (`funcional.md §5.5.2`). Es el mismo defecto por la vía equivocada que `RN-BO-50` corrigió en `ResolveTenant`, y aquí se evita antes de que ocurra |
| **Altas del período** | Filas de `tenant_lifecycle_events` con **`from_status IS NULL`** —la única transición que lo tiene— y `occurred_at` dentro de la ventana | `tenants.created_at` parece más directo y no sirve: no responde «cuántas altas hubo **en este período**» si la fila se eliminó después, y la tabla de historial es *append-only* (`datos.md §5.2`), así que su respuesta no cambia con el tiempo |
| **Bajas del período** | Filas con **`to_status = 'en_baja'`** en la ventana. Las eliminaciones (`to_status = 'eliminado'`) se devuelven **en una serie aparte**, nunca sumadas (`RN-BO-92`) | Sumarlas mezclaría el mes en que un centro se fue con el mes en que se cerró su expediente, y entre los dos hay **90 días de gracia** por diseño. Sumadas, un mes cualquiera contaría dos veces al mismo centro |

**El *churn* no se calcula** y no es un olvido: `REQ-BO-006` lo remite a `REQ-SAAS-004`, que es fase 2, y calcularlo exige una base de clientes facturables que no existe (`funcional.md §2.2`). Devolver «bajas ÷ activos» llamándolo *churn* sería inventar una métrica de negocio desde un panel técnico.

**La ventana** son dos parámetros `occurred_at_from` y `occurred_at_to`, inclusivos, con la sintaxis de `ADR-038 §5.2`; por omisión, los últimos 30 días. `422` si el principio es posterior al final. **El recuento por estado no lleva ventana**: es una foto del ahora, y decirlo evita la pregunta obvia de por qué un parámetro afecta a dos bloques y al tercero no.

#### 5.10.2 Adopción por módulo: el esqueleto es el catálogo, no las filas

`REQ-BO-006` lo pide «para decidir inversión de producto», y esa frase decide la forma: **la lista la marca el catálogo declarado** (`ModuleCatalog::all()`, el contrato que `1.6c` puso en `Core\Domain`), no las filas que existan en `module_subscriptions`.

- **Un módulo sin una sola contratación aparece, con cero.** Y ese cero **sí** se devuelve, sin contradecir `RN-BO-83`: aquí significa «medido y son cero», que es precisamente la respuesta que un responsable de producto necesita — un módulo que no aparece es indistinguible de uno que nadie ha construido.
- **Los módulos `essential` se devuelven marcados y sin recuento de contratación** (`RN-BO-93`). No tienen fila por diseño (`RN-BO-65`), así que un `0` diría que ningún centro tiene `core`, que es falso, y un recuento igual al total sería un número inventado con aspecto de medido.
- **Los módulos con `retired_at` siguen apareciendo**, con su marca: el catálogo nunca borra (`SyncModuleRegistry`) y una suscripción viva a un módulo retirado se factura (`RMOD-007`, `RN-BO-70`). Desaparecerlos del panel escondería exactamente lo que hay que ver.
- **El denominador viaja en la respuesta.** Centros vivos y no `eliminado`. Un porcentaje sin denominador es una cifra que nadie puede comprobar, y a los tres meses nadie recuerda si incluía a los suspendidos.

#### 5.10.3 Cómo se leen sin romper el aislamiento, y cuál es el modo de fallo

Las dos métricas recorren **todos** los centros, que es justo lo que ninguna otra parte del producto puede hacer.

- Corren dentro de **`runAsPlatform(PlatformAccessPurpose::BackofficeLectura, …)`**, que **no obliga a escribir en `admin_action_logs`** (`ADR-046 §6.2`). Es exactamente el caso para el que ese propósito se separó del de escritura, y decirlo evita que alguien «complete» la auditoría inventándose una acción que registrar — *«una obligación que hay que falsear se acaba desactivando»*.
- **`module_subscriptions` es tabla de tenant y aun así el agregado se escribe con el modelo de siempre.** Verificado sobre el código, no supuesto: en modo plataforma `TenantScope::apply()` **retorna sin añadir ningún `where`**, y `TenantModel::getConnectionName()` **devuelve `pgsql_platform`**, que tiene `BYPASSRLS`. No hay que tocar conexiones a mano ni usar `withoutGlobalScope()`, que además está prohibido en `app/Modules/**` por un test de arquitectura de `0.7.11`.
- **Y ese mismo hecho es el riesgo** (`RN-BO-94`): una consulta de este sub-paso que se escape del bloque de plataforma **no falla**, devuelve el recuento de un solo centro. Una métrica silenciosamente equivocada es peor que un error, porque se usa para decidir. Por eso las dos consultas viven detrás de una sola puerta y por eso su test de aislamiento usa **tres** centros y comprueba el total, no sólo que no se filtre nada.
- **Ninguna métrica se cachea en `1.6d`** (`RN-BO-95`), con el disparador de revisión escrito en `operacion.md §7`: dos `COUNT` agrupados sobre doscientos centros es trabajo despreciable, y una métrica cacheada es una métrica que alguien lee desactualizada **mientras decide**. Es la decisión contraria a la de `§4.3` de `operacion.md` para los *flags*, y lo es porque el problema es el contrario: allí se lee en cada petición de cada centro, aquí lo lee un operador unas veces al día.

#### 5.10.4 Lo que este sub-paso deliberadamente **no** hace

- **No crea ni una tabla, ni una columna, ni un índice** (`datos.md §14`). Todo sale de consultas sobre lo que ya existe.
- **No materializa ninguna métrica** en una tabla de recuentos diarios. Sería una segunda fuente de verdad que se desincroniza, y no hay ninguna consulta cara que lo justifique — el disparador para reconsiderarlo está escrito en `operacion.md §7`.
- **No añade ningún trabajo en cola ni ninguna tarea programada** (`operacion.md §6.1`, `§6.2`). Si en la implementación aparece un *job* de «consolidar métricas», el diseño se ha desviado de §5.10.3.
- **No expone ningún dato personal de ningún centro** (`RN-BO-33`), ni siquiera el nombre de un usuario dentro del *payload* de un trabajo (§5.9.5).

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

El punto 1 (test de arquitectura) lo cerró 1.5 con `RunAsPlatformArchitectureTest` (`CA-PERM-092`) y una lista blanca de **tres apariciones** en `app/`. Quedan los puntos 2 y 3, re-etiquetados a este paso, y **`ADR-046 §6` los decide**.

> **Corrección de una imprecisión de la revisión anterior**, señalada por `ADR-046 §11.3`: este documento hablaba de «sus tres llamadores». Son **dos llamadores reales** —`RunsPerTenant::eachTenant()` y `PurgeExpiredIdempotencyKeys::handle()`— más la **definición** del método en `App\Support\Tenancy\TenantContext`, que no es un llamador. Las tres apariciones de la lista blanca del test no son tres llamadas. Sin consecuencia sobre la decisión, pero la especificación tiene que decirlo bien.

**La propuesta literal del issue produce algo peor que el problema**, y `ADR-046 §6` lo confirma contra el código:

- **Auditar cada llamada a `runAsPlatform()` no sirve.** Sus dos llamadores reales son mantenimiento **sin sujeto** y **corren sin tenant activo**: `eachTenant()` lo usa para *listar* los tenants antes de iterar con `runFor()`, y `PurgeExpiredIdempotencyKeys` se despacha desde `routes/console.php` fuera de todo contexto de tenant. Auditar cada iteración llenaría `admin_action_logs` de entradas sin significado de negocio. **Lo que se audita es la operación** («este administrador suspendió este centro»), no la primitiva que la ejecuta.
- **Comprobar un permiso dentro de la primitiva tampoco sirve**, por lo mismo: un comando de consola no tiene sujeto al que comprobarle nada.

**`ADR-046 §6` acepta el cambio de firma y lo concreta hasta el punto en que ni `spec-writer` ni `implementer` deciden nada.** Tres cambios respecto de lo que yo proponía, cada uno con su motivo: el propósito de backoffice se parte en **lectura y escritura**, la primitiva **exige ausencia de tenant activo**, y la obligación de auditar deja de ser una promesa y pasa a ser una **comprobación al cierre del bloque**.

#### 6.2.1 La firma, exacta (`ADR-046 §6.1`)

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

El propósito es **el primer parámetro y no tiene valor por defecto**, deliberadamente: así ninguna llamada existente sigue compilando sin tocarla, y ningún llamador futuro hereda un propósito por omisión. **Los dos llamadores actuales pasan `PlatformAccessPurpose::Mantenimiento`.** El *enum* vive en `App\Support\Tenancy`, junto a `TenantContext`, `TenantScope` y `TenantContextMissing` —es infraestructura de aislamiento, no de `REQ-BO`—, con nombres de caso en español, como `TenantStatus::Activo` y `SessionEndReason::RevocadaUsuario`.

#### 6.2.2 Tres propósitos, y por qué no dos

Yo proponía dos (`Mantenimiento` / `Backoffice`) y que `Backoffice` obligara siempre a escribir en `admin_action_logs`. **Eso obligaría a una operación de sólo lectura —el rol `soporte`, «solo lectura y diagnóstico» según `REQ-BO-007`, listando el inventario— a inventarse una acción que registrar. Una obligación que hay que falsear se acaba desactivando.**

| Propósito | Quién puede | Obligación de auditoría |
|---|---|---|
| `Mantenimiento` | Sólo con `app()->runningInConsole()` verdadero —comandos, tareas programadas y *workers* de cola, que corren bajo `artisan`—. **Desde una petición HTTP lanza excepción**, sin exenciones: `INV-012` ya obliga a que lo pesado vaya en colas, así que no existe un caso legítimo | Ninguna. Sin sujeto no hay nada que registrar |
| `BackofficeLectura` | Administrador de plataforma autenticado en el *guard* `platform`, con la capacidad que el llamador ya comprobó | Ninguna en `admin_action_logs`. La auditoría de **lectura** de datos de categoría especial (`CLAUDE.md §8`) es otra cosa y sigue su propia regla |
| `BackofficeEscritura` | Igual que la anterior | **Obligatoria**, comprobada al cierre (§6.2.4) |

#### 6.2.3 Dónde vive la comprobación, sin romper `INV-007` (`ADR-046 §6.3`)

`TenantContext` está en `App\Support` y **no puede importar `App\Modules\Backoffice`**. La primitiva no sabe qué es un administrador de plataforma: lo pregunta.

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

- Enlace **por defecto** en `App\Support\Tenancy` (`DefaultPlatformAccessCheck`): permite `Mantenimiento` bajo consola y **deniega los dos propósitos de backoffice**. Consecuencia buscada: mientras `REQ-BO` no exista, los propósitos de backoffice son **inutilizables**, no «permitidos porque todavía no hay quien compruebe» (`INV-002`, denegar por defecto).
- El `ServiceProvider` de `App\Modules\Backoffice` **sustituye** el enlace por su implementación, que sí sabe consultar el *guard* `platform` y `admin_action_logs`.
- `after()` se llama en el `finally`, **siempre**, también si el *callback* lanzó.

#### 6.2.4 Ausencia de tenant activo, y la regla de cierre (`ADR-046 §6.4`, `§6.5`)

**`runAsPlatform()` lanza excepción si `hasTenant()` es verdadero, con cualquier propósito.** No es celo: la implementación actual **no limpia `tenantId`**, y el comentario que ya existe en `AuditRecorder` describe con precisión el fallo que esa combinación permite —escribir con el `tenant_id` equivocado o violar un `NOT NULL`, sin `TenantScope` que filtre y sobre una conexión `BYPASSRLS`—. Verificado que **no rompe nada**: los dos llamadores actuales corren sin tenant.

- El acceso a datos de un tenant concreto desde el backoffice se hace fijando `tenant_id` **a mano** dentro del bloque, que es lo que el *docblock* de `BelongsToTenant` ya prescribe para modo plataforma.
- La invalidación de caché de `RN-BO-25`/`ADR-045 §8.3`, que necesita el prefijo `t{id}:`, se hace **fuera** del bloque de plataforma, con `runFor($tenantId, …)`. Nunca componiendo el prefijo a mano desde dentro de modo plataforma (`operacion.md §4.2`).
- **`REQ-SUP-003` (impersonación) es la única funcionalidad prevista que podría querer la combinación prohibida.** No la necesita —una impersonación entra en un tenant *como usuario de ese tenant*, por `plataforma_app`— pero si su diseño de fase 2 concluyera lo contrario, **exigirá un ADR nuevo que sustituya esta decisión**.

**Un bloque `BackofficeEscritura` que termina sin ninguna entrada en `admin_action_logs` lanza excepción al cerrarse.** Lo comprueba `after()`, contando las entradas registradas por el grabador de acciones de plataforma dentro del bloque. El motivo es que esta decisión **retira una barrera** y hay que sustituirla, no eliminarla: hoy `AuditRecorder` lanza en modo plataforma y por eso ninguna escritura de plataforma pasa desapercibida, pero `ADR-045` obliga al backoffice a escribir `module_subscriptions` —`Auditable` con política `Full` y **tabla de tenant**—, cuyo rastro no puede ir a `audit_logs` porque no hay tenant en contexto. Silenciar la excepción sin más cambiaría un fallo ruidoso por un silencio.

Regla nueva de `AuditRecorder`, precisa (`ADR-046 §6.5`):

| Modo | Comportamiento |
|---|---|
| Sin modo plataforma | Sin cambios |
| Modo plataforma, propósito `Mantenimiento` | **Lanza**, con el mensaje actual. Sin cambios: defensa en profundidad, no redundancia a retirar |
| Modo plataforma, propósito de backoffice | **Retorna en silencio.** El rastro es `admin_action_logs` y su obligación la garantiza la regla de cierre, no este método |

El actor lo aporta `AuditActor::actingAs('platform', …)`, que ya está previsto en su propio *docblock*. **No se toca el vocabulario de `audit_logs`** de `ADR-039`: `admin_action_logs` es otra tabla con su propio vocabulario (`datos.md §4.2`).

#### 6.2.5 El test de arquitectura crece, y no se afloja (`ADR-046 §6.7`)

`RunAsPlatformArchitectureTest` (`CA-PERM-092`) **mantiene su lista blanca fichero a fichero**. Queda **prohibido** convertirla en un comodín de directorio del tipo `app/Modules/Backoffice/**`: eso vaciaría el test precisamente en el módulo que más lo necesita. Consecuencia práctica para `1.6`: el backoffice canaliza **todo** su acceso de plataforma por un conjunto **acotado y nombrado** de clases, que se añaden a la lista una a una y con su justificación en el propio test. Si esa lista crece sin control, es señal de un diseño mal repartido, y el test es lo que lo hace visible.

Aserción nueva: **ninguna llamada a `runAsPlatform()` en `app/` pasa un propósito calculado en tiempo de ejecución**; el argumento es siempre un caso literal del *enum* (`CA-BO-029`). Un propósito que dependa de una variable es un propósito que un día valdrá lo que convenga.

**Esto cambia una firma de `App\Support\Tenancy`, infraestructura compartida por todo el producto**, y por eso el trabajo cae dentro del sub-paso `1.6` aunque no sea código de `REQ-BO` (§12.2). Además de los dos llamadores, hay **cuatro ficheros de test** que actualizar —`TenantModelTest`, `AuditObserverTest`, `CorePurgeJobsTest`, `RunAsPlatformArchitectureTest`—, y `AuditObserverTest` **cambia de significado**: el caso que documentaba —modo plataforma con tenant activo— deja de ser «no ocurre hoy» y pasa a ser «lanza siempre» (`ADR-046 §8`).

### 6.3 Issue #7 · Caché de resolución de tenant · **lo cierra el sub-paso `1.6b`**

`ResolveTenant` cachea `{id, status}` durante 60 s bajo `tenant-resolution:{slug}`. Hoy es inofensivo porque nadie cambia `status`; **este sub-paso es exactamente el que deja de hacerlo inofensivo**, y por eso el propio issue lo declara *«bloqueante al implementar `REQ-BO-001`»*.

`RN-BO-14` lo convierte en regla: **toda escritura de `tenants.status` invalida esa clave en la misma operación**, no como paso posterior. Con test de que un tenant recién suspendido deja de resolver **de inmediato**, no en hasta 60 s (`CA-BO-054`).

**El mecanismo, con el detalle que hace falta para no implementarlo mal:**

1. **Un `Cache::forget("tenant-resolution:{$slug}")` basta, y aquí sí.** Es la diferencia con el caso difícil de `ADR-045 §8.3` (§6.2 de `operacion.md`), y el motivo es de orden de ejecución: `ResolveTenant` escribe esa clave **antes** de que `TenantContext::enter()` cambie `cache.prefix` a `t{tenant_id}:`, así que vive bajo el prefijo base. El backoffice corre **sin tenant**, luego también escribe bajo el prefijo base. **Las dos partes nombran la misma clave sin hacer nada especial.** `modules:{code}:enabled`, en cambio, vive bajo el prefijo del tenant y por eso exige entrar en su contexto. **No se unifiquen los dos mecanismos** (`operacion.md §4.4`).
2. **Se invalida después de confirmar la transacción, nunca dentro.** Invalidar dentro abre una ventana real: entre el `forget` y el `COMMIT`, una petición concurrente relee de la base de datos el valor **anterior** y lo vuelve a cachear 60 s. El resultado sería un tenant suspendido que sigue sirviendo, que es exactamente el defecto que se está cerrando. Va en el `afterCommit` de la transacción, no en su cuerpo.
3. **Quien la escribe es el servicio de transición, no un *listener*.** Un *listener* es algo que alguien puede no registrar en un entorno, y el fallo sería silencioso.
4. **El cambio de `slug` invalida dos claves**, la vieja y la nueva (`api.md §2.5`).
5. **El alta y la clonación también invalidan** la clave de su `slug` nuevo. Parece innecesario —nadie ha resuelto ese `slug` todavía— y no lo es: el `slug` de un tenant eliminado se puede reutilizar (§5.4.2, §8), y la entrada del anterior puede seguir viva.
6. **La invalidación no puede quedarse en el nodo que escribe.** La caché es Redis y es compartida; un almacén de proceso —`array`, `file`— rompería la garantía sin dar ningún síntoma en desarrollo con un solo proceso. Queda escrito porque el modo de fallo es invisible.

**Lo que `1.6b` cambia en `ResolveTenant`, además de la invalidación**, está en §5.4.1: hoy `en_baja` y `eliminado` responden `404` en vez de `503`, y un tenant con `deleted_at` ni siquiera se encuentra. Los tres arreglos son del mismo sub-paso porque los tres los activa la misma funcionalidad.

> **Cuidado con el hermano de este problema**, que es el que `ADR-045 §8.3` señala, que es más sutil y que es de `1.6c`: la caché de disponibilidad de módulo (`modules:{code}:enabled`) lleva el **prefijo de tenant** `t{tenant_id}:` que fija `TenantContext::enter()`, y **el backoffice escribe desde fuera del contexto del tenant**. Una invalidación ingenua limpiaría la clave del prefijo equivocado, y una activación masiva la limpiaría equivocada tantas veces como centros. La invalidación debe entrar en el contexto del tenant afectado, o componer su prefijo explícitamente. Con test (`CA-BO-033`).

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
| `RN-BO-48` | **`RequirePlatformHost` es el primer *middleware* de la pila de plataforma.** Si el *host* de la petición no coincide con `BACKOFFICE_HOST`, **`404`** —no `403`— **antes de sesión y antes de credenciales**: no se revela que la superficie existe, mismo criterio que `ResolveTenant` con un *host* desconocido. La comparación es **contra `BACKOFFICE_HOST`**, nunca contra «lo que no resuelve tenant». **No sustituye** a la regla `Host()` de Traefik ni al revés: las dos capas son obligatorias (`ADR-046 §4.3`) |
| `RN-BO-49` | El *host* del backoffice vive en **su propia variable de entorno** (`BACKOFFICE_HOST`), **nunca derivado de `TENANCY_BASE_DOMAIN`**, y **no debe ser un subdominio suyo**: `TenantHost::slugFrom()` devuelve la etiqueta más a la izquierda de **cualquier** *host* que termine en `.{TENANCY_BASE_DOMAIN}`, de modo que un backoffice en `admin.{dominio_base}` resolvería el *slug* `admin` y lo único que impediría que resolviera un centro sería que ninguno se llame así. **Defensa en profundidad obligatoria**: el alta de tenant rechaza con `422` un *slug* que coincida con la etiqueta del *host* de plataforma (`ADR-046 §4.4`, `CA-BO-017`) |

> **`RN-BO-48` y `RN-BO-49` van numeradas al final y colocadas aquí, y las dos cosas son a propósito.** Los identificadores de regla **no se reordenan nunca** —`RN-BO-12` a `RN-BO-47` ya están citadas desde los otros cuatro ficheros de este módulo y desde `ADR-046`—, así que las reglas nuevas siguen a la última existente aunque su sitio temático sea §7.1. Renumerar para que el orden quedara bonito rompería referencias en cinco documentos a cambio de nada.

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

#### 7.2.1 Reglas que añade el sub-paso `1.6b`

> Van numeradas **a continuación de la última existente**, no intercaladas, por la misma norma que ya obligó a colocar `RN-BO-48` y `RN-BO-49` al final: **los identificadores de regla no se reordenan nunca**, porque están citados desde los otros cuatro ficheros del módulo, desde tres ADR y desde los criterios de aceptación. Su sitio temático es esta sección; su número es el que les toca.

| ID | Regla |
|----|-------|
| `RN-BO-50` | **La resolución de tenant responde según el estado, y `404` sólo cuando no hay tenant.** `en_alta`, `suspendido`, `en_baja` y `eliminado` ⇒ **`503`** con el mensaje que corresponda y `Retry-After`; `activo` ⇒ pasa; ningún tenant con ese `slug`, vivo ni borrado ⇒ **`404`** genérico. La búsqueda por `slug` **incluye los borrados lógicos** —si no, un tenant `eliminado` daría `404` por la vía equivocada— y **prefiere siempre al tenant vivo** cuando el `slug` está reutilizado; entre borrados, el de `deleted_at` más reciente (§5.4.1, §5.4.2). Completa `RN-BO-15`, que no llegaba a este nivel de detalle, y **corrige el comportamiento actual del código**, que devuelve `404` en tres de los cuatro estados sin acceso |
| `RN-BO-51` | **La invalidación de `tenant-resolution:{slug}` se ejecuta al confirmar la transacción, nunca dentro de ella**, la escribe el servicio de transición —no un *listener*— y vive **bajo el prefijo base de caché**, no bajo `t{tenant_id}:` (§6.3). Invalidan: las cinco transiciones, el alta, la clonación y el cambio de `slug` —éste, **las dos claves**, la vieja y la nueva— |
| `RN-BO-52` | **El alta y la clonación ocurren en dos fases**: una síncrona que crea la fila en `en_alta` y responde `201`, y una en cola que aprovisiona y transita a `activo` (`INV-012`). **El fallo de la segunda no crea ningún estado nuevo**: el tenant se queda en `en_alta`, se registra `tenant.aprovisionamiento_fallido` y se repara con un comando de consola. Nunca con una escritura manual de `status` |
| `RN-BO-53` | **`REQ-BO` no aprovisiona un tenant ni lo clona: se lo pide a `REQ-CORE`** por el contrato público **`TenantProvisioner`** de `Core\Domain` (`ADR-048`), jamás importando su código interno (`INV-007`). Alcanza **al alta y a la clonación**: `tenant_settings`, `roles` y `permission_role` son de `REQ-CORE` y las escribe `REQ-CORE` por los dos caminos (`provision()` y `provisionFromTemplate()`). **`module_subscriptions` es la única excepción y la sigue escribiendo el backoffice**, por `ADR-045 §4.1`. Ampliar esa interfaz para que acepte la configuración inicial del centro es trabajo de `1.6b` y se declara como ampliación de la superficie pública de `REQ-CORE`, enumerada en `ADR-048 §10`. **Es un contrato síncrono y no un evento**: el evento no devuelve resultado ni propaga fallo, y §5.3.3/§5.3.5 necesitan las dos cosas |
| `RN-BO-54` | **Las transiciones con `actor_type = 'system'` escriben en `reason` una clave del catálogo de traducción, nunca una frase.** `tenant_lifecycle_events.reason` es `NOT NULL` y esas transiciones no tienen operador que lo redacte; una frase escrita en el código sería un literal visible (`INV-009`). El motivo escrito por una persona sigue siendo texto libre |
| `RN-BO-55` | **Eliminar un tenant revoca todas las sesiones vivas de sus usuarios; suspenderlo y darlo de baja, no** (§5.4.3). La revocación se ejecuta en cola, dentro del contexto del tenant, con el vocabulario de `SessionEndReason` que ya posee `REQ-AUTH` — **que no se amplía**: son sesiones de usuarios de tenant |
| `RN-BO-56` | **Ningún camino de código de `1.6b` borra ni anonimiza un solo dato de un tenant.** La eliminación es el nivel 1 de `ADR-004` y nada más: `status`, `deleted_at` y revocación de acceso. La purga es `REQ-PRIV-006` (§5.5.3) |
| `RN-BO-57` | La confirmación por nombre de la eliminación se compara **literalmente**: sin recortar espacios interiores, sin plegar mayúsculas y sin normalizar acentos (§5.5.2) |
| `RN-BO-58` | **Volver a `activo` limpia lo que puso la transición que se deshace**: desde `suspendido`, `suspended_at` y `suspension_message`; desde `en_baja`, `grace_period_ends_at` y `grace_period_expired_at`. Un rastro que sobrevive a su causa acaba mostrándose fuera de contexto (§5.4, §5.5) |
| `RN-BO-59` | **La clonación copia exactamente el inventario de §5.6.2 y nada más.** Nunca identidad fiscal, ni marca, ni estado, ni `early_adopter_since`, ni historial. El origen **no puede estar `eliminado` ni `en_alta`** (`422`); sí puede estar `suspendido` o `en_baja`. El clon crea un primer administrador **nuevo** a partir del cuerpo de la petición: no se copia a nadie, se da de alta a alguien |
| `RN-BO-60` | **El vencimiento del período de gracia se marca una sola vez** en `tenants.grace_period_expired_at`, **no borra nada** y **no notifica a nadie** —no hay infraestructura de notificaciones hasta `REQ-COM` (1.19)—: deja entrada de auditoría, aparece en el filtro del inventario y produce una señal de operación (§5.5.1) |
| `RN-BO-61` | **Los 90 días no son configurables por variable de entorno.** El plazo lo fija `REQ-BO-001`; una variable que lo acorte es una forma de saltarse el período de gracia sin dejar rastro, del mismo género que las tres que `operacion.md §2` se niega a crear |
| `RN-BO-62` | **La baja no exige doble autorización** (§5.5.4). `REQ-BO-007` la exige para eliminar, purgar y desactivar módulos en masa, y la baja es reversible durante 90 días. El valor `tenant.baja` del vocabulario de `dual_authorizations` **se conserva sin escritor**. Sujeta a `OPEN-BO-14` |

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

#### 7.3.1 Reglas que añade el sub-paso `1.6c`

> Mismo criterio de numeración que §7.2.1: **van a continuación de la última existente y no intercaladas**, porque `RN-BO-22` a `RN-BO-28` ya están citadas desde los otros cuatro ficheros del módulo, desde `ADR-045` y desde los criterios de aceptación. Su sitio temático es esta sección; su número es el que les toca.

| ID | Regla |
|----|-------|
| `RN-BO-63` | **`depends_on` y `essential` se leen siempre del descriptor, y el catálogo se resuelve una sola vez por proceso**, desde los `ServiceProvider` ya registrados por el contenedor — **nunca por escaneo de ficheros ni por consulta a `modules` en el camino de petición**. `ALWAYS_ENABLED` desaparece de `EloquentModuleAvailability` y **no se sustituye por otra constante** (`CA-BO-036`). El motivo no es elegancia: `isEnabled()` corre en cada petición de cada usuario de cada centro, por `EnsureModuleEnabled` y por el filtro de inercia de `PermissionResolver` (§5.8.3) |
| `RN-BO-64` | `platform:sync-registry` aborta el despliegue ante **tres** cosas, no dos: código de `depends_on` inexistente, ciclo en el grafo, y **un módulo `essential` que declare dependencia de uno no esencial**. La tercera es derivada y no está escrita en `ADR-045`: un esencial es utilizable sin fila (`§4.9`) y por tanto **ninguna escritura puede protegerlo** (`§4.5`), así que el despliegue es el único sitio donde la incoherencia se puede impedir (§5.8.3) |
| `RN-BO-65` | **Un módulo esencial no tiene conmutador en ninguna de las dos direcciones**: contratarlo es `422` igual que descontratarlo, y **no aparece en el cierre de dependencias** —no necesita fila—. `ADR-045 §4.7` dice «celda bloqueada, sin conmutador», y una celda medio bloqueada no es una celda bloqueada |
| `RN-BO-66` | **El cierre de dependencias se recalcula dentro de la transacción, sobre una lectura bloqueada**, y el punto de serialización es la **fila de `tenants`** (`SELECT … FOR UPDATE`), no las de `module_subscriptions`: contratar crea filas que todavía no existen y no se puede bloquear lo que no está (§5.8.5). Mismo mecanismo y mismo motivo que `datos.md §3.3` y `§6.4`, aplicado **desde el principio y no como parche posterior** |
| `RN-BO-67` | **`cascade` no tiene valor por defecto verdadero.** Sin él, toda operación que arrastre otro módulo —hacia arriba o hacia abajo— responde `409` con la lista exacta de arrastrados. Es denegar por defecto aplicado al efecto colateral: quien no ha pedido tocar cinco módulos no toca cinco módulos |
| `RN-BO-68` | **La vista previa no reserva nada y no es un contrato.** La ejecución recalcula el cierre dentro de su transacción y aplica el recálculo; la respuesta dice **qué se hizo de verdad**. `RN-BO-20` congela los **parámetros** de una doble autorización —módulo, dirección, lista de centros, motivo—, **no el cierre derivado**, que se recalcula al ejecutar sobre el estado de ese instante |
| `RN-BO-69` | **Contratar lo ya contratado, o descontratar lo ya descontratado, es no-operación completa**: no reescribe `enabled_at`, no reescribe `disabled_at`, **tampoco `reason`**, no emite evento, no invalida caché y no audita. `200` con el recurso sin cambios. Completa `CA-BO-044`; que tampoco se reescriba `reason` es la parte nueva, y es lo que impide una modificación del motivo sin ningún rastro |
| `RN-BO-70` | **Un módulo `retired_at` no se contrata** —ni directamente ni por arrastre de dependencia—, `422`; **sí se descontrata**, porque es la única forma de cerrar la suscripción a algo que ya no existe en el código. El catálogo nunca borra (`SyncModuleRegistry`), y una suscripción viva a un módulo retirado se factura (`RMOD-007`) |
| `RN-BO-71` | **Qué estados de tenant admiten escritura de módulos**: `activo`, `suspendido` y `en_baja` **sí**; `en_alta` y `eliminado` **no** — `409` en la operación individual, **omitido y reportado** en la masiva. `en_alta` porque el conjunto de suscripciones lo está escribiendo todavía el aprovisionamiento o la clonación (`RN-BO-52`, §5.6.2); `eliminado` porque contratar a un centro cerrado es una escritura facturable sobre algo que no se opera. Que `suspendido` y `en_baja` sí admitan es lo que ya decía §8: suspensión y contratación son ejes distintos (`ADR-045 §2`) |
| `RN-BO-72` | **El motivo del operador se escribe literal en el `reason` de todas las filas que la operación toca, arrastradas incluidas.** Qué fila fue la principal y cuál la arrastrada vive en `admin_action_logs.context` (`cascaded_from`), **no en una frase compuesta dentro de `reason`**: componer texto en el código sería un literal visible (`INV-009`) y además haría el motivo inconsultable |
| `RN-BO-73` | **El backoffice no escribe `created_by` ni `updated_by` de `module_subscriptions`, y sí escribe `tenant_id` a mano.** Las dos primeras referencian a `users` **de ese centro** y un administrador de plataforma no lo es (`RN-BO-01`); quedan nulas, y el actor vive en `admin_action_logs.actor_platform_admin_id`. **Verificado sobre `TenantMigration::tenantTable()`: llevan clave foránea compuesta a `users`, luego un `platform_admins.id` ahí falla ruidosamente** en vez de apuntar en silencio a otra persona. `tenant_id` no puede confiarse a su `DEFAULT app.current_tenant_id()`, que es nulo sin tenant activo (§5.8.5, `datos.md §7.6`) |
| `RN-BO-74` | **Una escritura de módulos del backoffice no deja fila en `audit_logs` del centro, y es correcto.** Ocurre bajo `BackofficeEscritura`, donde `AuditRecorder::record()` retorna en silencio por decisión de `ADR-046 §6.5` (§6.2.4), y `RN-BO-30` prohíbe mezclar los dos registros. El centro la ve por `GET /api/v1/platform-actions`. Se escribe como regla para que nadie lea ese silencio como un defecto y lo «arregle» |
| `RN-BO-75` | **La invalidación de caché y la emisión de eventos ocurren fuera del bloque de plataforma y después del `COMMIT`**, en dos fases: la 1 valida, bloquea, escribe y audita dentro de `runAsPlatform(BackofficeEscritura, …)`; la 2 entra en el contexto de cada centro con `runFor()`, invalida `modules:{code}:enabled` y emite los eventos. **La partición en dos fases no es estilo: la fuerza `ADR-046 §6.4`**, que prohíbe tenant activo dentro de un bloque de plataforma (§5.8.2) |
| `RN-BO-76` | **El fallo de la fase 2 no hace fallar la petición.** La escritura ya está confirmada y auditada; un `500` mentiría sobre algo que ya ocurrió e invitaría a reintentarlo. Se registra, produce señal de operación, y el **TTL de 300 s de la propia caché es el peor caso acotado** — que es la razón de que ese TTL exista y de que no deba subirse. **Carrera residual declarada**: un lector que leyó antes del `COMMIT` puede cachear el valor anterior después del `forget`, acotado por ese mismo TTL. Es la misma que `RN-BO-51` acepta con 60 s, y **no se elimina aquí** (§5.8.6) |
| `RN-BO-77` | **Todo *listener* de `ModuleContracted` y `ModuleDecontracted` es encolado.** Uno síncrono que lanzara convertiría un fallo de terceros en el fallo de una escritura ya confirmada. En `1.6c` no hay ninguno; la regla se escribe **antes** de que `1.19` escriba el primero (`ADR-045 §4.8` punto 3) |
| `RN-BO-78` | **Una operación masiva es una transacción por centro, no una sobre N**, y los centros se procesan **en orden ascendente de `tenants.id`** para que dos lotes concurrentes no se interbloqueen. Una sola transacción mantendría N bloqueos de fila durante todo el lote —parando de paso cualquier transición de estado de esos centros— y haría que el fallo del centro 57 deshiciera los 56 que ya estaban bien. **El fallo sobre un centro no aborta el lote**: se registra y se continúa |
| `RN-BO-79` | **Un lote no mezcla direcciones**: `POST /module-rollouts` declara **un** `enabled` para todo él. La contratación masiva no exige doble autorización; la descontratación masiva **sí** (`modulo.descontratar_masivo`, valor que el `CHECK` de `dual_authorizations.action` ya admite desde `1.6`). Un lote mixto tendría que pasar entero por doble autorización —encareciendo una contratación de rutina— o partirse por dentro, y partirlo produciría un `202` que significa dos cosas a la vez |
| `RN-BO-80` | **Al aprobar una `modulo.descontratar_masivo` se encola el lote, y `executed_at` marca ese instante, no el final.** Una `dual_authorization` registra **la autorización**, no el resultado de N operaciones independientes; hacer que su estado dependiera de todas ellas le daría un estado que no significa nada. El resultado por centro vive en `admin_action_logs`. Es la única de las tres acciones del vocabulario cuya ejecución es **asíncrona**, y por eso se dice |
| `RN-BO-81` | **El conjunto de centros de una masiva es una lista explícita de `public_id`, nunca un filtro.** Un filtro cambia de contenido entre la vista previa, la solicitud y la aprobación, y `RN-BO-20` congela parámetros, no consultas. «Todos los centros activos» lo compone el cliente desde `GET /tenants`, y eso es justamente lo que hace el conjunto congelable y auditable |
| `RN-BO-82` | **El motivo interno del operador no lo lee el centro.** `module_subscriptions.reason` es texto libre del proveedor y `plataforma_app` lee hoy la tabla entera; **`1.6c` es el sub-paso que hace que esa columna contenga algo**, exactamente como `1.6b` fue el que hizo que `status` cambiara (§6.3). Se aplica el criterio único ya ratificado por el usuario para las otras dos tablas (`datos.md §4.3.1`, `§5.3.1`): **ningún texto libre escrito por un operador del proveedor cruza el `GRANT`**. Sujeta a `OPEN-BO-19` (§5.8.8, `datos.md §7.7`) |

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

### 7.6 Salud y métricas (`REQ-BO-004`, `REQ-BO-006`) · sub-paso `1.6d`

> Mismo criterio de numeración que §7.2.1 y §7.3.1: **van a continuación de la última existente**, `RN-BO-82`, y no intercaladas. Los identificadores de regla no se reordenan nunca.

| ID | Regla |
|----|-------|
| `RN-BO-83` | **Un campo cuya fuente no existe se omite de la respuesta**: nunca `0`, nunca `null`, nunca `"n/d"`. Un cero indistinguible de «no medido» es peor que la ausencia, y los enumerados de respuesta son extensibles (`ADR-038 §7.3`), así que añadir el campo cuando exista el dato es un cambio compatible. **Y su recíproco, que es la mitad que se olvida: un recuento que sí se ha medido y vale cero se devuelve**, porque ahí el cero significa «medido y son cero» (§5.9.2, §5.10.2) |
| `RN-BO-84` | **Ninguna respuesta de la ficha de salud devuelve el *payload* de un trabajo ni su traza completa.** Un *payload* serializado contiene datos personales del centro —el correo y el nombre de una invitación, y mañana un menor—, y `RN-BO-33` prohíbe que el backoffice los muestre. Se devuelven `uuid`, `queue`, `connection`, `failed_at`, la clase del trabajo (`payload.displayName`, un nombre de clase PHP) y la clase de la excepción. Sobre **el mensaje** de la excepción, `OPEN-BO-22` (§5.9.5) |
| `RN-BO-85` | **El reintento reencola el *payload* original, literal, sobre la conexión y la cola que declara la propia fila, y nunca lo recompone.** El `tenant_id` que `ADR-033 §8` estampó vive dentro de ese *payload* y es lo único que hace que el *worker* vuelva a entrar en el contexto correcto. Un trabajo recompuesto desde el backoffice —que por construcción no tiene tenant (`ADR-046 §6.4`)— correría con `tenant_id` nulo, sobre `plataforma_app`, **escribiendo sin filtro de RLS**. Es el modo de fallo más grave de este sub-paso y no da ningún síntoma inmediato (`CA-BO-152`) |
| `RN-BO-86` | **Todo el camino de lectura, reencolado y borrado de `failed_jobs` corre por `pgsql_platform`**, dentro de `runAsPlatform()`. `plataforma_app` tiene `REVOKE SELECT, UPDATE, DELETE` sobre esa tabla desde `0.7` y conserva sólo `INSERT`, que es lo que el *worker* necesita; el proveedor de trabajos fallidos del framework apunta a esa misma conexión, así que `queue:retry`, `queue:failed` y `queue:prune-failed` **no pueden funcionar** (§5.9.6). Alcanza también a `bo:retry-provisioning`, que hoy lo hace mal |
| `RN-BO-87` | **Qué estados de tenant admiten reintento**: `en_alta`, `activo`, `suspendido` y `en_baja` **sí**; `eliminado` **no**, `409` con `bo.job.tenant_state_invalid`. Mismo criterio que `RN-BO-71`: reejecutar trabajo dentro de un centro cerrado es escribir en algo que ya no se opera. `suspendido` y `en_baja` **sí**, porque la suspensión bloquea el **acceso** y no la maquinaria (`RN-BO-16`) y sus trabajos siguen corriendo (§8) |
| `RN-BO-88` | **No hay reintento masivo, ni «reintentar todo», ni bandera que lo active.** `REQ-BO-004` pide «reintento de jobs», uno a uno, con su motivo y su entrada de auditoría. Un `retry all` reejecutaría efectos secundarios —correos a familias incluidos— sobre todos los centros a la vez sin que nadie haya mirado ninguno: es una acción destructiva, y `REQ-BO-007` exige doble autorización a las destructivas. Si algún día hace falta, entra por §5.7 con su propio valor de vocabulario |
| `RN-BO-89` | **El reintento se ejecuta dentro de la petición, no en cola.** Es un `INSERT` en `jobs` y un `DELETE` en `failed_jobs`; `INV-012` habla de trabajo pesado, y encolar un reintento es encolar el encolado |
| `RN-BO-90` | **La atribución de un trabajo a un centro se lee del *payload*, y sólo alcanza a los trabajos despachados dentro del tenant.** Los cuatro que despacha el backoffice llevan `payload.tenant_id` **nulo** por construcción, así que **no aparecen** en los trabajos del centro; la ficha lo dice en bloques separados y nombrados, y la incidencia de aprovisionamiento se ve por `admin_action_logs`. **Contradice lo que §5.3.5 y `operacion.md §8` afirmaban**, y por eso está sujeta a `OPEN-BO-20` (§5.9.3) |
| `RN-BO-91` | **El recuento de tenants por estado incluye los borrados lógicos.** Un tenant `eliminado` lleva `deleted_at`, y excluirlo dejaría esa fila **siempre a cero**: es el mismo defecto por la vía equivocada que `RN-BO-50` corrigió en `ResolveTenant`, evitado esta vez antes de que ocurra |
| `RN-BO-92` | **Altas y bajas se cuentan sobre `tenant_lifecycle_events` y son series separadas.** Alta = `from_status IS NULL`; baja = `to_status = 'en_baja'`; eliminación = `to_status = 'eliminado'`, que **nunca** se suma a las bajas —entre una y otra hay 90 días de gracia y sumarlas contaría dos veces al mismo centro—. **El *churn* no se calcula** (`REQ-SAAS-004`, fase 2): no hay base de clientes facturables, y «bajas ÷ activos» llamado *churn* sería inventar una métrica de negocio desde un panel técnico |
| `RN-BO-93` | **La adopción por módulo se construye sobre el catálogo declarado** (`ModuleCatalog`), no sobre las filas existentes: un módulo sin una sola contratación aparece con cero, y ahí el cero es una medición. **Los módulos `essential` se devuelven marcados y sin recuento**, porque no tienen fila (`RN-BO-65`) y un `0` diría que ningún centro tiene `core`. **Los `retired_at` siguen apareciendo**, con su marca: mientras viva una suscripción suya se factura (`RMOD-007`, `RN-BO-70`). **El denominador viaja en la respuesta** |
| `RN-BO-94` | **Toda lectura agregada de este sub-paso ocurre dentro de `runAsPlatform(PlatformAccessPurpose::BackofficeLectura, …)`**, que no obliga a auditar (`ADR-046 §6.2`) — es el caso para el que ese propósito se separó del de escritura, y nadie tiene que inventarse una acción que registrar. **Fuera de ese bloque el agregado no falla: sale silenciosamente reducido al tenant activo**, porque `TenantScope` sí filtra. Una métrica equivocada en silencio es peor que un error, porque se usa para decidir (§5.10.3) |
| `RN-BO-95` | **Ninguna métrica se cachea ni se materializa en `1.6d`**, con disparador de revisión escrito (`operacion.md §7`). Dos `COUNT` agrupados sobre doscientos centros es trabajo despreciable, y una métrica cacheada es una métrica que alguien lee desactualizada **mientras decide**. Es la decisión contraria a la de la caché de *flags* y lo es porque el problema es el contrario: aquélla se lee en cada petición de cada centro, ésta unas veces al día |
| `RN-BO-96` | **`1.6d` es de sólo lectura salvo por un botón.** Su única escritura es el reintento, y su único efecto sobre datos de un centro es el que produzca el trabajo reintentado —que es el mismo que habría producido de no haber fallado—. Ninguna otra ruta del sub-paso escribe en ninguna tabla. Se dice como regla para delimitar qué tiene que revisar `security-reviewer` |
| `RN-BO-97` | **La versión desplegada y las migraciones aplicadas son de la plataforma, no del centro**, y la respuesta las devuelve marcadas como de alcance global. Bajo `ADR-001` no existe «la versión de este centro»; un campo que lo sugiriera invitaría a buscar diferencias entre centros que no pueden existir |
| `RN-BO-98` | **La purga de `failed_jobs` es de este módulo, corre por `pgsql_platform` y su plazo no es configurable por variable de entorno.** El plazo son **24 horas**, fijadas por el issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73) para no conservar tokens de un solo uso más de lo necesario, y vive en `config/backoffice.php` como constante del producto — **nunca** en el entorno: una variable que la alargue anula en silencio una mitigación de datos personales, exactamente el mismo argumento con el que `RN-BO-61` se niega a que los 90 días del período de gracia sean configurables. **El comando del framework (`queue:prune-failed`) deja de programarse**, porque apunta al rol que no tiene `DELETE` (`RN-BO-86`) y por tanto no purga nada. §5.9.7 |

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
| El *host* del backoffice recibe una cookie de sesión de tenant | No hay *endpoint* que la acepte: la cookie es *host-only*, el *guard* es otro, el *provider* apunta a otra tabla y el almacén de sesión es `platform_sessions`. `401`, y el intento se audita |
| Una ruta de `/api/platform/*` se alcanza desde el *host* de un centro | **`404`**, por `RequirePlatformHost`, antes de sesión y de credenciales (`RN-BO-48`, `CA-BO-016`). En un despliegue correcto la petición ni siquiera llega: Traefik enruta por `Host()` (§3.4, condición 2) |
| Se intenta dar de alta un tenant cuyo `slug` coincide con la etiqueta del *host* de plataforma | `422` (`RN-BO-49`, `CA-BO-017`). Lo mismo en el cambio de `slug` de `api.md §2.5` |
| Código de módulo llama a `runAsPlatform()` con un tenant activo | **Lanza**, con cualquier propósito (§6.2.4, `CA-BO-027`). Para operar sobre un tenant concreto se fija `tenant_id` a mano dentro del bloque, o se sale y se usa `runFor()` |
| Un bloque `BackofficeEscritura` termina sin escribir en `admin_action_logs` | **Lanza al cerrarse** (§6.2.4, `CA-BO-028`). No es un aviso: la escritura de plataforma sin rastro no se completa |
| Suspensión mientras hay jobs del tenant en cola | Los jobs siguen y terminan. Suspender bloquea el **acceso**, no la maquinaria (`RN-BO-16`) |
| **`1.6b`** · El trabajo de aprovisionamiento falla y el tenant se queda en `en_alta` | Queda en `failed_jobs`, visible en la ficha de salud; entrada `tenant.aprovisionamiento_fallido`; **ninguna** fila de `tenant_lifecycle_events`; se repara con `bo:retry-provisioning`, que reencola un trabajo idempotente (§5.3.5, `RN-BO-52`) |
| **`1.6b`** · Se pide una transición sobre un tenant que sigue en `en_alta` | `409`: la única salida de `en_alta` la produce el aprovisionamiento, no una persona (`RN-BO-12`). No hay forzado manual a `activo` por API |
| **`1.6b`** · Un *host* de un tenant `eliminado` recibe tráfico | `503`, no `404`, y para siempre mientras su DNS apunte aquí (`RN-BO-50`). Que deje de responder es una decisión de DNS, no de la aplicación |
| **`1.6b`** · Se da de alta un tenant con el `slug` de otro eliminado, y llega tráfico al *host* | Resuelve al **vivo**; el borrado sólo se mira si no hay ninguno vivo (`RN-BO-50`, §5.4.2). La entrada de caché del anterior se invalidó en el alta (`RN-BO-51`) |
| **`1.6b`** · Se suspende un tenant y un usuario suyo ya tenía la sesión abierta | No pasa de `ResolveTenant`: `503` en la siguiente petición. **Su sesión no se revoca** y al reactivar sigue sirviendo (`RN-BO-55`, primer criterio de §5.51) |
| **`1.6b`** · Se elimina un tenant y un usuario suyo tenía la sesión abierta | La revocación se encola y cierra todas sus sesiones con `baja_usuario`. El `503` ya lo bloqueaba; la revocación es para que no queden credenciales vivas apuntando a un centro cerrado (`RN-BO-55`) |
| **`1.6b`** · Vence el período de gracia y nadie hace nada | El centro sigue en `en_baja`, marcado y **rescatable**. **No se borra nada, nunca, por temporizador** (`RN-BO-17`, `RN-BO-60`) |
| **`1.6b`** · La tarea diaria se ejecuta varias veces sobre el mismo tenant vencido | Escribe **una** entrada y **una** marca: `grace_period_expired_at` no se reescribe (`RN-BO-60`) |
| **`1.6b`** · Se aprueba una eliminación cuyo tenant ha vuelto a `activo` entre la solicitud y la aprobación | La ejecución falla y la solicitud queda `fallida` con su motivo: los parámetros congelados ya no son válidos (`RN-BO-20`, §5.7 punto 3). **No se rescata reinterpretando la operación** |
| **`1.6b`** · Se clona un tenant y, a la vez, alguien cambia su configuración | El clon refleja el estado del origen en el instante de la lectura, que es único y transaccional (§5.6.4). Nunca mitad viejo, mitad nuevo |
| **`1.6b`** · Se clona un tenant cuya marca y datos fiscales el operador esperaba heredar | No se heredan, y es deliberado (§5.6.2). La respuesta de la operación **dice qué se ha copiado**, para que la ausencia no se lea como un fallo |
| **`1.6c`** · Se contrata un módulo esencial | `422`, igual que descontratarlo. La celda está bloqueada en las dos direcciones (`RN-BO-65`, `CA-BO-130`) |
| **`1.6c`** · Se descontrata un módulo que ya está `retired_at` | **Se admite**, `200`. Es la única forma de cerrar la suscripción a algo que ya no existe en el código, y mientras viva se factura (`RN-BO-70`, `RMOD-007`) |
| **`1.6c`** · Se contrata un módulo en un tenant `en_alta` | `409`: el conjunto de suscripciones lo está escribiendo todavía el aprovisionamiento o la clonación (`RN-BO-71`). En una masiva, ese centro se **omite y se reporta**, y el lote sigue |
| **`1.6c`** · Se contrata un módulo en un tenant `eliminado` | `409`. Contratar a un centro cerrado es una escritura facturable sobre algo que no se opera (`RN-BO-71`) |
| **`1.6c`** · Dos peticiones a la vez: una contrata `M`, que depende de `N`; la otra descontrata `N` | Se serializan por el bloqueo de la fila de `tenants`, y la segunda recalcula el cierre sobre la lectura bloqueada. **Nunca queda `M` sin `N`** (`RN-BO-66`, `CA-BO-132`) |
| **`1.6c`** · Se vuelve a contratar un módulo ya contratado **con otro motivo** | No-operación completa: `reason` **no se reescribe**, no hay evento, no hay auditoría y no se invalida nada (`RN-BO-69`, `CA-BO-134`) |
| **`1.6c`** · Falla la invalidación de caché tras confirmar la escritura | La petición **responde con éxito**. La fila está escrita y auditada; el peor caso observable es que el centro siga viendo `403` durante el TTL de 300 s (`RN-BO-76`, `CA-BO-139`) |
| **`1.6c`** · Un lote de 200 centros falla en el número 57 | Los 56 anteriores quedan aplicados, el 57 se registra como fallido **sin entrada propia** —no le pasó nada— y el lote continúa con los 143 restantes (`RN-BO-78`, `CA-BO-141`) |
| **`1.6c`** · Se aprueba una descontratación masiva y un centro del lote ha sido eliminado entretanto | Ese centro se **omite y se reporta**; el resto se aplica. **El conjunto ejecutado es el congelado**, no el resultado de volver a consultar nada (`RN-BO-20`, `RN-BO-81`, `CA-BO-144`) |
| **`1.6c`** · Una versión nueva declara que `M` depende de `N`, y hay centros con `M` y sin `N` | `platform:sync-registry` **informa y no corrige** (`RN-BO-28`). La incoherencia se ve en `GET /tenants/{id}/modules` y en la ficha de salud, y la resuelve una persona desde la matriz |
| **`1.6c`** · Un módulo esencial declara `depends_on` de uno no esencial | **El despliegue aborta** (`RN-BO-64`, `CA-BO-129`). No hay escritura que pueda proteger a un esencial, porque no tiene fila que bloquear |
| **`1.6d`** · Un centro no tiene ningún trabajo fallido | La ficha devuelve `0`, **no omite el campo**: es un recuento medido, y ahí el cero significa «medido» (`RN-BO-83`). Lo que sí se omite es el uso de recursos, el certificado y los conectores, que no tienen fuente |
| **`1.6d`** · Un aprovisionamiento falla y el operador busca el trabajo en la ficha del centro | **No lo encuentra en el bloque de trabajos**, porque se despachó sin tenant (`RN-BO-90`). Lo encuentra en «última incidencia de plataforma», que lee `admin_action_logs` por `affected_tenant_id` (§5.9.3). **Es una limitación declarada, sujeta a `OPEN-BO-20`**, no un defecto de la implementación |
| **`1.6d`** · Un lote masivo falla sobre un centro concreto y el operador lo busca en su ficha | **No aparece**: `RN-BO-78` decide que los centros fallidos de un lote **no tienen entrada propia** porque «no les pasó nada». Se ve en `modulo.masivo_ejecutado`, que es de alcance global. **`1.6d` no cambia `RN-BO-78`** para taparlo |
| **`1.6d`** · Se reintenta el mismo `uuid` dos veces | La primera lo reencola y borra la fila; la segunda recibe **`404`**. No hace falta `Idempotency-Key`: el recurso desaparece al usarlo (`api.md §6`) |
| **`1.6d`** · Se reintenta un `uuid` que pertenece a otro centro | **`404`**, no `403`. Un `403` confirmaría que ese trabajo existe en algún sitio, y el `uuid` es adivinable por fuerza bruta mucho antes que un centro (`CA-BO-153`) |
| **`1.6d`** · Se reintenta un trabajo de un tenant `eliminado` | `409` con `bo.job.tenant_state_invalid` (`RN-BO-87`). El trabajo sigue en `failed_jobs` y visible; lo que no se hace es reejecutarlo dentro de un centro que ya no se opera |
| **`1.6d`** · Se reintenta un trabajo de un tenant `suspendido` | **Se reintenta** (`RN-BO-87`). Suspender bloquea el acceso, no la maquinaria (`RN-BO-16`), y sus trabajos siguen corriendo — es la misma decisión de §8 unas filas más arriba |
| **`1.6d`** · El trabajo reintentado vuelve a fallar | Vuelve a `failed_jobs` con un `uuid` **nuevo** y vuelve a aparecer en la ficha. El reintento anterior queda en `admin_action_logs` con su actor y su motivo: **se puede saber cuántas veces se ha reintentado y quién**, que es justo lo que hace falta para dejar de reintentar |
| **`1.6d`** · El operador busca en la ficha un fallo de hace tres días | **No está**: `bo:purge-failed-jobs` lo borró (§5.9.7). La retención de 24 horas la fijó el issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73) a propósito y `RN-BO-98` la blinda contra una variable de entorno. **Hoy sí estaría**, porque la purga no borra; que a partir de `1.6d` deje de estar es el arreglo funcionando, no una pérdida |
| **`1.6d`** · Un tenant lleva más de 24 horas atascado en `en_alta` y ya no hay trabajo que reintentar | `bo:retry-provisioning` no encuentra nada: la purga se llevó la fila. **Es consecuencia de la retención de #73, no de este sub-paso**, y la salida es dar de alta el centro otra vez o completar su aprovisionamiento a mano — nunca escribir `status` (`RN-BO-52`). Queda escrito porque es la única regresión visible que `1.6d` introduce al arreglar la purga (§5.9.7) |
| **`1.6d`** · No hay ningún *worker* desplegado (issue [#128](https://github.com/pirexia/plataforma-educativa/issues/128)) | La ficha enseña `jobs` creciendo y `failed_jobs` vacío. **Es información correcta, no un defecto de la ficha**: es exactamente el síntoma que se espera, y verlo es el motivo de que esta ficha exista |
| **`1.6d`** · Un módulo se retira del código y aún hay centros suscritos | Aparece en la adopción, **marcado como retirado y con su recuento real** (`RN-BO-93`). Desaparecerlo escondería suscripciones vivas que se siguen facturando (`RMOD-007`) |
| **`1.6d`** · Se consulta la adopción de un módulo esencial | Aparece **marcado y sin recuento de contratación** (`RN-BO-93`). Ni `0` —falso— ni el total —inventado con aspecto de medido— |
| **`1.6d`** · Se piden altas y bajas con `occurred_at_from` posterior a `occurred_at_to` | `422`. Parámetro conocido con valor inválido, según `ADR-038 §5.2` |
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

**`INV-007` en la frontera con `REQ-CORE`**: **contratar y descontratar no los escribe `REQ-BO` directamente.** Llama al contrato público que posee `REQ-CORE` —el mismo que resuelve dependencias, invalida caché y emite los eventos (§5.8.2)— exactamente como `REQ-CORE` expuso `BulkUserImporter` para que 1.24 no lo reimplemente. Sin eso, la regla de dependencias tendría dos implementaciones y el evento se emitiría desde el sitio equivocado.

> **Precisión añadida en `1.6c`, que corrige una frase más ancha que la decisión que describía.** Hasta esta pasada, este párrafo decía «`REQ-BO` no escribe `module_subscriptions` directamente», sin más, y eso **contradecía en apariencia** a §5.6.2 —«`module_subscriptions` es la excepción y la copia `REQ-BO`»— y al código ya mezclado de `1.6b`, donde `CloneTenant` usa `ModuleSubscription::query()->updateOrCreate()`. **No hay ninguna decisión en conflicto y no cambia nada**: lo que `ADR-045 §4.5`/`§4.8` manda al servicio de `REQ-CORE` es el **camino de contratación y descontratación**, que es el que tiene que resolver dependencias, invalidar caché y emitir eventos. El **sembrado inicial de un clon** es otra cosa: copia un conjunto que ya es coherente —si el origen cumple `RN-BO-22`, la copia lo cumple—, no emite eventos porque el clon nace en `en_alta` y todavía no tiene un solo usuario al que avisar, y `ADR-045 §4.1` ya decidió que el backoffice es el único escritor de esa tabla. `CloneTenant` **no se toca**.

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

`1.6` tal como está en el plan tiene, sólo en lo que **sí** entra: **trece tablas nuevas** —nueve del chasis, del ciclo de vida y de la auditoría; **dos de la sesión de plataforma** que trae `ADR-046 §5`; y dos del motor de *flags* que trae la decisión del 2026-09-08—, un segundo sujeto de autenticación con su propio MFA, una segunda superficie HTTP con su propia SPA, un mecanismo genérico de doble autorización, la migración de privilegios de `ADR-045 §4.4`, los tres derivados de `ADR-045 §11`, tres issues que cerrar y —por `ADR-046`— un cambio de firma en infraestructura compartida y dos bloques de trabajo en `infra/quadlet` (§12.2.1). Es varias veces el tamaño de `1.5`, que ya se dividió.

Y hay un argumento mejor que el tamaño: **el orden está forzado**. `REQ-BO-002` no se puede implementar antes que `REQ-BO-007`, porque escribir `enabled` desde el backoffice exige que exista alguien autenticado como Super Administrador. Cuando la secuencia ya es obligatoria, dividir no añade riesgo, sólo puntos de corte limpios.

### 12.2 La división, con los cinco sub-pasos

| Paso | Alcance | Por qué corta ahí |
|------|---------|-------------------|
| **1.6** · *Identidad, autorización y auditoría de plataforma* | `REQ-BO-007` completo: `platform_admins` y sus roles, MFA propio sin excepción, lista blanca de IP, sesión corta y reautenticación sobre **`platform_sessions`**, `admin_action_logs`, doble autorización genérica. Cierra los puntos 2-3 del issue #6. **Y, por `ADR-046`, el trabajo de separación de superficie que se detalla justo debajo (§12.2.1): las reglas `Host()` e `ipallowlist` de `infra/quadlet`, y la firma de `runAsPlatform()` en `App\Support\Tenancy`** | Es el chasis. Sin él, ninguno de los otros seis sub-requisitos tiene sujeto que autorizar ni sitio donde auditarse. Termina con algo verificable de punta a punta: un administrador de plataforma que entra, con MFA, desde una IP permitida y **bajo el *host* de plataforma**, y cuyo acceso queda registrado |
| **1.6b** · *Ciclo de vida de tenants* | `REQ-BO-001` completo, sobre el chasis de 1.6. Cierra los issues #7 y #27 | Es la primera operación destructiva real y la primera consumidora de la doble autorización. Separarla permite que el chasis se revise en seguridad **antes** de que exista algo peligroso que hacer con él |
| **1.6c** · *Matriz de módulos* (`ADR-045`) | `REQ-BO-002` completo, la migración de privilegios de `ADR-045 §4.4` y los **tres derivados** de `ADR-045 §11` | Es el paso que `ADR-045` describe punto por punto, y el único cuyo diseño ya está cerrado antes de empezar. Toca `REQ-CORE` (servicio de contratación, eventos, descriptor) más que a `REQ-BO` |
| **1.6d** · *Salud y métricas* | `REQ-BO-004` reducido y `REQ-BO-006` reducido (§5.9, §5.10) | Es el único bloque **enteramente de lectura**. Puede posponerse sin bloquear nada, y su valor crece a medida que existan más módulos que observar |
| **1.6e** · *Motor de* feature flags | `REQ-BO-005` puntos 1-2 completos (§5.11): catálogo declarado en código, `feature_flags` y `feature_flag_rules`, `tenants.early_adopter_since`, evaluador en `REQ-CORE`, caché versionada e interruptor de emergencia | §12.3 |

### 12.2.1 `1.6` incluye trabajo fuera de `apps/api/app/Modules/Backoffice`, y hay que decirlo aquí

**Añadido tras `ADR-046`. Los cortes de la tabla anterior no cambian; lo que cambia es que el primer sub-paso recoge explícitamente dos bloques de trabajo que no son código de módulo** y que sin ellos el chasis no se puede dar por terminado. `ADR-046 §4.1`, `§4.6` y `§8` los meten dentro de `1.6` a propósito, aunque `infra/` y `App\Support` no sean ámbito habitual de un paso de módulo: *«fuera del ámbito habitual de un paso de módulo, pero inseparable de esta decisión»*.

| Bloque | Qué entra en `1.6` | Por qué no puede esperar |
|---|---|---|
| **`infra/quadlet` y Traefik** | `Host()` en las reglas de `web.container` y `api@.container`, el *router* nuevo de `/api/platform` bajo el *host* del backoffice, y el *middleware* `ipallowlist` sobre él (`operacion.md §0.2`, filas 1 a 3). **El *router* y el contenedor de estáticos de la SPA no**: son del paso de interfaz, posterior a `1.7`/`1.9` (§12.5, `OPEN-BO-08`) — `ADR-046 §4.1` decide **dónde vive** esa SPA, no cuándo se construye | **Hoy Traefik enruta sólo por `PathPrefix`.** Sin `Host()`, `centroa.dominio/api/platform/…` alcanza el mismo contenedor, la separación de superficie no existe y `1.6` no cumple el requisito de `REQ-BO`. Y `plataforma-web`, con su `PathPrefix(/)` de prioridad 1, serviría la SPA de los centros bajo el *host* del backoffice |
| **`App\Support\Tenancy`** | Firma nueva de `runAsPlatform()`, `PlatformAccessPurpose`, `platformPurpose()`, `PlatformAccessCheck` con su enlace por defecto que **deniega** los propósitos de backoffice, la regla de ausencia de tenant activo, la regla de cierre y la regla nueva de `AuditRecorder` (§6.2) | Es **infraestructura compartida por todo el producto**, no código de `REQ-BO`: la firma cambia para los dos llamadores que existen y para todos los futuros, y arrastra cuatro ficheros de test. El backoffice no puede escribir nada de plataforma hasta que exista la puerta por la que hacerlo |

**Y una consecuencia sobre el orden interno de `1.6`**: el enlace por defecto de `PlatformAccessCheck` deniega los dos propósitos de backoffice mientras `App\Modules\Backoffice` no lo sustituya (§6.2.3). Eso significa que el trabajo de `App\Support\Tenancy` puede —y conviene que— ir **antes** que el resto del chasis: hasta que llegue, el producto queda estrictamente más cerrado que hoy, no más abierto.

**Nada de esto altera `1.6b`, `1.6c`, `1.6d` ni `1.6e`**, cuyo alcance y orden siguen exactamente como los describe §12.2.

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

**`ADR-046` no cambia esto, y conviene decir por qué no**, porque su `§4.1` exige «SPA propia en `apps/backoffice`, proyecto Vite independiente que no comparte *bundle* con `apps/web`». Eso decide **dónde vive** esa SPA y **qué no puede compartir** —es lo que descarta la Opción C—, no **cuándo** se construye. El cuándo lo decidió el usuario el 2026-09-08 (`OPEN-BO-08`) y sigue siendo: después de `1.7` y `1.9`. La consecuencia operativa está en `operacion.md §0.2`, fila 4: el *router* de Traefik y el contenedor de estáticos de esa SPA son del paso de interfaz, no de `1.6`.

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
- **`CA-BO-011`** · *(aserción 1 de `ADR-046 §4.5`)* *Dado* el mapa de rutas de la aplicación, *cuando* un test de arquitectura recorre **`Route::getRoutes()`** —no el texto de los ficheros—, *entonces* **ninguna** ruta bajo `/api/platform/*` lleva `resolve-tenant`, `verify-session-tenant` ni `require-mfa-enrollment` (§3.4, condición 5).
- **`CA-BO-012`** · *Dado* un `platform_admin` que perdió su segundo factor, *cuando* pide restablecerlo, *entonces* sólo puede hacerlo otro `superadministrador`, queda auditado, y **no existe ninguna vía de autoservicio ni por correo**.
- **`CA-BO-013`** · *(aserción 2 de `ADR-046 §4.5`)* *Dado* ese mismo recorrido de `Route::getRoutes()`, *entonces* **toda** ruta bajo `/api/platform/*` lleva la pila de plataforma **completa y en este orden**: `RequirePlatformHost`, `EnforcePlatformIpAllowlist`, cookies, sesión de plataforma, CSRF, caducidad, idioma, MFA de plataforma, capacidad (`api.md §1.1`). **Se comprueba por presencia, no por ausencia**: denegar por defecto es `INV-002`, y una pila incompleta en una sola ruta es la forma de fallo que este test existe para impedir. **`OPEN-BO-13` resuelta (lectura (a), `api.md §1.1.1`)**: los puestos 8 y 9 están presentes en **todas** las rutas sin excepción, y cada uno resuelve su propia excepción por identidad del sujeto o de la ruta —no hay lista blanca que mantener ni comprobar.
- **`CA-BO-014`** · *(aserción 3 de `ADR-046 §4.5`)* *Dado* ese mismo recorrido, *entonces* **ninguna** ruta bajo `/api/v1/*` ni del grupo `web` usa el *guard* `platform`.
- **`CA-BO-015`** · *(aserción 4 de `ADR-046 §4.5`)* *Dado* ese mismo recorrido, *entonces* **ninguna** ruta fuera de `/api/platform/*` apunta a un controlador de `App\Modules\Backoffice`.
- **`CA-BO-016`** · *Dado* una petición a cualquier ruta de `/api/platform/*` cuyo `Host` **no** coincide con `BACKOFFICE_HOST`, *entonces* **`404`** —no `403`, no `401`— y **antes** de tocar la sesión y las credenciales; y la respuesta es indistinguible de la de una ruta inexistente (`RN-BO-48`, `ADR-046 §4.3`).
- **`CA-BO-017`** · *Dado* un alta de tenant —o un cambio de `slug`— cuyo `slug` coincide con la etiqueta del *host* de plataforma, *entonces* **`422`** y no se crea ni se modifica nada (`RN-BO-49`, `ADR-046 §4.4`).
- **`CA-BO-018`** · *Dado* la conexión de la aplicación de los centros (`plataforma_app`), *cuando* intenta un `SELECT` sobre **`platform_sessions`**, *entonces* **el motor lo rechaza por falta de privilegio**, no la aplicación — verificado por privilegios de motor, con el mismo patrón con el que `CA-BO-030` comprueba `module_subscriptions` (`ADR-046 §5.2`, `datos.md §2.6`).

### 13.2 Auditoría de plataforma (`REQ-BO-007`, issues #6 y #27)

- **`CA-BO-020`** · *Dado* cualquier operación de escritura del backoffice, *cuando* termina con éxito, *entonces* existe una entrada en `admin_action_logs` con actor, acción, sujeto, motivo, `affected_tenant_id` cuando proceda, IP, `user_agent` y `request_id` (`INV-003`, `INV-013`).
- **`CA-BO-021`** · *Dado* una entrada de `admin_action_logs`, *cuando* se intenta actualizarla o borrarla por cualquier rol de base de datos, *entonces* la operación es rechazada **por el motor** (`RN-BO-29`).
- **`CA-BO-022`** · *Dado* un centro, *cuando* consulta desde su propia aplicación las acciones de plataforma que le afectan, *entonces* recibe **sólo** las entradas con su `affected_tenant_id` y ninguna otra, ni siquiera las de alcance global (`RN-BO-31`, `INV-001`).
- **`CA-BO-023`** · *Dado* la suspensión de un tenant, *cuando* se consulta `audit_logs` de ese tenant, *entonces* **no** aparece: la auditoría de plataforma no se mezcla con la del centro (`RN-BO-30`, `ADR-036`).
- **`CA-BO-024`** · *Dado* el ciclo de vida de un `Tenant` (alta, suspensión, reactivación, cambio de `slug`, baja, eliminación), *cuando* se ejecuta cualquiera de esas operaciones, *entonces* queda registrada — **cerrando el issue [#27](https://github.com/pirexia/plataforma-educativa/issues/27)**.
- **`CA-BO-025`** · *Dado* `runAsPlatform()` con un propósito **no alcanzable desde donde se invoca** —`Mantenimiento` desde una petición HTTP, o cualquiera de los dos de backoffice sin administrador de plataforma autenticado en el *guard* `platform`—, *entonces* `before()` lanza excepción y **no se ejecuta el *callback*** (§6.2.2, §6.2.3, `ADR-046 §6`). Y *dado* el enlace **por defecto** de `PlatformAccessCheck`, *cuando* `App\Modules\Backoffice` no está registrado, *entonces* los dos propósitos de backoffice están **denegados**, no permitidos — puntos 2-3 del issue [#6](https://github.com/pirexia/plataforma-educativa/issues/6).
- **`CA-BO-026`** · *Dado* el mantenimiento por consola que usa `runAsPlatform()` (`RunsPerTenant`, `PurgeExpiredIdempotencyKeys`), *cuando* se ejecuta sobre N tenants, *entonces* **no** escribe N entradas en `admin_action_logs`: se audita la operación, no la primitiva (§6.2).
- **`CA-BO-027`** · *Dado* un tenant activo en el contexto, *cuando* se invoca `runAsPlatform()` con **cualquiera** de los tres propósitos, *entonces* **lanza** y no ejecuta el *callback* (§6.2.4, `ADR-046 §6.4`). Y *dado* `AuditObserverTest`, *entonces* su caso «modo plataforma con tenant activo» deja de documentar algo que «no ocurre hoy» y pasa a comprobar que **lanza siempre**.
- **`CA-BO-028`** · *Dado* un bloque `runAsPlatform(PlatformAccessPurpose::BackofficeEscritura, …)` que termina **sin** haber dejado ninguna entrada en `admin_action_logs`, *entonces* `after()` **lanza al cerrar el bloque**, también si el *callback* ya había lanzado (§6.2.4, `ADR-046 §6.5`). Y *dado* `AuditRecorder::record()` bajo modo plataforma, *entonces* **lanza** con propósito `Mantenimiento` y **retorna en silencio** con los dos de backoffice, según la tabla de §6.2.4.
- **`CA-BO-029`** · *Dado* el código completo de `app/`, *cuando* el test de arquitectura de `RunAsPlatformArchitectureTest` recorre las invocaciones de `runAsPlatform()`, *entonces* **ninguna** pasa un propósito calculado en tiempo de ejecución —el argumento es siempre un caso literal del *enum*—, y **la lista blanca sigue siendo fichero a fichero**: un comodín de directorio del tipo `app/Modules/Backoffice/**` hace fallar el test (§6.2.5, `ADR-046 §6.7`).

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

#### 13.3.1 Los dieciséis anteriores **no están satisfechos hoy**, y los que añade el sub-paso `1.6c`

> **Verificado el 2026-09-15 sobre `develop` en `91adac6` (§5.8.1), y conviene decirlo porque lo razonable era suponer lo contrario**: parecía que los de catálogo y registro —`CA-BO-030` a `CA-BO-036`— estarían cubiertos por el trabajo de `0.8` y `1.1`. **No lo están.** No existe la migración de privilegios, no existe `Cache::forget`, `depends_on` no aparece en una sola línea de `apps/api` y `ALWAYS_ENABLED` sigue en `EloquentModuleAvailability:28`. Lo que sí existe es el **precedente** de cada uno: la validación de `applicable_scopes` que ya aborta el despliegue, las dos migraciones `harden_*_grants`, y el patrón `lockForUpdate()` de `1.6b`. **Los dieciséis son objetivo de este sub-paso, no herencia del anterior.**
>
> Y dos tests existentes cambian de conexión con el `REVOKE INSERT`: `ModuleSubscriptionsSchemaTest` —ya avisado por `ADR-045 §4.4` y `datos.md §7.3`— y **`SyncModuleRegistryTest`, que no estaba nombrado en ninguna parte** y que también inserta en `module_subscriptions` por `pgsql` (§5.8.1).

**Catálogo, descriptor y despliegue**

- **`CA-BO-128`** · *Dado* el camino de petición completo de un usuario de un centro, *cuando* se cuentan las consultas y los accesos a disco de `ModuleAvailability::isEnabled()`, *entonces* **no hay ninguna consulta a `modules` ni ningún escaneo de ficheros**: el catálogo de descriptores se resolvió una sola vez por proceso (`RN-BO-63`).
- **`CA-BO-129`** · *Dado* un módulo declarado `essential: true` que declara en `depends_on` un módulo **no** esencial, *cuando* se ejecuta `platform:sync-registry`, *entonces* **aborta con error y no escribe nada** — tercer caso de `RN-BO-64`, que `CA-BO-034` y `CA-BO-035` no cubren.

**Contratar y descontratar**

- **`CA-BO-130`** · *Dado* un módulo esencial, *cuando* se intenta **contratarlo** —no descontratarlo—, *entonces* `422` con `bo.module.essential`: la celda está bloqueada en las dos direcciones (`RN-BO-65`). Es la otra mitad de `CA-BO-041`.
- **`CA-BO-131`** · *Dado* un módulo `M` que declara `depends_on: ['core', 'N']`, *cuando* se contrata en un centro sin `N`, *entonces* se crean **dos** filas —`M` y `N`— y **ninguna para `core`**: un esencial no entra en el cierre porque no necesita fila (`RN-BO-65`).
- **`CA-BO-132`** · *(concurrencia)* *Dado* dos peticiones simultáneas sobre el mismo centro desde **dos conexiones distintas** —una contrata `M`, que depende de `N`; la otra descontrata `N`—, *cuando* ambas terminan, *entonces* el grafo resultante es coherente: o `M` y `N` contratados, o ninguno de los dos, **nunca `M` sin `N`** (`RN-BO-22`, `RN-BO-66`). Es el equivalente para módulos de lo que los issues #205, #207 y #209 fueron para `1.6b`, y se escribe **antes** de que ocurra.
- **`CA-BO-133`** · *Dado* una operación que arrastra otros módulos, *cuando* se envía **sin `cascade`**, *entonces* `409` con la lista exacta y **no se escribe nada**; *cuando* se envía con `cascade: true`, *entonces* se aplica exactamente lo que la vista previa anunció (`RN-BO-67`).
- **`CA-BO-134`** · *Dado* un módulo ya contratado, *cuando* se vuelve a contratar **con un motivo distinto**, *entonces* `reason` **no se reescribe**, no hay entrada en `admin_action_logs`, no hay evento y no se invalida ninguna caché (`RN-BO-69`). Es la parte de `CA-BO-044` que faltaba: sin ella, el motivo se podría cambiar sin dejar rastro.
- **`CA-BO-135`** · *Dado* un módulo con `retired_at`, *cuando* se intenta contratar —directamente o como dependencia arrastrada de otro—, *entonces* `422` con `bo.module.retired`; *cuando* se **descontrata**, *entonces* `200` y la suscripción queda cerrada (`RN-BO-70`).
- **`CA-BO-136`** · *Dado* un tenant en cada uno de los cinco estados, *cuando* se intenta contratar un módulo, *entonces* `activo`, `suspendido` y `en_baja` responden `200`, y `en_alta` y `eliminado` responden `409` con `bo.module.tenant_state_invalid` (`RN-BO-71`).
- **`CA-BO-137`** · *Dado* una contratación ejecutada desde el backoffice, *cuando* se lee la fila de `module_subscriptions`, *entonces* `created_by` y `updated_by` son **nulos** y ninguno contiene el identificador del administrador de plataforma (`RN-BO-73`).
- **`CA-BO-138`** · *Dado* esa misma contratación, *entonces* **no** existe ninguna fila nueva en `audit_logs` de ese centro, y **sí** existe una en `admin_action_logs` con `action = 'modulo.contratado'` y su `affected_tenant_id` (`RN-BO-74`, `RN-BO-30`).

**Invalidación, eventos y fallo de la fase 2**

- **`CA-BO-139`** · *(camino de fallo)* *Dado* una contratación cuya **fase 2 falla** —caché no disponible—, *entonces* la petición responde con éxito, la fila está escrita y auditada, se produce la señal de operación, y **el peor caso observable es que el centro siga viendo `403` durante el TTL de 300 s**, nunca más (`RN-BO-76`, §5.8.6).
- **`CA-BO-140`** · *Dado* un *listener* registrado sobre `ModuleContracted`, *cuando* se recorre el código, *entonces* es **encolado**; y *cuando* ese *listener* lanza, *entonces* el fallo acaba en `failed_jobs` y **no** en la respuesta de la operación (`RN-BO-77`).

**Activación masiva**

- **`CA-BO-141`** · *Dado* un lote sobre tres centros de los cuales **el segundo falla**, *cuando* termina, *entonces* el primero y el tercero están aplicados, el lote **no se abortó**, existe una entrada por cada centro aplicado con su `affected_tenant_id`, **ninguna** para el que falló, y **una** entrada `modulo.masivo_ejecutado` con `affected_tenant_id` **nulo** y los recuentos en `context` (`RN-BO-78`, §5.8.7).
- **`CA-BO-142`** · *Dado* un lote sobre N centros, *cuando* se inspecciona su ejecución, *entonces* son **N transacciones**, una por centro, en orden ascendente de `tenants.id`, y **no** una sola transacción (`RN-BO-78`).
- **`CA-BO-143`** · *Dado* una **descontratación** masiva, *entonces* responde `202` con la `dual_authorization` en `pendiente` y **ninguna fila de `module_subscriptions` ha cambiado**; *cuando* un segundo administrador la aprueba, *entonces* el lote queda **encolado**, `executed_at` marca ese instante y la solicitud pasa a `ejecutada` **aunque el lote no haya terminado** (`RN-BO-79`, `RN-BO-80`, `CA-BO-066`).
- **`CA-BO-144`** · *Dado* una descontratación masiva aprobada **entre cuya solicitud y cuya aprobación uno de los centros pasó a `eliminado`**, *cuando* se ejecuta, *entonces* ese centro se **omite y se reporta**, el resto se aplica, y **el conjunto ejecutado es el congelado**, no el resultado de volver a evaluar ningún filtro (`RN-BO-20`, `RN-BO-81`).
- **`CA-BO-145`** · *Dado* el cuerpo de `POST /module-rollouts`, *cuando* se busca un selector por filtro —«todos los activos», «los del plan X»—, *entonces* **no existe ninguno**: sólo se admite una lista explícita de `public_id`, no vacía y sin duplicados (`RN-BO-81`).

**Lecturas y motivo**

- **`CA-BO-146`** · *Dado* un centro con un módulo contratado, otro no contratado, uno esencial y uno con una **dependencia incoherente** —contratado y con su dependencia sin contratar, tras una versión que añadió la arista—, *cuando* se consulta `GET /tenants/{public_id}/modules`, *entonces* aparecen los **tres** estados de `ADR-045 §4.7` y la incoherencia se muestra con los códigos que faltan (`RN-BO-28`, `ADR-045 §4.5`).
- **`CA-BO-147`** · *Dado* la conexión de la aplicación de los centros (`plataforma_app`) dentro del contexto de un tenant, *cuando* intenta leer `module_subscriptions.reason`, *entonces* **el motor lo rechaza por falta de privilegio de columna**; y *cuando* consulta las columnas concedidas —incluidas `enabled` y `enabled_at`, que el panel del centro necesita (`ADR-045 §4.8` punto 2)—, *entonces* la consulta **funciona** (`RN-BO-82`). **Este criterio depende de `OPEN-BO-19`**: si el usuario decide no cerrar el `GRANT` por columnas, se retira y su garantía pasa a ser la proyección del *resource* de `REQ-CORE`, que es más débil y hay que decirlo.

**Aislamiento entre centros — obligatorio para cerrar el sub-paso**

- **`CA-BO-148`** · *Dado* **tres** tenants, *cuando* se ejecuta un lote sobre el primero y el tercero, *entonces* el segundo no ve alterada **ninguna** de sus suscripciones ni su entrada de caché `modules:{code}:enabled`, y su respuesta a un *endpoint* de ese módulo no cambia (`INV-001`, `CA-BO-033`, y el test obligatorio de la *skill* `aislamiento-tenant`).

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

#### 13.4.1 Los que añade el sub-paso `1.6b`

> `CA-BO-050` a `CA-BO-060` describen el ciclo de vida a la altura a la que se escribió el chasis. Los que siguen son los que hacen falta para **implementarlo**, y cubren en particular las tres cosas que la revisión de `1.6b` encontró sin cubrir: el camino de fallo del aprovisionamiento, el comportamiento real de `ResolveTenant` frente a `RN-BO-15`, y qué ocurre con las sesiones de los usuarios del centro.

**Alta**

- **`CA-BO-106`** · *Dado* un alta válida, *cuando* se llama al *endpoint*, *entonces* responde **`201`** con `status = 'en_alta'` **sin haber aprovisionado nada todavía**; y *cuando* el trabajo en cola termina, *entonces* el tenant queda `activo` con sus 16 roles, sus concesiones, su configuración inicial —idiomas, zona horaria, moneda y CCAA, **escritos en `tenant_settings`**— y el primer Administrador de Centro invitado (`RN-BO-52`, §5.3.3).
- **`CA-BO-107`** · *Dado* un alta **sin motivo o con motivo vacío**, *entonces* `422` y **no se crea ninguna fila en `tenants`** (`RN-BO-13`). Y *dado* un `slug` que no cumple el formato de etiqueta DNS, *entonces* `422` con `bo.tenant.slug_taken` reservado a su propio caso, no reutilizado para esto.
- **`CA-BO-108`** · *(camino de fallo)* *Dado* un aprovisionamiento que falla, *entonces* el tenant sigue en `en_alta`, existe **una** entrada `tenant.aprovisionamiento_fallido` con `actor_type = 'system'`, **no** existe ninguna fila nueva en `tenant_lifecycle_events`, y *cuando* se ejecuta `bo:retry-provisioning`, *entonces* el tenant queda `activo` **sin duplicar ningún rol, concesión, persona ni invitación** (`RN-BO-52`, §5.3.5).
- **`CA-BO-109`** · *Dado* el aprovisionamiento por la interfaz pública de `REQ-CORE`, *cuando* un test de arquitectura recorre `app/Modules/Backoffice`, *entonces* **no hay ni un `use` de una clase interna de `App\Modules\Core`** fuera de su superficie pública declarada (`RN-BO-53`, `INV-007`).

**Resolución de tenant y caché (issue [#7](https://github.com/pirexia/plataforma-educativa/issues/7))**

- **`CA-BO-110`** · *Dado* un tenant en cada uno de los cinco estados, *cuando* llega una petición a su *host*, *entonces* `en_alta`, `suspendido`, `en_baja` y `eliminado` responden **`503`** con `Retry-After` y su mensaje, `activo` pasa, y un *host* sin tenant responde **`404`** (`RN-BO-50`). **Los tres primeros fallan hoy** y este criterio es el que lo demuestra.
- **`CA-BO-111`** · *Dado* un tenant `eliminado` —y por tanto con `deleted_at`—, *cuando* llega una petición a su *host*, *entonces* recibe **`503`**, no `404`: la resolución lo encuentra pese al borrado lógico (`RN-BO-50`, §5.4.1).
- **`CA-BO-112`** · *Dado* un tenant eliminado cuyo `slug` se reutiliza en un alta posterior, *cuando* llega una petición a ese *host*, *entonces* resuelve al tenant **vivo** y sirve su estado, no el del eliminado (`RN-BO-50`, §5.4.2).
- **`CA-BO-113`** · *Dado* una transición cuya transacción **falla y revierte**, *entonces* la caché `tenant-resolution:{slug}` **no queda envenenada** con un valor intermedio y el estado servido sigue siendo el anterior. Es la comprobación de que la invalidación va **después** del `COMMIT` y no dentro (`RN-BO-51`).
- **`CA-BO-114`** · *Dado* un cambio de `slug`, *entonces* se invalidan **las dos** claves, la vieja y la nueva, y el *host* antiguo deja de resolver **de inmediato** (`RN-BO-51`, `api.md §2.5`).

**Transiciones**

- **`CA-BO-115`** · *Dado* un tenant en `en_alta`, *cuando* se intenta **cualquier** transición por API —incluida `activo`—, *entonces* `409`: de `en_alta` sólo sale el aprovisionamiento (`RN-BO-12`, `RN-BO-52`).
- **`CA-BO-116`** · *Dado* una reactivación, *entonces* `suspended_at` y `suspension_message` quedan a nulo; *dado* un rescate, *entonces* `grace_period_ends_at` y `grace_period_expired_at` quedan a nulo (`RN-BO-58`).
- **`CA-BO-117`** · *Dado* una transición ejecutada por el sistema —la de `en_alta` a `activo`—, *entonces* su `reason` es **una clave del catálogo de traducción** y existe en los cuatro idiomas; **no** una frase escrita en el código (`RN-BO-54`, `INV-009`).
- **`CA-BO-118`** · *Dado* una eliminación, *cuando* el `confirmation_name` difiere del nombre en mayúsculas, en acentos o en espacios interiores, *entonces* `422` y **no se crea solicitud** (`RN-BO-57`).

**Sesiones y datos**

- **`CA-BO-119`** · *Dado* un tenant suspendido cuyos usuarios tenían sesiones abiertas, *entonces* **ninguna sesión se cierra**, todas responden `503` mientras dure la suspensión, y *cuando* se reactiva, *entonces* **las mismas sesiones siguen sirviendo sin volver a autenticarse** (`RN-BO-55`, primer criterio de §5.51).
- **`CA-BO-120`** · *Dado* una eliminación ejecutada, *entonces* **todas** las sesiones vivas de los usuarios de ese centro quedan cerradas con `baja_usuario`, y ninguna sesión de **otro** centro se ve afectada (`RN-BO-55`).
- **`CA-BO-121`** · *Dado* un tenant con datos en varias tablas, *cuando* se elimina, *entonces* el recuento de filas de cada una de esas tablas es **idéntico** antes y después: la eliminación es el nivel 1 de `ADR-004` y no borra ni anonimiza nada (`RN-BO-56`, `INV-004`).

**Período de gracia**

- **`CA-BO-122`** · *Dado* un tenant `en_baja` cuya gracia vence, *cuando* se ejecuta la tarea diaria **tres días seguidos**, *entonces* `grace_period_expired_at` se escribe **una sola vez**, existe **una sola** entrada `tenant.gracia_vencida`, el tenant sigue `en_baja` y **sigue siendo rescatable** (`RN-BO-17`, `RN-BO-60`).

**Clonación**

- **`CA-BO-123`** · *Dado* un tenant origen con configuración operativa, identidad fiscal, marca, roles personalizados, módulos contratados, usuarios y designación de *early adopter*, *cuando* se clona, *entonces* el clon tiene **la configuración operativa, los roles —los predefinidos y los personalizados— con sus concesiones, y las suscripciones de módulo**, y **no** tiene identidad fiscal, ni marca, ni `early_adopter_since`, ni estado, ni historial, ni una sola persona copiada (`RN-BO-59`, §5.6.2).
- **`CA-BO-124`** · *Dado* una clonación, *entonces* el clon tiene **exactamente un** Administrador de Centro, creado a partir del cuerpo de la petición y con su invitación, **cuyo correo no coincide con ningún usuario del origen salvo que el operador lo haya escrito así** (`RN-BO-59`, §5.6.3).
- **`CA-BO-125`** · *Dado* un tenant origen `eliminado` o `en_alta`, *cuando* se intenta clonar, *entonces* `422` con `bo.tenant.clone_source_invalid`; *dado* uno `suspendido` o `en_baja`, *entonces* la clonación procede (`RN-BO-59`).

**Aislamiento entre centros — obligatorio para cerrar el sub-paso**

- **`CA-BO-126`** · *Dado* **dos** tenants con datos equivalentes, *cuando* se suspende, se da de baja, se elimina y se clona el primero, *entonces* el segundo no ve alterado **ninguno** de: su resolución, su entrada de caché, sus sesiones, sus suscripciones de módulo y el recuento de filas de sus tablas (`INV-001`, y el test obligatorio de la *skill* `aislamiento-tenant`).

**La baja, mientras `OPEN-BO-14` no diga lo contrario**

- **`CA-BO-127`** · *Dado* una transición a `en_baja`, *entonces* responde **`200`**, la transición se ha ejecutado y **no** se ha creado ninguna `dual_authorization` (`RN-BO-62`, §5.5.4). **Este criterio cambia de signo si el usuario resuelve `OPEN-BO-14` en sentido contrario**, y es el único de la lista que lo hace.

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
- **`CA-BO-075`** · *Dado* la suite completa, *cuando* se ejecuta, *entonces* ninguna tabla nueva de este módulo aparece en el test de esquema #8 de `ADR-033 §10` como incumplimiento. Y con `ADR-047 §4.2` aplicado la rama es **una sola**: **ninguna de las trece lleva una columna llamada `tenant_id`**, luego **las trece** caen en la rama «sin `tenant_id`» y **las trece** tienen que estar declaradas en `shared_tables.platform` de `config/tenancy.php`. Antes del renombrado esto no era cierto para `tenant_lifecycle_events`, que pasaba el test por la rama de RLS **sin que nadie comprobase su declaración** (`datos.md §11`, `ADR-047 §11` punto 5).

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

### 13.8 Visibilidad por tenant afectado, privilegios y sesión de plataforma (`ADR-047`)

> Los ocho salen de **`ADR-047`** (ACEPTADA, 2026-09-08). Los seis primeros son el mínimo que `ADR-047 §4.4` exige **por cada tabla** de la categoría «plataforma con visibilidad por tenant afectado» —`plataforma_app` no puede insertar, no puede leer una columna fuera de la lista, y **sí** ve y **sólo** ve las filas de su tenant—, con la lección de `ADR-045 §4.4`: *«un `REVOKE` que no se prueba no existe»*. Los seis se comprueban **por privilegios de motor**, no por la API, con el mismo patrón que `CA-BO-018` y `CA-BO-030`: un test que pase por el controlador comprobaría el `where` del controlador, no el `GRANT`.
>
> **El tercero de cada terna es el que importa más de lo que parece**: es el único que atrapa una **lista de columnas demasiado corta**, que es el fallo que no aparece en revisión y sí en producción, como error de privilegios sobre una consulta que el centro cree rutinaria (`ADR-047 §7`).

**`admin_action_logs`** (`datos.md §4.3`)

- **`CA-BO-098`** · *Dado* la conexión de la aplicación de los centros (`plataforma_app`), *cuando* intenta un `INSERT` en `admin_action_logs`, *entonces* **el motor lo rechaza**; y *cuando* intenta obtener un valor de `admin_action_logs_id_seq` con `nextval()`, *entonces* también lo rechaza — el `REVOKE` de tabla **no cubre la secuencia** y hace falta el suyo (`ADR-047 §4.4`, `§11` punto 1; `datos.md §12.1`).
- **`CA-BO-099`** · *Dado* esa misma conexión y dentro del contexto de un tenant, *cuando* intenta leer **cualquier** columna fuera de la lista concedida —`reason`, `actor_platform_admin_id`, `ip_address`, `user_agent`, `context`, `changes`— o un `SELECT *`, *entonces* **el motor lo rechaza por falta de privilegio de columna**, no la aplicación (`datos.md §4.3`, `§4.3.1`).
- **`CA-BO-100`** · *Dado* esa misma conexión, *cuando* consulta las columnas **sí** concedidas dentro del contexto del tenant `A`, *entonces* la consulta **funciona** y devuelve **exactamente** las filas con `affected_tenant_id = A`: ni las del tenant `B`, ni las de alcance global (`affected_tenant_id IS NULL`). Y *dado* la misma consulta **sin** contexto de tenant, *entonces* devuelve **cero** filas: la política `tenant_visibility` falla en cerrado en los dos sentidos (`ADR-047 §4.3`, regla 5).

**`tenant_lifecycle_events`** (`datos.md §5.3`)

- **`CA-BO-101`** · *Dado* la conexión `plataforma_app`, *cuando* intenta un `INSERT` en `tenant_lifecycle_events`, *entonces* el motor lo rechaza; y lo mismo con `nextval()` sobre `tenant_lifecycle_events_id_seq`.
- **`CA-BO-102`** · *Dado* esa misma conexión, *cuando* intenta leer `reason`, `performed_by` o `dual_authorization_id`, o un `SELECT *`, *entonces* **el motor lo rechaza** (`datos.md §5.3`, `§5.3.1`).
- **`CA-BO-103`** · *Dado* esa misma conexión, *cuando* consulta las seis columnas concedidas dentro del contexto del tenant `A`, *entonces* la consulta **funciona** y devuelve **exactamente** su propia historia de estados y ninguna otra; y **sin** contexto de tenant, cero filas.

**Sesión de plataforma** (`datos.md §2.6.3`, `§2.7.2`)

- **`CA-BO-104`** · *Dado* una fila viva de `platform_admin_sessions` —`ended_at IS NULL`— cuyo `session_id` **ya no existe** en `platform_sessions`, *cuando* se ejecuta `bo:close-orphaned-sessions`, *entonces* la fila queda con `session_id` nulo, `ended_at` fijado y `end_reason = 'caducidad'`, y **deja de aparecer** en la consulta de sesiones vivas del administrador — que es la de la revocación (`ADR-047 §5.1`, `operacion.md §6.3`). Y *dado* una fila viva **cuya** sesión sí existe, *entonces* la tarea **no la toca**.
- **`CA-BO-105`** · *Dado* una petición cualquiera a `/api/platform/*`, *cuando* ha pasado por el *middleware* que selecciona el almacén de sesión de plataforma, *entonces* la **conexión de base de datos por defecto de la aplicación no ha cambiado**: sigue siendo la del producto y **no** es `pgsql_platform`. El *middleware* fija `session.connection` y **sólo** eso; el `BYPASSRLS` de `pgsql_platform` se alcanza únicamente por `runAsPlatform()`, con su propósito declarado. Es lo que impide vaciar `ADR-046 §6` sin ningún síntoma (`ADR-047 §11` punto 2, `datos.md §2.6.3`).

### 13.9 Salud y métricas (`REQ-BO-004`, `REQ-BO-006` · sub-paso `1.6d`)

> **Ninguno de los que siguen está satisfecho hoy**, y aquí no hay sorpresa que declarar como la hubo en §13.3.1: **no existe ni una ruta, ni un controlador, ni una capacidad de salud o métricas en todo el módulo** (§5.9.1). Lo que sí existe es el precedente de cada mecanismo: el `REVOKE` de `failed_jobs` de `0.7`, el estampado de tenant de `ADR-033 §8`, `runAsPlatform()` con propósito de `ADR-046 §6`, el `ModuleCatalog` de `1.6c` y el valor `job.reintentado` ya desplegado en el `CHECK`.

**La ficha de salud**

- **`CA-BO-149`** · *Dado* un centro sin límites, sin certificado gestionado y sin conectores —es decir, cualquier centro de fase 1—, *cuando* se consulta `GET /tenants/{public_id}/health`, *entonces* la respuesta **no contiene** las claves de uso de recursos, certificado ni conectores, y **sí contiene** los recuentos de trabajos aunque valgan `0` (`RN-BO-83`).
- **`CA-BO-150`** · *Dado* un trabajo fallido cuyo *payload* contiene el correo de una persona del centro, *cuando* se recorre **entera** la respuesta de `GET /tenants/{public_id}/health` y la de `GET /tenants/{public_id}/failed-jobs`, *entonces* **no aparece el *payload*, ni la traza, ni ese correo** (`RN-BO-84`, `RN-BO-33`, y es la mitad de `CA-BO-074` que este sub-paso tiene que ganarse).
- **`CA-BO-151`** · *Dado* el bloque de plataforma de la ficha, *entonces* la versión y las migraciones vienen **marcadas de alcance global** y son idénticas al consultarlas desde dos centros distintos (`RN-BO-97`).
- **`CA-BO-152`** · *(el que más importa)* *Dado* un trabajo fallido del centro `A`, *cuando* se reintenta y el *worker* lo procesa, *entonces* el trabajo corre **dentro del contexto del tenant `A`** —el `tenant_id` del *payload* llegó intacto— y **ninguna de sus escrituras aparece en `B`** (`RN-BO-85`, `ADR-033 §8`). Y *dado* el código del servicio de reintento, *entonces* **no reconstruye el trabajo**: reencola el *payload* tal cual.
- **`CA-BO-153`** · *Dado* el `uuid` de un trabajo fallido del centro `B`, *cuando* se reintenta desde la ruta del centro `A`, *entonces* **`404`** —no `403`— y no se encola nada (`RN-BO-90`, `INV-001`).
- **`CA-BO-154`** · *Dado* un tenant en cada uno de los cinco estados con un trabajo fallido suyo, *cuando* se reintenta, *entonces* `en_alta`, `activo`, `suspendido` y `en_baja` responden `200` y `eliminado` responde `409` con `bo.job.tenant_state_invalid` (`RN-BO-87`). Es el simétrico de `CA-BO-136` para módulos.
- **`CA-BO-155`** · *Dado* un reintento **sin motivo o con motivo vacío**, *entonces* `422` con `bo.job.reason_required` y **la fila de `failed_jobs` sigue ahí**; *dado* uno con motivo, *entonces* existe **una** entrada en `admin_action_logs` con `action = 'job.reintentado'`, `subject_type = 'failed_job'`, `subject_public_id` igual al `uuid` y `affected_tenant_id` del centro.
- **`CA-BO-156`** · *Dado* un `uuid` ya reintentado, *cuando* se reintenta otra vez, *entonces* `404` y **no hay un segundo trabajo encolado** (`api.md §6`: la idempotencia la da la desaparición del recurso, no una `Idempotency-Key`).
- **`CA-BO-157`** · *Dado* la API de plataforma completa, *cuando* se busca un camino para reintentar más de un trabajo en una llamada —una ruta, una bandera, una lista en el cuerpo—, *entonces* **no existe ninguno** (`RN-BO-88`). Mismo espíritu que `CA-BO-090` y `CA-BO-145`.
- **`CA-BO-158`** · *(privilegios de motor, no de API)* *Dado* la conexión `plataforma_app`, *cuando* intenta `SELECT`, `UPDATE` o `DELETE` sobre `failed_jobs`, *entonces* **el motor lo rechaza**, y **sí** puede `INSERT`; *dado* el camino de reintento del backoffice, *entonces* **funciona**, porque corre por `pgsql_platform` (`RN-BO-86`). Se comprueba por privilegios, con el patrón de `CA-BO-018` y `CA-BO-030` — un test que pase por el controlador comprobaría el controlador, no el `GRANT`.

**Las métricas**

- **`CA-BO-159`** · *Dado* un tenant en cada uno de los cinco estados, incluido uno `eliminado` con `deleted_at`, *cuando* se consulta `GET /metrics/platform`, *entonces* **los cinco recuentos son correctos** y el de `eliminado` **no es cero** (`RN-BO-91`).
- **`CA-BO-160`** · *Dado* un centro dado de alta, otro dado de baja y otro eliminado dentro de la ventana, *entonces* la respuesta trae **tres series separadas** —altas, bajas y eliminaciones— y **la eliminación no está sumada a las bajas**; y *cuando* se busca un campo `churn`, *entonces* **no existe** (`RN-BO-92`).
- **`CA-BO-161`** · *Dado* un catálogo con un módulo contratado en dos centros, uno sin ninguna contratación, uno `essential` y uno `retired_at` con una suscripción viva, *cuando* se consulta `GET /metrics/module-adoption`, *entonces* **aparecen los cuatro**: el primero con `2`, el segundo con `0`, el esencial **marcado y sin recuento**, y el retirado con su marca y su recuento real. Y la respuesta trae el **denominador** (`RN-BO-93`).
- **`CA-BO-162`** · *(aislamiento — obligatorio para cerrar el sub-paso)* *Dado* **tres** tenants con datos equivalentes, *cuando* se consultan las dos métricas, *entonces* los recuentos incluyen **a los tres**; y *cuando* la misma consulta se ejecuta **fuera** del bloque `runAsPlatform(BackofficeLectura, …)`, *entonces* el resultado es **distinto y menor** — que es la demostración de que `RN-BO-94` describe un modo de fallo real y silencioso, y no una precaución teórica. Y la ficha del centro `A` no contiene **ni un** trabajo, módulo ni evento de `B` o `C` (`INV-001`, y el test obligatorio de la *skill* `aislamiento-tenant`).

**Permisos y regresiones**

- **`CA-BO-163`** · *Dado* el `enum` `PlatformCapability`, *entonces* declara **exactamente tres capacidades nuevas** —`salud.leer`, `job.reintentar` y `metrica.leer`— y ninguna más, y el test de catálogo de `permisos.md §9` sigue cuadrando celda a celda con `§3` y `§4`.
- **`CA-BO-164`** · *(regresión del hallazgo 2 de §5.9.6)* *Dado* un tenant atascado en `en_alta` con su trabajo en `failed_jobs`, *cuando* se ejecuta `bo:retry-provisioning` **con los privilegios reales de los tres roles de `ADR-033 §5`**, *entonces* el trabajo se reencola y el tenant queda `activo` — hoy ese comando falla por privilegios y **no tiene ningún test que lo demuestre** (§5.9.1).
- **`CA-BO-165`** · *Dado* el rol `soporte`, *entonces* lee la ficha, el listado de trabajos fallidos y **recibe `403` al reintentar** (`CA-BO-008`, «solo lectura y diagnóstico», y `job.reintentar` **es** escritura); *dado* `operaciones`, *entonces* reintenta; *dado* `comercial`, *entonces* recibe `403` en la ficha y `200` en las dos métricas — que es exactamente lo que `permisos.md §4` dice y lo que nadie comprueba si no se escribe.
- **`CA-BO-166`** · *(regresión del hallazgo 1 de §5.9.6, severidad **Alta**)* *Dado* una fila de `failed_jobs` con `failed_at` de hace más de 24 horas y otra de hace una, *cuando* se ejecuta `bo:purge-failed-jobs` **con los privilegios reales de los tres roles de `ADR-033 §5`**, *entonces* **la vieja desaparece, la reciente se queda, y el comando no lanza ningún error de privilegios**. Y *dado* el planificador, *entonces* **`queue:prune-failed` ya no está programado** y sí lo está `bo:purge-failed-jobs` — dejar los dos significaría que uno de ellos falla a diario en silencio, que es exactamente el estado que este criterio cierra (`RN-BO-98`, §5.9.7).

---

## 14. Preguntas abiertas

**No las resuelvo yo.** Las tres primeras eran estructurales y bloqueaban el arranque; **`ADR-046` (ACEPTADA, 2026-09-08) las cierra las tres**, y esta revisión aplica su `§10` a los cinco ficheros del módulo. **`ADR-047` (ACEPTADA, 2026-09-08) cierra además `OPEN-BO-10`**, que era la última puerta previa a la primera migración.

**Estado a 2026-09-08**, tras la respuesta del usuario y tras `ADR-046` y `ADR-047`:

| Pregunta | Estado |
|---|---|
| `OPEN-BO-01` · Separación de la aplicación | **RESUELTA por `ADR-046 §4`** · Opción A con cinco condiciones vinculantes |
| `OPEN-BO-02` · Sesión del backoffice | **RESUELTA por `ADR-046 §5`** · tabla propia `platform_sessions`. **La pregunta estaba mal planteada** y la premisa que la sostenía era falsa |
| `OPEN-BO-03` · Firma de `runAsPlatform()` | **RESUELTA por `ADR-046 §6`** · propósito declarado con **tres** casos, sin valor por defecto |
| `OPEN-BO-04` · ADR que cierre `ADR-036` | **Abierta**, no bloqueante |
| `OPEN-BO-05` · Baja sin portabilidad ni purga | **Resuelta 2026-09-08 · riesgo aceptado por el usuario** |
| `OPEN-BO-06` · Retención de `admin_action_logs` | **Abierta**, no bloqueante |
| `OPEN-BO-07` · *Feature flags* | **Resuelta 2026-09-08 · entran en alcance**, en el sub-paso `1.6e` |
| `OPEN-BO-08` · Sin pantallas hasta `1.7`/`1.9` | **Resuelta 2026-09-08 · sólo API** |
| `OPEN-BO-09` · Dos personas para eliminar un tenant | **Resuelta 2026-09-08 · aceptada sin relajar `RN-BO-19`** |
| `OPEN-BO-10` · RLS de `admin_action_logs` | **RESUELTA por `ADR-047`** (ACEPTADA, 2026-09-08) · categoría nueva «plataforma con visibilidad por tenant afectado», política `tenant_visibility` de solo lectura y `GRANT SELECT` de **columnas enumeradas**. Los vistos buenos de `architect` y `db-reviewer` **están dados**: las tres piezas aprobadas **con cambios**, ninguna rechazada |
| `OPEN-BO-11` · Direccionar un *flag* por su `key` | **Abierta · nueva**, no bloqueante. Surge de la decisión del 2026-09-08 |
| `OPEN-BO-12` · Nombre del *host* de plataforma | **Abierta · nueva**, no bloqueante. Depende de `OPEN-08` (`ADR-046 §2.1`, §4.4). Lo que **sí** queda decidido es la restricción que ese nombre deberá cumplir (`RN-BO-49`) |
| `OPEN-BO-13` · Las rutas de pre-autenticación y la aserción 2 de `ADR-046 §4.5` | **RESUELTA** (2026-09-08, lectura (a), `api.md §1.1.1`) |
| `OPEN-BO-14` · ¿La baja exige doble autorización? | **Abierta · nueva** (`1.6b`). **Nace de una contradicción interna de esta especificación**, no de una duda de diseño. Recomendación: **no** |
| `OPEN-BO-15` · Ampliar la superficie pública de `REQ-CORE` para el aprovisionamiento | **RESUELTA por `ADR-048`** (2026-09-11) · **contrato síncrono `TenantProvisioner` en `Core\Domain`, no evento**, con **dos** métodos: el alta y **la clonación** — que incumplía `INV-007` sin que nadie lo hubiera nombrado. Lo único que sigue pendiente del usuario es el permiso acotado de `ADR-048 §10`: que `1.6b` escriba las cinco cosas enumeradas dentro de `REQ-CORE` |
| `OPEN-BO-16` · El `503` permanente de un centro eliminado, y el `503` de `en_alta` | **Abierta · nueva** (`1.6b`), no bloqueante. `RN-BO-50` extiende `RN-BO-15` a dos casos que aquella no nombraba |
| `OPEN-BO-17` · ¿La descontratación **individual** exige reautenticación y/o doble autorización? | **RESUELTA 2026-09-15**: **reautenticación sí, doble autorización no**. Cambia la lista cerrada de `api.md §4` |
| `OPEN-BO-18` · Ampliar la superficie pública de `REQ-CORE` para el servicio de contratación | **RESUELTA 2026-09-15**: **sí, enumerado y sin ADR nuevo** — `ADR-045 §4.5`/`§4.8` ya decidió el mecanismo, mismo permiso acotado que `ADR-048 §10` pidió para `1.6b` |
| `OPEN-BO-19` · ¿Se cierra por columnas el `GRANT SELECT` de `module_subscriptions` para que el centro no lea `reason`? | **RESUELTA 2026-09-15 por decisión explícita del usuario: sí.** Es la única de las tres con consecuencia de seguridad. Cambia la migración de `datos.md §7` y toca dos *endpoints* de `REQ-CORE` |
| `OPEN-BO-20` · ¿Cómo se atribuye a un centro un trabajo despachado por el backoffice? | **RESUELTA 2026-09-16: se deja como está.** No se resuelve en `1.6d`; se retoma **con ADR nuevo** cuando exista un segundo caso de uso real. La ficha lo dice en dos bloques y `RN-BO-90` queda firme |
| `OPEN-BO-21` · ¿Es sensible el reintento de un trabajo fallido? | **RESUELTA 2026-09-16: sí.** Entra en la lista cerrada de `api.md §4`; `permisos.md §4.5` lo marca «Sí». Esta especificación ya estaba escrita contra el «sí», así que **no cambia ningún contenido** |
| `OPEN-BO-22` · ¿Se devuelve el **mensaje** de la excepción de un trabajo fallido? | **RESUELTA 2026-09-16: sí, se devuelve**, como excepción consciente y acotada a `RN-BO-33` (§5.9.5). Esta especificación ya estaba escrita contra el «sí» |
| `OPEN-BO-23` · ¿Se adopta Horizon, o el observatorio de colas se queda sobre las tablas del *driver* `database`? | **RESUELTA 2026-09-16: no se adopta por ahora**, y **se corrige `CLAUDE.md §1`** (v2.5.2): la fila de colas distingue lo elegido de lo instalado y remite al issue [#128](https://github.com/pirexia/plataforma-educativa/issues/128). Horizon **sigue siendo la elección de *stack***; lo que se corrige es el estado |
| **Hallazgo Alta de §5.9.6** · ¿Entra en `1.6d` el arreglo de `queue:prune-failed`? | **RESUELTA 2026-09-16: sí, entra.** Es la única de las cinco decisiones que añade trabajo de diseño: comando propio por `pgsql_platform` (§5.9.7), `RN-BO-98` y `CA-BO-166` |

**Con `1.6b` volvieron a aparecer preguntas abiertas: tres, de las cuales `ADR-048` cierra una (`OPEN-BO-15`, 2026-09-11) y de las dos restantes sólo `OPEN-BO-14` cambia un código de respuesta y por tanto un criterio de aceptación.** Lo que sigue siendo cierto es lo de la revisión anterior, referido al chasis: `ADR-047` cierra `OPEN-BO-10` **y** las dos piezas que se le habían añadido —`platform_sessions` y `platform_admin_sessions`—: las tres quedan aprobadas con cambios, ninguna rechazada (`ADR-047`, encabezado y `§5.1`). `OPEN-BO-13` queda resuelta por decisión del usuario. Lo que impedía empezar a implementar (§15) está cerrado: la aprobación explícita del usuario a la especificación completa, dada.

**Con `1.6d` aparecieron cuatro preguntas abiertas más y las cuatro se resolvieron el mismo día** (2026-09-16), todas como recomendaba `spec-writer`. **Tres de ellas no cambiaron ni una línea de contenido**, porque la especificación ya estaba escrita contra la salida que se eligió —`OPEN-BO-20`, `OPEN-BO-21` y `OPEN-BO-22`—; la cuarta, `OPEN-BO-23`, se resolvió **fuera de este documento**, en `CLAUDE.md §1`. Junto a ellas se decidió el alcance del hallazgo de severidad Alta de §5.9.6, que **sí** añade diseño: §5.9.7, `RN-BO-98` y `CA-BO-166`. Detalle y tabla de las cinco decisiones, en §15.4. **Siguen abiertas, y siguen sin bloquear, `OPEN-BO-04` y `OPEN-BO-06`.**

### `OPEN-BO-01` · ¿Cómo se separa técnicamente la aplicación del backoffice? · **RESUELTA por `ADR-046 §4`**

> **Decisión: Opción A** —mismo monolito y mismo despliegue de API, con *guard* propio, grupo de rutas propio y SPA propia—, **con cinco condiciones vinculantes** que son parte de la decisión y no glosa. **`ADR-002` no se toca.** El detalle está en §3.4; el motivo que decide, en `ADR-046 §7.1`: la Opción B rompía una regla decidida el mismo día —`ADR-045` y `RN-BO-22` exigen **una sola implementación** de las dependencias de módulos en `REQ-CORE`, y un backoffice desplegado aparte o la duplica o inventa un contrato entre servicios—.

Mi lectura previa (**A**, con dos condiciones) se ratifica en el fondo y **se corrige en la forma**: le faltaba el enrutado por `Host()`, sin el cual la Opción A no cumple el requisito. Lo que **no** decide `ADR-046` es el nombre de *host* concreto: sigue bloqueado por `OPEN-08` (ver `OPEN-BO-12`).

### `OPEN-BO-02` · ¿Dónde vive la sesión del backoffice? · **RESUELTA por `ADR-046 §5`** — y **estaba mal planteada**

> **La premisa de la que partía esta pregunta era falsa, y hay que decirlo antes que la respuesta.** Yo escribí que «`sessions` es tabla de tenant desde 1.2» y planteé como salida (b) que «`sessions.tenant_id` deja de ser obligatoria». **Esa columna no existe** (§0, punto 2; `ADR-046 §1.1`), luego la salida (b) describía algo imaginario y el argumento con el que la descartaba —«debilita una invariante sobre una tabla viva»— no aplicaba a nada real.

> **Decisión: tabla propia, `platform_sessions`** (`datos.md §2.6`). Coincide en el resultado con mi recomendación (a), **pero por un motivo distinto y verificable**: `sessions` es tabla del framework **sin `tenant_id`, sin RLS y legible por `plataforma_app`**, y en el *driver* `database` de Laravel `sessions.id` **es** el identificador de sesión. Dejar ahí las sesiones de plataforma significaría que cualquier camino que consiguiera leer esa tabla desde el *runtime* de un centro obtendría **identificadores de sesión vivos de administradores de plataforma** — una escalada de tenant a backoffice que no pasa por la autenticación. Que `plataforma_app` pueda leer las sesiones de todos los tenants es una debilidad **preexistente y aceptada** (issue [#81](https://github.com/pirexia/plataforma-educativa/issues/81), abierto): `ADR-046` **no la arregla**, pero **prohíbe heredarla**.

Lo que se construye: tabla de plataforma con `REVOKE ALL … FROM plataforma_app` en su migración, declarada en `shared_tables.platform`, cookie de nombre propio y *host-only*, vida propia y configurable, y selección del almacén **por grupo de rutas** mediante un *middleware* anterior a `start-session`. `sessions` **se queda exactamente como está**.

### `OPEN-BO-03` · ¿Se acepta cambiar la firma de `runAsPlatform()`? · **RESUELTA por `ADR-046 §6`**

> **Decisión: sí, con tres cambios sobre lo que yo proponía.** El propósito de backoffice se parte en **lectura y escritura** —tres casos, no dos—, la primitiva **exige ausencia de tenant activo**, y la obligación de auditar deja de ser una promesa y pasa a ser una **comprobación al cierre del bloque**. Firma exacta, contrato `PlatformAccessCheck`, regla de `AuditRecorder` y aserción nueva del test de arquitectura: §6.2.

De paso queda corregida una imprecisión mía: eran **dos llamadores**, no tres (`ADR-046 §11.3`).

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

### `OPEN-BO-10` · `admin_action_logs` con `affected_tenant_id` y RLS: ¿encaja en `ADR-033 §7`? · **RESUELTA por `ADR-047`**

**La pregunta.** `ADR-033 §7` clasifica `admin_action_logs` como tabla de plataforma «sin `tenant_id`, `REVOKE` completo para `plataforma_app` salvo lo imprescindible». `REQ-BO-007` exige además que sea «consultable por el propio centro en lo que le afecte», lo que obliga a una referencia al tenant afectado y a un camino de lectura para `plataforma_app`. `datos.md §4.3` propuso `affected_tenant_id` más una política de solo lectura y un `GRANT SELECT` acotado, y **no escribió la migración sin visto bueno** porque tocaba el registro de tablas compartidas y el test de esquema #8.

> **Decisión: `ADR-047`** (`docs/adr/ADR-047-tablas-de-plataforma-con-visibilidad-por-tenant-afectado.md`), **ACEPTADA el 2026-09-08**. **Los dos vistos buenos están dados y las tres piezas quedan aprobadas *con cambios*, ninguna rechazada.** El mecanismo propuesto era correcto; lo que faltaba era el sitio donde escribirlo, porque es una regla que van a copiar los 53 módulos y no cabe en la carpeta de uno — mismo argumento por el que `OPEN-CORE-09` acabó siendo `ADR-038`.

Lo que decide, y que esta revisión ha aplicado a `datos.md`, `operacion.md` y §13.8:

| # | Decisión de `ADR-047` | Dónde queda |
|---|---|---|
| 1 | **Categoría nueva** en la taxonomía de `ADR-033 §7`: «plataforma con visibilidad por tenant afectado». La adoptan `admin_action_logs` y `tenant_lifecycle_events`; **no** `feature_flag_rules` | `datos.md §1`, `§4.3`, `§5.3`, `§9.3.1` |
| 2 | **`tenant_id` queda reservado, sin excepción, a la columna de propiedad.** Una referencia a un tenant desde una tabla de plataforma se llama **`affected_tenant_id`**, siempre y en todo el proyecto | `datos.md §5.2` y `§9.3` renombran; `§4.3` ya era correcta |
| 3 | Política **`tenant_visibility`**, `FOR SELECT` únicamente, sin `WITH CHECK` y sin `OR … IS NULL`. **Lo que cierra la escritura es `FORCE` sin política permisiva**, no el `REVOKE` | `datos.md §4.3`, `§5.3` |
| 4 | El `GRANT SELECT` es de **columnas enumeradas, nunca de tabla**: RLS filtra filas, no columnas | `datos.md §4.3`, `§5.3`; la frontera de `reason` la decide el producto y se decide en `datos.md §4.3.1`/`§5.3.1`: **no cruza el `GRANT`** |
| 5 | **`platform_admin_sessions.session_id` no lleva clave foránea**, y en su lugar va un barrido de sesiones huérfanas | `datos.md §2.7.1`, `§2.7.2`; `operacion.md §6.3`; `CA-BO-104` |
| 6 | Tres criterios de privilegios **por tabla**, como mínimo | §13.8, `CA-BO-098` a `CA-BO-103` |
| 7 | Dos hallazgos reportados y **no corregidos por el propio ADR** (`§11`): el `REVOKE` de secuencia, que afecta a **las trece** tablas nuevas; y la conexión del almacén de sesión de plataforma | `datos.md §12.1` y `§11`; `datos.md §2.6.3` y `CA-BO-105` |

**Lo que `ADR-047` deliberadamente no decide**, y sigue abierto: la **retención** de `admin_action_logs` (`OPEN-BO-06`) y el **particionado**, que `datos.md §4.4` ya evaluó, descartó y dejó con disparador de revisión escrito.

**Y una consecuencia que hay que leer antes de escribir cualquier migración futura sobre estas dos tablas**: bajo `FORCE ROW LEVEL SECURITY` sin política permisiva de escritura, **ni siquiera `plataforma_owner` puede escribirlas**. Una migración *expand/contract* puede añadir una columna, pero **no puede rellenarla** sobre las filas existentes: `ADD COLUMN` es DDL y funciona, el `UPDATE` de relleno no. **Quedan fuera del ciclo *expand/contract* para siempre** (`ADR-047 §5.2`), toda columna nueva nace anulable y sin retroactividad, y `INV-004` no les aplica igual que no aplica a `audit_logs`. Es también la razón por la que los renombrados del punto 2 se hacen **ahora**: hoy es editar un fichero que no existe; después de la primera fila, no hay ciclo *contract* que valga.

### `OPEN-BO-11` · ¿Se puede direccionar un *feature flag* por su `key` en la URL?

`ADR-029` fija `public_id` ULID «en todo lo que se exponga en URL o API». `api.md §2.11` propone que las rutas de *flag* usen su **`key`** (`/feature-flags/comedor.reserva_v2`), que es lo que el código escribe y lo que un operador reconoce, y `datos.md §11` argumenta que la `key` cumple lo que `ADR-029` persigue: única, estable, inmutable, sin cardinalidad filtrada y sin ser una clave interna. El *flag* lleva su `public_id` de todos modos.

**No lo decido porque es la letra de un ADR vigente** (`CLAUDE.md §11`). Si se rechaza, las rutas pasan a `public_id` y **nada más de esta especificación cambia** — por eso no bloquea. Lo señalo en vez de resolverlo por comodidad, que es exactamente cómo se erosiona una convención de identificadores.

### `OPEN-BO-12` · ¿Cuál es el *host* del backoffice?

`ADR-046 §2.1` deja explícito que es **lo único que ese ADR no puede decidir**, porque sigue bloqueado por `OPEN-08` (dominio de la plataforma). Lo que sí queda decidido es la **restricción** que ese nombre deberá cumplir: variable de entorno propia `BACKOFFICE_HOST`, nunca derivada de `TENANCY_BASE_DOMAIN`, y **no un subdominio suyo** (`RN-BO-49`).

**No bloquea** porque toda la especificación está escrita contra la variable y ninguna parte contra un literal, y porque el `422` del *slug* colisionante (`CA-BO-017`) funciona con cualquier valor. Lo que sí exige es que `OPEN-08` se resuelva **antes del despliegue**, no antes de la implementación: sin nombre no hay reglas `Host()` en Traefik y sin ellas la separación de superficie no existe (§3.4, condición 2).

### `OPEN-BO-13` · ¿Cómo cumple la aserción 2 de `ADR-046 §4.5` en las rutas de pre-autenticación? · **RESUELTA, 2026-09-08: lectura (a)**

Surgió al aplicar `ADR-046 §10.4`: la aserción 2 exige que **toda** ruta de `/api/platform/*` lleve la pila completa, **incluidos el MFA de plataforma y la comprobación de capacidad**. Hay cuatro grupos de rutas para los que eso, literalmente, no puede cumplirse: `GET /csrf-cookie`, `POST /auth/session`, `POST /auth/session/mfa` y los tres de alta de segundo factor — que son, por `RN-BO-05`, **los únicos alcanzables sin factor confirmado**.

**Decisión del usuario: lectura (a)** (detalle en `api.md §1.1.1`) — los dos *middleware* están presentes en todas las rutas y conocen su propia excepción —la capacidad puede ser «por identidad del portador», como `GET /me`—, con lo que la aserción se cumple literalmente y **no hay lista que mantener**. Descartada la lectura (b) (lista blanca cerrada y nombrada de rutas de pre-autenticación, al modo de `RunAsPlatformArchitectureTest`), por el mismo motivo por el que `ADR-046 §6.7` prohíbe convertir una lista blanca en comodín: bajo (b) existe una lista que alguien puede ampliar; bajo (a) no existe.

### `OPEN-BO-14` · ¿La baja de un tenant exige doble autorización? · **nueva en `1.6b`**

**No es una duda de diseño: es una contradicción entre dos piezas ya escritas y una de ellas ya desplegada.** `dual_authorizations.action` admite `tenant.baja` —está en el `CHECK` de la migración del chasis y en el *enum* `DualAuthorizationAction`—, mientras que `api.md §2.4` clasifica la baja como transición simple con respuesta `200` y §5.5 sólo pone doble autorización en la eliminación.

Esta especificación está escrita contra **«no»**, con el argumento de §5.5.4: `REQ-BO-007` enumera tres operaciones que la exigen y la baja no está entre ellas, la baja es reversible durante 90 días, y exigir dos personas para una operación de rutina acaba produciendo aprobaciones adelantadas «para tenerlas firmadas».

**Lo que cambia si el usuario decide «sí»**, y es acotado: `POST /tenants/{id}/transitions` con `to_status = 'en_baja'` responde `202` con la solicitud pendiente en vez de `200`; `CA-BO-127` invierte su signo; `RN-BO-62` se retira. Nada más de esta especificación se ve afectado. **Lo señalo en vez de elegir por comodidad** porque elegir en silencio entre dos documentos aprobados es exactamente cómo una especificación deja de ser fuente de verdad.

### `OPEN-BO-15` · ¿Se aprueba ampliar la superficie pública de `REQ-CORE`? · **RESUELTA por `ADR-048`, 2026-09-11**

> **Decisión: contrato síncrono `TenantProvisioner` declarado en `Core\Domain`, con dos métodos — el alta y la clonación.** El detalle está en §5.3.4 y §5.6.2; el motivo que decide, en `ADR-048 §3.1` y `§6`.

`RN-BO-53` obliga a que el backoffice pida el aprovisionamiento a `REQ-CORE` por interfaz pública, y la que existe hoy —`provision(Tenant, adminEmail, adminGivenName, adminFamilyName)`— **no acepta la configuración inicial del centro** (idiomas, zona horaria, moneda, CCAA) que el alta sí recoge. Hay que ampliarla.

Al llevar la pregunta al usuario se le ofrecieron dos caminos —interfaz ampliada o evento de dominio— **sin haber leído el código**, y el usuario se negó, con razón, a elegir a ciegas. `ADR-048` decide sobre lo verificado, y encuentra tres cosas que la pregunta no contemplaba:

1. **El evento no era una opción viable**, y no por estilo: no devuelve resultado ni propaga fallo, y §5.3.3 necesita saber que el aprovisionamiento **terminó bien** para escribir la transición `en_alta` → `activo`, y §5.3.5 necesita el error para escribir `tenant.aprovisionamiento_fallido`. Además el reintento de `bo:retry-provisioning` obligaría a **reemitir `TenantCreated`**, que es un enunciado de hecho falso: el tenant no se ha vuelto a crear.
2. **El patrón ya existe y está repetido nueve veces**: `REQ-AUTH` consume `REQ-CORE` siempre igual —interfaz en `Core\Domain`, enlace en `CoreServiceProvider`, implementación dentro de `REQ-CORE`—, y los eventos se usan en la dirección contraria, para reaccionar a hechos consumados. Ningún evento del repositorio ordena nada ni devuelve resultado.
3. **La clonación de §5.6 tenía el mismo problema y nadie lo había nombrado.** Copia `tenant_settings`, `roles` y `permission_role`, que son tablas de `REQ-CORE`; tal como estaba escrita, `CloneTenant` las leería y escribiría desde `REQ-BO`. Por eso el contrato tiene **dos** métodos.

**Lo que sigue abierto, y es sólo esto**: el permiso para que `1.6b` escriba código dentro de `REQ-CORE`. `ADR-048 §10` lo acota a cinco cosas —tres tipos y un enumerado nuevos en `Core\Domain`, `implements` y un segundo método en la clase que ya existe, una línea de enlace en el proveedor, opciones nuevas en el comando de consola, y el `locale` del primer administrador— y deja explícito que **no se toca ninguna migración, columna, endpoint, permiso ni regla de negocio de `REQ-CORE`**. Si el usuario prefiere que esa ampliación se haga en un paso propio en vez de dentro de `1.6b`, el mecanismo no cambia: cambia sólo dónde se commitea.

### `OPEN-BO-16` · El `503` permanente de un centro eliminado, y el `503` de `en_alta` · **nueva en `1.6b`**

`RN-BO-15` nombra tres estados sin acceso —`suspendido`, `en_baja`, `eliminado`— y les asigna `503`. `RN-BO-50` completa el mapa y con ello toma **dos** decisiones que aquella regla no tomaba:

1. **`en_alta` también responde `503`**, no `404`. Es coherente con «`404` sólo cuando no hay tenant», y hace que la ventana de aprovisionamiento sea observable en vez de indistinguible de un error de DNS.
2. **Un centro eliminado responde `503` para siempre**, con lo que su *host* sigue revelando que ese centro existió. La alternativa —pasar a `404` transcurrido un plazo— convertiría el `404` en dos cosas distintas y obligaría a un temporizador nuevo.

Las dos son extensiones razonadas de una regla aprobada, no reinterpretaciones de ella, y por eso van escritas y no ejecutadas en silencio. **No bloquean**: si el usuario prefiere `404` en alguno de los dos casos, el cambio es una fila de una tabla en `ResolveTenant` y una fila de `CA-BO-110`.

### `OPEN-BO-17` · ¿La descontratación **individual** exige reautenticación y doble autorización? · **RESUELTA 2026-09-15: reautenticación sí, doble autorización no**

Son **dos** preguntas y conviene separarlas, porque tienen respuestas distintas.

**Doble autorización: no, y aquí no hay duda.** `REQ-BO-007` enumera literalmente las tres operaciones que la exigen —*«eliminar un tenant, purgar datos o desactivar módulos **en masa**»*— y la descontratación individual no está entre ellas. El vocabulario de `dual_authorizations.action` lo confirma: el valor desplegado es `modulo.descontratar_masivo`, no `modulo.descontratar`. Exigir dos personas para apagar un módulo en un centro convertiría una operación comercial de rutina en un trámite que se pide por adelantado «para tenerlo firmado», que es el mismo argumento de §5.5.4 con la baja.

**Reautenticación: creo que sí, y esto sí es una pregunta de verdad.** `api.md §4` declara la lista de operaciones sensibles **cerrada y en un solo sitio**, *«para que añadir una operación destructiva obligue a tocar este documento»*, y `PUT /tenants/{id}/modules/{code}` **no está en ella**. Descontratar un módulo deja sin acceso a una funcionalidad entera a todo un colegio, con consecuencia comercial (`RMOD-007`) y visible de inmediato para sus familias. Comparado con lo que sí está en la lista —`POST /tenants/{id}/slug`, que rompe una URL guardada— la asimetría no se sostiene.

**La propuesta es asimétrica, y esa es la parte que hay que aprobar o rechazar**: sensible **sólo con `enabled: false`**, no al contratar. Contratar va en la dirección de dar servicio y se deshace descontratando; descontratar es la dirección que el centro nota. Es la misma asimetría que `api.md §2.12` ya defiende para los *feature flags*, **aplicada al revés y a propósito**: allí el freno de emergencia es apagar y la fricción va en encender; aquí la dirección peligrosa es apagar, porque no hay ninguna emergencia que apagar un módulo resuelva.

**Lo que cambia si el usuario dice «no»**, y es acotado: se retira `PUT /tenants/{id}/modules/{code}` **con `enabled: false`** de la lista de `api.md §4`, y la columna «¿sensible?» de `permisos.md §4.4` pasa a «no» en esa fila. **Esta especificación está escrita contra el «sí»**, marcado en los tres sitios donde aparece (§5.8.4, `api.md §2.6`/`§4`, `permisos.md §4.4`). Nada más se ve afectado, y ningún criterio de aceptación cambia de signo.

### `OPEN-BO-18` · ¿Se aprueba ampliar otra vez la superficie pública de `REQ-CORE`? · **RESUELTA 2026-09-15: sí, acotada y sin ADR nuevo**

Es **la misma pregunta que `ADR-048 §10` planteó para `1.6b`**, sobre otro contrato, y por eso se plantea igual: no es una duda de diseño —`ADR-045 §4.5` y `§4.8` ya decidieron que la resolución de dependencias y la emisión de eventos viven en un servicio de dominio de `REQ-CORE`— sino un **permiso para escribir código dentro de otro módulo desde este sub-paso**.

Lo que se toca, enumerado y cerrado (§5.8.2):

1. Dos interfaces y tres tipos nuevos en `Core\Domain`: `ModuleCatalog`, `ModuleContracting`, `ModuleDescriptor`, `ModuleChange`, `ModuleContractingOutcome` (más `ModuleContractingPreview`).
2. Dos claves nuevas con valor por omisión en `DeclaresModuleRegistry::moduleDescriptor()`: `depends_on` y `essential`.
3. La implementación de las dos interfaces en `Core\Infrastructure`, y dos líneas de enlace en `CoreServiceProvider`.
4. La retirada de `ALWAYS_ENABLED` de `EloquentModuleAvailability`, sustituida por `essential` del descriptor (`CA-BO-036`, que ya estaba aprobado).
5. Dos clases de evento en `Core\Domain\Events`, más las tres validaciones de `SyncModuleRegistry`.

**No se toca ninguna migración, columna, *endpoint*, permiso ni regla de negocio de `REQ-CORE`** — con **una excepción que depende de `OPEN-BO-19`**: si se cierra el `GRANT` por columnas, `ModuleSubscription` necesita una proyección explícita y eso sí alcanza a `GET /modules` y `PATCH …/settings`, que son suyos.

**Recomendación: sí, y sin ADR nuevo.** `ADR-048` hizo falta porque `ADR-045` no había decidido **el mecanismo** del aprovisionamiento —contrato síncrono o evento— y la pregunta se había llevado al usuario sin haber leído el código. Aquí el mecanismo **ya está decidido** por `ADR-045 §4.5`/`§4.8`, y lo único que esta especificación añade es la firma, siguiendo el precedente que `ADR-048` acaba de sentar. Si el usuario prefiere que la ampliación se haga en un paso propio, el mecanismo no cambia: cambia sólo dónde se *commitea*.

### `OPEN-BO-19` · ¿El centro puede leer el motivo interno del proveedor? · **RESUELTA 2026-09-15: no, se cierra el `GRANT` por columnas — decisión explícita del usuario, es la única de las tres con consecuencia de seguridad**

**El problema, verificado**: `module_subscriptions` es tabla de tenant, su RLS le da al centro sus propias filas, y el `REVOKE` que `datos.md §7` especifica quita **`INSERT` y `UPDATE`, no `SELECT`**. A partir de este sub-paso, `reason` guarda texto libre escrito por un operador de plataforma —«impago del segundo trimestre», «rescisión, contrato 2026/27»— y **el centro puede leerlo**.

Es el mismo caso que el usuario ya resolvió el 2026-09-08 para `admin_action_logs` y `tenant_lifecycle_events`, con un criterio que se ratificó explícitamente: **ningún texto libre escrito por un operador del proveedor cruza el `GRANT`** (`datos.md §4.3.1`, `§5.3.1`). Y el argumento de por qué aparece **ahora** es idéntico al del issue #7 en `1.6b`: era inofensivo mientras nadie escribiera esa columna, y **este sub-paso es el que deja de hacerlo cierto**.

**Recomendación: cerrarlo, con su coste dicho.** La forma exacta está en `datos.md §7.7`: `REVOKE SELECT` de tabla y `GRANT SELECT` de columnas enumeradas, con `reason` fuera. El coste es real y no se esconde: `ModuleSubscription` deja de poder hacer `SELECT *`, y eso alcanza a `GET /modules` y a `PATCH …/settings` de `REQ-CORE`, que están en producción. A cambio, el fallo es **ruidoso** —error de privilegios, no una respuesta de más— y la superficie vuelve a ser el `GRANT` y no la proyección de un *resource*, que es lo que `ADR-047 §4.4` fijó como criterio.

**Lo que cambia si el usuario dice «no»**: `RN-BO-82` pasa a ser una regla de proyección del *resource* de `REQ-CORE` en vez de un privilegio, `CA-BO-147` se retira, y queda escrito que la garantía es más débil. **No es una decisión que yo pueda tomar**: cambia una migración ya especificada y toca *endpoints* de otro módulo ya desplegados.

### `OPEN-BO-20` · ¿Cómo se atribuye a un centro un trabajo que despachó el backoffice? · **RESUELTA 2026-09-16: se deja como está**

> **Decisión del usuario del 2026-09-16: la salida (c) —dejarlo como está—**, que es la que esta especificación recomendaba. `1.6d` **no** toca el estampado de `ADR-033 §8` ni la firma de nada en `App\Support\Tenancy`: la ficha separa «trabajos del centro» de «última incidencia de plataforma» (§5.9.2, §5.9.3) y `RN-BO-90` queda firme tal como está escrita.
>
> **Lo que la decisión no cierra, y queda con su disparador escrito**: el día que aparezca un **segundo** consumidor real —algo que necesite saber a qué centro afecta un trabajo de plataforma y que no esté ya cubierto por `admin_action_logs`—, la salida es la **(b)** de la tabla de abajo y **exige un ADR nuevo** (`CLAUDE.md §11`), porque toca la letra de `ADR-033 §8` e infraestructura compartida por todo el producto. No se improvisa entonces: el razonamiento ya está aquí.
>
> **Y la consecuencia aceptada, dicha en voz alta**: un `RunModuleRollout` fallido sobre un centro concreto **sigue sin verse desde la ficha de ese centro** (`RN-BO-78`). Se ve en `modulo.masivo_ejecutado`, de alcance global.

**No es una duda de diseño: es una contradicción entre esta especificación y un mecanismo verificado del código**, y aparece exactamente igual que apareció `OPEN-BO-14` — dos piezas ya escritas que no pueden ser ciertas a la vez.

`ADR-033 §8` estampa en el *payload* de todo trabajo el tenant **activo al despachar** (`Queue::createPayloadUsing()`, verificado). `ADR-046 §6.4` **prohíbe** que haya tenant activo dentro de un bloque de plataforma. Los cuatro trabajos del backoffice se despachan desde ahí. Luego `payload.tenant_id` es **nulo** en `ProvisionTenant`, `CloneTenant`, `RunModuleRollout` y `RevokeTenantSessions`, y una lista filtrada por ese campo **no los contiene**. Y sin embargo §5.3.5 dice que un aprovisionamiento fallido *«ya es visible por la ficha de salud del centro»* y `operacion.md §8` manda mirar *«`failed_jobs` del tenant»*. Las dos frases son falsas.

**Esta especificación está escrita contra la lectura conservadora** —la ficha separa «trabajos del centro» de «última incidencia de plataforma», y la segunda sí cubre el caso (§5.9.3)— porque las dos salidas que lo cerrarían de verdad tocan infraestructura compartida:

| Salida | Coste | Qué habría que decidir |
|---|---|---|
| **(a)** Que el estampado de `ADR-033 §8` distinga «el tenant en el que corro» de «el tenant al que afecto», con una segunda clave en el *payload* | Toca `App\Support\Tenancy`, que usa **todo** el producto, y la letra de un ADR vigente | **Un ADR nuevo** (`CLAUDE.md §11`), exactamente como `runAsPlatform()` acabó necesitando `ADR-046 §6` |
| **(b)** Que un trabajo declare su tenant afectado por una interfaz de `App\Support` que el estampador consulte al despachar | Igual de invasivo, pero **explícito por trabajo** y sin adivinanzas | Ídem, y además elegir qué pasa con los trabajos que afectan a varios centros —`RunModuleRollout` afecta a doscientos— |
| **(c)** Dejarlo como está, y que la ficha lo diga en dos bloques | **Ninguno.** Es lo que `1.6d` implementa | Nada, salvo aceptar que `RunModuleRollout` fallido sobre un centro sigue sin verse desde su ficha (§5.9.3) |

**Recomendación: (c) ahora, y (b) el día que haya un segundo caso que lo pida.** El único consumidor real de esto hoy es un aprovisionamiento fallido, y ése ya tiene su entrada en `admin_action_logs` con `affected_tenant_id`, escrita desde `1.6b`. Construir (a) o (b) para un solo consumidor que ya está cubierto es tocar la infraestructura de aislamiento de todo el producto para arreglar una frase de un documento.

**Lo que hay que corregir en cualquier caso, se decida lo que se decida**: las dos frases falsas. Si se elige (c), se reescriben; si se elige (a) o (b), dejan de serlo. **Lo señalo en vez de reescribirlas por mi cuenta** porque una de ellas está en una sección aprobada e implementada (`1.6b`) y cambiar en silencio lo que dice un documento aprobado es exactamente cómo una especificación deja de ser fuente de verdad.

### `OPEN-BO-21` · ¿Es sensible el reintento de un trabajo fallido? · **RESUELTA 2026-09-16: sí**

> **Decisión del usuario del 2026-09-16: sí, exige reautenticación viva.** Entra en la lista cerrada de `api.md §4`, y la celda «¿sensible?» de `api.md §2.10` y de `permisos.md §4.5` dice «Sí». **Esta especificación ya estaba escrita contra el «sí», así que la decisión no cambia ni una línea de contenido**: sólo la cierra.

`api.md §4` declara la lista de operaciones sensibles **cerrada y en un solo sitio**, *«para que añadir una operación destructiva obligue a tocar este documento»*. Este sub-paso añade una escritura, y la pregunta es si entra en esa lista. Es la misma pregunta que `OPEN-BO-17` para la descontratación individual, sobre otra operación.

**Argumento a favor, y es el del propio proyecto**: `permisos.md §4.1` ya lo escribió al repartir la capacidad — *«`job.reintentar` **parece** diagnóstico y **es** escritura: reejecuta un trabajo que puede enviar correos, modificar datos y disparar eventos»*. Un reintento de `SendInvitationEmail` manda un correo a una familia real; uno de `RunModuleRollout` toca doscientos centros. Comparado con lo que sí está en la lista —`POST /tenants/{id}/slug`, que rompe una URL guardada— la asimetría no se sostiene.

**Argumento en contra, y hay que ponerlo porque es real**: reintentar es la operación de diagnóstico más frecuente que tendrá este módulo, suele hacerse en mitad de un incidente, y la fricción en mitad de un incidente es cómo se acaban compartiendo credenciales — el mismo razonamiento con el que `api.md §2.12` deja el freno de emergencia de un *flag* **sin** reautenticación.

**La diferencia que decide, y que hace que la recomendación sea «sí»**: apagar un *flag* va en la dirección segura y se deshace con otra llamada; **un reintento no se deshace**. El correo ya salió. Por eso esta especificación está escrita contra el «sí», marcado en los tres sitios donde aparece (§5.9.4, `api.md §2.10.3`/`§4`, `permisos.md §4.5`).

**Lo que cambia si el usuario dice «no»**, y es acotado: se retira esa entrada de `api.md §4` y la celda «¿sensible?» de `permisos.md §4.5` pasa a «no». **Ningún criterio de aceptación cambia de signo** y nada más se ve afectado.

### `OPEN-BO-22` · ¿El mensaje de la excepción de un trabajo fallido cruza al backoffice? · **RESUELTA 2026-09-16: sí, se devuelve**

> **Decisión del usuario del 2026-09-16: se devuelve `exception_message`**, como esta especificación recomendaba. **Queda registrado como lo que es: una excepción consciente y acotada a `RN-BO-33`**, no un descuido — el campo puede arrastrar un dato del centro, está acotado a un rol interno con `salud.leer`, y sin él `REQ-BO-004` («Salud y **diagnóstico**») se vacía. `RN-BO-84` sigue dejando fuera el *payload* y la traza, que son el caso claro, y `CA-BO-150` sigue comprobándolo recorriendo la respuesta entera.
>
> **Lo que esta decisión obliga a escribir fuera de aquí**: la nota de `SECURITY.md` de `operacion.md §10`, que dice que **aquí la barrera es la proyección del *resource* y no un `GRANT` de columna** — una garantía más débil que la de `ADR-047 §4.4`, sostenida por un test y no por el motor. **Tiene que constar, no descubrirse en una revisión.**

**El problema, verificado**: `RN-BO-33` y `REQ-BO-007` dicen que el backoffice *«muestra métricas y estado, no listados de alumnos»*, y `CA-BO-074` lo comprueba recorriendo todas las respuestas del módulo. El **mensaje** de una excepción de PostgreSQL puede arrastrar el valor que violó una restricción, y ese valor puede ser el correo de una familia o el nombre de un alumno. `RN-BO-84` ya deja fuera el *payload* y la traza, que son el caso claro; el mensaje es el borde.

- **Devolverlo** es una excepción consciente a `RN-BO-33`, acotada a un campo y a un rol interno (`salud.leer`, que tienen `soporte`, `operaciones` y `superadministrador`), y sin la cual **el requisito se vacía**: `REQ-BO-004` se titula «Salud y **diagnóstico**», y «`QueryException`» no diagnostica nada.
- **No devolverlo** deja la ficha estrictamente conforme a `RN-BO-33` y convierte cada incidencia en un acceso al servidor para leer el log — que es justo lo que este *endpoint* existe para evitar, y que además **no** está más protegido: quien lee el log lo ve todo.
- **Una tercera vía que no recomiendo**: redactar el mensaje con la política de `ADR-035`. Esa maquinaria sabe redactar **atributos de un modelo conocido**, no texto libre de un motor de base de datos; aplicada aquí adivinaría, y una redacción que adivina da la peor combinación — mensajes mutilados que siguen filtrando lo que no supo reconocer.

**Esta especificación está escrita contra devolverlo**, y lo deja dicho como excepción y no como descuido (§5.9.5). **Lo que cambia si el usuario decide lo contrario**: `RN-BO-84` incluye también el mensaje, `CA-BO-150` gana una aserción más y la ficha devuelve sólo la clase de la excepción. Nada más se ve afectado.

### `OPEN-BO-23` · ¿Se adopta Horizon? · **RESUELTA 2026-09-16: no por ahora, y se corrige la documentación**

> **Decisión del usuario del 2026-09-16: no se adopta Horizon en `1.6d`**, y **`CLAUDE.md §1` se corrige** (versión **2.5.2**, 2026-09-16). La corrección es de **estado, no de elección**: Horizon sigue siendo la tecnología elegida para colas; lo que se arregla es que la tabla de *stack* daba a entender que ya estaba desplegado. La fila pasa a distinguir lo que hay hoy —*driver* `database`, tablas `jobs`/`failed_jobs`, **sin *worker* desplegado**, issue [#128](https://github.com/pirexia/plataforma-educativa/issues/128)— de lo elegido y no instalado, y se añade la regla general de que **esa tabla dice qué está elegido, no qué está instalado**, con este caso como motivo.
>
> **Por qué la corrección no es cosmética**: esta misma especificación llegó a afirmar que la ficha de salud leía datos «de Horizon», y nadie lo detectó hasta que `1.6d` fue a implementarlo. Una tabla de *stack* que no distingue elegido de instalado produce exactamente eso.

`CLAUDE.md §1` lista «Redis + Laravel Horizon» en la tabla de *stack*, y esta misma especificación decía en §5.9 que los trabajos en cola salen «de Horizon». **Horizon no está instalado** y nada lo instala (§5.9.1, verificado sobre `composer.json`): la cola por defecto es `database`, los trabajos viven en tablas de PostgreSQL y no hay ningún panel.

**No lo decido yo**, porque es una dependencia nueva y `CLAUDE.md §1` exige justificarla, comprobar su mantenimiento y envolverla tras una interfaz propia (`RNF-MANT-007`). Lo que sí digo es lo que cuesta cada camino:

| | Coste | Qué gana |
|---|---|---|
| **No adoptarlo** (recomendado para `1.6d`) | Ninguno. Dos consultas sobre `jobs` y `failed_jobs` | La ficha de salud funciona hoy, sin dependencia, sin Redis como cola y sin un segundo panel con su propia autenticación que proteger |
| **Adoptarlo** | Dependencia nueva + `QUEUE_CONNECTION=redis` + un panel web propio que hay que dejar fuera del *host* del backoffice o dentro de él con su propia autorización — **una tercera superficie**, después de la del producto y la de plataforma | Métricas de cola de verdad: rendimiento por cola, trabajos lentos, reintentos automáticos |

**Recomendación: no ahora.** El parque no tiene ni un centro real (issue [#128](https://github.com/pirexia/plataforma-educativa/issues/128): no hay ni *worker* desplegado), y adoptar un panel de observabilidad antes que el *worker* que observar es construir el termómetro antes que el paciente. **Lo que sí conviene decidir ya es documental**: o `CLAUDE.md §1` deja de nombrar Horizon como parte del *stack*, o queda escrito que es intención y no estado — hoy induce a error a cualquiera que lea la tabla, **y ya indujo a error a esta misma especificación**, que es la prueba.

---

## 15. ¿Se aprueba esta especificación?

**Aprobada.** El 2026-09-08 el usuario resolvió cuatro de las diez preguntas abiertas, `ADR-046` cerró las tres bloqueantes, `ADR-047` cerró `OPEN-BO-10` junto con los vistos buenos técnicos que faltaban, y el usuario aprobó explícitamente la especificación completa con las tres cosas aplicadas — más, en una segunda ratificación el mismo día, los dos puntos de decisión propia de la pasada de `ADR-047` (fila 5 y 6 de la tabla de abajo).

Lo resuelto el 2026-09-08, y ya incorporado a este documento:

| # | Decisión | Dónde queda |
|---|---|---|
| 1 | **División en cuatro pasos, confirmada** tal como estaba descrita | §12.2, sin cambiar una fila de la tabla original |
| 2 | ***Feature flags* dentro de alcance** (`OPEN-BO-07`) | §2.1, §5.11, §7.5, §12.3 (sub-paso `1.6e`), §13.7 |
| 3 | **Sólo API hasta `1.7`/`1.9`** (`OPEN-BO-08`) | §12.5, §14 |
| 4 | **Baja sin portabilidad ni purga, riesgo aceptado** (`OPEN-BO-05`) | §14, sin tocar §5.5 ni §7.2 |
| 5 | **Dos personas reales para eliminar un tenant** (`OPEN-BO-09`), sin relajar `RN-BO-19` | §14, `operacion.md §5` paso 5 |

Lo que trae **`ADR-046`**, aplicado en esta pasada a los cinco ficheros del módulo (`ADR-046 §10`):

| # | Decisión | Dónde queda |
|---|---|---|
| 6 | **Opción A con cinco condiciones vinculantes** (`OPEN-BO-01`) | §0 punto 3, §3.4, `RN-BO-48`, `RN-BO-49`, `CA-BO-011` y `CA-BO-013` a `CA-BO-017`, `api.md §0`/`§1.1`, `operacion.md §0` y `§2`, `permisos.md §5` |
| 7 | **`platform_sessions`, tabla propia** (`OPEN-BO-02`), sustituyendo una **premisa falsa** sobre `sessions.tenant_id`, más `platform_admin_sessions` para poder revocar | §0 punto 2, §1.2, §5.1, §14, `datos.md §2.6` y `§2.7`, `api.md §1`, `CA-BO-018` |
| 8 | **`runAsPlatform(PlatformAccessPurpose, Closure)`**, tres propósitos, sin tenant activo, con regla de cierre (`OPEN-BO-03`) | §6.2 completo, `CA-BO-025` a `CA-BO-029` |
| 9 | **`1.6` recoge el trabajo de `infra/quadlet` y de `App\Support\Tenancy`** que la separación de superficie exige | §12.2 y **§12.2.1**, `operacion.md §0` y `§5` paso 0 |
| 10 | Corrección de «tres llamadores» de `runAsPlatform()` → **dos** | §6.2 |
| 11 | **Dos preguntas abiertas nuevas**, ninguna bloqueante: el nombre del *host* (`OPEN-BO-12`, que `ADR-046 §2.1` declara fuera de su alcance) y las rutas de pre-autenticación frente a la aserción 2 (`OPEN-BO-13`, detectada al aplicar `ADR-046 §10.4`) | §14, `api.md §1.1.1` |

Lo que trae **`ADR-047`** (ACEPTADA, 2026-09-08), aplicado en esta pasada a `funcional.md`, `datos.md`, `api.md` y `operacion.md` (`permisos.md` no cambia: `ADR-047` no toca ninguna capacidad):

| # | Decisión | Dónde queda |
|---|---|---|
| 12 | **`OPEN-BO-10` resuelta**: categoría «plataforma con visibilidad por tenant afectado», política `tenant_visibility` de solo lectura y `GRANT SELECT` de **columnas enumeradas**. Los dos vistos buenos, dados; las tres piezas, aprobadas con cambios | §14 (`OPEN-BO-10`), `datos.md §1`, `§4.3`, `§5.3` |
| 13 | **`tenant_id` reservado a la columna de propiedad**: `tenant_lifecycle_events` y `feature_flag_rules` renombran su referencia a **`affected_tenant_id`** | `datos.md §5.2`, `§9.3`, `§9.3.1`, `§11`, `api.md §2.11` |
| 14 | **`platform_admin_sessions.session_id` sin clave foránea**, con barrido de sesiones huérfanas en su lugar | `datos.md §2.7.1`, `§2.7.2`, `operacion.md §6.2` y `§6.3`, `CA-BO-104` |
| 15 | **Ocho criterios de aceptación nuevos**, `CA-BO-098` a `CA-BO-105` | §13.8 |
| 16 | **Dos hallazgos de `ADR-047 §11` incorporados**: el `REVOKE` de secuencia en **las trece** tablas nuevas, y la conexión del almacén de sesión de plataforma | `datos.md §11`, `§12.1`, `§2.6.3`, `operacion.md §8`, `CA-BO-105` |
| 17 | La afirmación del checklist «las trece se declaran … o el test #8 falla» **pasa a ser cierta**, y **antes no lo era** para `tenant_lifecycle_events` | `datos.md §11` |

**Nada queda pendiente antes de que `implementer` toque una línea.** Registro de cierre:

1. ~~El visto bueno de `db-reviewer` y `architect` a `OPEN-BO-10`, `platform_sessions` y `platform_admin_sessions`.~~ **Cerrado por `ADR-047`**: los dos vistos buenos están dados y las tres piezas quedan aprobadas con cambios, ninguna rechazada.
2. ~~`OPEN-BO-13`.~~ **Resuelta** el 2026-09-08: lectura (a), sin lista blanca que mantener (`api.md §1.1.1`).
3. ~~La aprobación explícita del usuario a esta especificación completa.~~ **Dada el 2026-09-08**, con `ADR-046` y `ADR-047` aplicados.

**Puntos donde decidió la sesión orquestadora y quedan ratificados por el usuario** (todos el 2026-09-08): que la unidad de reparto de un *flag* la declare el código y no el operador (§5.11.2), que el rol filtre y no amplíe (§5.11.4), que `1.6e` vaya el último de los cinco sub-pasos (§12.3), el diseño de `platform_admin_sessions` (`datos.md §2.7`), y los dos que trajo la pasada de `ADR-047`:

| # | Decisión | Dónde | Ratificada |
|---|---|---|---|
| 5 | **`reason` no cruza el `GRANT SELECT`** en ninguna de las dos tablas. `ADR-047 §2.1` declina decidirlo y lo remite a `datos.md` | `datos.md §4.3.1`, `§5.3.1` | Sí, 2026-09-08 |
| 6 | **`tenant_lifecycle_events.affected_tenant_id` es `NOT NULL`**, mientras que `ADR-047 §4.2` describe la columna de la categoría como anulable —«nula ⇒ alcance global»— | `datos.md §5.2` | Sí, 2026-09-08 |

Lo que **sí** está cerrado y no espera a nadie es el encargo de `ADR-045 §10`: el documento de requisitos está en 3.2.0 con los trece requisitos reescritos, y `OPEN-CORE-03` queda marcado como resuelto en `docs/modulos/REQ-CORE/funcional.md`.

### 15.1 Cierre de implementación (2026-09-09/10)

El chasis se implementó (`aa668ba`) y pasó dos rondas de revisión independiente. **Primera pasada**: tres hallazgos Alta (issues [#173](https://github.com/pirexia/plataforma-educativa/issues/173)-[#175](https://github.com/pirexia/plataforma-educativa/issues/175) — mecanismo de invitación real, `RequirePlatformMfa` sin lista blanca, OpenAPI de plataforma) y dos Media (issues [#176](https://github.com/pirexia/plataforma-educativa/issues/176)/[#177](https://github.com/pirexia/plataforma-educativa/issues/177)), todos corregidos. **Segunda pasada**, centrada en esas correcciones: un cuarto Alta (issue [#180](https://github.com/pirexia/plataforma-educativa/issues/180), límite de tasa ausente en el canje) y cuatro Media (issues [#181](https://github.com/pirexia/plataforma-educativa/issues/181), [#183](https://github.com/pirexia/plataforma-educativa/issues/183)-[#185](https://github.com/pirexia/plataforma-educativa/issues/185)), todos corregidos. Dos hallazgos Baja quedan documentados, sin corregir a propósito (issues [#178](https://github.com/pirexia/plataforma-educativa/issues/178)/[#179](https://github.com/pirexia/plataforma-educativa/issues/179)/[#182](https://github.com/pirexia/plataforma-educativa/issues/182)/[#186](https://github.com/pirexia/plataforma-educativa/issues/186)). Detalle completo en `CHANGELOG.md`.

El hueco de especificación señalado en la resolución del issue #173 — no existe todavía un `CA-BO` numerado para `POST /admin-invitation-redemptions` — sigue abierto: documentado en `api.md §2.1`, no inventado aquí, pendiente de asignar cuando se revise `§13` con calma. **`1.6b` no lo cierra a propósito**: pertenece al chasis, ya implementado y mezclado, y asignarle un número desde un sub-paso posterior sin escribir su test sería cambiar el hueco de sitio.

### 15.2 Sub-paso `1.6b` · ciclo de vida de tenants — **cerrado y mezclado (2026-09-11/15)**

Esta pasada especifica `REQ-BO-001` completo a la altura que hace falta para implementarlo, sobre el chasis cerrado en `1.6`. Lo que trae, y dónde está:

| # | Qué | Dónde |
|---|---|---|
| 1 | **El alta en dos fases**, que resuelve una incoherencia entre `funcional.md` y `operacion.md` a favor del trabajo en cola, y el camino de fallo del aprovisionamiento, que no estaba escrito | §5.3, `RN-BO-52`, `CA-BO-106` a `CA-BO-108` |
| 2 | **`ResolveTenant` no cumple hoy `RN-BO-15`**, verificado sobre el código: `en_baja` y `eliminado` responden `404`, y un tenant con `deleted_at` ni se encuentra. Arreglarlo es de este sub-paso | §5.4.1, `RN-BO-50`, `CA-BO-110` a `CA-BO-112` |
| 3 | **El mecanismo exacto del issue [#7](https://github.com/pirexia/plataforma-educativa/issues/7)**: por qué aquí basta un `forget` y en `1.6c` no, y por qué va **después** del `COMMIT` | §6.3, `RN-BO-51`, `CA-BO-113`, `CA-BO-114` |
| 4 | **Qué pasa con las sesiones de los usuarios del centro**: eliminar revoca, suspender y dar de baja no, con el motivo de la asimetría | §5.4.3, `RN-BO-55`, `CA-BO-119`, `CA-BO-120` |
| 5 | **Borrado lógico frente a purga**, con la consecuencia sobre datos de menores dicha en voz alta | §5.5.3, `RN-BO-56`, `CA-BO-121` |
| 6 | **Inventario exacto de la clonación**, incluido lo que **no** se copia y por qué, y de dónde sale el primer administrador del clon | §5.6.2, `RN-BO-59`, `CA-BO-123` a `CA-BO-125` |
| 7 | **Qué significan «marca» y «avisa»** al vencer el período de gracia, y la columna que lo hace idempotente | §5.5.1, `RN-BO-60`, `datos.md §6`, `CA-BO-122` |
| 8 | **Trece reglas nuevas** (`RN-BO-50` a `RN-BO-62`) y **veintidós criterios nuevos** (`CA-BO-106` a `CA-BO-127`) | §7.2.1, §13.4.1 |
| 9 | **`ADR-048` (2026-09-11)**, aplicado a esta pasada: el aprovisionamiento se pide a `REQ-CORE` por **contrato síncrono `TenantProvisioner`** —con el evento de dominio descartado y por qué—, y **la clonación pasa por el mismo contrato**, porque copiaba tres tablas de `REQ-CORE` desde `REQ-BO` | §5.3.4, §5.6.2, §5.6.4, `RN-BO-53`, `operacion.md §6.1` y `§8` |

**Y tres preguntas que no resolvió `spec-writer`**: `OPEN-BO-14` (¿doble autorización en la baja? — contradicción interna, recomendación «no»), `OPEN-BO-15` (ampliar la superficie pública de `REQ-CORE`) y `OPEN-BO-16` (el `503` de `en_alta` y el permanente de un centro eliminado).

**`OPEN-BO-15` está resuelta desde el 2026-09-11 por `ADR-048`** (contrato síncrono `TenantProvisioner`, dos métodos, sin evento), que al evaluarla sobre el código encontró además que **la clonación de §5.6 incumplía `INV-007`** igual que el alta, sin que nadie lo hubiera nombrado (§5.6.2). De lo que queda, **sólo `OPEN-BO-14` cambia el comportamiento del código** —`200` frente a `202`— y por tanto es la única que conviene resolver antes de que `implementer` toque nada. `OPEN-BO-16` se puede responder mientras se implementa sin rehacer trabajo.

> **¿Se aprueba esta especificación de `1.6b` antes de pasar a implementación?**

**Cierre (2026-09-11/15).** Aprobada e implementada (`feat/REQ-BO-001-ciclo-de-vida-tenants`, PR [#204](https://github.com/pirexia/plataforma-educativa/pull/204)). Una pasada de revisión independiente encontró `ADR-048` pendiente de ratificar (resuelto: ratificada, §11 puntos 2/3 cerrados), un hallazgo Alta (reautenticación ausente en aprobación/rechazo de doble autorización) y varios Baja documentados; issues #196/#199-#203 abiertos en la revisión. El Alta y las incidencias Media (#196, #200) se corrigieron antes de mezclar.

Una segunda pasada, esta vez con `codex-plugin-cc` (`ADR-049`, prueba acotada) como segunda opinión sobre el PR ya mezclado, encontró tres condiciones de carrera reales por lectura-sin-bloqueo en escrituras concurrentes (issues #205-#207, Media) más una cuarta ya Crítica al generalizar el patrón (#209) y una quinta relacionada (#210): todas corregidas con `lockForUpdate()` y re-verificación de estado dentro de la transacción, con test de regresión que falla sin el fix para cada una (`fix/REQ-BO-001-condiciones-de-carrera`, PR [#212](https://github.com/pirexia/plataforma-educativa/pull/212)). Detalle completo en `docs/historial/1.6b-ciclo-vida-tenants.md` y `CHANGELOG.md`.

Quedan documentados y diferidos, sin corregir a propósito por severidad Baja o por no descarrilar el objetivo (§5 de `CLAUDE.md`): #199 (agotamiento intermitente de conexiones en la suite completa, nunca reproducido en CI, ajeno a este sub-paso), #201, #202, #203, #211, y la falta de `lock_timeout`/`statement_timeout` en `pgsql`/`pgsql_platform` (deuda sistémica, sin issue numerado todavía).

### 15.3 Sub-paso `1.6c` · matriz de módulos — **especificada y aprobada, implementada, en revisión independiente antes de mezclar**

Esta pasada especifica `REQ-BO-002` completo y los tres derivados de `ADR-045 §11` a la altura que hace falta para implementarlos, sobre el chasis de `1.6` y el ciclo de vida de `1.6b`. **No parte de cero**: §5.8 ya describía el flujo y §13.3 ya tenía dieciséis criterios. Lo que trae, y dónde está:

| # | Qué | Dónde |
|---|---|---|
| 1 | **Ninguno de los dieciséis criterios de `§13.3` está satisfecho hoy**, verificado sobre `develop` en `91adac6` — al contrario de lo razonable de suponer. Lo que existe es el **precedente** de cada uno, no el criterio | §5.8.1, §13.3.1 |
| 2 | **Dos tests existentes cambian de conexión** con el `REVOKE INSERT`, no uno: `ModuleSubscriptionsSchemaTest` —ya avisado— y **`SyncModuleRegistryTest`, que no estaba nombrado en ninguna parte** | §5.8.1 |
| 3 | **El contrato con `REQ-CORE`**, con la forma de `ADR-048`: `ModuleCatalog` (lectura, la consume también `EloquentModuleAvailability`) y `ModuleContracting` (escritura, en **dos fases**) | §5.8.2, `OPEN-BO-18` |
| 4 | **Por qué la escritura son dos fases y no una**: lo fuerza `ADR-046 §6.4` —prohibido tenant activo dentro de un bloque de plataforma—, y la invalidación necesita el prefijo del tenant | §5.8.2, §5.8.6, `RN-BO-75` |
| 5 | **Qué pasa si falla la invalidación o el evento**, que es la pregunta que nadie hace hasta que pasa: la petición **no falla**, el TTL de 300 s es el peor caso acotado, y la **carrera residual queda declarada** en vez de escondida | §5.8.6, `RN-BO-76`, `CA-BO-139` |
| 6 | **El bloqueo va sobre la fila de `tenants`, no sobre `module_subscriptions`**: contratar crea filas que aún no existen y no se puede bloquear lo que no está. Aplicado desde el principio, con el patrón de los issues #205/#207/#209 | §5.8.5, `RN-BO-66`, `CA-BO-132` |
| 7 | **Una tercera validación que aborta el despliegue** y que `ADR-045` no escribe: un módulo esencial no puede depender de uno no esencial, porque **ninguna escritura podría protegerlo** | §5.8.3, `RN-BO-64`, `CA-BO-129` |
| 8 | **Dos escrituras que el backoffice no hace aunque lo parezcan**: `created_by`/`updated_by` —referencias a `users` del centro, y un `platform_admin` no lo es— y la fila de `audit_logs`, que no existe y es correcto | §5.8.5, `RN-BO-73`, `RN-BO-74` |
| 9 | **La masiva, en detalle**: una transacción por centro en orden de `id`, el fallo de uno no aborta el lote, un lote no mezcla direcciones, y la aprobación **encola** en vez de ejecutar | §5.8.7, `RN-BO-78` a `RN-BO-81` |
| 10 | **Veinte reglas nuevas** (`RN-BO-63` a `RN-BO-82`) y **veintiún criterios nuevos** (`CA-BO-128` a `CA-BO-148`) | §7.3.1, §13.3.1 |
| 11 | **Una frase de esta especificación corregida en su redacción**, no en su decisión: §9 decía «`REQ-BO` no escribe `module_subscriptions` directamente» de forma más ancha que lo que `ADR-045` decide, y chocaba en apariencia con §5.6.2 y con `CloneTenant`, ya mezclado | §9, §5.8.2 |

**Y tres preguntas que no resuelvo yo**, ninguna de las cuales bloquea la implementación del resto:

| Pregunta | Recomendación | Qué cambia si se decide lo contrario |
|---|---|---|
| `OPEN-BO-17` · ¿Reautenticación y/o doble autorización en la descontratación **individual**? | Reautenticación **sí**, doble autorización **no** | Una fila de `api.md §4` y una celda de `permisos.md §4.4`. **Ningún criterio de aceptación cambia de signo** |
| `OPEN-BO-18` · ¿Se amplía la superficie pública de `REQ-CORE`? | **Sí**, acotada y enumerada, **sin ADR nuevo**: `ADR-045 §4.5`/`§4.8` ya decidió el mecanismo, que es justo lo que a `ADR-048` le faltaba | Sólo dónde se *commitea* |
| `OPEN-BO-19` · ¿Cierra el `GRANT SELECT` por columnas para que el centro no lea `reason`? | **Sí** | `RN-BO-82` pasa de privilegio a proyección, `CA-BO-147` se retira, y la garantía queda **más débil**. Es la única de las tres con consecuencia de seguridad |

**`OPEN-BO-19` es la que conviene resolver antes de que `implementer` toque nada**, porque cambia una migración ya especificada (`datos.md §7`) y alcanza a dos *endpoints* de `REQ-CORE` en producción. `OPEN-BO-17` y `OPEN-BO-18` se pueden responder mientras se implementa sin rehacer trabajo. **`OPEN-BO-04` y `OPEN-BO-06` siguen abiertas y siguen sin bloquear**: la primera es un ADR que formalice el cierre de `ADR-036` —`1.6c` no añade ni una fila a `admin_action_logs` que la afecte— y la segunda es la retención, que no condiciona ninguna escritura de este sub-paso.

> **¿Se aprueba esta especificación de `1.6c` antes de pasar a implementación?** — **Sí, aprobada 2026-09-15.** Las tres preguntas se resuelven como recomendaba `spec-writer`: `OPEN-BO-17` (reautenticación sí, doble autorización no), `OPEN-BO-18` (sí, superficie acotada sin ADR nuevo) y `OPEN-BO-19` (sí, se cierra el `GRANT` por columnas — decisión explícita del usuario, es la única con consecuencia de seguridad).

### 15.3.1 Cierre de implementación (2026-09-15/16)

Implementado en la rama `feature/REQ-BO-002-matriz-modulos-spec`. `ModuleCatalog`/`ModuleContracting` viven en `Core\Domain`, implementados por `DeclaredModuleCatalog` (`Core\Infrastructure`) y `ModuleContractingService` (`Core\Application`), exactamente la lista cerrada de `OPEN-BO-18`. `ModuleSubscriptionsService` (`Backoffice\Application`) orquesta capacidades, reautenticación, doble autorización e `Idempotency-Key`. Migraciones de privilegios en `database/migrations/2026_09_15_100100_harden_module_subscriptions_platform_grants.php` y `2026_09_16_100000_harden_module_subscriptions_platform_grants_delete.php` (`REVOKE`/`GRANT` de `datos.md §7`, `§7.7` con `OPEN-BO-19` aplicada). Verificado, no estimado: 673/673 Pest en la suite completa, Pint (799 ficheros) y Larastan (642 análisis) limpios.

**Revisión independiente en dos pasadas** (`db-reviewer`/`security-reviewer`/`doc-reviewer`), más dos pasos de prueba de Codex (`ADR-049 §8`, detalle en `memory.md`). Doce issues en total, todos cerrados:

| # | Severidad | Qué | Quién lo encontró |
|---|---|---|---|
| [#215](https://github.com/pirexia/plataforma-educativa/issues/215) | Alta | `TenantContext::runAsPlatform(BackofficeEscritura)` enmascaraba la excepción de negocio del `callback()` con su propio `RuntimeException` de cierre cuando el bloque terminaba sin escribir en `admin_action_logs` — cierto también cuando el motivo era una excepción legítima (esencial, retirado, dependencias sin confirmar), no sólo un olvido | Propio, al implementar |
| [#216](https://github.com/pirexia/plataforma-educativa/issues/216) | Alta | `RequireIdempotencyKey`/`IdempotencyKey` son de tenant; el backoffice nunca tiene tenant activo. Se creó la versión de plataforma (`platform_idempotency_keys`, `PlatformIdempotencyKey`, `RequirePlatformIdempotencyKey`) | Propio, al implementar |
| — | Alta | La primera corrección de #215 dejaba de invocar `after()` en el camino de excepción, revirtiendo `ADR-046 §6.5` ("también si el callback lanzó") sin ADR nuevo, y reutilizaba `CA-BO-028` para un significado contrario. Corregido sin reabrir el ADR: `after()` se sigue llamando siempre; su excepción se reporta con `report()` pero nunca sustituye a la del `callback()` | `doc-reviewer` |
| [#218](https://github.com/pirexia/plataforma-educativa/issues/218) | Media | En el camino de éxito, si `after()` lanzaba, `platformMode` quedaba sin restaurar (mismo tramo, ahora en `try`/`finally`) | `security-reviewer` |
| [#219](https://github.com/pirexia/plataforma-educativa/issues/219) | Baja | `CloneTenant::cloneModuleSubscriptions()` escribe por `pgsql_platform` (`BYPASSRLS`) sin `runAsPlatform()` — la RLS queda inerte, solo protege el *scope* de Eloquent. Documentado sin corregir | `security-reviewer` |
| [#220](https://github.com/pirexia/plataforma-educativa/issues/220) | Alta | Faltaba `REVOKE DELETE` en `module_subscriptions` para `plataforma_app` — única migración de endurecimiento del repositorio que no lo hacía | `db-reviewer` |
| [#221](https://github.com/pirexia/plataforma-educativa/issues/221) | Media | Faltaba `PurgePlatformIdempotencyKeys`, que el docblock de la migración de `platform_idempotency_keys` daba por existente | `db-reviewer` |
| [#222](https://github.com/pirexia/plataforma-educativa/issues/222) | Baja | `deleted_at` de más en el `GRANT UPDATE` de `module_subscriptions`, sin camino de escritura que lo alcance. Documentado sin corregir | `db-reviewer` |
| [#223](https://github.com/pirexia/plataforma-educativa/issues/223) | Media | Faltaba un test *Feature* de extremo a extremo del `PATCH` de ajustes por el camino real de Eloquent (la cobertura existente escribía por `DB::table()` crudo) | `db-reviewer` |
| [#224](https://github.com/pirexia/plataforma-educativa/issues/224) | Alta | `RunModuleRollout::dispatch()` corría dentro de la transacción de `DualAuthorizationService::execute()`, con las tres conexiones de cola en `after_commit => false`: si el `forceFill()->save()` o el `record()` posteriores revertían, el lote de descontratación masiva ya encolado se ejecutaba igual pese a que la autorización quedara `Fallida`. Corregido con `DB::connection('pgsql_platform')->afterCommit(...)`, mismo patrón que `RevokeTenantSessions` | `/codex:review`, segundo paso de prueba de `ADR-049` |
| [#225](https://github.com/pirexia/plataforma-educativa/issues/225) | Media | `RunModuleRollout` contaba un tenant sin cambios reales (no-operación, `RN-BO-69`) como `applied` en el resumen del lote, exagerando cuántos centros cambiaron de verdad en un reintento. Se cuenta aparte (`unchanged`) | `/codex:review`, mismo paso |
| [#227](https://github.com/pirexia/plataforma-educativa/issues/227) | Media | Los arreglos de #224/#225 no tenían test de regresión propio | `db-reviewer`/`security-reviewer`, pasada final |

**Dos issues abiertos por error, corregidos y cerrados en la propia sesión**: [#217](https://github.com/pirexia/plataforma-educativa/issues/217) (creí que `updated_by` sobraba en un `GRANT UPDATE`; `db-reviewer` verificó que sí se escribe, por `PATCH /module-subscriptions/{publicId}` vía `RecordsAuthorship`) y [#226](https://github.com/pirexia/plataforma-educativa/issues/226) (13 tests fallando en ejecución conjunta que parecían un problema de limpieza no acotada en un `afterEach`; era en realidad un *deadlock* real de PostgreSQL por dos *worktrees* ejecutando la suite completa a la vez contra la misma base de test compartida — lección de infraestructura, no bug de código, trasladada a `memory.md`).

Ninguno de los hallazgos cambia una regla de negocio de `REQ-BO-002`; varios corrigen infraestructura compartida (`App\Support\Tenancy`) fuera del ámbito de este sub-paso, sin necesitar ADR nuevo en ningún caso.

### 15.4 Sub-paso `1.6d` · salud y métricas de plataforma — **especificada, aprobada e implementada, en revisión independiente antes de mezclar**

Esta pasada especifica `REQ-BO-004` y `REQ-BO-006` **reducidos a lo observable** —lo que `PLAN-IMPLEMENTACION.md` fija como alcance de `1.6d`— a la altura que hace falta para implementarlos. **No parte de cero**: §5.9 y §5.10 ya tenían las dos tablas de inventario. Lo que trae, y dónde está:

| # | Qué | Dónde |
|---|---|---|
| 1 | **Horizon no existe**, verificado sobre `composer.json`: §5.9 decía que los trabajos en cola salen «de Horizon» y **es falso**. El observatorio de colas de `1.6d` son dos tablas del *driver* `database`. Misma clase de corrección que la premisa falsa sobre `sessions` de §0 punto 2 | §5.9, §5.9.1, `OPEN-BO-23` |
| 2 | **Los trabajos que despacha el backoffice llevan `payload.tenant_id` nulo**, porque `ADR-046 §6.4` prohíbe tenant activo en un bloque de plataforma. Luego **no aparecen en la lista de trabajos del centro**, y §5.3.5 y `operacion.md §8` afirmaban lo contrario | §5.9.3, `RN-BO-90`, `OPEN-BO-20` |
| 3 | **El reintento no puede apoyarse en el mecanismo del framework**: `plataforma_app` tiene `REVOKE SELECT, UPDATE, DELETE` sobre `failed_jobs` desde `0.7` y el proveedor del framework apunta a esa conexión. Todo el camino corre por `pgsql_platform` | §5.9.4, `RN-BO-86`, `CA-BO-158` |
| 4 | **El *payload* se reencola literal y nunca se recompone**, porque dentro viaja el `tenant_id` de `ADR-033 §8`. Recomponerlo produciría un trabajo corriendo **sin filtro de RLS**, sin síntoma inmediato | `RN-BO-85`, `CA-BO-152` |
| 5 | **La ficha no devuelve el *payload* ni la traza**: un *payload* serializado lleva datos personales del centro y `RN-BO-33` lo prohíbe. El **mensaje** de la excepción es el borde, y no lo decido yo | §5.9.5, `RN-BO-84`, `OPEN-BO-22` |
| 6 | **Las tres definiciones de métrica, sin ambigüedad**: el recuento por estado **incluye los borrados lógicos** —si no, `eliminado` sería siempre cero—, las altas salen de `from_status IS NULL` y **las bajas no se suman a las eliminaciones** | §5.10.1, `RN-BO-91`, `RN-BO-92` |
| 7 | **La adopción se construye sobre el catálogo declarado**, no sobre las filas: un módulo con cero contrataciones tiene que verse, y un `essential` no puede devolver `0` | §5.10.2, `RN-BO-93` |
| 8 | **El modo de fallo de este sub-paso es silencioso**: un agregado fuera del bloque de plataforma no falla, devuelve el recuento de un solo centro. Por eso su test de aislamiento usa **tres** centros y comprueba el total | §5.10.3, `RN-BO-94`, `CA-BO-162` |
| 9 | **Dieciséis reglas nuevas** (`RN-BO-83` a `RN-BO-98`) y **dieciocho criterios nuevos** (`CA-BO-149` a `CA-BO-166`) — las dos últimas de cada serie, `RN-BO-98` y `CA-BO-166`, las trae la decisión 5 | §7.6, §13.9 |
| 10 | **Ninguna tabla, columna, migración, índice ni trabajo en cola nuevo**, y el vocabulario que necesita (`job.reintentado`) **ya está en el `CHECK` desplegado**. Es el único de los cinco sub-pasos sin una sola migración. **Sí añade una tarea programada**, `bo:purge-failed-jobs`, y sólo por la decisión 5 de abajo | `datos.md §14`, `operacion.md §6.1`/`§6.2` |
| 11 | **Tres capacidades nuevas en el `enum` y ninguna decisión de reparto nueva**: `salud.leer`, `job.reintentar` y `metrica.leer` están en `permisos.md §3` y `§4` desde el chasis; lo que faltaba era el *endpoint* que las justifica | `permisos.md §4.5`, `CA-BO-163` |
| 12 | **El arreglo del hallazgo Alta, que entra en alcance por decisión del usuario**: `bo:purge-failed-jobs`, comando propio por `pgsql_platform`, que **sustituye** a `queue:prune-failed` en `routes/console.php` y aplica por fin la retención de 24 horas del issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73). **Ejecuta el `DELETE` él mismo en vez de encolarlo**, a diferencia de las otras dos purgas del módulo, y el motivo está escrito para que nadie lo «arregle» | §5.9.7, `RN-BO-98`, `CA-BO-166` |

**Y tres hallazgos de privilegios, anteriores a este sub-paso, que declaro y no arreglo de paso** (§5.9.6, `CLAUDE.md §5`, issue [#150](https://github.com/pirexia/plataforma-educativa/issues/150)). Los tres comparten causa: **el proveedor de trabajos fallidos del framework apunta a la conexión que perdió los privilegios en `0.7`**.

| Hallazgo | Severidad propuesta | ¿Entra en `1.6d`? |
|---|---|---|
| `queue:prune-failed` **lleva desde `0.7` sin borrar nada**, y es la segunda capa del issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73) — tokens de un solo uso conservados más allá de las 24 horas que se decidió conservarlos | **Alta** (mitigación de datos personales que se creyó desplegada) | **Sí, por decisión del usuario del 2026-09-16.** `bo:purge-failed-jobs`, §5.9.7 |
| `bo:retry-provisioning` no puede funcionar, y **no tiene test propio** | Media | **Sí**: lo arregla reutilizando el servicio de §5.9.4 (`CA-BO-164`) |
| `job_batches` sin endurecer, `jobs` sin endurecer y **inevitablemente** así con el *driver* `database` | Media / declarada | **No** |

**Dependencias de otros módulos: ninguna de código.** A diferencia de `1.6b` y `1.6c`, este sub-paso **no amplía la superficie pública de `REQ-CORE`** ni la de ningún otro módulo: consume `ModuleCatalog`, que `1.6c` ya declaró en `Core\Domain`, y lee tablas que ya existen. No hay `OPEN-BO` equivalente a `OPEN-BO-15`/`OPEN-BO-18` que plantear. **Lo que sí toca fuera del módulo es `routes/console.php` y la documentación de otros dos módulos**, por el arreglo de la purga, y está enumerado y cerrado en §5.9.7.

> **¿Se aprueba esta especificación de `1.6d` antes de pasar a implementación?** — **Sí, aprobada 2026-09-16.** Las cinco decisiones se resuelven como recomendaba `spec-writer`:
>
> | # | Decisión | Qué cambió en el documento |
> |---|---|---|
> | 1 | **`OPEN-BO-20`** (atribución de un trabajo de plataforma a un centro) → **se deja como está**; se retoma **con ADR nuevo** cuando exista un segundo caso de uso real | Nada de contenido: `RN-BO-90` y §5.9.3 ya estaban escritos contra esa salida. Sólo se cierra la pregunta, con su disparador |
> | 2 | **`OPEN-BO-21`** (¿reautenticación en el reintento?) → **sí** | Nada: `api.md §2.10`/`§4` y `permisos.md §4.5` ya lo marcaban «Sí» |
> | 3 | **`OPEN-BO-22`** (¿el mensaje de la excepción?) → **sí, se devuelve** | Nada: el cuerpo de `api.md §2.10.2` ya lo incluía. Queda registrada como **excepción consciente a `RN-BO-33`**, con su nota obligatoria en `SECURITY.md` |
> | 4 | **`OPEN-BO-23`** (¿Horizon?) → **no por ahora**, y **se corrige `CLAUDE.md §1`** a la versión **2.5.2** (2026-09-16): la fila de colas distingue lo elegido de lo instalado, y se añade la regla general de que esa tabla dice qué está elegido y no qué está instalado | Fuera de este documento: `CLAUDE.md` |
> | 5 | **Hallazgo Alta** (`queue:prune-failed`) → **entra en el alcance de `1.6d`** | **Es la única que añade diseño**: §5.9.7 nueva, `RN-BO-98`, `CA-BO-166`, más las cinco filas de documentación de otros módulos que el cambio arrastra |
>
> **`OPEN-BO-04` y `OPEN-BO-06` siguen abiertas y siguen sin bloquear**: la primera es un ADR que formalice el cierre de `ADR-036`, y la segunda es la retención de `admin_action_logs`, que `1.6d` no toca — la única fila nueva que este sub-paso escribe ahí es la del reintento.

### 15.4.1 Cierre de implementación (2026-09-21)

Implementado en la rama `feature/REQ-BO-1.6d-salud-metricas-plataforma` (commit `c476d66`, PR [#229](https://github.com/pirexia/plataforma-educativa/pull/229)). Los cinco *endpoints*, las tres capacidades nuevas y `bo:purge-failed-jobs` tal como los fija esta especificación. **Sin ninguna migración** (`datos.md §14`). Verificado, no estimado: 705/705 Pest de la suite completa en verde, Pint y Larastan (0 errores) limpios.

Durante la implementación, una regresión real de 39 tests ajenos (`REQ-AUTH`: bloqueo de cuenta, Google, SAML) resultó ser un problema de **entorno**, no de código: el *worktree* aislado del `implementer` tenía su propio `storage/framework/testing/saml-fake-idp/` con un par de claves distinto al que monta el contenedor de referencia `plataforma-api` (el flujo simulado de SAML cruza dos procesos —el `artisan serve` del contenedor y el proceso de Pest— y `FakeSamlKeyMaterial` asume que comparten sistema de ficheros, un supuesto que un *worktree* rompe por construcción). Sincronizar el material de firma con el del contenedor lo resolvió sin tocar una línea de `app/`. Lección de proceso para `memory.md`.

**Revisión independiente**, más un tercer paso de prueba de `/codex:review` (`ADR-049 §8`):

| # | Severidad | Qué | Quién lo encontró | Estado |
|---|---|---|---|---|
| — | P2 | `PlatformMetricsController::platform()` no validaba la forma de `occurred_at_from`/`occurred_at_to` antes de `Carbon::parse()`: una fecha mal formada producía `500` en vez de `422` | `/codex:review` | Corregido: `ShowPlatformMetricsRequest` (regla `date`), `CA-BO-160c` |
| — | P2 | `TenantHealthController::failedJobs()` no validaba `failed_at_from`/`failed_at_to`: llegaban sin comprobar a una comparación de `timestamp` en PostgreSQL, `QueryException` → `500` | `/codex:review` | Corregido: `IndexFailedJobsRequest` (regla `date`), `CA-BO-152b` |
| — | P2 | `limit=0` en la paginación de `failed-jobs` producía `has_more: true` sin filas y sin cursor siguiente (paginación irrecuperable); valores negativos llegaban a la base de datos | `/codex:review` | Corregido: `IndexFailedJobsRequest` (regla `integer|min:1|max:200`), `CA-BO-152c` |
| — | Media | `tenant_lifecycle_events` sin índice que sirva las tres consultas nuevas de `GET /metrics/platform` (*seq scan* completo, tres veces por petición) | `db-reviewer` | Declarado, no corregido: deuda de índice sin impacto con el volumen actual, migración futura `CONCURRENTLY` |
| — | Media | `failed_jobs` sin índice de expresión sobre `payload::jsonb ->> 'tenant_id'`, usado por la ficha de salud y el listado paginado | `db-reviewer` | Declarado, no corregido: mismo criterio que el anterior |
| — | Media | Las tres correcciones cruzadas a `docs/modulos/REQ-AUTH/operacion.md`, `docs/modulos/REQ-CORE/operacion.md` y `RUNBOOK.md` (que la propia especificación marcaba "obligatorio al cerrar") seguían sin aplicar, más la vigencia de `SYSADMIN.md`/`SECURITY.md`/`PRIVACY.md`/`CHANGELOG.md`/`README.md`/este mismo documento | `security-reviewer`/`doc-reviewer` | Corregido en esta misma sesión (`CLAUDE.md §6` regla 7) |
| — | Baja | `api.md §5` decía "veinticuatro" claves de error y no contaba `bo.metrics.invalid_period`, que el código sí introduce | `doc-reviewer` | Corregido: `api.md §5` |
| — | Baja | `TenantHealthController::retry()` comprueba reautenticación a mano, ya cubierta por el middleware de la ruta — código muerto, sin riesgo | `security-reviewer` | Documentado, no corregido a propósito (`CLAUDE.md §5`) |
| — | Baja | Esquema OpenAPI del reintento (`data.jobs`) menos preciso que sus hermanos, objeto vacío en vez de referenciar `PlatformTenantHealth.properties.jobs` | `doc-reviewer` | Documentado, no corregido a propósito (`CLAUDE.md §5`) |

Ningún hallazgo Crítico ni Alto en código. Ninguno cambia una regla de negocio de `REQ-BO-004`/`REQ-BO-006`. 32/32 Pest del fichero de este sub-paso (`TenantHealthAndMetricsTest.php`) en verde tras los tres arreglos de validación, suite completa reverificada en verde tras aplicarlos.
