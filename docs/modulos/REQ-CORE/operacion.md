# REQ-CORE · Operación

> Paso **1.1**. Complementa `SYSADMIN.md`; lo que aquí se describe es específico de este módulo.

---

## 1. Comportamiento con el módulo activo o inactivo

**`REQ-CORE` no es desactivable.** Se registra en el catálogo `modules` con `code = 'core'` y el *middleware* lo trata como permanentemente habilitado: sin usuarios, roles, configuración ni auditoría no hay plataforma que desactivar.

Lo que 1.1 entrega para los **demás** módulos es el *middleware* que `ADR-034 §5` dejó especificado y sin escribir:

| Aspecto | Comportamiento |
|---------|----------------|
| Origen de datos | `module_subscriptions` del tenant, con caché de prefijo de tenant (`ADR-033 §9`) |
| Fallo en cerrado | **Ausencia de fila = módulo desactivado.** Nunca se interpreta como «sin restricción» |
| Respuesta | `403` `application/problem+json` con `type` propio y mensaje traducido (`RMOD-009`, `INV-009`) |
| Interfaz | `GET /modules` es la fuente para ocultar lo desactivado sin dejar enlaces muertos (`RMOD-008`) |
| Invalidación de caché | En la escritura de la suscripción, **además** del TTL corto. No solo por vencimiento |

