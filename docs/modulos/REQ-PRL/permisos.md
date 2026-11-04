# REQ-PRL · Puerta y entrega de menores · Permisos

> **Origen del contenido.** Traslado **sin cambios de contenido** (salvo las secciones de implementación de `1.14b-b`, que son nuevas) de lo marcado `prl` en `docs/modulos/REQ-FAM-UNIT/` (especificación del paso `1.14b`, aprobada el 2026-10-29), tal como exige `ADR-068 §5.1`. La numeración de IDs (`RN-PRL-*`, `CA-PRL-*`, `OPEN-*`, secciones `§21.x`/`§8.x`) se conserva tal cual para que las referencias cruzadas existentes sigan siendo válidas. Las referencias a `§N` sin más indican la sección de `REQ-FAM-UNIT/funcional.md`, `datos.md`, `api.md`, `permisos.md` u `operacion.md` del fichero homónimo de ese módulo.

## 8.1 Recursos

| Recurso | Módulo | Qué representa | Por qué así |
|---------|--------|----------------|-------------|
| `entrega_menor` | `prl` | Consulta de la lista en la puerta y registro de movimientos (salidas, entradas, fuera de horario) | El proceso operativo de `REQ-PRL-004`: conserjería lo necesita **sin** poder dar de alta autorizados |
| `alerta_entrega` | `prl` | Alertas por persona no autorizada y su resolución | Abrir una alerta (conserjería) y cerrarla (dirección) pueden repartirse entre roles |

## 8.2 Catálogo

| `code` | `applicable_scopes` | *Endpoints* (`api.md §8`) |
|--------|---------------------|---------------------------|
| `entrega_menor.leer` (`prl`) | `['todos']` | `GET /door/students`, `GET /door/students/{studentPersonPublicId}/pickup-list`, `GET /student-movements*` |
| `entrega_menor.crear` (`prl`) | `['todos']` | `POST /student-movements` |
| `alerta_entrega.leer` (`prl`) | `['todos']` | `GET /pickup-alerts*` |
| `alerta_entrega.crear` (`prl`) | `['todos']` | `POST /pickup-alerts` |
| `alerta_entrega.actualizar` (`prl`) | `['todos']` | `PATCH /pickup-alerts/{id}` |

## 8.3 Matriz

| Recurso | crear | leer | actualizar | eliminar | exportar |
|---------|-------|------|------------|----------|----------|
| `entrega_menor` | `todos` | `todos` | — | — | — |
| `alerta_entrega` | `todos` | `todos` | `todos` | — | — |

**`prl` no necesita permisos de `fam_unit`**: lee la lista por `AuthorizedPickupList` con su propio permiso (`entrega_menor.leer`), y la interfaz audita la lectura. Enmienda del 2026-11-04 (`DP-11`): `alerta_entrega.leer` en el listado `GET /pickup-alerts` deja ahora fila `read` sin valores y consume el cupo de lectura de `prl`, como `entrega_menor.leer` en `GET /student-movements`; los permisos no cambian. Así conserjería consulta sin poder gestionar autorizados.

## 8.4 Siembra en roles predefinidos (`L9`, aprobada 2026-10-29)

| Rol | Permisos | Ámbito |
|-----|----------|--------|
| `administrador_centro` | Todos los de §8.2 | `todos` |
| `secretaria` | `autorizado_recogida.*`, `entrega_menor.leer`, `alerta_entrega.leer` | `todos` |
| `direccion` | `autorizado_recogida.leer`, `entrega_menor.leer`, `alerta_entrega.*` | `todos` |
| `conserjeria_pas` (rol predefinido de `ProvisionTenantDefaults`, `ADR-068` hecho 16) | `entrega_menor.leer`, `entrega_menor.crear`, `alerta_entrega.leer`, `alerta_entrega.crear` | `todos` |

