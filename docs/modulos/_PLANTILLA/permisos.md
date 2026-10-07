# REQ-XXX · Permisos

## Recursos que aporta el módulo

Para cada permiso del catálogo (`declaredPermissions()`): `code`, `resource`, `action`, `is_special_category`, **`applicable_scopes` explícito** (vocabulario cerrado y central de `ADR-044 §4.1`; omitirlo equivale a `['todos']`, así que se declara siempre) y `resource_label_key` (la clave de traducción del nombre del recurso, en los cuatro idiomas del módulo dueño). *`PermissionCatalogTest` falla si falta la etiqueta; `AR-07a`, `AR-07b`, `AR-09` y `AR-10` vigilan el resto.*

### Ámbito restringido (`ADR-044 §4.2`, `AR-10`)
Por cada recurso con `applicable_scopes` distinto de `['todos']`:
- **Resolutor de ámbito** de cada entidad propia: qué clase lo implementa y qué consulta acota (resuelve a una consulta, no a un booleano).
- El recurso entra en el mapa cerrado de `AR-10` (recurso → modelo y ficheros sancionados): **solo `ScopedQuery` consulta ese modelo**. Un fichero nuevo que lo consulte exige especificación aprobada; el test no ve accesos por relación ni `DB::table`, así que esa parte la cubre el criterio de aceptación de `funcional.md`.

## Matriz recurso × acción × ámbito

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| | | | | | | | | | |

## Asignación en roles predefinidos

| Rol | Permisos | Ámbito |
|-----|----------|--------|
| | | |

## Datos de categoría especial
¿El módulo expone salud, NEAE o convivencia? Si es así, permiso separado, no incluido en ningún rol por defecto, y auditoría de lectura (`RPERM-015`).

## MFA
¿Algún rol de este módulo debería llevar `mfa_obligatorio` por defecto?
