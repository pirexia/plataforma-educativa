# REQ-ALUM · Operación

> **Estado**: **APROBADA** (2026-11-06) junto con `funcional.md` (ver su cabecera); lo marcado [DERIVADA] o dependiente de un `OPEN-ALUM` no bloqueante sigue como propuesta.

## 1. Variables de entorno

Ninguna nueva en `.env`. Configuración del módulo en `config/alum.php`, que contiene `export.rate_limit` (§6) y `photo` (`1.15-c`): `max_bytes` 5 MB, `max_dimension` 1024 px de lado mayor tras reprocesar, `max_input_pixels` 12 MP de entrada, `signed_url_ttl_minutes` 5 y `read_rate_limit` 300 lecturas por hora por centro y usuario (mismos valores que `fam_unit.pickup.photo`, sin leer esa clave: `INV-007`; [DERIVADA], revisables). Previsto, sin implementar [DERIVADA]: longitud mínima de `q` (2) y tope de filas de la lista de clase servidas sin paginar (si la SPA lo pide). La subida va por `App\Support\Files` (`ADR-068`) con la política de `alum.photo`. **Pendiente, sin implementar (no está en la especificación)**: job de barrido de objetos huérfanos de `alum/student-photos` (solo los residuales: sustituir, `DELETE` y la baja del alumno ya borran su objeto al confirmar la transacción, y el borrado fallido se registra como `alum.photo.object_delete_failed` sin la clave; la fila histórica soft-deleted no tiene objeto); hasta entonces un objeto cuyo borrado falló queda en el *bucket* sin referencia.

## 2. Servicios externos de los que depende

