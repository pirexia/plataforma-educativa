# ADR-034 · Modelo de datos núcleo

**Estado**: PROPUESTA
**Fecha**: 2026-08-18
**Concreta**: sección 16 del documento de requisitos (`16.1`, `16.2`, `16.3`)
**Se apoya en**: `ADR-029` (identificadores y tipos), `ADR-033` (aislamiento), `ADR-004` (borrado en tres niveles), `ADR-020` (régimen por etapa)
**Afecta a**: `INV-003`, `INV-004`, `INV-005`, `INV-008`, `REQ-CORE-003`, `REQ-CORE-004`, `REQ-CORE-005`, `REQ-AUTH`, `REQ-CURSO-001`, `RPERM-001` a `RPERM-015`, `RMOD-002` a `RMOD-010`, `REQ-BO-002`, `REQ-PRIV-006`
**No sustituye a ningún ADR anterior.** Amplía `ADR-033 §7` (registro de tablas compartidas) con dos categorías nuevas de tablas de referencia.

---

## Contexto

El paso 0.7 construyó el mecanismo de aislamiento y dejó una sola tabla de negocio: `tenants`. El paso 0.8 escribe las siete tablas restantes del núcleo (`AcademicYear`, `Person`, `User`, `Role`, `Permission`, `AuditLog`, `ModuleSubscription`) y, con ellas, fija la forma que heredarán las ~200 tablas de los 53 módulos.

Ese es el problema real: no son siete tablas, son siete **plantillas**. Un `academic_year_id` que se decida nullable aquí se replica en cincuenta tablas; una `AuditLog` que no sirva para un módulo de salud obliga a montar un segundo sistema de auditoría en la fase 2; una relación `Person`/`User` mal puesta obliga a migrar el censo entero de un centro en producción.

Cinco cuestiones concretas hay que resolver antes de escribir la primera migración, porque las cinco son de reversibilidad baja:

1. **`Person` frente a `User`.** La sección 16.3 regla 1 ya obliga a separarlas, pero no dice dónde vive el correo, si toda cuenta tiene persona, ni cómo se cuelgan los papeles (alumno, tutor legal, empleado) que una misma persona puede acumular.
2. **`Role`/`Permission` hoy frente al sistema granular de 1.5.** Entre 1.1 y 1.5 hay cuatro pasos que necesitan autorizar endpoints (`INV-002`). Si no existe almacenamiento de permisos, esos pasos autorizarán por código de rol escrito a mano y 1.5 será una migración en caliente de la tabla más sensible del sistema.
3. **`AuditLog`.** `INV-003` la exige para *toda* entidad de negocio de *todos* los módulos futuros. Además choca de frente con dos reglas más: los datos de categoría especial van cifrados en tablas aparte (sección 8 de `CLAUDE.md`) y el derecho de supresión (`ADR-004`) exige poder anonimizar a una persona. Una tabla de auditoría con valores «antes/después» en claro rompe ambas si se diseña sin cuidado.
4. **`AcademicYear`.** `REQ-CURSO` está marcado como dimensión transversal: *«casi toda entidad de negocio está asociada a un curso; añadirlo después obliga a migrar todo el esquema»*. Hay que decidir **ahora** el criterio de cuándo una tabla lleva `academic_year_id` y con qué nulabilidad.
5. **`ModuleSubscription`.** `RMOD-009` obliga a que las APIs de módulos desactivados respondan 403. Ese control se ejecuta en cada petición, así que su origen de datos tiene que ser barato y tiene que fallar en cerrado igual que el tenant.

`ADR-033` dejó además dos deudas explícitas cobrables aquí: el docblock de `TenantMigration::tenantTable()` dice que `created_by`/`updated_by` *«los añade 0.8»*, y `config/tenancy.php` marca `users` como categoría temporal a *«quitar de aquí en cuanto 0.8 la rehaga»*. `ADR-033` §"Consecuencias" dice también que el sobrecoste de RLS *«se **mide** en el paso 0.8, no se da por bueno»*.

---

## Decisión

### 1. `Person` es la identidad; `User` es una faceta de autenticación

```
people  1 ──── 0..1  users            (una persona puede no tener cuenta)
people  1 ──── 0..1  students         (REQ-ALUM, no en 0.8)
people  1 ──── 0..1  guardians        (REQ-FAM-UNIT, no en 0.8)
people  1 ──── 0..1  employees        (REQ-RRHH, no en 0.8)
users   N ──── N     roles            (vía role_user)
```

Reglas fijadas:

- **`users.person_id` es `NOT NULL`.** Toda cuenta pertenece a una persona. No existe la cuenta sin identidad detrás; una «cuenta de servicio» futura será un cliente de API, no una fila de `users`.
- **`UNIQUE (tenant_id, person_id) WHERE deleted_at IS NULL`.** Una persona tiene como mucho una cuenta viva por tenant. Dos cuentas para la misma persona en el mismo centro romperían la resolución multi-rol de `RPERM-007` y devolverían el problema que `Person` existe para evitar.
- **Los papeles no son roles.** `student` / `guardian` / `employee` son **facetas** (tablas 0..1 colgadas de `people`, cada una en su módulo), no filas de `roles`. Una persona puede ser simultáneamente madre de un alumno y profesora: dos facetas, un `people`, un `users`, dos `roles` asignados. Esta es la razón de ser de la sección 16.3 regla 1 y la que hace que `REQ-FAM-UNIT` y `REQ-RRHH` no dupliquen el censo.
- **Las facetas no se crean en 0.8**, pero su forma queda fijada aquí para que sus módulos no la reinventen: `person_id` con `UNIQUE (tenant_id, person_id) WHERE deleted_at IS NULL` y clave foránea compuesta `(tenant_id, person_id) REFERENCES people (tenant_id, id)`, según `ADR-033 §6`.
- **Los roles se asignan a `users`, nunca a `people`.** Un permiso describe lo que una *sesión autenticada* puede hacer; una persona sin cuenta no ejecuta acciones. Esto mantiene la autorización con un único punto de entrada (el usuario autenticado) y evita tener que resolver «permisos de una persona sin sesión», que no significa nada.
- **`role_user` no lleva `academic_year_id`.** Las responsabilidades que sí dependen del curso (tutor de 3ºB en 2026-2027) las modela `REQ-ACAD` en sus propias tablas y alimentan la **resolución de ámbito** de `RPERM-004`, no la asignación de rol. Meter el curso en `role_user` obligaría a reasignar los roles de la plantilla entera en cada rollover.

