# CHANGELOG

Historial de la documentación del proyecto. Cuando exista código, este fichero recogerá también las versiones de la aplicación.

Formato: versionado semántico por documento. Mayor = cambio que invalida decisiones previas. Menor = contenido nuevo. Parche = correcciones.

---

## 2026-10-10 · Pest 5 y PHPUnit 13 (#346, rama `chore/deps-pest-5`)

- `pestphp/pest` `^5.0` (5.3.1), `pestphp/pest-plugin-laravel` `^5.0` (5.0.1), `phpunit/phpunit` `^13.0.0` (13.4.1), con `pest-plugin-arch` 5.0.0 y `laravel/boost` 2.10.3 (Pest 5.3.1 exige `boost >= 2.6.0`, lo que bloqueaba a Renovate). Sin cambios en tests, `phpunit.xml`, `composer.json` (scripts) ni `bootstrap.php`: 1105 tests en verde, igual que antes.

---

## 2026-10-10 · Suite de tests en paralelo y norma de ejecución (`ADR-060`, rama `chore/tests-paralelos`)

- **Implementación de `ADR-060` (aceptada)**: `composer test` ejecuta `Unit` + `Feature` en paralelo (una base `plataforma_test_N` y una base Redis de caché por proceso; 6 procesos en desarrollo, 4 en CI, `PEST_PROCESOS`) y después `Concurrency` en serie; `test:paralelo`, `test:concurrencia` y `test:serie` (camino de vuelta). Script idempotente `infra/containers/postgres/bases-test-paralelo.sh N [--recrear]`; `tests/bootstrap.php` con guarda (`^plataforma_test_[0-9]+$` y `APP_ENV=testing`, aborta antes del primer test); paso de bases en `ci-api.yml` antes de `artisan serve`. Las guardas de `Concurrency` no cambian.
- **Arreglos de la suite (`§4.2`)**: los *helpers* `bo*()` pasan de ficheros de test a `tests/Support/BackofficeTestHelpers.php`. Causa del `TypeError` de Collision (`AfterLastTestMethodErrored`): los **dos** `afterAll()` del repositorio (`AdminActionLogsTest`, `PlatformSchemaGrantsTest`; el «4» del ADR contaba también dos comentarios) llamaban a `DB::connection()` con la aplicación ya destruida (`Target class [config] does not exist`); ahora usan `Tests\Support\StandaloneDatabase`. En serie nunca se manifestó.
- **Trabajo no repetido (`§4.3`)**: `Tests\Support\TestPasswordHash` memoriza bcrypt coste 12 por proceso (`RN-AUTH-03` intacto, coste sin tocar); `platform:sync-registry` una vez por proceso (`syncRegistryOnce()`), con restauración explícita en `SyncModuleRegistryTest` y `FeatureFlagsTest` (sincronizan con proveedores de prueba y retiraban los permisos reales) y sincronización explícita en `CA-PERM-134` (recorre el catálogo entero).
- **Hallazgo propio, `#199`**: cada test reconstruía la aplicación y dejaba abiertas las conexiones de la anterior (tres roles) hasta el recolector de basura; con 6 procesos, el primer intento de paralelo agotó `max_connections=100` (481 fallos). `TestCase` cierra ahora las conexiones al destruir la aplicación (`beforeApplicationDestroyed`, tras el *rollback* de `DatabaseTransactions`): pico medido de **17 conexiones de 100** en `composer test`. No se cierra el issue desde esta rama.
- **Cifras medidas** (contenedor `plataforma-api`, 6 CPU): `composer test` = 1105 tests (1096 `Unit`+`Feature` y 9 `Concurrency`), **136-146 s** de pared en cinco ejecuciones seguidas, todas en verde; `composer test:serie` sobre el mismo código = 1105 tests, **388 s** (antes de esta rama: 1084 tests de `Unit`+`Feature` en 465 s, sin `Concurrency`). Relación paralelo/serie ≈ 36 % (media de las cinco: 141 s; `CA-060-03` exige ≤ 50 %).
- **Revisión de seguridad**: el script de bases solo acepta la plantilla `plataforma_test` (M-1); el bootstrap en paralelo exige `DB_HOST` local o el contenedor `postgres` (M-2); `StandaloneDatabase` lanza excepción si `DB_DATABASE` no es una base de test (B-1).
- **Comando parcial**: `CONTRIBUTING.md` y `SYSADMIN.md` usan `php -d memory_limit=-1 vendor/bin/pest <ruta>`, igual que `CLAUDE.md §3` y `verificador` (issue #106); `ADR-060 §4.1.6` y `§9` dicen `php artisan test <ruta>` y, como el ADR es inmutable, prevalece esta nota.
- **#401**: `ci-api.yml`, `ci-web.yml` y `build-images.yml` declaran `permissions: contents: read` a nivel de workflow (los jobs que escriben ya tenían los suyos). `.claude/settings.json` autoriza `composer test`. #199 cerrado (cierre de conexiones en `TestCase`).
- `SYSADMIN.md` 0.8.8, `CONTRIBUTING.md` 0.3.0 y `README.md` 2.6.18. `CLAUDE.md` 2.7.0 (`§3`: cuándo se ejecuta la suite, alcance del `Verificado`) y agentes `implementer`, `test-writer`, `verificador` (modos **afectados**/**completa**) y `security-reviewer`/`db-reviewer`/`doc-reviewer` (no relanzan la suite completa sin justificarlo), según `ADR-060 §4.4`.

---

## 2026-10-10 · Cobertura de las reglas de arquitectura `AR-01`, `AR-02` y `AR-08` (`INV-007`, `ADR-056`, #378 B1 y B2)

- **B1, control negativo permanente**: fixtures en `apps/api/tests/Fixtures/Architecture/` que violan a propósito la frontera de módulo (`Infrastructure` y `Domain\Models` de otro módulo) y un fixture que solo usa el `Domain` permitido (control positivo, para que la regla no pase por lanzar siempre). `AR-01` y `AR-02` comprueban con `toThrow` que `not->toUse(...)` muerde; la parte `arch()` de `AR-08` (Pest no escanea `Tests\`) lo comprueba aplicando `toOnlyBeUsedIn` con una lista deliberadamente estrecha. Un cambio de Pest que vacíe las reglas ya no pasa desapercibido.
- **B2, `AR-01` invertida**: `ArchitectureModules::forbiddenFor()` calcula lo vedado por sistema de ficheros (todo lo que cuelgue de la raíz de otro módulo salvo `Domain`, más `Domain\Models`); una carpeta o clase nueva (`Console`, `Support`, `Listeners`…) queda vedada sin tocar el test. Sin violaciones reales hoy. `AR-02` no se modifica (B3-B5 y la inversión de `AR-02` fuera de alcance).
- `ARCHITECTURE.md` 2.4.2 y `README.md` actualizados.

---

## 2026-10-10 · Test de la migración de `public_id` sin estado residual (`ADR-056 AR-05`, `CA-056-06`, #377)

- **`FeatureFlagsPublicIdMigrationTest` (#377 B-1, B-2)**: `down()`, `up()` y la fila de prueba corren dentro de una transacción de `pgsql_owner` que siempre termina en `ROLLBACK` (el DDL de PostgreSQL es transaccional). Un `kill` del proceso ya no deja las columnas en `text` ni la fila `test.migration.short_public_id`.
- **#377 B-3**: no se implementa; `SYSADMIN.md` 0.8.7 anota que los *workers*/Octane, cuando existan (#128), deben reiniciarse tras migraciones que cambien el tipo de una columna. `README.md` actualizado a `SYSADMIN.md` 0.8.7.

---

## 2026-10-08 · `AR-02` sin excepciones (#375, `INV-007`)

`EnforceSessionIdleTimeout` y `VerifySessionTenant` cierran la sesión por `App\Support\Sessions\ActiveSessionCloser` (implementación `Auth\Infrastructure\EloquentActiveSessionCloser`); `SyncModuleRegistry` invalida la caché por `App\Support\FeatureFlags\FeatureFlagCatalogInvalidator` (lo implementa `FeatureFlagCatalogCache`). `CoreBoundariesTest`: lista de excepciones vacía y test de resolución de las interfaces. Sin cambio de comportamiento. `ADR-056 §3.3`, `OPEN-056-03` y `CA-056-03`/`CA-056-15` quedan superados en esta parte (el ADR no se edita).

---

## 2026-10-08 · `ADR-059`: reapertura de un curso cerrado (`OPEN-CURSO-08`, `REQ-CURSO`)

- **`ADR-059` ACEPTADA**: opción B (reapertura por la API del centro, acotada por hechos: ningún otro curso activo y solo el cerrado más reciente), permiso propio `reapertura_curso_academico.actualizar`, motivo obligatorio en la tabla *append-only* `academic_year_reopenings`, registro vacío de validaciones de reapertura. No toca el disparador ni `YC001`. Resuelve `OPEN-CURSO-08`; `OPEN-059-05` y `-06` siguen abiertas.
- **Especificación de `REQ-CURSO`** ampliada (`RN-CURSO-40..48`, `CA-CURSO-087` y `-100..-107`, `OPEN-CURSO-24`), `SYSADMIN.md` 0.8.6, manual de administración y `REQUISITOS` 3.2.14. **Solo documentación: la reapertura aún no está implementada.**

---

## 2026-10-08 · Redistribución de modelos entre Haiku, Sonnet y Opus (`CLAUDE.md` 2.6.0)

- **Copia de seguridad previa**: etiqueta de Git `config-claude-2026-10-08` (commit `0ecd3a2`, publicada) con `CLAUDE.md`, `.claude/agents/`, `.claude/skills/`, `.claude/settings.json` y la tabla de agentes de `PLAN-IMPLEMENTACION.md` tal como estaban. Procedimiento de vuelta atrás en `CLAUDE.md §2`.
- **`CLAUDE.md §2`**: plan Pro con límite de 5 horas y Sonnet como modelo por defecto de la sesión principal (`"model": "sonnet"` en `.claude/settings.json`); se elimina la mención contradictoria a "la sesión corre vía API". Tabla de agentes con su modelo; reglas para no heredar Opus en `fork`/`general-purpose`/`Plan` en trabajo de ejecución; alias de modelo en vez de ID de versión.
- **Agentes nuevos en Haiku**: `verificador` (suite y linters con informe literal, sin corregir), `traductor` (en/de/fr a partir de es-ES, excluidos textos legales y de consentimiento) y `doc-precheck` (pasada mecánica previa a `doc-reviewer`, que sigue siendo obligatorio).
- **Agentes ampliados**: `janitor` (issues de hallazgos ya clasificados, PR, borrado de ramas mezcladas, entrada de `CHANGELOG.md`; nunca decide severidad ni escribe `memory.md`), `explorer` (`Bash` con lista cerrada de órdenes de consulta de Git y GitHub). `implementer`, `doc-reviewer` y las skills `cierre-de-sesion` e `i18n-cuatro-idiomas` ajustadas al nuevo reparto. Revisores y `test-writer` siguen en Sonnet.

## 2026-10-08 · `AR-15`: escrituras masivas sobre modelos auditables (`INV-003`, #380)

- **`AR-15` (#380)**: nueva regla de arquitectura (grupo `arch`) que prohíbe en `app/` la escritura masiva por modelo (`Modelo::query()->where()…->update()/delete()/forceDelete()/upsert()…`) sobre modelos `Auditable`, porque una sola sentencia SQL no dispara el *observer* y no deja rastro en `audit_logs`. Alcance acotado por decisión del usuario (2026-10-08) respecto al enunciado original del issue: **no** cubre `DB::table()`/SQL crudo. Lista de excepciones cerrada de cuatro jobs de purga física por retención, con motivo y *ratchet*, más control negativo. Medido: 8 ficheros / 9 sitios de escritura masiva sobre modelos auditables.
- **Corregidos cuatro fallos reales de auditoría** (borrado lógico masivo sin rastro): `MfaRecoveryCodeService::regenerate`, `MfaResetService::reset`, `MfaFactorRemovalService::remove` (códigos de respaldo) y `MfaEnrollmentService` (alta pendiente anterior), ahora por instancia. Cada código o factor borrado deja su fila `deleted` en `audit_logs` (un lote de códigos genera una fila por código). Test de regresión `BulkDeleteAuditTest`.
- `docs/modulos/REQ-CURSO/funcional.md §6` corregida: describe `AR-15` y su alcance real; el DML crudo sobre tablas de curso sigue cubierto solo por el disparador. `ARCHITECTURE.md §3.4`, `Modules/README.md` y la skill `modulo-nuevo` documentan la regla.

## 2026-10-08 · Cierre de pendientes previos a `1.11` (`REQ-CURSO-001`, #383, #381, #385, #382, `OPEN-057-03`)

- **`AR-14` (#383)**: nueva regla de arquitectura que confina la conexión `pgsql_owner` en tiempo de ejecución a una lista cerrada de cinco ficheros (`SyncModuleRegistry`, `TenantMigration`, `PurgeLoginAttempts`, `PurgeSamlAuthRequests`, `PurgeSamlConsumedAssertions`) y comprueba en el esquema que ninguna función `SECURITY DEFINER` del propietario menciona una tabla con `academic_year_id`. Medido: las acciones referenciales (`ON DELETE CASCADE`) corren como propietario de la tabla hija y saltan el disparador (hallazgo documentado en `ARCHITECTURE.md §3.4`).
- **Auditoría de la obligación MFA (#381, `INV-003`)**: `MfaEnrollmentService` y `MfaExemptionService` cerraban `user_mfa_obligations` con `query()->update()`, sin auditoría; ahora actualizan por instancia (`MfaObligationAuditTest`).
- **Endurecimientos (#385)**: `guardAcademicYearWrites()` con `lock_timeout` de 5 s e idempotencia explícita; `curso:grant-year-permissions` comprueba `platform:sync-registry`; `encodeURIComponent` en `academicYears.ts`; reintento `40P01` documentado en `REQ-CURSO/operacion.md`.
- **`OPEN-057-03` resuelta**: `RN-CURSO-33`, criterio de lectura denegada de curso cerrado obligatorio en toda especificación con datos por curso (plantilla, skill `modulo-nuevo`, `ARCHITECTURE.md §3.4`).
- **#382**: `1.11` y `1.15` en `PLAN-IMPLEMENTACION.md` heredan la obligación de repetir `AcademicYearWriteGuardOverheadTest` con la primera tabla real.
- **Pendiente, parado** (#380): la regla de DML crudo de `ADR-034 §3` no se ha implementado; la medición da 38 sitios de escritura masiva en 31 ficheros más 30 ficheros con `DB::`, por encima del umbral de 15 fijado para el paso.

---

## 2026-10-08 · Paso 1.10: el `SQLSTATE` propio pasa de `CY001` a `YC001` (`REQ-CURSO-001`, #384)

`ADR-058` cambia el `SQLSTATE` del bloqueo de escritura de cursos cerrados de `CY001` a `YC001` (decisión del usuario, opción A de #384); `ADR-057` es inmutable y conserva el valor antiguo en su texto. Se sustituye en código, migración, tests, OpenAPI, documentación del módulo y documentos raíz. El valor vive en una sola constante (`AcademicYearClosedTranslator::SQLSTATE`) que usan los tests. Nuevo test `CA-057-11`: la clase `YC` no está en los rangos reservados (0-4, A-H) ni coincide con ninguna clase de PostgreSQL. La migración aún no estaba en ningún entorno desplegado; en las bases locales `plataforma` y `plataforma_test` se reemplazó la función a mano (`CREATE OR REPLACE`, mismo cuerpo).

---

## 2026-10-07 · Paso 1.10: correcciones de la revisión de `doc-reviewer` y `db-reviewer` (`REQ-CURSO-001`)

- **Base de datos**: la migración de la función comprueba `has_schema_privilege(current_user, 'app', 'CREATE')` y, si falta, aborta con el comando exacto (`RUNBOOK.md` paso 0 de `§3b.2`); la función fija `SET search_path = pg_catalog, pg_temp`; `AR-13` gana la vigilancia «ninguna tabla con el disparador carece de `academic_year_id`» (con control negativo) y un test de que `plataforma_app`/`plataforma_platform` no tienen `CREATE` sobre `app`. `DROP TRIGGER` antes de `DROP COLUMN` documentado en `datos.md`, `operacion.md` y las skills `migracion-segura`/`modulo-nuevo`.
- **Documentación**: `funcional.md` acota el `409 invalid_transition` a destinos `activo`/`cerrado` (otro destino es `422`, `api.md §2`) y retira los «propuesta» obsoletos; firma real de `tenantForeignId(Blueprint $blueprint, …)`; `curso:grant-year-permissions` vive en Core; `PLAN-IMPLEMENTACION.md` recoloca la línea de boletines bajo 1.17; `SECURITY.md` 0.3.10 (bloqueo por disparador, `curso_historico.leer`, excepción `AR-07a`), `PRIVACY.md` 0.3.6 (`OPEN-057-04`), `RUNBOOK.md` 0.3.4, `SYSADMIN.md` 0.8.5, `ARCHITECTURE.md` 2.4.1, `PLAN-IMPLEMENTACION.md` 2.3.7, `docs/REQUISITOS-...` 3.2.13 (`ADR-057`, errata `RDB-012`), `README.md` 2.6.17.

---

## 2026-10-07 · Paso 1.10 implementado: ciclo de vida del curso académico y bloqueo de escritura de cursos cerrados (`REQ-CURSO-001`, `ADR-057`)

Implementa `REQ-CURSO-001` y el contrato transversal según la especificación aprobada (`docs/modulos/REQ-CURSO/`, `ADR-057` aceptada). Rama `feature/REQ-CURSO-1-10-ciclo-vida-curso`; pendiente de `db-reviewer`, `security-reviewer` y `doc-reviewer`.

- **Módulo `curso`** (esencial, `depends_on []`, `OPEN-CURSO-01`): `AcademicYear` y `AcademicYearStatus` pasan de `App\Models` a `Curso\Domain` (mismo alias `academic_year` en el *morph map*, `audit_logs` no cambia); seis endpoints (`GET/POST /academic-years`, `GET /academic-years/current`, `GET/PATCH /academic-years/{id}`, `POST /academic-years/{id}/status`) y OpenAPI; permisos `curso_academico.*`, `estado_curso_academico.actualizar`, `curso_historico.leer` y su siembra (`ProvisionTenantDefaults` + comando `curso:grant-year-permissions` para centros existentes).
- **Bloqueo por el motor**: única migración, `app.assert_academic_year_writable()` (primera función PL/pgSQL del proyecto, `SECURITY INVOKER`, exención del propietario real de la tabla, `FOR SHARE` sobre el curso, `SQLSTATE YC001`); `TenantMigration::tenantTable()`/`tenantTableAppendOnly()`/`guardAcademicYearWrites()` enganchan el disparador `academic_year_write_guard`; regla **`AR-13`** (lista de excepciones vacía) en `ARCHITECTURE.md §3.4`. `Curso` traduce `YC001` a `409 urn:pge:error:academic-year-closed` (catálogo de `ADR-038 §6.2` ampliado). Contrato en `Curso\Domain`: `AcademicYearContext`, `AcademicYearDirectory`, `AcademicYearWriteGuard`, `AcademicYearReadAccess`, registro de validaciones de cierre (vacío). Cierre con `FOR UPDATE` del curso antes que cualquier otro bloqueo (`RN-CURSO-32`).
- **Web**: listado, alta/edición y ficha (activar/cerrar con `ConfirmDialog`) en `apps/web/src/modules/curso/`, cuatro idiomas; `CA-CURSO-086` contra la API real (`npm run test:e2e:real`).
- **Decisiones y desviaciones a revisar**: (1) la migración exige `GRANT CREATE ON SCHEMA app TO plataforma_owner` (añadido a `01-tenancy.sql.tpl`; aplicar a mano en bases existentes, `SYSADMIN.md`); (2) `CA-CURSO-023` y `api.md §2` se contradicen sobre `status` ∈ {`planificacion`,`archivado`} en `POST …/status` (409 frente a 422): se implementó `api.md` (422); (3) `ApiException` gana `titleKey`/`academicYearClosed()` y `notFound()` admite `errors` (aditivo); (4) `AR-07a` pasa de 33 a 34 rutas (`GET /academic-years/current`, ampliación aprobada).
- **Sobrecarga del disparador (`CA-057-09`)**: inserción masiva de 20.000 filas **+182 % a +195 %** (≈ 16 µs por fila; 0,50 s frente a 0,17 s); fila a fila (1.000 sentencias) entre −1,7 % y +4,0 %, dentro del ruido. Supera el +1,24 % de RLS de `0.8.12` en escritura masiva: registrado como issue [#382](https://github.com/pirexia/plataforma-educativa/issues/382) (Baja), sin ajustar la cifra.
- **Verificación**: Pest **1062/1062**, Vitest 1337 (+3 omitidos), Pint, Larastan, `vue-tsc`, ESLint y `lint:i18n` limpios; `npm run test:e2e:real` (`CA-CURSO-086` y `CA-PERM-133`) en verde.
- **Tests**: `tests/Feature/Curso/` (escrituras por catorce caminos con `YC001`, paridad SQL↔PHP, exención del propietario, `TRUNCATE`, HTTP en cuatro idiomas, endpoints, contrato, OpenAPI), `AR-13` en `tests/Feature/Architecture/`, concurrencia real en `tests/Concurrency/CursoConcurrencyTest.php` (alta simultánea, activación simultánea y cierre frente a escritura en las dos órdenes) y Vitest del módulo web.

## 2026-10-07 · Paso 1.7b cerrado y mezclado (PR #379): reglas de arquitectura comprobadas por test (`ADR-056`, `INV-007`, `RNF-MANT-003`)

Implementa las piezas 1 a 8 de `ADR-056` Anexo A (el generador `make:module` y su *job* de CI se difieren al paso nuevo `1.11b`). Rama `feature/REQ-ARQ-1-7b-estandarizacion-modulos`; **revisión independiente hecha (`db-reviewer`, `security-reviewer` y `doc-reviewer`, sin Crítico ni Alta; Bajas en #377 y #378) y mezclado en el PR #379.** Issue [#163](https://github.com/pirexia/plataforma-educativa/issues/163).

- **Doce reglas, todas nacidas en verde** (`AR-01` a `AR-12`; `apps/api/tests/Feature/Architecture/` y `apps/web/src/modules/architecture.spec.ts`; catálogo con el nombre de su test en `ARCHITECTURE.md §3.4`): frontera entre módulos y del núcleo, convención de `ServiceProvider`, `ADR-029` en el esquema real (`pg_catalog`), forma de `public_id`, `Auditable` en todo modelo de tenant, `permission:` y `module-enabled:` en toda ruta de `api/v1`, confinamiento de `Role` y de dos códigos de rol, cable trampa de categoría especial, `ScopedQuery`, frontera y registro de los módulos web, y paridad de los cuatro idiomas. Cada excepción es una lista cerrada y nominal dentro del test, solo puede reducirse y falla si una entrada sobra (`CA-056-15`). Cada regla tiene un control negativo (se comprobó que falla al introducir una violación y que vuelve a verde al revertirla). Un único escáner de tokens compartido (`tests/Support/PhpScanner.php`) con casos fijos (`CA-056-14`).
- **Corrección de la medición de `ADR-056 §3.3` (`CA-056-01`).** Medidas todas las cifras con tests reales antes de escribir ninguna regla, coincidieron todas **salvo `AR-05`**: el ADR decía 0 violaciones en 30 tablas con `public_id` y eran **2** (`feature_flags.public_id` y `feature_flag_rules.public_id` eran `text`, migración de `1.6e`). El ADR dice además que el paso no lleva migraciones; **lleva una**. Decisión del usuario (2026-10-07): corregirlas, no registrar una excepción. `ADR-056` es inmutable (`ACEPTADA`), así que la corrección vive aquí y en `ARCHITECTURE.md §3.4`.
- **Migración nueva** `2026_10_07_100100_narrow_feature_flags_public_id_to_char26`: `text` → `character(26)` en las dos columnas, con comprobación previa de que ninguna fila tiene longitud distinta de 26 (aborta si la hay) y `down()` que restaura `text`. Conserva el índice único de una sola columna, los privilegios de tabla y de columna de `plataforma_platform`/`plataforma_app` y el `NOT NULL`; transaccional. Desviación consciente de «no cambiar el tipo sin columna intermedia» (`migracion-segura`): tablas de catálogo diminutas, reescritura instantánea, escritores que ya generan ULID de 26 caracteres. Test `FeatureFlagsPublicIdMigrationTest` (tipo, `NOT NULL`, índice único y privilegios tras migrar y tras `down()`, y el aborto). `AR-05` nace **sin excepciones**.
- **Corrección de `AR-01`**: `BackofficeServiceProvider` retira el enlace redundante de `MfaVerifier`/`TotpProvisioner` y el `use` de `Auth\Infrastructure` (`AuthServiceProvider` ya los enlaza); test explícito de que el contenedor sigue resolviéndolos a `Google2FaTotpVerifier`. Es el único cambio de código de aplicación, aparte de la migración.
- **Dos trampas descubiertas y ya tenidas en cuenta**: `expect([A, B])->not->toUse(...)` con varios objetivos **pasa en vacío** en Pest `4.7.8` (se escribe un `arch()` por objetivo), e `information_schema` solo muestra las tablas sobre las que el rol de la conexión tiene privilegios (24 de 30 tablas con `public_id` para `plataforma_app`; se usa `pg_catalog`). La consulta de `timestamp` sin zona usa `like 'timestamp%without time zone'` porque Laravel crea `timestamp(0)`.
- **Documentación**: `ARCHITECTURE.md §3.4` (forma real, reglas con su test, referencias por patrón y el disparador de revisión al cerrar `1.11`; `§3` añade `Database/`), `apps/api/app/Modules/README.md`, `apps/web/src/modules/README.md`, la skill `modulo-nuevo` (reescrita contra la realidad: tests en `tests/Feature/<Modulo>/`, factorías en `database/factories/`, cinco ficheros de documentación, las dos ediciones de ficheros compartidos y la parte web), los siete desfases de `docs/modulos/_PLANTILLA/` de `ADR-056 §3.7` con la regla que vigila cada casilla, y la comprobación 12 de `doc-reviewer` (rutas citadas en `ARCHITECTURE.md §3.4`). Las cabeceras de versión de `ARCHITECTURE.md` y la tabla de `README.md` no se han tocado: las sube el cierre de fase.
- **Issues** (`CA-056-24`): [#373](https://github.com/pirexia/plataforma-educativa/issues/373) (skill y README de módulos, corregido en este paso), [#374](https://github.com/pirexia/plataforma-educativa/issues/374) (comentarios desfasados de `IsolationBatteryTest`), [#375](https://github.com/pirexia/plataforma-educativa/issues/375) (tres dependencias del núcleo hacia módulos, `AR-02`) y [#376](https://github.com/pirexia/plataforma-educativa/issues/376) (`roleLiterals.spec.ts`).
- **Tiempo del grupo `arch`** (`CA-056-13`): medido con `./vendor/bin/pest --group=arch` en tres ejecuciones consecutivas, **82 tests y 415 aserciones en 6,08 s, 6,14 s y 6,35 s** (≈ 6 s en total, incluidos los casos fijos y los controles negativos; sin carga adicional). Se ejecutan también en `composer test` (suite completa). Si con decenas de módulos el coste de `AR-01` (un `arch()` por módulo) se volviera un problema, pasa al escáner de tokens compartido, que recorre `app/` una sola vez (`ADR-056 §5`).
- Verificación: `apps/api`: Pest **950/950** (13.227 aserciones, 487 s), Pint y Larastan limpios (`phpstan analyse`, 0 errores). `apps/web`: Vitest **1.281/1.281**, `vue-tsc -b`, ESLint, Prettier (`src/modules/README.md` reformateado; `test-results/` es un artefacto ignorado) y `lint:i18n` limpios. `db-reviewer` pasa a ser obligatorio en este paso (migración nueva).

## 2026-10-06 · `CA-PERM-133` completo y ejecutable contra la API real (`REQ-PERM-005`)

- El test de `apps/web/e2e/core-roles.spec.ts` solo cubría login, alta del rol y una concesión. Ahora recorre el criterio entero contra la API real: alta de «Revisión propia», `auditoria.leer` con «Propios», asignación a un usuario desde su ficha, permisos efectivos con «Permitido», «Propios» y procedencia, `rol_datos_especiales.actualizar` con «Permitir · Todos» y `special_data_access` deshabilitado en la edición del rol (comprobado con un control negativo: con `toBeEnabled()` el test falla).
- Nuevo `apps/api/tests/Support/e2e-real-tenant.php` (`setup`/`teardown`): crea el centro sintético `demo` con `TenantProvisioner`, activa al administrador con contraseña aleatoria y una excepción de MFA viva (`REQ-AUTH-003`) y añade un usuario `@example.com`. Exige a la vez CLI, `E2E_ALLOW_DESTRUCTIVE=1`, `APP_ENV` `local`/`testing`, base `plataforma`/`plataforma_test` y un slug válido; `teardown` se niega a tocar un centro que no creó él. Está excluido de la imagen de producción (`apps/api/.dockerignore`).
- Nuevo `npm run test:e2e:real` (`apps/web/scripts/e2e-real-api.sh`): prepara el centro, ejecuta el test y lo retira al terminar, también si falla. **El borrado de un centro no es en cascada** (31 tablas con `tenant_id`, 85 claves foráneas entre ellas, ninguna `ON DELETE CASCADE`, y el rol de plataforma no puede borrar de las tablas de solo anexar): el script purga las filas del tenant con el superusuario del contenedor de PostgreSQL de desarrollo. La purga física de un tenant real sigue pendiente (`REQ-PRIV-006`).
- Revisión de `security-reviewer` aplicada: guarda de CLI y bandera explícita, validación del slug, `trap` antes de `setup`, credenciales por stdin y no por argumentos, y limpieza de las filas huérfanas que dejaban las primeras ejecuciones.

## 2026-10-06 · Correcciones de seguridad menores de `POST /roles`: #359 (`REQ-PERM-005`)

- **#359**: con `clone_from`, los `422` `permission_retired`, `permission_not_found`, `scope_not_applicable`, `scope_resolver_missing` y `clone_requires_special_data_access` ya no llevan `params` ni nombran el código, el ámbito ni el atributo `special_data_access` del rol origen: mismo `code` estable, campo `errors.clone_from`, mensaje genérico traducido (claves `core.validation.clone_source_*`) y una sola entrada por `code`. Con `permissions` propias en el cuerpo, sin cambio. Alcanzables hoy con un origen real: retirado, ámbito no aplicable, ámbito sin resolutor y `special_data_access`; `permission_not_found` no (clave foránea). Tests `CloneRoleValidationLeakTest`. `REQ-PERM/api.md §8.4`, `funcional.md` (`RN-PERM-24`), `permisos.md` y OpenAPI.

## 2026-10-06 · Correcciones tras la revisión de 1.5b: #356, #349, #351, #353 (`REQ-PERM-005`, `REQ-CORE-001`)

- **#356**: el `403` de `POST /roles` con `clone_from` ya no lleva `errors.grant[0].params` ni nombra código/ámbito (reutiliza `cannot_grant_unheld_role_permission`, como #352). `POST /roles` con concesiones propias y `PUT /roles/{id}/permissions`, sin cambio. `REQ-PERM/api.md §8.4`, `funcional.md` (`RN-PERM-24`), `permisos.md` y OpenAPI.
- **#349**: `RN-CORE-07` (`SchoolAdministratorGuard`) se vuelve a comprobar dentro de `AdministrationCapacityGuard::protect()`, con el bloqueo por tenant tomado y sobre lecturas frescas, en `DELETE /users/{id}`, `POST /users/{id}/status` y `PUT /users/{id}/roles`; el orden de errores y el contrato público no cambian. Test de regresión determinista (`SchoolAdministratorSerializationTest`). `REQ-CORE` (`permisos.md`, `api.md`, `operacion.md`) y `REQ-PERM` actualizados.
- **#351**: nueva carpeta `apps/api/tests/Concurrency` (suite `Concurrency` de `phpunit.xml`, base `ConcurrentTestCase` sin transacción envolvente) con un test de concurrencia real de `RN-PERM-47` y `RN-CORE-07`: dos procesos PHP solapados, sincronizados con `pg_locks`. `REQ-PERM` (`permisos.md §12.4`, `CA-PERM-049`) dice con exactitud qué cubre.
- **#353**: medido el coste de `AdministrationCapacityGuard` bajo el bloqueo (lineal, ≈6 ms por titular del ancla; 8,5 s con 1000 titulares sintéticos, 36 ms con 5). Un único `PermissionResolver` por fase de evaluación (−14 % a −20 %), mismo resultado (`AdministrationCapacityResolverTest`); sin más optimización. Medición en `REQ-PERM/operacion.md` y en el código.

---

## 2026-10-05 · Corrección tras la revisión de 1.5b: #352 y hueco residual de #350 (`REQ-PERM-005`)

- **#352**: el `403` de `RPERM-013` en `PUT /users/{id}/roles` y `POST /users` con `role_ids` ya no lleva `errors.grant[0].params` ni nombra el código o el ámbito en `detail` (clave nueva `core.authorization.cannot_grant_unheld_role_permission`, cuatro idiomas); se mantienen el `403` y `errors.grant[0].code`. `PUT /roles/{id}/permissions`, `POST /roles` y la clonación, sin cambio. Documentado en `REQ-PERM/api.md §8.4`, `funcional.md` (`RN-PERM-24`, `CA-PERM-042`), `permisos.md` y OpenAPI.
- **#350 (hueco residual)**: `asignacion_rol.eliminar` se recalcula dentro de `protect()` sobre los roles releídos con el bloqueo (`RPERM-013`, `RN-PERM-20`), con test de regresión determinista en `ConcurrentSnapshotTest`.

---

## 2026-10-05 · Paso 1.5b cerrado y mezclado (PR #354) (`REQ-PERM-005`)

Interfaz de roles y permisos efectivos de `REQ-PERM` y tres cambios de servidor, en la rama `feature/REQ-PERM-ui-roles`. **Pendiente de revisión independiente y de merge: no se declara cerrado.** Sin migraciones, sin permisos nuevos y sin dependencias nuevas.

### Servidor (`apps/api`)
- **S-PERM-1**: `users_count` en `GET /roles/{public_id}`.
- **S-PERM-2**: `resource_label` (traducido por el servidor) en cada permiso del detalle de rol y de los permisos efectivos.
- **`RN-PERM-47`**: `409 core.validation.administration_capacity_lost` si una escritura dejaría al centro sin ningún usuario activo con la capacidad completa de administración (cinco rutas; `CA-PERM-046` a `-049`). Comprobación y escritura serializadas por tenant con `pg_advisory_xact_lock`. `CA-PERM-049` cubre el mecanismo del bloqueo y una escritura secuencial, no dos transacciones solapadas reales.
- **#170**: se mantiene el comportamiento estricto de `ReplaceRolePermissions` (estrechar a un ámbito no poseído responde `403`, sin cambio de código de producción); `CA-PERM-045` lo fija con un test y se corrige la redacción de `api.md §5.4`.
- **Corrección de contrato**: los datos de los errores `403 grant`, `409 role` y `409 administration_capacity` van en `errors[].params`, como define `ADR-038 §6.3`, y no en un `params` de primer nivel que el servidor nunca emitió (`api.md §9.2.1`); `ApiException::forbidden()`/`conflict()` aceptan `errors`.

### Interfaz (`apps/web`)
- Pantallas de roles (listado con alta y enlaces, ficha con concesiones, matriz recurso × acción × ámbito) y permisos efectivos de un usuario con procedencia y acción en su ficha (`RN-PERM-25`, `-26`, `-41` a `-45`).
- **Modo `local` del componente de tablas** (ampliación aditiva de `src/data-table/`, `RN-PERM-44`): primer consumidor, sobre campos de un recurso ya cargado.
- **Segunda lista de `RN-CORE-53`**: rejillas de edición, aprobada expresamente por el usuario; contiene solo `RolePermissionMatrix.vue` (`REQ-PERM/funcional.md §20.12`).
- Dos claves nuevas de `localStorage`: `plataforma.table.core.role_grants` y `plataforma.table.core.effective_permissions` (solo ids de columna; `PRIVACY.md`).

### Documentación y hallazgos
- Cabeceras y contenido de `docs/modulos/REQ-PERM/`, `REQ-CORE` (`RN-PERM-47` junto a `RN-CORE-07`; `RN-CORE-75` y `CA-CORE-240` sustituidos), `README.md` 2.6.15, `SECURITY.md` 0.3.8, `ARCHITECTURE.md` 2.3.5, `PRIVACY.md` 0.3.5 y `RUNBOOK.md` 0.3.3.
- Issues abiertos derivados: [#348](https://github.com/pirexia/plataforma-educativa/issues/348) (`PUT /roles/{id}/permissions` permitía dejar al centro sin forma de administrar roles; lo cubre `RN-PERM-47`) y [#349](https://github.com/pirexia/plataforma-educativa/issues/349) (`SchoolAdministratorGuard` de `RN-CORE-07` no serializa).

---

## 2026-10-05 · Cierre de #62 (`SESSION_LIFETIME`)

`apps/api/.env.example` y `apps/api/.env` pasan de `SESSION_LIFETIME=120` a `480` (`RN-AUTH-30`, `SessionEnvironmentGuard`), de modo que un entorno nuevo ya no tumba el contenedor `api` al arrancar. Se retira de `compose.yaml` el parche temporal. Verificado recreando la pila (`down` + `up -d`, sin `-v`): los cuatro contenedores `healthy`, `GET /api/health` 200 y `SESSION_LIFETIME=480` dentro de `api`. `SYSADMIN.md` actualizado.

---

## 2026-10-05 · Correcciones #339 y #340 (`REQ-CORE-003`, `REQ-CORE-005`)

`ValidateUserImport` falla el lote (`fallido`) si quien lo subió ya no resuelve (#339, `CA-CORE-299`) y `GenerateUserExport`/`GenerateAuditLogExport` fallan (`fallida`) si el solicitante perdió el permiso `*.exportar` antes de ejecutarse el trabajo (#340, `CA-CORE-298`), ambos por `INV-002`. Sin migración ni cambios de contrato. Pest `Core` 211/211. Detalle en `funcional.md §14.27`.

---

## 2026-10-05 · Batida de issues posterior a `1.9f` (`REQ-CORE-002`, `REQ-CORE-005`)

Tanda de correcciones y cierres del 2026-10-04/05. Sin migración, sin permisos ni dependencias nuevas. Los cambios de código van en la rama `fix/REQ-CORE-ajustes-security-1-9e-1-9b` (los issues #280, #323 y #324 se cierran al mezclarla).

### Corregido
- **#280 (`INV-002`)**: `GenerateUserExport` y `GenerateAuditLogExport` ya no exportan todo el centro si el solicitante no se resuelve al ejecutarse el trabajo: la exportación queda `fallida` con `core.export.generation_failed`, sin fichero (`CA-CORE-296`/`-297`, `REQ-CORE/funcional.md §14.27`, `operacion.md`). Pest `Core` 209/209.
- **#323**: `SettingsView` conserva el resto de `mfa_allowed_methods` que devuelve el servidor y solo alterna `email`.
- **#324**: `SettingsView` no envía enteros inválidos en `session_timeout_minutes` ni `mfa_grace_period_days`; error de cliente `core.settings.errors.notInteger` en es/en/de/fr, el rango sigue en el servidor (`funcional.md §14.9`). Vitest 1043/1043.
- Mezclados antes ese mismo día: PR #335 (`1.9f`), #336, #337 (cierra #331, #253, #322, #239) y #338 (cierra #276 y #326). #332, corregido en #335.

### Cerrados por ya resueltos
#90, #259, #327, #300, #302, #303, #293, #321, #248, #251, #263 y #275.

### Reabierto
- **#62** se cerró por error y está **reabierto**: conserva 4 tareas pendientes reales en su sección «Pendiente». Lección: no cerrar un issue por su título.

### Hallazgos (Baja, abiertos)
- [#339](https://github.com/pirexia/plataforma-educativa/issues/339): `ValidateUserImport` pasa un actor nulo al validador de filas.
- [#340](https://github.com/pirexia/plataforma-educativa/issues/340): una exportación termina `completada` con 0 filas si el solicitante pierde `*.exportar` antes de ejecutarse el trabajo.

### Revisión
`security-reviewer`: sin Crítico/Alto. Pint, Larastan, eslint, `vue-tsc` y `lint:i18n` limpios. Versiones: `README.md` 2.6.14, `PLAN-IMPLEMENTACION.md` 2.3.5, `ARCHITECTURE.md` 2.3.4.

### Documentación
`memory.md` archiva el detalle de 1.9b-1.9f en `docs/historial/1.9b-1.9f-interfaz-core.md`. `PLAN-IMPLEMENTACION.md` y `memory.md` dejan preparado `1.5b`: sin especificación de interfaz todavía, empieza con `spec-writer`.

---

## 2026-10-04 · `1.9f` · migración de las tres tablas de `REQ-AUTH` (`REQ-CORE-002`)

Implementa el sub-paso `1.9f` (`funcional.md §14.13`, `RN-CORE-84`, `-94`, `-95`, `-96`; `OPEN-CORE-54`, `-55` y `-56` = A, A, A, decisión del usuario 2026-10-04). Solo SPA: sin migración, sin cambios de servidor, sin permisos ni dependencias nuevas. **Cierra la serie 1.9b-1.9f de `REQ-CORE`** (interfaz completa).

### Añadido
- **`apps/web/src/data-table`**: filtro `enum` de selección única (`multiple: false`) con valor `initial` de reposo (`RN-CORE-94`, ampliación aditiva de `DataTableEnumFilter`): grupo de opciones exclusivas con «Todos», disparador que reutiliza `dataTable.filters.booleanTrigger`; con `initial`, «sin filtrar» es el valor de reposo (`OPEN-CORE-54`); con `urlState` se ignora `initial` y se avisa por consola (`OPEN-CORE-55`).
- **Migración de las tres vistas** de `REQ-AUTH` al componente de tabla, con lo que la lista de excepciones de `RN-CORE-53` queda **vacía** (`CA-CORE-255`): `MfaExemptionsArea` (filtro de estado, `live` por defecto), `AdminSsoView` y `SessionsView` (tarjetas en pantalla estrecha, tabla en ancha). Acción por fila tras éxito con `refresh()` (`RN-CORE-96`); errores de carga del componente (`RN-CORE-95`), salvo el `401`, que sigue navegando a `login`.
- **Diálogo de confirmación común** (`ConfirmDialog`/`useConfirm`, `RN-CORE-64`) en revocar excepción, eliminar proveedor SSO y cerrar sesiones; nombra al elemento afectado y se cierra con `Esc`.
- Tras conceder una excepción, la tabla vuelve a «página 1, `live`» **re-montando con `key`**, sin `reset()` (`OPEN-CORE-56`).
- Textos en es/en/de/fr (`auth.sessions.*`, `auth.mfaAdmin.exemptions.*`, `auth.ssoAdmin.*`) y guardián `migration19f.i18n.spec.ts`.
- Documentación: `REQ-CORE/funcional.md §14.13` (spec propia de 1.9f, cobertura real), manual `admin.md`, `docs/i18n.md`, `docs/design-system.md`, `ARCHITECTURE.md`.

### Cambiado
- `AdminSsoView` pagina el catálogo de proveedores **de 25 en 25** (antes cargaba todo de una vez).

### Hallazgos
- **`§14.13.7` (Baja, abiertos)**: #330 (el foco cae en `body` tras retirar una fila, WCAG 2.4.3) y #331 (un fallo de `DELETE` muestra `auth.ssoAdmin.loadError`).
- **Revisión independiente (Baja, abiertos)**: #332 (nombre accesible del botón de revocar sin el texto visible en es/de, WCAG 2.5.3), #333 (`revokingId` único y `totalSessions` desfasado con revocaciones concurrentes) y #334 (doble navegación a `login` en `401`).
- **Resueltos para estas vistas**: #90 (guion literal en `SessionsView`) y #259 (`useI18n` directo en `SessionsView`). Avance en #120 (`MfaExemptionsArea.spec.ts` cubre el área de `CA-AUTH-176` en el cliente).

### Revisión
`security-reviewer`: sin Crítico/Alto/Medio. `doc-reviewer`: 3 Media de documentación desincronizada, corregidas. `db-reviewer` no aplica (sin migración). Versiones: `README.md` 2.6.13, `ARCHITECTURE.md` 2.3.4, `PLAN-IMPLEMENTACION.md` 2.3.4.

### Verificado
Vitest **1037/1037** y Playwright **28/28** (verificados por la sesión orquestadora).

---

## 2026-10-03 · `1.9e` · configuración del centro, marca, módulos y perfil propio (`REQ-CORE-002`)

Implementa el sub-paso `1.9e` (`funcional.md §14.9`, `§14.10`, `§14.10b`, `§14.10c`, `§14.26`; `OPEN-CORE-37` = B, `-45` = A). Solo SPA: sin migración, sin cambios de servidor, sin permisos ni dependencias nuevas.

### Añadido
- **`apps/web`**: pantallas `/administracion/centro` (grupos Regional, Fiscal, Paleta y Seguridad —solo las claves `security.*` que `/administracion/mfa` no edita, que son las tres—), `/administracion/marca` (logo, favicon y fondo de acceso), `/administracion/modulos` (solo los contratados, solo lectura) y `/cuenta/perfil` (`core-profile`, autoservicio por identidad; la lista cerrada de `RN-CORE-24` pasa de seis a siete rutas).
- `useSession().updateSessionProfile` (`RN-CORE-89`), catálogo de comunidades autónomas del cliente con test que lo contrasta con el PHP, textos en es/en/de/fr.
- Documentación: `REQ-CORE/{funcional §14.26, api §2, permisos}.md`, manual `admin.md`, `docs/i18n.md`, `ARCHITECTURE.md`.

### Revisión
`security-reviewer` y `doc-reviewer`: sin Crítico/Alto; los Medios documentales se corrigieron. Issues: #321 (Media, `api.md` decía `required_ratio`; corregido), #322, #323, #324 y #326 (Baja, abiertos; #326: pie de paginación de una sola página en `CA-CORE-267`, redacción alineada con el componente).

### Verificado
Vitest **996 pasan, 3 omitidos** (999; las 3 son lecturas cruzadas a PHP que el contenedor `web` no puede hacer; la de `CA-CORE-252` se comprobó aparte, 12/12); `eslint`, `lint:i18n` y `vue-tsc` limpios (reejecutados por la sesión orquestadora). Playwright 27/27 (informe del implementer).

---

## 2026-10-03 · `1.9d` · auditoría y roles de solo lectura (`REQ-CORE-005`, `REQ-CORE-004`)

Implementa el sub-paso `1.9d` (`funcional.md §14.7`, `§14.8`, `§14.25`; `OPEN-CORE-33` = C, `-34` = B, `-35` = A, `-36` = A). Sin migración, sin permisos nuevos ni dependencias nuevas.

### Añadido
- **Servidor**: `GET /audit-logs/facets` (S10, `auditoria.leer`; catálogo declarado en código, sin consultar `audit_logs`) y, en `GET /audit-logs` y `POST /audit-logs/exports`, valores múltiples de `actor_type` y `module` (parte de auditoría de S7; el cuerpo de la exportación los admite como *array* y sigue aceptando el escalar anterior). `AuditCatalog` es la única fuente de los valores filtrables.
- **`apps/web`**: pantalla `/administracion/auditoria` (modo `cursor`, filtros de fecha, operación, tipo de actor, usuario, módulo y entidad, panel «Ver cambios», exportación) y `/administracion/roles` (solo lectura, sin detalle); tipo de filtro **`entity`** en `src/data-table` (aditivo); acción «Ver su actividad» en la ficha de usuario; traducciones en `es`, `en`, `de` y `fr`; `e2e/core-audit.spec.ts`.
- Documentación: `REQ-CORE/{funcional §14.25, api, permisos, operacion}.md`, OpenAPI, manual `admin.md` («Roles del centro», «Consultar el registro de auditoría»), `docs/i18n.md`, `ARCHITECTURE.md`.

### Cambiado
- Los tipos de cliente `AuditEvent` y `AuditActorType` pasan a los nueve y seis valores de `ADR-039`.
- `e2e/shell.spec.ts`: se aplica el formato de Prettier (elimina el aviso preexistente).

### Verificado
Pest completo en el host (`php -d memory_limit=512M ./vendor/bin/pest`): **815 de 830** pasan; los **15 fallos son los mismos de SAML** de 1.9c (`SamlLoginTest` 10, `SamlAssertionValidationTest` 3, `SamlAcsTest` 1, `SamlCertificatesTest` 1; issue #291), ajenos a este diff. Las pruebas nuevas (`AuditLogFacetsTest`, `CA-CORE-245` en `AuditLogsEndpointsTest`) pasan. Vitest 926 pasan y 2 omitidos (928), Playwright **23/23** (ejecutado completo), ESLint sin avisos, `lint:i18n`, `vue-tsc -b`, `npm run build`, Pint y Larastan (con `SESSION_LIFETIME=480`, ver nota del informe) limpios. Reverificado en los contenedores de referencia: Pest `Core` 208/208, Vitest 926 + 2 omitidos, `vue-tsc`/ESLint/`lint:i18n`/Pint limpios. Revisión independiente (`security-reviewer`, `doc-reviewer`; sin Crítico/Alto, `db-reviewer` no aplica): 8 Media de documentación desincronizada corregidas; Baja abiertas #318 (catálogo de módulos incompleto) y #319 (validación de `module` y paridad GET/POST del export).

---

## 2026-10-03 · `fix/REQ-CORE-003-importacion-tope-y-roles` (#313, #314)

Correcciones Media de la importación de usuarios, preexistentes de 1.9b y detectadas en la revisión de 1.9c. Sin cambio de esquema ni de permisos.

### Corregido
- **#313**: el tope `core.import_max_rows` (20 000) se aplica al leer el CSV; por encima, lote `fallido` con `limite_filas_superado` (`CA-CORE-286`).
- **#314**: `rol_no_concedible` se reporta en la fase 1 cuando quien importa no puede conceder un rol (`RPERM-013`), en vez de descartar la fila en silencio al ejecutar (`CA-CORE-287`). `CreateUser::canGrant` comparte regla con `assertActorCanGrant`. Mensajes en los cuatro idiomas.

---

## 2026-10-02 · `feature/REQ-CORE-003-importacion-usuarios` (implementación de `1.9c`)

Implementa el sub-paso `1.9c` (importación de usuarios, `REQ-CORE-003`) y el **catálogo cerrado de tipos de documento de identidad** (`funcional.md §14.6.4`, aprobado el 2026-10-02; issues #292, #308, #309, #310 y #285). Con una migración **de datos** (sin cambio de esquema), sin permisos nuevos ni dependencias nuevas. Notas y desviaciones: `funcional.md §14.23`.

### Añadido
- **Catálogo cerrado `person.document_type`** (`RN-CORE-90` a `-93`): enumerado público `App\Modules\Core\Domain\DocumentType` (`dni`, `nie`, `pasaporte`, minúsculas, sin `otro`); par tipo-número completo; número normalizado al guardar (recorte y mayúsculas; en `dni`/`nie`, sin espacios ni guiones); revalidación del par resultante en `PATCH` (`PersonDocumentRules`, una sola vía para alta y edición). Formato del pasaporte `^[A-Z0-9]{1,32}$` sin dígito de control. Errores nuevos `core.validation.document_type_invalid` y `core.validation.document_incomplete`; en la importación, `tipo_documento_no_valido` y `documento_incompleto` (cuatro idiomas). `enum` de OpenAPI derivado del enumerado (`CA-CORE-279`).
- **Migración de datos** `2026_10_02_100200_normalize_people_document_to_catalog` (entrega N de `OPEN-CORE-52` = A, sin `CHECK`): normaliza tipo y número de las filas existentes; **aborta sin tocar nada** enumerando los `public_id` si hay un tipo sin correspondencia o duplicados creados por la normalización. Usa `pgsql_platform` (el rol propietario no ve filas por RLS `FORCE`).
- **`apps/web`**: pantallas `/administracion/importaciones` (subida, cabecera copiable, lista de tipos de documento admitidos, listado de lotes) y `/administracion/importaciones/:publicId` (seguimiento con la política de `RN-CORE-49`, ejecución con `Idempotency-Key` ULID estable por confirmación, incidencias con el componente de tablas, aviso de las 50 primeras, informe firmado con renovación, descartar); selector del catálogo en el formulario de usuario y etiqueta traducida en la ficha; constante `DOCUMENT_TYPES` con test cruzado contra el enumerado PHP; `apps/web/src/lib/ulid.ts` (ULID propio, sin dependencia).

### Cambiado
- **Cambio de contrato** (`ADR-038 §7`): `POST /users` y `PATCH /users/{id}` **dejan de aceptar texto libre** en `person.document_type` (`422`); incompatible en sentido estricto, sin más clientes que la SPA propia y sin producción. `GET /users`, `GET /users/{id}` y `GET /me` devuelven el código canónico.
- **S8**: `UserImportResource` devuelve `created_at` (`CA-CORE-238`) y, por decisión del usuario del 2026-10-03, `send_invitations` (aditivo; la confirmación de ejecutar lo lee siempre de la API, `CA-CORE-235`). **S9** (`OPEN-CORE-38` = A, #285): el `message` de las incidencias —en `error_summary` y en `report.csv`— sale en el idioma de quien subió el lote (`person.locale` si está activo en el centro; si no, `default_locale`), y el trabajo restaura el idioma del proceso al terminar.
- `UserImportRowValidator`/`ExecuteUserImport`: grafía tolerante del código en la hoja (`OPEN-CORE-50` = A), duplicados sobre el valor normalizado (dentro del fichero y contra la base de datos) y el código y número canónicos al crear.
- Se corrigen **F1 a F7** de `§14.6.4.8` (#308, #309, #310): duplicado por mayúsculas, `PATCH` sin revalidar, número sin tipo, importación que comparaba en crudo, minúsculas con la letra de control desactivada, formato de pasaporte inexistente y *docblock* erróneo de `DocumentNumberValidator`.
- **Revisión independiente de `1.9c` (2026-10-03, sin Crítico/Alto)**: `lockForUpdate()` en la migración de datos (D1), documentada su irreversibilidad a propósito y la excepción a `INV-003` (D2, D3), el `422` de tipo de documento no admitido deja de reflejar el valor recibido (S3), el enlace del informe exige `https:` salvo en desarrollo y lleva `rel="noreferrer noopener"` (S5); manual renombrado a «Importación de usuarios», cabeceras de estado y documentos raíz actualizados (`README.md` 2.6.12, `ARCHITECTURE.md` 2.3.3, `PRIVACY.md` 0.3.4 con §2.6, `SYSADMIN.md` 0.8.4 con §2e, `RUNBOOK.md` 0.3.2 con §3b.6). No se tocan S1 (tope de 20 000 filas) ni S2 (roles no concedibles en silencio), preexistentes de 1.9b.
- **Decisiones del usuario (2026-10-03) sobre `1.9c`**: `accept=".csv"` se mantiene sin excepción nueva en `CA-CORE-192`; sin enlace al manual (remite al apartado por nombre); aviso de las 50 primeras también cuando las entradas llegan al tope de 50 (`RN-CORE-74`); el `CHECK` del catálogo (entrega N+1) queda en el issue #312 y el `CHECK` de par ya existía desde 0.8. Spec corregida en `funcional.md §14.6.1`/`§14.6.2`/`§14.6.4.4`/`§14.11`/`§14.23`.
- `openapi/components.yaml`: cinco descripciones con YAML inválido entrecomilladas (impedían leer el fichero).
- Documentación: `REQ-CORE/{funcional §14.23, api, datos Parte E, permisos §13, operacion §14}.md`, manual `admin.md` (alta y edición, «Importar usuarios»), `docs/i18n.md`.

### Verificado
Pest completo en el host (`php -d memory_limit=512M ./vendor/bin/pest`): **807 de 822** pasan; los **15 fallos son todos de SAML** (`SamlLoginTest` 10, `SamlAssertionValidationTest` 3, `SamlAcsTest` 1, `SamlCertificatesTest` 1), preexistentes desde `1.9b` y ajenos a este diff (issue #291, causa sin diagnosticar). Core, OpenAPI (`CA-CORE-279`) y las 21 pruebas nuevas del paso pasan; las pruebas de OIDC/IdP necesitan el simulador SSO del contenedor `plataforma-api` (`http://localhost:8000`) arrancado. Vitest **879/879**, ESLint sin errores (1 aviso de Prettier preexistente en `e2e/shell.spec.ts`), `lint:i18n`, `vue-tsc -b`, `npm run build`, Pint y Larastan limpios. Playwright **no se ha ejecutado** (sin e2e nuevos). Revisión independiente hecha (`db-reviewer`, `security-reviewer`, `doc-reviewer`; sin Crítico/Alto, hallazgos corregidos arriba); **pendiente de mezcla**.

---

## 2026-10-02 · `feature/REQ-CORE-003-usuarios-invitaciones` (implementación de `1.9b`)

Implementa el sub-paso `1.9b` (usuarios e invitaciones, `REQ-CORE-003`; exportación de usuarios, `REQ-CORE-005`), sobre `docs/modulos/REQ-CORE/funcional.md §14` (aprobada el 2026-10-01). Con una migración *expand*, sin permisos nuevos ni dependencias nuevas. Notas y desviaciones: `funcional.md §14.22`.

### Añadido
- **`POST /api/v1/users/exports`** (S1, `RN-CORE-85`): exportación asíncrona de usuarios en cola (`GenerateUserExport`, `core-exports`) con `CsvWriter`. Fichero de **doce columnas** (`public_id,status,deleted_at,created_at,email,given_name,family_name_1,family_name_2,contact_email,contact_phone,locale,roles`), códigos técnicos y cabeceras sin traducir, iguales para todos los solicitantes (`ADR-055`, `CA-CORE-207` verificado con `CA-CORE-226`). **No contiene `document_type`, `document_number` ni `birth_date`** (`OPEN-CORE-32` = B, `INV-008`). Acepta exactamente los filtros de `GET /users` (como *array*); `q` ⇒ `422` (`core.validation.export_search_not_supported`); tope de filas `CORE_EXPORT_MAX_ROWS`.
- **Migración** `2026_10_02_100100_widen_data_exports_kind_for_users` (S2): `data_exports_kind_check` admite `users` (`NOT VALID` + `VALIDATE`, sin transacción).
- **Regla `InList`** (`App\Support\Api\Rules`): valida cada valor de un filtro de lista por comas (`ADR-038 §5.2`).
- **`apps/web`**: cinco pantallas en `core/shell.ts` (`/administracion/usuarios`, `/nuevo`, `/:publicId`, `/:publicId/editar`, `/administracion/invitaciones`); `alert-dialog` vendorizado y `ConfirmDialog`/`useConfirm` (`RN-CORE-64`); filtro de tabla de **dos estados** y opción `label` del filtro `enum` (ampliaciones aditivas de `OPEN-CORE-40` = A); asignación de roles en la ficha (`OPEN-CORE-43` = A). Textos en los cuatro idiomas.

### Cambiado
- **Cambio de contrato (S4, `OPEN-CORE-39` = A)**: `GET /data-exports/{id}` de una exportación `fallida` responde **`200`** con `status: "fallida"`, `error_code` y `download_url: null`, no `409` (`api.md §8` no cubría la fallida hasta 1.9b). Antes la SPA esperaba 10 minutos sin decir nada. Único cliente: la SPA; sin periodo de compatibilidad (no hay producción, `H0`).
- **`GET /data-exports/{id}` se autoriza por `kind`** (S3, `RN-CORE-86`): `audit_logs` → `auditoria.exportar`, `users` → `usuario.exportar`, otro → `403`; la ruta ya no lleva `permission:` fijo.
- `GET /users`: `sort` admite `-email` (S5) y `locale` admite varios valores por comas (S7). `GET /users/{id}?include_deleted=true` (S6, `CA-CORE-014`, exige además `usuario.eliminar`). `GET /invitations?status=` admite varios valores (S7); un valor fuera del vocabulario responde `422`.
- `README.md` 2.6.11, `ARCHITECTURE.md` 2.3.2, `PRIVACY.md` 0.3.3 (§2.5: la exportación de usuarios como tratamiento), manual `admin.md` (usuarios, invitaciones y la tabla de columnas del CSV), `docs/i18n.md`, `docs/design-system.md §12.3c`, `REQ-CORE/{funcional,api,datos,permisos,operacion}.md`.

### Verificado
Pest completo en el host: **786/801**; los 15 fallos son todos de SAML (`Signature validation failed`), ajenos a este diff, y esos tests pasan en el contenedor de referencia sobre `develop`. Tests nuevos del paso: 16 Pest (`UserExportEndpointsTest`). Vitest **799/799** (717 en `develop`), Playwright **19/19** (4 nuevos), Pint, Larastan, ESLint, `lint:i18n`, `vue-tsc -b` y `vite build` limpios. Pendiente: revisión independiente (`db-reviewer`, `security-reviewer`, `doc-reviewer`).

---

## 2026-10-02 · `fix/REQ-CORE-008-bucle-403-recarga-sesion` (issue #300)

### Corregido
- **Un `403` persistente de un recurso dentro del *shell* ya no entra en bucle** (`REQ-CORE-008`, `CA-CORE-270` nuevo, `funcional.md §12.6`/`§12.3.4`). `fetchMe` (`apps/web/src/session/useSession.ts`) pasaba siempre a `loading`, lo que hacía que `AppShellLayout` desmontara la vista y, al volver a `ready`, esta repitiera el `GET` que daba el `403` (otra recarga de `/me`, sin fin). Ahora una recarga con la sesión ya `ready` no pasa por `loading`; el arranque y la recuperación desde `error`/otros estados siguen mostrando la carga. Sin cambio de contrato.
- Test de regresión con la pila real: `apps/web/src/layouts/AppShellLayout.forbidden.spec.ts` (una petición al recurso, una recarga de `/me`, «Sin acceso» dentro del *shell*).
- **Dos regresiones del fix de #300 (issues #302, #303)**: si la recarga de `/me` tras un `403` responde `401`, la SPA navega ahora a `/entrar?redirect=` reutilizando `handleUnauthorized` (`CA-CORE-271`); y el `RouterView` del *shell* lleva una `:key` derivada de `public_id` y del conjunto de permisos, de modo que la vista se remonta si cambian identidad o permisos pero no en una recarga normal (`CA-CORE-272`).

---

## 2026-10-01 · `feature/REQ-CORE-1.9b-pantallas-de-gestion` (especificación de `1.9b`)

Especificación `REQ-CORE/funcional.md §14` (más `api.md §14`, `datos.md` Parte D, `permisos.md §12`, `operacion.md §13`) de las pantallas de gestión pendientes de `REQ-CORE` y la migración de las tres tablas exceptuadas de `RN-CORE-53`, redactada por `spec-writer`. **Aprobada por el usuario** con la división en cinco sub-pasos (1.9b a 1.9f; `OPEN-CORE-30`) y las respuestas a `OPEN-CORE-31`, `-39`, `-40`, `-42` y `-43`; `OPEN-CORE-32` = B (CSV de usuarios sin documento ni fecha de nacimiento, por minimización, `INV-008`); `OPEN-CORE-31` = B amplía 1.9e con módulos contratados (solo lectura) y perfil propio. `RN-CORE-60` a `-89`, `CA-CORE-208` a `-269`. Nueva pregunta abierta `OPEN-CORE-45` (filas de la pantalla de módulos contratados; bloquea solo 1.9e). Issues abiertos de pasada: #287 (Media), #288 (Media), #289 (Baja). Versiones: `README.md` 2.6.10, `PLAN-IMPLEMENTACION.md` 2.3.3. Solo documentación.

## 2026-10-01 · `docs/ratifica-ADR-055` (`OPEN-054-01`)

`ADR-055` (el CSV de datos como contrato técnico) pasa a **`ACEPTADA`**, ratificado entero por el usuario el 2026-10-01 (opción A ahora, C como ampliación posterior; incluye las precisiones de formatos y compatibilidad y la objeción de `§3.3`). `OPEN-054-01` resuelta: `REQUISITOS` 3.2.11 (índice de ADR y fila de `ADR-054` con remisión), `REQ-CORE/funcional.md §13` (`RN-CORE-59`, `CA-CORE-207` pendiente de test hasta el primer generador nuevo de `1.9b`), `api.md §8` y manual `admin.md`. El fichero del ADR y la fila 3.2.10 de `REQUISITOS` llegaron a `develop` por error dentro del PR #284 (dependencia) como `PROPUESTA`. Solo documentación; sin cambios de código. Versiones: `README.md` 2.6.9, `PLAN-IMPLEMENTACION.md` 2.3.2, `REQUISITOS` 3.2.11. Abierto de pasada: #285 (idioma de `report.csv`, Media).

## 2026-09-30 · `fix/REQ-CORE-005-auditoria-csv-rangos-y-filtros` (issues #266, #267, #270, #273)

Cuatro hallazgos de `REQ-CORE-005` (auditoría y exportación) resueltos en una sola rama, sobre `ADR-054 §8`-`§10` y `ADR-038 §5.2`. Sin migraciones ni permisos nuevos.

### Añadido
- **`App\Support\Csv\CsvWriter` y `CsvColumnType`** (`apps/api/app/Support/Csv/`, #270, `RN-CORE-47`/`48`): única vía de escribir CSV. Esquema tipado (texto, entero, instante; nunca inferido), neutralización de fórmulas solo sobre texto y cabecera con las dos condiciones de `ADR-054 §10.2` (incluido el espacio en blanco inicial seguido de `= + - @`), dialecto de `ADR-054 §10.3`. Test de arquitectura (`tests/Unit/Csv/CsvArchitectureTest.php`): ningún `fputcsv` fuera de ella.
- **`AuditLogFilter`** (`Core/Infrastructure`): los filtros de `audit_logs` en un único sitio, compartido por el listado y por el trabajo de exportación.

### Cambiado
- **Cambio de contrato, sin periodo de compatibilidad (#266)**: `GET /audit-logs` y el cuerpo de `POST /audit-logs/exports` renombran `from`/`to` a `occurred_at_from`/`occurred_at_to` (`ADR-038 §5.2`). Se hace un renombrado directo, sin *expand/contract*, porque no hay producción (`H0` abierto) y el único consumidor es la SPA (`apps/web/src/modules/core/api/auditLogs.ts`, actualizada). Los nombres antiguos se ignoran sin error.
- **`POST /audit-logs/exports` aplica ahora `actor_id`, `actor_type`, `auditable_id` y `module` (#267)**, además de los que ya aplicaba: valida con las mismas reglas que el listado (`IndexAuditLogsRequest::filterRules()`) y los aplica el mismo código. Antes los ignoraba en silencio y el CSV traía más filas que las filtradas en pantalla. El ámbito de `auditoria.exportar` y el tenant siguen acotando el fichero (test con otro tenant y con ámbito `propios`). `ExportAuditLogsPayload` de la SPA se amplía a la par.
- **Formato visible del CSV de auditoría (#270)**: el fichero gana BOM UTF-8 y fin de línea CRLF, y `occurred_at` pierde los milisegundos y pasa de `toJSON()` (`…T..:..:..000000Z`) a ISO 8601 con desfase (`DATE_ATOM`, `+00:00`). Cabeceras y códigos sin cambios (`OPEN-054-01` sigue abierta). Quien procese el fichero por programa debe saberlo.
- **`ValidateUserImport::writeReport` (`report.csv`)** escribe con `CsvWriter`: neutraliza por defensa en profundidad y gana BOM y CRLF.
- **Tope de filas (#267)**: `EloquentExportRequestService::assertWithinRowLimit` usaba las claves `from`/`to` antiguas y no contaba los filtros nuevos; ahora aplica `AuditLogFilter`, así que el `422 export_range_too_large` cuenta exactamente lo que se exporta.
- **Documentación**: `operacion.md §3` (fila «Redis») y `§8` (síntoma «Importación queda en `subido`») describen el *driver* `database` sin *worker* (#128) y Redis/Horizon como elegido, no instalado (#273); `api.md §8`, `funcional.md §13`, OpenAPI (`core.yaml`), `SECURITY.md` 0.3.7 (fila «Exportaciones generadas (CSV)») y `README.md` 2.6.8 (tabla de versiones) y `ARCHITECTURE.md` 2.3.1 (fila «Caché y colas»: Redis solo caché; colas `database` sin worker, #128; Redis + Horizon elegido, no instalado; también en `README.md`, #273).

### Verificado
784/784 Pest (con el servidor de simulación SSO en `:8000`, como en el contenedor de referencia) y 717/717 Vitest en verde; Pint, Larastan (`composer analyse`), ESLint (una advertencia `prettier` preexistente en `e2e/shell.spec.ts`, issue #263), `lint:i18n`, `vue-tsc -b` y Prettier sobre `src/modules/core/api` limpios.

---

## 2026-09-30 · `feature/REQ-CORE-008-tablas-de-datos` (implementación de `1.9`)

Implementa el paso `1.9` (Bloque B, tablas de datos, `REQ-CORE-008`), sobre `docs/modulos/REQ-CORE/funcional.md §13` y `docs/adr/ADR-054-tablas-de-datos-y-exportacion-de-listados.md`. Solo `apps/web`: ni un *endpoint*, ni un permiso, ni una migración, ni una dependencia nueva.

### Añadido
- **Componente de tabla de datos** (`apps/web/src/data-table/`, superficie pública `index.ts`): `@tanstack/vue-table` envuelto en un único fichero (`useTableModel.ts`, `RN-CORE-37`); paginación por página (25/50/100) y por cursor («Cargar más», sin desplazamiento infinito, tope `MAX_CURSOR_ROWS = 1000`, `RN-CORE-52`/`56`); orden de una columna y filtros (búsqueda `q` con espera de 300 ms, enumerado múltiple, rango de fechas, booleano) en servidor, con «solo gana la última respuesta» (`RN-CORE-39`-`42`); visibilidad de columnas con «Restablecer» en `localStorage` (`plataforma.table.<tableId>`, `RN-CORE-43`); vista de tarjetas por debajo de 768 px con desplazamiento interno opcional (`RN-CORE-55`); estado de la consulta en la URL, opcional y sin `q` ni `cursor` (`RN-CORE-54`); estados de carga/vacío/error reutilizando los de `§12.6`; valor vacío común `dataTable.emptyValue` (`OPEN-CORE-27`, issue #90 para las tablas); accesibilidad (`table` nativa, `aria-sort`, anuncios, foco).
- **Disparador de exportación asíncrona** (`RN-CORE-46`/`49`/`51`/`57`): solicita al *endpoint* del módulo dueño, consulta `GET /data-exports/{id}` (una en vuelo, 2 s → 30 s, parada a los 10 min), descarga por enlace firmado y avisa de que el enlace se pierde al salir de la vista; sin generar ficheros en el cliente.
- **Espacio de nombres `dataTable.*`** en los cuatro idiomas (`src/i18n/locales`) y dos claves de `REQ-AUTH` (`auth.mfaAdmin.compliance.tableCaption`, `.resetActionFor`).
- **Tests de arquitectura** (`src/data-table/architecture.spec.ts`): importación única de TanStack, frontera sin `@/modules`, toda tabla por el componente con lista cerrada de tres excepciones que solo puede reducirse (`RN-CORE-53`), `tableId` literal y único, sin ficheros de exportación en el cliente; `CA-CORE-083` (tipografía en `rem`) ampliado a `src/data-table/**`.
- **Playwright** (`e2e/data-table.spec.ts`, tabla de prueba en `e2e/fixtures/tableFixture.ts`): `CA-CORE-177`, `-178`, `-185`, `-203`.
- Documentación: `funcional.md §13.24` (comprobación de la dependencia y notas de implementación), `docs/manual-usuario/admin.md` (filtrar, ordenar, columnas y exportar), `docs/i18n.md`, `docs/design-system.md §12.3b`, `ARCHITECTURE.md §3.3` (2.3.0), `SECURITY.md` 0.3.6, `PRIVACY.md` 0.3.2, `README.md` 2.6.6.

### Cambiado
- **`MfaComplianceArea.vue` migrada** al componente, con paridad estricta (`OPEN-CORE-28`): mismas peticiones (`GET /mfa-compliance/users` con `state` por comas y `page`; ahora también `per_page=25`, el valor por defecto del servidor), columnas, emisión de `reset-user`, tratamiento del `403` y `refresh()`. Gana barra de filtros, paginador completo, tarjetas en móvil y nombres accesibles por fila («Restablecer MFA de Ana López»). Sin estado en la URL, sin corregir el issue #116. El `'—'` literal desaparece (issue #90 sigue abierto para el resto de vistas). Cierra en parte el issue #120.

### Verificado
713/713 Vitest y 15/15 Playwright (10 previos + 5 de 1.9) en verde; ESLint sin errores (una advertencia `prettier` preexistente en `e2e/shell.spec.ts`, issue #263), `lint:i18n`, `vue-tsc -b` y `vite build` limpios. Vitest, ESLint, `lint:i18n`, `vue-tsc` y `build` se ejecutaron en el contenedor de referencia (`localhost/plataforma-educativa_web`) con el *worktree* montado; Playwright con el Chromium del *host* sobre un servidor de Vite propio (la instancia del contenedor sirve la copia principal, no este *worktree*).

### Revisión independiente (2026-09-30)
`security-reviewer` y `doc-reviewer`: sin Crítico/Alto. Corregido en la rama: #275 (Media, `download_url` validada a http(s) en `useExportFlow.ts`, con test de regresión; `CA-CORE-190`). Documentados sin corregir: #276 (`formatDate` con fechas inválidas), #277 (preferencias de columnas sin aislar por usuario, `noreferrer`), #278 (`@tanstack/vue-table` 8.x sin releases desde 2025-04). Recuento final: 715 Vitest y 15 Playwright en verde.

### Hallazgos para el revisor
- **`@tanstack/vue-table` 8.x sin *releases* desde 2025-04-14**; la línea activa es la 9 (estable desde 2026-08-04) con API distinta. No se migra en 1.9; el coste de hacerlo queda acotado a `useTableModel.ts` (`funcional.md §13.24`).
- Dos usos preexistentes de `new Blob(`/`URL.createObjectURL(` en `modules/auth` (metadatos SAML, códigos de recuperación) que no son exportaciones de listados: excepciones nominales y justificadas de `CA-CORE-192`.

---

## 2026-09-30 · `feature/1.9-tablas-de-datos`

Especificación del paso `1.9` (tablas de datos, TanStack Table) y `ADR-054` (aceptada, ratificada por el usuario el 2026-09-30). **Sin código**: la implementación va en una sesión nueva.

### Añadido
- `docs/modulos/REQ-CORE/funcional.md §13` (`RN-CORE-37`-`58`, `CA-CORE-160`-`206`, `OPEN-CORE-18`-`29`) y secciones de 1.9 en `datos.md`, `api.md`, `permisos.md`, `operacion.md`.
- `docs/adr/ADR-054-tablas-de-datos-y-exportacion-de-listados.md` (ACEPTADA) y su alta en la sección 18 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` (3.2.9).
- `PRIVACY.md` 0.3.1: clave `plataforma.table.<tableId>` de `localStorage`. `SECURITY.md` 0.3.5: fila CSV alineada con `ADR-054`.

### Decisiones del usuario (2026-09-30)
Sin virtualización (tope de 1.000 filas acumuladas en modo cursor); tarjetas por debajo de 768 px; columnas configurables = visibilidad + restablecer; `OPEN-CORE-22`-`29` según recomendación.

---

## 2026-09-29 · `fix/REQ-CORE-005-neutralizar-formulas-csv-auditoria`

Corrige el issue [#268](https://github.com/pirexia/plataforma-educativa/issues/268) (severidad alta): la exportación CSV del registro de auditoría escribía con `fputcsv` valores controlados por usuarios (nombre del actor) sin neutralizar celdas que empiezan por `=`, `+`, `-`, `@`, tabulador o retorno de carro, que Excel/LibreOffice/Sheets abren como fórmula activa.

### Corregido
- `GenerateAuditLogExport::neutralizeCsvCell` antepone un apóstrofo a esas celdas, en cabecera y filas (`RN-CORE-36`, `CA-CORE-055`, `docs/modulos/REQ-CORE/funcional.md §4.6`).
- Dos tests de regresión en `AuditLogsEndpointsTest.php`.
- `SECURITY.md` 0.3.3 → 0.3.4 (`README.md` cruzado); `api.md` documenta el apóstrofo para consumidores del CSV.

### Verificado
744/744 Pest en verde. Revisión independiente: `security-reviewer` sin Crítico/Alta; `doc-reviewer` con 4 Media corregidas en esta rama. Cuatro hallazgos Baja (espacios iniciales, helper privado, `ValidateUserImport::writeReport`, robustez de tests) quedan en un issue aparte.

---

## 2026-09-23 · `feature/REQ-CORE-008-layout-navegacion-dashboards`

Implementa el paso `1.8` (Bloque B, *layout*, navegación y panel de inicio, `REQ-CORE-008`), sobre `docs/modulos/REQ-CORE/funcional.md §12` y `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md`. Solo `apps/web`: ni un *endpoint*, ni un permiso, ni una migración.

### Añadido
- **Tres regímenes de *layout*** por ruta (`src/layouts/{PublicLayout,AppShellLayout,BareLayout}.vue`), elegidos en `src/App.vue` a partir de `route.meta.layout`.
- **Registro de navegación** (`ADR-053`): `src/navigation/{types,sections,modules,registry}.ts`; cada módulo declara su `shell.ts` (superficie pública, junto a `api/`, `types/`, `locales/`) con `routes`/`navigation`/`dashboardBlocks`. Las 17 rutas de `auth`, antes en `src/router/index.ts`, se trasladan a `src/modules/auth/shell.ts` con `meta.layout`/`meta.permissions` explícitos; `core` aporta la entrada «Inicio».
- **Estado de sesión en memoria** (`src/session/useSession.ts`, singleton sin Pinia, mismo patrón que la capa B de 1.7): recarga deduplicada ante cualquier `403` que no sea el muro de MFA (`ADR-053 §6`, incluido `module-disabled`), nunca en `localStorage`/`sessionStorage`.
- **Guardas del *router*** (`src/router/{guard,redirect}.ts`): host sin tenant → «centro no encontrado» sin `GET /me`; `401` → `/entrar` con el destino saneado (`RN-CORE-28`, evita redirección abierta); *catch-all* de régimen dinámico según haya sesión o no.
- **Barra superior, navegación lateral/*drawer*/hamburguesa** (componente `sheet` vendorizado) según los *breakpoints* nuevos (`RN-CORE-29`: 768/1024/1440/1920), **miga de pan**, **menú de usuario** (`dropdown-menu` vendorizado: cuenta, selector de idioma, control de modo de color, cerrar sesión).
- **Panel de inicio** (`src/views/HomeView.vue`): bienvenida, aviso de segundo factor pendiente, accesos directos — sustituye la vista de 0.5 que llamaba a `/health` (issue [#86](https://github.com/pirexia/plataforma-educativa/issues/86)).
- **Estados de carga/vacío/error** de nivel de aplicación (`src/layouts/components/{LoadingState,EmptyState,ErrorState}.vue`, `src/layouts/errorState.ts`), con la correspondencia completa de `ADR-038 §6`.
- **`redirect` saneado tras el *login*** (`LoginView.vue`, único cambio funcional permitido a una pantalla de `REQ-AUTH` en este paso) y **`401` genérico centralizado** en `src/api/client.ts` (`CA-CORE-092`).
- **Objetivos táctiles** (`OPEN-CORE-14`, opción B): variante `any-pointer:coarse` en los ocho componentes base de 1.7, sin cambiar el escritorio con ratón.
- Token `--overlay` (`docs/design-system.md §4.9`) para el velo del panel de navegación.

### Verificado
561/561 Vitest, 10/10 Playwright (los cuatro criterios marcados `[Playwright]` de `§12.11` — `CA-CORE-080`, `081`, `084`, `087` — más los dos ya existentes de 1.7), verificados contra el contenedor de referencia (`plataforma-web`) tras fusionar la rama del `implementer`, reiniciando el servidor de desarrollo para descartar caché de Vite obsoleta. ESLint y `lint:i18n` limpios, `vue-tsc -b` y `vite build` sin errores.

### Hallazgo corregido: issue #260
`mfa-enrollment-wall` y el *catch-all* declaran `meta.permissions` vacía por diseño (alcanzables por cualquier usuario autenticado, sin permiso real que exigir sin inventarlo, `INV-002`). La prosa de `RN-CORE-24`/`CA-CORE-103` solo citaba cuatro rutas con esa forma, no las seis reales — corregido en el commit `b11e2c9` (issue [#260](https://github.com/pirexia/plataforma-educativa/issues/260), cerrado).

### Revisión independiente completa
`db-reviewer` no aplica (cero migraciones). `security-reviewer`/`doc-reviewer` sin hallazgos Crítico/Alto. Issue [#261](https://github.com/pirexia/plataforma-educativa/issues/261) (Media: contención de errores de bloques del panel, `ADR-053 §5.2.4` sin mecanismo de código) diferido a propósito por decisión del usuario (2026-09-24) al primer paso que aporte un `dashboardBlocks` real (candidato `REQ-COM`/1.19); [#262](https://github.com/pirexia/plataforma-educativa/issues/262) corregido en el mismo cierre; [#263](https://github.com/pirexia/plataforma-educativa/issues/263) (Baja) documentado sin corregir a propósito.

Detalle completo: `docs/modulos/REQ-CORE/funcional.md §12`.

---

## 2026-09-23 · `feature/1.7-design-system`

Implementa el paso `1.7` (Bloque B, *design system*), sobre la especificación aprobada de `docs/design-system.md` y `ADR-052`. Solo `apps/web`: ni una línea de `apps/api`.

### Añadido
- **Hoja de tokens** propia (`design-system/tokens.css`) en tres niveles (entrada de marca, semántico, utilidad), con los añadidos `success`/`warning`/`info` (y sus `-foreground`), `--primary-on-background` y las duraciones de movimiento de `RUX-005`.
- **Capa A** (`design-system/theme/brandPalette.ts`): aplica la paleta de marca del centro al documento por CSSOM, sin crear ningún `<style>` (compatible con CSP sin `'unsafe-inline'`).
- **Función pura de derivación de contraste** (`design-system/color/deriveOnBackground.ts`, con `color.ts`/`contrast.ts`/`surfaces.ts`): garantiza `--primary-on-background` ≥ 4,5:1 contra **todas** las superficies neutras del modo (`OPEN-DS-01` resuelta: opción A), no solo contra el fondo.
- **Capa B** (`tenant/useTenantBranding.ts`, `tenant/favicon.ts`): única llamadora de `GET /tenant/branding` en la SPA, caché local de los dos colores (nunca URLs), *favicon* del centro, `refresh()` deduplicado y `reportAssetError()` para URLs firmadas caducadas.
- **Modo oscuro** (`design-system/color-mode/useColorScheme.ts`): envoltorio único de `useColorMode` de `@vueuse/core`, tres estados (`system`/`light`/`dark`), persistencia local, ortogonal a la marca. El control visible queda para **1.8** (`OPEN-DS-03` resuelta).
- **Tests de arquitectura** (Vitest, leyendo ficheros con `node:fs`): prohibición de color de marca fuera de `*-primary-on-background`, prohibición de colores literales fuera de la hoja de tokens, frontera del *design system* (qué puede importar `design-system/**` y `components/ui/**`), puntos únicos de entrada (`useColorMode`, `getTenantBranding`, `--brand-`). Más el test de contraste de todos los pares semánticos estáticos en ambos modos.
- **Adaptación de los ocho componentes vendorizados** (`button`, `badge`, `input`, `textarea`, `radio-group`, `select`, `label`, `table`) a las reglas anteriores; `--input` pasa a cumplir 3:1 (`OPEN-DS-02` resuelta: sí, WCAG 1.4.11).
- **Migración de `PublicAuthShell`/`usePublicAuthScreen`** al mecanismo global: la tarjeta deja de recibir la paleta por *prop*/`:style` y hereda el tema del documento.
- **Colores fijos de marca de terceros**: el logotipo de Google en `GoogleSignInButton`/`IdentityProviderLoginList` pasa de hexadecimales literales a tokens `--google-*` (hallazgo no anticipado por el inventario inicial, mismo patrón que preveía §10.3 en abstracto).
- **`prefers-reduced-motion`** como regla global de movimiento.
- **`components.json`** (issue [#251](https://github.com/pirexia/plataforma-educativa/issues/251)): retirada la clave `font` (no usada, sin campo web-font en el proyecto).
- `scripts/check-i18n-literals.mjs` deja de excluir `src/components/ui` y cubre también `src/design-system`; sin claves nuevas de traducción (los componentes base no tienen literales propios, por construcción).

### Verificado
418/418 Vitest, 3/3 Playwright (incluidos los dos criterios que necesitan cálculo real de estilos, `CA-DS-009`/`CA-DS-036`, verificados contra un servidor de desarrollo servido desde el propio árbol de trabajo y no contra el contenedor de referencia — mismo cuidado que `1.6d` ante código servido por dos sitios distintos), ESLint y `lint:i18n` limpios, `vue-tsc -b` y `vite build` sin errores. 46 de los 50 criterios de aceptación (`CA-DS-001`-`050`) tienen test propio que los cita; los cuatro restantes (`CA-DS-047`-`050`) son de verificación procesal (suite completa en verde, `lint:i18n` sin hallazgos y sin claves nuevas, estado de `components.json`, contraste de `--input` — este último sí cubierto por el test de contraste general) y se confirmaron a mano contra el resultado real, no supuestos.

### Corregido (revisión independiente, `doc-reviewer`/`security-reviewer` en paralelo; sin `db-reviewer`, cero migraciones)
- **Media**: `PRIVACY.md` no catalogaba ningún dato en `localStorage` pese a que 1.7 añade dos claves nuevas (`plataforma.brand`, `plataforma.color-mode`) y `plataforma.locale` (0.9) tampoco lo estaba. Nueva §2.1b con las tres, con la clasificación de por qué ninguna es dato personal.
- **Baja**, corregida: cabecera de `ARCHITECTURE.md` sin bump de versión/fecha pese a recibir contenido sustantivo nuevo (§3.1). `README.md` actualizado en las dos filas que este cierre tocó (`ARCHITECTURE.md`, `PRIVACY.md`); el resto de la tabla de versiones cruzada queda fuera de este paso (issue [#255](https://github.com/pirexia/plataforma-educativa/issues/255), pre-existente).
- **Baja**, documentada sin corregir (política `CLAUDE.md §5`): issue [#253](https://github.com/pirexia/plataforma-educativa/issues/253) (`PublicAuthShell.vue`, URL de fondo sin comillas dentro de `url(...)`, no explotable), issue [#254](https://github.com/pirexia/plataforma-educativa/issues/254) (`favicon.ts` sin validar esquema antes de escribir, no explotable, dato ya de confianza), issue [#255](https://github.com/pirexia/plataforma-educativa/issues/255) (cabeceras de versión de documentos raíz desincronizadas de sus tablas cruzadas, tercera recurrencia, pre-existente).
- Sin hallazgos Crítico/Alto en ninguna de las dos disciplinas. Sin discrepancia código↔documento en el núcleo del paso (tokens, capas A/B, modo oscuro, componentes, migración de `PublicAuthShell`, `components.json`, i18n).

Detalle completo, catálogo de tokens y los 50 criterios: `docs/design-system.md`.

---

## 2026-09-22 · `feature/REQ-BO-005-feature-flags`

Implementa el sub-paso `1.6e` (`REQ-BO-005` puntos 1-2, motor de *feature flags*), último de los cinco sub-pasos de `REQ-BO`, sobre la especificación aprobada de `docs/modulos/REQ-BO/funcional.md §15.5`.

### Añadido
- **Evaluador en `REQ-CORE`** (`App\Support\FeatureFlags\{FeatureFlagEvaluator,FeatureFlagExplainer}`, implementación única `EloquentFeatureFlagEvaluator`), precedente literal de `ModuleAvailability`: único código de este sub-paso que corre en el camino de petición de todos los tenants, no solo del backoffice.
- **`FeatureFlagDecisionEngine`**: función pura, un solo algoritmo para evaluación en caliente y explicación administrativa, para que el `matched_by` que ve un operador sea siempre el motivo real.
- **Ocho *endpoints***: siete de gestión en el backoffice (`GET/PUT /feature-flags`, vista previa y reemplazo de reglas, designación de *early adopter*) más `GET /api/v1/feature-flags` en `REQ-CORE`, autorizado por identidad del sujeto.
- **Tres migraciones**: tablas `feature_flags`/`feature_flag_rules`, ampliación del `CHECK` de `admin_action_logs.action` con los valores `flag.*`, y `tenants.early_adopter_since`.
- **`ADR-051` aplicado**: las cuatro rutas de *flag* se direccionan por `key`, no por `public_id`, con registro único (`CatalogKeyRouteParameters`) y su propio test de arquitectura.
- i18n `bo.flag.*` en los cuatro idiomas; OpenAPI de los ocho *endpoints*.

### Corregido (revisión independiente, `db-reviewer`/`security-reviewer`/`doc-reviewer` en paralelo)
- **Alto**: `GET /api/v1/feature-flags` evaluaba sin comprobar sesión (`INV-002`) pese a que el propio código afirmaba seguir el *guard* de `MeController` — no lo tenía. Corregido, con test de regresión.
- **Media**: faltaba el `CHECK (length(btrim(reason)) > 0)` de `feature_flag_rules.reason` que `datos.md §9.3` exige "en el motor". Migración aditiva.
- **Media**: `PUT .../rules` devolvía `affected_tenant_id` como `bigint` interno en el JSON de respuesta (`ADR-029`). Retirado del payload HTTP, conservado solo para `admin_action_logs`.
- **Media**, documentada sin corregir (`OPEN-BO-27`): `rollout_unit: 'user'` deja la vista previa y el bloque `impact` ciegos a las reglas `percentage` — decisión de alcance, no de esta sesión.
- **Media**, sin decidir (issue [#238](https://github.com/pirexia/plataforma-educativa/issues/238)): el registro de `ADR-051` no puede cubrir `permissions.code` por el mismo mecanismo que cubre `modules.code` — `permissions.code` nunca es parámetro de ruta, y el test de arquitectura solo recorre rutas. Ambigüedad real de `ADR-051 §5.3`, comentada en el issue.
- **Baja**, sin corregir por política (issue [#239](https://github.com/pirexia/plataforma-educativa/issues/239)): comentario obsoleto en `routes.php` referenciando una clase inexistente.

741/741 Pest en verde (suite completa, verificada de forma independiente contra el contenedor de referencia — confirma que los 74 fallos vistos por el `implementer` en su *worktree* aislado eran el mismo problema de entorno ya visto en `1.6d`, no una regresión), Pint y Larastan limpios. Detalle completo en `docs/modulos/REQ-BO/funcional.md §15.5.1`.

---

## 2026-09-21 · `chore/cierre-1.6d-memoria-plan`

`ADR-050`: cierre de la prueba de Codex (`ADR-049 §8`). Los tres puntos de medición que fijaba `§8.2` (calibrado sobre PR #204 + `1.6c` + `1.6d`) ya están cerrados:

| Paso | Cobertura (≥2/4) | Precisión (≤1 descartado/aceptado) | Aportación diferencial (≥1) |
|---|---|---|---|
| Calibrado, PR #204 | **0/4 — no cumple** | 3 propuestos, 0 descartados | — |
| `1.6c` (PR #214) | No aplica | 3/3 aceptados, 0 descartados | Sí — issue #224 (Alta) |
| `1.6d` (PR #229) | No aplica | 3/3 aceptados, 0 descartados | No en este paso |

Como `§8.2` exigía las tres a la vez, **el resultado formal es fracaso** — la Cobertura falló en el calibrado y esa medición no se repite. `ADR-050` no declara superada la prueba: documenta el fracaso formal, argumenta por qué el umbral de Cobertura estaba mal diseñado desde el principio (medía "adivinar el pasado", con derecho de veto sobre dos tercios de la evidencia aún sin recoger) y sustituye la regla hacia delante, por decisión expresa e informada del usuario tras ver la tabla completa: *"No lo desinstalamos, lo utilizamos como herramienta extra de verificación"*.

`ADR-050` sustituye `ADR-049 §8` completo y `§8.3` punto 1; conserva `§9` (procedimiento de reversión) íntegro, ahora como retirada **ordinaria** y no como consecuencia de una prueba; no instituye revalidación periódica; deja el uso como permanente y opcional, sin autoridad de bloqueo. Punto abierto para el usuario, no resuelto por `architect`: la política de datos de la capa gratuita de ChatGPT se aceptó "para esta fase de prueba", que ha terminado.

De paso: cierre de `1.6d` en `memory.md`/`PLAN-IMPLEMENTACION.md`, archivado el bloque de `1.6c` a `docs/historial/1.6c-matriz-modulos.md`.

---

## 2026-09-16/21 · `feature/REQ-BO-1.6d-salud-metricas-plataforma`

Implementa el sub-paso `1.6d` (`REQ-BO-004`/`REQ-BO-006` reducidos a lo observable sin `REQ-SAAS`/`REQ-ALUM`/`REQ-SUP`) sobre la especificación aprobada de `docs/modulos/REQ-BO/funcional.md §15.4`. PR [#229](https://github.com/pirexia/plataforma-educativa/pull/229).

### Añadido
- **Ficha de salud del tenant** (`GET /tenants/{public_id}/health`): bloque de trabajos del centro, última incidencia de plataforma (`admin_action_logs` por `affected_tenant_id`, no `failed_jobs` — los trabajos del backoffice no llevan tenant activo, `RN-BO-90`), versión desplegada y migraciones (alcance global), `dependency_inconsistencies` reutilizado del servicio ya existente de `REQ-CORE`.
- **Listado y reintento de trabajos fallidos** (`GET .../failed-jobs`, `POST .../failed-jobs/{uuid}/retry`): nunca devuelven el *payload* ni la traza (`RN-BO-84`), sí el mensaje de la excepción (`OPEN-BO-22`, decisión explícita del usuario). Reintento con capacidad `job.reintentar` y reautenticación viva; `404` uniforme para trabajo inexistente/ajeno/ya reintentado.
- **Métricas de plataforma y de adopción por módulo** (`GET /metrics/platform`, `GET /metrics/module-adoption`): tenants por estado (incluye borrados lógicos, `RN-BO-91`), altas/bajas/eliminaciones como series separadas sin `churn` (`RN-BO-92`), adopción sobre el catálogo declarado (`RN-BO-93`). Ambas, obligatoriamente dentro de `runAsPlatform(BackofficeLectura, …)` — fuera de ese bloque, el agregado sale reducido al tenant activo en vez de fallar (`RN-BO-94`).
- **`bo:purge-failed-jobs`**: arregla el hallazgo Alta de la especificación — `queue:prune-failed` llevaba desde la `0.7` sin poder borrar ni una fila (apuntaba a la conexión sin privilegio `DELETE`), dejando sin aplicar la segunda capa del issue [#73](https://github.com/pirexia/plataforma-educativa/issues/73) (retención de 24h de tokens de un solo uso). Comando propio por `pgsql_platform`, sustituye al del framework en `routes/console.php`.
- `bo:retry-provisioning` corregido de paso: usaba la conexión sin privilegios y un filtro `LIKE` que nunca casaba contra el JSON del *payload* con las comillas escapadas.
- Tres capacidades nuevas en `PlatformCapability` (`salud.leer`, `job.reintentar`, `metrica.leer`).
- `apps/api/openapi.yaml` enlaza ahora las rutas de módulos de `1.6c`, que existían en `platform.yaml` pero nunca se habían indexado (hallazgo propio, corregido de paso).

### Corregido (revisión independiente + `/codex:review`, tercer paso de prueba real de `ADR-049 §8`)
- **P2, `/codex:review`** — `PlatformMetricsController`/`TenantHealthController` no validaban la forma de sus filtros de fecha antes de parsearlos: una fecha mal formada producía `500` en vez de `422`. Corregido con `ShowPlatformMetricsRequest`/`IndexFailedJobsRequest` (mismo patrón que `IndexAuditLogsRequest`).
- **P2, `/codex:review`** — `limit=0` en la paginación de `failed-jobs` producía una respuesta sin filas y sin cursor siguiente, paginación irrecuperable; valores negativos llegaban a la base de datos. Corregido con la regla `integer|min:1|max:200` de las mismas peticiones validadas.
- **Media, `security-reviewer`/`doc-reviewer`** — `docs/modulos/REQ-AUTH/operacion.md`, `docs/modulos/REQ-CORE/operacion.md` y `RUNBOOK.md` seguían describiendo `queue:prune-failed` como una mitigación de datos personales vigente, dos meses después de que dejara de estarlo. Corregido, junto con la vigencia de `SYSADMIN.md`/`SECURITY.md`/`PRIVACY.md`/`README.md` (`CLAUDE.md §6` regla 7).
- **Baja, `doc-reviewer`** — `api.md §5` contaba veinticuatro claves de error sin incluir `bo.metrics.invalid_period`, que el código sí introduce. Corregido a veinticinco.

### Declarado, no corregido en este PR
- **Media, `db-reviewer`** — `tenant_lifecycle_events` y `failed_jobs` sin índice que sirva las consultas nuevas de métricas y de la ficha de salud (*seq scan* completo, sin impacto con el volumen actual). Issues [#230](https://github.com/pirexia/plataforma-educativa/issues/230)/[#231](https://github.com/pirexia/plataforma-educativa/issues/231), migración futura.
- **Baja** — doble comprobación de reautenticación en `TenantHealthController::retry()` (issue [#232](https://github.com/pirexia/plataforma-educativa/issues/232)) y esquema OpenAPI del reintento menos preciso que sus hermanos (issue [#233](https://github.com/pirexia/plataforma-educativa/issues/233)).

Sin ninguna migración. 705/705 Pest de la suite completa en verde, Pint y Larastan (0 errores) limpios — verificado de forma independiente por la sesión orquestadora, no solo reportado.

---

## 2026-09-15/16 · `feature/REQ-BO-002-matriz-modulos-spec`

Implementa el sub-paso `1.6c` (`REQ-BO-002`, matriz de módulos) sobre la especificación aprobada de `docs/modulos/REQ-BO/funcional.md §5.8`/`§7.3.1`/`§13.3.1`.

### Añadido
- `Core\Domain\ModuleCatalog`/`ModuleContracting` (`ADR-045 §4.5`/`§4.8`, `OPEN-BO-18`): lectura del catálogo de descriptores (`depends_on`, `essential`) y escritura de `module_subscriptions` en dos fases (`apply()`/`publish()`, forzado por `ADR-046 §6.4`), implementadas por `DeclaredModuleCatalog` y `ModuleContractingService`. Eventos de dominio `ModuleContracted`/`ModuleDecontracted`, emitidos siempre por `REQ-CORE` (`RMOD-010`).
- `Backoffice\Application\ModuleSubscriptionsService`: capacidades, reautenticación (`OPEN-BO-17`), doble autorización de la descontratación masiva y `Idempotency-Key` de la masiva. Job `RunModuleRollout` (una transacción por centro, orden ascendente de `id`, un fallo no aborta el lote).
- Cinco *endpoints* nuevos del backoffice: `GET /modules`, `GET /tenants/{id}/modules`, `POST /tenants/{id}/modules/preview`, `PUT /tenants/{id}/modules/{code}`, `POST /module-rollouts` y su vista previa.
- `platform:sync-registry` gana tres validaciones que abortan el despliegue sin escribir nada: código de `depends_on` inexistente, ciclo en el grafo, y un esencial que dependa de uno no esencial (`RN-BO-64`).
- Migración de privilegios de `module_subscriptions` (`REVOKE`/`GRANT` de `datos.md §7`/`§7.7`, `OPEN-BO-19` resuelta: el centro no lee `reason`).
- `platform_idempotency_keys` (versión de plataforma de `idempotency_keys`, ver "Corregido" — issue [#216](https://github.com/pirexia/plataforma-educativa/issues/216)).

### Corregido
- **[#215](https://github.com/pirexia/plataforma-educativa/issues/215)** (Alta) — `TenantContext::runAsPlatform(BackofficeEscritura)` enmascaraba con su propio `RuntimeException` de cierre cualquier excepción de negocio lanzada dentro del bloque antes de escribir en `admin_action_logs` — convertía, por ejemplo, un `422` de módulo esencial en un `500` de plataforma. `after()` ahora sólo se invoca en el camino de éxito.
- **[#216](https://github.com/pirexia/plataforma-educativa/issues/216)** (Alta) — la instrucción de reutilizar `RequireIdempotencyKey`/`IdempotencyKey` para `POST /module-rollouts` resultó técnicamente inviable: esa primitiva es de tenant y el backoffice nunca tiene tenant activo. Se creó su versión de plataforma.
- `CloneTenant`/tests existentes ajustados a la conexión `pgsql_platform` para `module_subscriptions`, consecuencia directa de la migración de privilegios (`ModuleSubscriptionsSchemaTest`, `SyncModuleRegistryTest`, `TenantCloneAndIsolationTest`).
- La primera corrección de #215 revertía `ADR-046 §6.5` sin ADR nuevo (hallazgo Alta de `doc-reviewer`) — corregida sin reabrir el ADR: `after()` se sigue llamando siempre, pero su excepción nunca sustituye a la del `callback()`.
- **[#220](https://github.com/pirexia/plataforma-educativa/issues/220)** (Alta, `db-reviewer`) — faltaba `REVOKE DELETE` en `module_subscriptions` para `plataforma_app`, única migración de endurecimiento del repositorio que no lo hacía. Migración propia, no editada la ya desplegada.
- **[#221](https://github.com/pirexia/plataforma-educativa/issues/221)** (Media, `db-reviewer`) — faltaba `PurgePlatformIdempotencyKeys`, que el docblock de la migración de `platform_idempotency_keys` daba por existente. Añadido su comando y su programación diaria, mismo patrón que `PurgeExpiredIdempotencyKeys`.
- **[#223](https://github.com/pirexia/plataforma-educativa/issues/223)** (Media, `db-reviewer`) — faltaba un test *Feature* de extremo a extremo del `PATCH` de ajustes por el camino real de Eloquent.
- **[#224](https://github.com/pirexia/plataforma-educativa/issues/224)** (Alta, `/codex:review`, segundo paso de prueba de `ADR-049`) — `RunModuleRollout::dispatch()` corría dentro de la transacción de `DualAuthorizationService::execute()`, con las tres conexiones de cola en `after_commit => false`: si el `forceFill()->save()` o el `record()` posteriores revertían, el lote de descontratación masiva ya encolado se ejecutaba igual pese a que la autorización quedara `Fallida`. Corregido con `DB::connection('pgsql_platform')->afterCommit(...)`, mismo patrón que `RevokeTenantSessions`.
- **[#225](https://github.com/pirexia/plataforma-educativa/issues/225)** (Media, `/codex:review`, mismo paso) — `RunModuleRollout` contaba un tenant sin cambios reales como `applied` en el resumen del lote. Se cuenta aparte (`unchanged`).
- **[#218](https://github.com/pirexia/plataforma-educativa/issues/218)** (Media, `security-reviewer`) — en el camino de éxito de `runAsPlatform()`, si `after()` lanzaba, `platformMode` quedaba sin restaurar (mismo tramo, ahora en `try`/`finally`).
- **[#227](https://github.com/pirexia/plataforma-educativa/issues/227)** (Media, `db-reviewer`/`security-reviewer`, pasada final) — los arreglos de #224/#225 no tenían test de regresión propio; añadidos, junto con el de #218.

### Documentado, sin corregir (Baja, `CLAUDE.md §5`)
- **[#219](https://github.com/pirexia/plataforma-educativa/issues/219)** — `CloneTenant::cloneModuleSubscriptions()` escribe por `pgsql_platform` (`BYPASSRLS`) sin `runAsPlatform()`: la RLS queda inerte, solo protege el *scope* de Eloquent.
- **[#222](https://github.com/pirexia/plataforma-educativa/issues/222)** — `deleted_at` de más en el `GRANT UPDATE` de `module_subscriptions`, sin camino de escritura que lo alcance.

### Abiertos por error de diagnóstico, corregidos en la propia sesión
- **[#217](https://github.com/pirexia/plataforma-educativa/issues/217)** — se creyó que `updated_by` sobraba en un `GRANT UPDATE`; `db-reviewer` verificó que sí se escribe, por `PATCH /module-subscriptions/{publicId}` vía `RecordsAuthorship`.
- **[#226](https://github.com/pirexia/plataforma-educativa/issues/226)** — 13 tests fallando solo en ejecución conjunta, que parecían un problema de limpieza no acotada en un `afterEach`; era en realidad un *deadlock* real de PostgreSQL por dos *worktrees* de agente ejecutando la suite completa a la vez contra la misma base de test compartida. Lección de infraestructura, no bug de código.

### Segundo paso de prueba de Codex (`ADR-049 §8.2`)
`/codex:review` sobre el diff completo de la rama contra `develop` (65 ficheros, ~4.560 líneas), sin darle los hallazgos ya conocidos. 3 propuestos, 3 aceptados, 0 descartados: #224 (aportación diferencial real, ningún revisor humano lo había visto), #225, y un tercero coincidente con el ya conocido #221. Van dos pasos de prueba reales (calibrado sobre PR #204 + este); falta uno más antes de la evaluación combinada final. Detalle en `memory.md`.

### Documentación
- `docs/modulos/REQ-CORE/funcional.md §7`: `ModuleCatalog`/`ModuleContracting` añadidas a las interfaces públicas, con sus dos eventos.
- `docs/modulos/REQ-CORE/permisos.md`: `modulo.actualizar` documenta que su alcance (`settings`) lo respalda un privilegio de columna, no solo la validación del controlador.
- `SECURITY.md`, `SYSADMIN.md`, `CONTRIBUTING.md` actualizados (aislamiento de módulos por `REVOKE` — incluido `DELETE`—, procedimiento de despliegue, cómo declarar `depends_on`/`essential`).
- `docs/modulos/REQ-BO/datos.md §7`/`§7.2`/`§7.7`, `api.md §2.6.4`: sincronizados con las migraciones reales (`REVOKE DELETE`, `OPEN-BO-19` resuelta) y el campo `unchanged` del resumen de la masiva.

**Verificado**: 673/673 Pest de la suite completa del repositorio, Pint (799 ficheros) y Larastan (642 análisis) limpios.

---

## 2026-09-15 · `fix/REQ-BO-001-condiciones-de-carrera`

Cierra el hilo abierto por `chore/codex-plugin-integracion` (2026-09-14): los tres bugs reales de condición de carrera que `/codex:review` encontró sobre el diff ya cerrado de `1.6b` (issues #205, #206, #207), corregidos con revisión independiente completa.

### Corregido: tres condiciones de carrera en el ciclo de vida de tenants y la doble autorización
- **#205** — `DualAuthorizationService::approve()`/`reject()` comprobaban `guardResolvable()` solo sobre el modelo cargado en memoria, antes de abrir la transacción. Dos resoluciones concurrentes de la misma solicitud podían pasar la comprobación cada una por su lado; la que escribía en último lugar ganaba en silencio, pudiendo dejar una eliminación ya ejecutada marcada como rechazada. Corregido con `lockForUpdate()` + repetir la comprobación dentro de la transacción.
- **#206** — `CloneTenant::cloneModuleSubscriptions()` insertaba con `create()` en un bucle sin idempotencia. Un fallo a mitad del bucle dejaba las filas anteriores comprometidas, y un reintento de `bo:retry-provisioning` violaba `module_subscriptions_tenant_module_unique` sin posibilidad de recuperación. Corregido con `updateOrCreate()`.
- **#207** — `TenantLifecycleService::executeSimpleTransition()` no volvía a comprobar el estado del tenant dentro de su transacción. Dos transiciones concurrentes sobre el mismo tenant podían aplicarse las dos, dejando `tenant_lifecycle_events` con una fila que no partió del estado que dice partir. Corregido con `lockForUpdate()` + recomprobación.

Los tres tests de regresión reproducen el escenario exacto sin hilos reales (dos copias en memoria de la misma fila), y se confirmó explícitamente que cada uno falla sin su arreglo antes de darlo por bueno (revertido y reejecutado uno a uno).

### Corregido: dos condiciones de carrera más, encontradas por la propia revisión independiente
- **#209** (Crítica, `db-reviewer` y `security-reviewer` de forma independiente) — `TenantLifecycleService::executeApprovedDeletion()` tenía el mismo defecto que #207 pero en la ejecución real de la eliminación, el camino más sensible del módulo: leía el tenant sin bloqueo, así que un rescate (`en_baja → activo`) podía colarse entre la comprobación de `RN-BO-20` y el `save()`, ejecutando la eliminación sobre un tenant recién rescatado. Corregido con el mismo patrón `lockForUpdate()`.
- **#210** (Media, `db-reviewer`) — `ExpireDualAuthorizations::handle()` listaba las solicitudes vencidas sin bloquear ninguna fila; una resolución concurrente justo antes de que le tocara el turno quedaba sobrescrita con `caducada`, corrompiendo el rastro de auditoría de una acción destructiva. Corregido con `lockForUpdate()` por fila dentro de su propia transacción. Añadida su primera cobertura de test (no tenía ninguna).

No cubren la ventana exacta de estas dos últimas carreras con un test de regresión: a diferencia de las tres primeras, exigen dos transacciones solapadas de verdad (el defecto está en el hueco entre la lectura y la escritura de un único método, no en dos operaciones públicas independientes), y este arnés de tests no tiene la infraestructura para simularlo de forma fiable — dicho explícitamente en vez de fingir una prueba que no demuestra nada.

### Documentado, sin corregir (Baja, `CLAUDE.md §5`)
- **#211** — `CheckGracePeriodsCommand` tiene el mismo patrón de lectura sin bloqueo, pero su único efecto es un campo informativo sin consecuencia sobre el ciclo de vida real.
- Ausencia de `lock_timeout`/`statement_timeout` en las conexiones `pgsql`/`pgsql_platform` (preexistente, sistémico, no introducido por esta rama): si una transacción que sostiene un `lockForUpdate()` se queda colgada, una petición sobre la misma fila queda bloqueada indefinidamente. No bloquea este merge.

### Documentación: mecanismo de bloqueo de fila documentado por primera vez
`docs/modulos/REQ-BO/datos.md §3.3`/`§6.4` y `permisos.md` ganan la misma nota que ya existía para `RN-BO-11` («esto no se puede expresar con un `CHECK`, se implementa con bloqueo de fila») aplicada a `RN-BO-12`, `RN-BO-19` y `RN-BO-20` — el módulo ya tenía el precedente de documentar este tipo de mecanismo cuando protege una invariante, y no lo había hecho para estos tres.

**Verificado**: 88/88 Pest de `tests/Feature/Backoffice/` en verde, Pint limpio (773 ficheros), Larastan sin errores. Revisión independiente completa (`db-reviewer`/`security-reviewer`/`doc-reviewer`) sobre los tres arreglos originales; los dos adicionales (#209, #210) no pasaron una segunda ronda completa de revisión —se aplicó el mismo patrón ya validado por los propios revisores para el caso análogo—, dicho explícitamente.

---

## 2026-09-14 · `chore/codex-plugin-integracion`

Origen: instalar `openai/codex-plugin-cc` (plugin oficial de OpenAI, Apache-2.0) como segunda opinión de revisión contra la cuota de OpenAI, no la del plan Pro. `ADR-049` (nuevo, ACEPTADA) decide el mecanismo completo.

### Nuevo: `ADR-049` y skill `revision-con-codex`
Solo lectura (`/codex:review`, `/codex:adversarial-review`, `/codex:status`, `/codex:result`, `/codex:cancel`), sin autoridad de bloqueo, sin puerta de revisión automática, prueba acotada y reversible con criterio de éxito/fracaso medido de antemano (`ADR-049 §8`). `RNF-MANT-007` para una herramienta de desarrollo que no es librería queda zanjado: la interfaz propia es el protocolo documentado en la skill, no código.

### Corregido: dos afirmaciones de seguridad falsas, detectadas por el propio Codex
Un `/codex:adversarial-review` (disparado sin querer por un `--help` exploratorio, que resultó ser el propio hallazgo) encontró que `ADR-049 §5.1` y la skill afirmaban una barrera técnica inexistente: el *sandbox* de solo lectura **no impide que `/codex:rescue` escriba** si se invoca — `codex-companion.mjs` fija el modo de *sandbox* por invocación (`--write` → `workspace-write`), y el agente `codex-rescue` añade `--write` por defecto. Corregido en el ADR y en la skill: la única protección real es no invocar `rescue`/`transfer`, sin red técnica. Segundo hallazgo: `--help` no corta la ejecución en los comandos que pasan por `codex-companion.mjs` — dispara una revisión real. Norma nueva: nunca sintaxis exploratoria.

### Corregido: bug conocido de `openai/codex` que dejaba la protección en el papel
El `.codex/config.toml` de proyecto no se aplicaba (issue [#30001](https://github.com/openai/codex/issues/30001) de `openai/codex`, verificado con `codex doctor` y una comprobación de comportamiento real). Corregido con una réplica en `~/.codex/config.toml` (nivel de usuario), verificada.

### Corregido: dos `.claude/worktrees/agent-*` abandonados
Con copias completas del árbol, incluidos los tres `.env` reales y las claves de prueba SAML — triplicaban el material sensible en disco justo cuando se instalaba una herramienta cuyo *sandbox* no puede restringir lectura. Limpiados con permiso explícito del usuario, sin trabajo único (verificado con `diff -rq`, mismo procedimiento que el precedente de `1.6`).

### Hallado: 3 bugs reales de condición de carrera en `1.6b` (issues #205-#207)
Calibrado de `ADR-049 §8.1`: `codex review` sobre el diff ya cerrado de PR #204, sin darle la lista de incidencias conocidas. No encontró ninguno de los 4 hallazgos ya documentados (falla el umbral de cobertura), pero encontró y se verificaron contra el código real tres condiciones de carrera que ningún revisor humano había detectado: doble resolución concurrente de una `dual_authorization` sin bloqueo de fila (#205), `CloneTenant` no idempotente ante fallo parcial —bloquea `bo:retry-provisioning`— (#206), y transición de tenant sin `lockForUpdate()` (#207). Cumple el umbral de aportación diferencial de `ADR-049 §8.2` pese a fallar el de cobertura; evaluación combinada pendiente de los dos pasos de prueba siguientes. Issues abiertos, sin corregir en esta sesión (fuera de su objetivo).

---

## 2026-09-11/14 · Cierre de 1.6b (`REQ-BO-001`, ciclo de vida de tenants)

Segundo de cinco sub-pasos (`1.6c`/`1.6d`/`1.6e` pendientes). `REQ-BO-001` completo sobre el chasis de `1.6`: alta en dos fases, suspensión/reactivación, baja con gracia de 90 días, eliminación con doble autorización, clonación. Cierra el issue [#7](https://github.com/pirexia/plataforma-educativa/issues/7) (invalidación de caché de resolución de tenant al cambiar `status`).

### Nuevo: `ADR-048` — contrato síncrono `TenantProvisioner` en `REQ-CORE`
Decisión de `architect` sobre el código real (2026-09-11), ratificada por el usuario (2026-09-14): `REQ-CORE` declara en su `Domain` el contrato `TenantProvisioner` (`provision()` para el alta, `provisionFromTemplate()` para la clonación), dos objetos de valor (`TenantInitialSettings`, `TenantAdministrator`) y el enumerado `TenantProvisioningOutcome`, implementado por `ProvisionTenantDefaults` (que no se mueve ni se renombra). Descarta el evento de dominio porque el llamador necesita resultado y propagación de fallo. De paso corrige una violación de `INV-007` que la propia especificación de clonación no había nombrado (copiaba `tenant_settings`/`roles`/`permission_role` directamente desde `REQ-BO`).

### Esquema
Migración aditiva pura sobre `tenants` (`suspension_message`, `suspended_at`, `grace_period_ends_at`, `grace_period_expired_at`), ampliación del vocabulario de `admin_action_logs`, corrección de un `CHECK` de la migración de `1.6` (`dual_authorizations_approved_coherence_check` impedía conservar `approved_by` al pasar a `ejecutada`/`fallida`) e índice parcial nuevo en `tenants` para el filtro de período de gracia.

### Revisión independiente (una pasada) y correcciones — 2026-09-14
`db-reviewer`/`security-reviewer`/`doc-reviewer` sobre el diff completo contra `develop`. Un hallazgo **Alta**, coincidente en dos revisores: `POST /dual-authorizations/{id}/approval` y `/rejection` —el momento exacto en que se ejecuta la eliminación de un tenant— no exigían reautenticación pese a que `api.md §4` lo exige explícitamente; corregido añadiendo `require-platform-reauthentication` a ambas rutas, con test negativo nuevo (no existía cobertura, `INV-015`). Hallazgos Media corregidos: índice parcial ausente en `tenants` (issue [#200](https://github.com/pirexia/plataforma-educativa/issues/200)); `ADR-048`/`docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`/`funcional.md §15.2` sin reflejar la ratificación del usuario; `docs/modulos/REQ-CORE/funcional.md` sin documentar la sexta interfaz pública (`TenantProvisioner`) ni las cinco opciones nuevas de `tenant:provision-defaults` —deuda que el propio `ADR-048 §11` ya declaraba—; código de estado equivocado en OpenAPI (`bo.tenant.name_mismatch` documentado como `409`, responde `422`); `README.md`/`PLAN-IMPLEMENTACION.md` desactualizados. Documentados sin corregir (Baja, `CLAUDE.md §5`): issue [#201](https://github.com/pirexia/plataforma-educativa/issues/201) (convención `NOT VALID`+`VALIDATE CONSTRAINT` para futuras migraciones de `CHECK`), [#202](https://github.com/pirexia/plataforma-educativa/issues/202) (un tenant eliminado puede cambiar de nombre/slug), [#203](https://github.com/pirexia/plataforma-educativa/issues/203) (posible hueco de contexto de tenant en `PurgeUnlockTokens`, ajeno a esta rama).

### Bugs reales encontrados y corregidos durante la implementación (issue [#196](https://github.com/pirexia/plataforma-educativa/issues/196))
Al ejecutar la suite completa por primera vez (cortada por límite de cuota antes de verificarse ni una sola vez): `TenantLifecycleService::create()`/`clone()` despachaban su job de fase 2 dentro de `runAsPlatform()` — con cola síncrona (tests) el job heredaba `platformMode=true` de `TenantContext` (singleton de proceso) y sus modelos resolvían a la conexión de plataforma en vez de la de tenant; sin impacto en producción (cola real `database`, *worker* en proceso separado), corregido igualmente moviendo el despacho fuera del bloque. `CloneTenant::cloneModuleSubscriptions()` sin `AuditActor::actingAs('console', ...)` — mismo patrón corregido en otros tres sitios (`ProvisionTenantDefaults`, `RevokeTenantSessions`, `IssueUserInvitation`) pero que se había quedado sin aplicar aquí, violando una FK compuesta. Tres bugs de test enmascarados en cascada por los anteriores: `module_code` inventado (`'comedor'`, no registrado en el catálogo), `early_adopter_since` usado antes de su migración de `1.6e`, comparación de historial sin `orderBy()` y *string* contra *enum*.

**Verificado**: 632/634 Pest en verde (los 2 restantes son `PlatformSchemaGrantsTest`, agotamiento de conexiones ajeno a esta rama — issue [#199](https://github.com/pirexia/plataforma-educativa/issues/199)), Pint limpio (773 ficheros), Larastan sin errores.

---

## 2026-09-08/10 · Cierre de 1.6 (`REQ-BO`, chasis de identidad, autorización y auditoría de plataforma)

Primer sub-paso de cinco (`1.6`/`1.6b`/`1.6c`/`1.6d`/`1.6e`, decisión del usuario del 2026-09-08). Este cierre es solo `1.6`: `REQ-BO-007` completo — identidad de plataforma, MFA propio, lista blanca de IP, sesión corta con reautenticación, auditoría de plataforma — más el trabajo de infraestructura que `ADR-046` exige para separar la superficie del backoffice del producto.

### Nuevo: segundo sujeto de autenticación, `App\Modules\Backoffice`
`platform_admins` (identidad de plataforma, sin `tenant_id`, `RN-BO-01`) y sus cuatro roles internos fijos (`soporte`, `operaciones`, `comercial`, `superadministrador`, declarados en código — `permisos.md §2`); MFA propio de solo TOTP, sin gracia ni exención (`platform_admin_mfa_factors`/`_recovery_codes`/`_challenges`); `platform_ip_allowlist` con tipo `cidr` nativo verificado por el motor; sesión de plataforma sobre `platform_sessions` (driver `database`, `REVOKE ALL … FROM plataforma_app`, `ADR-046 §5`) y `platform_admin_sessions` (dato de negocio, `session_id` sin FK a propósito — `ADR-047 §5.1`); `dual_authorizations` (mecanismo genérico de doble autorización, con su `CHECK` de aprobador distinto en el motor, `RN-BO-19` — sin *endpoint* propio todavía: la primera acción real llega en `1.6b`); `admin_action_logs` (auditoría de plataforma, categoría nueva "plataforma con visibilidad por tenant afectado" de `ADR-047`, `affected_tenant_id`, política `tenant_visibility`, `GRANT SELECT` de seis columnas enumeradas); `tenant_lifecycle_events` (tabla, pieza de esquema para `1.6b`).

Guard `platform` y provider `platform_admins` (`config/auth.php`), grupo de rutas `/api/platform/v1` hermano de `/api/v1` con pila de *middleware* propia y completa (`RequirePlatformHost`, `EnforcePlatformIpAllowlist`, `ConfigurePlatformSession`, `RequirePlatformSessionIdleTimeout`, `ResolvePlatformLocale`, `RequirePlatformMfa`, `RequirePlatformCapability` — ninguno de los tres de tenant). `GET /api/v1/platform-actions` (en `REQ-CORE`, no en `REQ-BO` — `INV-007`) y `GET /admin-action-logs`/`GET /tenants/{id}/admin-action-logs` (en el backoffice), con dos índices y dos codificadores de cursor distintos porque corren con privilegios distintos (`plataforma_app` sin `id` concedido, frente a `plataforma_platform` con `BYPASSRLS`).

### Nuevo: `App\Support\Tenancy` — `runAsPlatform(PlatformAccessPurpose, Closure)`
Cambio de firma de infraestructura compartida (`ADR-046 §6`), no código de `REQ-BO`: propósito declarado sin valor por defecto (`Mantenimiento`, `BackofficeLectura`, `BackofficeEscritura`), ausencia de tenant activo obligatoria con cualquier propósito, y comprobación delegada en `PlatformAccessCheck` (denegado por defecto hasta que `BackofficeServiceProvider` registra la implementación real). Un bloque `BackofficeEscritura` que no deja ninguna entrada en `admin_action_logs` lanza al cerrarse. `AuditRecorder` gana la rama de propósito: lanza con `Mantenimiento`, retorna en silencio con los dos de backoffice. Test de arquitectura ampliado con la aserción de que ningún propósito se calcula en tiempo de ejecución.

### Infraestructura: separación de superficie en `infra/quadlet` (`ADR-046 §4`)
Las reglas de Traefik pasan de `PathPrefix` a `Host(...) && PathPrefix(...)` en `web.container` y `api@.container`; router nuevo `plataforma-api-platform` bajo el host del backoffice, con su propio *middleware* `ipallowlist` — segunda capa de lista blanca, además de la de la aplicación. `install.sh` sustituye `TENANCY_BASE_DOMAIN`, `BACKOFFICE_HOST` y `BACKOFFICE_ALLOWED_IPS` con el mismo mecanismo de sustitución que `__TAG__`. El router de la SPA del backoffice queda para el paso de interfaz, posterior a `1.7`/`1.9` — no hay SPA que servir todavía.

### Esquema
Once migraciones aditivas puras, todas tablas nuevas (sin ciclo *contract*): siete tablas de plataforma pura del chasis; `platform_sessions`/`platform_admin_sessions`; `admin_action_logs`; `tenant_lifecycle_events`. Las once declaradas en `config('tenancy.shared_tables.platform')`. `TenantMigration` gana `platformTable()`/`revokeSequenceAndTable()` (recomendación de `ADR-047 §4.5`, tomada): centraliza el `REVOKE` de tabla y de secuencia que trece tablas escribiendo a mano habría arriesgado olvidar en una.

**Desviación deliberada de `datos.md §2.3`, declarada para revisión**: `platform_admin_mfa_factors` añade `last_used_step` (no listada en la tabla compacta del documento) porque el mecanismo reutilizado (`MfaVerifier`/`ADR-041`) la exige para el anti-repetición de `RN-AUTH-58`; sin ella, un código TOTP capturado sería válido varias veces dentro de su ventana, sobre la cuenta más peligrosa del producto.

### Revisión independiente (dos pasadas) y correcciones — 2026-09-09/10
**Primera pasada** (`db-reviewer`/`security-reviewer`/`doc-reviewer` sobre la implementación inicial): tres hallazgos Alta, corregidos antes de mezclar — (1) issue [#173](https://github.com/pirexia/plataforma-educativa/issues/173): no existía ningún mecanismo de alta de contraseña para `platform_admins` (`bo:create-admin` generaba una contraseña descartada); se añade `platform_admin_invitations` (token de un solo uso, solo su hash persistido), `IssuePlatformAdminInvitation`, `PlatformAdminInvitationRedemptionService` y el *endpoint* anónimo `POST /admin-invitation-redemptions` — no abre sesión, el administrador entra después por `POST /auth/session`; (2) issue [#174](https://github.com/pirexia/plataforma-educativa/issues/174): `RequirePlatformMfa` usaba una lista blanca cerrada de nombres de ruta, contradiciendo `OPEN-BO-13` — pasa a excepción declarada por parámetro (`require-platform-mfa:exento`) en cada ruta, mismo patrón que `RequirePlatformCapability`; (3) issue [#175](https://github.com/pirexia/plataforma-educativa/issues/175): 18 rutas/22 operaciones de `/api/platform/v1/*` sin documentar en OpenAPI (`apps/api/openapi/paths/platform.yaml` nuevo). Dos hallazgos Media corregidos de paso (issues [#176](https://github.com/pirexia/plataforma-educativa/issues/176)/[#177](https://github.com/pirexia/plataforma-educativa/issues/177): `datos.md` documentaba `platform_admin_id` cuando la migración usa correctamente `user_id`; cabeceras de versión de documentos raíz desincronizadas). Dos hallazgos Baja solo documentados (issues [#178](https://github.com/pirexia/plataforma-educativa/issues/178)/[#179](https://github.com/pirexia/plataforma-educativa/issues/179)).

**Segunda pasada**, centrada en la corrección de los tres Alta: un cuarto hallazgo Alta — issue [#180](https://github.com/pirexia/plataforma-educativa/issues/180): el *endpoint* anónimo de canje no tenía ningún límite de tasa, a diferencia del resto de *endpoints* anónimos de la aplicación; se añade límite por IP (`RateLimiter` inline, mismo criterio que `PlatformAuthenticationService::attempt()` — no se reutiliza `Auth\Application\RateLimitGuard` porque su clave exige tenant, y el canje corre sin él). Hallazgos Media: issue [#181](https://github.com/pirexia/plataforma-educativa/issues/181): `PlatformAdminInvitationRedemptionService` leía `config('auth-local.password_min_length')` directamente, incumpliendo `INV-007` — la interfaz `PasswordPolicy` gana `minLength()`; issue [#183](https://github.com/pirexia/plataforma-educativa/issues/183): faltaba `DELETE /api/platform/v1/auth/session` en OpenAPI (23 operaciones reales, no 22); issues [#184](https://github.com/pirexia/plataforma-educativa/issues/184)/[#185](https://github.com/pirexia/plataforma-educativa/issues/185): `SYSADMIN.md`/`SECURITY.md` y esta misma entrada seguían describiendo el chasis como si #173 no se hubiera corregido. Hallazgos Baja/no bloqueantes solo documentados: issue [#182](https://github.com/pirexia/plataforma-educativa/issues/182) (condición de carrera en el canje de un solo uso sin `lockForUpdate()`, patrón preexistente compartido con `Auth`/`Core`, no una regresión de este cierre) e issue [#186](https://github.com/pirexia/plataforma-educativa/issues/186) (ampliar el `CHECK` de `admin_action_logs` sin `NOT VALID` bloqueará en producción cuando la tabla tenga volumen — sin impacto en la migración ya aplicada, guía para la próxima ampliación).

607/607 Pest en verde, Larastan/Pint limpios sobre el estado final completo, tras aplicar las dos pasadas.

### Documentación
`infra/quadlet/plataforma.env.example` (tres variables nuevas), `phpunit.xml` (`BACKOFFICE_HOST` de pruebas). `docs/modulos/REQ-BO/*.md` actualizados con los hallazgos reales de implementación (mecanismo de invitación, orden de *middleware* de `SortedMiddleware`) tras el cierre.

---

## 2026-09-04/07 · Cierre de 1.5 (`REQ-PERM`, núcleo de autorización granular)

### Nuevo: motor de resolución multi-rol completo, sucesor del resolutor provisional de 1.1-1.4c
`App\Support\Authorization` (framework, no módulo — `ADR-044 §4.10`): `Scope` (enum PHP de los seis ámbitos cerrados), `ScopeResolverRegistry` + contrato `ScopeResolver` (un módulo propietario registra el resolutor de su entidad; el núcleo nunca sabe qué es un grupo), `PermissionDecision`/`PermissionSource` (procedencia y motivo de inercia), `ScopedQuery` (API sancionada única de acotación por ámbito) y `PermissionResolver` reescrito: algoritmo exacto de `RPERM-007` — deny ciego al ámbito veta el código entero, `allow` pasa cuatro filtros de inercia (catálogo retirado, módulo desactivado, categoría especial sin `special_data_access`, ámbito sin resolutor), unión de ámbitos supervivientes, `todos` absorbe, conjunto vacío deniega (`RPERM-011`). Memoizado por petición (`scoped()`), sin caché compartida (`ADR-044 §4.7`).

Único resolutor real probado de punta a punta (`ADR-044 §8`): `propios` sobre `auditoria` — un rol con ese ámbito ve solo sus propias entradas en `GET /audit-logs`, con detalle implícito por `auditable_id` (`404`, nunca lista vacía con `200`) y exportación acotada dentro del propio trabajo en cola (`GenerateAuditLogExport`, re-resuelve el ámbito con su propio contexto de tenant).

### Nuevo: CRUD completo de roles personalizados y concesiones, sobre `App\Modules\Core`
`POST /roles` (alta y clonación, `RPERM-005`/`006`), `PATCH /roles/{id}` ampliado (`name`, `special_data_access` con permiso propio `rol_datos_especiales.actualizar` + posesión del atributo), `DELETE /roles/{id}` (`409` si es `is_system` o tiene asignaciones vivas), `PUT /roles/{id}/permissions` (reemplazo completo, orden de validación fijo: forma antes que autorización, existencia del rol lo último). `RPERM-013` pasa a comparar pares (código, ámbito) con `todos` absorbiendo, en los cuatro puntos donde se concede algo (`POST /roles`, `PUT /roles/{id}/permissions`, `PUT /users/{id}/roles`, `POST /users` con `role_ids`).

Dos endpoints de permisos efectivos compartiendo un solo cálculo (`RPERM-009`): `GET /users/{id}/effective-permissions` (administración, permiso nuevo `permiso_efectivo.leer`, solo `administrador_centro`) y `GET /me/effective-permissions` (autoservicio, por identidad, sin permiso — decisión del usuario, 2026-09-04).

### Corregido: `PermissionRole` y `role_user` no dejaban rastro en `audit_logs` (issue [#165](https://github.com/pirexia/plataforma-educativa/issues/165), Alta)
`PermissionRole` pasa a `Auditable` (`Full`). El cambio de roles de un usuario (`PUT /users/{id}/roles`) audita `updated` sobre `user` con `changes.roles.{from,to}` mediante registro explícito (`sync()` no dispara eventos de modelo) — `User::$auditRecordedAttributes` gana `roles` para que no quede redactado como `identifier`.

### Corregido: hallazgo confirmado en `REQ-CORE/api.md §5` (issue [#165](https://github.com/pirexia/plataforma-educativa/issues/165))
`PUT /users/{id}/roles` documentaba desde 1.1 que retirar un rol exige también `asignacion_rol.eliminar`; la ruta solo declaraba `asignacion_rol.crear` y la comprobación no estaba implementada. Corregido en `ReplaceUserRoles`.

### Corregido: test de arquitectura para `TenantContext::runAsPlatform()` (issue [#6](https://github.com/pirexia/plataforma-educativa/issues/6), punto 1)
Mismo mecanismo que el ya existente para `withoutGlobalScope`: falla si `runAsPlatform()` aparece en código real de `apps/api/app/` fuera de tres excepciones verificadas una a una (`TenantContext.php`, `RunsPerTenant.php`, `PurgeExpiredIdempotencyKeys.php`). Los puntos 2 y 3 del issue quedan re-etiquetados a 1.6 (dependen de `platform_admins`/`admin_action_logs`, inexistentes hasta entonces).

### Esquema
Dos migraciones aditivas con tratamiento distinto según el riesgo (`ADR-044 §8`, `OPEN-PERM-06`): `permission_role.scope` a `NOT NULL` + `CHECK` de vocabulario, siete sentencias escalonadas sin bloqueo apreciable (tabla de tenant, RLS, escritura concurrente); `permissions.applicable_scopes` (`jsonb` anulable), `ADD COLUMN` simple (tabla de referencia de ~35 filas, un único escritor).

### Operación
Comando nuevo `perm:grant-role-administration` — el paso de despliegue que más fácil se olvida (mismo patrón que `auth:grant-lockout-permissions` de 1.2): concede a `administrador_centro`, en cada tenant existente, los cuatro permisos que `tenant:provision-defaults` ya siembra en los nuevos.

### Documentación desfasada por este cierre, corregida
`docs/modulos/REQ-CORE/permisos.md` (catálogo, matriz, siembra, la regla «todo es `todos`» reemplazada y no borrada), `docs/modulos/REQ-CORE/datos.md` (`PermissionRole` en el listado de modelos `Full`, `roles` en la lista de inclusión de auditoría de `User`), `docs/modulos/REQ-AUTH/permisos.md` (§5.6, §B.1, §C.7.6 marcadas como cerradas por 1.5), `SYSADMIN.md` (los cuatro pasos de despliegue), `SECURITY.md` (el modelo de autorización deja de ser «booleano por endpoint»).

---

## 2026-09-04 · `chore/alcance-declarado-subagentes`

Origen: se propuso configurar `.claudeignore` para aislar el contexto de agentes de backend y de frontend. Se descartó tras verificarlo empíricamente — `.claudeignore` no es una funcionalidad de Claude Code (solo una *feature request* abierta), y un fichero de prueba en la raíz no bloqueó ninguna lectura. La revisión del alcance realmente declarado de los nueve subagentes destapó lo que sigue.

### Corregido: gobernanza de subagentes
- Ninguno de los nueve declaraba `tools` ni `disallowedTools`, de modo que los nueve heredaban acceso completo. `explorer` decía "no modificas nada" en prosa mientras tenía `Write`, `Edit` y `Bash`; los tres revisores podían editar el código que debían auditar. Es la causa mecánica del issue [#150](https://github.com/pirexia/plataforma-educativa/issues/150) (`git reset`/revert no autorizado sobre trabajo ajeno, dos veces en 1.4c). Ahora: `explorer` solo `Read, Grep, Glob`; los tres revisores sin `Write`/`Edit` (conservan `Bash`: una revisión que no ejecuta nada es una revisión de memoria); `spec-writer` sin `Bash`; `implementer` y `test-writer` con `isolation: worktree`.
- Ninguno declaraba `skills`, así que las diez del proyecto solo se cargaban si al agente se le ocurría invocarlas. Precargadas donde corresponde.
- Ninguno declaraba ámbito de rutas ni recogía las reglas de `CLAUDE.md` §3 sobre relanzamiento tras corte de cuota. Añadidos a los nueve, junto con la prohibición de actuar sobre trabajo ajeno al encargo y de git destructivo.
- **`db-reviewer` apuntaba a `database/migrations`, ruta que no existe.** Son dos: `apps/api/database/migrations` y `apps/api/app/Modules/*/Database/migrations`, donde está el grueso. Añadidas comprobaciones de RLS, `public_id` ULID y convenciones de `ADR-029` que sus puntos 7 y 8 contradecían.
- `implementer` no citaba `INV-003` (auditoría de operaciones), distinto de los campos de auditoría de `INV-005`.
- `test-writer` exigía 80%/95% de cobertura que nadie mide: `ci-api.yml` corre con `coverage: none` en sus tres *jobs* y `vitest.config.ts` no la configura. Umbral retirado.
- `janitor.md` tenía el frontmatter con **YAML inválido desde su creación** (dos puntos sin comillas en `description`). Detectado al validar los nueve con un parser.

### Corregido: `_PLANTILLA` contra los ADR vigentes (issue [#162](https://github.com/pirexia/plataforma-educativa/issues/162), Media)
`docs/modulos/_PLANTILLA/` es del 2026-08-13 y no se había tocado desde entonces; `ADR-029` y `ADR-038` son posteriores. Su `datos.md` autorizaba *"importes en enteros de céntimos **o decimal exacto**"* y *"fechas en UTC"*, cuando `ADR-029` exige céntimos enteros y `TIMESTAMPTZ` — la misma redacción laxa que arrastraba `db-reviewer`, señal de que uno se copió del otro y el ADR posterior dejó obsoletos a los dos. Reescritas ambas plantillas; `doc-reviewer` gana una comprobación 11 para que las plantillas se revisen como el resto de la documentación.

### Corregido: causa raíz de un incidente de 1.2b
`worktree.baseRef` no estaba configurado y su valor por defecto (`"fresh"`) ramifica desde la rama por defecto **del remoto**, no desde el trabajo en curso. Es exactamente lo que ocurrió en 1.2b, cuando dos de tres agentes lanzados con aislamiento se crearon desde un commit de meses atrás y corrieron más de una hora así. Fijado a `"head"`. Añadido `.worktreeinclude` con los tres `.env`, sin los cuales un subagente aislado no puede arrancar la API ni ejecutar la suite.

### Anotado, no ejecutado
`1.7b` en `PLAN-IMPLEMENTACION.md` (issue [#163](https://github.com/pirexia/plataforma-educativa/issues/163), Baja): estandarización de módulos por tests de arquitectura con Pest `arch()` + generador `make:module`, en lugar de una plantilla de código maximalista. Deliberadamente posterior a 1.5 y 1.7, que definen la matriz de permisos y la forma del módulo de frontend.

Sin cambios en código de aplicación.

---

## 2026-09-02/04 · Cierre de 1.4c (`REQ-AUTH-004`, parte 2/2: SSO institucional SAML 2.0)

### Nuevo: SAML 2.0 como segundo protocolo del catálogo `identity_providers`
`architect` reevaluó en vivo, al abrir el paso, la comparación de bibliotecas SAML PHP que `ADR-043 §7.3` había dejado sin recomendación firme: dos de sus cuatro observaciones resultaron equivocadas (`litesaml/lightsaml` tuvo un salto de autenticación real por *XML Signature Wrapping* en 5.0.0 sin aviso publicado en Packagist ni GHSA; `simplesamlphp/saml2` v6 abandonó `xmlseclibs` por una biblioteca propia con escrutinio externo casi nulo). Recomendación resultante y decisión del usuario: **`SAML-Toolkits/php-saml` 4.x**, envuelta tras interfaz propia (`RNF-MANT-007`), nunca `OneLogin\Saml2\Auth` directamente.

Ocho decisiones más del usuario (2026-09-02, `ADR-043 §10.9`, todas siguiendo la recomendación): catálogo con discriminador `protocol` + tabla hija `saml_identity_provider_settings` (1:1, sin mover las columnas OIDC de 1.4b); excepción de CSRF acotada a un grupo de rutas propio solo para el ACS, nunca una lista global; **sin SSO iniciado por el IdP** (precondición de seguridad de esa misma excepción: sin petición previa que correlacionar, sería *login CSRF* sin mitigación); objeto de valor propio para la identidad SAML (no reutiliza `ExternalIdentity`, que depende de `email_verified`, inexistente en SAML); clave de firma del SP única de plataforma, por fichero montado y variable de entorno, nunca en base de datos; MFA propio nunca exento aunque el IdP declare su propio segundo factor; sin intermediario externo (Keycloak/Authentik). Siete preguntas más de `spec-writer` al detallar la especificación (`OPEN-AUTH-42`-`48`), todas resueltas por el usuario siguiendo la recomendación.

Correlación de la petición SAML en servidor (`saml_auth_requests`, consumo atómico de un solo uso), gestión y rotación de certificados de firma del IdP con retirada manual, obtención de metadatos por URL (reutilizando las cinco guardas SSRF de 1.4b) o XML pegado. Aprovisionamiento solo por emparejamiento, igual que OIDC — ya impuesto por el `CHECK` de `identity_providers.provisioning_mode` desde 1.4b, no una decisión nueva de este paso.

### Corregido
- **Media** (revisión independiente `db-reviewer`): `identity_provider_certificates_tenant_provider_fingerprint_unique` medía 65 caracteres — PostgreSQL lo habría truncado en silencio al límite de 63. Renombrado a `...tenant_provider_fp_unique`.
- **Media** (revisión independiente `db-reviewer`): el índice de purga de `saml_auth_requests` solo cubría la mitad de la condición real de la consulta (filas caducadas sin consumir); la rama de filas ya consumidas —es decir, todo login SSO SAML exitoso— se quedaba sin índice de apoyo hasta purgarse, mismo patrón que motivó los issues #118/#119 de `1.3b`. Añadido el índice que faltaba.
- **Alta** (revisión independiente `security-reviewer` y `doc-reviewer`, coincidente): el ACS —la única ruta sin CSRF de la aplicación— no tenía ningún test ejecutándolo de verdad, pese a que el código citaba varios criterios de aceptación como si estuvieran verificados. Issue [#152](https://github.com/pirexia/plataforma-educativa/issues/152).
- **Alta** (revisión independiente `doc-reviewer`): `SECURITY.md` remitía a una `§2.1` inexistente para la excepción de CSRF del ACS. Añadida.
- **Alta** (revisión independiente `doc-reviewer`): `apps/api/openapi.yaml` no registraba los cinco endpoints nuevos del paso (los esquemas existían en `openapi/paths/sso.yaml` pero no estaban enlazados). Corregido.
- **Media** (revisión independiente `doc-reviewer`): `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` seguía describiendo `REQ-AUTH-004` con aprovisionamiento por creación automática ("*Just-in-Time provisioning*"), contradiciendo la decisión de solo-emparejamiento tomada en `1.4b`.
- **Alta** (escribiendo los tests que pedía el hallazgo anterior, issue [#155](https://github.com/pirexia/plataforma-educativa/issues/155)): el IdP SAML simulado (`FakeSamlIdentityProviderController`) nunca firmaba el `<samlp:Response>`, solo la `Assertion` interna — ningún login SAML simulado podía completarse con éxito. Causa raíz añadida al investigar: el material de firma se cacheaba con `Cache::rememberForever()` bajo `CACHE_STORE=redis` forzado solo en `phpunit.xml`, pero el flujo cruza el proceso de Pest y el `artisan serve` persistente del contenedor, cada uno con su propia clave — mismo patrón de hueco de entorno que ya costó tres capas de fallo en CI durante `1.4b`. Corregido firmando también el `Response` y pasando el material de firma a un fichero compartido.
- **Alta** ([#156](https://github.com/pirexia/plataforma-educativa/issues/156)): `PATCH /identity-providers/{id}` nunca validaba ni aplicaba ningún campo exclusivo de SAML (`sign_authn_requests` incluido) — `UpdateIdentityProviderRequest::targetProtocol()` comparaba `is_string()` contra un valor que Eloquent ya devolvía convertido al enum `Protocol`, siempre falso. Corregido.
- **Media** ([#157](https://github.com/pirexia/plataforma-educativa/issues/157)): `datos.md §G.5` afirmaba que la huella del certificado del IdP sí se registraba en `audit_logs`, citando en la misma frase dos secciones de `ADR-043` que dicen justo lo contrario. Era `datos.md` el equivocado, no `funcional.md`/`RN-AUTH-127`. Corregidos el modelo (`fingerprint_sha256` a `$auditSecretAttributes`) y el texto de `datos.md`.
- **Media** (hallazgo de test, sin issue propio — infraestructura, no producción): `CA-AUTH-354` (vínculo de MFA pendiente) fallaba por `Illuminate\Session\DatabaseSessionHandler::$exists`, una bandera de instancia reutilizada entre peticiones de sesiones distintas dentro del mismo test, que convertía en silencio el `INSERT` de la sesión del ACS en un `UPDATE` de cero filas. Corregido con `resetSessionState()` en el *helper* de test, sin tocar producción.
- **Media** (segunda pasada `doc-reviewer`): cuatro de los cinco documentos de la Parte G (`datos.md`, `permisos.md`, `operacion.md`, `api.md`) seguían con la cabecera "especificada y pendiente de aprobación" desde el commit inicial de la especificación, pese a que `funcional.md` ya decía `APROBADA`. Alineadas las cuatro.
- **Media** (segunda pasada `doc-reviewer`): la nota de `memory.md` § "Trabajo en curso" afirmaba que los issues #152/#155/#156/#157 seguían sin cerrar cuando ya lo estaban. Corregida.
- **CI, tres hallazgos que ninguna ejecución local repitió** (mismo patrón que "pasa en local no es pasa en CI" de `1.4b`): `Análisis estático (Larastan)` con 131 errores porque `_ide_helper_models.php` se había regenerado sin `-M`/`--write-mixin` en algún commit anterior del paso, huérfanos las clases `IdeHelperX` que docenas de modelos ajenos al módulo referencian por `@mixin` — regenerado correctamente, 4 errores reales que quedaron (comprobaciones redundantes por tipos de PHPDoc, un `match` no exhaustivo sobre `email_claim`, ahora nullable desde este mismo paso) corregidos uno a uno. `Lint (Pint)` con 3 ficheros de test de `test-writer` que nunca se habían pasado por Pint completo. `Trivy` con las 5 CVE de `fast-uri`/`qs` (issue [#159](https://github.com/pirexia/plataforma-educativa/issues/159), transitivas de `shadcn-vue`) bloqueando el *check* obligatorio — resuelto de verdad (no solo documentado como Baja) moviendo `shadcn-vue` a `devDependencies`, que Trivy excluye por defecto.

### Diferido a propósito (issues abiertos)
[#153](https://github.com/pirexia/plataforma-educativa/issues/153) (Baja): nombre de FK de `1.4b` (`identity_provider_secrets`) también truncado a 63 bytes por PostgreSQL, hallazgo colateral de la revisión de `1.4c`, sin código de aplicación que lo referencie hoy · [#154](https://github.com/pirexia/plataforma-educativa/issues/154) (Baja): aviso de expiración de certificados SAML en el manual de administrador más vago que el resto del propio documento · [#158](https://github.com/pirexia/plataforma-educativa/issues/158) (Media, segunda pasada `security-reviewer`): la guarda anti-SSRF de `SsrfSafeFetcher` sí se repite en cada salto de redirección (verificado contra el código real, sin vulnerabilidad), pero `CA-AUTH-320` se queda sin test que lo ejerza, mismo hueco de cobertura ya existente desde `1.4b` para su hermano `CA-AUTH-263`.

### Revisión independiente
Dos pasadas de cada uno. **Primera pasada**: `security-reviewer` 1 Alta bloqueante (tests del ACS ausentes, issue #152, coincidente con `doc-reviewer`); `db-reviewer` 2 Media; `doc-reviewer` 3 Alta bloqueantes y 2 Media — la coherencia código↔documentación del propio módulo `REQ-AUTH` no tuvo hallazgos, todo lo bloqueante fue vigencia de documentos raíz y OpenAPI. Al escribir los tests que cerraban el hallazgo de #152, `test-writer` encontró y dejó documentados tres bugs reales más (#155, #156, #157, detalle arriba), corregidos por la sesión orquestadora. **Segunda pasada**, sobre el código ya corregido: `db-reviewer` sin hallazgos nuevos; `doc-reviewer` 2 Media (cabeceras y `memory.md`, detalle arriba); `security-reviewer` sin hallazgos Crítico/Alta, 1 Media no bloqueante (#158).

Backend: **506 tests Pest en verde** (`php -d memory_limit=512M ./vendor/bin/pest`, issue #106), incluidos los 56 que referencian `CA-AUTH-311` a `366` contra el ACS real. Frontend: dos pantallas ampliadas (`/administracion/sso`, `/administracion/sso/{public_id}`), ninguna nueva — **118/118 tests Vitest en verde**, `pint`/Larastan/`eslint`/`lint:i18n`/`vue-tsc` limpios, 14/14 *checks* de CI en verde. Mezclado a `develop` vía PR [#160](https://github.com/pirexia/plataforma-educativa/pull/160). Detalle completo en `docs/historial/1.4c-sso-institucional-saml.md`.

---

## 2026-09-01/02 · Cierre de 1.4b (`REQ-AUTH-004`, parte 1/2: SSO institucional OIDC y aprovisionamiento por emparejamiento)

### Nuevo: catálogo de proveedores OIDC por tenant, login institucional, aprovisionamiento por emparejamiento
`ADR-043` divide `REQ-AUTH-004` en dos pasos tras la evaluación previa de `architect`: SAML rompe a la vez el mecanismo de sesión del *callback* (`SameSite=Lax` no acompaña un `POST` entre sitios), el envoltorio de la dependencia (`ExternalIdentity` está construido sobre `email_verified`, que SAML no tiene), el perfil de riesgo (verificado contra Packagist: patrón recurrente de fallos de validación de firma en las bibliotecas PHP candidatas) y el ciclo de vida del certificado — ninguno de los cuales afecta a OIDC. `1.4b` construye el modelo completo (catálogo, aprovisionamiento, auditoría); `1.4c` (posterior) solo añade el protocolo SAML sobre él.

Catálogo `identity_providers` por tenant, en autoservicio del propio administrador del centro (Azure AD/Entra ID, Google Workspace con verificación del *claim* `hd`, o cualquier emisor conforme a OIDC) — sin ningún paso manual de operador, a diferencia de Google (1.4). Validación del documento de descubrimiento con cinco guardas contra SSRF (esquema, rango de IP privada/reservada, `CURLOPT_RESOLVE` para cerrar el TOCTOU de DNS, límite de redirecciones, *timeout*), revalidadas en cada salto. Credencial de cliente por tenant cifrada en tabla propia (`identity_provider_secrets`) con la clave de aplicación, con ventana de rotación sin corte de servicio y aviso de caducidad a 30 días. `user_identities` re-tecleada por proveedor concreto en vez de por protocolo (`ADR-043 §3.6`): la clave de `1.4` asumía que `provider` identificaba al emisor, falso en cuanto un centro puede tener más de un IdP institucional a la vez.

Tres decisiones del usuario (2026-09-01), las tres siguiendo la recomendación de la especificación: **aprovisionamiento solo por emparejamiento** con una `Person`/`User` ya existente en el censo, nunca crea cuentas nuevas — un directorio institucional contiene también alumnado, y crear automáticamente sin conocer la fecha de nacimiento incumpliría `INV-008` (`ADR-043 §4.1`, `OPEN-AUTH-38`); **credencial cifrada en tabla propia**, no en gestor externo (coherente con `ADR-037 §7`); **configuración del IdP en autoservicio del centro**, no del operador de la plataforma. Consecuencia aceptada y documentada: la tercera línea del requisito ("mapeo automático de atributos... a campos de usuario") queda cubierta solo en su mitad de identidad — el mapeo resuelve quién es la persona, no escribe sobre `people`, porque escribir encima de datos ya puestos por el centro es lo que `RN-AUTH-88` prohíbe desde 1.4.

Mismas comprobaciones que el login local, sin excepciones (`RN-AUTH-111`): bloqueo, estado de cuenta (`pendiente` no entra por SSO, decisión del usuario), `MfaPolicy` completo. 9 endpoints nuevos y 2 modificados, 4 permisos nuevos (`proveedor_identidad.*`, solo `administrador_centro`), 2 tareas programadas (refresco de descubrimiento, aviso de caducidad de credencial). Emisor OIDC simulado (`FakeOidcIssuerController`, dos barreras contra producción) para desarrollo y tests. Especificación aprobada en `docs/modulos/REQ-AUTH/*.md` Parte F.

Backend: 451 tests Pest en verde (`php -d memory_limit=512M ./vendor/bin/pest`, issue #106), incluidos 47 que referencian `CA-AUTH-260` a `310` contra el emisor simulado por HTTP real. Frontend: 5 pantallas (botones en `/entrar`, `/entrar/sso`, catálogo y alta/edición en `/administracion/sso`, bloque ampliado en `/cuenta/seguridad`), 106 tests Vitest en verde, `eslint`/`lint:i18n`/`vue-tsc` limpios. Verificado en navegador real (Playwright MCP) con el emisor simulado.

### Corregido
- **Alta** (revisión independiente `db-reviewer`): la migración de re-tecleado de `user_identities` (`2026_09_01_100500`) creaba los cinco índices y validaba la `FK`/los `CHECK` dentro de la transacción por defecto, bloqueando lecturas y escrituras sobre una tabla viva desde `1.4` durante todo el recorrido. Reescrita con `$withinTransaction = false` y el patrón `CREATE INDEX CONCURRENTLY`/`NOT VALID` + `VALIDATE CONSTRAINT` incondicional, igual que el precedente ya establecido en `2026_08_31_100100_add_purge_indexes_to_mfa_tables.php` (issues #118/#119).
- **Media** (revisión independiente `db-reviewer`): el nombre nuevo de dos índices insertaba un sufijo `_null` en medio del nombre antiguo, rompiendo la subcadena que `GoogleOAuthCallbackService` (código ya desplegado desde 1.4, sin cambios propios de este paso) usa para distinguir el motivo de un rechazo — durante la ventana de un despliegue continuo, una instancia antigua habría mostrado el mensaje equivocado. Corregido moviendo el sufijo al final del nombre, sin tocar código de aplicación salvo un comentario explicativo. El comentario de cabecera de la propia migración, que además contradecía a su propio `up()` sobre cuándo se retiraban los índices antiguos, también corregido.
- **Baja** (revisión independiente `db-reviewer`, defensa en profundidad): ningún `CHECK` impedía `link_method = 'fusion_automatica'` con `identity_provider_id` informado — solo lo evitaba el código de aplicación, contradiciendo la afirmación de `datos.md §F.8` de que nada vive solo en la aplicación. Añadido `user_identities_fusion_no_provider_check`, simétrico al ya existente para `google`.
- **Media** (issue [#147](https://github.com/pirexia/plataforma-educativa/issues/147)): las cinco pantallas nuevas o ampliadas (~1700 líneas) no tenían ningún test Vitest propio — mismo patrón débil ya documentado en 1.2/1.2b/1.3 (issues #116/#120), esta vez con mucho más código sin cubrir, y al menos `CA-AUTH-269` ("la pantalla no pinta ningún botón") solo verificado a nivel de contrato de API, no de pantalla. 60 tests Vitest nuevos, incluida paridad de traducciones en los cuatro idiomas.
- **Media** (issue [#148](https://github.com/pirexia/plataforma-educativa/issues/148), hallazgo al escribir los tests del punto anterior): seis de los trece mensajes del *callback* institucional reutilizaban literalmente las claves de `GoogleCallbackResultView.vue` y mencionaban "Google" explícitamente, aunque el usuario acababa de entrar por el proveedor de su centro. Nuevo juego neutro `auth.ssoCallback.*` en los cuatro idiomas, decisión del usuario: texto genérico, sin interpolar el nombre del proveedor.
- **Alta** (revisión independiente `doc-reviewer`): `CHANGELOG.md` sin la entrada de cierre de `1.4` (esta misma entrada, en el ciclo anterior) seguía describiendo su PR como "abierto, sin mezclar todavía" pese a estar mezclado desde `b19f29b` — recurrencia del patrón que motivó `CLAUDE.md §6.7`; corregida junto con la tabla de versiones de `README.md` (`docs/REQUISITOS-...md` en `3.1.2` cuando esta misma rama la subió a `3.1.3`) y el recuento de ADR (`32` cuando ya son `43`).
- **Alta** (revisión independiente `doc-reviewer`): `SECURITY.md` seguía listando SSO/OIDC como pendiente; `PRIVACY.md` sin el flujo de datos del IdP institucional (nuevo §2.3) y con una frase desactualizada sobre el aprovisionamiento automático; `docs/manual-usuario/admin.md` sin ninguna mención del autoservicio nuevo (`/administracion/sso`) pese a que el manual ya existe y cubre funciones de configuración de centro comparables.
- **Media** (revisión independiente `doc-reviewer`): `operacion.md §F.2.1` no listaba `AUTH_SSO_TOKEN_TIMEOUT_SECONDS`, pese a ser una variable real ya documentada en `SYSADMIN.md`; la entrada `OPEN-13` del índice de decisiones abiertas no mencionaba que `1.4b` también queda bloqueada por ella (fotografía del mapeo de atributos).

### Diferido a propósito (issues abiertos)
[#145](https://github.com/pirexia/plataforma-educativa/issues/145) (Baja): `people.locale` sin `CHECK` y con `DEFAULT` fuera del conjunto admitido — columna de `REQ-CORE`, detectada al escribir la especificación de este paso, no la agrava (`1.4b` no escribe sobre `people`) · [#146](https://github.com/pirexia/plataforma-educativa/issues/146) (Baja): `php artisan serve` de un solo hilo interbloquea el alta desde el navegador de un proveedor auto-referenciado al propio servidor de desarrollo — no afecta a producción (FrankenPHP) ni al uso real.

### Revisión independiente
`security-reviewer`: sin hallazgos Crítico/Alto, los doce puntos de atención verificados contra código real (SSRF, cifrado de credencial, aislamiento de tenant, sin creación automática, resolución de tenant desde sesión, restricción por dominio, MFA sin excepción, etc.). `db-reviewer`: 1 hallazgo Alta bloqueante y 2 Media, todos corregidos antes de mezclar (detalle arriba). `doc-reviewer`: 5 hallazgos Alta bloqueantes y 2 Media, todos corregidos antes de mezclar — la coherencia código↔documentación del propio módulo `REQ-AUTH` no tuvo ningún hallazgo; todo lo bloqueante fue vigencia de documentos raíz y manual de usuario.

---

## 2026-09-01 · Cierre de 1.4 (`REQ-AUTH-002`: login con Google y fusión de cuentas)

### Nuevo: login federado con Google, fusión, vinculación y desvinculación
OAuth2 con PKCE `S256`, seis endpoints (descubrimiento, arranque, *callback*, `GET /auth/mfa-challenges` sobre un recurso de 1.3, autoservicio de vínculos). Fusión automática de cuenta **solo** con `email_verified = true` normalizado por lista blanca estricta en un único punto (`SocialiteGoogleIdentityProvider`, `ADR-042 §4.4`); sin verificación, salida indistinguible de "no hay cuenta" (`RN-AUTH-87`, evita el oráculo de enumeración). Ningún usuario se crea a partir de un login federado — interpretación restrictiva decidida el 2026-08-31 (`RN-AUTH-99`, `OPEN-AUTH-31`), el aprovisionamiento automático queda para `1.4b`. El login federado pasa por las mismas comprobaciones que el local y en el mismo orden (bloqueo, estado de cuenta, `MfaPolicy` completo) sin saltarse ninguna. Ningún *token* de Google se persiste. Tres pantallas: botón "Continuar/Vincular con Google" (solo visible si el proveedor está configurado, `RN-AUTH-98`), `/entrar/google` (destino del *callback*, reutiliza el paso 2 de MFA ya existente), bloque "Cuentas vinculadas" en `/cuenta/seguridad`. `ADR-042` aprueba `laravel/socialite ^5.30` tras interfaz propia (`IdentityProvider`), único fichero autorizado a importar `Laravel\Socialite\*`. Proveedor simulado (`AUTH_OAUTH_DRIVER=fake`, dos barreras contra producción) para desarrollo y tests, necesario porque `0.10b` (dominio público) sigue pendiente y Google no admite *callbacks* sin TLS sobre un dominio registrable. Especificación aprobada en `docs/modulos/REQ-AUTH/*.md` Parte E. Los 49 tests Pest propios de `REQ-AUTH-002` (`GoogleLoginTest`, `IdentityLinkingTest`, `SocialiteGoogleIdentityProviderTest`, `AuthTranslationsParityTest`) en verde de forma repetida y estable; `pint`/`phpstan` limpios. Una ejecución completa de toda la suite del backend en esta sesión pasó limpia (406 tests); ejecuciones posteriores de la suite completa, en la misma sesión interactiva ya muy prolongada, resultaron inestables (fallos dispersos en ficheros sin relación con este paso, o el proceso terminado antes de completar) — no reproducible en los tests de este paso ejecutados solos, y compatible con presión de recursos de la propia sesión (contenedores recreados, decenas de conexiones `psql` manuales) más que con una regresión de código. Queda para que la ejecución de CI —entorno limpio en cada corrida— lo confirme antes de mezclar. Frontend (`eslint`/`lint:i18n`/`vue-tsc`+`build`/`vitest`, 39 tests) en verde. Verificado en navegador real (Playwright MCP) con el proveedor simulado: descubrimiento, fusión, vinculación con correo distinto, desvinculación (contraseña incorrecta y correcta), segundo factor, cancelado, sin cuenta — todo confirmado contra base de datos real, no solo la pantalla. Rama `feature/REQ-AUTH-002-google-login-fusion-cuentas`, PR [#143](https://github.com/pirexia/plataforma-educativa/pull/143) (*squash*, mezclado a `develop` en `b19f29b`).

### Corregido
- **Alta** (revisión independiente `doc-reviewer`): `RN-AUTH-99` —la regla que blinda la interpretación restrictiva de `OPEN-AUTH-31`— no tenía ningún test que la citara, y el caso central (`email_verified = true` **sin** ningún usuario local con ese correo) no estaba cubierto: el test más parecido (`CA-AUTH-207`) usa un correo con cuenta ya existente, así que prueba fusión, no ausencia de creación. Test nuevo que verifica `User::count()`/`Person::count()` sin cambios y que la sesión del *callback* sigue sin autenticar.
- **Alta** (revisión independiente `doc-reviewer`): `CHANGELOG.md` sin esta misma entrada — corregida la afirmación inicial (incorrecta, no contrastada contra `git log`) de que existía una convención del repositorio de diferirla a un commit posterior al *merge*; el patrón real, verificado, es incluirla en el propio cierre.
- **Media** (revisión independiente `doc-reviewer`, varios hallazgos): `label_key` en `GET /auth/identity-providers` documentaba un campo sin ningún consumidor real (retirado de backend, `oauth.yaml`, `api.md` y del tipo `IdentityProvider` del frontend, con una línea explicando por qué); `CA-AUTH-233`/`CA-AUTH-234` verificados a mano por el revisor sin regresión automatizada (añadidos: paridad de traducciones en los cuatro idiomas de `lang/*/auth.php`, y comprobación de que el logotipo de Google no carga ningún recurso externo); cinco cabeceras de la Parte E seguían diciendo "no implementada: es especificación previa" pese al cierre real (mismo criterio ya aplicado a las Partes B/C/D, issues #126/#127); tres referencias a que `ADR-042` estaba "en redacción" cuando ya está `ACEPTADA`; `SYSADMIN.md` documentaba solo 2 de las 3 guardas de arranque de `OAuthEnvironmentGuard` (faltaba la de HTTPS); `README.md`/`SECURITY.md`/`PRIVACY.md` sin reconciliar tras el cierre (estado del proyecto, tabla de versiones, "Google" todavía en "qué falta", flujo de datos de Google sin catalogar en el RAT).
- **Proceso**: `_ide_helper_models.php` conservaba una entrada obsoleta (`last_used_at` en vez de `last_login_at`, arrastrada de un renombrado de columna anterior a este cierre) que rompía `composer analyse` en cuanto la base de datos de desarrollo se reprovisionaba con el esquema correcto — corregida a mano, sin regenerar el fichero completo (la regeneración automática reformatea decenas de modelos no relacionados y corrompe *docblocks* existentes, comprobado y descartado). `MfaChallengeStep.vue` (extraído en un corte de cuota anterior) llamaba a una clave de traducción (`auth.mfaChallenge.loading`) que no existía en ningún idioma — activada por primera vez porque `/entrar/google` es el primer sitio que recupera un desafío de MFA sin datos iniciales; añadida en los cuatro idiomas.

### Diferido a propósito (issues abiertos)
[#141](https://github.com/pirexia/plataforma-educativa/issues/141) (Media): el `302` del *callback*, relativo y correcto para la topología de un solo origen de producción/*staging* (`ADR-028`), aterriza en el puerto de la API y no el de la SPA en el entorno de desarrollo de orígenes separados (`ADR-030`/issue #71) — verificado que el backend resuelve todo correctamente (base de datos), es un síntoma exclusivo de navegación manual en desarrollo. Dos propuestas de solución sin decidir cuál, mismo peso que el issue #71 original · [#142](https://github.com/pirexia/plataforma-educativa/issues/142) (Media, hallazgo de `db-reviewer`, sin relación con lo anterior): `RecordsAuditTrail` no excluye `last_login_at`/`last_used_at` de ninguno de los dos modelos que lo tienen, contradiciendo lo que ambos documentan.

### Revisión independiente
`security-reviewer` sin hallazgos. `db-reviewer` sin hallazgos bloqueantes (1 Media, #142, sin relación con este paso). `doc-reviewer`: 2 hallazgos Alta y varios Media, todos corregidos en la misma sesión antes de mezclar.

---

## 2026-08-31 · `chore/vigencia-documentacion-raiz`

### Nuevo: corrección de fondo para que la documentación no vuelva a quedarse desactualizada
El checklist de `doc-reviewer` solo cubría documentación de módulo, nunca documentos raíz (`SECURITY.md`, `README.md`, `PRIVACY.md`, `docs/REQUISITOS-...md`) ni el estado (`PROPUESTA`/`pendiente de aprobación`) de ADR y partes de módulo ya cerradas — llevaban desde 0.9/0.13 y desde cada cierre de fase respectivamente sin actualizarse. `.claude/agents/doc-reviewer.md` gana los puntos 9 y 10; `CLAUDE.md` §6 gana la regla 7 (v2.2.1→2.3.0).

### Corregido
- **Media** (issues [#111](https://github.com/pirexia/plataforma-educativa/issues/111)-[#114](https://github.com/pirexia/plataforma-educativa/issues/114)): `SECURITY.md`/`README.md`/`PRIVACY.md`/`docs/REQUISITOS-...md` describían un producto sin auth/MFA/permisos, con tabla de versiones desincronizada de 5 documentos, sin la fila `3.1.1` de su propio historial, y sin la cookie de sesión/`XSRF-TOKEN` en el RAT.
- **Media** ([#125](https://github.com/pirexia/plataforma-educativa/issues/125)): `CHANGELOG.md` sin las entradas de 1.2b y 1.3b.
- **Alta** ([#129](https://github.com/pirexia/plataforma-educativa/issues/129)): 11 de los 14 ADR en fichero propio (`028`-`038`) seguían `Estado: PROPUESTA` pese a estar implementados y ratificados de facto, incluido `ADR-033` (concreta la invariante crítica `INV-001`).
- **Media** ([#126](https://github.com/pirexia/plataforma-educativa/issues/126)/[#127](https://github.com/pirexia/plataforma-educativa/issues/127)): cabeceras de las Partes B/C/D de los cinco ficheros de `docs/modulos/REQ-AUTH/` seguían `pendiente de aprobación` pese a 1.2b/1.3/1.3b cerrados; `docs/modulos/REQ-CORE/funcional.md`/`datos.md` daban por pendiente el middleware `EnsureModuleEnabled` (ya implementado) y la aprobación de 1.1 (cerrado hace varias fases).
- **Alta** ([#128](https://github.com/pirexia/plataforma-educativa/issues/128), documentación corregida — infraestructura pendiente de decisión): ningún *worker* de colas (Horizon/`queue:work`) desplegado pese a 32 clases `ShouldQueue` reales y `QUEUE_CONNECTION=database` por defecto. `SYSADMIN.md`/`RUNBOOK.md` corregidos para reflejarlo con precisión; el despliegue del *worker* en sí queda para una decisión aparte (afecta a `ADR-028`/`ADR-037`).
- 13 issues cerrados por hallarse ya resueltos en código, dejados abiertos por descuido tras auditar la validez de los 49 issues abiertos: [#18](https://github.com/pirexia/plataforma-educativa/issues/18), [#36](https://github.com/pirexia/plataforma-educativa/issues/36), [#39](https://github.com/pirexia/plataforma-educativa/issues/39), [#49](https://github.com/pirexia/plataforma-educativa/issues/49), [#50](https://github.com/pirexia/plataforma-educativa/issues/50), [#52](https://github.com/pirexia/plataforma-educativa/issues/52), [#58](https://github.com/pirexia/plataforma-educativa/issues/58), [#66](https://github.com/pirexia/plataforma-educativa/issues/66), [#67](https://github.com/pirexia/plataforma-educativa/issues/67), [#70](https://github.com/pirexia/plataforma-educativa/issues/70), [#75](https://github.com/pirexia/plataforma-educativa/issues/75), [#95](https://github.com/pirexia/plataforma-educativa/issues/95), [#110](https://github.com/pirexia/plataforma-educativa/issues/110). [#62](https://github.com/pirexia/plataforma-educativa/issues/62) revisado y mantenido abierto: solo uno de sus cuatro puntos "Pendiente" está hecho.
- **Baja**: `ARCHITECTURE.md`/`CLAUDE.md` decían "52 módulos", el recuento canónico es 53.

### Diferido a propósito (issue abierto)
[#128](https://github.com/pirexia/plataforma-educativa/issues/128) (Alta) — desplegar el *worker* de colas en sí, decisión de infraestructura fuera del alcance de un `chore/` de documentación.

### Revisión independiente
`doc-reviewer` revisó el diff propio del chore (2 hallazgos, orden cronológico de `CHANGELOG.md` y una imprecisión de redacción, ambos corregidos) y auditó el resto de la documentación no tocada en la primera pasada (4 issues nuevos encontrados: #126-#129). Auditoría aparte de validez de los 49 issues abiertos del repositorio.

---

## 2026-08-31 · Cierre de 1.3b (`REQ-AUTH-003`: MFA — correo como segundo factor, excepciones temporales y administración)

### Nuevo: correo como 2FA, excepciones temporales y pantalla de administración
Partido de `1.3` por tamaño (`OPEN-AUTH-24`). Cuatro piezas: (1) correo como segundo factor de MFA con `DestinationMasker` (enmascarado determinista) y `MfaDeliveryCode` (hash SHA-256, comparación `hash_equals`); (2) excepciones temporales nominales a la obligatoriedad (`MfaExemptionService`, 3 endpoints, tope de 90 días, reapertura automática al caducar); (3) cuatro tareas de mantenimiento programadas (`PurgeMfaEnrollments`/`PurgeMfaFactors`/`PurgeMfaChallenges`/`MaterializeMfaObligations`+`ReopenExpiredMfaExemptions`), cierra issue [#109](https://github.com/pirexia/plataforma-educativa/issues/109); (4) pantalla `/administracion/mfa` (cumplimiento por rol, conmutador `mfa_required` con vista previa, restablecimiento ajeno, gestión de excepciones). Especificación aprobada en `docs/modulos/REQ-AUTH/*.md` Parte D. 288 tests Pest en verde, `pint`/`phpstan` limpios; frontend `eslint`/`vue-tsc`+`build`/`vitest`(20)/`lint:i18n` limpios. Verificado en navegador real (Playwright MCP). Mezclado a `develop` vía PR [#123](https://github.com/pirexia/plataforma-educativa/pull/123) (*squash*, commit `dd68f48`).

### Corregido
- **Media** (revisión independiente `db-reviewer`, [#118](https://github.com/pirexia/plataforma-educativa/issues/118)/[#119](https://github.com/pirexia/plataforma-educativa/issues/119)): `PurgeMfaChallenges`/`PurgeMfaFactors` filtraban sin índice de soporte — el índice de `1.3` quedó sobre la columna equivocada. Corregido con `CREATE INDEX CONCURRENTLY` el mismo día.
- **Media** (revisión independiente `doc-reviewer`, [#121](https://github.com/pirexia/plataforma-educativa/issues/121)/[#122](https://github.com/pirexia/plataforma-educativa/issues/122)): `funcional.md` afirmaba "ninguna dependencia nueva" — sin documentar el primer uso real de `@tanstack/vue-table` (ya en el stack aprobado) ni la dependencia genuinamente nueva `@vueuse/core`, ambas traídas por la pieza 3 — y `admin.md` citaba una pantalla inexistente. Ambos corregidos el mismo día.
- **Proceso**: issue [#110](https://github.com/pirexia/plataforma-educativa/issues/110), `Route::getController()` cacheaba el controlador entre peticiones simuladas de un mismo test Pest con dependencia `scoped()` — corregido en `tests/Pest.php`, no explotable en producción.
- De paso, cerrado en GitHub el issue [#115](https://github.com/pirexia/plataforma-educativa/issues/115) (403 de autorrestablecimiento sin `detailKey`): ya resuelto desde la pieza 2, había quedado abierto por descuido.

### Diferido a propósito (issues abiertos)
[#116](https://github.com/pirexia/plataforma-educativa/issues/116) (Baja) tabla de cumplimiento visible antes de elegir rol · [#117](https://github.com/pirexia/plataforma-educativa/issues/117) (Baja) `/mfa-exemptions` sin *rate limit* propio, no explotable · [#120](https://github.com/pirexia/plataforma-educativa/issues/120) (Baja) pantalla sin test automatizado.

### Revisión independiente
`security-reviewer` sin hallazgos Crítica/Alta (1 Baja diferida). `db-reviewer`/`doc-reviewer`: 3 hallazgos Media, todos corregidos el mismo día. Detalle completo en `docs/historial/1.3b-mfa-correo-excepciones.md`.

---

## 2026-08-27 · Cierre de 1.3 (`REQ-AUTH-003`: MFA — TOTP, obligatoriedad por rol y restablecimiento)

### Nuevo: backend y frontend completos de MFA
TOTP con códigos de respaldo, login en dos pasos, obligatoriedad por rol (`MfaPolicy`, resolución multi-rol), período de gracia y muro de sesión restringida, `PATCH /roles/{public_id}` acotado a `mfa_required` (permiso nuevo `rol.actualizar` en `REQ-CORE`), listado de cumplimiento (agregado e individualizado), restablecimiento por administrador. 6 tablas nuevas + 2 modificaciones aditivas, 10 endpoints en `Auth` + 1 en `Core`, 4 pantallas (`/entrar` en dos pasos, `/cuenta/seguridad`, `/cuenta/seguridad/obligatorio`, `QrCode.vue`). `ADR-041` aprueba `pragmarx/google2fa ^9.1` (backend) y `uqr ^0.1.3` (frontend), ambas envueltas tras interfaz propia. Especificación aprobada en `docs/modulos/REQ-AUTH/funcional.md §C` (`OPEN-AUTH-18` a `26` resueltas). Correo como segundo factor y excepciones temporales nominales diferidos a `1.3b`. 320 tests Pest en verde, `pint`/`phpstan` limpios; frontend (`eslint`/`lint:i18n`/`vue-tsc`+`build`/`vitest`) en verde; `composer audit`/`npm audit` sin vulnerabilidades. Mezclado a `develop` vía PR [#107](https://github.com/pirexia/plataforma-educativa/pull/107) (*squash*, commit `cd13e8a`).

### Corregido
- **Media** ([#96](https://github.com/pirexia/plataforma-educativa/issues/96)): `compose.yaml` no fijaba `target: dev` en el *build* multi-etapa de `api`/`web` desde `0.9b` — cualquier reconstrucción rompía el entorno de desarrollo local.
- **Media** (revisión independiente `db-reviewer`, [#98](https://github.com/pirexia/plataforma-educativa/issues/98)): migración de `login_attempts` sin `NOT VALID`/`VALIDATE CONSTRAINT`, riesgo de bloqueo en despliegue con volumen.
- **Media** (revisión independiente `doc-reviewer`, 5 hallazgos, [#99](https://github.com/pirexia/plataforma-educativa/issues/99)-[#103](https://github.com/pirexia/plataforma-educativa/issues/103)): `funcional.md`/`SYSADMIN.md`/`RUNBOOK.md`/manual de administrador sin reconciliar tras la partición 1.3/1.3b; `QrCode.vue` sin implementar `fill="currentColor"` como fija `ADR-041`.
- **Baja** (2 hallazgos, [#104](https://github.com/pirexia/plataforma-educativa/issues/104)-[#105](https://github.com/pirexia/plataforma-educativa/issues/105)): convención de FK y cifra incorrecta en `operacion.md`.
- **Proceso**: un subagente `implementer` relanzado tras un corte de cuota recortó `GET /mfa-compliance/users` del alcance ya aprobado, sin autorización — corregido, y motivó una norma nueva en `CLAUDE.md §3` (v2.2.1): relanzar un subagente de ejecución no es licencia para decidir alcance.

### Diferido a propósito (issues abiertos)
[#106](https://github.com/pirexia/plataforma-educativa/issues/106) suite Pest completa agota el `memory_limit` de 128M del PHP CLI en local, no afecta a CI · `1.3b` (correo como 2FA, excepciones temporales, pantalla de administración) → paso propio posterior.

### Revisión independiente
`security-reviewer` sin hallazgos. `db-reviewer`/`doc-reviewer`: 8 hallazgos, todos corregidos en la misma sesión. Detalle completo en `docs/historial/1.3-mfa-obligatorio-por-rol.md`.

---

## 2026-08-26 · Cierre de 1.2b (`REQ-AUTH-005` puntos 2-4: sesiones activas, cierre remoto y detección de dispositivo)

### Nuevo: panel de sesiones activas y detección de dispositivo nuevo
Puntos 2-4 de `REQ-AUTH-005`, diferidos de `1.2` (issue [#59](https://github.com/pirexia/plataforma-educativa/issues/59)): listado de sesiones activas, revocación individual y masiva, detección de login desde dispositivo nuevo. 2 migraciones (`user_known_devices`, `user_sessions`, RLS desde el primer día), 3 endpoints de autoservicio, pantalla `/cuenta/sesiones`, 4 idiomas, OpenAPI completo. `ADR-040` (exclusión declarativa del *observer* de auditoría). No incluye geolocalización por IP (`OPEN-AUTH-13`, pospuesta) ni RLS en `sessions` del framework (issue [#81](https://github.com/pirexia/plataforma-educativa/issues/81), endurecimiento futuro). 279 tests backend (1576 aserciones), `pint`/`phpstan` limpios; frontend `eslint`/`vue-tsc`/`lint:i18n`/`vitest` (10/10) limpios. Mezclado a `develop` vía PR [#91](https://github.com/pirexia/plataforma-educativa/pull/91) (*squash*, commit `12fe917`).

### Corregido
- **Alta** (verificación en navegador real, issue [#85](https://github.com/pirexia/plataforma-educativa/issues/85)): `device_known` siempre daba `true`, ningún test unitario lo detectó.
- **Media**: gestión de foco de las confirmaciones de revocar sesión no cumplía WCAG 2.2 AA 2.4.3/2.4.7.
- **Media** (issue [#88](https://github.com/pirexia/plataforma-educativa/issues/88)): fix de `security-reviewer` sobre `Auth::guard('web')->logout()` quedó incompleto y sin verificar (fallo de entorno propio del agente) — reproducía la misma violación de FK por otra vía; corregido y verificado de verdad, junto con el test de regresión que lo enmascaraba.
- **Media** (`db-reviewer`): los tres jobs de retención no tenían test — añadido `AuthRetentionJobsTest.php`.
- **Media** (`doc-reviewer`, issue [#89](https://github.com/pirexia/plataforma-educativa/issues/89)): `SYSADMIN.md`/`PRIVACY.md` sin el inventario de cookies comprometido en la especificación.
- **Proceso**: dos de los tres agentes de revisión lanzados con `isolation: "worktree"` se crearon desde un commit muy antiguo, sin el código del módulo — detectado tras más de una hora, parados y relanzados sin aislamiento. Motivó la norma de verificar `git log --oneline -1` en todo *worktree* de agente antes de dar por buena una revisión.

### Diferido a propósito (issues abiertos)
[#81](https://github.com/pirexia/plataforma-educativa/issues/81) (Media) `tenant_id`/RLS en `sessions` del framework · [#89](https://github.com/pirexia/plataforma-educativa/issues/89) (Media) plantilla de despliegue incompleta, no bloquea (`OPEN-11`) · [#90](https://github.com/pirexia/plataforma-educativa/issues/90) (Baja) literal sin traducir, decisión de convención pendiente.

### Revisión independiente
`db-reviewer`/`security-reviewer`/`doc-reviewer`, con el incidente de *worktrees* descrito arriba. Todos los hallazgos corregidos y verificados antes de mezclar. Detalle completo en `docs/historial/1.2b-sesiones-activas.md`.

---

## 2026-08-25 · Cierre de 1.2 (`REQ-AUTH`: autenticación local y sesiones)

### Nuevo: backend y frontend completos de `REQ-AUTH` (10 endpoints, 6 pantallas)
Migraciones, dominio, infraestructura y capa HTTP de los 10 endpoints (login, logout, activación de cuenta, recuperación/restablecimiento/cambio de contraseña, desbloqueo de cuenta, `me`). Cliente TS (`api`/`types`/`i18n`/composables) y las 6 pantallas públicas correspondientes en `apps/web`, enrutadas. OpenAPI (`apps/api/openapi/paths/auth.yaml`) con paridad 1:1 contra `route:list`. Especificación aprobada previamente (`docs/modulos/REQ-AUTH/`, `ADR-039`). 241 tests en verde, `pint`/`phpstan` limpios; frontend (`eslint`/`lint:i18n`/`vue-tsc`+`build`/`vitest`/Playwright e2e) en verde. Mezclado a `develop` vía PR [#76](https://github.com/pirexia/plataforma-educativa/pull/76) (*squash*, commit `0d34587`).

### Corregido
- **Severidad Crítica** ([#62](https://github.com/pirexia/plataforma-educativa/issues/62)): `SessionEnvironmentGuard` (nuevo, corre en todos los entornos) tumbaba `plataforma-api` porque `apps/api/.env` traía `SESSION_LIFETIME=120` (valor del starter kit) frente al mínimo de 480 que exige `REQ-AUTH`. Parcheado en `compose.yaml`, pendiente de trasladar a `.env` real. Documentado en `SYSADMIN.md §2c`/`RUNBOOK.md §2.2`.
- **Severidad Alta** ([#63](https://github.com/pirexia/plataforma-educativa/issues/63)): `login` se auditaba con `actor_type='anonymous'` porque `AuditRecorder::record()` corría antes de `Auth::login()`.
- **Severidad Alta** ([#67](https://github.com/pirexia/plataforma-educativa/issues/67)): colisión entre *worktrees* de subagentes trabajando la misma rama revirtió parte de un commit del frontend; detectada y corregida por el propio subagente.
- **Severidad Alta** ([#71](https://github.com/pirexia/plataforma-educativa/issues/71)): el login por navegador daba `404`/`419` pese a que una verificación con `curl` lo daba por bueno — esa verificación golpeaba el host de tenant correcto, no el camino real del navegador, que ni siquiera puede leer una cookie fijada por un host distinto al de la propia página (`document.cookie`, ignora `SameSite`/CORS del todo). Corregido sirviendo la SPA desde el mismo host que la API (`CORS_ALLOWED_ORIGINS` + `apps/web/vite.config.ts` `server.allowedHosts`), verificado de extremo a extremo replicando la petición exacta del navegador y confirmado en un navegador real.
- **Severidad Alta** ([#72](https://github.com/pirexia/plataforma-educativa/issues/72)): `apps/web/node_modules` desincronizado de `package-lock.json` (faltaba `vue-i18n` y ~170 paquetes), la SPA no cargaba. `npm ci` dentro del contenedor.
- **Severidad Alta** ([#73](https://github.com/pirexia/plataforma-educativa/issues/73), hallazgo de la revisión de seguridad independiente): los tokens de restablecimiento/desbloqueo en claro persistían indefinidamente en `failed_jobs` si el correo agotaba sus 5 reintentos — vía real de *account takeover*. `ShouldBeEncrypted` en los dos *jobs* de correo, más `queue:prune-failed --hours=24` programado como segunda capa.
- **Severidad Alta** ([#74](https://github.com/pirexia/plataforma-educativa/issues/74), misma revisión): `GET /auth/csrf-cookie` era el único de los 6 endpoints anónimos sin límite de tasa, pese a tener el *bucket* ya definido y sin usar — vector de agotamiento de recursos. Corregido invocándolo.
- **Severidad Alta** ([#75](https://github.com/pirexia/plataforma-educativa/issues/75)): mismo hallazgo que #73 en `SendInvitationEmail` (`REQ-CORE`, 1.1 ya mezclado) — corregido de paso por ser trivial y de la misma naturaleza, con su propio test de regresión.
- **Severidad Media** ([#64](https://github.com/pirexia/plataforma-educativa/issues/64)): `actingAs()` no fijaba `pge_tenant_id`; `VerifySessionTenant` (nuevo en 1.2) rompía los ~20 ficheros de test de 1.1. Corregido sobrescribiendo `actingAs()` en `Tests\TestCase`, sin tocar tests de `REQ-CORE`.
- **Severidad Media** ([#66](https://github.com/pirexia/plataforma-educativa/issues/66)): faltaba la guarda de arranque de `AUTH_PASSWORD_MIN_LENGTH`/`AUTH_BCRYPT_ROUNDS ≥ 12` que `operacion.md` ya documentaba como existente.
- **Severidad Media** ([#68](https://github.com/pirexia/plataforma-educativa/issues/68)): faltaba `apps/api/config/cors.php` — sin él, Laravel aplicaba `allowed_origins: ['*']`/`supports_credentials: false`, incompatible con `credentials: 'include'` (usado en todas las peticiones desde `client.ts`). Nuevo fichero con orígenes explícitos vía `CORS_ALLOWED_ORIGINS` (`SYSADMIN.md §2c`).
- **Severidad Media** (7 hallazgos de la revisión de documentación independiente, todos corregidos): `ADR-039` sin aplicar del todo en `datos.md` de `REQ-AUTH`/`REQ-CORE` (vocabulario de `audit_logs`); cadena de *middleware* de `api.md §8` sin `EncryptCookies`; `auth:grant-lockout-permissions` sin nombre real en `operacion.md`; `VITE_API_URL`/#71 sin reflejo en `SYSADMIN.md`/`RUNBOOK.md`; `admin.md` sin las dos capacidades nuevas de `administrador_centro` (cuentas bloqueadas, tiempo de sesión); un comentario de código con el recuento de endpoints viejo.

### Diferido a propósito (issues abiertos)
[#59](https://github.com/pirexia/plataforma-educativa/issues/59) resto de `REQ-AUTH-005` → `1.2b` · [#60](https://github.com/pirexia/plataforma-educativa/issues/60) `ValidationErrorFormatter` antepone "core." fuera de su módulo · [#61](https://github.com/pirexia/plataforma-educativa/issues/61) reutilización de `UnlockReason::Correo` a falta de un 4º valor · [#65](https://github.com/pirexia/plataforma-educativa/issues/65) manuales de usuario sin las pantallas nuevas · [#69](https://github.com/pirexia/plataforma-educativa/issues/69) `CA-AUTH-060`-`063` sin test automatizado · [#71](https://github.com/pirexia/plataforma-educativa/issues/71) parche temporal fijado a un único tenant de desarrollo, decisión definitiva pendiente.

### Revisión independiente
`security-reviewer`/`doc-reviewer` lanzados dos veces (la primera tanda fue interrumpida por el usuario sin resultado, relanzada de cero). Sin hallazgos Crítico/Alto sin corregir al final. Aislamiento de tenant y autorización denegar-por-defecto verificados activamente (tests cruzados de tenant reales), no solo asumidos. Nota honesta pendiente, igual que en el PR #56 de 1.1: no se pudo verificar el resultado de `ci-api.yml`/`ci-web.yml` sobre el PR antes de mezclar (mismo límite de permisos del token de `gh`) — mezclado confiando en una verificación local más completa que la de 1.1 (incluye Playwright e2e y `npm audit`, que 1.1 no llegó a ejercitar). Detalle completo en `docs/historial/1.2-auth-local-sesiones.md`.

---

## 2026-08-22 · Cierre de 1.1 (`REQ-CORE`: tenants y usuarios)

### Nuevo: API completa de tenants y usuarios
Configuración de centro, usuarios, invitaciones, importación masiva con idempotencia (`RequireIdempotencyKey`, `ADR-038 §8`), roles/permisos/módulos de solo lectura, auditoría+exportación, activos de marca (validación de tipo real por contenido, saneado de SVG). Sin pantallas todavía (`OPEN-CORE-02`, se completan en 1.8). `ADR-038` (convenciones REST) escrito antes de implementar. 76 `CA-CORE-*` con test propio, 183/183 en verde. OpenAPI completo (`components.yaml` + `paths/core.yaml`, 33 operaciones), cliente TS (`apps/web/src/modules/core/`). Mezclado a `develop` vía PR [#56](https://github.com/pirexia/plataforma-educativa/pull/56) (*squash*, commit `d32e4e9`).

### Corregido
- **Severidad Alta**: fallo preexistente de `TenancyServiceProvider` ([#49](https://github.com/pirexia/plataforma-educativa/issues/49)) que vaciaba el contexto de tenant tras cualquier *job* con `QUEUE_CONNECTION=sync`.
- **Severidad Media** (revisión independiente `security-reviewer`/`doc-reviewer`): [#53](https://github.com/pirexia/plataforma-educativa/issues/53) faltaban tests de aislamiento cruzado entre tenants para `/user-imports/*` y `assets/{kind}`; cinco hallazgos de coherencia de documentación (ejemplo: `"total": 17` en vez de 16 tras corregir [#48](https://github.com/pirexia/plataforma-educativa/issues/48)).
- **Severidad Media** ([#50](https://github.com/pirexia/plataforma-educativa/issues/50)): `IdempotencyKey` estaba fuera de su bounded context (`App\Models` en vez de `App\Modules\Core\...`).
- **Severidad Media** ([#51](https://github.com/pirexia/plataforma-educativa/issues/51)): Larastan no reconocía las columnas reales de los modelos de tenant (`phpstan analyse` 234→0 con `barryvdh/laravel-ide-helper` + `@mixin` en los 15 modelos).
- **Severidad Media** ([#55](https://github.com/pirexia/plataforma-educativa/issues/55)): `TenantMigrationTest` fallaba en una base de datos recién provisionada; reescrito para probar el comportamiento correcto.
- **Diferido a 1.2 a propósito** ([#18](https://github.com/pirexia/plataforma-educativa/issues/18)): falta un `PasswordBrokerRepository` propio con tenant en la recuperación de contraseña.

`security-reviewer` no encontró hallazgos Crítico/Alto. Nota honesta pendiente: no se pudo verificar el resultado de `ci-api.yml`/`ci-web.yml` sobre el PR antes de mezclar (el token de `gh` de esta sesión no tenía permiso para leer *check runs*, 403) — se mezcló confiando en la verificación local exhaustiva. Detalle completo, subpaso a subpaso, en `docs/historial/1.1-core-tenants-usuarios.md`.

---

## 2026-08-19 · Cierre de 0.9b (portabilidad del despliegue)

### Nuevo: Containerfiles multi-etapa, `build-images.yml`, `infra/quadlet/`
Implementa `ADR-037`. `infra/containers/{api,web}/Containerfile` con etapas `base`/`dev`/`build`/`prod` (FrankenPHP en modo clásico para la API, nginx solo de estáticos para la SPA). `.github/workflows/build-images.yml`: publica en GHCR con etiquetado por `sha`/`develop`/`vX.Y.Z`, retención desde el primer commit, guarda `proxy_pass`, `quadlet-lint`, y gate de CI en verde para tags de versión. Diez unidades Quadlet en `infra/quadlet/` conformes a `ADR-028`. Banco de pruebas local `infra/compose/compose.prodlike.yaml`, instalador `infra/install.sh`, convención de secretos por `EnvironmentFile=` (dos ficheros: `plataforma.env.example` para la API, `plataforma-postgres.env.example` para PostgreSQL).

Las tres pruebas obligatorias de `ARCHITECTURE.md §4.3` verificadas de verdad en WSL2 con `compose.prodlike.yaml` (el arranque nativo con `systemctl --user` quedó bloqueado por un problema de permisos preexistente del host, documentado en `SYSADMIN.md §6.2` sin forzarlo).

### Corregido
- **Severidad Media** (revisión independiente de `doc-reviewer`): `build-images.yml` no exigía CI en verde para tags de versión pese a que `ADR-037 §5.3` lo fija como obligatorio. Añadido el job `require-ci-green`.
- **Severidad Media** (`doc-reviewer`): `plataforma.env.example` se anunciaba como plantilla completa y le faltaban `APP_URL`/`APP_NAME` — ambas usadas por Laravel con valores por defecto silenciosos (`http://localhost`, `"Laravel"`).
- **Severidad Media** (`doc-reviewer`): numeración rota en `SYSADMIN.md §6` (dos secciones "6.3", ninguna "6.2"). Renumerado y corregidas las referencias cruzadas en `RUNBOOK.md`.
- **Severidad Media** (`doc-reviewer`): `infra/install.sh` recomendaba `enable --now` sobre `plataforma-migrate.service`, una unidad sin sección `[Install]` — corregido a `start`.
- **Severidad Media** ([#35](https://github.com/pirexia/plataforma-educativa/issues/35), `security-reviewer`): sin `.containerignore` en los contextos de construcción — construir la imagen `prod` localmente desde un árbol de desarrollo real copiaría `.env`/claves/`vendor` a la imagen. Añadidos `apps/api/.containerignore` y `.containerignore` (raíz).
- **Severidad Media** ([#36](https://github.com/pirexia/plataforma-educativa/issues/36), `security-reviewer`): `postgres.container` recibía el `EnvironmentFile` completo de la API (`APP_KEY`, `DB_*_PASSWORD`) cuando solo necesita sus propias credenciales de arranque. Separado en `plataforma-postgres.env.example`.

### Diferido a propósito (issues abiertos, severidad Baja, `CLAUDE.md §5`)
[#37](https://github.com/pirexia/plataforma-educativa/issues/37) Redis sin autenticación · [#38](https://github.com/pirexia/plataforma-educativa/issues/38) `minio-data.volume` huérfano hasta `0.10d` · [#39](https://github.com/pirexia/plataforma-educativa/issues/39) (resuelto en este cierre, ver arriba) · [#40](https://github.com/pirexia/plataforma-educativa/issues/40) sin escaneo de vulnerabilidades a nivel de imagen del SO.

---

## 2026-08-18 · Cierre automático de sesión por límite de cuota

### `CLAUDE.md` → 2.1.0
El cierre de sesión por poca cuota (§3) dejaba de disparar hasta que el usuario avisara. Ahora se dispara solo, en cuanto el sistema emite el aviso de "usage limit approaching": termina el paso en curso, comitea/pushea, actualiza `memory.md`/`PLAN-IMPLEMENTACION.md`, y programa la vuelta. Mecanismo detallado en el skill `cierre-de-sesion`.

### `cierre-de-sesion` → 1.1.0
Nueva sección "Cierre automático por límite de cuota": no hay herramienta para consultar el porcentaje de cuota ni la hora de reset (hay que preguntársela al usuario si no se sabe); `ScheduleWakeup` programa la vuelta, encadenando tramos de máximo una hora si el reset queda más lejos.

### `CLAUDE.md` → 2.1.1 y `cierre-de-sesion` → 1.1.1
Investigado (subagente `claude-code-guide`) si el propio aviso de límite trae la hora de reset. Según fuentes de terceros, no confirmadas en documentación oficial de Anthropic, el aviso de **límite alcanzado** (distinto del de aproximación visto en esta sesión, que no la trae) sí la incluiría: `"...resets 3:45pm"` (5h) / `"...resets Mon 12:00am"` (semanal). Añadidos los patrones de extracción como primer intento; si no coinciden, se sigue preguntando al usuario.

### `CLAUDE.md` → 2.1.2 y `cierre-de-sesion` → 1.1.2
Corrección tras confirmación real del usuario (app Android): la hora de reset la muestra el **cliente**, en una tarjeta de interfaz propia, no un texto que llegue al modelo. Retirados los patrones de extracción de la versión anterior (no aplicables); la regla vuelve a ser preguntar siempre, salvo que el usuario ya la haya dado en la conversación.

---

## 2026-08-18 · Cierre de 0.13 (plantillas de documentación)

### Nuevo: `SECURITY.md`, `PRIVACY.md`, `RUNBOOK.md`, `CONTRIBUTING.md`
Los cuatro documentos raíz que exige `CLAUDE.md` §6 y que todavía faltaban. `docs/modulos/_PLANTILLA/` ya existía desde el paso 0.1. Cada documento describe lo que es cierto hoy (fase 0, sin datos reales) y marca explícitamente como pendiente lo que depende de un bloqueante todavía abierto (`OPEN-07` para `PRIVACY.md`, `OPEN-11`/`OPEN-10` para `RUNBOOK.md`, `OPEN-08` para el contacto de seguridad de `SECURITY.md`) en vez de rellenarlo con una suposición.

---

## 2026-08-18 · Cierre de 0.8 (modelo de datos núcleo)

### Nuevo: `docs/adr/ADR-034-modelo-de-datos-nucleo.md`
Diseñado por el subagente `architect` (Opus). `Person`/`User` como identidad y credencial separadas; esquema completo de `Role`/`Permission` desde ahora con el resolutor granular diferido a 1.5; `AuditLog` polimórfica append-only con redacción por modelo; `AcademicYear` con `academic_year_id` obligatorio-o-ausente, nunca nullable; `ModuleSubscription` con catálogo de módulos materializado desde el código. Dos preguntas abiertas sin resolver a propósito (`OPEN-12`, supresión frente a auditoría inmutable; `OPEN-13`, columnas definitivas de `Person`), ninguna bloqueante de 0.8.

### Nuevo: `apps/api` — siete tablas del núcleo, modelos y comando de sincronización
`academic_years`, `people`, `users` (rehecha), `roles`/`role_user`, `permissions`/`permission_role`, `modules`/`module_subscriptions`, `audit_logs`. `TenantMigration` gana `tenantTableAppendOnly()` y `tenantForeignId()`. `TenantModel` gana `SoftDeletes` y `RecordsAuthorship`; nuevo `AppendOnlyModel`. Modelos `Person`, `User`, `Role`, `Permission`, `AcademicYear`, `ModuleSubscription`, `AuditLog`, con *morph map* forzado. Comando `platform:sync-registry`, idempotente. 94 tests en `tests/Feature/Core/` y `tests/Feature/Tenancy/`, incluida una batería de invariantes de esquema generales (no hardcodeadas por tabla) que amplía la de `ADR-033` §10.

### Corregido
- **Seguridad, severidad Alta**: `password_reset_tokens` del starter kit de Laravel usaba `email` como clave primaria global — con `users.email` único *por tenant*, un token del centro A servía para la cuenta homónima del centro B (toma de control de cuenta entre tenants). Ahora clave primaria compuesta `(tenant_id, email)`.
- **Seguridad, severidad Alta** ([#17](https://github.com/pirexia/plataforma-educativa/issues/17)): las tablas append-only solo revocaban `UPDATE, DELETE` a `plataforma_app`; `plataforma_platform` (BYPASSRLS) conservaba privilegio completo, vaciando la garantía de inmutabilidad de `audit_logs` para la conexión de backoffice. Revocado también para `plataforma_platform`.
- **Severidad Media** ([#16](https://github.com/pirexia/plataforma-educativa/issues/16)): `tenants.slug` (0.7) con índice único no parcial — un tenant dado de baja bloqueaba su slug para siempre.
- **Severidad Media** ([#19](https://github.com/pirexia/plataforma-educativa/issues/19)), hallazgo de la revisión independiente de `db-reviewer`/`security-reviewer` tras el autoinforme del *fork* de implementación: el test que comprueba que las tablas de referencia no dan privilegios de escritura solo miraba `plataforma_app` — mismo punto ciego que dejó pasar el #17. Generalizado a los dos roles de aplicación.
- **Severidad Media** ([#20](https://github.com/pirexia/plataforma-educativa/issues/20)), mismo origen: `people_tenant_document_unique` no impedía dos personas del mismo tenant con el mismo `document_number` si `document_type` quedaba `NULL` en ambas (PostgreSQL trata cada `NULL` como distinto). `CHECK` nuevo que empareja la nulabilidad de las dos columnas.
- **Diferido a 1.2** ([#18](https://github.com/pirexia/plataforma-educativa/issues/18)): falta un `PasswordBrokerRepository` propio que filtre por tenant — hoy la corrección de `password_reset_tokens` depende solo de RLS. Fuera de alcance de "modelo de datos núcleo".

### Bugs propios encontrados y corregidos durante la implementación
Detalle completo, subpaso a subpaso, en `docs/historial/0.8-modelo-de-datos-nucleo.md`.

---

## 2026-08-17 · Tarde · Cierre de 0.7 (núcleo multi-tenant)

### Nuevo: `docs/adr/ADR-033-implementacion-del-aislamiento-multi-tenant.md`
Diseñado por el subagente `architect` (Opus), aprobado por el usuario. RLS de PostgreSQL como barrera primaria, scope de Eloquent como ergonomía secundaria, tres roles de base de datos sin `SUPERUSER`, claves foráneas compuestas `(tenant_id, id)`, veto a PgBouncer en modo *transaction*, suite de tests sobre PostgreSQL real.

### Nuevo: `apps/api` — infraestructura de tenancy completa
`app/Support/Tenancy/` (`TenantContext`, `Tenant`, `TenantModel`, `BelongsToTenant`, `TenantScope`, `TenantHost`, `TenantStorage`, `TenantMigration`, `RunsPerTenant`, `TenantStatus`), `app/Http/Middleware/ResolveTenant.php`, `app/Providers/TenancyServiceProvider.php`. Tres conexiones de base de datos (`pgsql`/`pgsql_owner`/`pgsql_platform`), `config/tenancy.php` (dominio base, registro de tablas compartidas), primeras claves de `lang/*/tenancy.php`. `infra/containers/postgres/init/` provisiona el esquema `app`, la función `app.current_tenant_id()` y los tres roles. 47 tests en `tests/Feature/Tenancy/`, incluida la batería completa de diez tests de `ADR-033` §10.

### Bugs propios encontrados y corregidos durante la implementación
No relacionados con el diseño de 0.7 en sí, pero descubiertos verificándolo:
- `apps/api/phpunit.xml` sin `force="true"` en `<env>`: la suite llevaba desde el paso 0.4 corriendo contra la base de datos de desarrollo real, no contra la configuración de test documentada.
- `infra/containers/api/Containerfile` sin `--no-reload` en `php artisan serve`: toda petición HTTP real devolvía 500 vacío sin log porque Laravel filtraba el entorno del proceso hijo del servidor embebido.
- `failed_jobs` tenía privilegios completos para `plataforma_app` pese a no tener `tenant_id`/RLS (fuga potencial entre tenants en los registros de fallos).
- `Queue::$createPayloadCallbacks` es estático de clase: se acumulaba en cada reconstrucción de la aplicación (cada test de Laravel, o un futuro Octane en producción).
- `PendingDispatch` envía el job en su `__destruct()`: si `dispatch()` es la expresión de retorno de un closure pasado a `TenantContext::runFor()`, el envío ocurre después de que el contexto se restaure.

Detalle completo del proceso, subpaso a subpaso, en `memory.md`.

---

## 2026-08-17 · Corrección de coherencia: `ADR-024`/`ADR-027`/`ADR-030`

### `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` → 3.1.1
Al preparar el diseño de 0.7 se detectó que la sección 18 (fuente de verdad canónica de `ADR-001` a `ADR-027`, `CLAUDE.md` §6.3) tenía la entrada de `ADR-024` desactualizada — seguía diciendo "Docker Compose sobre VPS europeo" sin reflejar que `ADR-027` lo sustituyó — y que **`ADR-027` no aparecía en ningún sitio del documento**, pese a ser canónico ahí por numeración. Añadida la entrada de `ADR-027` y anotada en ambas la cadena de sustituciones real: `ADR-024` → `ADR-027` (host inicial: VM RHEL 10/VMware, no VPS) → `ADR-030` (sustituye a `ADR-027` para la etapa de desarrollo E0: WSL2 en equipo personal; la VM VMware queda como candidata a preproducción).

### `ARCHITECTURE.md` → 2.0.1
Las entradas de `ADR-024` y `ADR-027` en el apéndice de ADR contradecían a la tabla de §4.2 del mismo documento (que ya reflejaba correctamente WSL2 en E0 desde el cierre de `ADR-030`). Sincronizadas ambas entradas con la cadena de sustituciones.

### `README.md` → 2.4.1
La fila "Host inicial: VM VMware" de la tabla de stack contradecía directamente a la fila "Desarrollo: WSL2 en equipo personal" dos filas por encima. Sustituida por "Alojamiento del piloto: pendiente de decidir (`OPEN-11`)".

### `memory.md`
Nota añadida en la fila de `ADR-027` de la tabla de decisiones señalando la sustitución por `ADR-030` en desarrollo.

---

## 2026-08-14 · Cierre de 0.3 y 0.5, MCP de Boost y Playwright

### Nuevo: `apps/web` (Vue 3 + TypeScript + Vite)
- Tailwind v4 + shadcn-vue inicializados (tema con variables CSS, sin la fuente de Google que trae la plantilla por defecto: llamada a un tercero en cada carga, cuestión de privacidad en un producto que trata datos de menores).
- `vue-router`, `AppLayout` + `HomeView`, `src/modules/` (espejo de `apps/api/app/Modules/`, vacío hasta 1.1).
- Cliente API propio (`src/api/client.ts`, `fetch` nativo sin librería) con `ApiError` tipado y `credentials: 'include'` ya previsto para la cookie de sesión (`ADR-025`).
- ESLint (flat config) + Prettier, Vitest (4 tests) y Playwright (1 e2e, verificado contra el servidor real) en verde.

### `compose.yaml` → 0.3.0
Servicio `web` añadido al perfil reducido (`infra/containers/web/Containerfile`), que queda con `postgres`+`redis`+`api`+`web` por defecto.

### `.mcp.json` (nuevo, raíz del repo)
Laravel Boost (`laravel/boost` en `apps/api`, `php artisan boost:install --mcp`) y Playwright (`@playwright/mcp`). El instalador de Boost escribió el comando envuelto en `wsl.exe`; corregido a mano porque Claude Code ya corre dentro de WSL2.

### `SYSADMIN.md` → 0.3.0
Documentado el servicio `web` y por qué `VITE_API_URL` no se sobrescribe dentro del contenedor (quien hace la petición es el navegador de Windows, no el contenedor).

---

## 2026-08-13 · Tarde · Cierre de 0.4

### Nuevo: `apps/api` (Laravel 13, PHP 8.4)
Primer código de aplicación del repositorio.
- `app/Modules/` con la convención de bounded context (`Domain`, `Application`, `Infrastructure`, `Http`, `INV-007`) y autodescubrimiento de `ServiceProvider` vía `App\Support\Modules\ModuleServiceProviderDiscovery`, sin registro manual en `bootstrap/providers.php`. Vacío hasta el paso 1.1.
- `GET /api/health`, documentado en `apps/api/openapi.yaml`.
- Pest configurado (4 tests, 8 aserciones) y Larastan nivel 6, ambos en verde.
- `routes/web.php` vaciado y `resources/views/welcome.blade.php` eliminada: backend puramente API (`INV-006`).

### `compose.yaml` → 0.2.0
Servicio `api` añadido al perfil reducido (`infra/containers/api/Containerfile`). Corregido un fallo propio de la imagen: purgar `libpq-dev`/`libzip-dev` con `--auto-remove` tras compilar las extensiones se llevaba las librerías compartidas en tiempo de ejecución (`libpq.so.5`, `libzip.so.4`) y `pdo_pgsql`/`zip` dejaban de cargar; el healthcheck no lo detectaba porque no toca la base de datos.

### `SYSADMIN.md` → 0.2.0
Documentado el servicio `api`: puerto, montaje de volumen, variables de entorno sobrescritas para resolución de nombres dentro de la red de contenedores.

---

## 2026-08-13 · Cierre de pasos 0.1, 0.2 y 0.3

### Nuevo: `LICENSE`
Propietaria, todos los derechos reservados. Titularidad jurídica definitiva pendiente de `OPEN-07`.

### Limpieza de 0.1
- Eliminado `SKILL.md` suelto en la raíz, duplicado de `.claude/skills/aislamiento-tenant/SKILL.md`.
- `.gitignore`: añadidos patrones de Python (`__pycache__/`, `*.pyc`, entornos virtuales) para `seed/`.

### `docs/SETUP-ENTORNO.md` → 1.3.0
Alta del MCP de GitHub con gestión segura del token (tres ámbitos de configuración, detección de token en claro en `~/.claude.json`), y cuatro pruebas de verificación del paso 0.2, incluida la prueba negativa de la Regla 0.

### Cierre de 0.2
Verificado con las cuatro pruebas de `docs/SETUP-ENTORNO.md` §7.4: MCP de GitHub confirmado creando y cerrando un issue de prueba. Pendiente sin resolver: `spec-writer` no aparece en la lista de subagentes disponibles de esta sesión pese a estar bien definido en `.claude/agents/spec-writer.md`.

### Nuevo: `compose.yaml`, `.env.example`, `SYSADMIN.md` → 0.1.0
Paso 0.3: perfil reducido (`postgres` + `redis` por defecto, `minio` tras `--profile full`), red externa `plataforma-net` sin destruir (`ADR-028`). Verificado arrancando ambos contenedores en estado `healthy`. `api`, `web` y el servicio de PDF quedan fuera a propósito: los dos primeros por los pasos 0.4/0.5, el tercero por no tener motor decidido.

---

## 2026-08-12 · Tarde

### `.gitignore` → corrección
- **Excluía `marketing/*.pdf`**, lo que habría dejado fuera del repositorio la propia presentación comercial. Ahora solo se ignoran los renders intermedios (`slide-*.jpg`) y `build/`.

### Nuevo: `docs/SETUP-ENTORNO.md` → 1.1.0
Guía completa de puesta en marcha: WSL2 con límite de recursos, claves SSH, GitHub, Podman con red externa, Node, PHP, Claude Code, repositorio con ramas protegidas, plugins y MCP. Con lista de comprobación y problemas frecuentes.
- **1.1.0**: punto 6.3 reescrito con el árbol completo de los 53 ficheros, tabla de qué no se sube y verificación de recuento.

### Nuevo: `marketing/`
Presentación comercial de 15 diapositivas. Nombre de marca **provisional**.

### `PLAN-IMPLEMENTACION.md` → 2.2.0
- Paso **0.10f** (presentación comercial) marcado como completado.
- Nuevo paso **0.11b**: web publicitaria.
- Nuevo paso **0.11c**: identidad de marca, bloqueante de la web.

### `README.md` → 2.1.0
- Índice ampliado con la guía de entorno, el generador y marketing.

---

## 2026-08-12

### `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` → 3.1.0
- **`ADR-032`**: fuente única de autorizaciones de recogida de menores. El concepto estaba definido dos veces —en `REQ-PRL-004` (fase 3) y en `REQ-TRAN-005` (fase 2)— con listas separadas que podían divergir.
- Nuevo **`REQ-FAM-UNIT-005`**: lista maestra de personas autorizadas, con foto y documento, en fase 1.
- `REQ-PRL-004` reducido al proceso operativo de entrega y **adelantado a fase 1**.
- `REQ-TRAN-005` pasa a consumir la lista maestra.

### `PLAN-IMPLEMENTACION.md` → 2.1.0
- Nuevo paso **1.14b**, marcado como crítico.

### Skills
- `datos-personales` → 1.1.0: sección de autorizaciones de recogida.

### `seed/`
- Generador de datos sintéticos ejecutable, con verificador. Tres centros generados.
- Autorizaciones de recogida trasladadas de la suscripción de transporte a la unidad familiar.

---

## 2026-08-11 · Tarde

### `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` → 3.0.0

Versión mayor: cambia el entorno de trabajo y se reordena una fase.

- **`REQ-TRAN` (transporte escolar) reescrito**: de 3 requisitos genéricos a 12. Reubicado de COULD/fase 4 a **SHOULD/fase 2** (`ADR-031`). Incorpora autorizaciones de recogida, registro de subida y bajada con alerta de discrepancia, acompañante de ruta, certificación negativa del RCDS con bloqueo, empresa como encargado de tratamiento e integración en la factura mensual.
- **Nuevo módulo `REQ-SEED`** (datos de demostración), MUST de fase 1: tres centros ficticios de régimen distinto, entre 300 y 1.200 alumnos, plantilla completa de personal, convención de datos sintéticos y bloqueo en producción.
- `ADR-030`: entorno de desarrollo en WSL2 y separación respecto al alojamiento.
- `ADR-031`: alcance y fase del transporte escolar.
- Cerrada `OPEN-06` (titularidad de la infraestructura). Abierta **`OPEN-11`**: dónde se aloja el piloto.
- Total: **53 módulos, 31 ADR**.

### `CLAUDE.md` → 2.0.0
- Desarrollo en WSL2 con perfil reducido.
- **Prohibición explícita de datos reales en desarrollo**, sin excepción.
- Convención de datos sintéticos de `REQ-SEED-005`.

### `ARCHITECTURE.md` → 2.0.0
- Etapa E0 pasa a WSL2; nueva etapa E0b para el piloto.
- Tabla de recursos de desarrollo con límite de `.wslconfig` y perfil reducido.
- Advertencia de que las mediciones de rendimiento en el equipo personal son orientativas.

### `PLAN-IMPLEMENTACION.md` → 2.0.0
- Paso 0.3 reescrito para WSL2.
- Paso 0.10 pasa a ser la decisión de alojamiento del piloto (`OPEN-11`).
- Nuevo paso **1.15b**: generador de datos de demostración.
- `REQ-TRAN` movido a fase 2, inmediatamente después del módulo económico.
- Fase 1: 17 módulos.

### `README.md` → 2.0.0
- Regla 0: ningún dato real en desarrollo.
- Tabla de versiones y bloqueantes actualizados.

---

## 2026-08-11 · Mañana

### `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` → 2.6.0
- `ADR-028`: topología de red y dependencias entre contenedores.
- `ADR-029`: identificador público ULID y convenciones de tipos en PostgreSQL.
- Ambos en fichero propio, estrenando la regla de `ADR-026`.
- Nuevas decisiones abiertas `OPEN-06` a `OPEN-10`.

### `CLAUDE.md` → 1.2.0
- Podman en lugar de Docker.
- Reglas de red y dependencias de contenedores.
- Convenciones de esquema de `ADR-029`.
- ADR `001`-`027` canónicos en la sección 18; del `028` en adelante, fichero propio.

### `ARCHITECTURE.md` → 1.2.0
- `ADR-027`: Podman sobre RHEL 10 en VM VMware.
- `ADR-028`: red y dependencias.
- Sección 4.3 de red entre contenedores.
- Tabla de dimensionado para host único.

### `PLAN-IMPLEMENTACION.md` → 1.1.0
- Corregido el conteo de módulos de fase 1: son 16, no 9. Estimación revisada a 6-8 meses.
- Nuevos pasos 0.10b a 0.10e: dominio y DNS, correo transaccional, destino de copias, staging.
- Nuevos pasos 0.12 (marco legal del proveedor) y 0.13 (plantillas de documentación).

### `docs/SETUP-CLAUDE-CODE.md` → 1.2.0
- Evaluación de MCP: se adopta Context7; se descartan Filesystem, Laravel Codebase MCP y Figma; se aplazan Sentry y Kubernetes.
- Restricción de solo lectura y fuera de producción para el MCP de PostgreSQL.
- Adopción de `timescale/pg-aiguide`.
- 10 skills propias y regla de contención.

### `README.md` → 1.1.0
- Creado como punto de entrada e índice.
- Tabla de versiones de documentos.

### Skills
| Skill | Versión |
|-------|---------|
| `aislamiento-tenant` | 1.0.0 |
| `contenedores-y-red` | 1.0.0 |
| `migracion-segura` | 1.0.0 |
| `postgres-rendimiento` | 1.1.0 (convenciones de `ADR-029`) |
| `depuracion` | 1.0.0 |
| `permisos-y-roles` | 1.0.0 |
| `datos-personales` | 1.0.0 |
| `modulo-nuevo` | 1.0.0 |
| `i18n-cuatro-idiomas` | 1.0.0 |
| `cierre-de-sesion` | 1.0.0 |

---

## Anterior

- **2.5.0** · Stack cerrado: Laravel + Vue 3/TS + PostgreSQL. `ADR-023` a `ADR-026`. Cerradas `OPEN-01` a `OPEN-05`.
- **2.4.0** · MFA por rol, módulo de copias de seguridad, despliegue sin interrupción.
- **2.3.0** · Backoffice de Super Administrador.
- **2.2.0** · Primer ciclo de Infantil 0-3, régimen por etapa, cuatro idiomas.
- **2.1.0** · Segmento concertados de Madrid, posicionamiento frente a Raíces.
- **2.0.0** · Reorganización para implementación asistida por IA. 22 módulos nuevos.
- **1.2.0** y anteriores · Versiones iniciales del documento de requisitos.