La invalidación en escritura no es un detalle: el [issue #7](https://github.com/pirexia/plataforma-educativa/issues/7) es exactamente este fallo aplicado a la resolución de tenant (suspender un tenant no invalidaba su caché). Se resuelve aquí de la misma forma, no se reinventa.

---

## 2. Variables de entorno

| Variable | Uso | Valor en desarrollo |
|----------|-----|---------------------|
| `TENANCY_BASE_DOMAIN` | Resolución de tenant por subdominio y construcción del enlace de invitación | `plataforma.test` |
| `APP_URL` | Base de las URLs generadas | `http://localhost` |
| `MAIL_MAILER` | Envío del correo de invitación | `log` (ver §4) |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Remitente transaccional | Ficticios (`@example.com`) |
| `FILESYSTEM_DISK` | Disco de los activos de marca, ficheros de importación y exportaciones | `s3` (MinIO en desarrollo) |
| `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_USE_PATH_STYLE_ENDPOINT` | Almacenamiento compatible S3 | MinIO local (perfil `full` de `compose.yaml`) |
| `QUEUE_CONNECTION` | Colas | `database` (hoy, sin *worker* desplegado, issue #128; `redis` + Horizon es lo elegido, no instalado) |
| `CORE_INVITATION_TTL_DAYS` | Caducidad de la invitación (`RN-CORE-10`) | `7` |
| `CORE_IMPORT_MAX_ROWS` | Límite de filas por importación | `20000` |
| `CORE_IMPORT_RETENTION_DAYS` | Purga de ficheros de importación e informes (`RN-CORE-21`) | `30` |
| `CORE_EXPORT_MAX_ROWS` | Límite de filas por exportación de auditoría | `500000` |
| `CORE_EXPORT_RETENTION_DAYS` | Caducidad del artefacto de exportación | `7` |
| `CORE_SIGNED_URL_TTL_MINUTES` | Caducidad de las URLs firmadas | `15` |

Ninguna es un secreto salvo las credenciales de S3 y de correo, que van por gestor de secretos / `EnvironmentFile=` (`ADR-037`, `CLAUDE.md §8`). **No hay valores por defecto permisivos**: sin `TENANCY_BASE_DOMAIN` ningún host resuelve tenant y la API devuelve `404` (comportamiento ya existente desde 0.7).

---

## 3. Servicios externos y degradación

| Servicio | Uso | Si no responde |
|----------|-----|----------------|
| **PostgreSQL** | Todo | La API no sirve. Sin degradación posible ni deseable |
| **Redis** | Colas (Horizon) y caché | La caché degrada a consulta directa (más lenta, correcta). Las **colas no degradan**: sin Redis no se envían invitaciones, no se validan ni ejecutan importaciones y no se generan exportaciones. Los trabajos quedan sin encolar y la petición debe fallar con `503`, nunca aceptar en silencio algo que no va a ocurrir |
| **S3 / MinIO** | Activos de marca, ficheros de importación, artefactos de exportación | Subida y descarga fallan con `503`. El resto del módulo (usuarios, roles, configuración no gráfica, auditoría) sigue funcionando. La configuración se devuelve con las URLs de branding a `null`, no con un error |
| **Correo transaccional** | Invitación | Depende de `0.10c`, **sin decidir** (`OPEN-CORE-04`). El trabajo reintenta; agotados los reintentos, la invitación queda emitida y visible en `GET /invitations`, y el administrador puede reenviarla. **La invitación no se invalida por un fallo de entrega** |

Sin *circuit breaker* en 1.1: no hay ninguna integración con un tercero de latencia impredecible. Cuando llegue `REQ-COM` (1.19) con proveedores de SMS y push, será su decisión.

---

## 4. Colas y trabajos (`INV-012`)

Ninguna de estas operaciones ocurre en el ciclo de petición HTTP.

| Cola | Trabajo | Disparo | Reintentos |
|------|---------|---------|------------|
| `core-mail` | `SendInvitationEmail` | Emisión o reenvío de invitación | 5, con retroceso exponencial (1 min → 30 min) |
| `core-imports` | `ValidateUserImport` | `POST /user-imports` | 1 (un fallo de validación es determinista; reintentar no ayuda) |
| `core-imports` | `ExecuteUserImport` | `POST /user-imports/{id}/execute` | 3, con reanudación desde la última fila confirmada |
| `core-exports` | `GenerateAuditLogExport` | `POST /audit-logs/exports` | 3 |
| `core-maintenance` | `PurgeExpiredInvitations` | Programado, diario | — |
| `core-maintenance` | `PurgeImportArtifacts` | Programado, diario | — |
| `core-maintenance` | `PurgeExpiredExports` | Programado, diario | — |
| `core-maintenance` | `PurgeOrphanBrandingAssets` | Programado, diario | — |

Reglas transversales de los trabajos:

- **Todo trabajo lleva su `tenant_id` explícito y establece el contexto de tenant al arrancar.** Un trabajo sin contexto de tenant no ve nada (RLS falla en cerrado, `ADR-033 §3`), que es el comportamiento correcto pero produce un fallo confuso. El contexto se fija, no se hereda.
- **Todo trabajo registra su actividad con `actor_type` adecuado**: `import` para los de importación, `system` para los de mantenimiento, `user` cuando el actor original es identificable (invitación).
- **Los trabajos programados de purga se ejecutan por tenant**, no en una pasada global sin contexto.
- **`SendInvitationEmail` implementa `ShouldBeEncrypted`** (issue [#75](https://github.com/pirexia/plataforma-educativa/issues/75), mismo hallazgo que [#73](https://github.com/pirexia/plataforma-educativa/issues/73) de `REQ-AUTH`): el token de activación en claro que lleva en su *payload* viaja y se almacena cifrado con `APP_KEY`, también si el trabajo agota sus 5 reintentos y cae en `failed_jobs`. `bo:purge-failed-jobs` (`REQ-BO`, `routes/console.php`) es la segunda capa, y cubre esta cola también — no es específica de `auth-mail`. Sustituye desde `1.6d` (2026-09-21) al comando del framework `queue:prune-failed`, que llevaba desde `0.7` sin poder borrar nada por apuntar a la conexión sin privilegio de `DELETE` (`docs/modulos/REQ-BO/operacion.md §6.2`).
- El *scheduler* corre en su propio contenedor, no en el de la API (`ADR-037`).

### Trabajos de purga: qué borran

| Trabajo | Qué borra | Base |
|---------|-----------|------|
| `PurgeExpiredInvitations` | Marca `deleted_at` en invitaciones caducadas hace más de 30 días. **No borra el hash antes de caducar**: la traza de que se invitó a alguien es relevante | Minimización |
| `PurgeImportArtifacts` | Objeto CSV fuente e informe de errores con más de `CORE_IMPORT_RETENTION_DAYS`. La fila `user_imports` **se conserva** (es el registro de que hubo una importación) con las columnas de clave de objeto a `null` | `RN-CORE-21`: el CSV contiene datos personales de todo el personal |
| `PurgeExpiredExports` | Artefacto de exportación vencido y su fila | `RN-CORE-21` análogo |
| `PurgeOrphanBrandingAssets` | Objetos de branding ya no referenciados por `tenant_settings` con más de 24 h | Evita crecimiento indefinido tras cada cambio de logo |

**Ninguno de estos trabajos toca `audit_logs`.** La purga por retención del registro de auditoría es `REQ-PRIV-006`, se ejecuta con el rol propietario (`REVOKE UPDATE, DELETE` impide lo contrario desde la aplicación) y no existe todavía (`OPEN-CORE-11`).

---

## 5. Almacenamiento de ficheros

| Contenido | Clave | Visibilidad |
|-----------|-------|-------------|
| Activos de marca | `tenants/{tenant_public_id}/branding/{kind}/{ulid}.{ext}` | Bucket **privado**. Entrega solo por URL firmada de caducidad corta, incluida la del endpoint público de branding |
| Fichero fuente de importación | `tenants/{tenant_public_id}/imports/{import_public_id}/source.csv` | Privado |
| Informe de errores | `tenants/{tenant_public_id}/imports/{import_public_id}/report.csv` | Privado |
| Exportaciones | `tenants/{tenant_public_id}/exports/{export_public_id}.csv` | Privado |

Reglas:

- **Nada dentro de la raíz web** (`CLAUDE.md §8`, `RSEC-OWASP-012`). La aplicación es *stateless* y no escribe en disco local (`CLAUDE.md §9`).
- **El prefijo lleva el `public_id` del tenant**, no su `id`. Una clave de objeto es tan pública como una URL.
- **Validación de tipo real por contenido** antes de escribir, nunca por extensión ni por `Content-Type` declarado.
- **SVG saneado** (eliminación de `<script>`, manejadores `on*`, `<foreignObject>` y referencias externas) antes de almacenar. Un SVG servido desde el dominio del centro y sin sanear es XSS con el origen del propio centro.
- **Sin análisis antivirus** en 1.1 (`RSEC-OWASP-012` lo exige y no hay servicio): `OPEN-CORE-10`.

---

## 6. Caché

| Clave | Contenido | TTL | Invalidación |
|-------|-----------|-----|--------------|
| `tenant:{id}:settings` | Configuración del centro | 10 min | En `PATCH /tenant/settings` y en subida/borrado de activos |
| `tenant:{id}:modules` | Suscripciones y su estado | 5 min | En cualquier escritura de `module_subscriptions`, incluida la de consola |
| `tenant:{id}:branding` | Respuesta del endpoint público | 5 min | Igual que `settings` |

Todas con **prefijo de tenant** (`ADR-033 §9`). Una clave de caché sin prefijo de tenant es una fuga entre tenants tan real como una consulta sin `WHERE`, y no la detecta la RLS.

**Las URLs firmadas no se cachean** dentro del valor cacheado: se generan en cada respuesta. Cachear una URL firmada con TTL mayor que su vencimiento produce enlaces rotos.

---

## 7. Métricas y alertas

| Métrica | Alerta |
|---------|--------|
| Correos de invitación fallidos tras agotar reintentos | > 5 en 1 h ⇒ aviso. Suele indicar problema del proveedor, no del centro |
| Profundidad de `core-exports` y `core-imports` | Cola creciente sostenida ⇒ falta capacidad de *worker* |
| Duración de `ExecuteUserImport` | p95 > 10 min ⇒ revisar tamaño de lote |
| Tasa de `403` en endpoints de `REQ-CORE` | Pico ⇒ o falta un permiso en el rol, o hay sondeo |
| Tasa de `404` en `GET /tenant/branding` | Pico ⇒ enumeración de subdominios |
| Peticiones a `GET /audit-logs` por actor | Volumen anómalo ⇒ posible extracción del registro. `RSEC-OWASP-009` |
| Latencia de `GET /users` p95 | Regresión ⇒ índice o consulta N+1 |
| Objetos huérfanos de branding | Crecimiento sostenido ⇒ la purga no corre |

El sobrecoste de RLS medido en 0.8.12 (media ~1,24 %) es la línea base: una regresión clara en los listados de este módulo se investiga contra ese número, no contra una impresión.

---

## 8. Problemas conocidos y diagnóstico

| Síntoma | Causa probable |
|---------|----------------|
| Un trabajo de cola no ve ningún dato | No se estableció el contexto de tenant al arrancar el trabajo. RLS devuelve cero filas, no un error (`ADR-033 §3`) |
| La configuración cambiada no se refleja | Caché no invalidada en la escritura. Es el patrón del [issue #7](https://github.com/pirexia/plataforma-educativa/issues/7) |
| El logo desaparece tras cambiarlo | El activo anterior se purgó antes de confirmar el nuevo. La purga es diferida (24 h) precisamente para esto |
| Enlace de invitación devuelve `404` | El host del enlace no resuelve tenant: `TENANCY_BASE_DOMAIN` mal configurado, o DNS con comodín ausente (`OPEN-08`, paso 0.10b) |
| Enlace de invitación «no hace nada» | **Esperado en 1.1**: el canje lo implementa 1.2 (`funcional.md` §1.4, `OPEN-CORE-01`) |
| Importación queda en `subido` para siempre | *Worker* de `core-imports` caído, o Redis no disponible |
| `403` en un endpoint de otro módulo recién desplegado | `platform:sync-registry` no ejecutado tras el despliegue: el permiso no existe y se deniega por defecto. Es el comportamiento correcto y está documentado como paso obligatorio de entrega (`ADR-034`, consecuencias) |
| Un usuario no puede iniciar sesión | **Esperado en 1.1**: no hay login hasta 1.2 |

---

## 9. Impacto en copias de seguridad y restauración

`REQ-BKP` es el paso 1.26 y `0.10d` (destino de copias) sigue pendiente. Lo que 1.1 aporta al alcance de esa copia:

- **Base de datos**: tres tablas nuevas (`tenant_settings`, `user_invitations`, `user_imports`, `data_exports`) que entran en la copia general sin nada especial. `audit_logs` ya estaba.
- **Objetos en S3**: 1.1 es el **primer paso que escribe ficheros**. La copia debe cubrir el bucket, no solo la base de datos, o una restauración devolvería un centro con su configuración intacta y sin logo.
- **Coherencia entre ambos**: una restauración de base de datos a un punto anterior deja `tenant_settings` apuntando a claves de objeto que quizá ya purgó `PurgeOrphanBrandingAssets`. Es un caso a contemplar en el procedimiento de 1.26: restaurar objetos y base de datos al mismo punto, o aceptar la pérdida del activo y que la interfaz degrade a «sin logo» en vez de romper.
- **Lo que no hace falta copiar**: los artefactos de exportación (`data_exports`) son regenerables y caducan a los 7 días. Excluirlos del respaldo es correcto y ahorra volumen.

---

## 10. Despliegue

Orden obligatorio, coherente con expand/contract (`CLAUDE.md §9`):

1. Migraciones de las tablas nuevas (todas aditivas: no se altera ninguna tabla de 0.8, ver `datos.md`).
2. Despliegue de la aplicación.
3. **`php artisan platform:sync-registry`** — materializa los permisos de §2 de `permisos.md` y la entrada `core` del catálogo de módulos. Sin este paso, todo endpoint del módulo deniega por defecto.
4. `php artisan tenant:provision-defaults {slug}` **solo en el alta de un centro nuevo**, no en cada despliegue. Es idempotente, pero no forma parte de la entrega.

Reversión: las migraciones de 1.1 son aditivas y su `down()` elimina tablas que ninguna otra referencia. Revertir el código a la versión anterior deja las tablas creadas y sin uso, que es inocuo. **Los objetos ya escritos en S3 no se revierten** y hay que borrarlos a mano si se abandona la entrega.

---

## 11. Paso 1.8 (`REQ-CORE-008`): *layout*, navegación y panel

> Estado: **APROBADO** (2026-09-23), con `funcional.md §12`.

### 11.1 Qué se despliega

**Solo la imagen de `apps/web`.** Ningún cambio en `apps/api`, ninguna migración, ningún `platform:sync-registry` extraordinario, ningún comando de consola nuevo.

| Aspecto | Paso 1.8 |
|---------|----------|
| Variables de entorno | **Ninguna nueva.** Se sigue usando `VITE_API_URL` (0.5) |
| Colas y trabajos | **Ninguno.** Nada del paso es pesado (`INV-012` no aplica) |
| Tareas programadas | **Ninguna** |
| Caché de servidor | **Ninguna nueva.** `GET /me` no se cachea (motor de permisos sin caché compartida, `ADR-044 §4.7`); `tenant:{id}:branding` (§6) sin cambios |
| Almacenamiento del navegador | **Ninguna clave nueva** (`funcional.md` `CA-CORE-151`). Siguen `plataforma.brand`, `plataforma.color-mode` (1.7) y `plataforma.locale` (0.9) |
| Dependencias | **Ninguna nueva.** `sheet` y `dropdown-menu` se vendorizaron **a mano** (la CLI de shadcn-vue falla en este entorno con `EALLOWSCRIPTS`; descarga directa del JSON del registro sobre Reka UI, ya instalada — `docs/design-system.md §12.3`); los iconos, de `@lucide/vue`, ya instalado |

### 11.2 Carga sobre la API

Por carga completa de la SPA con sesión: una petición anónima a `GET /tenant/branding` (ya existente desde 1.7) y **una** a `GET /me`. Antes de 1.8, cada pantalla con sesión pedía su propio `GET /me` al montarse; ahora lo pide el *guard* una vez. La carga baja o se mantiene.

`GET /me` recalcula los permisos efectivos sobre todo el catálogo en cada llamada (`PermissionResolver::decideAll`, 1.5). Con la recarga acotada de `funcional.md §12.3.4` (arranque, *login*, `PATCH /me`, `403` genérico deduplicado) no hay llamadas por navegación. Si la medición con volumen (`REQ-SEED`, 1.15b) mostrara latencia, el remedio es de servidor, no una caché en cliente.

### 11.3 Seguridad operativa

- **CSP**: nada nuevo inyecta `<style>` ni `<script>`; las transiciones usan clases y variables CSS existentes (`docs/design-system.md §16`). Compatible con `style-src`/`script-src` sin `'unsafe-inline'`.
- **Redirección abierta**: `RN-CORE-28` (`CA-CORE-091`).
- **Datos de sesión**: solo en memoria (`RN-AUTH-28`).

### 11.4 Métricas y alertas

Ninguna nueva en servidor. Lo que sí conviene mirar tras desplegar, con las métricas que ya existen (§7): la **tasa de `403` de `REQ-CORE`/`REQ-AUTH` debería bajar**, no subir, porque el panel ya no pide nada que el usuario no pueda ver (`RN-CORE-33`). Un aumento tras desplegar 1.8 indica una entrada de navegación con permisos mal declarados.

### 11.5 Despliegue y reversión

1. Construir y publicar la imagen de `apps/web` (`ADR-037`).
2. Desplegar. No hay orden relativo con `apps/api`: el paso solo consume *endpoints* que existen desde 1.1-1.5.

**Reversión**: volver a la imagen anterior de `apps/web`. Sin datos que migrar ni deshacer. Efecto visible para el usuario: desaparecen *shell*, panel, selector de idioma y control de modo; las pantallas de `/cuenta/*` y `/administracion/*` vuelven a su forma autónoma de 1.2-1.4c. La preferencia de idioma guardada por el selector en `people.locale` **se conserva** (es un dato de 1.1) y la sigue respetando el servidor en correos y documentos.

**Caché del navegador**: los *assets* de Vite llevan *hash* de contenido; `index.html` debe servirse sin caché de larga duración (**no verificado** al escribir esta especificación: el implementador lo comprueba en la configuración del servidor de la imagen de `apps/web` y, si no es así, lo reporta como issue en vez de darlo por hecho), para que una reversión llegue a los usuarios en su siguiente carga.

### 11.6 Problemas conocidos y diagnóstico

| Síntoma | Causa probable |
|---------|----------------|
| Un usuario no ve una entrada que «debería» ver | Falta el permiso en su rol, el módulo no está contratado (permiso inerte) o el permiso no se sincronizó (`platform:sync-registry`). Se diagnostica con `GET /users/{id}/effective-permissions` (1.5), que da la procedencia; **no** es un fallo de la SPA |
| El menú muestra una entrada que ya no debería | Estado de sesión anterior a un cambio de roles; se corrige en el siguiente `403` o recarga (`funcional.md §12.9`). Esperado |
| Pantalla «centro no encontrado» | El *host* no resuelve tenant: `TENANCY_BASE_DOMAIN` o DNS (§8, `OPEN-08`) |
| Bucle entre `/entrar` y la ruta pedida | `GET /me` responde `401` justo después de un *login* correcto: cookie de sesión no aceptada por el navegador (dominio, `SameSite`, orígenes distintos en desarrollo — issue [#71](https://github.com/pirexia/plataforma-educativa/issues/71)) |
| En desarrollo, idioma o sesión «no se guardan» | SPA servida desde `localhost` en vez de `demo.plataforma.test` (issue #71) |

---

## 12. Paso 1.9 (tablas de datos)

> Estado: **APROBADO** (2026-09-30, decisión del usuario), **ajustado a `ADR-054`, ratificado entero por el usuario el 2026-09-30 (ACEPTADA)**, con `funcional.md §13`. Listo para `implementer`.

### 12.1 Qué se despliega

**Solo la imagen de `apps/web`.**

| Aspecto | Paso 1.9 |
|---------|----------|
| Variables de entorno | **Ninguna nueva** |
| Colas y trabajos | **Ninguno nuevo.** El componente consume exportaciones que ya se generan en `core-exports` (§4) |
| Tareas programadas | **Ninguna** |
| Almacenamiento del navegador | **Una clave nueva por tabla**: `plataforma.table.<tableId>` (`RN-CORE-43`, `tableId` literal `<modulo>.<nombre>`), sin datos personales; no se borra al cerrar sesión. Se cataloga en `PRIVACY.md §2.1b` |
| Dependencias | **Ninguna nueva.** Sin virtualización (`OPEN-CORE-19`, opción A, decisión del usuario 2026-09-30): **no** se instala `@tanstack/vue-virtual`. `@tanstack/vue-table` ya estaba instalada. Los componentes nuevos del *design system* (`checkbox`, `popover`, si hacen falta) se vendorizan sobre Reka UI, ya instalada (`docs/design-system.md §12.2`/`§12.3`) |
| Estado en la URL | En las rutas cuya tabla principal lo declara (opcional por tabla, como máximo una por ruta), la *query* lleva página, orden y filtros **salvo `q` y `cursor`** (`RN-CORE-54`). Llega a los registros de acceso del servidor web en una recarga completa: por eso nunca contiene el texto de búsqueda. `/administracion/mfa` no lo declara (paridad estricta de la migración). Sin cambios de configuración |

### 12.2 Dependencia operativa: *worker* de colas

La exportación **solo termina si hay un *worker* procesando `core-exports`**. Hoy no hay ninguno desplegado, ni en `compose.yaml` ni en `infra/quadlet/*` (issue [#128](https://github.com/pirexia/plataforma-educativa/issues/128), Alta). Sin él, toda exportación se queda en `pendiente` y la interfaz, tras la duración máxima de `RN-CORE-49`, deja de consultar y ofrece «Comprobar de nuevo». **No es un fallo de 1.9**, y no debe diagnosticarse como tal.

**Discrepancia documental** (`funcional.md §13.20`, punto 1; issue [#273](https://github.com/pirexia/plataforma-educativa/issues/273)): §2 de este documento decía `QUEUE_CONNECTION = redis`; `apps/api/config/queue.php` y `CLAUDE.md §1` dicen `database`. **§2 ya está corregido.** Quedan sin corregir §3 (fila «Redis»: «Colas (Horizon)») y §8 (síntoma «Importación queda en `subido`»: «o Redis no disponible»), que siguen describiendo Redis como cola vigente; son secciones de 1.1, se reportan y no se tocan en este paso.

### 12.3 Carga sobre la API

- Búsqueda con espera de 300 ms y una sola respuesta aplicada (`RN-CORE-40`/`41`): una petición por pausa al escribir, no por pulsación.
- Consulta del estado de exportación con una sola en vuelo, espera inicial de 2 s que se duplica hasta 30 s, y parada a los 10 min (`RN-CORE-49`, `ADR-054 §7.5`): del orden de 23 consultas por exportación como máximo (cálculo sobre las esperas, sin contar la latencia), acotada por diseño incluso sin *worker*.
- «Cargar más» solo por acción del usuario, nunca por desplazamiento (`RN-CORE-56`).
- Sin sondeo de listados: la tabla solo pide datos ante una acción del usuario o al montarse.
- Modo `cursor` acotado a 1.000 filas acumuladas por consulta (`RN-CORE-52`): como mucho 20 peticiones de 50 filas (o 5 de 200) antes de que la interfaz remita a acotar filtros o exportar.

### 12.4 Despliegue y reversión

1. Construir y publicar la imagen de `apps/web` (`ADR-037`).
2. Desplegar. Sin orden relativo con `apps/api`: 1.9 no consume nada que no exista desde 1.1.

**Reversión**: volver a la imagen anterior. Efecto: `MfaComplianceArea` vuelve a su tabla propia (la migra 1.9, `OPEN-CORE-28`; las otras tres tablas existentes no cambian en 1.9, son excepciones de `RN-CORE-53`), los enlaces guardados con estado de tabla en la URL abren sin él (la versión anterior no lee esos parámetros; no verificado, lo comprueba el implementador al probar la reversión), y las claves `plataforma.table.*` quedan huérfanas en los navegadores, inocuas y sin datos personales. Sin datos de servidor que deshacer.

### 12.5 Problemas conocidos y diagnóstico

| Síntoma | Causa probable |
|---------|----------------|
| La exportación se queda en «Preparando…» y acaba en «Comprobar de nuevo» | Ningún *worker* procesa `core-exports` (#128), o el trabajo falló sin llegar a `failed()` |
| Una columna ordenable devuelve `422` al ordenar | La columna se declaró `sortable` sin que su `id` esté en el `enum` de `sort` del *endpoint* en OpenAPI: error del consumidor |
| Un filtro «no filtra» | El `id` de la columna no coincide con el nombre del parámetro de consulta (`ADR-038 §13.3`): el servidor lo ignora como parámetro desconocido (`ADR-038 §5.2`) |
| Las columnas ocultas «vuelven» | Navegación privada, almacenamiento bloqueado u otro navegador: la configuración es solo local (`RN-CORE-43`). Esperado |
| La auditoría deja de «cargar más» | Se alcanzó el tope de 1.000 filas acumuladas (`RN-CORE-52`). Esperado: acotar filtros o exportar |
| Un enlace compartido no conserva la búsqueda por texto | `q` nunca va en la URL (`RN-CORE-54`). Esperado |
| Un enlace a `/administracion/mfa` no conserva los filtros de la tabla | Esa tabla no declara estado en la URL (paridad estricta, `OPEN-CORE-28`). Esperado |
| El botón de exportar está deshabilitado | Hay una búsqueda por texto activa: ninguna exportación acepta `q` (`RN-CORE-57`/`58`). Esperado: borrar la búsqueda |
| Tras recargar, la auditoría vuelve al principio | El `cursor` no va a la URL (`RN-CORE-54`). Esperado |
