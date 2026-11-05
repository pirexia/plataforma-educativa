# REQ-FAM-UNIT · Operación

> **Estado**: **APROBADA por el usuario** (2026-10-26, todas las recomendaciones de `OPEN-FAM-UNIT-01..21` aceptadas tal cual; ver `funcional.md §13`). **`1.14-a` implementada (2026-10-26)**: cinco migraciones aditivas, once permisos y el comando `fam_unit:grant-permissions`; ninguna variable de entorno, servicio, cola ni tarea programada nuevos (`§8`). **Enmendada por `ADR-066` (ACEPTADA, 2026-10-27; `funcional.md §16`)**: `1.14-b` crea `terms_versions` (con `kind`) y `image_consent_decisions`; ya no se crea `image_consents`. **`1.14-b` IMPLEMENTADA (2026-10-27; `funcional.md §18`)**, aprobada por el usuario (`funcional.md §17`); sin variables, colas ni tareas programadas nuevas por la aprobación. **Condiciones previas a cargar datos reales del consentimiento de imagen** (no bloquean el despliegue con datos sintéticos): validación jurídica de `OPEN-FAM-UNIT-08`, criterio sobre 14 y 18 años (`OPEN-FAM-UNIT-18`) y respuesta a `OPEN-066-02` (`funcional.md §13`). **Paso `1.14b`**: operación de §12 **APROBADA** (2026-10-29, con `ADR-068` ACEPTADA); sin implementar.

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

---

## 12. Paso `1.14b` (autorizados y entrega de menores) — APROBADA (2026-10-29)

