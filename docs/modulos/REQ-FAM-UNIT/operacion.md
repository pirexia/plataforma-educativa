# REQ-FAM-UNIT · Operación

> **Estado**: **APROBADA por el usuario** (2026-10-26, todas las recomendaciones de `OPEN-FAM-UNIT-01..21` aceptadas tal cual; ver `funcional.md §13`). **`1.14-a` implementada (2026-10-26)**: cinco migraciones aditivas, once permisos y el comando `fam_unit:grant-permissions`; ninguna variable de entorno, servicio, cola ni tarea programada nuevos (`§8`). **Enmendada por `ADR-066` (ACEPTADA, 2026-10-27; `funcional.md §16`)**: `1.14-b` crea `terms_versions` (con `kind`) y `image_consent_decisions`; ya no se crea `image_consents`. **`1.14-b` IMPLEMENTADA (2026-10-27; `funcional.md §18`)**, aprobada por el usuario (`funcional.md §17`); sin variables, colas ni tareas programadas nuevas por la aprobación. **Condiciones previas a cargar datos reales del consentimiento de imagen** (no bloquean el despliegue con datos sintéticos): validación jurídica de `OPEN-FAM-UNIT-08`, criterio sobre 14 y 18 años (`OPEN-FAM-UNIT-18`) y respuesta a `OPEN-066-02` (`funcional.md §13`).

## 1. Variables de entorno

**Ninguna nueva** en `1.14-a`/`-b`/`-c`/`-d`.

El detalle de custodia (condicionado a `OPEN-FAM-UNIT-05`) necesitará, según lo que decida su ADR, una o más claves de cifrado a nivel de campo. Irán en el gestor de secretos por `EnvironmentFile=` (`ADR-037`, `CLAUDE.md §8`), nunca en el repositorio, y su procedimiento de rotación en `SYSADMIN.md`. No se nombran aquí para no adelantar el diseño.

## 2. Servicios externos de los que depende

- **PostgreSQL** (RLS). Sin bloqueos de fila en el consentimiento de imagen: `RN-FAM-UNIT-44` queda sustituida por el cálculo en origen (`ADR-066 §5.2`).
- **Almacenamiento compatible S3** solo para el informe de consentimientos (vía `data_exports` de `Core`) y, condicionado, para el documento de custodia. Si no responde, la exportación queda `fallida` con su motivo y se puede repetir; ninguna operación de vínculo, revocación o consentimiento depende del almacenamiento.
- **Ninguno externo** (correo, SMS, firma): este módulo no envía nada (`funcional.md §1.3`).

## 3. Despliegue: registro de módulos y permisos (`ADR-034 §5`)

`php artisan platform:sync-registry` materializa:

- el módulo `fam_unit` en `modules` (`phase 1`, `essential false`, `depends_on []`, `OPEN-FAM-UNIT-14`);
- los permisos de `permisos.md §2` en `permissions` (sin `restriccion_custodia.*` hasta que se apruebe su ADR).

Aborta el despliegue si un ámbito declarado no pertenece al vocabulario cerrado: `unidad_familiar` sí pertenece desde `1.5`. Si aborta, no hay que tocar datos: corregir el descriptor y volver a desplegar.

