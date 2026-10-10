# ADR-056 · Estandarización de módulos: reglas de arquitectura comprobadas por test, generador mínimo y referencias por patrón

**Estado**: **ACEPTADA** (2026-10-07). El usuario aprobó el 2026-10-07 las nueve recomendaciones de `§7` tal cual (`OPEN-056-01` a `-09`): en particular, **el generador `make:module` se difiere a un paso nuevo posterior a `1.11`** y `1.7b` implementa solo las reglas de arquitectura y la documentación (Anexo A, piezas 1-8).
**Fecha**: 2026-10-07
**Paso**: `1.7b` de `PLAN-IMPLEMENTACION.md` («Estandarización de módulos: tests de arquitectura y generador»), issue [#163](https://github.com/pirexia/plataforma-educativa/issues/163). Issue hermano [#162](https://github.com/pirexia/plataforma-educativa/issues/162) (`_PLANTILLA` contra `ADR-029`/`ADR-038`), cerrado el 2026-09-04.
**Se apoya en**: `INV-001`, `INV-002`, `INV-003`, `INV-007`, `INV-015`; `ADR-029`; `ADR-033 §10`; `ADR-034 §5`; `ADR-035`; `ADR-044 §4.2`, `§4.4`, `§4.9`, `§8`; `ADR-045`; `ADR-046`/`ADR-047`; `ADR-051 §5`; `ADR-053`; `RN-CORE-53` (régimen de excepciones de `ADR-054`)
**No sustituye** ningún ADR. **No modifica** ninguna decisión de `ADR-029`, `ADR-033`, `ADR-044` ni `ADR-051`: las convierte en comprobaciones.
**Afecta a**: `RNF-MANT-003`, `RNF-MANT-004`, `RNF-MANT-006`, `INV-015`; a los 50 módulos pendientes como regla.

---

## 1 · Contexto

`1.7b` nace del issue #163 (2026-09-04) con tres piezas por orden de valor: tests de arquitectura con Pest `arch()`, un generador `php artisan make:module` mínimo y un módulo de referencia designado en `ARCHITECTURE.md` en lugar de una plantilla de código. El issue difirió el paso hasta tener «4-5 módulos reales sobre los que observar el patrón en vez de adivinarlo».

### 1.1 Lo que el issue suponía y lo que hay (verificado contra el código en `0312224`)

| Premisa del issue o del plan | Dato verificado |
|---|---|
| «Hay cero tests de arquitectura» | **Falso hoy.** Hay comprobaciones estructurales en **ocho ficheros**: `Tenancy/IsolationBatteryTest` (#8 RLS en toda tabla con `tenant_id`; #9 `withoutGlobalScope` y herencia de `TenantModel`), `Tenancy/RunAsPlatformArchitectureTest` (`CA-PERM-092`, `CA-BO-029`), `Auth/PasswordResetTokenArchitectureTest` (único `arch()` real, `CA-AUTH-034`), `Authorization/GrantWritePathArchitectureTest` (`CA-PERM-087`), `Unit/Csv/CsvArchitectureTest` (`RN-CORE-47`), `Support/CatalogKeyRouteArchitectureTest` (`ADR-051 §5.2`), `Backoffice/PlatformRouteArchitectureTest` (`CA-BO-011`…`-015`) y `CA-BO-169` en `FeatureFlagsTest`. En el frontend, `src/{design-system,navigation,data-table}/architecture.spec.ts` y `src/roleLiterals.spec.ts`. |
| «n = 2, y los dos atípicos» (`Core` 92 ficheros, `Auth` 311) | **n = 3, y los tres atípicos.** `Auth` 311 ficheros, `Backoffice` 148, `Core` 143. **Ninguno es un módulo de negocio**: `Core` es el cimiento, y además sus entidades principales (`User`, `Role`, `Person`, `AcademicYear`, `AuditLog`, `ModuleSubscription`, `Permission`, `PermissionRole`, `IdempotencyKey`) viven en `App\Models`, **fuera del módulo**; `Auth` es el más cargado de seguridad; `Backoffice` es de plataforma (sin tenant, sin `DeclaresModuleRegistry`, `ADR-046`/`ADR-047`). El argumento del propio issue contra congelar una forma desde casos atípicos **sigue vigente con n = 3**. |
| Candidato (4) de `ADR-044 §8`: `runAsPlatform()` fuera de su lista | **Ya existe** desde 1.5 (`CA-PERM-092`) y 1.6b (`CA-BO-029`), con escáner de tokens y lista de nueve excepciones verificadas una a una. Nada que hacer en 1.7b. |
| «Todo modelo expuesto en API con `public_id`» | **La mitad URL ya la cubre** `ADR-051 §5.2` (`CatalogKeyRouteArchitectureTest`: todo parámetro de ruta es `public_id`/`publicId`/`*PublicId` o una clave de catálogo registrada). |
| Capacidades de Pest | Pest `v4.7.8`, `pest-plugin-arch` `v4.0.2`: existen `toExtend`, `toImplement`, `toOnlyBeUsedIn`, `toHaveSuffix`, `toUseTrait`, `ignoring()`. El comentario de `IsolationBatteryTest` que dice que `toExtend` «no existe en esta versión» está desfasado (`§8`). |
| Análisis estático | Larastan `v3.10.0` (PHPStan `2.2.8`), nivel 6, en CI (`composer analyse`). Su escáner de migraciones ya se demostró ciego a `TenantMigration` (issue #51). |

### 1.2 Lo que de verdad se repite entre los tres módulos (contado, no supuesto)

| Pieza | Auth | Backoffice | Core | Conclusión |
|---|:-:|:-:|:-:|---|
| `Infrastructure/<M>ServiceProvider.php`, descubierto por `ModuleServiceProviderDiscovery` | sí | sí | sí | **Se repite** |
| `loadMigrationsFrom(app_path('Modules/<M>/Database/migrations'))` | sí | sí | sí | **Se repite** |
| `Http/routes.php`, **requerido a mano** desde `routes/api-v1.php` (Core, Auth) o `routes/api.php` (Backoffice) | sí | sí | sí | **Se repite**, con una edición de fichero compartido |
| `lang/{es,en,de,fr}/<código>.php` | `auth` | `bo` | `core` | **Se repite** (el nombre del fichero no siempre es el del módulo) |
| `implements DeclaresModuleRegistry` (`moduleDescriptor()` + `declaredPermissions()`) | sí | **no** (plataforma) | sí | Se repite en todo módulo de tenant |
| Entrada en `lang/*/modules.php` (`name_key`) | sí | no | sí | Ídem |
| `Relation::enforceMorphMap([...])` en `boot()` | sí | no | sí | Solo si hay modelos `Auditable` |
| `loadViewsFrom` (correo), `commands([...])` | sí | sí | sí | **Contenido**, no esqueleto: aparece cuando hay correo o consola |
| `Event::listen` | sí | no | no | Contenido |
| `Domain/`, `Application/`, `Http/{Controllers,Requests,Resources}` | sí | sí | sí | Contenido: ningún módulo tiene una capa vacía |
| Factorías **dentro** del módulo | 0 | 0 | 0 | **No se repite.** Solo hay tres factorías en todo el repositorio (`Tenant`, `Person`, `User`, en `database/factories`); los modelos de módulo se crean en los tests con `::create()` |
| Tests **dentro** del módulo (`<M>/Tests/`) | 0 | 0 | 0 | **No se repite.** Los tests viven en `tests/Feature/<M>/` |
| Migración con `TenantMigration::tenantTable` + `$table->ulid('public_id')->unique()` | sí | n/a | sí | **Se repite** en toda tabla de tenant expuesta |

Dos conclusiones que ordenan el resto del documento:

1. **El esqueleto común real es pequeño**: un `ServiceProvider` con dos métodos de catálogo, un `routes.php`, un directorio de migraciones, cuatro ficheros de idioma y dos ediciones de ficheros compartidos. Todo lo demás es contenido.
2. **La skill `modulo-nuevo` describe una estructura que ningún módulo tiene** (`Database/` con factorías y *seeders*, `Tests/` dentro del módulo, «los cuatro ficheros» de documentación cuando son cinco). La forma escrita ya divergió de la real con tres módulos; con cincuenta divergiría más.

### 1.3 Por qué decidir ahora, y qué parte no

Las reglas de arquitectura codifican **invariantes**, no patrones: `INV-007` dice lo mismo con 3 módulos que con 53, y su comprobación no depende de cuántos módulos se hayan observado. Esa parte no tiene motivo para esperar y cada módulo nuevo sin ella es deriva posible.

El generador, en cambio, codifica **un patrón**, y el patrón de un módulo de negocio todavía no existe en el repositorio. Ver `OPEN-056-01`.

---

## 2 · Qué NO decide este ADR

- **La granularidad de los 50 módulos** (si `REQ-ALUM` y la matrícula son uno o dos *bounded contexts*). Es de cada especificación.
- **El mecanismo de auditoría de lectura** de los permisos de categoría especial (`ADR-044 §4.4`). Sin consumidor, se fija un cable trampa (`AR-09`), no un diseño.
- **La refactorización de las tres dependencias del núcleo hacia módulos** que `AR-02` registra como excepción: se abren como issues, no se arreglan en este paso.
- **Reglas de estilo** (presets de Pest `php`/`laravel`/`security`): no medidas, genéricas, solapan Pint y Larastan. Fuera.
- **Reglas de PHPStan propias**: ver `§3.1`.

---

## 3 · Decisión

### 3.1 La técnica se elige por regla, no por gusto

`arch()` no es la herramienta universal. Cada regla usa la técnica que **ve la verdad** de lo que comprueba:

| Técnica | Para qué | Por qué esa y no otra |
|---|---|---|
| **Pest `arch()`** | «El espacio de nombres X no usa / solo es usado por Y» | Analizador de la librería (ignora comentarios, resuelve `use` y nombres cualificados). Declarativo. **No sabe** comprobar presencia de ficheros ni si una excepción sigue haciendo falta |
| **Reflexión sobre clases cargadas** | Herencia, interfaces implementadas | Patrón ya probado en `IsolationBatteryTest` #9 |
| **Esquema real** (`information_schema`/`pg_catalog`, base de test migrada) | Tipos de columna, nulabilidad, índices | El texto de las migraciones miente: `TenantMigration` añade columnas que el texto no muestra, `DB::statement` escribe SQL crudo, y Larastan ya demostró no verlas (#51). El esquema es la fuente de verdad |
| **Tabla de rutas registrada** | *Middleware* y parámetros por ruta | Patrón de `ADR-051 §5.2` y `CA-BO-013` |
| **Escáner de tokens** (`token_get_all`) | Llamadas y literales concretos | Patrón de `CA-PERM-092`. **Uno solo, compartido** en `tests/Support/` (nuevo), con casos fijos de auto-comprobación: hoy hay cuatro detectores escritos a mano con criterios distintos (tokens, expresión regular, `str_contains`), y uno de ellos tuvo un agujero real (#167, operador *nullsafe*) |

**Reglas propias de PHPStan: no.** Serían más precisas para dos casos (`AR-08`, `AR-10`), pero exigen dominar la API de reglas de PHPStan, se rompen con cada versión mayor y su mantenimiento recae en una sola persona. Se reconsidera solo si un test de tokens acumula falsos positivos que obliguen a excepciones por motivo técnico y no de diseño.

### 3.2 Régimen único de excepciones

Toda regla de este ADR, y toda regla estructural futura, sigue el régimen que `RN-CORE-53` ya aplica en el frontend y `CA-PERM-092` en el backend:

1. **Una regla que nace en rojo no entra en CI.** Cada violación existente se **corrige en el paso** o se registra como **excepción**.
2. Las excepciones son una **lista cerrada y nominal dentro del propio test** (fichero, clase, ruta o tabla; nunca un patrón de carpeta), con **motivo y referencia** por entrada.
3. **La lista solo puede reducirse**: cada entrada se verifica viva; si la excepción ya no hace falta, el test falla pidiendo retirarla.
4. **Añadir una entrada** exige especificación aprobada expresamente por el usuario (`OPEN-056-02`).
5. Todo detector propio (tokens, expresiones) lleva **casos fijos** que demuestran que detecta lo que debe y no lo que no debe, y una aserción de **no vacuidad** (recorrió más de cero elementos), como `RN-CORE-47`.
6. Todas en el grupo `arch`; el nombre del test lleva el ID de regla de este ADR y el invariante o ADR que comprueba (`INV-015`).

### 3.3 Catálogo de reglas

Violaciones medidas el 2026-10-07 sobre `0312224`: dependencias por escáner de tokens sobre `app/` (`T_NAME_QUALIFIED`/`T_NAME_FULLY_QUALIFIED`, equivalente a lo que analiza `arch()`), esquema sobre `plataforma_test` migrada, rutas con `php artisan route:list --json`. **No se ejecutaron como tests Pest**: el intento de correr `arch()` fuera del árbol del repositorio falló por entorno (el mismo test de control existente también fallaba allí), y escribir en `apps/api/tests` está fuera del ámbito de este documento. La primera tarea de la implementación es confirmarlas (`CA-056-01`).

| ID | Regla | Técnica | Violaciones hoy | Tratamiento | Prioridad |
|---|---|---|---|---|---|
| **AR-01** | `INV-007` entre módulos: un módulo solo usa de otro su `Domain` **excluido `Domain\Models`**; nunca `Application`, `Infrastructure`, `Http`, `Database` | `arch()`, un test por módulo (no por pareja) | **1**: `BackofficeServiceProvider` → `Auth\Infrastructure\Google2FaTotpVerifier` | **Se corrige**: el enlace es redundante, `AuthServiceProvider` ya enlaza las mismas dos interfaces a la misma clase | P1 |
| **AR-02** | Núcleo (`App\Support`, `App\Http`, `App\Models`, `App\Providers`) → módulos: misma frontera que AR-01 | `arch()` + comprobación de excepción viva | **3**: `EnforceSessionIdleTimeout` y `VerifySessionTenant` → `Auth\Domain\Models\UserSession`; `SyncModuleRegistry` → `Core\Infrastructure\FeatureFlagCatalogCache` | **Excepción** (refactorizar *middleware* de seguridad no es de este paso) + issue de severidad Media | P2 · `OPEN-056-03` |
| **AR-03** | `ServiceProvider` por convención: todo directorio de `app/Modules/` tiene `Infrastructure/<M>ServiceProvider.php`, extiende `ServiceProvider` y aparece en `ModuleServiceProviderDiscovery::discover()`; ninguna otra clase `*ServiceProvider` en `App\Modules`; implementa `DeclaresModuleRegistry`; si existe `Database/migrations`, su ruta está entre las del *migrator* | Sistema de ficheros + reflexión + `arch()` | 0 | 1 excepción: `Backoffice` no implementa `DeclaresModuleRegistry` (`REQ-BO/funcional.md §10`) | P1 |
| **AR-04** | `ADR-029` en el esquema: ninguna columna `character varying`, ninguna `timestamp without time zone`, ningún tipo `ENUM` de PostgreSQL; `character(n)` solo con `n = 26` (ULID) | Esquema | **0 en tablas de negocio.** En siete tablas del *framework*: `cache`, `cache_locks`, `failed_jobs` (incl. `failed_at` sin zona), `job_batches`, `jobs`, `migrations`, `sessions` | Las siete como **excepción** nominal (esquema de Laravel, no de negocio) | P1 |
| **AR-05** | Forma de `public_id`: donde exista la columna, `character(26) NOT NULL` con índice único propio | Esquema | 0 (30 tablas) | — | P3 |
| **AR-06** | `INV-003`: toda subclase de `TenantModel` o `AppendOnlyModel` implementa `Auditable` | Reflexión | 0 fuera de la lista | **6 excepciones**: `AuditLog` (es el propio rastro), `IdempotencyKey`, `LoginAttempt`, `MfaChallenge`, `SamlAuthRequest`, `SamlConsumedAssertion`, cada una con su motivo documentado en su `datos.md` | P1 |
| **AR-07a** | `INV-002`: toda ruta de `api/v1` lleva `permission:` o está en la lista cerrada | Tabla de rutas | 33 rutas sin `permission:` hoy, todas de autoservicio o públicas: 27 de `auth/*`, `me` ×3, `tenant/branding`, `feature-flags`, `data-exports/{publicId}` (autoriza por `kind` en el controlador) | Las 33 como **excepción** nominal por nombre de ruta, calculadas en entorno de test | P1 · `OPEN-056-04` |
| **AR-07b** | `RMOD-009`, `ADR-044 §4.9`: toda ruta de un módulo **no esencial** lleva `module-enabled:<su código>` **antes** de `permission:` | Tabla de rutas + catálogo | 0 (hoy los dos módulos con rutas de tenant son esenciales) | Cuantificación universal: muerde con el primer módulo activable, que es cuando debe | P1 |
| **AR-08** | `ADR-044 §8` (1), control de acceso por código de rol: `App\Models\Role` solo se usa en `App\Modules\Core`, `App\Support\Authorization`, `App\Models`, `App\Providers`; y los literales `'administrador_centro'`/`'soporte_plataforma'` solo en lista | `arch()` `toOnlyBeUsedIn` + escáner de tokens | `Role` en 5 ficheros de `Auth` (`roles.mfa_required`, `REQ-AUTH-003`: atributo del rol, no control de acceso); literal en 6 ficheros, todos invariantes de negocio o siembra (`SchoolAdministratorGuard`, `ReplaceUserRoles`, `UsersController`, `ProvisionTenantDefaults`, `GrantRoleAdministrationCommand`, `GrantLockoutPermissionsCommand`) | **Excepciones** nominales | P2 · `OPEN-056-05` |
| **AR-09** | `ADR-044 §8` (2), evento `read` en todo permiso `is_special_category`: **cable trampa** — ningún permiso declarado tiene `is_special_category = true` | Catálogo (`declaredPermissions()` de todos los módulos) | 0 | Cuando aparezca el primero, el test falla hasta que su especificación diseñe el mecanismo y sustituya el cable por la regla real | P3 |
| **AR-10** | `ADR-044 §8` (3), consulta de recurso con ámbito restringido solo por `ScopedQuery`: todo permiso con `applicable_scopes` distinto de `['todos']` tiene su recurso en un mapa cerrado del test `recurso → {modelo, ficheros sancionados}`; ningún otro fichero de `app/` hace una llamada estática de consulta sobre ese modelo | Catálogo + escáner de tokens | 0: `auditoria` → `AuditLog` → `EloquentAuditQuery`, `GenerateAuditLogExport`, `EloquentExportRequestService` (los tres usan `ScopedQuery`) | — | P2 · `OPEN-056-09` |
| **AR-11** | Frontend: todo directorio de `src/modules/` tiene `shell.ts` y `locales/{es,en,de,fr}.json`, está registrado en `src/navigation/modules.ts` y en `src/i18n/index.ts`, y solo importa de otro módulo su superficie pública (`api/`, `types/`, `shell`) | Vitest, patrón de `navigation/architecture.spec.ts` | 0 (`auth` importa `@/modules/core/api`, pública) | — | P2 |
| **AR-12** | `INV-009`: todo `lang/es/*.php` tiene gemelo en `en`, `de` y `fr` con exactamente las mismas claves y ningún literal vacío | Sistema de ficheros | 0 (nueve ficheros; paridad comprobada clave a clave) | Generaliza `CA-AUTH-233` y `CA-BO-073`, que hoy cubren solo `auth.php` y `bo.php`: `core.php`, `modules.php`, `roles.php`, `scopes.php` y otros cinco no tienen test de paridad | P1 |
| — | `runAsPlatform()` fuera de lista; RLS en toda tabla con `tenant_id`; `TenantModel`; `withoutGlobalScope`; parámetros de ruta | Ya existen | — | **Sin cambios** | — |

**Por qué AR-01 excluye `Domain\Models`.** La skill `modulo-nuevo` ya prohíbe «consultar directamente tablas de otro módulo», y un modelo Eloquent de otro módulo es exactamente eso. Hoy ningún módulo usa el `Domain\Models` de otro, así que la regla nace en verde. Su coste aparecerá con los módulos de negocio (una matrícula querrá un `belongsTo` hacia el alumno): la clave foránea en base de datos sigue permitida; el modelo ajeno no, y la lectura va por interfaz de `Domain`. Es lo que `INV-007` exige, no una restricción nueva, pero conviene que esté dicho antes del primer caso.

**Por qué AR-08 no usa los dieciséis códigos de rol.** `'direccion'` es también «dirección postal», `'tutor_legal'` será un tipo de parentesco en `REQ-FAM-UNIT` y `'docente'`/`'estudiante'` son vocabulario del dominio. Prohibir esos literales en el *backend* produce falsos positivos seguros en los módulos de personas. Se vigilan solo los dos códigos sin ambigüedad, y el grueso de la protección lo da el confinamiento de la clase `Role`: un módulo de negocio que necesite saber algo de roles (por ejemplo, comunicaciones dirigidas a un rol) lo pide por una interfaz de `Core\Domain`, que es donde `INV-007` lo pone de todas formas.

**Por qué AR-10 es honesto solo a medias.** El escáner ve `AuditLog::query()`, no un acceso por relación (`$user->auditLogs()`) ni `DB::table('audit_logs')`. Son falsos negativos, no positivos: la regla atrapa el error común y no da una garantía que no tiene. `ADR-044 §4.2` ya lo llamó «la mayor deuda estructural» y la compensa con tres piezas; esta es la segunda, y la tercera —criterio de aceptación de acceso denegado en listado **y** en detalle por recurso— sigue siendo obligatoria en cada especificación.

### 3.4 Lo que NO se automatiza, con el dato que lo justifica

| Candidato | Por qué no |
|---|---|
| Pureza de capas dentro de un módulo (`Domain` sin Eloquent, `Application` sin `Illuminate\Http`) | `Domain\Models` es Eloquent por convención en los tres módulos, y 8 ficheros de `Application` usan `Illuminate\Http` (flujos de sesión de `Auth`, subida de marca de `Core`). La regla nacería con una lista de excepciones que crecería con cada módulo: sería documentar la convención real como si fuera una violación |
| Registro en el *morph map* de todo modelo `Auditable` | Se autodetecta: `enforceMorphMap()` falla en el primer test que cree el modelo |
| `routes.php` incluido desde `routes/api-v1.php` | Se autodetecta: los tests de los propios *endpoints* responden 404 |
| Factoría por modelo | Dato de `§1.2`: no es la práctica del repositorio. `REQ-SEED` (1.15b) decidirá las suyas |
| Presencia del test HTTP de aislamiento por recurso | No se puede saber estáticamente qué test cubre qué ruta. Es criterio de aceptación por recurso (`ADR-044 §4.2`, `RNF-MANT-006`), comprobado en revisión |
| `public_id` en el cuerpo de las respuestas | «Expuesto» es semántico. Hoy ningún `Resource` emite la clave interna (comprobado); la URL ya la cubre `ADR-051 §5.2` |
| Importes en céntimos enteros (`ADR-029`) | La base de datos no sabe qué columna es un importe; `numeric` es legítimo para una calificación |
| Prohibir `time without time zone` | Una franja horaria de un horario (1.12) es una hora de reloj, no una marca de tiempo; `ADR-029` habla de marcas de tiempo |
| Presets de Pest (`php`, `laravel`, `security`) | No medidos, genéricos, solapan Pint/Larastan |

### 3.5 Generador `php artisan make:module`

**Momento: `OPEN-056-01`.** La recomendación es **diferirlo** a un paso propio después de `1.11`, y que `1.7b` entregue reglas, referencias y documentación. Motivos, con los datos de `§1`:

- Ningún módulo del repositorio es de negocio. Un generador escrito hoy congela la forma de tres casos atípicos, que es lo que #163 argumentó en contra.
- Las reglas de `§3.3` atrapan las omisiones que el generador evitaría (`ServiceProvider`, tipos, `Auditable`, `permission:`, `module-enabled:`). Lo que el generador añade sobre ellas es **tiempo ahorrado**: unos siete ficheros pequeños por módulo.
- `1.10` (`REQ-CURSO`) y `1.11` (`REQ-ACAD`) son los dos primeros módulos de negocio. Construirlos a mano, con la skill reescrita y las reglas en CI, es la observación que el generador necesita.

Lo que sigue es la especificación del generador **para cuando se construya**, ahora o tras `1.11`. Si se difiere, el paso que lo construya la revalida contra `REQ-CURSO`/`REQ-ACAD` antes de implementarla.

**Firma**: `php artisan make:module <Nombre> --code=<codigo> --name-es=… --name-en=… --name-de=… --name-fr=… [--entity=<Entidad> --audit-policy=<full|selective|redacted>]`

**Sin `--entity`** (esqueleto mínimo, solo lo que `§1.2` mide como repetido):

1. `app/Modules/<Nombre>/Infrastructure/<Nombre>ServiceProvider.php`: `register()` vacío; `boot()` con `loadMigrationsFrom`; `implements DeclaresModuleRegistry` con `moduleDescriptor()` (`code`, `name_key = modules.<codigo>`, `phase`, `depends_on = []`, `essential = false`) y `declaredPermissions()` devolviendo `[]`.
2. `app/Modules/<Nombre>/Http/routes.php`: grupo con `module-enabled:<codigo>` y ninguna ruta.
3. `app/Modules/<Nombre>/Database/migrations/` (con `.gitkeep`).
4. `lang/{es,en,de,fr}/<codigo>.php` con un arreglo vacío.
5. `tests/Feature/<Nombre>/ModuleRegistrationTest.php`: el `ServiceProvider` se descubre, el descriptor declara su código, `essential` es falso.
6. **Dos ediciones de ficheros compartidos**, idempotentes: una línea `require` en `routes/api-v1.php` y la entrada `'<codigo>' => '<nombre>'` en `lang/{es,en,de,fr}/modules.php`. Los cuatro nombres son **opciones obligatorias** del comando (`--name-es`, `--name-en`, `--name-de`, `--name-fr`): un nombre sin traducir copiado a los cuatro ficheros pasaría la paridad de claves de `AR-12` e incumpliría `INV-009` en silencio.

**No genera** `Domain/`, `Application/` ni `Http/Controllers` vacíos (punto 3 de #163: capas vacías para siempre), ni factoría, ni *seeder*, ni el `datos.md`/`api.md` del módulo (eso es de `spec-writer`).

**Con `--entity=<Entidad>`** (`OPEN-056-06`):

7. Migración con `TenantMigration::tenantTable('<tabla>', …)` y `$table->ulid('public_id')->unique()`, y `down()` que borra la tabla. Ninguna columna de negocio.
8. `Domain/Models/<Entidad>.php`: `extends TenantModel`, `use HasPublicId`, `implements Auditable`.
9. Entrada en `Relation::enforceMorphMap` del `boot()`.
10. `database/factories/<Nombre>/<Entidad>Factory.php` (fuera del espacio `App`, donde ya están las tres que existen y donde `REQ-SEED` podrá usarlas sin violar `AR-01`/`AR-02`).
11. `tests/Feature/<Nombre>/<Entidad>TenantIsolationTest.php`, **a nivel de modelo**: una fila creada en el tenant B no es visible desde A ni por Eloquent ni por SQL crudo, `find` por su `public_id` devuelve nulo, y una inserción con el `tenant_id` de B desde A la rechaza `WITH CHECK`. Pasa nada más generarse. **No sustituye** el test HTTP de aislamiento en listado y detalle, que el implementador escribe con el *endpoint*.

**`--audit-policy` sin valor por defecto.** `ADR-035` clasifica por modelo y falla en cerrado. Un valor por defecto `full` copiaría en `audit_logs` los datos personales de la primera entidad de personas que alguien genere sin pensar; uno por defecto `redacted` escondería el problema. El generador no decide: exige la opción.

**No genera HTTP** (controlador, *request*, *resource*, rutas CRUD): eso es la plantilla maximalista que #163 descartó, y cada recurso decide paginación, filtros, ámbito y exportación por `ADR-038`/`ADR-054`.

**Parte web: no se genera (`OPEN-056-07`).** El contenedor `plataforma-api` monta solo `apps/api` en `/var/www/html`: un comando `artisan` no puede escribir en `apps/web` en el entorno de desarrollo. La parte web son siete ficheros triviales (`shell.ts` con tres listas vacías, cuatro `locales/*.json`, `api/index.ts`, `types/index.ts`) más dos registros, y `AR-11` falla si falta cualquiera de ellos. La skill lo describe.

**Cómo se prueba que lo generado cumple** (`CA-056-34`, `-35`): un *job* de CI que genera un módulo de prueba con y sin `--entity`, ejecuta `php -l` sobre lo generado, migra y revierte, corre el grupo `arch`, los tests generados y `composer analyse`, y lo descarta. Es la única prueba de extremo a extremo: un test unitario del comando con ficheros de referencia comprobaría el texto, no que el resultado cumpla las reglas, y costaría mantenimiento en cada cambio de plantilla.

### 3.6 Módulo de referencia: por patrón, no por módulo

Ninguno de los tres módulos es representativo entero (`§1.1`). Designar «el módulo de referencia» sería designar un caso atípico. **Se designa una referencia por patrón**, apuntando a ficheros concretos, en una sección nueva `ARCHITECTURE.md §3.4`:

| Patrón | Referencia | Lo fija |
|---|---|---|
| Declaración de módulo y permisos con `applicable_scopes` explícito por entrada | `AuthServiceProvider::declaredPermissions()` (lista literal, más legible para un módulo nuevo que el bucle de `Core`) | `PermissionCatalogTest`, `SyncModuleRegistryTest` |
| Listado + detalle + exportación de un recurso con ámbito restringido | Auditoría de `Core`: `AuditLogsController`, `EloquentAuditQuery`, `AuditoriaPropiosScopeResolver`, `GenerateAuditLogExport` | `AuditoriaScopeTest`, `AR-10` |
| Recurso de tenant con `public_id`, `Auditable` y 404 entre tenants | Invitaciones de `Core` (`UserInvitation`, `InvitationsController`) | Sus CA de `REQ-CORE` |
| Tarea en cola por tenant (`INV-012`) | `Core\Infrastructure\Jobs\GenerateUserExport` | `UserExportEndpointsTest` |
| Interfaz pública consumida por otro módulo | `Core\Domain\TenantSettingsReader` (consumida por `Auth`) | `AR-01` |
| Evento de dominio entre módulos | `Core\Domain\Events\UserDeactivated` → `Auth` (`RevokeSessionsOnUserDeactivated`) | Sus tests de `REQ-AUTH` |
| Migración de tabla de tenant | `create_user_invitations_table` de `Core` | `AR-04`, `AR-05`, `IsolationBatteryTest` #8 |
| Módulo de frontend | `src/modules/core` (`shell.ts`, tabla de datos) | `AR-11`, `navigation/architecture.spec.ts` |
| **Antirreferencia** para módulos de tenant | `Backoffice`: de plataforma, sin tenant, sin catálogo | — |

**Cómo no se pudre**:

1. Cada entrada apunta a código que **está bajo test**; una referencia que deja de existir rompe algo antes que el documento.
2. Ficheros y clases, nunca números de línea.
3. `doc-reviewer` gana un punto de comprobación: toda ruta citada en `ARCHITECTURE.md §3.4` existe. No se automatiza: `ARCHITECTURE.md` está en la raíz del repositorio, fuera del montaje del contenedor de la API, y un test que solo pase en CI es peor que una comprobación de revisión que pasa siempre.
4. **Disparador de revisión**: al cerrar `1.11`, la tabla se revisa y el primer módulo de negocio sustituye a `Core` en las filas donde sea más representativo.

### 3.7 `docs/modulos/_PLANTILLA` y la skill `modulo-nuevo`

**`_PLANTILLA` se conserva**: es documentación, no código, y la usan `spec-writer` (`.claude/agents/spec-writer.md`) y `doc-reviewer` (comprobaciones 1 y 11). Pero **vuelve a estar desfasada** después de #162, contra ADR posteriores:

| Desfase | ADR |
|---|---|
| La línea de `public_id` del checklist de `datos.md` no menciona las claves de catálogo, y `ADR-051 §5.1` dice expresamente que «cada módulo que añada una entrada lo anota además en la línea de `public_id` de su checklist de `datos.md`» | `ADR-051 §5.1` — **contradicción directa** |
| `permisos.md` no pide `applicable_scopes` por permiso ni el resolutor de ámbito de cada entidad propia; `funcional.md` no pide el criterio de aceptación de acceso denegado en listado y en detalle por recurso con ámbito restringido | `ADR-044 §4.1`, `§4.2` |
| `datos.md` no pide la política de valor de auditoría por modelo ni el alias del *morph map* | `ADR-035` |
| Ninguna sección para el *shell* del frontend (rutas, `meta.permissions`, navegación, bloques del panel) | `ADR-053` |
| `api.md` no recoge las normas de exportación (paridad de filtros, sin `q`, CSV como contrato técnico) | `ADR-054`, `ADR-055` |
| `funcional.md` no pide los campos del descriptor (`essential`, `depends_on`) | `ADR-045` |
| `operacion.md` no menciona `platform:sync-registry` en el despliegue | `ADR-034 §5` |

**Decisión**: `1.7b` la actualiza, y cada casilla del checklist que una regla de `§3.3` comprueba lo dice («lo comprueba `AR-04`»). Así queda visible qué parte de la plantilla es norma vigilada y cuál depende de revisión, y la parte vigilada no puede pudrirse sin que un test lo note.

**La skill `modulo-nuevo` se reescribe** contra la realidad de `§1.2`: tests en `tests/Feature/<M>/`, factorías en `database/factories/`, cinco ficheros de documentación, sin `Tests/` ni `Database/factories` dentro del módulo, las dos ediciones de ficheros compartidos, la parte web y las reglas `AR-*` que la vigilan. `apps/api/app/Modules/README.md` («vacío hasta el paso 1.1», sin la capa `Database/`) también.

### 3.8 Dónde vive la especificación del paso

En el **Anexo A de este ADR**, no en `docs/modulos/` ni en un documento aparte:

1. `1.7b` no es un módulo ni tiene identificador `REQ-*`: abrir los cinco ficheros de `docs/modulos/REQ-*/` para algo que no es un *bounded context* repetiría el error que `ADR-044 §4.10` evitó con `REQ-PERM`.
2. Lo que tiene que vivir y cambiar después (listas de excepciones, mapa de `AR-10`) **vive en el código de los tests**, como `ADR-051 §5.1` dispuso para su registro: un ADR es inmutable y una lista que se reduce no puede vivir en él. Lo que tiene que leerse a diario (la tabla de referencias) vive en `ARCHITECTURE.md §3.4`. Un tercer documento de especificación duplicaría ambos y sería el siguiente en pudrirse.
3. El Anexo A queda congelado al aceptarse: es el contrato que el implementador sigue al pie de la letra (`CLAUDE.md §3`).

---

## 4 · Motivo

1. **Una plantilla es una sugerencia en t = 0; un test es cumplimiento permanente** (#163). Pero un test solo vale si ve la verdad: por eso la técnica se elige por regla (`§3.1`) y el esquema se comprueba en el esquema, no en el texto de la migración.
2. **Las reglas codifican invariantes y no dependen de n**; el generador codifica un patrón y sí depende. De ahí la asimetría de calendario de `OPEN-056-01`.
3. **Todas las reglas nacen en verde** con un régimen de excepciones único que solo puede reducirse: mismo mecanismo que ya funciona en `RN-CORE-53` y `CA-PERM-092`, sin inventar uno.
4. **Ninguna regla promete más de lo que comprueba.** `AR-10` y `AR-08` dicen sus límites, y lo que no se puede automatizar sigue siendo criterio de aceptación y revisión (`§3.4`).
5. **Reversibilidad**: cada regla es un test; retirarla es borrar un fichero. Nada de esto toca el esquema, las rutas ni el comportamiento del producto, salvo dos líneas redundantes de `BackofficeServiceProvider`.

---

## 5 · Consecuencias

**Buenas**

- `INV-002`, `INV-003`, `INV-007`, `INV-009` (paridad de claves), `ADR-029` y `RMOD-009` pasan de revisión a comprobación en CI para los 50 módulos.
- La regla más probable de olvidar en un módulo nuevo —la ruta sin `permission:` o sin `module-enabled:`— falla en CI en vez de en `security-reviewer`.
- La documentación de cómo se hace un módulo deja de contradecir al código.

**Malas, y hay que asumirlas**

- **Fricción deliberada**: un módulo de negocio no puede usar el modelo Eloquent de otro (AR-01) ni la clase `Role` (AR-08); necesita una interfaz de `Domain` primero. Es lo que `INV-007` pide, pero cada caso cuesta una interfaz.
- **Excepciones de partida**: 3 (AR-02) + 1 (AR-03) + 7 tablas (AR-04) + 6 (AR-06) + 33 rutas (AR-07a) + 11 ficheros (AR-08). La de AR-07a es grande, aunque estable: son rutas de autoservicio de `Auth` y `Core`, y un módulo de negocio rara vez añadirá una.
- **Aprobación del usuario para ampliar una excepción** (`OPEN-056-02`): frena, a propósito.
- **Coste de CI**: AR-01 es un test `arch()` por módulo; con 53 módulos son 53 análisis. El tiempo del grupo `arch` se mide en la implementación y se anota (`CA-056-13`); si se vuelve un problema, AR-01 pasa al escáner de tokens compartido, que recorre `app/` una sola vez.
- **Conflictos de fusión triviales** en `routes/api-v1.php` y `lang/*/modules.php` si dos ramas crean módulos a la vez (solo con generador).

**Riesgos de falsos positivos**

| Regla | Riesgo | Mitigación |
|---|---|---|
| AR-04 | Una columna `character(2)` para un código ISO | Es violación real de `ADR-029` (`text` + `CHECK`); el test acierta |
| AR-04 | Tablas sonda que los tests crean en `plataforma_test` (`fk_probe_*`, `tenant_model_probes`…) | Se crean con `TenantMigration` y cumplen; el test no las excluye a ciegas |
| AR-07a | Rutas que solo existen en entorno de test (simuladores OIDC/SAML) | La lista se calcula en entorno de test, como la suite |
| AR-08 | Códigos de rol con homónimos de dominio | Solo se vigilan los dos sin ambigüedad (`§3.3`) |
| AR-10 | Referencias no consultivas al modelo (`::class` en el *morph map*) | Solo cuentan las llamadas estáticas de consulta |

---

## 6 · Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| Plantilla de código completa | Descartada ya por #163; los datos de `§1.2` lo refuerzan: el esqueleto común es pequeño y lo demás es contenido |
| Todas las reglas con `arch()` | No ve el esquema, ni la tabla de rutas, ni la presencia de ficheros, ni si una excepción sigue viva |
| Comprobar tipos leyendo el texto de las migraciones | Ciego a `TenantMigration` y a `DB::statement`; precedente #51 |
| Reglas propias de PHPStan desde el principio | Coste de mantenimiento en solitario desproporcionado para dos reglas (`§3.1`) |
| Un único «módulo de referencia» | Los tres son atípicos; cualquiera enseñaría decisiones accidentales como norma |
| Retirar `_PLANTILLA` | La consumen dos subagentes; el problema no es que exista, es que nadie comprueba su vigencia |
| Generador que también escriba la parte web | Imposible desde el contenedor de la API en desarrollo; siete ficheros triviales vigilados por AR-11 |
| Política de auditoría por defecto en el generador | Contradice el fallo en cerrado de `ADR-035` |

---

## 7 · Preguntas abiertas

| ID | Pregunta | Recomendación | Motivo |
|---|---|---|---|
| **`OPEN-056-01`** | ¿Se construye el generador en `1.7b` o se difiere? | **Diferir** a un paso nuevo tras `1.11`; `1.7b` entrega reglas, referencias, `_PLANTILLA` y skill | `§3.5`: n = 3 atípicos, cero módulos de negocio; las reglas ya atrapan las omisiones; el patrón real se observa en `1.10`/`1.11` |
| **`OPEN-056-02`** | ¿Quién autoriza una entrada nueva en una lista de excepciones? | **Especificación aprobada expresamente por el usuario**, como `RN-CORE-53` | Si la puede añadir el implementador, la lista crece con cada módulo y la regla se erosiona como describe `ADR-051 §6` |
| **`OPEN-056-03`** | ¿Entra AR-02 (núcleo → internos de módulos) con 3 excepciones? | **Sí**, con las 3 excepciones y un issue de severidad Media | `INV-007` habla de módulos, pero un núcleo que depende de internos de un módulo invierte la dirección que `ADR-044 §4.10` fijó; sin regla, el patrón de `SyncModuleRegistry` se repetirá |
| **`OPEN-056-04`** | ¿Entra AR-07a (`permission:` en toda ruta de `api/v1`), que #163 no proponía? | **Sí** | Es el olvido más probable de un módulo nuevo y el de mayor impacto (`INV-002`, denegar por defecto); 33 excepciones estables |
| **`OPEN-056-05`** | ¿Se confina `App\Models\Role` a `Core`/núcleo (AR-08), aun sabiendo que algún módulo futuro querrá roles? | **Sí** | Obliga a pasar por una interfaz de `Core\Domain`, que es lo que `INV-007` ya exige; sin confinamiento, el candidato (1) de `ADR-044 §8` no tiene detector fiable |
| **`OPEN-056-06`** | ¿El generador lleva `--entity`? ¿Genera capa HTTP? | **`--entity` sí, con `--audit-policy` obligatorio; capa HTTP no** | Migración, modelo, factoría y test de aislamiento de modelo son lo que se repite y lo que pasa en verde sin rellenar nada; la capa HTTP es contenido |
| **`OPEN-056-07`** | ¿El generador escribe la parte web? | **No**; AR-11 la vigila | `§3.5`: el contenedor de la API no ve `apps/web` |
| **`OPEN-056-08`** | ¿Se añade un *job* de CI que genere, compruebe y descarte un módulo? | **Sí**, si se construye el generador | Es la única prueba de que lo generado cumple las reglas y compila |
| **`OPEN-056-09`** | ¿AR-10 ahora o al llegar el segundo recurso con ámbito restringido? | **Ahora** | Nace en verde con un único recurso, cuesta poco con el escáner compartido, y el mapa del test documenta qué ficheros están sancionados |

---

## 8 · Hallazgos fuera del alcance de este ADR (reportados, no corregidos)

1. **`docs/modulos/_PLANTILLA/datos.md` contradice `ADR-051 §5.1`** (`§3.7`). La comprobación 11 de `doc-reviewer`, creada justo para esto tras #162, no lo detectó en los cierres posteriores al 2026-09-21. Severidad Media (`CLAUDE.md §6.6`).
2. **La skill `modulo-nuevo` y `apps/api/app/Modules/README.md` describen una estructura que ningún módulo tiene** (`§1.2`). Severidad Media.
3. **`tests/Feature/Tenancy/IsolationBatteryTest.php` tiene dos comentarios desfasados**: que `toExtend` no existe en la versión instalada de Pest (sí existe en `v4.7.8`) y que el test #1 de `ADR-033 §10` (aislamiento HTTP de extremo a extremo) está diferido porque `app/Modules/` está vacío. Severidad Baja.
4. **`BackofficeServiceProvider` vuelve a enlazar `MfaVerifier`/`TotpProvisioner` a la misma clase que `AuthServiceProvider`**, importando `Auth\Infrastructure` (`AR-01`). Se corrige en `1.7b`.
5. **Tres dependencias del núcleo hacia internos de módulos** (`AR-02`). Issue de severidad Media propuesto.
6. **`apps/web/src/roleLiterals.spec.ts` prohíbe los dieciséis códigos de rol como literal en todo `src/`**, incluidos `'direccion'` y `'tutor_legal'`. Producirá falsos positivos en `REQ-FAM-UNIT` (parentesco) y en cualquier formulario con dirección postal. No es un fallo hoy; es la decisión que habrá que tomar cuando llegue. Severidad Baja.
7. **La rama de este paso usa el prefijo `REQ-ARQ`, que no existe como requisito** en `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`. Los commits y los tests deben referenciar los IDs reales (`INV-007`, `RNF-MANT-003`, `RNF-MANT-006`, `ADR-056 AR-NN`, `#163`), no uno inventado.
8. `ARCHITECTURE.md §3` dibuja `<Modulo>/{Domain,Application,Infrastructure,Http}` sin `Database/`, que los tres módulos tienen. Se corrige con `§3.6`.

---

## Anexo A · Especificación del paso `1.7b`

Ejecución por `implementer` (Sonnet). Sin migraciones de esquema. Revisores: `security-reviewer` (AR-07, AR-08, AR-10 tocan autorización), `doc-reviewer` (cierre de paso), `db-reviewer` **solo** si se construye el generador (plantilla de migración).

### A.1 Inventario

| # | Pieza | Dónde |
|---|---|---|
| 1 | Escáner de tokens compartido: lista de ficheros PHP de un directorio, tokens significativos sin comentarios, detección de llamada/literal/nombre cualificado | `apps/api/tests/Support/` |
| 2 | Corrección de AR-01: retirar el enlace redundante y el `use` de `Auth\Infrastructure` | `BackofficeServiceProvider` |
| 3 | Tests AR-01 a AR-10 y AR-12 | `apps/api/tests/Feature/Architecture/` (directorio nuevo; los existentes no se mueven) |
| 4 | Test AR-11 | `apps/web/src/modules/architecture.spec.ts` |
| 5 | Sección «Módulos de la API: forma, reglas comprobadas y referencias» con la tabla de `§3.6` y la lista de reglas con su test | `ARCHITECTURE.md §3.4`; `§3` añade `Database/` |
| 6 | Reescritura contra la realidad | `apps/api/app/Modules/README.md`, `apps/web/src/modules/README.md` (reglas de AR-11), `.claude/skills/modulo-nuevo/SKILL.md` |
| 7 | Actualización de los siete desfases de `§3.7`, con la regla que comprueba cada casilla | `docs/modulos/_PLANTILLA/*.md` |
| 8 | Punto de comprobación de rutas citadas en `ARCHITECTURE.md §3.4` | `.claude/agents/doc-reviewer.md` |
| 9 | *Condicional a `OPEN-056-01`*: comando `make:module` y *job* de CI | `apps/api/app/Support/Modules/` (o `app/Console`), `.github/workflows/ci-api.yml` |

### A.2 Criterios de aceptación

**Reglas**

- **CA-056-01**: antes de escribir ninguna regla, se ejecutan las mediciones de `§3.3` como tests y se confirma el número de violaciones de cada una. Cualquier diferencia con este ADR **detiene el paso** y se reporta; no se ajusta la regla ni la lista por cuenta propia.
- **CA-056-02** (AR-01, `INV-007`): un test `arch()` por directorio de `app/Modules/`, generado por enumeración del sistema de ficheros, que prohíbe usar `Application`, `Infrastructure`, `Http`, `Database` y `Domain\Models` de cualquier otro módulo. Verde sin excepciones tras la pieza 2, y la resolución de `MfaVerifier` y `TotpProvisioner` desde el contenedor sigue devolviendo `Google2FaTotpVerifier` (test explícito).
- **CA-056-03** (AR-02): la misma frontera para `App\Support`, `App\Http`, `App\Models`, `App\Providers`, con las tres excepciones nominales de `§3.3`, y una aserción por excepción de que el fichero sigue usando esa clase.
- **CA-056-04** (AR-03): las cinco comprobaciones de `§3.3`, con `Backoffice` como única excepción a `DeclaresModuleRegistry`.
- **CA-056-05** (AR-04, `ADR-029`): consulta sobre el esquema de la base de test migrada; las siete tablas del *framework* como excepción nominal por tabla y columna; cada excepción se verifica viva.
- **CA-056-06** (AR-05): toda columna `public_id` es `character(26)`, `NOT NULL` y tiene un índice único de una sola columna.
- **CA-056-07** (AR-06, `INV-003`): reflexión sobre todas las clases de `App` que extienden `TenantModel` o `AppendOnlyModel`; seis excepciones nominales, cada una con referencia a su `datos.md`.
- **CA-056-08** (AR-07a, `INV-002`): toda ruta cuyo URI empieza por `api/v1` lleva un *middleware* `permission:` o su nombre está en la lista de 33; (AR-07b, `RMOD-009`) toda ruta cuyo controlador pertenece a un módulo con `essential = false` lleva `module-enabled:<código del módulo>` y aparece antes que `permission:`.
- **CA-056-09** (AR-08): `arch()->expect('App\Models\Role')->toOnlyBeUsedIn([...])` con la lista de `§3.3`; escáner de tokens para `'administrador_centro'` y `'soporte_plataforma'` con seis ficheros de excepción.
- **CA-056-10** (AR-09): ningún permiso de ningún `declaredPermissions()` tiene `is_special_category = true`; el mensaje de fallo remite a `ADR-044 §4.4` y a este ADR.
- **CA-056-11** (AR-10): mapa cerrado `auditoria → AuditLog → {EloquentAuditQuery, GenerateAuditLogExport, EloquentExportRequestService}`; todo permiso con ámbito distinto de `todos` tiene entrada en el mapa; ninguna llamada estática de consulta sobre el modelo fuera de los ficheros sancionados; cada fichero sancionado usa `ScopedQuery`.
- **CA-056-12** (AR-11): las cuatro comprobaciones de `§3.3` en Vitest, con casos fijos.
- **CA-056-16** (AR-12, `INV-009`): para cada `lang/es/*.php`, existen los ficheros `en`, `de` y `fr` con el mismo conjunto de claves aplanadas y ningún valor vacío. `CA-AUTH-233` y `CA-BO-073` se mantienen (no se borran tests ajenos a este paso).
- **CA-056-13**: todos los tests de A.1-3 en el grupo `arch`; se ejecutan con `composer test` en CI; el tiempo del grupo se mide y se anota en `memory.md` y en el `CHANGELOG.md` (número real, no estimado).
- **CA-056-14**: el escáner compartido tiene casos fijos que cubren, como mínimo, las formas que #167 encontró (`?->`, nombre dinámico entre llaves) y la exclusión de comentarios y cadenas.
- **CA-056-15**: cada lista de excepciones falla si contiene una entrada que ya no hace falta.

**Documentación**

- **CA-056-20**: `ARCHITECTURE.md §3.4` existe con la tabla de `§3.6` y el catálogo de reglas con el nombre de su test.
- **CA-056-21**: la skill `modulo-nuevo` y los dos `README.md` de módulos describen la estructura real de `§1.2`; ninguna afirmación suya contradice el código.
- **CA-056-22**: `_PLANTILLA` corrige los siete desfases de `§3.7` y marca qué casillas comprueba una regla.
- **CA-056-23**: `doc-reviewer` incluye la comprobación de rutas de `§3.6` punto 3.
- **CA-056-24**: issues abiertos para los hallazgos 2, 3, 5 y 6 de `§8` (el 1 y el 4 se cierran en el propio paso).

**Generador** (solo si `OPEN-056-01` se resuelve en contra de la recomendación)

- **CA-056-30**: valida nombre (`StudlyCase`), código (`[a-z][a-z0-9_]*`, no declarado por otro módulo) y la presencia de los cuatro nombres traducidos **antes** de escribir nada; si el módulo existe o falta una opción, falla sin tocar ningún fichero.
- **CA-056-31**: sin `--entity` genera exactamente las piezas 1-5 de `§3.5` y ningún directorio vacío más.
- **CA-056-32**: las dos ediciones compartidas son idempotentes (ejecutar dos veces no duplica líneas).
- **CA-056-33**: `--entity` sin `--audit-policy` falla con mensaje que cita `ADR-035`.
- **CA-056-34**: sobre lo generado, con y sin `--entity`: `php -l`, `migrate` y `migrate:rollback`, grupo `arch`, tests generados y `composer analyse` en verde.
- **CA-056-35**: un *job* de CI ejecuta CA-056-34 en cada PR y descarta lo generado.

### A.3 Orden de implementación

1. CA-056-01 (medir antes de escribir).
2. Escáner compartido y sus casos fijos (CA-056-14).
3. Pieza 2 y AR-01 (CA-056-02).
4. Reglas sin excepciones o con excepciones estables: AR-03, AR-04, AR-05, AR-06, AR-12.
5. AR-07a y AR-07b.
6. AR-02, AR-08, AR-10, AR-09.
7. AR-11 (frontend).
8. Documentación (CA-056-20 a -24).
9. *Condicional*: generador y *job* de CI (CA-056-30 a -35), en PR propio después de lo anterior mezclado.

Cada punto en commits pequeños con la suite en verde y el número real de tests en el mensaje (`CLAUDE.md §3`).

---

## Estado posterior (nota, no modifica ninguna decisión)

- **2026-10-10 · `#375`**: `AR-02` pasa de 3 a **0 excepciones nominales**. Los *middleware* de sesión y `SyncModuleRegistry` dependen ya de las interfaces `App\Support\Sessions\ActiveSessionCloser` y `App\Support\FeatureFlags\FeatureFlagCatalogInvalidator`, que implementan Auth y Core. Con la lista vacía se cumplen `CA-056-03` y `CA-056-15`; `OPEN-056-03` y el hallazgo 5 de §8 quedan resueltos. Las menciones a las tres excepciones en §3.3, §5 y §8 describen el estado en que se aceptó el ADR.
- **2026-10-10 · `#378` (B1, B2)**: `AR-01` pasa a vedar todo lo que cuelgue de la raíz de otro módulo salvo `Domain` sin `Models` (no solo cinco capas fijas), y `AR-01`, `AR-02` y la parte `arch()` de `AR-08` tienen control negativo permanente. B3-B5 siguen abiertos.
