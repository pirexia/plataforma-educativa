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

## 2. Contradicción detectada: quién activa y desactiva módulos

**Hay una contradicción real entre dos requisitos y no la resuelvo yo.**

| Requisito | Dice |
|-----------|------|
| `REQ-CORE-002` | «El Administrador de Centro puede […] **activar/desactivar módulos contratados**.» |
| `RMOD-002` | «El **Super Admin** puede activar/desactivar módulos por tenant desde el panel de administración.» |

No es un matiz de redacción: `module_subscriptions` tiene **un solo booleano** `enabled` (`ADR-034 §5`). Un único interruptor no puede representar a la vez «la plataforma ha contratado este módulo al centro» y «el centro lo tiene encendido». Con el esquema actual, o manda uno o manda el otro, y el que pierda puede pisar la decisión del que gana.

La lectura que reconcilia los dos requisitos exigiría **dos estados** (contratado por la plataforma × habilitado por el centro), lo que significa una columna nueva y una regla de precedencia — es decir, una decisión de modelo de datos con impacto en `RMOD-003`, `RMOD-004`, `RMOD-006`, `RMOD-009` y en la facturación de `REQ-SAAS-001`. Eso es un ADR, no una decisión de esta especificación (`CLAUDE.md §11`).

**Efecto sobre el alcance de 1.1, para no construir sobre una contradicción sin resolver:**

- 1.1 expone `module_subscriptions` en **solo lectura** (`GET /modules`), que es lo que necesitan `RMOD-008` (ocultar módulos desactivados en la interfaz) y el *middleware* de `RMOD-009`.
- 1.1 permite editar únicamente `module_subscriptions.settings` (configuración del módulo para ese centro), que ningún requisito disputa.
- **1.1 no permite cambiar `enabled` a nadie.** Ni al Administrador de Centro ni por API. Hasta 1.6, el interruptor se mueve por consola con el rol propietario.

Registrado como `OPEN-CORE-03`, con severidad de bloqueo para 1.6.

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

`php artisan tenant:provision-defaults {slug} --admin-email= --admin-given-name= --admin-family-name=`

1. Comprueba que el tenant existe y no tiene ya configuración.
2. Crea la fila `tenant_settings` con los valores por defecto (`es-ES`, idiomas activos `['es-ES']`, `Europe/Madrid`, `EUR`).
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

Interfaces públicas que `REQ-CORE` expone en su `Domain` para que otros módulos las consuman sin acoplarse:

- `TenantSettingsReader` — idioma por defecto, idiomas activos, zona horaria y moneda del centro. Lo necesitarán todos los módulos que generen documentos o importes.
- `UserDirectory` — resolución de un usuario por `public_id` y consulta de su idioma preferido, para las comunicaciones de `REQ-COM`.
- `BulkUserImporter` — destino de importación de personal para `REQ-ONB-002` (§1.10).
- `AuditQuery` — consulta filtrada del registro, para que ningún módulo consulte `audit_logs` directamente.
- `ExportRequestService` — solicitud de una exportación asíncrona (§4.6), reutilizable por `REQ-PRIV` y demás.

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
- **`CA-CORE-061`** · *Dado* un Administrador de Centro, *cuando* intenta cambiar `enabled` de una suscripción por API, *entonces* la operación no existe (`404`/`405`) — está fuera de alcance hasta que se resuelva `OPEN-CORE-03` (§2).
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

### `OPEN-CORE-03` · Quién activa y desactiva módulos: contradicción `REQ-CORE-002` vs `RMOD-002` — **RESUELTO (diferido)**

Detallada en §2. Requiere **ADR** (dos estados: contratado × habilitado, con regla de precedencia) porque toca el modelo de datos y afecta a `RMOD-003`, `RMOD-004`, `RMOD-006`, `RMOD-009` y `REQ-SAAS-001`. **No bloquea 1.1** porque el alcance se ha acotado a solo lectura. Registrado como issue [#44](https://github.com/pirexia/plataforma-educativa/issues/44) (severidad Media); el ADR se escribe al arrancar **1.6**.

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