**Correo electrónico: dos columnas, deliberadamente.**

| Columna | Significado | Unicidad |
|---------|-------------|----------|
| `people.contact_email` | Dato de contacto. Una madre que nunca activa el portal lo tiene y recibe circulares en él (`REQ-COM`) | Ninguna |
| `users.email` | **Credencial de acceso.** Es lo que se teclea en el login y lo que fusiona `REQ-AUTH-002` | `UNIQUE (tenant_id, email) WHERE deleted_at IS NULL` |

Se crea la cuenta copiando `contact_email` y a partir de ahí divergen sin problema. Una sola columna no vale: si vive en `people`, el proveedor de autenticación de Laravel necesita un *join* para cada login, cada recuperación de contraseña y cada fusión OAuth; si vive solo en `users`, las personas sin cuenta se quedan sin canal de comunicación. La duplicación es de dos conceptos distintos que casualmente empiezan con el mismo valor, no de un mismo dato en dos sitios.

**Unicidad por tenant, no global** (`RMT-009`): la misma profesora en dos centros son dos `people` y dos `users` sin relación entre sí. Es coherente con `ADR-001` y `ADR-011` (nunca hay *join* entre tenants) y con `ADR-033 §2` (la cookie es *host-only*, no hay sesión compartida).

**Columnas de `people` en 0.8** (mínimo suficiente, ampliable por expand):

`public_id` (ULID), `given_name`, `family_name_1`, `family_name_2` (nullable — la segunda no existe fuera de España), `birth_date` (nullable), `document_type` + `document_number` (nullable, con `UNIQUE (tenant_id, document_type, document_number) WHERE document_number IS NOT NULL AND deleted_at IS NULL`), `contact_email`, `contact_phone`, `locale` (`REQ-CORE-006`: idioma por usuario). Todo `text` salvo la fecha (`date`) según `ADR-029`.

Quedan **fuera a propósito** de `people`: fotografía, sexo, nacionalidad, dirección postal. Son datos de faceta (`REQ-ALUM`, `REQ-RRHH`) o requieren base legal por campo que hoy no está catalogada (`REQ-PRIV-006`). Minimizar es la posición por defecto: añadir una columna es expand; quitarla, con datos reales dentro, es un incidente de protección de datos.

### 2. `Role` y `Permission`: el esquema completo ahora, el resolutor en 1.5

Se crean en 0.8 **cinco** tablas y se difiere a 1.5 **la lógica**, no el esquema. El criterio es sencillo: *lo que costaría una migración en caliente se decide ahora; lo que es código se escribe cuando toque*.

| Tabla | Ámbito | Contenido |
|-------|--------|-----------|
| `roles` | Tenant | `public_id`, `code`, `name_key`, `name`, `is_system`, `mfa_required`, `special_data_access` |
| `role_user` | Tenant | `user_id`, `role_id` — `UNIQUE (tenant_id, user_id, role_id) WHERE deleted_at IS NULL` |
| `permissions` | **Referencia compartida** | `code` (`recurso.accion`), `resource`, `action`, `module_code`, `is_special_category`, `retired_at` |
| `permission_role` | Tenant | `role_id`, `permission_code`, `effect` (`allow`/`deny`), `scope` (`RPERM-004`) |
| `modules` | **Referencia compartida** | ver punto 5 |

Detalles que no son cosméticos:

- **`roles.name_key` frente a `roles.name`**, con `CHECK` de que exactamente uno está relleno. Los roles predefinidos de la sección 11.1 llevan clave de traducción (`INV-009`, cuatro idiomas); los roles personalizados de `RPERM-005` llevan literal, porque son contenido del centro. No se implementa nombre de rol multi-idioma: es contenido de centro de la capa 3 de `REQ-CORE-006`, pero cuatro traducciones para el nombre de un rol es coste sin beneficio, y la limitación queda escrita aquí para que no se descubra como sorpresa.
- **`roles.mfa_required` y `roles.special_data_access` existen desde 0.8** aunque nadie los lea hasta 1.3. Son `RPERM-014` y `RPERM-015`, dos booleanos; añadirlos después significaría migrar la tabla de roles de un centro en producción para activar MFA, que es justo el momento en que no se quiere tocar el esquema.
- **`permissions` es catálogo, no dato del tenant.** El repertorio de `recurso × acción` lo define la plataforma, no el centro; un centro elige qué permisos concede, no cuáles existen. Va sin `tenant_id`, en la categoría `reference` de `ADR-033 §7`, con `GRANT SELECT` para `plataforma_app` y escritura solo por el propietario.
- **La fuente de verdad de los permisos es el código de cada módulo** (`INV-007`: cada bounded context declara los suyos), materializada en la tabla por un comando idempotente de despliegue. Así se conserva la integridad referencial de `permission_role` sin que el catálogo sea un fichero que haya que mantener a mano. Un permiso que desaparece del código se marca `retired_at`, **nunca se borra**: borrarlo arrastraría por clave foránea las concesiones históricas de todos los centros.
- **`permission_role.effect` y `.scope` se crean vacíos de semántica.** La resolución (`deny` gana a `allow`, `RPERM-007`; denegación por defecto, `RPERM-011`) queda **especificada aquí** e **implementada en 1.5**. Entre 1.1 y 1.5 el resolutor provisional lee `effect` ignorando `scope` (equivalente a `scope = 'all'`). 1.5 enriquece el resolutor; no toca el esquema.
- **Se difiere a 1.5, y son decisiones suyas**: permisos condicionales (`RPERM-008`), vista previa de permisos efectivos (`RPERM-009`), caché de permisos resueltos, y **herencia viva de roles**. Sobre esta última: `RPERM-006` pide *clonación*, que es copia en el momento del alta y no necesita columna; `REQ-CORE-004` menciona *herencia con override*, que sí necesitaría `roles.parent_role_id`. Son cosas distintas y el documento las mezcla. 0.8 implementa clonación (sin columna). Si 1.5 decide herencia viva, el coste es **una columna nullable añadida**, que es expand puro y reversible; combinarla con `deny` sobre `allow` y con ámbitos produce una matriz de resolución difícil de explicar, y esa valoración le corresponde a 1.5 con el problema delante.
- **Los roles de plataforma no viven aquí.** El Super Administrador y los roles internos de `REQ-BO-007` (soporte, operaciones, comercial) van en `platform_admins` y sus propias tablas, sin `tenant_id`, en el paso 1.6. Insertar un superadministrador en `roles` sería darle un tenant, que es exactamente lo que no es.

