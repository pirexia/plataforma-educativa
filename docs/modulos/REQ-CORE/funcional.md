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
> **Paso 1.9 (tablas de datos: TanStack Table, filtrado, ordenación, columnas configurables y exportación; sin virtualización, `OPEN-CORE-19`): §13, APROBADA** (2026-09-30), **ajustada a `ADR-054`, ratificado entero por el usuario el 2026-09-30 (ACEPTADA)**; `OPEN-054-01` resuelta el 2026-10-01 por `ADR-055` (ACEPTADA). Lista para `implementer`. Ubicación definitiva: aquí (`OPEN-CORE-18`, resuelta por el usuario el 2026-09-30). §0-§12 no se reabren.
>
> **Paso 1.9b (pantallas de gestión pendientes de `REQ-CORE-002`/`-003`/`-004`/`-005` y migración de las tres tablas exceptuadas de `RN-CORE-53`): §14, APROBADA** (2026-10-01, decisión del usuario). Dividido en cinco sub-pasos `1.9b`-`1.9f` (`OPEN-CORE-30`, §14.2). Resueltas `OPEN-CORE-30`, `-31`, `-32`, `-39`, `-40`, `-42`, `-43`; `-33` a `-36` **resueltas el 2026-10-03** (C, B, A, A; §14.17); `-37` y `-45` **resueltas el 2026-10-03** (B, A); el estado de las demás, en §14.17 y bloquean solo el sub-paso que indica §14.17. §0-§13 no se reabren: §14 **precisa** dos puntos de §13 (§14.12) y **amplía** la lista cerrada de `RN-CORE-24` de seis a siete rutas como consecuencia de `OPEN-CORE-31` (§14.3.1), y lo dice donde lo hace. **Sub-paso 1.9f implementado el 2026-10-04** (`OPEN-CORE-54`/`-55`/`-56` resueltas = A, A, A): con él se cierra la serie 1.9b-1.9f y la lista de excepciones de `RN-CORE-53` queda vacía.

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
5. **Neutralización de fórmulas (`RN-CORE-36`, issue #268; ampliada y movida a `CsvWriter` por `RN-CORE-47`/`48`, issue #270).** Toda celda de texto del CSV cuyo primer carácter sea `=`, `+`, `-`, `@`, tabulador, retorno de carro o salto de línea se escribe con un apóstrofo delante, para que Excel/LibreOffice/Sheets no la interpreten como fórmula activa (inyección de fórmulas CSV, OWASP). Aplica a la cabecera y a todas las filas.

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

Nota (#300, `CA-CORE-270`): la recarga de sesión que dispara un `403` **no** pone la sesión en «cargando» si ya estaba `ready` (la vista conserva su estado y no repite la petición); el estado de carga a pantalla completa es solo del arranque y de la recuperación desde cualquier estado distinto de `ready`. Si la recarga falla (red, `5xx`, `429`), la sesión pasa a `error` (estado de error a pantalla completa, §12.3.1) y la vista se desmonta.

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
| `RN-CORE-24` | `meta.permissions` vacía (`[]` explícito) en una ruta `app`/`bare` significa «cualquier usuario autenticado» y **solo** se admite en rutas sin ningún permiso real que exigir sin inventarlo: las cuatro de autoservicio por identidad (`permisos.md §5.2`: Inicio, Contraseña, Sesiones, Seguridad de la cuenta), el muro de MFA (`mfa-enrollment-wall`, régimen `bare`: se alcanza precisamente cuando `GET /me` ya ha fallado con `403`, no hay ningún permiso previo que comprobar) y la página «no encontrada» (*catch-all*: «no encontrado» es igual para cualquiera, con o sin permisos). Lista cerrada de **seis** rutas en un test (`ADR-053 §2`, comprobación 5) — issue [#260](https://github.com/pirexia/plataforma-educativa/issues/260), corregido aquí: la redacción original solo citaba cuatro. **Ampliada a siete en `1.9e`** con `core-profile` (perfil propio, autoservicio por identidad), por decisión del usuario (`OPEN-CORE-31` = B, §14.3.1) |
| `RN-CORE-25` | El registro solo contiene entradas cuya ruta existe en el *router*. Ninguna entrada «próximamente» para módulos no implementados |
| `RN-CORE-26` | Estado de sesión en memoria, recargado según §12.3.4. Ninguna vista vuelve a pedir `/me` para comprobar la sesión: lo hace el *guard* (se retira el `getMe()` de comprobación de `SessionsView` y análogas). La recarga con la sesión ya `ready` no pasa por «cargando» (nota de §12.6, `CA-CORE-270`) |
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
- **`CA-CORE-270`** [`RN-CORE-26`, `CA-CORE-098`, #300] · **Dado** un usuario con la sesión `ready`, **cuando** una vista dentro del *shell* recibe un `403` persistente de un recurso, **entonces** se hace una sola petición al recurso, una sola recarga de `/me`, la vista no se desmonta y se pinta «sin acceso» dentro del *shell*, sin bucle.
- **`CA-CORE-271`** [`RN-CORE-26`, `CA-CORE-270`, #302] · **Dado** un usuario con la sesión `ready`, **cuando** un `403` dispara la recarga de `/me` y esta responde `401`, **entonces** la sesión pasa a anónima y la SPA navega a `/entrar?redirect=<ruta actual>` (mismo manejo que cualquier `401`), sin repetir el recurso en bucle.
- **`CA-CORE-272`** [`RN-CORE-26`, `CA-CORE-270`, #303] · **Dado** un usuario con la sesión `ready`, **cuando** la recarga de `/me` tras un `403` devuelve otra identidad (`public_id`) u otro conjunto de permisos, **entonces** la vista se remonta (no conserva datos del usuario anterior) o, si la ruta ya no está permitida, se pinta «sin acceso»; con identidad y permisos idénticos no se remonta.

#### Navegación y permisos

- **`CA-CORE-100`** [`REQ-CORE-008`, `RPERM-011`] · **Dado** un usuario cuyo `/me.permissions` está vacío, **cuando** carga el *shell*, **entonces** la navegación contiene exactamente Inicio y las tres entradas de «Mi cuenta»; **y dado** uno con `proveedor_identidad.leer`, aparece además «SSO».
- **`CA-CORE-101`** [`REQ-CORE-008` criterio 2, `RMOD-008`] · **Dado** un registro con una entrada de prueba de un módulo `fixture` que exige `fixture.leer`, **y** un `/me` sin ese permiso (como lo devuelve el servidor cuando el módulo está descontratado: permiso inerte, `REQ-PERM/api.md §7.2`), **cuando** se carga el panel, **entonces** el texto de esa entrada no aparece en ningún lugar del documento (menú, accesos directos, *breadcrumb*).
- **`CA-CORE-102`** [`RN-CORE-23`] · **Dado** `src/` salvo tests, **entonces** ningún fichero contiene como literal ninguno de los 16 códigos de rol predefinidos ni accede a `roles[…].code`/`.code` de un rol para decidir; con casos fijos en el test que prueban que detecta `'administrador_centro'` y `roles.some(r => r.code === …)`.
- **`CA-CORE-103`** [`RN-CORE-24`, `RN-CORE-25`, `ADR-053 §2`] · **Dado** el registro de navegación ensamblado, **entonces** toda entrada apunta a un nombre de ruta registrado en el *router* con `meta.layout === 'app'`, y las únicas rutas `app`/`bare` con `meta.permissions` vacía (`[]` explícito) son las de Inicio, Contraseña, Sesiones, Seguridad, el muro de MFA (`mfa-enrollment-wall`, `bare`) y el *catch-all* — **seis**, no cuatro (`RN-CORE-24`). *(Desde `1.9e`, siete: se añade `core-profile`, §14.3.1, `CA-CORE-264`.)*
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
| Estado | **APROBADA** (2026-09-30, decisión del usuario), **ajustada a `ADR-054`, ratificado entero por el usuario el 2026-09-30 (ACEPTADA)**. `OPEN-CORE-18` a `OPEN-CORE-29` resueltas por el usuario (§13.21), las de `-19` a `-29` registradas en el ADR. Las precisiones y correcciones que `ADR-054` añade (§2.3, §5.3, §6.2, §8.1-§8.3, §9, §10.1, §10.2 del ADR) están incorporadas y **son firmes**. `OPEN-054-01` (idioma del CSV) sigue abierta: bloquea `1.9b`, no `1.9`. **Implementada** (2026-09-30, rama `feature/REQ-CORE-008-tablas-de-datos`, notas en §13.24); revisión independiente hecha (sin Crítico/Alto) y mezclado. Antes: «Lista para `implementer`» (§13.23) |

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
- *(Estado de partida; resuelto en #270, donde `neutralizeCsvCell` ya no existe.)* **Su implementación es código de `apps/api`**: extraer `neutralizeCsvCell` de `GenerateAuditLogExport`, reescribir los tests que hoy usan `ReflectionMethod` y neutralizar `report.csv`. Meterlo en 1.9 mezclaría en un paso de `apps/web` un cambio de servidor sin consumidor nuevo: 1.9 no añade ningún generador de CSV.
- **Decidido** (`OPEN-CORE-24`, decisión del usuario 2026-09-30): #270 se resuelve en una rama `fix/REQ-CORE-005-...` propia, **después** de la ratificación de `ADR-054` y **antes** del primer paso que añada un segundo generador de CSV. Esa rama aplica también el dialecto común de §13.14.3. No forma parte de 1.9. **Implementado** en la rama `fix/REQ-CORE-005-auditoria-csv-rangos-y-filtros`: `App\Support\Csv\CsvWriter` (+ `CsvColumnType`), migración de `GenerateAuditLogExport` y `ValidateUserImport::writeReport`, y test de arquitectura `tests/Unit/Csv/CsvArchitectureTest.php` (`RN-CORE-47`/`48`, #270).

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
- *(Estado de partida; `neutralizeCsvCell` ya no existe, resuelto en #270.)* **Exportación existente**: solo auditoría. `POST /audit-logs/exports` → `202` → `GET /data-exports/{id}` (estado `pendiente`/`generando`/`completada`/`fallida`, `download_url` firmada, `409` si aún no está lista, `410` si venció, solo el solicitante descarga). La generación la hace `GenerateAuditLogExport` en la cola `core-exports`, con `RN-CORE-36`. Su `neutralizeCsvCell` es privado, acepta `int|float` (`-5` pasa a `'-5`) y no cubre espacios iniciales (#270).
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

  > **Nota de 1.5b (2026-10-05, modificación APROBADA expresamente por el usuario, `REQ-PERM/funcional.md §20.12`, `OPEN-PERM-08` = A)**: se añade a `RN-CORE-53` un párrafo y una **segunda lista**, distinta de la de excepciones de tablas de datos. «**Rejillas de edición.** Una rejilla de edición es una vista cuyas celdas son **controles de formulario** que editan un único recurso (no un listado de filas que se leen), y cuyo propósito es comparar ese recurso en dos ejes a la vez. Las rejillas de edición pueden importar `@/components/ui/table`, y solo ellas, si figuran en una **lista cerrada de rejillas de edición** escrita en el propio test, separada de la lista de excepciones de tablas de datos. Cada entrada nombra el fichero, la especificación que la justifica y la aprobación del usuario. **La lista de rejillas solo crece con una especificación aprobada expresamente por el usuario que la nombre**; ninguna sesión de implementación la amplía. La lista de excepciones de tablas de datos sigue vacía y sigue sin poder crecer. Una rejilla de edición no puede contener `<table` crudo ni importar `@tanstack/vue-table` (`RN-CORE-37` no tiene excepciones), y cumple `RN-CORE-44` salvo `rowHeader` único, con `th scope="row"` por recurso y `th scope="col"` por acción.» **Lista inicial**: `src/modules/core/components/roles/RolePermissionMatrix.vue`. `ADR-054 §1.4` no se edita (es inmutable): la precisión se registra aquí. Lo anterior de este apartado describe la lista de excepciones de tablas de datos y sigue vigente para ella.

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

> **Nota de 1.5b (2026-10-05, `REQ-PERM/funcional.md §20.11`, `RN-PERM-43`/`-44`)**: con `1.5b` los modos son **tres**. El modo **`local`** existe desde ese paso (`OPEN-CORE-26` = B; `ADR-054 §2.1`: aditivo, sin tocar el ADR): solo para colecciones documentadas como no paginadas o campos de un recurso, pidiéndolas enteras **una vez**; paginación, orden, filtros (`enum`, `boolean`) y búsqueda en cliente, sin exportación (`RN-CORE-46`). Lo anterior de este apartado («dos modos en 1.9, y solo dos») describe 1.9 y queda precisado, no derogado: `page` y `cursor` no cambian.

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

`OPEN-CORE-23` resuelta (opción A, decisión del usuario 2026-09-30): **esquema fijo por recurso, definido en servidor** y documentado en su OpenAPI, como hace hoy la auditoría, y no «las columnas visibles». El esquema fija las columnas, sus nombres, su orden **y el orden de las filas** (`ADR-054 §8.1`). La solicitud de exportación **no** envía la configuración de columnas del navegador **ni `sort`**. Idioma de la cabecera y de los valores enumerados del fichero: **resuelto**, contrato técnico sin traducir (`OPEN-054-01`, `ADR-055`, `RN-CORE-59`, §13.21). El fichero es un contrato estable para quien lo procesa después (una hoja de cálculo de secretaría, una importación en otro sistema). Que dependa de qué columnas ocultó cada usuario en su navegador lo hace irreproducible e inauditable (`data_exports.filters` no lo registraría).

#### 13.14.3 Regla común de CSV (norma para todo generador, `ADR-054`)

Aplica a `apps/api`. La fija `ADR-054 §9`-`§10` para cumplir lo que `SECURITY.md` y el issue #270 remiten a 1.9; aquí se recoge, y **se implementa fuera de 1.9** (§13.1.3):

- **`RN-CORE-47`** · **Una sola vía de escritura CSV** (`ADR-054 §10.1`). Todo generador de CSV del producto (exportaciones de listados, informes de errores de importación como `report.csv`, y los futuros) escribe con **una clase única** en `apps/api/app/Support/Csv/` (infraestructura compartida, como `App\Support\Audit`, para que cualquier módulo la use sin importar código interno de `Core`, `INV-007`; el nombre concreto lo elige quien la implemente). **Sin par interfaz + implementación**: `RNF-MANT-007` obliga a envolver **dependencias externas**, y `fputcsv` es PHP; no hay segunda implementación previsible y la propia clase ya es el punto único. **Test de arquitectura**, que entra con la clase: ninguna llamada a `fputcsv` ni a `SplFileObject::fputcsv` fuera de ella.
- **`RN-CORE-48`** · **Celdas tipadas y neutralización solo sobre texto** (`ADR-054 §10.2`, amplía `RN-CORE-36`).
  - **Cada generador declara el tipo de cada columna en su esquema** (§13.14.2), y la clase escribe según ese tipo. **La clase nunca deduce el tipo del contenido**: si lo dedujera, una cadena `"-5"` y un entero `-5` se tratarían igual según cómo llegaran. Tipos admitidos: texto, entero, fecha/instante y nulo. Los enteros (céntimos de `ADR-029` incluidos) se escriben sin apóstrofo (`-5` sigue siendo `-5`); los instantes, en ISO 8601 con desfase. Sin coma flotante: un decimal (una nota) lo formatea el generador como texto. Añadir un tipo decimal cuando haga falta es aditivo.
  - **La neutralización recibe solo cadenas** y se aplica a toda celda de texto y a la cabecera. Se antepone un apóstrofo si se cumple cualquiera de estas dos condiciones: (1) el primer carácter es `=`, `+`, `-`, `@`, tabulador, retorno de carro o salto de línea (`RN-CORE-36`, vigente); (2) **el primer carácter que no es espacio en blanco** (espacios Unicode incluidos) es `=`, `+`, `-` o `@`. Expresión única: `/^(?:[=+\-@\t\r\n]|[\s\p{Z}]+[=+\-@])/u`.
  - Sobre #270 (neutralizar todo valor que empiece por cualquier espacio en blanco): se adopta **la protección, no la regla**. La condición 2 cubre `" =1+1"` sin que `" Juan"` gane un apóstrofo inútil. Es defensa en profundidad, **no verificada** contra versiones concretas de Excel, LibreOffice o Sheets. Riesgo residual aceptado: signos de ancho completo (`＝`) y otras variantes Unicode.
- **Dialecto CSV** (`OPEN-CORE-24`, opción A, decisión del usuario 2026-09-30; detalle en `ADR-054 §10.3`): **coma como separador, UTF-8 con BOM, fin de línea CRLF, comillas dobles de RFC 4180 (una comilla dentro del campo se duplica) y sin carácter de escape** (`escape: ''` en PHP), cabecera siempre presente y sin línea `sep=`. Antes la auditoría se escribía con `fputcsv` por defecto (coma, sin BOM, `\n`, escape `\`); `CsvWriter` ya aplica este dialecto (rama `fix/REQ-CORE-005-auditoria-csv-rangos-y-filtros`, #270), el cambio consta en `CHANGELOG.md` y `docs/manual-usuario/admin.md` documenta cómo abrir el fichero en Excel con configuración regional española (importación indicando el separador). No hay dato de uso real que respalde la elección (sin centro piloto, H0); si aparece, se revisa.
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
| Usuarios (`GET /users`: `status`, `role`, `q`) | 1.9b | `page` | Filtros enumerado + texto, orden, columnas, tarjetas, exportación | **Exportación sin *endpoint***: `POST /users/exports` no existe. Si 1.9b lo quiere, es trabajo de `apps/api` con `RN-CORE-47`/`48`, paridad de filtros con `GET /users` salvo `q` y `RN-CORE-58` (`q` ⇒ `422`); en la tabla, exportar queda deshabilitado con búsqueda activa (`RN-CORE-57`). Cabecera y valores técnicos sin traducir (`ADR-055`, `RN-CORE-59`; `OPEN-054-01` resuelta) |
| Invitaciones, importaciones | 1.9b | `page` | Filtros, orden, estados | — |
| Roles (solo lectura en 1.1; CRUD desde 1.5) | 1.9b / 1.5b | `page` | Listado | — |
| Auditoría (`GET /audit-logs`) | 1.9b | `cursor` | Filtros de rango, enumerados, «cargar más», tope (`RN-CORE-52`), **exportación ya existente** | Primer consumidor real de la exportación y del modo `cursor`. **Antes de conectarlo**, `1.9b` resuelve los dos hallazgos de §13.20 (puntos 5 y 6): nombres del rango `from`/`to` frente a `occurred_at_from`/`occurred_at_to`, y paridad de filtros de `POST /audit-logs/exports` con `GET /audit-logs` |
| Permisos efectivos de un usuario (`GET /users/{id}/effective-permissions`) | 1.5b | `local` o `page` según su contrato | Listado con procedencia | El implementador de 1.5b comprueba la paginación en `REQ-PERM/api.md`. Si no está paginado, 1.5b, como primer consumidor real, añade el modo `local` dentro de `src/data-table` (`OPEN-CORE-26`, opción B; `ADR-054 §2.1`) |
| **Matriz de concesión** (recurso × acción × ámbito) | 1.5b | — | **No es una tabla de datos** en el sentido de este paso: es una rejilla de edición con celdas que son controles | Se preveía construirla a medida en 1.5b sobre `components/ui/table` como excepción nominal de `RN-CORE-53`. Con la regla vigente (la lista de excepciones solo se reduce), **1.5b tiene que proponer la modificación de `RN-CORE-53` y el usuario aprobarla** antes de construirla así. **Implementada en 1.5b** (`REQ-PERM/funcional.md §20.8`/`§20.12`): `RolePermissionMatrix.vue`, rejilla de edición aprobada el 2026-10-05, segunda lista de `RN-CORE-53` |
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
| `RN-CORE-59` | CSV de datos: cabeceras = `snake_case` del campo de la API, valores enumerados = código técnico sin traducir, formatos independientes del idioma; el generador no llama a ningún catálogo de traducción (`ADR-055 §2`) |

**Issues relacionados con estas reglas (#266, #267 y #273 resueltos en la rama `fix/REQ-CORE-005-auditoria-csv-rangos-y-filtros`; los textos de abajo describen el estado de partida de 1.9)** (detalle en §13.20): [#266](https://github.com/pirexia/plataforma-educativa/issues/266) (nombres del rango de fechas de auditoría, contra `ADR-038 §5.2`; afecta a la regla «`id` de columna = parámetro» de §13.4 en la pantalla de auditoría de `1.9b`), [#267](https://github.com/pirexia/plataforma-educativa/issues/267) (`POST /audit-logs/exports` sin paridad de filtros con su listado, `ADR-054 §8.2`, `api.md §13.4`) y [#273](https://github.com/pirexia/plataforma-educativa/issues/273) (`QUEUE_CONNECTION` en `operacion.md §2`, que condiciona el diagnóstico de `RN-CORE-49`). Ninguno bloquea 1.9; los dos primeros se resuelven antes de que `1.9b` conecte la pantalla de auditoría.

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
  > **Nota de 1.5b (2026-10-05)**: `CA-CORE-200` se reescribe para comprobar **dos listas** (`REQ-PERM/funcional.md §20.12`, `CA-PERM-129`): la de excepciones de tablas de datos (subconjunto de las tres rutas originales; **vacía** desde 1.9f, `CA-CORE-255`) y la de rejillas de edición (igual a la constante aprobada, `RolePermissionMatrix.vue`), con casos fijos que prueban que una rejilla no listada que importa `@/components/ui/table` falla, que añadir una segunda entrada sin cambiar la constante falla y que una entrada que ya no importa la tabla base falla. El test vigente está en `apps/web/src/data-table/architecture.spec.ts`.
- **`CA-CORE-201`** [`OPEN-CORE-27`, `INV-009`, issue #90] · **Dado** una fila de prueba con un campo nulo, **cuando** se pinta en tabla y en tarjeta, **entonces** la celda y el `dd` contienen la marca visual con `aria-hidden="true"` y un texto solo para lector de pantalla igual a la traducción de `dataTable.emptyValue` en el idioma activo (comprobado en `es`, `en`, `de` y `fr`); **y** ni `src/data-table/**` ni `MfaComplianceArea.vue` contienen `'—'` como literal.

#### Estado en la URL (`RN-CORE-54`, `OPEN-CORE-22`)

- **`CA-CORE-198`** [`RN-CORE-54`, `ADR-054 §6`] · **Dado** una tabla de prueba que declara el estado en URL, con página 3, `sort=-created_at` y `status=activo`, **cuando** se recarga el documento, **entonces** se restaura la misma consulta desde la URL; **y** el texto de búsqueda `q` no aparece nunca en la URL ni en `history.state`; **y dado** una tabla de prueba en modo `cursor` que declara el estado en URL, tras «cargar más» la URL no contiene `cursor` y, al recargar, la primera petición no lleva `cursor`; **y dado** una tabla de prueba que **no** declara el estado en URL, ni paginar, ni ordenar, ni filtrar modifican la URL de la ruta.
- **`CA-CORE-206`** [`RN-CORE-54`, `OPEN-CORE-28`] · **Dado** `MfaComplianceArea` migrada, **cuando** se marcan filtros y se pagina, **entonces** la URL de `/administracion/mfa` no cambia (la tabla no declara estado en URL, paridad estricta).
- **`CA-CORE-207`** [`RN-CORE-59`, `ADR-055`] · **Dado** un generador de CSV de datos y dos solicitantes con idioma distinto, **cuando** ambos exportan el mismo conjunto, **entonces** los dos ficheros son idénticos byte a byte salvo el nombre, y la cabecera coincide con el esquema documentado en OpenAPI; ningún generador de `apps/api/app` referencia `__()`/`trans()` para cabeceras ni valores. _(Verificación pendiente: se implementa con el primer generador nuevo de `1.9b`; hoy solo existe el de auditoría.)_

#### Retirado

- *Identificadores `CA-CORE-153` a `-159` no usados (reservados, sin criterio asociado); la serie de 1.9 empieza en 160.*
- ~~**`CA-CORE-199`**~~ · **Retirado** el 2026-09-30: el modo `local` no existe en 1.9 (`OPEN-CORE-26`, opción B; `ADR-054 §2.1`). El identificador queda reservado y no se reutiliza; el paso que añada el modo escribe sus propios criterios.

### 13.19 Documentación a actualizar al cerrar 1.9

- Este documento: estado de §13 (implementada). La ubicación ya es definitiva (`OPEN-CORE-18`, resuelta).
- `docs/adr/ADR-054-tablas-de-datos-y-exportacion-de-listados.md`: ACEPTADA (ratificado por el usuario el 2026-09-30; la edición del ADR no corresponde a esta especificación). El índice de la sección 18 del documento de requisitos y su historial de versiones deben reflejarlo (hallazgo 6 del ADR); lo confirma `doc-reviewer`.
- `docs/design-system.md §12`: componentes vendorizados nuevos, si los hay (`checkbox`, `popover`…).
- `docs/i18n.md`: espacio de nombres `dataTable.*`.
- `PRIVACY.md §2.1b`: clave `plataforma.table.<tableId>` (`RN-CORE-43`).
- *(Hecho: `SECURITY.md` 0.3.7 ya está corregido.)* `SECURITY.md`, fila «Exportaciones generadas (CSV)»: añadir el **salto de línea (LF)** a la lista de caracteres, que hoy omite aunque `RN-CORE-36` y el código lo incluyen (hallazgo 3 de `ADR-054`), y sustituir «se fijará como norma común en el ADR… cuando exista» por la referencia a `ADR-054 §9`-`§10`, a la segunda condición de neutralización (primer carácter no blanco) y a `RN-CORE-46`-`48`/`58`.
- `ARCHITECTURE.md` (frontend: `src/data-table/`, importación única de TanStack), `CHANGELOG.md`.
- `docs/manual-usuario/admin.md`: cómo filtrar, ordenar, configurar columnas y exportar (sección común para los manuales que existan, issue #65).
- `PLAN-IMPLEMENTACION.md`: la línea de 1.9 **ya se actualizó** al aprobar la especificación (2026-09-30, sin virtualización, `OPEN-CORE-19`); al cerrar, se marca el paso como terminado.

### 13.20 Hallazgos fuera del ámbito de esta especificación

No se corrigen aquí; se reportan:

1. **`operacion.md §2` decía `QUEUE_CONNECTION = redis`**, mientras `CLAUDE.md §1` y `apps/api/config/queue.php` dicen `database`, sin *worker* desplegado (#128). Issue [#273](https://github.com/pirexia/plataforma-educativa/issues/273) (Media, detectado por `doc-reviewer`): **`operacion.md §2` ya está corregido** en la rama `feature/1.9-tablas-de-datos`. El resto en `operacion.md §3` y §8 (fila «Redis» y síntoma «Importación queda en `subido`») **se corrigió después** en la rama `fix/REQ-CORE-005-auditoria-csv-rangos-y-filtros` (#273, cerrado).
2. **`data_exports.filters` con política `Full`** copiaría en `audit_logs` cualquier filtro de texto libre con un dato personal (§13.14.3, último punto). No ocurre hoy (la exportación de auditoría no acepta `q`). **Resuelto como norma por `ADR-054 §9`** (`RN-CORE-58`), ratificado.
3. **`MfaComplianceArea.vue` usa `'—'` literal** (issue #90) e importa `useI18n` de `vue-i18n` directamente para obtener `locale` (patrón que issue #259 señala en otras vistas). Se resuelve en esa vista con la migración (`OPEN-CORE-28`, opción A) y el valor vacío común (`OPEN-CORE-27`, opción A); #90 y #259 siguen abiertos para las demás vistas.
4. **`SECURITY.md` promete para 1.9 una norma común de CSV** que es de servidor, en un paso que el plan y el encargo sitúan en `apps/web`. §13.1.3 lo resuelve separando decisión (`ADR-054 §10`) e implementación (#270).
5. **Nombres del rango de fechas de auditoría** (hallazgo 1 de `ADR-054`, severidad Media, issue [#266](https://github.com/pirexia/plataforma-educativa/issues/266)): `GET /audit-logs` y `POST /audit-logs/exports` usan `from`/`to` (código y `api.md` de este módulo), no `occurred_at_from`/`occurred_at_to` como exige `ADR-038 §5.2`. Con la regla «`id` de columna = parámetro» (`ADR-038 §13.3`), la pantalla de auditoría de `1.9b` enviaría un rango que el servidor ignora en silencio. No afecta a 1.9 (no conecta esa pantalla). Propuesta del ADR: en `1.9b`, antes de la pantalla, aceptar además los nombres conformes y retirar los antiguos por *expand/contract*. **Resuelto** en la rama `fix/REQ-CORE-005-auditoria-csv-rangos-y-filtros` (#266) con un renombrado directo, sin periodo de compatibilidad, porque no hay producción (`H0` abierto) y el único consumidor es la SPA.
6. **`POST /audit-logs/exports` sin paridad de filtros con su listado** (hallazgo 2 de `ADR-054`, severidad Media, issue [#267](https://github.com/pirexia/plataforma-educativa/issues/267)): le faltan `actor_id`, `actor_type`, `auditable_id` y `module`, y por `ADR-038 §5.2` no da error; el fichero no corresponde a lo que el usuario ve filtrado (el ámbito del permiso sí se sigue aplicando en el trabajo). Incumple `ADR-054 §8.2`. Se corrige antes de que `1.9b` conecte el botón de exportar de auditoría. **Resuelto** en la rama `fix/REQ-CORE-005-auditoria-csv-rangos-y-filtros` (#267).
7. **Tres tablas existentes fuera del componente** (`MfaExemptionsArea.vue`, `AdminSsoView.vue`, `SessionsView.vue`, §13.2): no se migran en 1.9 por decisión del usuario (2026-09-30) y quedan como excepciones explícitas de `RN-CORE-53`. Su migración es trabajo de `1.9b` o de un paso posterior; `PLAN-IMPLEMENTACION.md` debería recogerlo en la línea de `1.9b` (fuera del ámbito de escritura de esta especificación).

### 13.21 Preguntas abiertas del paso 1.9

**Decididas por el usuario el 2026-09-30** (`OPEN-CORE-18` a `OPEN-CORE-29`), todas con la opción recomendada salvo donde se indica; las de `-19` a `-29` están **registradas en `ADR-054`** (ACEPTADA), que remite aquí. `ADR-054` añade una pregunta nueva, **`OPEN-054-01`**, abierta: **no bloquea 1.9 y bloquea `1.9b`**. Se conservan las opciones y su argumento, no se borran, para que una revisión futura pueda leer por qué se eligió cada una.

**Abiertas a 2026-09-30:**

| ID | Pregunta | Bloquea | Quién decide |
|----|----------|---------|--------------|
| `OPEN-054-01` | Idioma de la cabecera y de los valores enumerados del CSV | **RESUELTA** (2026-10-01, `ADR-055`) | Usuario |

#### `OPEN-054-01` · Idioma de la cabecera y de los valores enumerados del CSV — **RESUELTA** (2026-10-01, decisión del usuario, `ADR-055` ACEPTADA)

> **Decisión: opción A ahora, con C como ampliación posterior.** Un CSV de datos generado por la API es un **contrato técnico estable**, no un «documento generado» en el sentido de `ADR-021`: cabeceras = nombre `snake_case` del campo en la API, valores enumerados = código técnico sin traducir, y el fichero (formatos incluidos) no depende de quién lo solicita. La interfaz que dispara y comunica la exportación sigue en los cuatro idiomas (`INV-009`). C (columnas `<col>_label` al final, en el idioma del solicitante) queda como ampliación aditiva con demanda de un centro piloto (`ADR-055 §3`). Regla: `RN-CORE-59`; criterio: `CA-CORE-207`. **`1.9b` queda sin este bloqueo.**

_Texto original de la pregunta, conservado como contexto:_

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

`OPEN-054-01` (idioma del CSV) **resuelta** el 2026-10-01 por `ADR-055`.

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

---

## 14. Paso 1.9b · Pantallas de gestión pendientes de `REQ-CORE`

| Campo | Valor |
|-------|-------|
| Paso | **1.9b** (`PLAN-IMPLEMENTACION.md`, Bloque B), añadido por `OPEN-CORE-12` (§12.14), dividido en `1.9b`-`1.9f` (§14.2); **1.9c** (importación de usuarios y catálogo de tipos de documento) y **1.9d** (auditoría y roles de solo lectura, §14.25) implementados |
| Requisitos de origen | `REQ-CORE-002` (configuración, *branding*, consulta de módulos contratados), `REQ-CORE-003` (usuarios, invitaciones, importación y perfil propio, §4.9), `REQ-CORE-004` (roles, solo lectura en este paso), `REQ-CORE-005` (auditoría y su exportación). Transversales: `RUX-003`/`-004`/`-006`, `RUX-RESP-004`/`-005`/`-007`, `RUX-BRAND-001` a `-004` y `-006`, `RNF-UX-002`, `RNF-LIM-004`, `RPERM-003`/`-011`/`-013`, `INV-002`/`-003`/`-006`/`-009`/`-010`/`-011`/`-012` |
| Alcance | **Fijado por el usuario**: usuarios, invitaciones, importación, roles (solo lectura), auditoría, configuración del centro y activos de marca, con el componente de 1.9 (`RN-CORE-53`), **más** la migración al componente de `MfaExemptionsArea.vue`, `AdminSsoView.vue` y `SessionsView.vue`, **más** (ampliación decidida por el usuario el 2026-10-01, `OPEN-CORE-31` = B) la pantalla de módulos contratados en solo lectura y el perfil propio de autoservicio, ambos en `1.9e` |
| Decisiones vinculantes | `ADR-038`, `ADR-044`, `ADR-052`, `ADR-053`, `ADR-054`, `ADR-055` (todas ACEPTADAS) y §12/§13 de este documento |
| Depende de | 1.1 (API de §2-§8 de `api.md`), 1.2 (sesión), 1.5 (`/me.permissions`, resolutor), 1.7 (*design system*, `useTenantBranding().refresh()`, `contrast.ts`), 1.8 (*shell*, registro de navegación, estados de §12.6), 1.9 (`src/data-table/`), `fix/` de #266/#267/#270/#273 (PR #282) y `ADR-055`. **Todas implementadas.** **Dependencia operativa no resuelta**: sin *worker* de colas (#128, Alta), ni la importación ni ninguna exportación terminan fuera de un entorno con `queue:work` arrancado a mano (§14.15) |
| Código afectado | `apps/web` (pantallas, `shell.ts` de `core`, ampliaciones aditivas de `src/data-table` de `OPEN-CORE-40` = A) **y `apps/api`** (§14.11: dos *endpoints* nuevos —S1 y, si `OPEN-CORE-34` = B, S10—, cuatro cambios compatibles —S5 a S8— y tres correcciones —S3, S4 y S9—) **y una migración *expand*** (S2, `datos.md` Parte D) **y, en 1.9c, una migración de datos sin cambio de esquema** (`datos.md` Parte E) |
| Estado | **APROBADA** (2026-10-01, decisión del usuario): `OPEN-CORE-30` (A), `-31` (B), `-32` (B), `-39` (A), `-40` (A), `-42` (A) y `-43` (A) resueltas. **Sub-paso `1.9b` IMPLEMENTADO** (2026-10-02; notas y desviaciones en §14.22; pendiente de revisión independiente); **Sub-paso `1.9c` IMPLEMENTADO** (2026-10-02/03; notas y desviaciones en §14.23; revisado por `db-reviewer`, `security-reviewer` y `doc-reviewer` sin hallazgos Crítico/Alto; **pendiente de mezcla**); **Sub-paso `1.9d` IMPLEMENTADO y mezclado** (PR #320); **Sub-paso `1.9e` IMPLEMENTADO** (2026-10-03; notas y desviaciones en §14.26; revisado por `security-reviewer` y `doc-reviewer` sin hallazgos Crítico/Alto; pendiente de mezcla); **Sub-paso `1.9f` IMPLEMENTADO** (2026-10-04; `OPEN-CORE-54`/`-55`/`-56` = A; cobertura en §14.13.6; revisado por `security-reviewer` y `doc-reviewer` sin hallazgos Crítico/Alto; pendiente de mezcla), que **cierra la serie 1.9b-1.9f** |

### 14.0 Verificación del estado de partida (2026-10-01, rama `feature/REQ-CORE-1.9b-pantallas-de-gestion`, `495d19c`)

Lectura de código, no ejecución. La sesión de especificación no tenía herramienta de búsqueda en el árbol: se leyeron los ficheros por ruta conocida y lo que no se pudo localizar queda dicho como tal.

- **Frontend**: `src/modules/core/` tiene `api/` (clientes tipados de todos los *endpoints* de 1.1, incluidos `listUsers`, `listUserImports`, `executeUserImport`, `listAuditLogs` con `occurred_at_from/to`, `exportAuditLogs`, `getDataExport`), `types/`, `locales/` y un `shell.ts` con **una sola** entrada de navegación (`core.home`) y **ninguna ruta**. No hay ninguna vista de `REQ-CORE`. El catálogo de secciones (`src/navigation/sections.ts`) ya tiene `administracion`. `RouteMeta` ya admite `titleKey`, `breadcrumbKey` y `breadcrumbParent` (los usa `src/modules/auth/shell.ts`).
- **`src/data-table/`** (1.9): filtros cerrados `enum` (siempre **múltiple**), `dateRange` y `boolean` (tres estados); **sin valor inicial de filtro**, sin filtro de selección única y sin filtro de entidad. `DataTableExportConfig.status` trata **todo `409`** como «aún no está lista» (`useExportFlow.ts`).
- **Servidor**: rutas en `apps/api/app/Modules/Core/Http/routes.php`. Hallazgos que condicionan este paso (todos detallados en §14.16):
  1. `GET /data-exports/{id}` responde **`409 core.validation.export_failed`** a una exportación `fallida` (`DataExportsController::show()`), no `200` con `status: "fallida"` como describen `api.md §8` y §13.14.1 punto 5. Con el cliente de 1.9, una exportación fallida **se queda en «Preparando…» hasta agotar los 10 minutos** de `RN-CORE-49`.
  2. La ruta `GET /data-exports/{id}` lleva fijo `permission:auditoria.exportar` («cuando otro módulo use `ExportRequestService` con un `kind` propio, este *middleware* tendrá que resolverse por `kind`», comentario en `routes.php`). Ningún segundo `kind` funciona sin cambiarla.
  3. `GET /users` acepta `sort ∈ {family_name_1, -family_name_1, created_at, -created_at, email}`: **falta `-email`**. Una columna `email` ordenable enviaría `-email` en su segundo estado (`RN-CORE-39`) y recibiría `422`.
  4. `GET /users/{id}` **no** admite `include_deleted` (`UsersController::show()`), aunque `CA-CORE-014` dice que el detalle de un usuario eliminado responde `404` «salvo que se pida explícitamente incluir los eliminados».
  5. `GET /invitations?status=`, `GET /users?locale=` y `GET /audit-logs?actor_type=` validan **un solo valor** con `in:`/`Rule::in` (una lista por comas da `422`). `GET /audit-logs?module=` **no valida contra ninguna lista** (`'string'`): una lista por comas no da error, sino que llega a `AuditQuery` como un único código de módulo desconocido, sin aviso (**verificado en 1.9d**: devuelve lista vacía, sin error; ver §14.25). El filtro `enum` de 1.9 envía siempre lista por comas.
  6. `UserImportResource` **no devuelve `created_at`**, aunque `api.md §7` lo muestra en la respuesta.
  7. `ValidateUserImport` escribe `message` con `__()` en el idioma por defecto del proceso (issue [#285](https://github.com/pirexia/plataforma-educativa/issues/285)), y lo **persiste** en `user_imports.error_summary`: el texto queda congelado en un idioma, sea quien sea quien lo mire después.
- **No localizado**: si la SPA tiene ya una utilidad que genere ULID (la necesita la `Idempotency-Key` de la importación, `api.md §7`; el comentario de `executeUserImport()` dice que «quien llama lo aporta»); si existe un componente de diálogo de confirmación vendorizado (`alert-dialog`); y si alguna pantalla existente ya edita el grupo `security` de `PATCH /tenant/settings`. El implementador lo comprueba antes de escribir y lo reporta si no coincide con lo que aquí se supone.

### 14.1 Alcance

#### 14.1.1 Entra en 1.9b

| # | Qué | Requisitos |
|---|-----|------------|
| 1 | **Usuarios**: listado (filtros, búsqueda, orden, exportación), ficha, alta, edición, cambio de estado, baja lógica y restauración, reemisión de invitación desde la ficha, y asignación de roles en el alta y en la ficha (`OPEN-CORE-43` = A) | `REQ-CORE-002`/`-003`/`-004` (asignación múltiple), `RPERM-013` |
| 2 | **Invitaciones**: listado con filtro de estado, revocación y reemisión | `REQ-CORE-003` |
| 3 | **Importación de usuarios**: subida, seguimiento de la validación, incidencias, informe, ejecución idempotente, descarte, listado de lotes | `REQ-CORE-003`, `INV-011`, `INV-012` |
| 4 | **Roles**: listado de solo lectura (detalle según `OPEN-CORE-36`) | `REQ-CORE-004` (parte de 1.1) |
| 5 | **Auditoría**: listado en modo `cursor` con los filtros que exige `REQ-CORE-005` (fecha, usuario, tipo de operación, módulo), detalle de cambios y exportación | `REQ-CORE-005` |
| 6 | **Configuración del centro**: regional, fiscal y paleta, en lectura o edición según permiso | `REQ-CORE-002`, `RUX-BRAND-002`/`-006` |
| 7 | **Activos de marca**: logo, *favicon* y fondo de acceso | `RUX-BRAND-001`/`-003`/`-004` |
| 7b | **Módulos contratados**, solo lectura, con la fecha de alta como aviso (`OPEN-CORE-31` = B, §14.10b) | `REQ-CORE-002`, `RMOD-008`, `ADR-045` |
| 7c | **Perfil propio** de autoservicio: correo y teléfono de contacto (`OPEN-CORE-31` = B, §14.10c) | `REQ-CORE-003`, §4.9 |
| 8 | **Migración al componente** de `MfaExemptionsArea.vue`, `AdminSsoView.vue` y `SessionsView.vue`, retirando cada una de la lista de excepciones de `RN-CORE-53` | `RN-CORE-53`, `ADR-054 §1.4` |
| 9 | **Servidor**: `POST /users/exports` (primer generador de CSV de datos nuevo, prueba de `CA-CORE-207`), autorización por `kind` en `GET /data-exports/{id}`, corrección del contrato de `fallida`, y los cambios compatibles de §14.11 | `RPERM-003`, `ADR-054 §8`-`§10`, `ADR-055`, `INV-006` |

#### 14.1.2 No entra en 1.9b

| Fuera | Dónde va | Motivo |
|-------|----------|--------|
| **Configuración de `settings` de un módulo** (`PATCH /module-subscriptions/{id}`) | Paso del primer módulo que declare `settings` configurables | `OPEN-CORE-31` = B: la pantalla de módulos es de **solo lectura**; hoy ningún módulo tiene `settings` que configurar y un formulario sería una pantalla vacía |
| Notificación *in-app* de un módulo recién contratado | `REQ-COM-003` (1.19) | `ADR-045`: el aviso de 1.9e es la fecha de alta visible en la lista, no una notificación |
| Editor de roles, clonación, matriz de concesión, permisos efectivos por usuario | `1.5b` | Plan, `ADR-044 §6`. En 1.9b los roles son de solo lectura aunque la API de 1.5 ya admita escritura (`RN-CORE-75`) |
| Edición de `mfa_required` de un rol | Ya existe en `/administracion/mfa` (`REQ-AUTH` 1.3) | No se duplica |
| Foto de perfil | Sin paso | `OPEN-CORE-08` |
| Mapeo visual de columnas, plantillas, reversibilidad del lote de importación | `REQ-ONB` (1.24) | §1.10 |
| Plantilla CSV descargable para la importación | Sin paso | Ningún requisito la pide. La pantalla muestra la cabecera exacta, copiable, y remite al manual (§14.6.1). Generarla en el cliente chocaría con `CA-CORE-192` |
| Exportación PDF de auditoría, XLSX | 1.17 / sin paso | §4.6, §13.1.2 |
| «Mis exportaciones» (`GET /data-exports`) | Sin decidir: `OPEN-CORE-41` | `OPEN-CORE-25` lo remitía a 1.9b |
| Columnas `<col>_label` en los CSV | Con demanda de centro piloto | `ADR-055 §3` |
| Dominio personalizado, textos de sistema personalizables (`RUX-BRAND-005`), parámetros académicos | §1.2, sin paso, 1.10/1.11/1.16 | No hay servidor que los soporte |
| Acciones de plataforma visibles para el centro (`GET /platform-actions`) | Sin decidir: `OPEN-CORE-44` | Es `REQ-BO-007` (1.6); su interfaz no está en el alcance fijado |

### 14.2 Tamaño y división (`OPEN-CORE-30`, resuelta: cinco sub-pasos)

**El paso no cabe en un solo `implementer` ni en una sola revisión.** Medido por lo que hay que construir: nueve pantallas principales (siete del alcance inicial más módulos y perfil) y seis secundarias (ficha, alta, edición, detalle de importación, detalle de rol, activos), tres migraciones de pantallas de `REQ-AUTH` con sus tests preexistentes, un *endpoint* nuevo con su trabajo en cola y su migración, cinco cambios de contrato en servidor y hasta tres ampliaciones del componente de tablas. El precedente más parecido, 1.9, era **solo** el componente más una migración y ya ocupó una sesión entera de implementación y otra de revisión. Además mezcla `apps/api` y `apps/web`, lo que exige `db-reviewer` (hay migración) en una parte y no en otras.

**División decidida por el usuario el 2026-10-01** (`OPEN-CORE-30` = A). Cada sub-paso cierra con suite en verde, documentación y revisión independiente, como `1.6`-`1.6e`:

| Sub-paso | Contenido | Servidor | Depende de | Revisores |
|----------|-----------|----------|------------|-----------|
| **1.9b** | **Usuarios e invitaciones**: §14.4, §14.5, exportación de usuarios (esquema de §14.11.1), asignación de roles; además el diálogo de confirmación común (`RN-CORE-64`) y el filtro booleano de dos estados (`RN-CORE-68`) | `POST /users/exports` + trabajo + migración del `CHECK` de `kind`; `GET /data-exports` por `kind` y contrato de `fallida`; `-email`; `include_deleted` en el detalle; `status` múltiple en invitaciones; `locale` múltiple en usuarios | 1.9 | `db-reviewer`, `security-reviewer`, `doc-reviewer` |
| **1.9c** | **Importación**: §14.6 | `created_at` y `send_invitations` en `UserImportResource`; idioma de `ValidateUserImport` (#285, `OPEN-CORE-38` = A, resuelta) | 1.9b (navegación a usuarios, utilidad ULID) | `security-reviewer`, `doc-reviewer` |
| **1.9d** | **Auditoría y roles (solo lectura)**: §14.7, §14.8 | `actor_type`/`module` múltiples; *endpoint* de facetas (S10, `OPEN-CORE-34` = B) | 1.9b (selector de usuario para el filtro de actor, `OPEN-CORE-33` = C) | `security-reviewer`, `doc-reviewer` (`db-reviewer` solo si hay índice nuevo) |
| **1.9e** | **Configuración del centro, activos de marca, módulos contratados (solo lectura) y perfil propio**: §14.9, §14.10, §14.10b, §14.10c. Incluye ampliar la lista cerrada de `RN-CORE-24` de seis a siete rutas (§14.3.1) | Ninguno | 1.9 | `security-reviewer` (subida de ficheros), `doc-reviewer` |
| **1.9f** | **Migración de las tres tablas de `REQ-AUTH`**: §14.13 | Ninguno | Ampliaciones del componente de `OPEN-CORE-40` (A) | `doc-reviewer`, `security-reviewer` |

**Orden y motivo**: 1.9b primero porque contiene el primer generador de CSV nuevo (`CA-CORE-207`, con el esquema ya fijado por `OPEN-CORE-32` = B), las dos correcciones de `GET /data-exports` que también afectan a la exportación de auditoría ya existente, y la búsqueda de usuarios que reutiliza 1.9d. 1.9e y 1.9f no dependen de nada de este paso y pueden ir en cualquier punto. Las ampliaciones del componente de `OPEN-CORE-40` = A se reparten así: el filtro booleano de dos estados (dados de baja) entra en 1.9b, que es su primer consumidor; el filtro `enum` de selección única con valor inicial entra en 1.9f (`MfaExemptionsArea`). Si 1.9f se adelanta a 1.9d, la ampliación de selección única gana un consumidor con tests preexistentes antes; no es obligatorio. La numeración `1.9c`-`1.9f` no colisiona con ningún paso del plan.

**Alternativa descartada** (tres sub-pasos): {usuarios+invitaciones+importación}, {auditoría+roles+migraciones}, {configuración+marca}. Ahorraba dos cierres pero la primera supera con holgura lo que cabe en una sesión de 5 horas con Sonnet, y un corte de cuota a mitad de un sub-paso con migración es el peor caso de `CLAUDE.md §3`.

### 14.3 Inventario de pantallas y rutas

Todas en el régimen `app`, registradas en `src/modules/core/shell.ts` (`ADR-053 §1`), en la sección `administracion` salvo el perfil propio, que va en `cuenta`. **Los permisos se declaran una sola vez, en `meta.permissions` de la ruta** (anyOf, `ADR-053 §3`); la entrada de menú no lleva permisos. **Una sola ruta nueva usa `[]`**: `core-profile` (perfil propio, autoservicio por identidad), que amplía la lista cerrada de `RN-CORE-24` de seis a siete rutas como consecuencia de `OPEN-CORE-31` = B (§14.3.1). Ninguna otra la amplía.

| Ruta (nombre · ruta) | `meta.permissions` (anyOf) | Entrada de menú (`id`, acceso directo) | *Endpoints* que consume | Sub-paso |
|----------------------|-----------------------------|----------------------------------------|-------------------------|----------|
| `core-users` · `/administracion/usuarios` | `usuario.leer` | `core.users`, **sí** | `GET /users`; `GET /roles` (opciones del filtro, solo con `rol.leer`); `POST /users/exports` + `GET /data-exports/{id}` (solo con `usuario.exportar`) | 1.9b |
| `core-user-new` · `/administracion/usuarios/nuevo` | `usuario.crear` | — (acción del listado) | `POST /users`; `GET /roles` (solo con `rol.leer` y `asignacion_rol.crear`) | 1.9b |
| `core-user-detail` · `/administracion/usuarios/:publicId` | `usuario.leer` | — | `GET /users/{id}` (con `include_deleted=true` solo con `usuario.eliminar`); `POST /users/{id}/status`; `DELETE /users/{id}`; `POST /users/{id}/restore`; `POST /users/{id}/invitations`; `GET`/`PUT /users/{id}/roles` (`OPEN-CORE-43` = A) | 1.9b |
| `core-user-edit` · `/administracion/usuarios/:publicId/editar` | `usuario.actualizar` | — | `GET /users/{id}`, `PATCH /users/{id}` | 1.9b |
| `core-invitations` · `/administracion/invitaciones` | `invitacion.leer` | `core.invitations`, no | `GET /invitations`; `DELETE /invitations/{id}`; `POST /users/{id}/invitations` | 1.9b |
| `core-user-imports` · `/administracion/importaciones` | `usuario.importar` | `core.userImports`, no | `GET /user-imports`; `POST /user-imports` | 1.9c |
| `core-user-import-detail` · `/administracion/importaciones/:publicId` | `usuario.importar` | — | `GET /user-imports/{id}`; `POST /user-imports/{id}/execute`; `DELETE /user-imports/{id}` | 1.9c |
| `core-roles` · `/administracion/roles` | `rol.leer` | `core.roles`, no | `GET /roles` | 1.9d |
| `core-role-detail` · `/administracion/roles/:publicId` | `rol.leer` | — | **no se crea** (`OPEN-CORE-36` = A; llega con `1.5b`) | 1.9d |
| `core-audit` · `/administracion/auditoria` | `auditoria.leer` | `core.audit`, **sí** | `GET /audit-logs`; `POST /audit-logs/exports` + `GET /data-exports/{id}` (solo con `auditoria.exportar`); `GET /users?q=` (filtro de actor, solo con `usuario.leer`, `OPEN-CORE-33` = C); `GET /audit-logs/facets` (`OPEN-CORE-34` = B) | 1.9d |
| `core-settings` · `/administracion/centro` | `configuracion.leer` | `core.settings`, no | `GET /tenant/settings`; `PATCH /tenant/settings` (solo con `configuracion.actualizar`) | 1.9e |
| `core-branding-assets` · `/administracion/centro/marca` | `configuracion.leer` | — (acción de `core-settings`, miga de pan bajo «Centro») | `GET /tenant/settings`; `PUT`/`DELETE /tenant/settings/assets/{kind}` (solo con `configuracion.actualizar`) | 1.9e |
| `core-modules` · `/administracion/modulos` | `modulo.leer` | `core.modules`, no | `GET /modules` (solo lectura; **nunca** `PATCH /module-subscriptions/{id}`, aunque el usuario tenga `modulo.actualizar`) | 1.9e |
| `core-profile` · `/cuenta/perfil` | **`[]`** (autoservicio por identidad, `permisos.md §5.2`; no existe ni se inventa permiso granular) | `core.profile`, no — sección **`cuenta`** | Estado de sesión de `GET /me` (ya cargado por el *guard*, `RN-CORE-26`); `PATCH /me` con `person.contact_email`/`person.contact_phone` | 1.9e |

Las rutas secundarias declaran `breadcrumbParent` (la principal de su fila) y `titleKey`/`breadcrumbKey` en el espacio de nombres `core.*`, como hacen las de SSO. Accesos directos: usuarios y auditoría (`shortcut: true`), el resto no. Es presentación sin requisito detrás; cambiarlos es tocar el campo `shortcut` y nada más.

#### 14.3.1 Ampliación de la lista cerrada de `RN-CORE-24` (consecuencia de `OPEN-CORE-31` = B)

`RN-CORE-24` (§12.8) y `CA-CORE-103` (§12.11) fijan una lista cerrada de **seis** rutas `app`/`bare` con `meta.permissions` vacía, y `funcional.md` §14 en su redacción inicial afirmaba que 1.9b no la ampliaba. Con la decisión del usuario de incluir el perfil propio, **la lista pasa a siete**: las seis de §12.8 más `core-profile`. Cumple el criterio de entrada de `RN-CORE-24` —autoservicio por identidad sin ningún permiso real que exigir sin inventarlo— por el mismo motivo que `password-change`, `sessions` y `mfa-security`: `PATCH /me` se autoriza por identidad del sujeto, nunca por permiso (`MeController`, `permisos.md §5.2`), y un permiso para editar los datos propios crearía una forma de dejar a alguien sin poder corregir su propio teléfono.

- **Qué cambia**: la constante con las rutas de `[]` del test de coherencia de `ADR-053 §2` (comprobación 5), hoy en `apps/web/src/navigation/modules.spec.ts` (lo cita el comentario de cabecera de `src/modules/auth/shell.ts`), gana `core-profile`. Se actualiza **en 1.9e**, en el mismo *commit* que añade la ruta; antes de ese *commit* el test fallaría si la ruta existiera, y después fallaría si no se ampliara la constante.
- `RN-CORE-24` y `CA-CORE-103` de §12 llevan una nota que remite aquí; su texto original se conserva (precedente del issue #260).
- La pantalla de módulos **no** usa `[]`: exige `modulo.leer`.

**`RN-CORE-60` · Registro.** Toda pantalla de 1.9b se registra en `src/modules/core/shell.ts`, no en `src/router/index.ts`; `meta.permissions` de cada ruta es exactamente el permiso del *endpoint* que la vista necesita para pintar su contenido principal (columna 2), nunca un rol ni la unión de permisos de sus acciones secundarias; la única excepción es `core-profile`, con `[]` por identidad (§14.3.1). Las acciones secundarias se gobiernan por `RN-CORE-61`. Los cinco tests de coherencia de `ADR-053 §2` cubren las rutas nuevas; el único cambio en ellos es la constante de rutas con `[]` de la comprobación 5, que gana `core-profile` en 1.9e (§14.3.1).

### 14.4 Usuarios

#### 14.4.1 Listado (`core-users`)

Tabla `core.users`, modo `page`, **estado en la URL** (`urlState`, tabla principal de la ruta, `RN-CORE-54`), búsqueda `q` (nombre, apellidos y correo de acceso, `api.md §3`), tarjetas por debajo de 768 px.

**`RN-CORE-67` · Columnas del listado de usuarios** (criterio de minimización, skill `datos-personales`):

| `id` | Cabecera | Contenido | Ordenable | Tarjeta | Por defecto |
|------|----------|-----------|-----------|---------|-------------|
| `family_name_1` | Nombre | «Apellido1 Apellido2, Nombre», enlace a la ficha. `rowHeader` | Sí | `title` | Visible, no ocultable |
| `email` | Correo de acceso | `email` | Sí (requiere `-email`, §14.11) | `subtitle` | Visible |
| `status` | Estado | Etiqueta traducida de `pendiente`/`activo`/`inactivo`; «dado de baja» si `deleted_at` | No | `field` | Visible |
| `role` | Roles | `roles[].name` separados por coma | No | `field` | Visible |
| `locale` | Idioma | Nombre del idioma en el idioma de la interfaz | No | `field` | Oculta |
| `created_at` | Alta | Fecha con `Intl` | Sí | `field` | Oculta |

**No** se muestran en el listado `document_number`, `birth_date`, `contact_email` ni `contact_phone`, aunque el *endpoint* los devuelve (§14.16, hallazgo 8): el listado es para localizar a una persona, no para leer sus datos identificativos en bloque; la ficha los muestra uno por uno. Ocultarlos no es minimización en la API (`§13.8`, último punto): solo no los pinta.

**Filtros**: `status` (`enum`: `pendiente`, `activo`, `inactivo`); `role` (`enum` con las opciones de `GET /roles?per_page=100`, **solo si** el usuario tiene `rol.leer`, `RN-CORE-62`); `locale` (`enum` con los idiomas activos del centro de la capa B; requiere que `GET /users` acepte varios valores, §14.11); **dados de baja** (`include_deleted`) solo si el usuario tiene `usuario.eliminar` (`RN-CORE-68`).

- **`RN-CORE-68` · Usuarios dados de baja.** El filtro que incluye a los dados de baja solo se ofrece con `usuario.eliminar` (el servidor lo exige además de `usuario.leer`, `permisos.md §2`). Es un filtro **booleano de dos estados** («incluir dados de baja», desmarcado por defecto, que con la casilla marcada envía `include_deleted=true` y desmarcada no envía el parámetro), ampliación aditiva de `src/data-table` decidida en `OPEN-CORE-40` = A: el filtro `boolean` de 1.9 tiene tres estados y «todos» y «no» darían el mismo resultado con etiquetas distintas. La ampliación se construye en 1.9b, su primer consumidor. La fila de un usuario dado de baja muestra el estado «dado de baja» y su ficha se abre con `include_deleted=true` (§14.11).

**Exportación**: `canExport` = `usuario.exportar` en `/me.permissions` (`RN-CORE-51`); deshabilitada con `q` activo (`RN-CORE-57`).

- **`RN-CORE-69` · Exportación de usuarios.** La solicitud lleva los filtros estructurados del listado (`status`, `role`, `locale`, `include_deleted`) como *arrays* JSON donde son múltiples, nunca `q`, `sort`, `page` ni `per_page` (`ADR-054 §7.3`, `§8.2`). La función de solicitud vive en `src/modules/core/api/` y traduce la forma por comas a *array* (`ADR-054 §8.2`). El contenido del fichero lo fija §14.11.1 (`OPEN-CORE-32` = B): **no contiene** tipo ni número de documento ni fecha de nacimiento. El texto que acompaña al control de exportación no promete esas columnas, y el manual describe las que sí contiene.

**Acciones de la barra**: «Nuevo usuario» (con `usuario.crear`) y «Importar» (enlace a `core-user-imports`, con `usuario.importar`).

#### 14.4.2 Ficha (`core-user-detail`)

Muestra todos los campos de `GET /users/{id}`, incluidos `document_type`/`document_number`, `birth_date`, `contact_email`, `contact_phone`, `locale`, `email_verified_at`, fechas de alta y baja, y sus roles. Acciones, cada una visible solo con su permiso (`RN-CORE-61`):

| Acción | Permiso | *Endpoint* | Visible cuando |
|--------|---------|------------|----------------|
| Editar | `usuario.actualizar` | navegación a `core-user-edit` | No dado de baja |
| Activar / desactivar | `usuario.actualizar` | `POST /users/{id}/status` | `status` ∈ {`activo`, `inactivo`} y no dado de baja (`pendiente` solo sale por canje, `RN-CORE-04`) |
| Dar de baja | `usuario.eliminar` | `DELETE /users/{id}` | No dado de baja |
| Restaurar | `usuario.eliminar` | `POST /users/{id}/restore` | Dado de baja |
| Enviar / reenviar invitación | `invitacion.crear` | `POST /users/{id}/invitations` | `status = pendiente` y no dado de baja |
| Ver sus roles | `asignacion_rol.leer` | `GET /users/{id}/roles` | Siempre (con el permiso) |
| Gestionar roles (`OPEN-CORE-43` = A) | `asignacion_rol.crear` (y `rol.leer` para las opciones; retirar roles exige además `asignacion_rol.eliminar`, que decide el servidor) | `PUT /users/{id}/roles` | No dado de baja |
| Ver su actividad | `auditoria.leer` | navegación a `core-audit` con `actor_id` | `OPEN-CORE-33` = C (resuelta): la acción se añade en 1.9d, no en 1.9b |

- **`RN-CORE-61` · Acciones por permiso, nunca por rol, y sin anticipar reglas de servidor.** Una acción se muestra si y solo si `/me.permissions` contiene el permiso de su *endpoint*. La interfaz **no** reproduce `RN-CORE-06` (no tocarse a uno mismo) ni `RN-CORE-07` (último administrador) comprobando códigos de rol (`RN-CORE-23`, `CA-CORE-102`): el servidor responde `409` y la vista muestra su `detail` traducido. **Única excepción admitida, por identidad y no por rol**: si el `public_id` de la ficha es el de `/me`, las acciones de estado, baja y roles se muestran deshabilitadas con la explicación «no puedes modificar tu propia cuenta desde aquí» — comodidad, el servidor sigue decidiendo.
- **Restaurar** devuelve el usuario en `inactivo` (`api.md §3`): la vista lo dice tras la restauración y ofrece «Activar» si procede. `409` (correo o documento ocupados) muestra el `detail`.

#### 14.4.3 Alta y edición (`core-user-new`, `core-user-edit`)

Formulario con los campos de `POST /users` (`api.md §3`): correo de acceso, nombre, primer y segundo apellido, fecha de nacimiento, tipo y número de documento, correo y teléfono de contacto, idioma preferido (solo idiomas activos del centro, por defecto el `default_locale`), roles (selector múltiple, `OPEN-CORE-43` = A, visible solo con `rol.leer` y `asignacion_rol.crear`) y «enviar invitación» (marcado por defecto). La edición usa el mismo formulario sin roles ni invitación y envía `PATCH` (`RN-CORE-65`).

- **Tipo de documento**: el servidor lo acepta como texto libre de hasta 32 caracteres (`StoreUserRequest`) y valida el formato por tipo en `CreateUser`. **No se ha localizado un catálogo cerrado de tipos expuesto a la SPA.** El implementador lee `CreateUser` y usa como opciones del selector exactamente los tipos que el servidor valida; si no hay lista cerrada en servidor, para y lo reporta (no inventa una). **Sustituido en 1.9c por §14.6.4** (catálogo cerrado `RN-CORE-90`, selector en el formulario).
- **`RN-CORE-65` · Formularios.** La validación de cliente es solo comodidad (`INV-010`). Un `422` pinta cada `errors.<campo>[].message` (ya traducido por el servidor, `ADR-038 §6.3`) bajo su campo, con `aria-invalid="true"` y `aria-describedby`; el foco va al primer campo con error y un resumen con `role="alert"` enumera los errores. `PATCH` envía **solo las claves modificadas** (`ADR-038 §9.2`: clave ausente no toca el campo; vaciar un campo opcional envía `null`, nunca `""`). Un `403` de `RPERM-013` (asignar un rol con permisos que el solicitante no tiene) muestra el `detail` del servidor junto al campo de roles. Los campos usan el tipo de entrada adecuado para teclado táctil (`type="email"`, `type="tel"`, `type="date"`, `RUX-RESP-005`).
- **`RN-CORE-66` · Resultado de una escritura.** Tras una escritura correcta: mensaje con `role="status"`, navegación a la ficha (alta, edición) o actualización del dato en pantalla (acciones de la ficha). Al volver al listado, la consulta se conserva (estado en la URL, `RN-CORE-54`).
- Si el alta incluía invitación, la ficha muestra la caducidad devuelta en `invitation.expires_at`. El token **nunca** llega a la SPA (`RN-CORE-19`).

### 14.5 Invitaciones (`core-invitations`)

Tabla `core.invitations`, modo `page`, estado en la URL. Columnas: correo del usuario (`rowHeader`, enlace a su ficha si se tiene `usuario.leer`), estado (`vigente`/`caducada`/`revocada`/`aceptada`, traducido), caducidad, emisión, aceptación o revocación. Sin búsqueda (`GET /invitations` no acepta `q`) y sin columnas ordenables (no acepta `sort`; orden del servidor: más reciente primero). Filtro `status` (`enum`, requiere que el servidor acepte varios valores, §14.11).

- **`RN-CORE-70` · Acciones sobre invitaciones.** «Revocar» (`invitacion.eliminar`) solo en las `vigente`. «Reenviar» (`invitacion.crear`, `POST /users/{user}/invitations`) en las `caducada` y `revocada`; si el usuario ya no está `pendiente`, el servidor responde `409` (`RN-CORE-12`) y la vista muestra su `detail` y refresca la fila. `429` (límite de reenvíos, `api.md §4`) muestra los segundos de `Retry-After` (§12.6). Las dos acciones piden confirmación (`RN-CORE-64`).

### 14.6 Importación de usuarios

#### 14.6.1 Listado y subida (`core-user-imports`)

Tabla `core.user_imports`, modo `page`, sin filtros ni orden (el *endpoint* no los acepta). Columnas: fichero (`original_filename`, `rowHeader`, enlace al detalle), fecha de subida (`created_at`, requiere el cambio de §14.11), estado (traducido), filas, filas con error, usuarios creados.

Formulario de subida encima de la tabla: un campo de fichero (`accept=".csv"`: el literal `text/csv` está prohibido en el cliente por `CA-CORE-192`, sin excepciones, decisión del usuario del 2026-10-03; el servidor decide el tipo real, `415`), la casilla «enviar invitaciones» (marcada por defecto, igual que el servidor) y la **cabecera exacta esperada** en un bloque copiable (`api.md §7`). **Sin enlace al manual** (la SPA no publica el manual; decisión del usuario del 2026-10-03): la pantalla remite al apartado «Importación de usuarios» del manual de administración por su nombre.

- **`RN-CORE-71` · Subida.** Las comprobaciones de extensión y tamaño (≤ 10 MB) en cliente son comodidad; el servidor decide (`413`, `415`, `422`, `RN-CORE-18`). Tras `202`, la vista navega al detalle del lote.

#### 14.6.2 Detalle de un lote (`core-user-import-detail`)

Muestra estado, recuentos, fechas y, cuando el lote está `validado` o `fallido`, las incidencias.

- **`RN-CORE-72` · Seguimiento del estado.** Mientras el lote está en `subido`, `validando` o `ejecutando`, la vista consulta `GET /user-imports/{id}` con la **misma política que `RN-CORE-49`** y las mismas constantes exportadas de `src/data-table` (una consulta en vuelo, 2 s duplicando hasta 30 s, parada a los 10 min con «Comprobar de nuevo», se detiene al desmontar). A diferencia de una exportación, **salir de la vista no pierde nada**: el lote aparece en el listado. El estado se anuncia con `role="status"` en cada cambio, no en cada consulta.
- **`RN-CORE-73` · Ejecución idempotente.** «Ejecutar» (con `usuario.importar`) solo en `validado`. Pide confirmación (`RN-CORE-64`) que dice **cuántas filas se crearán** (`row_count − error_count`), que las filas con error se omiten, **si se enviarán invitaciones** y que **una importación no se deshace** (§1.10). Al confirmar se genera **una** `Idempotency-Key` ULID (`ADR-038 §8`); si la petición falla sin respuesta (red, `5xx`) y el usuario reintenta **la misma confirmación**, se reutiliza la misma clave; una confirmación nueva genera una clave nueva. Una respuesta con `Idempotency-Replayed: true` se trata como éxito. `409` muestra el `detail` (`import_not_validated`, clave reutilizada con otro cuerpo o ejecución en curso). La ULID se genera sin dependencia nueva (`CLAUDE.md §1`, `RNF-MANT-007`): utilidad propia mínima si no existe ya (§14.0).
- **`RN-CORE-74` · Incidencias.** Se pinta `error_summary` como tabla `core.user_import_errors` (modo `page` de una sola página, ver la nota) con línea, columna y motivo; si `error_count` supera las incidencias recibidas **o** las entradas recibidas llegan al tope de 50 (`error_count` cuenta filas, no incidencias: 30 filas con 2 errores se truncan a 50 con `error_count` = 30; decisión del usuario del 2026-10-03), un aviso dice que solo se muestran las 50 primeras y ofrece el informe completo (`report_url`, enlace a la URL firmada, `RN-CORE-46`: sin `Blob`). El texto del motivo es `message` tal como llega; su idioma es el de quien subió el lote (`OPEN-CORE-38` = A, S9, #285). Si el lote está `fallido` por cabecera, se muestra el motivo y la cabecera esperada, sin «Ejecutar».
  - *Nota*: `error_summary` no es un listado paginado de ningún *endpoint* sino un campo de un recurso (como mucho 50 entradas). El componente de 1.9 no tiene modo `local` (`OPEN-CORE-26`). **Se pinta con el componente** igualmente (`RN-CORE-53` no admite una cuarta excepción), con una función de petición que devuelve las entradas ya recibidas como una única página (`meta = {current_page: 1, per_page: 50, total: n, last_page: 1}`), sin filtros, orden ni exportación. No es el modo `local`: no ordena ni filtra en cliente. El modo `local` sigue sin construirse (`OPEN-CORE-40` = A; queda para `1.5b`, `OPEN-CORE-26`). La misma técnica de página única sirve a la pantalla de módulos (§14.10b).
- **Descartar** (`DELETE /user-imports/{id}`) en `subido`, `validando`, `validado` y `fallido`, con confirmación; `409` si ya se ejecutó.
- **Informe caducado**: `report_url` caduca a los 15 min (`CORE_SIGNED_URL_TTL_MINUTES`). La vista vuelve a pedir el detalle antes de mostrar el enlace si han pasado más de 10 min desde la última respuesta (margen sobre el TTL), y ofrece «Actualizar» si el enlace falla.

#### 14.6.3 Idioma de los mensajes (issue #285)

`ADR-055 §1` deja **fuera** del contrato técnico el informe de errores: su forma es la de `errors` de `ADR-038 §6.3` (código estable más mensaje legible), dirigido a quien subió el fichero. El mensaje sale en el idioma de quien subió el lote (`OPEN-CORE-38` = A, S9; precedencia de `RN-CORE-34`), se persiste en ese idioma y se muestra tal cual en la pantalla y en `report.csv`.

#### 14.6.4 Catálogo cerrado de tipos de documento de identidad — **APROBADA** (2026-10-02) (issue [#292](https://github.com/pirexia/plataforma-educativa/issues/292), prerrequisito de 1.9c)

> **Implementada en `1.9c`** (2026-10-02; notas y desviaciones en §14.23). **Estado: APROBADA el 2026-10-02.** El usuario ratificó en bloque las recomendaciones de `OPEN-CORE-46` a `OPEN-CORE-53` (§14.6.4.9): **46 = B** (`dni`, `nie`, `pasaporte`; **sin `otro`**), 47 = A, 48 = A, 49 = A, 50 = A, 51 = A, 52 = A, 53 = A y `label` del filtro `enum` ratificada. Donde el texto siguiente dice «según `OPEN-CORE-NN`» rige la opción recomendada de esa pregunta. No reabre §14.4.3 ni §14.22 punto 1: los sustituye **solo si** se aprueba, y entonces se anotará allí. No toca `OPEN-CORE-38` ni `OPEN-CORE-32` (el CSV de exportación sigue sin `document_type`, `document_number` ni `birth_date`).

##### 14.6.4.1 Por qué hace falta antes de 1.9c

- **Estado real del código** (lectura, 2026-10-02, `develop` en `3e2c475`): `person.document_type` se acepta como texto libre de hasta 32 caracteres (`StoreUserRequest`, `UpdateUserRequest`); `DocumentNumberValidator` solo reconoce `DNI` y `NIE` (formato más letra de control módulo 23, esta última conmutable fuera de producción por `OPEN-CORE-06`) y para **cualquier otro valor** solo exige número no vacío. La columna `people.document_type` es `text` sin `CHECK` (`ADR-034 §1`), con `UNIQUE (tenant_id, document_type, document_number) WHERE document_number IS NOT NULL AND deleted_at IS NULL`.
- **La importación sí lleva el tipo**: la cabecera fija de `UserImportCsvReader::EXPECTED_HEADER` incluye `document_type`, `document_number` y `birth_date`, y `UserImportRowValidator` valida el par con el mismo `DocumentNumberValidator`. Sin catálogo, una hoja con `D.N.I.`, `dni`, `Pasaporte` o `passport` pasa la validación con reglas distintas según cómo se escriba el tipo, y la pantalla de 1.9c tendría que explicar en el manual una columna cuyos valores admitidos **no existen**.
- **Consecuencias que ya produce el texto libre** (hallazgos de §14.6.4.8, no se corrigen aquí): el validador compara el tipo en mayúsculas pero se guarda tal cual llega, de modo que `DNI` y `dni` son **tipos distintos para el índice único** y la misma persona puede darse de alta dos veces (`RN-CORE-03` incumplida); un `PATCH` que cambia solo el tipo no revalida el número; un número sin tipo se guarda con tipo `NULL`, que el índice único no compara.
- **Qué dicen los requisitos**: `REQ-CORE-003` enumera como dato de usuario «**DNI/NIE**» y nada más. §6 de este documento (aprobado en 1.1) dice «se valida el formato de DNI/NIE/**pasaporte**», pero el código no valida ningún formato de pasaporte (hallazgo F6). `REQ-FAM-UNIT-005` habla de «documento identificativo» sin tipo. Ningún requisito enumera más tipos. **Cualquier valor fuera de `DNI`/`NIE` es, por tanto, decisión del usuario** (`OPEN-CORE-46`), no de esta propuesta.

##### 14.6.4.2 Valores propuestos

Códigos técnicos estables, sin traducir en servidor (`ADR-038 §3.2`), traducidos en el cliente con rama por defecto que muestra el código (`ADR-038 §7.3`). La grafía (minúsculas, como el resto de enumerados del producto —`pendiente`, `users`, `todos`—, o mayúsculas, como los valores que hoy reconoce el validador) es `OPEN-CORE-47`; la tabla usa minúsculas solo para fijar ideas.

| Código | Qué es | Origen en requisitos | Formato (tras normalizar, §14.6.4.3) | Control | En la propuesta |
|--------|--------|----------------------|--------------------------------------|---------|-----------------|
| `dni` | Documento nacional de identidad español | `REQ-CORE-003` («DNI/NIE») | `^\d{8}[A-Z]$` | Letra = `TRWAGMYFPDXBNJZSQVHLCKE[n mod 23]` sobre los 8 dígitos. Conmutable fuera de producción (`OPEN-CORE-06`); el **formato se exige siempre** | Sí, sin alternativa |
| `nie` | Número de identidad de extranjero (el que figura en la TIE o en el certificado de registro de ciudadano de la UE) | `REQ-CORE-003` | `^[XYZ]\d{7}[A-Z]$` | Igual que `dni`, sustituyendo `X`→0, `Y`→1, `Z`→2. Mismo conmutador | Sí, sin alternativa |
| `pasaporte` | Pasaporte, de cualquier país | Solo §6 de este documento; no está en `REQ-CORE-003` | Alfanumérico sin separadores, longitud según `OPEN-CORE-51` | **Ninguno**: el número de pasaporte no lleva dígito de control propio (el de la zona MRZ no se captura) | Según `OPEN-CORE-46` |
| `otro` | Cualquier otro documento de identidad (p. ej. documento nacional de otro Estado de la UE sin NIE todavía) | **Ninguno** | Texto no vacío, ≤ 32 caracteres | Ninguno | **Excluido** (`OPEN-CORE-46` = B, 2026-10-02); no se implementa ni se traduce |

**Descartados expresamente** (no se proponen; si el usuario quiere alguno, se añade por la vía aditiva de §14.6.4.4):

- **TIE** como tipo propio: la tarjeta es el soporte; el identificador es el NIE. Dos códigos para el mismo número romperían `RN-CORE-03`.
- **NIF K/L/M** (españoles menores de 14 años sin DNI, españoles no residentes, extranjeros sin NIE): son identificadores fiscales, ningún requisito de `REQ-CORE` los pide, y su validación es distinta. Si `REQ-ALUM` o `REQ-FIN` los necesitan, los propondrán con su requisito.
- **NIA / identificador autonómico del alumno**: no es un documento de identidad sino un código administrativo de la Consejería; es dato de la faceta `students` (`REQ-ALUM`/`REQ-SEC`), no de `people` (`ADR-034 §1`: los datos de faceta no van en `people`).

**Interacción con `REQ-SEED-005`** («documentos con formato válido pero dígito de control deliberadamente incorrecto»): solo `dni` y `nie` pueden cumplirla. Un `pasaporte` sintético no tiene dígito que invalidar; si `1.15b` (`REQ-SEED`) quiere generarlos, tendrá que decidir cómo hacerlos reconociblemente ficticios. No bloquea 1.9c; se anota para la especificación de `REQ-SEED`.

##### 14.6.4.3 Reglas de negocio propuestas

- **`RN-CORE-90` · Catálogo cerrado.** `person.document_type` solo admite los códigos del catálogo aprobado. La única fuente de verdad en servidor es un enumerado PHP público en `App\Modules\Core\Domain` (`INV-007`: `REQ-ALUM`, `REQ-FAM-UNIT` y `REQ-RRHH` lo consumen por esa interfaz pública, nunca leyendo la tabla ni copiando la lista), del que se derivan la regla de validación, el `enum` de OpenAPI y el `CHECK` de la base de datos. El catálogo es **de plataforma**, igual para todos los centros: un centro no añade tipos.
- **`RN-CORE-91` · Par completo** (`OPEN-CORE-48` = A). Tipo y número se informan los dos o ninguno. Un número sin tipo no puede validarse por tipo ni compararse por el índice único (el `NULL` no colisiona); un tipo sin número no identifica a nadie.
- **`RN-CORE-92` · Normalización antes de validar y guardar** (alcance exacto en `OPEN-CORE-49`). El servidor guarda el número **canónico**: sin espacios al principio ni al final y en mayúsculas en todos los tipos; además, en `dni` y `nie`, sin espacios ni guiones intermedios (`12345678-z` → `12345678Z`). La unicidad de `RN-CORE-03` se comprueba sobre el valor normalizado, en la API y en la importación por igual (hoy la importación deduplica en mayúsculas dentro del fichero pero compara en crudo contra la base de datos).
- **`RN-CORE-93` · Revalidación al cambiar cualquiera de los dos.** Un `PATCH /users/{id}` que cambia el tipo, el número o ambos valida el par **resultante** (el campo no enviado se toma del valor guardado), y comprueba la unicidad excluyendo a la propia persona. Vaciar el par se hace enviando los dos a `null`.
- **Sin cambio**: `RN-CORE-03` (unicidad entre personas vivas), el conmutador de `OPEN-CORE-06`, la política de auditoría de `Person` (`Selective`: `document_type` y `document_number` siguen redactados como `identifier`, `datos.md`), `OPEN-CORE-32` = B (ni el tipo ni el número salen en el CSV de usuarios) y `RN-CORE-67` (el listado no los pinta).

##### 14.6.4.4 Almacenamiento

**Propuesta: `text` + `CHECK` sobre `people.document_type`**, con el enumerado PHP de `RN-CORE-90` como fuente. Es el patrón que el proyecto ya usa para enumerados cerrados (`audit_logs.actor_type` y `.event`, `ADR-034 §3`; `data_exports.kind`, `datos.md` Parte D) y respeta `ADR-029` (`text`, no `varchar(n)`).

| Alternativa | Por qué no |
|-------------|------------|
| Tipo `ENUM` de PostgreSQL | `ALTER TYPE … ADD VALUE` es aditivo, pero **no hay `DROP VALUE`**: retirar un tipo obliga a recrear el tipo y reescribir la columna. Ningún enumerado del proyecto lo usa; introducirlo aquí sería la primera excepción sin motivo |
| Tabla de referencia compartida (categoría `reference` de `ADR-033 §7`, como `permissions`/`modules`) | Solo aporta algo si cada tipo necesita metadatos editables o si el catálogo lo gestiona alguien fuera del código. Aquí la validación por tipo **es código** (`DocumentNumberValidator`), así que la tabla duplicaría la lista sin poder sustituirla, y añadiría un comando de sincronización, una clave foránea y una tabla más a `ADR-033 §7`. Reconsiderar si un día los tipos pasan a depender de la comunidad autónoma o del país |

**Añadir un tipo después** es *expand*: nuevo caso del enumerado y `CHECK` sustituido por otro con un valor más (`DROP CONSTRAINT` + `ADD CONSTRAINT … NOT VALID` + `VALIDATE CONSTRAINT`, que no bloquea escrituras). **Retirar un tipo** con personas que lo usan exige migrar sus datos: por eso la propuesta es empezar con el catálogo mínimo.

**Migración en dos entregas** (`CLAUDE.md §9`; compresión posible solo por `OPEN-CORE-52`):

1. **Entrega N (1.9c)**, sin `CHECK`: la aplicación valida contra el catálogo y normaliza al escribir (`RN-CORE-90`-`93`). Una migración de datos normaliza las filas existentes: tipo con variantes reconocibles (`DNI`, `dni`, ` Nie `…) al código canónico, número a su forma canónica. Antes de escribir, **comprueba** que la normalización no crea duplicados entre personas vivas y que no queda ningún tipo sin correspondencia; si encuentra cualquiera de los dos casos, **aborta con un mensaje que enumera los `public_id` afectados** y no toca nada (no hay producción: los datos son de desarrollo y se pueden resembrar; nunca se inventa una correspondencia). Compatible con N-1: la versión anterior acepta cualquier texto, también el canónico.
2. **Entrega N+1** (cuando ya no corra ningún proceso de N-1, incluidos *workers* de `core-imports` con trabajos encolados): `CHECK (document_type IS NULL OR document_type IN (…))` añadido `NOT VALID` y validado después, (el `CHECK` de par **ya existía desde 0.8**, `people_document_type_number_paired`, migración `2026_08_18_101000`: no hay nada que añadir; la entrega N+1 queda en el issue [#312](https://github.com/pirexia/plataforma-educativa/issues/312)). Añadirlo en N haría que una escritura de N-1 con un tipo libre fallase con `500` en vez de `422`.

`academic_year_id`: no aplica (`people` no depende del curso, `ADR-034 §4`). `tenant_id` y el índice único existentes no cambian. Revisor obligatorio: `db-reviewer`.

##### 14.6.4.5 API y OpenAPI

| *Endpoint* | Cambio | Compatibilidad (`ADR-038 §7`) |
|------------|--------|-------------------------------|
| `POST /users` | `person.document_type` validado contra el catálogo (`422`, campo `person.document_type`, código `core.validation.document_type_invalid`); `RN-CORE-91`/`-92` | **Incompatible en sentido estricto**: deja de aceptar valores que hoy acepta. Sin más clientes que la SPA propia y sin producción (`H0`), mismo criterio que S4 (`OPEN-CORE-39`); se anota en `CHANGELOG.md` |
| `PATCH /users/{id}` | Igual, más `RN-CORE-93` | Ídem |
| `GET /users`, `GET /users/{id}`, `GET /me` | Sin cambio de forma; `document_type` devuelve el código canónico | Compatible |
| `POST /user-imports` | Sin cambio en la subida; la validación de filas cambia (§14.6.4.6) | Compatible en el contrato HTTP |
| `PATCH /me` | Sin cambio: no admite documento (`CA-CORE-018`) | — |

- **OpenAPI**: `enum` cerrado en `person.document_type` del esquema de petición y de respuesta, **generado o comprobado contra el enumerado PHP** (`CA-CORE-279`), y descripción del formato por tipo. El esquema de `POST /users/exports` no cambia (`OPEN-CORE-32` = B).
- **Errores nuevos** en el catálogo de `core` (`lang/*/core.php`, cuatro idiomas): `core.validation.document_type_invalid` («El tipo de documento indicado no está admitido.», **sin reflejar el valor recibido**, `security-reviewer` S3) y `core.validation.document_incomplete` («Indica a la vez el tipo y el número de documento, o ninguno de los dos.»). `document_number_invalid` y `document_duplicate` se conservan.
- **Exposición a la SPA**: ninguna ruta nueva. El cliente lleva una constante con los códigos, comprobada contra el enumerado PHP con el mismo test cruzado que `RN-CORE-82` ya exige para las comunidades autónomas (y con la misma salida si la lectura cruzada no es viable en CI: el implementador lo reporta). Un *endpoint* de catálogos es la alternativa de `OPEN-CORE-53`.

##### 14.6.4.6 Importación CSV (1.9c)

- La cabecera **no cambia** (`EXPECTED_HEADER`): ni columnas nuevas ni otro orden.
- `UserImportRowValidator` aplica `RN-CORE-90`-`92` a cada fila. Códigos de incidencia nuevos, en `core.import.*` (cuatro idiomas; su idioma de emisión es el de quien subió el lote, `OPEN-CORE-38` = A): `tipo_documento_no_valido` (columna `document_type`) y `documento_incompleto` (columna `document_type` o `document_number`, la que falte). `formato_invalido`, `duplicado_en_fichero` y `duplicado_en_base_de_datos` se conservan, ahora sobre el valor normalizado.
- Tolerancia en la grafía del código (`dni`, `DNI`, ` Dni `): `OPEN-CORE-50`.
- La pantalla de subida (§14.6.1) muestra, junto a la cabecera copiable, **la lista de códigos admitidos** con su nombre traducido; el manual (`admin.md`) la recoge con el formato de cada tipo.
- `ExecuteUserImport` revalida con las mismas reglas (ya lo hace por `UserImportRowValidator`): un lote validado antes del cambio y ejecutado después se revalida con el catálogo.

##### 14.6.4.7 Interfaz y traducciones

- **Alta y edición** (`UserFormView`): el campo de texto de `document_type` y su pista `core.users.form.documentTypeHint` se sustituyen por un `<select>` con «Sin indicar» (envía `null`) más los códigos de la constante, etiqueta traducida; el número queda deshabilitado mientras el tipo sea «Sin indicar» (comodidad; decide el servidor, `INV-010`). Al editar una persona con un valor anterior al catálogo, el selector **lo conserva como opción** con su código crudo, igual que ya hace con un idioma retirado: no se pierde en silencio y el `PATCH` no lo envía si no se toca. La clave `documentTypeHint` se retira de los cuatro `locales/*.json`.
- **Ficha** (`UserDetailView`): muestra la etiqueta traducida; un código desconocido se pinta crudo (`ADR-038 §7.3`).
- **Filtro `enum` con `label`** (§14.22 punto 2): **el catálogo no lo necesita** —sus etiquetas son claves del cliente (`labelKey`)— y no se usa en ningún filtro, porque el listado de usuarios no filtra por documento. Su ratificación es una cuestión aparte, recogida en `OPEN-CORE-53` (§14.6.4.9).
- **Claves nuevas** en `core.person.documentType.<código>`, propuesta de texto:

| Código | `es` | `en` | `de` | `fr` |
|--------|------|------|------|------|
| `dni` | DNI (documento nacional de identidad) | Spanish national identity card (DNI) | Spanischer Personalausweis (DNI) | Carte nationale d'identité espagnole (DNI) |
| `nie` | NIE (número de identidad de extranjero) | Foreigner identity number (NIE) | Ausländer-Identifikationsnummer (NIE) | Numéro d'identité d'étranger (NIE) |
| `pasaporte` | Pasaporte | Passport | Reisepass | Passeport |
| `otro` | Otro documento de identidad | Other identity document | Anderes Ausweisdokument | Autre pièce d'identité |

  Más `core.person.documentType.none` («Sin indicar» / «Not specified» / «Keine Angabe» / «Non renseigné»). Las filas `pasaporte` y `otro` solo existen si `OPEN-CORE-46` las incluye.

##### 14.6.4.8 Hallazgos en el código actual (fuera del ámbito de esta propuesta; no se corrigen aquí)

Para que la sesión orquestadora abra los issues que correspondan (`CLAUDE.md §5`). Lectura de código, sin ejecución:

| # | Hallazgo | Ficheros | Severidad propuesta |
|---|----------|----------|---------------------|
| F1 | **Duplicado de identidad por mayúsculas en el tipo**: `DocumentNumberValidator` valida `strtoupper($documentType)`, pero `CreateUser`/`UpdateUser` guardan y consultan el tipo **tal como llega**. `DNI` + `12345678Z` y `dni` + `12345678Z` pasan los dos la comprobación de unicidad y el índice único (`RN-CORE-03`). Ocurre también en producción, con el dígito de control activo | `Application/DocumentNumberValidator.php`, `CreateUser.php`, `UpdateUser.php` | **Media** (invariante de negocio incumplida, sin impacto inmediato: no hay producción) |
| F2 | **Cambiar solo el tipo no revalida**: `UpdateUser` valida únicamente si llega `document_number`; un `PATCH` con solo `document_type: "DNI"` sobre un número de pasaporte deja un par que nunca habría pasado el alta | `UpdateUser.php` | **Media** |
| F3 | **Número sin tipo**: se valida con la rama por defecto (no vacío) y se guarda con tipo `NULL`; el índice único no compara `NULL`, así que el mismo número puede repetirse sin límite | `CreateUser.php`, `UserImportRowValidator.php` | **Media** |
| F4 | La importación deduplica **dentro del fichero** en mayúsculas pero compara **contra la base de datos** en crudo: el mismo documento con distinta grafía es duplicado en un sitio y no en el otro | `UserImportRowValidator.php` | Baja (desaparece con F1) |
| F5 | Con el dígito de control desactivado (desarrollo), un número en minúsculas (`12345678z`) pasa el formato y se guarda en minúsculas, distinto para el índice de `12345678Z` | `DocumentNumberValidator.php` | Baja (solo fuera de producción) |
| F6 | §6 de este documento dice «se valida el formato de DNI/NIE/pasaporte»; el código no valida ningún formato de pasaporte. Código y documentación se contradicen (`CLAUDE.md §6.6`) | `funcional.md §6`, `DocumentNumberValidator.php` | **Media** por la regla de `CLAUDE.md §6.6`; se resuelve con `OPEN-CORE-46` |
| F7 | El *docblock* de `DocumentNumberValidator` atribuye a `ADR-034 §1` que el tipo quede «como `text` libre a propósito»; `ADR-034 §1` solo fija el tipo `text` y el índice, no la ausencia de catálogo | `DocumentNumberValidator.php` | Baja |

F1 a F3 los corrige `RN-CORE-90`-`93` **si se aprueba** la propuesta; si no, siguen abiertos por su cuenta.

##### 14.6.4.9 Preguntas abiertas (para el usuario; ninguna se resuelve aquí)

Todas bloquean la implementación del catálogo y, por tanto, **1.9c** (§14.2) salvo donde se dice.

| ID | Pregunta | Bloquea | Recomendación |
|----|----------|---------|---------------|
| `OPEN-CORE-46` | Valores del catálogo | 1.9c | B — **RESUELTA** (2026-10-02) |
| `OPEN-CORE-47` | Grafía de los códigos | 1.9c | A — **RESUELTA** (2026-10-02, ratificada en bloque) |
| `OPEN-CORE-48` | Par tipo-número completo | 1.9c | A — **RESUELTA** (2026-10-02, ratificada en bloque) |
| `OPEN-CORE-49` | Alcance de la normalización del número | 1.9c | A — **RESUELTA** (2026-10-02, ratificada en bloque) |
| `OPEN-CORE-50` | Tolerancia de grafía del código en la importación | 1.9c | A — **RESUELTA** (2026-10-02, ratificada en bloque) |
| `OPEN-CORE-51` | Formato del pasaporte (solo si entra) | 1.9c | A — **RESUELTA** (2026-10-02, ratificada en bloque) |
| `OPEN-CORE-52` | Migración y `CHECK`: dos entregas o una | 1.9c | A — **RESUELTA** (2026-10-02, ratificada en bloque) |
| `OPEN-CORE-53` | Exposición del catálogo a la SPA, y ratificación de `label` del filtro `enum` | 1.9c (catálogo); no (`label`) | A / ratificar — **RESUELTA** (2026-10-02, ratificada en bloque) |
| `OPEN-CORE-54` | Qué es «sin filtrar» en un `enum` con `initial` | **1.9f** | **RESUELTA** (2026-10-04, usuario): A, `initial` es el estado de reposo |
| `OPEN-CORE-55` | `initial` combinado con `urlState` | — | **RESUELTA** (2026-10-04, usuario): A, no se admite en 1.9f |
| `OPEN-CORE-56` | Volver a «página 1, `live`» tras conceder una excepción | **1.9f** | **RESUELTA** (2026-10-04, usuario): A, re-montar con `key`, sin `reset()` |

**`OPEN-CORE-46` · Valores del catálogo.**
- **A** · `dni`, `nie`. Lectura literal de `REQ-CORE-003`. Deja sin forma de registrar a una persona con solo pasaporte (p. ej. un tutor extranjero recién llegado, cuando `REQ-FAM-UNIT` dé cuentas a las familias).
- **B** · `dni`, `nie`, `pasaporte`. Añade lo que §6 de este documento ya preveía (y cierra F6).
- **C** · B más `otro`.

**Recomendación: B.** Cubre a quien no tiene documento español sin abrir una categoría comodín. `otro` (C) sin formato ni control convierte el catálogo en texto libre con otro nombre: cualquier cosa cabe y la unicidad pierde sentido. Como añadir un tipo después es *expand* (§14.6.4.4) y retirarlo no, empezar por B y ampliar con un caso real es lo barato. Es una ampliación sobre `REQ-CORE-003`: decide el usuario.

**`OPEN-CORE-47` · Grafía de los códigos.**
- **A** · Minúsculas (`dni`, `nie`, `pasaporte`), como todos los demás enumerados técnicos del producto (estados, `kind`, `event`, ámbitos).
- **B** · Mayúsculas (`DNI`, `NIE`, `PASAPORTE`), como los valores que hoy reconoce el validador y como se escriben las siglas.

**Recomendación: A**, por coherencia con `ADR-038 §3.2` tal como lo aplica el resto de la API; la etiqueta visible ya muestra las siglas en mayúsculas. Los datos existentes son de desarrollo y los normaliza la migración en cualquiera de las dos.

**`OPEN-CORE-48` · ¿Tipo y número, los dos o ninguno?**
- **A** · Sí (`RN-CORE-91`), en la API, en la importación y, en N+1, con `CHECK`.
- **B** · No: se mantiene que el par solo se comprueba si hay número (`RN-CORE-03` literal), y un número sin tipo sigue guardándose con tipo `NULL`.

**Recomendación: A.** B mantiene F3 abierto: un número sin tipo no se valida por formato y escapa del índice único.

**`OPEN-CORE-49` · Normalización del número.**
- **A** · La de `RN-CORE-92`: recorte y mayúsculas en todos los tipos; además, sin espacios ni guiones intermedios en `dni`/`nie`.
- **B** · Solo recorte y mayúsculas; un guion o espacio intermedio en un DNI es error de formato.
- **C** · Sin normalización: se rechaza todo lo que no venga ya en forma canónica.

**Recomendación: A.** Es lo que una secretaría teclea o pega desde otro sistema (`12.345.678-Z` no se propone: los puntos no se quitan, serían un error de formato). B y C trasladan al usuario una corrección que el servidor puede hacer sin ambigüedad.

**`OPEN-CORE-50` · Grafía del código de tipo en el CSV.**
- **A** · La importación acepta el código sin distinguir mayúsculas y con espacios alrededor, y guarda el canónico; la API (cliente técnico) exige el código exacto.
- **B** · Exacto en los dos: `DNI` en una hoja da `tipo_documento_no_valido` si el canónico es `dni`.

**Recomendación: A.** La hoja la edita una persona; la API, un programa que lee OpenAPI. Más tolerancia en la importación que en la API no rompe `INV-010`: el servidor sigue decidiendo.

**`OPEN-CORE-51` · Formato del pasaporte** (solo si `OPEN-CORE-46` lo incluye).
- **A** · Alfanumérico `[A-Z0-9]`, de 1 a 32 caracteres tras normalizar, sin control. No supone nada sobre el país emisor.
- **B** · Límite de la zona MRZ de ICAO 9303 (9 caracteres alfanuméricos).

**Recomendación: A.** Ningún requisito fija un país, y B rechazaría números válidos que en la MRZ se extienden al campo opcional. Si el usuario quiere distinguir el país emisor, es un campo más (`ADR-034 §1`: añadir columna es *expand*) con su propia base legal, fuera de esta propuesta.

**`OPEN-CORE-52` · Migración y `CHECK`.**
- **A** · Dos entregas (§14.6.4.4): validación y normalización en 1.9c; `CHECK` en la entrega siguiente que toque migraciones, con un paso del plan que lo nombre.
- **B** · Todo en 1.9c, porque no hay producción ni versiones conviviendo; se registra la excepción a `CLAUDE.md §9` como discrepancia.
- En las dos, la migración de datos **aborta** ante tipos sin correspondencia o duplicados creados por la normalización, en vez de inventar una correspondencia.

**Recomendación: A.** Cuesta una migración más y no deja un precedente de saltarse *expand/contract* «porque aún no hay producción», que es exactamente cuando se fija la costumbre.

**`OPEN-CORE-53` · Exposición del catálogo a la SPA y `label` del filtro `enum`.**
- **A** · Constante en el cliente con test cruzado contra el enumerado PHP (precedente `RN-CORE-82`).
- **B** · *Endpoint* de catálogos (p. ej. dentro de `GET /me` o uno propio), sin constante en el cliente.

**Recomendación: A**: un catálogo de cuatro valores que solo cambia con un despliegue no justifica una petición más, y el test impide la divergencia silenciosa (§14.15). **Aparte**, y sin relación técnica con el catálogo: el campo `label` que 1.9b añadió a `DataTableEnumFilter.options` (§14.22 punto 2) no figura entre las ampliaciones de `OPEN-CORE-40`; **recomendación: ratificarlo** e incorporarlo a la lista cerrada de §13.7, porque es aditivo, lo exige `RN-CORE-62`/`-63` para el filtro de rol (nombres ya traducidos por el servidor) y la alternativa —claves de cliente para nombres de roles personalizados— no existe. Si el usuario no lo ratifica, el filtro de rol de 1.9b tendría que retirarse o mostrar ULID.

##### 14.6.4.10 Criterios de aceptación propuestos

Se escriben ahora para que sean verificables; los que dependen de una pregunta lo dicen y se reescriben al resolverla. Pest salvo los marcados **[Vitest]**.

- **`CA-CORE-273`** [`RN-CORE-90`, `INV-010`] · **Dado** `POST /users` con `person.document_type` fuera del catálogo (p. ej. `"carnet"`), **cuando** se envía, **entonces** `422` con error en `person.document_type` y código `core.validation.document_type_invalid`, y no se crea ninguna `Person` ni `User`; **y** lo mismo con `PATCH /users/{id}`, sin modificar la persona.
- **`CA-CORE-274`** [`RN-CORE-90`, `OPEN-CORE-06`, `REQ-SEED-005`] · **Dado** cada código del catálogo, **cuando** se da de alta una persona con un número de formato válido y otro de formato inválido para ese tipo, **entonces** el primero se acepta y el segundo responde `422` en `person.document_number`; **y dado** `dni` o `nie` con formato válido y letra de control incorrecta, `422` con la comprobación de dígito activa y `201` con ella desactivada (fuera de producción) — el formato se exige en los dos casos.
- **`CA-CORE-275`** [`RN-CORE-92`, `RN-CORE-03`, F1, F5] *(según `OPEN-CORE-49`/`-50`)* · **Dado** una persona viva con tipo `dni` y número `12345678Z` (valores de prueba de `REQ-SEED-005`), **cuando** se da de alta otra con número ` 12345678-z `, **entonces** `422` `core.validation.document_duplicate`; **y** la primera persona tiene guardado exactamente el código canónico y `12345678Z`.
- **`CA-CORE-276`** [`RN-CORE-91`, F3] · **Dado** `POST /users` con número y sin tipo, **entonces** `422` `core.validation.document_incomplete`; **y** con tipo y sin número, el mismo `422`; **y** sin ninguno de los dos, `201`.
- **`CA-CORE-277`** [`RN-CORE-93`, F2] · **Dado** una persona con tipo `pasaporte` y número `AB1234567` *(o `nie` si `OPEN-CORE-46` = A)*, **cuando** `PATCH /users/{id}` envía solo `person.document_type` con el código de `dni`, **entonces** `422` en `person.document_number` y la persona no cambia.
- **`CA-CORE-278`** [`RN-CORE-90`-`92`, §14.6.4.6] · **Dado** un CSV con una fila de tipo desconocido, una con el código en otra grafía *(según `OPEN-CORE-50`)* y dos filas con el mismo documento escrito con y sin guion, **cuando** se valida, **entonces** la primera da `tipo_documento_no_valido` en la columna `document_type`, la segunda no da error y, al ejecutarse, guarda el código canónico, y la cuarta da `duplicado_en_fichero`; **y** una fila cuyo documento coincide, normalizado, con el de una persona viva da `duplicado_en_base_de_datos`.
- **`CA-CORE-279`** [`RN-CORE-90`, `INV-006`] · **Dado** la especificación OpenAPI, **entonces** `person.document_type` tiene en el esquema de petición de `POST /users` y `PATCH /users/{id}` y en el de respuesta del usuario un `enum` exactamente igual a los casos del enumerado PHP, en el mismo orden.
- **`CA-CORE-280`** [`RN-CORE-90`, §14.6.4.4] · **Dado** una base de datos con filas de tipo `DNI`, `dni` y ` Nie ` y números con espacios, **cuando** se ejecuta la migración de normalización, **entonces** quedan con el código y el número canónicos; **y dado** una fila con un tipo sin correspondencia o dos personas vivas que la normalización convertiría en duplicadas, la migración falla, enumera los `public_id` afectados y no modifica ninguna fila. **Y**, en la entrega del `CHECK` *(según `OPEN-CORE-52`)*, un `INSERT` directo con un tipo fuera del catálogo falla con violación de restricción.
- **`CA-CORE-281`** [`RN-CORE-90`, `RN-CORE-65`] **[Vitest]** · **Dado** el formulario de alta, **entonces** el tipo de documento es un selector con «Sin indicar» más exactamente los códigos de la constante, con etiqueta traducida; con «Sin indicar» el número está deshabilitado y el cuerpo de `POST /users` no contiene ni tipo ni número; **y dado** la edición de una persona con un tipo anterior al catálogo, el selector lo muestra como opción con su código crudo y, si no se toca, el `PATCH` no envía `person.document_type`.
- **`CA-CORE-282`** [`RN-CORE-90`, `INV-009`] **[Vitest]** *(según `OPEN-CORE-53`)* · **Dado** la constante de tipos del cliente y el enumerado PHP del servidor, **entonces** contienen exactamente los mismos códigos, y cada código más `none` tiene etiqueta en `es`, `en`, `de` y `fr`; **y** la clave `core.users.form.documentTypeHint` ya no existe en ningún `locales/*.json`.
- **`CA-CORE-283`** [`ADR-038 §7.3`] **[Vitest]** · **Dado** la ficha de un usuario con tipo `nie`, **entonces** muestra la etiqueta traducida y no el código; **y dado** un código que el cliente no conoce, muestra el código crudo.
- **`CA-CORE-284`** [§14.6.4.6, `RN-CORE-71`] **[Vitest]** · **Dado** el formulario de subida de importación, **entonces** muestra, junto a la cabecera, la lista de códigos de tipo de documento admitidos con su nombre traducido.
- **`CA-CORE-285`** [`INV-009`, `ADR-038 §6.3`] · **Dado** los cuatro `lang/*/core.php` del servidor, **entonces** existen en `es`, `en`, `de` y `fr` `core.validation.document_type_invalid`, `core.import.tipo_documento_no_valido` y, `core.validation.document_incomplete` y `core.import.documento_incompleto`.

**Nota de numeración**: `RN-CORE-90` a `-93` y `CA-CORE-273` a `-285` se han tomado como siguientes libres según este documento (último `RN-CORE-89`, §14.14; `CA-CORE-270`-`272` ocupados según la sesión orquestadora, no localizados en este fichero). `OPEN-CORE-46` a `-53`, como siguientes a `OPEN-CORE-45`. Si alguno está ya usado en otro documento del módulo, se renumera antes de aprobar.

### 14.7 Auditoría (`core-audit`)

Tabla `core.audit_logs`, **modo `cursor`** (`ADR-038 §4.2`), «Cargar más», tope de 1.000 filas (`RN-CORE-52`), estado en la URL (sin `cursor`, `RN-CORE-54`), **sin búsqueda** (el *endpoint* no acepta `q`) y **sin columnas ordenables** (orden fijo `occurred_at DESC, id DESC`, sin `sort`).

| `id` | Cabecera | Contenido | Tarjeta |
|------|----------|-----------|---------|
| `occurred_at` | Fecha y hora | Instante con `Intl`, zona del navegador (`OPEN-CORE-35` = A). `rowHeader` | `title` |
| `event` | Operación | Etiqueta traducida de los **nueve** valores (`datos.md`, `ADR-039`; ver hallazgo 9 de §14.16), rama por defecto con el código crudo (`ADR-038 §7.3`) | `subtitle` |
| `actor` | Usuario | `actor.display_name`; para `actor_type ≠ user`, la etiqueta traducida del tipo (sistema, consola, importación, plataforma, anónimo) | `field` |
| `auditable_type` | Entidad | Etiqueta traducida del alias si existe en el catálogo del cliente; si no, el alias crudo | `field` |
| `auditable_public_id` | Identificador | ULID, oculta por defecto | `field` |
| `ip_address` | IP | Oculta por defecto | `field` |
| `request_id` | Petición | Oculta por defecto | `field` |
| `actions` | — | «Ver cambios» | `actions` |

- **`RN-CORE-76` · Filtros de auditoría** (`REQ-CORE-005`: «fecha, usuario, tipo de operación, módulo»):
  - **Fecha**: `dateRange` con `id = occurred_at` → `occurred_at_from`/`occurred_at_to`. Como el parámetro es `TIMESTAMPTZ`, la función de petición del módulo convierte el día elegido (inicio del día «desde», fin del día «hasta», inclusivos) a instante ISO 8601 en la zona del navegador (`OPEN-CORE-35` = A, resuelta; `§13.7`); se documenta en el manual.
  - **Tipo de operación**: `enum` `event` con los nueve valores.
  - **Tipo de actor**: `enum` `actor_type` con los seis valores (requiere varios valores en servidor, §14.11).
  - **Usuario**: `actor_id`. **`OPEN-CORE-33` = C (resuelta)**: tipo de filtro nuevo **`entity`** en `src/data-table` (aditivo; amplía la lista cerrada de §13.7), selección única por búsqueda asíncrona con función aportada por el consumidor (`GET /users?q=`, requiere `usuario.leer`; sin él el filtro no se ofrece, `RN-CORE-62`), serializado como `actor_id=<ulid>`; la etiqueta se resuelve con `GET /users/{id}` al restaurar desde la URL. Además, «Ver su actividad» en la ficha del usuario (`auditoria.leer`) navega a `core-audit` con `actor_id` y el indicador «Filtrado por: {nombre}» con acción de quitarlo.
  - **Módulo** (`module`) y **tipo de entidad** (`auditable_type`): `OPEN-CORE-34` = B (resuelta): las opciones salen de `GET /audit-logs/facets` (S10, `auditoria.leer`, `api.md §14.4`); el cliente traduce con rama por defecto.
- **`RN-CORE-77` · Detalle de cambios.** «Ver cambios» abre un panel modal (`sheet` ya vendorizado en 1.8, `role="dialog"`, foco atrapado, `Esc` cierra, foco vuelve al botón) con `changes` **tal como llega** (`CA-CORE-052`): por atributo, `from` → `to` como texto (un valor no escalar, p. ej. `active_locales`, como JSON compacto); una entrada redactada se muestra como «valor no registrado» más el motivo traducido (`secret`, `special`, `identifier`, `oversized`) y, si existen, «antes vacío / después vacío» a partir de `from_empty`/`to_empty`. **Nunca** intenta reconstruir un valor redactado ni pide nada más al servidor. `changes: null` (eventos `read`, `exported`, `login`…) muestra «sin cambios registrados». El nombre del atributo se muestra tal cual (es el nombre técnico de la columna): traducir los atributos de todos los modelos auditables de todos los módulos es un catálogo sin dueño que este paso no inventa.
- **`RN-CORE-78` · Exportación de auditoría.** `canExport` = `auditoria.exportar`; la solicitud lleva exactamente los filtros estructurados del listado (`api.md §8`, #267 ya resuelto). Al tope de `RN-CORE-52`, el aviso ofrece exportar.

### 14.8 Roles, solo lectura (`core-roles`)

Tabla `core.roles`, modo `page`, sin filtros, sin búsqueda, sin orden (el *endpoint* no los acepta; orden por `code`). Columnas: nombre (`rowHeader`), tipo (del sistema / personalizado, de `is_system`), MFA obligatorio, acceso a datos especiales, usuarios (`users_count`, alineado a la derecha).

- **`RN-CORE-75` · Solo lectura.** Ninguna acción de escritura sobre roles en 1.9b, **aunque** la API de 1.5 las admita y el usuario tenga `rol.crear`/`rol.actualizar`/`rol.eliminar`: el editor es `1.5b`. La edición de `mfa_required` ya existe en `/administracion/mfa` y no se duplica.
- **`RN-CORE-63` · Texto traducido por el servidor.** `GET /roles` (`name` de los roles del sistema) y `GET /users` (`roles[].name`) devuelven texto traducido en el idioma de la petición (`api.md §3`). Las tablas que lo muestran **vuelven a pedir la página actual** al cambiar el idioma de la interfaz (precisión de §13.12, último punto, que preveía «en 1.9b no se prevé ninguno»: sí lo hay).
- **Detalle de un rol** con sus concesiones: **fuera de 1.9d** (`OPEN-CORE-36` = A, resuelta): llega con la matriz de `1.5b`. No se crea `core-role-detail` ni se llama a `GET /roles/{id}`.

### 14.9 Configuración del centro (`core-settings`)

Un formulario por grupo de `GET /tenant/settings` (`api.md §2`): **Regional** (idioma por defecto, idiomas activos, zona horaria, moneda, comunidad autónoma), **Fiscal** (razón social, NIF/CIF, dirección, código postal, municipio, provincia, país) y **Paleta** (colores primario y secundario). El grupo **Seguridad** (`security.*`, de `REQ-AUTH`) se resuelve por `OPEN-CORE-37` = B: la pantalla incluye solo las claves `security.*` que **no** edite ya `/administracion/mfa` (el implementador lo comprueba antes de escribir y lo reporta) y enlaza a esa pantalla para las demás; no se duplica ningún campo. Enlace a «Activos de marca».

- **`RN-CORE-79` · Lectura o edición por permiso.** Con `configuracion.leer` sin `configuracion.actualizar` (p. ej. `direccion`, `permisos.md §4.1`), la pantalla se pinta en **solo lectura** (valores como texto, sin campos editables ni botón de guardar). Con `configuracion.actualizar`, cada grupo se guarda por separado con su propio `PATCH` que envía **solo las claves modificadas de ese grupo** (`RN-CORE-65`), lo que reduce el efecto de «la última escritura gana» de §6 entre dos administradores que editan grupos distintos.
- **`RN-CORE-80` · Paleta.** Mientras se edita, la vista muestra una **vista previa** del par primario/secundario y su razón de contraste calculada con las funciones puras de 1.7 (`design-system/color/contrast.ts`), con el umbral de 4,5:1 (`RUX-BRAND-006`). Es comodidad: el servidor es la autoridad y un `422 contrast_insufficient` muestra `ratio` y `required` del servidor. La vista previa **no** aplica la paleta al documento (`applyBrandPalette` solo lo llama la capa B). Tras guardar, se llama a `useTenantBranding().refresh()` (`docs/design-system.md §7`) para que el *shell* adopte la paleta sin recargar.
- **`RN-CORE-81` · Idiomas.** «Idiomas activos» es un grupo de casillas de los cuatro de `ADR-021`, al menos una marcada; «idioma por defecto» solo ofrece los marcados. Si se retira el idioma en el que el usuario ve la interfaz, la vista avisa antes de guardar de que pasará a ver el idioma por defecto (`RN-CORE-34`). Tras guardar, `refresh()` de la capa B (el selector de idioma de 1.8 lee de ahí los activos).
- **`RN-CORE-82` · Catálogos en el cliente.** Zona horaria: lista de `Intl.supportedValuesOf('timeZone')` con búsqueda por texto (el servidor valida con `timezone:all`). Moneda: campo de tres letras mayúsculas (el servidor solo exige `^[A-Z]{3}$`; no hay catálogo de monedas que inventar). Comunidad autónoma: **constante en el cliente con exactamente los códigos de `App\Modules\Core\Domain\AutonomousCommunity::CODES`** y su nombre en los cuatro idiomas, más la opción «sin indicar» (`null`). Un test de Vitest lee ese fichero PHP del repositorio y comprueba que los códigos coinciden; si la lectura cruzada entre aplicaciones no es viable en CI, el implementador lo reporta y se decide otra vía.
- **Actualización 2026-10-05 (issues #323 y #324, §14.27), grupo Seguridad.** (1) Al guardar `mfa_allowed_methods`, la pantalla parte de la lista que devolvió el servidor y **solo alterna `email`**: cualquier otro método que el servidor ya tuviera se conserva, no se fija en el cliente. (2) `session_timeout_minutes` y `mfa_grace_period_days` **no se envían si no son enteros** (texto vacío, decimales, no numérico): el cliente muestra bajo el campo `core.settings.errors.notInteger` («Introduce un número entero.», en es/en/de/fr) y no hace la petición. El **rango** (5-480 y 1-90) sigue decidiéndolo solo el servidor (`INV-010`).

### 14.10 Activos de marca (`core-branding-assets`)

Tres bloques (logo, *favicon*, fondo de acceso), cada uno con la imagen actual (URL firmada de `GET /tenant/settings`), los tipos y tamaños admitidos (`api.md §2`: logo SVG/PNG/WebP ≤ 1 MB; *favicon* PNG/ICO/SVG ≤ 256 KB; fondo JPEG/PNG/WebP ≤ 3 MB, sin SVG) y, con `configuracion.actualizar`, «Sustituir» y «Eliminar».

- **`RN-CORE-83` · Activos.** Las comprobaciones de tipo y tamaño en cliente son comodidad; el servidor decide por **contenido** (`413`, `415`, `422`, `RN-CORE-18`) y la vista muestra su mensaje. Eliminar pide confirmación (`RN-CORE-64`). Tras sustituir o eliminar: se vuelve a pedir `GET /tenant/settings` y se llama a `useTenantBranding().refresh()` (logo del *shell*, *favicon*). La imagen se pinta con `alt` traducido («Logotipo actual de {centro}»; el fondo es decorativo). Si una URL firmada falla al cargar (caducada), la vista vuelve a pedir la configuración **una sola vez** y, si vuelve a fallar, muestra el estado de error sin bucle.
- La SPA **nunca** sanea ni inspecciona el SVG: lo hace el servidor (`§4.2`). La vista previa local antes de subir (`URL.createObjectURL` del fichero elegido) **no se hace**: además de estar vetado por `CA-CORE-192`, pintaría en el origen del centro un SVG sin sanear. La vista previa es la del activo ya guardado.

### 14.10b Módulos contratados, solo lectura (`core-modules`, `OPEN-CORE-31` = B)

Estado del servidor verificado (2026-10-01, `ModulesController::index()`): `GET /modules` exige `modulo.leer`, **no está paginado** (devuelve `{"data": [...]}` sin `meta`), recorre **todo el catálogo** de módulos no retirados ordenado por `code` e incluye los no contratados con `enabled: false` y `public_id: null` (`api.md §6`); `name` viene **traducido por el servidor**; nunca devuelve el `reason` interno del proveedor (`RN-BO-82`).

- **`RN-CORE-87` · Pantalla de módulos de solo lectura.** Tabla `core.modules` con el componente de 1.9 (`RN-CORE-53`), con una función de petición que envuelve la respuesta sin paginar en una única página (`meta = {current_page: 1, per_page: n, total: n, last_page: 1}`), la misma técnica de `RN-CORE-74`; sin búsqueda, filtros, orden ni exportación. Columnas: nombre (`rowHeader`), estado (contratado / no contratado, traducido en el cliente) y **fecha de alta** (`enabled_at`, con `Intl`), que es el «aviso de las nuevas altas» de `REQ-CORE-002` en este paso (`ADR-045`: informativo, sin acción requerida). Filas mostradas (`OPEN-CORE-45` = A): **solo las contratadas** (`enabled: true`), filtradas en el cliente sobre la respuesta completa de `GET /modules` (no es filtrado de seguridad). **Ninguna acción de escritura**: la pantalla nunca llama a `PATCH /module-subscriptions/{id}`, aunque el usuario tenga `modulo.actualizar`, ni muestra `settings` ni `phase`. La tabla recarga al cambiar de idioma (`RN-CORE-63`, por `name`).

### 14.10c Perfil propio (`core-profile`, `OPEN-CORE-31` = B)

Estado del servidor verificado (2026-10-01, `MeController`): `PATCH /me` **no tiene permiso granular**: se autoriza por identidad del portador de la sesión (`permisos.md §5.2`) y solo acepta `person.locale`, `person.contact_email` (correo válido o `null`) y `person.contact_phone` (texto de hasta 32 caracteres o `null`); cualquier otro campo se ignora en silencio (`CA-CORE-018`). Responde con el mismo recurso que `GET /me`.

- **`RN-CORE-88` · Perfil propio de autoservicio.** Ruta `core-profile` en la sección `cuenta`, con `meta.permissions: []` (§14.3.1). Muestra en solo lectura el nombre completo y el correo de acceso (este último con la explicación de que no se cambia desde aquí, §4.9), y en un formulario editable **solo** el correo y el teléfono de contacto. El idioma **no** se edita aquí: ya lo cambia el selector del menú de usuario de 1.8 (§12.3.6), y dos controles para el mismo dato divergirían. Los datos se leen del estado de sesión ya cargado (`RN-CORE-26`): la pantalla **no** pide `GET /me` por su cuenta.
- **`RN-CORE-89` · Guardado del perfil.** `PATCH /me` envía solo las claves modificadas de `person` (`RN-CORE-65`; vaciar un campo envía `null`); un `422` pinta los mensajes del servidor por campo. Con `200`, el estado de sesión se sustituye por la respuesta del propio `PATCH`, sin segunda petición (§12.3.4), y se muestra un mensaje con `role="status"`. La interfaz **nunca** envía `email`, `status`, `roles` ni ningún otro campo.

### 14.11 Cambios en servidor

Detalle de contratos en `api.md §14`. Recuento: **dos *endpoints* nuevos** (S1; S10, `OPEN-CORE-34` = B, resuelta), **una migración** (S2), **tres correcciones** (S3 y S4, que alinean el código con el contrato documentado —S4 cambia la respuesta de `fallida`, por eso va en negrita—, y S9, `OPEN-CORE-38` = A, resuelta) y **cuatro cambios compatibles** en el sentido de `ADR-038 §7` (S5 a S8). 1.9e no añade nada al servidor: módulos y perfil consumen `GET /modules` y `PATCH /me` tal como están.

| # | Cambio | Tipo | Sub-paso |
|---|--------|------|----------|
| S1 | **`POST /api/v1/users/exports`** (nuevo) y trabajo `GenerateUserExport` en `core-exports` | Nuevo | 1.9b |
| S2 | `data_exports.kind`: añadir `users` al `CHECK` (*expand*, `datos.md` Parte D) | Migración | 1.9b |
| S3 | `GET /data-exports/{id}`: autorización **por `kind`** (`audit_logs` → `auditoria.exportar`, `users` → `usuario.exportar`; `kind` sin correspondencia → `403`) en lugar de `auditoria.exportar` fijo | Corrección (necesaria para S1) | 1.9b |
| S4 | `GET /data-exports/{id}`: una exportación `fallida` responde **`200` con `status: "fallida"` y `error_code`**, no `409` (`OPEN-CORE-39`) | **Corrección de contrato** | 1.9b |
| S5 | `GET /users`: `sort` admite `-email` | Compatible | 1.9b |
| S6 | `GET /users/{id}?include_deleted=true`, exigiendo además `usuario.eliminar` (cumple `CA-CORE-014`) | Compatible | 1.9b |
| S7 | Valores múltiples por comas (`ADR-038 §5.2`) en `GET /invitations?status=`, `GET /users?locale=` (1.9b) y `GET /audit-logs?actor_type=`/`module=` (1.9d), con la misma regla en `POST /users/exports` y `POST /audit-logs/exports` (paridad, `ADR-054 §8.2`) | Compatible | 1.9b / 1.9d |
| S8 | `UserImportResource` devuelve `created_at` (ya documentado en `api.md §7`) **y `send_invitations`** (ampliación aditiva decidida por el usuario el 2026-10-03: la confirmación de ejecutar, `RN-CORE-73`/`CA-CORE-235`, lo lee siempre de la API) | Compatible | 1.9c |
| S9 | Idioma de `message` en `ValidateUserImport` (#285, `OPEN-CORE-38` = A, resuelta) | Corrección | 1.9c |
| S10 | *Endpoint* de facetas de auditoría (`OPEN-CORE-34` = B, implementado) | Nuevo | 1.9d |

#### 14.11.1 `POST /users/exports`: norma aplicable

- **`RN-CORE-85` · Exportación de usuarios en servidor.** Cumple entera la norma de `ADR-054 §8`-`§10` y `ADR-055`, sin excepciones: permiso `usuario.exportar` (ámbito `todos`, el único que admite, `permisos.md §2`); `include_deleted=true` exige además `usuario.eliminar`, igual que el listado; paridad exacta de filtros con `GET /users` (`status`, `role`, `locale`, `include_deleted`), con test que recorre los parámetros de filtro del listado en OpenAPI; **`q` ⇒ `422`** con un código propio del recurso en el catálogo de `core` (`RN-CORE-58`; el nombre de la clave lo fija el implementador y se documenta en OpenAPI); sin `sort`; generación en cola (`INV-012`) con `CsvWriter` (`RN-CORE-47`/`48`), tipos de columna declarados, dialecto de `ADR-054 §10.3`; cabeceras y valores técnicos sin traducir (`RN-CORE-59`) — el trabajo **no** llama a `__()`/`trans()`; límite de filas con `422` (`RNF-LIM-004`, §14.15); auditoría `exported`; URL firmada; caducidad de siete días; nombre de fichero `<public_id>.csv`. **Esquema del fichero** (`OPEN-CORE-32` = B, resuelta el 2026-10-01), documentado igual en OpenAPI y en `api.md §14.1`:

  | # | Columna | Origen en la API (`GET /users`) | Tipo (`CsvColumnType`, `ADR-054 §10.2`) | Vacío |
  |---|---------|----------------------------------|------------------------------------------|-------|
  | 1 | `public_id` | `public_id` | Texto (ULID) | Nunca |
  | 2 | `status` | `status` (código técnico: `pendiente`, `activo`, `inactivo`) | Texto | Nunca |
  | 3 | `deleted_at` | `deleted_at` | Instante ISO 8601 con desfase | Si no está dado de baja |
  | 4 | `created_at` | `created_at` | Instante ISO 8601 con desfase | Nunca |
  | 5 | `email` | `email` (correo de acceso) | Texto | Nunca |
  | 6 | `given_name` | `person.given_name` | Texto | Nunca |
  | 7 | `family_name_1` | `person.family_name_1` | Texto | Nunca |
  | 8 | `family_name_2` | `person.family_name_2` | Texto | Si no tiene |
  | 9 | `contact_email` | `person.contact_email` | Texto | Si no tiene |
  | 10 | `contact_phone` | `person.contact_phone` | Texto (nunca entero: un `+34…` es texto y se neutraliza, `RN-CORE-48`) | Si no tiene |
  | 11 | `locale` | `person.locale` (`es-ES`, `en`, `de`, `fr`) | Texto | Nunca |
  | 12 | `roles` | `roles[].code`, **códigos** técnicos (nunca `name`) ordenados alfabéticamente y unidos con `\|`, como en la cabecera de importación (`api.md §7`) | Texto | Si no tiene roles |

  - **Orden de las filas**: `family_name_1`, después `given_name`, después `public_id` (desempate único), ascendente, con la colación de la base de datos. No depende del `sort` de la pantalla (`ADR-054 §8.1`).
  - **Columnas que el fichero NO contiene, por decisión del usuario**: `document_type`, `document_number` y `birth_date`, ni ninguna otra que no esté en la tabla (`email_verified_at`, `updated_at`…). Motivo: minimización; el listado de usuarios incluye a cuentas de alumnado menor de edad cuando existan (`INV-008`), y un documento de identidad o una fecha de nacimiento exportados en bloque salen del control del sistema. **Añadirlas después** sería un cambio **aditivo** (columnas al final, `ADR-055 §2.4`), pero exige una **decisión expresa del usuario** con su base legal (`INV-008`), no solo una petición de un centro; no lo decide ningún paso por su cuenta.
  - **Consecuencia aceptada**: el fichero no coincide con la cabecera de importación (le faltan tres columnas y le sobran cuatro), así que no sirve para reimportar sin editarlo. Ningún requisito pide esa ida y vuelta.
- **`RN-CORE-86` · `GET /data-exports/{id}` por `kind`.** El permiso que exige el detalle de una exportación es el del recurso exportado, resuelto por su `kind` con una correspondencia cerrada en código; un `kind` sin correspondencia se deniega (`RPERM-011`). Sigue exigiendo además ser el solicitante (`permisos.md §8`). Se mantiene `409` para «aún no está lista» (el cliente de 1.9 ya lo trata así) y `410` para caducada; `fallida` según S4.

### 14.12 Precisiones a §13 (sin reabrirlo)

1. **`CA-CORE-207`** dice «ningún generador de `apps/api/app` referencia `__()`/`trans()`». `ADR-055 §1` excluye expresamente de esa regla el informe de errores de importación (`report.csv`), que **sí** debe llevar un mensaje legible. Al implementar `CA-CORE-207` en 1.9b, la comprobación se aplica a los **generadores de CSV de datos** (los que escriben un artefacto de `data_exports`), no a `ValidateUserImport`. Si el usuario prefiere corregir el texto de `CA-CORE-207`, es una edición de §13 que esta especificación no hace por su cuenta.
2. **§13.12, último punto** («en 1.9b no se prevé ninguno» *endpoint* con texto traducido en las filas): sí los hay, `GET /users` y `GET /roles` (`RN-CORE-63`).

### 14.13 Migración de las tres tablas exceptuadas de `RN-CORE-53`

Criterio común, el mismo que §13.15 aplicó a `MfaComplianceArea.vue`:

- **`RN-CORE-84` · Migración con paridad.** Mismas peticiones (salvo `per_page`, que pasa a enviarse explícito, §13.24 punto 5), mismas acciones con los mismos *endpoints*, mismos mensajes y mismo tratamiento de errores. Cambia el aspecto por construcción (barra de filtros, paginador, tarjetas, valor vacío común de `dataTable.emptyValue`, nombres accesibles de acción por fila con la identidad de la fila, WCAG 2.4.6). **En el mismo *commit*** que migra cada vista, su ruta se retira de la lista de excepciones del test de `RN-CORE-53` (`CA-CORE-200`), que de otro modo falla. Ninguna de las tres declara estado en la URL (paridad, como `CA-CORE-206`). Los tests preexistentes de cada vista siguen en verde. Los issues Baja que la reescritura elimine **por construcción** en esa vista (#90 el `'—'` literal, #259 el `import { useI18n } from 'vue-i18n'`) quedan resueltos para esa vista y se anota en el issue; no se buscan ni corrigen en otras vistas (`CLAUDE.md §5`).

| Vista | Modo | Particularidades |
|-------|------|------------------|
| `MfaExemptionsArea.vue` (`/administracion/mfa`, área 4) | `page` | Filtro de estado **de selección única** con valor inicial `live` y opción «todos», con la ampliación aditiva del filtro `enum` (`multiple: false`, `initial`) de `OPEN-CORE-40` = A, construida en 1.9f. Formulario de concesión fuera de la tabla, sin cambios. La confirmación de revocación pasa al diálogo de `RN-CORE-64`. `tableId` `auth.mfa_exemptions` |
| `AdminSsoView.vue` (`/administracion/sso`) | `page` | Hoy pide `per_page=100` y no pagina: pasa a paginar de 25 en 25 con el paginador del componente (cambio por construcción). La confirmación de borrado con `window.confirm` se sustituye por el diálogo de `RN-CORE-64` (`OPEN-CORE-42` = A), con el aviso adicional para SAML que ya existe. `tableId` `auth.identity_providers` |
| `SessionsView.vue` (`/cuenta/sesiones`) | `page` | Hoy pinta solo la primera página de `GET /auth/sessions`: pasa a paginar. «Cerrar las demás sesiones» queda fuera de la tabla. Las confirmaciones (por fila y de «cerrar las demás») pasan al diálogo de `RN-CORE-64` (`OPEN-CORE-42` = A), que asume la gestión de foco que hoy hace la vista a mano. IP nula: valor vacío común. Revocar la sesión actual sigue llevando a `/entrar`. `tableId` `auth.sessions` |

> **Especificación propia de 1.9f** (§14.13.1 a §14.13.7, añadida el 2026-10-04 sobre el código real de las tres vistas, sus tests y `src/data-table`). No reabre `RN-CORE-84` ni la tabla de arriba: las concreta. Lo que no se puede concretar sin decisión del usuario va a §14.13.6 y **bloquea solo lo que allí se dice**.

#### 14.13.1 Estado de partida verificado (2026-10-04)

| Vista | Petición actual | Acciones y confirmación actuales | Errores actuales | Tests actuales |
|-------|-----------------|----------------------------------|------------------|----------------|
| `MfaExemptionsArea.vue` (`auth/components/admin/`) | `GET /mfa-exemptions?state=<valor>&page=<n>` (`listMfaExemptions`), **sin `per_page`**; valor inicial `live`, «todos» no envía `state`. Paginador propio con `last_page` | «Conceder» abre un formulario fuera de la tabla (`POST /mfa-exemptions`); tras `201`, vuelve a la **página 1 con `state=live`**. «Revocar» solo en filas `live`, con confirmación en línea dentro de la celda (`DELETE /mfa-exemptions/{id}`); tras `204`, recarga **con el mismo filtro y la misma página**. Filas no `live`: `'—'` literal en la celda de acciones. Ningún control se oculta por permiso (`REQ-AUTH/permisos.md §D.6.3` regla 1, `CA-AUTH-176`) | Listado: `403` ⇒ `auth.mfaAdmin.forbidden`; otro ⇒ `auth.common.unexpectedError`. Revocar: cualquier error ⇒ `auth.common.unexpectedError`, sin recargar. Conceder: `403` ⇒ `detail` o `forbidden`; `409` ⇒ `exemptions.alreadyLive`; `422` ⇒ mensajes de `reason`/`expires_at`; `404` ⇒ `reset.userNotFound` | **Ninguno propio** (issue #120: el resto de `/administracion/mfa` sigue sin test). Importa `vue-i18n` para `locale` (#259) |
| `AdminSsoView.vue` | `GET /identity-providers?per_page=100` una sola vez, sin paginar | «Nuevo proveedor» (enlace, fuera de la tabla). Por fila: «Editar» (enlace a `sso-administration-edit`) y «Eliminar» con `window.confirm` (texto `confirmDelete` + `confirmDeleteSaml` en SAML); tras `204`, **quita la fila en memoria sin volver a pedir**. Botones de fila **sin nombre accesible con la identidad**. Ningún control se oculta por permiso | Carga: `401` ⇒ navega a `login`; otro ⇒ `auth.ssoAdmin.loadError`. Eliminar: cualquier error ⇒ `auth.ssoAdmin.loadError` (la fila no desaparece) | `AdminSsoView.spec.ts`, 13 casos; cinco usan `window.confirm` simulado |
| `SessionsView.vue` | `GET /auth/sessions` **sin parámetros** (`listSessions`): solo la primera página (25 por defecto en servidor) | «Cerrar sesión» por fila (`DELETE /auth/sessions/{id}`) y «Cerrar las demás sesiones» (`DELETE /auth/sessions?scope=others`, deshabilitado sin otras sesiones), ambas con confirmación en línea y foco gestionado a mano; tras éxito, `role="status"` con `revokedSuccess`/`revokeOthersSuccess` y recarga; revocar la `current` navega a `login`. Nombre accesible de fila = `revoke` + `' — '` literal + dispositivo | Carga: `401` ⇒ `login`; otro ⇒ `unexpectedError`. Revocar: `401` ⇒ `login`; `404`/`409` ⇒ recarga sin error; `429` ⇒ segundos de `Retry-After`; otro ⇒ `unexpectedError`. «Las demás»: `401`, `429`, otro, igual | **No se ha localizado** ningún `SessionsView.spec.ts` (ni junto a la vista ni en `__tests__/`); el implementador lo comprueba con una búsqueda antes de empezar. Importa `vue-i18n` (#259) y escribe `'—'` para la IP nula (#90) |

Servidor, verificado para lo que la migración necesita: `IndexMfaExemptionsRequest` acepta `per_page` (1-100) además de `state` por comas y `page`. `GET /identity-providers` acepta `page`/`per_page` (la vista ya envía `per_page`). `GET /auth/sessions` acepta `page`, `per_page` (25 por defecto, máximo 100) y `sort` (`REQ-AUTH/api.md §B.2`; **solo comprobado en la documentación**, no en el `FormRequest`: el implementador lo verifica y, si no coincide, para y lo reporta). **1.9f no toca `apps/api`.**

#### 14.13.2 Ampliación del filtro `enum`: selección única y valor inicial (`OPEN-CORE-40` = A)

- **`RN-CORE-94` · Filtro `enum` de selección única con valor inicial.** Ampliación **aditiva** de `DataTableEnumFilter` (amplía la lista cerrada de §13.7, como ya hicieron `twoState` de 1.9b, `label` ratificado en `OPEN-CORE-53` y `entity` de 1.9d), con dos campos opcionales nuevos:

  | Campo | Tipo | Por defecto | Contrato |
  |-------|------|-------------|----------|
  | `multiple` | booleano | `true` | `true` o ausente: el filtro de 1.9 sin ningún cambio (casillas, valores por comas). `false`: **selección única** |
  | `initial` | texto | ausente | Solo con `multiple: false`. Valor con el que arranca el filtro cuando no hay estado previo (sin `urlState`, siempre). Debe ser el `value` de una de `options`; si no lo es, o si se declara con `multiple` distinto de `false`, el componente lo **ignora** (el filtro arranca en «todos») y avisa por consola, con el mismo mecanismo que el aviso de `urlState` duplicado de `useDataTableController` — nunca lanza ni envía un valor no declarado |

  Comportamiento con `multiple: false`:
  1. **Control**: grupo de opciones **exclusivas** (`DropdownMenuRadioGroup`, ya vendorizado y usado por el filtro `boolean` de tres estados), con una primera opción «Todos» (`dataTable.filters.all`, ya existente) seguida de `options` en el orden declarado. Etiqueta de cada opción con la misma regla de `optionLabel` (`label` > `labelKey` > código crudo, `ADR-038 §7.3`).
  2. **Disparador**: botón con el nombre del filtro (`labelKey`) y el valor elegido o «Todos», con la misma forma que el disparador del booleano de tres estados (`dataTable.filters.booleanTrigger` o una clave hermana de `dataTable.filters.*`; el nombre lo elige el implementador, en `es`, `en`, `de` y `fr`, §13.12). El nombre accesible del disparador **empieza** por el texto de `labelKey`, para que un test lo localice igual que el de un `enum` múltiple.
  3. **Serialización**: un valor ⇒ `<id>=<valor>`; «Todos» ⇒ el parámetro no se envía (`CA-CORE-269`). Nunca hay dos valores a la vez, tampoco si llegan por otra vía (un valor con coma en el estado se trata como no declarado y se descarta).
  4. Cambiar el valor reinicia a la página 1 (`CA-CORE-168`) y lanza una sola petición.
  5. **Qué es «sin filtrar»** para un filtro con `initial` (`OPEN-CORE-54` = A, resuelta el 2026-10-04): `initial` es el **estado de reposo**. Recién montada la tabla no hay «Limpiar filtros» y el texto de vacío es el del consumidor; elegir «Todos» activa «Limpiar filtros», que devuelve el filtro a `initial`.
  6. **Con `urlState`** (`OPEN-CORE-55` = A, resuelta el 2026-10-04): no se admite en 1.9f. Con `urlState` se **ignora `initial`** (la primera petición no lleva el parámetro y la URL no lo contiene) y se **avisa por consola**. Ningún consumidor de 1.9f lo necesita (las tres vistas no declaran estado en la URL).
  7. Las tablas existentes (sin `multiple` ni `initial`) no cambian de comportamiento ni de petición: `MfaComplianceArea`, usuarios, invitaciones, importaciones, auditoría, módulos y las tablas de prueba de 1.9 mantienen sus tests en verde.

  Se exportan desde `src/data-table/index.ts` solo los tipos ya existentes (los campos nuevos viven en `DataTableEnumFilter`); ningún módulo importa `src/data-table/` por ruta interna (`§13.3`).

#### 14.13.3 `MfaExemptionsArea.vue` (`auth.mfa_exemptions`)

Sigue siendo un área embebida en `AdminMfaView.vue`, sin ruta propia. `mode="page"`, sin `urlState`, sin búsqueda, sin columnas ordenables (`GET /mfa-exemptions` no acepta `sort`; orden del servidor: vivas primero y por `granted_at` descendente), sin exportación. `card-heading-level` 3 (la sección ya tiene un `h2`). Texto de vacío del consumidor: `auth.mfaAdmin.exemptions.empty` (el actual). `caption`: clave nueva `auth.mfaAdmin.exemptions.tableCaption` (hermana de `auth.mfaAdmin.compliance.tableCaption`).

**Columnas** (las seis de hoy, mismo orden):

| `id` | Cabecera (clave actual) | Valor | Tarjeta | Ocultable |
|------|-------------------------|-------|---------|-----------|
| `user` | `columnUser` | «Nombre Apellido · correo», como hoy (`given_name`, `family_name_1`, `email`). `rowHeader` | `title` | No |
| `state` | `columnState` | Insignia con el texto traducido de `auth.mfaAdmin.exemptions.state.<valor>` (rama por defecto: código crudo), variante `default` en `live` y `secondary` en el resto, como hoy; el color no es la única señal (D.9) | `subtitle` | Sí |
| `reason` | `columnReason` | Texto tal cual, en varias líneas (§13.11) | `field` | Sí |
| `expires_at` | `columnExpiresAt` | `formatDateTime` de `useDataTableFormatters` (fecha **y hora**, como hoy: la caducidad efectiva es las 00:00 del día, `REQ-AUTH/api.md §D.4`) | `field` | Sí |
| `granted_by` | `columnGrantedBy` | «Nombre Apellido» de `granted_by` | `field` | Sí |
| `actions` | `columnActions` | Ver abajo | `actions` | No |

**Valor vacío**: toda celda sin valor usa el valor vacío común (`dataTable.emptyValue`, `CA-CORE-201`); en particular, **la celda de acciones de una fila no `live` pinta `DataTableEmptyValue`** (exportado por `src/data-table`) en lugar del `'—'` literal actual, lo que resuelve #90 en esta vista.

**Filtro**: `{ type: 'enum', id: 'state', labelKey: 'auth.mfaAdmin.exemptions.filterLegend', multiple: false, initial: 'live', options: live, expired, revoked }` con las etiquetas `auth.mfaAdmin.exemptions.state.<valor>` (§14.13.2).

**Función de petición**: `GET /mfa-exemptions` con `state` (el valor del filtro, o ausente con «Todos»), `page` y **`per_page` explícito** (§13.24 punto 5); `listMfaExemptions` del cliente del módulo gana el parámetro `per_page` (aditivo). Ningún otro parámetro (no envía `user`, como hoy). Primera petición al montar: `state=live&page=1&per_page=25` (`CA-CORE-256`).

**Acción por fila «Revocar»** (solo `state = live`, como hoy, sin ocultarla por permiso: regla 1 de `REQ-AUTH/permisos.md §D.6.3`, que `RN-CORE-84` conserva):
- Texto visible: `revokeAction` (el actual). **Nombre accesible** con la identidad de la fila: clave nueva con parámetro de nombre («Revocar la excepción de Ana López»), del mismo patrón que `auth.mfaAdmin.compliance.resetActionFor` (WCAG 2.4.6, `CA-CORE-186`).
- **Confirmación** con `useConfirm` + `ConfirmDialog` (`RN-CORE-64`, `OPEN-CORE-42` = A): título que nombra a la persona («Revocar la excepción de Ana López»), descripción = el texto actual de `auth.mfaAdmin.exemptions.confirmRevoke`, botón de confirmar con la identidad, `destructive: true`. Desaparece la confirmación en línea (`revokingId` como estado de «fila en confirmación»).
- Mientras la petición está en vuelo, el botón «Revocar» de esa fila está deshabilitado (paridad de `revokeSubmitting`).
- `204` ⇒ `refresh()` de la tabla (**misma consulta**: mismo filtro y misma página, como hoy; `RN-CORE-45` cubre la página que se queda vacía).
- Error ⇒ `auth.common.unexpectedError` con `role="alert"` debajo de la tabla, como hoy, **sin recargar** (paridad; un `404` de «ya revocada» se comporta igual que hoy).

**Formulario de concesión**: sin cambios de campos, validación, peticiones ni mensajes (fuera de la tabla, `CA-CORE-256`). Tras `201`, la tabla vuelve a la **página 1 con `state=live`** (paridad): el mecanismo depende de **`OPEN-CORE-56`**.

**Errores del listado**: pasan a los estados de error de §13.10/§12.6 del componente (precedente de `MfaComplianceArea`, §13.15): un `403` pinta el estado «sin acceso» **dentro del área**, sin ocultarla ni redirigir (sigue cumpliendo `CA-AUTH-176`), y un `5xx`, el estado de error con `request_id` y «Reintentar». **No** se conservan los textos `auth.mfaAdmin.forbidden`/`unexpectedError` del listado (ver `RN-CORE-95`). Los mensajes de las **acciones** (revocar, conceder) sí se conservan literalmente.

**Limpieza por construcción**: deja de importar `useI18n` de `vue-i18n` (usa `useT` y los formateadores de `src/data-table`, #259) y de escribir `'—'` (#90); `MAX_EXEMPTION_DAYS` y su comentario no cambian.

#### 14.13.4 `AdminSsoView.vue` (`auth.identity_providers`)

`mode="page"`, sin `urlState`, sin búsqueda, sin columnas ordenables (la vista no ordena hoy; no se añade), sin filtros, sin exportación. `card-heading-level` 2 (la vista tiene `h1`). Texto de vacío: `auth.ssoAdmin.empty` (el actual; el test «catálogo vacío muestra el estado vacío, no una tabla» sigue valiendo). `caption`: clave nueva `auth.ssoAdmin.tableCaption`. Cabecera de la vista (título, introducción y enlace «Nuevo proveedor») **fuera** de la tabla, sin cambios.

**Columnas** (las siete de hoy, mismo orden; la última sin cabecera visible hoy pasa a tener una, porque el componente exige `headerKey`: clave nueva `auth.ssoAdmin.columns.actions`):

| `id` | Cabecera | Valor | Tarjeta | Ocultable |
|------|----------|-------|---------|-----------|
| `display_name` | `columns.displayName` | `display_name`. `rowHeader` | `title` | No |
| `protocol` | `columns.protocol` | `auth.ssoAdmin.protocolLabel.<protocol>` (rama por defecto: código crudo) | `subtitle` | Sí |
| `issuer` | `columns.issuer` | `issuer`; hoy se trunca con `truncate`: si se mantiene el truncado, el texto completo es accesible (`title` o nombre accesible, §13.11 «Texto largo»); vacío ⇒ valor vacío común | `field` | Sí |
| `status` | `columns.status` | `status.enabled`/`status.disabled` según `is_enabled` | `field` | Sí |
| `provisioning_mode` | `columns.provisioningMode` | `auth.ssoAdmin.provisioningMode.<valor>` | `field` | Sí |
| `secret` | `columns.secret` | Mismo contenido que hoy por *slot*: en SAML, `certificateStatus.none` (con `text-destructive`) o `certificateStatus.active` más `nextExpiry`; en OIDC, `secretStatus.none`/`expiringSoon` (con fecha si la hay)/`active`. **OIDC sin `secret_status`** (hoy celda vacía) ⇒ valor vacío común | `field` | Sí |
| `actions` | `columns.actions` (nueva) | «Editar» y «Eliminar» | `actions` | No |

Las fechas de esta columna pasan de `toLocaleDateString()` a `formatDate` de `src/data-table` (§13.12: ninguna vista crea su formateador).

**Función de petición**: `GET /identity-providers` con `page` y `per_page` del componente (25 por defecto; `CA-CORE-257`). Un `401` en la carga **sigue navegando a `login`** (paridad y test existente): la función de petición lo detecta, navega y relanza el error, del mismo modo que `MfaComplianceArea` reenvía su `403`. Los demás errores de carga pasan al estado de error del componente (`RN-CORE-95`).

**Acciones por fila** (visibles siempre, como hoy; el servidor decide con `proveedor_identidad.*`, `INV-002`):
- **Editar**: enlace a `sso-administration-edit` con el `public_id`. Texto visible `auth.ssoAdmin.edit`; **nombre accesible** con el nombre del proveedor (clave nueva con parámetro, «Editar Entra ID del centro»).
- **Eliminar**: texto visible `auth.ssoAdmin.delete`; nombre accesible con el nombre del proveedor (clave nueva). **Confirmación** con `useConfirm` + `ConfirmDialog` en lugar de `window.confirm` (`OPEN-CORE-42` = A): título con el nombre del proveedor, descripción = `confirmDelete` y, **solo en SAML**, además `confirmDeleteSaml` (el aviso de la URL del ACS, `REQ-AUTH funcional.md §G.9`), botón de confirmar con el nombre, `destructive: true`. Mientras la petición está en vuelo, el botón de esa fila está deshabilitado (paridad de `deletingId`).
- `204` ⇒ `refresh()` de la tabla. **Cambio por construcción**: hoy la fila se quita en memoria sin volver a pedir; con paginación en servidor la página tiene que volver a pedirse (si queda vacía y no es la primera, `RN-CORE-45`). Es una petición `GET` más tras eliminar, sin cambio de *endpoint*.
- Error ⇒ `auth.ssoAdmin.loadError` con `role="alert"` (paridad literal, aunque el texto hable de «cargar»; no se corrige aquí, ver hallazgo 2 de §14.13.7) y la fila sigue en la tabla.

**Tests existentes** (`AdminSsoView.spec.ts`): los cinco casos que simulan `window.confirm` se reescriben para el diálogo (lo exige el cambio de componente, §14.19, último párrafo); conservan lo que prueban (se pide confirmación, cancelar no llama a la API, el aviso de SAML aparece solo en SAML, el error deja la fila). El de «al retirar… desaparece la fila» pasa a comprobar que, tras `204`, se vuelve a pedir el catálogo y se pinta lo que devuelve. Los demás no cambian de aserción.

#### 14.13.5 `SessionsView.vue` (`auth.sessions`)

`mode="page"`, sin `urlState`, sin búsqueda, sin filtros, sin exportación. **Sin columnas ordenables**: el servidor admite `sort` (`started_at`, `last_activity_at`), pero la vista no ordena hoy y `RN-CORE-84` exige las mismas peticiones; añadir orden es un cambio de comportamiento fuera de este paso. `card-heading-level` 2. `caption`: `auth.sessions.title` (el actual, ya en `caption sr-only`). Texto de vacío: clave nueva `auth.sessions.empty` (el componente lo exige; en la práctica no se ve, porque la sesión actual siempre está en la lista). Título, introducción, mensajes `role="status"`/`role="alert"` y «Cerrar las demás sesiones» **fuera** de la tabla, encima, como hoy.

**Columnas** (las cinco de hoy, mismo orden y mismas claves de cabecera):

| `id` | Cabecera | Valor | Tarjeta | Ocultable |
|------|----------|-------|---------|-----------|
| `device` | `columnDevice` | Primera línea: `browser · platform`; segunda, en texto secundario: el resumen actual (tipo de dispositivo traducido con rama por defecto, más «esta sesión» si `current` y «dispositivo no reconocido» si `!device_known`), por *slot*. `rowHeader` | `title` | No |
| `started_at` | `columnStarted` | `formatDateTime` | `field` | Sí |
| `last_activity_at` | `columnLastActivity` | `formatDateTime` | `field` | Sí |
| `ip_address` | `columnIp` | `ip_address`; `null` ⇒ valor vacío común (`CA-CORE-258`, resuelve #90 aquí) | `field` | Sí |
| `actions` | `columnActions` | «Cerrar sesión» | `actions` | No |

`location` sigue sin mostrarse (siempre `null`, `REQ-AUTH/api.md §B.7.3`).

**Función de petición**: `GET /auth/sessions` con `page` y `per_page` del componente; `listSessions` gana esos dos parámetros (aditivo). Sin `sort`. Un `401` en la carga navega a `login` desde la función de petición (paridad, mismo mecanismo que §14.13.4).

**«Cerrar las demás sesiones» habilitado** si y solo si hay otras sesiones. Como la vista ya no tiene las filas, se calcula con el `meta.total` de la última respuesta que capture la función de petición: habilitado si `total > 1` (exactamente una fila es `current`, `CA-AUTH-082`). Mientras no haya respuesta, deshabilitado. Es más exacto que hoy, que solo miraba la primera página.

**Acción por fila «Cerrar sesión»**:
- Texto visible `auth.sessions.revoke`. **Nombre accesible** con una clave nueva con parámetros que identifique la fila **de forma única**: dispositivo **y** fecha de inicio formateada («Cerrar sesión de Chrome · Windows iniciada el 3 oct 2026, 10:15»). El dispositivo solo no basta: dos sesiones del mismo navegador y sistema darían el mismo nombre (WCAG 2.4.6, `CA-CORE-186`). Sustituye la concatenación actual con `' — '` literal (`INV-009`).
- **Confirmación** con `useConfirm` + `ConfirmDialog` (`OPEN-CORE-42` = A): título con la identidad de la fila; descripción = `confirmRevokeCurrent` si la fila es `current` y `confirmRevoke` si no (los textos actuales); botón de confirmar con la identidad; `destructive: true`. Sustituye a la confirmación en línea y a la gestión manual de foco (`rowTriggerEls`, `rowConfirmEls`, `focusFirstButton`): la apertura y la devolución del foco las hace el diálogo (`RN-CORE-64`).
- Mientras la petición está en vuelo, el botón de esa fila está deshabilitado.
- `204` y fila `current` ⇒ navega a `login` (sin recargar). `204` y otra fila ⇒ `revokedSuccess` con `role="status"` y `refresh()`. `401` ⇒ `login`. `404`/`409` ⇒ `refresh()` sin mensaje de error. `429` ⇒ `tooManyRequestsWithSeconds` con los segundos de `Retry-After` o `tooManyRequests`. Otro ⇒ `unexpectedError`. Todo igual que hoy.

**«Cerrar las demás sesiones»**: mismo *endpoint* (`DELETE /auth/sessions?scope=others`), confirmación con el mismo diálogo (título `revokeOthers`, descripción `confirmRevokeOthers`, botón de confirmar `revokeOthers`; es una acción masiva sin identidad de fila), `destructive: true`. Éxito ⇒ `revokeOthersSuccess` con `role="status"` y `refresh()`. Errores igual que hoy.

**Limpieza por construcción**: deja de importar `vue-i18n` (usa `useT` y `useDataTableFormatters`, `CA-CORE-258`, #259) y de escribir `'—'` (#90).

#### 14.13.6 Reglas, preguntas abiertas y criterios de 1.9f

- **`RN-CORE-95` · Errores en las vistas migradas.** Los errores de **carga del listado** pasan al tratamiento de §13.10/§12.6 del componente (estado de error con `request_id` y «Reintentar», «sin acceso» en `403`), igual que en la migración de `MfaComplianceArea` (§13.15), salvo el `401` de carga, que conserva la navegación a `login` donde la vista la hacía (`AdminSsoView`, `SessionsView`). Los mensajes de las **acciones** (revocar, eliminar, cerrar sesiones, conceder) se conservan literalmente, con sus claves actuales. Es la lectura que esta especificación hace de «mismos mensajes y mismo tratamiento de errores» (`RN-CORE-84`) junto con «cambia el aspecto por construcción»; si el usuario no la acepta, ver la «Nota sobre `RN-CORE-95`» tras las preguntas abiertas.
- **`RN-CORE-96` · Acción por fila tras éxito.** En las tres vistas, una acción por fila que termina bien vuelve a pedir la página con `refresh()` del componente (nunca muta las filas en memoria, que son del componente, `RN-CORE-50`); si la página queda vacía, aplica `RN-CORE-45`. Ningún botón de fila se oculta por permiso en estas tres vistas (paridad, `REQ-AUTH/permisos.md §D.6.3` regla 1).

**Preguntas** (resueltas el 2026-10-04 por el usuario, que aprobó la especificación con las recomendaciones indicadas, salvo `OPEN-CORE-56`, donde se eligió A y no la recomendación B de `spec-writer`). Numeradas como siguientes a `OPEN-CORE-53`; si alguna está usada en otro documento del módulo, se renumera antes de aprobar.

| ID | Pregunta | Bloquea | Recomendación |
|----|----------|---------|---------------|
| `OPEN-CORE-54` | Qué es «sin filtrar» en un `enum` con `initial` | **1.9f** (`CA-CORE-289`, `MfaExemptionsArea`) | **RESUELTA: A** |
| `OPEN-CORE-55` | `initial` combinado con `urlState` | No (ningún consumidor de 1.9f) | **RESUELTA: A** |
| `OPEN-CORE-56` | Cómo vuelve la tabla de excepciones a «página 1, `live`» tras conceder | **1.9f** (`CA-CORE-292`) | **RESUELTA: A** (re-montar con `key`, sin `reset()`) |

**`OPEN-CORE-54` · Qué es «sin filtrar» en un filtro `enum` con `initial`.** Hoy el componente considera activo todo filtro con valor (`hasActiveFilters`) y «Limpiar filtros» deja el mapa vacío. Con `initial: 'live'`, la tabla de excepciones **arranca** con un filtro activo, y eso cambia lo que el administrador ve al entrar.
- **A** · El valor inicial es el **estado de reposo**: el filtro cuenta como activo solo si difiere de `initial` («Todos» incluido); «Limpiar filtros» vuelve a `initial`, no a «Todos»; con el filtro en `initial` y sin filas se pinta el texto de vacío del consumidor (`exemptions.empty`, paridad). Tablas sin `initial`: sin cambio (su reposo es el mapa vacío).
- **B** · El valor inicial es solo el **primer valor**: cuenta como filtro activo desde la carga; el botón «Limpiar filtros» se ve al entrar y lleva a «Todos»; sin filas se pinta «sin resultados con estos filtros».

**Recomendación: A.** Es la que conserva lo que hoy ve el administrador de MFA al entrar (`OPEN-CORE-40` = A se eligió precisamente por la paridad de `MfaExemptionsArea`) y la única en la que «Limpiar filtros» devuelve la pantalla a como estaba al abrirla. B es más simple de implementar, pero enseña al entrar un botón de «limpiar» que nadie ha pedido.

**`OPEN-CORE-55` · `initial` con `urlState`.** Con estado en la URL, un parámetro ausente significa hoy «sin filtro»; con `initial`, ausente tendría que significar `initial`, y «Todos» necesitaría un valor propio en la URL (`ADR-054 §6`/`RN-CORE-54` no lo prevén).
- **A** · No se admite en 1.9f: si una tabla declara `urlState` y un `enum` con `initial`, el componente ignora `initial` (el filtro arranca en «Todos») y avisa por consola. Se diseña con el primer consumidor real.
- **B** · Diseñarlo ahora, con un valor reservado para «Todos» en la URL.

**Recomendación: A.** Ningún consumidor lo necesita y B fija un contrato de URL sin caso real.

**`OPEN-CORE-56` · Volver a «página 1, `state=live`» tras conceder una excepción.** El componente solo expone `refresh()` (repite la consulta actual) y `focusSearch()`; la vista ya no puede fijar página ni filtro.
- **A** · La vista vuelve a montar la tabla (cambio de `key`), que arranca en su estado inicial. Sin tocar el componente; a cambio, pinta el estado de carga completo en vez del de recarga y vuelve a leer la configuración de columnas.
- **B** · Ampliación aditiva del componente: `reset()` expuesto, que vuelve al estado inicial de la consulta (página 1, filtros de reposo según `OPEN-CORE-54`, sin búsqueda) y pide una vez. No figura entre las ampliaciones de `OPEN-CORE-40`, así que necesita ratificación expresa (como `label` en `OPEN-CORE-53`).
- **C** · Solo `refresh()`: la tabla se queda con el filtro y la página que tuviera. Pierde paridad: si el administrador estaba mirando las caducadas, la excepción recién concedida no aparece.

**Resolución: A** (2026-10-04). Se evita ampliar el componente compartido por un único consumidor (`RN-CORE-37`, #278); si aparece un segundo caso, se reconsidera `reset()`. Recomendación original de `spec-writer`: B.

**Nota sobre `RN-CORE-95`**: no se plantea como pregunta porque sigue el precedente ya aceptado de §13.15; si el usuario quiere conservar **literalmente** los textos de error de carga (`auth.mfaAdmin.forbidden`, `auth.ssoAdmin.loadError`, `auth.common.unexpectedError`), el componente necesitaría un mecanismo para que el consumidor sustituya su estado de error, que no existe y no se propone aquí.

**Criterios de aceptación de 1.9f** (complementan `CA-CORE-255` a `-259` y la parte de `enum` de `CA-CORE-269`, que no se reescriben). Vitest salvo indicación; cada test cita su ID (`INV-015`). Numerados como siguientes a `CA-CORE-287` (§14.24); si alguno está usado en otro documento del módulo, se renumera antes de aprobar.

- **`CA-CORE-288`** [`RN-CORE-94`, §13.7] · **Dado** una tabla de prueba con un `enum` de `multiple: false`, `initial: 'b'` y opciones `a`, `b`, `c`, **entonces** el control es un grupo de opciones exclusivas con «Todos» en primer lugar y `a`, `b`, `c` después, con `b` marcada; el disparador tiene un nombre accesible que empieza por la etiqueta del filtro e incluye la opción elegida; **cuando** se elige `c`, sale una sola petición con `<id>=c` y `page=1`, y `b` deja de estar marcada; **cuando** se elige «Todos», la petición no lleva `<id>`. **Y dado** `initial: 'z'` (no declarado) o `initial` con `multiple` ausente, el filtro arranca en «Todos», la primera petición no lleva `<id>` y se emite un aviso por consola, sin error.
- **`CA-CORE-289`** [`RN-CORE-94`, `OPEN-CORE-54`] · **Dado** la tabla de prueba de `CA-CORE-288` recién montada, **entonces** no hay botón «Limpiar filtros»; **cuando** se elige «Todos», aparece, y al activarlo la petición vuelve a llevar `<id>=b` y el botón desaparece; **y dado** `data: []` con el filtro en `b`, se pinta el texto de vacío del consumidor, no el de «sin resultados con estos filtros».
- **`CA-CORE-290`** [`RN-CORE-94`, `OPEN-CORE-55`] · **Dado** una tabla de prueba con `urlState` y un `enum` con `initial`, **entonces** la primera petición no lleva el parámetro del filtro, la URL no lo contiene y se emite un aviso por consola.
- **`CA-CORE-291`** [`RN-CORE-84`, `RN-CORE-64`, `RN-CORE-96`, `REQ-AUTH-003`] · **Dado** `MfaExemptionsArea` con una fila `live` de Ana López y otra `expired`, **entonces** la primera petición es `GET /mfa-exemptions` con `state=live`, `page=1` y `per_page=25`; la celda de acciones de la fila `expired` contiene el valor vacío común y ningún botón; el botón de la fila `live` tiene como nombre accesible el texto de revocar con «Ana López»; **cuando** se activa, no sale ninguna petición hasta confirmar, el diálogo nombra a Ana López, `Esc` lo cierra sin petición y devuelve el foco al botón; **cuando** se confirma, sale exactamente un `DELETE /mfa-exemptions/{id}` y, con `204`, una petición `GET /mfa-exemptions` con el mismo `state` y la misma `page` que la anterior; **y cuando** el `DELETE` responde `500`, aparece `auth.common.unexpectedError` con `role="alert"` y no sale ningún `GET`.
- **`CA-CORE-292`** [`RN-CORE-84`, `OPEN-CORE-56`] · **Dado** `MfaExemptionsArea` con el filtro en la opción `expired` y la página 2, **cuando** se concede una excepción y `POST /mfa-exemptions` responde `201`, **entonces** la siguiente petición del listado lleva `state=live` y `page=1`, y el filtro muestra seleccionada la opción `live` (etiqueta `auth.mfaAdmin.exemptions.state.live`).
- **`CA-CORE-293`** [`RN-CORE-84`, `RN-CORE-95`, `RN-CORE-96`, `REQ-AUTH-004`] · **Dado** `AdminSsoView` con un proveedor OIDC sin `secret_status` y un proveedor SAML, **entonces** la celda de credencial del OIDC contiene el valor vacío común; «Editar» y «Eliminar» de cada fila tienen nombres accesibles distintos con el nombre de su proveedor; **cuando** se confirma «Eliminar» y el `DELETE` responde `204`, se vuelve a pedir `GET /identity-providers` con la misma `page` y `per_page`; **cuando** responde con error, la fila sigue y aparece `auth.ssoAdmin.loadError` con `role="alert"`; **y dado** que la carga responde `401`, la ruta pasa a `login`, y que responde `503`, se pinta el estado de error del componente con «Reintentar».
- **`CA-CORE-294`** [`RN-CORE-84`, `RN-CORE-64`, `REQ-AUTH-005`, WCAG 2.4.6] · **Dado** `SessionsView` con dos sesiones `Chrome · Windows` iniciadas en fechas distintas, una de ellas `current`, **entonces** la primera petición es `GET /auth/sessions` con `page=1` y `per_page=25` y sin `sort`; los dos botones de cerrar sesión tienen nombres accesibles distintos; el diálogo de la fila `current` usa el texto de `confirmRevokeCurrent` y el de la otra el de `confirmRevoke`; **cuando** el `DELETE` de la otra responde `409`, se vuelve a pedir el listado sin mensaje de error, y **cuando** responde `429` con `Retry-After: 30`, el mensaje contiene «30»; **y dado** una respuesta con `meta.total = 1`, «Cerrar las demás sesiones» está deshabilitado, y con `meta.total = 2` en una página que solo contiene la `current`, habilitado.
- **`CA-CORE-295`** [`INV-009`, `CA-CORE-201`, `CA-CORE-261`, #90, #259] · **Dado** `MfaExemptionsArea.vue`, `AdminSsoView.vue` y `SessionsView.vue`, **entonces** ninguno contiene `'—'` como literal ni importa `vue-i18n`; **y** toda clave nueva de 1.9f en `auth.*` y `dataTable.*` existe en `es`, `en`, `de` y `fr`, y `npm run lint:i18n` termina sin hallazgos.
- **`CA-CORE-176`** (ya existente) se cumple con los tres `tableId` nuevos (`auth.mfa_exemptions`, `auth.identity_providers`, `auth.sessions`), sin cambiar su texto.

**Cobertura real** (1.9f, 2026-10-04): `CA-CORE-255` en `src/data-table/architecture.spec.ts` (lista de excepciones vacía); `CA-CORE-288`, `-289` y `-290` (y `CA-CORE-269`, parte `enum`) en `src/data-table/DataTable.singleEnum.spec.ts`; `CA-CORE-256`, `-291` y `-292` en `modules/auth/components/admin/MfaExemptionsArea.spec.ts` (nuevo; avance sobre #120); `CA-CORE-257` y `-293` en `modules/auth/views/AdminSsoView.spec.ts`; `CA-CORE-258` y `-294` en `modules/auth/views/SessionsView.spec.ts` (nuevo); `CA-CORE-259` (parte de sesiones) en `e2e/auth-sessions.spec.ts` (Playwright); `CA-CORE-295` en `modules/auth/locales/migration19f.i18n.spec.ts`.

#### 14.13.7 Hallazgos fuera de alcance de 1.9f

No se corrigen aquí (`CLAUDE.md §5`); para que la sesión orquestadora abra el issue que corresponda:

1. **Foco tras una acción que retira la fila.** `useConfirm`/`ConfirmDialog` devuelven el foco al botón que abrió la confirmación, pero si la acción termina bien y el `refresh()` retira esa fila (revocar, eliminar, cerrar sesión; también revocar una invitación en 1.9b), el botón ya no existe y el foco cae en `body` (WCAG 2.4.3). No lo resuelve ninguna regla de §14; 1.9f sigue el precedente de `InvitationsView.vue`, que tampoco lo gestiona. **Severidad propuesta: Baja**; propuesta: llevar el foco al mensaje de resultado (`role="status"`) o al `caption` de la tabla.
2. **`auth.ssoAdmin.loadError` como mensaje de error de borrado** («No se ha podido cargar el catálogo…» cuando falla un `DELETE`): el texto no describe lo que ha pasado. Se conserva por paridad. **Baja**; propuesta: clave propia de error de borrado, con el `detail` del servidor si existe.
3. **Índices**: `RN-CORE-94` a `-96` y `OPEN-CORE-54` a `-56` añadidos al índice de §14.14 y a §14.17 al aprobar (2026-10-04).

### 14.14 Reglas transversales de las pantallas

- **`RN-CORE-62` · Sin peticiones a ciegas.** Ninguna vista pide un *endpoint* auxiliar cuyo permiso el usuario no tiene efectivo (extensión de `RN-CORE-33` a las pantallas): si falta, la parte de la interfaz que lo usaría (filtro de rol, selector de roles, enlace a la ficha, búsqueda de usuario) no se ofrece. Un `403` esperado no es un mecanismo de descubrimiento.
- **`RN-CORE-64` · Confirmación de acciones destructivas o masivas.** Dar de baja, desactivar, revocar invitación, reenviar, ejecutar y descartar una importación, eliminar un activo y retirar roles piden confirmación explícita antes de la petición: texto que nombra la entidad afectada y la consecuencia, botón de confirmar con nombre que incluye la identidad de la fila («Dar de baja a Ana López»), `Esc` cancela, el foco va a la confirmación al abrirla y vuelve al control que la abrió al cerrarla. **Mecanismo** (`OPEN-CORE-42` = A): un único componente de aplicación de confirmación sobre `alert-dialog` de shadcn-vue, vendorizado sobre Reka UI ya instalada (`docs/design-system.md §12.2`, sin dependencia nueva), diálogo modal con foco atrapado; se usa en todas las pantallas de 1.9b-1.9f y sustituye a `window.confirm` y a las confirmaciones en línea de las tres vistas migradas. Se vendoriza en 1.9b, su primer consumidor. Restaurar y activar no la piden (son reversibles y no dejan a nadie sin acceso).
- **Estados**: toda vista usa los componentes y la correspondencia de errores de §12.6 (carga, vacío, error, sin acceso, módulo no disponible, `429`, `5xx` con `request_id`), y toda tabla los de §13.10. Una ficha cuyo `GET` responde `404` pinta «no encontrado» (también para un `public_id` de otro centro, `CA-CORE-073`).
- **`RN-CORE-50` se extiende a fichas y formularios**: ningún dato de usuario, invitación, importación o auditoría se guarda en `localStorage`, `sessionStorage`, IndexedDB ni en la URL; en la URL solo van `public_id` en la ruta y los filtros de `RN-CORE-54` (nunca `q`).
- **i18n**: todo texto nuevo en el espacio `core.*` del módulo (`core.users.*`, `core.invitations.*`, `core.userImports.*`, `core.roles.*`, `core.audit.*`, `core.settings.*`, `core.branding.*`, `core.modules.*`, `core.profile.*`), en `es`, `en`, `de` y `fr`, accedido con `useT` de `@/i18n` (nunca `vue-i18n` directo, issue #259). Los enumerados (estados de usuario, invitación e importación, `event`, `actor_type`, motivos de redacción, nombres de idioma, comunidades autónomas) se traducen en el cliente con rama por defecto que muestra el código (`ADR-038 §3.2`, `§7.3`). Fechas y cifras con los formateadores de `src/data-table` (`§13.12`).

**Índice de reglas de 1.9b**

| ID | Regla | Dónde |
|----|-------|-------|
| `RN-CORE-60` | Registro de rutas en `core/shell.ts`, permiso de la ruta = permiso del contenido principal | §14.3 |
| `RN-CORE-61` | Acciones por permiso, nunca por rol; sin anticipar `RN-CORE-06`/`07`; excepción por identidad propia | §14.4.2 |
| `RN-CORE-62` | Sin peticiones a ciegas en las pantallas | §14.14 |
| `RN-CORE-63` | Tablas con texto traducido por el servidor recargan al cambiar de idioma | §14.8 |
| `RN-CORE-64` | Confirmación accesible de acciones destructivas o masivas | §14.14 |
| `RN-CORE-65` | Formularios: errores por campo, foco, `PATCH` solo con lo modificado | §14.4.3 |
| `RN-CORE-66` | Resultado de una escritura: `role="status"`, navegación, consulta conservada | §14.4.3 |
| `RN-CORE-67` | Columnas del listado de usuarios, sin datos identificativos | §14.4.1 |
| `RN-CORE-68` | Dados de baja solo con `usuario.eliminar` | §14.4.1 |
| `RN-CORE-69` | Solicitud de exportación de usuarios desde la tabla | §14.4.1 |
| `RN-CORE-70` | Acciones sobre invitaciones según estado | §14.5 |
| `RN-CORE-71` | Subida de importación | §14.6.1 |
| `RN-CORE-72` | Seguimiento del estado de un lote (política de `RN-CORE-49`) | §14.6.2 |
| `RN-CORE-73` | Ejecución con confirmación e `Idempotency-Key` estable por confirmación | §14.6.2 |
| `RN-CORE-74` | Incidencias de importación con el componente, aviso de 50, informe | §14.6.2 |
| `RN-CORE-75` | Roles solo lectura en 1.9b | §14.8 |
| `RN-CORE-76` | Filtros de auditoría | §14.7 |
| `RN-CORE-77` | Detalle de cambios sin reconstruir valores redactados | §14.7 |
| `RN-CORE-78` | Exportación de auditoría desde la tabla | §14.7 |
| `RN-CORE-79` | Configuración en lectura o edición por permiso; un `PATCH` por grupo | §14.9 |
| `RN-CORE-80` | Paleta: vista previa de contraste en cliente, servidor como autoridad, `refresh()` | §14.9 |
| `RN-CORE-81` | Idiomas activos y por defecto | §14.9 |
| `RN-CORE-82` | Catálogos de cliente con comprobación cruzada | §14.9 |
| `RN-CORE-83` | Activos de marca | §14.10 |
| `RN-CORE-84` | Migración con paridad y retirada de la excepción en el mismo *commit* | §14.13 |
| `RN-CORE-85` | **(servidor)** `POST /users/exports` conforme a `ADR-054 §8`-`§10` y `ADR-055` | §14.11.1 |
| `RN-CORE-86` | **(servidor)** `GET /data-exports/{id}` autorizado por `kind` | §14.11.1 |
| `RN-CORE-87` | Módulos contratados en solo lectura, página única sintética, fecha de alta como aviso | §14.10b |
| `RN-CORE-88` | Perfil propio de autoservicio: solo contacto editable, sin `GET /me` propio | §14.10c |
| `RN-CORE-89` | Guardado del perfil con `PATCH /me` parcial y sustitución del estado de sesión | §14.10c |
| `RN-CORE-94` | Filtro `enum` de selección única con valor inicial | §14.13.2 |
| `RN-CORE-95` | Errores de carga de las vistas migradas, con el tratamiento del componente | §14.13.6 |
| `RN-CORE-96` | Acción por fila tras éxito: `refresh()` | §14.13.6 |

### 14.15 Riesgos y dependencias operativas

| Riesgo | Efecto | Mitigación |
|--------|--------|------------|
| **#128: sin *worker* de colas** (Alta) | La importación se queda en `subido` y toda exportación en `pendiente`; en un entorno real **las dos pantallas no funcionan** | `RN-CORE-72`/`RN-CORE-49` acotan la consulta y lo comunican. Cerrar un sub-paso con importación o exportación sin #128 resuelto entrega funcionalidad que no opera en producción: el usuario debe saberlo al aprobar |
| Tamaño del paso | Corte de cuota a mitad, revisión inabarcable | División de §14.2 (`OPEN-CORE-30`) |
| Contrato de `fallida` (S4) | Hoy una exportación de auditoría fallida espera 10 min sin decir nada | Corregir en el primer sub-paso; issue propio (§14.16) |
| Catálogos duplicados en el cliente (comunidades autónomas, tipos de documento, alias de auditoría) | Divergencia silenciosa con el servidor | Test cruzado (`RN-CORE-82`) o *endpoint* de facetas (`OPEN-CORE-34`); para los tipos de documento, lectura de `CreateUser` antes de implementar |
| URLs firmadas de 15 min en pantallas largas (informe, activos) | Enlaces o imágenes rotas | Renovación de §14.6.2 y `RN-CORE-83` |
| Datos personales en el CSV de usuarios | Salen del sistema nombre, correos, teléfono de contacto, idioma y roles del personal (y del alumnado con cuenta, si lo hay). **No** salen documento de identidad ni fecha de nacimiento (`OPEN-CORE-32` = B) | Esquema cerrado de §14.11.1 con test de ausencia (`CA-CORE-227`), permiso propio, auditoría `exported`, caducidad de siete días. Ampliar el esquema con datos identificativos exige decisión expresa del usuario (`INV-008`) |
| `@tanstack/vue-table` 8.x sin *releases* (#278) | Más consumidores sobre una línea parada | `RN-CORE-37`: el cambio a 9.x sigue siendo un solo directorio, se consuma o no en 1.9b |
| `CORE_EXPORT_MAX_ROWS` (500 000) pasa a aplicar también a usuarios | Ninguno práctico: un centro no tiene 500 000 usuarios | Se reutiliza la variable (§14.11, `operacion.md §13`); `RNF-LIM-004` queda cubierto |

### 14.16 Hallazgos fuera del ámbito de esta especificación

No se corrigen aquí; se reportan para que la sesión orquestadora abra el issue que corresponda (`CLAUDE.md §5`):

1. **`GET /data-exports/{id}` responde `409` a una exportación `fallida`** y el cliente de 1.9 trata todo `409` como «aún no está lista»: una exportación fallida espera 10 min y acaba en «Comprobar de nuevo», sin mensaje de error. Contradice `api.md §8` (respuesta `200` con `status`) y §13.14.1 punto 5. `CA-CORE-190` pasa porque se probó con una respuesta simulada `200 fallida`. **Severidad propuesta: Media** (fallo funcional con rodeo; código y documentación se contradicen, `CLAUDE.md §6.6`). Afecta ya hoy a la exportación de auditoría. Corrección propuesta: S4 (`OPEN-CORE-39`).
2. `routes.php` fija `permission:auditoria.exportar` en `GET /data-exports/{id}`: ningún segundo `kind` puede consultarse. No es un fallo hoy (solo existe `audit_logs`); lo es en cuanto exista S1. Corrección: S3.
3. `GET /users` no admite `sort=-email` (`IndexUsersRequest`). **Baja** hasta que una columna `email` sea ordenable. Corrección: S5.
4. `GET /users/{id}` no admite `include_deleted`, contra `CA-CORE-014`. **Media** (código y especificación se contradicen). Corrección: S6.
5. Cuatro filtros de enumerado admiten un solo valor (`invitations.status`, `users.locale` y `audit-logs.actor_type` lo validan con `in:`; `audit-logs.module` no valida y trata la lista entera como un solo código desconocido, sin error), mientras `ADR-038 §5.2` define la coma para valores múltiples de enumerados e identificadores y el componente de 1.9 siempre la usa. No es contradicción con el ADR (no obliga a aceptar varios), pero impide el filtro. Corrección: S7.
6. `UserImportResource` no devuelve `created_at`, que `api.md §7` muestra. **Baja.** Corrección: S8.
7. `CA-CORE-207` (§13.18) es más amplio que `ADR-055 §1` (§14.12 punto 1).
8. `GET /users` devuelve en cada fila del listado `document_number`, `birth_date`, `contact_email` y `contact_phone`. No es un fallo de seguridad (exige `usuario.leer` y el ámbito es `todos`), pero el listado expone en bloque más datos identificativos de los que una pantalla de localización necesita. Reducir la respuesta es un cambio **incompatible** (`ADR-038 §7`) y no se propone en 1.9b; se anota para una revisión de minimización con `REQ-PRIV`.
9. `api.md §8` documenta `event` con seis valores (`created|updated|deleted|restored|read|exported`); `datos.md` y `ADR-039` fijan nueve (`+login`, `+logout`, `+password_reset_requested`). Documentación del módulo desincronizada, **Baja**. 1.9b usa los nueve.
10. ~~§12.1.3 enumeraba «módulos contratados» y «perfil propio» entre las pantallas diferidas a 1.9b; el alcance fijado por el usuario no las incluía.~~ **Resuelto** por la decisión del usuario del 2026-10-01 (`OPEN-CORE-31` = B): entran en 1.9e (§14.10b, §14.10c).

### 14.17 Preguntas del paso 1.9b

| ID | Pregunta | Bloquea | Estado / recomendación |
|----|----------|---------|------------------------|
| `OPEN-CORE-30` | División del paso | — | **RESUELTA** (2026-10-01, decisión del usuario): A, cinco sub-pasos |
| `OPEN-CORE-31` | Módulos contratados y perfil propio | — | **RESUELTA** (2026-10-01, decisión del usuario): B, entran en 1.9e |
| `OPEN-CORE-32` | Columnas del CSV de usuarios | — | **RESUELTA** (2026-10-01, decisión del usuario): B, sin `document_type`, `document_number` ni `birth_date` (esquema en §14.11.1) |
| `OPEN-CORE-33` | Filtro por usuario en auditoría | **1.9d** | **RESUELTA** (2026-10-03, decisión del usuario): C (filtro `entity` + «Ver su actividad») |
| `OPEN-CORE-34` | Opciones de módulo y tipo de entidad en auditoría | **1.9d** | **RESUELTA** (2026-10-03, decisión del usuario): B (`GET /audit-logs/facets`, S10) |
| `OPEN-CORE-35` | Zona horaria de fechas de auditoría | **1.9d** | **RESUELTA** (2026-10-03, decisión del usuario): A (zona del navegador) |
| `OPEN-CORE-36` | Detalle de rol con concesiones | 1.9d | **RESUELTA** (2026-10-03, decisión del usuario): A (solo listado; detalle en `1.5b`) |
| `OPEN-CORE-37` | Grupo `security` en la configuración | **1.9e** | **RESUELTA** (2026-10-03, decisión del usuario): B (solo lo que no edite ya `/administracion/mfa`; el resto se enlaza) |
| `OPEN-CORE-38` | Idioma de los mensajes de importación (#285) | **1.9c** | A — **RESUELTA** (2026-10-02, decisión del usuario) |
| `OPEN-CORE-39` | Contrato de `fallida` en `GET /data-exports` | — | **RESUELTA** (2026-10-01, decisión del usuario): A |
| `OPEN-CORE-40` | Ampliaciones del componente de tablas | — | **RESUELTA** (2026-10-01, decisión del usuario): A |
| `OPEN-CORE-41` | «Mis exportaciones» | No | A |
| `OPEN-CORE-42` | Mecanismo de confirmación | — | **RESUELTA** (2026-10-01, decisión del usuario): A |
| `OPEN-CORE-43` | Asignación de roles en la ficha de usuario | — | **RESUELTA** (2026-10-01, decisión del usuario): A |
| `OPEN-CORE-44` | Acciones de plataforma en la pantalla de auditoría | No | A |
| `OPEN-CORE-45` | Qué filas muestra la pantalla de módulos | **1.9e** | **RESUELTA** (2026-10-03, decisión del usuario): A (solo los contratados) |

#### `OPEN-CORE-30` · División del paso — **RESUELTA** (2026-10-01, decisión del usuario): opción A

§14.2. **A** · Cinco sub-pasos (1.9b usuarios+invitaciones con su servidor; 1.9c importación; 1.9d auditoría+roles; 1.9e configuración+marca; 1.9f migración de las tres tablas). **B** · Tres sub-pasos más gruesos. **C** · Un solo paso.

**Recomendación: A.** Es la única en la que cada sub-paso cabe en una sesión con margen para la revisión, y aísla la única migración de esquema en un sub-paso con `db-reviewer`. C repite el riesgo de corte de cuota que motivó las normas de relanzamiento de `CLAUDE.md §3`.

#### `OPEN-CORE-31` · Módulos contratados y perfil propio — **RESUELTA** (2026-10-01, decisión del usuario): opción B

> Aplicada en §14.1.1 (filas 7b y 7c), §14.2 (1.9e), §14.3 y §14.3.1 (ampliación de `RN-CORE-24` a siete rutas), §14.10b, §14.10c, `RN-CORE-87`-`89`, `CA-CORE-264`-`269`, `permisos.md §12.2` y `api.md §14.5`. De su aplicación surge una pregunta nueva, `OPEN-CORE-45`.

`REQ-CORE-002` pide «consultar los módulos contratados… recibir aviso de las nuevas altas y configurar los que estén contratados» (MUST); la API existe (`GET /modules`, `PATCH /module-subscriptions/{id}`). El perfil propio (`PATCH /me`: correo y teléfono de contacto; el idioma ya lo cubre el selector de 1.8) también. §12.1.3 los remitía a 1.9b; el alcance fijado no los nombra, y esta especificación no los añade por su cuenta.

- **A** · Fuera de 1.9b; se les asigna paso más adelante (`REQ-CORE` sigue sin cumplir `CLAUDE.md §10` hasta entonces).
- **B** · Añadirlos a 1.9e: módulos en **solo lectura** (no hay hoy ningún módulo con `settings` que configurar, así que un formulario de `settings` sería una pantalla vacía) con la fecha de alta como «aviso» (`ADR-045`, `enabled_at`), y perfil propio como una ruta de autoservicio `[]` más (amplía la lista cerrada de `RN-CORE-24` de seis a siete, con su justificación).
- **C** · Solo el perfil propio en 1.9e.

**Recomendación: B.** Son dos pantallas pequeñas sobre API ya existente, sin servidor nuevo, y con ellas `REQ-CORE` puede cerrarse completo al terminar 1.9b-1.9f. Pero amplía el alcance fijado: decide el usuario.

#### `OPEN-CORE-32` · Columnas del CSV de usuarios — **RESUELTA** (2026-10-01, decisión del usuario): opción B

> El fichero **no** contiene `document_type`, `document_number` ni `birth_date`. Esquema completo, orden de columnas y filas y tipos en §14.11.1 (`RN-CORE-85`); verificación de la ausencia en `CA-CORE-227`. Añadir esas columnas más adelante es aditivo (`ADR-055 §2.4`) pero requiere decisión expresa del usuario (`INV-008`). La recomendación original (A) se conserva abajo como contexto; no rige.

`ADR-054 §8.1` exige esquema fijo; `ADR-055` exige nombres técnicos iguales a los de la API. Qué campos salen del sistema es una decisión de producto con peso de protección de datos.

- **A** · `public_id`, `status`, `deleted_at`, `created_at` más **exactamente las columnas de la cabecera de importación** (`email`, `given_name`, `family_name_1`, `family_name_2`, `document_type`, `document_number`, `birth_date`, `contact_email`, `contact_phone`, `locale`, `roles` con códigos de rol separados por `|`, como en la importación), en ese orden; filas por `family_name_1`, `given_name`, `public_id`. Permite reimportar un fichero editado (salvo el dialecto: la importación ya acepta coma).
- **B** · Igual que A **sin** `document_type`, `document_number` ni `birth_date`.
- **C** · Solo las columnas visibles por defecto en el listado (`RN-CORE-67`).

**Recomendación: A**, porque el caso de uso real de secretaría con un listado de personal suele incluir el documento, el permiso es exclusivo del administrador por defecto, la exportación queda auditada con sus filtros y caduca en siete días, y la coincidencia con la cabecera de importación evita un segundo esquema. **B** si el usuario prefiere que el documento de identidad no salga nunca en bloque; es aditivo pasar de B a A (`ADR-055 §2.4`), no al revés.

#### `OPEN-CORE-33` · Filtro por usuario en auditoría (`REQ-CORE-005`) — RESUELTA (2026-10-03): C

El requisito exige filtrar por usuario. El parámetro existe (`actor_id`, ULID). El componente de 1.9 solo tiene `enum`, `dateRange` y `boolean`, cerrados por §13.7.

- **A** · Nuevo tipo de filtro **`entity`** en `src/data-table` (aditivo): selección única por búsqueda asíncrona con una función de búsqueda que aporta el consumidor (aquí, `GET /users?q=`, que exige `usuario.leer`; sin él, el filtro no se ofrece, `RN-CORE-62`), serializado como `actor_id=<ulid>`; la etiqueta se resuelve con `GET /users/{id}` al restaurar desde la URL. Amplía la lista cerrada de §13.7.
- **B** · Sin control en la barra: solo se llega filtrado por usuario desde su ficha («Ver su actividad», navegación con `actor_id` en la URL y un indicador «Filtrado por: {nombre}» con la acción de quitarlo).
- **C** · A y B.

**Recomendación: C.** A es lo que pide el requisito; B cuesta casi nada sobre A y es el camino que usará quien investiga a una persona concreta. Si el usuario prefiere no ampliar el componente aún, B solo cumple el requisito a medias: hay que decirlo.

#### `OPEN-CORE-34` · Opciones de los filtros de módulo y tipo de entidad en auditoría — RESUELTA (2026-10-03): B

El filtro `module` lo exige `REQ-CORE-005`; `auditable_type` es útil pero no exigido. Ninguno tiene hoy un *endpoint* de opciones accesible con `auditoria.leer`: `GET /modules` exige `modulo.leer` (un rol personalizado con `auditoria.leer` y sin `modulo.leer` se quedaría sin el filtro), y los alias de `auditable_type` viven en el *morph map* de PHP.

- **A** · `module` con las opciones de `GET /modules` solo si el usuario tiene `modulo.leer`; sin filtro de `auditable_type`.
- **B** · *Endpoint* nuevo de solo lectura, `GET /audit-logs/facets` (`auditoria.leer`), que devuelve los códigos de módulo filtrables y los alias de `auditable_type` con su módulo. Sin traducción en servidor (`ADR-038 §3.2`); el cliente traduce con rama por defecto.
- **C** · Constantes en el cliente.

**Recomendación: B.** Cumple el requisito para cualquier rol con `auditoria.leer`, no duplica catálogos de servidor en el cliente (C diverge en silencio en cuanto un módulo nuevo declare sus alias) y cuesta un controlador de lectura sin esquema. A es aceptable si se asume que solo administradores consultan la auditoría.

#### `OPEN-CORE-35` · Zona horaria de los filtros y fechas de auditoría — RESUELTA (2026-10-03): A

§13.7 dice que el consumidor convierte «el día local del centro» a instante UTC, pero la SPA **no conoce la zona horaria del centro** salvo con `configuracion.leer` (`GET /tenant/settings`); `GET /tenant/branding` no la incluye y su contrato prohíbe añadir campos sin justificarlos como información pública.

- **A** · Zona del navegador, para filtrar **y** para mostrar (coherentes entre sí), documentado en el manual.
- **B** · Añadir la zona del centro a `GET /me` (cambio compatible) y usarla para filtrar y mostrar.
- **C** · Enviar fechas sin hora y que el servidor las interprete en la zona del centro (cambia la semántica documentada del parámetro).

**Recomendación: A** para 1.9b: los centros objetivo están en la península y su personal consulta desde allí; es coherente con cómo ya pinta fechas todo el producto (`Intl` del navegador). B si el usuario prevé personal que consulte desde otra zona; es aditivo.

#### `OPEN-CORE-36` · Detalle de un rol con sus concesiones — RESUELTA (2026-10-03): A

`GET /roles/{id}` devuelve las concesiones con `code`, `resource`, `action`, `effect` y `scope`. No hay catálogo de nombres legibles de recursos en el cliente.

- **A** · 1.9b solo con el listado; el detalle con concesiones llega con la matriz de `1.5b`, que tiene que construir ese catálogo de todas formas.
- **B** · Detalle en 1.9b mostrando el código del permiso tal cual, con `effect` y `scope` traducidos.

**Recomendación: A.** B construye una pantalla que `1.5b` sustituirá enseguida, con un código técnico visible para el usuario como único contenido.

#### `OPEN-CORE-37` · Grupo `security` de la configuración (`REQ-AUTH`) — **RESUELTA** (2026-10-03, decisión del usuario): opción B

`PATCH /tenant/settings` admite `security.session_timeout_minutes`, `security.mfa_allowed_methods` y `security.mfa_grace_period_days` con el mismo permiso. No se ha comprobado si `/administracion/mfa` ya edita alguno (§14.0).

- **A** · Incluir el grupo completo en la pantalla de configuración.
- **B** · Incluir solo lo que **no** edite ya otra pantalla, comprobado por el implementador antes de escribir; lo que ya se edite en `/administracion/mfa` se enlaza, no se duplica.
- **C** · Dejarlo fuera de 1.9b.

**Recomendación: B.** Dos formularios para el mismo dato terminan divergiendo en validación y mensajes.

#### `OPEN-CORE-38` · Idioma de los mensajes de importación (#285)

`message` se escribe en el idioma del proceso del trabajo (`en`) y se persiste en `error_summary` y en `report.csv`. `ADR-055 §1` dice que es un mensaje legible dirigido a quien subió el fichero.

- **A** · Corregir el trabajo (S9): resuelve el idioma de quien subió el lote (`user_imports.created_by` → `person.locale` si está entre los activos del centro, si no `default_locale`, la misma precedencia que `RN-CORE-34`), **sin esquema nuevo**. La pantalla muestra `message` tal cual. Limitación aceptada: otro administrador que abra el lote lo lee en el idioma de quien lo subió.
- **B** · La pantalla ignora `message` y traduce `code` en el cliente con un catálogo propio de los códigos de error de importación; `report.csv` queda como está.
- **C** · A y B.

**Recomendación: A.** Cierra #285 donde nace, para la pantalla y el informe a la vez, y evita el catálogo duplicado que `ADR-038 §6.3` desaconseja. La limitación de un segundo lector es la misma que ya tiene cualquier mensaje persistido.

#### `OPEN-CORE-39` · Contrato de `fallida` en `GET /data-exports/{id}` — **RESUELTA** (2026-10-01, decisión del usuario): opción A

§14.16 hallazgo 1. Aplicada como S4 (§14.11, `api.md §14.2`, `CA-CORE-230`).

- **A** · Servidor: `fallida` responde `200` con `status: "fallida"` y `error_code` (alinea el código con `api.md §8` y §13.14.1). Sin cambio en el cliente de 1.9.
- **B** · Cliente: distinguir el `409` por su código (`core.validation.export_failed`) y tratarlo como fallida. El servidor sigue contradiciendo su documentación.

**Recomendación: A.** El documento aprobado describe A, el cliente ya está escrito para A, y no hay producción ni más clientes que el propio (`H0`).

#### `OPEN-CORE-40` · Ampliaciones del componente de tablas — **RESUELTA** (2026-10-01, decisión del usuario): opción A

> Booleano de dos estados en 1.9b (`RN-CORE-68`); `enum` de selección única con valor inicial en 1.9f (`MfaExemptionsArea`); página única sintética para `error_summary` (1.9c) y módulos (1.9e). Sin modo `local`. Las tres son ampliaciones de la lista cerrada de filtros de §13.7, con tests propios.

Tres necesidades que el componente de 1.9 no cubre: (1) filtro de **selección única con valor inicial** (paridad de `MfaExemptionsArea`: `live` por defecto, con «todos»); (2) filtro de **dos estados** para «incluir dados de baja» (`RN-CORE-68`); (3) presentar `error_summary` sin modo `local` (`RN-CORE-74`).

- **A** · Ampliar `src/data-table` de forma aditiva: opción `multiple: false` y `initial` en el filtro `enum`; variante de dos estados del filtro `boolean`; (3) con la función de petición de página única de `RN-CORE-74`. Precisa la lista cerrada de §13.7 (no `ADR-054`, que no la enumera). Con tests propios y sin tocar a los consumidores existentes.
- **B** · Como A, y además el **modo `local`** que §13.21 (`OPEN-CORE-26`) dejó para su primer consumidor real.
- **C** · No ampliar: `MfaExemptionsArea` pierde su valor inicial (muestra todas al entrar), «incluir dados de baja» usa el filtro de tres estados, y `error_summary` como en A.

**Recomendación: A.** Mantiene la paridad que §13.15 exigió en la primera migración y no construye `local` sin la necesidad real de `1.5b`. C es más barata pero cambia lo que ve el administrador de MFA al entrar, sin que nadie lo haya pedido.

#### `OPEN-CORE-41` · «Mis exportaciones» (`GET /data-exports`)

`OPEN-CORE-25` lo remitía a 1.9b. Con dos pantallas exportables, el aviso de §13.14.4 aparece en dos sitios; el índice ya existe (`datos.md` A.7).

- **A** · Seguir sin él en 1.9b.
- **B** · Añadir `GET /data-exports` (por identidad: solo las propias, y de cada `kind` solo si se tiene su permiso de exportar) y una pantalla en «Mi cuenta».

**Recomendación: A.** Mientras #128 siga abierto ninguna exportación termina en un entorno real, y B añade un *endpoint*, una regla de autorización mixta y una pantalla a un paso ya demasiado grande. Reconsiderar al cerrar #128.

#### `OPEN-CORE-42` · Mecanismo de confirmación (`RN-CORE-64`) — **RESUELTA** (2026-10-01, decisión del usuario): opción A

Hoy conviven confirmación en línea (`SessionsView`, `MfaExemptionsArea`) y `window.confirm` (`AdminSsoView`, sin control de foco ni estilo, y con texto del navegador).

- **A** · Vendorizar `alert-dialog` de shadcn-vue sobre Reka UI (ya instalada, sin dependencia nueva, `docs/design-system.md §12.2`), un único componente de aplicación de confirmación, y usarlo en todas las pantallas de 1.9b y en las tres migradas.
- **B** · Generalizar la confirmación en línea de `SessionsView`.

**Recomendación: A.** Un diálogo modal con foco atrapado es el patrón accesible estándar para una acción destructiva y sustituye `window.confirm`; la confirmación en línea dentro de una fila de tabla no encaja en la vista de tarjetas.

#### `OPEN-CORE-43` · Asignación de roles en la ficha de usuario — **RESUELTA** (2026-10-01, decisión del usuario): opción A

El alcance dice «roles (solo lectura)». La asignación de roles **a un usuario** (`PUT /users/{id}/roles`, `asignacion_rol.*`, `RPERM-013`) no es edición del rol, sino de su relación con el usuario, y `REQ-CORE-004` pide «asignación múltiple de roles por usuario».

- **A** · Entra en 1.9b: selector múltiple de roles en el alta y sección «Roles» en la ficha, con `RN-CORE-06`/`07`/`RPERM-013` decididos por el servidor.
- **B** · Fuera: los roles de un usuario se ven en la ficha pero solo se asignan por API hasta `1.5b`.

**Recomendación: A.** Sin ella, dar de alta un usuario desde la interfaz produce una cuenta sin permisos que solo puede completarse por API, y la API ya existe y está probada desde 1.1/1.5.

#### `OPEN-CORE-44` · Acciones de plataforma en la pantalla de auditoría

`GET /platform-actions` (`REQ-BO-007`, `auditoria.leer`) muestra al centro lo que el Super Administrador hizo sobre él.

- **A** · Fuera de 1.9b; lo recoge el paso de interfaz de `REQ-BO` o uno de cierre de `REQ-CORE`.
- **B** · Segunda tabla en la pantalla de auditoría (en memoria, sin estado en URL, `RN-CORE-54`).

**Recomendación: A.** No está en el alcance fijado y su especificación es de `REQ-BO`.

#### `OPEN-CORE-45` · Qué filas muestra la pantalla de módulos contratados — **RESUELTA** (2026-10-03, decisión del usuario): opción A

Surge al aplicar `OPEN-CORE-31` = B. `GET /modules` devuelve **todo el catálogo** de módulos no retirados, incluidos los que el centro no tiene contratados (`enabled: false`, `public_id: null`, y con `disabled_at` los que se descontrataron). `REQ-CORE-002` dice «**consultar los módulos contratados**», y ningún requisito dice si el centro debe ver además lo que no tiene. Es decisión de producto (y con lectura comercial: enseñar al centro el catálogo completo), no técnica.

- **A** · Solo los contratados (`enabled: true`). La función de petición filtra en el cliente sobre la respuesta completa (no es filtrado de seguridad: el servidor ya entrega el catálogo a quien tiene `modulo.leer`).
- **B** · Todo el catálogo, con el estado (contratado / no contratado / descontratado el {fecha}).
- **C** · Contratados por defecto, con un filtro para ver también los demás.

**Recomendación: A**, por ser la lectura literal del requisito y la que no convierte una pantalla de consulta en un escaparate comercial sin que nadie lo haya decidido. B o C si el usuario quiere que el centro vea lo que podría contratar.

### 14.18 Criterios de aceptación

Vitest salvo los marcados **[Playwright]** o **[Pest]**. Cada test cita su ID (`INV-015`). Ningún criterio depende ya de una pregunta abierta de 1.9e (`OPEN-CORE-37` y `-45` resueltas el 2026-10-03); todos son firmes.

#### Navegación y permisos

- **`CA-CORE-208`** [`RN-CORE-60`, `ADR-053 §2`] · **Dado** el registro ensamblado, **entonces** existen las rutas de §14.3 del sub-paso entregado, todas con `meta.layout === 'app'` y `meta.permissions` igual a la columna 2 de §14.3 —no vacía en todas salvo `core-profile`—, y los cinco tests de coherencia de `ADR-053 §2` siguen en verde; la lista cerrada de `RN-CORE-24` no cambia en 1.9b, 1.9c, 1.9d ni 1.9f, y en 1.9e gana exactamente `core-profile` (`CA-CORE-264`).
- **`CA-CORE-209`** [`RN-CORE-60`, `REQ-CORE-008`] · **Dado** un `/me.permissions` con solo `usuario.leer`, **cuando** se carga el *shell*, **entonces** la sección «Administración» contiene «Usuarios» y ninguna otra entrada de §14.3; **y dado** uno sin ningún permiso de `REQ-CORE`, ninguna entrada de §14.3 aparece en el documento.
- **`CA-CORE-210`** [`RN-CORE-61`, `RN-CORE-23`] · **Dado** la ficha de un usuario y un `/me.permissions` con `usuario.leer` y sin `usuario.actualizar`, `usuario.eliminar` ni `invitacion.crear`, **entonces** no hay en el documento ningún control de editar, activar, desactivar, dar de baja, restaurar ni invitar; **y** `CA-CORE-102` sigue en verde (ningún literal de código de rol en `src/`).
- **`CA-CORE-211`** [`RN-CORE-61`] · **Dado** un usuario cuya ficha es la suya propia (mismo `public_id` que `/me`), **entonces** las acciones de estado, baja y roles están deshabilitadas con la explicación traducida; **y dado** otra ficha cuyo `DELETE` responde `409` con `detail`, ese `detail` se muestra con `role="alert"` y la ficha no cambia.
- **`CA-CORE-212`** [`RN-CORE-62`] · **Dado** un usuario con `usuario.leer` y sin `rol.leer`, **cuando** abre el listado de usuarios, **entonces** no se pide `GET /roles` y no existe el filtro de rol; **y con** `rol.leer`, se pide una vez y el filtro ofrece los roles recibidos.

#### Usuarios

- **`CA-CORE-213`** [`RN-CORE-67`, `REQ-CORE-003`] · **Dado** `GET /users` con dos usuarios, **entonces** la tabla tiene `caption` traducido, la columna «Nombre» es `th scope="row"` con enlace a la ficha, y el documento **no** contiene el `document_number` ni la `birth_date` de ninguno.
- **`CA-CORE-214`** [`RN-CORE-67`, `RN-CORE-39`, S5] · **Dado** la columna «Correo de acceso», **cuando** se activa su ordenación dos veces, **entonces** las peticiones llevan `sort=email` y `sort=-email`; **y** [Pest] `GET /users?sort=-email` responde `200` ordenado de forma descendente.
- **`CA-CORE-215`** [`RN-CORE-54`] · **Dado** el listado de usuarios con `status=activo`, página 2 y `sort=-created_at`, **cuando** se abre la ficha de un usuario y se vuelve atrás, **entonces** la tabla restaura la misma consulta y la URL no contiene `q`.
- **`CA-CORE-216`** [`RN-CORE-68`, S6] · **Dado** un usuario con `usuario.leer` y sin `usuario.eliminar`, **entonces** no existe el filtro de dados de baja; **y con** `usuario.eliminar`, al activarlo la petición lleva `include_deleted=true` y la ficha de un usuario dado de baja se pide con `include_deleted=true` y muestra «Restaurar»; **y** [Pest] `GET /users/{id}?include_deleted=true` de un usuario eliminado responde `200` con `usuario.eliminar` y `403` sin él, y sin el parámetro responde `404` (`CA-CORE-014`).
- **`CA-CORE-217`** [`RN-CORE-65`, `INV-010`] · **Dado** el formulario de alta, **cuando** `POST /users` responde `422` con errores en `email` y `person.locale`, **entonces** cada mensaje del servidor aparece bajo su campo con `aria-invalid="true"` y `aria-describedby`, el foco pasa al campo de correo y un resumen con `role="alert"` enumera los dos.
- **`CA-CORE-218`** [`RN-CORE-65`, `ADR-038 §9.2`] · **Dado** la edición de un usuario en la que solo se cambia el teléfono de contacto y se vacía el segundo apellido, **cuando** se guarda, **entonces** el cuerpo de `PATCH /users/{id}` es exactamente `{"person":{"contact_phone":"…","family_name_2":null}}`.
- **`CA-CORE-219`** [`RN-CORE-08`, `RPERM-013`, `OPEN-CORE-43`] · **Dado** el alta con roles, **cuando** `POST /users` responde `403` por `RPERM-013`, **entonces** el `detail` del servidor aparece junto al selector de roles y no se navega.
- **`CA-CORE-220`** [`RN-CORE-64`] · **Dado** la ficha de un usuario activo, **cuando** se pulsa «Dar de baja», **entonces** no sale ninguna petición hasta confirmar; la confirmación nombra al usuario, recibe el foco, `Esc` la cierra sin petición y devuelve el foco al botón; **y al confirmar** sale exactamente un `DELETE /users/{id}`.
- **`CA-CORE-221`** [`RN-CORE-66`, `RN-CORE-19`] · **Dado** un alta con invitación, **cuando** `POST /users` responde `201` con `invitation.expires_at`, **entonces** se navega a la ficha, aparece un mensaje con `role="status"` con la caducidad formateada, y ningún texto del documento ni del almacenamiento del navegador contiene un token.

#### Exportación de usuarios

- **`CA-CORE-222`** [`RN-CORE-69`, `RN-CORE-51`, `RN-CORE-57`] · **Dado** el listado con `usuario.exportar`, `status=activo,inactivo` y `role=<ulid>`, **cuando** se pulsa exportar, **entonces** `POST /users/exports` recibe `{"status":["activo","inactivo"],"role":["<ulid>"]}` sin `q`, `sort`, `page` ni `per_page`; **y sin** `usuario.exportar` no hay control de exportación.
- **`CA-CORE-223`** [Pest] [`RN-CORE-85`, `RPERM-003`, `INV-002`] · **Dado** un usuario sin `usuario.exportar`, **cuando** pide `POST /users/exports`, **entonces** `403`; **y con** él, `202` con `public_id`, una fila en `data_exports` con `kind = users`, el trabajo encolado en `core-exports` (no ejecutado en la petición, `INV-012`) y un registro `exported` en `audit_logs`.
- **`CA-CORE-224`** [Pest] [`RN-CORE-58`, `RN-CORE-85`] · **Dado** `POST /users/exports` con `q`, **entonces** `422` con el código propio del recurso y no se crea ninguna fila en `data_exports`; **y** `data_exports.filters` de cualquier exportación de usuarios no contiene nunca la clave `q`.
- **`CA-CORE-225`** [Pest] [`ADR-054 §8.2`, `RN-CORE-85`] · **Dado** la especificación OpenAPI, **entonces** todo parámetro de filtro de `GET /users` (salvo `q`, `sort`, `page`, `per_page`) existe en el esquema del cuerpo de `POST /users/exports` con el mismo nombre; **y** `include_deleted=true` sin `usuario.eliminar` responde `403`, igual que en el listado.
- **`CA-CORE-226`** [Pest] [`RN-CORE-59`, `ADR-055`, `CA-CORE-207`] · **Dado** dos solicitantes con `person.locale` `es-ES` y `de`, **cuando** exportan usuarios con los mismos filtros y se ejecuta el trabajo, **entonces** los dos ficheros son idénticos byte a byte, la cabecera coincide con el esquema documentado en OpenAPI y los valores de `status` son los códigos técnicos; **y** el generador no referencia `__()` ni `trans()`. **Este test es la verificación pendiente de `CA-CORE-207`** (§14.12 punto 1).
- **`CA-CORE-227`** [Pest] [`RN-CORE-85`, `RN-CORE-48`, `RN-CORE-36`, `OPEN-CORE-32`, `INV-008`] · **Dado** tres usuarios con `document_type`, `document_number` y `birth_date` informados (valores distinguibles de prueba, `REQ-SEED-005`), uno cuyo `given_name` empieza por `=`, otro cuyo `contact_phone` empieza por ` +`, y uno con dos roles, **cuando** se genera el CSV, **entonces**: la cabecera es exactamente `public_id,status,deleted_at,created_at,email,given_name,family_name_1,family_name_2,contact_email,contact_phone,locale,roles`, en ese orden; **ninguna** cabecera es `document_type`, `document_number` ni `birth_date` y **ningún** valor de documento ni fecha de nacimiento de los tres usuarios aparece en ninguna celda del fichero; las filas siguen el orden `family_name_1`, `given_name`, `public_id`; `roles` contiene los códigos ordenados y unidos con `|`; las dos celdas peligrosas llevan apóstrofo; y el fichero tiene BOM y CRLF.
- **`CA-CORE-228`** [Pest] [`INV-001`] · **Dado** una exportación de usuarios del tenant A, **cuando** un usuario del tenant B con `usuario.exportar` pide `GET /data-exports/{id}`, **entonces** `404`; **y** el trabajo del tenant A no incluye ninguna fila del tenant B.

#### `GET /data-exports/{id}`

- **`CA-CORE-229`** [Pest] [`RN-CORE-86`, S3] · **Dado** una exportación `kind = users` solicitada por un usuario con `usuario.exportar` y sin `auditoria.exportar`, **cuando** él mismo pide su estado, **entonces** `200`/`409` según su estado (no `403`); **y dado** una `kind = audit_logs` y un solicitante que ha perdido `auditoria.exportar`, `403`; **y** otro usuario del mismo centro con el permiso recibe `403` (no es el solicitante).
- **`CA-CORE-230`** [Pest] [`RN-CORE-86`, S4, `OPEN-CORE-39`] · **Dado** una exportación `fallida` con `error_code`, **cuando** su solicitante pide el estado, **entonces** `200` con `status: "fallida"`, `error_code` y `download_url: null`; **y** una `pendiente` sigue respondiendo `409`. **[Vitest]** Con esa respuesta real simulada, el componente de 1.9 pasa al estado de fallo con el mensaje traducido de `error_code` sin esperar a la duración máxima.

#### Invitaciones

- **`CA-CORE-231`** [`RN-CORE-70`, S7] · **Dado** el filtro de estado con `vigente` y `caducada` marcados, **entonces** la petición lleva `status=vigente,caducada`; **y** [Pest] `GET /invitations?status=vigente,caducada` devuelve la unión de ambos y `status=vigente,otro` responde `422`.
- **`CA-CORE-232`** [`RN-CORE-70`] · **Dado** una fila `vigente`, **entonces** ofrece «Revocar» y no «Reenviar»; **dado** una `caducada`, ofrece «Reenviar» y no «Revocar»; **dado** una `aceptada`, ninguna; **y cuando** «Reenviar» responde `429` con `Retry-After: 120`, el mensaje contiene «120».

#### Importación

- **`CA-CORE-233`** [`RN-CORE-71`] · **Dado** el formulario de subida, **entonces** muestra la cabecera exacta de `api.md §7` y la casilla de invitaciones marcada; **cuando** se sube un fichero y `POST /user-imports` responde `202`, se navega al detalle del lote; **y cuando** responde `415`, se muestra el mensaje del servidor sin navegar.
- **`CA-CORE-234`** [`RN-CORE-72`] · **Dado** un lote que responde `subido`, luego `validando` y luego `validado`, con temporizadores simulados, **entonces** nunca hay dos consultas en vuelo, la espera crece con las constantes de `src/data-table`, el cambio de estado se anuncia con `role="status"`, y tras `validado` no sale ninguna consulta más; **y dado** un lote que sigue en `subido` hasta agotar la duración máxima, aparece «Comprobar de nuevo» y se deja de consultar.
- **`CA-CORE-235`** [`RN-CORE-73`, `INV-011`] · **Dado** un lote `validado` con `row_count = 5` y `error_count = 2`, **cuando** se pulsa «Ejecutar», **entonces** la confirmación dice que se crearán 3 usuarios, si se enviarán invitaciones y que la importación no se deshace; **cuando** se confirma y la petición falla por red y se reintenta, **entonces** las dos peticiones llevan la **misma** `Idempotency-Key`, con formato ULID; **y** una confirmación posterior lleva una clave distinta.
- **`CA-CORE-236`** [`RN-CORE-73`] · **Dado** que `POST /user-imports/{id}/execute` responde `202` con `Idempotency-Replayed: true`, **entonces** la vista lo trata como éxito y pasa a seguir el estado; **y dado** `409`, muestra el `detail` y no reintenta.
- **`CA-CORE-237`** [`RN-CORE-74`, `RN-CORE-53`] · **Dado** un lote con `error_count = 60` y 50 entradas en `error_summary`, **entonces** las incidencias se pintan con el componente de tablas (no con `<table>` propio, `CA-CORE-200` en verde) con línea, columna y motivo, el aviso de «solo las 50 primeras» y un enlace cuyo `href` es `report_url`; **y dado** un lote `fallido` por cabecera, no existe «Ejecutar».
- **`CA-CORE-238`** [Pest] [S8] · **Dado** `GET /user-imports` y `GET /user-imports/{id}`, **entonces** cada recurso incluye `created_at` en ISO 8601 UTC.
- **`CA-CORE-239`** [Pest] [`OPEN-CORE-38` = A, S9, #285] · **Dado** un lote subido por un usuario con `person.locale = 'fr'` en un centro con `fr` activo, **cuando** se valida un fichero con un correo duplicado, **entonces** `error_summary[].message` y la columna `message` de `report.csv` están en francés; **y dado** un usuario con un idioma no activo en el centro, en el `default_locale` del centro.

#### Roles

- **`CA-CORE-240`** [`RN-CORE-75`] · **Dado** un usuario con `rol.leer`, `rol.crear`, `rol.actualizar` y `rol.eliminar`, **cuando** abre `/administracion/roles`, **entonces** ve el listado con nombre, tipo, MFA obligatorio, acceso a datos especiales y número de usuarios, y no existe en el documento ningún control de crear, clonar, editar ni borrar roles.
- **`CA-CORE-241`** [`RN-CORE-63`] · **Dado** el listado de roles cargado en `es`, **cuando** se cambia el idioma a `en` con el selector de 1.8, **entonces** se vuelve a pedir la página actual (una sola petición) y se muestran los nombres que devuelve el servidor; **y** el listado de usuarios hace lo mismo con su columna de roles.

#### Auditoría

- **`CA-CORE-242`** [`RN-CORE-76`, `ADR-038 §4.4`] · **Dado** `/administracion/auditoria`, **entonces** la tabla está en modo `cursor` («Cargar más», sin paginador ni total), no tiene búsqueda ni cabeceras ordenables, y la URL nunca contiene `cursor`.
- **`CA-CORE-243`** [`RN-CORE-76`, `OPEN-CORE-35`] · **Dado** un rango de fechas del 1 al 3 de marzo, **entonces** la petición lleva `occurred_at_from` y `occurred_at_to` como instantes ISO 8601 que corresponden al inicio del día 1 y al final del día 3 en la zona fijada por `OPEN-CORE-35`; **y** los nueve valores de `event` aparecen como opciones del filtro con su etiqueta traducida en los cuatro idiomas.
- **`CA-CORE-244`** [`REQ-CORE-005`, `OPEN-CORE-33`] · **Dado** un usuario con `auditoria.leer` y `usuario.leer`, **cuando** filtra por un usuario elegido en el filtro de actor (A) o llega desde «Ver su actividad» de su ficha (B), **entonces** la petición lleva `actor_id=<ulid>` y el filtro activo muestra el nombre del usuario; **y sin** `usuario.leer` no se ofrece el filtro ni se pide `GET /users`. *(Se ajusta a la opción elegida.)*
- **`CA-CORE-245`** [`REQ-CORE-005`, `OPEN-CORE-34`, S7] · **Dado** el filtro de módulo con dos módulos marcados, **entonces** la petición lleva `module=<a>,<b>`; **y** [Pest] `GET /audit-logs?module=a,b` devuelve la unión y `actor_type=user,system` la unión de ambos tipos; **y** `POST /audit-logs/exports` acepta los mismos valores como *array* (paridad). *(El origen de las opciones se ajusta a la opción elegida.)*
- **`CA-CORE-246`** [`RN-CORE-77`, `CA-CORE-052`, `ADR-035`] · **Dado** una entrada cuyo `changes` contiene `status {from: pendiente, to: activo}` y `document_number {redacted: identifier, from_empty: false, to_empty: false}`, **cuando** se abre «Ver cambios», **entonces** el panel es un diálogo con foco atrapado que muestra `pendiente → activo` y, para `document_number`, «valor no registrado» con el motivo traducido, sin ningún otro valor; `Esc` lo cierra y el foco vuelve al botón; **y** no sale ninguna petición al abrirlo.
- **`CA-CORE-247`** [`RN-CORE-78`, `RN-CORE-52`] · **Dado** la tabla de auditoría con `auditoria.exportar` y filtros de `event` y `actor_type`, **cuando** se exporta, **entonces** `POST /audit-logs/exports` recibe esos filtros como *arrays* y nada más; **y al** alcanzar 1.000 filas, el aviso de tope ofrece exportar.

#### Configuración del centro y marca

- **`CA-CORE-248`** [`RN-CORE-79`] · **Dado** un usuario con `configuracion.leer` y sin `configuracion.actualizar`, **entonces** `/administracion/centro` muestra los valores y no contiene ningún campo editable, botón de guardar ni acción de «Sustituir»/«Eliminar» activo; **y** no sale ninguna petición `PATCH`/`PUT`/`DELETE`.
- **`CA-CORE-249`** [`RN-CORE-79`, `ADR-038 §9.2`] · **Dado** el grupo «Fiscal» con solo el municipio cambiado, **cuando** se guarda, **entonces** el cuerpo es exactamente `{"fiscal":{"city":"…"}}` y los demás grupos no se envían.
- **`CA-CORE-250`** [`RN-CORE-80`, `RUX-BRAND-006`] · **Dado** una paleta editada con contraste 3,1:1, **entonces** la vista previa muestra «3,1:1» (formato del idioma activo) y el aviso de que no alcanza 4,5:1; **cuando** se guarda y el servidor responde `422 contrast_insufficient` con `ratio` y `required`, se muestran los valores del servidor; **y cuando** una paleta válida responde `200`, se llama una vez a `useTenantBranding().refresh()`.
- **`CA-CORE-251`** [`RN-CORE-81`, `RN-CORE-13`] · **Dado** los idiomas activos `es-ES` y `en` con `en` por defecto, **cuando** se desmarca `en`, **entonces** «idioma por defecto» deja de ofrecer `en` y el formulario no permite guardar hasta elegir otro; **y dado** que la interfaz está en `en`, aparece el aviso de que pasará a verse en el idioma por defecto.
- **`CA-CORE-252`** [`RN-CORE-82`] · **Dado** la constante de comunidades autónomas del cliente y `AutonomousCommunity::CODES` del servidor, **entonces** contienen exactamente los mismos códigos, y cada código tiene nombre en `es`, `en`, `de` y `fr`.
- **`CA-CORE-253`** [`RN-CORE-83`, `RSEC-OWASP-012`] · **Dado** «Sustituir logo» con un fichero de 2 MB, **entonces** la vista avisa del límite de 1 MB sin enviar nada; **y dado** un fichero válido cuya subida responde `422` (tipo real distinto), se muestra el mensaje del servidor; **y cuando** responde `200`, se vuelve a pedir `GET /tenant/settings` y se llama a `refresh()` una vez; **y** no se usa `URL.createObjectURL` en ningún fichero de la pantalla (`CA-CORE-192` sin excepción nueva).
- **`CA-CORE-254`** [`RN-CORE-83`] · **Dado** la imagen de un activo cuya carga falla, **entonces** se pide `GET /tenant/settings` exactamente una vez; **y si** vuelve a fallar, se pinta el estado de error y no hay más peticiones.

#### Migración de las tres tablas

- **`CA-CORE-255`** [`RN-CORE-84`, `RN-CORE-53`, `CA-CORE-200`] · **Dado** cada vista migrada, **entonces** no importa `@/components/ui/table` ni contiene `<table`, su ruta ya no está en la lista de excepciones del test de `RN-CORE-53`, y el test sigue en verde; **y** al terminar 1.9f la lista de excepciones está **vacía**.
- **`CA-CORE-256`** [`RN-CORE-84`, `REQ-AUTH-003`] · **Dado** `MfaExemptionsArea` migrada, **entonces** al entrar la primera petición es `GET /mfa-exemptions` con `state=live` y el filtro de estado es de selección única con «todos» disponible, la revocación sigue pidiendo confirmación (diálogo de `RN-CORE-64`) y llama al mismo *endpoint*, el formulario de concesión no cambia, y todos sus tests preexistentes siguen en verde.
- **`CA-CORE-257`** [`RN-CORE-84`, `REQ-AUTH-004`] · **Dado** `AdminSsoView` migrada con 30 proveedores, **entonces** la primera petición lleva `per_page=25` y el paginador muestra 2 páginas; «Eliminar» de un proveedor SAML muestra el aviso adicional de SAML en la confirmación de `RN-CORE-64` y no usa `window.confirm`; y cada botón de fila tiene un nombre accesible con el nombre del proveedor.
- **`CA-CORE-258`** [`RN-CORE-84`, `REQ-AUTH-005`] · **Dado** `SessionsView` migrada, **entonces** una sesión con `ip_address: null` muestra el valor vacío común (`CA-CORE-201`), revocar la sesión actual navega a `/entrar`, «Cerrar las demás sesiones» sigue fuera de la tabla con su confirmación, y la vista ya no importa `vue-i18n` directamente.
- **`CA-CORE-259`** [`RUX-RESP-004`] **[Playwright]** · **Dado** `/administracion/usuarios`, `/administracion/auditoria` y `/cuenta/sesiones` a 320 px, **entonces** en las tres hay lista de tarjetas y no `table`, y `scrollWidth ≤ clientWidth` del documento; **y** a 1024 px, `table`.

#### Transversales

- **`CA-CORE-260`** [`RUX-004`, WCAG 2.2 AA] **[Playwright]** · **Dado** el alta de usuario, la ficha y la configuración del centro, **cuando** se recorren solo con teclado, **entonces** todos los campos y acciones se alcanzan en orden de documento, todo campo tiene etiqueta asociada, los obligatorios están marcados de forma no solo visual, y ningún control queda por debajo de 44 × 44 px con puntero grueso (`OPEN-CORE-14`).
- **`CA-CORE-261`** [`INV-009`] · **Dado** los cuatro `locales/*.json` de `core`, **entonces** toda clave nueva de 1.9b existe en `es`, `en`, `de` y `fr`, y `npm run lint:i18n` termina sin hallazgos.
- **`CA-CORE-262`** [`RN-CORE-50`] · **Dado** `localStorage`, `sessionStorage` e `indexedDB` simulados, **cuando** se recorren el listado y la ficha de usuarios, el detalle de una importación y la auditoría, **entonces** las únicas escrituras son claves `plataforma.table.<tableId>` y no contienen ningún dato de fila ni texto de búsqueda.
- **`CA-CORE-263`** [`CA-CORE-070`, `CA-CORE-073`] · **Dado** cada ficha de §14.3 (usuario, importación, rol si existe), **cuando** su `GET` responde `404`, **entonces** se pinta «no encontrado» dentro del *shell*; **y cuando** responde `403`, «sin acceso» con recarga de `/me` (§12.6).

#### Módulos contratados y perfil propio (1.9e, `OPEN-CORE-31` = B)

- **`CA-CORE-264`** [`RN-CORE-24`, `RN-CORE-88`, `ADR-053 §2`, §14.3.1] · **Dado** el registro ensamblado tras 1.9e, **entonces** las rutas `app`/`bare` con `meta.permissions` vacía son exactamente **siete**: las seis de `CA-CORE-103` más `core-profile`; la constante del test de coherencia (`src/navigation/modules.spec.ts`, comprobación 5) contiene esas siete; y `core-modules` declara `['modulo.leer']`. Con un caso fijo que prueba que una octava ruta con `[]` hace fallar el test.
- **`CA-CORE-265`** [`RN-CORE-88`, `REQ-CORE-003`] · **Dado** un usuario con `/me.permissions` vacío, **cuando** carga el *shell*, **entonces** la sección «Mi cuenta» contiene «Perfil» y la pantalla se abre; muestra nombre y correo de acceso sin campo editable para ellos, campos editables solo para correo y teléfono de contacto, ningún selector de idioma, y **no** sale ninguna petición `GET /me` además de la del *guard*.
- **`CA-CORE-266`** [`RN-CORE-89`, `ADR-038 §9.2`, `CA-CORE-018`] · **Dado** el perfil con solo el teléfono cambiado y el correo de contacto vaciado, **cuando** se guarda, **entonces** el cuerpo de `PATCH /me` es exactamente `{"person":{"contact_phone":"…","contact_email":null}}`; con `200`, el estado de sesión pasa a ser la respuesta (sin otra petición a `/me`) y aparece un mensaje con `role="status"`; **y con** `422` en `person.contact_email`, el mensaje del servidor aparece bajo ese campo con `aria-invalid="true"`.
- **`CA-CORE-267`** [`RN-CORE-87`, `ADR-045`] · **Dado** un usuario con `modulo.leer` y `modulo.actualizar`, **cuando** abre `/administracion/modulos`, **entonces** la tabla (con el componente, `CA-CORE-200` en verde) muestra nombre, estado y fecha de alta formateada; no existe ningún control de edición ni de `settings`; no sale ninguna petición `PATCH`; y una respuesta de `GET /modules` sin `meta` se pinta como una sola página; no se pinta el pie de paginación (propiedad aditiva `hideSinglePageFooter` del componente, issue #326, 2026-10-04; antes, por decisión del usuario del 2026-10-03, «Página 1 de 1» con los botones deshabilitados). Solo se muestran las filas con `enabled: true` (`OPEN-CORE-45` = A); un módulo con `enabled: false` presente en la respuesta no aparece.
- **`CA-CORE-268`** [`RN-CORE-62`] · **Dado** un usuario sin `modulo.leer`, **entonces** no aparece la entrada «Módulos» ni se pide `GET /modules`; **y dado** el listado de módulos en `es`, al cambiar a `en` se vuelve a pedir una vez (`RN-CORE-63`).

#### Ampliaciones del componente de tablas (`OPEN-CORE-40` = A)

- **`CA-CORE-269`** [`RN-CORE-68`, `RN-CORE-84`, §13.7] · **Dado** una tabla de prueba con un filtro booleano de dos estados, **entonces** desmarcado no envía el parámetro y marcado envía `<id>=true`, sin opción «todos»; **y dado** un filtro `enum` con `multiple: false` e `initial: 'live'`, la primera petición lleva `<id>=live`, solo puede haber un valor elegido a la vez y la opción «todos» no envía el parámetro; **y** las tablas existentes (`MfaComplianceArea`, tablas de prueba de 1.9) no cambian de comportamiento (sus tests siguen en verde).

### 14.19 Estrategia de pruebas

| Capa | Herramienta | Qué cubre | Criterios |
|------|-------------|-----------|-----------|
| Servidor | **Pest**, contra PostgreSQL real con RLS (como toda la suite) | S1-S10: permiso, `403`/`404`, aislamiento entre tenants, paridad de filtros contra OpenAPI, `q` ⇒ `422`, cola (`Queue::fake` para «encolado, no ejecutado»), contenido del CSV byte a byte (esquema de §14.11.1 y ausencia de documento y fecha de nacimiento), neutralización, `exported` en auditoría, migración del `CHECK` | 214 (parte), 216 (parte), 223, 224, 225, 226, 227, 228, 229, 230 (parte), 231 (parte), 238, 239, 245 (parte) |
| Pantallas | **Vitest** con las funciones de `api/` del módulo simuladas (sin red), temporizadores simulados para las consultas de estado | Permisos de interfaz, formularios, errores, confirmaciones, foco, i18n, almacenamiento, ampliaciones del componente | 208-213, 214 (parte), 215, 216 (parte), 217-222, 230 (parte), 231 (parte), 232-237, 240-244, 245 (parte), 246-258, 261-269 |
| Navegador real | **Playwright** con `page.route` para las respuestas de la API (mismo patrón que 1.9: sin servidor de API), contra el servidor de Vite | Tarjetas a 320 px, teclado, objetivos táctiles | 259, 260 |
| Arquitectura | **Vitest** (tests existentes de 1.8/1.9, ampliados) | `RN-CORE-53` (lista que se reduce a cero), `RN-CORE-37`, `CA-CORE-102`, `CA-CORE-176` (`tableId` nuevos), `CA-CORE-192` sin excepciones nuevas, constante de rutas con `[]` de `ADR-053 §2` ampliada a siete en 1.9e | 200, 255, 264 |

Cada sub-paso ejecuta la suite completa del lado que toca (Pest con `php -d memory_limit=512M ./vendor/bin/pest`, issue #106; Vitest y Playwright en el contenedor `web`), y el mensaje del último *commit* del lote cita el número real (`CLAUDE.md §3`). Los tests preexistentes de `/administracion/mfa`, `/administracion/sso` y `/cuenta/sesiones` no se reescriben salvo lo que exija el cambio de componente.

### 14.20 Documentación a actualizar al cerrar cada sub-paso

- Este documento: estado de §14 y del sub-paso; `api.md §14`, `datos.md` Parte D, `permisos.md §12`, `operacion.md §13`.
- OpenAPI (`apps/api/openapi/`): S1-S10, incluido el **esquema del fichero** de `POST /users/exports` (`ADR-054 §8.1`).
- `docs/manual-usuario/admin.md`: cada pantalla; para la exportación de usuarios, la **tabla de columnas y códigos** que exige `ADR-055` (Consecuencias), y cómo abrir el CSV en Excel con configuración regional española (§13.14.3). `secretaria.md`/`direccion.md` no existen (issue #65): si se crean, con las pantallas que sus roles pueden ver.
- `docs/i18n.md` (espacios `core.*` nuevos), `docs/design-system.md §12` (`alert-dialog` vendorizado en 1.9b, `OPEN-CORE-42` = A), `ARCHITECTURE.md`, `CHANGELOG.md` (con el cambio de contrato de S4 y el esquema nuevo de CSV).
- `PRIVACY.md`: el CSV de usuarios como tratamiento de exportación (las columnas de §14.11.1, sin documento ni fecha de nacimiento; retención de siete días); lo confirma `doc-reviewer`.
- `PLAN-IMPLEMENTACION.md`: los cinco sub-pasos `1.9b`-`1.9f` decididos (`OPEN-CORE-30` = A), con módulos y perfil en `1.9e`; fuera del ámbito de escritura de esta especificación.

### 14.21 Aprobación

**Aprobada el 2026-10-01** por el usuario, con las respuestas de §14.17 que constan como resueltas: `OPEN-CORE-30` (A), `-31` (B), `-32` (B, contra la recomendación A de esta especificación), `-39` (A), `-40` (A), `-42` (A) y `-43` (A). **1.9b está listo para `implementer` sin reservas**, incluido `POST /users/exports` con el esquema de §14.11.1. Quedan pendientes:

1. Las preguntas abiertas, antes del sub-paso al que bloquean: `-37` y `-45` (1.9e), `-33` a `-36` (1.9d) y `-38` (1.9c) **ya resueltas** (2026-10-03); `-41` y `-44` no bloquean.
2. Los issues de los hallazgos 1 a 6 y 9 de §14.16 están abiertos: #287, #288 y #289.
3. Tener presente que, sin #128, la importación y las exportaciones de este paso no funcionan en un entorno real (§14.15).

### 14.22 Notas de implementación de `1.9b` (2026-10-02)

Lo entregado por el sub-paso `1.9b` y lo que **no** coincide al pie de la letra con lo escrito arriba. Nada de esto reabre la especificación aprobada; cada punto queda a decisión de la sesión orquestadora o del usuario.

**Entregado**: S1 a S7 en `apps/api` (la parte de `GET /audit-logs`/`POST /audit-logs/exports` de S7 es de `1.9d`, como fija la columna «Sub-paso» de §14.11); migración de S2; `apps/web`: `alert-dialog` vendorizado (`src/components/ui/alert-dialog/`), `ConfirmDialog` + `useConfirm` (`src/components/`), las ampliaciones del componente de tablas de `OPEN-CORE-40` = A que corresponden a este sub-paso, y las cinco rutas de usuarios e invitaciones de §14.3 en `core/shell.ts`.

**Desviaciones y puntos que la especificación no resolvía**:

1. **Tipo de documento** (§14.4.3). El servidor lo acepta como texto libre (≤ 32) y solo valida el formato de `DNI` y `NIE` (`DocumentNumberValidator`); **no hay catálogo cerrado**. §14.4.3 manda parar y reportar en ese caso. Se ha **seguido adelante con el contrato real del servidor**: un campo de texto (`maxlength` 32) con una pista, sin inventar una lista de tipos. Si el usuario quiere un selector, necesita antes un catálogo en servidor. **Sustituido en 1.9c por §14.6.4** (catálogo cerrado y selector).
2. **Opción `label` del filtro `enum`** (§14.4.1, `RN-CORE-62`/`-63`). Las opciones del filtro de rol son nombres **ya traducidos por el servidor**, no claves del cliente; `labelKey` solo admite claves y, sin traducción, muestra el código (un ULID). Se añadió a `DataTableEnumFilter.options` el campo opcional `label` (texto literal, con prioridad sobre `labelKey`). Es aditivo, pero **no figura entre las tres ampliaciones que enumera `OPEN-CORE-40`**. **Ratificado el 2026-10-02** (`OPEN-CORE-53`, §14.6.4.9); sigue vigente en 1.9c.
3. **«Importar»** (§14.4.1) enlaza con `core-user-imports`, ruta de `1.9c`. Mientras no esté registrada (`router.hasRoute`), el enlace no se pinta, para no ofrecer uno roto.
4. **`status` y `role` de `GET /users`** siguen sin validar sus valores (como antes); `locale` sí (`InList`, S7). `POST /users/exports` valida los tres por elemento. Es una asimetría heredada: validar `status`/`role` en el listado cambiaría respuestas hoy correctas (`200` vacío) y no figura en S1-S7.
5. **`format`** en `POST /users/exports` es opcional (por defecto `csv`): `CA-CORE-222` da el cuerpo `{"status":[…],"role":[…]}` sin `format`.
6. **Autorización de `GET /data-exports/{id}`** (`RN-CORE-86`): la ruta ya no lleva `permission:` y la comprobación vive en el controlador; sin sesión responde `401` (igual que antes) y un `kind` sin correspondencia, `403`.
7. **`CA-CORE-241`** se prueba solo para el listado de usuarios (el de roles es de `1.9d`); **`CA-CORE-259`**, para el listado de usuarios (auditoría y sesiones llegan con `1.9d` y `1.9f`); **`CA-CORE-260`**, para el alta y la ficha (la configuración del centro llega con `1.9e`); **`CA-CORE-262`** y **`-263`**, para listado, ficha e invitaciones (`UsersAdmin.shell.spec.ts`, pila real, con `indexedDB` espiado y la recarga de `/me` en el `403`; importación y rol llegan en `1.9c`/`1.9d`); **`CA-CORE-269`**, solo la parte del booleano de dos estados (la del `enum` de selección única es de `1.9f`).
8. **Pest completo en el host** (`php -d memory_limit=512M ./vendor/bin/pest`): 786/801. Los 15 fallos son **todos** de SAML (`Signature validation failed`), ajenos a este diff (no toca `Auth`); `SamlCertificatesTest` pasa 9/9 en el contenedor `plataforma-api` sobre `develop`. Causa sin diagnosticar (hipótesis: OpenSSL del anfitrión), issue [#291](https://github.com/pirexia/plataforma-educativa/issues/291); hay que reverificar la suite en el contenedor de referencia o en CI. Los puntos 1 y 2 de arriba, a decisión, en el issue [#292](https://github.com/pirexia/plataforma-educativa/issues/292). Esta implementación corrige en código los hallazgos de [#287](https://github.com/pirexia/plataforma-educativa/issues/287) (S4), [#288](https://github.com/pirexia/plataforma-educativa/issues/288) (S5/S6) y la parte de ruta de [#289](https://github.com/pirexia/plataforma-educativa/issues/289) (S3); el cierre de los issues lo decide la sesión orquestadora tras la revisión.

**Cobertura de los criterios de 1.9b**: `CA-CORE-208` a `-232` con test (Pest o Vitest, `CA-CORE-214`/`-216`/`-230`/`-231` en ambos), `-241` (usuarios), `-259` y `-260` (Playwright), `-261` (`src/modules/core/locales.spec.ts` y `npm run lint:i18n`), `-262`, `-263` y `-269` (booleano). `CA-CORE-102` sigue en verde sin excepciones nuevas.

### 14.23 Notas de implementación de `1.9c` (2026-10-02)

Lo entregado por el sub-paso `1.9c` y lo que **no** coincide al pie de la letra con lo escrito arriba. Nada de esto reabre la especificación aprobada; cada punto queda a decisión de la sesión orquestadora o del usuario.

**Entregado**: §14.6.1 y §14.6.2 (listado y subida, detalle con seguimiento, ejecución idempotente, incidencias, descartar, renovación del enlace del informe) con `RN-CORE-71` a `-74`; S8 (`created_at`), S9 (idioma de los mensajes, `OPEN-CORE-38` = A, #285) y el catálogo cerrado de tipos de documento de §14.6.4 (`RN-CORE-90` a `-93`): enumerado `App\Modules\Core\Domain\DocumentType`, `PersonDocumentRules` (alta y edición por la misma vía), `UserImportRowValidator` con grafía tolerante (`OPEN-CORE-50` = A), migración de datos de la entrega N (`OPEN-CORE-52` = A, `datos.md` Parte E), `enum` de OpenAPI, selector en el formulario de usuario, etiqueta traducida en la ficha, constante `DOCUMENT_TYPES` del cliente con test cruzado (`OPEN-CORE-53` = A) y traducciones `es`/`en`/`de`/`fr`.

**Desviaciones y puntos que la especificación no resolvía**:

1. **`accept=".csv"` (§14.6.1) y `CA-CORE-192`.** El test de `CA-CORE-192` prohíbe el literal `text/csv` en `src/modules/**` sin excepciones. **Decisión del usuario (2026-10-03)**: se mantiene `accept=".csv"` y no se añade ninguna excepción en `architecture.spec.ts`; §14.6.1 queda corregido.
2. **Sin enlace al manual (§14.6.1).** La SPA no publica el manual. **Decisión del usuario (2026-10-03)**: la pantalla remite al apartado por su nombre; §14.6.1 queda corregido.
3. **`send_invitations` en `UserImportResource` (`RN-CORE-73`, `CA-CORE-235`).** **Decisión del usuario (2026-10-03)**: se expone en el listado y el detalle (aditivo, S8 ampliado en §14.11, `api.md §7`, OpenAPI) y la confirmación de ejecutar lo lee **siempre** de la API; se retira el recuerdo en memoria de la subida. Prueba: `CA-CORE-235` en Pest (`UserImportCatalogTest`) y Vitest (`UserImportDetailView.spec.ts`).
4. **Aviso de las 50 primeras (`RN-CORE-74`).** **Decisión del usuario (2026-10-03)**: el aviso sale si `error_count` supera las entradas recibidas **o** si las entradas llegan al tope de 50 (`error_count` cuenta filas y `error_summary` incidencias). Prueba del caso 30 filas × 2 errores en `UserImportDetailView.spec.ts`; §14.6.2 corregido.
5. **`table-id`**: `core.user_imports` y `core.user_import_errors` (snake_case, forma de `RN-CORE-43`; las claves de traducción siguen en camelCase, `core.userImports.*`).
6. **Pest completo en el host**: ver `CHANGELOG.md`. Los fallos son de pruebas de `Auth` (OIDC/SAML) que necesitan red o criptografía del contenedor de referencia, ajenos a este diff.
7. **OpenAPI** (`openapi/components.yaml`) tenía **cinco líneas con YAML inválido** (descripciones con `:` o `,` sin comillas, de 1.9b y de `REQ-AUTH`: `Solo con \`status: fallida\``, `IP completa del propio titular, sin enmascarar.`, `RN-AUTH-59, 10 minutos por defecto.`, `RN-AUTH-54, 5 minutos por defecto.` y `to_status = eliminado, serie aparte de closed.`) que impedían leer el fichero con un analizador YAML; `CA-CORE-279` lo necesita. Se han entrecomillado, sin cambiar su contenido.
8. **Migración de datos y `pgsql_platform`**: `plataforma_owner` queda sujeto a RLS por `FORCE` y no ve ninguna fila de ningún tenant, así que la migración de datos de entrega N usa `pgsql_platform` (`datos.md` Parte E). `CA-CORE-280` la prueba acotada por tenant (método `normalize()`, que `up()` llama sin acotar).
9. **El `CHECK` de par ya existía** (`people_document_type_number_paired`, 0.8): §14.6.4.4 lo daba por añadir en la entrega N+1 y ya está corregido allí. Queda solo el `CHECK` del catálogo (entrega N+1), en el issue [#312](https://github.com/pirexia/plataforma-educativa/issues/312).

10. **Revisión independiente (2026-10-03)** (`db-reviewer`, `security-reviewer`, `doc-reviewer`; sin hallazgos Crítico/Alto). Corregido: **D1** la migración lee con `lockForUpdate()` dentro de su transacción; **S3** el `422` de `core.validation.document_type_invalid` ya **no refleja el valor recibido** (ni en el mensaje ni en `params`; el mensaje es el mismo para cualquier valor); **S5** el enlace del informe exige `https:` salvo en desarrollo (`import.meta.env.DEV`) y lleva `rel="noreferrer noopener"`. Documentado: **D2** la migración de datos es **irreversible a propósito** (`down()` vacío, `OPEN-CORE-52` = A): la reversión se apoya en la copia de seguridad / PITR (`operacion.md §14`); **D3** el `UPDATE` de la migración no pasa por la auditoría ni toca `updated_at`, excepción consciente a `INV-003` (`datos.md` Parte E). **No se tocan en 1.9c** S1 (el tope de 20 000 filas no se aplica) ni S2 (los roles no concedibles de una fila fallan en silencio), preexistentes de 1.9b y a decisión del usuario; **se corrigen después** en rama `fix/` propia (#313, #314, ver `CA-CORE-286`/`-287` abajo).

**Cobertura de los criterios de 1.9c**: Pest — `CA-CORE-238`, `-239` (`UserImportCatalogTest`), `-273` a `-280` y `-285` (`DocumentTypeCatalogTest`, `DocumentNumberValidatorTest`, `UserImportCatalogTest`); Vitest — `CA-CORE-233` a `-237` (`UserImportsView.spec.ts`, `UserImportDetailView.spec.ts`), `-262` y `-263` para la importación (`UsersAdmin.shell.spec.ts`), `-281` (`UserFormView.spec.ts`), `-282` (`documentTypes.spec.ts`), `-283` (`UserDetailView.spec.ts`), `-284` (`UserImportsView.spec.ts`); `CA-CORE-208` ampliado a las rutas de importación (`shell.spec.ts`). `CA-CORE-279` también comprueba que el `enum` de OpenAPI es el del enumerado PHP. Regresión de los issues #308, #309 y #310: los tests de `CA-CORE-273` a `-278` llevan sus referencias (F1 a F5).

### 14.24 Correcciones posteriores a 1.9c (2026-10-03)

- **#313 · `CA-CORE-286`** (`RNF-LIM-004`): `UserImportCsvReader` aplica `core.import_max_rows` (20 000 por defecto) a las filas de datos. Por encima, el lote queda `fallido` con una única incidencia `limite_filas_superado` (línea 1, columna `file`), `row_count` 0, sin validar ninguna fila ni generar informe. En el tope exacto el lote es válido. Pest: `UserImportCatalogTest`.
- **#314 · `CA-CORE-287`** (`RPERM-013`, `RN-CORE-08`): `UserImportRowValidator` comprueba, con el actor que subió el lote, que puede conceder cada rol de la fila (`CreateUser::canGrant`, misma regla que `assertActorCanGrant`). Si no puede, incidencia `rol_no_concedible` en la fase 1 (y también al revalidar en la fase 2), de modo que «se crearán N» coincide con lo que se crea. Pest: `UserImportCatalogTest`.

### 14.25 Notas de implementación de `1.9d` (2026-10-03)

Lo entregado por el sub-paso `1.9d` (auditoría y roles de solo lectura) y lo que no coincide al pie de la letra con lo escrito arriba. Nada de esto reabre la especificación aprobada.

**Entregado**

- **Servidor.** S10: `GET /audit-logs/facets` (`auditoria.leer`, `api.md §14.4`) y la parte de auditoría de S7: `GET /audit-logs?actor_type=`/`?module=` admiten varios valores por comas y `POST /audit-logs/exports` los admite como *array* (paridad, `ADR-054 §8.2`). El catálogo de valores filtrables vive en una única clase, `App\Modules\Core\Domain\AuditCatalog` (módulo → alias de `auditable_type`, los nueve valores de `event`, los seis de `actor_type`), que usan el filtro (`AuditLogFilter`), la validación (`IndexAuditLogsRequest`) y el *endpoint* de facetas, de modo que lo que la pantalla ofrece y lo que el servidor acepta no pueden divergir. No hay migración ni índice nuevo (no hace falta `db-reviewer`). Pest: `AuditLogFacetsTest` (catálogo, **sin ninguna consulta a `audit_logs`**, `403`/`401`, aislamiento entre tenants) y `AuditLogsEndpointsTest` (`CA-CORE-245`).
- **Cliente.** Rutas `core-audit` (`auditoria.leer`, acceso directo) y `core-roles` (`rol.leer`) en `shell.ts`; **no** se crea `core-role-detail` ni se llama a `GET /roles/{id}` (`OPEN-CORE-36` = A; test en `shell.spec.ts` y `RolesView.spec.ts`). Pantalla de auditoría (`AuditView.vue`, §14.7) con modo `cursor`, los seis filtros de `RN-CORE-76`, panel «Ver cambios» (`RN-CORE-77`) y exportación (`RN-CORE-78`); listado de roles (`RolesView.vue`, §14.8). Tipo de filtro nuevo **`entity`** en `src/data-table` (`DataTableEntityFilter.vue`; ampliación aditiva, amplía la lista cerrada de §13.7; `OPEN-CORE-33` = C). Acción «Ver su actividad» en la ficha de usuario (`auditoria.leer`). Traducciones `core.audit.*`, `core.roles.*`, `core.nav.{audit,roles}`, `core.users.detail.viewActivity*` y `dataTable.filters.entity*` en `es`, `en`, `de` y `fr`.

**Precisiones y desviaciones**

1. **Contrato del filtro `entity`** (§13.7, ampliado). `{ type: 'entity', id, labelKey, search(texto, {signal}) → [{value, label}], resolve(valor) → etiqueta|null }`. Selección única, serializada como `<id>=<ulid>`. La búsqueda sale con la espera de `SEARCH_DEBOUNCE_MS` y el texto recortado; la etiqueta de un valor que viene de la URL se resuelve **una sola vez** (si falla o devuelve `null`, el filtro sigue aplicado y se muestra «Elemento no disponible»). Un valor de la URL que no es un identificador de 26 caracteres se ignora sin llegar al servidor. El control es un campo de búsqueda con etiqueta y una lista de botones (patrón de divulgación, no un `combobox` completo) y, con valor, el texto «Filtrado por: {nombre}» con un botón para quitarlo; ese texto cumple el «indicador "Filtrado por: {nombre}"» de §14.7 sin duplicarlo.
2. **Etiqueta del usuario en el filtro.** «Nombre Apellido (correo)»: dos personas con el mismo nombre se distinguen por el correo, que el usuario ya ve en el listado de usuarios (`usuario.leer`). Es la misma cadena al elegir y al restaurar desde la URL.
3. **La tabla no se monta hasta que las facetas terminan** (con éxito o con error): el estado de la URL solo se interpreta con los filtros ya declarados (`parseUrlState`), y un `module=core` de la URL se perdería si el filtro apareciera después. Si `GET /audit-logs/facets` falla, la tabla funciona sin los filtros de módulo y entidad.
4. **`event` y `actor_type`** se ofrecen desde constantes del cliente (los vocabularios cerrados de `ADR-039`, como fija §14.7) a las que se **añade**, al final, cualquier valor nuevo que devuelvan las facetas (`ADR-038 §7.3`). Solo `module` y `auditable_type` salen íntegramente de las facetas (`OPEN-CORE-34` = B).
5. **Catálogo de nombres del cliente** (§14.7, columna «Entidad»): los diez alias de `core` (los nueve de `module=core` más `permission_role`) y dos módulos (`core`, `auth`); cualquier otro alias o código se muestra crudo. Las entidades de `auth` (`user_session`, `mfa_factor`…) aparecen en los registros pero **no** en el filtro de entidad (ver el hallazgo 1 de abajo).
6. **Zona horaria** (`OPEN-CORE-35` = A, `auditQuery.ts`): el día «hasta» se convierte al último instante del día local **con microsegundos** (`…:59.999999Z`), no a `.999`, para que un registro de la última milésima del día no quede fuera. Documentado en el manual.
7. **`POST /audit-logs/exports`** sigue aceptando un `actor_type` o un `module` **escalar** (la forma anterior a S7) y lo trata como una lista de uno (aditivo, `ADR-038 §7`); la SPA envía siempre *arrays*.
8. **Tope de 1.000 filas** (`CA-CORE-247`): el aviso de tope y su botón de exportar son del componente de 1.9; `AuditView.spec.ts` lo comprueba cargando 20 páginas de 50 (≈ 10 s: es la prueba más lenta del módulo).
9. **Playwright** (`CA-CORE-259`, parte de 1.9d, y `CA-CORE-246` con foco real): `e2e/core-audit.spec.ts` (auditoría y roles en tarjetas a 320 px y como tabla a 1024 px, sin desplazamiento horizontal; el panel atrapa el foco, `Esc` lo cierra y el foco vuelve al botón; los filtros tienen etiqueta accesible).

**Hallazgos fuera de alcance** (no se corrigen aquí; `CLAUDE.md §5`):

1. **`AuditCatalog::MODULE_ALIASES` solo declara `core`.** `GET /audit-logs?module=auth` no devuelve ninguna fila aunque `auth` tiene entidades auditables (`user_session`, `mfa_factor`, `identity_provider`…) y el catálogo del cliente ya traduce `auth`. Además `permission_role` (de `core`) no está en la lista del módulo `core`: `module=core` no devuelve sus registros. **Severidad: Baja, issue [#318](https://github.com/pirexia/plataforma-educativa/issues/318)** (el filtro por módulo es incompleto, no incorrecto, y el de entidad sí acepta cualquier alias). Corrección propuesta: que cada módulo declare sus alias de auditoría (como ya declara su *morph map*, `INV-007`) y `AuditCatalog` los agregue.
2. El test de `CA-CORE-150` (`shell.i18n.spec.ts`) tenía que admitir `core.nav.roles` como cognado idéntico en español e inglés («Roles»); se añade a su lista de excepciones.

**Cobertura de los criterios de 1.9d**: Pest — `CA-CORE-245` (`AuditLogsEndpointsTest`, `AuditLogFacetsTest`); Vitest — `CA-CORE-208` ampliado a `core-roles`/`core-audit` y `CA-CORE-209` a sus entradas (`shell.spec.ts`), `CA-CORE-240` y `-241` (`RolesView.spec.ts`), `CA-CORE-242` a `-247` y `-262` (`AuditView.spec.ts`), `CA-CORE-243`/`-247` (`auditQuery.spec.ts`), `CA-CORE-244` (`DataTable.entity.spec.ts`, `UserDetailView.spec.ts`), `CA-CORE-261` (`locales.spec.ts`, `i18n.spec.ts`); Playwright — `CA-CORE-259` y `-246` (`e2e/core-audit.spec.ts`).

### 14.26 Notas de implementación de `1.9e` (2026-10-03)

Lo entregado por el sub-paso `1.9e` (configuración del centro, activos de marca, módulos contratados en solo lectura y perfil propio) y lo que no coincide al pie de la letra con lo escrito arriba. Nada de esto reabre la especificación aprobada. **Sin cambios de servidor** (`apps/api` no se toca): solo `apps/web`, más esta documentación.

**Entregado**

- **Rutas** en `core/shell.ts`: `core-settings` (`/administracion/centro`, `configuracion.leer`), `core-branding-assets` (`/administracion/centro/marca`, `configuracion.leer`, sin entrada de menú, miga de pan bajo `core-settings`), `core-modules` (`/administracion/modulos`, `modulo.leer`) y `core-profile` (`/cuenta/perfil`, **`permissions: []`**, sección `cuenta`). La lista cerrada de `RN-CORE-24` pasa de seis a **siete** rutas (`CA-CORE-264`): la constante del test de coherencia (`src/navigation/modules.spec.ts`) contiene `core-profile`, con un caso fijo que prueba que una octava ruta con `[]` hace fallar la comprobación. Entradas de menú nuevas: `core.settings`, `core.modules` (ambas en `administracion`, sin acceso directo) y `core.profile` (en `cuenta`).
- **Pantallas**: `SettingsView.vue` (cuatro formularios independientes, `RN-CORE-79` a `-82`), `BrandingAssetsView.vue` (`RN-CORE-83`, confirmación de `RN-CORE-64`), `ModulesView.vue` (`RN-CORE-87`, tabla `core.modules` con el componente de 1.9, filtrada en cliente por `enabled: true`, `OPEN-CORE-45` = A) y `ProfileView.vue` (`RN-CORE-88`/`-89`). Componente de apoyo `SettingsField.vue` (etiqueta, marca de obligatorio no solo visual, pista y mensajes del servidor con `aria-invalid`/`aria-describedby`).
- **Catálogo de comunidades autónomas** (`autonomousCommunities.ts`, `RN-CORE-82`): los 19 códigos de `App\Modules\Core\Domain\AutonomousCommunity::CODES` en el mismo orden, con su nombre en `core.settings.autonomousCommunity.<código>` en los cuatro idiomas. `autonomousCommunities.spec.ts` lee el fichero PHP (`CA-CORE-252`).
- **Estado de sesión**: `useSession` gana `updateSessionProfile(person)` (junto a `updateSessionLocale`), que hace `PATCH /me` y sustituye el usuario de sesión por la respuesta, sin segunda petición (`RN-CORE-89`, §12.3.4).
- **Traducciones** `core.settings.*`, `core.branding.*`, `core.modules.*`, `core.profile.*` y `core.nav.{settings,modules,profile}` en `es`, `en`, `de` y `fr`.

**`OPEN-CORE-37` = B: qué edita ya `/administracion/mfa` (comprobado en el código real de `apps/web`)**

La pantalla `mfa-administration` (`auth/views/AdminMfaView.vue` y sus cuatro áreas) **no edita ninguna clave de `security.*`** de la configuración del centro: ni `session_timeout_minutes`, ni `mfa_allowed_methods`, ni `mfa_grace_period_days` aparecen en ningún fichero de producción de `apps/web` fuera de un comentario de `MfaExemptionsArea.vue`. Lo que edita es otra cosa: `mfa_required` **de cada rol** (`PATCH /roles/{id}`), las excepciones temporales (`/mfa-exemptions`), los restablecimientos del segundo factor y la consulta de cumplimiento. Por tanto el grupo **Seguridad** de `/administracion/centro` incluye **las tres claves**, sin duplicar ningún campo, y enlaza a `/administracion/mfa` (solo si el usuario tiene alguno de los permisos de esa ruta, `RN-CORE-62`) para el resto.

**Precisiones y desviaciones**

1. **Grupo Seguridad** (concreción de `OPEN-CORE-37`): `session_timeout_minutes` (entero, 5-480), `mfa_grace_period_days` (entero, 1-90) y `mfa_allowed_methods`. De los métodos se ofrecen **`totp` (siempre marcado y deshabilitado: el servidor exige que esté, `RN-AUTH-69`) y `email`**; `sms` **no se ofrece** porque el servidor lo rechaza mientras no haya proveedor. El `PATCH` envía `mfa_allowed_methods` completo (`["totp"]` o `["totp","email"]`) solo si cambia.
2. **`CA-CORE-267`, «sin paginador»**: el componente de 1.9 pinta siempre su pie de paginación en modo `page` cuando hay filas (misma técnica de única página que `RN-CORE-74`); `src/data-table` no se modifica en este sub-paso. Con una sola página el pie muestra el total, **«Página 1 de 1»** y los cuatro botones de navegación **deshabilitados**; el test lo comprueba así. **Resuelto por decisión del usuario (2026-10-03, opción C):** `CA-CORE-267` se reescribe a lo que hace el componente; ocultar el pie queda como cambio aditivo posterior en la issue #326 (Baja), tras lo cual se restaurará la redacción «sin paginador». **Actualización 2026-10-04 (issue #326):** el componente gana `hideSinglePageFooter` (opt-in, `ModulesView` lo activa; las demás tablas conservan el pie y su selector de filas por página) y `CA-CORE-267` recupera la redacción «sin paginador»; cubierto en `DataTable.singlePage.spec.ts` y `ModulesView.spec.ts`.
3. **Paleta** (`RN-CORE-80`): los colores se escriben como texto `#RRGGBB` (sin selector nativo de color); la vista previa pinta el par primario/secundario como fondo/texto y la razón se **trunca** (no se redondea) a dos decimales con el formato del idioma activo (`3,1:1` en `es`, `3.1:1` en `en`), para que 4,497 no se lea como 4,5. Vaciar un color envía `null`. El `422 contrast_insufficient` llega en `errors.branding[]` (no en `branding.color_*`) con `params.ratio` y `params.required`: la vista pinta el mensaje del servidor y esos dos valores en el resumen del grupo.
4. **Guardado por grupo** (`RN-CORE-79`): tras guardar un grupo se repone con la respuesta, pero lo que el usuario haya escrito y no guardado en los otros tres se conserva. El botón de cada grupo está deshabilitado sin cambios; en Regional, además, mientras no haya idioma por defecto entre los activos o no haya ninguno activo (`CA-CORE-251`).
5. **Zona horaria** (`RN-CORE-82`): un campo de búsqueda y un `<select>` nativo con `Intl.supportedValuesOf('timeZone')` filtrado; el valor guardado se conserva siempre como opción.
6. **Activos** (`RN-CORE-83`): el `<input type="file">` va oculto y los botones «Sustituir …»/«Eliminar …» son el control accesible (texto distinto por bloque, WCAG 2.4.6). La comprobación de cliente compara el tamaño y, si el navegador informa de tipo, el tipo; si el navegador no lo informa (p. ej. `.ico` en algunos sistemas), decide el servidor. Un `413`/`415`/`422` sin cuerpo JSON cae a un texto traducido. La recarga por URL caducada es **una por visita a la pantalla** (se rearma tras sustituir o eliminar); los errores de carga que lleguen mientras esa petición está en vuelo se ignoran por ser de las URL viejas.
7. **Perfil** (`RN-CORE-88`): el campo de teléfono tiene `maxlength` 32 (límite de `PATCH /me`); el de correo es `type="email"` con `novalidate` en el formulario (decide el servidor). Sin cambios, el botón está deshabilitado.
8. **`CA-CORE-100`** (`src/navigation/registry.spec.ts`): «Inicio y las tres de Mi cuenta» pasa a «Inicio y las **cuatro** de Mi cuenta» porque `core.profile` aparece para todo usuario autenticado (`CA-CORE-265`). Es consecuencia directa de la especificación.
9. **Tests con lectura cruzada** (`autonomousCommunities.spec.ts`, como `documentTypes.spec.ts`): se omiten (quedan `skipped` a la vista) en el contenedor `web`, que solo monta `apps/web`; en CI y en un contenedor con `apps/` completo se ejecutan.

**Hallazgos fuera de alcance**

1. **`api.md §2` decía `required_ratio`** y el servidor devuelve `params.required` (`TenantSettingsController::brandingUpdates()`; `funcional.md §14.9` ya decía `required`). Contradicción documentación/código (severidad **Media**, `CLAUDE.md §6.6`); corregido en `api.md` en este sub-paso. Issue [#321](https://github.com/pirexia/plataforma-educativa/issues/321).
2. **Claves huérfanas `core.module.{enabled,disabled}`** (paso 1.1) sin ningún consumidor en el código tras añadir `core.modules.status.*`. Severidad **Baja**, issue [#322](https://github.com/pirexia/plataforma-educativa/issues/322), sin corregir.

**Cobertura de los criterios de 1.9e**: Vitest — `CA-CORE-208`/`-264` (`shell.spec.ts`, `navigation/modules.spec.ts`), `CA-CORE-209`/`-265`/`-268` (entradas por permiso, `shell.spec.ts`), `CA-CORE-248` a `-251` (`SettingsView.spec.ts`), `CA-CORE-252` (`autonomousCommunities.spec.ts`), `CA-CORE-248`/`-253`/`-254` (`BrandingAssetsView.spec.ts`), `CA-CORE-267`/`-268` (`ModulesView.spec.ts`), `CA-CORE-265`/`-266` (`ProfileView.spec.ts`), `CA-CORE-261` (`locales.spec.ts`); Playwright — `CA-CORE-260` (configuración del centro: orden de teclado, etiquetas, obligatorios, 44 px) y `CA-CORE-265` con el *shell* real (`e2e/core-settings.spec.ts`). `CA-CORE-262` (almacenamiento) se comprueba para la configuración (`SettingsView.spec.ts`).

### 14.27 Correcciones posteriores a 1.9f (2026-10-05, rama `fix/REQ-CORE-ajustes-security-1-9e-1-9b`)

Tres correcciones de severidad Baja detectadas en revisiones de 1.9b/1.9e. Sin migración, sin permisos nuevos, sin cambios de contrato de API.

- **`CA-CORE-296`** [`INV-002`, issue #280] · **Dado** una exportación de usuarios (`GenerateUserExport`) cuyo solicitante ya no se resuelve cuando se ejecuta el trabajo (p. ej. borrado lógico entre la solicitud y la ejecución), **entonces** la exportación queda en estado `fallida` con `error_code = "core.export.generation_failed"`, sin `object_key` y **sin fichero** en el almacenamiento; no se exporta el tenant entero. Denegar por defecto: sin solicitante no hay ámbito que aplicar.
- **`CA-CORE-297`** [`INV-002`, issue #280] · Lo mismo para la exportación de auditoría (`GenerateAuditLogExport`).
- **Issues #323 y #324 (`SettingsView`, grupo Seguridad)**: ver la actualización de `§14.9`. Cubiertos por los tests «issue #324» e «issue #323» de `SettingsView.spec.ts` (sin `CA-CORE` propio: corrigen el comportamiento de `RN-CORE-79`/`CA-CORE-249`).

**Cobertura real**: `CA-CORE-296` y `-297` en `apps/api/tests/Feature/Core/UserExportEndpointsTest.php` (un solo test que recorre ambos trabajos y comprueba estado, código de error, ausencia de `object_key` y de fichero). Pest `Core` 209/209 y Vitest 1043/1043 verificados al cierre de la corrección.

**Hallazgos de la revisión de seguridad (Baja), corregidos el 2026-10-05 (rama `fix/REQ-CORE-339-340-62-pendientes`)**: [#339](https://github.com/pirexia/plataforma-educativa/issues/339) y [#340](https://github.com/pirexia/plataforma-educativa/issues/340).

- **`CA-CORE-298`** [`INV-002`, issue #340] · **Dado** una exportación de usuarios o de auditoría cuyo solicitante existe pero ya no tiene `usuario.exportar`/`auditoria.exportar` cuando se ejecuta el trabajo, **entonces** queda `fallida` con `core.export.generation_failed`, sin `object_key` y sin fichero (antes, `completada` con 0 filas).
- **`CA-CORE-299`** [`INV-002`, issue #339] · **Dado** un lote de importación cuyo autor ya no se resuelve cuando se ejecuta `ValidateUserImport`, **entonces** el lote queda `fallido` con `validated_at` y no se valida ninguna fila (antes se validaba sin la comprobación `RPERM-013`). Mismo criterio que `ExecuteUserImport`.
- **Cobertura real**: `CA-CORE-298` en `UserExportEndpointsTest.php` y `CA-CORE-299` en `UserImportCatalogTest.php`; ambos fallan sin la corrección. Pest `Core` 211/211, Pint y Larastan limpios.
