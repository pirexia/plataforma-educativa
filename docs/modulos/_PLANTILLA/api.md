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

## Eventos de dominio emitidos

## Webhooks disponibles
