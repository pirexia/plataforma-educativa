# REQ-CURSO · Operación

> **Estado**: **APROBADA** (2026-10-07), paso **1.10**, con los ajustes de `ADR-057` (aceptado). **Ampliada el 2026-10-08 por `ADR-059`** (aceptado, reapertura de un curso cerrado): ver §3.1, §6.1, §7 y §9.1.

## 1. Variables de entorno

**Ninguna.** El módulo no tiene configuración por entorno: no hay plazos, ni límites, ni proveedores externos. No toca `infra/quadlet/plataforma.env.example` (issue #89 sigue abierto por otras variables).

## 2. Servicios externos de los que depende

**Ninguno.** Solo PostgreSQL, como todo el producto. No usa Redis (`RN-CURSO-24`: el curso activo se memoiza por petición, sin caché compartida), ni almacenamiento de objetos, ni cola, ni el servicio de PDF.

## 3. Despliegue: registro de módulos y permisos (`ADR-034 §5`)

`php artisan platform:sync-registry` materializa, por primera vez:

- En `modules`: la fila `curso` con `name_key = 'modules.curso'`, `phase = '1'`, `essential = true` y `depends_on = []` (aprobado, `OPEN-CURSO-01`).
- En `permissions`: los cinco códigos de `permisos.md §2`, con `module_code = 'curso'` y `applicable_scopes = ['todos']`.

**Orden obligatorio del despliegue** (mismo que el resto de módulos):

1. Migraciones, ejecutadas por el rol propietario como todas (`TenantMigration`): 1.10 trae **una**, la función del bloqueo de escritura (§6).
2. `platform:sync-registry`, **antes** de abrir tráfico: las FK de `permission_role` apuntan a `permissions`.
3. Siembra de los permisos nuevos en los roles predefinidos de los **centros ya existentes**: `php artisan curso:grant-year-permissions`, que **vive en el módulo Core** (`App\Modules\Core\Infrastructure\Console\GrantAcademicYearPermissionsCommand`) aunque su prefijo sea `curso:` (la escritura en `roles`/`permission_role` es de Core, `INV-007`); comando idempotente de migración de datos del mismo tipo que el de `REQ-PERM/operacion.md §4.3` (añade las concesiones de `permisos.md §4` a los roles predefinidos que no las tengan y **no** toca roles personalizados; como los cinco permisos son nuevos, ningún centro ha podido quitarlos antes, así que no hay decisión del centro que pisar). Los centros nuevos los reciben de `ProvisionTenantDefaults`. **Comprueba primero que `platform:sync-registry` ya se ejecutó** (los cinco permisos están en `permissions`): si no, falla con un mensaje que lo dice y no concede nada. **Solo recorre los centros en estado `Activo`**: un centro suspendido, en baja o aún aprovisionándose no recibe las concesiones y, al reactivarlo, hay que volver a ejecutar el comando (#385).
4. Abrir tráfico.

### 3.1 Despliegue de la reapertura (`ADR-059`)

Mismo orden que arriba, en la entrega que implemente la reapertura (el paso lo fija `PLAN-IMPLEMENTACION.md`):

1. Migración: crea `academic_year_reopenings` (§6.1).
2. `platform:sync-registry`: materializa `reapertura_curso_academico.actualizar` (`module_code = 'curso'`, `applicable_scopes = ['todos']`).
3. Concesión a `administrador_centro` en los **centros ya existentes**: **pendiente de `OPEN-CURSO-24`** (`funcional.md §15.2`). No se documenta un comando hasta que se decida: volver a ejecutar `curso:grant-year-permissions` con la lista ampliada podría re-conceder permisos de 1.10 que un centro hubiera retirado. Los centros nuevos lo reciben de `ProvisionTenantDefaults`.
4. Abrir tráfico.

Sin variables de entorno, colas ni tareas programadas nuevas: la reapertura es una transición síncrona sobre una fila, como el cierre (`INV-012` no aplica).

**Si `sync-registry` aborta**: las causas posibles con este módulo son un ciclo en `depends_on` (si se declarara `['acad','alum']`, `OPEN-CURSO-01`), un esencial que dependa de un no esencial, o un ámbito fuera del vocabulario. Ninguna se corrige en el servidor: se corrige el descriptor y se vuelve a desplegar.

## 4. Tareas programadas y jobs

**Ninguno.** Ninguna operación de 1.10 es pesada (`INV-012`): crear, editar y cambiar de estado una fila. El cierre ejecuta en la propia petición las validaciones registradas (vacías en 1.10) mientras retiene `FOR UPDATE` sobre la fila del curso, y **durante ese tiempo toda escritura del centro en ese curso espera** (`ADR-057 §5.4`). **Cuando un módulo registre una validación costosa** (p. ej. recorrer las calificaciones de todo un curso), su especificación debe decidir si el cierre pasa a ser una operación en cola o si valida fuera del bloqueo y revalida dentro.

**Trabajos en cola y comandos de otros módulos** que escriban en tablas de curso: el bloqueo les llega como `QueryException` con `SQLSTATE` `YC001`, que deja abortada la transacción en curso. Deben comprobar antes con `AcademicYearWriteGuard` o usar un punto de guardado por unidad de trabajo (`ADR-057 §5.5`). No hay activación automática por fecha (`RN-CURSO-14`), así que no hay tarea programada.

`RMOD-005` (módulos no contratados sin jobs ni *listeners*): no aplica, el módulo no tiene ninguno.

## 5. Métricas y alertas

Ninguna métrica nueva. Señales operativas útiles, a consultar en la auditoría y no en un panel:

- Centros **sin curso activo** durante más de unos días en periodo lectivo: probablemente un cierre sin activar el siguiente (`funcional.md §4.4` paso 5, `OPEN-CURSO-06`). Candidato a la ficha de salud del tenant de `REQ-BO-004` (1.6d) cuando haya centros reales; no se construye ahora.
- Picos de `409 urn:pge:error:academic-year-closed`: una pantalla que no respeta el modo solo lectura o un cierre prematuro.

## 6. Migraciones

**Una sola migración** (`ADR-057 §5.2`, `datos.md §1.4`): crea la función `app.assert_academic_year_writable()` en `apps/api/database/migrations/` (junto a la de `academic_years`), con `down()` que la elimina. No crea ni altera tablas ni columnas, ni engancha el disparador a ninguna tabla real (en 1.10 ninguna tiene `academic_year_id`). Es la primera función PL/pgSQL del proyecto: **`db-reviewer` es obligatorio** en el paso. Es aditiva y compatible con la versión anterior del código (*expand*): la versión anterior no la invoca.

**Requisito previo**: `GRANT CREATE ON SCHEMA app TO <rol propietario>` (`RUNBOOK.md` paso 0); sin él la migración aborta con el comando exacto. **Retirar la columna** `academic_year_id` de una tabla en una migración futura exige `DROP TRIGGER academic_year_write_guard ON <tabla>` **antes** de `DROP COLUMN` (`AR-13` falla si el disparador sobrevive).

### 6.1 Migración de la reapertura (`ADR-059`)

Una migración del módulo (`apps/api/app/Modules/Curso/Database/migrations/`) que crea `academic_year_reopenings` con `TenantMigration::tenantTableAppendOnly()` y `tenantForeignId(…, 'academic_year_id', 'academic_years')` (`datos.md §1.5`): recibe el disparador `academic_year_write_guard` como toda tabla de curso, sin excepción en `AR-13`, y sin acciones referenciales en ninguna FK. **No** altera `academic_years`, ni la función `app.assert_academic_year_writable()`, ni ningún disparador existente (`ADR-059 §5.3`). Aditiva (*expand*): la versión anterior del código no la usa. Revisión de `db-reviewer` obligatoria, como toda migración.

Cambios asociados que **no** son migración: `TenantMigration::tenantTable()`/`tenantTableAppendOnly()` enganchan el disparador a toda tabla nueva con `academic_year_id`, y `TenantMigration::guardAcademicYearWrites()` lo hace para tablas existentes; la regla `AR-13` lo vigila. Las migraciones que rellenen columnas de tablas de curso en entregas futuras se ejecutan, como todas, por el rol propietario, exento del bloqueo.

El modelo `AcademicYear` y su enumerado se mueven al módulo (`OPEN-CURSO-03`, aprobada) y el alias del *morph map* pasa de `AppServiceProvider` a `CursoServiceProvider` **con el mismo valor** (`academic_year`): ninguna fila de `audit_logs` cambia de significado. La migración de 0.8.2 se queda donde está.

## 7. Problemas conocidos y diagnóstico

| Síntoma | Causa probable | Qué hacer |
|---------|----------------|-----------|
| `409 curso.conflict.active_exists` al empezar el curso nuevo | El anterior sigue `activo` (`OPEN-CURSO-06`: hay que cerrarlo antes) | Cerrar el anterior y activar el nuevo. Si el anterior no puede cerrarse aún, es el hueco operativo de `OPEN-CURSO-06` |
| `404 curso.no_active_year` en pantallas que listan datos | No hay curso activo (centro nuevo, o entre cierre y activación) | Activar el curso en planificación |
| Un curso se cerró por error | Cierre prematuro (`ADR-059`) | **Dentro de la ventana T1** (no hay otro curso `activo`, es el cerrado más reciente y ninguna validación de reapertura lo impide): lo reabre desde la aplicación quien tenga `reapertura_curso_academico.actualizar` (por defecto, el Administrador de Centro), con motivo obligatorio, «Reabrir curso» en la ficha o `POST /academic-years/{id}/reopen` (`funcional.md §4.6`). Queda en `audit_logs` y en `academic_year_reopenings`. **Fuera de la ventana** (el curso siguiente ya está `activo`, hay un curso cerrado posterior, o el curso está `archivado`): **sigue sin procedimiento**; solo la rectificación por módulo cuando exista (`OPEN-CURSO-19`). **En ningún caso** se reabre con SQL a mano, con las credenciales del propietario ni desactivando el disparador: saltaría la auditoría (`INV-003`) y está prohibido (`ADR-057 §5.7` punto 6, `RN-CURSO-47`) |
| `409 curso.conflict.reopen_not_latest` al reabrir | Hay un curso cerrado (o archivado) posterior al que se quiere reabrir | Comportamiento correcto: solo se reabre el cerrado más reciente (`RN-CURSO-41`). No hay vía para el anterior |
| `409 curso.conflict.active_exists` al reabrir | El curso siguiente ya está `activo` (ventana T2) | Comportamiento correcto. No se cierra el curso en curso para reabrir el anterior (`ADR-059 §3`) |
| Códigos de error saliendo como `core.curso.*` | Issue #60 | Ver ese issue |
| `500` en vez de `409` al crear o activar a la vez | Una violación de índice único no traducida | Bug: la traducción de `RN-CURSO-04`/`-11` falla; `CA-CURSO-009` debe detectarlo |
| `409 urn:pge:error:academic-year-closed` en la API, o `SQLSTATE YC001` (`academic_year_closed:<public_id>`) en el log | Se ha intentado escribir un dato de un curso `cerrado`/`archivado`: lo rechaza el disparador `academic_year_write_guard` (`ADR-057`). Es el comportamiento correcto | Identificar el curso por el `public_id` del mensaje. Si viene de la interfaz, una pantalla no respeta el modo solo lectura; si viene de un trabajo en cola o un comando, el proceso no comprueba antes con `AcademicYearWriteGuard` (y su transacción ha quedado abortada). **No** se resuelve desactivando el disparador ni escribiendo como propietario desde la aplicación (prohibido, `ADR-057 §5.7`) |
| `SQLSTATE YC001` en el log sin `409` en la respuesta (`500`) | La traducción del error de `curso` no se ha aplicado (p. ej. el error escapa en un contexto no HTTP, o falla el mapeo del `ServiceProvider`) | Bug si ocurre en una petición HTTP: `CA-057-05` debe detectarlo |
| Una migración de relleno falla con `YC001` | Se está ejecutando con un rol que **no** es el propietario de la tabla (p. ej. con las credenciales de la aplicación) | Ejecutar las migraciones con la conexión propietaria (`pgsql_owner`), como exige `TenantMigration` |
| La escritura en un curso tarda o espera más de lo normal | Un cierre de ese curso está en curso y retiene `FOR UPDATE` sobre su fila (`ADR-057 §5.4`) | Esperar a que termine; si es habitual, una validación de cierre no está acotada |
| `SQLSTATE 40P01` (`deadlock_detected`) en el log, o un `500` aislado y no reproducible, en una escritura de datos de un curso o en el cierre de un curso | Interbloqueo entre el cierre (`FOR UPDATE` sobre la fila del curso, `ADR-057 §5.4`) y escrituras que toman `FOR SHARE` sobre esa misma fila desde el disparador, agravado si una validación de cierre toma bloqueos de otros módulos **antes** que el del curso (lo prohíbe `ADR-057 §5.4`). **En 1.10 no hay reintento automático**: `AcademicYearTransitions` y el resto de transacciones de `curso` usan `DB::transaction()` sin `attempts`, y no se prevé un cierre con validaciones registradas hasta `1.11`. PostgreSQL aborta una de las dos transacciones y no se confirma nada de ella | Es seguro **repetir la operación** (PostgreSQL la ha revertido entera; si el cierre sí llegó a confirmarse, repetirlo responde `409 invalid_transition`, sin efecto). Si se repite con frecuencia, buscar la validación de cierre que bloquea en orden distinto al del curso. Un módulo consumidor que quiera reintento automático lo pide con `DB::transaction($callback, $attempts)`, que Laravel reintenta precisamente ante `40P01` y `40001` (#385) |

## 8. Impacto en copias de seguridad y restauración

`academic_years` es una tabla de tenant ordinaria: entra en el PITR y en la exportación lógica por tenant (`ADR-022`) como cualquier otra. **Orden de restauración**: `academic_years` antes que cualquier tabla con `academic_year_id` (todas las de `ACAD`, `ALUM`, `CALIF`… desde 1.11), por las FK compuestas. Restaurar granularmente las filas de un tenant sin su `academic_years` falla por FK, que es el comportamiento correcto. El archivado en frío (`REQ-CURSO-005`, fuera de 1.10) cambiará esto y lo documentará su ADR.

## 9. Reversión de la entrega

La reversión es desplegar la imagen anterior (`RUNBOOK.md`). **La migración de la función no hace falta revertirla**: es aditiva, la versión anterior no la invoca y en 1.10 ninguna tabla real tiene el disparador. Si aun así se revierte, su `down()` elimina la función; eso solo es posible mientras ninguna tabla tenga un disparador que la use (cierto en 1.10, falso desde `1.11`). Para desactivar el bloqueo en todas las tablas a la vez sin quitar disparadores, la vía es una migración nueva que reescriba la función (`ADR-057 §6` punto 5), nunca `ALTER TABLE … DISABLE TRIGGER`.

Tres efectos que **no** se revierten solos, y no hace falta:

- La función `app.assert_academic_year_writable()` se queda si no se ejecuta `down()`; sin disparadores que la usen, es inerte.

- Las filas de `permissions` con `module_code = 'curso'` y la de `modules` se quedan; el siguiente `sync-registry` de la versión anterior (que no las declara) las marca `retired_at`, sin borrarlas (`ADR-034 §2`), y sus concesiones quedan inertes.
- Los cursos creados o cambiados de estado con 1.10 siguen ahí; la versión anterior no tiene *endpoints* sobre ellos, pero la tabla y sus restricciones son las mismas de 0.8.

Procedimiento a **probar** antes de mezclar (`CLAUDE.md §9`, `RARQ-DEP-013`).

### 9.1 Reversión de la reapertura (`ADR-059 §4`)

Reversible: desplegar la imagen anterior quita la rama de transición y el *endpoint*; el siguiente `sync-registry` de esa versión marca `reapertura_curso_academico.actualizar` con `retired_at` (sus concesiones quedan inertes). La tabla `academic_year_reopenings` **se queda como histórico** (no se revierte su migración: perdería el registro de reaperturas ya hechas). Un curso reabierto con la versión nueva sigue `activo` tras la reversión; se cierra con la transición ordinaria si procede. Procedimiento a probar antes de mezclar la entrega que lo implemente.

## 10. Documentación a actualizar en el cierre

`docs/manual-usuario/admin.md` (sección «Cursos académicos»), y `direccion.md`/`secretaria.md`, a los que la siembra aprobada da permisos (`CLAUDE.md §6.4`); `SYSADMIN.md` (el módulo nuevo en la lista de `sync-registry`, el comando de siembra para centros existentes, y la función/disparador del bloqueo con el diagnóstico de §7); `ARCHITECTURE.md §3.4` (el contrato transversal del curso como referencia de patrón, la fila de la regla **`AR-13`** con su test `tests/Feature/Architecture/AcademicYearWriteGuardTest.php`, y el disparador de revisión al cerrar `1.11`); `docs/modulos/_PLANTILLA/datos.md` (la casilla de `academic_year_id` dice «lo comprueba `AR-13`»); la *skill* `modulo-nuevo` (las tablas de curso se crean con el ayudante de `TenantMigration`); `CHANGELOG.md` (con la sobrecarga medida en `CA-057-09`); y en el documento de requisitos, la fila de `ADR-057` en la sección 18, su entrada de historial y la errata `RDB-010` → `RDB-012` de `REQ-CURSO-005` (`OPEN-CURSO-22`) (`ADR-057 §9` punto 12).

**Por `ADR-059`** (reapertura): `docs/manual-usuario/admin.md` («Cursos académicos»: reapertura, ventana, motivo y advertencia de no usarla como rectificación) y `SYSADMIN.md` (procedimiento ante un cierre por error), ya actualizados con la especificación; en la entrega que la implemente, además `CHANGELOG.md`, `apps/api/openapi.yaml`, y la nota de despliegue de `SYSADMIN.md` con el paso de concesión que decida `OPEN-CURSO-24`.
