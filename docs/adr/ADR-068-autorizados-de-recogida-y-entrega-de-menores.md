# ADR-068 · Autorizados de recogida y entrega de menores

**Estado**: **ACEPTADA** (2026-10-29), **con todas las ampliaciones aprobadas**. El usuario aprueba el 2026-10-29 las cuatro decisiones estructurales de `REQ-FAM-UNIT/funcional.md §21.2` con la recomendación de cada una (`OPEN-068-01` = B, `-02` = A, `-03` = A, `-04` = A), las preguntas `OPEN-FAM-UNIT-27`, `-28`, `-29` y `-36` con su recomendación, los criterios propuestos de `OPEN-FAM-UNIT-34` y `-35` (**aprobados por el usuario el 2026-10-29 (confirmación explícita posterior)**: la puerta responde igual ante «no figura» y «excluido»; la recogida no prevista solo con autorización puntual registrada por secretaría o dirección) y la partición `1.14b-a` a `1.14b-d` de `§21.12`. Ese mismo día aprueba también, con la recomendación del `architect`, las ampliaciones de listas cerradas `L1`, `L2`, `L5`, `L7`, `L8`, `L9` y `L10` (`§6`), `OPEN-068-05` a `-08` (`§10`) y `OPEN-FAM-UNIT-26` y `-30`. Queda abierta, para transporte, `OPEN-FAM-UNIT-45`.
**Fecha**: 2026-10-29
**Paso**: `1.14b · REQ-FAM-UNIT-005 + REQ-PRL-004` (`docs/modulos/REQ-FAM-UNIT/funcional.md §21`; `datos.md §8`, `api.md §8`, `permisos.md §8`, `operacion.md §12`).
**Afecta a**: `REQ-FAM-UNIT-005`, `REQ-PRL-004`, `REQ-CORE` (`Core\Domain`: personas), `REQ-TRAN-005`, `REQ-COMED`, `REQ-EXTRA`, `REQ-ACOG`, `REQ-INF` (consumidores futuros), `INV-001`, `INV-002`, `INV-003`, `INV-007`, `INV-008`, `INV-010`, `INV-011`, `INV-012`.
**Se apoya en**: `ADR-013` (almacenamiento S3), `ADR-032` (fuente única de autorizaciones de recogida), `ADR-034 §1` (persona y facetas), `ADR-035` (auditoría y supresión; `§5`, forma del `context`), `ADR-039` (vocabulario de `audit_logs.event`), `ADR-044` (autorización; `§8 (2)` y `(3)`), `ADR-045` (descriptor y contratación de módulos), `ADR-048` (sin eventos sin consumidor), `ADR-054` (precedente `App\Support\Csv`), `ADR-056` (`AR-01`, `AR-02`, `AR-10`, listas cerradas), `ADR-066 §5.2`/`§5.3`/`§5.7` (estado calculado, consulta en origen, fallo en cerrado).
**Resuelve** `OPEN-068-01` a `-04` de `funcional.md §21.13.1`. **No sustituye ningún ADR.** Concreta `ADR-032` («el proceso operativo sí puede vivir en `REQ-PRL`») sin cambiarlo.

---

## 1 · Contexto

`REQ-FAM-UNIT-005` define la lista maestra de quién puede recoger a un menor y `ADR-032` la hace fuente única para todos los servicios. `REQ-PRL-004`, adelantado a fase 1, es el proceso en la puerta que la consume. La especificación de `1.14b` (`funcional.md §21`) identificó cinco decisiones que cambian la estructura del producto (dónde vive el proceso de puerta, qué es un tercero autorizado, cómo se suben y sirven ficheros privados, cómo se audita una lectura y qué contrato ven los consumidores) y las dejó a este ADR.

Es un paso crítico: datos de menores y de terceros, fotografías de personas y un riesgo físico directo (entregar a un niño a quien no debe). Por eso el ADR se ha contrastado contra el código antes de decidir.

### 1.1 Hechos verificados (rama `chore/spec-1-14b-autorizados-entrega`, sobre `e08862a`)

**Personas (`Core`)**

1. `people` (`database/migrations/2026_08_18_100200_create_people_table.php`) tiene `given_name`, `family_name_1`, `family_name_2`, `birth_date`, `document_type`, `document_number`, `contact_email`, `contact_phone` y `locale`, y deja **fuera a propósito la fotografía** (comentario de la migración). Índice único parcial `people_tenant_document_unique (tenant_id, document_type, document_number) WHERE document_number IS NOT NULL AND deleted_at IS NULL`. Confirma `H4` de la especificación.
2. El modelo es **`App\Models\Person`** (núcleo, no `Core\Domain\Models`), `Auditable`, con alias `person` en el *morph map* del núcleo (`AppServiceProvider`). Fuera de `Core` ningún módulo lo importa: `fam_unit` llega a las personas solo por `Core\Domain\PersonDirectory`.
3. **Hoy no existe ningún camino para crear una persona sin cuenta.** El único `Person::create` del código es `Core\Application\CreateUser`, que crea persona y usuario juntos. Tampoco hay ningún *endpoint* que edite o borre una persona que no tenga usuario: `Core` expone las personas solo a través de `/users`. Esto **amplía `H4`**: no falta solo la interfaz de creación, falta también la de corrección y supresión (`OPEN-068-07`).
4. `PersonDirectory` tiene cinco métodos (`findByPublicId`, `refsByIds`, `personIdForUser`, `findUserByPublicId`, `usersByPersonIds`) y devuelve `PersonRef {id, publicId, displayName, removed}`, **sin documento, contacto ni fecha de nacimiento**. No hay búsqueda por nombre (confirma `H9`) **ni por documento**, y no hay forma de leer el teléfono o el documento de una persona desde otro módulo. La especificación necesita las tres cosas (`api.md §8`: `GET /pickup-persons?document_type=&document_number=`; `§21.8`: `contact_phone` y `document_masked` en `entriesFor`; `RN-PRL-08`: teléfonos del principal y del contacto de emergencia). Solo la búsqueda por nombre se propuso como ampliación (`OPEN-068-05`).
5. La validación del documento es `Core\Application\PersonDocumentRules::check()` (catálogo `DocumentType`, par completo, valor canónico, `RN-CORE-90` a `-93`), interna de `Core`.

