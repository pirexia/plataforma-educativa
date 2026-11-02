# REQ-PRL · Puerta y entrega de menores · Operación

> **Origen del contenido.** Traslado **sin cambios de contenido** de lo marcado `prl` en `docs/modulos/REQ-FAM-UNIT/` (especificación del paso `1.14b`, aprobada el 2026-10-29), tal como exige `ADR-068 §5.1`. La numeración de IDs (`RN-PRL-*`, `CA-PRL-*`, `OPEN-*`, secciones `§21.x`/`§8.x`) se conserva tal cual para que las referencias cruzadas existentes sigan siendo válidas. Las referencias a `§N` sin más indican la sección de `REQ-FAM-UNIT/funcional.md`, `datos.md`, `api.md`, `permisos.md` u `operacion.md` del fichero homónimo de ese módulo.

## 12.1 Configuración

El texto del protocolo ante persona no autorizada es **configuración del centro** (`settings` del módulo `prl`, `modulo.actualizar`, `ADR-045`), no del despliegue (`OPEN-FAM-UNIT-40`).

## 12.4 Despliegue: orden, siembra y reversión

Véase `REQ-FAM-UNIT/operacion.md §12.4` (orden común: migrar, `sync-registry`, contratar `prl`, siembra, publicar el texto `autorizacion_recogida`). Reversión: `student_movements` y `pickup_alerts` son evidencia; sus `down()` fallan si hay filas.

## 12.5 Métricas, alertas y diagnóstico

Señales de `prl`: alertas `persona_no_autorizada` por día y errores `422 person_not_authorized` (posible intento de recogida indebida o lista desactualizada). Véase `REQ-FAM-UNIT/operacion.md §12.5`.

## 12.6 Despliegue de `1.14b-b`: orden, siembra y reversión

Orden: **migrar → `platform:sync-registry` → contratar `prl` (que exige `fam_unit`) → `php artisan fam_unit:grant-permissions`** (centros existentes; los nuevos los siembra `tenant:provision-defaults`). Dos migraciones aditivas: `student_movements` y `pickup_alerts` (`php artisan migrate --database=pgsql_owner`). **Reversión**: desplegar la imagen anterior; **no** ejecutar `migrate:rollback` sobre estas tablas (evidencia: sus `down()` fallan si hay filas). Efecto de volver atrás: desaparecen la puerta y los movimientos de la API; el centro usa su procedimiento de contingencia. Los trabajos y la cola no cambian: `prl` no tiene tareas en cola ni programadas (`INV-012`: no hay trabajo pesado; la lista y la firma de fotografías son consultas acotadas).

## 12.7 Verificación contra la API real (E2E sin navegador)

`apps/web/e2e/prl-door-api.spec.ts`, lanzado con `npm run test:e2e:real:prl` (`apps/web/scripts/e2e-real-prl.sh`): crea **dos centros sintéticos** con `apps/api/tests/Support/e2e-real-tenant.php setup <slug> door` (`fam_unit` y `prl` contratados, conserje y secretaría con los roles predefinidos, dos alumnos de nombre ficticio, `@example.com`), recorre la puerta con **HTTP real**, cookie de sesión y CSRF (`GET /auth/csrf-cookie` → `POST /auth/session`, cabecera `X-XSRF-TOKEN`) y los retira al terminar. Cubre persona → autorización → fotografía (`PUT …/photo` multipart con un JPEG sintético con EXIF que el servidor debe eliminar), búsqueda, lista de puerta con foto firmada descargada del almacén, salida verificada por foto, persona que no figura idéntica a la excluida por revocación, método de verificación del tutor sin fotografía, fuera de horario, alerta y su resolución (`409` al reabrir), idempotencia, auditoría y aislamiento entre los dos centros.

- **Documentos de prueba**: la API valida el dígito de control de `dni`/`nie` (`RN-CORE-90`), así que un documento «con dígito de control inválido» (`REQ-SEED-005`) se rechaza; el *script* usa `pasaporte` con valores ficticios (`E2EFICT0001`).
- **Almacén S3**: requiere un servicio S3 en `minio:9000` con el *bucket* `plataforma`. Con MinIO (`podman compose --profile full up -d minio`) basta. Si la imagen no se puede descargar, `apps/api/tests/Support/e2e-fake-s3.php` es un **servidor S3 mínimo y falso** (`PutObject`, `GetObject`, `HeadObject`, `DeleteObject`, `ListObjectsV2` y crear *bucket*, sin validar firmas ni caducidad) que se lanza desde un contenedor de la red de desarrollo: `podman run -d --name minio --network plataforma-net -p 127.0.0.1:9000:9000 -v ./apps/api:/var/www/html:Z -w /var/www/html localhost/plataforma-educativa_api php -S 0.0.0.0:9000 tests/Support/e2e-fake-s3.php`, y el *bucket* se crea con `PUT http://127.0.0.1:9000/plataforma`. **Con el servidor falso no se prueba la caducidad ni la firma de las URL** (las comprueba `PrivateObjectStoreTest` con un disco de prueba) ni un S3 real.
