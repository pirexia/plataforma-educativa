# app/Modules/

Un directorio por bounded context (`ARCHITECTURE.md` §3, y §3.4 para las reglas comprobadas y las referencias). Hoy hay cuatro: `Auth`, `Backoffice`, `Core` y `Curso`. Ninguno es todavía un módulo de negocio con tablas por curso: `Core` es el cimiento (y sus entidades principales —`User`, `Role`, `Person`, `AuditLog`, `ModuleSubscription`, `Permission`, `PermissionRole`, `IdempotencyKey`— viven en `App\Models`, fuera del módulo), `Auth` es el más cargado de seguridad, `Backoffice` es de plataforma (sin tenant ni catálogo de permisos) y `Curso` (esencial, `depends_on []`, desde `1.10`) es el dueño de `AcademicYear` y del contrato transversal del curso (`ADR-057`). La forma de un módulo **de negocio** se fijará al construir los primeros (`REQ-CURSO`, `REQ-ACAD`); lo de abajo es lo que de verdad se repite.

```
<Modulo>/
├── Domain/           # entidades, value objects, interfaces públicas, eventos; Domain/Models/ = modelos Eloquent
├── Application/      # casos de uso, DTOs
├── Infrastructure/   # repositorios, adaptadores, <Modulo>ServiceProvider.php, listeners, jobs, comandos de consola
├── Http/             # controladores, requests, resources y routes.php
└── Database/
    └── migrations/   # migraciones del módulo (el ServiceProvider las carga con loadMigrationsFrom)
```

Una capa no existe hasta que tiene contenido: no se crean carpetas vacías por adelantado.

## El esqueleto común

- **`Infrastructure/<Modulo>ServiceProvider.php`** (namespace `App\Modules\<Modulo>\Infrastructure`): se autodescubre con `App\Support\Modules\ModuleServiceProviderDiscovery`; no se registra a mano en `bootstrap/providers.php`. Extiende `ServiceProvider`, carga `Database/migrations` en `boot()` e implementa `DeclaresModuleRegistry` (`moduleDescriptor()` y `declaredPermissions()`, `ADR-034 §5`, `ADR-045`); `platform:sync-registry` lo materializa en `modules` y `permissions`. Excepción: `Backoffice` no la implementa (`REQ-BO/funcional.md §10`).
- **`Http/routes.php`**: se **requiere a mano** desde `routes/api-v1.php` (`Core`, `Auth`, `Curso`) o `routes/api.php` (`Backoffice`, bajo `platform/v1`). Un fichero compartido que se edita al crear el módulo.
- **Traducciones**: `lang/{es,en,de,fr}/<código>.php` (el nombre del fichero no siempre es el del módulo: `auth`, `bo`, `core`) y la entrada con el nombre del módulo en `lang/{es,en,de,fr}/modules.php` (`name_key`).
- **Modelos auditables**: si hay modelos `Auditable`, se registran en `Relation::enforceMorphMap([...])` en el `boot()` de su `ServiceProvider`, que se suma al mapa existente (`ADR-034 §3`). Matices: las cuatro entidades de `Core` que viven en `App\Models` (`person`, `user`, `role`, `module_subscription`) se registran en `app/Providers/AppServiceProvider.php`, no en el módulo; `Auth`, `Core` y `Curso` (`academic_year`) registran las suyas en su `ServiceProvider`; `Backoffice` no registra ninguna.
- **Tests**: en `apps/api/tests/Feature/<Modulo>/`, no dentro del módulo. **Factorías**: en `apps/api/database/factories/` (hoy solo `Tenant`, `Person` y `User`); los demás modelos se crean en los tests con `::create()`.
- **Documentación**: `docs/modulos/REQ-XXX/{funcional,datos,api,permisos,operacion}.md` (plantilla en `docs/modulos/_PLANTILLA/`).
- **Parte web**: `apps/web/src/modules/<modulo>/` (ver su `README.md`).

## Reglas que vigilan los tests (`ADR-056`, `ARCHITECTURE.md §3.4`)

Todas en el grupo `arch` (`./vendor/bin/pest --group=arch`), en `tests/Feature/Architecture/`. Un módulo nuevo queda vigilado sin tocar los tests: se enumera el sistema de ficheros.

- **Ningún módulo importa código interno de otro** (`INV-007`, `AR-01`): de otro módulo solo se usa su `Domain`, **excluido `Domain\Models`** (un modelo Eloquent ajeno es consultar la tabla de otro módulo; la clave foránea en base de datos sigue permitida, la lectura va por una interfaz de `Domain`). La comunicación es por interfaces públicas o eventos de dominio. El núcleo (`App\Support`, `App\Http`, `App\Models`, `App\Providers`) tiene la misma frontera (`AR-02`).
- **Convención de `ServiceProvider`** (`AR-03`), **esquema** (`AR-04`: ni `varchar`, ni `timestamp` sin zona, ni `ENUM`; `AR-05`: `public_id` `character(26) NOT NULL` con índice único), **`Auditable`** en todo modelo de tenant (`AR-06`, `INV-003`), **`permission:` en toda ruta de `api/v1`** (`AR-07a`, `INV-002`) y **`module-enabled:<código>` antes de `permission:` en las de un módulo no esencial** (`AR-07b`, `RMOD-009`), **sin comprobar el rol, sino el permiso** (`AR-08`: la clase `Role` y los códigos `administrador_centro`/`soporte_plataforma` confinados), **ámbito restringido solo por `ScopedQuery`** (`AR-10`), **sin escrituras masivas por modelo sobre un modelo `Auditable`** (`AR-15`, `INV-003`: `Modelo::where()->update()/delete()` no dispara la auditoría; se actualiza o borra por instancia, o se audita explícitamente), **paridad de las cuatro lenguas** (`AR-12`, `INV-009`) y, desde `1.10`, **toda tabla con `academic_year_id` lleva el disparador `academic_year_write_guard`** que bloquea la escritura de un curso cerrado (`AR-13`, `ADR-057`; lo ponen solos los ayudantes de `TenantMigration`).
- Las listas de excepciones de esos tests son **cerradas y nominales**, solo pueden reducirse y ampliarlas exige especificación aprobada expresamente por el usuario.
- No se vigilan por test, y son criterio de aceptación y de revisión: el test HTTP de aislamiento entre tenants en listado y detalle de cada recurso (`ADR-044 §4.2`), la visibilidad de `public_id` en las respuestas y los importes en céntimos.