**Siembra para centros existentes**: un comando en `Core` (como `acad:grant-structure-permissions`, `INV-007`), `fam_unit:grant-permissions` (implementado en `Core`: `GrantFamUnitPermissionsCommand`), idempotente, solo roles predefinidos y centros `Activo`, que **no concede nada** si alguno de los permisos falta en `permissions` o tiene `retired_at` (issue #387). Se ejecuta una vez tras el primer despliegue de cada entrega que añada permisos. Centros nuevos: `ProvisionTenantDefaults`.

**Contratación**: el módulo es no esencial; queda inactivo en cada centro hasta que el Super Administrador lo contrate (`ADR-045`). Para el centro `demo` de desarrollo hay que contratarlo a mano antes de probar.

**Orden de migraciones** (enmendado por `ADR-066 §9.27`): `family_units` → `guardians` → FK de `principal_guardian_id` → `family_unit_students` → `guardian_student_links` → (`1.14-b`) **`terms_versions` (con `kind`) antes que `image_consent_decisions`** (la FK `RESTRICT` de `consent_text_version_id` apunta a la primera) → (`1.14-c`, implementada) `terms_acceptances`. **Ya no se crea `image_consents`.** Todas aditivas: *expand* puro, compatibles con la versión anterior del código (`RARQ-DEP-005`). Se aplican con `php artisan migrate --database=pgsql_owner` (lección de `1.11d-a`). `db-reviewer` obligatorio.

**Reversión** (`RARQ-DEP-013`): las migraciones son reversibles mientras las tablas estén vacías en producción; el `down()` de `image_consent_decisions` y de `terms_versions` **lanza una excepción si hay filas** (evidencia legal, `db-reviewer` B-3). Con datos reales, revertir el código es seguro (las tablas nuevas quedan sin uso) y **no** se ejecuta `migrate:rollback` sobre `image_consent_decisions`, `terms_versions` (los textos a los que apuntan las decisiones) ni `terms_acceptances`: son evidencia de consentimiento. Con `1.14-b` desplegada, `sync-registry` registra los permisos de `consentimiento_imagen` y `texto_legal`, y `fam_unit:grant-permissions` siembra los de `permisos.md §4` en los centros existentes (incluido `texto_legal.leer` en `secretaria`, `OPEN-FAM-UNIT-22`). El procedimiento probado se escribe en `RUNBOOK.md` al cerrar cada entrega.

## 4. Tareas programadas y trabajos en cola

| Trabajo | Cola | Cuándo | Notas |
|---------|------|--------|-------|
| Generación del informe de consentimientos | `default` (conexión `database`, *worker* `worker`) | Bajo demanda (`POST /image-consents/exports`) | Trabajo propio `FamUnit\Infrastructure\Jobs\GenerateImageConsentsExport`, que recorre la solicitud `data_exports` de `kind = image_consents` por `Core\Domain\ExportJobLifecycle` (no existe un trabajo genérico en `Core`). Por lotes. Volumen esperado: dos filas por alumno, miles como mucho: no necesita la cola `long` |

**Ninguna tarea programada** en `1.14`. Si `OPEN-FAM-UNIT-11` decide un informe periódico programado, lo añade `COM` con su planificador (issue #453: el planificador todavía no tiene servicio desplegado).

Compatibilidad de *workers* (`RARQ-DEP-006`): el trabajo de exportación es `GenerateImageConsentsExport` de `FamUnit`; el `kind` nuevo y `ExportJobLifecycle` no cambian el contrato de los trabajos existentes de `Core`.

## 5. Métricas y alertas

Sin métricas propias en `1.14` (no hay pila de observabilidad desplegada todavía). Señales a vigilar cuando exista:

- Latencia del resolutor `unidad_familiar` (se ejecuta en cada petición de un tutor; objetivo dentro de `RNF-PERF-001`).
- Tutores obligados con aceptación pendiente tras publicar una versión con re-aceptación (pico previsible de peticiones `403 terms-acceptance-required`).
- Revocaciones por día: un pico anómalo merece revisión manual (registro en `audit_logs`, `event = updated` sobre `guardian_student_link`).

## 6. Problemas conocidos y diagnóstico

| Síntoma | Causa probable | Qué hacer |
|---------|----------------|-----------|
| Un tutor «no ve a su hijo» | Vínculo revocado, dado de baja o inexistente; o el rol del usuario no tiene `familia.leer` con `unidad_familiar` | Ficha de la unidad con `revocacion_tutor.leer`; permisos efectivos del usuario (`GET /users/{id}/effective-permissions`) |
| Un tutor revocado sigue viendo al alumno | **Incidente crítico** (`CLAUDE.md §5`: exposición de datos de un menor). Un resolutor de otro módulo cachea la pertenencia o no usa `FamilyMembership` | Detener, abrir issue crítico, comprobar el resolutor del módulo afectado contra `permisos.md §3.2` |
| Todos los tutores reciben `403 terms-acceptance-required` | Se publicó una versión con re-aceptación | Comportamiento esperado; confirmar con `GET /terms-versions` |
| Una imagen se publicó con consentimiento `pendiente` | El módulo publicador no consulta `ImageConsentPolicy` al servir (`funcional.md §7.3`) | **Incidente crítico** (`INV-014`); issue en el módulo publicador |
| `422 user_not_found` al dar de alta un tutor con un usuario que existe | El usuario es de otro centro o está borrado | Comprobar en `Core` |
| `409 fam_unit.link.revoked_not_deletable` al dar de baja un vínculo o un tutor | Hay un vínculo `revocado` (`RN-FAM-UNIT-39`, `funcional.md §15` D-10): una revocación no se pierde por una baja | Levantar antes la revocación (`DELETE /guardian-links/{id}/revocation`, permiso `revocacion_tutor.eliminar`) si de verdad procede |
| `422 fam_unit.image_consent.text_not_published` al registrar `autorizo`; todo el alumnado `pendiente` | El centro no ha publicado el texto de ese nivel (`RN-FAM-UNIT-68`) | Publicar la versión del tipo `consentimiento_imagen_web` o `_redes` (`POST /terms-versions/{id}/publish`, `texto_legal.publicar`) |
| Un alumno vuelve a `pendiente` sin que nadie haya decidido | Se dio de alta un representante legal, o se reverificó uno de forma explícita (desmarcar y volver a marcar `is_legal_guardian`; reenviar `true` ya no reverifica, `RN-FAM-UNIT-74`) (`RN-FAM-UNIT-72`, `funcional.md §6`) | Comportamiento esperado: falta la decisión del nuevo representante, o una nueva del reverificado. Comprobar en `audit_logs` los cambios del vínculo (`is_legal_guardian`, `legal_guardian_verified_at`) |
| `422 fam_unit.guardian.user_not_eligible` | El usuario está `inactivo` (`RN-FAM-UNIT-10`: solo `pendiente` o `activo`) | Reactivar la cuenta en `Core` o usar otra |

## 7. Impacto en copias de seguridad y restauración

- Todas las tablas son de tenant y entran en la copia por centro (`REQ-BKP-002`).
- **Restauración y revocaciones**: restaurar un centro a un punto anterior a una revocación **devolvería el acceso** al tutor revocado. Es un riesgo directo para un menor. El procedimiento de restauración (`REQ-BKP-003`) debe incluir, para este módulo, la reaplicación de las revocaciones posteriores al punto restaurado, igual que `REQ-BKP-005` exige reaplicar las supresiones. Se anota para `1.26`; **no se resuelve aquí**.
- **Restauración y consentimientos**: lo mismo con un `no_autorizo` posterior al punto restaurado. Mismo tratamiento.
- El documento de custodia (condicionado) vive en el *bucket*; su copia sigue la de almacenamiento de objetos con versionado (`REQ-BKP-001`).

---

## 8. Despliegue de `1.14-a`: orden, siembra y reversión

Orden: **migrar → `sync-registry` → contratar → siembra** (mismo orden que `acad`):

```bash
# 1. Cinco migraciones aditivas (family_units, guardians, FK de principal_guardian_id, family_unit_students, guardian_student_links).
php artisan migrate --database=pgsql_owner
# 2. Registra el módulo fam_unit y sus once permisos.
php artisan platform:sync-registry
# 3. Contratar fam_unit en cada centro (backoffice, REQ-BO-002).
# 4. Centros que YA existían: siembra idempotente de los permisos en los roles predefinidos (por (rol, código):
#    no pisa un ámbito ya concedido; no toca roles personalizados; solo centros Activo; NO concede nada si falta
#    algún permiso o está retirado (retired_at), e imprime cuál).
php artisan fam_unit:grant-permissions
```

El comando `fam_unit:grant-permissions` **vive en `Core`** (`apps/api/app/Modules/Core/Infrastructure/Console/GrantFamUnitPermissionsCommand.php`), no en `FamUnit`: la escritura en `roles`/`permission_role` es de `Core` (`INV-007`, `AR-08`). La propuesta inicial de `§3` lo ubicaba igual (`Core`).

Los centros **nuevos** la reciben de `tenant:provision-defaults`. Con `fam_unit` sin contratar los permisos son inertes y toda ruta responde `403 module-disabled`.

**Reversión** (`RARQ-DEP-013`): desplegar la imagen anterior; **no** se ejecuta `migrate:rollback` (las tablas son aditivas, la versión anterior no las usa, y su `down()` borraría las familias y las revocaciones del centro: una revocación judicial perdida devolvería el acceso a un tutor). El siguiente `sync-registry` de la versión anterior marca el módulo y sus permisos con `retired_at` (concesiones inertes). Efecto de volver atrás: **ninguna interfaz de `§7` existe**, así que ningún módulo puede excluir a un tutor revocado; mientras dure, no se debe entregar ningún menor por una lista que dependa de ellas (`1.14b` no existe todavía). Al volver a la versión nueva: `migrate --database=pgsql_owner --force`, `sync-registry` y `fam_unit:grant-permissions`.

**Qué se probó y qué no (2026-10-26)**: *probado* con la base de test real: las migraciones (`FamUnitSchemaTest`), el comportamiento de las interfaces y de los resolutores, y las carreras reales de dos procesos (`FamUnitConcurrencyTest`). *No probado*: el procedimiento de reversión sobre un servidor (no hay servidor; `OPEN-11`), ni `migrate:rollback` sobre las tablas con datos.

---

## 9. Despliegue de `1.14-b`: orden, siembra y reversión

Orden: **migrar → `sync-registry` → siembra** (el módulo ya está contratado donde se use; mismo orden que `§8`):

```bash
# 1. Tres migraciones aditivas: kind image_consents en data_exports (Core, expand con NOT VALID + VALIDATE),
#    terms_versions (con kind) e image_consent_decisions (solo anexar, FK RESTRICT al texto).
php artisan migrate --database=pgsql_owner
# 2. Registra los ocho permisos nuevos (consentimiento_imagen.* y texto_legal.*).
php artisan platform:sync-registry
# 3. Centros que YA existían: siembra idempotente (incluye texto_legal.leer en secretaría, CA-FAM-UNIT-106).
php artisan fam_unit:grant-permissions
```

Sin variables de entorno, servicios, colas ni tareas programadas nuevas (los límites de `RN-FAM-UNIT-76` son claves de `config/fam_unit.php` con valor por defecto: `consent.rate_limit.decision` 60/h y `.export` 10/h): el informe usa la cola `default` que ya consume el *worker* (`operacion.md §4`). **Antes de usar el consentimiento de imagen, el centro debe publicar el texto de cada nivel** (`POST /terms-versions` y `…/publish`, `texto_legal.publicar`, solo el administrador): sin él nadie puede registrar `autorizo` y todo el alumnado queda `pendiente` (§6).

**Reversión** (`RARQ-DEP-013`): como en `§3`, desplegar la imagen anterior y **no** ejecutar `migrate:rollback` sobre `image_consent_decisions` ni `terms_versions` (evidencia). La versión anterior no usa las tablas nuevas. Mientras dure, **ningún módulo puede consultar `ImageConsentPolicy`** (no existe en la imagen de `1.14-a`): no hay publicadores todavía (`REQ-COM`/`REQ-INF` son posteriores), así que no hay nada que dejar de bloquear. El `kind image_consents` de `data_exports` queda inerte (su `down()` falla si hay filas de ese tipo: se purgan antes a mano). Al volver a la versión nueva: `migrate --database=pgsql_owner --force`, `sync-registry` y `fam_unit:grant-permissions`.

**Qué se probó y qué no (2026-10-27)**: *probado* con la base de test real: las migraciones y las restricciones del motor (`ImageConsentSchemaTest`), las carreras reales de dos procesos (decisiones simultáneas y publicaciones simultáneas, `FamUnitConcurrencyTest`), la interfaz `ImageConsentPolicy` y el informe con la cola `sync` y `Storage::fake`. *No probado*: el informe contra S3 real, la cola `database` con un *worker* real, el procedimiento de reversión sobre un servidor (no hay servidor, `OPEN-11`) y el rendimiento del cálculo del estado con miles de alumnos (una sentencia con `DISTINCT ON`; sin medición).

---

## 10. Despliegue de `1.14-c`: orden, permiso y reversión

Orden: **migrar → `sync-registry` → siembra** (mismo orden que `§9`):

```bash
# 1. Una migración aditiva: terms_acceptances (solo anexar, FK RESTRICT a terms_versions).
php artisan migrate --database=pgsql_owner
# 2. Registra el permiso nuevo aceptacion_condiciones.leer.
php artisan platform:sync-registry
# 3. Centros que YA existían: siembra idempotente (aceptacion_condiciones.leer en administrador_centro y secretaria).
php artisan fam_unit:grant-permissions
```

Sin variables de entorno, servicios, colas ni tareas programadas nuevas (el módulo no encola nada). El muro de condiciones solo actúa si el centro tiene `fam_unit` contratado y ha publicado una versión de `condiciones_uso` con `requires_reacceptance`.

**Reversión** (`RARQ-DEP-013`): desplegar la imagen anterior y **no** ejecutar `migrate:rollback` sobre `terms_acceptances`: su `down()` falla mientras haya filas y es **evidencia legal** de qué texto aceptó cada tutor; cualquier reversión de esa tabla requiere aprobación expresa y documentada. La imagen anterior no lee la tabla: el muro de condiciones deja de existir y la imagen anterior vuelve a traer su propio `RequireMfaEnrollment`. El siguiente `sync-registry` de la versión anterior marca el permiso con `retired_at`.

**Qué se probó y qué no (2026-10-28)**: *probado* con la base de test real, las migraciones, el muro y la inmutabilidad en el motor. *No probado*: el procedimiento de reversión sobre un servidor (no hay servidor, `OPEN-11`) ni el E2E contra la API real (`funcional.md §19`, D-39).

## 11. Despliegue de `1.14-d`: sin cambios de despliegue

Solo SPA (pantallas de personal de `funcional.md §10`): ninguna migración, variable de entorno, servicio, cola, tarea programada ni permiso nuevos, y no hay nada que sembrar ni sincronizar. **Reversión**: desplegar la versión anterior de la web; no hay nada que deshacer en la API ni en la base de datos. No probado sobre un servidor (`OPEN-11`).