> **Estado**: **APROBADA** (2026-10-29), con `ADR-068` ACEPTADA (`funcional.md §21.2`). **`1.14b-a` implementada (2026-11-02)**: lo de `fam_unit` y del núcleo (§12.1 a §12.4, salvo `prl`); lo de `prl` (despliegue, verificación contra la API real) se **implementó en `1.14b-b` (2026-11-02)**: `docs/modulos/REQ-PRL/operacion.md`. **Verificado** (`funcional.md §22.1`): la subida `PUT` multipart en FrankenPHP, `gd` y `exif` en el contenedor reconstruido, el reprocesado de 12 MP en 0,38 s y la suite completa dentro del contenedor. **Sin probar**: un MinIO/S3 real (la imagen de MinIO no se puede descargar), la reversión en servidor y el planificador (issue #453). Lo de `prl` (protocolo, `student_movements`, `pickup_alerts`) sigue sin implementar.

> **Referencia cruzada**: lo operativo del módulo `prl` (protocolo ante persona no autorizada, métricas de alertas) está también en `docs/modulos/REQ-PRL/operacion.md` (traslado de `1.14b-b`, `ADR-068 §5.1`).

### 12.1 Variables de entorno y configuración

**Variables de entorno**: ninguna propia de `fam_unit`, pero el almacenamiento privado exige S3 compatible en `production` y `staging` (`funcional.md §22` D-67, ajuste del 2026-11-02; en desarrollo con `APP_ENV=local` vale el disco local): `FILESYSTEM_DISK=s3`, `AWS_ENDPOINT`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` y `AWS_USE_PATH_STYLE_ENDPOINT=true` (`files.disk` toma por defecto `filesystems.default`). La imagen fija `upload_max_filesize = 6M`, `post_max_size = 8M` y `memory_limit = 256M`. Claves de configuración con valor por defecto (en `config/fam_unit.php` y, con `prl`, `config/prl.php`), revisables:

| Clave | Defecto [DERIVADA] | Regla |
|-------|--------------------|-------|
| `files.disk` (núcleo, `config/files.php`, `App\Support\Files`) | `filesystems.default` (disco privado S3; MinIO en desarrollo) | Disco donde `PrivateObjectStore` escribe y firma las fotografías (**sin S3 configurado la aplicación falla en cerrado**: rechaza un disco `local` en `production` y `staging`, `funcional.md §22` D-67; con `APP_ENV=local` o `testing` es válido), bajo `tenants/<tenant public_id>/<área>/` (`ADR-068 §5.3`, `INV-001`). Clave compartida con cualquier fichero privado futuro, no propia de `fam_unit` |
| `fam_unit.pickup.photo.max_bytes` | 5 MB | `RN-FAM-UNIT-83` |
| `fam_unit.pickup.photo.max_dimension` | 1024 px (lado mayor tras reprocesar) | `RN-FAM-UNIT-83` |
| `fam_unit.pickup.photo.max_input_pixels` | 12 000 000 (4000 × 3000) | Píxeles máximos de **entrada**, comprobados antes de decodificar (`ADR-068 §5.3`); acota la memoria de `gd` (`funcional.md §22` D-57) |
| `fam_unit.pickup.photo.signed_url_ttl_minutes` | 5 | `RN-FAM-UNIT-83` |
| `fam_unit.pickup.photo.orphan_grace_days` | 30 (`OPEN-FAM-UNIT-37`) | §8.8 de `datos.md` |
| `fam_unit.pickup.read_rate_limit` | 300 por hora, por centro y usuario (`attempts` y `decay_seconds`) | `RN-FAM-UNIT-93` |
| `fam_unit.pickup.person_rate_limit.lookup` / `.register` | 60 por hora cada uno, por centro y usuario | Búsqueda por documento y alta de personas (`funcional.md §22` D-66) |

El texto del protocolo ante persona no autorizada es **configuración del centro** (`settings` del módulo `prl`, `modulo.actualizar`, `ADR-045`), no del despliegue (`OPEN-FAM-UNIT-40`).

### 12.2 Servicios externos

- **Almacenamiento S3** (MinIO en desarrollo): pasa a ser **necesario para la operación diaria** (fotografías en la puerta), no solo para informes. Sin S3 configurado (`FILESYSTEM_DISK` distinto de un disco S3 en `production`/`staging`) la subida y la firma **fallan en cerrado** en `production` y `staging` (en desarrollo reducido, sin MinIO, se usa el disco local). Si no responde, la lista se sirve con `photo_url = null` y el aviso de verificar por documento; **no** se bloquea la entrega a quien figura, porque la verificación por documento sigue siendo posible [DERIVADA, a ratificar]. El alta y sustitución de fotos sí falla (`503`) sin escribir nada.
- **Extensiones de PHP `gd` y `exif`** para reprocesar fotografías (D3): `ADR-068` (hecho 7) verificó que la imagen no tiene ninguna; `L8` (aprobada 2026-10-29) las añade en `infra/containers/api/Containerfile` (etapa `base`, 7 a 9 extensiones) y en las dos listas de `.github/workflows/ci-api.yml` (6 a 8). Con la dependencia de Composer `league/flysystem-aws-s3-v3` (MIT) para el disco S3, decisión del usuario del 2026-11-02 (`funcional.md §22` D-67); las extensiones no añaden ningún paquete.
- Ningún servicio de correo, SMS ni notificaciones (diferido a `1.19`).

### 12.3 Colas y tareas programadas

| Trabajo | Cola | Cuándo | Notas |
|---------|------|--------|-------|
| Reprocesado de la fotografía | — (síncrono) | En la subida | Una imagen de 5 MB como máximo; la respuesta debe llevar la foto ya limpia y la autorización depende de ella (`RN-FAM-UNIT-84`). Medido en `1.14b-a`: **0,38 s** para 12 MP con EXIF 6 (`funcional.md §22` D-70), dentro del objetivo, así que **se mantiene síncrono**; si una medición futura lo supera, pasaría a la cola `default` con estado «procesando» (decisión de implementación con `db-reviewer`) |
| Barrido de fotografías huérfanas | `default` | Diario | Patrón `PurgeOrphanBrandingAssets`: `PurgeOrphanPickupPhotos`, despachado por centro con `php artisan fam_unit:purge-orphan-photos` (programado `daily()` en `routes/console.php`; criterios en `funcional.md §22` D-58). **Depende del planificador**, que no tiene servicio desplegado (issue #453); mientras no lo tenga, comando manual documentado en `RUNBOOK.md` |

La caducidad de las autorizaciones puntuales **no** necesita tarea: es una condición de fecha en la consulta (`RN-FAM-UNIT-82`).

### 12.4 Despliegue: orden, siembra y reversión

Orden: **migrar → `sync-registry` → contratar `prl` → siembra → publicar el texto `autorizacion_recogida`**:

1. Migraciones aditivas (*expand*): `pickup_photos`, ampliación del `CHECK` de `terms_versions.kind`, `pickup_authorizations` (FK `RESTRICT` a `terms_versions`), y con `prl`: `student_movements` (solo anexar) y `pickup_alerts`. `php artisan migrate --database=pgsql_owner`. `db-reviewer` obligatorio.
2. `platform:sync-registry`: registra `autorizado_recogida.*` en `fam_unit` y el módulo `prl` (`phase 1`, `depends_on ['fam_unit']`) con `entrega_menor.*` y `alerta_entrega.*`; aborta si la dependencia no existe.
3. Contratar `prl` en cada centro (Super Administrador, `ADR-045`; el *backoffice* exige `fam_unit`, `RMOD-006`).
4. Siembra para centros existentes (comando de `Core`, §8.4 de `permisos.md`: `administrador_centro`, `secretaria`, `direccion`, `conserjeria_pas` y `tutor_legal`).
5. **Antes de dar de alta terceros**, el centro publica el texto `autorizacion_recogida` (sin él, `422 text_not_published`, `RN-FAM-UNIT-85`). Los tutores figuran en la lista sin necesidad de ese texto.

**Reversión** (`RARQ-DEP-013`): desplegar la imagen anterior; **no** ejecutar `migrate:rollback` sobre `pickup_authorizations`, `student_movements` ni `pickup_alerts` (evidencia ante un incidente con un menor); sus `down()` deben fallar si hay filas (patrón `db-reviewer` B-3 de `1.14-b`). **Efecto de volver atrás**: desaparecen la lista y la puerta; **mientras dure, el centro no puede apoyarse en el sistema para entregar menores** y debe usar su procedimiento de contingencia (`OPEN-FAM-UNIT-41`). El procedimiento probado se escribe en `RUNBOOK.md` al cerrar cada entrega.

### 12.5 Métricas, alertas y diagnóstico

Señales a vigilar cuando haya observabilidad: lecturas de lista por usuario y hora (un conserje que abre cientos de listas fuera de la hora de salida es anómalo; el límite de `RN-FAM-UNIT-93` lo frena), alertas `persona_no_autorizada` por día, errores `422 person_not_authorized` (posible intento de recogida indebida o lista desactualizada).

| Síntoma | Causa probable | Qué hacer |
|---------|----------------|-----------|
| Un abuelo dado de alta no aparece en la puerta | Sin foto, fuera de vigencia, retirada, o persona excluida por revocación (`ineffective_reason`) | Ficha de la autorización; si es `no_vigente` y no hay otra causa, la ve dirección con `revocacion_tutor.leer` |
| Una persona revocada aparece en la lista | **Incidente crítico** (`CLAUDE.md §5`): la composición no aplica `RN-FAM-UNIT-80` o un consumidor guarda copia | Detener, issue crítico, comprobar `CA-FAM-UNIT-112` |
| Fotos sin cargar en la puerta | Almacenamiento caído o URL caducada (cliente que la cachea) | Verificar por documento; la SPA nunca reutiliza una URL caducada |
| `422 text_not_published` al dar de alta un tercero | El centro no ha publicado `autorizacion_recogida` | Publicarlo (`texto_legal.publicar`) |

### 12.6 Copias de seguridad

Las tablas nuevas entran en la copia por centro; los objetos de fotografía, en la copia del almacenamiento con versionado (`REQ-BKP-001`). **Restaurar a un punto anterior a una retirada o a una revocación devolvería a alguien a la lista de un menor**: se suma a `OPEN-FAM-UNIT-21` para `1.26` (reaplicación tras restaurar). No se resuelve aquí.

**`1.14b-c1` (pantallas de autorizados de recogida)**: solo `apps/web`; ninguna variable de entorno, servicio, cola ni tarea programada nuevos. E2E real: `docs/modulos/REQ-PRL/operacion.md §12.7`. Cabecera `Cache-Control: no-store` del servidor pendiente (#510).

---

## 13. Endurecimiento del consentimiento de imagen (issues #480, #482, #483, #484)

Entrega de correcciones Bajas sobre `1.14-b` (`REQ-FAM-UNIT-004`, `ADR-066`). Sin servicios, colas ni tareas programadas nuevas.

### 13.1 Migraciones (orden: `php artisan migrate --database=pgsql_owner`)

Tres migraciones *expand*, aditivas y reversibles (`down()` no se niega con datos: no destruye evidencia), sin tocar filas:

| Migración | Qué hace | Coste en una tabla en uso |
|-----------|----------|---------------------------|
| `2026_11_05_100100_add_terms_versions_published_immutable_trigger` (#482) | Disparador que impide cambiar título y cuerpo de una versión que no es borrador | `CREATE TRIGGER` toma un bloqueo breve (`lock_timeout` 5 s) |
| `2026_11_05_100200_widen_consent_verification_timestamps_to_microseconds` (#483) | `image_consent_decisions.occurred_at` y `guardian_student_links.legal_guardian_verified_at` pasan a `timestamptz(6)` | Sin reescritura de tabla ni de índices (ampliar la precisión solo cambia el `typmod`; comprobado: el `relfilenode` de la tabla y del índice no cambia). `ACCESS EXCLUSIVE` instantáneo con `lock_timeout` 5 s |
| `2026_11_05_100300_add_image_consent_decisions_user_agent_length_check` (#480 punto 1) | `CHECK (char_length(user_agent) <= 512)` | `NOT VALID` + `VALIDATE CONSTRAINT` (sin bloqueo largo). Si `VALIDATE` falla, aborta y no se corrigen filas |

**Compatibilidad con la versión anterior del código** (`RARQ-DEP-005`): la anterior escribe en segundos (valores válidos en `timestamptz(6)`), trunca el `user_agent` a 512 y no edita textos publicados, de modo que ninguna restricción la rechaza. **Reversión**: `migrate:rollback --step=3` es seguro (el paso de precisión redondea a segundo).

### 13.2 Síntomas

| Síntoma | Causa probable | Qué hacer |
|---------|----------------|-----------|
| `SQLSTATE 23001` (`restrict_violation`) al editar una versión de texto legal | Un `UPDATE` directo (no por la API) de título o cuerpo de una versión publicada o retirada | Es la protección esperada: la evidencia no se edita. Publicar una versión nueva |
| `SQLSTATE 23514` (`image_consent_decisions_user_agent_length_check`) | Un `INSERT` directo con `user_agent` de más de 512 caracteres | Truncar antes de insertar; el servicio ya lo hace |

### 13.3 Proxies de confianza: `TRUSTED_PROXIES` (#484)

`ImageConsentService` registra `ip_address` con `$request->ip()`. Detrás de Traefik, sin confiar en el proxy, esa IP era la de Traefik y el dato no probaba nada. `bootstrap/app.php` configura ahora `trustProxies` (clase `App\Support\Http\TrustedProxyList`):

- **Variable `TRUSTED_PROXIES`** (lista separada por comas de IP o CIDR). **Por defecto `10.89.10.0/24`**, la subred de `infra/quadlet/plataforma.network` a la que se unen Traefik, `api@` y `web` (`ADR-028`: no se escribe a mano ninguna IP de contenedor). Se aborta el arranque con `*`, `0.0.0.0/0` o `::/0` fuera de `local` y `testing`, o con una entrada que no sea IP ni CIDR.
- **Solo `X-Forwarded-For`**. `X-Forwarded-Host/Proto/Port` no se aceptan: el tenant se resuelve por `Host` (`ADR-014`).
- Desde un origen que no es de confianza, `X-Forwarded-For` se ignora (el cliente no puede falsear su IP). Con una cadena `a, b`, la IP es la última no confiable por la derecha.
- Traefik recibe hoy la conexión directamente (`PublishPort=80:80`) y reescribe `X-Forwarded-For` con la IP real; si un día hay otro proxy delante, además de esta variable hace falta `forwardedHeaders.trustedIPs` en `traefik.container` (su comentario lo recoge).
- **Efecto colateral deseado**: todo lo que usa `$request->ip()` pasa a ver la IP del cliente —limitadores por IP, `login_attempts`, sesiones, y la lista blanca de IP del *backoffice* (`EnforcePlatformIpAllowlist`), que antes comparaba contra la del proxy—. Si `TRUSTED_PROXIES` queda fuera de la red real, nada falla: la IP vuelve a ser la del proxy.

**Verificar en un despliegue real queda pendiente.** Los tests (`TrustedProxiesTest`, `TrustedProxyListTest`) prueban la lógica con direcciones simuladas, no la cadena Traefik real. Comprobación obligatoria antes de dar valor probatorio a `ip_address`: registrar una decisión desde un cliente con IP conocida y confirmar que `image_consent_decisions.ip_address` es la del cliente y no `10.89.10.x`; y que la subred por defecto coincide con `plataforma.network` del servidor. Mientras no se haga, el campo no se aporta como prueba (`REQ-PRIV-003`, `api.md §2.7`).