### 3. `AuditLog`: tabla única polimórfica, append-only, con redacción por modelo

**Decisión: una sola tabla `audit_logs` por tenant, con referencia polimórfica, escrita desde el ciclo de vida del ORM (paso 0.9).**

Se descartan las alternativas por este orden:

- **Una tabla de auditoría por módulo**: `REQ-CORE-005` pide filtrar por fecha, usuario, tipo de operación y módulo en una sola pantalla. Con 53 módulos eso es una unión de 53 tablas por consulta, y cada módulo nuevo obliga a tocar la pantalla. Descartada.
- **Disparadores (*triggers*) de PostgreSQL**: capturarían incluso el SQL crudo y serían inviolables, que es tentador. Pero el disparador no conoce al actor de aplicación, ni la IP, ni el `request_id` de `INV-013`, salvo que se propaguen tres GUC más por el mismo mecanismo que el tenant — maquinaria considerable — y encarece toda migración con relleno de datos. Además, el paso 0.9 del plan ya está enunciado como *«registro automático de auditoría en el ciclo de vida del ORM»*. Descartada **para el caso general**, y anotada como la salida prevista si algún día un requisito legal exige garantía a nivel de motor sobre un subconjunto de tablas.
- **Almacén externo append-only o flujo de eventos**: añade una pieza de infraestructura y un punto de fallo para un volumen que PostgreSQL absorbe. Contradice el criterio de `ADR-010`. Descartada.

**Limitación conocida y asumida** de la vía ORM: no captura `DB::table()->update()`, ni `Model::query()->delete()` masivo, ni SQL crudo. Se compensa con (a) el test de arquitectura de 0.7.11 ampliado para prohibir DML crudo en `app/Modules/**`, y (b) la regla de que toda operación masiva pasa por un servicio que emite su propio registro. Es disciplina verificada por build, no confianza.

**Esquema:**

| Columna | Tipo | Nota |
|---------|------|------|
| `id`, `tenant_id` | `bigint` | De `tenantTableAppendOnly()` |
| `public_id` | ULID | `ADR-029`. Ver la nota de particionado más abajo |
| `occurred_at` | `TIMESTAMPTZ` | Momento del hecho, no de la escritura (un job puede registrarlo después) |
| `actor_user_id` | `bigint` nullable | FK compuesta `(tenant_id, actor_user_id) → users` |
| `actor_type` | `text` + `CHECK` | `user`, `system`, `console`, `import`, `platform`. Desambigua el `NULL` anterior |
| `auditable_type` | `text` | **Alias del *morph map*, jamás el FQCN de PHP** |
| `auditable_id` | `bigint` | Clave interna. No hay FK: es polimórfica |
| `auditable_public_id` | ULID nullable | Permite listar sin *join* y **sobrevive a la purga** de la entidad |
| `event` | `text` + `CHECK` | `created`, `updated`, `deleted`, `restored`, `read`, `exported` |
| `changes` | `jsonb` nullable | Solo atributos modificados, con redacción (ver abajo) |
| `ip_address` | `inet` nullable | Tipo nativo, no `text` |
| `user_agent` | `text` nullable | |
| `request_id` | `text` nullable | `INV-013` |
| `context` | `jsonb` nullable | Extensión por módulo sin migrar |

Cuatro decisiones dentro de la tabla:

- **`auditable_type` guarda el alias del *morph map*, no el nombre de clase.** Guardar `App\Modules\Alumnado\Domain\Student` ata el histórico de auditoría a los espacios de nombres de PHP: el día que un módulo se renombre o se extraiga a un servicio (`ADR-002` lo contempla), todo el histórico queda huérfano. Se registra `student` y un test comprueba que todo modelo auditable está en el *morph map*.
- **`changes` guarda solo los atributos que cambiaron, nunca la fila entera**, y pasa por una **lista de redacción por modelo**. Contraseñas, *tokens*, semillas TOTP y códigos de respaldo **nunca** se escriben. En los modelos marcados como categoría especial (salud, NEAE, convivencia) se registra **qué atributos cambiaron pero no sus valores**. Sin esta regla, la auditoría sería una copia en claro y sin cifrar de las tablas cifradas, y anularía por completo la separación que exige la sección 8 de `CLAUDE.md`. Es la decisión más importante de este apartado.
- **No se guarda el nombre del actor, solo su `actor_user_id`.** Un nombre desnormalizado sobreviviría a la anonimización de `ADR-004` nivel 2 y convertiría la auditoría en la puerta trasera que deja sin efecto el derecho de supresión. Resolviendo por FK, cuando la persona se anonimiza la auditoría muestra un actor anonimizado, que es el comportamiento correcto, y la cadena de imputación (mismo `id`) se conserva.
- **Inmutabilidad forzada en el motor, no por convención**: `REVOKE UPDATE, DELETE ON audit_logs FROM plataforma_app`, dejando solo `INSERT` y `SELECT`. La purga por retención (`REQ-PRIV-006`) la ejecuta el rol propietario desde una tarea de mantenimiento. Es la misma lección del bug 6 de 0.7 (`failed_jobs` con DML completo por los `GRANT` por defecto): un `REVOKE` que no se escribe explícitamente no existe.

**Particionado: no en 0.8, con disparador escrito.** `audit_logs` será la tabla más grande del sistema y su purga por retención (mínimo dos años, `REQ-CORE-005`) se resuelve mucho mejor descartando particiones que borrando millones de filas. Aun así **no se particiona ahora**: con un centro piloto el volumen no lo justifica, obliga desde el primer día a una tarea programada de creación de particiones, y complica el índice único de `public_id` (en una tabla particionada un índice único debe incluir la clave de partición). Se crea como tabla plana con `occurred_at` `NOT NULL` liderando los índices, es decir, ya con la forma que necesita la conversión. **Disparador de revisión**: cuando `audit_logs` supere los 50 millones de filas o la purga de una ventana de retención exceda una ventana de mantenimiento razonable, se convierte a particiones mensuales por rango sobre `occurred_at`; esa conversión redefine el índice único de `public_id` como índice por partición y **necesita ADR propio**. Queda escrito para que se descubra en una revisión, no en una noche de incidencia.

**Auditoría de plataforma**: fuera de esta tabla. `REQ-BO-007` exige registro *«independiente e inmutable […] no mezclado con la auditoría de los tenants»*, y `ADR-033 §7` ya reservó `admin_action_logs` como tabla de plataforma. La crea el paso 1.6, no 0.8.

