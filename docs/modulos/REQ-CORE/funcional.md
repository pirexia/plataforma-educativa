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

> **Paso 1.8 (`REQ-CORE-008`, *layout*, navegación y panel de inicio): §12, APROBADA** (2026-09-23; implementada y mezclada, PR #264). Las secciones §0-§11 son las de 1.1 y **no se reabren**; §12 se añade detrás, mismo criterio que `REQ-BO/funcional.md §15.x` para sub-pasos sucesivos del mismo módulo.
>
> **Paso 1.9 (tablas de datos: TanStack Table, filtrado, ordenación, columnas configurables y exportación; sin virtualización, `OPEN-CORE-19`): §13, APROBADA** (2026-09-30), **ajustada a `ADR-054`, ratificado entero por el usuario el 2026-09-30 (ACEPTADA)**. Lista para `implementer`. Ubicación definitiva: aquí (`OPEN-CORE-18`, resuelta por el usuario el 2026-09-30). §0-§12 no se reabren.

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
5. **Neutralización de fórmulas (`RN-CORE-36`, issue #268).** Toda celda de texto del CSV cuyo primer carácter sea `=`, `+`, `-`, `@`, tabulador, retorno de carro o salto de línea se escribe con un apóstrofo delante, para que Excel/LibreOffice/Sheets no la interpreten como fórmula activa (inyección de fórmulas CSV, OWASP). Aplica a la cabecera y a todas las filas.

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
- **`CA-CORE-055`** · *Dado* un registro de auditoría cuyo actor tiene un nombre que empieza por `=`, `+`, `-`, `@`, tabulador, CR o LF, *cuando* se genera la exportación CSV, *entonces* esa celda se escribe precedida de apóstrofo y nunca como fórmula activa (`RN-CORE-36`).

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
| Estado | **APROBADA** (2026-09-23). `OPEN-CORE-12`/`-13`/`-14`/`-17` resueltas por el usuario; `OPEN-CORE-16` resuelta por `ADR-053` (`architect`, ratificado por el usuario); `OPEN-CORE-15` abierta y no bloqueante (issue #258, servidor, fuera de 1.8). **Implementación entregada** (2026-09-23, rama `feature/REQ-CORE-008-layout-navegacion-dashboards`): 561/561 Vitest y 10/10 Playwright en verde, ESLint/`lint:i18n`/`vue-tsc`/`build` limpios. Issue [#260](https://github.com/pirexia/plataforma-educativa/issues/260) (Media, lista cerrada de `RN-CORE-24`/`CA-CORE-103` citaba cuatro rutas en vez de seis) **corregido y cerrado** (commit `b11e2c9`). Revisión independiente (`security-reviewer`/`doc-reviewer`, `db-reviewer` no aplica: cero migraciones) completa, sin Crítico/Alto. Issue [#261](https://github.com/pirexia/plataforma-educativa/issues/261) (Media, mecanismo de renderizado de bloques de panel sin construir) diferido a propósito por decisión del usuario (2026-09-24, ver §12.4); issues [#262](https://github.com/pirexia/plataforma-educativa/issues/262) corregido, [#263](https://github.com/pirexia/plataforma-educativa/issues/263) (Baja) documentado sin corregir. **Listo para mezclar** |

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

**Punto de extensión**: los módulos futuros aportarán bloques del panel declarándolos en `dashboardBlocks` de su `shell.ts` (`ADR-053 §5`): `id`, `titleKey`, `permissions` (anyOf, nunca vacía) y un componente cargado bajo demanda que pide sus propios datos, pinta su propio estado vacío y contiene su propio error. 1.8 fija ese contrato **como tipo y como función de filtrado** (`visibleDashboardBlocks()` en `src/navigation/registry.ts`) y **no entrega ningún bloque de módulo**. **Pendiente, issue [#261](https://github.com/pirexia/plataforma-educativa/issues/261) (Media, decisión del usuario 2026-09-24, diferida a propósito)**: el mecanismo de renderizado genérico que itera el registro y contiene el fallo de cada bloque (`ADR-053 §5.2.4`) no existe todavía — `HomeView.vue` pinta sus tres bloques fijos a mano. Lo construye el primer paso que aporte un `dashboardBlocks` real (candidato: `REQ-COM`/1.19), no bloquea el cierre de 1.8.

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
| `RN-CORE-36` | Exportación CSV: toda celda que empiece por `=`, `+`, `-`, `@`, tabulador, CR o LF se escribe precedida de apóstrofo (`§4.6` paso 5) |

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

---

## 13. Paso 1.9 · Tablas de datos

| Campo | Valor |
|-------|-------|
| Paso | **1.9** (`PLAN-IMPLEMENTACION.md`, Bloque B): «TanStack Table con filtrado, ordenación, columnas configurables y exportación». La virtualización que enumeraba la redacción original del plan queda **fuera** por decisión del usuario (`OPEN-CORE-19`, 2026-09-30; `ADR-054 §3`); la línea del plan ya está actualizada |
| ADR | **`ADR-054`** (`docs/adr/ADR-054-tablas-de-datos-y-exportacion-de-listados.md`), **ACEPTADA** (redactado 2026-09-30; ratificado entero por el usuario el 2026-09-30) |
| Requisitos de origen | **Ningún `REQ-*` propio.** Lo gobiernan requisitos transversales: `RUX-RESP-004` (tablas en móvil), `RUX-004`/`RNF-UX-002` (WCAG 2.2 AA), `RUX-006`/`RNF-UX-005` (estados), `RUX-RESP-006`/`-007`, `RNF-UX-007`, `INV-009` (i18n), `RNF-MANT-007` (dependencias tras interfaz propia), `RNF-COMP-004` (formatos de exportación), `RNF-LIM-004` (límites de exportación), `RPERM-003` (acción `exportar`), y las decisiones `ADR-023` (TanStack Table), `ADR-038 §4`/`§5`/`§13.3` (paginación, filtros, orden, encaje con TanStack) y `ADR-052` (*design system*) |
| Requisitos de módulo que lo usan | `REQ-CORE-003`/`-004`/`-005` (pantallas de `1.9b`), `RPERM-005`/`-006`/`-009` (`1.5b`); primitiva de exportación de `REQ-CORE-005` (`data_exports`, `ExportRequestService`, §7) |
| Depende de | 1.7 (`docs/design-system.md`, componente `table`, tokens, `RN-DS-*`), 1.8 (componentes de estado y correspondencia de errores de §12.6, *breakpoints* `RN-CORE-29`, objetivos táctiles `OPEN-CORE-14`), 1.1 (`GET /data-exports/{id}`, `POST /audit-logs/exports`). **Todas implementadas.** Ninguna dependencia no implementada para lo que este paso especifica. **Dependencia operativa no resuelta**: ninguna exportación asíncrona termina en un entorno real mientras no exista un *worker* de colas desplegado (issue [#128](https://github.com/pirexia/plataforma-educativa/issues/128), Alta) — ver §13.14.6 y riesgos |
| Código afectado | **Solo `apps/web`** (§13.1.3 justifica por qué la regla común de CSV no se implementa aquí). Ni un *endpoint*, ni un permiso, ni una migración (`api.md §13`, `permisos.md §11`, `datos.md` Parte C, `operacion.md §12`) |
| Estado | **APROBADA** (2026-09-30, decisión del usuario), **ajustada a `ADR-054`, ratificado entero por el usuario el 2026-09-30 (ACEPTADA)**. `OPEN-CORE-18` a `OPEN-CORE-29` resueltas por el usuario (§13.21), las de `-19` a `-29` registradas en el ADR. Las precisiones y correcciones que `ADR-054` añade (§2.3, §5.3, §6.2, §8.1-§8.3, §9, §10.1, §10.2 del ADR) están incorporadas y **son firmes**. `OPEN-054-01` (idioma del CSV) sigue abierta: bloquea `1.9b`, no `1.9`. **Implementada** (2026-09-30, rama `feature/REQ-CORE-008-tablas-de-datos`, notas en §13.24); pendiente de revisión independiente y mezcla. Antes: «Lista para `implementer`» (§13.23) |

### 13.0 Ubicación de esta especificación

`REQ-CORE/funcional.md §12.0` justificó meter 1.8 aquí con tres motivos. **1.9 solo cumple dos de ellos, y hay que decirlo**:

1. **Requisito de módulo propio: no lo tiene.** 1.9 se parece más a 1.7 que a 1.8: es infraestructura de presentación transversal, sin `REQ-*` que la gobierne. Por el criterio de `ADR-052 §6`, lo coherente sería un documento transversal (`docs/tablas-de-datos.md`, o una ampliación de `docs/design-system.md`), igual que `docs/design-system.md` y `docs/i18n.md`.
2. **Consumidores en esta misma carpeta: sí.** Los primeros consumidores reales son las pantallas de `REQ-CORE` de `1.9b` (§12.1.3), y la exportación se apoya en una primitiva que ya es de `REQ-CORE` (`data_exports`, `ExportRequestService`, `RN-CORE-36`).
3. **Volumen: sí.** Sin modelo de datos ni API nuevos, los otros cuatro documentos solo ganan una sección corta.

Se redactó aquí por el precedente de 1.8 y porque el ámbito de escritura de quien especifica se limita a `docs/modulos/`. **Decisión del usuario (2026-09-30, `OPEN-CORE-18`, opción A): la especificación se queda aquí**, en `REQ-CORE/funcional.md §13`, con los identificadores `RN-CORE-*`/`CA-CORE-*`/`OPEN-CORE-*`. No hay mudanza a un documento transversal.

### 13.1 Alcance

#### 13.1.1 Entra en 1.9

| # | Qué | Requisitos |
|---|-----|------------|
| 1 | **Componente de tabla de datos reutilizable** de nivel de aplicación sobre `components/ui/table` (shadcn-vue) y `@tanstack/vue-table`, con **`@tanstack/vue-table` envuelto**: un único punto de importación (§13.3) | `ADR-023`, `RNF-MANT-007` |
| 2 | **Contrato de columnas propio** (§13.4), del que salen la tabla, la vista de tarjetas, el menú de columnas y la serialización de filtros y orden | `ADR-038 §13.3` |
| 3 | **Dos modos de paginación** con criterio de `ADR-038 §4.2`: por página (paginador numerado) y por cursor («cargar más» como botón, **sin desplazamiento infinito**, sin total, con **tope de 1.000 filas acumuladas**, `RN-CORE-52`) (§13.5) | `ADR-038 §4.3`, `§4.4`, `§4.5`; `OPEN-CORE-19` |
| 4 | **Ordenación** de una sola columna, en servidor (§13.6) | `ADR-038 §5.3` |
| 5 | **Filtrado** en servidor con parámetros planos: texto libre `q`, enumerados múltiples por comas, rangos `_from`/`_to`, booleanos (§13.7) | `ADR-038 §5.2` |
| 6 | **Columnas configurables**: **visibilidad más «restablecer»**, sin reordenar ni redimensionar (`OPEN-CORE-21`), con persistencia por navegador (§13.8) | Plan |
| 7 | **Vista de tarjetas por debajo de 768 px**, con **desplazamiento horizontal interno como opción por tabla** (`OPEN-CORE-20`) (§13.9) | `RUX-RESP-004` |
| 8 | **Estados** de carga, vacío (dos variantes) y error, reutilizando los componentes y la correspondencia de §12.6 (§13.10) | `RUX-006`, `RNF-UX-005`, `RNF-UX-007` |
| 9 | **Accesibilidad** de tabla, tarjetas, controles y anuncios (§13.11) | `RUX-004`, `RNF-UX-002` |
| 10 | **Disparador de exportación asíncrona en servidor** y seguimiento de su estado sobre `GET /data-exports/{id}` (§13.14). Sin generación de ficheros en el cliente | `RPERM-003`, `REQ-CORE-005`, `INV-012` |
| 11 | **Migración de `MfaComplianceArea.vue`** al componente nuevo, con paridad estricta (`OPEN-CORE-28`, §13.15). De las cuatro tablas existentes, **1.9 migra solo esta**; las otras tres quedan como excepciones explícitas (§13.2, `RN-CORE-53`) | `RNF-MANT-007` |
| 12 | **Tests de arquitectura**: frontera del componente, importación única de TanStack y **toda tabla nueva por el componente**, con lista cerrada de excepciones que solo puede reducirse (`RN-CORE-53`, `OPEN-CORE-29`) (§13.3) | `ADR-052 §3.3` (precedente) |
| 13 | **Estado de la consulta en la URL** (página, `per_page`, orden, enumerados, booleanos y fechas), **nunca `q` ni `cursor`**; **opcional por tabla y como máximo una por ruta** (`RN-CORE-54`, `OPEN-CORE-22`) (§13.5) | `ADR-038 §6.5` (mismo motivo), `ADR-054 §6` |
| 14 | **Valor vacío en celda** común, `dataTable.emptyValue` (`OPEN-CORE-27`) (§13.12) | `INV-009`, issue #90 |

#### 13.1.2 No entra en 1.9

| Fuera | Dónde va | Motivo |
|-------|----------|--------|
| Pantallas de usuarios, invitaciones, importación, roles, auditoría, configuración y activos | **`1.9b`** | `OPEN-CORE-12`. 1.9 entrega el componente, no pantallas de negocio |
| Migración al componente de `MfaExemptionsArea.vue`, `AdminSsoView.vue` y `SessionsView.vue` | **`1.9b`** o paso posterior (decisión del usuario 2026-09-30) | 1.9 migra solo `MfaComplianceArea.vue`. Las tres quedan como **excepciones explícitas** de `RN-CORE-53` (§13.2) |
| Matriz de concesión de permisos (recurso × acción × ámbito) | **`1.5b`** | Rejilla de edición bidimensional, no un listado. Ver §13.15 |
| **Virtualización** (y la dependencia `@tanstack/vue-virtual`) | **No se hace** (`OPEN-CORE-19`, opción A, decisión del usuario 2026-09-30) | §13.13: con los contratos de `ADR-038` el único caso real es el modo `cursor`, y lo resuelve el tope de `RN-CORE-52` sin coste de accesibilidad. Reversible dentro de `src/data-table` sin tocar consumidores (`RN-CORE-37`) |
| **Nuevos *endpoints* de exportación** (`POST /users/exports` para `usuario.exportar`, etc.) | El paso que construya la pantalla que los necesite (candidato: `1.9b`) | Son `apps/api`. 1.9 solo entrega el disparador genérico en el cliente. `usuario.exportar` existe en el catálogo sin *endpoint* (`permisos.md §7`) |
| **Clase compartida de escritura CSV y neutralización** (issue [#270](https://github.com/pirexia/plataforma-educativa/issues/270)) | Rama `fix/` propia tras la ratificación de `ADR-054` | §13.1.3 |
| Exportación **XLSX** (`RNF-COMP-004`) | Sin paso | Exige una dependencia PHP nueva, con su justificación (`CLAUDE.md §1`). Ningún consumidor la pide todavía. Exportación PDF: 1.17 (`funcional.md §4.6`) |
| Pantalla «Mis exportaciones» y `GET /data-exports` | Se reconsidera en `1.9b`, primer consumidor real (`OPEN-CORE-25`, opción A) | No existe *endpoint* de listado de `data_exports` (solo `GET /data-exports/{id}`). 1.9 avisa en su lugar (§13.14.4) |
| Modo `local` (colecciones sin paginar, ordenación y filtrado en cliente) | El primer paso con un consumidor real (candidato **`1.5b`**), dentro de `src/data-table`, sin tocar `ADR-054` (`OPEN-CORE-26`, opción B; `ADR-054 §2.1`) | Sin consumidor en 1.9 ni en 1.9b. Es aditivo |
| Desplazamiento infinito en modo `cursor` | **No se hace en ningún paso** | `ADR-054 §2.2` lo retira para todo el producto: deja inalcanzable con teclado lo que hay debajo de la lista y no ancla foco ni anuncios a una acción del usuario |
| Selección de filas y acciones masivas | Sin paso | Ningún requisito ni consumidor de 1.9b/1.5b las pide. Añadirlas es aditivo |
| Orden multicolumna, `OR` entre campos, filtros por columna con operadores | No se hace | `ADR-038 §5.2`/`§5.3`: «no» consciente |
| Edición en línea de celdas | Sin paso | Ningún requisito |
| Reordenar y redimensionar columnas | No se hace en 1.9 (`OPEN-CORE-21`, opción A) | Ningún consumidor conocido lo necesita. El formato versionado de `RN-CORE-43` permite añadir el orden después sin romper nada. Si llega, sin arrastre (WCAG 2.5.7) |

#### 13.1.3 Por qué la regla común de CSV se decide en 1.9 pero no se implementa aquí

El issue #270 pide extraer la neutralización de fórmulas a una utilidad compartida solo para cadenas, «decidir en el ADR de 1.9», y aplicarla también a `ValidateUserImport::writeReport`. `SECURITY.md` (fila «Exportaciones generadas (CSV)») promete fijarla «como norma común en el ADR de tablas de datos y exportación de listados del paso 1.9».

- **La regla es transversal y la fija `ADR-054 §10`**: aquí se recoge como `RN-CORE-47` y `RN-CORE-48` (§13.14.3).
- **Su implementación es código de `apps/api`**: extraer `neutralizeCsvCell` de `GenerateAuditLogExport`, reescribir los tests que hoy usan `ReflectionMethod` y neutralizar `report.csv`. Meterlo en 1.9 mezclaría en un paso de `apps/web` un cambio de servidor sin consumidor nuevo: 1.9 no añade ningún generador de CSV.
- **Decidido** (`OPEN-CORE-24`, decisión del usuario 2026-09-30): #270 se resuelve en una rama `fix/REQ-CORE-005-...` propia, **después** de la ratificación de `ADR-054` y **antes** del primer paso que añada un segundo generador de CSV. Esa rama aplica también el dialecto común de §13.14.3. No forma parte de 1.9.

### 13.2 Estado de partida verificado (2026-09-30, rama `feature/1.9-tablas-de-datos`)

- `@tanstack/vue-table` `^8.21.3` ya es dependencia de `apps/web/package.json`. **`@tanstack/vue-virtual` no está instalada, y 1.9 no la instala** (`OPEN-CORE-19`).
- `components/ui/table` contiene `Table`, `TableBody`, `TableCaption`, `TableCell`, `TableEmpty`, `TableFooter`, `TableHead`, `TableHeader`, `TableRow` (sin cambios de color ni de objetivo táctil en 1.7, `docs/design-system.md §12.1`/`§12.1b`).
- **Tablas existentes: cuatro** (inventario del 2026-09-30, aportado por la sesión orquestadora; rutas comprobadas sobre el fuente). La redacción anterior de esta sección afirmaba que solo existía una tabla, y era incorrecta:

  | Fichero (`apps/web/src/…`) | Cómo pinta la tabla | Qué hace 1.9 |
  |----------------------------|---------------------|--------------|
  | `modules/auth/components/admin/MfaComplianceArea.vue` | Importa `@tanstack/vue-table` directamente | **Se migra** con paridad estricta (§13.15) |
  | `modules/auth/components/admin/MfaExemptionsArea.vue` | Importa `@/components/ui/table` | **Excepción explícita** de `RN-CORE-53`. Se migra en `1.9b` o paso posterior |
  | `modules/auth/views/AdminSsoView.vue` | Importa `@/components/ui/table` | **Excepción explícita** de `RN-CORE-53`. Se migra en `1.9b` o paso posterior |
  | `modules/auth/views/SessionsView.vue` | `<table>` HTML crudo (hacia la línea 290), dentro de un contenedor con desplazamiento horizontal | **Excepción explícita** de `RN-CORE-53`. Se migra en `1.9b` o paso posterior |

  **Motivo de las tres excepciones** (decisión del usuario 2026-09-30): acotar 1.9 a un solo consumidor real con paridad estricta, en vez de migrar cuatro pantallas de `REQ-AUTH` sin tests de regresión suficientes en todas (issue #120 para `/administracion/mfa`). Las excepciones no son permanentes: la lista del test solo puede reducirse (`RN-CORE-53`).
- **`MfaComplianceArea.vue`**, la que se migra: pagina por página contra `GET /mfa-compliance/users`, filtra por `state` con casillas `<input type="checkbox">` nativas, pinta `'—'` literal en celdas vacías (issue [#90](https://github.com/pirexia/plataforma-educativa/issues/90), sin convención decidida) y muestra filas antes de elegir rol (issue [#116](https://github.com/pirexia/plataforma-educativa/issues/116), Baja, no corregida a propósito). No tiene test automatizado (issue [#120](https://github.com/pirexia/plataforma-educativa/issues/120)).
- **Exportación existente**: solo auditoría. `POST /audit-logs/exports` → `202` → `GET /data-exports/{id}` (estado `pendiente`/`generando`/`completada`/`fallida`, `download_url` firmada, `409` si aún no está lista, `410` si venció, solo el solicitante descarga). La generación la hace `GenerateAuditLogExport` en la cola `core-exports`, con `RN-CORE-36`. Su `neutralizeCsvCell` es privado, acepta `int|float` (`-5` pasa a `'-5`) y no cubre espacios iniciales (#270).
- **Listados sin paginar que la SPA consumirá**: `GET /permissions` (deliberado, `REQ-PERM/api.md §2.1`: unos cientos de filas como máximo, la matriz lo necesita entero).
- **Limitación de esta verificación**: la sesión de especificación no tenía herramienta de búsqueda en el árbol; el inventario de tablas de arriba lo aportó la sesión orquestadora. No se ha comprobado dónde viven exactamente los componentes de estado de §12.6 ni si `src/api/client.ts` tiene ya el ayudante de construcción de *query* con listas por comas que exige `ADR-038 §13.2`. Lo comprueba el implementador antes de escribir y lo reporta si no coincide. Si el test de `RN-CORE-53` (`CA-CORE-200`) encuentra **otra** tabla existente fuera de las cuatro, el implementador **para y lo reporta**: no la añade a la lista de excepciones ni la migra por su cuenta.

### 13.3 Arquitectura del componente

**Dónde vive**: `apps/web/src/data-table/`, de nivel de aplicación, como `src/tenant/` (1.7) y `src/navigation/` (1.8).

- **No va en `components/ui`**: el componente tiene textos propios traducidos (paginador, menú de columnas, estados, anuncios), y `RN-DS-24`/`RN-DS-20` prohíben literales e importar `vue-i18n`/`@/i18n` dentro de la frontera del *design system*. Es el mismo motivo por el que los componentes de estado de §12.6 viven fuera de ella.
- **No va en `src/modules/core`**: lo consumen todos los módulos, y ponerlo en la superficie pública de `core` haría que `auth`, `perm` y los 50 restantes dependieran de `core` para pintar una tabla (`INV-007`).

**Reglas de frontera** (tests de arquitectura en Vitest, mismo patrón que `docs/design-system.md §10`, con casos fijos que demuestran que el test sabe fallar, §10.6):

- **`RN-CORE-37`** · **Importación única de TanStack.** Solo los ficheros de `src/data-table/**` importan `@tanstack/vue-table` (estático, dinámico o de tipos; `ADR-054 §1.2`). Ningún módulo ve tipos ni funciones de TanStack: declara columnas y fuente de datos con los tipos propios de §13.4/§13.5. Motivo: `RNF-MANT-007` y el precedente de `RN-DS-13`/`RN-DS-21` (`useColorMode` envuelto). La versión 8 → 9 de TanStack cambiará su API, y con 53 módulos la diferencia entre tocar un directorio o tocar cincuenta la decide esta regla desde el primer consumidor.
- **`RN-CORE-38`** · `src/data-table/**` no importa nada de `src/modules/**` (`INV-007`) y solo usa del resto `@/components/ui/**`, `@/design-system/**`, `@/i18n`, `@/lib/utils`, `@/api` (tipos de error y ayudante de *query*), los componentes de estado de §12.6 y `vue-router` (solo para el estado en la URL de `RN-CORE-54`, `OPEN-CORE-22`, `ADR-054 §1.3`). **Construir URLs de *endpoints* no es cosa suya**: la petición la aporta el módulo consumidor (§13.5).
- **`RN-CORE-53`** · **Toda tabla nueva pasa por el componente** (`OPEN-CORE-29`, opción A; alcance fijado por el usuario el 2026-09-30). Fuera de `src/data-table/**` y salvo los ficheros de una **lista cerrada de excepciones** escrita dentro del propio test (mismo criterio que `docs/design-system.md §10`), ningún fichero de producción de `src/`:
  1. importa `@/components/ui/table` (ni por ruta relativa que resuelva a `src/components/ui/table`);
  2. importa `@tanstack/vue-table` (lo cubre ya `RN-CORE-37`, que **no tiene excepciones**: el único importador existente, `MfaComplianceArea.vue`, se migra en 1.9);
  3. contiene un elemento `<table` en un `.vue` (tabla HTML cruda). Quedan fuera de esta comprobación `src/components/ui/**` (el propio componente base `table` del *design system*) y `src/data-table/**`.

  **La lista nace con tres excepciones**, las tablas existentes que 1.9 no migra (§13.2), cada una con su fichero y su motivo en el propio test:
  - `src/modules/auth/components/admin/MfaExemptionsArea.vue` (importa `@/components/ui/table`);
  - `src/modules/auth/views/AdminSsoView.vue` (importa `@/components/ui/table`);
  - `src/modules/auth/views/SessionsView.vue` (`<table>` crudo).

  **La lista solo puede reducirse, nunca crecer.** El test lo hace cumplir de dos formas: (a) la lista de excepciones debe ser un subconjunto de esas tres rutas, escritas como constante en el propio test, así que añadir una cuarta hace fallar el test; (b) toda excepción de la lista debe seguir incumpliendo la regla, así que un fichero ya migrado que siga en la lista también hace fallar el test y obliga a retirarlo. Las tres se migran en `1.9b` o en un paso posterior. **Consecuencia que el usuario debe tener presente**: §13.15 preveía que la matriz de concesión de `1.5b` entrase como excepción nominal; con esta regla, eso exige que la especificación de `1.5b` proponga modificar `RN-CORE-53` y que el usuario lo apruebe expresamente. Si el test encuentra otra tabla existente fuera de las tres y de `MfaComplianceArea.vue`, el implementador para y lo reporta (§13.2).

**Componentes que harán falta y cómo se añaden**: el menú de columnas y los filtros de enumerado necesitan casillas en menú (`dropdown-menu` ya vendorizado en 1.8 tiene elemento de casilla), y probablemente `checkbox` y `popover`. Se vendorizan por `docs/design-system.md §12.2`, sobre Reka UI ya instalada: **sin dependencia npm nueva**. Cada uno se somete a los tests de `§10`, y su texto propio pasa a *prop* (`RN-DS-24`).

### 13.4 Contrato de columnas

Cada columna la declara el módulo consumidor con estos campos (tipo propio de `src/data-table`, no de TanStack):

| Campo | Significado | Regla |
|-------|-------------|-------|
| `id` | Identificador estable de la columna | **Igual al nombre del parámetro de consulta** con el que se filtra u ordena (`ADR-038 §13.3`), para serializar sin tabla de correspondencias. Si la columna no filtra ni ordena, cualquier `snake_case` único |
| `headerKey` | Clave de traducción de la cabecera | `INV-009`. Nunca texto |
| celda | Cómo se pinta el valor | *Slot* o función del consumidor. Por defecto, el valor como texto |
| `sortable` | Si se puede ordenar por ella | Solo si `id` está en el `enum` de `sort` del *endpoint* en OpenAPI (`ADR-038 §5.3`). El cliente no puede hacer ordenable lo que el servidor no ordena |
| `rowHeader` | Si identifica la fila | **Exactamente una columna por tabla** (se pinta como `th scope="row"`, `RN-CORE-44`) |
| `hideable` | Si el usuario puede ocultarla | Por defecto `true`. `false` obligatorio para la columna `rowHeader` y la de acciones |
| `defaultHidden` | Oculta por defecto | Por defecto `false` |
| `card` | Papel en la vista de tarjetas | `title` (una sola, suele coincidir con `rowHeader`), `subtitle`, `field`, `actions` u `omit`. Obligatorio en tablas con vista de tarjetas; no aplica a las declaradas con desplazamiento interno (§13.9, `RN-CORE-55`) |
| `align` | Alineación | `start` por defecto; `end` para cifras |

**Identidad de fila**: por `public_id` (`ADR-029`) salvo que el consumidor declare otra clave, como `code` en `GET /permissions` (`ADR-051`). Nunca por índice de posición, que cambia al paginar u ordenar y rompe el foco y las claves de Vue.

### 13.5 Fuentes de datos y paginación

El componente **no hace peticiones por su cuenta**: recibe del consumidor una función que, dada la consulta (página o cursor, orden y filtros), devuelve la respuesta de su *endpoint* con la forma de `ADR-038 §3.1` (`data` + `meta`). La función la escribe el módulo en su `api/`, con el ayudante de *query* de `ADR-038 §13.2`, que serializa las listas por comas en un único sitio.

**Dos modos en 1.9**, y solo dos: el modo `local` **no existe en 1.9** (`OPEN-CORE-26`, opción B; `ADR-054 §2.1`). Los fija el consumidor según el `datos.md` del recurso (criterio objetivo de `ADR-038 §4.2`, no a gusto):

| Modo | Cuándo | Paginación | Ordenación y filtrado |
|------|--------|------------|-----------------------|
| **`page`** | Catálogo de entidades (`ADR-038 §4.3`) | Paginador numerado: primera, anterior, siguiente, última; «página X de Y»; total de resultados. `per_page` elegible entre **25 (defecto), 50 y 100**. No se ofrece nada por encima de 100: el servidor responde `422` y no recorta (`ADR-038 §4.3`) | Servidor (`manualPagination`, `manualSorting`, `manualFiltering`, `enableMultiSort: false`, `ADR-038 §13.3`) |
| **`cursor`** | Flujo de eventos (`ADR-038 §4.4`) | **«Cargar más»** (un botón), que añade filas al final con `cursor=<next_cursor>&limit`. Sin total, sin números de página, sin «última» (`ADR-038 §4.5`), sin alimentar el modelo de paginación de TanStack (`pageCount: -1`). **Sin desplazamiento infinito**. Tope de filas acumuladas: `RN-CORE-52` | Servidor. Cambiar orden o filtros reinicia la lista **sin cursor**: un cursor emitido con otros filtros es `422` (`ADR-038 §4.4` regla 2) |

- **`RN-CORE-56`** · **Reglas del modo `cursor`** (`ADR-054 §2.2`/`§2.3`):
  1. **Sin desplazamiento infinito**, en ningún paso del producto. `ADR-038 §4.5` lo admitía como alternativa; `ADR-054 §2.2` lo retira: deja inalcanzable con teclado todo lo que hay debajo de la lista, obliga a gestionar anuncios y foco sin una acción explícita del usuario y es incompatible con el tope de `RN-CORE-52`. La única forma de pedir más filas es activar «Cargar más».
  2. **Fallo de «cargar más»**: si la petición de una carga adicional falla, **se conservan todas las filas ya cargadas**, se muestra el error junto al control (correspondencia de §12.6) y «Reintentar» repite la petición **con el mismo `cursor`**. No reinicia la lista ni pide desde el principio.
  3. Cambiar orden o filtros reinicia la lista **sin `cursor`** (tabla de arriba).
  4. El `cursor` **nunca** va a la URL (`RN-CORE-54`).

- **`RN-CORE-52`** · **Tope de filas acumuladas en modo `cursor`** (`OPEN-CORE-19`, opción A): **1.000 filas**, constante exportada de `src/data-table` (20 cargas del `limit` por defecto de `ADR-038 §4.4`). Cuando las filas acumuladas **alcanzan o superan** el tope, «Cargar más» se sustituye por un aviso traducido («Has cargado el máximo de 1.000 filas; acota los filtros o exporta»), con la acción de exportar si la tabla la ofrece (§13.14), y no sale ninguna petición más de paginación. Las filas ya recibidas se muestran enteras (no se recorta la última carga). Cambiar orden o filtros reinicia la cuenta. Es una cifra de diseño sin medición detrás: se revisa con volumen real (`REQ-SEED`, 1.15b).
- **`RN-CORE-54`** · **Estado de la consulta en la URL, sin `q` ni `cursor`** (`OPEN-CORE-22`, opción A; `ADR-054 §6`). En una tabla que lo declara, página, `per_page`, orden, enumerados, booleanos y rangos de fechas se reflejan en la *query* de la ruta y se restauran al recargar, al volver atrás y al abrir un enlace compartido. **El texto de búsqueda `q` nunca va en la URL ni en `history.state`**: la *query* queda en el historial del navegador y, en una recarga completa, en los registros de acceso del servidor (mismo motivo que `ADR-038 §6.5`). Los identificadores que aparezcan en un enumerado (`role`, `actor_id`) son ULID seudónimos (`ADR-029`) y se aceptan. Un valor restaurado desde la URL que el servidor rechace (`422`) sigue `RN-CORE-42`, y «Limpiar filtros» lo resuelve. Precisiones de `ADR-054 §6.2`:
  1. **Opcional por tabla** (*opt-in*): el reflejo en la URL lo declara el consumidor; por defecto, la tabla guarda su consulta solo en memoria.
  2. **Como máximo una tabla por ruta** lo declara: dos tablas sincronizando con la misma *query* se pisarían los parámetros. La tabla principal de la vista lo declara; las demás quedan en memoria.
  3. **El `cursor` nunca va a la URL**: es opaco, está ligado a los filtros que lo emitieron, y restaurarlo no aporta nada. Tras una recarga, la lista en modo `cursor` se reinicia desde el principio.

`ADR-038 §4.5` dice que el modo cursor es «un componente distinto». Aquí se lee como **un contenedor de paginación distinto sobre el mismo contrato de columnas y el mismo pintado**, no como una segunda implementación de tabla. Cumple el motivo del ADR (un cursor no puede tener paginador numerado) sin duplicar accesibilidad, tarjetas ni estados. **`ADR-054 §2.2` confirma que esta lectura es compatible** con `ADR-038 §4.5` y `§13.3`, con la precisión de `RN-CORE-56` (sin desplazamiento infinito).

### 13.6 Ordenación

- **`RN-CORE-39`** · Una sola columna ordenada a la vez (`ADR-038 §5.3`). Activar la ordenación de una columna recorre **ascendente → descendente → sin orden explícito**. «Sin orden» significa **no enviar `sort`**: el servidor aplica su orden por defecto, que es explícito y determinista por contrato (`ADR-038 §5.3`, último punto). Se serializa como `sort=<id>` o `sort=-<id>`.
- Cambiar el orden vuelve a la página 1 (modo `page`) o reinicia la lista (modo `cursor`).
- Un `422` por `sort` fuera de la lista blanca es un error de programación del consumidor (`sortable` sin respaldo en OpenAPI). Se pinta como error y lo detecta el test del consumidor, no se oculta.

### 13.7 Filtrado

Los filtros van en una **barra de herramientas encima de la tabla**, no dentro de las cabeceras: en móvil no hay cabeceras (§13.9) y un campo dentro de un `th` complica la navegación con lector de pantalla. Tipos admitidos, cerrados:

| Tipo | Control | Serialización (`ADR-038 §5.2`) |
|------|---------|-------------------------------|
| Texto libre | Un único campo de búsqueda | `q=<texto>`. Nunca otro nombre |
| Enumerado múltiple | Grupo de casillas en menú | `<id>=a,b` (coma = `OR`). Etiquetas traducidas por el consumidor, con **rama por defecto que muestra el código en crudo** si llega un valor desconocido (`ADR-038 §7.3`) |
| Rango de fechas | Dos campos de fecha (`input type="date"` del *design system*, sin componente de calendario nuevo) | `<id>_from`, `<id>_to`, ambos inclusivos. Fecha sin hora como `AAAA-MM-DD`; si el parámetro es de tipo `TIMESTAMPTZ`, el consumidor convierte el día local del centro a instante UTC en su función de petición |
| Booleano | Selector de tres estados (todos, sí, no) | `<id>=true`/`false`; «todos» no envía el parámetro |

- **`RN-CORE-40`** · **Búsqueda con espera.** Una sola petición cuando el usuario deja de escribir durante `SEARCH_DEBOUNCE_MS` = **300 ms**, constante exportada. Es una propuesta de diseño, no un requisito: es el orden de magnitud habitual entre «responde al teclear» y «no lanza una petición por pulsación», y se revisa si la medición con volumen (`REQ-SEED`, 1.15b) dice otra cosa.
- **`RN-CORE-41`** · **Solo gana la última respuesta.** Si llegan respuestas fuera de orden (filtro A lanzado, filtro B lanzado, A responde después que B), solo se aplica la de la última petición emitida. Si el cliente HTTP admite cancelación, la petición superada se cancela; si no, su respuesta se descarta.
- Cambiar un filtro vuelve a la página 1 o reinicia el cursor.
- **Acción «Limpiar filtros»**, visible cuando hay alguno activo.
- **`RN-CORE-42`** · **`422` de un filtro** (valor inválido, rango excesivo): se muestra el `message` ya traducido del servidor (`ADR-038 §6.3`) junto a la barra, con `role="alert"`, y **se conservan las filas anteriores sin presentarlas como filtradas**: el texto del estado deja claro que el filtro no se aplicó. No se pasa al estado de error de pantalla completa, porque la tabla sigue sirviendo. Motivo: `ADR-038 §5.2` («devolver datos que el usuario cree filtrados y no lo están es un incidente de privacidad»).
- La validación de filtros en cliente (fecha «desde» posterior a «hasta») es solo comodidad (`INV-010`): el servidor decide.

### 13.8 Columnas configurables

**Alcance** (qué se configura), `OPEN-CORE-21` resuelta (opción A, decisión del usuario 2026-09-30): **visibilidad de columnas más «Restablecer columnas»**. Sin reordenar ni redimensionar en 1.9.

**Persistencia**, justificada aquí y **confirmada por `ADR-054 §5.2`**:

- **`RN-CORE-43`** · La configuración de columnas se guarda en **`localStorage`** del navegador, clave `plataforma.table.<tableId>`. **`tableId` es un literal** con forma `<modulo>.<nombre>` (p. ej. `auth.mfa_compliance`), declarado por el consumidor y único en toda la SPA. La unicidad y la forma las comprueba un test que **recorre las fuentes** de `src/modules/**` (misma técnica de escaneo que `docs/design-system.md §10`); un `tableId` calculado, no literal, hace fallar el test. No existe ningún «registro de tablas» de la SPA, ni se crea para esto (`ADR-054 §5.3`). El valor tiene la forma exacta `{"v":1,"hidden":["<id>",…]}`; nada más (un orden de columnas futuro iría en una versión `v` nueva). Solo `id` de columna: **nunca un dato de fila, un filtro, un texto de búsqueda ni nada del usuario**. Un valor que no se pueda leer, con `v` distinto de 1 o con `id` que ya no existen, se descarta (los `id` desconocidos se ignoran y se aplica el resto). Todo acceso va en `try/catch`: navegación privada o almacenamiento bloqueado ⇒ se sigue con la configuración por defecto.

  **Por qué `localStorage` y no servidor ni memoria**:
  - **No es estado que deba ser fiable.** Perderlo cuesta volver a ocultar una columna: una molestia, no una pérdida de datos ni un fallo de seguridad. Es la misma naturaleza que la preferencia de modo de color, que `ADR-052 P2` ya decidió dejar solo en local (`RN-DS-12`).
  - **En servidor** haría falta una tabla de preferencias por usuario, un *endpoint*, un permiso de autoservicio y una migración en `apps/api`, y **ningún requisito pide** que la configuración de columnas siga al usuario entre dispositivos. Sería el esquema que `ADR-034` `OPEN-13` prohíbe adelantar «por si acaso». Si un centro lo pide, es aditivo: un *endpoint* nuevo que siembra la clave local.
  - **Solo en memoria** haría que la configuración se perdiera en cada recarga y convertiría la funcionalidad en inútil en la práctica.

  **Consecuencias aceptadas**: la configuración es por navegador y por origen, y el origen es el centro (resolución por *host*), así que no se mezcla entre centros. **Dos usuarios que comparten navegador comparten configuración de columnas**, y la clave **no se borra al cerrar sesión**. Se acepta porque la clave no contiene nada de ninguno de los dos (`ADR-054 §5.2`). Hay que añadir la clave al inventario de `PRIVACY.md §2.1b` (lo confirma `doc-reviewer`).
- Ocultar una columna **no cambia la petición**: el *endpoint* devuelve los mismos campos. Es presentación, no minimización de datos, y no se presenta como tal.
- Mientras no se ha leído la configuración (antes del primer pintado) se usa la configuración por defecto: sin destello de columnas.

### 13.9 Vista en móvil (`RUX-RESP-004`)

`RUX-RESP-004` admite **scroll horizontal o vista de tarjetas**. `OPEN-CORE-20` resuelta (opción A, decisión del usuario 2026-09-30): **tarjetas por defecto por debajo de 768 px, con desplazamiento horizontal interno como opción por tabla**.

- **`RN-CORE-55`** · Toda tabla usa la vista de tarjetas por debajo de 768 px **salvo** que el consumidor la declare explícitamente con desplazamiento interno (opción a nivel de tabla, que declara el consumidor; el valor por defecto es tarjetas).
- Por debajo de **768 px** (`--breakpoint-md`, `RN-CORE-29`), la tabla se sustituye por una **lista de tarjetas** construida con el mismo contrato de columnas (§13.4, campo `card`). En el DOM solo hay una de las dos representaciones a la vez: pintar ambas y ocultar una con CSS duplicaría el trabajo y, si algún día fallara la ocultación, duplicaría también el contenido leído por el lector de pantalla.
- **Tarjeta**: elemento de una lista (`ul`/`li`). El valor de la columna `title` va como encabezado de la tarjeta, cuyo nivel lo fija el consumidor según la jerarquía de su vista. `subtitle` va debajo. Los `field` se pintan como lista de descripción (`dl`, con la etiqueta traducida de la cabecera como `dt`), y `actions` al pie.
- La ordenación en tarjetas se hace con un **selector** de la barra de herramientas (columna y sentido), porque no hay cabeceras que pulsar.
- Paginación, «cargar más», filtros, menú de columnas y exportación: los mismos controles, reordenados en vertical.
- **Tabla que no puede ser tarjeta**: el consumidor la declara con desplazamiento horizontal **dentro de su contenedor** (`RN-CORE-55`). **Criterio** (`ADR-054 §4`): el propósito de la tabla es comparar valores de una misma columna entre filas (calificaciones, importes, series), de modo que partirla en tarjetas destruye la comparación. La especificación del paso que declara la excepción lo justifica en una línea; «las tarjetas cuestan más» no es criterio. En ese caso se pinta la `table` a cualquier anchura y no hay lista de tarjetas. Criterio de conformidad: la página nunca se desplaza en horizontal (`CA-CORE-080`), y el contenedor con desplazamiento es enfocable, tiene nombre accesible y se puede desplazar con el teclado. WCAG 1.4.10 exceptúa las tablas de datos del *reflow*, pero no del acceso por teclado.

### 13.10 Estados (`RUX-006`, `RNF-UX-005`)

Se reutilizan los tres componentes de §12.6 y su función de correspondencia de errores. **No se crea un tercer juego.**

| Situación | Qué se pinta |
|-----------|--------------|
| Primera carga | Estado de **carga** de §12.6 (`role="status"`, esqueleto con tokens) en el sitio de la tabla. La barra de herramientas ya es visible y utilizable |
| Recarga (página, orden, filtro) | **Se conservan las filas actuales**, la región pasa a `aria-busy="true"` y aparece un indicador discreto, sin vaciar la tabla ni desplazar el diseño |
| Sin datos y sin filtros activos | Estado **vacío** con texto del consumidor («Aún no hay invitaciones») y acción opcional |
| Sin datos con filtros activos | Estado **vacío** propio de la tabla («Ningún resultado con estos filtros») con la acción **Limpiar filtros**. Son dos mensajes distintos porque el usuario hace cosas distintas en cada caso |
| Error de carga | Estado de **error** de §12.6: correspondencia única de §12.6 (sin conexión, `403`, `403 module-disabled` con recarga de `/me`, `404`, `429` con `Retry-After`, `5xx` con `request_id`), con **Reintentar** que repite la última consulta |
| `422` de filtro | `RN-CORE-42`, no el estado de error |
| Error en «Cargar más» (modo `cursor`) | `RN-CORE-56`: se conservan las filas, error junto al control, «Reintentar» con el mismo `cursor`; no el estado de error de pantalla completa |
| Página fuera de rango | `RN-CORE-45` |

### 13.11 Accesibilidad (`RUX-004`, `RNF-UX-002`, WCAG 2.2 AA)

- **`RN-CORE-44`** · **Semántica de tabla nativa**: `table` con `caption` (visible u oculta visualmente, con el nombre de la tabla que da el consumidor, traducido), cabeceras `th scope="col"` y la columna `rowHeader` como `th scope="row"`. **No se usa `role="grid"`**: una tabla de datos con controles no es una rejilla de navegación por celdas, y el patrón *grid* obliga a una navegación con flechas que el usuario de lector de pantalla no espera en un listado.
- **Ordenación accesible**: la cabecera ordenable contiene un `button`. `aria-sort` (`ascending`/`descending`) va **solo** en el `th` de la columna ordenada. El nombre accesible del botón dice la columna y la acción que hará, traducido («Nombre, ordenar de forma descendente»). Los iconos de sentido son decorativos (`aria-hidden`).
- **Anuncios**: una región `aria-live="polite"` anuncia, **una vez por carga completada**, el resultado («137 resultados», «Sin resultados», «50 filas más cargadas»), con plural correcto en los cuatro idiomas. Nunca un anuncio por pulsación de tecla (la espera de `RN-CORE-40` lo evita).
- **Foco**: al cambiar de página con el paginador, el foco se queda en el control pulsado (no salta), y el anuncio informa del cambio. Si el control queda deshabilitado (se llegó a la última página), el foco pasa al siguiente control habilitado del paginador y nunca se pierde en `body`. Al «cargar más», el foco se queda en el botón y la primera fila nueva es alcanzable con `Tab` en el orden natural. Al limpiar filtros, el foco pasa al campo de búsqueda.
- **Acciones por fila**: todo control de fila tiene un nombre accesible que **incluye la identidad de la fila** («Restablecer MFA de Ana López»), no solo el verbo repetido N veces (WCAG 2.4.6). El consumidor lo compone con la clave de traducción e interpolación.
- **Objetivos táctiles**: controles de la tabla y de las tarjetas ≥ 44 px en puntero grueso, heredado de los componentes base (`OPEN-CORE-14`, `docs/design-system.md §12.1b`). Los que no son componente base (botón de ordenación en la cabecera, elementos del menú de columnas) añaden `min-h-11` por instancia en `any-pointer: coarse`. Mínimo absoluto de WCAG 2.5.8 (24 px) en cualquier puntero.
- **Sin arrastre**: nada del componente exige arrastrar (WCAG 2.5.7). 1.9 no reordena columnas (`OPEN-CORE-21`).
- **Contraste y color**: solo tokens semánticos (`RN-DS-16`/`RN-DS-19`). Estado ordenado, fila con foco y fila en recarga no se distinguen solo por color (WCAG 1.4.1).
- **Texto largo**: se ajusta en varias líneas por defecto. Si un consumidor trunca, el texto completo es accesible (nombre accesible o `title`), como en §12.9.
- **Tipografía** en `rem`, sin `text-[NNpx]` (`RN-CORE-31`; `CA-CORE-083` amplía su alcance a `src/data-table/**`).

### 13.12 Traducción y formato (`INV-009`)

- Todo texto propio del componente va en un espacio de nombres de aplicación `dataTable.*` (mismo criterio que `shell.*` de 1.8), en `es`, `en`, `de` y `fr`, y pasa `npm run lint:i18n`. Se accede con `useT` de `@/i18n`, nunca importando `vue-i18n` directamente (`docs/i18n.md`, issue [#259](https://github.com/pirexia/plataforma-educativa/issues/259)).
- Recuentos con pluralización de `vue-i18n`. Cifras y fechas con `Intl.NumberFormat`/`Intl.DateTimeFormat` del idioma activo. El componente ofrece formateadores al consumidor para que ninguna vista cree el suyo, como hace hoy `MfaComplianceArea`.
- **Valor vacío en celda** (`OPEN-CORE-27`, opción A, decisión del usuario 2026-09-30): una celda o un campo de tarjeta sin valor muestra la marca visual «—» **oculta al lector de pantalla** (`aria-hidden`) junto a un texto solo para lector de pantalla («Sin valor» y sus traducciones) con la clave común `dataTable.emptyValue`; la propia marca también sale de una clave del espacio `dataTable.*` (el nombre exacto lo elige el implementador). Ni el componente ni ninguna vista escriben `'—'` como literal (`INV-009`). Resuelve el issue #90 **para las tablas** y propone la convención para el resto de vistas (`SessionsView.vue` y análogas siguen con #90 abierto: no se tocan en 1.9).
- Cambiar de idioma con la tabla montada (§12.3.6) retraduce cabeceras, controles y valores formateados **sin volver a pedir datos**: los enumerados se traducen en cliente y los datos no dependen del idioma. Si algún *endpoint* devolviera texto traducido por el servidor en las filas, lo declararía su consumidor y recargaría. En 1.9b no se prevé ninguno.

### 13.13 Virtualización: evaluación y decisión (`OPEN-CORE-19`, resuelta)

**Decisión del usuario (2026-09-30): opción A — no se virtualiza en 1.9**, no se instala `@tanstack/vue-virtual`, y el modo `cursor` lleva el tope de `RN-CORE-52`. Registrada en `ADR-054 §3`: el tope es la constante única `MAX_CURSOR_ROWS = 1000` de `src/data-table`, **no configurable por tabla**; si algún paso necesita virtualizar, lo decide un ADR nuevo con la diligencia de dependencia de `CLAUDE.md §1` (`ADR-054` no aprueba ninguna biblioteca). La línea de 1.9 en `PLAN-IMPLEMENTACION.md` ya no la enumera. Lo que sigue se conserva como justificación de la decisión.

La redacción original del plan la enumeraba como entregable. **Ningún requisito la pide.** La pregunta es si hace falta con los contratos ya decididos:

| Modo | Filas en el DOM como máximo | ¿Virtualización? |
|------|-----------------------------|-------------------|
| `page` | 100 (`ADR-038 §4.3`, máximo duro) × columnas visibles | **No.** Cien filas es un volumen trivial para cualquier navegador de `RNF-COMP-001`/`-002` |
| `local` (no existe en 1.9, `OPEN-CORE-26`) | Unos cientos (`GET /permissions`) | **No** con los consumidores conocidos |
| `cursor` | **Sin límite**: cada «cargar más» suma hasta 200 filas (`ADR-038 §4.4`) y la auditoría de un centro crece sin cota | **Es el único caso real**, y tiene dos soluciones |

**Coste de virtualizar**, que no es solo una dependencia:

- **Accesibilidad**: las filas fuera de pantalla no existen en el DOM. El lector de pantalla no puede recorrerlas, «buscar en la página» del navegador no las encuentra, y hay que mantener `aria-rowcount`/`aria-rowindex` correctos. Es trabajo específico y fácil de romper en un producto obligado a WCAG 2.2 AA y, para centros públicos, a la Ley 11/2023 (`RNF-UX-002`).
- **Vista de tarjetas**: las tarjetas tienen altura variable, lo que complica la virtualización de verdad.
- **Dependencia**: `@tanstack/vue-virtual` habría exigido la diligencia de `CLAUDE.md §1` (mantenimiento, licencia, ritmo de versiones), que no se llegó a hacer porque la opción elegida no la instala.

**Solución adoptada, sin dependencia**: el **tope de filas acumuladas** en modo `cursor` (`RN-CORE-52`). Es honesto con la realidad de uso: nadie revisa a ojo diez mil entradas de auditoría, y para eso existe la exportación. Si algún día hiciera falta virtualizar, se añade dentro de `src/data-table` sin tocar a ningún consumidor (`RN-CORE-37`), con su propio ADR.

### 13.14 Exportación de listados

#### 13.14.1 Qué parte es del cliente y qué parte del servidor

- **`RN-CORE-46`** · **La SPA nunca genera el fichero de una exportación** (ni CSV, ni XLSX, ni ningún otro formato, ni con las filas ya cargadas en pantalla). La exportación es siempre una **petición a un *endpoint* de exportación del módulo dueño del recurso**, que se ejecuta **en cola en servidor** y se descarga por URL firmada de caducidad corta.

  **Motivo** (seguridad, no preferencia):
  1. **`exportar` es una acción de permiso distinta de `leer`** (`RPERM-003`). Un botón que convierte en fichero lo que ya está en memoria dejaría exportar a quien solo puede leer, sin que el servidor intervenga (`INV-002`: la interfaz oculta, no protege).
  2. **Toda exportación se audita** con lo que se exportó (`event = 'exported'` y `data_exports.filters`, `funcional.md §4.6` punto 4). Una exportación en cliente no deja rastro.
  3. **El ámbito del permiso acota el artefacto dentro del propio trabajo** (`GenerateAuditLogExport`, `RN-PERM-15`), no solo el listado.
  4. **Una página no es el listado**: exportar las 25 filas visibles se presenta como «exportar» y entrega una fracción del conjunto filtrado. `RNF-LIM-004` exige límites de filas y troceado en servidor.
  5. `INV-012`: las exportaciones son tarea pesada.

- **Parte del cliente (1.9)**: el botón de exportación en la barra de herramientas y el seguimiento del estado:
  1. El consumidor declara si la tabla exporta (`canExport`, calculado con el permiso `<recurso>.exportar` de `/me.permissions`, nunca con el rol, `RN-CORE-23`) y aporta la función de solicitud de su módulo.
  2. Al pulsar, la solicitud se envía con **los filtros estructurados del listado visible y nada más**: **sin `sort`**, sin `page`, `per_page` ni `cursor`, y **sin `q`** (`ADR-054 §7.3`). **Sin `sort`** porque el orden de las filas del fichero lo fija el esquema del servidor por recurso (§13.14.2, `ADR-054 §8.1`): enviarlo sería peor que no enviarlo, porque un parámetro desconocido se ignora (`ADR-038 §5.2`) y el usuario creería que el fichero sigue el orden de la pantalla. Un valor múltiple va como *array* JSON en el cuerpo; la traducción desde la forma por comas de la *query* la hace la función de solicitud del módulo (`ADR-054 §8.2`).
     - **`RN-CORE-57`** · **Con una búsqueda `q` activa, el control de exportación está deshabilitado** y dice por qué con texto traducido («borra la búsqueda para exportar; los demás filtros sí se aplican»). No se exporta ignorando `q`: el usuario creería que el fichero está filtrado por su búsqueda (`ADR-054 §7.4`, consecuencia de `§9`).
  3. `202` con `public_id` ⇒ el componente consulta `GET /data-exports/{public_id}` con espera creciente mientras la vista esté montada (`RN-CORE-49`), y muestra «Preparando exportación…» con `role="status"`.
  4. `completada` ⇒ un **enlace de descarga** a `download_url`, con la caducidad visible. No se descarga el fichero a memoria con `fetch` ni se crea un `Blob`: el enlace navega a la URL firmada.
  5. `fallida` ⇒ el mensaje traducido de `error_code` con `role="alert"`.
  6. `410` ⇒ «La exportación ha caducado; vuelve a solicitarla».
  7. `409` durante la consulta de estado ⇒ se trata como «aún no está lista» y se sigue esperando.
  8. `422` en la solicitud (demasiadas filas, `RNF-LIM-004`) ⇒ `message` del servidor («acota el rango»).
- **Parte del servidor**: cada módulo expone su `POST /<recurso>/exports` sobre la primitiva `ExportRequestService`/`data_exports` de §7. **1.9 no crea ninguno.** El único existente es el de auditoría. El de usuarios (`usuario.exportar`) no existe todavía (§13.1.2). La norma que obliga a todo *endpoint* de exportación nuevo o modificado (no a 1.9) está en `ADR-054 §8`-`§10` y se resume en §13.14.3 y en `api.md §13.4`: esquema fijo, **paridad exacta de filtros con su listado** (salvo paginación, `sort` y `q`), y **`q` rechazado con `422`**.

#### 13.14.2 Qué columnas contiene el fichero

`OPEN-CORE-23` resuelta (opción A, decisión del usuario 2026-09-30): **esquema fijo por recurso, definido en servidor** y documentado en su OpenAPI, como hace hoy la auditoría, y no «las columnas visibles». El esquema fija las columnas, sus nombres, su orden **y el orden de las filas** (`ADR-054 §8.1`). La solicitud de exportación **no** envía la configuración de columnas del navegador **ni `sort`**. Idioma de la cabecera y de los valores enumerados del fichero: **abierto**, `OPEN-054-01` (§13.21). El fichero es un contrato estable para quien lo procesa después (una hoja de cálculo de secretaría, una importación en otro sistema). Que dependa de qué columnas ocultó cada usuario en su navegador lo hace irreproducible e inauditable (`data_exports.filters` no lo registraría).

#### 13.14.3 Regla común de CSV (norma para todo generador, `ADR-054`)

Aplica a `apps/api`. La fija `ADR-054 §9`-`§10` para cumplir lo que `SECURITY.md` y el issue #270 remiten a 1.9; aquí se recoge, y **se implementa fuera de 1.9** (§13.1.3):

- **`RN-CORE-47`** · **Una sola vía de escritura CSV** (`ADR-054 §10.1`). Todo generador de CSV del producto (exportaciones de listados, informes de errores de importación como `report.csv`, y los futuros) escribe con **una clase única** en `apps/api/app/Support/Csv/` (infraestructura compartida, como `App\Support\Audit`, para que cualquier módulo la use sin importar código interno de `Core`, `INV-007`; el nombre concreto lo elige quien la implemente). **Sin par interfaz + implementación**: `RNF-MANT-007` obliga a envolver **dependencias externas**, y `fputcsv` es PHP; no hay segunda implementación previsible y la propia clase ya es el punto único. **Test de arquitectura**, que entra con la clase: ninguna llamada a `fputcsv` ni a `SplFileObject::fputcsv` fuera de ella.
- **`RN-CORE-48`** · **Celdas tipadas y neutralización solo sobre texto** (`ADR-054 §10.2`, amplía `RN-CORE-36`).
  - **Cada generador declara el tipo de cada columna en su esquema** (§13.14.2), y la clase escribe según ese tipo. **La clase nunca deduce el tipo del contenido**: si lo dedujera, una cadena `"-5"` y un entero `-5` se tratarían igual según cómo llegaran. Tipos admitidos: texto, entero, fecha/instante y nulo. Los enteros (céntimos de `ADR-029` incluidos) se escriben sin apóstrofo (`-5` sigue siendo `-5`); los instantes, en ISO 8601 con desfase. Sin coma flotante: un decimal (una nota) lo formatea el generador como texto. Añadir un tipo decimal cuando haga falta es aditivo.
  - **La neutralización recibe solo cadenas** y se aplica a toda celda de texto y a la cabecera. Se antepone un apóstrofo si se cumple cualquiera de estas dos condiciones: (1) el primer carácter es `=`, `+`, `-`, `@`, tabulador, retorno de carro o salto de línea (`RN-CORE-36`, vigente); (2) **el primer carácter que no es espacio en blanco** (espacios Unicode incluidos) es `=`, `+`, `-` o `@`. Expresión única: `/^(?:[=+\-@\t\r\n]|[\s\p{Z}]+[=+\-@])/u`.
  - Sobre #270 (neutralizar todo valor que empiece por cualquier espacio en blanco): se adopta **la protección, no la regla**. La condición 2 cubre `" =1+1"` sin que `" Juan"` gane un apóstrofo inútil. Es defensa en profundidad, **no verificada** contra versiones concretas de Excel, LibreOffice o Sheets. Riesgo residual aceptado: signos de ancho completo (`＝`) y otras variantes Unicode.
- **Dialecto CSV** (`OPEN-CORE-24`, opción A, decisión del usuario 2026-09-30; detalle en `ADR-054 §10.3`): **coma como separador, UTF-8 con BOM, fin de línea CRLF, comillas dobles de RFC 4180 (una comilla dentro del campo se duplica) y sin carácter de escape** (`escape: ''` en PHP), cabecera siempre presente y sin línea `sep=`. Hoy la auditoría se escribe con `fputcsv` por defecto (coma, sin BOM, `\n`, escape `\`); el cambio le llega en la rama `fix/` de #270 (§13.1.3), no en 1.9, va al `CHANGELOG.md`, y esa misma rama documenta en `docs/manual-usuario/admin.md` cómo abrir el fichero en Excel con configuración regional española (importación indicando el separador). No hay dato de uso real que respalde la elección (sin centro piloto, H0); si aparece, se revisa.
- **Texto libre y `data_exports.filters`** (`ADR-054 §9`, sustituye la alternativa que aquí quedaba abierta). `data_exports.filters` tiene política de auditoría `Full` (`datos.md` A.4): el `created` automático copia `filters` en `audit_logs.changes`, tabla inmutable con dos años de retención, y un `q` («López») rompería la garantía de `ADR-035 §1` (el tope de 256 caracteres de `ADR-035 §5` no lo atrapa). **Regla**: ningún texto libre introducido por un usuario llega a `audit_logs` a través de `data_exports`.
  - **`RN-CORE-58`** · **Ningún *endpoint* de exportación acepta `q`** mientras un paso no justifique lo contrario con un caso real. Si el listado del recurso acepta `q`, su exportación responde **`422`** cuando lo recibe, con código de error propio del recurso. No se ignora: ignorarlo produciría un fichero «filtrado» que no lo está.
  - Si un paso necesita exportar con búsqueda, la única vía admitida es la de `ADR-054 §9.2` (columna propia de `data_exports` fuera de `filters`, añadida por *expand*, y `DataExport` de `Full` a `Selective`). Descartadas: redactar `filters` entero, guardar un *hash* de `q` (prohibido por `ADR-035 §3`) y confiar en el tope de tamaño.
  - Hoy no ocurre: la exportación de auditoría no acepta `q`. La regla existe para que el primer *endpoint* de exportación de usuarios (`1.9b`, cuyo listado sí acepta `q`) no la estrene.

#### 13.14.4 Si el usuario sale de la vista

El `public_id` de la exportación vive solo en la memoria de la vista. Si el usuario navega fuera antes de que termine, **el trabajo sigue en servidor y el fichero se genera**, pero **no hay forma de volver a él desde la interfaz**: no existe listado de `data_exports` (el índice «Mis exportaciones» de `datos.md` A.7 existe, el *endpoint* no). `OPEN-CORE-25` resuelta (opción A, decisión del usuario 2026-09-30; `ADR-054 §7.7`): mientras la exportación está `pendiente` o `generando`, la vista muestra **junto al estado de la exportación un aviso permanente** traducido («la exportación seguirá preparándose, pero si sales de esta vista no podrás descargarla desde aquí»), **sin bloquear la navegación**: **ningún diálogo de confirmación al salir y ningún manejador de `beforeunload`**. Un diálogo bloquearía lo que esta decisión dice no bloquear, y `beforeunload` ni siquiera se dispara en la navegación interna de la SPA. Se acepta la pérdida del enlace; «Mis exportaciones» (`GET /data-exports`) se reconsidera en `1.9b`.

#### 13.14.5 Consulta del estado

- **`RN-CORE-49`** · La consulta de `GET /data-exports/{id}` es **una sola en vuelo por exportación**, con espera creciente entre consultas y una duración máxima tras la cual se deja de consultar y se ofrece «Comprobar de nuevo», que reinicia el ciclo. Se detiene al desmontar la vista. `409` se trata como «aún no está lista»; `410`, como caducada. Sin consulta indefinida: con el *worker* ausente (#128), una exportación se quedaría en `pendiente` para siempre y la vista consultaría sin fin. **Valores fijados por `ADR-054 §7.5`** (los que proponía esta especificación): espera inicial de **2 s**, que se **duplica** en cada consulta hasta un máximo de **30 s**, y se deja de consultar a los **10 min** desde la solicitud. Se implementan como constantes exportadas de `src/data-table`, para que `CA-CORE-189`/`-191` no dependan de las cifras.

#### 13.14.6 Dependencia operativa

Sin *worker* de colas desplegado (issue #128, Alta), **ninguna exportación termina** fuera de un entorno donde alguien arranque `queue:work` a mano. 1.9 no lo arregla (es infraestructura, `ADR-028`/`ADR-037`), pero su interfaz tiene que comportarse bien en ese caso: `RN-CORE-49`. Un paso que cierre una pantalla con exportación sin #128 resuelto entrega una funcionalidad que, en producción, no funciona.

### 13.15 Consumidores y migración

| Consumidor | Paso | Modo | Qué usa del componente | Observaciones |
|------------|------|------|------------------------|---------------|
| Usuarios (`GET /users`: `status`, `role`, `q`) | 1.9b | `page` | Filtros enumerado + texto, orden, columnas, tarjetas, exportación | **Exportación sin *endpoint***: `POST /users/exports` no existe. Si 1.9b lo quiere, es trabajo de `apps/api` con `RN-CORE-47`/`48`, paridad de filtros con `GET /users` salvo `q` y `RN-CORE-58` (`q` ⇒ `422`); en la tabla, exportar queda deshabilitado con búsqueda activa (`RN-CORE-57`). Antes, el usuario decide `OPEN-054-01` (idioma del CSV) |
| Invitaciones, importaciones | 1.9b | `page` | Filtros, orden, estados | — |
| Roles (solo lectura en 1.1; CRUD desde 1.5) | 1.9b / 1.5b | `page` | Listado | — |
| Auditoría (`GET /audit-logs`) | 1.9b | `cursor` | Filtros de rango, enumerados, «cargar más», tope (`RN-CORE-52`), **exportación ya existente** | Primer consumidor real de la exportación y del modo `cursor`. **Antes de conectarlo**, `1.9b` resuelve los dos hallazgos de §13.20 (puntos 5 y 6): nombres del rango `from`/`to` frente a `occurred_at_from`/`occurred_at_to`, y paridad de filtros de `POST /audit-logs/exports` con `GET /audit-logs` |
| Permisos efectivos de un usuario (`GET /users/{id}/effective-permissions`) | 1.5b | `local` o `page` según su contrato | Listado con procedencia | El implementador de 1.5b comprueba la paginación en `REQ-PERM/api.md`. Si no está paginado, 1.5b, como primer consumidor real, añade el modo `local` dentro de `src/data-table` (`OPEN-CORE-26`, opción B; `ADR-054 §2.1`) |
| **Matriz de concesión** (recurso × acción × ámbito) | 1.5b | — | **No es una tabla de datos** en el sentido de este paso: es una rejilla de edición con celdas que son controles | Se preveía construirla a medida en 1.5b sobre `components/ui/table` como excepción nominal de `RN-CORE-53`. Con la regla vigente (la lista de excepciones solo se reduce), **1.5b tiene que proponer la modificación de `RN-CORE-53` y el usuario aprobarla** antes de construirla así |
| `MfaComplianceArea.vue` | **1.9** | `page` | Filtro enumerado `state`, paginación, acción por fila | Se migra (`OPEN-CORE-28`, opción A, decisión del usuario 2026-09-30) |
| `MfaExemptionsArea.vue`, `AdminSsoView.vue`, `SessionsView.vue` | **1.9b** o posterior | Por decidir en ese paso | Por decidir en ese paso | **Excepciones explícitas** de `RN-CORE-53` en 1.9 (§13.2, decisión del usuario 2026-09-30). Su migración retira la entrada correspondiente de la lista del test |

**Migración de `MfaComplianceArea.vue`** (`OPEN-CORE-28`, opción A):

- **Paridad funcional estricta**: mismas peticiones (`GET /mfa-compliance/users` con `state` por comas y `page`), mismas columnas, la misma emisión de `reset-user`, el mismo tratamiento del `403` (`emit('forbidden')`) y el mismo `refresh()` expuesto. El aspecto cambia (barra de filtros en vez de casillas sueltas, paginador completo, tarjetas en móvil): es lo que se busca al unificar.
- **No corrige #116**: #116 (filas antes de elegir rol) es Baja y no se resuelve sin que lo pida el usuario (`CLAUDE.md §5`). Si la migración obliga a tocar el comportamiento de #116, se para y se pregunta. **El `'—'` literal (#90) desaparece de esta tabla** por construcción, al usar el valor vacío común de §13.12 (`OPEN-CORE-27`); #90 sigue abierto para el resto de vistas.
- **Estado en la URL** (`RN-CORE-54`): **la tabla migrada no lo declara**. Hoy `MfaComplianceArea.vue` no refleja nada en la URL (no importa `vue-router`; verificado sobre el fuente el 2026-09-30), y la paridad estricta (`OPEN-CORE-28`) impide añadírselo; al ser el reflejo opcional por tabla (`ADR-054 §6.2`), no hay conflicto. Tampoco usa `q` (su listado no tiene búsqueda). El primer consumidor real del estado en URL será una pantalla de `1.9b`; en 1.9, `CA-CORE-198` se prueba con la tabla de prueba.
- **Cierra en parte #120**: la tabla migrada gana tests por construcción (`CA-CORE-187`). El resto de `/administracion/mfa` sigue sin test.

### 13.16 Reglas de negocio

| ID | Regla |
|----|-------|
| `RN-CORE-37` | Solo `src/data-table/**` importa `@tanstack/vue-table`, sea estática, dinámica o de tipos (§13.3, `ADR-054 §1.2`) |
| `RN-CORE-38` | `src/data-table/**` no importa `src/modules/**` ni construye URLs de *endpoints* (§13.3) |
| `RN-CORE-39` | Una sola columna ordenada; ciclo ascendente → descendente → sin `sort` (§13.6) |
| `RN-CORE-40` | Búsqueda `q` con espera de 300 ms; una petición por pausa (§13.7) |
| `RN-CORE-41` | Solo se aplica la respuesta de la última consulta emitida (§13.7) |
| `RN-CORE-42` | `422` de filtro: mensaje del servidor junto a los filtros, sin presentar filas como filtradas (§13.7) |
| `RN-CORE-43` | Configuración de columnas en `localStorage` `plataforma.table.<tableId>`, forma `{"v":1,…}` cerrada, solo `id` de columna, con `try/catch`; `tableId` literal `<modulo>.<nombre>`, único, comprobado por escaneo de fuentes (§13.8) |
| `RN-CORE-44` | Tabla nativa con `caption`, `th scope="col"`, una columna `th scope="row"`, `aria-sort` solo en la ordenada, sin `role="grid"` (§13.11) |
| `RN-CORE-45` | Modo `page`: si la respuesta trae `data: []` con `page > 1` y `total > 0` (se borraron filas mientras se paginaba), se pide `last_page` una sola vez, no en bucle |
| `RN-CORE-46` | La SPA nunca genera ficheros de exportación; siempre *endpoint* del módulo, en cola, URL firmada (§13.14.1) |
| `RN-CORE-47` | **(servidor, norma común)** Una sola clase de escritura CSV en `apps/api/app/Support/Csv/`, sin interfaz; ningún `fputcsv` fuera de ella (§13.14.3) |
| `RN-CORE-48` | **(servidor, norma común)** Tipo de cada columna declarado por el generador, nunca deducido; neutralización solo sobre texto y cabecera, con la expresión de §13.14.3 (primer carácter `= + - @`, tabulador, CR o LF, o primer carácter no blanco `= + - @`) (§13.14.3) |
| `RN-CORE-49` | Consulta del estado de una exportación: una en vuelo, 2 s duplicando hasta 30 s, parada a los 10 min con «Comprobar de nuevo», se detiene al desmontar (§13.14.5, `ADR-054 §7.5`) |
| `RN-CORE-50` | Las filas de una tabla viven solo en la memoria del componente: **nunca** en `localStorage`, `sessionStorage`, IndexedDB ni en la URL. Al desmontar la vista o cerrar sesión se descartan |
| `RN-CORE-51` | La visibilidad del botón de exportar se decide por `<recurso>.exportar` en `/me.permissions`, nunca por rol (`RN-CORE-23`); el servidor sigue decidiendo (`INV-002`) |
| `RN-CORE-52` | Modo `cursor`: tope de 1.000 filas acumuladas; al alcanzarlo, aviso con acotar/exportar en lugar de «Cargar más» (§13.5, `OPEN-CORE-19`) |
| `RN-CORE-53` | Fuera de `src/data-table/**`, ningún fichero importa `@/components/ui/table` ni `@tanstack/vue-table`, ni contiene `<table` en un `.vue` (salvo `src/components/ui/**`), excepto las tres excepciones explícitas de §13.2 (`MfaExemptionsArea.vue`, `AdminSsoView.vue`, `SessionsView.vue`); la lista de excepciones solo puede reducirse, nunca crecer (§13.3, `OPEN-CORE-29`) |
| `RN-CORE-54` | Estado de la consulta en la URL (página, `per_page`, orden, enumerados, booleanos, fechas); `q` y el cursor nunca; opcional por tabla y como máximo una por ruta (§13.5, `OPEN-CORE-22`) |
| `RN-CORE-55` | Tarjetas por debajo de 768 px por defecto; desplazamiento interno solo si el consumidor lo declara para esa tabla, con el criterio de comparación entre filas (§13.9, `OPEN-CORE-20`, `ADR-054 §4`) |
| `RN-CORE-56` | Modo `cursor`: «Cargar más» como botón, sin desplazamiento infinito; un fallo de carga adicional conserva las filas y «Reintentar» repite con el mismo `cursor` (§13.5) |
| `RN-CORE-57` | Con `q` activo, el control de exportación está deshabilitado y explica por qué; la solicitud nunca lleva `q` ni `sort` (§13.14.1) |
| `RN-CORE-58` | **(servidor, norma común)** Ningún *endpoint* de exportación acepta `q`: `422` con código propio del recurso; ningún texto libre llega a `audit_logs` por `data_exports.filters` (§13.14.3) |

**Issues abiertos relacionados con estas reglas** (detalle en §13.20): [#266](https://github.com/pirexia/plataforma-educativa/issues/266) (nombres del rango de fechas de auditoría, contra `ADR-038 §5.2`; afecta a la regla «`id` de columna = parámetro» de §13.4 en la pantalla de auditoría de `1.9b`), [#267](https://github.com/pirexia/plataforma-educativa/issues/267) (`POST /audit-logs/exports` sin paridad de filtros con su listado, `ADR-054 §8.2`, `api.md §13.4`) y [#273](https://github.com/pirexia/plataforma-educativa/issues/273) (`QUEUE_CONNECTION` en `operacion.md §2`, que condiciona el diagnóstico de `RN-CORE-49`). Ninguno bloquea 1.9; los dos primeros se resuelven antes de que `1.9b` conecte la pantalla de auditoría.

### 13.17 Casos límite

| Situación | Comportamiento |
|-----------|----------------|
| Se borra una fila de la última página mientras se mira | `RN-CORE-45` |
| Llega en `meta.total` un número distinto entre páginas (altas concurrentes) | Se muestra el último recibido. Sin corrección en cliente: el modo `page` no promete consistencia ante escrituras concurrentes, y quien la necesita usa `cursor` (`ADR-038 §4.1`) |
| Un filtro guardado en la URL (`RN-CORE-54`) con un valor que ya no existe (rol borrado) | El servidor responde `422` (valor conocido inválido) o ignora el parámetro; se aplica `RN-CORE-42` y la acción «Limpiar filtros» lo resuelve |
| Configuración de columnas guardada con una columna que el consumidor retiró | Se ignora ese `id` (`RN-CORE-43`) |
| Todas las columnas ocultables ocultas | Se permite: `rowHeader` y acciones no son ocultables, así que la tabla nunca queda vacía de columnas |
| Cambio de idioma con la tabla abierta | §13.12, sin nueva petición |
| `403` por pérdida de permiso a mitad de paginación | Correspondencia de §12.6: «sin acceso» y recarga de `/me` (`ADR-053 §6`) |
| `403 module-disabled` | Ídem, conservando el mensaje de `RMOD-009` |
| `401` | Redirección a `/entrar` (§12.3.1), las filas se descartan (`RN-CORE-50`) |
| Exportación solicitada dos veces seguidas | Dos filas de `data_exports`. Sin `Idempotency-Key`: ninguno de los criterios de `ADR-038 §8.1` se cumple (no mueve dinero, no envía nada a terceros, no es un lote sobre entidades, y el duplicado es inocuo y auditado). El botón se deshabilita mientras la solicitud está en vuelo |
| Exportación que vence mientras la vista está abierta | Al pulsar el enlace, la URL firmada falla en el almacenamiento, fuera de la SPA; `GET /data-exports/{id}` dirá `410` en la siguiente consulta. Se muestra la caducidad junto al enlace para que no sorprenda |
| Navegador sin `localStorage` utilizable | Configuración por defecto en cada carga; el resto funciona igual (`RN-CORE-43`) |
| 320 px con muchas columnas | Tarjetas, o desplazamiento interno si la tabla lo declara (`RN-CORE-55`); nunca desplazamiento horizontal de la página |
| Modo `cursor` al llegar a 1.000 filas | Aviso de tope y fin de la paginación (`RN-CORE-52`); cambiar filtros u orden reinicia la cuenta |
| Falla un «Cargar más» (`503`, sin conexión) con 400 filas ya cargadas | Las 400 filas se conservan; error junto al control; «Reintentar» repite con el mismo `cursor` (`RN-CORE-56`) |
| Recarga de una vista con tabla en modo `cursor` | La lista empieza desde el principio: el cursor no está en la URL (`RN-CORE-54`) |
| El usuario escribe una búsqueda y quiere exportar | Control de exportación deshabilitado con la explicación; al borrar la búsqueda se habilita y exporta con los demás filtros (`RN-CORE-57`) |
| Dos tablas en la misma vista | Solo una, la principal, puede declarar estado en URL; la otra guarda su consulta en memoria (`RN-CORE-54`) |

### 13.18 Criterios de aceptación

Vitest salvo los marcados **[Playwright]**. Cada test cita su ID (`INV-015`). Con las decisiones del usuario del 2026-09-30, los que dependían de `OPEN-CORE-19`/`-20`/`-22`/`-28` pasan a firmes, `CA-CORE-199` se retira (`OPEN-CORE-26`, opción B) y `CA-CORE-200` a `CA-CORE-203` cubren decisiones que no tenían criterio. **Ajuste a `ADR-054`**: `CA-CORE-176`, `-188`, `-198` y `-202` se reescriben, y `CA-CORE-204` a `-206` se añaden; con la ratificación del ADR (2026-09-30) son firmes. `CA-CORE-200` se reescribe con el alcance de `RN-CORE-53` fijado por el usuario el mismo día (tres excepciones explícitas). Donde dice «tabla de prueba», es una tabla declarada solo en el test, con una función de petición simulada y un módulo ficticio `fixture`.

#### Paginación y datos

- **`CA-CORE-160`** [`ADR-038 §4.3`] · **Dado** una tabla de prueba en modo `page` cuya primera respuesta trae `meta = {current_page: 2, per_page: 25, total: 137, last_page: 6}`, **entonces** se muestra «página 2 de 6» y el total de 137 con el formato del idioma activo; **cuando** se activa «siguiente», la función de petición recibe `page=3, per_page=25`; **y** en la página 6, «siguiente» y «última» están deshabilitados.
- **`CA-CORE-161`** [`ADR-038 §4.3`] · **Dado** el selector de filas por página, **entonces** ofrece exactamente 25, 50 y 100 con 25 por defecto; **cuando** se elige 50 estando en la página 4, la petición lleva `page=1, per_page=50`.
- **`CA-CORE-162`** [`RN-CORE-45`] · **Dado** el modo `page` en la página 3, **cuando** la respuesta trae `data: []` con `total: 51` y `last_page: 2`, **entonces** se pide la página 2 exactamente una vez; **y dado** `total: 0`, no se pide nada más y se pinta el estado vacío.
- **`CA-CORE-163`** [`ADR-038 §4.4`, `§4.5`] · **Dado** una tabla en modo `cursor` cuya respuesta trae `meta = {next_cursor: "c1", has_more: true}`, **entonces** no hay números de página ni total y existe «Cargar más»; **cuando** se activa, la petición lleva `cursor=c1` y las filas nuevas se añaden detrás de las existentes; **y cuando** llega `has_more: false`, «Cargar más» desaparece.
- **`CA-CORE-164`** [`ADR-038 §4.4` regla 2] · **Dado** el modo `cursor` con filas ya cargadas por cursor, **cuando** cambia un filtro o el orden, **entonces** la siguiente petición **no** lleva `cursor` y la lista se sustituye, no se amplía.
- **`CA-CORE-165`** [`RN-CORE-52`, `OPEN-CORE-19`] · **Dado** el modo `cursor` con el tope de la constante exportada (1.000) y respuestas simuladas de 50 filas con `has_more: true`, **cuando** las filas acumuladas alcanzan o superan el tope, **entonces** «Cargar más» se sustituye por el aviso traducido de tope, ninguna petición más sale al pulsar nada de la paginación, y todas las filas recibidas siguen en el documento; **y dado** `canExport = true`, el aviso ofrece la acción de exportar; **y cuando** cambia un filtro, la lista se reinicia y «Cargar más» vuelve a estar disponible.
- **`CA-CORE-166`** [`RN-CORE-41`] · **Dado** dos consultas emitidas en orden A y B, **cuando** B responde antes que A, **entonces** la tabla muestra las filas de B y, al llegar A, no cambia.
- **`CA-CORE-204`** [`RN-CORE-56`, `ADR-054 §2.3`] · **Dado** el modo `cursor` con 100 filas cargadas y `next_cursor: "c2"`, **cuando** «Cargar más» responde `503`, **entonces** las 100 filas siguen en el documento, aparece el error junto al control con «Reintentar», y **cuando** se activa «Reintentar», la petición lleva `cursor=c2` (no reinicia la lista ni omite el cursor); **y dado** que el usuario desplaza la lista hasta el final sin activar ningún control, no sale ninguna petición de paginación (sin desplazamiento infinito).

#### Ordenación y filtrado

- **`CA-CORE-167`** [`RN-CORE-39`, `ADR-038 §5.3`] · **Dado** una columna `sortable` con `id = "family_name_1"`, **cuando** se activa su botón tres veces, **entonces** las peticiones llevan sucesivamente `sort=family_name_1`, `sort=-family_name_1` y ningún `sort`; su `th` lleva `aria-sort="ascending"`, `"descending"` y ningún `aria-sort`; y en ningún momento hay dos `th` con `aria-sort`. **Dado** una columna no `sortable`, su cabecera no contiene botón.
- **`CA-CORE-168`** · **Dado** el modo `page` en la página 4, **cuando** cambia el orden o cualquier filtro, **entonces** la petición lleva `page=1`.
- **`CA-CORE-169`** [`ADR-038 §5.2`, `§13.3`] · **Dado** un filtro enumerado de `id = "status"` con `activo` e `inactivo` marcados, un rango de `id = "occurred_at"` con las dos fechas y un booleano `id = "is_system"` en «sí», **entonces** la consulta lleva `status=activo,inactivo`, `occurred_at_from=…`, `occurred_at_to=…` e `is_system=true`, sin tabla de correspondencias entre `id` y parámetro; **y** con el booleano en «todos», no lleva `is_system`.
- **`CA-CORE-170`** [`RN-CORE-40`] · **Dado** el campo de búsqueda con temporizadores simulados, **cuando** se escriben cinco caracteres con 50 ms entre cada uno, **entonces** sale una sola petición, con `q` igual al texto completo, 300 ms después de la última pulsación.
- **`CA-CORE-171`** [`RN-CORE-42`, `ADR-038 §5.2`] · **Dado** una tabla con filas cargadas, **cuando** un cambio de filtro responde `422` con `errors.occurred_at_from[0].message`, **entonces** ese mensaje aparece junto a la barra de filtros con `role="alert"`, la tabla no pasa al estado de error de pantalla completa, y el texto de estado indica que el filtro no se ha aplicado.
- **`CA-CORE-172`** [`ADR-038 §7.3`] · **Dado** un filtro enumerado cuya respuesta o definición trae un valor sin etiqueta traducida, **entonces** se muestra el código en crudo y no se lanza ningún error.

#### Columnas configurables

- **`CA-CORE-173`** [`RN-CORE-43`] · **Dado** el menú de columnas, **cuando** se oculta una columna ocultable, **entonces** desaparece de la tabla (y de las tarjetas, si aplica) y `localStorage["plataforma.table.<tableId>"]` pasa a ser exactamente `{"v":1,"hidden":["<id>"]}`; **y** la columna `rowHeader` y la de acciones no aparecen en el menú como ocultables.
- **`CA-CORE-174`** [`RN-CORE-43`] · **Dado** en `localStorage` un valor que no se puede leer, con `v: 2` o con un `id` inexistente junto a uno válido, **cuando** se monta la tabla, **entonces** con los dos primeros se aplica la configuración por defecto y se borra la clave, y con el tercero se oculta solo la columna válida; **y dado** un `localStorage` que lanza en toda operación, la tabla se pinta con la configuración por defecto sin error.
- **`CA-CORE-175`** · **Dado** columnas ocultas, **cuando** se activa «Restablecer columnas», **entonces** vuelve la configuración por defecto y la clave se elimina.
- **`CA-CORE-176`** [`RN-CORE-43`, `ADR-054 §5.3`] · **Dado** un test que recorre las fuentes de producción de `src/modules/**` y extrae cada `tableId` declarado, **entonces** todo `tableId` es un literal de cadena con forma `<modulo>.<nombre>` y ninguno se repite; con casos fijos que prueban que el test detecta un duplicado, un `tableId` sin la forma `<modulo>.<nombre>` y un `tableId` no literal (calculado).

#### Móvil (`RUX-RESP-004`, `RN-CORE-55`, `OPEN-CORE-20`)

- **`CA-CORE-177`** [`RUX-RESP-004`, `RUX-RESP-001`] **[Playwright]** · **Dado** una pantalla con la tabla de prueba (o `MfaComplianceArea` migrada), **cuando** la ventana mide 320 px, **entonces** no hay ningún elemento `table` visible, existe la lista de tarjetas con una tarjeta por fila, y `scrollWidth ≤ clientWidth` del documento; **y cuando** mide 768 px, existe la `table` y no la lista.
- **`CA-CORE-178`** [`RUX-RESP-007`] **[Playwright]** · **Dado** 320 px con `hasTouch: true`, **cuando** se miden el botón de ordenación de tarjetas, los controles del paginador, «Cargar más», el menú de columnas y una acción de tarjeta, **entonces** todos miden al menos 44 × 44 px.
- **`CA-CORE-179`** · **Dado** la vista de tarjetas, **entonces** es una lista (`ul`) con un `li` por fila; el valor de la columna `title` es el encabezado de la tarjeta; cada `field` aparece como par `dt`/`dd` con la etiqueta traducida de su cabecera; las columnas `omit` y las ocultas no aparecen; y el orden se cambia con un selector de columna y sentido que produce el mismo `sort` que la cabecera.
- **`CA-CORE-203`** [`RN-CORE-55`, `RUX-RESP-004`, WCAG 1.4.10/2.1.1] **[Playwright]** · **Dado** una tabla de prueba declarada con desplazamiento interno y más columnas de las que caben, **cuando** la ventana mide 320 px, **entonces** existe la `table` y no la lista de tarjetas, `scrollWidth ≤ clientWidth` del documento, el contenedor de la tabla es enfocable con `Tab`, tiene nombre accesible traducido y, con el foco en él, la tecla flecha derecha aumenta su `scrollLeft`.

#### Estados

- **`CA-CORE-180`** [`RUX-006`] · **Dado** la primera petición sin respuesta, **entonces** se pinta el estado de carga de §12.6 con `role="status"` y la barra de filtros ya es interactiva; **y dado** una recarga por cambio de página, las filas anteriores siguen en el documento y la región lleva `aria-busy="true"` hasta la respuesta.
- **`CA-CORE-181`** [`RUX-006`] · **Dado** `data: []` sin filtros activos, **entonces** se pinta el texto de vacío del consumidor; **y dado** `data: []` con un filtro activo, se pinta el texto de «sin resultados con estos filtros» con la acción «Limpiar filtros», que al activarse lanza la petición sin filtros y mueve el foco al campo de búsqueda.
- **`CA-CORE-182`** [`RUX-006`, `RNF-UX-007`] · **Dado** una respuesta `503` con `request_id`, **entonces** se pinta el estado de error de §12.6 con el `request_id` y «Reintentar», que repite **la misma** consulta (página, orden y filtros); **y** los casos `403`, `403 module-disabled` y `429` pasan por la función de correspondencia de `CA-CORE-140` (se comprueba que se invoca, no se repite su tabla).

#### Accesibilidad

- **`CA-CORE-183`** [`RN-CORE-44`, `RUX-004`] · **Dado** la tabla pintada, **entonces** tiene `caption` con el nombre traducido que da el consumidor, todas sus cabeceras son `th scope="col"`, la columna `rowHeader` de cada fila es `th scope="row"`, no hay ningún `role="grid"`, y el botón de ordenación tiene como nombre accesible la cabecera más la acción traducida.
- **`CA-CORE-184`** [`RUX-004`] · **Dado** una carga completada con 137 resultados, **entonces** la región `aria-live="polite"` contiene el texto traducido de «137 resultados» con el plural correcto en `es`, `en`, `de` y `fr`; **y durante** la escritura en la búsqueda no cambia hasta que llega la respuesta de la petición única de `CA-CORE-170`.
- **`CA-CORE-185`** [`RUX-004`] **[Playwright]** · **Dado** la pantalla con la tabla, **cuando** se recorre solo con teclado (`Tab`, `Shift+Tab`, `Enter`, `Espacio`, `Esc` en los menús), **entonces** se alcanzan y accionan, en orden de documento, búsqueda, filtros, menú de columnas, exportación, botones de ordenación, acciones de fila y paginador; los menús atrapan y devuelven el foco; y al activar «siguiente» en la penúltima página el foco no acaba en `body`.
- **`CA-CORE-186`** [WCAG 2.4.6] · **Dado** `MfaComplianceArea` migrada con dos filas, **entonces** los dos botones de restablecimiento tienen nombres accesibles distintos, cada uno con el nombre de su usuario.

#### Migración (`OPEN-CORE-28`)

- **`CA-CORE-187`** [`REQ-AUTH-003`] · **Dado** `MfaComplianceArea` migrada, **cuando** se marcan los estados `pending` y `past_deadline` y se pasa a la página 2, **entonces** la petición es `GET /mfa-compliance/users` con `state=pending,past_deadline` y `page=2`; la acción de fila emite `reset-user` con el usuario de esa fila; un `403` emite `forbidden`; `refresh()` sigue expuesto; y todos los tests preexistentes de `/administracion/mfa` siguen en verde.

#### Exportación

- **`CA-CORE-188`** [`RN-CORE-46`, `RN-CORE-51`, `RN-CORE-57`, `ADR-054 §7.3`/`§8.1`] · **Dado** una tabla de prueba con `canExport = false`, **entonces** no hay control de exportación en el documento; **y dado** `canExport = true` con el filtro `status=activo` y el orden `-created_at` en la página 3, **cuando** se pulsa exportar, **entonces** la función de solicitud del consumidor recibe exactamente el filtro `status` con el valor `activo` y **ninguna** de estas claves: `sort`, `page`, `per_page`, `cursor`, `q`; y el control queda deshabilitado mientras la solicitud está en vuelo.
- **`CA-CORE-205`** [`RN-CORE-57`, `ADR-054 §7.4`] · **Dado** una tabla de prueba con `canExport = true` y el filtro `status=activo`, **cuando** se escribe un texto en la búsqueda y la consulta con `q` se aplica, **entonces** el control de exportación está deshabilitado (no solo con estilo: no invoca la función de solicitud al activarse), con un texto traducido asociado que explica que hay que borrar la búsqueda para exportar; **y cuando** se borra la búsqueda, el control se habilita y, al pulsarlo, la solicitud lleva `status` y no lleva `q`.
- **`CA-CORE-189`** [`RN-CORE-49`] · **Dado** una solicitud que responde `202` con `public_id` y temporizadores simulados, **cuando** `GET /data-exports/{id}` devuelve `pendiente`, después `generando` y después `completada` con `download_url` y `expires_at`, **entonces** nunca hay dos consultas en vuelo a la vez, el intervalo entre consultas crece, se muestra el estado con `role="status"`, y al final aparece un enlace cuyo `href` es `download_url`, con la caducidad formateada.
- **`CA-CORE-190`** · **Dado** la consulta de estado, **cuando** responde `fallida` con `error_code = "core.export.generation_failed"`, **entonces** se muestra su traducción con `role="alert"`; **cuando** responde `409`, se sigue esperando sin error; **cuando** responde `410`, se muestra el mensaje de caducada con la acción de volver a solicitar; **y cuando** la solicitud responde `422`, se muestra el `message` del servidor.
- **`CA-CORE-191`** [`RN-CORE-49`, issue #128] · **Dado** una exportación que sigue `pendiente` indefinidamente, **cuando** se agota la duración máxima, **entonces** se deja de consultar y se ofrece «Comprobar de nuevo»; **y cuando** la vista se desmonta con una consulta programada, no sale ninguna petición más.
- **`CA-CORE-202`** [`OPEN-CORE-25`, `ADR-054 §7.7`] · **Dado** una exportación en estado `pendiente` o `generando`, **entonces** junto al estado se muestra, de forma permanente, el aviso traducido de que no se podrá descargar desde aquí si se sale de la vista; **y cuando** el usuario navega a otra ruta, la navegación se produce sin diálogo de confirmación ni retención (ninguna guarda de navegación la cancela) y **no hay ningún manejador de `beforeunload` registrado** en `window`; **y dado** el estado `completada`, `fallida` o sin exportación en curso, el aviso no está en el documento.
- **`CA-CORE-192`** [`RN-CORE-46`] · **Dado** `src/data-table/**` y todo `src/modules/**`, **entonces** ningún fichero de producción construye un fichero de exportación en el cliente: no aparecen `new Blob(`, `URL.createObjectURL(`, `text/csv` ni `application/vnd.openxmlformats` salvo en excepciones nominales justificadas en el propio test; con casos fijos que prueban que el test los detecta.

#### Arquitectura, privacidad y traducción

- **`CA-CORE-193`** [`RN-CORE-37`, `RNF-MANT-007`] · **Dado** `src/` salvo tests, **entonces** solo los ficheros bajo `src/data-table/` importan `@tanstack/vue-table`; con casos fijos que prueban que el test detecta un *import* estático, uno dinámico y uno de tipos (`import type`).
- **`CA-CORE-194`** [`RN-CORE-38`, `INV-007`] · **Dado** `src/data-table/**`, **entonces** no importa `@/modules/**` ni rutas relativas que resuelvan a `src/modules/`, y solo usa los destinos `@/` de §13.3.
- **`CA-CORE-195`** [`RN-CORE-50`] · **Dado** `localStorage`, `sessionStorage` e `indexedDB` simulados, **cuando** una tabla de prueba carga, pagina, filtra y se desmonta, **entonces** la única escritura es la clave de `RN-CORE-43`, y su valor no contiene ningún valor de fila ni el texto de búsqueda (el test usa valores de fila distinguibles y los busca).
- **`CA-CORE-196`** [`INV-009`] · **Dado** los cuatro `locales/*.json`, **entonces** toda clave nueva de `dataTable.*` existe en `es`, `en`, `de` y `fr`, y `npm run lint:i18n` termina sin hallazgos; **y** `CA-CORE-083` (tipografía en `rem`) se amplía a `src/data-table/**`.
- **`CA-CORE-197`** [`ADR-052`] · **Dado** los componentes vendorizados que añada 1.9 (§13.3), **entonces** pasan los tests de `docs/design-system.md §10` sin excepción nueva, y no tienen texto propio (`RN-DS-24`).
- **`CA-CORE-200`** [`RN-CORE-53`, `OPEN-CORE-29`] · **Dado** los ficheros de producción de `src/` salvo tests, fuera de `src/data-table/` y fuera de la lista cerrada de excepciones del propio test, **entonces** ninguno importa `@/components/ui/table` (ni por ruta relativa que resuelva a `src/components/ui/table`) ni `@tanstack/vue-table`, y ningún `.vue` fuera además de `src/components/ui/` contiene `<table`; **y dado** la lista de excepciones, **entonces** es un subconjunto de exactamente estas tres rutas, escritas como constante en el test: `src/modules/auth/components/admin/MfaExemptionsArea.vue`, `src/modules/auth/views/AdminSsoView.vue` y `src/modules/auth/views/SessionsView.vue` (una cuarta ruta hace fallar el test); **y** cada ruta que siga en la lista sigue incumpliendo la regla (un fichero ya migrado que siga en la lista hace fallar el test); **y** `MfaComplianceArea.vue` no está en la lista. Con casos fijos que prueban que el test detecta un *import* estático, uno dinámico y uno relativo de `@/components/ui/table`, un `<table` crudo en un `.vue`, una excepción añadida fuera de las tres y una excepción que ya no incumple.
- **`CA-CORE-201`** [`OPEN-CORE-27`, `INV-009`, issue #90] · **Dado** una fila de prueba con un campo nulo, **cuando** se pinta en tabla y en tarjeta, **entonces** la celda y el `dd` contienen la marca visual con `aria-hidden="true"` y un texto solo para lector de pantalla igual a la traducción de `dataTable.emptyValue` en el idioma activo (comprobado en `es`, `en`, `de` y `fr`); **y** ni `src/data-table/**` ni `MfaComplianceArea.vue` contienen `'—'` como literal.

#### Estado en la URL (`RN-CORE-54`, `OPEN-CORE-22`)

- **`CA-CORE-198`** [`RN-CORE-54`, `ADR-054 §6`] · **Dado** una tabla de prueba que declara el estado en URL, con página 3, `sort=-created_at` y `status=activo`, **cuando** se recarga el documento, **entonces** se restaura la misma consulta desde la URL; **y** el texto de búsqueda `q` no aparece nunca en la URL ni en `history.state`; **y dado** una tabla de prueba en modo `cursor` que declara el estado en URL, tras «cargar más» la URL no contiene `cursor` y, al recargar, la primera petición no lleva `cursor`; **y dado** una tabla de prueba que **no** declara el estado en URL, ni paginar, ni ordenar, ni filtrar modifican la URL de la ruta.
- **`CA-CORE-206`** [`RN-CORE-54`, `OPEN-CORE-28`] · **Dado** `MfaComplianceArea` migrada, **cuando** se marcan filtros y se pagina, **entonces** la URL de `/administracion/mfa` no cambia (la tabla no declara estado en URL, paridad estricta).

#### Retirado

- *Identificadores `CA-CORE-153` a `-159` no usados (reservados, sin criterio asociado); la serie de 1.9 empieza en 160.*
- ~~**`CA-CORE-199`**~~ · **Retirado** el 2026-09-30: el modo `local` no existe en 1.9 (`OPEN-CORE-26`, opción B; `ADR-054 §2.1`). El identificador queda reservado y no se reutiliza; el paso que añada el modo escribe sus propios criterios.

### 13.19 Documentación a actualizar al cerrar 1.9

- Este documento: estado de §13 (implementada). La ubicación ya es definitiva (`OPEN-CORE-18`, resuelta).
- `docs/adr/ADR-054-tablas-de-datos-y-exportacion-de-listados.md`: ACEPTADA (ratificado por el usuario el 2026-09-30; la edición del ADR no corresponde a esta especificación). El índice de la sección 18 del documento de requisitos y su historial de versiones deben reflejarlo (hallazgo 6 del ADR); lo confirma `doc-reviewer`.
- `docs/design-system.md §12`: componentes vendorizados nuevos, si los hay (`checkbox`, `popover`…).
- `docs/i18n.md`: espacio de nombres `dataTable.*`.
- `PRIVACY.md §2.1b`: clave `plataforma.table.<tableId>` (`RN-CORE-43`).
- `SECURITY.md`, fila «Exportaciones generadas (CSV)»: añadir el **salto de línea (LF)** a la lista de caracteres, que hoy omite aunque `RN-CORE-36` y el código lo incluyen (hallazgo 3 de `ADR-054`), y sustituir «se fijará como norma común en el ADR… cuando exista» por la referencia a `ADR-054 §9`-`§10`, a la segunda condición de neutralización (primer carácter no blanco) y a `RN-CORE-46`-`48`/`58`.
- `ARCHITECTURE.md` (frontend: `src/data-table/`, importación única de TanStack), `CHANGELOG.md`.
- `docs/manual-usuario/admin.md`: cómo filtrar, ordenar, configurar columnas y exportar (sección común para los manuales que existan, issue #65).
- `PLAN-IMPLEMENTACION.md`: la línea de 1.9 **ya se actualizó** al aprobar la especificación (2026-09-30, sin virtualización, `OPEN-CORE-19`); al cerrar, se marca el paso como terminado.

### 13.20 Hallazgos fuera del ámbito de esta especificación

No se corrigen aquí; se reportan:

1. **`operacion.md §2` decía `QUEUE_CONNECTION = redis`**, mientras `CLAUDE.md §1` y `apps/api/config/queue.php` dicen `database`, sin *worker* desplegado (#128). Issue [#273](https://github.com/pirexia/plataforma-educativa/issues/273) (Media, detectado por `doc-reviewer`): **`operacion.md §2` ya está corregido** en la rama `feature/1.9-tablas-de-datos`. Queda un resto en `operacion.md §3` y §8, que siguen describiendo Redis/Horizon como sistema de colas vigente (fila «Redis» de §3 y síntoma «Importación queda en `subido`» de §8). Son secciones de 1.1, fuera de §13: se reportan, no se corrigen aquí.
2. **`data_exports.filters` con política `Full`** copiaría en `audit_logs` cualquier filtro de texto libre con un dato personal (§13.14.3, último punto). No ocurre hoy (la exportación de auditoría no acepta `q`). **Resuelto como norma por `ADR-054 §9`** (`RN-CORE-58`), ratificado.
3. **`MfaComplianceArea.vue` usa `'—'` literal** (issue #90) e importa `useI18n` de `vue-i18n` directamente para obtener `locale` (patrón que issue #259 señala en otras vistas). Se resuelve en esa vista con la migración (`OPEN-CORE-28`, opción A) y el valor vacío común (`OPEN-CORE-27`, opción A); #90 y #259 siguen abiertos para las demás vistas.
4. **`SECURITY.md` promete para 1.9 una norma común de CSV** que es de servidor, en un paso que el plan y el encargo sitúan en `apps/web`. §13.1.3 lo resuelve separando decisión (`ADR-054 §10`) e implementación (#270).
5. **Nombres del rango de fechas de auditoría** (hallazgo 1 de `ADR-054`, severidad Media, issue [#266](https://github.com/pirexia/plataforma-educativa/issues/266)): `GET /audit-logs` y `POST /audit-logs/exports` usan `from`/`to` (código y `api.md` de este módulo), no `occurred_at_from`/`occurred_at_to` como exige `ADR-038 §5.2`. Con la regla «`id` de columna = parámetro» (`ADR-038 §13.3`), la pantalla de auditoría de `1.9b` enviaría un rango que el servidor ignora en silencio. No afecta a 1.9 (no conecta esa pantalla). Propuesta del ADR: en `1.9b`, antes de la pantalla, aceptar además los nombres conformes y retirar los antiguos por *expand/contract*.
6. **`POST /audit-logs/exports` sin paridad de filtros con su listado** (hallazgo 2 de `ADR-054`, severidad Media, issue [#267](https://github.com/pirexia/plataforma-educativa/issues/267)): le faltan `actor_id`, `actor_type`, `auditable_id` y `module`, y por `ADR-038 §5.2` no da error; el fichero no corresponde a lo que el usuario ve filtrado (el ámbito del permiso sí se sigue aplicando en el trabajo). Incumple `ADR-054 §8.2`. Se corrige antes de que `1.9b` conecte el botón de exportar de auditoría.
7. **Tres tablas existentes fuera del componente** (`MfaExemptionsArea.vue`, `AdminSsoView.vue`, `SessionsView.vue`, §13.2): no se migran en 1.9 por decisión del usuario (2026-09-30) y quedan como excepciones explícitas de `RN-CORE-53`. Su migración es trabajo de `1.9b` o de un paso posterior; `PLAN-IMPLEMENTACION.md` debería recogerlo en la línea de `1.9b` (fuera del ámbito de escritura de esta especificación).

### 13.21 Preguntas abiertas del paso 1.9

**Decididas por el usuario el 2026-09-30** (`OPEN-CORE-18` a `OPEN-CORE-29`), todas con la opción recomendada salvo donde se indica; las de `-19` a `-29` están **registradas en `ADR-054`** (ACEPTADA), que remite aquí. `ADR-054` añade una pregunta nueva, **`OPEN-054-01`**, abierta: **no bloquea 1.9 y bloquea `1.9b`**. Se conservan las opciones y su argumento, no se borran, para que una revisión futura pueda leer por qué se eligió cada una.

**Abiertas a 2026-09-30:**

| ID | Pregunta | Bloquea | Quién decide |
|----|----------|---------|--------------|
| `OPEN-054-01` | Idioma de la cabecera y de los valores enumerados del CSV | `1.9b` (antes del primer *endpoint* de exportación nuevo). **No bloquea 1.9** | Usuario |

#### `OPEN-054-01` · Idioma de la cabecera y de los valores enumerados del CSV — **abierta; bloquea `1.9b`, no `1.9`**

> Planteada por `ADR-054` (sección «Preguntas abiertas»), que **no la decide**. Esta especificación tampoco: no hay requisito que precise si un CSV de datos es un «documento generado» en el sentido de `CLAUDE.md §7` (cuatro idiomas obligatorios en los documentos generados), y decidirlo aquí sería inventar un requisito.

La exportación de auditoría escribe hoy nombres técnicos de columna (`occurred_at`) y códigos (`event`). Es coherente con el esquema fijo como contrato estable para programas (§13.14.2, `ADR-054 §8.1`), pero poco legible para secretaría. Afecta a todo *endpoint* de exportación, no al componente de 1.9, que no escribe ficheros (`RN-CORE-46`). Las opciones no las ha formulado el ADR; no se proponen aquí para no adelantar una decisión que corresponde al usuario, con `architect` si hace falta. Se resuelve antes de que `1.9b` añada `POST /users/exports` o modifique `POST /audit-logs/exports`.

#### `OPEN-CORE-18` · Ubicación de esta especificación — **RESUELTA** (2026-09-30, decisión del usuario)

> **Decisión: opción A.** La especificación se queda en `REQ-CORE/funcional.md §13`, con las secciones cortas de `datos`/`api`/`permisos`/`operacion` y los identificadores `RN-CORE-*`/`CA-CORE-*`. Sin mudanza.

§13.0: 1.9 no tiene `REQ-*` propio, y por el criterio de `ADR-052 §6` le correspondería un documento transversal.

- **A** · Se queda en `REQ-CORE/funcional.md §13`, con las secciones cortas de `datos`/`api`/`permisos`/`operacion`. Precedente de 1.8; la exportación sí es de `REQ-CORE`.
- **B** · Se muda a `docs/tablas-de-datos.md` (o a una ampliación de `docs/design-system.md`) con prefijos `RN-DT-*`/`CA-DT-*`/`OPEN-DT-*`, y aquí queda solo la parte de exportación (`RN-CORE-46`-`49`, `51`). Criterio de `ADR-052 §6`. Exige que otro agente con permiso fuera de `docs/modulos/` haga la mudanza.

**Recomendación: A**, por coste: la mudanza no aporta nada funcional y los consumidores inmediatos están en esta carpeta. Si se prefiere la coherencia con 1.7, B es igual de válida.

#### `OPEN-CORE-19` · Virtualización — **RESUELTA** (2026-09-30, decisión del usuario)

> **Decisión: opción A.** No se virtualiza en 1.9 ni se instala `@tanstack/vue-virtual`; tope de 1.000 filas acumuladas en modo `cursor` (`RN-CORE-52`, `CA-CORE-165`). Se retira la virtualización del alcance (§13.1.2) y de la línea del plan (`PLAN-IMPLEMENTACION.md`, paso 1.9, ya actualizada).

§13.13.

- **A** · **Sin virtualización en 1.9**, sin dependencia nueva, con **tope de filas acumuladas en modo `cursor`** y aviso para acotar o exportar. Tope propuesto: **1.000 filas** (20 cargas del `limit` por defecto de `ADR-038 §4.4`). Es una cifra de diseño sin medición detrás, y se revisa con `REQ-SEED`. Se actualiza la línea del plan.
- **B** · Virtualizar el modo `cursor` con `@tanstack/vue-virtual`, envuelta en `src/data-table`, con `aria-rowcount`/`aria-rowindex` y sin tarjetas virtualizadas. Exige que `architect` verifique antes mantenimiento, licencia y versiones (`CLAUDE.md §1`).
- **C** · Virtualización propia sin dependencia. Descartable: el coste de accesibilidad de B, más escribirla y mantenerla.

**Recomendación: A.** Resuelve el único caso real sin tocar la accesibilidad. Es reversible: B se puede añadir después dentro de `src/data-table` sin tocar a ningún consumidor, gracias a `RN-CORE-37`.

#### `OPEN-CORE-20` · Tablas en móvil (`RUX-RESP-004`) — **RESUELTA** (2026-09-30, decisión del usuario)

> **Decisión: opción A.** Tarjetas por debajo de 768 px, con desplazamiento horizontal interno como opción por tabla (`RN-CORE-55`, §13.9, `CA-CORE-177`-`179` y `CA-CORE-203`).

El requisito admite «scroll horizontal **o** vista alternativa de tarjetas».

- **A** · **Tarjetas por defecto por debajo de 768 px**, con desplazamiento horizontal interno como opción por tabla (§13.9).
- **B** · Solo desplazamiento horizontal interno en todas las tablas: más barato, y conforme con WCAG 1.4.10 (las tablas de datos están exceptuadas), pero peor de usar a 320 px con más de tres columnas.
- **C** · Solo tarjetas, sin opción de desplazamiento: deja sin salida a tablas comparativas.

**Recomendación: A.**

#### `OPEN-CORE-21` · Qué significa «columnas configurables» — **RESUELTA** (2026-09-30, decisión del usuario)

> **Decisión: opción A.** Visibilidad más «Restablecer columnas»; sin reordenar ni redimensionar (§13.8, `RN-CORE-43`, `CA-CORE-173`-`176`).

El plan no lo precisa y ningún requisito lo define.

- **A** · Visibilidad más «restablecer».
- **B** · A más orden, con botones subir/bajar (sin arrastre, WCAG 2.5.7).
- **C** · B más anchura, con control accesible por teclado.

**Recomendación: A.** Es lo único que cualquier consumidor conocido necesita, y el formato versionado de `RN-CORE-43` permite añadir el orden después sin romper nada.

#### `OPEN-CORE-22` · ¿Estado de la tabla en la URL? — **RESUELTA** (2026-09-30, decisión del usuario)

> **Decisión: opción A.** Página, `per_page`, orden, enumerados, booleanos y fechas en la URL; `q` nunca (`RN-CORE-54`, `CA-CORE-198`). `src/data-table` puede importar `vue-router` (`RN-CORE-38`). Convivencia de dos tablas en una vista y carácter opcional por tabla: los fija `ADR-054 §6.2` (opcional por tabla, como máximo una por ruta, `cursor` nunca en la URL).

Reflejar página, orden y filtros en la *query* de la ruta permite volver atrás, recargar y compartir un enlace filtrado. Pero la *query* queda en el historial del navegador y, en una recarga completa, llega al servidor y a sus registros de acceso. Un `q=López` es un dato personal ahí (el mismo motivo por el que `ADR-038 §6.5` quita la *query* de `instance`).

- **A** · Página, `per_page`, orden, enumerados, booleanos y fechas en la URL; **`q` nunca**.
- **B** · Nada en la URL: estado solo en memoria.
- **C** · Todo en la URL, incluido `q`. Desaconsejada.

**Recomendación: A.** Si se aprueba, `src/data-table` puede importar `vue-router` (`RN-CORE-38`).

#### `OPEN-CORE-23` · Contenido del fichero exportado — **RESUELTA** (2026-09-30, decisión del usuario)

> **Decisión: opción A.** Esquema fijo por recurso, definido en servidor y documentado en su OpenAPI (§13.14.2). Vincula al primer *endpoint* de exportación nuevo.

- **A** · Esquema fijo por recurso, definido en el servidor y documentado en su OpenAPI (como la auditoría).
- **B** · Las columnas visibles, enviadas como parámetro `columns=` con lista blanca.

**Recomendación: A** (§13.14.2).

#### `OPEN-CORE-24` · Dialecto CSV común y cuándo se implementa #270 — **RESUELTA** (2026-09-30, decisión del usuario)

> **Decisión: opción A** para el dialecto (coma, UTF-8 con BOM, CRLF, documentando en el manual la importación con separador en Excel), y #270 en una rama `fix/` propia tras `ADR-054` y antes del primer generador de CSV nuevo (§13.1.3, §13.14.3). Nada de esto se implementa en 1.9.

Excel en configuración regional española usa `;` como separador de lista (la coma es el separador decimal), y sin BOM no reconoce UTF-8. El usuario típico de secretaría abrirá el CSV con doble clic en Excel. `RNF-COMP-004` solo dice «CSV (UTF-8)».

- **A** · Coma, UTF-8 **con** BOM, CRLF: estándar RFC 4180; Excel detecta UTF-8, pero en regional española puede mostrarlo todo en una columna.
- **B** · Punto y coma, UTF-8 con BOM: se abre bien en Excel español, pero no es RFC 4180 y el centro alemán o inglés tendría el problema inverso.
- **C** · Separador según el idioma de quien exporta: resuelve los dos casos, pero el fichero depende de quién lo pidió.

**Recomendación: A**, documentando en el manual la importación con separador en Excel. Es la opción que no cambia lo que la auditoría ya entrega (salvo el BOM) y deja el formato estable para quien lo procesa por programa. **No hay dato de uso real** que la respalde (no hay centro piloto, H0): si el usuario tiene información de cómo abren los ficheros los centros objetivo, prevalece.

Sobre #270 (Baja): **recomendación** resolverlo en una rama `fix/` propia tras `ADR-054` y antes del primer generador nuevo (§13.1.3). Decide el usuario.

#### `OPEN-CORE-25` · Recuperar una exportación tras salir de la vista — **RESUELTA** (2026-09-30, decisión del usuario)

> **Decisión: opción A.** 1.9 avisa sin bloquear la navegación y acepta la pérdida del enlace (§13.14.4, `CA-CORE-202`); «Mis exportaciones» se reconsidera en `1.9b`. `ADR-054 §7.7` precisa la forma: aviso permanente junto al estado, sin diálogo de confirmación y sin `beforeunload`.

- **A** · 1.9 avisa antes de salir y acepta la pérdida del enlace. El listado «Mis exportaciones» (`GET /data-exports` sobre el índice ya existente de `datos.md` A.7) se añade cuando lo pida un consumidor.
- **B** · Añadir ahora `GET /data-exports` y una pantalla «Mis exportaciones». Es `apps/api` (`endpoint`, OpenAPI, test) más una pantalla: saca el paso de `apps/web`.

**Recomendación: A** en 1.9. Reconsiderar en 1.9b, que es cuando aparece el primer consumidor real.

#### `OPEN-CORE-26` · Modo `local` (colecciones sin paginar) — **RESUELTA** (2026-09-30, decisión del usuario)

> **Decisión: opción B.** No existe en 1.9; lo añade dentro de `src/data-table` el primer paso con consumidor real (candidato `1.5b`), sin tocar `ADR-054` (`ADR-054 §2.1`). `CA-CORE-199` retirado.

`GET /permissions` es deliberadamente no paginado. El listado de permisos efectivos de 1.5b quizá también (no verificado).

- **A** · Incluir el modo `local` en 1.9 (ordenación con `Intl.Collator`, filtrado de texto en cliente, sin paginación).
- **B** · Diferirlo a 1.5b, que lo añade dentro de `src/data-table` si lo necesita.

**Recomendación: B.** No hay consumidor en 1.9 ni en 1.9b, y construir un modo sin consumidor es exactamente lo que dejó el issue #261 abierto en 1.8.

#### `OPEN-CORE-27` · Representación de un valor vacío en una celda — **RESUELTA** (2026-09-30, decisión del usuario)

> **Decisión: opción A.** Clave común `dataTable.emptyValue`: marca «—» con texto alternativo traducido (§13.12, `CA-CORE-201`). 1.9 fija la convención para las tablas; #90 sigue abierto para el resto de vistas.

Depende de la convención pendiente del issue #90 (`'—'` literal en `SessionsView.vue`).

- **A** · Clave común `dataTable.emptyValue`, que se muestra como «—» con texto alternativo traducido para el lector de pantalla («Sin valor»). Resuelve #90 para las tablas y propone la convención para el resto.
- **B** · Celda vacía sin marca.
- **C** · Decidir primero #90 de forma general y aplicarlo aquí.

**Recomendación: A**, si el usuario acepta que 1.9 fije la convención que #90 dejó abierta.

#### `OPEN-CORE-28` · Migrar `MfaComplianceArea.vue` en 1.9 — **RESUELTA** (2026-09-30, decisión del usuario)

> **Decisión: opción A.** Se migra con paridad estricta y sin corregir #116 (§13.15, `CA-CORE-186`/`187`).

- **A** · Sí, con paridad estricta (§13.15), sin corregir #116.
- **B** · No: se migra cuando se toque `/administracion/mfa`.

**Recomendación: A.** Es el único consumidor real disponible para probar el componente contra una API de verdad, y sin migrarla `RN-CORE-37` necesitaría una excepción nominal desde el primer día.

#### `OPEN-CORE-29` · ¿Toda tabla pasa por el componente? — **RESUELTA** (2026-09-30, decisión del usuario)

> **Decisión: opción A.** Test de arquitectura con lista cerrada de excepciones (`RN-CORE-53`, `CA-CORE-200`). **Precisada por el usuario el mismo día**, al conocerse que existían cuatro tablas y no una (§13.2): 1.9 migra solo `MfaComplianceArea.vue`; `MfaExemptionsArea.vue`, `AdminSsoView.vue` y `SessionsView.vue` quedan como excepciones explícitas, la lista solo puede reducirse, y el test vigila también `@tanstack/vue-table` y los `<table` crudos. La redacción original («lista vacía en 1.9») queda sustituida.

- **A** · Test de arquitectura: fuera de `src/data-table/**`, nadie importa `@/components/ui/table`, salvo excepciones nominales (la matriz de 1.5b).
- **B** · Sin regla: el componente es la vía recomendada, pero una vista puede usar `components/ui/table` directamente para tablas estáticas pequeñas.

**Recomendación: A**, con la lista de excepciones cerrada en el test, mismo criterio que `docs/design-system.md §10`. Sin esa regla, dentro de veinte módulos habrá tablas con accesibilidad y estados propios, cada una distinta.

### 13.22 Decisiones que merecen `ADR-054`

`ADR-054` (`docs/adr/ADR-054-tablas-de-datos-y-exportacion-de-listados.md`) **no lo escribe esta especificación**: lo redactó `architect` el 2026-09-30 sobre las decisiones del usuario de esa fecha (§13.21), y **el usuario lo ratificó entero el mismo día (ACEPTADA)**. Son decisiones transversales que copiarán los 53 módulos, igual que `ADR-038` (API) y `ADR-053` (navegación). **Los siete puntos quedan resueltos en el ADR** y esta sección está ajustada a él; lo que el ADR añadía por su cuenta es firme con la ratificación:

1. **Envoltura de TanStack Table** (`RN-CORE-37`, `RN-CORE-38`, `RN-CORE-53`) → `ADR-054 §1`. Registra lo ya decidido. El alcance de `RN-CORE-53` (tres excepciones explícitas, lista que solo se reduce) lo fijó después el usuario en esta especificación (§13.3).
2. **Lectura de `ADR-038 §4.5`** → `ADR-054 §2.2`: **compatible**, confirmada. **Sin desplazamiento infinito** en todo el producto, y reglas del modo `cursor` de `§2.3` (`RN-CORE-56`, `CA-CORE-204`).
3. **Sin virtualización** → `ADR-054 §3`. Registra `OPEN-CORE-19` A; la constante `MAX_CURSOR_ROWS = 1000` no es configurable por tabla.
4. **Tablas en móvil** → `ADR-054 §4`. Registra `OPEN-CORE-20` A y fija el criterio de la excepción (comparación entre filas) (§13.9).
5. **Columnas y estado en URL** → `ADR-054 §5`-`§6`. `localStorage` confirmado; `tableId` literal `<modulo>.<nombre>` comprobado por escaneo de fuentes, sin «registro de tablas» (`CA-CORE-176`); estado en URL **opcional por tabla, como máximo una por ruta, sin `cursor`** (`RN-CORE-54`, `CA-CORE-198`, `CA-CORE-206`).
6. **Norma común de exportación y CSV** → `ADR-054 §7`, `§8`, `§10`. Valores de `RN-CORE-49` como se proponían; la solicitud **no lleva `sort`** (`CA-CORE-188`); paridad exacta de filtros entre exportación y listado (`api.md §13.4`); `RN-CORE-47` como **una clase sin interfaz**; `RN-CORE-48` con **tipos declarados por el generador** y la **segunda condición de neutralización** (primer carácter no blanco). Pregunta abierta: `OPEN-054-01` (§13.21).
7. **Texto libre en `data_exports.filters`** → `ADR-054 §9`: ningún *endpoint* de exportación acepta `q` (`422`, `RN-CORE-58`), y la SPA deshabilita exportar con búsqueda activa (`RN-CORE-57`, `CA-CORE-205`).

No merecen ADR (se deciden en esta especificación): la espera de búsqueda, las opciones de filas por página, el ciclo de ordenación, los estados, la semántica de accesibilidad y la migración de `MfaComplianceArea`.

### 13.23 ¿Se aprueba esta especificación?

**Sí, aprobada el 2026-09-30, ajustada a `ADR-054` el mismo día, y `ADR-054` ratificado entero por el usuario también el 2026-09-30 (ACEPTADA). Lista para `implementer`.** Decisiones del usuario del 2026-09-30:

1. `OPEN-CORE-19` (opción A): **sin virtualización**, tope de 1.000 filas en modo `cursor` (`RN-CORE-52`). Alcance del paso y línea de `PLAN-IMPLEMENTACION.md` actualizados.
2. `OPEN-CORE-20` (opción A): tarjetas por debajo de 768 px, con desplazamiento interno como opción por tabla (`RN-CORE-55`).
3. `OPEN-CORE-21` (opción A): visibilidad de columnas más «Restablecer».
4. `OPEN-CORE-22` a `OPEN-CORE-29`, con la opción recomendada: estado en URL sin `q` (A), esquema fijo por recurso (A), dialecto coma + BOM + CRLF e #270 en rama `fix/` propia (A), aviso sin «Mis exportaciones» (A), modo `local` diferido a `1.5b` (B), valor vacío común (A), migración de `MfaComplianceArea` (A) y toda tabla por el componente (A).
5. `ADR-054`: **ratificado entero**, sin cambios (ACEPTADA). Las marcas de ratificación pendiente se han retirado de §13 y de `datos.md` Parte C, `api.md §13`, `permisos.md §11` y `operacion.md §12`.
6. **Tablas existentes** (precisión de `OPEN-CORE-29`): 1.9 migra solo `MfaComplianceArea.vue`, con paridad estricta; `MfaExemptionsArea.vue`, `AdminSsoView.vue` y `SessionsView.vue` quedan como excepciones explícitas de `RN-CORE-53`, se migran en `1.9b` o posterior, y la lista solo puede reducirse (§13.2, §13.3, `CA-CORE-200`).
7. `OPEN-CORE-18` (opción A): la especificación se queda aquí.

`OPEN-054-01` (idioma del CSV) sigue abierta y **no bloquea 1.9**: bloquea `1.9b`.

**Punto que el usuario debe tener presente, sin bloquear 1.9**: con «la lista de excepciones solo se reduce», la matriz de concesión de `1.5b` ya no puede entrar como excepción nominal sin más; su especificación tendrá que proponer modificar `RN-CORE-53` y el usuario aprobarlo (§13.3, §13.15).

### 13.24 Notas de implementación (2026-09-30)

Implementado por `implementer` sobre esta especificación y `ADR-054`, sin cambiar alcance. Lo que sigue son datos de la implementación que la especificación pedía comprobar o que conviene que el revisor y `1.9b` conozcan.

**Comprobación de la dependencia TanStack** (`CLAUDE.md §1`; la dependencia ya estaba instalada, no se añadió ninguna). Consultado con `npm view` el 2026-09-30:

| Paquete | Versión instalada | Licencia | Último *release* de esa línea | Línea actual |
|---------|-------------------|----------|-------------------------------|--------------|
| `@tanstack/vue-table` | `8.21.3` (`^8.21.3`) | MIT | `8.21.3`, **2025-04-14** (no hay ninguno posterior en 8.x) | `9.2.4` (estable desde `9.0.0`, 2026-08-04; último *release* 2026-08-28) |
| `@tanstack/table-core` (transitiva) | `8.21.3` | MIT | ídem | — |

Mantenimiento: el proyecto está activo (tres mantenedores en el registro, línea `9.x` publicada hace semanas), **pero la línea 8 lleva más de 17 meses sin un solo *release***. Eso no invalida 1.9: `8.21.3` no está marcada como obsoleta (`deprecated` vacío), no hay aviso de seguridad conocido y el paquete se usa solo en el lado cliente de una SPA. Sí es un hallazgo que el usuario debe conocer: **la línea que sigue recibiendo mejoras es la 9, con API distinta**, y `ADR-054 §1`/`RN-CORE-37` ya prevé el coste de cambiar de versión (un único directorio). No se hace la migración a 9 en este paso (sería ampliar el alcance). En el código, TanStack se toca en un solo fichero, `src/data-table/useTableModel.ts`.

**Estructura entregada** (`apps/web/src/data-table/`): `index.ts` (superficie pública), `types.ts` (contrato de columnas y de la función de petición), `constants.ts` (`MAX_CURSOR_ROWS`, `SEARCH_DEBOUNCE_MS`, `EXPORT_POLL_*`…), `useTableModel.ts` (TanStack), `useDataTableController.ts` (consulta, carga, última respuesta gana, estado en la URL), `useExportFlow.ts` (exportación y consulta de estado), `columnPreferences.ts` (`localStorage`), `urlState.ts`/`filterState.ts` (funciones puras), `formatters.ts`, `useNarrowViewport.ts` y `components/` (`DataTable`, `DataTableToolbar`, `DataTableGrid`, `DataTablePagination`, `DataTableExportStatus`, `DataTableEmptyValue`, `DataTableCellContent`). Tests: `DataTable.spec.ts`, `.columns.spec.ts`, `.export.spec.ts`, `.url.spec.ts`, `urlState.spec.ts`, `i18n.spec.ts`, `architecture.spec.ts` (Vitest); `e2e/data-table.spec.ts` con `e2e/fixtures/tableFixture.ts` (Playwright).

**Decisiones de implementación dentro de la especificación** (ninguna cambia un criterio):

1. **Tabla de prueba en Playwright.** Los criterios `[Playwright]` (`CA-CORE-177`, `-178`, `-185`, `-203`) usan una tabla de prueba que `e2e/fixtures/tableFixture.ts` monta en la página: el servidor de desarrollo de Vite sirve el fichero y transforma sus importaciones, así que se prueba en un navegador real sin añadir ninguna ruta de prueba a la aplicación. `CA-CORE-203` necesita una tabla con desplazamiento interno y ningún consumidor real la declara.
2. **Excepciones de `CA-CORE-192`.** La especificación las admite «nominales y justificadas en el propio test», y la búsqueda encontró dos usos de `new Blob(`/`URL.createObjectURL(` en `modules/auth` que no son exportaciones de listados: `AdminSsoProviderView.vue` (descarga del XML de metadatos SAML que el servidor ya devolvió) y `RecoveryCodesReveal.vue` (los códigos de recuperación recién generados, mostrados una sola vez). Quedan como excepciones de esos dos tokens solo en esos dos ficheros, cada una con su justificación y con un test que exige retirarla si el fichero deja de usarlo. `text/csv` y `openxmlformats` no tienen ninguna.
3. **Ningún componente base nuevo** (`CA-CORE-197` por vacuidad): el `dropdown-menu` de 1.8 cubre los menús de casillas y de opción única (`docs/design-system.md §12.3b`).
4. **Importaciones de `src/data-table/**`.** Además de los destinos de `§13.3`, importa los tres componentes de estado de `§12.6` (`@/layouts/components/{EmptyState,ErrorState,LoadingState}.vue`) y `@/layouts/errorState` — los «componentes de estado de §12.6» que `RN-CORE-38` permite, nombrados uno a uno en el test (`CA-CORE-194`). Para el idioma activo usa `i18n.global.locale` de `@/i18n` (no importa `vue-i18n`).
5. **`per_page` en `MfaComplianceArea`.** La tabla migrada envía siempre `per_page` (25 por defecto, `ADR-038 §4.3`); antes la petición lo omitía. Es el mismo valor que aplica el servidor, así que el resultado no cambia (paridad de `OPEN-CORE-28`).
6. **Claves nuevas de `REQ-AUTH`.** `auth.mfaAdmin.compliance.tableCaption` y `.resetActionFor` («Restablecer MFA de {name}», `CA-CORE-186`), en los cuatro idiomas. Las claves `previousPage`/`nextPage`/`pageIndicator` de esa misma sección se conservan: siguen en uso por `MfaExemptionsArea.vue`.
7. **`tableId` y escaneo** (`CA-CORE-176`): el test recorre `src/modules/**` buscando `table-id="…"`, `:table-id="'…'"` y `tableId: …`; cualquier forma no literal falla. La tabla migrada declara `auth.mfa_compliance`.
8. **Foco.** El paginador lleva el foco al control habilitado más cercano cuando el pulsado queda deshabilitado; «Cargar más» lo deja en el botón y, al desaparecer (tope o final de la lista), lo pasa al aviso; «Limpiar filtros» lo lleva a la búsqueda (`§13.11`).

**Verificación** (contra el contenedor de referencia `plataforma-educativa_web`, con el *worktree* montado; Playwright con el Chromium del *host* sobre un servidor de Vite propio): ver `CHANGELOG.md` y `memory.md` para el recuento final.
