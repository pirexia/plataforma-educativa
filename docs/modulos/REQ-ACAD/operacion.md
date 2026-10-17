# REQ-ACAD · Operación

> **Estado**: **`1.11d-a` (períodos) y `1.11d-b` (escalas) implementadas (2026-10-17, ramas `feature/REQ-ACAD-1-11d-a-periodos` y `feature/REQ-ACAD-1-11d-b-escalas`; la segunda sin PR todavía).** **APROBADA** (2026-10-10), paso **1.11**, con las recomendaciones de `funcional.md §15` aprobadas y `ADR-062`/`ADR-063` ACEPTADOS. **Implementado en `1.11-b`** (2026-10-12): ver `funcional.md §16` para las desviaciones aprobadas respecto a este documento.
>
> **`1.12`** (**PROPUESTA**, pendiente de aprobación): operación de horarios y asignación docente en **§12**, al final del fichero.
>
> **`1.11d`** (APROBADA, 2026-10-16): operación de períodos lectivos y escalas en **§11**, tras §10 (el §6.2 de `1.11-a`, que ya estaba fuera de orden al final del fichero, sigue detrás).

## 1. Variables de entorno

Ninguna.

## 2. Servicios externos de los que depende

Ninguno. Solo PostgreSQL. Sin Redis (nada se cachea entre peticiones; el curso activo y los resúmenes de curso académico se memoizan por petición, `RN-CURSO-24`), sin almacenamiento de objetos, sin cola, sin PDF.

## 3. Despliegue: registro de módulos y permisos (`ADR-034 §5`)

`php artisan platform:sync-registry` materializa por primera vez:

- En `modules`: `acad` con `name_key = 'modules.acad'`, `phase = '1'`, `depends_on = ['curso']`, `essential = false` (aprobado, `OPEN-ACAD-10`).
- En `permissions`: los cuatro códigos de `permisos.md §2`, `module_code = 'acad'`, `applicable_scopes = ['todos']`.

**Orden obligatorio**:

1. Migraciones del módulo, por el rol propietario (§6).
2. `platform:sync-registry`, antes de abrir tráfico.
3. **Contratación**: al ser no esencial, ningún centro lo tiene hasta que el Super Administrador lo contrate desde el *backoffice* (`ADR-045`, `REQ-BO-002`). En desarrollo y para el piloto, contratarlo explícitamente.
4. **Siembra de permisos en centros existentes**: comando nuevo `php artisan acad:grant-structure-permissions`, del mismo tipo que `curso:grant-year-permissions` y, como él, **en el módulo `Core`** (la escritura en `roles`/`permission_role` es de `Core`, `INV-007`): idempotente, solo roles predefinidos, no toca roles personalizados, solo centros en estado `Activo`, y **comprueba antes** que los cuatro permisos existen en `permissions` **con `retired_at IS NULL`** (issue #387, B-5; `CA-ACAD-036`). Si no, falla diciendo qué falta y no concede nada. Los centros nuevos los reciben de `ProvisionTenantDefaults`. En la misma entrega, `curso:grant-year-permissions` gana la misma comprobación (`OPEN-ACAD-14` = A, `CA-ACAD-041`); su comportamiento en el caso normal no cambia.
5. Abrir tráfico.

**Si `sync-registry` aborta**: un `depends_on` con código inexistente, un ciclo, o un ámbito fuera del vocabulario. Se corrige el descriptor y se vuelve a desplegar.

## 4. Tareas programadas y jobs

Ninguno. Toda operación es síncrona sobre pocas filas (`INV-012` no aplica). `RMOD-005`: el módulo no tiene *jobs* ni *listeners*.

## 5. Métricas y alertas

Ninguna nueva. Señal útil en el log: picos de `409 academic-year-closed` desde rutas de `acad` (pantalla que no respeta el modo solo lectura).

## 6. Migraciones

Seis migraciones aditivas (*expand*), una por tabla, en `apps/api/app/Modules/Acad/Database/migrations/`, cargadas por `AcadServiceProvider::boot()` con `loadMigrationsFrom` (`AR-03`). Orden: `education_levels` → `courses` → `groups` → `subjects` → `subgroups` → `group_subjects`.

- Todas con `TenantMigration::tenantTable()` y `tenantForeignId($table, 'academic_year_id', 'academic_years')`; el ayudante engancha el disparador `academic_year_write_guard` (con `lock_timeout` local) porque la tabla tiene la columna, y desde `1.11` crea también el índice `(tenant_id, academic_year_id)` (issue #407, `CA-ACAD-038`). Nunca `Schema::create` ni `CREATE TRIGGER` a mano (`AR-13`).
- Columnas `name_translations` con el ayudante de columna traducible de `TenantMigration` (`ADR-062 §5.1`), que emite el `CHECK`; nunca escrito a mano (`AR-16`).
- FK de tres columnas hacia el padre declaradas a mano (el ayudante solo emite dos; excepción justificada a la convención de #104), **sin** `cascadeOnDelete()`/`nullOnDelete()`/`cascadeOnUpdate()` (`AR-13`, `ADR-057 §5.2`).
- Índices de `datos.md §3` que no crea el ayudante, declarados explícitamente. Los únicos parciales requieren SQL o `->where()` según lo que admita la versión de Laravel; revisa `db-reviewer`.
- Tablas nuevas y vacías: crear índices sin `CONCURRENTLY` es correcto (no hay bloqueo apreciable). Las migraciones corren en transacción (necesario para `guardAcademicYearWrites`, que lo exige).
- **`db-reviewer` obligatorio.** Son las primeras tablas reales con el disparador.

Cambios asociados que no son migración (aprobados, `funcional.md §1.2`): `tenantForeignId()` crea el índice (#407, no retroactivo en las migraciones antiguas; el inventario de las 22 llamadas ya mezcladas y su convergencia están en `1.11-a` con la migración `2026_10_12_100000_add_tenant_foreign_key_indexes`, #412, ver más abajo); ayudante de columna traducible; regla `AR-16` (`ADR-062 §5.8`, `CA-ACAD-015`); aserción de no vacuidad de `AR-13` sobre el esquema real (`CA-ACAD-021`); regla `arch()` «solo migraciones y fixtures usan `TenantMigration`» y escaneo de `AR-14` ampliado a `database/`, `routes/` y `bootstrap/` (#387 B-4, `CA-ACAD-040`); validación del código de `permission:` contra el catálogo en `AR-07a` (#378 B3, `CA-ACAD-039`).

### 6.1 Medición del disparador con tabla real (issue #382, `CA-057-09`, `CA-ACAD-023`)

`AcademicYearWriteGuardOverheadTest` se amplía para medir sobre una tabla real del módulo (propuesta `subjects`, la más simple con FK de tres columnas) frente a una tabla de control idéntica sin disparador, con los mismos volúmenes de `1.10` (`CURSO_OVERHEAD_BULK`/`CURSO_OVERHEAD_SINGLE`): tres corridas, mediana de cinco repeticiones. La cifra va a `CHANGELOG.md` (entrada del paso) y a `memory.md`. Si difiere de la de la sonda de `1.10` más allá del ruido, se abre issue; la cifra no se ajusta. El issue #382 está cerrado, pero la obligación sigue en `PLAN-IMPLEMENTACION.md` (`1.11` y `1.15`).

## 7. Impacto en copias de seguridad y restauración

Tablas de tenant ordinarias: PITR y exportación lógica por tenant (`ADR-022`). Orden de restauración por FK: `academic_years` → `education_levels` → `courses` → `groups`, `subjects` → `subgroups`, `group_subjects`. Restaurar como propietario (exento del disparador; necesario para filas de cursos cerrados).

## 8. Problemas conocidos y diagnóstico

| Síntoma | Causa probable | Qué hacer |
|---------|----------------|-----------|
| `403 module-disabled` en todas las pantallas de estructura | `acad` no contratado para el centro | Contratarlo desde el *backoffice* |
| `404 curso.no_active_year` al abrir «Estructura académica» | Sin curso activo | Elegir curso en el selector o activar el de planificación |
| `404` en una ficha que existe | Curso cerrado y el usuario sin `curso_historico.leer` | Comportamiento correcto (`RN-ACAD-08`) |
| `409 academic-year-closed` al editar | Curso cerrado | Correcto; si es un error de cierre, reapertura en ventana T1 (`REQ-CURSO`, implementada en `1.11c`) |
| `409 has_dependents` al borrar | Hijos vivos | Borrar antes los hijos (`RN-ACAD-20`) |
| `500` con `23503` en un alta | Padre de otro curso académico colado por un fallo de servicio: la FK compuesta lo para | Bug: el servicio debe responder `422 parent_not_found` |
| Un usuario no puede conceder un permiso de otro módulo con ámbito `grupo` o `clase` | No hay resolutor (`ADR-063 §5.1`) | Correcto: falla en cerrado hasta que exista la asignación docente (`ADR-063 §5.4`). No se resuelve dando `todos` |
| `422 translations.default_locale_required` al guardar un nombre | Falta el idioma por defecto del centro, o el centro cambió de idioma por defecto y la fila no lo tiene | Añadir el nombre en el idioma por defecto (`ADR-062 §5.2`) |
| Un nombre aparece en otro idioma que el de la pantalla | Falta la traducción en ese idioma: se muestra la caída (`ADR-062 §5.3`) | Correcto; añadir la traducción |
| El comando de siembra no concede nada | `sync-registry` no se ejecutó o algún permiso está retirado | Ejecutar `sync-registry`; revisar `retired_at` |

## 9. Reversión de la entrega

Desplegar la imagen anterior (`RUNBOOK.md`). **Las migraciones no se revierten**: son aditivas, la versión anterior no usa las tablas, y su `down()` borraría datos de estructura del centro. El siguiente `sync-registry` de la versión anterior marca `modules.acad` y sus permisos con `retired_at` (concesiones inertes). Los cambios en `curso` (`OPEN-ACAD-11`/`-12`) y en `Core` (comandos) se revierten con la imagen: `AcademicYearDirectory` gana métodos (aditivo) y `GET /academic-years/selectable` desaparece sin consumidores fuera de la SPA de la misma versión. Los ayudantes de `TenantMigration` y el desglose de auditoría también vuelven atrás; las filas de `audit_logs` ya escritas con claves desglosadas (`name_translations.es-ES`) se quedan (`audit_logs` es inmutable) y la versión anterior las muestra como claves desconocidas, sin fallar **[DERIVADA; comprobar al probar la reversión]**. Procedimiento a probar antes de mezclar (`CLAUDE.md §9`).

## 10. Documentación a actualizar en el cierre

- `docs/manual-usuario/admin.md`: actualizar (estructura académica, selector, nombres en varios idiomas).
- `docs/manual-usuario/direccion.md`, `secretaria.md` y `docente.md`: **ficheros nuevos, creados en `1.11-b`**, con la consulta de la estructura que les da la siembra aprobada (`CLAUDE.md §6.4`).
- `SYSADMIN.md`: módulo nuevo en `sync-registry`, contratación, comando de siembra y su comprobación de `retired_at`.
- `ARCHITECTURE.md §3.4`, en una sola pasada:
  - referencias de patrón: primer módulo de negocio con tablas por curso (migración con FK de tres columnas, lectura con `AcademicYearReadAccess`) y ayudante de columna traducible; revisión de la tabla de referencias que `PLAN 1.11b` pide al cerrar `1.11`;
  - fila de **`AR-07a`**: la lista cerrada de excepciones (34 antes de `1.11-a`, 35 con él) gana `GET /academic-years/selectable`, y la regla gana la validación del código de `permission:` contra el catálogo (#378 B3);
  - fila de **`AR-13`**: aserción de no vacuidad sobre el esquema real;
  - fila de **`AR-14`**: escaneo ampliado a `database/`, `routes/` y `bootstrap/` (#387 B-4);
  - filas nuevas: **`AR-16`** (`ADR-062 §5.8`) y la regla `arch()` «solo migraciones y *fixtures* usan `TenantMigration`».
- `apps/api/app/Modules/README.md` («hoy hay cuatro») y `apps/web/src/modules/README.md` (dice «hoy hay dos», sin `curso`: issue #408, `CA-ACAD-052`).
- `docs/i18n.md`: contenido del centro traducible según `ADR-062` (columna, validación, resolutor, códigos `translations.*`, componente de la SPA); retirar la línea de «Qué falta».
- `CHANGELOG.md`, con la cifra de `CA-ACAD-023` (hecho en `1.11-b`).
- `docs/modulos/REQ-CURSO/` (`GET /academic-years/selectable`, ampliación de `AcademicYearDirectory`, comprobación de `retired_at`; lo hace la sesión principal).
- `PLAN-IMPLEMENTACION.md` (sub-paso `1.11d`, orden `1.13`/`1.15` de `OPEN-ACAD-22`, y la obligación y el plazo de `ADR-063 §5.4` en `1.12`, `1.13` y `1.16`; lo hace la sesión principal).

## 11. `1.11d` · Períodos lectivos y escalas (APROBADA, 2026-10-16)

### 11.1 Variables de entorno, servicios, colas y tareas

Ninguna variable nueva, ningún servicio externo, **ninguna cola ni tarea programada**: toda operación es síncrona sobre decenas de filas (una etapa tiene unos pocos períodos; una escala, como mucho 100 valores, `RN-ACAD-89`); `INV-012` no aplica. Sin caché entre peticiones: las fechas del curso con que se valida un período salen de la lectura con bloqueo compartido de `curso` (`AcademicYearSharedLock`, sin memoización, `RN-ACAD-57`); `AcademicYearDirectory` (memoizado por petición) sigue sirviendo para resolver y autorizar la lectura.

### 11.2 Despliegue

1. Migraciones del módulo (*expand*, aditivas), por el rol propietario: `…_create_academic_periods_table`, `…_create_grading_scales_table`, `…_create_grading_scale_values_table`, en ese orden (la tercera depende de la segunda), en `apps/api/app/Modules/Acad/Database/migrations/`, con `TenantMigration::tenantTable()`, `tenantForeignId()` y `translatableColumn()`; FK de tres y cuatro columnas a mano con nombre explícito, **sin** acción referencial (`AR-13`); `CHECK` e índices parciales por sentencia del propietario, como `2026_10_13_100200_create_courses_table.php`. Tablas nuevas y vacías: sin `CONCURRENTLY` y `ADD CONSTRAINT` sin `NOT VALID`. Ninguna altera una tabla existente: no hay fase *contract*.
2. `php artisan platform:sync-registry`: materializa los cuatro códigos de `configuracion_academica` (`module_code = 'acad'`, `applicable_scopes = ['todos']`). El descriptor del módulo no cambia (`depends_on: ['curso']`, `essential: false`).
3. Siembra en centros existentes (`OPEN-ACAD-28` decidida: `php artisan acad:grant-structure-permissions` ampliado a los ocho códigos, con su comprobación de `retired_at`). Centros nuevos: `ProvisionTenantDefaults`.
4. Abrir tráfico. Sin cambios de contratación: el módulo es el mismo `acad`.
5. **`db-reviewer` obligatorio** (tres migraciones, FK de cuatro columnas, `CHECK` entre columnas con `round()`); `security-reviewer` (permisos nuevos, sincronización de valores por `public_id` ajeno).

Por `OPEN-ACAD-24` = B y `OPEN-CURSO-25` a `-27` (decididas), el punto de extensión del cambio de fechas, la lectura con bloqueo compartido y el filtro por módulo contratado de `curso` se despliegan en la misma imagen, sin migración ni configuración (`REQ-CURSO/operacion.md §3.2`). La validación de `acad` se registra al arrancar (`AcadServiceProvider::boot()`).

### 11.3 Rendimiento esperado

Del orden de 2-12 períodos y 1-10 escalas por centro y curso académico, con decenas de valores en total. El listado de escalas carga sus valores con una consulta (*eager loading*, nunca N+1) acotada por `per_page`. La validación de solape y tipo lee los períodos vivos de una etapa por el índice de la FK (`datos.md §3.1`). El bloqueo `FOR UPDATE` de la etapa serializa solo las escrituras de períodos **de esa etapa**, raras y humanas. El `FOR SHARE` sobre la fila del curso que toman el alta y la edición de un período (`RN-ACAD-54`) no hace esperar a ninguna escritura ordinaria; solo a un `PATCH` de fechas, un cierre o una reapertura de ese curso, mientras dura la transacción del período. La validación de `acad` en un `PATCH` de fechas es una consulta agregada sobre los períodos vivos del curso (decenas de filas) por el índice `(tenant_id, academic_year_id)`. Coste del disparador por fila (`CA-ACAD-023`): irrelevante con este volumen.

### 11.4 Métricas y diagnóstico

Sin métricas nuevas. Problemas conocidos:

| Síntoma | Causa probable | Qué hacer |
|---------|----------------|-----------|
| `403` en «Escalas de calificación» con `estructura_academica.*` concedido | Son recursos distintos (`RN-ACAD-50`) | Conceder `configuracion_academica.*` o ejecutar la siembra |
| No aparece la sección de períodos en la ficha de etapa | Falta `configuracion_academica.leer` | Ídem |
| `409 has_dependents` con «períodos lectivos» al borrar una etapa | `RN-ACAD-61` | Borrar antes sus períodos |
| `422 period_type_mismatch` al cambiar de trimestres a semestres | Un solo tipo por etapa (`RN-ACAD-59`) | Borrar los períodos de la etapa y crear los nuevos |
| `422 bands_not_contiguous` tras cambiar el máximo de una mixta | Los tramos ya no cubren la escala (`RN-ACAD-85`) | Enviar los tramos ajustados en el mismo `PATCH` |
| `500` con `23514`/`23503` en una escritura de escala o valor | El servicio dejó pasar algo que el motor rechaza | *Bug*: debe ser `422`; abrir issue |
| `409 curso.conflict.date_change_checks_failed` con `acad.conflict.periods_outside_academic_year` al cambiar las fechas de un curso en planificación | Las fechas nuevas dejan fuera períodos lectivos vivos (`RN-ACAD-62`); `params` dice qué intervalo deben incluir | Comportamiento correcto: ajustar o borrar antes esos períodos en la ficha de cada etapa, o elegir fechas que incluyan el intervalo |
| Períodos fuera de las fechas del curso | Solo posible si las fechas se cambiaron con `acad` descontratado (`REQ-CURSO RN-CURSO-52`, decisión del usuario) o con una versión anterior a `1.11d-a` | Editar o borrar esos períodos; la siguiente edición de cada uno exige `RN-ACAD-57`. Si aparece con `acad` contratado, es un *bug*: abrir issue |
| `422 period_outside_academic_year` en un alta de período que antes de enviarla cabía en el curso | Un `PATCH` de fechas del curso confirmó mientras el alta esperaba su bloqueo (`RN-ACAD-54`, `-57`) | Comportamiento correcto: recargar la ficha para ver las fechas nuevas del curso |

### 11.5 Copias y restauración

Tablas de tenant ordinarias (PITR, exportación por tenant). Orden: `academic_years` → `education_levels` → `academic_periods`; `academic_years` → `grading_scales` → `grading_scale_values` (`datos.md §6`). Como propietario (exento del disparador).

### 11.6 Reversión

Desplegar la imagen anterior. **Las migraciones no se revierten**: aditivas, la versión anterior no usa las tablas, y `down()` (`DROP TABLE IF EXISTS`) borraría configuración del centro. El `sync-registry` de la versión anterior marca los cuatro códigos de `configuracion_academica` con `retired_at` (concesiones inertes). El comando `acad:grant-structure-permissions` se amplía en `1.11d` a los ocho códigos (`OPEN-ACAD-28` = X + 1); la versión anterior vuelve a sembrar solo los cuatro de `estructura_academica`. Las filas de `audit_logs` con `academic_period`/`grading_scale`/`grading_scale_value` quedan (inmutables) y la versión anterior las muestra con un alias desconocido, sin fallar **[DERIVADA; comprobar al probar la reversión]**. Con `RN-ACAD-61`, la versión anterior permitiría borrar una etapa con períodos vivos (lógicamente): sin daño de integridad (borrado lógico, FK `NO ACTION`), pero el período quedaría huérfano lógico; se acepta durante la reversión y se documenta en `RUNBOOK.md`. Procedimiento a probar antes de mezclar (`CLAUDE.md §9`).

### 11.6 bis Estado de `1.11d-b` (2026-10-17)

Implementada. Sin variables, servicios, colas ni tareas programadas nuevos (§11.1). Dos migraciones aditivas (`2026_10_17_100200_create_grading_scales_table.php`, `2026_10_17_100300_create_grading_scale_values_table.php`), tablas nuevas y vacías; ningún cambio en `curso`. Procedimiento de despliegue y reversión: §11.2 y §11.6 sin cambios (imagen anterior, migraciones sin revertir). El `GradingScaleService` toma solo `FOR UPDATE` de la fila de la escala (en `PATCH` y `DELETE`), no la del curso (`funcional.md §17.4`). **Pendiente antes de dar el paso por cerrado**: ejecutar el E2E `CA-ACAD-101` de escalas (`apps/web/e2e/acad-scales.spec.ts`; `test:e2e:real` recrea el centro demo, por eso no se lanzó), revisiones `db-`/`security-`/`doc-reviewer` y suite completa.

### 11.7 Documentación a actualizar en el cierre de `1.11d`

`docs/manual-usuario/admin.md` (períodos y escalas) y `direccion.md`, `secretaria.md`, `docente.md` (ya existen; se amplían con la consulta que da la siembra) (ya hecho: las escalas se ampliaron en `1.11d-b` en los cuatro manuales de usuario, `admin.md`, `direccion.md`, `secretaria.md` y `docente.md`); `RUNBOOK.md` (reversión conjunta de `1.11d-a` en `acad` y `curso`, §11.6 y `REQ-CURSO/operacion.md §9.2`, incluido el período huérfano lógico si se revierte con etapas borradas); `SYSADMIN.md` (permisos nuevos y comando de siembra); `ARCHITECTURE.md §3.4` (referencia de patrón: FK de cuatro columnas que arrastra un discriminador, sincronización de un conjunto hijo por `public_id`) **no se actualiza en `1.11d-b`: se difiere al cierre completo de `1.11`** (anotado en `PLAN-IMPLEMENTACION.md`); `CHANGELOG.md`; `PLAN-IMPLEMENTACION.md` (entregas de `OPEN-ACAD-31`); `apps/api/openapi/paths/acad.yaml` (cabecera); y `docs/modulos/REQ-CURSO/` (ya especificado el 2026-10-16: `OPEN-ACAD-24` = B y `OPEN-CURSO-25` a `-27`; en el cierre, contrastarlo con lo implementado).

### 6.2 Convergencia de índices de clave foránea ya mezclada (`1.11-a`, issue #412)

`tenantForeignId()` crea el índice `(tenant_id, columna)` desde `1.11-a`, pero las **22** llamadas que ya estaban en `develop` se ejecutaron antes: una base migrada con la versión anterior no lo tiene y una instalación nueva sí. La migración *expand* `database/migrations/2026_10_12_100000_add_tenant_foreign_key_indexes.php` lo crea donde falta, con **el mismo nombre** (`TenantMigration::indexName()`) que una instalación nueva:

- Un índice «cubre» la clave foránea si es válido, `btree`, **no parcial**, sin expresiones y empieza por `(tenant_id, columna)`. Un índice parcial (`WHERE deleted_at IS NULL`…) no la cubre. Solo se crea donde nada la cubre: **18 de las 22** en una base migrada con la versión anterior; en una instalación nueva ninguno (los crea el ayudante). Las otras 4 ya tenían un índice completo a mano.
- `CREATE INDEX CONCURRENTLY IF NOT EXISTS` con `$withinTransaction = false` (no bloquea escrituras). Idempotente; un índice homónimo INVÁLIDO por un fallo anterior se elimina y se recrea. Si se interrumpe, basta repetir `php artisan migrate`.
- `down()`: `DROP INDEX CONCURRENTLY IF EXISTS` de los **18** nombres que `up()` puede crear; no toca los 4 índices manuales que cubren las otras claves. En una instalación nueva esos 18 los creó el ayudante, no esta migración, y se retiran igualmente.
- **Instalación nueva y base migrada convergen en 18 índices del ayudante.** Para ello, y como **excepción deliberada a «las migraciones mezcladas no cambian»**, las cuatro migraciones antiguas que ya declaran a mano un índice completo (`user_known_devices`, `mfa_resets` —solo `user_id`; `performed_by` conserva el suyo—, `saml_consumed_assertions`, `data_exports`) pasan `withIndex: false` a `tenantForeignId()`. No altera el esquema de ninguna base ya migrada y no existe producción. `saml_identity_provider_settings.identity_provider_id` NO está entre ellas: su único índice con ese prefijo es parcial (`WHERE deleted_at IS NULL`), y recibe el del ayudante.
- Por qué un parcial no cubre la FK: el disparador de integridad referencial ejecuta `SELECT 1 FROM ONLY hija WHERE tenant_id = $1 AND col = $2 FOR KEY SHARE` sin el predicado del parcial, y solo se dispara en el `DELETE` físico del padre (purga de tenant, derecho al olvido físico).
- Sin dos `migrate` a la vez: `php artisan migrate --isolated`. `CREATE INDEX CONCURRENTLY` espera a transacciones largas sobre la tabla; ejecutarla en una ventana sin ellas (o con `lock_timeout` de sesión).
- **No se retira ningún índice existente** (fase *contract*, decisión del usuario). Candidatos a retirar, con evidencia de catálogo (sin estadísticas de uso en desarrollo): `user_mfa_recovery_codes_tenant_user_unused_idx` y `user_mfa_factors_tenant_user_confirmed_idx`, prefijo estricto, con el mismo predicado, de otro índice.
- Tests: `tests/Feature/Core/TenantForeignKeyIndexesMigrationTest.php`.

## 12. `1.12` · Horarios y asignación docente (**PROPUESTA**)

Pendiente de aprobación (`funcional.md §18`).

### 12.1 Variables, servicios, colas y tareas

Ninguna variable de entorno, ningún servicio externo, **ninguna cola ni tarea programada**: toda escritura es síncrona sobre decenas de filas (la detección de conflictos lee las sesiones de un profesor, un aula o un grupo en un curso). `INV-012` no aplica en `1.12`; aplicará a la exportación PDF cuando se especifique (`OPEN-ACAD-38`), que necesitará el servicio de PDF y un *worker* que hoy no existen (issue #128). Sin caché entre peticiones.

### 12.2 Despliegue

1. Migraciones (*expand*), por el rol propietario, en `apps/api/app/Modules/Acad/Database/migrations/`, en este orden: (a) únicos nuevos en `group_subjects` y `subgroups` (`datos.md §9.3`: índice `CONCURRENTLY` fuera de transacción y `ADD CONSTRAINT … USING INDEX`; tablas ya en `develop`); (b) `facilities`; (c) `timetables`; (d) `teaching_assignments`, `group_tutors`; (e) `timetable_frames`, `time_slots`, `timetable_sessions`; en `1.12-b`, `facility_bookings`. Con `TenantMigration::tenantTable()`, `tenantForeignId()` y `translatableColumn()`; FK de tres columnas a mano, **sin** acción referencial (`AR-13`). Antes de escribirlas, comprobar que `AR-04` admite `time` (`OPEN-ACAD-40`).
2. `php artisan platform:sync-registry`: materializa los códigos nuevos de `permisos.md §10.1`. Descriptor sin cambios.
3. Siembra en centros existentes: `php artisan acad:grant-structure-permissions` ampliado (`permisos.md §10.3`). Centros nuevos: `ProvisionTenantDefaults`.
4. Abrir tráfico.
5. Revisiones: **`db-reviewer`** (ocho tablas, FK con discriminador y `NULLS NOT DISTINCT`, cambios en dos tablas existentes, ancla de serialización); **`security-reviewer`** (asignación docente, nombres de profesorado en respuestas, resolutor `propios` y ampliación de `AR-10` en `1.12-b`); `doc-reviewer`.

### 12.3 Rendimiento

≈ 1.200 sesiones por curso en un centro de 40 grupos. La escritura de una sesión toma `FOR UPDATE` del ancla del curso: serializa todas las escrituras de horario de ese curso en el centro, que son humanas y raras; una transacción dura milisegundos (tres lecturas indexadas y una inserción). Las lecturas no bloquean. `GET /timetable-sessions` carga resúmenes con *eager loading*, sin N+1, con tope de 500 filas.

### 12.4 Diagnóstico

| Síntoma | Causa probable | Qué hacer |
|---------|----------------|-----------|
| `422 timetable_conflict` | Profesor, aula o grupo ya ocupados a esa hora (`RN-ACAD-123`) | Comportamiento correcto; `params.conflicts` nombra la sesión |
| `409 has_dependents` al cambiar la hora de una franja o borrar una asignación | Tiene sesiones (`RN-ACAD-114`, `-95`) | Mover o borrar antes las sesiones |
| `409 has_dependents` al quitar una asignatura a un grupo | Tiene profesorado asignado (`RN-ACAD-96`) | Borrar antes las asignaciones |
| `422 user_not_found` con un usuario que existe | Inactivo o de otro centro | Reactivarlo en `Core` |
| No se puede borrar un aula | Sesiones o reservas en un curso en planificación o activo (`RN-ACAD-102`) | Moverlas o borrarlas |
| Dos sesiones en conflicto confirmadas | Escritura que no pasó por el servicio o no tomó el ancla | *Bug*: abrir issue (`RN-ACAD-125`) |
| `500` con `23503`/`23514` en una escritura | El servicio dejó pasar algo que el motor rechaza | *Bug*: debe ser `422` |

### 12.5 Copias y reversión

Tablas de tenant ordinarias (PITR, exportación por tenant); orden de restauración en `datos.md §9.6`; como propietario (exento del disparador). **Reversión**: imagen anterior; las migraciones no se revierten (aditivas; `down()` borraría el horario del centro). Los únicos de §9.3 se quedan: la versión anterior no los usa y no estorban. `sync-registry` de la versión anterior retira los códigos nuevos (concesiones inertes). Con la versión anterior, `RN-ACAD-96` deja de aplicarse: se podría borrar lógicamente un grupo o una asignatura con asignaciones vivas (huérfanos lógicos, sin daño de integridad: FK `NO ACTION`, borrado lógico); se documenta en `RUNBOOK.md`. Procedimiento a probar antes de mezclar (`CLAUDE.md §9`).

### 12.6 Documentación a actualizar en el cierre

`docs/manual-usuario/admin.md` (espacios, marcos, asignación docente, horario, conflictos; reservas en `1.12-b`), `direccion.md` (composición del horario, según la siembra), `secretaria.md`, `docente.md` (consulta del horario; reservas en `1.12-b`); `SYSADMIN.md` (permisos nuevos y siembra); `ARCHITECTURE.md §3.4` (patrón: ancla de serialización por curso para reglas entre filas sin `btree_gist`; rejilla a medida); `RUNBOOK.md` (reversión); `CHANGELOG.md`; `PLAN-IMPLEMENTACION.md` (entregas de `OPEN-ACAD-44`, y en `1.13`/`1.16` que el plazo de `ADR-063 §5.4` queda cumplido al aprobarse §18). Si `OPEN-ACAD-40` se resuelve con ADR, lo redacta `architect` en `docs/adr/`.
