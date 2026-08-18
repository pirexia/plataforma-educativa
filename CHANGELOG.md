# CHANGELOG

Historial de la documentación del proyecto. Cuando exista código, este fichero recogerá también las versiones de la aplicación.

Formato: versionado semántico por documento. Mayor = cambio que invalida decisiones previas. Menor = contenido nuevo. Parche = correcciones.

---

## 2026-08-18 · Cierre de 0.13 (plantillas de documentación)

### Nuevo: `SECURITY.md`, `PRIVACY.md`, `RUNBOOK.md`, `CONTRIBUTING.md`
Los cuatro documentos raíz que exige `CLAUDE.md` §6 y que todavía faltaban. `docs/modulos/_PLANTILLA/` ya existía desde el paso 0.1. Cada documento describe lo que es cierto hoy (fase 0, sin datos reales) y marca explícitamente como pendiente lo que depende de un bloqueante todavía abierto (`OPEN-07` para `PRIVACY.md`, `OPEN-11`/`OPEN-10` para `RUNBOOK.md`, `OPEN-08` para el contacto de seguridad de `SECURITY.md`) en vez de rellenarlo con una suposición.

---

## 2026-08-18 · Cierre de 0.8 (modelo de datos núcleo)

### Nuevo: `docs/adr/ADR-034-modelo-de-datos-nucleo.md`
Diseñado por el subagente `architect` (Opus). `Person`/`User` como identidad y credencial separadas; esquema completo de `Role`/`Permission` desde ahora con el resolutor granular diferido a 1.5; `AuditLog` polimórfica append-only con redacción por modelo; `AcademicYear` con `academic_year_id` obligatorio-o-ausente, nunca nullable; `ModuleSubscription` con catálogo de módulos materializado desde el código. Dos preguntas abiertas sin resolver a propósito (`OPEN-12`, supresión frente a auditoría inmutable; `OPEN-13`, columnas definitivas de `Person`), ninguna bloqueante de 0.8.

### Nuevo: `apps/api` — siete tablas del núcleo, modelos y comando de sincronización
`academic_years`, `people`, `users` (rehecha), `roles`/`role_user`, `permissions`/`permission_role`, `modules`/`module_subscriptions`, `audit_logs`. `TenantMigration` gana `tenantTableAppendOnly()` y `tenantForeignId()`. `TenantModel` gana `SoftDeletes` y `RecordsAuthorship`; nuevo `AppendOnlyModel`. Modelos `Person`, `User`, `Role`, `Permission`, `AcademicYear`, `ModuleSubscription`, `AuditLog`, con *morph map* forzado. Comando `platform:sync-registry`, idempotente. 94 tests en `tests/Feature/Core/` y `tests/Feature/Tenancy/`, incluida una batería de invariantes de esquema generales (no hardcodeadas por tabla) que amplía la de `ADR-033` §10.

