# REQ-XXX · API

Prefijo: `/api/v1/...`

Todo lo que sigue se ajusta a `ADR-038` (convenciones de la API REST), que es de aplicación a los 53 módulos: envoltura de la respuesta (§3), paginación por página o por cursor según el criterio objetivo de §4, sintaxis de filtrado y orden (§5), formato de error RFC 9457 con `type` como URN (§6) y reglas de versionado y compatibilidad (§7).

## Endpoints

### `VERBO /ruta`
- **Permiso**: recurso · acción · ámbito
- **Parámetros**
- **Respuesta 200**
- **Errores**: 400, 401, 403, 404, 409, 422, 429
- **Idempotencia**: sí/no

## Paginación, filtrado y ordenación
Según `ADR-038` §4 y §5. Indica cuál de las dos paginaciones usa cada listado y por qué, y qué campos admiten filtro y orden.

## Exportación de listados (`ADR-054`, `ADR-055`)
Solo si el recurso se exporta (`exportar` es la acción que más datos mueve y la que más se olvida):
- Se ejecuta **en cola** (`INV-012`) y entrega un enlace caducable; verifica permiso y se audita con el detalle de lo exportado.
- **Paridad de filtros** (`ADR-054 §8.2`): acepta exactamente los filtros estructurados de su listado, con los mismos nombres y semántica, salvo paginación, `sort` y `q`.
- **Sin `q`** (`ADR-054 §9`): la exportación responde `422` con un código de error propio del recurso si recibe `q`. No se ignora.
- **El CSV de datos es un contrato técnico estable** (`ADR-055`): esquema cerrado, declarado aquí, sin literales de interfaz ni datos que el listado no muestre; los campos de texto libre se neutralizan contra inyección de fórmulas (`CsvWriter`).

## Eventos de dominio emitidos

## Webhooks disponibles
