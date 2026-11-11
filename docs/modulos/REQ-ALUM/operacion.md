# REQ-ALUM · Operación

> **Estado**: **APROBADA** (2026-11-06) junto con `funcional.md` (ver su cabecera); lo marcado [DERIVADA] o dependiente de un `OPEN-ALUM` no bloqueante sigue como propuesta.

## 1. Variables de entorno

Ninguna nueva en `.env`. Configuración del módulo en `config/alum.php`, que hoy solo contiene `export.rate_limit` (§6). Previsto, sin implementar [DERIVADA]: longitud mínima de `q` (2) y tope de filas de la lista de clase servidas sin paginar (si la SPA lo pide). La caducidad de la URL firmada de la foto y el tamaño máximo son los de `App\Support\Files` (`ADR-068`), no propios.

## 2. Servicios externos de los que depende

| Servicio | Para qué | Si no responde |
|----------|----------|----------------|
| PostgreSQL | Todo | Caída del módulo, como el resto |
| Almacenamiento S3 (MinIO en desarrollo) | Fotografía (`OPEN-ALUM-08` = A) | La ficha se sirve sin foto (`photo.url = null` con indicador de no disponible); subir responde `503`. Nada más depende de S3 [DERIVADA, precedente `OPEN-FAM-UNIT-47`] |
| *Worker* de colas (`worker`, conexión `database`) | Exportación de listas de clase | La solicitud queda `pendiente` en `data_exports` hasta que el *worker* vuelva |

## 3. Despliegue: registro de módulos y permisos (`ADR-034 §5`)