**Ficheros**

6. La única subida validada es `Core\Application\UploadBrandingAsset`: tipo real con `finfo`, extensión declarada contra tipo real, tamaño por tipo, SVG saneado, clave `tenants/<tenant public_id>/branding/<kind>/<ULID>.<ext>` en el disco `config('filesystems.default')`. **No reprocesa JPEG, PNG ni WebP: sus metadatos se guardan tal cual.** La firma es `Core\Infrastructure\BrandingAssetUrlSigner` (`temporaryUrl`, `core.signed_url_ttl_minutes`, **15** por defecto). La purga de huérfanos es `PurgeOrphanBrandingAssets`. Todo es interno de `Core` (confirma `H3`).
7. **La imagen del contenedor no tiene ninguna extensión de imagen.** `infra/containers/api/Containerfile` instala `pdo_pgsql pgsql zip intl mbstring redis opcache`; `.github/workflows/ci-api.yml` (líneas 69 y 332) declara `pdo_pgsql, pgsql, zip, intl, mbstring, redis`. Ni `gd`, ni `imagick`, ni `exif`. `fileinfo` sí está (viene compilada en la imagen oficial y la usa el *branding*). La especificación lo dejaba «pendiente de verificar»: **está verificado y falta** (`§5.3`, `L8`).
8. Precedente de primitiva compartida: `App\Support\Csv\CsvWriter` (`ADR-054`). `App\Support` está vigilado por `AR-02` (`CoreBoundariesTest`, lista de excepciones vacía, #375): no puede importar internos de ningún módulo.

**Auditoría**

9. El `CHECK` de `audit_logs.event` y `AuditCatalog::EVENTS` ya admiten `read` (`ADR-039`). **No hay ningún escritor de `read`** (confirma `H5`).
10. `App\Support\Audit\AuditRecorder::record()` exige un `Model&Auditable` y resuelve el alias por el *morph map*. Un módulo que quiera auditar la lectura de la lista **de un alumno** (sujeto: su persona) tendría que tener en la mano un `App\Models\Person`, que solo usa `Core` (hecho 2). Hace falta otra entrada (`§5.4`). El `context` se valida por forma (`ADR-035 §5`): claves `^[a-z][a-z0-9_]*$`, valores código, ULID, entero, booleano o nulo.

**Módulos, permisos y tests de arquitectura**

11. Los módulos se descubren por sistema de ficheros: `ModuleServiceProviderDiscovery` (proveedores) y `Tests\Support\ArchitectureModules::names()` (reglas `AR-01`/`AR-02`). Un módulo `Prl` queda vigilado sin tocar los tests. El descriptor de `fam_unit` es `phase '1'`, `depends_on []`, `essential false`.
12. `AR-10` (`ScopedQueryConfinementTest::scopedResourceMap()`) tiene **5** recursos con ámbito restringido (`auditoria`, `reservas`, `familia`, `vinculo_tutor`, `consentimiento_imagen`).
13. `AR-07a` tiene **37** excepciones (`toHaveCount(37)`); `AR-06`, **6**; la lista de rutas de la SPA con `meta.permissions: []`, **8**. `AR-09` prohíbe todo permiso con `is_special_category = true`.
14. El `CHECK` de `terms_versions.kind` tiene **3** valores (`condiciones_uso`, `consentimiento_imagen_web`, `consentimiento_imagen_redes`).
15. Existen `App\Http\Middleware\RequireIdempotencyKey` y `App\Models\IdempotencyKey` (`INV-011`), y el patrón de límite con `RateLimiter` (`Curso\Application\Rollover\RolloverRateLimit`, `Auth\Application\RateLimitGuard`).
16. El rol predefinido de conserjería existe: `conserjeria_pas` (`ProvisionTenantDefaults`).
17. La base de datos no tiene `unaccent` ni `pg_trgm`; las búsquedas existentes son `ilike`.
18. Los *shells* de la SPA se registran a mano en `apps/web/src/navigation/modules.ts`.

---

## 2 · Qué NO decide este ADR

- **Las reglas de negocio** de `§21.6` y `§21.7` (composición de la lista, vigencia, principal, retirada, recomprobación, métodos de verificación). Son de la especificación.
- **Las preguntas no bloqueantes de `§21.13.2`** que no figuran en la aprobación del encabezado. Siguen como están en la especificación (`§10`).
- **La salida autónoma, la alerta de no recogido y los avisos automáticos** (`1.14b-d`, diferidos).
- **La contradicción con `REQ-TRAN-005`** (`OPEN-FAM-UNIT-45`, `§10`).
- **El ADR de custodia** (`OPEN-FAM-UNIT-05`) ni la auditoría de lectura de categoría especial. Este ADR deja una pieza compatible (`§5.4`) sin anticiparla.
- **La migración del *branding* a la primitiva de ficheros.** Sería refactorizar código ajeno al paso (`CLAUDE.md §11`).

---

## 3 · Opciones reales

| Decisión | Opciones | Aprobada |
|---|---|---|
| **D1** · Dónde vive el proceso de puerta | A `fam_unit`; **B módulo `prl`**; C módulo de control de acceso (descartado por `ADR-032`) | B |
| **D2** · Identidad de los terceros | **A personas de `Core` + `PersonRegistrar` + búsqueda**; B tabla propia en `fam_unit` | A |
| **D3** · Ficheros privados | **A `App\Support\Files`**; B interfaz en `Core\Domain`; C duplicar en `fam_unit` | A |
| **D4** · Auditoría de lectura no especial | **A una fila `read` por consulta de lista, en la interfaz**; B una por foto; C esperar al ADR de custodia | A |
| **D5** · Contrato para consumidores | Interfaz pública en `FamUnit\Domain`, sin eventos y sin caché (único candidato compatible con `ADR-032` y `ADR-066 §5.3`) | Sí |

---

## 4 · Evaluación

| Criterio | D1-B `prl` | D2-A personas de `Core` | D3-A `App\Support\Files` | D4-A `read` por lista |
|---|---|---|---|---|
| **Coste en solitario** | Medio: un módulo con su proveedor, descriptor, permisos y carpeta de documentación | Medio: una interfaz de escritura y tres métodos de lectura en `Core` | Medio: tres clases y dos extensiones de PHP | Bajo: un método en `AuditRecorder` |
| **Mantenimiento a 3 años** | `TRAN`, `COMED`, `EXTRA`, `ACOG` e `INF` reutilizan un contrato ya probado por un consumidor real | Una persona, un registro (`§16.3` regla 1): el abuelo de dos nietos o el empleado que recoge a su hijo no se duplican | Una sola implementación de tipo real, metadatos y firma para todo fichero privado futuro (justificantes, documentos de `REQ-SEC`) | La custodia puede reutilizar la misma entrada |
| **Invariantes** | `INV-007` probada por `AR-01` en el primer consumidor | `INV-007`: `fam_unit` nunca escribe en `people`; `Core` sigue siendo el dueño | `INV-001`: el prefijo de tenant lo pone la primitiva, no el llamador | `INV-003` ampliada a lecturas; `ADR-035 §5` intacto |
| **Reversibilidad** | Alta: mover `prl` dentro de `fam_unit` es mover código, sin migraciones de datos (las tablas no cambian de nombre) | Media: los terceros quedan como filas de `people`; deshacerlo exigiría migrar datos | Alta | Alta: filas `read` adicionales, borrables sin afectar a nada |

Las cuatro alternativas descartadas están en `§9`. La más discutible es D1, y se acepta con su contrapartida (`§8`).

---

## 5 · Decisión

### 5.1 D1 · Módulo `prl`

- Directorio `app/Modules/Prl`, descriptor `code = 'prl'`, `phase = '1'`, `depends_on = ['fam_unit']`, `essential = false` (`ADR-045`). En `1.14b` contiene **solo** `REQ-PRL-004`; el resto de `REQ-PRL` llega en fase 2 al mismo módulo.
- Tablas propias: `student_movements` (de solo anexar) y `pickup_alerts` (`datos.md §8.4`-`§8.5`). **Ninguna tabla de `prl` guarda personas autorizadas ni el estado de una autorización** (`RN-PRL-01`). Las FK hacia `people`, `guardian_student_links` y `pickup_authorizations` están permitidas en base de datos como referencia de evidencia; la **lectura** va siempre por `AuthorizedPickupList` (`INV-007`).
- Documentación en `docs/modulos/REQ-PRL/`: las partes marcadas `prl` de `REQ-FAM-UNIT` se trasladan allí **sin cambios de contenido** antes de implementar `1.14b-b`.
- `prl` **no** usa permisos de `fam_unit`: tiene los suyos (`entrega_menor`, `alerta_entrega`; `permisos.md §8`). Conserjería consulta la lista sin poder dar de alta autorizados.
- Condición de revisión: cuando la especificación de fase 2 de `REQ-PRL` añada `COM`, `RRHH` o `ESP` a `depends_on`, la puerta pasará a exigir esos contratos (`RMOD-006`). Esa especificación decide entonces, con un ADR nuevo que sustituya este apartado, si `REQ-PRL-004` se queda en `prl` o se separa.

### 5.2 D2 · Terceros como personas de `Core`

**Escritura: `App\Modules\Core\Domain\PersonRegistrar`** (interfaz nueva; implementación en `Core\Infrastructure`).

```php
namespace App\Modules\Core\Domain;

interface PersonRegistrar
{
    /**
     * Crea una persona sin cuenta o reutiliza la persona viva del centro con el mismo documento.
     * Nunca modifica una persona existente. Valida el documento con las reglas de Core (RN-CORE-90 a -93).
     */
    public function registerOrReuse(PersonRegistration $data): PersonRegistrationOutcome;
}
```

- `PersonRegistration`: `givenName`, `familyName1`, `familyName2?`, `documentType?`, `documentNumber?`, `contactPhone?`. Ni fecha de nacimiento, ni correo, ni dirección (minimización; `OPEN-FAM-UNIT-27`). `locale` = idioma por defecto del centro.
- `PersonRegistrationOutcome`: `personId`, `publicId`, `reused` (bool) y `dataMismatch` (bool: algún dato aportado difiere del registrado). **No devuelve qué campo difiere ni el valor registrado**: el personal lo ve en la ficha de la persona, con su permiso.
- Con documento, la búsqueda de la persona viva y la inserción se hacen en la misma transacción. Si dos altas simultáneas con el mismo documento chocan con `people_tenant_document_unique`, la que pierde relee y reutiliza (punto de guardado, sin error para el usuario). **Sin documento, siempre crea** (no hay clave con la que reutilizar; consecuencia aceptada en `§8`).
- La persona del propio alumno y la de un tutor revocado **sí** se pueden reutilizar en `PersonRegistrar`: la regla de elegibilidad es de `fam_unit` (`RN-FAM-UNIT-86`), no de `Core`.
- La creación la audita `Core` por el ciclo de vida de `Person` (`Auditable`), como hoy.
- **Autorización**: la interfaz no comprueba permisos, igual que `FamilyUnitStudents`. La comprobación es la del *endpoint* de `fam_unit` (`autorizado_recogida.crear`, `INV-002`).

**Lectura: `PersonDirectory`**, ampliación aprobada:

- `searchByNameAmong(string $term, list<int> $personIds, int $limit): list<PersonRef>`: personas **vivas** del centro cuyo nombre o apellidos empiezan por `$term`, sin distinguir mayúsculas (`ilike` con comodines escapados), **acotada** a `$personIds`, en una consulta y ordenada por nombre visible. Sin plegado de acentos en `1.14b` (hecho 17; `§8`).

**Lectura: dos métodos más que la especificación necesita y no propuso. Aprobados el 2026-10-29 (`OPEN-068-05`):**

- `findByDocument(string $documentType, string $documentNumber): ?PersonRef`, para `GET /pickup-persons?document_type=&document_number=` (`api.md §8`).
- `contactDetailsByIds(list<int> $personIds): array<int, PersonContact>`, con `PersonContact {contactPhone?, documentType?, documentNumber?}`, para `entriesFor` (`§21.8`) y los teléfonos de la alerta (`RN-PRL-08`). Devuelve el documento completo; **el enmascarado es de `fam_unit`** (`RN-FAM-UNIT-90`) y ningún consumidor de `AuthorizedPickupList` recibe el número completo.

### 5.3 D3 · `App\Support\Files`

Núcleo, sin dependencia de ningún módulo (`AR-02`). Tres piezas:

| Pieza | Responsabilidad |
|---|---|
| `ImageUploadPolicy` (objeto de valor) | Tipos admitidos (en `1.14b`, `image/jpeg`, `image/png`, `image/webp`; **nunca SVG**), bytes máximos, píxeles máximos de entrada y lado máximo de salida |
| `ImageSanitizer` (interfaz) + `GdImageSanitizer` | Tipo real con `finfo`, rechazo si la extensión declarada no casa, dimensiones con `getimagesize()` **antes** de decodificar (rechaza bombas de descompresión), aplica la orientación EXIF y **la descarta**, redimensiona al lado máximo y **recodifica** en el mismo formato. Lo recodificado no lleva EXIF, XMP, IPTC ni perfiles. Vuelve a comprobar el tipo real del resultado |
| `PrivateObjectStore` | Escribe en el disco privado configurado (`files.disk`, por defecto `filesystems.default`) bajo `tenants/<tenant public_id>/<área>/<ULID>.<ext>`. **El tenant lo toma de `TenantContext`, nunca de un parámetro** (`INV-001`). Firma (`temporaryUrl`) solo claves con el prefijo del tenant en curso, y lanza si no. Borra por clave con la misma comprobación |

- **Nunca se escribe el original** en el *bucket*: solo el resultado recodificado.
- **Síncrono, en la petición.** `INV-012` pide colas para lo pesado, pero aquí el trabajo está acotado por la política (una imagen, píxeles máximos) y una cola obligaría a guardar el original con sus metadatos mientras espera, que es justo lo que se quiere evitar. Si la medida de `1.14b-a` supera los 2 s con la imagen máxima, se revisa.
- **Extensiones de PHP: `gd` (con JPEG, PNG y WebP) y `exif`** (solo para leer la orientación antes de descartarla). Son extensiones incluidas en la distribución de PHP, con su licencia y su calendario de versiones, sin paquete de Composer nuevo. `GdImageSanitizer` las envuelve tras `ImageSanitizer` (`RNF-MANT-007`). Se descarta `imagick` (`§9`). Requiere aprobación como dependencia (`CLAUDE.md §1`; `L8`).
- `fam_unit` la usa para las fotografías de recogida (`RN-FAM-UNIT-83`, área `fam-unit/pickup-photos`). TTL de firma propio [DERIVADA de la especificación: 5 minutos], no `core.signed_url_ttl_minutes`. El barrido de huérfanos sigue el patrón de `PurgeOrphanBrandingAssets`.
- El *branding* **no** migra en este paso. Su falta de reprocesado (hecho 6) va como hallazgo (`§11`).

### 5.4 D4 · Auditoría de lectura

- **`AuditRecorder::recordRead(string $auditableAlias, int $auditableId, ?string $auditablePublicId, array $context): void`**: escribe `event = read`, `changes = NULL`, mismo actor, IP, agente y `request_id` que `record()`, la misma validación de `context` (`ADR-035 §5`) y las mismas reglas de modo plataforma. El alias tiene que existir en el *morph map*; si no, lanza. Así `fam_unit` audita con sujeto `person` sin importar `App\Models\Person`.
- **Quién la escribe**: `fam_unit`, **dentro** de `AuthorizedPickupList::entriesFor()` y del *endpoint* de foto (`GET /pickup-persons/{person}/photo`), no el consumidor. Ningún consumidor puede olvidarla.
- **Cuándo**: en toda lectura que devuelva una fotografía firmada o un documento (`RN-FAM-UNIT-92`). Sujeto: la persona del alumno (lista) o la persona de la foto (foto). `context`: `{purpose: 'gestion' | 'puerta', entries: int, photos: int}`. Sin nombres, documentos, teléfonos ni claves de objeto.
- **Fallo en cerrado**: la fila se escribe **antes** de devolver los datos. Si la escritura falla, la petición falla y no sale ninguna URL firmada. Sin rastro no hay datos.
- `isAuthorized` y `authorizedAmong` no se auditan (no devuelven datos personales).
- **Compatibilidad**: la custodia (`OPEN-FAM-UNIT-05`) puede usar la misma entrada. `AR-09` no cambia: aquí no hay ningún permiso de categoría especial (`§21.10`).
- Volumen: una fila por consulta de puerta (del orden de 1 000 al día en un centro de 1 000 alumnos). Entra en el catálogo de retención de `1.23b`.

### 5.5 D5 · Contrato `App\Modules\FamUnit\Domain\AuthorizedPickupList`

Es el de `§21.8`, con estas precisiones:

- **Consultas**: `isAuthorized`, `entriesFor` y `authorizedAmong`, como en `§21.8`. Una más, que `GET /door/students` necesita y `§21.8` no recoge: `studentsMatching(string $term, int $limit)`, que devuelve `{student_person_id, student_public_id, display_name, family_unit_code}`. `fam_unit` acota el universo de alumnos y llama a `PersonDirectory::searchByNameAmong()`. **Qué alumnos forman ese universo lo fija `OPEN-068-06`, aprobada el 2026-10-29**.
- **El instante**: `§21.8` pone `instante` como parámetro. Propongo que no lo sea y que siempre sea el del servidor. Así ningún consumidor «planifica» consultando una fecha futura, que es lo que prohíbe el punto 2 del contrato. Cambia la firma de `§21.8`: aprobado el 2026-10-29 (`OPEN-068-08`).
- El resto del contrato, sin cambios: id interno de persona; el centro siempre del contexto; consulta en el momento de entregar; prohibido guardar la lista o su estado; fallo en cerrado con alumno o persona desconocidos, de otro centro o con `fam_unit` descontratado; sin eventos (`ADR-048`); ningún módulo, tampoco `REQ-AUT`, ofrece «autorización de recogida» (`H8`). La exclusión por revocación va en la misma consulta que compone la lista (`RN-FAM-UNIT-80`), con el mismo predicado que `FamilyMembership::isAccessRevoked()`.
- El límite de lecturas con foto (`RN-FAM-UNIT-93`) se aplica dentro de `entriesFor`, con `RateLimiter` y clave por tenant y usuario (patrón del hecho 15).

### 5.6 Invariantes

- **`INV-001`**: la primitiva de ficheros pone y comprueba el prefijo del tenant (`§5.3`). `PersonRegistrar` y `PersonDirectory` consultan con `TenantScope` + RLS. `CA-FAM-UNIT-124` y `CA-PRL-011` cubren los *endpoints*.
- **`INV-002`**: las interfaces de `Core` y de `fam_unit` no autorizan; lo hace cada *endpoint* con su permiso. `autorizado_recogida` entra en `AR-10` (`L1`).
- **`INV-003`**: las escrituras se auditan por el ORM (`Selective`, `datos.md §8.7`) y las lecturas sensibles por `recordRead` (`§5.4`).
- **`INV-007`**: `fam_unit` no toca `people` (solo `PersonRegistrar`/`PersonDirectory`), `prl` no toca nada de `fam_unit` salvo `AuthorizedPickupList`, y `App\Support\Files` no conoce ningún módulo. Lo vigilan `AR-01`/`AR-02` sin cambios.
- **`INV-008`**: la base legal de los datos del tercero y su información (art. 14 RGPD) es **condición previa a datos reales** (`OPEN-FAM-UNIT-36`, aprobada así). No bloquea la implementación con datos de `REQ-SEED`.
- **`INV-011`**: `POST /student-movements` y `POST /pickup-alerts` con `Idempotency-Key` (hecho 15).

---

## 6 · Ampliaciones de listas cerradas (cada una requirió aprobación expresa; todas aprobadas el 2026-10-29)

| ID | Lista | Cambio | De → a | Estado |
|---|---|---|---|---|
| **L1** | Mapa de `AR-10` (`scopedResourceMap()`) | + `autorizado_recogida`: modelos `PickupAuthorization` y `PickupPhoto`, con el servicio de la lista como único fichero sancionado (patrón `consentimiento_imagen`) | 5 → **6** | **Aprobada 2026-10-29** (`permisos.md §8.3`) |
| **L2** | `CHECK` de `terms_versions.kind` y el vocabulario de `TermsVersionService` | + `autorizacion_recogida` | 3 → **4** | **Aprobada 2026-10-29** |
| **L3** | Catálogo de módulos (`ADR-045`) | + `prl` (`phase 1`, `depends_on ['fam_unit']`, no esencial) | — | **Aprobada** (`OPEN-068-01`) |
| **L4** | Superficie de `Core\Domain` | + `PersonRegistrar`; + `PersonDirectory::searchByNameAmong()` | — | **Aprobada** (`OPEN-068-02`) |
| **L5** | Superficie de `Core\Domain` | + `PersonDirectory::findByDocument()`; + `PersonDirectory::contactDetailsByIds()` | — | **Aprobada 2026-10-29** (`OPEN-068-05`) |
| **L6** | Núcleo `App\Support` | + espacio `Files` (`§5.3`); + `AuditRecorder::recordRead()` (`§5.4`) | — | **Aprobada** como concepto (`OPEN-068-03`, `-04`); la firma es la de este ADR |
| **L7** | Catálogo de permisos | `fam_unit` + `autorizado_recogida.{leer,crear,actualizar,eliminar}` (4); `prl` + `entrega_menor.{leer,crear}`, `alerta_entrega.{leer,crear,actualizar}` (5). Ninguno de categoría especial | +9 | **Aprobada 2026-10-29** (`permisos.md §8.2`) |
| **L8** | Extensiones de PHP en `infra/containers/api/Containerfile` (etapa `base`) y en `ci-api.yml` (las dos listas) | + `gd`, + `exif` | 7 → **9** y 6 → **8** | **Aprobada 2026-10-29** (`CLAUDE.md §1`) |
| **L9** | Siembra de roles predefinidos (`ProvisionTenantDefaults` y comando de concesión para centros existentes) | Matriz de `permisos.md §8.4` sobre `administrador_centro`, `secretaria`, `direccion`, `conserjeria_pas` y `tutor_legal` | — | **Aprobada 2026-10-29** (`OPEN-FAM-UNIT-42`) |
| **L10** | *Morph map* | `fam_unit` + `pickup_photo`, `pickup_authorization` (7 → 9); `prl` nuevo con `student_movement`, `pickup_alert` (2) | — | **Aprobada 2026-10-29** (alias estables, `ADR-034 §3`) |

**Sin cambios** (comprobado): `AR-07a` (37: todas las rutas nuevas llevan `permission:`), `AR-06` (6: todos los modelos nuevos son `Auditable`), rutas de la SPA con `meta.permissions: []` (8), `CHECK` de `audit_logs.event` (ya admite `read`), catálogo de `type` de `ADR-038 §6.2` (los errores nuevos son códigos de `422`; `413`, `415`, `429` y `module-disabled` ya existen), `AR-09` y `data_exports.kind` (sin exportación). El registro del *shell* `prl` en `apps/web/src/navigation/modules.ts` es una línea más, no una lista vigilada por test.

---

## 7 · Comprobaciones (`INV-015`)

Se suman a `CA-FAM-UNIT-111` a `-129` y `CA-PRL-001` a `-013`:

- **CA-068-01** — `GdImageSanitizer`: un JPEG con EXIF de geolocalización y orientación 6 sale girado y sin ningún bloque de metadatos (leyendo el objeto guardado); una imagen que declara más píxeles que el máximo se rechaza **sin decodificar**; un SVG, un HEIC y un PNG con extensión `.jpg` se rechazan sin escribir nada.
- **CA-068-02** — `PrivateObjectStore`: con el tenant A, firmar o borrar una clave con el prefijo de B lanza; la clave nunca contiene datos de la persona.
- **CA-068-03** — `recordRead`: escribe `read` con `changes` nulo; un alias que no esté en el *morph map* o un `context` con texto libre lanza.
- **CA-068-04** — Fallo en cerrado de la lectura: si `recordRead` lanza dentro de `entriesFor`, la respuesta es error y no contiene ninguna URL firmada.
- **CA-068-05** (suite `Concurrency`) — Dos `registerOrReuse` simultáneos con el mismo documento producen **una** persona y los dos resultados apuntan a ella.
- **CA-068-06** — `registerOrReuse` sobre una persona existente con otro nombre y teléfono no cambia ninguna columna de `people` (comparada fila antes y después) y devuelve `reused = true`, `dataMismatch = true`.
- **CA-068-07** — `prl` se registra con `depends_on ['fam_unit']`, y ninguna clase de `prl` lee tablas de `fam_unit` (`AR-01` lo cubre para el código; un test sobre el esquema comprueba que `prl` no tiene columnas de autorización copiadas).

---

## 8 · Consecuencias

**Buenas**

- El primer consumidor de la lista maestra es real y está aislado por las mismas reglas que tendrán transporte, comedor y extraescolares.
- Una persona, un registro: la revocación de un tutor le excluye también como tercero, en la misma consulta.
- La primitiva de ficheros cierra para todo el producto la fuga de metadatos (geolocalización incluida) en las imágenes privadas futuras.
- La auditoría de lectura existe por fin con un escritor, sin abrir la puerta a un permiso especial.

**Malas, o que hay que aceptar**

- **Un módulo más en el catálogo**, con un nombre (`prl`, «prevención de riesgos laborales») que no describe lo que contiene en fase 1. `ADR-032` ya señalaba que la puerta no es un riesgo laboral. Un centro que contrate `fam_unit` sin `prl` tiene lista pero no puerta. La etiqueta visible del módulo es traducible y puede decir lo que hace.
- **Sin documento no hay reutilización**: dos altas del mismo tercero sin documento crean dos personas.
- **Búsqueda sin acentos**: «Munoz» no encuentra «Muñoz» en `1.14b` (hecho 17). Plegar acentos exige una extensión de PostgreSQL y su propia decisión.
- **Dos extensiones de PHP más** en la imagen y en CI.
- **Sin HEIC**: las fotos de algunos móviles hay que convertirlas antes de subirlas. Es una restricción deliberada (sin decodificador fiable en `gd`).
- **Sin canal para corregir o suprimir los datos de un tercero sin cuenta** mientras no se resuelva `OPEN-068-07`. Afecta a `RN-FAM-UNIT-89` («se corrige en `Core`»), al caso límite «persona de tercero borrada en `Core`» y a la supresión de `§21.10`.

---

## 9 · Alternativas descartadas

1. **D1-A, la puerta dentro de `fam_unit`.** Ahorra un módulo, pero mezcla un registro operativo diario y voluminoso con el dato maestro y no prueba el contrato que usarán los demás servicios.
2. **D1-C, módulo de control de acceso.** Descartado expresamente por `ADR-032`.
3. **D2-B, tabla propia de terceros.** Duplica `people`, rompe la regla 1 de `§16.3` y el índice único de documento, y deja la revocación sin efecto sobre quien aparece dos veces.
4. **D3-B, interfaz de ficheros en `Core\Domain`.** Funciona, pero convierte en dependencia de `Core` una pieza técnica sin dominio. `ADR-054` ya fijó `App\Support` como sitio de lo compartido sin negocio.
5. **D3-C, duplicar la subida en `fam_unit`.** Segunda copia de las reglas de tipo real, la siguiente en desviarse.
6. **`imagick` en vez de `gd`.** Tiene orientación y limpieza de metadatos en una llamada, pero arrastra ImageMagick, con su historial de vulnerabilidades en sus decodificadores y su `policy.xml`. Para tres formatos de foto, `gd` basta.
7. **Reprocesar en cola.** Obliga a guardar el original con sus metadatos hasta que corra el trabajo (`§5.3`).
8. **D4-B, una fila `read` por foto.** Multiplica el volumen sin dar más información: la fila por lista ya dice cuántas fotos se firmaron.
9. **D4-C, esperar al ADR de custodia.** Bloquea `1.14b` por una decisión sin fecha. La entrada de `§5.4` no la condiciona.
10. **Que el consumidor escriba la auditoría de lectura.** Basta un consumidor que la olvide para que la lectura de datos de terceros quede sin rastro.
11. **Caché de la lista con invalidación por eventos.** Una invalidación perdida entrega a un menor a una persona retirada o revocada. Mismo motivo que `ADR-044 §4.7` y que el rechazo del aviso por evento en `ADR-066 §8`.

---

## 10 · Preguntas abiertas

**Nuevas de este ADR**, todas **resueltas el 2026-10-29 con la recomendación del `architect`**:

| ID | Pregunta | Recomendación | Bloquea |
|---|---|---|---|
| **`OPEN-068-05`** (resuelta 2026-10-29) | ¿Se amplía `PersonDirectory` con `findByDocument()` y `contactDetailsByIds()` (`§5.2`, `L5`)? | **Sí.** Sin ellos no hay búsqueda por documento, teléfono ni documento enmascarado en la lista, y la única alternativa es que `fam_unit` lea `people`, contra `INV-007` | `1.14b-a` |
| **`OPEN-068-06`** (resuelta 2026-10-29) | ¿Qué alumnos busca la puerta (`studentsMatching`, `§5.5`) antes de `1.15`? | Toda persona con pertenencia viva a una unidad familiar **o** con algún vínculo vivo (activo o revocado) como alumno: es el universo para el que `fam_unit` puede calcular lista. Con `1.15`, alumnado matriculado (`OPEN-FAM-UNIT-44`) | `1.14b-b` |
| **`OPEN-068-07`** (resuelta 2026-10-29) | ¿Cómo se corrigen y suprimen los datos de una persona sin cuenta (hecho 3)? | Un método más de `PersonRegistrar`, `correctUnaccounted(personId, datos)`, que solo actúa sobre personas **sin usuario**, lo audita `Core` y lo invoca `fam_unit` con `autorizado_recogida.actualizar`. **Endpoint definido (confirmado por el usuario el 2026-10-29): `PATCH /pickup-persons/{person}`** con `autorizado_recogida.actualizar` (`api.md §8.2`, `CA-FAM-UNIT-130`). Las personas con usuario se siguen corrigiendo por `/users`. La supresión se resuelve con la retención de `1.23b` | Condición previa a datos reales; recomendable dentro de `1.14b-a` |
| **`OPEN-068-08`** (resuelta 2026-10-29) | ¿`AuthorizedPickupList` recibe el instante como parámetro (`§21.8`) o usa siempre el del servidor (`§5.5`)? | **El del servidor.** Impide consultar fechas futuras para planificar. Los tests fijan el reloj | `1.14b-a` |

**Heredadas**: `OPEN-FAM-UNIT-26` y `-30` quedan resueltas el 2026-10-29. Siguen abiertas las de `funcional.md §21.13.2` que no figuran en ninguna aprobación (`-31` a `-33`, `-37` a `-43`, `-46`, `-47`). `OPEN-FAM-UNIT-44` queda aprobada en la partición y abierta en «acotar a alumnado matriculado en `1.15`».

**`OPEN-FAM-UNIT-45` (pendiente para transporte).** Se decide antes de especificar `REQ-TRAN`. Hay un matiz que la especificación no recoge: **el propio `ADR-032`** pone «si el alumno tiene autorización para bajar solo» entre las restricciones que aporta transporte. Si se aprueba la recomendación (la salida o bajada autónoma es dato de la lista maestra y transporte solo la restringe), hará falta un ADR nuevo que sustituya ese punto de `ADR-032`. No afecta a `1.14b`.

---

## 11 · Hallazgos fuera del ámbito de este ADR (para issue; no se corrigen aquí)

1. **El *branding* no elimina metadatos** de JPEG, PNG ni WebP (hecho 6): solo sanea SVG. Son logotipos del centro, no personas, y se sirven por URL firmada. Severidad **Baja**. Se resuelve migrando a `App\Support\Files` cuando se toque ese código.
2. **`Core\Domain\AuditCatalog::MODULE_ALIASES` solo declara `core`.** Los alias de `fam_unit` (`guardian`, `guardian_student_link`, `image_consent_decision`…) y los que añade este paso no aparecen en las facetas ni en la validación del filtro de auditoría, así que no se puede filtrar por ellos. Es una contradicción entre código y lo que la pantalla de auditoría pretende cubrir: severidad **Media** como mínimo (`CLAUDE.md §6.6`). Hay que comprobar si ya hay un issue abierto.
3. **La especificación dice en `D3` «pendiente de verificar» la extensión de imagen.** Está verificado: no existe (hecho 7). Se corrige en `funcional.md` (`§12`).

---

## 12 · Cambios que este ADR pide a otros documentos y al código (no los hace `architect`)

- **`REQ-FAM-UNIT/funcional.md §21`**: cabecera de estado y la de `§21.2` a **APROBADA** con referencia a este ADR (`ACEPTADA`). En `§21.13.1`, `OPEN-068-01` a `-04` y `OPEN-FAM-UNIT-27`, `-28`, `-29` y `-36` como resueltas, y `-34`/`-35` con su criterio aprobado (aprobada por el usuario el 2026-10-29, confirmación explícita posterior), y el *endpoint* de `correctUnaccounted` (`PATCH /pickup-persons/{person}`) definido en `api.md §8.2`. Añadir `OPEN-068-05` a `-08`. `H3`/`D3`: la extensión de imagen no existe (hecho 7), y son `gd` y `exif`. `H4`: tampoco hay forma de crear, corregir ni borrar una persona sin cuenta (hecho 3). `H5`/`D4`: `AuditRecorder` exige un modelo y hace falta `recordRead` (hecho 10). `§21.8`: la consulta `studentsMatching` y el instante (`OPEN-068-08`). `RN-FAM-UNIT-89` y `§21.9` («persona de tercero borrada en `Core`»): condicionados a `OPEN-068-07`.
- **`REQ-FAM-UNIT/datos.md §8`, `api.md §8`, `permisos.md §8`, `operacion.md §12`**: estado según la aprobación. `api.md`: `GET /pickup-persons` depende de `OPEN-068-05`.
- **`docs/modulos/REQ-PRL/`** (nueva): traslado sin cambios de contenido de lo marcado `prl`, antes de `1.14b-b`.
- **`REQ-CORE`** (`funcional.md`/`api.md` de interfaces de `Core\Domain`): `PersonRegistrar` y los métodos nuevos de `PersonDirectory`.
- **`ARCHITECTURE.md`**: `App\Support\Files` y `AuditRecorder::recordRead` como piezas del núcleo; `prl` en el mapa de módulos.
- **`docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`**: cabecera (versión y fecha) y fila del historial §20.2 por la entrada de este ADR en el índice de la sección 18.
- **Código** (lo hace `implementer` en `1.14b-a`/`-b`, con todo aprobado el 2026-10-29): las piezas de `§5`, los tests de `§7`, las ampliaciones aprobadas de `§6`; las extensiones de `L8` en `Containerfile` y `ci-api.yml`.
