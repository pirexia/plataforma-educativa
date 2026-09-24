# REQ-CORE · Módulo núcleo / plataforma base · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-CORE` |
| Prioridad | MUST |
| Fase | 1 · Bloque A · **paso 1.1** |
| Depende de | 0.7 (aislamiento multi-tenant, `ADR-033`), 0.8 (modelo de datos núcleo, `ADR-034`), 0.9 (auditoría `ADR-035`/`ADR-036`, i18n) |
| Estado | **IMPLEMENTADO** — API completa, revisión independiente hecha, sin pantallas (`OPEN-CORE-02`, se completan en 1.8) |
| Módulo (código) | `core` · `apps/api/app/Modules/Core` · `apps/web/src/modules/core` |

> Fuente de verdad: sección 5.1 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` (`REQ-CORE-001` a `REQ-CORE-008`). Este documento **no** reabre lo decidido en `ADR-033`, `ADR-034`, `ADR-035` ni `ADR-036`.

> **Paso 1.8 (`REQ-CORE-008`, *layout*, navegación y panel de inicio): §12, PROPUESTO** (2026-09-23), pendiente de aprobación. Las secciones §0-§11 son las de 1.1 y **no se reabren**; §12 se añade detrás, mismo criterio que `REQ-BO/funcional.md §15.x` para sub-pasos sucesivos del mismo módulo.

---

## 0. Resumen de la frontera del paso 1.1

`REQ-CORE` en el documento de requisitos son ocho sub-requisitos que abarcan desde el alta de centros hasta el dashboard. El paso 1.1 **no** los implementa todos: implementa la parte que convierte al núcleo ya construido (tablas de 0.8, auditoría de 0.9) en un módulo con superficie HTTP real.

**Entra en 1.1:**

| Sub-requisito | Qué parte |
|---------------|-----------|
| `REQ-CORE-002` | Configuración del propio centro: regional, fiscal y branding. Lectura de módulos contratados. |
| `REQ-CORE-003` | CRUD de usuarios del centro, invitación por correo con enlace caducable, importación masiva desde CSV con validación previa e informe de errores, asignación de roles. |
| `REQ-CORE-004` | Solo la parte de **almacenamiento y consulta**: listado de roles predefinidos y del catálogo de permisos, asignación de roles a usuarios, y el resolutor **provisional** que `ADR-034 §2` fija (lee `effect`, ignora `scope`). |
| `REQ-CORE-005` | API de consulta y filtrado del registro de auditoría, y exportación a CSV. |
| `REQ-CORE-006` | Idioma preferido por usuario e idiomas activos/por defecto del tenant, expuestos en la API. |
| `RMOD-008`/`RMOD-009` | *Middleware* `EnsureModuleEnabled` (contrato fijado en `ADR-034 §5`), implementado y registrado como alias `module-enabled` (`apps/api/bootstrap/app.php`). Sin consumidores todavía: los únicos módulos que existen hasta ahora, `Core` y `Auth`, están exentos por diseño. |

**No entra en 1.1** — cada exclusión con su motivo en §1.

---

## 1. Alcance: qué queda fuera y por qué

### 1.1 `REQ-CORE-001` · Ciclo de vida de tenants → paso **1.6** (`REQ-BO`)

Crear, suspender, reactivar y eliminar un tenant son operaciones **de plataforma**, no de centro: las ejecuta el Super Administrador desde un backoffice con dominio y aplicación separados (§11.1 del documento de requisitos, `REQ-BO`), escriben por la conexión `pgsql_platform` (`ADR-033 §5`) y se auditan en `admin_action_logs`, que no existe hasta 1.6 (`ADR-036`).

Consecuencia operativa que hay que asumir: **1.1 no puede dar de alta un centro por API**. El aprovisionamiento inicial lo hace un comando de consola (§4.7), no un endpoint. Esto es deliberado: exponer el alta de tenants por HTTP antes de que exista el backoffice y su registro de auditoría de plataforma sería crear una operación crítica sin trazabilidad.

Lo que sí entra de `REQ-CORE-001` es la parte que el propio centro configura sobre sí mismo (idiomas, zona horaria, moneda, datos fiscales, comunidad autónoma, logo, paleta), porque `REQ-CORE-001` la enumera pero `REQ-CORE-002` la asigna al Administrador de Centro.

### 1.2 Dominio personalizado y certificado SSL → **1.6 + infraestructura**

`REQ-CORE-002` pide configurarlos y `RUX-DOM-002` a `RUX-DOM-006` los detallan (validación de propiedad del dominio, emisión y renovación automática de certificados, alerta ante fallo). Nada de eso es código de aplicación: es Traefik/ACME más DNS, y `config/tenancy.php` ya deja escrito que requiere una columna en `tenants` y una gestión de certificados «no decidida todavía». Además `OPEN-08` (dominio real y DNS con comodín, paso 0.10b) sigue abierta y **bloquea incluso el subdominio por defecto** de `RUX-DOM-001`.

Especificar aquí un formulario de dominio personalizado sería escribir sobre una infraestructura que no existe. Se difiere entero.

### 1.3 `REQ-CORE-004` · Resolutor de permisos granulares → paso **1.5**

`ADR-034 §2` ya lo decidió: el esquema completo en 0.8, la lógica en 1.5. Entre 1.1 y 1.5 rige el **resolutor provisional**: lee `permission_role.effect` y **ignora `permission_role.scope`**, equivalente a tratar todo ámbito como `all`.

Esto tiene una consecuencia de seguridad que hay que respetar al implementar 1.1 y que se detalla en `permisos.md` §5: **en 1.1 no se concede ningún permiso con ámbito distinto de `todos`**. Conceder `usuario.leer` con ámbito `propios` produciría, con el resolutor provisional, acceso a **todos** los usuarios del centro. Los endpoints de autoservicio (`/me`) no se autorizan por permiso sino por identidad del sujeto.

También quedan en 1.5: creación y edición de roles personalizados (`RPERM-005`), clonación (`RPERM-006`), concesión y revocación de permisos sobre un rol, permisos condicionales (`RPERM-008`) y vista previa de permisos efectivos (`RPERM-009`). En 1.1 los roles y el catálogo de permisos son **solo lectura**; lo único que se escribe es la relación `role_user` (asignar y retirar roles a un usuario).

### 1.4 Autenticación y sesiones → pasos **1.2**, **1.3**, **1.4**

1.1 crea el usuario y su credencial (`users.email`, `users.password`), pero **no implementa ningún flujo de acceso**: ni login local, ni recuperación de contraseña, ni política de contraseñas, ni bloqueo por intentos, ni MFA, ni SSO.

Frontera concreta y sus consecuencias:

- **La contraseña no se establece en 1.1.** Un usuario creado en 1.1 queda con `status = 'pendiente'` y un hash aleatorio no utilizable (`users.password` es `NOT NULL`). Quien la establece es el flujo de canje de la invitación, que pertenece a `REQ-AUTH-001` (1.2) porque es quien define la política de contraseñas.
- **1.1 emite invitaciones; 1.2 las canjea.** 1.1 crea, reenvía, revoca y caduca la invitación; el endpoint que consume el token, fija la contraseña y pasa el usuario a `activo` lo entrega 1.2. Se especifica aquí el contrato del token (§4.3) para que 1.2 no lo reinvente.
- **Al cerrar 1.1, ningún usuario del centro puede activarse ni iniciar sesión.** Es la consecuencia lógica de que 1.2 vaya después. Ver la pregunta abierta `OPEN-CORE-01`.
- **Gestión de sesiones** (timeout configurable, cierre remoto, historial de accesos) → 1.2. `ADR-034 §8` ya lo anotó: `sessions` necesita `tenant_id` y esa columna la añade 1.2. **1.1 no crea el ajuste `session_timeout_minutes`**: la semántica del timeout la define 1.2, y añadir la columna después es *expand* puro. Añadirla ahora sería exactamente lo que `ADR-034` `OPEN-13` prohíbe («no se debe adelantar ninguna por si acaso»).

### 1.5 `REQ-CORE-006` · Panel de gestión de traducciones e informe de cobertura → **diferido, sin paso asignado todavía**

El mecanismo de i18n está completo desde 0.9 (`docs/i18n.md`). Lo que falta de `REQ-CORE-006` es la **capa 3**: traducción del contenido introducido por el centro, con campos multi-idioma y idioma de respaldo.

En 1.1 **no existe todavía contenido de centro multi-idioma que gestionar**:

- Los nombres de rol están deliberadamente fuera: `ADR-034 §2` decidió que un rol lleva `name_key` (predefinido, traducido por la plataforma) **o** `name` (personalizado, literal único), y que «no se implementa nombre de rol multi-idioma».
- Nombres de asignaturas y actividades son `REQ-ACAD` (1.11) y `REQ-EXTRA`.
- Textos de branding personalizables (`RUX-BRAND-005`), condiciones de uso y web pública no están en el alcance de 1.1.

Construir un panel de gestión de traducciones sin contenido que traducir es construir una pantalla vacía. Se difiere al primer módulo que introduzca contenido de centro multi-idioma. El informe de cobertura de la **capa 1** (interfaz) ya lo cubre parcialmente `npm run lint:i18n` en CI.

Sí entra en 1.1 la parte de `REQ-CORE-006` que es dato: `people.locale` (idioma por usuario, ya en el esquema) expuesto y editable por API, y `tenant_settings.default_locale` / `active_locales`.

### 1.6 `REQ-CORE-007` · Notificaciones → paso **1.19** (`REQ-COM`)

Plantillas por tenant, canales (email/SMS/push), preferencias por usuario y confirmación de entrega son `REQ-COM`. 1.1 **no** configura canales de comunicación: configurarlos sin motor que los use es inventar un formulario sin destino.

Excepción acotada y necesaria: 1.1 envía **un** correo transaccional, el de invitación, directamente con `Mail` de Laravel sobre cola (§4.3). No usa ni prefigura el motor de `REQ-COM`. Depende de `0.10c` (proveedor de correo transaccional), que sigue **pendiente**: ver `OPEN-CORE-04`.

### 1.7 `REQ-CORE-008` · Dashboard y zona de cliente → paso **1.8**

Widgets, dashboards por defecto por rol y navegación SPA son 1.8, y dependen del *design system* de 1.7.

### 1.8 Parámetros académicos (`REQ-CORE-002`, tercer punto) → **1.10**, **1.11**, **1.16**

`REQ-CORE-002` pide configurar «cursos lectivos, períodos de evaluación, escalas de calificación». Los tres tienen dueño propio y posterior:

- Cursos lectivos → `REQ-CURSO` (1.10), marcado ⚠️ *paso crítico* precisamente porque es dimensión transversal.
- Períodos de evaluación → `REQ-ACAD` (1.11).
- Escalas de calificación → `REQ-CALIF` (1.16).

La tabla `academic_years` existe desde 0.8, pero **1.1 no expone ningún endpoint sobre ella**. Adelantar un CRUD de cursos aquí colisionaría de frente con el paso que el propio plan marca como crítico y habría que rehacerlo.

### 1.9 Backups y exportaciones de datos (`REQ-CORE-002`, último punto) → **1.26** (`REQ-BKP`) y `REQ-PRIV`

- Copias, restauración granular por tenant y prueba de restauración → `REQ-BKP` (1.26). Es infraestructura (PITR, `pg_dump` por tenant, destino de copias — `0.10d`, pendiente), no un formulario.
- Exportación completa de los datos del centro / portabilidad GDPR → `REQ-PRIV`.

1.1 sí introduce un mecanismo de exportación acotado (`data_exports`, §4.6) para la exportación CSV que `REQ-CORE-005` exige del registro de auditoría, diseñado como primitiva reutilizable.

### 1.10 Importación masiva: relación con `REQ-ONB-002` (1.24)

`REQ-CORE-003` pide «importación masiva desde CSV/Excel con validación previa y reporte de errores». `REQ-ONB-002` (paso 1.24) pide un **importador universal** con mapeo visual de columnas, plantillas reutilizables, ejecución reversible (rollback del lote) y estrategia configurable de duplicados, y su lista de entidades incluye explícitamente «personal».

Se solapan. Decisión, para no construir dos veces lo mismo:

- **1.1 implementa la importación de usuarios con esquema de columnas fijo y documentado** (§4.4): subida, validación previa completa sin escribir nada, informe de errores fila a fila, ejecución asíncrona e idempotente.
- **1.1 no implementa** mapeo visual de columnas, plantillas de mapeo, reversibilidad del lote ni estrategia configurable de duplicados. Son `REQ-ONB-002` y quedan explícitamente fuera.
- El caso de uso de 1.1 se expone como **servicio de aplicación con interfaz pública** (`BulkUserImporter`), de modo que 1.24 lo consuma como destino de importación en vez de reimplementarlo (`INV-007`: interfaces públicas, no importación de código interno).

Limitación que hay que documentar en el manual de usuario: **una importación de 1.1 no se deshace**. Corregir un lote mal importado exige desactivar los usuarios creados uno a uno hasta que 1.24 aporte el rollback.

### 1.11 Interfaz de usuario: 1.1 es **solo API**

Esta es la decisión de alcance con más consecuencias prácticas y merece su argumento.

1.1 entrega la API completa, su documentación OpenAPI y sus tests. **No entrega pantallas.** Razones, en orden de peso:

1. **No hay login hasta 1.2.** Una pantalla de gestión de usuarios en 1.1 no es alcanzable por ningún ser humano: no existe forma de autenticarse. Solo sería verificable con `actingAs()` desde tests, que es exactamente lo que sí se hace con la API.
2. **No hay *design system* hasta 1.7 ni layout hasta 1.8.** Las pantallas construidas en 1.1 usarían shadcn-vue sin los tokens ni el tema por tenant de 1.7, sin el layout responsive de 1.8 y sin las tablas de datos de 1.9 — y `RUX-BRAND-002`/`RUX-002` obligan a rehacerlas.
3. `INV-006` lo respalda: la API existe antes que la interfaz, y la interfaz es un cliente más.

Lo que sí entrega 1.1 en `apps/web/src/modules/core/`: `api/` (cliente tipado de los endpoints, sobre `src/api/client.ts`), `types/` (tipos de los recursos) y `locales/` (literales del módulo en los cuatro idiomas). Nada en `views/` ni `components/`.

**Consecuencia que hay que aceptar explícitamente**: el módulo `REQ-CORE` no está «terminado» según `CLAUDE.md §10` al cerrar 1.1, porque no tiene interfaz accesible ni manual de usuario con capturas. Se cierra funcionalmente cuando 1.8 monte sus pantallas. Ver `OPEN-CORE-02`.

---

## 2. Quién contrata y descontrata módulos — **RESUELTO por `ADR-045`** (2026-09-08)

> **Estado**: la contradicción que esta sección describía está **cerrada**. Se conserva el planteamiento original, no se borra, porque el motivo por el que 1.1 acotó su alcance a solo lectura es exactamente lo que `ADR-045` vino a decidir, y una revisión futura debe poder leerlo (`ADR-044 §8`, mismo criterio que `permisos.md §5`).

### 2.1 La contradicción, tal como se detectó en 1.1

| Requisito | Decía |
|-----------|-------|
| `REQ-CORE-002` | «El Administrador de Centro puede […] **activar/desactivar módulos contratados**.» |
| `RMOD-002` | «El **Super Admin** puede activar/desactivar módulos por tenant desde el panel de administración.» |

No era un matiz de redacción: `module_subscriptions` tiene **un solo booleano** `enabled` (`ADR-034 §5`). Un único interruptor no puede representar a la vez «la plataforma ha contratado este módulo al centro» y «el centro lo tiene encendido». Con ese esquema, o manda uno o manda el otro, y el que pierda puede pisar la decisión del que gana — y la contradicción no se manifiesta como un error, se manifiesta como una factura.

### 2.2 Cómo se resolvió

`ADR-045` (ACEPTADA, 2026-09-08) la resuelve **eliminando uno de los dos actores, no repartiendo el dato**, y **sin tocar el esquema**: ni una columna nueva, ni un renombrado, ni ciclo *expand/contract*.

| Punto | Decisión |
|-------|----------|
| Significado de `enabled` | «La plataforma ha contratado este módulo para este centro **y por tanto el centro puede usarlo**». Un solo significado, un solo dueño |
| Quién lo escribe | **Solo el Super Administrador**, desde el backoffice, por la conexión `pgsql_platform`, con motivo obligatorio en `reason` (`REQ-BO-002`) |
| Qué conserva el Administrador de Centro | **Leer** sus módulos (`modulo.leer`, `GET /modules`) y **configurar** los contratados (`modulo.actualizar`, `PATCH` sobre `settings`). **Nada más.** Sin conmutar, ni para encender ni para apagar |
| Qué gana el centro a cambio | Un **aviso** de que el módulo está disponible, informativo y sin acción requerida. En 1.6 se deriva de `enabled_at`, que ya se rellena; la notificación in-app de verdad es un *listener* de `ModuleContracted` en `REQ-COM-003` (1.19) |
| Quién hace cumplir la restricción | **El motor, no el controlador.** 1.6 aplica `REVOKE UPDATE, INSERT ON module_subscriptions FROM plataforma_app` más `GRANT UPDATE (settings, …)`. Hoy la garantía es un `if` en `ModulesController::updateSettings()`; `INV-001` exige que las restricciones que importan no vivan ahí |

La recomendación de `architect` había sido la contraria (dos columnas con `AND` lógico, para no borrar una capacidad de `REQ-CORE-002`); el usuario decidió lo anterior y la discrepancia queda registrada en `ADR-045 §12.1`. La vuelta atrás es **aditiva pura** —una columna `enabled_by_tenant` con `DEFAULT true` que no cambia el estado efectivo de ninguna fila—, de modo que la elección no cierra ninguna puerta.

### 2.3 Qué significa esto para lo que 1.1 dejó escrito

- La acotación de 1.1 —`module_subscriptions` en solo lectura salvo `settings`— **deja de ser provisional y pasa a ser la regla definitiva.** No hay que deshacer nada de lo que 1.1 construyó.
- `ModulesController::updateSettings()` sigue rechazando `enabled` con `422` y `core.validation.enabled_not_editable`, y a partir de 1.6 el rechazo lo respalda además un privilegio de columna en PostgreSQL.
- `CA-CORE-061` **se conserva y se refuerza** (§9): deja de ser una limitación temporal y pasa a ser una propiedad del producto verificada en dos capas.
- Lo que 1.6 sí añade es el **camino de escritura del backoffice**, que hoy no existe: contratar y descontratar desde `REQ-BO-002`, con emisión de `ModuleContracted`/`ModuleDecontracted` desde `REQ-CORE` (`ADR-045 §4.8`) e invalidación de la caché de disponibilidad (`ADR-045 §8.3`).

---

## 3. Actores y roles implicados

| Actor | Qué hace en 1.1 |
|-------|-----------------|
| **Administrador de Centro** | Todo: configuración del centro, alta/baja/modificación de usuarios, asignación de roles, invitaciones, importación, consulta y exportación de auditoría. |
| **Dirección / Jefatura de Estudios** | Consulta el listado de usuarios, los roles y la configuración del centro. No escribe. |
| **Secretaría** | Consulta el listado de usuarios. No escribe. |
| **Cualquier usuario autenticado** | Consulta y edita su propio perfil (`/me`): idioma preferido y datos de contacto. Sin permiso, por identidad. |
| **Super Administrador** | **Ninguna operación en 1.1.** Todo lo suyo es 1.6. |
| **Operador de sistemas** | Aprovisionamiento inicial del centro por consola (§4.7). |

---

## 4. Flujos principales

### 4.1 Configurar el centro (`REQ-CORE-002`)

1. El Administrador de Centro solicita la configuración actual (`GET /tenant/settings`).
2. Modifica uno o varios campos (`PATCH /tenant/settings`): idioma por defecto, idiomas activos, zona horaria, moneda, comunidad autónoma, datos fiscales, colores primario y secundario.
3. El servidor valida (`INV-010`): el idioma por defecto pertenece a los idiomas activos; los idiomas activos son un subconjunto no vacío de los cuatro de `ADR-021`; la zona horaria es un identificador IANA válido; la moneda es ISO 4217; la comunidad autónoma pertenece al catálogo; los colores son hexadecimales de 6 dígitos.
4. Si cambian los colores, el servidor valida el **contraste** de la paleta contra WCAG 2.2 AA (`RUX-BRAND-006`, `RNF-UX-002`). Contraste insuficiente ⇒ `422`, con el ratio calculado y el mínimo exigido en la respuesta.
5. Se escribe la fila, el *observer* de auditoría registra el cambio (`INV-003`) y se invalida la caché de configuración del tenant.

Los activos de marca (logo, favicon, fondo de login) van por endpoints propios porque son subida de fichero: §4.2.

### 4.2 Subir un activo de marca (`RUX-BRAND-001`, `-003`, `-004`)

1. El Administrador de Centro sube el fichero (`POST /tenant/settings/assets/{kind}`, `kind ∈ {logo, favicon, login-background}`).
2. Validación en servidor (`RSEC-OWASP-012`): tipo **real** por contenido, no por extensión ni por `Content-Type`; tamaño máximo por tipo de activo; dimensiones máximas.
3. Si es SVG, se **sanea** eliminando `<script>`, manejadores `on*`, `<foreignObject>` y referencias externas. Un SVG sin sanear es un vector de XSS servido desde el propio dominio del centro.
4. Se almacena en el bucket privado bajo la clave `tenants/{tenant_public_id}/branding/{kind}/{ulid}.{ext}`, nunca en la raíz web.
5. Se actualiza la columna correspondiente de `tenant_settings`. El activo anterior se marca para purga diferida (no se borra en la petición: si la escritura fallara, el centro se quedaría sin logo).
6. La entrega al navegador se hace siempre por URL firmada de caducidad corta, incluida la del endpoint público de branding (§4.8).

### 4.3 Alta de usuario e invitación (`REQ-CORE-003`)

1. El Administrador de Centro envía los datos (`POST /users`): nombre, primer apellido, segundo apellido (opcional), correo de acceso, correo de contacto, teléfono, tipo y número de documento, fecha de nacimiento, idioma preferido, y opcionalmente los roles a asignar.
2. El servidor valida: correo de acceso no repetido entre los usuarios vivos del tenant; documento (tipo + número) no repetido entre las personas vivas del tenant; el idioma pertenece a los idiomas activos del centro; los roles existen en el tenant y **quien invita no puede asignar un rol cuyos permisos no posea él mismo** (`RPERM-013`).
3. En una transacción se crea la `Person`, el `User` con `status = 'pendiente'` y contraseña aleatoria no utilizable, y las filas de `role_user`.
4. Si se pidió invitación, se genera un token aleatorio de 32 bytes; se guarda **solo su hash** en `user_invitations` con su caducidad; el token en claro solo viaja en el correo y no se persiste ni se registra en ningún log.
5. Se encola el envío del correo (`INV-012`), en el idioma preferido del destinatario (`REQ-CORE-006`, capa 2).
6. El *observer* audita la creación de `Person`, `User` y la invitación (`INV-003`).

Reenvío: `POST /users/{id}/invitations` sobre un usuario `pendiente` **revoca la invitación viva** y emite una nueva. Nunca hay dos invitaciones válidas a la vez para el mismo usuario.

Canje: **fuera de 1.1** (§1.4). El contrato que 1.2 debe respetar queda fijado aquí: el enlace es `https://{slug}.{dominio_base}/activar/{token}`, el tenant se resuelve por el host antes de tocar datos (`ADR-033 §2`), la búsqueda es por `(tenant_id, hash(token))`, y el canje exige que la invitación no esté caducada, revocada ni aceptada.

