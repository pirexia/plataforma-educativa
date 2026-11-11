# ADR-076 · Almacenamiento de objetos: la API S3 como contrato único, Garage fijado por versión y digest en desarrollo, CI y E2E, y todo acceso por el almacén privado con prefijo del centro

**Estado**: **ACEPTADA** (usuario, 2026-11-11). Las preguntas abiertas de `§10` se resuelven así: la 2 (contrato en `App\Support\Files`) y la 3 (Garage solo en el perfil `full`) con la recomendación; las 4, 5 y 6 conservan el valor propuesto y siguen **abiertas** (no las contestó el usuario); la 1 (dónde vive el expediente de cumplimiento) sigue **pendiente**; la 7 (issue #38) se cierra con la entrega `S-1b`. La primera entrega de `§8` se parte en `a` y `b` (recomendación de la sesión, aplicada al aceptar).
**Fecha**: 2026-11-11
**Origen**: decisiones del usuario del 2026-11-11: la API S3 es el contrato único de almacenamiento (Flysystem S3 tras `PrivateObjectStore`) y `OPEN-STO-02` = A (Garage sustituye a MinIO y al servidor S3 falso en desarrollo, CI y E2E; no en producción).
**Afecta a**: `compose.yaml`, `infra/` (nuevo `infra/garage/`, retirada de `infra/quadlet/minio-data.volume`), `apps/api/config/filesystems.php`, `apps/api/app/Support/Files/`, los 15 ficheros con acceso directo al disco (§3.2), `apps/api/tests/Support/e2e-fake-s3.php`, `apps/web/scripts/e2e-real-*.sh`, `apps/web/scripts/landing-shots.sh`, los *specs* de E2E de §7.1, `.github/workflows/ci-api.yml` y la documentación viva de §7.3.
**Concreta sin sustituir**: `ADR-013` (almacenamiento compatible con S3, URL firmadas de caducidad corta, sin acceso público), `ADR-033 §9` (prefijo `tenants/{public_id}/`) y `ADR-068 §5.3` (`PrivateObjectStore`).
**Sustituye en parte a**:
- `ADR-030`, punto «Perfil de desarrollo reducido»: donde dice «MinIO» pasa a leerse «Garage (`§2.2` de este ADR)». El resto del punto sigue vigente.
- `ADR-037`, tabla de ficheros de Quadlet (fila ya enmendada por `ADR-061`): se elimina `minio-data.volume`. No hay volumen de almacenamiento de objetos en Quadlet hasta que `OPEN-STO-01` se resuelva.
**Se apoya en**: `INV-001`, `INV-007`, `RARQ-INF-007`, `RNF-MANT-007`, `CLAUDE.md §8` (ficheros con URL firmada de caducidad corta), `CLAUDE.md §9`.
**Fuera de este ADR (expediente aparte)**: proveedor de producción (`OPEN-STO-01`) y cumplimiento (`OPEN-STO-04` a `-12`: ENS, EIPD, ISO propia, DPA, plazos de conservación, salida del proveedor, evidencias). Se citan como pendientes; aquí no se decide nada sobre ellos.

---

## 1 · Contexto

### 1.1 Qué hay hoy

- **Contrato**: el disco `s3` de Laravel (`config/filesystems.php`) sobre `league/flysystem-aws-s3-v3` 3.35.3 y `aws/aws-sdk-php` 3.403.1 (`composer.lock`). `PrivateObjectStore` (`App\Support\Files`, `ADR-068 §5.3`) escribe bajo `tenants/<public_id>/<área>/<ULID>.<ext>`, toma el centro de `TenantContext` y rechaza claves ajenas con `ForeignObjectKeyException`.
- **Desarrollo**: servicio `minio` en `compose.yaml` (perfil `full`) con `image: docker.io/minio/minio:latest`, sin versión ni digest, y credenciales por defecto `minioadmin` como valor de reserva en el entorno de `api`/`worker`.
- **E2E**: como la imagen de MinIO ya no se descarga, se usa `apps/api/tests/Support/e2e-fake-s3.php`, un servidor S3 falso que se hace pasar por `minio:9000`. **No valida firmas ni caducidad** (lo dice su propia cabecera). Los *specs* reescriben el *host* de la URL firmada (`minio:9000` → `127.0.0.1:9000`) para que el navegador la alcance.
- **Suite Pest**: `Storage::fake('local')` con `FILESYSTEM_DISK=local` forzado en `phpunit.xml`. Ningún test ejecuta hoy el *driver* S3 contra un servidor.

### 1.2 Problemas

1. **MinIO no es una base sostenible**: el repositorio comunitario está archivado y desde octubre de 2025 no publica imágenes descargables (estudio previo de esta sesión; no reverificado al redactar este borrador). `latest` sin digest, además, es irreproducible.
2. **El E2E no prueba lo que dice probar**: firma, caducidad y región de las URL firmadas nunca se han validado contra un servidor que las compruebe. Un fallo de firma en producción pasaría todas las pruebas actuales.
3. **El prefijo del centro se compone a mano fuera de `PrivateObjectStore`**: 16 llamadas `Storage::disk(config('filesystems.default'))` en 14 ficheros de `apps/api/app` (recuento con `grep` del 2026-11-11; el estudio previo hablaba de «unas 20»; la diferencia no se ha conciliado, ver §10). Escriben claves `tenants/{public_id}/exports/…` o `tenants/%s/branding/…` con `sprintf` o interpolación. `INV-001` depende ahí de la disciplina de cada llamador, no del framework. Caso concreto: `PurgeExpiredExports` borra `$export->object_key` tal como viene de la base de datos sin comprobar que cuelgue del prefijo del centro.
4. **Sumas de verificación del SDK**: desde `aws-sdk-php` 3.337 el cliente calcula y envía sumas de verificación por defecto (`when_supported`) y valida las de respuesta. Varios servidores S3 que no son AWS las rechazan o no las devuelven. La versión instalada (3.403.1) está afectada y `config/filesystems.php` no fija `request_checksum_calculation` ni `response_checksum_validation`.
5. **Dos abstracciones solapadas**: `TenantStorage` (`ADR-033 §9`, prefijo por `root` del disco) solo se usa en su propio test; `PrivateObjectStore` es la que usa el código. Tener dos invita a una tercera.

## 2 · Decisión

### 2.1 La API S3 es el contrato único

Todo almacenamiento de objetos de la aplicación habla S3 (firma SigV4) a través de Flysystem S3, envuelto por la interfaz de §3 (`RNF-MANT-007`). No se añade ningún *driver* nativo de otro proveedor. Cambiar de servidor (Garage, el proveedor de producción, otro emulador) solo cambia variables de entorno y, en desarrollo, `compose.yaml`.

### 2.2 Garage en desarrollo, CI y E2E

- Imagen de Garage **fijada por versión y por digest** (`image: <registro>/garage:vX.Y.Z@sha256:…`), en un solo sitio (`compose.yaml`) que también lee CI. Prohibidos `latest` y las etiquetas sin digest. Renovate propone las subidas, que pasan por el CI como cualquier dependencia.
- Un solo nodo, `replication_factor = 1`, solo datos sintéticos (`ADR-030`, `REQ-SEED-005`).
- **Garage nunca se despliega en un servidor** (staging ni producción) por este ADR. Si `OPEN-STO-01` acabara eligiendo Garage autoalojado para producción, haría falta un ADR nuevo: un nodo único no da durabilidad y Garage carece de versionado, Object Lock y cifrado gestionado en servidor (§2.3).
- La versión exacta y su digest **no se fijan en este borrador**: se eligen al implementar, comprobando la última estable publicada y su registro, y se anotan en `SYSADMIN.md`.

### 2.3 Matriz: qué se prueba con Garage y qué solo en staging

| Comportamiento | Garage (dev, CI, E2E) | Staging contra el proveedor real |
|---|---|---|
| `PutObject`, `GetObject`, `HeadObject`, `DeleteObject`, `ListObjectsV2` | Sí | Sí (conformidad, §5) |
| URL firmada GET válida (SigV4, región, *path-style*) | Sí | Sí |
| URL firmada caducada → `403` | Sí | Sí |
| URL con firma alterada o de otra clave → `403` | Sí | Sí |
| Aislamiento por prefijo de centro (es lógica de la aplicación) | Sí | Sí |
| Configuración de sumas de verificación del SDK | Sí (con el valor de §4) | Sí: confirma que el valor elegido funciona con el proveedor |
| Subida multiparte (si algún flujo la usa) | Sí | Sí |
| Estilo *virtual-hosted* | No (se usa *path-style*) | Solo si el proveedor lo exige |
| TLS del *endpoint* | No (HTTP en la red local) | Sí |
| Versionado, Object Lock, retención | **No: Garage no lo implementa** | Sí, si `OPEN-STO-*` lo exige |
| Cifrado en servidor gestionado (SSE-S3/SSE-KMS) | **No: Garage no lo implementa** | Sí, si `OPEN-STO-*` lo exige |
| Reglas de ciclo de vida, políticas de *bucket*, bloqueo de acceso público | No | Sí |
| Límites de peticiones, latencia, errores transitorios del proveedor | No | Sí |
| Copias y restauración del *bucket* | No | Sí (con el expediente de cumplimiento) |

Consecuencia directa: **nada de la columna «No» puede darse por probado en verde de CI**. Si una funcionalidad depende de ella, su criterio de aceptación se verifica en staging y así se dice en su informe.

## 3 · Abstracción

### 3.1 Qué pasa por el almacén privado

Todo objeto que pertenezca a un centro: activos de marca, ficheros de importación y sus artefactos, exportaciones (usuarios, auditoría, alumnado, consentimientos de imagen), fotografías (recogida, alumnado) y cualquier fichero futuro de módulo. Ningún módulo obtiene un `Filesystem` crudo ni compone `tenants/…`.

Excepción, explícita: objetos de plataforma no ligados a un centro, si llegan a existir, irán por una variante de plataforma separada, nunca por el disco crudo. Hoy no hay ninguno.

### 3.2 El hueco: acceso directo al disco (16 llamadas en 15 ficheros)

| Fichero (`apps/api/app/Modules/…`) | Operaciones |
|---|---|
| `FamUnit/Infrastructure/Jobs/GenerateImageConsentsExport.php` | `put` |
| `Core/Application/UploadBrandingAsset.php` | `put` (clave `tenants/%s/branding/…` con `sprintf`) |
| `Core/Infrastructure/EloquentBulkUserImporter.php` | `put` |
| `Core/Infrastructure/BrandingAssetUrlSigner.php` | `temporaryUrl` |
| `Core/Infrastructure/Jobs/GenerateUserExport.php` | `put` (clave interpolada) |
| `Core/Infrastructure/Jobs/PurgeOrphanBrandingAssets.php` | `files`, `lastModified`, `delete` |
| `Core/Infrastructure/Jobs/ValidateUserImport.php` | `get`, `put` |
| `Core/Infrastructure/Jobs/PurgeExpiredExports.php` | `delete` (sin comprobar prefijo) |
| `Core/Infrastructure/Jobs/GenerateAuditLogExport.php` | disco completo |
| `Core/Infrastructure/Jobs/PurgeImportArtifacts.php` | disco completo |
| `Core/Infrastructure/Jobs/ExecuteUserImport.php` | `get` |
| `Core/Http/Controllers/UserImportsController.php` | disco completo |
| `Core/Http/Controllers/DataExportsController.php` | `temporaryUrl` |
| `Core/Http/Resources/UserImportResource.php` | `temporaryUrl` |
| `Alum/Infrastructure/Jobs/GenerateEnrollmentsExport.php` | `put` (clave interpolada) |

(15 ficheros; `ValidateUserImport.php` tiene dos llamadas. La lista exacta se regenera con `grep` al implementar.)

### 3.3 Propuesta de interfaz (no se implementa en este ADR)

Un contrato `TenantObjectStore` en el núcleo compartido `App\Support\Files`, implementado por `PrivateObjectStore`. Se deja en `App\Support` y no en el módulo `Core` porque `FamUnit` y `Alum` también lo usan y `INV-007` impide que importen internos de otro módulo; si el usuario entendía «Core» como el módulo, ver §10.

Reglas que el contrato impone:

1. **El prefijo `tenants/<public_id>/` lo pone siempre la implementación**, tomando el centro de `TenantContext`. Ningún método recibe el centro ni una clave con prefijo para escribir.
2. **Escritura**: `put(área, contenido, extensión, ?nombre)` y `putStream(…)` devuelven la clave completa. `nombre` opcional (ULID o `public_id` ya existente, validado con el mismo patrón) para conservar las claves que hoy usan exportaciones y marca (`exports/{public_id}.csv`, `branding/{tipo}/…`). **El formato de clave actual no cambia**: las claves guardadas en la base de datos (`object_key`, `*_object_key`) siguen resolviendo sin migrar datos.
3. **Lectura, firma, borrado**: `get`, `readStream`, `exists`, `lastModified`, `delete`, `temporaryUrl(clave, caducidad, ?nombreDescarga)`. Todos validan que la clave cuelgue del prefijo del centro en curso (como `assertOwn` hoy). `temporaryUrl` limita la caducidad al máximo de §4.
4. **Listado**: `keysIn(área)`, solo dentro del prefijo.
5. **Trabajos de purga por centro** (`PurgeExpiredExports`, `PurgeImportArtifacts`, `PurgeOrphanBrandingAssets`): ya reciben un centro; entran en su `TenantContext` y usan el contrato. Ninguno necesita listar el *bucket* entero.
6. **Cumplimiento automático**: un test de arquitectura de Pest prohíbe `Illuminate\Support\Facades\Storage` y `Storage::disk`/`Storage::build` fuera de `App\Support\Files` (y de los tests). Sin esa regla, el hueco reaparece.
7. `TenantStorage` (`ADR-033 §9`) se retira junto con su test cuando el contrato exista, o se reduce a un detalle interno de la implementación. Lo que no puede es seguir siendo una segunda vía pública.

La migración de las 16 llamadas es código de negocio de varios módulos: es un paso del plan propio, con su especificación, no parte de la entrega de Garage (§8).

## 4 · Configuración del cliente

| Parámetro | Decisión |
|---|---|
| Región | Explícita en cada entorno (`AWS_DEFAULT_REGION`) e **igual** a la región configurada en el servidor (en Garage, `s3_region` de `garage.toml`). Una discrepancia rompe la firma. |
| Estilo de ruta | `AWS_USE_PATH_STYLE_ENDPOINT=true` en dev/CI/E2E. En producción, lo que exija el proveedor. |
| Sumas de verificación | `request_checksum_calculation = when_required` y `response_checksum_validation = when_required` en el disco `s3` de `config/filesystems.php`, leídas de entorno con ese valor por defecto. Vale para todos los entornos mientras el proveedor no se haya decidido; staging confirma que no rompe con el proveedor. |
| Caducidad de URL firmadas | Corta (`CLAUDE.md §8`): fijada por cada caso de uso, con un **máximo global** en `config/files.php` (propuesta: 15 min; ningún uso actual necesita más, a comprobar al implementar). Las URL se firman en cada respuesta y no se cachean (ya es así). |
| *Endpoint* de firma | **Nuevo**: `AWS_URL_SIGNING_ENDPOINT` (nombre a fijar), opcional. La firma SigV4 incluye la cabecera `Host`; hoy los *specs* reescriben `minio:9000` a `127.0.0.1:9000` **después** de firmar, lo que solo funciona porque el servidor falso no valida. Con Garage esa reescritura invalida la firma. Solución: firmar con un cliente cuyo *endpoint* sea el que alcanza el navegador (en dev, `http://127.0.0.1:3900`; firmar no necesita red), y operar con el *endpoint* interno (`http://garage:3900`). En producción ambos coinciden y la variable queda vacía. Es un cambio de código en `PrivateObjectStore`; se valida en el test de conformidad (§5). |
| *Bucket* | Uno por entorno, con nombre propio (`plataforma-dev`, `plataforma-ci`, `plataforma-e2e`, `plataforma-staging`, …). Nunca un *bucket* compartido entre entornos. |
| Credenciales | Fuera del código y del repositorio: `.env` (no versionado) en desarrollo, secretos de GitHub Actions o generadas en el propio trabajo en CI, gestor de secretos en servidores (`ADR-037`). **Se elimina el valor de reserva `minioadmin` de `compose.yaml`**: si falta la variable, `compose` falla (`${VAR:?…}`). |
| Clave de acceso | Una por entorno, con permiso solo sobre su *bucket*. El aislamiento entre centros es de la aplicación (prefijo), no de IAM: ver §10. |

## 5 · Test de conformidad del almacén

Un conjunto Pest propio (grupo `storage-conformance`), excluido de la suite normal y ejecutado contra un servidor S3 real:

1. Subir, leer y comparar contenido y tamaño; `exists`; `lastModified`.
2. Borrar y comprobar ausencia.
3. Listar un área y comprobar que solo devuelve claves del centro en curso.
4. URL firmada: `GET` sin credenciales → `200` con el contenido; con caducidad corta (p. ej. 2 s) y espera → `403`; firma alterada → `403`; URL de una clave cambiada a mano → `403`.
5. Aislamiento: con dos centros sintéticos, A no puede leer, firmar, listar ni borrar claves de B (`ForeignObjectKeyException`) y una URL firmada para A no sirve para una clave de B.
6. *Endpoint* de firma distinto del de operación (§4), cuando esté configurado.
7. Configuración de sumas de verificación: subida y lectura sin error con `when_required`.

Dónde corre:

- **CI**: un trabajo de `ci-api.yml` arranca Garage con la imagen fijada de §2.2, ejecuta el script de inicialización (§6) y lanza el grupo. Corre en cada PR que toque `apps/api/app/Support/Files`, `config/filesystems.php`, `config/files.php`, `compose.yaml` o `infra/garage/`, y en `develop` siempre (propuesta: a decidir si en todo PR, según su coste medido).
- **Staging**: el mismo grupo contra el proveedor real, al desplegar y tras cualquier cambio de proveedor o configuración. Hasta que exista staging, esta mitad queda pendiente y así se dice.

## 6 · Servicio Garage en `compose.yaml`

- **Servicio** `garage`, imagen de §2.2, `container_name: plataforma-garage`, en `plataforma-net` (red externa, `ADR-028`), puertos solo en `127.0.0.1` (S3 `3900`; administración `3903` solo si el script lo usa).
- **Perfil**: `full`, como hoy MinIO (`ADR-030`: el perfil reducido no lo arranca; el disco local sigue valiendo con `APP_ENV=local`). Los scripts de E2E lo levantan explícitamente.
- **Volumen**: uno, `garage-data`, con `meta/` y `data/` dentro, montado con `:Z`. Se borra y recrea sin pérdida (solo datos sintéticos).
- **Configuración**: `infra/garage/garage.toml` versionado sin secretos, montado de solo lectura. `rpc_secret` y el *token* de administración entran por variable de entorno desde `.env` (Garage admite leerlos de entorno o de fichero; a comprobar con la versión elegida).
- **Inicialización repetible**: `infra/garage/init.sh`, idempotente, ejecutado por `podman exec` (la imagen oficial no trae *shell*, a comprobar; si no lo trae, el script corre en el host e invoca el binario dentro del contenedor):
  1. Espera a que el nodo responda.
  2. Si no hay *layout* aplicado: asigna el nodo (zona `dc1`, capacidad fija) y aplica la versión 1.
  3. Importa la clave de acceso desde `.env` (`key import`, con identificador y secreto fijos para que las variables `AWS_*` no cambien entre recreaciones); si ya existe, no hace nada.
  4. Crea el *bucket* del entorno y concede lectura y escritura a la clave; si ya existen, no hace nada.
  5. Sale con código distinto de cero ante cualquier fallo, con mensaje claro.
- **Salud**: la imagen probablemente no trae `curl`; el *healthcheck* usa el propio binario (`garage status`) o se omite y el script espera activamente.
- **Quadlet**: **no procede**. Garage no se despliega en servidores (§2.2) y staging prueba contra el proveedor real (§2.3). Se retira `infra/quadlet/minio-data.volume` (issue #38). Si `OPEN-STO-01` eligiera algo autoalojado, su unidad Quadlet llegaría con su propio ADR.

## 7 · Inventario de limpieza

Recuento con `grep -w` del 2026-11-11 sobre `minio`, `minio-data`, `minioadmin`, `MINIO_ROOT_*`, `e2e-fake-s3`, `FAKE_S3_DIR` y `E2E_S3_INTERNAL_URL`. Se regenera al implementar y el PR adjunta el `grep` final, que solo puede dar resultados en documentos históricos.

### 7.1 Código, configuración e infraestructura (se elimina o renombra)

| Elemento | Acción |
|---|---|
| `compose.yaml`: servicio `minio` | Se elimina; entra `garage` (§6). |
| `compose.yaml`: volumen `minio-data` | Se elimina; entra `garage-data`. El volumen local existente lo borra cada desarrollador (instrucción en `SYSADMIN.md`). |
| `compose.yaml`: comentario de cabecera y comentarios de `api`/`worker` que citan MinIO | Se reescriben. |
| `compose.yaml`: `AWS_ENDPOINT: "http://minio:9000"`, `AWS_ACCESS_KEY_ID: ${MINIO_ROOT_USER:-minioadmin}`, `AWS_SECRET_ACCESS_KEY: ${MINIO_ROOT_PASSWORD:-minioadmin}`, `AWS_DEFAULT_REGION: us-east-1`, `AWS_BUCKET: plataforma` (en `api` y `worker`) | *Endpoint* `http://garage:3900`; credenciales de `.env` sin valor de reserva; región y *bucket* de §4; nueva variable de firma. |
| `.env.example` (raíz): `MINIO_ROOT_USER`/`MINIO_ROOT_PASSWORD` | Se sustituyen por `GARAGE_RPC_SECRET`, `GARAGE_ADMIN_TOKEN`, clave y secreto de acceso S3 de desarrollo (sin valores). |
| `infra/quadlet/minio-data.volume` | Se elimina (issue #38). |
| `infra/quadlet/plataforma.env.example`: bloque `MinIO / almacenamiento` (`MINIO_ROOT_*`) | Se elimina; el bloque `AWS_*` se conserva y gana las variables de §4. |
| `apps/api/tests/Support/e2e-fake-s3.php` | Se elimina. |
| `apps/api/config/filesystems.php` (disco `s3`) | Añade sumas de verificación y *endpoint* de firma (§4). |
| `apps/api/config/files.php` | Comentario «MinIO en desarrollo» → Garage; añade el máximo de caducidad. |
| `apps/api/phpunit.xml` (comentario de `FILESYSTEM_DISK`) | Comentario: MinIO → Garage. |
| `apps/api/app/Support/Files/PrivateObjectStore.php`, `apps/api/app/Support/Tenancy/TenantStorage.php` (comentarios) | Comentarios: MinIO → Garage (o retirada de `TenantStorage`, §3.3). |
| `apps/web/scripts/e2e-real-api.sh`, `e2e-real-prl.sh`, `landing-shots.sh` | Levantan `garage` y ejecutan `infra/garage/init.sh`; desaparece la alternativa del servidor falso. |
| `apps/web/e2e/prl-door-api.spec.ts`, `prl-door-real.spec.ts`, `fam-unit-pickup-real.spec.ts`, `alum-enrollments-real.spec.ts`, `landing-shots.spec.ts` | `E2E_S3_INTERNAL_URL` y la reescritura `minio:9000` → `127.0.0.1` se eliminan (la URL ya se firma con el *endpoint* público, §4); las búsquedas de la cadena `'minio'` en la página (comprobación de que no se filtra el *host* interno) pasan a `'garage'`. En `alum-enrollments-real.spec.ts`, la rama «URL firmada no alcanzable: contenido NO comprobado» deja de ser aceptable: el CSV se comprueba. |
| `apps/web/src/modules/core/views/UserImportDetailView.vue` (comentario) | MinIO → almacén S3 de desarrollo. |
| `.github/workflows/ci-api.yml` | Nuevo trabajo de conformidad (§5). |

### 7.2 Configuración del proceso (fuera de mi ámbito; la sesión decide)

`CLAUDE.md §1` (fila «Almacenamiento: Compatible S3 (MinIO en desarrollo)»), `.claude/agents/verificador.md` y `.claude/skills/frontend-spa/SKILL.md` citan MinIO o el servidor falso. Hay que actualizarlos, pero son configuración del proceso y no los toco.

### 7.3 Documentos vivos que se actualizan

| Documento | Qué cambia |
|---|---|
| `ARCHITECTURE.md` | Tabla de stack (línea «MinIO en desarrollo»), diagrama (`S3/MinIO`), tabla de recursos y reparto de memoria (MinIO ~512 MB → Garage, cifra a medir). |
| `SYSADMIN.md` | Tabla de servicios (`minio` → `garage`, puertos), variables `AWS_*` y nuevas, inicialización (`infra/garage/init.sh`), borrado del volumen antiguo, nota de E2E de `1.14b-c1` (alternativa del servidor falso). |
| `RUNBOOK.md` | `podman compose --profile full up -d   # añade minio …` → garage. |
| `CONTRIBUTING.md` | Solo si describe el arranque del perfil `full` o el E2E (no aparece en el recuento; a comprobar). |
| `docs/modulos/REQ-PRL/operacion.md` | `§12.7` (verificación E2E contra la API real con servidor falso) y referencias de `§12.1`. |
| `docs/modulos/REQ-PRL/funcional.md` | Dos referencias a MinIO o al servidor falso. |
| `docs/modulos/REQ-FAM-UNIT/operacion.md` | `§12` (variables, servicios externos, despliegue). |
| `docs/modulos/REQ-FAM-UNIT/funcional.md` | Cuatro referencias, entre ellas `§22.1` (imagen de MinIO no descargable). |
| `docs/modulos/REQ-ALUM/operacion.md` | Fila «Almacenamiento S3 (MinIO en desarrollo)». |
| `docs/modulos/REQ-ALUM/funcional.md` | `CA-ALUM-072` (E2E: contenido de la URL firmada). |
| `docs/modulos/REQ-CORE/operacion.md` | Variables (`FILESYSTEM_DISK`, `AWS_*`) y fila «S3 / MinIO». |
| `docs/modulos/REQ-PERM/operacion.md`, `docs/modulos/REQ-AUTH/operacion.md` | Filas «S3 / MinIO» → «Almacenamiento S3» (cambio de rótulo). |
| `apps/landing/README.md` | Requisito del almacén S3 para la captura de marca. |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md §18` | Fila de este ADR. |

### 7.4 Documentos históricos que **no** se tocan

Los ADR (`ADR-030`, `ADR-037`, `ADR-049`, `ADR-061` y cualquier otro que cite MinIO: se enmiendan por la cabecera de este ADR), `CHANGELOG.md` (entradas pasadas), `docs/proceso/memoria-archivo.md`, `docs/historial/*`, las entradas ya cerradas de `memory.md` y de `PLAN-IMPLEMENTACION.md` (paso `0.x` del entorno). La entrada nueva de `CHANGELOG.md` y la nota de `memory.md` se añaden; las antiguas no se reescriben.

## 8 · Consecuencias, riesgos y reversión

**Consecuencias**

- La firma, la caducidad y la región de las URL firmadas se prueban de verdad en CI y E2E por primera vez.
- Desaparece un servidor S3 escrito por nosotros (código de prueba sin pruebas).
- El cambio de servidor queda en `compose.yaml` y variables.
- Se añade un servicio más al perfil `full` (Garage es ligero; la memoria real se mide y se anota en `ARCHITECTURE.md`).
- Se añade un cambio de código no trivial: el *endpoint* de firma (§4). Sin él, el E2E con Garage no funciona.

**Orden de entrega** (cada una ≤3 tramos, `CLAUDE.md §2` punto 7):

1. **Garage + configuración del cliente + conformidad**, partida en dos (decisión al aceptar el ADR: no cabe en tres tramos). Paso **crítico** (toca URL firmadas de datos de menores):
   - **`a`**: servicio `garage` y `infra/garage/init.sh`, sumas de verificación y *endpoint* de firma en `PrivateObjectStore`, test de conformidad (grupo `storage-conformance`) y su trabajo de CI. Antes de escribirlo, un experimento acotado: arrancar la imagen fijada y firmar una URL con caducidad, para comprobar lo que `§10` declara no verificado.
   - **`b`**: scripts y *specs* de E2E (incluido el E2E de foto de `REQ-ALUM`, `1.15-d` T3), retirada de MinIO, del servidor falso y de `minio-data.volume`, y la documentación de `§7`.
2. **Contrato `TenantObjectStore` y migración de las 16 llamadas**, con el test de arquitectura. Paso crítico (`INV-001`). Necesita su especificación.

**Riesgos**

| Riesgo | Mitigación |
|---|---|
| La imagen de Garage deja de estar disponible (el problema de MinIO) | Digest fijado. Si se necesita, réplica privada de la imagen (ver la nota de licencia de §9). |
| Garage acepta algo que el proveedor real rechaza, o al revés | Matriz de §2.3 y conformidad en staging. Verde en CI no es verde en producción para la columna «No». |
| El CI no puede descargar la imagen (antecedente: issue #609, DNS externo) | Mismo tratamiento que el resto de imágenes. El trabajo de conformidad falla de forma visible, no se salta en silencio. |
| `when_required` no vale para el proveedor elegido | Variable de entorno por entorno; staging lo comprueba. |
| Migrar las 16 llamadas cambia una clave y deja objetos huérfanos | El contrato conserva el formato de clave (§3.3, regla 2); test de regresión con claves antiguas. |

**Reversión**: volver a MinIO (u otro emulador S3) es cambiar el servicio y las variables de `compose.yaml` y la imagen del trabajo de CI. El código de aplicación no depende de Garage. El *endpoint* de firma y las sumas de verificación son configuraciones neutras que sirven con cualquier servidor S3. La migración de §3.3 es independiente y no se revierte con esto.

## 9 · Alternativas descartadas

| Alternativa | Motivo |
|---|---|
| Seguir con MinIO (imagen comunitaria) | Repositorio archivado y sin imágenes descargables desde octubre de 2025: no es reproducible. |
| *Fork* comunitario de MinIO | Mantenimiento y continuidad sin acreditar; cambia una dependencia frágil por otra. |
| SeaweedFS | Más piezas (maestro, volumen, *filer*, pasarela S3) para lo mismo en un entorno de pruebas; Garage cubre la superficie S3 que usamos con un solo binario. |
| Mantener el servidor S3 falso | No valida firmas ni caducidad: es justo lo que hay que probar. |
| Azure Blob o Google Cloud Storage con *driver* nativo | Rompe el contrato único S3, duplica la configuración y ata el código a un proveedor sin decidir (`OPEN-STO-01`). |

**Licencia de Garage (AGPLv3)**: se usa sin modificar, como proceso aparte, comunicándose por HTTP en desarrollo y CI. No se enlaza con la aplicación, no se distribuye en las imágenes del producto y no se ofrece como servicio a terceros. La AGPLv3 impone obligaciones al distribuir el programa o al ofrecer una **versión modificada** a usuarios por red (AGPLv3 §13); ninguno de los dos casos se da, así que no afecta al código del proyecto. Una réplica **pública** de la imagen sí sería distribución (habría que ofrecer el código fuente correspondiente): si se replica, en un registro privado. Esta lectura no es asesoramiento legal; si se quisiera certeza formal, va al expediente de cumplimiento.

## 10 · Preguntas abiertas y lo no verificado

**No verificado en esta sesión**

- No se ha descargado ni arrancado ninguna imagen de Garage: ni su registro, ni la versión estable vigente, ni el digest, ni si trae *shell* o `curl`, ni cómo lee los secretos de entorno.
- No se han generado URL prefirmadas con `aws-sdk-php` 3.403.1 contra Garage, ni comprobado que Garage devuelva `403` al caducar o que rechace o ignore las cabeceras de suma de verificación con `when_supported`.
- No se ha comprobado que Laravel pase `request_checksum_calculation`/`response_checksum_validation` del array del disco al `S3Client` (se espera que sí, porque Laravel entrega la configuración del disco al cliente).
- El estado de MinIO (archivado, sin imágenes desde octubre de 2025) viene del estudio previo de esta sesión; no se ha vuelto a comprobar.
- Recuento de llamadas directas: 16 en 15 ficheros (`grep` de hoy) frente a «unas 20» del estudio previo. Puede que el estudio contara tests o `Storage::fake`; no se ha conciliado.

**Preguntas para el usuario**

1. **`OPEN-STO-01` a `-12` no existen en el repositorio**: este ADR los cita como me llegaron. ¿Dónde vive el expediente aparte, y se registran esos identificadores en algún documento antes de aceptar este ADR?
2. **«Interfaz de Core»**: propongo el contrato en `App\Support\Files` (núcleo compartido), no en el módulo `Core`, por `INV-007`. ¿Conforme?
3. **Perfil por defecto**: ¿Garage solo en `full`, como MinIO hasta ahora, o también en el perfil reducido con `FILESYSTEM_DISK=s3` por defecto, para que desarrollo se parezca más a producción? Recomiendo lo primero (reversible y respeta `ADR-030`).
4. **Frecuencia del trabajo de conformidad en CI**: ¿en todo PR o solo cuando cambian las rutas de §5 y en `develop`?
5. **Caducidad máxima** de URL firmadas: propuesta de 15 min. ¿Algún uso la necesita mayor (descarga de exportaciones grandes)?
6. **Aislamiento por IAM**: hoy una sola clave ve el *bucket* entero y el aislamiento entre centros es solo de la aplicación. ¿Lo trata el expediente de cumplimiento (clave o *bucket* por centro en producción) o se acepta así? No lo decide este ADR.
7. **Issue #38**: habla de `infra/quadlet/minio-data.volume`, no del volumen de `compose.yaml`. Este ADR retira ambos; el issue se cierra con la primera entrega.

**Hallazgos fuera de mi ámbito (para issue, no los corrijo)**

- `PurgeExpiredExports` borra `object_key` sin comprobar que cuelgue del prefijo del centro (`INV-001`, defensa en profundidad). Propuesta de severidad: media. Queda resuelto por la entrega 2 de §8.
- `compose.yaml` usa `minioadmin` como credencial de reserva en `api` y `worker`. Severidad baja (solo desarrollo, puerto en loopback). Queda resuelto por la entrega 1.
- `CLAUDE.md §1` describe «MinIO en desarrollo» (§7.2).