### Corregido
- **Seguridad, severidad Alta**: `password_reset_tokens` del starter kit de Laravel usaba `email` como clave primaria global — con `users.email` único *por tenant*, un token del centro A servía para la cuenta homónima del centro B (toma de control de cuenta entre tenants). Ahora clave primaria compuesta `(tenant_id, email)`.
- **Seguridad, severidad Alta** ([#17](https://github.com/pirexia/plataforma-educativa/issues/17)): las tablas append-only solo revocaban `UPDATE, DELETE` a `plataforma_app`; `plataforma_platform` (BYPASSRLS) conservaba privilegio completo, vaciando la garantía de inmutabilidad de `audit_logs` para la conexión de backoffice. Revocado también para `plataforma_platform`.
- **Severidad Media** ([#16](https://github.com/pirexia/plataforma-educativa/issues/16)): `tenants.slug` (0.7) con índice único no parcial — un tenant dado de baja bloqueaba su slug para siempre.
- **Severidad Media** ([#19](https://github.com/pirexia/plataforma-educativa/issues/19)), hallazgo de la revisión independiente de `db-reviewer`/`security-reviewer` tras el autoinforme del *fork* de implementación: el test que comprueba que las tablas de referencia no dan privilegios de escritura solo miraba `plataforma_app` — mismo punto ciego que dejó pasar el #17. Generalizado a los dos roles de aplicación.
- **Severidad Media** ([#20](https://github.com/pirexia/plataforma-educativa/issues/20)), mismo origen: `people_tenant_document_unique` no impedía dos personas del mismo tenant con el mismo `document_number` si `document_type` quedaba `NULL` en ambas (PostgreSQL trata cada `NULL` como distinto). `CHECK` nuevo que empareja la nulabilidad de las dos columnas.
- **Diferido a 1.2** ([#18](https://github.com/pirexia/plataforma-educativa/issues/18)): falta un `PasswordBrokerRepository` propio que filtre por tenant — hoy la corrección de `password_reset_tokens` depende solo de RLS. Fuera de alcance de "modelo de datos núcleo".

### Bugs propios encontrados y corregidos durante la implementación
Detalle completo, subpaso a subpaso, en `docs/historial/0.8-modelo-de-datos-nucleo.md`.

---

## 2026-08-17 · Tarde · Cierre de 0.7 (núcleo multi-tenant)

### Nuevo: `docs/adr/ADR-033-implementacion-del-aislamiento-multi-tenant.md`
Diseñado por el subagente `architect` (Opus), aprobado por el usuario. RLS de PostgreSQL como barrera primaria, scope de Eloquent como ergonomía secundaria, tres roles de base de datos sin `SUPERUSER`, claves foráneas compuestas `(tenant_id, id)`, veto a PgBouncer en modo *transaction*, suite de tests sobre PostgreSQL real.

### Nuevo: `apps/api` — infraestructura de tenancy completa
`app/Support/Tenancy/` (`TenantContext`, `Tenant`, `TenantModel`, `BelongsToTenant`, `TenantScope`, `TenantHost`, `TenantStorage`, `TenantMigration`, `RunsPerTenant`, `TenantStatus`), `app/Http/Middleware/ResolveTenant.php`, `app/Providers/TenancyServiceProvider.php`. Tres conexiones de base de datos (`pgsql`/`pgsql_owner`/`pgsql_platform`), `config/tenancy.php` (dominio base, registro de tablas compartidas), primeras claves de `lang/*/tenancy.php`. `infra/containers/postgres/init/` provisiona el esquema `app`, la función `app.current_tenant_id()` y los tres roles. 47 tests en `tests/Feature/Tenancy/`, incluida la batería completa de diez tests de `ADR-033` §10.

### Bugs propios encontrados y corregidos durante la implementación
No relacionados con el diseño de 0.7 en sí, pero descubiertos verificándolo:
- `apps/api/phpunit.xml` sin `force="true"` en `<env>`: la suite llevaba desde el paso 0.4 corriendo contra la base de datos de desarrollo real, no contra la configuración de test documentada.
- `infra/containers/api/Containerfile` sin `--no-reload` en `php artisan serve`: toda petición HTTP real devolvía 500 vacío sin log porque Laravel filtraba el entorno del proceso hijo del servidor embebido.
- `failed_jobs` tenía privilegios completos para `plataforma_app` pese a no tener `tenant_id`/RLS (fuga potencial entre tenants en los registros de fallos).
- `Queue::$createPayloadCallbacks` es estático de clase: se acumulaba en cada reconstrucción de la aplicación (cada test de Laravel, o un futuro Octane en producción).
- `PendingDispatch` envía el job en su `__destruct()`: si `dispatch()` es la expresión de retorno de un closure pasado a `TenantContext::runFor()`, el envío ocurre después de que el contexto se restaure.

Detalle completo del proceso, subpaso a subpaso, en `memory.md`.

---

## 2026-08-17 · Corrección de coherencia: `ADR-024`/`ADR-027`/`ADR-030`

### `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` → 3.1.1
Al preparar el diseño de 0.7 se detectó que la sección 18 (fuente de verdad canónica de `ADR-001` a `ADR-027`, `CLAUDE.md` §6.3) tenía la entrada de `ADR-024` desactualizada — seguía diciendo "Docker Compose sobre VPS europeo" sin reflejar que `ADR-027` lo sustituyó — y que **`ADR-027` no aparecía en ningún sitio del documento**, pese a ser canónico ahí por numeración. Añadida la entrada de `ADR-027` y anotada en ambas la cadena de sustituciones real: `ADR-024` → `ADR-027` (host inicial: VM RHEL 10/VMware, no VPS) → `ADR-030` (sustituye a `ADR-027` para la etapa de desarrollo E0: WSL2 en equipo personal; la VM VMware queda como candidata a preproducción).

### `ARCHITECTURE.md` → 2.0.1
Las entradas de `ADR-024` y `ADR-027` en el apéndice de ADR contradecían a la tabla de §4.2 del mismo documento (que ya reflejaba correctamente WSL2 en E0 desde el cierre de `ADR-030`). Sincronizadas ambas entradas con la cadena de sustituciones.

### `README.md` → 2.4.1
La fila "Host inicial: VM VMware" de la tabla de stack contradecía directamente a la fila "Desarrollo: WSL2 en equipo personal" dos filas por encima. Sustituida por "Alojamiento del piloto: pendiente de decidir (`OPEN-11`)".

### `memory.md`
Nota añadida en la fila de `ADR-027` de la tabla de decisiones señalando la sustitución por `ADR-030` en desarrollo.

---

## 2026-08-14 · Cierre de 0.3 y 0.5, MCP de Boost y Playwright

### Nuevo: `apps/web` (Vue 3 + TypeScript + Vite)
- Tailwind v4 + shadcn-vue inicializados (tema con variables CSS, sin la fuente de Google que trae la plantilla por defecto: llamada a un tercero en cada carga, cuestión de privacidad en un producto que trata datos de menores).
- `vue-router`, `AppLayout` + `HomeView`, `src/modules/` (espejo de `apps/api/app/Modules/`, vacío hasta 1.1).
- Cliente API propio (`src/api/client.ts`, `fetch` nativo sin librería) con `ApiError` tipado y `credentials: 'include'` ya previsto para la cookie de sesión (`ADR-025`).
- ESLint (flat config) + Prettier, Vitest (4 tests) y Playwright (1 e2e, verificado contra el servidor real) en verde.

### `compose.yaml` → 0.3.0
Servicio `web` añadido al perfil reducido (`infra/containers/web/Containerfile`), que queda con `postgres`+`redis`+`api`+`web` por defecto.

### `.mcp.json` (nuevo, raíz del repo)
Laravel Boost (`laravel/boost` en `apps/api`, `php artisan boost:install --mcp`) y Playwright (`@playwright/mcp`). El instalador de Boost escribió el comando envuelto en `wsl.exe`; corregido a mano porque Claude Code ya corre dentro de WSL2.

### `SYSADMIN.md` → 0.3.0
Documentado el servicio `web` y por qué `VITE_API_URL` no se sobrescribe dentro del contenedor (quien hace la petición es el navegador de Windows, no el contenedor).

---

## 2026-08-13 · Tarde · Cierre de 0.4

### Nuevo: `apps/api` (Laravel 13, PHP 8.4)
Primer código de aplicación del repositorio.
- `app/Modules/` con la convención de bounded context (`Domain`, `Application`, `Infrastructure`, `Http`, `INV-007`) y autodescubrimiento de `ServiceProvider` vía `App\Support\Modules\ModuleServiceProviderDiscovery`, sin registro manual en `bootstrap/providers.php`. Vacío hasta el paso 1.1.
- `GET /api/health`, documentado en `apps/api/openapi.yaml`.
- Pest configurado (4 tests, 8 aserciones) y Larastan nivel 6, ambos en verde.
- `routes/web.php` vaciado y `resources/views/welcome.blade.php` eliminada: backend puramente API (`INV-006`).

### `compose.yaml` → 0.2.0
Servicio `api` añadido al perfil reducido (`infra/containers/api/Containerfile`). Corregido un fallo propio de la imagen: purgar `libpq-dev`/`libzip-dev` con `--auto-remove` tras compilar las extensiones se llevaba las librerías compartidas en tiempo de ejecución (`libpq.so.5`, `libzip.so.4`) y `pdo_pgsql`/`zip` dejaban de cargar; el healthcheck no lo detectaba porque no toca la base de datos.

### `SYSADMIN.md` → 0.2.0
Documentado el servicio `api`: puerto, montaje de volumen, variables de entorno sobrescritas para resolución de nombres dentro de la red de contenedores.

---

## 2026-08-13 · Cierre de pasos 0.1, 0.2 y 0.3

### Nuevo: `LICENSE`
Propietaria, todos los derechos reservados. Titularidad jurídica definitiva pendiente de `OPEN-07`.

### Limpieza de 0.1
- Eliminado `SKILL.md` suelto en la raíz, duplicado de `.claude/skills/aislamiento-tenant/SKILL.md`.
- `.gitignore`: añadidos patrones de Python (`__pycache__/`, `*.pyc`, entornos virtuales) para `seed/`.

### `docs/SETUP-ENTORNO.md` → 1.3.0
Alta del MCP de GitHub con gestión segura del token (tres ámbitos de configuración, detección de token en claro en `~/.claude.json`), y cuatro pruebas de verificación del paso 0.2, incluida la prueba negativa de la Regla 0.

### Cierre de 0.2
Verificado con las cuatro pruebas de `docs/SETUP-ENTORNO.md` §7.4: MCP de GitHub confirmado creando y cerrando un issue de prueba. Pendiente sin resolver: `spec-writer` no aparece en la lista de subagentes disponibles de esta sesión pese a estar bien definido en `.claude/agents/spec-writer.md`.

### Nuevo: `compose.yaml`, `.env.example`, `SYSADMIN.md` → 0.1.0
Paso 0.3: perfil reducido (`postgres` + `redis` por defecto, `minio` tras `--profile full`), red externa `plataforma-net` sin destruir (`ADR-028`). Verificado arrancando ambos contenedores en estado `healthy`. `api`, `web` y el servicio de PDF quedan fuera a propósito: los dos primeros por los pasos 0.4/0.5, el tercero por no tener motor decidido.

---

## 2026-08-12 · Tarde

### `.gitignore` → corrección
- **Excluía `marketing/*.pdf`**, lo que habría dejado fuera del repositorio la propia presentación comercial. Ahora solo se ignoran los renders intermedios (`slide-*.jpg`) y `build/`.

### Nuevo: `docs/SETUP-ENTORNO.md` → 1.1.0
Guía completa de puesta en marcha: WSL2 con límite de recursos, claves SSH, GitHub, Podman con red externa, Node, PHP, Claude Code, repositorio con ramas protegidas, plugins y MCP. Con lista de comprobación y problemas frecuentes.
- **1.1.0**: punto 6.3 reescrito con el árbol completo de los 53 ficheros, tabla de qué no se sube y verificación de recuento.

### Nuevo: `marketing/`
Presentación comercial de 15 diapositivas. Nombre de marca **provisional**.

### `PLAN-IMPLEMENTACION.md` → 2.2.0
- Paso **0.10f** (presentación comercial) marcado como completado.
- Nuevo paso **0.11b**: web publicitaria.
- Nuevo paso **0.11c**: identidad de marca, bloqueante de la web.

### `README.md` → 2.1.0
- Índice ampliado con la guía de entorno, el generador y marketing.

---

## 2026-08-12

### `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` → 3.1.0
- **`ADR-032`**: fuente única de autorizaciones de recogida de menores. El concepto estaba definido dos veces —en `REQ-PRL-004` (fase 3) y en `REQ-TRAN-005` (fase 2)— con listas separadas que podían divergir.
- Nuevo **`REQ-FAM-UNIT-005`**: lista maestra de personas autorizadas, con foto y documento, en fase 1.
- `REQ-PRL-004` reducido al proceso operativo de entrega y **adelantado a fase 1**.
- `REQ-TRAN-005` pasa a consumir la lista maestra.

### `PLAN-IMPLEMENTACION.md` → 2.1.0
- Nuevo paso **1.14b**, marcado como crítico.

### Skills
- `datos-personales` → 1.1.0: sección de autorizaciones de recogida.

### `seed/`
- Generador de datos sintéticos ejecutable, con verificador. Tres centros generados.
- Autorizaciones de recogida trasladadas de la suscripción de transporte a la unidad familiar.

---

## 2026-08-11 · Tarde

### `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` → 3.0.0

Versión mayor: cambia el entorno de trabajo y se reordena una fase.

- **`REQ-TRAN` (transporte escolar) reescrito**: de 3 requisitos genéricos a 12. Reubicado de COULD/fase 4 a **SHOULD/fase 2** (`ADR-031`). Incorpora autorizaciones de recogida, registro de subida y bajada con alerta de discrepancia, acompañante de ruta, certificación negativa del RCDS con bloqueo, empresa como encargado de tratamiento e integración en la factura mensual.
- **Nuevo módulo `REQ-SEED`** (datos de demostración), MUST de fase 1: tres centros ficticios de régimen distinto, entre 300 y 1.200 alumnos, plantilla completa de personal, convención de datos sintéticos y bloqueo en producción.
- `ADR-030`: entorno de desarrollo en WSL2 y separación respecto al alojamiento.
- `ADR-031`: alcance y fase del transporte escolar.
- Cerrada `OPEN-06` (titularidad de la infraestructura). Abierta **`OPEN-11`**: dónde se aloja el piloto.
- Total: **53 módulos, 31 ADR**.

### `CLAUDE.md` → 2.0.0
- Desarrollo en WSL2 con perfil reducido.
- **Prohibición explícita de datos reales en desarrollo**, sin excepción.
- Convención de datos sintéticos de `REQ-SEED-005`.

### `ARCHITECTURE.md` → 2.0.0
- Etapa E0 pasa a WSL2; nueva etapa E0b para el piloto.
- Tabla de recursos de desarrollo con límite de `.wslconfig` y perfil reducido.
- Advertencia de que las mediciones de rendimiento en el equipo personal son orientativas.

### `PLAN-IMPLEMENTACION.md` → 2.0.0
- Paso 0.3 reescrito para WSL2.
- Paso 0.10 pasa a ser la decisión de alojamiento del piloto (`OPEN-11`).
- Nuevo paso **1.15b**: generador de datos de demostración.
- `REQ-TRAN` movido a fase 2, inmediatamente después del módulo económico.
- Fase 1: 17 módulos.

### `README.md` → 2.0.0
- Regla 0: ningún dato real en desarrollo.
- Tabla de versiones y bloqueantes actualizados.

---

## 2026-08-11 · Mañana

### `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` → 2.6.0
- `ADR-028`: topología de red y dependencias entre contenedores.
- `ADR-029`: identificador público ULID y convenciones de tipos en PostgreSQL.
- Ambos en fichero propio, estrenando la regla de `ADR-026`.
- Nuevas decisiones abiertas `OPEN-06` a `OPEN-10`.

### `CLAUDE.md` → 1.2.0
- Podman en lugar de Docker.
- Reglas de red y dependencias de contenedores.
- Convenciones de esquema de `ADR-029`.
- ADR `001`-`027` canónicos en la sección 18; del `028` en adelante, fichero propio.

### `ARCHITECTURE.md` → 1.2.0
- `ADR-027`: Podman sobre RHEL 10 en VM VMware.
- `ADR-028`: red y dependencias.
- Sección 4.3 de red entre contenedores.
- Tabla de dimensionado para host único.

### `PLAN-IMPLEMENTACION.md` → 1.1.0
- Corregido el conteo de módulos de fase 1: son 16, no 9. Estimación revisada a 6-8 meses.
- Nuevos pasos 0.10b a 0.10e: dominio y DNS, correo transaccional, destino de copias, staging.
- Nuevos pasos 0.12 (marco legal del proveedor) y 0.13 (plantillas de documentación).

### `docs/SETUP-CLAUDE-CODE.md` → 1.2.0
- Evaluación de MCP: se adopta Context7; se descartan Filesystem, Laravel Codebase MCP y Figma; se aplazan Sentry y Kubernetes.
- Restricción de solo lectura y fuera de producción para el MCP de PostgreSQL.
- Adopción de `timescale/pg-aiguide`.
- 10 skills propias y regla de contención.

### `README.md` → 1.1.0
- Creado como punto de entrada e índice.
- Tabla de versiones de documentos.

### Skills
| Skill | Versión |
|-------|---------|
| `aislamiento-tenant` | 1.0.0 |
| `contenedores-y-red` | 1.0.0 |
| `migracion-segura` | 1.0.0 |
| `postgres-rendimiento` | 1.1.0 (convenciones de `ADR-029`) |
| `depuracion` | 1.0.0 |
| `permisos-y-roles` | 1.0.0 |
| `datos-personales` | 1.0.0 |
| `modulo-nuevo` | 1.0.0 |
| `i18n-cuatro-idiomas` | 1.0.0 |
| `cierre-de-sesion` | 1.0.0 |

---

## Anterior

- **2.5.0** · Stack cerrado: Laravel + Vue 3/TS + PostgreSQL. `ADR-023` a `ADR-026`. Cerradas `OPEN-01` a `OPEN-05`.
- **2.4.0** · MFA por rol, módulo de copias de seguridad, despliegue sin interrupción.
- **2.3.0** · Backoffice de Super Administrador.
- **2.2.0** · Primer ciclo de Infantil 0-3, régimen por etapa, cuatro idiomas.
- **2.1.0** · Segmento concertados de Madrid, posicionamiento frente a Raíces.
- **2.0.0** · Reorganización para implementación asistida por IA. 22 módulos nuevos.
- **1.2.0** y anteriores · Versiones iniciales del documento de requisitos.