1. Migraciones (`php artisan migrate --database=pgsql_owner`), en este orden: (a) `students` (`1.15-a`); (b) `enrollments` (`1.15-b`); (c) `group_change_requests` y `student_photos` (`1.15-c`, `OPEN-ALUM-08` = A); antes de ellas, `enrollments_tenant_year_id_unique` (`CONCURRENTLY` + `USING INDEX`, issue #602). Los únicos de destino de las FK compuestas en `acad` ya existen (`datos.md §1.2`): no hay migración de `acad`. De `Core` solo la ampliación del `CHECK` de `data_exports.kind` con `alum_enrollments`, migración `2026_11_10_100200_widen_data_exports_kind_for_enrollments`. Todas aditivas (*expand*); compatibles con la versión anterior, que no las conoce.
2. `php artisan platform:sync-registry`: añade el módulo `alum` (`depends_on ['curso','acad','fam_unit']`) y sus permisos (diez en total; cuatro en `1.15-a`: `alumno.leer`, `.crear`, `.actualizar`, `.eliminar`). **Aborta** si algún código de `depends_on` no existe o hay ciclo: comprobar que `acad` y `fam_unit` están desplegados. Recordatorio de `RMOD-006`: el comando informa y no contrata; un centro con `alum` contratado y sin `acad` o `fam_unit` queda señalado.
3. `php artisan alum:grant-permissions` para los centros existentes (siembra de `permisos.md §4`), idempotente; se ejecuta tras `1.15-a` (solo `alumno.*`) y de nuevo tras `1.15-b` (`matricula.*` y concesiones `grupo`).
4. Contratar `alum` por centro desde el *backoffice* (`REQ-BO-002`); exige `acad` y `fam_unit` contratados.

**Reversión**: código anterior sin rutas de `alum`; las tablas nuevas pueden quedar (nadie las lee) y se retiran con `down()` en orden inverso solo si no hay datos que conservar. Descontratar `alum` no borra nada (`RMOD-003`). El procedimiento se prueba antes del PR (`CLAUDE.md §9`).

**Workers**: no hay *jobs* nuevos salvo la exportación, que usa el *job* genérico de `data_exports` con un `kind` nuevo; un *worker* de la versión anterior que reciba ese `kind` debe fallar en cerrado (marcar la exportación fallida), no perder la solicitud. Desplegar API y *workers* juntos.

## 4. Tareas programadas y jobs

| Elemento | Cola | Notas |
|----------|------|-------|
| Exportación `alum_enrollments` (`Alum\Infrastructure\Jobs\GenerateEnrollmentsExport`, `1.15-b` T2c) | `default` (conexión `database`) | `INV-012`; con contexto de tenant (`ADR-033`); CSV por `App\Support\Csv`; `tries = 3`; permiso y módulo se recomprueban al ejecutar. `default` y no `long`: conjuntos acotados por centro (≤ ~3.000 personas, `ADR-005`), segundos de trabajo |

Sin tareas programadas (el planificador #453 sigue sin desplegar; nada en `1.15` lo necesita).

## 5. Métricas y alertas

Sin métricas propias. Cifra de `CA-ALUM-060` (`#382`, `1.15-b` T1b; sobrecoste del disparador `academic_year_write_guard` en `enrollments`, medida con `AcademicYearWriteGuardOverheadTest`: 3 corridas, mediana de 5 repeticiones, 20.000 filas por sentencia y 1.000 sentencias, frente a una tabla de control idéntica sin disparador): inserción masiva **+40,5 %, +58,6 % y +54,2 %** (con disparador 1,56 s, 1,32 s y 1,29 s; sin él 1,11 s, 0,83 s y 0,83 s); fila a fila **-9,0 %, +5,0 % y -1,5 %** (1,05-1,07 s frente a 1,02-1,17 s: ruido). Por debajo de `1.10` (+182 % a +195 %) y de `CA-ACAD-023` (+85 % a +104 %); sin hallazgo. No es umbral de aprobado. Se repite en `CHANGELOG.md`.

## 6. Problemas conocidos y diagnóstico

| Síntoma | Causa probable | Qué hacer |
|---------|----------------|-----------|
| `409 academic-year-closed` al matricular | Curso cerrado o archivado | Esperado; reapertura solo por `ADR-059` |
| Docente que «no ve a sus alumnos» | Sin asignación docente o tutoría en ese curso, o curso de la petición distinto (`OPEN-ALUM-17`) | Revisar `acad` (asignación y tutoría del grupo) y el parámetro `academic_year` |
| `409 has_dependents` al borrar un grupo en `acad` | Matrículas vivas del grupo (`RN-ALUM-26`) | Mover o dar de baja las matrículas antes |
| `409 has_dependents` al borrar un curso de etapa en `acad` | Matrículas vivas del curso de etapa, **también las que no tienen grupo** (`RN-ALUM-26`, `ADR-071 §5.5`: segundo veto, independiente del de grupo) | Dar de baja o anular esas matrículas antes |
| `platform:sync-registry` aborta | `acad` o `fam_unit` no registrados | Desplegar en orden |
| Exportación `pendiente` indefinida | *Worker* parado | `RUNBOOK.md`, arranque del perfil `worker` |
| `429` al pedir la exportación de matrículas | Límite de tasa por centro y usuario (`config/alum.php`, `export.rate_limit`: 10 por hora) | Esperar el `Retry-After`; ajustar la configuración solo si el uso legítimo lo exige |
| Exportación `fallida` con `core.export.generation_failed` | Al ejecutarse, el solicitante ya no tiene `matricula.exportar`, el módulo está descontratado o el curso académico dejó de ser legible para él | Repetir la petición con un usuario con permiso |
| Foto no visible | S3 caído o credenciales | §2; la ficha sigue funcionando |
| Borrado de un grupo o de un curso de etapa sin `409` con `alum` deshabilitado en el centro | El veto de `alum` no se ejecuta con el módulo deshabilitado (`Acad/Application/StructureVetoes.php`, coherente con `RN-CURSO-60`); al reactivarlo pueden quedar matrículas huérfanas (#597) | Sin acción operativa hasta la decisión (propuesta pendiente, ADR); seguimiento en #597 |
| Datos de un menor en `idempotency_keys.response_body` durante 24 h tras el alta | Respuesta `201` de `POST /students` guardada por la idempotencia de `Core` | Conocido, sin acción operativa; seguimiento en #558 |

## 7. Impacto en copias de seguridad y restauración

Tablas de tenant ordinarias: entran en PITR y en la exportación lógica por tenant (`ADR-022`). Las fotos viven en el *bucket* del tenant: la restauración de un centro incluye sus objetos de `alum`. Ningún secreto propio.

## 8. Documentación que el paso actualiza al cerrar (no la escribe esta especificación)

`SYSADMIN.md` (comando `alum:grant-permissions`, orden de despliegue), `docs/manual-usuario/secretaria.md`, `direccion.md`, `docente.md` (pantallas de §10 de `funcional.md`), `CHANGELOG.md` (entrada por entrega y la cifra de `CA-ALUM-060`), `ARCHITECTURE.md §3.4` si `alum` pasa a ser referencia de algún patrón, `PRIVACY.md` (tratamiento de datos de alumnado y fotografía), y las notas de `REQ-ACAD` (`TeachingMembership`, `GroupDirectory` y registro de vetos construidos). `REQ-FAM-UNIT` no cambia por esto: `FamilyUnitStudents` ya existía y `alum` solo la consume (como mucho, una nota en su §7.4 de que el contrato tiene ya consumidor).

**Documento de requisitos** (`docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`), con subida de versión de la cabecera y entrada en su historial: (1) `§5.6` y la fila de `REQ-ALUM` del índice de módulos: «Depende de» pasa a `CORE, CURSO, ACAD, FAM-UNIT` (`OPEN-ALUM-28` = A); (2) `§16.1` (`Enrollment`: «curso académico, curso de etapa y, cuando se asigna, grupo») y `§16.2` (`ENROLLMENT }o--o| GROUP`), por `OPEN-ALUM-11` = B, **con el ADR** que exige el propio `§16` («no deben modificarse sin ADR»), `ADR-071` (`ACEPTADA`, 2026-11-09), referenciado en `§18`. **Aplicado en la versión 3.2.25 del documento de requisitos** por la sesión (no está en el ámbito de escritura de `spec-writer`).