| Servicio | Para qué | Si no responde |
|----------|----------|----------------|
| PostgreSQL | Todo | Caída del módulo, como el resto |
| Almacenamiento S3 (MinIO en desarrollo) | Fotografía (`OPEN-ALUM-08` = A) | La ficha se sirve igual (`has_photo` sale de la base de datos, no de S3); subir responde `503` sin escribir nada. Nada más depende de S3 [DERIVADA, precedente `OPEN-FAM-UNIT-47`] |
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
| `409 has_dependents` con `group_change_requests` al borrar un grupo en `acad` | Solicitudes de cambio de grupo `pendiente` con ese grupo de origen o destino (`RN-ALUM-47`, desde `1.15-c`) | Aprobar, rechazar o cancelar las solicitudes antes |
| `409 has_dependents` al borrar un curso de etapa en `acad` | Matrículas vivas del curso de etapa, **también las que no tienen grupo** (`RN-ALUM-26`, `ADR-071 §5.5`: segundo veto, independiente del de grupo) | Dar de baja o anular esas matrículas antes |
| `platform:sync-registry` aborta | `acad` o `fam_unit` no registrados | Desplegar en orden |
| Exportación `pendiente` indefinida | *Worker* parado | `RUNBOOK.md`, arranque del perfil `worker` |
| `429` al pedir la exportación de matrículas | Límite de tasa por centro y usuario (`config/alum.php`, `export.rate_limit`: 10 por hora) | Esperar el `Retry-After`; ajustar la configuración solo si el uso legítimo lo exige |
| Exportación `fallida` con `core.export.generation_failed` | Al ejecutarse, el solicitante ya no tiene `matricula.exportar`, el módulo está descontratado o el curso académico dejó de ser legible para él | Repetir la petición con un usuario con permiso |
| `429` al pedir la foto de alumnos | Límite de lecturas por centro y usuario (`config/alum.php`, `photo.read_rate_limit`: 300 por hora) | Esperar el `Retry-After`; ajustar solo si el uso legítimo lo exige |
| Foto no visible | S3 caído o credenciales | §2; la ficha sigue funcionando |
| Borrado de un grupo o de un curso de etapa sin `409` con `alum` deshabilitado en el centro | El veto de `alum` no se ejecuta con el módulo deshabilitado (`Acad/Application/StructureVetoes.php`, coherente con `RN-CURSO-60`); al reactivarlo pueden quedar matrículas huérfanas (#597) | Sin acción operativa hasta la decisión (propuesta pendiente, ADR); seguimiento en #597 |
| Datos de un menor en `idempotency_keys.response_body` durante 24 h tras el alta | Respuesta `201` de `POST /students` guardada por la idempotencia de `Core` | Conocido, sin acción operativa; seguimiento en #558 |

## 7. Impacto en copias de seguridad y restauración

Tablas de tenant ordinarias: entran en PITR y en la exportación lógica por tenant (`ADR-022`). Las fotos viven en el *bucket* del tenant: la restauración de un centro incluye sus objetos de `alum`. Ningún secreto propio.

## 8. Documentación que el paso actualiza al cerrar (no la escribe esta especificación)

`SYSADMIN.md` (comando `alum:grant-permissions`, orden de despliegue), `docs/manual-usuario/secretaria.md`, `direccion.md`, `docente.md` (pantallas de §10 de `funcional.md`), `CHANGELOG.md` (entrada por entrega y la cifra de `CA-ALUM-060`), `ARCHITECTURE.md §3.4` si `alum` pasa a ser referencia de algún patrón, `PRIVACY.md` (tratamiento de datos de alumnado y fotografía), y las notas de `REQ-ACAD` (`TeachingMembership`, `GroupDirectory` y registro de vetos construidos). `REQ-FAM-UNIT` no cambia por esto: `FamilyUnitStudents` ya existía y `alum` solo la consume (como mucho, una nota en su §7.4 de que el contrato tiene ya consumidor).

**Documento de requisitos** (`docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`), con subida de versión de la cabecera y entrada en su historial: (1) `§5.6` y la fila de `REQ-ALUM` del índice de módulos: «Depende de» pasa a `CORE, CURSO, ACAD, FAM-UNIT` (`OPEN-ALUM-28` = A); (2) `§16.1` (`Enrollment`: «curso académico, curso de etapa y, cuando se asigna, grupo») y `§16.2` (`ENROLLMENT }o--o| GROUP`), por `OPEN-ALUM-11` = B, **con el ADR** que exige el propio `§16` («no deben modificarse sin ADR»), `ADR-071` (`ACEPTADA`, 2026-11-09), referenciado en `§18`. **Aplicado en la versión 3.2.25 del documento de requisitos** por la sesión (no está en el ámbito de escritura de `spec-writer`).

## 9. E2E real de alumnado (`1.15-d` T3)

**Cómo se ejecuta.** Con la pila de desarrollo levantada (`plataforma-api` en :8000 y `plataforma-postgres`), migraciones y `platform:sync-registry` aplicados: `npm run test:e2e:real:alum` (`apps/web/scripts/e2e-real-alum.sh`; admite argumentos de Playwright). El guion prepara dos centros sintéticos (`demo` y `demo2`) con `alum` y `fam_unit` contratados, lanza Playwright y los retira al terminar, también si falla. Solo toca contenedores de desarrollo y datos sintéticos (`REQ-SEED-005`). Los specs de navegador necesitan `demo.plataforma.test` en `/etc/hosts` y que no haya otra SPA en :5173 con otra `VITE_API_URL` (el guion se niega).

**Dos invocaciones de Playwright**, en este orden: (1) los specs HTTP `alum-students-real` y `alum-enrollments-real` (3 y 8 tests, cookie de sesión y CSRF reales, sin navegador); (2) los de navegador `alum-screens-real` (8) y `alum-group-change-real` (6), con `--workers=1`. Van separados por el límite de accesos de la API (`429`, 10 inicios de sesión por minuto y centro desde una IP): los de navegador esperan lo que pida el servidor, los HTTP no, y por eso van antes. Un actor se autentica **una vez** por spec: la SPA recibe el estado de sesión obtenido por **inicio de sesión por API** (no por la pantalla de acceso).

**Usuarios de prueba** (todos `@example.com`, creados por `apps/api/tests/Support/e2e-real-tenant.php setup <slug> alum`): `e2e-secretaria` (rol `secretaria`), `e2e-direccion` (rol `direccion`), los docentes `e2e-docente` y `e2e-docente2` (ambos con el rol `docente`, con ámbito `grupo`; el spec asigna solo al primero al grupo de origen), el administrador del centro y un tutor con el rol `tutor_legal`. Las contraseñas las devuelve el guion de preparación y se pasan por variables de entorno `E2E_*`; no están en el repositorio.

**Estado que deja y que exige.** El **último test** de `alum-group-change-real` **cierra el curso académico activo** del centro de pruebas (`CA-ALUM-070`: curso de solo lectura); por eso ese spec va el último de la suite. Los specs obtienen el curso con `ensureActiveYear` (`apps/web/e2e/fixtures/alumReal.ts`), que **recrea** un curso activo si el activo se cerró o no existe, sin solaparse con los existentes. Un spec lanzado solo funciona igual.

**Qué NO prueba** (`CA-ALUM-072`, aplazado a `S-1b` de `ADR-076`, cuando exista un S3 real con firma): (1) **la descarga del CSV desde S3**: la exportación se acepta con `202` y se comprueba que no falla, pero el fichero no se descarga ni se lee; (2) **la fotografía**: ni subir, ni ver (URL firmada), ni sustituir, ni quitar en navegador; la foto está cubierta solo por los tests de API y de Vitest. El guion no usa S3. Un resultado en verde de esta suite **no** garantiza que S3 funcione.
