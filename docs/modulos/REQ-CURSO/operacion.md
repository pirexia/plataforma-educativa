# REQ-CURSO · Operación

> **Estado**: **Implementada el 2026-10-17 en `1.11d-a`** (punto de extensión del cambio de fechas, `RN-CURSO-49` a `-60`, `CA-CURSO-108` a `-122`), **y la reapertura de un curso cerrado** (`ADR-059`, `RN-CURSO-40` a `-48`, `CA-CURSO-087` y `-100` a `-107`, y la mitad de reapertura de `CA-CURSO-121`) **implementada el 2026-10-17 en `1.11c`**. **APROBADA** (2026-10-07), paso **1.10**, con los ajustes de `ADR-057` (aceptado). **Ampliada el 2026-10-08 por `ADR-059`** (aceptado, reapertura de un curso cerrado): ver §3.1, §6.1, §7 y §9.1. **Ampliada el 2026-10-12 por `1.11-a`** (`REQ-ACAD-001`, `OPEN-ACAD-11`/`-12`, #409, #387 B-5): `GET /academic-years/selectable`, `AcademicYearDirectory` por identificador interno y comprobación de `retired_at` en `curso:grant-year-permissions`. **Ampliada el 2026-10-16 por `1.11d-a`** (`OPEN-ACAD-24` = B, aprobada por el usuario): validaciones del cambio de fechas (`funcional.md §5.6`); ver §3.2, §4 y §7. **Completada el 2026-10-16** con `OPEN-CURSO-25` a `-27` (decisiones del usuario): lectura del curso con bloqueo compartido (`funcional.md §5.6.1`) y filtro por módulo contratado en los registros de cierre y reapertura (`RN-CURSO-60`); ver §3.2, §4, §7 y §9.2. **Ampliada el 2026-10-20 por `1.12b`** (`REQ-CURSO-002`, apertura del curso siguiente; **PROPUESTA, pendiente de aprobación**): **primeros trabajos en cola del módulo** (copia y reversión), dos migraciones, comando de siembra; ver §1, §2, §3.3, §4.1, §6.2, §7 y §9.3. **Depende de un *worker* de colas que hoy no está desplegado (issue #128, `OPEN-CURSO-28`).**

## 1. Variables de entorno

**Ninguna.** El módulo no tiene configuración por entorno: no hay plazos, ni límites, ni proveedores externos. No toca `infra/quadlet/plataforma.env.example` (issue #89 sigue abierto por otras variables).

**`1.12b`**: tampoco añade variables propias. Usa la conexión de cola por defecto (`QUEUE_CONNECTION`, hoy `database`) y su `retry_after`, que **debe ser mayor** que el tiempo máximo del trabajo (§4.1): si no, la cola reentregaría una copia aún en curso (la reentrega es segura, `RN-CURSO-81`, pero duplicaría la espera). El valor del tiempo máximo y el ajuste de `retry_after` se fijan con la medición de `CA-CURSO-149`.

## 2. Servicios externos de los que depende

**Ninguno.** Solo PostgreSQL, como todo el producto. No usa Redis (`RN-CURSO-24`: el curso activo se memoiza por petición, sin caché compartida), ni almacenamiento de objetos, ni cola, ni el servicio de PDF.

**Desde `1.12b`** usa la **cola** (driver `database` hoy; Redis + Horizon elegido y no instalado, `CLAUDE.md §1`) para la copia y la reversión (`INV-012`). **Necesita un proceso que consuma la cola**, que no existe en ningún entorno desplegado (issue #128, `OPEN-CURSO-28`): sin él, las copias se quedan en `pendiente`.

## 3. Despliegue: registro de módulos y permisos (`ADR-034 §5`)

`php artisan platform:sync-registry` materializa, por primera vez:

- En `modules`: la fila `curso` con `name_key = 'modules.curso'`, `phase = '1'`, `essential = true` y `depends_on = []` (aprobado, `OPEN-CURSO-01`).
- En `permissions`: los cinco códigos de `permisos.md §2`, con `module_code = 'curso'` y `applicable_scopes = ['todos']`.

**Orden obligatorio del despliegue** (mismo que el resto de módulos):

1. Migraciones, ejecutadas por el rol propietario como todas (`TenantMigration`): 1.10 trae **una**, la función del bloqueo de escritura (§6).
2. `platform:sync-registry`, **antes** de abrir tráfico: las FK de `permission_role` apuntan a `permissions`.
3. Siembra de los permisos nuevos en los roles predefinidos de los **centros ya existentes**: `php artisan curso:grant-year-permissions`, que **vive en el módulo Core** (`App\Modules\Core\Infrastructure\Console\GrantAcademicYearPermissionsCommand`) aunque su prefijo sea `curso:` (la escritura en `roles`/`permission_role` es de Core, `INV-007`); comando idempotente de migración de datos del mismo tipo que el de `REQ-PERM/operacion.md §4.3` (añade las concesiones de `permisos.md §4` a los roles predefinidos que no las tengan y **no** toca roles personalizados; como los cinco permisos son nuevos, ningún centro ha podido quitarlos antes, así que no hay decisión del centro que pisar). Los centros nuevos los reciben de `ProvisionTenantDefaults`. **Comprueba primero que `platform:sync-registry` ya se ejecutó** (los cinco permisos están en `permissions`): si no, falla con un mensaje que lo dice y no concede nada. **Solo recorre los centros en estado `Activo`**: un centro suspendido, en baja o aún aprovisionándose no recibe las concesiones y, al reactivarlo, hay que volver a ejecutar el comando (#385). Desde `1.11-a` (issue #387 B-5, `CA-ACAD-041`) comprueba además que ninguno de esos permisos tenga `retired_at` informado: si alguno lo tiene, falla diciendo cuáles y no concede nada; el caso normal no cambia.
4. Abrir tráfico.

### 3.1 Despliegue de la reapertura (`ADR-059`)

Mismo orden que arriba, en la entrega de `1.11c`:

1. Migración: crea `academic_year_reopenings` (§6.1).
2. `platform:sync-registry`: materializa `reapertura_curso_academico.actualizar` (`module_code = 'curso'`, `applicable_scopes = ['todos']`).
3. Concesión a `administrador_centro` en los **centros ya existentes**: **`php artisan curso:grant-reopen-permission`** (`OPEN-CURSO-24` = b, implementado en `1.11c`). Concede **solo** `reapertura_curso_academico.actualizar`: **no** se reejecuta `curso:grant-year-permissions` para esto, porque re-concedería también los cinco permisos de 1.10 a los roles predefinidos de un centro que los hubiera retirado a propósito. Vive en `Core` (`App\Modules\Core\Infrastructure\Console\GrantReopenPermissionCommand`; `AR-08` confina `Role` a `Core`) y tiene el mismo patrón que aquel: idempotente, solo `administrador_centro`, no toca roles personalizados, escribe por el modelo (auditado), **solo recorre centros `Activo`** (al reactivar un centro suspendido hay que volver a ejecutarlo) y **comprueba antes** que `platform:sync-registry` ya se ejecutó y que el permiso no está retirado (`retired_at`): si no, falla con el remedio y no concede nada. Los centros nuevos lo reciben de `ProvisionTenantDefaults::ACADEMIC_YEAR_REOPEN_PERMISSION_GRANTS`. **Sin este paso, el administrador de un centro existente ve un `403` al reabrir.**
4. Abrir tráfico.

Sin variables de entorno, colas ni tareas programadas nuevas: la reapertura es una transición síncrona sobre una fila, como el cierre (`INV-012` no aplica).

### 3.2 Despliegue de las validaciones del cambio de fechas (`1.11d-a`)

Llega en la misma entrega que su primer consumidor (`acad`, `1.11d-a`). **Sin migración en `curso`, sin permisos nuevos, sin `platform:sync-registry` distinto del habitual y sin comando de siembra**: el registro es código, y cada módulo registra su validación al arrancar (`boot()` de su `ServiceProvider`). Sin variables de entorno, colas ni tareas programadas. Compatible con la versión anterior: hasta desplegarse, un `PATCH` de fechas no ejecuta ninguna validación, que es exactamente el comportamiento de antes. En la misma entrega llegan, también sin migración ni configuración: la interfaz `AcademicYearSharedLock` (`funcional.md §5.6.1`), que `acad` usa en el alta y la edición de períodos, y el filtro por módulo contratado en el registro de cierre (`RN-CURSO-60`; el de reapertura nace con él cuando se implemente la reapertura). Con los registros de cierre y reapertura vacíos en producción, el filtro no cambia ningún comportamiento observable.

### 3.3 Despliegue de la apertura del curso siguiente (`1.12b`, PROPUESTA)

Mismo orden que §3:

1. Migraciones del módulo (`apps/api/app/Modules/Curso/Database/migrations/`): crean `year_rollovers` y `year_rollover_items` (§6.2). Aditivas.
2. `platform:sync-registry`: materializa `apertura_curso_academico.leer`, `.crear` y `.eliminar` (`module_code = 'curso'`, `applicable_scopes = ['todos']`).
3. Concesión a `administrador_centro` en los **centros ya existentes**: **`php artisan curso:grant-rollover-permissions`**, en `Core` (`AR-08`), con el patrón de `curso:grant-reopen-permission` (§3.1): concede **solo** esos tres, idempotente, no toca roles personalizados, escribe por el modelo (auditado), solo centros `Activo`, y comprueba antes que `sync-registry` se ejecutó y que ninguno está retirado; si no, falla con el remedio y no concede nada. Centros nuevos: constante aparte de `ProvisionTenantDefaults`.
4. **Proceso de cola en marcha** que consuma la cola por defecto con el código de la versión nueva. **Hoy no existe** (#128): el despliegue de `1.12b` puede hacerse igualmente (la función queda en `pendiente`, sin romper nada más), pero **no** debe anunciarse a un centro hasta que exista.
5. Abrir tráfico.

Compatible con la versión anterior: la versión anterior no conoce las tablas ni las rutas. El participante de `acad` llega en la misma entrega (registro en `AcadServiceProvider::boot()`).

**Si `sync-registry` aborta**: las causas posibles con este módulo son un ciclo en `depends_on` (si se declarara `['acad','alum']`, `OPEN-CURSO-01`), un esencial que dependa de un no esencial, o un ámbito fuera del vocabulario. Ninguna se corrige en el servidor: se corrige el descriptor y se vuelve a desplegar.

## 4. Tareas programadas y jobs

**Ninguno.** Ninguna operación de 1.10 es pesada (`INV-012`): crear, editar y cambiar de estado una fila. El cierre ejecuta en la propia petición las validaciones registradas (vacías en 1.10) mientras retiene `FOR UPDATE` sobre la fila del curso, y **durante ese tiempo toda escritura del centro en ese curso espera** (`ADR-057 §5.4`). **Cuando un módulo registre una validación costosa** (p. ej. recorrer las calificaciones de todo un curso), su especificación debe decidir si el cierre pasa a ser una operación en cola o si valida fuera del bloqueo y revalida dentro.

**Desde `1.11d-a`**, el `PATCH` de fechas de un curso en `planificacion` hace lo mismo a menor escala: toma `FOR UPDATE` sobre la fila del curso y ejecuta en la propia petición las validaciones del cambio de fechas de los módulos contratados (`funcional.md RN-CURSO-51`), sin bloquear filas de otros módulos; mientras tanto, toda escritura del centro en ese curso espera. La de `acad` consulta los períodos vivos de un curso (decenas de filas). La misma obligación que para el cierre: un módulo que registre una validación costosa decide en su especificación cómo mantenerla acotada. **A la inversa** (`funcional.md RN-CURSO-58`): mientras un alta o edición de período lectivo retiene `FOR SHARE` sobre la fila del curso (lectura con bloqueo compartido), un `PATCH` de fechas, un cierre o una reapertura de ese curso esperan a que confirme; las escrituras ordinarias no esperan.

**Trabajos en cola y comandos de otros módulos** que escriban en tablas de curso: el bloqueo les llega como `QueryException` con `SQLSTATE` `YC001`, que deja abortada la transacción en curso. Deben comprobar antes con `AcademicYearWriteGuard` o usar un punto de guardado por unidad de trabajo (`ADR-057 §5.5`). No hay activación automática por fecha (`RN-CURSO-14`), así que no hay tarea programada.

`RMOD-005` (módulos no contratados sin jobs ni *listeners*): no aplica, el módulo no tiene ninguno. **Desde `1.12b`** tiene dos trabajos (§4.1); `curso` es esencial, así que siempre está contratado, y los participantes de módulos no contratados no se ejecutan (`RN-CURSO-62`).

### 4.1 Trabajos de la apertura del curso siguiente (`1.12b`, PROPUESTA)

| Trabajo **[DERIVADA: nombres]** | Lo encola | Hace | Carga útil |
|---------------------------------|-----------|------|------------|
| `RunAcademicYearRollover` (`Curso\Infrastructure`) | `POST /academic-year-rollovers`, tras confirmar | La copia, `RN-CURSO-69` | Identificador interno de la copia; el centro lo pone el mecanismo de colas consciente del centro (`0.7`) |
| `RevertAcademicYearRollover` (`Curso\Infrastructure`) | `POST /academic-year-rollovers/{id}/revert`, tras confirmar | La reversión, `RN-CURSO-75` | Ídem |

- **Cola**: la por defecto. **Intentos**: uno por trabajo (`tries = 1`); el reintento de un `40001` se hace **dentro** del trabajo, repitiendo la transacción completa un número acotado de veces (`RN-CURSO-69`). Al fallar del todo, el manejador de fallo deja la copia en `fallido` (copia) o de vuelta en `completado` con `revert_failure` (reversión), en una transacción propia, y el trabajo queda en `failed_jobs`.
- **Tiempo máximo**: se fija con la medición de `CA-CURSO-149` (volumen de un centro de 40 grupos), con margen amplio sobre la mediana medida; `retry_after` de la conexión, mayor que él (§1). No se inventa una cifra antes de medir.
- **Bloqueos que retiene**: `FOR SHARE` sobre la fila del curso destino durante toda la transacción (`RN-CURSO-70`): mientras dura, la activación, el cierre, la reapertura y el `PATCH` de fechas de **ese** curso esperan; ninguna escritura ordinaria espera. Por eso la transacción debe ser acotada (la medición lo comprueba).
- **Reentrega** (`retry_after`, caída del proceso): segura (`RN-CURSO-81`).
- **Compatibilidad entre versiones** (`CLAUDE.md §9`): la carga útil es solo un identificador; un *worker* de una versión posterior procesa trabajos de la anterior. Un *worker* de una versión **anterior** a `1.12b` no conoce la clase: el trabajo acaba en `failed_jobs` y la copia queda en `pendiente` (§9.3).
- **Sin tareas programadas.** Una copia atascada en `pendiente` o `en_curso` no se repara sola (§7).

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

### 6.2 Migraciones de la apertura del curso siguiente (`1.12b`, PROPUESTA)

Dos migraciones del módulo (`apps/api/app/Modules/Curso/Database/migrations/`), en este orden: `year_rollovers` con `TenantMigration::tenantTable()` y `year_rollover_items` con `TenantMigration::tenantTableAppendOnly()`, ambas con `tenantForeignId(…, 'academic_year_id', 'academic_years')` (disparador incluido, sin excepción en `AR-13`); `source_academic_year_id` y `reverted_by` con `tenantForeignId` (el ayudante solo engancha el disparador por la columna `academic_year_id`); la FK de tres columnas de los *items* declarada a mano con nombre explícito; `CHECK` e índice único parcial por sentencia del propietario (`datos.md §1.6`, `§1.7`). Ninguna acción referencial. **No** alteran `academic_years`, la función del bloqueo, ningún disparador existente ni tablas de otros módulos. Aditivas (*expand*): la versión anterior no las usa. Revisión de `db-reviewer` obligatoria.

## 7. Problemas conocidos y diagnóstico

| Síntoma | Causa probable | Qué hacer |
|---------|----------------|-----------|
| `409 curso.conflict.active_exists` al empezar el curso nuevo | El anterior sigue `activo` (`OPEN-CURSO-06`: hay que cerrarlo antes) | Cerrar el anterior y activar el nuevo. Si el anterior no puede cerrarse aún, es el hueco operativo de `OPEN-CURSO-06` |
| `404 curso.no_active_year` en pantallas que listan datos | No hay curso activo (centro nuevo, o entre cierre y activación) | Activar el curso en planificación |
| Un curso se cerró por error | Cierre prematuro (`ADR-059`) | **Dentro de la ventana T1** (no hay otro curso `activo`, es el cerrado más reciente y ninguna validación de reapertura lo impide): lo reabre desde la aplicación quien tenga `reapertura_curso_academico.actualizar` (por defecto, el Administrador de Centro), con motivo obligatorio, «Reabrir curso» en la ficha o `POST /academic-years/{id}/reopen` (`funcional.md §4.6`). Queda en `audit_logs` y en `academic_year_reopenings`. **Fuera de la ventana** (el curso siguiente ya está `activo`, hay un curso cerrado posterior, o el curso está `archivado`): **sigue sin procedimiento**; solo la rectificación por módulo cuando exista (`OPEN-CURSO-19`). **En ningún caso** se reabre con SQL a mano, con las credenciales del propietario ni desactivando el disparador: saltaría la auditoría (`INV-003`) y está prohibido (`ADR-057 §5.7` punto 6, `RN-CURSO-47`) |
| `409 curso.conflict.reopen_not_latest` al reabrir | Hay un curso cerrado (o archivado) posterior al que se quiere reabrir | Comportamiento correcto: solo se reabre el cerrado más reciente (`RN-CURSO-41`). No hay vía para el anterior |
| `409 curso.conflict.active_exists` al reabrir | El curso siguiente ya está `activo` (ventana T2) | Comportamiento correcto. No se cierra el curso en curso para reabrir el anterior (`ADR-059 §3`) |
| `409 curso.conflict.date_change_checks_failed` al cambiar las fechas de un curso en planificación (`1.11d-a`) | Las fechas nuevas dejan fuera datos de un módulo contratado; hoy, períodos lectivos vivos de alguna etapa (`RN-ACAD-62`). Cada entrada de `errors.date_change[]` dice cuál | Comportamiento correcto. Ajustar o borrar antes esos períodos en la ficha de la etapa y repetir el cambio de fechas. **No** se resuelve descontratando `acad` para que deje de validar (`RN-CURSO-52`): los períodos quedarían fuera y al recontratar incumplirían `RN-ACAD-57` |
| Códigos de error saliendo como `core.curso.*` | Issue #60 | Ver ese issue |
| `500` en vez de `409` al crear o activar a la vez | Una violación de índice único no traducida | Bug: la traducción de `RN-CURSO-04`/`-11` falla; `CA-CURSO-009` debe detectarlo |
| `409 urn:pge:error:academic-year-closed` en la API, o `SQLSTATE YC001` (`academic_year_closed:<public_id>`) en el log | Se ha intentado escribir un dato de un curso `cerrado`/`archivado`: lo rechaza el disparador `academic_year_write_guard` (`ADR-057`). Es el comportamiento correcto | Identificar el curso por el `public_id` del mensaje. Si viene de la interfaz, una pantalla no respeta el modo solo lectura; si viene de un trabajo en cola o un comando, el proceso no comprueba antes con `AcademicYearWriteGuard` (y su transacción ha quedado abortada). **No** se resuelve desactivando el disparador ni escribiendo como propietario desde la aplicación (prohibido, `ADR-057 §5.7`) |
| `SQLSTATE YC001` en el log sin `409` en la respuesta (`500`) | La traducción del error de `curso` no se ha aplicado (p. ej. el error escapa en un contexto no HTTP, o falla el mapeo del `ServiceProvider`) | Bug si ocurre en una petición HTTP: `CA-057-05` debe detectarlo |
| Una migración de relleno falla con `YC001` | Se está ejecutando con un rol que **no** es el propietario de la tabla (p. ej. con las credenciales de la aplicación) | Ejecutar las migraciones con la conexión propietaria (`pgsql_owner`), como exige `TenantMigration` |
| La escritura en un curso tarda o espera más de lo normal | Un cierre, una reapertura o un `PATCH` de fechas de ese curso está en curso y retiene `FOR UPDATE` sobre su fila (`ADR-057 §5.4`, `RN-CURSO-51`) | Esperar a que termine; si es habitual, una validación de cierre o de cambio de fechas no está acotada |
| Un `PATCH` de fechas, un cierre o una reapertura tarda más de lo normal (`1.11d-a`) | Un alta o edición de período lectivo del mismo curso retiene `FOR SHARE` sobre su fila (`RN-CURSO-58`) | Esperar a que termine (transacciones cortas). Si es habitual, una transacción que usa `AcademicYearSharedLock` hace trabajo largo después de bloquear: bug del consumidor |
| `LogicException` (`500`) en una escritura de período lectivo: lectura con bloqueo compartido sin transacción | Un consumidor llama a `AcademicYearSharedLock::lockForShareById()` fuera de `DB::transaction()` (`RN-CURSO-56`) | Bug del módulo consumidor: la llamada debe ir dentro de la transacción de la escritura. No se «arregla» quitando la comprobación |
| `SQLSTATE 40P01` (`deadlock_detected`) en el log, o un `500` aislado y no reproducible, en una escritura de datos de un curso o en el cierre de un curso | Interbloqueo entre el cierre (`FOR UPDATE` sobre la fila del curso, `ADR-057 §5.4`) y escrituras que toman `FOR SHARE` sobre esa misma fila desde el disparador, agravado si una validación de cierre toma bloqueos de otros módulos **antes** que el del curso (lo prohíbe `ADR-057 §5.4`). **En 1.10 no hay reintento automático**: `AcademicYearTransitions` y el resto de transacciones de `curso` usan `DB::transaction()` sin `attempts`, y no se prevé un cierre con validaciones registradas hasta `1.11`. PostgreSQL aborta una de las dos transacciones y no se confirma nada de ella | Es seguro **repetir la operación** (PostgreSQL la ha revertido entera; si el cierre sí llegó a confirmarse, repetirlo responde `409 invalid_transition`, sin efecto). Si se repite con frecuencia, buscar la validación de cierre que bloquea en orden distinto al del curso. Un módulo consumidor que quiera reintento automático lo pide con `DB::transaction($callback, $attempts)`, que Laravel reintenta precisamente ante `40P01` y `40001` (#385) |
| Una copia (`1.12b`) se queda en `pendiente` («En cola») indefinidamente | No hay proceso consumiendo la cola (issue #128), o el *worker* es de una versión anterior a `1.12b` (el trabajo acaba en `failed_jobs`) | Comprobar que hay un *worker* en marcha con la versión desplegada y si el trabajo está en `jobs` o en `failed_jobs`. Con un *worker* correcto, el trabajo pendiente se procesa solo. Si está en `failed_jobs` por versión antigua, reencolarlo desde allí tras desplegar el *worker* nuevo (la reentrega es segura, `RN-CURSO-81`). **No** se cambia el estado de la copia a mano por SQL |
| Una copia se queda en `en_curso` o `revirtiendo` mucho más que el tiempo medido | El proceso murió a mitad: su transacción se revirtió (nada copiado o nada revertido), pero la marca de estado previa se confirmó; la cola la reentregará al vencer `retry_after` | Esperar a `retry_after`; la reentrega retoma desde el principio (`RN-CURSO-69`, `-81`). Si el trabajo agotó intentos y está en `failed_jobs`, reencolarlo. Si `retry_after` es menor que la duración real, ajustar (§1) |
| Copia en `fallido` con `target_changed` | Escrituras concurrentes en el destino o en el origen durante la copia: error de serialización (`40001`) tras agotar reintentos, o choque de unicidad (`23505`) con algo creado a mano en el destino | Comportamiento correcto: no se copió nada. Repetir la copia cuando no se esté editando el destino; si choca con datos creados a mano, la simulación lo dirá |
| Copia en `fallido` con `internal_error` | Excepción no prevista en un participante | Bug: ver el log por `request_id`/identificador de la copia y `failed_jobs`. No se copió nada; el destino está como antes |
| `409 curso.conflict.rollover_in_progress` al activar el curso siguiente | Hay una copia o una reversión en vuelo hacia él (`RN-CURSO-71`) | Esperar a que termine (y, si está atascada, ver las filas anteriores) |
| `409 curso.conflict.rollover_revert_blocked` al revertir | Se añadieron datos a mano que dependen de lo copiado (`OPEN-CURSO-33` = A) | Borrar antes esos datos (cada entrada dice de qué tipo son) o no revertir |
| Activación, cierre o `PATCH` de fechas del curso siguiente que tardan | Una copia o reversión retiene `FOR SHARE` sobre su fila (`RN-CURSO-70`) | Esperar a que termine; si es habitual, la copia no está acotada (revisar la medición de `CA-CURSO-149`) |

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

Reversible: desplegar la imagen anterior quita la rama de transición y el *endpoint*; el siguiente `sync-registry` de esa versión marca `reapertura_curso_academico.actualizar` con `retired_at` (sus concesiones quedan inertes). La tabla `academic_year_reopenings` **se queda como histórico** (no se revierte su migración: perdería el registro de reaperturas ya hechas). Un curso reabierto con la versión nueva sigue `activo` tras la reversión; se cierra con la transición ordinaria si procede. Comprobado en `1.11c`: revisión del `down()` (solo elimina la tabla) y ejecución del *rollback* en desarrollo; la migración es aditiva y la versión anterior no usa la tabla. **Pendiente** de probar la reversión sobre una instalación real antes de producción. Advertencia: ejecutar el `down()` destruye el histórico *append-only* de reaperturas, sin recuperación salvo copia de seguridad.

### 9.2 Reversión de las validaciones del cambio de fechas (`1.11d-a`)

Desplegar la imagen anterior quita el registro y la validación de `acad`, la lectura con bloqueo compartido y el filtro por módulo contratado del cierre: el `PATCH` de fechas vuelve a no validar nada, como antes de `1.11d-a`, y el cierre vuelve a ejecutar todas las validaciones registradas (ninguna en producción). No hay migración ni dato que revertir. Los cambios de fechas hechos después con la versión anterior pueden dejar períodos fuera (`REQ-ACAD/funcional.md §14`); la versión anterior no tiene períodos lectivos (llegan en la misma entrega), así que la reversión de `1.11d-a` es conjunta. Procedimiento a probar con el resto de la reversión de `1.11d-a`.

### 9.3 Reversión de la apertura del curso siguiente (`1.12b`, PROPUESTA)

**Antes de revertir la entrega, esperar a que no haya copias ni reversiones en vuelo** (`pendiente`, `en_curso`, `revirtiendo`): un *worker* de la versión anterior no conoce sus trabajos y los mandaría a `failed_jobs`, dejando la copia atascada. Desplegar la imagen anterior quita rutas, trabajos y el participante de `acad`; el siguiente `sync-registry` de esa versión marca los tres permisos de `apertura_curso_academico` con `retired_at` (concesiones inertes). Las tablas `year_rollovers` y `year_rollover_items` **se quedan** (no se revierten sus migraciones: perderían el registro de qué se copió); lo ya copiado son datos ordinarios de `acad` y siguen ahí. Sin la versión nueva, una copia `completado` ya no se puede revertir por la aplicación: si hiciera falta, se vuelve a desplegar la versión nueva. Procedimiento a probar antes de mezclar (`CLAUDE.md §9`).

## 10. Documentación a actualizar en el cierre

`docs/manual-usuario/admin.md` (sección «Cursos académicos»), y `direccion.md`/`secretaria.md`, a los que la siembra aprobada da permisos (`CLAUDE.md §6.4`); `SYSADMIN.md` (el módulo nuevo en la lista de `sync-registry`, el comando de siembra para centros existentes, y la función/disparador del bloqueo con el diagnóstico de §7); `ARCHITECTURE.md §3.4` (el contrato transversal del curso como referencia de patrón, la fila de la regla **`AR-13`** con su test `tests/Feature/Architecture/AcademicYearWriteGuardTest.php`, y el disparador de revisión al cerrar `1.11`); `docs/modulos/_PLANTILLA/datos.md` (la casilla de `academic_year_id` dice «lo comprueba `AR-13`»); la *skill* `modulo-nuevo` (las tablas de curso se crean con el ayudante de `TenantMigration`); `CHANGELOG.md` (con la sobrecarga medida en `CA-057-09`); y en el documento de requisitos, la fila de `ADR-057` en la sección 18, su entrada de historial y la errata `RDB-010` → `RDB-012` de `REQ-CURSO-005` (`OPEN-CURSO-22`) (`ADR-057 §9` punto 12).

**Por `ADR-059`** (reapertura): `docs/manual-usuario/admin.md` («Cursos académicos»: reapertura, ventana, motivo y advertencia de no usarla como rectificación) y `SYSADMIN.md` (procedimiento ante un cierre por error), ya actualizados con la especificación; en la entrega que la implemente, además `CHANGELOG.md`, `apps/api/openapi.yaml`, y la nota de despliegue de `SYSADMIN.md` con el paso de concesión (`curso:grant-reopen-permission`, `OPEN-CURSO-24` = b); los cuatro ya actualizados en `1.11c`.

**Por `1.12b`** (en la entrega que lo implemente): `docs/manual-usuario/admin.md` («Abrir el curso siguiente», `funcional.md §11`); `SYSADMIN.md` (comando `curso:grant-rollover-permissions`, dependencia del *worker* de colas y diagnóstico de copias atascadas de §7); `RUNBOOK.md` (orden de despliegue de §3.3 y reversión de §9.3); `ARCHITECTURE.md §3.4` (el registro de participantes del *rollover* como referencia de patrón de «contrato síncrono por módulo + trabajo en cola del llamador», `ADR-048 §4.9`); `apps/api/openapi/paths/curso.yaml` (`api.md §8`); `CHANGELOG.md` (con la medición de `CA-CURSO-149`); y **`docs/modulos/REQ-ACAD/`** (participante de `acad`, `funcional.md §7.4`), que esta especificación no escribe.
