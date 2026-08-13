# REQ-XXX · Modelo de datos

## Entidades
Una tabla por entidad: campo, tipo, nulo, valor por defecto, descripción.

## Relaciones
Diagrama Mermaid.

## Índices
Justificar cada uno con la consulta que lo necesita.

## Checklist obligatorio
- [ ] `tenant_id` presente e indexado como primera columna de las consultas frecuentes
- [ ] `academic_year_id` si la entidad depende del curso
- [ ] `created_at`, `updated_at`, `deleted_at`, `created_by`, `updated_by`
- [ ] Claves foráneas y restricciones declaradas en base de datos
- [ ] Importes en enteros de céntimos o decimal exacto
- [ ] Fechas en UTC
- [ ] Datos de categoría especial en tabla separada y cifrada
- [ ] Particionado evaluado si es tabla de alto crecimiento

## Retención y supresión
Plazo de conservación, base legal y estrategia de supresión (`ADR-004`, `REQ-PRIV-006`).