### 4.4 Importación masiva de usuarios (`REQ-CORE-003`)

Dos fases separadas, y **la validación nunca escribe** (`REQ-ONB-002` lo exige también, y aquí se cumple aunque el importador genérico sea 1.24).

**Fase 1 — subir y validar.**

1. `POST /user-imports` con el fichero CSV (multipart). Validación de tipo real y tamaño (`RSEC-OWASP-012`).
2. Se crea la fila `user_imports` en estado `subido` y se encola la validación (`INV-012`).
3. El trabajo recorre el fichero y valida fila a fila: cabecera esperada, campos obligatorios, formato de correo, formato de documento, idioma dentro de los activos, roles existentes, duplicados **dentro del propio fichero** y duplicados **contra la base de datos** (correo y documento).
4. Al terminar: estado `validado`, `row_count`, `error_count`, un informe CSV completo en el bucket (una fila por error, con número de línea, columna y motivo) y las primeras 50 incidencias en `error_summary` para poder pintarlas sin descargar nada.
5. `error_count > 0` **no impide** ejecutar: se ejecutan las filas válidas y se omiten las erróneas. Lo que sí impide ejecutar es que falle la cabecera (el fichero entero es inválido).

**Fase 2 — ejecutar.**

6. `POST /user-imports/{id}/execute` con cabecera `Idempotency-Key` obligatoria (`INV-011`). Estado `ejecutando`.
7. Se crean personas, usuarios, roles e invitaciones de las filas válidas, en lotes, cada fila en su propia transacción para que un fallo aislado no tumbe el lote.
8. Estado `completado` con `created_count`, o `fallido` con el motivo. Se emite el evento de dominio `UserImportCompleted`.

El esquema de columnas es fijo y se documenta en `api.md` §7. **No hay mapeo visual ni reversibilidad** (§1.10).

### 4.5 Consulta del registro de auditoría (`REQ-CORE-005`)

1. `GET /audit-logs` con filtros por rango de fechas, actor, evento, tipo de entidad y módulo, paginado por cursor sobre `(occurred_at DESC, id DESC)`.
2. La respuesta nunca revela valores redactados: `changes` se devuelve tal y como está almacenado, y `ADR-035` garantiza que un valor redactado nunca entró.
3. La consulta de auditoría exige permiso propio (`auditoria.leer`) y **no está incluida en ningún rol salvo el de Administrador de Centro**: el registro es un mapa de la actividad de todo el personal del centro.

### 4.6 Exportación del registro de auditoría (`REQ-CORE-005`)

1. `POST /audit-logs/exports` con los mismos filtros de §4.5. Se crea una fila en `data_exports` y se encola la generación (`INV-012`: nunca en la petición HTTP).
2. El trabajo genera el CSV por lotes y lo deja en el bucket con caducidad de 7 días.
3. `GET /data-exports/{id}` devuelve el estado y, cuando está listo, una URL firmada de caducidad corta.
4. La propia solicitud de exportación se audita como evento `exported` (`INV-003`; el vocabulario de `event` ya lo contempla).

**Exportación a PDF: fuera de 1.1.** `REQ-CORE-005` pide CSV **y** PDF; el servicio contenerizado de renderizado HTML→PDF no existe (motor sin decidir, paso 1.17, explícitamente pendiente desde 0.3). Se difiere a 1.17.

### 4.7 Aprovisionamiento inicial de un centro (comando de consola)

Sin este flujo, 1.1 no tiene ni un solo usuario con el que probarse. No es un endpoint (§1.1).

`php artisan tenant:provision-defaults {slug} --admin-email= --admin-given-name= --admin-family-name= [--default-locale=es-ES] [--active-locale=*] [--timezone=Europe/Madrid] [--currency=EUR] [--autonomous-community=]`

Desde `ADR-048` (2026-09-11, `1.6b`): inyecta el contrato `TenantProvisioner` en vez de instanciar la clase concreta, y las cinco opciones nuevas (`--default-locale`, `--active-locale`, repetible, `--timezone`, `--currency`, `--autonomous-community`) permiten fijar los ajustes iniciales sin editarlos después — cada una con el valor por defecto que ya tenía la columna, así que arrancar un centro por consola sin tocarlas se comporta igual que antes.

