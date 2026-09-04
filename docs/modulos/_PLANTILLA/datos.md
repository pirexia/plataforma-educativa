# REQ-XXX · Modelo de datos

## Entidades
Una tabla por entidad: campo, tipo, nulo, valor por defecto, descripción.

## Relaciones
Diagrama Mermaid.

## Índices
Justificar cada uno con la consulta que lo necesita. Un índice sin consulta que lo necesite es deuda.

## Checklist obligatorio
- [ ] `tenant_id` presente e indexado como primera columna de las consultas frecuentes
- [ ] **Política de RLS declarada para cada tabla nueva de negocio** (`INV-001`, `ADR-033`): el aislamiento va en base de datos, no solo en el framework
- [ ] `academic_year_id` si la entidad depende del curso, obligatorio-o-ausente, nunca *nullable* (`ADR-034`)
- [ ] `created_at`, `updated_at`, `deleted_at`, `created_by`, `updated_by` (`INV-005`)
- [ ] Claves foráneas, `CHECK` y restricciones declaradas en base de datos, no solo en la aplicación

### Convenciones de tipos (`ADR-029`, sin excepciones)
- [ ] **`TIMESTAMPTZ` siempre**, nunca `timestamp` sin zona: `timestampsTz()` y `timestampTz()`, no los `timestamps()` por defecto de Laravel
- [ ] **`text`**, nunca `varchar(n)`: la longitud se valida en la aplicación y con `CHECK` cuando sea regla de negocio
- [ ] **Importes en enteros de céntimos.** Ni coma flotante ni decimal: el riesgo está en PHP, no en PostgreSQL
- [ ] **Enumerados** como columna `text` con `CHECK`, o tabla de referencia. Nunca el tipo `ENUM` de PostgreSQL
- [ ] Clave primaria `bigint` interna **más `public_id` ULID** en toda entidad que aparezca en URL, API o documento exportado. Las claves foráneas siguen usando la clave interna
- [ ] `NULLS NOT DISTINCT` en la unicidad cuando la regla lo exija

### Resto
- [ ] Datos de categoría especial en tabla separada y cifrada, con permisos propios y auditoría de lectura
- [ ] Particionado declarativo nativo por curso académico evaluado si es tabla de alto crecimiento (`ADR-029`); nunca *hypertables*

## Retención y supresión
Plazo de conservación, base legal y estrategia de supresión (`ADR-004`, `ADR-035`, `REQ-PRIV-006`).
