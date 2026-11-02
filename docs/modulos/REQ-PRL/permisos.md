# REQ-PRL · Puerta y entrega de menores · Permisos

> **Origen del contenido.** Traslado **sin cambios de contenido** de lo marcado `prl` en `docs/modulos/REQ-FAM-UNIT/` (especificación del paso `1.14b`, aprobada el 2026-10-29), tal como exige `ADR-068 §5.1`. La numeración de IDs (`RN-PRL-*`, `CA-PRL-*`, `OPEN-*`, secciones `§21.x`/`§8.x`) se conserva tal cual para que las referencias cruzadas existentes sigan siendo válidas. Las referencias a `§N` sin más indican la sección de `REQ-FAM-UNIT/funcional.md`, `datos.md`, `api.md`, `permisos.md` u `operacion.md` del fichero homónimo de ese módulo.

## 8.1 Recursos

| Recurso | Módulo | Qué representa | Por qué así |
|---------|--------|----------------|-------------|
| `entrega_menor` | `prl` | Consulta de la lista en la puerta y registro de movimientos (salidas, entradas, fuera de horario) | El proceso operativo de `REQ-PRL-004`: conserjería lo necesita **sin** poder dar de alta autorizados |
| `alerta_entrega` | `prl` | Alertas por persona no autorizada y su resolución | Abrir una alerta (conserjería) y cerrarla (dirección) pueden repartirse entre roles |

## 8.2 Catálogo

| `code` | `applicable_scopes` | *Endpoints* (`api.md §8`) |
|--------|---------------------|---------------------------|
| `entrega_menor.leer` (`prl`) | `['todos']` | `GET /door/students`, `GET /door/students/{…}/pickup-list`, `GET /student-movements*` |
| `entrega_menor.crear` (`prl`) | `['todos']` | `POST /student-movements` |
| `alerta_entrega.leer` (`prl`) | `['todos']` | `GET /pickup-alerts*` |
| `alerta_entrega.crear` (`prl`) | `['todos']` | `POST /pickup-alerts` |
| `alerta_entrega.actualizar` (`prl`) | `['todos']` | `PATCH /pickup-alerts/{id}` |

## 8.3 Matriz

| Recurso | crear | leer | actualizar | eliminar | exportar |
|---------|-------|------|------------|----------|----------|
| `entrega_menor` | `todos` | `todos` | — | — | — |
| `alerta_entrega` | `todos` | `todos` | `todos` | — | — |

**`prl` no necesita permisos de `fam_unit`**: lee la lista por `AuthorizedPickupList` con su propio permiso (`entrega_menor.leer`), y la interfaz audita la lectura. Así conserjería consulta sin poder gestionar autorizados.

## 8.4 Siembra en roles predefinidos (`L9`, aprobada 2026-10-29)

| Rol | Permisos | Ámbito |
|-----|----------|--------|
| `administrador_centro` | Todos los de §8.2 | `todos` |
| `secretaria` | `autorizado_recogida.*`, `entrega_menor.leer`, `alerta_entrega.leer` | `todos` |
| `direccion` | `autorizado_recogida.leer`, `entrega_menor.leer`, `alerta_entrega.*` | `todos` |
| `conserjeria_pas` (rol predefinido de `ProvisionTenantDefaults`, `ADR-068` hecho 16) | `entrega_menor.leer`, `entrega_menor.crear`, `alerta_entrega.leer`, `alerta_entrega.crear` | `todos` |

Conserjería **no** recibe `autorizado_recogida.crear`: una recogida no prevista pasa por secretaría o dirección, que la registran como autorización puntual por el canal `telefono` tras devolver la llamada al principal (`OPEN-FAM-UNIT-35`, aprobada; `RN-FAM-UNIT-85`). Siembra por `ProvisionTenantDefaults` y por un comando de `Core` para centros existentes (patrón `fam_unit:grant-permissions`; uno por módulo o ampliar el existente: decisión de implementación), idempotente y sin conceder nada si falta un permiso (#387). **MFA** (recomendación, no se aplica): un rol con `autorizado_recogida.crear` debería llevar `mfa_obligatorio`, porque puede añadir a un extraño a la lista de un menor.