1. Comprueba que el tenant existe y no tiene ya configuración.
2. Crea la fila `tenant_settings` con los valores por defecto (`es-ES`, idiomas activos `['es-ES']`, `Europe/Madrid`, `EUR`) o los que las opciones anteriores indiquen.
3. Siembra los **16 roles predefinidos** de la sección 11.1 con `is_system = true`, `name_key = 'roles.{code}'`, y los atributos `mfa_required` / `special_data_access` de `permisos.md` §4. (`super_administrador` no es fila de `roles`: vive en `platform_admins`, sin `tenant_id` — `ADR-034 §2`, `permisos.md` §4.5. La sección 11.1 enumera 17 roles porque incluye ese, pero solo 16 se materializan como fila del tenant. Corregido tras confirmación del usuario, issue [#48](https://github.com/pirexia/plataforma-educativa/issues/48).)
4. Concede a cada rol predefinido sus permisos de `REQ-CORE` según la matriz de `permisos.md` §4.
5. Crea la persona y el usuario del primer Administrador de Centro, le asigna el rol y emite su invitación.
6. Es **idempotente**: una segunda ejecución no duplica nada y no reescribe lo ya configurado.

Se ejecuta con el rol propietario, se registra con `actor_type = 'console'` y 1.6 lo envolverá en el alta de tenant del backoffice.

### 4.8 Branding público previo a la sesión

La pantalla de login de 1.2 necesita el logo, los colores, el fondo y los idiomas del centro **antes** de que exista sesión.

`GET /tenant/branding` es el único endpoint de 1.1 **sin autenticación**. Resuelve el tenant por el host (`ADR-033 §2`) y devuelve exclusivamente: nombre del centro, colores primario y secundario, URLs firmadas de logo/favicon/fondo, idioma por defecto e idiomas activos.

Regla explícita: **no devuelve nada más**. Ni datos fiscales, ni número de usuarios, ni estado del tenant, ni módulos contratados. Cualquier campo añadido a este endpoint es información pública de Internet y debe justificarse como tal en la revisión de seguridad.

### 4.9 Autoservicio del perfil propio

`GET /me` y `PATCH /me`. El usuario autenticado consulta y modifica su idioma preferido, su correo de contacto y su teléfono. **No** puede cambiar su correo de acceso, su estado, sus roles ni su documento.

Se autoriza por identidad (el sujeto es el propio usuario autenticado), **no por permiso con ámbito `propios`**, por el motivo de §1.3.

---

## 5. Reglas de negocio

| ID | Regla |
|----|-------|
| `RN-CORE-01` | Un usuario pertenece a un único tenant y a una única persona (`users.person_id NOT NULL`, `ADR-034 §1`). |
| `RN-CORE-02` | El correo de acceso (`users.email`) es único entre los usuarios **vivos** del tenant. Tras la baja lógica, el correo vuelve a estar disponible. |
| `RN-CORE-03` | El par (tipo, número) de documento es único entre las personas **vivas** del tenant, y solo se comprueba si el número está informado. |
| `RN-CORE-04` | Estados de usuario: `pendiente` → `activo` (canje de invitación, 1.2), `activo` ⇄ `inactivo` (baja y alta administrativa). No hay transición de `pendiente` a `activo` sin canje. |
| `RN-CORE-05` | La baja de un usuario es **lógica** (`INV-004`): `deleted_at` informado y `status = 'inactivo'`. Nunca borrado físico. |
| `RN-CORE-06` | Un usuario **no puede darse de baja a sí mismo** por la API de gestión, ni retirarse sus propios roles. Evita que un administrador se deje al centro sin administrador. |
| `RN-CORE-07` | Debe existir **al menos un usuario vivo con el rol `administrador_centro`** en todo momento. La operación que dejaría el centro sin ninguno se rechaza con `409`. |
| `RN-CORE-08` | Nadie puede asignar un rol cuyos permisos no posea él mismo (`RPERM-013`). Se comprueba comparando el conjunto de permisos concedidos del rol destino contra los del solicitante. |
| `RN-CORE-09` | Solo hay **una invitación viva** por usuario. Emitir una nueva revoca la anterior. |
| `RN-CORE-10` | La invitación caduca a los **7 días** por defecto. El valor es constante de configuración de la aplicación en 1.1, no ajuste del centro (ver `OPEN-CORE-05`). |
| `RN-CORE-11` | Cambiar `users.email` **revoca automáticamente** las invitaciones vivas de ese usuario: un enlace emitido hacia la dirección anterior deja de ser válido. |
| `RN-CORE-12` | Solo se invita a usuarios en estado `pendiente`. Sobre `activo` o `inactivo`, `409`. |
| `RN-CORE-13` | El idioma por defecto del tenant debe pertenecer a sus idiomas activos, y el idioma preferido de un usuario también. |
| `RN-CORE-14` | Los idiomas activos son un subconjunto no vacío de `{es-ES, en, de, fr}` (`ADR-021`). Las lenguas cooficiales no se aceptan en fase 1. |
| `RN-CORE-15` | Una paleta que no alcance el contraste WCAG 2.2 AA se rechaza (`RUX-BRAND-006`). No se acepta «con advertencia». |
| `RN-CORE-16` | Los roles del sistema (`is_system = true`) no se pueden borrar ni renombrar. En 1.1 no se pueden editar en absoluto. |
| `RN-CORE-17` | La configuración del centro se cachea con prefijo de tenant (`ADR-033 §9`) y **se invalida en la escritura**, no solo por TTL (lección del [issue #7](https://github.com/pirexia/plataforma-educativa/issues/7)). |
| `RN-CORE-18` | Un fichero subido cuyo tipo real no coincida con el declarado se rechaza (`422`) y **no se almacena**. |
| `RN-CORE-19` | El token de invitación se persiste solo como hash. El valor en claro no aparece en base de datos, ni en logs, ni en la respuesta de la API, ni en el registro de auditoría (queda cubierto por el patrón `*token*` de `config('audit.secret_attribute_patterns')`). |
| `RN-CORE-20` | La importación se valida entera antes de escribir una sola fila. |
| `RN-CORE-21` | El fichero fuente de una importación y su informe de errores contienen datos personales: se purgan a los **30 días**. |
| `RN-CORE-22` | En 1.1, todo permiso se concede con ámbito `todos`. Ningún permiso con ámbito distinto se siembra ni se acepta (§1.3). |

---

## 6. Casos límite y errores

| Situación | Comportamiento |
|-----------|----------------|
| Host que no resuelve ningún tenant | `404`, sin filtrar (`ADR-033`: fallo en cerrado). Nunca «sin tenant» ni consulta sin filtro. |
| Alta con correo de un usuario dado de baja lógica | Se permite: el índice único es parcial sobre `deleted_at IS NULL`. Crea un usuario **nuevo**, no restaura el antiguo. |
| Restauración de un usuario cuyo correo lo ocupa ya otro usuario vivo | `409` con el conflicto identificado. La restauración no puede violar la unicidad. |
| Documento con dígito de control inválido | Se valida el formato de DNI/NIE/pasaporte. En datos de prueba el dígito es inválido a propósito (`REQ-SEED-005`), luego la validación de dígito debe poder desactivarse por entorno, nunca en producción. Ver `OPEN-CORE-06`. |
| Invitación caducada | El canje (1.2) devuelve `410`. En 1.1, la invitación caducada aparece en el listado con estado `caducada` y se puede reemitir. |
| Dos administradores editan la configuración a la vez | Última escritura gana. Sin bloqueo optimista en 1.1: la configuración de centro se toca rara vez y ambos cambios quedan en auditoría. Se anota como limitación consciente. |
| Importación con cabecera desconocida | `422` en la fase de validación, `error_count` a nivel de fichero y estado `fallido`. No se puede ejecutar. |
| Importación ejecutada dos veces con la misma `Idempotency-Key` | La segunda devuelve el resultado de la primera sin crear nada (`INV-011`). |
| Importación ejecutada dos veces con claves distintas | Las filas ya creadas fallan por unicidad de correo/documento y se reportan como error de fila. No se crean duplicados. |
| Exportación de auditoría con rango enorme | Se ejecuta en cola. Si supera el límite de filas configurado, se rechaza con `422` pidiendo acotar el rango. |
| Usuario que pierde su único rol | Permitido: queda sin permisos y solo puede usar `/me`. Es el resultado correcto de la denegación por defecto (`RPERM-011`). |
| Módulo desactivado | El *middleware* devuelve `403` con cuerpo informativo (`RMOD-009`). No aplica a `REQ-CORE`: ver §8. |
| Idioma retirado de los activos con usuarios que lo tenían preferido | Esos usuarios pasan a ver la interfaz en el idioma por defecto del tenant (respaldo), sin modificar su preferencia almacenada. Si el idioma vuelve a activarse, la recuperan. |

---

## 7. Interacción con otros módulos

`REQ-CORE` es el módulo base: **no consume** eventos de ningún otro. Publica los siguientes eventos de dominio (`INV-007`: los demás módulos escuchan, nunca importan código de `Core`):

| Evento | Cuándo | Consumidor previsto |
|--------|--------|---------------------|
| `UserCreated` | Alta de usuario, individual o por importación | `REQ-COM` (1.19), `REQ-RRHH` |
| `UserDeactivated` | Baja lógica de usuario | `REQ-AUTH` (revocar sesiones, 1.2), `REQ-COM` |
| `UserRestored` | Restauración | — |
| `UserRolesChanged` | Cambio del conjunto de roles | `REQ-AUTH` (reevaluar MFA obligatorio, 1.3), caché de permisos (1.5) |
| `UserEmailChanged` | Cambio del correo de acceso | `REQ-AUTH` (1.2) |
| `InvitationIssued` | Emisión o reenvío | `REQ-COM` (1.19), que sustituirá el envío directo de 1.1 |
| `InvitationRevoked` | Revocación o caducidad | — |
| `TenantSettingsUpdated` | `PATCH /tenant/settings` (idioma, zona horaria, moneda, comunidad autónoma, datos fiscales o colores). **No** se emite desde `PUT`/`DELETE .../assets/{kind}`: aunque también modifican `tenant_settings` (las claves de objeto de branding), sus únicos consumidores previstos (`REQ-CALIF`/`REQ-ECON`) no necesitan enterarse de un cambio de logo — esos endpoints solo invalidan la caché directamente | Invalidación de caché; `REQ-CALIF`/`REQ-ECON` (moneda, idioma de documentos) |
| `UserImportCompleted` | Fin de una importación | `REQ-ONB` (1.24) |
| `ModuleContracted` | `enabled` pasa a `true` en `module_subscriptions` de un tenant (`ADR-045 §4.8`, 1.6c). Lo emite `ModuleContracting::publish()`, nunca `REQ-BO` directamente (`RMOD-010`) | Ninguno en 1.6c; `REQ-COM-003` (1.19) |
| `ModuleDecontracted` | `enabled` pasa a `false`, aunque el módulo quede apagado (`CA-BO-038`) | Ídem |

Interfaces públicas que `REQ-CORE` expone en su `Domain` para que otros módulos las consuman sin acoplarse:

- `TenantSettingsReader` — idioma por defecto, idiomas activos, zona horaria y moneda del centro. Lo necesitarán todos los módulos que generen documentos o importes.
- `UserDirectory` — resolución de un usuario por `public_id` y consulta de su idioma preferido, para las comunicaciones de `REQ-COM`.
- `BulkUserImporter` — destino de importación de personal para `REQ-ONB-002` (§1.10).
- `AuditQuery` — consulta filtrada del registro, para que ningún módulo consulte `audit_logs` directamente.
- `ExportRequestService` — solicitud de una exportación asíncrona (§4.6), reutilizable por `REQ-PRIV` y demás.
- `TenantProvisioner` (`ADR-048`, 2026-09-11) — contrato síncrono con dos métodos: `provision()` (alta, fase 2 del alta de tenant) y `provisionFromTemplate()` (clonación, `REQ-BO/funcional.md §5.6.2`). Implementado por `ProvisionTenantDefaults`, consumido por `REQ-BO` (`1.6b`) desde sus trabajos en cola `ProvisionTenant`/`CloneTenant`, sin que `REQ-BO` importe código interno de `Core` (`INV-007`). Dos objetos de valor de entrada (`TenantInitialSettings`, `TenantAdministrator`) y el enumerado de resultado `TenantProvisioningOutcome`. Devuelve resultado y propaga fallo — por eso es contrato síncrono y no evento de dominio (`ADR-048 §4`, tres motivos de fondo). Fija el patrón para `1.6c` y `1.24` (`REQ-ONB`).
- `ModuleCatalog` (`ADR-045`, 1.6c) — lectura del catálogo de descriptores declarados en código (`code`, `name_key`, `phase`, `depends_on`, `essential`), resuelta una sola vez por proceso desde los `ServiceProvider` ya registrados por el contenedor (`RN-BO-63`). Implementada por `DeclaredModuleCatalog`. La consume también `EloquentModuleAvailability::isEnabled()` (sustituye a la constante `ALWAYS_ENABLED`) y `REQ-BO` para resolver el cierre de dependencias de la contratación de módulos.
- `ModuleContracting` (`ADR-045 §4.5`/`§4.8`, 1.6c) — escritura de `module_subscriptions` en dos fases (`apply()`/`publish()`, forzado por `ADR-046 §6.4`): una sola implementación del cierre de dependencias (`RN-BO-22`), consumida por la contratación individual, la masiva y la vista previa de `REQ-BO` (`1.6c`), nunca reimplementada allí. Implementada por `ModuleContractingService`. Emite `ModuleContracted`/`ModuleDecontracted` (tabla de eventos de arriba) desde `publish()`, nunca desde el backoffice (`RMOD-010`).

> Nota de convención: las clases (modelos, eventos, servicios) se nombran en inglés, coherentes con el código ya existente (`Person`, `User`, `AcademicYear`, `AuditLog`). La documentación y los literales de interfaz van en español. El ejemplo en español del skill `modulo-nuevo` no coincide con lo que hay en el repositorio; se sigue el repositorio.

---

## 8. Comportamiento con el módulo desactivado

**`REQ-CORE` no es desactivable.** Es el núcleo: sin él no hay usuarios, ni roles, ni configuración, ni auditoría. Se registra en el catálogo `modules` con `code = 'core'` y se marca como no desactivable; el comando `platform:sync-registry` y el *middleware* `EnsureModuleEnabled` lo tratan como siempre habilitado.

Lo que 1.1 sí entrega para los **demás** módulos es el *middleware* que `ADR-034 §5` dejó pendiente:

- `EnsureModuleEnabled` consulta la suscripción del tenant (con caché de prefijo de tenant, TTL corto, invalidada en escritura).
- **Falla en cerrado**: ausencia de fila ⇒ módulo desactivado.
- Devuelve `403` con `application/problem+json`, `type` propio y mensaje traducido (`RMOD-009`, `INV-009`).
- La respuesta de `GET /modules` es la fuente para que la interfaz oculte lo desactivado sin enlaces muertos (`RMOD-008`).

---

## 9. Criterios de aceptación

Verificables, cada uno con test que referencia su ID (`INV-015`).

### Configuración del centro (`REQ-CORE-002`)

- **`CA-CORE-001`** · *Dado* un Administrador de Centro autenticado, *cuando* solicita `GET /tenant/settings`, *entonces* recibe `200` con el idioma por defecto, los idiomas activos, la zona horaria, la moneda, la comunidad autónoma, los datos fiscales y la paleta de su centro, y **ningún dato de otro tenant**.
- **`CA-CORE-002`** · *Dado* un Administrador de Centro, *cuando* hace `PATCH /tenant/settings` con `default_locale` que no está en `active_locales`, *entonces* recibe `422` y no se modifica nada (`RN-CORE-13`).
- **`CA-CORE-003`** · *Dado* un Administrador de Centro, *cuando* envía una paleta cuyo contraste no alcanza WCAG 2.2 AA, *entonces* recibe `422` con el ratio calculado y el mínimo exigido, y la paleta no se guarda (`RN-CORE-15`, `RUX-BRAND-006`).
- **`CA-CORE-004`** · *Dado* un cambio válido de configuración, *cuando* se guarda, *entonces* existe un registro en `audit_logs` con `event = 'updated'`, el actor correcto y los atributos modificados (`INV-003`), y la caché de configuración del tenant queda invalidada (`RN-CORE-17`).
- **`CA-CORE-005`** · *Dado* un fichero `.png` renombrado a `.svg`, *cuando* se sube como logo, *entonces* se rechaza con `422` por tipo real y no se escribe ningún objeto en el bucket (`RN-CORE-18`, `RSEC-OWASP-012`).
- **`CA-CORE-006`** · *Dado* un SVG que contiene `<script>` y atributos `onload`, *cuando* se sube como logo, *entonces* el objeto almacenado no contiene ni el script ni los manejadores.
- **`CA-CORE-007`** · *Dado* un usuario **sin autenticar**, *cuando* pide `GET /tenant/branding` sobre el host de un centro, *entonces* recibe `200` con nombre, colores, activos e idiomas, y la respuesta **no contiene** datos fiscales, estado del tenant, módulos ni recuento de usuarios (§4.8).

### Usuarios (`REQ-CORE-003`)

- **`CA-CORE-010`** · *Dado* un Administrador de Centro, *cuando* crea un usuario con datos válidos, *entonces* recibe `201` con el `public_id` ULID, se crean una `Person` y un `User` con `status = 'pendiente'`, y la URL **no contiene ninguna clave interna** (`ADR-029`).
- **`CA-CORE-011`** · *Dado* un correo ya usado por un usuario vivo del mismo tenant, *cuando* se intenta crear otro usuario con él, *entonces* `422` (`RN-CORE-02`).
- **`CA-CORE-012`** · *Dado* el mismo correo en **dos tenants distintos**, *cuando* se crean ambos usuarios, *entonces* las dos altas tienen éxito y ninguna consulta de un tenant devuelve el usuario del otro (`INV-001`, `RMT-002`).
- **`CA-CORE-013`** · *Dado* un usuario dado de baja lógica, *cuando* se crea un usuario nuevo con su mismo correo, *entonces* el alta tiene éxito y el usuario antiguo permanece con `deleted_at` informado (`RN-CORE-02`).
- **`CA-CORE-014`** · *Dado* un usuario, *cuando* se elimina, *entonces* la fila permanece con `deleted_at` y `status = 'inactivo'`, y `GET /users/{id}` devuelve `404` salvo que se pida explícitamente incluir los eliminados (`INV-004`, `RN-CORE-05`).
- **`CA-CORE-015`** · *Dado* el único Administrador de Centro vivo, *cuando* se intenta darlo de baja o retirarle el rol, *entonces* `409` y no se modifica nada (`RN-CORE-07`).
- **`CA-CORE-016`** · *Dado* un usuario autenticado, *cuando* intenta darse de baja a sí mismo por `DELETE /users/{su_id}`, *entonces* `409` (`RN-CORE-06`).
- **`CA-CORE-017`** · *Dado* un usuario con permiso `asignacion_rol.crear` pero **sin** el permiso `auditoria.leer`, *cuando* intenta asignar a otro un rol que concede `auditoria.leer`, *entonces* `403` (`RPERM-013`, `RN-CORE-08`).
- **`CA-CORE-018`** · *Dado* cualquier usuario autenticado sin permisos de gestión, *cuando* hace `PATCH /me` cambiando su idioma preferido, *entonces* `200` y el cambio se persiste; *cuando* intenta cambiar su correo de acceso, su estado o sus roles por el mismo endpoint, *entonces* esos campos se ignoran y no se modifican (§4.9).
- **`CA-CORE-019`** · *Dado* un usuario sin ningún permiso de `REQ-CORE`, *cuando* llama a `GET /users`, *entonces* `403` (`INV-002`, `RPERM-011`).

### Invitaciones (`REQ-CORE-003`)

- **`CA-CORE-020`** · *Dado* un usuario `pendiente`, *cuando* se emite su invitación, *entonces* se crea una fila en `user_invitations` con caducidad futura, se encola un correo (no se envía en la petición, `INV-012`) y **el token en claro no aparece** ni en la respuesta, ni en la tabla, ni en `audit_logs` (`RN-CORE-19`).
- **`CA-CORE-021`** · *Dado* un usuario con invitación viva, *cuando* se emite otra, *entonces* la anterior queda revocada y solo una está vigente (`RN-CORE-09`).
- **`CA-CORE-022`** · *Dado* un usuario con invitación viva, *cuando* se cambia su correo de acceso, *entonces* la invitación queda revocada (`RN-CORE-11`).
- **`CA-CORE-023`** · *Dado* un usuario `activo`, *cuando* se intenta invitarlo, *entonces* `409` (`RN-CORE-12`).
- **`CA-CORE-024`** · *Dado* el correo de invitación, *cuando* se genera, *entonces* su asunto y cuerpo están en el idioma preferido del destinatario y existen los cuatro idiomas de `ADR-021` (`INV-009`, `REQ-CORE-006` capa 2).

### Importación (`REQ-CORE-003`)

- **`CA-CORE-030`** · *Dado* un CSV con 3 filas válidas y 2 inválidas, *cuando* se valida, *entonces* el estado es `validado`, `row_count = 5`, `error_count = 2`, existe un informe descargable con línea, columna y motivo de cada error, y **no se ha creado ningún usuario** (`RN-CORE-20`).
- **`CA-CORE-031`** · *Dado* ese mismo lote validado, *cuando* se ejecuta, *entonces* se crean exactamente 3 usuarios y `created_count = 3`.
- **`CA-CORE-032`** · *Dado* un lote ya ejecutado, *cuando* se reintenta con la **misma** `Idempotency-Key`, *entonces* se devuelve el resultado anterior sin crear usuarios nuevos (`INV-011`).
- **`CA-CORE-033`** · *Dado* un CSV con cabecera desconocida, *cuando* se valida, *entonces* el estado es `fallido` y la ejecución se rechaza con `409`.
- **`CA-CORE-034`** · *Dado* un CSV que contiene dos veces el mismo correo, *cuando* se valida, *entonces* la segunda aparición se reporta como error de duplicado dentro del fichero.
- **`CA-CORE-035`** · *Dado* un fichero de importación con más de 30 días, *cuando* corre la tarea de purga, *entonces* el objeto fuente y el informe se eliminan del bucket (`RN-CORE-21`).

### Roles y permisos (`REQ-CORE-004`, parte de 1.1)

- **`CA-CORE-040`** · *Dado* un tenant recién aprovisionado, *cuando* se listan sus roles, *entonces* existen los 16 roles predefinidos de la sección 11.1 (todos salvo `super_administrador`, que no es fila de `roles` — `permisos.md` §4.5) con `is_system = true` y `name_key` informado (`name` nulo).
- **`CA-CORE-041`** · *Dado* un rol del sistema, *cuando* se intenta modificarlo o borrarlo, *entonces* `405` — en 1.1 no existe ninguna ruta `PATCH`/`DELETE` sobre `/roles/{id}` (solo lectura, `RN-CORE-16`, §1.3): Laravel responde `405` automáticamente a un método sin ruta registrada sobre una URI que sí resuelve, sin necesidad de un middleware de permiso que lo bloquee explícitamente.
- **`CA-CORE-042`** · *Dado* un tenant aprovisionado, *cuando* se inspeccionan sus filas de `permission_role`, *entonces* **ninguna** tiene un `scope` distinto de `todos` (`RN-CORE-22`, §1.3).
- **`CA-CORE-043`** · *Dado* un usuario del tenant A, *cuando* se intenta asignarle un rol cuyo `public_id` pertenece al tenant B, *entonces* la operación falla y no se crea la fila `role_user` (`INV-001`).

### Auditoría (`REQ-CORE-005`)

- **`CA-CORE-050`** · *Dado* un Administrador de Centro, *cuando* consulta `GET /audit-logs` filtrando por rango de fechas y actor, *entonces* recibe solo registros de su tenant, ordenados por `occurred_at` descendente, con paginación por cursor estable.
- **`CA-CORE-051`** · *Dado* un usuario sin `auditoria.leer`, *cuando* consulta el registro, *entonces* `403` (`INV-002`).
- **`CA-CORE-052`** · *Dado* un registro cuyo `changes` contiene una entrada redactada, *cuando* se devuelve por la API, *entonces* la respuesta conserva el objeto `{"redacted": "..."}` y **no expone ningún valor** (`ADR-035`).
- **`CA-CORE-053`** · *Dado* una solicitud de exportación, *cuando* se acepta, *entonces* devuelve `202` con el `public_id` de la exportación, la generación ocurre en cola (`INV-012`) y la propia solicitud queda auditada con `event = 'exported'`.
- **`CA-CORE-054`** · *Dado* una exportación completada, *cuando* se solicita su descarga, *entonces* se devuelve una URL firmada de caducidad corta y **nunca** una ruta directa al bucket.

### Módulos (`RMOD-008`, `RMOD-009`)

- **`CA-CORE-060`** · *Dado* un tenant sin fila de suscripción para un módulo, *cuando* se llama a un endpoint de ese módulo, *entonces* `403` con cuerpo informativo — la ausencia de fila se lee como desactivado (`ADR-034 §5`, fallo en cerrado).
- **`CA-CORE-061`** · *Dado* un Administrador de Centro, *cuando* intenta cambiar `enabled` de una suscripción por API, *entonces* la operación es rechazada — `PATCH /module-subscriptions/{public_id}` con la clave `enabled` responde `422` con `core.validation.enabled_not_editable`, y no existe ninguna otra ruta que lo permita. **Reforzado por `ADR-045` (§2)**: deja de ser una limitación temporal de 1.1 y pasa a ser la regla definitiva del producto. Desde 1.6 se verifica **en dos capas**, y las dos tienen test propio: la validación de la aplicación (este criterio) y el privilegio de columna en PostgreSQL (`REVOKE UPDATE, INSERT ON module_subscriptions FROM plataforma_app`, `CA-BO-030`/`CA-BO-031`), de modo que un fallo futuro en el controlador —o un controlador nuevo que nadie relacione con esto— siga sin poder contratar nada.
- **`CA-CORE-062`** · *Dado* `GET /modules`, *cuando* lo consulta un Administrador de Centro, *entonces* recibe solo las suscripciones de su tenant, con su estado y su configuración.

### Transversales

- **`CA-CORE-070`** · *Dado* cualquier endpoint de este módulo, *cuando* se llama sin sesión válida, *entonces* `401`; sin el permiso requerido, `403`. Ninguno responde con datos (`INV-002`, denegación por defecto).
- **`CA-CORE-071`** · *Dado* cualquier endpoint de escritura, *cuando* se ejecuta con éxito, *entonces* existe el registro de auditoría correspondiente con actor, IP, `user_agent` y `request_id` (`INV-003`, `INV-013`).
- **`CA-CORE-072`** · *Dado* cualquier recurso expuesto, *cuando* aparece en una URL o en un cuerpo de respuesta, *entonces* se identifica por `public_id` ULID y **nunca** por la clave interna `bigint` (`ADR-029`).
- **`CA-CORE-073`** · *Dado* un usuario del tenant A, *cuando* pide por `public_id` un recurso del tenant B, *entonces* `404` (no `403`: no se confirma la existencia del recurso ajeno).
- **`CA-CORE-074`** · *Dado* el comando `tenant:provision-defaults`, *cuando* se ejecuta dos veces seguidas sobre el mismo tenant, *entonces* la segunda no crea ni modifica nada (§4.7).
- **`CA-CORE-075`** · *Dado* cualquier mensaje visible de este módulo, *cuando* se revisa, *entonces* existe en `es-ES`, `en`, `de` y `fr`, y no hay literales en el código (`INV-009`).

---

## 10. Preguntas abiertas

Decididas por el usuario el 2026-08-19 tras revisar esta especificación, salvo donde se indica lo contrario.

### `OPEN-CORE-01` · Al cerrar 1.1, ningún usuario puede acceder al sistema. ¿Se acepta?

1.1 crea usuarios en estado `pendiente` y emite invitaciones, pero el canje y el login son 1.2. Es coherente con el plan, pero significa que 1.1 se cierra sin ninguna demostración manual posible: solo tests. **Aceptado sin cambios.**

### `OPEN-CORE-02` · ¿Dónde se construye la interfaz de `REQ-CORE`? — **RESUELTO**

**Decisión**: 1.1 es solo API (§1.11 se mantiene tal cual). Las pantallas de `REQ-CORE` se construyen dentro del paso **1.8** (layout, navegación y dashboards por rol), junto con el resto de la interfaz por rol, cuando ya existan el design system (1.7) y el layout (1.8) — no como paso «1.8b» separado. Consecuencia aceptada explícitamente: `REQ-CORE` no cumple la definición de terminado de `CLAUDE.md §10` al cerrar 1.1; se completa al cerrar 1.8.

### `OPEN-CORE-03` · Quién activa y desactiva módulos: contradicción `REQ-CORE-002` vs `RMOD-002` — **RESUELTO por `ADR-045`** (2026-09-08)

Detallada en §2. En 1.1 se difirió con severidad de bloqueo para 1.6 y se registró como issue [#44](https://github.com/pirexia/plataforma-educativa/issues/44) (severidad Media).

**Cerrada.** `ADR-045` (ACEPTADA, 2026-09-08) decide que la potestad es **única y del Super Administrador**, con aviso informativo al centro; el Administrador de Centro conserva consultar y configurar `settings`, y pierde la de conmutar. La opción que `1.1` anticipaba —dos estados con regla de precedencia— fue la recomendación de `architect` y **no** la elegida; la discrepancia queda registrada en `ADR-045 §12.1`. **Coste de esquema cero**: no se añade, renombra ni elimina ninguna columna, luego nada de lo que 1.1 construyó hay que deshacerlo, y la acotación a solo lectura pasa de provisional a definitiva. La reversibilidad hacia la opción de dos columnas es aditiva pura, si algún día aparece la demanda de soporte que la justifique (`ADR-045 §8.1`). El camino de escritura del backoffice lo construye **1.6** (`docs/modulos/REQ-BO/`).

### `OPEN-CORE-04` · Proveedor de correo transaccional (`0.10c`), sin decidir

La invitación de `REQ-CORE-003` es un correo. `0.10c` sigue pendiente (`memory.md`). En desarrollo basta el `mailer` de log o `array`, y los tests comprueban que el trabajo se encola, no que el correo llega. **No bloquea 1.1**, pero **bloquea la validación de extremo a extremo** del flujo de invitación y, por tanto, la puesta en producción del piloto.

### `OPEN-CORE-05` · Caducidad de la invitación: ¿constante de plataforma o ajuste del centro?

`REQ-CORE-003` dice «enlace de activación caducable» sin fijar plazo ni decir quién lo fija. `RN-CORE-10` propone 7 días como constante de aplicación. Si debe ser configurable por centro, es una columna más en `tenant_settings` y un campo más en el panel. **No bloquea**: pasar de constante a columna es *expand* puro.

### `OPEN-CORE-06` · Validación del dígito de control de DNI/NIE frente a `REQ-SEED-005` — **RESUELTO**

**Decisión**: opción (b). Se valida el dígito de control de verdad, con un conmutador de configuración por entorno (`config('core.documents.validate_check_digit')` o equivalente); el entorno de producción **fuerza** la validación activa y no permite desactivarla en runtime (comprobación en el propio `ServiceProvider` o en un test de configuración, no solo documentación). En desarrollo/test se desactiva explícitamente para que 1.15b (`REQ-SEED`) pueda generar personas con dígito inválido a propósito por los mismos servicios de aplicación de 1.1, sin puerta trasera.

### `OPEN-CORE-07` · Lista definitiva de datos fiscales del centro

`REQ-CORE-001` dice «datos fiscales» sin enumerarlos. `datos.md` propone el mínimo (razón social, NIF/CIF, dirección fiscal estructurada, país). El dueño real del requisito es `REQ-ECON` (facturación), que necesita la dirección estructurada para emitir facturas conformes y que no está en fase 1 bloque A. **No bloquea**: añadir columnas es *expand*.

### `OPEN-CORE-08` · Foto de perfil: `REQ-CORE-003` la pide, `ADR-034` la excluye

`REQ-CORE-003` enumera «foto de perfil» entre los datos de usuario. `ADR-034 §1` dejó la fotografía **deliberadamente fuera** de `people` y la remitió a `OPEN-13` («base legal por campo, no catalogada todavía», decisión de `REQ-PRIV-006`). No es contradicción entre requisitos: es un requisito bloqueado por una decisión de protección de datos aún sin tomar. **1.1 no implementa foto de perfil.** Desbloquearlo exige cerrar `OPEN-13`, y trae consigo consentimiento de imagen (`INV-014`) y almacenamiento de datos personales gráficos.

### `OPEN-CORE-09` · Convenciones de la API REST: falta un ADR

1.1 es el **primer módulo con endpoints**, así que fija por omisión las convenciones que copiarán los 52 restantes: forma de la paginación, sintaxis de filtros y orden, formato de error, versionado, cabecera de idempotencia y política de `PATCH` frente a `PUT`. `api.md` §8 contiene una propuesta completa y argumentada, pero **una convención transversal decidida dentro de la especificación de un módulo no es un ADR** (`CLAUDE.md §6.3`).

**RESUELTO**: `docs/adr/ADR-038-convenciones-api-rest.md` publicado y referenciado en `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` §18. Corrigió siete puntos de la propuesta de `api.md` §9 (sintaxis de filtros, cursor cifrado con desempate, formato de `errors`, dónde se almacena la idempotencia, semántica exacta de `PATCH`); `api.md` y `datos.md` ya están actualizados en consecuencia. De paso detectó dos defectos reales en `apps/web/src/api/client.ts` (fusión de cabeceras y `Content-Type` fijo rompiendo *multipart*), corregidos en la misma sesión (issue [#47](https://github.com/pirexia/plataforma-educativa/issues/47)).

### `OPEN-CORE-10` · Análisis antivirus de ficheros subidos (`RSEC-OWASP-012`) — **RESUELTO (diferido)**

`RSEC-OWASP-012` exige «análisis antivirus» de todo fichero subido. No existe ningún servicio de análisis en la infraestructura y `ADR-037` no lo contempla. 1.1 implementa validación de tipo real, tamaño y saneado de SVG, pero **no análisis antivirus**. Registrado como issue [#45](https://github.com/pirexia/plataforma-educativa/issues/45) (severidad Media), candidato natural el paso **1.27** (endurecimiento y revisión OWASP). **No bloquea 1.1.**

### `OPEN-CORE-11` · Retención configurable del registro de auditoría

`REQ-CORE-005` exige «retención mínima de 2 años, configurable por compliance». La purga la implementa `REQ-PRIV-006`, que no existe. 1.1 **no crea** el ajuste de retención (sería una columna que nadie lee, justo lo que `ADR-034 OPEN-13` desaconseja). Hay que confirmar que el ajuste nace con `REQ-PRIV-006` y no antes.

### Observación menor, no pregunta: `people.locale` por defecto

La migración `create_people_table` fija `locale` con valor por defecto `'es'`, mientras `ADR-021` y `REQ-CORE-006` nombran el idioma por defecto como **`es-ES`**, y `apps/web/src/i18n` usa `es`. Es una inconsistencia de nomenclatura de bajo impacto pero que hay que zanjar **antes** de que existan datos: 1.1 unifica en `es-ES` en `tenant_settings` y en `people.locale`, con la migración *expand* correspondiente. Registrado como issue [#46](https://github.com/pirexia/plataforma-educativa/issues/46) (severidad Baja), informado al usuario, no resuelto en silencio (`CLAUDE.md §5`).

---

## 11. ¿Se aprueba esta especificación?

**Aprobada por el usuario el 2026-08-19.** Decisiones tomadas:

1. Frontera de alcance de §1 confirmada, incluida §1.11 (**1.1 es solo API, sin pantallas** — pantallas en el paso 1.8, ver `OPEN-CORE-02`).
2. Acotación de módulos a solo lectura por la contradicción de §2 confirmada (issue [#44](https://github.com/pirexia/plataforma-educativa/issues/44), ADR diferido a 1.6).
3. `OPEN-CORE-06` (dígito de control de DNI/NIE): opción (b), conmutador por entorno forzado a validar en producción.
4. `OPEN-CORE-09` (`ADR-038`, convenciones de API REST): publicado, `api.md`/`datos.md` actualizados. **Nada pendiente — listo para `implementer`.**

---

## 12. Paso 1.8 · *Layout*, navegación y panel de inicio (`REQ-CORE-008`)

| Campo | Valor |
|-------|-------|
| Paso | **1.8** (`PLAN-IMPLEMENTACION.md`, Bloque B) |
| Requisito de origen | `REQ-CORE-008` (sección 5.1), diferido aquí por §1.7 de este documento |
| Requisitos transversales | `RUX-001` a `RUX-006`, `RUX-RESP-001` a `RUX-RESP-003`, `RUX-RESP-005` a `RUX-RESP-007`, `RUX-ICON-001`/`002`/`003`/`006` (sección **10** del documento de requisitos, no la 5), `RMOD-008`, `REQ-CORE-006` (idioma conmutable sin perder estado) |
| Depende de | 1.1 (`GET /me`, `PATCH /me`, `GET /tenant/branding`), 1.2 (`DELETE /auth/session`, cookie de sesión), 1.3 (bloque `mfa` de `/me`, muro de alta de MFA), 1.5 (`permissions` de `/me` calculado por el motor completo, con inercia por módulo), **1.7** (`docs/design-system.md`, `ADR-052`). **Todas implementadas.** Ninguna dependencia no implementada para lo que este paso especifica; las que faltan afectan solo a lo que §12.1.2 deja fuera |
| Código afectado | **Solo `apps/web`.** Ni un endpoint, ni un permiso, ni una migración (`api.md §12`, `permisos.md §10`, `datos.md` Parte B) |
| Estado | **APROBADA** (2026-09-23). `OPEN-CORE-12`/`-13`/`-14`/`-17` resueltas por el usuario; `OPEN-CORE-16` resuelta por `ADR-053` (`architect`, ratificado por el usuario); `OPEN-CORE-15` abierta y no bloqueante (issue #258, servidor, fuera de 1.8). **Implementación entregada** (2026-09-23, rama `feature/REQ-CORE-008-layout-navegacion-dashboards`): 518/518 Vitest y 10/10 Playwright en verde; pendiente de revisión independiente (`security-reviewer`/`doc-reviewer`, `db-reviewer` no aplica) y de mezclar. Un hallazgo Media documentado y no corregido: `mfa-enrollment-wall` y el *catch-all* declaran `meta.permissions` vacía por diseño (§12.5, comentario de cabecera de `src/modules/auth/shell.ts`), fuera de la lista cerrada de cuatro rutas que enumera la prosa de `RN-CORE-24`/`CA-CORE-103` — issue [#260](https://github.com/pirexia/plataforma-educativa/issues/260) |

### 12.0 Por qué esto va en `REQ-CORE` y no en un documento aparte

Se valoró seguir el precedente de `docs/design-system.md` (documento propio en `docs/`). Se descarta por tres motivos:

1. **Tiene requisito de módulo propio.** `docs/design-system.md` existe fuera de `docs/modulos/` porque `ADR-052 §6` declaró que 1.7 no es un *bounded context* y no tenía `REQ-*` que lo gobernara. 1.8 sí: `REQ-CORE-008` es un sub-requisito de `REQ-CORE`, y §1.7 de este mismo documento ya lo difirió aquí con nombre.
2. **`REQ-CORE-008` es el sub-requisito de origen** y las pantallas de gestión que le quedan pendientes a `REQ-CORE` (diferidas a `1.9b`, `OPEN-CORE-12`) viven en esta misma carpeta. Separar el *layout* de ellas en dos documentos obligaría a cruzar referencias en cada criterio.
3. **El volumen cabe.** Sin modelo de datos ni API nuevos, `datos.md`/`api.md`/`permisos.md`/`operacion.md` solo ganan una sección corta cada uno; un documento aparte tendría cuatro de sus cinco partes vacías.

Coste aceptado: el código del *shell* vive en `apps/web/src/layouts` y `src/navigation`, fuera de `src/modules/core`. Es presentación de aplicación, como `src/tenant/` en 1.7 (`docs/design-system.md §3`), y la especificación la gobierna el requisito, no la carpeta.

### 12.1 Alcance

#### 12.1.1 Entra en 1.8

| # | Qué | Requisitos |
|---|-----|------------|
| 1 | **Tres regímenes de *layout*** por ruta: público (pantallas sin sesión, `PublicAuthShell` de 1.7), aplicación (con sesión, *shell* con navegación) y desnudo (con sesión, sin navegación: muro de MFA) | `RUX-001`, `RUX-003` |
| 2 | ***Shell* de aplicación responsive**: barra superior, navegación lateral persistente en escritorio, *drawer* en tableta, menú de hamburguesa en móvil, cinco *breakpoints* | `RUX-RESP-001`, `-002`, `-003`, `-006`, `-007` |
| 3 | **Estado de sesión global en cliente** y *guard* de *router* (experiencia de usuario, no control de acceso) | `REQ-CORE-008` punto 1, `INV-002` (el servidor sigue decidiendo) |
| 4 | **Registro de navegación** derivado de los **permisos efectivos** del usuario (`GET /me` → `permissions`), nunca del código de rol; forma y ensamblado por `ADR-053` | `REQ-CORE-008` punto 2 y criterio 2, `RMOD-008`, `RPERM-011` |
| 4b | **Ficheros de convención de `ADR-053`**: `src/navigation/{types,modules,sections}.ts`, `src/modules/auth/shell.ts` (rutas de `auth` trasladadas desde `router/index.ts`), y los cinco tests de coherencia de `ADR-053 §2` | `ADR-053 §1-§2` |
| 5 | ***Breadcrumb*** derivado de la ruta | `RUX-003` |
| 6 | **Panel de inicio** (`/`) con lo que hoy tiene datos reales: saludo, centro, estado de la cuenta (MFA) y accesos directos (§12.4) | `REQ-CORE-008` puntos 1-3 (solo «accesos directos») |
| 7 | **Menú de usuario**: cuenta, **selector de idioma** y **control de modo de color** (ambos diferidos aquí por `docs/i18n.md` y `OPEN-DS-03`), cierre de sesión | `REQ-CORE-006`, `RNF-UX-004` |
| 8 | **Componentes de estado** vacío / carga / error reutilizables y su correspondencia con los errores de la API (`ADR-038 §6`), incluido el `403` de módulo desactivado | `RUX-006`, `RMOD-009` |
| 9 | **Integración en el *shell* de las pantallas con sesión que ya existen** (`/cuenta/*`, `/administracion/mfa`, `/administracion/sso*`), sin cambiar su funcionalidad | `RUX-003` |
| 10 | **Transiciones de ruta** sin recarga, respetando `prefers-reduced-motion` por el mecanismo global de 1.7 | `REQ-CORE-008` punto 5, `RUX-005` |
| 11 | Retirada de la `HomeView` de 0.5, que llama a un *endpoint* inexistente (issue [#86](https://github.com/pirexia/plataforma-educativa/issues/86)) | — |

#### 12.1.2 No entra en 1.8

| Fuera | Dónde va | Motivo |
|-------|----------|--------|
| *Widgets* de próximos eventos y calendario | `REQ-AGENDA` (5.25, sin paso asignado en el plan) | Sin módulo que aporte los datos |
| *Widget* de notificaciones | `REQ-COM` (1.19) / `REQ-CORE-007` | Sin motor de notificaciones (§1.6) |
| *Widget* de tareas pendientes | **Sin dueño identificado** — ver `OPEN-CORE-13` | `REQ-CORE-008` no dice de qué son las tareas |
| *Widgets* configurables por el usuario y *dashboards* por defecto definidos por el administrador | **Pendiente de `OPEN-CORE-13`** | Con un único tipo de bloque con datos, un motor de configuración no tendría nada que configurar |
| Tablas de datos (TanStack Table), vista de tarjetas en móvil de `RUX-RESP-004` | **1.9** | Plan |
| Editor de roles, matriz de permisos, vista previa de permisos efectivos | **1.5b** | Plan, `ADR-044 §6` |
| Selector de centro para identidad federada compartida (`RMT-009`) | Sin paso | No hay identidad compartida entre tenants hoy: cada cuenta es de un único tenant (`RN-CORE-01`) |
| *Banner* de *impersonation* (`REQ-SUP-003`) | Fase 2 | — |
| Cualquier cosa del *backoffice* de plataforma | `apps/backoffice`, paso de interfaz de `REQ-BO` | `ADR-046 §4.1`: SPA separada, *guard* `platform` separado. **Ninguna entrada de navegación de este paso apunta a `/api/platform/v1`** |
| Ilustraciones en estados vacíos (`RUX-ICON-005`) | Pendiente de `OPEN-CORE-17` | Recurso gráfico con licencia a registrar (`RUX-ICON-007`) |
| Nuevas pantallas de módulos que no existen | Cada módulo, en su paso | `RN-CORE-25`: nada de «próximamente» |

#### 12.1.3 Pantallas pendientes de `REQ-CORE`: diferidas a `1.9b` (`OPEN-CORE-12`, resuelta)

`OPEN-CORE-02` (resuelta el 2026-08-19) decía que las pantallas de `REQ-CORE` se construyen **dentro de 1.8, no como paso «1.8b» separado**. Dos documentos posteriores lo contradecían o lo hacían inviable, contradicción registrada como `OPEN-CORE-12`:

- `PLAN-IMPLEMENTACION.md` describe 1.8 como «Responsive con los *breakpoints* de `RUX-RESP-001`, menús adaptativos, estados vacíos y de error», sin pantallas de módulo.
- `docs/design-system.md §1.2` (aprobado el 2026-09-22) sitúa la pantalla de configuración de marca «**posterior a 1.8** (pantallas de `REQ-CORE`, `OPEN-CORE-02`)».
- Las pantallas principales de `REQ-CORE` son listados paginados y filtrables (usuarios, invitaciones, importaciones, auditoría con cursor, roles). Construirlas antes de 1.9 (TanStack Table) es el mismo error que `ADR-044 §6` evitó con `1.5b`: hacerlas dos veces.

**Resuelta por el usuario el 2026-09-23 (§12.14, `OPEN-CORE-12`): van en `1.9b`**, paso propio tras 1.9, y `OPEN-CORE-02` queda reescrita por esta decisión. §12 **no contiene ni una regla ni un criterio** de las pantallas de gestión de usuarios, invitaciones, importación, roles, auditoría, configuración del centro, activos de marca, módulos contratados ni perfil propio — todas se especifican en `1.9b`. Lo que sí especifica 1.8 —*shell*, navegación, panel— es independiente de ellas: se enchufan al registro de §12.5 sin cambiar nada de lo aquí escrito.

### 12.2 Actores y lo que ve cada rol predefinido hoy

**El panel y la navegación no tienen variantes por rol en el código** (`RN-CORE-23`). Lo que cambia entre usuarios es el conjunto de permisos efectivos que devuelve `GET /me`, que es la unión resuelta de sus roles (`RPERM-007`, deny incluido) con la inercia por módulo ya aplicada (`REQ-PERM/api.md §7.2`). Un rol personalizado de 1.5 funciona sin tocar una línea.

Consecuencia que hay que ver antes de aprobar: con los permisos sembrados hoy (`permisos.md §4.1` más los de `REQ-AUTH`), **12 de los 16 roles predefinidos no tienen ni un permiso**. Su panel es, con toda honestidad, casi vacío:

| Roles | Navegación visible al cerrar 1.8 |
|-------|------------------------------------------------------------------------------------------|
| `administrador_centro` | Inicio · Mi cuenta (contraseña, sesiones, seguridad) · Administración: MFA, SSO (suponiendo que la siembra de `REQ-AUTH` le concede los permisos de ambas pantallas; **no verificado** contra la siembra al redactar — `CA-CORE-100` lo fija con datos de prueba, no con la siembra) |
| `direccion`, `secretaria`, `administrativo` | Inicio · Mi cuenta. Tienen `usuario.leer` y otros permisos de lectura, pero **ninguna pantalla que los use existe todavía** |
| Los doce restantes (`docente`, `tutor_grupo`, `orientador`, `coordinador_bienestar`, `estudiante`, `tutor_legal`, `responsable_economico`, `bibliotecario`, `monitor_extraescolares`, `personal_sanitario`, `conserjeria_pas`, `soporte_plataforma`) | Inicio · Mi cuenta. Panel con estado vacío explicativo en «Accesos directos» |

Esto no es un defecto de 1.8 sino la consecuencia directa de que no exista ningún módulo académico (1.10 en adelante). La alternativa —rellenar el panel con bloques de módulos que no existen— está prohibida por `CLAUDE.md §11` y por el criterio 2 de `REQ-CORE-008`.

**Sobre las seis familias de manual de usuario** (`admin`, `direccion`, `secretaria`, `docente`, `familia`, `estudiante`, `CLAUDE.md §6`): son una agrupación de **documentación por audiencia**, no de *dashboards*. No cubren a siete de los dieciséis roles (orientación, bienestar, economía, biblioteca, extraescolares, enfermería, conserjería) ni a ningún rol personalizado. Usarlas como seis variantes de panel sería exactamente el error de «comprobar el rol en lugar del permiso» de la *skill* `permisos-y-roles`. No se hace.

### 12.3 Flujos

#### 12.3.1 Arranque de la SPA

Se conserva íntegro el orden de `docs/design-system.md §8` (modo de color, paleta cacheada, *branding*, montaje). 1.8 añade, **después** de montar:

1. El *router* resuelve la ruta pedida y su régimen de *layout* (`meta.layout`: `public` | `app` | `bare`).
2. Si la capa B de 1.7 terminó en `not-found` (host sin tenant): se pinta la pantalla de **centro no encontrado**, sin *shell* y **sin llamar a `/me`** (`RN-CORE-35`).
3. Rutas `public`: se pintan como hoy, sin *shell*, **sin llamar a `/me`**.
4. Rutas `app` y `bare`: el *guard* pide `GET /me` **una sola vez** (petición deduplicada, §12.3.4) y:
   - `200` → estado de sesión cargado; se aplica el idioma (§12.3.6); continúa la navegación.
   - `401` → redirige a `/entrar?redirect=<ruta pedida>` (`RN-CORE-28`).
   - `403 urn:pge:error:mfa-enrollment-required` → la única ruta alcanzable es `mfa-enrollment-wall`, con régimen `bare` (§12.3.5).
   - Error de red, `5xx`, `429` → estado de error a pantalla completa con reintento (§12.6); **no** se redirige al *login*: la sesión puede estar perfectamente viva.

#### 12.3.2 Navegar

1. El usuario activa una entrada del menú, un acceso directo o un elemento del *breadcrumb*.
2. Navegación SPA (`router.push`), sin recarga (`REQ-CORE-008` punto 5).
3. Si la ruta declara `meta.permissions` y **ninguno** está en los permisos efectivos, se pinta el estado **«sin acceso»** dentro del *shell* y **la vista de destino no se monta** (no se lanza ninguna de sus peticiones). Es experiencia de usuario, no seguridad: el servidor responde `403` igualmente si alguien la fuerza (`INV-002`, `CA-CORE-070`).
4. Tras la navegación: el foco pasa al encabezado principal de la vista, `document.title` se actualiza a `<título de la vista> · <nombre del centro>` y, en tableta y móvil, el *drawer*/menú se cierra.

#### 12.3.3 Iniciar sesión con destino

1. `/entrar?redirect=/cuenta/sesiones`.
2. Tras el *login* correcto (incluido el segundo factor), la SPA navega al destino **solo si** pasa el saneado de `RN-CORE-28`; si no, a `/`.
3. `LoginView` recibe este cambio (única modificación funcional a una pantalla de `REQ-AUTH`; su flujo, sus errores y sus tests no cambian).

#### 12.3.4 Estado de sesión y su frescura

- **Única fuente**: `GET /me` (el recurso que ya comparte `POST /auth/session`, `UserProfilePresenter`). No hay endpoint nuevo.
- **Singleton en memoria**, mismo patrón que `useTenantBranding` de 1.7 (`shallowRef` de ámbito de módulo, sin Pinia — `ADR-052`, alternativas descartadas). **Nunca** en `localStorage`/`sessionStorage` (`RN-AUTH-28`).
- **Se recarga** (`RN-CORE-26`): tras el *login*; en el arranque; tras un `PATCH /me` correcto (con la respuesta del propio `PATCH`, sin segunda petición); y cuando cualquier petición de la API devuelve un `403` que **no** sea `mfa-enrollment-required` — señal de que los permisos pueden haber cambiado desde la carga (un administrador retiró un rol, o un módulo se descontrató: `403 module-disabled` **también** recarga, `ADR-053 §6`). Deduplicada: varias peticiones concurrentes que fallan provocan una sola recarga. **Restricción** (`ADR-053 §6`): si la recarga la disparó un `module-disabled` y la ruta actual deja de estar permitida tras ella, la vista conserva el estado «módulo no disponible» (§12.6) hasta la siguiente navegación, en vez de pasar a «sin acceso».
- **Se vacía** al cerrar sesión y ante cualquier `401`.

#### 12.3.5 Muro de MFA

El muro ya existe (`REQ-AUTH` 1.3, `client.ts` redirige ante `mfa-enrollment-required`). 1.8 solo garantiza que:

- la ruta `mfa-enrollment-wall` se pinta con régimen `bare`: sin navegación, sin menú de usuario salvo **cerrar sesión** (salir de la cuenta no es salir del muro);
- el *guard* devuelve al muro cualquier intento de navegar a otra ruta `app` mientras `/me` (u otra petición) siga respondiendo `mfa-enrollment-required`;
- no hay bucle: el *guard* no vuelve a pedir `/me` al entrar en el muro si la última respuesta ya fue ese `403`.

Si `GET /me` está en la lista blanca del *middleware* del muro (y responde `200` con `mfa.enforced = true`), el efecto debe ser el mismo; el implementador lo comprueba contra `REQ-AUTH/funcional.md §C.4.9` antes de escribir el *guard* y cubre el caso que corresponda.

#### 12.3.6 Idioma

1. **Con sesión**: `person.locale` de `/me` si pertenece a los `active_locales` del centro (capa B de 1.7); si no, el `default_locale` del centro — la preferencia almacenada **no se modifica** (§6, fila «Idioma retirado de los activos»). Cierra la nota pendiente de `docs/i18n.md` («la preferencia del servidor pasa a tener prioridad»).
2. **Sin sesión**: sin cambios respecto a 1.7 (`usePublicAuthScreen`, `resolveTenantLocale`).
3. **Cambio desde el selector**: ofrece exactamente los `active_locales` del centro, con el nombre de cada idioma en su propia lengua. Al elegir: `PATCH /me` con `person.locale`; con `200`, se aplica `setLocale()` **sin recargar y sin perder el estado de la vista** (`REQ-CORE-006`); con `422` (`core.validation.locale_not_active`, el centro retiró el idioma entretanto), se mantiene el idioma actual y se muestra el mensaje del servidor.

Conversión de vocabulario `es-ES` ↔ `es` exclusivamente por `localeFromDomain()` de `src/i18n` (ya existente).

#### 12.3.7 Modo de color

Control de tres estados (`sistema`, `claro`, `oscuro`) en el menú de usuario, sobre `useColorScheme().setPreference` de 1.7. Sin servidor (`ADR-052 P2`). No aparece en el régimen `bare`: el muro solo ofrece cerrar sesión (§12.3.5).

#### 12.3.8 Cerrar sesión

`DELETE /auth/session` (idempotente, `204`). Con respuesta o sin ella (error de red), la SPA **vacía el estado de sesión** y navega a `/entrar`. La paleta y el modo de color se conservan: son del centro y del navegador, no del usuario.

### 12.4 Panel de inicio (`/`)

Tres bloques, en este orden. Ninguno pide un *endpoint* distinto de `/me` y `/tenant/branding` (ya cargados), así que **el panel no hace ninguna petición propia** en 1.8.

| Bloque | Contenido | Fuente | Cuándo se muestra |
|--------|-----------|--------|-------------------|
| **Bienvenida** | Saludo con `person.given_name`; nombre y logotipo del centro | `/me`, capa B de 1.7 | Siempre. Sin logotipo si `logo_url` es nulo o falla la carga (`reportAssetError`, `docs/design-system.md §7.4`) |
| **Estado de la cuenta** | Aviso de segundo factor obligatorio con los días restantes y enlace a `/cuenta/seguridad` | `/me` → `mfa` (`REQ-AUTH/api.md §C.6`, «avisos en cada acceso») | Solo si `mfa.obligated && !mfa.enrolled`. En cualquier otro caso el bloque no se pinta (no se inventan otros avisos) |
| **Accesos directos** | Las entradas del registro de navegación marcadas como acceso directo y permitidas | Registro de §12.5 + `/me.permissions` | Siempre; **estado vacío explicativo** si no hay ninguna (`RUX-006`) |

**Punto de extensión**: los módulos futuros aportarán bloques del panel declarándolos en `dashboardBlocks` de su `shell.ts` (`ADR-053 §5`): `id`, `titleKey`, `permissions` (anyOf, nunca vacía) y un componente cargado bajo demanda que pide sus propios datos, pinta su propio estado vacío y contiene su propio error. 1.8 fija ese contrato y **no entrega ningún bloque de módulo**.

### 12.5 Registro de navegación

Forma, ensamblado y motivo fijados por `ADR-053` (resuelve `OPEN-CORE-16`). Resumen aplicado a 1.8:

Cada entrada declara (`ADR-053 §4.1`):

| Campo | Significado |
|-------|-------------|
| `id` | `<módulo>.<nombre>`, estable, único en todo el registro |
| `route` | Nombre de ruta (nunca una URL literal) de una ruta `app` |
| `labelKey` | Clave de traducción, en el espacio de nombres del módulo (`INV-009`) |
| `icon` | Componente de `@lucide/vue` (`RUX-ICON-001`/`002`), decorativo (`aria-hidden`); el texto lo da `labelKey` |
| `section` | Una de las del catálogo cerrado de `src/navigation/sections.ts` (`ADR-053 §4.2`) |
| `shortcut` | Si aparece en «Accesos directos» del panel |

**La entrada no declara `permissions`** (`ADR-053 §3`): su visibilidad se deriva de `meta.permissions` de la ruta a la que apunta — una entrada es visible si y solo si el *guard* dejaría montar esa ruta. Los permisos de una pantalla se declaran una sola vez, en la ruta (§12.3.2).

**Secciones de 1.8** (catálogo de `src/navigation/sections.ts`, `ADR-053 §4.2`): `inicio`, `cuenta`, `administracion`. Un módulo futuro no añade la suya: se amplía el catálogo en el paso del primer módulo que la necesite, con justificación propia.

Entradas de 1.8 (solo pantallas que existen, `RN-CORE-25`) y `meta.permissions` (anyOf) de su ruta:

| Entrada | Ruta | `section` | `meta.permissions` (anyOf) de la ruta | Acceso directo |
|---------|------|-----------|-----------------------------------------|----------------|
| Inicio | `home` (`/`) | `inicio` | `[]` (identidad) | No |
| Contraseña | `password-change` | `cuenta` | `[]` (identidad) | No |
| Sesiones abiertas | `sessions` | `cuenta` | `[]` (identidad) | No |
| Seguridad de la cuenta | `mfa-security` | `cuenta` | `[]` (identidad) | Sí |
| Administración de MFA | `mfa-administration` | `administracion` | Los permisos que consumen sus cuatro áreas, según `REQ-AUTH/permisos.md §D.6.3` (el implementador los copia de ahí, no los deduce) | Sí |
| Inicio de sesión institucional (SSO) | `sso-administration` | `administracion` | `proveedor_identidad.leer` | Sí |

Las rutas `sso-administration-new`/`-edit` no son entradas de menú (sin sub-entradas, `ADR-053 §4.4`): son destino de acciones dentro de su vista y aparecen en el *breadcrumb* bajo «SSO».

**Dónde vive y cómo se ensambla** (`ADR-053 §1-§2`): cada módulo declara un único `shell.ts` en su superficie pública, con tres listas (`routes`, `navigation`, `dashboardBlocks`), junto a `api/index.ts`, `types/index.ts` y `locales/`. `src/navigation/modules.ts` importa el `shell` de cada módulo en una lista explícita y ordenada (`moduleShells`) — mismo patrón que `src/i18n/index.ts`, sin descubrimiento automático — y el *router*, el registro de navegación y el panel se construyen concatenando sus listas. **Las rutas de `auth`, hoy en `src/router/index.ts`, se trasladan a `src/modules/auth/shell.ts`** en este paso (mecánico: 1.8 ya reescribe todas las rutas para añadirles `meta`); en `router/index.ts` quedan solo `home`, el *catch-all* y la pantalla de centro no encontrado. El *shell* no importa código interno de ningún módulo, solo su superficie pública (`INV-007`, `CA-CORE-152`).

**Cinco tests de coherencia sobre el registro ya ensamblado** (`ADR-053 §2`, `CA-CORE-103`/`CA-CORE-106`): `id` únicos con prefijo de módulo; nombres de ruta únicos entre todos los módulos; toda entrada apunta a una ruta registrada con `meta.layout === 'app'`; toda `section` existe en el catálogo; toda ruta `app`/`bare` declara `meta.permissions` explícitamente (un `[]` escrito, nunca un campo ausente), con las vacías en la lista cerrada de `RN-CORE-24`.

### 12.6 Estados de carga, vacío y error (`RUX-006`)

Tres componentes de nivel de aplicación (no del *design system*: tienen textos por defecto traducidos, y `RN-DS-24` prohíbe literales en `components/ui`):

| Componente | Semántica | Uso |
|------------|-----------|-----|
| Carga | `role="status"`, `aria-busy="true"` en la región; esqueleto con los tokens de 1.7 | Arranque de sesión, bloques del panel, cualquier vista |
| Vacío | Icono decorativo + título + texto + acción opcional | «Sin accesos directos», y futuras listas vacías |
| Error | `role="alert"`, título + texto + **Reintentar** + referencia `request_id` si la respuesta la trae (`INV-013`, útil para soporte) | Cualquier fallo de carga |

Correspondencia con la API (`ADR-038 §6`), aplicada por una única función:

| Respuesta | Estado que se pinta |
|-----------|---------------------|
| Sin respuesta (`status 0`) | Sin conexión, con reintento |
| `401` | Ninguno: redirección a `/entrar` (§12.3.1) |
| `403 urn:pge:error:mfa-enrollment-required` | Ninguno: muro (§12.3.5) |
| `403 urn:pge:error:module-disabled` | Módulo no disponible para el centro, con el `detail` traducido del servidor (`RMOD-009`). Dispara la recarga de sesión de §12.3.4 (`ADR-053 §6`); si la ruta deja de estar permitida tras la recarga, **conserva este mensaje** hasta la siguiente navegación (no pasa a «sin acceso») |
| Otro `403` | Sin acceso. Dispara la recarga de sesión de §12.3.4 |
| `404` | No encontrado |
| `429` | Demasiadas peticiones, con los segundos de `Retry-After` si vienen |
| `5xx` | Error inesperado, con reintento y `request_id` |

Rutas: `catch-all` → estado «página no encontrada», dentro del *shell* si hay sesión, en régimen público si no.

### 12.7 *Layout* responsive

**`RN-CORE-29` · *Breakpoints*** (`RUX-RESP-001`): 320 px es el **ancho mínimo soportado** (sin desplazamiento horizontal, WCAG 1.4.10); 768, 1024, 1440 y 1920 px son puntos de cambio. En Tailwind v4 se declaran como `--breakpoint-md: 48rem`, `--breakpoint-lg: 64rem` (coinciden con los valores por defecto), `--breakpoint-xl: 90rem` y `--breakpoint-2xl: 120rem` (**sustituyen** a los 1280/1536 por defecto). Es un cambio en `@theme` de `style.css`, dentro de la frontera del *design system*: obliga a actualizar `docs/design-system.md §4` en el mismo *commit* (fuera del ámbito de escritura de esta especificación; queda anotado para el implementador).

**`RN-CORE-30` · Regímenes de navegación** (`RUX-RESP-003`):

| Ancho | Navegación | Disparador |
|-------|------------|------------|
| < 768 | **Menú de hamburguesa**: panel a pantalla completa | Botón de hamburguesa en la barra superior |
| 768 – 1023 | ***Drawer***: panel lateral superpuesto, parcial, con fondo atenuado | Botón en la barra superior |
| ≥ 1024 | **Barra lateral persistente** | Ninguno |
| ≥ 1440 | Igual, con el contenido principal más ancho | — |
| ≥ 1920 | Igual, con anchura máxima del contenido para no superar una longitud de línea legible | — |

Hamburguesa y *drawer* son diálogos modales (`role="dialog"`, `aria-modal`): foco atrapado, `Esc` cierra, el foco vuelve al disparador, `aria-expanded` en el disparador, se cierran al navegar. Se construyen con el componente `sheet` de shadcn-vue (vendorizado según `docs/design-system.md §12.2`, sin dependencia nueva: Reka UI ya está).

**`RN-CORE-31` · Tipografía y medidas** (`RUX-RESP-006`): todo tamaño de fuente del *shell* en `rem`; ninguna clase de tamaño arbitrario en `px` (`text-[NNpx]`).

**`RN-CORE-32` · Objetivos táctiles** (`RUX-RESP-007`): **todo control del *shell*** (hamburguesa, entradas de menú, menú de usuario, opciones de idioma y modo, elementos del *breadcrumb*, botones de los estados) mide al menos 44 × 44 px. **El alcance fuera del *shell* depende de `OPEN-CORE-14`**: los botones base de 1.7 miden hoy 32 px (`size: default` es `h-8`), así que `RUX-RESP-007` no se cumple en ningún formulario del producto.

**Accesibilidad del *shell*** (`RUX-004`): enlace «saltar al contenido» como primer elemento enfocable; *landmarks* únicos (`header`, `nav` con `aria-label`, `main`); entrada activa con `aria-current="page"`; *breadcrumb* como `nav` con `aria-label` y `aria-current="page"` en el último elemento; iconos decorativos con `aria-hidden` y controles solo-icono con nombre accesible traducido (`RUX-ICON-003`/`006`).

**Transiciones** (`RUX-005`): transición de ruta con `--motion-duration-normal`; sin regla propia de movimiento reducido — la regla global de `docs/design-system.md §4.6` ya la anula.

### 12.8 Reglas de negocio

| ID | Regla |
|----|-------|
| `RN-CORE-23` | La visibilidad de toda entrada de navegación, acceso directo o bloque del panel se decide **solo** por los códigos de `GET /me` → `permissions`. **Ningún fichero de `src/` decide por `roles[].code`** ni contiene los códigos de los roles predefinidos como literal. Lo que la interfaz oculta es comodidad; la autorización es del servidor (`INV-002`) |
| `RN-CORE-24` | `meta.permissions` vacía (`[]` explícito) en una ruta `app`/`bare` significa «cualquier usuario autenticado» y **solo** se admite en rutas sin ningún permiso real que exigir sin inventarlo: las cuatro de autoservicio por identidad (`permisos.md §5.2`: Inicio, Contraseña, Sesiones, Seguridad de la cuenta), el muro de MFA (`mfa-enrollment-wall`, régimen `bare`: se alcanza precisamente cuando `GET /me` ya ha fallado con `403`, no hay ningún permiso previo que comprobar) y la página «no encontrada» (*catch-all*: «no encontrado» es igual para cualquiera, con o sin permisos). Lista cerrada de **seis** rutas en un test (`ADR-053 §2`, comprobación 5) — issue [#260](https://github.com/pirexia/plataforma-educativa/issues/260), corregido aquí: la redacción original solo citaba cuatro |
| `RN-CORE-25` | El registro solo contiene entradas cuya ruta existe en el *router*. Ninguna entrada «próximamente» para módulos no implementados |
| `RN-CORE-26` | Estado de sesión en memoria, recargado según §12.3.4. Ninguna vista vuelve a pedir `/me` para comprobar la sesión: lo hace el *guard* (se retira el `getMe()` de comprobación de `SessionsView` y análogas) |
| `RN-CORE-27` | Cerrar sesión vacía el estado de sesión aunque `DELETE /auth/session` falle |
| `RN-CORE-28` | `redirect` solo se acepta si es una ruta relativa del propio origen: empieza por `/`, no por `//` ni `/\`, no contiene esquema, y resuelve a una ruta registrada del régimen `app`. En otro caso se ignora y se va a `/` (evita la redirección abierta, `RSEC-OWASP`) |
| `RN-CORE-29` | *Breakpoints* (§12.7) |
| `RN-CORE-30` | Regímenes de navegación (§12.7) |
| `RN-CORE-31` | Tipografía en `rem` (§12.7) |
| `RN-CORE-32` | Objetivos táctiles del *shell* ≥ 44 × 44 px (§12.7) |
| `RN-CORE-33` | El panel y la navegación no piden ningún *endpoint* que exija un permiso que el usuario no tenga efectivo (sin `403` de ruido, sin sondeo involuntario) |
| `RN-CORE-34` | Precedencia de idioma con sesión: `person.locale` si está activo en el centro, si no `default_locale` del centro (§12.3.6) |
| `RN-CORE-35` | Host sin tenant: pantalla «centro no encontrado», sin *shell* y sin `/me` |

### 12.9 Casos límite

| Situación | Comportamiento |
|-----------|----------------|
| Usuario sin ningún rol (§6, «Usuario que pierde su único rol») | Inicio y «Mi cuenta»; panel con estado vacío en accesos directos. Correcto por `RPERM-011` |
| Un administrador retira un rol a un usuario con la sesión abierta | La siguiente petición que devuelva `403` recarga `/me`; menú y panel se actualizan; si la vista actual deja de estar permitida, pasa a «sin acceso» |
| Cambio de permisos sin ningún `403` intermedio | El menú muestra una entrada ya no permitida hasta la siguiente recarga de `/me`; al usarla, el servidor responde `403` y se corrige. Aceptado: sin sondeo periódico (no lo pide ningún requisito) |
| Módulo descontratado con la sesión abierta | Sus permisos pasan a inertes; la primera petición a él devuelve `403 module-disabled` (estado informativo) y **recarga `/me`** (`ADR-053 §6`, deduplicada). Si el servidor todavía devuelve el permiso (la caché de disponibilidad de módulos de `ADR-045 §8.3` aún no se ha invalidado), la entrada sigue visible hasta la siguiente recarga — sin sondeo, sin bucle: la siguiente respuesta `module-disabled` vuelve a recargar |
| Capa B de 1.7 en `unavailable` (red, `429`, `5xx`) | *Shell* con paleta cacheada o neutra, sin nombre ni logotipo del centro; `document.title` sin sufijo de centro |
| Nombre de centro o de usuario muy largo | Truncado con elipsis en barra superior y menú; texto completo accesible (atributo `title` y nombre accesible) |
| Zoom al 200 % o 320 px de ancho | Sin desplazamiento horizontal; en escritorio con zoom alto se pasa al régimen de *drawer* por *breakpoint* efectivo, que es el comportamiento correcto |
| Cierre de sesión en otra pestaña | La siguiente petición de esta pestaña recibe `401` y redirige a `/entrar` |
| `redirect` manipulado | `RN-CORE-28` |
| `GET /me` responde `404` | Solo ocurre con host sin tenant; se trata como `RN-CORE-35` |

### 12.10 Criterio 1 de `REQ-CORE-008`: «se registra el intento»

El primer criterio de aceptación de `REQ-CORE-008` pide `404` ante un recurso de otro tenant **y que se registre el intento**. La primera mitad ya la cubre `CA-CORE-073`. **La segunda no está cubierta por ningún criterio de 1.1**, y no es de interfaz sino de servidor. Además choca con el diseño de aislamiento: con RLS (`ADR-033`), la aplicación **no puede distinguir** «pertenece a otro tenant» de «no existe» sin salir del contexto de tenant, que es precisamente lo que `ADR-033` impide. Se deja como `OPEN-CORE-15`, issue [#258](https://github.com/pirexia/plataforma-educativa/issues/258); 1.8 no lo implementa.

### 12.11 Criterios de aceptación

Vitest salvo los marcados **[Playwright]** (necesitan *layout* real, *media queries* o medida de cajas). Cada test cita su ID (`INV-015`).

#### *Layout* y responsive

- **`CA-CORE-080`** [`RUX-RESP-001`, `RUX-RESP-002`] **[Playwright]** · **Dado** un usuario autenticado en `/`, **cuando** la ventana mide 320, 768, 1024, 1440 y 1920 px de ancho, **entonces** en los cinco casos `document.documentElement.scrollWidth` ≤ `clientWidth` (sin desplazamiento horizontal).
- **`CA-CORE-081`** [`RUX-RESP-003`] **[Playwright]** · **Dado** un usuario autenticado, **cuando** la ventana mide 1024 px o más, **entonces** la navegación lateral es visible sin interacción y no existe botón de menú; **cuando** mide entre 768 y 1023, la navegación no es visible y un botón de la barra superior abre un panel lateral superpuesto; **cuando** mide menos de 768, un botón de hamburguesa abre el menú a pantalla completa.
- **`CA-CORE-082`** [`RUX-004`] · **Dado** el menú de hamburguesa o el *drawer* abierto, **cuando** se pulsa `Tab` repetidamente, **entonces** el foco no sale del panel; **cuando** se pulsa `Esc`, se cierra y el foco vuelve al botón que lo abrió, cuyo `aria-expanded` pasa a `false`; **cuando** se activa una entrada, se navega y el panel se cierra.
- **`CA-CORE-083`** [`RUX-RESP-006`] · **Dado** `src/layouts/**` y `src/navigation/**`, **entonces** no contienen clases de tamaño de fuente arbitrario en píxeles (`text-[…px]`); con casos fijos en el propio test que prueban que la comprobación detecta `text-[14px]` y no `text-sm`.
- **`CA-CORE-084`** [`RUX-RESP-007`] **[Playwright]** · **Dado** un usuario autenticado a 320 y a 768 px, **cuando** se miden las cajas del botón de menú, de cada entrada de navegación, del menú de usuario y de sus opciones, **entonces** todas miden al menos 44 × 44 px. (El alcance fuera del *shell* lo añade `OPEN-CORE-14`.)
- **`CA-CORE-085`** [`RUX-004`] · **Dado** cualquier ruta del régimen `app`, **cuando** se pulsa `Tab` desde el principio del documento, **entonces** el primer elemento enfocado es «saltar al contenido», y al activarlo el foco pasa a `main`; y el documento tiene exactamente un `header`, un `main` y una `nav` principal con `aria-label`.
- **`CA-CORE-086`** [`RUX-004`] · **Dado** un usuario en `/`, **cuando** navega a `/cuenta/sesiones`, **entonces** el foco pasa al encabezado principal de la vista y `document.title` es `<título traducido de la vista> · <nombre del centro>`.
- **`CA-CORE-087`** [`RUX-005`, `REQ-CORE-008`] **[Playwright]** · **Dado** una navegación entre dos rutas del *shell*, **entonces** no hay recarga de documento (el mismo objeto `window` conserva una marca puesta antes de navegar); **y cuando** el navegador emula `reducedMotion: 'reduce'`, la duración calculada de la transición de ruta es ≤ `0.01ms`.
- **`CA-CORE-088`** [`RUX-003`] · **Dado** la ruta `sso-administration-edit`, **entonces** el *breadcrumb* es una `nav` con `aria-label` que contiene Inicio › SSO › (edición), el último con `aria-current="page"` y sin enlace, y los anteriores como enlaces a sus rutas.

#### Sesión y *router*

- **`CA-CORE-090`** · **Dado** un navegador sin sesión, **cuando** abre `/cuenta/sesiones`, **entonces** acaba en `/entrar?redirect=%2Fcuenta%2Fsesiones`; **y cuando** completa el *login*, llega a `/cuenta/sesiones`.
- **`CA-CORE-091`** [`RN-CORE-28`] · **Dado** `/entrar` con `redirect` igual a `//evil.example`, `https://evil.example`, `/\evil.example`, `javascript:alert(1)` o una ruta inexistente, **cuando** el *login* se completa, **entonces** la SPA navega a `/`.
- **`CA-CORE-092`** · **Dado** un usuario con la sesión cargada en una ruta `app`, **cuando** una petición cualquiera recibe `401`, **entonces** el estado de sesión queda vacío y se navega una sola vez a `/entrar` con `redirect` a la ruta actual, aunque fallen varias peticiones a la vez.
- **`CA-CORE-093`** · **Dado** un usuario cuyo `GET /me` responde `403 urn:pge:error:mfa-enrollment-required`, **cuando** intenta abrir `/` o `/cuenta/sesiones`, **entonces** se pinta el muro de MFA sin navegación ni más opción de menú que cerrar sesión, y `GET /me` no se ha pedido más de una vez.
- **`CA-CORE-094`** · **Dado** cualquier ruta del régimen `public` (`/entrar`, `/recuperar`, `/activar/:token`…), **cuando** se abre, **entonces** no se pinta el *shell* y no se pide `GET /me`.
- **`CA-CORE-095`** [`INV-002`] · **Dado** una ruta de prueba con `meta.permissions = ['fixture.leer']` y un usuario sin ese permiso, **cuando** navega a ella, **entonces** se pinta el estado «sin acceso» dentro del *shell* y la vista de destino no se monta (ninguna de sus peticiones sale).
- **`CA-CORE-096`** · **Dado** una ruta inexistente, **cuando** la abre un usuario con sesión, **entonces** ve «página no encontrada» dentro del *shell*; **y sin sesión**, en régimen público.
- **`CA-CORE-097`** [`RN-CORE-27`] · **Dado** un usuario con sesión, **cuando** cierra sesión (y también cuando `DELETE /auth/session` falla por red), **entonces** el estado de sesión queda vacío, se navega a `/entrar`, y al volver atrás con el historial no aparece el nombre del usuario anterior en ninguna parte del documento.
- **`CA-CORE-098`** [`RN-CORE-26`, `ADR-053 §6`] · **Dado** un usuario con la sesión cargada, **cuando** tres peticiones concurrentes reciben un `403` genérico, **entonces** `GET /me` se pide una sola vez; **y cuando** reciben `403 module-disabled`, también se pide una sola vez.
- **`CA-CORE-099`** [`ADR-053 §6`] · **Dado** un usuario en una vista cuyo permiso se ha vuelto inerte, **cuando** una petición de esa vista recibe `403 module-disabled` y la recarga de `/me` confirma que la ruta actual ya no está permitida, **entonces** la vista sigue mostrando el estado «módulo no disponible» (con el `detail` del servidor) y no pasa a «sin acceso» hasta la siguiente navegación.

#### Navegación y permisos

- **`CA-CORE-100`** [`REQ-CORE-008`, `RPERM-011`] · **Dado** un usuario cuyo `/me.permissions` está vacío, **cuando** carga el *shell*, **entonces** la navegación contiene exactamente Inicio y las tres entradas de «Mi cuenta»; **y dado** uno con `proveedor_identidad.leer`, aparece además «SSO».
- **`CA-CORE-101`** [`REQ-CORE-008` criterio 2, `RMOD-008`] · **Dado** un registro con una entrada de prueba de un módulo `fixture` que exige `fixture.leer`, **y** un `/me` sin ese permiso (como lo devuelve el servidor cuando el módulo está descontratado: permiso inerte, `REQ-PERM/api.md §7.2`), **cuando** se carga el panel, **entonces** el texto de esa entrada no aparece en ningún lugar del documento (menú, accesos directos, *breadcrumb*).
- **`CA-CORE-102`** [`RN-CORE-23`] · **Dado** `src/` salvo tests, **entonces** ningún fichero contiene como literal ninguno de los 16 códigos de rol predefinidos ni accede a `roles[…].code`/`.code` de un rol para decidir; con casos fijos en el test que prueban que detecta `'administrador_centro'` y `roles.some(r => r.code === …)`.
- **`CA-CORE-103`** [`RN-CORE-24`, `RN-CORE-25`, `ADR-053 §2`] · **Dado** el registro de navegación ensamblado, **entonces** toda entrada apunta a un nombre de ruta registrado en el *router* con `meta.layout === 'app'`, y las únicas rutas `app`/`bare` con `meta.permissions` vacía (`[]` explícito) son las de Inicio, Contraseña, Sesiones, Seguridad, el muro de MFA (`mfa-enrollment-wall`, `bare`) y el *catch-all* — **seis**, no cuatro (`RN-CORE-24`).
- **`CA-CORE-106`** [`ADR-053 §2`] · **Dado** el registro ensamblado, **entonces**: (a) todo `id` de entrada y de bloque de panel es único en todo el registro y lleva el prefijo de su módulo (`auth.sessions`, `core.users`); (b) todo nombre de ruta es único entre todos los módulos; (c) toda `section` de toda entrada existe en el catálogo de `src/navigation/sections.ts`; con casos fijos en el propio test que introducen un duplicado o una sección inexistente y comprueban que el test los detecta.
- **`CA-CORE-104`** [`RUX-004`] · **Dado** un usuario en `/cuenta/seguridad`, **entonces** la entrada correspondiente del menú lleva `aria-current="page"` y ninguna otra.
- **`CA-CORE-105`** [`RN-CORE-33`] · **Dado** un usuario sin `modulo.leer` ni ningún otro permiso, **cuando** carga el panel, **entonces** las únicas peticiones a la API son `GET /tenant/branding` y `GET /me`.

#### Panel de inicio

- **`CA-CORE-110`** · **Dado** un `/me` con `given_name = 'Ana'` y un *branding* con nombre y logotipo, **cuando** se carga `/`, **entonces** el saludo contiene «Ana», aparece el nombre del centro y el logotipo con `alt` igual al nombre; **y cuando** el logotipo falla al cargar, se llama a `reportAssetError` con su URL.
- **`CA-CORE-111`** [`REQ-AUTH-003`] · **Dado** `/me.mfa = { obligated: true, enrolled: false, days_remaining: 3 }`, **cuando** se carga `/`, **entonces** aparece el aviso con «3» y un enlace a `/cuenta/seguridad`; **y dado** `obligated: false` o `enrolled: true`, el bloque no existe en el documento.
- **`CA-CORE-112`** [`RUX-006`] · **Dado** un usuario sin ningún acceso directo permitido, **cuando** se carga `/`, **entonces** el bloque de accesos directos muestra el estado vacío con título y texto traducidos, no una región en blanco.
- **`CA-CORE-113`** [`RUX-006`] · **Dado** `GET /me` sin respuesta todavía, **entonces** se pinta el estado de carga con `role="status"`; **y cuando** responde `503` con `request_id`, se pinta el estado de error con `role="alert"`, el `request_id` visible y un botón «Reintentar» que repite `GET /me`.
- **`CA-CORE-114`** · **Dado** `src/views/HomeView.vue` de 0.5, **entonces** ya no existe ni se pide `/health` desde ninguna vista (issue #86).

#### Menú de usuario, idioma y modo de color

- **`CA-CORE-120`** [`REQ-CORE-006`] · **Dado** un centro con `active_locales = ['es-ES','fr']`, **cuando** se abre el selector de idioma, **entonces** ofrece exactamente esos dos; **y cuando** se elige `fr`, se envía `PATCH /me` con `{"person":{"locale":"fr"}}`, la interfaz pasa a francés sin recargar el documento y un campo de texto escrito antes del cambio conserva su valor.
- **`CA-CORE-121`** · **Dado** que `PATCH /me` responde `422 core.validation.locale_not_active`, **cuando** se elige un idioma, **entonces** la interfaz conserva el idioma anterior y muestra el mensaje del servidor con `role="alert"`.
- **`CA-CORE-122`** [`RN-CORE-34`] · **Dado** un usuario con `person.locale = 'de'` en un centro cuyos idiomas activos son `['es-ES','en']` y por defecto `en`, **cuando** carga la aplicación con sesión, **entonces** la interfaz está en inglés y no se envía ningún `PATCH /me`.
- **`CA-CORE-123`** [`RNF-UX-004`] · **Dado** el menú de usuario, **cuando** se abre el control de modo de color, **entonces** es un grupo de tres opciones con semántica de radio, marca la preferencia vigente, y elegir «oscuro» llama a `setPreference('dark')`.
- **`CA-CORE-124`** · **Dado** el menú de usuario, **entonces** muestra el nombre del usuario y enlaces a contraseña, sesiones y seguridad, y la opción de cerrar sesión.

#### Pantallas existentes

- **`CA-CORE-130`** · **Dado** las rutas `password-change`, `sessions`, `mfa-security`, `mfa-administration` y `sso-administration*`, **cuando** se abren con sesión, **entonces** se pintan dentro del *shell* (con navegación y *breadcrumb*) y **todos sus tests preexistentes siguen en verde** sin más reescritura que la retirada de la comprobación de sesión propia (`RN-CORE-26`).
- **`CA-CORE-131`** · **Dado** `mfa-enrollment-wall`, **entonces** usa el régimen `bare` (§12.3.5).

#### Estados y errores

- **`CA-CORE-140`** [`RUX-006`, `RMOD-009`] · **Dado** la función de correspondencia de §12.6, **cuando** recibe cada una de las respuestas de su tabla, **entonces** devuelve el estado indicado; para `403 module-disabled` el texto es el `detail` del servidor; para `429` con `Retry-After: 30`, el texto contiene «30».
- **`CA-CORE-141`** [`RN-CORE-35`] · **Dado** la capa B de 1.7 en `not-found`, **cuando** se abre cualquier ruta, **entonces** se pinta «centro no encontrado» sin *shell* y no se pide `GET /me`.

#### Transversales

- **`CA-CORE-150`** [`INV-009`] · **Dado** los cuatro `locales/*.json`, **entonces** toda clave nueva de 1.8 existe en `es`, `en`, `de` y `fr`, y `npm run lint:i18n` termina sin hallazgos.
- **`CA-CORE-151`** [`RN-AUTH-28`] · **Dado** el módulo del estado de sesión, **entonces** no lee ni escribe `localStorage` ni `sessionStorage` (test con ambos simulados que falla ante cualquier llamada), y tras un ciclo *login* → *logout* ninguna clave nueva queda en ninguno de los dos.
- **`CA-CORE-152`** [`ADR-053 §1`] · **Dado** `src/layouts/**` y `src/navigation/**`, **entonces** no importan nada de `src/modules/*/` salvo su superficie pública (`api/index.ts`, `shell.ts`, `types/index.ts`) (`INV-007`); y ningún módulo importa el `shell.ts` de otro módulo.

### 12.12 Documentación a actualizar al cerrar 1.8

- Este documento: estado de §12 y de §12.1.3 según `OPEN-CORE-12`.
- `docs/design-system.md §4`: *breakpoints* (`RN-CORE-29`) y los componentes vendorizados que se añadan (`sheet`, y los que necesite el menú) en §12.1.
- `docs/i18n.md`: selector de idioma entregado; precedencia con sesión (`RN-CORE-34`).
- `docs/manual-usuario/admin.md`: navegación, panel, selector de idioma y control de modo de color (sustituye la línea «llega con el paso 1.8»). Los otros cinco manuales no existen (issue [#65](https://github.com/pirexia/plataforma-educativa/issues/65)); si se crean en este paso, con la misma sección común.
- `ARCHITECTURE.md` (frontend: regímenes de *layout*, registro de navegación), `CHANGELOG.md`.
- `PRIVACY.md`: **sin cambios esperados** (ninguna clave nueva de almacenamiento del navegador, `CA-CORE-151`); `doc-reviewer` lo confirma.

### 12.13 Hallazgos fuera del ámbito de esta especificación

No se corrigen aquí; se reportan:

1. `SessionsView.vue` (y previsiblemente otras vistas de `REQ-AUTH`) importa `useI18n` de `vue-i18n` directamente, contra la regla de `docs/i18n.md` («un componente nunca hace `import { useI18n } from 'vue-i18n'`»). No lo detecta ningún test. Reportado como issue [#259](https://github.com/pirexia/plataforma-educativa/issues/259) (Baja), no corregido a propósito.
2. El encargo de este paso cita los `RUX-*` como «sección 5» del documento de requisitos; están en la **sección 10**. Sin efecto salvo en las referencias.
3. `docs/design-system.md §1.2` y `OPEN-CORE-02` se contradicen sobre dónde van las pantallas de `REQ-CORE` (`OPEN-CORE-12`).

### 12.14 Preguntas abiertas del paso 1.8

#### `OPEN-CORE-12` · ¿Entran en 1.8 las pantallas pendientes de `REQ-CORE`? — **RESUELTO por el usuario** (2026-09-23)

Contradicción descrita en §12.1.3: `OPEN-CORE-02` (aprobada) dice «dentro de 1.8, no como 1.8b»; `PLAN-IMPLEMENTACION.md` no las incluye; `docs/design-system.md §1.2` (aprobado después) las sitúa «posterior a 1.8»; y la mayoría son listados que dependen de 1.9.

**Decisión: opción A.** Se diferirán a un paso propio tras 1.9 (`1.9b`, junto a `1.5b`, que ya está ahí por el mismo motivo). `OPEN-CORE-02` queda reescrita por esta decisión: las pantallas de `REQ-CORE` no van dentro de 1.8. `REQ-CORE` sigue sin cumplir `CLAUDE.md §10` en su totalidad hasta que se cierre `1.9b` — no es una regresión de 1.8, es la continuación pendiente del propio módulo, igual que `1.5b` lo es de `REQ-PERM`. `PLAN-IMPLEMENTACION.md` se actualiza con el paso `1.9b` en el mismo commit que esta especificación. No cambia nada más de §12: con esta opción, lo aquí escrito (*shell*, navegación, panel) queda exactamente igual.

#### `OPEN-CORE-13` · *Widgets* configurables y *dashboards* por defecto por rol (`REQ-CORE-008` puntos 3-4) — **RESUELTO por el usuario** (2026-09-23)

`REQ-CORE-008` pide *widgets* configurables (próximos eventos, tareas pendientes, notificaciones, calendario, accesos directos) y que el administrador defina *dashboards* por defecto por rol. De los cinco *widgets*, solo «accesos directos» tiene datos hoy. «Tareas pendientes» **no tiene módulo dueño identificable** en el documento de requisitos (¿tareas del LMS? ¿de secretaría? ¿aprobaciones de `REQ-PERM`?), pregunta que sigue sin responder y queda anotada para cuando corresponda decidir la opción B.

**Decisión: opción A.** Se difiere la configuración (por usuario y por rol) al primer paso que aporte un segundo bloque de panel con datos reales (candidato: `REQ-COM`, 1.19, notificaciones). 1.8 entrega solo el panel fijo de §12.4 y el punto de extensión de §12.4/§12.5. Sin tabla, sin *endpoint*, sin permiso nuevo en este paso.

#### `OPEN-CORE-14` · Alcance de los 44 × 44 px (`RUX-RESP-007`) — **RESUELTO por el usuario** (2026-09-23)

Los componentes base de 1.7 miden 24-36 px (`button` por defecto 32 px). Hoy ninguna pantalla del producto cumple `RUX-RESP-007`. `RN-CORE-32` lo garantiza solo en el *shell*.

**Decisión: opción B.** Todo el producto, solo en punteros gruesos: variante `any-pointer: coarse` en los componentes base de 1.7 que eleva la altura mínima a 44 px en dispositivos táctiles, sin cambiar el escritorio con ratón — lectura literal de «objetivos **táctiles**». Esto toca los ocho componentes base de 1.7 (fuera de la frontera estricta de `apps/web/src/layouts`/`src/navigation` de este paso, pero dentro de `apps/web`) y exige ampliar `docs/design-system.md §12` en el mismo *commit* que 1.8. `CA-CORE-084` se amplía: además de medir el *shell* a 320/768px, verifica en un componente base (`button`) que la altura mínima solo sube a 44px cuando el test emula `(any-pointer: coarse)`, no en el caso por defecto.

#### `OPEN-CORE-15` · «Se registra el intento» de acceso a un recurso de otro tenant (criterio 1 de `REQ-CORE-008`) — **abierta, no bloqueante, issue [#258](https://github.com/pirexia/plataforma-educativa/issues/258)**

Descrito en §12.10. Con RLS la aplicación no sabe que el recurso es de otro tenant; registrar «el intento» exigiría o bien registrar **todo** `404` por `public_id` (volumen y ruido, sin distinguir errores legítimos), o bien una comprobación con `runAsPlatform()` en cada `404`, contraria al espíritu de `ADR-033`/`ADR-046`. Ninguna de las dos es de 1.8 (interfaz), por eso no bloquea el cierre de este paso. Queda documentada como issue de servidor para cuando le toque turno; opciones registradas en el issue.

#### `OPEN-CORE-16` · ¿ADR para la convención de navegación de los 53 módulos? — **RESUELTO por `ADR-053`** (2026-09-23, ratificado por el usuario)

El registro de §12.5 (y el punto de extensión de bloques del panel de §12.4) es una convención transversal que copiarán todos los módulos de frontend, igual que `OPEN-CORE-09` lo fue para la API. `architect` redactó `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md`, que fija la forma de la entrada, dónde vive cada `shell.ts` y cómo se ensambla, el contrato de los bloques del panel, y que `403 module-disabled` también recarga `/me`. §12.3.4, §12.4, §12.5, §12.6, §12.8, §12.9 y los criterios de aceptación de §12.11 ya reflejan su contenido.

#### `OPEN-CORE-17` · Ilustraciones en estados vacíos (`RUX-ICON-005`, `RUX-ICON-007`) — **RESUELTO por el usuario** (2026-09-23)

`RUX-ICON-005` prevé ilustraciones de librerías públicas (unDraw, Humaaans, Blush) para estados vacíos. Incorporarlas exige registrar su licencia (`RUX-ICON-007`), que no existe como procedimiento, y cada una necesita variante clara/oscura o tratamiento por tokens (`RN-DS-19` prohíbe colores literales).

**Decisión: solo iconos de Lucide.** Cero activos gráficos nuevos en 1.8; §12.1.2 (fila «Ilustraciones en estados vacíos») queda confirmada como fuera de alcance sin condición pendiente. El procedimiento de registro de licencia de `RUX-ICON-007` se define cuando llegue el primer paso que sí incorpore ilustraciones.

### 12.15 ¿Se aprueba esta especificación?

**Sí, aprobada el 2026-09-23.** `OPEN-CORE-12` (opción A, paso `1.9b`), `OPEN-CORE-13` (opción A, diferir configuración), `OPEN-CORE-14` (opción B, `any-pointer: coarse`) y `OPEN-CORE-17` (solo iconos) resueltas por el usuario; `OPEN-CORE-16` resuelta por `ADR-053` (`architect`), ratificado por el usuario sin cambios. `OPEN-CORE-15` (issue #258) no bloquea: es trabajo de servidor fuera de 1.8. Lista para pasar a `implementer`.
