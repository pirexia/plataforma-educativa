---
name: modulo-nuevo
description: Estructura y pasos para crear un módulo (bounded context) nuevo en la API o en el frontend. Úsala al iniciar cualquier REQ-XXX que no exista todavía.
---

# Crear un módulo nuevo

Esta skill describe la forma **real** de los módulos que existen (`Auth`, `Backoffice`, `Core` —`ADR-056 §1.2`— y `Curso` desde `1.10`) y qué regla de CI vigila cada pieza. Ninguno es todavía un módulo de negocio con tablas por curso propias: `Curso` es el dueño del contrato transversal del curso (`ADR-057`) y la forma de uno de negocio se fijará con `REQ-ACAD` (`1.11`). El generador `make:module` está diferido al paso `1.11b`: **hoy el módulo se crea a mano**, y las reglas `AR-*` fallan si se omite algo.

## Estructura en la API

```
apps/api/app/Modules/<Modulo>/
├── Domain/          entidades, objetos de valor, eventos, interfaces públicas; Domain/Models/ = modelos Eloquent
├── Application/     casos de uso, DTOs
├── Infrastructure/  <Modulo>ServiceProvider.php, repositorios, adaptadores, listeners, jobs, comandos
├── Http/            controladores, requests, resources y routes.php
└── Database/
    └── migrations/
```

Una capa no se crea hasta que tiene contenido. **No existen** `Tests/` ni `Database/factories` ni `Database/seeders` dentro del módulo: los tests viven en `apps/api/tests/Feature/<Modulo>/` y las factorías, cuando haga falta alguna, en `apps/api/database/factories/`.

## Piezas del esqueleto y quién las vigila

| Pieza | Regla que la vigila |
|-------|---------------------|
| `Infrastructure/<Modulo>ServiceProvider.php`, extiende `ServiceProvider`, autodescubierto (no se registra en `bootstrap/providers.php`); ninguna otra clase `*ServiceProvider` en el módulo | `AR-03` |
| `boot()` con `loadMigrationsFrom(app_path('Modules/<Modulo>/Database/migrations'))` | `AR-03` |
| `implements DeclaresModuleRegistry`: `moduleDescriptor()` (`code`, `name_key`, `phase`, `depends_on`, `essential`) y `declaredPermissions()` con `applicable_scopes` por entrada; `Backoffice` es la única excepción (módulo de plataforma) | `AR-03`, `AR-09`, `AR-10` |
| `Http/routes.php`, **requerido a mano** desde `routes/api-v1.php` (o `routes/api.php` si es de plataforma). Todo endpoint con `permission:`; módulo no esencial con `module-enabled:<código>` **antes** de `permission:` | `AR-07a`, `AR-07b` |
| `lang/{es,en,de,fr}/<código>.php` y la entrada `name_key` del módulo en `lang/{es,en,de,fr}/modules.php`, con los cuatro nombres reales traducidos | `AR-12` (paridad de claves; no detecta un nombre sin traducir copiado a los cuatro) |
| Cada modelo de tenant: migración con `TenantMigration::tenantTable(...)`, `$table->ulid('public_id')->unique()`, `text` y no `varchar`, `timestampTz` y no `timestamp`, sin `ENUM` | `AR-04`, `AR-05` |
| **Tablas que dependen del curso** (`academic_year_id`): se crean **con el ayudante** `TenantMigration::tenantTable()`/`tenantTableAppendOnly()` y `TenantMigration::tenantForeignId($blueprint, 'academic_year_id', 'academic_years')` (firma `Blueprint $blueprint, string $column, string $referencedTable, ?string $constraintName = null`), que engancha solo el disparador `academic_year_write_guard` (bloqueo de escritura de un curso cerrado, `ADR-057`). Nunca `Schema::create` a mano ni un `CREATE TRIGGER` propio; **Prohibido `cascadeOnDelete()`, `nullOnDelete()` y `cascadeOnUpdate()` (ON DELETE/UPDATE CASCADE, SET NULL, SET DEFAULT) en cualquier tabla con `academic_year_id`**: PostgreSQL ejecuta las acciones referenciales como propietario de la tabla hija, exento del disparador (`ADR-057 §5.2`), así que saltarían el bloqueo de un curso cerrado. Solo `NO ACTION`; lo comprueba `AR-13`.  al añadir la columna a una tabla existente, `TenantMigration::guardAcademicYearWrites($table)`; al retirarla, `DROP TRIGGER academic_year_write_guard` **antes** de `DROP COLUMN` (`AR-13` vigila el disparador huérfano). El módulo accede al curso solo por las interfaces de `Curso\Domain` (`AcademicYearContext`, `AcademicYearDirectory`, `AcademicYearReadAccess`…), nunca por el modelo ni consultando `academic_years`; la lectura de un curso cerrado invoca `AcademicYearReadAccess` en cada endpoint (`RN-CURSO-25`) | `AR-13` (escritura); la lectura es criterio de aceptación de cada módulo (`OPEN-057-03`) |
| **Lectura de datos por curso**: la especificación incluye el criterio de aceptación de lectura denegada de curso cerrado (`RN-CURSO-33`, `OPEN-057-03`): sin `curso_historico.leer`, `404` en listado **y** en detalle; cada *endpoint* de lectura invoca `AcademicYearReadAccess` (la escritura la cubre el disparador, la lectura no) | Revisión y criterio de aceptación; sin regla `AR-*` |
| Cada modelo de tenant: `extends TenantModel`, `implements Auditable` (`ADR-035`) y alias en `Relation::enforceMorphMap([...])` del `boot()` de su `ServiceProvider` (los modelos de `App\Models` los registra `app/Providers/AppServiceProvider.php`; `Backoffice` no registra ninguno) | `AR-06` |
| Consultas de un recurso con ámbito restringido solo por `ScopedQuery`; un recurso nuevo con ámbito distinto de `todos` entra en el mapa cerrado de `AR-10` con su especificación | `AR-10` |
| Documentación: `docs/modulos/REQ-XXX/{funcional,datos,api,permisos,operacion}.md` (**cinco** ficheros, plantilla en `docs/modulos/_PLANTILLA/`) | `doc-reviewer` |