Conserjería **no** recibe `autorizado_recogida.crear`: una recogida no prevista pasa por secretaría o dirección, que la registran como autorización puntual por el canal `telefono` tras devolver la llamada al principal (`OPEN-FAM-UNIT-35`, aprobada; `RN-FAM-UNIT-85`). Siembra por `ProvisionTenantDefaults` y por un comando de `Core` para centros existentes (se amplió el existente, `fam_unit:grant-permissions`), idempotente y sin conceder nada si falta un permiso (#387). **MFA** (recomendación, no se aplica): un rol con `autorizado_recogida.crear` debería llevar `mfa_obligatorio`, porque puede añadir a un extraño a la lista de un menor.

### 8.5 Implementación de `1.14b-b`

> **Estado**: **IMPLEMENTADA** (2026-11-02). Los cinco permisos los declara `PrlServiceProvider::declaredPermissions()` (ámbito `todos`, ninguno de categoría especial, ni `exportar` ni `importar`; `AR-09`). La siembra de `§8.4` es `ProvisionTenantDefaults::FAMILY_PERMISSION_GRANTS` (centros nuevos) y `fam_unit:grant-permissions` (centros existentes): `administrador_centro` los cinco; `secretaria` `entrega_menor.leer` y `alerta_entrega.leer`; `direccion` `entrega_menor.leer` y `alerta_entrega.{leer,crear,actualizar}`; `conserjeria_pas` `entrega_menor.{leer,crear}` y `alerta_entrega.{leer,crear}` (sin `autorizado_recogida.*`, sin cerrar alertas). Sin siembra para docentes ni `tutor_legal` (`OPEN-FAM-UNIT-42`). Tests: `apps/api/tests/Feature/Prl/PrlPermissionsTest.php` y `DoorApiTest.php` (`CA-PRL-009`: sin permiso, también un tutor con ámbito `unidad_familiar`, `403`; `deny` en uno de los roles, `403`).

Un rol con estos permisos en un ámbito distinto de `todos` (p. ej. insertado a mano) **no concede nada**: `entrega_menor.*` y `alerta_entrega.*` no tienen resolutor de ámbito y las nueve rutas responden `403` (`PrlReadAuditTest`, seguridad M2).

### 8.6 Entrega `1.14b-d1` — APROBADA e implementada (2026-11-04)

> **Estado**: **APROBADA** por el usuario (2026-11-04) e implementada en `1.14b-d1` T1-T2. **Sin permisos, recursos, ámbitos ni siembra nuevos**: la matriz de `§8.3` y la siembra de `§8.4` no cambian.

| Recurso | Acción | Ámbito | Cambio en `1.14b-d1` |
|---------|--------|--------|----------------------|
| `entrega_menor` | `crear` | `todos` | La comprobación de **negocio** que acompaña al permiso (la persona figura en la lista) pasa a hacerse con bloqueo (`funcional.md RN-PRL-12`); el permiso y su comprobación en la ruta no cambian |
| `alerta_entrega` | `crear` | `todos` | Ídem para la existencia del alumno |
| `alerta_entrega` | `eliminar` | — | Sigue sin existir; ahora además el motor niega `DELETE` al rol de la aplicación (`RN-PRL-18`) |

- **Defensa en profundidad de privilegios del motor**: `REVOKE DELETE` en `pickup_alerts` (`datos.md §8.9`). Es un cambio de `GRANT`: uno de los motivos de la clasificación **crítica**.
- **`delivered_by`** (`OPEN-PRL-03`): si se aprueba A, `prl` comprobará que el usuario indicado como «quién entrega» tiene `entrega_menor.crear` (permiso, nunca rol, `AR-08`), por una interfaz de `Core\Domain` existente; si no existe esa interfaz, no se amplía `Core` para esto y se queda como está.
- **Tests de denegación** que siguen exigidos: `CA-PRL-009` (sin permiso, `deny`) y `CA-PRL-011` (aislamiento entre centros) se repiten sobre los dos `POST` tras el cambio (`CA-PRL-035`).