Índices: `(tenant_id, occurred_at DESC)`, `(tenant_id, auditable_type, auditable_id, occurred_at DESC)`, `(tenant_id, actor_user_id, occurred_at DESC)`. Nada más por ahora: un GIN sobre `changes` se añade cuando exista una consulta que lo pida.

### 4. `AcademicYear`: del tenant, y obligatorio o ausente — nunca nullable

**`academic_years` es una tabla de tenant normal** (`tenantTable()`): cada centro define sus propios cursos, con su código, sus fechas y su estado. No es catálogo compartido: las fechas de inicio y fin las fija cada centro.

Columnas: `public_id`, `code` (`2026-2027`), `starts_on`, `ends_on` (`date`), `status` con `CHECK IN ('planificacion','activo','cerrado','archivado')` (`REQ-CURSO-001`).

Restricciones:

- `UNIQUE (tenant_id, code) WHERE deleted_at IS NULL`.
- **`UNIQUE (tenant_id, status) WHERE status IN ('activo','planificacion') AND deleted_at IS NULL`**: como mucho un curso activo y uno en planificación por centro, que es literalmente lo que dice `REQ-CURSO-001`. Se hace con índice único parcial y no con validación de aplicación porque es una invariante de datos, no una regla de flujo: una condición de carrera entre dos peticiones simultáneas la rompería sin que el servidor se enterara.
- `CHECK (ends_on > starts_on)`.
- **No se añade restricción de exclusión contra solapamiento de fechas.** Requeriría la extensión `btree_gist` y hay casos legítimos de solape en los bordes (programas de verano, curso que cierra el 31 de agosto). Se valida en el servicio (`INV-010`) y se anota como opción para 1.10, que es quien tendrá los casos reales.

**Cómo lo referencian las tablas de negocio — la regla que hay que respetar en los 53 módulos:**

> `academic_year_id` es **`NOT NULL`** o **no existe la columna**. Se prohíbe `academic_year_id` nullable.

| Categoría | Ejemplos | Columna |
|-----------|----------|---------|
| Depende del curso | matrícula, grupo, horario, calificación, asistencia, tarifa, beca, plaza, campaña de renovación | `academic_year_id NOT NULL` |
| No depende del curso | `people`, `users`, `roles`, `permission_role`, `module_subscriptions`, `audit_logs`, unidad familiar, configuración del centro | **sin columna** |

El motivo es que una columna nullable introduce un tercer estado —«no pertenece a ningún curso»— cuyo significado nadie define, que obliga a escribir `AND (academic_year_id = ? OR academic_year_id IS NULL)` en cada consulta durante tres años, y que en la práctica acaba siendo el vertedero de las filas mal creadas. Ante la duda, la entidad **no** es del curso: su matrícula sí lo es. Es la sección 16.3 regla 4 llevada al esquema.

La referencia se declara siempre como clave foránea compuesta `(tenant_id, academic_year_id) REFERENCES academic_years (tenant_id, id)` (`ADR-033 §6`). Para que esto no dependa de que alguien se acuerde, `TenantMigration` gana un ayudante `tenantForeignId()` que emite columna, índice y clave compuesta de una vez (ver punto 7).

**No hay *global scope* de curso académico.** A diferencia del tenant, la consulta entre cursos es legítima y frecuente: `REQ-CURSO-001` exige un selector de curso y consulta histórica en solo lectura, y `REQ-CURSO-002` (rollover) lee el curso actual y escribe en el siguiente en la misma operación. Un ámbito global aquí obligaría a desactivarlo continuamente, que es precisamente el hábito que `ADR-033` quiere erradicar. El curso activo se resuelve en un contexto explícito (`AcademicYearContext`) que construye el paso 1.10; 0.8 no lo crea. Se deja escrito para que nadie lo «mejore» añadiendo el ámbito automático.

### 5. `ModuleSubscription`: tabla de tenant, catálogo en código, fallo en cerrado

Dos tablas:

- **`modules`** — **referencia compartida**, sin `tenant_id`, `GRANT SELECT` para `plataforma_app`. Columnas: `code`, `name_key`, `phase`, `retired_at`. Es el catálogo de lo que existe en la plataforma.
- **`module_subscriptions`** — **tabla de tenant** (`tenantTable()`), con RLS. Columnas: `public_id`, `module_code` (FK a `modules.code`), `enabled` (`boolean NOT NULL`), `enabled_at`, `disabled_at`, `reason` (`text`, motivo obligatorio en el flujo de `REQ-BO-002`), `settings` (`jsonb`, configuración del módulo para ese centro). `UNIQUE (tenant_id, module_code) WHERE deleted_at IS NULL`.

Decisiones:

