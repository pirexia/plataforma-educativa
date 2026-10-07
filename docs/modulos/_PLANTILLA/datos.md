# REQ-XXX · Modelo de datos

## Entidades
Una tabla por entidad: campo, tipo, nulo, valor por defecto, descripción.

## Relaciones
Diagrama Mermaid.

## Índices
Justificar cada uno con la consulta que lo necesita. Un índice sin consulta que lo necesite es deuda.

## Checklist obligatorio

Cada casilla dice si la **vigila un test** o depende de revisión (`ADR-056`, `ARCHITECTURE.md §3.4`). Lo vigilado no puede pudrirse sin que un test falle; el resto lo comprueban `db-reviewer` y `doc-reviewer`.

- [ ] `tenant_id` presente e indexado como primera columna de las consultas frecuentes
- [ ] **Política de RLS declarada para cada tabla nueva de negocio** (`INV-001`, `ADR-033`): el aislamiento va en base de datos, no solo en el framework. *Lo comprueba `IsolationBatteryTest` #8 (RLS en toda tabla con `tenant_id`).*
- [ ] `academic_year_id` si la entidad depende del curso, obligatorio-o-ausente, nunca *nullable* (`ADR-034`), declarado con `TenantMigration::tenantForeignId(Blueprint $blueprint, string $column, string $referencedTable, ?string $constraintName = null)`. **Lo comprueba `AR-13`**: la tabla lleva el disparador `academic_year_write_guard` (`ADR-057`), que ponen solos `TenantMigration::tenantTable()`/`tenantTableAppendOnly()` (y `guardAcademicYearWrites()` si se añade la columna a una tabla existente); nunca se escribe el `CREATE TRIGGER` a mano; al retirar la columna, `DROP TRIGGER academic_year_write_guard` antes de `DROP COLUMN`
- [ ] `created_at`, `updated_at`, `deleted_at`, `created_by`, `updated_by` (`INV-005`)
- [ ] Claves foráneas, `CHECK` y restricciones declaradas en base de datos, no solo en la aplicación
- [ ] **Política de valor de auditoría del modelo** (`ADR-035`): `Full`, `Selective` o `None`, con los atributos registrados, y cuáles se redactan o no se registran. **Y alias estable del modelo en el *morph map*** (`Relation::enforceMorphMap`, `ADR-034 §3`). *`AR-06` comprueba que todo modelo de tenant implementa `Auditable`; la política y el alias dependen de revisión (`enforceMorphMap()` falla en el primer test que cree el modelo sin alias).*

### Convenciones de tipos (`ADR-029`, sin excepciones)
Las cuatro primeras las comprueba `AR-04` sobre el esquema real de la base de test; `AR-05` la quinta.
- [ ] **`TIMESTAMPTZ` siempre**, nunca `timestamp` sin zona: `timestampsTz()` y `timestampTz()`, no los `timestamps()` por defecto de Laravel. *Lo comprueba `AR-04`.*
- [ ] **`text`**, nunca `varchar(n)`: la longitud se valida en la aplicación y con `CHECK` cuando sea regla de negocio. *Lo comprueba `AR-04` (tampoco admite `character(n)` salvo `n = 26`).*
- [ ] **Importes en enteros de céntimos.** Ni coma flotante ni decimal: el riesgo está en PHP, no en PostgreSQL. *Depende de revisión: la base de datos no sabe qué columna es un importe.*
- [ ] **Enumerados** como columna `text` con `CHECK`, o tabla de referencia. Nunca el tipo `ENUM` de PostgreSQL. *Lo comprueba `AR-04`.*
- [ ] Clave primaria `bigint` interna **más `public_id` ULID** en toda entidad que aparezca en URL, API o documento exportado (`character(26) NOT NULL` con índice único propio; las claves foráneas siguen usando la clave interna). *Lo comprueba `AR-05` para la forma de la columna; que la entidad la lleve depende de revisión.* **Línea de `public_id`: anota aquí, si el módulo declara alguna, las claves de catálogo que se exponen en URL o API además del ULID** (`ADR-051 §5.1`: el módulo demuestra en este fichero las seis condiciones C1-C6 y las registra en el registro de claves de catálogo; `CatalogKeyRouteArchitectureTest` falla con cualquier parámetro de ruta que no sea `public_id` ni una clave registrada). Si no declara ninguna, di «ninguna».
- [ ] `NULLS NOT DISTINCT` en la unicidad cuando la regla lo exija

### Resto
- [ ] Datos de categoría especial en tabla separada y cifrada, con permisos propios y auditoría de lectura. *`AR-09` es un cable trampa: falla en cuanto un permiso declara `is_special_category = true`, hasta que se diseñe el mecanismo (`ADR-044 §4.4`).*
- [ ] Particionado declarativo nativo por curso académico evaluado si es tabla de alto crecimiento (`ADR-029`); nunca *hypertables*

## Retención y supresión
Plazo de conservación, base legal y estrategia de supresión (`ADR-004`, `ADR-035`, `REQ-PRIV-006`).