## Estructura en el frontend

```
apps/web/src/modules/<modulo>/
├── shell.ts          rutas, navegación y bloques del panel (ADR-053); tres listas, cualquiera vacía
├── api/index.ts      superficie pública: cliente tipado
├── types/index.ts    superficie pública: formas de los recursos
├── locales/{es,en,de,fr}.json
└── views/ components/ composables/   internos
```

Dos registros que se editan a mano: la línea del `shell` en `src/navigation/modules.ts` y los cuatro imports de `locales` en `src/i18n/index.ts`. `AR-11` (`src/modules/architecture.spec.ts`) falla si falta cualquiera de las siete piezas o los dos registros, o si el módulo importa de otro algo que no sea `api/`, `types/` o `shell`. El generador no escribirá la parte web: el contenedor de la API no ve `apps/web`.

## Reglas de frontera (`INV-007`, `AR-01`, `AR-02`)

- Un módulo **no importa** clases internas de otro. De otro módulo solo usa su `Domain`, **excluido `Domain\Models`**: una interfaz pública, un evento o un enumerado. Un `belongsTo` hacia el modelo Eloquent de otro módulo no vale: la clave foránea en base de datos sigue permitida, pero la lectura va por una interfaz de `Domain`.
- Si un módulo necesita saber algo de roles, lo pide por una interfaz de `Core\Domain`: `App\Models\Role` y los códigos `administrador_centro`/`soporte_plataforma` están confinados (`AR-08`). Se comprueban permisos, nunca roles.
- El núcleo (`App\Support`, `App\Http`, `App\Models`, `App\Providers`) no depende de internos de ningún módulo.
- La comunicación entre módulos es por **eventos de dominio** (p. ej. `UserDeactivated`, emitido por `Core` y consumido por `Auth`) o por interfaces públicas (p. ej. `TenantSettingsReader`).
- Si dos módulos necesitan compartir mucho, probablemente son el mismo módulo o falta uno común. Plantéalo antes de acoplarlos.
- Prohibido consultar directamente tablas de otro módulo.
- Las listas de excepciones de los tests de arquitectura son cerradas y solo se amplían con especificación aprobada expresamente por el usuario.

## Referencias por patrón

`ARCHITECTURE.md §3.4` apunta a ficheros concretos (declaración de permisos, recurso con ámbito, recurso de tenant, tarea en cola, interfaz pública, evento, migración, módulo web). Copia el patrón de ahí, no de «el módulo de referencia»: no existe.

## Checklist de alta

- [ ] Especificación aprobada en `docs/modulos/REQ-XXX/`
- [ ] Registro del módulo en el catálogo de módulos activables (`RMOD-001`): `moduleDescriptor()` y entrada en `modules.php`
- [ ] Dependencias declaradas (`RMOD-006`): `depends_on`
- [ ] Permisos definidos: recurso, acciones y ámbitos (`applicable_scopes` y `resource_label_key` por permiso)
- [ ] Comportamiento con el módulo desactivado: oculto en la interfaz y 403 informativo en la API (`RMOD-008`, `RMOD-009`), `module-enabled:` en cada ruta
- [ ] Traducciones en los cuatro idiomas
- [ ] Tests en `tests/Feature/<Modulo>/`, incluido el de aislamiento entre tenants en listado y detalle
- [ ] `./vendor/bin/pest --group=arch` en verde (y `AR-11` en la web)
- [ ] Documentación de los cinco ficheros del módulo (`funcional`, `datos`, `api`, `permisos`, `operacion`)
