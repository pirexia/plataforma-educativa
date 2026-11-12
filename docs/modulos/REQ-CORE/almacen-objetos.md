# REQ-CORE · Almacén de objetos por centro (`TenantObjectStore`) · Especificación del paso `S-2`

| Campo | Valor |
|-------|-------|
| Código | `REQ-CORE` (núcleo compartido `App\Support\Files`; no es un módulo activable) |
| Paso | `S-2` de `ADR-076 §8` |
| Prioridad | MUST (cierra el issue #612 y es condición de `ADR-077 §5.3`, `OPEN-PURGA-05`) |
| Depende de | `S-1a` y `S-1b` (mezcladas: PR #628 y #629), `ADR-076` (ACEPTADA), `ADR-068 §5.3`, `ADR-033 §9` |
| Coordina con | `ADR-077` (PROPUESTA, purga física de tenant), `ADR-083` (en curso, proveedor S3) |
| Estado | **PROPUESTO** (2026-11-12). No se implementa hasta aprobar y resolver las preguntas bloqueantes de §16 |
| **Clasificación del paso** | **crítico** (`ADR-069 §4.2`; motivo en §15) |

> Fuente de verdad: `docs/adr/ADR-076-almacenamiento-de-objetos-s3-garage.md §3.2`, `§3.3`, `§4` y `§8`; issue #612; `INV-001`, `INV-003`, `INV-007`, `INV-015`; `CLAUDE.md §8` y `§9`. Este documento **no** reabre nada de `ADR-076`: lo concreta para implementarlo. Donde `ADR-076` no alcanza, lo dice y lo deja como `OPEN-CORE-NN` (§16).
>
> Un solo fichero y no los cinco de la plantilla: `S-2` no crea módulo, tabla, *endpoint* ni permiso. Los cinco aspectos de `docs/modulos/_PLANTILLA/` están en §9 (datos, API, permisos, operación) y en el resto del documento (funcional).

---

## 0. Resumen

Hoy conviven dos vías para tocar el *bucket* privado: `PrivateObjectStore` (`App\Support\Files`), que impone el prefijo `tenants/<public_id>/` y rechaza claves ajenas, y **13 llamadas directas** a `Storage::disk(config('filesystems.default'))` en 12 ficheros de tres módulos, que componen la clave a mano y no comprueban nada. `INV-001` depende en esas 13 de la disciplina de cada llamador. `S-2`:

1. Declara el contrato `TenantObjectStore` en `App\Support\Files`, implementado por `PrivateObjectStore`.
2. Migra las 13 llamadas al contrato, sin cambiar ninguna clave ya guardada.
3. Hace que las purgas comprueben el prefijo antes de borrar (issue #612).
4. Añade la regla de arquitectura que impide que el hueco reaparezca.
5. Retira `TenantStorage`, la segunda abstracción que nadie usa.

No cambia ningún *endpoint*, permiso, tabla ni variable de entorno.

## 1. Estado comprobado (2026-11-12, `develop` en `b7995bc`)

Leído en el código, no supuesto. Las cifras de `ADR-076 §3.2` (16 llamadas en 15 ficheros) ya no valen: `S-1b` migró las tres firmas (issue #625).

| Hecho | Dónde |
|---|---|
| `PrivateObjectStore` es `final`, sin interfaz. Operaciones: `put(área, contenido, extensión)`, `temporaryUrl`, `exists`, `get`, `lastModified`, `delete`, `keysIn`. El nombre del objeto es siempre un ULID nuevo: **no admite nombre** | `apps/api/app/Support/Files/PrivateObjectStore.php` |
| Área validada con `^[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*$` (solo minúsculas); extensión `^[a-z0-9]{2,5}$` | Ídem, líneas 30 y 39 |
| El tope de caducidad ya existe: `files.signed_url_max_ttl_minutes`, de `FILES_SIGNED_URL_MAX_TTL_MINUTES`, 15 por defecto; recorta y avisa en el registro sin clave ni URL | Ídem, líneas 61-73; `apps/api/config/files.php:15` |
| Ya usan `PrivateObjectStore` (clase concreta): `BrandingAssetUrlSigner`, `DataExportsController`, `UserImportResource` (Core); `PickupPhotoService`, `PickupListReader`, `PurgeOrphanPickupPhotos` (FamUnit); `StudentPhotoService` (Alum) | 7 ficheros |
| `TenantStorage` solo lo referencian su registro `singleton` y su test | `apps/api/app/Support/Tenancy/TenantStorage.php`, `apps/api/app/Providers/TenancyServiceProvider.php:29`, `apps/api/tests/Feature/Tenancy/TenantStorageTest.php` |
| `Illuminate\Support\Facades\Storage` en `apps/api` fuera de `tests/`: los 12 ficheros de §5, `PrivateObjectStore` y `TenantStorage`. Ninguno en `config/`, `routes/`, `database/` ni `bootstrap/` | Búsqueda de código sobre `b7995bc` |
| `public_id` es `(string) Str::ulid()`: 26 caracteres **en mayúsculas** | `apps/api/app/Support/Database/HasPublicId.php:18` |
| Los valores de `BrandingAssetKind` (`logo`, `favicon`, `login-background`) cumplen el patrón de área | `apps/api/app/Modules/Core/Application/BrandingAssetKind.php` |
| Las reglas de arquitectura llegan a `AR-16`; `AR-17` está libre | Búsqueda de código; tests en `apps/api/tests/Feature/Architecture/` |

**Consecuencia que `ADR-076` no previó.** Su regla 2 de `§3.3` admite un `nombre` opcional «ULID o `public_id`». Eso cubre exportaciones (`exports/<public_id>.csv`) y marca (`branding/<tipo>/<ULID>.<ext>`), pero **no** las importaciones, cuya clave es `imports/<public_id>/source.csv` y `imports/<public_id>/report.csv`: el `public_id` va en un segmento intermedio, en mayúsculas, y el nombre es una palabra fija. Con los patrones actuales esa clave no se puede escribir por el contrato. Es `OPEN-CORE-70` (bloqueante).

## 2. Alcance

**Entra**

- Interfaz `TenantObjectStore` y su enlace en el contenedor a `PrivateObjectStore`.
- Operaciones nuevas que `ADR-076 §3.3` fija y hoy no existen: escritura con nombre, `putStream`, `readStream`, nombre de descarga en `temporaryUrl`.
- Migración de las 13 llamadas de §5 y paso a la interfaz de los 7 consumidores que hoy inyectan la clase concreta.
- Comprobación de prefijo en las tres purgas de Core y en el descarte de una importación (#612).
- Regla de arquitectura `AR-17` (§7).
- Retirada de `TenantStorage`, su registro y su test.
- Ampliación del grupo `storage-conformance` con las operaciones nuevas.
- Documentación viva de módulo afectada (§9.4).

**No entra**

- **Borrado masivo por prefijo** («borrar todo lo de un centro»): es de `ADR-077 §5.6`, con interfaz propia y su propio test de arquitectura. `S-2` solo deja el terreno preparado (§10).
- Objetos de plataforma no ligados a un centro (`ADR-076 §3.1`): hoy no hay ninguno. El libro externo de purgas de `ADR-077 §5.9` será el primero y llegará con su variante de plataforma.
- Cambiar el formato o la ubicación de ninguna clave existente, ni migrar datos.
- Cambiar qué se audita en los flujos migrados (§8).
- Proveedor de producción, versionado, cifrado en servidor, ciclo de vida (`ADR-083`, `OPEN-STO-*`).
- Mejoras de comportamiento de los trabajos migrados (lectura en flujo del CSV, mensajes de error): se migran tal como funcionan. Lo detectado se lista en §18 y no se corrige.

## 3. Contrato `TenantObjectStore`

Interfaz en `App\Support\Files`. La implementa `PrivateObjectStore`; el contenedor resuelve la interfaz a esa clase. Los módulos dependen de la interfaz, nunca de la clase (`RNF-MANT-007`).

### 3.1 Principios (de `ADR-076 §3.3`, reglas 1 a 4)

1. **El prefijo lo pone la implementación.** Ningún método recibe el centro, su `public_id` ni un prefijo. El centro sale de `TenantContext`; sin centro en curso, toda operación falla (falla en cerrado).
2. **Quien escribe no compone claves.** Entrega área, extensión y, si procede, nombre; recibe la clave completa y la guarda tal cual.
3. **Quien lee, firma o borra entrega una clave completa** (la que guardó). La implementación comprueba que cuelga del prefijo del centro en curso antes de tocar el disco.
4. **Nada lista fuera del prefijo.** No existe operación sobre el *bucket* entero ni sobre el prefijo entero de un centro.

### 3.2 Operaciones

| Operación | Entrada | Resultado | Validación |
|---|---|---|---|
| Escribir | área, contenido, extensión, nombre opcional | clave completa | área, extensión y nombre (§3.3). Sin nombre: ULID nuevo |
| Escribir en flujo | área, flujo de lectura, extensión, nombre opcional | clave completa | Las mismas. No carga el contenido entero en memoria |
| Leer | clave | contenido, o «no existe» | prefijo propio |
| Leer en flujo | clave | flujo de lectura, o «no existe» | prefijo propio |
| Existe | clave | sí/no | prefijo propio |
| Última modificación | clave | instante | prefijo propio |
| Borrar | clave | nada. **Idempotente**: borrar un objeto que ya no existe no es error | prefijo propio |
| URL firmada | clave, caducidad, nombre de descarga opcional | URL | prefijo propio; caducidad recortada al tope (§3.5); nombre de descarga (§3.4) |
| Listar un área | área | claves completas de ese nivel | área; solo dentro del prefijo |

Firmas exactas y nombres de método: los de `PrivateObjectStore` hoy (`put`, `get`, `exists`, `lastModified`, `delete`, `temporaryUrl`, `keysIn`) más `putStream` y `readStream`. Los parámetros nuevos son opcionales y van al final: ninguna llamada existente cambia.

### 3.3 Validación de área, nombre y extensión

Depende de `OPEN-CORE-70`. Con la opción recomendada:

- **Área**: segmentos separados por `/`. Cada segmento es una etiqueta en minúsculas (patrón actual) **o** un ULID exacto (26 caracteres del alfabeto de Crockford). Sin segmentos vacíos, sin `.` ni `..`.
- **Nombre**: un ULID exacto o una etiqueta en minúsculas con el patrón de segmento. Nunca contiene `/` ni `.`.
- **Extensión**: patrón actual.
- Cualquier otra cosa se rechaza **antes** de tocar el disco, con error de argumento. La clave nunca contiene datos de una persona (`ADR-068 §5.3`): solo etiquetas fijas e identificadores opacos.

Escribir con un nombre que ya existe **sobrescribe** (es lo que hoy ocurre al reintentar una exportación: `tries = 3` y misma clave).

### 3.4 Nombre de descarga

`ADR-076 §3.3` regla 3 lo incluye en `temporaryUrl`. Ningún llamador lo usa hoy. Se especifica lo mínimo: si se entrega, la URL firmada hace que el navegador descargue con ese nombre; se rechaza un nombre con saltos de línea, comillas, `/`, `\` o caracteres de control (inyección de cabecera). El nombre lo entrega el llamador ya saneado y sin datos personales salvo que su propia especificación lo justifique. Ver `OPEN-CORE-75`.

### 3.5 Caducidad de las URL firmadas

- Cada caso de uso fija la suya. El contrato la **recorta** al tope global (`files.signed_url_max_ttl_minutes`, 15 minutos, `ADR-076 §4`) y nunca la amplía.
- El recorte deja un aviso en el registro con el tope aplicado, sin clave y sin URL (ya es así).
- La URL se genera en cada respuesta y no se guarda ni se cachea.
- Hoy el tope es una variable de entorno sin límite superior: un despliegue puede subirlo a cualquier valor. Ver `OPEN-CORE-73`.

### 3.6 Errores

| Situación | Resultado |
|---|---|
| Sin centro en curso | Error de ejecución; no se toca el disco |
| Clave que no cuelga del prefijo del centro en curso, que contiene `..` o que es solo el prefijo | `ForeignObjectKeyException`; no se toca el disco |
| Área, nombre, extensión o nombre de descarga no válidos | Error de argumento; no se toca el disco |
| Disco `local` en `production` o `staging` | Error de ejecución (ya es así) |
| El disco rechaza la escritura | Error de ejecución (ya es así) |

Ninguno de estos errores incluye la clave ni la URL en su mensaje ni en el registro.

## 4. Formato de claves (no cambia)

| Uso | Clave bajo `tenants/<public_id del centro>/` | Columna que la guarda | Con el contrato |
|---|---|---|---|
| Exportaciones (usuarios, auditoría, matrículas, consentimientos de imagen) | `exports/<public_id de la exportación>.csv` | `data_exports.object_key` | área `exports`, nombre = `public_id` |
| Activos de marca | `branding/<logo\|favicon\|login-background>/<ULID>.<ext>` | `tenant_settings.{logo,favicon,login_background}_object_key` | área `branding/<tipo>`, sin nombre |
| Fichero fuente de importación | `imports/<public_id de la importación>/source.csv` | `user_imports.source_object_key` | `OPEN-CORE-70` |
| Informe de importación | `imports/<public_id de la importación>/report.csv` | `user_imports.report_object_key` | `OPEN-CORE-70` |
| Fotografías de recogida y de alumnado | área propia de cada módulo, `<ULID>.<ext>` | sus tablas | Ya van por el almacén; no cambian |

Las claves ya guardadas siguen resolviendo: lectura, firma y borrado reciben la clave completa y solo comprueban el prefijo.

## 5. Inventario de llamadas directas y orden de migración

Lista exacta de `Storage::disk(config(` en `apps/api/app` fuera de `App\Support\Files`: **13 llamadas en 12 ficheros** (rutas relativas a `apps/api/app/Modules/`). «Línea» es la de la llamada a `Storage`; entre paréntesis, dónde se compone la clave o se opera.

### 5.1 Grupo A: leen o borran una clave guardada (no necesitan nada nuevo del contrato)

| Orden | Fichero y línea | Operación | Origen de la clave | Riesgo | Por qué |
|---|---|---|---|---|---|
| 1 | `Core/Infrastructure/Jobs/PurgeExpiredExports.php:33` (borra en `:40`) | `delete` | `data_exports.object_key` | **Alto** | Es el issue #612: borra lo que diga la fila. Además hace `forceDelete` de la fila: decidir qué pasa con una clave ajena es `OPEN-CORE-71` |
| 2 | `Core/Infrastructure/Jobs/PurgeImportArtifacts.php:34` (`:46`) | `delete` ×2 | `user_imports.source_object_key`, `report_object_key` | **Alto** | Mismo patrón que #612. Los objetos llevan datos personales de todo el personal (`RN-CORE-21`) |
| 3 | `Core/Infrastructure/Jobs/PurgeOrphanBrandingAssets.php:50` (`:54`, `:56`, `:61`, `:62`) | `files`, `lastModified`, `delete` | prefijo compuesto a mano con `Tenant::find($this->tenantId)` | **Medio** | Lista un directorio compuesto con el `tenantId` del constructor, no con el contexto. Pasa a listar el área `branding/<tipo>`. El criterio de huérfano (no referenciado y más de 24 h) no cambia |
| 4 | `Core/Http/Controllers/UserImportsController.php:89` (`:93`) | `delete` ×2 | columnas de `user_imports` | **Medio** | Única llamada en una petición HTTP. Una clave ajena aquí acaba en respuesta de error: `OPEN-CORE-71` |
| 5 | `Core/Infrastructure/Jobs/ValidateUserImport.php:89` | `get` | `user_imports.source_object_key` | **Bajo** | Lectura. Clave ajena: el trabajo falla y `failed()` deja el lote en `fallido` (comportamiento ya existente ante cualquier excepción) |
| 6 | `Core/Infrastructure/Jobs/ExecuteUserImport.php:69` | `get` | `user_imports.source_object_key` | **Medio** | Lectura, pero con 3 reintentos y creación de usuarios detrás: comprobar que una clave ajena no deja el lote en `ejecutando` para siempre |

### 5.2 Grupo B: escriben componiendo la clave (necesitan escritura con nombre)

| Orden | Fichero y línea | Operación | Cómo compone hoy la clave | Riesgo | Por qué |
|---|---|---|---|---|---|
| 7 | `Core/Infrastructure/Jobs/GenerateUserExport.php:104` (clave `:103`, escribe `:146`) | `put` | `$this->tenantPublicId` del constructor | **Medio** | El `public_id` del centro viaja serializado en el trabajo; quien despacha puede pasar cadena vacía (§18) |
| 8 | `Core/Infrastructure/Jobs/GenerateAuditLogExport.php:92` (`:91`, `:119`) | `put` | Ídem | **Medio** | Ídem |
| 9 | `Alum/Infrastructure/Jobs/GenerateEnrollmentsExport.php:106` (`:105`) | `put` | Ídem, con guarda de cadena vacía en el constructor | **Bajo** | Ya falla en cerrado; contiene datos de menores |
| 10 | `FamUnit/Infrastructure/Jobs/GenerateImageConsentsExport.php:102` (`:101`) | `put` | Ídem, sin guarda | **Medio** | Datos de menores; sin guarda de cadena vacía |
| 11 | `Core/Application/UploadBrandingAsset.php:90` (clave `:82-88`) | `put` | `sprintf` con `$tenantPublicId` recibido como parámetro | **Medio** | El parámetro deja de hacer falta; lo pasa un controlador. Extensión de reserva `bin` (3 caracteres, válida) |
| 12 | `Core/Infrastructure/EloquentBulkUserImporter.php:53` (clave `:51`) | `put` | `Tenant::findOrFail(contexto)` | **Alto** | Formato `imports/<public_id>/source.csv`: depende de `OPEN-CORE-70` |
| 13 | `Core/Infrastructure/Jobs/ValidateUserImport.php:179` (clave `:150`) | `put` | `$this->tenantPublicId` del constructor | **Alto** | Formato `imports/<public_id>/report.csv`: depende de `OPEN-CORE-70` |

### 5.3 Motivo del orden

1. **Primero lo que cierra #612** (1 y 2): es la única incidencia abierta y no necesita ampliar el contrato, porque `delete` con comprobación de prefijo ya existe en `PrivateObjectStore`.
2. **Después el resto del grupo A**: mismo motivo; cada uno es un cambio pequeño y verificable con los tests que ya existen (`CorePurgeJobsTest`, `UserImportsEndpointsTest`).
3. **El grupo B detrás**, cuando exista la escritura con nombre; dentro de él, las exportaciones antes (formato cubierto por `ADR-076`) y las importaciones al final (dependen de `OPEN-CORE-70`).
4. **`AR-17` al final**: no puede estar en verde hasta que no quede ninguna llamada.

### 5.4 Trabajos encolados por la versión anterior (`CLAUDE.md §9`)

Seis trabajos llevan `tenantPublicId` en su constructor y, por tanto, en el *payload* serializado (las cuatro exportaciones, `ValidateUserImport` y `ExecuteUserImport`). **La propiedad no se elimina en esta entrega**: un *worker* nuevo debe poder procesar un trabajo encolado por la versión anterior. Deja de usarse para componer la clave. Qué se hace con ella es `OPEN-CORE-72`.

## 6. Purga con comprobación de prefijo (issue #612)

Afecta a `PurgeExpiredExports`, `PurgeImportArtifacts`, `PurgeOrphanBrandingAssets` y al descarte de una importación en `UserImportsController`.

1. **El centro sale del contexto, no de un parámetro.** Los tres trabajos reciben `tenantId` y hoy confían en que el contexto que fija el mecanismo de colas coincide. Pasan a **comprobarlo** al empezar, como ya hace `PurgeOrphanPickupPhotos`: si no hay centro en curso o no es el esperado, el trabajo falla sin borrar nada.
2. **Todo borrado pasa por el contrato**, que rechaza una clave que no cuelgue del prefijo del centro en curso. El objeto ajeno **no se borra nunca**.
3. **Ningún trabajo de purga lista el *bucket* ni el prefijo entero del centro** (`ADR-076 §3.3` regla 5): `PurgeOrphanBrandingAssets` lista las tres áreas `branding/<tipo>`; las otras dos no listan.
4. **Qué pasa con la fila cuya clave es ajena** es `OPEN-CORE-71`. Con la opción recomendada: la fila no se borra ni se modifica, se deja constancia en el registro de errores (tipo de registro y `public_id`, **sin la clave**), el trabajo sigue con las demás filas y termina **fallando**, para que quede en `failed_jobs` y alguien lo vea. En el descarte por HTTP: la importación no se descarta y la respuesta es un error de servidor genérico con `request_id`.
5. **El orden objeto → fila no cambia**: primero se borra el objeto, después la fila (o se anulan las columnas). Si el borrado del objeto falla, la fila se conserva y se reintenta en la siguiente pasada.
6. **Borrar un objeto que ya no existe no es un fallo**: la fila se purga igual.

## 7. Regla de arquitectura `AR-17`

Test de Pest del grupo `arch`, en `apps/api/tests/Feature/Architecture/`, con el analizador que ya usan las demás reglas. Número `AR-17`: siguiente libre a 2026-11-12; lo confirma la orquestadora, porque otras entregas de la ola pueden pedir número.

**Prohíbe**, en todo `apps/api/app` salvo el espacio `App\Support\Files`:

1. Importar o usar `Illuminate\Support\Facades\Storage`, con cualquier método (`disk`, `build`, `fake`, `drive`, `cloud` u otro) y también por su alias global.
2. Con la opción recomendada de `OPEN-CORE-74`, las demás puertas al mismo disco: `Illuminate\Contracts\Filesystem\*`, `Illuminate\Filesystem\FilesystemManager` y `FilesystemAdapter`, `League\Flysystem\*`, `Aws\S3\*` y `app('filesystem')`.
3. Nombrar la clase concreta `PrivateObjectStore` fuera de `App\Support\Files` y de `App\Providers` (el enlace): los módulos inyectan `TenantObjectStore`.
4. Componer un literal que empiece por `tenants/` fuera de `App\Support\Files`.

**Lista de excepciones: vacía y cerrada.** Solo se amplía con especificación aprobada por el usuario, como las demás (`ADR-056`). `tests/` queda fuera del alcance de la regla (`ADR-076 §3.3` regla 6): los tests siguen usando `Storage::fake`.

La regla tiene su propio test de que detecta: un fichero de muestra con cada una de las formas prohibidas debe hacerla fallar (patrón de `PhpScannerTest`).

`TenantStorage` vive en `App\Support\Tenancy` y usa `Storage::build`: o se retira (§2) o `AR-17` falla. No hay tercera opción.

## 8. Auditoría (`INV-003`) y registro

- **Un objeto no es una entidad auditable.** El hecho auditable es el cambio de la fila que lo referencia (`data_exports`, `user_imports`, `tenant_settings`), y ya se audita por los modelos (`Auditable`, `ADR-035`). `S-2` **no cambia** qué eventos de modelo se disparan ni con qué actor: las purgas siguen borrando y actualizando **por instancia** (`AR-15`).
- **Criterio verificable**: los registros de `audit_logs` que producen los flujos migrados son los mismos antes y después (mismos `event`, `auditable_type` y actor). Lo comprueban los tests existentes de cada flujo, que deben seguir en verde sin tocar sus aserciones de auditoría.
- **Lectura**: firmar una URL no es una lectura auditada por el almacén. La auditoría de lectura de datos sensibles (fotografías de menores, consentimientos) es de cada *endpoint* y ya está especificada en su módulo; `S-2` no la toca.
- **Clave ajena rechazada**: es una anomalía de integridad, no una acción de usuario. Con la opción recomendada de `OPEN-CORE-71` va al registro de errores de la aplicación, no a `audit_logs`: añadir un evento al vocabulario de auditoría es una decisión del tipo de `ADR-039` que nadie ha pedido.
- **Nunca se registran** claves, URL firmadas ni contenido (`CLAUDE.md §8`).

## 9. Datos, API, permisos y operación

### 9.1 Datos

Sin migraciones. Sin tablas, columnas ni índices nuevos. Las columnas `*_object_key` conservan tipo (`text`) y contenido. No hay `academic_year_id` implicado. `db-reviewer` no interviene salvo que una pregunta abierta acabe exigiendo esquema.

### 9.2 API

Ningún *endpoint* nuevo ni modificado en contrato (`ADR-038`): mismas rutas, verbos, cuerpos y códigos. Las URL firmadas de `download_url`, `report_url` y de los activos de marca siguen igual. Único caso nuevo de error, y solo ante datos corruptos: `DELETE /user-imports/{public_id}` con una clave ajena en la fila (§6.4). OpenAPI no cambia.

### 9.3 Permisos

Ningún permiso, recurso, acción ni ámbito nuevo o modificado. El almacén no decide quién puede: lo deciden los *endpoints* y trabajos que lo llaman, que conservan sus comprobaciones (`INV-002`). El almacén añade una barrera distinta, la del centro (`INV-001`), y no sustituye a la de permiso.

| Recurso | Acción | Ámbito | Cambio en `S-2` |
|---|---|---|---|
| Todos los de los flujos migrados (`usuario.exportar`, `auditoria.exportar`, `matricula.exportar`, `consentimiento_imagen.exportar`, importación de usuarios, configuración del centro) | las actuales | los actuales | Ninguno |

### 9.4 Operación

| Aspecto | Valor |
|---|---|
| Variables de entorno | Ninguna nueva. `FILES_SIGNED_URL_MAX_TTL_MINUTES` ya existe (`S-1a`); puede cambiar de semántica según `OPEN-CORE-73` |
| Colas | Sin cambios: `core-maintenance`, `core-imports`, `core-exports`, `default` |
| Tareas programadas | Sin cambios |
| Despliegue | Sin migraciones. Los *workers* nuevos procesan trabajos encolados por la versión anterior (§5.4): criterio `CA-STO-17` |
| Reversión | Revertir el PR. No hay esquema ni datos que deshacer: las claves escritas por la versión nueva tienen el mismo formato que las antiguas |
| Dependencias | Ninguna nueva |
| Entornos | Garage en desarrollo, CI y E2E (`ADR-076 §2.2`). Nada de `S-2` depende de la columna «No» de `ADR-076 §2.3` |
| Documentación de módulo que se actualiza | `REQ-CORE/operacion.md` (almacenamiento), `REQ-CORE/datos.md` (referencias a claves compuestas a mano, si las hay), `REQ-ALUM/operacion.md` y `REQ-FAM-UNIT/operacion.md` (mención del almacén), y un enlace a este documento desde `REQ-CORE/funcional.md` |

## 10. Coordinación con `ADR-077` (purga física de tenant, PROPUESTA)

`ADR-077 §5.3` y `§5.6` borrarán por el prefijo `tenants/<public_id>/`. Lo que `S-2` hace y deja de hacer para no estorbar ni adelantarse:

| `ADR-077` exige | Qué hace `S-2` |
|---|---|
| El borrado por prefijo va en una interfaz **separada** de `TenantObjectStore` (`§5.6`) | El contrato **no** tiene borrado masivo ni listado del prefijo entero (`RN-STO-04`). `S-2` no crea esa interfaz |
| Esa interfaz vive en `App\Support\Files` y compone el prefijo a partir del `public_id` de la lápida | El formato del prefijo se define en **un solo punto** dentro de `App\Support\Files`, que recibe un `public_id` y devuelve el prefijo; `PrivateObjectStore` lo usa con el centro del contexto. La purga podrá usarlo con el `public_id` de la lápida, sin `TenantContext` (el centro estará `eliminado`) y sin copiar el formato (`RN-STO-09`) |
| Ningún `Storage` fuera del almacén (`§5.3`, garantía de S3) | `AR-17`. Su espacio permitido es `App\Support\Files` entero, así que la futura interfaz de purga cabe sin ampliar excepciones |
| `OPEN-PURGA-05`: ¿escriben siempre bajo el prefijo las llamadas directas? | **Respondido por el inventario de §5**: las 13 componen bajo `tenants/<public_id>/`. Matiz: cuatro escritoras toman el `public_id` de un argumento que quien despacha puede dejar vacío (§18), lo que daría `tenants//…`, fuera del prefijo de cualquier centro. Tras `S-2` es imposible. Si existe algún objeto así en un *bucket* real no se puede saber desde el código: `OPEN-CORE-76` |
| Aborto de subidas multiparte incompletas bajo el prefijo (`§5.4` fase 4) | `putStream` puede iniciar subidas multiparte según el tamaño (comportamiento de la librería, sin verificar con Garage). Se anota aquí para que la especificación de `1.23b` lo pruebe |
| Copias por centro y cualquier fichero futuro, bajo el prefijo del centro (`§5.6`) | Es lo único que el contrato permite escribir |
| Un trabajo no debe poder escribir en un centro `eliminado` (`§5.4`, sin verificar) | Fuera de `S-2`: el almacén sigue al `TenantContext`. Se señala a `1.23b` |

`ADR-077` está en PROPUESTA: si cambia su `§5.6`, se revisa esta tabla, no el contrato.

## 11. Reglas de negocio

| ID | Regla |
|---|---|
| `RN-STO-01` | Todo objeto de un centro se escribe, lee, firma, lista y borra por `TenantObjectStore`. Ningún módulo obtiene un disco ni compone `tenants/…` |
| `RN-STO-02` | El prefijo `tenants/<public_id>/` lo impone la implementación con el centro de `TenantContext`. Sin centro en curso, toda operación falla |
| `RN-STO-03` | Leer, existir, fechar, firmar y borrar rechazan con `ForeignObjectKeyException` una clave que no cuelgue del prefijo del centro en curso, antes de tocar el disco |
| `RN-STO-04` | El contrato no ofrece borrado masivo ni listado fuera de un área |
| `RN-STO-05` | Área, nombre y extensión se validan contra patrones cerrados; la clave no contiene datos personales |
| `RN-STO-06` | Las claves ya guardadas siguen siendo válidas; `S-2` no cambia el formato de ninguna |
| `RN-STO-07` | La caducidad de una URL firmada nunca supera el tope global de 15 minutos; la URL no se cachea ni se registra |
| `RN-STO-08` | Una purga comprueba que su contexto es el del centro que debía purgar y no borra ningún objeto cuya clave sea ajena |
| `RN-STO-09` | El formato del prefijo de centro se define en un único punto de `App\Support\Files` |
| `RN-STO-10` | Fuera de `App\Support\Files` no se usa `Storage` ni la clase concreta del almacén (`AR-17`), con lista de excepciones vacía y cerrada |
| `RN-STO-11` | `S-2` no altera los registros de auditoría de los flujos migrados |
| `RN-STO-12` | Un trabajo encolado por la versión anterior se procesa correctamente con la nueva |

## 12. Casos límite y errores

| Caso | Comportamiento |
|---|---|
| Trabajo de exportación reintentado | Escribe la misma clave y sobrescribe; una sola fila, un solo objeto |
| Exportación cuyo objeto ya no existe al pedir la descarga | Como hoy: se firma la URL y el almacén responde que no existe al descargar. `S-2` no añade comprobación de existencia |
| `source_object_key` nulo al validar o ejecutar (la subida falló tras crear la fila) | Hoy se pasa una clave nula al disco (resultado no comprobado); el contrato exige una cadena. El trabajo debe dejar el lote en `fallido` sin leer nada. Es el cambio mínimo que la migración obliga a decidir, no una mejora |
| Clave con `..`, con otro centro o igual al prefijo | `ForeignObjectKeyException` |
| Dos centros con una exportación del mismo `public_id` (imposible por unicidad, se prueba igual) | Claves distintas por el prefijo |
| Trabajo de purga despachado sin centro en curso | Falla al empezar; no borra |
| Subida de marca con tipo real no reconocido | Como hoy: extensión `bin` |
| Fichero de importación de 10 MB | Como hoy: se carga en memoria. `putStream` existe, pero cambiar a flujo no es de esta entrega (§18) |
| Disco local en desarrollo (perfil reducido, `ADR-030`) | Todo funciona igual: el contrato no depende de S3 |

## 13. Criterios de aceptación

Cada uno tiene al menos un test que cita su ID (`INV-015`). «Centro A» y «centro B» son dos centros sintéticos (`REQ-SEED-005`).

**Contrato**

- **`CA-STO-01`** · *Dado* el contenedor de la aplicación, *cuando* se resuelve `TenantObjectStore`, *entonces* se obtiene `PrivateObjectStore`.
- **`CA-STO-02`** · *Dado* el centro A en curso, *cuando* se escribe en el área `exports` con nombre `N` y extensión `csv`, *entonces* la clave devuelta es exactamente `tenants/<public_id de A>/exports/N.csv` y el objeto existe con ese contenido.
- **`CA-STO-03`** · *Dado* el centro A en curso, *cuando* se escribe sin nombre, *entonces* el nombre es un ULID nuevo y dos escrituras dan claves distintas.
- **`CA-STO-04`** · *Dado* ningún centro en curso, *cuando* se invoca cualquier operación del contrato, *entonces* falla y el disco no recibe ninguna llamada.
- **`CA-STO-05`** · *Dado* el centro A en curso y una clave del centro B, *cuando* se intenta leer, leer en flujo, comprobar existencia, fechar, firmar o borrar, *entonces* cada operación lanza `ForeignObjectKeyException` y el objeto de B sigue intacto. Igual con una clave que contiene `..` y con el prefijo solo.
- **`CA-STO-06`** · *Dado* un área, nombre o extensión fuera de patrón (mayúsculas no admitidas, `/` en el nombre, `..`, vacío), *cuando* se escribe o se lista, *entonces* se rechaza con error de argumento y no se escribe nada.
- **`CA-STO-07`** · *Dado* el centro A en curso con objetos en `branding/logo` y el centro B con objetos en la misma área, *cuando* A lista `branding/logo`, *entonces* solo recibe claves que empiezan por su prefijo.
- **`CA-STO-08`** · *Dado* un contenido escrito en flujo, *cuando* se lee en flujo, *entonces* los bytes y el tamaño coinciden. En el grupo `storage-conformance`, contra Garage.
- **`CA-STO-09`** · *Dado* una caducidad pedida de 60 minutos, *cuando* se firma una URL, *entonces* la URL caduca como mucho a los 15 minutos y el aviso del registro no contiene la clave ni la URL. *Dado* una de 5 minutos, *entonces* no se modifica.
- **`CA-STO-10`** · *Dado* un nombre de descarga válido, *cuando* se firma y se descarga contra Garage, *entonces* la respuesta indica ese nombre de fichero. *Dado* uno con salto de línea, comillas o `/`, *entonces* se rechaza. (Grupo `storage-conformance`; depende de `OPEN-CORE-75`.)

**Claves existentes**

- **`CA-STO-11`** · *Dado* un objeto escrito con cada uno de los formatos de §4 tal como los genera el código anterior a `S-2`, *cuando* se lee, se firma y se borra por el contrato, *entonces* las tres operaciones funcionan sin migrar ningún dato.
- **`CA-STO-12`** · *Dado* cada flujo de escritura migrado (cuatro exportaciones, marca, fuente e informe de importación), *cuando* se ejecuta, *entonces* la clave guardada en su columna tiene el mismo formato que antes de `S-2` (§4).

**Purga (#612)**

- **`CA-STO-13`** · *Dado* una exportación caducada del centro A cuya `object_key` apunta a un objeto del centro B, *cuando* se ejecuta `PurgeExpiredExports` de A, *entonces* el objeto de B sigue existiendo y el resto del comportamiento es el de la opción elegida en `OPEN-CORE-71`. Es el test de regresión del issue #612.
- **`CA-STO-14`** · Lo mismo para `PurgeImportArtifacts` (ambas columnas) y para `DELETE /user-imports/{public_id}`.
- **`CA-STO-15`** · *Dado* un trabajo de purga creado para el centro A, *cuando* se ejecuta con el centro B en curso o sin centro, *entonces* falla sin borrar ningún objeto ni fila.
- **`CA-STO-16`** · *Dado* activos de marca huérfanos de más de 24 horas en A y en B, *cuando* se ejecuta `PurgeOrphanBrandingAssets` de A, *entonces* se borran los de A, se conservan los referenciados y los de menos de 24 horas, y no se toca ninguno de B.

**Compatibilidad y auditoría**

- **`CA-STO-17`** · *Dado* un trabajo de cada una de las seis clases de §5.4 serializado con la forma actual de su constructor, *cuando* lo procesa el código nuevo, *entonces* termina correctamente y escribe bajo el prefijo del centro en curso.
- **`CA-STO-18`** · *Dado* los tests existentes de los flujos migrados, *cuando* se ejecutan tras `S-2` sin modificar sus aserciones de auditoría, *entonces* siguen en verde (`RN-STO-11`).

**Arquitectura**

- **`CA-STO-19`** · *Dado* el código de `apps/api/app`, *cuando* se ejecuta `AR-17`, *entonces* no hay ninguna de las formas prohibidas de §7 fuera de `App\Support\Files`, con la lista de excepciones vacía.
- **`CA-STO-20`** · *Dado* un fichero de muestra con cada forma prohibida, *cuando* lo analiza `AR-17`, *entonces* la detecta.
- **`CA-STO-21`** · *Dado* el repositorio tras `S-2`, *entonces* no existen `TenantStorage`, su registro ni su test, y ninguna referencia viva a ellos.

**Aislamiento entre centros (`INV-001`)**

- **`CA-STO-22`** · *Dado* los centros A y B con exportaciones, importaciones y activos de marca equivalentes, *cuando* cada flujo migrado se ejecuta para A (HTTP y cola), *entonces* ningún objeto de B se lee, firma, lista, sobrescribe ni borra, y el listado del prefijo de B es idéntico antes y después.

**No aplican**: acceso denegado por ámbito en listado y detalle (no hay recurso ni *endpoint* nuevo) y lectura de curso cerrado (no hay datos por curso).

**E2E contra la API real** (`CLAUDE.md §3`; lo ejecuta `verificador`): los *specs* existentes que pasan por exportación con descarga del CSV, importación de usuarios con informe, activo de marca y fotografías deben seguir en verde contra Garage sin modificarse. No se escribe *spec* nuevo: `S-2` no cambia comportamiento visible. No se prueba el S3 del proveedor (`ADR-076 §2.3`).

## 14. Plan de tramos (`CLAUDE.md §2`, punto 7)

Tres tramos secuenciales de `implementer`, cada uno con su commit `Verificado`.

| Tramo | Contenido | Criterios | Tests afectados |
|---|---|---|---|
| **T1 · Contrato y purgas** | Interfaz y enlace; punto único del prefijo (`RN-STO-09`); migración del grupo A (órdenes 1 a 6); comprobación de contexto en las purgas; regresión de #612 | `CA-STO-01`, `-04`, `-05`, `-07`, `-11`, `-13` a `-16` | `PrivateObjectStoreTest`, `CorePurgeJobsTest`, `UserImportsEndpointsTest` |
| **T2 · Escritura con nombre y escritoras** | Nombre opcional, `putStream`, `readStream`, nombre de descarga; migración del grupo B (órdenes 7 a 13); compatibilidad de trabajos encolados | `CA-STO-02`, `-03`, `-06`, `-09`, `-10`, `-12`, `-17`, `-18`, `-22` | Tests de exportación de Core, Alum y FamUnit, `BrandingAssetsEndpointsTest`, `UserImportsEndpointsTest` |
| **T3 · Cierre** | Los 7 consumidores pasan a la interfaz; retirada de `TenantStorage`; `AR-17` y su test de detección; ampliación de `storage-conformance`; documentación de §9.4 | `CA-STO-08`, `-19`, `-20`, `-21` | Grupo `arch`, `storage-conformance` |

Después, fuera de los tramos: suite completa una vez, E2E real por `verificador`, Codex, `security-reviewer` (paso crítico) y `doc-precheck`/`doc-reviewer`. Sin `db-reviewer` (no hay migraciones).

Estimación: T1 y T2 de tamaño medio, T3 pequeño. **Sin medir**: no hay tramo comparable de refactor transversal en `docs/metricas-agentes.md`. Si `OPEN-CORE-70` se resuelve con la opción C (cambiar formato de importaciones) o `OPEN-CORE-74` con la lista amplia y aparecen usos no previstos, T2 o T3 pueden crecer; aun así caben en tres.

## 15. Clasificación del paso

**Clasificación del paso: crítico** (`ADR-069 §4.2`; `ADR-076 §8` ya lo declara así).

- **Ficheros**: es el almacén de todos los ficheros privados.
- **Aislamiento entre centros** (`INV-001`): cambia el mecanismo que impide escribir, leer o borrar en el prefijo de otro centro.
- **Datos personales de menores y de terceros**: por él pasan las exportaciones de matrículas y de consentimientos de imagen, los CSV de personal y las fotografías.
- **Borrado**: toca tres purgas y un `forceDelete`.

No toca migraciones, RLS, `GRANT`, autenticación, permisos ni dinero. La sesión lo comprueba contra el diff.

## 16. Preguntas abiertas

Numeración: se usa el bloque `OPEN-CORE-70` a `-76`. El último identificador localizado en el repositorio es `OPEN-CORE-56`; `REQ-CORE/funcional.md` (461 kB) no se pudo recorrer entero en esta sesión, así que se deja hueco para no colisionar. La orquestadora renumera si procede.

### 16.1 Bloquean la implementación

| ID | Pregunta | Opciones | Recomendación e impacto | Decide |
|---|---|---|---|---|
| `OPEN-CORE-70` | Las claves de importación (`imports/<public_id>/source.csv`, `…/report.csv`) no caben en la regla 2 de `ADR-076 §3.3`: el `public_id` va en mayúsculas en un segmento del área y el nombre es una palabra fija. ¿Cómo se escriben por el contrato? | **A.** El área admite segmentos ULID y el nombre admite una etiqueta en minúsculas además de un ULID. **B.** Parámetro propio «subcarpeta de entidad» (un ULID) entre el área y el nombre. **C.** Formato nuevo para las importaciones nuevas (`imports/<public_id>-source.csv`); las antiguas siguen leyéndose por su clave | **A (Recomendada)**: conserva el formato actual al pie de la letra, sin parámetro nuevo; la validación sigue siendo cerrada (etiqueta o ULID exacto, nada más). B es equivalente con una firma más larga. C contradice «el formato de clave actual no cambia» y deja dos formatos vivos. Bloquea T2 (órdenes 12 y 13) y la validación de §3.3 | Usuario (precisa `ADR-076 §3.3`; con C haría falta enmendar el ADR) |
| `OPEN-CORE-71` | Una purga o un descarte encuentra una fila cuya clave no es del centro. El objeto no se borra en ningún caso. ¿Qué pasa con la fila y con el trabajo? | **A.** Fila intacta, error en el registro (sin la clave), el trabajo sigue con las demás y termina fallando; en HTTP, error de servidor genérico. **B.** Igual, pero el trabajo termina bien (solo registro). **C.** Se purga la fila (o se anulan sus columnas) sin borrar el objeto, con registro | **A (Recomendada)**: una clave ajena es un fallo de integridad que alguien debe ver; `failed_jobs` lo hace visible sin inventar un evento de auditoría. Con B pasa inadvertido. Con C se destruye la única prueba de la anomalía. Coste de A: el trabajo falla en cada pasada hasta que se corrige la fila. Bloquea T1 (`CA-STO-13`, `-14`) | Usuario, con `security-reviewer` |
| `OPEN-CORE-72` | Los seis trabajos llevan `tenantPublicId` serializado. Tras `S-2` no hace falta para componer la clave. ¿Qué se hace con él? | **A.** Se conserva la propiedad y se compara con el centro en curso: si no coincide (o viene vacío), el trabajo falla. **B.** Se conserva y se ignora. **C.** Se elimina ya | **A (Recomendada)**: comprobación gratuita de que el trabajo corre en el centro que lo pidió, mismo criterio que `RN-STO-08`. B es lo mínimo compatible. **C no es admisible** en esta entrega (`CLAUDE.md §9`: trabajos encolados por la versión anterior); la retirada sería una entrega posterior. Ojo con A: un trabajo ya encolado con cadena vacía fallaría; hoy escribiría en `tenants//…`, que es peor. Bloquea T2 (`CA-STO-17`) | Usuario |

### 16.2 No bloquean

| ID | Pregunta | Opciones | Recomendación e impacto | Decide |
|---|---|---|---|---|
| `OPEN-CORE-73` | El tope de caducidad es `FILES_SIGNED_URL_MAX_TTL_MINUTES` sin límite superior: un entorno puede fijar cualquier valor. `ADR-076 §4` propone 15 minutos y su pregunta 5 sigue abierta | **A.** Techo de 15 minutos en código; la variable solo puede bajarlo. **B.** Dejarlo como está. **C.** Quitar la variable y fijar 15 | **A (Recomendada)**: un error de configuración no puede alargar el acceso a ficheros de menores. Si un uso futuro necesita más, se cambia con decisión expresa. Sin respuesta se implementa B (estado actual) | Usuario |
| `OPEN-CORE-74` | ¿`AR-17` prohíbe solo la fachada `Storage` (literal de `ADR-076 §3.3` regla 6) o también las demás puertas al disco (contratos de `Filesystem`, `FilesystemManager`, Flysystem, cliente S3, `app('filesystem')`), la clase concreta y los literales `tenants/`? | **A.** Lista amplia de §7. **B.** Solo la fachada. **C.** Fachada y clase concreta | **A (Recomendada)**: sin ella el hueco reaparece por inyección del contrato de Laravel, que es la forma idiomática. Hoy no hay ningún uso de esas puertas fuera del almacén (búsqueda sobre `b7995bc` de la fachada; las demás, sin búsqueda exhaustiva, §18). Sin respuesta se implementa B | Usuario |
| `OPEN-CORE-75` | El nombre de descarga en `temporaryUrl` no tiene hoy ningún llamador. ¿Se implementa ya? | **A.** Sí, como fija `ADR-076 §3.3` regla 3. **B.** Se declara en el contrato y se implementa con su primer consumidor | **A (Recomendada)**: el ADR está aceptado con esa firma y probarlo contra Garage ahora cuesta poco. B ahorra código sin uso, pero deja el contrato a medias | Usuario |
| `OPEN-CORE-76` | ¿Se comprueba que ningún *bucket* existente tiene objetos fuera de `tenants/<public_id de un centro que existe>/` (p. ej. `tenants//…`)? | **A.** Comprobación manual única por entorno con el cliente S3, anotada en `memory.md`. **B.** No comprobar: solo hay datos sintéticos. **C.** Dejarlo a la verificación de `ADR-077` | **A (Recomendada)** y, en todo caso, C: hoy no hay datos reales, así que el coste es mínimo y cierra `OPEN-PURGA-05` con evidencia. No es código | Técnico (la sesión) |

## 17. Cambios propuestos en ficheros compartidos

No se ha tocado ninguno. Los aplica la sesión orquestadora.

| Fichero | Cambio propuesto |
|---|---|
| `PLAN-IMPLEMENTACION.md` | Paso `S-2`: enlazar este documento; estado «especificado, pendiente de aprobación»; corregir «16 llamadas» por «13 en 12 ficheros (a 2026-11-12, tras `S-1b`)»; tres tramos; anotar que cierra #612 y responde `OPEN-PURGA-05` |
| `memory.md` | Decisiones pendientes: `OPEN-CORE-70`, `-71`, `-72` (bloquean). Hallazgos de §18 |
| `ARCHITECTURE.md` | Al implementar: `§3.4`, patrón «fichero privado» apuntando a `TenantObjectStore`; lista de reglas `AR-*` con `AR-17`; retirar la mención de `TenantStorage` si existe |
| `CHANGELOG.md` | Entrada de la especificación al integrarla y otra al implementar |
| `CONTRIBUTING.md` | Al implementar: `AR-17` en la lista de reglas de arquitectura, si las enumera |
| `.claude/skills/modulo-nuevo/SKILL.md` | Al implementar: fila «ficheros del módulo: solo por `TenantObjectStore`; regla `AR-17`» en la tabla de piezas |
| `.claude/skills/aislamiento-tenant/SKILL.md` | Al implementar: el almacenamiento va por `TenantObjectStore`, si hoy cita `TenantStorage` (no leído) |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md §18` | Ninguno: `S-2` no crea ADR. Si `OPEN-CORE-70` se resuelve con C, hace falta un ADR que enmiende `ADR-076 §3.3` |
| `docs/adr/ADR-077` (rama de A1) | `§1.2` y `OPEN-PURGA-05`: «16 llamadas» → 13, y remitir a §5 y §10 de este documento |
| Issue #612 | Comentario con enlace a este documento; se cierra al mezclar `S-2` |
| `SYSADMIN.md`, `RUNBOOK.md`, `README.md`, `CLAUDE.md` | Ninguno |

## 18. Lo no verificado y hallazgos fuera de ámbito

**No verificado**

1. El identificador `OPEN-CORE` más alto en uso (§16).
2. Quién despacha las tres purgas de Core y las cuatro exportaciones, y con qué contexto: no se han leído los despachadores. Sí se ha leído que `EloquentBulkUserImporter:57` y `UserImportsController:76` despachan con `$tenant->public_id ?? ''`.
3. Que no haya usos de `Illuminate\Contracts\Filesystem`, `FilesystemManager`, Flysystem o el cliente S3 fuera del almacén: solo se buscó la fachada y `temporaryUrl`.
4. El resto de `ExecuteUserImport` a partir de la línea 90 (no se espera más acceso al disco: la búsqueda da una sola llamada en el fichero).
5. Qué *specs* de E2E real cubren hoy la importación de usuarios y los activos de marca.
6. Comportamiento de `putStream` con Garage (umbral de subida multiparte) y de la cabecera de nombre de descarga en una URL firmada contra Garage.
7. Qué documentos de módulo citan claves compuestas a mano (§9.4 dice «si las hay»).
8. No se ha ejecutado nada: ni tests ni búsquedas en un *bucket*.

**Hallazgos fuera de ámbito, señalados y no corregidos** (`CLAUDE.md §5`; la sesión decide si abre issue)

| Hallazgo | Dónde | Severidad propuesta |
|---|---|---|
| Tres trabajos que escriben (`GenerateUserExport`, `GenerateAuditLogExport`, `GenerateImageConsentsExport`) y `ValidateUserImport` no rechazan un `tenantPublicId` vacío; daría la clave `tenants//…`, fuera de todo centro. Solo `GenerateEnrollmentsExport` lo rechaza. `S-2` lo elimina de raíz | Constructores de esos trabajos | Media (mismo tipo que #612; queda resuelto por `S-2`) |
| `ValidateUserImport` convierte un fichero fuente inexistente en cadena vacía y lo informa como «cabecera desconocida» | `ValidateUserImport.php:89-104` | Baja |
| La importación carga hasta 10 MB en memoria en la petición y en dos trabajos | `EloquentBulkUserImporter.php:52`, `ValidateUserImport.php:89`, `ExecuteUserImport.php:69` | Baja |
| `ADR-076 §3.2` y `ADR-077 §1.2` citan 16 llamadas; son 13 desde `S-1b` | Los dos ADR | Baja (documentación; `ADR-076` es inmutable: se corrige en el plan, no en el ADR) |

---

**¿Se aprueba esta especificación para pasar a implementación?** Antes hacen falta las respuestas a `OPEN-CORE-70`, `-71` y `-72`.