- **Es dato del tenant, no de la plataforma**, aunque la escriba el Super Administrador desde el backoffice. Un centro tiene que poder leer qué módulos tiene activos (`REQ-CORE-002` deja al Administrador de Centro activar y desactivar los contratados) y el control de `RMOD-009` se ejecuta en cada petición del tenant. Con RLS, un centro nunca ve las activaciones de otro. El backoffice escribe por la conexión `pgsql_platform` de `ADR-033 §5`, que es exactamente el caso de uso para el que existe ese rol.
- **Desactivar es `enabled = false`, nunca borrar** (`RMOD-003`, `RMOD-004`: *soft-disable*, los datos se preservan y la reactivación los restaura). La fila registra el contrato y su historia. `deleted_at` existe porque `tenantTable()` lo pone y porque `INV-005` lo exige, pero **no forma parte del ciclo de vida del módulo**: los índices únicos son parciales sobre `deleted_at IS NULL` por seguridad y nada más.
- **El catálogo de módulos vive en el código**, igual que el de permisos, y se materializa en `modules` con el mismo comando idempotente. Un módulo retirado se marca `retired_at`; borrar la fila arrastraría las suscripciones históricas de todos los centros.
- **Las dependencias entre módulos (`RMOD-006`) viven solo en el código**, en el `ServiceProvider` de cada módulo. No se desnormalizan en la tabla: una copia en base de datos de algo que declara el código se desincroniza en el primer despliegue en que alguien olvide correr el comando, y el fallo sería silencioso.
- **La comprobación falla en cerrado**: ausencia de fila equivale a módulo desactivado. Es la misma filosofía que `TenantContext::tenantId()` de `ADR-033 §3`. El *middleware* `EnsureModuleEnabled` que devuelve el 403 de `RMOD-009` es código de aplicación y lo escribe 1.1/1.6; su contrato queda fijado aquí.
- **La caché de suscripciones lleva el prefijo de tenant** de `ADR-033 §9` y se invalida en la escritura, además de tener TTL corto. Es exactamente el problema abierto del [issue #7](https://github.com/pirexia/plataforma-educativa/issues/7) (la caché de resolución de tenant no se invalida al suspender): se resuelve en el mismo sitio y de la misma manera, no se reinventa.

### 6. Borrado lógico y campos de autoría

**`INV-004` + `INV-005` se cumplen desde el helper, no desde cada migración.**

- `tenantTable()` ya añade `created_at`, `updated_at`, `deleted_at`. **0.8 le añade `created_by` y `updated_by`**, `bigint` nullable con clave foránea compuesta a `users`. Son nullable porque hay actores sin usuario: consola, jobs del sistema, seeders, importaciones.
- **No hay `deleted_by`.** `INV-005` no lo pide y `audit_logs` ya registra quién borró con más contexto (IP, `request_id`, momento). `created_by`/`updated_by` existen porque `INV-005` los exige y porque son los dos que se pintan en pantalla constantemente; duplicar más allá de eso sería replicar el registro de auditoría en 200 tablas.
- **`TenantModel` pasa a usar `SoftDeletes` por defecto**, junto con un *trait* `RecordsAuthorship` que rellena `created_by`/`updated_by` desde el usuario autenticado. Razón: hoy `tenantTable()` crea `deleted_at` en todas las tablas pero `TenantModel` **no** usa `SoftDeletes`; un `delete()` sobre cualquier modelo de negocio borra la fila físicamente dejando una columna `deleted_at` de adorno. Es pérdida de datos silenciosa y contradice `INV-004`. Un modelo que de verdad necesite borrado físico lo declara y figura en un registro explícito con test de arquitectura, igual que el registro de tablas compartidas de `ADR-033 §7`.
- **Toda restricción de unicidad sobre una tabla con borrado lógico es parcial** (`WHERE deleted_at IS NULL`). Sin esto, una persona dada de baja bloquea para siempre el alta de otra con el mismo documento, y el centro descubre el problema con la familia delante. Se comprueba en el test de esquema, no en la revisión.
  Dos excepciones que **no** son parciales: `UNIQUE (tenant_id, id)` (es el destino de las claves foráneas compuestas y debe cubrir también las filas borradas) y `UNIQUE (public_id)` (un identificador público no se reutiliza jamás).
- **Modelos append-only**: `audit_logs` no extiende `TenantModel`. Extiende `AppendOnlyModel`, que lanza excepción en `update()` y `delete()`. Es la contraparte en PHP del `REVOKE` del motor: el error salta en desarrollo con un mensaje claro en vez de como un fallo de privilegios en producción. Servirá igual a las demás tablas append-only que anuncia la sección 16.3 regla 6 (fichajes, consentimientos, firmas).

### 7. Qué usa `tenantTable()` y qué no

| Tabla | Helper | Motivo |
|-------|--------|--------|
| `academic_years` | `tenantTable()` | Tabla de tenant ordinaria |
| `people` | `tenantTable()` | |
| `users` | `tenantTable()` | Se **rehace** la migración del starter kit de Laravel: hoy no tiene `tenant_id` y está en la lista temporal `framework` de `config/tenancy.php` |
| `roles` | `tenantTable()` | |
| `role_user` | `tenantTable()` | Pivote con `tenant_id` y FK compuestas a ambos lados |
| `permission_role` | `tenantTable()` | Los roles son del tenant, luego las concesiones también |
| `module_subscriptions` | `tenantTable()` | |
| `audit_logs` | **`tenantTableAppendOnly()`** (nuevo) | Sin `deleted_at` (borrado lógico en tabla append-only no significa nada), sin `created_by`/`updated_by` (tiene `actor_user_id`), y con `REVOKE UPDATE, DELETE` |
| `permissions` | **Ninguno** | Catálogo de referencia: sin `tenant_id`, sin RLS, `GRANT SELECT`. Categoría `reference` de `ADR-033 §7` |
| `modules` | **Ninguno** | Ídem |
| `sessions`, `password_reset_tokens` | **Ninguno** | Infraestructura del framework. Ver el hallazgo del punto 8 |

`TenantMigration` gana tres cosas en 0.8, todas por el mismo motivo por el que existe: que la regla se aplique en un sitio y no en 200 migraciones.

1. `created_by` / `updated_by` dentro de `tenantTable()`.
2. `tenantTableAppendOnly()`, que comparte el núcleo (`id`, `tenant_id`, `DEFAULT`, RLS `ENABLE`+`FORCE`, política, `UNIQUE (tenant_id, id)`) y cambia lo demás. Se elige un **método con nombre propio** en lugar de un parámetro booleano en `tenantTable()`: el nombre declara la intención, es localizable con `grep`, y un booleano invita al segundo y al tercero.
3. `tenantForeignId(string $column, string $referencedTable)`, que emite la columna, el índice y la clave foránea compuesta `(tenant_id, columna) REFERENCES tabla (tenant_id, id)` de `ADR-033 §6` en una sola llamada. Hoy esa regla depende de que cada migración la recuerde; en cuanto haya cincuenta tablas, alguna no lo hará.

### 8. Hallazgo: `password_reset_tokens` es incorrecta en multi-tenant

La migración del starter kit define `password_reset_tokens` con `email` como **clave primaria**. Con `users.email` único **por tenant** (punto 1), dos centros pueden tener legítimamente `ana@example.com`. Consecuencias:

- La segunda solicitud de restablecimiento sobrescribe el token de la primera: la familia del otro centro recibe un enlace que ya no funciona.
- Peor: el repositorio de tokens de Laravel busca por correo, sin tenant. Un token emitido en el centro A es aceptable en el formulario del centro B para la cuenta homónima. **Es una vía de toma de control de cuenta entre tenants.**

No es explotable hoy porque no existe todavía el flujo de recuperación de contraseña (`REQ-AUTH-001`, paso 1.2). Se corrige en 0.8, que es cuando `users` se rehace: la tabla pasa a llevar `tenant_id` y clave primaria `(tenant_id, email)`, con un `PasswordBrokerRepository` propio que incluya el tenant en la búsqueda. Escribirlo aquí, con su test de regresión en 1.2, evita que el paso 1.2 lo herede como si fuera correcto.

En la misma línea, y **sin resolver en 0.8**: `sessions` no tiene `tenant_id`. No hay fuga (el identificador de sesión es aleatorio y `ADR-033 §2` ya reverifica el tenant de la sesión en cada petición), pero `REQ-AUTH-005` pide listar y revocar las sesiones activas de un usuario, y eso necesitará la columna. Se anota para 1.2; añadirla es expand puro.

---

## Motivo

El hilo que une las siete decisiones es que **las siete son plantillas**, no tablas. El coste de equivocarse no se paga en 0.8 sino en la fase 2, multiplicado por el número de módulos que ya hayan copiado el patrón, y en una base de datos que para entonces contiene alumnos reales.

- **`Person`/`User` separadas** no es una preferencia de modelado: es la única forma de que la profesora que además es madre no exista dos veces en el censo. Con dos filas, sus dos correos divergen, su consentimiento de imagen se registra en una y se comprueba en la otra, y el ejercicio de un derecho GDPR anonimiza la mitad de sus datos. La sección 16.3 ya lo obliga; lo que aporta este ADR es la nulabilidad, la unicidad y la separación entre correo de contacto y credencial, que es donde el patrón se rompe en la práctica.
- **Crear el esquema de permisos ahora y el resolutor en 1.5** responde a que entre 1.1 y 1.5 hay cuatro pasos que tienen que cumplir `INV-002` con algo. La alternativa realista no es «esperar a 1.5», es «autorizar por código de rol escrito a mano y migrarlo después», que significa tocar la tabla más sensible del sistema con el piloto ya dentro. Cinco tablas y dos booleanos hoy cuestan medio día; la migración en caliente cuesta una ventana de mantenimiento y un riesgo de que un rol se quede sin permisos en producción.
- **La redacción por modelo en `audit_logs`** es la decisión con más consecuencias legales del ADR. `INV-003` («valores antes/después») y la regla de datos de categoría especial en tablas separadas y cifradas son contradictorias si se leen literalmente: cumplir la primera sin matices produce una copia en claro de la segunda. Se resuelve registrando *qué* cambió sin registrar *qué valor*, que satisface la finalidad de la auditoría (trazabilidad e imputación) sin duplicar el dato protegido.
- **La prohibición de `academic_year_id` nullable** parece un detalle y es lo contrario. Es la diferencia entre que las consultas de la fase 1 tengan un predicado o tengan dos, durante tres años y en cincuenta tablas. Y las columnas nullables sin semántica definida no se quedan vacías: se llenan de las filas que alguien creó sin saber a qué curso pertenecían.
- **El registro de módulos y de permisos en código, materializado en tabla**, es el punto medio entre integridad referencial y fuente única de verdad. `INV-007` exige que cada módulo declare lo suyo; la clave foránea exige que la fila exista. Un comando idempotente en el despliegue cierra la distancia sin que nadie mantenga un fichero a mano.
- **`SoftDeletes` por defecto en `TenantModel`** corrige un desajuste que ya existe hoy en el repositorio y que produciría pérdida de datos silenciosa en la primera entidad de negocio que se borre. Es exactamente el mismo argumento de `ADR-033`: cuando el sistema se rompe, tiene que dejar de funcionar de forma ruidosa, no seguir funcionando de forma permisiva.

Y el criterio transversal, el mismo de `ADR-033`: donde ha habido que elegir entre lo óptimo y lo reversible, se ha elegido lo reversible. Por eso `audit_logs` no se particiona todavía pero se crea con la forma que la conversión necesitará; por eso la herencia de roles se difiere (una columna nullable es expand puro); y por eso las restricciones que **no** son reversibles —`tenant_id`, claves compuestas, nulabilidad de `academic_year_id`, `person_id NOT NULL`— se deciden aquí y no más tarde.

---

## Consecuencias

**A favor**

- Las siete entidades núcleo quedan escritas y con ellas el patrón que copiarán los 53 módulos, sin que ninguno tenga que volver a decidir nulabilidad, unicidad parcial, autoría ni claves compuestas.
- `INV-003`, `INV-004` y `INV-005` dejan de depender de la migración: los cumple el helper. Una tabla nueva que se salte alguno rompe el test de esquema, no la producción.
- `INV-002` tiene almacenamiento desde 1.1. El paso 1.5 enriquece código y no migra esquema.
- La auditoría vale para los 53 módulos desde el primer día y no duplica datos de categoría especial.
- Una vulnerabilidad real de toma de control de cuenta entre tenants (`password_reset_tokens`) se corrige antes de que exista el flujo que la haría explotable.
- El desajuste `deleted_at` sin `SoftDeletes` se corrige antes de que haya una sola entidad de negocio.

**En contra, y se asume**

- Se **rehace** la migración `users` del starter kit de Laravel. Es aceptable exactamente ahora, en fase 0 y sin datos reales; en cualquier otro momento sería una migración con parada.
- `TenantMigration` crece de un método a tres. Es más superficie que mantener, y es el precio de que la regla viva en un sitio.
- Dos tablas de referencia (`permissions`, `modules`) exigen un comando idempotente en el despliegue. Si no se ejecuta, un permiso nuevo no existe y su endpoint deniega — falla en cerrado, que es lo correcto, pero hay que documentarlo en `SYSADMIN.md` y en el procedimiento de entrega.
- `created_by`/`updated_by` añaden dos `bigint` y dos claves compuestas por tabla. Redundancia deliberada con `audit_logs`, acotada a esas dos columnas.
- `audit_logs` sin particionar tiene fecha de caducidad conocida. Queda el disparador escrito y el ADR pendiente; ignorarlo no lo hace desaparecer.
- Los índices únicos parciales no se pueden usar como destino de clave foránea. Ninguna clave foránea del núcleo apunta a ellos (todas van contra `(tenant_id, id)`), pero es una restricción a recordar en los módulos.

**Reversibilidad**

- **Alta**: número de columnas de `people`, `roles.parent_role_id` si 1.5 quiere herencia, índices, `settings` de `module_subscriptions`, columna `tenant_id` en `sessions`. Todo expand.
- **Media**: particionado de `audit_logs` (migración de una tabla grande, con ventana, pero acotada a una tabla).
- **Baja, y por eso se decide ahora**: `users.person_id NOT NULL`, la unicidad por tenant del correo, la nulabilidad prohibida de `academic_year_id`, la forma polimórfica de `audit_logs` y las claves foráneas compuestas. Cambiar cualquiera de estas con datos de un centro dentro es una migración de las que se anuncian a los clientes.

---

## Alternativas descartadas y por qué

- **`Person` y `User` fusionadas en una tabla** (el `users` por defecto de Laravel con los datos personales dentro): lo prohíbe la sección 16.3 regla 1, y con razón. Obliga a crear una cuenta a un alumno de tres años y a un tutor legal que nunca entrará al portal, solo para poder guardar su nombre, y duplica a la persona que tiene dos papeles en el centro.
- **`users.person_id` nullable** («cuentas sin persona»): abre un estado sin significado. Un cliente de API futuro no es un usuario y tendrá su propia entidad.
- **`Student`, `Guardian` y `Employee` como valores de un campo `tipo` en `people`**: impide que una persona sea dos cosas a la vez, que es el caso que motiva toda la separación, y mete los campos específicos de cada papel en una tabla común llena de columnas nullables.
- **Correo único a nivel de plataforma**: rompe `RMT-009` (la misma persona en dos centros) y crea una relación entre tenants donde `ADR-001` y `ADR-011` exigen que no haya ninguna.
- **Permisos como constantes de código sin tabla**: más simple, pero deja `permission_role` sin integridad referencial, impide la vista previa de `RPERM-009` sin recorrer el código, y no da sitio a la traducción de las descripciones (`INV-009`).
- **Permisos como tabla del tenant**: cada centro tendría su propio catálogo de permisos. Multiplica el catálogo por el número de centros, hace imposible desplegar un permiso nuevo de un módulo y no aporta nada: lo que el centro personaliza son los roles, no el repertorio de acciones.
- **Una tabla de auditoría por módulo**: convierte la pantalla de `REQ-CORE-005` en una unión de 53 tablas y obliga a tocarla con cada módulo nuevo.
- **Auditoría por disparadores de PostgreSQL**: inviolable y ciega. No conoce actor de aplicación, IP ni `request_id` sin propagar tres GUC más, y encarece toda migración con relleno. Se conserva anotada como salida si un requisito legal exige garantía a nivel de motor sobre un subconjunto de tablas.
- **Auditoría en almacén externo append-only**: una pieza de infraestructura y un punto de fallo más para un volumen que PostgreSQL absorbe. Mismo criterio que `ADR-010` con el motor de búsqueda.
- **`audit_logs` particionada desde 0.8**: correcta a tres años, prematura a cero filas. Obliga a una tarea de creación de particiones y a un índice único por partición desde el día uno. Se prefiere la forma preparada más un disparador de revisión escrito.
- **Guardar el FQCN de PHP en `auditable_type`**: ata el histórico de auditoría a los espacios de nombres. Un renombrado de módulo —previsto por `ADR-002`— dejaría huérfano el histórico.
- **Guardar el nombre del actor desnormalizado en `audit_logs`**: cómodo para pintar listados y una puerta trasera al derecho de supresión de `ADR-004`.
- **`academic_year_id` nullable en todas las tablas «por flexibilidad»**: introduce un tercer estado sin semántica y duplica el predicado de todas las consultas durante tres años.
- **`academic_years` como catálogo compartido de la plataforma**: las fechas de inicio y fin las fija cada centro y `REQ-CURSO-005` permite cerrar y archivar por centro. Compartirlo obligaría a que todos los centros cerraran el curso el mismo día.
- **Ámbito global de curso académico en el ORM**, en paralelo al de tenant: habría que desactivarlo en el selector de curso, en la consulta histórica y en todo el rollover. Fomentaría justo el hábito (`withoutGlobalScope`) que `ADR-033 §4` prohíbe.
- **`module_subscriptions` como tabla de plataforma sin `tenant_id`**: el control de `RMOD-009` se ejecuta en cada petición del tenant y el Administrador de Centro debe poder leer y cambiar sus módulos contratados (`REQ-CORE-002`). Sacarla del tenant obligaría a consultarla por la conexión de plataforma en el camino caliente de todas las peticiones.
- **Dependencias entre módulos desnormalizadas en la tabla `modules`**: se desincroniza en el primer despliegue en que no se ejecute el comando, y de forma silenciosa.
- **`deleted_by` en todas las tablas**: `audit_logs` ya lo cubre con más contexto. Replicar el registro de auditoría en 200 tablas es el camino a que ninguno de los dos sea de fiar.
- **Parámetro booleano `softDeletes: false` en `tenantTable()`** en vez de un método con nombre propio: el primer booleano invita al segundo y al tercero, y una llamada con tres banderas no dice qué clase de tabla se está creando.

---

## Preguntas abiertas

**`OPEN-12` · Qué ocurre con los datos personales ya escritos en `audit_logs.changes` cuando una persona ejerce el derecho de supresión.**

`ADR-004` define tres niveles de borrado y deja a `REQ-PRIV-006` el catálogo de retención por entidad, pero **no resuelve el conflicto** entre dos reglas que este ADR pone frente a frente:

- `audit_logs` es append-only e inmutable por diseño (`REQ-CORE-005`, `REQ-BO-007`), con `REVOKE UPDATE, DELETE` en el motor.
- La anonimización de `ADR-004` nivel 2 exige que los identificadores personales dejen de ser reversibles.

Si una fila de auditoría registró el cambio del nombre o del documento de una persona, ese valor sigue en `changes` después de anonimizarla. Redactarlo a posteriori viola la inmutabilidad; dejarlo viola la supresión.

Opciones sobre la mesa, ninguna elegida:

1. **Excluir de `changes` los atributos identificativos** de los modelos tipo persona, registrando solo qué atributo cambió. Es lo más simple y encaja con la política de redacción ya decidida para categoría especial, a costa de perder trazabilidad sobre el dato que más a menudo se quiere auditar.
2. **Cifrado por sujeto con destrucción de clave** (*crypto-shredding*): `changes` se cifra con una clave por persona que se destruye al anonimizar. Conserva la inmutabilidad formal (la fila no se toca) y hace irrecuperable el contenido. Añade gestión de claves y complica la consulta.
3. **Redacción dirigida por el rol propietario**, con su propio registro en `admin_action_logs`. Rompe la inmutabilidad de forma controlada y auditada.

**No bloquea el paso 0.8**: la decisión de guardar `changes` como `jsonb` con política de redacción por modelo mantiene abiertas las tres. **Sí bloquea la entrada del primer dato real** y debe resolverse con `REQ-PRIV-006`, en un ADR propio, antes del hito H0.

**`OPEN-13` · Lista definitiva de columnas de `people` y su base legal por campo.**

Este ADR fija un mínimo defendible por minimización (nombre, apellidos, fecha de nacimiento, documento, contacto, idioma) y deja fuera fotografía, sexo, nacionalidad y dirección postal por no haber hoy catálogo de bases legales. La lista final es una decisión de protección de datos, no de arquitectura, y le corresponde a `REQ-PRIV-006` junto con el paso 1.1. Añadir columnas después es expand y no bloquea 0.8; **no** se debe adelantar ninguna «por si acaso».

---

## Plan de implementación

Doce subpasos, al estilo de los once de `ADR-033`, para ejecutar de uno en uno con tests y sin volver a decidir nada. Cada commit referencia `[REQ-0.8]` y el `INV`/`REQ` que cubre.

- **0.8.1 · Ampliar `TenantMigration`.** `created_by`/`updated_by` (nullable, FK compuesta a `users`, añadida en 0.8.4 cuando `users` exista) dentro de `tenantTable()`; nuevo `tenantTableAppendOnly()`; nuevo `tenantForeignId(string $column, string $referencedTable)`. Tests unitarios sobre tabla de prueba (ojo al bug 3 de 0.7: crear y borrar la tabla por la **misma** conexión que la usa, o el test se queda esperando el *lock*). Sin migraciones de negocio todavía.
- **0.8.2 · `academic_years`.** `tenantTable()`, columnas y `CHECK` de estado, los tres índices únicos parciales del punto 4. Test: no se pueden crear dos cursos `activo` en el mismo tenant; sí uno `activo` y uno `planificacion`; sí dos `activo` en tenants distintos.
- **0.8.3 · `people`.** `tenantTable()`, columnas del punto 1, unicidad parcial de documento. Test: dos personas con el mismo documento en tenants distintos conviven; en el mismo tenant no; tras borrado lógico se puede volver a dar de alta el mismo documento.
- **0.8.4 · `users` rehecha + `password_reset_tokens` con tenant.** Migración que sustituye la del starter kit: `tenantTable()`, `person_id NOT NULL` con FK compuesta, `email` único parcial por tenant, columnas de `REQ-CORE-003` (estado, `email_verified_at`, `password`, `remember_token`). `password_reset_tokens` con `tenant_id` y clave primaria `(tenant_id, email)` (punto 8). Retirar `users` de la categoría temporal `framework` de `config/tenancy.php`. Cerrar aquí la FK de `created_by`/`updated_by` de 0.8.1. Test de regresión del punto 8: un token emitido en el tenant A no sirve en el tenant B.
- **0.8.5 · `roles` y `role_user`.** `tenantTable()` en ambas, `CHECK` de `name_key` XOR `name`, `mfa_required`, `special_data_access`, `is_system`. Test: `role_user` con un rol de otro tenant es rechazada por la FK compuesta (test 7 de `ADR-033 §10` aplicado a tablas reales).
- **0.8.6 · `permissions` (referencia) y `permission_role`.** `permissions` sin `tenant_id`, con `GRANT SELECT` a `plataforma_app` y `REVOKE` del resto — replicando explícitamente la lección del bug 6 de 0.7. `permission_role` con `tenantTable()`, `effect` y `scope` con sus `CHECK`. Registrar `permissions` en la categoría `reference` de `config/tenancy.php`. Test: `plataforma_app` no puede escribir en `permissions`.
- **0.8.7 · `modules` (referencia) y `module_subscriptions`.** Mismo patrón de privilegios. `UNIQUE (tenant_id, module_code)` parcial. Test: la ausencia de fila se lee como desactivado (fallo en cerrado); un tenant no ve las suscripciones de otro.
- **0.8.8 · `audit_logs`.** `tenantTableAppendOnly()`, todas las columnas del punto 3, los tres índices, `REVOKE UPDATE, DELETE ON audit_logs FROM plataforma_app`. Test: `INSERT` funciona; `UPDATE` y `DELETE` son rechazados **por el motor**, no por PHP.
- **0.8.9 · Modelos y *traits*.** `SoftDeletes` + `RecordsAuthorship` en `TenantModel`; registro de modelos con borrado físico permitido, vacío de momento; `AppendOnlyModel`; modelos `Person`, `User` (reemplaza a `App\Models\User`, que pasa a extender `TenantModel`), `Role`, `Permission`, `ModuleSubscription`, `AcademicYear`, `AuditLog`; *morph map* con los alias estables del punto 3. Test de arquitectura: todo modelo auditable está en el *morph map*.
- **0.8.10 · Ampliar los tests de esquema de 0.7.11.** Añadir a la batería: (a) toda restricción única sobre tabla con `deleted_at` es parcial, salvo `(tenant_id, id)` y `public_id`; (b) ninguna columna `academic_year_id` es nullable; (c) toda columna `*_id` que referencie una tabla de tenant tiene clave foránea **compuesta**; (d) las tablas de referencia no tienen privilegios de escritura para `plataforma_app`. Son los tests que impiden que el patrón se erosione módulo a módulo, que es lo que hicieron los tests 8-10 de `ADR-033`.
- **0.8.11 · Comando `platform:sync-registry`.** Idempotente, ejecutado por el rol propietario, materializa en `permissions` y `modules` lo que declaran los `ServiceProvider` de los módulos. Marca `retired_at` lo que desaparece del código; no borra nunca. Documentar en `SYSADMIN.md` como paso obligatorio del despliegue. Test: dos ejecuciones seguidas no producen cambios; retirar un módulo del código marca `retired_at` y conserva las suscripciones.
- **0.8.12 · Medición del sobrecoste de RLS.** `ADR-033` lo dejó comprometido para este paso: *«se mide en el paso 0.8, no se da por bueno»*. Con las tablas del núcleo pobladas por el generador sintético (`REQ-SEED`, nunca datos reales — `ADR-030`), comparar el mismo listado con y sin políticas activas y anotar el resultado en `docs/historial/0.8-modelo-de-datos-nucleo.md`. Si supera el 1-3% previsto, es un hallazgo que hay que registrar como issue, no una cifra que se ajusta a la expectativa.

**Revisiones obligatorias antes de mezclar** (`CLAUDE.md` §6): `db-reviewer` sobre todas las migraciones y `security-reviewer` sobre el conjunto, con atención expresa a los `GRANT`/`REVOKE` de las tablas de referencia y de `audit_logs`, y al test de regresión de `password_reset_tokens`.
